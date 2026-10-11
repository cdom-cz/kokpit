<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalRules;
use Carbon\CarbonImmutable;

/**
 * Ticks a task done or undone. Allowed today and on past days, even locked ones; a day that has not
 * happened yet cannot be completed.
 */
final class ToggleSignalTaskDone
{
    use AuthorizesSignal;

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $taskId, bool $done): SignalTask
    {
        $this->authorizeActor($actor);

        $task = $this->findOwned(SignalTask::class, $taskId);

        if (! SignalRules::canToggleDone($task->for_date, SignalCalendar::today())) {
            throw SignalRuleViolation::because('future_day');
        }

        $task->is_done = $done;
        $task->completed_at = $done ? CarbonImmutable::now() : null;
        $task->save();

        return $task;
    }
}
