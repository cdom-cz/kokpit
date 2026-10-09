---
phase: 06-time-tracking
plan: 12
subsystem: time-tracking
tags: [laravel, filament, livewire, widget, relation-manager, postgres, pest]

requires:
  - phase: 06-time-tracking
    provides: "TimeTotals::forTask, TimeEntryResource::tableColumns()/filters()/billingBulkActions(), TimerClock, DurationFormat, the Admin-only access rule machinery and the billing Actions (06-01 to 06-11)"
provides:
  - "ProjectTimeStats: Admin-only StatsOverviewWidget on the project view page with Odhad, Odpracováno, Vyfakturováno, Nevyfakturováno as H:MM (percent of the estimate, 'Překročeno o', 'Odhad není zadaný', 'Nefakturovatelné: :duration'); registered on ProjectResource::getWidgets() and returned by ViewProject::getHeaderWidgets(), the panel widget list stays empty"
  - "TimeTotals::forProject() (worked, billed, unbilled, non_billable, estimate), withoutTask(), ownTaskEstimates() and taskColumns() (correlated sub-selects worked_seconds, billed_seconds, unbilled_seconds, non_billable_seconds, estimate_seconds with the own-or-parent estimate)"
  - "ProjectTasksTimeRelationManager ('Úkoly a čas'): every task and subtask, archived ones included, estimate against worked, remainder in danger text, billed and unbilled, hidden toggleable Nefakturovatelné, footer with the 'Bez úkolu' line and 'Celkem'"
  - "ProjectTimeEntriesRelationManager ('Časové záznamy'): the entries list columns and filters (no Projekt) and the two billing bulk actions on the project page"
  - "Project::timeEntries(); kokpit.time.project.* Czech strings"
affects: [06-14]

tech-stack:
  added: []
  patterns:
    - "A widget registered on a Resource and returned by a resource page instead of discovered at panel level, so the panel widget list stays empty"
    - "Per-row aggregates of a relation manager as correlated sub-selects over one aggregate-friendly helper (TimeTotals::taskColumns), with custom sortable(query:) closures because the columns exist only as aliases"
    - "A table footer made of two lines in one contentFooter view (the first row closes its own tr and opens the next), aligned with the visible columns"
    - "A no-op #[On('time-entry-saved')] method as the cheapest way to make a Livewire component re-render after a sibling component billed entries"

key-files:
  created:
    - app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php
    - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTasksTimeRelationManager.php
    - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTimeEntriesRelationManager.php
    - resources/views/filament/resources/project-resource/tasks-time-footer.blade.php
    - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
  modified:
    - app/Domain/Projects/Models/Project.php
    - app/Domain/TimeTracking/Queries/TimeTotals.php
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
    - app/Filament/Concerns/EnforcesWidgetAccessRule.php
    - lang/cs/kokpit.php

key-decisions:
  - "The widget lives in app/Filament/Resources/ProjectResource/Widgets and is registered through ProjectResource::getWidgets(); the panel only discovers app/Filament/Widgets, so Panel::getWidgets() stays [] (PanelAccessTest) and the widget is never offered to a dashboard without a record. This corrects the path proposed in 06-PATTERNS.md"
  - "Task rows compare time only with the task's own or its parent's estimate (research A2); the project estimate is only in the stats row. COALESCE keeps an own estimate of 0 as a value"
  - "'Bez úkolu' is rendered in the table footer together with 'Celkem' instead of one fixed last row, because a relation manager table cannot host a synthetic record (Open Question 2); the numbers are the contract"
  - "A running billable entry counts in Odpracováno and Nevyfakturováno at its elapsed time at render; the widget has no polling, so there is no live ticking (UI-SPEC unresolved M7 assumption)"
  - "The footer Odhad is the sum of estimates that tasks hold in their own billing row and the footer Zbývá is blank, so an estimate inherited by subtasks is never counted twice and a remainder of a sum does not mix tasks with and without an estimate"
  - "Stats row and task tab re-render on the existing time-entry-saved event, so billing from the 'Časové záznamy' tab shows at once instead of after a reload"

patterns-established:
  - "Tests that assert a colour anchor on the cell value (a regex from fi-color-danger to the value), because the class name also appears in the generic column-manager markup of every table"

requirements-completed: [PR-05]

coverage:
  - id: D1
    description: "The Admin project page shows the stats row Odhad, Odpracováno, Vyfakturováno, Nevyfakturováno as H:MM, with the running entry counted at its elapsed time, the percent of the estimate rounded down, 'Překročeno o :duration' in danger text above the estimate, an estimate of 0 as a value, and '—' with 'Odhad není zadaný' without one; worked = billed + unbilled + non-billable in exact seconds; no money"
    requirement: "PR-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the stats row (9 tests)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The tab 'Úkoly a čas' lists every task and subtask including archived ones with Odhad (own or parent only), Odpracováno, Zbývá (blank without an estimate, minus sign in danger text), Vyfakturováno, Nevyfakturováno and the hidden Nefakturovatelné, ordered by Odpracováno descending, with the same number of queries for 3 and for 20 tasks and sortable time columns"
    requirement: "PR-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the tab \"Úkoly a čas\" (it compares the time of a task ... through ... issues the same number of queries)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Entries logged to the project without a task appear as 'Bez úkolu' in the table footer, followed by 'Celkem' summing all tasks plus 'Bez úkolu'; the footer Odpracováno equals the stats row"
    requirement: "PR-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the tab \"Úkoly a čas\" it shows the time without a task on the \"Bez úkolu\" line and sums everything in \"Celkem\""
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the tab \"Úkoly a čas\" it sums the estimates of the tasks that hold one, a subtask that only inherits adds none"
        status: pass
    human_judgment: true
    rationale: "The footer is two lines inside the table's tfoot, aligned with the visible columns by colspan and column order; the tests assert the text and figures, but how the alignment and weights look in a browser (also with the Nefakturovatelné column toggled on) can be judged only visually"
  - id: D4
    description: "The tab 'Časové záznamy' lists only the entries of the project with the columns, badges and filters of the entries list (no Projekt filter), offers the two billing bulk actions, bills and unbills a selection, and the stats row reflects it; each tab has its own empty state; a long unbroken task title wraps with overflow-wrap: anywhere"
    requirement: "PR-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the tab \"Časové záznamy\" (10 tests)"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the tab \"Úkoly a čas\" it wraps a single unbroken word in the title instead of overflowing"
        status: pass
    human_judgment: false
  - id: D5
    description: "The widget and both tabs exist only on the Admin ProjectResource: a Partner is refused on the widget and both tabs (403, also forged), the Partner project page renders none of the words, and the panel widget list stays empty"
    requirement: "PR-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#the registration (3 tests)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php and tests/Arch/PanelRegistryTest.php (whole files)"
        status: pass
    human_judgment: false

actuals:
  tokens: 15000
  tasks: 3
  commits: 3
plan_head_before: 837ca8fb5aaa40f29a9e1f7e19825ca64ab75395
plan_head_after: d717a8a21e10815c34a9d78305b9fa59caa50473
commits: 3

duration: 15min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 12: Project time overview Summary

**An Admin-only time overview on the project page: a four-figure stats row (estimate, worked, billed, unbilled as H:MM, a running entry at its elapsed time), a tab of every task with estimate against actual and billed against unbilled at constant query cost with a "Bez úkolu" and "Celkem" footer, and a tab of the project's entries with the two billing bulk actions, none of it reachable by a Partner.**

## Performance

- **Duration:** about 15 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 11 (5 created, 6 modified)

## Accomplishments

- `TimeTotals` now holds the one aggregate shared by `forTask()`, `forProject()` and `withoutTask()` (four sums with `FILTER` clauses over `COALESCE(duration_seconds, elapsed at $now)`), plus `taskColumns()` for the task tab: five correlated sub-selects per task (worked, billed, unbilled, non-billable, estimate), so the tab costs the same number of queries for 3 and for 20 tasks and runs no resolver per row. The query helpers keep PHPStan's literal-string rule by building SQL from class constants.
- `ProjectTimeStats` is a `final` `StatsOverviewWidget` with `#[AccessRule(Audience::AdminOnly)]` and `EnforcesWidgetAccessRule`, no polling and no lazy loading. The percent is `intdiv(worked * 100, estimate)` (99:59 of 1 h reads 99 %), an estimate of 0 has no percent and any time exceeds it. The `@phpstan-ignore trait.unused` line on the trait is gone now that a widget uses it.
- "Úkoly a čas" lists archived tasks too (soft-delete scope lifted for the tab), sorts by worked time descending then id, and sorts by every time column through `sortable(query:)` closures. The row opens the task page; the Název cell carries `overflow-wrap: anywhere`.
- "Časové záznamy" is the entry list again: `TimeEntryResource::tableColumns()`, its filters minus Projekt, its `billingBulkActions()`, the elapsed-seconds and overlap scopes on the relationship query, and the entry page as row URL.
- 38 tests in `ProjectTimeOverviewTest`; the full suite is 2442 passed (17331 assertions) after the last code edit; Pint, PHPStan and `scripts/check-sensitive.sh` are clean on every commit.

## Task Commits

1. **Task 1 (tracer): estimate, worked, billed and unbilled time in the stats row** - `a6a568e` (feat)
2. **Task 2: every task with estimate against worked and billed against unbilled, "Bez úkolu" and "Celkem"** - `f08623d` (feat)
3. **Task 3: the project's entries with the billing actions, Partner proof, full suite** - `d717a8a` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the new test file, the panel access and registry tests, Pint and PHPStan were run end to end and passed before the expansion.

## TDD note

Task 2 and Task 3 are `tdd="true"`. The tests of each task were written first and run RED (the relation manager class did not exist, `ComponentNotFoundException`) before the implementation; as in the earlier plans of this phase each task is one commit holding tests and implementation, not a separate `test(...)` commit. In Task 2 one test turned out to be a false positive on first sight (`fi-color-danger` also occurs in the column-manager markup of every table), so the colour assertions were tightened to anchor on the cell value before the commit.

## Decisions Made

See `key-decisions`. Findings worth keeping: (1) a `contentFooter` renders inside one `<tfoot><tr>` and only when the page has task rows, so two lines are made by closing and reopening the `tr` in the view and a project without tasks shows the empty state, not the "Bez úkolu" line (its time is in the stats row); (2) the stats widget needs `$pollingInterval = null` (the default is 5 s) and `$isLazy = false`; (3) the Livewire test of an event on a component without a listener fails, so the no-op `#[On('time-entry-saved')]` methods are real behaviour, verified by removing one.

## Deviations from Plan

### Plan changes (within scope)

- **Footer Zbývá is blank and footer Odhad is the sum of own estimates:** the plan says "footer sums of all numeric columns". Summing the displayed estimates would count a parent's estimate again for every inheriting subtask, and a sum of remainders would mix tasks with and without an estimate. Added `TimeTotals::ownTaskEstimates()` (not in the plan's artifact list, same file) and left the remainder blank. For owner review.
- **Refresh on `time-entry-saved`:** the plan only requires the stats row to reflect billing "after reload". All three components now carry a no-op `#[On('time-entry-saved')]` method so the figures update at once; the event is the one the billing bulk actions already dispatch. Small addition in the same files.
- **`ownTaskEstimates()` returns `int|null`:** null while no task holds an estimate, so the footer shows "—" instead of an ambiguous 0:00.
- **`tests/Feature/TimeTracking/ProjectTimeOverviewTest.php` is wider than the plan lists:** it also pins sorting, the ordering by id, the cross-project isolation, the edit page's tabs, the registration order and the Partner refusal of both tabs.

**Total deviations:** 0 auto-fixed bugs, 4 small in-scope changes.
**Impact:** none on scope; the contract of the plan is met.

## Issues Encountered

None open. The alignment of the two footer lines is a human check (below); no browser was available here.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-31 (widget and both tabs declare `#[AccessRule(AdminOnly)]` and use the enforcing traits, so a Partner is refused at boot; tests for the widget, both tabs mounted forged, the `canViewForRecord` checks and the Partner project page free of "Odpracováno", "Vyfakturováno", "Nevyfakturováno", "Odhad", "Úkoly a čas", "Časové záznamy"), T-06-32 (the widget is registered on the resource, outside `app/Filament/Widgets`; `Panel::getWidgets()` is asserted `[]` and `PanelAccessTest` and `PanelRegistryTest` pass), T-06-SC (no package added).

## Requirements

`requirements-completed: [PR-05]` copies the plan frontmatter. PR-05 is also declared by plan 06-14 (the Partner leak canary), which has no summary yet, so the shared-ID gate keeps it from being ticked in REQUIREMENTS.md until 06-14 finishes. TI-01 and TI-05 were ticked earlier and stay so.

## Next Phase Readiness

Plan 06-14 should add "Odpracováno", "Vyfakturováno", "Nevyfakturováno", "Úkoly a čas" and "Časové záznamy" to the Partner leak canary; this plan already asserts that the Partner project page and the three Livewire components contain or admit none of it. Human check for `/gsd-verify-work`: in DDEV as the Admin open a project with tasks, subtasks, an archived task, a running entry and entries without a task; check that the stats row matches the "Celkem" line of "Úkoly a čas", that the two footer lines line up with the columns (also after toggling Nefakturovatelné on), that a negative Zbývá is red with a minus sign, and that billing a selection in "Časové záznamy" updates the stats row without a reload. The owner should also review the three decisions above that deviate from the UI-SPEC wording (footer instead of a fixed last row, no project estimate on task rows, blank footer Zbývá).

## Self-Check: PASSED

Created files `ProjectTimeStats.php`, `ProjectTasksTimeRelationManager.php`, `ProjectTimeEntriesRelationManager.php`, `tasks-time-footer.blade.php` and `ProjectTimeOverviewTest.php` exist; commits `a6a568e`, `f08623d` and `d717a8a` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2442 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
