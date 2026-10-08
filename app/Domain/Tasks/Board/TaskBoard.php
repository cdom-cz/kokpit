<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Board;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The kanban board as a write-side service: the board lock and the next
 * column position.
 *
 * Every write of `(status, position)` happens behind one transaction-scoped
 * advisory lock, the same one for task creation and (in a later plan) for
 * moving a card. One lock for the whole board keeps the lock order fixed
 * (board lock, project row, counter row) so creates, moves and key edits
 * cannot deadlock.
 *
 * Not final on purpose: a test double that skips the lock extends it.
 */
class TaskBoard
{
    public const string LOCK_KEY = 'kokpit:task_board';

    /**
     * Takes the board lock until the surrounding transaction ends.
     *
     * @throws LogicException outside a database transaction
     */
    public function lockBoard(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TaskBoard::lockBoard() must run inside a database transaction.');
        }

        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK_KEY]);
    }

    /**
     * The position a task takes at the end of the column of the given status.
     * A Done task holds no slot and carries 0. Otherwise it is the highest
     * position among the active, non-done tasks of that status plus one, or 0
     * for an empty column.
     *
     * The order is global across clients, so the read is a system run: a task
     * created by a Partner must not collide with a task the Partner cannot see.
     * Call it under the board lock.
     */
    public function nextPosition(ProjectStatus $status): int
    {
        if ($status === ProjectStatus::Done) {
            return 0;
        }

        $highest = app(PartnerContext::class)->runAsSystem(
            static fn (): mixed => Task::query()->where('status', $status->value)->max('position'),
        );

        return $highest === null ? 0 : (int) $highest + 1;
    }

    /**
     * Writes a status change: the task takes the end of the column of the new
     * status, and `completed_at` is set (now) when the status is Done and cleared
     * otherwise. Status, `completed_at` and `position` go out in one model save,
     * together with any attribute the caller filled on the model beforehand, so
     * the `updated` event reaches the activity log once.
     *
     * Call it with the board lock held and the task row locked, in the caller's
     * transaction (D-01, D-02).
     *
     * @throws LogicException outside a database transaction
     */
    public function appendToColumn(Task $task, ProjectStatus $status): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TaskBoard::appendToColumn() must run inside a database transaction.');
        }

        $task->forceFill([
            'status' => $status,
            'completed_at' => $status === ProjectStatus::Done ? now() : null,
            'position' => $this->nextPosition($status),
        ])->save();
    }
}
