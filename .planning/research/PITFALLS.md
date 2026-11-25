# Pitfalls Research: Knowledge Center (milestone v1.1)

**Domain:** Knowledge base / wiki with client sharing, versioning and full-text search, added to an existing single-tenant CRM/ERP (Laravel 13, Filament 5 SPA panel, PostgreSQL 18, Spatie packages, Partner role with a restricted view in the same panel, public AGPL repository)
**Researched:** 2026-11-25
**Confidence:** MEDIUM-HIGH. Verified directly against the installed code in this repository (league/commonmark 2.10.7 defaults, Filament 5 `MarkdownEditor` source, existing `RichText` sanitiser, existing accent-insensitive search migration, existing audit harness in `tests/Support`) and against the PostgreSQL 18 manual (ts_headline safety, `websearch_to_tsquery`, ispell not shipped). Items tagged **[verify]** come from training knowledge and should become a test or a phase-research spike, not an assumption.

This file replaces the v1 pitfalls research (Oct 2026, still in git history). All examples are fictional (`example.com`, "Acme Studio s.r.o.", company ID `12345678`). No content, title or link from any real workspace appears here, and none may be written into fixtures, seeders, tests or planning docs.

Phase names below are **suggested logical phases** for the roadmapper (K1..K6), not final numbers:

| Id | Suggested phase | Contains |
|----|-----------------|----------|
| K1 | Data model, tree, visibility seam | tables, enums, tree integrity, `client_visible`/`sensitive` columns and CHECKs, Policy, `PartnerScope`, audit declarations (canaries, `ModelDeclaration`, `RouteRules`, `PanelInventory`), morph map, tags type |
| K2 | Markdown pipeline, editor, secret guard | one renderer, normaliser, editor screens, copy button, save guard, optimistic lock |
| K3 | Versions and restore | immutable version rows, restore semantics, purge/redact |
| K4 | Image attachments | upload, re-encode, EXIF strip, authorised serving, orphan cleanup |
| K5 | Search | text search config, generated tsvector, query builder, snippets, scoped SQL |
| K6 | Special sections and sharing | Incidents hub, Retrospectives button, per-article client share switch, Partner view, AI-friendly format |

**Top 6 cross-cutting findings the roadmap must respect**

1. **Laravel's `Str::markdown()` is unsafe by default** (`html_input = allow`, `allow_unsafe_links = true`, verified in `vendor/league/commonmark` 2.10.7). The repo's existing `RichText` sanitiser drops `img`, so a knowledge renderer cannot simply reuse it. One dedicated renderer, with escape mode, no unsafe links, a sanitiser pass on the output, and an internal-only image scheme, must exist before any article view ships (K2).
2. **The client-share switch is the last thing to enable, not the first thing to build.** The visibility seam (column, DB CHECK, Policy, global scope, audit declarations) is built in K1 with Partner = no access. Search, versions, attachments, tags, folder names, breadcrumbs, incident sub-pages and retrospectives each add a new leak channel; the switch goes live in K6 only after each channel has a canary test.
3. **Postgres has no Czech text search configuration and the managed database cannot take dictionary files** (ispell files are not shipped, the docs page says so; Czech snowball is not documented as available). Plan for `simple` + `unaccent` + prefix queries + trigram on titles from day one; do not plan on "Czech stemming" **[verify on the Zerops database service]**.
4. **`ts_headline` output is not HTML-safe** (PostgreSQL 18 manual, explicit XSS warning). Snippets must be produced with sentinel markers, escaped, then re-marked.
5. **Secrets leak into five places, not one**: the article body, the version rows, the activity log diff, the search vector/snippets, and screenshots. A save-time guard on the body alone fixes one of five.
6. **Retrospectives contain measured time**, which a Partner must never see. The time overview must be rendered live for Admin from `time_entries` and never stored in the Markdown body that can be shared, and `retrospective` rows must be unshareable by a CHECK constraint.

---

## Critical Pitfalls

### Pitfall 1: Markdown rendered with library defaults (stored XSS, javascript: links, tracking images)

**What goes wrong:**
`Str::markdown($body)` and `->markdown()` call `GithubFlavoredMarkdownConverter` with the CommonMark defaults: raw HTML passes through (`html_input = allow`) and `javascript:`, `data:` and `vbscript:` URLs are kept (`allow_unsafe_links = true`). An article that contains `<img src=x onerror=...>` or `[click](javascript:...)` becomes stored XSS in the Admin session (full panel takeover, Stripe/invoice data) and, once shared, in every Partner session. Three adjacent failures:
- `![](https://external.example.com/pixel.png)` in a shared article is a tracking pixel and leaks the Partner's IP and reading time to a third party; with an `http://` URL it is also mixed content.
- Filament's own `MarkdownEditor` preview runs `markdown()->sanitizeHtml()` through Filament's **globally bound** sanitiser config, which (per the existing `RichText` doc comment) allows `style`, `class` and `data-*`. The editor preview therefore differs from the production view: something looks fine in the preview and is stripped in the view (or the reverse, a preview-only injection surface).
- The existing `App\Domain\Shared\Text\RichText` sanitiser drops `img` entirely by design. Reusing it for articles silently deletes every image; "fixing" that by allowing `img` generally re-opens the external-image problem.

**Why it happens:**
The default converter works in a demo. Escaping vs stripping is not obvious: `html_input = strip` silently deletes placeholders like `<hostname>` or `<your-token>` from technical prose, so developers flip back to `allow`.

**How to avoid:**
- One `KnowledgeMarkdown` renderer class, the only place a body becomes HTML. Options: `html_input => 'escape'` (not `strip`: tech articles are full of `<placeholder>` text and escape keeps it visible), `allow_unsafe_links => false`, GFM tables and task lists only, heading ids generated by the renderer (never from user text unescaped).
- Run the converter output through a Symfony `HtmlSanitizer` with an allowlist built for this renderer (do **not** touch Filament's global config, same approach `RichText` already takes). Allow `img` only with `src` matching the internal attachment route/scheme; no `style`, `class`, `on*`, `iframe`, `svg`, `form`.
- Images in Markdown use an internal reference, for example `![alt](kokpit-attachment:<uuid>)`, which the renderer resolves to the authorised route. A pasted `https://` image URL is rendered as a plain link or a visible "external image blocked" placeholder.
- Turn off Filament preview or feed it the same renderer. Never use `{!! !!}` with a body; wrap the renderer output in `HtmlString` in exactly one place.
- Render on read, store only the Markdown source. Do not cache rendered HTML without keying it on the renderer version (a sanitiser fix must take effect on old articles).
- Add a hostile-corpus Pest test: a fixed list of XSS payloads (raw HTML, `javascript:` and `data:` links, entity-encoded schemes, reference-style links, autolinks, image `onerror`, SVG `<use>`) must render with no executable attribute; run it against the final HTML with a DOM parser, not a regex.
- Send a CSP header on the panel (at minimum `img-src 'self' data:` for article views) as defence in depth **[verify Filament/Livewire compatibility with the chosen CSP]**.

**Warning signs:**
`{!!` or `->toHtmlString()` near a body field; `Str::markdown(` without options anywhere in `app/`; an `<img>` in article HTML whose `src` is not an internal route; two different render paths (editor preview vs view vs PDF/e-mail).

**Phase to address:** K2 (renderer + hostile-corpus test). K6 re-verifies it in the Partner view.

---

### Pitfall 2: Secret guard that only scans the body, only by regex, and only once

**What goes wrong:**
The guard is added to the "Save" action and the team considers the requirement done. Real leak paths remain:
1. **Version rows**: a secret saved once (warn-and-save path, or guard bypass) lives in `knowledge_article_versions` forever, even after the body is cleaned. Restore can bring it back.
2. **Activity log**: `spatie/laravel-activitylog` with `logAll()`/`logDirty()` stores the old and new `body` in `activity_log.properties`. That is a second and third copy, readable by anyone with log access, and not covered by the guard or by purge.
3. **Search**: the `tsvector` and `ts_headline` snippets expose the secret in the results list (and to the Partner if the article is shared).
4. **Logs and error trackers**: a Livewire validation exception, a failed job payload or an error report can contain the request state including the whole body. The "your text contains a secret" message itself can echo the match.
5. **Screenshots**: image attachments cannot be scanned; terminal and dashboard screenshots are the most common place a token sits in plain sight.
6. **Other fields**: title, tags, folder names, image alt text, retrospective notes. The guard runs on the body field only.
7. **Other entry points**: the future API/MCP, a seeder, a console command, a restore action, a duplicate action, the incident/retrospective generators. A guard in a Filament page hook is bypassed by all of them.

Also the false-positive side: high-entropy heuristics flag UUIDs (every record in this app has one), git SHAs, hashes, base64 fixtures and sample JWT-looking placeholders; a guard that blocks all of these is turned off within a week or trains the Admin to always click "save anyway".

**Why it happens:**
Secret detection is treated as a form validation rule. The data model fans the body out to several stores that were added for other reasons (history, audit, search).

**How to avoid:**
- Put the guard in a domain Action (`SaveArticle`, `RestoreVersion`, `CreateRetrospective`, ...), the single write seam, not in a Filament hook. An arch test asserts no code outside the Action assigns `body`.
- Two severities: **block** for unambiguous material (private key blocks, URLs with embedded credentials, tokens with well-known vendor prefixes, Stripe-style secret keys, `Authorization: Bearer` lines with a long value) and **warn + auto-flag sensitive** for ambiguous material (internal IP addresses, private hostnames, `password:`-style assignments, long entropy strings outside code fences matching UUID/SHA shapes). Warn requires an explicit acknowledgment that is recorded without the value (rule name, line number, masked 4 characters) and sets `sensitive = true`.
- Mask in every message: show rule name and line number, never the matched text. Never put the body in the exception context. Add `body` to the Livewire/Sentry scrub list **[verify the project's Sentry/scrubbing config]**.
- Activity log: log only metadata (`title`, `folder_id`, `client_visible`, `sensitive`, version number). `body` is excluded from `logOnly` and from `logAttributesToIgnore`; a test asserts no `activity_log` row ever contains the body of a saved article.
- Reuse the **rule list** of `scripts/check-sensitive.sh` / gitleaks as the starting vocabulary, but keep a separate runtime ruleset: the repository rule bans *any* real hostname or IP in git, while the Admin's private knowledge base legitimately contains their own infrastructure. The runtime guard's job is to classify and flag, not to forbid.
- Provide a **purge/redact version** Admin action (versions are otherwise immutable, see Pitfall 7) and say in the UI that rotating the leaked secret is the real remedy, matching the repository's own "rotate first" policy.
- Image attachments: show a one-line notice at upload ("screenshots may contain tokens") and let the Admin mark the article sensitive; do not promise OCR.
- Guard tests build their fake secrets at runtime from fragments (repo rule: no single line of a test looks like a real value; the pre-commit scanner and gitleaks will otherwise reject the test file). Allowlist entries need owner approval (project memory), so design the tests to need none.

**Warning signs:**
`activity_log.properties` containing `body`; the guard referenced only from a `Page` class; error pages or logs quoting article text; a "save anyway" button with no recorded acknowledgment; tests with token-shaped literals.

**Phase to address:** K2 (guard, masking, activity-log scoping), K3 (restore passes through the guard, purge action), K5 (snippets mask sensitive articles), K4 (screenshot notice).

---

### Pitfall 3: Client (Partner) leaks through the side channels, not the article page

**What goes wrong:**
The article show page is policy-protected and the team ships. The leaks come from everything *around* the article:

| Channel | Leak |
|---------|------|
| Search results | rows, snippets, counts or "N results" for articles the Partner cannot open; ranking applied before filtering in PHP so pagination totals reveal hidden hits |
| Folder tree / breadcrumb | the folder or ancestor names of a visible article (folder named after another client) |
| Tags | Spatie tags are a global table; tag autocomplete or tag filter lists every tag, including those on internal articles and on other clients' articles; knowledge tags mixed with the existing client tags |
| Version history | the Partner opens an old version of a shared article, which was written before it was cleaned up or before sharing was granted; or still sees history after sharing is revoked |
| Attachments | image URL works for anyone who knows or guesses the media id; presigned URL stays valid after the share is revoked; the image of an unshared article is reachable from a shared article's version |
| Inline links | a link or embed to another article renders the target's title for a viewer who cannot open it |
| Incident hub / sub-pages | sharing the hub makes all sub-pages visible, including the internal post-mortem sub-page; or the reverse, sub-pages hidden from the listing but reachable by search and direct URL |
| Retrospectives | time overview, rates, billed/unbilled state in the body (see Pitfall 11) |
| Client/Project pages | a new RelationManager "Knowledge" on the Client or Project resource lists articles without the visibility filter |
| Activity log, notifications, Filament global search | titles of hidden articles in an activity feed or a global search result |
| Moved article | an article shared with client A is relinked to client B and the grant silently carries over |

**Why it happens:**
The existing v1 audit protects *known* screens. Every new model and route is a new unknown surface; knowledge features add several query paths (raw SQL for search is the big one: `DB::select` / `whereRaw` bypass Eloquent global scopes).

**How to avoid:**
- K1 builds the seam before any feature: `client_visible boolean not null default false`, `sensitive boolean not null default false`, DB `CHECK (NOT (client_visible AND sensitive))`, DB `CHECK (kind <> 'retrospective' OR NOT client_visible)`. A Partner sees an article only if `client_visible AND client_id = their client` (and, for a project link, the project is itself client-visible; compute through the existing project visibility rule, not a copy).
- The model gets the existing `PartnerIsolated` + `IsolatesPartners` (fail-closed `PartnerScope`) from day one. Every raw SQL path (search) is a declared escape hatch in the repo's `EscapeHatchScanner` and **applies the same visibility predicate in the same statement** (`WHERE ... AND <visible>` inside the query that ranks, counts and paginates).
- Visibility is **per article, never inherited**: not from a folder, not from a hub. For a sub-page the rule is AND (own flag AND hub flag). Folder names and the tree are not shown to the Partner at all; the Partner sees a flat list with the article's own title.
- Tags: a dedicated Spatie tag `type` for knowledge, and the Partner never gets a tag list or tag autocomplete; tag chips on a visible article show only that article's tags.
- Versions and history: no Partner access in v1.1 (Policy `viewHistory` = Admin only, tested); a Partner cannot restore or edit. Make Partner **read-only** for the whole milestone (any Partner write path means stored-XSS from the client side and prompt injection into AI context).
- Attachments are served only through an authorised controller that checks the article's *current* visibility on every request (no public URLs, no long-lived presigned URLs; if presigned, keep expiry at a few minutes). Revoking the share or setting `sensitive` is one transaction and takes effect immediately.
- Relinking `client_id` resets `client_visible` to false in the same Action.
- Extend the audit harness in K1, not K6: register new models in `ModelDeclaration`, new routes in `RouteRules` (keyed by route name), the new Filament Resource/pages/RelationManagers in `PanelInventory`, a `Canary` article per visibility state (private, shared-own-client, shared-other-client, sensitive, incident sub-page, retrospective), and add every channel in the table above to `ChannelMatrix`/`PartnerMatrix`. The permission matrix stays a hand-written literal, as decided in Phase 12, and the owner reviews it.
- Set `$isGloballySearchable = false` on the Knowledge Resource (own search only; Filament global search does ILIKE over searchable attributes and runs outside the panel's own query).

**Warning signs:**
`DB::select`/`whereRaw` over `knowledge_*` without the visibility predicate; PHP-side `->filter()` after a query; a Resource without `getEloquentQuery()` scoping; a RelationManager on Client/Project showing articles; tag list endpoints; any route returning a media file by id alone.

**Phase to address:** K1 (seam, constraints, declarations, canaries), then each of K3/K4/K5/K6 adds its channel rows to the matrix **in the same PR** as the feature. K6 turns the share switch on.

---

### Pitfall 4: Search snippets rendered as HTML (`ts_headline` XSS) and snippet leakage

**What goes wrong:**
`ts_headline('kokpit_cs', body, query)` wraps hits in `<b>..</b>` and returns the rest of the text unescaped. Rendering it with `{!! !!}` makes article text executable in the results list. The PostgreSQL 18 manual states the output is not safe for direct inclusion in web pages and is not an XSS defence. Rendering it escaped instead shows literal `<b>` tags. Further snippet problems: the snippet comes from the Markdown *source*, so it is full of fences, pipes and `#`; it can contain a secret that the article's `sensitive` flag was supposed to protect; and calling `ts_headline` over thousands of large bodies makes search slow.

**How to avoid:**
- Call `ts_headline` with `StartSel`/`StopSel` set to sentinel characters that cannot occur in text (for example `U+E000`/`U+E001`), then `e()` the whole string, then replace the sentinels with `<mark>`/`</mark>`. Never emit raw `ts_headline` output.
- Compute headlines only for the page of results being shown (outer query over the top N ids), with `MaxWords`/`MinWords` limits and `MaxFragments`, never in the ranking subquery.
- For `sensitive` articles show no snippet (title only), and for any article mask snippet lines matched by the secret guard rules.
- Build the headline input from stripped text, not raw Markdown, if the generated column for search already holds the stripped text (see Pitfall 5).

**Warning signs:**
`{!! $row->headline !!}`; `ts_headline` inside the same query as `ORDER BY ts_rank` over the whole table; a snippet showing code-fence backticks.

**Phase to address:** K5.

---

### Pitfall 5: PostgreSQL full-text search for Czech built on assumptions that do not hold

**What goes wrong:**
- `to_tsvector('czech', ...)` fails: there is no built-in `czech` text search configuration (the manual does not list one; ispell/hunspell dictionaries are **not shipped** and must be placed in `$SHAREDIR/tsearch_data`, which a managed database service normally does not allow) **[verify on the Zerops PostgreSQL service]**. Using `english` "because it works" stems Czech words into garbage and drops Czech words as English stopwords.
- Plain `simple` finds `příběh` only when typed with diacritics, while the Admin types without them. The repo already solved this for substring search (`kokpit_search_text` = `lower(public.unaccent(...))` with GIN trigram indexes), but a tsvector needs its own handling.
- Czech is heavily inflected (`server`, `serveru`, `serverem`, `serverů`). Exact lexeme matching with `simple` misses most inflections. Prefix queries (`server:*`) recover most of them at the cost of some noise.
- The tsvector expression in a generated column or index must be IMMUTABLE. `to_tsvector(regconfig, text)` with a literal config is immutable; the one-argument form and a bare `unaccent()` are not. The repo's migration documents exactly this trap for `unaccent`.
- `to_tsquery` raises a syntax error on user input such as `a & | b` or a stray quote (HTTP 500 on search). `websearch_to_tsquery` never raises but does **not** support prefix `:*`.
- A single `tsvector` is limited to about 1 MB and a lexeme to 2046 bytes **[verify]**. A pasted log, a base64 blob or a 2 MB Markdown dump makes the INSERT fail with "string is too long for tsvector", which surfaces as a failed save of the article.
- Code-heavy content pollutes the index: IPs, hashes, long paths and flags become lexemes; `to_tsvector` splits `/etc/nginx/nginx.conf` and URLs into multiple token types. Searching `nginx.conf` or an exact command may not behave as the user expects.
- Changing the text search configuration mapping later does not rewrite existing vectors; stale vectors silently stop matching.
- GIN `fastupdate` pending list makes a freshly saved article appear in search a moment later under heavy writes; irrelevant at this scale but tests that save-then-search can be flaky.

**How to avoid:**
- Create one project configuration in a migration: `CREATE TEXT SEARCH CONFIGURATION public.kokpit_cs (COPY = simple)` then `ALTER ... ALTER MAPPING FOR asciiword, word, numword, asciihword, hword, numhword, hword_asciipart, hword_part, hword_numpart WITH unaccent, simple`. This keeps indexing immutable and diacritic-insensitive. Reuse the already-installed `unaccent` and `pg_trgm` extensions.
- Store search text in a **generated stored column** (or maintained by the single Action) such as `search_vector = setweight(to_tsvector('public.kokpit_cs'::regconfig, left(title, ...)), 'A') || setweight(tags text, 'B') || setweight(to_tsvector(..., left(stripped_body, 500000)), 'C')`. Cap the body at a hard application limit (for example 200 KB, validated with a field error) so the vector can never overflow.
- Build the tsquery yourself: lowercase, split on non-word characters, drop empties, join with `&`, append `:*` to each token, bind as a parameter; only then `to_tsquery`. Unit-test hostile input (`"`, `&`, `|`, `!`, `<->`, `(`, `\`, empty string, one 5000-character token).
- Add `pg_trgm` GIN on the **title only** (substring and typo tolerance, "find the article called ..."), mirroring the existing `{table}_{column}_search_index` convention. Do **not** trigram-index the body (index size is several times the text).
- Test search with `EXPLAIN` like the existing accent-insensitive test does, and add a regression test that each new search column has its index.
- Keep a Czech-quality test corpus (fictional articles with inflected forms and diacritics) with expected hits; this is the acceptance test for "search feels right".
- If the Admin later wants real Czech stemming, treat it as a separate phase: hunspell dictionary licence (AGPL compatibility) and managed-DB file access are both unresolved.

**Warning signs:**
`'czech'` or `'english'` string literal in a migration; `to_tsquery(:q)` with raw user input; an index expression that calls one-argument `unaccent`; search returning 500 on a quote; "no results" for a word typed without diacritics.

**Phase to address:** K5 (config, generated column, query builder, corpus test). K1 only reserves the columns.

---

### Pitfall 6: Folder tree integrity (cycles, concurrent moves, duplicates under NULL, delete cascades)

**What goes wrong:**
- **Cycle**: moving folder A under its own descendant produces a loop; the tree rendering or breadcrumb recursion never terminates (or the recursive CTE runs until memory). A UI check in the move action passes in a single request but two concurrent moves (A under B, B under A) each pass and together form a cycle.
- **Duplicate names at root**: a unique index on `(parent_id, name)` does not protect root siblings because `NULL`s are distinct (PostgreSQL 18 supports `NULLS NOT DISTINCT`, but only if you ask for it).
- **Delete**: `ON DELETE CASCADE` on `parent_id` deletes a whole subtree of articles, versions and attachment rows in one click. DB-level cascades bypass Eloquent events, so Spatie media rows (morph, no FK) and their S3 objects are orphaned, and `taggables` rows stay behind. The opposite choice, `RESTRICT` with no UX, leaves the Admin unable to delete anything.
- **Depth/size**: unbounded depth makes breadcrumb and tree queries slow and the UI unusable.
- **Ordering**: sibling order with `spatie/eloquent-sortable` on UUID keys under concurrent drag/drop produces duplicate positions.
- **URLs built from names or paths** break on rename/move; folder names in URLs also leak (Pitfall 3).
- A folder and an article tree needs one decision: are folders their own table, or is a folder a kind of article? Mixed models double the integrity logic.

**How to avoid:**
- Separate `knowledge_folders` (self-referencing `parent_id uuid` FK) from articles (`folder_id` FK, nullable for root). Moves and deletes go through Actions only.
- Prevent cycles in the **database**: a `BEFORE INSERT OR UPDATE OF parent_id` trigger that walks ancestors (recursive CTE) and raises on a cycle or on depth > N (for example 8), taking a transaction-level advisory lock for the whole tree so concurrent moves serialise (a single company, a single tree: a global advisory lock is cheap). The repo already ships "child guard" trigger functions as a pattern. Also keep the app-level check for a friendly message.
- Unique sibling name: `CREATE UNIQUE INDEX ... ON knowledge_folders (parent_id, lower(name)) NULLS NOT DISTINCT` (or two partial indexes) and the same for the root.
- Delete rule: refuse to delete a non-empty folder (`ON DELETE RESTRICT`) and offer "move contents to parent, then delete"; articles are soft-deleted with a retention window, never cascaded. If hard delete of an article is allowed, it runs an Action that deletes media (and S3 objects), tag attachments and versions explicitly, and an orphan-detector test asserts no media row points at a missing article (the repo already has morph/orphan arch-test habits).
- URLs use the article UUID only; the slug, if shown, is cosmetic and ignored on lookup.
- Folder `sort_order` uses a gap-tolerant integer assigned under the same advisory lock, not eloquent-sortable's read-modify-write.
- Tree render uses one recursive CTE or an eager-loaded parent map, never per-node queries; the tree is small (hundreds of nodes) so no closure table is needed, **but** document why so nobody adds one.

**Warning signs:**
`parent_id` update without a lock; a test that moves a folder under its child passing only because the UI hides the option; media or taggables rows with no owner after a delete; root folder with two identical names in dev data.

**Phase to address:** K1 (schema, trigger, constraints, Actions, orphan test).

---

### Pitfall 7: Version history that bloats and a restore that destroys or resurrects

**What goes wrong:**
- **Bloat**: a full copy of the body per save, including no-op saves, or per Livewire update/autosave, multiplies storage. For one company this is not a terabyte problem, but a few hundred articles saved dozens of times each, with large code-heavy bodies, grows quickly, makes backups bigger, and, worse, versions end up in the list query or the search index.
- **Versions in the hot row/query**: the article list `SELECT *` pulls the TOASTed body (and with a `versions` eager load, all bodies) just to render a table.
- **Version numbering race**: two saves compute `max(version)+1` and collide or leave gaps.
- **Mutable history**: versions that can be edited or deleted by code paths other than purge are not history.
- **Restore semantics** are undefined: restore by rewriting the current row loses the "before restore" state; restore of body only leaves title/folder/tags changed; restore reintroduces a secret that was cleaned later (Pitfall 2) or a link to an image that was deleted since; restore of an article whose folder was deleted; restore by a Partner or on a retrospective's generated section.
- **Double storage**: the version body plus the activity-log diff (Pitfall 2).

**How to avoid:**
- Separate table `knowledge_article_versions` (`article_id`, `number`, `title`, `body`, `body_sha256`, `created_by`, `created_at`, `reason`). Unique `(article_id, number)`; the Action takes `SELECT ... FOR UPDATE` on the article row, increments `lock_version` and writes the version in the same transaction (this is also the optimistic lock, Pitfall 8).
- Do not write a version if `body_sha256` and title are unchanged. Coalesce rapid successive saves by the same user within a short window (for example 10 minutes) into one version, except when restore, share change or sensitive change happens. No autosave versions.
- Version rows are **immutable** (DB trigger like the project's other immutability guards), purge/redact is a separate Admin Action that blanks the body, keeps the row and records who/why in the activity log (without the content).
- **Restore = create a new version** whose body/title equal the old one; history is append-only and the pre-restore state stays reachable. The restore Action re-runs the secret guard and normaliser, lets the Admin choose "body only" (default) and does not change folder, tags, `client_visible` or `sensitive` (those are current state, not content). If the restored text contains a rule match, restore is blocked or flagged exactly like a save.
- Attachments referenced by an old version must still exist. Either attachments are never hard-deleted while any version references them, or the renderer shows an explicit "image removed" placeholder instead of a broken image. Decide this in K4 and write it down.
- Retention: keep everything for the first release, add a `knowledge:prune-versions` command (keep the latest N plus one per day for 30 days) behind a setting only if size measurably matters; document the expected size. A diff-based storage format is **not** worth the complexity at this scale.
- List and tree queries select explicit columns without `body`; add a test with a query counter and an assertion on the selected columns for the index page.
- Versions are excluded from the search vector and from Partner access (Pitfall 3).

**Warning signs:**
Versions created on every Livewire `updated` hook; `max(number) + 1` in application code without a lock; `Article::with('versions')` in a list; restore implemented as `$article->update($version->only(...))`.

**Phase to address:** K3 (table, Action, restore semantics, purge); K1 reserves `lock_version`.

---

### Pitfall 8: Lost updates between two tabs, devices and the restore button

**What goes wrong:**
There is a single Admin, so "concurrency" looks irrelevant. In practice the Admin has the edit screen open in two tabs, on a laptop and a phone, or leaves an edit screen open for hours; a Livewire form holds the body loaded at mount time. Saving from the stale tab overwrites newer text silently (last write wins). A restore performed while an edit screen is open has the same effect. Related: the session expires during a long edit and the save fails with a 419; the form content is lost.

Also: the "create retrospective" button and "create incident hub" button double-clicked create two rows.

**How to avoid:**
- Optimistic locking: article carries `lock_version integer not null default 1`; the edit form posts the version it loaded; the save Action runs `UPDATE ... WHERE id = ? AND lock_version = ?` (or compares under `FOR UPDATE`) and on mismatch aborts with a Czech message and offers "reload" and "show differences"; it never merges silently. The restore Action uses the same check.
- Separate **view** and **edit** screens (already the milestone design) reduces accidental editing; keep the edit screen free of auto-refresh/polling that would overwrite typed text.
- Warn on leaving with unsaved changes (`beforeunload`), and keep the session alive while the edit screen is open **[verify Filament SPA mode behaviour with the session lifetime]**. Persist an unsaved draft in browser storage only if a lost-edit incident motivates it; do not build server drafts in this milestone.
- Create-once actions rely on DB uniqueness, not on UI disabling: `UNIQUE (project_id) WHERE kind = 'retrospective'`, and an idempotent Action (`firstOrCreate` semantics returning the existing row with a notice).
- Use `Livewire` `#[Locked]` on the article id and authorise in each action, not only in `mount()` (Livewire state is client-tamperable).

**Warning signs:**
No `lock_version`/`updated_at` comparison in the save Action; a test "two saves in a row" passing because it runs in one process; duplicate retrospective rows in dev.

**Phase to address:** K2 (optimistic lock in the Action), K3 (restore uses it), K6 (create-once constraints).

---

### Pitfall 9: Pasted Markdown artefacts (whole page inside one fence) and a normaliser that damages code

**What goes wrong:**
- Content pasted from a chat assistant or another tool arrives wrapped in a single outer fence (```` ```markdown ... ``` ````) so the entire article renders as one grey code block with no headings.
- Content that itself contains triple-backtick blocks, wrapped in an outer triple-backtick fence, terminates early: the first inner fence closes the outer one and the remainder renders as Markdown, then a later fence opens a block that swallows the rest of the page to the end.
- Unclosed fence: everything after it becomes code.
- Invisible characters from web copy and chat tools: NBSP, zero-width space, BOM, smart quotes and en/em dashes inside commands (a copied `--flag` becomes `–flag` and fails when pasted into a terminal), `\r\n` line endings, tabs, trailing spaces that create accidental hard breaks.
- Raw HTML from a rich-text copy (`<br>`, `<details>`, `<aside>`) now renders as literal text because of `html_input = escape` (Pitfall 1).
- Shell prompt prefixes (`$ `, `> `) inside code blocks: the **copy button** copies the prompt too and the command fails. Line numbers rendered as text instead of CSS pollute the copy.
- The **normaliser itself** is the bigger risk: a "helpful" cleaner that rewrites quotes or whitespace *inside* a fenced block silently corrupts the code the user wants to copy exactly.
- The copy button uses `navigator.clipboard`, which only exists in a secure context; and copying `innerHTML` instead of `textContent` copies entities.

**How to avoid:**
- Define "canonical Markdown" in one short spec (LF endings, UTF-8 without BOM, ATX headings, `-` bullets, fenced code only, `~~~`/longer fences when the content contains backticks, one blank line between blocks, info string on every fence).
- A conservative, **idempotent** normaliser run by the save Action: (a) normalise line endings and strip BOM; (b) if the *entire* document is one fence whose info string is `markdown`/`md`/empty, unwrap it (with a notice shown to the Admin); (c) detect an unbalanced fence and **warn**, not auto-fix; (d) outside code only: strip zero-width characters and convert NBSP to space, trim trailing whitespace. **Inside fences and inline code: line endings only**, never anything else. Property tests: `normalize(normalize(x)) === normalize(x)`, and "all fenced block contents are byte-identical before and after" over a corpus.
- Parse with the same CommonMark parser to find fences (do not use regex on backticks); use the AST to know what is code.
- Copy button: read `textContent` of the `<code>` element, with a fallback to `document.execCommand('copy')` when the Clipboard API is unavailable; render line numbers via CSS counters only; do not auto-add or auto-strip prompts (document a convention instead). Add a feature test on the rendered HTML that a code block contains exactly the source text.
- The editor shows a visible warning chip when the guard/normaliser changed or flagged something, so the change is never silent.

**Warning signs:**
An article whose rendered view has one single `<pre>` element; code blocks that render differently after a save/load round trip; `str_replace` of quotes on the whole body; clipboard code using `innerText`.

**Phase to address:** K2 (spec, normaliser, property tests, copy button).

---

### Pitfall 10: Image uploads (SVG, EXIF, bombs, public URLs)

**What goes wrong:**
- **SVG**: `spatie/laravel-medialibrary` in this repo registers `Svg` as an image generator and `Svgo` as an optimiser. An uploaded SVG served from the app origin executes script (stored XSS) and can reference external resources. "Image-only" validated by file extension or client MIME type lets `evil.svg`, an HTML file renamed `.png`, or a GIF/JS polyglot through.
- **EXIF**: phone and screenshot tools embed GPS coordinates, device model, timestamps. A shared article with such an image leaks the Admin's location to the client. Orientation tag handling: stripping EXIF *without* applying orientation first makes photos appear rotated.
- **Decompression bombs / huge dimensions**: a 40000x40000 palette PNG is tiny on disk and exhausts memory in the conversion job (queue worker OOM, retries, stuck queue).
- **Filename and path**: user-supplied name used as storage key or in `Content-Disposition` (header injection, path tricks).
- **Public or default disk**: Filament's `MarkdownEditor` file attachments are **always `public`** (verified: `fileAttachmentsVisibility()` throws, the getter returns `'public'`), so enabling the toolbar attach button stores images publicly, outside the Documents policy. The existing `TaskResource` already disables it for the same reason.
- **Reusing the Documents domain**: images would show up in the central documents listing, ZIP download and bulk delete, and in the Partner's Documents view for a client-visible project.
- **Orphans**: images attached but later removed from the text stay in storage; deleted articles leave media rows (morph, no FK).
- **Pasted screenshots** with secrets (Pitfall 2).

**How to avoid:**
- Allowlist **by content**, not extension: `finfo`/`getimagesize` detected type in {jpeg, png, webp, gif (maybe avif)}; reject SVG and everything else. Remove `Svg` from the knowledge collection's accepted types and use `acceptsMimeTypes()` plus a server-side sniff.
- Always **re-encode** (Intervention/GD/Imagick) to a fresh file: this kills EXIF/XMP/ICC junk and polyglot payloads; call orientation correction first, then strip. Cap pixel dimensions (for example 8000x8000) *before* decoding (read the header with `getimagesize`) and cap bytes (for example 8 MB); set queue memory limits for conversions **[verify Imagick/GD availability in DDEV and on Zerops]**.
- Own media collection `knowledge-images` on the private disk, UUID-based storage names, never user filenames; serve through an authorised controller that streams or issues a 2-5 minute presigned URL after checking current visibility, with `X-Content-Type-Options: nosniff`, an explicit `Content-Type` from the re-encoded type, `Content-Disposition: inline` with a sanitised name, and `Content-Security-Policy: sandbox` on the response.
- `->fileAttachments(false)` on the editor; images are added through a dedicated upload action that inserts the internal reference `kokpit-attachment:<uuid>` (Pitfall 1). Keep the Documents domain out of it.
- Ownership: the media `model_type` is the article via the morph map; the Action that deletes an article deletes media in the same step; a scheduled `knowledge:prune-orphan-images` job removes images that no current version references, **after** the version-reference decision from Pitfall 7.
- Add tests: SVG rejected, HTML-as-PNG rejected, GPS EXIF stripped (assert re-encoded file has no EXIF segment), oversized dimensions rejected, anonymous request to image URL is 302/403/404, Partner request for an unshared article's image is 404.

**Warning signs:**
`image/svg+xml` accepted anywhere in the knowledge code; image URLs containing a bucket host; `toMediaCollection` with `usingFileName($request->file()->getClientOriginalName())`; image rows visible in the Documents screen.

**Phase to address:** K4 (all of it); K1 reserves the morph map entry and the tags/media uuid morph columns.

---

### Pitfall 11: Retrospectives and incidents: time, rates and hidden sub-pages

**What goes wrong:**
- A retrospective "created by a button" with a time overview from tracked time **stores** that overview in the Markdown body. Then: it is searchable and snippet-able; it can be shared with the client by flipping the switch (measured time is exactly what a Partner must never see); it is copied into versions and the activity log; and it becomes stale the moment more time is logged or entries are billed/edited.
- Time overview mistakes: including running timers (no end), counting subtask time twice when rolling up to the parent task, mixing billed and unbilled without labelling, rounding (the project rule is integer seconds, never rounded), showing rates or money (never in a retrospective), ignoring soft-deleted tasks, using local rather than UTC boundaries for "period".
- Regenerate-on-click **overwrites Admin edits** to the retrospective text.
- The button creates duplicates (Pitfall 8) or silently fails when the project has no time.
- **Incident hub**: sub-pages are "hidden from the listing but searchable". Implemented as a global scope on the model, "hidden" also hides them from search, from the Partner scope's counting, from the policy lookup and from admin tools (cannot open them by id). Or implemented as a UI-only filter, so the sub-pages show in the folder tree and under Partner lists. When the hub is deleted or moved, sub-pages become orphans. A shared hub reveals sub-pages (Pitfall 3).
- `kind` (article / incident_hub / incident_page / retrospective) changes after creation and breaks assumptions in policies and queries.

**How to avoid:**
- Retrospective = a normal article row with `kind = 'retrospective'`, `project_id` NOT NULL, unique per project, **never shareable** (CHECK, Pitfall 3), and two parts: a stored Markdown body for the Admin's text (fixed headings generated once) and the **time overview rendered live** from `time_entries` by a query class, shown only on the Admin view screen (Policy-gated) and not part of body, search vector, versions or AI export. If a frozen snapshot is a hard requirement later, store it in a separate Admin-only table, not in the body.
- Overview query: only stopped entries, per task and per user as durations in integer seconds, subtasks counted once at their own row, billed/unbilled labelled, no rates/prices/currency columns selected at all (select an explicit column list so a future refactor cannot leak them), period in `Europe/Prague` converted to UTC bounds. Unit tests include a running timer, a billed entry, a subtask and a soft-deleted task.
- The button is idempotent (`firstOrCreate` on `project_id`); "regenerate" only recreates the live overview, never touches the Admin's text.
- Incident model: `kind` plus `parent_article_id` (hub) with CHECKs: sub-page requires a hub parent of kind `incident_hub`; hub has no parent; `kind` immutable (trigger, as for invoices). "Hidden from listing" is an explicit **list-query filter** (`whereNull('parent_article_id')` in the listing query class), not a global scope; search and direct URL do not use it. Deleting a hub requires deleting or moving its sub-pages first (RESTRICT).
- Sub-page visibility to a Partner = own flag AND hub flag (Pitfall 3); tests cover sub-page of a shared hub that is itself unshared.

**Warning signs:**
A table of hours inside the stored body; `->select('*')` from `time_entries` in the overview; a global scope on incident sub-pages; no `UNIQUE` for retrospectives; a "regenerate" that calls `update(['body' => ...])`.

**Phase to address:** K6 (build); K1 (CHECK constraints and `kind` immutability trigger go into the first migration so later phases cannot forget them).

---

### Pitfall 12: Sensitive flag that is cosmetic, and exclusion that depends on every future caller remembering

**What goes wrong:**
`sensitive` is added as a boolean and honoured by the Partner view, but not by search snippets, the AI-friendly export, notifications, or the future API/MCP. The milestone explicitly prepares an "AI-friendly storage format" for a later API/MCP milestone; the later milestone reads `knowledge_articles` directly and ships sensitive content to a model provider. Other holes: new articles default to shared or non-sensitive without a prompt; un-flagging happens by editing a field with no audit trail; flagging an article that is currently shared leaves the share active; versions of a sensitive article are not themselves treated as sensitive; the guard auto-flags an article but a later edit that removes the match does not un-flag it (correct) while a later import or restore does reset it (wrong).

Also **prompt injection**: article text is untrusted data once fed to a model; if Partner can write articles (not planned) or a pasted web page contains instructions, the AI consumer may follow them.

**How to avoid:**
- Defaults fail closed: `client_visible = false`, new articles from the guard path `sensitive = true` when a warn rule fired.
- One **query seam for machine/outsider consumption**: a single class (and optionally a DB view `knowledge_exportable`) returning only `NOT sensitive` rows plus an explicit allowlist of columns; the AI export, any future API resource and any feed read only from it; an arch test forbids `KnowledgeArticle::` queries in `app/Domain/Api` and in the export namespace outside the seam. Put this in the K6 format spec so the later milestone inherits it.
- The AI-friendly format (for example Markdown file per article with a small YAML front matter: `id`, `title`, `kind`, `folder_path`, `tags`, `client_ref`, `updated_at`, `format_version`) carries no sensitive articles, no retrospective time data, no attachments binaries, and has a `format_version` so it can evolve. `client_ref` uses the client's UUID or key, not name data that is not needed.
- Setting `sensitive = true` is one transaction that also sets `client_visible = false`, revokes outstanding attachment URLs (they are per-request authorised, Pitfall 10) and logs the change. Clearing it requires an explicit Admin action with a reason in the activity log.
- Treat any article text handed to a model as data (document this rule in the format spec; instruct consumers to delimit it).

**Warning signs:**
A second place that filters `where('sensitive', false)` by hand; export code that selects from the table directly; default `true` for `client_visible` in a factory.

**Phase to address:** K1 (columns, CHECK, defaults), K6 (seam, format spec, arch test).

---

## Moderate Pitfalls

### Pitfall 13: Filament specifics that bite this feature
**What goes wrong:** The editor value travels in every Livewire request; `->live()` on a large body makes typing laggy and balloons payloads. A Resource without `getEloquentQuery()` scoping relies solely on the model scope. A table column `->searchable()` on `body` runs ILIKE over a large TOAST column and is unindexed. `$isGloballySearchable` left true. Separate view and edit pages share a form schema, so a "view" page accidentally has editable state. Notifications and breadcrumbs include titles of hidden articles.
**Prevention:** `->live(onBlur: true)` or none; table columns searchable only on `title`; custom Livewire search page, not table search, for body search; `$isGloballySearchable = false`; the view page is an Infolist rendering the single renderer; authorise in each page and action; Livewire properties for ids are `#[Locked]`. **Phase:** K2, K5.

### Pitfall 14: Tags shared across features
**What goes wrong:** `spatie/laravel-tags` is global; the existing client tags and the new knowledge tags mix, translatable-name JSON and slug collisions appear, tag counts show other clients' tag names to a Partner, and `taggables` has no FK so deleting an article leaves rows.
**Prevention:** dedicated `type = 'knowledge'`; explicit tag management only for Admin; delete tags through the same Action as the article; `taggables.taggable_id` is uuid (existing convention, add to the arch test); show tags on the Partner view only for the visible article. **Phase:** K1.

### Pitfall 15: Morph map, UUID and arch tests fail late
**What goes wrong:** New models are not in `MorphMap::MAP` (`enforceMorphMap` throws at runtime on the first media/tag/activity write), `HasUuids` missing on the new package-model subclasses, `uuid` morph columns. The repository's catalog-introspecting arch tests catch this, but only after the feature is written.
**Prevention:** first K1 task = migration + model + morph map + declarations so the red arch tests drive the rest. **Phase:** K1.

### Pitfall 16: Search page does too much work per request
**What goes wrong:** `ts_headline` and `ts_rank` over every match; counting with `COUNT(*)` over the same expensive expression; loading bodies for the list; recursive tree queries per node; N+1 on tags, folder paths and client names.
**Prevention:** two-step query (ids + rank on the indexed column with `LIMIT 50`, then headline for the page); `simplePaginate` or a capped total; select explicit columns; eager-load tags and folder path with one query; a query-count test on the list and search pages. **Phase:** K5.

### Pitfall 17: Article-to-article links
**What goes wrong:** relative-path links break on move/rename; links by title leak a hidden target's title to a Partner; a link to a deleted article is a 404 with no explanation.
**Prevention:** internal links use the UUID (`kokpit-article:<uuid>` resolved by the renderer); the renderer shows the link text only (never the target title) and a neutral "no access" state when the viewer cannot open it; deleting leaves a visible "removed" state. **Phase:** K2 (renderer), K6 (Partner checks).

### Pitfall 18: Client/project link and share switch semantics drift
**What goes wrong:** project link without client link, article linked to a project of client A but `client_id` of client B, switch turned on with no client, project made non-client-visible later while the article stays shared, client account deactivated.
**Prevention:** DB CHECK `client_visible => client_id IS NOT NULL`; `client_id` derived from the project when `project_id` is set (single source, composite FK `(project_id, client_id)` if the existing schema allows) **[verify against the projects table]**; the Partner predicate re-checks the project's visibility at read time; changing the project's client or visibility triggers the same reset Action as relinking. **Phase:** K1 (constraints), K6 (switch).

---

## Minor Pitfalls

### Pitfall 19: Czech UI and canonical English
**What goes wrong:** user-facing strings hard-coded, enum labels, Czech collation in sorting folders and tags (`ch` sorts after `h`), diacritics lost in generated slugs.
**Prevention:** all UI text in `lang/cs`; sort with an explicit ICU/`cs-CZ-x-icu` collation or by `sort_order`, and verify the database locale **[verify]**; do not build URLs from names. **Phase:** K1/K2.

### Pitfall 20: Repository hygiene in tests, seeders, docs and screenshots
**What goes wrong:** a developer pastes a real article to produce a realistic fixture; a demo seeder runs in production; a secret-guard test contains a token-shaped literal and trips the scanner; documentation screenshots show real folder names; planning docs quote structure from a real workspace.
**Prevention:** fixtures and factories use a fictional corpus ("Acme Studio s.r.o.", `example.com`, company ID `12345678`) written from scratch; guard test secrets are assembled from fragments at runtime; any importer, if it is ever added, ships **only** with a fictional input fixture and the real export directory lives outside the repository (and its path is never committed); the demo seeder is guarded by `ProductionConfigGuard`; run `scripts/check-sensitive.sh` on every new planning and test file; never bypass the hook or edit the allowlist without owner approval. **Phase:** every phase, a standing review item.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| `Str::markdown($body)` directly in a Blade view | one line | stored XSS; two render paths diverge | Never |
| Reuse `RichText` sanitiser for articles | already exists | images dropped, then "fixed" by loosening it for the whole app | Never; build a sibling sanitiser |
| Filament `MarkdownEditor` attachments enabled | free image upload button | public disk, bypasses policy, EXIF, SVG | Never |
| Body stored only in the article row, versions added "later" | faster K2 | cannot recover history; restore semantics designed under pressure | Never: version table in K3 but `lock_version` and Action seam in K2 |
| `english` or no text search config | search works today | wrong stemming, hard reindex later | Never; use `kokpit_cs` from the first migration |
| Search with `ILIKE '%q%'` on body only | no FTS work | sequential scans, no ranking, no snippets | Only as a temporary internal tool, never behind the Partner view |
| Visibility enforced in the Resource query only | fast | raw SQL search, API, export bypass it | Never; model global scope + DB CHECK + Policy |
| Full body copy per save, no dedupe | trivial | storage growth | Acceptable in K3 if dedupe by hash and a coalescing window exist |
| Closure table / `ltree` for the tree | scalable ancestors | extra moving parts for a few hundred nodes | Not needed; revisit above ~10k nodes |
| Skip optimistic locking "single user" | less code | silent lost edits with two tabs | Never |
| `sensitive` as a UI hint only | quick | AI/API milestone leaks it | Never; query seam from K6 |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| league/commonmark (via Laravel) | default options | escape HTML, no unsafe links, sanitiser pass, internal image scheme |
| Filament `MarkdownEditor` | enabling attachments; trusting its preview | `fileAttachments(false)`; preview through the same renderer or off |
| Filament global search | leaving knowledge resource searchable | `$isGloballySearchable = false`; own search page |
| `spatie/laravel-medialibrary` | default collection, SVG generator, user filename, conversions on huge images | private disk collection, content sniff + re-encode, dimension cap, UUID names |
| `spatie/laravel-activitylog` | `logAll()` storing body diffs | log metadata only, test that no body is stored |
| `spatie/laravel-tags` | global tag list shared with clients | dedicated tag type; Admin-only tag management |
| `spatie/eloquent-sortable` | read-modify-write ordering under concurrency | `sort_order` assigned under the tree advisory lock |
| PostgreSQL FTS | `to_tsquery` on raw input; no Czech config; unescaped `ts_headline` | own query builder; `kokpit_cs`; sentinel markers + escape |
| PostgreSQL `unaccent` | calling the one-argument (STABLE) form in an index/generated column | schema-qualified dictionary inside a text search configuration or the existing `kokpit_search_text` wrapper |
| S3-compatible storage | presigned URLs with long expiry; orphaned objects after DB cascade | per-request authorisation, 2-5 minute URLs, Actions that delete objects |
| Sentry / logging | request state with body in exception context | scrub `body`, never put matches in messages |
| Existing audit harness | adding models/routes without declarations | declare in K1; canary per visibility state |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| `ts_headline` for every match | slow search page | headline only for the shown page | about 1k articles with large bodies |
| Trigram GIN on `body` | index several times table size, slow writes | trigram on title only, tsvector for body | about 500 large articles |
| `SELECT *` incl. body in lists | slow index page, memory | explicit columns, `defer` | about 200 articles |
| Eager loading all versions | huge payloads | count with `withCount`, paginate versions | a few dozen versions per article |
| Per-node tree queries | N+1 on the tree sidebar | one recursive CTE / one query + in-memory build | about 100 folders |
| Livewire `live()` on body | laggy typing, large payloads | `onBlur` or none | bodies above about 50 KB |
| Image conversions in a worker with no memory cap | queue worker OOM, retry loop | pixel/byte caps before decode, `--memory` | a single huge upload |
| Re-indexing tsvector in the web request for bulk operations | timeouts | generated column; bulk operations through a job | bulk moves/imports of hundreds |
| tsvector size overflow | save fails with "string too long" | cap body length and `left()` in the expression | about 1 MB of text |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Raw HTML / unsafe links in Markdown | stored XSS in Admin and Partner sessions (HIGH) | Pitfall 1 |
| `ts_headline` output unescaped | XSS in search results (HIGH) | Pitfall 4 |
| Secret in body, versions, activity log, snippets | credential disclosure to Partners, logs, AI (HIGH) | Pitfall 2 |
| Search/tag/folder/version/attachment endpoints without visibility predicate | client data leak across clients (HIGH) | Pitfall 3 |
| Retrospective with time in a shareable body | measured time disclosed to Partner (HIGH) | Pitfall 11 |
| SVG or polyglot upload served inline | stored XSS, content sniffing (HIGH) | Pitfall 10 |
| EXIF GPS kept in shared images | location disclosure (MEDIUM) | re-encode, strip |
| External image URLs rendered | tracking, IP leak, mixed content (MEDIUM) | block external images in renderer, CSP |
| Public or long-lived attachment URLs | access after revoke (MEDIUM-HIGH) | authorised streaming or short presigned URLs |
| Partner write access to articles | stored XSS from client side, prompt injection (HIGH) | Partner is read-only in v1.1 |
| Share inherited from folder/hub | silent over-sharing (HIGH) | per-article flag, AND rule for sub-pages |
| Livewire id tampering on edit/restore | unauthorised action (MEDIUM) | `#[Locked]`, authorise per action |
| Sensitive articles in the AI/API path | secrets to a model provider (HIGH, later milestone) | single export seam, arch test |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Guard blocks with a vague message | Admin cannot tell what to fix | rule name + line number, masked, with "mark sensitive and save" |
| Guard cries wolf on UUIDs and hashes | alert fatigue, "save anyway" habit | tuned rules, code-fence aware, separate block vs warn |
| Silent normalisation | unexpected edits to text | visible notice of what changed (unwrapped fence, stripped characters) |
| Restore replaces current text with no undo | fear of using history | restore creates a new version, diff before confirm |
| Edit conflict loses the typed text | rage | show conflict, keep the user's text in the form, offer reload and diff |
| Search finds nothing for unaccented input or inflected forms | search feels broken | unaccent + prefix + title trigram; corpus test |
| Hidden incident sub-pages with no way to find them | pages appear lost | breadcrumb + hub page lists its sub-pages; search finds them |
| Share switch with no explanation | accidental sharing | confirm dialog naming the client and listing what the client will see; badge on shared articles |
| Copy button copies prompt or line numbers | commands fail | plain text copy, CSS line numbers |
| Deleting a folder deletes everything | data loss | refuse non-empty, move-then-delete |

## "Looks Done But Isn't" Checklist

- [ ] **Markdown view:** often missing hostile-corpus test and escape mode, verify raw `<script>`, `javascript:` and external image render inert in the final DOM.
- [ ] **Editor preview:** often differs from the view, verify one renderer or preview disabled.
- [ ] **Secret guard:** often only on the form, verify the same guard runs in restore, duplicate, retrospective create and any console/API path, and that activity log contains no body.
- [ ] **Sensitive flag:** often cosmetic, verify excluded from Partner view, snippets, export seam; share is revoked in the same transaction.
- [ ] **Partner isolation:** often missing the side channels, verify canary rows for each channel (search, folder, tag, version, attachment, link, sub-page, retrospective, relation managers) and the hand-written matrix reviewed by the owner.
- [ ] **Search:** often works only with diacritics, verify unaccented, inflected, hostile-input and empty-query cases; `EXPLAIN` uses the GIN index.
- [ ] **Snippets:** verify sentinel escape and no snippet for sensitive articles.
- [ ] **Tree:** verify DB-level cycle prevention under two concurrent moves, root duplicate names, non-empty delete refusal, no media orphans.
- [ ] **Versions:** verify unique `(article_id, number)` under concurrent saves, no-op save creates no version, restore creates a new version, purge keeps the row.
- [ ] **Attachments:** verify SVG/HTML-as-PNG rejected, EXIF stripped, dimension cap, private serving, revoke takes effect immediately, orphan cleanup.
- [ ] **Copy button:** verify copied text equals the source of the fence, with a non-secure-context fallback.
- [ ] **Retrospective:** verify no time in stored body, unique per project, regenerate does not overwrite text, running timers excluded, no money columns selected.
- [ ] **Incidents:** verify sub-pages hidden from the list, found by search, orphan handling on hub delete, AND rule for Partner.
- [ ] **Hygiene:** verify no real data in fixtures, `scripts/check-sensitive.sh` clean on all new files, no allowlist change without owner approval.
- [ ] **Migrations:** run only after merge into HEAD branch, not in a worktree; the text search configuration migration is reversible and tested on a fresh database.

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| XSS payload found in stored articles | MEDIUM | fix renderer (sanitiser pass takes effect on read since only source is stored), find affected rows by scanning bodies, rotate sessions |
| Secret saved into body/versions/log | HIGH | rotate the secret first (repo policy), purge/redact versions, clean activity log rows, reindex search vector; check backups |
| Share leak discovered | HIGH | revoke all `client_visible` in one migration, add the missing canary, notify owner (client data disclosure) |
| tsvector built with wrong config | LOW-MEDIUM | new config migration + `UPDATE ... SET body = body` (regenerate column) or `REINDEX`, in a queued job |
| Folder cycle already in data | MEDIUM | recursive CTE to find, detach node to root, add the trigger |
| Version bloat | LOW | prune command, `VACUUM FULL` off-hours |
| Orphan media / S3 objects | LOW | scheduled orphan scan; reconcile against bucket listing |
| Lost edit | MEDIUM | restore from the previous version if saved; otherwise unrecoverable, so lock first |
| Duplicate retrospectives | LOW | merge manually, add unique index |
| Image with EXIF already shared | MEDIUM | re-encode existing media in a job, invalidate caches/URLs |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| 1 Markdown XSS / unsafe links | K2 | hostile-corpus test on final DOM; arch test forbids raw markdown calls |
| 2 Secret guard and its five leak paths | K2, K3, K5 | tests: activity log has no body, masked messages, guard in every Action, restore guarded |
| 3 Partner side-channel leaks | K1 seam, K3-K6 per channel | canaries and hand-written matrix entries per channel; raw SQL declared as escape hatch |
| 4 `ts_headline` XSS | K5 | test with `<script>` in a body; snippet escaped; no snippet for sensitive |
| 5 Czech FTS | K5 | corpus test (diacritics, inflections, hostile input), EXPLAIN uses GIN, size-cap test |
| 6 Tree integrity | K1 | concurrent-move test (two connections), duplicate root name, delete refusal, no orphans |
| 7 Versions and restore | K3 | numbering race test, dedupe, restore-as-new-version, purge, no body in list query |
| 8 Lost updates and double-create | K2, K3, K6 | stale-form save rejected; unique retrospective constraint |
| 9 Pasted artefacts, normaliser | K2 | idempotence and code-byte-identical property tests; outer-fence unwrap test |
| 10 Image upload risks | K4 | SVG, polyglot, EXIF, dimension, private URL tests |
| 11 Retrospective and incident design | K6 (constraints in K1) | CHECKs reject shared retrospective; overview query unit tests; hub delete restricted |
| 12 Sensitive exclusion and AI seam | K1 columns, K6 seam | arch test on export seam; share revoked on flag |
| 13 Filament specifics | K2, K5 | `isGloballySearchable` false test; page authorisation tests |
| 14 Tags | K1 | tag type test; Partner sees no tag list |
| 15 Morph/UUID/arch | K1 | existing arch suite green |
| 16 Search performance | K5 | query-count and EXPLAIN tests |
| 17 Article links | K2, K6 | renderer test: no hidden title leak |
| 18 Client/project link semantics | K1, K6 | CHECK and reset-on-relink tests |
| 19 Czech UI and collation | K1, K2 | lang coverage test, sort test |
| 20 Repository hygiene | all | `scripts/check-sensitive.sh`, gitleaks, CI scan, owner review of fixtures |

**Research flags for the roadmapper:**
- K5 (search) and K4 (images) deserve a short phase-level research spike: the Czech config behaviour on the real managed PostgreSQL and the image library available on Zerops are **[verify]** items.
- K1, K2 and K3 follow patterns already used in this repository (scope, Actions, immutability triggers, escape-hatch scanning); standard planning is enough.
- K6 needs the owner's decision on the share semantics (per-article flag, AND rule for sub-pages, Partner read-only), the retrospective overview contents and the AI export format before planning.

## Sources

- Repository (verified directly): `vendor/league/commonmark` 2.10.7 `Environment.php` defaults (`html_input` allow, `allow_unsafe_links` true); `vendor/laravel/framework` `Str::markdown`; `vendor/filament/forms` `MarkdownEditor` (attachments always public, preview via `sanitizeHtml()`); `app/Domain/Shared/Text/RichText.php`; `app/Filament/Resources/TaskResource.php` (`fileAttachments(false)` rationale); `database/migrations/2026_10_30_000100_add_accent_insensitive_search.php`; `app/Domain/Shared/Auth/IsolatesPartners.php`; `tests/Support` audit harness; `.planning/PROJECT.md`; `.claude/CLAUDE.md` repository hygiene rules.
- PostgreSQL 18 manual, text search dictionaries (ispell files not shipped, unaccent as filtering dictionary): https://www.postgresql.org/docs/18/textsearch-dictionaries.html
- PostgreSQL 18 manual, text search controls (`ts_headline` not safe for web pages, `websearch_to_tsquery` never raises syntax errors and has no prefix support): https://www.postgresql.org/docs/18/textsearch-controls.html
- Training knowledge, tagged **[verify]** above: tsvector 1 MB / 2046-byte limits, absence of a Czech snowball configuration, `NULLS NOT DISTINCT` behaviour, Imagick/GD availability on the deploy target, Filament SPA session behaviour.

---
*Pitfalls research for: Knowledge Center added to an existing Laravel/Filament CRM with Partner isolation (milestone v1.1)*
*Researched: 2026-11-25*
