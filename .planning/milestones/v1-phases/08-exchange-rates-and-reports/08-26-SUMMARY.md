---
phase: 08-exchange-rates-and-reports
plan: 26
subsystem: reports-ui
tags: [time-report, layout, ui-spec-amendment, gap-closure, time-report-layout]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-23 per-currency amount lines in the report tree; 08-25 client currency lock over task money"
provides:
  - "08-UI-SPEC.md Amendment A-1 (2026-10-15): the readable tree contract for B3, B4, spacing, copy, UI considerations and decisions U-20 to U-25"
  - "TimeReportPage::getMaxContentWidth() returns Width::Full"
  - "The report tree as a fixed table (min-width 64rem, colgroup: Název auto, four hour columns of 6rem, two money columns of 10rem) with a two-row header and the group heading Hodiny"
  - "Seven tree columns in the order Název, Odpracováno, Nevyfakturováno, Vyfakturováno, Nefakturovatelné, Částka, Částka v Kč; Fakturovatelné left the tree"
  - "Names clamped to two lines with the escaped full label as a title tooltip; indent 1rem per level"
affects: [08-27 level hierarchy, sticky Název column, open depth by filters, gap bookkeeping]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

plan_head_before: 92de6e71256f3181b84a4e0810008128b8cb7938
plan_head_after: b56d0258ff785cbfeffe6ffd5a33eb4782e5c529
commits: 2

tech-stack:
  added: []
  patterns:
    - "Contract before code: a UI-SPEC amendment is committed ahead of the layout change it governs"
    - "Fixed table layout with a colgroup gives the free column a floor (64rem table minus 44rem of fixed columns = 20rem)"
    - "A clamped label carries its full text in an escaped title attribute"

key-files:
  created: []
  modified:
    - .planning/phases/08-exchange-rates-and-reports/08-UI-SPEC.md
    - .planning/todos/pending/2026-10-14-redesign-unreadable-time-report-layout.md
    - app/Filament/Pages/TimeReportPage.php
    - resources/views/filament/pages/time-report.blade.php
    - lang/cs/kokpit.php
    - tests/Feature/Reports/TimeReportTest.php
    - tests/Feature/Reports/EffectiveRateTest.php

key-decisions:
  - "Amendment A-1 records U-20 to U-25 (full width and fixed layout, Fakturovatelné leaves the tree, two-line clamp with tooltip, sticky Název column, open depth by filters, four-level hierarchy); U-20 to U-22 are built here, U-23 to U-25 in 08-27"
  - "Hour column headers use overflow-wrap: anywhere and hyphens: auto, because the longest titles do not fit in 6rem at 14px semibold; the owner visual pass decides whether that reads well"

requirements-completed: [RE-01, RE-02]

coverage:
  - id: D1
    description: "08-UI-SPEC.md carries Amendment A-1 before any layout code changed: rewritten B3 and B4, spacing scale, copy row, UI considerations, decisions U-20 to U-25, amendment log; the owner todo carries a Resolution section"
    requirement: "RE-01"
    verification:
      - kind: other
        ref: "grep of Amendment A-1, U-25, 64rem, Hodiny, kokpit-report-, 1024px, 320px in 08-UI-SPEC.md; scripts/check-sensitive.sh"
        status: pass
    human_judgment: false
  - id: D2
    description: "The report page is full width and the tree is a fixed table with a colgroup (one auto column, four of 6rem, two of 10rem), so the Název column cannot get narrower than 20rem at the 64rem floor"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the readable table"
        status: pass
    human_judgment: false
  - id: D3
    description: "Two-row header with the group Hodiny (scope colgroup, colspan 4) over Odpracováno, Nevyfakturováno, Vyfakturováno, Nefakturovatelné; no Fakturovatelné header cell; every body and footer row in the new column order"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php, tests/Feature/Reports/EffectiveRateTest.php (26 row expectations moved)"
        status: pass
    human_judgment: false
  - id: D4
    description: "A 200-character task title is clamped to two lines with its full label in an escaped title attribute; a markup and quote canary in a title never becomes a live tag or attribute"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#clamps a very long task title / shows markup in a title escaped"
        status: pass
    human_judgment: false
  - id: D5
    description: "At 1280px and at 375px the names read on at most two lines and the numeric columns stay aligned, including the hour column headers in a 6rem column"
    verification: []
    human_judgment: true
    rationale: "Visual judgment of a rendered layout; owner visual pass, WINDOWS.md ledger 6 (backstop)"

duration: 5min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 26: Readable time report tree, first half Summary

**Amendment A-1 written first, then the report rebuilt on it: a full-width page with a fixed 64rem table whose Název column keeps at least 20rem, seven grouped columns under a two-row header, and names clamped to two lines with an escaped tooltip.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-15T06:02:54Z
- **Completed:** 2026-10-15T06:07:04Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments

- 08-UI-SPEC.md Amendment A-1 rewrites Surface B3 and B4 as one readable contract (frame, columns, header grouping, Název cell, sticky column, four-level hierarchy, open depth by filters, B4 spacing), moves the tree indent to the 16px token, replaces the 960px floor, updates the copy row and the two UI-consideration rows, adds U-20 to U-25 and a log section with what each statement said before.
- The owner todo has a Resolution section: the design is A-1, built by 08-26 and 08-27, closed after the owner's visual pass (ledger 6); the file stays in pending.
- The tree table is a fixed layout with a colgroup, so a task title can no longer be squeezed to one word per line; the numeric columns shrank from seven to six hour-and-money columns, with the hour columns grouped under "Hodiny".
- The per-currency amount lines, fixed-price and missing-rate markers, footnotes, row cap and effective-rate block all render in the new order; Reports and Isolation suites are green.

## Task Commits

1. **Task 1: Contract first - Amendment A-1 of 08-UI-SPEC.md** - `07cdc11` (docs)
2. **Task 2: Tracer - full width, fixed grouped table, clamped names** - `b56d025` (feat)

**Plan metadata:** recorded in the docs commit that follows this summary.

## Files Created/Modified

- `.planning/phases/08-exchange-rates-and-reports/08-UI-SPEC.md` - Amendment A-1 and the statements it rewrites
- `.planning/todos/pending/2026-10-14-redesign-unreadable-time-report-layout.md` - Resolution section
- `app/Filament/Pages/TimeReportPage.php` - `getMaxContentWidth()` returns `Width::Full`
- `resources/views/filament/pages/time-report.blade.php` - fixed layout, colgroup, two-row header, new column order, clamped labels with title, money-group rule, effective-rate row colspan 7
- `lang/cs/kokpit.php` - `kokpit.report.tree.groups.hours` = "Hodiny"; the unused `columns.billable` entry removed (grep showed only the view read the column keys)
- `tests/Feature/Reports/TimeReportTest.php` - new describe "the readable table" (5 cases) and 25 row expectations moved to the new order
- `tests/Feature/Reports/EffectiveRateTest.php` - 1 row expectation moved

## Decisions Made

- Moved the row expectations mechanically (worked, billable, non-billable, billed, unbilled becomes worked, unbilled, billed, non-billable): the dropped billable value always equals unbilled plus billed, so no information left the assertions.
- Hour column headers get `overflow-wrap: anywhere` and `hyphens: auto`. "Nefakturovatelné" and "Nevyfakturováno" are wider than the 5rem left inside a 6rem column at 14px semibold, so without a break rule they would spill into the next column. Whether the resulting hyphenation or mid-word break reads well is the visual pass's call; the fallback is widening the hour columns in the contract.
- Placeholder rows ("Bez projektu", "Bez úkolu") keep their opacity 0.7 for now; the secondary-gray treatment belongs to plan 08-27.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The new clamp and escaping tests passed before any code change**
- **Found during:** Task 2 (RED run)
- **Issue:** The first versions asserted `line-clamp: 2` and the `title` attribute over the whole page. The timer bar and recent-entries panel already render both on every panel page, so the cases proved nothing.
- **Fix:** Added a `timeReportRowHtml()` helper and scoped both assertions to the tree row of the task; they then failed in RED and pass after the view change.
- **Files modified:** tests/Feature/Reports/TimeReportTest.php
- **Verification:** `--filter="the readable table"` showed 5 failures before and 5 passes after the view change.
- **Committed in:** b56d025

---

**Total deviations:** 1 auto-fixed (1 test-validity bug)
**Impact on plan:** None on scope; the tests are stronger than first written.

## Issues Encountered

None beyond the deviation above.

## Threat Flags

None. T-08-80 is mitigated: every name reaches HTML and the `title` attribute through `{{ }}`, and the markup and quote canary test proves it. T-08-81: the page keeps its Admin-only access rule and gains no public Livewire property or action; `tests/Isolation` (211 tests) is green. T-08-SC: nothing installed.

## Known Stubs

None.

## Next Phase Readiness

Ready for 08-27: the scoped `kokpit-report-` style block with the sticky Název column and dark variants, the four-level hierarchy, the open depth by filters, the effective-rate block spacing and the gap bookkeeping. The owner's visual pass (WINDOWS.md ledger 6) at 1280px and 375px stays open and should look at the hour column headers in 6rem and the money-group rule.

## Self-Check: PASSED

- Files: 08-UI-SPEC.md, the owner todo, TimeReportPage.php, time-report.blade.php, kokpit.php, both test files all exist and are committed.
- Commits: `07cdc11` and `b56d025` are ancestors of HEAD; `git rev-list --count` from the plan base gives 2.
- Acceptance criteria of both tasks re-run and passing; `ddev exec vendor/bin/pest tests/Feature/Reports` 311 passed, 2 skipped; `tests/Isolation` 211 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-15*
