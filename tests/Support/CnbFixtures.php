<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Invented CNB daily-list payloads for the rates tests.
 *
 * Every payload is assembled at runtime from invented values; no real list is copied. Rates are written into the
 * JSON as numbers (not strings), exactly as the CNB API writes them, so the exact-decimal quoting path of the
 * client is exercised.
 */
final class CnbFixtures
{
    /**
     * Invented currencies with the unit amounts the CNB publishes (1, 100 and 1000). The rate is kept as a
     * string and written as a JSON number.
     *
     * @return list<array{currencyCode: string, amount: int, rate: string}>
     */
    public static function defaultRates(): array
    {
        return [
            ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.125'],
            ['currencyCode' => 'USD', 'amount' => 1, 'rate' => '22.5'],
            ['currencyCode' => 'GBP', 'amount' => 1, 'rate' => '29.75'],
            ['currencyCode' => 'HUF', 'amount' => 100, 'rate' => '6.31'],
            ['currencyCode' => 'JPY', 'amount' => 100, 'rate' => '15.2'],
            ['currencyCode' => 'IDR', 'amount' => 1000, 'rate' => '1.4'],
        ];
    }

    /**
     * The JSON body of one daily list valid for `$validFor`.
     *
     * A rate is written verbatim as a JSON number token, so `rate => '1.0E-5'` or `'-1'` produces exactly that
     * token; a row may also override `validFor` to build a mixed-date payload.
     *
     * @param  list<array<string, mixed>>|null  $rates  rows of `currencyCode`, `amount`, `rate` and an optional `validFor`
     */
    public static function dailyJson(string $validFor, ?array $rates = null): string
    {
        return '{"rates":['.implode(',', self::rowsJson($validFor, $rates)).']}';
    }

    /**
     * The JSON body of the `daily-year` answer for `$year`: one list of invented rows for every given day, in the
     * order given, as the CNB writes every published day of a year into one `rates` array.
     *
     * A day is written `Y-m-d`, or `m-d` for a day of `$year`; a full date of another year is kept as written, so a
     * test can build an answer that leaves the requested year. Every day carries the same invented rows unless
     * `$rates` names others.
     *
     * @param  list<string>  $days
     * @param  list<array<string, mixed>>|null  $rates  rows of `currencyCode`, `amount`, `rate` and an optional `validFor`
     */
    public static function yearJson(int $year, array $days, ?array $rates = null): string
    {
        $rows = [];

        foreach ($days as $day) {
            $validFor = strlen($day) === 5 ? sprintf('%04d-%s', $year, $day) : $day;

            array_push($rows, ...self::rowsJson($validFor, $rates));
        }

        return '{"rates":['.implode(',', $rows).']}';
    }

    /**
     * The JSON objects of one list, one per row.
     *
     * @param  list<array<string, mixed>>|null  $rates
     * @return list<string>
     */
    private static function rowsJson(string $validFor, ?array $rates): array
    {
        $rows = [];

        foreach ($rates ?? self::defaultRates() as $position => $row) {
            $rows[] = sprintf(
                '{"validFor":%s,"order":%d,"country":%s,"currency":%s,"amount":%s,"currencyCode":%s,"rate":%s}',
                json_encode($row['validFor'] ?? $validFor, JSON_THROW_ON_ERROR),
                198,
                json_encode('Country '.($position + 1), JSON_THROW_ON_ERROR),
                json_encode('Currency '.($position + 1), JSON_THROW_ON_ERROR),
                json_encode($row['amount'] ?? 1, JSON_THROW_ON_ERROR),
                json_encode($row['currencyCode'] ?? 'EUR', JSON_THROW_ON_ERROR),
                (string) ($row['rate'] ?? '1'),
            );
        }

        return $rows;
    }
}
