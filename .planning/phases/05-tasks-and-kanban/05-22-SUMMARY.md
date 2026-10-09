---
phase: 05-tasks-and-kanban
plan: 22
subsystem: testing
tags: [pest, uuid-v7, test-determinism, activity-log, gap-closure]

requires:
  - phase: 05-tasks-and-kanban
    provides: "05-21 description edit path (D-16) and its closing-gate claim"
provides:
  - "partnerDescHistory() and escalationComments() order by created_at then id (deterministic ties)"
  - "any-Partner description dataset case proves each save by the one new description_changed row, independent of row order"
  - "recorded frozen-clock proof that the UUID v7 id carries the order among tied timestamps"
  - "five green tests/Feature/Tasks runs and one green full suite (2047 passed)"
affects: [05-verification, phase-5-reverification]

actuals:
  tokens: 700
  tasks: 2
  commits: 2
plan_head_before: 6968505014a61ea2ba880d063435f3df488c6407
plan_head_after: 66b42e0035a4de1ed9fe5362be1e1e2ad7d7aed3
commits: 2

tech-stack:
  added: []
  patterns:
    - "Test helpers that read activity or comment rows order by created_at, then by the UUID v7 id"

key-files:
  created: []
  modified:
    - tests/Feature/Tasks/PartnerTaskDescriptionTest.php
    - tests/Feature/Tasks/TaskEscalationTest.php

key-decisions:
  - "Fix the flaky any-Partner case in the test helper (created_at then id) and add an order-independent new-row proof; production code stays untouched"
  - "Apply the same tiebreaker to the escalation comments helper as a latent-instance fix"

requirements-completed: [TA-07, TA-01]

coverage:
  - id: D1
    description: "The any-Partner description dataset case no longer depends on tied created_at values"
    requirement: "TA-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#lets any Partner of the client edit the description of a task in an editable status, whatever its requester or assignee"
        status: pass
    human_judgment: false
  - id: D2
    description: "Escalation comments helper breaks created_at ties by id"
    requirement: "TA-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskEscalationTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Closing gate: tests/Feature/Tasks green five times in a row and the full suite green once"
    requirement: "TA-07"
    verification:
      - kind: integration
        ref: "ddev exec vendor/bin/pest tests/Feature/Tasks --compact (5 runs), ddev exec vendor/bin/pest --compact"
        status: pass
    human_judgment: false

duration: 9min
completed: 2026-10-09
status: complete
---

# Phase 05 Plan 22: Deterministic description history order Summary

**The any-Partner description dataset case is deterministic: activity rows are ordered by created_at then UUID v7 id, each save is proven by its own new row, and the closing gate is green five times in a row plus once for the full suite (2047 passed).**

## Performance

- **Duration:** 9 min
- **Started:** 2026-10-09T10:26:27Z
- **Completed:** 2026-10-09T10:35:00Z
- **Tasks:** 2
- **Files modified:** 2 (tests only)

## Accomplishments

- `partnerDescHistory()` orders by `created_at` then `id`; its docblock explains the whole-second `created_at` and the UUID v7 tiebreaker.
- The dataset case collects `$editIdsBefore`, computes `$newEdits` by id difference and expects exactly one new row whose `causer_id` is the saving Partner; the description and `array_last(...)->causer_id` expectations stay.
- `escalationComments()` got the same tiebreaker (latent instance, no assertion change). Every `created_at` ordering in `tests/Feature/Tasks` now carries the id tiebreaker (2 of 2).
- Closing gate of plan 05-21 re-established as reliably true (see "Closing gate runs").

## Assumption check

UUID v7 ordering assumption, checked with a temporary `Carbon::setTestNow(Carbon::now()->startOfSecond())` at the start of the dataset case and `Carbon::setTestNow()` at its end, so every activity row of the case shares one whole-second `created_at`. Command: `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskDescriptionTest.php --filter="lets any Partner of the client edit the description"`.

| Step | Variant | Result |
|---|---|---|
| (a) run 1 | ascending id, clock frozen | 2 passed (66 assertions), 2.16s |
| (a) run 2 | ascending id, clock frozen | 2 passed (66 assertions), 1.64s |
| (a) run 3 | ascending id, clock frozen | 2 passed (66 assertions), 1.72s |
| (b) | descending id (`orderByDesc('id')`), clock frozen | 2 failed (44 assertions): both datasets (planned, to clarify) failed at the `array_last(partnerDescEdits($task))->causer_id` expectation (line 265); the `$newEdits` expectations on lines 263-264 passed |
| (c) | mutation reverted with `git checkout -- tests/Feature/Tasks/PartnerTaskDescriptionTest.php` | `git diff HEAD` on the file empty; file re-run: 28 passed (338 assertions), 4.80s |

Conclusion: with all timestamps tied, the id alone orders the rows in write order (ascending passes three times, descending fails on the last-row causer), so the UUID v7 assumption holds at run time and the tiebreaker is what the case depends on. The new-row expectations are independent of row order, as designed.

## Closing gate runs

All runs strictly sequential, none in the background.

| Run | Command | Passed | Failed | Assertions | Duration |
|---|---|---|---|---|---|
| 1 | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` | 365 | 0 | 1942 | 41.22s |
| 2 | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` | 365 | 0 | 1942 | 40.42s |
| 3 | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` | 365 | 0 | 1942 | 33.92s |
| 4 | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` | 365 | 0 | 1942 | 36.10s |
| 5 | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` | 365 | 0 | 1942 | 32.42s |
| Full | `ddev exec vendor/bin/pest --compact` | 2047 | 0 | 15333 | 215.89s |

- Pint (`ddev exec vendor/bin/pint --test`): PASS, 458 files.
- PHPStan (`ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`): [OK] No errors.
- `scripts/check-sensitive.sh --all`: clean (generic patterns only; `KOKPIT_DENYLIST` not set in this environment).
- Production untouched: `git diff --name-only 6968505..HEAD -- app database lang config routes resources bootstrap composer.json composer.lock` prints nothing.

## Task Commits

1. **Task 1: deterministic description history and order-independent causer proof** - `acee05f` (test)
2. **Task 2: escalation comments tiebreaker and closing gate** - `66b42e0` (test)

**Plan metadata:** recorded by the docs(05-22) commit that adds this file.

## Files Created/Modified

- `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` - helper tiebreaker, docblock, new-row proof in the any-Partner dataset case
- `tests/Feature/Tasks/TaskEscalationTest.php` - helper tiebreaker and docblock

## Decisions Made

- Fixed the flake in the test layer only: the production code was correct (both `description_changed` rows carried the right causers), only the read order was undefined.
- Gave the escalation comments helper the same tiebreaker although it cannot fail today, so a later case that reads past one row cannot flake.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None. The Task 1 mutation was applied and reverted in the working tree only; it was never committed.

## Known Stubs

None.

## Threat Flags

None - tests only, no new surface.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- The gap of 05-VERIFICATION.md (flaky any-Partner description case) is closed; Phase 5 is ready for re-verification.
- The five `human_verification` items of 05-VERIFICATION.md remain open for the owner and are not claimed here.

## Self-Check: PASSED

- Modified files exist: `tests/Feature/Tasks/PartnerTaskDescriptionTest.php`, `tests/Feature/Tasks/TaskEscalationTest.php`.
- Commits `acee05f` and `66b42e0` are ancestors of HEAD.
- Acceptance criteria re-checked: the helper `orderBy('created_at')` followed by `orderBy('id')` in both files; `$editIdsBefore`, `$newEdits` and the `array_last(...)` expectation present; `git diff HEAD` on the mutated file empty; created_at orderings in `tests/Feature/Tasks` all carry the id tiebreaker (2 of 2); production diff empty.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
