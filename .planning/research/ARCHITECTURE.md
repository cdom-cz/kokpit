# Architecture Research: Knowledge Center (milestone v1.1)

**Domain:** Knowledge base inside an existing single-tenant CRM/ERP (Laravel 13, Filament 5.12 SPA panel, PostgreSQL 18, Redis queue, S3-compatible private bucket)
**Researched:** 2026-11-25
**Confidence:** HIGH for integration points (read from the real code under `app/`, `database/migrations/`, `tests/`); MEDIUM for the full-text configuration and the Markdown rendering pipeline (designed from documented PostgreSQL, CommonMark and Filament behaviour, not yet executed in this repository)

All names, companies and e-mail addresses in this document are fictional (`Example Ltd`, `example.com`). No content, title or link from any external workspace appears here, by hard rule.

This file replaces the v1 architecture research (recoverable from git history); the application it described is shipped, so everything below builds on code that exists.

## What exists and what the Knowledge Center must reuse

Verified by reading the code:

| Existing mechanism | Where | How Knowledge uses it |
|--------------------|-------|-----------------------|
| `KokpitModel` (HasUuids, UUID v7, `timestampsTz`, one morph alias, isolation declared on the class) | `app/Domain/Shared/Models/KokpitModel.php` | Base of every new model |
| Fail-closed `PartnerScope` + `PartnerIsolated::constrainForPartner()` + `IsolatesPartners` / `DeniesPartners` | `app/Domain/Shared/Auth/` | Every new model implements one of the two; `tests/Arch/ModelDeclarationTest` fails otherwise |
| `KokpitPolicy` default-deny, `AdminOnlyPolicy`, policies registered in `AccessServiceProvider` | `app/Domain/Shared/`, `app/Providers/AccessServiceProvider.php` | One new policy for articles; everything else `AdminOnlyPolicy` |
| `#[AccessRule(Audience::...)]` on every Resource, Page, Widget, RelationManager + `EnforcesResourceAccessRule` | `app/Domain/Shared/Auth/AccessRule.php`, `tests/Arch/PanelRegistryTest.php` | Mandatory on every new Filament class |
| Admin half / Partner half split into two tables (`projects` + `project_billing`, `invoices` + `invoice_admin`, D-05) | `ProjectBilling`, `InvoiceAdmin` | Pattern for the Admin-only retrospective time overview |
| Separate Partner resource classes with their own slug (`PartnerProjectResource`, slug `my-projects`) over the same scoped model | `app/Filament/Partner/Resources/` | Pattern for the Partner view of shared knowledge |
| `LogsAllowlistedActivity` + `#[LoggedAttributes([...])]` (free text never logged) | `app/Domain/Audit/` | Article metadata logged; title and body are not |
| Spatie tags with typed tags (`TagType` enum, custom `Tag` model that is Partner-visible only for project tags on visible projects) | `app/Domain/Shared/Tags/TagType.php`, `Shared/Models/Tag.php` | New `TagType::Knowledge`; Partner sees none of them with no change to `Tag` |
| `Media` model with UUID keys, `DeniesPartners`; file streaming controller with signed route, user id under the signature, Partner-scoped lookup, `nosniff`, `private, no-store` | `Shared/Models/Media.php`, `DocumentFileController` | Pattern for image attachments |
| Accent-insensitive search: `kokpit_search_text()` (lower of unaccent, IMMUTABLE) + GIN trigram indexes + `AccentInsensitiveSearch::where()` | migration `2026_10_30_000100`, `Shared/Database/AccentInsensitiveSearch.php` | Reused for title matching; extended with a tsvector for body text |
| Panel `->globalSearch()->globalSearchResourceOptIn()`; only Client, Project, Task resources opt in | `AdminPanelProvider` | Knowledge resources deliberately do NOT opt in; the Knowledge search is its own page |
| `TimeTotals::forProject()` and the per-task time columns query; Admin-only (`TimeEntry` is `DeniesPartners`) | `app/Domain/TimeTracking/Queries/TimeTotals.php` | Source of the retrospective time overview, no new time maths |
| Composite FK `(project_id, client_id) -> projects(id, client_id)` via `projects_id_client_unique` | migrations `time_entries`, `documents`, `transactions` | Same constraint on `knowledge_articles` |
| Actions as the only write path (`CreateProject`, `UpdateProject` + input DTO), Filament pages only hand form state to them | `app/Domain/*/Actions` | `Domain/Knowledge/Actions`; also what a later API/MCP layer will call |
| `RichText` sanitiser (HtmlSanitizer, write and render) and `RichTextMarkdown` | `Shared/Text/` | Stays for task descriptions; Knowledge adds a separate Markdown renderer, never mixes the two |
| Partner permission matrix, route rules, model list as literal declarations in `tests/Support` | `PartnerMatrix`, `RouteRules`, `ModelDeclaration` | Must be edited in the same pull request as each new screen, route or model |
| `Concurrency` and `Isolation` Pest suites in CI | `tests/Concurrency`, `tests/Isolation` | New suites for concurrent saves and Knowledge leak checks |
| Navigation group via trait (`InSignalGroup`) and `lang/cs/kokpit.php` keys | `app/Filament/Concerns/InSignalGroup.php` | New `InKnowledgeGroup` trait, key `kokpit.knowledge.navigation_group` = "Znalosti" |

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         Filament panel (SPA, Czech UI)                         │
├──────────────────────────────────────────────────────────────────────────────┤
│  Group "Znalosti"                                                              │
│  ┌──────────────┐ ┌──────────────┐ ┌────────────────┐ ┌──────────────────┐    │
│  │ Knowledge-   │ │ Incident-    │ │ Retrospective- │ │ KnowledgeSearch- │    │
│  │ Article-     │ │ Resource     │ │ Resource       │ │ Page             │    │
│  │ Resource     │ │  └ nested    │ │ (list, view,   │ │ (not in the      │    │
│  │ (general)    │ │  IncidentPage│ │  edit)         │ │  global search)  │    │
│  └──────┬───────┘ └──────┬───────┘ └───────┬────────┘ └────────┬─────────┘    │
│         │   Partner side: PartnerKnowledgeResource (read-only, shared only)    │
│  Project view page: header action "Vytvořit retrospektivu"                     │
├─────────┴────────────────┴─────────────────┴──────────────────┴──────────────┤
│                         Domain/Knowledge (new)                                  │
│  Actions: SaveArticle, RestoreVersion, MoveFolder, CreateIncidentPage,         │
│           CreateRetrospective, AttachImage, ArchiveArticle                      │
│  Support: SecretScanner, MarkdownRenderer, ArticleDocument (AI export),         │
│           RetrospectiveTemplate, IncidentPageType                               │
│  Queries: KnowledgeSearch, FolderTree, ArticleListing                           │
├──────────────────────────────────────────────────────────────────────────────┤
│                  Access layer (existing, extended not changed)                  │
│  PartnerScope on KnowledgeArticle / Image (own rules), DeniesPartners on        │
│  Folder / Version / TimeOverview, KnowledgeArticlePolicy, AdminOnlyPolicy       │
├──────────────────────────────────────────────────────────────────────────────┤
│  PostgreSQL: knowledge_folders, knowledge_articles, knowledge_article_versions, │
│  knowledge_images (+ media), retrospective_time_overviews, taggables (tags),    │
│  activity_log                                                                   │
│  S3 private bucket: article images only (through medialibrary, as system)       │
│  Reads from: projects, clients, tasks, time_entries (retrospective only)        │
└──────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | New / Modified | Responsibility |
|-----------|----------------|----------------|
| `KnowledgeArticle` model | NEW | One row for every article kind; owns the canonical Markdown, current version number, link to client/project, client-access flag; Partner scope |
| `KnowledgeFolder` model | NEW | Admin-only folder tree for general articles |
| `KnowledgeArticleVersion` model | NEW | Append-only full snapshot (title + Markdown) per save; Admin-only |
| `KnowledgeImage` model | NEW | An image attached to an article; owns one medialibrary media row on the private disk; Partner-visible exactly when its article is |
| `RetrospectiveTimeOverview` model | NEW | Admin-only frozen time snapshot of a retrospective (the Admin half, like `ProjectBilling`) |
| `KnowledgeArticlePolicy` | NEW | Partner may `viewAny` and `view` a shared article of the own client; every write denied |
| `SaveArticle` and the other Actions | NEW | The only writers: lock, optimistic version check, secret guard, version row, activity row, all in one transaction |
| `SecretScanner` | NEW | Pattern-based refusal of secrets on save, never echoes the matched value |
| `MarkdownRenderer` | NEW | Markdown to safe HTML with copy-button code blocks and signed image URLs |
| `KnowledgeSearch` | NEW | Full-text query that only adds predicates to an already scoped builder |
| `ArticleDocument` | NEW | Renders an article as Markdown with a front-matter header; the AI-friendly format and the future API/MCP payload |
| `TagType` enum | MODIFIED | New case `Knowledge = 'knowledge'` |
| `MorphMap` | MODIFIED | Five new aliases |
| `AccessServiceProvider` | MODIFIED | Policy registrations for the new models |
| `AdminPanelProvider` | MODIFIED (small) | Optional `->navigationGroups([...])` to pin the order of "Znalosti"; nothing else |
| `ViewProject` page | MODIFIED | One header action "Vytvořit retrospektivu" (Admin only) |
| `ProjectResource` / `ClientResource` | MODIFIED (late) | A "Znalosti" relation manager each; optional, last phase |
| `PartnerProjectResource` | MODIFIED (late) | A read-only "Znalosti" relation manager; optional, last phase |
| `tests/Support/*`, `tests/Arch/ModelDeclarationTest`, `PanelRegistryTest` | MODIFIED | New literal declarations in the same PR as each screen |
| `lang/cs/kokpit.php` | MODIFIED | `kokpit.knowledge.*` keys (all user-facing text is Czech) |
| `Document` model, `DocumentOwnerType`, `DocumentFileController` | UNCHANGED | Deliberately not reused for images (see Pattern 6) |
| `Tag`, `RichText`, `TimeTotals`, global search config | UNCHANGED | Reused as they are |

## Recommended Project Structure

```
app/Domain/Knowledge/
├── Actions/
│   ├── SaveArticle.php                 # create + update, the one write path; version + guard + activity
│   ├── ArticleInput.php                # input DTO, no Filament types (API/MCP-ready)
│   ├── RestoreVersion.php              # writes a NEW version copying an old one
│   ├── CreateIncidentPage.php          # typed sub-page of a hub
│   ├── CreateRetrospective.php         # from a project: article + template + time snapshot
│   ├── AttachImage.php
│   ├── DeleteImage.php
│   ├── MoveFolder.php                  # cycle and depth checks
│   └── ArchiveArticle.php              # soft delete (archive), restore
├── Enums/
│   ├── ArticleKind.php                 # General, Incident, IncidentPage, Retrospective
│   ├── IncidentPageType.php            # typed sub-pages of an incident
│   └── VersionChange.php               # Created, Edited, Restored
├── Models/
│   ├── KnowledgeArticle.php
│   ├── KnowledgeFolder.php
│   ├── KnowledgeArticleVersion.php
│   ├── KnowledgeImage.php
│   └── RetrospectiveTimeOverview.php
├── Policies/
│   └── KnowledgeArticlePolicy.php
├── Queries/
│   ├── KnowledgeSearch.php
│   ├── FolderTree.php                  # recursive CTE, descendants of a folder
│   └── RetrospectiveOverviewBuilder.php# reads TimeTotals as system, returns a DTO
├── Guard/
│   ├── SecretScanner.php
│   └── SecretFinding.php               # category + line number, never the value
└── Support/
    ├── MarkdownRenderer.php
    ├── ArticleDocument.php
    ├── RetrospectiveTemplate.php       # the fixed Markdown skeleton (Czech headings from lang)
    └── ImageToken.php                  # parse/format  kokpit-image:<uuid>

app/Filament/Resources/
├── KnowledgeArticleResource.php        # general; Pages: List, Create, View, Edit
├── IncidentResource.php                # hubs only; RelationManager "Podstránky"
├── IncidentPageResource.php            # nested under IncidentResource, no nav entry, no list page
├── RetrospectiveResource.php           # List, View, Edit (no Create: made by the project button)
└── KnowledgeArticleResource/Pages/...  # one Pages folder per resource, as today

app/Filament/Partner/Resources/
└── PartnerKnowledgeResource.php        # slug "knowledge-shared"; List, View; Partner-safe columns only

app/Filament/Pages/Knowledge/
└── KnowledgeSearchPage.php             # own search page, #[AccessRule(PartnerAllowed)]

app/Filament/Concerns/InKnowledgeGroup.php
app/Http/Controllers/KnowledgeImageController.php   # signed route, like DocumentFileController
database/migrations/2026_12_xx_*_create_knowledge_*.php
tests/Feature/Knowledge/  tests/Isolation/Knowledge*  tests/Concurrency/KnowledgeSaveTest
```

### Structure Rationale

- **`Domain/Knowledge` is a new bounded folder, like `Domain/Tasks`.** The repository organises by domain, Filament classes only shape forms and tables. Keeping every rule in Actions is what makes the later API/MCP milestone a thin controller layer instead of a rewrite.
- **Three Resources over one model, not one resource with tabs.** The brief fixes three menu entries. Filament allows several resources on one model; each overrides `getEloquentQuery()` with a kind filter and its own slug (`knowledge`, `incidents`, `retrospectives`). They share one policy. `strictAuthorization()` is on, so each resource needs a policy method, which the shared policy provides.
- **`IncidentPageResource` as a nested resource.** Filament 5.12 ships nested-resource support (`BelongsToParent` concern is in the vendor tree). Sub-pages get their own View and Edit pages and URLs under the hub (`/incidents/{hub}/pages/{page}`), have no list page and no navigation entry, and so are "hidden from the listing". MEDIUM: nested resources were not exercised here; the phase should spike one page first. Fallback: a plain resource with `$shouldRegisterNavigation = false` and no list page.
- **A separate Partner resource.** Same decision v1 made for projects: a Partner class is read-only and exposes only Partner-safe columns, and the Admin never gets a second menu entry.

## Data Model

Conventions copied from the existing migrations: uuid primary key with `uuidv7()` column default, `timestampsTz()`, FK, unique, partial indexes and CHECK constraints in the database, soft delete = archive, never a force-delete action.

### `knowledge_folders` (Admin-only)

| Column | Notes |
|--------|-------|
| `id` uuid pk | |
| `parent_id` uuid null FK self, `ON DELETE RESTRICT` | adjacency list |
| `name` text | CHECK `name ~ '[^[:space:]]'` (same as `documents_name_check`) |
| `position` int | spatie eloquent-sortable (already a dependency) |
| `UNIQUE NULLS NOT DISTINCT (parent_id, name)` | PostgreSQL 15+, the project runs 18; one root namespace without two partial indexes |
| trigger `knowledge_folders_cycle_guard` | refuses `parent_id` that is the row itself or one of its descendants (a CHECK cannot see the tree); same style as the existing `kokpit_guard_*` triggers |

Adjacency list plus recursive CTE, not ltree or nested sets: a single company has hundreds of folders, moves are rare, and the plain model keeps `parent_id` an ordinary FK. Depth limit (suggest 6) is checked in `MoveFolder`, not in SQL.

### `knowledge_articles`

| Column | Notes |
|--------|-------|
| `id` uuid pk | URLs and API use the UUID; no slug column (project convention) |
| `kind` text | CHECK in (`general`, `incident`, `incident_page`, `retrospective`); all four kinds exist from the first migration so later phases never alter the CHECK |
| `parent_id` uuid null FK self `RESTRICT` | only incident sub-pages |
| `page_type` text null | `IncidentPageType` value, only for sub-pages |
| `title` text | CHECK not blank |
| `body_md` text | **canonical content**, Markdown, never HTML; CHECK `length(body_md) <= 200000` |
| `folder_id` uuid null FK `RESTRICT` | general articles only |
| `client_id` uuid null FK, `project_id` uuid null | composite FK `(project_id, client_id) -> projects(id, client_id)` (exists: `projects_id_client_unique`) |
| `client_access` boolean not null default false | the per-client/project switch |
| `version_no` int not null default 1 | number of the current version |
| `created_by_id`, `updated_by_id` uuid FK users | |
| `deleted_at`, timestamps | soft delete = archive |
| `search_vector` tsvector GENERATED ALWAYS ... STORED | see Search |

CHECK constraints that carry the security and structure rules (the database enforces them, the UI only reflects them):

```
-- structure
kind = 'incident_page'   <=> parent_id IS NOT NULL AND page_type IS NOT NULL
kind <> 'incident_page'  =>  parent_id IS NULL AND page_type IS NULL
folder_id IS NULL OR kind = 'general'
kind = 'retrospective'   =>  project_id IS NOT NULL            (client_id then follows via the composite FK)
project_id IS NULL OR client_id IS NOT NULL
-- access
client_access = false OR client_id IS NOT NULL
kind = 'retrospective'   =>  client_access = false             (a retrospective is never shared, see below)
kind = 'incident_page'   =>  client_id IS NULL AND project_id IS NULL AND client_access = false AND folder_id IS NULL
```

Indexes: partial unique `(project_id) WHERE kind = 'retrospective' AND deleted_at IS NULL` (one live retrospective per project, makes the project button idempotent under a double click); `(parent_id, page_type)` partial unique `WHERE page_type <> 'other'` if the requirements want singleton sub-page types; `(kind, updated_at DESC) WHERE deleted_at IS NULL` for the listings; `(client_id) WHERE client_access` for the Partner scope; `(folder_id)`; GIN on `search_vector`; GIN trigram `kokpit_search_text(title)` named `knowledge_articles_title_search_index` per the existing naming rule.

**Single source of truth for incident sub-pages.** A sub-page carries no client, project or access value of its own (the CHECK forces them empty). Its effective access is its hub's, computed in the Partner scope. This removes the drift risk of copying the flag to children and needs no sync code. A composite FK with `ON UPDATE CASCADE` was considered and rejected: with nullable `client_id` the default `MATCH SIMPLE` skips the check silently.

**Retrospectives are never shared with a Partner.** The time overview is measured time, which a Partner must never see. Two layers: the overview lives in an Admin-only table (below), and a CHECK makes the retrospective's `client_access` impossible to set. If the owner later wants to share a retrospective, that is a separate, deliberate redesign (share a sanitised copy), not a flag flip. Flag this decision for the requirements step.

### `knowledge_article_versions` (Admin-only, append-only)

`id`, `article_id` FK `RESTRICT`, `version_no` int, `title`, `body_md`, `change` (`created|edited|restored`), `restored_from_version_no` int null, `author_id` FK users null, `created_at`. `UNIQUE (article_id, version_no)`, CHECK `version_no >= 1`, CHECK `(change = 'restored') = (restored_from_version_no IS NOT NULL)`.

Full snapshot per save, not a diff: Markdown articles are small, restore becomes a copy, and a diff chain would make every future AI/API read reconstruct state. Unchanged saves (same title and body hash) write no version. Tags, folder and the client link are metadata on the article and are not versioned; the activity log (allowlisted attributes) records their changes.

Version numbers are taken from `knowledge_articles.version_no` under a row lock on the article, not from `SequenceAllocator`: the counter is per article, so a `number_sequences` row per article would add rows for no gain. Gap-free and duplicate-free follows from `SELECT ... FOR UPDATE` on the article plus the unique constraint.

Append-only enforcement: a trigger refusing `UPDATE` and `DELETE`, in the style of the existing guard triggers. One exception is needed (see Pitfall 1 below): a controlled redaction path, because a secret that reached a version would otherwise live forever. Design it in the phase: either the trigger allows changing `body_md`/`title` only when `redacted_at` is set in the same statement, or there is no trigger and an Arch test pins that only `RedactVersion` updates the table.

### `knowledge_images`

`id`, `article_id` FK `RESTRICT`, `alt` text, `created_by_id`, timestamps. Implements `HasMedia` (collection `image`, single file). The stored object is a medialibrary `media` row on the private disk with `model_type = 'knowledge_image'`. Upload limits: png, jpeg, webp, gif only, size cap from config (the library default is 10 MB). SVG is refused (script-capable). Article deletion is archive only, so images are never orphaned by a cascade.

### `retrospective_time_overviews` (Admin-only half)

`article_id` uuid unique FK (1:1), `project_id` FK, `generated_at`, `worked_seconds`, `billed_seconds`, `unbilled_seconds`, `non_billable_seconds` bigint with CHECK `>= 0`, `per_task` jsonb (list of `{task_reference, title, worked_seconds}` built by a typed DTO), `period_start`/`period_end` dates. Time only, no rates and no money, exactly as `ProjectTimeStats` shows ("No money anywhere in this phase"). Rates and prices stay out on purpose: the overview feeds a narrative page, not an invoice.

Why a table and not Markdown inside `body_md`: the body is the AI-readable canonical text and may be exported; measured time must stay behind the Admin-only model boundary. The view page renders the overview as an Admin-only infolist section; a later AI export can include it behind an Admin-only API ability.

Snapshot, not live: a retrospective records the project at closing time. `CreateRetrospective` writes the snapshot; an Admin action "Obnovit přehled času" regenerates it (new `generated_at`, no version row because the body is untouched).

### Tags and morph map

- `TagType::Knowledge = 'knowledge'`; tag input is always called with `->type(TagType::Knowledge->value)`, per the existing rule in the enum docblock.
- `Tag::constrainForPartner()` keeps only `type = project` tags attached to visible projects, so a Partner never sees a knowledge tag. No change to `Tag`. Add a case to `PartnerTagVisibilityTest`.
- `MorphMap` additions: `knowledge_folder`, `knowledge_article`, `knowledge_article_version`, `knowledge_image`, `retrospective_time_overview`. Morph aliases are persisted in `taggables`, `media`, `activity_log`; choose them once.

## Access Model (the part that must not leak)

| Model | Isolation | Policy | Partner sees |
|-------|-----------|--------|--------------|
| `KnowledgeArticle` | `PartnerIsolated` with its own constraint | `KnowledgeArticlePolicy` (viewAny/view only) | shared articles of the own client |
| `KnowledgeImage` | `PartnerIsolated`: `article_id IN (KnowledgeArticle::query()->select('id'))` | `KnowledgeArticlePolicy`-style read grant | images of articles the Partner can see |
| `KnowledgeFolder` | `DeniesPartners` | `AdminOnlyPolicy` | nothing (no tree, no breadcrumbs) |
| `KnowledgeArticleVersion` | `DeniesPartners` | `AdminOnlyPolicy` | nothing (no history) |
| `RetrospectiveTimeOverview` | `DeniesPartners` | `AdminOnlyPolicy` | nothing |
| `Media` (existing) | `DeniesPartners`, unchanged | `AdminOnlyPolicy` | read only as system after the article policy passed |

The Partner constraint of the article, as a sketch in the style of `Project::constrainForPartner()` and `Document::constrainForPartner()` (the owner/project check is an id subquery over the already scoped `Project::query()`, never `withTrashed()`):

```php
public function constrainForPartner(Builder $query, string $clientId): void
{
    $t = $this->getTable();

    $query->where(function (Builder $q) use ($t, $clientId): void {
        // A hub, a general article: shared, own client, client not archived, project (if any) visible to the Partner.
        $q->where(fn (Builder $own) => $this->ownRowShared($own, $t, $clientId)->where("{$t}.kind", '!=', 'incident_page'))
          // A sub-page: its HUB must be shared the same way. The sub-page has no access columns of its own.
          ->orWhere(fn (Builder $page) => $page
              ->where("{$t}.kind", 'incident_page')
              ->whereIn("{$t}.parent_id", static::sharedHubIds($clientId)));
    });
}

private function ownRowShared(Builder $q, string $t, string $clientId): Builder
{
    return $q->where("{$t}.client_access", true)
        ->where("{$t}.client_id", $clientId)
        ->whereIn("{$t}.kind", ['general', 'incident'])               // a retrospective can never match
        ->whereExists(fn ($s) => $s->selectRaw('1')->from('clients')   // plain EXISTS: Client is closed to Partners
            ->whereColumn('clients.id', "{$t}.client_id")->whereNull('clients.deleted_at'))
        ->where(fn (Builder $p) => $p->whereNull("{$t}.project_id")
            ->orWhereIn("{$t}.project_id", Project::query()->select('projects.id')));
}
```

Rules that follow from the existing security constraint ("enforced by Policies and global query scopes, not only UI hiding"):

1. **Search, counts, relation managers and the signed image route all go through the scoped model.** `KnowledgeSearch` takes a builder and adds predicates, exactly as `AccentInsensitiveSearch::where()` does; it never builds its own `FROM knowledge_articles`. No `DB::table()` (the `QueryEscapeHatchTest` allowlist is empty on purpose and must stay empty; the full-text query uses Eloquent `whereRaw` on the scoped builder).
2. **No Admin-only column on `knowledge_articles`.** Every column of that table is Partner-safe by construction (`PartnerSafeColumnsTest` style). Anything Admin-only goes in a 1:1 Admin table. `created_by_id`/`updated_by_id` are not rendered on the Partner resource.
3. **Un-sharing is instantaneous and global.** The scope reads `client_access` on every query, so switching the flag off removes the article, its sub-pages, its images and its search hits from the next request. Cached search results or rendered HTML must therefore never be cached across users (no shared cache of rendered Markdown keyed only by article id).
4. **The Partner constraint requires the project to be Partner-visible**, not just the client. A shared article linked to a project hidden from the client (`client_visible = false`) is not shown. This is stricter than the flag alone and consistent with `Document`.
5. **Retrospective time never reaches a Partner path:** the model is `DeniesPartners`, the CHECK blocks sharing, `RetrospectiveResource` is `Audience::AdminOnly`, and `TimeEntry` stays `DeniesPartners`. The `CreateRetrospective` action reads time under `PartnerContext::runAsSystem()` only after asserting `isAdmin()`.
6. **Markdown rendering is per request and Partner-aware for images only:** a signed image URL carries the requesting user id under the signature, as the document route does.
7. **Arch/matrix tests are part of the build, not follow-up.** `ModelDeclarationTest` lists every model; `PanelRegistryTest` requires `#[AccessRule]` on each new Resource, Page, RelationManager; `PartnerMatrix`, `RouteRules`, `ChannelMatrix` are literal declarations that the owner reviews. Add them with each screen (see build order).

## Architectural Patterns

### Pattern 1: One write path, optimistic version check

**What:** `SaveArticle::handle(?KnowledgeArticle $article, ArticleInput $input, ?int $expectedVersionNo)` is the only code that writes title, body or metadata. Inside one transaction: lock the article row, compare `expectedVersionNo` with `version_no` (mismatch throws a Czech validation error "Článek mezitím změnil jiný zápis"), run `SecretScanner`, update the row, insert a version row when title or body changed, let the activity-log trait write the metadata change.
**When:** every create, edit, restore and API/MCP write.
**Trade-offs:** one extra hidden form field (`version_no`) and one lock per save; in return two browser tabs or an AI client and the UI can never silently overwrite each other, and the version history has no gaps.

```php
DB::transaction(function () use ($article, $input, $expected): KnowledgeArticle {
    $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
    throw_if($expected !== null && $locked->version_no !== $expected, StaleArticle::class);
    app(SecretScanner::class)->assertClean($input->title, $input->body);   // throws ValidationException, never echoes the value
    // ...update; if title/body hash changed: $locked->version_no++ and insert a KnowledgeArticleVersion row
});
```

### Pattern 2: Admin half in a separate table

**What:** anything an Admin may see and a Partner may not goes in a 1:1 table with `DeniesPartners`, as `project_billing` and `invoice_admin` do. Here: `retrospective_time_overviews`.
**When:** whenever a Partner-readable article needs an Admin-only attachment.
**Trade-offs:** a join or a second query; the gain is that a missed column hide cannot leak, because the Partner scope returns zero rows from the second table.

### Pattern 3: Markdown is canonical, HTML is derived and never stored

**What:** `body_md` holds the only truth. `MarkdownRenderer` converts per request: `league/commonmark` 2.10 (already installed through Laravel) with GFM tables, strikethrough and task lists, `html_input => strip`, `allow_unsafe_links => false`; then the output goes through a strict HtmlSanitizer config of its own (the `RichText` approach: do not touch Filament's globally bound sanitiser config, which allows `style` and `class`); then a DOM pass wraps each `<pre><code>` with a copy button (Alpine `navigator.clipboard.writeText`) and rewrites `kokpit-image:<uuid>` to a signed route.
**When:** every view page, the Partner view, any future HTML mail.
**Trade-offs:** rendering cost on each view (negligible at this size; cache per `(article id, version_no, user id)` only if profiling ever shows a need). Filament's own `TextEntry::markdown()` is not used: it sanitises with the panel-wide config and cannot resolve image tokens or add copy buttons (verified in `CanFormatState` source).

### Pattern 4: Images referenced by token in the Markdown

**What:** the canonical text contains `![Dashboard after the fix](kokpit-image:0191a2f0-...)`. The token is a stable id, not a signed URL, so exports stay valid and an AI client sees the alt text and the id. Only `MarkdownRenderer` turns it into `/admin/files/knowledge-images/{image}?u=...` (signed, short-lived, user id under the signature).
**When:** every embedded image.
**Trade-offs:** needs a small upload action instead of the editor's built-in attach button (see Pitfall 2).

### Pattern 5: Search runs on the scoped builder

**What:** `KnowledgeSearch::apply(Builder $scoped, string $term, SearchFilters $f): Builder` adds a `search_vector @@ query` predicate, a title trigram `OR`, the filters (kind, tag, folder subtree via `FolderTree`, client, project) and a rank ordering. The caller decides the scope: the Admin page passes `KnowledgeArticle::query()`, the Partner path passes the same model, so the Partner scope applies by itself.
**When:** the Knowledge search page, the list-page quick search, future API `?q=`.
**Trade-offs:** none beyond discipline; this is the same idea as `AccentInsensitiveSearch`.

### Pattern 6: Image attachments are not `Document` rows

**What:** images get their own `knowledge_images` model and their own signed controller, instead of a new `DocumentOwnerType`.
**Why:** reusing `Document` means a new branch in `DocumentOwnerResolver`, in `Document::constrainForPartner`, in the `ARCHIVABLE_OWNER_TYPES` list, in the `documents_visible_owner_check` CHECK, in the central listing (embedded images would appear as files to manage) and in ZIP archives. Each is an edit to a code path that is audited by the Partner matrix. A separate small model reuses only the safe pieces (private disk, `Media`, controller pattern). Cost: one controller and one policy, about 150 lines, both copies of proven code.

### Pattern 7: Hub with typed sub-pages through a nested resource

**What:** an incident is a hub article (`kind = incident`, body = summary) with typed sub-pages (`kind = incident_page`, `page_type` from `IncidentPageType`, e.g. timeline, impact, root cause, resolution, prevention, other). The hub view page lists sub-pages in a relation manager and links to their own view/edit pages. Listings (`IncidentResource`, `ArticleListing`) exclude `incident_page`; `KnowledgeSearch` includes it and shows the hub title as a breadcrumb.
**When:** incidents only. Retrospectives are a single flat article plus the overview.

## Data Flow

### Save an article (Admin)

```
EditKnowledgeArticle (Filament page, MarkdownEditor)
    ↓ form state + hidden expected version_no
SaveArticle::handle(ArticleInput)           (Action, DB::transaction)
    ↓ lockForUpdate(article)  → stale? → validation error
    ↓ SecretScanner::assertClean(title, body) → findings? → validation error (category + line only)
    ↓ update knowledge_articles (version_no+1) + insert knowledge_article_versions
    ↓ activity_log row (allowlisted metadata, no title/body)   ← LogsAllowlistedActivity
    ↓ tags sync (type "knowledge")
search_vector regenerated by PostgreSQL (generated column)
```

### Project button to retrospective

```
ViewProject header action "Vytvořit retrospektivu"  (visible: Admin, project not archived, no live retrospective)
    ↓
CreateRetrospective::handle(Project)
    ↓ assert isAdmin(); transaction
    ↓ RetrospectiveOverviewBuilder (runAsSystem): TimeTotals::forProject() + per-task time columns
    ↓ insert article kind=retrospective, project_id, client_id (from project), body = RetrospectiveTemplate (fixed headings, empty sections)
    ↓ insert version 1, insert retrospective_time_overviews row
    ↓ partial unique index (project_id) catches a double click → redirect to the existing one
redirect to the EDIT page of the retrospective
```

### Partner reading shared knowledge

```
PartnerKnowledgeResource / KnowledgeSearchPage (Partner)
    ↓ KnowledgeArticle::query()  ← PartnerScope applies constrainForPartner()
    ↓ KnowledgeSearch adds predicates only
view page → MarkdownRenderer → img src = signed route (user id under signature)
KnowledgeImageController → Partner-scoped KnowledgeImage lookup → policy view → Media read as system → stream
```

### AI-friendly export (format now, endpoint later)

```
ArticleDocument::render(KnowledgeArticle): string
---
id: 0191a2f0-...            # UUID v7, stable
kind: incident
title: "Queue worker stalled after deploy"
tags: [queue, deploy]
folder: "Operations / Runbooks"        # general articles only
client: 0191...                          # ids, never names of a client in the file
project: 0191...
version: 7
updated_at: 2026-11-25T09:14:00Z
---
<body_md exactly as stored>
```

Front matter plus the untouched Markdown. The future REST/MCP layer returns this document for `GET /api/v1/knowledge/{id}` with a new Sanctum ability (`knowledge:read`/`knowledge:write` as a separate group, per the existing "one group per ability" rule in `routes/api.php`), `ETag` = `version_no`, and calls the same Actions for writes with `expected_version_no` as the `If-Match` equivalent. Nothing in this milestone has to change when that layer arrives. API tokens are Admin-only, so the API never needs the Partner scope.

## Search Architecture

Two mechanisms, both already half-present:

1. **Title and short fields:** the existing trigram + `kokpit_search_text()` pattern (typed substring, any accent).
2. **Body full text:** a stored generated column.

```sql
-- migration sketch (MEDIUM: verify immutability of the generated expression in the first spike)
CREATE TEXT SEARCH CONFIGURATION public.kokpit_search (COPY = pg_catalog.simple);
ALTER  TEXT SEARCH CONFIGURATION public.kokpit_search
  ALTER MAPPING FOR hword, hword_part, word WITH public.unaccent, pg_catalog.simple;

ALTER TABLE knowledge_articles ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
  setweight(to_tsvector('public.kokpit_search', title), 'A') ||
  setweight(to_tsvector('public.kokpit_search', body_md), 'B')
) STORED;
CREATE INDEX knowledge_articles_search_vector_index ON knowledge_articles USING gin (search_vector);
```

Why a custom text-search configuration instead of `to_tsvector('simple', kokpit_search_text(body))`: with `unaccent` inside the configuration, `ts_headline('public.kokpit_search', body_md, query)` runs on the ORIGINAL text with its diacritics and still matches an accent-free query, so snippets keep their Czech letters. With the wrapper function the headline would have to run on the folded text and would print `skola` for `škola`.

Czech has no built-in PostgreSQL stemmer dictionary. `simple` (lowercase, no stemming) plus prefix matching handles Czech inflection well enough for a company knowledge base: the query is built from the typed tokens as `token:*` joined with `&`, tokens restricted to letters and digits with a regex before they reach SQL (never pass raw user text to `to_tsquery`; `websearch_to_tsquery` has no prefix operator). Ranking: `ts_rank_cd(search_vector, query)` with title weight A above body weight B, ties broken by `updated_at`.

Combine with the title trigram index so a partial word in a title always matches even before a body match. No external search engine: one company, thousands of articles at most, PostgreSQL handles it; adding Meilisearch or Scout would add an operational service to a project whose deploy target is Zerops with a managed database.

The search page is a Filament `Page` with a Livewire search input, results list (title, kind badge, hub breadcrumb for sub-pages, tags, snippet), filters (kind, tag, folder, client/project). It is not a Resource, so `globalSearchResourceOptIn()` keeps the Filament global search blind to Knowledge on purpose: global search results appear in the top bar for the Admin only; mixing article bodies into it would blur the "own search" requirement and put article titles into a surface that is shared with client names.

## Filament Integration Details

- **Navigation:** `InKnowledgeGroup` trait (copy of `InSignalGroup`) on the three resources and the search page; `navigationSort` 10/20/30. The Partner resource shows the group label too but is a separate class with its own `canAccess()` requiring a Partner with a client (the `PartnerProjectResource::canAccess()` pattern, so the Admin never sees two entries).
- **Separate view and edit pages:** Filament's default ListRecords / ViewRecord / EditRecord / CreateRecord. List row click goes to View (`recordUrl` to `view`), a header action goes to Edit. This is already how `ProjectResource` works.
- **Editor:** `MarkdownEditor` with `fileAttachments(false)`. Verified in `vendor/filament/forms/src/Components/MarkdownEditor.php`: its attachment visibility is hard-coded to `public` and `fileAttachmentsVisibility()` throws. Leaving the attach button on would write public files, which breaks the private-bucket constraint. Images go through the own upload action instead.
- **Editor validation:** `maxLength` mirrors the DB CHECK; the Action re-validates because `/livewire/update` is a public endpoint and the Action has no Gate of its own, the same reason `ViewProject::changeStatusAction` repeats `canEdit()` in its body.
- **Project page:** `ViewProject::getHeaderActions()` gains one action next to `board` and `newTask`, with `->visible(fn () => RetrospectiveResource::canAccess() && no live retrospective)` and a body that re-checks `isAdmin()`.
- **Activity history:** reuse the pattern of `ProjectHistoryRelationManager` for a "Historie změn" tab; the separate "Verze" tab lists `knowledge_article_versions` with Restore.
- **Localisation:** keys under `kokpit.knowledge.*` in `lang/cs/kokpit.php`; enum labels in `lang/cs/enums.php`; code, DB, routes, tests in English (project rule).

## Anti-Patterns

### Anti-Pattern 1: Reusing the task `RichText` HTML pipeline for articles

**What people do:** store the editor's HTML and convert to Markdown on demand (`RichTextMarkdown`) because the converter exists.
**Why wrong:** the requirement is Markdown as the canonical store; HTML to Markdown is lossy (tables, code fences, task lists) and the stored form would not be what an AI tool reads.
**Do this instead:** store Markdown, render HTML. Leave `RichText`/`RichTextMarkdown` serving task descriptions untouched.

### Anti-Pattern 2: A `client_access` flag copied onto sub-pages (or any child)

**What people do:** denormalise `client_id`, `project_id`, `client_access` onto incident sub-pages so the scope stays one flat `WHERE`.
**Why wrong:** drift. An Admin un-shares the hub, a sync bug leaves a sub-page flagged, a Partner reads a page of an incident that is no longer shared.
**Do this instead:** CHECK the child's access columns to be empty and resolve through the hub in the Partner scope.

### Anti-Pattern 3: Time or rates inside the Markdown of a retrospective

**What people do:** generate "Worked 42:10 h, billed 38:00 h" as a Markdown table in the body for convenience.
**Why wrong:** the body is the exportable, searchable, Partner-shareable canonical text. Measured time would be searchable, versioned forever, copied into AI context and one flag away from a Partner.
**Do this instead:** Admin-only overview table, shown by an Admin-only infolist section, with a CHECK that retrospectives cannot be shared.

### Anti-Pattern 4: Editing the Filament global search to include articles

**What people do:** set `$isGloballySearchable = true` on the Knowledge resources.
**Why wrong:** the requirement says separate; the global search ranks nothing, shows titles next to client names, has no filters, and the panel was deliberately set to opt-in to protect Partners.
**Do this instead:** a dedicated search page over the scoped model.

### Anti-Pattern 5: Folder permissions per Partner

**What people do:** let a Partner see the folder tree and filter articles by folder.
**Why wrong:** folder names are internal structure and would need their own isolation rules.
**Do this instead:** Admin-only folders; the Partner list is flat, ordered by update date, filtered by project.

### Anti-Pattern 6: Putting article text into the activity log

**What people do:** add `title`/`body_md` to `#[LoggedAttributes]` to get "history for free".
**Why wrong:** the log is a free-text sink that leaks passwords by design in other apps; this repository's allowlist exists to prevent it. Versions already store the content in a table with controlled access.
**Do this instead:** log `kind`, `folder_id`, `client_id`, `project_id`, `client_access`, `parent_id` only.

## Scaling Considerations

| Scale | Architecture adjustments |
|-------|--------------------------|
| One instance, one company, up to a few thousand articles | Everything above as-is. Full snapshots per version cost kilobytes each. The recursive folder CTE and the tsvector GIN index are instant. |
| Tens of thousands of articles, heavy editing | Version table grows first (full copies): add a retention action that thins old `edited` versions of one article while keeping restore points; consider TOAST compression, already automatic for large text. |
| Beyond that | Not a target of this product (one instance per company). Do not pre-build for it. |

First bottleneck would be the version table size; second a search snippet pass over very large bodies (`ts_headline` is per row, only run it for the page of results shown, never for the whole match set).

## Integration Points

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Knowledge → Projects/Clients | FK + the scoped `Project::query()` subquery; plain `EXISTS` on `clients` | Never load `Client` through its model in a Partner path (closed to Partners) |
| Knowledge → TimeTracking | `TimeTotals` called by `RetrospectiveOverviewBuilder` as system after an Admin assertion | Knowledge never writes time data; time never reads Knowledge |
| Knowledge → Tags | `HasTags` with `TagType::Knowledge` | Tag rename or delete in the future tag screen affects all typed users: keep tag editing per type |
| Knowledge → Media/S3 | `KnowledgeImage` implements `HasMedia`; reads as system | Same private disk and `Media` model as documents; no new bucket |
| Knowledge → Activity log | `LogsAllowlistedActivity` | Allowlist only |
| Knowledge → Tasks (`RichText`) | None | Two different text pipelines on purpose |
| Knowledge → Documents module | None | Images are not Documents |
| Knowledge → API/MCP (later) | Same Actions, `ArticleDocument`, new Sanctum ability group | No work in v1.1 beyond keeping Actions Filament-free and ids stable |
| Knowledge → Queue | none required in this milestone | Everything is synchronous (a user waits); only a possible later "re-index"/"purge versions" command would be queued |

### External Services

None new. S3-compatible storage is already configured; PostgreSQL extensions `pg_trgm` and `unaccent` are already installed by migration `2026_10_30_000100`; no new Composer package is required (CommonMark, HtmlSanitizer, medialibrary, tags, eloquent-sortable, activitylog are present).

## Suggested Build Order

Dependencies drive the order: the schema and the isolation rules come first because every later feature (and the audit tests) stands on them; Partner-facing screens come before features that add more Partner surface; search comes after the kinds it must cover exist, but the schema for all kinds is created at the start so no later phase alters a CHECK.

1. **Schema, models and access skeleton.** All migrations of articles, folders, versions (images and the retrospective overview tables can wait for their phases, but `knowledge_articles` carries every kind and every column from the start), models, morph aliases, `TagType::Knowledge`, policies, Partner scope, `AccessServiceProvider` entries, `ModelDeclarationTest`, schema tests, `Isolation` tests for the scope. No UI yet. Why first: it is the highest-risk, hardest-to-change layer (constraints, morph aliases are persisted).
2. **Core article: Actions, secret guard, versions, Admin resource "Obecné znalosti".** `SaveArticle`, `SecretScanner`, version rows, `MarkdownRenderer` with copy-button code blocks, `MarkdownEditor`, nav group "Znalosti", list/create/view/edit with separate View and Edit pages, soft-delete archive, activity log. Concurrency test for simultaneous saves. Depends on 1. Contains the secret guard because it must exist before any content is saved, otherwise early content can contain secrets that sit in versions forever.
3. **Organisation and client access.** Folder tree (`FolderTree`, `MoveFolder`, cycle trigger), tags, client/project link, the client-access switch, `PartnerKnowledgeResource` (list + view), Partner matrix, `RouteRules`, `PartnerSafeColumnsTest`, `PartnerTagVisibilityTest`, leak tests including un-share, archived project and archived client. Depends on 1 and 2. This phase is the security gate for the milestone; do not defer any of it.
4. **Version history UI and restore.** "Verze" tab, `RestoreVersion` (writes a new version), diff view optional (plain side-by-side is enough), redaction path for a secret that reached a version. Depends on 2. Independent of 3, can run in parallel with it after 2.
5. **Image attachments.** `knowledge_images`, upload action, `ImageToken`, `KnowledgeImageController` and signed route, `MarkdownRenderer` image resolution, Partner scope for images, `RouteRules` entry, S3 test. Depends on 2 (renderer) and 3 (Partner scope exists).
6. **Incidents.** `IncidentResource`, nested `IncidentPageResource`, `IncidentPageType`, sub-page CHECKs (already in the schema), listing exclusion, Partner resolution through the hub. Depends on 3.
7. **Retrospectives.** `retrospective_time_overviews` migration, `RetrospectiveTemplate`, `CreateRetrospective`, the `ViewProject` header action, `RetrospectiveResource`, Admin-only time infolist, refresh action, leak tests for time. Depends on 2, and on the stable `TimeTotals` API (unchanged). Independent of 6.
8. **Knowledge search.** Text-search configuration migration, generated column, `KnowledgeSearch`, `KnowledgeSearchPage`, snippets, filters, `EXPLAIN` test like the existing accent-insensitive test, Partner search leak tests. Depends on 3 (scope), 6 (sub-pages searchable), 7 (all kinds exist). Put it late so its tests cover every kind; do the spike early (see flags).
9. **Integration and AI-friendly format.** `ArticleDocument` with front matter and its contract test, "Znalosti" relation managers on client and project (Admin) and the read-only one on the Partner project page, Czech language pass, docs. Depends on all above.

## Research Flags for Phases

| Phase | Flag | Reason |
|-------|------|--------|
| 2 | Needs a spike | The Markdown pipeline (CommonMark options, sanitiser config that keeps `pre/code`, tables and task lists but drops all raw HTML, copy button DOM pass) and the editor's image-insertion UX (an upload action cannot easily insert at the cursor of EasyMDE; the fallback is to append the token or copy it to the clipboard) |
| 3 | Standard patterns, high attention | Copies `Project`/`Document` scope patterns; the cost is test and matrix breadth, not design |
| 5 | Needs care | Image mime sniffing server-side (not the client's type), EXIF stripping decision, no SVG, inline disposition list, `nosniff`, response CSP; copy `DocumentDisposition` logic |
| 6 | Small spike | Nested resource in Filament 5.12 (exists in vendor, unexercised here) |
| 8 | Needs a spike first | Text-search configuration with `unaccent` inside a STORED generated column, that the expression is accepted as immutable, `ts_headline` snippets on accented text, prefix tokenizer, `EXPLAIN` uses the GIN index, DDEV and Zerops PostgreSQL both allow `CREATE TEXT SEARCH CONFIGURATION` for the application role. Fallback if the generated column is rejected: a trigger-maintained `tsvector` column |

## Pitfalls specific to this integration (details belong in PITFALLS.md)

1. **A secret that reached a version stays forever.** The version table is append-only and the guard only runs on future saves. Provide the redaction path before shipping versions; scan existing versions when the pattern list changes.
2. **`MarkdownEditor` file attachments are always public.** Turn them off; never accept its upload.
3. **`PartnerMatrix`/`RouteRules` are declared by hand and owner-reviewed.** Each phase that adds a screen or route needs its own matrix review; budget it in the plan.
4. **Archived project or client:** the Partner scope must hide a shared article when its project or client is archived, even for an open session (same behaviour as `Project`). Test both.
5. **Two Filament resources on one model:** the model-level scopes (Partner, SoftDeletes) are shared, but `getEloquentQuery()` kind filters are per resource. A route-bound record from the wrong resource (an incident opened under `/knowledge/{id}`) must 404: filter in `getRecordRouteBindingEloquentQuery()` too, as `ProjectResource` already overrides that method.
6. **Listings that forget the sub-page filter** show sub-pages as if they were articles; centralise it in one `ofListing()` scope used by every list and count.
7. **Optimistic version field must survive Livewire rehydration** (`#[Locked]` on the property, never trusted from the browser alone; the Action compares it against the locked row).

## Sources

- `.planning/PROJECT.md` (milestone v1.1 goal, constraints, key decisions): HIGH
- Repository code read for this document: `app/Domain/Shared/Auth/*`, `app/Domain/Projects/Models/Project.php`, `Policies/ProjectPolicy.php`, `app/Domain/Documents/Models/Document.php`, `Support/DocumentOwnerResolver.php`, `app/Http/Controllers/DocumentFileController.php`, `app/Domain/Shared/Models/{KokpitModel,Tag,Media}.php`, `app/Domain/Shared/Database/{MorphMap,AccentInsensitiveSearch,Immutability}.php`, `app/Domain/Shared/Text/*`, `app/Domain/TimeTracking/Queries/TimeTotals.php`, `app/Domain/Audit/*`, `app/Providers/AccessServiceProvider.php`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/Resources/ProjectResource*`, `app/Filament/Partner/Resources/PartnerProjectResource.php`, migrations `2026_10_11_000100`, `2026_10_16_000100`, `2026_10_30_000100`, `routes/api.php`, `tests/Arch/*`, `tests/Support/PartnerMatrix.php`: HIGH
- `vendor/filament/forms/src/Components/MarkdownEditor.php` (attachments always public; `fileAttachmentsVisibility()` throws) and `vendor/filament/infolists/.../CanFormatState.php` (markdown output sanitised with `Str::sanitizeHtml`): HIGH (installed Filament 5.12.0)
- `vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToParent.php` present (nested resources in Filament 5.12): HIGH that it exists, MEDIUM that it fits this use
- `league/commonmark` 2.10.7 installed (Laravel dependency): HIGH
- PostgreSQL documentation, appendix F `unaccent` ("text search configuration with unaccent dictionary mapping"), `UNIQUE NULLS NOT DISTINCT` (PostgreSQL 15+), generated columns: MEDIUM (from documentation knowledge, not re-fetched in this session; the immutability of the exact generated expression is the item to verify in the phase-8 spike)

---
*Architecture research for: Knowledge Center in Kokpit (milestone v1.1)*
*Researched: 2026-11-25*
