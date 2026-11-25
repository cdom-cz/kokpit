# Roadmap: Kokpit

## Milestones

- ✅ **v1** — Phases 1-17.1 (shipped 2026-10-30) — [archive](milestones/v1-ROADMAP.md)
- 🚧 **v1.1 Knowledge Center** — Phases 18-23 (in progress)

## Phases

**Phase Numbering:**
- Integer phases (18, 19, 20): Planned milestone work
- Decimal phases (18.1, 18.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

<details>
<summary>✅ v1 (Phases 1-17.1) — SHIPPED 2026-10-30</summary>

- [x] Phase 1: Repository Hygiene (11/11 plans) — completed 2026-10-08
- [x] Phase 2: Platform Foundation (13/13 plans) — completed 2026-10-08
- [x] Phase 3: Operations Foundation (19/19 plans) — completed 2026-10-08
- [x] Phase 4: Clients and Projects (21/21 plans) — completed 2026-10-09
- [x] Phase 5: Tasks and Kanban (22/22 plans) — completed 2026-10-09
- [x] Phase 6: Time Tracking (14/14 plans) — completed 2026-10-14
- [x] Phase 7: REST API (20/20 plans) — completed 2026-10-14
- [x] Phase 8: Exchange Rates and Reports (27/27 plans) — completed 2026-10-29
- [x] Phase 9: Documents and Finance (28/28 plans) — completed 2026-10-29
- [x] Phase 9.1: Transactions API (INSERTED)
- [x] Phase 10: Invoicing (41/41 plans) — completed 2026-10-29
- [x] Phase 10.1: Invoices stay fully editable until sent, with a manual unlock (INSERTED)
- [x] Phase 11: Stripe Payments (20/20 plans)
- [x] Phase 12: Partner Audit (9/9 plans)
- [x] Phase 13: Unified e-mail layout and HTML e-mail templates (9/9 plans)
- [x] Phase 14: Switchable payment method and independent invoice currency
- [x] Phase 15: Cash payment method for invoices and cash receipt documents
- [x] Phase 16: Per-client e-mail sending toggle
- [x] Phase 17: Task, project and search UX refinements
- [x] Phase 17.1: Close gap G1/G2, release time on full partial credit and reject payments on converted proformas (INSERTED)

Full phase details: [milestones/v1-ROADMAP.md](milestones/v1-ROADMAP.md). Requirements: [milestones/v1-REQUIREMENTS.md](milestones/v1-REQUIREMENTS.md). Audit: [milestones/v1-MILESTONE-AUDIT.md](milestones/v1-MILESTONE-AUDIT.md).

</details>

### 🚧 v1.1 Knowledge Center (In Progress)

**Milestone Goal:** A company "second brain" inside Kokpit: general and project/client knowledge, organised in folders and tags, versioned, full-text searchable and stored in a form that AI tools can read.

The milestone is built schema and write seam first, guard before content, sharing last. Phase 18 lays down the knowledge data model for every article kind, the fail-closed Partner access seam and the single save path (optimistic version check, secret guard, version row, metadata-only activity log in one transaction), and delivers the core Admin article on top of it. Phases 19 and 20 add organisation, version history and images to that article. Phase 21 builds the two special sections, incidents and retrospectives, as further article kinds. Phase 22 adds the own full-text search once every kind exists, so it covers incident sub-pages from the start. Phase 23 is the only phase that opens anything to a Partner, and it ships the AI-readable export through the same seam that excludes sensitive articles.

- [ ] **Phase 18: Knowledge Articles and Secret Guard** - Markdown articles with separate view and edit screens, a hardened renderer, server preview, archive, optimistic locking, immutable versions and a secret guard on every save
- [ ] **Phase 19: Folders, Tags and Version History** - Folder tree, knowledge tags, client and project links with Knowledge tabs, and version history with view, diff, restore and redaction
- [ ] **Phase 20: Image Attachments** - Image-only uploads on private storage, re-encoded without EXIF, served through an authorised route, with orphan cleanup that respects stored versions
- [ ] **Phase 21: Incidents and Retrospectives** - Numbered incident hubs with causes, timeline, follow-ups and hidden sub-pages, and one fixed-format retrospective per project with an Admin-only time overview
- [ ] **Phase 22: Knowledge Search** - Dedicated diacritics-insensitive full-text search with ranking, safe snippets, filters and the viewer's visibility rules
- [ ] **Phase 23: Client Sharing and AI Export** - Per-article client sharing, read-only Partner view proven by the Partner audit, and the canonical AI-readable export with Copy as Markdown for AI

**Mapping notes** (how the 45 v1.1 requirements were assigned, each to exactly one phase):

- Default rule: a requirement is traced to the phase that delivers the capability it describes, or, if it spans phases, to the phase where it first becomes fully observable.
- The research's suggested first phase (data model and access seam) carries no requirement of its own and cannot be verified alone, so it opens Phase 18: migrations for all article kinds, the folder and version tables with their integrity triggers, morph aliases, the dedicated knowledge tag type, models with the fail-closed Partner scope, policies, Partner matrix declarations, canary articles and `lang/cs` keys.
- VER-01 is traced to Phase 18 because every save writes the version row in the same transaction as the guard and the concurrency check (KB-08 depends on the version number). The history screen, view, diff, restore and redaction are Phase 19 (VER-02 to VER-05).
- SEC-01 covers saves in Phase 18; its restore clause is proven in Phase 19 by VER-03. The sensitive mark is set from Phase 18 (SEC-02); what it blocks, sharing and export, is traced to Phase 23 (SEC-04), where both exist.
- INC-03 is traced to Phase 21 (sub-pages exist and stay out of listings); the "but searchable" clause is proven in Phase 22 by SRCH-04.
- NAV-01 is traced to Phase 21, where the Knowledge group first holds all three entries. Phase 18 creates the group with General knowledge.
- SHARE-04 is traced to Phase 23, but Partner fail-closed holds from Phase 18 on: every phase declares each new screen, route, Livewire component and channel in the Partner permission matrix (`PartnerMatrix`, `RouteRules`) in the same pull request, as the v1 Partner audit (Phase 12) requires. Partner code may exist earlier behind the closed scope; the share switch goes live only in Phase 23.
- Cross-cutting: all Czech UI text lives in `lang/cs`; the activity log records metadata only, never a title or body; every fixture is fictional, and secret-guard fixtures are assembled at runtime from fragments.

**Research flags** (run research or a spike before planning): Phase 18 (renderer and sanitiser configuration, callout extension), Phase 20 (image library on the deploy target, CSP, image insertion at the editor cursor), Phase 21 (nested Filament resource for incident sub-pages), Phase 22 (`kokpit_cs` text-search configuration rights on the managed PostgreSQL, generated-column immutability, `ts_headline` with accents). Phase 23 needs owner decisions before planning (see STATE.md Blockers/Concerns).

## Phase Details

### Phase 18: Knowledge Articles and Secret Guard
**Goal**: Admin can write, read and maintain Markdown knowledge articles that render safely, while no save stores a secret unnoticed or silently overwrites a concurrent change
**Depends on**: Phase 17.1 (v1 shipped)
**Requirements**: KB-01, KB-02, KB-03, KB-04, KB-05, KB-06, KB-07, KB-08, SEC-01, SEC-02, SEC-03, VER-01
**Success Criteria** (what must be TRUE):
  1. From General knowledge in the new Knowledge menu group, Admin creates an article with a title and a Markdown body, opens it on its own view screen and changes it on a separate edit screen whose server-rendered preview matches the view screen; an archived article leaves the active list and can be restored
  2. The view screen renders tables, task lists, links, highlighted code and `> [!NOTE]` callouts as formatted blocks, every code block has a Copy button that copies its exact text, and a fictional hostile corpus (raw HTML, script tags, `javascript:` links) shows up as escaped text or dropped links, never as live markup
  3. Saving a body that contains an unambiguous secret (a fake credential assembled at runtime in the test) is refused with a message that names the rule and the line but never the value; an ambiguous finding saves only after explicit acknowledgment and marks the article sensitive, and that override covers the one save only and appears in the activity log without the content
  4. A save that changes the content raises the article's version number by one and keeps the content as an immutable version, a save without changes creates no version, and when two edit screens save from the same starting version the second save is rejected with a notice instead of overwriting the first
  5. A Partner account sees no Knowledge menu group and is refused on every knowledge screen and URL; the new screens and routes are declared in the Partner permission matrix and the Partner audit stays green
**Plans:** 10/10 plans executed (5 waves; every Pest run happens after the merge into the HEAD branch, one at a time against the shared `kokpit_test` database; after the wave 1 merge run `ddev composer install` first)

Plans:
**Wave 1**
- [x] 18-01-PLAN.md — Tracer: knowledge article, version and folder tables with their triggers, the three models, `ArticleKind`, morph aliases (wave 1)
- [x] 18-02-PLAN.md — Fail-closed Partner seam: Admin-only policies, `TagType::Knowledge`, canary, matrix and declaration rows, `KnowledgeFixtures` (wave 1, coupled with 18-01)
- [x] 18-03-PLAN.md — Hardened `KnowledgeMarkdown` renderer: link and image policy, sanitiser, code blocks with Copy chrome and highlighting, task lists, container binding (wave 1)
- [x] 18-04-PLAN.md — `SecretScanner`, `MarkdownNormaliser`, `SecretFixtures` and the complete `lang/cs/knowledge.php` (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 18-05-PLAN.md — `SaveArticle` single save path: versions, no-op, stale check, secret guard with one-save acknowledgment, concurrency proof (wave 2)
- [x] 18-06-PLAN.md — Callouts, prose stylesheet and copy script as Filament assets (wave 2)
- [x] 18-07-PLAN.md — Znalosti group, General knowledge list with the archived switch, view screen, Partner screen declarations (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 18-08-PLAN.md — Archive and restore Actions with metadata-only history rows, row and header actions, history relation manager (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*
- [x] 18-09-PLAN.md — Create screen on the shared form: server preview tab, D-07 toolbar with task-list button, leave warning, guard feedback (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*
- [x] 18-10-PLAN.md — Edit screen with stale-save notice, edit entry points, knowledge seam Arch test, CONTRIBUTING hand-over (wave 5)
**UI hint**: yes

### Phase 19: Folders, Tags and Version History
**Goal**: Admin can organise articles in a folder tree with tags and client or project links, and can inspect, compare, restore and redact earlier versions of any article
**Depends on**: Phase 18
**Requirements**: ORG-01, ORG-02, ORG-03, ORG-04, VER-02, VER-03, VER-04, VER-05
**Success Criteria** (what must be TRUE):
  1. Admin creates, renames and moves folders in a tree; moving a folder into its own subtree or beyond the depth cap is refused with an explanation, and deleting a folder that still holds articles or subfolders is refused
  2. Admin files an article in a folder and gives it knowledge tags that never show up among client tags (and client tags never show up as knowledge tags), and can browse the article list by folder and filter it by tag
  3. Admin links an article to a client, a project or both, and the client and project detail pages show a Knowledge tab listing the linked articles
  4. Admin opens an article's version history, views any earlier version read-only and sees the difference between two chosen versions
  5. Restoring an earlier version adds a new version on top (nothing in the history is lost) and passes the secret guard exactly like a save; Admin can redact a secret from a stored version so it no longer appears in that version's view, diff or restore, and the redaction is logged without the value
**Plans**: TBD
**UI hint**: yes

### Phase 20: Image Attachments
**Goal**: Admin can put screenshots and other images into an article while no image becomes publicly reachable or carries hidden metadata
**Depends on**: Phase 19
**Requirements**: IMG-01, IMG-02, IMG-03
**Success Criteria** (what must be TRUE):
  1. From the article editor Admin uploads a PNG, JPEG, WebP or GIF image and sees it inline in the preview and on the view screen; an SVG, a non-image file renamed to an image extension, or a file over the size cap is rejected with a message
  2. A stored image carries no EXIF data (a fictional test photo with a GPS position comes back without it) and is served only through an authorised route: a guest, a Partner without access to the article, or a stale signed link is refused, and no direct bucket URL appears in the page
  3. After an image is removed from an article body, the cleanup job deletes it once no stored version references it, while an image still referenced by an earlier version survives and shows when that version is viewed or restored
**Plans**: TBD
**UI hint**: yes

### Phase 21: Incidents and Retrospectives
**Goal**: Admin documents incidents as numbered hubs with a structured, blameless post-mortem and hidden sub-pages, and records one fixed-format retrospective per project with a time overview from tracked time, both visible to Admin only
**Depends on**: Phase 20
**Requirements**: INC-01, INC-02, INC-03, INC-04, INC-05, INC-06, RETRO-01, RETRO-02, RETRO-03, RETRO-04, NAV-01
**Success Criteria** (what must be TRUE):
  1. The Knowledge menu group lists General knowledge, Incidents and Retrospectives; Admin creates an incident that gets the next `INC-NNNN` number (no gap or duplicate under concurrent creation) and records the occurrence time with timezone, severity, status and affected system, all shown in the incident list
  2. On the incident hub Admin records causes marked hypothesis, confirmed or ruled out, a timeline, actions taken and follow-ups with due date and status, and turns a follow-up into a Kokpit task with one action; later changes to the task do not change the follow-up
  3. Admin adds overview, report, changes, runbook, monitoring and tasks sub-pages to an incident; they open from the hub but never appear in the article or incident lists
  4. The Create retrospective button on a project opens a new retrospective with the four fixed sections (description and output, what went well, what we learned, what to do better); pressing it again, even from two tabs at once, opens the same live retrospective instead of creating a second one
  5. The retrospective shows a time overview (worked, billed, unbilled, estimate vs actual) built from tracked time with a Refresh action, without rates, prices or amounts and with none of it in the Markdown body; a Partner, even of the project's own client, sees no incident, sub-page, retrospective or time overview anywhere
**Plans**: TBD
**UI hint**: yes

### Phase 22: Knowledge Search
**Goal**: Admin finds any piece of knowledge, including incident sub-pages and technical text, from one dedicated search page that tolerates missing diacritics and never shows a result the viewer may not see
**Depends on**: Phase 21
**Requirements**: SRCH-01, SRCH-02, SRCH-03, SRCH-04
**Success Criteria** (what must be TRUE):
  1. A Knowledge search page in the Knowledge group returns matching articles, while the Filament global search in the panel header shows no knowledge results
  2. On a fictional Czech corpus a query typed without diacritics or in a different case finds the accented text (`zlutoucky` finds `žluťoučký`), a word prefix finds the full word, and an article matching in the title ranks above one matching only in the body
  3. Results show highlighted snippets in which any markup from the article body appears escaped, never rendered, and the list can be filtered by kind, tag, folder, client and project
  4. Search finds incident sub-pages (which still stay out of every list) and text inside code blocks, file paths and shell commands, such as a fictional `/Users/example/` path segment or a command-line flag
  5. Results follow the viewer's visibility, proven with canary articles: no account gets a result, title or snippet from an article it cannot open, and the search page is declared in the Partner permission matrix
**Plans**: TBD
**UI hint**: yes

### Phase 23: Client Sharing and AI Export
**Goal**: Admin shares selected general articles with the client they belong to, a Partner reads exactly those and nothing else, and any non-sensitive article can be taken out as canonical AI-readable Markdown
**Depends on**: Phase 22
**Requirements**: SHARE-01, SHARE-02, SHARE-03, SHARE-04, SEC-04, AI-01, AI-02
**Success Criteria** (what must be TRUE):
  1. On a general article linked to a client, Admin turns on client sharing after confirming a dialog; the switch is not offered for an article without a client link, for incidents, sub-pages and retrospectives, or for a sensitive article, and marking a shared article sensitive withdraws the sharing
  2. A Partner sees a flat, read-only list of the shared articles of their own client, without folders, tags, versions or edit actions, and can open each one including its images; an article linked to a project appears only while that project is also visible to the client
  3. The Partner audit, extended with knowledge canaries for every visibility state, passes and proves that a Partner never sees a sensitive or unshared article, an incident, sub-page or retrospective, any version history, or another client's article through any screen, search, image, route or component
  4. Every non-sensitive article has a canonical AI-readable export of YAML front matter with its metadata followed by the original Markdown body unchanged, and the Copy as Markdown for AI action copies exactly that text
  5. A sensitive article is left out of every export: it offers no AI export and no Copy as Markdown for AI action, and requesting its export directly is refused
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 18 → 19 → 20 → 21 → 22 → 23

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 18. Knowledge Articles and Secret Guard | v1.1 | 10/10 | In Progress | - |
| 19. Folders, Tags and Version History | v1.1 | 0/TBD | Not started | - |
| 20. Image Attachments | v1.1 | 0/TBD | Not started | - |
| 21. Incidents and Retrospectives | v1.1 | 0/TBD | Not started | - |
| 22. Knowledge Search | v1.1 | 0/TBD | Not started | - |
| 23. Client Sharing and AI Export | v1.1 | 0/TBD | Not started | - |

v1 phases (1-17.1) are complete; their progress is in [milestones/v1-ROADMAP.md](milestones/v1-ROADMAP.md).

## Backlog

### Phase 999.1: API Timer (BACKLOG)

**Goal**: An API client starts and stops the timer and reads the currently running one, with the same rules as the UI (auto-stop of the running timer on start, one running timer per user) and idempotent start
**Requirements**: AP-03
**Origin**: Removed from Phase 7 on 2026-10-14 (see `.planning/phases/07-rest-api/07-CONTEXT.md`, deferred)
**Plans**: 0 plans
