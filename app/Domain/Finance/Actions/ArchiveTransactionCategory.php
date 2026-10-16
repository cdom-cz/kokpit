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
 * Archives or restores a transaction category (D-15, D-16).
 *
 * Archiving hides a category from the pickers for new transactions and keeps every transaction booked to it;
 * it is a timestamp, never a delete. The system income category can never be archived: the Action refuses it
 * with `system_locked`, and the guard trigger of the table (KP001) is the backstop. A repeated call is a
 * no-op that keeps the first `archived_at`.
 */
final class ArchiveTransactionCategory
{
    /**
     * @throws DomainException the category is the system category
     */
    public function handle(User $actor, TransactionCategory $category, bool $archive): TransactionCategory
    {
        Gate::forUser($actor)->authorize('update', $category);

        try {
            return DB::transaction(function () use ($category, $archive): TransactionCategory {
                $locked = TransactionCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->is_system) {
                    throw new DomainException(__('finance.categories.errors.system_locked'));
                }

                if ($archive !== $locked->isArchived()) {
                    $locked->forceFill(['archived_at' => $archive ? now() : null])->save();
                }

                return $locked->refresh();
            });
        } catch (QueryException $e) {
            if (SqlState::of($e) === SqlState::FROZEN_ROW) {
                throw new DomainException(__('finance.categories.errors.system_locked'), previous: $e);
            }

            throw $e;
        }
    }
}
