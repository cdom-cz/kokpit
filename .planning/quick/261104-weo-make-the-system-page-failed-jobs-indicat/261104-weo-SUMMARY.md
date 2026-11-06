---
phase: 261104-weo-make-the-system-page-failed-jobs-indicat
plan: 261104-weo
subsystem: operations-health
tags: [health, horizon, queue, system-page]
requires: []
provides:
  - FailedJobsIndicator counts Horizon's failed set on a Redis queue with Horizon bound
affects: [System page failed-jobs slot]
key-files:
  modified:
    - app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php
    - tests/Feature/Operations/HealthIndicatorsTest.php
    - config/horizon.php
decisions:
  - "Source is chosen from the configured driver of queue.default (no Queue::connection() resolution), so choosing the source never builds a connection and a misnamed connection never throws"
  - "countFailed() is used, not countRecentlyFailed(), so horizon:forget can acknowledge a reviewed failure"
status: complete
commits: 2
plan_head_before: 2c9470ad5b549b24210ee99c0afe8ee163981d22
plan_head_after: 17e8c855c0849b121d10dd2abbc2be45f4b5623c
actuals:
  tokens: 6000
  tasks: 2
  commits: 2
completed: 2026-11-04
---

# Phase 261104-weo Plan 261104-weo: Failed jobs indicator source Summary

The System page "Failed jobs" slot now reads Horizon's `JobRepository::countFailed()` when the default queue connection uses the redis driver and Horizon is bound, and keeps counting through the queue failer everywhere else.

## Tasks

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Horizon failed-set count end to end (tracer) | 94b6530 |
| 2 | Five branch tests and horizon.php trim comment | 17e8c85 |

## What changed

- `FailedJobsIndicator` got a private `horizonRepository()` that returns the bound `JobRepository` only when the default connection's driver is `redis`. `check()` takes the count from Horizon or from the failer (unchanged NotAvailable handling), then applies the shared threshold logic once. Constructor stays argument-less, so the registry wiring is untouched.
- Six new Pest tests: tracer through `/admin/system` (mocked Horizon count 2, empty `failed_jobs`), Horizon count, table ignored, threshold on Horizon, non-Redis failer path with Horizon never asked, Redis failer fallback without Horizon.
- `config/horizon.php`: the comment above `trim` no longer calls the table the authoritative record; it names `trim.failed` as the window of the System page count on Redis.

## Deviations from Plan

None - plan executed as written. The Redis branch tests mock `JobRepository`, so no live Redis is needed for them.

## Verification state

Only cheap checks were run in the worktree: `php -l` on all three files, the grep gates from both tasks, and `scripts/check-sensitive.sh` (clean, plus the lefthook hook passed on both commits). Pint, PHPStan and Pest have NOT been run; per project rules they run after the merge in DDEV (`tests/Feature/Operations/HealthIndicatorsTest.php`, then the full suite).

## Known Stubs

None.

## Threat Flags

None.

## Self-Check: PASSED

Files exist, commits 94b6530 and 17e8c85 are on the branch.
