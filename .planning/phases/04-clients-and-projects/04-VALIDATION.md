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

Requirement coverage the plans must preserve (task IDs are assigned by the planner):

| Requirement | Behavior | Test Type | Automated Command | File Exists |
|-------------|----------|-----------|-------------------|-------------|
| CL-01 | Create / edit client; defaults copied once; Money pair stored | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php` | ❌ W0 |
| CL-01 | clients constraints: stage / currency CHECKs, rate currency = currency, unique `(country, company_id)` incl. archived | feature (schema, raw SQL) | `ddev exec vendor/bin/pest tests/Feature/Schema/ClientTablesTest.php` | ❌ W0 |
| CL-02 | Contacts: one primary (partial unique index), primary promotion, billing flags | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ContactsTest.php` | ❌ W0 |
| CL-04 | `CompanyId` mod-11 checksum | unit | `ddev exec vendor/bin/pest tests/Unit/Ares/CompanyIdTest.php` | ❌ W0 |
| CL-04 | `AresClient` with `Http::fake`: 200 mapping, 404, 400, 5xx, 429, timeout, malformed JSON, 404 not retried | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/AresClientTest.php` | ❌ W0 |
| CL-04 | Form action fills only ARES fields; every failure sets an error on `company_id`, form unchanged; hidden for non-CZ | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/AresFormActionTest.php` | ❌ W0 |
| CL-05 | Archive hides from lists and pickers; restore returns; no force delete; tags survive archive and restore | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientArchiveTest.php` | ❌ W0 |
| PR-01 | Create / edit project with tags (type `project`) | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Projects/ProjectResourceTest.php` | ❌ W0 |
| PR-02 | Key suggester (diacritics, one word, digits, collisions incl. archived); duplicate key rejected; CHECK `^[A-Z]{2,6}$` | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Projects/ProjectKeySuggesterTest.php tests/Feature/Projects/ProjectKeyTest.php` | ❌ W0 |
| PR-03 | `project_billing` 1:1, money pairs, estimate, currency = client currency | feature | `ddev exec vendor/bin/pest tests/Feature/Projects/ProjectBillingTest.php` | ❌ W0 |
| PR-04 | Partner sees only own, visible, non-archived projects of a non-archived client; policy denies writes | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerProjectVisibilityTest.php` | ❌ W0 |
| US-02 | Invite: record plus queued notification; existing e-mail rejected; resend invalidates old link; revoke | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/PartnerInvitationTest.php` | ❌ W0 |
| US-02 | Accept (guest): password set, Partner created with `client_id`, single use, neutral page for every invalid state, throttled, no self-registration | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/AcceptInvitationTest.php` | ❌ W0 |
| US-02 | Deactivate / reactivate / reset; archived client blocks login and restore reopens | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/PartnerAccountLifecycleTest.php` | ❌ W0 |
| PR-03 / PR-04 | `projects` column list pinned to a Partner-safe allowlist | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerSafeColumnsTest.php` | ❌ W0 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/Canary.php` — `twoClients()` creates real fictional clients; add `Canary::projectKey()`; `Client` / `Project` factories under `database/factories/`
- [ ] `tests/Support/CanaryRegistry.php` — new fixture lines for the new models; `Tag` fixture re-pointed at a visible `Project` (fixture order matters)
- [ ] Update hard-coded model lists in `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ActivityAllowlistTest.php`; extend `RouteWalkTest`; update `DeniedModelsTest` wording
- [ ] `Http::preventStrayRequests()` in the Feature bootstrap so no test can call ARES
- [ ] `tests/Isolation/PartnerSafeColumnsTest.php` pinning `Schema::getColumnListing('projects')` to an explicit allowlist
- [ ] Optional: `EscapeHatchScanner` extension for `withoutGlobalScope(PartnerScope::class)`
- [ ] `composer require filament/spatie-laravel-tags-plugin` (and optionally `symfony/intl`) behind a human verify of package legitimacy

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
