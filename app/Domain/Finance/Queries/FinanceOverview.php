<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures of the finance overview: income, expense and balance of a period, in CZK (FI-02, D-17).
 *
 * Every sum is a sum of the stored `czk_minor` of the transactions of the period; nothing is converted again
 * (D-13). The period is a half-open range of Prague calendar dates over the transaction date. Money stays in
 * minor units until the screen formats it.
 *
 * The reads go through the Transaction model, so its Partner scope applies as well, but the reader also refuses
 * everybody but the Admin and a system run on its own: finance is closed to Partners (T-09-51).
 */
final class FinanceOverview
{
    /**
     * Income, expense and balance (income minus expense, negative when the expenses are higher) of the period.
     *
     * @return array{income: Money, expense: Money, balance: Money}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function totals(FinanceFilters $filters): array
    {
        $this->authorise();

        $sums = $this->inPeriod($filters)
            ->selectRaw('transactions.type AS type, COALESCE(SUM(transactions.czk_minor), 0) AS total')
            ->groupBy('transactions.type')
            ->toBase()
            ->pluck('total', 'type');

        $income = Money::ofMinor((int) ($sums[TransactionType::Income->value] ?? 0), 'CZK');
        $expense = Money::ofMinor((int) ($sums[TransactionType::Expense->value] ?? 0), 'CZK');

        return ['income' => $income, 'expense' => $expense, 'balance' => $income->minus($expense)];
    }

    /**
     * The transactions dated inside the period.
     *
     * @return Builder<Transaction>
     */
    private function inPeriod(FinanceFilters $filters): Builder
    {
        $period = $filters->period();

        return Transaction::query()
            ->where('transactions.transaction_date', '>=', $period['from'])
            ->where('transactions.transaction_date', '<', $period['until']);
    }

    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The finance overview is available to the Admin only.');
        }
    }
}
