<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The numbers behind the timesheet "Výkaz" (TI-06; D-04, D-05): the Prague day and week ranges,
 * the totals of a range and the week grid.
 *
 * A day is a Prague day. It is filtered with UTC range predicates computed from the Prague
 * boundaries, so the (user_id, started_at) index is used and the 23 and 25 hour days of the
 * daylight saving change are right (research Pattern 9, Pitfall 7). An entry belongs to the day
 * it started: a stretch from 23:30 to 00:30 is counted wholly on the first day (research A5).
 *
 * Every sum is formed from exact seconds. A running entry counts with the seconds from its start
 * to `$now`, bound from the PHP clock and never SQL `now()`, so a frozen test clock works. The
 * queries are scoped to one user, and the number of queries does not depend on the number of
 * entries. No money is formed here.
 */
final class TimesheetQuery
{
    private const string ZONE = 'Europe/Prague';

    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * The Prague date `Y-m-d` of an instant.
     */
    public function today(CarbonInterface $now): string
    {
        return CarbonImmutable::instance($now)->setTimezone(self::ZONE)->format(self::DAY_FORMAT);
    }

    /**
     * The view from the URL: `week`, or the day view for anything else.
     */
    public function normalizeView(mixed $view): string
    {
        return $view === 'week' ? 'week' : 'day';
    }

    /**
     * The date from the URL: a real calendar date written `Y-m-d`, or today's Prague date for
     * anything else (an array, an impossible date such as 2026-13-45, or a date with trailing text).
     */
    public function normalizeDate(mixed $date, CarbonInterface $now): string
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->today($now);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::DAY_FORMAT, $date, self::ZONE);
        } catch (Throwable) {
            return $this->today($now);
        }

        return $parsed instanceof CarbonImmutable && $parsed->format(self::DAY_FORMAT) === $date
            ? $date
            : $this->today($now);
    }

    /**
     * The Prague day as the UTC range `[from, to)`: the Prague start of the day to the next
     * Prague start of day, so a DST day is 23 or 25 hours long.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function dayRange(string $date): array
    {
        $start = CarbonImmutable::parse($date, self::ZONE)->startOfDay();

        return [$start->utc(), $start->addDays(1)->startOfDay()->utc()];
    }

    /**
     * The ISO week (Monday to Sunday) of the Prague date as the UTC range `[from, to)`.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function weekRange(string $date): array
    {
        $monday = $this->monday($date);

        return [$monday->utc(), $monday->addDays(7)->startOfDay()->utc()];
    }

    /**
     * The seven Prague dates `Y-m-d` of the ISO week of `$date`, Monday first.
     *
     * @return list<string>
     */
    public function weekDays(string $date): array
    {
        $monday = $this->monday($date);

        return array_map(
            static fn (int $offset): string => $monday->addDays($offset)->format(self::DAY_FORMAT),
            range(0, 6),
        );
    }

    /**
     * The total, billable and non-billable seconds of the entries of one user that started in
     * `[$from, $to)`. A running entry counts at its elapsed time. One aggregate query.
     *
     * @return array{total: int, billable: int, non_billable: int}
     */
    public function summary(User $user, CarbonInterface $from, CarbonInterface $to, CarbonInterface $now): array
    {
        $row = DB::query()
            ->fromSub($this->entries($user, $from, $to, $now), 'e')
            ->selectRaw(
                'COALESCE(SUM(e.elapsed_seconds), 0) AS total,'
                .' COALESCE(SUM(e.elapsed_seconds) FILTER (WHERE e.billable), 0) AS billable,'
                .' COALESCE(SUM(e.elapsed_seconds) FILTER (WHERE NOT e.billable), 0) AS non_billable',
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'billable' => (int) ($row->billable ?? 0),
            'non_billable' => (int) ($row->non_billable ?? 0),
        ];
    }

    /**
     * The grid of one ISO week: one row per client, project and task, the seconds per Prague day,
     * the totals of the days and of the week, and which days contain overlapping entries (D-04).
     *
     * One grouped query reads the seconds together with the names of the client, the project and
     * the task (archived ones included), so nothing else is read per row. A row's `days` map holds
     * every day of the week; a day without an entry is null, so a day with entries of zero length
     * still reads 0:00 and an empty day reads as empty. `day_totals` holds only the days that have
     * entries. A row without a project has a null `project`, a row without a task a null `task`.
     *
     * @return array{rows: list<array{key: string, client: string, project: string|null, task: string|null, days: array<string, int|null>, total: int}>, day_totals: array<string, int>, day_overlaps: array<string, bool>, total: int}
     */
    public function weekRows(User $user, string $date, CarbonInterface $now): array
    {
        [$from, $to] = $this->weekRange($date);
        $days = $this->weekDays($date);

        $query = DB::query()
            ->fromSub($this->entries($user, $from, $to, $now, withOverlap: true), 'e')
            ->leftJoin('clients', 'clients.id', '=', 'e.client_id')
            ->leftJoin('projects', 'projects.id', '=', 'e.project_id')
            ->leftJoin('tasks', 'tasks.id', '=', 'e.task_id')
            ->selectRaw(
                'e.client_id, clients.name AS client_name, e.project_id, projects.key AS project_key,'
                .' e.task_id, tasks.reference AS task_reference,'
                ." (e.started_at AT TIME ZONE 'Europe/Prague')::date AS day,"
                .' SUM(e.elapsed_seconds) AS seconds, bool_or(e.overlaps) AS overlaps',
            )
            ->groupBy([
                'e.client_id', 'clients.name', 'e.project_id', 'projects.key', 'e.task_id', 'tasks.reference', 'tasks.number',
                DB::raw("(e.started_at AT TIME ZONE 'Europe/Prague')::date"),
            ]);

        // Clients by name in Czech order, then the project keys and the task numbers; rows without a
        // project or a task come last in their group. The ids keep equal names in one stable order.
        CzechCollation::orderBy($query, 'clients.name');
        $query
            ->orderBy('e.client_id')
            ->orderByRaw('projects.key IS NULL')
            ->orderBy('projects.key')
            ->orderByRaw('tasks.number IS NULL')
            ->orderBy('tasks.number')
            ->orderBy('e.task_id');

        $empty = array_fill_keys($days, null);
        $rows = [];
        $dayTotals = [];
        $dayOverlaps = array_fill_keys($days, false);
        $total = 0;

        foreach ($query->get() as $record) {
            $day = substr((string) $record->day, 0, 10);

            if (! array_key_exists($day, $empty)) {
                continue;
            }

            $key = $record->client_id.'|'.($record->project_id ?? '-').'|'.($record->task_id ?? '-');
            $seconds = (int) $record->seconds;

            $rows[$key] ??= [
                'key' => $key,
                'client' => (string) $record->client_name,
                'project' => $record->project_key === null ? null : (string) $record->project_key,
                'task' => $record->task_reference === null ? null : (string) $record->task_reference,
                'days' => $empty,
                'total' => 0,
            ];
            $rows[$key]['days'][$day] = $seconds;
            $rows[$key]['total'] += $seconds;

            $dayTotals[$day] = ($dayTotals[$day] ?? 0) + $seconds;
            $dayOverlaps[$day] = $dayOverlaps[$day] || (bool) $record->overlaps;
            $total += $seconds;
        }

        return [
            'rows' => array_values($rows),
            'day_totals' => $dayTotals,
            'day_overlaps' => $dayOverlaps,
            'total' => $total,
        ];
    }

    /**
     * The entries of one user that started in `[$from, $to)`, with `elapsed_seconds` and, on
     * request, the overlap flag, as the inner query of the aggregates. Read through the model, so
     * its global scopes apply.
     */
    private function entries(User $user, CarbonInterface $from, CarbonInterface $to, CarbonInterface $now, bool $withOverlap = false): QueryBuilder
    {
        $query = TimeEntry::query()
            ->withElapsedSeconds($now)
            ->where('time_entries.user_id', $user->getKey())
            ->where('time_entries.started_at', '>=', $from)
            ->where('time_entries.started_at', '<', $to);

        if ($withOverlap) {
            $query->withOverlapFlag();
        }

        return $query->toBase();
    }

    private function monday(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date, self::ZONE)->startOfDay();

        return $day->subDays($day->isoWeekday() - 1)->startOfDay();
    }
}
