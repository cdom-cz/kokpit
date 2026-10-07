<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Probes\MoneyProbe;
use Tests\Support\Uuids;

beforeEach(function () {
    MoneyProbe::provision();
});

it('persists a duration-based amount in two columns and reads it back equal', function () {
    $amount = Money::forDuration(Money::ofMinor(123456, 'CZK'), 4020);

    $probe = MoneyProbe::create(['amount' => $amount]);
    $reloaded = MoneyProbe::query()->findOrFail($probe->id);
    $row = DB::table('money_probes')->first();

    expect($probe->id)->toMatch(Uuids::V7_PATTERN)
        ->and($reloaded->amount)->toBeInstanceOf(Money::class)
        ->and($reloaded->amount?->equals($amount))->toBeTrue()
        ->and($reloaded->amount?->minor)->toBe(137859)
        ->and($reloaded->amount?->currency)->toBe('CZK')
        ->and($row?->amount_minor)->toBe(137859)
        ->and($row?->amount_currency)->toBe('CZK');
});

it('stores no amount as two null columns and reads it back as null', function () {
    $probe = MoneyProbe::create(['amount' => null]);
    $reloaded = MoneyProbe::query()->findOrFail($probe->id);
    $row = DB::table('money_probes')->first();

    expect($reloaded->amount)->toBeNull()
        ->and($row?->amount_minor)->toBeNull()
        ->and($row?->amount_currency)->toBeNull();
});

it('replaces the amount through the cast when the attribute is reassigned', function () {
    $probe = MoneyProbe::create(['amount' => Money::ofMinor(100, 'CZK')]);

    $probe->amount = Money::ofMinor(250, 'EUR');
    $probe->save();

    $row = DB::table('money_probes')->first();

    expect($row?->amount_minor)->toBe(250)
        ->and($row?->amount_currency)->toBe('EUR');
});

it('rejects a value that is not a Money instance', function () {
    MoneyProbe::create(['amount' => 12.5]);
})->throws(InvalidArgumentException::class);

it('rejects a lower-case currency with a CHECK violation and keeps the transaction usable', function () {
    $sqlState = null;

    try {
        DB::transaction(fn () => DB::insert(
            "INSERT INTO money_probes (amount_minor, amount_currency, created_at, updated_at) VALUES (100, 'czk', now(), now())",
        ));
    } catch (QueryException $e) {
        $sqlState = $e->errorInfo[0] ?? null;
    }

    expect($sqlState)->toBe('23514')
        ->and(DB::table('money_probes')->count())->toBe(0);
});

it('reads a stored decimal rate back as a string with ten fractional digits', function () {
    $probe = MoneyProbe::create(['rate' => '0.0166666667']);
    $reloaded = MoneyProbe::query()->findOrFail($probe->id);

    expect($reloaded->rate)->toBeString()->toBe('0.0166666667');
});
