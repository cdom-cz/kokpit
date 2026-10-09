# Phase 6: Time Tracking - Pattern Map

**Mapped:** 2026-10-09
**Files analyzed:** 36 new/modified
**Analogs found:** 33 / 36 (all analog paths are git-tracked source)

Line numbers below refer to the files as read on the mapping date. Fictional data only.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|-------------------|------|-----------|----------------|-------|
| `database/migrations/2026_10_11_000100_*` (composite unique keys on projects, tasks) | migration | schema | `database/migrations/2026_10_10_000100_create_tasks_table.php` (line 89 `tasks_ident_unique`) | role |
| `database/migrations/2026_10_11_000200_create_time_entries_table.php` | migration | CRUD + schema invariants | `database/migrations/2026_10_10_000100_create_tasks_table.php` + `app/Domain/Shared/Database/Immutability.php` | exact |
| users panel-preference migration (side panel toggle) | migration | CRUD | `database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php` | exact |
| `app/Domain/TimeTracking/Models/TimeEntry.php` | model | CRUD | `app/Domain/Tasks/Models/TaskBilling.php` (DeniesPartners) + `Task.php` (forceFill, Fillable) | exact |
| `database/factories/TimeEntryFactory.php` | factory | CRUD | `database/factories/TaskFactory.php` | exact |
| `app/Domain/TimeTracking/Actions/{StartTimer,StopTimer,CreateTimeEntry,UpdateTimeEntry,DeleteTimeEntry,MarkEntriesBilled,CancelEntriesBilling}.php` | service (Action) | CRUD in transaction, advisory lock | `app/Domain/Tasks/Actions/ArchiveTask.php`, `app/Domain/Tasks/Board/TaskBoard.php` | exact |
| `app/Domain/TimeTracking/Billing/{TimeEntryRateResolver,EntryRate,BillableDefault}.php` | service | transform | `app/Domain/Tasks/Billing/TaskBillingResolver.php` | exact |
| `app/Domain/TimeTracking/Enums/BillingState.php`, `BillingBadge.php` | enum | transform | `app/Domain/Tasks/Enums/TaskBillingType.php`, `Tasks/Billing/BillingSource.php` | exact |
| `app/Domain/TimeTracking/Queries/{OverlapFinder,TimesheetQuery,ProjectTimeSummary}.php` | service | read aggregate | `app/Domain/Tasks/Board/TaskBoard.php` / `BoardFilters.php` | role |
| `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` | job | batch / scheduled | `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php` + `KokpitJob.php` | exact |
| `app/Domain/TimeTracking/Notifications/LongRunningTimerNotification.php` | notification | event-driven (bell) | `app/Domain/Tasks/Notifications/TaskNotification.php` | role (sync, no mail) |
| `app/Domain/TimeTracking/TimeEntryInput.php` | utility | transform | `app/Domain/Tasks/TaskInput.php` | exact |
| `app/Domain/TimeTracking/Support/{DurationFormat,TimerClock}.php` | utility | transform | `app/Domain/Projects/EstimateHours.php` | role |
| Policy registration in `app/Providers/AccessServiceProvider.php` | config | - | line 77 `Gate::policy(TaskBilling::class, AdminOnlyPolicy::class)` | exact |
| `app/Filament/Resources/TimeEntryResource.php` (+ `Pages/{List,Create,Edit,View}TimeEntry`) | resource | CRUD | `app/Filament/Resources/TaskResource.php` (+ `TaskResource/Pages/*`) | exact |
| `app/Filament/RelationManagers/TimeEntryHistoryRelationManager.php` | relation manager | read | `app/Filament/RelationManagers/TaskHistoryRelationManager.php` | exact |
| `app/Filament/Pages/TimesheetPage.php` + `resources/views/filament/pages/timesheet.blade.php` | page | request-response, aggregate | `app/Filament/Pages/TaskBoardPage.php` + `filament/pages/task-board.blade.php` | role |
| `app/Filament/Widgets/ProjectTimeStats.php` | widget | read aggregate | `app/Filament/Concerns/EnforcesWidgetAccessRule.php` (no widget exists yet) | partial |
| `app/Filament/Resources/ProjectResource/RelationManagers/{ProjectTasksTimeRelationManager,ProjectTimeEntriesRelationManager}.php` | relation manager | read aggregate | `app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php` | role |
| `ViewProject.php` (modify: header widgets, relation managers on ProjectResource) | page | - | `app/Filament/Resources/ProjectResource/Pages/ViewProject.php`, `ProjectResource.php` | exact |
| `app/Livewire/TimeTracking/{TimerBar,RecentEntriesPanel}.php` + traits `RequiresAdmin` + views | component | event-driven, polling | `app/Filament/Concerns/EnforcesPageAccessRule.php` (boot guard) | partial |
| Render hook registration in `app/Providers/Filament/AdminPanelProvider.php` | config | - | same file (`->globalSearchResourceOptIn()` already chained) | exact |
| `TaskResource.php`, `ViewTask`, `ListTasks`, `ManagesTaskBoard.php`, `task-board.blade.php` (modify: start-timer affordances) | resource/page | request-response | themselves (`previewData()` untrusted-argument pattern in `ManagesTaskBoard`) | exact |
| `Project`, `Task`, `Client` models (modify: `timeEntries()` relation) | model | - | existing relation methods in `Task.php` (`billing()`, `subtasks()`) | exact |
| `config/kokpit.php` (modify: `time.long_running_hours`) | config | - | `board` block at line ~104-115 | exact |
| `routes/console.php` (modify: schedule job) | config | scheduled | lines 20-38 | exact |
| `lang/cs/*` (labels) | config | - | `lang/cs/enums.php` lines 77-82, `kokpit.tasks.*` keys | exact |
| Tests: schema, Actions, resource, isolation, concurrency | test | - | see Testing section | exact |
| `tests/Support/CanaryRegistry.php` (modify: one fixture per model) | test support | - | `TaskBilling::class` fixture at lines 161-173 | exact |
| `tests/Concurrency/timer-worker.php` + `TimerConcurrencyTest.php` | test | concurrency | `tests/Concurrency/task-worker.php`, `TaskBoardConcurrencyTest.php` | exact |

## Pattern Assignments

### `time_entries` migration (migration, schema invariants)

**Analog:** `database/migrations/2026_10_10_000100_create_tasks_table.php`

Split: Blueprint for plain columns and indexes, `DB::statement` for composite FKs, generated columns, CHECKs, partial indexes.

**Blueprint style** (lines 44-52, 68-77):
```php
Schema::create('tasks', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
    $table->uuid('parent_id')->nullable();
    $table->smallInteger('parent_depth')->nullable()
        ->storedAs('CASE WHEN parent_id IS NULL THEN NULL ELSE 0::smallint END');
    $table->timestampTz('completed_at')->nullable();
    $table->softDeletesTz();
    $table->timestampsTz();
    $table->index(['project_id', 'status'], 'tasks_project_status_index');
```

**DB-enforced invariants** (lines 82-95):
```php
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN (...))");
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_ident_unique UNIQUE (id, project_id, depth)');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_fk FOREIGN KEY (parent_id, project_id, parent_depth) REFERENCES tasks (id, project_id, depth) ON DELETE RESTRICT');
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_completed_check CHECK ((status = 'done') = (completed_at IS NOT NULL))");
```

Apply: the full schema proposal is in RESEARCH Pattern 2 (use `timestampTz('started_at', 0)`, stored `duration_seconds`, partial unique index `time_entries_one_running_per_user`, `time_entries_task_needs_project_check`). Because `tasks_ident_unique` is `(id, project_id, depth)`, the new `tasks_id_project_unique (id, project_id)` and `projects_id_client_unique (id, client_id)` are separate migrations that must precede it.

**Guard trigger** from `app/Domain/Shared/Database/Immutability.php:40-77`:
```php
DB::statement(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds']));
DB::statement(Immutability::truncateGuardSql('time_entries'));
// down(): DB::statement(Immutability::dropGuardTriggerSql('time_entries'));
```
`duration_seconds` MUST be in the mutable list (RESEARCH Pitfall 1). Names are validated by `^[a-z_][a-z0-9_]*$` and table name length <= 48. The function `kokpit_guard_frozen_row` comes from `2026_10_07_000200_create_kokpit_guard_frozen_row_function.php`. Schema rules R1-R9 are asserted by `tests/Feature/Schema/SchemaConventionsTest.php`.

---

### `app/Domain/TimeTracking/Models/TimeEntry.php` (model, CRUD, Admin-only)

**Analog:** `app/Domain/Tasks/Models/TaskBilling.php` (Admin-only) and `Task.php` (identity columns via forceFill)

**Imports and class declaration** (TaskBilling lines 5-20, 62-66):
```php
use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['description', 'billable'])]          // content only; ids, started_at, ended_at, billing_state set with forceFill
#[LoggedAttributes(['client_id', 'project_id', 'task_id', 'started_at', 'ended_at', 'billable', 'billing_state', 'billed_at'])]
final class TimeEntry extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;
```
Log allowlist excludes `description` (free text) and `long_running_notified_at` (internal marker). Model must live in `app/Domain/TimeTracking/Models/` so `ModelDeclaration::appModels()` enforces `DeniesPartners`. Declare `casts()` returning `array<string,string>` as at TaskBilling lines 80-88.

---

### `app/Domain/TimeTracking/Actions/StartTimer.php`, `StopTimer.php`, etc. (service, CRUD in transaction)

**Analog:** `app/Domain/Tasks/Actions/ArchiveTask.php` (lines 28-49)

```php
final class ArchiveTask
{
    public function __construct(private readonly TaskBoard $board) {}

    public function handle(User $actor, Task $task): void
    {
        Gate::forUser($actor)->authorize('delete', $task);

        DB::transaction(function () use ($task): void {
            $this->board->lockBoard();
            $locked = Task::query()->withTrashed()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->subtasks()->exists()) {
                throw ValidationException::withMessages(['task' => __('kokpit.tasks.errors.has_active_subtasks')]);
            }
            $locked->delete();
        });
    }
}
```
Conventions: `final` class, `handle(User $actor, ...)`, `Gate::forUser($actor)->authorize(...)`, transaction, re-read the row `lockForUpdate`, domain errors as `ValidationException::withMessages(['<field>' => __('kokpit....')])` keyed by the bare field name.

**Advisory lock** (`app/Domain/Tasks/Board/TaskBoard.php:45`, guarded at 42 by `LogicException` when not in a transaction):
```php
DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK_KEY]);
```
For StartTimer use key `'kokpit:timer:'.$actor->getKey()` (RESEARCH Pattern 3 skeleton). Keep the lock class non-final or inject it so the concurrency mutation run can swap it (TaskBoard docblock line 28: "Not final on purpose: a test double that skips the lock extends it").

Per-model `forceFill(...)->save()` for billing so each entry writes one activity row (RESEARCH Pattern 8); never a bulk `update()` for logged state.

---

### `TimeEntryRateResolver`, `BillableDefault` (service, transform)

**Analog:** `app/Domain/Tasks/Billing/TaskBillingResolver.php`

Constructor-injected `PartnerContext`; refuses non-Admin non-system with `AuthorizationException('The effective billing is available to the Admin only.')`. Per-field first-level-wins list (lines 72-74):
```php
[$own?->hourly_rate, BillingSource::Task],
[$parent?->hourly_rate, BillingSource::ParentTask],
[$projectRow->hourly_rate, BillingSource::Project],
```
`BillableDefault::for(?Task)` delegates to `->resolve($task)->isBillable()` (`EffectiveBilling.php:38`). `BillingSource` has Task / ParentTask / Project / Client; labels in `lang/cs/enums.php:77-82` (default-setting label is RESEARCH Open Question 4).

---

### `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` (job, scheduled batch)

**Analog:** `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php` (lines 5-34) and `KokpitJob.php`

```php
#[Idempotent(how: 'claims each entry once through long_running_notified_at, so a second run notifies nothing new')]
final class NotifyLongRunningTimers extends KokpitJob
{
    public function handle(): void { /* atomic claim UPDATE ... RETURNING, then sync database notification in same transaction */ }
}
```
Contract (KokpitJob docblock): natural key (entry id), check-then-act, unique constraint/atomic claim as last defence; `handle()` runs in the system context via the fixed `RunsAsSystem` middleware; do not override `middleware()` (it is `final`). `JobContractTest` requires `#[Idempotent]`; add the class to its expected list.

**Schedule** (`routes/console.php:20-38`):
```php
Schedule::job(new NotifyLongRunningTimers)
    ->everyFiveMinutes()
    ->name('kokpit-long-running-timers')
    ->onOneServer();
```
`ScheduleOnOneServerTest` fails any event without `onOneServer`.

---

### `LongRunningTimerNotification.php` (notification, bell)

**Analog:** `app/Domain/Tasks/Notifications/TaskNotification.php`

Scalars only in the constructor (lines 47-58), bell payload (lines 125-142):
```php
return FilamentNotification::make()
    ->title($this->text('bell_title', self::TARGET_BELL))
    ->body($body)
    ->actions([ Action::make('open')->label(__('...'))->url($this->url) ])
    ->getDatabaseMessage();
```
Escape every interpolated value once with `e()` (bell target). Difference from the analog: do NOT implement `ShouldQueue` (RESEARCH: sync, same transaction as the claim); `via()` returns `['database']` only; no mail (deferred).

---

### `app/Filament/Resources/TimeEntryResource.php` (+ Pages) (resource, CRUD)

**Analog:** `app/Filament/Resources/TaskResource.php`

**Class header** (lines 73-90):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class TimeEntryResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = TimeEntry::class;
    protected static ?string $slug = 'time-entries';
    protected static ?int $navigationSort = 40;
    // omit $isGloballySearchable: the panel opts in per resource
```
Navigation via `getNavigationLabel(): string` => `__('kokpit...')` and `getNavigationIcon(): Heroicon` (lines 92-100).

**Billed lock override** (lines 138-141, pattern; the policy cannot do it because `KokpitPolicy::before()` returns true for Admin):
```php
public static function canEdit(Model $record): bool
{
    return parent::canEdit($record) && ! ($record instanceof TimeEntry && $record->billing_state === BillingState::Billed);
}
```
Same for `canDelete`. `EditTimeEntry` redirects billed entries to the view page before `parent::mount()`.

**Pages** (lines 526-534): `'index' => ListTimeEntries::route('/')`, `'view' => ViewTimeEntry::route('/{record}')`, `'edit' => EditTimeEntry::route('/{record}/edit')`. Relation managers array includes `TimeEntryHistoryRelationManager::class`.

**Form error mapping** (`app/Filament/Concerns/RethrowsDomainValidation.php`): wrap Action calls in `$this->withFormErrors(fn () => ...)` so bare keys `client_id`/`project_id`/`task_id` become `data.client_id` etc.

**Bulk actions:** closures call `MarkEntriesBilled` / `CancelEntriesBilling` with ids only (re-read under lock), `requiresConfirmation()`, toasts via `Filament\Notifications\Notification`.

Register policy in `AccessServiceProvider` next to line 77: `Gate::policy(TimeEntry::class, AdminOnlyPolicy::class);`

---

### `TimeEntryHistoryRelationManager.php`

**Analog:** `app/Filament/RelationManagers/TaskHistoryRelationManager.php` (whole file, 15 lines):
```php
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class TimeEntryHistoryRelationManager extends ActivityHistoryRelationManager {}
```

---

### `TimesheetPage.php` (page, aggregate)

**Analog:** `app/Filament/Pages/TaskBoardPage.php` (lines 17-43)
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
class TimesheetPage extends Page
{
    use EnforcesPageAccessRule;
    protected static ?string $slug = 'timesheet';
    protected static ?int $navigationSort = 41;
    protected string $view = 'filament.pages.timesheet';
    public static function getNavigationLabel(): string { return __('kokpit....'); }
    public static function getNavigationIcon(): Heroicon { return Heroicon::OutlinedClock; }
    public function getTitle(): string { return __('kokpit....'); }
}
```
Week grid uses `Table::records(Closure)` with a unique `key` per record (RESEARCH Pattern 9).

---

### `ProjectTimeStats.php` (widget) and `ViewProject` changes

**Analog:** `app/Filament/Concerns/EnforcesWidgetAccessRule.php` (no widget exists yet; `app/Filament/Widgets` directory is new). Use `#[AccessRule(Audience::AdminOnly, ...)]` plus `use EnforcesWidgetAccessRule;` on a `StatsOverviewWidget` subclass. Its docblock marks the trait as awaiting its first real widget: remove the `@phpstan-ignore trait.unused` comment when the widget lands.

Modify `app/Filament/Resources/ProjectResource/Pages/ViewProject.php` (final class, `protected static string $resource`): add `getHeaderWidgets(): array`. Add the two relation managers to `ProjectResource::getRelations()` (it already lists `ProjectHistoryRelationManager`).

---

### `ProjectTasksTimeRelationManager` / `ProjectTimeEntriesRelationManager`

**Analog:** `app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php` (lines 26-40)
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class SubtasksRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewByAccessRule;
    }
    protected static string $relationship = 'subtasks';
    public function isReadOnly(): bool { return false; }   // for time tabs: keep read-only
```

---

### Livewire `TimerBar`, `RecentEntriesPanel`, `RequiresAdmin` (component, event-driven)

**Analog (guard only):** `app/Filament/Concerns/EnforcesWidgetAccessRule.php`, boot-hook shape:
```php
public function bootEnforcesWidgetAccessRule(): void
{
    abort_unless(AccessRules::allows(static::class) && static::canView(), 403);
}
```
Copy as `bootRequiresAdmin()` with `abort_unless(app(PartnerContext::class)->isAdmin(), 403)`. Livewire runs trait boot hooks on mount and on every hydrate (RESEARCH Pattern 1). Scalar public properties only. Render hook registration, `GLOBAL_SEARCH_AFTER` and `LAYOUT_END`, goes into `AdminPanelProvider` and returns `''` for non-Admin. No existing Livewire components outside Filament: see No Analog Found.

---

### Task surface start buttons (`ManagesTaskBoard`, `ViewTask`, `ListTasks`, board view)

**Analog:** `app/Filament/Concerns/ManagesTaskBoard.php` `previewData()` (docblock: "The task argument of the preview action is untrusted the same way: scoped lookup, then the view Gate"). Add `startTimer(string $taskId)` using the same checks, then call `StartTimer` and `$this->dispatch('timer-started')`. Page already carries `EnforcesPageAccessRule`. In the board Blade view place a plain button inside the existing `<div wire:sort:ignore ...>` header cluster.

---

### `config/kokpit.php`

Add beside the `board` block (~line 104-115, a boxed comment header then array): `'time' => ['long_running_hours' => 12]` with the same comment style.

---

### Tests

- Schema: copy `tests/Feature/Schema/TasksTableTest.php` and `TaskBillingTableTest.php`; assert violations by SQLSTATE (guard error `KP001`, `23505`, `23503`, `23514`).
- Actions: `tests/Feature/Tasks/TaskActionsTest.php`, `TaskArchiveTest.php`, `TaskBillingResolverTest.php`.
- Isolation: add one fixture to `tests/Support/CanaryRegistry.php` modelled on the `TaskBilling::class` entry (lines 161-173):
```php
TimeEntry::class => static function (string $clientId, string $canary): void {
    app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
        // create an entry for the client with $canary in the description
    });
},
```
- Concurrency: `tests/Concurrency/task-worker.php` conventions (no RefreshDatabase, refuse non-`_test` DB, shared start barrier, JSON summary line, sign in Admin, swap lock class in container for mutation run); test class modelled on `TaskBoardConcurrencyTest.php`.
- Operations: `ScheduleOnOneServerTest`, `JobContractTest`, `ActivityAllowlistColumnsTest` pick up the new job/model automatically; update expected lists.
- Arch: `tests/Arch` enforces `#[AccessRule]` on every Filament class and Partner scope on every model.

## Shared Patterns

### Fail-closed access declaration
**Source:** `TaskResource.php:73`, `TaskBoardPage.php:17`, `TaskHistoryRelationManager.php`
**Apply to:** every new Resource, Page, Widget, RelationManager, Livewire component
`#[AccessRule(Audience::AdminOnly, reason: '...')]` plus the matching `Enforces*AccessRule` trait. Models: `DeniesPartners` + `PartnerIsolated`. Policy: `AdminOnlyPolicy` registered in `AccessServiceProvider`.

### Domain Action shape
**Source:** `ArchiveTask.php`
**Apply to:** all seven TimeTracking Actions. `final`, `handle(User $actor, ...)`, Gate authorize, `DB::transaction`, `lockForUpdate` re-read, `ValidationException::withMessages([field => __(...)])`. Truncate with `startOfSecond()` after taking the lock; use `forceFill` for non-fillable columns.

### Activity log allowlist
**Source:** `TaskBilling.php` (`#[LoggedAttributes([...])]`, `use LogsAllowlistedActivity`). Only model saves write rows; never use bulk update for logged changes.

### Notifications escape
**Source:** `TaskNotification.php` `toDatabase()`; `e()` once per value.

### Czech and wording
Labels in `lang/cs/*.php` under `kokpit.*`; plural via `trans_choice`. Code, comments, tests in English.

### Repository hygiene
Fictional data only (`example.com`, company ID `12345678`); run `scripts/check-sensitive.sh` before each commit.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Livewire/TimeTracking/TimerBar.php`, `RecentEntriesPanel.php` | component | event-driven, polling | No standalone Livewire component exists yet; use RESEARCH Pattern 1 (render hooks, `HasActions` + `InteractsWithActions`, `<x-filament-actions::modals />`) |
| `app/Filament/Widgets/ProjectTimeStats.php` | widget | read aggregate | No widget exists in the project; use RESEARCH Pattern 12 and `EnforcesWidgetAccessRule` |
| Czech collation helper (`COLLATE "cs-CZ-x-icu"`) | utility | transform | No existing collation helper; RESEARCH Pitfall 3 and CONTRIBUTING require defining and testing it in this phase |

## Metadata

**Analog search scope:** `app/Domain/{Tasks,Operations,Shared,Projects}`, `app/Filament/{Resources,Pages,Concerns,RelationManagers}`, `database/migrations`, `routes/console.php`, `config/kokpit.php`, `tests/{Concurrency,Support,Feature}`
**Files scanned:** about 40
**Pattern extraction date:** 2026-10-09
