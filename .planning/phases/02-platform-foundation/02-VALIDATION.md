---
phase: "02"
slug: "platform-foundation"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: validated
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-07"
---

# Phase 02 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.0 on PHPUnit 13.3.6 with `pestphp/pest-plugin-laravel`; hygiene harness in bash (`scripts/tests/run.sh`) |
| **Config file** | `phpunit.xml` (PostgreSQL `kokpit_test`), `tests/Pest.php`, `phpstan.neon`, `pint.json` — Wave 0 creates them |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Unit tests/Feature/Schema tests/Arch` (from 02-05 on; before that `ddev exec vendor/bin/pest tests/Unit tests/Feature`) |
| **Full suite command** | `ddev composer ci && bash scripts/tests/run.sh && scripts/check-sensitive.sh --all` (`composer ci` = test, lint, stan, check-licenses inside DDEV, from 02-12; the bash hygiene suite and the scanner run on the host) |
| **Estimated runtime** | ~30 seconds (quick), a few minutes (full) |
| **Execution order** | 13 plans in 13 waves, strictly sequential: every plan from 02-02 on runs Pest in the single DDEV project against the shared `kokpit_test` database |

---

## Sampling Rate

- **After every task commit:** Run the quick run command (the pre-commit hook runs `scripts/check-sensitive.sh`)
- **After every plan wave:** Run the full suite command
- **Before `/gsd-verify-work`:** Full suite must be green, CI green, manual `ddev start` on a clean clone
- **Max feedback latency:** 30 seconds (quick run)

---

## Per-Task Verification Map

Task IDs are `02-<plan>-<task>`. Checkpoint tasks have no automated command; their outcome is recorded in the plan SUMMARY.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 02-01-01 | 02-01 | 1 | FND-01 | T-02-01 | Maintainer approves the composer.lock allowlist entries (blocking-human) | decision | — | n/a | ✅ green |
| 02-01-02 | 02-01 | 1 | FND-14 | — | Owner chooses the SPDX licence id (one-way) | decision | — | n/a | ✅ green |
| 02-01-03 | 02-01 | 1 | FND-01, FND-14 | T-02-01..04, T-02-SC | Skeleton commit passes the hook; fresh clone installs | hygiene + fresh clone | `scripts/check-sensitive.sh --all && bash scripts/tests/run.sh && composer validate --strict && php artisan route:list --path=admin` | ✅ | ✅ green |
| 02-02-01 | 02-02 | 2 | FND-20, FND-01 | T-02-05 | Boot on PostgreSQL 18; test DB guard fires before any migration | feature | `ddev start && ddev exec vendor/bin/pest tests/Feature/Boot` | ✅ | ✅ green |
| 02-02-02 | 02-02 | 2 | FND-20, FND-14 | T-02-06, T-02-07 | DDEV services and daemons; `.env.example` in sync both ways | static + service | `ddev exec vendor/bin/pest tests/Feature/Repo/DdevConfigTest.php tests/Feature/Repo/EnvExampleTest.php` | ✅ | ✅ green |
| 02-02-03 | 02-02 | 2 | FND-01 | — | Pint and Larastan level 8 clean, no baseline | static | `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | ✅ | ✅ green |
| 02-03-01 | 02-03 | 3 | FND-02, FND-03 | T-02-11, T-02-12 | R1, R2, R4, R5, R6; UUID and timestamp edges; client_id not fillable | schema | `ddev exec vendor/bin/pest tests/Feature/Schema` | ✅ | ✅ green |
| 02-03-02 | 02-03 | 3 | FND-02, FND-03 | T-02-09, T-02-10 | Permission tables on UUID; R3, R7, R8; morph map enforced | schema | `ddev exec vendor/bin/pest tests/Feature/Schema` | ✅ | ✅ green |
| 02-04-01 | 02-04 | 4 | FND-02, FND-03 | T-02-13 | Sanctum tokens and notifications on UUID; owner resolved | schema + feature | `ddev exec vendor/bin/pest tests/Feature/Schema` | ✅ | ✅ green |
| 02-04-02 | 02-04 | 4 | FND-02, FND-03 | T-02-14 | Media and tags on UUID through registered subclasses | schema + feature | `ddev exec vendor/bin/pest tests/Feature/Schema` | ✅ | ✅ green |
| 02-04-03 | 02-04 | 4 | FND-02, FND-03 | T-02-15 | Activity log and webhook calls on UUID; R9 morph values mapped | schema + feature | `ddev exec vendor/bin/pest` | ✅ | ✅ green |
| 02-05-01 | 02-05 | 5 | FND-04 | T-02-16 | One rounding point, once per line; two-column cast round trip | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Money tests/Feature/Money` | ✅ | ✅ green |
| 02-05-02 | 02-05 | 5 | FND-04 | T-02-16..18 | Exact conversion, currency and overflow guards, Brick confined | unit + arch | `ddev exec vendor/bin/pest tests/Unit/Money tests/Feature/Money tests/Arch` | ✅ | ✅ green |
| 02-06-01 | 02-06 | 6 | FND-05 | T-02-19 | Owner confirms the counter row contract (one-way) | decision | — | n/a | ✅ green |
| 02-06-02 | 02-06 | 6 | FND-05 | T-02-19..21 | Allocation in the caller's transaction; rollback gap-free; key format | feature | `ddev exec vendor/bin/pest tests/Feature/Sequences tests/Feature/Schema` | ✅ | ✅ green |
| 02-06-03 | 02-06 | 6 | FND-05 | T-02-19, T-02-22 | Gap-free, duplicate-free under real parallel processes; mutation detected | concurrency | `ddev exec vendor/bin/pest tests/Concurrency` | ✅ | ✅ green |
| 02-07-01 | 02-07 | 7 | FND-12 | T-02-23 | Issued pilot row refused with KP001 by raw SQL | database | `ddev exec vendor/bin/pest tests/Feature/Database` | ✅ | ✅ green |
| 02-07-02 | 02-07 | 7 | FND-12 | T-02-23..26 | CHECK, partial unique, FK, delete, truncate verdicts; safe identifiers | database + unit | `ddev exec vendor/bin/pest tests/Feature/Database tests/Unit/Database` | ✅ | ✅ green |
| 02-08-01 | 02-08 | 8 | FND-11, FND-03 | T-02-28 | Czech login and validation; Prague display defaults | feature | `ddev exec vendor/bin/pest tests/Feature/Localisation` | ✅ | ✅ green |
| 02-08-02 | 02-08 | 8 | FND-11, FND-03 | T-02-27 | Formats, day boundary, both DST switches, NFC, enum labels | feature | `ddev exec vendor/bin/pest tests/Feature/Localisation` | ✅ | ✅ green |
| 02-09-01 | 02-09 | 9 | FND-17, FND-06 | T-02-29, T-02-30, T-02-33, T-02-35 | No default or argument password; one Admin; roles via package | feature | `ddev exec vendor/bin/pest tests/Feature/Auth/InstallCommandTest.php` | ✅ | ✅ green |
| 02-09-02 | 02-09 | 9 | FND-17 | T-02-31, T-02-32 | Admin TOTP enforced; Partner not forced; production boot guard | feature + unit | `ddev exec vendor/bin/pest tests/Feature/Auth tests/Unit/Support` | ✅ | ✅ green |
| 02-09-03 | 02-09 | 9 | FND-17 | T-02-34 | TOTP reset from the CLI, logged by id | feature | `ddev exec vendor/bin/pest tests/Feature/Auth/ResetTwoFactorCommandTest.php` | ✅ | ✅ green |
| 02-10-01 | 02-10 | 10 | FND-06, FND-18 | T-02-36..38 | Fail-closed scope matrix with canary strings | isolation | `ddev exec vendor/bin/pest tests/Isolation/FailClosedScopeTest.php` | ✅ | ✅ green |
| 02-10-02 | 02-10 | 10 | FND-06 | T-02-40 | Default-deny policy base; explicit Partner grants | isolation | `ddev exec vendor/bin/pest tests/Isolation/PolicyBaseTest.php` | ✅ | ✅ green |
| 02-10-03 | 02-10 | 10 | FND-06, FND-18 | T-02-39 | Every model declares isolation; escape hatches scanned | arch | `ddev exec vendor/bin/pest tests/Arch/ModelDeclarationTest.php tests/Arch/QueryEscapeHatchTest.php` | ✅ | ✅ green |
| 02-11-01 | 02-11 | 11 | FND-18, FND-06 | T-02-41, T-02-45 | Registry test fails on any undeclared panel class | arch + isolation | `ddev exec vendor/bin/pest tests/Arch/PanelRegistryTest.php tests/Isolation/PanelAccessTest.php` | ✅ | ✅ green |
| 02-11-02 | 02-11 | 11 | FND-18, FND-06 | T-02-44 | Enforcement traits keep policies; canary Resource never in production | isolation + unit | `ddev exec vendor/bin/pest tests/Isolation tests/Unit/Support` | ✅ | ✅ green |
| 02-11-03 | 02-11 | 11 | FND-18 | T-02-42, T-02-43 | Canary registry complete; route walk and global search leak nothing | isolation | `ddev exec vendor/bin/pest tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ | ✅ green |
| 02-12-01 | 02-12 | 12 | FND-13, FND-20, FND-01 | T-02-46, T-02-47 | Tests job on PostgreSQL 18 behind CI Passed; boot from `.env.example` | bash + lint + feature | `actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows && bash scripts/tests/run.sh && ddev exec vendor/bin/pest tests/Feature/Repo` | ✅ | ✅ green |
| 02-12-02 | 02-12 | 12 | FND-13 | T-02-48, T-02-SC | Licence allowlist with OR semantics rejects GPL-2.0-only | unit + script | `ddev exec vendor/bin/pest tests/Unit/LicenceCheckTest.php && ddev composer check-licenses` | ✅ | ✅ green |
| 02-13-01 | 02-13 | 13 | FND-14, FND-01, FND-20 | T-02-50..52 | README install path; SPDX consistent; SECURITY.md without e-mail | feature | `ddev exec vendor/bin/pest tests/Feature/Repo` | ✅ | ✅ green |
| 02-13-02 | 02-13 | 13 | FND-14 | T-02-50 | Development conventions documented and phrase-tested; phase gate | bash + full suite | `bash scripts/tests/run.sh && ddev composer ci && scripts/check-sensitive.sh --all` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] Skeleton merge with hygiene cleanup, `composer.json` licence, framework install via Composer with Pest `-W` (02-01)
- [x] `phpunit.xml` on PostgreSQL, `tests/Pest.php`, `tests/TestCase.php` with the `_test` guard, `phpstan.neon`, `pint.json`, composer scripts `test`/`lint`/`stan` (02-02)
- [x] `tests/Support/PgSchema.php` (02-03), `tests/Support/RawSql.php` (02-07), `tests/Support/{CanaryRecord,CanaryRecordPolicy,Canary}.php` (02-10), `tests/Support/CanaryRegistry.php` (02-11)
- [x] Every test file in the map above, created by the task that owns it (none exist yet)
- [x] `scripts/tests/test-gitignore.sh` verdicts (02-01); `scripts/check-licenses.php`, `scripts/tests/test-workflow.sh`, `scripts/boot-from-env-example.sh` (02-12)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `ddev start` on a clean clone is healthy, daemons RUNNING, bucket exists | FND-20 | Needs Docker and DDEV on a real machine | Clone fresh, follow only the README and `.env.example`, run `ddev start`, check `ddev describe` and the daemon status |
| Czech walk-through of login, 2FA set-up and profile | FND-11, FND-17 | Visual check of translated UI | Install the Admin, sign in, enable 2FA, open the profile page, confirm Czech text and formats (human-check in task 02-13-02) |
| CI green after push | FND-13, FND-20 | Needs a push to GitHub (owner action) | Open the Hygiene run of the pull request; scan, workflow-lint, tests, static-analysis and dependencies succeed and CI Passed is green (human-check in task 02-13-02) |

The clean-clone `ddev start` check is the human-check in task 02-13-01.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 30s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-10-07 (all 13 FND requirements covered by green automated tests; the three manual-only checks remain for UAT)

## Validation Audit 2026-10-07

| Metric | Count |
|---|---|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |
