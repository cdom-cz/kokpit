<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\CnbFixtures;

/*
 * The gap self-heal of the scheduled CNB download (EX-01, research Pitfall 4): a Czech working day inside the
 * lookback window without a stored list is fetched once from the year endpoint, so a missing day no longer looks
 * like a holiday. Every payload is invented and assembled at runtime; Http::preventStrayRequests() blocks the
 * live service.
 */

beforeEach(function (): void {
    Sleep::fake();
    Cache::flush();
});

/**
 * Freezes the clock at the given Prague local time.
 */
function healAt(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Europe/Prague'));
}

/**
 * Stores one invented list dated `$day`, in the system context like the download writes it.
 */
function healStored(string $day, string $rate = '25.125'): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($day, $rate): void {
        ExchangeRate::query()->insert([
            'rate_date' => $day,
            'currency' => 'EUR',
            'unit_amount' => 1,
            'rate' => $rate,
            'fetched_at' => $day.' 14:45:00+00',
            'created_at' => $day.' 14:45:00+00',
            'updated_at' => $day.' 14:45:00+00',
        ]);
    });
}

/**
 * The CNB answers today's list on the daily endpoint and the given body (or, per year, bodies) on `daily-year`.
 *
 * @param  string|array<int, string>  $years  one body for every year, or a body per year
 */
function healServes(string $dailyFor, string|array $years = []): void
{
    Http::fake([
        '*/exrates/daily-year*' => static function (Request $request) use ($years) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = is_array($years) ? ($years[(int) ($query['year'] ?? 0)] ?? null) : $years;

            return $body === null
                ? Http::response('CANARY_UNEXPECTED_YEAR', 404)
                : Http::response($body, 200, ['Content-Type' => 'application/json']);
        },
        '*/exrates/daily*' => Http::response(
            CnbFixtures::dailyJson($dailyFor),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

/**
 * The invented rows a year answer carries: EUR with a rate that no stored list uses, so a lookup shows whose list
 * answered it.
 *
 * @return list<array<string, mixed>>
 */
function healYearRates(): array
{
    return [['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '24.5']];
}

/**
 * The number of requests that went to the year endpoint, and their `year` query values.
 *
 * @return list<int>
 */
function healYearRequests(): array
{
    $years = [];

    Http::recorded(static function (Request $request) use (&$years): void {
        if (str_contains($request->url(), '/exrates/daily-year')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $years[] = (int) $query['year'];
        }
    });

    return $years;
}

/**
 * The distinct stored list dates, ascending.
 *
 * @return list<string>
 */
function healStoredDays(): array
{
    /** @var list<string> $days */
    $days = app(PartnerContext::class)->runAsSystem(
        static fn (): array => ExchangeRate::query()
            ->orderBy('rate_date')
            ->distinct()
            ->pluck('rate_date')
            ->map(static fn (mixed $day): string => CarbonImmutable::parse((string) $day)->toDateString())
            ->all(),
    );

    return $days;
}

/**
 * Stores the lists of 2026-10-05 to 2026-10-13 (working days) except the given ones.
 *
 * @param  list<string>  $except
 */
function healStoreOctoberExcept(array $except): void
{
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13'] as $day) {
        if (! in_array($day, $except, true)) {
            healStored($day);
        }
    }
}

/**
 * @return Collection<int, object>
 */
function healBell(object $admin): Collection
{
    return DB::table('notifications')->where('notifiable_id', $admin->getKey())->get();
}

describe('a missed working day', function (): void {
    it('is fetched from the year endpoint once and filled by the next scheduled run', function (): void {
        // 2026-10-14 is a Wednesday; the list of Monday 2026-10-12 was never stored.
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-05', '10-06', '10-07', '10-08', '10-09', '10-12', '10-13', '10-14'], healYearRates()));

        $exit = Artisan::call('kokpit:rates:download');
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and(healYearRequests())->toBe([2026])
            ->and(healStoredDays())->toContain('2026-10-12')->toContain('2026-10-14')
            ->and($output)->toContain('Doplněno chybějících seznamů kurzů: 1.');
    });

    it('is answered by its own list in a lookup afterwards instead of the older one', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-12', '10-13', '10-14'], healYearRates()));

        Artisan::call('kokpit:rates:download');

        $lookup = app(PartnerContext::class)->runAsSystem(
            static fn (): ?array => RateBook::load(['EUR'], '2026-10-12', '2026-10-12')->rateFor('EUR', '2026-10-12'),
        );

        expect($lookup)->not->toBeNull()
            ->and($lookup['rate_date'])->toBe('2026-10-12')
            ->and($lookup['rate'])->toBe('24.5000000000');
    });

    it('is filled once even when the heal runs again, without a second row', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-12'], healYearRates()));

        Artisan::call('kokpit:rates:download');
        Cache::flush();
        Artisan::call('kokpit:rates:download');

        $rows = app(PartnerContext::class)->runAsSystem(
            static fn (): int => ExchangeRate::query()->where('rate_date', '2026-10-12')->count(),
        );

        expect($rows)->toBe(1)
            ->and(healYearRequests())->toBe([2026]);
    });
});
