---
phase: 08-exchange-rates-and-reports
plan: 06
subsystem: reports
tags: [dompdf, pdf, work-report, czech, english, page-script, entry-list-source]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-03: WorkReportSource::forPeriod, WorkReportPdf render/html on a hardened Dompdf, the first-page template, lang/cs/work_report.php"
provides:
  - "Two-section work report: Fakturovatelne first, then Nefakturovatelne, each with its own table, Celkem za den subtotals, project rows and total; an empty section is omitted"
  - "Souhrn block kept together: days worked, total hours (bold), per-section totals, hours per project when there are several"
  - "Running header from page 2 and the footer (Strana n / total right, Vytvoreno date left) drawn by WorkReportPageDecoration, a Dompdf page script"
  - "lang/en/work_report.php, the English document with Y-m-d dates"
  - "WorkReportSource::fromEntryIds(): the same document from an explicit list of one client's time entries (Phase 10 attachment)"
  - "WorkReportSource section helpers: section, sectionSeconds, totalSeconds, daysWorked, projectTotals"
affects: [08-21 report page PDF download, 10 invoice work-report attachment]

commits: 5
plan_head_before: 969e3c0d56cbb957f410076de5e16801b90c51d5
plan_head_after: 467901b24bd458b95d0c4c6fa662a17d17bac45d

actuals:
  tokens: 13200
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Page decorations are an application callable handed to Dompdf page_script (isPhpEnabled stays off); the class only places pre-translated texts, so it is tested against a recording Canvas without any PDF text extraction"
    - "One query builder behind two public factories: forPeriod and fromEntryIds pass a constraint closure to the same grouped query and the same Admin-or-system gate"
    - "A runtime-limit rule is a pure function (WorkReportPdf::raisedMemoryLimit) so it is tested without lowering the process memory limit"

key-files:
  created:
    - app/Domain/Reports/WorkReport/WorkReportPageDecoration.php
    - lang/en/work_report.php
  modified:
    - app/Domain/Reports/WorkReport/WorkReportSource.php
    - app/Domain/Reports/WorkReport/WorkReportPdf.php
    - resources/views/pdf/work-report.blade.php
    - lang/cs/work_report.php
    - tests/Feature/Reports/WorkReportPdfTest.php

key-decisions:
  - "The running header and footer live in a small final readonly class WorkReportPageDecoration, not in a closure inside WorkReportPdf, so the placement rules are unit-tested against a recording Dompdf Canvas (pdftotext is not installed in DDEV)"
  - "A project row precedes a project's days only when the whole report holds several projects (per-report, not per-section), so both sections read the same way"
  - "The per-project summary table is ordered by hours, largest first, entries without a project last (no PHP-side Czech collation is available: intl is not a declared dependency)"
  - "render() takes an optional generation instant; the footer date is its Prague day in the client's date format"
  - "fromEntryIds counts a repeated id once, refuses unknown ids and entries of more than one client, and names the project in the header only when all entries share one"

patterns-established:
  - "Pattern: Dompdf text() takes the TOP of the text as y, not the baseline; a rule drawn under a header must clear the text height"
  - "Pattern: per-language label resolution happens before the canvas callback runs, with placeholders {n} and {total} filled in by the callback"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "The work report shows the billable and the non-billable section, each with its own table, day subtotals, project rows and total, the Souhrn block and the empty-period document"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the two sections"
        status: pass
      - kind: unit
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the section helpers of the source"
        status: pass
    human_judgment: false
  - id: D2
    description: "Pages after the first carry the running header with a 1pt rule, every page carries Strana n / total and the generation date, long titles wrap inside the Ukol cell, the table header repeats"
    requirement: "RE-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the page decoration"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#many pages"
        status: pass
      - kind: other
        ref: "pdftotext, pdfinfo and pdftoppm on rendered samples of 6 and 8 pages (host binary; the two pdftotext tests skip inside DDEV)"
        status: pass
    human_judgment: true
    rationale: "The look of a multi-page document (running header position, spacing, grayscale) is a visual judgement; samples were inspected as images and as extracted text, no automated visual assertion exists"
  - id: D3
    description: "A client with invoice language en gets the English document with Y-m-d dates; cs gets Czech with j. n. Y dates; durations are H:MM in both; both language files have the same keys"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the English document"
        status: pass
    human_judgment: false
  - id: D4
    description: "No rate, price, amount, currency, exchange rate or entry description reaches the document even when all are stored; markup in names is escaped"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#money, descriptions and markup"
        status: pass
    human_judgment: false
  - id: D5
    description: "WorkReportSource::fromEntryIds builds the same document from the listed entries of one client for Phase 10, and refuses Partners, guests, empty lists, unknown ids and mixed clients"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the entry-list source"
        status: pass
    human_judgment: false

duration: 19min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 06: Complete work report PDF Summary

**Two-section Czech/English work report on Dompdf with day subtotals, a kept-together summary, a running header and "Strana n / total" footer drawn by a canvas page script, and a fromEntryIds source for Phase 10**

## Performance

- **Duration:** 19 min
- **Started:** 2026-10-14T19:46:57Z
- **Completed:** 2026-10-14T20:05:30Z
- **Tasks:** 3
- **Files modified:** 7 (2 created, 5 modified)

## Accomplishments

- The document now has the billable section before the non-billable one, each with its own 12pt heading, its own table of day x task rows, "Celkem za den" subtotals (fill `#f4f4f5`), project rows when the report holds several projects, and its own total row. A fixed-price entry is billable hours in the first section. An empty section is omitted; a period without entries renders the header, one empty row and a `0:00` summary.
- "Souhrn" follows the tables and is kept together (`page-break-inside: avoid`): days worked, total hours in bold, the per-section totals and, with several projects, hours per project.
- Pages after the first carry "Pracovni vykaz · client · from – to" with a 1pt rule; every page carries "Strana n / total" on the right and "Vytvoreno date" on the left at 8pt. `WorkReportPageDecoration` is an application callable handed to the canvas `page_script`, so `isPhpEnabled` stays false. A client name too long for the header is cut with an ellipsis. Long titles wrap (`table-layout: fixed`, `overflow-wrap`); a 300 character title stays inside the right margin (measured: xMax 547.28 of 595.28 - 48).
- `lang/en/work_report.php` has exactly the keys of the Czech file; English dates are `Y-m-d`, Czech `j. n. Y`, durations `H:MM` in both; the company ID line is "Company ID: :id" in English.
- `WorkReportSource::fromEntryIds()` shares the grouped query and the Admin-or-system gate with `forPeriod()`; the period is the first and last Prague day of the entries, the project line appears when all entries share one project.
- No money: with a client rate, a project rate, a fixed task price, a CNB rate and entry descriptions stored (EUR client), the html in both languages contains none of the currency codes, the rate and price strings, the CNB rate or the description canary.

## Task Commits

1. **Task 1: Tracer - two sections, totals, summary** - `4b06aa5` (feat; tracer, tests and code in one commit; the tracer `<verify>` was re-run end-to-end before expansion and passed)
2. **Task 2: Running header, page numbers, generation date, memory guard**
   - `3cc1295` (test) failing tests for the page decoration, many pages, wrapping, memory
   - `1d64ce6` (feat) `WorkReportPageDecoration`, page script wiring, wrapping CSS
3. **Task 3: English, no money, escaping, entry-list source**
   - `b4b2166` (test) failing tests for the English document, money-free output, escaping and `fromEntryIds`
   - `467901b` (feat) `lang/en/work_report.php`, `fromEntryIds`, shared builder, testable memory rule

**Plan metadata:** the docs commit that carries this SUMMARY, STATE.md, ROADMAP.md and REQUIREMENTS.md.

## Files Created/Modified

- `app/Domain/Reports/WorkReport/WorkReportSource.php` - section helpers, `fromEntryIds`, shared `build()` and `authorise()`, the query takes a constraint closure
- `app/Domain/Reports/WorkReport/WorkReportPdf.php` - sections view data, project rows, optional generation instant, page script, `raisedMemoryLimit()`
- `app/Domain/Reports/WorkReport/WorkReportPageDecoration.php` - footer and running header placement
- `resources/views/pdf/work-report.blade.php` - section loop, project/day rows, summary block, wrapping rules
- `lang/cs/work_report.php` - comment now points at the English file
- `lang/en/work_report.php` - the English column of the Copywriting Contract
- `tests/Feature/Reports/WorkReportPdfTest.php` - 47 tests in all (31 new in this plan)

## Decisions Made

See `key-decisions`. The memory guard the plan asks for already existed from 08-03 (`raiseMemoryLimit`); this plan extracted its rule into a pure function and tests it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A class file the plan did not list: `WorkReportPageDecoration`**
- **Found during:** Task 2 (RED)
- **Issue:** the plan's behaviour asks for tests on the running header and "Strana n / total" through `pdftotext`, which is not installed in the DDEV web image (the plan allows skipping). Without another check, the page decoration would have had no executed test in DDEV.
- **Fix:** the placement logic is a small `final readonly` class used as the page script callable; tests drive it against a recording Dompdf `Canvas` (footer on page 1, header and rule from page 2, right alignment, ellipsis cut). The `pdftotext` tests stay (they skip in DDEV).
- **Files modified:** `app/Domain/Reports/WorkReport/WorkReportPageDecoration.php` (new), `WorkReportPdf.php`
- **Committed in:** `3cc1295`, `1d64ce6`

**2. [Rule 1 - Bug] Running header overlapped its own rule**
- **Found during:** Task 2, visual check of a rendered page
- **Issue:** Dompdf places `text()` from the top of the text, not the baseline; header text at y 40 ran into the rule at y 48.
- **Fix:** text top at 30pt, rule at 46pt (both inside the 72pt top margin).
- **Files modified:** `WorkReportPageDecoration.php`
- **Verification:** rendered page 2 inspected as an image
- **Committed in:** `1d64ce6`

**3. [Rule 1 - Bug] "Hodiny" column header was left aligned over right-aligned values**
- **Found during:** Task 2, visual check
- **Fix:** `table.entries th.hours { text-align: right }` (the generic `th` rule won on specificity)
- **Files modified:** `resources/views/pdf/work-report.blade.php`
- **Committed in:** `1d64ce6`

**4. [Rule 1 - Bug] The memory test failed in the full suite**
- **Found during:** Task 3, full-suite run
- **Issue:** lowering `memory_limit` to 128M emits a warning (turned into an exception) when the process already uses more than that; true for the full suite, not for the single file.
- **Fix:** the rule is the pure `WorkReportPdf::raisedMemoryLimit()`, tested for 128M, a byte count, 256M, 512M, 1G and -1; the process-level test remains and skips when lowering is impossible.
- **Files modified:** `WorkReportPdf.php`, `WorkReportPdfTest.php`
- **Committed in:** `467901b` (code), `b4b2166` (tests)

---

**Total deviations:** 4 auto-fixed (2 bugs found by visual inspection, 1 test-robustness bug, 1 blocking testability gap)
**Impact on plan:** all necessary; the new class is the only addition outside the listed files and holds no behaviour beyond the plan.

## Issues Encountered

- `pdftotext` is not installed in the DDEV web image, so the two text-extraction tests skip there (as the plan allows, research A10). The same checks were done by hand with the host's poppler tools on rendered samples: page count, DejaVu Sans and DejaVu Sans Bold embedded as subsets, the running header on pages 2+ and not on page 1, "Strana n / m" and the generation date on every page, no clipped text beyond the margin, English and Czech variants.
- DDEV file sync lags the host by a few seconds; a scratch directory under `storage/framework` used for sample PDFs was removed before committing.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required. Optional: add `poppler-utils` to the DDEV web image so the two `pdftotext` tests run in DDEV instead of skipping.

## Next Phase Readiness

- 08-21 can add the "Pracovni vykaz PDF" download item with `forPeriod()` and `render()`; the language comes from `$source->clientLanguage`.
- Phase 10 attaches the report for exactly the billed entries with `fromEntryIds()` and the same `render()`.
- The `<human-check>` of Task 3 (look at a roughly three-page Czech and English sample) was done by the executor on rendered samples; a human sign-off remains for phase verification.

## Self-Check: PASSED

- Files: all seven created or modified key files found on disk
- Commits: `4b06aa5`, `3cc1295`, `1d64ce6`, `b4b2166`, `467901b` are ancestors of HEAD; `commits: 5` measured from the ledger base `969e3c0d56cbb957f410076de5e16801b90c51d5`
- `ddev exec vendor/bin/pest` full suite: 3293 passed, 3 skipped (two need pdftotext, one pre-existing); Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set
- Acceptance criteria: Czech strings and `sectionSeconds`/`daysWorked` present, `memory_limit`/`PAGE_COUNT`/`table-header-group`/`pdftotext`/`markTestSkipped` present, `fromEntryIds` and `lang/en/work_report.php` with "Work report" and "Non-billable" present, language files have identical key sets

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
