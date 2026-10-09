<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Actions\StopTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\TimerRaceLost;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The one start-or-stop of every task surface: the task page, the task list and the board cards
 * (TI-01, D-01, D-02, D-03).
 *
 * A task that is the signed-in user's running one is stopped; any other task starts a timer with
 * the task id only, so the project, the client and the billable default come from the task inside
 * StartTimer, and a running timer on another task is stopped and kept (D-02). The toasts are the
 * ones of the timer bar. Nothing here writes a row: the two Actions authorize and write.
 */
final class TaskTimerToggle
{
    /**
     * The id of the task the user's running entry is logged to; null when nothing runs or the
     * running entry has no task.
     */
    public function runningTaskId(User $actor): ?string
    {
        $taskId = TimeEntry::query()
            ->where('user_id', $actor->getKey())
            ->whereNull('ended_at')
            ->value('task_id');

        return is_string($taskId) ? $taskId : null;
    }

    /**
     * Starts or stops the timer for the task and tells the user.
     *
     * @return string|null the event the other timer surfaces listen to, or null when nothing changed
     */
    public function handle(User $actor, Task $task): ?string
    {
        if ($this->runningTaskId($actor) === $task->getKey()) {
            return $this->stop($actor);
        }

        return $this->start($actor, $task);
    }

    private function start(User $actor, Task $task): ?string
    {
        try {
            $result = app(StartTimer::class)->handle($actor, ['task_id' => $task->getKey()]);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($this->firstMessage($e))->send();

            return null;
        } catch (TimerRaceLost $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return null;
        }

        $stopped = $result['stopped'];

        Notification::make()
            ->success()
            ->title(__('kokpit.time.timer.started'))
            ->body($stopped instanceof TimeEntry
                ? (string) __('kokpit.time.timer.started_previous', ['duration' => DurationFormat::hoursMinutes((int) $stopped->duration_seconds)])
                : null)
            ->send();

        return 'timer-started';
    }

    private function stop(User $actor): string
    {
        $stopped = app(StopTimer::class)->handle($actor);

        if (! $stopped instanceof TimeEntry) {
            Notification::make()->info()->title(__('kokpit.time.timer.nothing_running'))->send();

            return 'timer-stopped';
        }

        Notification::make()
            ->success()
            ->title(__('kokpit.time.timer.stopped'))
            ->body(DurationFormat::hoursMinutes((int) $stopped->duration_seconds))
            ->send();

        return 'timer-stopped';
    }

    private function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return $e->getMessage();
    }
}
