<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only Partner write path of a task's description (gap G-05-5, D-16, D-10).
 *
 * It writes the description and nothing else: the Action takes no data array, so
 * no other attribute can ride along (T-05-47). The actor needs the
 * `editDescription` ability on the task; for a Partner that means a task of an own
 * client-visible project, not archived, in one of the statuses
 * TaskPolicy::DESCRIPTION_EDITABLE_STATUSES (D-16). The Admin is admitted by
 * KokpitPolicy::before() in every status, but never on an archived task (read-only
 * for everybody).
 *
 * The ability is checked twice. Before the transaction it guards the instance the
 * caller holds. Inside the transaction the task is re-read through the scoped
 * query with a row lock (a foreign, hidden or archived task is a not-found error),
 * and the same ability is evaluated again on the locked row, so a status change
 * made by the Admin between the Partner opening the editor and saving is a field
 * error on `description` and nothing is written. A move between the two editable
 * statuses does not block the save.
 *
 * Then the fingerprint of the description the editor was opened with is compared
 * with the locked row: a description changed in the meantime is a field error
 * (never a silent overwrite, T-05-51). The text is cleaned by the same sanitiser as
 * every other write (TaskInput::description, D-10; a text over the byte limit is a
 * field error), and a cleaned value equal to the stored one writes and logs
 * nothing, so saving the same text twice records one change.
 *
 * History: the change is one manual activity row (event `description_changed`,
 * the actor as causer) with no properties. The text of the description, old or
 * new, is never logged. Lock order: only the row lock of the task is taken; no
 * status or position is written, so the board lock does not apply and this Action
 * cannot deadlock with UpdateTask (board lock, then row).
 */
final class UpdateTaskDescription
{
    /**
     * The fingerprint of a stored description, the one definition for the page and the Action.
     */
    public static function fingerprint(?string $storedDescription): string
    {
        return hash('sha256', $storedDescription ?? '');
    }

    /**
     * @param  string  $basedOn  the fingerprint of the description the editor was opened with
     *
     * @throws ValidationException a field error on `description` for a text over the limit, a task whose status left the editable ones, or a description changed since the editor was opened
     */
    public function handle(User $actor, Task $task, ?string $description, string $basedOn): Task
    {
        Gate::forUser($actor)->authorize('editDescription', $task);

        $clean = TaskInput::description($description);

        DB::transaction(function () use ($actor, $task, $clean, $basedOn): void {
            // The scoped query: a task that is not visible to the actor, or archived, is a ModelNotFoundException.
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            // D-16 again, on the locked row: the status may have changed since the caller read it.
            if (Gate::forUser($actor)->denies('editDescription', $locked)) {
                throw ValidationException::withMessages(['description' => __('kokpit.tasks.errors.description_not_editable')]);
            }

            if (! hash_equals(self::fingerprint($locked->description), $basedOn)) {
                throw ValidationException::withMessages(['description' => __('kokpit.tasks.errors.description_stale')]);
            }

            if ($clean === $locked->description) {
                return;
            }

            $locked->forceFill(['description' => $clean])->save();

            activity()
                ->useLog($locked->getMorphClass())
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('description_changed')
                ->log('description_changed');
        });

        // The caller's instance shows the stored value, whatever it showed before.
        $task->refresh();

        return $task;
    }
}
