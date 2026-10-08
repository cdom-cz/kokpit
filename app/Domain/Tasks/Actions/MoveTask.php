<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Moves a card on the kanban board: the guarded, locked mover (KB-02).
 *
 * The order of the guards is part of the contract. The caller whitelists the
 * target status (422) before it gets here. Then:
 * 1. the task is looked up through the scoped query: another client's task and
 *    an archived task are not found (404), an id that is no UUID the same;
 * 2. the Gate authorises `update` for the actor (403), so a Partner never moves
 *    a card whatever else is true;
 * 3. inside one transaction the board lock is taken and the task is read again
 *    under it, so a card archived or moved by a parallel request is seen as it
 *    is now (404 when it is gone);
 * 4. TaskBoard::move writes the status through the model and the order through
 *    setNewOrder.
 *
 * The database CHECK on `status` and the deferred position exclusion are the
 * last line.
 */
final class MoveTask
{
    public function __construct(private readonly TaskBoard $board) {}

    public function handle(User $actor, string $taskId, int $index, ProjectStatus $status, BoardFilters $filters): Task
    {
        // A malformed id would reach the uuid column as a database error.
        if (! Str::isUuid($taskId)) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$taskId]);
        }

        $task = Task::query()->findOrFail($taskId);

        Gate::forUser($actor)->authorize('update', $task);

        return DB::transaction(function () use ($task, $index, $status, $filters): Task {
            $this->board->lockBoard();

            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $this->board->move($locked, $index, $status, $filters);

            return $locked;
        });
    }
}
