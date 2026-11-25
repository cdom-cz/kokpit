# Phase 18: Knowledge Articles and Secret Guard - Context

**Gathered:** 2026-11-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin can write, read and maintain Markdown knowledge articles that render safely, while no save stores a secret unnoticed or silently overwrites a concurrent change.

This phase lays down the knowledge data model for every article kind (general, incident hub, incident sub-page, retrospective), the fail-closed Partner access seam, the single save path (optimistic version check, secret guard, immutable version row, metadata-only activity log, all in one transaction) and the core Admin article UI on top of it (menu group "Znalosti" with General knowledge, list, separate view and edit screens, server-rendered preview, archive and restore).

Out of this phase: folders UI, tags, client/project links and version history screens (Phase 19); images (Phase 20); incidents and retrospectives UI (Phase 21); search (Phase 22); client sharing and AI export (Phase 23).

</domain>

<decisions>
## Implementation Decisions

### Secret guard rules (SEC-01, SEC-02, SEC-03)
- **D-01:** "Unambiguous secret" (blocks the save, no override) means only high-precision patterns: PEM private keys, AWS/Stripe/GitHub-style tokens, JWTs, URLs with embedded credentials. Everything else the scanner finds (for example `password=...`, long random strings) is "ambiguous": the save needs explicit acknowledgment.
- **D-02:** The guard scans the article body only, not the title.
- **D-03:** Acknowledgment flow: the first save with an ambiguous finding is refused with a list of findings (rule name and line number, never the value); the form then shows the warning and a "save anyway" button. The acknowledgment covers that one save only and is logged in the activity log without any content.
- **D-04:** A confirmed ambiguous finding marks the article `sensitive`. The flag stays set until the Admin clears it by hand in the editor; a later save without findings never clears it automatically (fail closed). Admin can also set the flag by hand without any finding.
- **D-05:** Test fixtures for secrets are assembled at runtime from fragments, so no single line in the repository looks like a real value (repository hygiene rule).

### Editor and preview (KB-06)
- **D-06:** The edit screen has two tabs, "Write" and "Preview" (Czech labels in `lang/cs`). The preview is rendered on the server by the same renderer as the view screen, so both always match. No side-by-side layout, no modal.
- **D-07:** Filament `MarkdownEditor` (EasyMDE) with a basic toolbar only: bold, italic, headings, lists, task list, link, code, table, quote. No image button (images arrive in Phase 20), no built-in preview, side-by-side or fullscreen, because the built-in preview does not use the Kokpit renderer.
- **D-08:** Leaving the edit screen with unsaved changes shows a leave warning. No server-side autosave.

### Concurrent edit and no-op saves (KB-08, VER-01)
- **D-09:** A stale save (the article moved to a newer version since the edit screen was opened) is rejected with a notice. The user's typed text stays in the editor so it can be copied, and the notice offers "Load current version", which opens the current article in a new tab. No automatic merging and no "overwrite anyway" option.
- **D-10:** A save without changes creates no version. "Without changes" is compared after normalisation: line endings unified to LF, trailing spaces trimmed, trailing blank lines removed. Content inside fenced code blocks is never altered. The stored text is the normalised text.
- **D-11:** A version is a full snapshot of title and body. A change to the title alone creates a new version, and the version number rises by exactly one per content-changing save (gap-free, allocated under a lock on the article row).

### List, view screen and callouts (KB-02, KB-03, KB-04, KB-05, KB-07)
- **D-12:** Callouts supported: the five GitHub alert types NOTE, TIP, IMPORTANT, WARNING and CAUTION (`> [!NOTE]` syntax). An unknown type renders as an ordinary blockquote.
- **D-13:** The General knowledge list shows title, version number, last edited and a sensitive indicator. It defaults to active articles only, with a switch to show archived ones (restore is available from there), sorted by last edit descending. Folder, tag and client columns arrive in Phase 19.
- **D-14:** The Copy button on a code block copies the exact original text of the block (no highlighting markup, no line numbers) and briefly changes its label to a "Copied" confirmation.

### Claude's Discretion
- Naming of the article kinds and the folder tree implementation (own `parent_id` with recursive CTE versus `staudenmeir/laravel-adjacency-list`): the owner left these to the planner. Research recommends the own `parent_id` with a recursive CTE; the kind naming is fixed once in the Phase 18 data model and never changes after creation.
- Renderer and sanitiser configuration details, syntax highlighter choice (`tempest/highlight` is optional per research), exact scanner pattern list, view screen layout, icons and Czech wording.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 18" and the v1.1 mapping notes — goal, success criteria, the data model scope that opens this phase
- `.planning/REQUIREMENTS.md` — KB-01 to KB-08, SEC-01 to SEC-03, VER-01 (SEC-04 and sharing belong to Phase 23)
- `.planning/PROJECT.md` — Constraints (UUID v7, Partner isolation by policies and scopes, fictional data only), Key Decisions
- `.planning/STATE.md` §Blockers/Concerns — open Phase 18 items (kind naming, folder tree, renderer spike) and the cross-cutting scrub of `body` from Sentry and logs

### Research
- `.planning/research/SUMMARY.md` — recommended stack, conflicts resolved, phase order
- `.planning/research/ARCHITECTURE.md` — `app/Domain/Knowledge/` layout, write seam, access layer
- `.planning/research/PITFALLS.md` — Markdown XSS, secret-guard leak paths, Partner side channels, versions and restore, lost updates
- `.planning/research/STACK.md` — CommonMark, html-sanitizer, highlighter, Filament MarkdownEditor findings
- `.planning/research/FEATURES.md` — table stakes and anti-features for the knowledge center

### Partner audit and repository hygiene
- `tests/Support/PartnerMatrix.php` and `tests/Support/RouteRules.php` — every new screen, route, Livewire component and channel must be declared here in the same pull request (Phase 12 audit)
- `tests/Support/ModelDeclaration.php` and `tests/Support/ModelRules.php` — model declarations enforced by `ModelDeclarationTest`
- `tests/Support/Canary.php`, `tests/Support/CanaryRegistry.php` — canary records for isolation tests
- `CONTRIBUTING.md` — repository hygiene rules; `scripts/check-sensitive.sh` runs before every commit

No external specs beyond these; requirements are fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Shared/Models/KokpitModel.php`: base model (UUID v7 keys) the new knowledge models extend.
- `app/Domain/Shared/Auth/DeniesPartners.php` and the `PartnerIsolated` scope used by models in `app/Domain/Clients`, `Payments` and `Tasks`: the fail-closed Partner mechanism to reuse.
- `app/Filament/Resources/ClientResource` with `Pages/{List,Create,Edit,View}`: the pattern for separate view and edit screens.
- Spatie activitylog (metadata only), medialibrary and tags are already installed; the tag type `knowledge` is added in the data model.
- `kokpit_search_text()` and the accent-insensitive search migration (`2026_10_30_000100_add_accent_insensitive_search.php`) are the base for Phase 22, not this phase.

### Established Patterns
- Domain code lives in `app/Domain/<Area>/` (Actions, Enums, Models, Policies, Queries); the new area is `app/Domain/Knowledge/`.
- Filament resources in `app/Filament/Resources` define navigation label, group and icon through `getNavigationLabel/Group/Icon`; Partner screens live in `app/Filament/Partner`.
- All user-facing text is Czech in `lang/cs`; code, enums, routes and tests are English.
- The Partner audit (`Audit` suite) fails when a new route, screen or Livewire component is not declared in the matrix.
- Pest suites: Unit, Arch, Feature, Isolation, Concurrency, Audit; migrations and the full test run happen after merge into the HEAD branch, not in a worktree.

### Integration Points
- New navigation group "Znalosti" (first entry General knowledge) in the Admin panel.
- New morph aliases, `TagType::Knowledge`, policies registered with the Partner matrix declarations.
- Activity log: records metadata only (ids, version number, flags), never title or body.

</code_context>

<specifics>
## Specific Ideas

No specific requirements beyond the decisions above. The five-type callout set mirrors GitHub alerts so articles stay readable outside Kokpit.

</specifics>

<deferred>
## Deferred Ideas

None. The discussion stayed within phase scope. The retained open items in `STATE.md` (version retention and redaction in Phase 19, image library in Phase 20, nested resource spike in Phase 21, search configuration in Phase 22, Partner share decisions in Phase 23) stay with their phases.

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" (2026-10-14): matched on keywords but is a reports UI task unrelated to knowledge articles; stays in the v1 carry-over list.

</deferred>

---

*Phase: 18-Knowledge Articles and Secret Guard*
*Context gathered: 2026-11-25*
