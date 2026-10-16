<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a transaction category that nothing refers to (D-15, D-16).
 *
 * Only an unused category is deleted: one with children, and from the transactions plan on one that has
 * transactions, is refused with `in_use` (the Admin archives it instead). The system category is refused with
 * `system_locked`. The delete runs in a savepoint and the database is the real guard: a foreign key refusal
 * (SQLSTATE 23503) becomes `in_use` and the frozen row trigger (KP001) becomes `system_locked`, so a row that
 * appeared between the pre-check and the delete is still refused with a Czech message. A category that is
 * already gone is a no-op.
 */
final class DeleteTransactionCategory
{
    /**
     * @throws DomainException the category is in use or is the system category
     */
    public function handle(User $actor, TransactionCategory $category): void
    {
        Gate::forUser($actor)->authorize('delete', $category);

        try {
            DB::transaction(function () use ($category): void {
                $locked = TransactionCategory::query()->whereKey($category->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                if ($locked->is_system) {
                    throw new DomainException(__('finance.categories.errors.system_locked'));
                }

                if ($locked->children()->exists()) {
                    throw new DomainException(__('finance.categories.errors.in_use'));
                }

                $locked->delete();
            });
        } catch (QueryException $e) {
            $state = SqlState::of($e);

            if ($state === SqlState::FOREIGN_KEY) {
                throw new DomainException(__('finance.categories.errors.in_use'), previous: $e);
            }

            if ($state === SqlState::FROZEN_ROW) {
                throw new DomainException(__('finance.categories.errors.system_locked'), previous: $e);
            }

            throw $e;
        }
    }
}
