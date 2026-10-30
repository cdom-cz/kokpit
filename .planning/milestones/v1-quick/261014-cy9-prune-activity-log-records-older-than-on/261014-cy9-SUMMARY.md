---
phase: quick-261014-cy9
plan: 01
subsystem: audit
tags: [activitylog, scheduler, retention, partner-scope]
requires: []
provides:
  - nightly prune of activity_log rows older than 365 days
affects: [audit, operations]
tech-stack:
  added: []
  patterns:
    - package action override that wraps the delete in PartnerContext::runAsSystem()
key-files:
  created:
    - app/Domain/Audit/KokpitCleanActivityLogAction.php
    - tests/Feature/Operations/ActivityLogPruningTest.php
  modified:
    - config/activitylog.php
    - routes/console.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - README.md
    - .planning/STATE.md
  deleted:
    - app/Domain/Audit/RefusingCleanActivityLogAction.php
    - tests/Feature/Operations/NoPruningTest.php
decisions:
  - "Activity rows older than 365 days (activitylog.clean_after_days) are pruned nightly; supersedes Phase 03 D-09 and the 03-11 never-pruned decision (threat T-03-27) on the owner's request"
  - "The clean action deletes as system so the fail-closed PartnerScope cannot reduce the delete to 1 = 0 (WR-04)"
requirements: [FND-08]
status: complete
duration: ~25 min
completed: 2026-10-14
estimate:
  tokens: 45000
  raw_tokens: 45000
  tasks: 3
  confidence: low
actuals:
  tokens: 9000
  tasks: 3
  commits: 7
plan_head_before: 9686f864d0a0fa45c227d19cce389b3e0dc3f126
plan_head_after: ab8ec7fcf772bc6668e852f2fed4d4f28e25a934
---

# Phase quick-261014-cy9 Plan 01: Prune activity log records older than one year Summary

Nightly `activitylog:clean --force` (daily 00:00, one server) deletes activity rows older than 365 days through `KokpitCleanActivityLogAction`, which runs the package delete as system so the Partner scope cannot turn it into a silent no-op.

## Supersedes Phase 03 D-09

This quick task reverses Phase 03 decision D-09 ("activity records are kept, no pruning"), the enforcement added in plan 03-11 (`RefusingCleanActivityLogAction` and `NoPruningTest`) and its mitigation T-03-27, on the owner's explicit request. Phase 03 artifacts stay untouched as history. The supersession is recorded in STATE.md under Decisions. Verifier and later planners should treat "never pruned" as no longer true. Data older than 365 days is recoverable only from PostgreSQL backups.

## What was built

- `App\Domain\Audit\KokpitCleanActivityLogAction` extends the package action; `execute()` returns `app(PartnerContext::class)->runAsSystem(fn () => parent::execute(...))`. No raw query, no scope removal (QueryEscapeHatchTest stays green with its empty allowlist).
- `config/activitylog.php`: `actions.clean_log` points at the new action; the `clean_after_days` comment describes the nightly prune; the value stays the integer 365 and is the only place retention is set.
- `routes/console.php`: `Schedule::command('activitylog:clean', ['--force'])->daily()->name('kokpit-activity-log-prune')->onOneServer()`. `--force` is needed because the command's ConfirmableTrait refuses in production in a non-interactive run. No `--days` and no log argument, so every log uses the config retention.
- `ActivityLogPruningTest` replaces `NoPruningTest`: config wiring, end-to-end prune (5 years, 366 days, 364 days, now, across the default and `fictional-archive` logs, no user), WR-04 non-vacuity (the package's plain action deletes 0 in the same state, the new one deletes 1), system flag restored, and the schedule contract (one event, name, `0 0 * * *`, onOneServer, `--force`, no `--days`).
- `RefusingCleanActivityLogAction` deleted; `RepositoryFilesTest` now checks `KokpitCleanActivityLogAction`; `ScheduleOnOneServerTest` lists `kokpit-activity-log-prune`; CONTRIBUTING.md and README.md describe the nightly prune.

## TDD runs

- RED (`ActivityLogPruningTest`, after deleting NoPruningTest, before any implementation): 5 of 5 failed. Config wiring failed on the old class; the prune and the flag test threw the old "Refusing to clean ... (D-09)" RuntimeException; the non-vacuity test failed with `Target class [App\Domain\Audit\KokpitCleanActivityLogAction] does not exist`; the schedule test failed with 0 events instead of 1.
- GREEN: 5 of 5 passed. Together with ScheduleOnOneServerTest, QueryEscapeHatchTest and ActivitySourceTest: 27 passed. Final `ddev composer ci`: 2623 tests passed, Pint clean (586 files), PHPStan no errors, licence check OK (213 packages).

## Backdating

`activity()->createdAt(now()->subDays(N))->log(...)` was enough to backdate rows (also with a named log, `activity('fictional-archive')`). The `DB::table()->update()` fallback was not needed.

## Commits

| Task | Commit | Files |
| ---- | ------ | ----- |
| 1 | b73c341 | `app/Domain/Audit/KokpitCleanActivityLogAction.php`, `config/activitylog.php`, `routes/console.php`, `tests/Feature/Operations/ActivityLogPruningTest.php`, `tests/Feature/Operations/NoPruningTest.php` (deleted) |
| 2 | eaec160 | `app/Domain/Audit/RefusingCleanActivityLogAction.php` (deleted), `tests/Feature/Repo/RepositoryFilesTest.php`, `CONTRIBUTING.md`, `README.md` |
| 3 | ab8ec7f | `tests/Feature/Operations/ScheduleOnOneServerTest.php` |

The STATE.md decision bullet was edited in the working tree as planned and left for the orchestrator; a concurrent Phase 7 docs commit (5562b2f) already swept it into git. The measured `commits: 7` in the frontmatter counts `plan_head_before..HEAD` and includes 4 unrelated Phase 7 docs commits made by another session in the same window (9889e6b, 5562b2f, 2d9dc3e, 908c381); this plan's own commits are the three above.

## Deviations from Plan

None - plan executed exactly as written. RED and GREEN were run in one session and committed together with the implementation (the plan allows that); the old NoPruningTest deletion shares the Task 1 commit.

## Deferred Issues

None. `ddev composer ci` passed in full, no unrelated failures.

## Known Stubs

None.

## Threat Flags

None. The only new surface is the nightly console delete already covered by T-261014-cy9-01 to -06.

## Self-Check: PASSED

- FOUND: app/Domain/Audit/KokpitCleanActivityLogAction.php
- FOUND: tests/Feature/Operations/ActivityLogPruningTest.php
- MISSING (intentionally deleted): app/Domain/Audit/RefusingCleanActivityLogAction.php, tests/Feature/Operations/NoPruningTest.php
- FOUND commits: b73c341, eaec160, ab8ec7f
