<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\ClientInput;
use App\Domain\Clients\Actions\CreateClient as CreateClientAction;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\ClientHistoryRelationManager;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;

/**
 * The Admin client screens: list, create, edit and view with billing data and terms
 * (CL-01), archive and restore (CL-05, D-11).
 *
 * Nothing here writes a row: the Create and Edit pages hand the form state to the
 * domain Actions CreateClient and UpdateClient, which own every rule. The stage
 * is a label and a list filter only (D-10). The client is Admin-only data (D-06):
 * the resource is not globally searchable, and a Partner gets 403 on every route.
 * A new client form opens pre-filled from the typed defaults (D-13); the values are
 * copied into the client row and never read from the settings again. Archiving
 * is a soft delete through the domain Actions; there is no force-delete action
 * anywhere, so a client is never removed from the application.
 *
 * @phpstan-import-type ClientData from CreateClientAction
 */
#[AccessRule(Audience::AdminOnly, reason: 'Clients carry rates, payment terms and billing data; a Partner never sees a client record.')]
final class ClientResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Client::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.clients.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedUserGroup;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.clients.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.clients.plural_model_label');
    }

    /**
     * Archived clients stay reachable: the trashed filter decides what the list
     * shows. The Partner scope is a different scope and stays on.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * An archived client opens by its URL.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        $currencies = array_combine(Money::isoCurrencyCodes(), Money::isoCurrencyCodes());

        return $schema->components([
            Section::make(__('kokpit.clients.sections.billing_data'))
                ->columns(2)
                ->schema([
                    // The country comes first: the Czech company number check and the ARES lookup depend on it.
                    TextInput::make('country')
                        ->label(__('kokpit.clients.fields.country'))
                        ->helperText(__('kokpit.clients.hints.country'))
                        ->default(static fn (): ?string => self::nonEmpty(app(SupplierSettings::class)->country))
                        ->required()
                        ->length(2)
                        ->regex('/^[A-Z]{2}$/')
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(static fn (mixed $state): mixed => is_string($state) ? mb_strtoupper(trim($state)) : $state),
                    TextInput::make('company_number')
                        ->label(__('kokpit.clients.fields.company_number'))
                        ->maxLength(32),
                    TextInput::make('name')
                        ->label(__('kokpit.clients.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('tax_number')
                        ->label(__('kokpit.clients.fields.tax_number'))
                        ->maxLength(32),
                    TextInput::make('street')
                        ->label(__('kokpit.clients.fields.street'))
                        ->maxLength(255),
                    TextInput::make('city')
                        ->label(__('kokpit.clients.fields.city'))
                        ->maxLength(255),
                    TextInput::make('postal_code')
                        ->label(__('kokpit.clients.fields.postal_code'))
                        ->maxLength(20),
                ]),
            Section::make(__('kokpit.clients.sections.terms'))
                ->columns(2)
                ->schema([
                    Select::make('stage')
                        ->label(__('kokpit.clients.fields.stage'))
                        ->helperText(__('kokpit.clients.hints.stage'))
                        ->options(ClientStage::class)
                        ->default(ClientStage::Active->value)
                        ->required()
                        ->native(false),
                    Select::make('currency')
                        ->label(__('kokpit.clients.fields.currency'))
                        ->options($currencies)
                        ->default(static fn (): string => app(DefaultsSettings::class)->default_currency)
                        ->searchable()
                        ->required()
                        ->live(),
                    TextInput::make('hourly_rate')
                        ->label(__('kokpit.clients.fields.hourly_rate'))
                        ->helperText(__('kokpit.clients.hints.hourly_rate'))
                        ->inputMode('decimal')
                        ->default(static fn (): string => app(DefaultsSettings::class)->toFormState()['default_hourly_rate'])
                        ->required()
                        ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $currency = $get('currency');

                            if (! is_string($value) || ! is_string($currency) || ! Money::isKnownCurrency($currency)) {
                                return;
                            }

                            try {
                                ClientInput::rate($value, $currency);
                            } catch (InvalidArgumentException) {
                                $fail(__('kokpit.clients.errors.rate_invalid'));
                            }
                        })
                        ->suffix(static fn (Get $get): string => (is_string($get('currency')) ? $get('currency') : '').' / '.__('kokpit.settings.defaults.per_hour')),
                    TextInput::make('payment_terms_days')
                        ->label(__('kokpit.clients.fields.payment_terms_days'))
                        ->helperText(__('kokpit.clients.hints.payment_terms_days'))
                        ->default(static fn (): int => app(InvoicingSettings::class)->payment_due_days)
                        ->required()
                        ->numeric()
                        ->rules(['integer', 'between:0,365'])
                        ->suffix(__('kokpit.clients.days')),
                    Select::make('invoice_language')
                        ->label(__('kokpit.clients.fields.invoice_language'))
                        ->options(InvoiceLanguage::class)
                        ->default(static fn (): string => app(DefaultsSettings::class)->default_invoice_language->value)
                        ->required()
                        ->native(false),
                    TextInput::make('invoice_email')
                        ->label(__('kokpit.clients.fields.invoice_email'))
                        ->email()
                        ->maxLength(255),
                    Toggle::make('online_payment_enabled')
                        ->label(__('kokpit.clients.fields.online_payment_enabled'))
                        ->helperText(__('kokpit.clients.hints.online_payment_enabled'))
                        ->default(static fn (): bool => app(PaymentSettings::class)->online_payments_enabled)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.clients.sections.tags'))
                ->schema([
                    // Always typed: without a type the plugin reads and syncs the tags of every type.
                    SpatieTagsInput::make('tags')
                        ->label(__('kokpit.clients.fields.tags'))
                        ->helperText(__('kokpit.clients.hints.tags'))
                        ->type(TagType::Client->value),
                ]),
        ]);
    }

    /**
     * The read-only detail page: billing data and terms.
     */
    public static function infolist(Schema $schema): Schema
    {
        $empty = __('kokpit.clients.empty_value');

        return $schema->components([
            Section::make(__('kokpit.clients.sections.billing_data'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('kokpit.clients.fields.name')),
                    TextEntry::make('company_number')->label(__('kokpit.clients.fields.company_number'))->placeholder($empty),
                    TextEntry::make('tax_number')->label(__('kokpit.clients.fields.tax_number'))->placeholder($empty),
                    TextEntry::make('country')->label(__('kokpit.clients.fields.country')),
                    TextEntry::make('street')->label(__('kokpit.clients.fields.street'))->placeholder($empty),
                    TextEntry::make('city')->label(__('kokpit.clients.fields.city'))->placeholder($empty),
                    TextEntry::make('postal_code')->label(__('kokpit.clients.fields.postal_code'))->placeholder($empty),
                ]),
            Section::make(__('kokpit.clients.sections.terms'))
                ->columns(2)
                ->schema([
                    TextEntry::make('stage')->label(__('kokpit.clients.fields.stage'))->badge(),
                    TextEntry::make('currency')->label(__('kokpit.clients.fields.currency')),
                    TextEntry::make('hourly_rate')
                        ->label(__('kokpit.clients.fields.hourly_rate'))
                        ->state(static fn (Client $record): string => str_replace('.', ',', $record->hourly_rate?->toMajor() ?? '').' '.$record->currency.' / '.__('kokpit.settings.defaults.per_hour')),
                    TextEntry::make('payment_terms_days')
                        ->label(__('kokpit.clients.fields.payment_terms_days'))
                        ->suffix(' '.__('kokpit.clients.days')),
                    TextEntry::make('invoice_language')->label(__('kokpit.clients.fields.invoice_language')),
                    TextEntry::make('invoice_email')->label(__('kokpit.clients.fields.invoice_email'))->placeholder($empty),
                    IconEntry::make('online_payment_enabled')
                        ->label(__('kokpit.clients.fields.online_payment_enabled'))
                        ->boolean(),
                ]),
            Section::make(__('kokpit.clients.sections.tags'))
                ->schema([
                    SpatieTagsEntry::make('tags')
                        ->label(__('kokpit.clients.fields.tags'))
                        ->type(TagType::Client->value)
                        ->placeholder($empty),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.clients.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_number')
                    ->label(__('kokpit.clients.fields.company_number'))
                    ->searchable()
                    ->placeholder(__('kokpit.clients.empty_value')),
                TextColumn::make('city')
                    ->label(__('kokpit.clients.fields.city'))
                    ->placeholder(__('kokpit.clients.empty_value'))
                    ->sortable(),
                TextColumn::make('stage')
                    ->label(__('kokpit.clients.fields.stage'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('currency')
                    ->label(__('kokpit.clients.fields.currency'))
                    ->sortable(),
                SpatieTagsColumn::make('tags')
                    ->label(__('kokpit.clients.fields.tags'))
                    ->type(TagType::Client->value),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->label(__('kokpit.clients.filters.stage'))
                    ->options(ClientStage::class),
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
                    self::archiveBulkAction(DeleteBulkAction::make()),
                    self::restoreBulkAction(RestoreBulkAction::make()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('kokpit.clients.empty_heading'))
            ->emptyStateDescription(__('kokpit.clients.empty_description'));
    }

    /**
     * The Czech wording of the archive action: a soft delete through the domain
     * Action, never a removal. The confirmation names what the archive does.
     */
    public static function archiveAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->label(__('kokpit.clients.actions.archive'))
            ->modalHeading(__('kokpit.clients.actions.archive_heading'))
            ->modalDescription(__('kokpit.clients.actions.archive_description'))
            ->modalSubmitActionLabel(__('kokpit.clients.actions.archive'))
            ->successNotificationTitle(__('kokpit.clients.notifications.archived'))
            ->using(static function (Model $record): bool {
                assert($record instanceof Client);

                app(ArchiveClient::class)->handle($record);

                return true;
            });
    }

    public static function restoreAction(RestoreAction $action): RestoreAction
    {
        return $action
            ->label(__('kokpit.clients.actions.restore'))
            ->modalHeading(__('kokpit.clients.actions.restore_heading'))
            ->modalDescription(__('kokpit.clients.actions.restore_description'))
            ->modalSubmitActionLabel(__('kokpit.clients.actions.restore'))
            ->successNotificationTitle(__('kokpit.clients.notifications.restored'))
            ->using(static function (Model $record): bool {
                assert($record instanceof Client);

                app(RestoreClient::class)->handle($record);

                return true;
            });
    }

    public static function archiveBulkAction(DeleteBulkAction $action): DeleteBulkAction
    {
        return $action
            ->label(__('kokpit.clients.actions.archive'))
            ->modalHeading(__('kokpit.clients.actions.archive_heading'))
            ->modalDescription(__('kokpit.clients.actions.archive_description'))
            ->modalSubmitActionLabel(__('kokpit.clients.actions.archive'))
            ->successNotificationTitle(__('kokpit.clients.notifications.archived_many'))
            ->using(static fn (EloquentCollection $records) => self::eachClient($records, ArchiveClient::class));
    }

    public static function restoreBulkAction(RestoreBulkAction $action): RestoreBulkAction
    {
        return $action
            ->label(__('kokpit.clients.actions.restore'))
            ->modalHeading(__('kokpit.clients.actions.restore_heading'))
            ->modalDescription(__('kokpit.clients.actions.restore_description'))
            ->modalSubmitActionLabel(__('kokpit.clients.actions.restore'))
            ->successNotificationTitle(__('kokpit.clients.notifications.restored_many'))
            ->using(static fn (EloquentCollection $records) => self::eachClient($records, RestoreClient::class));
    }

    public static function getRelations(): array
    {
        return [
            ClientHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    /**
     * Adds the rate of a client to the flat form state as text with a decimal comma.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillRateState(Client $client, array $data): array
    {
        $data['hourly_rate'] = str_replace('.', ',', $client->hourly_rate?->toMajor() ?? '');

        return $data;
    }

    /**
     * The form state in the shape of the domain Actions: enum cases become their values.
     *
     * @param  array<string, mixed>  $data
     * @return ClientData
     */
    public static function actionData(array $data): array
    {
        return [
            'name' => self::string($data['name'] ?? null),
            'company_number' => self::nullableString($data['company_number'] ?? null),
            'tax_number' => self::nullableString($data['tax_number'] ?? null),
            'country' => self::string($data['country'] ?? null),
            'street' => self::nullableString($data['street'] ?? null),
            'city' => self::nullableString($data['city'] ?? null),
            'postal_code' => self::nullableString($data['postal_code'] ?? null),
            'stage' => self::string($data['stage'] ?? null),
            'currency' => self::string($data['currency'] ?? null),
            'hourly_rate' => self::nullableString($data['hourly_rate'] ?? null),
            'payment_terms_days' => $data['payment_terms_days'] ?? null,
            'invoice_email' => self::nullableString($data['invoice_email'] ?? null),
            'invoice_language' => self::string($data['invoice_language'] ?? null),
            'online_payment_enabled' => (bool) ($data['online_payment_enabled'] ?? false),
        ];
    }

    /**
     * Runs an archive or restore Action over the selected clients, one by one.
     *
     * @param  EloquentCollection<int, Model>  $records
     * @param  class-string<ArchiveClient|RestoreClient>  $action
     */
    private static function eachClient(EloquentCollection $records, string $action): void
    {
        foreach ($records as $record) {
            if ($record instanceof Client) {
                app($action)->handle($record);
            }
        }
    }

    private static function nonEmpty(string $text): ?string
    {
        return trim($text) === '' ? null : $text;
    }

    private static function nullableString(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        return is_string($state) ? $state : null;
    }

    private static function string(mixed $state): string
    {
        return self::nullableString($state) ?? '';
    }
}
