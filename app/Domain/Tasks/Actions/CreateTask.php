<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a task in a project and hands it the next KEY-N reference.
 *
 * Allocation and insert share one transaction, which is also the caller's
 * transaction when there is one: a rolled-back caller gives the number back.
 * The locks are taken in one fixed order so creates, moves and key edits cannot
 * deadlock: the board advisory lock, then the project row FOR SHARE (a key
 * change needs the row lock and so waits for this transaction), then the counter
 * row inside DocumentNumbering.
 *
 * A project that does not exist, is not visible to the actor, is archived or
 * belongs to an archived client is the single neutral field error `project_id`,
 * which consumes no number and is no existence oracle.
 *
 * Errors are ValidationExceptions keyed by the data key.
 *
 * @phpstan-type TaskData array{
 *     title: string,
 *     description?: string|null,
 *     status?: string|null,
 *     priority?: string|null,
 *     start_date?: string|null,
 *     due_date?: string|null,
 *     assignee_id?: string|null,
 *     requester_id?: string|null,
 * }
 */
final class CreateTask
{
    public function __construct(
        private readonly TaskBoard $board,
        private readonly DocumentNumbering $numbering,
    ) {}

    /**
     * @param  TaskData  $data
     */
    public function handle(User $actor, Project $project, array $data): Task
    {
        $title = trim($data['title']);

        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('kokpit.tasks.errors.title_required')]);
        }

        $status = self::statusFrom($data['status'] ?? null);
        $priority = self::priorityFrom($data['priority'] ?? null);

        return DB::transaction(function () use ($actor, $project, $data, $title, $status, $priority): Task {
            $this->board->lockBoard();

            // The scoped lookup: a Partner only finds a visible project of the own client.
            $locked = Project::query()->selectable()->whereKey($project->getKey())->sharedLock()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['project_id' => __('kokpit.tasks.errors.project_unavailable')]);
            }

            $reference = $this->numbering->nextTaskNumber($locked->id, $locked->key);
            $number = (int) substr($reference, (int) strrpos($reference, '-') + 1);

            $attributes = [
                'title' => $title,
                'description' => $data['description'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ];

            // Status and priority are NOT NULL with database defaults: omit them when not given.
            if (($data['status'] ?? null) !== null) {
                $attributes['status'] = $status;
            }

            if ($priority !== null) {
                $attributes['priority'] = $priority;
            }

            $task = new Task($attributes);
            $task->forceFill([
                'project_id' => $locked->id,
                'number' => $number,
                'reference' => $reference,
                'depth' => 0,
                'parent_id' => null,
                'position' => $this->board->nextPosition($status),
                'completed_at' => $status === ProjectStatus::Done ? now() : null,
                'requester_id' => $actor->id,
                'assignee_id' => $actor->id,
            ])->save();

            // Load the database defaults, so the returned model is complete.
            return $task->refresh();
        });
    }

    private static function statusFrom(?string $value): ProjectStatus
    {
        if ($value === null) {
            return ProjectStatus::Planned;
        }

        return ProjectStatus::tryFrom($value)
            ?? throw ValidationException::withMessages(['status' => __('kokpit.tasks.errors.status_invalid')]);
    }

    private static function priorityFrom(?string $value): ?ProjectPriority
    {
        if ($value === null) {
            return null;
        }

        return ProjectPriority::tryFrom($value)
            ?? throw ValidationException::withMessages(['priority' => __('kokpit.tasks.errors.priority_invalid')]);
    }
}
