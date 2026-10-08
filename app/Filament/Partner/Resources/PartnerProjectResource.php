<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ListPartnerProjects;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ViewPartnerProject;
use App\Filament\Support\ProjectColumns;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The read-only "Moje projekty" list and detail of a Partner (PR-04, D-07).
 *
 * The query is the scoped `Project` model: only the own client's client-visible,
 * non-archived projects exist for a Partner. The table and the infolist are built
 * only from the Partner-safe builders of `ProjectColumns`; there is no create or
 * edit page, no action, no bulk action, no relation manager, no export and no
 * global search. The slug differs from the Admin project resource.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner sees the own client\'s client-visible projects read-only; only Partner-safe fields are shown.')]
final class PartnerProjectResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Project::class;

    protected static ?string $slug = 'my-projects';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 10;

    /**
     * The declaration AND the policy AND a Partner with a client. This method
     * replaces the trait's one with a stricter check, so it repeats the trait's
     * two conditions: the Admin must not get a second navigation entry (the
     * Admin project resource serves the Admin).
     */
    public static function canAccess(): bool
    {
        return AccessRules::allows(self::class)
            && parent::canAccess()
            && app(PartnerContext::class)->partnerClientId() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('kokpit.partner_projects.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedRectangleStack;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.partner_projects.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.partner_projects.plural_model_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(ProjectColumns::partnerColumns())
            ->defaultSort('name')
            ->emptyStateHeading(__('kokpit.partner_projects.empty_heading'))
            ->emptyStateDescription(__('kokpit.partner_projects.empty_description'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(ProjectColumns::partnerEntries());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerProjects::route('/'),
            'view' => ViewPartnerProject::route('/{record}'),
        ];
    }
}
