<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceItemInput;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Saves the whole draft in one transaction under the row lock: the single write path of a draft (IN-02, IN-08, D-03,
 * D-08, D-09, D-11). The Admin only. The editor hands a plain array; a Filament relationship repeater is never the
 * write path (research Pattern 12), so every rule here is re-checked whatever the form offered.
 *
 * Data keys, each optional (an absent key keeps the stored value, a present key replaces it):
 * - `currency`: a known ISO 4217 code (R-11-2); a missing bank account for it is a blocker of issuing, not an error
 *   here. A caller that changes the currency sends the `items` in the new currency with it, as the editor always does;
 * - `language`: `cs` or `en`;
 * - `issue_date`, `due_date`: Prague `Y-m-d` or null, the due date not before the issue date. A null issue date means
 *   "today (Europe/Prague) at issue" and a null due date "the issue date plus the client's payment terms at issue";
 *   only a value the Admin typed is stored (R-11-1);
 * - `note` (at most 500 characters), printed on the document;
 * - `internal_note` (at most 2 000 characters), saved through {@see UpdateInternalNote} in the same transaction;
 * - `card_payment_enabled` (a strict boolean, an invoice or a proforma draft only): the per-document card switch of the
 *   Admin-only row (D-06, ruling R11-03-5). A new draft starts with the client's value; this key overrides it, and the
 *   issue freezes the AND of it with the global setting and a configured Stripe. A credit-note draft or a value that is
 *   not a boolean is refused with `card_payment_invalid` under `card_payment_enabled`;
 * - `items`: the whole list of lines, `id?`, `description`, `quantity`, `unit`, `unit_price`, `confirm_zero_price?`
 *   (see {@see InvoiceItemInput}).
 *
 * Lines are matched by id (R-11-3): a line with an id of this draft is updated and keeps its row and its source, a
 * line without one is inserted as a manual line, a stored line missing from the list is deleted with its source,
 * and positions follow the order of the list. An id that does not belong to the draft is a field error
 * `items.<n>.id`, never a write. Editing or deleting a line never changes which time entries are linked (D-03,
 * D-06). Changing the unit price of a line that was generated from time or a fixed amount marks its source as a manual
 * price and keeps its origin; the later conversion step leaves such a price alone.
 *
 * Every line total is computed here, never read from the data (T-10-28); the subtotal and the total are the sum of the
 * stored line totals, the VAT is zero in the non-payer mode. An ordinary line costs {@see DraftTotals::lineTotal()} and
 * is stored with `price_converted` false. A line generated from time whose hourly rate is in another currency than
 * the draft and whose price was not typed is re-previewed on every save through {@see DraftPreview::convertLine()}
 * with the CNB lists of today (UI-SPEC C1, ruling R-17-1): the printed unit price is the display figure, the line
 * amount is rounded once from the exact conversion, `price_converted` is true, so an edit of the quantity or the text
 * never turns a preview into a two-rounding figure. The price shown for such a line is not a typed price: only a
 * unit price that differs from the stored one is, and it makes the line manual and exact. When no list exists for a
 * needed currency an ordinary save keeps the stored preview of those lines (the issue blocks with `cnb_missing`
 * anyway).
 *
 * Changing the `currency` re-previews the generated lines for the new currency, with or without `items`: a rate in the
 * new currency is restored exactly, the others are converted again, a unit price typed by hand is never changed. A
 * change to a currency whose list is missing for a line that needs it is refused with `cnb_missing` under `currency`,
 * like the draft creation (R-13-4), and nothing is written. The issue converts these lines again with the lists of the
 * issue date and never reuses the preview.
 */
final readonly class UpdateDraft
{
    /**
     * Where a kept line is parked while the positions are reassigned, so the unique key of (document, position)
     * is never hit by a reorder. Far above the number of lines a draft may hold.
     */
    private const int PARKED_POSITION = 1_000_000;

    private const int NOTE_MAX_LENGTH = 500;

    public function __construct(
        private UpdateInternalNote $internalNote,
        private DraftPreview $preview,
        private ExchangeRateConverter $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('update', $draft);

        return DB::transaction(function () use ($actor, $draft, $data): Invoice {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            $header = $this->header($data, $locked);
            $cardPayment = $this->cardPayment($data, $locked);
            $currencyChanged = $header['currency'] !== $locked->currency;
            $today = TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d');

            if ($currencyChanged) {
                $this->assertListsFor($locked, $header['currency'], $today);
            }

            if (array_key_exists('internal_note', $data)) {
                $this->internalNote->handle($actor, $locked, is_string($data['internal_note']) ? $data['internal_note'] : null);
            }

            if ($cardPayment !== null) {
                $this->saveCardPayment($locked, $cardPayment);
            }

            if (array_key_exists('items', $data)) {
                if (! is_array($data['items'])) {
                    throw new InvalidArgumentException('The items of a draft are a list of lines.');
                }

                $this->saveItems($locked, $data['items'], $header['currency'], $currencyChanged, $today);
            } elseif ($currencyChanged) {
                $this->repriceGenerated($locked, $header['currency'], $today);
            }

            $totals = $this->totals($locked, $header['currency']);

            $locked->fill(['note' => $header['note']]);
            $locked->forceFill([
                'currency' => $header['currency'],
                'language' => $header['language'],
                'issue_date' => $header['issue_date'],
                'due_date' => $header['due_date'],
                'subtotal_minor' => $totals['subtotal']->minor,
                'vat_minor' => $totals['vat']->minor,
                'total_minor' => $totals['total']->minor,
            ]);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * The checked header: the data over the stored values.
     *
     * @param  array<string, mixed>  $data
     * @return array{currency: string, language: InvoiceLanguage, issue_date: ?string, due_date: ?string, note: ?string}
     *
     * @throws ValidationException
     */
    private function header(array $data, Invoice $draft): array
    {
        $issueDate = array_key_exists('issue_date', $data) ? $this->day($data['issue_date'], 'issue_date') : $draft->issue_date?->toDateString();
        $dueDate = array_key_exists('due_date', $data) ? $this->day($data['due_date'], 'due_date') : $draft->due_date?->toDateString();

        if ($issueDate !== null && $dueDate !== null && $dueDate < $issueDate) {
            throw ValidationException::withMessages(['due_date' => __('invoices.errors.due_before_issue')]);
        }

        return [
            'currency' => array_key_exists('currency', $data) ? $this->currency($data['currency']) : $draft->currency,
            'language' => array_key_exists('language', $data) ? $this->language($data['language']) : $draft->language,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'note' => array_key_exists('note', $data) ? $this->note($data['note']) : $draft->note,
        ];
    }

    /**
     * The checked card switch of the data, or null when the key is absent (D-06, ruling R11-03-5).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException `card_payment_enabled` for a non-boolean or for a credit note
     */
    private function cardPayment(array $data, Invoice $draft): ?bool
    {
        if (! array_key_exists('card_payment_enabled', $data)) {
            return null;
        }

        $value = $data['card_payment_enabled'];

        if (! is_bool($value) || $draft->kind === InvoiceKind::CreditNote) {
            throw ValidationException::withMessages(['card_payment_enabled' => __('invoices.errors.card_payment_invalid')]);
        }

        return $value;
    }

    /**
     * Writes the switch on the Admin-only row of the draft, creating the row when it is missing (as the internal note
     * does). A value equal to the stored one writes nothing.
     */
    private function saveCardPayment(Invoice $draft, bool $on): void
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $draft->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $draft->id, 'card_payment_enabled' => false]);
        }

        if ($admin->exists && $admin->card_payment_enabled === $on) {
            return;
        }

        $admin->forceFill(['card_payment_enabled' => $on])->save();
    }

    /**
     * @throws ValidationException
     */
    private function day(mixed $value, string $key): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return PragueDay::day(is_string($value) ? trim($value) : $value)
            ?? throw ValidationException::withMessages([$key => __('invoices.errors.date_invalid')]);
    }

    /**
     * @throws ValidationException
     */
    private function currency(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! Money::isKnownCurrency($code)) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.currency')]);
        }

        return $code;
    }

    /**
     * @throws ValidationException
     */
    private function language(mixed $value): InvoiceLanguage
    {
        $language = $value instanceof InvoiceLanguage ? $value : (is_string($value) ? InvoiceLanguage::tryFrom($value) : null);

        return $language ?? throw ValidationException::withMessages(['language' => __('invoices.errors.language')]);
    }

    /**
     * @throws ValidationException
     */
    private function note(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_string($value) ? trim($value) : '';

        if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages(['note' => __('invoices.errors.note_length')]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * Replaces the lines of the draft by the list: update by id, insert, delete the missing, order by the list.
     *
     * @param  array<array-key, mixed>  $items
     *
     * @throws ValidationException
     */
    private function saveItems(Invoice $draft, array $items, string $currency, bool $currencyChanged, string $today): void
    {
        $inputs = InvoiceItemInput::list($items, $currency, $draft->kind, (int) config('kokpit.invoicing.max_items'));

        /** @var Collection<string, InvoiceItem> $existing */
        $existing = InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->get()->keyBy('id');

        $kept = $this->assertOwnLines($inputs, $existing, $currency);

        foreach ($existing as $item) {
            if (! isset($kept[$item->id])) {
                $item->source?->delete();
                $item->delete();
            }
        }

        // Park every kept line whose position changes, so no reorder ever meets the unique (document, position).
        foreach ($inputs as $index => $input) {
            $item = $input->id === null ? null : $existing->get($input->id);

            if ($item instanceof InvoiceItem && $item->position !== $index + 1) {
                $item->forceFill(['position' => self::PARKED_POSITION + $index + 1])->save();
            }
        }

        $quotes = [];

        foreach ($inputs as $index => $input) {
            $this->writeLine($draft, $input, $input->id === null ? null : $existing->get($input->id), $index + 1, $currency, $currencyChanged, $today, $quotes);
        }
    }

    /**
     * Every id of the list must be a line of this draft, once. Line totals that do not fit are a field error too.
     *
     * @param  list<InvoiceItemInput>  $inputs
     * @param  Collection<string, InvoiceItem>  $existing
     * @return array<string, true> the ids kept
     *
     * @throws ValidationException
     */
    private function assertOwnLines(array $inputs, Collection $existing, string $currency): array
    {
        $kept = [];
        $errors = [];

        foreach ($inputs as $index => $input) {
            if ($input->id !== null) {
                if (! $existing->has($input->id) || isset($kept[$input->id])) {
                    $errors["items.{$index}.id"] = [__('invoices.errors.item_unknown')];
                }

                $kept[$input->id] = true;
            }

            try {
                DraftTotals::lineTotal($input->unitPrice, $input->quantity);
            } catch (OverflowException) {
                $errors["items.{$index}.unit_price"] = [__('invoices.errors.item_price')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $kept;
    }

    /**
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes  the quotes found so far, filled in
     *
     * @throws ValidationException
     */
    private function writeLine(Invoice $draft, InvoiceItemInput $input, ?InvoiceItem $stored, int $position, string $currency, bool $currencyChanged, string $today, array &$quotes): void
    {
        $item = $stored ?? new InvoiceItem;
        $source = $stored instanceof InvoiceItem ? $stored->source : null;
        $generated = $stored instanceof InvoiceItem && $source instanceof InvoiceItemSource && self::isGenerated($source);

        // The price a generated line shows is the preview, not a typed price; on a change of currency the editor
        // cannot know the new preview, so the figure it sends for such a line is never read as typed.
        $priceChanged = $stored instanceof InvoiceItem
            && ! ($generated && $currencyChanged)
            && $stored->unit_price_minor !== $input->unitPrice->minor;

        $unitPrice = $input->unitPrice;
        $lineTotal = DraftTotals::lineTotal($unitPrice, $input->quantity);
        $converted = false;

        if ($generated && ! $priceChanged) {
            [$unitPrice, $lineTotal, $converted] = $this->generatedPrice($source, $stored, $input->quantity, $currency, $currencyChanged, $today, $quotes);
        }

        $item->fill(['description' => $input->description, 'unit' => $input->unit]);
        $item->forceFill([
            'invoice_id' => $draft->id,
            'position' => $position,
            'quantity' => $input->quantity->toDecimal(),
            'unit_price_minor' => $unitPrice->minor,
            'line_total_minor' => $lineTotal->minor,
            'price_converted' => $converted,
            'vat_rate' => null,
            'vat_minor' => 0,
        ]);
        $item->save();

        if (! $source instanceof InvoiceItemSource) {
            $source = new InvoiceItemSource;
            $source->forceFill([
                'invoice_item_id' => $item->id,
                'invoice_id' => $draft->id,
                'origin' => InvoiceItemOrigin::Manual,
                'price_manual' => true,
            ])->save();

            return;
        }

        if ($priceChanged && ! $source->price_manual) {
            $source->forceFill(['price_manual' => true])->save();
        }
    }

    /**
     * Whether the price of the line follows its hourly rate: generated from time, the price not typed by hand.
     */
    private static function isGenerated(InvoiceItemSource $source): bool
    {
        return $source->origin === InvoiceItemOrigin::Time && ! $source->price_manual && $source->sourceRate() instanceof Money;
    }

    /**
     * The unit price, the amount and the converted flag of a generated line for the quantity: a rate in another
     * currency than the draft is previewed with the lists of today (one rounding, ruling R-17-1) or, when the list is
     * missing, keeps the stored preview; a rate in the draft's currency stays as it is, and is restored exactly after
     * a change of currency.
     *
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes
     * @return array{0: Money, 1: Money, 2: bool}
     *
     * @throws ValidationException when an amount does not fit
     */
    private function generatedPrice(InvoiceItemSource $source, InvoiceItem $stored, InvoiceQuantity $quantity, string $currency, bool $currencyChanged, string $today, array &$quotes): array
    {
        $rate = $source->sourceRate() ?? throw new LogicException('A generated line has its source rate.');

        try {
            if ($rate->currency !== $currency) {
                $converted = $this->preview->convertLine($rate, $quantity->toDecimal(), $currency, $today, $quotes);

                return [$converted['unit_price'], $converted['line_total'], true];
            }

            if ($currencyChanged) {
                return [$rate, DraftTotals::lineTotal($rate, $quantity), false];
            }
        } catch (ExchangeRateMissing) {
            // Keep what the draft shows; the issue refuses it with cnb_missing until the list is there.
            $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);
            $lineTotal = $stored->quantity === $quantity->toDecimal()
                ? Money::ofMinor($stored->line_total_minor, $currency)
                : DraftTotals::lineTotal($unitPrice, $quantity);

            return [$unitPrice, $lineTotal, $stored->price_converted];
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }

        $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);

        return [$unitPrice, DraftTotals::lineTotal($unitPrice, $quantity), false];
    }

    /**
     * Re-previews the generated lines of a draft for a new currency when the save carries no lines; the lines typed by
     * hand, the fixed amounts and the manual lines are not touched.
     *
     * @throws ValidationException
     */
    private function repriceGenerated(Invoice $draft, string $currency, string $today): void
    {
        $quotes = [];

        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->get();

        foreach ($items as $item) {
            $source = $item->source;

            if (! $source instanceof InvoiceItemSource || ! self::isGenerated($source)) {
                continue;
            }

            [$unitPrice, $lineTotal, $converted] = $this->generatedPrice($source, $item, InvoiceQuantity::fromDecimal($item->quantity), $currency, true, $today, $quotes);

            $item->forceFill([
                'unit_price_minor' => $unitPrice->minor,
                'line_total_minor' => $lineTotal->minor,
                'price_converted' => $converted,
            ])->save();
        }
    }

    /**
     * A change of currency needs the CNB list of every currency the generated lines are converted with: the new
     * currency and the currency of each rate that differs from it (CZK needs none). One missing list refuses the
     * change under `currency`, like the draft creation (plan 10-13 R-13-4).
     *
     * @throws ValidationException
     */
    private function assertListsFor(Invoice $draft, string $currency, string $today): void
    {
        /** @var list<string> $rateCurrencies */
        $rateCurrencies = InvoiceItemSource::query()
            ->where('invoice_id', $draft->id)
            ->where('origin', InvoiceItemOrigin::Time->value)
            ->where('price_manual', false)
            ->whereNotNull('source_rate_currency')
            ->where('source_rate_currency', '<>', $currency)
            ->distinct()
            ->orderBy('source_rate_currency')
            ->pluck('source_rate_currency')
            ->all();

        if ($rateCurrencies === []) {
            return;
        }

        foreach (array_unique([...$rateCurrencies, $currency]) as $needed) {
            if ($needed === 'CZK') {
                continue;
            }

            try {
                $this->rates->rateFor($needed, $today);
            } catch (ExchangeRateMissing) {
                throw ValidationException::withMessages(['currency' => [__('invoices.errors.cnb_missing', ['currency' => $needed, 'date' => IssueChecks::czechDate($today)])]]);
            }
        }
    }

    /**
     * The subtotal, VAT and total from the stored line totals, never from a unit price (D-09).
     *
     * @return array{subtotal: Money, vat: Money, total: Money}
     *
     * @throws ValidationException when the sum does not fit
     */
    private function totals(Invoice $draft, string $currency): array
    {
        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $draft->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            return DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $currency), $minors), $currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }
    }
}
