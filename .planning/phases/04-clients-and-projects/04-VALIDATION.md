---
phase: "4"
slug: "clients-and-projects"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-08"
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Seeded from `04-RESEARCH.md` § Validation Architecture. The per-task map is filled in as plans are written.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13, Livewire / Filament testing helpers (no browser tool installed) |
| **Config file** | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `KOKPIT_CANARY_HARNESS=true`, `KOKPIT_REQUIRE_ADMIN_2FA=false`); `tests/Pest.php` applies `RefreshDatabase` to Feature and Isolation |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Arch tests/Isolation` |
| **Full suite command** | `ddev exec vendor/bin/pest`, then `ddev composer ci` (test + pint + phpstan + licence check) |
| **Estimated runtime** | quick about 8 s (baseline 131 tests); full suite to be measured in Wave 0 |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest tests/Arch tests/Isolation` plus the new test file(s) of the task
- **After every plan wave:** Run `ddev exec vendor/bin/pest` (full suite)
- **Before `/gsd-verify-work`:** Full suite green, `ddev composer ci` green, `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 60 seconds for the per-task command

---

## Per-Task Verification Map

Requirement coverage the plans must preserve (plan and task that create each test file; every test file is created inside the task that needs it, so no separate Wave 0 plan exists). Column names: the Czech company ID is stored as `company_number` and the tax ID as `tax_number` (schema rule R1 reserves `*_id` for uuid columns).

| Requirement | Behavior | Test Type | Automated Command | Plan / Task | File Exists |
|-------------|----------|-----------|-------------------|-------------|-------------|
| US-02 / CL-05 | Deactivated user and Partner of an archived or missing client refused at login and on the next request | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerLockoutTest.php` | 04-01 T2 | ❌ W0 |
| CL-01 / PR-02 | clients, projects and users constraints: CHECKs, rate currency = currency, unique `(country, company_number)` incl. archived, key CHECK and unique incl. archived, FK 23503 / 23001 | feature (schema, raw SQL) | `ddev exec vendor/bin/pest tests/Feature/Schema/ClientTablesTest.php` | 04-01 T3 (clients, users), 04-02 T2 (projects) | ❌ W0 |
| PR-04 | Partner sees only own, visible, non-archived projects of a non-archived client; policy denies writes | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerProjectVisibilityTest.php` | 04-02 T1 | ❌ W0 |
| PR-03 / PR-04 | `projects` column list pinned to a Partner-safe allowlist | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerSafeColumnsTest.php` | 04-02 T2 | ❌ W0 |
| PR-04 | Partner list and detail: Partner-safe columns only, search, URL refusals, no global search | feature (HTTP, Livewire) | `ddev exec vendor/bin/pest tests/Feature/Projects/PartnerProjectResourceTest.php` | 04-03 T1, T2 | ❌ W0 |
| PR-04 | Partner sees project tags of visible own projects only; archive keeps tags | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerTagVisibilityTest.php` | 04-04 T1, T2 | ❌ W0 |
| PR-04 | `Audience::Guest` always denied; registry governs SimplePage; scanner reports PartnerScope removal | arch + isolation | `ddev exec vendor/bin/pest tests/Arch/PanelRegistryTest.php tests/Isolation/PanelAccessTest.php tests/Arch/QueryEscapeHatchTest.php` | 04-05 T1, T2 | ❌ W0 |
| PR-03 | `project_billing` 1:1, money pairs, currency = client currency, Partner reads nothing, key race | feature | `ddev exec vendor/bin/pest tests/Feature/Projects/ProjectBillingTest.php` | 04-06 T1, T2 | ❌ W0 |
| PR-03 | Estimate hours to whole seconds without rounding | unit | `ddev exec vendor/bin/pest tests/Unit/Projects/EstimateHoursTest.php` | 04-07 T1 | ❌ W0 |
| PR-01 / PR-03 | UpdateProject: client immutable, free status switching, fixed price rule, selectable scope | feature | `ddev exec vendor/bin/pest tests/Feature/Projects/ProjectActionsTest.php` | 04-07 T1 | ❌ W0 |
| PR-03 | project_billing constraints and audit allowlist | feature (schema, raw SQL) | `ddev exec vendor/bin/pest tests/Feature/Schema/ProjectBillingTableTest.php` | 04-07 T2 | ❌ W0 |
| PR-01 | Create / edit / archive project with tags (type `project`) and billing section | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Projects/ProjectResourceTest.php` | 04-08 T1 | ❌ W0 |
| PR-02 | Key suggester (diacritics, one word, digits, collisions incl. archived); duplicate key rejected; CHECK `^[A-Z]{2,6}$` | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Projects/ProjectKeySuggesterTest.php tests/Feature/Projects/ProjectKeyTest.php` | 04-08 T2 | ❌ W0 |
| CL-01 | Create / edit client; Money pair stored; rate precision and terms boundary | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php` | 04-09 T1, T2 | ❌ W0 |
| CL-01 | Defaults prefill and one-time copy incl. the new default invoice language | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientDefaultsTest.php` | 04-10 T1, T2 | ❌ W0 |
| CL-05 | Archive hides from lists and pickers; restore returns; no force delete; tags survive archive and restore; idempotent | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientArchiveTest.php` | 04-11 T1 | ❌ W0 |
| CL-05 | Client tags typed `client`, client history | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientTagsAndHistoryTest.php` | 04-11 T2 | ❌ W0 |
| CL-01 | Currency lock while project money exists; company number unique per country | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientRulesTest.php` | 04-11 T3 | ❌ W0 |
| CL-02 | Contacts: one primary (partial unique index), primary switch, protected delete, billing flags | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ContactsTest.php` | 04-12 T1, T2; 04-13 T1, T2 | ❌ W0 |
| CL-04 | `CompanyId` mod-11 checksum | unit | `ddev exec vendor/bin/pest tests/Unit/Ares/CompanyIdTest.php` | 04-14 T2 | ❌ W0 |
| CL-04 | CZ-only company number checksum as a field error in the form and in CreateClient / UpdateClient; foreign numbers free | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php tests/Feature/Clients/ClientRulesTest.php` | 04-14 T1, T2 | ❌ W0 |
| CL-04 | Form action fills only ARES fields; every failure sets an error on `company_number`, form unchanged; hidden for non-CZ | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/AresFormActionTest.php` | 04-15 T1, T2 | ❌ W0 |
| CL-04 | `AresClient` with `Http::fake`: 200 mapping, 404, 400, 5xx, 429, timeout, malformed JSON, 404 not retried, cache, limiter | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/AresClientTest.php` | 04-15 T2 | ❌ W0 |
| US-02 | Invite: record plus queued notification; existing e-mail rejected; resend invalidates old link; revoke | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/PartnerInvitationTest.php` | 04-16 T1, T2; 04-17 T1, T2 | ❌ W0 |
| US-02 | Accept (guest): password set, Partner created with `client_id`, single use, neutral page for every invalid state, throttled, no self-registration, login and visible projects | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/AcceptInvitationTest.php` | 04-18 T1, T2 | ❌ W0 |
| US-02 | Invite, resend, revoke from the client detail | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/InvitationManagementTest.php` | 04-19 T1, T2 | ❌ W0 |
| US-02 | Deactivate / reactivate, tokens deleted, no delete action | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/PartnerAccountLifecycleTest.php` | 04-20 T1 | ❌ W0 |
| US-02 | Password reset from the client detail, generic public reset page | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/PartnerPasswordResetTest.php` | 04-20 T2 | ❌ W0 |
| all | Gate-only plan: CONTRIBUTING and README name every Phase 4 mechanism (documentation test), then the full phase gate (CI suite, S3 group, hygiene scan, fresh-clone install) | feature + gate | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` and `ddev composer ci` | 04-21 T1, T2 | ❌ W0 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/Canary.php` — `twoClients()` creates real fictional clients and the `Client` factory under `database/factories/` (plan 04-01 Task 1); `Canary::projectKey()` and the `Project` factory (plan 04-02 Task 1)
- [ ] `tests/Support/CanaryRegistry.php` — new fixture lines for the new models (04-01 Client, 04-02 Project, 04-06 ProjectBilling, 04-12 Contact, 04-16 ClientInvitation); `Tag` fixture re-pointed at a visible `Project` (04-04 Task 1; fixture order matters)
- [ ] Update hard-coded model lists in `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php` (with every new model), `tests/Arch/ActivityAllowlistTest.php` (04-07, 04-11, 04-13); teach `RouteWalkTest` the resources with record routes (04-03, then 04-08 and 04-09); update `DeniedModelsTest` wording (04-04)
- [ ] `Http::preventStrayRequests()` in `tests/TestCase.php::setUp()` so no booted test can call ARES (04-15 Task 1)
- [ ] `tests/Isolation/PartnerSafeColumnsTest.php` pinning `Schema::getColumnListing('projects')` to an explicit allowlist (04-02 Task 2)
- [ ] `EscapeHatchScanner` extension for `withoutGlobalScope(PartnerScope::class)` (04-05 Task 2)
- [ ] `composer require filament/spatie-laravel-tags-plugin:^5.10` (04-04 Task 1): Approved in the research Package Legitimacy Audit (not `[ASSUMED]` or `[SUS]`), so no human checkpoint; `symfony/intl` (`[ASSUMED]`) is not installed, the country stays a two-letter ISO input

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual highlight of fields changed by the ARES button | CL-04 | No browser test tool installed | In DDEV, open a new client, set country CZ, enter a checksum-valid fictional company ID, press the ARES button, confirm changed fields are highlighted and an error shows next to the field on a failure (for example with the network blocked) |
| Rendered invitation e-mail and accept page | US-02 | Mail layout and wording | Invite an `example.com` address, open the mail in Mailpit (port 8025), follow the link, set a password, log in as the new Partner and confirm only visible projects appear |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
