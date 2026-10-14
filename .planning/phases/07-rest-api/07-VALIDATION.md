---
phase: "07"
slug: "rest-api"
status: planned
nyquist_compliant: true
wave_0_complete: false
created: "2026-10-14"
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13 |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Api` |
| **Full suite command** | `ddev exec vendor/bin/pest` |
| **Estimated runtime** | ~120 seconds |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest tests/Feature/Api` (plus the touched arch/isolation file)
- **After every plan wave:** Run `ddev exec vendor/bin/pest`, `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- **Before `/gsd-verify-work`:** Full suite green, `composer check-licenses` green, `scripts/check-sensitive.sh` green
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

Filled by the planner from the "Phase Requirements to Test Map" in `07-RESEARCH.md` (AP-01, AP-02, AP-04, AP-05).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 07-01-01 | 01 | 1 | AP-01, AP-02 | T-07-07 | An Admin token with time:read reads one entry; no token gets 401; exact key list without money | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/AuthenticationTest.php tests/Feature/Repo/EnvExampleTest.php` | ❌ created by the task | ⬜ pending |
| 07-01-02 | 01 | 1 | AP-01 | T-07-01, T-07-02, T-07-03, T-07-04, T-07-06 | Malformed, unknown, revoked, expired, Partner, role-less, deactivated and session-only requests get 401; missing ability 403; bad ids 404 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/AuthenticationTest.php tests/Feature/Schema/PackageModelsTest.php` | ❌ created by 07-01-01 | ⬜ pending |
| 07-01-03 | 01 | 1 | AP-05 | T-07-05 | Per-token 120/60 limits with 429 + Retry-After; separate read and write buckets per token; both groups carry auth, ability and throttle | feature | `ddev exec vendor/bin/pest tests/Feature/Api/RateLimitTest.php tests/Feature/Api/AuthenticationTest.php` | ❌ created by the task | ⬜ pending |
| 07-02-01 | 02 | 2 | AP-05 | T-07-08, T-07-10, T-07-11, T-07-SC | Scribe generates spec and page without a database; data-driven completeness rules; output gitignored | feature (subprocess, tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Repo/EnvExampleTest.php` and `bash scripts/tests/test-gitignore.sh` | ❌ created by the task | ⬜ pending |
| 07-02-02 | 02 | 2 | AP-02, AP-04, AP-05 | — | Owner confirms the wire contract (D-09 one-way door) | checkpoint:decision | n/a (human decision) | — | ⬜ pending |
| 07-02-03 | 02 | 2 | AP-05 | T-07-09, T-07-12, T-07-13 | Contract pinned; guest 200 without cookie, noindex, 404 before generation, 429 per IP, API still 401 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryContractTest.php tests/Feature/Api/ApiDocumentationAccessTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Api/AuthenticationTest.php` | ❌ created by the task | ⬜ pending |
| 07-03-01 | 03 | 2 | AP-01, AP-05 | T-07-14, T-07-15 | Admin mints a token after password + TOTP, sees it once, it reads the API; ApiAbility labels (HasLabel) | feature (Livewire, tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/ApiTokenScreenTest.php tests/Arch/PanelRegistryTest.php tests/Feature/Localisation/EnumLabelsTest.php` | ❌ created by the task | ⬜ pending |
| 07-03-02 | 03 | 2 | AP-01, AP-05 | T-07-16, T-07-18, T-07-19 | Wrong/reused/recovery code and wrong password create nothing; throttle; Prague end-of-day expiry; status badges; audit row without secret | feature | `ddev exec vendor/bin/pest tests/Feature/Api/IssueApiTokenTest.php tests/Feature/Api/ApiTokenScreenTest.php` | ❌ created by the task | ⬜ pending |
| 07-03-03 | 03 | 2 | AP-01, AP-05 | T-07-14, T-07-15, T-07-17, T-07-56 | Revoke with owner check; revoked token 401; Partner sees and drives nothing; no token in HTML after close; route walk: every api/v1 route has auth, ability and throttle, guest and Partner token 401, missing ability 403 | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Api/ApiTokenScreenTest.php tests/Feature/Api/IssueApiTokenTest.php tests/Isolation/PanelAccessTest.php tests/Isolation/ApiAccessTest.php` | ✅ (extends); ❌ ApiAccessTest created by the task | ⬜ pending |
| 07-04-01 | 04 | 3 | AP-02 | T-07-20, T-07-SC | List newest first with cursor and filter[client] | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryListTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ❌ created by the task | ⬜ pending |
| 07-04-02 | 04 | 3 | AP-02 | T-07-20, T-07-21 | Project/task by uuid or key, billable, billed, inclusive Prague-day range (DST), shared PragueDay | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryListTest.php tests/Feature/TimeTracking/PragueDayTest.php tests/Feature/TimeTracking/TimeEntryListTest.php tests/Feature/TimeTracking/TimeEntryResourceTest.php tests/Feature/TimeTracking/TimesheetTest.php` | ❌ PragueDayTest created by the task | ⬜ pending |
| 07-04-03 | 04 | 3 | AP-02 | T-07-22, T-07-23 | 120 equal started_at page without skip/repeat; page size and sort limits | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryListTest.php` | ✅ (extends) | ⬜ pending |
| 07-05-01 | 05 | 4 | AP-02 | T-07-24, T-07-29 | Create via CreateTimeEntry with duration_seconds and task KEY-N | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryWriteTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ❌ created by the task | ⬜ pending |
| 07-05-02 | 05 | 4 | AP-02 | T-07-25, T-07-28 | PATCH/DELETE over the Actions; billed 409 entry_locked; running 422 / 409 entry_running | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryWriteTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ✅ (extends) | ⬜ pending |
| 07-05-03 | 05 | 4 | AP-02 | T-07-24, T-07-26, T-07-27, T-07-29 | UI refusal matrix through the API; overflow, offsets, smuggled keys, write throttle | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryWriteTest.php` | ✅ (extends) | ⬜ pending |
| 07-06-01 | 06 | 5 | AP-02 | T-07-32 | external object round-trips on create and read | feature + schema (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryExternalTest.php tests/Feature/Api/TimeEntryContractTest.php tests/Feature/Schema/SchemaConventionsTest.php tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ❌ created by the task | ⬜ pending |
| 07-06-02 | 06 | 5 | AP-02 | T-07-30, T-07-31 | {} stays an object, []/scalars 422, 4096 bytes and 8 levels, absent keeps / null clears | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryExternalTest.php tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ✅ (extends) | ⬜ pending |
| 07-06-03 | 06 | 5 | AP-02 | T-07-31, T-07-32, T-07-33 | CHECK refuses non-objects, KP001 on billed rows, no activity row, not rendered in the panel | schema + feature | `ddev exec vendor/bin/pest tests/Feature/Schema/TimeEntriesTableTest.php tests/Feature/Api/TimeEntryExternalTest.php` | ✅ (extends) | ⬜ pending |
| 07-07-01 | 07 | 5 | AP-02 | T-07-34, T-07-35 | API create writes activity source 'api' | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/ApiActivitySourceTest.php tests/Feature/Localisation/EnumLabelsTest.php` | ❌ created by the task | ⬜ pending |
| 07-07-02 | 07 | 5 | AP-02 | T-07-34, T-07-35 | Update/delete 'api'; other sources unchanged; CHECK; overview filter | feature | `ddev exec vendor/bin/pest tests/Feature/Api/ApiActivitySourceTest.php tests/Feature/Operations/ActivitySourceTest.php tests/Feature/Operations/ActivityViewsTest.php` | ✅ (extends) | ⬜ pending |
| 07-08-01 | 08 | 5 | AP-04 | T-07-39 | Task lookup by KEY-N and uuid | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/LookupTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ❌ created by the task | ⬜ pending |
| 07-08-02 | 08 | 5 | AP-04 | T-07-37, T-07-38 | Clients and projects list/detail, filter[name] partial, selectable only | feature | `ddev exec vendor/bin/pest tests/Feature/Api/LookupTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ✅ (extends) | ⬜ pending |
| 07-08-03 | 08 | 5 | AP-04 | T-07-36 | Task list; no money or contact data (canaries); read-only (405) | feature | `ddev exec vendor/bin/pest tests/Feature/Api/LookupTest.php tests/Feature/Api/ApiDocumentationTest.php` | ✅ (extends) | ⬜ pending |
| 07-09-01 | 09 | 6 | AP-05 | T-07-40, T-07-42 | Same key twice: one entry, byte-identical replay with Idempotent-Replayed | feature + arch (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/IdempotencyTest.php tests/Arch/ModelDeclarationTest.php tests/Arch/QueryEscapeHatchTest.php tests/Feature/Schema/SchemaConventionsTest.php tests/Feature/Api/ApiDocumentationTest.php` | ❌ created by the task | ⬜ pending |
| 07-09-02 | 09 | 6 | AP-05 | T-07-41, T-07-44 | Different body 422, in progress 409, stale takeover, expired key, failures release | feature | `ddev exec vendor/bin/pest tests/Feature/Api/IdempotencyTest.php` | ✅ (extends) | ⬜ pending |
| 07-09-03 | 09 | 6 | AP-05 | T-07-40, T-07-43 | Malformed keys 422, per-token isolation, create-only, revoke cascade, documented on the endpoint | feature | `ddev exec vendor/bin/pest tests/Feature/Api/IdempotencyTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ✅ (extends) | ⬜ pending |
| 07-10-01 | 10 | 7 | AP-05 | T-07-46, T-07-47 | Daily purge on one server deletes only expired keys | feature + arch (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/PurgeIdempotencyKeysTest.php tests/Arch/JobContractTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php` | ❌ created by the task | ⬜ pending |
| 07-10-02 | 10 | 7 | AP-05 | T-07-45 | 8 processes, one key, one entry; lock-less mutation run creates 8 | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/IdempotencyConcurrencyTest.php` | ❌ created by the task | ⬜ pending |
| 07-11-01 | 11 | 7 | AP-02, AP-05 | T-07-48 | Valid batch saved through CreateTimeEntry, 201 with counts | feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryBatchTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Isolation/ApiAccessTest.php` | ❌ created by the task | ⬜ pending |
| 07-11-02 | 11 | 7 | AP-02 | T-07-48, T-07-50, T-07-51 | 207 / 422, only failed items with index, per-item transactions, 0 and 101 items 422 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryBatchTest.php` | ✅ (extends) | ⬜ pending |
| 07-11-03 | 11 | 7 | AP-05 | T-07-49 | Retried mixed batch replays 207; 422 releases the key; one write against the limit; docs intro covers Idempotency-Key and batch retries | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryBatchTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Api/IdempotencyTest.php` | ✅ (extends) | ⬜ pending |
| 07-12-01 | 12 | 8 | AP-05 | T-07-53, T-07-55 | Last init command generates docs on every container; manifest weakening detected | repo + feature (tracer) | `ddev exec vendor/bin/pest tests/Feature/Repo/ZeropsConfigTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Repo/DeployWorkflowTest.php` | ✅ (extends) | ⬜ pending |
| 07-12-02 | 12 | 8 | AP-05 | T-07-52, T-07-53 | No real host, token, e-mail, path or env value in the output; broken attribute exits 2; eleven routes documented | feature (subprocess) | `ddev exec vendor/bin/pest tests/Feature/Api/ApiDocumentationHygieneTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Api/ApiDocumentationAccessTest.php` | ❌ created by the task | ⬜ pending |
| 07-12-03 | 12 | 8 | AP-01, AP-02, AP-04, AP-05 | T-07-54 | Guest and Partner token: 401 and no canary on every api/v1 route; CONTRIBUTING classes exist; phase gate | isolation + repo + gate | `ddev exec vendor/bin/pest` and `scripts/tests/run.sh --quick && scripts/check-sensitive.sh` | ✅ (extends) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

No separate Wave 0 plan: every test file is written first (RED) inside the task that needs it, and each plan's tracer task creates the file its later tasks extend.

- [ ] `tests/Support/ApiFixtures.php` — shared fixture class (Admin, real tokens with chosen abilities, clients, projects, tasks, entries, billed and running entries); plan 07-01 Task 1
- [ ] `tests/Feature/Api/AuthenticationTest.php`, `RateLimitTest.php`; plan 07-01
- [ ] `tests/Support/ApiDocs.php`, `tests/Feature/Api/ApiDocumentationTest.php` (data-driven docs rules), `ApiDocumentationAccessTest.php`, `TimeEntryContractTest.php`; plan 07-02
- [ ] `tests/Feature/Api/ApiTokenScreenTest.php`, `IssueApiTokenTest.php`, `tests/Isolation/ApiAccessTest.php` (data-driven route walk, in place before wave 3 adds the next route); plan 07-03
- [ ] `tests/Feature/Api/TimeEntryListTest.php`, `tests/Feature/TimeTracking/PragueDayTest.php`; plan 07-04
- [ ] `tests/Feature/Api/TimeEntryWriteTest.php`; plan 07-05
- [ ] `tests/Feature/Api/TimeEntryExternalTest.php`; plan 07-06
- [ ] `tests/Feature/Api/ApiActivitySourceTest.php`; plan 07-07
- [ ] `tests/Feature/Api/LookupTest.php`; plan 07-08
- [ ] `tests/Feature/Api/IdempotencyTest.php`; plan 07-09
- [ ] `tests/Feature/Api/PurgeIdempotencyKeysTest.php`, `tests/Concurrency/IdempotencyConcurrencyTest.php`, `tests/Concurrency/idempotency-worker.php`, `tests/Support/UnclaimedIdempotencyStore.php`; plan 07-10
- [ ] `tests/Feature/Api/TimeEntryBatchTest.php`; plan 07-11
- [ ] `tests/Feature/Api/ApiDocumentationHygieneTest.php`, `tests/Support/scribe-broken-route.php`, `tests/Support/Probes/BrokenDocsProbeController.php`; plan 07-12
- [ ] Exact-list tests updated: `ModelDeclarationTest` (07-09), `JobContractTest` and `ScheduleOnOneServerTest` (07-10), `ZeropsConfigTest` (07-12), `RepositoryFilesTest` (07-12), `scripts/tests/test-gitignore.sh` (07-02), `TimeEntriesTableTest` billed-row dataset (07-06), `ActivitySourceTest` / `ActivityViewsTest` (07-07)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Token screen look and feel | AP-01 | Visual contract in `07-UI-SPEC.md` | Open the token screen as Admin, create and revoke a token (07-03 Task 3 human-check: light and dark mode, 375 px, in-flight spinners, long names) |
| Wire contract confirmation | AP-02, AP-04, AP-05 | One-way contract (D-09) | Owner answers the 07-02 Task 2 decision checkpoint |
| Docs generation on Zerops | AP-05 | Needs a real deploy (research A10: init-command user can write the output directories) | First Zerops rehearsal after the phase: `/docs` and `/docs.openapi` answer 200 on every container |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (the only exception is the 07-02 decision checkpoint)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (each test file is created by the task that first needs it)
- [x] No watch-mode flags
- [ ] Feedback latency < 180s (to confirm at execution: the docs tests run `scribe:generate` once per test process, the concurrency test spawns 8 workers)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
