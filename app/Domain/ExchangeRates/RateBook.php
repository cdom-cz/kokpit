<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use App\Domain\ExchangeRates\Models\ExchangeRate;
use InvalidArgumentException;

/**
 * The stored CNB rates a conversion needs, preloaded for a currency set and a range of days.
 *
 * A day without a list (weekend, holiday, outage) is answered by lookup: the newest stored list not after the
 * day, however old. Such days are never stored as copies (research Pattern 3), so the stored lists stay the
 * only source of truth and a later correction of a gap needs no chasing of copies.
 *
 * Loading takes two queries whatever the range: the newest list before the range per currency (the floor, which
 * is the newest earlier list and not a fixed number of days, because an outage or a long holiday block can be
 * longer than any fixed window), then every row from the oldest floor up to the end of the range. Lookups run in
 * memory, so a report converting thousands of entries asks the database twice.
 */
final class RateBook
{
    /**
     * @param  array<string, list<array{rate: string, unit_amount: int, rate_date: string}>>  $byCurrency  per currency, ascending by day
     */
    private function __construct(private readonly array $byCurrency) {}

    /**
     * @param  list<string>|null  $currencies  null loads every currency
     *
     * @throws InvalidArgumentException when a day is not a `YYYY-MM-DD` date or the range is reversed
     */
    public static function load(?array $currencies, string $from, string $to): self
    {
        self::assertDay($from);
        self::assertDay($to);

        if ($from > $to) {
            throw new InvalidArgumentException('The range must not end before it starts.');
        }

        if ($currencies === []) {
            return new self([]);
        }

        $floors = ExchangeRate::query()
            ->when($currencies !== null, static fn ($query) => $query->whereIn('currency', $currencies))
            ->where('rate_date', '<', $from)
            ->selectRaw('currency, max(rate_date) as floor_date')
            ->groupBy('currency')
            ->get();

        $start = $from;

        foreach ($floors as $floor) {
            $day = (string) $floor->getAttribute('floor_date');

            if ($day < $start) {
                $start = $day;
            }
        }

        $rows = ExchangeRate::query()
            ->when($currencies !== null, static fn ($query) => $query->whereIn('currency', $currencies))
            ->where('rate_date', '>=', $start)
            ->where('rate_date', '<=', $to)
            ->orderBy('currency')
            ->orderBy('rate_date')
            ->get(['currency', 'rate_date', 'unit_amount', 'rate']);

        $byCurrency = [];

        foreach ($rows as $row) {
            $byCurrency[$row->currency][] = [
                'rate' => $row->rate,
                'unit_amount' => $row->unit_amount,
                'rate_date' => $row->rate_date->toDateString(),
            ];
        }

        return new self($byCurrency);
    }

    /**
     * The newest loaded list not after the day, or null when none is loaded.
     *
     * @return array{rate: string, unit_amount: int, rate_date: string}|null
     *
     * @throws InvalidArgumentException when the day is not a `YYYY-MM-DD` date
     */
    public function rateFor(string $currency, string $day): ?array
    {
        self::assertDay($day);

        $rows = $this->byCurrency[$currency] ?? [];
        $low = 0;
        $high = count($rows) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($rows[$middle]['rate_date'] <= $day) {
                $found = $rows[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }

    private static function assertDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            throw new InvalidArgumentException('A day must be written as YYYY-MM-DD.');
        }
    }
}
