<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the recurring templates that fire today into real tasks, once per template and day.
 *
 * Only today is materialized: the past is never backfilled, and a later day shows its templates as
 * read-only shadows until it becomes today. The partial unique index on (recurring_id, for_date) makes
 * the insert idempotent, so parallel requests that both see "missing" create one row; the insert
 * names no conflict target on purpose, because it runs outside the model events. New tasks go to the
 * end of their colour group.
 */
final class MaterializeSignalRecurring
{
    use AuthorizesSignal;

    public function handle(User $actor, string $day): void
    {
        $this->authorizeActor($actor);

        if ($day !== SignalCalendar::today()) {
            return;
        }

        $templates = SignalRecurringTask::query()->firingOn($day)->orderBy('created_at')->orderBy('id')->get();

        if ($templates->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($actor, $day, $templates): void {
            SignalLock::take((string) $actor->getKey(), $day);

            $done = SignalTask::query()->where('for_date', $day)->whereIn('recurring_id', $templates->pluck('id')->all())->pluck('recurring_id')->all();
            $pending = $templates->reject(static fn (SignalRecurringTask $template): bool => in_array($template->id, $done, true));

            if ($pending->isEmpty()) {
                return;
            }

            $next = [];

            foreach (SignalTask::query()->where('for_date', $day)->selectRaw('category, max(position)::int as top')->groupBy('category')->get() as $row) {
                $next[(string) $row->getRawOriginal('category')] = (int) $row->getAttribute('top') + 1;
            }

            $now = CarbonImmutable::now();
            $rows = [];

            foreach ($pending as $template) {
                $category = $template->category->value;
                $position = $next[$category] ?? 0;
                $next[$category] = $position + 1;

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'user_id' => $actor->getKey(),
                    'title' => $template->title,
                    'for_date' => $day,
                    'category' => $category,
                    'is_done' => false,
                    'recurring_id' => $template->id,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            SignalTask::query()->insertOrIgnore($rows);
        });
    }
}
