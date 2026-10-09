<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Support\ActivityPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only history of one record: the activity rows whose subject it is (D-07).
 *
 * The record's model must log activity (`LogsAllowlistedActivity`), which
 * provides the `activitiesAsSubject` relation. A later phase attaches the
 * history to a record with a concrete subclass of a few lines, which carries
 * its own audience declaration, and lists it in the resource's `getRelations()`:
 *
 *     #[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what.')]
 *     final class TaskHistoryRelationManager extends ActivityHistoryRelationManager {}
 *
 * The declaration belongs on the concrete class: attributes are not inherited,
 * so a subclass without one is denied to everybody (D-03).
 *
 * The table has no header, record or bulk actions, shows the same columns as the
 * global overview minus the subject, and never loads the subject model.
 */
abstract class ActivityHistoryRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'activitiesAsSubject';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.activity.relation_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('causer'))
            ->columns(ActivityPresenter::columns(withSubject: false))
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading(__('kokpit.activity.empty_heading'))
            ->emptyStateDescription(__('kokpit.activity.empty_description'));
    }
}
