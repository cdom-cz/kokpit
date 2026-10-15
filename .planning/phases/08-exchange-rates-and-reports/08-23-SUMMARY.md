---
phase: 08-exchange-rates-and-reports
plan: 23
subsystem: reports
tags: [time-report, multi-currency, report-bucket, xlsx-export, csv-export, gap-closure, cr-01]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-14 TimeReport tree, ReportBucket and the report view; 08-16 BuildReportExport"
provides:
  - "ReportBucket keeps client-currency amounts per ISO currency; plus() runs only between equal currencies"
  - "TimeReport tree nodes carry an amounts map (ksorted) and czk_missing_currency; the footer amounts come from the root bucket and equal summary amounts billable key by key"
  - "The report view lists one Částka line per currency on every row and names the currency that lacks a CNB rate"
  - "Regression tests for the page, the aggregate, the missing rate, the fixed price, the day rows, the XLSX and the CSV export of a CZK client with a EUR task rate"
  - "UI-SPEC B3, E2 and U-19, and the CONTRIBUTING sentence, describe per-currency rows instead of one currency per client"
affects: [08-24 dashboard widget, 08-25 client currency lock, 08-26 report column redesign]

actuals:
  tokens: 11700
  tasks: 3
  commits: 3

plan_head_before: b3ff6eb5f70b0b9efdd7c72da27d979f529ada6d
plan_head_after: 85da80341b82f551defdc002ace66152a1880499
commits: 3

tech-stack:
  added: []
  patterns:
    - "A report row keeps amounts as array<string, Money> keyed by currency; only the CZK figure crosses currencies"
    - "The footer is the root bucket's amounts map, so it equals the summary by construction"

key-files:
  created: []
  modified:
    - app/Domain/Reports/ReportBucket.php
    - app/Domain/Reports/TimeReport.php
    - resources/views/filament/pages/time-report.blade.php
    - tests/Feature/Reports/TimeReportTest.php
    - tests/Feature/Reports/ReportExportTest.php
    - .planning/phases/08-exchange-rates-and-reports/08-UI-SPEC.md
    - CONTRIBUTING.md

key-decisions:
  - "The single nullable ReportBucket amount became an amounts map by currency; the sumsAmount constructor switch was removed because the root bucket now sums per currency too"
  - "The footer reads the root bucket's amounts instead of merging client rows, which makes footer == summary billable per key without extra code"
  - "BuildReportExport needed no change: its per-currency accumulation was already safe and is now pinned by tests"

patterns-established:
  - "Missing-rate tooltip currency comes from the first entry of the row without a CNB rate (czk_missing_currency), not from a row-level currency"

requirements-completed: [RE-01, RE-02, RE-03]

coverage:
  - id: D1
    description: "The time report page and aggregate stay usable for a CZK client with a EUR task rate: one amount line per currency, CZK summed at the day rate"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#a client with money in two currencies"
        status: pass
    human_judgment: false
  - id: D2
    description: "Footer amounts equal the summary billable amounts per currency; missing-rate badge names the currency; fixed-price hours keep dashes"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#keeps the footer equal to the billable amounts of the summary, key by key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#lists the part without a CNB rate as missing and names the currency, never as zero"
        status: pass
    human_judgment: false
  - id: D3
    description: "XLSX and CSV export of a two-currency client end Ready, with a summary row per currency and CZK total 4500"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#a client with money in two currencies"
        status: pass
    human_judgment: false

duration: 30min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 23: Mixed-currency time report and exports Summary

**Report rows keep client-currency amounts per ISO currency (ReportBucket amounts map, node amounts and czk_missing_currency, one Částka line per currency), so a CZK client with a legacy EUR task rate no longer crashes the page, and the XLSX and CSV exports are pinned to end Ready.**

## Performance

- **Duration:** about 30 min
- **Tasks:** 3 of 3
- **Files modified:** 7

## Accomplishments

- Closed the consumer half of CR-01 (08-VERIFICATION.md gap 1): `GET /admin/time-report` for the mixed client and for all clients answers 200 and `TimeReport::build()` no longer throws "Money in different currencies cannot be combined."
- Client and project rows show 1 500,00 Kč and 120,00 € as separate lines with 4 500,00 Kč in Částka v Kč; the Landing page row shows 120,00 € and 3 000,00 Kč (CNB rate of its Prague day); the Contact form row shows 1 500,00 Kč in both columns.
- The footer lists one amount per currency and equals `summary['amounts']['billable']` key by key; its CZK figure equals `summary['czk']['billable']`.
- Without a stored EUR rate the mixed client row keeps 1 500,00 Kč with the Chybí kurz badge, the tooltip names EUR and 15. 9. 2026, the footnote counts 1 entry and no zero amount appears.
- Fixed-price hours under the mixed client still carry dashes and the tooltip; the day row of Landing page lists both currencies.
- XLSX export of the mixed client ends Ready with 120.0 EUR / 3000.0 and 1500.0 CZK / 1500.0 entry rows and a summary `[[1500.0, 'CZK'], [120.0, 'EUR']]` with Částka v Kč 4500.0; the CSV export ends Ready with each row in its own currency.
- UI-SPEC (B3 bullet, E2 column, new U-19) and the CONTRIBUTING "Reports and exports" bullet now describe per-currency rows.

## Task Commits

1. **Task 1 (tracer): per-currency buckets, nodes and view, UI-SPEC** - `40a7f2a` (fix)
2. **Task 2: markers on mixed rows and the CONTRIBUTING sentence** - `ed66acd` (docs)
3. **Task 3: XLSX and CSV export regression, then the full gate** - `85da803` (test)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Reports` : 306 passed, 2 skipped (pre-existing skips)
- `ddev exec vendor/bin/pest` (full suite): 3875 passed, 3 skipped
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors
- `scripts/check-sensitive.sh`: clean on every commit; lefthook (sensitive-content, gitleaks) passed
- Acceptance greps of the three tasks all hold (single nullable amount gone, `$node['amount']` gone from the view, U-19 present, the old CONTRIBUTING phrase gone).
- Tracer gate (auto and end-of-phase mode): the tracer verify (TimeReportTest and EffectiveRateTest) was re-run end to end before expansion and passed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Test text assertion for "no 0,00 Kč" matched the tail of "1 500,00 Kč"**
- **Found during:** Task 1 (running the mixed-client missing-rate case)
- **Issue:** `not->toContain('0,00 Kč')` is a substring of `1 500,00 Kč`, so the assertion could never pass for a CZK amount of 1 500
- **Fix:** replaced with `not->toMatch('/(?<!\d)0,00 Kč/u')`, which rejects only a standalone zero amount
- **Files modified:** tests/Feature/Reports/TimeReportTest.php
- **Commit:** 40a7f2a

**2. [Rule 1 - Bug in own test] Client order in the footer-equals-summary case**
- **Found during:** Task 1
- **Issue:** the clients sort in Czech alphabetical order (Alpha, Gamma, Mixed), not in creation order, so the first draft of the test indexed the wrong tree rows
- **Fix:** indexes corrected to tree[0] EUR, tree[1] USD, tree[2] CZK and EUR
- **Files modified:** tests/Feature/Reports/TimeReportTest.php
- **Commit:** 40a7f2a

### Sequencing note

The four Task 2 test cases (missing-rate currency, footer equals summary, fixed price, day row) share the Task 1 helper and describe block, so they were written and committed together with Task 1 (commit `40a7f2a`) instead of in Task 2. They passed without further production code, so Task 2's own commit holds only the CONTRIBUTING change. No behaviour differs from the plan.

**Total deviations:** 2 auto-fixed (1 blocking test assertion, 1 test indexing bug), 1 sequencing note. **Impact:** none on scope or production behaviour.

`app/Domain/Reports/Jobs/BuildReportExport.php` was not touched: the new export tests passed against it unchanged, as the plan expected.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or trust-boundary surface; amounts and the tooltip currency reach the view only through `{{ }}`.

## Issues Encountered

None. Out of scope and untouched: the dashboard widget half (plan 08-24) and the lock half (plan 08-25).

## Next Phase Readiness

Ready for 08-24 and 08-25. Plan 08-26 can build the column redesign on the per-currency `amounts` map of every node.

## Self-Check: PASSED

- Files exist: ReportBucket.php, TimeReport.php, time-report.blade.php, TimeReportTest.php, ReportExportTest.php, 08-UI-SPEC.md, CONTRIBUTING.md
- Commits `40a7f2a`, `ed66acd`, `85da803` are ancestors of HEAD
