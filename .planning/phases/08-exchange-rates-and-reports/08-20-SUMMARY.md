---
phase: 08-exchange-rates-and-reports
plan: 20
subsystem: ui
tags: [filament, dashboard, widgets, table-widget, signal, livewire, alpine, task-deadlines]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-15 Admin branch of Dashboard::getWidgets(), two-column layout, the kokpit.dashboard.deadlines/plan copy, AdminDashboardTest fixtures, the PanelAccessTest widget set"
  - phase: 07-signal-planner
    provides: "SignalDayReader tasks()/shadows(), SignalCalendar::tomorrow()/formatLong(), SignalCategory colours and labels, the owner scope of the Signal models"
  - phase: 05-tasks-and-kanban
    provides: "TaskColumns::orderByPriority, TaskResource view URL by reference"
provides:
  - "App\\Filament\\Widgets\\UpcomingDeadlines: the ten first open tasks with a due date, priority first then nearest due date, overdue rows highlighted by date, badge and day count"
  - "App\\Filament\\Widgets\\TomorrowPlan: the signed-in Admin's own Signal plan for tomorrow, read-only, first three items visible and the rest behind an aria-expanded toggle"
  - "Admin branch of Dashboard::getWidgets() now HoursStats, UnbilledPerClient, UpcomingDeadlines, TomorrowPlan"
affects: [08-22 Phase 8 isolation sweep, Phase 10 and 11 dashboard band at sort 20]

actuals:
  tokens: 17500
  tasks: 2
  commits: 5

plan_head_before: 760520afdd5b31042a94869671fc62fd7a64d36d
plan_head_after: 6a782b72301bd16f6a5de8e887b7e4f2cc72a4b3
commits: 5

tech-stack:
  added: []
  patterns:
    - "Table widget over an Eloquent query with ViewColumn cells selected by a kind value, the row a Task with its project and client eager-loaded (withTrashed) in three queries whatever the row count"
    - "Collapsible region without a Filament component: an Alpine x-data toggle, a hidden attribute that is also bound with x-bind:hidden, aria-expanded and aria-controls on one button, label swapped by x-text from @js values"
    - "Read-only proof by markup: the widget test lists every <a> href and counts <button>, <input>, <form> and wire:click"

key-files:
  created:
    - app/Filament/Widgets/UpcomingDeadlines.php
    - app/Filament/Widgets/TomorrowPlan.php
    - resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
    - resources/views/filament/widgets/tomorrow-plan.blade.php
  modified:
    - app/Filament/Pages/Dashboard.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Feature/Dashboard/PartnerDashboardTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "Deadlines are ordered by priority first (TaskColumns::orderByPriority: urgent, high, normal, low), then the nearest due date with NULLS LAST, then reference and id. This is the owner's requirement (coordinator message during execution) and the semantics of the completed todo 'Sort tasks by priority then nearest due date by default'; the plan and UI-SPEC E3 said due date first, then priority, so the owner's rule wins"
  - "A task without a due date stays out of the list (plan truth, empty state 'Žádné nadcházející termíny'); NULLS LAST is kept in the order as a guard but can never place an undated task"
  - "The overdue day-count line sits in the Termín cell under the badge, in danger text; the client and project line of the Úkol cell stays gray"
  - "The client and project of an open task are named even when the project or client is archived (withTrashed on both relations)"
  - "A task already materialised from a recurring template (recurring_id set) carries the Opakuje se marker like a template not yet materialised, as the planner page shows the arrow for both"
  - "The count badge is rendered also for an empty plan ('0 úkolů'), the plural contract has a {0} form"

patterns-established:
  - "Priority-aware default order of an open-task list is TaskColumns::orderByPriority + due_date ASC NULLS LAST + stable tiebreakers, shared by the task list, the Partner dashboard and the deadlines widget"

requirements-completed: [RE-04]

coverage:
  - id: D1
    description: "'Nejbližší termíny' lists the first ten open tasks with a due date (not done, not archived), each row 'KEY-N · title' linked to the task page with the client and project in gray and the date j. n. Y; done, undated and archived tasks are absent; an empty list shows 'Žádné nadcházející termíny'"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#lists the ten nearest of twelve open tasks of one priority with a due date, nearest first"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#leaves out a done task, a task without a due date and an archived task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#links each row to the task page and names the client and the project under the title"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#says \"Žádné nadcházející termíny\" when no open task has a due date"
        status: pass
    human_judgment: false
  - id: D2
    description: "Owner requirement: priority weighs in with the due date (urgent before high before normal before low, nearest date within a priority); undated tasks are never listed; overdue rows stay highlighted wherever the order puts them"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#puts the higher priority first and orders by the nearest due date within a priority"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#puts the most urgent first among tasks due on the same day"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#lists a task without a due date nowhere, so no undated task can take a place"
        status: pass
    human_judgment: false
  - id: D3
    description: "Overdue rows show the date in danger text, the danger badge 'Po termínu' with the warning triangle and 'Po termínu o :count den/dny/dní'; a task due today reads 'Dnes' in semibold without alarm colour; markup in titles and names is escaped"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#highlights an overdue task with the danger date, the badge and the day count, and a task due today with \"Dnes\""
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows no alarm for a task due today or later"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#escapes markup in a title and in the client and project names"
        status: pass
    human_judgment: true
    rationale: "Tests prove the danger classes, text and icon markup; whether the danger colour, the one-line ellipsis and the 20rem cap read well in the half-width card in light and dark mode is a visual check"
  - id: D4
    description: "'Plán na zítřek' reads the signed-in Admin's own Signal plan for tomorrow (tasks plus templates not yet materialised), read-only: no checkbox, drag handle, add, edit, lock or Signal action, only the link 'Otevřít plán'; another Admin's rows never appear; an empty tomorrow shows 'Na zítřek zatím nic nenaplánováno' with the planner hint"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#is read-only: no form control but the toggle and no link but the one to the planner"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#reads only the own planner, never another Admin's rows"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#says \"Na zítřek zatím nic nenaplánováno\" with the planner hint and the link when nothing is planned"
        status: pass
    human_judgment: false
  - id: D5
    description: "Three items always visible, the rest in a region hidden on every load, one toggle 'Zobrazit dalších :count' with aria-expanded and aria-controls only above three items, count badge in Czech plural forms, recurring marker with the circular arrow, category dot and label, long titles on one line with tooltip"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows tomorrow's tasks and the template not yet materialised, three outside the collapsed region and three inside"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows no toggle for exactly three items and the right count badge for one, two and three"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#marks a recurring template with \"Opakuje se\" and the circular arrow, and every item with its category and dot"
        status: pass
    human_judgment: true
    rationale: "The Alpine expand and collapse itself (the button turning into 'Skrýt dalších :count', the region opening) runs in the browser and no test executes Alpine; only the server-rendered initial state and the attributes are asserted"
  - id: D6
    description: "A Partner gets 403 mounting UpcomingDeadlines or TomorrowPlan and the Partner dashboard shows neither; the panel widget set includes both"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#is refused to a Partner, also when mounted by force, and absent from the Partner dashboard"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#replaces the stock dashboard and its widgets"
        status: pass
    human_judgment: false

duration: 33min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 20: Dashboard deadlines and tomorrow plan Summary

**Admin dashboard gains "Nejbližší termíny" (ten open tasks, priority first then nearest due date, overdue highlighted by date, badge and day count) and a read-only "Plán na zítřek" from the Signal planner, collapsed to three items behind an aria-expanded toggle, both Admin-only.**

## Performance

- **Duration:** 33 min
- **Started:** 2026-10-14T22:47:00Z (approximate)
- **Completed:** 2026-10-14T23:21:00Z
- **Tasks:** 2
- **Files modified:** 8 (4 created, 4 modified)

## Accomplishments
- `UpcomingDeadlines` table widget (sort 40): open tasks with a due date, three queries however many rows, client and project named even when archived, four time-event listeners and a 60 s visible-only poll.
- Overdue highlighting carried three ways (danger date, badge with the warning triangle, Czech-plural day count); a task due today reads "Dnes" in semibold.
- `TomorrowPlan` widget (sort 50, full width, no polling) reading `SignalDayReader::tasks()` plus `shadows()` for tomorrow through the Signal owner scope; no Signal action is called.
- Owner requirement applied mid-plan: the deadlines order is priority first, then due date, using the shared `TaskColumns::orderByPriority`.

## Task Commits

1. **Task 1: Tracer, ten nearest deadlines with overdue highlighted** - `6e102b8` (feat)
2. **Task 2 RED: tomorrow plan tests** - `2ecb467` (test)
3. **Task 2 GREEN: TomorrowPlan widget and view** - `b9688fb` (feat)
4. **Owner requirement: priority-aware deadline order** - `6a782b7` (feat)

**Plan metadata:** the docs commit that carries this file (docs: complete plan).

The measured `commits: 5` of the frontmatter includes `8acdbee`, a planning todo commit made by the coordinator during this run; the four commits above are this plan's own.

## Files Created/Modified
- `app/Filament/Widgets/UpcomingDeadlines.php` - the deadlines table widget and the `overdueDays()` helper
- `resources/views/filament/widgets/upcoming-deadlines-cell.blade.php` - the task cell (link, one line, tooltip, client and project) and the due cell (date, Dnes, overdue badge and line)
- `app/Filament/Widgets/TomorrowPlan.php` - the tomorrow plan widget, `items()` and the view data
- `resources/views/filament/widgets/tomorrow-plan.blade.php` - section with count badge, link, the three visible items, the hidden region and the toggle
- `app/Filament/Pages/Dashboard.php` - Admin branch extended, docblock updated
- `tests/Feature/Dashboard/AdminDashboardTest.php` - deadline and plan tests with Signal and task fixtures
- `tests/Feature/Dashboard/PartnerDashboardTest.php` - one assertion made specific (see deviations)
- `tests/Isolation/PanelAccessTest.php` - both widgets in the panel widget set

## Decisions Made
See `key-decisions`. The ordering decision in short: priority weight first, then nearest due date, nulls last, then reference and id. Trade-off recorded for the owner: with strict priority first, ten urgent tasks due next month take all ten places and an overdue low-priority task is not listed until they are done; if that proves too blunt, an "overdue first" bucket in front of the priority order is a one-line change in `UpcomingDeadlines::deadlines()`.

## Deviations from Plan

### Owner requirement overriding the plan

**1. [Owner requirement] Deadline order is priority first, then due date**
- **Found during:** Task 2 (coordinator message)
- **Issue:** Plan and UI-SPEC E3 order by due date, then priority; the owner wants priority to weigh in, reusing `TaskColumns::orderByPriority` as in the Partner dashboard and the completed todo on default task sorting. The owner also asked the test to prove "nulls last"; the plan truth says an undated task is not listed, so undated tasks are excluded and the NULLS LAST clause is a guard only.
- **Fix:** `orderByPriority`, then `due_date ASC NULLS LAST`, then reference and id; three new tests (order, same-day tie, undated never listed); the two older ordering tests renamed to say what they now prove.
- **Files modified:** app/Filament/Widgets/UpcomingDeadlines.php, tests/Feature/Dashboard/AdminDashboardTest.php
- **Committed in:** 6a782b7

### Auto-fixed Issues

**2. [Rule 3 - Blocking] Extra Blade view for the deadline cells**
- **Found during:** Task 1
- **Issue:** The plan's file list has no view for the deadlines table, but the two-line task cell and the date, badge and day-count cell cannot be built from stock columns with the required markup (tooltip, one-line ellipsis, danger styling).
- **Fix:** `resources/views/filament/widgets/upcoming-deadlines-cell.blade.php`, a ViewColumn cell selected by a `kind` value like the unbilled table's cell.
- **Committed in:** 6e102b8

**3. [Rule 1 - Bug] Existing Partner dashboard test collided with the planned empty-state copy**
- **Found during:** Task 1
- **Issue:** `PartnerDashboardTest` asserted the Admin dashboard does not contain "Otevřené úkoly", but the locked deadlines empty-state description begins "Otevřené úkoly zatím nemají termín."
- **Fix:** The assertion now uses the Partner widget's own sub-line (`partner_tasks.description`).
- **Files modified:** tests/Feature/Dashboard/PartnerDashboardTest.php
- **Committed in:** 6e102b8

**4. [Rule 1 - Bug] Test fixtures against database limits**
- **Found during:** Task 1
- **Issue:** The behaviour list names a 300-character title, but `tasks.title` is varchar(255), and project keys accept letters only.
- **Fix:** The long-title tests use 251 characters; fixture keys are letters.
- **Committed in:** 6e102b8, 2ecb467

---

**Total deviations:** 1 owner override and 3 auto-fixed (2 bug, 1 blocking)
**Impact on plan:** No scope creep; the order override is the only change to a locked decision and it is the owner's.

## Issues Encountered
- The first full-suite run failed once on `PartnerDashboardTest` "nothing of time or money on the Partner dashboard" (assertion "true is false"); the test passed alone eight times in a row and the second full run was green (3826 passed, 3 skipped). The Partner dashboard does not render either new widget; the probable cause is a random hex fragment of a canary name colliding with one of the digit strings of that test's never list. Not caused by this plan, not fixed (scope boundary); worth a look in a hardening pass.

## Known Stubs
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Ready for the remaining Phase 8 plans; the Admin dashboard holds all four planned widgets and the reserved sort 20 band for Phases 10 and 11 is untouched.
- Visual check pending for the half-width deadlines card and the Alpine toggle in a browser (coverage D3 and D5).

## Self-Check: PASSED

All four created files exist, commits `6e102b8`, `2ecb467`, `b9688fb` and `6a782b7` are ancestors of HEAD, the acceptance greps pass, `pest` (full suite), Pint and PHPStan are clean, and `scripts/check-sensitive.sh` was clean on every commit.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
