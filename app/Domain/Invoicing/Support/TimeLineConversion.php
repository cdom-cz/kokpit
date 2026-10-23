<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Prices a line generated from time with the quotes frozen in its conversion snapshot (R-10.1-04-6, research Pitfall 6,
 * ruling R-17-1). A document in another currency than the hourly rate freezes the CNB quotes of the issue date on the
 * line ({@see TimeLineConversion::freezeAt()}); a later correction of the quantity must use exactly those quotes and
 * never today's lists, so the line stays on the exchange rate the customer was told.
 *
 * The line amount is {@see Money::timesThroughCzk()}, one exact rational rounded once; the printed unit price is
 * {@see Money::convertThroughCzk()}, a display figure that never feeds the amount.
 *
 * {@see TimeLineConversion::freezeAt()} is the one place that converts the lines of a stored document at the lists of
 * a day: the issue ({@see DocumentIssuer}) and the change of currency of an open issued document
 * ({@see UpdateIssuedDocument}) both call it (R-10.1-08-1, research Pitfall 6).
 */
final class TimeLineConversion
{
    /**
     * Converts the time lines of the locked document that are priced in another currency than the document, with the
     * CNB lists valid on the day (D-09, ruling R-17-1): the line amount is {@see Money::timesThroughCzk()} of the
     * quantity and the source rate, rounded once from the exact conversion (through {@see DraftPreview::convertLine()}),
     * the printed unit price is the display figure of the same conversion and never feeds the amount, and the line is
     * flagged `price_converted`. Both quotes and the list date are frozen in the conversion snapshot of the line (ruling
     * R-17-2). A price typed by hand is never converted, in either mode. The document totals are then recomputed as the
     * sum of the stored line amounts.
     *
     * With `$again` false a line whose source already carries a snapshot was frozen before and is left alone, which is
     * what the issue needs. With `$again` true (the change of currency) every such line is converted afresh with the
     * lists of the day, and a line whose rate is in the currency of the document is restored to the exact rate price,
     * `price_converted` false and no snapshot; the totals are recomputed in that mode whether or not a line changed.
     *
     * The document row is saved with the new totals unless `$persistTotals` is false, where the caller saves the row
     * itself (an edit that writes the row once); the totals are set on the model in both cases.
     *
     * @return bool whether any line was written
     *
     * @throws ValidationException `cnb_missing` when a needed list is missing, `items` when an amount does not fit,
     *                             `total_not_positive` when the total is no longer positive
     */
    public static function freezeAt(Invoice $locked, string $day, bool $again = false, bool $persistTotals = true): bool
    {
        $conversion = app(DraftPreview::class);

        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $locked->id)->with('source')->orderBy('position')->get();

        $quotes = [];
        $changed = false;

        foreach ($items as $item) {
            $source = $item->source;

            if (! $source instanceof InvoiceItemSource) {
                continue;
            }

            if (DraftPreview::needsConversion($source, $locked->currency)) {
                if (! $again && $source->conversion_snapshot !== null) {
                    continue;
                }

                try {
                    $converted = $conversion->convertLine(
                        $source->sourceRate() ?? throw new LogicException('A converted line has its source rate.'),
                        $item->quantity,
                        $locked->currency,
                        $day,
                        $quotes,
                    );
                } catch (ExchangeRateMissing $missing) {
                    throw ValidationException::withMessages([
                        'cnb_missing' => __('invoices.errors.cnb_missing', ['currency' => $missing->currency, 'date' => IssueChecks::czechDate($day)]),
                    ]);
                } catch (OverflowException) {
                    throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
                }

                $item->forceFill([
                    'unit_price_minor' => $converted['unit_price']->minor,
                    'line_total_minor' => $converted['line_total']->minor,
                    'price_converted' => true,
                ])->save();

                $source->forceFill(['conversion_snapshot' => $converted['snapshot']])->save();
                $changed = true;

                continue;
            }

            if ($again && ItemListWriter::isGenerated($source)) {
                $rate = $source->sourceRate() ?? throw new LogicException('A generated line has its source rate.');

                try {
                    $lineTotal = DraftTotals::lineTotal($rate, $item->quantity());
                } catch (OverflowException) {
                    throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
                }

                $item->forceFill([
                    'unit_price_minor' => $rate->minor,
                    'line_total_minor' => $lineTotal->minor,
                    'price_converted' => false,
                ])->save();

                $source->forceFill(['conversion_snapshot' => null])->save();
                $changed = true;
            }
        }

        if (! $changed && ! $again) {
            return false;
        }

        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $locked->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            $totals = DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $locked->currency), $minors), $locked->currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }

        if ($locked->kind === InvoiceKind::CreditNote ? ! $totals['total']->isNegative() : ! $totals['total']->isPositive()) {
            throw ValidationException::withMessages(['total_not_positive' => __('invoices.errors.total_not_positive')]);
        }

        $locked->forceFill([
            'subtotal_minor' => $totals['subtotal']->minor,
            'vat_minor' => $totals['vat']->minor,
            'total_minor' => $totals['total']->minor,
        ]);

        if ($persistTotals) {
            $locked->save();
        }

        return $changed;
    }

    /**
     * @param  Money  $sourceRate  the hourly rate of the line, in its own currency
     * @param  array<string, mixed>  $snapshot  the frozen quotes: `source_rate`, `source_unit`, `target_currency`, `target_rate`, `target_unit`
     * @return array{unit_price: Money, line_total: Money}
     *
     * @throws InvalidArgumentException when the snapshot lacks a quote or holds one the money type refuses
     * @throws OverflowException when the amount does not fit
     */
    public static function fromSnapshot(Money $sourceRate, InvoiceQuantity $quantity, array $snapshot): array
    {
        $sourceQuote = self::text($snapshot, 'source_rate');
        $targetQuote = self::text($snapshot, 'target_rate');
        $sourceUnit = self::unit($snapshot, 'source_unit');
        $targetUnit = self::unit($snapshot, 'target_unit');
        $target = self::text($snapshot, 'target_currency');

        return [
            'unit_price' => $sourceRate->convertThroughCzk($sourceQuote, $sourceUnit, $target, $targetQuote, $targetUnit),
            'line_total' => $sourceRate->timesThroughCzk($quantity->toDecimal(), $sourceQuote, $sourceUnit, $target, $targetQuote, $targetUnit),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function text(array $snapshot, string $key): string
    {
        $value = $snapshot[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("The conversion snapshot has no {$key}.");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function unit(array $snapshot, string $key): int
    {
        $value = $snapshot[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("The conversion snapshot has no {$key}.");
        }

        return (int) $value;
    }
}
