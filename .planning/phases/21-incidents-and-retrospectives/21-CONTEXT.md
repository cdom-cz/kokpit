# Phase 21: Incidents and Retrospectives - Context

**Gathered:** 2026-11-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin documents incidents as numbered hubs (`INC-NNNN`) with a structured, blameless post-mortem and hidden sub-pages, and records one fixed-format retrospective per project with a time overview built from tracked time. Everything is visible to Admin only. The Knowledge menu group lists General knowledge, Incidents and Retrospectives (NAV-01).

Requirements: INC-01..INC-06, RETRO-01..RETRO-04, NAV-01. Search over incidents is Phase 22, client sharing is Phase 23.

</domain>

<decisions>
## Implementation Decisions

### Incident structure (INC-01, INC-02, INC-04)
- **D-01:** Causes, timeline entries, actions taken and follow-ups are structured rows in their own tables, not sections of the Markdown body. A follow-up carries status and due date as real columns, so it can be filtered and turned into a task with one action. The Markdown body of the hub holds only the free description.
- **D-02:** These rows have no versions of their own and are edited and deleted in place. Every change is written to the activity log without content. Only the Markdown body of the hub is versioned (Phase 19 machinery).
- **D-03:** Occurrence time is stored as a UTC instant plus an IANA time zone (for example `Europe/Prague`). The list and the hub show it in the incident's zone with the zone marked.
- **D-04:** Severity is `SEV1`-`SEV4`; status is open / resolved / closed. Both are fixed enums with a database check constraint, like `ArticleKind`.
- **D-05:** The affected system is a short free-text field.
- **D-06:** The hub screen shows a metadata block on top, then sections Causes, Timeline, Actions taken and Follow-ups (each with inline add), then the Markdown body. Sub-pages are linked from the hub.
- **D-07:** The incident number comes from `SequenceAllocator` (`INC-NNNN`, gap-free and duplicate-free under concurrent creation, allocated inside the creating transaction).

### Incident sub-pages (INC-03)
- **D-08:** Sub-page types are overview, report, changes, runbook, monitoring and tasks. An incident may have many sub-pages of the same type (no unique index on incident and type).
- **D-09:** A new sub-page is created empty with a title prefilled from its type (renameable). No template skeleton. The hub does not create a Report automatically; Admin adds every sub-page by hand.
- **D-10:** On the hub sub-pages are grouped by type in the fixed order overview, report, changes, runbook, monitoring, tasks, then by creation time within a type.
- **D-11:** A sub-page has its own view, edit and versions screens (same article machinery, kind `incident_page`) with a breadcrumb back to the incident hub. It never appears in the article or incident lists.

### Follow-up to task (INC-05)
- **D-12:** The "create task" action opens a dialog with title (follow-up text), description and due date prefilled. The project is prefilled from the incident when it links one, otherwise Admin must pick one.
- **D-13:** Title, description and due date are copied once. The follow-up stores the task id as a plain link, with no synchronisation: later changes to the task never change the follow-up. After creation the button becomes a link to the task, and a second press, even from two tabs, never creates a second task.
- **D-14:** If the linked task is deleted, the link is set to null (`ON DELETE SET NULL`); the follow-up stays and shows that the task no longer exists, and a task can be created again.

### Retrospective (RETRO-01, RETRO-02, RETRO-03, RETRO-04)
- **D-15:** A retrospective is live while it is not archived. A partial unique index per project (not archived) enforces at most one; Create retrospective opens the live one if it exists, even when pressed from two tabs at once. After archiving a new one can be created.
- **D-16:** Create retrospective is an action in the header of the project detail, Admin only. When a live retrospective exists its label becomes "open retrospective".
- **D-17:** The four fixed sections (description and output, what went well, what we learned, what to do better) are four separate fields in the editor. They are composed into one Markdown body with fixed headings, so versions, diff, secret guard and images work unchanged.
- **D-18:** The time overview (worked, billed, unbilled, estimate vs actual) is a stored snapshot with its computation time. It is computed on first open and on the Refresh action, shows the age of the snapshot, has no rates, prices or amounts, and is never part of the Markdown body or of any version.

### Visibility (INC-06, RETRO-04)
- **D-19:** Incidents, sub-pages, retrospectives and the time overview are Admin only, enforced by Policies and query scopes, with every new resource, page, route and Livewire component declared in the Partner permission matrix in the same pull request.

### Claude's Discretion
- Table and column names, Czech wording and icons, the order of items in the Knowledge menu group, how the one-press task creation is made idempotent (lock or unique constraint on the task link), the exact definition of billed versus unbilled time from the existing billing state, the list columns and filters of the incident list, and ordering of causes, timeline and follow-ups inside the hub.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 21: Incidents and Retrospectives" (goal and five success criteria)
- `.planning/REQUIREMENTS.md` — INC-01..INC-06, RETRO-01..RETRO-04, NAV-01
- `.planning/STATE.md` — Blockers/Concerns for this milestone

### Prior decisions
- `.planning/phases/18-knowledge-articles-and-secret-guard/18-CONTEXT.md` — single save path, stale-save check, secret guard, editor and preview
- `.planning/phases/19-folders-tags-and-version-history/19-CONTEXT.md` — versions, restore, redaction, client and project links
- `.planning/phases/20-image-attachments/20-CONTEXT.md` — image binding and cleanup, which sub-pages and retrospectives inherit

### Research
- `.planning/research/ARCHITECTURE.md` — `app/Domain/Knowledge/` layout
- `.planning/research/PITFALLS.md` and `.planning/research/SUMMARY.md` — incident and retrospective notes

### Existing code
- `database/migrations/*knowledge_articles*` — one table for all kinds; `kind` frozen by trigger (KP001); `incident_page` has a parent and nothing else does; `client_visible` allowed only for general and incident
- `app/Domain/Knowledge/Enums/ArticleKind.php` — general, incident, incident_page, retrospective
- `app/Domain/Shared/Sequences/SequenceAllocator.php` — the only source of gap-free numbers; must run in the caller's transaction
- `app/Domain/Knowledge/Actions/SaveArticle.php`, `ArchiveArticle.php`, `RestoreVersion.php` — save, archive and restore paths to reuse for new kinds
- `app/Filament/Resources/KnowledgeArticleResource.php` and `app/Providers/Filament/` — navigation group `knowledge.navigation_group`, panel registration
- `app/Domain/Tasks/`, `app/Domain/Projects/`, `app/Domain/TimeTracking/` — task creation and tracked-time sources for the follow-up action and the time overview
- `tests/Support/PartnerMatrix.php`, `tests/Support/RouteRules.php` — declare every new route, page and Livewire component
- `CONTRIBUTING.md` — repository hygiene (fictional fixtures only)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `KnowledgeArticle` model and `SaveArticle` action already handle versions, secret guard, images and stale saves for every kind.
- `SequenceAllocator::next('scope:key')` for `INC-NNNN`.
- Phase 19 relation manager and client/project columns on articles for project links.

### Established Patterns
- Fixed enums with DB check constraints; DB-level guards (triggers, composite FKs) with error codes mapped to explanations.
- Domain code in `app/Domain/<Area>/{Actions,Models,Enums,Queries}`; Filament resources in `app/Filament`.
- Every state-changing action writes a content-free activity log entry.

### Integration Points
- Knowledge navigation group (add Incidents and Retrospectives resources).
- Project detail header action (Create retrospective).
- Task creation from a follow-up (Tasks domain).
- Tracked time and billing state for the retrospective time overview.

</code_context>

<specifics>
## Specific Ideas

No specific requirements beyond the decisions above; open to standard approaches.

</specifics>

<deferred>
## Deferred Ideas

None - discussion stayed within phase scope.

</deferred>

---

*Phase: 21-Incidents and Retrospectives*
*Context gathered: 2026-11-27*
