<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
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
