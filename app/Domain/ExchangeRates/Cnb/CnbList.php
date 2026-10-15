<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Cnb;

use Carbon\CarbonInterface;

/**
 * One validated CNB daily list: the day the CNB says it is valid for and one entry per currency.
 *
 * A rate is the price of `unit_amount` units of the currency in CZK and stays a decimal string from the HTTP
 * body to the database column; no float ever holds it.
 */
final readonly class CnbList
{
    /**
     * @param  string  $validFor  the published day, `Y-m-d`
     * @param  list<array{currency: string, unit_amount: int, rate: string}>  $rates
     */
    public function __construct(
        public string $validFor,
        public array $rates,
    ) {}

    /**
     * Insert rows for `ExchangeRate::query()->insertOrIgnore()`: explicit values and timestamps, no id (the
     * database default `uuidv7()` fills it).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(CarbonInterface $fetchedAt): array
    {
        $stamp = $fetchedAt->utc()->format('Y-m-d H:i:sP');

        return array_map(fn (array $rate): array => [
            'rate_date' => $this->validFor,
            'currency' => $rate['currency'],
            'unit_amount' => $rate['unit_amount'],
            'rate' => $rate['rate'],
            'fetched_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ], $this->rates);
    }
}
