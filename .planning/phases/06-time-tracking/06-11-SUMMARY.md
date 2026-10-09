---
phase: 06-time-tracking
plan: 11
subsystem: time-tracking
tags: [laravel, filament, livewire, timesheet, postgres, pest]

requires:
  - phase: 06-time-tracking
    provides: "TimeEntry with withElapsedSeconds and withOverlapFlag, TimeEntryResource::tableColumns(), DurationFormat, TimerClock and the Admin-only access rule machinery (06-01 to 06-10)"
provides:
  - "TimesheetPage ('Výkaz', /admin/timesheet, navigation sort 41, icon OutlinedCalendarDays): Admin-only page with a day view and a week grid, view and date in the URL (?view=day|week&date=YYYY-MM-DD) re-validated on every request"
  - "TimesheetQuery: Prague day and ISO week ranges as UTC predicates, strict view and date normalisation, summary() of total, billable and non-billable seconds, weekRows() as one grouped query with names joined and Prague day buckets"
  - "Day view: the entry list columns (without Datum and Změněno) over one Prague day ordered by start, a running entry at its elapsed time, footer 'Celkem za den', the 'Překryv' badge on every overlapping row"
  - "Week grid: Table::records() over arrays (client, project, task, seven day columns, Celkem), 'Bez projektu' and 'Bez úkolu' labels, '—' for empty cells, day headers that link to the day view, a footer row with day totals, the grand total and the overlap warning icon"
  - "kokpit.time.timesheet.* Czech strings"
affects: [06-12, 06-14]

tech-stack:
  added: []
  patterns:
    - "A Filament page with a table whose variant (query-backed day table, array-backed week grid) is chosen per request from a re-validated URL value"
    - "One grouped query for a grid: the entry scopes (elapsed seconds, overlap flag) as the inner query, names joined in the outer one, day bucket by AT TIME ZONE, bool_or for the overlap flag"
    - "Footer row of an array-backed Filament table as a contentFooter view that renders only cells inside the table's own <tr>"

key-files:
  created:
    - app/Filament/Pages/TimesheetPage.php
    - app/Domain/TimeTracking/Queries/TimesheetQuery.php
    - resources/views/filament/pages/timesheet.blade.php
    - resources/views/filament/pages/timesheet-week-footer.blade.php
    - tests/Feature/TimeTracking/TimesheetTest.php
  modified:
    - lang/cs/kokpit.php

key-decisions:
  - "The page property that carries the view is named $mode with #[Url(as: 'view')], because the Filament Page already owns a $view property for its blade view; the URL parameter stays ?view="
  - "Livewire properties are client-controlled, so mount() normalises them and every read goes through resolvedMode() and resolvedDate(); a forged set('mode') or set('date') after mounting falls back to the day view of today"
  - "The week grid reads clients, projects and tasks by joining them into the one grouped query (archived rows included), so the page costs the same number of queries at any volume and no separate name lookup exists"
  - "The grid rows use the project key and the task reference as the day view does; clients are ordered in Czech collation, then project key and task number, rows without a project or task last"
  - "The day view reuses TimeEntryResource::tableColumns() unchanged: the 'Celkem za den' footer is the list's 'total' summarizer relabelled, and its billable and non-billable summarizers are hidden for this page (the split is in the summary line)"
  - "Day totals in the footer show '—' for a day without entries and a total (also 0:00) for a day whose entries have zero length"
  - "An entry belongs to the Prague day it started; the rule is pinned by tests (23:30 to 00:30, Sunday night in the week it started) and is the one for the owner to review"

patterns-established:
  - "A test helper that reduces a rendered page to its visible text (tags, icons and comments removed, white space collapsed) so a grid row can be asserted as one string"
  - "A query-count test that warms the page up, then compares the logged queries of the same URL with 3 and with 40 entries spread over separate clients, projects and tasks"

requirements-completed: [TI-06]

coverage:
  - id: D1
    description: "The Admin opens 'Výkaz' and sees the day view of today: the signed-in user's entries of that Prague day by start with Čas, Klient, Projekt, Úkol, Popis, Trvání, Stav and Překryv, a running entry as 'H:i–' with 'Běží' counted at its elapsed time, the footer 'Celkem za den' and the summary line 'Celkem :total · fakturovatelné :billable · nefakturovatelné :nonbillable' from exact seconds"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the day view it lists the entries of today by start with the day total and the summary line"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the day view it shows the day total over the exact seconds, not the sum of rounded rows"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the day view it shows a dash for the project and the task of a client-only entry"
        status: pass
    human_judgment: false
  - id: D2
    description: "The week view shows Monday to Sunday under 'Týden :n · :from – :to' with one row per client, project and task ('Bez projektu', 'Bez úkolu'), seven day columns headed 'Po 12. 10.' that link to the day view (today's header semibold with aria-current=date), a Celkem column, '—' in empty cells and a footer row with the day totals and the grand total"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it groups the week by client, project and task with the totals of the rows, the days and the week"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it renders the rows, the empty cells and the footer"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it heads the week with its number and dates and the days with links to the day view"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it sums the week summary line over exact seconds"
        status: pass
    human_judgment: false
  - id: D3
    description: "A day total with overlapping entries carries the warning icon with the tooltip 'V tento den se záznamy překrývají, součet může být vyšší než skutečný čas.'; in the day view every overlapping row has the 'Překryv' badge, also when more than two overlap; nothing is blocked"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it marks a day whose entries overlap with the warning icon and its tooltip, and no other day"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the overlap flag it marks every overlapping row in the day view, also when more than two overlap"
        status: pass
    human_judgment: false
  - id: D4
    description: "View and date live in the URL; an invalid view or date (unknown view, impossible date, trailing text, array values, a forged Livewire property) falls back to the day view of today; the controls Den / Týden, Předchozí / Další, Dnes / Tento týden and Datum lead to the new URL"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the URL it falls back to the day view of today for a bad view or date (7 datasets)"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the URL it keeps a valid view and date"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the URL it ignores a forged view or date sent to the page after it was mounted"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it moves by seven days and returns to this week"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it moves the day view by one day and keeps the date when the view switches"
        status: pass
    human_judgment: false
  - id: D5
    description: "Days are Prague days filtered with UTC range predicates: an entry belongs to the day it started (23:30 to 00:30 wholly to the first day, a Sunday night entry to its own week), the 25-hour day 2026-10-25 and the 23-hour day 2027-03-28 sum from exact seconds, and the number of queries does not grow with the number of entries"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#Prague midnight and daylight saving it attributes an entry from 23:30 to 00:30 wholly to the day it started, in both views"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#Prague midnight and daylight saving it puts a Sunday night entry in the week it started and not in the next one"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#Prague midnight and daylight saving it sums the 25-hour day the clocks go back without losing the repeated hour"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#Prague midnight and daylight saving it sums the 23-hour day the clocks go forward"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the cost of a page it issues the same number of queries for the day and the week with 3 and with 40 entries"
        status: pass
    human_judgment: false
  - id: D6
    description: "Both views are read-only and Admin-only: a Partner gets 403 on both URLs and has no navigation item; the grid has no record or bulk actions"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the day view it refuses a Partner and gives it no navigation item"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the day view it gives the Admin a navigation item after the entries"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#the week grid it is read only: the grid has no record actions and no row link"
        status: pass
      - kind: integration
        ref: "tests/Arch/PanelRegistryTest.php (whole file)"
        status: pass
    human_judgment: false
  - id: D7
    description: "An empty day or week renders 'V tento den nejsou žádné záznamy' or 'V tomto týdnu nejsou žádné záznamy' with its body while the date controls stay usable"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#empty days and weeks it says so and keeps the controls usable"
        status: pass
    human_judgment: false
  - id: D8
    description: "At 375px the week grid scrolls horizontally inside its wrapper and the page itself never scrolls sideways; the weekday headers and 'Úterý', 'Čtvrtek', 'Pátek' style diacritics render correctly"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimesheetTest.php#empty days and weeks it scrolls the grid inside its wrapper so that the page never scrolls sideways"
        status: pass
    human_judgment: true
    rationale: "The test asserts the wrapper's overflow style; whether the page really does not scroll sideways at 375px and how the grid looks can be judged only in a browser (the plan's human check)"

actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: 305b18bbb2f3df7beaf66d7fc98ce5380b248e35
plan_head_after: f11620452851b58b0795e0f8258cc0652d7c6871
commits: 3

duration: 40min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 11: Timesheet "Výkaz" Summary

**An Admin-only timesheet at /admin/timesheet with a day table (exact-second totals, overlap badges, a running entry counted at its elapsed time) and a week grid by client, project and task with day and grand totals, both on Prague days via UTC range predicates and costing the same number of queries at any volume.**

## Performance

- **Duration:** about 40 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 6 (5 created, 1 modified)

## Accomplishments

- `TimesheetPage` is an Admin-only Filament page with a table: the day variant is a query-backed table that reuses `TimeEntryResource::tableColumns()` (minus Datum and Změněno), so the Čas, Stav and Překryv columns, the "Běží" badge and the overlap tooltip are the ones of the entry list; the week variant is `Table::records()` over arrays from one grouped query, `paginated(false)`, with a `contentFooter` view for the totals row.
- `TimesheetQuery` forms the Prague day range, the ISO week range and the seven week dates, normalises the URL values strictly (only a real `Y-m-d` calendar date and `week` are accepted), and reads the summary and the week grid from exact seconds. The grid query takes `withElapsedSeconds` and `withOverlapFlag` as its inner query, so the overlap rule exists in one place; names of archived clients, projects and tasks are joined in, so one query reads the whole week.
- Every control is a link or a GET form to the page URL with a new `view` and `date`: the Den / Týden switch keeps the date, previous and next move by one day or seven days, "Dnes" or "Tento týden" returns to the present (and carries `aria-current="date"` while it is the shown period), the date input submits on change.
- The week heading reads "Týden 42 · 12. 10. – 18. 10."; the day headers link to the day view with `wire:navigate`, today's is semibold with `aria-current="date"`; a day total with overlapping entries shows the warning icon with the tooltip and the same words as screen-reader text.
- The tests pin the Prague rules with real dates: 23:30 to 00:30 belongs to the first day in both views, a Sunday night entry stays in its own week, 2026-10-25 holds four hours for 00:30 to 03:30 and sums to 5:00 with the late entry, 2027-03-28 holds one hour for 01:30 to 03:30; bad `view` and `date` values (7 datasets) and a forged Livewire property fall back to the day view of today; 3 and 40 entries on separate clients, projects and tasks cost the same number of queries in both views.
- Full suite 2404 passed (17107 assertions) after the last code edit; Pint, PHPStan and `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): the Admin opens "Výkaz" and sees today's entries with the day total and the summary line** - `ecdeeb8` (feat)
2. **Task 2: the week grid by client, project and task with day and grand totals** - `a23d20e` (feat)
3. **Task 3: midnight, daylight saving, URL fallbacks and a constant query count, pinned by tests** - `f116204` (test)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the new test file, the panel registry test, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2 and Task 3 are flagged `tdd="true"`. As in the earlier plans of this phase each task is one commit holding tests and implementation, not a separate RED `test(...)` commit. For Task 2 the tests were written before the page code, but the first run happened after the code (it found two defects in my own test expectations and the unordered `day_totals`, which `ksort` now orders). For Task 3 the behaviour was already implemented by Tasks 1 and 2, so the new tests passed on the first correct run once their own fixtures were right (an invalid project key in a fixture, wrong expected cell text); no production code changed in Task 3, which is why its commit type is `test`.

## Decisions Made

See `key-decisions`. Findings worth keeping: (1) a Filament `Page` already has a `$view` property, so a public `$view` URL property is impossible; `#[Url(as: 'view')] public string $mode` keeps the URL parameter; (2) in the installed Filament, `contentFooter()` of a table renders inside `<tfoot><tr>`, so the view must emit `<td>` cells only; (3) the rendered page contains the side panel's recent entries, so a test that asserts a client name is absent from the grid must anchor on the grid row text, not on the name alone; (4) `x-tooltip` content is rendered unescaped-unicode inside the attribute, so a tooltip is asserted with a regex on the attribute, plus the screen-reader copy in the visible text.

## Deviations from Plan

### Plan changes (within scope)

- **`$mode` instead of `$view`:** the artifact table names `#[Url] public string $view = 'day'`. That name is taken by `Filament\Pages\Page::$view` (the blade view), so the property is `$mode` with `#[Url(as: 'view')]`; the URL contract `?view=day|week` is unchanged.
- **Names joined into the grouped query:** the plan says "one `GROUP BY` query plus one name lookup". The names are joined in the same query instead, so the grid costs one query for rows plus the summary query, and nothing per row or per name.
- **Day-column headers are built in PHP:** the headers are `HtmlString` links rendered with an inline `Blade::render` in `TimesheetPage::dayHeader()` (the markup is tiny and needs the page URL). The acceptance check `grep 'aria-current'` on `timesheet.blade.php` is met by the "Dnes" / "Tento týden" button, which carries `aria-current="date"` while the shown period is the current one; today's column header carries it in the PHP-built link.
- **The footer "Celkem za den":** reuses the list's `total` summarizer with a new label and hides the list's billable and non-billable summarizers for this page, instead of changing `TimeEntryResource::tableColumns()` (not in the plan's file list).
- **Day view paginates nothing:** `paginated(false)` on the day table as well, so a day is always complete; the plan only required it for the grid.
- **Extra columns removed:** the day view also drops the list's toggleable "Změněno" column; the UI-SPEC column list for the day view does not include it.
- **TI-06 is shared:** plans 06-07 and 06-14 also declare TI-06; see Requirements.

**Total deviations:** 0 auto-fixed bugs, 6 small in-scope changes.
**Impact:** none on scope; the contract of the plan is met.

## Issues Encountered

None open. The 375px overflow check from the plan is a human check (below); no browser was available here.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-29 (the page declares `#[AccessRule(Audience::AdminOnly)]` with `EnforcesPageAccessRule`, which refuses at boot before anything is read; `TimeEntry` stays `DeniesPartners`; tests for a Partner on both URLs and for the missing navigation item), T-06-30 (the view is whitelisted to `day` / `week`, the date is parsed strictly as a real `Y-m-d` date, array values fall back, every read re-validates the Livewire property, and the dates are bound as parameters; seven URL datasets and a forged-property test), T-06-SC (no package added).

## Requirements

`requirements-completed: [TI-06]` copies the plan frontmatter. TI-06 is also declared by plans 06-07 and 06-14, and 06-14 has no summary yet, so the shared-ID gate keeps it from being ticked in REQUIREMENTS.md until 06-14 finishes. TI-01 and TI-05 were ticked earlier and stay so.

## Next Phase Readiness

Plan 06-12 (project overview) is independent of the timesheet; `TimesheetQuery` and `TimeTotals` can share the elapsed-seconds expression if a third place needs it. Plan 06-14 should add "Výkaz", "Den", "Týden", "Celkem za den" and "Bez úkolu" to the Partner leak canary; this plan already asserts a Partner gets 403 on the page and no navigation item. Human check for `/gsd-verify-work`: in DDEV as the Admin at 375px width open the week view with entries on all seven days; the grid scrolls horizontally inside its wrapper and the page does not scroll sideways; the weekday headers and the diacritics render correctly; the day-header links open the day view; the overlap icon shows its tooltip on hover and keyboard focus is sensible. Expected: matches UI-SPEC Surface G. The owner should also review the rule that an entry spanning midnight belongs to the day it started (research A5), which the tests pin.

## Self-Check: PASSED

Created files `TimesheetPage.php`, `TimesheetQuery.php`, `timesheet.blade.php`, `timesheet-week-footer.blade.php` and `TimesheetTest.php` exist; commits `ecdeeb8`, `a23d20e` and `f116204` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2404 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
