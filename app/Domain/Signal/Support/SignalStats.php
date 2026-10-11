<?php

declare(strict_types=1);

namespace App\Domain\Signal\Support;

/**
 * The pure aggregations behind the overview. They take plain rows, so the math is testable without
 * a database and independent of how the rows were read.
 *
 * A task row is `['for_date' => 'Y-m-d', 'category' => 'main|medium|other|extra', 'is_done' => bool]`.
 *
 * @phpstan-type TaskRow array{for_date: string, category: string, is_done: bool}
 * @phpstan-type Progress array{done: int, total: int}
 * @phpstan-type DayTrend array{date: string, done: int, total: int, ratio: float}
 */
final class SignalStats
{
    public const array CATEGORIES = ['main', 'medium', 'other', 'extra'];

    /**
     * Done and total per category.
     *
     * @param  iterable<TaskRow>  $tasks
     * @return array<string, Progress>
     */
    public static function categoryProgress(iterable $tasks): array
    {
        $progress = array_fill_keys(self::CATEGORIES, ['done' => 0, 'total' => 0]);

        foreach ($tasks as $task) {
            $progress[$task['category']]['total']++;

            if ($task['is_done']) {
                $progress[$task['category']]['done']++;
            }
        }

        return $progress;
    }

    /**
     * @param  iterable<TaskRow>  $tasks
     * @return Progress
     */
    public static function totalProgress(iterable $tasks): array
    {
        $done = 0;
        $total = 0;

        foreach ($tasks as $task) {
            $total++;
            $done += $task['is_done'] ? 1 : 0;
        }

        return ['done' => $done, 'total' => $total];
    }

    /**
     * The completion ratio of every day from $from to $to; a day without tasks has ratio 0.
     *
     * @param  iterable<TaskRow>  $tasks
     * @return list<DayTrend>
     */
    public static function dailyTrend(iterable $tasks, string $from, string $to): array
    {
        $byDay = [];

        foreach ($tasks as $task) {
            $byDay[$task['for_date']]['total'] = ($byDay[$task['for_date']]['total'] ?? 0) + 1;
            $byDay[$task['for_date']]['done'] = ($byDay[$task['for_date']]['done'] ?? 0) + ($task['is_done'] ? 1 : 0);
        }

        $trend = [];

        foreach (SignalCalendar::range($from, $to) as $day) {
            $total = $byDay[$day]['total'] ?? 0;
            $done = $byDay[$day]['done'] ?? 0;
            $trend[] = ['date' => $day, 'done' => $done, 'total' => $total, 'ratio' => $total > 0 ? (float) ($done / $total) : 0.0];
        }

        return $trend;
    }

    /**
     * The daily ratio of completed deep-work blocks. A day without a stored row takes `planned`
     * from the settings and has nothing completed; a day with planned = 0 has ratio 0.
     *
     * @param  array<string, array{planned: int, completed: int}>  $stored  by day
     * @return list<DayTrend>
     */
    public static function deepWorkTrend(array $stored, int $weekdayBlocks, int $weekendBlocks, string $from, string $to): array
    {
        $trend = [];

        foreach (SignalCalendar::range($from, $to) as $day) {
            $planned = $stored[$day]['planned'] ?? SignalRules::plannedBlocks($day, $weekdayBlocks, $weekendBlocks);
            $completed = $stored[$day]['completed'] ?? 0;
            $trend[] = ['date' => $day, 'done' => $completed, 'total' => $planned, 'ratio' => $planned > 0 ? (float) ($completed / $planned) : 0.0];
        }

        return $trend;
    }

    /**
     * Planned (red, blue, green) against extra (grey) tasks.
     *
     * @param  iterable<TaskRow>  $tasks
     * @return array{planned: int, extra: int, total: int}
     */
    public static function plannedVsExtra(iterable $tasks): array
    {
        $planned = 0;
        $extra = 0;

        foreach ($tasks as $task) {
            if ($task['category'] === 'extra') {
                $extra++;
            } else {
                $planned++;
            }
        }

        return ['planned' => $planned, 'extra' => $extra, 'total' => $planned + $extra];
    }

    /**
     * The number of consecutive days, ending today or yesterday, on which every main task was done.
     * A day counts only when it had main tasks. Today is included only if it already qualifies, so
     * a day in progress does not reset the streak.
     *
     * @param  iterable<TaskRow>  $tasks
     */
    public static function mainStreak(iterable $tasks, string $today): int
    {
        $byDay = [];

        foreach ($tasks as $task) {
            if ($task['category'] !== 'main') {
                continue;
            }

            $byDay[$task['for_date']]['total'] = ($byDay[$task['for_date']]['total'] ?? 0) + 1;
            $byDay[$task['for_date']]['done'] = ($byDay[$task['for_date']]['done'] ?? 0) + ($task['is_done'] ? 1 : 0);
        }

        $qualifies = static fn (string $day): bool => isset($byDay[$day]) && $byDay[$day]['total'] > 0 && $byDay[$day]['done'] === $byDay[$day]['total'];

        $streak = 0;
        $cursor = $qualifies($today) ? $today : SignalCalendar::addDays($today, -1);

        for ($i = 0; $i < 400 && $qualifies($cursor); $i++) {
            $streak++;
            $cursor = SignalCalendar::addDays($cursor, -1);
        }

        return $streak;
    }
}
