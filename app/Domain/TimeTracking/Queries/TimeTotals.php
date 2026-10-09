<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sums of tracked time in whole seconds, for the screens that show how much time something took
 * (TI-08, PR-05, UI-SPEC Surfaces C and H). Admin-only like the entries: a Partner reads zero rows.
 *
 * A finished entry counts with its stored duration, a running one with the seconds from its start
 * to `$now` (never negative), so a running billable entry already counts as unbilled time (research
 * A3: no unbilled time slips through unnoticed). The instant is bound from the PHP clock, never SQL
 * `now()`, as in TimeEntry::scopeWithElapsedSeconds. No money is formed here: the amount is
 * written at billing time.
 *
 * Every figure is one of four sums over the same entries, and they add up: worked = billed +
 * unbilled + non_billable, because a billed entry is always billable (a database CHECK).
 */
final class TimeTotals
{
    /**
     * The seconds of one entry row of `time_entries`, with one `?` for the instant.
     */
    private const string ELAPSED = 'COALESCE(time_entries.duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - time_entries.started_at))::int))';

    /**
     * The same, for the alias `entry` used inside a correlated sub-select.
     */
    private const string ELAPSED_OF_ENTRY = 'COALESCE(entry.duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - entry.started_at))::int))';

    /**
     * The time logged to exactly this task; a parent task does not add the time of its subtasks.
     * One aggregate query with FILTER clauses.
     *
     * - worked: every entry of the task
     * - billed: billable entries that are billed
     * - unbilled: billable entries that are not billed yet, a running one included
     * - non_billable: entries that are not billable
     *
     * @return array{worked: int, billed: int, unbilled: int, non_billable: int}
     */
    public function forTask(Task $task, CarbonInterface $now): array
    {
        return $this->aggregate(
            TimeEntry::query()->where('time_entries.task_id', $task->getKey()),
            $now,
        );
    }

    /**
     * The time of a whole project: every entry of the project, with or without a task, and the
     * estimate of the project (whole seconds, null while none is set; zero is a value). One
     * aggregate query plus the billing row.
     *
     * @return array{worked: int, billed: int, unbilled: int, non_billable: int, estimate: int|null}
     */
    public function forProject(Project $project, CarbonInterface $now): array
    {
        $estimate = ProjectBilling::query()->where('project_id', $project->getKey())->value('estimate_seconds');

        return [
            ...$this->aggregate(TimeEntry::query()->where('time_entries.project_id', $project->getKey()), $now),
            'estimate' => $estimate === null ? null : (int) $estimate,
        ];
    }

    /**
     * The time logged to the project without a task: the "Bez úkolu" line of the project overview.
     *
     * @return array{worked: int, billed: int, unbilled: int, non_billable: int}
     */
    public function withoutTask(Project $project, CarbonInterface $now): array
    {
        return $this->aggregate(
            TimeEntry::query()
                ->where('time_entries.project_id', $project->getKey())
                ->whereNull('time_entries.task_id'),
            $now,
        );
    }

    /**
     * The sum of the estimates that tasks of the project hold in their own billing row, archived
     * tasks included, or null while no task holds one. A subtask that only inherits its parent's
     * estimate adds nothing, so the sum does not count one estimate twice.
     */
    public function ownTaskEstimates(Project $project): ?int
    {
        $sum = TaskBilling::query()
            ->whereIn('task_id', Task::query()->withTrashed()->where('project_id', $project->getKey())->select('tasks.id'))
            ->toBase()
            ->selectRaw('SUM(estimate_seconds) AS total')
            ->value('total');

        return $sum === null ? null : (int) $sum;
    }

    /**
     * Adds the time columns to a query over tasks, as correlated sub-selects: `worked_seconds`,
     * `billed_seconds`, `unbilled_seconds`, `non_billable_seconds` and `estimate_seconds`, so the
     * number of queries does not depend on the number of tasks.
     *
     * The estimate is the task's own `task_billing.estimate_seconds`, or the parent task's when the
     * task has none of its own. The project estimate is never inherited here: it would print on every
     * task and make "Zbývá" meaningless (research A2). Zero is a value, so it is kept by COALESCE.
     *
     * @param  Builder<Task>  $tasks
     * @return Builder<Task>
     */
    public function taskColumns(Builder $tasks, CarbonInterface $now): Builder
    {
        $instant = $now->utc()->format('Y-m-d H:i:sP');
        if ($tasks->getQuery()->columns === null) {
            $tasks->addSelect($tasks->getModel()->qualifyColumn('*'));
        }

        return $tasks
            ->selectRaw(self::taskSum('').' AS worked_seconds', [$instant])
            ->selectRaw(self::taskSum(" AND entry.billable AND entry.billing_state = 'billed'").' AS billed_seconds', [$instant])
            ->selectRaw(self::taskSum(" AND entry.billable AND entry.billing_state = 'unbilled'").' AS unbilled_seconds', [$instant])
            ->selectRaw(self::taskSum(' AND NOT entry.billable').' AS non_billable_seconds', [$instant])
            ->selectRaw(
                'COALESCE('
                .'(SELECT own.estimate_seconds FROM task_billing AS own WHERE own.task_id = tasks.id), '
                .'(SELECT inherited.estimate_seconds FROM task_billing AS inherited WHERE inherited.task_id = tasks.parent_id)'
                .') AS estimate_seconds',
            );
    }

    /**
     * The four sums over the entries of the given query, in one statement with FILTER clauses.
     *
     * @param  Builder<TimeEntry>  $entries
     * @return array{worked: int, billed: int, unbilled: int, non_billable: int}
     */
    private function aggregate(Builder $entries, CarbonInterface $now): array
    {
        $instant = $now->utc()->format('Y-m-d H:i:sP');

        $row = $entries
            ->selectRaw(
                'COALESCE(SUM('.self::ELAPSED.'), 0) AS worked,'
                .'COALESCE(SUM('.self::ELAPSED.") FILTER (WHERE time_entries.billable AND time_entries.billing_state = 'billed'), 0) AS billed,"
                .'COALESCE(SUM('.self::ELAPSED.") FILTER (WHERE time_entries.billable AND time_entries.billing_state = 'unbilled'), 0) AS unbilled,"
                .'COALESCE(SUM('.self::ELAPSED.') FILTER (WHERE NOT time_entries.billable), 0) AS non_billable',
                [$instant, $instant, $instant, $instant],
            )
            ->toBase()
            ->first();

        return [
            'worked' => (int) ($row->worked ?? 0),
            'billed' => (int) ($row->billed ?? 0),
            'unbilled' => (int) ($row->unbilled ?? 0),
            'non_billable' => (int) ($row->non_billable ?? 0),
        ];
    }

    /**
     * One correlated sub-select over the entries of the outer task, narrowed by `$where`.
     *
     * @param  literal-string  $where  extra conditions, each starting with ` AND `
     * @return literal-string
     */
    private static function taskSum(string $where): string
    {
        return '(SELECT COALESCE(SUM('.self::ELAPSED_OF_ENTRY.'), 0) FROM time_entries AS entry WHERE entry.task_id = tasks.id'.$where.')';
    }
}
