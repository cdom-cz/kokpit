---
phase: "17"
slug: "task-project-and-search-ux-refinements"
status: verified
threats_open: 0
asvs_level: 1
created: "2026-10-29"
---

# Phase 17 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Admin form / payload -> UpdateTask | crafted or partial payload may omit or garble the board-visibility flag | display flag |
| Partner session -> UpdateTask, CreateTask, tasks table | a Partner must never change where a task shows; the flag is Partner-readable | display flag |
| page HTML -> browser | a rendered signed PDF link leaves the server with the page | signed file URL |
| browser -> DocumentFileController / previewCommentImage | document ids and requests are client-controlled | document ids, file bytes |
| uploaded file -> browser rendering | a file is untrusted content | file content |
| stored rich text -> page HTML | descriptions and comments are untrusted markup | rich text |
| browser -> SignalDay / ViewProject / ViewTask (/livewire/update) | method arguments and modal data are client-controlled | task, project, signal ids, status, time context |
| typed term -> suggestion SQL | the term reaches a LIKE comparison | search term |
| repository -> public readers | docs and tests are public (AGPL) | fixtures, text |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-17-01 | Tampering | update payload without the flag key | low | mitigate | UpdateTask writes the flag only for a present, non-null key; TaskUpdateTest | closed |
| T-17-02 | Elevation of privilege | Partner setting the flag | medium | mitigate | UpdateTask Gate `update`; CreateTask explicit attribute list; TaskUpdateTest | closed |
| T-17-03 | Information disclosure | Partner reading `tasks.hidden_on_board` | low | accept | display flag, pinned in PartnerSafeColumnsTest; API resource is an allow-list | closed |
| T-17-04 | Information disclosure | signed PDF link in list and thread HTML | medium | accept | owner decision "Signed PDF link exception" (17-CONTEXT.md); 120 s TTL, user-bound, scoped, policy-checked, nosniff | closed |
| T-17-05 | Information disclosure | IDOR via forged document id in previewCommentImage | high | mitigate | commentDocument() scoped lookup, UUID check, Gate `view`; CommentAttachmentsTest | closed |
| T-17-06 | Tampering | active file (SVG, HTML) in the lightbox | high | mitigate | isInlineImage allowlist (PNG, JPEG, GIF, WebP); controller serves others as octet-stream with nosniff | closed |
| T-17-07 | Information disclosure | Partner seeing hidden or internal files | high | mitigate | Partner-scoped tables and relation, `allows('view')`; DocumentVisibilityTest, CommentAttachmentsTest | closed |
| T-17-08 | Tampering | stored XSS via rich text | high | mitigate | RichText::render() everywhere, no html()/markdown(); RichTextRenderingTest | closed |
| T-17-09 | Information disclosure | real data in docs and fixtures | low | mitigate | fictional fixtures; check-sensitive.sh and gitleaks hook | closed |
| T-17-10 | Tampering | forged task id linked to a signal | medium | mitigate | AddSignalTask re-reads via LinkableTasks::find(); #[Locked] pickedTaskId; SignalTaskLinkTest | closed |
| T-17-11 | Tampering / Spoofing | forged title on a real task | medium | mitigate | #[Locked] pickedTaskLabel composed from the re-read task; SignalTaskLinkTest | closed |
| T-17-12 | Tampering | LIKE wildcard abuse | medium | mitigate | bound value, addcslashes, 10-row limit; SignalTaskLinkTest | closed |
| T-17-13 | Elevation of privilege | Partner reaching SignalDay or the Action | high | mitigate | RequiresAdmin, authorizeActor Gate; LivewireComponentContractTest, SignalScreensTest | closed |
| T-17-14 | Information disclosure | another Admin's planner rows | low | mitigate | OwnedByUser scope, task_id not fillable; SignalIsolationTest | closed |
| T-17-15 | Tampering | SQL in the new raw order | low | mitigate | qualifyColumn() and bound enum value | closed |
| T-17-16 | Information disclosure | Partner seeing search results | low | mitigate | resources stay AdminOnly; GlobalSearchTest Partner case | closed |
| T-17-17 | Information disclosure / Tampering | IDOR via signal row in toggleTaskTimer | medium | mitigate | owner-scoped SignalTask query, id from the row, Gate `view`; SignalTaskLinkTest, SignalIsolationTest | closed |
| T-17-18 | Tampering | timer on a Done or archived task from a stale row | low | mitigate | toggleTaskTimer refuses, StartTimer re-checks context; SignalTaskLinkTest | closed |
| T-17-19 | Elevation of privilege | Partner reaching the timer via the planner | high | mitigate | RequiresAdmin, StartTimer Gate `create` (AdminOnlyPolicy) | closed |
| T-17-20 | Tampering | forged project_id in the locked New task modal | medium | mitigate | project read from the record, CreateTask re-checks selectable() under a share lock; ProjectViewActionsTest | closed |
| T-17-21 | Elevation of privilege | non-Admin calling changeStatus | high | mitigate | action body aborts unless canAccess/canEdit and not trashed; Admin-only resource | closed |
| T-17-22 | Tampering | invalid status value | low | mitigate | ProjectStatus::tryFrom() before UpdateProject; options validation | closed |
| T-17-23 | Tampering | Partner task in a Done project via forged id | low | mitigate | Partner options exclude Done; PartnerTaskResourceTest | closed |
| T-17-24 | Tampering | forged context in addTime | medium | mitigate | context merged from the task record; CreateTimeEntry consistency checks; TaskAddTimeTest | closed |
| T-17-25 | Elevation of privilege | non-Admin calling addTime | high | mitigate | abort_unless(canAccess && canCreate), Gate `create`; TimeLeakTest | closed |
| T-17-26 | Information disclosure | real data in hand-over text or fixtures | medium | mitigate | fictional fixtures; sensitive-content hook | closed |
| T-17-SC | Tampering | Composer or npm installs in Phase 17 | high | mitigate | no phase commit changed composer.json, composer.lock, package.json or lock files | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-17-01 | T-17-03 | Partner-readable display flag with no money or internal data; pinned in PartnerSafeColumnsTest | planning decision | 2026-10-29 |
| AR-17-02 | T-17-04 | Signed PDF link exception: a copied link works for at most 120 s and only for the signed-in user; a stale open page shows 403 | owner (17-CONTEXT.md) | 2026-10-29 |

Observations from the audit (informational, not threats): T-17-23 relies on Filament's Select option validation and CreateTask keeps `selectable()` without `open()`; T-17-21 has no dedicated Partner `changeStatus` test, the guard sits in the action body.

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-29 | 27 | 27 | 0 | gsd-security-auditor (ASVS 1, code and test reading; DB test suite runs after merge in DDEV) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-29
