<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Support\SignalLock;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a goal and renumbers the remaining goals of its week to 1..n in their current order, so a
 * goal added afterwards always lands last (the same as a task, which goes to the end of its group).
 * The positions are rewritten row by row under the lock of the week; the unique key of the position is
 * deferred, so it is checked at commit, the same as in ReorderSignalGoals.
 */
final class DeleteSignalGoal
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id): void
    {
        $this->authorizeActor($actor);

        $found = $this->findOwned(SignalWeeklyGoal::class, $id);

        DB::transaction(function () use ($actor, $found): void {
            SignalLock::take((string) $actor->getKey(), $found->week_start, 'goals');

            $found->delete();

            $remaining = SignalWeeklyGoal::query()->where('week_start', $found->week_start)->orderBy('position')->lockForUpdate()->pluck('id');

            foreach ($remaining->values() as $index => $goalId) {
                SignalWeeklyGoal::query()->whereKey($goalId)->update(['position' => $index + 1]);
            }
        });
    }
}
