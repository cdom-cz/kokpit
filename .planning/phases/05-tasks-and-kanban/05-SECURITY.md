---
phase: "5"
slug: tasks-and-kanban
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
created: "2026-10-09"
---

# Phase 5 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Verified by the gsd-security-auditor against the implementation (not the plan text); the pinning tests were re-run green.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Partner browser to panel | Partner (client account) uses the same panel as the Admin, with a restricted view | Task title/description/comments of the own client only; forged Livewire payloads |
| Partner scope in the data layer | Global query scopes and policies decide which rows a Partner can load | Tasks, comments (non-internal only); never task_billing, checklist, tags, rates, prices |
| Admin-only side tables | `task_billing`, `task_checklist_items`, internal comments | Prices, rate overrides, estimates, internal notes |
| Rich text input | Admin and Partner write HTML through the editor | Stored and rendered HTML (XSS) |
| Board mutation | Drag and drop sends card id, target status and index | Status and position writes under one advisory lock |
| Queue / notification worker | Notifications are built for a recipient and sent by mail and bell | Task and comment excerpts, links, recipient identity |
| Dependency supply chain | One new direct dependency (`spatie/eloquent-sortable`) | Package code, license |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-05-01 | Information disclosure | Partner reads tasks of another client or hidden project | high | mitigate | Partner filter through the scoped Project query (`Task.php`), `TaskPolicy` rechecks client, canary fixture | closed |
| T-05-02 | Tampering | Forged people, Partner status/priority | high | mitigate | `CreateTask` drops Partner status/priority/people; allowed set recomputed in `TaskPeople`; no `exists:` rule | closed |
| T-05-03 | Tampering | Duplicate or skipped KEY-N | medium | mitigate | `nextTaskNumber` inside the transaction, unique indexes, 8x25 process proof with mutation run | closed |
| T-05-04 | Tampering | Project key changed after tasks exist | medium | mitigate | DB trigger `projects_key_frozen_guard` (KP002), Action error, disabled form field | closed |
| T-05-05 | Denial of service | Deadlock between creation and key update | low | mitigate | Fixed lock order board, project FOR SHARE, counter | closed |
| T-05-06 | Information disclosure | Global search leaks to a Partner | high | mitigate | Search opt-in per resource; only the Admin-only TaskResource opts in | closed |
| T-05-07 | Information disclosure | Partner opens Admin task routes | high | mitigate | AdminOnly access rule, route walk entry `tasks` | closed |
| T-05-08 | Information disclosure | Quick-create picker shows archived/other projects | low | mitigate | `selectable()` scope, neutral error on recheck | closed |
| T-05-09 | Tampering / Elevation | Stored XSS in descriptions | high | mitigate | Strict `RichText` sanitiser on write and render, img dropped | closed |
| T-05-10 | Tampering | Forged editor upload | medium | mitigate | `fileAttachments(false)` on all five editors, pinned by test | closed |
| T-05-11 | Repudiation | Task changes untraced | low | mitigate | Activity allowlist on `Task`, Admin-only history | closed |
| T-05-12 | Tampering | Forged parent (sub-subtask, cross-project) | medium | mitigate | Composite FK `tasks_parent_fk`, parent re-read under lock | closed |
| T-05-13 | Denial of service | Archive orphans subtasks / restore collides | low | mitigate | Archive guard, locked re-append on restore | closed |
| T-05-14 | Information disclosure | Checklist reaches a Partner | medium | mitigate | `DeniesPartners` + AdminOnlyPolicy, canary fixture | closed |
| T-05-15 | Information disclosure | Task price/rate/estimate read by a Partner | high | mitigate | `TaskBilling` DeniesPartners, `tasks` column pin | closed |
| T-05-16 | Tampering | Money rounding / wrong currency | medium | mitigate | `ProjectInput::money`, DB CHECKs | closed |
| T-05-17 | Information disclosure | Effective rate shown to a Partner | high | mitigate | Resolver refuses any caller except the Admin or a system run | closed |
| T-05-18 | Repudiation | Billing changes untraced | low | mitigate | Billing allowlist without `internal_note` | closed |
| T-05-19 | Information disclosure | Internal comment read by a Partner | high | mitigate | `TaskComment` Partner scope `is_internal = false`, policy, canary twin | closed |
| T-05-20 | Tampering | Partner forges `is_internal` | medium | mitigate | `AddTaskComment` honours the flag for the Admin only, mutation-checked | closed |
| T-05-21 | Tampering / Elevation | Stored XSS in comments | high | mitigate | Clean on write, `RichText::render` on every render path | closed |
| T-05-22 | Tampering | Forged board move | high | mitigate | Admin-only board page, UUID + scoped lookup + Gate update, status whitelist 422 and DB CHECK | closed |
| T-05-23 | Tampering | Lost or duplicated positions | medium | mitigate | Advisory lock, re-read under lock, deferred exclusion constraint | closed |
| T-05-24 | Denial of service | Board N+1 / huge snapshot | low | mitigate | Eager loads, Done cap from config, query-count test at 200 cards | closed |
| T-05-25 | Information disclosure | Forged preview argument | medium | mitigate | UUID check, scoped `findOrFail`, `Gate::authorize('view')` | closed |
| T-05-26 | Tampering | Board lock removed unnoticed | medium | mitigate | Two-process test with no-lock mutation double | closed |
| T-05-27 | Elevation | Partner sets status/priority/assignee/tags in a forged create payload | high | mitigate | `CreatePartnerTask` forwards title and description only; see G-1 | closed |
| T-05-28 | Information disclosure | IDOR / existence oracle on Partner routes | high | mitigate | Scoped record binding, scoped options, neutral error, route walk | closed |
| T-05-29 | Information disclosure | Tags, checklist, billing, history, internal comments on Partner pages | high | mitigate | Pinned Partner builders, comments the only Partner relation | closed |
| T-05-30 | Elevation | Partner changes priority/status via escalation | medium | mitigate | `EscalateTask`/`ClearEscalation` write only the escalation pair | closed |
| T-05-31 | Tampering / Elevation | XSS in Partner comments | high | mitigate | Same clean-on-write and RichText render for every actor | closed |
| T-05-32 | Repudiation | Racing or repeated escalations | low | mitigate | Row lock, already-escalated refusal | closed |
| T-05-33 | Elevation | Another user's preferences / mass assignment | medium | mitigate | Preference column not fillable, owner-only Action | closed |
| T-05-34 | Information disclosure | Partner bell shows others' rows | high | mitigate | Bell reads the signed-in user's notifications; leak test | closed |
| T-05-35 | Tampering | Preference re-enables internal comments to Partners | high | mitigate | Recipients chosen before preferences; constructor guard; preferences only narrow | closed |
| T-05-36 | Information disclosure | Internal comment notified/quoted to a Partner | high | mitigate | Blocked in three places, no excerpt for internal comments | closed |
| T-05-37 | Information disclosure | Worker reloads models without scope | medium | mitigate | Scalar-only constructors, afterCommit, no-user render tests | closed |
| T-05-38 | Denial of service | Partner floods the Admin with notifications | low | accept | No rate limit; per-user channel switches are the only control (Pitfall 9) | closed (accepted) |
| T-05-39 | Information disclosure | Body carries billing, checklist, tags, internal text | high | mitigate | Allowlisted scalar bodies; NotificationLeakTest (391 assertions) | closed |
| T-05-40 | Information disclosure | Partner gets an Admin link | medium | mitigate | Link chosen per recipient audience, tested | closed |
| T-05-41 | Information disclosure | Real data in docs | medium | mitigate | `scripts/check-sensitive.sh --all` clean, hooks and gitleaks | closed |
| T-05-42 | Elevation | Non-assignee Partner clears escalation | medium | mitigate | `clearEscalation` for the assignee only, authorised on the locked row | closed |
| T-05-43 | Information disclosure | Escalation reaches an ineligible Partner | medium | mitigate | Eligibility check in `addCandidate` (active, same client, may read) | closed |
| T-05-SC | Tampering | Supply chain (all 17 plans) | low | accept / mitigate | Only `spatie/eloquent-sortable ^5.0` added; `composer check-licenses`: 210 packages allowed | closed |
| T-05-44 | Tampering / Information disclosure | Partner injects HTML/Markdown (links, styles, remote images) into the Admin's bell and notification mails via task title or own display name | medium | mitigate | Closed by plan 05-19: every interpolated value is escaped once in the `TaskNotification` base class (`final` `toMail` and `toDatabase`; mail lines through `escapeMarkdown()`, bell through `e()`); `tests/Isolation/NotificationMarkupTest.php` covers every class and audience with a DOM-level markup canary and four recorded mutation runs | closed |

*Status: open · closed*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*

### Findings outside the register

- **U-1 (= T-05-44), medium — CLOSED by plan 05-19:** reproduced by the auditor without writes. The bell body (`TaskCreatedNotification::bellBody`, comment and escalation bell bodies) puts the task title and the actor's name in unescaped; Filament renders it through its permissive global sanitiser (links, `style`, remote `img`). The mail lines (`lang/cs/kokpit.php` `mail_line` strings) do the same. A Partner can set their own display name on the profile page. No script execution, but phishing links, a full-screen style overlay and a tracking image can reach the Admin. `TaskChangedNotification::bellBody` already escapes and is the pattern to copy.
- **G-1, low (defence in depth) — CLOSED by plan 05-18:** `CreateTask` now drops `tags` from a Partner payload before any input is parsed (`unset(... $data['tags'])`), proven by a Partner/Admin pair of tests and a mutation run. Original finding: `CreateTask` drops a Partner's status, priority, assignee and requester but not `tags` (`CreateTask.php` around lines 100-108 and 160-162). Not reachable today (`CreatePartnerTask` forwards only title and description; test and mutation run prove it). Add `tags` to the Partner drop list before Phase 7 adds a task API.

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-05-01 | T-05-38 | No rate limit on notifications a Partner triggers; each recipient can switch channels per event (D-15). Documented in the 05-15 threat model and in the 05-17 SUMMARY "For the owner". | plan register (owner confirmation pending in 05-17 SUMMARY) | 2026-10-09 |
| AR-05-02 | T-05-SC | Sixteen plans add no package; plan 05-10 adds `spatie/eloquent-sortable` (MIT, already locked) under the license gate. | plan register | 2026-10-09 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-09 | 44 | 44 | 0 (blocking); 1 unregistered medium finding (T-05-44) open below threshold | gsd-security-auditor (opus), orchestrator |
| 2026-10-09 | 45 | 45 | 0 (T-05-44 and G-1 closed by gap-closure plans 05-19 and 05-18) | orchestrator (L1 grep check of the mitigations plus the green full suite) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed (blocking threshold: high)
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-09 for the blocking gate. T-05-44 / U-1 (medium) and G-1 (low) were closed by gap-closure plans 05-19 and 05-18 and re-checked on 2026-10-09.

## Security Audit 2026-10-09

| Metric | Count |
|---|---|
| Threats found | 45 |
| Closed | 45 |
| Open | 0 |
