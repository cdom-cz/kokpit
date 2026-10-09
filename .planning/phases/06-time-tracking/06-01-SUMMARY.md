---
phase: 06-time-tracking
plan: 01
subsystem: time-tracking
tags: [postgres, generated-column, partial-unique-index, advisory-lock, guard-trigger, canary-harness, pest]

requires:
  - phase: 05-tasks-and-kanban
    provides: "tasks table, TaskBoard advisory-lock style, Immutability guard builders, CanaryRegistry harness"
provides:
  - "time_entries table: composite FKs (project_id, client_id) and (task_id, project_id), five CHECKs, partial unique index time_entries_one_running_per_user, stored generated duration_seconds, frozen-row guard trigger (mutable: billing_state, billed_at, duration_seconds) and truncate guard"
  - "projects_id_client_unique and tasks_id_project_unique composite unique keys"
  - "TimeEntry model (Admin-only, DeniesPartners, AdminOnlyPolicy), BillingState storage enum, TimeEntryFactory"
  - "TimerClock: the single whole-second truncating clock of every time write"
  - "StartTimer Action: client-only start, stops the running entry at the same instant under a per-user advisory lock"
  - "morph alias time_entry, canary fixture and registry entries for TimeEntry"
affects: [06-02, 06-03, 06-04, 06-05, 06-06, 06-07, 06-08, 06-09, 06-10, 06-11, 06-12, 06-13, 06-14, phase-07-api, phase-08, phase-10-invoicing]

tech-stack:
  added: []
  patterns:
    - "All time instants are truncated (never rounded) through TimerClock before they reach timestamptz(0)"
    - "Per-user pg_advisory_xact_lock key kokpit:timer:<user id>, clock read after the lock, running row lockForUpdate"
    - "A running entry ahead of the clock is stopped at max(now, started_at), so ended_at >= started_at cannot fail"
    - "Database-refusal tests run each statement in a savepoint (DB::transaction) so the test transaction survives"

key-files:
  created:
    - database/migrations/2026_10_11_000100_create_time_entries_table.php
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - app/Domain/TimeTracking/Enums/BillingState.php
    - app/Domain/TimeTracking/Support/TimerClock.php
    - app/Domain/TimeTracking/Actions/StartTimer.php
    - database/factories/TimeEntryFactory.php
    - tests/Feature/TimeTracking/TimerActionsTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - lang/cs/kokpit.php

key-decisions:
  - "CHECK ended_at >= started_at (zero length allowed): a start in the same second as the running entry keeps the stopped zero-length entry (research A1). Manual entries (06-04) will require a strictly later end."
  - "duration_seconds stays in the guard's mutable list because PostgreSQL computes generated columns after BEFORE triggers; without it even the billing unlock is refused (research Pitfall 1). Phase 10 must re-create the guard with an extended list when it adds snapshot columns."
  - "TimeEntry relations to client, project and task use withTrashed(): archiving never stops a running timer or hides tracked time."
  - "The per-user advisory lock is inline in StartTimer for now; plan 06-03 moves it into a swappable TimerLock service."
  - "BillingState is a storage enum without a label; display labels come with 06-07's BillingBadge."

patterns-established:
  - "TimeEntry ids, instants and billing state are not fillable; Actions use forceFill"
  - "Czech error copy lives under kokpit.time.errors"

requirements-completed: [TI-01, TI-03, TI-07, TI-08]

coverage:
  - id: D1
    description: "The Admin starts a client-only timer; a second start stops the running entry at the same instant and keeps it; exactly one entry of the user stays running"
    requirement: "TI-03"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#starts a client-only timer at the current second"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#stops the running timer at the instant the next one starts and keeps it"
        status: pass
    human_judgment: false
  - id: D2
    description: "The database refuses a second running entry, an end before the start, a project of another client, a task of another project, a task without a project, and edits of a billed row; the unlock and duration recompute pass"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#refuses in the database (6 cases)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Durations are exact whole seconds: fractional instants are truncated not rounded, same-second start keeps a zero-length entry, clock skew never violates the end check"
    requirement: "TI-08"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#truncates a fractional start and stop instead of rounding them up"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#keeps a zero-length entry when a start comes in the same second as the running one"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#stops a running entry that lies ahead of the clock at its own start, never before it (clock skew)"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner reads no time entry and a Partner calling StartTimer is refused before anything is written; the canary registry holds a TimeEntry fixture per client"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php#lets a Partner read no time entry at all"
        status: pass
      - kind: unit
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
      - kind: unit
        ref: "tests/Arch/ModelDeclarationTest.php"
        status: pass
    human_judgment: false

actuals:
  tokens: 27700
  tasks: 3
  commits: 3
plan_head_before: d34c2dc7579b6b1caa8e93a458ed4e139add1811
plan_head_after: a43fde5a2b60f02d48f36884a5394eb82a882130
commits: 3

duration: 30min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 01: Time entries tracer Summary

**time_entries with every consistency rule in PostgreSQL (composite FKs, one running timer per user, generated exact-second duration, billed-row guard), an Admin-only TimeEntry and a StartTimer Action that stops the running timer at the same truncated instant under a per-user advisory lock.**

## Performance

- **Duration:** about 30 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 auto of which one tdd-flagged)
- **Files:** 13 (7 created, 6 modified)

## Accomplishments

- One migration adds `projects_id_client_unique` and `tasks_id_project_unique`, then creates `time_entries`: composite foreign keys `(project_id, client_id)` and `(task_id, project_id)`, the task-needs-project, end, state, billed-at and billed-finished CHECKs, the partial unique index `time_entries_one_running_per_user`, the supporting indexes, the stored generated `duration_seconds`, and the frozen-row guard plus truncate guard.
- `TimerClock` is the single clock of every time write (`startOfSecond()`); `timestamptz(0)` would otherwise round a fractional second up.
- `StartTimer` authorizes first, then in one transaction takes `pg_advisory_xact_lock` for `kokpit:timer:<user id>`, reads the clock after the lock, validates the client under a share lock, stops the running row at `max(now, started_at)` and inserts the new entry. Both entries come back refreshed, so `duration_seconds` is loaded from the database.
- `TimeEntry` denies Partners, `AdminOnlyPolicy` is registered, the morph alias `time_entry` exists, and the canary registry has a TimeEntry fixture per client; the registry and model-declaration scans were extended.
- Full suite green: 2066 passed. Pint and PHPStan clean.

## Task Commits

1. **Task 1 (tracer): table, model, StartTimer** - `bedd8bc` (feat)
2. **Task 2: morph map, canary fixture, registry scans** - `9de2462` (feat)
3. **Task 3: exact seconds at the edges** - `a43fde5` (test)

Tracer feedback gate: the tracer `<verify>` is automated-only and the human-verify mode is end-of-phase, so the verify was re-run end to end and passed before expansion. Tracer verified end-to-end, expanded.

## TDD note for Task 3 (RED evidence)

Task 3 is `tdd="true"`, but the behaviors it pins were already implemented by the tracer in Task 1 exactly as the plan's Task 1 step 3 prescribes (clamp `max(now, started_at)`, refresh of the stopped entry, truncating `TimerClock`). The five new tests were therefore GREEN on their first run (`unexpected_green`); no RED failure could be produced honestly, and no `test(...)`-then-`feat(...)` pair exists. The plan type is `execute`, not `tdd`, so no plan-level gate applies. To prove the tests are not vacuous, three temporary mutations of the committed code were run and then restored (working tree verified clean afterwards):

- `TimerClock::now()` rounding instead of truncating: the fractional-instant test failed.
- the stop at `$now` instead of `max(now, started_at)`: the clock-skew test failed.
- dropping the `refresh()` of the stopped entry: five tests failed (duration not loaded).

## Files Created/Modified

See `key-files` above. Notable: `tests/Feature/TimeTracking/TimerActionsTest.php` holds 19 cases (start, stop and keep, Partner isolation, field errors, six database-refusal cases, five exact-second edge cases).

## Decisions Made

See `key-decisions`. In short: zero-length entries are allowed by the CHECK (`>=`), `duration_seconds` is in the guard's mutable list, relations include archived rows, the advisory lock is inline until 06-03.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Savepoints for multi-refusal tests**
- **Found during:** Task 1 (database-refusal tests)
- **Issue:** after the first refused statement PostgreSQL aborts the surrounding test transaction (SQLSTATE 25P02), so a second refusal in the same test failed with a misleading error.
- **Fix:** a `timerRefused()` helper wraps each expected-to-fail statement in `DB::transaction` (a savepoint).
- **Files modified:** tests/Feature/TimeTracking/TimerActionsTest.php
- **Commit:** bedd8bc

**2. [Rule 1 - Bug] PHPStan: always-false null check in the factory**
- **Found during:** Task 1 (PHPStan)
- **Issue:** `TimeEntryFactory::configure()` compared the non-nullable `started_at` to null.
- **Fix:** removed the null check for `started_at`; `ended_at` and `billed_at` keep theirs.
- **Files modified:** database/factories/TimeEntryFactory.php
- **Commit:** bedd8bc

### Plan additions (within scope)

- Task 1 test file also covers the database-level refusals named in the must-haves (second running row, end before start, composite FK mismatches, task without project, billed guard with unlock and duration recompute) even though the plan's tracer list names only the Action cases. They test the migration the task creates.
- Task 3 produced no `StartTimer` or `TimerClock` change: see the TDD note above.

**Total deviations:** 2 auto-fixed (1 blocking test helper, 1 bug), 1 TDD evidence gap recorded above.
**Impact:** none on scope or behavior.

## For the owner

REQUIREMENTS.md TI-07 says "end after start" (strict) while ROADMAP.md Phase 6 success criterion 2 says the database rejects an end "before the start". This plan keeps `ended_at >= started_at` in the database (strict `>` belongs to forms and the Phase 7 API) so a same-second auto-stop works. Decide whether TI-07 should read "end not before start" or whether zero-length auto-stopped entries must be removed. No requirement file was changed. Plan 06-14 lists the item as well.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The plan's threat register is covered: T-06-01 (DeniesPartners, AdminOnlyPolicy, canary fixture, authorize before write), T-06-02 (UUID check, share-locked re-read with the soft-delete scope, ids only through forceFill), T-06-03 (partial unique index plus advisory lock; the parallel-process proof is plan 06-03).

## Next Phase Readiness

Ready for 06-02. `TimeEntry`, `TimeEntryFactory`, `TimerClock` and the `time_entries` schema are in place; 06-03 moves the inline advisory lock into `TimerLock` and adds the concurrency proof; Phase 10 must extend the guard's mutable list when it adds snapshot columns.

## Self-Check: PASSED

All seven created files exist, commits `bedd8bc`, `9de2462` and `a43fde5` are ancestors of HEAD, all task acceptance criteria re-run green, and the plan-level verification (full `vendor/bin/pest` 2066 passed, Pint, PHPStan) passes.
