---
phase: 17-task-project-and-search-ux-refinements
plan: 06
subsystem: signal
tags: [signal, livewire, timer, task-link, idor]
requires:
  - phase: 17-04
    provides: signal_tasks.task_id, SignalTask::task(), the pick and the link
provides:
  - SignalDayReader::tasks() eager-loads the linked task (archived included) in one query
  - SignalDay::day() per-row 'link' data (url, can_time, running)
  - SignalDay::toggleTaskTimer() over the shared TaskTimerToggle
  - SignalDay::timerChanged() with four timer event listeners
  - timer and open-task icon controls in the Signal row
affects: []
tech-stack:
  added: []
  patterns:
    - row id resolved through the owner-scoped model, the task id never taken from the browser
    - repeatable #[On] attributes on one refresh method (as ControlsTimer::refreshState)
key-files:
  created: []
  modified:
    - app/Domain/Signal/Queries/SignalDayReader.php
    - app/Livewire/Signal/SignalDay.php
    - resources/views/livewire/signal/day.blade.php
    - tests/Feature/Signal/SignalTaskLinkTest.php
    - tests/Isolation/SignalIsolationTest.php
key-decisions:
  - "R-17-06-1..5 of the plan implemented as written; the eager load selects only id, reference, status, deleted_at"
  - "toggleTaskTimer refuses a start on an archived or Done task unless it is the running one, so a stale row can still stop its own timer"
patterns-established:
  - "Linked-row controls sit in their own wire:sort:ignore container outside the done styling and the can_edit condition"
requirements-completed: [P17-07]
status: complete
commits: 2
plan_head_before: 31923ad183e010a9fcca7458f0e1d68528e1cb93
plan_head_after: 3364dd4286293b477420144a82f4fc4816414b60
actuals:
  tokens: 14000
  tasks: 2
  commits: 2
---

# Phase 17 Plan 06: Signal row timer and open-task controls Summary

A linked Signal row now shows the shared task timer (play/stop through TaskTimerToggle) and an open-task icon; every Signal card re-reads on the four timer events, so Today, Tomorrow and the top bar agree.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: open-task link and play button on a linked row, shared timer start/stop | f3a5898 |
| 2 | State rules, sync, locked/done rendering, forged and foreign rows (tests) | 3364dd4 |

`timerChanged()` and its four `#[On]` attributes were written together with Task 1 (same component file) and are covered by the Task 2 tests; Task 2's commit therefore contains only tests.

## What was built

- `SignalDayReader::tasks()` adds `with('task' => ...)` using `withoutGlobalScopes([SoftDeletingScope::class])` (never a bare `withoutGlobalScopes()`), selecting `id, reference, status, deleted_at`: one extra query per day however many rows are linked.
- `SignalDay::day()` reads the running task id once per render and adds `link` to each row: null for a plain signal or a hard-deleted task, otherwise `url` (TaskResource view), `can_time` (not archived, not Done) and `running`.
- `SignalDay::toggleTaskTimer(string $signalTaskId)` runs through `attempt()`: non-UUID or a row not found with the owner-scoped query for the shown day gives the "Záznam už neexistuje." notification; the task comes from the row's own `task_id` (`withTrashed`), Gate `view`, a start on an archived/Done task not already running is refused with `task_unavailable`, otherwise `TaskTimerToggle::handle()` and the returned event is dispatched. No planner row is written and `signal-changed` is not dispatched.
- View: after the recurring icon, a `wire:sort:ignore` container (flex, no shrink) with the play/stop `x-filament::icon-button` (gray play, warning stop, `aria-pressed`, label and tooltip "Spustit/Zastavit časovač") when `can_time`, and always the `tag="a"` open-task icon "Otevřít úkol". It ignores the day lock and the done state; the title stays plain text.

## Verification status

Per project rules (worktree), only cheap checks ran here: `php -l` on all touched PHP files, Pint `--test` (clean after one auto-fix of `SignalDay.php` import order/unary spacing), `scripts/check-sensitive.sh` (clean), grep acceptance counts (`function toggleTaskTimer` 1, `aria-pressed` 3, `#[On('` 4, `function timerChanged` 1, `can_time` 1, `toggleTaskTimer` in the isolation test 1).

NOT run here, to be run by the orchestrator after the merge (shared DDEV DB):
- `ddev exec vendor/bin/pest tests/Feature/Signal/SignalTaskLinkTest.php tests/Feature/TimeTracking/TimerActionsTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Signal/SignalTaskLinkTest.php tests/Isolation/SignalIsolationTest.php tests/Feature/Signal/SignalScreensTest.php`
- plan gate: `ddev exec vendor/bin/pest tests/Feature/Signal tests/Feature/TimeTracking tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (runtime to be recorded at first run).

The new Pest cases were written blind (no DB in the worktree). Likely spots to adjust if one fails: the row-markup regex helper `sigLinkRow()` (depends on `wire:key="task-<id>"` surviving in `Livewire::test()->html()`), and the query-count case (uses `DB::enableQueryLog()` around `call('timerChanged')`).

## Deviations from Plan

None - plan executed as written, apart from the commit split noted above (timerChanged shipped in Task 1's commit).

## Known Stubs

None.

## Threat Flags

None. `toggleTaskTimer` is a new public Livewire endpoint but it is the surface the plan's threat model already covers (T-17-17 owner-scoped row lookup, T-17-18 refusal on Done/archived, T-17-19 RequiresAdmin unchanged).

## Human check pending

Task 2's `human-check` (cross-card refresh in a live browser, narrow layout with long text, completion in another tab) is not performed by this agent.

## Self-Check: PASSED

- Files modified exist; commits f3a5898 and 3364dd4 are ancestors of HEAD.
