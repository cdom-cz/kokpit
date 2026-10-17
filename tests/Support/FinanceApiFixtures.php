<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Fixtures of the finance API tests.
 *
 * Pest helper functions are global for the whole suite, so the helpers of the finance API tests live here as
 * static methods, next to {@see ApiFixtures}. Every value is fictional.
 */
final class FinanceApiFixtures
{
    /**
     * The seeded root expense category "Software" of the starter set.
     */
    public static function softwareCategory(): TransactionCategory
    {
        return self::system(static fn (): TransactionCategory => TransactionCategory::query()
            ->where('type', TransactionType::Expense->value)
            ->whereNull('parent_id')
            ->where('name', 'Software')
            ->sole());
    }

    /**
     * The first child of "Software" by position.
     */
    public static function softwareChild(): TransactionCategory
    {
        $root = self::softwareCategory();

        return self::system(static fn (): TransactionCategory => TransactionCategory::query()
            ->where('parent_id', $root->id)
            ->orderBy('position')
            ->firstOrFail());
    }

    /**
     * The first root income category that is not the system one.
     */
    public static function incomeCategory(): TransactionCategory
    {
        return self::system(static fn (): TransactionCategory => TransactionCategory::query()
            ->where('type', TransactionType::Income->value)
            ->whereNull('parent_id')
            ->where('is_system', false)
            ->orderBy('position')
            ->firstOrFail());
    }

    /**
     * Stores one invented CNB rate row in the system context, as the download Action does.
     */
    public static function cnbRate(string $currency, string $day, string $rate, int $unit = 1): void
    {
        $row = [
            'rate_date' => $day,
            'currency' => $currency,
            'unit_amount' => $unit,
            'rate' => $rate,
            'fetched_at' => $day.' 14:45:00+00',
            'created_at' => $day.' 14:45:00+00',
            'updated_at' => $day.' 14:45:00+00',
        ];

        self::system(static function () use ($row): void {
            ExchangeRate::query()->upsert([$row], ['currency', 'rate_date'], ['rate', 'unit_amount']);
        });
    }

    /**
     * A transaction recorded through the domain Action with the actor signed in: a CZK expense in "Software" by
     * default, the overrides replace single keys of the Action data.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function transaction(User $admin, array $overrides = []): Transaction
    {
        $data = [
            'type' => 'expense',
            'transaction_date' => '2026-10-14',
            'category_id' => self::softwareCategory()->id,
            'amount' => '1 234,50',
            'currency' => 'CZK',
            'description' => 'Example licence',
            'counterparty' => 'Demo Vendor',
            ...$overrides,
        ];

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($admin);

        try {
            return app(RecordTransaction::class)->handle($admin, $data);
        } finally {
            Auth::forgetGuards();
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function system(Closure $callback): mixed
    {
        return app(PartnerContext::class)->runAsSystem($callback);
    }
}
