<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Canary;

/*
 * The write paths of transactions (FI-01, D-12, D-13): the CZK rule that fixes the CZK figure when a transaction
 * is recorded, and the record, update and delete Actions. Names, amounts and rates are invented.
 */

/**
 * The seeded root expense category "Software" of the starter set.
 */
function czkTestSoftware(): TransactionCategory
{
    return TransactionCategory::query()
        ->where('type', TransactionType::Expense->value)
        ->whereNull('parent_id')
        ->where('name', 'Software')
        ->sole();
}

/**
 * The data of a valid CZK expense; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function czkTestData(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => czkTestSoftware()->getKey(),
        'amount' => '1 234,50',
        'currency' => 'CZK',
        'description' => 'Fictional licence',
        'counterparty' => Canary::canary('counterparty'),
        ...$overrides,
    ];
}

describe('recording a CZK transaction', function (): void {
    it('stores the amount as the CZK figure with source identity and no rate', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        $transaction = app(RecordTransaction::class)->handle($admin, czkTestData());

        expect($transaction->type)->toBe(TransactionType::Expense)
            ->and($transaction->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($transaction->amount_minor)->toBe(123450)
            ->and($transaction->amount_currency)->toBe('CZK')
            ->and($transaction->czk_minor)->toBe(123450)
            ->and($transaction->czk_source)->toBe(CzkSource::Identity)
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_unit_amount)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull()
            ->and($transaction->czk()->equals(Money::ofMinor(123450, 'CZK')))->toBeTrue()
            ->and($transaction->description)->toBe('Fictional licence');
    });

    it('refuses a Partner and stores nothing', function (): void {
        $this->actingAs(Canary::admin());
        $data = czkTestData();
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(fn () => app(RecordTransaction::class)->handle($partner, $data))
            ->toThrow(AuthorizationException::class);

        $this->actingAs(Canary::admin());
        expect(Transaction::query()->where('counterparty', $data['counterparty'])->exists())->toBeFalse();
    });
});

describe('the CZK source labels', function (): void {
    it('shows the Czech label of every source', function (): void {
        expect(CzkSource::Identity->getLabel())->toBe('Kč')
            ->and(CzkSource::Cnb->getLabel())->toBe('Kurz ČNB')
            ->and(CzkSource::Manual->getLabel())->toBe('Zadáno ručně');
    });
});
