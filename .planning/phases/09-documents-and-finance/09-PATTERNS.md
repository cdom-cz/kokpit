# Phase 9: Documents and Finance - Pattern Map

**Mapped:** 2026-10-16
**Files analyzed:** 40 (new/modified, grouped)
**Analogs found:** 36 / 40 (all analogs are git-tracked source)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `database/migrations/2026_10_16_*_create_documents_table.php` | migration | CRUD | `database/migrations/2026_10_15_000200_create_report_exports_table.php` (+ `..._create_tasks_table.php` composite FK) | exact |
| `database/migrations/*_create_transaction_categories_table.php`, `*_create_transactions_table.php` | migration | CRUD | `2026_10_10_000100_create_tasks_table.php` (`tasks_parent_fk`, depth CHECK), `2026_10_10_000400_create_task_billing_table.php` (money pair) | exact |
| `database/migrations/*_create_document_archives_table.php` | migration | batch | `2026_10_15_000200_create_report_exports_table.php` | exact |
| `app/Domain/Documents/Models/Document.php` | model | CRUD, Partner-scoped | `app/Domain/Tasks/Models/TaskComment.php` | exact |
| `app/Domain/Documents/Models/DocumentArchive.php` | model | batch | `app/Domain/Reports/Models/ReportExport.php` | exact |
| `app/Domain/Documents/Policies/DocumentPolicy.php` | policy | request-response | `app/Domain/Tasks/Policies/TaskCommentPolicy.php` | exact |
| `app/Domain/Documents/Actions/{AttachDocuments,RenameDocument,SetDocumentVisibility,DeleteDocument,RequestDocumentArchive}.php` | service (Action) | CRUD | `app/Domain/Tasks/Actions/AddTaskComment.php` | role-match |
| `app/Domain/Documents/Jobs/BuildDocumentArchive.php` | job | batch, file-I/O | `app/Domain/Reports/Jobs/BuildReportExport.php` | exact |
| `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` | job | batch | `app/Domain/Reports/Jobs/PruneExpiredReportExports.php` | exact |
| `app/Domain/Documents/Notifications/DocumentArchiveReadyNotification.php`, `PartnerUploadedFilesNotification.php` | notification | event-driven | `app/Domain/Reports/Notifications/ReportExportReadyNotification.php` | exact |
| `app/Domain/Documents/Http/DocumentFileController.php` + route in `AdminPanelProvider` | controller | streaming | `AdminPanelProvider.php` lines 66-72 (signed route) ; no streaming analog | partial |
| `app/Domain/Documents/Support/DocumentUrl.php` | utility | request-response | `AcceptInvitation` signed route in `AdminPanelProvider` | partial |
| `app/Domain/Finance/Models/Transaction.php`, `TransactionCategory.php` | model | CRUD | `app/Domain/Tasks/Models/TaskBilling.php` (MoneyCast), `app/Domain/Tasks/Models/Task.php` (SortableTrait, LogsAllowlistedActivity), `app/Domain/Clients/Models/Contact.php` (DeniesPartners + LoggedAttributes) | role-match |
| `app/Domain/Finance/Actions/{CreateTransaction,UpdateTransaction,DeleteTransaction,ConvertToCzk,ArchiveCategory}.php` | service | CRUD | `app/Domain/Tasks/Actions/AddTaskComment.php`; `app/Domain/ExchangeRates/ExchangeRateConverter.php` | role-match |
| `app/Domain/Finance/Queries/FinanceOverview.php` | service | transform/aggregate | `app/Domain/Reports/Queries/TimeReportReader.php` | role-match |
| `app/Filament/Resources/DocumentResource.php` (+Pages) | resource | CRUD list | `app/Filament/Resources/ReportExportResource.php` | role-match |
| `app/Filament/Resources/DocumentArchiveResource.php` | resource | list | `app/Filament/Resources/ReportExportResource.php` | exact |
| `app/Filament/Resources/TransactionResource.php`, `TransactionCategoryResource.php` | resource | CRUD | `app/Filament/Resources/ExchangeRateResource.php` / `ProjectResource.php` | role-match |
| `app/Filament/RelationManagers/{Client,Project,Task,Transaction}DocumentsRelationManager.php` + trait | relation manager | CRUD | `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php` | role-match |
| `app/Filament/Partner/Resources/*/RelationManagers/Partner*DocumentsRelationManager.php` | relation manager | read | `app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php` | exact |
| modify `TaskCommentsRelationManager`, `PartnerTaskCommentsRelationManager`, `TaskResource`, `PartnerTaskResource`, `ViewPartnerTask` (replace `fileAttachments(false)` markers) | component | request-response | self (lines listed below) | exact |
| `app/Filament/Pages/FinanceOverviewPage.php` | page | request-response | `app/Filament/Pages/TimeReportPage.php` | exact |
| Finance Stats/Chart widgets | widget | transform | `app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php` | role-match (stats exact; no Chart widget analog) |
| Documents stats widget (storage totals) | widget | transform | same `ExchangeRateStats.php` | role-match |
| modify `app/Domain/Shared/Database/MorphMap.php` | config | - | self | exact |
| modify `app/Providers/AccessServiceProvider.php` | config | - | self | exact |
| modify `routes/console.php` (schedule prune) | config | batch | lines 83-87 of itself | exact |
| modify `config/kokpit.php` (documents + finance keys) | config | - | `exports` block, lines 239-268 | exact |
| `config/livewire.php` (new) | config | - | no analog | none |
| tests: `tests/Feature/Documents/*`, `tests/Feature/Finance/*`, `tests/Feature/Schema/*` | test | - | `tests/Support/RawSql.php`, existing Schema tests | role-match |
| modify `tests/Support/CanaryRegistry.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Arch/ActivityAllowlistTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Support/JobDeclaration.php` | test | - | self (pinned lists) | exact |

## Pattern Assignments

### `Document` model (model, Partner-scoped CRUD)

**Analog:** `app/Domain/Tasks/Models/TaskComment.php`

**Imports + declaration** (lines 5-18, 39-42):
```php
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

#[Fillable(['body'])]
final class TaskComment extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners;
```
Copy: only the name/description are `#[Fillable]`; owner, client_id, uploader, flags are set with `forceFill` in the Action (as `AddTaskComment` does). Add `InteractsWithMedia` + `HasMedia` and `LogsAllowlistedActivity` (see Contact below).

**Partner scope delegating to already-scoped queries** (lines 50-54):
```php
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query->where($this->qualifyColumn('is_internal'), false)
        ->whereIn($this->qualifyColumn('task_id'), Task::query()->select('tasks.id'));
}
```
Document version = RESEARCH Pattern 2 (visible_to_client true AND owner in scoped `Project`/`Task`/`TaskComment` subqueries via `owner_type` alias).

**Allowlisted activity** (analog `app/Domain/Clients/Models/Contact.php` lines 7-8, 48-56, 59):
```php
use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
#[LoggedAttributes(['client_id','name','email', ...])]   // Document: name, visible_to_client, owner_type, size_bytes (not description)
    use DeniesPartners, LogsAllowlistedActivity;           // Document uses IsolatesPartners instead of DeniesPartners
```
Sortable trait for categories: `Task.php` lines 28-29, 96-115 (`SortableTrait`, `public array $sortable = ['order_column_name' => 'position', 'sort_when_creating' => ...]`).

**Media stays closed:** `app/Domain/Shared/Models/Media.php` (`DeniesPartners, HasUuids`) is NOT changed; load media only inside `PartnerContext::runAsSystem()` (RESEARCH Pitfall 3).

---

### `documents`, `transaction_categories`, `transactions`, `document_archives` migrations

**Analog:** `database/migrations/2026_10_15_000200_create_report_exports_table.php`

**Table + constraints skeleton** (lines 29-57):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('status', 16)->default('pending');
            $table->string('path', 255)->nullable();
            $table->timestampTz('expires_at', 0)->index('report_exports_expires_at_index');
            $table->timestampsTz();
            $table->index(['user_id', 'created_at'], 'report_exports_user_created_index');
        });
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'ready', 'failed'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_ready_check CHECK (status <> 'ready' OR (path IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_path_check CHECK (path IS NULL OR path ~ '^report-exports/[0-9a-f-]{36}\\.(csv|xlsx)\$')");
    }
```
`document_archives` copies this verbatim with `path ~ '^document-archives/[0-9a-f-]{36}\.zip$'`. Use the explanatory header comment block style (lines 10-28).

**Composite FK (categories type/depth, transaction type match, project-belongs-to-client)** — analog `database/migrations/2026_10_10_000100_create_tasks_table.php` lines 88-90:
```php
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_depth_check CHECK (depth = CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END)');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_ident_unique UNIQUE (id, project_id, depth)');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_fk FOREIGN KEY (parent_id, project_id, parent_depth) REFERENCES tasks (id, project_id, depth) ON DELETE RESTRICT');
```
(`parent_depth` is a stored generated column there; see lines before 84 for its definition.) Categories use `(id, type, depth)`; transactions use `(category_id, type)` and `(project_id, client_id)` against new `projects_id_client_unique`.

**Money column pair** — analog `database/migrations/2026_10_10_000400_create_task_billing_table.php` lines 41-58:
```php
$table->char('hourly_rate_currency', 3)->nullable();
DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_{$name}_currency_check CHECK ({$name}_currency ~ '^[A-Z]{3}\$')");
```
Transactions: `amount_minor` bigint + `amount_currency` char(3), plus CZK snapshot columns (RESEARCH Pattern 7).

**Comment-style row guard / system category freeze:** `app/Domain/Shared/Database/Immutability.php` line ~40, `guardTriggerSql(string $table, string $stateColumn, string $openState, array $mutableColumns)`; open state must match `^[a-z_]+$`, so `'false'` is accepted.

---

### `DocumentPolicy` (policy)

**Analog:** `app/Domain/Tasks/Policies/TaskCommentPolicy.php`

**Pattern** (lines 24-47):
```php
final class TaskCommentPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof TaskComment || $record->is_internal || $user->client_id === null) {
            return false;
        }
        // The relation goes through the Partner-scoped Task query: invisible task is null here.
        $task = $record->task;
        return $task instanceof Task && $user->can('view', $task);
    }

    public function create(User $user): bool { return true; }
}
```
Register in `app/Providers/AccessServiceProvider.php` boot (lines ~60-90): `Gate::policy(TaskComment::class, TaskCommentPolicy::class);` -> add `Gate::policy(Document::class, DocumentPolicy::class);`. Finance models and `DocumentArchive`: `Gate::policy(X::class, AdminOnlyPolicy::class);` (as for `Client`, `ProjectBilling`).

---

### Documents Actions (`AttachDocuments`, `DeleteDocument`, ...)

**Analog:** `app/Domain/Tasks/Actions/AddTaskComment.php`

**Pattern** (lines 40-60): authorize as actor first, then a `DB::transaction`, then `forceFill` of server-owned fields:
```php
public function handle(User $actor, Task $task, string $body, bool $internal = false, bool $escalation = false): TaskComment
{
    Gate::forUser($actor)->authorize('comment', $task);
    ...
    return DB::transaction(function () use (...): TaskComment {
        $comment = new TaskComment(['body' => $clean]);
        $comment->forceFill([
            'task_id' => $task->getKey(),
            'author_id' => $actor->getKey(),
            'is_internal' => $internal,
        ])->save();
```
Validation errors: `Illuminate\Validation\ValidationException::withMessages(['files' => ...])`. Notification after insert inside the transaction via a notifier (see `TaskNotifier`). Media write and delete run inside `PartnerContext::runAsSystem()` after the policy passed (`app/Domain/Shared/Auth/PartnerContext`, used in `BuildReportExport` imports).

---

### `BuildDocumentArchive` job (job, batch + file-I/O)

**Analog:** `app/Domain/Reports/Jobs/BuildReportExport.php`

**Imports/attributes/class** (lines 10-11, 31, 75-77, 104):
```php
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Queue\Attributes\Timeout;

#[Idempotent(how: 'identified by the export id; a record that is not pending is skipped and the file path is the export id, so a rerun cannot duplicate a file')]
#[Timeout(240)]
final class BuildReportExport extends KokpitJob
{
    public function __construct(public readonly string $exportId) {}
```

**Core flow** (lines 106-139): skip unless pending; temp file via `tempnam(sys_get_temp_dir(), ...)`; build; `store()`; `finally { unlink }`:
```php
$export = ReportExport::query()->find($this->exportId);
if ($export === null || $export->status !== ReportExportStatus::Pending) { return; }
$temporary = tempnam(sys_get_temp_dir(), 'kokpit-export-');
if ($temporary === false) { throw new RuntimeException('A temporary file for the export could not be created.'); }
try { ...; $this->store($export, $temporary); } finally { if (is_file($temporary)) { unlink($temporary); } }
```

**Mark ready + bell in one transaction** (lines 141-150):
```php
DB::transaction(function () use ($export, ...): void {
    $locked = ReportExport::query()->whereKey($export->getKey())->lockForUpdate()->first();
    if ($locked === null || $locked->status !== ReportExportStatus::Pending) { return; }
    $locked->markReady($locked->storagePath(), $count, TimerClock::now());
    User::query()->find($locked->user_id)?->notifyNow(new ReportExportReadyNotification(...));
```
Failure path: `failed()` + `ReportExportFailedNotification` (same folder). Register job in `tests/Support/JobDeclaration.php`. Replace OpenSpout with `ZipStream` (RESEARCH Pattern 5).

---

### `DocumentArchive` model and `PruneExpiredDocumentArchives`

**Analog:** `app/Domain/Reports/Models/ReportExport.php` (copy `ttlDays()`, `storagePath()`, `isDownloadable()`, `markReady()`, `markFailed()`, `creating` hook for `expires_at`, `OwnedByUser`) and `app/Domain/Reports/Jobs/PruneExpiredReportExports.php`:

```php
#[Idempotent(how: 'deletes the file and the row of every export past expires_at; a second run finds nothing to delete and a missing file is skipped')]
final class PruneExpiredReportExports extends KokpitJob
{
    private const int CHUNK = 100;
    public function handle(): void
    {
        $disk = Storage::disk();
        ReportExport::query()->where('expires_at', '<', now())->chunkById(self::CHUNK, static function ($exports) use ($disk): void {
            foreach ($exports as $export) {
                if ($export->path !== null) { $disk->delete($export->path); }   // file first
                $export->delete();
            }
        });
    }
}
```
Schedule (analog `routes/console.php` lines 83-87):
```php
Schedule::job(new PruneExpiredReportExports)->daily()->name('kokpit-report-export-prune')->onOneServer();
```

---

### Notifications (`DocumentArchiveReadyNotification`, `PartnerUploadedFilesNotification`)

**Analog:** `app/Domain/Reports/Notifications/ReportExportReadyNotification.php` (lines 24-65): scalars in constructor, `via` returns `['database']`, `toDatabase` builds `FilamentNotification::make()->title(__(...))->body(__(..., ['x' => e($this->x)]))->actions([Action::make('open')->url($this->url)])->getDatabaseMessage()`. Not queueable; sent with `notifyNow()`. Partner upload bell: scalars only (uploader, task reference, file count); no file names.

---

### Documents list Resource and archives Resource (resource)

**Analog:** `app/Filament/Resources/ReportExportResource.php`

**Header/access** (lines 34-37, 45-51, 76-79):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ReportExportResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $model = ReportExport::class;
    protected static ?string $slug = 'report-exports';
    protected static bool $isGloballySearchable = false;   // keep false for documents (no leak via search)
    public static function canCreate(): bool { return false; }
```
**Table** (lines 83-119): `TextColumn::make('created_at')->dateTime(self::DATE_FORMAT, self::DISPLAY_TIMEZONE)->sortable()`, `->defaultSort`, `->poll(...)` while pending, `->emptyStateHeading(__('kokpit.exports.empty.heading'))`. Czech texts in `lang/cs`.

**Download action caveat:** lines 127-152 use `$disk->download(...)` in a Livewire action. Phase 9 deliberately does NOT copy this for documents/ZIP (RESEARCH Pitfall 2): the row action only authorizes and redirects to the signed route. Keep the re-read via scoped query + `isDownloadable` check pattern (lines 137-149) for `DocumentArchiveResource`.

Pages folder pattern: `app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php` (`getPages()` lines 155-160).

---

### Soubory relation managers (client, project, task, transaction; Partner read-only variants)

**Analog:** `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php`

**Access + class** (lines 4-45):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ContactsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    protected static string $relationship = 'contacts';

    public function isReadOnly(): bool { return false; }   // needed: relation managers on view pages are read-only by default

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.contacts.relation_title');
    }

    public function form(Schema $schema): Schema { return $schema->components($this->fields()); }
```
Actions are written only through domain Actions (class docblock lines 36-41), not Filament's default model writes. `#[AccessRule]` is not inherited, so one `final` class per host with a shared trait (RESEARCH structure). Partner variant analog: `app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php`.

---

### Comment forms and Task forms (modify)

**Analog/self:** replace the markers, keep `RichEditor::fileAttachments(false)` and add a separate `FileUpload` field.
- `app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php` lines 40-59 (`commentFields()` returns `[RichEditor::make('body')->fileAttachments(false)..., Toggle::make('is_internal')...]`; comment at line 43 "No file attachments ... (A10)" to be removed).
- `app/Filament/Resources/TaskResource.php:210`
- `app/Filament/Partner/Resources/PartnerTaskResource.php:136`
- `app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php:50`
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php:73,128`
Trait for the thread rendering: `App\Filament\Concerns\RendersTaskCommentThread`; the Action path is `AddTaskComment`.

---

### `FinanceOverviewPage` (page, request-response)

**Analog:** `app/Filament/Pages/TimeReportPage.php`

**Header/access/URL state** (lines 56-82):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
class TimeReportPage extends Page
{
    use EnforcesPageAccessRule;
    protected static ?string $slug = 'time-report';
    protected static ?int $navigationSort = 42;
    protected string $view = 'filament.pages.time-report';

    #[Url]
    public mixed $client = null;     // untyped on purpose: Livewire properties are client-controlled, re-validated by ReportFilters
```
Copy: `FinanceFilters::fromInput()` (analog `app/Domain/Reports/ReportFilters.php`, `fromStored`), falls back to current Prague month. Note RESEARCH proposes `HasFiltersForm`; the analog uses `#[Url]` untyped properties; either way revalidate server side.

### Stat widgets (documents totals, finance tiles)

**Analog:** `app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php`

```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ExchangeRateStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;
    protected static bool $isLazy = false;
    /** @return array<Stat> */
    protected function getStats(): array { return [ Stat::make($label, $value)->description(...), ... ]; }
```
Registered as header widget of the page, not panel-discovered (docblock lines 27-28). No `ChartWidget` exists in the repo: use RESEARCH Pattern 8 with the same `#[AccessRule]` + `EnforcesWidgetAccessRule`.

---

### CZK snapshot (`ConvertToCzk`)

**Analog:** `app/Domain/ExchangeRates/ExchangeRateConverter.php`

```php
public function toCzk(Money $amount, string $day): Money
{
    if ($amount->currency === self::CZK) { return $amount; }
    $rate = $this->rateFor($amount->currency, $day);          // line 73; throws ExchangeRateMissing
    return $amount->convert($rate['rate'], self::CZK, $rate['unit_amount']);
}
```
Use `rateFor()` (not `toCzk`) so the exact rate, unit amount and rate day are stored as the snapshot; catch `App\Domain\ExchangeRates\ExchangeRateMissing`. Money columns via `MoneyCast` as in `TaskBilling.php` lines 107-115 (`'hourly_rate' => MoneyCast::class`) over `*_minor` + `*_currency`.

---

### Shared registry edits

- `app/Domain/Shared/Database/MorphMap.php` lines 47-76: add one alias line each, e.g. `'document' => Document::class, 'document_archive' => DocumentArchive::class, 'transaction' => Transaction::class, 'transaction_category' => TransactionCategory::class` (`'task_comment'` already exists at line 65).
- `config/kokpit.php`: add `documents` and `finance` blocks after the `exports` block (lines 239-268) in the same commented style (`'ttl_days' => 7`, etc.): max_size_kb, max_files_per_upload, signed_url_ttl_seconds, archive ttl_days/max_files/max_bytes.
- Tests to extend (pinned lists): `tests/Support/CanaryRegistry.php` (`fixtures()`), `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Arch/ActivityAllowlistTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Support/JobDeclaration.php`. Raw SQL constraint assertions: `Tests\Support\RawSql::expectSqlState($state, fn)` (savepoint-wrapped).

## Shared Patterns

### Fail-closed access attributes
**Source:** `ReportExportResource.php` line 34-37, `ContactsRelationManager.php` line 4x, `TimeReportPage.php` line 56, `ExchangeRateStats.php` line 30
**Apply to:** every new Resource, Page, Widget, relation manager (traits `EnforcesResourceAccessRule`, `EnforcesPageAccessRule`, `EnforcesWidgetAccessRule`, `EnforcesRelationManagerAccessRule`, all in `App\Filament\Concerns`).

### Queued job contract
**Source:** `PruneExpiredReportExports.php` lines 21-22, `BuildReportExport.php` lines 75-77
**Apply to:** `BuildDocumentArchive`, `PruneExpiredDocumentArchives`: `extends KokpitJob`, `#[Idempotent(how: '...')]`, optional `#[Timeout(240)]`.

### Bell notification
**Source:** `ReportExportReadyNotification.php` lines 40-65
**Apply to:** both new notifications; `e()` once on scalars, database channel only.

### Domain Action authorize-then-transact
**Source:** `AddTaskComment.php` lines 40-60
**Apply to:** all Document and Finance Actions; Filament only adapts.

### Activity log allowlist
**Source:** `Contact.php` lines 7-8, 48-59 (`#[LoggedAttributes([...])]` + `LogsAllowlistedActivity`)
**Apply to:** `Document`, `Transaction`, `TransactionCategory`.

### Database as guarantee
**Source:** report_exports and tasks migrations above
**Apply to:** all new tables (uuidv7 default, CHECK, composite FK, path-shape CHECK, restrict FKs).

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `DocumentFileController` (streamed, signed, authenticated download) | controller | streaming | Only Livewire-action downloads exist (`ReportExportResource` lines 127-152). Nearest: signed panel route in `AdminPanelProvider.php` lines 66-72 (`->routes(fn () => Route::get(...)->middleware([..., 'signed', 'throttle:invitation'])->name(...))`); use `authenticatedRoutes()` per RESEARCH Pattern 4 and define a `documents` rate limiter next to the `invitation` limiter. |
| `config/livewire.php` | config | - | No Livewire config or `FileUpload` exists in the repo; follow RESEARCH Code Example. |
| Filament `ChartWidget` (finance bar chart) | widget | transform | No chart widget exists; follow RESEARCH Pattern 8. |
| ZipStream usage | utility | streaming | No ZIP code; follow RESEARCH Pattern 5 inside the `BuildReportExport` job shape. |
| `FileUpload` with `storeFiles(false)` | component | file-I/O | First upload field in repo. |

## Metadata

**Analog search scope:** `app/Domain/{Reports,Tasks,Clients,ExchangeRates,Shared}`, `app/Filament/{Resources,Pages,Partner,Widgets}`, `app/Providers`, `database/migrations`, `routes/console.php`, `config/kokpit.php`, `tests/Support`
**Files scanned:** about 25 read in full or by section
**Tracked-source gate:** all named analog paths are under `app/`, `database/`, `routes/`, `config/`, `tests/` (no gitignored `.gsd` capability mirrors); `TimeReportPage.php` and `ExchangeRateStats.php` verified with `git ls-files`.
**Pattern extraction date:** 2026-10-16
