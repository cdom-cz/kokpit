<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Jobs;

use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads the CNB history of a day range in the background (EX-01, D-05; UI-SPEC Surface A).
 *
 * The CNB serves one year per request, so the job asks once for every calendar year from the year of `from` minus
 * ten days (the list before a holiday block at the start of the range) to the year of `to`, and stores every list of
 * the answers dated not after `to`: whole years are cheap and the table keeps every published list (D-02). Weekends
 * and holidays have no list; a lookup answers them with the newest earlier one, so the range needs no special days.
 *
 * Idempotent: rows go in with insert-or-ignore on the unique key (currency, day), so a rerun over the same range, an
 * overlapping range or a retry after a partial run inserts nothing twice. A year is stored in one transaction, and
 * `CnbClient::year()` validates the whole answer before it returns anything, so a poisoned year stores nothing and
 * ends in a typed failure, which follows the Phase 3 failed-job path (retries, then the alert).
 *
 * The range is validated again here, behind the form of the Admin, because a payload of the queue is not a form:
 * both days are real calendar days, `from` is not after `to`, `from` is not before the configured earliest day, `to`
 * is not in the future and the span stays within the configured maximum. The timeout stays under the 300 s of the
 * Horizon supervisor.
 */
#[Idempotent(how: 'unique (currency, rate_date) with insert-or-ignore; a rerun of the same range inserts nothing new')]
#[Timeout(240)]
final class BackfillCnbRates extends KokpitJob
{
    /** The list before a holiday block at the start of the range is published up to this many days earlier. */
    private const int MARGIN_DAYS = 10;

    /**
     * @param  string  $from  first day of the range, `Y-m-d`
     * @param  string  $to  last day of the range, `Y-m-d`
     *
     * @throws InvalidArgumentException when the range is not one a backfill accepts
     */
    public function __construct(public readonly string $from, public readonly string $to)
    {
        self::guard($from, $to);
    }

    /**
     * The limits a range breaks, as the keys of `kokpit.rates.backfill.errors`: `order`, `earliest`, `future`, `span`.
     * Empty for a range within the limits. A day that is not a real calendar day is not judged here.
     *
     * `order` and `earliest` belong to `from`, `future` and `span` to `to`. A span is counted in days including
     * both ends and is only judged for a range in order.
     *
     * @return list<string>
     */
    public static function violations(string $from, string $to): array
    {
        $first = PragueDay::day($from);
        $last = PragueDay::day($to);

        if ($first === null || $last === null) {
            return [];
        }

        $violations = [];

        if ($first > $last) {
            $violations[] = 'order';
        }

        if ($first < (string) config('kokpit.rates.backfill.earliest')) {
            $violations[] = 'earliest';
        }

        if ($last > CarbonImmutable::now('Europe/Prague')->format('Y-m-d')) {
            $violations[] = 'future';
        }

        if ($first <= $last && self::spanDays($first, $last) > self::maxSpanDays()) {
            $violations[] = 'span';
        }

        return $violations;
    }

    /**
     * The longest span in days one backfill may cover.
     */
    private static function maxSpanDays(): int
    {
        return max(1, (int) config('kokpit.rates.backfill.max_span_days'));
    }

    /**
     * @throws CnbDownloadFailed
     * @throws InvalidArgumentException when the range is not one a backfill accepts
     */
    public function handle(CnbClient $client): void
    {
        // The payload came out of the queue, not through the constructor.
        self::guard($this->from, $this->to);

        $firstYear = self::day($this->from)->subDays(self::MARGIN_DAYS)->year;
        $lastYear = self::day($this->to)->year;

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $lists = $client->year($year);

            DB::transaction(function () use ($lists): void {
                foreach ($lists as $list) {
                    if ($list->validFor <= $this->to) {
                        ExchangeRate::query()->insertOrIgnore($list->rows(now()));
                    }
                }
            });
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function guard(string $from, string $to): void
    {
        foreach ([$from, $to] as $day) {
            if (PragueDay::day($day) !== $day) {
                throw new InvalidArgumentException('Not a calendar day: '.$day);
            }
        }

        $violations = self::violations($from, $to);

        if ($violations !== []) {
            throw new InvalidArgumentException('Not a range the backfill accepts: '.implode(', ', $violations));
        }
    }

    /**
     * The days from `$from` to `$to`, both included.
     */
    private static function spanDays(string $from, string $to): int
    {
        return intdiv(self::day($to)->getTimestamp() - self::day($from)->getTimestamp(), 86400) + 1;
    }

    private static function day(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC') ?? throw new InvalidArgumentException('Not a calendar day: '.$day);
    }
}
