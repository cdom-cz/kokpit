---
phase: 08-exchange-rates-and-reports
plan: 09
subsystem: reports
tags: [time-report, filament-page, per-entry-amounts, cnb-rates, effective-billing-sql, report-filters, czech-collation]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-05 RateBook::load()/rateFor() and Money::forDurationConverted() for CZK at the CNB rate of the entry's day"
  - phase: 08-exchange-rates-and-reports
    provides: "08-07 kokpit.exports.* copy and the export record the report's export menu will use"
  - phase: 06-time-tracking
    provides: "TimeEntry::elapsedSecondsSql, PragueDay, TimeTotals, TimeEntryRateResolver and TaskBillingResolver as the specification the SQL is pinned to"
provides:
  - "ReportFilters: validated, URL-backed filters (client, project, period, from, to, split) with a stored form for exports"
  - "TimeReportReader: one SQL over time_entries with the effective billing type and rate resolved set-based, Admin or system only"
  - "EntryAmounts: per-entry amount and CZK, rounded once, no amount for fixed-price, non-billable or rate-less entries"
  - "TimeReport: summary over the whole filtered set and the client > project > task tree with footer"
  - "TimeReportPage 'Report času' (Admin only, sort 42) with the GET filter strip, summary tiles, tree and empty state"
  - "The whole kokpit.report.* copy and config kokpit.report.max_rendered_rows"
affects: [08-14 tree split and markers, 08-15 dashboard, 08-16 exports, 08-19 effective rate block, 08-21 XLSX and PDF menu]

actuals:
  tokens: 28500
  tasks: 3
  commits: 4

plan_head_before: b5c000bd65acd28a311d2a9c943d888c12ed3f52
plan_head_after: a1c2edfd2c5bc48d7d6a29f808fd746164913aef
commits: 4

tech-stack:
  added: []
  patterns:
    - "Effective billing resolved in SQL by COALESCE over task, parent task, project, client (NULLIF inherit for the type), pinned to the resolvers by a parity test"
    - "Every report figure is a sum of per-entry amounts each rounded once; the CNB lookup is loaded once per report"
    - "Czech alphabetical order of tree rows from the database collation through small id-rank queries, constant in the number of entries"
    - "Untyped (mixed) URL properties on a Livewire page so a forged array reaches the validation instead of a type error"

key-files:
  created:
    - app/Domain/Reports/ReportFilters.php
    - app/Domain/Reports/ReportEntry.php
    - app/Domain/Reports/ReportBucket.php
    - app/Domain/Reports/Queries/TimeReportReader.php
    - app/Domain/Reports/EntryAmounts.php
    - app/Domain/Reports/TimeReport.php
    - app/Filament/Pages/TimeReportPage.php
    - resources/views/filament/pages/time-report.blade.php
    - tests/Feature/Reports/TimeReportTest.php
    - tests/Feature/Reports/EffectiveBillingParityTest.php
    - tests/Feature/Reports/ReportFiltersTest.php
  modified:
    - lang/cs/kokpit.php
    - config/kokpit.php

key-decisions:
  - "The summary is a flat array (worked, billable, non_billable, billed, unbilled, fixed, amounts, czk, czk_missing) so later plans read $summary['czk_missing'] and ['fixed'] directly"
  - "A tree node carries seconds (worked, billable, non_billable, billed, unbilled, fixed), amount, czk, czk_missing, fixed and children; amount and czk are null when no entry of the row had one"
  - "Entries of any billing type carry an amount when their own billable flag is set, the effective type is not fixed_price and a rate exists; a task of type non_billable with a billable entry is priced (the flag wins)"
  - "fromStored() and period() throw on an invalid value instead of falling back, so an export never silently covers other data than it was asked for; only fromInput() falls back"
  - "A reversed custom range is the only announced fallback; a missing or impossible day falls back to this month silently because the date inputs cannot produce it"
  - "TimeReportReader::rateCurrencies() was added so RateBook::load() asks only for the currencies the entries are priced in"
  - "mount() leaves the properties of a refused range untouched so every request reads the same refusal"

patterns-established:
  - "Parity test as the contract between a set-based SQL optimisation and the resolver that stays the specification"
  - "Test helper functions of a Pest file carry a file-specific prefix (timeReport*, parity*, filters*): Pest helpers share one global namespace"

requirements-completed: [RE-01, RE-02]

coverage:
  - id: D1
    description: "The Admin opens Report času for a client and a month and sees hours, the client-currency amount and the CZK amount per client, project and task, each entry priced once and converted at the CNB rate of its Prague day (a Saturday entry at Friday's rate)"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#shows the hours, the client-currency amount and the CZK amount per client, project and task"
        status: pass
    human_judgment: false
  - id: D2
    description: "The summary tiles and the footer cover the whole filtered set; worked equals billed + unbilled + non-billable and equals TimeTotals; amounts exist only for billable hourly entries; an entry without a CNB rate leaves the CZK sum and is counted"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the summary"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#shows the summary tiles over the whole filtered set and the footer total"
        status: pass
    human_judgment: false
  - id: D3
    description: "Filters live in the URL, are re-validated on every request and fall back to the defaults for any invalid client, project, date, period or split; a reversed custom range falls back to this month and says so; archived clients are listed marked; the project select is disabled until a client is chosen"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportFiltersTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the filter strip"
        status: pass
    human_judgment: false
  - id: D4
    description: "The effective billing type and rate of the report SQL equal TaskBillingResolver and TimeEntryRateResolver for task, parent task, project, client, zero, fixed-price at every level, non-billable, project-only, client-only and archived entries"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/EffectiveBillingParityTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "A Partner is refused the page, every Livewire update of it and the reader (403 or AuthorizationException); a guest too; a system run is served"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#who may open the report"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/EffectiveBillingParityTest.php#who may read the entries"
        status: pass
    human_judgment: false
  - id: D6
    description: "A period without entries renders the tiles at 0:00 and a dash plus the documented empty state, with the filter strip still usable"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#a period without entries"
        status: pass
    human_judgment: false
  - id: D7
    description: "A slow period loads under the stock SPA progress bar and the strip stays usable"
    verification: []
    human_judgment: true
    rationale: "A visual loading state in a browser; no automated test asserts it (UI Considerations M5 loading is a backstop). Recorded in the broken-windows ledger as an unrun verify."

duration: 22min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 09: Time Report Core Summary

**Admin-only "Report času" page: one SQL reads the effective billing of every entry (pinned to the existing resolvers by a 20-case parity test), each entry is priced once and converted to CZK at the CNB rate of its Prague day, and a summary over the whole filtered set plus a client > project > task tree sit behind a URL-backed, re-validated filter strip**

## Performance

- **Duration:** 22 min
- **Started:** 2026-10-14T20:30:00Z (reconstructed from file times; the start time was not recorded)
- **Completed:** 2026-10-14T20:52:00Z
- **Tasks:** 3
- **Files modified:** 13 (11 created, 2 modified)

## Accomplishments

- `TimeReportReader` joins client, user, project, task and the three billing rows in one statement and resolves the effective type (`COALESCE(NULLIF(tb.billing_type,'inherit'), NULLIF(ptb.billing_type,'inherit'), pb.billing_type, 'hourly')`) and rate (task, parent task, project, client; zero is a value, the currency comes from the same level). A running entry counts at its elapsed time from the bound PHP clock, an entry belongs to the Prague day it started, and archived clients, projects and tasks stay in.
- `EntryAmounts` prices an entry once (`Money::forDuration`), converts at the CNB rate of its day with one rounding (`Money::forDurationConverted`), passes a CZK client through without a lookup, gives no amount to fixed-price, non-billable or rate-less entries and reports a missing CNB rate as `czk_missing` instead of a zero.
- `TimeReport::build()` loads one `RateBook` for the priced currencies and aggregates the lazy rows in one pass into the summary (hours by state, amounts per currency, CZK, `czk_missing`, fixed-price seconds), the footer and the tree with clients and projects in Czech collation order and tasks by number. Worked = billed + unbilled + non-billable and equals `TimeTotals`.
- `TimeReportPage` (slug `time-report`, sort 42, `OutlinedDocumentChartBar`, no group) renders the GET filter strip that submits on every change, the four summary tiles in a `fi-grid`, the computed-amounts note, the tree with the "Celkem" footer, the refused-range line and the empty state. `ReportFilters` validates everything from the URL (UUIDs of existing clients, a project of that client, real Prague days, period and split lists).
- The whole `kokpit.report.*` copy (filters, summary, notes, tree, effective rate block, empty state, export menu labels, PDF error toast) and `kokpit.report.max_rendered_rows` = 1000 are in place for plans 08-14, 08-16, 08-19 and 08-21.

## Task Commits

1. **Task 1: Tracer - the Admin opens "Report času" for a client and a month** - `24bca91` (feat)
2. **Task 2: The effective billing chain in SQL equals the resolvers** - `cf624f0` (test)
3. **Task 3: Validated filters, summary identity, empty state, row-cap setting** - `f40374b` (test, RED), `a1c2edf` (feat, GREEN)

**Plan metadata:** recorded in the docs commit that follows this summary.

### TDD gate notes

- **Task 2 had no RED.** The matrix (20 cases) was green on arrival because the tracer already built the SQL straight from the resolver specification. Accepted as in plan 08-05 ("fix whatever the matrix exposes", and it exposed nothing). To prove the test is not vacuous it was run against a deliberately broken reader (parent-task level removed, `inherit` no longer deferred): 7 of 20 cases failed (own rate, parent rate, subtask rate precedence, zero rate, same-level currency, inherited parent fixed price, archived task); the reader was restored byte for byte before the commit.
- **Task 3 RED (`f40374b`):** three target tests failed. Semantic assessment: (1) `refused custom range` failed because the page's `mount()` normalised the properties and erased the refusal, a real bug; (2) `max_rendered_rows` failed because the setting did not exist, the planned missing behavior; (3) the summary identity case failed on a test-side arithmetic fault and a running entry outside the period (fixed in the test, not a production defect). The RED commit contains the test files only; the `mount()` fix and the config landed in the GREEN commit `a1c2edf`.

## Files Created/Modified

- `app/Domain/Reports/ReportFilters.php` - validated filter value (`fromInput`, `period`, `toArray`, `fromStored`, `toQuery`, `startUtc`, `endUtcExclusive`)
- `app/Domain/Reports/ReportEntry.php` - one entry as the report reads it, with its effective type and rate
- `app/Domain/Reports/ReportBucket.php` - internal mutable accumulator of one tree row (not in the plan's file list)
- `app/Domain/Reports/Queries/TimeReportReader.php` - the one SQL, Admin or system only; `entries()` and `rateCurrencies()`
- `app/Domain/Reports/EntryAmounts.php` - per-entry amount and CZK, rounded once
- `app/Domain/Reports/TimeReport.php` - summary, tree, footer, entry count
- `app/Filament/Pages/TimeReportPage.php` - Admin-only page with URL filters
- `resources/views/filament/pages/time-report.blade.php` - filter strip, tiles, tree, footer, empty state (inline styles and Filament components only)
- `lang/cs/kokpit.php`, `config/kokpit.php` - the `report` copy and the row-cap setting
- `tests/Feature/Reports/TimeReportTest.php`, `EffectiveBillingParityTest.php`, `ReportFiltersTest.php` - 20 + 20 + 55 cases (plus the page cases)

## Decisions Made

See `key-decisions` above. The ones later plans must know: the summary and node shapes, that `fromStored()` throws, that the reader has `rateCurrencies()`, and that an entry with its own billable flag set is priced even when its task is of type non-billable (the flag is preset from the task but editable, so it wins).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A refused custom range was erased by `mount()` before the page could announce it**
- **Found during:** Task 3 (RED run of the filter strip tests)
- **Issue:** `mount()` wrote the normalised filters back into the URL properties, so the later `filters()` call in the view saw an already valid this-month range and never set `rangeError`; "Datum Od nesmí být později než Do." never appeared.
- **Fix:** when the filters report a range error, `mount()` leaves period, from and to as they came; every request then re-reads the same refusal.
- **Files modified:** app/Filament/Pages/TimeReportPage.php
- **Verification:** `TimeReportTest` "says why a reversed custom range was refused and shows this month"; full suite green
- **Committed in:** a1c2edf

**2. [Rule 3 - Blocking] Pest helper names collided across test files**
- **Found during:** Task 1 (wider run with `tests/Feature/Reports`)
- **Issue:** `reportEntry()` already exists in `WorkReportPdfTest.php`; Pest helpers share one global namespace, so the combined run died with "Cannot redeclare function".
- **Fix:** every helper of the three new test files carries a file prefix (`timeReport*`, `parity*`, `filters*`).
- **Files modified:** tests/Feature/Reports/TimeReportTest.php (before its first commit)
- **Committed in:** 24bca91

**3. [Rule 2 - Missing critical] Added `TimeReportReader::rateCurrencies()` and the `ReportBucket` accumulator**
- **Found during:** Task 1
- **Issue:** the artifact table has neither. `TimeReport::build()` must load `RateBook` once for the currencies in scope before streaming, and a typed mutable accumulator keeps the one-pass aggregation readable at PHPStan level 8.
- **Fix:** a second reader method (distinct rate currencies of the filtered entries, same joins and guard) and an internal `ReportBucket`.
- **Files modified:** app/Domain/Reports/Queries/TimeReportReader.php, app/Domain/Reports/ReportBucket.php
- **Committed in:** 24bca91

### Process note (not a code deviation)

The orchestrator's `pin.sh` root guard was refused by the permission classifier ("Code from External") and was therefore not executed. Its checks were done inline with plain git commands before every commit instead: `git rev-parse --show-toplevel` equals the pinned project root given by the orchestrator, HEAD is the attached branch `base-crm-erp`, and no commit ran from another checkout. Nothing was self-repaired.

---

**Total deviations:** 3 auto-fixed (1 bug, 1 blocking, 1 missing critical)
**Impact on plan:** The bug fix was needed for the plan's own truth about the refused range; the other two are structural and add no scope.

## Issues Encountered

- The sensitive-content checker flagged an eight-digit number in a test dataset as a company-ID lookalike; the dataset value was shortened (no data involved).
- Estimate was 72k tokens; the realized diff is about 28.5k on the same chars/4 scale (the estimate counted whole-file reads and four test files, the tests ended up as three).

## Known Stubs

None. The "Rozpad" control, the day and user child rows, the expand and collapse toggles, the fixed-price and missing-rate badges and footnotes and the row-cap rendering are plan 08-14's scope; `split` is parsed and validated here and the copy is in place. The export menu and the "Exporty" link are plans 08-16 and 08-21.

## Threat Flags

None. The new endpoint `/admin/time-report` is the one the plan's threat model covers (T-08-31 to T-08-35): Admin-only access rule plus boot hook, reader guard, `ReportFilters` validation, parity test, Blade `{{ }}` only.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 08-14 can add the split children under the task nodes (`children`), the toggles, badges, footnotes and the row cap on top of `TimeReport::tree`, `summary['czk_missing']`, `summary['fixed']` and `config('kokpit.report.max_rendered_rows')`.
- Plans 08-16 and 08-21 can stream `TimeReportReader::entries()` through `EntryAmounts::of()` with `RateBook::load()` and store/read filters through `ReportFilters::toArray()` / `fromStored()`.
- Plan 08-15's dashboard figure for a client and this month can be taken from `TimeReport::build(ReportFilters::period(...))`.
- Open: the human check of the SPA progress bar on a year of entries is recorded as an open entry in `.planning/WINDOWS.md`.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*

## Self-Check: PASSED

- Created files exist: all 11 files under `key-files.created` (FOUND on disk)
- Commits `24bca91`, `cf624f0`, `f40374b`, `a1c2edf` are ancestors of HEAD; `check evaluation-scope --plan 08-09 --commits-only` resolved all four
- Task acceptance criteria re-run: all greps succeed, `TimeReportTest`, `EffectiveBillingParityTest` and `ReportFiltersTest` exit 0
- Plan verification: full Pest suite 3541 passed, 3 skipped (pre-existing); Pint and Larastan clean; `scripts/check-sensitive.sh` clean on every commit
