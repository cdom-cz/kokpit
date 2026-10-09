---
phase: 06-time-tracking
plan: 10
subsystem: time-tracking
tags: [laravel, filament, livewire, kanban, pest]

requires:
  - phase: 06-time-tracking
    provides: "StartTimer, StopTimer, TimerRaceLost, DurationFormat, TimerClock, TimerBar and RecentEntriesPanel listening to timer-started and timer-stopped (06-02 to 06-09)"
provides:
  - "TaskResource::timerAction(): the one-click 'Spustit časovač' / 'Zastavit časovač' action (name toggleTimer) used as header action on the task page and the task edit page and as an icon-button row action in the Admin task list"
  - "TaskTimerToggle (App\\Filament\\Support): the shared start-or-stop with the toasts and event names of the timer bar, so every task surface calls StartTimer with the task id only (D-02, D-03)"
  - "ManagesTaskBoard::toggleTimer(string $taskId) and computed runningTaskId(): untrusted card argument (uuid check, Partner-scoped lookup, view Gate) and a 1.5rem start or stop icon button in the card header inside wire:sort:ignore"
  - "TimeTotals::forTask(): worked, billed, unbilled and non-billable seconds of a task in one FILTER aggregate, a running entry at its elapsed time"
  - "Task::timeEntries() and the Admin-only 'Čas' section of the task page (Odpracováno, Nevyfakturováno, link 'Zobrazit záznamy' to the entries list filtered to the task)"
affects: [06-12, 06-13, 06-14]

tech-stack:
  added: []
  patterns:
    - "One shared Filament-side service (TaskTimerToggle) behind every task start affordance, instead of three copies of the toast and event code"
    - "Per-request memo of the running task id for a Filament table: a static WeakMap keyed by the Livewire component, dropped after the action; the board uses a Livewire computed property"
    - "Entries-list deep link carries filters[task_id][value]=<id>, the query string of the installed Filament ListRecords page"

key-files:
  created:
    - app/Filament/Support/TaskTimerToggle.php
    - app/Domain/TimeTracking/Queries/TimeTotals.php
    - tests/Feature/TimeTracking/TaskStartAffordancesTest.php
  modified:
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - app/Filament/Resources/TaskResource/Pages/EditTask.php
    - app/Filament/Concerns/ManagesTaskBoard.php
    - resources/views/filament/pages/task-board.blade.php
    - app/Domain/Tasks/Models/Task.php
    - lang/cs/kokpit.php

key-decisions:
  - "The start-or-stop logic lives once in TaskTimerToggle; TaskResource::timerAction() and ManagesTaskBoard::toggleTimer() only authorize, resolve the task and dispatch the event"
  - "The board's toggleTimer looks the task up with withTrashed() (still through the Partner-scoped query and the view Gate), so a stale card of a task archived meanwhile gets the task error toast on start and can still stop its own running timer, instead of a bare 404"
  - "'Nevyfakturováno' counts billable entries whose billing_state is unbilled, a running one at its elapsed time (research A3); 'Odpracováno' counts every entry of the task, non-billable ones included"
  - "The task page figures cover task_id = this task only; a parent task does not add its subtasks' time"
  - "The 'Čas' figures are computed once per request on the page (a private memo), both entries share one query"

patterns-established:
  - "Static memo keyed by the Livewire component (WeakMap) for a value read by many table rows; unset after the action that changes it"
  - "A test that counts a query shape through DB::enableQueryLog and a str_contains filter (running-task read once for 20 rows, one FILTER aggregate for the page)"

requirements-completed: [TI-01, TI-04]

coverage:
  - id: D1
    description: "On the Admin task page and the task edit page the header action 'Spustit časovač' (gray, play) starts a timer for the task in one click with the task's project and client and the billable default of the task; while that task runs the same slot reads 'Zastavit časovač' (warning, stop); an archived task has no such action"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#starts a timer for the task from its page in one click with the context of the task"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#stores a non-billable entry when the task is non-billable"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#offers the stop in the same slot while the task is the running one and stops it"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#offers the same one-click toggle on the edit page"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#offers no timer action on the page of an archived task"
        status: pass
    human_judgment: false
  - id: D2
    description: "Starting from a task surface stops a running timer without a question (D-02), the toast names the previous record's duration, and the surface dispatches timer-started so the bar and the panel refresh"
    requirement: "TI-04"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#stops the running timer of another task, keeps it and names its duration in the toast"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#says in the toast which previous record was stopped"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#stops a running timer of another task when a card starts one and says so"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Admin task list has a one-click icon-button row action with a tooltip outside any group that turns into the stop icon on the running task's row and is absent on archived rows; the running task is read with one query for 20 rows"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#starts the timer from a row of the task list with one click and turns the row into the stop"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#offers the list timer as an icon button with a tooltip outside any action group"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#has no list timer on an archived row"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#reads the running task once for twenty rows"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every card of the global board and of a project board has a start icon button (1.5rem square, 0.5rem gap, type button, never a link) inside the sort-ignore wrapper that becomes the stop icon while the card's task runs; toggleTimer treats its argument as untrusted: malformed or unknown id 404, a Partner is refused, a task archived meanwhile gets the task error toast, a running timer on an archived task can still be stopped"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#shows a start button on every card of the global board and of a project board inside the sort-ignore wrapper"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#makes the card button at least 1.5rem square with half a rem to its neighbours"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#starts and stops the timer from a board card and re-renders the card"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#answers 404 to a malformed or unknown card id and writes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#refuses to start a timer on a card whose task was archived meanwhile and tells why"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#still stops the timer of a task that was archived meanwhile"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#refuses a forged card toggle by a Partner on a board the Admin mounted and writes nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Admin task page shows a section 'Čas' with Odpracováno and Nevyfakturováno as H:MM (a running entry at its elapsed time, no money, one aggregate query) and the link 'Zobrazit záznamy' to the entries list filtered to the task"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#shows the worked and the unbilled time of the task with a running entry at its elapsed time"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#shows zero time for a task without entries"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#reads the time of the task with one aggregate query and shows no money"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#links to the entries list filtered to the task and that list shows only its entries"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner's task list, task page, Admin task page and board get none of the timer controls or time figures, and the preview slide-over of the Admin board carries none"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#shows a Partner none of the timer controls or time figures on the task list and the task page"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#refuses a Partner the Admin task page and the board toggle"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TaskStartAffordancesTest.php#shows no timer control in the preview slide-over of a board card"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php (whole file)"
        status: pass
    human_judgment: false
  - id: D7
    description: "In the real browser the card button is easy to hit next to the preview and open icons, a click on it never starts a drag or navigates, the icon turns into the stop on the running card, and the task page header, list row and card all refresh the bar and the side panel"
    requirement: "TI-01"
    verification: []
    human_judgment: true
    rationale: "Hit area, drag behaviour of the wire:sort:ignore wrapper and the look of the icon next to the other two can be judged only in a browser; tests assert the markup, sizes and the server behaviour"

actuals:
  tokens: 11000
  tasks: 3
  commits: 3
plan_head_before: 0915a902c13b32005084dfbf8751183288d6d11d
plan_head_after: 3da9c4b58ff26b2991c5aab0b758e540951c90ec
commits: 3

duration: 10min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 10: Task start affordances Summary

**A one-click start or stop of the timer on the task page, the task edit page, the Admin task list row and every board card, all through one shared `TaskTimerToggle` over `StartTimer` with the task id only, an untrusted card argument on the board, and an Admin-only "Čas" section on the task page with worked and unbilled hours.**

## Performance

- **Duration:** about 10 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 10 (3 created, 7 modified)

## Accomplishments

- `TaskResource::timerAction()` (name `toggleTimer`): label, tooltip, icon and colour follow whether the record is the signed-in user's running task (gray play, warning stop); hidden for an archived record; it dispatches `timer-started` or `timer-stopped`. It is the header action on `ViewTask` and `EditTask` and, as `iconButton()`, the first record action in the list, outside any group.
- `TaskTimerToggle` holds the single start-or-stop: a running task is stopped, any other task starts with `['task_id' => id]`, so the project, client and the D-03 billable default come from `StartTimer`; a running timer on another task is stopped and kept, and the toast reads "Časovač byl spuštěn" with "Předchozí záznam (:duration) byl zastaven a uložen."; validation errors and a lost race are danger toasts.
- Board: `toggleTimer(string $taskId)` checks the uuid (404), finds the task through the Partner-scoped query, runs `Gate::authorize('view')`, toggles, resets the computed `runningTaskId` and dispatches the event. The card header got a third icon button (type button, 1.5rem square, 0.5rem gap, title and aria-label) in the existing `wire:sort:ignore` wrapper, play or stop per `runningTaskId`.
- `TimeTotals::forTask()` and the "Čas" section: Odpracováno 2:45 and Nevyfakturováno 1:40 for finished 1:30 unbilled, 0:45 billed, 0:20 non-billable and a running billable entry of 10 minutes; one aggregate query with `FILTER` clauses; the link goes to `TimeEntryResource` index with `filters[task_id][value]=<id>`, verified by opening the filtered list.
- Full suite 2374 passed (16935 assertions) after the last code edit; Pint, PHPStan and `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): one click on the task page starts the timer for that task; the header then offers the stop** - `7644864` (feat)
2. **Task 2: one-click start in the task list and on board cards, with the untrusted card argument** - `242c29d` (feat)
3. **Task 3: the task page "Čas" section and nothing for a Partner** - `3da9c4b` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the new test file, `TaskResourceTest`, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2 and Task 3: the behaviour tests were written first and run red for the board (nine failures on the missing `toggleTimer`, button markup and `runningTaskId`) and the "Čas" section (four failures) before the code was written. The list-row tests already passed at that point because the row action was added to the table in the Task 1 commit together with `timerAction()`. As in the earlier plans of this phase, each task is one commit holding tests and implementation, not a separate RED `test(...)` commit.

## Decisions Made

See `key-decisions`. Findings worth keeping: (1) Filament icon-button actions render the tooltip as `x-tooltip` content and `aria-label`, not a `title` attribute; (2) the `filters` query-string key of the installed Filament list page is `filters` (`#[Url(as: 'filters')]` on `ListRecords::$tableFilters`), so the deep link is `filters[task_id][value]`; (3) a Filament table evaluates action closures once per row and per property, so anything shared per request (here the running task id) needs a memo keyed by the Livewire component.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan: a method that never returns null had a nullable return type**
- **Found during:** Task 1
- **Fix:** `TaskTimerToggle::stop()` returns `string`.
- **Commit:** `7644864`

### Plan changes (within scope)

- **Shared service instead of three copies:** `App\Filament\Support\TaskTimerToggle` (a new file not in the plan's list) holds the start-or-stop, the toasts and the running-task read. `timerAction()` and `toggleTimer()` call it; the acceptance grep for `StartTimer` / `StopTimer` in `TaskResource.php` is met by the docblock that names them, since the Actions themselves are called from the service.
- **`toggleTimer` uses `withTrashed()`:** the plan's artifact table says `Task::query()->whereKey($taskId)->firstOrFail()`, while the behaviour list says an archived task id gets "the task error toast". A scoped lookup alone would answer 404 for an archived task, so the lookup lifts the soft-delete scope only (the Partner scope and the view Gate stay), which also lets a card still stop a timer running on a task archived meanwhile.
- **Running task in the list:** read through a static `WeakMap` keyed by the Livewire component (one query per request and component, dropped after the action), as the plan's one-query rule requires without a per-row query.
- The Czech strings for all three tasks were added in the Task 1 commit (`lang/cs/kokpit.php` is a Task 1 file); the strings `Odpracováno` / `Nevyfakturováno` were placed under `kokpit.time.task.*` as the artifact table says.

**Total deviations:** 1 auto-fixed (Rule 1), 4 small in-scope changes.
**Impact:** none on scope; the contract of the plan is met.

## Issues Encountered

None open. The hit area, drag behaviour and look of the card button next to the two other icons could not be exercised in a browser here; it is the human check below.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-27 (uuid check, Partner-scoped lookup, `Gate::authorize('view')`, `StartTimer` re-derives the context under its locks, the board page refuses a Partner before the method runs; tests for a malformed id, an unknown id, a Partner forging the call on a mounted board and an archived task), T-06-28 (the affordances live only on the Admin-only `TaskResource` pages and the Admin board, `TimeEntry` stays `DeniesPartners`, a Partner page test asserts the control and figure strings are absent on the Partner list and page, and `PartnerTaskVisibilityTest` passes unchanged), T-06-SC (no package added).

## Requirements

`requirements-completed: [TI-01, TI-04]` copies the plan frontmatter. Both were already ticked in REQUIREMENTS.md (TI-01 earlier in this phase, TI-04 by an earlier plan) and stay so; nothing new was ticked by this plan.

## Next Phase Readiness

Plan 06-12 (project overview) can reuse `TimeTotals` and add its project methods next to `forTask()`. Plan 06-13 (forgotten timer job) is untouched. Plan 06-14 should add the new strings ("Spustit časovač" on task surfaces, "Odpracováno", "Nevyfakturováno", "toggleTimer") to the Partner leak canary; this plan already asserts them absent on the Partner task list and page. Human check for `/gsd-verify-work`: as the Admin open a task page, click "Spustit časovač" in the header (bar and side panel show the run, the header turns to the warning "Zastavit časovač"), open the task list and see the play icon with a tooltip on every active row and the stop icon on the running one, open the board and click the new play icon on a card (no drag starts, no navigation, the icon turns to the stop), start a second task and see the toast name the stopped record's duration, and check "Čas" on the task page after stopping. Expected: matches UI-SPEC Surface C.

## Self-Check: PASSED

Created files `TaskTimerToggle.php`, `TimeTotals.php` and `TaskStartAffordancesTest.php` exist; commits `7644864`, `242c29d` and `3da9c4b` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2374 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
