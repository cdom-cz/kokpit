<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Enums\DocumentTypeGroup;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageByClient;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageStats;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\DocumentColumns;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The Admin's central "Soubory" listing (DO-02, D-07): every stored file of every owner, and the files that
 * hang on nothing, in one table with the owner and its link, the client, the size, the type, the uploader, the
 * date and the flag. A list page only: a file is created by an upload action, never by a form, and has no
 * page of its own, so there is no create, edit or view route. Files are not globally searchable (D-04): a
 * file name is exactly what a Partner must never be able to probe.
 *
 * The rows are the Admin's whole `documents` table. The table is read through the model, whose Partner scope
 * leaves a Partner no row; the resource rule above keeps a Partner from opening the page at all.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The central listing shows the files of every client; only the Admin sees it, and a Partner reads files only on the own tasks and projects.')]
final class DocumentResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'documents';

    protected static ?int $navigationSort = 55;

    protected static bool $isGloballySearchable = false;

    /** The value of the owner-type filter that selects the files without an owner. */
    private const string UNATTACHED = 'unattached';

    public static function getNavigationLabel(): string
    {
        return __('documents.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedDocumentText;
    }

    public static function getModelLabel(): string
    {
        return __('documents.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documents.plural_label');
    }

    /**
     * Files are uploaded by an action of the page, not created through a form.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The owner, the client and the uploader are loaded with the rows, archived ones included, so a file of an
     * archived project, task or client still names where it hangs (research Pitfall 7) and no row costs a query.
     *
     * @return Builder<Document>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Document> $query */
        $query = parent::getEloquentQuery();

        return DocumentColumns::withOwners($query)
            ->with(['client' => static fn ($client) => $client->withTrashed(), 'uploader']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc($query->qualifyColumn('created_at'))->orderByDesc($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100])
            ->filters(self::filters())
            ->filtersFormColumns(2)
            ->recordUrl(null)
            ->recordActions([
                DocumentActions::preview(),
                DocumentActions::download(),
                DocumentActions::rename(),
                DocumentActions::toggleVisibility(),
                DocumentActions::delete(),
            ])
            ->toolbarActions([
                BulkActionGroup::make(DocumentActions::bulkActions()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('documents.listing_empty.heading'))
            ->emptyStateDescription(__('documents.listing_empty.description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }

    /**
     * The storage totals shown above the listing (D-11); the list page places them.
     *
     * @return array<class-string>
     */
    public static function getWidgets(): array
    {
        return [
            DocumentStorageStats::class,
            DocumentStorageByClient::class,
        ];
    }

    /**
     * The columns of the listing, in the order of D-07. The shared columns come from DocumentColumns (name with
     * its Czech order and search, owner, size, uploader, date, flag); this listing adds the owner link, the
     * client and the MIME type, and makes every column sortable.
     *
     * @return list<Column>
     */
    private static function columns(): array
    {
        $shared = [];

        foreach (DocumentColumns::admin(showOwner: true) as $column) {
            $shared[$column->getName()] = $column;
        }

        return [
            $shared['name'],
            $shared['owner']
                ->url(static fn (Document $record): ?string => self::ownerUrl($record))
                ->sortable(query: static fn (Builder $query, string $direction): Builder => $query
                    ->orderBy($query->qualifyColumn('owner_type'), $direction === 'desc' ? 'desc' : 'asc')
                    ->orderBy($query->qualifyColumn('id'))),
            TextColumn::make('client.name')
                ->label(__('documents.columns.client'))
                ->placeholder('–')
                ->sortable(query: static fn (Builder $query, string $direction): Builder => self::orderByJoined($query, 'clients', 'client_id', $direction)),
            $shared['size_bytes'],
            TextColumn::make('mime_type')
                ->label(__('documents.columns.mime_type'))
                ->sortable(),
            $shared['uploader.name']
                ->sortable(query: static fn (Builder $query, string $direction): Builder => self::orderByJoined($query, 'users', 'uploaded_by_id', $direction)),
            $shared['created_at'],
            $shared['visible_to_client']
                ->sortable(),
        ];
    }

    /**
     * The filters of the listing (D-07, D-21), combined with AND. A value the Admin did not pick from the
     * options (a forged one) narrows nothing, so it can neither raise an error nor hide a file: the queries
     * below accept only a known owner kind, a real key, a real calendar day, a known group and a known flag.
     * Only the archived-owners filter starts on a value: the files of active owners.
     *
     * @return list<Filter|SelectFilter|TernaryFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('owner_type')
                ->label(__('documents.filters.owner_type'))
                ->options(self::ownerTypeOptions())
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if ($value === self::UNATTACHED) {
                        return $query->whereNull($query->qualifyColumn('owner_type'));
                    }

                    $type = is_string($value) ? DocumentOwnerType::tryFrom($value) : null;

                    return $type === null ? $query : $query->where($query->qualifyColumn('owner_type'), $type->value);
                }),
            SelectFilter::make('client_id')
                ->label(__('documents.filters.client'))
                ->options(static fn (): array => self::clientOptions())
                ->searchable()
                ->query(static fn (Builder $query, array $data): Builder => self::whereKeyIs($query, 'client_id', $data['value'] ?? null)),
            SelectFilter::make('project_id')
                ->label(__('documents.filters.project'))
                ->options(static fn (): array => self::projectOptions())
                ->searchable()
                ->query(static fn (Builder $query, array $data): Builder => self::whereKeyIs($query, 'project_id', $data['value'] ?? null)),
            Filter::make('created')
                ->label(__('documents.filters.created'))
                ->schema([
                    DatePicker::make('from')->label(__('documents.filters.created_from')),
                    DatePicker::make('until')->label(__('documents.filters.created_until')),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);

                    // Half-open range on the UTC instants of the Prague day boundaries, so both days are whole days.
                    return $query
                        ->when($from !== null, static fn (Builder $query): Builder => $query->whereRaw('documents.created_at >= ?::timestamptz', [PragueDay::start((string) $from)->format('Y-m-d H:i:sP')]))
                        ->when($until !== null, static fn (Builder $query): Builder => $query->whereRaw('documents.created_at < ?::timestamptz', [PragueDay::endExclusive((string) $until)->format('Y-m-d H:i:sP')]));
                })
                ->indicateUsing(static function (array $data): array {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);
                    $label = __('documents.filters.created');

                    return array_values(array_filter([
                        $from === null ? null : $label.' '.mb_strtolower(__('documents.filters.created_from')).': '.self::dayText($from),
                        $until === null ? null : $label.' '.mb_strtolower(__('documents.filters.created_until')).': '.self::dayText($until),
                    ]));
                }),
            SelectFilter::make('type_group')
                ->label(__('documents.filters.type_group'))
                ->options(DocumentTypeGroup::class)
                ->query(static function (Builder $query, array $data): Builder {
                    $group = is_string($data['value'] ?? null) ? DocumentTypeGroup::tryFrom($data['value']) : null;

                    return $group === null ? $query : $group->constrain($query);
                }),
            TernaryFilter::make('visible_to_client')
                ->label(__('documents.filters.visibility'))
                ->trueLabel(__('documents.filters.visibility_visible'))
                ->falseLabel(__('documents.filters.visibility_hidden'))
                ->placeholder(__('documents.filters.visibility_all'))
                ->query(static function (Builder $query, array $data): Builder {
                    $visible = self::ternary($data['value'] ?? null);

                    return $visible === null ? $query : $query->where($query->qualifyColumn('visible_to_client'), $visible);
                }),
            // D-21: the files of archived clients, projects and tasks leave the default listing; the rule is in the
            // Document scopes, this filter only chooses. The blank option shows every file.
            TernaryFilter::make('archived_owners')
                ->label(__('documents.filters.archived_owners'))
                ->trueLabel(__('documents.filters.archived_owners_only'))
                ->falseLabel(__('documents.filters.archived_owners_without'))
                ->placeholder(__('documents.filters.archived_owners_all'))
                ->default(false)
                ->query(static fn (Builder $query, array $data): Builder => self::archivedOwners($query, self::ternary($data['value'] ?? null))),
        ];
    }

    /**
     * The archived-owners choice (D-21): false is the files of active owners, true only the files of archived
     * owners, null every file. The rule is in the Document scopes.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private static function archivedOwners(Builder $query, ?bool $archived): Builder
    {
        return match ($archived) {
            true => $query->onlyArchivedOwners(),
            false => $query->withoutArchivedOwners(),
            null => $query,
        };
    }

    /**
     * What a ternary filter was set to: true, false, or null for the blank option and for anything that is
     * not one of the two (a forged value shows every file).
     */
    private static function ternary(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    /**
     * Narrows to the rows whose key column equals the picked key; anything but a UUID narrows nothing.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private static function whereKeyIs(Builder $query, string $column, mixed $value): Builder
    {
        return is_string($value) && Str::isUuid($value) ? $query->where($query->qualifyColumn($column), $value) : $query;
    }

    /**
     * @return array<string, string>
     */
    private static function ownerTypeOptions(): array
    {
        $options = [];

        foreach (DocumentOwnerType::cases() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        $options[self::UNATTACHED] = __('documents.filters.unattached');

        return $options;
    }

    /**
     * Every client, archived ones included: an archived client still owns files (D-07). Czech order.
     *
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        /** @var array<string, string> */
        return CzechCollation::orderBy(Client::query()->withTrashed(), 'name')->pluck('name', 'id')->all();
    }

    /**
     * Every project, archived ones included, as "KEY · name" by key.
     *
     * @return array<string, string>
     */
    private static function projectOptions(): array
    {
        $options = [];

        foreach (Project::query()->withTrashed()->orderBy('key')->get(['id', 'key', 'name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    private static function dayText(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day)?->format(LocalisationServiceProvider::DATE_FORMAT) ?? $day;
    }

    /**
     * The page of the owner in the Admin panel: the client, project and task pages, the page of the task for a
     * comment, the edit page of a transaction. Null for an unattached file. Archived owners keep their pages.
     */
    private static function ownerUrl(Document $document): ?string
    {
        $owner = $document->owner;

        return match (true) {
            $owner instanceof Client => ClientResource::getUrl('view', ['record' => $owner]),
            $owner instanceof Project => ProjectResource::getUrl('view', ['record' => $owner]),
            $owner instanceof Task => TaskResource::getUrl('view', ['record' => $owner]),
            $owner instanceof TaskComment => $owner->task === null ? null : TaskResource::getUrl('view', ['record' => $owner->task]),
            $owner instanceof Transaction => TransactionResource::getUrl('edit', ['record' => $owner]),
            default => null,
        };
    }

    /**
     * Sorts by the name of a related row (the client, the uploader) in Czech order, without loading it: the
     * table is joined under an alias for the order only, archived rows included, and the file columns stay the
     * only ones selected.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private static function orderByJoined(Builder $query, string $table, string $foreignKey, string $direction): Builder
    {
        $alias = 'sort_'.$table;

        $query->leftJoin("{$table} as {$alias}", "{$alias}.id", '=', $query->qualifyColumn($foreignKey));

        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        CzechCollation::orderBy($query, "{$alias}.name", $direction);

        return $query->orderBy($query->qualifyColumn('id'));
    }
}
