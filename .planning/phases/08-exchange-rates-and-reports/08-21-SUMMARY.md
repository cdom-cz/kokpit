---
phase: 08-exchange-rates-and-reports
plan: 21
subsystem: reports
tags: [openspout, xlsx, export, formula-injection, work-report-pdf, dompdf, filament-actions, livewire-download]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-16 BuildReportExport (CSV path, entry reader, bells), SpreadsheetCells, the export ActionGroup with exportCsv and the Exporty link"
  - phase: 08-exchange-rates-and-reports
    provides: "08-06 WorkReportSource::forPeriod() and WorkReportPdf::render(); 08-07 ReportExportFormat::Xlsx and the record; 08-09 ReportFilters and TimeReport summary"
provides:
  - "BuildReportExport XLSX path: sheet Záznamy (date and numeric cells, hours 0.0000, amounts #,##0.00, bold frozen header, auto filter, column widths) and sheet Souhrn accumulated in the same single pass over the entries"
  - "SpreadsheetCells::number(): a numeric cell from a plain decimal string only (text such as =1+1 is refused), negative numbers stay numeric"
  - "Missing CNB rate: empty CZK cell carrying the comment Chybí kurz ČNB, both on the entry row and on the summary CZK total; Poznámka row with the fixed-price and missing-rate notes only where they apply"
  - "TimeReportPage items exportXlsx (Tabulka XLSX) and workReport (Pracovní výkaz PDF, rendered only with a client, streamDownload with a generated file name, danger toast on failure)"
affects: [08-22 Phase 8 isolation sweep, 09 and 10 work report storing and mailing, 08-14 time report view]

actuals:
  tokens: 11200
  tasks: 3
  commits: 5

plan_head_before: 819ecc9dfb1c5016567a1020acc053f5f185ddc6
plan_head_after: f9fb8aae4bc99feac7c67def1ff34c41e2c043e9
commits: 5

tech-stack:
  added: []
  patterns:
    - "XLSX numbers are built from exact strings only at the cell (SpreadsheetCells::number); the CSV keeps exact strings"
    - "Summary accumulated while the entries stream: the reader is read once, the summary is the sum of the very amounts written on the rows"
    - "Download action builds the whole PDF before streaming, so a failure leaves no partial file; the renderer is resolved from the container so a test can swap it"

key-files:
  created:
    - tests/Feature/Reports/WorkReportDownloadTest.php
  modified:
    - app/Domain/Reports/Jobs/BuildReportExport.php
    - app/Domain/Reports/Exports/SpreadsheetCells.php
    - app/Filament/Pages/TimeReportPage.php
    - tests/Feature/Reports/ReportExportTest.php
    - tests/Unit/Reports/SpreadsheetCellsTest.php

key-decisions:
  - "The XLSX summary lists billable amounts per currency (billed plus unbilled), the same figure as the Částka column of the report tree, one row per currency with the label repeated; with no amount at all one row with 0.00 and no currency"
  - "Summary hours are numeric hours rounded half up to four decimals from the summed seconds (same helper as the entry rows), not a sum of rounded entry hours"
  - "The summary CZK total with entries lacking a CNB rate is a lower bound with the comment; it is an empty commented cell when no entry could be converted, never a zero"
  - "The two Poznámka notes share one row, joined by a space; the row is absent when neither applies"
  - "A text cell with no value (no project, no task, no currency) is a really empty cell in the workbook, not an empty string"

patterns-established:
  - "Report page download items: abort_unless(canAccess) again on the action, validated ReportFilters, catch Throwable, report(), fixed toast, return null"
  - "Tests swap the final renderer through app()->instance() with a duck-typed recording double"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "Tabulka XLSX in the export group goes through the same queue, bell and download path as the CSV and delivers sheet Záznamy with the CSV columns as numeric and date cells, a bold frozen header, an auto filter and column widths"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#the XLSX export of the report page"
        status: pass
    human_judgment: false
  - id: D2
    description: "Sheet Souhrn holds Klient, Projekt, Období, the hour lines, one amount row per currency and Částka v Kč, equal to the report summary for the same filters"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it summarises the same filters as the report: hours, amounts per currency and the CZK total"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it lists one amount row per currency"
        status: pass
    human_judgment: false
  - id: D3
    description: "No cell of either sheet is a formula: user text is an inline string with the apostrophe prefix, numbers are numeric cells, and a self-check proves the detection can fail"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#spreadsheet-safe cells in the workbook"
        status: pass
      - kind: unit
        ref: "tests/Unit/Reports/SpreadsheetCellsTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "A missing CNB rate leaves Částka v Kč empty with the comment Chybí kurz ČNB, and the Poznámka row carries the fixed-price and missing-rate notes only where they apply"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it leaves the CZK cell empty and attaches the comment \"Chybí kurz ČNB\" when the rate is missing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it adds a Poznámka row with the fixed-price and the missing-rate notes only where they apply"
        status: pass
    human_judgment: false
  - id: D5
    description: "Pracovní výkaz PDF item is rendered only with a client, downloads the work report of the client, project and period in the client invoice language under a generated file name, and a failure shows the danger toast without exception text and without a file"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportDownloadTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "The workbook opens correctly in a spreadsheet application: frozen header pane, auto filter, number formats, comments on the CZK cells"
    requirement: RE-03
    verification: []
    human_judgment: true
    rationale: "Behaviour in Excel or LibreOffice is application-specific; the package was verified by reading it back with OpenSpout and by unzipping the XML, not by opening it in an application (recorded in .planning/WINDOWS.md)"

duration: 35min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 21: XLSX export and work report PDF download Summary

**The "Exportovat report" menu is complete: a formula-free XLSX workbook (entries as real numeric and date cells plus a Souhrn sheet that equals the report summary, with the missing-rate comment and notes row) and the "Pracovní výkaz PDF" download of the chosen client's work report, shown only with a client and failing with a fixed toast**

## Performance

- **Duration:** about 35 min (start time not recorded at the beginning of the run; estimated from the commit times)
- **Completed:** 2026-10-15T01:35:00+02:00
- **Tasks:** 3 (one tracer, two TDD tasks)
- **Files modified:** 6 (1 created, 5 modified)

## Accomplishments

- Tracer: "Tabulka XLSX" goes end to end through the CSV's queue, bell and download path. The workbook is read back in the tests with the OpenSpout XLSX reader: sheet "Záznamy" with the Czech header and one row per entry (seconds numeric, date a real date cell), sheet "Souhrn" whose Odpracováno, Nevyfakturováno, per-currency amount rows and Částka v Kč equal `TimeReport::build(...)->summary` for the same filters. The tracer `<verify>` was re-run before expansion and passed.
- Styling is asserted on the package XML: frozen first row (`ySplit="1"`), `<autoFilter ref="A1:L4"/>` (and `A1:L1` for an empty period), a `<cols>` block, a bold font, formats `0.0000` and `d.m.yyyy`, built-in format 4 for `#,##0.00`.
- Formula safety: both sheets are scanned for an `<f>` element with a canary client, project, task (also a tab-led one) and user name; every canary is an inline string with the apostrophe prefix, also in the Souhrn Klient and Projekt rows. A self-check writes `=1+1` through OpenSpout's value-inferring factory and proves the scan detects the formula. `SpreadsheetCells::number()` accepts a plain decimal string only, so text can never become a numeric cell.
- An entry without a CNB rate has an empty CZK cell with the comment "Chybí kurz ČNB" (row L2 yes, the priced row L3 no). The Souhrn CZK total is an empty commented cell when nothing could be converted and a commented lower bound otherwise; the Poznámka row carries the fixed-price and missing-rate notes only where they apply.
- "Pracovní výkaz PDF": hidden (not disabled) without a client, present with one; downloads `pracovni-vykaz-<from>_<to>.pdf` (`work-report-...` for an English client) with real `%PDF-` bytes, limited to the chosen project; a throwing renderer shows the danger toast with the fixed body, no exception text and no download.

## Task Commits

1. **Task 1: Tracer - Tabulka XLSX with the entries and a summary sheet** - `c242406` (feat)
2. **Task 2: No formula, numeric cells, missing-rate comment and notes** - `4fcbfa6` (test, RED) and `3660c1d` (feat, GREEN)
3. **Task 3: The work report PDF item** - `0717453` (test, RED) and `f9fb8aa` (feat, GREEN)

The measured range from the plan base also contains three unrelated owner commits that landed in parallel (`ce3f756`, `3ff314f`, `2275a2f`, todo captures); `commits: 5` counts the plan's own commits.

**Plan metadata:** the docs commit that follows this file (docs: complete plan).

## TDD notes

- Task 2 RED (`4fcbfa6`): two tests failed on their intended assertions (the comment part had no `ref="L2"`, the Poznámka key was missing). The formula and numeric-cell tests passed at once because the Task 1 implementation is formula-free by construction; they are guard tests, and their ability to fail is proven by the self-check test.
- Task 3 RED (`0717453`): seven tests failed because the action did not exist; the Partner test already passed because the page itself refuses a Partner (08-09), so it is a regression guard.

## Files Created/Modified

- `app/Domain/Reports/Jobs/BuildReportExport.php` - format switch; `writeXlsx()`, `writeSummarySheet()`, entry cells, styles, missing-rate comment
- `app/Domain/Reports/Exports/SpreadsheetCells.php` - `number()` numeric cell from a plain decimal string
- `app/Filament/Pages/TimeReportPage.php` - items `exportXlsx` and `workReport`, `downloadWorkReport()`, generated file name
- `tests/Feature/Reports/ReportExportTest.php` - XLSX tests, workbook readers, formula scan, comment and notes tests
- `tests/Feature/Reports/WorkReportDownloadTest.php` - the PDF item tests
- `tests/Unit/Reports/SpreadsheetCellsTest.php` - `number()` tests

## Decisions Made

See `key-decisions` in the frontmatter. The plan's recorded decisions (summary accumulated in one pass, numeric cells from exact strings converted only at the cell, `d.m.yyyy` date style, server-built PDF file name with a fixed prefix per language) were followed as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added `SpreadsheetCells::number()` and its unit tests**
- **Found during:** Task 1 (XLSX entry cells)
- **Issue:** the plan names `SpreadsheetCells` as the safe cell source but lists only `integer()`, `decimal()` (text) and `text()`. XLSX numbers need numeric cells, and building them inline from arbitrary strings would reopen the inference hole of research Pitfall 1 and make "a negative number stays numeric" untestable at the cell level.
- **Fix:** `number(string $exact, ?Style $style)` accepts only `-?digits(.digits)?`, refuses anything else with an exception, and returns a `NumericCell`.
- **Files modified:** `app/Domain/Reports/Exports/SpreadsheetCells.php` (not in the plan's `files_modified`), `tests/Unit/Reports/SpreadsheetCellsTest.php`
- **Verification:** unit tests for whole, two-decimal, four-decimal and negative values and for formula, text, decimal-comma, empty, exponent and trailing-newline refusals
- **Committed in:** `c242406`

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** one extra file touched, small and inside the same safety contract; no scope creep.

## Issues Encountered

- The first run of the summary test compared `14400 / 3600` (an int in PHP) with a float read back from the sheet; the test now divides by `3600.0`. A test fix, not a code problem.
- The renderer `WorkReportPdf` is final, so the failure and the English-language tests swap it through `app()->instance()` with a duck-typed recording double; the Czech download test uses the real renderer and checks the `%PDF-` bytes.

## Known Stubs

None.

## Threat Flags

None: the plan's threat model covers the new surface (workbook cells, the PDF action on the Admin-only page). T-08-67 is mitigated (formula scan of both sheets with a self-check, `SpreadsheetCells::text()` for every text, no `fromValues` in `app/Domain/Reports`), T-08-68 is mitigated (action asks `canAccess()` again, uses validated `ReportFilters`, `WorkReportSource` refuses non-Admin, fixed toast copy). No `composer.json` or `composer.lock` change (T-08-SC).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- The export menu is complete (CSV, XLSX, PDF). Phases 9 and 10 can store and mail the work report through the same `WorkReportPdf::render()`.
- Open item recorded in `.planning/WINDOWS.md`: the workbook was verified from its XML and through the OpenSpout reader, not in a spreadsheet application (frozen pane, comments, number formats). Part of the owner's phase-8 UAT.
- The known risk that the Livewire download holds the PDF in memory (research Pitfall 10) is accepted for freelancer volumes, as the plan states.

## Self-Check: PASSED

- Files exist: `app/Domain/Reports/Jobs/BuildReportExport.php`, `app/Filament/Pages/TimeReportPage.php`, `tests/Feature/Reports/ReportExportTest.php`, `tests/Feature/Reports/WorkReportDownloadTest.php`
- Commits found in HEAD: `c242406`, `4fcbfa6`, `3660c1d`, `0717453`, `f9fb8aa`
- Acceptance criteria of all three tasks re-run: PASS (`ddev exec vendor/bin/pest tests/Feature/Reports`: 298 passed, 2 skipped; full suite 3857 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set)

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-15*
