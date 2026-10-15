---
phase: 08-exchange-rates-and-reports
plan: 27
subsystem: reports-ui
tags: [time-report, layout, ui-spec-amendment, gap-closure, time-report-layout, CR-01, bookkeeping]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-26 full-width fixed table, grouped header, clamped names and Amendment A-1; 08-24 dashboard per currency; 08-23 and 08-25 CR-01 fixes"
provides:
  - "TimeReportPage view data openDepth: 0 without a client, 1 with a client, 2 with a project"
  - "The tree opens only as deep as the filters ask: rows above the depth start hidden in the server HTML, rows with children below it start open; the effective-rate row follows its project row"
  - "data-level (client, project, task, leaf) on every tree row"
  - "One static scoped style block with the kokpit-report- prefix: sticky opaque Název cells with an inset separator, the client band with a 24% top rule, the secondary gray, all with dark variants"
  - "Client and project rows at weight 600, task rows at 400, day and user rows and the placeholders in the secondary gray with no opacity, the task reference in the secondary gray"
  - "The effective-rate block on the spacing scale (name indent plus 1.5rem, padding 0 1rem 0.5rem, gap 0.5rem 2rem)"
  - "08-REVIEW-DISPOSITION.md CR-01 fixed; 08-VALIDATION.md rows 08-23-T1 to 08-27-T2, the gap round gate and the redesigned ledger 6 row"
affects: [verify-work visual pass (WINDOWS.md ledger 6), phase 08 verification]

actuals:
  tokens: 9000
  tasks: 2
  commits: 3

plan_head_before: 363eb8c592c6e055f586d1558ce758f57d0642b4
plan_head_after: dd93ff24534ddd44fbf3c9b101942fb19254851e
commits: 3

tech-stack:
  added: []
  patterns:
    - "Open depth derived from the validated filters and rendered as server hidden attributes, so the collapsed state needs no round trip and no flash"
    - "Scoped style block with .dark variants (the timer bar pattern) for rules a utility class cannot express: sticky cells with an opaque background, color-mix bands"
    - "A clamped label whose first part is a muted span keeps the full text in the title attribute"

key-files:
  created: []
  modified:
    - app/Filament/Pages/TimeReportPage.php
    - resources/views/filament/pages/time-report.blade.php
    - tests/Feature/Reports/TimeReportTest.php
    - .planning/phases/08-exchange-rates-and-reports/08-REVIEW-DISPOSITION.md
    - .planning/phases/08-exchange-rates-and-reports/08-VALIDATION.md

key-decisions:
  - "openDepth is computed in getViewData() from the validated ReportFilters (project set: 2, client only: 1, else 0); a forged URL value is already normalised by ReportFilters, so nothing new crosses the boundary (T-08-82 accepted)"
  - "Client rows are styled by the class on the tr and a child selector for the sticky cell, so the band wins over the plain sticky background; the strong top rule moved from the inline rule style to the class, because an inline border would override it"
  - "Row weight is set on the tr and repeated on the th, because the UA stylesheet makes th bold"
  - "The task reference span is built in the view from node reference and label; the full label stays in the title attribute and in the page text"

requirements-completed: [RE-01, RE-02]

coverage:
  - id: D1
    description: "Without a client every client row is visible and collapsed and all project, task and effective-rate rows carry hidden; with a client the client is open and its projects are visible and collapsed; with a project and split=day the client and project are open, tasks visible, day rows hidden"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the tree opens as deep as the filters"
        status: pass
    human_judgment: false
  - id: D2
    description: "The four levels differ by more than indent: client band class, 600 and 400 weights, secondary gray on day rows, placeholders and the task reference with no opacity"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the levels of the tree"
        status: pass
    human_judgment: false
  - id: D3
    description: "Název cells of header, body and footer are sticky with an opaque background in light and dark mode and an inset separator; one style block, every selector prefixed kokpit-report-, no Blade echo inside"
    requirement: "RE-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the levels of the tree (style block and sticky cells cases)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The effective-rate block uses only 0.5rem, 1rem, 1.5rem and 2rem and no opacity below 0.8 appears in the view"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/TimeReportTest.php#the levels of the tree (effective-rate case); grep acceptance of the view"
        status: pass
    human_judgment: false
  - id: D5
    description: "08-REVIEW-DISPOSITION.md marks CR-01 fixed (08-23, 08-24, 08-25); 08-VALIDATION.md maps every gap task 08-23-T1 to 08-27-T2 with command and threat refs; the full suite, Pint, Larastan and check-sensitive --all are green"
    requirement: "RE-01"
    verification:
      - kind: other
        ref: "grep of the CR-01 row and the 08-27-T2 and 08-23-T1 rows; full Pest run 3903 passed, 3 skipped; Pint 735 files clean; Larastan no errors; scripts/check-sensitive.sh --all clean"
        status: pass
    human_judgment: false
  - id: D6
    description: "The owner judges /admin/time-report readable at 1280px and 375px in light and dark mode; the Název column stays in view while the figures scroll; the secondary gray measures at least 4.5:1 (WINDOWS.md ledger 6)"
    verification: []
    human_judgment: true
    rationale: "Visual judgment of a rendered layout in a browser; owner visual pass, WINDOWS.md ledger 6 (backstop). Computed contrast, not a screen measurement: gray-500 on white 4.83:1, gray-400 on gray-900 6.99:1 (Tailwind gray hex equivalents of the Filament gray palette)"

duration: 25min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 27: Readable time report tree, second half and gap round close Summary

**The report opens as one compact line per client and opens only as deep as the filters ask; client, project, task and day rows read apart through a band, weights and a secondary gray; the Název column is sticky; CR-01 is recorded as fixed and the whole gap round is mapped and green.**

## Performance

- **Duration:** about 25 min (the full suite run alone takes 278 s)
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments

- `TimeReportPage::getViewData()` passes `openDepth`; the view starts a row open when it has children and its level is below the depth, and starts it hidden when its level is above it. The all-clients page is now one line per client instead of a long scroll; a client filter keeps the old result (client open, project closed, tasks hidden).
- Every tree row carries `data-level`; the effective-rate row starts hidden together with its project row.
- One static style block (`.kokpit-report-name`, `-client`, `-muted` with `.dark` variants) gives the sticky Název column an opaque background and an inset separator, the client rows a band and a 24% top rule, and the secondary gray to day rows, the placeholders "Bez projektu" and "Bez úkolu" and the task reference. The placeholders lost their 0.7 opacity.
- The effective-rate block moved to the B4 spacing; no off-scale value (0.75rem, 1.75rem, 2.5rem) and no opacity below 0.8 is left in the view.
- Bookkeeping: CR-01 `fixed` in the disposition; eleven new rows in the validation map, the gap round gate line and the redesigned ledger 6 manual-only row.

## Task Commits

1. **Task 1: Tracer - open depth by filters** - `caf6516` (feat)
2. **Task 2: Levels, sticky column, on-scale block** - `26fa901` (feat), **bookkeeping and gate** - `dd93ff2` (docs)

**Plan metadata:** recorded in the docs commit that follows this summary.

## Files Created/Modified

- `app/Filament/Pages/TimeReportPage.php` - `openDepth` view data
- `resources/views/filament/pages/time-report.blade.php` - open depth, `data-level`, style block, weights, muted classes, task reference span, effective-rate spacing
- `tests/Feature/Reports/TimeReportTest.php` - describes "the tree opens as deep as the filters" (4 cases) and "the levels of the tree" (5 cases)
- `.planning/phases/08-exchange-rates-and-reports/08-REVIEW-DISPOSITION.md` - CR-01 fixed
- `.planning/phases/08-exchange-rates-and-reports/08-VALIDATION.md` - gap rows, gate line, ledger 6 row

## Decisions Made

- See `key-decisions` in the frontmatter. The task reference gets its own muted span only for task rows with a stored reference; the label, the `title` and the page text stay "KEY-N · title".
- The contrast of the secondary gray was computed from the palette (Tailwind gray hex equivalents), not measured on a rendered screen, and recorded as such; the owner's pass confirms it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A new test seeded the same CNB rate twice**
- **Found during:** Task 1 (GREEN run)
- **Issue:** The two-client case inserted the 2026-09-15 rate inside its loop, so the unique key on rates failed.
- **Fix:** The rate is stored once before the loop.
- **Files modified:** tests/Feature/Reports/TimeReportTest.php
- **Committed in:** caf6516

**2. [Rule 1 - Bug] A new test asserted markup the Livewire output interleaves with comments**
- **Found during:** Task 2 (GREEN run)
- **Issue:** The placeholder check matched the text directly after the tag, but the page prints Livewire block comments between them.
- **Fix:** The assertion matches the span opening tag with its class instead.
- **Files modified:** tests/Feature/Reports/TimeReportTest.php
- **Committed in:** 26fa901

---

**Total deviations:** 2 auto-fixed (both test-only bugs)
**Impact on plan:** None on scope.

**Process note:** In Task 2 the view change was written before the tests; the RED state was proved afterwards by restoring the committed view with `git checkout -- <file>` (5 of 5 new cases failed), then putting the new view back from a scratchpad copy.

## Issues Encountered

None beyond the deviations above.

## Threat Flags

None. T-08-82 accepted as planned: `openDepth` is derived from the filters `ReportFilters::fromInput()` already validates. T-08-83 mitigated: the style block holds static rules only; the test asserts every selector carries the `kokpit-report-` prefix and the block has no Blade echo. T-08-SC: nothing installed, `composer.json` and `composer.lock` untouched.

## Known Stubs

None.

## User Setup Required

None. Open item for the owner: the visual pass of ledger 6 at 1280px and 375px in light and dark mode. On acceptance close the todo `2026-10-14-redesign-unreadable-time-report-layout`.

## Next Phase Readiness

All 27 plans of phase 8 are executed. Both 08-VERIFICATION.md gaps have code, tests and bookkeeping; the phase verifier and the owner's visual pass (ledger 6) remain. The hour column headers in 6rem (flagged by 08-26) and the sticky column on a phone are the two things that pass should look at. One known limit: the effective-rate row spans all seven columns and is not sticky, so on a phone its text scrolls with the figures.

## Self-Check: PASSED

- Files: the five files above exist and are committed.
- Commits: `caf6516`, `26fa901` and `dd93ff2` are ancestors of HEAD; `git rev-list --count` from the plan base gives 3.
- Acceptance criteria re-run: the grep checks for `kokpit-report-client`, `.dark .kokpit-report-`, `position: sticky`, the CR-01 row, the 08-27-T2 and 08-23-T1 rows pass; the off-scale grep finds nothing; the style block holds no `{{`.
- Gate: full Pest 3903 passed and 3 skipped; Pint clean over 735 files; Larastan no errors; `scripts/check-sensitive.sh --all` clean.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-15*
