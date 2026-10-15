<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\ViewColumn;

/**
 * The one due-date cell of the "Nejbližší termíny" widget and the Admin task list (TA-05, RE-04): the view
 * `filament.tasks.due-date-cell` built by {@see self::column()}, so the markup and the Czech copy exist once.
 *
 * An open task is any task whose status is not `done` and that is not archived; only an open task is ever overdue.
 * The day boundary is the Prague day ({@see self::today()}), never the server's or the browser's. The cell shows
 * admin-only information, so no Partner column builder may use it: the Partner list keeps its plain date.
 */
final class TaskDueDate
{
    /** The view of the cell. */
    public const string VIEW = 'filament.tasks.due-date-cell';

    /** The padding of the cell inside the dashboard card (the widget's own spacing). */
    public const string CARD_PADDING = '0.5rem 1rem';

    /** The padding of the cell inside the task list (Filament's own text-cell spacing). */
    public const string LIST_PADDING = '1rem 0.75rem';

    /**
     * Today in Prague as `Y-m-d`; it follows the frozen clock of a test.
     */
    public static function today(): string
    {
        return app(TimesheetQuery::class)->today(TimerClock::now());
    }

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

    /**
     * How many whole days an open task is overdue: zero without a due day, for a done or an archived task, and for
     * a due day of today or later. Reads loaded attributes only, so a list needs no query per row.
     *
     * @param  string  $today  today in Prague `Y-m-d`
     */
    public static function overdueDaysOf(Task $task, string $today): int
    {
        if ($task->due_date === null || $task->status === ProjectStatus::Done || $task->trashed()) {
            return 0;
        }

        return self::overdueDays($task->due_date->format('Y-m-d'), $today);
    }

    /**
     * The due-date column; the caller adds the label and, on a list, the sorting. Today is read once per table build.
     *
     * @param  string  $padding  one of the padding constants of this class, never input
     */
    public static function column(string $padding): ViewColumn
    {
        return ViewColumn::make('due_date')
            ->view(self::VIEW)
            ->viewData(['today' => self::today(), 'padding' => $padding]);
    }
}
