<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\ProjectResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Attributes\On;

/**
 * The tab "Projekty" of the Admin client page (CL-03, UI-SPEC Surface I): the projects of the client with their
 * status and priority, the unbilled time of each as H:MM and the "Pevná cena" badge. Admin only.
 *
 * Archived projects stay listed and are marked. The unbilled time is the correlated sub-select of
 * {@see TimeTotals::projectColumns()}, so the number of queries does not depend on the number of rows; it counts time
 * held by a draft as unbilled, like the stats row above (R-27-1).
 */
#[AccessRule(Audience::AdminOnly, reason: 'Unbilled time and billing types of a client are finance data; a Partner never sees this tab.')]
final class ClientProjectsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'projects';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoices.client_panel.tabs.projects');
    }

    /**
     * Time saved or deleted elsewhere on the page changes the unbilled column; the event only has to re-render.
     */
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshAfterTimeChange(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(static fn (Builder $query): Builder => app(TimeTotals::class)->projectColumns(
                $query->withoutGlobalScopes([SoftDeletingScope::class])->with('billing'),
                TimerClock::now(),
            ))
            ->columns([
                TextColumn::make('name')
                    ->label(__('invoices.client_panel.projects.columns.name'))
                    ->weight('semibold')
                    ->description(static fn (Project $record): ?string => $record->trashed() ? (string) __('invoices.client_panel.projects.archived') : null)
                    ->url(static fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('invoices.client_panel.projects.columns.key'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('invoices.client_panel.projects.columns.status'))
                    ->badge(),
                TextColumn::make('priority')
                    ->label(__('invoices.client_panel.projects.columns.priority'))
                    ->badge(),
                TextColumn::make('unbilled_seconds')
                    ->label(__('invoices.client_panel.projects.columns.unbilled'))
                    ->state(static fn (Project $record): string => DurationFormat::hoursMinutes((int) $record->getAttribute('unbilled_seconds')))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
                TextColumn::make('billing_type')
                    ->label(__('invoices.client_panel.projects.columns.fixed_price'))
                    ->state(static fn (Project $record): ?string => $record->billing?->billing_type === BillingType::FixedPrice
                        ? (string) __('invoices.client_panel.projects.columns.fixed_price')
                        : null)
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('name')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('invoices.client_panel.projects.empty_heading'))
            ->emptyStateDescription(__('invoices.client_panel.projects.empty_body'));
    }
}
