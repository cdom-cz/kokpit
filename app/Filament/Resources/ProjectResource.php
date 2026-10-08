<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\EstimateHours;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\ProjectKeySuggester;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\ProjectHistoryRelationManager;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Support\ProjectColumns;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * The Admin project screens: list, create, edit, view, archive and restore, with
 * the Admin-only billing terms in their own section (PR-01, PR-02, PR-03, PR-04).
 *
 * Nothing here writes a row: the Create and Edit pages hand the form state to the
 * domain Actions CreateProject and UpdateProject, which own every rule. The client
 * of a project is chosen once and then shown read-only (research item 3). Archiving
 * is a soft delete; there is no force-delete action anywhere (D-16).
 *
 * @phpstan-import-type ProjectData from CreateProjectAction
 */
#[AccessRule(Audience::AdminOnly, reason: 'Projects carry rates, prices and billing terms; the Partner has its own read-only project list.')]
final class ProjectResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Project::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.projects.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedFolder;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.projects.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.projects.plural_model_label');
    }

    /**
     * Archived projects stay reachable: the trashed filter decides what the list
     * shows. The Partner scope is a different scope and stays on.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * An archived project opens by its URL.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('kokpit.projects.sections.project'))
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label(__('kokpit.projects.fields.client'))
                        ->relationship(
                            'client',
                            'name',
                            // Archived clients disappear from the picker on create; an existing project still shows its client.
                            modifyQueryUsing: static fn (Builder $query, string $operation): Builder => $operation === 'create'
                                ? $query
                                : $query->withoutGlobalScopes([SoftDeletingScope::class]),
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->disabled(static fn (string $operation): bool => $operation !== 'create')
                        ->dehydrated(static fn (string $operation): bool => $operation === 'create')
                        ->helperText(static fn (string $operation): ?string => $operation === 'create' ? null : (string) __('kokpit.projects.hints.client_locked')),
                    TextInput::make('name')
                        ->label(__('kokpit.projects.fields.name'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(static function (Get $get, Set $set, mixed $state, string $operation): void {
                            // Only on create, and only while the Admin has not typed a key (D-14).
                            if ($operation !== 'create' || $get('key_touched') === true) {
                                return;
                            }

                            $suggestion = ProjectKeySuggester::suggest(
                                is_string($state) ? $state : '',
                                // Archived projects keep their key reserved, so they count as taken.
                                static fn (string $key): bool => Project::withTrashed()->where('key', $key)->exists(),
                            );

                            if ($suggestion !== null) {
                                $set('key', $suggestion);
                            }
                        }),
                    TextInput::make('key')
                        ->label(__('kokpit.projects.fields.key'))
                        ->helperText(__('kokpit.projects.hints.key'))
                        ->required()
                        ->maxLength(6)
                        ->regex('/^[A-Z]{2,6}$/')
                        // The raw unique rule also counts archived rows, like the database index.
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            // Upper-case before validation, so keys that differ only in case cannot coexist.
                            $key = is_string($state) ? Str::upper(trim($state)) : '';

                            $set('key', $key);
                            $set('key_touched', $key !== '');
                        }),
                    Hidden::make('key_touched')
                        ->default(false)
                        ->dehydrated(false),
                    Select::make('status')
                        ->label(__('kokpit.projects.fields.status'))
                        ->options(ProjectStatus::class)
                        ->default(ProjectStatus::Planned->value)
                        ->required()
                        ->native(false),
                    Select::make('priority')
                        ->label(__('kokpit.projects.fields.priority'))
                        ->options(ProjectPriority::class)
                        ->default(ProjectPriority::Normal->value)
                        ->required()
                        ->native(false),
                    Textarea::make('description')
                        ->label(__('kokpit.projects.fields.description'))
                        ->rows(4)
                        ->columnSpanFull(),
                    DatePicker::make('start_date')
                        ->label(__('kokpit.projects.fields.start_date')),
                    DatePicker::make('end_date')
                        ->label(__('kokpit.projects.fields.end_date'))
                        ->afterOrEqual('start_date'),
                    Toggle::make('client_visible')
                        ->label(__('kokpit.projects.fields.client_visible'))
                        ->helperText(__('kokpit.projects.hints.client_visible'))
                        ->default(false)
                        ->columnSpanFull(),
                    SpatieTagsInput::make('tags')
                        ->label(__('kokpit.projects.fields.tags'))
                        ->type(TagType::Project->value)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.projects.sections.billing'))
                ->columns(2)
                ->schema([
                    Select::make('billing_type')
                        ->label(__('kokpit.projects.fields.billing_type'))
                        ->options(BillingType::class)
                        ->default(BillingType::Hourly->value)
                        ->required()
                        ->live()
                        ->native(false),
                    TextInput::make('hourly_rate')
                        ->label(__('kokpit.projects.fields.hourly_rate'))
                        ->helperText(__('kokpit.projects.hints.hourly_rate'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d+)?$/')
                        ->suffix(static fn (Get $get): string => self::clientCurrency($get('client_id')).' / '.__('kokpit.settings.defaults.per_hour')),
                    TextInput::make('fixed_price')
                        ->label(__('kokpit.projects.fields.fixed_price'))
                        ->helperText(__('kokpit.projects.hints.fixed_price'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d+)?$/')
                        ->required(static fn (Get $get): bool => self::enumValue($get('billing_type')) === BillingType::FixedPrice->value)
                        ->suffix(static fn (Get $get): string => self::clientCurrency($get('client_id'))),
                    TextInput::make('estimate_hours')
                        ->label(__('kokpit.projects.fields.estimate_hours'))
                        ->helperText(__('kokpit.projects.hints.estimate_hours'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d{1,2})?$/'),
                    Textarea::make('internal_note')
                        ->label(__('kokpit.projects.fields.internal_note'))
                        ->helperText(__('kokpit.projects.hints.internal_note'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = ProjectColumns::partnerColumns();
        $client = TextColumn::make('client.name')
            ->label(__('kokpit.projects.fields.client'))
            ->placeholder(__('kokpit.projects.empty_value'))
            ->sortable();
        $visible = IconColumn::make('client_visible')
            ->label(__('kokpit.projects.fields.client_visible'))
            ->boolean();

        // Name and key first, then the client, then the shared Partner-safe columns, then the visibility icon.
        array_splice($columns, 2, 0, [$client]);

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['client' => static fn ($client) => $client->withoutGlobalScopes([SoftDeletingScope::class])]))
            ->columns([...$columns, $visible])
            ->filters([
                SelectFilter::make('client_id')
                    ->label(__('kokpit.projects.filters.client'))
                    ->relationship('client', 'name', static fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label(__('kokpit.projects.filters.status'))
                    ->options(ProjectStatus::class),
                SelectFilter::make('priority')
                    ->label(__('kokpit.projects.filters.priority'))
                    ->options(ProjectPriority::class),
                TernaryFilter::make('client_visible')
                    ->label(__('kokpit.projects.filters.client_visible')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::archiveAction(DeleteAction::make()),
                self::restoreAction(RestoreAction::make()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label(__('kokpit.projects.actions.archive')),
                    RestoreBulkAction::make()->label(__('kokpit.projects.actions.restore')),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('kokpit.projects.empty_heading'))
            ->emptyStateDescription(__('kokpit.projects.empty_description'));
    }

    /**
     * The Czech wording of the archive action: a soft delete, never a removal.
     */
    public static function archiveAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->label(__('kokpit.projects.actions.archive'))
            ->modalHeading(__('kokpit.projects.actions.archive_heading'))
            ->modalDescription(__('kokpit.projects.actions.archive_description'))
            ->successNotificationTitle(__('kokpit.projects.notifications.archived'));
    }

    public static function restoreAction(RestoreAction $action): RestoreAction
    {
        return $action
            ->label(__('kokpit.projects.actions.restore'))
            ->successNotificationTitle(__('kokpit.projects.notifications.restored'));
    }

    public static function getRelations(): array
    {
        return [
            ProjectHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }

    /**
     * Adds the billing row of a project to the flat form state: the rates as
     * text with a decimal comma, the estimate in hours.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillBillingState(Project $project, array $data): array
    {
        $billing = $project->billing;

        if ($billing === null) {
            return $data;
        }

        $data['billing_type'] = $billing->billing_type->value;
        $data['hourly_rate'] = $billing->hourly_rate === null ? null : str_replace('.', ',', $billing->hourly_rate->toMajor());
        $data['fixed_price'] = $billing->fixed_price === null ? null : str_replace('.', ',', $billing->fixed_price->toMajor());
        $data['estimate_hours'] = $billing->estimate_seconds === null ? null : EstimateHours::fromSeconds($billing->estimate_seconds);
        $data['internal_note'] = $billing->internal_note;

        return $data;
    }

    /**
     * The form state in the shape of the domain Actions: enum cases become their
     * values and empty text becomes null. The client is not part of it (the
     * create page passes it separately; the edit page never sends it).
     *
     * @param  array<string, mixed>  $data
     * @return ProjectData
     */
    public static function actionData(array $data): array
    {
        return [
            'name' => self::text($data['name'] ?? null) ?? '',
            'key' => self::text($data['key'] ?? null) ?? '',
            'description' => self::text($data['description'] ?? null),
            'status' => self::enumValue($data['status'] ?? null),
            'priority' => self::enumValue($data['priority'] ?? null),
            'start_date' => self::text($data['start_date'] ?? null),
            'end_date' => self::text($data['end_date'] ?? null),
            'client_visible' => (bool) ($data['client_visible'] ?? false),
            'billing_type' => self::enumValue($data['billing_type'] ?? null) ?? BillingType::Hourly->value,
            'hourly_rate' => self::text($data['hourly_rate'] ?? null),
            'fixed_price' => self::text($data['fixed_price'] ?? null),
            'estimate_hours' => self::text($data['estimate_hours'] ?? null),
            'internal_note' => self::text($data['internal_note'] ?? null),
        ];
    }

    /**
     * The value of a form state that may hold an enum case (a Filament select over an enum).
     */
    public static function enumValue(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        return is_string($state) && $state !== '' ? $state : null;
    }

    private static function text(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        return trim($state) === '' ? null : $state;
    }

    /**
     * The currency code of the selected client, empty before one is chosen.
     */
    private static function clientCurrency(mixed $clientId): string
    {
        if (! is_string($clientId) || $clientId === '') {
            return '';
        }

        return (string) Client::query()->withTrashed()->whereKey($clientId)->value('currency');
    }
}
