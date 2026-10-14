---
phase: "08"
slug: "exchange-rates-and-reports"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-14"
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Full requirement-to-test map: see `08-RESEARCH.md` section "Validation Architecture".

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5 on PHPUnit 13 (suites Unit, Feature, Arch, Isolation, Concurrency) |
| **Config file** | `phpunit.xml` (PostgreSQL `kokpit_test`, `QUEUE_CONNECTION=sync`) |
| **Quick run command** | `ddev exec vendor/bin/pest --filter=<new test class>` |
| **Full suite command** | `ddev exec vendor/bin/pest` (plus `ddev composer stan`, `lint`, `check-licenses`) |
| **Estimated runtime** | ~300 seconds |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest --filter=<new test class>`
- **After every plan wave:** Run `ddev exec vendor/bin/pest` plus stan and lint
- **Before `/gsd-verify-work`:** Full suite, `check-licenses` and `scripts/check-sensitive.sh` green
- **Max feedback latency:** 60 seconds for the quick run

---

## Per-Task Verification Map

Filled in by the planner from the requirement map in `08-RESEARCH.md` (EX-01..03, RE-01..05). Every task's own `<verify>` block in its PLAN.md is authoritative; the commands below are the targeted runs (`ddev exec vendor/bin/pest <files>`), and every plan's last task also runs the full suite, Pint and Larastan.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 08-01-T1 | 01 | 1 | EX-01 | T-08-01, T-08-02, T-08-03 | stored under validFor, exact decimal rates, no raw text on console | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` | ❌ W0 (created by task) | ⬜ pending |
| 08-01-T2 | 01 | 1 | EX-01 | T-08-01, T-08-04, T-08-05 | refusal matrix, DB CHECK/unique proofs, mutation self-check, Partner sees no rate | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Schema/ExchangeRatesTableTest.php tests/Arch/ModelDeclarationTest.php tests/Isolation/CanaryRegistryTest.php` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-02-T1 | 02 | 1 | RE-04 | T-08-07, T-08-09 | Partner sees own open tasks, in_review first; Admin mount 403 | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard/PartnerDashboardTest.php tests/Isolation/PanelAccessTest.php` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-02-T2 | 02 | 1 | RE-04 | T-08-08, T-08-10 | pinned Partner fields, no time or money, canary | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard/PartnerDashboardTest.php tests/Isolation/TimeLeakTest.php` | ✅ (after T1) | ⬜ pending |
| 08-03-T1 | 03 | 2 | EX-02 | T-08-12, T-08-13 | final-run alert by mail and bell without the queue, no body text | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/RateAlertTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-03-T2 | 03 | 2 | EX-01 | — | Czech working days, Easter, fixed holidays | unit | `ddev exec vendor/bin/pest tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php` | ❌ W0 | ⬜ pending |
| 08-03-T3 | 03 | 2 | EX-01, EX-02 | T-08-11, T-08-14 | no HTTP on weekends/holidays, one alert a day at most | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates tests/Unit/ExchangeRates` | ✅ (after T1) | ⬜ pending |
| 08-04-T1 | 04 | 2 | EX-03 | T-08-17 | previous valid rate by lookup, ExchangeRateMissing not zero | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/CnbConverterTest.php` | ❌ W0 | ⬜ pending |
| 08-04-T2 | 04 | 2 | RE-02, RE-05 | T-08-15 | single rounding point, Brick confined | unit/arch | `ddev exec vendor/bin/pest tests/Unit/Money/MoneyTest.php tests/Arch/MoneyBoundaryTest.php` | ✅ extend | ⬜ pending |
| 08-04-T3 | 04 | 2 | EX-03 | T-08-16, T-08-18 | per-unit HUF/JPY/IDR, constant queries, Partner finds no rate | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/CnbConverterTest.php` | ✅ (after T1) | ⬜ pending |
| 08-05-T1 | 05 | 2 | RE-03 | T-08-20 | download only through the checked action from the private disk | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportResourceTest.php` | ❌ W0 | ⬜ pending |
| 08-05-T2 | 05 | 2 | RE-03 | T-08-19, T-08-21, T-08-22 | owner scope, Partner 403, path CHECK, expired/foreign refused | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportResourceTest.php tests/Feature/Schema/ReportExportsTableTest.php tests/Isolation/CanaryRegistryTest.php` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-06-T1 | 06 | 3 | EX-02 | T-08-26 | real last-rate-date slot, Czech detail only | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/LastRateDateIndicatorTest.php tests/Feature/Operations/HealthRegistryTest.php tests/Feature/Operations/SystemPageTest.php` | ❌ W0 / ✅ edit | ⬜ pending |
| 08-06-T2 | 06 | 3 | EX-02 | T-08-24 | Warning/Error in working days, never downloaded is Error | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Operations/LastRateDateIndicatorTest.php tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php` | ✅ (after T1) | ⬜ pending |
| 08-06-T3 | 06 | 3 | EX-01 | T-08-24, T-08-25, T-08-27 | gap self-heal once a day, validated year payload | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` | ✅ extend | ⬜ pending |
| 08-07-T1 | 07 | 3 | RE-01, RE-02 | T-08-28, T-08-32 | Admin-only page, per-entry amounts, CZK at entry day | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ❌ W0 | ⬜ pending |
| 08-07-T2 | 07 | 3 | RE-02 | T-08-30, T-08-31 | SQL chain equals resolvers, Partner refused | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/EffectiveBillingParityTest.php` | ❌ W0 | ⬜ pending |
| 08-07-T3 | 07 | 3 | RE-01, RE-02 | T-08-29 | validated URL filters, four-sum identity, task/day/user groups | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Unit/Reports` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-08-T1 | 08 | 4 | EX-01, EX-02 | T-08-34 | Admin-only overview, manual download | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateResourceTest.php` | ❌ W0 | ⬜ pending |
| 08-08-T2 | 08 | 4 | EX-01 | T-08-35, T-08-36 | idempotent backfill job, timeout chain | feature/arch | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/BackfillCnbRatesTest.php tests/Arch/JobContractTest.php tests/Feature/Operations/HorizonConfigTest.php` | ❌ W0 / ✅ edit | ⬜ pending |
| 08-08-T3 | 08 | 4 | EX-01, EX-02 | T-08-33 | backfill limits, failure notice without alert | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates` | ✅ (after T1) | ⬜ pending |
| 08-09-T1 | 09 | 4 | RE-05 | T-08-37 | effective rate over whole-project hours, dash at zero | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/EffectiveRateTest.php` | ❌ W0 | ⬜ pending |
| 08-09-T2 | 09 | 4 | RE-01 | — | Prague days on DST, running and midnight entries | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ✅ extend | ⬜ pending |
| 08-09-T3 | 09 | 4 | RE-02 | T-08-38, T-08-39 | missing rate marked, currencies apart, constant queries | feature | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Unit/Reports` | ✅ extend | ⬜ pending |
| 08-10-T1 | 10 | 5 | RE-04 | T-08-40 | Admin hours stats | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php` | ❌ W0 / ✅ edit | ⬜ pending |
| 08-10-T2 | 10 | 5 | RE-04 | T-08-43 | unbilled per client equals the report | feature | `ddev exec vendor/bin/pest tests/Feature/Dashboard/AdminDashboardTest.php` | ✅ (after T1) | ⬜ pending |
| 08-10-T3 | 10 | 5 | RE-04 | T-08-40, T-08-41, T-08-42 | deadlines, tomorrow plan owner-scoped, Partner 403 on all widgets | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php` | ✅ (after T1) | ⬜ pending |
| 08-11-T1 | 11 | 5 | RE-03 | T-08-49 | owner licence decision (checkpoint) | manual | n/a (decision checkpoint) | — | ⬜ pending |
| 08-11-T2 | 11 | 5 | RE-03 | T-08-45, T-08-48, T-08-SC | Dompdf hardened options, Admin-only, licence gate green | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php tests/Unit/LicenceCheckTest.php` | ❌ W0 / ✅ extend | ⬜ pending |
| 08-11-T3 | 11 | 5 | RE-03 | T-08-44, T-08-46, T-08-47 | cs/en, multi-page, no money, escaped text | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php` | ✅ (after T2) | ⬜ pending |
| 08-12-T1 | 12 | 6 | RE-03 | T-08-51, T-08-SC | queued CSV, private disk, bell link | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php tests/Arch/JobContractTest.php` | ❌ W0 / ✅ edit | ⬜ pending |
| 08-12-T2 | 12 | 6 | RE-03 | T-08-50 | formula canaries inert, self-check | unit/feature | `ddev exec vendor/bin/pest tests/Unit/Reports/SpreadsheetCellsTest.php tests/Feature/Reports/ReportExportTest.php` | ❌ W0 | ⬜ pending |
| 08-12-T3 | 12 | 6 | RE-03 | T-08-52, T-08-53, T-08-54 | export equals report, failure visible, escaped bell | feature | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Unit/Reports` | ✅ (after T1) | ⬜ pending |
| 08-13-T1 | 13 | 7 | RE-03 | — | XLSX with summary sheet equal to the report | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php` | ✅ extend | ⬜ pending |
| 08-13-T2 | 13 | 7 | RE-03 | T-08-55 | no `<f>` in any sheet, self-check | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php` | ✅ extend | ⬜ pending |
| 08-13-T3 | 13 | 7 | RE-03 | T-08-56, T-08-57 | 7-day prune of file and record, one server | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportPruneTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch/JobContractTest.php` | ❌ W0 / ✅ edit | ⬜ pending |
| 08-14-T1 | 14 | 8 | all | T-08-58 | Partner leak sweep over every Phase 8 surface | isolation | `ddev exec vendor/bin/pest tests/Isolation` | ✅ extend | ⬜ pending |
| 08-14-T2 | 14 | 8 | all | T-08-60 | docs name only existing classes and tests | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` | ✅ extend | ⬜ pending |
| 08-14-T3 | 14 | 8 | all | T-08-59 | full gate, licences, sensitive scan | gate | `ddev exec vendor/bin/pest` and `ddev composer check-licenses && bash scripts/tests/test-gitignore.sh && scripts/check-sensitive.sh --all` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] New test files for EX-01..03 and RE-01..05 with invented CNB fixtures (no real lists): `Tests\Support\CnbFixtures` (plan 08-01, year payloads in 08-06); each new test file is created by the first task that needs it (map above)
- [ ] Deliberate edits of `HealthRegistryTest` and `SystemPageTest` (08-06), `PanelAccessTest` and `ProjectTimeOverviewTest` (08-02, 08-10: the panel now discovers dashboard widgets), `JobContractTest` (08-08, 08-12, 08-13), `ModelDeclarationTest`, `MorphMap`, `CanaryRegistry`, `CanaryRegistryTest` and `AccessServiceProvider` policy registrations (08-01 `ExchangeRate`, 08-05 `ReportExport`), `ScheduleOnOneServerTest` (08-03, 08-13)
- [ ] `composer.json`: `dompdf/dompdf ^3.1` after the owner's licence decision (08-11, checkpoint), `openspout/openspout ^4.32` (08-12); `scripts/check-licenses.php` changed only per that decision, with a `LicenceCheckTest` case
- [ ] Mutation-style self-checks: plain insert vs insert-or-ignore (08-01), value-inferring cell factory vs `SpreadsheetCells` for CSV (08-12) and XLSX sheet XML (08-13)
- [ ] Not needed: `.env.example` `CNB_API_BASE_URL` (the key has a default in `config/services.php`, like `ARES_BASE_URL`, and EnvExampleTest documents only keys read without a default)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| PDF visual layout and diacritics | RE-03 | Visual judgement | Open generated work report, check Czech characters and page footer |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
