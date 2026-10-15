<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
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

describe('per-unit quotes', function (): void {
    it('divides a rate quoted for 100 or 1000 units by its unit amount', function (string $currency, int $unit, string $rate, int $amountMinor, int $expectedMinor): void {
        converterStore(['currency' => $currency, 'unit_amount' => $unit, 'rate' => $rate]);

        $this->actingAs(Canary::admin());

        $czk = app(ExchangeRateConverter::class)->toCzk(Money::ofMinor($amountMinor, $currency), '2026-10-14');

        expect($czk->minor)->toBe($expectedMinor)
            ->and($czk->currency)->toBe('CZK');
    })->with([
        'HUF per 100' => ['HUF', 100, '6.481', 100000, 6481],
        'JPY per 100, no fraction digits' => ['JPY', 100, '15.064', 1000, 15064],
        'IDR per 1000' => ['IDR', 1000, '1.402', 100000000, 140200],
        'EUR per 1' => ['EUR', 1, '25.125', 10000, 251250],
    ]);
});

describe('durationToCzk', function (): void {
    it('prices a duration in CZK with one rounding, not by converting the rounded client amount', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);

        $this->actingAs(Canary::admin());

        $rate = Money::ofMinor(10000, 'EUR');
        $converter = app(ExchangeRateConverter::class);

        $once = $converter->durationToCzk($rate, 61, '2026-10-14');

        expect($once->minor)->toBe(4257)
            ->and($once->equals(Money::forDurationConverted($rate, 61, '25.125', 'CZK')))->toBeTrue()
            ->and($once->minor)->not->toBe($converter->toCzk(Money::forDuration($rate, 61), '2026-10-14')->minor);
    });

    it('prices a CZK rate without a lookup', function (): void {
        $this->actingAs(Canary::admin());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $rate = Money::ofMinor(123456, 'CZK');
        $czk = app(ExchangeRateConverter::class)->durationToCzk($rate, 4020, '2026-10-14');

        expect($czk->equals(Money::forDuration($rate, 4020)))->toBeTrue()
            ->and($queries)->toBe(0);
    });

    it('divides the per-unit rate of a duration by its unit amount', function (): void {
        converterStore(['currency' => 'JPY', 'unit_amount' => 100, 'rate' => '15.064']);

        $this->actingAs(Canary::admin());

        $czk = app(ExchangeRateConverter::class)->durationToCzk(Money::ofMinor(5000, 'JPY'), 3600, '2026-10-14');

        expect($czk->minor)->toBe(75320);
    });

    it('throws when no list exists for the day of the entry', function (): void {
        $this->actingAs(Canary::admin());

        expect(fn () => app(ExchangeRateConverter::class)->durationToCzk(Money::ofMinor(10000, 'EUR'), 60, '2026-10-14'))
            ->toThrow(ExchangeRateMissing::class);
    });
});

describe('RateBook', function (): void {
    it('answers a Monday with the last list before a gap of ten days', function (): void {
        converterStore(['rate_date' => '2026-09-30', 'rate' => '24.5']);
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);
        converterStore(['rate_date' => '2026-09-30', 'currency' => 'USD', 'rate' => '22']);

        $this->actingAs(Canary::admin());

        $book = RateBook::load(null, '2026-10-12', '2026-10-16');

        expect($book->rateFor('EUR', '2026-10-12')['rate_date'])->toBe('2026-09-30')
            ->and($book->rateFor('EUR', '2026-10-13')['rate'])->toBe('24.5000000000')
            ->and($book->rateFor('EUR', '2026-10-14')['rate_date'])->toBe('2026-10-14')
            ->and($book->rateFor('EUR', '2026-10-16')['rate'])->toBe('25.1250000000')
            ->and($book->rateFor('USD', '2026-10-16')['rate_date'])->toBe('2026-09-30')
            ->and($book->rateFor('CHF', '2026-10-16'))->toBeNull();
    });

    it('never uses a list dated after the asked day', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'rate' => '25.125']);
        converterStore(['rate_date' => '2026-10-16', 'rate' => '26']);

        $this->actingAs(Canary::admin());

        $book = RateBook::load(['EUR'], '2026-10-13', '2026-10-16');

        expect($book->rateFor('EUR', '2026-10-13'))->toBeNull()
            ->and($book->rateFor('EUR', '2026-10-15')['rate'])->toBe('25.1250000000')
            ->and($book->rateFor('EUR', '2026-10-16')['rate'])->toBe('26.0000000000');
    });

    it('carries the unit amount of the list that applies', function (): void {
        converterStore(['rate_date' => '2026-10-14', 'currency' => 'HUF', 'unit_amount' => 100, 'rate' => '6.481']);

        $this->actingAs(Canary::admin());

        expect(RateBook::load(['HUF'], '2026-10-14', '2026-10-14')->rateFor('HUF', '2026-10-14')['unit_amount'])->toBe(100);
    });

    it('answers every day of a 31-day range with two queries', function (): void {
        converterStore(['rate_date' => '2026-09-25', 'rate' => '24.5']);
        converterStore(['rate_date' => '2026-10-07', 'rate' => '25']);
        converterStore(['rate_date' => '2026-10-21', 'rate' => '25.125']);
        converterStore(['rate_date' => '2026-10-07', 'currency' => 'USD', 'rate' => '22']);

        $this->actingAs(Canary::admin());

        // The Partner scope reads the acting user's roles once on its first query; only rate reads are counted.
        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'exchange_rates')) {
                $queries++;
            }
        });

        $book = RateBook::load(['EUR', 'USD'], '2026-10-01', '2026-10-31');
        $found = 0;

        for ($day = CarbonImmutable::parse('2026-10-01'); $day->lessThanOrEqualTo('2026-10-31'); $day = $day->addDay()) {
            expect($book->rateFor('EUR', $day->toDateString()))->not->toBeNull();
            $found++;
        }

        expect($found)->toBe(31)
            ->and($queries)->toBe(2)
            ->and($book->rateFor('EUR', '2026-10-06')['rate_date'])->toBe('2026-09-25')
            ->and($book->rateFor('EUR', '2026-10-20')['rate_date'])->toBe('2026-10-07')
            ->and($book->rateFor('USD', '2026-10-31')['rate_date'])->toBe('2026-10-07');
    });

    it('refuses a malformed day or a reversed range', function (): void {
        expect(fn () => RateBook::load(null, '14.10.2026', '2026-10-16'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => RateBook::load(null, '2026-10-16', '2026-10-14'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Partner context', function (): void {
    it('finds no rate for a Partner although rows exist', function (): void {
        [$clientA] = Canary::twoClients();
        converterStore(['rate_date' => '2026-10-14']);

        $this->actingAs(Canary::partnerFor($clientA));

        expect(fn () => app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-10-14'))
            ->toThrow(ExchangeRateMissing::class);
    });
});
