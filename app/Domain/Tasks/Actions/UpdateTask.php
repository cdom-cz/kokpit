<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskInput;
use App\Domain\Tasks\TaskPeople;
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
 * People (D-04, D-05): a changed assignee or requester must be in the allowed set
 * of TaskPeople (the active Admin and the active Partners of the project's
 * client), recomputed on every write, otherwise the error is keyed `assignee_id`
 * or `requester_id` and nothing is written. An unchanged person is not checked
 * again, so a task keeps a person who was deactivated since.
 *
 * The description is cleaned with RichText::clean (through TaskInput::description)
 * before it is stored, whoever wrote it (D-10); text over the length limit is a
 * field error on `description`. Dates must be calendar days and the due date may not precede the start date,
 * checked against the stored value of the date the data does not name. Tags are
 * a list of names synced as task tags.
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
 *     assignee_id?: string|null,
 *     requester_id?: string|null,
 *     tags?: list<string>|null,
 * }
 */
final class UpdateTask
{
    /** The two people columns, validated against the allowed set when they change. */
    private const array PEOPLE_COLUMNS = ['assignee_id', 'requester_id'];

    public function __construct(
        private readonly TaskBoard $board,
        private readonly TaskPeople $people,
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
        $tags = TaskInput::tags($data['tags'] ?? null);

        $plain = [];

        if (array_key_exists('description', $data)) {
            $plain['description'] = TaskInput::description($data['description']);
        }

        $dates = [];

        foreach (['start_date', 'due_date'] as $column) {
            if (array_key_exists($column, $data)) {
                $dates[$column] = TaskInput::day($data[$column], $column);
            }
        }

        return DB::transaction(function () use ($task, $data, $title, $status, $priority, $tags, $plain, $dates): Task {
            $this->board->lockBoard();

            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $attributes = [...$plain, ...$dates];

            TaskInput::assertDatesInOrder(
                array_key_exists('start_date', $dates) ? $dates['start_date'] : $locked->start_date?->toDateString(),
                array_key_exists('due_date', $dates) ? $dates['due_date'] : $locked->due_date?->toDateString(),
            );

            $people = $this->changedPeople($locked, $data);

            if ($title !== null) {
                $attributes['title'] = $title;
            }

            // Priority is NOT NULL, so a null value leaves it unchanged.
            if ($priority !== null) {
                $attributes['priority'] = $priority;
            }

            // The people columns are not mass assignable; they come from the checked set only.
            $locked->fill($attributes)->forceFill($people);

            if ($status !== null && $status !== $locked->status) {
                $this->board->appendToColumn($locked, $status);
            } else {
                $locked->save();
            }

            if ($tags !== null) {
                $locked->syncTagsWithType($tags, TagType::Task->value);
            }

            return $locked->refresh();
        });
    }

    /**
     * The assignee and requester columns the data changes. Each changed person
     * must be allowed on the task's project. An archived project still counts:
     * the Admin edits its tasks.
     *
     * @param  TaskUpdateData  $data
     * @return array<string, string>
     */
    private function changedPeople(Task $task, array $data): array
    {
        $project = null;
        $changed = [];

        foreach (self::PEOPLE_COLUMNS as $column) {
            $userId = $data[$column] ?? null;

            if ($userId === null || $userId === $task->getAttribute($column)) {
                continue;
            }

            $project ??= Project::query()->withTrashed()->findOrFail($task->project_id);

            $this->people->assertAllowed($project, (string) $userId, $column);

            $changed[$column] = (string) $userId;
        }

        return $changed;
    }
}
