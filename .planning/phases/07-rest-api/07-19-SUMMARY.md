---
phase: 07-rest-api
plan: 19
subsystem: time-tracking
tags: [postgres, elapsed-seconds, overflow, gap-closure, filament, time-totals]

requires:
  - phase: 07-rest-api
    provides: "07-13 write guard on a running entry's start (TimeEntryInput::assertDurationFits)"
provides:
  - "TimeEntry::elapsedSecondsSql(alias): the one overflow-proof elapsed-seconds SQL expression"
  - "scopeWithElapsedSeconds and both TimeTotals readers on that expression"
  - "Regression tests reproducing G-07-1 through the API, the Admin pages, the Action layer and the totals"
affects: [time-tracking, billing, admin-panel]

plan_head_before: 3f7ed3c9fc3f6d0b49bc8e2c21468b2ee366e1f5
plan_head_after: a93fd41542458141e016c2caca1334b4eb1746e5

actuals:
  tokens: 5400
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One shared SQL expression per derived value (computed wide in bigint, clamped, then cast), reused by every reader"

key-files:
  created: []
  modified:
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - app/Domain/TimeTracking/Queries/TimeTotals.php
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
    - tests/Feature/Api/TimeEntryWriteTest.php
    - tests/Feature/TimeTracking/TimeEntryListTest.php
    - tests/Feature/TimeTracking/TimeEntryActionsTest.php
    - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php

key-decisions:
  - "Fix on the read side: elapsedSecondsSql computes the epoch difference as bigint, floors at 0, caps at TimeEntryInput::MAX_DURATION_SECONDS and only then casts to int, so the read no longer depends on the margin of the write guard"
  - "The 07-13 write bound stays unchanged (D-09); it is now documented as a sanity bound, and the read clamp shares its constant so the two cannot drift"
  - "PHP readouts (ControlsTimer, NotifyLongRunningTimers) are not changed: they use 64-bit PHP integers and cannot overflow"
  - "StopTimer on an overrun entry is left to the owner (T-07-77): the recovery path is correct the start through UpdateTimeEntry, then stop"

patterns-established:
  - "Every SQL reader of elapsed seconds goes through TimeEntry::elapsedSecondsSql; it is the only EXTRACT(EPOCH in app/"

requirements-completed: [AP-02]

coverage:
  - id: D1
    description: "G-07-1 closed: a running entry PATCHed to a start 2147483645 s old stays readable after the clock passes the integer limit (API read-back and scope)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#keeps the entry list readable after a running start accepted at the edge of the limit runs past it (G-07-1)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Admin entry list, view and edit pages render the overrun running entry (596523:14 and 596523:14:07) with an exact total beyond the integer range"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#lists a running entry that has run past the integer range with the clamped duration and an exact total (G-07-1)"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#shows and edits a running entry that has run past the integer range (G-07-1)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The scope clamps any stored running start (year 1, ahead of now, normal), the limit boundary keeps reading after one more second, and an overrun entry can be corrected and stopped"
    requirement: AP-02
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#reads the elapsed seconds of any stored running start without overflow"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#bounds the start of a running entry against the current second in both directions, exactly at the column limit"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#lets the Admin correct a running entry that has run past the limit and then stop it"
        status: pass
    human_judgment: false
  - id: D4
    description: "TimeTotals (task page figures, project stats row, project task tab) stay readable with a running entry far beyond the integer range and sum in bigint"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/ProjectTimeOverviewTest.php#elapsed seconds beyond the integer range"
        status: pass
    human_judgment: false

duration: 45min
completed: 2026-10-14
status: complete
---

# Phase 07 Plan 19: Overflow-proof elapsed seconds Summary

**One bigint-computed, clamped SQL expression (`TimeEntry::elapsedSecondsSql`) now feeds the entry list scope and both time-totals readers, so no stored running start can raise SQLSTATE 22003 any more.**

## Performance

- **Duration:** about 45 min
- **Completed:** 2026-10-14
- **Tasks:** 2
- **Files modified:** 8 (4 app, 4 tests)

## Accomplishments

- G-07-1 (07-UAT.md test 1), 07-VERIFICATION.md truth 7 and 07-REVIEW.md WR-02 are closed. The UAT sequence (PATCH a start 2147483645 s old, let 4 s pass) is now an automated test and passes.
- `TimeEntry::elapsedSecondsSql(alias)` is the single place for the elapsed-seconds SQL: stored `duration_seconds` first, otherwise the epoch difference cast to bigint, floored at 0, capped at `TimeEntryInput::MAX_DURATION_SECONDS`, and only then cast to int. It is the only `EXTRACT(EPOCH` left in `app/`.
- `scopeWithElapsedSeconds` and `TimeTotals` (`aggregate()` and `taskSum()`, aliases `time_entries` and `entry`) use it. The two private copies in `TimeTotals` are gone. Sums stay readable beyond the integer range because PostgreSQL `SUM(int)` is bigint.
- The Admin list, view and edit pages render the overrun entry as 596523:14 and 596523:14:07, with an exact footer total of 2147483647 + 3600.
- The docblocks of `MAX_DURATION_SECONDS`, `assertDurationFits` and `UpdateTimeEntry` no longer claim the write guard keeps the list readable; the running-start bound is described as a sanity bound. No code of the write bound changed (D-09, same field keys and message).
- No wire change: `git grep elapsed_seconds -- app/Http` is empty.

## Task Commits

1. **Task 1: Tracer, the scope reads an overrun running entry** - `18a9c2b` (fix)
2. **Task 2: TimeTotals on the one expression, docblocks corrected** - `a93fd41` (fix)

## RED Evidence

Before the change every new test failed with the reproduced gap, for example
`SQLSTATE[22003]: Numeric value out of range: 7 ERROR: integer out of range` on
`COALESCE(time_entries.duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (... - time_entries.started_at))::int))`.
Task 1: 6 failed (API read-back, list, view/edit, boundary extension, year-1 and year-9999 dataset cases). Task 2: 3 failed (TimeTotals, stats row and task tab, future start). The recovery test (correct the start, then stop) already passed before the change because it corrects the start before reading; it stays as the T-07-77 evidence.

## Verification

- `pest tests/Feature/Api tests/Isolation tests/Concurrency tests/Feature/TimeTracking`: 948 passed (6585 assertions), including the known combined-run `TimerBarTest` case.
- Pint clean. PHPStan shows only the two known pre-existing `cast.string` errors (`ResetAdminTwoFactorCommand.php` line 38, `StorageCheckCommand.php` line 33).
- `scripts/check-sensitive.sh` clean on both commits; the hook ran (no bypass).
- Acceptance greps of both tasks pass (one `EXTRACT(EPOCH` in `app/`, no `started_at))::int`, no `elapsed_seconds` in `app/Http`, stale guard wording gone, `MAX_DURATION_SECONDS = 2147483647` unchanged).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Test bug] Boundary test clock left one second ahead**
- **Found during:** Task 1 (GREEN run)
- **Issue:** The extended boundary test moves the clock one second on to prove the clamp; the refusal checks that follow are written against the original `$now`, so one of them was accepted and the test failed.
- **Fix:** Travel back to the original second after the clamp assertion, so the refusals stay exactly as they were.
- **Files modified:** tests/Feature/TimeTracking/TimeEntryActionsTest.php
- **Commit:** 18a9c2b

**Total deviations:** 1 auto-fixed (1 test bug). **Impact:** none on the product code or the plan scope.

## Issues Encountered

None.

## Decisions Made

See `key-decisions` in the frontmatter. Notably the clamp ceiling is the same constant as the write bound, and PHP readouts that use 64-bit integers are deliberately left as they are (an entry that has run for 68 years shows a few more seconds in the top bar than in the clamped list).

## Threat Flags

None. T-07-74 and T-07-75 are mitigated (tests above as evidence). T-07-76 is accepted.

## Left to the owner

T-07-77: `StopTimer` on a running entry that has already run past 2147483647 s would write an end whose generated `duration_seconds` integer column overflows, so such an entry cannot be stopped as it is. This plan does not change `StopTimer`. The list, view and edit pages stay reachable and the recovery path (correct the start through `UpdateTimeEntry`, then stop) is tested in `TimeEntryActionsTest` ("lets the Admin correct a running entry that has run past the limit and then stop it"). Reaching the state needs a start set within seconds of the 68-year bound.

## Known Stubs

None.

## Self-Check: PASSED

- Modified files exist: TimeEntry.php, TimeTotals.php, TimeEntryInput.php, UpdateTimeEntry.php and the four test files (FOUND).
- Commits `18a9c2b` and `a93fd41` are ancestors of HEAD.
