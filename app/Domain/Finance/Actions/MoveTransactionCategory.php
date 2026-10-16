<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Moves a category one place up or down among its siblings (D-15).
 *
 * The siblings are the categories of the same type and parent (spatie/eloquent-sortable through
 * `TransactionCategory::buildSortQuery()`), so roots move among roots of their type and a child among the
 * children of its parent, never across groups. The first sibling moving up and the last moving down change
 * nothing. The group is locked in id order for the swap, so two moves cannot interleave. The system category
 * can be moved: its position is one of the columns the guard trigger leaves mutable.
 */
final class MoveTransactionCategory
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    /**
     * @param  string  $direction  `up` or `down`
     *
     * @throws InvalidArgumentException a direction other than `up` or `down`
     */
    public function handle(User $actor, TransactionCategory $category, string $direction): void
    {
        Gate::forUser($actor)->authorize('update', $category);

        if ($direction !== self::UP && $direction !== self::DOWN) {
            throw new InvalidArgumentException('The direction must be up or down.');
        }

        DB::transaction(function () use ($category, $direction): void {
            $locked = TransactionCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            // Lock the whole sibling group in a fixed order before the swap.
            $locked->buildSortQuery()->orderBy('id')->lockForUpdate()->pluck('id');

            if ($direction === self::UP) {
                $locked->moveOrderUp();
            } else {
                $locked->moveOrderDown();
            }
        });
    }
}
