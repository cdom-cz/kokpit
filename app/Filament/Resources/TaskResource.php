<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\EstimateHours;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskPeople;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\TaskDocumentsRelationManager;
use App\Filament\RelationManagers\TaskHistoryRelationManager;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\SubtasksRelationManager;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use App\Filament\Support\TaskColumns;
use App\Filament\Support\TaskTimerToggle;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;
use WeakMap;

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

    /** One of the three Admin-only searchable resources (with ClientResource and ProjectResource); found by KEY-N or title, and `id` is never searchable. */
    protected static bool $isGloballySearchable = true;

    protected static ?int $globalSearchSort = 30;

    protected static ?int $navigationSort = 30;

    /**
     * The running task id read for a Livewire component in this request; the key is weak, so the
     * entry dies with the component.
     *
     * @var WeakMap<Component, array{id: string|null}>|null
     */
    private static ?WeakMap $runningTasks = null;

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
     * Archived tasks stay reachable: the trashed filter decides what the list
     * shows, and an archived task opens by its address (TA-02). The Partner scope
     * is a different scope and stays on.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * An archived task is read-only until it is restored.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && ! ($record instanceof Task && $record->trashed());
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

    /**
     * Archived tasks are not suggested. The tasks still being worked on come first and the Done ones after
     * them, the newest first in each part (D-10). The order is part of this base query, so it applies before
     * Filament's limit of 50 results and Done matches can never push an active one out of the group.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $task = new Task;

        return parent::getGlobalSearchEloquentQuery()
            ->whereNull($task->qualifyColumn('deleted_at'))
            ->with([
                'project' => static fn ($project) => $project->withoutGlobalScopes([SoftDeletingScope::class]),
            ])
            // false sorts before true, so the rows that are not Done come first.
            // @phpstan-ignore argument.type (the column is qualified by the model, the value is bound)
            ->orderByRaw('('.$task->qualifyColumn('status').' = ?) ASC', [ProjectStatus::Done->value])
            ->orderByDesc($task->qualifyColumn('created_at'))
            ->orderByDesc($task->qualifyColumn('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('kokpit.tasks.sections.main'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label(__('kokpit.tasks.fields.title'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    // Files go through the Soubory tab and AttachDocuments, never through this editor: inline
                    // editor files would bypass `documents`, its policy and the sanitiser, so the editor keeps
                    // `fileAttachments(false)` on purpose (D-22, research Anti-Patterns). The server cleans the
                    // value again (UpdateTask), so the editor is only a convenience.
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
                        // The Partner accounts of the client read the description of a client-visible project (Pitfall 6).
                        ->helperText(static fn (?Task $record): ?string => self::projectOf($record)?->client_visible === true
                            ? (string) __('kokpit.tasks.hints.description_shared')
                            : null)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.tasks.sections.status'))
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label(__('kokpit.tasks.fields.status'))
                        ->options(ProjectStatus::class)
                        ->required()
                        ->native(false),
                    Select::make('priority')
                        ->label(__('kokpit.tasks.fields.priority'))
                        ->options(ProjectPriority::class)
                        ->required()
                        ->native(false),
                    Checkbox::make('hidden_on_board')
                        ->label(__('kokpit.tasks.fields.hidden_on_board'))
                        ->helperText(__('kokpit.tasks.hints.hidden_on_board'))
                        ->default(false)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.tasks.sections.dates'))
                ->columns(2)
                ->schema([
                    DatePicker::make('start_date')
                        ->label(__('kokpit.tasks.fields.start_date')),
                    DatePicker::make('due_date')
                        ->label(__('kokpit.tasks.fields.due_date'))
                        ->afterOrEqual('start_date'),
                ]),
            Section::make(__('kokpit.tasks.sections.people'))
                ->columns(2)
                ->schema([
                    // The active Admin and the active Partners of the project's client, plus the
                    // field's current person (D-05): UpdateTask keeps an unchanged person even
                    // when deactivated since and re-checks every changed one, so the list must
                    // still contain the stored value or the form would refuse every save.
                    Select::make('assignee_id')
                        ->label(__('kokpit.tasks.fields.assignee'))
                        ->options(static fn (?Task $record): array => self::peopleOptions($record, 'assignee_id'))
                        ->required()
                        ->native(false),
                    Select::make('requester_id')
                        ->label(__('kokpit.tasks.fields.requester'))
                        ->options(static fn (?Task $record): array => self::peopleOptions($record, 'requester_id'))
                        ->required()
                        ->native(false),
                ]),
            // The private todo list of the Admin (TA-03). The repeater is bound to the Admin-only
            // relationship and keeps the order in `position`; a subtask has the same list (D-11).
            Section::make(__('kokpit.tasks.checklist.heading'))
                ->columnSpanFull()
                ->schema([
                    Repeater::make('checklistItems')
                        ->relationship()
                        ->orderColumn('position')
                        ->reorderable()
                        ->hiddenLabel()
                        ->defaultItems(0)
                        ->addActionLabel(__('kokpit.tasks.checklist.add'))
                        ->schema([
                            TextInput::make('text')
                                ->label(__('kokpit.tasks.checklist.text'))
                                ->required()
                                ->maxLength(500),
                            Toggle::make('is_done')
                                ->label(__('kokpit.tasks.checklist.is_done'))
                                ->default(false),
                        ])
                        ->columns(2),
                ]),
            // The Admin-only billing overrides (TA-06, D-13). The section exists on the Admin
            // form only; the values are stored in task_billing, never in the tasks table.
            Section::make(__('kokpit.tasks.sections.billing'))
                ->columns(2)
                ->schema([
                    Select::make('billing_type')
                        ->label(__('kokpit.tasks.fields.billing_type'))
                        ->helperText(__('kokpit.tasks.hints.billing_type'))
                        ->options(TaskBillingType::class)
                        ->default(TaskBillingType::Inherit->value)
                        ->required()
                        ->live()
                        ->native(false),
                    TextInput::make('hourly_rate')
                        ->label(__('kokpit.tasks.fields.hourly_rate'))
                        ->helperText(__('kokpit.tasks.hints.hourly_rate'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d+)?$/')
                        ->suffix(static fn (?Task $record): string => self::clientCurrency($record).' / '.__('kokpit.settings.defaults.per_hour')),
                    TextInput::make('fixed_price')
                        ->label(__('kokpit.tasks.fields.fixed_price'))
                        ->helperText(__('kokpit.tasks.hints.fixed_price'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d+)?$/')
                        ->required(static fn (Get $get): bool => self::enumValue($get('billing_type')) === TaskBillingType::FixedPrice->value)
                        ->suffix(static fn (?Task $record): string => self::clientCurrency($record)),
                    TextInput::make('estimate_hours')
                        ->label(__('kokpit.tasks.fields.estimate_hours'))
                        ->helperText(__('kokpit.tasks.hints.estimate_hours'))
                        ->inputMode('decimal')
                        ->regex('/^\d+([.,]\d{1,2})?$/'),
                    Textarea::make('internal_note')
                        ->label(__('kokpit.tasks.fields.internal_note'))
                        ->helperText(__('kokpit.tasks.hints.internal_note'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.tasks.sections.tags'))
                ->schema([
                    // Dehydrated, so UpdateTask receives the names and stays the one writer.
                    SpatieTagsInput::make('tags')
                        ->label(__('kokpit.tasks.fields.tags'))
                        ->type(TagType::Task->value)
                        ->dehydrated(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ...TaskColumns::adminEntries(),
            // A subtask names its parent and links back to it (D-11).
            TextEntry::make('parent_reference')
                ->label(__('kokpit.tasks.fields.parent'))
                ->state(static fn (Task $record): ?string => self::parentOf($record)?->reference)
                ->url(static function (Task $record): ?string {
                    $parent = self::parentOf($record);

                    return $parent === null ? null : self::getUrl('view', ['record' => $parent]);
                })
                ->visible(static fn (Task $record): bool => $record->parent_id !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withCount(TaskColumns::checklistCounts())->with([
                // An archived project still names its tasks.
                'project' => static fn ($project) => $project->withoutGlobalScopes([SoftDeletingScope::class]),
                'parent' => static fn ($parent) => $parent->withoutGlobalScopes([SoftDeletingScope::class]),
                'assignee',
                'tags',
            ]))
            ->columns(self::columns())
            ->filters([...self::filters(), TrashedFilter::make()])
            ->filtersFormColumns(2)
            // Most pressing first: by priority, then by the nearest due date, a task without one
            // last within its priority. The id is the tie-breaker, so rows with equal keys keep
            // one order across pages; it also stays the last key when a column sort is chosen.
            ->defaultSort(static fn (Builder $query): Builder => TaskColumns::orderByPriority($query)
                // @phpstan-ignore argument.type (a column qualified by the model, never input)
                ->orderByRaw($query->qualifyColumn('due_date').' ASC NULLS LAST')
                ->orderByDesc($query->qualifyColumn('id')))
            ->recordActions([
                // One click, outside any group; it turns into the stop icon on the running task (TI-01).
                self::timerAction()->iconButton(),
                ViewAction::make(),
                self::archiveAction(),
                self::restoreAction(),
            ])
            ->recordUrl(static fn (Task $record): string => self::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('kokpit.tasks.empty_heading'))
            ->emptyStateDescription(__('kokpit.tasks.empty_description'));
    }

    /**
     * The Admin list columns, with the parent reference of a subtask next to the
     * task's own reference.
     *
     * @return list<Column>
     */
    private static function columns(): array
    {
        $columns = TaskColumns::adminColumns();

        array_splice($columns, 1, 0, [
            TextColumn::make('parent.reference')
                ->label(__('kokpit.tasks.fields.parent'))
                ->placeholder(__('kokpit.tasks.empty_value')),
        ]);

        return $columns;
    }

    /**
     * Archives the task through the domain Action (A9). A parent that still has
     * active subtasks stays and the Admin is told why. A soft delete only: there
     * is no hard delete of a task anywhere, so a task number stays valid forever.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('kokpit.tasks.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('kokpit.tasks.actions.archive_heading'))
            ->modalDescription(__('kokpit.tasks.actions.archive_description'))
            ->successNotificationTitle(__('kokpit.tasks.notifications.archived'))
            ->visible(static fn (?Model $record): bool => $record instanceof Task && ! $record->trashed())
            ->action(static function (Model $record, Action $action): void {
                assert($record instanceof Task);

                self::runLifecycle($action, static function (User $actor) use ($record): void {
                    app(ArchiveTask::class)->handle($actor, $record);
                });

                $record->refresh();
            });
    }

    /**
     * Restores an archived task through the domain Action, back to the end of
     * its status column (Pitfall 2).
     */
    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label(__('kokpit.tasks.actions.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->successNotificationTitle(__('kokpit.tasks.notifications.restored'))
            ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->trashed())
            ->action(static function (Model $record, Action $action): void {
                assert($record instanceof Task);

                self::runLifecycle($action, static function (User $actor) use ($record): void {
                    app(RestoreTask::class)->handle($actor, $record);
                });

                $record->refresh();
            });
    }

    /**
     * Starts the timer for the task, or stops it while this task is the running one (TI-01, D-01).
     *
     * One click on the task page, the edit page and the list row. The task id is the only input of
     * StartTimer, so the project, the client and the billable default (D-03) come from the task, and
     * a running timer is stopped and kept (D-02). Hidden for an archived task. The label, icon and
     * colour follow whether the record is the running task of the signed-in user, read once per
     * request and surface. The start and the stop themselves are StartTimer and StopTimer, called
     * through TaskTimerToggle, which every task surface shares. The other timer surfaces refresh on
     * the event.
     */
    public static function timerAction(): Action
    {
        $running = static fn (?Model $record, Component $livewire): bool => $record instanceof Task
            && self::runningTaskId($livewire) === $record->getKey();

        return Action::make('toggleTimer')
            ->label(static fn (?Model $record, Component $livewire): string => (string) __($running($record, $livewire) ? 'kokpit.time.timer.stop' : 'kokpit.time.timer.start'))
            ->tooltip(static fn (?Model $record, Component $livewire): string => (string) __($running($record, $livewire) ? 'kokpit.time.timer.stop' : 'kokpit.time.timer.start'))
            ->icon(static fn (?Model $record, Component $livewire): Heroicon => $running($record, $livewire) ? Heroicon::OutlinedStop : Heroicon::OutlinedPlay)
            ->color(static fn (?Model $record, Component $livewire): string => $running($record, $livewire) ? 'warning' : 'gray')
            ->visible(static fn (?Model $record): bool => $record instanceof Task && ! $record->trashed())
            ->action(static function (Model $record, Component $livewire): void {
                abort_unless(self::canAccess(), 403);
                assert($record instanceof Task);

                $actor = auth()->user();
                assert($actor instanceof User);

                $event = app(TaskTimerToggle::class)->handle($actor, $record);

                // The running task changed (or was found stale): read it again on the next render.
                self::forgetRunningTask($livewire);

                if ($event !== null) {
                    $livewire->dispatch($event);
                }
            });
    }

    /**
     * The id of the signed-in user's running task, one query per request and component however
     * many rows and actions ask.
     */
    private static function runningTaskId(Component $livewire): ?string
    {
        $cache = self::$runningTasks ??= new WeakMap;

        if (! isset($cache[$livewire])) {
            $actor = auth()->user();

            $cache[$livewire] = ['id' => $actor instanceof User ? app(TaskTimerToggle::class)->runningTaskId($actor) : null];
        }

        return $cache[$livewire]['id'];
    }

    private static function forgetRunningTask(Component $livewire): void
    {
        if (self::$runningTasks instanceof WeakMap) {
            unset(self::$runningTasks[$livewire]);
        }
    }

    /**
     * Runs a lifecycle Action as the signed-in Admin and reports the outcome: a
     * refusal of the Action is shown as the failure message instead of a field error.
     *
     * @param  Closure(User): void  $run
     */
    private static function runLifecycle(Action $action, Closure $run): void
    {
        abort_unless(self::canAccess(), 403);

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            $run($actor);
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title((string) collect($e->errors())->flatten()->first())
                ->send();

            return;
        }

        $action->success();
    }

    /**
     * The quick creation modal (D-09): the project and the title, nothing else.
     * It is a static builder so the boards and the project view reuse it. On
     * success it opens the new task's edit page, where the description, people,
     * dates and tags are filled in (D-09).
     *
     * Unlocked (the list and the boards), the project picker offers only projects
     * that are not Done (D-11) and starts with the given project unless it is Done.
     * Locked (the project view, D-07), the picker holds only the given project and
     * is disabled; a disabled field is not submitted, so the project is read fresh
     * from the record and the submitted data never decides it.
     */
    public static function quickCreateAction(?Project $project = null, bool $lockProject = false): Action
    {
        $lockedProject = $lockProject ? $project : null;

        $projectField = Select::make('project_id')
            ->label(__('kokpit.tasks.fields.project'))
            ->searchable();

        if ($lockedProject instanceof Project) {
            $projectField
                ->options([$lockedProject->getKey() => $lockedProject->key.' · '.$lockedProject->name])
                ->default($lockedProject->getKey())
                ->disabled()
                ->dehydrated(false)
                ->helperText(__('kokpit.tasks.hints.project_locked'));
        } else {
            $projectField
                ->options(static fn (): array => self::projectOptions(openOnly: true))
                ->default($project instanceof Project && $project->status !== ProjectStatus::Done ? $project->getKey() : null)
                ->required();
        }

        return Action::make('quickCreate')
            ->label(__('kokpit.tasks.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('kokpit.tasks.actions.create_heading'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.create_submit'))
            ->schema([
                $projectField,
                TextInput::make('title')
                    ->label(__('kokpit.tasks.fields.title'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action) use ($lockedProject): void {
                abort_unless(self::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    if ($lockedProject instanceof Project) {
                        // The locked project comes from the record, never from the (disabled) field.
                        $chosen = Project::query()->withTrashed()->find($lockedProject->getKey());
                    } else {
                        $projectId = is_string($data['project_id'] ?? null) ? $data['project_id'] : '';
                        $chosen = $projectId === '' ? null : Project::query()->withTrashed()->find($projectId);
                    }

                    if (! $chosen instanceof Project) {
                        throw ValidationException::withMessages(['project_id' => __('kokpit.tasks.errors.project_unavailable')]);
                    }

                    $task = app(CreateTask::class)->handle($actor, $chosen, ['title' => (string) ($data['title'] ?? '')]);
                } catch (ValidationException $e) {
                    // A disabled field cannot show an inline error, so a project refusal is a toast.
                    if ($lockedProject instanceof Project && array_key_exists('project_id', $e->errors())) {
                        Notification::make()
                            ->danger()
                            ->title((string) collect($e->errors())->flatten()->first())
                            ->send();

                        $action->halt();

                        return;
                    }

                    throw self::underModal($e);
                }

                $action->redirect(self::getUrl('edit', ['record' => $task]));
            });
    }

    public static function getRelations(): array
    {
        return [
            TaskCommentsRelationManager::class,
            SubtasksRelationManager::class,
            TaskDocumentsRelationManager::class,
            TaskHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }

    /**
     * The stored task in the shape of the edit form state. Later plans add the
     * form keys that are not plain columns here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillData(Task $task, array $data): array
    {
        $data['tags'] = $task->tagsWithType(TagType::Task->value)->pluck('name')->all();

        $billing = $task->billing;

        $data['billing_type'] = ($billing->billing_type ?? TaskBillingType::Inherit)->value;
        $data['hourly_rate'] = $billing?->hourly_rate === null ? null : str_replace('.', ',', $billing->hourly_rate->toMajor());
        $data['fixed_price'] = $billing?->fixed_price === null ? null : str_replace('.', ',', $billing->fixed_price->toMajor());
        $data['estimate_hours'] = $billing?->estimate_seconds === null ? null : EstimateHours::fromSeconds($billing->estimate_seconds);
        $data['internal_note'] = $billing?->internal_note;

        return $data;
    }

    /**
     * The form state in the shape of the domain Action UpdateTask: enum cases
     * become their values and empty text becomes null. Later plans add their
     * form keys here only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function actionData(array $data): array
    {
        return [
            'title' => self::text($data['title'] ?? null) ?? '',
            'description' => self::text($data['description'] ?? null),
            'status' => self::enumValue($data['status'] ?? null),
            'priority' => self::enumValue($data['priority'] ?? null),
            'start_date' => self::text($data['start_date'] ?? null),
            'due_date' => self::text($data['due_date'] ?? null),
            'assignee_id' => self::text($data['assignee_id'] ?? null),
            'requester_id' => self::text($data['requester_id'] ?? null),
            'tags' => is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], 'is_string')) : null,
            'billing_type' => self::enumValue($data['billing_type'] ?? null) ?? TaskBillingType::Inherit->value,
            'hourly_rate' => self::text($data['hourly_rate'] ?? null),
            'fixed_price' => self::text($data['fixed_price'] ?? null),
            'estimate_hours' => self::text($data['estimate_hours'] ?? null),
            'internal_note' => self::text($data['internal_note'] ?? null),
            'hidden_on_board' => array_key_exists('hidden_on_board', $data) && $data['hidden_on_board'] !== null ? (bool) $data['hidden_on_board'] : null,
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

    /**
     * The parent of a subtask, an archived one included.
     */
    private static function parentOf(Task $task): ?Task
    {
        return $task->parent_id === null ? null : $task->parent()->withTrashed()->first();
    }

    /**
     * The project of a task, an archived one included.
     */
    private static function projectOf(?Task $task): ?Project
    {
        return $task === null ? null : Project::query()->withTrashed()->find($task->project_id);
    }

    /**
     * The people a task of the record's project may be assigned to or requested by:
     * TaskPeople::options of the project, the set UpdateTask enforces for a changed
     * person (D-05), plus the task's current person of `$field` when that account is no
     * longer active. UpdateTask does not check an unchanged person again, so the stored
     * value stays selectable; no other inactive account is ever offered.
     *
     * @return array<string, string>
     */
    private static function peopleOptions(?Task $task, string $field): array
    {
        $project = self::projectOf($task);

        if ($project === null) {
            return [];
        }

        $options = app(TaskPeople::class)->options($project);
        $current = $task?->getAttribute($field);

        if (is_string($current) && ! array_key_exists($current, $options)) {
            $options[$current] = (string) User::query()->whereKey($current)->value('name');
        }

        return $options;
    }

    /**
     * The currency code of the client of the task's project, shown beside the money fields.
     */
    private static function clientCurrency(?Task $task): string
    {
        $project = self::projectOf($task);

        return $project === null ? '' : (string) Client::query()->withTrashed()->whereKey($project->client_id)->value('currency');
    }

    private static function text(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        return trim($state) === '' ? null : $state;
    }

    /**
     * The projects a task may be created in, `KEY · name`, ordered by key. Archived
     * projects and projects of archived clients are not offered; with `$openOnly`
     * (task creation, D-11) finished projects are left out too.
     *
     * @return array<string, string>
     */
    private static function projectOptions(bool $openOnly = false): array
    {
        $options = [];
        $query = Project::query()->selectable();

        if ($openOnly) {
            $query->open();
        }

        foreach ($query->orderBy('key')->orderBy('id')->get(['id', 'key', 'name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    /**
     * The filters of the Admin list (TA-05). They combine with AND.
     *
     * @return list<Filter|SelectFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('client')
                ->label(__('kokpit.tasks.filters.client'))
                ->options(static fn (): array => self::clientOptions())
                ->searchable()
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    // Archived projects still own their tasks, so the archive scope is lifted.
                    return is_string($value) && $value !== ''
                        ? $query->whereIn('project_id', Project::query()
                            ->withoutGlobalScopes([SoftDeletingScope::class])
                            ->where('client_id', $value)
                            ->select('projects.id'))
                        : $query;
                }),
            SelectFilter::make('project')
                ->label(__('kokpit.tasks.filters.project'))
                ->attribute('project_id')
                ->options(static fn (): array => self::projectOptions())
                ->searchable(),
            // Several states at once; Done is left out by default, so finished work stays out of
            // the way until it is picked. An empty selection lists every state.
            SelectFilter::make('status')
                ->label(__('kokpit.tasks.filters.status'))
                ->options(ProjectStatus::class)
                ->multiple()
                ->default(array_map(
                    static fn (ProjectStatus $status): string => $status->value,
                    array_filter(ProjectStatus::cases(), static fn (ProjectStatus $status): bool => $status !== ProjectStatus::Done),
                )),
            SelectFilter::make('priority')
                ->label(__('kokpit.tasks.filters.priority'))
                ->options(ProjectPriority::class),
            SelectFilter::make('assignee')
                ->label(__('kokpit.tasks.filters.assignee'))
                ->attribute('assignee_id')
                ->options(static fn (): array => self::assigneeOptions())
                ->searchable(),
            SelectFilter::make('tag')
                ->label(__('kokpit.tasks.filters.tag'))
                ->options(static fn (): array => self::tagOptions())
                ->searchable()
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    return is_string($value) && $value !== ''
                        ? $query->whereHas('tags', static fn (Builder $tags): Builder => $tags->where('tags.id', $value))
                        : $query;
                }),
            Filter::make('due')
                ->label(__('kokpit.tasks.filters.due'))
                ->schema([
                    DatePicker::make('due_from')->label(__('kokpit.tasks.filters.due_from')),
                    DatePicker::make('due_until')->label(__('kokpit.tasks.filters.due_until')),
                ])
                ->columns(2)
                ->query(static function (Builder $query, array $data): Builder {
                    $from = self::day($data['due_from'] ?? null);
                    $until = self::day($data['due_until'] ?? null);

                    // Both bounds are inclusive; a task without a due date matches neither bound.
                    return $query
                        ->when($from !== null, static fn (Builder $query): Builder => $query->where('due_date', '>=', $from))
                        ->when($until !== null, static fn (Builder $query): Builder => $query->where('due_date', '<=', $until));
                })
                ->indicateUsing(static function (array $data): array {
                    $from = self::day($data['due_from'] ?? null);
                    $until = self::day($data['due_until'] ?? null);

                    return array_values(array_filter([
                        $from === null ? null : __('kokpit.tasks.filters.due_from').': '.$from,
                        $until === null ? null : __('kokpit.tasks.filters.due_until').': '.$until,
                    ]));
                }),
        ];
    }

    /**
     * A real calendar day as `Y-m-d`, or null for anything else.
     */
    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $day !== null && $day->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        /** @var array<string, string> $clients */
        $clients = Client::query()->withoutGlobalScopes([SoftDeletingScope::class])->orderBy('name')->pluck('name', 'id')->all();

        return $clients;
    }

    /**
     * @return array<string, string>
     */
    private static function assigneeOptions(): array
    {
        /** @var array<string, string> $users */
        $users = User::query()->orderBy('name')->pluck('name', 'id')->all();

        return $users;
    }

    /**
     * The task tags only; project and client tags never appear here.
     *
     * @return array<string, string>
     */
    private static function tagOptions(): array
    {
        $options = [];

        foreach (Tag::query()->where('type', TagType::Task->value)->get() as $tag) {
            $options[(string) $tag->getKey()] = (string) $tag->name;
        }

        asort($options);

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
