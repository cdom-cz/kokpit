<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Clears the escalation flag of a task (D-06: the assignee resolves the flag).
 *
 * Inside one transaction the task is re-read through the scoped query with a row
 * lock (a task the actor cannot see is a ModelNotFoundException), and the
 * `clearEscalation` ability is checked on that fresh row, not on the instance the
 * caller holds: the Admin may always clear, a Partner only while they are still
 * the assignee of a task of the own client, so a Partner reassigned away after
 * loading the page is refused. A task that is not escalated is a field error on
 * `task`. Exactly the two escalation columns are set to null in one save;
 * priority, status, people and the comments are untouched, and the Partner can
 * escalate again afterwards.
 */
final class ClearEscalation
{
    /**
     * @throws ValidationException a field error on `task` when the task is not escalated
     */
    public function handle(User $actor, Task $task): Task
    {
        $fresh = DB::transaction(static function () use ($actor, $task): Task {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('clearEscalation', $locked);

            if ($locked->escalated_at === null) {
                throw ValidationException::withMessages(['task' => __('kokpit.tasks.errors.not_escalated')]);
            }

            $locked->forceFill(['escalated_at' => null, 'escalated_by_id' => null])->save();

            return $locked;
        });

        $task->refresh();

        return $fresh;
    }
}
