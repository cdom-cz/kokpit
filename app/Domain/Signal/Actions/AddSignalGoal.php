<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Facades\DB;

/**
 * Adds a goal to a week (the Monday that identifies it), at the lowest free position 1..3.
 * A week holds at most three goals; the count and the insert run under a lock of the week, and the
 * position key of the database is the last line.
 */
final class AddSignalGoal
{
    use AuthorizesSignal;

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $weekStart, string $title): SignalWeeklyGoal
    {
        $this->authorizeActor($actor);

        $title = $this->cleanTitle($title);

        if (! SignalCalendar::isWeekStart($weekStart)) {
            throw SignalRuleViolation::because('invalid_week');
        }

        return DB::transaction(function () use ($actor, $weekStart, $title): SignalWeeklyGoal {
            SignalLock::take((string) $actor->getKey(), $weekStart, 'goals');

            $used = SignalWeeklyGoal::query()->where('week_start', $weekStart)->pluck('position')->map(intval(...))->all();

            if (count($used) >= SignalRules::MAX_GOALS_PER_WEEK) {
                throw SignalRuleViolation::because('goal_limit', ['max' => SignalRules::MAX_GOALS_PER_WEEK]);
            }

            $position = 1;

            while (in_array($position, $used, true)) {
                $position++;
            }

            return SignalWeeklyGoal::query()->create(['week_start' => $weekStart, 'title' => $title, 'position' => $position]);
        });
    }
}
