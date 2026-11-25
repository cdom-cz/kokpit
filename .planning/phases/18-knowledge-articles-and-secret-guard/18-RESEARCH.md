# Phase 18: Knowledge Articles and Secret Guard - Research

**Researched:** 2026-11-25
**Domain:** Markdown knowledge articles in an existing Laravel 13 / Filament 5.12 / PostgreSQL 18 CRM: hardened Markdown rendering, single transactional write seam (optimistic version check, secret guard, immutable versions, metadata-only activity log), fail-closed Partner seam
**Confidence:** HIGH for the renderer, sanitiser, highlighter, normaliser, scanner and SQL pieces (all executed in this session, see "Spike results"); MEDIUM for Filament browser behaviour (tab roundtrip of deferred editor state, copy button in a real browser), which needs one Livewire test plus one human check

All names, addresses and values in this file are fictional (`example.com`, company ID `12345678`). No file path in it is an absolute path of a person's machine, and no token-shaped literal appears in it.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Secret guard rules (SEC-01, SEC-02, SEC-03)**
- **D-01:** "Unambiguous secret" (blocks the save, no override) means only high-precision patterns: PEM private keys, AWS/Stripe/GitHub-style tokens, JWTs, URLs with embedded credentials. Everything else the scanner finds (for example `password=...`, long random strings) is "ambiguous": the save needs explicit acknowledgment.
- **D-02:** The guard scans the article body only, not the title.
- **D-03:** Acknowledgment flow: the first save with an ambiguous finding is refused with a list of findings (rule name and line number, never the value); the form then shows the warning and a "save anyway" button. The acknowledgment covers that one save only and is logged in the activity log without any content.
- **D-04:** A confirmed ambiguous finding marks the article `sensitive`. The flag stays set until the Admin clears it by hand in the editor; a later save without findings never clears it automatically (fail closed). Admin can also set the flag by hand without any finding.
- **D-05:** Test fixtures for secrets are assembled at runtime from fragments, so no single line in the repository looks like a real value (repository hygiene rule).

**Editor and preview (KB-06)**
- **D-06:** The edit screen has two tabs, "Write" and "Preview" (Czech labels in `lang/cs`). The preview is rendered on the server by the same renderer as the view screen, so both always match. No side-by-side layout, no modal.
- **D-07:** Filament `MarkdownEditor` (EasyMDE) with a basic toolbar only: bold, italic, headings, lists, task list, link, code, table, quote. No image button (images arrive in Phase 20), no built-in preview, side-by-side or fullscreen, because the built-in preview does not use the Kokpit renderer.
- **D-08:** Leaving the edit screen with unsaved changes shows a leave warning. No server-side autosave.

**Concurrent edit and no-op saves (KB-08, VER-01)**
- **D-09:** A stale save (the article moved to a newer version since the edit screen was opened) is rejected with a notice. The user's typed text stays in the editor so it can be copied, and the notice offers "Load current version", which opens the current article in a new tab. No automatic merging and no "overwrite anyway" option.
- **D-10:** A save without changes creates no version. "Without changes" is compared after normalisation: line endings unified to LF, trailing spaces trimmed, trailing blank lines removed. Content inside fenced code blocks is never altered. The stored text is the normalised text.
- **D-11:** A version is a full snapshot of title and body. A change to the title alone creates a new version, and the version number rises by exactly one per content-changing save (gap-free, allocated under a lock on the article row).

**List, view screen and callouts (KB-02, KB-03, KB-04, KB-05, KB-07)**
- **D-12:** Callouts supported: the five GitHub alert types NOTE, TIP, IMPORTANT, WARNING and CAUTION (`> [!NOTE]` syntax). An unknown type renders as an ordinary blockquote.
- **D-13:** The General knowledge list shows title, version number, last edited and a sensitive indicator. It defaults to active articles only, with a switch to show archived ones (restore is available from there), sorted by last edit descending. Folder, tag and client columns arrive in Phase 19.
- **D-14:** The Copy button on a code block copies the exact original text of the block (no highlighting markup, no line numbers) and briefly changes its label to a "Copied" confirmation.

### Claude's Discretion
- Naming of the article kinds and the folder tree implementation (own `parent_id` with recursive CTE versus `staudenmeir/laravel-adjacency-list`): the owner left these to the planner. Research recommends the own `parent_id` with a recursive CTE; the kind naming is fixed once in the Phase 18 data model and never changes after creation.
- Renderer and sanitiser configuration details, syntax highlighter choice (`tempest/highlight` is optional per research), exact scanner pattern list, view screen layout, icons and Czech wording.

### Deferred Ideas (OUT OF SCOPE)
None. The discussion stayed within phase scope. The retained open items in `STATE.md` (version retention and redaction in Phase 19, image library in Phase 20, nested resource spike in Phase 21, search configuration in Phase 22, Partner share decisions in Phase 23) stay with their phases. Reviewed todo "Redesign unreadable time report layout" is not folded. Out of this phase: folders UI, tags, client/project links and version history screens (Phase 19); images (Phase 20); incidents and retrospectives UI (Phase 21); search (Phase 22); client sharing and AI export (Phase 23).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| KB-01 | Admin can create an article with a title and a Markdown body | `SaveArticle` create path, `knowledge_articles` table, Create page (Architecture Patterns 1, 5) |
| KB-02 | Own view screen and own edit screen | Resource with List/Create/View/Edit pages; ClientResource pattern; Partner 403 in `mount()` (Pattern 5) |
| KB-03 | Safe rendering: tables, task lists, links, highlighted code; raw HTML escaped; unsafe links dropped | Renderer pipeline and hostile corpus, executed spike (Pattern 2, Code Examples 1) |
| KB-04 | `> [!NOTE]` callouts | Own `Callout` block node plus `DocumentParsedEvent` listener, executed spike (Code Examples 1) |
| KB-05 | Every code block has a Copy button | Server-rendered block chrome, delegated click handler registered as a Filament JS asset, round-trip invariant for exact text (Pattern 2) |
| KB-06 | Server-rendered preview | `Tabs::livewireProperty()` roundtrip, same `KnowledgeMarkdown` service and same prose view (Pattern 5) |
| KB-07 | Archive and restore | Soft delete, `ArchiveArticle` / `RestoreArticle` Actions with explicit metadata-only activity rows (Pattern 1) |
| KB-08 | Concurrent edit never overwrites silently | Locked `expectedVersion`, `lockForUpdate`, `StaleArticle`, Concurrency suite (Pattern 1, Validation Architecture) |
| SEC-01 | Every save scans for secrets; message names rule and line, never the value | `SecretScanner` with `SecretFinding` DTO (Pattern 3); restore clause belongs to Phase 19 and reuses the same scanner |
| SEC-02 | Unambiguous blocks; ambiguous needs acknowledgment and marks sensitive | Two severities, `acknowledge` flag bound to the refused body hash (Pattern 1, Pattern 5) |
| SEC-03 | Override covers one save only and is logged without content | Acknowledgment is a per-call argument, explicit `secret_acknowledged` activity row with rule keys only (Pattern 1) |
| VER-01 | Every content change creates an immutable version; gap-free; none for a no-op | `knowledge_article_versions`, append-only trigger (executed on PG 18.6), normalised compare (Patterns 1, 4; Code Examples 4) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Extracted from `.claude/CLAUDE.md` (same authority as the locked decisions above):

- Public AGPL-3.0 repository, **fictional data only**: no client names, prices, real addresses, company IDs, bank accounts, IPs, hostnames, tokens or personal absolute paths in code, tests, fixtures, docs, `.planning/` or commit messages. Use `example.com`, company ID `12345678`, `/Users/example/`; test fakes are assembled at runtime from fragments.
- Review before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. The lefthook pre-commit hook runs the checker and gitleaks; never bypass it (`--no-verify`, `LEFTHOOK=0`).
- Stack fixed: PHP 8.5, Laravel, Filament (SPA mode), PostgreSQL, UUID v7 keys everywhere (including package morph columns); FK, unique, partial indexes and CHECK constraints are enforced in the database.
- Partner must never see restricted data: enforced by Policies and global query scopes, not only UI hiding.
- All dependencies must be AGPL-compatible; no paid or closed packages.
- Development in git worktrees: write code and tests there, run only cheap checks needing no shared state. **Do not run migrations from a worktree.** The final migration run and the full suite happen after the merge into the HEAD branch, in DDEV: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (CI shards it). Do not use `pest --parallel`; a failure caused by it is not a repository bug.
- Tests run in DDEV (`ddev exec`), the host has no database.
- Everything under `.planning/` is English; all user-facing text is Czech in `lang/cs`; code, enums, routes, tests are English.
- Work goes through a GSD workflow command; no direct repo edits outside one.
- After any regeneration of GSD-managed blocks in `.claude/CLAUDE.md`, rerun `scripts/check-sensitive.sh .claude/CLAUDE.md`.

## Summary

Phase 18 is mostly an integration phase on top of conventions that already exist and are strictly enforced by Arch, Isolation and Audit tests: model declarations, morph map, Partner matrix, canary registry, activity allowlist, UUID v7 schema rules. The libraries it needs are small: `league/commonmark` and `symfony/html-sanitizer` are already installed transitively and only need promotion to direct requirements; `tempest/highlight` is the one genuinely new package. Everything else (callout extension, secret scanner, normaliser, version table, folder trigger) is own code, and this session executed a working prototype of each against hostile input, on PHP 8.5.13 inside DDEV and on PostgreSQL 18.6 in a throw-away scratch database.

The decisive findings that change how the plans must be written are: (1) the sanitiser alone does not give the contract of the UI spec, because it leaves inert `<a>` elements and truncates silently at 20000 characters, so link and image policy must be applied in the CommonMark AST and the sanitiser limit must be raised; (2) Tempest's `{+ +}` style markers inside code change the displayed text, so a round-trip invariant (stripped highlighted HTML must equal the original code) must gate the highlighter, otherwise the Copy button would not copy the exact text; (3) a 100 KB body of unbalanced brackets peaks at 186 MB of PHP memory, so the body needs a hard cap (100 000 characters recommended) enforced in the form, the Action and a CHECK constraint; (4) the repository already contains the exact pattern for lock, stale check and explicit activity row (`UpdateTaskDescription`), and Filament already supports a per-page unsaved-changes alert, so no panel-wide setting is needed.

The access seam is deliberately dumb in this phase: all three models (`knowledge_articles`, `knowledge_article_versions`, `knowledge_folders`) use `DeniesPartners` and `AdminOnlyPolicy`. Phase 23 replaces that for articles. The cost of Phase 18 is therefore not design but breadth of declarations: each new model, resource and route touches about eight test-support files that fail the build when forgotten (listed in the checklist below).

**Primary recommendation:** Build in this order: (1) schema, models, declarations and canary entries; (2) `KnowledgeMarkdown` renderer plus assets and the hostile-corpus test; (3) `MarkdownNormaliser` and `SecretScanner` as pure unit-tested classes; (4) `SaveArticle` with the Concurrency test; (5) the Filament resource on top. Steps 2 and 3 are independent of 1 and can run in parallel; step 5 needs 2 and 4.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Markdown to safe HTML | API / Backend (`KnowledgeMarkdown` service) | Browser (copy handler only) | Raw HTML escaping, link policy and sanitising must run on the server; the browser only enhances the already safe markup |
| Preview | API / Backend (Livewire roundtrip) | Browser (editor holds unsaved state) | D-06 requires the same renderer as the view screen; client-side rendering is excluded |
| Copy button | Browser (one delegated JS handler) | API (renders the fixed button markup) | Clipboard API is browser only; the markup is fixed server-side so the Markdown author cannot influence it |
| Secret scanning | API / Backend (`SecretScanner` inside `SaveArticle`) | UI (shows findings) | Must run in the single write path so no entry point can skip it; the UI only displays rule and line |
| Optimistic version check, version rows, numbering | Database / Storage (row lock, UNIQUE, trigger) | API (`SaveArticle`) | Gap-free and duplicate-free must hold for every writer; the Action orchestrates, the database enforces |
| Partner exclusion | Database / Storage (CHECK) and API (scope + policy) | UI (no menu entry) | Constraint says enforcement by scopes and policies, not UI hiding |
| Archive / restore | API (Action, soft delete) | UI (confirmation modal) | Reversible, writes a metadata-only activity row |
| Folder integrity (table only in this phase) | Database / Storage (trigger) | API (Phase 19 `MoveFolder`) | A CHECK cannot see a tree; concurrent moves must serialise in the database |
| Static assets (prose CSS, copy JS) | CDN / Static (`public/css/app`, `public/js/app` via `filament:assets`) | — | No build step exists in the repo; Filament copies registered assets |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| league/commonmark | `^2.10` (lock: 2.10.7; Packagist latest 2.10.8, 2026-11-24, BSD-3-Clause) | Markdown to HTML, AST access for callouts, links, images, task lists | Already installed transitively (Laravel, Filament); AST events are what the callout and link policy need. [VERIFIED: composer.lock `league/commonmark 2.10.7`, license `["BSD-3-Clause"]`; Packagist API 2.10.8] |
| symfony/html-sanitizer | `^8.1` (lock: v8.1.11, MIT; 8.2 is still BETA, stay on 8.1) | Defence-in-depth pass with a Kokpit-owned config | Already installed; own `HtmlSanitizerConfig` instance, never Filament's global one. [VERIFIED: composer.lock; Packagist API shows `v8.2.0-BETA2` as newest] |
| tempest/highlight | `^2.30` (2.30.0, 2026-10-27, MIT, requires only `php ^8.4`) | Server-side syntax highlighting, class-only output (`hl-*`), pure PHP | The only new package. Executed in this session: output is HTML-escaped, class-only, unknown language falls back to escaped plain text, 200 KB highlights in under 75 ms. [VERIFIED: `composer show tempest/highlight` in a scratch project; spike2/spike4/perf runs] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| spatie/laravel-activitylog | 5.1 (installed) | Metadata-only activity rows | Via the existing `LogsAllowlistedActivity` wrapper plus explicit `activity()` rows |
| spatie/laravel-tags | 4.12 (installed) | Only `TagType::Knowledge` enum case in this phase | Tags UI is Phase 19 |
| ext-intl `Normalizer` | present | NFKC fold and zero-width strip on a scanning copy of the body | Before running scanner rules |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| tempest/highlight | phiki/phiki (TextMate grammars) | v3 is beta only (per STACK research); keep the highlighter behind the one `CodeBlockRenderer` so a swap is cheap |
| Own `parent_id` + recursive CTE | staudenmeir/laravel-adjacency-list | New dependency for two queries (descendants, ancestors); UUID behaviour of its `path` column was never verified. The database trigger is needed either way. **Recommend own.** |
| Own callout extension | pomodocs/commonmark-alert (pre-1.0, niche) | Own code is ~50 lines, gives Czech labels and exact markup; executed in this session |
| Own `SecretScanner` | third-party scanner packages | None mature (STACK research); gitleaks stays in the commit pipeline only |

**Installation (in DDEV, not in a worktree):**
```bash
ddev composer require league/commonmark:^2.10 symfony/html-sanitizer:^8.1 tempest/highlight:^2.30
ddev composer check-licenses
```
Add `/public/css/app/` and `/public/js/app/` to `.gitignore` (see Pitfall 8).

**Version verification:** league/commonmark and symfony/html-sanitizer versions read from `composer.lock`; latest versions and licences from the Packagist API (`repo.packagist.org/p2/...`) on 2026-11-25; tempest/highlight installed into a scratch project and inspected with `composer show`. License allowlist of `scripts/check-licenses.php` contains `'MIT'` and `'BSD-3-Clause'` [VERIFIED: scripts/check-licenses.php:38-41].

## Package Legitimacy Audit

The `gsd-tools package-legitimacy` seam only supports `npm|pypi|crates` (its usage line reads `--ecosystem <npm|pypi|crates>`), so it cannot rate Packagist packages. The audit below is therefore done by hand from the Packagist API and `composer.lock`; no row carries the seam's `OK`, and the planner should keep the first `composer require` behind the normal `composer check-licenses` gate and a glance at the `composer.lock` diff.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| league/commonmark | Packagist | years (BSD-3-Clause), already in `composer.lock` | already a dependency of Laravel and Filament | github.com/thephpleague/commonmark | already installed | Approved (promotion to direct only) |
| symfony/html-sanitizer | Packagist | already in `composer.lock` | already a dependency of Filament | github.com/symfony/html-sanitizer | already installed | Approved (promotion to direct only) |
| tempest/highlight | Packagist | releases through 2026-10-27 (2.30.0) | 1,555,463 total, 204,522 monthly, 8,633 daily [CITED: packagist.org/packages/tempest/highlight.json] | github.com/tempestphp/highlight, not abandoned, `type: library`, no Composer plugin, MIT | not rated by the seam (ecosystem unsupported) | Approved with manual verification above; installs only `php ^8.4` requirement, no transitive packages |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
*No package in this phase was discovered through WebSearch; all three come from the project's own earlier stack research and were re-checked against Packagist today.*

## Architecture Patterns

### System Architecture Diagram

```
Admin browser (Filament SPA panel)
   |  list / view / create / edit screens           Partner browser
   v                                                      |
KnowledgeArticleResource pages ------------------- mount(): abort_unless(canAccess(), 403)
   |  form state (title, body, sensitive) + #[Locked] expectedVersion + #[Locked] refusedBodyHash
   |
   +--(Preview tab: $set('activeTab','preview') -> Livewire roundtrip carries deferred editor state)
   |        v
   |   KnowledgeMarkdown::render(body) ---> same view  <div class="kokpit-prose"> as the view screen
   |
   +--(Save / Save anyway)
            v
      SaveArticle::handle(actor, article?, ArticleInput, expectedVersion, acknowledge)   [one DB transaction]
        1 authorize (Gate)                                  -> Partner/guest: AuthorizationException
        2 lock article row (lockForUpdate, scoped query)    -> archived row: refuse edit
        3 expectedVersion != locked.version_no ?            -> throw StaleArticle           (nothing written)
        4 MarkdownNormaliser::normalise(body)  (AST masks fenced + indented code)
        5 content unchanged (title + normalised body) ?     -> metadata only (sensitive flag), NO version, return Unchanged
        6 SecretScanner::scan(body)                         -> blocking finding   -> throw SecretGuardRefused(findings)
                                                            -> ambiguous, !ack    -> throw SecretGuardRefused(findings)
                                                            -> ambiguous, ack     -> sensitive = true, explicit activity row (rule keys only)
        7 version_no + 1, edited_at = now, UPDATE article, INSERT knowledge_article_versions (full snapshot)
        8 activity rows via LogsAllowlistedActivity (ids, flags, version number; never title or body)
            v
      PostgreSQL: UNIQUE (article_id, version_no); BEFORE UPDATE/DELETE/TRUNCATE trigger on versions (KP001);
                  CHECK constraints (length, kind, structure); folders cycle/depth trigger; DeniesPartners scope
```

### Recommended Project Structure
```
app/Domain/Knowledge/
├── Actions/
│   ├── SaveArticle.php            # the one write path (create + update)
│   ├── ArticleInput.php           # input DTO, no Filament types (API-ready for later)
│   ├── SaveResult.php             # outcome enum (Created, Updated, Unchanged) + article
│   ├── ArchiveArticle.php         # soft delete + explicit 'archived' activity row
│   └── RestoreArticle.php         # restore + explicit 'restored' activity row
├── Enums/ArticleKind.php          # general, incident, incident_page, retrospective (HasLabel, lang/cs/enums.php)
├── Exceptions/{StaleArticle,SecretGuardRefused}.php   # messages generic, never carry a value
├── Guard/{SecretScanner,SecretFinding,SecretRule,Severity}.php
├── Models/{KnowledgeArticle,KnowledgeArticleVersion,KnowledgeFolder}.php
├── Policies/                      # none needed: AdminOnlyPolicy registered for all three
└── Support/{KnowledgeMarkdown,MarkdownNormaliser,CalloutExtension,Callout*,CodeBlockRenderer}.php
app/Filament/Resources/KnowledgeArticleResource.php (+ Pages/{List,Create,View,Edit}KnowledgeArticle.php)
app/Filament/Concerns/InKnowledgeGroup.php             # copy of InSignalGroup
resources/css/kokpit-prose.css   resources/js/knowledge-copy.js   # registered via FilamentAsset, no build step
resources/views/filament/knowledge/{prose,guard-findings}.blade.php
lang/cs/knowledge.php                                  # all Czech strings of the UI-SPEC copywriting contract
database/migrations/2026_11_2x_*_create_knowledge_{folders,articles,article_versions}_tables.php
tests/Unit/Knowledge/  tests/Feature/Knowledge/  tests/Feature/Schema/  tests/Isolation/  tests/Concurrency/  tests/Arch/
```

### Pattern 1: One write seam, modelled on `UpdateTaskDescription`
**What:** `SaveArticle` is the only code that writes `title`, `body_md`, `sensitive`, `version_no`. The repository already ships the pattern: `app/Domain/Tasks/Actions/UpdateTaskDescription.php` authorises with `Gate::forUser($actor)->authorize(...)`, opens `DB::transaction`, loads `Task::query()->whereKey(...)->lockForUpdate()->firstOrFail()` (the scoped query), compares a client-held fingerprint with `hash_equals`, returns early on an unchanged value and writes an explicit `activity()->useLog(...)->performedOn(...)->causedBy(...)->event('description_changed')->log('description_changed')` row. [VERIFIED: app/Domain/Tasks/Actions/UpdateTaskDescription.php:61-100, quoted calls above]
**Rules that follow:**
- `expectedVersion` is a **required** argument for update (no default null), so no caller can skip the check. Create has no row to lock; its first version is 1.
- The acknowledgment is a boolean argument of one `handle()` call, never stored. "Covers one save only" (SEC-03, D-03) is therefore structural. The page binds it to the exact refused body (Pattern 5).
- Order inside the transaction: authorise, lock, stale check, normalise, no-op check, scan, write. A stale save never reaches the scanner; a no-op save skips the scan (nothing new enters the store, and a flag-only edit on an already sensitive article must stay possible, D-04).
- Version numbers come from the locked row (`version_no + 1`), not from `SequenceAllocator`: the counter is per article. Duplicate-free is additionally enforced by `UNIQUE (article_id, version_no)`.
- Title-only change is a content change (D-11): compare `title` (trimmed) and normalised body together.
- Exceptions carry rule keys and line numbers only. A test asserts that no exception message, log line or activity `properties` JSON contains any fragment of the fixture secret.
- Activity: `KnowledgeArticle` uses `LogsAllowlistedActivity` with `#[LoggedAttributes(['kind', 'folder_id', 'client_id', 'project_id', 'client_visible', 'sensitive', 'version_no'])]`. `title` and `body_md` must never be listed; `tests/Arch/ActivityAllowlistTest.php` hard-codes the list of logging models and must get `KnowledgeArticle::class` added [VERIFIED: tests/Arch/ActivityAllowlistTest.php:54, `expect(AuditDeclaration::loggingModels())->toBe([Client::class, ... TimeEntry::class]);`]. The forbidden-name pattern is `/^(password|secret|token|remember_token)$|^password_|_password$|^two_factor|_secret$|_token$/i` [VERIFIED: tests/Support/AuditDeclaration.php:30], so a column named `sensitive` is allowed.
- Archive and restore are explicit Actions that write an `archived` / `restored` row (event name only, article id as subject). Do not rely on the package's `deleted` event: with `logOnlyDirty` and no allowlisted dirty attribute it may write no row [ASSUMED, verify with one test].

### Pattern 2: One renderer, hardened in the AST first and sanitised second
**What:** `KnowledgeMarkdown::render(string $md): HtmlString` is the only place a body becomes HTML. View screen, preview tab and (later) version view and export call it; nothing else in the app calls `League\CommonMark` or `Str::markdown()` for knowledge content. Build the `Environment` once (singleton in a service provider).
**Configuration, all executed in the spike (Code Examples 1):**
- `html_input => 'escape'`, `allow_unsafe_links => false`, `max_nesting_level => 20`, `max_delimiters_per_line => 1000`. [CITED: commonmark.thephpleague.com/2.x/security/ , recommends `escape` or `strip` for untrusted input, 100 for nesting and 100-1000 for delimiters, and "enforcing input size or line length limits upstream"]. Defaults in the installed version are `html_input` ALLOW, `allow_unsafe_links` true, nesting and delimiters unlimited [VERIFIED: vendor/league/commonmark/src/Environment/Environment.php:434-437, `'html_input' => Expect::anyOf(HtmlFilter::STRIP, HtmlFilter::ALLOW, HtmlFilter::ESCAPE)->default(HtmlFilter::ALLOW)`, `'allow_unsafe_links' => Expect::bool(true)`, `'max_nesting_level' => Expect::type('int')->default(PHP_INT_MAX)`].
- Extensions: `CommonMarkCoreExtension`, `GithubFlavoredMarkdownExtension` (tables, task lists, strikethrough, autolink), own callout listener and renderer, own `FencedCode` renderer (priority 10), own `TaskListItemMarker` renderer.
- **Link policy in the AST, not in the sanitiser.** With `allow_unsafe_links=false` CommonMark leaves `<a>` without `href`; the sanitiser keeps that inert anchor too (executed). The UI-SPEC says an unsafe link becomes plain text. A `DocumentParsedEvent` listener hoists the children of every `Link` whose URL does not match `^(https?://|mailto:)` and detaches the link. This also removes relative links, protocol-relative links and reference-style links that point at `javascript:`.
- **Images become alt text** (UI-SPEC: "An image Markdown renders as its alt text"): replace each `Image` node with a `Text` of its alt. This also removes CommonMark's allowed `data:image/...` sources [CITED: security page: unsafe `data:` links are blocked "except `data:image` in PNG, GIF, JPEG, or WebP"].
- **Callouts:** top-level `BlockQuote` whose first child is a `Paragraph` whose first child is a `Text` equal to `[!TYPE]` (five types, accept case-insensitively as an own decision), followed by a soft line break or nothing. `> [!WARNING] same line text` stays a blockquote (GitHub behaves the same way [ASSUMED]). Nested callouts stay plain blockquotes because only direct children of the document are converted (UI-SPEC rule). Verified outputs: NOTE and TIP became callout blocks, `[!WARNING] same line text`, `[!FOO]` and the nested NOTE stayed blockquotes.
- **Code blocks:** allowlist the language (first word of the info string, lower-cased) against a fixed list; anything else, including an info string that tries markup injection, renders as plain escaped text with an empty language label. Remove exactly one trailing `"\n"` from the literal so `textContent` of `<code>` equals the author's block text.
- **Round-trip invariant (new, important):** Tempest treats `{+ x +}`, `{- x -}`, `{~ x ~}` and `{_ x _}` inside code as highlight-injection markers: it strips the markers and emits `hl-addition`, `hl-deletion`, `hl-blur` and `hl-em` spans and, for blur and em, `<span style="display: none">` elements. [VERIFIED: executed spike4; `src/Languages/Base/IsHighlightInjection.php` uses `TokenTypeEnum::HIDDEN`, rendered by `Themes/CssTheme.php:19-20` as `<span style="display: none">`]. The displayed and copied text would then differ from the source. Accept the highlighted HTML only when `html_entity_decode(strip_tags($highlighted), ENT_QUOTES) === $code`; otherwise render plain escaped text. Executed: a PHP block containing `'{+ marker +}'` fell back to plain text with the marker intact.
- **Block chrome is fixed server-side:** `<div class="kokpit-code"><div class="kokpit-code__head"><span class="kokpit-code__lang">…</span><button type="button" class="kokpit-code__copy" data-kokpit-copy>…</button></div><pre tabindex="0"><code>…</code></pre></div>`. The Markdown author cannot produce a `button` because raw HTML is escaped; the sanitiser config then allows `button[type,class,data-kokpit-copy]` only as an allowlist entry for this fixed markup. One delegated `click` listener on `document` (registered once via `FilamentAsset`) reads `pre code` `textContent`, so it survives Livewire morphs and SPA navigation and needs no per-element Alpine.
- **Sanitiser:** `allowSafeElements()`, link schemes `https|http|mailto`, no media schemes, no relative links or media, `forceAttribute('a','rel','noopener noreferrer nofollow')`, `dropAttribute('target','a')` (the safe-element default keeps `target`, executed), `input[type,checked,disabled,aria-hidden]` for task items, `class` only on `span|div|p`, `tabindex` only on `pre`, **`withMaxInputLength(-1)`**: the default is 20000 characters and it truncates silently [VERIFIED: executed, `default max=20000`, a 30000-character paragraph came back as 20004 characters; `-1` returned 30007]. Highlighted HTML is up to about 7 times larger than the code, so a fixed high limit is also fine, but never the default.
- The sanitiser re-encodes entities numerically (`=` becomes `&#61;`). This is harmless for rendering and for `textContent`, but HTML snapshot assertions must compare decoded text or use `toContain` on structure, not on raw punctuation.
- Task list: own `TaskListItemMarker` renderer emits the disabled checkbox with `aria-hidden="true"` plus a visually-hidden `Hotovo:` / `Nehotovo:` span (UI-SPEC).
- **No HTML cache in this phase.** Rendering is a few tens of milliseconds for realistic bodies (see Spike results); the Admin is the only viewer. A cache keyed by `sha256(body) + renderer version` is a later optimisation, never cache per article id alone.
- **Body cap:** an unbalanced-bracket body of 100 000 characters peaked at 186 MB and took 0.5 s; 50 000 characters peaked at 99 MB; a 128 MB CLI default `memory_limit` died on the 100 KB case [VERIFIED: executed p1/p1b/p1c]. Cap the body at **100 000 characters** in the form (`maxLength`), in `ArticleInput` validation and as a CHECK; the CommonMark page itself recommends upstream size limits.
- Labels (callout names, copy button states) must come from `lang/cs` but the renderer must stay unit-testable without the application (Unit tests in this repo are plain PHPUnit without the app, see `tests/Pest.php`). Inject a `Closure(string $key): string` translator through the constructor; the service provider binds `fn (string $key) => __('knowledge.'.$key)`, the unit test passes an identity closure.

### Pattern 3: Secret scanner as a pure class
**What:** `SecretScanner::scan(string $body): list<SecretFinding>`; a finding holds a `SecretRule` enum case, a `Severity` (Blocking | Ambiguous) and a 1-based line. It never holds the matched text, not even masked, so nothing downstream can leak it. Labels come from `lang/cs/knowledge.php` by rule key (UI-SPEC lists the Czech names).
**Rules (D-01 split), regexes executed against fixtures assembled at runtime:**

| Rule key | Severity | Pattern idea (PCRE, no backtracking traps) | Notes |
|----------|----------|--------------------------------------------|-------|
| `pem_private_key` | Blocking | `-----BEGIN (?:[A-Z]+ )*PRIVATE KEY(?: BLOCK)?-----` followed by newline and at least two base64-looking lines of 16+ characters | A bare header sentence in prose did **not** match (executed), so documentation that merely names the header is not blocked without an override |
| `aws_access_key` | Blocking | `\b(?:A3T[A-Z0-9]\|AKIA\|ASIA\|ABIA\|ACCA)[A-Z2-7]{16}\b` | The widely published documentation example key ends in `EXAMPLE`; decide whether to allowlist that exact suffix (Open Question 2) |
| `stripe_secret` | Blocking | live-mode secret and restricted keys (`sk` or `rk`, `_live_`, 24-99 alphanumerics) and webhook signing secrets | Test-mode keys: ambiguous, planner's choice (the repo uses Stripe, docs may legitimately show test keys) |
| `github_token` | Blocking | `gh[pousr]_` + 36-255 alphanumerics, fine-grained `github_pat_` + 22-255 | |
| `jwt` | Blocking | `eyJ…{8,}.eyJ…{8,}.…{8,}` (header and payload both start with the base64 of `{"`) | |
| `url_credentials` | Blocking | `scheme://user:pass@host` with a placeholder allowlist on the password part | `postgres://user:password@localhost/db` is **not** a finding (executed) |
| `password_assignment` | Ambiguous | `password\|passwd\|pwd\|secret\|token\|api[_-]key\|auth(orization)` then `:` or `=` then a 6-128 character value, minus placeholders | Placeholders: `changeme`, `password`, `<...>`, `${...}`, `{{...}}`, `%VAR%`, `****`, `your-...`, `null`, `true`... Executed: prose "The token is stored in the session." is not a finding |
| `high_entropy_string` | Ambiguous | 32-256 characters of `[A-Za-z0-9+/=_-]`, not hex/UUID only, at least three character classes (lower, upper, digit), Shannon entropy at least 4.2 | Entropy alone cannot separate a random token (4.39 for 32 base64 characters) from a long lowercase word run (4.54): the class-mix requirement is what keeps identifiers, SHA-1, SHA-256 and UUIDs out (executed on ten samples) |

Process the text per line for single-line rules and over the whole text for the PEM rule; line number = `substr_count(substr($text, 0, $offset), "\n") + 1`. Scan a copy folded with `Normalizer::normalize(NFKC)` and with zero-width characters removed **inside lines only**, so line numbers stay identical to the editor. Fail closed: if `preg_last_error() !== PREG_NO_ERROR` after any rule, throw a refusal that names the rule and line 0, never fall through to "clean". All eight regexes ran on 200 KB pathological inputs (repeated `A`, `eyJabcdefgh.`, `a://`, repeated PEM headers, `password=`, `a:b:`, `AKIA`) with none above 50 ms and no PCRE error [VERIFIED: executed redos.php].

### Pattern 4: Normaliser driven by the CommonMark AST
**What:** `MarkdownNormaliser::normalise(string $md): string`: strip BOM, convert `\r\n` and `\r` to `\n`, collect the line numbers covered by every `FencedCode` and `IndentedCode` node (from `getStartLine()` to `getEndLine()` of a parsed document), `rtrim` spaces and tabs on every other line, `rtrim` trailing newlines of the whole text. Executed result: fences inside block quotes and list items are protected, tilde fences and indented code are protected, an unclosed fence is protected to the end of the text, and `normalise(normalise(x)) === normalise(x)`. A regex over backticks would not do this.
**Interpretation to confirm:** D-10 says content inside fenced code blocks "is never altered" while line endings are unified. This research applies LF unification everywhere (including inside fences, because a stored CR in a copied command is a bug) and leaves trailing spaces and blank lines inside code untouched. Logged as A3. Trailing double-space hard line breaks outside code are trimmed by D-10 and therefore lost.

### Pattern 5: Filament screens on the existing conventions
- **Resource:** `#[AccessRule(Audience::AdminOnly, reason: '…')]`, `use EnforcesResourceAccessRule`, `getNavigationGroup()` through a new `InKnowledgeGroup` trait copied from `InSignalGroup` [VERIFIED: app/Filament/Concerns/InSignalGroup.php:16, `return __('kokpit.signal.navigation_group');`]. Not opted into global search (the panel uses `->globalSearchResourceOptIn()`) [VERIFIED: app/Providers/Filament/AdminPanelProvider.php:133, `->globalSearchResourceOptIn()`].
- **Partner gets 403, not 404:** the fail-closed scope would answer 404 first, so every page overrides `mount()` with `abort_unless(KnowledgeArticleResource::canAccess(), 403)`. [VERIFIED precedent: app/Filament/Resources/ClientResource/Pages/EditClient.php:34, `abort_unless(ClientResource::canAccess(), 403);`]
- **Archived articles stay reachable:** `getEloquentQuery()` and `getRecordRouteBindingEloquentQuery()` remove only `SoftDeletingScope`, exactly as `ProjectResource` does [VERIFIED: app/Filament/Resources/ProjectResource.php:118,126, `->withoutGlobalScopes([SoftDeletingScope::class])`]. Never call bare `withoutGlobalScopes()`: `tests/Arch/QueryEscapeHatchTest.php` has an empty allowlist and fails on it.
- **List query:** select explicit columns (`id, title, version_no, edited_at, sensitive, deleted_at`), never `body_md`, so the TOASTed body is not pulled for a table. Add a test that asserts the selected columns.
- **Preview tab:** `Tabs::make()->livewireProperty('activeTab')` with `Tab` keys `write` and `preview`, plus `public string $activeTab = 'write'` on the page. Each tab click runs `$set('activeTab', key)` [VERIFIED: vendor/filament/schemas/src/Components/Tabs.php:773, `? "\$set('{$livewireProperty}', '" . addslashes($tabKey) . "')"`] and the panel of an inactive tab renders nothing [VERIFIED: vendor/filament/schemas/src/Components/Tabs/Tab.php:229, `<?= $isActive ? $this->getChildSchema()->toHtml() : '' ?>`], so every preview open is a server roundtrip that carries the deferred editor state. The Preview tab body is `Html::make(fn (Get $get) => new HtmlString(view('filament.knowledge.prose', ['html' => app(KnowledgeMarkdown::class)->render((string) $get('body'))])->render()))`. The view screen uses the same Blade view, so the markup is identical. The preview never calls the scanner and never writes.
- **MarkdownEditor:** default toolbar is `['bold', 'italic', 'strike', 'link'], ['heading'], ['blockquote', 'codeBlock', 'bulletList', 'orderedList'], ['table', …attachFiles]` and its Alpine init disables `togglePreview` and `toggleSideBySide` [VERIFIED: vendor/filament/forms/src/Components/MarkdownEditor.php:39-44; vendor/filament/forms/resources/js/components/markdown-editor.js:154-155, `togglePreview: null, toggleSideBySide: null`]. Use `toolbarButtons([...])` exactly as the UI-SPEC lists, `fileAttachments(false)`, `maxLength(100000)`, `minHeight('24rem')`. There is no task-list button (UI-SPEC Deviation 1 stands). Do not make the editor `->live()`; typing sends no request and the state rides along with the next call.
- **Leave warning (D-08), scoped, no panel-wide switch:** `HasUnsavedDataChangesAlert` declares `protected ?bool $hasUnsavedDataChangesAlert = null;` and `protected function hasUnsavedDataChangesAlert(): bool { return $this->hasUnsavedDataChangesAlert ?? Filament::hasUnsavedChangesAlerts(); }` [VERIFIED: vendor/filament/filament/src/Pages/Concerns/HasUnsavedDataChangesAlert.php:14,31-34]. Override that method to `return true` on `CreateKnowledgeArticle` and `EditKnowledgeArticle` only. It compares a hash of `$this->data` with `savedDataHash`, so a Preview tab switch (a different property) does not trigger it. The alert covers the SPA mode (`setUpFilamentSpaModeUnsavedDataChangesAlert`) and plain reloads.
- **Save flow on the page:** `handleRecordUpdate()` / `handleRecordCreation()` call `SaveArticle` with `expectedVersion` from a `#[Locked] public int $loadedVersion` set in `mount()` and never refreshed on a refusal. Catch `StaleArticle` -> persistent warning notification with the action "Načíst aktuální verzi" (`->url(view url, shouldOpenInNewTab: true)`) then `throw new Halt` (same pattern as `EditInvoice`, which catches a `DomainException`, sends a notification and throws `Halt`). Catch `SecretGuardRefused` -> store `findings` (rule key and line only) in `#[Locked] public array $guardFindings`; for an ambiguous-only refusal also store `#[Locked] public ?string $refusedBodyHash = hash('sha256', normalised body)`; then `Halt`. A "Uložit článek i přesto" form action calls `saveAnyway()`, which passes `acknowledge: true` only when `hash_equals($this->refusedBodyHash, hash('sha256', normalise(current body)))`; any other body means a normal save that is scanned again (UI-SPEC "acknowledgment applies only to the exact refused save"). Clear `guardFindings` when the body changes (an `afterStateUpdated` on the editor, or clear on the next save attempt, as the UI-SPEC says "stays until the next save attempt").
- **Guard callouts** use `Filament\Schemas\Components\Callout` with `->color('danger'|'warning')`, `->icon(...)`, built from `lang/cs` rule labels and line numbers only. `Callout`, `Html`, `View`, `Tabs` all exist in `vendor/filament/schemas/src/Components` [VERIFIED: directory listing].
- **Assets:** register in a service provider: `FilamentAsset::register([Css::make('kokpit-prose', resource_path('css/kokpit-prose.css')), Js::make('knowledge-copy', resource_path('js/knowledge-copy.js'))])`. Files land in `public/css/app/{id}.css` and `public/js/app/{id}.js` [VERIFIED: vendor/filament/support/src/Assets/Css.php:55-64 and Js.php:180-185, `return ltrim("{$path}/css/{$this->getPackage()}/{$this->getId()}.css", '/');`], published by `filament:assets`, which `filament:upgrade` runs [VERIFIED: vendor/filament/support/src/Commands/UpgradeCommand.php:19, `AssetsCommand::class,`], which `composer.json` runs on `post-autoload-dump` [VERIFIED: composer.json:84, `"@php artisan filament:upgrade"` under `post-autoload-dump`]. No Vite and no Tailwind build exist, so write plain CSS using Filament's `var(--gray-*)` etc. as the UI-SPEC requires.
- **Navigation order:** groups that are not registered via `navigationGroups()` sort by first appearance, so "after the work groups, before Signal and Správa" is not guaranteed by `navigationSort` alone (Signal items sort 1-5, Správa items 76-95). [VERIFIED: NavigationManager.php:154-178, unregistered groups all return `count($registeredGroups)`]. Pin the order with `->navigationGroups([...])` in `AdminPanelProvider` and add a Feature test over `Filament::getNavigation()`.

### Pattern 6: Data model (all kinds from the first migration; no UI beyond general articles)

Conventions that the schema tests enforce: uuid keys with `uuidv7()` default, `timestampsTz()`, no identity columns, every `*_type` morph column has a uuid sibling [VERIFIED: tests/Support/PgSchema.php:81-156 rule docblocks R1-R6; migration style from `2026_10_27_000200_create_cash_receipts_table.php`: `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))`, constraints added with `DB::statement('ALTER TABLE … ADD CONSTRAINT …')`].

**`knowledge_folders`** (Admin only; UI in Phase 19, table and trigger now because the roadmap mapping note puts "the folder and version tables with their integrity triggers" in this phase): `id`, `parent_id` self FK `ON DELETE RESTRICT`, `name`, `timestampsTz`. `UNIQUE (parent_id, lower(name)) NULLS NOT DISTINCT`, CHECK `name ~ '[^[:space:]]'`. `BEFORE INSERT OR UPDATE OF parent_id` trigger that takes `pg_advisory_xact_lock(hashtext('knowledge_folders_tree'))`, refuses self-parent, refuses a cycle (recursive CTE over ancestors of the new parent) and a depth over the cap, with its own SQLSTATE next to `KP001`/`KP002`.
**Executed on PostgreSQL 18.6** in a scratch database (dropped afterwards): the duplicate root name was refused by the `NULLS NOT DISTINCT` expression index, the cycle (A under its child B) and the self-parent were refused, the depth cap fired at the configured level. **Gap found:** that trigger measured only the depth of the moved node's new position; moving a deep subtree under a deep parent must also add the height of the moved subtree (a downward CTE) or the cap can be exceeded. The Phase 19 `MoveFolder` Action needs the same arithmetic; put it in the trigger now.

**`knowledge_articles`** (one table for every kind):

| Column | Notes |
|--------|-------|
| `id` uuid v7 | |
| `kind` text | CHECK in (`general`, `incident`, `incident_page`, `retrospective`) — the name set recommended here; a trigger refuses any change of `kind` after insert (D-claim "fixed at creation") |
| `parent_id` uuid null, self FK RESTRICT | only incident sub-pages; CHECK `(kind = 'incident_page') = (parent_id IS NOT NULL)` |
| `title` text | CHECK not blank, `char_length <= 200` (UI-SPEC) |
| `body_md` text not null default '' | CHECK `char_length(body_md) <= 100000` |
| `version_no` int not null default 1 | CHECK `>= 1` |
| `edited_at` timestamptz not null | timestamp of the current version; stored (UI-SPEC Deviation 2); archive and restore never touch it; default sort of the list |
| `sensitive` boolean not null default false | |
| `folder_id` uuid null FK RESTRICT | CHECK `folder_id IS NULL OR kind = 'general'` |
| `client_id`, `project_id` uuid null | composite FK `(project_id, client_id) REFERENCES projects (id, client_id)`; `projects_id_client_unique` already exists [VERIFIED: database/migrations/2026_10_11_000100_create_time_entries_table.php:48, `ALTER TABLE projects ADD CONSTRAINT projects_id_client_unique UNIQUE (id, client_id)`]; CHECK `project_id IS NULL OR client_id IS NOT NULL` |
| `client_visible` boolean not null default false | name settled in the milestone research summary; CHECKs: `NOT client_visible OR client_id IS NOT NULL`, `NOT (client_visible AND sensitive)`, `kind IN ('general','incident') OR NOT client_visible` — cheap now, costly to add under data later; no UI before Phase 23 |
| `deleted_at`, `timestampsTz` | archive = soft delete; hard delete is never offered |

Left to later phases on purpose: `page_type` (Phase 21 adds it with its own CHECK; a nullable column is non-breaking), incident metadata and numbers, the retrospective overview table, images, search vector. No `created_by`/`updated_by` on the article; the author lives on the version row.

**`knowledge_article_versions`:** `id`, `article_id` FK RESTRICT, `version_no`, `title`, `body_md`, `author_id` FK users, `created_at` only (no `updated_at`). `UNIQUE (article_id, version_no)`, CHECK `version_no >= 1`, same `char_length` CHECKs. A purpose-built trigger function `BEFORE UPDATE OR DELETE` refusing with `ERRCODE = 'KP001'` plus a `BEFORE TRUNCATE` statement trigger, in the style of `kokpit_refuse_truncate()` [VERIFIED: database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php:56, `RAISE EXCEPTION 'table %.% is guarded: truncate refused', … USING ERRCODE = 'KP001';`]. Executed on PG 18.6: UPDATE, DELETE and TRUNCATE were all refused and the row survived. Phase 19 redaction replaces the function with a variant that admits a transaction-local setting (the `kokpit.cash_receipt_unlock` pattern); do not build that now. Optional integrity extra: a `DEFERRABLE INITIALLY DEFERRED` foreign key `(id, version_no)` from the article to `knowledge_article_versions (article_id, version_no)` guarantees the current version row exists; planner's call.

**Access seam:** all three models `use DeniesPartners` and `implements PartnerIsolated` [VERIFIED: app/Domain/Shared/Auth/DeniesPartners.php:28, `$query->whereRaw('1 = 0');`], policy `AdminOnlyPolicy` registered next to the others in `AccessServiceProvider` [VERIFIED: app/Providers/AccessServiceProvider.php:137, `Gate::policy(TimeEntry::class, AdminOnlyPolicy::class);`]. `TagType` gets `case Knowledge = 'knowledge'`; `Tag::constrainForPartner` keeps only `TagType::Project` tags, so no Partner ever sees a knowledge tag [VERIFIED: app/Domain/Shared/Models/Tag.php:45-46, `->where($this->qualifyColumn('type'), TagType::Project->value)`; app/Domain/Shared/Tags/TagType.php:18-20 `case Client = 'client'; case Project = 'project'; case Task = 'task';`].

### Declaration checklist (each item fails a test when forgotten)

| Place | Edit |
|-------|------|
| `app/Domain/Shared/Database/MorphMap.php` | `knowledge_folder`, `knowledge_article`, `knowledge_article_version` aliases (short snake_case nouns) |
| `app/Domain/Shared/Tags/TagType.php` | `Knowledge` case |
| `app/Providers/AccessServiceProvider.php` | three `Gate::policy(..., AdminOnlyPolicy::class)` |
| `tests/Arch/ModelDeclarationTest.php` | add models to the "finds every model" list (line 65) and the PartnerIsolated list (line 150) |
| `tests/Support/CanaryRegistry.php` and `tests/Isolation/CanaryRegistryTest.php` | one fixture per PartnerIsolated model (keyed by client; use a client-linked article carrying the canary in the body) and the expected-models list (line 115) |
| `tests/Support/PartnerMatrix.php` | `KnowledgeArticleResource::class => ['admin' => true, 'partner' => false, 'reason' => …]` in `panelClasses()`; three models in `adminOnlyModels()` |
| `tests/Support/ChannelMatrix.php` | eight cells for each model (all `none` for Partner; `activity` applicable only for the article, others `NA_ACTIVITY`) |
| `tests/Arch/ActivityAllowlistTest.php` | add `KnowledgeArticle::class` to the logging-models list |
| `tests/Support/RouteRules.php` | nothing for Filament resource routes (authenticated); a new custom route would need an entry. This phase adds none |
| `lang/cs/enums.php`, `lang/cs/knowledge.php` | `ArticleKind` labels (Enum label test scans every `HasLabel` enum) and every UI-SPEC string |
| `.gitignore` | `/public/css/app/`, `/public/js/app/` |

### Anti-Patterns to Avoid
- **`TextEntry::markdown()`, `Str::markdown()`, `->markdown()` on article text:** defaults allow raw HTML and unsafe links and use Filament's global sanitiser; use `KnowledgeMarkdown` only. Pin with an Arch test scanning `app/` and `resources/views/` for `->markdown(` and `{!!` outside the single prose view.
- **Stale check by `updated_at`:** use `version_no` from the locked row; `updated_at` moves on archive and flag changes.
- **Scanning before the stale check, or writing before the scan:** a refused or stale save must leave no row and no version.
- **Logging the finding value, even masked:** the `SecretFinding` DTO has no value field by design.
- **A hard delete action or a cascade on `knowledge_*`:** every FK is `RESTRICT`; archive is the only removal.
- **Reading `body_md` in list queries, global search, notifications or breadcrumbs.**
- **Putting the acknowledgment on the model or in the session:** it must be a call argument bound to the refused body hash.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Markdown parsing | A regex Markdown converter | `league/commonmark` AST + events | Fences in lists and quotes, reference links, nesting limits |
| Fence detection for the normaliser | Regex over backticks | `MarkdownParser` + `FencedCode`/`IndentedCode` `getStartLine()/getEndLine()` | Executed: handles quotes, lists, tilde fences, unclosed fences |
| HTML sanitising | `strip_tags`, own whitelist | `symfony/html-sanitizer` with an own config | DOM-based, scheme checks |
| Syntax highlighting | Own tokenizer | `tempest/highlight` behind the round-trip invariant | 40+ languages, class-only output |
| Gap-free per-article numbering | `max(version_no)+1` | `lockForUpdate` on the article row + `UNIQUE (article_id, version_no)` | Repo precedent, race-free |
| Immutable rows | Application checks only | Trigger refusing UPDATE/DELETE/TRUNCATE with `KP001` | Holds for raw SQL and console too |
| Folder cycle prevention | App-only check | `BEFORE … OF parent_id` trigger with advisory lock | Two concurrent moves each pass an app check and together form a cycle |
| Unsaved-changes warning | Custom `beforeunload` script | `HasUnsavedDataChangesAlert` via the page's `hasUnsavedDataChangesAlert()` override | SPA-aware, already in Filament 5.12 |
| Optimistic lock scaffolding | New abstraction | Copy `UpdateTaskDescription` structure | Proven in this repo |
| Secret scanner library | Third-party scanner package | Own `SecretScanner` (8 reviewed rules) | No mature PHP package (STACK research); rules are short and fully tested here |

**Key insight:** the two places where a library default is actively wrong for this feature are CommonMark (`html_input` allow, unsafe links allowed) and the HTML sanitiser (20000-character silent truncation, inert anchors kept). Everything that makes the output safe must be asserted by a test over a hostile corpus, because neither failure is visible in normal use.

## Common Pitfalls

### Pitfall 1: Sanitiser silently truncates long articles
**What goes wrong:** the view screen shows the first 20000 characters of the HTML and nothing else, no error. **Why:** `HtmlSanitizerConfig` default `maxInputLength` is 20000. **Avoid:** `withMaxInputLength(-1)` (or a fixed limit above the largest possible highlighted output). **Warning signs:** a test with a 30000-character paragraph; the executed spike returned 20004 characters with the default.

### Pitfall 2: Highlighter changes the text that Copy must reproduce
**What goes wrong:** code containing `{+ … +}` loses its markers and gets hidden spans; Copy copies something the author did not write. **Avoid:** the round-trip invariant (Pattern 2) with a fallback to plain escaped code; a test with a block that contains each of the four marker pairs. **Warning signs:** `style="display: none"` in rendered output (the sanitiser drops `style`, leaving a visible empty span).

### Pitfall 3: Inert `<a>` and `<img>` survive
**What goes wrong:** `[x](javascript:…)` renders `<a>x</a>` (harmless but violates "plain text"), an image with a `data:` source keeps its `src` through CommonMark. **Avoid:** AST pass for `Link` and `Image`, then a sanitiser as the second layer. **Warning signs:** `<a ` without `href` in output.

### Pitfall 4: Memory and time blow-up on adversarial input
**What goes wrong:** 100 KB of `[` peaks at 186 MB; 128 MB CLI `memory_limit` aborts. **Avoid:** body cap of 100 000 characters in form, Action and CHECK; `max_nesting_level` 20; `max_delimiters_per_line` 1000 (limits emphasis only, not brackets). **Warning signs:** a hostile-corpus test with bracket floods run under the CI memory limit. The production `memory_limit` of the deploy target is unknown (A4).

### Pitfall 5: Acknowledgment that outlives its save
**What goes wrong:** a "confirmed" flag persisted in the session or model lets a later, different body skip the guard. **Avoid:** per-call boolean, bound to the sha256 of the exact refused normalised body, `#[Locked]` Livewire properties, scanner re-run on every real save. **Warning signs:** a test that changes the body after the refusal and expects another refusal.

### Pitfall 6: Stale check compared against refreshed state
**What goes wrong:** after a refused save the page re-reads the record and the next attempt passes the version check. **Avoid:** `#[Locked] int $loadedVersion` set only in `mount()`; Filament `EditRecord` refreshes the record after `afterSave`, never after `Halt`. UI-SPEC: "the version number the form started from is not silently refreshed".

### Pitfall 7: A no-op save that is not one
**What goes wrong:** CRLF vs LF, trailing spaces or a trailing blank line make every save a "change" and inflate versions; or normalisation inside fences corrupts commands. **Avoid:** compare normalised stored text with normalised input; property test `normalise(normalise(x)) === normalise(x)`; fixture whose fenced block content must stay byte-identical (except LF unification).

### Pitfall 8: Generated asset files appear in git
**What goes wrong:** `filament:upgrade` (every `composer install`) writes `public/css/app/kokpit-prose.css` and `public/js/app/knowledge-copy.js`; they are not ignored today (`git check-ignore` printed nothing and exited 1; only `/public/js/filament/`, `/public/css/filament/`, `/public/fonts/filament/` are ignored). **Avoid:** add both directories to `.gitignore` in the same change that registers the assets.

### Pitfall 9: Forgotten declarations break CI late
**What goes wrong:** Arch, Isolation and Audit suites fail on a new model, resource or Partner-facing class that is not declared (checklist above). **Avoid:** create the declarations in the first plan together with the models; the red tests then drive the rest.

### Pitfall 10: Partner gets 404 instead of 403
**What goes wrong:** the deny-all scope makes a Partner's `GET /admin/…/{id}` answer 404 before any access check, which differs from the audit expectation of 403 on screens. **Avoid:** `abort_unless(canAccess(), 403)` in the `mount()` of every page (precedent in `EditClient`).

### Pitfall 11: Test fixtures that look like real secrets
**What goes wrong:** a literal token in a test trips the hygiene hook or gitleaks, or the planner pastes a realistic value. **Avoid:** one helper `Tests\Support\SecretFixtures` building every sample from fragments at runtime (executed pattern: `'AK'.'IA'.…`, `'sk_'.'live_'.str_repeat(…)`, `random_bytes` for PEM bodies and tokens); never print a fixture in an assertion message; run `scripts/check-sensitive.sh` over new test files.

### Pitfall 12: Group order in the sidebar
See Pattern 5 (navigation order). Unregistered groups follow first appearance, so a sort number alone does not place Znalosti.

### Pitfall 13: Livewire tab content and EasyMDE lifecycle
**What goes wrong:** with `livewireProperty` tabs the Write panel is removed from the DOM while Preview is open, so the editor is re-initialised on return (undo history lost, cursor position lost). **Avoid:** accept it (state lives in `$this->data`); verify in the human check that typed text survives a Write → Preview → Write round trip. **Warning signs:** an empty editor after switching back, which would mean the deferred state did not reach the server before the tab call (A5).

## Code Examples

All executed in this session unless marked otherwise (PHP 8.5.13 in DDEV; identical md5 of the output on host PHP 8.6.0).

### Example 1: Hardened environment, link and image policy, callout, code renderer, sanitiser
```php
// Source: executed prototype (scratchpad proto.php); structure per commonmark.thephpleague.com/2.x/
$env = new Environment([
    'html_input' => 'escape', 'allow_unsafe_links' => false,
    'max_nesting_level' => 20, 'max_delimiters_per_line' => 1000,
]);
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new GithubFlavoredMarkdownExtension());
$env->addRenderer(Callout::class, new CalloutRenderer());
$env->addRenderer(TaskListItemMarker::class, new TaskMarkerRenderer(), 10);
$env->addRenderer(FencedCode::class, new CodeRenderer(new Highlighter(new CssTheme())), 10);

$env->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $e): void {
    $doc = $e->getDocument();
    foreach ($doc->iterator() as $n) {                       // links and images
        if ($n instanceof Image) {
            $alt = '';
            foreach ($n->iterator() as $c) { if ($c instanceof Text) { $alt .= $c->getLiteral(); } }
            $n->replaceWith(new Text($alt));
        } elseif ($n instanceof Link && ! preg_match('#^(https?://|mailto:)#i', $n->getUrl())) {
            foreach ($n->children() as $c) { $n->insertBefore($c); }   // hoist children, drop the anchor
            $n->detach();
        }
    }
    foreach ($doc->children() as $bq) {                      // callouts: top-level block quotes only
        if (! $bq instanceof BlockQuote) { continue; }
        $p = $bq->firstChild();  if (! $p instanceof Paragraph) { continue; }
        $t = $p->firstChild();   if (! $t instanceof Text) { continue; }
        if (! preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]$/i', $t->getLiteral(), $m)) { continue; }
        $next = $t->next();
        if ($next !== null && ! $next instanceof Newline) { continue; }
        $callout = new Callout(strtoupper($m[1]));
        $t->detach(); $next?->detach();
        if ($p->firstChild() === null) { $p->detach(); }
        foreach ($bq->children() as $child) { $callout->appendChild($child); }
        $bq->replaceWith($callout);
    }
});

// CodeRenderer::render(): language allowlist, one trailing "\n" removed, round-trip invariant
$code = $node->getLiteral(); if (str_ends_with($code, "\n")) { $code = substr($code, 0, -1); }
$html = htmlspecialchars($code, ENT_QUOTES);
if ($lang !== '') {
    $hl = $highlighter->parse($code, $lang);
    if (html_entity_decode(strip_tags($hl), ENT_QUOTES) === $code) { $html = $hl; }   // else plain text
}

$sanitiser = new HtmlSanitizer(
    (new HtmlSanitizerConfig())
        ->allowSafeElements()->allowRelativeLinks(false)->allowLinkSchemes(['https', 'http', 'mailto'])
        ->allowMediaSchemes([])->allowRelativeMedias(false)
        ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')->dropAttribute('target', 'a')
        ->allowElement('input', ['type', 'checked', 'disabled', 'aria-hidden'])
        ->allowElement('button', ['type', 'class', 'data-kokpit-copy'])
        ->allowElement('pre', ['tabindex'])->allowElement('code', [])
        ->allowElement('span', ['class'])->allowElement('div', ['class', 'role'])->allowElement('p', ['class'])
        ->withMaxInputLength(-1),
);
```
Observed on the hostile corpus: `<script>`, `<iframe>`, `onclick`, `onerror` appear only as escaped text; `javascript:` / `JaVaScRiPt:` / `data:` / `vbscript:` / relative / protocol-relative / reference-style links become plain text; images become alt text; `[!NOTE]` and `[!TIP]` became callout blocks; `[!WARNING] same line text`, `[!FOO]` and a nested `[!NOTE]` stayed blockquotes; a code fence info string `php"><script>x</script>` rendered as plain code with an empty language; HTML entities in the body (`&lt;script&gt;`) stay visible as text.

### Example 2: Normaliser (AST line mask)
```php
// Source: executed prototype (scratchpad norm.php)
$md  = ltrim(str_replace(["\r\n", "\r"], "\n", $md), "\u{FEFF}");
$doc = (new MarkdownParser($env))->parse($md);                       // CommonMarkCore + GFM, no rendering
$protected = [];
foreach ($doc->iterator() as $n) {
    if ($n instanceof FencedCode || $n instanceof IndentedCode) {
        for ($l = $n->getStartLine(); $l <= $n->getEndLine(); $l++) { $protected[$l] = true; }
    }
}
$lines = explode("\n", $md);
foreach ($lines as $i => $line) { if (! isset($protected[$i + 1])) { $lines[$i] = rtrim($line, " \t"); } }
return rtrim(implode("\n", $lines), "\n");
```

### Example 3: Secret fixtures assembled at runtime (D-05)
```php
// Source: executed pattern (scratchpad scan.php); fragments never form a real-looking line
$aws      = 'AK'.'IA'.'ABCDEFGHIJKLMNOP';
$stripe   = 'sk_'.'live_'.str_repeat('a1B2', 7);
$github   = 'gh'.'p_'.str_repeat('aB3', 12);
$jwt      = implode('.', [rtrim(base64_encode('{"alg":"HS256"}'), '='), rtrim(base64_encode('{"sub":"1"}'), '='), 'abcdefgh12345']);
$pem      = '-----BEGIN '.'RSA PRIV'.'ATE KEY-----'."\n".chunk_split(base64_encode(random_bytes(96)), 64, "\n").'-----END '.'RSA PRIV'.'ATE KEY-----';
$urlCred  = 'postgres://app:'.'Xk29'.'sLq7@db.example.com/app';
$password = 'db password = '.'Tr0ub4dor'.'x9';
```

### Example 4: Append-only versions and optimistic save core
```sql
-- Source: executed on PostgreSQL 18.6 in a scratch database (dropped afterwards)
CREATE TRIGGER knowledge_article_versions_frozen BEFORE UPDATE OR DELETE ON knowledge_article_versions
  FOR EACH ROW EXECUTE FUNCTION kokpit_refuse_row_change();      -- RAISE ... USING ERRCODE = 'KP001'
CREATE TRIGGER knowledge_article_versions_truncate_guard BEFORE TRUNCATE ON knowledge_article_versions
  FOR EACH STATEMENT EXECUTE FUNCTION kokpit_refuse_row_change();
CREATE UNIQUE INDEX knowledge_folders_sibling_name_unique
  ON knowledge_folders (parent_id, lower(name)) NULLS NOT DISTINCT;
```
```php
// Source: pattern of app/Domain/Tasks/Actions/UpdateTaskDescription.php, adapted
DB::transaction(function () use (...): SaveResult {
    $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
    if ($locked->version_no !== $expectedVersion) { throw new StaleArticle; }          // before anything else
    $body = $this->normaliser->normalise($input->body);
    if ($input->title === $locked->title && $body === $locked->body_md) { /* metadata only, no version */ }
    $findings = $this->scanner->scan($body);                                            // never holds a value
    // blocking -> throw SecretGuardRefused; ambiguous && ! $acknowledge -> throw; ambiguous && $acknowledge -> sensitive = true
    $locked->forceFill([... 'version_no' => $locked->version_no + 1, 'edited_at' => now()])->save();
    $locked->versions()->create([... 'version_no' => $locked->version_no, 'author_id' => $actor->getKey()]);
});
```

### Example 5: Preview tab bound to a Livewire property (structure, not executed in a browser)
```php
// Source: vendor/filament/schemas/src/Components/Tabs.php (livewireProperty) and Tabs/Tab.php
Tabs::make()->livewireProperty('activeTab')->tabs([
    'write'   => Tab::make(__('knowledge.tabs.write'))->schema([MarkdownEditor::make('body')->…]),
    'preview' => Tab::make(__('knowledge.tabs.preview'))->schema([
        Html::make(fn (Get $get) => view('filament.knowledge.prose', [
            'html' => app(KnowledgeMarkdown::class)->render((string) $get('body')),
        ])),
    ]),
]);
```

### Spike results (what was executed, for the planner's confidence)

| Spike | Result |
|-------|--------|
| Hardened pipeline on a hostile corpus (script, img onerror, event handlers, six unsafe link forms, reference-style, data images, fence-info injection, nested callouts) | No live markup; all dropped links plain text; callouts correct; output identical on PHP 8.5.13 (DDEV) and 8.6.0 (host) |
| Highlighter round trip | `{+ +}` family breaks text equality; the invariant falls back to plain text; unknown language falls back to escaped text; 200 KB of nginx config highlights in 72 ms |
| Render time and memory | 228 KB of ordinary paragraphs 242 ms; 120 KB emphasis flood 395 ms; 200 KB backtick flood 733 ms; 100 KB bracket flood 526 ms and 186 MB peak |
| Normaliser | Idempotent; fences in quotes and lists protected; unclosed fence protected |
| Scanner regexes | All six blocking patterns and both ambiguous patterns hit their fixtures; placeholders and prose do not; no regex over 50 ms on 200 KB adversarial input |
| PostgreSQL 18.6 | `NULLS NOT DISTINCT` expression unique index, cycle and self-parent refusal, depth cap, append-only versions trigger (UPDATE, DELETE, TRUNCATE all refused) |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Str::markdown()` with Filament's global sanitiser | Own `Environment` + own sanitiser config | Filament 5 docs warn to combine `sanitizeHtml()` with `markdown()`; defaults still permit unsafe links | Own pipeline required |
| Two partial unique indexes for nullable parent | `UNIQUE … NULLS NOT DISTINCT` | PostgreSQL 15 | One index (executed on 18.6) |
| Panel-wide unsaved-changes alert | Per-page `hasUnsavedDataChangesAlert()` override | Present in Filament 5.12 | No global behaviour change |
| Alert callouts via niche packages | Own listener on `DocumentParsedEvent` | n/a | ~40 lines, Czech labels |

**Deprecated/outdated:** `MarkdownEditor::fileAttachments()` default (always `public` visibility, `fileAttachmentsVisibility()` throws a `LogicException` [VERIFIED: vendor/filament/forms/src/Components/MarkdownEditor.php:65, `throw new LogicException('The visibility of file attachments for markdown content is always public…`]); keep `fileAttachments(false)` until Phase 20.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | GitHub treats `> [!NOTE] text on the same line` as a plain blockquote and accepts lowercase types; Kokpit mirrors this (accepts types case-insensitively, requires the marker alone on its line) | Pattern 2 | Cosmetic: a callout renders as a quote or vice versa; author sees the difference at once |
| A2 | The Spatie `deleted` / `restored` events write no activity row when nothing allowlisted is dirty, hence the explicit archive/restore rows | Pattern 1 | A duplicate or missing audit row; one test settles it |
| A3 | D-10 "never altered inside fenced code" is read as: LF unification everywhere, nothing else changed inside code (trailing spaces and blank lines kept) | Pattern 4 | If the owner wants raw CRs kept in code, the normaliser needs a different mask; low impact |
| A4 | The deploy target's PHP `memory_limit` is at least 256 MB and a 100 000 character cap (peak 186 MB on the worst adversarial body) is acceptable | Pitfall 4 | Out-of-memory on an adversarial body; lower the cap to 50 000 characters (99 MB peak) if the target is smaller |
| A5 | Deferred (non-live) MarkdownEditor state reaches the server with the `$set('activeTab', …)` call of a tab click, so the preview shows unsaved text | Pattern 5, Pitfall 13 | Preview would show the last synced text; mitigation is a Livewire feature test plus a human check, fallback `->live(onBlur: true)` on the editor |
| A6 | Stripe test-mode keys and the AWS documentation example key should be ambiguous / allowlisted rather than blocking | Pattern 3 | Either docs get blocked with no override (too strict) or a real test key slips as "ambiguous"; owner's call, see Open Question 2 |
| A7 | `ArticleKind` values `general`, `incident`, `incident_page`, `retrospective` | Pattern 6 | Immutable after creation, so choose deliberately; the names match the roadmap and requirement wording |
| A8 | Body cap 100 000 characters and title cap 200 are acceptable product limits | Pattern 6 | Owner may want a larger cap for long runbooks; raising it needs the memory check of A4 |

## Open Questions

1. **Stripe test keys and documentation placeholders**
   - What we know: D-01 lists "AWS/Stripe/GitHub-style tokens" as unambiguous; an unambiguous finding has no override.
   - What is unclear: whether test-mode keys (`sk_test_…`) and the public AWS example key must also hard-block.
   - Recommendation: block live and restricted-live keys and webhook secrets; treat test-mode keys as ambiguous; allowlist a value ending in `EXAMPLE`. Confirm with the owner when D-01 is next touched; implement as data in the rule table so it is a one-line change.
2. **Scan skipped for a no-op save**
   - What we know: a no-op save creates no version (D-10) and the flag toggle must remain editable on an article whose stored body has an acknowledged ambiguous finding.
   - Unclear: whether SEC-01 "every save scans" should also cover a metadata-only save.
   - Recommendation: scan only when the normalised content differs from the stored current version (nothing new enters the store otherwise). Record as decision in the plan.
3. **Folder depth cap value**
   - Research suggests 6 to 8; the trigger needs one number now.
   - Recommendation: 6, as a single constant shared by the trigger and the later `MoveFolder`.
4. **`edited_at` for archive and restore**
   - UI-SPEC says archive and restore must not reorder the list; `edited_at` is stored and only `SaveArticle` writes it. No open risk, listed so the planner does not use `updated_at`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | all tests, composer, migrations | ✓ | v1.25.6, web PHP 8.5.13 | — |
| PostgreSQL | schema, triggers, tests | ✓ (DDEV db) | 18.6 | — |
| Composer | three packages | ✓ (host and DDEV) | present | — |
| Host PHP | pure Unit tests only | ✓ | 8.6.0 (project requires ^8.5; DDEV is the reference) | run in DDEV |
| ext-intl | `Normalizer` for the scanner copy | ✓ (host `php -m` lists intl; Filament requires it) | — | skip fold, scan raw (weaker) |
| Node / Vite | not needed | n/a | — | the repo has no `package.json` and uses `FilamentAsset` with plain files |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none. Note the repo rule: migrations and the full suite run only after merge into the HEAD branch; Unit tests for the scanner, normaliser and renderer need neither the application nor a database and are the cheap checks allowed inside a worktree.

## Validation Architecture

`workflow.nyquist_validation` is `true` in `.planning/config.json`.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13.3 (`composer.json`) |
| Config file | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Audit, Concurrency), `tests/Pest.php` |
| Quick run command | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge` (no database, no app boot) |
| Full suite command | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` then `ddev exec vendor/bin/pest --testsuite=Audit`; also `ddev composer lint`, `ddev composer stan`, `ddev composer check-licenses` (final run after merge, never from a worktree) |

Unit tests are plain PHPUnit without the application (so the renderer must not call `__()`; inject the translator). Feature and Isolation use `RefreshDatabase` on the guarded `kokpit_test` database; Concurrency tests commit real rows and spawn worker processes (pattern: `tests/Concurrency/TaskNumberConcurrencyTest.php` + `task-worker.php`, which refuses to run unless the database name ends in `_test`).

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| KB-03 | Hostile corpus renders as escaped text, links dropped to text, images to alt, no `<script>`, no `on*`, no `style`, no inert `<a>`; long article not truncated; bracket-flood stays under memory cap | unit | `pest tests/Unit/Knowledge/KnowledgeMarkdownTest.php` | ❌ Wave 0 |
| KB-03/KB-05 | Code block `textContent` equals source text for every language in the allowlist and for blocks with `{+ +}` markers; unknown language is plain text | unit | `pest tests/Unit/Knowledge/CodeBlockTest.php` | ❌ Wave 0 |
| KB-04 | Five callout types render; unknown type, same-line text and nested callout stay blockquotes | unit | `pest tests/Unit/Knowledge/CalloutTest.php` | ❌ Wave 0 |
| KB-05 | Copy button markup present once per block; JS reads `textContent` (asset file contains no `innerHTML`/`innerText` read) | unit + manual | `pest tests/Unit/Knowledge/CodeBlockTest.php` + UAT item | ❌ Wave 0 |
| SEC-01/02 | Each rule hits its runtime-assembled fixture with correct line; placeholders and prose do not; finding DTO and exceptions carry no value; preg error fails closed | unit | `pest tests/Unit/Knowledge/SecretScannerTest.php` | ❌ Wave 0 |
| VER-01 / D-10 | Normaliser idempotent; fenced and indented code protected; CRLF/BOM/trailing space/trailing blank lines | unit | `pest tests/Unit/Knowledge/MarkdownNormaliserTest.php` | ❌ Wave 0 |
| KB-01, VER-01, SEC-01..03 | `SaveArticle`: create makes version 1; content change raises version by one and writes a snapshot; title-only change versions; no-op writes no version; blocking finding refused and nothing stored; ambiguous refused then acknowledged sets `sensitive`, writes the metadata-only `secret_acknowledged` row; a later body change needs a fresh acknowledgment; clean save never clears `sensitive`; activity `properties` JSON never contains title, body or a fixture fragment | feature | `pest tests/Feature/Knowledge/SaveArticleTest.php` | ❌ Wave 0 |
| KB-08 | Two edit screens from the same version: second save refused with the stale notice, typed text stays in the form, retry still refused | feature (Livewire) | `pest tests/Feature/Knowledge/EditKnowledgeArticleTest.php` | ❌ Wave 0 |
| KB-08, VER-01 | Real parallel processes: N workers save from the same expected version, exactly one wins; sequential saves produce gap-free numbers; DB refuses a duplicate `(article_id, version_no)` | concurrency | `pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` | ❌ Wave 0 |
| VER-01 | Versions table refuses UPDATE, DELETE, TRUNCATE (`KP001`); UNIQUE and CHECKs; folder cycle, self-parent, depth cap, duplicate root name; kind change refused; CHECKs on structure and `client_visible`/`sensitive` | feature (raw SQL) | `pest tests/Feature/Schema/KnowledgeTablesTest.php` | ❌ Wave 0 |
| KB-01, KB-02, KB-06, KB-07 | List/create/view/edit pages; preview equals view markup; archive/restore keep the version and `edited_at`; list default hides archived, filter shows them; list query selects no `body_md` | feature (Livewire) | `pest tests/Feature/Knowledge/KnowledgeArticleResourceTest.php` | ❌ Wave 0 |
| Partner seam | Partner sees no navigation entry, gets 403 on every page and URL, scopes return zero rows for all three models, canary articles never appear; matrices declared | isolation + audit | `pest tests/Isolation/KnowledgeVisibilityTest.php`, `pest --testsuite=Audit` | ❌ Wave 0 |
| Seam guards | No `->markdown(` / `Str::markdown` / `{!!` on knowledge content outside the one prose view; only `SaveArticle` writes `body_md` | arch | `pest tests/Arch/KnowledgeSeamTest.php` | ❌ Wave 0 |
| Existing declarations | Model, morph map, canary, activity allowlist, enum label, channel matrix tests stay green | arch/isolation/audit/feature | `pest tests/Arch tests/Isolation/CanaryRegistryTest.php` and `--testsuite=Audit` | ✅ exist, need edits |

Manual (UAT, end of phase per `human_verify_mode`): leave warning on reload and link click, none after save or Preview switch; Write → Preview → Write keeps typed text and the preview shows unsaved text; Copy button copies exact block text and shows the confirmation (clipboard needs HTTPS, DDEV has it); light and dark mode of callouts and code colours; keyboard focus ring on Copy.

### Sampling Rate
- **Per task commit:** `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge` (pure, allowed in a worktree) plus Pint on touched files.
- **Per wave merge (after merge into the HEAD branch):** migrations, then `pest --filter=Knowledge` across Feature, Isolation, Concurrency, Arch.
- **Phase gate:** the full CI-equivalent suite, Audit suite, `ddev composer stan`, `ddev composer lint`, `ddev composer check-licenses` green, then `scripts/check-sensitive.sh` on the staged diff, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Support/SecretFixtures.php` — runtime-assembled fake secrets (D-05), used by Unit, Feature and Concurrency tests
- [ ] `tests/Support/Fixtures/knowledge-hostile-corpus.md` (or a PHP heredoc helper) — fictional hostile Markdown (script tags, handlers, six link forms, fence-info injection, bracket flood generator)
- [ ] `tests/Unit/Knowledge/*` — renderer, code block, callout, scanner, normaliser tests (no app boot)
- [ ] `tests/Feature/Schema/KnowledgeTablesTest.php` — raw-SQL integrity tests in the style of `CashReceiptsTableTest`
- [ ] `tests/Concurrency/KnowledgeSaveConcurrencyTest.php` + `knowledge-save-worker.php` — modelled on the task-number worker
- [ ] `tests/Isolation/KnowledgeVisibilityTest.php`, `tests/Arch/KnowledgeSeamTest.php`
- [ ] Edits to the existing declaration files (checklist in Architecture Patterns), made in the same change as each model or resource
- [ ] Framework install: none (Pest present)

## Security Domain

`security_enforcement` is enabled (absent in config means enabled; `security_asvs_level` is 1, `security_block_on` is `high`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no change | Existing panel login, mandatory TOTP for Admin (unchanged) |
| V3 Session Management | no change | Existing Filament session; open item "SPA session behaviour on long edits" stays a UAT observation (a 419 on save after a long edit must not lose typed text; verify manually) |
| V4 Access Control | yes | `#[AccessRule(AdminOnly)]`, `AdminOnlyPolicy`, `DeniesPartners` scope, `abort_unless(canAccess(), 403)` on every page, Gate authorisation inside `SaveArticle`/Archive Actions (the Livewire endpoint is public, so the Action authorises itself, as `UpdateTaskDescription` does) |
| V5 Input Validation / Output Encoding | yes | `html_input=escape`, AST link/image policy, own sanitiser config, language allowlist, body (100 000) and title (200) caps in form, Action and CHECK |
| V6 Cryptography | no | No new crypto; `hash('sha256')` for the acknowledgment binding only; `hash_equals` for comparison |
| V7 Error Handling and Logging | yes | Exceptions, logs and activity rows carry rule keys and line numbers only; allowlisted activity attributes exclude `title` and `body_md`; scrub `body` from any external error reporter (none is installed in this repository: no Sentry, Bugsnag or Flare in `composer.json`; the open "Cross-cutting" item applies at deploy time) |
| V8 Data Protection | yes | Secret guard (scanner), `sensitive` flag, append-only versions (a secret that was acknowledged stays in history until the Phase 19 redaction path) |
| V12 Files and Resources | no | No uploads (`fileAttachments(false)`); images are Phase 20 |
| V13 API | no | No API route added |

### Known Threat Patterns for Markdown content in Laravel/Filament

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Stored XSS through raw HTML or event handlers in Markdown | Tampering / Elevation | `html_input=escape` + sanitiser; hostile-corpus test |
| `javascript:` / `data:` / `vbscript:` links, reference-style links | Tampering | AST drops unsafe links to plain text; scheme allowlist `https|http|mailto` |
| Tracking or exfiltrating images (`![](https://…)`) | Information disclosure | Images render as alt text in this phase |
| Code-fence info string markup injection | Tampering | Language allowlist; unknown renders plain escaped |
| Highlighter output differing from source (copy of altered text) | Tampering | Round-trip invariant |
| ReDoS in scanner rules | Denial of service | Bounded quantifiers, possessive-style structure, 200 KB adversarial timing test, fail closed on `preg_last_error` |
| Memory exhaustion by crafted bracket/emphasis floods | Denial of service | Body cap, nesting and delimiter limits |
| Secret leak through exception text, logs, activity `properties`, notification text | Information disclosure | `SecretFinding` without value; generic exception messages; tests grep outputs for fixture fragments |
| Lost update / replay of an old form | Tampering | `lockForUpdate` + `#[Locked]` expected version + hash-bound acknowledgment |
| Partner reaching knowledge by URL guess or Livewire call | Elevation / Information disclosure | Deny-all scope, policy, 403 in `mount()`, Gate inside Actions, canary test, Partner matrix and channel matrix declarations |
| Tampered Livewire property (`guardFindings`, `refusedBodyHash`, `loadedVersion`) | Tampering | `#[Locked]`; the Action re-validates everything server-side |
| Mass assignment of `version_no`, `sensitive`, `kind` | Tampering | `forceFill` only inside `SaveArticle`; `$fillable` empty or minimal; `kind` frozen by trigger |

## Sources

### Primary (HIGH confidence)
- Repository code read this session: `app/Domain/Shared/Models/KokpitModel.php`, `Shared/Auth/{DeniesPartners,PartnerIsolated,PartnerScope,KokpitPolicy}.php`, `Shared/Database/{MorphMap,Immutability}.php`, `Shared/Tags/TagType.php`, `Shared/Models/Tag.php`, `Shared/Sequences/SequenceAllocator.php`, `Audit/LogsAllowlistedActivity.php`, `Tasks/Actions/UpdateTaskDescription.php`, `Filament/Resources/{ProjectResource,ClientResource}` pages, `Filament/Concerns/{InSignalGroup,EnforcesResourceAccessRule,RethrowsDomainValidation}.php`, `Providers/Filament/AdminPanelProvider.php`, `Providers/AccessServiceProvider.php`, `bootstrap/app.php`, `composer.json`, `composer.lock`, `.gitignore`, `.planning/config.json`
- Test support read this session: `tests/Pest.php`, `tests/Support/{PartnerMatrix,RouteRules,ChannelMatrix,CanaryRegistry,Canary,ModelDeclaration,AuditDeclaration,EscapeHatchScanner}.php`, `tests/Arch/{ModelDeclarationTest,ActivityAllowlistTest,QueryEscapeHatchTest}.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Audit/*`, `tests/Concurrency/TaskNumberConcurrencyTest.php`
- Vendor code read this session: `vendor/filament/forms/src/Components/MarkdownEditor.php` and its JS, `vendor/filament/schemas/src/Components/{Tabs,Tabs/Tab,Callout,Html,View}.php`, `vendor/filament/filament/src/Pages/Concerns/HasUnsavedDataChangesAlert.php`, `Panel/Concerns/HasUnsavedChangesAlerts.php`, `Navigation/NavigationManager.php`, `vendor/filament/support/src/Assets/{Css,Js,Asset}.php`, `Commands/UpgradeCommand.php`, `vendor/league/commonmark/src/Environment/Environment.php`
- Executed spikes (scratch directory outside the repository, scratch database dropped): renderer and sanitiser prototype on PHP 8.5.13 in DDEV, highlighter behaviour and performance, normaliser, scanner regexes and ReDoS timing, PostgreSQL 18.6 trigger and index SQL
- https://commonmark.thephpleague.com/2.x/security/ — `html_input`, `allow_unsafe_links`, `max_nesting_level`, `max_delimiters_per_line` recommendations and the advice to limit input size upstream
- Packagist API (`repo.packagist.org/p2/…`, `packagist.org/packages/tempest/highlight.json`) — versions, licences, install counts, no abandonment

### Secondary (MEDIUM confidence)
- `.planning/research/{SUMMARY,STACK,ARCHITECTURE,PITFALLS,FEATURES}.md` (milestone research of 2026-11-25; their claims were re-checked where this phase depends on them, and corrected where the executed spikes disagreed: sanitiser inert anchors, highlighter markers, truncation default, body cap)

### Tertiary (LOW confidence)
- GitHub alert case-insensitivity and same-line behaviour (A1), activity-log `deleted` event behaviour (A2), deploy-target `memory_limit` (A4): not verified in this session

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions from `composer.lock`/Packagist, highlighter installed and exercised
- Architecture: HIGH for the write seam, data model and declarations (modelled on code read this session and on executed SQL); MEDIUM for the Filament tab roundtrip and leave-warning behaviour in a real browser
- Pitfalls: HIGH for the five renderer/scanner/normaliser pitfalls (each reproduced); MEDIUM for navigation order and EasyMDE re-initialisation

**Research date:** 2026-11-25
**Valid until:** 2026-12-25 for the renderer and Filament findings (Filament 5.12 and commonmark 2.10 are pinned in `composer.lock`); re-run the spike if `league/commonmark`, `symfony/html-sanitizer` or `tempest/highlight` change major or minor version.
