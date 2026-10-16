<?php

declare(strict_types=1);

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     * The categories with transactions in the period, the children's sums rolled into their parent (D-15, D-17).
     *
     * Income roots come first, then expense roots, each in the order of the category screen; the children of a root
     * follow its order too. A root lists a child only when that child has transactions in the period, and its total
     * is its own transactions plus those of its children. Archived categories stay, because their history stays.
     * The rows are grouped by `COALESCE(parent_id, id)`, the root of each category.
     *
     * @return list<array{id: string, name: string, type: string, total_minor: int, children: list<array{id: string, name: string, type: string, total_minor: int}>}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function byCategory(FinanceFilters $filters): array
    {
        $this->authorise();

        $sums = $this->inPeriod($filters)
            ->join('transaction_categories AS c', 'c.id', '=', 'transactions.category_id')
            ->selectRaw('transactions.category_id AS category_id, COALESCE(c.parent_id, c.id) AS root_id, COALESCE(SUM(transactions.czk_minor), 0) AS total')
            ->groupByRaw('transactions.category_id, COALESCE(c.parent_id, c.id)')
            ->toBase()
            ->get();

        if ($sums->isEmpty()) {
            return [];
        }

        $ids = $sums->flatMap(static fn (object $row): array => [(string) $row->category_id, (string) $row->root_id])->unique()->values()->all();
        /** @var Collection<string, TransactionCategory> $categories */
        $categories = TransactionCategory::query()->whereIn('id', $ids)->get()->keyBy('id');

        /** @var array<string, array{id: string, name: string, type: string, total_minor: int, position: int, children: list<array{id: string, name: string, type: string, total_minor: int, position: int}>}> $roots */
        $roots = [];

        foreach ($sums as $row) {
            $category = $categories[(string) $row->category_id] ?? null;
            $root = $categories[(string) $row->root_id] ?? null;

            if ($category === null || $root === null) {
                continue;
            }

            $total = (int) $row->total;
            $roots[$root->id] ??= ['id' => $root->id, 'name' => $root->name, 'type' => $root->type->value, 'total_minor' => 0, 'position' => $root->position, 'children' => []];
            $roots[$root->id]['total_minor'] += $total;

            if ($category->id !== $root->id) {
                $roots[$root->id]['children'][] = ['id' => $category->id, 'name' => $category->name, 'type' => $category->type->value, 'total_minor' => $total, 'position' => $category->position];
            }
        }

        $typeOrder = [TransactionType::Income->value => 0, TransactionType::Expense->value => 1];
        $byPosition = static fn (array $a, array $b): int => [$a['position'], $a['name']] <=> [$b['position'], $b['name']];

        $roots = array_values($roots);
        usort($roots, static fn (array $a, array $b): int => [$typeOrder[$a['type']] ?? 2, $a['position'], $a['name']] <=> [$typeOrder[$b['type']] ?? 2, $b['position'], $b['name']]);

        return array_map(static function (array $root) use ($byPosition): array {
            usort($root['children'], $byPosition);

            return [
                'id' => $root['id'],
                'name' => $root['name'],
                'type' => $root['type'],
                'total_minor' => $root['total_minor'],
                'children' => array_map(static fn (array $child): array => [
                    'id' => $child['id'],
                    'name' => $child['name'],
                    'type' => $child['type'],
                    'total_minor' => $child['total_minor'],
                ], $root['children']),
            ];
        }, $roots);
    }

    /**
     * Income, expense and balance per client in CZK, clients in Czech order and the transactions without a client
     * as the last row with a null `client_id` and an empty name (the screen words it). Archived clients stay.
     *
     * @return list<array{client_id: string|null, name: string, income_minor: int, expense_minor: int, balance_minor: int}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function byClient(FinanceFilters $filters): array
    {
        $this->authorise();

        $income = TransactionType::Income->value;
        $expense = TransactionType::Expense->value;

        $query = $this->inPeriod($filters)
            ->leftJoin('clients AS cl', 'cl.id', '=', 'transactions.client_id')
            ->selectRaw(
                'transactions.client_id AS client_id, cl.name AS name, '
                .'COALESCE(SUM(CASE WHEN transactions.type = ? THEN transactions.czk_minor END), 0) AS income, '
                .'COALESCE(SUM(CASE WHEN transactions.type = ? THEN transactions.czk_minor END), 0) AS expense',
                [$income, $expense],
            )
            ->groupBy('transactions.client_id', 'cl.name')
            ->orderByRaw('transactions.client_id IS NULL');
        CzechCollation::orderBy($query, 'cl.name');

        return array_values($query->orderBy('transactions.client_id')->toBase()->get()->map(static fn (object $row): array => [
            'client_id' => $row->client_id === null ? null : (string) $row->client_id,
            'name' => $row->name === null ? '' : (string) $row->name,
            'income_minor' => (int) $row->income,
            'expense_minor' => (int) $row->expense,
            'balance_minor' => (int) $row->income - (int) $row->expense,
        ])->all());
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
