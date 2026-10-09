<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;

/**
 * Sums of tracked time in whole seconds, for the screens that show how much time something took
 * (TI-08, UI-SPEC Surface C). Admin-only like the entries: a Partner reads zero rows.
 *
 * A finished entry counts with its stored duration, a running one with the seconds from its start
 * to `$now` (never negative), so a running billable entry already counts as unbilled time (research
 * A3: no unbilled time slips through unnoticed). The instant is bound from the PHP clock, never SQL
 * `now()`, as in TimeEntry::scopeWithElapsedSeconds. No money is formed here: the amount is
 * written at billing time.
 */
final class TimeTotals
{
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
        $elapsed = 'COALESCE(time_entries.duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - time_entries.started_at))::int))';
        $instant = $now->utc()->format('Y-m-d H:i:sP');

        $row = TimeEntry::query()
            ->where('time_entries.task_id', $task->getKey())
            ->selectRaw(
                "COALESCE(SUM({$elapsed}), 0) AS worked,"
                ."COALESCE(SUM({$elapsed}) FILTER (WHERE time_entries.billable AND time_entries.billing_state = 'billed'), 0) AS billed,"
                ."COALESCE(SUM({$elapsed}) FILTER (WHERE time_entries.billable AND time_entries.billing_state = 'unbilled'), 0) AS unbilled,"
                ."COALESCE(SUM({$elapsed}) FILTER (WHERE NOT time_entries.billable), 0) AS non_billable",
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
}
