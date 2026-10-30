---
phase: 08-exchange-rates-and-reports
plan: 16
subsystem: reports
tags: [openspout, csv-export, queued-job, notifications, filament-actions, formula-injection, private-disk]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-07 ReportExport record (markReady, markFailed, storagePath, ttlDays) and ReportExportFormat"
  - phase: 08-exchange-rates-and-reports
    provides: "08-09 TimeReportReader::entries(), ReportFilters (toArray, fromStored), EntryAmounts::of(), RateBook::load(), TimeReportPage"
  - phase: 08-exchange-rates-and-reports
    provides: "08-11 ReportExportResource list with the checked download action; 08-12 the 7-day prune"
provides:
  - "App\\Domain\\Reports\\Jobs\\BuildReportExport: queued KokpitJob that streams the filtered entries into a UTF-8 BOM, semicolon CSV on the private disk, then marks the record ready and notifies the owner in one transaction; failed() marks it failed and sends the failed bell inside an explicit system run"
  - "App\\Domain\\Reports\\Exports\\SpreadsheetCells: text() (apostrophe prefix for a leading =, +, -, @, tab or CR, also after spaces, always an explicit StringCell), decimal() (configured decimal sign), integer()"
  - "ReportExportReadyNotification and ReportExportFailedNotification: database-only, not queued, format and period only, values escaped once"
  - "TimeReportPage header: gray ActionGroup 'Exportovat report' with the exportCsv item, and the gray 'Exporty' link to the exports list"
  - "openspout/openspout ^4.32 as an explicit requirement; BuildReportExport added to the expected job list on purpose"
affects: [08-21 XLSX and PDF export items (reuse entryCells, SpreadsheetCells and the menu group), 08-14 time report view and aggregate, 08-22 Phase 8 isolation sweep]

actuals:
  tokens: 14400
  tasks: 3
  commits: 3

plan_head_before: b74922a30dc7269b740ed3b3d168dc84ccc7e301
plan_head_after: a4c5a60a558129031462bf03f2e056b471fa3d97
commits: 3

tech-stack:
  added: ["openspout/openspout ^4.32 (already locked through filament/actions, MIT)"]
  patterns:
    - "Streaming export: lazy reader -> OpenSpout writer on a temp file -> Storage::put(stream) under the server-generated path"
    - "Never let a library infer a cell type from user text: every text through SpreadsheetCells::text(), a canary test proves the inferring factory would fail"
    - "Numbers as text from exact values: hours by integer arithmetic (half up, four decimals), amounts from Money::toMajor() with the decimal sign swapped"
    - "Job state change and bell notice in one transaction (notifyNow), failed() wraps its own runAsSystem because the job middleware does not cover it"

key-files:
  created:
    - app/Domain/Reports/Jobs/BuildReportExport.php
    - app/Domain/Reports/Exports/SpreadsheetCells.php
    - app/Domain/Reports/Notifications/ReportExportReadyNotification.php
    - app/Domain/Reports/Notifications/ReportExportFailedNotification.php
    - tests/Feature/Reports/ReportExportTest.php
    - tests/Unit/Reports/SpreadsheetCellsTest.php
    - .planning/phases/08-exchange-rates-and-reports/deferred-items.md
  modified:
    - app/Filament/Pages/TimeReportPage.php
    - composer.json
    - composer.lock
    - tests/Arch/JobContractTest.php

key-decisions:
  - "The Úkol column carries the task title only (no KEY-N reference), so a title that starts with a formula character is neutralised by the apostrophe rule instead of being hidden behind a prefix"
  - "The failed bell period falls back to the creation day when the stored days are damaged, so a record with broken filters can still be reported"
  - "The failing-build test makes the stored filters refuse (a client that no longer exists) because TimeReportReader is final and cannot be faked; the path through failed() is the same for any exception"

patterns-established:
  - "Export bell notices name format and period only; escape once with e() in the notification, not in the job"
  - "Unit tests that read config use uses(TestCase::class) in the file (plain unit tests have no application)"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "Tabulka CSV in the Exportovat report group records the export for the current filters, shows the info toast, a queued job builds the file and the gray Exporty link opens the list"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it queues the export, builds the file in the background, notifies the Admin and offers the download"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it offers the export menu and the \"Exporty\" link on the report page"
        status: pass
    human_judgment: false
  - id: D2
    description: "The CSV is UTF-8 with BOM, semicolon-delimited, Czech header row, one row per entry, decimal commas, empty amount cells for fixed-price, non-billable and rate-less entries, header-only for an empty period; its sums equal the report's"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#the file follows the report"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every text cell is spreadsheet-safe and never inferred as a formula, with a self-check that the canary catches the inferring factory"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Unit/Reports/SpreadsheetCellsTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#it writes a client, project, task and user name that start like formulas with a leading apostrophe"
        status: pass
    human_judgment: false
  - id: D4
    description: "Ready and failed bell notices carry format and period only, escaped once; a failing build marks the record failed; a rerun of a ready export changes nothing"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#the bell notices"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#a failing export"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportTest.php#the build job"
        status: pass
    human_judgment: false
  - id: D5
    description: "The bell renders the export notices with stock Filament styling, long period texts included, and the CSV opens correctly in a Czech spreadsheet"
    requirement: RE-03
    verification: []
    human_judgment: true
    rationale: "Bell rendering and spreadsheet behaviour are visual and application-specific; the plan's human check was not run (recorded in .planning/WINDOWS.md)"

duration: 20min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 16: CSV export of the time report Summary

**Queued, streamed, formula-safe CSV export of the time report: the "Exportovat report" menu on the report page creates the record, a `KokpitJob` writes a UTF-8 BOM, semicolon CSV with Czech headers and decimal commas to the private disk using OpenSpout, and a database-only bell tells the Admin where to download it**

## Performance

- **Duration:** about 20 min
- **Started:** 2026-10-14T21:35:00Z (approximate)
- **Completed:** 2026-10-14T21:57:00Z
- **Tasks:** 3 (one tracer, two TDD tasks)
- **Files modified:** 11 (7 created, 4 modified)

## Accomplishments

- Tracer: "Tabulka CSV" on "Report času" goes end to end (record, queued job, file on the private disk, ready bell with the "Otevřít exporty" action, download through the plan 08-11 action). The tracer's `<verify>` was re-run before expansion and passed.
- `SpreadsheetCells` closes the OpenSpout formula inference hole (research Pitfall 1): apostrophe prefix for a leading `=`, `+`, `-`, `@`, tab or CR (also after leading spaces) and always an explicit string cell. A mutation check (disabling the prefix) fails the unit tests, and a canary test proves OpenSpout's own factory turns `=...` into a formula cell.
- The file follows the report: the seconds column sums to the report's worked seconds and the CZK column to its billable CZK total; fixed-price, non-billable and rate-less entries have empty cells, 61 seconds read `0,0169`, a running entry counts at the job clock, an empty period gives a header-only ready file, a rerun of a ready export changes nothing.
- A failing build marks the record failed, sends "Export se nepodařil" (period only) and still raises the Phase 3 `JobFailed` alert path. Neither bell names a client, an amount or a path; markup is escaped exactly once.

## Task Commits

1. **Task 1: Tracer - Tabulka CSV end to end** - `7c12fd0` (feat)
2. **Task 2: Formula-safe text cells with the canary self-check** - `38b3ad5` (test)
3. **Task 3: The export follows the report, failure is visible, bell stays clean** - `a4c5a60` (test)

**Plan metadata:** recorded in the docs commit that follows this summary.

_Note: the implementation of Tasks 2 and 3 (`SpreadsheetCells`, the failed notification, `failed()`, hour formatting, idempotence) was written with the tracer because the plan's artifact table specified it there; the TDD tasks therefore added their tests against existing behaviour (no GREEN-after-RED gap). The Task 2 RED evidence is a mutation check: with the apostrophe branch disabled the target tests fail on the planned assertions._

## Files Created/Modified

- `app/Domain/Reports/Jobs/BuildReportExport.php` - queued CSV build, `COLUMNS`, private `entryCells()` for the XLSX path of plan 08-21, `failed()` with its own system run
- `app/Domain/Reports/Exports/SpreadsheetCells.php` - formula-safe text, decimal and integer cells
- `app/Domain/Reports/Notifications/ReportExportReadyNotification.php` / `ReportExportFailedNotification.php` - the two database-only bell notices
- `app/Filament/Pages/TimeReportPage.php` - export `ActionGroup` with `exportCsv`, the "Exporty" link, `queueExport()` and the server-built "Zadání" summary
- `composer.json`, `composer.lock` - `openspout/openspout ^4.32` explicit (content hash only in the lock)
- `tests/Arch/JobContractTest.php` - `BuildReportExport` added to the expected job list
- `tests/Feature/Reports/ReportExportTest.php`, `tests/Unit/Reports/SpreadsheetCellsTest.php` - 21 + 3 new feature cases and 21 unit cases
- `.planning/phases/08-exchange-rates-and-reports/deferred-items.md` - the out-of-scope finding below

## Decisions Made

- The Úkol column holds the task title only, so a hostile title is neutralised by the apostrophe rule.
- The failed bell falls back to the creation day when stored days are not valid.
- The failure test drives `failed()` through the sync queue with stored filters that no longer validate, because `TimeReportReader` is final.
- Numbers are text built from exact values; no float is involved anywhere in the file.

## Deviations from Plan

None - plan executed exactly as written.

The one extra edit is a comment line in `tests/Arch/JobContractTest.php` naming plan 08-16 next to the new expected job.

## Issues Encountered

- **Pre-existing bug found, deferred (not fixed here):** `TimeReport::build()` throws `Money in different currencies cannot be combined.` when the filtered set holds priced entries of clients in different currencies (for example EUR and CZK) and no client is chosen. `TimeReport.php` line 75 adds every entry's amount to the root bucket with `Money::plus()`. The page "Report času" therefore fails for "Všichni klienti" in that situation. It belongs to the plan 08-09 aggregate; plan 08-14 lists the EUR + USD case as an acceptance case and is the right place to fix it (details in `deferred-items.md`). The 08-16 tests that compare the export with the report use one currency per comparison for that reason; the CSV job itself prices each entry on its own and is not affected.
- The plan's `<human-check>` (run an export in DDEV, open the bell, open the file in a Czech spreadsheet) was not run; it is recorded as an open `unrun-verify` entry in `.planning/WINDOWS.md`. The M17 long-text statement of the bell is therefore a backstop that still needs a human.

## Known Stubs

None.

## Threat Flags

None. The new surface (export action, job, private file, bell) is exactly the one the plan's threat model covers (T-08-52 to T-08-57); each mitigation is tested.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 08-21 can add "Tabulka XLSX" and "Pracovní výkaz PDF" to the existing `ActionGroup`, reuse `BuildReportExport::entryCells()` and `SpreadsheetCells`, and switch on `ReportExport::$format`.
- Plan 08-14 should fix the mixed-currency root bucket before it adds the per-currency summary and footer lines (see Issues Encountered).

## Self-Check: PASSED

- Created files exist on disk (job, cells, both notifications, both test files, deferred-items).
- Commits `7c12fd0`, `38b3ad5`, `a4c5a60` are ancestors of HEAD; the evaluation-scope check resolves the plan.
- Task acceptance criteria re-run: composer requirement present, `#[Idempotent(`, `#[Timeout(240)]`, `SpreadsheetCells::text`, `BuildReportExport::dispatch`, `ActionGroup`, `BuildReportExport::class` in the arch test, `function failed` and `runAsSystem`, `ReportExportStatus::Failed` and `0,0169` in the tests all found; `fromValues` absent from `app/Domain/Reports`.
- Plan verification: full suite `ddev exec vendor/bin/pest` green (3710 passed, 3 skipped, none from this plan); `ddev composer check-licenses` clean (225 packages); Pint clean; PHPStan no errors; `scripts/check-sensitive.sh` clean on each commit.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
