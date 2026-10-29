<?php

declare(strict_types=1);

namespace App\Domain\Signal\Queries;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Signal\Support\SignalRules;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Which Kokpit tasks a planner task may link to, and how the linked text reads.
 *
 * D-03: only unfinished tasks are offered. Unfinished means not Done (the only terminal state), not
 * archived, in a project that is not archived and whose client is not archived. A task hidden from the
 * kanban board (`hidden_on_board`) is deliberately offered too: the flag is a board preference and says
 * nothing about whether the work is open.
 *
 * D-01: the text of a linked planner task is a snapshot taken when the task is picked. `label()` is
 * called once, by the component, at pick time; the save never rebuilds the text, so a later rename,
 * completion or archive of the task leaves the stored text unchanged. `find()` is the authority that
 * decides whether a task may still be linked, at pick time and again inside the add transaction.
 */
final class LinkableTasks
{
    public const int LIMIT = 10;

    public const int MIN_TERM = 2;

    /**
     * Up to LIMIT linkable tasks whose code, name, project key or project name contains the term, in
     * project-key then number order. An empty list below MIN_TERM characters.
     *
     * @return list<array{id: string, reference: string, title: string, project: string}>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_TERM) {
            return [];
        }

        // The term is a bound value; a typed `%`, `_` or `\` is a plain character.
        $like = '%'.addcslashes($term, '\\%_').'%';

        $tasks = self::linkable()
            ->where(static function (Builder $match) use ($like): void {
                $match->where('tasks.reference', 'ilike', $like)
                    ->orWhere('tasks.title', 'ilike', $like)
                    ->orWhereHas('project', static function (Builder $project) use ($like): void {
                        $project->where(static function (Builder $inner) use ($like): void {
                            $inner->where('projects.key', 'ilike', $like)
                                ->orWhere('projects.name', 'ilike', $like);
                        });
                    });
            })
            ->with('project:id,key,name')
            ->orderBy(Project::query()->select('projects.key')->whereColumn('projects.id', 'tasks.project_id'))
            ->orderBy('tasks.number')
            ->orderBy('tasks.id')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($tasks as $task) {
            $rows[] = [
                'id' => (string) $task->getKey(),
                'reference' => $task->reference,
                'title' => $task->title,
                'project' => (string) $task->project?->name,
            ];
        }

        return $rows;
    }

    /**
     * The task when it is linkable right now, otherwise null (a malformed id, an unknown id, a Done or
     * archived task, a task of an archived project or client).
     */
    public function find(string $id): ?Task
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return self::linkable()->with('project')->whereKey($id)->first();
    }

    /**
     * The snapshot text `{project name}: {task name} [{task code}]`, at most SignalRules::MAX_TITLE_LENGTH
     * characters. A too long text shortens the task name (with a trailing ellipsis) so that the
     * `[task code]` suffix survives; a prefix that cannot fit even so is cut as the last resort.
     */
    public static function label(Task $task): string
    {
        $max = SignalRules::MAX_TITLE_LENGTH;
        $prefix = ((string) $task->project?->name).': ';
        $suffix = ' ['.$task->reference.']';
        $title = $task->title;

        if (mb_strlen($prefix.$title.$suffix) > $max) {
            $room = $max - mb_strlen($prefix) - mb_strlen($suffix) - 1;
            $title = $room > 0 ? rtrim(mb_substr($title, 0, $room)).'…' : '';
        }

        return mb_substr($prefix.$title.$suffix, 0, $max);
    }

    /**
     * @return Builder<Task>
     */
    private static function linkable(): Builder
    {
        return Task::query()
            ->where('tasks.status', '!=', ProjectStatus::Done->value)
            ->whereIn('tasks.project_id', Project::query()->selectable()->select('projects.id'));
    }
}
