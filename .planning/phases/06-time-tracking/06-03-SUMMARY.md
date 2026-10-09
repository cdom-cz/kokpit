---
phase: 06-time-tracking
plan: 03
subsystem: time-tracking
tags: [postgres, advisory-lock, concurrency, partial-unique-index, mutation-run, pest]

requires:
  - phase: 06-time-tracking
    provides: "time_entries table with time_entries_one_running_per_user, StartTimer, StopTimer with an inline per-user lock (plans 06-01, 06-02)"
provides:
  - "TimerLock: the per-user advisory lock as its own container-resolved service, shared by StartTimer and StopTimer"
  - "TimerRaceLost: typed DomainException with the Czech UI-SPEC copy, thrown by StartTimer when a start loses the race on the one-running index"
  - "timer-worker.php and TimerConcurrencyTest: real-process proof of TI-07 / D-02 (8 x 25 starts, and the deterministic 5-round mutation run)"
  - "UnlockedTimerLock: test-only lock double with an arrival rendezvous between the running-row read and the insert"
affects: [06-04, 06-05, 06-06, 06-07, phase-07-api]

tech-stack:
  added: []
  patterns:
    - "A lock a test must remove is its own non-final class resolved from the container (TaskBoard / UnlockedTaskBoard precedent); production classes carry no test hook"
    - "A mutation run forces its interleaving with an arrival barrier on an Eloquent event instead of sleeping or relying on natural timing"
    - "A database unique violation is translated to a typed domain error outside the rolled-back transaction, by constraint name; any other violation is rethrown"

key-files:
  created:
    - app/Domain/TimeTracking/TimerLock.php
    - app/Domain/TimeTracking/TimerRaceLost.php
    - tests/Concurrency/timer-worker.php
    - tests/Concurrency/TimerConcurrencyTest.php
    - tests/Support/UnlockedTimerLock.php
  modified:
    - app/Domain/TimeTracking/Actions/StartTimer.php
    - app/Domain/TimeTracking/Actions/StopTimer.php
    - lang/cs/kokpit.php

key-decisions:
  - "TimerLock is a plain class (not final) resolved from the container, so UnlockedTimerLock extends it; lock() throws LogicException outside a transaction like TaskBoard::lockBoard()."
  - "The mutation run is deterministic: UnlockedTimerLock listens to eloquent.creating on TimeEntry, which fires after the running-row read and before the INSERT, and holds each party until all 8 arrived. Exactly 1 started and 7 raced per round, no retry, no tolerance."
  - "StartTimer translates the unique violation by matching the constraint name in the exception message, outside DB::transaction(), so the rollback is already done and any other unique violation is rethrown unchanged."

patterns-established:
  - "Rendezvous files <dir>/<pid> with a 15 s timeout that fails the round loudly instead of passing by luck"
  - "The worker refuses unsafe argument combinations (rendezvous with the real lock, rendezvous with count != 1) before doing any work"

requirements-completed: [TI-07, TI-01]

coverage:
  - id: D1
    description: "Eight real processes starting 25 timers each for one user leave 200 entries, exactly one running, no finished entry ending before it started, and every stop instant equal to the start instant of another entry of the user; no worker reports a failure or a lost race"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Concurrency/TimerConcurrencyTest.php#leaves exactly one running timer and a gap-free chain when 8 processes start 25 timers each for one user"
        status: pass
    human_judgment: false
  - id: D2
    description: "Without the advisory lock, and with every worker forced to read 'nothing running' before any inserts, exactly 1 of 8 starts wins and 7 surface as TimerRaceLost in each of 5 rounds, leaving one running entry and one entry per round; this proves both the partial unique index backstop and that the harness sees the race"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Concurrency/TimerConcurrencyTest.php#lets exactly one of 8 simultaneous starts win and reports the other 7 as a lost race without the lock (mutation run)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A lost race surfaces as the typed TimerRaceLost with the Czech copy, translated after the rollback; a unique violation on any other index is rethrown (checked in the catch block)"
    requirement: "TI-07"
    verification:
      - kind: unit
        ref: "tests/Concurrency/TimerConcurrencyTest.php (mutation run: raced = 7 with failed = 0 in every round)"
        status: pass
      - kind: command
        ref: "grep time_entries_one_running_per_user app/Domain/TimeTracking/Actions/StartTimer.php; grep start_race lang/cs/kokpit.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "StartTimer and StopTimer take the same per-user TimerLock before any row lock; the worker and the database guard refuse unsafe invocations"
    requirement: "TI-01"
    verification:
      - kind: unit
        ref: "tests/Concurrency/TimerConcurrencyTest.php#refuses the rendezvous together with the real lock or with more than one start"
        status: pass
      - kind: unit
        ref: "tests/Concurrency/TimerConcurrencyTest.php#refuses to run against a database that is not a test database"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimerActionsTest.php (34 cases, green on the extracted lock)"
        status: pass
    human_judgment: false

actuals:
  tokens: 8400
  tasks: 2
  commits: 3
plan_head_before: dbb57d20b2570318fa177da84297943fe56fc18d
plan_head_after: b88760618d9173d29e22d07b814797b8190621f7
commits: 3

duration: 40min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 03: Timer concurrency proof Summary

**Real-process proof that concurrent starts never leave two running timers for one user: a per-user TimerLock service, a deterministic lock-less mutation run (1 started, 7 TimerRaceLost in each of 5 rounds) forced by an arrival rendezvous, and a typed Czech error for the lost race.**

## Performance

- **Duration:** about 40 min
- **Completed:** 2026-10-09
- **Tasks:** 2 (1 tracer, 1 tdd-flagged auto)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `TimerLock` replaces the private inline lock of `StartTimer` and `StopTimer`. Both take it first in their transaction, before the clock and before any row lock, so one user's starts and stops serialize and different users never wait on each other. The key is `kokpit:timer:<user id>`, unchanged from plans 06-01 and 06-02, so no lock behavior moved.
- Locked run: 8 worker processes, 25 starts each behind one shared barrier, leave 200 entries for the user, exactly one running, no finished entry ending before its start, and zero finished entries whose stop instant is not the start instant of another entry of the user (one SQL query). No worker saw a failure or a lost race.
- Mutation run: `UnlockedTimerLock` takes no advisory lock and, given a rendezvous directory and a party count, holds every worker on the Eloquent `creating` event of `TimeEntry`. That event fires inside `StartTimer` after the running row was read and before the INSERT. Each round starts with nothing running, so nobody blocks on a row lock before the rendezvous; all 8 read "nothing running", then the partial unique index lets exactly one insert win. In each of 5 rounds the result is exactly 1 started, 7 `TimerRaceLost`, 0 failed, one running entry, and one entry per round (the lost races rolled back completely). Three consecutive runs of the file were green.
- `TimerRaceLost` (`final`, `DomainException`) carries `kokpit.time.errors.start_race`. `StartTimer` catches `UniqueConstraintViolationException` around the whole `DB::transaction` call and throws it only when the message names `time_entries_one_running_per_user`; anything else is rethrown.
- The worker refuses a database whose name does not end in `_test`, the rendezvous with the real lock (it would deadlock) and the rendezvous with a count other than 1; the test proves the last two and the database guard through child processes.
- Cleanup removes activity rows of the entries (`subject_type = 'time_entry'`, so the test stays green once plan 06-05 adds the activity allowlist), entries, client rows, role pivot, user and the rendezvous directories, then asserts nothing remains.
- Full suite green: 2117 passed (15693 assertions). Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): TimerLock extracted, worker, locked 8 x 25 run** - `4b5ffbf` (feat)
2. **Task 2 RED: UnlockedTimerLock, rendezvous worker arguments, mutation run** - `63c8d87` (test)
3. **Task 2 GREEN: TimerRaceLost, translation in StartTimer, Czech copy** - `b887606` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only and the human-verify mode is end-of-phase, so the verify (concurrency test, timer action tests, Pint, PHPStan) was run end to end and passed before expansion.

## TDD note for Task 2

Genuine RED then GREEN. With the double and the rendezvous in place but `StartTimer` not yet translating, the mutation run failed in round 1 exactly as expected: the losers surfaced as raw `SQLSTATE[23505] ... time_entries_one_running_per_user` (`failed` 1 instead of 0). That RED is also the proof that the rendezvous makes the race observable at the first attempt. After the catch was added, 3 consecutive runs and the full suite were green. `TimerRaceLost` and the Czech string stayed out of the RED commit (untracked) so the RED state was real.

## Decisions Made

See `key-decisions`. The test-only rendezvous replaces the sleep-based widening of the `task-worker.php` precedent with an arrival barrier, which is why the mutation run needs no retry loop and no "at least one round" tolerance.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pint style on StopTimer after removing the inline lock**
- **Found during:** Task 1 (Pint)
- **Issue:** removing the private method left a trailing blank line before the closing brace.
- **Fix:** ran Pint on that one path; no unrelated file changed.
- **Files modified:** app/Domain/TimeTracking/Actions/StopTimer.php
- **Commit:** 4b5ffbf

### Plan additions (within scope)

- The test file also has the worker-refusal cases (rendezvous with the real lock, rendezvous with a count of 2, non-test database) and a shared `timerConcurrencyEnv()` helper, as the behavior list asks for the refusals to be provable.
- `StartTimer::handle()` keeps the transaction closure and wraps it in the try/catch; the constraint name is a private constant.

**Total deviations:** 1 auto-fixed (blocking style, caught before commit).
**Impact:** none on scope or behavior.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-07 (per-user `TimerLock` plus `time_entries_one_running_per_user`, proven with real processes and a deterministic mutation run with exact per-round counts and no retry), T-06-08 (the worker refuses any database whose name does not end in `_test` before doing work, tested through a child process; cleanup removes every committed row and asserts emptiness), T-06-SC (no package added).

## Next Phase Readiness

Ready for 06-04. `StartTimer` can now throw `TimerRaceLost`; the Livewire/Filament start action in a later plan should catch it and show its message as a notification. The Czech copy `kokpit.time.errors.start_race` already exists.

## Self-Check: PASSED

All eight files exist, commits `4b5ffbf`, `63c8d87` and `b887606` are ancestors of HEAD, all task acceptance criteria re-run green, and the plan-level verification (full `vendor/bin/pest` 2117 passed, concurrency file green three times in a row, Pint, PHPStan, `scripts/check-sensitive.sh`) passes.
