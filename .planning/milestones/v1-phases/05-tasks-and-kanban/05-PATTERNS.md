# Phase 5: Tasks and Kanban - Pattern Map

**Mapped:** 2026-10-08
**Files analyzed:** 34 new or modified
**Analogs found:** 31 / 34

All analog paths are git-tracked source (checked with `git ls-files` for the key ones; the `app/`, `database/`, `tests/` trees are fully tracked). Line numbers refer to the files as of this mapping.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Domain/Tasks/Models/Task.php` | model | CRUD | `app/Domain/Projects/Models/Project.php` | exact |
| `app/Domain/Tasks/Models/TaskBilling.php` | model (Admin-only 1:1) | CRUD | `app/Domain/Projects/Models/ProjectBilling.php` | exact |
| `app/Domain/Tasks/Models/TaskComment.php` | model (Partner-scoped) | CRUD | `app/Domain/Projects/Models/Project.php` (scope part) | role-match |
| `app/Domain/Tasks/Models/TaskChecklistItem.php` | model (Admin-only) | CRUD | `app/Domain/Projects/Models/ProjectBilling.php` (`DeniesPartners`) | role-match |
| `app/Domain/Tasks/Enums/TaskBillingType.php` | enum | transform | `app/Domain/Projects/Enums/BillingType.php` | exact |
| `app/Domain/Tasks/Actions/CreateTask.php` (+ `UpdateTask`, `AddTaskComment`, `EscalateTask`, `ClearEscalation`, `SaveChecklist`, `RestoreTask`) | service (Action) | CRUD, transactional | `app/Domain/Projects/Actions/CreateProject.php` | exact |
| `app/Domain/Tasks/Actions/MoveTask.php`, `app/Domain/Tasks/Board/TaskBoard.php` | service | event-driven (Livewire move) | none in repo; use RESEARCH Pattern 5 plus `SequenceAllocator` lock style | no analog |
| `app/Domain/Tasks/Billing/TaskBillingResolver.php` | service | transform | none (RESEARCH Pattern 7) | no analog |
| `app/Domain/Tasks/Policies/TaskPolicy.php`, `TaskCommentPolicy.php` | policy | request-response | `app/Domain/Projects/Policies/ProjectPolicy.php` | exact |
| `app/Domain/Tasks/Notifications/*Notification.php`, `TaskNotifier.php` | notification | pub-sub, queued | `app/Domain/Clients/Notifications/PartnerInvitation.php` plus `app/Domain/Operations/Alerts/OperationalAlert.php` (`toDatabase`) | role-match |
| `app/Domain/Shared/Text/RichText.php` | utility | transform | none (RESEARCH Pattern 4); Filament `SupportServiceProvider` binding | no analog |
| `app/Domain/Shared/Tags/TagType.php` (modify) | enum | config | itself | exact |
| `app/Domain/Shared/Database/MorphMap.php` (modify) | config | config | itself, lines 43-44 | exact |
| `app/Providers/AccessServiceProvider.php` (modify) | provider | config | itself, lines 59-64 | exact |
| `app/Providers/Filament/AdminPanelProvider.php` (modify: bell, global search, profile) | provider | config | itself, lines 73, 80 | exact |
| `app/Domain/Projects/Actions/UpdateProject.php` (modify: key freeze error) | service | CRUD | itself, lines 121-123 | exact |
| `app/Filament/Resources/TaskResource.php` + `Pages/*` | resource | CRUD | `app/Filament/Resources/ProjectResource.php` + `Pages/CreateProject.php` | exact |
| `app/Filament/Partner/Resources/PartnerTaskResource.php` + `Pages/*` | resource (Partner) | CRUD (list, create, view) | `app/Filament/Partner/Resources/PartnerProjectResource.php` | exact (adds create) |
| `app/Filament/RelationManagers/TaskHistoryRelationManager.php` | relation manager | read | `app/Filament/RelationManagers/ProjectHistoryRelationManager.php` | exact |
| `TaskCommentsRelationManager`, `PartnerTaskCommentsRelationManager`, `SubtasksRelationManager` | relation manager | CRUD | `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php` | role-match |
| `app/Filament/Support/TaskColumns.php` | utility | transform | `app/Filament/Support/ProjectColumns.php` | exact |
| `app/Filament/Pages/TaskBoardPage.php`, `ProjectResource/Pages/ProjectBoard.php`, `Concerns/ManagesTaskBoard.php` | page / trait | event-driven | `app/Filament/Pages/SystemPage.php` (page + `EnforcesPageAccessRule`) | partial |
| `app/Filament/Pages/Auth/EditProfile.php` | page | CRUD | `app/Filament/Pages/Auth/AcceptInvitation.php` (page subclass with `#[AccessRule]`) | partial |
| `resources/views/filament/pages/task-board.blade.php` | view | render | `resources/views/filament/pages/system-page.blade.php` (inline style) | partial |
| `database/migrations/2026_10_10_*` (tasks, task_billing, comments, checklist, users prefs, key-freeze trigger) | migration | DDL | `database/migrations/2026_10_09_000300_create_projects_table.php`, `..._000400_create_project_billing_table.php` | exact |
| `database/factories/TaskFactory.php` | factory | transform | `database/factories/ProjectFactory.php` | exact |
| `config/kokpit.php` (modify: `board.done_limit`) | config | config | itself (`alerts`, `health` blocks) | exact |
| `lang/cs/kokpit.php`, `lang/cs/enums.php` (modify) | i18n | config | itself | exact |
| `tests/Arch/ModelDeclarationTest.php`, `ActivityAllowlistTest.php` (modify) | test | registry | themselves | exact |
| `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`, `RouteWalkTest.php`, `PartnerSafeColumnsTest.php` (modify) | test | registry | themselves | exact |
| New feature tests (`tests/Feature/Tasks/*`, concurrency `task-worker.php`) | test | mixed | `tests/Feature/Projects/*`, `tests/Concurrency/worker.php`, `tests/Support/UnlockedSequenceAllocator.php` | role-match |

## Pattern Assignments

### `app/Domain/Tasks/Models/Task.php` (model, CRUD)

**Analog:** `app/Domain/Projects/Models/Project.php`

**Imports and attributes** (lines 7-26, 60-83):
```php
use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

#[Fillable([...plain columns only...])]   // ids set with forceFill (client_id is not fillable in Project)
#[LoggedAttributes([...])]                // allowlist; description stays out
final class Task extends KokpitModel implements PartnerIsolated
{
    use HasFactory, IsolatesPartners, LogsAllowlistedActivity, SoftDeletes;
    use HasTags { detachTags as private detachTagsFromTrait; }
```

**Partner scope** (Project lines 97-108). For Task, delegate to the scoped `Project` query (RESEARCH Pattern 3):
```php
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query->whereIn($this->qualifyColumn('project_id'), Project::query()->select('projects.id'));
}
```

**Keep tags on archive** (Project lines 141-148): copy the `detachTags` override verbatim (a soft delete must not detach tags).

**Casts** (Project lines 171-180): `'status' => ProjectStatus::class, 'priority' => ProjectPriority::class, 'start_date' => 'date'`, plus `completed_at`/`escalated_at` datetimes.

**Relations**: `billing(): HasOne` as in Project lines 163-166; `project(): BelongsTo`, `parent`/`subtasks`, `assignee`, `requester`. Add `SortableTrait` with `$sortable` config (RESEARCH C7). Add `getRouteKeyName(): 'reference'`.

---

### `app/Domain/Tasks/Models/TaskBilling.php` and `TaskChecklistItem.php` (model, Admin-only)

**Analog:** `app/Domain/Projects/Models/ProjectBilling.php`

**Core** (lines 50-70, 99-110):
```php
#[Fillable(['billing_type', 'hourly_rate', 'fixed_price', 'estimate_seconds'])]
#[LoggedAttributes(['task_id', 'billing_type', 'hourly_rate_minor', 'hourly_rate_currency', 'fixed_price_minor', 'fixed_price_currency', 'estimate_seconds'])]
final class TaskBilling extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'task_billing';

    protected function casts(): array
    {
        return [
            'billing_type' => TaskBillingType::class,
            'hourly_rate' => MoneyCast::class,
            'fixed_price' => MoneyCast::class,
            'estimate_seconds' => 'integer',
        ];
    }
}
```
`task_id` is not fillable (created via `$task->billing()->create()`). `TaskChecklistItem` uses `DeniesPartners` only (no activity log). Do not copy the `internal_note` column or `clientHoldsMoney`.

---

### `app/Domain/Tasks/Enums/TaskBillingType.php` (enum)

**Analog:** `app/Domain/Projects/Enums/BillingType.php` (two-valued, `HasLabel`, doc ties values to the CHECK constraint). Add four cases and Czech keys `enums.task_billing_type.*` in `lang/cs/enums.php` (next to `project_status` at line 49); `tests/Feature/Localisation/EnumLabelsTest.php` fails otherwise. Task status and priority reuse `ProjectStatus` / `ProjectPriority` (no new enums).

---

### `app/Domain/Tasks/Actions/CreateTask.php` (+ sibling Actions) (service, transactional CRUD)

**Analog:** `app/Domain/Projects/Actions/CreateProject.php`

**Structure** (lines 51-111): `final class`, one `handle()` taking the actor/parent model and a typed `@phpstan-type` data array; validate and convert input before the transaction using `ProjectInput`; run the write in `DB::transaction`; translate unique violations outside the transaction.
```php
$hourlyRate = ProjectInput::money($data['hourly_rate'] ?? null, $client, 'hourly_rate');
$fixedPrice = ProjectInput::money($data['fixed_price'] ?? null, $client, 'fixed_price');
$estimateSeconds = ProjectInput::estimateSeconds($data['estimate_hours'] ?? null);
ProjectInput::requireFixedPrice($data['billing_type'], $fixedPrice);

try {
    return DB::transaction(function () use (...): Project {
        $project = $client->projects()->create($attributes);          // relation, never mass-assigned FK
        if ($tags !== []) { $project->syncTagsWithType($tags, TagType::Project->value); }
        $project->billing()->create([...]);
        return $project->refresh();                                    // load DB defaults
    });
} catch (UniqueConstraintViolationException $e) {
    ProjectInput::translateKeyViolation($e);
}
```
Errors are `ValidationException::withMessages([...])` keyed by the data key (line 59). Omit status/priority when null so DB defaults apply (lines 81-86).

**Numbering inside the transaction** (`app/Domain/Settings/Numbering/DocumentNumbering.php` lines 68-78): `nextTaskNumber(string $projectId, string $projectKey): string` returns `KEY-N`; it throws `LogicException` outside a transaction. Lock order: board advisory lock, project `FOR SHARE`, counter row (RESEARCH Pattern 2). The integer is the suffix after the last `-`.

**Notifications** are dispatched explicitly from the Action (not observers), after the work, inside the transaction (notifications use `afterCommit`).

---

### `app/Domain/Tasks/Policies/TaskPolicy.php`, `TaskCommentPolicy.php` (policy)

**Analog:** `app/Domain/Projects/Policies/ProjectPolicy.php` lines 19-33
```php
final class TaskPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Task && /* project client_id === $user->client_id */;
    }
    // plus create/comment/escalate for Partners; update/delete keep the base denial
}
```
Register in `app/Providers/AccessServiceProvider.php` after line 64, same style as lines 59-64:
```php
Gate::policy(Project::class, ProjectPolicy::class);
Gate::policy(ProjectBilling::class, AdminOnlyPolicy::class);
```
Add `Task` -> `TaskPolicy`, `TaskComment` -> `TaskCommentPolicy`, `TaskBilling` and `TaskChecklistItem` -> `AdminOnlyPolicy`.

---

### `app/Domain/Tasks/Notifications/*` (notification, queued)

**Analog:** `app/Domain/Clients/Notifications/PartnerInvitation.php` (lines 22-50)
```php
final class PartnerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $inviteeName, /* scalars only */)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array { return ['mail']; }   // Phase 5: filter ['mail','database'] by preferences

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('kokpit.invitations.mail.subject'))->greeting(...)->line(...)->action(...);
    }
}
```
**Database payload:** `app/Domain/Operations/Alerts/OperationalAlert.php` `toDatabase` (lines 67-81) builds `FilamentNotification::make()->title()->body()->actions([Action::make()->url(...)])` and returns the Filament database message. Follow it, adding `->getDatabaseMessage()` as RESEARCH Pattern 8 states. Constructors take scalars only; URLs are precomputed per recipient audience; the internal-comment guard throws `LogicException` in the constructor.

---

### `app/Filament/Resources/TaskResource.php` + Pages (resource, CRUD)

**Analog:** `app/Filament/Resources/ProjectResource.php` and `Pages/CreateProject.php`

**Resource header** (ProjectResource lines 1-60): `#[AccessRule(Audience::AdminOnly, reason: ...)]`, `use EnforcesResourceAccessRule;`, imports of `SpatieTagsInput`, `SelectFilter`, `TrashedFilter`, `RestoreAction`, `Section`. Tag inputs always pass the type (`->type(TagType::Task->value)`).

**Create page** (CreateProject lines 15-38):
```php
final class CreateProject extends CreateRecord
{
    use RethrowsDomainValidation;
    protected static string $resource = ProjectResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormErrors(function () use ($data): Project {
            ...scoped lookup of parent (Client) or ValidationException...
            return app(CreateProjectAction::class)->handle($client, ProjectResource::actionData($data));
        });
    }
}
```
Edit page follows `EditProject`, delegating to `UpdateTask`. Resource `getPages()` and a `ProjectResource::actionData()`-style static form-to-Action mapper are the template. Set `$recordRouteKeyName = 'reference'`, `$isGloballySearchable = true`, `getGloballySearchableAttributes(): ['reference', 'title']`.

---

### `app/Filament/Partner/Resources/PartnerTaskResource.php` (resource, Partner)

**Analog:** `app/Filament/Partner/Resources/PartnerProjectResource.php` (lines 30-104)
```php
#[AccessRule(Audience::PartnerAllowed, reason: '...')]
final class PartnerTaskResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $slug = 'my-tasks';
    protected static bool $isGloballySearchable = false;

    public static function canAccess(): bool
    {
        return AccessRules::allows(self::class)
            && parent::canAccess()
            && app(PartnerContext::class)->partnerClientId() !== null;
    }

    public static function table(Table $table): Table
    {
        return $table->columns(ProjectColumns::partnerColumns())->defaultSort('name')->emptyStateHeading(...);
    }
    // getPages(): 'index' => ..., 'view' => ...  (Phase 5 adds 'create'; canCreate() returns true, not false)
}
```
Replace `ProjectColumns` with `TaskColumns::partnerColumns()` / `partnerEntries()` (new `app/Filament/Support/TaskColumns.php`, mirroring `ProjectColumns::PARTNER_COLUMN_NAMES` pinned list). No edit page, no bulk actions, no export.

---

### `app/Filament/RelationManagers/TaskHistoryRelationManager.php`

**Analog:** `app/Filament/RelationManagers/ProjectHistoryRelationManager.php` (full file, 15 lines)
```php
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class TaskHistoryRelationManager extends ActivityHistoryRelationManager {}
```
Also add Czech subject labels `kokpit.activity.subjects.task` and `.task_billing` in `lang/cs/kokpit.php` (existing keys near lines 206-211).

---

### Registry modifications (config)

**MorphMap** (`app/Domain/Shared/Database/MorphMap.php` lines 43-44):
```php
'project' => Project::class,
'project_billing' => ProjectBilling::class,
```
Add `'task'`, `'task_billing'`, `'task_comment'`, `'task_checklist_item'`.

**TagType** (`app/Domain/Shared/Tags/TagType.php` lines 18-19): add `case Task = 'task';`.

**CanaryRegistry** (`tests/Support/CanaryRegistry.php` lines 105-125): one closure per model, wrapped in `runAsSystem`, `forceFill(['client_id' => ...])->save()`; Task fixture finds the canary project by name, ProjectBilling fixture shows how to attach a child row and put the canary into a field. Add four closures (Task, TaskComment, TaskBilling, TaskChecklistItem).

**RouteWalkTest** (`tests/Isolation/RouteWalkTest.php` lines 114-118): add `'my-tasks' => ['partner' => true, ...]` and `'tasks' => ['partner' => false, ...]`; record ids are task references, not uuids (resolve with a `$taskReference` closure like `$projectId`).

Other pinned lists to extend are enumerated in RESEARCH "Registry lines to touch" (ModelDeclarationTest, ActivityAllowlistTest line 45, CanaryRegistryTest, PartnerSafeColumnsTest).

---

### Migrations (`database/migrations/2026_10_10_*`)

**Analog:** `database/migrations/2026_10_09_000300_create_projects_table.php` and `2026_10_09_000400_create_project_billing_table.php`

Conventions to copy: anonymous `return new class extends Migration`, header docblock explaining Partner-readability, `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))`, `foreignUuid(...)->constrained(...)->restrictOnDelete()`, varchar plus `DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')` for enum values (status and priority CHECK lists at projects migration lines 54-55), `softDeletesTz()`, `timestampsTz()`. `task_billing` mirrors project_billing (own uuid pk because schema rule R6, unique `task_id`, money pair checks). Full column and constraint list is in RESEARCH Pattern 1; trigger SQL in Pattern 2 (use ERRCODE `KP002`, existing guard uses `KP001`). `SchemaConventionsTest` enforces the generic rules.

---

### `database/factories/TaskFactory.php`

**Analog:** `database/factories/ProjectFactory.php` (lines 14-50): `@extends Factory<Task>`, `$model`, fictional runtime-assembled strings, `'client_id' => Client::factory()` pattern becomes `'project_id' => Project::factory()`; user FKs via `User::factory()`. Factories build unguarded so non-fillable ids can be set.

---

## Shared Patterns

### Access declaration (fail-closed)
**Source:** `app/Filament/Partner/Resources/PartnerProjectResource.php` line 30; `app/Filament/RelationManagers/ProjectHistoryRelationManager.php` line 14.
**Apply to:** every new Resource, Page, RelationManager under `app/Filament` (`tests/Arch/PanelRegistryTest.php` fails otherwise). Use `Audience::AdminOnly` or `Audience::PartnerAllowed` plus a `reason`, with the matching `Enforces*AccessRule` trait.

### Partner isolation of models
**Source:** `Project.php` (`IsolatesPartners`, `constrainForPartner`), `ProjectBilling.php` (`DeniesPartners`).
**Apply to:** Task and TaskComment (real scope), TaskBilling and TaskChecklistItem (`DeniesPartners`). Never use `exists:` validation rules for pickers; use scoped queries.

### Domain Actions and form errors
**Source:** `CreateProject.php` lines 59, 108-110 and `app/Filament/Concerns/RethrowsDomainValidation.php` (`withFormErrors`).
**Apply to:** all Task Actions and their Filament pages: errors keyed by data key, form is a thin adapter.

### Activity log allowlist
**Source:** `Project.php` lines 70-79 (`#[LoggedAttributes]` plus `LogsAllowlistedActivity`).
**Apply to:** Task and TaskBilling only; never TaskComment or checklist; never description or comment bodies.

### Queued notifications
**Source:** `PartnerInvitation.php` (scalars only, `afterCommit()`), `OperationalAlert.php` (Filament `toDatabase`).
**Apply to:** all task notifications; no `KokpitJob` needed (keeps `tests/Arch/JobContractTest.php` green).

### Fictional data
**Source:** `.claude/CLAUDE.md`; `ProjectFactory` builds names at runtime.
**Apply to:** all fixtures and the XSS canary test (assemble payload fragments at runtime).

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Tasks/Actions/MoveTask.php`, `Board/TaskBoard.php` | service | event-driven | No board or advisory-lock mover exists; use RESEARCH Pattern 5 and `03-SPIKE-KANBAN.md`. The `FOR UPDATE` counter lock in `app/Domain/Shared/Sequences/SequenceAllocator.php` is the nearest locking style |
| `app/Domain/Shared/Text/RichText.php` | utility | transform | No sanitiser wrapper in repo; RESEARCH Pattern 4 gives the strict `HtmlSanitizerConfig` |
| `app/Domain/Tasks/Billing/TaskBillingResolver.php` | service | transform | No read-time inheritance resolver yet; RESEARCH Pattern 7 |

## Metadata

**Analog search scope:** `app/Domain/Projects`, `app/Domain/Clients/Notifications`, `app/Domain/Operations/Alerts`, `app/Domain/Settings/Numbering`, `app/Filament`, `app/Providers`, `database/migrations`, `database/factories`, `tests/Support`, `tests/Isolation`, `tests/Arch`
**Files scanned:** about 25 read in full or in part
**Pattern extraction date:** 2026-10-08
