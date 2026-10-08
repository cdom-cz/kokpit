---
phase: 04-clients-and-projects
verified: 2026-10-08T12:00:00Z
status: human_needed
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
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Projects/Models/ProjectBilling.php
  - app/Domain/Projects/ProjectKeySuggester.php
  - app/Domain/Shared/Models/Tag.php
  - app/Filament/Partner/Resources/PartnerProjectResource.php
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ProjectResource.php
covered_digest: "v3:sha256:b7fa2ffc324bbdbfbe94fee104c10e0e28691a275dc901906170e849664398fc"
behavior_unverified: 0
overrides_applied: 0
re_verification: null
gaps: []
deferred: []
noted_risks:
  - id: WR-01
    severity: warning-medium
    note: "Project Partner scope relies on the SoftDeletes scope for 'not archived'; no current path exposes data, fix before Phase 5 adds Partner task paths"
  - id: WR-03
    severity: warning-medium
    note: "Plaintext invitation token sits in the queued notification payload (jobs, failed_jobs); single-use, 7-day signed link"
  - id: WR-02
    severity: warning-low
    note: "Client currency lock vs project money currency checked outside a row lock (race)"
  - id: WR-04
    severity: warning-low
    note: "Admin send-reset action reports success when nothing was sent (Partner of an archived client)"
  - id: WR-05
    severity: warning-low
    note: "Project Actions and parts of ClientInput do not own their field validation; non-form callers get raw DB errors"
  - id: WR-06
    severity: warning-low
    note: "Reset and login e-mail lookup is case-sensitive"
human_verification:
  - test: "ARES button on a Czech client form (browser)"
    expected: "With a fictional valid company ID the ARES-sourced fields (name, company ID, tax ID, address) fill and show the 'filled' highlight; with the network blocked or an unknown ID the error appears next to the company ID field and every field stays unchanged"
    why_human: "Visual highlight and the live ARES response shape cannot be asserted in Pest; tests use faked HTTP"
  - test: "Partner invitation end to end through Mailpit"
    expected: "Admin presses 'Pozvat partnera' in the client detail; the mail arrives in Mailpit; the link opens the accept page showing the invitee e-mail; setting a password (12+ chars) signs the Partner in and shows only the 'Moje projekty' list with visible projects of that client"
    why_human: "Real mail rendering, signed-link host/scheme and the browser sign-in flow are outside the Pest harness"
  - test: "Password reset for Admin and Partner through Mailpit"
    expected: "Reset mail arrives for both; after resetting, the Partner lands in the panel and the Admin still gets the 2FA challenge"
    why_human: "Needs a real mail round trip and the 2FA challenge UI"
---

# Phase 4: Clients and Projects Verification Report

**Phase Goal:** Admin can maintain clients, contacts and projects with their billing terms, and invite a client to a restricted Partner account that sees only projects flagged visible to it
**Verified:** 2026-10-08
**Status:** human_needed
**Re-verification:** No, initial verification

## Verification Approach

SUMMARY.md claims were not used as evidence. I read the migrations, models, Actions, Filament resources, Partner resource, ARES client, invitation flow and the isolation test support code, and re-ran the Phase 4 test directories myself in DDEV:

`ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Projects tests/Isolation tests/Unit/Ares tests/Unit/Projects tests/Arch tests/Feature/Schema` returned 701 passed, 9467 assertions, exit 0. The orchestrator-reported full suite (1547 tests), `ddev composer ci`, `--group=s3` and `scripts/tests/run.sh` were not re-run by me.

No goal-blocking gap was found. Status is `human_needed` because three browser/mail checks are manual-only by nature, and because the descriptor-less prohibitions and four flagged assumptions cannot be closed as authoritative passes (see below).

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin creates, edits, archives and restores clients with billing data, stage, currency, rate, payment terms, invoice e-mail, language, online-payment flag, tags and several contacts with primary and billing flags; archived clients disappear from pickers and lists | VERIFIED | `clients` migration holds every field with CHECK constraints (stage enum, currency pair, rate >= 0, terms 0..365, language cs/en); `Client` model is `DeniesPartners`, `SoftDeletes`, `HasTags` with a `detachTags` guard that keeps tags through an archive; contacts table has `contacts_one_primary_per_client` partial unique index (D-12); `SetPrimaryContact`, `CreateContact`, `DeleteContact` demote before promote under a client row lock. Tests: `ClientResourceTest`, `ClientArchiveTest` (archive, restore, picker exclusion, `Project::selectable()`), `ContactsTest`, `ClientDefaultsTest`, `ClientTagsAndHistoryTest`, all green in my run. |
| 2 | Fictional company ID plus the ARES button fills the client form; on error or timeout the message appears next to the field and the form is unchanged | VERIFIED (automated part); live behaviour and highlight go to human | `ClientResource::aresAction()` is visible only when country is CZ, calls `AresClient::lookup`, converts `AresLookupFailed` to a `ValidationException` keyed on the component state path (field-level message) and only writes `$set(...)` after success (form unchanged on failure). It fills name, company ID, street, city, postal code, country and tax ID only when ARES publishes one (D-08: never touches currency, rate, terms, language, flag, contacts, tags). `AresClient` uses 3 s connect and 5 s total timeout, retries only connection errors and 5xx, maps 404, 400, 429 and malformed responses, caches 1 h, rate-limits 10 per minute. Tests `AresClientTest` and `AresFormActionTest` green (404, three 500s, connection error, tax number kept, action hidden for non-CZ). |
| 3 | Admin creates projects with name, status, description, dates, priority, tags, billing type, hourly rate, fixed price and time estimate; a unique 2-6 letter uppercase key is suggested from the name; a duplicate is rejected | VERIFIED | `projects` migration: `projects_key_unique` (plain unique, archived keys stay reserved), `projects_key_check ^[A-Z]{2,6}$`, status and priority CHECKs matching D-16, dates CHECK. `project_billing` is a 1:1 table (unique `project_id`) with billing type CHECK, money column pairs, `estimate_seconds`, fixed-price-required CHECK. `ProjectKeySuggester` strips diacritics via `Str::ascii`, takes initials (single word: first 4 letters, then 3, 5, 6, then variants A-Z) and returns the next free candidate. `ProjectResource` hands form state to `CreateProject` and `UpdateProject` Actions. Tests `ProjectKeyTest` (duplicate and archived key give a field error on `key`, suggestion moves past archived keys), `ProjectBillingTest`, `ProjectActionsTest` (hours to exact seconds, free status/priority switching "in any values in any order", hourly vs fixed price), `ProjectKeySuggesterTest`, `EstimateHoursTest` green. |
| 4 | From the client detail Admin invites a Partner by e-mail; the invited person sets a password and logs in | VERIFIED (automated part); Mailpit round trip goes to human | `InvitePartner` (client row lock, archived client refused, `EmailHasNoAccount`, `EmailHasNoOpenInvitation`, 256-bit token stored as SHA-256, TTL 7 days from `config/kokpit.php`), `InvitationMail` (temporary signed route, queued notification), `AcceptInvitation` Action (validation, `lockForUpdate` on the invitation, `hash_equals` check in the model, user created only on accept with `client_id` and Partner role, neutral failure on reuse or bad token), Filament `AcceptInvitation` page declared `Audience::Guest` with `#[Locked]` properties and a throttle. DB backstops: `client_invitations_open_email_unique`, `users_email_lower_unique`. Test "it turns the mailed link into a Partner account that logs in and sees only the client's visible projects (US-02, D-01)" and "uses an invitation once: the second submit of the same link is neutral" green. |
| 5 | A Partner sees only projects of their own client that are flagged client-visible, never rates, prices or estimates in lists, details, selects or search, never another client's canary data | VERIFIED | `Project::constrainForPartner` = own client AND `client_visible = true` AND client not archived (EXISTS subquery); fail-closed `PartnerScope` returns `1 = 0` for any non-admin without a client. `projects` table is Partner-safe; rate, price, estimate, billing type and internal note live only in `project_billing` (`DeniesPartners`, so the relation is null for a Partner). `PartnerProjectResource` is built only from `ProjectColumns::PARTNER_COLUMN_NAMES/ENTRY_NAMES`, is read-only (`canCreate` false, no actions), `isGloballySearchable = false`, only `name` and `key` are searchable. `Tag` Partner scope derives from `Project::query()`, so a tag on hidden projects is invisible (`PartnerTagVisibilityTest`). Canary harness seeds a billing row with a rate and the canary in `internal_note` (`CanaryRegistry`), plus real clients. `PartnerSafeColumnsTest` pins the projects column list and rejects money-like column names. `PartnerProjectVisibilityTest`, `PartnerProjectResourceTest` (hidden, other-client, archived project, archived client: 403/404 and no canary B in the body), `PartnerLockoutTest`, `DeniedModelsTest`, `RouteWalkTest` green in my run. |

**Score:** 5/5 truths verified (0 behavior-unverified, 0 overrides)

### Locked Decisions Honoured (04-CONTEXT.md)

| Decision | Status | Evidence |
|----------|--------|----------|
| D-01 invitation is its own record, user created on accept | HONOURED | `client_invitations` table; `AcceptInvitation::accept` creates the `User` only there |
| D-02 7-day validity, resend rotates token, revoke | HONOURED | `config/kokpit.php` `ttl_days` 7; `ResendInvitation`, `RevokeInvitation`; `InvitationManagementTest` |
| D-03 existing e-mail rejected | HONOURED | `EmailHasNoAccount` rule inside `InvitePartner`; `users_email_lower_unique` backstop |
| D-04 deactivate, reactivate, reset from client detail | HONOURED | `DeactivatePartnerAccount`, `ReactivatePartnerAccount`, `SendPartnerPasswordReset`, `PartnerAccountsRelationManager`, `PartnerAccountLifecycleTest` (see WR-04 for one false-success path) |
| D-05 `project_billing` split | HONOURED | Separate 1:1 table, `ProjectBilling` is `DeniesPartners`; `PartnerSafeColumnsTest` |
| D-06 Partner sees no clients or contacts | HONOURED | `Client` and `Contact` `DeniesPartners`; `DeniedModelsTest` |
| D-07 Partner sees tags, not rate/price/estimate/billing type/notes | HONOURED | `SpatieTagsColumn`/`SpatieTagsEntry` in `ProjectColumns`; Tag scope; `PartnerTagVisibilityTest` |
| D-08 ARES overwrites only ARES fields, field-level error | HONOURED | `AresCompany::formState()` and `aresAction()` (country is part of the address; no commercial field is set) |
| D-09 foreign clients, ARES and mod-11 only for CZ | HONOURED | `isCzech()` gates the button; `CompanyIdRule`; unique `(country, company_number)` partial index; test "saves a foreign client with a free-text company number", "hides the ARES action while the country is not CZ" |
| D-10 stage enum | HONOURED | `clients_stage_check`, `ClientStage` enum, Czech labels in `lang/cs/enums.php` |
| D-11 archive lockout and restore | HONOURED | `User::canAccessPanel()` checks Client existence on every request; `PartnerLockoutTest` (next request refused after archive, admitted after restore, same message as a wrong password) |
| D-12 one primary contact | HONOURED | Partial unique index `contacts_one_primary_per_client`; test "refuses a second primary row ... with 23505" |
| D-13 defaults copied at creation | HONOURED | `ClientDefaultsTest` |
| D-14 key suggestion and uniqueness | HONOURED | `ProjectKeySuggester`, DB unique index and CHECK |
| D-15 billing type two values, rate optional, currency = client currency | HONOURED with caveat | CHECKs in DB; currency match enforced in Actions only (see WR-02) |
| D-16 free status/priority switching | HONOURED | No transition guard anywhere in `app/Domain/Projects`; test "switches status and priority between any values in any order" |

### Required Artifacts and Key Links

| Artifact / link | Status | Details |
|-----------------|--------|---------|
| `app/Domain/Clients/Models/{Client,Contact,ClientInvitation}.php`, migrations 000100/000500/000600 | VERIFIED | Substantive, wired into resources and Actions, DB constraints present |
| `app/Domain/Projects/Models/{Project,ProjectBilling}.php`, migrations 000300/000400 | VERIFIED | Substantive and wired |
| `app/Filament/Resources/{ClientResource,ProjectResource}.php` + pages + relation managers | VERIFIED | Declare `#[AccessRule(AdminOnly)]`; form state goes through domain Actions |
| `app/Filament/Partner/Resources/PartnerProjectResource.php` | VERIFIED | Discovered by `AdminPanelProvider` (`discoverResources` on `Filament/Partner/Resources`); read-only; Partner-safe names only |
| `app/Filament/Pages/Auth/AcceptInvitation.php` -> `AcceptInvitation` Action -> `User` | WIRED | Signed route `filament.admin.invitation.accept` built by `InvitationMail` |
| `ClientResource::aresAction` -> `AresClient` -> config `services.ares.base_url` | WIRED | Field-level `ValidationException` |
| `Tag` Partner scope -> `Project::query()` | WIRED | Inherits the project scope (and its WR-01 caveat) |
| `users.client_id` FK to `clients` (RESTRICT) | WIRED | Migration 000200 |

### Data-Flow Trace (Level 4)

| Artifact | Data variable | Source | Real data | Status |
|----------|---------------|--------|-----------|--------|
| `PartnerProjectResource` table and infolist | Project rows | Eloquent `Project` with `PartnerScope` | Yes (DB query; zero-row result for hidden, archived, foreign) | FLOWING |
| `ClientResource` form (ARES) | name, address fields | `AresClient` HTTP GET + cache | Yes (HTTP faked in tests, live untested) | FLOWING, live part to human |
| `ProjectResource` billing section | `project_billing` row | `UpdateProject`/`CreateProject` Actions | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 4 feature, isolation, arch, schema and unit suites | `ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Projects tests/Isolation tests/Unit/Ares tests/Unit/Projects tests/Arch tests/Feature/Schema` | 701 passed, 9467 assertions, exit 0 | PASS |
| Debt markers in files changed since Phase 3 (app, database, lang, tests, config, scripts; 155 files) | grep for TBD, FIXME, XXX, TODO, HACK, PLACEHOLDER | no matches | PASS |
| Full suite, `composer ci`, `--group=s3`, `scripts/tests/run.sh` | not re-run | orchestrator reports green | SKIPPED (relied on orchestrator) |

### Probe Execution

Step 7c: no probe scripts are declared by the Phase 4 plans (`scripts/tests/` is the repository-hygiene harness, run by the orchestrator in plan 04-21). SKIPPED.

### Requirements Coverage

All nine IDs appear in the PLAN frontmatter and in REQUIREMENTS.md (all marked complete and mapped to Phase 4); none are orphaned.

| Requirement | Source plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| US-02 | 04-01, 04-16..04-21 | Admin creates a Partner from the client detail with an e-mail invitation | SATISFIED (Mailpit round trip human) | Invitation flow above |
| CL-01 | 04-01, 04-09, 04-10, 04-11, 04-21 | Clients with billing data, stage, currency, rate, terms, invoice e-mail and language, online-payment flag | SATISFIED | Truth 1 |
| CL-02 | 04-12, 04-13, 04-21 | Several contacts with primary and billing flags | SATISFIED | Truth 1, D-12 |
| CL-04 | 04-14, 04-15, 04-21 | ARES synchronous button, error next to the field, form unchanged | SATISFIED (live ARES and visual highlight human) | Truth 2 |
| CL-05 | 04-01, 04-02, 04-11, 04-21 | Soft-delete archive, tags on clients | SATISFIED | Truth 1, D-11 |
| PR-01 | 04-02, 04-03, 04-04, 04-06..04-08, 04-21 | Projects with name, status, description, dates, priority, tags (files are Phase 9 per ROADMAP mapping notes) | SATISFIED for the Phase 4 scope | Truth 3 |
| PR-02 | 04-02, 04-06, 04-07, 04-08, 04-21 | Unique 2-6 letter key suggested from the name (the freeze after the first task is Phase 5) | SATISFIED for the Phase 4 scope | Truth 3 |
| PR-03 | 04-06, 04-07, 04-08, 04-21 | Billing type, hourly rate, fixed price, estimate where Partner cannot read them | SATISFIED | Truths 3 and 5, D-05 |
| PR-04 | 04-02..04-05, 04-08, 04-21 | Client-visibility flag controls Partner access | SATISFIED | Truth 5 |

### Anti-Patterns Found

None blocking. No debt markers, no stubs, no placeholder returns in the Phase 4 files that I scanned. Review findings are recorded below as noted risks.

### Prohibitions (descriptor-less, flagged unverified by the probe disposition)

These were injected without enforcement descriptors, so formally each is flagged `unverified-prohibition`. My evidence-based judgement is recorded beside each; none of it is an authoritative judge verdict.

| Prohibition | Formal disposition | Verifier judgement and evidence |
|-------------|--------------------|---------------------------------|
| Partner must not see rates, prices or estimates (lists, details, selects, search) | flagged unverified (no descriptor) | Holds on every path I read: values live only in the `DeniesPartners` `project_billing` table; Partner resource built from pinned name lists; search limited to name and key; global search off. Backed by `PartnerSafeColumnsTest`, `PartnerProjectResourceTest`, `CanaryRegistry` rate and note canaries. |
| Partner must not see another client's data | flagged unverified (no descriptor) | Holds: fail-closed `PartnerScope`, `Project` and `Tag` scopes, `Client`/`Contact`/`ClientInvitation`/`ProjectBilling` denied to Partners; canary tests with two real clients. Caveat WR-01 (defence in depth). |
| Partner must not see clients or contacts at all (D-06) | flagged unverified (no descriptor) | Holds: `DeniesPartners` on both models; `DeniedModelsTest`. |
| No hard delete of clients, projects or Partner accounts | flagged unverified (no descriptor) | Holds on the UI/Actions paths read: only archive/restore Actions; FK `RESTRICT` everywhere; no force-delete action in `ProjectResource`. |
| Plaintext invitation token must not be stored | flagged unverified (no descriptor) | Partly violated: the DB table stores only the SHA-256 hash, but the plaintext token is persisted inside the queued notification payload (WR-03). |
| A Partner of an archived client or a deactivated Partner must not sign in | flagged unverified (no descriptor) | Holds at the panel gate (`canAccessPanel`, `PartnerLockoutTest`); not yet a gate for future API routes (IN-05). |

### Flagged Assumptions

| Assumption | Verdict | Evidence |
|------------|---------|----------|
| PR-04 toggle race (Admin flips `client_visible` while a Partner is on the list) | Unverified under real concurrency; low risk | No caching of Partner project queries: `PartnerScope` re-evaluates `client_visible` on every query, so the flip takes effect on the next request. There is no Phase 4 test that toggles the flag mid-session, and no parallel-process test. |
| CL-02 concurrent primary switch | Backstopped, not exercised in parallel | `SetPrimaryContact`, `CreateContact`, `DeleteContact` lock the client row and re-read; the partial unique index `contacts_one_primary_per_client` makes a double primary impossible even if a lock were missed (a loser gets a unique violation, not corrupt data). Stale-instance tests exist; no parallel-process test. |
| CL-04 unobserved ARES behaviours (live response shape, latency, `ico` mismatch) | Unverified | Tests use faked HTTP; live ARES not contacted by design. IN-01: the response `ico` is not compared with the requested number. Goes to human check 1. |
| US-02 concurrent link submit | Backstopped, not exercised in parallel | `AcceptInvitation` takes `lockForUpdate` on the invitation row and re-checks the account; `users_email_lower_unique` and a caught `UniqueConstraintViolationException` give a neutral failure. Sequential "second submit of the same link is neutral" test passes. |

### Code Review Disposition (04-REVIEW.md, 6 warnings, 6 info, all still open)

None undermines a success criterion on current code; they are noted risks.

| ID | My judgement |
|----|--------------|
| WR-01 Partner scope relies on SoftDeletes for "not archived" | Medium, defence in depth. Verified that today the Partner list and view hide archived projects (tests green) and the Admin resource's scope-stripped route binding still ends in a 403, so there is no data leak, only an existence signal (403 vs 404). Because Phase 5 will add Partner-facing paths over `Project` and `Tag`, add `deleted_at IS NULL` to `constrainForPartner` (a one-line fix plus a `withTrashed()` Partner test) before then. Does not fail SC 5. |
| WR-03 plaintext token in queue payload | Medium. The link is single-use, expires in 7 days, and is the same value mailed to the invitee, but it widens exposure to DB backups, `failed_jobs` and Horizon. Does not fail SC 4. Encrypt the job or prune `failed_jobs`; document in operator notes. |
| WR-02 currency/lock race | Low. Needs two concurrent admin writes on a single-admin instance; DB cannot catch it. Fix with the client row lock. |
| WR-04 false-success reset notification | Low, UX only (Partner of an archived client). |
| WR-05 Actions do not own field validation | Low today (the Filament forms validate); becomes real once Phase 7 or imports call the Actions directly. |
| WR-06 case-sensitive reset lookup | Low, usability: a Partner typing a capital letter gets no reset mail. |
| IN-01..IN-06 | Informational; IN-05 (API-route gate) should be carried into Phase 7 planning. |

### Observations

- A fresh-clone install without a `.env` fails on `ProductionConfigGuard`. Pre-existing since Phase 3, not a Phase 4 regression and not counted as a gap.
- Regression check: earlier verifications (Phases 1, 2, 3) are `passed`; their architecture and isolation suites are part of my 701-test run and are green.

### Human Verification Required

#### 1. ARES button, visual highlight and error placement

**Test:** On a new client with country CZ, enter a fictional valid company ID, press the ARES button; then repeat with the network blocked and with an unknown ID.
**Expected:** ARES-sourced fields fill and show the highlight; on error the message sits beside the company ID field and no field changes.
**Why human:** Visual state and the real ARES response cannot be asserted with faked HTTP.

#### 2. Partner invitation through Mailpit

**Test:** Invite a fictional Partner from the client detail, open the mail in Mailpit, follow the link, set a password, sign in.
**Expected:** Accept page shows the invitee e-mail, the new Partner lands in the panel and sees only "Moje projekty" of that client.
**Why human:** Real mail rendering, link host and browser flow.

#### 3. Password reset for Admin and Partner through Mailpit

**Test:** Request a reset for the Admin and for the Partner, complete it.
**Expected:** Both succeed; the Admin still gets the 2FA challenge afterwards.
**Why human:** Mail round trip and 2FA UI.

### Gaps Summary

No gaps. All five success criteria and all sixteen locked decisions are backed by code and green tests (701 re-run by me). The phase is routed to `human_needed` for the three manual mail/browser checks and because the descriptor-less prohibitions and four concurrency or live-ARES assumptions remain flagged rather than authoritatively passed. Recommended follow-up before Phase 5: close WR-01 and WR-03, and carry IN-05 to Phase 7.

---

_Verified: 2026-10-08_
_Verifier: Claude (gsd-verifier)_
