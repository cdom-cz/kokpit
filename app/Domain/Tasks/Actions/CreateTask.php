<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskInput;
use App\Domain\Tasks\TaskPeople;
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
 * People (D-04, D-05): the Admin gets requester = assignee = self unless ids
 * are given; every id must be in the allowed set of TaskPeople (the active
 * Admin and the active Partners of the project's client), otherwise the error is
 * keyed `assignee_id` or `requester_id`. A Partner always becomes the requester
 * with the Admin as assignee, and their status, priority and people inputs are
 * ignored. With no active Admin a Partner creation is a DomainException.
 *
 * The description is cleaned with RichText::clean (through TaskInput::description)
 * before it is stored, whoever wrote it (D-10); text over the length limit is a
 * field error on `description`. Dates must be calendar days and the due date may not precede the start date
 * (field error `due_date`). Tags are a list of names stored as task tags.
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
 *     tags?: list<string>|null,
 * }
 */
final class CreateTask
{
    public function __construct(
        private readonly TaskBoard $board,
        private readonly DocumentNumbering $numbering,
        private readonly TaskPeople $people,
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

        // A Partner only supplies the content: status, priority and people are ignored (D-04),
        // before they are validated, so a forged value is no error but simply has no effect.
        $isPartner = $this->people->isPartner($actor);

        if ($isPartner) {
            unset($data['status'], $data['priority'], $data['assignee_id'], $data['requester_id']);
        }

        $status = TaskInput::status($data['status'] ?? null) ?? ProjectStatus::Planned;
        $priority = TaskInput::priority($data['priority'] ?? null);
        $startDate = TaskInput::day($data['start_date'] ?? null, 'start_date');
        $dueDate = TaskInput::day($data['due_date'] ?? null, 'due_date');
        $tags = TaskInput::tags($data['tags'] ?? null);
        $description = TaskInput::description($data['description'] ?? null);

        TaskInput::assertDatesInOrder($startDate, $dueDate);

        return DB::transaction(function () use ($actor, $project, $data, $title, $status, $priority, $isPartner, $description, $startDate, $dueDate, $tags): Task {
            $this->board->lockBoard();

            // The scoped lookup: a Partner only finds a visible project of the own client.
            $locked = Project::query()->selectable()->whereKey($project->getKey())->sharedLock()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['project_id' => __('kokpit.tasks.errors.project_unavailable')]);
            }

            $people = $this->peopleFor($actor, $locked, $data, $isPartner);

            $reference = $this->numbering->nextTaskNumber($locked->id, $locked->key);
            $number = (int) substr($reference, (int) strrpos($reference, '-') + 1);

            $attributes = [
                'title' => $title,
                'description' => $description,
                'start_date' => $startDate,
                'due_date' => $dueDate,
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
                'requester_id' => $people['requester_id'],
                'assignee_id' => $people['assignee_id'],
            ])->save();

            if ($tags !== null) {
                $task->syncTagsWithType($tags, TagType::Task->value);
            }

            // Load the database defaults, so the returned model is complete.
            return $task->refresh();
        });
    }

    /**
     * The requester and assignee of the new task (D-04, D-05). A Partner always
     * gets the defaults. The Admin gets the given ids or the defaults, and both
     * must be in the allowed set of the project.
     *
     * @param  TaskData  $data
     * @return array{requester_id: string, assignee_id: string}
     */
    private function peopleFor(User $actor, Project $project, array $data, bool $isPartner): array
    {
        $defaults = $this->people->defaultsFor($actor);

        if ($isPartner) {
            return $defaults;
        }

        $people = [
            'requester_id' => $data['requester_id'] ?? $defaults['requester_id'],
            'assignee_id' => $data['assignee_id'] ?? $defaults['assignee_id'],
        ];

        foreach ($people as $field => $userId) {
            $this->people->assertAllowed($project, $userId, $field);
        }

        return $people;
    }
}
