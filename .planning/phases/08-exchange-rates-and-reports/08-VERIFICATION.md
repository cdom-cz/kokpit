---
phase: 08-exchange-rates-and-reports
verified: 2026-10-29T14:30:00Z
status: passed
score: 5/5 must-haves verified
covered_files:
  - .planning/phases/08-exchange-rates-and-reports/08-01-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-01-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-02-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-02-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-03-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-03-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-04-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-04-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-05-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-05-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-06-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-06-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-07-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-07-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-08-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-08-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-09-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-09-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-10-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-10-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-11-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-11-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-12-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-12-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-13-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-13-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-14-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-14-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-15-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-15-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-16-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-16-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-17-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-17-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-18-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-18-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-19-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-19-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-20-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-20-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-21-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-21-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-22-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-22-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-23-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-23-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-24-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-24-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-25-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-25-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-26-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-26-SUMMARY.md
  - .planning/phases/08-exchange-rates-and-reports/08-27-PLAN.md
  - .planning/phases/08-exchange-rates-and-reports/08-27-SUMMARY.md
  - app/Domain/Clients/Actions/UpdateClient.php
  - app/Domain/Reports/Jobs/BuildReportExport.php
  - app/Domain/Reports/ReportBucket.php
  - app/Domain/Reports/TimeReport.php
  - app/Domain/Tasks/Models/TaskBilling.php
  - app/Filament/Pages/TimeReportPage.php
  - app/Filament/Widgets/UnbilledPerClient.php
  - lang/cs/kokpit.php
  - resources/views/filament/pages/time-report.blade.php
  - resources/views/filament/widgets/unbilled-per-client-cell.blade.php
  - routes/console.php
covered_digest: "v4:sha256:b34a192b2a6eab3db499e0d644abebbb82bde4d3057aa80b6313183560378e53"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 5/5
  gaps_closed: []
  gaps_remaining: []
  regressions: []
deferred: []
---

# Phase 8: Exchange Rates and Reports Verification Report

**Phase Goal:** Admin sees where time and money stand per client, project and period in client currency and CZK, can export and hand over work reports, and CNB rates feed every conversion
**Verified:** 2026-10-29T14:30:00Z
**Status:** passed
**Re-verification:** Yes. The previous report (2026-10-15, `human_needed`) is stale: `app/Domain/Reports/TimeReport.php`, `lang/cs/kokpit.php`, `resources/views/filament/pages/time-report.blade.php` and `routes/console.php` changed after it was written, and the human items have since been closed by UAT.

## What changed since the previous report

| File | Later commits | Effect on Phase 8 truths |
|------|---------------|--------------------------|
| `app/Domain/Reports/TimeReport.php`, `resources/views/filament/pages/time-report.blade.php` | `c489e06` (Phase 10, plan 40): the report summary names the reserved part of the unbilled time in the unbilled tile | Additive display change; the per-currency amounts, tree and footer are unchanged. Report tests still pass |
| `lang/cs/kokpit.php` | Phase 17 commits (task and project view strings) | Translation keys only; no Phase 8 key removed. Localisation tests pass |
| `routes/console.php` | `9fa6abf` (Phase 9), `cb74ac6` and `ff1a346` (Phase 11): new scheduled prune and sweep jobs | The two CNB schedule entries (`kokpit-cnb-rates`, `kokpit-cnb-rates-final`) and `kokpit-report-export-prune` are intact, Prague timezone, `onOneServer()` |

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence read in the code and tests |
|---|-------|--------|-------------------------------------|
| 1 | Scheduled daily CNB download, idempotent, weekend/holiday uses previous rate, per-unit amounts, manual run and backfill | VERIFIED | `routes/console.php` lines 73-86 schedule `kokpit:rates:download` at the configured hours Monday to Friday in `Europe/Prague`, plus a `--final` run. `DownloadCnbRates`, `HealCnbRateGaps`, `BackfillCnbRates` (job), `RateBook` (newest list not after the day) and `CzechPublicHolidays` exist and are substantive. `ExchangeRateConverter` divides by `unit_amount` (D-02). Tests `DownloadCnbRatesTest`, `BackfillCnbRatesTest`, `HealCnbRateGapsTest`, `RatesContractTest`, `ExchangeRateConverterTest` pass |
| 2 | Rates overview, queue-independent failure alert, last known rate kept, System page last-rate date, conversion helper returns CZK for a currency and date | VERIFIED | `ExchangeRateResource` (overview), `DownloadCnbRatesCommand` runs synchronously from the scheduler and `alertAdmin()` is called only on the `--final` run (lines 33-106), `LastRateDateIndicator` feeds the System page health, `ExchangeRateConverter::toCzk()/rateFor()/durationToCzk()` throws `ExchangeRateMissing` instead of returning zero, and keeps using the last known rate however old (D-04). `ExchangeRateResourceTest`, `RateAlertTest`, `ExchangeRateConverterTest` pass |
| 3 | Time report per client and project for a period with task/day/user breakdown, billable vs non-billable, billed vs unbilled, client currency and CZK, effective hourly rate for fixed-price projects | VERIFIED | `TimeReport`, `ReportBucket` (per-currency `$amounts`), `TimeReportReader`, `FixedPriceSummary` (price over all project hours, `effective_rate` and `effective_rate_czk`, Admin only), `TimeReportPage` and the Blade view are present and wired. `TimeReportTest`, `EffectiveRateTest`, `EffectiveBillingParityTest`, `BilledSnapshotAmountsTest`, `ReportFiltersTest` pass. The owner visual pass of the layout is recorded as passed in UAT test 1 |
| 4 | CSV or XLSX export built in the background and delivered by notification with spreadsheet-safe cells; multi-page PDF work report with correct Czech diacritics | VERIFIED | `BuildReportExport` job, `ReportExport` model and enums, `ReportExportReadyNotification` / `ReportExportFailedNotification`, `SpreadsheetCells` (text neutralisation, exact decimals), `WorkReportPdf` / `WorkReportSource` / `WorkReportPageDecoration`. `ReportExportTest`, `ReportExportRecordTest`, `ReportExportResourceTest`, `ReportExportPruneTest`, `WorkReportPdfTest`, `WorkReportDownloadTest` pass. UAT tests 5, 6 and 7 (CSV in a Czech spreadsheet, XLSX opened, PDF look with Czech and English client) all passed |
| 5 | Dashboard shows today's and this week's hours, unbilled time per client and upcoming task deadlines | VERIFIED | `Dashboard` registers `HoursStats`, `UnbilledPerClient`, `UpcomingDeadlines` (plus the later-phase tiles `UnpaidInvoices`, `UnresolvedPayments`). `UnbilledPerClient` keeps one amount line per currency via the cell view. `tests/Feature/Dashboard` passes. UAT test 4 (browser pass including a two-currency client) passed |

**Score:** 5/5 truths verified (0 present, behavior-unverified)

### Human Verification Items from the previous report

All seven items are closed by `08-UAT.md` (status `complete`, 7 passed, 0 issues, 0 pending, last updated 2026-10-29):

| Previous item | UAT test | Result |
|---------------|----------|--------|
| Owner visual pass of `/admin/time-report` at desktop width and 375px | 1 | pass |
| Ledger 2: SPA progress bar and usable filter strip with a year of entries | 2 | pass |
| Ledger 3: rates overview download button in flight, light and dark, contrast (UI-SPEC F-1) | 3 | pass |
| Ledger 4: Admin dashboard with a two-currency client | 4 | pass |
| Ledger 5: CSV export, notification bell, Czech spreadsheet | 5 | pass |
| Ledger 7: XLSX opened in a spreadsheet application | 6 | pass |
| PDF look sign-off (about three pages, Czech and English client) | 7 | pass |

No human verification item remains.

### Deferred Items

None. The previous report deferred "invoice and transaction CZK amounts and document rate snapshots" (EX-03 second half) to Phase 10. That caveat is now resolved in code, not just deferred: `ExchangeRateConverter` has production callers in `app/Domain/Invoicing/Issuing/DocumentIssuer.php` (rate frozen at issue date via `rateFor()`), `app/Domain/Finance/TransactionCzk.php`, `RecordPayment`, `UpdateDraft`, `UpdateIssuedDocument`, `IssueChecks`, `DraftPreview`, `TimeItemGrouping` and `FixedPriceSummary`.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/ExchangeRates/*` (actions, CNB client, jobs, model, `RateBook`, converter, holidays) | rates domain | VERIFIED | Substantive and wired from the command, scheduler, resource and invoicing code |
| `app/Domain/Reports/*` (`TimeReport`, `ReportBucket`, `FixedPriceSummary`, exports, jobs, WorkReport, queries) | reports domain | VERIFIED | Substantive and wired from `TimeReportPage`, dashboard widgets and notifications |
| `app/Filament/Pages/TimeReportPage.php`, `resources/views/filament/pages/time-report.blade.php` | readable tree | VERIFIED | Wired, tested, owner-approved in UAT |
| `app/Filament/Widgets/UnbilledPerClient.php`, `resources/views/filament/widgets/unbilled-per-client-cell.blade.php` | per-currency dashboard rows | VERIFIED | Registered in `Dashboard` |
| `app/Domain/Tasks/Models/TaskBilling.php`, `app/Domain/Clients/Actions/UpdateClient.php` | currency lock over task money | VERIFIED | Unchanged since the previous report; covered by `ClientRulesTest` |
| `app/Filament/Resources/ExchangeRateResource`, `ReportExportResource` | overview and export list | VERIFIED | Present, covered by resource tests |
| `routes/console.php` schedule entries | daily rates, final alert run, export prune | VERIFIED | Present, `onOneServer()` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `routes/console.php` | `kokpit:rates:download` | `Schedule::command` (hourly attempts and `--final`) | WIRED | |
| `DownloadCnbRatesCommand` | Admin alert | `alertAdmin()` on the `--final` run, synchronous | WIRED | Independent of the queue |
| `LastRateDateIndicator` | System page | health slot | WIRED | |
| `ExchangeRateConverter` | invoice and transaction CZK code | `DocumentIssuer`, `TransactionCzk`, others | WIRED | Previously ORPHANED (intentional); Phase 10 added callers |
| `TimeReportPage` | `TimeReport` / `FixedPriceSummary` / `BuildReportExport` | page data and export action | WIRED | |
| `TimeReport` summary | dashboard widget and export summary | per-currency key shape | WIRED | Parity pinned by dashboard tests |
| `UpdateClient` currency lock | `task_billing` money | `TaskBilling::clientHoldsMoney()` | WIRED | |
| `routes/console.php` | `PruneExpiredReportExports` | `Schedule::job` | WIRED | |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Time report tree | buckets / amounts | `TimeReportReader` SQL over `time_entries` and billing levels, `RateBook` | Yes | FLOWING |
| Dashboard unbilled widget | rows / amounts | scoped queries and `EntryAmounts` | Yes | FLOWING |
| Rates overview | rows | `exchange_rates` table | Yes | FLOWING |
| System page last rate date | indicator value | newest `exchange_rates` day | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 8 test areas (rates, reports, dashboard) | `ddev exec vendor/bin/pest --compact tests/Feature/ExchangeRates tests/Feature/Reports tests/Feature/Dashboard` | 674 passed, 2 skipped (2945 assertions), exit 0 | PASS |
| Whole suite (run once by the caller, reported) | `ddev exec vendor/bin/pest --compact` | 7807 passed, 3 skipped | PASS (reported, not re-run) |
| No debt markers in Phase 8 code | grep `TBD\|FIXME\|XXX` over `app/Domain/Reports`, `app/Domain/ExchangeRates`, `TimeReportPage`, `UnbilledPerClient`, `DownloadCnbRatesCommand` | no match | PASS |

The 2 skipped tests are the `pdftotext` layout tests (the tool is not installed in DDEV); the PDF look itself was signed off by the owner in UAT test 7.

### Probe Execution

SKIPPED: the phase declares no probe scripts.

### Requirements Coverage

All eight IDs from the phase are declared in the Phase 8 plans, are mapped to Phase 8 in REQUIREMENTS.md and appear in the ROADMAP requirement list. No orphaned requirement.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| EX-01 | 08-01, 04, 08, 13, 17, 18 | Daily idempotent CNB download, manual run, backfill, previous rate, per-unit | SATISFIED | Truth 1 |
| EX-02 | 08-04, 08, 10, 13 | Rates overview, failure alert, last known rate | SATISFIED | Truth 2 |
| EX-03 | 08-05 | Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots | SATISFIED | Helper tested; production callers `DocumentIssuer` and `TransactionCzk` now exist (Phase 10) |
| RE-01 | 08-09, 14, 23, 26, 27 | Time report per client and project with task/day/user breakdown | SATISFIED | Truth 3; UAT 1 |
| RE-02 | 08-05, 09, 14, 23, 25, 26, 27 | Billable/non-billable and billed/unbilled with client currency and CZK amounts | SATISFIED | Truth 3 |
| RE-03 | 08-03, 06, 07, 11, 12, 16, 21, 23 | CSV/XLSX queued export, PDF work report | SATISFIED | Truth 4; UAT 5, 6, 7 |
| RE-04 | 08-02, 15, 20, 24 | Dashboard hours, unbilled per client, deadlines (overdue invoices and unresolved payments tiles belong to Phases 10 and 11) | SATISFIED | Truth 5; UAT 4 |
| RE-05 | 08-05, 19 | Effective hourly rate for fixed-price projects | SATISFIED | `FixedPriceSummary`, `EffectiveRateTest` |

**Bookkeeping note (not a code gap):** `.planning/REQUIREMENTS.md` still shows EX-01, EX-02, EX-03 and RE-05 with unchecked boxes and trace-table status "Gaps Found" (lines 101-103, 111, 322-324, 329), left over from the premature-completion revert in commit 581f5f9. Verification finds all four satisfied, so they should be checked and marked Complete together with RE-01 to RE-04 (already Complete). The orchestrator owns that edit.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Reports/Jobs/PruneExpiredReportExports.php` | 35-37 | WR-01: an export file whose record ended failed or pending is never deleted from the private disk (disposition `open`) | WARNING | Report data can linger on the private disk; no must-have truth depends on it |
| `app/Console/Commands/DownloadCnbRatesCommand.php` | 56-76 | WR-02: a failed gap heal after a successful download can raise a false "rates missing" alert (disposition `open`) | WARNING | Misleading Admin alert; the rate data itself is correct |
| `app/Filament/Pages/TimeReportPage.php` | export request | WR-03: `report_exports.summary` is varchar(255); a very long client plus project name can fail the request with a DB error (disposition `open`) | WARNING | Edge case |
| IN-01 to IN-09 | various | See `08-REVIEW.md`, all `open` in `08-REVIEW-DISPOSITION.md` | INFO | Follow-ups |

No `TBD`, `FIXME` or `XXX` markers. The three warnings are carried follow-ups from the code review and do not block any roadmap success criterion (not modified since 2026-10-14 per git history).

### Human Verification Required

None. UAT (`08-UAT.md`) records 7 passed, 0 issues, including the owner readability sign-off for the time report that the original gap depended on.

### Gaps Summary

No gaps. All five roadmap success criteria hold in the current code, all eight requirement IDs are satisfied, the previously deferred EX-03 half is delivered by Phase 10, all seven human items are resolved by UAT, and the Phase 8 test areas pass (674 passed, 2 skipped) alongside the reported full suite (7807 passed, 3 skipped). Open items are the three review warnings WR-01 to WR-03 (follow-ups) and the stale requirement checkboxes in REQUIREMENTS.md.

---

_Verified: 2026-10-29T14:30:00Z_
_Verifier: Claude (gsd-verifier)_
