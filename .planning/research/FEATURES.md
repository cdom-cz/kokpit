# Feature Research: Knowledge Center (milestone v1.1)

**Domain:** Internal wiki / runbook / post-mortem / retrospective tooling, embedded in a single-company CRM/ERP (Laravel + Filament 5, one Admin, Partner client accounts with a restricted view)
**Researched:** 2026-11-25
**Confidence:** MEDIUM overall.
- Wiki conventions (history with restore, hierarchy, search, per-space permissions): HIGH from product knowledge, corroborated by several vendor comparisons. Vendor blogs are weak on tags and folder depth, so those points are judgment.
- Post-mortem structure (summary, impact, timeline, cause analysis, what went well or badly, owned and dated action items): MEDIUM-HIGH, the same core appears in Atlassian, PagerDuty, incident.io, Rootly and others. The "do not use one root-cause field" advice comes from a few sources and is adopted as judgment.
- Retrospective structure: MEDIUM-HIGH, the "went well / learned / do better + actions" core is common to Atlassian, Miro, Dropbox and Smartsheet templates.
- Postgres Czech full-text options: MEDIUM. There is no built-in Czech stemmer, and the ispell route needs files installed on the DB server. Verify in the search phase against the actual managed database.
- Web search digests were shallow excerpts (provider tier LOW). They were used only to confirm well-known conventions, not as sole evidence for any recommendation.

**Scope note:** The v1.1 scope is fixed by `PROJECT.md` (Current Milestone) and is not re-litigated here. This file (a) classifies each area into table stakes, differentiators and anti-features, (b) states expected behaviour for each area, (c) records the interaction with existing Kokpit features, and (d) lists what the brief leaves implicit and will hurt if missed. API and MCP are out of scope. The previous (v1) content of this file is in git history.

**Hard rule honoured:** no content, titles or links from any private Notion workspace were read or used. Everything below is generic domain knowledge and public template conventions. Examples use fictional data only.

---

## Interpretation of the brief (assumptions to confirm in requirements)

| # | Brief wording | Interpretation used here | Confirm? |
|---|---------------|--------------------------|----------|
| A1 | "Incidents (hub with sub-pages, sub-pages hidden from the listing but searchable)" | Two levels. The Incidents menu item is an index table of incidents. Each incident is itself a "hub page" (metadata, fixed sections) that can own free-form sub-pages (for example a raw log analysis, a query dump, a screenshot walkthrough). Sub-pages never appear in any listing or tree, only via the parent hub and via search. | Yes, ask owner |
| A2 | "Project retrospective created by a button" | A button on the project (record page header action, and optionally the project list) creates a retrospective pre-filled with the fixed sections and a time overview. One retrospective per project by default; if one exists, the button opens it instead of creating a second. | Yes |
| A3 | "per-client/project switch that grants client access" | A boolean on an article, effective only when the article is linked to a client or project. Applies to General knowledge articles only. Incidents and Retrospectives are Admin-only in v1.1. | Yes (security decision) |
| A4 | "Secret/sensitive-data guard on save" | A server-side check on every save path that blocks (with an explicit, logged override) when the Markdown body matches secret patterns. It is not a vault. | Yes |
| A5 | "AI-friendly storage format" | Body stored as plain canonical Markdown, metadata as structured columns, a deterministic serializer that outputs "metadata block + Markdown" for one article. No HTML in the stored body. No API in this milestone. | No |

---

## Feature Landscape

### Table Stakes (Users Expect These)

Missing these makes the Knowledge Center feel like a notes textarea, not a wiki. Grouped by area.

#### A. Articles, Markdown, view and edit

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Article = title + Markdown body, stored as canonical Markdown | The whole point of "AI-readable" and portable | LOW | No HTML or editor-JSON stored. Normalise line endings, trim trailing whitespace on save |
| Separate read view and separate edit screen | In the brief; also how wikis behave (reading is the 95% case) | LOW | Filament ViewRecord and EditRecord pages. View is a rendered page, not a disabled form |
| Safe Markdown rendering (CommonMark + GFM tables, task lists, strikethrough, autolinks) | Runbooks live on tables, checklists and code | LOW-MEDIUM | `league/commonmark` is the standard PHP renderer. Disable raw HTML, restrict link schemes (`http`, `https`, `mailto`, relative), add `rel="noopener noreferrer"` to external links |
| Fenced code blocks with language and a one-click "copy" button | Runbooks are mostly commands. Explicitly in the brief | LOW | Small Alpine component; Clipboard API needs HTTPS (production yes, DDEV yes). Copy the raw code only, never the line numbers or prompt chars |
| Editor with toolbar and live preview | Writing Markdown without preview is hostile | LOW | Filament's built-in Markdown editor is the default choice (verify its file-attachment and preview hooks in the phase). Do not build a custom editor |
| Heading anchors and an auto table of contents on the view page | Long runbooks and post-mortems are unreadable without it | LOW | Generated at render time from headings, never stored |
| Soft delete with a trash and restore | A wiki without undo for deletion loses content once, then nobody trusts it | LOW | Deleted articles drop out of listings and search; restorable by Admin. Hard delete only after an explicit second step |
| Optimistic concurrency on edit | Two browser tabs (or a long edit session) silently overwriting each other is the classic wiki data-loss bug | LOW | Compare a `version` or `updated_at` token on save; on mismatch show "the article changed meanwhile" and offer to reopen. No real-time co-editing |
| Created / updated timestamps and last editor on the view page | Basic trust signal | LOW | Time shown in `Europe/Prague`, stored UTC (existing project rule) |
| Czech user-facing UI, English code | Project rule | LOW | All labels, enum labels, empty states in `lang/cs` |

#### B. Folders, tags, linking

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Folder tree (nested), move article between folders, rename, reorder | Confluence spaces/pages, BookStack shelves/books/chapters, Outline collections: every wiki has a hierarchy | MEDIUM | Adjacency list (`parent_id`) is enough for a small company; cap depth (suggest 5) and forbid cycles in a DB-level or action-level check. Folder is organisational only |
| Deleting a folder is refused unless empty (or contents are moved explicitly) | Prevents orphaned or silently deleted articles | LOW | Do not cascade delete |
| Tags on articles, tag filter, tag cloud or tag column | In the brief; users expect cross-cutting grouping | LOW | Project already has `spatie/laravel-tags` and the Filament tags plugin. Use a dedicated tag `type` for knowledge so client tags and article tags do not mix. Respect the UUID-morph rule for tag pivot tables |
| Link an article to a client and/or project | In the brief; the "second brain" must be attachable to the CRM objects | LOW | Nullable FKs. A project link implies its client; enforce `article.client_id == project.client_id` in the DB or action |
| "Znalosti" tab on client and project pages | Where do I find what we know about this client? | LOW-MEDIUM | Relation manager or tab listing linked articles. Admin sees all; Partner sees only shared ones |
| Breadcrumbs on the view page (folder path and parent hub for sub-pages) | Orientation in a tree | LOW | |
| Listing with filters (folder, tag, client, project, search term) | Table stakes for any content list | LOW | Filament table; the default listing excludes hidden sub-pages |

#### C. Client access (Partner)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Per-article "visible to client" switch, effective only with a client or project link | In the brief. Per-page sharing is the normal shape in Confluence/BookStack ("restrict this page") | MEDIUM | Enforced by Policy plus global/query scope, not UI hiding (project rule). DB check constraint: `client_visible = false OR client_id IS NOT NULL` |
| Partner sees a read-only article with no edit, no history, no tags, no folder tree | A client must not see internal organisation or internal tags | MEDIUM | Partner gets a flat list grouped by project, read-only. Internal tags and folder names can leak unrelated info |
| Partner access respects the project's own client-visibility flag | An article linked to a project that is hidden from the client must stay hidden, even if the article switch is on | MEDIUM | Visibility = `client_visible AND client belongs to Partner AND (project IS NULL OR project is client-visible)` |
| Switching the flag off removes access immediately, including from search | Revocation must be instant | LOW | Falls out of query-scope enforcement; needs a test |
| Images embedded in a shared article are reachable by the Partner, and only by them | Otherwise the shared article shows broken images | MEDIUM | Images come through an authorised route or short-lived signed URL, never a public bucket URL |
| Partner audit matrix and route allowlist extended for every new screen, route and Livewire component | Existing Phase 12 decision: the matrix is a hand-written declaration in `tests/Support`, and new routes require editing `RouteRules` in the same PR | MEDIUM | Not optional. See Dependencies on existing features |

#### D. Attachments (image-only)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Paste or drag-and-drop an image into the editor, inserted as Markdown image syntax | Screenshots are the main attachment in runbooks and incident write-ups | MEDIUM | Editor upload hook returns a stable reference, not a public URL |
| Whitelist by detected content type (jpeg, png, webp, gif), size cap | "Image-only" must be enforced server-side, not by file extension | LOW | Reject SVG (script-bearing) and everything else |
| Private storage, served through an authorised endpoint | Partner isolation and "private bucket" constraint | MEDIUM | Existing `spatie/laravel-medialibrary` with UUID morph columns and the private S3-compatible disk. Reuse the patterns of the existing Documents module for authorisation |
| Strip EXIF (notably GPS) and generate a display size | Screenshots from phones carry location; large originals slow pages | LOW-MEDIUM | Medialibrary conversions run on the queue |
| Orphan cleanup (images no longer referenced by the current body or any retained version) | Otherwise the bucket grows forever | MEDIUM | See Pitfalls: versions reference images too, so cleanup must consider history |

#### E. Version history with restore

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| A version row created on every save that changes content (title, body, metadata) | Outline, BookStack, Wiki.js and Confluence all keep page history | MEDIUM | Dedicated `article_versions` table (full snapshot, not a diff). Do NOT use the activity log for this: the activity log is allowlisted and must not store bodies |
| Version list: who, when, short change note optional | Navigation of history | LOW | Admin only; Partner never sees history |
| View a past version read-only | You cannot restore what you cannot inspect | LOW | |
| Restore = create a new current version from the old snapshot (non-destructive) | History is never rewritten; restoring is itself undoable | LOW-MEDIUM | |
| Coalesce rapid saves into one version | Otherwise "save, save, save" floods history | LOW | e.g. same editor within a few minutes updates the latest version. Keep the rule simple and documented |

#### F. Full-text search (own, separate from Filament global search)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Dedicated search page with one input, result list with title, type badge, snippet, breadcrumb | In the brief; every wiki has it | MEDIUM | Separate from Filament global search (explicit requirement) |
| Searches title, body and tags, ranked (title and tags above body) | Users expect title hits first | MEDIUM | Postgres `tsvector` with weighted fields, GIN index. A generated or trigger-maintained column avoids a queue and keeps the index consistent |
| Diacritics-insensitive and case-insensitive (Czech: "zakaznik" finds "zákazník") | Czech users routinely type without diacritics | MEDIUM | `unaccent` filtering dictionary plus the `simple` configuration is the pragmatic baseline. Czech stemming (ispell) is an optional upgrade that needs dictionary files on the database server; do not make it a requirement |
| Prefix match while typing ("kube" finds "kubernetes") | People type partial words | LOW-MEDIUM | `to_tsquery` with `:*` on the last token |
| Highlighted snippets showing the match in context | Without a snippet users open every result | LOW-MEDIUM | `ts_headline` on top-N results only (it is slow on large result sets) |
| Hidden incident sub-pages are searchable and shown as "Incident > sub-page" | In the brief | LOW | The listing scope hides them; the search scope must not. Two separate scopes, both tested |
| Search results obey the same visibility rules as listings (Partner sees only shared articles, deleted articles never appear) | A search that leaks titles or snippets defeats the Policy | MEDIUM | One shared "readable by this user" query scope used by listing, search and view |
| Filters in the search: type (General / Incident / Retrospective), client, project, tag, folder | Narrowing is how people search wikis | LOW-MEDIUM | |

#### G. Secret guard

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Scan the body (and title, and incident fields) on every save path and block when a secret pattern matches | In the brief. Runbooks attract pasted credentials; this store is AI-readable and may be shared with clients | MEDIUM | Patterns: private key blocks, cloud and payment provider key prefixes, JWT-shaped strings, `password=`/`secret=`/`token=` assignments, connection strings with credentials, bearer tokens. Start from well-known public regex sets (gitleaks-style) and keep the list in code, with a test per pattern |
| The message names the kind and line of the match, never echoes the value | Showing the secret in a flash message or log re-leaks it | LOW | |
| Explicit Admin override ("this is a placeholder") that is recorded | False positives are certain (example tokens in docs) | MEDIUM | Override writes a metadata-only activity log entry (no body). Overrides are per save, not a permanent allowlist |
| The guard also covers restore-from-version | Restoring an old version that contains a secret must not bypass the check | LOW | Same save action |
| Never log the body (nor the matched value) in the activity log or exception reports | Defeats the guard otherwise | LOW | Activity log is allowlist-based already; keep the new models out of body logging |

#### H. Navigation and structure

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Menu group "Znalosti" with General knowledge, Incidents, Retrospectives | In the brief | LOW | Filament navigation group. Partner sees only the entries it may open (Admin-only items are absent, and their routes answer like any other forbidden route) |
| Empty states that explain what to do ("Create the first retrospective from a project page") | Standard UX for new sections | LOW | |

#### I. Incidents (post-mortem section)

Expected behaviour in post-mortem tools (Atlassian, PagerDuty, incident.io, Rootly, Google SRE style): one record per incident with a short fixed header, a factual timeline first, impact, cause analysis, what went well or badly, and owned, dated action items. Blameless wording. Over-long mandatory templates produce empty prose, so keep the fixed part small.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Incident index table: title, severity, status, affected system, occurred at, open follow-ups count | The hub page the brief describes | LOW-MEDIUM | Sub-pages excluded from this table |
| Incident record metadata: occurred-at with timezone, severity, status, affected system, linked client/project (optional) | In the brief | MEDIUM | See the timezone note below |
| Severity as a small fixed scale (4 levels, each with a one-line definition shown near the field) | PagerDuty/Atlassian use SEV1-SEV4 or P1-P4. Undefined levels drift | LOW | Enum, Czech labels |
| Status with a short life cycle: open, mitigated, resolved, closed (closed = review done) | Every incident tool tracks life cycle | LOW | Enum. Status change is a normal edit that creates a version |
| Time with timezone: store `timestamptz` (UTC) plus an IANA timezone name, display in that timezone with offset, show the Prague equivalent if it differs | In the brief ("time with timezone"). Incidents often come from logs in UTC or another zone | MEDIUM | Existing rule: stored UTC, shown `Europe/Prague`. This adds an input and display timezone per incident. Handle DST edges with a test around a clock change |
| Optional detected-at and resolved-at; derived duration | Duration is the first number anyone asks for | LOW | Derived, never typed. Integer seconds, no rounding (project rule) |
| Cause analysis as a list of causes, each marked hypothesis, confirmed, or ruled out | In the brief ("confirmed vs hypothesised cause"). Sources warn that one "root cause" field invites premature closure | MEDIUM | Structured rows (text, state, optional evidence note). The incident shows a confirmed count. Rendered into Markdown for search and AI |
| Structured timeline: ordered entries (timestamp in the incident timezone + text) | In the brief; timeline-first is the consensus advice | MEDIUM | Structured rows rather than a hand-typed Markdown table, so ordering and timezone are reliable. Serialised into Markdown for search and AI |
| Actions taken (what was done to restore service) | In the brief | LOW | Markdown section or structured list |
| Follow-ups: text, due date, status (open, done, dropped), optional owner | In the brief. "Specific and owned, with a date" is the strongest consensus point in post-mortem guidance | MEDIUM | Structured rows. Open and overdue count is shown in the index and the navigation badge |
| Free-form Markdown sections for summary, impact and lessons | Needed for narrative; keep fixed headings small | LOW | Summary, Impact, What went well, What did not, Lessons |
| Sub-pages under an incident: create, list on the hub, hidden from all other listings, searchable | In the brief | MEDIUM | Same article model with a parent link and a "hidden from listings" kind. Inherit access from the hub (Admin-only in v1.1) |
| Versioning, secret guard and images behave exactly as for general articles | Consistency | LOW if the foundation is shared | Reuse, do not duplicate |

#### J. Project retrospective

Expected behaviour (Atlassian, Miro, Dropbox, Smartsheet templates): a short, fixed set of reflective sections plus action items; blameless; looks for patterns, not blame.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| "Create retrospective" button on the project | In the brief | LOW | One retrospective per project by default (A2). If it exists the button opens it |
| Fixed sections: Description and output, What went well, What we learned, What to do better | In the brief | LOW | Fixed headings, rendered in a fixed order, each a Markdown block. Users cannot add, remove or rename sections (fixed format) |
| Time overview computed from tracked time | In the brief | MEDIUM | Total tracked, split billable vs non-billable and billed vs unbilled, by person, by month, and estimate vs actual if the project has an estimate. All from existing time entries, exact seconds, no rounding, shown as `h:mm` |
| Retrospective is Admin-only | Time overview exposes measured time; Partner must never see measured time or rates | LOW (the decision), MEDIUM (the test) | Enforced by Policy and scope; add to the Partner audit matrix as a closed screen |
| Link back from the project page and from the retrospective to the project | Navigation | LOW | |
| Listing under Retrospectives: project, client, created date, state | The menu entry in the brief needs an index | LOW | |
| Versioning, secret guard, images, search: as for general articles | Consistency | LOW if the foundation is shared | |

---

### Differentiators (Competitive Advantage)

Not expected by a user of a generic wiki, but valuable here, and mostly cheap because the data already lives in Kokpit. Aligned with the Core Value ("nothing slips through unnoticed"): the differentiators lean on follow-ups that do not get lost, time data from tracked work, and AI-readable output.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Retrospective time overview from real tracked time, with estimate vs actual | Generic wikis cannot do this; the numbers are not retyped by hand | MEDIUM | Compute live; take a snapshot into the stored Markdown when the retrospective is saved or closed, so the AI-readable text and the page agree and later time entries do not silently change a finished retrospective. Provide a "refresh from tracked time" action until closed |
| "Copy as Markdown for AI" on every article (metadata block + body) | Delivers the "AI-readable" goal without an API: paste a whole runbook into an assistant | LOW | The same serializer will feed a later API or MCP. Keep one canonical serializer (front-matter-style metadata block, then body). Stable ID in the block |
| Canonical AI-readable serialization with stable identifiers, headings as chunk boundaries, no HTML | Prepares for the later API/MCP milestone without building it | LOW-MEDIUM | Metadata as structured columns, never parsed out of prose. Internal links stored as stable ID references and resolved at render, so renames do not break them |
| Incident numbers such as `INC-0007` | Humans and AI refer to incidents by short id; numbering infrastructure already exists | LOW-MEDIUM | Reuse `number_sequences`. Gap-free is not required here, but the concurrency-safe allocator is free to reuse. Decide in requirements whether the number is purely a label |
| Open and overdue follow-up badge in the Znalosti menu and on the incident index | Matches the Core Value: nothing quietly dropped | LOW | Counts only, no dashboard widget in v1.1 |
| Convert a follow-up into a Kokpit task (one-way, links back) | Follow-ups become real work with time tracking | MEDIUM | One-way creation with a stored reference. No two-way status sync (see anti-features). Needs the task create action, project key rules, and Partner visibility rules for tasks |
| Typo-tolerant search via `pg_trgm` as a fallback | Czech names, hostnames and error strings are typo-prone | MEDIUM | Secondary ranking path; skip if the baseline search is good enough |
| Diff view between two versions | Makes "restore" a confident decision | MEDIUM | A server-side line diff of Markdown is enough. Defer if cost bites |
| Article templates for runbooks (a few built-in starters) | Faster start, consistent runbooks | LOW | Insert starter Markdown on create. Do not build a template manager |
| Backlinks ("referenced by") from `[[article]]`-style internal links | Wiki feel, helps navigation and AI context | MEDIUM | Requires a link table maintained on save. Defer unless internal linking is in the requirements |
| Syntax highlighting in code blocks | Readability | LOW-MEDIUM | Optional. The copy button matters more than colours |
| Markdown export of a folder or everything as a ZIP | Portability and offline AI use, doubles as insurance | MEDIUM | Reuse the existing ZIP download pattern from Documents. A candidate for the AI-storage requirement |
| Linked incidents shown on the client and project pages (Admin only) | "What went wrong for this client before?" | LOW | Same relation tab as general knowledge, Admin only |

---

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Real-time collaborative editing (live cursors) | Modern wikis have it | One Admin; enormous complexity (CRDT or OT, websockets), conflicts with plain canonical Markdown | Optimistic concurrency check on save |
| Block-based / WYSIWYG rich editor storing JSON or HTML | Feels modern | Breaks "canonical Markdown" and AI readability; lock-in; sanitising HTML is a security surface | Markdown editor with preview |
| General file attachments (PDF, DOCX, ZIP) in articles | "Just attach the contract" | Brief says image-only and "text always lives inside the article"; non-image files are unsearchable, unversionable and a malware surface | Existing Documents module for files; link from the article text |
| Storing secrets (passwords, API keys) in articles, "vault" feature | Convenience | A plain-text store that is searchable, versioned forever and AI-readable is the wrong place; history makes redaction hard | Secret guard blocks it; document where the secret lives in a proper secrets manager, never the value |
| Folder-level permissions and permission inheritance | Confluence/BookStack have it | Doubles the access model for one Admin and Partner roles; high risk of Partner leaks | One per-article switch tied to client/project (the brief). Folders stay purely organisational |
| Public share links / publish to web | "Share without an account" | Bypasses Partner isolation and the audit matrix; new unauthenticated routes | Partner accounts are the sharing model |
| Partner editing, commenting or reacting on articles | Collaboration | Partner is read-only by design for knowledge; adds moderation and notification flows | Partner reads; feedback goes through existing task comments |
| Sharing Incidents or Retrospectives with Partner in v1.1 | Clients like post-mortems | Retrospective carries measured time; incidents often name internal systems and causes. Doubles the audit scope | Admin-only now. A later "client-facing incident summary" can be a separate, deliberately written general article |
| Comments, @mentions, notifications, subscriptions on articles | Wiki parity | One Admin; no audience | None |
| Approval workflows, article owners, "verify by" review cycles | Confluence/Guru stale-content features | Single user; process without people | Updated-at shown on the page |
| Auto-save drafts to the server on every keystroke | Never lose work | Floods version history; collides with concurrency checks and the secret guard | Explicit Save; coalescing of rapid saves; browser-side draft only if cheap |
| Embeddings, semantic search, in-app AI summarisation or Q&A | "AI-ready" read as "AI built in" | API/MCP are out of scope; adds pgvector, cost, privacy and a model dependency | Clean canonical storage and "Copy as Markdown for AI" now; embeddings in a later milestone |
| Using Filament global search for the knowledge search | Free | Brief demands a separate search; global search is per-resource and cannot rank, snippet or scope hidden sub-pages | Own search page |
| Incident tooling beyond documentation: on-call paging, status page, alert integrations, SLA/MTTR dashboards, chat-timeline import | It is what incident products sell | This is a post-mortem record, not an incident management platform; each integration is a separate product | Manual timeline and follow-ups; derived duration only |
| A "person at fault" or "responsible" field on incidents | Accountability | Contradicts blameless practice; the owner is a freelancer or tiny team, and blame fields attract bad data | Contributing causes and follow-up owners only |
| Two-way sync between follow-ups and Kokpit tasks | Single source of truth | Status drift, Partner task visibility rules, and conflict handling | One-way "create task from follow-up" with a reference |
| Retrospective voting boards, anonymous input, sentiment, multi-participant sessions | Team retro tools | One Admin; this is a written record | Four fixed sections |
| Profitability and revenue analysis inside the retrospective | Natural next question after time overview | Duplicates invoicing and rate-resolution logic and exposes prices; wrong results are worse than none | Hours only in v1.1; the existing reports cover money |
| Customisable retrospective or incident section sets | Flexibility | The brief says fixed format; configurable templates need a template manager and migrations of old records | Fixed sections; free-form sub-pages for anything extra (incidents) |
| PDF export of articles | Handy for clients | New rendering path and Partner leak surface | "Copy as Markdown" and ZIP export |
| Real "tree" drag-and-drop everywhere, infinite depth | Looks polished | Cost without value at this scale | Move action with a folder select; depth cap |

---

## Feature Dependencies

```
Article model (title, Markdown body, folder, client/project link, soft delete)
    |--requires--> Folder tree
    |--requires--> Tags (existing spatie tags, new tag type)
    |--requires--> Secret guard (save path hook) ........ must precede any real content
    |--requires--> Versions table (snapshot on save)
    |        `--requires--> Secret guard on restore
    |--requires--> Image attachments (medialibrary, private disk, authorised route)
    |        `--requires--> Image reference tracking (versions + orphan cleanup)
    `--requires--> "Readable by this user" query scope + Policy

Client/project visibility switch
    |--requires--> Article.client/project link
    |--requires--> Existing project client-visibility flag + Partner scope
    `--requires--> Partner audit matrix + RouteRules updated in the same PR

Full-text search
    |--requires--> Article model + tags + stored searchable text
    |--requires--> "Readable by this user" scope (shared with listing)
    `--requires--> Incident/retro searchable text (structured rows serialised into Markdown)

Incidents
    |--requires--> Article base (versions, guard, images, search)
    |--requires--> Parent/hub + "hidden from listings" sub-pages
    |--requires--> Timezone-aware time handling
    |--requires--> Structured rows (causes, timeline, follow-ups)
    `--enhances-> Follow-up -> Kokpit task (optional, existing Tasks)

Retrospectives
    |--requires--> Article base
    |--requires--> Project record action (button)
    `--requires--> Existing time entries (aggregate query, Admin-only)

AI-readable storage
    |--requires--> Canonical serializer (metadata block + Markdown)
    `--enhances-> "Copy as Markdown for AI", ZIP export, future API/MCP

Menu group "Znalosti"
    `--requires--> Index pages for General, Incidents, Retrospectives

Secret guard ──conflicts──> Auto-save drafts to server
Partner sharing of Incidents/Retrospectives ──conflicts──> "Partner never sees measured time"
```

### Dependency Notes

- **Secret guard must precede real content:** version history retains every save, so a secret that gets saved once stays in history and is hard to purge. The guard belongs in the first phase that can persist an article body, not at the end.
- **Visibility switch requires the shared scope:** listing, view, search, image delivery and the client/project tabs must all call one "readable by this user" scope. Building search first with its own filtering invites a leak.
- **Search requires incident/retro text in storage:** causes, timeline and follow-ups are structured rows; they need a serialised Markdown form (also used for AI storage) so the search column and the AI serializer share one source.
- **Incidents and Retrospectives require the article base:** model them as article kinds (or subtypes) on one base rather than three unrelated tables, otherwise versions, guard, images and search triple.
- **Retrospective requires time entries but must not expose them:** the Admin-only rule keeps this inside the existing guarantee. If the owner later wants retrospectives shared, the time overview section must be dropped for Partner by design.
- **Follow-up to task requires Tasks:** it needs project selection (a follow-up may have no project) and the task creation rules; that is why it is a differentiator and not table stakes.

### Dependencies on existing Kokpit features

| Existing feature | How the Knowledge Center depends on it | Risk |
|------------------|----------------------------------------|------|
| Roles Admin/Partner, Policies, global query scopes | Every article read path goes through them | HIGH: a leak is a product failure |
| Phase 12 Partner audit (matrix in `tests/Support`, `RouteRules`, `Audit` suite, `composer partner-audit`) | New screens, routes, Livewire components and Partner actions must be declared by hand in the same PR | MEDIUM: cheap if remembered, a failing audit if not |
| Projects: client link, client-visibility flag, estimate | Article/project link rules, retrospective source, estimate vs actual | LOW |
| Time entries (UTC seconds, billable/billed state) | Retrospective aggregation | MEDIUM: define "billed vs unbilled" and "billable" exactly as existing reports do; reuse their queries, do not re-implement |
| Tags (`spatie/laravel-tags`, Filament tags plugin) | Article tags | LOW: separate tag type; UUID morph columns |
| Medialibrary and the private S3-compatible disk | Image attachments, conversions on the queue | MEDIUM: UUID morph, authorised delivery |
| Documents module | Stays the home for non-image files | LOW: no overlap, just a link in docs |
| Activity log (allowlisted attributes) | Metadata-only audit of create, delete, restore, share toggle, guard override | MEDIUM: must not capture bodies |
| `number_sequences` | Optional incident numbers | LOW |
| Queue (Redis) | Image conversions, orphan cleanup job | LOW |
| `lang/cs` and Czech-first UI | All new text | LOW |
| Filament navigation groups | "Znalosti" group | LOW |
| Settings (spatie settings) | Optional: secret-guard pattern toggles. Prefer code-defined patterns | LOW |

---

## MVP Definition

For this milestone every area in the brief is committed scope, so "MVP" here means the order in which value lands and which parts are cut first if time is short.

### Launch With (v1.1 core)

- [ ] Article model, Markdown view and edit screens, folders, tags, soft delete, optimistic concurrency, code-block copy button
- [ ] Secret guard on every save path (first, before content accumulates)
- [ ] Version history with view and restore
- [ ] Image-only attachments on private storage with authorised delivery
- [ ] Client/project link, per-article client switch, Partner read-only view, Partner audit matrix updates
- [ ] Own full-text search (diacritics-insensitive, ranked, snippets, filters, Partner-scoped)
- [ ] Incidents: index, metadata (time with timezone, severity, status, affected system), causes with confirmed/hypothesis, timeline, actions, follow-ups, hidden searchable sub-pages
- [ ] Retrospective: button, fixed sections, time overview from tracked time, Admin-only
- [ ] Menu group "Znalosti"
- [ ] Canonical AI-readable serialization plus "Copy as Markdown for AI"

### Add After Validation (v1.1.x)

- [ ] Open/overdue follow-up badge (cheap, add with Incidents if time allows)
- [ ] Incident numbers `INC-NNNN`
- [ ] Follow-up to Kokpit task (one-way)
- [ ] Version diff view
- [ ] Linked incidents on client/project pages
- [ ] Markdown ZIP export
- [ ] Article starter templates

### Future Consideration (v2+)

- [ ] API and MCP access to the Markdown store (explicitly next milestone)
- [ ] Embeddings / semantic search
- [ ] Backlinks and `[[wiki links]]`
- [ ] Czech ispell stemming and `pg_trgm` typo tolerance
- [ ] Client-facing incident summaries
- [ ] Dashboard widget for overdue follow-ups

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Article + Markdown view/edit + code copy | HIGH | LOW | P1 |
| Folders + tags + link to client/project | HIGH | MEDIUM | P1 |
| Secret guard | HIGH | MEDIUM | P1 |
| Version history + restore | HIGH | MEDIUM | P1 |
| Image-only attachments (private, authorised) | HIGH | MEDIUM | P1 |
| Client switch + Partner view + audit matrix | HIGH | MEDIUM | P1 |
| Own full-text search (unaccent, ranked, snippets) | HIGH | MEDIUM | P1 |
| Incidents (metadata, causes, timeline, follow-ups) | HIGH | HIGH | P1 |
| Hidden searchable incident sub-pages | MEDIUM | MEDIUM | P1 (brief) |
| Retrospective with time overview | HIGH | MEDIUM | P1 |
| Menu group and index pages | MEDIUM | LOW | P1 |
| Copy as Markdown for AI + canonical serializer | HIGH | LOW-MEDIUM | P1 |
| Optimistic concurrency, soft delete | MEDIUM | LOW | P1 |
| Follow-up badge | MEDIUM | LOW | P2 |
| Incident numbers | MEDIUM | LOW-MEDIUM | P2 |
| Follow-up to task | MEDIUM | MEDIUM | P2 |
| Version diff | MEDIUM | MEDIUM | P2 |
| Markdown ZIP export | MEDIUM | MEDIUM | P2 |
| Article templates | LOW | LOW | P2 |
| Typo-tolerant search (`pg_trgm`) | LOW-MEDIUM | MEDIUM | P3 |
| Backlinks | LOW-MEDIUM | MEDIUM | P3 |
| Syntax highlighting | LOW | LOW-MEDIUM | P3 |
| Czech ispell stemming | LOW | HIGH | P3 |

**Priority key:** P1 required for the milestone, P2 add when possible, P3 later.

---

## Competitor Feature Analysis

Generic, public-product conventions only.

| Feature | Confluence / Outline / BookStack (wikis) | Atlassian / PagerDuty / incident.io / Rootly (post-mortems) | Our Approach |
|---------|------------------------------------------|-------------------------------------------------------------|--------------|
| Structure | Spaces/collections or shelves-books-chapters-pages | One document per incident | Folder tree plus tags; incidents and retrospectives as special kinds |
| History | Per-page history with restore | Usually the document's own history | Snapshot per save, non-destructive restore |
| Permissions | Space and page level, groups | Team or organisation | One switch per article tied to client/project; Admin-only for incidents and retrospectives |
| Search | Full-text with filters and snippets | Within the incident tool | Postgres full-text, unaccent, ranked, snippets; separate from global search |
| Editor | WYSIWYG or Markdown | Rich forms and templates | Markdown only, copy button on code blocks |
| Structure of a post-mortem | Template page | Summary, impact, timeline, causes, what went well/badly, action items | Same, with structured timeline, causes (hypothesis/confirmed) and follow-ups |
| Action items | Checkbox lists | Tracked with owner and due date, often synced to a ticket tool | Structured follow-ups with due date and status; optional one-way task creation |
| Retrospective | Templates (went well / didn't / improve, 4Ls) | n/a | Four fixed sections plus an automatic time overview from real tracked time |
| AI access | Add-on assistants, integrations | Add-on summaries | Canonical Markdown plus "Copy for AI" now, API/MCP later |

---

## Sources

- Atlassian incident management post-mortem guidance and templates (atlassian.com/incident-management/postmortem and /templates): sections and blameless approach. MEDIUM, search excerpt only
- PagerDuty post-mortem documentation guide, incident.io SRE post-mortem best practices, Rootly post-mortem template, OneUptime and others: the consensus of summary, impact, timeline, cause, owned action items; single root-cause field criticised; over-long mandatory forms discouraged. MEDIUM
- Atlassian Confluence retrospective template, Miro project retrospective, Dropbox retrospectives doc, Smartsheet retrospective guide, ClickUp: went well / did not / improve / actions, 4Ls, blameless tone. MEDIUM
- Outline, BookStack, Wiki.js, Docmost comparisons (docmost.com, elest.io, ClickUp, ONES): history with restore, hierarchy and permissions as must-haves; weak on tags. MEDIUM
- PostgreSQL documentation for `unaccent` and text search dictionaries; community Czech ispell recipes (tvondra/ispell_czech, Discourse thread, `postgresql-czech-fulltext` image): no built-in Czech stemmer, ispell needs server-side dictionary files, GPL-licensed dictionary data. MEDIUM, verify against the managed database during the search phase
- Project files: `.planning/PROJECT.md` (milestone scope, constraints, Phase 12 audit decisions)

---
*Feature research for: Knowledge Center (wiki, incidents, project retrospectives) in Kokpit v1.1*
*Researched: 2026-11-25*
