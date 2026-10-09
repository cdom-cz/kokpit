<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\TimeEntryResource;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * The tab "Časové záznamy" of the Admin project page (PR-05, UI-SPEC Surface H): the entries of the
 * project with the columns, the badges and the filters of the entries list, without the project
 * filter, and with the two billing bulk actions of that list. Admin only.
 *
 * Nothing is built twice: the columns, the filters and the bulk actions are the ones of
 * TimeEntryResource, so a change to the list reaches this tab as well. There is no per-row edit or
 * delete here; a row opens the entry page.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Measured time is closed to Partners; the Partner project pages show none of it.')]
final class ProjectTimeEntriesRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'timeEntries';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.time.project.tabs.entries');
    }

    /**
     * Billing a selection here changes the figures of this tab; the stats row listens as well.
     */
    #[On('time-entry-saved')]
    public function refreshAfterEntryChange(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->with(['client', 'project', 'task'])
                ->scopes(['withElapsedSeconds' => [TimerClock::now()], 'withOverlapFlag' => []]))
            ->columns(TimeEntryResource::tableColumns())
            // Newest start first; the id keeps equal starts in one order across pages.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->reorder()
                ->orderByDesc($query->qualifyColumn('started_at'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            // Every entry here belongs to the project, so the project filter would filter nothing.
            ->filters(array_values(array_filter(
                TimeEntryResource::filters(),
                static fn (BaseFilter $filter): bool => $filter->getName() !== 'project_id',
            )))
            ->summaries(pageCondition: false)
            ->recordUrl(static fn (TimeEntry $record): string => TimeEntryResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make(TimeEntryResource::billingBulkActions()),
            ])
            ->emptyStateHeading(__('kokpit.time.project.entries_empty_heading'))
            ->emptyStateDescription(__('kokpit.time.project.entries_empty_body'));
    }
}
