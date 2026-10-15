<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * The hours of the signed-in Admin on the dashboard: today, this week (Monday to Sunday) and this month, all as
 * H:MM, the Prague calendar (RE-04; D-11, UI-SPEC E1 and U-8).
 *
 * The figures are the Admin's own entries, as the timesheet shows them: a running timer counts at its elapsed time
 * at render and the "Dnes" stat says so, every sum is formed from seconds and formatted once, and an entry belongs
 * to the Prague day it started. Nothing ticks: the widget re-renders on the four time events and by a 60 second
 * poll, so a timer stopped in another tab shows here after at most a minute (UI Considerations M10).
 *
 * Admin only: a Partner is refused at boot, before anything is read, and the Admin's dashboard is the only list
 * that offers the widget.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin\'s own measured time is internal; a Partner never sees time, so the widget is refused at boot.')]
final class HoursStats extends StatsOverviewWidget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    protected static ?int $sort = 10;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.hours-stats';

    /** No live ticking: the figures are read again every 60 seconds, while the widget is visible (`wire:poll.visible.60s`). */
    protected ?string $pollingInterval = 'visible.60s';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * A timer started or stopped, an entry saved or deleted anywhere: the figures are read again on the render
     * that follows, so the listener has nothing to do.
     */
    #[On('timer-started')]
    #[On('timer-stopped')]
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshAfterTimeChange(): void {}

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::HoursStats;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.hours.heading');
    }

    /**
     * Today's total, as the "Dnes" stat shows it, for the header of the collapsed card.
     */
    protected function collapsedSummary(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $now = TimerClock::now();
        $timesheet = app(TimesheetQuery::class);
        [$dayFrom, $dayTo] = $timesheet->dayRange($timesheet->today($now));

        return __('kokpit.dashboard.hours.summary', [
            'duration' => DurationFormat::hoursMinutes($timesheet->summary($user, $dayFrom, $dayTo, $now)['total']),
        ]);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $now = TimerClock::now();
        $timesheet = app(TimesheetQuery::class);
        $today = $timesheet->today($now);

        [$dayFrom, $dayTo] = $timesheet->dayRange($today);
        [$weekFrom, $weekTo] = $timesheet->weekRange($today);
        [$monthFrom, $monthTo] = self::monthRange($today);

        $day = $timesheet->summary($user, $dayFrom, $dayTo, $now);
        $week = $timesheet->summary($user, $weekFrom, $weekTo, $now);
        $month = $timesheet->summary($user, $monthFrom, $monthTo, $now);

        return [
            $this->todayStat($user, $day['total'], $dayFrom, $dayTo),
            Stat::make(__('kokpit.dashboard.hours.week'), DurationFormat::hoursMinutes($week['total']))
                ->description(__('kokpit.dashboard.hours.week_range', self::weekLabels($timesheet->weekDays($today)))),
            Stat::make(__('kokpit.dashboard.hours.month'), DurationFormat::hoursMinutes($month['total']))
                ->description(__('kokpit.dashboard.hours.month_billable', ['duration' => DurationFormat::hoursMinutes($month['billable'])])),
        ];
    }

    /**
     * Today's hours; while a timer of the Admin runs, the description says it is counted in warning text with the
     * clock icon (the running colour of the timer).
     */
    private function todayStat(User $user, int $seconds, CarbonImmutable $from, CarbonImmutable $to): Stat
    {
        $stat = Stat::make(__('kokpit.dashboard.hours.today'), DurationFormat::hoursMinutes($seconds));

        $running = TimeEntry::query()
            ->where('time_entries.user_id', $user->getKey())
            ->whereNull('time_entries.ended_at')
            ->where('time_entries.started_at', '>=', $from)
            ->where('time_entries.started_at', '<', $to)
            ->exists();

        if (! $running) {
            return $stat->description(__('kokpit.dashboard.hours.today_done'));
        }

        return $stat
            ->description(__('kokpit.dashboard.hours.today_running'))
            ->descriptionIcon(Heroicon::OutlinedClock)
            ->descriptionColor('warning');
    }

    /**
     * The Prague month of `$today` as the UTC range `[from, to)`: the start of its first day to the start of the
     * day after its last.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function monthRange(string $today): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');
        assert($first instanceof CarbonImmutable);

        $first = $first->startOfMonth();

        return [PragueDay::start($first->format('Y-m-d')), PragueDay::endExclusive($first->endOfMonth()->format('Y-m-d'))];
    }

    /**
     * The first and the last day of the week as `j. n.`.
     *
     * @param  list<string>  $days  the seven days `Y-m-d`, Monday first
     * @return array{from: string, to: string}
     */
    private static function weekLabels(array $days): array
    {
        $label = static fn (string $day): string => CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n.') ?? $day;

        return ['from' => $label($days[0]), 'to' => $label($days[6])];
    }
}
