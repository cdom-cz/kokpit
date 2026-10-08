<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Models\Activity;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Support\ActivityPresenter;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The global, read-only audit trail for the Admin (D-07).
 *
 * Nothing here creates, edits or deletes a row, and nothing loads the subject
 * model of a row: the causer is the only relation that is eager-loaded.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The audit trail shows who changed what; a Partner never sees it.')]
final class ActivityResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity';

    protected static ?int $navigationSort = 80;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.activity.navigation_label');
    }

    public static function getNavigationGroup(): string
    {
        return __('kokpit.activity.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClock;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.activity.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.activity.title');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('causer'))
            ->columns(ActivityPresenter::columns(withSubject: true))
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading(__('kokpit.activity.empty_heading'))
            ->emptyStateDescription(__('kokpit.activity.empty_description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
