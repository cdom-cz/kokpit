---
phase: 06-time-tracking
plan: 04
subsystem: time-tracking
tags: [laravel, postgres, domain-actions, row-lock, guard-trigger, dst, pest]

requires:
  - phase: 06-time-tracking
    provides: "time_entries table with guard trigger, TimeEntryInput::context, BillableDefault, TimerClock, StartTimer/StopTimer (plans 06-01 to 06-03)"
provides:
  - "CreateTimeEntry: manual creation of a finished entry (client required, project and task optional, exact whole seconds)"
  - "UpdateTimeEntry and DeleteTimeEntry: row re-read FOR UPDATE, billed entry refused, KP001 translated to the same Czech DomainException"
  - "TimeEntryInput::instant, assertEndAfterStart, locked, isFrozenRowRefusal: the shared time parsing and billed-lock rules"
  - "OverlapFinder::first: non-blocking overlap lookup with strict inequalities"
  - "DurationFormat: the single H:MM / H:MM:SS truncating display rule"
affects: [06-05, 06-06, 06-07, 06-08, 06-09, phase-07-api]

tech-stack:
  added: []
  patterns:
    - "Instants are moved to the application timezone before they reach a model cast, because the cast writes the wall clock of the instant's own zone"
    - "A guard-trigger refusal (SQLSTATE KP001) is translated to a typed domain error outside the rolled-back transaction; the application check under the row lock comes first"
    - "A payload that names a new task or project derives the ids below it unless the payload names them too"

key-files:
  created:
    - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
    - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
    - app/Domain/TimeTracking/Actions/DeleteTimeEntry.php
    - app/Domain/TimeTracking/Queries/OverlapFinder.php
    - app/Domain/TimeTracking/Support/DurationFormat.php
    - tests/Feature/TimeTracking/TimeEntryActionsTest.php
    - tests/Unit/TimeTracking/DurationFormatTest.php
  modified:
    - app/Domain/TimeTracking/TimeEntryInput.php
    - lang/cs/kokpit.php

key-decisions:
  - "Manual creation always makes a finished entry: Konec is required; a running entry exists only through StartTimer."
  - "No cap on duration and no ban on future times (research A12); a typo is corrected by editing."
  - "Update: absent keys keep the stored value, billable included. The context is re-checked only when a named id differs from the stored one."
  - "Update: naming a new task derives project and client, naming a new project derives the client, unless the payload names them; clearing the task alone keeps project and client."
  - "Update: the end-after-start check runs only when the start or end actually changes, so a zero-length entry from a same-second timer start still allows a description edit."
  - "DeleteTimeEntry accepts a running entry and refuses a billed one."
  - "The KP001 detection and the locked DomainException live in TimeEntryInput, shared by Update and Delete."

patterns-established:
  - "OverlapFinder: started_at < COALESCE(to, infinity) and COALESCE(ended_at, infinity) > from, plus a zero-length guard on the stored side and an early return for a zero-length query"
  - "Trigger-backstop test: an Eloquent updating/deleting listener bills the row between the locked read and the write, so the KP001 path is exercised deterministically"

requirements-completed: [TI-02, TI-03, TI-07, TI-08]

coverage:
  - id: D1
    description: "The Admin records a finished entry with only a client by hand; times in UTC wall clock or ISO 8601 with an offset are stored in exact whole seconds, a fraction is truncated"
    requirement: "TI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#records a finished client-only entry in exact seconds"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#truncates a fractional second instead of rounding it"
        status: pass
    human_judgment: false
  - id: D2
    description: "Creation rules: client required, Konec required and strictly after Začátek, unparsable times and impossible days are field errors, context and billable default as for a start, DST days store exactly 7200 s"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#refuses an end equal to or before the start with the field error ended_at"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#stores exact seconds across a daylight saving change"
        status: pass
    human_judgment: false
  - id: D3
    description: "UpdateTimeEntry edits an unbilled entry (times, description, context, billable), keeps a running entry running, and does not re-check an unchanged context"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#edits a running entry and keeps it running even when the payload names an end"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#does not re-check an unchanged context, so a description edit survives an archived task"
        status: pass
    human_judgment: false
  - id: D4
    description: "A billed entry is refused by update and delete under the row lock with the Czech locked message, and a KP001 from the guard trigger that slips past the lock is translated to the same message (mutation-checked)"
    requirement: "TI-07"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#refuses to update or delete a billed entry and leaves it unchanged"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#translates a guard trigger refusal that slips past the lock into the same message"
        status: pass
    human_judgment: false
  - id: D5
    description: "Overlapping entries are never blocked; OverlapFinder names the first overlap with strict inequalities, treats a running entry as open-ended and a zero-length entry as overlapping nothing"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#finds an entry that overlaps and ignores one that only touches"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#lets each of three mutually overlapping entries find one of the other two"
        status: pass
    human_judgment: false
  - id: D6
    description: "DurationFormat is the single display rule: H:MM truncated, H:MM:SS, unbounded hours, minus sign, integer arithmetic only"
    requirement: "TI-08"
    verification:
      - kind: unit
        ref: "tests/Unit/TimeTracking/DurationFormatTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "A Partner is refused by create, update and delete before anything is written (Admin-only policy)"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#refuses a Partner on update and delete and leaves the entry untouched"
        status: pass
    human_judgment: false

actuals:
  tokens: 14500
  tasks: 3
  commits: 3
plan_head_before: 16f7ed98cd9d0671345977f57703f19dee72f17b
plan_head_after: c4d4ae8b0b30c1dceab22c731c0b357bd792f337
commits: 3

duration: 30min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 04: Manual entries and the duration format Summary

**Manual time entries through domain Actions: create a finished client-only entry in exact seconds (UTC wall clock or ISO 8601 with offset), edit and delete under a row lock that refuses billed rows (KP001 translated), a non-blocking overlap lookup, and one truncating H:MM / H:MM:SS format.**

## Performance

- **Duration:** about 30 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 9 (7 created, 2 modified)

## Accomplishments

- `CreateTimeEntry` records a finished entry from a client, with optional project, task and description. `billable` is the given bool or the D-03 default of the task. Ids and instants are set with `forceFill`. No cap on duration and no ban on future times (research A12).
- `TimeEntryInput::instant()` parses a `CarbonInterface`, an ISO 8601 string with `Z` or an offset, or `Y-m-d H:i:s` in the application timezone, with an optional fraction. Parsing is strict (a day that does not exist is a field error, not a rollover). The result is truncated to the whole second and moved to the application timezone. `assertEndAfterStart()` makes Konec strictly later than Začátek (`ended_at` field error).
- `UpdateTimeEntry` and `DeleteTimeEntry` re-read the row `FOR UPDATE`, refuse a billed entry with `kokpit.time.errors.locked`, and translate a `KP001` QueryException from the guard trigger to the same message. The update keeps absent keys, re-checks the context only when a named id changes, keeps a running entry running, and edits a zero-length entry's description without tripping the end-after-start rule.
- `OverlapFinder::first()` uses `started_at < COALESCE(to, infinity)` and `COALESCE(ended_at, infinity) > from`; a zero-length interval overlaps nothing on either side; ordered by `started_at`, `id`; client, project and task loaded with archived rows.
- `DurationFormat` gives `0:00`, `1:25`, `38:05` and `0:07:42`, `123:45:07`, truncating, with the sign kept for a negative value. Pure integer arithmetic; the Unit test needs no application.
- 70 new test cases (51 in the actions file including the tracer, 19 in the duration unit test). Full suite 2187 passed (15873 assertions). Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): CreateTimeEntry, instant parsing, Czech strings** - `5f5dad5` (feat)
2. **Task 2: UpdateTimeEntry, DeleteTimeEntry, OverlapFinder, billed lock, DST, overlaps** - `e7e12ae` (feat)
3. **Task 3: DurationFormat and its unit test** - `c4d4ae8` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only and the human-verify mode is end-of-phase, so the verify (actions test, Pint, PHPStan) was run end to end and passed before expansion.

## TDD note

Tasks 2 and 3 were run test-first: the Task 2 tests were written and run against missing classes (all update, delete and overlap cases failed with `BindingResolutionException`) before the Actions existed, and the Task 3 unit test failed on the missing class before `DurationFormat` was written. The failing run was not committed separately; each task is one commit with tests and implementation together, matching the plan's three-commit shape. The KP001 translation test was mutation-checked: disabling the catch in `UpdateTimeEntry` made it fail.

## Decisions Made

See `key-decisions`. The Prague wall clock never reaches these Actions directly: Filament dehydrates the pickers to UTC (`Y-m-d H:i:s` in the app timezone), and an ISO string with an offset covers the API of Phase 7.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] ISO instant with an offset was stored two hours late**
- **Found during:** Task 1 (first test run)
- **Issue:** the model cast writes the wall clock of the instant's own timezone, so `10:00:00+02:00` was stored as `10:00:00` UTC and tripped `time_entries_end_check` against an end given in UTC.
- **Fix:** `TimeEntryInput::instant()` moves every parsed instant (and every `CarbonInterface` input) to the application timezone after truncating, through one private `normalised()` helper.
- **Files modified:** app/Domain/TimeTracking/TimeEntryInput.php
- **Commit:** 5f5dad5

**2. [Rule 1 - Bug] Zero-length entry could not have its description edited**
- **Found during:** Task 2 design (the plan's own rules, applied to a timer stop in the same second as its start)
- **Issue:** checking end-after-start on every update would refuse any edit of a legitimate zero-length entry created by `StartTimer`.
- **Fix:** `UpdateTimeEntry` asserts end-after-start only when the start or the end actually changes.
- **Files modified:** app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
- **Commit:** e7e12ae

### Plan additions (within scope)

- `TimeEntryInput` also carries `locked()` and `isFrozenRowRefusal()`, so Update and Delete share one translation of the billed lock.
- Task 2 and Task 3 were committed as single test-plus-implementation commits rather than separate RED and GREEN commits.

**Total deviations:** 2 auto-fixed (both bugs caught by tests before commit).
**Impact:** none on scope; both fixes are inside the planned files.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-09 (`TimeEntryInput::context` with `selectable()` and share locks on every changed context; forged combinations are `inconsistent_context` field errors on the bare data key, tested for create and update), T-06-10 (row re-read `FOR UPDATE`, `BillingState::Billed` refused, KP001 translated and mutation-checked), T-06-11 (1000-character description rule tested at 1000 and 1001; unparsable times and impossible days are field errors), T-06-SC (no package added).

## Requirements

`requirements-completed` copies the plan frontmatter. In REQUIREMENTS.md only TI-07 was already ticked. TI-02 (the entry screens in plans 06-06 and 06-07), TI-03 (the entry form) and TI-08 (rate resolution and the billing snapshot, plans 06-05 and later) stay open because their full scope is not yet delivered.

## Next Phase Readiness

Ready for 06-05. The entry screens (06-06, 06-07), the running-entry modal (06-08) and the Phase 7 API call `CreateTimeEntry`, `UpdateTimeEntry`, `DeleteTimeEntry`, `OverlapFinder` and `DurationFormat`. Callers must catch the `DomainException` of the billed lock and show its message, and map `ValidationException` keys (`client_id`, `project_id`, `task_id`, `description`, `started_at`, `ended_at`) to form state paths.

## Self-Check: PASSED

All seven created files exist, commits `5f5dad5`, `e7e12ae` and `c4d4ae8` are ancestors of HEAD, all task acceptance criteria re-run green, and the plan-level verification (full `vendor/bin/pest` 2187 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) passes.
