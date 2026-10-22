<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Shared\Money\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * Prices a line generated from time with the quotes frozen in its conversion snapshot (R-10.1-04-6, research Pitfall 6,
 * ruling R-17-1). A document in another currency than the hourly rate freezes the CNB quotes of the issue date on the
 * line ({@see DraftPreview::convertLine()}); a later correction of the quantity must use exactly those quotes and never
 * today's lists, so the line stays on the exchange rate the customer was told.
 *
 * The line amount is {@see Money::timesThroughCzk()}, one exact rational rounded once; the printed unit price is
 * {@see Money::convertThroughCzk()}, a display figure that never feeds the amount.
 */
final class TimeLineConversion
{
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
