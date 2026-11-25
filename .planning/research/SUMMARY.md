# Project Research Summary

**Project:** Kokpit, milestone v1.1 Knowledge Center
**Domain:** Internal knowledge base (Markdown articles, incident post-mortems, project retrospectives) inside an existing single-company CRM/ERP (Laravel 13, Filament 5.12 SPA panel, Livewire 4, PHP 8.5, PostgreSQL 18, Redis queue, S3-compatible private bucket, AGPL-3.0)
**Researched:** 2026-11-25
**Confidence:** MEDIUM-HIGH

All examples are fictional (`example.com`, `Example Ltd`, company ID `12345678`). No content, title or link from any external workspace was read or used.

## Executive Summary

Kokpit v1.1 adds a Markdown knowledge store next to the existing CRM objects: general articles in an Admin-only folder tree, incident hubs with hidden searchable sub-pages, and one retrospective per project created from a button. The Markdown source is the only stored truth, HTML is rendered per request through one hardened renderer, every save is an append-only full snapshot, and search uses the database's own full-text engine. No new service is needed: PostgreSQL 18 with `pg_trgm` and `unaccent` is installed, and the Markdown, sanitiser and attachment libraries are already in `vendor/`.

The approach has three parts. First, one `KnowledgeMarkdown` renderer (CommonMark with `html_input = escape`, no unsafe links, GFM tables and task lists, a Kokpit-owned HTML sanitiser pass, a small own callout extension) is the only place a body becomes HTML. Second, one write seam (`SaveArticle` and siblings) enforces the optimistic version check, the secret guard, the version row and the metadata-only activity log in one transaction. Third, Partner isolation is enforced by the model's fail-closed scope, a Policy and database CHECK constraints, never by hiding UI. Search uses a custom `kokpit_cs` text-search configuration (`simple` plus `unaccent`, prefix queries, title trigram) because PostgreSQL ships no Czech stemmer.

Main risks: stored XSS through Markdown or `ts_headline` snippets; Partner side-channel leaks (search, folder names, tags, version history, image URLs, sub-pages, retrospective time); secrets reaching several stores (body, versions, activity log, search index and snippets, screenshots); weak Czech search quality without dictionary files; retrospective measured time leaking into shareable text. The Partner share switch is deliberately enabled last.

## Key Findings

### Recommended Stack

Small Composer additions, zero npm packages, two own components (a CommonMark callout extension and a `SecretScanner`).

- `league/commonmark` ^2.10 (promote to direct): Markdown to HTML, AST access for callouts and task lists.
- `symfony/html-sanitizer` ^8.1 (promote to direct): defence-in-depth pass with a Kokpit-owned config; not Filament's global config (allows `style`, truncates long input).
- `tempest/highlight` ^2.30: server-side highlighting, pure PHP. Optional (P3).
- `jfcherng/php-diff` ^7.0: version diff. Optional (P2); fallback `sebastian/diff` already in the lock.
- `symfony/yaml` ^8.1 (move from require-dev): YAML front matter for the AI-friendly export.
- Folder tree: own `parent_id` plus recursive CTE (recommended) or `staudenmeir/laravel-adjacency-list`; decide in phase 1.
- Already installed and reused: `unaccent`, `pg_trgm` (via `kokpit_search_text()`), `spatie/laravel-medialibrary`, `spatie/laravel-tags` (dedicated type `knowledge`), `spatie/laravel-activitylog` (metadata only, never title or body).
- Filament `MarkdownEditor` (EasyMDE) with `fileAttachments(false)`: no usable built-in preview; its attachments are always public, so images go through an own upload action.

Database: text-search configuration `public.kokpit_cs` (`COPY = simple`, `unaccent, simple` for word tokens); generated STORED `tsvector` (title A, body B) with GIN index; GIN trigram indexes; `UNIQUE ... NULLS NOT DISTINCT` on `(parent_id, name)` for folders.

Do not use: `Str::markdown()` / `TextEntry::markdown()` defaults; the `RichText` sanitiser for articles; Filament `RichEditor`; Node-based highlighters; Scout/Meilisearch/pgvector; public or long-lived image URLs; one-argument `unaccent()` in indexes; raw input to `to_tsquery`; third-party secret-scanner packages.

### Expected Features

**Must have (table stakes):**
- Article = title plus canonical Markdown body; separate view and edit screens; safe rendering of tables, task lists, autolinks; fenced code with language and copy button.
- Nested folder tree (Admin-only): move, rename, depth cap, refuse to delete a non-empty folder.
- Tags on a dedicated type; link to a client and/or project.
- Per-article client-visible switch, effective only with a client link; Partner gets a flat read-only list; visible only if the linked project is also client-visible.
- Image-only attachments, private storage, authorised delivery, EXIF stripping, orphan cleanup.
- Version history with view and non-destructive restore; Admin only.
- Own full-text search: diacritics- and case-insensitive, prefix match, snippets on the visible page, filters, same visibility rules as listings, hidden incident sub-pages searchable.
- Secret guard on every save path including restore; message names rule and line, never the value; Admin override logged without the body.
- Incidents: index table; hub with metadata (occurred-at with timezone, severity, status), causes marked hypothesis or confirmed, timeline, actions, follow-ups; free-form sub-pages hidden from listings.
- Retrospective: project button, one per project, four fixed sections, time overview from tracked time, Admin only.
- Menu group "Znalosti"; Czech UI strings in `lang/cs`; optimistic concurrency; soft delete.
- AI-readable serialisation (front matter plus untouched Markdown) and "Copy as Markdown for AI".

**Should have (v1.1.x):** overdue follow-up badge; incident numbers; follow-up to task; version diff; Markdown ZIP export; linked incidents on client and project pages; starter templates.

**Defer (v2+):** API and MCP; embeddings; backlinks; Czech Hunspell stemming; client-facing incident summaries.

**Anti-features:** real-time co-editing; WYSIWYG/block editor storing HTML; general file attachments; secrets vault; folder-level permissions; public share links; Partner editing; sharing incidents or retrospectives with Partner in v1.1; approval workflows; server-side autosave; Filament global search for knowledge; any "person at fault" field; two-way task sync; profitability figures in retrospectives.

### Architecture Approach

New bounded folder `app/Domain/Knowledge/` (Actions, Enums, Models, Policies, Queries, Guard, Support). Writes go through Actions (`SaveArticle`, `RestoreVersion`, `MoveFolder`, `CreateIncidentPage`, `CreateRetrospective`, `AttachImage`, `ArchiveArticle`) that a later API/MCP layer will also call.

1. `KnowledgeArticle`: one table for all kinds (`general`, `incident`, `incident_page`, `retrospective`); canonical `body_md`, `version_no`, client/project link, client-visible flag, `search_vector`.
2. `KnowledgeFolder`: Admin-only adjacency list; cycle guard trigger; depth cap in `MoveFolder`.
3. `KnowledgeArticleVersion`: append-only full snapshot; `UNIQUE (article_id, version_no)`; numbers allocated under a row lock; Admin only.
4. `KnowledgeImage`: owns one medialibrary row on the private disk; Partner-visible exactly when its article is; signed authorised controller.
5. `RetrospectiveTimeOverview`: Admin-only 1:1 table, integer seconds, no rates or money; never in the body.
6. `SaveArticle` + `SecretScanner`, `MarkdownRenderer`, `KnowledgeSearch`, `ArticleDocument` (AI export), `FolderTree`.
7. Access layer extended: `PartnerIsolated`, `DeniesPartners`, `KnowledgeArticlePolicy` (view only for Partner), `#[AccessRule]`, morph aliases, `TagType::Knowledge`, `ModelDeclarationTest`, `PartnerMatrix`, `RouteRules`.

Key patterns: one write path; Markdown canonical, HTML derived, images as `kokpit-image:<uuid>` tokens; Admin-only half in a separate table; sub-pages carry no access columns and inherit the hub's access; one scoped search query class; images are not `Document` rows.

### Critical Pitfalls

1. Markdown XSS and unsafe links: `html_input => escape`, `allow_unsafe_links => false`, dedicated sanitiser, one renderer everywhere, hostile-corpus test.
2. Secret guard with several leak paths: guard inside the Action, metadata-only logs, masked messages, runtime-built test fixtures, redaction path for versions.
3. Partner side-channel leaks: fail-closed scope from phase 1, DB CHECKs, canary per visibility state, matrix entry per channel in the same PR; sub-page visibility = own flag AND hub flag.
4. `ts_headline` is not HTML-safe: sentinel characters, escape, then replace with `<mark>`.
5. Czech full-text assumptions: `kokpit_cs`, tsquery built in PHP, body length cap, fictional Czech corpus as acceptance test.
6. Folder tree integrity: cycle trigger with advisory lock, depth cap, no cascading deletes.
7. Versions and restore: numbers from the locked row, no version on no-op save, restore creates a new version and re-runs the guard.
8. Lost updates and double creates: locked `version_no`, partial unique index per project for retrospectives.
9. Pasted-content artefacts: idempotent normaliser, never touches fenced content.
10. Image uploads: content sniffing, reject SVG, re-encode, caps, UUID names, authorised streaming, orphan job.
11. Retrospective and incident design: no time in any stored body; running timers excluded; no money columns; `kind` immutable.
12. Sensitive flag and export seam: fail closed; sensitive revokes sharing; one export seam excluding sensitive rows.

## Implications for Roadmap

Suggested phase order (schema and seam first, guard before content, sharing last):

1. **Data model and access seam:** migrations for all kinds, folders with cycle trigger, versions table with append-only trigger, morph map, `TagType::Knowledge`, models with isolation traits, policies, declarations, canary articles, language keys.
2. **Core article, renderer, secret guard, save path:** `SaveArticle`, `SecretScanner`, renderer and sanitiser, callouts, copy buttons, normaliser, Admin resource "Obecné znalosti" with separate list/create/view/edit pages, server-rendered preview, archive, concurrency and hostile-corpus tests.
3. **Organisation and version history:** folder tree UI, tags, client/project link, "Verze" tab, `RestoreVersion`, redaction action, retention decision.
4. **Image attachments:** `knowledge_images`, own upload action, sniffing and re-encoding, signed controller, CSP, orphan job.
5. **Incidents and retrospectives:** incident hub and hidden sub-pages, metadata, causes, timeline, follow-ups; retrospective button, fixed template, Admin-only time overview.
6. **Own full-text search:** `kokpit_cs`, generated vector, GIN and trigram indexes, scoped `KnowledgeSearch`, sentinel snippets, search page outside global search, leak canaries.
7. **Client sharing, Partner view, AI-friendly export:** share switch with confirmation, `PartnerKnowledgeResource`, relation managers, matrix and route rules, `ArticleDocument` export, "Copy as Markdown for AI".

### Research Flags

Spikes needed: renderer and sanitiser config and cursor image insertion (2); image library on deploy target and CSP (4); nested Filament resource for sub-pages (5); `kokpit_cs` rights on managed PostgreSQL, generated-column immutability, `ts_headline` on accents (6); owner decisions before 7.

Standard patterns: phases 1 and 3 follow existing conventions.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH (versions, licences); MEDIUM (PostgreSQL config, highlighter output) | Verified in `vendor/` and `composer.lock`; text-search behaviour must be proven on PG 18. |
| Features | MEDIUM | Established wiki and post-mortem conventions. |
| Architecture | HIGH (integration points); MEDIUM (nested resource, generated column, renderer) | Read from repository code. |
| Pitfalls | MEDIUM-HIGH | Verified against installed libraries; some items to verify in spikes. |

**Overall confidence:** MEDIUM-HIGH

### Conflicts between research files (resolved)

- Dependencies: promote `league/commonmark` and `symfony/html-sanitizer`; `tempest/highlight` and `jfcherng/php-diff` optional; folder tree via own recursive CTE unless the owner prefers the package.
- Markdown escaping: `escape`, not `strip`.
- Time in retrospectives: none in the body; Admin-only overview table with a refresh action.
- Secret guard: block unambiguous secrets; warn plus acknowledgment for ambiguous matches; override per save, logged without the body.
- Share flag naming: `client_visible` and `sensitive`.
- Incident kinds: one naming chosen in phase 1.
- Search configuration name: `kokpit_cs`.
- Sharing timing: Partner code may exist earlier behind the closed scope; the switch goes live only in phase 7.

### Gaps to Address

- Owner decisions: per-article switch and its AND rule with the project's client-visible flag; incidents and retrospectives never shared in v1.1; guard block-only versus block-plus-warn; version coalescing.
- Retrospective overview contents: live query versus refreshed snapshot table.
- Czech search quality: accept `kokpit_cs` for v1.1; Hunspell is a separate later decision.
- Managed PostgreSQL permissions and image library availability.
- Folder tree implementation and UUID behaviour.
- P2 items: decide at requirements time whether any enters v1.1.
- Retention of versions and orphan images.
- Filament SPA session behaviour on long edits; Sentry and log scrubbing of `body`.

## Sources

### Primary (HIGH confidence)
- Repository code and `vendor/` read 2026-11-25 (CommonMark defaults, Filament `MarkdownEditor`, `RichText` sanitiser, `KokpitModel`, `PartnerScope`, `TimeTotals`, `DocumentFileController`, accent-insensitive search migration, Partner audit harness).
- `composer.lock` and Packagist metadata.
- PostgreSQL 18 manual: text-search dictionaries and controls.

### Secondary (MEDIUM confidence)
- Tempest highlight upstream source (read, not executed).
- Public post-mortem and retrospective templates; wiki product comparisons.

### Tertiary (LOW confidence)
- Web search excerpts on secret-scanning libraries and CommonMark alert extensions.
- Training-knowledge limits to verify in spikes.

---
*Research completed: 2026-11-25*
*Ready for roadmap: yes*
