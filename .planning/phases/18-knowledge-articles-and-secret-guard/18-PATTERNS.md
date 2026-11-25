# Phase 18: Knowledge Articles and Secret Guard - Pattern Map

**Mapped:** 2026-11-25
**Files analyzed:** 33 new/modified (grouped where the pattern is identical)
**Analogs found:** 27 / 33 (6 have no analog, see "No Analog Found")

All analog paths below were checked as git-tracked source (`git ls-files`); none is a gitignored mirror. All excerpts are verbatim from the repository; no real values appear.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_11_2x_*_create_knowledge_{folders,articles,article_versions}_tables.php` | migration | CRUD + trigger guards | `database/migrations/2026_10_27_000200_create_cash_receipts_table.php` | exact |
| `app/Domain/Knowledge/Models/KnowledgeArticle.php` | model | CRUD | `app/Domain/Tasks/Models/TaskBilling.php` | exact (Admin-only, logged allowlist) |
| `app/Domain/Knowledge/Models/KnowledgeArticleVersion.php` | model | append-only | `app/Domain/Tasks/Models/TaskBilling.php` (no logging) | role-match |
| `app/Domain/Knowledge/Models/KnowledgeFolder.php` | model | CRUD | `app/Domain/Signal/Models/SignalTask.php` (shape) + `DeniesPartners` | role-match |
| `app/Domain/Knowledge/Enums/ArticleKind.php` | enum | n/a | `app/Domain/Shared/Tags/TagType.php` + `lang/cs/enums.php` | role-match |
| `app/Domain/Knowledge/Actions/SaveArticle.php` | service (Action) | request-response, transactional write | `app/Domain/Tasks/Actions/UpdateTaskDescription.php` | exact |
| `app/Domain/Knowledge/Actions/ArchiveArticle.php`, `RestoreArticle.php` | service (Action) | CRUD + explicit activity row | `UpdateTaskDescription.php` (activity block) | role-match |
| `app/Domain/Knowledge/Actions/{ArticleInput,SaveResult}.php` | DTO/enum | transform | `app/Domain/Tasks/TaskInput.php` | role-match |
| `app/Domain/Knowledge/Exceptions/{StaleArticle,SecretGuardRefused,ArticleWriteFailed}.php` | exception | request-response | `DomainException` use in `EditInvoice.php` | partial |
| `app/Domain/Knowledge/Guard/{SecretScanner,SecretFinding,SecretRule,Severity}.php` | utility | transform | none | no analog |
| `app/Domain/Knowledge/Support/{KnowledgeMarkdown,MarkdownNormaliser,CalloutExtension,CodeBlockRenderer}.php` | utility | transform | none (vendor docs only) | no analog |
| `app/Filament/Resources/KnowledgeArticleResource.php` | resource | CRUD | `app/Filament/Resources/ProjectResource.php` | exact |
| `app/Filament/Resources/KnowledgeArticleResource/Pages/{List,Create,View,Edit}KnowledgeArticle.php` | page | request-response | `app/Filament/Resources/ClientResource/Pages/{Edit,Create}Client.php` | exact |
| `app/Filament/Concerns/InKnowledgeGroup.php` | trait | n/a | `app/Filament/Concerns/InSignalGroup.php` | exact |
| `app/Providers/Filament/AdminPanelProvider.php` (modify: `navigationGroups`) | config | n/a | same file | self |
| `app/Providers/AccessServiceProvider.php` (modify) | config | n/a | lines 120-145 | self |
| `app/Domain/Shared/Database/MorphMap.php` (modify) | config | n/a | same file `MAP` | self |
| `app/Domain/Shared/Tags/TagType.php` (modify) | enum | n/a | same file | self |
| `resources/css/kokpit-prose.css`, `resources/js/knowledge-copy.js` + FilamentAsset registration | asset | event-driven (click) | none | no analog |
| `resources/views/filament/knowledge/{prose,guard-findings}.blade.php` | view | request-response | `resources/views/filament/tasks/comment-thread.blade.php` (cited by UI-SPEC) | role-match |
| `lang/cs/knowledge.php`, `lang/cs/enums.php` (modify) | config | n/a | `lang/cs/enums.php` | role-match |
| `tests/Concurrency/KnowledgeSaveConcurrencyTest.php` (+ worker `knowledge-save-worker.php`) | test | concurrent | `tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` | exact |
| `tests/Arch/ModelDeclarationTest.php`, `ActivityAllowlistTest.php` (modify) | test | n/a | same files | self |
| `tests/Support/{PartnerMatrix,ChannelMatrix,CanaryRegistry}.php`, `tests/Isolation/CanaryRegistryTest.php` (modify) | test support | n/a | `SignalTask` entries in each | exact |
| `tests/Support/SecretFixtures.php`, `tests/Unit/Knowledge/*`, `tests/Feature/Knowledge/*` | test | n/a | `tests/Support/*Fixtures.php`, `tests/Unit/*` | role-match |
| `.gitignore` (modify) | config | n/a | existing `/public/css/filament/` lines | self |

## Pattern Assignments

### `SaveArticle.php` (service, transactional write)

**Analog:** `app/Domain/Tasks/Actions/UpdateTaskDescription.php`

**Imports and class shape** (lines 5-14, 52-54):
```php
namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateTaskDescription
{
    public function __construct(private readonly TaskNotifier $notifier) {}
```

**Core pattern: authorise, transaction, scoped locked re-read, stale check, no-op return** (lines 69-92):
```php
Gate::forUser($actor)->authorize('editDescription', $task);
...
DB::transaction(function () use ($actor, $task, $clean, $basedOn): void {
    // The scoped query: a task that is not visible to the actor, or archived, is a ModelNotFoundException.
    $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
    ...
    if (! hash_equals(self::fingerprint($locked->description), $basedOn)) {
        throw ValidationException::withMessages(['description' => __('kokpit.tasks.errors.description_stale')]);
    }

    if ($clean === $locked->description) {
        return;
    }

    $locked->forceFill(['description' => $clean])->save();
```

**Explicit metadata-only activity row** (lines 94-99), reuse for `secret_acknowledged`, `archived`, `restored`:
```php
activity()
    ->useLog($locked->getMorphClass())
    ->performedOn($locked)
    ->causedBy($actor)
    ->event('description_changed')
    ->log('description_changed');
```

**Deviations for Phase 18 (from RESEARCH Pattern 1):** compare `version_no` (an int the page holds in `#[Locked] $loadedVersion`) instead of a content fingerprint; throw a dedicated `StaleArticle` instead of `ValidationException` (the page needs to tell stale from field errors); `expectedVersion` is a required argument; order is authorise, lock, stale check, normalise, no-op check, scan, write (version row insert with `version_no + 1`). `forceFill` is used because `version_no`, `body_md`, `sensitive` must not be mass-assignable.

---

### `Models/KnowledgeArticle.php` (model, CRUD, Admin-only with logged allowlist)

**Analog:** `app/Domain/Tasks/Models/TaskBilling.php`

**Imports / attributes / traits** (lines 5-20, 56-79):
```php
use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([...])]
#[LoggedAttributes([
    'task_id',
    'billing_type',
    ...
])]
final class TaskBilling extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'task_billing';
```
For the article: `#[LoggedAttributes(['kind','folder_id','client_id','project_id','client_visible','sensitive','version_no'])]`; never `title` or `body_md`. Add `SoftDeletes`. `#[Fillable]` should list nothing content-bearing (writes go through `forceFill` in `SaveArticle`). Docblock style: `@property` block, plus a paragraph stating why it is Admin-only and what is never logged.

**`KnowledgeArticleVersion`** and **`KnowledgeFolder`**: same `DeniesPartners` + `PartnerIsolated` header but without `LogsAllowlistedActivity` (their `ChannelMatrix` activity cell is `NA_ACTIVITY`). Version has no `updated_at` (set `public $timestamps = false` with `created_at` cast) and no mass-assignable attributes.

**DeniesPartners trait** (`app/Domain/Shared/Auth/DeniesPartners.php:26-31`):
```php
trait DeniesPartners
{
    use IsolatesPartners;

    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->whereRaw('1 = 0');
    }
}
```

---

### Migration `create_knowledge_*_tables.php` (migration, CRUD with guards)

**Analog:** `database/migrations/2026_10_27_000200_create_cash_receipts_table.php`

**Header and imports** (lines 5-9): `declare(strict_types=1); use App\Domain\Shared\Database\Immutability; Migration, Blueprint, DB, Schema`. Anonymous `return new class extends Migration`. A large block comment above explains rules, mutable columns and limits; copy that habit.

**Table style** (lines 72-73, 99-105):
```php
Schema::create('cash_receipts', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    ...
    $table->timestampsTz();

    $table->unique('number', 'cash_receipts_number_unique');
    $table->index('invoice_id', 'cash_receipts_invoice_id_index');
});
```

**Constraints added through `DB::statement`** (lines 107-119):
```php
DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_invoice_client_fk FOREIGN KEY (invoice_id, client_id) REFERENCES invoices (id, client_id)');
DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_lock_state_check CHECK (lock_state IN ('open', 'locked'))");
DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_purpose_check CHECK (btrim(purpose) <> '')");
```
Use the same for: `kind` IN list, `parent_id` structure CHECK, `char_length(title) <= 200`, `char_length(body_md) <= 100000`, composite FK `(project_id, client_id) REFERENCES projects (id, client_id)` (constraint `projects_id_client_unique` already exists), `client_visible`/`sensitive` CHECKs. Name every constraint `<table>_<what>_check|fk|unique`.

**Trigger function plus trigger, KP001 errcode** (lines 121-122, 157-159, 210):
```php
DB::unprepared(<<<'SQL'
    CREATE OR REPLACE FUNCTION kokpit_guard_cash_receipt_row() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN
      IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a cash receipt is never deleted' USING ERRCODE = 'KP001';
      END IF;
      ...
    END $$
    SQL);

DB::unprepared('CREATE TRIGGER cash_receipts_row_guard BEFORE INSERT OR UPDATE OR DELETE ON cash_receipts FOR EACH ROW EXECUTE FUNCTION kokpit_guard_cash_receipt_row()');
```
Use for: version table append-only function (UPDATE/DELETE refused), article `kind` immutability trigger, folder cycle/depth trigger (own SQLSTATE next to KP001/KP002).

**Truncate guard and down()** (lines 242, 245-255):
```php
DB::unprepared(Immutability::truncateGuardSql('cash_receipts'));
...
DB::unprepared(Immutability::dropTruncateGuardSql('cash_receipts'));
DB::unprepared('DROP TRIGGER IF EXISTS cash_receipts_row_guard ON cash_receipts');
Schema::dropIfExists('cash_receipts');
DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_cash_receipt_row()');
```
Table name limit: `Immutability::TABLE_MAX_LENGTH = 48` (`knowledge_article_versions` is 26, fine). `Immutability::guardTriggerSql()` is for state-column lifecycles and does not fit the version table; write a purpose-built function as in the cash receipt migration.

---

### `KnowledgeArticleResource.php` (resource, CRUD)

**Analog:** `app/Filament/Resources/ProjectResource.php`

**Access declaration and traits** (lines 75-81):
```php
#[AccessRule(Audience::AdminOnly, reason: 'Projects carry rates, ...')]
final class ProjectResource extends Resource
{
    use EnforcesResourceAccessRule;
```
Do NOT copy `$isGloballySearchable = true` or `SearchesGloballyWithoutAccents` (the article stays out of global search).

**Navigation / labels, Czech from lang** (lines 92-110):
```php
public static function getNavigationLabel(): string { return __('kokpit.projects.navigation_label'); }
public static function getNavigationIcon(): Heroicon { return Heroicon::OutlinedFolder; }
public static function getModelLabel(): string { return __('kokpit.projects.model_label'); }
```
Use `Heroicon::OutlinedBookOpen`, add `use InKnowledgeGroup;` for the group.

**Archived rows stay reachable** (lines 116-127). Never bare `withoutGlobalScopes()` (Arch test `QueryEscapeHatchTest`):
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
}
public static function getRecordRouteBindingEloquentQuery(): Builder
{
    return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
}
```
For the list, add `->select([...explicit columns, no body_md...])` through `modifyQueryUsing`.

**Table, filters, record actions, archive/restore wording** (lines 336-389):
```php
->recordActions([
    ViewAction::make(),
    EditAction::make(),
    self::archiveAction(DeleteAction::make()),
    self::restoreAction(RestoreAction::make()),
])
->defaultSort('name')
->emptyStateHeading(__('kokpit.projects.empty_heading'))
->emptyStateDescription(__('kokpit.projects.empty_description'));
...
public static function archiveAction(DeleteAction $action): DeleteAction
{
    return $action
        ->label(__('kokpit.projects.actions.archive'))
        ->modalHeading(__('kokpit.projects.actions.archive_heading'))
        ->modalDescription(__('kokpit.projects.actions.archive_description'))
        ->successNotificationTitle(__('kokpit.projects.notifications.archived'));
}
```
Deviation: archive/restore must call `ArchiveArticle`/`RestoreArticle` Actions (explicit activity row) instead of the stock `DeleteAction` default behaviour, so use `Action::make()->action(...)` with a confirmation modal, or `DeleteAction::...->using(...)`. Default sort `edited_at` desc; archived toggle via `TernaryFilter`/`TrashedFilter`-style filter (D-13: active by default). No bulk actions (UI-SPEC).

**Pages map** (lines 415-424): `'index' => ListX::route('/'), 'create' => ..., 'view' => ViewX::route('/{record}'), 'edit' => EditX::route('/{record}/edit')`.

---

### `Pages/EditKnowledgeArticle.php`, `CreateKnowledgeArticle.php`, `ViewKnowledgeArticle.php`, `ListKnowledgeArticles.php` (page, request-response)

**Analog:** `app/Filament/Resources/ClientResource/Pages/EditClient.php` and `CreateClient.php`

**Partner 403 before the record lookup** (EditClient lines 27-37). Put in `mount()` of every page:
```php
public function mount(int|string $record): void
{
    abort_unless(ClientResource::canAccess(), 403);

    parent::mount($record);
}
```
(Create/List pages: `mount(): void` with the same `abort_unless`.)

**Save through the domain Action, never the model** (EditClient lines 73-80, CreateClient lines 21-27):
```php
protected function handleRecordUpdate(Model $record, array $data): Model
{
    assert($record instanceof Client);

    return $this->withFormErrors(
        static fn (): Client => app(UpdateClient::class)->handle($record, ClientResource::actionData($data)),
    );
}
```
Page uses `RethrowsDomainValidation` trait (`$this->withFormErrors`). Replace with `app(SaveArticle::class)->handle(...)` passing `$this->loadedVersion`.

**Catch domain refusal, notify, halt** (analog `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php` lines 843-849):
```php
} catch (DomainException $exception) {
    // The document was issued in the meantime: nothing was written.
    Notification::make()->danger()->title($exception->getMessage())->send();

    throw new Halt;
}
```
Use for `StaleArticle` (persistent warning notification with an action `->url(view url, shouldOpenInNewTab: true)`) and `SecretGuardRefused` (store rule key and line only in `#[Locked] array $guardFindings`, plus `#[Locked] ?string $refusedBodyHash`, then `Halt`). `Halt` import is Filament's `Filament\Support\Exceptions\Halt` (confirm in `EditInvoice.php` header).

**Header actions** (EditClient lines 42-49): `getHeaderActions(): array` returning `ViewAction::make()` plus the resource's archive/restore factory methods.

Preview tab, `Tabs::livewireProperty('activeTab')`, unsaved-changes override `hasUnsavedDataChangesAlert(): bool { return true; }`: no repo analog, follow RESEARCH Pattern 5 (verified vendor lines cited there).

---

### `app/Filament/Concerns/InKnowledgeGroup.php` (trait)

**Analog:** `app/Filament/Concerns/InSignalGroup.php` (full file, 18 lines):
```php
namespace App\Filament\Concerns;

use UnitEnum;

trait InSignalGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('kokpit.signal.navigation_group');
    }
}
```
Copy with `__('knowledge.navigation_group')` (or wherever the planner places the key). Group order must also be pinned with `->navigationGroups([...])` in `AdminPanelProvider` (RESEARCH Pitfall 12); open `app/Providers/Filament/AdminPanelProvider.php` near line 133 when planning that edit.

---

### Concurrency test `KnowledgeSaveConcurrencyTest.php`

**Analog:** `tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` (plus the worker `tests/Concurrency/knowledge-save-worker.php` in the style of `tests/Concurrency/*-worker.php`; names as planned in 18-05 R-18-05-7)

Key conventions (lines 1-50): plain Pest file, no `RefreshDatabase` wrapper on purpose; `Symfony\Component\Process\Process` workers behind one barrier; constants like `const X_ROUNDS = 5; const X_BARRIER_SECONDS = 5.0; const X_TIMEOUT_SECONDS = 180;`; a `scratch()` helper tracking committed rows; cleanup in a transaction that disables user triggers on guarded tables, deletes the test's own rows, re-enables triggers and asserts all triggers are enabled. For Phase 18 the guarded table is `knowledge_article_versions` (its append-only trigger blocks plain cleanup). Assertions: N parallel saves of one article with the same `expectedVersion` end with exactly one version row written and the others stale; versions numbered gap-free.

---

### Declaration edits (modify existing files; each fails a test when forgotten)

Analogs are the `Signal*`/`TaskBilling` entries already in each file.

- `app/Providers/AccessServiceProvider.php` (~line 137 block): follow
  ```php
  // Tracked time, its rates and its billing are Admin-only (TI-07): a Partner is granted nothing.
  Gate::policy(TimeEntry::class, AdminOnlyPolicy::class);
  ```
  Add three `Gate::policy(KnowledgeArticle::class|KnowledgeArticleVersion::class|KnowledgeFolder::class, AdminOnlyPolicy::class)` with a comment.
- `app/Domain/Shared/Database/MorphMap.php`: add to `MAP` (short singular snake_case aliases): `'knowledge_folder' => KnowledgeFolder::class, 'knowledge_article' => ..., 'knowledge_article_version' => ...` plus `use` lines in alphabetical position.
- `app/Domain/Shared/Tags/TagType.php`: add `case Knowledge = 'knowledge';` and update the docblock (Admin only, never shown to a Partner).
- `tests/Arch/ModelDeclarationTest.php`: add the three models to the "finds every model" list (around lines 107-108 where `SignalSetting::class, SignalTask::class` sit) and to the PartnerIsolated `toContain(...)` list at line 150.
- `tests/Support/PartnerMatrix.php`: panel entry like line 211 `SignalSettingsPage::class => ['admin' => true, 'partner' => false, 'reason' => '...']` for `KnowledgeArticleResource`; model entries in the Admin-only list like lines 507-509 (`SignalTask::class => 'Personal planner of the Admin.'`).
- `tests/Support/ChannelMatrix.php`: like line 167:
  `SignalTask::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],`
  Article: `activity` applicable (logging model); version and folder: `NA_ACTIVITY`-style `$activityNa`.
- `tests/Support/CanaryRegistry.php`: closure fixture pattern at line 509 (`SignalTask::class => static function (string $clientId, string $canary): void { ... }`); the article fixture must be client-linked and carry the canary in `body_md`. Mirror in `tests/Isolation/CanaryRegistryTest.php` expected-models list (RESEARCH says ~line 115).
- `tests/Arch/ActivityAllowlistTest.php:54`: add `KnowledgeArticle::class` to the hard-coded logging-models list.
- `lang/cs/enums.php`: add an `article_kind` block, following the flat `'document_kind' => ['invoice' => 'Faktura', ...]` style. `lang/cs/knowledge.php` is new; all UI-SPEC Czech strings, English identifiers.
- `.gitignore`: add `/public/css/app/` and `/public/js/app/` next to the existing `/public/css/filament/` entries.

## Shared Patterns

### Admin-only fail-closed access
**Source:** `app/Domain/Shared/Auth/DeniesPartners.php` + `AdminOnlyPolicy` registered in `AccessServiceProvider`; resource-level `#[AccessRule(Audience::AdminOnly, reason: ...)]` with `EnforcesResourceAccessRule` (`app/Filament/Concerns/EnforcesResourceAccessRule.php`: `AccessRules::allows(static::class) && parent::canAccess()`).
**Apply to:** all three models, the resource and every page (`abort_unless(Resource::canAccess(), 403)` in `mount()`).

### One write seam, no data array
**Source:** `UpdateTaskDescription` (docblock lines 15-51 states the rule: the Action takes typed arguments so no other attribute can ride along; lock order and what is logged are documented in the class comment).
**Apply to:** `SaveArticle`, `ArchiveArticle`, `RestoreArticle`. Pages never call `->save()` on the model.

### Metadata-only activity
**Source:** `LogsAllowlistedActivity` + `#[LoggedAttributes([...])]` on `TaskBilling`; explicit `activity()->useLog(...)->event(...)->log(...)` row in `UpdateTaskDescription` lines 94-99 (no properties).
**Apply to:** article model (allowlist without `title`/`body_md`), `secret_acknowledged`, `archived`, `restored` rows. The audit forbidden-name regex (`tests/Support/AuditDeclaration.php:30`) allows a column named `sensitive`.

### DB-level integrity
**Source:** cash receipts migration (named CHECKs, KP001 trigger, `Immutability::truncateGuardSql`).
**Apply to:** all three knowledge tables; every FK `RESTRICT`, no cascade, archive = soft delete only.

### Czech copy
**Source:** `__('kokpit.<area>.<key>')` in `ProjectResource`; `lang/cs/enums.php`.
**Apply to:** every user-facing string; code, enums, routes and tests stay English. Fixtures for secrets are assembled at runtime from fragments (D-05, `tests/Support/SecretFixtures.php` new, style of the other `tests/Support/*Fixtures.php`).

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Knowledge/Guard/*` (SecretScanner, SecretFinding, SecretRule, Severity) | utility | transform | No scanning or regex-rule class exists; use RESEARCH Pattern 3 (rule table, fail-closed on `preg_last_error()`, DTO without value field) |
| `app/Domain/Knowledge/Support/KnowledgeMarkdown.php`, `CalloutExtension`, `CodeBlockRenderer`, `MarkdownNormaliser` | utility | transform | No Markdown renderer exists in the app; follow RESEARCH Pattern 2/4 and Code Example 1 (executed spike). Must be unit-testable without the app (translator `Closure` injected) |
| `resources/css/kokpit-prose.css`, `resources/js/knowledge-copy.js` | asset | event-driven | No custom FilamentAsset exists yet; RESEARCH Pattern 5 "Assets" (`FilamentAsset::register([Css::make(...), Js::make(...)])` in a service provider) |
| Preview tab (`Tabs::livewireProperty`) and `hasUnsavedDataChangesAlert()` override | page behaviour | request-response | No existing Tabs preview or unsaved-changes alert in the repo; RESEARCH Pattern 5 (vendor lines verified there) |
| Folder cycle/depth trigger (recursive CTE, advisory lock) | migration | CRUD | Closest is the KP001 trigger style of cash receipts for syntax only; logic per RESEARCH Pattern 6 incl. the subtree-height gap |
| `app/Domain/Knowledge/Exceptions/*` | exception | n/a | Repo uses `ValidationException` and `DomainException`; two dedicated exceptions are new by design (messages generic, never carry a value) |

## Metadata

**Analog search scope:** `app/Domain/{Tasks,Signal,Shared,Invoicing}`, `app/Filament/{Resources,Concerns}`, `database/migrations`, `tests/{Concurrency,Support,Arch}`, `lang/cs`
**Files read:** `UpdateTaskDescription`, `ProjectResource`, `EditClient`, `CreateClient` (head), `InSignalGroup`, `EnforcesResourceAccessRule`, `DeniesPartners`, `TaskBilling` (head), `SignalTask`, `Immutability`, cash receipts migration, `EditInvoice` (Halt excerpt), `MorphMap`, `TagType`, `AccessServiceProvider` (excerpt), `CashReceiptNumberingConcurrencyTest` (head), plus grep of the test-support declaration points
**Not re-verified here:** line numbers in test-support files (`PartnerMatrix`, `ChannelMatrix`, `CanaryRegistry`, `ModelDeclarationTest`) come from grep output at mapping time; the planner should re-open them before editing
**Pattern extraction date:** 2026-11-25
