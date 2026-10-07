---
phase: 02-platform-foundation
verified: 2026-10-07T21:00:00Z
status: human_needed
score: 5/7 must-haves verified
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
covered_digest: "v3:sha256:49d980d0d9af7f2c87e9e2f681618532b143d67ab6324550117c9f7465423396"
behavior_unverified: 0
overrides_applied: 0
re_verification: null
gaps: []
deferred:
  - truth: "Lists sorted by a Czech text column use Czech collation (ch sorts after h) wherever such a list exists (02-08 backstop truth; no sortable text list exists in Phase 2)"
    addressed_in: "Phase 4"
    evidence: "Phase 4 delivers the first sortable Czech text lists (clients, projects: success criterion 1, 'archived clients disappear from lists'); the owner confirmed deferral of the collation backstop to Phase 4. The Phase 4 roadmap text does not name collation explicitly, so this is recorded as a carried obligation, not as proof."
advisory: []
coincidental_reliance_items: []
human_verification:
  - test: "Clean-clone start (FND-01, FND-20): on a Docker + DDEV 1.25+ host, clone the pushed branch into an empty directory and follow only README.md and .env.example (ddev start, cp .env.example .env, ddev composer install, ddev artisan key:generate, ddev artisan migrate, ddev artisan kokpit:install). Then ddev describe and ddev exec supervisorctl status."
    expected: "Every step works with no extra knowledge. Web (PHP 8.5), db (PostgreSQL 18), redis, rustfs and mailpit are OK, queue-worker and scheduler are RUNNING without a restart loop before composer install, the RustFS bucket kokpit-dev exists, and the printed panel URL opens the login page."
    why_human: "Needs an empty clone on a real Docker host. The existing DDEV project in this working tree was set up incrementally, so it cannot prove the README alone is sufficient. The CI boot job covers the same steps without ddev start-up."
  - test: "Czech walk-through (FND-11, FND-17, FND-20): with KOKPIT_REQUIRE_ADMIN_2FA=true and APP_LOCALE=cs in .env, open the login page, sign in as the Admin, complete TOTP set-up (QR code, manual key, recovery codes), sign out and sign in again with the TOTP challenge, open the dashboard and the profile; then sign in as a Partner test account. Check light and dark mode."
    expected: "All visible text is Czech (no English labels, no raw translation keys), dates as j. n. Y H:i in Europe/Prague, Czech diacritics render in Inter (not a fallback font), the QR code and recovery-code layout is usable, dark-mode button and text contrast is acceptable. The Admin cannot reach the dashboard before TOTP is set up. The Partner reaches the dashboard without being forced into TOTP and sees the neutral empty state."
    why_human: "Visual and UX judgement over stock Filament pages. No test judges rendered language, font or contrast. The TOTP set-up submission itself (scan, enter code, enable) is also not driven end to end by any automated test; tests cover enforcement redirect, an existing secret, the code challenge and recovery-code sign-in. Note: in this DDEV environment config('app.locale') currently resolves to en, so the local .env predates .env.example and must carry APP_LOCALE=cs before the walk-through."
  - test: "CI green after the owner pushes (FND-13): open a pull request and read the Hygiene run."
    expected: "Jobs scan, workflow-lint, tests, static-analysis and dependencies succeed on ubuntu-24.04 (setup-php installs PHP 8.5, the postgres:18 and redis:7 services start, Pest including the concurrency child processes passes on the runner) and CI Passed is green and is accepted by the organisation ruleset."
    why_human: "Requires a push to GitHub, an owner action. actionlint and zizmor pass locally, the workflow structure is tested, but the runner assumptions (PHP 8.5 availability, service containers, ruleset name) cannot be exercised here. Note the workflow triggers on push to main and on pull_request only, so a push to a feature branch without a PR does not run CI."
  - test: "Enable GitHub private vulnerability reporting for the repository."
    expected: "The 'Report a vulnerability' button exists, because SECURITY.md names it as the only reporting channel and has no fallback address by design."
    why_human: "A repository setting the owner must switch on; not visible from the codebase."
  - test: "Owner decision on the UI-SPEC colour contract (A-3): AdminPanelProvider still sets the primary colour to Color::Amber while the approved UI-SPEC and the UI review require Indigo (Amber with white text is about 3.2:1, below WCAG AA)."
    expected: "Either apply the one-line change to Indigo and re-check dark-mode contrast in the walk-through, or accept the deviation with an override entry (must_have, reason, accepted_by, accepted_at). No plan took ownership of A-3."
    why_human: "A design-contract deviation that is not a roadmap success criterion, so it does not make the phase goal fail, but it is a real gap against an approved spec and the owner should decide."
  - test: "Owner decision on Admin password recovery (UI-SPEC A-1): there is no web or CLI path to recover a forgotten Admin password; kokpit:admin:reset-2fa resets TOTP only and README states the limit."
    expected: "Accept for Phase 2 (the spec says web reset arrives with the Phase 4 invitation mail flow) or schedule a CLI password reset before the first real deployment."
    why_human: "Product and operations decision, not a verifiable behaviour."
  - test: "Backstop truth from 02-03: where a list orders by a timestamp, ties are broken by the UUID v7 id (verification: backstop, convention documented in CONTRIBUTING)."
    expected: "Confirm the convention is acceptable as documentation-only until the first sorted list exists; the first list feature must add a held-out test."
    why_human: "Non-inferable truth. No list exists in Phase 2, so presence and wiring cannot prove it; status is insufficient_spec, not failed."
  - test: "Backstop truth from 02-08: sorted Czech text lists use Czech collation (ch sorts after h)."
    expected: "Confirm deferral to Phase 4 (already decided by the owner); the first sortable text column must bring a collation test."
    why_human: "Non-inferable truth. No sortable text list or collated column exists in Phase 2; status is insufficient_spec, not failed."
---

# Phase 2: Platform Foundation Verification Report

**Phase Goal:** A running, installable Laravel + Filament application whose data conventions, money handling, numbering and Partner default-deny access are enforced by failing tests before any feature is built
**Verified:** 2026-10-07
**Status:** human_needed
**Re-verification:** No - initial verification

## Verdict

The phase goal is achieved in the codebase. The five roadmap success criteria hold on automated evidence that I re-ran myself, and no truth is FAILED. The status is `human_needed` rather than `passed` because (a) four things only a human or the owner's push can prove (clean-clone `ddev start`, the Czech/visual walk-through, CI on GitHub, GitHub private vulnerability reporting), (b) two plan truths are flagged `backstop` and cannot be proven by presence, and (c) two recorded gaps against the approved UI-SPEC (Indigo colour A-3, no Admin password recovery A-1) need an owner decision. None of these is a roadmap success criterion, so none is a blocker.

## Evidence I produced myself (not taken from SUMMARY.md)

| Check | Command | Result |
|-------|---------|--------|
| Full test suite on PostgreSQL 18 | `ddev exec vendor/bin/pest` | 411 passed, 2210 assertions, exit 0 |
| Formatting | `ddev composer lint` | Pint PASS, 147 files |
| Static analysis | `ddev composer stan` | Larastan level 8, no errors, no baseline |
| Shell self-tests | `bash scripts/tests/run.sh` | PASS 10, FAIL 0, SKIP 0 |
| Hygiene scan | `scripts/check-sensitive.sh --all` | clean (generic patterns only, no local denylist in this session) |
| Workflow lint | `actionlint`, `zizmor --offline .github/workflows` | no findings |
| Dependencies | `composer validate --strict`, `composer check-licenses`, `composer audit --locked` | valid; 200 packages all on the allowlist; no advisories |
| Independent catalogue query on `kokpit_test` | `psql` over `information_schema` | only `migrations.id` (integer), `failed_jobs.id` (bigint) and `sessions.id` (string) are non-uuid ids, all in the documented exempt map; zero `timestamp without time zone` columns, 26 `timestamptz`; every own and package table `id` is uuid with `uuidv7()` default |
| Runtime | `php -v`, `ddev describe`, `supervisorctl status` | PHP 8.5.8, Laravel 13.35.0, PostgreSQL 18.6, redis, rustfs, rustfs-init OK; queue-worker, scheduler, mailpit, nginx, php-fpm RUNNING |
| Panel routes | `artisan route:list --path=admin` | single panel: dashboard, login, logout, MFA set-up, profile |
| Install command surface | `artisan kokpit:install --help` | options `--name`, `--email` only; no password option or argument |
| Gate::before | reflection on the Gate | exactly one before-callback registered (the permission package), confirming the recorded limit |
| Debt markers | grep for TBD, FIXME, XXX, TODO, HACK, PLACEHOLDER over tracked files outside vendored/lock/tests-of-scanner | none |
| Plan artifacts and key links | `verify.artifacts`, `verify.key-links` for all 13 plans | 13/13 plans pass key links; artifacts all pass except one pattern miss (see below) |

## Goal Achievement

### Observable Truths (ROADMAP success criteria are the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Fresh checkout following README and `.env.example` starts with `ddev start` (PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS, queue worker, scheduler), boots on PostgreSQL; `kokpit:install` creates the Admin without a default password; Admin enables 2FA, logs in to the single SPA panel, sees Czech text with Czech formats and Europe/Prague times | VERIFIED (automated part); clean-clone and visual residual routed to human items 1 and 2 | `.ddev/config.yaml` pins PHP 8.5, postgres 18, two daemons, test-DB hook; services running. `InstallCommand` has no password option, reads `KOKPIT_ADMIN_PASSWORD` only non-interactively, refuses a second Admin, takes an advisory lock. `EnsureAdminHasTwoFactor` is attached via `multiFactorAuthenticationRequiredMiddlewareName`; tests cover redirect to set-up, no redirect loop, Partner never forced, challenge login, recovery-code single use, encrypted secret storage. `LocalisationServiceProvider` sets Prague timezone and `j. n. Y` formats; tests pin DST and day-boundary cases and the Czech login page and validation text. The fresh-process test proves `cs` is the default even if the env omits the variable |
| 2 | Every push runs CI on PostgreSQL with tests, static analysis, formatting, secret scan and a dependency licence allowlist; `LICENSE` (AGPL-3.0) matches `composer.json`; README, SECURITY.md, CONTRIBUTING.md and `.env.example` in sync | VERIFIED in the repository; runner execution routed to human item 3 | `hygiene.yml` has scan, workflow-lint, tests (postgres:18 + redis:7), static-analysis (Pint, Larastan), dependencies (validate, audit, licence allowlist) and `CI Passed` needing all five; `test-workflow.sh` mutation-tests the needs list; `CiParityTest` ties PHP and PostgreSQL versions to DDEV. `composer.json` has `AGPL-3.0-only`, LICENSE is the AGPL v3 text, `RepositoryFilesTest` and `EnvExampleTest` pass. Note: triggers are push to `main` and `pull_request`, not every branch push |
| 3 | Schema tests fail the build on any own or package table with a non-UUID v7 key or morph column, a non-`timestamptz` timestamp, or a morph type outside the enforced map; a pilot immutability trigger, CHECK and partial index are proven by raw-SQL tests | VERIFIED | `SchemaConventionsTest` applies rules R1-R9 to the catalogue with an exempt map of exactly four reasoned entries and self-checks for each rule; my independent `psql` query agrees with it. All seven package models are `HasUuids` subclasses registered with their packages (R8). `Relation::enforceMorphMap` is active (R7); R9 exercises tokens, notifications, media, tags, permissions and activity and asserts the exact eight morph columns written. `ImmutabilityPilotTest` asserts KP001, 23514, 23505, 23503 and TRUNCATE refusal through `RawSql::expectSqlState` inside savepoints. `sessions.id` is exempt as an opaque session token (a string by design) while `sessions.user_id` is uuid; I read the roadmap wording "sessions" as the latter |
| 4 | Money is integer minor units plus ISO 4217 currency via one value object with a single documented rounding point; the sequence allocator is gap-free and duplicate-free under real parallel-process tests on PostgreSQL | VERIFIED | `Money` is `final readonly`, private constructor, no float API; `RoundingMode` appears only in `fromExactMinor`, and `MoneyBoundaryTest` confines Brick to `app/Domain/Shared/Money`. `SequenceAllocator::next` throws outside a transaction, locks with `SELECT ... FOR UPDATE`, never resets; the DB enforces unique key, `next_value >= 1` and a key-format CHECK. The concurrency test spawns 8 real PHP processes x 25 allocations behind a shared barrier, asserts exactly 1..200, at least two pids, and again with every fourth transaction rolled back; a mutation run with a lock-free allocator must fail, so the harness can detect the defect. All passed in my run |
| 5 | A Partner test account sees nothing by default (policies plus global scopes); the canary harness with two fictional clients passes; a registry test fails when any Resource, Page, Widget or relation manager lacks an explicit access rule | VERIFIED | `PartnerScope` returns all rows only for Admin or an explicit system run, constrains a Partner with a `client_id` through the model, and adds `1 = 0` for every other state; `PartnerContext` is `scoped()` and `runAsSystem` restores state in `finally`. `KokpitPolicy::before` is the single Admin rule; every ability denies by default. `AccessRules::allows` denies a class without `#[AccessRule]`, even for the Admin. `PanelRegistryTest` reflects over the panel registry plus a scan of `app/Filament`, reports offenders sorted, and has non-vacuity and self-check cases. `RouteWalkTest` requests every panel GET route as Partner A and as a Partner without a client, asserts no client B canary or id in any body, 403/404 for B's record routes, 200 and own canary for A's, plus Livewire table, search and global-search checks, and shows the Admin can see B's record (so the refusal comes from the scope). `ModelDeclarationTest` and `CanaryRegistryTest` force every model to be isolated, denied or declared not scoped |
| 6 | Plan truth (02-03, `verification: backstop`): ties on timestamp ordering are broken by the UUID v7 id | ? UNCERTAIN (insufficient_spec) | Documented in CONTRIBUTING; no list exists in Phase 2, so there is nothing to test. Human item 7 |
| 7 | Plan truth (02-08, `verification: backstop`): sorted Czech text lists use Czech collation | ? UNCERTAIN (insufficient_spec), deferred to Phase 4 | No sortable text column exists; owner confirmed deferral. Human item 8 |

**Score:** 5/7 truths verified (0 present-but-behavior-unverified). The two remaining truths are backstop truths that abstain by design, not failures.

The per-plan `must_haves.truths` for plans 02-01 to 02-13 were each checked against code and tests; they are subsumed by the rows above. Notable spot-verifications: `.gitignore` placeholders tracked (12 storage and bootstrap/cache placeholders), no `AGENTS.md`, `CLAUDE.md`, `package.json`, Vite or welcome view in the tree, composer.lock allowlist entries scoped to that path; the `Money::format` and `MoneyCast` paths run on PostgreSQL; the 2FA reset command writes no e-mail to the log; the production guard throws for 2FA off and canary harness on.

### Prohibitions (`must_haves.prohibitions`, all `verification: test`)

| Prohibition | Plan | Status | Enforcement evidence |
|-------------|------|--------|----------------------|
| No real or instance-specific data committed | 02-01 | VERIFIED | `check-sensitive.sh --all` clean; allowlist entries path-scoped to `composer.lock` |
| No rounding outside the single method | 02-05 | VERIFIED | `MoneyBoundaryTest` (mutation-checked per SUMMARY; confinement also confirmed by my grep: no `Brick\` import outside the Money directory) |
| No duplicate, skipped or implicitly reset numbers | 02-06 | VERIFIED | Real parallel-process test and mutation run |
| No frozen-row change or delete through any writer | 02-07 | VERIFIED | Trigger KP001 on UPDATE, DELETE and TRUNCATE proven by raw SQL |
| No Admin default password, password argument, or logging of it; no production boot with 2FA off | 02-09 | VERIFIED | `--help` shows no password option; install and guard tests |
| No Partner read of another client's or Admin-only rows by default | 02-10 | VERIFIED | Fail-closed matrix on the canary model, policy base tests, declaration arch tests |
| No Partner read through any panel surface; new panel class denied until declared | 02-11 | VERIFIED | Registry test, `AccessRules` fail-closed, route walk |
| No dependency without or with an incompatible licence | 02-12 | VERIFIED | `scripts/check-licenses.php` and its unit tests (OR semantics, GPL-2.0-only, missing licence, malformed input) |
| No real data or contact address in README, SECURITY.md, CONTRIBUTING.md | 02-13 | VERIFIED | `RepositoryFilesTest` address detector with self-check; scanner clean |

Test-tier prohibitions all have wired enforcement, so none is flagged unverified.

### Required Artifacts

All declared artifacts of the 13 plans exist, are substantive and wired (`verify.artifacts`: every plan passes). One mismatch: 02-04 declared `tests/Feature/Schema/PackageModelsTest.php` as containing the string `morphMap` ("one row per package API plus rule R9"). R9 actually lives in `tests/Feature/Schema/SchemaConventionsTest.php` (`exercisePackages()` plus the R9 test, passing). The behaviour exists; only the planned location differs. Info.

### Key Link Verification

`verify.key-links`: 3/3, 3/3, 3/3, 3/3, 2/2, 2/2, 2/2, 2/2, 3/3, 3/3, 3/3, 3/3, 2/2 across plans 02-01 to 02-13. All wired.

### Data-Flow Trace (Level 4)

No phase code renders dynamic business data; the only data-bearing surfaces are the test-only canary resource (reads the isolated `CanaryRecord` table through the scope, proven by the route walk) and the dashboard empty state (static by design). Not applicable beyond that.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 8 parallel processes allocate 1..200 gap-free; lock-free mutation is detected | Pest `tests/Concurrency` (part of the 411) | passed | PASS |
| Install command exposes no password option | `ddev artisan kokpit:install --help` | only `--name`, `--email` | PASS |
| Only the documented ids are non-uuid, no tz-less timestamps | `psql` over `information_schema` on `kokpit_test` | 3 exempt ids, 0 tz-less, 26 timestamptz | PASS |
| Panel is a single SPA panel at `/admin` | `artisan route:list --path=admin` | 5 routes, one panel | PASS |
| Dev environment locale | `config('app.locale')` in this DDEV shell | `en` (the working copy's `.env` predates `.env.example`; code default is `cs`, proven by a fresh-process test) | NOTE, see human item 2 |

### Probe Execution

No `probe-*.sh` scripts declared or present. Step skipped.

### Requirements Coverage

All 13 phase requirement IDs appear in PLAN frontmatter, and all are marked Complete in REQUIREMENTS.md. No requirement mapped to Phase 2 in REQUIREMENTS.md is missing from a plan (no ORPHANED IDs).

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| FND-01 | 02-01, 02-02, 02-12, 02-13 | Installs and runs from README and `.env.example`; Laravel, PostgreSQL, one Filament SPA panel | SATISFIED (clean-clone run is human item 1) | Panel boot test, `boot-from-env-example.sh`, README order test |
| FND-02 | 02-03, 02-04 | UUID v7 keys, FKs and morph columns incl. package tables, schema tests | SATISFIED | R1-R9, independent catalogue query |
| FND-03 | 02-03, 02-04, 02-08 | `timestamptz`, UTC storage, Prague display, enforced morph map | SATISFIED | R4, R7, DST and boundary tests |
| FND-04 | 02-05 | Integer minor units plus ISO 4217, one value object, one rounding point | SATISFIED | `Money`, `MoneyCast`, arch test |
| FND-05 | 02-06 | One gap-free allocator, real parallel-process proof | SATISFIED | Concurrency test and mutation run |
| FND-06 | 02-09, 02-10, 02-11 | Roles Admin and Partner, policies and global scopes, default-deny | SATISFIED, with the Gate::before limit below | Scope, policy base, access rules |
| FND-11 | 02-08 | Czech via `lang/cs`, Czech formats | SATISFIED (visual completeness is human item 2) | Localisation tests, enum label checker, encoding test |
| FND-12 | 02-07 | Constraint and immutability-trigger pattern with raw-SQL helper | SATISFIED | Pilot and unit tests |
| FND-13 | 02-12 | CI on PostgreSQL, static analysis, formatting, secret scan, licence allowlist | SATISFIED in repo (green run is human item 3) | Workflow, tests, local lint |
| FND-14 | 02-01, 02-02, 02-13 | LICENSE, matching `composer.json`, README, SECURITY.md, CONTRIBUTING.md, `.env.example` in sync | SATISFIED | `RepositoryFilesTest`, `EnvExampleTest`, docs script |
| FND-17 | 02-09 | Admin by install command, no default password, Admin 2FA | SATISFIED (set-up submission is human item 2) | Install and 2FA tests |
| FND-18 | 02-10, 02-11 | Canary harness from Foundation, registry test | SATISFIED | Canary registry, route walk, registry test |
| FND-20 | 02-02, 02-12, 02-13 | DDEV environment, same versions in tests and CI | SATISFIED (clean-clone run is human item 1) | `DdevConfigTest`, `CiParityTest`, running project |

### Decisions D-01 to D-12 honoured

D-01 `users.client_id` nullable, not fillable (confirmed in `User`). D-02 fail-closed scope and policy base (read). D-03 attribute-based registry, no hand list (read). D-04 test-only tenant model, no `clients` table (the migration list has none). D-05 interactive install, password never an argument, refuses a second Admin (read). D-06 mandatory Admin TOTP, switchable only outside production (read; see WR-06 for the narrow `isProduction()` test). D-07 built-in Filament TOTP with recovery codes, CLI reset (read). D-08 thin own `Money` over brick/money. D-09 HALF_UP once. D-10 rates as decimal strings, `NUMERIC(20,10)`. D-11 counter table with `FOR UPDATE`. D-12 generic scope key, no resets.

### Anti-Patterns Found

None in the phase's application code. No TBD, FIXME, XXX, TODO or placeholder markers. No stubs; `return []` style hits are test fixtures. No debt-marker blocker.

### Recorded gaps and open review findings, graded

None of the following fails a roadmap success criterion or a plan truth, so none is a BLOCKER. They are WARNINGS the owner should see.

| Item | Grade | Assessment |
|------|-------|------------|
| UI-SPEC A-3, Indigo primary colour not applied (`AdminPanelProvider` still `Color::Amber`) | WARNING, decision requested | Real deviation from an approved design contract; the UI review calls it a blocker for the colour pillar (15/24 overall). No plan owned it (02-09 excluded it, 02-11 and 02-13 deferred it). One-line fix or an explicit override. Human item 5 |
| UI-SPEC A-1, no Admin password recovery | WARNING, decision requested | Documented in README; only TOTP reset exists. Acceptable for a pre-deployment phase, risky for the first real instance. Human item 6 |
| Permission package `Gate::before` runs before `KokpitPolicy` | WARNING (latent) | Confirmed by reflection: one before-callback is registered. No permissions exist today, so no current exposure; a permission named like a policy ability would bypass the policy default-deny. Recorded in CONTRIBUTING. The first plan that introduces permissions must close it and extend the isolation tests (US-01 and Phase 4/12 work) |
| WR-03 media library default disk `public` | WARNING | Contradicts the private-bucket constraint if a deployment forgets `MEDIA_DISK`. No upload path exists yet; Phase 3 (FND-16, private storage) is the natural place to fix it. Not deferred formally because Phase 3 does not name it |
| WR-04 fail-closed scope turns package maintenance commands (`activitylog:clean`, `model:prune` for webhook calls) into silent no-ops | WARNING | Retention would never apply. Becomes live in Phase 3 (activity log) and Phase 11 (webhooks); fix by running console and queue work as system by default |
| WR-05 session cookie `Secure` not enforced, WR-06 production guard only for `APP_ENV=production` | WARNING | Production-hardening gaps relevant to the Phase 3 deploy work (FND-15); `APP_ENV=staging` would skip the 2FA and canary guards |
| WR-01 escape-hatch scanner narrower than it claims, WR-02 model scan limited to `app/Domain/**/Models` | WARNING | The tests overstate coverage; a later phase can add a bypass the arch test does not see. Harden before Phase 4 adds real models |
| WR-07 case-sensitive e-mail identity, WR-08 2FA reset leaves sessions valid, WR-09 queue not `after_commit`, WR-10 `Money::convert` accepts a zero or negative rate | WARNING | I confirmed WR-10 in the code (the regex allows a sign and zero). WR-09 matters from Phase 10 when jobs are dispatched inside issue transactions |
| IN-01 to IN-08 | INFO | Missing `down()` in four migrations, Redis eviction policy, skeleton leftovers, Immutability helper limited to its own state column, GET-only route walk, hard-coded test DB name, machine-translated Czech action labels, timing-based concurrency barrier and a paid-package import in media config |
| Disposition record | INFO | All 18 findings are `open` in `02-REVIEW-DISPOSITION.md`; none is triaged |

### Human Verification Required

See the `human_verification` list in the frontmatter (eight items): clean-clone start, Czech walk-through (including TOTP set-up submission, dark mode and diacritics), CI after push, enabling private vulnerability reporting, the A-3 colour decision, the A-1 recovery decision, and the two backstop truths.

### Gaps Summary

There are no gaps that fail the phase goal or a success criterion, so `gaps:` is empty. The code, schema, money, numbering and isolation work is genuinely enforced by tests that I ran and read, including mutation or self-check cases that prove the guards can fail. The residual risk is in what a unit suite cannot see: the first-run experience on a clean machine, the rendered Czech UI, the CI runner, and a handful of production-hardening defaults that the advisory review left open. Recommend the owner triages the 18 review findings before Phase 3, closes WR-03, WR-04, WR-05 and WR-06 inside Phase 3, and decides A-3 and A-1.

---

_Verified: 2026-10-07_
_Verifier: Claude (gsd-verifier)_
