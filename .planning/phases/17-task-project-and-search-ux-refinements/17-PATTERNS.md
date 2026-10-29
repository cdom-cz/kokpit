# Phase 17: Task, project and search UX refinements - Pattern Map

**Mapped:** 2026-10-29
**Files analyzed:** 27 (2 new migrations, 1 new blade, rest modified)
**Analogs found:** 25 / 27 (all analogs are git-tracked source under `app/`, `resources/`, `database/`)

Every item is an extension of an existing owner (add a parameter or branch). Read the touched file in full before editing.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `database/migrations/2026_10_29_000100_add_hidden_on_board_to_tasks_table.php` | migration | batch | `database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php` (header-comment style) | role-match |
| `database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php` | migration | batch | same + `Schema::table` form from RESEARCH Pattern 8 | role-match |
| `app/Domain/Tasks/Board/TaskBoard.php` | domain query | request-response | itself, `applyFilters` (lines 112-140) | exact |
| `app/Domain/Tasks/Actions/UpdateTask.php` (+ `Task` model) | domain action | CRUD | itself (`array_key_exists` per key) | exact |
| `app/Domain/Projects/Models/Project.php` (`scopeOpen`) | model scope | CRUD | `Project::selectable()` scope in same file | exact |
| `app/Domain/Signal/Models/SignalTask.php` | model | CRUD | itself; add `task()` BelongsTo, update docblock "the two never link" | exact |
| `app/Domain/Signal/Actions/AddSignalTask.php` | domain action | CRUD | itself (lines 34-70) | exact |
| `app/Domain/Signal/Queries/SignalDayReader.php` | query | request-response | itself | exact |
| `app/Livewire/Signal/SignalDay.php` | Livewire component | event-driven | itself (`addTask` line 79) + `TaskResource::timerAction` (468-495) | exact |
| `resources/views/livewire/signal/day.blade.php` | view | request-response | itself (row markup lines 66-120) | exact |
| `app/Filament/Resources/TaskResource.php` | resource | CRUD | itself (`quickCreateAction` 554, `projectOptions` 745, form 272, search 190) | exact |
| `app/Filament/Resources/TaskResource/Pages/ViewTask.php` (Add time) | page action | request-response | `changeStatusAction` (lines ~312-362) | exact |
| `app/Filament/Resources/ProjectResource/Pages/ViewProject.php` (New task, Change status) | page action | request-response | `ViewTask::changeStatusAction` + `TaskResource::quickCreateAction` | exact |
| `app/Filament/Resources/ProjectResource.php` (search order) | resource | request-response | `getGlobalSearchEloquentQuery` lines 163-169 | exact |
| `app/Filament/Resources/TimeEntryResource.php` (`entryFields` lockContext) | form builder | CRUD | `entryFields(bool $running, bool $reserved)` line 153 (`->disabled($reserved)`) | exact |
| `app/Filament/Support/TaskColumns.php` | infolist | request-response | itself, description entries lines 109-113 and 235-239 | exact |
| `resources/views/filament/pages/partials/task-preview.blade.php` | view | request-response | itself | exact |
| `app/Filament/Support/DocumentActions.php` (`preview`) | action | file-I/O | itself lines 110-121 | exact |
| `app/Filament/Concerns/RendersTaskCommentThread.php` + `resources/views/filament/tasks/comment-thread.blade.php` | concern + view | file-I/O | `deleteCommentDocumentAction` (238-260), `previewCommentDocument` (222-231) | exact |
| `resources/views/filament/documents/lightbox.blade.php` (new) | view | request-response | no direct analog; use `<img>` inline-style per RESEARCH Pattern 9 | none |
| `app/Filament/Partner/Resources/PartnerTaskResource.php` (`projectOptions`) | resource | CRUD | itself line 187 | exact |
| `lang/cs/kokpit.php`, `lang/cs/documents.php` | config | - | existing keys `kokpit.tasks.actions.*`, `documents.actions.*` | exact |
| `tests/Isolation/PartnerSafeColumnsTest.php` | test | - | itself (pinned list at line 50) | exact |
| `tests/Feature/Signal/SignalTaskLinkTest.php` (new) | test | - | existing `tests/Feature/Signal/*` | role-match |
| `tests/Feature/TimeTracking/TaskAddTimeTest.php` (new) | test | - | existing `tests/Feature/TimeTracking/*` | role-match |
| `tests/Feature/Projects/*` (new project-view action tests) | test | - | `tests/Feature/Tasks/TaskResourceTest.php` | role-match |
| extended: `GlobalSearchTest`, `TaskBoardTest`, `TaskResourceTest`, `PartnerTaskResourceTest` | test | - | themselves | exact |

## Pattern Assignments

### Checklist full width - `TaskResource.php` line 272 (form)
Add `->columnSpanFull()` right after `Section::make(__('kokpit.tasks.checklist.heading'))`; `ViewTask.php` lines 101-103 already do this for the view page.
```php
Section::make(__('kokpit.tasks.checklist.heading'))
    ->columnSpanFull()
    ->schema([ Repeater::make('checklistItems') ... ]),
```

### Rich text - `TaskColumns.php` lines 109-113 and 235-239
Keep `RichText::render` and add `->prose()` (adds `fi-prose`); do NOT use `->html()`.
```php
TextEntry::make('description')
    ->label(__('kokpit.tasks.fields.description'))
    ->formatStateUsing(static fn (?string $state): HtmlString => RichText::render($state))
    ->prose()
    ->placeholder(__('kokpit.tasks.empty_value'))
    ->columnSpanFull(),
```
`task-preview.blade.php`: wrap the `RichText::render(...)` output in `<div class="fi-prose" style="overflow-x: auto; overflow-wrap: anywhere;">`. Comments already use `fi-prose` (comment-thread.blade.php ~line 19).

### Hide flag - `TaskBoard::applyFilters` (lines 112-140)
Existing style: sequential `$query->where('tasks.<col>', ...)`. Add unconditionally next to the `whereIn('tasks.project_id', $projects)` line:
```php
$query->where('tasks.hidden_on_board', false);
```
One place only: `columns()` and `move()` both use `applyFilters`. `UpdateTask`: copy the per-key pattern already used in the file:
```php
if (array_key_exists('hidden_on_board', $data)) { $attributes['hidden_on_board'] = (bool) $data['hidden_on_board']; }
```
Form: `Toggle::make('hidden_on_board')->default(false)` in `TaskResource::form()` (Toggle is used for booleans, e.g. checklist `is_done`, lines 285-287); mirror in `fillData`/`actionData`. Do not add to `CreateTask` or `LoggedAttributes`. Admin-only: place in an Admin-only section. Update `PartnerSafeColumnsTest.php` pinned `tasks` list (line 50): append `'hidden_on_board'` after `'updated_at'`.
Migration: `Schema::table('tasks', fn (Blueprint $table) => $table->boolean('hidden_on_board')->default(false));` with a `/* ... */` header comment explaining the why, like `2026_10_28_000400_make_invoice_item_unit_optional.php` lines 8-21; `declare(strict_types=1);`, anonymous `return new class extends Migration`.

### New task from project - `TaskResource::quickCreateAction` (lines 554-594)
Current signature `quickCreateAction(?Project $project = null)`; Select `project_id` uses `->options(static fn (): array => self::projectOptions())->default($project?->getKey())`, action body resolves `Project::query()->withTrashed()->find($projectId)` and calls `CreateTask`, then `$action->redirect(self::getUrl('edit', ['record' => $task]))`, errors via `self::underModal($e)`.
Change: add `bool $lockProject = false`; on the Select `->disabled($lockProject)->dehydrated(false)`; in the body use `$lockProject ? $project : Project::find(...)` (disabled fields are not in `$data`). Options: `projectOptions(bool $openOnly = false)` (line 745: `Project::query()->selectable()->orderBy('key')->orderBy('id')->get(['id','key','name'])`) with `->open()` when true; quick-create passes true, the list filter at line ~782 keeps false.
`Project` scope, mirror `selectable()` in the same model:
```php
public function scopeOpen(Builder $query): void { $query->where("{$this->getTable()}.status", '!=', ProjectStatus::Done->value); }
```
`ViewProject::getHeaderActions()` (lines 21-31): add `TaskResource::quickCreateAction($this->getRecord(), lockProject: true)->visible(fn () => ! status done && ! trashed)` before `EditAction::make()`.
Partner: `PartnerTaskResource::projectOptions()` (line 187, identical query) gets `->open()`.

### Project status change - `ViewProject.php`
Analog: `ViewTask::changeStatusAction()` (lines ~312-362). Copy structure verbatim, swapping Task for Project:
```php
Action::make('changeStatus')
    ->visible(fn (): bool => $this->getRecord() instanceof Task && TaskResource::canEdit($this->getRecord()))
    ->fillForm(fn () => ['status' => TaskResource::enumValue($task->status)])
    ->schema([Select::make('status')->options(ProjectStatus::class)->required()->native(false)])
    ->action(function (array $data, Action $action): void {
        abort_unless(TaskResource::canAccess(), 403);
        $status = TaskResource::enumValue($data['status'] ?? null);
        if ($status === null) { throw ValidationException::withMessages(['mountedActions.0.data.status' => ...]); }
        app(UpdateTask::class)->handle($actor, $task, ['status' => $status]);
        $task->refresh();
        $action->success();
    });
```
For projects: `ProjectResource::canAccess()/canEdit()`, `app(UpdateProject::class)->handle($project, ['status' => $status])` (no actor arg; verify signature in `UpdateProject.php`). Use `->link()` or a header `Action` with icon `Heroicon::OutlinedArrowsRightLeft`; add `kokpit.projects.actions.change_status*` and `kokpit.projects.notifications.status_changed` strings. Hidden on trashed project.

### Search order
`TaskResource::getGlobalSearchEloquentQuery()` (lines 190-195, currently no order) and `ProjectResource` (lines 163-169, ends `->orderBy((new Project)->qualifyColumn('key'))`). Add before existing order:
```php
->orderByRaw('('.(new Task)->qualifyColumn('status')." = '".ProjectStatus::Done->value."') ASC")
```
(constant, not input; precedent `TaskColumns::orderByPriority` with `@phpstan-ignore argument.type`). Tasks: add tie-breaks `tasks.reference`, `tasks.id`. Test in `tests/Feature/Search/GlobalSearchTest.php`.

### Add time from task - `ViewTask` + `TimeEntryResource::entryFields`
`entryFields(bool $running = false, bool $reserved = false)` (line 153): each context Select has `->disabled($reserved)` (client line 160, project 180, ...). Add `bool $lockContext = false` and `->disabled($reserved || $lockContext)` on client, project, task Selects only. In `ViewTask` add header action modeled on `changeStatusAction` (same `abort_unless(...canAccess(), 403)`, `assert($actor instanceof User)`):
```php
->schema(TimeEntryResource::entryFields(lockContext: true))
->action(function (array $data, Action $action) {
    // merge client_id/project_id/task_id from the record: disabled fields are not in $data
    app(CreateTimeEntry::class)->handle($actor, TimeEntryResource::actionData([...]));
    $this->dispatch('time-entry-saved');
    $action->success();
});
```
Catch `ValidationException` and send `Notification::make()->danger()` (as `TaskResource::runLifecycle` does), since disabled fields hide errors. Reset `$this->timeTotals = null` after save (private property, ViewTask lines ~44-49).

### Signal link
**`AddSignalTask::handle` (lines 34-70):** add `?string $taskId = null`; inside the existing transaction (after `SignalLock::take`), when set: `Str::isUuid`, load `Task::query()->with('project')->whereKey($taskId)->first()`, refuse `status === done` with `SignalRuleViolation::because('task_unavailable')`, rebuild the title server-side `"{$task->project->name}: {$task->title} [{$task->reference}]"` truncated with `mb_substr(..., 0, SignalRules::MAX_TITLE_LENGTH)` (clarification: format is `{PROJECT}: {task name} [{task code}]`, overrides RESEARCH's code-first order). The existing create call:
```php
return SignalTask::query()->create([
    'title' => $title, 'for_date' => $forDate, 'category' => $category,
    'position' => $position === null ? 0 : ((int) $position) + 1,
]);
```
Then `->forceFill(['task_id' => ...])->save()` (ids are never mass-assigned; `SignalTask` uses `#[Fillable([...])]` line 31, do not add `task_id`). Add messages under `kokpit.signal.errors.*`.
**Migration:** `$table->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete(); $table->index('task_id', 'signal_tasks_task_id_index');`
**`SignalDay` (lines 55-87):** public props must be typed scalars (Arch `LivewireComponentContractTest`); add `public ?string $pickedTaskId = null; public string $pickedLabel = '';`, `#[Computed]` suggestions, `pickTask(string $id)` (validate `Str::isUuid`, `Str::isUuid` pattern as in `startEdit` line 98), `clearPick()`. `addTask()` becomes:
```php
if ($this->attempt(fn ($actor) => app(AddSignalTask::class)->handle($actor, $this->newTitle, $this->forDate, $category, $this->pickedTaskId))) {
    $this->newTitle = ''; $this->pickedTaskId = null; $this->changed();
}
```
Timer: new `toggleTaskTimer(string $signalTaskId)` that resolves via owner-scoped `SignalTask::query()->whereKey($id)->first()`, reads `task_id` from the DB row, and mirrors the dispatch tail of `TaskResource::timerAction`:
```php
$event = app(TaskTimerToggle::class)->handle($actor, $task);
if ($event !== null) { $this->dispatch($event); }
```
Add `#[On('timer-started')]`, `#[On('timer-stopped')]`, `#[On('time-entry-saved')]` handlers that `unset($this->day)` (component already uses `#[Computed] day`). Keep `RequiresAdmin`.
**Reader:** `SignalDayReader::tasks()` eager-loads `task` with `withoutGlobalScopes([SoftDeletingScope::class])` (bare `withoutGlobalScopes()` is banned by `tests/Arch/QueryEscapeHatchTest.php`). Update the `SignalTask` class docblock.
**Blade (`day.blade.php`):** title span at ~line 112 stays plain text; next to the recurring icon (`<x-filament::icon icon="heroicon-m-arrow-path" style="width: 1rem; height: 1rem;" />`) add timer button (`heroicon-m-play`/`heroicon-m-stop`, `wire:click="toggleTaskTimer('{{ $task['id'] }}')"`) and `<a href=... >` with `heroicon-m-arrow-top-right-on-square`. Inline styles only (no asset pipeline). Add-form input: `x-filament::input.wrapper` + `x-filament::input` as in the edit row (lines 88-95), `wire:model.live.debounce.250ms="newTitle"`, suggestion `<ul>` of `<button type="button" wire:click="pickTask('...')">`, cross `x-filament::icon-button` with `heroicon-m-x-mark`.

### Files: lightbox and new window - `DocumentActions::preview()` (lines 110-121)
Current:
```php
Action::make('preview')
    ->visible(static fn (Document $record): bool => DocumentDisposition::isInline($record))
    ->action(static function (Document $record, Component $livewire): void {
        Gate::forUser(self::user())->authorize('view', $record);
        $livewire->redirect(DocumentUrl::preview($record, self::user()));
    });
```
For images (`str_starts_with($record->mime_type, 'image/')` AND `isInline`): `->modalSubmitAction(false)->modalCancelActionLabel(...)->modalWidth(Width::FiveExtraLarge)->modalHeading(fn (Document $record) => $record->name)->modalContent(fn (Document $record): View => view('filament.documents.lightbox', ['src' => DocumentUrl::preview(...)]))`, re-run `Gate::authorize('view')` inside the closure (URL minted at render, never in list HTML; TTL 120 s). Non-images: replace `$livewire->redirect(...)` with `$livewire->js('window.open('.Js::from($url).', "_blank", "noopener")')` (RESEARCH Pitfall 4; needs Safari/Chrome/Firefox manual check; fallback is an authenticated open-route). Same treatment for the download path if it should open in a new window.
Comment thread: copy `deleteCommentDocumentAction` (238-260: `Action::make`, `$arguments['document']`, `$this->commentDocument($id)` scoped lookup, `$this->commentActor()`) as `previewCommentImageAction()`; blade button at line 33 switches from `wire:click="previewCommentDocument(...)"` to `wire:click="mountAction('previewCommentImage', { document: '...' })"` (same form as line 40) for images; non-images keep `previewCommentDocument` (lines 222-231) with the new-window `js()`. Keep `Gate::forUser($actor)->authorize('view', $document)` and the `DocumentDisposition::isInline` abort.

## Shared Patterns

### Action body guard (public `/livewire/update`)
Source: `ViewTask::changeStatusAction` / `TaskResource::quickCreateAction`. Apply to all new Filament actions.
```php
abort_unless(TaskResource::canAccess(), 403);
$actor = auth()->user();
assert($actor instanceof User);
```

### Validation errors under modal
`throw ValidationException::withMessages(['mountedActions.0.data.status' => __('...')])` or `self::underModal($e)` (TaskResource). Errors on disabled fields become a `Notification`.

### Domain Action owns the rule
UI never writes models directly: `UpdateTask`, `UpdateProject`, `CreateTask`, `CreateTimeEntry`, `AddSignalTask`, `TaskTimerToggle`. Client-controlled ids are `Str::isUuid`-checked and re-read server-side.

### Query escape hatches
Only `withoutGlobalScopes([SoftDeletingScope::class])`; never bare `withoutGlobalScopes()` or `DB::table(` in `app/`.

### Terminal state
`ProjectStatus::Done` ("Dokončeno") is the only terminal state; no Completed/Cancelled enum cases exist.

## No Analog Found

| File | Role | Reason |
|---|---|---|
| `resources/views/filament/documents/lightbox.blade.php` | view | No lightbox view exists; use a single `<img src alt style="max-width:100%;max-height:80vh;margin:auto">` and Filament docs `modalContent` example |
| Signal type-ahead dropdown markup | view | No type-ahead in the repo; build from `x-filament::input.wrapper` and a positioned `<ul>` |
| Non-image new-window open | behaviour | No existing `window.open`/`target=_blank` for documents; verify in browsers |

## Metadata

**Analog search scope:** `app/Domain/{Tasks,Projects,Signal}`, `app/Filament/{Resources,Support,Concerns,Partner}`, `app/Livewire/Signal`, `resources/views`, `database/migrations`, `tests/{Isolation,Arch}`
**Files scanned:** ~25 read or grepped
**Pattern extraction date:** 2026-10-29
