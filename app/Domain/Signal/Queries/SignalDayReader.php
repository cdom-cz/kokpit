<?php

declare(strict_types=1);

namespace App\Domain\Signal\Queries;

use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalDayOverride;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalSetting;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Collection;

/**
 * Reads of the signed-in user's planner for one day. Every query goes through the owner scope of the
 * models, so it can only ever return the own rows.
 */
final class SignalDayReader
{
    /**
     * The tasks of a day: by colour, then by manual position, then by creation.
     *
     * @return Collection<int, SignalTask>
     */
    public function tasks(string $day): Collection
    {
        return SignalTask::query()
            ->where('for_date', $day)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->sort(static fn (SignalTask $a, SignalTask $b): int => [$a->category->rank(), $a->position] <=> [$b->category->rank(), $b->position])
            ->values();
    }

    /**
     * The number of tasks per category on a day.
     *
     * @return array<string, int>
     */
    public function categoryCounts(string $day): array
    {
        $counts = array_fill_keys(array_column(SignalCategory::cases(), 'value'), 0);

        foreach (SignalTask::query()->where('for_date', $day)->selectRaw('category, count(*)::int as aggregate')->groupBy('category')->get() as $row) {
            $counts[(string) $row->getRawOriginal('category')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * The reserved slots of the day's limit: the number of active recurring templates per category that
     * fire on the day and have no task on it yet. A template that was materialized already holds its slot
     * as a real task, which categoryCounts() counts, so it is not reserved a second time.
     *
     * @return array<string, int>
     */
    public function recurringCounts(string $day): array
    {
        $counts = array_fill_keys(array_column(SignalCategory::cases(), 'value'), 0);
        $materialized = SignalTask::query()->where('for_date', $day)->whereNotNull('recurring_id')->pluck('recurring_id')->all();

        foreach (SignalRecurringTask::query()->firingOn($day)->whereNotIn('id', $materialized)->get(['id', 'category']) as $template) {
            $counts[$template->category->value]++;
        }

        return $counts;
    }

    /**
     * The ids of those tasks that cannot be deleted: they carry a template that is still active and fires
     * on the day, and the day is today. The next materialization would generate such a task again, so
     * deleting it would only make it come back with a new id. The past is never materialized again and a
     * future day has no instances, so nothing is pinned there.
     *
     * @param  Collection<int, SignalTask>  $tasks  the tasks to check, all of the given day
     * @return list<string>
     */
    public function pinnedTaskIds(string $day, Collection $tasks): array
    {
        $templateIds = $tasks->pluck('recurring_id')->filter()->unique()->values()->all();

        if ($day !== SignalCalendar::today() || $templateIds === []) {
            return [];
        }

        $firing = SignalRecurringTask::query()->firingOn($day)->whereIn('id', $templateIds)->pluck('id')->all();

        return array_values(
            $tasks
                ->filter(static fn (SignalTask $task): bool => $task->recurring_id !== null && in_array($task->recurring_id, $firing, true))
                ->map(static fn (SignalTask $task): string => $task->id)
                ->all(),
        );
    }

    /**
     * The recurring templates that fire on a future day, as read-only shadows; templates already
     * materialized into a real task of that day are left out.
     *
     * @param  Collection<int, SignalTask>  $tasks  the real tasks of the day
     * @return Collection<int, SignalRecurringTask>
     */
    public function shadows(string $day, Collection $tasks): Collection
    {
        $materialized = $tasks->pluck('recurring_id')->filter()->all();

        return SignalRecurringTask::query()
            ->firingOn($day)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->reject(static fn (SignalRecurringTask $template): bool => in_array($template->id, $materialized, true))
            ->values();
    }

    public function isUnlocked(string $day): bool
    {
        return SignalDayOverride::query()->where('for_date', $day)->exists();
    }

    /**
     * The unlocked days among the given ones.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    public function unlockedDays(array $days): array
    {
        if ($days === []) {
            return [];
        }

        return array_values(SignalDayOverride::query()->whereIn('for_date', $days)->pluck('for_date')->map(static fn ($day): string => (string) $day)->all());
    }

    /**
     * @return array{weekday: int, weekend: int}
     */
    public function blockSettings(): array
    {
        $settings = SignalSetting::query()->first();

        return [
            'weekday' => $settings->deep_work_weekday_blocks ?? SignalSetting::DEFAULT_WEEKDAY_BLOCKS,
            'weekend' => $settings->deep_work_weekend_blocks ?? SignalSetting::DEFAULT_WEEKEND_BLOCKS,
        ];
    }

    /**
     * The planned and completed deep-work blocks of a day. Without a stored row `planned` follows the
     * current settings and nothing is completed.
     *
     * @return array{planned: int, completed: int}
     */
    public function deepWork(string $day): array
    {
        $row = SignalDeepWorkDay::query()->where('for_date', $day)->first();

        if ($row !== null) {
            return ['planned' => $row->planned, 'completed' => $row->completed];
        }

        $settings = $this->blockSettings();

        return ['planned' => SignalRules::plannedBlocks($day, $settings['weekday'], $settings['weekend']), 'completed' => 0];
    }
}
