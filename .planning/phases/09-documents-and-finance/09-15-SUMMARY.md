---
phase: 09-documents-and-finance
plan: 15
subsystem: finance
tags: [finance, overview, filament-page, filters-form, chart-widget, table-widget, czk, partner-isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: transactions table with stored czk_minor (09-09), transaction screen and write Actions (09-11, 09-22), receipts (09-13)
  - phase: 08-time-reports-and-exchange-rates
    provides: the ReportFilters validation pattern and the Admin-or-system reader refusal
provides:
  - FinanceFilters value object (Month / Year selection, half-open Prague date range, fallback to the current Prague month)
  - FinanceOverview reader (totals, byCategory with child sums rolled into parents, byClient with a last "Bez klienta" row, months)
  - "Přehled financí" Admin page with the Month / Year filter form
  - FinanceStats, FinanceCategoryTable, FinanceClientTable and FinanceMonthsChart widgets
affects: [Phase 10 invoice payments booked into the system income category, Phase 11 payment views]

actuals:
  tokens: 16000
  tasks: 3
  commits: 3
plan_head_before: ed1fbaaa8796caa0a6af6ec4f2426d7d3dbe3502
plan_head_after: 97d15d3766ca6f78b5afac0d10cb369a53a4488d
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Page with HasFiltersForm whose state is re-normalised on mount and on every change; every widget re-validates its client-controlled pageFilters through FinanceFilters::fromInput"
    - "Finance widgets under app/Filament/Finance/Widgets, outside panel widget discovery, each with AccessRule AdminOnly and EnforcesWidgetAccessRule"
    - "TableWidget over records() with a flat list of arrays; parent rows bold, child rows indented with a chevron"

key-files:
  created:
    - app/Domain/Finance/FinanceFilters.php
    - app/Domain/Finance/Queries/FinanceOverview.php
    - app/Filament/Pages/FinanceOverviewPage.php
    - resources/views/filament/pages/finance-overview.blade.php
    - app/Filament/Finance/Widgets/FinanceStats.php
    - app/Filament/Finance/Widgets/FinanceCategoryTable.php
    - app/Filament/Finance/Widgets/FinanceClientTable.php
    - app/Filament/Finance/Widgets/FinanceMonthsChart.php
    - tests/Feature/Finance/FinanceOverviewTest.php
  modified:
    - lang/cs/finance.php

key-decisions:
  - "Any invalid or missing period, year or month makes the whole selection fall back to the current Prague month; only a year period ignores the month"
  - "FinanceFilters::fromInput(period, year, month, ?now) is the single validation entry; the page and every widget call it with the three array keys, there is no second parsing path"
  - "The page does not persist its filters in the session (persistsFiltersInSession() is false), a new visit starts on the current month"
  - "The chart is the one place where minor units become plain numbers: whole CZK, rounded half up with integer arithmetic"
  - "Category rows are the categories with transactions in the period only; a root's total is its own transactions plus its children's, and its own part is not shown as a separate row"
  - "The reader returns an empty name and a null client_id for the no-client row; the widget words it with finance.overview.clients.no_client"

patterns-established:
  - "Admin-only overview reader: refuse everybody but the Admin and a system run, read through the Eloquent model so the Partner scope still applies, sum stored czk_minor, never convert again"

requirements-completed: [FI-02]

coverage:
  - id: D1
    description: "The Admin opens one Přehled financí page with a Month / Year switch and sees income, expense and balance tiles in CZK for the chosen period"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#the tracer: the tiles of a month"
        status: pass
    human_judgment: false
  - id: D2
    description: "A category table shows income and expense categories with the children's sums rolled into each parent and the children listed under it; archived categories with transactions stay"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#the category rollup"
        status: pass
    human_judgment: false
  - id: D3
    description: "A by-client table shows income, expense and balance per client in Czech order with a last Bez klienta row"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#the by-client table"
        status: pass
    human_judgment: false
  - id: D4
    description: "A bar chart shows income and expense for each month of the selected year (the year of the chosen month in Month mode)"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#the monthly chart"
        status: pass
    human_judgment: false
  - id: D5
    description: "Forged or missing period, year or month in widget state, page state or URL fall back to the current Prague month; years outside 2000..2100 are refused"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#forged filters"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner gets 403 on the page and on mounting every widget, and the reader refuses a Partner"
    requirement: FI-02
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/FinanceOverviewTest.php#the tracer: only the Admin"
        status: pass
      - kind: integration
        ref: "tests/Arch/PanelRegistryTest.php and tests/Isolation/PanelAccessTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "Visual layout of the overview page (tile row, two side-by-side tables, chart legend and tick formatting)"
    requirement: FI-02
    verification: []
    human_judgment: true
    rationale: "Rendering of the Chart.js bar chart, the indentation of child rows and the responsive grid are not asserted by tests; the tests read the widget data and the rendered text only"

# Metrics
duration: 20min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 15: Finance Overview Summary

**Admin-only "Přehled financí" page with a Month / Year filter form, CZK tiles, a category table that rolls child sums into parents, a by-client table with a "Bez klienta" row and a monthly Filament bar chart, all read from stored CZK amounts by one validated reader**

## Performance

- **Duration:** about 20 min (the start time was not recorded exactly; the full suite run took about 5 min of it)
- **Completed:** 2026-10-16T15:44Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 10 (9 created, 1 modified)

## Accomplishments

- `FinanceFilters` validates period (month or year), year (2000..2100) and month (1..12), accepts ints and digit strings only, and falls back to the current Prague month for arrays, floats, out-of-range numbers and missing values; it never throws. The period is a half-open range of Prague dates.
- `FinanceOverview` reads `totals()`, `byCategory()` (grouped by `COALESCE(parent_id, id)`), `byClient()` (Czech collation, no-client row last) and `months()` (twelve rows) by summing the stored `czk_minor` through `Transaction::query()`; it refuses everybody but the Admin and a system run.
- `FinanceOverviewPage` uses `HasFiltersForm` with a Month / Year toggle, a year select and a month select (hidden in Year mode); the URL state and every form change are normalised through `FinanceFilters`, so a forged `filters[...]` query or Livewire update is replaced by a valid state.
- Four widgets under `app/Filament/Finance/Widgets`, outside panel discovery so `PanelAccessTest`'s pinned widget list is unchanged; each has `AccessRule(Audience::AdminOnly)` and `EnforcesWidgetAccessRule` and re-validates `pageFilters`.
- The Czech copy lives in `lang/cs/finance.php` under `overview`.

## Task Commits

1. **Task 1: Tracer - the Admin opens the overview for a month and the tiles show income, expense and balance** - `cc1574e` (feat)
2. **Task 2: Category rollup and the by-client table, for a month and for a year** - `74202ee` (feat, TDD: tests written first and seen failing, then the reader methods and widgets)
3. **Task 3: The monthly bar chart and forged filters** - `97d15d3` (feat, TDD: tests written first and seen failing)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the targeted tests, the panel registry and panel access tests, Pint and PHPStan were green end to end before expansion.

## Files Created/Modified

- `app/Domain/Finance/FinanceFilters.php` - validated Month / Year selection
- `app/Domain/Finance/Queries/FinanceOverview.php` - the Admin-only reader of the overview figures
- `app/Filament/Pages/FinanceOverviewPage.php`, `resources/views/filament/pages/finance-overview.blade.php` - the page, filter form and layout
- `app/Filament/Finance/Widgets/FinanceStats.php`, `FinanceCategoryTable.php`, `FinanceClientTable.php`, `FinanceMonthsChart.php` - the four widgets
- `lang/cs/finance.php` - the `overview` copy
- `tests/Feature/Finance/FinanceOverviewTest.php` - 44 cases: tiles, rollup, clients, ranges, chart, forged filters, Partner refusals

## Decisions Made

See `key-decisions` above. The notable ones: one validation entry point (`fromInput`) used by the page and all widgets; no session persistence of the filters; whole-CZK rounding only in the chart; no-client label owned by the widget, not by the reader.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A project-wide Pint run reformatted unrelated lang files**
- **Found during:** Task 1 (running `pint app tests lang` for the new files)
- **Issue:** Pint rewrote six untouched `lang/cs/*.php` files (`binary_operator_spaces`), which are not part of this plan.
- **Fix:** Reverted those six files with `git checkout -- <file>` before staging; only `lang/cs/finance.php` was committed. Later Pint runs were limited to `app` and `tests/Feature/Finance`.
- **Files modified:** none committed
- **Verification:** `git status --short` showed only plan files before each commit; `pint --test` passes
- **Committed in:** n/a

**2. [Rule 1 - Bug] Larastan flagged unnecessary types and a collection pluck**
- **Found during:** Tasks 1 and 3 (PHPStan)
- **Issue:** `->get()->pluck()` should be a query `pluck`, and `FinanceMonthsChart::getHeading()` declared a return type wider than it returns.
- **Fix:** `->toBase()->pluck('total', 'type')` and a `string` return type.
- **Files modified:** `app/Domain/Finance/Queries/FinanceOverview.php`, `app/Filament/Finance/Widgets/FinanceMonthsChart.php`
- **Verification:** PHPStan clean
- **Committed in:** `cc1574e`, `97d15d3`

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** None on behavior or scope. One small contract refinement: the plan's artifact table lists `FinanceFilters::fromInput(period, year, month)`; it gained an optional fourth `$now` argument (defaults to the clock) so the Prague-month fallback is testable, and the throwaway `fromState` helper added in Task 1 was removed again so that the widgets call `fromInput` directly as the plan's key link states.

## Issues Encountered

None. The tracer and the Task 2 and 3 tests passed on the first run after the implementation was in place; each RED step was observed failing for the missing reader methods or widgets before they were written.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - the new surface is the Admin-only page and four widgets covered by T-09-51 (AccessRule AdminOnly on the page and every widget with the Enforces traits, reader refusal, 403 tests) and T-09-52 (FinanceFilters validation with forged-value tests). T-09-SC: no change to `composer.json` or `composer.lock`.

## Next Phase Readiness

- Plan 09-16 onward can link to `FinanceOverviewPage` (slug `finance-overview`, navigation sort 61).
- Phase 10 invoice payments booked into the system income category will appear in the overview with no change.
- The chart's look (colours, tick formatting) and the table indentation are visual and have no automated check; a quick look in the browser is worthwhile at phase verification.

## Self-Check: PASSED

- Created files exist on disk (all nine new files listed under key-files).
- Commits `cc1574e`, `74202ee` and `97d15d3` are ancestors of HEAD; `git rev-list --count ed1fbaa..HEAD` is 3 before this SUMMARY.
- Full suite `ddev exec vendor/bin/pest`: 4412 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.
- Acceptance greps passed: `HasFiltersForm` and `Audience::AdminOnly` in the page, `FinanceFilters::fromInput` and `Audience::AdminOnly` in `FinanceStats`, `czk_minor` and `COALESCE` and `function months` in the reader, `no_client` in `FinanceClientTable`, `extends ChartWidget` and `'bar'` in the chart.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
