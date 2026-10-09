---
phase: "03"
slug: operations-foundation
status: validated
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-08"
---

# Phase 03 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Source: `03-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13 (DDEV) |
| **Config file** | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency) |
| **Quick run command** | `ddev exec vendor/bin/pest --compact tests/Feature/Operations tests/Arch` |
| **Full suite command** | `ddev composer ci` (Pest, Pint `--test`, Larastan level 8, licence check) |
| **Shell self-tests** | `bash scripts/tests/run.sh` (when `.gitignore`, scripts or workflows change) |
| **Estimated runtime** | ~35 s (Pest alone, 395 tests at baseline) |

---

## Sampling Rate

- **After every task commit:** quick run command for the touched files, plus `ddev exec vendor/bin/pint --test`
- **After every plan wave:** `ddev composer ci`
- **Before `/gsd-verify-work`:** full suite green, `bash scripts/tests/run.sh` green, `pest --group=s3` green against RustFS
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

Refined by the plans (task IDs are `{plan}-T{n}`). Every test file below is created by the task named in the Task column (no separate Wave 0 plan: each task writes its own tests first).

| Task | Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|------|-------------|----------|-----------|-------------------|-------------|--------|
| 03-01-T1, T2 | FND-19 / D-14, D-16 | PDF measurements run from a spike directory outside the repo; record has criteria, both engines, licences, decision | script + structure grep | `php "$HOME/kokpit-spikes/pdf/bench.php" --engine=dompdf --rows=20 --runs=1 --out="$HOME/kokpit-spikes/pdf/out"` and section greps on `03-SPIKE-PDF.md` | created by task | manual-only (see below) |
| 03-02-T1..T3 | FND-19 / D-15, D-16 | custom board persistence, model events, forged move, concurrency; Flowforge evaluation; build-or-buy record | spike tests + structure grep | `(cd "$HOME/kokpit-spikes/kanban/app" && vendor/bin/pest tests/Spike)` and section greps on `03-SPIKE-KANBAN.md` | created by task | manual-only (see below) |
| 03-03-T1 | FND-07 / D-01 | settings table UUID v7 + timestamptz; supplier settings round trip as Admin and in the system context; Partner read fails closed; Phase 2 touch points | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsStorageTest.php tests/Feature/Schema tests/Arch/ModelDeclarationTest.php tests/Isolation` | ✅ (T1) | ✅ green |
| 03-03-T2 | FND-07 / D-01 | Partner group allowlist, cache off, system-context settings migrations, scoped freshness | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsStorageTest.php tests/Isolation` | ✅ (03-03-T1) | ✅ green |
| 03-04-T1 | FND-07 / D-02 | Admin saves supplier data on the Czech page and sees it after reload; Partner 403 | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsPageTest.php tests/Isolation tests/Arch/PanelRegistryTest.php` | ✅ (T1) | ✅ green |
| 03-04-T2 | FND-07 / D-02 | data-layer validation outside the form, mapped errors in one transaction, 403 before `mount()` | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsStorageTest.php tests/Feature/Operations/SettingsPageTest.php tests/Isolation/PanelAccessTest.php` | ✅ (T2 fixture) | ✅ green |
| 03-05-T1, T2 | FND-07 / D-02 | `Money::fromMajor` without rounding; default rate and currency; data-layer rules | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Money tests/Arch/MoneyBoundaryTest.php tests/Feature/Operations/SettingsGroupsTest.php tests/Feature/Operations/SettingsPageTest.php` | ✅ (T2) | ✅ green |
| 03-06-T1, T2 | FND-07 / D-02 | VAT mode, due days, online-payment toggle, payer refused, one-transaction save across tabs | feature | `ddev exec vendor/bin/pest tests/Feature/Operations tests/Feature/Localisation/EnumLabelsTest.php` | ✅ (extends 03-04, 03-05 files) | ✅ green |
| 03-07-T1, T2 | FND-07 / D-03, D-04 | per-format bank fields, own IBAN rule, one account per currency in form and data layer | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Settings/IbanTest.php tests/Feature/Operations/BankAccountSettingsTest.php` | ✅ (T1) | ✅ green |
| 03-08-T1, T2 | FND-07 / D-05 | peek never writes, scope-key invariant, token grammar, data-layer pattern rule, fixed task number | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Numbering tests/Feature/Operations/NumberingTest.php tests/Feature/Sequences tests/Concurrency` | ✅ (T1, T2) | ✅ green |
| 03-09-T1, T2 | FND-07 / D-05 | live previews never consume a number, Czech reasons, reset-period warning, locked task pattern | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/NumberingPageTest.php` | ✅ (T1) | ✅ green |
| 03-10-T1 | FND-08 / D-06 | non-allowlisted attributes never written | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/ActivityLogBehaviourTest.php` | ✅ (T1) | ✅ green |
| 03-10-T2 | FND-08 / D-08 | causer null + source label for console, job, webhook | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/ActivitySourceTest.php` | ✅ (T2) | ✅ green |
| 03-11-T1 | FND-08 / D-06 | every logged model has an allowlist of real, non-sensitive columns | arch + feature | `ddev exec vendor/bin/pest tests/Arch/ActivityAllowlistTest.php tests/Feature/Operations/ActivityAllowlistColumnsTest.php` | ✅ (T1) | ✅ green |
| 03-11-T2 | FND-08 / D-09 | pruning refused; production guard for the activity log | feature + unit | `ddev exec vendor/bin/pest tests/Feature/Operations/NoPruningTest.php tests/Unit/Support/ProductionConfigGuardTest.php` | ✅ (T2) | ✅ green |
| 03-12-T1..T3 | FND-08 / D-07 | overview and history relation manager Admin only, filters, boot-time denial | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Operations/ActivityViewsTest.php tests/Isolation tests/Arch` | ✅ (T1) | ✅ green |
| 03-13-T1 | FND-09 / D-10, D-11 | real Redis retry x3, failed_jobs, one mail + one bell notification | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/FailingJobFlowTest.php` | ✅ (T1) | ✅ green |
| 03-13-T2 | FND-09 / D-11 | throttle, safe content, channel isolation, listener never throws, queue independence | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/AdminAlertTest.php` | ✅ (T2) | ✅ green |
| 03-14-T1 | FND-09 / D-10 | base defaults and override in the Redis payload, after-commit, job-context settings read | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/QueueContractTest.php` | ✅ (T1) | ✅ green |
| 03-14-T2 | FND-09 / D-10 | base class and `#[Idempotent]` required, production queue guard | arch + unit | `ddev exec vendor/bin/pest tests/Arch/JobContractTest.php tests/Unit/Support/ProductionConfigGuardTest.php` | ✅ (T2) | ✅ green |
| 03-15-T1, T2 | FND-10 / D-12, D-13 | six slots registered, System page Admin only, missing-slot self-check, fail-safe results | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Operations/HealthRegistryTest.php tests/Feature/Operations/SystemPageTest.php tests/Isolation` | ✅ (T1) | ✅ green |
| 03-16-T1..T3 | FND-10 / D-12, D-13, FND-09 | real indicators against thresholds, heartbeats on the schedule, Redis queue age, alert link | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/HealthIndicatorsTest.php tests/Feature/Operations/AdminAlertTest.php tests/Arch/JobContractTest.php` | ✅ (T1) | ✅ green |
| 03-17-T1, T2 | FND-16 / D-17 | storage check passes, fails clearly, never leaks a signed URL; CI RustFS parity | feature (group `s3`) | `ddev exec vendor/bin/pest --group=s3 && ddev exec vendor/bin/pest tests/Feature/Repo/CiParityTest.php` | ✅ (T1) | ✅ green |
| 03-18-T1 | FND-15 / D-18 | `zerops.yml` structure, no literal secrets, one migrate; readiness verify; proxy trust | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/ZeropsConfigTest.php tests/Feature/Operations/DeployVerifyCommandTest.php tests/Feature/Operations/TrustedProxiesTest.php` | ✅ (T1) | ✅ green |
| 03-18-T2 | FND-15 | `deploy.yml` triggers, environment, pinned actions, no injection; linters | feature + lint | `ddev exec vendor/bin/pest tests/Feature/Repo/DeployWorkflowTest.php` and `actionlint && zizmor --offline .github/workflows` | ✅ (T2) | ✅ green |
| 03-17-T2, 03-18-T2 | FND-15 | existing hygiene workflow aggregator unchanged | existing | `bash scripts/tests/test-workflow.sh` | ✅ | ✅ green |
| 03-18-T3, 03-19-T1 | FND-15, FND-07..FND-16 | documented checklist items and conventions exist and name real classes | feature (docs) | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` | ✅ (extended) | ✅ green |
| 03-19-T2 | all | phase gate | full | `ddev composer ci`, `ddev exec vendor/bin/pest --group=s3`, `bash scripts/tests/run.sh`, `scripts/check-sensitive.sh --all` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Each item is created inside the plan task that first needs it (no separate Wave 0 plan):

- [x] `tests/Feature/Operations/` directory (03-03-T1)
- [x] probe page fixture `tests/Support/Filament/Fixtures/MountProbePage.php` (03-04-T2)
- [x] test-only activity probe table and model `tests/Support/Probes/ActivityProbe.php` (03-10-T1)
- [x] failing job fixture `tests/Support/Probes/FailingProbeJob.php` and Redis queue helper `tests/Support/RedisTestQueue.php` (03-13-T1)
- [x] Pest group `s3`, `tests/Support/S3TestDisk.php` (fails, never skips, when the endpoint is unreachable) (03-17-T1), CI RustFS service (03-17-T2)
- [x] updates to Phase 2 tests named in research Pattern 1, plus a `CanaryRegistry` line for the settings model (03-03-T1)
- [x] QR decode for the PDF spike: chillerlan/php-qrcode's built-in reader in the spike directory, no extra tool installed (03-01-T1)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| PDF and kanban spike outcomes (rows 03-01 and 03-02 of the map; measurements ran outside the repo, so no in-repo automated test exists) | FND-19 | the decision is a judgement over measurements taken outside the app | review `03-SPIKE-PDF.md` and `03-SPIKE-KANBAN.md` for criteria, measurements, outcome (human-checks in 03-01-T2, 03-02-T2/T3; touch drag on the spike board is part of 03-02-T2) |
| Zerops rehearsal (failing migration, phpredis, URL scheme behind balancer, eviction policy, env reference names, worker and scheduler start, storage check on Zerops) | FND-15 | needs a real Zerops project; non-blocking for the phase (resolved open question 4) | follow `03-ZEROPS-REHEARSAL.md` (human-check in 03-18-T3) |
| GitHub and Zerops settings (`production` environment, reviewers, tag rule, environment secret, native Git integration disabled, Valkey policy) | FND-15 | repository and platform settings are not in git | follow the CONTRIBUTING "Deploy (maintainer, manual)" checklist (human-check in 03-18-T3) |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** validated 2026-10-08

## Validation Audit 2026-10-08

| Metric | Count |
|---|---|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |
