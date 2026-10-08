<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Support\TaskColumns;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Admin task screens: the list with its quick create modal and the full task
 * page at the stable address /admin/tasks/KEY-N (D-09, TA-02, TA-05).
 *
 * The route key of a task is its reference, so `KEY-N` is the address in the URL;
 * the binding upper-cases the incoming value, so a lower-case key resolves too.
 * Nothing here writes a row: the quick create modal hands the project and the
 * title to the domain Action CreateTask, which owns every rule.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin task screens carry people, billing and internal data; the Partner has its own task list.')]
final class TaskResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    /** The address of a task is its reference: /admin/tasks/KEY-N. */
    protected static ?string $recordRouteKeyName = 'reference';

    protected static ?string $recordTitleAttribute = 'title';

    /** The only searchable resource of the panel (panel resource opt-in); `id` is never searchable. */
    protected static bool $isGloballySearchable = true;

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.tasks.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.tasks.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.tasks.plural_model_label');
    }

    /**
     * A lower-case key opens the same task: /admin/tasks/abc-1 is ABC-1.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        return parent::resolveRecordRouteBinding(is_string($key) ? Str::upper($key) : $key, $modifyQuery);
    }

    /**
     * Searchable by the key and the title, never by the id.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'title'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record instanceof Task ? $record->reference.' · '.$record->title : parent::getGlobalSearchResultTitle($record);
    }

    /**
     * The project key and the status; never a rate, a price or any money.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Task) {
            return [];
        }

        return [
            __('kokpit.tasks.fields.project') => (string) $record->project?->key,
            __('kokpit.tasks.fields.status') => $record->status->getLabel(),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with([
            'project' => static fn ($project) => $project->withoutGlobalScopes([SoftDeletingScope::class]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(TaskColumns::adminEntries());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with([
                // An archived project still names its tasks.
                'project' => static fn ($project) => $project->withoutGlobalScopes([SoftDeletingScope::class]),
                'assignee',
            ]))
            ->columns(TaskColumns::adminColumns())
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(static fn (Task $record): string => self::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('kokpit.tasks.empty_heading'))
            ->emptyStateDescription(__('kokpit.tasks.empty_description'));
    }

    /**
     * The quick creation modal (D-09): the project and the title, nothing else.
     * It is a static builder so the boards reuse it. On success it opens the new
     * task's page; the detail page is where the description, people and dates are
     * filled in.
     */
    public static function quickCreateAction(?Project $project = null): Action
    {
        return Action::make('quickCreate')
            ->label(__('kokpit.tasks.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('kokpit.tasks.actions.create_heading'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.create_submit'))
            ->schema([
                Select::make('project_id')
                    ->label(__('kokpit.tasks.fields.project'))
                    ->options(static fn (): array => self::projectOptions())
                    ->default($project?->getKey())
                    ->searchable()
                    ->required(),
                TextInput::make('title')
                    ->label(__('kokpit.tasks.fields.title'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(self::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    $projectId = is_string($data['project_id'] ?? null) ? $data['project_id'] : '';
                    $chosen = $projectId === '' ? null : Project::query()->withTrashed()->find($projectId);

                    if (! $chosen instanceof Project) {
                        throw ValidationException::withMessages(['project_id' => __('kokpit.tasks.errors.project_unavailable')]);
                    }

                    $task = app(CreateTask::class)->handle($actor, $chosen, ['title' => (string) ($data['title'] ?? '')]);
                } catch (ValidationException $e) {
                    throw self::underModal($e);
                }

                $action->redirect(self::getUrl('view', ['record' => $task]));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
        ];
    }

    /**
     * The projects a task may be created in, `KEY · name`, ordered by key. Archived
     * projects and projects of archived clients are not offered.
     *
     * @return array<string, string>
     */
    private static function projectOptions(): array
    {
        $options = [];

        foreach (Project::query()->selectable()->orderBy('key')->orderBy('id')->get(['id', 'key', 'name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     */
    private static function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $path = "mountedActions.0.data.{$key}";
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
