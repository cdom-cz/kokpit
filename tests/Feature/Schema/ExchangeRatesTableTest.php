<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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

/**
 * Inserts one exchange rate row by raw SQL, with valid defaults, so no model, cast or Action stands between the
 * value and the constraint. Overrides replace single columns; the column names come from the test only.
 *
 * @param  array<string, mixed>  $overrides
 */
function exchangeRatesRawInsert(array $overrides = []): string
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

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into exchange_rates (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

describe('uniqueness (23505)', function (): void {
    it('refuses a second row for the same currency and day, whatever the unit amount and the rate', function (): void {
        exchangeRatesRawInsert();

        RawSql::expectSqlState('23505', fn () => exchangeRatesRawInsert());
        RawSql::expectSqlState('23505', fn () => exchangeRatesRawInsert(['unit_amount' => 100, 'rate' => '99.5']));
    });

    it('accepts the same currency on another day and another currency on the same day', function (): void {
        exchangeRatesRawInsert();

        RawSql::expectAllowed(fn () => exchangeRatesRawInsert(['rate_date' => '2026-10-13']));
        RawSql::expectAllowed(fn () => exchangeRatesRawInsert(['currency' => 'XTS']));
    });

    it('answers the unique violation to a plain insert of the same row twice (mutation self-check, first half)', function (): void {
        $row = [
            'rate_date' => '2026-10-12',
            'currency' => 'EUR',
            'unit_amount' => 1,
            'rate' => '25.125',
            'fetched_at' => '2026-10-12 14:45:00+00',
            'created_at' => '2026-10-12 14:45:00+00',
            'updated_at' => '2026-10-12 14:45:00+00',
        ];

        app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
            ExchangeRate::query()->insert($row);
        });

        RawSql::expectSqlState('23505', static function () use ($row): void {
            app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
                ExchangeRate::query()->insert($row);
            });
        });
    });
});

describe('checks (23514)', function (): void {
    it('refuses a currency that is not three upper-case letters', function (string $currency): void {
        RawSql::expectSqlState('23514', fn () => exchangeRatesRawInsert(['currency' => $currency]));
    })->with(['lower case' => 'eur', 'two letters' => 'EU', 'a digit' => 'E1R', 'blank' => '   ']);

    it('refuses a unit amount below 1', function (int $unitAmount): void {
        RawSql::expectSqlState('23514', fn () => exchangeRatesRawInsert(['unit_amount' => $unitAmount]));
    })->with(['zero' => 0, 'negative' => -1]);

    it('refuses a rate of zero or below', function (string $rate): void {
        RawSql::expectSqlState('23514', fn () => exchangeRatesRawInsert(['rate' => $rate]));
    })->with(['zero' => '0', 'negative' => '-1']);

    it('accepts the unit amounts the CNB publishes and a rate with ten places', function (): void {
        RawSql::expectAllowed(fn () => exchangeRatesRawInsert(['currency' => 'XAA', 'unit_amount' => 1]));
        RawSql::expectAllowed(fn () => exchangeRatesRawInsert(['currency' => 'XBB', 'unit_amount' => 100]));
        RawSql::expectAllowed(fn () => exchangeRatesRawInsert(['currency' => 'XCC', 'unit_amount' => 1000, 'rate' => '0.0000000001']));
    });
});

describe('catalogue', function (): void {
    it('generates a UUID version 7 for a row inserted without an id', function (): void {
        $id = exchangeRatesRawInsert();

        expect(DB::selectOne('select uuid_extract_version(id) as version from exchange_rates where id = ?', [$id])->version)->toBe(7);
    });

    it('stores the rate as numeric(20,10), the day as date and the fetch moment as timestamptz', function (): void {
        $columns = collect(DB::select(
            "select column_name, data_type, numeric_precision, numeric_scale from information_schema.columns where table_name = 'exchange_rates'",
        ))->keyBy('column_name');

        expect($columns['rate']->data_type)->toBe('numeric')
            ->and($columns['rate']->numeric_precision)->toBe(20)
            ->and($columns['rate']->numeric_scale)->toBe(10)
            ->and($columns['rate_date']->data_type)->toBe('date')
            ->and($columns['fetched_at']->data_type)->toBe('timestamp with time zone')
            ->and($columns['currency']->data_type)->toBe('character')
            ->and($columns['unit_amount']->data_type)->toBe('integer');
    });

    it('holds no float column', function (): void {
        $floats = DB::select(
            "select column_name from information_schema.columns where table_name = 'exchange_rates' and data_type in ('real', 'double precision')",
        );

        expect($floats)->toBe([]);
    });
});

describe('mutation self-check: each refusal is owed to its named constraint', function (): void {
    it('accepts the refused row once the constraint is dropped', function (string $constraint, array $row): void {
        exchangeRatesRawInsert();

        RawSql::expectSqlState(str_ends_with($constraint, '_unique') ? '23505' : '23514', fn () => exchangeRatesRawInsert($row));

        // DDL is transactional in PostgreSQL: the drop is rolled back with the test transaction.
        DB::statement("alter table exchange_rates drop constraint {$constraint}");

        RawSql::expectAllowed(fn () => exchangeRatesRawInsert($row));
    })->with([
        'unique key' => ['exchange_rates_currency_date_unique', ['unit_amount' => 100]],
        'currency check' => ['exchange_rates_currency_check', ['currency' => 'eur', 'rate_date' => '2026-10-14']],
        'unit amount check' => ['exchange_rates_unit_amount_check', ['unit_amount' => 0, 'currency' => 'XTS']],
        'rate check' => ['exchange_rates_rate_check', ['rate' => '0', 'currency' => 'XTS']],
    ]);
});
