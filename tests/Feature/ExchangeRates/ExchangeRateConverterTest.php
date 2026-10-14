<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The conversion helper (EX-03, D-01, D-02, D-04, D-05): the CZK amount of a currency, an amount and a day, with
 * the newest stored CNB list not after that day. Weekends and holidays are resolved by lookup, an outage keeps
 * using the last known rate, a per-unit quote is divided by its unit amount, and a missing rate is a typed error
 * and never a zero. Every rate and date below is invented.
 */

/**
 * Stores one invented rate row in the system context, as the download Action does.
 *
 * @param  array<string, mixed>  $overrides
 */
function converterStore(array $overrides = []): void
{
    $row = [
        'rate_date' => '2026-10-14',
        'currency' => 'EUR',
        'unit_amount' => 1,
        'rate' => '25.125',
        'fetched_at' => '2026-10-14 14:45:00+00',
        'created_at' => '2026-10-14 14:45:00+00',
        'updated_at' => '2026-10-14 14:45:00+00',
        ...$overrides,
    ];

    app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
        ExchangeRate::query()->insert($row);
    });
}

describe('toCzk', function (): void {
    it('converts an amount at the stored rate of its day', function (): void {
        // 2026-10-14 is a Wednesday.
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);

        $this->actingAs(Canary::admin());

        $czk = app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-10-14');

        expect($czk->minor)->toBe(251250)
            ->and($czk->currency)->toBe('CZK');
    });

    it('uses the previous valid list on a Saturday and a Sunday', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);
        converterStore(['rate_date' => '2026-10-16', 'rate' => '25.5']);

        $this->actingAs(Canary::admin());
        $converter = app(ExchangeRateConverter::class);
        $hundred = Money::ofMinor(10000, 'EUR');

        // Friday 16th has its own list, Saturday 17th and Sunday 18th use Friday's.
        expect($converter->toCzk($hundred, '2026-10-16')->minor)->toBe(255000)
            ->and($converter->toCzk($hundred, '2026-10-17')->minor)->toBe(255000)
            ->and($converter->toCzk($hundred, '2026-10-18')->minor)->toBe(255000)
            ->and($converter->toCzk($hundred, '2026-10-15')->minor)->toBe(251250);
    });

    it('returns a CZK amount unchanged without any lookup', function (): void {
        $this->actingAs(Canary::admin());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $czk = app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(123456, 'CZK'), '2026-10-14');

        expect($czk->minor)->toBe(123456)
            ->and($czk->currency)->toBe('CZK')
            ->and($queries)->toBe(0);
    });

    it('throws a typed error naming the currency and the day when no list exists on or before the day', function (): void {
        converterStore(['rate_date' => '2026-10-14']);

        $this->actingAs(Canary::admin());

        try {
            app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-10-13');
            $this->fail('A day before the first stored list must not convert.');
        } catch (ExchangeRateMissing $e) {
            expect($e)->toBeInstanceOf(RuntimeException::class)
                ->and($e->currency)->toBe('EUR')
                ->and($e->day)->toBe('2026-10-13');
        }
    });

    it('throws for a currency the lists never carried', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'currency' => 'EUR']);

        $this->actingAs(Canary::admin());

        expect(fn () => app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'USD'), '2026-10-14'))
            ->toThrow(ExchangeRateMissing::class);
    });

    it('keeps using the newest list thirty days later however old it is', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);

        $this->actingAs(Canary::admin());

        $czk = app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-11-13');

        expect($czk->minor)->toBe(251250);
    });
});
