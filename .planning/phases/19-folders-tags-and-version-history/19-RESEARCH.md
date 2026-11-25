# Phase 19: Folders, Tags and Version History - Research

**Researched:** 2026-11-25
**Domain:** Laravel 13 + Filament 5 (SPA) + PostgreSQL: folder tree UI over an existing guarded schema, typed Spatie tags, client/project links, append-only version history with diff, restore through the single save path, and a controlled redaction path through the immutability trigger.
**Confidence:** HIGH for everything grounded in the Phase 18 code and migrations (all read this session); MEDIUM for Filament modal and page composition details (verified against vendor source, not run); the design choices marked `[ASSUMED]` in the Assumptions Log need planner or owner confirmation.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
### Folder tree (ORG-01, ORG-02)
- **D-01:** The tree is a panel on the left of the General knowledge list. Clicking a folder filters the list and includes its subfolders. Create, rename, move and delete are actions on the tree. No new resource and no extra navigation entry.
- **D-02:** The tree has a "No folder" entry for unfiled articles.
- **D-03:** Moving a folder is a "Move" action with a parent select in a modal (no drag and drop). A refusal (own subtree, depth cap, non-empty delete) shows an explanation derived from the database guard (KP003, RESTRICT), with the cap taken from `KnowledgeFolder::MAX_DEPTH`.

### Tags and filters (ORG-03)
- **D-04:** Tags are created on the fly in the article editor (select with inline "create new"), limited to the `knowledge` tag type. Client tags never appear there, and knowledge tags never appear among client tags. No separate tag management screen.
- **D-05:** Selecting several tags in the list filter narrows the result (AND). Folder, tag and archived filters combine.

### Client and project links (ORG-04)
- **D-06:** In the editor Admin picks the client first, then a project from that client's projects only. Changing or clearing the client clears the project.
- **D-07:** The Knowledge tab on client and project detail pages is a relation manager listing linked articles (title, version, last edited, tags, sensitive indicator; click opens the article) with an "Add article" action that opens the create screen with client (and project) prefilled. Archived articles are hidden behind a switch. The project tab lists only articles linked to that project.

### Version history and diff (VER-02, VER-04)
- **D-08:** History lives on a separate "Versions" page of the article resource (next to View and Edit): table of versions (number, author, time, markers such as restored or redacted), from which a read-only version view and the diff open.
- **D-09:** The diff is a unified line diff, title shown separately. Default comparison is the chosen version against its predecessor; any two versions can be picked. Use the diff library already in the lock or the optional `jfcherng/php-diff`; planner decides.
- **D-10:** Retention: keep every version, no coalescing and no pruning (consistent with D-11 of Phase 18, gap-free numbering). A prune command is added only if size becomes a measured problem.

### Restore and redaction (VER-03, VER-05)
- **D-11:** Restore shows the diff of the current version against the chosen one and, after confirmation, writes a new version on top through the same save path: same stale-save check and secret guard as an editor save. An unambiguous secret refuses the restore, an ambiguous finding needs the same acknowledgment (one restore only, logged without content). Nothing in the history is lost.
- **D-12:** Redaction rewrites the body of the chosen stored version only (marker replaces the content), keeps the row and marks it "redacted" in the history. View, diff and restore of that version use the redacted body. Reason is required. The activity log records who, which version and that a reason exists, never the value or the reason text. The UI states that rotating the leaked secret is the real remedy.
- **D-13:** Any version, including the latest, can be redacted. Redacting the latest version also applies to the article's current body so the article and its newest version never disagree.

### Claude's Discretion
Exact tree component, Czech wording, icons, the redaction marker text (whole body or only matching lines), diff library choice, how the transaction-local setting for the trigger variant is named.

### Deferred Ideas (OUT OF SCOPE)
None. Version pruning stays an optional later command (see D-10).

Out of this phase (CONTEXT.md domain note): images (Phase 20), incidents and retrospectives (Phase 21), search (Phase 22), client sharing and AI export (Phase 23).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ORG-01 | Admin manages a folder tree (create, rename, move; depth capped, cycles impossible) | Folder Actions over the existing KP003 trigger; `FolderTree` query; tree panel inside `ListKnowledgeArticles::content()`; Pitfalls 1, 2, 3 |
| ORG-02 | A non-empty folder cannot be deleted | RESTRICT FKs already exist; pre-count including archived articles; 23503 backstop mapping; Pitfall 4 |
| ORG-03 | Articles filed in folders and tagged with a dedicated knowledge tag type, separate from client tags | `HasTags` on `KnowledgeArticle`, `TagType::Knowledge`, tags written inside the save transaction, AND filter via `withAllTags`; Pitfalls 5, 6 |
| ORG-04 | Article linked to client and/or project; Knowledge tab on client and project pages | Composite FK already exists; dependent selects; two relation managers; Partner matrix rows |
| VER-02 | Admin sees the version history and views an earlier version | `ManageRelatedRecords` Versions page + version view page; body never loaded in the table |
| VER-03 | Restoring creates a new version and passes the secret guard again | `RestoreVersion` Action wrapping `SaveArticle::update()` with an outer lock; `restored_from_version_no` column; Pitfall 7 |
| VER-04 | Admin sees the difference between two versions | `sebastian/diff` 9.0.1 promoted to `require`; line-array diff; row cap; plain-text escaped rendering |
| VER-05 | Admin can redact a secret that reached a stored version | Trigger variant with transaction-local setting, `RedactVersion` Action, reason table, `redaction_no` stale token; Pitfalls 8 to 11 |
| SEC-01 (restore clause) | Every restore scans for secrets | Proven by VER-03: restore calls the one `SaveArticle::update()` path |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Extracted from the project `.claude/CLAUDE.md` and the user's global `CLAUDE.md`:

- **Fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages: `example.com` addresses, company ID `12345678`, `/Users/example/` paths; test fakes assembled at runtime from fragments (secret samples only via `Tests\Support\SecretFixtures`). Review before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook hook (`--no-verify`, `LEFTHOOK=0`).
- **Security:** Partner must never see knowledge data; enforced by Policies and global query scopes, not UI hiding. Every new page, route and Livewire component is declared in `tests/Support/PartnerMatrix.php` / `tests/Support/RouteRules.php` in the same pull request.
- **Data integrity:** UUID v7 keys everywhere (new tables use `uuidv7()` default); FK, unique, partial indexes and check constraints enforced in the database.
- **Immutability:** version rows are immutable; snapshots; the only exception built here is the redaction path.
- **Worktrees, tests, migrations:** write code and tests in worktrees; do NOT run migrations from a worktree; migrations and the full suite run only after merge into the HEAD branch, in DDEV: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency`. Do not use `pest --parallel`.
- **GSD workflow:** file changes go through a GSD command; all UI text Czech in `lang/cs/knowledge.php`, code, tests, routes and planning docs English.
- **Global:** commit messages follow the user's Conventional Commits prompt (no body for mechanical changes).
- No project skills found in `.claude/skills/`.

## Summary

Phase 18 already ships the hard parts of the schema: the folder tree guard (KP003, `KnowledgeFolder::MAX_DEPTH = 6`, RESTRICT FKs, case-insensitive sibling-name unique index), the composite `(project_id, client_id)` FK on articles, and the immutable full-snapshot `knowledge_article_versions` (KP001 trigger). Phase 19 is therefore mostly Actions, queries and Filament screens on top, plus one migration. The migration is small but load-bearing: it adds the persisted markers the UI contract needs (`restored_from_version_no` on versions, a redaction table for the required reason, a `redaction_no` stale-token column on articles) and replaces the version trigger function with a variant that admits a body-only update while a transaction-local setting is on.

Five findings change how the plan must be shaped. (1) `KnowledgeArticle` does **not** use `HasTags` yet and the plugin's `SpatieTagsInput` writes tags in a separate step after the Action (`dehydrated(false)` + `saveRelationshipsUsing`), so tags, folder and links must be written inside `SaveArticle`'s locked transaction through an extended `ArticleInput`, with a plain `TagsInput` bound to the form state, otherwise a stale or guard-refused save could still change tags. (2) The Arch test `tests/Arch/KnowledgeSeamTest.php` allows only `SaveArticle.php` to write `body_md`/`version_no`; `RedactVersion` must write both the version row and (for the current version) the article row, so the arch rule needs a reviewed second writer. (3) D-13 requires that an editor or restore modal opened before a redaction of the current version cannot save the old text back, but `version_no` does not change on redaction, so a second stale token (`redaction_no`) is needed and `SaveArticle::update()` gets a second required parameter. (4) Restore must read the chosen version body **after** locking the article row, inside one outer transaction, or it can race a redaction and write the unredacted body as a new version. (5) `sebastian/diff` 9.0.1 is only in `packages-dev` of `composer.lock`; it must be promoted to `require`. Its Myers implementation is linear-space but O((N+M)·D), so a work budget is needed for hostile bodies.

**Primary recommendation:** Build in this order: one migration (columns, redaction table, trigger variant) with schema tests; extend `ArticleInput`/`SaveArticle` (classification, tags, `redaction_no`, `restored_from_version_no`); folder Actions and a `FolderTree` query; then the three UI slices (tree panel and filters, editor classification and Knowledge tabs, versions pages with restore and redaction), declaring every new class in `PartnerMatrix` and updating the Arch seam test in the same plan that introduces the second writer.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Folder tree integrity (cycle, depth, unique siblings, non-empty delete) | Database / Storage | API / Backend | The KP003 trigger and RESTRICT FKs are authoritative for every writer; the Action pre-checks only to produce an explanation |
| Folder create/rename/move/delete rules and messages | API / Backend (Domain Actions) | Database | Actions hold the rules (`MAX_DEPTH`, name length, NUL refusal) and translate guard results to refusal reasons |
| Folder tree rendering and selection | Frontend Server (Livewire page + Blade) | Browser (disclosure, focus) | Server-rendered nested `<ul>` inside the list page, selection in a `#[Url]` Livewire property |
| Tags (type separation, AND filter) | API / Backend | Database | Type isolation is a query/sync rule (`TagType::Knowledge`); AND filter is a query rule |
| Client/project link consistency | Database (composite FK) | API / Backend (Select option scopes) | FK enforces the project belongs to the client; the form only narrows options |
| Version history listing, view, diff | API / Backend (query + `VersionDiff`) | Frontend Server | Diff computed server-side from two stored rows, rendered as escaped text |
| Restore | API / Backend (`RestoreVersion` over `SaveArticle`) | Frontend Server (modal) | Must reuse the one write path: stale check, guard, normaliser |
| Redaction | Database (trigger variant) | API / Backend (`RedactVersion`) | Immutability is a DB property; the only exception is gated by a transaction-local setting that only the Action sets |
| Partner isolation of all new screens | API / Backend (policies, scopes, access rules) | Frontend Server (no tab rendered) | Fail-closed models plus `#[AccessRule]` and matrix declarations |
| Activity log (metadata only) | API / Backend | Database | Explicit rows from the Actions; never the value, line text or reason |

## Standard Stack

### Core (all already in `composer.lock`; versions verified from `composer.lock` and `vendor/`)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 (composer.json) | App framework | Project stack [VERIFIED: composer.json:24] |
| filament/filament (+ schemas, forms, tables, actions) | v5.12.0 | Admin panel UI | Project stack [VERIFIED: composer.lock] |
| livewire/livewire | v4.4.9 | Reactive pages | [VERIFIED: composer.lock] |
| spatie/laravel-tags | 4.12.0 | Typed tags (`HasTags`, `syncTagsWithType`, `withAllTags`) | Already used for client, project and task tags [VERIFIED: composer.lock] |
| filament/spatie-laravel-tags-plugin | v5.12.0 | Tag columns/entries | Already used; its input is NOT recommended for the editor here (see Pitfall 5) [VERIFIED: composer.lock] |
| spatie/laravel-activitylog | ^5.1 | Metadata-only audit rows | Existing `LogsAllowlistedActivity` trait [VERIFIED: composer.json:34] |
| sebastian/diff | 9.0.1 | Line diff of two version bodies | Already locked (transitive of phpunit), BSD-3-Clause, `php >=8.4`; zero new transitive packages [VERIFIED: composer.lock section `packages-dev`, vendor/sebastian/diff/composer.json] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| league/commonmark via `KnowledgeMarkdown` | 2.10.7 | The one renderer | Version view only; the diff and the redaction preview must NOT render Markdown |
| `App\Domain\Shared\Database\CzechCollation` | in repo | Czech collation ordering | Sorting siblings in the tree and option lists [VERIFIED: app/Domain/Shared/Database/CzechCollation.php:43] |
| `Project::selectable()` | in repo | Pickable projects scope | Project select options, as in `TransactionResource::projectOptions` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `sebastian/diff` | `jfcherng/php-diff` ^7 | Adds three packages and its own HTML renderers; the contract wants a custom table with old/new line numbers, which `diffToArray()` plus a small row builder satisfies. UI-SPEC also recommends `sebastian/diff` |
| Filament `SpatieTagsInput` | Plain `TagsInput` with knowledge-tag suggestions | The plugin input writes tags outside the Action transaction; plain `TagsInput` keeps the names in form state so `SaveArticle` writes them atomically |
| Dedicated `redaction_no` column | Hash of the stored body as stale token | Hash needs no column but gives no countable "redacted" state; either changes the `update()` signature. Column recommended |
| Reason stored in a table | No storage of the reason text | See Open Question 1 |

**Installation (after merge into the HEAD branch, in DDEV):**
```bash
ddev composer require sebastian/diff:^9.0.1
ddev composer check-licenses   # script scripts/check-licenses.php must stay green (BSD-3-Clause is on its allowlist)
```
**Version verification:** `sebastian/diff` 9.0.1 read from `composer.lock` (entry in `packages-dev`, `require: php >=8.4`) and `vendor/sebastian/diff/composer.json` (`"license": "BSD-3-Clause"`), 2026-11-25. No registry call was needed: the package is already locked.

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| sebastian/diff | Packagist | mature (author of PHPUnit; 9.0.1 locked) | very high (transitive of phpunit/pest) | github.com/sebastianbergmann/diff | OK (not a new package; present in `composer.lock`, installed in `vendor/`) | Approved: promote from transitive dev to explicit `require` |

**Packages removed due to [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** none.
**Note:** `gsd-tools package-legitimacy check` supports `npm|pypi|crates` only, so it was not run for a Packagist package. No new package is added: the only change is a `require` entry for an already-locked package (`composer.lock` content-hash changes, no new `vendor/` code). `jfcherng/php-diff` is NOT recommended and therefore not audited. Do not add it.

## Architecture Patterns

### System Architecture Diagram

```
                           Admin (browser, Filament SPA)
                                      |
        +-----------------------------+------------------------------+
        |                             |                              |
 General knowledge list       Article editor / create        Versions pages (new)
 (tree panel + table)         (+ "Zarazeni" section)         list | view | compare
        |                             |                              |
  Livewire page actions         form submit                   page / row actions
  (folder CRUD, ?folder=)       (title, body, folder,         (restore modal, redact modal)
        |                        client, project, tags)              |
        v                             v                              v
  Folder Actions            SaveArticle::create/update       RestoreVersion        RedactVersion
  Create/Rename/Move/       (lock article row, stale +       (outer txn: lock      (txn: lock article row,
  Delete (+FolderTree        redaction_no check, normalise,   article, read version, set_config on, UPDATE
  query, MAX_DEPTH)          guard scan, classification +     delegate to update)   version.body_md [+ article
        |                    tags, version row)                    |                 .body_md + redaction_no],
        |                             |                            |                 set_config off, reason row,
        |                             |                            +-> SaveArticle  activity row w/o content)
        v                             v                                              |
  +-----------------------------------------------------------------------------------+
  | PostgreSQL: knowledge_folders (KP003 tree guard, RESTRICT)                        |
  |             knowledge_articles (composite FK project/client, folder FK, redaction_no)|
  |             knowledge_article_versions (KP001 trigger variant; restored_from_version_no)|
  |             knowledge_version_redactions (append-only; reason)                    |
  |             tags / taggables (type = 'knowledge')                                 |
  +-----------------------------------------------------------------------------------+
        ^
        |  read side: FolderTree (one query, PHP rollups), VersionDiff (two rows -> rows[]),
        |  Knowledge relation managers on Client / Project (list select, no body_md)
```

### Recommended Project Structure
```
app/Domain/Knowledge/
├── Actions/
│   ├── ArticleInput.php            # extend: optional ArticleClassification
│   ├── ArticleClassification.php   # NEW readonly value object: folderId, clientId, projectId, tags
│   ├── SaveArticle.php             # extend: classification+tags, expectedRedactionNo, restoredFrom
│   ├── SaveOutcome.php            # add ClassificationChanged
│   ├── CreateFolder.php, RenameFolder.php, MoveFolder.php, DeleteFolder.php   # NEW
│   ├── RestoreVersion.php          # NEW (thin, outer lock + delegate)
│   └── RedactVersion.php           # NEW (the second reviewed body writer)
├── Exceptions/
│   ├── FolderMoveRefused.php       # reason enum Cycle | Depth (carries max depth)
│   ├── FolderNotEmpty.php          # carries article and subfolder counts (archived included)
│   └── StaleArticle.php            # extend: bool $redactionChanged
├── Queries/
│   └── FolderTree.php              # NEW: load all folders once, paths, descendants, counts
├── Support/
│   └── VersionDiff.php             # NEW: two bodies -> DiffRow[] (+ summary, truncation flag)
├── Models/ KnowledgeVersionRedaction.php   # NEW (not logged, DeniesPartners)
app/Filament/Resources/KnowledgeArticleResource/Pages/
├── ListKnowledgeArticles.php       # extend: content() = Flex[tree, EmbeddedTable]; #[Url] folder; folder actions
├── ListArticleVersions.php         # NEW (ManageRelatedRecords, relationship 'versions')
├── ViewArticleVersion.php          # NEW
├── CompareArticleVersions.php      # NEW
app/Filament/Resources/ClientResource/RelationManagers/ClientKnowledgeRelationManager.php   # NEW
app/Filament/Resources/ProjectResource/RelationManagers/ProjectKnowledgeRelationManager.php # NEW
resources/views/filament/knowledge/  folder-tree.blade.php, version-diff.blade.php, redaction-preview.blade.php
resources/css/kokpit-diff.css        # NEW (UI-SPEC)
database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php  # NEW (name by date after merge)
```

### Pattern 1: One migration, four changes (schema contract)
**What:** a single reviewed migration after `2026_11_25_000300_*`.
**Columns and tables (design; names are the planner's, behaviour is the contract):**
- `knowledge_article_versions.restored_from_version_no integer null`, CHECK `restored_from_version_no IS NULL OR restored_from_version_no < version_no`, composite FK `(article_id, restored_from_version_no)` referencing `(article_id, version_no)` (the unique constraint `knowledge_article_versions_article_version_unique` exists, so the FK target is valid) with RESTRICT. A NULL in the FK column skips the FK check (default MATCH SIMPLE).
- `knowledge_articles.redaction_no integer not null default 0`, CHECK `>= 0`. Bumped only by `RedactVersion` when the redacted version is the current one.
- `knowledge_version_redactions` (id uuidv7 default, `version_id` FK RESTRICT, `actor_id` FK users RESTRICT, `scope text` CHECK in ('lines','whole'), `reason text` CHECK `btrim(reason) <> '' AND char_length(reason) <= 500`, `created_at timestamptz default now()`), append-only through its own trigger function plus `Immutability::truncateGuardSql()`. "Redacted" marker = `EXISTS` on this table (no change to the version row beyond `body_md`).
- Replace `kokpit_refuse_knowledge_version_change()` with the variant below (CREATE OR REPLACE; the trigger `knowledge_article_versions_frozen` already points at the function name).

**Trigger variant (transaction-local setting; the setting name is the planner's choice, `kokpit.redacting_knowledge_version` is a suggestion):**
```sql
-- Source: PostgreSQL docs "System Administration Functions" (set_config is_local = true is transaction-local;
-- current_setting(name, true) returns NULL for an unknown setting) + the existing function in
-- database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php:63-68
CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'UPDATE'
     AND coalesce(current_setting('kokpit.redacting_knowledge_version', true), '') = 'on'
     AND (NEW.id, NEW.article_id, NEW.version_no, NEW.title, NEW.author_id, NEW.created_at, NEW.restored_from_version_no)
         IS NOT DISTINCT FROM
         (OLD.id, OLD.article_id, OLD.version_no, OLD.title, OLD.author_id, OLD.created_at, OLD.restored_from_version_no)
  THEN
    RETURN NEW;   -- only body_md may differ
  END IF;
  RAISE EXCEPTION 'a knowledge article version is never changed or deleted' USING ERRCODE = 'KP001';
END $$
```
DELETE and TRUNCATE stay refused (the truncate guard is a separate statement trigger and is untouched). `coalesce(..., '')` matters: after the first `set_config` in a session PostgreSQL keeps an empty-string placeholder, so `current_setting` returns `''`, not NULL, in later transactions [ASSUMED: PostgreSQL custom-GUC placeholder behaviour from training knowledge; the `coalesce` makes the function correct either way].
`down()` must re-create the original function body verbatim (Phase 18 text) and drop the new columns and table.

### Pattern 2: Classification and tags written inside the save transaction
**What:** extend `ArticleInput` with an optional `ArticleClassification` (folder id, client id, project id, tag names) that the form always sends in full; `null` means "keep as stored" for non-form callers. `SaveArticle` writes it under the same row lock, after the stale check and before/after the content branch.
**Outcomes:** content change -> `Updated` (new version, classification written too); classification-only change -> new `SaveOutcome::ClassificationChanged` (no version, `edited_at` unchanged, notification "Zarazeni clanku bylo ulozeno, nova verze nevznikla."); nothing changed -> `Unchanged` (existing). The existing no-op branch (`SaveArticle.php:120-130`) must also compare classification, otherwise a classification-only edit is dropped as "Unchanged".
**Tags:** add `use Spatie\Tags\HasTags` to `KnowledgeArticle` (mirror `Task`: `use HasTags { detachTags as private detachTagsFromTrait; }` with the soft-delete guard, see `app/Domain/Tasks/Models/Task.php:106-108,158-165`). Inside the Action: `$locked->syncTagsWithType($names, TagType::Knowledge->value)`.
**Case-insensitive merge:** the package matches by exact `name->locale` or `slug->locale` equal to the input string (`Tag::findManyFromString`, `vendor/spatie/laravel-tags/src/Tag.php:100-115`), so "Server" and "server" are not merged by the package. Resolve each input name against existing knowledge tags case-insensitively in the Action (lower-case compare via `mb_strtolower`) and pass the stored spelling to `syncTagsWithType`. The `tags` table has no unique index (`database/migrations/2026_10_07_152543_create_tag_tables.php`), so concurrent creation can duplicate a tag; with a single Admin this is accepted (note in Assumptions Log).
**Activity log:** `folder_id`, `client_id`, `project_id` are already in `#[LoggedAttributes]`, so link changes log automatically on the model save; tag changes are not logged (no attribute). Do not add the tag names to the log.

### Pattern 3: Stale token covers redaction (D-13)
`SaveArticle::update(User, KnowledgeArticle, ArticleInput, int $expectedVersion, bool $acknowledge)` currently compares only `version_no` (`SaveArticle.php:114`). Add a second required parameter `int $expectedRedactionNo` (no default, mirror `expectedVersion`; extend `KnowledgeSeamTest` `it('gives the expected version ... no default')` to cover it) and throw `StaleArticle` with `redactionChanged = true` when `$locked->redaction_no !== $expectedRedactionNo`. `EditKnowledgeArticle` stores a second `#[Locked] public int $loadedRedactionNo` in `mount()` (like `loadedVersion`) and picks the notification by `redactionChanged` (UI-SPEC Section 7 wording). The restore modal does the same for its own token. Existing tests calling `update()` need the new argument (cost: SaveArticleTest, EditKnowledgeArticleTest, concurrency worker `tests/Concurrency/knowledge-save-worker.php`).

### Pattern 4: Restore = outer lock, read, delegate
```php
// RestoreVersion::handle(User $actor, KnowledgeArticle $article, int $versionNo, int $expectedVersion,
//                        int $expectedRedactionNo, bool $acknowledge = false): SaveResult
DB::transaction(function () use (...) {
    // 1. Same lock order as RedactVersion and SaveArticle: the article row first.
    $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail(); // archived => not found
    // 2. Only now read the version: a redaction that committed before the lock is already visible.
    $version = KnowledgeArticleVersion::query()->where('article_id', $locked->getKey())->where('version_no', $versionNo)->firstOrFail();
    // 3. The one write path: stale check, normalise, guard, version row.
    return app(SaveArticle::class)->update($actor, $locked, ArticleInput::from($version->title, $version->body_md, false),
        $expectedVersion, $expectedRedactionNo, $acknowledge, restoredFromVersionNo: $versionNo);
});
```
`SaveArticle` writes `restored_from_version_no` in `writeVersion()`. A restore whose title and body equal the current ones returns `SaveOutcome::Unchanged` through the existing no-op branch (UI: "Tato verze se shoduje..."; the modal should check this itself to hide the submit). `SaveArticle::update()`'s own `lockForUpdate()` re-locks the same row inside the same transaction (no deadlock). `ArticleInput::from(..., sensitive: false, clearSensitive: false)` means "keep the stored flag" (`ArticleInput.php` docblock), so restore never clears `sensitive` and an acknowledged ambiguous finding marks it, as D-11 requires.

### Pattern 5: Redaction
```php
// RedactVersion::handle(User $actor, KnowledgeArticle $article, int $versionNo, RedactionScope $scope,
//                       list<int> $lines, string $reason): RedactResult
DB::transaction(function () {
    $article = KnowledgeArticle::query()->withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail(); // lock order: article first; archived allowed (UI-SPEC)
    $version = KnowledgeArticleVersion::query()->where(...)->firstOrFail();
    $redacted = /* replace selected lines (split on the scanner's /\r\n|\r|\n/) or the whole body with '[REDIGOVÁNO]' */;
    DB::select("select set_config('kokpit.redacting_knowledge_version', 'on', true)");
    DB::table('knowledge_article_versions')->where('id', $version->id)->update(['body_md' => $redacted]);
    if ($version->version_no === $article->version_no) {
        DB::table('knowledge_articles')->where('id', $article->id)->update(['body_md' => $redacted, 'redaction_no' => $article->redaction_no + 1]);
    }
    DB::select("select set_config('kokpit.redacting_knowledge_version', 'off', true)"); // see Pitfall 9
    // append-only reason row + one activity row: event 'version_redacted', properties: version_no, scope, is_current, reason_recorded=true
});
```
Only the query builder is used (no model events, so the activity package writes nothing about `body_md`; `body_md` is not in `#[LoggedAttributes]` anyway). The Arch test must be updated (Pitfall 10).

### Pattern 6: Folder tree on the list page
- `ListKnowledgeArticles::content(Schema $schema)` is overridable `[VERIFIED: vendor/filament/filament/src/Resources/Pages/ListRecords.php:261-271]` and the schema package ships `Flex` and `View` components `[VERIFIED: ls vendor/filament/schemas/src/Components]`. Return `Flex::make([View::make('filament.knowledge.folder-tree')->grow(false), EmbeddedTable::make()])` (replace the default component list; keep the tabs and render hooks if used). The 1024px collapse is CSS in `kokpit-diff.css` under `.kokpit-tree`.
- Selection: `#[Url] public ?string $folder = null;`. Normalise on every read: only a valid UUID (`Str::isUuid`) or the literal `none` is honoured, anything else is "all" (an arbitrary string cast to `uuid` makes PostgreSQL raise and the page 500). Resetting the table page on change is Filament's default for filters; call `$this->resetPage()` when `folder` changes.
- Folder filter in `modifyQueryUsing` (the existing closure already receives `HasTable $livewire`): `none` -> `whereNull('folder_id')`; a uuid -> `whereIn('folder_id', <descendant ids incl. self>)`. Add `folder_id` to the existing select list (`KnowledgeArticleResource::table()` selects a fixed column list without `body_md`).
- Descendants: recursive CTE in `FolderTree` with a bound uuid, same shape as the trigger's own CTE (`2026_11_25_000300_*.php:87-92`). Whether Laravel 13's query builder has `withRecursiveExpression` was not verified; use `DB::select` with a raw CTE and bindings `[ASSUMED]`.
- Tree data: one query `select id, parent_id, name from knowledge_folders` ordered with `CzechCollation::orderBy(..., 'name')`; counts in one grouped query over non-archived `general` articles (`select folder_id, count(*) ... where deleted_at is null group by folder_id`), rolled up to ancestors in PHP; hundreds of folders is the expected scale (ARCHITECTURE.md). No N+1 and no per-row CTE.
- Folder actions are methods on the page following Filament's `{name}Action` convention (`mountAction($name, $arguments, $context)` is public, `[VERIFIED: vendor/filament/actions/src/Concerns/InteractsWithActions.php:294]`) invoked from the Blade view with the folder id as an argument. Treat the id argument as untrusted: re-resolve the folder, authorise through the Domain Action (`Gate::forUser($actor)->authorize(...)` against `AdminOnlyPolicy`, registered for `KnowledgeFolder` in `AccessServiceProvider.php:159`), and never trust the client for depth or ancestry.

### Pattern 7: Versions pages
- Register `'versions' => ListArticleVersions::route('/{record}/versions')`, `'version' => ViewArticleVersion::route('/{record}/versions/{version}')`, `'compare' => CompareArticleVersions::route('/{record}/versions/compare')` in `KnowledgeArticleResource::getPages()`. Declare `compare` **before** `{version}` (Laravel matches in registration order `[ASSUMED]`; the alternative is a numeric constraint on `{version}`).
- `getRecordSubNavigation(Page $page)` exists on the resource base and returns `[]` by default `[VERIFIED: vendor/filament/filament/src/Resources/Resource/Concerns/HasNavigation.php:195]`; override with `$page->generateNavigationItems([ViewKnowledgeArticle::class, EditKnowledgeArticle::class, ListArticleVersions::class])` (`generateNavigationItems` `[VERIFIED: vendor/filament/filament/src/Pages/Concerns/HasSubNavigation.php:170]`).
- `ListArticleVersions` can extend `Filament\Resources\Pages\ManageRelatedRecords` (exists `[VERIFIED: ls vendor/filament/filament/src/Resources/Pages]`) with `$relationship = 'versions'`. The existing `KnowledgeArticle::versions()` has a baked-in `->orderBy('version_no')` (`KnowledgeArticle.php`), which would win over a descending default sort: call `->reorder()->orderByDesc('version_no')` in `modifyQueryUsing`, and select every column except `body_md`, adding `withExists('redactions')` for the "Redigovano" badge. Guard every page with `abort_unless(KnowledgeArticleResource::canAccess(), 403)` in `mount()` before the record lookup (the Phase 18 pattern, `ListKnowledgeArticles::mount`).
- A missing version number is a 404 (`firstOrFail` on `(article_id, version_no)`); validate that the route value is an integer before querying.

### Pattern 8: Diff rows
```php
// Source: vendor/sebastian/diff/src/Differ.php (OLD=0, ADDED=1, REMOVED=2; diffToArray(array|string, array|string))
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;

$differ  = new Differ(new DiffOnlyOutputBuilder(''));        // builder is required by the constructor, unused by diffToArray
$entries = $differ->diffToArray($fromLines, $toLines);       // pass LISTS of lines without line breaks
// walk $entries keeping $old and $new counters; ADDED -> $new++, REMOVED -> $old++, OLD -> both; emit rows
// [oldNo|null, newNo|null, marker '+'|'-'|' ', text]; collapse unchanged runs longer than 2*3 context lines.
```
Pass arrays of lines (split the normalised body on `\n`, after replacing `\r\n`/`\r`) instead of strings: with strings the library keeps the trailing `\n` in each token and may prepend a `DIFF_LINE_END_WARNING` entry (`Differ.php:60-86`), which the table must not show. Bodies are stored normalised (LF) by `SaveArticle`, except bytes inside code blocks which are kept as typed (`MarkdownNormaliser` docblock), so a code block can legitimately hold `\r\n`: split with `preg_split('/\r\n|\r|\n/')`, the same expression the secret scanner uses (`SecretScanner.php:31`), so diff and finding line numbers agree.

### Anti-Patterns to Avoid
- **Writing tags with the plugin's `SpatieTagsInput` in the editor:** it is `dehydrated(false)` and syncs in `saveRelationshipsUsing` after the page's save (`vendor/filament/spatie-laravel-tags-plugin/src/Forms/Components/SpatieTagsInput.php`), outside the Action transaction and the stale/guard decision.
- **Reading the version body before locking the article** in restore (race with redaction, Pitfall 7).
- **Using `$article->update($version->only(...))` for restore** (PITFALLS.md Pitfall 7 anti-pattern) or any `body_md` write outside the two reviewed files.
- **Rendering the diff or the redaction preview through `KnowledgeMarkdown`:** plain escaped text only (UI-SPEC 6.4), so a stored secret or hostile markup never becomes live markup. Blade `{{ }}` only; `KnowledgeSeamTest` already forbids raw `{!!` in `resources/views/filament/knowledge/*.blade.php`, and the new views live there.
- **Hard-coding the depth** (6) anywhere: always `KnowledgeFolder::MAX_DEPTH`.
- **Loading `body_md` in any list** (tree-linked list, Knowledge tabs, versions table).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Line diff | A custom LCS/Myers | `sebastian/diff` 9.0.1 `Differ::diffToArray()` | Linear-space Myers with prefix/suffix trimming already; only the row/line-number walker is custom |
| Tag storage and AND filter | Own pivot or JSON column | `HasTags`, `syncTagsWithType`, `scopeWithAllTags($ids, 'knowledge')` | `scopeWithAllTags` adds one `whereHas('tags', id = ?)` per tag, which is exactly AND (`vendor/spatie/laravel-tags/src/HasTags.php:108-122`) |
| Cycle and depth detection for folders | Another PHP-only guard as the authority | The existing KP003 trigger (backstop) plus PHP pre-check only for the message | The trigger already serialises moves with an advisory lock and counts subtree height |
| Sibling-name uniqueness | App-level check as the authority | The unique index `knowledge_folders_sibling_name_unique` (SQLSTATE 23505) | Race-proof, case-insensitive, NULL-parent aware |
| Secret detection in restore | A second scanner | The call to `SaveArticle::update()` | Single guard, single normaliser, single stale check (SEC-01 restore clause) |
| Markdown rendering of a version | A new renderer | `KnowledgeMarkdown` (existing singleton) | `KnowledgeSeamTest` enforces one renderer |
| Czech ordering | `strcmp`/`usort` | `CzechCollation::orderBy` in SQL, or PHP `Collator` only if the SQL path is impossible | Project already standardises it |
| Client/project select options | Fresh queries | `Project::selectable()` + `CzechCollation::orderBy` as in `TransactionResource::projectOptions` | Consistent archived/closed rules |
| Modal confirmation, notifications, relation manager access | Custom JS | Filament `Action` modals, `Notification`, `EnforcesRelationManagerAccessRule` + `#[AccessRule]` | Already the repository pattern |

**Key insight:** every integrity property here already lives in the database or in `SaveArticle`; the work is routing new writers through those seams and refusing to add a second path to any of them.

## Runtime State Inventory

Not a rename or migration phase (it extends a schema with new columns and a table; no existing runtime string is renamed). Stated explicitly for the one adjacent risk:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Existing `knowledge_article_versions` rows get `restored_from_version_no = NULL` (nullable column, no backfill); existing articles get `redaction_no = 0` via the column default | None beyond the migration defaults |
| Live service config | None | None |
| OS-registered state | None | None |
| Secrets/env vars | None | None |
| Build artifacts | `public/css/app` and `public/js/app` are published by `filament:assets` (git-ignored); the new `resources/css/kokpit-diff.css` needs `FilamentAsset::register` in `KnowledgeServiceProvider::boot()` and a `ddev exec php artisan filament:assets` after merge | Re-publish assets in the verification step |

## Common Pitfalls

### Pitfall 1: One KP003 SQLSTATE for three different reasons
**What goes wrong:** the trigger raises KP003 for self-parent, cycle and depth, with different message texts (`2026_11_25_000300_*.php:82,95,107`); a catch on the SQLSTATE alone gives one generic message, but D-03 and UI-SPEC want distinct explanations.
**Why:** the SQLSTATE is shared by design.
**How to avoid:** `MoveFolder` takes the tree advisory lock itself (`pg_advisory_xact_lock(hashtext('knowledge_folders_tree'))`, same key as the trigger, re-entrant within a session), classifies cycle vs depth in PHP using the same arithmetic (parent depth + subtree height > `MAX_DEPTH`), and throws `FolderMoveRefused(Cycle|Depth)`. Keep the trigger as the backstop: a KP003 that still arrives maps by the message prefix (`own subtree`/`own parent` -> Cycle, `nest at most` -> Depth). Do not depend on the QueryException message format; read `errorInfo` of the previous PDOException.
**Warning signs:** a test that moves into a subtree and expects a depth message.

### Pitfall 2: Depth arithmetic differs between Select options and the trigger
**What goes wrong:** the modal disables options by a PHP depth rule that disagrees with the trigger (off-by-one at level 6 or for a moved subtree).
**How to avoid:** a root is level 1; parent depth = number of ancestors including itself; subtree height = 1 for a leaf (`2026_11_25_000300_*.php:25-31`). Disable a candidate parent when `depth(candidate) + height(moved) > MAX_DEPTH`, when it is the folder itself, or a descendant. Test the boundary: a six-level chain accepts, a seventh is refused (existing schema test shows `knowledgeTablesFolder` helper at `tests/Feature/Schema/KnowledgeTablesTest.php:282-308`).

### Pitfall 3: Duplicate sibling names and NUL bytes
**What goes wrong:** the unique index is case-insensitive and NULL-parent aware; a PHP check that compares case-sensitively accepts "Ops"/"ops" and the database then raises 23505; a NUL byte in a name is silently truncated by the pgsql driver (the reason `ArticleInput` refuses NUL).
**How to avoid:** trim, 1 to 100 chars (UI-SPEC), refuse NUL with a field error, pre-check case-insensitively, map `23505` on `knowledge_folders_sibling_name_unique` to `folders.errors.name_taken` as the backstop. The DB check `name ~ '[^[:space:]]'` rejects blank names.

### Pitfall 4: Delete counts must include archived articles
**What goes wrong:** an "empty" folder (no active articles) still holds archived ones; the RESTRICT FK refuses the delete with 23503 after the confirmation.
**How to avoid:** `DeleteFolder` counts `KnowledgeArticle::withTrashed()` plus child folders before opening the confirmation (UI-SPEC "refused up front"), and re-checks in the transaction; map 23503 as the backstop. The visible tree count excludes archived articles (UI-SPEC), so the two numbers legitimately differ.

### Pitfall 5: Tags written outside the transaction, and case duplicates
See Pattern 2. Also: the "both directions" separation holds only while every tag input/column passes a type. All existing `SpatieTags*` uses and `Tag::query()` calls are typed (`client`, `project`, `task`; verified by grep of `->type(` and `where('type', ...)`). A new untyped tag read would leak knowledge tags into other screens; add a test that creates a knowledge tag and asserts it appears in none of the client, project and task tag suggestions, and that a client tag never appears in the knowledge editor suggestions.

### Pitfall 6: Tag filter AND vs the stock multi-select
`SelectFilter::multiple()` is OR. Use `SelectFilter::make('tags')->multiple()->options(<knowledge tags id => name>)->query(fn ($query, array $data) => ...)` where the callback applies one `whereHas('tags', fn ($q) => $q->whereKey($id))` per selected id (or `withAllTags($ids, 'knowledge')`). The custom `query` hook exists on filters (`vendor/filament/tables/src/Filters/Concerns/InteractsWithTableQuery.php:68`). The tags column eager-loads `->with('tags')` in `modifyQueryUsing` (the list uses a fixed `select`, so keep `id` selected), otherwise the chip column is N+1.

### Pitfall 7: Restore racing a redaction
Covered in Pattern 4. Test: redact version k, then restore k, and assert the new current version holds the marker text; also a concurrency test with two real processes (restore of k vs redaction of k) following `tests/Concurrency/KnowledgeSaveConcurrencyTest.php` and its `knowledge-save-worker.php`, whose cleanup disables user triggers per table, so extend it for the new table's trigger.

### Pitfall 8: Multi-line secrets and line-based redaction
`SecretScanner` reports a finding as (rule, line) only; for a PEM private key the line is the header and the key body lines are "covered" and not reported separately (`SecretScanner` class docblock). Redacting only the reported line leaves the base64 key body in the version, and a rescan finds nothing (the header is gone), so the leak is invisible. Mitigations to build (design, `[ASSUMED]`): (a) `RedactVersion` extends a selected line that matches the PEM header regex to the matching `-----END ... -----` line within a bound (reuse the `KEY_LOOKAHEAD` idea, capped), (b) the redaction modal helper text recommends "Celý text verze" for keys, (c) after redacting, rescan the new body and report any remaining finding (rule and line) in the success notification, without blocking.

### Pitfall 9: The transaction-local setting leaks inside a test transaction
Feature and Isolation tests run in one outer `RefreshDatabase` transaction, so `set_config(..., true)` set by `RedactVersion` stays `on` until the end of the test: a later assertion in the same test that an `UPDATE` is refused would wrongly pass. Always reset (`set_config(name, 'off', true)`) right after the UPDATE inside the Action, and add a schema test that proves the refusal returns after a redaction. Never read the setting from application code; only the trigger reads it.

### Pitfall 10: Arch seam test forbids the second body writer
`knowledgeSeamWrites()` flags any `'body_md' =>` key or `->body_md =` assignment outside `KNOWLEDGE_SEAM_WRITER_FILE` (`tests/Arch/KnowledgeSeamTest.php`). `RedactVersion` writes `body_md` of two tables with the query builder (`'body_md' =>` key). Change the constant to a reviewed list (`SaveArticle.php`, `RedactVersion.php`), keep each file's self-check (the scan must see the key where it is written), and add rules that (a) `RedactVersion` never writes `version_no`, (b) only `RedactVersion` mentions the redaction setting name (and the migration), (c) no file selects or logs the redaction `reason` column other than the Action that inserts it. Do not bypass by hiding the write in raw SQL strings to dodge the scanner.

### Pitfall 11: Marker length can exceed the body cap
Replacing an empty line with `[REDIGOVÁNO]` lengthens the body; at 99,995 characters the article/version CHECK `char_length(body_md) <= 100000` raises 23514. Pre-check the redacted length (`KnowledgeArticle::MAX_BODY_LENGTH`) and refuse with a field error ("Row N") instead of letting the database refuse, and prefer not to redact blank lines (selecting one is refused as "nothing to redact").

### Pitfall 12: Diff cost on adversarial bodies
`MyersDiff` is linear space but O((N+M)·D) time (`vendor/sebastian/diff/src/MyersDiff.php` docblock). A 100,000-character body can have up to 50,000 one-character lines; two completely different such bodies make D huge and the request time out. The UI-SPEC row cap (1,500 rows) limits rendering, not computation. Add a work budget in `VersionDiff` (design, `[ASSUMED]`): after trimming the common prefix and suffix, if `(n + m)` exceeds a measured threshold, skip the algorithm and show a "too large to compare" state (new Czech copy needed, not in UI-SPEC: flag to the planner). Measure with a worst-case fixture in DDEV before fixing the threshold.

### Pitfall 13: The editor's `activeTab`/preview must not break with new fields
The create/edit forms use `Tabs::make()->livewireProperty('activeTab')` and a `Callout` footer view that reads `$livewire instanceof SecretGuardForm`. The new `Section` "Zarazeni" goes between the tabs and the sensitive toggle (UI-SPEC) and must not be `live()` on text fields (each live roundtrip carries the whole body). Only client needs `->live()`.

### Pitfall 14: Prefill from the query string
`?folder=`, `?client=`, `?project=` on the create screen come from the URL: accept only values that are UUIDs and exist (and a project only if it belongs to the client), otherwise ignore silently; the Selects reject values outside their options on validation anyway.

### Pitfall 15: Knowledge tab scope
`KnowledgeArticleResource::getEloquentQuery()` limits to `kind = general`, but the relation managers query through a `hasMany` on the model: add `where('kind', ArticleKind::General->value)` (incident articles arrive in Phase 21 and get their own tab or an extension) and `withoutGlobalScopes([SoftDeletingScope::class])` plus the archived toggle, like `ClientProjectsRelationManager`. A relation manager needs `knowledgeArticles()` relations on `Client` and `Project` (new `HasMany` methods).

### Pitfall 16: Redacted `title`
D-12 redacts the body only; the title is never scanned (Phase 18 D-02) and is not redacted. State this in the modal copy review so nobody assumes otherwise.

## Code Examples

### Tag AND filter (verified API)
```php
// Source: vendor/spatie/laravel-tags/src/HasTags.php:108-122 (scopeWithAllTags adds one whereHas per tag)
$query->withAllTags($selectedTagIds, TagType::Knowledge->value);   // requires `use HasTags` on KnowledgeArticle
```

### Descendant folder ids (pattern from the trigger's own CTE)
```sql
-- Source: shape of the recursive CTE in database/migrations/2026_11_25_000300_create_knowledge_folders_table.php:99-103
WITH RECURSIVE subtree(id) AS (
  SELECT id FROM knowledge_folders WHERE id = ?
  UNION ALL
  SELECT f.id FROM knowledge_folders f JOIN subtree s ON f.parent_id = s.id
)
SELECT id FROM subtree
```

### Line selection for redaction (parse "3, 7-9")
```php
// Pure function, unit-testable; reject non-digits, ranges where from > to, and numbers above the line count.
// Line numbering is 1-based over preg_split('/\r\n|\r|\n/', $body), the same split as SecretScanner (SecretScanner.php:31,200).
```

### Folder move refusal mapping
```php
// DB backstop only; the PHP classification under the advisory lock runs first.
catch (QueryException $e) { if (($e->errorInfo[0] ?? null) === 'KP003') { /* map by previous PDOException message prefix */ } }
```

### Test: the trigger variant (schema test style of tests/Feature/Schema/KnowledgeTablesTest.php:144-150)
```php
RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $id)->update(['body_md' => 'x'])); // without the setting
// then inside DB::transaction: set_config('...','on',true); update body_md => succeeds; update title => KP001; delete => KP001; reset the setting
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Resource sub-pages via custom pages only | `ManageRelatedRecords` + `getRecordSubNavigation()` in Filament 5 | in installed v5.12.0 | Versions page gets sub-navigation tabs for free |
| Plugin-synced tag input everywhere | Typed tags written by a Domain Action | repository pattern (`UpdateTask`, `CreateTask`) | Atomic with the rest of the write |
| `jfcherng/php-diff` as default | `sebastian/diff` already locked | 2026 (v9 requires PHP >= 8.4) | No new dependency |

**Deprecated/outdated:** none relevant. Research file `ARCHITECTURE.md` suggested a depth limit "checked in `MoveFolder`, not in SQL"; Phase 18 implemented it in SQL instead (trigger), so `MoveFolder` is explanatory plus backstop.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | A `redaction_no` counter column on `knowledge_articles` is the chosen stale-token mechanism (UI-SPEC leaves the mechanism to the planner; a body-hash token also satisfies the contract) | Pattern 3 | Low: either works; changes the `update()` signature either way |
| A2 | Storing the redaction reason text in an append-only `knowledge_version_redactions` table (never displayed, never logged) | Pattern 1, Open Question 1 | Medium: the reason may itself contain a secret and creates another store; owner may prefer to persist only that a reason existed |
| A3 | PostgreSQL keeps an empty-string placeholder for a custom setting after a transaction-local `set_config` ends, so the trigger must `coalesce` | Pattern 1 | Low: `coalesce` is correct in both cases |
| A4 | Laravel 13 query builder may lack a recursive-CTE helper; raw CTE with bindings used | Pattern 6 | Low |
| A5 | Static route `compare` registered before `{version}` wins in Laravel route matching | Pattern 7 | Low: add a numeric constraint if not |
| A6 | PEM block extension heuristic and post-redaction rescan notice (Pitfall 8) | Pitfall 8 | Medium: without it a multi-line key leaks through a line-based redaction |
| A7 | Diff work budget and "too large" state (threshold to be measured; new Czech copy) | Pitfall 12 | Medium: unbounded request time on hostile bodies |
| A8 | Concurrent creation of the same new tag can duplicate it (no unique index on `tags`); accepted for a single-Admin tool | Pattern 2 | Low |
| A9 | `restored_from_version_no` as a version-row column (rather than deriving "restored" from the activity log) | Pattern 1 | Low: UI-SPEC lists it as unresolved and needing a persisted column |
| A10 | Folder create/rename/move/delete write no activity rows (consistent with the `KnowledgeFolder` docblock "Not logged") | Summary | Low: owner may want a metadata-only audit of folder changes |
| A11 | The Filament action modal can stay open on a guard refusal through `Halt` plus locked page properties (mirrors `GuardsSecrets` for forms); not prototyped for modal actions | Pattern 4, UI | Medium: modal composition is the least-verified UI piece; recommend a spike task before the restore UI plan |

## Open Questions (RESOLVED)

1. **Where does the redaction reason live?**
   - What we know: D-12 requires a reason; the activity log must record only that a reason exists; the UI never shows it again; the version row is frozen apart from `body_md`.
   - What's unclear: whether to persist the text at all (a typed secret in the reason would create a fifth store, per PITFALLS.md "secrets leak into five places").
   - Recommendation: store it in the append-only redactions table with a 500-char cap, never select it in application queries (Arch rule), and keep the helper text warning not to type the secret. If the owner prefers zero storage, persist only `reason_length` and keep the same table.
   - RESOLVED (owner decision OD-1, 2026-11-25): length only. `knowledge_version_redactions` keeps `reason_length` (1 to 500), no column for the text (the flag that a reason exists is the `reason_recorded` property of the `version_redacted` activity row); the Admin still types a required reason, `RedactVersion` validates it and discards it. See 19-01 R-19-01-3, 19-09 R-19-09-3 and R-19-09-4 (Arch persistence ban), 19-11 R-19-11-4.
2. **Should restore be allowed for a version that currently fails the guard after a partial redaction?**
   - What we know: D-11 refuses an unambiguous secret; UI-SPEC shows a link to redact.
   - Recommendation: keep the refusal as specified; no special case.
   - RESOLVED (planner, per D-11): no special case; a Blocking finding refuses the restore, an Ambiguous one needs the one-restore acknowledgment. See 19-09 R-19-09-OQ2 and 19-11 R-19-11-3.
3. **Redaction of a version whose content also lives in later versions.**
   - What we know: only the chosen row (and the article when it is current) changes (D-12, D-13); a secret that survived several saves exists in every later snapshot.
   - Recommendation: no automatic cascade (not in scope); the modal copy already says rotating the secret is the real remedy. Optionally show "same text appears in N other versions" (cheap hash compare), planner's discretion.
   - RESOLVED (planner, within D-12): no cascade and no count of other versions (a count would bind the redacted line text into a query parameter). D-12 limits the redaction to the chosen stored version; the owner chose an explicit on-screen warning of this consequence (owner decision OD-3, 2026-11-26): the redaction modal shows `knowledge.redact.later_versions_keep`. See 19-09 R-19-09-OQ3, 19-02 R-19-02-3 and 19-11 R-19-11-4.
4. **Does the Knowledge tab include incidents later?** Phase 21 owns that; keep the relation manager filtered to `general` now.
   - RESOLVED (planner): the client and project Knowledge tabs list only the general kind in Phase 19; incidents are Phase 21's. See 19-07 R-19-07-2.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | Tests, migrations, assets | yes (binary at `/opt/homebrew/bin/ddev`; not started or probed) | not probed | none; project rule requires DDEV |
| PHP on the host | Static reads only | yes | 8.6.0 (host); project requires `^8.5` (DDEV runs the project's version) | run everything in DDEV |
| PostgreSQL 18 (DDEV) | Triggers, recursive CTE, `uuidv7()`, `NULLS NOT DISTINCT` | not probed (host has no DB by project memory note) | project assumes 18 | none |
| `sebastian/diff` | Diff | yes (`vendor/`, 9.0.1) | 9.0.1 | n/a |
| `gsd-tools` seams | Research caching | n/a (not used; research was in-repo plus one docs fetch) | n/a | n/a |

**Missing dependencies with no fallback:** none identified (DB and DDEV are exercised only after merge, per project rules).
**Missing dependencies with fallback:** none.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest (PHPUnit 13 runner) with Livewire/Filament testing helpers; suites `Unit`, `Arch`, `Feature`, `Isolation`, `Concurrency`, `Audit` (`tests/Pest.php`) |
| Config file | `phpunit.xml`, `tests/Pest.php` |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Knowledge/<File>.php` (single file) |
| Full suite command | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (no `--parallel`) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ORG-01 | Create, rename, move; cycle refused (reason Cycle), depth refused (reason Depth, cap from `MAX_DEPTH`), duplicate sibling name refused | Feature (Actions) + Schema | `pest tests/Feature/Knowledge/FolderActionsTest.php` | no, Wave 0 |
| ORG-01 | KP003 backstop reachable by raw SQL still refused after the Action layer exists | Schema | `pest tests/Feature/Schema/KnowledgeTablesTest.php` | yes (extend) |
| ORG-01 | Tree panel renders, counts exclude archived and include subfolders, selection via `?folder=`, invalid value falls back to all | Feature (Livewire) | `pest tests/Feature/Knowledge/FolderTreeListTest.php` | no, Wave 0 |
| ORG-02 | Delete refused for subfolders, active and archived articles; allowed for empty; notification shows contents | Feature | `pest tests/Feature/Knowledge/FolderActionsTest.php` | no, Wave 0 |
| ORG-03 | Knowledge tags typed; never in client/project/task tag suggestions and vice versa; case-insensitive merge; AND filter; combine with folder and archived | Feature | `pest tests/Feature/Knowledge/KnowledgeTagsTest.php` | no, Wave 0 |
| ORG-03 | Classification-only save: no new version, `edited_at` unchanged, outcome `ClassificationChanged`; stale/guard-refused save writes no tags | Feature | `pest tests/Feature/Knowledge/SaveArticleClassificationTest.php` | no, Wave 0 |
| ORG-04 | Project must belong to client (DB + form), client change clears project, prefill from query | Feature | `pest tests/Feature/Knowledge/ArticleLinksTest.php` | no, Wave 0 |
| ORG-04 | Knowledge tabs: rows, archived toggle, add action prefill, Partner sees no tab | Feature + Isolation | `pest tests/Feature/Knowledge/KnowledgeRelationManagersTest.php`, `tests/Isolation/KnowledgeScreensTest.php` | no, Wave 0 / yes (extend) |
| VER-02 | Versions page lists versions newest first, badges (current, restored from, redacted), version view read-only, 404 for missing number | Feature | `pest tests/Feature/Knowledge/ArticleVersionsPageTest.php` | no, Wave 0 |
| VER-04 | Diff rows, line numbers, context collapse, row cap notice, identical and same-version states, escaped text (hostile corpus via `KnowledgeHostileCorpus`) | Unit + Feature | `pest tests/Unit/Knowledge/VersionDiffTest.php`, `tests/Feature/Knowledge/VersionComparePageTest.php` | no, Wave 0 |
| VER-03 | Restore writes version n+1 with `restored_from_version_no`, history intact, guard refusal (Blocking) writes nothing, ambiguous needs acknowledgment once and marks sensitive, stale refused, archived refused | Feature | `pest tests/Feature/Knowledge/RestoreVersionTest.php` | no, Wave 0 |
| VER-03 | Restore vs redaction race | Concurrency | `pest tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php` | no, Wave 0 |
| VER-05 | Redaction replaces lines/whole body, keeps the row, trigger refuses everything else, reset after use, activity row has no value or reason text, redacted version in view/diff/restore shows marker only, current-version redaction updates the article and bumps `redaction_no`, open editor save refused as stale | Feature + Schema | `pest tests/Feature/Knowledge/RedactVersionTest.php`, `tests/Feature/Schema/KnowledgeTablesTest.php` | no, Wave 0 / yes (extend) |
| SEC-01/03 | Activity log, exceptions and notifications never contain body, line text or reason (secret sample from `SecretFixtures` only) | Feature | extend `tests/Feature/Knowledge/SaveArticleTest.php` log test pattern | yes (extend) |
| Seams | Second reviewed body writer; `expectedRedactionNo` has no default; only `RedactVersion` names the setting; reason column never selected | Arch | `pest tests/Arch/KnowledgeSeamTest.php` | yes (extend) |
| Partner | Every new page, relation manager and route declared; Partner 403 on all new URLs | Audit + Isolation | `pest tests/Audit tests/Isolation/KnowledgeScreensTest.php` | yes (extend `PartnerMatrix`) |
| Lang | Every new `knowledge.*` key exists and is Czech | Feature | `pest tests/Feature/Knowledge/KnowledgeLangTest.php` | yes (extend) |

### Sampling Rate
- **Per task commit:** the single relevant test file in DDEV after the merge; in a worktree only formatting (Pint) and static analysis that needs no DB.
- **Per wave merge:** `ddev exec vendor/bin/pest --testsuite=Arch,Unit` plus the Feature files of that wave.
- **Phase gate:** the full CI-equivalent suite above green, then `ddev exec vendor/bin/phpstan analyse` and `scripts/check-sensitive.sh`, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Knowledge/FolderActionsTest.php`, `FolderTreeListTest.php`, `KnowledgeTagsTest.php`, `SaveArticleClassificationTest.php`, `ArticleLinksTest.php`, `KnowledgeRelationManagersTest.php`, `ArticleVersionsPageTest.php`, `VersionComparePageTest.php`, `RestoreVersionTest.php`, `RedactVersionTest.php`
- [ ] `tests/Unit/Knowledge/VersionDiffTest.php` (row builder incl. context collapse, cap, escaping)
- [ ] `tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php` plus a worker script (reuse `knowledge-save-worker.php` structure; extend its trigger cleanup for the new table)
- [ ] `KnowledgeFixtures` helpers: article with tags, a folder chain of a given depth, a version with `restored_from_version_no`
- [ ] Extend `tests/Support/PartnerMatrix.php` (relation managers, new model `KnowledgeVersionRedaction` in `adminOnlyModels()`), `tests/Support/CanaryRegistry.php` (a canary row for the redactions model), `tests/Arch/KnowledgeSeamTest.php`, `tests/Feature/Schema/KnowledgeTablesTest.php`
- [ ] Framework install: none (Pest present)

## Security Domain

### Applicable ASVS Categories (level 1, `security_enforcement` enabled in `.planning/config.json`)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no new auth surface) | existing panel auth |
| V3 Session Management | no | existing |
| V4 Access Control | yes | `AdminOnlyPolicy` on all three models and the new redactions model, `#[AccessRule(Audience::AdminOnly)]` on the new relation managers, `abort_unless(canAccess(), 403)` in every new page `mount()`, `#[Locked]` Livewire properties, Partner matrix rows |
| V5 Input Validation | yes | `ArticleClassification` validation (UUID existence, project-of-client), folder name rules and NUL refusal, redaction line-list parser, reason length, Select option scoping; diff and preview rendered as escaped text |
| V6 Cryptography | no | none added |
| V7 Error handling and logging | yes | metadata-only activity rows, fixed-message exceptions without content (extend the `ArticleWriteFailed` pattern for redaction failures), no value, line text or reason in logs, notifications or exception messages |
| V8 Data protection | yes | redaction path, `sensitive` flag preserved on restore, reason never echoed |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Livewire tampering with folder id, version number or the client-written `#[Url]` folder | Tampering | Re-resolve and authorise server-side in each Action; `Str::isUuid` normalisation; `#[Locked]` on tokens and findings |
| Stored XSS through a version body or title in view or diff | Tampering/Information disclosure | Version view only through `KnowledgeMarkdown`; diff and preview plain escaped text; arch rule against raw Blade echo already covers `resources/views/filament/knowledge` |
| Restore re-introducing a leaked secret | Information disclosure | Single guard path; redaction applies to the stored row so restore and diff read the redacted body; lock order article then version |
| Redaction setting abused to rewrite history | Tampering | Trigger allows only a body change while the transaction-local setting is on and all other columns are identical; DELETE/TRUNCATE stay refused; Arch rule limits who names the setting; production DB role is not a superuser (existing assumption A4 note in the migrations) |
| Secret leaking into the activity log, exceptions or Sentry via the new Actions | Information disclosure | Content-free exceptions, explicit activity rows with allowlisted properties, tests asserting the log tables hold no sample secret (cross-cutting STATE.md item "scrub body from Sentry and logs" remains owned by the cross-cutting work, not this phase) |
| Partner reaching Knowledge tabs, version URLs or folder names | Information disclosure | Fail-closed scopes (`DeniesPartners`, `IsolatesPartners` pattern), matrix and route declarations, 403 tests for every new URL |
| Oversized or adversarial body causing diff DoS | Denial of service | Row cap plus work budget (Pitfall 12) |

## Sources

### Primary (HIGH confidence, read this session)
- `app/Domain/Knowledge/Actions/SaveArticle.php`, `ArticleInput.php`, `RestoreArticle.php`, `ArchiveArticle.php`; `Exceptions/*`; `Models/KnowledgeArticle.php`, `KnowledgeFolder.php`, `KnowledgeArticleVersion.php`; `Guard/SecretScanner.php`, `SecretFinding.php`
- `database/migrations/2026_11_25_000100_create_knowledge_articles_table.php`, `..._000200_create_knowledge_article_versions_table.php`, `..._000300_create_knowledge_folders_table.php`, `2026_10_07_152543_create_tag_tables.php`
- `app/Filament/Resources/KnowledgeArticleResource.php`, its `Pages/*` and `Concerns/*`; `ClientProjectsRelationManager.php`; `EnforcesRelationManagerAccessRule.php`; `TimeEntryResource.php` and `TransactionResource.php` select patterns
- `tests/Arch/KnowledgeSeamTest.php`, `tests/Support/PartnerMatrix.php`, `RouteRules.php`, `KnowledgeFixtures.php`, `tests/Isolation/KnowledgeVisibilityTest.php`, `KnowledgeScreensTest.php`, `tests/Concurrency/KnowledgeSaveConcurrencyTest.php`
- `vendor/sebastian/diff` (composer.json, `Differ.php`, `MyersDiff.php`, `DiffOnlyOutputBuilder.php`), `composer.lock`, `composer.json`, `scripts/check-licenses.php`
- `vendor/spatie/laravel-tags/src/HasTags.php`, `Tag.php`; `vendor/filament/spatie-laravel-tags-plugin/src/Forms/Components/SpatieTagsInput.php`
- `vendor/filament/filament/src/Resources/Pages/ListRecords.php`, `Resource/Concerns/HasNavigation.php`, `Pages/Concerns/HasSubNavigation.php`; `vendor/filament/actions/src/Concerns/InteractsWithActions.php`; `vendor/filament/forms/docs/03-select.md` (`disableOptionWhen`)
- `.planning/phases/19-*/19-CONTEXT.md`, `19-UI-SPEC.md`, `.planning/research/PITFALLS.md`, `ARCHITECTURE.md`, `SUMMARY.md`, `.planning/STATE.md`, `REQUIREMENTS.md`, `ROADMAP.md`

### Secondary (MEDIUM confidence)
- PostgreSQL documentation, "System Administration Functions" (https://www.postgresql.org/docs/current/functions-admin.html): `set_config(setting_name, new_value, is_local)` with `is_local = true` applies only during the current transaction; `current_setting(setting_name, missing_ok)` returns NULL for an unknown setting when `missing_ok` is true.

### Tertiary (LOW confidence)
- Training knowledge only (tagged `[ASSUMED]` in the Assumptions Log): custom GUC placeholder behaviour, Laravel route registration order, absence of a recursive-CTE helper in the query builder.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, everything is already locked and was read in `vendor/`.
- Architecture: HIGH for the schema and write-seam constraints (all migrations and the Arch test read); MEDIUM for the Filament modal composition of restore/redaction (not prototyped, A11).
- Pitfalls: HIGH for the ones derived from code read this session (1, 3, 4, 5, 7, 9, 10, 11); MEDIUM for 8 and 12 (designs not yet measured or built).

**Research date:** 2026-11-25
**Valid until:** 30 days (stable stack; re-check Filament 5.x minor changes to `ListRecords::content()` and `ManageRelatedRecords` if the lock changes).
