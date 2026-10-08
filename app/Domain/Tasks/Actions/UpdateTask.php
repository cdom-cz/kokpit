<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Updates a task as the Admin (TA-01).
 *
 * A key that is absent from the data leaves the stored value unchanged. Status
 * switches freely between all six values with no workflow (D-01). Every write
 * runs in one transaction under the board lock with the task row locked, so a
 * status change cannot interleave with a creation or a move: a changed status
 * goes through TaskBoard::appendToColumn, which appends the task to the end of
 * the new column and sets or clears `completed_at` (D-02, Pitfall 1b). Every
 * other attribute goes out in the same save, so the activity log gets one row.
 *
 * Errors are ValidationExceptions keyed by the data key; the Admin form maps
 * them to its state paths.
 *
 * @phpstan-type TaskUpdateData array{
 *     title?: string,
 *     description?: string|null,
 *     status?: string|null,
 *     priority?: string|null,
 *     start_date?: string|null,
 *     due_date?: string|null,
 * }
 */
final class UpdateTask
{
    /** Task columns copied from the data as given when the data names them. */
    private const array PLAIN_COLUMNS = ['description', 'start_date', 'due_date'];

    public function __construct(
        private readonly TaskBoard $board,
    ) {}

    /**
     * @param  TaskUpdateData  $data
     */
    public function handle(User $actor, Task $task, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        $title = array_key_exists('title', $data) ? trim((string) $data['title']) : null;

        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('kokpit.tasks.errors.title_required')]);
        }

        $status = TaskInput::status($data['status'] ?? null);
        $priority = TaskInput::priority($data['priority'] ?? null);

        return DB::transaction(function () use ($task, $data, $title, $status, $priority): Task {
            $this->board->lockBoard();

            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $attributes = [];

            if ($title !== null) {
                $attributes['title'] = $title;
            }

            foreach (self::PLAIN_COLUMNS as $column) {
                if (array_key_exists($column, $data)) {
                    $attributes[$column] = $data[$column];
                }
            }

            // Priority is NOT NULL, so a null value leaves it unchanged.
            if ($priority !== null) {
                $attributes['priority'] = $priority;
            }

            $locked->fill($attributes);

            if ($status !== null && $status !== $locked->status) {
                $this->board->appendToColumn($locked, $status);
            } else {
                $locked->save();
            }

            return $locked->refresh();
        });
    }
}
