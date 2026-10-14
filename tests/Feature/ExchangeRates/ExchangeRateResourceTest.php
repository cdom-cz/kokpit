<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ExchangeRateResource;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CnbFixtures;

/*
 * The Admin-only "Kurzy ČNB" overview (EX-01, EX-02; D-02, D-05; UI-SPEC Surface A): the stored lists as one row per
 * currency and day, and the synchronous "Stáhnout dnešní kurzy" action. Every rate, date and name is invented; the
 * CNB answers come from CnbFixtures through Http::fake (stray requests are blocked by the TestCase).
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Stores one invented rate row dated `$day`, in the system context like the download writes it.
 */
function rateResourceStore(string $day, string $currency = 'EUR', string $rate = '25.1250000000', int $unit = 1, string $fetchedAt = '14:45:00'): ExchangeRate
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($day, $currency, $rate, $unit, $fetchedAt): ExchangeRate {
        ExchangeRate::query()->insert([
            'rate_date' => $day,
            'currency' => $currency,
            'unit_amount' => $unit,
            'rate' => $rate,
            'fetched_at' => $day.' '.$fetchedAt.'+00',
            'created_at' => $day.' '.$fetchedAt.'+00',
            'updated_at' => $day.' '.$fetchedAt.'+00',
        ]);

        return ExchangeRate::query()->where('rate_date', $day)->where('currency', $currency)->firstOrFail();
    });
}

/**
 * The CNB host answers every request with an invented list valid for `$validFor`.
 *
 * @param  list<array<string, mixed>>|null  $rates
 */
function rateResourceServes(string $validFor, ?array $rates = null): void
{
    Http::fake(['*' => Http::response(CnbFixtures::dailyJson($validFor, $rates), 200, ['Content-Type' => 'application/json'])]);
}

describe('the tracer: open the page, fetch today\'s list, read it', function (): void {
    it('opens for the Admin with the heading', function (): void {
        $this->get(ExchangeRateResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Kurzy ČNB')
            ->assertSee('Stáhnout dnešní kurzy');
    });

    it('stores today\'s list with the download action and lists the rate trimmed to three decimals', function (): void {
        rateResourceServes('2026-10-14', [
            ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.125'],
            ['currencyCode' => 'USD', 'amount' => 1, 'rate' => '22.5'],
        ]);

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()->success()->title('Kurzy byly staženy')->body('Platnost ke dni 14. 10. 2026, 2 měn.'),
            );

        $rows = app(PartnerContext::class)->runAsSystem(
            static fn (): array => ExchangeRate::query()->where('rate_date', '2026-10-14')->orderBy('currency')->pluck('rate', 'currency')->all(),
        );

        expect($rows)->toBe(['EUR' => '25.1250000000', 'USD' => '22.5000000000']);

        $page = Livewire::test(ListExchangeRates::class);

        $page->assertSee('25,125')
            ->assertSee('22,500')
            ->assertSee('14. 10. 2026');
    });
});
