<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Actions;

use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\TimeTracking\Support\PragueDay;
use InvalidArgumentException;

/**
 * Downloads the CNB list for one day and stores it, once (EX-01, D-02).
 *
 * A published list is immutable, so a stored list is never rewritten: when a list dated `$day` is already stored
 * no request is made, and otherwise the answer is stored with insert-or-ignore on the unique key, so a rerun or
 * a concurrent run inserts nothing twice. The list is stored under the day the CNB names (`validFor`), which on a
 * weekend or holiday is an earlier day than `$day`.
 *
 * Must run in an explicit system context (the console command does): the stored-day check reads
 * `exchange_rates`, whose scope is closed without a signed-in user.
 */
final readonly class DownloadCnbRates
{
    public function __construct(private CnbClient $client) {}

    /**
     * @param  string  $day  `Y-m-d`
     * @return array{valid_for: string|null, inserted: int, skipped: bool}
     *
     * @throws CnbDownloadFailed
     * @throws InvalidArgumentException when `$day` is not a calendar day
     */
    public function handle(string $day): array
    {
        if (PragueDay::day($day) !== $day) {
            throw new InvalidArgumentException('Not a calendar day: '.$day);
        }

        if (ExchangeRate::query()->where('rate_date', $day)->exists()) {
            return ['valid_for' => $day, 'inserted' => 0, 'skipped' => true];
        }

        $list = $this->client->daily($day);

        return [
            'valid_for' => $list->validFor,
            'inserted' => ExchangeRate::query()->insertOrIgnore($list->rows(now())),
            'skipped' => false,
        ];
    }
}
