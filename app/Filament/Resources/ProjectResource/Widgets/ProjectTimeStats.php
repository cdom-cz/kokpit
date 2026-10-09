<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Widgets;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The time row of the Admin project page (PR-05): estimate, worked, billed and unbilled time as
 * H:MM. No money anywhere in this phase.
 *
 * Worked time is every finished and running entry of the project, with or without a task; a
 * running one counts at its elapsed time at render, and the page does not tick (no polling). The
 * four figures come from `TimeTotals::forProject()`, so worked = billed + unbilled + non-billable.
 *
 * Registered on `ProjectResource` and returned by `ViewProject`, not discovered at panel level: the
 * panel's widget list stays empty, so the widget is never offered to a dashboard without a record.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Worked and billed time and the estimate are internal; the Partner project pages show none of it.')]
final class ProjectTimeStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    protected static bool $isLazy = false;

    /**
     * The page shows the time at render; a clock that ticks in the stats would not match the tabs.
     */
    protected ?string $pollingInterval = null;

    #[Locked]
    public ?Model $record = null;

    /**
     * Billing or saving an entry on the page changes these figures; the event only has to re-render
     * the widget, which reads the totals again.
     */
    #[On('time-entry-saved')]
    public function refreshAfterEntryChange(): void {}

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $project = $this->record;

        if (! $project instanceof Project) {
            return [];
        }

        $totals = app(TimeTotals::class)->forProject($project, TimerClock::now());
        $estimate = $totals['estimate'];

        return [
            Stat::make(
                __('kokpit.time.project.stats.estimate'),
                $estimate === null ? (string) __('kokpit.time.empty_value') : DurationFormat::hoursMinutes($estimate),
            )->description($estimate === null ? (string) __('kokpit.time.project.estimate_missing') : null),
            $this->workedStat($totals['worked'], $estimate),
            Stat::make(__('kokpit.time.project.stats.billed'), DurationFormat::hoursMinutes($totals['billed'])),
            Stat::make(__('kokpit.time.project.stats.unbilled'), DurationFormat::hoursMinutes($totals['unbilled']))
                ->description(__('kokpit.time.project.non_billable', ['duration' => DurationFormat::hoursMinutes($totals['non_billable'])])),
        ];
    }

    /**
     * Worked time against the estimate: the whole percent (never rounded up) while within the
     * estimate, "Překročeno o" in danger text above it. An estimate of 0 counts as set, so any
     * time exceeds it, and it has no percent.
     */
    private function workedStat(int $worked, ?int $estimate): Stat
    {
        $stat = Stat::make(__('kokpit.time.project.stats.worked'), DurationFormat::hoursMinutes($worked));

        if ($estimate === null) {
            return $stat;
        }

        if ($worked > $estimate) {
            return $stat
                ->description(__('kokpit.time.project.estimate_exceeded', ['duration' => DurationFormat::hoursMinutes($worked - $estimate)]))
                ->descriptionColor('danger');
        }

        if ($estimate === 0) {
            return $stat;
        }

        return $stat->description(__('kokpit.time.project.estimate_percent', ['percent' => intdiv($worked * 100, $estimate)]));
    }
}
