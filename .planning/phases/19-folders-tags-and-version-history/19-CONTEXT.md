# Phase 19: Folders, Tags and Version History - Context

**Gathered:** 2026-11-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin can organise articles in a folder tree with tags and client or project links, and can inspect, compare, restore and redact earlier versions of any article (ORG-01 to ORG-04, VER-02 to VER-05).

The schema already exists from Phase 18: `knowledge_folders` (parent_id, cycle and depth trigger KP003, `MAX_DEPTH = 6`, RESTRICT foreign keys), `knowledge_articles` (`folder_id`, `client_id`, `project_id` with composite FK so a project must belong to the article's client), and the immutable `knowledge_article_versions` (full snapshots, trigger KP001; the Phase 18 migration notes that Phase 19 redaction replaces the trigger function with a variant that admits a transaction-local setting). This phase builds the screens, Actions and policies on top.

Out of this phase: images (Phase 20), incidents and retrospectives (Phase 21), search (Phase 22), client sharing and AI export (Phase 23).

</domain>

<decisions>
## Implementation Decisions

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
- Exact tree component, Czech wording, icons, the redaction marker text (whole body or only matching lines), diff library choice, how the transaction-local setting for the trigger variant is named.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 19" and the v1.1 mapping notes
- `.planning/REQUIREMENTS.md` — ORG-01 to ORG-04, VER-02 to VER-05 (also SEC-01 restore clause)
- `.planning/phases/18-knowledge-articles-and-secret-guard/18-CONTEXT.md` — D-01 to D-14, especially the secret guard, stale-save check and full-snapshot versions
- `.planning/STATE.md` §Blockers/Concerns — Phase 19 retention and redaction item, cross-cutting body scrub from Sentry and logs

### Research
- `.planning/research/PITFALLS.md` — versions and restore, purge/redact, folder tree integrity, secrets in five places
- `.planning/research/ARCHITECTURE.md` — `app/Domain/Knowledge/` layout, write seam
- `.planning/research/SUMMARY.md` — organisation and version history phase notes, diff library options

### Existing schema and code
- `database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php` — immutability trigger and the planned redaction variant
- `database/migrations/2026_11_25_000300_create_knowledge_folders_table.php` — tree guard, depth cap
- `database/migrations/2026_11_25_000100_create_knowledge_articles_table.php` — folder, client, project constraints
- `app/Domain/Knowledge/Actions/SaveArticle.php`, `app/Domain/Knowledge/Guard/` — single save path and secret scanner
- `tests/Support/PartnerMatrix.php`, `tests/Support/RouteRules.php` — every new page, route and Livewire component declared in the same pull request
- `CONTRIBUTING.md` — repository hygiene

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `KnowledgeFolder` model with `MAX_DEPTH`, `KnowledgeArticleVersion` model, `ArticleKind`, `StaleArticle`, `SecretGuardRefused` exceptions.
- `SaveArticle` Action (lock, stale check, guard, version row, activity log in one transaction): restore reuses it.
- Spatie tags with `TagType::Knowledge`, already registered in Phase 18.
- `app/Filament/Resources/ClientResource/RelationManagers/` and `ProjectResource`: pattern for the Knowledge tab relation managers.
- `app/Filament/Resources/KnowledgeArticleResource` with its pages: list screen to extend with tree panel and filters.

### Established Patterns
- Domain Actions in `app/Domain/Knowledge/Actions` (planned `MoveFolder`, `RestoreVersion`, `RedactVersion` per research).
- All UI text Czech in `lang/cs/knowledge.php`; code, tests, routes English.
- Partner fail-closed: new Knowledge tabs on client and project pages must not render for Partner and must be declared in the Partner matrix.
- Migrations and the full test run happen after merge into the HEAD branch.

### Integration Points
- General knowledge list (tree panel, tag and folder filters), article resource (new Versions page), Client and Project resources (Knowledge relation manager), a new migration replacing the version immutability function with the redaction variant.

</code_context>

<specifics>
## Specific Ideas

No specific references beyond the decisions above. Restore and redaction must never log or display secret values or reason text in the activity log.

</specifics>

<deferred>
## Deferred Ideas

None. Version pruning stays an optional later command (see D-10).

</deferred>

---

*Phase: 19-Folders, Tags and Version History*
*Context gathered: 2026-11-25*
