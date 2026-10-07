---
phase: 02-platform-foundation
verified: 2026-10-08T00:00:00Z
status: passed
score: 8/8 must-haves verified
covered_files:
  - .ddev/config.yaml
  - .env.example
  - .github/CODEOWNERS
  - .github/dependabot.yml
  - .github/workflows/hygiene.yml
  - .planning/phases/02-platform-foundation/02-01-PLAN.md
  - .planning/phases/02-platform-foundation/02-01-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-02-PLAN.md
  - .planning/phases/02-platform-foundation/02-02-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-03-PLAN.md
  - .planning/phases/02-platform-foundation/02-03-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-04-PLAN.md
  - .planning/phases/02-platform-foundation/02-04-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-05-PLAN.md
  - .planning/phases/02-platform-foundation/02-05-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-06-PLAN.md
  - .planning/phases/02-platform-foundation/02-06-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-07-PLAN.md
  - .planning/phases/02-platform-foundation/02-07-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-08-PLAN.md
  - .planning/phases/02-platform-foundation/02-08-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-09-PLAN.md
  - .planning/phases/02-platform-foundation/02-09-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-10-PLAN.md
  - .planning/phases/02-platform-foundation/02-10-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-11-PLAN.md
  - .planning/phases/02-platform-foundation/02-11-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-12-PLAN.md
  - .planning/phases/02-platform-foundation/02-12-SUMMARY.md
  - .planning/phases/02-platform-foundation/02-13-PLAN.md
  - .planning/phases/02-platform-foundation/02-13-SUMMARY.md
  - CONTRIBUTING.md
  - LICENSE
  - README.md
  - SECURITY.md
  - app/Console/Commands/InstallCommand.php
  - app/Console/Commands/ResetAdminTwoFactorCommand.php
  - app/Domain/Identity/Models/Permission.php
  - app/Domain/Identity/Models/PersonalAccessToken.php
  - app/Domain/Identity/Models/Role.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Identity/RoleName.php
  - app/Domain/Shared/Auth/AccessRule.php
  - app/Domain/Shared/Auth/AccessRules.php
  - app/Domain/Shared/Auth/Audience.php
  - app/Domain/Shared/Auth/DeniesPartners.php
  - app/Domain/Shared/Auth/IsolatesPartners.php
  - app/Domain/Shared/Auth/KokpitPolicy.php
  - app/Domain/Shared/Auth/NotPartnerScoped.php
  - app/Domain/Shared/Auth/PartnerContext.php
  - app/Domain/Shared/Auth/PartnerIsolated.php
  - app/Domain/Shared/Auth/PartnerScope.php
  - app/Domain/Shared/Database/Immutability.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Models/Activity.php
  - app/Domain/Shared/Models/KokpitModel.php
  - app/Domain/Shared/Models/Media.php
  - app/Domain/Shared/Models/Tag.php
  - app/Domain/Shared/Models/WebhookCall.php
  - app/Domain/Shared/Money/Money.php
  - app/Domain/Shared/Money/MoneyCast.php
  - app/Domain/Shared/Policies/AdminOnlyPolicy.php
  - app/Domain/Shared/Sequences/SequenceAllocator.php
  - app/Filament/Concerns/EnforcesPageAccessRule.php
  - app/Filament/Concerns/EnforcesRelationManagerAccessRule.php
  - app/Filament/Concerns/EnforcesResourceAccessRule.php
  - app/Filament/Concerns/EnforcesWidgetAccessRule.php
  - app/Filament/Pages/Dashboard.php
  - app/Http/Controllers/Controller.php
  - app/Http/Middleware/EnsureAdminHasTwoFactor.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/LocalisationServiceProvider.php
  - app/Providers/ModelConventionsServiceProvider.php
  - app/Support/InitialsAvatarProvider.php
  - app/Support/ProductionConfigGuard.php
  - bootstrap/app.php
  - bootstrap/cache/.gitignore
  - bootstrap/providers.php
  - composer.json
  - composer.lock
  - config/activitylog.php
  - config/app.php
  - config/auth.php
  - config/cache.php
  - config/database.php
  - config/filesystems.php
  - config/kokpit.php
  - config/logging.php
  - config/mail.php
  - config/media-library.php
  - config/permission.php
  - config/queue.php
  - config/services.php
  - config/session.php
  - config/tags.php
  - config/webhook-client.php
  - database/.gitignore
  - database/factories/UserFactory.php
  - database/migrations/0001_01_01_000000_create_users_table.php
  - database/migrations/0001_01_01_000002_create_jobs_table.php
  - database/migrations/2026_10_07_000100_create_number_sequences_table.php
  - database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php
  - database/migrations/2026_10_07_151401_create_permission_tables.php
  - database/migrations/2026_10_07_151910_create_personal_access_tokens_table.php
  - database/migrations/2026_10_07_151911_create_notifications_table.php
  - database/migrations/2026_10_07_152542_create_media_table.php
  - database/migrations/2026_10_07_152543_create_tag_tables.php
  - database/migrations/2026_10_07_152629_create_activity_log_table.php
  - database/migrations/2026_10_07_152631_create_webhook_calls_table.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/RoleSeeder.php
  - lang/cs.json
  - lang/cs/actions.php
  - lang/cs/auth.php
  - lang/cs/enums.php
  - lang/cs/http-statuses.php
  - lang/cs/kokpit.php
  - lang/cs/pagination.php
  - lang/cs/passwords.php
  - lang/cs/validation.php
  - phpstan.neon
  - phpunit.xml
  - pint.json
  - routes/console.php
  - routes/web.php
  - scripts/boot-from-env-example.sh
  - scripts/check-licenses.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Arch/MoneyBoundaryTest.php
  - tests/Arch/PanelRegistryTest.php
  - tests/Arch/QueryEscapeHatchTest.php
  - tests/Concurrency/SequenceAllocatorConcurrencyTest.php
  - tests/Concurrency/worker.php
  - tests/Feature/Auth/InstallCommandTest.php
  - tests/Feature/Auth/ResetTwoFactorCommandTest.php
  - tests/Feature/Auth/TwoFactorEnforcementTest.php
  - tests/Feature/Boot/PanelBootTest.php
  - tests/Feature/Database/ImmutabilityPilotTest.php
  - tests/Feature/Localisation/EnumLabelsTest.php
  - tests/Feature/Localisation/FormatsTest.php
  - tests/Feature/Localisation/LangEncodingTest.php
  - tests/Feature/Localisation/LocalisationTest.php
  - tests/Feature/Money/MoneyCastTest.php
  - tests/Feature/Repo/CiParityTest.php
  - tests/Feature/Repo/DdevConfigTest.php
  - tests/Feature/Repo/EnvExampleTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/KeysAndTimestampsTest.php
  - tests/Feature/Schema/MorphMapTest.php
  - tests/Feature/Schema/PackageModelsTest.php
  - tests/Feature/Schema/SchemaConventionsTest.php
  - tests/Feature/Sequences/SequenceAllocatorTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/DeniedModelsTest.php
  - tests/Isolation/FailClosedScopeTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PolicyBaseTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Isolation/SystemRunCommandsTest.php
  - tests/Pest.php
  - tests/Support/Canary.php
  - tests/Support/CanaryRecord.php
  - tests/Support/CanaryRecordPolicy.php
  - tests/Support/CanaryRegistry.php
  - tests/Support/EscapeHatchScanner.php
  - tests/Support/Filament/CanaryRecordResource.php
  - tests/Support/Filament/CanaryRecordResource/Pages/ListCanaryRecords.php
  - tests/Support/Filament/CanaryRecordResource/Pages/ViewCanaryRecord.php
  - tests/Support/Filament/Fixtures/AdminOnlyRelationManager.php
  - tests/Support/Filament/Fixtures/AdminOnlyWidget.php
  - tests/Support/Filament/Fixtures/PartnerAllowedWidget.php
  - tests/Support/Filament/Fixtures/PolicyDeniedRelationManager.php
  - tests/Support/Filament/Fixtures/PolicyDeniedResource.php
  - tests/Support/Filament/Fixtures/UndeclaredCanaryRecordResource.php
  - tests/Support/Filament/Fixtures/UndeclaredPage.php
  - tests/Support/Fixtures/TranslatedFixtureEnum.php
  - tests/Support/Fixtures/UntranslatedFixtureEnum.php
  - tests/Support/Localisation/EnumLabelChecker.php
  - tests/Support/ModelDeclaration.php
  - tests/Support/ModelRules.php
  - tests/Support/PgSchema.php
  - tests/Support/Probes/MoneyProbe.php
  - tests/Support/Probes/PackageProbe.php
  - tests/Support/Probes/ProbeNotification.php
  - tests/Support/Probes/UnmappedProbe.php
  - tests/Support/RawSql.php
  - tests/Support/UnlockedSequenceAllocator.php
  - tests/Support/Uuids.php
  - tests/TestCase.php
  - tests/Unit/Database/ImmutabilityTest.php
  - tests/Unit/LicenceCheckTest.php
  - tests/Unit/Money/MoneyTest.php
  - tests/Unit/Support/InitialsAvatarProviderTest.php
  - tests/Unit/Support/ProductionConfigGuardTest.php
covered_digest: "v3:sha256:81b52a6b9c61fb695602b1faa01fc1416e6557e7fc1091998b4b2044e81a221f"
behavior_unverified: 0
overrides_applied: 1
overrides:
  - must_have: "UI-SPEC A-3: primary colour is Indigo"
    reason: "Owner keeps Color::Amber; the deviation from the approved UI-SPEC is accepted. Known trade-off: Amber with white text is about 3.2:1, below WCAG AA."
    accepted_by: "Petr Gräf"
    accepted_at: "2026-10-08"
re_verification:
  previous_status: human_needed
  previous_score: 5/7
  gaps_closed: []
  gaps_remaining: []
  regressions: []
gaps: []
deferred:
  - truth: "Lists sorted by a Czech text column use Czech collation (ch sorts after h) wherever such a list exists (02-08 backstop truth; no sortable text list exists in Phase 2)"
    addressed_in: "Phase 4"
    evidence: "Phase 4 delivers the first sortable Czech text lists (clients, projects: success criterion 1, 'archived clients disappear from lists'); the owner confirmed the deferral in the UAT (02-UAT.md test 8). The Phase 4 roadmap text does not name collation explicitly, so this is recorded as a carried obligation, not as proof."
advisory: []
coincidental_reliance_items: []
---

# Phase 2: Platform Foundation Verification Report

**Phase Goal:** A running, installable Laravel + Filament application whose data conventions, money handling, numbering and Partner default-deny access are enforced by failing tests before any feature is built
**Verified:** 2026-10-08
**Status:** passed
**Re-verification:** Yes. The report of 2026-10-07 (status `human_needed`, 5/7) went stale only because covered files changed afterwards (CONTRIBUTING.md in commit 59ec1d1, planning documents). This run re-checked the current tree from scratch and folded in the owner's completed UAT.

## Verdict

The phase goal is achieved. All five roadmap success criteria hold on automated evidence that I re-ran myself on the current tree, and no truth is FAILED. The previous `human_needed` status rested on eight items that only a human or the owner's push could settle. `02-UAT.md` (status complete, 8 passed, 0 issues) records the owner's result for every one of them: clean-clone start, Czech walk-through, CI green on GitHub, private vulnerability reporting enabled, the two backstop truths accepted, the A-1 decision (accepted for Phase 2) and the A-3 decision (an explicit override). The human verification list is therefore empty and the status is `passed`.

Scope of what I could and could not check myself: I cannot observe the owner's clean clone, browser walk-through or the GitHub run; those rest on the UAT record. Everything else below is my own evidence. The open code-review warnings (below) do not fail a roadmap criterion or a plan truth; they remain owner follow-ups.

## What changed since the previous report

`git diff 59ec1d1~1 HEAD -- . ':!.planning'` touches one file: `CONTRIBUTING.md` (11 insertions, 11 deletions, the checklist of verified repository settings). No application, migration, test, config or workflow file changed. The 170 non-planning covered files are the same set as before; the digest differs only because of that edit and the planning documents.

## Evidence I produced myself (not taken from SUMMARY.md)

| Check | Command | Result |
|-------|---------|--------|
| Full test suite on PostgreSQL 18 | `ddev exec vendor/bin/pest` | 411 passed, 2210 assertions, exit 0 (26 s), including the 8-process concurrency tests and the lock-free mutation run |
| Formatting | `ddev composer lint` | Pint PASS, 147 files |
| Static analysis | `ddev composer stan` | Larastan level 8, "No errors", no baseline |
| Licence allowlist | `ddev composer check-licenses` | 200 packages, every one has an allowed licence |
| Advisories | `ddev composer audit --locked` | no security vulnerability advisories |
| Shell self-tests | `bash scripts/tests/run.sh` | PASS 10, FAIL 0, SKIP 0 (includes the workflow `needs` mutation test) |
| Timestamp convention | `psql` over `information_schema` on `kokpit_test` | 0 columns of type `timestamp without time zone` |
| Runtime | `supervisorctl status` in the web container | queue-worker and scheduler RUNNING, mailpit, nginx, php-fpm RUNNING |
| Panel routes | `artisan route:list --path=admin` | 5 routes: dashboard, login, logout, MFA set-up, profile (one panel) |
| Install command surface | `artisan kokpit:install --help` | options `--name`, `--email` only; no password option |
| Licence | `composer.json`, `LICENSE` | `AGPL-3.0-only`; AGPL v3 text |
| Debt markers | grep for TBD, FIXME, XXX over tracked application files | none |
| Production code drift | `git diff 59ec1d1~1 HEAD -- . ':!.planning'` | `CONTRIBUTING.md` only |
| Fingerprint | `gsd_run query verification.fingerprint` | 196 covered files, digest copied verbatim into the frontmatter |

The previous report's independent checks that depend on unchanged code (catalogue query, `Gate::before` reflection, `verify.artifacts` and `verify.key-links` over the 13 plans, local hygiene and workflow lint) were not repeated; the diff above shows no code they could be sensitive to changed, and the suite that encodes them (411 tests) passes again.

## Goal Achievement

### Observable Truths (ROADMAP success criteria are the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Fresh checkout following README and `.env.example` starts with `ddev start` (PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS, queue worker, scheduler), boots on PostgreSQL; `kokpit:install` creates the Admin without a default password; Admin enables 2FA, logs in to the single SPA panel, sees Czech text with Czech formats and Europe/Prague times | VERIFIED | `.ddev/config.yaml` pins the stack (`DdevConfigTest`, `CiParityTest`); daemons RUNNING in my session; `InstallCommand` has no password option and `--help` confirms it; `EnsureAdminHasTwoFactor` enforcement, challenge login and recovery-code tests pass; `LocalisationServiceProvider` and the format tests pin Prague time and Czech formats. Residual clean-clone and visual parts: owner UAT tests 1 and 2, both pass |
| 2 | Every push runs CI on PostgreSQL with tests, static analysis, formatting, secret scan and a dependency licence allowlist; `LICENSE` (AGPL-3.0) matches `composer.json`; README, SECURITY.md, CONTRIBUTING.md and `.env.example` in sync | VERIFIED | `hygiene.yml` jobs and the `CI Passed` gate are tested by `test-workflow.sh` (mutation case passes in my run) and `CiParityTest`; lint, stan, licence check and audit pass locally; `RepositoryFilesTest` and `EnvExampleTest` pass. Runner execution: owner UAT test 3 (pass); private vulnerability reporting: UAT test 4 (pass). The workflow triggers on push to `main` and on `pull_request` |
| 3 | Schema tests fail the build on a non-UUID v7 key or morph column, a non-`timestamptz` timestamp, or a morph type outside the enforced map; a pilot immutability trigger, CHECK and partial index are proven by raw-SQL tests | VERIFIED | `SchemaConventionsTest`, `KeysAndTimestampsTest`, `MorphMapTest`, `PackageModelsTest` and `ImmutabilityPilotTest` all pass; my catalogue query finds zero timestamps without time zone. `sessions.id` is an opaque session token (string by design, documented exempt entry) while `sessions.user_id` is uuid |
| 4 | Money is integer minor units plus ISO 4217 currency via one value object with a single documented rounding point; the sequence allocator is gap-free and duplicate-free under real parallel-process tests on PostgreSQL | VERIFIED | `MoneyTest`, `MoneyCastTest` and `MoneyBoundaryTest` pass. The concurrency test hands exactly 1..200 to 8 parallel PHP processes, stays consecutive when every fourth transaction rolls back, and the lock-free mutation run is detected as a failure (all visible in my run output), so the harness can fail |
| 5 | A Partner test account sees nothing by default (policies plus global scopes); the canary harness with two fictional clients passes; a registry test fails when any Resource, Page, Widget or relation manager lacks an explicit access rule | VERIFIED | `FailClosedScopeTest`, `PolicyBaseTest`, `DeniedModelsTest`, `PanelAccessTest`, `RouteWalkTest`, `CanaryRegistryTest`, `SystemRunCommandsTest`, `ModelDeclarationTest` and `PanelRegistryTest` all pass in the 411. The route walk requests every panel GET route as a Partner and asserts no client-B canary appears |
| 6 | Plan truth (02-03, `verification: backstop`): ties on timestamp ordering are broken by the UUID v7 id | VERIFIED by explicit human confirmation | Non-inferable by presence: no list exists in Phase 2. The convention is documented in CONTRIBUTING; the owner confirmed it as documentation-only until the first sorted list, which must bring a held-out test (02-UAT.md test 7, pass). Obligation carried to the first list feature |
| 7 | Plan truth (02-08, `verification: backstop`): sorted Czech text lists use Czech collation | VERIFIED by explicit human confirmation, obligation deferred to Phase 4 | No sortable text column exists in Phase 2. The owner confirmed deferral to Phase 4 (02-UAT.md test 8, pass); the first sortable text column must add a collation test (ch sorts after h) |
| 8 | Approved UI-SPEC contract A-3: primary colour is Indigo | PASSED (override) | `AdminPanelProvider` line 63 still sets `Color::Amber`. Override: owner keeps Amber, trade-off recorded (about 3.2:1 with white text, below WCAG AA); accepted by Petr Gräf on 2026-10-08 (02-UAT.md test 5). Not a roadmap criterion; listed so the deviation stays visible |

**Score:** 8/8 truths verified (0 present-but-behavior-unverified). Rows 6 and 7 are backstop truths that the verifier cannot prove from code; they count only because the owner supplied the explicit human confirmation recorded in the UAT. Row 8 counts through the accepted override.

### Prohibitions (`must_haves.prohibitions`, all `verification: test`)

| Prohibition | Plan | Status | Enforcement evidence |
|-------------|------|--------|----------------------|
| No real or instance-specific data committed | 02-01 | VERIFIED | Hygiene checks and path-scoped allowlist; hook and CI scan |
| No rounding outside the single method | 02-05 | VERIFIED | `MoneyBoundaryTest` passes |
| No duplicate, skipped or implicitly reset numbers | 02-06 | VERIFIED | Concurrency test and mutation run pass |
| No frozen-row change or delete through any writer | 02-07 | VERIFIED | `ImmutabilityPilotTest` and `ImmutabilityTest` pass (KP001, TRUNCATE refusal) |
| No Admin default password, password argument, or logging of it; no production boot with 2FA off | 02-09 | VERIFIED | `--help` shows no password option; `InstallCommandTest`, `ProductionConfigGuardTest` pass |
| No Partner read of another client's or Admin-only rows by default | 02-10 | VERIFIED | Isolation suite passes |
| No Partner read through any panel surface; new panel class denied until declared | 02-11 | VERIFIED | `PanelRegistryTest`, `RouteWalkTest` pass |
| No dependency without or with an incompatible licence | 02-12 | VERIFIED | `check-licenses` (200 packages) and `LicenceCheckTest` pass |
| No real data or contact address in README, SECURITY.md, CONTRIBUTING.md | 02-13 | VERIFIED | `RepositoryFilesTest` passes |

All test-tier prohibitions have wired enforcement, so none is flagged unverified.

### Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | Czech collation for sorted text lists (02-08 backstop) | Phase 4 | First sortable Czech text lists arrive with clients and projects; the owner confirmed the deferral. Carried obligation, not proof |

### Advisory (New Scope, Unevidenced)

None.

### Required Artifacts

All declared artifacts of the 13 plans exist, are substantive and wired; the full test suite exercises them and the diff since the previous report touches none of them. One carried-over info item: 02-04 declared `tests/Feature/Schema/PackageModelsTest.php` as containing `morphMap`; rule R9 actually lives in `SchemaConventionsTest.php` and passes. The behaviour exists, only the planned location differs.

### Key Link Verification

Unchanged since the previous run (13 of 13 plans passed `verify.key-links`); the code behind them did not change and the dependent tests pass.

### Data-Flow Trace (Level 4)

No phase code renders dynamic business data. The test-only canary resource reads the isolated `CanaryRecord` table through the scope (proven by the route walk) and the dashboard empty state is static by design. Not applicable beyond that.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 8 parallel processes allocate 1..200 gap-free; lock-free mutation detected | Pest `tests/Concurrency` (within the 411) | passed | PASS |
| Install command exposes no password option | `ddev artisan kokpit:install --help` | only `--name`, `--email` | PASS |
| No timestamp without time zone | `psql` over `information_schema` on `kokpit_test` | 0 | PASS |
| Panel is a single SPA panel at `/admin` | `artisan route:list --path=admin` | 5 routes | PASS |

### Probe Execution

No `probe-*.sh` scripts declared or present. Step skipped.

### Requirements Coverage

All 13 phase requirement IDs (FND-01, FND-02, FND-03, FND-04, FND-05, FND-06, FND-11, FND-12, FND-13, FND-14, FND-17, FND-18, FND-20) appear in the `requirements:` frontmatter of the 13 PLAN files and are marked `[x]` and "Complete" in REQUIREMENTS.md (traceability rows for Phase 2). REQUIREMENTS.md maps no other ID to Phase 2, so there are no ORPHANED requirements.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| FND-01 | 02-01, 02-02, 02-12, 02-13 | Installs and runs from README and `.env.example`; Laravel, PostgreSQL, one Filament SPA panel | SATISFIED | Panel boot test, `boot-from-env-example.sh`, route list; clean clone confirmed in UAT 1 |
| FND-02 | 02-03, 02-04 | UUID v7 keys, FKs and morph columns incl. package tables, schema tests | SATISFIED | Schema suite passes |
| FND-03 | 02-03, 02-04, 02-08 | `timestamptz`, UTC storage, Prague display, enforced morph map | SATISFIED | Schema and format tests; 0 tz-less columns |
| FND-04 | 02-05 | Integer minor units plus ISO 4217, one value object, one rounding point | SATISFIED | `Money`, `MoneyCast`, arch test |
| FND-05 | 02-06 | One gap-free allocator, real parallel-process proof | SATISFIED | Concurrency suite plus mutation run |
| FND-06 | 02-09, 02-10, 02-11 | Roles Admin and Partner, policies and global scopes, default-deny | SATISFIED, with the `Gate::before` limit below | Scope, policy base and access-rule tests |
| FND-11 | 02-08 | Czech via `lang/cs`, Czech formats | SATISFIED | Localisation tests; Czech walk-through passed (UAT 2) |
| FND-12 | 02-07 | Constraint and immutability-trigger pattern with raw-SQL helper | SATISFIED | Pilot and unit tests |
| FND-13 | 02-12 | CI on PostgreSQL, static analysis, formatting, secret scan, licence allowlist | SATISFIED | Workflow tests, local lint/stan/licence; CI green confirmed in UAT 3 |
| FND-14 | 02-01, 02-02, 02-13 | LICENSE, matching `composer.json`, README, SECURITY.md, CONTRIBUTING.md, `.env.example` in sync | SATISFIED | `RepositoryFilesTest`, `EnvExampleTest` |
| FND-17 | 02-09 | Admin by install command, no default password, Admin 2FA | SATISFIED | Install and 2FA tests; TOTP set-up submission confirmed in UAT 2 |
| FND-18 | 02-10, 02-11 | Canary harness from Foundation, registry test | SATISFIED | Canary registry, route walk, registry test |
| FND-20 | 02-02, 02-12, 02-13 | DDEV environment, same versions in tests and CI | SATISFIED | `DdevConfigTest`, `CiParityTest`, running project, UAT 1 |

### Anti-Patterns Found

None in the phase's application code: no TBD, FIXME or XXX markers, no stubs. No debt-marker blocker.

### Open review findings (not blockers)

`02-REVIEW.md` raised 18 findings, all still `open` in `02-REVIEW-DISPOSITION.md` (none triaged). None fails a roadmap success criterion or a plan truth, so they are WARNINGS the owner should schedule, not gaps. The Phase 2 security audit (`02-SECURITY.md`, `threats_open: 0`) closed every register threat. Highest-value items:

| Item | Assessment |
|------|------------|
| Permission package `Gate::before` runs before `KokpitPolicy` | Latent; no permissions exist today. The first plan that introduces permissions must close it and extend the isolation tests. Recorded in CONTRIBUTING |
| WR-03 media library default disk `public` | Contradicts the private-bucket constraint if a deployment omits `MEDIA_DISK`; no upload path exists yet; natural fix in Phase 3 (private storage) |
| WR-04 fail-closed scope makes package maintenance commands silent no-ops | Becomes live with the activity log (Phase 3) and webhooks (Phase 11) |
| WR-05 / WR-06 session cookie `Secure` not enforced; production guard only for `APP_ENV=production` | Production hardening for the Phase 3 deploy work |
| WR-01 / WR-02 escape-hatch scanner and model scan narrower than they claim | Harden before Phase 4 adds real models |
| WR-07 to WR-10, IN-01 to IN-08 | Case-sensitive e-mail identity, 2FA reset leaves sessions valid, queue not `after_commit`, `Money::convert` accepts a zero or negative rate, and minor items |

Owner-accepted decisions (UAT): A-1 no Admin password recovery in Phase 2 (web reset and a CLI password-reset link arrive in Phase 4); A-3 Amber kept (override, truth 8).

### Human Verification Required

None outstanding. The eight items of the previous report were completed by the owner in `02-UAT.md` (8 of 8 pass, 0 issues).

### Gaps Summary

There are no gaps. The code, schema, money, numbering and isolation work is enforced by tests that I re-ran and that include mutation or self-check cases proving the guards can fail. The only change since the previous report is a documentation edit; production code is unchanged. Recommended follow-up, outside this phase's verdict: triage the 18 review findings before Phase 3 and close WR-03, WR-04, WR-05 and WR-06 inside Phase 3.

---

_Verified: 2026-10-08_
_Verifier: Claude (gsd-verifier)_
