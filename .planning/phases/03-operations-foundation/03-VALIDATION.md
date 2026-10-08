---
phase: "03"
slug: operations-foundation
status: draft
nyquist_compliant: false
wave_0_complete: false
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

Seeded from the research requirement-to-test map; plans refine task IDs.

| Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|----------|-----------|-------------------|-------------|--------|
| FND-07 / D-01 | settings table passes UUID v7 + timestamptz rules | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsStorageTest.php tests/Feature/Schema` | ❌ W0 | ⬜ pending |
| FND-07 / D-02 | Admin saves settings in one transaction; Partner denied before `mount()`; Czech form | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/SettingsPageTest.php` | ❌ W0 | ⬜ pending |
| FND-07 / D-03, D-04 | per-format bank fields, IBAN validation, one account per currency | feature + unit | `ddev exec vendor/bin/pest tests/Feature/Operations/BankAccountSettingsTest.php` | ❌ W0 | ⬜ pending |
| FND-07 / D-05 | numbering tokens, preview does not consume a number | unit + feature | `ddev exec vendor/bin/pest tests/Unit/Numbering tests/Feature/Operations/NumberingTest.php` | ❌ W0 | ⬜ pending |
| FND-08 / D-06 | every logged model has an allowlist of real columns | arch | `ddev exec vendor/bin/pest tests/Arch/ActivityAllowlistTest.php` | ❌ W0 | ⬜ pending |
| FND-08 / D-06 | non-allowlisted attributes never written | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/ActivityLogBehaviourTest.php` | ❌ W0 | ⬜ pending |
| FND-08 / D-07 | history views Admin only | isolation | `ddev exec vendor/bin/pest tests/Isolation tests/Feature/Operations/ActivityViewsTest.php` | partial | ⬜ pending |
| FND-08 / D-08 | causer null + source label | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/ActivitySourceTest.php` | ❌ W0 | ⬜ pending |
| FND-08 / D-09 | no pruning scheduled | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/NoPruningTest.php` | ❌ W0 | ⬜ pending |
| FND-09 / D-10 | base job defaults, override, idempotence marker | unit + arch | `ddev exec vendor/bin/pest tests/Arch/JobContractTest.php` | ❌ W0 | ⬜ pending |
| FND-09 / D-10, D-11 | retry, failed_jobs, queue-independent throttled alert | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/FailingJobFlowTest.php` | ❌ W0 | ⬜ pending |
| FND-10 / D-12 | thresholds per status | unit | `ddev exec vendor/bin/pest tests/Unit/Health` | ❌ W0 | ⬜ pending |
| FND-10 / D-13 | six slots registered | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/HealthRegistryTest.php` | ❌ W0 | ⬜ pending |
| FND-10 | System page Admin only | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/SystemPageTest.php` | ❌ W0 | ⬜ pending |
| FND-15 / D-18 | `zerops.yml` structure, no literal secrets, one migrate | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/ZeropsConfigTest.php` | ❌ W0 | ⬜ pending |
| FND-15 | `deploy.yml` triggers, environment, pinned actions | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/DeployWorkflowTest.php` | ❌ W0 | ⬜ pending |
| FND-15 | existing hygiene workflow unchanged | existing | `bash scripts/tests/test-workflow.sh` | ✅ | ⬜ pending |
| FND-16 / D-17 | storage check passes / fails clearly | feature (group `s3`) | `ddev exec vendor/bin/pest --group=s3` | ❌ W0 | ⬜ pending |
| FND-19 | PDF and kanban decision records exist | manual-only | human-verify checkpoint | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Operations/` directory
- [ ] test-only activity probe table and model (pattern of `Canary::createTable()` / `PackageProbe`)
- [ ] failing job fixture and a Redis queue test helper (unique queue name, flush after)
- [ ] Pest group `s3`, unreachable-endpoint helper, CI RustFS service
- [ ] updates to Phase 2 tests named in research Pattern 1, plus a `CanaryRegistry` line for the settings model
- [ ] QR decoder for the PDF spike (spike directory only)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| PDF and kanban spike outcomes | FND-19 | the decision is a judgement over measurements taken outside the app | review the two decision records for criteria, measurements, outcome |
| Zerops rehearsal (failing migration, phpredis, URL scheme behind balancer, eviction policy) | FND-15 | needs a real Zerops project | follow the rehearsal checklist produced by the deploy plan |
| GitHub settings (`production` environment, reviewers, Git integration disabled) | FND-15 | repository settings are not in git | follow the CONTRIBUTING checklist |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
