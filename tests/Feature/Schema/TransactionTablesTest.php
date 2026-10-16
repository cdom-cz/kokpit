<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * The storage end of the finance transactions (FI-01, D-12, D-13): one row per income or expense with its
 * amount, its CZK figure and the snapshot of the conversion, readable by the Admin only. The database rules
 * (types, keys, money and snapshot checks) follow in the later describe blocks. Names and amounts are
 * invented; no real business data.
 */

/**
 * The seeded root expense category "Software" of the starter set.
 */
function transactionTablesSoftwareCategory(): TransactionCategory
{
    return TransactionCategory::query()
        ->where('type', TransactionType::Expense->value)
        ->whereNull('parent_id')
        ->where('name', 'Software')
        ->sole();
}

describe('storing and reading a transaction', function (): void {
    it('stores a CZK expense with its CZK amount and gives the Admin the amount as Money', function (): void {
        $this->actingAs(Canary::admin());
        $counterparty = Canary::canary('counterparty');

        app(PartnerContext::class)->runAsSystem(static function () use ($counterparty): void {
            (new Transaction(['counterparty' => $counterparty, 'description' => 'Fictional licence']))->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(123450, 'CZK'),
                'czk_minor' => 123450,
                'czk_source' => 'identity',
            ])->save();
        });

        $transaction = Transaction::query()->where('counterparty', $counterparty)->sole();

        expect($transaction->type)->toBe(TransactionType::Expense)
            ->and($transaction->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($transaction->amount)->toBeInstanceOf(Money::class)
            ->and($transaction->amount->equals(Money::ofMinor(123450, 'CZK')))->toBeTrue()
            ->and($transaction->czk_minor)->toBe(123450)
            ->and($transaction->czk_source)->toBe('identity')
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_unit_amount)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull()
            ->and($transaction->client_id)->toBeNull()
            ->and($transaction->project_id)->toBeNull()
            ->and($transaction->category->name)->toBe('Software');
    });
});

describe('Partner visibility', function (): void {
    it('shows a Partner with a client no transaction row', function (): void {
        [$clientA] = Canary::twoClients();
        app(PartnerContext::class)->runAsSystem(static function (): void {
            (new Transaction(['counterparty' => Canary::canary('counterparty')]))->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(5000, 'CZK'),
                'czk_minor' => 5000,
                'czk_source' => 'identity',
            ])->save();
        });

        $this->actingAs(Canary::partnerFor($clientA));

        expect(Transaction::query()->count())->toBe(0);
    });

    it('grants a Partner no ability on Transaction', function (): void {
        [$clientA] = Canary::twoClients();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);
        $row = app(PartnerContext::class)->runAsSystem(static function (): Transaction {
            $transaction = (new Transaction)->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(5000, 'CZK'),
                'czk_minor' => 5000,
                'czk_source' => 'identity',
            ]);
            $transaction->save();

            return $transaction;
        });

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, Transaction::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $row))->toBeFalse($ability);
        }
    });

    it('grants the Admin the abilities on Transaction', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        expect(Gate::forUser($admin)->allows('viewAny', Transaction::class))->toBeTrue();
    });
});
