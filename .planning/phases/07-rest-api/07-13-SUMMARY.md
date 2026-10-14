---
phase: 07-rest-api
plan: 13
subsystem: api
tags: [rest-api, time-entries, validation, integer-overflow, gap-closure, domain-rules, pest]

requires:
  - phase: 07-rest-api
    provides: "07-05 time entry writes, TimeEntryPayload and the integer column bound"
provides:
  - "TimeEntryInput::MAX_DURATION_SECONDS and TimeEntryInput::assertDurationFits(): the single interval bound of every time entry writer"
  - "UpdateTimeEntry refuses a changed start or end whose interval exceeds the integer limit, on a finished entry (against its end) and on a running entry (against TimerClock::now(), both directions)"
  - "CreateTimeEntry carries the same bound, so a manual panel entry cannot overflow duration_seconds either"
  - "kokpit.time.errors.duration_too_long in the domain lang file, text unchanged"
affects: [07-14, 07-15, 07-16, 07-17]

status: complete
requirements-completed: [AP-02]

actuals:
  tokens: 21000
  tasks: 3
  commits: 3

plan_head_before: 41433ca6d8350802cb208485541b7a5520094f9e
plan_head_after: eb6db9e2122e29e2dea11c888b679b6bbd13f1ba

tech-stack:
  added: []
  patterns:
    - "A domain rule shared by the panel and the API lives in TimeEntryInput and is called by the Actions; the HTTP payload only delegates, so the two layers cannot drift"
    - "The field key of a bound error is the field the data changes: ended_at when the data names an end, otherwise started_at"

key-files:
  created: []
  modified:
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
    - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - lang/cs/kokpit.php
    - lang/cs/api.php
    - tests/Feature/Api/TimeEntryWriteTest.php
    - tests/Feature/TimeTracking/TimeEntryActionsTest.php

key-decisions:
  - "The bound is the magnitude of the interval, abs(to - start) > 2147483647, so a running entry is refused for a start far in the past and for one more than about 68 years ahead of now"
  - "The field key is the field the data changes (ended_at when the data names an end, always on create, otherwise started_at); the 07-05 contract for ended_at and duration_seconds overflow under ended_at is unchanged"
  - "The Czech text 'Záznam je příliš dlouhý.' moved from api.errors to kokpit.time.errors because the rule now lives in the domain; the API wire text is unchanged"
  - "The bound runs only when the start or the end changes, so a description edit of an untouched entry still saves"

patterns-established:
  - "Exact-boundary tests (2147483647 accepted, 2147483648 refused) for running and finished entries, with travelTo wherever the result depends on now"

coverage:
  - id: D1
    description: "PATCH started_at far in the past on a finished entry answers 422 under started_at, never the integer-overflow 500, and the row is unchanged; an old start whose interval fits is still accepted"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses a start far in the past on a finished entry with 422 under started_at, never a database error"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it still accepts an old start whose interval fits the column"
        status: pass
    human_judgment: false
  - id: D2
    description: "A running entry's changed start is bounded against the current second in both directions, for the API and the panel, so the entry list cannot be poisoned"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses a start far in the past or far ahead on a running entry and keeps the entry list readable"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#it bounds the start of a running entry against the current second in both directions, exactly at the column limit"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#it accepts a finished interval of exactly 2147483647 seconds and refuses one second more under started_at"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#it runs the span bound only when the start or the end changes"
        status: pass
    human_judgment: false
  - id: D3
    description: "One bound for every writer: CreateTimeEntry carries it, TimeEntryPayload delegates, and the existing ended_at / duration_seconds overflow contract and Czech text are unchanged"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#it refuses a manual entry spanning more than the column limit under ended_at and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#it stores a manual entry of exactly 2147483647 seconds"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an interval beyond the column limit under ended_at, never a database error"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 13: Interval bound in the shared Actions Summary

**One domain bound (TimeEntryInput::assertDurationFits, 2147483647 seconds) enforced in UpdateTimeEntry and CreateTimeEntry, so a far-past or far-ahead start is a clean 422 on finished and running entries, through the API and the panel alike.**

## Performance

- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files modified:** 8
- **Verification:** `pest tests/Feature/Api tests/Feature/TimeTracking` 629 passed; Pint clean; `scripts/check-sensitive.sh` clean

## Accomplishments

- Closed WR-01 / open threat T-07-26: PATCH `{"started_at": "1900-01-01T00:00:00Z"}` on a finished entry answered a PostgreSQL integer-overflow 500; it now answers 422 under `started_at` with the stored row unchanged.
- A running entry with a far-past or far-ahead start can no longer be stored, so `TimeEntry::scopeWithElapsedSeconds` (the Admin's entry list) cannot be broken (T-07-58).
- `CreateTimeEntry` carries the same bound; the panel and the API refuse the same intervals with the same field keys and message (D-09).
- `TimeEntryPayload` lost its own constant and comparison and delegates to the domain helper; the API wire text for the existing overflow cases is unchanged.

## Task Commits

1. **Task 1 (tracer): bound a finished entry on a changed start or end** - `ba54192`
2. **Task 2: bound a running entry's changed start against the current second** - `d0734d7`
3. **Task 3: one bound for every writer (Create, payload delegation, message moved)** - `eb6db9e`

## Deviations from Plan

None - plan executed exactly as written.

## Threat Mitigation

| Threat | Status | Evidence |
|---|---|---|
| T-07-26 (open, from 07-05) / T-07-57 | mitigated | `TimeEntryWriteTest` "refuses a start far in the past on a finished entry with 422 under started_at" |
| T-07-58 | mitigated | running-entry API test and the Action boundary test |
| T-07-59 | mitigated | "refuses a manual entry spanning more than the column limit under ended_at and stores nothing" |
| T-07-60 | mitigated | one constant and one helper in `TimeEntryInput`; payload delegates |

`/gsd-secure-phase 07` can mark T-07-26 closed with the Task 1 test as evidence.

## Issues Encountered

`phpstan analyse` reports 2 pre-existing errors (`cast.string` in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 38 and `app/Console/Commands/StorageCheckCommand.php` line 33). Both files are outside this plan's scope and were not touched; the plan's files introduce no PHPStan errors. Out of scope per the scope boundary, left for a separate fix.

## Known Stubs

None.

## Threat Flags

None - no new endpoints, auth paths or schema changes.

## Next Phase Readiness

Ready for 07-14 (UTC year range of instants, including the end computed from `duration_seconds`). Note: `TimeEntryPayload::end()` now uses the absolute difference, so a PATCH naming an `ended_at` far before the stored start reports `duration_too_long` from the payload before the Action's `end_before_start` runs; the Action alone still reports `end_before_start` for near ends.

## Self-Check: PASSED

- Modified files exist; commits `ba54192`, `d0734d7`, `eb6db9e` are ancestors of HEAD.
- All task acceptance criteria re-run and passing; plan-level verification: Pest 629 passed, Pint clean, check-sensitive clean (PHPStan: only the 2 pre-existing out-of-scope errors).
