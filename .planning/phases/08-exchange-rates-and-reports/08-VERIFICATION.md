---
phase: 08-exchange-rates-and-reports
verified: 2026-10-15T14:00:00Z
status: gaps_found
score: 4/5 must-haves verified
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
  - app/Domain/Clients/Actions/UpdateClient.php
  - app/Domain/ExchangeRates/Actions/DownloadCnbRates.php
  - app/Domain/ExchangeRates/ExchangeRateConverter.php
  - app/Domain/Projects/Models/ProjectBilling.php
  - app/Domain/Reports/Jobs/BuildReportExport.php
  - app/Domain/Reports/Queries/TimeReportReader.php
  - app/Domain/Reports/ReportBucket.php
  - app/Domain/Reports/TimeReport.php
  - app/Filament/Pages/TimeReportPage.php
  - app/Filament/Widgets/UnbilledPerClient.php
  - resources/views/filament/pages/time-report.blade.php
  - routes/console.php
covered_digest: "v3:sha256:49408e5c2a40a9023fef2cb83f48ee78c10029e269fdafb64658d2616d35bdb7"
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "Admin sees a time report per client and project for a period that stays usable when a client carries money in two currencies (SC3 amounts in client currency and CZK; also SC4 exports and SC5 dashboard unbilled widget)"
    status: failed
    reason: "CR-01 of 08-REVIEW.md, confirmed by reading the code. ReportBucket::add() and UnbilledPerClient::compute() sum per-entry amounts of one client with Money::plus(), which throws InvalidArgumentException on mixed currencies. The invariant 'a client has one currency' is only locked by ProjectBilling::clientHoldsMoney() (project_billing rows). UpdateClient does not look at task_billing, whose hourly_rate_currency also holds money, and the report SQL resolves the currency from the same level as the rate (RATE_CURRENCY_SQL = COALESCE(tb, ptb, pb, client)). Reachable path: client EUR with a task-level EUR rate and no project money, Admin changes the client to CZK, entries of that task price in EUR and the rest in CZK inside one client bucket. Result: the time report page, the Admin dashboard (UnbilledPerClient, not lazy) and the XLSX/summary export all fail. No test covers it (grep of tests finds no mixed-currency-per-client case). Disposition in 08-REVIEW-DISPOSITION.md is still 'open'. Judged a gap against the phase goal, not a follow-up, because the failure lands on the Admin's landing page and on the headline report the phase exists to deliver, and the data does not heal without the Admin guessing that a task rate must be edited."
    artifacts:
      - path: "app/Domain/Reports/ReportBucket.php"
        issue: "add() sums amount across currencies (line ~83) with Money::plus() that throws on mismatch"
      - path: "app/Domain/Reports/TimeReport.php"
        issue: "client/project/task buckets all call add() with mixed-currency entries"
      - path: "app/Filament/Widgets/UnbilledPerClient.php"
        issue: "compute() sums client amount with Money::plus() (line ~200) while rendering the Admin dashboard"
      - path: "app/Domain/Clients/Actions/UpdateClient.php"
        issue: "currency lock consults ProjectBilling::clientHoldsMoney() only, ignores task_billing money"
      - path: "app/Domain/Projects/Models/ProjectBilling.php"
        issue: "clientHoldsMoney() does not include task_billing hourly rate or fixed price"
    missing:
      - "Extend the client currency lock to task_billing money (archived tasks included)"
      - "Make report buckets and the unbilled widget currency-safe (per-currency amounts or a visible mixed-currency marker, never an exception)"
      - "Test: client with a CZK client rate and one task with an EUR task rate renders the report, the dashboard widget and the XLSX export"
  - truth: "Admin can actually read the time report per client, project and task (SC3 breakdown by task, day and user, owner-recorded gap)"
    status: failed
    reason: "Owner-recorded gap (todo 2026-10-14-redesign-unreadable-time-report-layout, gap: true, severity major). The owner calls the page practically unusable. Code confirms the cause: resources/views/filament/pages/time-report.blade.php sets white-space: nowrap on every numeric cell and the Nazev column has no width floor, so task titles wrap per word and rows become very tall; client, project and task levels differ only by indent and weight. 08-UI-REVIEW.md scores Visuals 2/4 and names the same cause. Functionally correct (tests green), but the owner explicitly classified readability as a gap against RE-01/RE-02, not polish. Needs a UI-SPEC amendment before code."
    artifacts:
      - path: "resources/views/filament/pages/time-report.blade.php"
        issue: "no min-width on the name column, nowrap on seven numeric cells, weak level hierarchy, no collapsed-by-default levels"
      - path: "app/Filament/Pages/TimeReportPage.php"
        issue: "page layout is the owner-rejected one"
    missing:
      - "UI-SPEC amendment and redesign: wider or sticky name column, grouped or fewer columns, clearer level hierarchy, collapsible client/project levels"
deferred:
  - truth: "Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots (EX-03 second half)"
    addressed_in: "Phase 10"
    evidence: "Phase 10 success criterion 2: issuing 'freezes supplier, customer and bank snapshots, rates, amounts and the CNB rate with CZK total for client-currency invoices'. Phase 8 SC2 only requires the helper to return the CZK amount for a currency and date, which it does. ExchangeRateConverter has no production caller yet (08-REVIEW IN-07)."
human_verification:
  - test: "Ledger 2 (WINDOWS.md): open /admin/time-report in DDEV with a year of entries"
    expected: "The SPA progress bar shows while it loads and the filter strip stays usable (plan 08-09 task 3)"
    why_human: "Browser behaviour; not run by the executor"
  - test: "Ledger 3 (WINDOWS.md): rates overview 'Stahnout dnesni kurzy' with a slowed CNB answer, light and dark mode"
    expected: "In-flight state of the button is visible; white-on-primary contrast is acceptable (UI-SPEC F-1; 08-UI-REVIEW measured about 3.2:1 on Amber, below AA 4.5:1 for 14px text)"
    why_human: "Visual contrast and in-flight state need a browser; F-1 needs an owner decision (switch primary to Indigo or amend the spec)"
  - test: "Ledger 4 (WINDOWS.md): Admin dashboard in a browser"
    expected: "One column below lg, two from lg, widgets side by side; 'Pevna cena' and 'Chybi kurz' badges in the unbilled table; running-timer text contrast acceptable"
    why_human: "Layout and contrast; only the markup and figures are asserted by tests"
  - test: "Ledger 5 (WINDOWS.md): request a CSV export, open the notification bell, open the CSV in a Czech spreadsheet"
    expected: "Stock bell styling copes with long period text; columns split at the semicolon; decimal commas display correctly"
    why_human: "Depends on a spreadsheet application and bell rendering"
  - test: "Ledger 6 (WINDOWS.md): 375px visual pass of /admin/time-report and the Alpine expand/collapse"
    expected: "Horizontal scroll inside the tree, toggles work with Tab/Enter/Space with a visible focus ring, long names wrap"
    why_human: "Responsive and keyboard behaviour in a browser; also the natural place to judge the layout redesign"
  - test: "Ledger 7 (WINDOWS.md): open an XLSX export in a spreadsheet application"
    expected: "Frozen header pane, auto filter, CZK-missing comments and number formats as designed"
    why_human: "The workbook was verified by reading the package, never opened"
  - test: "PDF look sign-off (08-VALIDATION.md manual-only list, plan 08-06): generate a work report of about three pages for a Czech and an English client"
    expected: "Czech characters on every page, running header from page 2, footer 'Strana N / M' with generation date, billable section before non-billable. Two pdftotext layout tests are skipped in DDEV because pdftotext is not installed"
    why_human: "Visual judgement; owner sign-off still outstanding"
---

# Phase 8: Exchange Rates and Reports Verification Report

**Phase Goal:** Admin sees where time and money stand per client, project and period in client currency and CZK, can export and hand over work reports, and CNB rates feed every conversion
**Verified:** 2026-10-15T14:00:00Z
**Status:** gaps_found
**Re-verification:** No, initial verification

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Daily CNB download is scheduled, idempotent, weekends/holidays use previous rate, per-unit amounts convert, manual run and backfill exist | VERIFIED | `routes/console.php` schedules `kokpit:rates:download` at the configured hours Mon-Fri in Europe/Prague plus a `--final` run, both `onOneServer`. `DownloadCnbRates` and `HealCnbRateGaps` write with `insertOrIgnore` against a UNIQUE constraint (`exchange_rates` migration, `ExchangeRatesTableTest`). `RateBook`/`ExchangeRateConverter` look up the latest rate on or before the day and divide by the unit amount; `BackfillCnbRates` job and `DownloadCnbRatesCommand` exist; `DownloadCnbRatesTest`, `HealCnbRateGapsTest`, `BackfillCnbRatesTest`, `ExchangeRateConverterTest` pass in the full run |
| 2 | Rates overview, queue-independent failure alert, last known rate kept, System page last-rate date, conversion helper returns CZK for currency and date | VERIFIED | `ExchangeRateResource` + stats widget; `OperationalAlert` is deliberately not queueable and is sent with `sendNow` on the final run; `LastRateDateIndicator` fills the System page slot; `ExchangeRateConverter::toCzk/durationToCzk/rateFor` present, tested by `ExchangeRateConverterTest`, `RateAlertTest`. Caveat: WR-02 (false "today missing" alert when only the gap heal fails) is a warning, not a gap |
| 3 | Time report per client and project and period with task/day/user breakdown, billable vs non-billable, billed vs unbilled, client currency and CZK, effective hourly rate for fixed price | FAILED | Feature exists, is wired (`TimeReportPage` -> `TimeReport` -> `TimeReportReader`, `FixedPriceSummary`, `EffectiveRateTest`, `EffectiveBillingParityTest`, `TimeReportTest` green). FAILED on two recorded gaps: CR-01 mixed-currency crash and the owner-recorded unreadable layout (see Gaps) |
| 4 | CSV/XLSX export built in background and delivered by notification with spreadsheet-safe cells; multi-page PDF work report with correct Czech diacritics | VERIFIED (with caveat) | `BuildReportExport` job (CSV via writer, XLSX via OpenSpout, explicit string cells via `SpreadsheetCells`), ready/failed notifications, `ReportExportResource` with checked download, `PruneExpiredReportExports` scheduled; `WorkReportPdf` (Dompdf) with tests `WorkReportPdfTest`, `ReportExportTest`, `WorkReportDownloadTest`. Caveats: XLSX path shares CR-01; PDF look and XLSX opening are human items; WR-01 (orphan file on failed record) and WR-03 (255-char summary overflow) are warnings |
| 5 | Dashboard shows today's and this week's hours, unbilled time per client and upcoming task deadlines | VERIFIED (with caveat) | `Dashboard` registers `HoursStats`, `UnbilledPerClient`, `UpcomingDeadlines`, `TomorrowPlan` for Admin and `PartnerTasks`/`PartnerProjects` for Partner; `AdminDashboardTest`, `PartnerDashboardTest` green. Caveat: `UnbilledPerClient` throws on mixed currencies (CR-01) |

**Score:** 4/5 truths verified (0 present, behavior-unverified)

### Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | EX-03 second half: invoice and transaction CZK amounts and document rate snapshots | Phase 10 | Phase 10 SC2: issuing freezes rates, amounts and the CNB rate with CZK total. The helper itself is delivered and tested here; it has no production caller yet (IN-07) |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/ExchangeRates/*` (Models, Actions, Cnb client, RateBook, Converter, Jobs, CzechPublicHolidays) | Rates storage, download, heal, backfill, conversion | VERIFIED | Present, substantive, wired through console schedule, Filament resource and report code |
| `database/migrations/2026_10_15_000100_create_exchange_rates_table.php`, `..._000200_create_report_exports_table.php` | DB constraints | VERIFIED | Exist; schema tests green |
| `app/Domain/Reports/*` (reader, TimeReport, buckets, filters, FixedPriceSummary, exports, jobs, WorkReport) | Report engine, exports, PDF | VERIFIED (CR-01 defect in aggregation) | Substantive and wired |
| `app/Filament/Pages/TimeReportPage.php`, `resources/views/filament/pages/time-report.blade.php` | Report UI | HOLLOW as to usability | Renders and is tested, layout rejected by the owner |
| `app/Filament/Widgets/*` (HoursStats, UnbilledPerClient, UpcomingDeadlines, TomorrowPlan, PartnerTasks, PartnerProjects) | Dashboard | VERIFIED | Wired in `Dashboard.php` |
| `dompdf/dompdf`, `openspout/openspout` in `composer.json` | PDF and XLSX | VERIFIED | Present; licence checkpoint decided 2026-10-14; `composer check-licenses` reported green by 08-22 |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `routes/console.php` | `kokpit:rates:download` | Schedule::command (hours + --final) | WIRED | onOneServer, Prague timezone |
| `TimeReportPage` | `TimeReport`/`TimeReportReader` | report() | WIRED | |
| `BuildReportExport` | `ReportExport` record, notifications, private disk | queued job | WIRED | |
| `Dashboard` | Admin and Partner widgets | getWidgets by role | WIRED | |
| `ExchangeRateConverter` | invoice/transaction code | none yet | ORPHANED (intentional) | Phase 10 caller; IN-07 |
| `UpdateClient` currency lock | `task_billing` money | none | NOT_WIRED | Root cause of CR-01 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Time report tree | buckets | `TimeReportReader` SQL over `time_entries` joined to billing levels and `RateBook` over `exchange_rates` | Yes | FLOWING (crashes on mixed-currency client) |
| Dashboard widgets | stats, unbilled rows, deadlines | scoped Eloquent/SQL queries | Yes | FLOWING |
| Rates overview | rows | `exchange_rates` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Whole suite (run once) | `ddev exec vendor/bin/pest --compact` | 3867 passed, 3 skipped (25019 assertions), 275 s | PASS |
| Schedule registered | read `routes/console.php` | rates, final run and export prune present, all onOneServer | PASS |
| CR-01 reproduction | read of `ReportBucket::add`, `UnbilledPerClient::compute`, `UpdateClient`, `ProjectBilling::clientHoldsMoney`, `RATE_CURRENCY_SQL`; grep of `tests/` | defect path present, no covering test | FAIL (confirmed by reading, not executed, because no source or DB mutation was allowed) |

### Probe Execution

SKIPPED: the phase declares no probe scripts (`scripts/*/tests/probe-*.sh` not referenced by any PLAN).

### Requirements Coverage

All eight IDs are declared in the plans (22 plans, see ROADMAP plan list) and mapped to Phase 8 in REQUIREMENTS.md; no orphaned requirement.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| EX-01 | 08-01, 04, 08, 13, 17, 18 | Daily idempotent CNB download, manual run, backfill, previous rate on weekends/holidays, per-unit | SATISFIED | Truth 1 |
| EX-02 | 08-04, 08, 10, 13 | Rates overview, failure alert, last known rate used | SATISFIED | Truth 2 (WR-02 warning) |
| EX-03 | 08-05 | Conversion helper; document rate snapshots | SATISFIED for this phase | Helper delivered and tested; invoice/transaction use and snapshots land in Phase 10 (deferred) |
| RE-01 | 08-09, 14 | Time report per client and project with task/day/user breakdown | BLOCKED | Gaps: CR-01, unreadable layout |
| RE-02 | 08-05, 09, 14 | Billable/non-billable and billed/unbilled with client currency and CZK amounts | BLOCKED | Gap: CR-01 (and layout) |
| RE-03 | 08-03, 06, 07, 11, 12, 16, 21 | CSV/XLSX queued export, PDF work report | SATISFIED (human sign-off pending) | Truth 4; XLSX affected by CR-01 |
| RE-04 | 08-02, 15, 20 | Dashboard hours, unbilled per client, deadlines (overdue invoices and payments tiles are Phase 10/11) | SATISFIED for Phase 8 scope | Truth 5; CR-01 affects the unbilled widget |
| RE-05 | 08-05, 19 | Effective hourly rate for fixed-price projects | SATISFIED | `FixedPriceSummary`, `EffectiveRateTest` |

REQUIREMENTS.md currently marks all eight Complete; with the gaps above RE-01 and RE-02 should not be treated as closed until the gap plans land.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Reports/ReportBucket.php` | ~83 | Unguarded `Money::plus()` across entries | BLOCKER | CR-01 |
| `app/Filament/Widgets/UnbilledPerClient.php` | ~200 | Unguarded `Money::plus()` | BLOCKER | CR-01, Admin dashboard 500 |
| `app/Domain/Reports/Jobs/PruneExpiredReportExports.php` | 35-37 | Deletes only `$export->path`; failed record with orphan file leaks it (WR-01) | WARNING | Report data left on private disk |
| `app/Console/Commands/DownloadCnbRatesCommand.php` | 56-76 | Heal failure alerts as "today's rates missing" (WR-02) | WARNING | Misleading Admin alert |
| `app/Filament/Pages/TimeReportPage.php` | 140-144, 215-226 | `report_exports.summary` varchar(255) can overflow (WR-03) | WARNING | Export request fails with DB error on long names |
| Info IN-01 to IN-09 | various | See 08-REVIEW.md | INFO | Follow-ups, not blocking |

No unreferenced `TBD`, `FIXME` or `XXX` markers were found in `app`, `resources/views`, `lang`, `routes` or `config`.

### Human Verification Required

See the `human_verification` frontmatter: WINDOWS.md ledger entries 2 to 7 (all still `open`, none marked passed) and the PDF look sign-off. Security (`08-SECURITY.md`, 72 threats closed, 0 open) and validation (`08-VALIDATION.md`, validated) were read and are consistent with the code reviewed; no Partner leak path was found by the code review.

### Gaps Summary

Two gaps block the phase goal.

1. **CR-01 (mixed currencies per client).** Treated as a gap, not a follow-up. The report, the Admin dashboard widget and the XLSX export throw when one client's entries resolve to two currencies, which is reachable through the UI because the client currency lock ignores task-level rates. It undermines RE-01/RE-02 amounts "in client currency and CZK" and RE-04 on the Admin's landing page. Fix both the lock (root cause, outside the phase file list) and the consumers, and add the regression test. 08-REVIEW-DISPOSITION.md still lists CR-01 as `open`.
2. **Unreadable time report layout.** Owner-recorded gap (pending todo `2026-10-14-redesign-unreadable-time-report-layout`), root cause confirmed in `time-report.blade.php`; needs a UI-SPEC amendment, then a redesign through `/gsd-plan-phase 8 --gaps`.

Not gaps (run as separate quick tasks after the phase, per the owner): overdue highlight on `/admin/tasks`, today's plan under the hours stats, collapsible dashboard widgets (pending todos dated 2026-10-15). The review warnings WR-01 to WR-03 are recommended for the same gap round because they touch the same files; IN-01 to IN-09 are optional follow-ups.

Everything else in the phase holds: EX-01, EX-02, RE-03, RE-05 and the rest of RE-04 are backed by substantive, wired code and a green full suite (3867 passed, 3 skipped, all environment guards).

---

_Verified: 2026-10-15T14:00:00Z_
_Verifier: Claude (gsd-verifier)_
