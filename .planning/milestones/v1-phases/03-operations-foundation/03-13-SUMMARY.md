---
phase: 03-operations-foundation
plan: 13
subsystem: infra
tags: [queue, redis, jobs, notifications, filament-bell, alerting, throttle, laravel-13-attributes]

requires:
  - phase: 03-operations-foundation
    provides: "OperationsServiceProvider and queue listeners (03-10), Filament panel boot hooks (03-12)"
  - phase: 02-platform-foundation
    provides: "PartnerContext::runAsSystem, RoleName, config/kokpit.php style, Canary test helpers"
provides:
  - "Abstract KokpitJob: 3 attempts, backoff 10/60/300 s, 60 s timeout through Laravel 13 attributes, fixed RunsAsSystem middleware, idempotence contract docblock"
  - "#[Idempotent(how)] class attribute (declaration only; the architecture test follows in 03-14)"
  - "ReportFailedJob listener on JobFailed and AdminAlerter: one throttled, queue-independent Admin alert by e-mail and Filament bell per final job failure"
  - "OperationalAlert: non-queueable notification with Filament-format toDatabase(), reusable by later alerts (health checks, 03-15/03-16)"
  - "Filament database notifications (bell) for the Admin only, polling 30 s"
  - "RedisTestQueue helper and FailingProbeJob for real-Redis queue tests"
affects: [03-14, 03-15, 03-16, phase-08-cnb-rates-pdf, phase-10-invoices, phase-11-stripe]

actuals:
  tokens: 9100
  tasks: 2
  commits: 3

plan_head_before: f991b23510a957e631a297b62b8efd12c287f095
plan_head_after: 96a916931e725a1cc0a06f0a3f57ac900f217d1d

tech-stack:
  added: []
  patterns:
    - "Queue defaults as class attributes on an abstract base; the middleware list is final and composed from a fixed system middleware plus a protected extension point"
    - "Alerts never use the queue: non-queueable notification class, Notification::sendNow, one try/catch per channel, critical log as the last resort"
    - "Throttle with Cache::add per key and a held-back counter that outlives the window; a failing cache sends the alert (duplicates beat silence)"
    - "Real-Redis tests use a unique queue name and delete exactly that queue's keys"

key-files:
  created:
    - app/Domain/Operations/Jobs/KokpitJob.php
    - app/Domain/Operations/Jobs/Idempotent.php
    - app/Domain/Operations/Jobs/Middleware/RunsAsSystem.php
    - app/Domain/Operations/Alerts/OperationalAlert.php
    - app/Domain/Operations/Alerts/AdminAlerter.php
    - app/Domain/Operations/Alerts/ReportFailedJob.php
    - database/migrations/2026_10_08_000300_make_notifications_data_jsonb.php
    - tests/Support/Probes/FailingProbeJob.php
    - tests/Support/RedisTestQueue.php
    - tests/Feature/Operations/FailingJobFlowTest.php
    - tests/Feature/Operations/AdminAlertTest.php
  modified:
    - app/Providers/OperationsServiceProvider.php
    - app/Providers/Filament/AdminPanelProvider.php
    - config/kokpit.php
    - lang/cs/kokpit.php

key-decisions:
  - "Bell decision: databaseNotifications() takes a closure condition, so the bell is enabled for the Admin only (static fn => PartnerContext::isAdmin(), evaluated per request); a Partner never gets the bell. Rating: reversible"
  - "notifications.data becomes jsonb through a new migration: the Filament bell filters with data->>'format', which PostgreSQL rejects on a text column and turned every Admin panel page into a 500"
  - "AdminAlerter is not final (same precedent as SupplierSettings) so a test can replace it with a double that throws; ReportFailedJob resolves it lazily inside its try/catch"
  - "Held-back counter lives under its own cache key with a one-day TTL, so it outlives the 900 s window and is reported with the first alert after it"
  - "The alert body is plain text with one line per row; the first message line is cut to message_max_length with no ellipsis, so the limit is exact"
  - "afterCommit() in the KokpitJob constructor is left to plan 03-14 (after-commit dispatch contract), not added here"

patterns-established:
  - "Alert content rule: job name, connection and queue, attempts, exception class, failed job uuid and a cut first message line; never payload() or getRawBody(), never a trace"
  - "Mutation-check discipline for tests that were green at RED: break the guarded behaviour and watch exactly the matching test fail"

requirements-completed: [FND-09]

coverage:
  - id: D1
    description: "KokpitJob base: 3 attempts, backoff 10/60/300 s, 60 s timeout as attributes, final middleware list that always starts with the system-context middleware, #[Idempotent] declaration and contract docblock"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/FailingJobFlowTest.php#tries a failing job three times, records it in failed_jobs and alerts the Admin once"
        status: pass
    human_judgment: false
  - id: D2
    description: "A failing job on a real Redis queue is attempted 3 times, ends in failed_jobs and raises exactly one Admin e-mail and one Filament-format bell row, sent without the queue by a non-queueable notification"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/FailingJobFlowTest.php (both tests); tests/Feature/Operations/AdminAlertTest.php#delivers the mail and the bell row without pushing anything to a queue"
        status: pass
    human_judgment: false
  - id: D3
    description: "Alerts are throttled per job class (900 s window), held-back failures are counted and reported with the next alert, a failing cache still sends"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/AdminAlertTest.php (same class, counted suppressions, different classes, cache failure)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Alert content carries job, queue, attempts, exception class, failed job id and at most 200 characters of the first message line; no payload, no stack trace; one failing channel or the whole alerter never stops the failed_jobs record"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/AdminAlertTest.php (content canary test, mail transport throws, database channel throws, alerter throws)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Filament bell enabled for the Admin only with 30 s polling; the rendered bell works on PostgreSQL"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "Full suite: every Admin panel request test (Isolation/PanelAccessTest, Auth/TwoFactorEnforcementTest and others) renders the bell query against the jsonb column"
        status: pass
    human_judgment: true
    rationale: "No test asserts that the bell icon shows an unread alert in a browser; the stored row format is asserted, the visual result is not"

duration: 10min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 13: Failing Jobs Fail Loudly Summary

**Abstract KokpitJob (3 attempts, 10/60/300 s backoff, 60 s timeout, fixed system-context middleware) plus a JobFailed listener that raises one throttled, queue-independent Admin alert by e-mail and Filament bell, proven on a real Redis queue**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-08T04:01:35Z (previous plan's last commit; the start time was not recorded separately)
- **Completed:** 2026-10-08T04:12:00Z
- **Tasks:** 2
- **Files modified:** 15 (11 created, 4 modified)

## Accomplishments

- `KokpitJob`, `#[Idempotent(how)]` and the `RunsAsSystem` middleware: defaults come from `#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]`, `middleware()` is final and always returns the system middleware first, a subclass extends through `jobMiddleware()`. The docblock states the idempotence contract (natural key, check then act, unique constraints) and the delayed-job note for the oldest-pending indicator.
- Final-failure path: `ReportFailedJob` (listener on `JobFailed`, catches everything) builds the alert and `AdminAlerter` sends it per channel with `Notification::sendNow` to every Admin; `OperationalAlert` implements no queue interface. A real Redis run proves 3 attempts, one `failed_jobs` row, one mail and one Filament-format bell row.
- Throttle: first failure alerts at once, further failures of the same job class inside `kokpit.alerts.throttle_seconds` (900) are counted, the next alert after the window says how many were held back; different classes alert separately; a failing cache sends anyway.
- Safe content: exception class, queue, attempts, failed job uuid and at most 200 characters of the first message line (`kokpit.alerts.message_max_length`); a runtime-canary test proves the tail of a 300-character line, a second line, the job payload and raw body and the stack frame text never reach the mail or the bell row.
- Admin-only Filament bell with 30 s polling.

## Task Commits

1. **Task 1 (tracer): failing job on real Redis, Admin mail and bell** - `d2d8e7f` (feat)
2. **Task 2 (tdd): throttled alerts, safe content, independent channels**
   - RED `8cf0330` (test) - 2 of 10 tests fail on the intended assertions
   - GREEN `96a9169` (feat) - throttle in `AdminAlerter`

**Plan metadata:** recorded in the docs commit that follows this summary.

Tracer gate: auto-chain was active, so the tracer's `<verify>` was re-run end to end (`FailingJobFlowTest`, Pint, Larastan) before expansion; it passed ("Tracer verified end-to-end - expanding").

## TDD Gate Compliance

`test(03-13)` precedes `feat(03-13)` for Task 2 (`8cf0330` then `96a9169`). Task 1 is a tracer (not `tdd="true"`); its tests were written together with the code. No REFACTOR commit was needed.

**RED evidence (semantic assessment), Task 2, `AdminAlertTest`:** 2 of 10 tests failed, both on the planned assertion for the missing behaviour: "alerts once for two failures ... and reports the suppressed count" failed with `Failed asserting that actual size 2 matches expected size 1` (two mails instead of one), and "counts every suppressed failure of the window" failed because the body did not contain the suppressed-count line. The target tests executed, no setup or import fault.

**Green on first run (8 of 10):** the tracer already implemented per-class alerts, the cache fallback, the sendNow path, per-channel isolation, the throwing alerter, content truncation and the panel link. Each was proven non-vacuous by a mutation check at GREEN, each failing exactly the matching test:

| Mutation | Failed test |
|---|---|
| max length + 100 | content canary test |
| per-channel catch removed | mail-transport-throws and bell-fails tests |
| listener catch narrowed to LogicException | alerter-throws test |
| cache catch narrowed to LogicException | cache-fails test |
| raw body appended to the alert body | content canary test |
| stack trace appended to the alert body | content canary test |

The `gsd_run check tdd-red-evidence` classifier does not parse Pest/PHPUnit output, so no `RED_EVIDENCE_OK` record exists (same as plan 03-10).

## Files Created/Modified

- `app/Domain/Operations/Jobs/KokpitJob.php` - abstract job base, attributes, final middleware list, contract docblock
- `app/Domain/Operations/Jobs/Idempotent.php` - mandatory idempotence declaration attribute
- `app/Domain/Operations/Jobs/Middleware/RunsAsSystem.php` - runs `$next($job)` in `PartnerContext::runAsSystem`
- `app/Domain/Operations/Alerts/OperationalAlert.php` - non-queueable notification, mail and Filament-format database payload
- `app/Domain/Operations/Alerts/AdminAlerter.php` - throttle, per-channel `sendNow`, critical log fallback, never throws
- `app/Domain/Operations/Alerts/ReportFailedJob.php` - JobFailed listener, content rules, System page link with panel-home fallback
- `app/Providers/OperationsServiceProvider.php` - registers the listener
- `app/Providers/Filament/AdminPanelProvider.php` - Admin-only `databaseNotifications()`, 30 s polling
- `config/kokpit.php`, `lang/cs/kokpit.php` - `alerts` thresholds and Czech texts under `kokpit.alerts.*`
- `database/migrations/2026_10_08_000300_make_notifications_data_jsonb.php` - `notifications.data` text to jsonb
- `tests/Support/Probes/FailingProbeJob.php`, `tests/Support/RedisTestQueue.php`, `tests/Feature/Operations/FailingJobFlowTest.php`, `tests/Feature/Operations/AdminAlertTest.php`

## Decisions Made

- **Bell: Admin-only condition.** `Panel::databaseNotifications()` accepts a closure (`HasNotifications.php`), so the bell is enabled with `static fn () => PartnerContext::isAdmin()`. The planner's fallback (panel-wide bell) was not needed.
- **System page link.** Until plan 03-15 creates the route `filament.admin.pages.system`, the alert links to the panel home URL; once the route exists the same code picks it up (plan 03-16 asserts the link).
- **Alert recipients.** Every user with the Admin role, looked up inside `runAsSystem`, because the listener runs outside the job middleware and without a signed-in user. No Admin yields a critical log entry, not silence.
- **Held-back counter TTL** of one day so it is still there when the window expires; the window marker itself lives exactly `throttle_seconds`.
- The config `alerts` key and the Czech texts were added in the Task 1 commit (the content rules need them); Task 2 only adds the throttle behaviour that reads `throttle_seconds`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] notifications.data is a text column, which breaks the Filament bell on PostgreSQL**
- **Found during:** Task 1 (full Pest run after enabling `databaseNotifications()`)
- **Issue:** 13 existing tests that request an Admin panel page failed with `operator does not exist: text ->> unknown`: the bell counts unread notifications with `"data"->>'format' = 'filament'`. With the bell on, every Admin page would return HTTP 500.
- **Fix:** New migration `2026_10_08_000300_make_notifications_data_jsonb.php` converts the column in place (`ALTER COLUMN data TYPE jsonb USING data::jsonb`, reversible). The Phase 2 create migration stays untouched. This is a column type change, not a new table, so it was handled under Rule 1 rather than Rule 4.
- **Files modified:** `database/migrations/2026_10_08_000300_make_notifications_data_jsonb.php` (not in the plan's file list)
- **Verification:** `ddev composer ci` green (772 tests); schema convention tests still pass; the flow test reads the stored data as JSON.
- **Committed in:** `d2d8e7f`

**2. [Rule 3 - Blocking] Pint style fixes on the new test files**
- **Found during:** Task 1 and Task 2 (Pint check)
- **Issue:** `fully_qualified_strict_types` and `ordered_imports` on the test files.
- **Fix:** `vendor/bin/pint` on those files.
- **Committed in:** `d2d8e7f`, `8cf0330`

---

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking style).
**Impact on plan:** The jsonb migration is a necessary consequence of enabling the bell; no scope creep. No Rule 4 trigger.

## Issues Encountered

- A first mutation attempt (BSD `sed` with an unescaped pattern) did not match, so the listener stayed registered and the test passed; this was noticed from the unchanged diff and the mutation was redone with a Python replace, which failed the flow test as expected. Later mutations asserted a match before replacing.
- Working-tree edits through the DDEV mount can lag by a second; each mutation run waited one second after the edit.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-03-32 (flooding), T-03-33 (leakage) and T-03-34 (silent failure) are mitigated and covered by the tests above. The column type change on `notifications` adds no new trust boundary.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Apply the new migration to long-lived development databases (`ddev exec php artisan migrate`); the test database migrates itself.
- Visual check of the bell (an unread danger notification with the "Otevřít stránku Systém" action) is left to the phase verification; the stored row format is asserted, the rendering is not.
- Production: the queue worker must run with the redis connection for the alert flow to matter; the production guard for `queue.default` and the after-commit dispatch contract belong to plan 03-14.

## Next Phase Readiness

- Plan 03-14 can build the architecture test on `KokpitJob` and `#[Idempotent]` (every concrete job must declare a non-empty `how`), the `after_commit` dispatch rule and the production queue guard.
- Plans 03-15 and 03-16 can reuse `OperationalAlert` and `AdminAlerter` for health alerts and create the `filament.admin.pages.system` route that the failed-job alert will then link to.
- Open item for later job plans: new jobs extend `KokpitJob` and declare `#[Idempotent]`; the Idempotent attribute is not yet enforced until 03-14.

## Self-Check: PASSED

- Created files exist: all 11 files under key-files.created found on disk.
- Commits `d2d8e7f`, `8cf0330`, `96a9169` are ancestors of HEAD.
- `ddev composer ci` green at the last code commit: Pest 772 passed (3300 assertions), Pint 225 files, Larastan no errors, licence check 201 packages.
- Acceptance criteria of both tasks re-run: all pass (Tries/Backoff attributes, final `middleware()`, `JobFailed::class`, `sendNow`, 0 `ShouldQueue` code lines in `OperationalAlert`, `databaseNotifications`, `throttle_seconds`/`message_max_length` literals, `Cache::add`, `Log::critical`, 0 `payload()` code lines in `ReportFailedJob`).

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
