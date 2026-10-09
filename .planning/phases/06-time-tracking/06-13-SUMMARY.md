---
phase: 06-time-tracking
plan: 13
subsystem: time-tracking
tags: [laravel, scheduler, notifications, filament, queue, postgres, pest]

requires:
  - phase: 06-time-tracking
    provides: "TimeEntry with long_running_notified_at, TimerClock, DurationFormat, TimeEntryResource view page, kokpit.time.long_running_hours and the bar and panel warning states (06-01 to 06-12); KokpitJob, #[Idempotent] and the schedule registry (phase 3)"
provides:
  - "NotifyLongRunningTimers: final KokpitJob with #[Idempotent]; finds running, unannounced entries started at least kokpit.time.long_running_hours ago, skips an owner who is deactivated or not the Admin without claiming, claims each entry with one atomic builder update of long_running_notified_at and sends the bell notice with notifyNow in the same transaction"
  - "LongRunningTimerNotification: plain (not queued) database-channel notification in the Filament bell format: title 'Časovač běží příliš dlouho', body with the client name (escaped once) and the H:MM duration only, danger colour, warning icon, action 'Otevřít záznam' to the entry view page"
  - "Schedule entry kokpit-long-running-timers (every five minutes, onOneServer); job and schedule registry lines; kokpit.time.long_running.* Czech strings"
affects: [06-14]

tech-stack:
  added: []
  patterns:
    - "Claim and notice in one transaction: an Eloquent builder update (no model event, so no activity row for an internal marker) guarded by whereNull, then a synchronous notifyNow, so a failing notice rolls the claim back and the next run retries"
    - "A Filament-format database notification built by hand (not queued), with scalars in the constructor and the one e() on the raw value in toDatabase()"

key-files:
  created:
    - app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php
    - app/Domain/TimeTracking/Notifications/LongRunningTimerNotification.php
    - tests/Feature/TimeTracking/LongRunningTimerTest.php
  modified:
    - routes/console.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - lang/cs/kokpit.php
    - config/kokpit.php

key-decisions:
  - "The notification is not queued and is sent with notifyNow inside the claim transaction (research A9), so claim and notice commit or roll back together"
  - "An owner who is deactivated or not the Admin is skipped before the claim, so a reactivated Admin is still told while the timer keeps running; the test proves the second run after reactivation delivers"
  - "The claim is a Eloquent builder update, not DB::table() (QueryEscapeHatchTest), and raises no model event, so no activity row is written"
  - "A failure in the notice is not swallowed per entry: it propagates, the transaction rolls the claim back and the queue retry (then the failed-job alert) takes over"

patterns-established:
  - "Mutation check of an escaping test: doubling e() on the client name makes the single-escape test fail, so the test can detect the defect it guards"

requirements-completed: [TI-09]

coverage:
  - id: D1
    description: "A scheduled job every five minutes on one server puts exactly one bell notice per forgotten running entry into the bell of its Admin owner, with the contracted title, body (client name and H:MM duration), and an 'Otevřít záznam' action to the entry view page"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/LongRunningTimerTest.php#it puts one notice in the bell of the Admin for a timer past the threshold"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/ScheduleOnOneServerTest.php#it registers the known scheduled events"
        status: pass
    human_judgment: false
  - id: D2
    description: "Idempotent and exact: a second run, a pre-claimed entry, an entry under the threshold, a finished entry, a deactivated Admin and a non-Admin owner produce no notice; the threshold is read from kokpit.time.long_running_hours; the job never stops the timer, sends no mail and writes no activity row"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/LongRunningTimerTest.php (second run, pre-claimed, threshold, finished, deactivated, non-Admin, config, no stop/mail/activity)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The bell body carries no description and every interpolated value is escaped exactly once; a failing notice rolls the claim back"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/LongRunningTimerTest.php#it escapes a client name with markup exactly once; #it rolls the claim back when the notification fails"
        status: pass
    human_judgment: false
  - id: D4
    description: "UI state M10: the stock Filament bell renders the forgotten-timer notice with its action button"
    verification: []
    human_judgment: true
    rationale: "The stored payload is asserted in the Filament database format, but how the stock bell draws it in a browser is a visual check; the plan marks it as a backstop (verification: backstop), for the phase UAT"

actuals:
  tokens: 5000
  tasks: 2
  commits: 2

plan_head_before: 9f254468d780bf262da826eed49a294d936ddd28
plan_head_after: f6669d9ea770206290742548b00b37985e463e87
commits: 2

duration: 20min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 13: Forgotten-timer bell notice Summary

**A scheduled, idempotent job tells the Admin once, in the stock bell, that a timer has run past the configured threshold: one notice per running entry claimed atomically in the same transaction as the synchronous notification, client name escaped once, nothing for finished, deactivated or non-Admin cases, and the timer is never stopped.**

## Performance

- **Duration:** about 20 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 2 (1 tracer, 1 tdd-flagged auto)
- **Files:** 8 (3 created, 5 modified)

## Accomplishments

- `NotifyLongRunningTimers` reads `max(1, (int) config('kokpit.time.long_running_hours'))`, selects running, unannounced entries started at or before the cutoff (oldest first, owner and client with archived ones), and per entry runs one `DB::transaction`: a `TimeEntry::query()->whereKey()->whereNull('ended_at')->whereNull('long_running_notified_at')->update(...)` claim, and only for a claimed row `$owner->notifyNow(new LongRunningTimerNotification(...))`. The builder update raises no model event, so the activity log gets no row for the internal marker.
- `LongRunningTimerNotification` is deliberately not queueable, takes scalars only, and builds the bell payload with `FilamentNotification::make()->...->getDatabaseMessage()`. The client name goes through `e()` once; the duration is `DurationFormat::hoursMinutes()` (12 h 3 min reads `12:03`).
- The schedule line `kokpit-long-running-timers` (every five minutes, `onOneServer()`) is in `routes/console.php`; `JobContractTest` expects the new job and `ScheduleOnOneServerTest` knows the new event.
- 14 tests in `LongRunningTimerTest`. A mutation (doubling `e()` on the client name) made the single-escape test fail, then the file was restored. The full suite is 2456 passed (17373 assertions) after the last code edit; Pint, PHPStan and `scripts/check-sensitive.sh` are clean.

## Task Commits

1. **Task 1 (tracer): a timer past the threshold puts one notice in the Admin's bell from the scheduled job** - `f459598` (feat)
2. **Task 2 (tdd): exactly once, only when it should, never stopping, escaped once** - `f6669d9` (test)

**Plan metadata:** recorded in the following docs commit.

## TDD note

Task 2 is tdd-flagged, but the tracer commit already held the complete job (claim, owner guard, threshold, transaction), as the plan's artifact table specifies it in full. The behaviour tests written afterwards passed on the first run apart from one test of my own (two running entries for one user, which the `time_entries_one_running_per_user` partial index forbids; the test now uses two Admin accounts). There was no separate RED commit: the value of the tests was proven by the escaping mutation instead.

## Decisions Made

See `key-decisions` above. The tracer feedback gate ran as a re-run of the tracer verify (targeted tests, job and schedule registries, Pint, PHPStan), which passed, and expansion continued.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan nullable relations**
- **Found during:** Task 1
- **Issue:** `$entry->user` and `$entry->client` are typed nullable (no `@property` on the relations), so PHPStan level checks refused the property and method access.
- **Fix:** a guard that skips an entry without owner or client (cannot happen, both foreign keys are not null) and passes the client into the transaction closure.
- **Files modified:** `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php`
- **Commit:** `f459598`

**2. [Rule 1 - Bug in own test] Two running entries for one user**
- **Found during:** Task 2
- **Issue:** the draft "several forgotten timers of one Admin" test hit the unique partial index `time_entries_one_running_per_user`.
- **Fix:** the test now uses two Admin accounts with one running entry each and checks that each gets exactly one notice.
- **Files modified:** `tests/Feature/TimeTracking/LongRunningTimerTest.php`
- **Commit:** `f6669d9`

### Scope additions

- `config/kokpit.php`: the comment of `long_running_hours` said "in a later plan, the Admin gets one bell notification"; it now states that the scheduled job does it. Comment only, not in the plan's file list.

**Total deviations:** 2 auto-fixed (1 blocking, 1 own-test bug), 1 comment-only scope addition. **Impact:** none on behaviour.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The mitigations of the plan's register are in place and tested: T-06-33 (single `e()`, canary test and mutation), T-06-34 (owner-only, active Admin only, client name and duration only; non-Admin and deactivated tests), T-06-35 (`onOneServer()` plus the atomic claim; second-run and pre-claimed tests).

## Requirements

TI-09 (a forgotten long-running timer is flagged to the user): the bar pill and the panel callout (06-08, 06-09) and this bell notice are all delivered, so the full scope is complete.

## Next Phase Readiness

Ready for 06-14. Open for the phase UAT: how the stock Filament bell draws the notice with its action button (UI state M10, a backstop check).

## Self-Check: PASSED

- Files exist: `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php`, `app/Domain/TimeTracking/Notifications/LongRunningTimerNotification.php`, `tests/Feature/TimeTracking/LongRunningTimerTest.php`.
- Commits `f459598` and `f6669d9` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 2.
- All acceptance criteria of both tasks re-run and passing; plan verification (full `ddev exec vendor/bin/pest`, Pint, PHPStan, `scripts/check-sensitive.sh`) clean.
