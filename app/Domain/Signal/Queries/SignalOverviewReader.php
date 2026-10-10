<?php

declare(strict_types=1);

namespace App\Domain\Signal\Queries;

use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use Illuminate\Support\Collection;

/**
 * Reads of the signed-in user's planner over a range of days or weeks, for the overview, the week
 * and the reflection. Every query goes through the owner scope of the models.
 */
final class SignalOverviewReader
{
    /**
     * The tasks from $from to $to (both included) as the plain rows SignalStats works with.
     *
     * @return list<array{for_date: string, category: string, is_done: bool}>
     */
    public function taskRows(string $from, string $to): array
    {
        return SignalTask::query()
            ->whereBetween('for_date', [$from, $to])
            ->get(['for_date', 'category', 'is_done'])
            ->map(static fn (SignalTask $task): array => ['for_date' => $task->for_date, 'category' => $task->category->value, 'is_done' => $task->is_done])
            ->all();
    }

    /**
     * The stored deep-work days from $from to $to, by day.
     *
     * @return array<string, array{planned: int, completed: int}>
     */
    public function deepWorkRows(string $from, string $to): array
    {
        $rows = [];

        foreach (SignalDeepWorkDay::query()->whereBetween('for_date', [$from, $to])->get(['for_date', 'planned', 'completed']) as $row) {
            $rows[$row->for_date] = ['planned' => $row->planned, 'completed' => $row->completed];
        }

        return $rows;
    }

    /**
     * @return Collection<int, SignalWeeklyGoal>
     */
    public function goals(string $weekStart): Collection
    {
        return SignalWeeklyGoal::query()->where('week_start', $weekStart)->orderBy('position')->get();
    }

    public function recap(string $weekStart): ?SignalWeeklyRecap
    {
        return SignalWeeklyRecap::query()->where('week_start', $weekStart)->first();
    }

    /**
     * @return Collection<int, SignalWeeklyRecap>
     */
    public function recentRecaps(int $limit): Collection
    {
        return SignalWeeklyRecap::query()->orderByDesc('week_start')->limit($limit)->get();
    }

    /**
     * The recaps whose week starts from $from to $to (both included), newest first.
     *
     * @return Collection<int, SignalWeeklyRecap>
     */
    public function recapsBetween(string $from, string $to): Collection
    {
        return SignalWeeklyRecap::query()->whereBetween('week_start', [$from, $to])->orderByDesc('week_start')->get();
    }

    /**
     * @return Collection<int, SignalRecurringTask>
     */
    public function recurringTemplates(): Collection
    {
        return SignalRecurringTask::query()->orderByDesc('active')->orderBy('created_at')->orderBy('id')->get();
    }
}
