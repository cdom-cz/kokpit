<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Finance\Actions\ArchiveTransactionCategory;
use App\Domain\Finance\Actions\DeleteTransactionCategory;
use App\Domain\Finance\Actions\MoveTransactionCategory;
use App\Domain\Finance\Actions\SaveTransactionCategory;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\TransactionCategoryResource\Pages\ListTransactionCategories;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Admin's "Kategorie" screen (FI-01, D-15, D-16): the two-level tree of income and expense categories on
 * one list, with create and edit in modals. There are no record routes.
 *
 * Every write goes through a domain Action, which authorizes the Admin again; the screen never touches the
 * model. The list shows a parent directly above its children, so the order is the tree order and the table has
 * no sortable column (reordering is "move up" and "move down", Task 2).
 */
#[AccessRule(Audience::AdminOnly, reason: 'The categories of the finance module belong to the Admin; a Partner never sees finance.')]
final class TransactionCategoryResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = TransactionCategory::class;

    protected static ?string $slug = 'transaction-categories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 62;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('finance.categories.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedTag;
    }

    public static function getModelLabel(): string
    {
        return __('finance.categories.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('finance.categories.plural_label');
    }

    /**
     * The parent is read on every row (the "Nadřazená kategorie" column), and the children are counted once.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent')->withCount('children');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactionCategories::route('/'),
        ];
    }

    /**
     * The roots a category can be put under: active roots of the type, offered by name. Without a type there
     * is nothing to choose from. The category being edited is never offered as its own parent.
     *
     * @return array<string, string>
     */
    public static function parentOptions(?string $type, ?string $exceptId = null): array
    {
        if (TransactionType::tryFrom((string) $type) === null) {
            return [];
        }

        $query = TransactionCategory::query()
            ->where('type', $type)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('name');

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * The fields of the create and edit modals. The type and the parent are locked where the rules lock them:
     * a child has the type of its parent, the system category and a category with children keep both.
     *
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('finance.categories.fields.name'))
                ->required()
                ->maxLength(120),
            Select::make('type')
                ->label(__('finance.categories.fields.type'))
                ->options(TransactionType::class)
                ->required(static fn (Get $get): bool => blank($get('parent_id')))
                ->live()
                ->disabled(static fn (Get $get, ?Model $record): bool => filled($get('parent_id')) || self::lockedStructure($record))
                // Sent even while a parent locks the field: the screen has set it to the type of the parent.
                ->dehydrated(static fn (?Model $record): bool => ! self::lockedStructure($record)),
            Select::make('parent_id')
                ->label(__('finance.categories.fields.parent'))
                ->helperText(__('finance.categories.hints.parent'))
                ->placeholder(__('finance.categories.placeholders.parent'))
                ->options(static fn (Get $get, ?Model $record): array => self::parentOptions(
                    self::typeValue($get('type')),
                    $record?->getKey() === null ? null : (string) $record->getKey(),
                ))
                ->live()
                ->afterStateUpdated(static function (Get $get, Set $set): void {
                    $parentId = $get('parent_id');

                    if (is_string($parentId) && $parentId !== '') {
                        $parent = TransactionCategory::query()->find($parentId);

                        if ($parent !== null) {
                            $set('type', $parent->type->value);
                        }
                    }
                })
                ->disabled(static fn (?Model $record): bool => self::lockedStructure($record))
                ->dehydrated(static fn (?Model $record): bool => ! self::lockedStructure($record)),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('finance.categories.columns.name'))
                    ->weight(static fn (TransactionCategory $record): ?string => $record->depth === 0 ? 'semibold' : null)
                    ->extraAttributes(static fn (TransactionCategory $record): array => $record->depth === 1
                        ? ['style' => 'padding-inline-start: 1.5rem;']
                        : []),
                TextColumn::make('type')
                    ->label(__('finance.categories.columns.type'))
                    ->badge(),
                TextColumn::make('parent.name')
                    ->label(__('finance.categories.columns.parent'))
                    ->placeholder(__('finance.categories.placeholders.empty_value')),
                TextColumn::make('state')
                    ->label(__('finance.categories.columns.state'))
                    ->state(static fn (TransactionCategory $record): array => self::badges($record))
                    ->badge()
                    ->color(static fn (string $state): string => $state === __('finance.categories.badges.system') ? 'info' : 'gray'),
            ])
            ->defaultSort(static fn (Builder $query): Builder => self::treeOrder($query))
            ->paginated(false)
            ->filters([
                SelectFilter::make('type')
                    ->label(__('finance.categories.filters.type'))
                    ->options(TransactionType::class),
                TernaryFilter::make('archived')
                    ->label(__('finance.categories.filters.archived'))
                    ->placeholder(__('finance.categories.filters.archived_all'))
                    ->trueLabel(__('finance.categories.filters.archived_only'))
                    ->falseLabel(__('finance.categories.filters.archived_active'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNotNull('transaction_categories.archived_at'),
                        false: static fn (Builder $query): Builder => $query->whereNull('transaction_categories.archived_at'),
                        blank: static fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('finance.categories.actions.edit'))
                    ->modalHeading(__('finance.categories.actions.edit_heading'))
                    ->successNotificationTitle(__('finance.categories.notifications.updated'))
                    ->schema(self::fields())
                    ->using(static function (Model $record, array $data): Model {
                        assert($record instanceof TransactionCategory);

                        try {
                            return app(SaveTransactionCategory::class)->handle(self::actor(), $record, $data);
                        } catch (ValidationException $e) {
                            throw self::underModal($e);
                        }
                    }),
                Action::make('moveUp')
                    ->label(__('finance.categories.actions.move_up'))
                    ->tooltip(__('finance.categories.actions.move_up'))
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->iconButton()
                    ->action(static function (Model $record): void {
                        assert($record instanceof TransactionCategory);

                        app(MoveTransactionCategory::class)->handle(self::actor(), $record, MoveTransactionCategory::UP);
                    }),
                Action::make('moveDown')
                    ->label(__('finance.categories.actions.move_down'))
                    ->tooltip(__('finance.categories.actions.move_down'))
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->iconButton()
                    ->action(static function (Model $record): void {
                        assert($record instanceof TransactionCategory);

                        app(MoveTransactionCategory::class)->handle(self::actor(), $record, MoveTransactionCategory::DOWN);
                    }),
                // The system category is renamed and moved only: the database refuses the rest, so the
                // screen does not offer archive or delete for it.
                Action::make('archive')
                    ->label(__('finance.categories.actions.archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('finance.categories.actions.archive_heading'))
                    ->modalDescription(__('finance.categories.actions.archive_description'))
                    ->successNotificationTitle(__('finance.categories.notifications.archived'))
                    ->hidden(static fn (Model $record): bool => $record instanceof TransactionCategory && ($record->is_system || $record->isArchived()))
                    ->action(static fn (Model $record, Action $action) => self::archive($record, true, $action)),
                Action::make('restore')
                    ->label(__('finance.categories.actions.restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->successNotificationTitle(__('finance.categories.notifications.restored'))
                    ->visible(static fn (Model $record): bool => $record instanceof TransactionCategory && ! $record->is_system && $record->isArchived())
                    ->action(static fn (Model $record, Action $action) => self::archive($record, false, $action)),
                DeleteAction::make()
                    ->label(__('finance.categories.actions.delete'))
                    ->modalHeading(__('finance.categories.actions.delete_heading'))
                    ->modalDescription(__('finance.categories.actions.delete_description'))
                    ->successNotificationTitle(__('finance.categories.notifications.deleted'))
                    ->hidden(static fn (Model $record): bool => $record instanceof TransactionCategory && $record->is_system)
                    ->using(static function (Model $record, DeleteAction $action): bool {
                        assert($record instanceof TransactionCategory);

                        try {
                            app(DeleteTransactionCategory::class)->handle(self::actor(), $record);
                        } catch (DomainException $e) {
                            // A used category stays: say why in a danger notification and suggest archiving.
                            $action->failureNotificationTitle($e->getMessage());

                            return false;
                        }

                        return true;
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading(__('finance.categories.empty.heading'))
            ->emptyStateDescription(__('finance.categories.empty.description'));
    }

    /**
     * Archives or restores a row; the system category is refused by the Action and shown as a danger notification.
     */
    private static function archive(Model $record, bool $archive, Action $action): void
    {
        assert($record instanceof TransactionCategory);

        try {
            app(ArchiveTransactionCategory::class)->handle(self::actor(), $record, $archive);
        } catch (DomainException $e) {
            $action->failureNotificationTitle($e->getMessage());
            $action->failure();

            return;
        }

        $action->success();
    }

    /**
     * The signed-in user the Actions authorize; the screen is Admin-only, so there always is one.
     */
    public static function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('The category screen needs a signed-in user.');
        }

        return $user;
    }

    /**
     * The Actions report plain data keys; the modal form lives under the action state path.
     */
    public static function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $mapped["mountedActions.0.data.{$key}"] = $messages;
        }

        return ValidationException::withMessages($mapped);
    }

    /**
     * The tree order: type, then the root (a child follows its parent), then depth, position and the Czech name.
     *
     * @param  Builder<TransactionCategory>  $query
     * @return Builder<TransactionCategory>
     */
    private static function treeOrder(Builder $query): Builder
    {
        $table = 'transaction_categories';
        $rootPosition = "coalesce((select p.position from {$table} as p where p.id = {$table}.parent_id), {$table}.position)";
        $rootId = "coalesce({$table}.parent_id, {$table}.id)";

        $query
            ->orderBy("{$table}.type")
            ->orderByRaw($rootPosition)
            ->orderByRaw($rootId)
            ->orderBy("{$table}.depth")
            ->orderBy("{$table}.position");

        CzechCollation::orderBy($query, "{$table}.name");

        return $query->orderBy("{$table}.id");
    }

    /**
     * The state badges of a row: system first, then archived.
     *
     * @return list<string>
     */
    private static function badges(TransactionCategory $record): array
    {
        $badges = [];

        if ($record->is_system) {
            $badges[] = __('finance.categories.badges.system');
        }

        if ($record->isArchived()) {
            $badges[] = __('finance.categories.badges.archived');
        }

        return $badges;
    }

    /**
     * Whether the type and the parent of a stored category are fixed: the system category, and a category
     * with children. A new record (create modal) is never locked.
     */
    private static function lockedStructure(?Model $record): bool
    {
        if (! $record instanceof TransactionCategory) {
            return false;
        }

        if ($record->is_system) {
            return true;
        }

        $children = $record->getAttributes()['children_count'] ?? null;

        return is_numeric($children) ? (int) $children > 0 : $record->children()->exists();
    }

    private static function typeValue(mixed $state): ?string
    {
        return match (true) {
            $state instanceof TransactionType => $state->value,
            is_string($state) => $state,
            default => null,
        };
    }
}
