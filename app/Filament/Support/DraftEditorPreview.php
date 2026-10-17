<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\TimerClock;
use Filament\Support\Facades\FilamentTimezone;
use InvalidArgumentException;
use OverflowException;

/**
 * What the draft editor shows next to the fields (UI-SPEC Surface C, ruling R-25-1): the amount of each line, the
 * origin and preview badges, and the summary with its CZK preview. Every figure is computed here, on the server,
 * from the figures the Admin typed and the stored line sources; nothing is computed in the browser, so the single
 * rounding point of the amounts holds.
 *
 * It is a reader only and writes nothing. The editor state is client-controlled, so the line id is used only as a key
 * into the lines that really belong to this draft (one scoped query, once per request); an id of another document
 * finds nothing and the line is treated as a manual one. The rules for a generated line mirror
 * {@see UpdateDraft}: a time line whose price the Admin did not type follows its hourly
 * rate (converted with the lists of today when the rate is in another currency), and a typed price makes it manual.
 * What the save then stores is the Action's own computation; this class only previews it.
 */
final class DraftEditorPreview
{
    /** @var array<string, InvoiceItem>|null */
    private ?array $stored = null;

    /** @var array<string, array{rate: string, unit_amount: int, rate_date: string|null}> */
    private array $quotes = [];

    /** @var array<string, array{quantity: InvoiceQuantity|null, unit_price: Money|null, total: Money|null, from_time: bool, seconds: int|null, generated: bool, converted: bool, rate: string|null, stored: bool}> */
    private array $memo = [];

    public function __construct(
        private readonly Invoice $draft,
        private readonly DraftPreview $preview,
    ) {}

    /**
     * Today as a Prague calendar day.
     */
    public static function today(): string
    {
        return TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d');
    }

    /**
     * The quantity a person typed (a comma or a point, spaces ignored), or null when it is not a usable one.
     */
    public static function quantity(mixed $value): ?InvoiceQuantity
    {
        $text = self::decimal($value);

        if ($text === null) {
            return null;
        }

        try {
            $quantity = InvoiceQuantity::fromDecimal($text);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $quantity->isZero() ? null : $quantity;
    }

    /**
     * The unit price a person typed in the currency, or null when it is not a usable, non-negative amount.
     */
    public static function price(mixed $value, string $currency): ?Money
    {
        $text = self::decimal($value);

        if ($text === null) {
            return null;
        }

        try {
            $price = Money::fromMajor($text, $currency);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }

        return $price->minor < 0 ? null : $price;
    }

    /**
     * A price for an input: the comma of the Czech keyboard, the figures of the currency.
     */
    public static function priceText(Money $price): string
    {
        return str_replace('.', ',', $price->toMajor());
    }

    /**
     * The figures of one line for the quantity and the unit price in the editor state.
     *
     * @param  string|null  $id  the id of the stored line, null for a new one
     * @return array{quantity: InvoiceQuantity|null, unit_price: Money|null, total: Money|null, from_time: bool, seconds: int|null, generated: bool, converted: bool, rate: string|null, stored: bool}
     */
    public function line(?string $id, mixed $quantity, mixed $price, string $currency): array
    {
        $key = ($id ?? '').'|'.json_encode([$quantity, $price, $currency]);

        return $this->memo[$key] ??= $this->compute($id, $quantity, $price, $currency);
    }

    /**
     * The summary under the table: the totals of the lines that have an amount and the CZK preview of a foreign
     * document. A line with no usable figures counts as nothing here; the save refuses it with a field error.
     *
     * @param  list<Money|null>  $lineTotals
     * @return array{subtotal: Money, total: Money, czk: array{czk: Money, rate: string, unit: int, rate_date: string}|null, czk_missing: bool}
     */
    public function summary(array $lineTotals, string $currency): array
    {
        try {
            $totals = DraftTotals::for(array_filter($lineTotals, static fn (?Money $total): bool => $total instanceof Money), $currency);
        } catch (OverflowException) {
            $totals = DraftTotals::for([], $currency);
        }

        $czk = null;
        $missing = false;

        if ($currency !== 'CZK') {
            $probe = clone $this->draft;
            $probe->forceFill(['currency' => $currency, 'total_minor' => $totals['total']->minor]);

            try {
                $czk = $this->preview->czkPreview($probe, self::today());
            } catch (ExchangeRateMissing) {
                $missing = true;
            }
        }

        return ['subtotal' => $totals['subtotal'], 'total' => $totals['total'], 'czk' => $czk, 'czk_missing' => $missing];
    }

    /**
     * Forgets the stored lines, after a write changed them.
     */
    public function reset(): void
    {
        $this->stored = null;
        $this->memo = [];
    }

    /**
     * Whether a line with this id is one of the lines of the draft.
     */
    public function isStored(string $id): bool
    {
        return isset($this->stored()[$id]);
    }

    /**
     * @return array{quantity: InvoiceQuantity|null, unit_price: Money|null, total: Money|null, from_time: bool, seconds: int|null, generated: bool, converted: bool, rate: string|null, stored: bool}
     */
    private function compute(?string $id, mixed $quantityValue, mixed $priceValue, string $currency): array
    {
        $stored = $id === null ? null : ($this->stored()[$id] ?? null);
        $source = $stored?->source;

        $fromTime = false;
        $seconds = null;
        $generated = false;

        if ($stored instanceof InvoiceItem && $source instanceof InvoiceItemSource && $source->origin === InvoiceItemOrigin::Time) {
            $fromTime = true;
            $seconds = $source->source_seconds;
            $generated = ! $source->price_manual && $source->sourceRate() instanceof Money;
        }
        $currencyChanged = $currency !== $this->draft->currency;

        $quantity = self::quantity($quantityValue);
        $typed = self::price($priceValue, $currency);

        // The same test as the Action: a figure that differs from the stored price is a typed price, except that the
        // price of a generated line is never read as typed on a change of currency.
        $priceChanged = $stored instanceof InvoiceItem
            && ! ($generated && $currencyChanged)
            && $typed instanceof Money
            && $stored->unit_price_minor !== $typed->minor;

        $unitPrice = $typed;
        $total = null;
        $converted = false;
        $rate = null;

        if ($stored instanceof InvoiceItem && $source instanceof InvoiceItemSource && $generated && ! $priceChanged) {
            [$unitPrice, $total, $converted, $rate] = $this->generated($source, $stored, $quantity, $currency, $currencyChanged);
        } elseif ($quantity instanceof InvoiceQuantity && $typed instanceof Money) {
            $total = self::lineTotal($typed, $quantity);
        }

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'from_time' => $fromTime,
            'seconds' => $seconds,
            'generated' => $generated,
            'converted' => $converted,
            'rate' => $rate,
            'stored' => $stored instanceof InvoiceItem,
        ];
    }

    /**
     * The price and the amount of a generated line, as the Action will store them: a rate in another currency is
     * previewed with the lists of today, a rate in the currency of the document is restored exactly after a change
     * of currency, and a missing list keeps the stored preview.
     *
     * @return array{0: Money|null, 1: Money|null, 2: bool, 3: string|null} unit price, amount, converted, shown rate
     */
    private function generated(InvoiceItemSource $source, InvoiceItem $stored, ?InvoiceQuantity $quantity, string $currency, bool $currencyChanged): array
    {
        $sourceRate = $source->sourceRate();

        if (! $sourceRate instanceof Money) {
            return [null, null, false, null];
        }

        $effective = $quantity ?? InvoiceQuantity::fromDecimal('1');

        try {
            if ($sourceRate->currency !== $currency) {
                $line = $this->preview->convertLine($sourceRate, $effective->toDecimal(), $currency, self::today(), $this->quotes);

                return [$line['unit_price'], $quantity instanceof InvoiceQuantity ? $line['line_total'] : null, true, RateFormat::display($line['rate'])];
            }

            if ($currencyChanged) {
                return [$sourceRate, $quantity instanceof InvoiceQuantity ? self::lineTotal($sourceRate, $quantity) : null, false, null];
            }
        } catch (ExchangeRateMissing|OverflowException) {
            $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);

            return [
                $unitPrice,
                $quantity instanceof InvoiceQuantity ? ($stored->quantity === $quantity->toDecimal() ? Money::ofMinor($stored->line_total_minor, $currency) : self::lineTotal($unitPrice, $quantity)) : null,
                $stored->price_converted,
                null,
            ];
        }

        $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);

        return [$unitPrice, $quantity instanceof InvoiceQuantity ? self::lineTotal($unitPrice, $quantity) : null, false, null];
    }

    private static function lineTotal(Money $price, InvoiceQuantity $quantity): ?Money
    {
        try {
            return DraftTotals::lineTotal($price, $quantity);
        } catch (OverflowException) {
            return null;
        }
    }

    /**
     * The lines that really belong to the draft, with their sources, keyed by id: one query for the whole request.
     *
     * @return array<string, InvoiceItem>
     */
    private function stored(): array
    {
        if ($this->stored === null) {
            $this->stored = InvoiceItem::query()
                ->where('invoice_id', $this->draft->id)
                ->with('source')
                ->orderBy('position')
                ->get()
                ->keyBy('id')
                ->all();
        }

        return $this->stored;
    }

    /**
     * A decimal typed by a person, spaces of any kind removed; null for anything but text or a whole number.
     */
    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);

        return $text === '' ? null : $text;
    }
}
