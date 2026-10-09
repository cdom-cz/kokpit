<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Resources\TimeEntryResource;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/**
 * The timesheet "Výkaz": the entries of the signed-in Admin by day, with the totals and the
 * overlap flags (TI-06; D-04, D-05).
 *
 * The day view is a table of the entries of one Prague day. Both views are read-only and Admin
 * only: a Partner is refused at boot, before anything is read. The view and the date live in the
 * URL (`?view=day|week&date=YYYY-MM-DD`) and are re-validated on every request, because a
 * Livewire property is client-controlled: anything else falls back to the day view of today. Every
 * control is a link to the page URL with a new view or date, so the table is built once per
 * request.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The timesheet shows measured time, which is closed to Partners.')]
class TimesheetPage extends Page implements HasTable
{
    use EnforcesPageAccessRule, InteractsWithTable;

    protected static ?string $slug = 'timesheet';

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.timesheet';

    /** `day` or `week`; the URL parameter is `view`, which the page's own blade view name occupies as a property. */
    #[Url(as: 'view')]
    public string $mode = 'day';

    #[Url]
    public ?string $date = null;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.time.timesheet.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedCalendarDays;
    }

    public function getTitle(): string
    {
        return __('kokpit.time.timesheet.title');
    }

    /**
     * Normalises a bad URL to the day view of today (T-06-30).
     */
    public function mount(): void
    {
        $this->mode = $this->resolvedMode();
        $this->date = $this->resolvedDate();
    }

    public function table(Table $table): Table
    {
        return $this->dayTable($table);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = $this->query();
        $now = TimerClock::now();
        $mode = $this->resolvedMode();
        $date = $this->resolvedDate();
        $week = $mode === 'week';
        [$from, $to] = $this->range($date);
        $summary = $query->summary($this->admin(), $from, $to, $now);
        $step = $week ? 7 : 1;
        $today = $query->today($now);
        $url = static fn (string $view, string $day): string => static::getUrl(['view' => $view, 'date' => $day]);

        return [
            'mode' => $mode,
            'date' => $date,
            'actionUrl' => static::getUrl(),
            'dayUrl' => $url('day', $date),
            'weekUrl' => $url('week', $date),
            'previousUrl' => $url($mode, $this->shift($date, -$step)),
            'nextUrl' => $url($mode, $this->shift($date, $step)),
            'previousLabel' => __($week ? 'kokpit.time.timesheet.previous_week' : 'kokpit.time.timesheet.previous_day'),
            'nextLabel' => __($week ? 'kokpit.time.timesheet.next_week' : 'kokpit.time.timesheet.next_day'),
            'currentUrl' => $url($mode, $today),
            'currentLabel' => __($week ? 'kokpit.time.timesheet.this_week' : 'kokpit.time.timesheet.today'),
            'isCurrent' => $week ? in_array($today, $query->weekDays($date), true) : $date === $today,
            'weekHeading' => $week ? $this->weekHeading($date) : null,
            'summaryLine' => __('kokpit.time.timesheet.summary', [
                'total' => DurationFormat::hoursMinutes($summary['total']),
                'billable' => DurationFormat::hoursMinutes($summary['billable']),
                'nonbillable' => DurationFormat::hoursMinutes($summary['non_billable']),
            ]),
        ];
    }

    /**
     * "Týden 42 · 12. 10. – 18. 10.": the ISO week number and the Monday and Sunday of the week.
     */
    private function weekHeading(string $date): string
    {
        $days = $this->query()->weekDays($date);
        $monday = CarbonImmutable::parse($days[0]);

        return __('kokpit.time.timesheet.week_heading', [
            'n' => (int) $monday->format('W'),
            'from' => $monday->format('j. n.'),
            'to' => CarbonImmutable::parse($days[6])->format('j. n.'),
        ]);
    }

    private function shift(string $date, int $days): string
    {
        return CarbonImmutable::parse($date)->addDays($days)->format('Y-m-d');
    }

    /**
     * The range of the shown view: the day, or the ISO week.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(string $date): array
    {
        return $this->resolvedMode() === 'week'
            ? $this->query()->weekRange($date)
            : $this->query()->dayRange($date);
    }

    public function resolvedMode(): string
    {
        return $this->query()->normalizeView($this->mode);
    }

    public function resolvedDate(): string
    {
        return $this->query()->normalizeDate($this->date, TimerClock::now());
    }

    /**
     * The table of one Prague day: the columns of the entry list without the date and the
     * "changed" toggle, the entries by start, the footer "Celkem za den" over the whole day.
     */
    private function dayTable(Table $table): Table
    {
        $columns = array_values(array_filter(
            TimeEntryResource::tableColumns(),
            static fn (Column $column): bool => ! in_array($column->getName(), ['started_at', 'updated_at'], true),
        ));

        foreach ($columns as $column) {
            if ($column->getName() !== 'elapsed_seconds') {
                continue;
            }

            // The list's three footer rows become one: the split is in the summary line above.
            $column->getSummarizer('total')?->label(__('kokpit.time.timesheet.day_total'));
            $column->getSummarizer('billable')?->visible(false);
            $column->getSummarizer('non_billable')?->visible(false);
        }

        return $table
            ->query(fn (): Builder => $this->dayEntries())
            ->columns($columns)
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderBy($query->qualifyColumn('started_at'))
                ->orderBy($query->qualifyColumn('id')))
            ->paginated(false)
            ->summaries(pageCondition: false)
            ->recordUrl(static fn (TimeEntry $record): string => TimeEntryResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('kokpit.time.timesheet.empty_day_heading'))
            ->emptyStateDescription(__('kokpit.time.timesheet.empty_day_body'));
    }

    /**
     * @return Builder<Model>
     */
    private function dayEntries(): Builder
    {
        [$from, $to] = $this->query()->dayRange($this->resolvedDate());

        return TimeEntryResource::getEloquentQuery()
            ->where('time_entries.user_id', $this->admin()->getKey())
            ->where('time_entries.started_at', '>=', $from)
            ->where('time_entries.started_at', '<', $to);
    }

    private function query(): TimesheetQuery
    {
        return app(TimesheetQuery::class);
    }

    private function admin(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
