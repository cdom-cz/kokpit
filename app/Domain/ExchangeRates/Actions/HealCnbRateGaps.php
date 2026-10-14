<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Actions;

use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Cnb\CnbList;
use App\Domain\ExchangeRates\CzechPublicHolidays;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Repairs silent gaps in the stored CNB history (EX-01, research Pitfall 4).
 *
 * A Czech working day without a stored list looks exactly like a holiday to a lookup, which then answers an older
 * rate without a warning. The scheduled download calls this after a successful daily step: it looks for working
 * days without a list in the window and, when there are any, fetches the affected years from `daily-year` and
 * stores the missing lists.
 *
 * The window runs from the later of the oldest stored list and `gap_lookback_days` before `$day` up to the previous
 * working day of `$day` (today's list is the daily step's business). Before the oldest stored list nothing is
 * expected, so a fresh install asks for nothing; a gap older than the lookback is the backfill's job (D-05). One
 * query reads the stored days of the window.
 *
 * Request discipline (COVERAGE.md): a heal claim `kokpit:cnb-gap-heal:<day>` allows one round per Prague day, so a
 * day the calendar wrongly expects (assumption A5) or a poisoned answer costs one request a day and not one per
 * hourly attempt. A round asks for one request per affected year, and every answer is validated before anything is
 * stored. A list dated after `$day` is ignored; rows are stored with insert-or-ignore, so a rerun or a concurrent
 * run inserts nothing twice.
 *
 * Must run in an explicit system context (the console command does): it reads `exchange_rates`, whose scope is
 * closed without a signed-in user.
 */
final readonly class HealCnbRateGaps
{
    public function __construct(private CnbClient $client) {}

    /**
     * @param  string  $day  the Prague day of the run, `Y-m-d`; a Czech working day
     * @return int how many missing lists were stored
     *
     * @throws CnbDownloadFailed
     * @throws InvalidArgumentException when `$day` is not a calendar day
     */
    public function handle(string $day): int
    {
        if (PragueDay::day($day) !== $day) {
            throw new InvalidArgumentException('Not a calendar day: '.$day);
        }

        $window = $this->window($day);

        if ($window === null) {
            return 0;
        }

        $stored = $this->storedDays($window[0], $day);
        $years = $this->missingYears($window, $stored);

        // The claim is taken only for a round that makes a request, and kept whatever the answer is.
        if ($years === [] || ! Cache::add('kokpit:cnb-gap-heal:'.$day, true, now()->addDays(2))) {
            return 0;
        }

        // Every answer is validated before anything is stored, so a bad year leaves the earlier ones unused too.
        $answers = [];

        foreach ($years as $year) {
            $answers[] = $this->client->year($year);
        }

        $healed = 0;

        foreach ($answers as $lists) {
            foreach ($lists as $list) {
                // A published list is never completed or rewritten: only a day with no stored row is filled, and
                // never one after the run's day.
                if ($list->validFor >= $window[0] && $list->validFor <= $day && ! in_array($list->validFor, $stored, true) && $this->store($list) > 0) {
                    $healed++;
                }
            }
        }

        return $healed;
    }

    /**
     * The days from `$from` to `$to` that have at least one stored row.
     *
     * @return list<string>
     */
    private function storedDays(string $from, string $to): array
    {
        $days = [];

        foreach (ExchangeRate::query()->whereBetween('rate_date', [$from, $to])->distinct()->pluck('rate_date') as $date) {
            $days[] = CarbonImmutable::parse((string) $date)->format('Y-m-d');
        }

        return $days;
    }

    /**
     * The years of the working days in the window that have no stored list, ascending.
     *
     * @param  array{0: string, 1: string}  $window
     * @param  list<string>  $stored
     * @return list<int>
     */
    private function missingYears(array $window, array $stored): array
    {
        $years = [];

        foreach (CzechPublicHolidays::workingDays($window[0], $window[1]) as $working) {
            if (! in_array($working, $stored, true)) {
                $years[(int) substr($working, 0, 4)] = true;
            }
        }

        $years = array_keys($years);
        sort($years);

        return $years;
    }

    /**
     * The first and the last day of the window, or null when nothing is stored or the window is empty.
     *
     * @return array{0: string, 1: string}|null
     */
    private function window(string $day): ?array
    {
        $oldest = ExchangeRate::query()->min('rate_date');

        if (! is_string($oldest)) {
            return null;
        }

        $lookback = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')
            ?->modify('-'.max(0, (int) config('kokpit.rates.gap_lookback_days')).' days')
            ->format('Y-m-d');
        $from = max(CarbonImmutable::parse($oldest)->format('Y-m-d'), (string) $lookback);
        $to = CzechPublicHolidays::previousWorkingDay($day);

        return $from > $to ? null : [$from, $to];
    }

    private function store(CnbList $list): int
    {
        return ExchangeRate::query()->insertOrIgnore($list->rows(now()));
    }
}
