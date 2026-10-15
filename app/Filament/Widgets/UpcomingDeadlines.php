<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Support\TaskColumns;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewire\Attributes\On;

/**
 * "Nejbližší termíny" on the Admin dashboard: the first ten open tasks that have a due date, the more urgent
 * priority first, then the nearest due date (RE-04 upcoming task deadlines; D-11, UI-SPEC E3). The plan and the
 * UI-SPEC say due date first; the owner's requirement for priority-aware ordering wins and is the order of the task
 * list and the Partner dashboard.
 *
 * An open task is any task whose status is not `done`; an archived task is gone with the soft delete scope, and a
 * task without a due date is not a deadline. A task is overdue when its due day is before today in Prague, and the
 * row then says so three times: the date in danger text, the "Po termínu" badge with the warning triangle and the
 * line "Po termínu o :count dní", so colour is never the only carrier. A task due today says "Dnes" in semibold
 * without an alarm colour.
 *
 * The client and the project of a task are read through one eager-loaded chain, archived ones included: an archived
 * project or client does not make an open task unnamed.
 *
 * The widget re-renders on the four time events and every 60 seconds while it is visible; nothing ticks. Admin
 * only: a Partner is refused at boot, before anything is read.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin\'s task deadlines across all clients are internal; a Partner is refused at boot and never sees the widget.')]
final class UpcomingDeadlines extends TableWidget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    /** The most rows the widget shows. */
    public const int LIMIT = 10;

    protected static ?int $sort = 40;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.dashboard-table-widget';

    /**
     * A timer started or stopped, an entry saved or deleted anywhere: the table is read again on the render that
     * follows, so the listener has nothing to do.
     */
    #[On('timer-started')]
    #[On('timer-stopped')]
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshAfterTimeChange(): void {}

    /**
     * How many whole days a due day lies before today; zero for today and for a day to come.
     *
     * @param  string  $due  the due day `Y-m-d`
     * @param  string  $today  today in Prague `Y-m-d`
     */
    public static function overdueDays(string $due, string $today): int
    {
        if ($due >= $today) {
            return 0;
        }

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $due, 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');

        return $from === null || $to === null ? 0 : (int) $from->diffInDays($to);
    }

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::UpcomingDeadlines;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.deadlines.heading');
    }

    /**
     * How many open tasks are overdue (the due day is before today in Prague), or that none is.
     */
    protected function collapsedSummary(): string
    {
        $today = app(TimesheetQuery::class)->today(TimerClock::now());

        $overdue = Task::query()
            ->whereNotNull('tasks.due_date')
            ->where('tasks.status', '!=', ProjectStatus::Done->value)
            ->where('tasks.due_date', '<', $today)
            ->count();

        return $overdue > 0
            ? trans_choice('kokpit.dashboard.deadlines.summary_overdue', $overdue, ['count' => $overdue])
            : __('kokpit.dashboard.deadlines.summary_none');
    }

    public function table(Table $table): Table
    {
        $today = app(TimesheetQuery::class)->today(TimerClock::now());

        return $table
            // The card carries the title; without this the table makes a heading from the class name.
            ->heading(null)
            ->query(fn (): Builder => $this->deadlines())
            ->defaultKeySort(false)
            ->columns([
                ViewColumn::make('task')
                    ->label(__('kokpit.dashboard.deadlines.columns.task'))
                    ->view('filament.widgets.upcoming-deadlines-cell')
                    ->viewData(['kind' => 'task', 'today' => $today]),
                ViewColumn::make('due_date')
                    ->label(__('kokpit.dashboard.deadlines.columns.due'))
                    ->view('filament.widgets.upcoming-deadlines-cell')
                    ->viewData(['kind' => 'due', 'today' => $today]),
            ])
            ->paginated(false)
            ->poll('visible.60s')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading(__('kokpit.dashboard.deadlines.empty_heading'))
            ->emptyStateDescription(__('kokpit.dashboard.deadlines.empty_description'));
    }

    /**
     * The ten first open tasks with a due date, in the default order of every task list (owner requirement, the todo
     * "Sort tasks by priority then nearest due date by default"): priority weight first (urgent, high, normal, low,
     * the same builder as the Partner dashboard and the task list), then the nearest due day with an undated task
     * last, then reference and id, so the order is the same on every render.
     *
     * @return Builder<Task>
     */
    private function deadlines(): Builder
    {
        $query = Task::query()
            ->whereNotNull('tasks.due_date')
            ->where('tasks.status', '!=', ProjectStatus::Done->value);

        return TaskColumns::orderByPriority($query)
            ->orderByRaw('"tasks"."due_date" ASC NULLS LAST')
            ->orderBy('tasks.reference')
            ->orderBy('tasks.id')
            ->with(['project' => static fn (BelongsTo $project): BelongsTo => $project
                ->withTrashed()
                ->with(['client' => static fn (BelongsTo $client): BelongsTo => $client->withTrashed()])])
            ->limit(self::LIMIT);
    }
}
