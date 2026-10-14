---
phase: 07-rest-api
plan: 14
subsystem: api
tags: [rest-api, time-tracking, validation, timestamptz, gap-closure, pest]

requires:
  - phase: 07-rest-api
    provides: "07-13 TimeEntryPayload::end() with the span bound, 07-16 cursor validation, 07-04 PragueDay filters"
provides:
  - "TimerClock::isStorable(): the UTC year 1..9999 envelope every stored instant must fit"
  - "TimeEntryInput::normalised() refuses an unstorable instant with invalid_time under the caller's field (create, update, batch items, instant list filters, panel Actions)"
  - "TimeEntryPayload::end() routes the end computed from duration_seconds through TimeEntryInput::instant, so the range check answers before the Action runs"
  - "PragueDay: midnight() built from day components and null when unstorable; day() refuses a day whose bounding midnights leave UTC years 1 to 9999"
affects: [07-15]

status: complete
requirements-completed: [AP-02]

actuals:
  tokens: 5400
  tasks: 2
  commits: 2

plan_head_before: 0b169fd5c07fc224df6b6f80a24f73d9c9bf25ad
plan_head_after: 32d771912c75cfc2d3c857ad984da992cbd34d33

tech-stack:
  added: []
  patterns:
    - "One storable-instant envelope (TimerClock::isStorable) asked by TimeEntryInput::normalised and PragueDay; the payload reaches it only through TimeEntryInput::instant"

key-files:
  created: []
  modified:
    - app/Domain/TimeTracking/Support/TimerClock.php
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Support/PragueDay.php
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - tests/Feature/Api/TimeEntryWriteTest.php
    - tests/Feature/TimeTracking/PragueDayTest.php
    - tests/Feature/Api/TimeEntryListTest.php
    - tests/Feature/TimeTracking/TimeEntryListTest.php

key-decisions:
  - "The envelope lives in TimerClock::isStorable (UTC year 1 to 9999) and both TimeEntryInput and PragueDay ask it, so the range is stated once"
  - "The computed end of duration_seconds is wrapped in TimeEntryInput::instant(..., 'ended_at') inside TimeEntryPayload::end(), not checked directly: range, truncation and the invalid_time message stay in normalised() only, and a named ended_at and a computed end take exactly the same path"
  - "The range check runs before the span bound of plan 07-13, as for a named ended_at"
  - "PragueDay::midnight() builds the midnight from the year, month and day components, never from a re-parsed formatted string, so 9999-12-31 is a supported day while 0001-01-01 is not"
  - "An out-of-range day in the panel's custom period filter is dropped through the existing PragueDay::day() null path, with no new message"

patterns-established:
  - "Tests assert year bounds and storability, not exact historic local-mean-time seconds, so a tzdata update cannot break them"

coverage:
  - id: D1
    description: "POST and PATCH with an instant outside UTC years 1 to 9999 (0001-01-01T00:00:00+02:00, 9999-12-31T23:59:59-12:00) answer 422 invalid_time under the sent field, nothing stored or changed, never a 500"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an instant outside UTC years 1 to 9999 under its own field on create, never a database error"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an unstorable instant on PATCH and leaves the row unchanged"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it accepts the first storable second of UTC year 1"
        status: pass
    human_judgment: false
  - id: D2
    description: "The end computed from duration_seconds is range-checked like a named ended_at: 422 under ended_at on POST and PATCH for 2147483647 and 86400, 201/200 for 86399, and the payload itself refuses it before any Action runs (T-07-73)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an end computed from duration_seconds that leaves UTC year 9999 under ended_at, and accepts the last storable second"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses a PATCH duration_seconds whose computed end leaves UTC year 9999 and accepts the last storable second"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses the computed end of duration_seconds in the payload itself, before any Action runs"
        status: pass
    human_judgment: false
  - id: D3
    description: "GET filter[from] and filter[to] answer 422 under their own key for 0001-01-01, 0001-01-01T00:00:00+02:00, 9999-12-31T23:59:59-12:00 and 0000-01-01T00:00:00Z; 0001-01-02 and 9999-12-31 answer 200"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it answers 422 under the filter key for a malformed value, never 400 or 500"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it accepts the first day of UTC year 1 as a lower bound and the last day of year 9999 as an upper bound"
        status: pass
    human_judgment: false
  - id: D4
    description: "PragueDay is range-aware and ordinary days (23- and 25-hour days) keep their exact boundaries; the panel's custom period filter ignores an out-of-range day and filters normally on 9999-12-31"
    requirement: AP-02
    verification:
      - kind: unit
        ref: "tests/Feature/TimeTracking/PragueDayTest.php#it keeps every supported day inside UTC years 1 to 9999 and refuses the days outside"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/PragueDayTest.php#it builds a midnight for a plain day past year 9999 and refuses one before year 1"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#it ignores a custom period day outside UTC years 1 to 9999 and still filters on the last supported day"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 14: Storable instant envelope for time writes and filters Summary

**One UTC-year-1-to-9999 check (`TimerClock::isStorable`) asked by `TimeEntryInput::normalised` and by a range-aware `PragueDay` turns every unstorable instant, computed `duration_seconds` end or filter day into a 422 under the field instead of a PostgreSQL 22008 or Carbon 500.**

## Performance

- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 8 modified, 0 created
- **Duration:** about 25 min
- **Completed:** 2026-10-14
- **Verification:** `pest tests/Feature/Api tests/Feature/TimeTracking` 705 passed; Pint clean; PHPStan reports only the two known pre-existing errors in `ResetAdminTwoFactorCommand.php` and `StorageCheckCommand.php`; `scripts/check-sensitive.sh` clean

## Accomplishments

- Closed gap 2 (WR-02, write and filter part) of 07-VERIFICATION: a year-1 instant with a positive offset (UTC year 0), a year-9999 instant with a negative offset (UTC year 10000) and the day `0001-01-01` all answered 500 before; they now answer 422 invalid_time (or the filter key) and nothing is stored or changed.
- The computed end of a `duration_seconds` entry (`started_at` 9999-12-31T00:00:00Z with 86400 or 2147483647) takes the same path as a named `ended_at`, on POST, PATCH and batch items, and is refused by the payload itself before the Action runs (threat T-07-73 mitigated; evidence: test "it refuses the computed end of duration_seconds in the payload itself, before any Action runs"). A computed end of exactly 9999-12-31T23:59:59Z (86399) is still accepted.
- `PragueDay::midnight()` no longer re-parses a formatted string, so `9999-12-31` works as a day (its end-exclusive midnight is `9999-12-31T23:00:00Z`) while `0001-01-01` (Prague local mean time puts its midnight in UTC year 0) is not a supported day; `0001-01-02` is. The panel's custom period filter shares this rule and ignores such a bound without an error or an indicator.
- Existing DST boundary tests (23- and 25-hour days) stay green unchanged.

## Task Commits

1. **Task 1 (tracer): an unstorable instant answers 422 invalid_time on create and update, computed end included** - `43f75ee`
2. **Task 2: range-aware PragueDay, 422 for out-of-range filter days and instants, panel bound ignored** - `32d7719`

The tracer feedback gate ran in the automated form: the Task 1 verify (write, actions and batch tests, Pint, PHPStan) passed end to end before Task 2 started. RED was confirmed first: 5 of the new write tests failed before the change (the Test 7 payload test because no exception was thrown, the rest with 500s), and 3 PragueDay and 3 list/panel tests failed before the PragueDay change.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan: `CarbonImmutable::create()` may return null**
- **Found during:** Task 2
- **Issue:** `PragueDay::midnight()` called `->utc()` on the result of `CarbonImmutable::create()`, which PHPStan types as nullable.
- **Fix:** nullsafe `?->utc()` plus an `instanceof CarbonImmutable` guard before the `isStorable` check, so a null midnight stays null.
- **Files modified:** app/Domain/TimeTracking/Support/PragueDay.php
- **Commit:** 32d7719 (fixed before the commit)

Otherwise none - plan executed exactly as written. The extra test cases beyond the plan (`filter[from]=9999-12-31` and `filter[to]=0001-01-02` answer 200 with an empty list) are additions in the same spirit.

## Known Stubs

None.

## Threat Flags

None. T-07-61, T-07-62, T-07-63 and T-07-73 from the plan's threat model are mitigated; no new endpoint, auth path or schema change was introduced.

## Next Phase Readiness

Ready for 07-15 (batch item mapping of a non-validation fault, plus its batch regression test for a bad-range item). A bad-range batch item already fails with its own `invalid_time` item error through `forBatchItem` -> `forCreate` -> `end()`.

## Self-Check: PASSED

- Created/modified files exist on disk (all 8 key-files).
- Commits `43f75ee` and `32d7719` are ancestors of HEAD.
- All task acceptance greps pass; `ddev exec vendor/bin/pest tests/Feature/Api tests/Feature/TimeTracking` exits 0.
