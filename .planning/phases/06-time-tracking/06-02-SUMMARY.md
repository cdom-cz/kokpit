---
phase: 06-time-tracking
plan: 02
subsystem: time-tracking
tags: [postgres, share-lock, advisory-lock, billing-resolver, sqlstate, pest]

requires:
  - phase: 06-time-tracking
    provides: "time_entries table, TimeEntry, StartTimer client-only, TimerClock (plan 06-01)"
  - phase: 05-tasks-and-kanban
    provides: "TaskBillingResolver, Project::selectable(), ArchiveTask, CreateTask"
provides:
  - "StartTimer from a task: project and client derived from the share-locked task row, so a forged combination cannot be stored"
  - "TimeEntryInput::context() and description(): the shared context and description rules for StartTimer now and CreateTimeEntry / UpdateTimeEntry in plan 06-04"
  - "BillableDefault::for(?Task): the D-03 billable pre-set from the single TaskBillingResolver"
  - "StopTimer: idempotent stop of the running timer, optional expected entry id, per-user lock"
  - "Czech field errors kokpit.time.errors.task_unavailable, project_unavailable, inconsistent_context"
  - "TimeEntriesTableTest: raw-SQL proof of every time_entries rule by SQLSTATE"
affects: [06-03, 06-04, 06-05, 06-06, 06-07, phase-07-api, phase-10-invoicing]

tech-stack:
  added: []
  patterns:
    - "Context rules for a time entry live in TimeEntryInput::context(), run inside the caller's transaction: task, project, client re-read FOR SHARE in that order, project through Project::selectable()"
    - "Billable default only through BillableDefault, which only calls TaskBillingResolver; an explicit bool from the caller always wins"
    - "StopTimer authorizes create on TimeEntry (refuses a Partner before any query), then update on the found row"

key-files:
  created:
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Billing/BillableDefault.php
    - app/Domain/TimeTracking/Actions/StopTimer.php
    - tests/Feature/TimeTracking/BillableDefaultTest.php
    - tests/Feature/Schema/TimeEntriesTableTest.php
  modified:
    - app/Domain/TimeTracking/Actions/StartTimer.php
    - lang/cs/kokpit.php
    - tests/Feature/TimeTracking/TimerActionsTest.php

key-decisions:
  - "Lock order in StartTimer: per-user timer lock, task FOR SHARE, project FOR SHARE, client FOR SHARE, running entry FOR UPDATE. ArchiveTask takes the board lock then the task row FOR UPDATE and never the timer lock, so no cycle."
  - "Field error keys: task_id for an unknown, archived or malformed task and for a task whose project is not selectable; project_id for a project that is not selectable; client_id for a missing or archived client; inconsistent_context on task_id (task vs project) and on project_id (project vs client)."
  - "StopTimer takes the same inline advisory lock key as StartTimer (kokpit:timer:<user id>); plan 06-03 moves both into TimerLock."
  - "A non-bool billable in the StartTimer payload falls back to the D-03 default instead of being coerced; forms pass a bool."

patterns-established:
  - "A task fixes project and client: a given project_id or client_id is only compared with the derived one"
  - "Mutation runs (temporary, restored) are the RED evidence when tests of an already-implemented behavior are green on first run"

requirements-completed: [TI-01, TI-04, TI-05, TI-07]

coverage:
  - id: D1
    description: "The Admin starts a timer with only a task id; project and client are derived from the task; billable defaults to true"
    requirement: "TI-01"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#starts a timer from only a task id and derives its project and client"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#starts a timer from a project and derives the client"
        status: pass
    human_judgment: false
  - id: D2
    description: "billable is pre-set false only for a resolved non-billable task (own row or inherited from the parent); fixed-price stays true; an explicit value wins"
    requirement: "TI-04"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/BillableDefaultTest.php (6 cases)"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#pre-sets billable to false when the task is non-billable"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#stores an explicit billable value over the default of the task"
        status: pass
    human_judgment: false
  - id: D3
    description: "StopTimer stops at max(now, started_at), is a no-op with nothing running or a stale expected id, and refuses a Partner"
    requirement: "TI-05"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#stops the running timer at the current second and is a no-op the next time"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#does nothing when the expected entry id is not the running entry"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#refuses a Partner who stops a timer and leaves the timer of the Admin running"
        status: pass
    human_judgment: false
  - id: D4
    description: "Archived, forged, malformed and inconsistent task, project and client context is a field error with the UI-SPEC copy, nothing is written and the running timer is untouched; archiving after the start never orphans the running timer"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#refuses an archived task, a task of an archived project and a task of an archived client on task_id and leaves the running timer alone"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#refuses an archived project and a project of an archived client on project_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#answers a malformed uuid in any of the three keys with the field error of that key"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#keeps the running timer running after the task, the project client and the task are archived and stops it normally"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every database rule of time_entries is proven by SQLSTATE through raw SQL: 23514, 23503, 23505, KP001 for edit, delete and TRUNCATE of a billed row, unlock allowed, duration recompute, zero length accepted"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/TimeEntriesTableTest.php (21 cases)"
        status: pass
    human_judgment: false

actuals:
  tokens: 10800
  tasks: 3
  commits: 3
plan_head_before: a06d7ecc34222a7d630c42760114463308fdc459
plan_head_after: 5d74c6be4819a392388c5193aa39cef74aae7203
commits: 3

duration: 35min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 02: Start from a task, stop, billable default Summary

**A timer starts from only a task id with project and client taken from the share-locked task row, billable pre-set from the single billing resolver (false only for a resolved non-billable task, parent inheritance included), an idempotent StopTimer, and every time_entries database rule proven by SQLSTATE through raw SQL.**

## Performance

- **Duration:** about 35 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 1 tdd-flagged auto, 1 auto)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `TimeEntryInput::context()` reads the task, then its project through `Project::selectable()`, then the client, each `FOR SHARE` inside the caller's transaction. A given `project_id` or `client_id` is only compared with the derived value, so a forged combination is a field error and never stored. `TimeEntryInput::description()` carries the 1000-character rule out of `StartTimer` so plan 06-04 reuses it.
- `StartTimer` now takes `client_id`, `project_id`, `task_id`, `description` and `billable`; `billable` is the given bool or `BillableDefault::for($task)`.
- `BillableDefault` is the only reader of the D-03 rule and only calls `TaskBillingResolver`, so inheritance (a subtask under a non-billable parent) and the fixed-price case are the resolver's, not a second copy.
- `StopTimer` takes the same per-user advisory lock as `StartTimer`, reads the clock after it, finds the running row `FOR UPDATE`, returns null for nothing running or a stale expected id, and otherwise stops at `max(now, started_at)`. A Partner is refused by `create` before any query.
- `TimeEntriesTableTest` proves 23514 (task without project, end before start, billed running, billed non-billable, billed_at mismatch both ways, unknown state), 23503 (project of another client, task of another project), 23505 (second running row; another user allowed), KP001 (start, end, client and description edits, delete, TRUNCATE), the unlock, the duration recompute after the unlock (3600 to 7200), 5130 seconds for 10:00:00 to 11:25:30 and the zero-length row.
- Full suite green: 2113 passed. Pint and PHPStan clean.

## Task Commits

1. **Task 1 (tracer): task start, StopTimer, TimeEntryInput, BillableDefault, Czech strings** - `af9143d` (feat)
2. **Task 2: context guards, billable default, archive after start** - `f97bcfc` (test)
3. **Task 3: raw-SQL proof of every time_entries constraint** - `5d74c6b` (test)

Tracer feedback gate: the tracer `<verify>` is automated-only and the human-verify mode is end-of-phase, so the verify (targeted Pest, Pint, PHPStan) was re-run end to end and passed before the expansion tasks.

## TDD note for Task 2 (RED evidence)

Task 2 is `tdd="true"`, but the guards it pins were already implemented by the tracer exactly as the plan's Task 1 steps 1 to 4 prescribe (`selectable()`, share locks, `inconsistent_context`, the resolver call, the expected-id check). All new tests were GREEN on their first run (`unexpected_green`), so no RED failure could be produced honestly and no `test(...)`-then-`feat(...)` pair exists. The plan type is `execute`, so no plan-level gate applies. To prove the tests are not vacuous, five temporary mutations of the committed code were run and then restored with `git checkout -- <file>` (working tree verified clean afterwards):

- dropping `selectable()` from the task's project re-read: the archived-client case failed;
- dropping the project-versus-client comparison: the `inconsistent_context` on `project_id` test failed;
- `BillableDefault::for` returning true: three tests failed (own row, inherited from the parent, StartTimer pre-set);
- ignoring the expected entry id in `StopTimer`: the stale-id test failed;
- dropping the `create` authorization in `StopTimer`: the Partner test failed.

## Files Created/Modified

See `key-files`. Notable: `TimerActionsTest.php` grew from 19 to 34 cases (helpers `timerProject`, `timerTask` build the projects and tasks through the domain Actions so the billing resolver finds its rows).

## Decisions Made

See `key-decisions`. In short: one lock order (timer, task, project, client, running entry) with no cycle against `ArchiveTask`; a task fixes project and client; `StopTimer` shares the inline lock key until 06-03.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Duplicated Czech copy in the wrong block**
- **Found during:** Task 1 (Czech strings)
- **Issue:** a replace of the `client_required` line also hit the same line in the projects error block, adding three unrelated keys there.
- **Fix:** removed the stray lines before committing; only `kokpit.time.errors` carries the new keys.
- **Files modified:** lang/cs/kokpit.php
- **Commit:** af9143d

**2. [Rule 3 - Blocking] Pint run with explicit paths reformatted unrelated lang files**
- **Found during:** Task 1 (Pint)
- **Issue:** `pint app lang tests` (explicit paths) rewrote six untouched `lang/cs/*.php` files.
- **Fix:** restored those six files with `git checkout -- <file>`; they were never staged. `pint --test` without paths (the project configuration) is clean.
- **Commit:** none needed

### Plan additions (within scope)

- Task 2 also tests starting from a project alone, a task together with its own project and client, and an explicit `billable` over the default; they are the remaining branches of `TimeEntryInput::context()`.
- Task 2 produced no source change: see the TDD note above.

**Total deviations:** 2 auto-fixed (both blocking, caught before commit), 1 TDD evidence gap recorded above.
**Impact:** none on scope or behavior.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-04 (the task path derives project and client from the locked task row, every id is re-read with `selectable()` and `sharedLock()`, composite foreign keys as the last line, tests with mismatched and malformed ids), T-06-05 (`TimeEntriesTableTest` proves KP001 for update, delete and TRUNCATE), T-06-06 (both Actions authorize before any write; Partner tests for start and stop).

## Next Phase Readiness

Ready for 06-03: `StartTimer` and `StopTimer` hold the same inline advisory lock key `kokpit:timer:<user id>` and can be moved into `TimerLock` together, and the parallel-process proof still belongs to that plan. Plan 06-04 reuses `TimeEntryInput::context()` and `description()` for manual entries (which will also need a strictly later end).

## Self-Check: PASSED

All five created files exist, commits `af9143d`, `f97bcfc` and `5d74c6b` are ancestors of HEAD, all task acceptance criteria re-run green, and the plan-level verification (full `vendor/bin/pest` 2113 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) passes.
