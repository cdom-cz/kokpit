---
phase: "08"
slug: "exchange-rates-and-reports"
status: approved
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-14"
approved: "2026-10-15"
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
| **Measured full-suite runtime** | ~270 seconds (3867 tests, 3 skipped, at the end of Phase 8) |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest --filter=<new test class>`
- **After every plan wave:** Run `ddev exec vendor/bin/pest` plus stan and lint
- **Before `/gsd-verify-work`:** Full suite, `check-licenses` and `scripts/check-sensitive.sh` green
- **Max feedback latency:** 60 seconds for the quick run

---

## Per-Task Verification Map

Final plan and task ids of the 22 plans of the phase (the map was drafted for an earlier plan set and is rewritten here). The command of a row is the first `<automated>` command of the task; every task's own `<verify>` block in its PLAN.md is authoritative, and every plan's last task also runs the full suite, Pint and Larastan. Threat refs are the plan-level register of its PLAN.md (the supply-chain row T-08-SC is common to every plan and is left out).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 08-01-T1 | 01 | 1 | EX-01 | T-08-01, T-08-02 | a stored exchange rate reaches the Admin and never a Partner, under every repository convention | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Schema/ExchangeRatesTableTest.php tests/Arch/ModelDeclarationTest.php tests/Isolation/CanaryRegistryTest.php tests/Feature/Schema/SchemaConventionsTest.php` | ✅ | ✅ green |
| 08-01-T2 | 01 | 1 | EX-01 | T-08-01, T-08-02 | The database itself refuses duplicates and nonsense rates | feature | `ddev exec vendor/bin/pest tests/Feature/Schema` | ✅ | ✅ green |
| 08-02-T1 | 02 | 1 | RE-04 | T-08-03, T-08-04, T-08-05, T-08-06 | a Partner opens the dashboard and sees the own open tasks with those waiting for review first and highlighted | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard/PartnerDashboardTest.php tests/Isolation/PanelAccessTest.php tests/Feature/TimeTracking/ProjectTimeOverviewTest.php tests/Arch/PanelRegistryTest.php` | ✅ | ✅ green |
| 08-02-T2 | 02 | 1 | RE-04 | T-08-03, T-08-04, T-08-05, T-08-06 | The Partner's visible projects, the empty states and the no-time, no-money leak proof | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard/PartnerDashboardTest.php tests/Isolation/PanelAccessTest.php tests/Isolation/TimeLeakTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 08-03-T1 | 03 | 1 | RE-03 | — | owner decision on the dompdf licence (LGPL-2.1 read as LGPL-2.1-only), taken 2026-10-14 | manual (decision) | n/a (decision checkpoint) | — | ✅ decided |
| 08-03-T2 | 03 | 1 | RE-03 | T-08-07, T-08-09, T-08-10 | Dompdf passes the licence gate and renders a client's period into a real Czech work report PDF | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php tests/Unit/LicenceCheckTest.php` | ✅ | ✅ green |
| 08-04-T1 | 04 | 2 | EX-01, EX-02 | T-08-01, T-08-11, T-08-12, T-08-13, T-08-14, T-08-15 | kokpit:rates:download stores today's whole CNB list once, under its own validFor date | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` | ✅ | ✅ green |
| 08-04-T2 | 04 | 2 | EX-01, EX-02 | T-08-01, T-08-11, T-08-12, T-08-13, T-08-14, T-08-15 | Every refusal of a bad payload, and the mutation self-check that the Action keeps ignoring conflicts | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/DownloadCnbRatesTest.php tests/Feature/Schema/ExchangeRatesTableTest.php` | ✅ | ✅ green |
| 08-04-T3 | 04 | 2 | EX-01, EX-02 | T-08-01, T-08-11, T-08-12, T-08-13, T-08-14, T-08-15 | The rates timetable, limits and Czech copy as one reviewed contract | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates tests/Feature/Localisation` | ✅ | ✅ green |
| 08-05-T1 | 05 | 2 | EX-03, RE-02, RE-05 | T-08-16, T-08-17, T-08-18, T-08-19 | toCzk converts an amount at the stored CNB rate of its day, and a Saturday at Friday's rate | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateConverterTest.php` | ✅ | ✅ green |
| 08-05-T2 | 05 | 2 | EX-03, RE-02, RE-05 | T-08-16, T-08-17, T-08-18, T-08-19 | Money rounds a converted duration and an effective hourly rate once | unit | `ddev exec vendor/bin/pest tests/Unit/Money/MoneyTest.php tests/Arch/MoneyBoundaryTest.php` | ✅ | ✅ green |
| 08-05-T3 | 05 | 2 | EX-03, RE-02, RE-05 | T-08-16, T-08-17, T-08-18, T-08-19 | Per-unit currencies, the converted duration, bulk lookups and the fail-closed Partner context | feature/unit | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateConverterTest.php tests/Unit/Money/MoneyTest.php` | ✅ | ✅ green |
| 08-06-T1 | 06 | 2 | RE-03 | T-08-20, T-08-21, T-08-22, T-08-23 | the work report shows the billable and the non-billable section with their totals and the summary | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php` | ✅ | ✅ green |
| 08-06-T2 | 06 | 2 | RE-03 | T-08-20, T-08-21, T-08-22, T-08-23 | Many pages - running header, page numbers, generation date and the memory guard | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php` | ✅ | ✅ green |
| 08-06-T3 | 06 | 2 | RE-03 | T-08-20, T-08-21, T-08-22, T-08-23 | English, no money and no description, escaped text, and the entry-list source for Phase 10 | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php tests/Feature/Localisation` | ✅ | ✅ green |
| 08-07-T1 | 07 | 3 | RE-03 | T-08-24, T-08-25, T-08-26 | an export request is stored for its owner and nobody else can read it, under every repository convention | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportRecordTest.php tests/Arch/ModelDeclarationTest.php tests/Isolation/CanaryRegistryTest.php tests/Feature/Localisation/EnumLabelsTest.php tests/Feature/Schema/SchemaConventionsTest.php` | ✅ | ✅ green |
| 08-07-T2 | 07 | 3 | RE-03 | T-08-24, T-08-25, T-08-26 | The lifecycle, the expiry rule, server-generated names and the database refusals | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportRecordTest.php tests/Feature/Schema/ReportExportsTableTest.php` | ✅ | ✅ green |
| 08-07-T3 | 07 | 3 | RE-03 | T-08-24, T-08-25, T-08-26 | The exports retention, CSV settings and Czech copy as one reviewed contract | feature | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Feature/Localisation` | ✅ | ✅ green |
| 08-08-T1 | 08 | 3 | EX-01, EX-02 | T-08-27, T-08-28, T-08-29, T-08-30 | the 18:00 run that could not download today's list alerts the Admin by e-mail and bell without the queue | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/RateAlertTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Feature/Operations/AdminAlertTest.php` | ✅ | ✅ green |
| 08-08-T2 | 08 | 3 | EX-01, EX-02 | T-08-27, T-08-28, T-08-29, T-08-30 | The Czech working-day calendar | unit | `ddev exec vendor/bin/pest tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php` | ✅ | ✅ green |
| 08-08-T3 | 08 | 3 | EX-01, EX-02 | T-08-27, T-08-28, T-08-29, T-08-30 | Working-day gating of every scheduled run, quiet hourly failures and the "no list for today" alert | feature/unit | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates tests/Unit/ExchangeRates tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Feature/Operations/AdminAlertTest.php` | ✅ | ✅ green |
| 08-09-T1 | 09 | 4 | RE-01, RE-02 | T-08-31, T-08-32, T-08-33, T-08-34, T-08-35 | the Admin opens "Report času" for a client and a month and sees hours, the client-currency amount and CZK per project and task | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ✅ | ✅ green |
| 08-09-T2 | 09 | 4 | RE-01, RE-02 | T-08-31, T-08-32, T-08-33, T-08-34, T-08-35 | The effective billing chain in SQL equals the resolvers for every shape of entry | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/EffectiveBillingParityTest.php tests/Feature/TimeTracking/EntryRateResolverTest.php` | ✅ | ✅ green |
| 08-09-T3 | 09 | 4 | RE-01, RE-02 | T-08-31, T-08-32, T-08-33, T-08-34, T-08-35 | Validated filters, the four-sum summary identity, the empty state and the row-cap setting | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Isolation/RouteWalkTest.php tests/Arch/PanelRegistryTest.php` | ✅ | ✅ green |
| 08-10-T1 | 10 | 4 | EX-02 | T-08-36, T-08-37 | the System page shows the date of the newest CNB list as a real Ok slot | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/LastRateDateIndicatorTest.php tests/Feature/Operations/HealthRegistryTest.php tests/Feature/Operations/SystemPageTest.php` | ✅ | ✅ green |
| 08-10-T2 | 10 | 4 | EX-02 | T-08-36, T-08-37 | Warning and Error measured in Czech working days, never downloaded is Error | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Operations/LastRateDateIndicatorTest.php tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php tests/Feature/Operations` | ✅ | ✅ green |
| 08-11-T1 | 11 | 4 | RE-03 | T-08-38, T-08-39, T-08-40 | the Admin opens "Exporty" and downloads a ready export of their own through the app | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportResourceTest.php tests/Arch/PanelRegistryTest.php` | ✅ | ✅ green |
| 08-11-T2 | 11 | 4 | RE-03 | T-08-38, T-08-39, T-08-40 | Refused downloads, the pending poll, the failed row and the Partner and second-Admin proofs | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportResourceTest.php tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ | ✅ green |
| 08-12-T1 | 12 | 4 | RE-03 | T-08-41, T-08-42 | the daily job removes an expired export's file and record and keeps a fresh one | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportPruneTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch/JobContractTest.php` | ✅ | ✅ green |
| 08-12-T2 | 12 | 4 | RE-03 | T-08-41, T-08-42 | Records without files, two owners, a second run and chunked deletion | feature | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Feature/Operations/HorizonConfigTest.php` | ✅ | ✅ green |
| 08-13-T1 | 13 | 5 | EX-01, EX-02 | T-08-43, T-08-44, T-08-45 | the Admin opens "Kurzy ČNB", clicks "Stáhnout dnešní kurzy" and sees today's list | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateResourceTest.php tests/Arch/PanelRegistryTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 08-13-T2 | 13 | 5 | EX-01, EX-02 | T-08-43, T-08-44, T-08-45 | The header stats, currency names, the filters and both empty states | feature/unit/isolation | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateResourceTest.php tests/Unit/ExchangeRates/RateFormatTest.php tests/Isolation/PanelAccessTest.php` | ✅ | ✅ green |
| 08-13-T3 | 13 | 5 | EX-01, EX-02 | T-08-43, T-08-44, T-08-45 | Every download outcome, the alert link and the Partner refusal | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates` | ✅ | ✅ green |
| 08-14-T1 | 14 | 5 | RE-01, RE-02 | T-08-46, T-08-47, T-08-48 | the Admin switches "Rozpad" to "Po dnech" or "Podle uživatelů" and sees day or user rows below each task | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ✅ | ✅ green |
| 08-14-T2 | 14 | 5 | RE-01, RE-02 | T-08-46, T-08-47, T-08-48 | The accessible tree, the fixed-price and missing-rate markers, the footnotes and the row cap | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ✅ | ✅ green |
| 08-14-T3 | 14 | 5 | RE-01, RE-02 | T-08-46, T-08-47, T-08-48 | Prague days on DST changes, midnight-spanning and running entries, several currencies and a constant query count | feature | `ddev exec vendor/bin/pest tests/Feature/Reports` | ✅ | ✅ green |
| 08-15-T1 | 15 | 5 | RE-04 | T-08-49, T-08-50, T-08-51 | the Admin opens the dashboard and sees the hours worked today, this week and this month | feature/arch/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php tests/Arch/PanelRegistryTest.php` | ✅ | ✅ green |
| 08-15-T2 | 15 | 5 | RE-04 | T-08-49, T-08-50, T-08-51 | Unbilled billable time of the month per client, with money in the client currency and CZK | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Feature/Reports/TimeReportTest.php tests/Isolation/PanelAccessTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 08-16-T1 | 16 | 5 | RE-03 | T-08-52, T-08-53, T-08-54, T-08-55, T-08-56 | "Tabulka CSV" on the report page ends as a ready CSV on the private disk and a bell notice linking to the exports list | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php tests/Arch/JobContractTest.php tests/Feature/Operations/HorizonConfigTest.php` | ✅ | ✅ green |
| 08-16-T2 | 16 | 5 | RE-03 | T-08-52, T-08-53, T-08-54, T-08-55, T-08-56 | Formula-safe text cells, with a self-check that the canary catches the value-inferring factory | feature/unit | `ddev exec vendor/bin/pest tests/Unit/Reports/SpreadsheetCellsTest.php tests/Feature/Reports/ReportExportTest.php` | ✅ | ✅ green |
| 08-16-T3 | 16 | 5 | RE-03 | T-08-52, T-08-53, T-08-54, T-08-55, T-08-56 | The export follows the report exactly, a failing build is visible, and the bell stays clean | feature/unit/arch | `ddev exec vendor/bin/pest tests/Feature/Reports tests/Unit/Reports tests/Arch` | ✅ | ✅ green |
| 08-17-T1 | 17 | 5 | EX-01 | T-08-58, T-08-59 | a scheduled run after a missed working day fetches the year once and fills the gap | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/HealCnbRateGapsTest.php` | ✅ | ✅ green |
| 08-17-T2 | 17 | 5 | EX-01 | T-08-58, T-08-59 | One heal a day, no needless requests, a new-year gap, a poisoned year answer and lists after the run's day | feature/unit | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates tests/Unit/ExchangeRates tests/Feature/Operations/ScheduleOnOneServerTest.php` | ✅ | ✅ green |
| 08-18-T1 | 18 | 6 | EX-01 | T-08-60, T-08-61, T-08-62 | the Admin backfills a range from "Kurzy ČNB" and a past Saturday then converts at the previous Friday's rate | feature/arch | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/BackfillCnbRatesTest.php tests/Arch/JobContractTest.php tests/Feature/Operations/HorizonConfigTest.php` | ✅ | ✅ green |
| 08-18-T2 | 18 | 6 | EX-01 | T-08-60, T-08-61, T-08-62 | The modal's limits, labels and field errors | feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateResourceTest.php` | ✅ | ✅ green |
| 08-18-T3 | 18 | 6 | EX-01 | T-08-60, T-08-61, T-08-62 | Idempotent reruns, year boundaries, a poisoned year and the job's own range check | feature/arch | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates tests/Arch/JobContractTest.php tests/Feature/Operations/HorizonConfigTest.php` | ✅ | ✅ green |
| 08-19-T1 | 19 | 6 | RE-05 | T-08-63 | the report shows a fixed-price project's price, whole-project hours and effective hourly rate in the client currency and CZK | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/EffectiveRateTest.php` | ✅ | ✅ green |
| 08-19-T2 | 19 | 6 | RE-05 | T-08-63 | Zero hours, the capped conversion day, CZK projects, missing rates, task-level fixed prices and a constant query count | feature | `ddev exec vendor/bin/pest tests/Feature/Reports` | ✅ | ✅ green |
| 08-20-T1 | 20 | 6 | RE-04 | T-08-64, T-08-65, T-08-66 | the Admin sees the ten nearest open-task deadlines on the dashboard, overdue ones highlighted | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php` | ✅ | ✅ green |
| 08-20-T2 | 20 | 6 | RE-04 | T-08-64, T-08-65, T-08-66 | Tomorrow's Signal plan, collapsed to three, read-only and owner-scoped | feature/isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php tests/Isolation/RouteWalkTest.php tests/Feature/Signal` | ✅ | ✅ green |
| 08-21-T1 | 21 | 6 | RE-03 | T-08-67, T-08-68 | "Tabulka XLSX" delivers a workbook with the entries and a summary sheet | feature | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php` | ✅ | ✅ green |
| 08-21-T2 | 21 | 6 | RE-03 | T-08-67, T-08-68 | No formula in any XLSX cell, numeric cells and the missing-rate comment | feature/unit | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php tests/Unit/Reports/SpreadsheetCellsTest.php` | ✅ | ✅ green |
| 08-21-T3 | 21 | 6 | RE-03 | T-08-67, T-08-68 | The work report PDF item on the report page | feature | `ddev exec vendor/bin/pest tests/Feature/Reports` | ✅ | ✅ green |
| 08-22-T1 | 22 | 7 | EX-01, EX-02, EX-03, RE-01, RE-02, RE-03, RE-04, RE-05 | T-08-69, T-08-70, T-08-71 | a Partner who tries every Phase 8 surface sees nothing of time, money or the other client | isolation | `ddev exec vendor/bin/pest tests/Isolation` | ✅ | ✅ green |
| 08-22-T2 | 22 | 7 | EX-01, EX-02, EX-03, RE-01, RE-02, RE-03, RE-04, RE-05 | T-08-69, T-08-70, T-08-71 | Conventions, hand-over and operator notes, guarded by the documentation test | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` | ✅ | ✅ green |
| 08-22-T3 | 22 | 7 | EX-01, EX-02, EX-03, RE-01, RE-02, RE-03, RE-04, RE-05 | T-08-69, T-08-70, T-08-71 | Per-phase .gitignore review, the phase gate and the validation sign-off | gate | `ddev exec vendor/bin/pest` (full suite), `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `ddev composer check-licenses && bash scripts/tests/test-gitignore.sh && scripts/check-sensitive.sh --all` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Gate run of plan 08-22 (2026-10-15): full Pest suite 3867 passed and 3 skipped (the three skips are environment guards of `WorkReportPdfTest`: two cases need `pdftotext`, which DDEV does not install, and one cannot lower the memory limit below the usage of the running process; the same facts are covered by the unit tests of `WorkReportPageDecoration` and `WorkReportPdf::raisedMemoryLimit()`), Pint `--test` clean over 735 files, Larastan level 8 no errors, `composer check-licenses` 225 packages allowed, `scripts/tests/test-gitignore.sh` 122 assertions passed, `scripts/check-sensitive.sh --all` clean.

---

## Wave 0 Requirements

- [x] New test files for EX-01..03 and RE-01..05 with invented CNB fixtures (no real lists): `Tests\Support\CnbFixtures` (plan 08-04, year payloads in 08-17); each new test file was created by the first task that needed it (map above)
- [x] Deliberate edits of `HealthRegistryTest` and `SystemPageTest` (08-10), `PanelAccessTest` and `ProjectTimeOverviewTest` (08-02, 08-15, 08-20: the panel now discovers dashboard widgets), `JobContractTest` (08-12, 08-16, 08-18), `ModelDeclarationTest`, `MorphMap`, `CanaryRegistry`, `CanaryRegistryTest` and `AccessServiceProvider` policy registrations (08-01 `ExchangeRate`, 08-07 `ReportExport`; each landed in the plan that created the model, because the post-wave full suite fails on a model without them), `ScheduleOnOneServerTest` (08-08, 08-12); and, in this plan, the canary task of `CanaryRegistry` created in status `in_review`
- [x] `composer.json`: `dompdf/dompdf ^3.1` after the owner's licence decision (08-03, checkpoint), `openspout/openspout ^4.32` (08-16); `scripts/check-licenses.php` changed only per that decision, with a `LicenceCheckTest` case
- [x] Mutation-style self-checks: plain insert vs insert-or-ignore (plain-insert half 08-01, Action half 08-04), value-inferring cell factory vs `SpreadsheetCells` for CSV (08-16) and XLSX sheet XML (08-21)
- [x] Not needed: `.env.example` `CNB_API_BASE_URL` (the key has a default in `config/services.php`, like `ARES_BASE_URL`, and EnvExampleTest documents only keys read without a default)

---

## Manual-Only Verifications

The executors of the plans could not drive a browser or a spreadsheet application, so these checks stay open. Each one is an open `unrun-verify` entry of `.planning/WINDOWS.md` (its id in the last column) and is left to the owner and `/gsd-verify-work`; none of them is a numeric or authorisation claim, and every such claim is asserted by an automated test.

| Behavior | Requirement | Why Manual | Test Instructions | Ledger |
|----------|-------------|------------|-------------------|--------|
| PDF visual layout and diacritics (08-06) | RE-03 | Visual judgement | Open a generated work report of about three pages for a Czech and for an English client: Czech characters on every page, running header from page 2, footer "Strana N / M" with the generation date, billable section before the non-billable one. The executor of 08-06 inspected rendered samples as images and as extracted text; an owner sign-off remains | — |
| Report loading state (08-09) | RE-01 | Needs a browser and a slow period | Open `/admin/time-report` with a year of entries: the SPA progress bar shows while it loads and the filter strip stays usable | #2 |
| Download in-flight state (08-13) | EX-01 | Needs a browser and a slowed CNB answer | Click "Stáhnout dnešní kurzy" with the CNB answer slowed: the button shows Filament's in-flight state until the toast appears | #3 |
| 375 px layout and tree toggles (08-14) | RE-01 | Needs a browser | `/admin/time-report` at 375 px with long names: the tree scrolls inside its section, the page never scrolls sideways, names wrap, every toggle works with Tab, Enter and Space with a visible focus ring | #6 |
| Admin dashboard layout (08-15) | RE-04 | Needs a browser | `/admin`: one column below `lg`, two from `lg`, the unbilled table and the deadlines side by side, the "Pevná cena" and "Chybí kurz" badges and the running-timer text readable | #4 |
| Bell notice and CSV in a Czech spreadsheet (08-16) | RE-03 | Needs a browser and a spreadsheet application | Run a CSV export, open the bell ("Export je připraven" with format, period, expiry and "Otevřít exporty" in stock styling), open the file in a Czech spreadsheet: columns split at `;`, decimal commas | #5 |
| XLSX workbook in a spreadsheet application (08-21) | RE-03 | Needs a spreadsheet application | Open the XLSX: frozen header pane, auto filter, the CZK-missing comments and number formats; no formula in any cell | #7 |

### Gate record: contrast of the primary buttons (UI-SPEC Carried Flag F-1)

The UI-SPEC assumed white text on the Amber primary (about 3.2:1 on Amber 600). Filament 5 does not do that: `ButtonComponentColorMap` picks the background shade of a filled button by contrast and sets the text colour with it, so for `'primary' => Color::Amber` the classes are `fi-bg-color-400` with `fi-text-color-900` in light mode and `dark:fi-bg-color-600` with `dark:fi-text-color-950` in dark mode (read with `get_component_color_classes(ButtonComponent::class, 'primary')` in DDEV). The WCAG contrast of those pairs, computed from the palette values of `Filament\Support\Colors\Color::Amber` (oklch converted to sRGB), is:

| Button | Mode | Background / text | Contrast |
|--------|------|-------------------|----------|
| "Stáhnout dnešní kurzy" (header action, `color('primary')`) | light | Amber 400 `#ffb900` / Amber 900 `#7b3306` | 5.29:1 |
| "Stáhnout dnešní kurzy" | dark | Amber 600 `#e17100` / Amber 950 `#461901` | 4.70:1 |
| "Doplnit historii" (submit button of the backfill modal, `color('primary')`; the header trigger is gray) | light | same pair | 5.29:1 |
| "Doplnit historii" | dark | same pair | 4.70:1 |
| "Zkontrolovat úkol" (Partner dashboard, `x-filament::button color="primary"`) | light | same pair | 5.29:1 |
| "Zkontrolovat úkol" | dark | same pair | 4.70:1 |

All six measure at or above the 4.5:1 of WCAG AA for normal text; the white-on-Amber reading of F-1 (3.19:1 on Amber 600) describes a colouring Filament does not use, so the flag needs no change in this phase. The values are computed, not read from a rendered screen: the pixel check in a browser is part of ledger entry #3.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 60s for the quick run
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** automated gate approved 2026-10-15 by the executor of plan 08-22 (full gate green, see above); the manual-only verifications above remain open for the owner and the phase verifier.
