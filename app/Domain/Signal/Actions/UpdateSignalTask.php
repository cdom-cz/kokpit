<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Facades\DB;

/**
 * Renames a task and, for a planned one, changes its colour. Full editing only on a day that is not
 * locked. A grey (`extra`) task keeps its colour. Moving a task to a limited colour re-checks the
 * day's limit under the lock of the day, and the task goes to the end of its new colour group.
 */
final class UpdateSignalTask
{
    use AuthorizesSignal;

    public function __construct(private readonly SignalDayReader $reader) {}

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $taskId, string $title, ?SignalCategory $category): SignalTask
    {
        $this->authorizeActor($actor);

        $title = $this->cleanTitle($title);
        $found = $this->findOwned(SignalTask::class, $taskId);

        return DB::transaction(function () use ($actor, $found, $title, $category): SignalTask {
            SignalLock::take((string) $actor->getKey(), $found->for_date);

            $task = SignalTask::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();

            if (! SignalRules::canEditDay($task->for_date, $this->reader->isUnlocked($task->for_date), SignalCalendar::today())) {
                throw SignalRuleViolation::because('day_locked');
            }

            $target = $task->category;

            if ($task->category !== SignalCategory::Extra) {
                if ($category === null || ! $category->isPlannable()) {
                    throw SignalRuleViolation::because('category_required');
                }

                $target = $category;
            }

            if ($target !== $task->category) {
                $counts = $this->reader->categoryCounts($task->for_date);
                $reserved = $this->reader->recurringCounts($task->for_date);

                if (! SignalRules::canAdd($target, $counts[$target->value], $reserved[$target->value])) {
                    throw SignalRuleViolation::because('limit_'.$target->value, ['limit' => SignalRules::effectiveLimit($target, $reserved[$target->value])]);
                }

                $max = SignalTask::query()->where('for_date', $task->for_date)->where('category', $target->value)->max('position');
                $task->position = $max === null ? 0 : ((int) $max) + 1;
            }

            $task->title = $title;
            $task->category = $target;
            $task->save();

            return $task;
        });
    }
}
