<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\TransactionCzk;
use App\Domain\Finance\TransactionInput;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\TransactionDocumentsRelationManager;
use App\Filament\RelationManagers\TransactionHistoryRelationManager;
use App\Filament\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The Admin's "Transakce" screen (FI-01, D-12, D-13, D-14): the list, the create page and the edit page.
 *
 * Nothing here writes a row: the pages hand the form state to RecordTransaction, UpdateTransaction and
 * DeleteTransaction, which re-validate every key. The select options only narrow the pickers. The CZK
 * preview of the form is the very rule the Action applies ({@see TransactionCzk}), so what the Admin sees is
 * what gets stored. Transactions are not globally searchable.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Finance belongs to the Admin: a Partner never sees a transaction, an amount or a rate.')]
final class TransactionResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Transaction::class;

    protected static ?string $slug = 'transactions';

    protected static ?int $navigationSort = 60;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('finance.transactions.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedBanknotes;
    }

    public static function getModelLabel(): string
    {
        return __('finance.transactions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('finance.transactions.plural_label');
    }

    /**
     * The relations include archived rows, so an archived category, client or project still names its
     * transactions.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category.parent', 'client', 'project']);
    }

    public static function getRelations(): array
    {
        return [
            TransactionDocumentsRelationManager::class,
            TransactionHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransaction::route('/create'),
            'edit' => EditTransaction::route('/{record}/edit'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('finance.transactions.sections.transaction'))
                ->columns(2)
                ->schema([
                    ToggleButtons::make('type')
                        ->label(__('finance.transactions.fields.type'))
                        ->options(TransactionType::class)
                        ->default(TransactionType::Expense->value)
                        ->inline()
                        ->required()
                        ->live()
                        ->afterStateUpdated(static function (Get $get, Set $set): void {
                            self::typeChanged($get, $set);
                        })
                        ->columnSpanFull(),
                    DatePicker::make('transaction_date')
                        ->label(__('finance.transactions.fields.transaction_date'))
                        ->default(static fn (): string => TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d'))
                        ->required()
                        ->live(),
                    Select::make('category_id')
                        ->label(__('finance.transactions.fields.category'))
                        ->options(static fn (?Transaction $record, Get $get): array => self::categoryOptions(
                            self::typeOf($get('type')),
                            $record,
                            $get('category_id'),
                        ))
                        ->searchable()
                        ->required()
                        ->native(false),
                    TextInput::make('counterparty')
                        ->label(__('finance.transactions.fields.counterparty'))
                        ->maxLength(255),
                    TextInput::make('document_number')
                        ->label(__('finance.transactions.fields.document_number'))
                        ->maxLength(64),
                    Textarea::make('description')
                        ->label(__('finance.transactions.fields.description'))
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
            Section::make(__('finance.transactions.sections.amount'))
                ->columns(2)
                ->schema([
                    TextInput::make('amount')
                        ->label(__('finance.transactions.fields.amount'))
                        ->inputMode('decimal')
                        ->maxLength(32)
                        ->required()
                        ->live(onBlur: true),
                    Select::make('currency')
                        ->label(__('finance.transactions.fields.currency'))
                        ->options(static fn (?Transaction $record): array => self::currencyChoices($record))
                        ->default('CZK')
                        ->searchable()
                        ->required()
                        ->live()
                        ->native(false),
                    TextEntry::make('czk_preview')
                        ->label(__('finance.transactions.fields.czk_preview'))
                        ->state(static fn (Get $get, ?Transaction $record): string => self::figureNote($get, $record)['text'])
                        ->color(static fn (Get $get, ?Transaction $record): string => self::figureNote($get, $record)['kind'] === 'missing' ? 'warning' : 'gray')
                        ->visible(static fn (Get $get, ?Transaction $record): bool => self::figureNote($get, $record)['kind'] !== 'none')
                        ->columnSpanFull(),
                    Toggle::make('czk_manual')
                        ->label(__('finance.transactions.fields.czk_manual'))
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(static function (Get $get, Set $set, ?Transaction $record, mixed $state): void {
                            self::manualSwitched($get, $set, $record, $state === true);
                        })
                        // While the figure is manual a change of date, currency or amount keeps it (Pitfall 13).
                        ->helperText(static fn (Get $get): ?string => $get('czk_manual') === true
                            ? (string) __('finance.transactions.hints.czk_manual_kept')
                            : null)
                        ->visible(static fn (Get $get): bool => self::isForeign($get('currency')))
                        ->columnSpanFull(),
                    TextInput::make('czk_amount')
                        ->label(__('finance.transactions.fields.czk_amount'))
                        ->suffix('Kč')
                        ->inputMode('decimal')
                        ->maxLength(32)
                        ->visible(static fn (Get $get, ?Transaction $record): bool => self::czkAmountNeeded($get, $record))
                        ->required(static fn (Get $get, ?Transaction $record): bool => self::czkAmountNeeded($get, $record))
                        ->helperText(static fn (Get $get): ?string => self::preview($get)['kind'] === 'missing'
                            ? self::preview($get)['text']
                            : null)
                        ->validationMessages([
                            'required' => static fn (Get $get): string => self::preview($get)['kind'] === 'missing'
                                ? self::preview($get)['text']
                                : (string) __('finance.transactions.errors.czk_required'),
                        ]),
                ]),
            Section::make(__('finance.transactions.sections.context'))
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label(__('finance.transactions.fields.client'))
                        ->options(static fn (?Transaction $record, Get $get): array => self::clientOptions($record, $get('client_id')))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(static function (Get $get, Set $set): void {
                            self::clientChanged($get, $set);
                        })
                        ->native(false),
                    Select::make('project_id')
                        ->label(__('finance.transactions.fields.project'))
                        ->options(static fn (?Transaction $record, Get $get): array => self::projectOptions(
                            $record,
                            self::id($get('client_id')),
                            $get('project_id'),
                        ))
                        ->placeholder(static fn (Get $get): string => self::id($get('client_id')) === null
                            ? (string) __('finance.transactions.placeholders.project_without_client')
                            : (string) __('finance.transactions.placeholders.project'))
                        ->searchable()
                        ->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label(__('finance.transactions.columns.transaction_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('finance.transactions.columns.type'))
                    ->badge(),
                TextColumn::make('category')
                    ->label(__('finance.transactions.columns.category'))
                    ->state(static fn (Transaction $record): string => self::categoryLabel($record->category)),
                TextColumn::make('counterparty')
                    ->label(__('finance.transactions.columns.counterparty'))
                    ->placeholder(__('finance.transactions.placeholders.empty_value'))
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('finance.transactions.columns.amount'))
                    ->state(static fn (Transaction $record): string => $record->amount->format('cs'))
                    ->alignEnd(),
                TextColumn::make('czk')
                    ->label(__('finance.transactions.columns.czk'))
                    ->state(static fn (Transaction $record): HtmlString => self::czkCell($record))
                    ->html()
                    ->alignEnd(),
                TextColumn::make('client.name')
                    ->label(__('finance.transactions.columns.client'))
                    ->placeholder(__('finance.transactions.placeholders.empty_value')),
                TextColumn::make('project.name')
                    ->label(__('finance.transactions.columns.project'))
                    ->placeholder(__('finance.transactions.placeholders.empty_value')),
                TextColumn::make('documents_count')
                    ->label(__('finance.transactions.receipts.column'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            // The receipt count in one grouped query for the page, not one query per row.
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withCount('documents'))
            // Newest first. The id is the tie-breaker, so rows of one day keep one order across pages.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByDesc($query->qualifyColumn('transaction_date'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->filters(self::filters())
            ->recordUrl(static fn (Transaction $record): string => self::getUrl('edit', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->label(__('finance.transactions.actions.edit')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('finance.transactions.empty.heading'))
            ->emptyStateDescription(__('finance.transactions.empty.description'));
    }

    /**
     * The filters of the list, combined with AND and none on by default: type, category (a parent includes its
     * children), client, project, currency and an inclusive range of days.
     *
     * @return list<Filter|SelectFilter|TernaryFilter>
     */
    public static function filters(): array
    {
        return [
            SelectFilter::make('type')
                ->label(__('finance.transactions.filters.type'))
                ->options(TransactionType::class),
            SelectFilter::make('category_id')
                ->label(__('finance.transactions.filters.category'))
                ->options(static fn (): array => self::categoryFilterOptions())
                ->searchable()
                ->query(static function (Builder $query, array $data): Builder {
                    $id = self::id($data['value'] ?? null);

                    if ($id === null) {
                        return $query;
                    }

                    return $query->where(static fn (Builder $inner): Builder => $inner
                        ->where('transactions.category_id', $id)
                        ->orWhereIn('transactions.category_id', static fn (QueryBuilder $sub): QueryBuilder => $sub
                            ->select('transaction_categories.id')
                            ->from('transaction_categories')
                            ->where('transaction_categories.parent_id', $id)));
                }),
            SelectFilter::make('client_id')
                ->label(__('finance.transactions.filters.client'))
                ->options(static fn (): array => self::clientFilterOptions())
                ->searchable(),
            SelectFilter::make('project_id')
                ->label(__('finance.transactions.filters.project'))
                ->options(static fn (): array => self::projectFilterOptions())
                ->searchable(),
            TernaryFilter::make('without_receipt')
                ->label(__('finance.transactions.receipts.column'))
                ->placeholder(__('finance.transactions.receipts.filter_all'))
                ->trueLabel(__('finance.transactions.receipts.filter_without'))
                ->falseLabel(__('finance.transactions.receipts.filter_with'))
                ->queries(
                    true: static fn (Builder $query): Builder => $query->whereDoesntHave('documents'),
                    false: static fn (Builder $query): Builder => $query->whereHas('documents'),
                    blank: static fn (Builder $query): Builder => $query,
                ),
            SelectFilter::make('amount_currency')
                ->label(__('finance.transactions.filters.currency'))
                ->options(static fn (): array => self::currencyFilterOptions()),
            Filter::make('period')
                ->label(__('finance.transactions.filters.period'))
                ->schema([
                    DatePicker::make('from')
                        ->label(__('finance.transactions.filters.from')),
                    DatePicker::make('until')
                        ->label(__('finance.transactions.filters.until')),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);

                    // Both days are inclusive; the column is a plain date, so no time zone is involved.
                    return $query
                        ->when($from !== null, static fn (Builder $query): Builder => $query->where('transactions.transaction_date', '>=', $from))
                        ->when($until !== null, static fn (Builder $query): Builder => $query->where('transactions.transaction_date', '<=', $until));
                })
                ->indicateUsing(static function (array $data): array {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);
                    $label = (string) __('finance.transactions.filters.period');

                    return array_values(array_filter([
                        $from === null ? null : $label.' '.mb_strtolower((string) __('finance.transactions.filters.from')).': '.self::dayText($from),
                        $until === null ? null : $label.' '.mb_strtolower((string) __('finance.transactions.filters.until')).': '.self::dayText($until),
                    ]));
                }),
        ];
    }

    /**
     * The form state of a stored transaction: only the keys of the form, the amounts as the Admin types
     * them (decimal comma). The typed CZK figure is filled for a manual transaction only: a computed one has
     * to ask for it again if its date moves to a day without a list.
     *
     * @return array<string, mixed>
     */
    public static function formState(Transaction $transaction): array
    {
        $manual = $transaction->czk_source === CzkSource::Manual;

        return [
            'type' => $transaction->type->value,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'category_id' => $transaction->category_id,
            'amount' => self::decimalText($transaction->amount),
            'currency' => $transaction->amount_currency,
            'czk_manual' => $manual,
            'czk_amount' => $manual ? self::decimalText($transaction->czk()) : null,
            'description' => $transaction->description,
            'counterparty' => $transaction->counterparty,
            'document_number' => $transaction->document_number,
            'client_id' => $transaction->client_id,
            'project_id' => $transaction->project_id,
        ];
    }

    /**
     * The data the Actions take, from the state of the form. A CZK transaction carries no CZK figure; a
     * foreign one is manual when the switch is on or when the Admin typed the figure because no list exists,
     * and is computed again otherwise (the switch off, `czk_manual` false).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function actionData(array $data): array
    {
        $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : 'CZK';
        $typed = is_string($data['czk_amount'] ?? null) && trim($data['czk_amount']) !== '';

        if ($currency === 'CZK') {
            unset($data['czk_amount'], $data['czk_manual']);

            return $data;
        }

        if (($data['czk_manual'] ?? false) === true) {
            $data['czk_manual'] = true;

            return $data;
        }

        if ($typed) {
            // No list for the day: the typed figure is the manual one.
            unset($data['czk_manual']);

            return $data;
        }

        unset($data['czk_amount']);
        $data['czk_manual'] = false;

        return $data;
    }

    /**
     * What the form says about the CZK figure of the current input: nothing yet (`none`), the CNB conversion
     * (`cnb`) or that no list exists for the day (`missing`). It runs the rule of the Actions, so the preview
     * and the stored figure cannot disagree.
     *
     * @return array{kind: 'none'|'cnb'|'missing', text: string, czk: string|null}
     */
    public static function preview(Get $get): array
    {
        $none = ['kind' => 'none', 'text' => '', 'czk' => null];
        $currency = self::currencyOf($get('currency'));
        $day = PragueDay::day($get('transaction_date'));

        if ($currency === null || $currency === 'CZK' || $day === null) {
            return $none;
        }

        $text = $get('amount');

        if (! is_string($text)) {
            return $none;
        }

        try {
            $amount = Money::fromMajor((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text), $currency);
        } catch (InvalidArgumentException|OverflowException) {
            return $none;
        }

        if ($amount->minor <= 0) {
            return $none;
        }

        try {
            $result = app(TransactionCzk::class)->resolve($amount, $day, null);
        } catch (ValidationException) {
            return ['kind' => 'missing', 'text' => self::missingRateText($currency, $day), 'czk' => null];
        }

        $czk = Money::ofMinor($result['czk_minor'], 'CZK');
        $rateDate = $result['exchange_rate_date'];

        return [
            'kind' => 'cnb',
            'text' => (string) __('finance.transactions.hints.czk_preview', [
                'amount' => $amount->format('cs'),
                'czk' => $czk->format('cs'),
                'rate' => RateFormat::display((string) $result['exchange_rate']),
                'unit' => (string) $result['exchange_unit_amount'],
                'currency' => $currency,
                'date' => $rateDate === null ? '' : self::dayText($rateDate),
            ]),
            'czk' => self::decimalText($czk),
        ];
    }

    /**
     * The "Parent › Child" label of a category, or the plain name of a root.
     */
    public static function categoryLabel(?TransactionCategory $category): string
    {
        if (! $category instanceof TransactionCategory) {
            return (string) __('finance.transactions.placeholders.empty_value');
        }

        return $category->parent instanceof TransactionCategory
            ? $category->parent->name.' › '.$category->name
            : $category->name;
    }

    /**
     * A money amount as a person types it: the decimal comma, no grouping.
     */
    private static function decimalText(Money $money): string
    {
        return str_replace('.', ',', $money->toMajor());
    }

    /**
     * Whether the CZK field is on the form: the Admin switched the manual figure on, or the day has no list. A
     * row kept at its own rate asks for nothing while its amount, date and currency are unchanged, even in a
     * currency without any CNB list.
     */
    private static function czkAmountNeeded(Get $get, ?Transaction $record): bool
    {
        if (! self::isForeign($get('currency'))) {
            return false;
        }

        if ($get('czk_manual') === true) {
            return true;
        }

        return ! self::customKept($get, $record) && self::preview($get)['kind'] === 'missing';
    }

    /**
     * Whether the record is booked at an own rate and the form still holds its stored amount, date and currency:
     * saving then keeps the own rate (owner decision A1 on D-06). Changing any of the three computes the figure
     * again at the CNB rate, which is what the Action does when the UI sends `czk_manual` false.
     */
    private static function customKept(Get $get, ?Transaction $record): bool
    {
        if (! $record instanceof Transaction || $record->czk_source !== CzkSource::Custom) {
            return false;
        }

        $currency = self::currencyOf($get('currency'));
        $text = $get('amount');

        if ($currency !== $record->amount_currency || ! is_string($text)) {
            return false;
        }

        if (PragueDay::day($get('transaction_date')) !== $record->transaction_date->toDateString()) {
            return false;
        }

        try {
            $amount = Money::fromMajor((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text), $currency);
        } catch (InvalidArgumentException|OverflowException) {
            return false;
        }

        return $amount->equals($record->amount);
    }

    /**
     * The note under the amount: the stored own rate of a kept custom row (kind `custom`), otherwise the CNB
     * preview of the current input.
     *
     * @return array{kind: 'none'|'cnb'|'missing'|'custom', text: string, czk: string|null}
     */
    private static function figureNote(Get $get, ?Transaction $record): array
    {
        if ($record instanceof Transaction && $get('czk_manual') !== true && self::customKept($get, $record)) {
            return [
                'kind' => 'custom',
                'text' => (string) __('finance.transactions.hints.czk_custom_kept', [
                    'amount' => $record->amount->format('cs'),
                    'czk' => $record->czk()->format('cs'),
                    'rate' => RateFormat::display((string) $record->exchange_rate),
                    'unit' => (string) $record->exchange_unit_amount,
                    'currency' => $record->amount_currency,
                ]),
                'czk' => self::decimalText($record->czk()),
            ];
        }

        return self::preview($get);
    }

    private static function isForeign(mixed $currency): bool
    {
        $code = self::currencyOf($currency);

        return $code !== null && $code !== 'CZK';
    }

    private static function currencyOf(mixed $state): ?string
    {
        return is_string($state) && preg_match('/^[A-Za-z]{3}$/D', $state) === 1 ? strtoupper($state) : null;
    }

    private static function missingRateText(string $currency, string $day): string
    {
        return (string) __('finance.transactions.hints.czk_missing_rate', [
            'currency' => $currency,
            'date' => self::dayText($day),
        ]);
    }

    private static function dayText(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day)?->format(LocalisationServiceProvider::DATE_FORMAT) ?? $day;
    }

    /**
     * Switching the manual figure on starts from the figure the list gives (the stored figure of a row kept at
     * its own rate), so the Admin corrects it to the bank's number instead of typing it from nothing.
     */
    private static function manualSwitched(Get $get, Set $set, ?Transaction $record, bool $on): void
    {
        $typed = $get('czk_amount');

        if ($on && (! is_string($typed) || trim($typed) === '')) {
            // A kept own-rate row starts from its stored figure, any other from the figure the list gives.
            $set('czk_amount', $record instanceof Transaction && self::customKept($get, $record)
                ? self::decimalText($record->czk())
                : self::preview($get)['czk']);
        }
    }

    /**
     * A category of the other type is cleared when the type changes.
     */
    private static function typeChanged(Get $get, Set $set): void
    {
        $type = self::typeOf($get('type'));
        $id = self::id($get('category_id'));

        if ($id === null || ! $type instanceof TransactionType) {
            return;
        }

        if (! TransactionCategory::query()->whereKey($id)->where('type', $type->value)->exists()) {
            $set('category_id', null);
        }
    }

    /**
     * A project of another client is cleared when the client changes.
     */
    private static function clientChanged(Get $get, Set $set): void
    {
        $projectId = self::id($get('project_id'));

        if ($projectId === null) {
            return;
        }

        $clientId = self::id($get('client_id'));

        if ($clientId === null || ! Project::query()->withTrashed()->whereKey($projectId)->where('client_id', $clientId)->exists()) {
            $set('project_id', null);
        }
    }

    private static function typeOf(mixed $state): ?TransactionType
    {
        return match (true) {
            $state instanceof TransactionType => $state,
            is_string($state) => TransactionType::tryFrom($state),
            default => null,
        };
    }

    private static function id(mixed $state): ?string
    {
        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * The categories a new transaction of the type may take, "Parent › Child", in tree order. The category the
     * transaction being edited already has stays offered even when it was archived later.
     *
     * @return array<string, string>
     */
    private static function categoryOptions(?TransactionType $type, ?Transaction $record, mixed $current): array
    {
        $options = [];

        if ($type instanceof TransactionType) {
            $categories = TransactionCategory::query()->selectable($type)->with('parent')->get()
                ->sortBy([
                    static fn (TransactionCategory $category): int => $category->parent->position ?? $category->position,
                    static fn (TransactionCategory $category): string => $category->parent_id ?? $category->id,
                    static fn (TransactionCategory $category): int => $category->depth,
                    static fn (TransactionCategory $category): int => $category->position,
                ]);

            foreach ($categories as $category) {
                $options[$category->id] = self::categoryLabel($category);
            }
        }

        $stored = $record?->category_id;

        if ($stored !== null && $current === $stored && $type === $record->type && ! array_key_exists($stored, $options)) {
            $options[$stored] = self::categoryLabel($record->category);
        }

        return $options;
    }

    /**
     * CZK and every currency with a stored CNB row (the Action refuses any other), and the currency the
     * transaction being edited already has.
     *
     * @return array<string, string>
     */
    private static function currencyChoices(?Transaction $record): array
    {
        $codes = TransactionInput::currencyOptions();

        if ($record instanceof Transaction && ! in_array($record->amount_currency, $codes, true)) {
            $codes[] = $record->amount_currency;
        }

        $options = [];

        foreach ($codes as $code) {
            $name = RateFormat::currencyName($code);
            $options[$code] = $name === null ? $code : $code.' · '.$name;
        }

        return $options;
    }

    /**
     * The clients a transaction may be tied to: not archived, in Czech order; the stored client stays offered.
     *
     * @return array<string, string>
     */
    private static function clientOptions(?Transaction $record, mixed $current): array
    {
        $options = [];

        foreach (CzechCollation::orderBy(Client::query(), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name']) as $client) {
            $options[$client->id] = $client->name;
        }

        $stored = $record?->client_id;

        if ($stored !== null && $current === $stored && ! array_key_exists($stored, $options)) {
            $name = Client::query()->withTrashed()->whereKey($stored)->value('name');

            if (is_string($name)) {
                $options[$stored] = $name;
            }
        }

        return $options;
    }

    /**
     * The selectable projects of the chosen client, `KEY · name`; none without a client. The stored project
     * stays offered.
     *
     * @return array<string, string>
     */
    private static function projectOptions(?Transaction $record, ?string $clientId, mixed $current): array
    {
        $options = [];

        if ($clientId !== null) {
            $query = Project::query()->selectable()->where('projects.client_id', $clientId);

            foreach (CzechCollation::orderBy($query, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.key', 'projects.name']) as $project) {
                $options[$project->id] = $project->key.' · '.$project->name;
            }
        }

        $stored = $record?->project_id;

        if ($stored !== null && $current === $stored && ! array_key_exists($stored, $options)) {
            $project = Project::query()->withTrashed()->whereKey($stored)->where('client_id', $clientId)->first(['id', 'key', 'name']);

            if ($project instanceof Project) {
                $options[$stored] = $project->key.' · '.$project->name;
            }
        }

        return $options;
    }

    /**
     * Every category, archived ones included, as "Parent › Child".
     *
     * @return array<string, string>
     */
    private static function categoryFilterOptions(): array
    {
        $options = [];

        foreach (TransactionCategory::query()->with('parent')->orderBy('type')->orderBy('depth')->orderBy('position')->get() as $category) {
            $options[$category->id] = self::categoryLabel($category).' ('.$category->type->getLabel().')';
        }

        return $options;
    }

    /**
     * Every client that has a transaction, archived ones included, in Czech order.
     *
     * @return array<string, string>
     */
    private static function clientFilterOptions(): array
    {
        $used = Transaction::query()->whereNotNull('client_id')->select('client_id');

        /** @var array<string, string> */
        return CzechCollation::orderBy(Client::query()->withTrashed()->whereIn('clients.id', $used), 'clients.name')->pluck('clients.name', 'clients.id')->all();
    }

    /**
     * Every project that has a transaction, archived ones included.
     *
     * @return array<string, string>
     */
    private static function projectFilterOptions(): array
    {
        $used = Transaction::query()->whereNotNull('project_id')->select('project_id');
        $options = [];

        foreach (Project::query()->withTrashed()->whereIn('projects.id', $used)->orderBy('key')->get(['id', 'key', 'name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    /**
     * The currencies the stored transactions are written in, and the ones a new transaction can use.
     *
     * @return array<string, string>
     */
    private static function currencyFilterOptions(): array
    {
        /** @var list<string> $stored */
        $stored = Transaction::query()->distinct()->orderBy('amount_currency')->pluck('amount_currency')->all();
        $known = ExchangeRate::query()->distinct()->orderBy('currency')->pluck('currency')->all();
        $codes = array_values(array_unique(['CZK', ...$stored, ...array_map('strval', $known)]));
        sort($codes);

        return array_combine($codes, $codes);
    }

    /**
     * The CZK figure with a "ručně" badge when the Admin typed it and a "vlastní kurz" badge when it was booked
     * at an own rate.
     */
    private static function czkCell(Transaction $record): HtmlString
    {
        $text = e($record->czk()->format('cs'));

        [$label, $color] = match ($record->czk_source) {
            CzkSource::Manual => [__('finance.transactions.badges.manual'), 'warning'],
            CzkSource::Custom => [__('finance.transactions.badges.custom'), 'info'],
            default => [null, null],
        };

        if ($label === null) {
            return new HtmlString($text);
        }

        $badge = Blade::render(
            '<x-filament::badge :color="$color" size="sm">{{ $label }}</x-filament::badge>',
            ['label' => $label, 'color' => $color],
        );

        return new HtmlString($text.' '.$badge);
    }
}
