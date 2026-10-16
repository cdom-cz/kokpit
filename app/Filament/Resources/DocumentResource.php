<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
