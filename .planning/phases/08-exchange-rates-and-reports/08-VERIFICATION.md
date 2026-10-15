---
phase: 08-exchange-rates-and-reports
verified: 2026-10-15T20:00:00Z
status: human_needed
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
covered_digest: "v3:sha256:50a5a3a1626f4f5cbf0ec449fa605269d9db7f58c6503615cb3d1fd5ae0130be"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 4/5
  gaps_closed:
    - "CR-01: a client with money in two currencies no longer crashes the time report, the Admin dashboard widget or the XLSX/CSV export (08-23, 08-24, 08-25)"
    - "Unreadable time report layout, code side (08-26, 08-27): full-width fixed table, name column floor, grouped columns, four distinguishable levels, open depth by filters, sticky name column"
  gaps_remaining: []
  regressions: []
deferred:
  - truth: "Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots (EX-03 second half)"
    addressed_in: "Phase 10"
    evidence: "Phase 10 success criterion 2: issuing 'freezes supplier, customer and bank snapshots, rates, amounts and the CNB rate with CZK total for client-currency invoices'. Phase 8 SC2 only requires the helper to return the CZK amount for a currency and date, which it does."
human_verification:
  - test: "Owner visual pass of /admin/time-report (WINDOWS.md ledger 6; todo 2026-10-14-redesign-unreadable-time-report-layout) with a real month of entries, at desktop width and at 375px"
    expected: "Task titles no longer wrap one word per line, client/project/task/day-or-user levels are told apart, the tree opens only as deep as the filters ask, the Nazev column stays in view while scrolling sideways, toggles work with Tab/Enter/Space with a visible focus ring. The owner confirms the page is now usable"
    why_human: "The original gap was recorded by the owner as a readability judgement. Tests pin the markup (data-level, hidden attributes, sticky and colgroup rules) but cannot judge that the page reads well. This is the only way to close the owner-recorded gap"
  - test: "Ledger 2 (WINDOWS.md): open /admin/time-report in DDEV with a year of entries"
    expected: "The SPA progress bar shows while it loads and the filter strip stays usable (plan 08-09 task 3)"
    why_human: "Browser behaviour; not run by the executor"
  - test: "Ledger 3 (WINDOWS.md): rates overview 'Stahnout dnesni kurzy' with a slowed CNB answer, light and dark mode"
    expected: "In-flight state of the button is visible; white-on-primary contrast acceptable (UI-SPEC F-1; 08-UI-REVIEW measured about 3.2:1 on Amber, below AA 4.5:1 for 14px text)"
    why_human: "Visual contrast and in-flight state need a browser; F-1 needs an owner decision (switch primary to Indigo or amend the spec)"
  - test: "Ledger 4 (WINDOWS.md): Admin dashboard in a browser, including a client with unbilled time in two currencies (one Castka line per currency)"
    expected: "One column below lg, two from lg, widgets side by side; 'Pevna cena' and 'Chybi kurz' badges; running-timer text contrast acceptable"
    why_human: "Layout and contrast; only the markup and figures are asserted by tests"
  - test: "Ledger 5 (WINDOWS.md): request a CSV export, open the notification bell, open the CSV in a Czech spreadsheet"
    expected: "Stock bell styling copes with long period text; columns split at the semicolon; decimal commas display correctly"
    why_human: "Depends on a spreadsheet application and bell rendering"
  - test: "Ledger 7 (WINDOWS.md): open an XLSX export in a spreadsheet application"
    expected: "Frozen header pane, auto filter, CZK-missing comments and number formats as designed; per-currency amounts in the Souhrn sheet"
    why_human: "The workbook was verified by reading the package, never opened"
  - test: "PDF look sign-off (08-VALIDATION.md manual-only list, plan 08-06): generate a work report of about three pages for a Czech and an English client"
    expected: "Czech characters on every page, running header from page 2, footer 'Strana N / M' with generation date, billable section before non-billable. Two pdftotext layout tests are skipped in DDEV because pdftotext is not installed"
    why_human: "Visual judgement; owner sign-off still outstanding"
---

# Phase 8: Exchange Rates and Reports Verification Report

**Phase Goal:** Admin sees where time and money stand per client, project and period in client currency and CZK, can export and hand over work reports, and CNB rates feed every conversion
**Verified:** 2026-10-15T20:00:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap closure (plans 08-23 to 08-27)

## Re-verification of the previous gaps

| Previous gap | Verdict | Evidence read in the code |
|--------------|---------|---------------------------|
| CR-01: mixed-currency client crashes report, dashboard widget, export | CLOSED | `ReportBucket::add()` keeps `$amounts` keyed by ISO currency and calls `Money::plus()` only inside one currency (the CZK sum is the only cross-currency figure). `TimeReport.php:103`, `UnbilledPerClient.php:206-207` and `BuildReportExport.php:267` use the same per-currency pattern. Every remaining `plus()` call site in `app/Domain/Reports` and `app/Filament` was grepped: none sums across currencies. Regression tests exist and pass: `TimeReportTest` "a client with money in two currencies", `ReportExportTest` same describe (XLSX and CSV), `AdminDashboardTest` "opens the dashboard of a client whose unbilled time is priced in two currencies" and "equals the report per currency" |
| Root cause: client currency lock ignored `task_billing` money | CLOSED | `TaskBilling::clientHoldsMoney()` (raw-table `whereExists` over `tasks` and `projects`, so archived rows count; a stored zero counts) and `UpdateClient::handle()` refuses the currency change when `ProjectBilling::clientHoldsMoney() \|\| TaskBilling::clientHoldsMoney()`, as a field error on `currency`. `ClientRulesTest` "currency lock" covers task fixed price, task rate of zero and unlock after clearing the task rate |
| Unreadable time report layout (owner-recorded) | CLOSED in code, owner sign-off pending | `time-report.blade.php` is now a `table-layout: fixed` table, `min-width: 64rem`, `colgroup` with a free Nazev column and 6rem/10rem numeric columns, grouped header "Hodiny", names clamped to two lines with a `title` tooltip, sticky opaque Nazev cells, `data-level` on every row with distinct weight/band per level, `openDepth` computed in `TimeReportPage::getViewData()` (0 no client, 1 client, 2 project) and rendered as server `hidden` attributes, `getMaxContentWidth()` returns `Width::Full`. Tests: `TimeReportTest` "the tree opens as deep as the filters", the static style block / sticky rule tests. Whether the page now reads well is a judgement only the owner can make (see Human Verification) |

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Scheduled daily CNB download, idempotent, weekend/holiday uses previous rate, per-unit amounts, manual run and backfill | VERIFIED | Unchanged since the previous report (regression check: `routes/console.php`, `DownloadCnbRates`, `RateBook`, `ExchangeRateConverter`, backfill job present; related tests green in the full run) |
| 2 | Rates overview, queue-independent failure alert, last known rate kept, System page last-rate date, conversion helper | VERIFIED | Unchanged; `ExchangeRateConverterTest`, `RateAlertTest` and the resource tests green. WR-02 stays a warning |
| 3 | Time report per client and project with task/day/user breakdown, billable vs non-billable, billed vs unbilled, client currency and CZK, effective hourly rate for fixed price | VERIFIED (code and tests); layout readability needs owner sign-off | Mixed-currency crash removed (above); amounts are one line per currency plus CZK; `EffectiveRateTest`, `EffectiveBillingParityTest`, `TimeReportTest` green; readable layout built per UI-SPEC Amendment A-1 |
| 4 | CSV/XLSX export in background with notification and spreadsheet-safe cells; multi-page PDF work report with Czech diacritics | VERIFIED | `BuildReportExport` accumulates amounts per currency (already safe, now pinned by tests for CSV and XLSX of a mixed client); PDF path unchanged and tested. XLSX opening and PDF look remain human items |
| 5 | Dashboard shows today's and this week's hours, unbilled per client, upcoming deadlines | VERIFIED | `UnbilledPerClient` rows carry `amounts` per currency, Castka column is a `ViewColumn` over the cell view with one line per currency; dashboard answers 200 for a mixed-currency client (test). `AdminDashboardTest`, `PartnerDashboardTest` green |

**Score:** 5/5 truths verified (0 present, behavior-unverified)

### Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | EX-03 second half: invoice and transaction CZK amounts and document rate snapshots | Phase 10 | Phase 10 SC2: issuing freezes rates, amounts and the CNB rate with CZK total. The helper itself is delivered and tested here; it has no production caller yet |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Reports/ReportBucket.php` | per-currency amounts | VERIFIED | `array<string, Money> $amounts`, guarded accumulation |
| `app/Domain/Reports/TimeReport.php` | tree nodes with `amounts` map and `czk_missing_currency`, footer from root bucket | VERIFIED | Wired from `TimeReportPage` and the view |
| `app/Filament/Widgets/UnbilledPerClient.php`, `resources/views/filament/widgets/unbilled-per-client-cell.blade.php` | per-currency widget rows | VERIFIED | Wired in `Dashboard` |
| `app/Domain/Tasks/Models/TaskBilling.php`, `app/Domain/Clients/Actions/UpdateClient.php` | currency lock over task money | VERIFIED | `clientHoldsMoney()` called from `UpdateClient::handle()` |
| `app/Filament/Pages/TimeReportPage.php`, `resources/views/filament/pages/time-report.blade.php` | readable tree | VERIFIED (visual sign-off pending) | Substantive, wired, tested |
| `08-UI-SPEC.md` Amendment A-1 | contract before code | VERIFIED | Committed ahead of the layout change (07cdc11 before b56d025) |
| Rest of phase artifacts (rates domain, exports, PDF, widgets, migrations, packages) | as in the previous report | VERIFIED | No regression found; full suite green |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `UpdateClient` currency lock | `task_billing` money | `TaskBilling::clientHoldsMoney()` | WIRED | Previously NOT_WIRED, root cause of CR-01 |
| `TimeReportPage::getViewData()` | view `$openDepth` | filters-derived depth | WIRED | |
| `TimeReport` summary | dashboard widget and export summary | same per-currency key shape | WIRED | Parity pinned by `AdminDashboardTest` "equals the report per currency" |
| `routes/console.php` | `kokpit:rates:download` | Schedule::command | WIRED | unchanged |
| `ExchangeRateConverter` | invoice/transaction code | none yet | ORPHANED (intentional) | Phase 10 caller |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Time report tree | buckets / amounts | `TimeReportReader` SQL over `time_entries` and billing levels, `RateBook` | Yes | FLOWING (mixed-currency client now renders) |
| Dashboard unbilled widget | rows / amounts | scoped queries and `EntryAmounts` | Yes | FLOWING |
| Rates overview | rows | `exchange_rates` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Whole suite (run once) | `ddev exec vendor/bin/pest --compact` | 3903 passed, 3 skipped (25242 assertions), exit 0 (previous run 3867 passed) | PASS |
| Static analysis | `ddev exec vendor/bin/phpstan analyse` | No errors | PASS |
| No unguarded cross-currency sum | grep of `plus(` in `app/Domain/Reports` and `app/Filament` | every call is keyed by currency or is the CZK sum | PASS |

### Probe Execution

SKIPPED: the phase declares no probe scripts.

### Requirements Coverage

All eight IDs are declared in the Phase 8 plans and mapped to Phase 8 in REQUIREMENTS.md; no orphaned requirement. Gap plans declare RE-04 (08-24), RE-02 (08-25), RE-01/RE-02/RE-03 (08-23), RE-01/RE-02 (08-26, 08-27).

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| EX-01 | 08-01, 04, 08, 13, 17, 18 | Daily idempotent CNB download, manual run, backfill, previous rate, per-unit | SATISFIED | Truth 1 |
| EX-02 | 08-04, 08, 10, 13 | Rates overview, failure alert, last known rate | SATISFIED | Truth 2 |
| EX-03 | 08-05 | Conversion helper; document rate snapshots | SATISFIED for this phase | Helper delivered and tested; invoice/transaction use deferred to Phase 10 |
| RE-01 | 08-09, 14, 23, 26, 27 | Time report per client and project with task/day/user breakdown | SATISFIED (owner visual sign-off pending) | Truth 3 |
| RE-02 | 08-05, 09, 14, 23, 25, 26, 27 | Billable/non-billable and billed/unbilled with client currency and CZK amounts | SATISFIED | Truth 3, CR-01 closed |
| RE-03 | 08-03, 06, 07, 11, 12, 16, 21, 23 | CSV/XLSX queued export, PDF work report | SATISFIED (human sign-off pending) | Truth 4 |
| RE-04 | 08-02, 15, 20, 24 | Dashboard hours, unbilled per client, deadlines | SATISFIED for Phase 8 scope | Truth 5 |
| RE-05 | 08-05, 19 | Effective hourly rate for fixed-price projects | SATISFIED | `FixedPriceSummary`, `EffectiveRateTest` |

**Bookkeeping note (not a code gap):** `.planning/REQUIREMENTS.md` is currently inconsistent. EX-01, EX-02, EX-03 and RE-05 have unchecked boxes and trace-table status "Gaps Found" although this verification finds them satisfied (the previous report never listed them as gaps; the revert in 581f5f9 was broader than the gaps). RE-01 to RE-04 are checked and "Complete". Once the human items are accepted, all eight (EX-03 with the Phase 10 caveat) can be marked Complete.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Reports/Jobs/PruneExpiredReportExports.php` | 35-37 | WR-01 orphan file for a failed record (disposition still open) | WARNING | Report data left on private disk |
| `app/Console/Commands/DownloadCnbRatesCommand.php` | 56-76 | WR-02 false "today's rates missing" alert when only the heal fails (open) | WARNING | Misleading Admin alert |
| `app/Filament/Pages/TimeReportPage.php` | export request | WR-03 `report_exports.summary` varchar(255) overflow (open) | WARNING | Export request can fail with a DB error on very long names |
| IN-01 to IN-09 | various | See 08-REVIEW.md, all `open` in 08-REVIEW-DISPOSITION.md | INFO | Follow-ups |

No `TBD`, `FIXME` or `XXX` marker in any file changed by the gap round. WR-01 to WR-03 were recommended, not required, for the gap round and were not taken; they do not block a must-have truth.

### Human Verification Required

See the `human_verification` frontmatter. The decisive item is the owner visual pass of `/admin/time-report`: the original gap was the owner's readability judgement, which automated tests cannot close. The remaining items are the WINDOWS.md ledger entries 2 to 5 and 7 (still `open`) and the PDF look sign-off. Security (`08-SECURITY.md`, 72 threats closed) and validation (`08-VALIDATION.md`, gap-round rows added) are consistent with the code read.

### Gaps Summary

No blocking gaps remain. Both previous gaps are closed in code and covered by passing regression tests (full suite 3903 passed, 3 skipped, PHPStan clean). The phase is `human_needed` because the layout gap was recorded by the owner on a readability judgement and the browser, spreadsheet and PDF checks were never run by anyone.

---

_Verified: 2026-10-15T20:00:00Z_
_Verifier: Claude (gsd-verifier)_
