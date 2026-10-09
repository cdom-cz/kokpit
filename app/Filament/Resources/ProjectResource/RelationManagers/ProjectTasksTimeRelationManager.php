<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\TaskResource;
use Closure;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Attributes\On;

/**
 * The tab "Úkoly a čas" of the Admin project page (PR-05): every task and subtask of the project,
 * archived ones included (they keep their time), with the estimate against the worked time and the
 * billed against the unbilled time. Read-only, Admin only.
 *
 * The time columns are correlated sub-selects (`TimeTotals::taskColumns()`), so the tab costs the
 * same number of queries for 3 tasks as for 300 and runs no resolver per row. A row compares its
 * time only with an estimate of the task itself or of its parent; the project estimate is in the
 * stats row and never repeated here (research A2).
 *
 * The time logged to the project without a task is the "Bez úkolu" line of the table footer,
 * together with "Celkem". The UI-SPEC asks for one fixed last row, but a relation manager table is
 * backed by the relationship and cannot host a synthetic record (research Open Question 2); the
 * numbers are the contract. The footer shows only while the page has task rows.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Worked time, estimates and billing state are internal; the Partner project pages show none of it.')]
final class ProjectTasksTimeRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'tasks';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.time.project.tabs.tasks');
    }

    /**
     * Billing or saving an entry changes the figures of this tab; the event only has to re-render it.
     */
    #[On('time-entry-saved')]
    public function refreshAfterEntryChange(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(static fn (Builder $query): Builder => app(TimeTotals::class)->taskColumns(
                // Archived tasks keep their time, so the soft-delete scope is lifted for this tab.
                $query->withoutGlobalScopes([SoftDeletingScope::class]),
                TimerClock::now(),
            ))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('kokpit.time.project.columns.reference'))
                    ->weight('bold')
                    ->url(static fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('kokpit.time.project.columns.title'))
                    ->wrap()
                    // A single unbroken word wider than the column wraps instead of overflowing.
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                TextColumn::make('status')
                    ->label(__('kokpit.time.project.columns.status'))
                    ->badge(),
                TextColumn::make('estimate_seconds')
                    ->label(__('kokpit.time.project.columns.estimate'))
                    ->state(static fn (Task $record): string => self::seconds($record->getAttribute('estimate_seconds')) ?? (string) __('kokpit.time.empty_value'))
                    ->alignEnd()
                    ->sortable(query: self::sortBy('estimate_seconds')),
                TextColumn::make('worked_seconds')
                    ->label(__('kokpit.time.project.columns.worked'))
                    ->state(static fn (Task $record): ?string => self::seconds($record->getAttribute('worked_seconds')))
                    ->alignEnd()
                    ->sortable(query: self::sortBy('worked_seconds')),
                // Estimate minus worked; blank without an estimate, a minus sign in danger text when over.
                TextColumn::make('remaining')
                    ->label(__('kokpit.time.project.columns.remaining'))
                    ->state(static fn (Task $record): ?string => self::seconds(self::remaining($record)))
                    ->color(static fn (Task $record): ?string => (self::remaining($record) ?? 0) < 0 ? 'danger' : null)
                    ->alignEnd(),
                TextColumn::make('billed_seconds')
                    ->label(__('kokpit.time.project.columns.billed'))
                    ->state(static fn (Task $record): ?string => self::seconds($record->getAttribute('billed_seconds')))
                    ->alignEnd()
                    ->sortable(query: self::sortBy('billed_seconds')),
                TextColumn::make('unbilled_seconds')
                    ->label(__('kokpit.time.project.columns.unbilled'))
                    ->state(static fn (Task $record): ?string => self::seconds($record->getAttribute('unbilled_seconds')))
                    ->alignEnd()
                    ->sortable(query: self::sortBy('unbilled_seconds')),
                TextColumn::make('non_billable_seconds')
                    ->label(__('kokpit.time.project.columns.non_billable'))
                    ->state(static fn (Task $record): ?string => self::seconds($record->getAttribute('non_billable_seconds')))
                    ->alignEnd()
                    ->sortable(query: self::sortBy('non_billable_seconds'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Most worked time first; the id keeps the order of equal rows stable across pages.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByDesc('worked_seconds')
                ->orderBy($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->recordUrl(static fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->contentFooter(fn (): View => $this->footer())
            ->emptyStateHeading(__('kokpit.time.project.tasks_empty_heading'))
            ->emptyStateDescription(__('kokpit.time.project.tasks_empty_body'));
    }

    /**
     * The footer: the "Bez úkolu" line, then "Celkem" over every task and the entries without a
     * task. Both figures come from the same sums as the stats row of the page.
     */
    private function footer(): View
    {
        $project = $this->getOwnerRecord();
        assert($project instanceof Project);

        $totals = app(TimeTotals::class);
        $now = TimerClock::now();
        $without = $totals->withoutTask($project, $now);
        $all = $totals->forProject($project, $now);
        $dash = (string) __('kokpit.time.empty_value');

        return view('filament.resources.project-resource.tasks-time-footer', [
            'withoutTask' => [
                'estimate_seconds' => $dash,
                'worked_seconds' => DurationFormat::hoursMinutes($without['worked']),
                'remaining' => '',
                'billed_seconds' => DurationFormat::hoursMinutes($without['billed']),
                'unbilled_seconds' => DurationFormat::hoursMinutes($without['unbilled']),
                'non_billable_seconds' => DurationFormat::hoursMinutes($without['non_billable']),
            ],
            // The estimates are the ones tasks hold themselves; the remainder of a sum would mix tasks with and without one.
            'total' => [
                'estimate_seconds' => self::seconds($totals->ownTaskEstimates($project)) ?? $dash,
                'worked_seconds' => DurationFormat::hoursMinutes($all['worked']),
                'remaining' => '',
                'billed_seconds' => DurationFormat::hoursMinutes($all['billed']),
                'unbilled_seconds' => DurationFormat::hoursMinutes($all['unbilled']),
                'non_billable_seconds' => DurationFormat::hoursMinutes($all['non_billable']),
            ],
        ]);
    }

    /**
     * The sort of a column that exists only as a sub-select of the query.
     *
     * @param  literal-string  $column
     * @return Closure(Builder<Model>, string): Builder<Model>
     */
    private static function sortBy(string $column): Closure
    {
        return static fn (Builder $query, string $direction): Builder => $query->orderBy($column, $direction === 'desc' ? 'desc' : 'asc');
    }

    /**
     * Seconds read from a sub-select column as H:MM, or null when the column holds none.
     */
    private static function seconds(mixed $value): ?string
    {
        return $value === null ? null : DurationFormat::hoursMinutes((int) $value);
    }

    /**
     * Estimate minus worked in seconds, or null when the task has no estimate to compare with.
     */
    private static function remaining(Task $task): ?int
    {
        $estimate = $task->getAttribute('estimate_seconds');

        return $estimate === null ? null : (int) $estimate - (int) $task->getAttribute('worked_seconds');
    }
}
