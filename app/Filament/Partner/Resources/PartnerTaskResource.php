<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ListPartnerTasks;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskDocumentsRelationManager;
use App\Filament\Support\TaskColumns;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The "Moje úkoly" screens of a Partner (TA-07, KB-03, D-04, D-13).
 *
 * The query is the scoped `Task` model: only the tasks of the own client's
 * client-visible, non-archived projects exist for a Partner, and an archived task
 * is gone with the default soft delete scope. The table and the infolist are
 * built only from the pinned Partner builders of `TaskColumns`; there is no edit
 * page, no record action, no bulk action, no board, no
 * export and no global search. The create form offers the project, the title and
 * the description and the files and nothing else: status, priority and people are
 * decided by the domain Action CreateTask, the files go to AttachDocuments (D-05).
 * The relations are the comments tab (non-internal comments only) and the files tab. The slug differs from the Admin task resource.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner sees the tasks of the own client\'s client-visible projects read-only and may create one; only Partner-safe fields are shown.')]
final class PartnerTaskResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Task::class;

    protected static ?string $slug = 'my-tasks';

    /** The address of a task is its reference: /admin/my-tasks/KEY-N. */
    protected static ?string $recordRouteKeyName = 'reference';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 20;

    /**
     * The declaration AND the policy AND a Partner with a client. This method
     * replaces the trait's one with a stricter check, so it repeats the trait's
     * two conditions: the Admin must not get a second navigation entry (the
     * Admin task resource serves the Admin).
     */
    public static function canAccess(): bool
    {
        return AccessRules::allows(self::class)
            && parent::canAccess()
            && app(PartnerContext::class)->partnerClientId() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('kokpit.partner_tasks.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.partner_tasks.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.partner_tasks.plural_model_label');
    }

    /**
     * A lower-case key opens the same task: /admin/my-tasks/abc-1 is ABC-1.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        return parent::resolveRecordRouteBinding(is_string($key) ? Str::upper($key) : $key, $modifyQuery);
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Only the visible projects of the own client; the Partner scope decides, not this list.
            Select::make('project_id')
                ->label(__('kokpit.tasks.fields.project'))
                ->options(static fn (): array => self::projectOptions())
                ->searchable()
                ->required()
                ->native(false),
            TextInput::make('title')
                ->label(__('kokpit.tasks.fields.title'))
                ->required()
                ->maxLength(255),
            // Files do not go into the editor: it keeps no attachments (D-22) and the separate `files` field
            // below hands them to AttachDocuments with the new task as owner (D-05).
            // The server cleans the value again (CreateTask), so the editor is only a convenience.
            RichEditor::make('description')
                ->label(__('kokpit.tasks.fields.description'))
                ->fileAttachments(false)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['h2', 'h3'],
                    ['blockquote', 'bulletList', 'orderedList'],
                    ['table'],
                    ['undo', 'redo'],
                ])
                ->helperText(__('kokpit.partner_tasks.hints.description')),
            // Not stored by the field: the temporary objects stay where Livewire put them until the Action has
            // checked, copied and removed them. The limits are the Admin's (D-06).
            FileUpload::make('files')
                ->label(__('documents.fields.files'))
                ->helperText(__('documents.hints.partner_files', [
                    'count' => (int) config('kokpit.documents.max_files_per_upload'),
                    'max' => (int) ((int) config('kokpit.documents.max_size_kb') / 1024),
                ]))
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('kokpit.documents.max_size_kb'))
                ->maxFiles((int) config('kokpit.documents.max_files_per_upload')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['project', 'assignee']))
            ->columns(TaskColumns::partnerColumns())
            // Newest first; the id is the tie-breaker, so rows of one second keep one order.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByDesc($query->qualifyColumn('created_at'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->recordUrl(static fn (Task $record): string => self::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('kokpit.partner_tasks.empty_heading'))
            ->emptyStateDescription(__('kokpit.partner_tasks.empty_description'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(TaskColumns::partnerEntries());
    }

    /**
     * The projects a Partner may raise a task in, `KEY · name`, ordered by key. Finished
     * projects are not offered (D-11).
     *
     * @return array<string, string>
     */
    public static function projectOptions(): array
    {
        $options = [];

        foreach (Project::query()->selectable()->open()->orderBy('key')->orderBy('id')->get(['id', 'key', 'name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    /**
     * The relations of the Partner task page: the non-internal comments and the shared files.
     */
    public static function getRelations(): array
    {
        return [
            PartnerTaskCommentsRelationManager::class,
            PartnerTaskDocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerTasks::route('/'),
            'create' => CreatePartnerTask::route('/create'),
            'view' => ViewPartnerTask::route('/{record}'),
        ];
    }
}
