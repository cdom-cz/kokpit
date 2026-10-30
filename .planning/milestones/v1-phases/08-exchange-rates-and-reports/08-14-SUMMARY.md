---
phase: 08-exchange-rates-and-reports
plan: 14
subsystem: reports
tags: [time-report, alpine, accessibility, rozpad, fixed-price, cnb-missing-rate, row-cap, multi-currency, prague-days]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-09 TimeReport aggregate, TimeReportReader, EntryAmounts, ReportFilters (split) and the report page view"
  - phase: 08-exchange-rates-and-reports
    provides: "08-05 RateBook and the CNB per-entry CZK amount"
provides:
  - "Rozpad (Bez rozpadu, Po dnech, Podle uživatelů): Prague-day rows in date order or user rows in Czech order below each task, same single pass"
  - "Accessible Alpine tree: toggle buttons with aria-expanded, aria-controls and Rozbalit/Sbalit names, hidden attribute on collapsed rows, Rozbalit vše and Sbalit vše"
  - "Fixed-price markers (Pevná cena badge, dash with tooltip, * on the summary amount lines, footnote) and missing-rate markers (Chybí kurz badge with currency and day, count footnote, never a zero)"
  - "Leaf row cap from kokpit.report.max_rendered_rows with the warning callout; capped, renderedLeafCount and leafCap on TimeReport"
  - "Fix: a report over clients of different currencies builds (root bucket no longer sums client-currency amounts)"
affects: [08-15 dashboard, 08-16 exports, 08-18 effective rate block, 08-21 XLSX and PDF menu, time report layout gap]

actuals:
  tokens: 17700
  tasks: 3
  commits: 3

plan_head_before: a8d18b0c9f1bf50bd5123f312293891fcbd4ea37
plan_head_after: e2b61cc69f11bc0ff56db298b4f3a5abf973916e
commits: 3

tech-stack:
  added: []
  patterns:
    - "Tree expand state in one Alpine x-data object (open map plus expandAll/collapseAll); every row is in the HTML and a row is hidden while any row above it is closed (x-bind:hidden with the server-rendered hidden attribute as the initial state)"
    - "Accessible names carried as data-expand/data-collapse attributes so the Czech text stays readable in the HTML and Alpine only switches between them"
    - "Leaf cap applied while the tree is built from the buckets, so totals come from every entry and only the drawn rows are cut"

key-files:
  created: []
  modified:
    - app/Domain/Reports/TimeReport.php
    - app/Domain/Reports/ReportBucket.php
    - app/Domain/Reports/ReportEntry.php
    - app/Domain/Reports/Queries/TimeReportReader.php
    - resources/views/filament/pages/time-report.blade.php
    - tests/Feature/Reports/TimeReportTest.php

key-decisions:
  - "ReportEntry gained a trailing optional projectBillingType (read from the existing project_billing join) so a fixed-price project row can carry the Pevná cena badge even when one of its tasks is hourly; the constructor stays compatible"
  - "A node carries czk_missing_day (the earliest day without a CNB rate in the row) so the missing-rate tooltip can name the day; an aggregate row names its earliest missing day"
  - "The summary gained czk_missing_by_state so the Chybí kurz badge shows only on the Nevyfakturováno or Vyfakturováno line that really lacks rates"
  - "Rows above a cut leaf row are dropped when none of their leaves is drawn; a task keeps its own totals when only some of its day or user rows are cut"
  - "The root report bucket takes sumsAmount false: hours and CZK are summed at the root, a client-currency amount exists from the client row down and per currency in the summary and the footer"

patterns-established:
  - "Tooltips are plain title attributes (the Czech text stays assertable in the HTML)"
  - "Page chrome (recent-entries panel) lists tasks too, so tree assertions read only the tree part of the page text"

requirements-completed: [RE-01, RE-02]

coverage:
  - id: D1
    description: "The Rozpad switch adds Prague-day rows (date order) or user rows (Czech order) below each task; summary and footer do not change across the three splits"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the Rozpad breakdown below each task"
        status: pass
    human_judgment: false
  - id: D2
    description: "The tree has accessible toggles (aria-expanded, aria-controls, Rozbalit/Sbalit names), clients open one level, collapsed rows hidden, expand all and collapse all, fixed labels Bez projektu and Bez úkolu last in their group"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the accessible tree"
        status: pass
    human_judgment: true
    rationale: "The server HTML is asserted, but the Alpine behaviour (click, Enter, Space, focus ring, 375px scroll) was not run in a browser; the plan's human-check is open"
  - id: D3
    description: "Fixed-price hours show a dash with the tooltip, the Pevná cena badge and the footnote with * on the summary lines; a missing CNB rate shows the Chybí kurz badge with currency and day, stays out of the CZK sum, is counted in a footnote and is never shown as zero; a CZK client shows the same amount in both columns"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the fixed-price and missing-rate markers"
        status: pass
    human_judgment: false
  - id: D4
    description: "At most max_rendered_rows leaf rows are drawn with the warning callout while the summary, row totals and footer cover every entry"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the row cap"
        status: pass
    human_judgment: false
  - id: D5
    description: "Entries land on the right Prague day on the 25-hour and 23-hour days, across midnight and while running; several currencies are never added together; the query count is the same for 5 and for 200 entries; the same report built twice is identical"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#Prague days on the calendar edges"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#several currencies in one report"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the cost of a long report"
        status: pass
    human_judgment: false

duration: 13min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 14: Report tree split, markers and cap Summary

**The time report tree now nests Prague-day or user rows below each task, has accessible Alpine toggles, shows fixed-price and missing-CNB-rate markers with footnotes, cuts at a configurable leaf cap, and builds for clients of different currencies (the 08-16 bug).**

## Performance

- **Duration:** 13 min
- **Started:** 2026-10-14T22:23:38Z
- **Completed:** 2026-10-14T22:36:10Z
- **Tasks:** 3
- **Files modified:** 6 (plus the phase deferred-items note)

## Accomplishments

- "Rozpad" group in the filter strip (links that keep every other filter, `aria-current`) and day or user child rows at indent 4.5rem, aggregated in the same single pass as the rest, so the summary and footer are identical across the three splits.
- Accessible tree: a `<button type="button">` per expandable row with `aria-expanded`, `aria-controls` (the ids of the rows it controls), the names "Rozbalit :name" / "Sbalit :name", a 24px-high hit area, native Enter and Space; clients start open one level, deeper rows carry `hidden`; "Rozbalit vše" and "Sbalit vše" in the section header.
- Markers and footnotes: "Bez projektu" and "Bez úkolu" (gray, last), `Pevná cena` badge on fixed-price project rows, `—` with the tooltip on fixed hours, `*` on the summary amount lines, "Chybí kurz" badge naming the currency and the day, CZK sums that leave rate-less entries out and count them, never "0,00 Kč".
- Row cap: `TimeReport` exposes `capped`, `renderedLeafCount` and `leafCap`; the tree is cut at `kokpit.report.max_rendered_rows` (never below 1) and the page shows the warning callout.
- Fixed the plan 08-09 multi-currency defect from `deferred-items.md`: a report without a client filter over EUR, USD and CZK clients renders, with one amount line per currency in the summary and the footer and one CZK sum.
- Constant query count proven for 5 and 200 entries on the page (no split, day, user).

## Task Commits

1. **Task 1: Tracer - Rozpad day and user rows below each task** - `78c818f` (feat)
2. **Task 2: Accessible tree, markers, footnotes and row cap** - `e3a619d` (feat)
3. **Task 3: Prague-day edges, several currencies, constant query count** - `e2b61cc` (fix)

**Plan metadata:** the docs commit that carries this SUMMARY, STATE.md, ROADMAP.md and the deferred-items note.

_Tests were written first in each task and seen failing on the planned assertions before the code was added; the plan is `type: execute`, so each task is one commit (tests and code together), not a RED/GREEN pair._

## Files Created/Modified

- `app/Domain/Reports/TimeReport.php` - day and user children per task, leaf cap, missing-rate counts per state, project fixed-price flag, root bucket without client-currency sum
- `app/Domain/Reports/ReportBucket.php` - `czkMissingDay`, `fixedPrice`, `sumsAmount`
- `app/Domain/Reports/ReportEntry.php` - trailing optional `projectBillingType`
- `app/Domain/Reports/Queries/TimeReportReader.php` - selects `pb.billing_type` (existing join) as the project billing type
- `resources/views/filament/pages/time-report.blade.php` - Rozpad group, Alpine tree, badges, tooltips, footnotes, cap callout
- `tests/Feature/Reports/TimeReportTest.php` - 26 new tests

## Decisions Made

See `key-decisions` above. The plan's own recorded decisions (client-side Alpine state, leaf cap counting rule, Czech order of users from the database collation) were followed as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Root bucket summed client-currency amounts across currencies**
- **Found during:** Task 3 (the EUR + USD + CZK test; the defect was logged earlier in `deferred-items.md` by plan 08-16)
- **Issue:** `ReportBucket::add()` on the root added the per-entry amount with `Money::plus()`, which throws "Money in different currencies cannot be combined." as soon as a filtered set held clients of two currencies; the page failed for "Všichni klienti".
- **Fix:** the root bucket is built with `sumsAmount: false` (hours and CZK still sum there); per-currency amounts come from the client buckets and the per-currency summary arrays, which already existed.
- **Files modified:** `app/Domain/Reports/ReportBucket.php`, `app/Domain/Reports/TimeReport.php`
- **Verification:** `TimeReportTest` "several currencies in one report" (two tests) and the constant-query-count test, which renders two currencies on the page.
- **Committed in:** `e2b61cc`

**2. [Rule 2 - Missing critical] A fixed-price project row needs the project's own billing type**
- **Found during:** Task 2
- **Issue:** the node flag `fixed` means "has fixed-price hours", which is also true for an hourly project with one fixed-price task; the `Pevná cena` badge belongs only to fixed-price projects.
- **Fix:** `ReportEntry` got a trailing optional `projectBillingType` read from the already joined `project_billing` row; the project bucket carries `fixedPrice`. Constructor stays compatible, no new query.
- **Files modified:** `ReportEntry.php`, `TimeReportReader.php`, `ReportBucket.php`, `TimeReport.php`
- **Verification:** the fixed-price test asserts exactly one badge with an hourly project holding a fixed-price task.
- **Committed in:** `e3a619d`

---

**Total deviations:** 2 auto-fixed (1 bug, 1 missing critical)
**Impact on plan:** both were needed for the stated acceptance cases (the EUR + USD case; the badge only on fixed-price projects). `EntryAmounts.php` needed no change: CZK clients already priced correctly. The DST, midnight and running-entry tests passed against the existing reader, so the range predicate and Prague work-day expression needed no fix.

## Issues Encountered

- The page chrome (the recent-entries panel) also lists task references, so the row-cap test reads only the tree part of the page text.
- A DDEV file-sync lag made one test run use the pre-edit file; a re-run showed the real result.

## Known Stubs

None.

## Open Human Verification

The plan's task 3 human-check was not run (no browser session here): open `/admin/time-report` at 375px with a client with long names; the tree must scroll inside its section, the page must not scroll sideways, long names wrap, and every toggle works with Tab, Enter and Space with a visible focus ring. Recorded in `.planning/WINDOWS.md` as `unrun-verify` and marked `human_judgment: true` for D2. The owner's pending todo `2026-10-14-redesign-unreadable-time-report-layout.md` still stands: the layout was not redesigned here (the toggle and spacer take 1.5rem of the name column, names still wrap with `overflow-wrap: anywhere`).

## Threat Flags

None. No new endpoint, auth path or trust boundary; the added markup renders stored names through escaped Blade output (T-08-46 to T-08-48 mitigations are covered by the new tests).

## Next Phase Readiness

- Plans 08-15 and 08-16 consume `TimeReport`, `ReportEntry` and `EntryAmounts` unchanged apart from additive fields.
- The time report layout gap (todo) is the open item for a gap plan.

## Self-Check: PASSED

- Files exist: `app/Domain/Reports/TimeReport.php`, `ReportBucket.php`, `ReportEntry.php`, `Queries/TimeReportReader.php`, `resources/views/filament/pages/time-report.blade.php`, `tests/Feature/Reports/TimeReportTest.php`
- Commits exist on HEAD: `78c818f`, `e3a619d`, `e2b61cc`
- Acceptance criteria re-run: all greps pass; `ddev exec vendor/bin/pest` 3757 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on each commit

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
