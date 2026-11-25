# Phase 19: Folders, Tags and Version History - Pattern Map

**Mapped:** 2026-11-25
**Files analyzed:** 27 (new or modified)
**Analogs found:** 25 / 27

All analog paths are git-tracked source under `app/`, `tests/`, `database/`. Line numbers refer to the repository state at mapping time.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php` | migration | batch (DDL) | `database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php` | exact |
| `app/Domain/Knowledge/Actions/ArticleClassification.php` | model (value object) | transform | `app/Domain/Knowledge/Actions/ArticleInput.php` | exact |
| `app/Domain/Knowledge/Actions/ArticleInput.php` (modify) | model (value object) | transform | itself | exact |
| `app/Domain/Knowledge/Actions/SaveArticle.php` (modify) | service (Action) | CRUD | itself | exact |
| `app/Domain/Knowledge/Actions/SaveOutcome.php` (modify) | enum | transform | itself | exact |
| `app/Domain/Knowledge/Exceptions/StaleArticle.php` (modify) | exception | request-response | itself | exact |
| `app/Domain/Knowledge/Models/KnowledgeArticle.php` (modify: `HasTags`, `redaction_no`, `knowledgeArticles()` inverse) | model | CRUD | `app/Domain/Tasks/Models/Task.php` (lines 106-108, 158-165) | exact |
| `app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php` | model | CRUD (append-only) | `app/Domain/Knowledge/Models/KnowledgeArticleVersion.php` | exact |
| `app/Domain/Knowledge/Actions/CreateFolder.php`, `RenameFolder.php`, `MoveFolder.php`, `DeleteFolder.php` | service (Action) | CRUD | `app/Domain/Tasks/Actions/ArchiveTask.php` | role-match |
| `app/Domain/Knowledge/Exceptions/FolderMoveRefused.php`, `FolderNotEmpty.php` | exception | request-response | `app/Domain/Knowledge/Exceptions/StaleArticle.php` | exact |
| `app/Domain/Knowledge/Queries/FolderTree.php` | service (query) | transform | `app/Domain/TimeTracking/Queries/TimeTotals.php` | role-match |
| `app/Domain/Knowledge/Actions/RestoreVersion.php` | service (Action) | CRUD | `app/Domain/Knowledge/Actions/SaveArticle.php` | role-match |
| `app/Domain/Knowledge/Actions/RedactVersion.php` | service (Action) | CRUD | `app/Domain/Knowledge/Actions/SaveArticle.php` (writeAcknowledgment, inTransaction) | role-match |
| `app/Domain/Knowledge/Support/VersionDiff.php` | utility | transform | `app/Domain/Knowledge/Guard/SecretScanner.php` (line split) | partial |
| `app/Filament/Resources/KnowledgeArticleResource.php` (modify: form section, table filters, pages, sub-navigation) | component (resource) | CRUD | itself; `TransactionResource.php` for dependent selects | exact |
| `app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php` (modify) | component (page) | request-response | itself | exact |
| `.../Pages/ListArticleVersions.php`, `ViewArticleVersion.php`, `CompareArticleVersions.php` | component (page) | request-response | `Pages/ListKnowledgeArticles.php` + `ViewKnowledgeArticle.php` | role-match |
| `.../Pages/CreateKnowledgeArticle.php`, `EditKnowledgeArticle.php` (modify: prefill, `loadedRedactionNo`) | component (page) | request-response | themselves (`loadedVersion` handling) | exact |
| `app/Filament/Resources/ClientResource/RelationManagers/ClientKnowledgeRelationManager.php` | component (relation manager) | CRUD (read) | `ClientProjectsRelationManager.php` | exact |
| `app/Filament/Resources/ProjectResource/RelationManagers/ProjectKnowledgeRelationManager.php` | component (relation manager) | CRUD (read) | `ClientProjectsRelationManager.php` | exact |
| `app/Domain/Clients/Models/Client.php`, `app/Domain/Projects/Models/Project.php` (modify: `knowledgeArticles()` HasMany) | model | CRUD | `KnowledgeFolder::articles()` (HasMany) | role-match |
| `resources/views/filament/knowledge/folder-tree.blade.php`, `version-diff.blade.php`, `redaction-preview.blade.php` | component (Blade) | request-response | existing `resources/views/filament/knowledge/*.blade.php` (escaped `{{ }}` only) | role-match |
| `resources/css/kokpit-diff.css` + `KnowledgeServiceProvider::boot()` asset registration | config | n/a | `FilamentAsset::register` usage elsewhere | role-match |
| `lang/cs/knowledge.php` (modify) | config | n/a | itself | exact |
| `tests/Support/PartnerMatrix.php` (modify) | test support | n/a | itself (lines 204, 250, 259, 514-515) | exact |
| `tests/Support/RouteRules.php` (modify) | test support | n/a | itself | exact |
| `tests/Arch/KnowledgeSeamTest.php` (modify) | test | n/a | itself (lines 28, 189, 393-402, 430) | exact |
| `tests/Feature/Knowledge/*` and `tests/Feature/Schema/KnowledgeTablesTest.php` (new tests) | test | n/a | existing `tests/Feature/Knowledge/*Test.php` | exact |
| `tests/Concurrency/knowledge-save-worker.php` + `KnowledgeSaveConcurrencyTest.php` (modify/extend) | test | event-driven | themselves | exact |

## Pattern Assignments

### `database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php` (migration, DDL)

**Analog:** `database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php`

**Constraint and trigger style** (lines 55-72): named constraints through `DB::statement`, trigger function through `DB::unprepared` heredoc, `Immutability::truncateGuardSql()` for append-only tables.
```php
DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_article_version_unique UNIQUE (article_id, version_no)');
DB::statement("ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_title_check CHECK (btrim(title) <> '' AND char_length(title) <= 200)");

DB::unprepared(<<<'SQL'
    CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN
      RAISE EXCEPTION 'a knowledge article version is never changed or deleted' USING ERRCODE = 'KP001';
    END $$
    SQL);

DB::unprepared('CREATE TRIGGER knowledge_article_versions_frozen BEFORE UPDATE OR DELETE ON knowledge_article_versions FOR EACH ROW EXECUTE FUNCTION kokpit_refuse_knowledge_version_change()');
DB::unprepared(Immutability::truncateGuardSql('knowledge_article_versions'));
```
Apply: `CREATE OR REPLACE` the same function name with the RESEARCH Pattern 1 variant (setting read with `coalesce(current_setting(..., true), '')`), keep the existing trigger. `down()` re-creates the Phase 18 function body verbatim. New `knowledge_version_redactions` table: uuidv7 default, RESTRICT FKs, CHECK on reason, own append-only trigger plus `truncateGuardSql`. Do not run the migration from a worktree.

---

### `app/Domain/Knowledge/Actions/SaveArticle.php` (modify: classification, tags, `expectedRedactionNo`, `restoredFromVersionNo`)

**Analog:** itself. Keep this order inside `update()` (lines 104-152): authorise, `lockForUpdate` through the scoped query, authorise on the locked row, stale check, normalise, no-op branch, guard, write, version row.

**Stale check to extend** (lines 110-116):
```php
$locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
Gate::forUser($actor)->authorize('update', $locked);
if ($locked->version_no !== $expectedVersion) {
    throw new StaleArticle($locked->version_no);
}
```
Apply: add a second required `int $expectedRedactionNo` (no default, as `expectedVersion`) and throw `StaleArticle` with `redactionChanged = true` on mismatch. Extend the no-op branch (lines 120-130) to compare classification so a classification-only edit returns `SaveOutcome::ClassificationChanged` and not `Unchanged`. Write `syncTagsWithType($names, TagType::Knowledge->value)` on `$locked` inside the same closure.

**Version row** (lines 225-234): add `'restored_from_version_no' => ...` to the `forceFill` in `writeVersion()`.

**Activity row without content** (lines 207-220): copy for `version_redacted` / restore events.
```php
activity()
    ->useLog($article->getMorphClass())
    ->performedOn($article)
    ->causedBy($actor)
    ->event('secret_acknowledged')
    ->withProperties(['rules' => $rules, 'version_no' => $article->version_no])
    ->log('secret_acknowledged');
```
**Error wrapper** (lines 243-250): `inTransaction()` maps `PDOException` to `ArticleWriteFailed` with only the SQLSTATE; reuse the same wrapper shape in Folder and Redact Actions where bound values could carry content (redaction reason, body).

---

### `app/Domain/Knowledge/Exceptions/StaleArticle.php` (modify) and new `FolderMoveRefused`, `FolderNotEmpty`

**Analog:** `app/Domain/Knowledge/Exceptions/StaleArticle.php` (whole file, 18 lines)
```php
final class StaleArticle extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The article moved to a newer version since the editor was opened.');
    }
}
```
Apply: fixed message, readonly public properties carrying only counts, reason enum or numbers (`MAX_DEPTH`, article count, subfolder count), never names or content. Add `public readonly bool $redactionChanged = false` to `StaleArticle`.

---

### `app/Domain/Knowledge/Models/KnowledgeArticle.php` (modify: add `HasTags`)

**Analog:** `app/Domain/Tasks/Models/Task.php` lines 106-108 and 158-165
```php
use HasTags {
    detachTags as private detachTagsFromTrait;
}
// ...
public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
{
    if ($this->trashed() && ! $this->isForceDeleting()) {
        return $this;
    }
    return $this->detachTagsFromTrait($tags, $type);
}
```
Apply: archiving is a soft delete, so tags must stay attached. Do not add tag names to `#[LoggedAttributes]`.

---

### `app/Domain/Knowledge/Actions/CreateFolder.php`, `RenameFolder.php`, `MoveFolder.php`, `DeleteFolder.php` (Actions)

**Analog:** `app/Domain/Tasks/Actions/ArchiveTask.php` (header lines 1-30) for the Action skeleton (`final class`, `handle(User $actor, Model ...)`, `Gate::forUser($actor)->authorize(...)`, `DB::transaction`, `ValidationException` with a keyed field error), combined with the guard facts in `KnowledgeFolder` (`MAX_DEPTH = 6` at line 39; relations `parent()`, `children()`, `articles()`).

Apply:
- Authorise through `Gate::forUser($actor)` (`AdminOnlyPolicy` is registered for `KnowledgeFolder` in `AccessServiceProvider`).
- `MoveFolder` takes `pg_advisory_xact_lock(hashtext('knowledge_folders_tree'))` (same key as the KP003 trigger), classifies Cycle vs Depth in PHP, and maps a backstop KP003 by the PDOException message prefix.
- `DeleteFolder` counts `KnowledgeArticle::withTrashed()` plus child folders; map 23503 as backstop; map 23505 on `knowledge_folders_sibling_name_unique` to a name-taken error.
- Never hard-code depth: use `KnowledgeFolder::MAX_DEPTH`.

---

### `app/Domain/Knowledge/Queries/FolderTree.php` (query)

**Analog:** `app/Domain/TimeTracking/Queries/TimeTotals.php` (pure query class, called as `app(TimeTotals::class)->projectColumns(...)` from the relation manager). Ordering: `App\Domain\Shared\Database\CzechCollation::orderBy($query, 'name')`.
Apply: one query for all folders, one grouped count over non-archived `general` articles, rollups in PHP, descendant ids by a raw recursive CTE with bindings (shape in RESEARCH "Code Examples").

---

### `app/Domain/Knowledge/Actions/RestoreVersion.php` and `RedactVersion.php`

**Analog:** `SaveArticle.php`. RestoreVersion: follow RESEARCH Pattern 4 (outer `DB::transaction`, lock article first, read version second, delegate to `SaveArticle::update()` with `ArticleInput::from($version->title, $version->body_md, false)`). RedactVersion: RESEARCH Pattern 5 (query builder only, `set_config(..., 'on', true)` then reset to `'off'`, metadata-only activity row copied from `writeAcknowledgment()`). RedactVersion is the one reviewed second `body_md` writer (see Arch test below).

---

### `app/Domain/Knowledge/Support/VersionDiff.php` (utility, transform)

**Partial analog:** `app/Domain/Knowledge/Guard/SecretScanner.php` line 31 uses `preg_split('/\r\n|\r|\n/', ...)`; use the same split so diff line numbers equal finding line numbers. Library API per RESEARCH Pattern 8 (`SebastianBergmann\Diff\Differ::diffToArray` on arrays of lines). Add the work budget (Pitfall 12). Promote `sebastian/diff` to `require`.

---

### `app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php` (modify: tree panel, `#[Url] folder`)

**Analog:** itself (47 lines).

**403 before anything is read** (lines 24-29):
```php
public function mount(): void
{
    abort_unless(KnowledgeArticleResource::canAccess(), 403);

    parent::mount();
}
```
Apply: every new page (versions list, version view, compare) copies this `mount()` guard before the record lookup. Add `content(Schema $schema)` returning `Flex::make([View::make('filament.knowledge.folder-tree')->grow(false), EmbeddedTable::make()])`; normalise `$folder` (UUID or literal `none`, else all).

---

### `app/Filament/Resources/KnowledgeArticleResource.php` (modify table: folder filter, tag AND filter, select columns)

**Analog:** itself, lines 240-300.

**Query closure** (lines 244-251): fixed select list without `body_md`; archived switch applied in `modifyQueryUsing`, not in the filter callback.
```php
->modifyQueryUsing(static function (Builder $query, HasTable $livewire, bool $isResolvingRecord): Builder {
    $query->select(['id', 'kind', 'title', 'version_no', 'edited_at', 'sensitive', 'deleted_at']);
    $showArchived = (bool) data_get($livewire->getTableFilterState('show_archived'), 'isActive', false);
    return $showArchived || $isResolvingRecord ? $query : $query->whereNull('deleted_at');
})
```
Apply: add `folder_id` to the select, `->with('tags')` for the chip column (keep `id` selected), folder constraint from the page's `$folder`. Tag filter: `SelectFilter::make('tags')->multiple()->query(...)` calling `withAllTags($ids, TagType::Knowledge->value)` (AND). Never load `body_md`.

**Dependent client/project selects** (`app/Filament/Resources/TransactionResource.php` lines 244-268, helper at 846-858):
```php
Select::make('client_id')->options(static fn (?Transaction $record, Get $get): array => self::clientOptions($record, $get('client_id')))
    ->searchable()->live()
    ->afterStateUpdated(static function (Get $get, Set $set): void { self::clientChanged($get, $set); })->native(false),
Select::make('project_id')->options(static fn (...) => self::projectOptions(...))->searchable()->native(false),
// projectOptions:
$query = Project::query()->selectable()->where('projects.client_id', $clientId);
foreach (CzechCollation::orderBy($query, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.key', 'projects.name']) as $project) {
    $options[$project->id] = $project->key.' · '.$project->name;
}
```
Apply: only `client_id` is `->live()` (changing or clearing it clears `project_id`, D-06). Tags use a plain `TagsInput` bound to form state with knowledge-tag suggestions, not `SpatieTagsInput`.

---

### `ClientKnowledgeRelationManager.php` and `ProjectKnowledgeRelationManager.php`

**Analog:** `app/Filament/Resources/ClientResource/RelationManagers/ClientProjectsRelationManager.php` (103 lines)

**Header, access rule, trait** (lines 34-49):
```php
#[AccessRule(Audience::AdminOnly, reason: '...a Partner never sees this tab.')]
final class ClientProjectsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'projects';

    public function isReadOnly(): bool { return true; }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoices.client_panel.tabs.projects');
    }
```
**Table skeleton** (lines 58-101): `->recordTitleAttribute(...)`, `->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class])...)`, `TextColumn::make(...)->url(fn (Model $record) => Resource::getUrl('view', ['record' => $record]))`, `->paginated([10])`, empty state with `Heroicon::OutlinedInbox`.
Apply: `$relationship = 'knowledgeArticles'`, add `where('kind', ArticleKind::General->value)`, archived `Filter::make('show_archived')->toggle()` as in the resource table, no `body_md`, "Add article" header action linking to the create URL with `?client=`/`?project=` (validated UUID and existence on the create page, Pitfall 14). Project tab lists only that project's articles. Register both classes in `PartnerMatrix` with `'partner' => false`.

---

### Versions pages (`ListArticleVersions`, `ViewArticleVersion`, `CompareArticleVersions`)

**Analog:** `Pages/ListKnowledgeArticles.php` (mount guard, `getTitle()` via `__('knowledge...')`) and the existing `ViewKnowledgeArticle.php`/`KnowledgeArticleHistoryRelationManager.php` (`app/Filament/RelationManagers/`, 16-line subclass of `ActivityHistoryRelationManager`).
Apply: `ListArticleVersions extends ManageRelatedRecords` with `$relationship = 'versions'`; table excludes `body_md`, `->reorder()->orderByDesc('version_no')`, `withExists('redactions')`. Register routes in `getPages()` with `compare` before `{version}`; override `getRecordSubNavigation()`. Plain escaped Blade only (`{{ }}`, never `{!!`; `KnowledgeSeamTest` enforces it).

---

### `tests/Arch/KnowledgeSeamTest.php` (modify)

**Analog:** itself. Line 28 `const KNOWLEDGE_SEAM_WRITER_FILE = 'app/Domain/Knowledge/Actions/SaveArticle.php';`, scanner `knowledgeSeamWrites()` at line 189, per-file exemption at 393-402, self-check at 430-431 (`toHaveCount(5)`).
Apply: turn the constant into a reviewed list (`SaveArticle.php`, `RedactVersion.php`); keep each file's self-check; add rules that `RedactVersion` never writes `version_no`, that only `RedactVersion` and the migration name the redaction setting, and that nothing else selects or logs the redaction `reason`. Extend the "no default" test to cover `expectedRedactionNo`.

---

### `tests/Support/PartnerMatrix.php` and `tests/Support/RouteRules.php` (modify)

**Analog:** `PartnerMatrix.php` lines 204, 250, 259, 514-515:
```php
KnowledgeArticleResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Internal knowledge articles of the Admin; sharing arrives in Phase 23.'],
KnowledgeArticleHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a knowledge article.'],
ClientProjectsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Unbilled time and billing types of a client.'],
KnowledgeArticleVersion::class => 'Immutable versions of the knowledge articles of the Admin.',
```
Apply: declare in the same pull request both new relation managers, the three new pages and routes, any new Livewire component, and the new model `KnowledgeVersionRedaction` (model list at the lines 514-515 style). Reasons in English, fictional data only.

---

## Shared Patterns

### Admin-only gate and Partner fail-closed
**Source:** `ListKnowledgeArticles::mount()` (lines 24-29) and `#[AccessRule(Audience::AdminOnly, reason: ...)]` plus `EnforcesRelationManagerAccessRule` in `ClientProjectsRelationManager.php` (lines 34-37).
**Apply to:** every new page and relation manager; models use `DeniesPartners` (as `KnowledgeFolder`).

### One write path for `body_md` and `version_no`
**Source:** `SaveArticle.php` header docblock (lines 25-57). Restore calls `update()`; only `RedactVersion` is a second reviewed writer (Arch test updated in the same plan).

### Metadata-only logging and exceptions
**Source:** `SaveArticle::writeAcknowledgment()` (lines 207-220) and `StaleArticle` (fixed message). Never put title, body, reason text, tag names or secret values in activity rows, messages or exception properties.

### Czech UI text, English code
**Source:** `lang/cs/knowledge.php`; call sites use `__('knowledge.columns.title')`, `trans()->string(...)`. New copy (including the "too large to compare" state flagged in RESEARCH Pitfall 12) goes into the same file, plus the existing `KnowledgeLangTest` coverage.

### Czech ordering and selectable projects
**Source:** `CzechCollation::orderBy(...)` and `Project::selectable()` as in `TransactionResource::projectOptions` (lines 846-858).

### Test seams
Secret samples only via `Tests\Support\SecretFixtures`; schema trigger assertions via `RawSql::expectSqlState('KP001', ...)`; reset the transaction-local setting right after use (Pitfall 9).

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `resources/views/filament/knowledge/folder-tree.blade.php` | component | request-response | No nested tree or disclosure component exists in the repository; follow UI-SPEC and RESEARCH Pattern 6 (server-rendered nested `<ul>`, escaped output only) |
| `app/Domain/Knowledge/Support/VersionDiff.php` (algorithm part) | utility | transform | No diff code exists; follow RESEARCH Pattern 8 and Pitfall 12 |

## Metadata

**Analog search scope:** `app/Domain/Knowledge`, `app/Domain/Tasks`, `app/Filament/Resources` (Knowledge, Client, Project, Transaction), `app/Filament/RelationManagers`, `database/migrations` (knowledge), `tests/Arch`, `tests/Support`, `tests/Feature/Knowledge`
**Files scanned:** about 25 (read or grepped)
**Pattern extraction date:** 2026-11-25
