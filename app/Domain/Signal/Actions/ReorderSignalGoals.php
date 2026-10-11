<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use Illuminate\Support\Facades\DB;

/**
 * Stores a drag-and-drop order of the goals of a week and renumbers them to 1..n (a delete can leave
 * gaps). The ids must be exactly the goals of the week, otherwise the whole list is refused as stale.
 * The positions are rewritten row by row; the unique key is deferred, so it is checked at commit.
 */
final class ReorderSignalGoals
{
    use AuthorizesSignal;

    /**
     * @param  list<string>  $orderedIds
     *
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $weekStart, array $orderedIds): void
    {
        $this->authorizeActor($actor);

        if (! SignalCalendar::isWeekStart($weekStart)) {
            throw SignalRuleViolation::because('invalid_week');
        }

        if ($orderedIds === []) {
            return;
        }

        DB::transaction(function () use ($actor, $weekStart, $orderedIds): void {
            SignalLock::take((string) $actor->getKey(), $weekStart, 'goals');

            $existing = SignalWeeklyGoal::query()->where('week_start', $weekStart)->lockForUpdate()->pluck('id')->map(strval(...))->all();
            $given = array_values($orderedIds);

            if (count($given) !== count($existing) || array_diff($given, $existing) !== [] || count(array_unique($given)) !== count($given)) {
                throw SignalRuleViolation::because('order_stale');
            }

            foreach ($given as $index => $id) {
                SignalWeeklyGoal::query()->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
