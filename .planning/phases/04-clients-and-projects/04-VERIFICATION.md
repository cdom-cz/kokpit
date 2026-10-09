---
phase: 04-clients-and-projects
verified: 2026-10-09T09:00:00Z
status: passed
score: 5/5 must-haves verified
covered_files:
  - .planning/phases/04-clients-and-projects/04-01-PLAN.md
  - .planning/phases/04-clients-and-projects/04-01-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-02-PLAN.md
  - .planning/phases/04-clients-and-projects/04-02-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-03-PLAN.md
  - .planning/phases/04-clients-and-projects/04-03-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-04-PLAN.md
  - .planning/phases/04-clients-and-projects/04-04-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-05-PLAN.md
  - .planning/phases/04-clients-and-projects/04-05-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-06-PLAN.md
  - .planning/phases/04-clients-and-projects/04-06-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-07-PLAN.md
  - .planning/phases/04-clients-and-projects/04-07-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-08-PLAN.md
  - .planning/phases/04-clients-and-projects/04-08-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-09-PLAN.md
  - .planning/phases/04-clients-and-projects/04-09-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-10-PLAN.md
  - .planning/phases/04-clients-and-projects/04-10-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-11-PLAN.md
  - .planning/phases/04-clients-and-projects/04-11-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-12-PLAN.md
  - .planning/phases/04-clients-and-projects/04-12-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-13-PLAN.md
  - .planning/phases/04-clients-and-projects/04-13-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-14-PLAN.md
  - .planning/phases/04-clients-and-projects/04-14-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-15-PLAN.md
  - .planning/phases/04-clients-and-projects/04-15-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-16-PLAN.md
  - .planning/phases/04-clients-and-projects/04-16-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-17-PLAN.md
  - .planning/phases/04-clients-and-projects/04-17-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-18-PLAN.md
  - .planning/phases/04-clients-and-projects/04-18-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-19-PLAN.md
  - .planning/phases/04-clients-and-projects/04-19-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-20-PLAN.md
  - .planning/phases/04-clients-and-projects/04-20-SUMMARY.md
  - .planning/phases/04-clients-and-projects/04-21-PLAN.md
  - .planning/phases/04-clients-and-projects/04-21-SUMMARY.md
  - app/Domain/Clients/Actions/AcceptInvitation.php
  - app/Domain/Clients/Actions/InvitePartner.php
  - app/Domain/Clients/Ares/AresClient.php
  - app/Domain/Clients/Models/Client.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Projects/Actions/UpdateProject.php
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Projects/Models/ProjectBilling.php
  - app/Domain/Projects/ProjectKeySuggester.php
  - app/Domain/Shared/Models/Tag.php
  - app/Filament/Partner/Resources/PartnerProjectResource.php
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ProjectResource.php
covered_digest: "v3:sha256:7a0b97976c6218cc4d69c831503a92f00e913f5b21ebac6c0bff31a4456bcae1"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 5/5
  gaps_closed:
    - "Human check 1: ARES button on a Czech client form (UAT 04-UAT.md test 1, pass)"
    - "Human check 2: Partner invitation end to end through Mailpit (UAT test 2, pass)"
    - "Human check 3: Password reset for Admin and Partner through Mailpit (UAT test 3, pass)"
  gaps_remaining: []
  regressions: []
gaps: []
deferred: []
noted_risks:
  - id: WR-01
    severity: warning-medium
    note: "Project Partner scope relies on the SoftDeletes scope for 'not archived'. Re-read in Project.php: constrainForPartner has no deleted_at condition. No path exposes data today (Partner list, view and the scope-stripped Admin route binding all end in 403/404, tests green); Phase 5 Task and Tag scopes inherit the same dependence. Recommended one-line fix plus a withTrashed() Partner test."
  - id: WR-03
    severity: warning-medium
    note: "Plaintext invitation token sits in the queued notification payload (jobs, failed_jobs). Single-use, 7-day signed link; the database table itself stores only the SHA-256 hash."
  - id: WR-02
    severity: warning-low
    note: "Client currency vs project money currency checked outside a row lock (race on a single-admin instance)"
  - id: WR-04
    severity: warning-low
    note: "Admin send-reset action reports success when nothing was sent (Partner of an archived client)"
  - id: WR-05
    severity: warning-low
    note: "Project Actions and parts of ClientInput do not own their field validation; non-form callers get raw DB errors (key '' passes the new freeze precheck)"
  - id: WR-06
    severity: warning-low
    note: "Reset and login e-mail lookup is case-sensitive"
  - id: WR-07
    severity: warning-low
    note: "Phase 5 key-freeze precheck in UpdateProject reads tasks through the fail-closed PartnerScope; with no signed-in user it is always false and only the projects_key_frozen_guard trigger fires. Does not affect Phase 4 criteria."
  - id: WR-08-09
    severity: warning-low
    note: "Two weak assertions in tests/Feature/Repo/RepositoryFilesTest.php (unguarded planning-file read, substring-only class-name check)"
---

# Phase 4: Clients and Projects Verification Report

**Phase Goal:** Admin can maintain clients, contacts and projects with their billing terms, and invite a client to a restricted Partner account that sees only projects flagged visible to it
**Verified:** 2026-10-09
**Status:** passed
**Re-verification:** Yes, after the human UAT (04-UAT.md, 3 of 3 passed). The previous report (2026-10-08) was `human_needed` with 5/5 truths verified and no gaps.

## Verification Approach

SUMMARY.md claims were not used as evidence. This pass re-checked the code on disk and re-ran the tests myself:

- Re-read `Project::constrainForPartner`, `Project::scopeSelectable`, the Partner project resource, the `ProjectBilling` model, the invitation, ARES and user-lockout migrations and the Action files that changed since the first verification.
- Listed every Phase 4 code change since the first review (commit 487fd4b): Phase 5 edits to `Project`, `UpdateProject`, `ProjectResource`, `User` (key freeze, task relation, notification preferences) and new Partner task resources. None alters a Phase 4 behaviour; the Partner project, invitation, ARES and client paths are untouched.
- Re-ran the Phase 4 suites in DDEV: `ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Projects tests/Isolation tests/Unit/Ares tests/Unit/Projects tests/Arch tests/Feature/Schema` returned **803 passed, 10278 assertions, exit 0** (up from 701 because Phase 5 added tests to the same directories). The full suite (2015 tests, 0 failures) was reported by the orchestrator and not re-run by me.

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin creates, edits and archives clients with billing data, stage, currency, rate, payment terms, invoice e-mail and language, online-payment flag, tags and several contacts with primary and billing flags; archived clients disappear from pickers and lists but can be restored | VERIFIED | Migrations `2026_10_09_000100_create_clients_table.php` and `000500_create_contacts_table.php` hold the fields with CHECK constraints and the partial unique index `contacts_one_primary_per_client` (also re-confirmed by the test "refuses a second primary row ... with 23505"). Actions present: `CreateClient`, `UpdateClient`, `ArchiveClient`, `RestoreClient`, `CreateContact`, `UpdateContact`, `SetPrimaryContact`, `DeleteContact`. `Project::scopeSelectable` excludes archived projects and projects of archived clients. `ClientResourceTest`, `ClientArchiveTest`, `ContactsTest`, `ClientDefaultsTest`, `ClientTagsAndHistoryTest` green in my run. |
| 2 | Fictional company ID plus the ARES button fills the client form; on error or timeout the message appears next to the field and the form is unchanged | VERIFIED | `ClientResource::aresAction()` (line 517) calls `AresClient::lookup`, converts failures to a field-level `ValidationException`, writes `$set(...)` only after success, visible only for CZ. `AresClient` has timeouts, limited retries, cache and rate limit; `AresClientTest` and `AresFormActionTest` green. Live behaviour confirmed by UAT test 1 (pass: fields fill with the highlight; blocked network and unknown ID show the error next to the field with all fields unchanged). |
| 3 | Admin creates projects with name, status, description, dates, priority, tags, billing type, hourly rate, fixed price and time estimate; a unique 2-6 letter uppercase key is suggested from the name; a duplicate key is rejected | VERIFIED | `projects` migration: `projects_key_unique`, `projects_key_check ^[A-Z]{2,6}$`, status, priority and date CHECKs. `project_billing` 1:1 table holds billing type, rate, fixed price, estimate. `CreateProject`, `UpdateProject`, `ProjectInput`, `ProjectKeySuggester` present and used by `ProjectResource`. `ProjectKeyTest`, `ProjectBillingTest`, `ProjectActionsTest`, `ProjectKeySuggesterTest`, `EstimateHoursTest` green. The Phase 5 key freeze (`UpdateProject`, trigger `projects_key_frozen_guard`) only restricts a key that already has tasks and does not weaken this criterion. |
| 4 | From the client detail Admin invites a Partner account by e-mail, and the invited person sets a password and logs in | VERIFIED | `InvitePartner` (85 lines), `AcceptInvitation` Action (119 lines), `InvitationMail`/`PartnerInvitation` notification, `Filament/Pages/Auth/AcceptInvitation.php`, DB backstops `client_invitations_open_email_unique` and `users_email_lower_unique` (grep-confirmed in migrations). `AcceptInvitationTest` includes "uses an invitation once: the second submit of the same link is neutral" and the end-to-end sign-in test, green. Real mail round trip confirmed by UAT test 2 (pass: mail arrives in Mailpit, accept page shows the invitee e-mail, password of 12+ chars signs the Partner in and shows only "Moje projekty") and UAT test 3 (pass: reset mail for Admin and Partner, Partner lands in the panel, Admin still gets the 2FA challenge). |
| 5 | A Partner sees only projects of their own client that are flagged client-visible, never sees rates, prices or estimates in lists, details, selects or search, and never sees another client's canary data | VERIFIED | `Project::constrainForPartner` = own client AND `client_visible` AND client not archived (EXISTS subquery), fail-closed `PartnerScope`. `projects` holds Partner-safe columns only; money and estimate live in `ProjectBilling` (`DeniesPartners`, confirmed in the model). `PartnerProjectResource` has `isGloballySearchable = false`, `canCreate` false and no reference to billing, rate, price or estimate (grep returns none). `PartnerSafeColumnsTest`, `PartnerProjectVisibilityTest`, `PartnerTagVisibilityTest`, `PartnerProjectResourceTest`, `PartnerLockoutTest`, `DeniedModelsTest`, `RouteWalkTest`, `CanaryRegistryTest` green. Caveat WR-01 below: "not archived" for the project itself comes from the SoftDeletes scope, not from `constrainForPartner`; no exposure path found. |

**Score:** 5/5 truths verified (0 behavior-unverified, 0 overrides)

### Human Verification (closed by UAT)

| # | Item | Result | Source |
|---|------|--------|--------|
| 1 | ARES button on a Czech client form (browser) | pass | 04-UAT.md test 1 |
| 2 | Partner invitation end to end through Mailpit | pass | 04-UAT.md test 2 |
| 3 | Password reset for Admin and Partner through Mailpit | pass | 04-UAT.md test 3 |

UAT summary: total 3, passed 3, issues 0, pending 0, skipped 0, blocked 0. No human verification item remains open.

### Locked Decisions Honoured (04-CONTEXT.md)

Unchanged from the first verification, re-spot-checked where Phase 5 touched adjacent code: D-01 to D-16 are honoured. Notes: D-04 has the WR-04 false-success path (UX only); D-15 currency match is enforced in Actions only (WR-02).

### Required Artifacts and Key Links

| Artifact / link | Status | Details |
|-----------------|--------|---------|
| Client, Contact, ClientInvitation models and migrations 000100/000200/000500/000600 | VERIFIED | Exist, substantive, wired into resources and Actions, DB constraints present |
| Project, ProjectBilling models and migrations 000300/000400 | VERIFIED | Substantive and wired |
| `ClientResource`, `ProjectResource` with pages and relation managers | VERIFIED | Admin-only, form state goes through domain Actions |
| `PartnerProjectResource` | VERIFIED | Read-only, Partner-safe names only, registered in the Partner namespace |
| Accept page -> `AcceptInvitation` Action -> `User` | WIRED | Signed route built by the invitation mail; exercised by UAT 2 |
| `ClientResource::aresAction` -> `AresClient` | WIRED | Field-level `ValidationException`; exercised by UAT 1 |
| `Tag` Partner scope -> `Project::query()` | WIRED | Inherits the project scope (and its WR-01 caveat) |
| `users.client_id` FK to `clients` (RESTRICT) | WIRED | Migration 000200 |

### Data-Flow Trace (Level 4)

| Artifact | Data variable | Source | Real data | Status |
|----------|---------------|--------|-----------|--------|
| `PartnerProjectResource` list and infolist | Project rows | Eloquent `Project` with `PartnerScope` | Yes, DB query | FLOWING |
| `ClientResource` ARES fields | name, address, tax ID | `AresClient` HTTP GET | Yes, live behaviour confirmed by UAT 1 | FLOWING |
| `ProjectResource` billing section | `project_billing` row | `CreateProject`/`UpdateProject` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 4 feature, isolation, arch, schema and unit suites | `ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Projects tests/Isolation tests/Unit/Ares tests/Unit/Projects tests/Arch tests/Feature/Schema` | 803 passed, 10278 assertions, exit 0 | PASS |
| Debt markers (TBD, FIXME, XXX, TODO, HACK, PLACEHOLDER) in Phase 4 domain, Filament, auth-page and identity files | grep over the tracked files | no matches | PASS |
| Full suite | not re-run by me | orchestrator reports 2015 tests, 0 failures | SKIPPED (relied on orchestrator) |

### Probe Execution

No probe scripts are declared by the Phase 4 plans. SKIPPED.

### Requirements Coverage

All nine IDs appear in PLAN frontmatter (`requirements:` of 04-01 to 04-21) and in REQUIREMENTS.md, marked complete and mapped to Phase 4. None are orphaned.

| Requirement | Source plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| US-02 | 04-01, 04-16..04-21 | Admin creates a Partner from the client detail with an e-mail invitation | SATISFIED | Truth 4, UAT 2 and 3 |
| CL-01 | 04-01, 04-09, 04-10, 04-11, 04-21 | Clients with billing data, stage, currency, rate, terms, invoice e-mail, language, online-payment flag | SATISFIED | Truth 1 |
| CL-02 | 04-12, 04-13, 04-21 | Several contacts with primary and billing flags | SATISFIED | Truth 1 |
| CL-04 | 04-14, 04-15, 04-21 | ARES synchronous button, error next to the field, form unchanged | SATISFIED | Truth 2, UAT 1 |
| CL-05 | 04-01, 04-02, 04-11, 04-21 | Soft-delete archive, tags on clients | SATISFIED | Truth 1 |
| PR-01 | 04-02..04-08, 04-21 | Projects with name, status, description, dates, priority, tags (files are Phase 9) | SATISFIED for the Phase 4 scope | Truth 3 |
| PR-02 | 04-02, 04-06..04-08, 04-21 | Unique 2-6 letter key suggested from the name (freeze after first task is Phase 5, already implemented there) | SATISFIED for the Phase 4 scope | Truth 3 |
| PR-03 | 04-06..04-08, 04-21 | Billing type, hourly rate, fixed price, estimate where Partner cannot read them | SATISFIED | Truths 3 and 5 |
| PR-04 | 04-02..04-05, 04-08, 04-21 | Client-visibility flag controls Partner access | SATISFIED | Truth 5 |

### PLAN-declared Prohibitions

Every `must_haves.prohibitions` item in the plans (04-01, 04-05, 04-09, 04-13, 04-17) is `verification: test` with enforcing tests present and green in my run:

| Prohibition | Enforcement | Status |
|-------------|-------------|--------|
| Partner must not read any client row (D-06) | `DeniedModelsTest`, `AdminOnlyPolicy` | VERIFIED |
| Audience::Guest must not pass AccessRules for anyone | `PanelRegistryTest` and access-rule tests | VERIFIED |
| Partner must not open a client list, form or record route | `RouteWalkTest`, `ClientResourceTest` | VERIFIED |
| No client with two primary contacts or none while contacts exist | `contacts_one_primary_per_client` index, `ContactsTest` (23505) | VERIFIED |
| Invitation page must not reveal whether an invitation exists, expired, revoked or accepted | `AcceptInvitationTest` neutral-message cases | VERIFIED |

### Anti-Patterns Found

None blocking. No debt markers, no stubs and no placeholder returns in the scanned Phase 4 files.

### Code Review Disposition (04-REVIEW.md, 9 warnings, 8 info, 0 critical, all still open)

The latest review (2026-10-09) covers Phase 5 edits to Phase 4 code. The Phase 4 findings carried forward (WR-01 to WR-06, IN-01 to IN-06) are unchanged. None fails a success criterion on current code; they are recorded as noted risks in the frontmatter. Most relevant for the next phases:

- WR-01 (Partner `Project` scope depends on SoftDeletes for "not archived") is confirmed by re-reading `Project::constrainForPartner`: it has no `deleted_at` condition. No exposure path exists today, but Phase 5 `Task` and `Tag` scopes inherit the dependence. Add `whereNull("{$table}.deleted_at")` and a `Project::withTrashed()` Partner test.
- WR-03 (plaintext invitation token in `jobs` and `failed_jobs`) remains. Make the notification `ShouldBeEncrypted` or prune failed jobs; document in operator notes.
- IN-05 (archived-client and deactivated-user lockout only in `canAccessPanel()`) must be carried into Phase 7 and Phase 12 planning.
- WR-07 to WR-09 concern Phase 5 code and tests, not Phase 4 criteria.

Note on status: the previous report held `human_needed` partly because descriptor-less prohibitions and four concurrency assumptions could not be closed as authoritative passes. Those prohibitions were never part of any PLAN `must_haves`; their evidence is the same tests listed above, and the two that touch known warnings are WR-01 (no exposure path) and WR-03 (hashed in the table, plaintext only in the queue payload). They are recorded as noted risks rather than reasons to withhold the verdict. The concurrency assumptions (PR-04 flag toggle, CL-02 parallel primary switch, US-02 parallel submit) are backstopped by DB constraints (partial unique indexes, row locks), so a loser gets a constraint error, never corrupt data.

### Human Verification Required

None. All three items from the previous report were completed and passed in 04-UAT.md.

### Gaps Summary

No gaps. All five ROADMAP success criteria are backed by code and green tests (803 re-run by me, 2015 full-suite per orchestrator), and the live browser and mail checks passed in the UAT. Recommended follow-up before further Partner-facing work: close WR-01 and WR-03, and carry IN-05 to Phase 7 and Phase 12.

---

_Verified: 2026-10-09_
_Verifier: Claude (gsd-verifier)_
