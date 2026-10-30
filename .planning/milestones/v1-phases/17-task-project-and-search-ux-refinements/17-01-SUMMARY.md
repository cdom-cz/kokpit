---
phase: 17-task-project-and-search-ux-refinements
plan: 01
subsystem: tasks
tags: [kanban, board, tasks, filament, partner-isolation]
requires: []
provides:
  - tasks.hidden_on_board column (boolean NOT NULL DEFAULT false)
  - UpdateTask key hidden_on_board (the only writer of the flag)
  - single board filter in TaskBoard::applyFilters
  - Checkbox "Skrýt na nástěnce" on the task edit form
affects: [17-04 Signal type-ahead (offers hidden tasks too), 17-05, 17-07]
tech-stack:
  added: []
  patterns:
    - "one board choke point: applyFilters feeds columns() and move()"
    - "absent or null update key keeps a NOT NULL stored value"
key-files:
  created:
    - database/migrations/2026_10_29_000100_add_hidden_on_board_to_tasks_table.php
  modified:
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Tasks/Actions/UpdateTask.php
    - app/Domain/Tasks/Board/TaskBoard.php
    - app/Filament/Resources/TaskResource.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskBoardTest.php
    - tests/Feature/Tasks/TaskUpdateTest.php
    - tests/Feature/Schema/TasksTableTest.php
    - tests/Isolation/PartnerSafeColumnsTest.php
key-decisions:
  - "hidden_on_board is fillable and cast to boolean but not in LoggedAttributes: a display preference writes no history row"
  - "CreateTask is untouched: its explicit attribute list means a new task is always shown and a Partner payload cannot set the flag"
  - "The flag is a Partner-readable display column; the pinned tasks column list grows by it"
requirements-completed: [P17-06]
duration: 25min
completed: 2026-10-29
status: complete
commits: 2
plan_head_before: 7430d924fe5b099a45286a969d3480e362167b50
plan_head_after: 0cfa8508c845c9ca6d4473ae59206c1d6b630d30
actuals:
  tokens: 14000
  tasks: 2
  commits: 2
---

# Phase 17 Plan 01: Hide a task from the kanban boards Summary

An Admin-only "Skrýt na nástěnce" checkbox on the task form takes a task off the global and the project kanban board through one filter in `TaskBoard::applyFilters`, while lists, search, task page, timer and time entries keep the task.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: migration, model, UpdateTask, board filter, form checkbox, Czech copy, board test | a13bdaa |
| 2 | Safe-by-default tests: missing/null key, Partner refusal, no history, drop around hidden card, schema, Partner-safe pin | 0cfa850 |

## What was built

- Migration `2026_10_29_000100_add_hidden_on_board_to_tasks_table.php`: `boolean hidden_on_board NOT NULL DEFAULT false`, no backfill, no index; `down()` drops the column.
- `Task`: `@property bool`, fillable, boolean cast; not logged.
- `UpdateTask`: writes the flag only when the key is present and not null.
- `TaskBoard::applyFilters`: unconditional `where('tasks.hidden_on_board', false)` shared by `columns()` (cards and Done total) and `move()` (visible neighbour list).
- `TaskResource`: `Checkbox::make('hidden_on_board')` at the end of the Stav section; `actionData()` passes a bool or null for an absent key.
- Tests: tracer (edit checkbox hides/unhides on both boards, list still shows), update semantics, Partner refusal for `UpdateTask` and `CreateTask`, no history row, timer from a hidden task's page, raw insert default and 23502 on NULL, drop around a hidden card, Done total, empty column, global search; Partner-safe column pin extended.

## Deviations from Plan

### Notes

**1. [Interpretation] Drop position around a hidden card**
- The plan text says a drop at visible index 1 "puts it right after A in the stored order". `TaskBoard::insertionIndex` places a card directly before the visible card at that index, so with stored order A, hidden B, C the card lands as A, B, D, C. The test asserts that actual order (the visible order A, D, C is what the Admin sees; hidden B keeps its place relative to A). No production code changed for this.

No other deviations; no auto-fixes were needed.

## Verification status

- Worktree: `php -l` clean on every touched PHP file; `scripts/check-sensitive.sh` and the lefthook pre-commit hook (sensitive-content, gitleaks) passed on both commits.
- NOT run here (CLAUDE.md: worktree has no vendor/, shared DDEV database): migration, Pest, Pint, PHPStan. To run after merge on the HEAD branch: `ddev exec php artisan migrate`, then the plan gate `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Feature/Schema tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. The plan gate runtime was not measured.
- Unverified assumption to check first in that run: the new tests (Livewire `assertSchemaStateSet` on the new checkbox, `callAction('toggleTimer')` on a hidden task) were written without being executed.

## Known Stubs

None.

## Threat Flags

None. The flag is a Partner-readable display column (T-17-03, accepted); writes are Admin-only (T-17-02, covered by TaskUpdateTest).

## Self-Check: PASSED

- Files exist: migration, Task.php, UpdateTask.php, TaskBoard.php, TaskResource.php, lang, four test files.
- Commits a13bdaa and 0cfa850 are ancestors of HEAD.
