<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Board;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The kanban board: the board lock, the next column position, the columns the
 * board page renders and the card move.
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
     * A task that is already Done keeps the completion time it has: appending it
     * again to the Done column (a restore from the archive) is no new completion.
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

        $keepsCompletion = $status === ProjectStatus::Done
            && $task->status === ProjectStatus::Done
            && $task->completed_at !== null;

        $task->forceFill([
            'status' => $status,
            'completed_at' => $status === ProjectStatus::Done ? ($keepsCompletion ? $task->completed_at : now()) : null,
            'position' => $this->nextPosition($status),
        ])->save();
    }

    /**
     * The tasks the board shows: active tasks of selectable projects (not
     * archived, client not archived), narrowed by the filters. One method for
     * the rendered columns and for the neighbour recompute of a move, so a drop
     * is computed over exactly the cards the Admin sees.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function applyFilters(Builder $query, BoardFilters $filters): Builder
    {
        return $query->whereIn('tasks.project_id', Project::query()->selectable()->select('projects.id'));
    }

    /**
     * The columns of the board, one per status in the status order. A column
     * holds its cards ordered by position; the Done column holds the most recent
     * `kokpit.board.done_limit` tasks by completion time. `shown` is the number
     * of cards rendered and `total` the number of matching tasks.
     *
     * Plain arrays only: the page keeps no Eloquent model in its state.
     *
     * @return array<string, array{label: string, cards: list<array<string, mixed>>, shown: int, total: int}>
     */
    public function columns(BoardFilters $filters): array
    {
        $columns = [];

        foreach (ProjectStatus::cases() as $status) {
            $query = $this->applyFilters(Task::query(), $filters)->where('tasks.status', $status->value);

            if ($status === ProjectStatus::Done) {
                $total = (clone $query)->count();
                $tasks = $query
                    ->orderByDesc('tasks.completed_at')
                    ->orderByDesc('tasks.id')
                    ->limit(max(0, (int) config('kokpit.board.done_limit', 20)))
                    ->get();
            } else {
                $tasks = $query->orderBy('tasks.position')->orderBy('tasks.id')->get();
                $total = $tasks->count();
            }

            $columns[$status->value] = [
                'label' => $status->getLabel(),
                'cards' => array_values($tasks->map(fn (Task $task): array => $this->card($task))->all()),
                'shown' => $tasks->count(),
                'total' => $total,
            ];
        }

        return $columns;
    }

    /**
     * Moves a card to the given status at the given drop index.
     *
     * The caller holds the board lock and has re-read the task under it. The
     * index is the drop position among the cards of the destination column
     * without the moved card. A change of status goes through
     * appendToColumn, so one model save writes status, completion time and
     * position and the activity log sees it once; the order of the column is
     * then written through the query builder, so a reorder alone fires no model
     * event. A drop inside the Done column changes nothing: that column is
     * ordered by completion time.
     *
     * @throws LogicException outside a database transaction
     */
    public function move(Task $task, int $index, ProjectStatus $status, BoardFilters $filters): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TaskBoard::move() must run inside a database transaction.');
        }

        if ($status === ProjectStatus::Done) {
            if ($task->status !== ProjectStatus::Done) {
                $this->appendToColumn($task, $status);
            }

            return;
        }

        $changesStatus = $task->status !== $status;

        $column = fn (): Builder => Task::query()->where('tasks.status', $status->value)->whereKeyNot($task->getKey());

        /** @var array<string, int> $positions */
        $positions = app(PartnerContext::class)->runAsSystem(
            static fn (): array => $column()->orderBy('tasks.position')->orderBy('tasks.id')->pluck('position', 'id')->map(static fn (mixed $position): int => (int) $position)->all(),
        );
        $full = array_map(strval(...), array_keys($positions));

        $visible = $this->applyFilters($column(), $filters)->orderBy('tasks.position')->orderBy('tasks.id')->pluck('id')->all();
        $visible = array_values($visible);

        $insertAt = $this->insertionIndex($full, $visible, $index);
        array_splice($full, $insertAt, 0, [(string) $task->getKey()]);

        // The moved card has no valid slot yet when it changes the column.
        $positions[(string) $task->getKey()] = $changesStatus ? -1 : $task->position;

        if ($changesStatus) {
            $this->appendToColumn($task, $status);
        }

        // Only the cards from the first one whose stored position differs from its new index are rewritten.
        $from = count($full);

        foreach ($full as $i => $id) {
            if (($positions[$id] ?? -1) !== $i) {
                $from = $i;

                break;
            }
        }

        $rewrite = array_slice($full, $from);

        if ($rewrite !== []) {
            Task::setNewOrder($rewrite, $from);
        }
    }

    /**
     * Where in the full column a card dropped at the given visible index goes:
     * directly before the visible card now at that index, or at the end when
     * there is none. Hidden cards keep their place relative to each other.
     *
     * @param  list<string>  $full  the ids of the whole column without the moved card
     * @param  list<mixed>  $visible  the ids of the cards the board shows, without the moved card
     */
    private function insertionIndex(array $full, array $visible, int $index): int
    {
        $before = $visible[max(0, $index)] ?? null;

        if ($before === null) {
            return count($full);
        }

        $found = array_search((string) $before, $full, true);

        return $found === false ? count($full) : $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Task $task): array
    {
        return [
            'id' => (string) $task->getKey(),
            'reference' => $task->reference,
            'title' => $task->title,
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->getLabel(),
            'priority_color' => $task->priority->getColor(),
        ];
    }
}
