<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * What a draft in another currency is worth with the CNB lists of a day (IN-07; D-09, D-12, UI-SPEC C2 and C5, ruling
 * R-17-1), and the one place that converts a time line.
 *
 * A time line whose hourly rate is in another currency than the document, and whose price the Admin did not type, is
 * converted in a single way everywhere: the line amount is {@see Money::timesThroughCzk()} of the quantity and the
 * source rate, one exact rational rounded once; the printed unit price is {@see Money::convertThroughCzk()} of the
 * source rate, a display figure that never feeds the amount. The draft editor, every save of a draft and the issue call
 * {@see DraftPreview::convertLine()}, so with the same list a preview and the issued value are the same figures. The
 * preview is only a preview: the issue looks the lists up again for the issue date and never reuses the rate shown
 * here.
 *
 * The two reading methods are for the Admin and the system. Nothing is stored here.
 */
final readonly class DraftPreview
{
    public function __construct(private ExchangeRateConverter $rates) {}

    /**
     * The converted display unit price and the single-rounded line amount of a time line at the lists of a day, with
     * the rate used and the date of its list; null for a line that is not converted (typed by hand, not from time,
     * or already in the currency of the document). The rate is the quote of the foreign currency involved: the
     * document's when it is not CZK, otherwise the one of the line's rate.
     *
     * @param  string  $today  the Prague calendar day, `Y-m-d`
     * @return array{unit_price: Money, line_total: Money, rate: string, rate_date: string}|null
     *
     * @throws AuthorizationException for a Partner
     * @throws ExchangeRateMissing when a needed list does not exist
     * @throws OverflowException when the amount does not fit
     */
    public function linePreview(InvoiceItem $item, string $today): ?array
    {
        self::authorise();

        $source = $item->source;
        $invoice = $item->invoice ?? throw new LogicException('A line of a draft has its document.');

        if (! $source instanceof InvoiceItemSource || ! self::needsConversion($source, $invoice->currency)) {
            return null;
        }

        $converted = $this->convertLine($source->sourceRate() ?? throw new LogicException('A converted line has its source rate.'), $item->quantity, $invoice->currency, $today);

        return [
            'unit_price' => $converted['unit_price'],
            'line_total' => $converted['line_total'],
            'rate' => $converted['rate'],
            'rate_date' => $converted['rate_date'],
        ];
    }

    /**
     * The document total in CZK at the list of a day, with the rate, its unit amount and the date of its list; null
     * for a draft in CZK.
     *
     * @param  string  $today  the Prague calendar day, `Y-m-d`
     * @return array{czk: Money, rate: string, unit: int, rate_date: string}|null
     *
     * @throws AuthorizationException for a Partner
     * @throws ExchangeRateMissing when there is no list for the currency of the draft
     */
    public function czkPreview(Invoice $draft, string $today): ?array
    {
        self::authorise();

        if ($draft->currency === 'CZK') {
            return null;
        }

        $quote = $this->rates->rateFor($draft->currency, $today);

        return [
            'czk' => $draft->total()->convert($quote['rate'], 'CZK', $quote['unit_amount']),
            'rate' => $quote['rate'],
            'unit' => $quote['unit_amount'],
            'rate_date' => $quote['rate_date'],
        ];
    }

    /**
     * Whether a line is converted: generated from time, its price not typed by hand, and its hourly rate in another
     * currency than the document.
     */
    public static function needsConversion(InvoiceItemSource $source, string $documentCurrency): bool
    {
        return $source->origin === InvoiceItemOrigin::Time
            && ! $source->price_manual
            && $source->source_rate_minor !== null
            && $source->source_rate_currency !== null
            && $source->source_rate_currency !== $documentCurrency;
    }

    /**
     * Converts one line: the unit price for display and the amount rounded once, both from the lists of a day, and
     * the frozen shape of the quotes.
     *
     * @param  string  $quantity  the exact decimal quantity of the line
     * @param  string  $day  the Prague calendar day, `Y-m-d`, whose lists apply
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes  the quotes found so far by currency, filled in
     * @return array{unit_price: Money, line_total: Money, rate: string, rate_date: string, snapshot: array{source_currency: string, source_rate: string, source_unit: int, target_currency: string, target_rate: string, target_unit: int, rate_date: string}}
     *
     * @throws ExchangeRateMissing when a needed list does not exist
     * @throws InvalidArgumentException when the rate and the document are in the same currency
     * @throws OverflowException when the amount does not fit
     */
    public function convertLine(Money $sourceRate, string $quantity, string $documentCurrency, string $day, array &$quotes = []): array
    {
        $source = $this->quote($sourceRate->currency, $day, $quotes);
        $target = $this->quote($documentCurrency, $day, $quotes);

        $unitPrice = $sourceRate->convertThroughCzk($source['rate'], $source['unit_amount'], $documentCurrency, $target['rate'], $target['unit_amount']);
        $lineTotal = $sourceRate->timesThroughCzk($quantity, $source['rate'], $source['unit_amount'], $documentCurrency, $target['rate'], $target['unit_amount']);

        // The date of the list a figure rests on; CZK has none, so the later of the foreign lists is the date of both.
        $dates = array_filter([$source['rate_date'], $target['rate_date']], static fn (?string $date): bool => $date !== null);
        $rateDate = $dates === [] ? $day : max($dates);

        return [
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'rate' => $documentCurrency === 'CZK' ? $source['rate'] : $target['rate'],
            'rate_date' => $rateDate,
            'snapshot' => [
                'source_currency' => $sourceRate->currency,
                'source_rate' => $source['rate'],
                'source_unit' => $source['unit_amount'],
                'target_currency' => $documentCurrency,
                'target_rate' => $target['rate'],
                'target_unit' => $target['unit_amount'],
                'rate_date' => $rateDate,
            ],
        ];
    }

    /**
     * The CNB quote of a currency on a day; CZK is its own base, rate one for one unit, and has no list date.
     *
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes
     * @return array{rate: string, unit_amount: int, rate_date: string|null}
     *
     * @throws ExchangeRateMissing
     */
    private function quote(string $currency, string $day, array &$quotes): array
    {
        if ($currency === 'CZK') {
            return ['rate' => '1', 'unit_amount' => 1, 'rate_date' => null];
        }

        return $quotes[$currency] ??= $this->rates->rateFor($currency, $day);
    }

    /**
     * @throws AuthorizationException
     */
    private static function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The preview of a draft is available to the Admin only.');
        }
    }
}
