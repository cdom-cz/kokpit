<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
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
