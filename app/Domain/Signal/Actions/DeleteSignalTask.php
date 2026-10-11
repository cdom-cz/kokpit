<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalRules;

/**
 * Deletes a task. Only on a day that is not locked, and not a task of today that its recurring template
 * still produces: the next materialization would generate it again with a new id. The user skips such a
 * task by switching the template off. The rule is the one the view uses to hide the button
 * (SignalDayReader::pinnedTaskIds).
 */
final class DeleteSignalTask
{
    use AuthorizesSignal;

    public function __construct(private readonly SignalDayReader $reader) {}

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $taskId): void
    {
        $this->authorizeActor($actor);

        $task = $this->findOwned(SignalTask::class, $taskId);

        if (! SignalRules::canEditDay($task->for_date, $this->reader->isUnlocked($task->for_date), SignalCalendar::today())) {
            throw SignalRuleViolation::because('day_locked');
        }

        if ($this->reader->pinnedTaskIds($task->for_date, collect([$task])) !== []) {
            throw SignalRuleViolation::because('recurring_pinned');
        }

        $task->delete();
    }
}
