---
phase: 03-operations-foundation
plan: 16
subsystem: infra
tags: [health, queue, redis, scheduler, heartbeat, system-page, thresholds]

requires:
  - phase: 03-operations-foundation
    provides: "HealthIndicator, HealthResult, HealthStatus, HealthSlot, HealthIndicatorRegistry and the System page (03-15); KokpitJob, #[Idempotent], RedisTestQueue, ReportFailedJob alert link (03-13); JobContractTest and its expected job list (03-14)"
provides:
  - "FailedJobsIndicator: failed jobs counted through the queue failer, Warning from kokpit.health.failed_jobs_warning_at"
  - "Heartbeats (cache keys kokpit:heartbeat:scheduler and kokpit:heartbeat:worker, Cache::forever) and SchedulerHeartbeatIndicator: Error past 180 s and when never recorded"
  - "OldestPendingJobIndicator: age of the Redis queue head, Warning past 600 s, Error past 1800 s, Not available on any other connection, worker heartbeat time in the detail"
  - "RecordWorkerHeartbeat KokpitJob and the kokpit-heartbeat / kokpit-worker-heartbeat schedule entries (every minute)"
  - "kokpit.health thresholds in config/kokpit.php with the delayed-job and heartbeat-flush caveats documented"
affects: [03-19, phase-08-cnb-rates-pdf, phase-10-invoices, phase-11-stripe]

actuals:
  tokens: 8600
  tasks: 3
  commits: 5

plan_head_before: 75b200e83a8bebf879af52ed213be56a3e447d55
plan_head_after: b8f70b4688afb70d4d93ffd0e07862e0d9c5fb09

tech-stack:
  added: []
  patterns:
    - "The registry binding registers the real indicators first and a PlaceholderIndicator only for the slots that HealthIndicatorRegistry::missingSlots() reports as uncovered, so register() never sees a duplicate and a later phase only adds its indicator to the list"
    - "Time-dependent health tests fix the clock with Carbon::setTestNow and create queue jobs at an earlier fixed instant, so ages are exact and boundary tests (600/601, 1800/1801, 180/181) are deterministic"
    - "A scheduled trivial queued job turns a silent dead worker into a visible growing queue age"

key-files:
  created:
    - app/Domain/Operations/Health/Heartbeats.php
    - app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php
    - app/Domain/Operations/Health/Indicators/SchedulerHeartbeatIndicator.php
    - app/Domain/Operations/Health/Indicators/OldestPendingJobIndicator.php
    - app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php
    - tests/Feature/Operations/HealthIndicatorsTest.php
  modified:
    - app/Providers/OperationsServiceProvider.php
    - routes/console.php
    - config/kokpit.php
    - lang/cs/kokpit.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/Operations/AdminAlertTest.php
    - tests/Feature/Operations/HealthRegistryTest.php
    - tests/Feature/Operations/SystemPageTest.php

key-decisions:
  - "The registry binding registers real indicators and fills only uncovered slots with placeholders (register() throws on a duplicate); Phases 8, 10 and 11 use replace() or add their indicator to the real list"
  - "RecordWorkerHeartbeat::handle() takes no arguments and resolves Heartbeats from the container, so the job can be called directly in a test and matches the plan's contract"
  - "Values are shown as seconds ('60 s'); the detail of the oldest-pending slot always ends with the last worker heartbeat time in Europe/Prague (LocalisationServiceProvider::DATE_TIME_SECONDS_FORMAT) or a 'worker has not processed a heartbeat job yet' text"
  - "FailedJobsIndicator types the failer as object (PHPStan infers the concrete database driver and would call the instanceof always true); a failer that cannot count (null or file driver) reports Not available, never Ok"
  - "The empty Redis queue is Ok with no value; the 'oldest pending' slot does not read the worker key to decide the status (the dead-worker signal is the growing age of the scheduled heartbeat job, as the plan specifies)"

patterns-established:
  - "Boundary tests per threshold: exactly at the limit is the lower status, one second over is the higher; each limit is also moved through config to prove it is not hard-coded"

requirements-completed: []  # FND-09 and FND-10 are also declared by plan 03-19 (documentation), so the shared-ID gate keeps them open

coverage:
  - id: D1
    description: "Failed-jobs slot: count through the queue failer, Warning at the configured threshold, shown as Warning with the count on the Admin System page"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthIndicatorsTest.php (failed jobs: Ok at zero, Warning at one, config threshold, System page render)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Scheduler slot: Ok at 60 s and exactly 180 s, Error at 181 s, Error with a never-recorded detail, config-driven boundary; kokpit-heartbeat runs every minute and writes the key"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthIndicatorsTest.php (scheduler heartbeat tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Oldest-pending slot on a real Redis queue: Ok when empty, Ok/Warning at 600/601 s, Warning/Error at 1800/1801 s, thresholds and queue name configurable, Not available on a non-Redis connection, worker heartbeat time in the detail"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthIndicatorsTest.php (oldest pending tests, unique Redis queue per test, flushed in afterEach)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Dead worker visible: the scheduled RecordWorkerHeartbeat job ages in the queue until a worker processes it, after which the age clears and the worker key is written; the job is on the architecture test's expected list and carries #[Idempotent]"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthIndicatorsTest.php#lets a worker clear the age...; tests/Arch/JobContractTest.php (expected job list, idempotent declaration)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The failed-job alert links to the System page, in the bell action and in the e-mail"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/AdminAlertTest.php#links the alert to the System page"
        status: pass
    human_judgment: false
  - id: D6
    description: "Real behaviour of the schedule against a running scheduler and worker (cron/supervisor) in a deployed environment"
    verification: []
    human_judgment: true
    rationale: "Tests run the schedule events and a worker over a test queue in-process; they do not prove that the deployed cron entry and worker daemon exist, which plan 03-18 documents"

duration: 9min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 16: Background-Work Health Indicators Summary

**The System page now measures failed jobs through the queue failer, the age of the oldest Redis job and the scheduler heartbeat against fixed documented thresholds, and a scheduled worker-heartbeat job makes a dead worker or scheduler show up as a growing age or an Error**

FND-10 partially delivered by design: three indicators are placeholders by design (last rate date, unprocessed webhooks, unsent invoice e-mails); they are replaced in Phases 8, 10 and 11.

## Performance

- **Duration:** 9 min
- **Started:** 2026-10-08T04:32:32Z
- **Completed:** 2026-10-08T04:41:30Z
- **Tasks:** 3
- **Files modified:** 14 (6 created, 8 modified)

## Accomplishments

- `FailedJobsIndicator` counts via `app('queue.failer')` (no table query, `QueryEscapeHatchTest` stays green). Not countable failer: Not available.
- `kokpit.health` in `config/kokpit.php`: `failed_jobs_warning_at` 1, `oldest_pending_warning_after` 600, `oldest_pending_error_after` 1800, `scheduler_heartbeat_error_after` 180, with a docblock naming D-12, the delayed-job age caveat and the cache-flush caveat (Cache::forever heartbeats show Error for up to a minute after a flush, T-03-42 accepted).
- `Heartbeats` plus `SchedulerHeartbeatIndicator`; `Schedule::call` named `kokpit-heartbeat` writes the scheduler key every minute.
- `OldestPendingJobIndicator` reads `RedisQueue::creationTimeOfOldestPendingJob()`; the scheduled `RecordWorkerHeartbeat` job (`kokpit-worker-heartbeat`, every minute) writes the worker key when a worker processes it, so with a dead worker the oldest-pending age grows even when nothing else is queued.
- The registry binding registers the three real indicators and a placeholder only for the three later slots.
- Czech strings under `kokpit.system.failed_jobs.*`, `kokpit.system.scheduler.*`, `kokpit.system.oldest_pending.*`.

## Task Commits

1. **Task 1 (tracer): failed-jobs slot turns to Warning on the System page** - `db25964` (feat)
2. **Task 2 (tdd): scheduler heartbeat and its indicator**
   - RED `8e921ba` (test) - 7 of 12 tests fail on the missing classes
   - GREEN `a459b68` (feat)
3. **Task 3 (tdd): oldest pending job, worker heartbeat job, job contract list, alert link**
   - RED `726e8ae` (test) - 13 of the 41 tests across the three files fail
   - GREEN `b8f70b4` (feat)

**Plan metadata:** recorded in the docs commit that follows this summary.

Tracer gate: auto mode was active, so the tracer's `<verify>` was re-run end to end (the three named test files, the full Pest suite 815 passed, Pint, Larastan) before expansion; it passed. "Tracer verified end-to-end - expanding".

## TDD Gate Compliance

`test(03-16)` (`8e921ba`, `726e8ae`) precedes `feat(03-16)` (`a459b68`, `b8f70b4`) for Tasks 2 and 3. No REFACTOR commit was needed. Task 1 is a tracer (not `tdd="true"`); its tests were written with the code.

**RED evidence (semantic assessment).**
- Task 2: 7 of 12 tests in `HealthIndicatorsTest` failed; the 5 failed-jobs tests from Task 1 still passed. The failures are `BindingResolutionException` for the not-yet-existing `Heartbeats` and `SchedulerHeartbeatIndicator` classes and, for the schedule and registry tests, the absent `kokpit-heartbeat` event and unbound indicator. The targets executed; the failure is the planned API being absent, not a setup, import or syntax fault in the test file.
- Task 3: 13 of 41 tests failed: the 11 new Redis/heartbeat tests (missing indicator and job class), `JobContractTest` "has exactly the expected application jobs" (the list names `RecordWorkerHeartbeat`, which does not exist yet) and "declares the worker heartbeat job as idempotent". The 28 other tests passed.
- `gsd_run check tdd-red-evidence` does not parse Pest output, so no `RED_EVIDENCE_OK` record exists (same as plans 03-10, 03-13 to 03-15).

**Green on first run:** `AdminAlertTest#links the alert to the System page` (03-15 already created the route and 03-13's `ReportFailedJob` already links it). The mutation check below proves it is not vacuous.

**Mutation checks** (each file copied to a scratch path first and restored from the copy; each mutation failed exactly the matching tests):

| Mutation | Failed tests |
|---|---|
| failed-jobs threshold hard-coded to 1 | config threshold test |
| failed-jobs status Warning changed to Ok | Warning test, config test, System page render test |
| failed-jobs count forced to 0 | same three |
| scheduler `>` changed to `>=` | 181/180 boundary test |
| scheduler never-recorded reported as Ok | never-recorded test |
| scheduler threshold hard-coded to 180 | config boundary test |
| `kokpit-heartbeat` every minute changed to hourly | schedule test |
| worker key sharing the scheduler key | two-heartbeats-apart test |
| `kokpit-heartbeat` name changed | schedule test |
| oldest-pending warning `>` changed to `>=` | threshold boundary test |
| oldest-pending error threshold hard-coded | config thresholds test |
| non-Redis connection reported as Ok | Not available test |
| given queue name ignored | queue-argument test |
| worker heartbeat dropped from the detail | detail test |
| empty queue reported as Error | empty test, queue-argument test, worker-clears-age test |
| `kokpit-worker-heartbeat` every minute changed to hourly | worker schedule test |
| RecordWorkerHeartbeat writes the scheduler key | worker-clears-age, handle and schedule tests |
| alert link changed to the panel home | alert link test |

## Files Created/Modified

- `app/Domain/Operations/Health/Heartbeats.php` - the two cache heartbeats
- `app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php`, `SchedulerHeartbeatIndicator.php`, `OldestPendingJobIndicator.php` - the three measurements
- `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php` - the scheduled worker heartbeat job
- `app/Providers/OperationsServiceProvider.php` - registry binding with real indicators plus placeholders for uncovered slots
- `routes/console.php` - `kokpit-heartbeat` and `kokpit-worker-heartbeat`
- `config/kokpit.php`, `lang/cs/kokpit.php` - thresholds and Czech texts
- `tests/Feature/Operations/HealthIndicatorsTest.php` (new, 25 tests), `tests/Arch/JobContractTest.php`, `tests/Feature/Operations/AdminAlertTest.php` (link test renamed and strengthened), `tests/Feature/Operations/HealthRegistryTest.php`, `tests/Feature/Operations/SystemPageTest.php`

## Decisions Made

See `key-decisions` above. The two that later work needs to know: the binding takes a list of real indicators (add a Phase 8/10/11 indicator there, or call `replace()`), and every job with a delay or backoff above 600 s will trip the oldest-pending Warning until that indicator learns to exclude it (documented in `config/kokpit.php` and the `KokpitJob` docblock).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Phase 03-15 tests asserted that every slot is a placeholder**
- **Found during:** Task 1 (the plan turned the failed-jobs slot into a real measurement)
- **Issue:** `HealthRegistryTest` asserted all six slots Not available and the failed-jobs slot Not available after a swap; `SystemPageTest` asserted at least six Not available badges.
- **Fix:** The placeholder test now covers the three later slots only, the swap test expects the failed-jobs slot Ok, the badge count is at least 3.
- **Files modified:** `tests/Feature/Operations/HealthRegistryTest.php`, `tests/Feature/Operations/SystemPageTest.php` (not in the plan's file list; Phase 3 tests the plan's change affects)
- **Committed in:** `db25964`

**2. [Rule 3 - Blocking] PHPStan rejected the failer instanceof check**
- **Found during:** Task 1 (Larastan run)
- **Issue:** The container return type resolves to the database failer, so `instanceof CountableFailedJobProvider` was reported as always true.
- **Fix:** `@var object` on the failer variable with a comment; the runtime check stays for the null and file drivers.
- **Committed in:** `db25964`

**Total deviations:** 2 auto-fixed (1 bug in earlier tests, 1 blocking static-analysis finding). **Impact on plan:** none on behaviour; no Rule 4 trigger.

## Issues Encountered

- A mutation loop used an unquoted `echo "M2 never->Ok"`, which created two stray files (`Ok`, `hourly`) in the repository root; they were noticed from `git status`, read and deleted before any commit. Nothing was staged from them.
- DDEV mount lag: a one-to-two second wait before each Pest run after an edit (known from 03-13 to 03-15).

## Known Stubs

Intentional by design (D-13), not defects, same table as 03-15 minus the slots this plan fills:

| Slot | Indicator | Replaced by |
|---|---|---|
| last_rate_date | `PlaceholderIndicator` (`app/Providers/OperationsServiceProvider.php`) | Phase 8 |
| unsent_invoice_emails | `PlaceholderIndicator` | Phase 10 |
| unprocessed_webhooks | `PlaceholderIndicator` | Phase 11 |

## Threat Flags

None beyond the plan's register. T-03-41 (dead worker or scheduler invisible) is mitigated and tested; T-03-42 (heartbeat lost on a cache flush) is accepted and documented in `config/kokpit.php`; T-03-SC: no package added. The health details contain only fixed Czech texts and times, no exception message or connection string.

## User Setup Required

None. The deployed environment needs the Laravel scheduler (`schedule:run` every minute) and a Redis queue worker for the heartbeats to turn green; plan 03-18 documents both.

## Manual follow-up

- Visual check of the System page with real data (failed job, aged queue, stopped scheduler) in a browser is left to the phase verification; tests assert text, badge colour classes and values.
- After deployment, confirm that the scheduler cron entry and the worker daemon are running; until the first scheduler run the scheduler slot honestly shows Error with "never recorded".

## Next Phase Readiness

- Plan 03-19 can document the thresholds, both heartbeats and the System page slots. FND-09 and FND-10 stay open because 03-19 declares them too (shared-ID gate).
- Phases 8, 10 and 11 add their indicators to the `$real` list in the registry binding (or call `replace()`); the three placeholder slots keep working until then.
- Full gate green at the last code commit: `ddev composer ci` Pest 834 passed (3534 assertions), Pint 245 files, Larastan no errors, licence check 201 packages.

## Self-Check: PASSED

- All 6 created files exist on disk.
- Commits `db25964`, `8e921ba`, `a459b68`, `726e8ae`, `b8f70b4` are ancestors of HEAD; `git rev-list --count 75b200e..HEAD` = 5.
- Acceptance criteria of all three tasks re-run: every grep succeeds (`queue.failer`, the four threshold literals, `FailedJobsIndicator`, `kokpit-heartbeat`, `Cache::forever`, `SchedulerHeartbeatIndicator`, `creationTimeOfOldestPendingJob`, `RecordWorkerHeartbeat` in console and arch test, `system` in the alert test).
- Plan-level verification: full Pest suite green including the Redis tests, Pint and Larastan clean, `scripts/check-sensitive.sh` clean on every commit.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
