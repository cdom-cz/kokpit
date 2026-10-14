<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Cnb\CnbFailure;
use App\Domain\ExchangeRates\CzechPublicHolidays;
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
use Tests\Support\Canary;
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
 * The CNB answers a list on the daily endpoint and the given body (or, per year, bodies) on `daily-year`. The daily
 * list is valid for `$dailyFor`, or for the day asked for when that is null (a run on any day then finds today's
 * list published).
 *
 * @param  string|array<int, string>  $years  one body for every year, or a body per year
 */
function healServes(?string $dailyFor, string|array $years = []): void
{
    Http::fake([
        '*/exrates/daily-year*' => static function (Request $request) use ($years) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = is_array($years) ? ($years[(int) ($query['year'] ?? 0)] ?? null) : $years;

            return $body === null
                ? Http::response('CANARY_UNEXPECTED_YEAR', 404)
                : Http::response($body, 200, ['Content-Type' => 'application/json']);
        },
        '*/exrates/daily*' => static function (Request $request) use ($dailyFor) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(
                CnbFixtures::dailyJson($dailyFor ?? (string) ($query['date'] ?? '')),
                200,
                ['Content-Type' => 'application/json'],
            );
        },
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

describe('one heal a day and no needless request', function (): void {
    it('makes no second year request in a later run of the same day, but asks again the next day', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        // The answer does not hold the missing day, so the gap stays open and only the claim stops the second request.
        healServes(null, CnbFixtures::yearJson(2026, ['10-05'], healYearRates()));

        Artisan::call('kokpit:rates:download');
        healAt('2026-10-14 16:00:00');
        Artisan::call('kokpit:rates:download');

        expect(healYearRequests())->toBe([2026]);

        healAt('2026-10-15 15:00:00');
        Artisan::call('kokpit:rates:download');

        expect(healYearRequests())->toBe([2026, 2026]);
    });

    it('makes no year request on a fresh install that holds only today\'s list', function (): void {
        healAt('2026-10-14 15:00:00');
        healServes('2026-10-14');

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(0)
            ->and(healStoredDays())->toBe(['2026-10-14'])
            ->and(healYearRequests())->toBe([]);
    });

    it('makes no year request for a gap older than the lookback window', function (): void {
        healAt('2026-10-14 15:00:00');
        // 2026-08-03 is the oldest list; everything inside the 31 days before 2026-10-14 is stored.
        healStored('2026-08-03');

        foreach (CzechPublicHolidays::workingDays('2026-09-13', '2026-10-13') as $day) {
            healStored($day);
        }

        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['08-04'], healYearRates()));

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(0)
            ->and(healYearRequests())->toBe([])
            ->and(healStoredDays())->not->toContain('2026-08-04');
    });

    it('makes no year request on a day without a list to expect', function (): void {
        // Saturday 2026-10-10, with the list of Friday missing.
        healAt('2026-10-10 15:00:00');
        healStored('2026-10-07');
        healStored('2026-10-09');
        Http::fake();

        $exit = Artisan::call('kokpit:rates:download');

        Http::assertNothingSent();
        expect($exit)->toBe(0);
    });

    it('reads a missed working day as a gap and a holiday as none', function (): void {
        // Monday 2026-10-12 is missing; the long weekend before Statehood Day is not the point here, the
        // holiday 2026-09-28 sits inside the window and must not be asked for.
        healAt('2026-10-14 15:00:00');
        healStored('2026-09-24');

        foreach (CzechPublicHolidays::workingDays('2026-09-25', '2026-10-13') as $day) {
            healStored($day);
        }

        healServes('2026-10-14');

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(0)
            ->and(healStoredDays())->not->toContain('2026-09-28')
            ->and(healYearRequests())->toBe([]);
    });
});

describe('a gap across the new year', function (): void {
    it('asks for each affected year once', function (): void {
        // 2027-01-05 is a Tuesday; 2026-12-31 and 2027-01-04 have no list.
        healAt('2027-01-05 15:00:00');

        foreach (['2026-12-21', '2026-12-22', '2026-12-23', '2026-12-28', '2026-12-29', '2026-12-30'] as $day) {
            healStored($day);
        }

        healServes('2027-01-05', [
            2026 => CnbFixtures::yearJson(2026, ['12-31'], healYearRates()),
            2027 => CnbFixtures::yearJson(2027, ['01-04'], healYearRates()),
        ]);

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(0)
            ->and(healYearRequests())->toBe([2026, 2027])
            ->and(healStoredDays())->toContain('2026-12-31')->toContain('2027-01-04')->toContain('2027-01-05')
            ->and(Artisan::output())->toContain('Doplněno chybějících seznamů kurzů: 2.');
    });
});

describe('a year answer that cannot be trusted', function (): void {
    it('fails the run with Malformed, stores nothing from it and keeps today\'s rows, and the final run alerts', function (string $mode): void {
        $admin = Canary::admin();
        healAt('2026-10-14 18:00:00');
        healStoreOctoberExcept(['2026-10-12']);

        $good = CnbFixtures::yearJson(2026, ['10-12']);
        $body = match ($mode) {
            'a negative rate in one row' => str_replace('"rate":6.31', '"rate":-6.31', $good),
            'not json' => 'not json',
            'an empty list' => '{"rates":[]}',
            'a list of another year' => CnbFixtures::yearJson(2026, ['10-12', '2025-12-31']),
            'a currency twice in one list' => CnbFixtures::yearJson(2026, ['10-12'], [
                ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.125'],
                ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.5'],
            ]),
        };
        healServes('2026-10-14', $body);

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('Server ČNB vrátil neplatná data.')
            ->and(healStoredDays())->toContain('2026-10-14')->not->toContain('2026-10-12')
            ->and(healBell($admin))->toHaveCount(1);
    })->with([
        'a negative rate in one row',
        'not json',
        'an empty list',
        'a list of another year',
        'a currency twice in one list',
    ]);

    it('fails an hourly run quietly with a typed failure and no alert', function (): void {
        $admin = Canary::admin();
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', 'not json');

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(1)
            ->and(healStoredDays())->toContain('2026-10-14')
            ->and(healBell($admin))->toHaveCount(0);
    });

    it('asks a refusing year endpoint once and names the status', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', [2025 => '[]']);

        $exit = Artisan::call('kokpit:rates:download');
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('Server ČNB požadavek odmítl.')->toContain('Stavový kód odpovědi: 404.')
            ->and($output)->not->toContain('CANARY_UNEXPECTED_YEAR')
            ->and(healYearRequests())->toBe([2026]);
    });

    it('stores a good list of the answer only up to the run\'s day, never one dated after it', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-12', '10-15', '10-16', '11-02'], healYearRates()));

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(0)
            ->and(healStoredDays())->toContain('2026-10-12')
            ->not->toContain('2026-10-15')->not->toContain('2026-10-16')->not->toContain('2026-11-02');
    });

    it('never completes or rewrites a list that is already stored', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-12', '10-13'], healYearRates()));

        Artisan::call('kokpit:rates:download');

        $rates = app(PartnerContext::class)->runAsSystem(
            static fn (): array => ExchangeRate::query()->where('rate_date', '2026-10-13')->pluck('rate')->all(),
        );

        expect($rates)->toBe(['25.1250000000']);
    });
});

describe('where the heal does not run', function (): void {
    it('skips the heal when the daily step failed', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        Http::fake([
            '*/exrates/daily-year*' => Http::response(CnbFixtures::yearJson(2026, ['10-12']), 200),
            '*/exrates/daily*' => Http::response('CANARY_BODY_UNSET', 503),
        ]);

        $exit = Artisan::call('kokpit:rates:download');

        expect($exit)->toBe(1)
            ->and(healYearRequests())->toBe([])
            ->and(healStoredDays())->not->toContain('2026-10-12');
    });

    it('never heals from the manual download Action', function (): void {
        healAt('2026-10-14 15:00:00');
        healStoreOctoberExcept(['2026-10-12']);
        healServes('2026-10-14', CnbFixtures::yearJson(2026, ['10-12'], healYearRates()));

        $result = app(PartnerContext::class)->runAsSystem(
            static fn (): array => app(DownloadCnbRates::class)->handle('2026-10-14'),
        );

        expect($result['inserted'])->toBe(6)
            ->and(healYearRequests())->toBe([])
            ->and(healStoredDays())->not->toContain('2026-10-12');
    });

    it('reports the typed failure of a year answer from the client', function (): void {
        Http::fake(['*' => Http::response('not json', 200)]);

        try {
            app(CnbClient::class)->year(2026);
        } catch (CnbDownloadFailed $failure) {
            expect($failure->failure)->toBe(CnbFailure::Malformed);

            return;
        }

        throw new RuntimeException('The year answer did not fail.');
    });
});
