<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * The storage end of the CNB rates (EX-01, D-02). One row per currency and published day, the unit amount
 * as published, a rate that reads back as the exact decimal string written, and no rate row visible to a
 * Partner. Currency codes, rates and dates are invented.
 */

/**
 * Stores one invented rate row in the system context, as the download Action will (explicit values and
 * timestamps; the primary key comes from the database default).
 *
 * @param  array<string, mixed>  $overrides
 */
function exchangeRatesStore(array $overrides = []): void
{
    $row = [
        'rate_date' => '2026-10-12',
        'currency' => 'EUR',
        'unit_amount' => 1,
        'rate' => '25.125',
        'fetched_at' => '2026-10-12 14:45:00+00',
        'created_at' => '2026-10-12 14:45:00+00',
        'updated_at' => '2026-10-12 14:45:00+00',
        ...$overrides,
    ];

    app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
        ExchangeRate::query()->insert($row);
    });
}

describe('reading (Admin)', function (): void {
    it('reads a stored rate back as the exact decimal string and the stored day', function (): void {
        exchangeRatesStore();

        $this->actingAs(Canary::admin());

        $rate = ExchangeRate::query()->where('currency', 'EUR')->sole();

        expect($rate->rate)->toBe('25.1250000000')
            ->and($rate->unit_amount)->toBe(1)
            ->and($rate->rate_date->toDateString())->toBe('2026-10-12');
    });

    it('stores the unit amount as published', function (): void {
        exchangeRatesStore(['currency' => 'XTS', 'unit_amount' => 100, 'rate' => '1.5']);

        $this->actingAs(Canary::admin());

        $rate = ExchangeRate::query()->where('currency', 'XTS')->sole();

        expect($rate->unit_amount)->toBe(100)
            ->and($rate->rate)->toBe('1.5000000000');
    });

    it('lets the Admin read every row', function (): void {
        exchangeRatesStore();
        exchangeRatesStore(['currency' => 'XTS', 'unit_amount' => 100, 'rate' => '1.5']);

        $this->actingAs(Canary::admin());

        expect(ExchangeRate::query()->count())->toBe(2);
    });
});

describe('Partner visibility', function (): void {
    it('shows a Partner with a client no exchange rate row', function (): void {
        [$clientA] = Canary::twoClients();
        exchangeRatesStore();
        exchangeRatesStore(['currency' => 'XTS', 'unit_amount' => 100, 'rate' => '1.5']);

        $this->actingAs(Canary::partnerFor($clientA));

        expect(ExchangeRate::query()->count())->toBe(0);
    });

    it('grants a Partner no ability on ExchangeRate', function (): void {
        [$clientA] = Canary::twoClients();
        exchangeRatesStore();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);
        $row = app(PartnerContext::class)->runAsSystem(static fn (): ExchangeRate => ExchangeRate::query()->firstOrFail());

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, ExchangeRate::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $row))->toBeFalse($ability);
        }
    });

    it('grants the Admin the abilities on ExchangeRate', function (): void {
        exchangeRatesStore();

        $admin = Canary::admin();
        $this->actingAs($admin);
        $row = ExchangeRate::query()->firstOrFail();

        expect(Gate::forUser($admin)->allows('viewAny', ExchangeRate::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('view', $row))->toBeTrue();
    });
});

it('gives a row stored without an id a database-generated key', function (): void {
    exchangeRatesStore();

    expect(DB::selectOne('select count(*) as n from exchange_rates where id is not null')->n)->toBe(1);
});
