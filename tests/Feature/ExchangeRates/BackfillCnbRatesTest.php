<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\Jobs\BackfillCnbRates;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CnbFixtures;

/*
 * The history backfill (EX-01, D-05): the Admin asks for a range on "Kurzy ČNB", a queued idempotent job stores the
 * lists of the covered years from the year endpoint, and a weekend or holiday day then converts at the previous valid
 * rate. Every payload is invented and assembled at runtime; Http::preventStrayRequests() blocks the live service.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The body of a `daily-year` answer: one invented EUR row per day, `m-d` => rate, in the order given.
 *
 * @param  array<string, string>  $daysToRate
 */
function backfillYearBody(int $year, array $daysToRate): string
{
    $rows = [];

    foreach ($daysToRate as $day => $rate) {
        $body = CnbFixtures::yearJson($year, [$day], [['currencyCode' => 'EUR', 'amount' => 1, 'rate' => $rate]]);
        $rows[] = substr($body, strlen('{"rates":['), -2);
    }

    return '{"rates":['.implode(',', $rows).']}';
}

/**
 * The CNB host answers a `daily-year` request with the given body of the requested year.
 *
 * @param  array<int, string>  $bodies  year => body
 */
function backfillServes(array $bodies): void
{
    $fakes = [];

    foreach ($bodies as $year => $body) {
        $fakes['*daily-year?year='.$year] = Http::response($body, 200, ['Content-Type' => 'application/json']);
    }

    Http::fake($fakes);
}

/**
 * The stored days, ascending, read in the system context.
 *
 * @return list<string>
 */
function backfillStoredDays(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => ExchangeRate::query()->orderBy('rate_date')->get()
            ->map(static fn (ExchangeRate $rate): string => $rate->rate_date->format('Y-m-d'))
            ->unique()->values()->all(),
    );
}

describe('the tracer: backfill a range from the overview', function (): void {
    it('queues the backfill, stores the lists of the year up to Do and answers a Saturday with the Friday rate', function (): void {
        backfillServes([2025 => backfillYearBody(2025, [
            '01-03' => '24.9',
            '02-28' => '25.0',
            '03-03' => '25.2',
            '06-27' => '25.5',
            '06-30' => '25.8',
            '07-01' => '26.1',
        ])]);

        Livewire::test(ListExchangeRates::class)
            ->callAction('backfill', ['from' => '2025-03-01', 'to' => '2025-06-30'])
            ->assertHasNoActionErrors()
            ->assertNotified(
                Notification::make()->info()->title('Doplnění historie je ve frontě')->body('Zpracuje se na pozadí. Výsledek uvidíte v přehledu kurzů.'),
            );

        // One request, for the one year the range touches; the list of 1 July is after Do and not stored.
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/exrates/daily-year') && str_contains($request->url(), 'year=2025'));

        expect(backfillStoredDays())->toBe(['2025-01-03', '2025-02-28', '2025-03-03', '2025-06-27', '2025-06-30']);

        // Saturday 2025-06-28 has no list of its own: the previous Friday's rate applies.
        $czk = app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2025-06-28');

        expect($czk->minor)->toBe(255000);
    });
});

/**
 * Runs the job in the system context, as a worker does, without the queue.
 */
function backfillRun(string $from, string $to): void
{
    app(PartnerContext::class)->runAsSystem(
        static fn () => (new BackfillCnbRates($from, $to))->handle(app(CnbClient::class)),
    );
}

/**
 * How many rows the stored lists hold, read in the system context.
 */
function backfillRowCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => ExchangeRate::query()->count());
}

/**
 * How many `daily-year` requests asked for `$year`.
 */
function backfillRequestsFor(int $year): int
{
    $count = 0;

    Http::assertSent(static function (Request $request) use (&$count, $year): bool {
        if (str_contains($request->url(), 'daily-year') && str_contains($request->url(), 'year='.$year)) {
            $count++;
        }

        return true;
    });

    return $count;
}

describe('idempotence', function (): void {
    it('stores nothing new and keeps the row count when the same range runs again', function (): void {
        backfillServes([2025 => backfillYearBody(2025, ['03-03' => '25.2', '06-27' => '25.5', '06-30' => '25.8'])]);

        backfillRun('2025-03-01', '2025-06-30');
        $first = backfillRowCount();

        backfillRun('2025-03-01', '2025-06-30');

        expect($first)->toBe(3)
            ->and(backfillRowCount())->toBe(3);
    });

    it('never rewrites a stored list, even when the CNB answer names another rate for the day', function (): void {
        backfillServes([2025 => backfillYearBody(2025, ['06-27' => '25.5'])]);
        backfillRun('2025-06-01', '2025-06-30');

        backfillServes([2025 => backfillYearBody(2025, ['06-27' => '99.9'])]);
        backfillRun('2025-06-01', '2025-06-30');

        $rate = app(PartnerContext::class)->runAsSystem(
            static fn (): string => (string) ExchangeRate::query()->where('rate_date', '2025-06-27')->value('rate'),
        );

        expect($rate)->toBe('25.5000000000')
            ->and(backfillRowCount())->toBe(1);
    });

    it('runs as the queued job through the sync queue and again as a rerun', function (): void {
        backfillServes([2025 => backfillYearBody(2025, ['06-27' => '25.5'])]);

        dispatch_sync(new BackfillCnbRates('2025-06-01', '2025-06-30'));
        dispatch_sync(new BackfillCnbRates('2025-06-01', '2025-06-30'));

        expect(backfillRowCount())->toBe(1);
    });
});

describe('the years it asks for', function (): void {
    it('asks for the year before when the margin of ten days crosses the new year', function (): void {
        backfillServes([
            2024 => backfillYearBody(2024, ['12-30' => '24.0', '12-31' => '24.1']),
            2025 => backfillYearBody(2025, ['01-02' => '24.5', '01-03' => '24.7', '02-10' => '25.0', '02-11' => '25.1']),
        ]);

        backfillRun('2025-01-05', '2025-02-10');

        Http::assertSentCount(2);

        expect(backfillRequestsFor(2024))->toBe(1)
            ->and(backfillRequestsFor(2025))->toBe(1)
            ->and(backfillStoredDays())->toBe(['2024-12-30', '2024-12-31', '2025-01-02', '2025-01-03', '2025-02-10']);

        // Sunday 2025-01-05 answers Friday 2025-01-03's list; Wednesday 2025-01-01 (a holiday) answers 2024-12-31.
        $this->actingAs(Canary::admin());
        $converter = app(ExchangeRateConverter::class);
        $hundred = Money::ofMinor(10000, 'EUR');

        expect($converter->toCzk($hundred, '2025-01-05')->minor)->toBe(247000)
            ->and($converter->toCzk($hundred, '2025-01-01')->minor)->toBe(241000);
    });

    it('asks once for each year of a range over three calendar years', function (): void {
        backfillServes([
            2024 => backfillYearBody(2024, ['11-04' => '24.0']),
            2025 => backfillYearBody(2025, ['06-02' => '25.0']),
            2026 => backfillYearBody(2026, ['01-30' => '26.0', '02-02' => '26.1']),
        ]);

        backfillRun('2024-11-01', '2026-02-01');

        Http::assertSentCount(3);

        expect(backfillRequestsFor(2024))->toBe(1)
            ->and(backfillRequestsFor(2025))->toBe(1)
            ->and(backfillRequestsFor(2026))->toBe(1)
            // The list of 2 February 2026 is after Do and stays unstored.
            ->and(backfillStoredDays())->toBe(['2024-11-04', '2025-06-02', '2026-01-30']);
    });

    it('asks for no year before the margin: a range inside one year costs one request', function (): void {
        backfillServes([2025 => backfillYearBody(2025, ['03-03' => '25.2'])]);

        backfillRun('2025-03-01', '2025-03-31');

        Http::assertSentCount(1);
    });
});

describe('a poisoned answer', function (): void {
    it('fails the job with a typed failure and stores nothing from that year', function (): void {
        backfillServes([
            2024 => backfillYearBody(2024, ['12-30' => '24.0']),
            // The second list carries a negative rate: the whole answer is refused, the good first list too.
            2025 => backfillYearBody(2025, ['01-02' => '24.5', '01-03' => '-1']),
        ]);

        expect(fn () => backfillRun('2024-12-20', '2025-03-01'))->toThrow(CnbDownloadFailed::class)
            ->and(backfillStoredDays())->toBe(['2024-12-30']);
    });

    it('fails on an answer that names a day of another year', function (): void {
        backfillServes([2025 => CnbFixtures::yearJson(2025, ['03-03', '2026-03-03'])]);

        expect(fn () => backfillRun('2025-03-01', '2025-06-30'))->toThrow(CnbDownloadFailed::class)
            ->and(backfillRowCount())->toBe(0);
    });

    it('fails on a rejected request and stores nothing', function (): void {
        Http::fake(['*' => Http::response('nope', 404)]);

        expect(fn () => backfillRun('2025-03-01', '2025-06-30'))->toThrow(CnbDownloadFailed::class)
            ->and(backfillRowCount())->toBe(0);

        Http::assertSentCount(1);
    });
});

describe('the range check of the job itself', function (): void {
    it('refuses a range with Od after Do before any request', function (): void {
        Http::fake();

        expect(fn () => new BackfillCnbRates('2025-06-30', '2025-03-01'))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });

    it('refuses a day that is not a calendar day before any request', function (): void {
        Http::fake();

        expect(fn () => new BackfillCnbRates('2025-02-30', '2025-06-30'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new BackfillCnbRates('2025-03-01', 'yesterday'))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });

    it('refuses a payload of the queue that was changed to a bad range, since a payload skips the constructor', function (): void {
        Http::fake();
        $payload = str_replace('2025-03-01', '2025-13-45', serialize(new BackfillCnbRates('2025-03-01', '2025-06-30')));
        $job = unserialize($payload);

        expect($job)->toBeInstanceOf(BackfillCnbRates::class)
            ->and(fn () => $job->handle(app(CnbClient::class)))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });

    it('refuses an Od before the earliest day, a Do in the future and a span above the maximum', function (): void {
        Http::fake();
        config(['kokpit.rates.backfill.max_span_days' => 30]);

        expect(fn () => new BackfillCnbRates('1999-12-31', '2000-01-10'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new BackfillCnbRates('2026-10-01', '2026-10-15'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new BackfillCnbRates('2026-09-14', '2026-10-14'))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });

    it('keeps its timeout below the 300 s supervisor', function (): void {
        $timeout = (new ReflectionClass(BackfillCnbRates::class))->getAttributes(Timeout::class)[0]->newInstance()->timeout;
        $supervisor = (int) collect(config('horizon.defaults'))->max('timeout');

        expect($timeout)->toBe(240)
            ->and($timeout)->toBeLessThan($supervisor);
    });
});
