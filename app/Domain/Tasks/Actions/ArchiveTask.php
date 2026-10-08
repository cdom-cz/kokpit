<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives a task: a soft delete, never a removal (TA-02, research A9).
 *
 * A task is never deleted for good, so its number stays a valid reference
 * forever. A parent with active (not archived) subtasks cannot be archived: the
 * field error is keyed `task` and nothing changes. Archiving a subtask is
 * always allowed. The check runs under the board lock with the row locked, the
 * lock a subtask creation holds too, so a subtask cannot appear between the
 * check and the archive. The tags stay attached. Archiving an archived task is
 * a no-op.
 */
final class ArchiveTask
{
    public function __construct(private readonly TaskBoard $board) {}

    public function handle(User $actor, Task $task): void
    {
        Gate::forUser($actor)->authorize('delete', $task);

        DB::transaction(function () use ($task): void {
            $this->board->lockBoard();

            // Another request may have archived the row since this instance was loaded.
            $locked = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                return;
            }

            if ($locked->subtasks()->exists()) {
                throw ValidationException::withMessages(['task' => __('kokpit.tasks.errors.has_active_subtasks')]);
            }

            $locked->delete();
        });
    }
}
