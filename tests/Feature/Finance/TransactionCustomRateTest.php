<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * The own exchange rate of one transaction (FI-01, D-06): a rate the caller gives for this one transaction makes
 * the CZK figure custom and is stored as the snapshot. Rates and amounts are invented.
 */

/**
 * The seeded root expense category "Software" of the starter set.
 */
function ownRateSoftware(): TransactionCategory
{
    return TransactionCategory::query()
        ->where('type', TransactionType::Expense->value)
        ->whereNull('parent_id')
        ->where('name', 'Software')
        ->sole();
}

/**
 * The data of a valid EUR expense; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownRateData(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => ownRateSoftware()->getKey(),
        'amount' => '100,00',
        'currency' => 'EUR',
        'description' => 'Fictional licence',
        ...$overrides,
    ];
}

/**
 * Stores one invented CNB rate row in the system context, as the download Action does.
 *
 * @param  array<string, mixed>  $overrides
 */
function ownRateCnb(array $overrides = []): void
{
    $row = [
        'rate_date' => '2026-10-09',
        'currency' => 'EUR',
        'unit_amount' => 1,
        'rate' => '24.500',
        'fetched_at' => '2026-10-09 14:45:00+00',
        'created_at' => '2026-10-09 14:45:00+00',
        'updated_at' => '2026-10-09 14:45:00+00',
        ...$overrides,
    ];

    app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
        ExchangeRate::query()->upsert([$row], ['currency', 'rate_date'], ['rate', 'unit_amount']);
    });
}

describe('recording a transaction at an own rate', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
    });

    it('converts at the own rate, stores it as custom and takes the transaction date as the rate date', function (): void {
        ownRateCnb();

        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25.125']));

        expect($transaction->amount_minor)->toBe(10000)
            ->and($transaction->czk_minor)->toBe(251250)
            ->and($transaction->czk_source)->toBe(CzkSource::Custom)
            ->and($transaction->exchange_rate)->toBe('25.1250000000')
            ->and($transaction->exchange_unit_amount)->toBe(1)
            ->and($transaction->exchange_rate_date->toDateString())->toBe('2026-10-14');
    });

    it('converts a unit amount of one hundred', function (): void {
        ownRateCnb(['currency' => 'JPY', 'unit_amount' => 100, 'rate' => '16.000']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData([
            'amount' => '15000',
            'currency' => 'JPY',
            'exchange_rate' => '16.25',
            'exchange_unit_amount' => 100,
        ]));

        expect($transaction->czk_minor)->toBe(243750)
            ->and($transaction->czk_source)->toBe(CzkSource::Custom)
            ->and($transaction->exchange_unit_amount)->toBe(100);
    });

    it('never writes the own rate to the CNB list', function (): void {
        ownRateCnb();
        $before = ExchangeRate::query()->count();

        app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25.125']));

        expect(ExchangeRate::query()->count())->toBe($before)
            ->and(ExchangeRate::query()->where('rate', '25.125')->exists())->toBeFalse();
    });
});

/**
 * The field errors of a ValidationException a callback raises, or null when it raises nothing.
 *
 * @return array<string, list<string>>|null
 */
function ownRateErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

describe('the checks of an own rate', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
        $this->record = fn (array $overrides) => ownRateErrors(fn () => app(RecordTransaction::class)->handle($this->admin, ownRateData($overrides)));
    });

    it('refuses a rate that is zero, malformed or out of bounds under exchange_rate', function (string $rate): void {
        $errors = ($this->record)(['exchange_rate' => $rate]);

        expect($errors)->toHaveKey('exchange_rate')
            ->and($errors['exchange_rate'][0])->toBe(__('finance.transactions.errors.rate_invalid'));
    })->with([
        'zero' => ['0'],
        'zero with fraction' => ['0.0000000000'],
        'negative' => ['-1'],
        'exponent' => ['1e3'],
        'letters' => ['abc'],
        'empty' => [''],
        'eleven integer digits' => [str_repeat('1', 11)],
        'eleven fraction digits' => ['1.'.str_repeat('1', 11)],
    ]);

    it('accepts a comma like a dot and the widest rate the column holds', function (): void {
        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25,125']));

        expect($transaction->czk_minor)->toBe(251250)
            ->and($transaction->czk_source)->toBe(CzkSource::Custom);

        $widest = str_repeat('9', 10).'.'.str_repeat('9', 10);

        expect(($this->record)(['exchange_rate' => $widest, 'amount' => '0,01']))->toBeNull();
    });

    it('refuses a unit amount outside one to the largest integer under exchange_unit_amount', function (mixed $unit): void {
        $errors = ($this->record)(['exchange_rate' => '25.125', 'exchange_unit_amount' => $unit]);

        expect($errors)->toHaveKey('exchange_unit_amount')
            ->and($errors['exchange_unit_amount'][0])->toBe(__('finance.transactions.errors.unit_invalid'));
    })->with([
        'zero' => [0],
        'negative' => [-1],
        'above the integer column' => [2147483647 + 1],
        'letters' => ['x'],
    ]);

    it('keeps a unit amount of one when none is given and stores the largest one', function (): void {
        $default = app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25.125']));
        $largest = app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25.125', 'exchange_unit_amount' => 2147483647]));

        expect($default->exchange_unit_amount)->toBe(1)
            ->and($largest->exchange_unit_amount)->toBe(2147483647);
    });

    it('refuses a unit amount without a rate under exchange_unit_amount', function (): void {
        $errors = ($this->record)(['exchange_unit_amount' => 100, 'czk_amount' => '2 500,00']);

        expect($errors)->toHaveKey('exchange_unit_amount')
            ->and($errors['exchange_unit_amount'][0])->toBe(__('finance.transactions.errors.unit_without_rate'));
    });

    it('refuses a rate together with a typed CZK figure and stores nothing', function (): void {
        $before = Transaction::query()->count();
        $errors = ($this->record)(['exchange_rate' => '25.125', 'czk_amount' => '2 500,00']);

        expect($errors)->toHaveKey('exchange_rate')
            ->and($errors['exchange_rate'][0])->toBe(__('finance.transactions.errors.rate_with_czk_amount'))
            ->and(Transaction::query()->count())->toBe($before);
    });

    it('lets the rate stand when the typed figure is switched off with czk_manual false', function (): void {
        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData([
            'exchange_rate' => '25.125',
            'czk_amount' => '2 500,00',
            'czk_manual' => false,
        ]));

        expect($transaction->czk_source)->toBe(CzkSource::Custom)
            ->and($transaction->czk_minor)->toBe(251250);
    });

    it('wins over a CNB list of the same currency and day', function (): void {
        ownRateCnb(['rate_date' => '2026-10-14', 'rate' => '24.000']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData(['exchange_rate' => '25.125']));

        expect($transaction->czk_source)->toBe(CzkSource::Custom)
            ->and($transaction->czk_minor)->toBe(251250);
    });

    it('applies the CNB rule of Phase 9 unchanged without a rate', function (): void {
        ownRateCnb(['rate_date' => '2026-10-14', 'rate' => '24.000']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData());

        expect($transaction->czk_source)->toBe(CzkSource::Cnb)
            ->and($transaction->czk_minor)->toBe(240000);
    });

    it('accepts a known currency without any CNB row with a rate or a figure and refuses it with neither', function (): void {
        $custom = app(RecordTransaction::class)->handle($this->admin, ownRateData(['currency' => 'ISK', 'exchange_rate' => '0.18']));
        $manual = app(RecordTransaction::class)->handle($this->admin, ownRateData(['currency' => 'ISK', 'czk_amount' => '18,00']));

        expect($custom->czk_source)->toBe(CzkSource::Custom)
            ->and($custom->czk_minor)->toBe(1800)
            ->and($manual->czk_source)->toBe(CzkSource::Manual)
            ->and($manual->czk_minor)->toBe(1800)
            ->and(($this->record)(['currency' => 'ISK']))->toHaveKey('currency');
    });

    it('refuses an unknown currency code even with a rate', function (): void {
        expect(($this->record)(['currency' => 'XYZ', 'exchange_rate' => '25.125']))->toHaveKey('currency');
    });

    it('stores a CZK amount with a rate as identity without a snapshot', function (): void {
        $transaction = app(RecordTransaction::class)->handle($this->admin, ownRateData(['currency' => 'CZK', 'exchange_rate' => '25.125']));

        expect($transaction->czk_source)->toBe(CzkSource::Identity)
            ->and($transaction->czk_minor)->toBe(10000)
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_unit_amount)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull();
    });

    it('refuses an amount whose conversion does not fit under amount', function (): void {
        $errors = ($this->record)([
            'amount' => str_repeat('9', 12).',00',
            'exchange_rate' => str_repeat('9', 10),
        ]);

        expect($errors)->toHaveKey('amount');
    });
});
