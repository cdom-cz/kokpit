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
 * Restores an archived task (TA-02, research A9, Pitfall 2).
 *
 * The task returns to the column of its stored status, appended at the end under
 * the board lock: its old position may have been taken while it was archived,
 * and the active positions of a column are unique. A Done task keeps its
 * `completed_at`. A subtask whose parent is archived cannot be restored, so an
 * active subtask never hangs under an archived parent (field error `task`).
 * Restoring an active task is a no-op.
 */
final class RestoreTask
{
    public function __construct(private readonly TaskBoard $board) {}

    public function handle(User $actor, Task $task): Task
    {
        Gate::forUser($actor)->authorize('restore', $task);

        return DB::transaction(function () use ($task): Task {
            $this->board->lockBoard();

            // Another request may have restored the row since this instance was loaded.
            $locked = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                return $locked;
            }

            if ($locked->parent_id !== null) {
                $parent = Task::query()->withTrashed()->whereKey($locked->parent_id)->lockForUpdate()->first();

                if ($parent === null || $parent->trashed()) {
                    throw ValidationException::withMessages(['task' => __('kokpit.tasks.errors.parent_archived')]);
                }
            }

            // Appended while still archived: the archived row holds no slot, so the
            // new position is the end of the column without the row's own old one.
            $this->board->appendToColumn($locked, $locked->status);
            $locked->restore();

            return $locked->refresh();
        });
    }
}
