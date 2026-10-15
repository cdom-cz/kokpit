---
phase: quick-261015-diz
plan: 01
subsystem: tasks-ui
tags: [filament, tasks, dashboard, overdue, shared-cell]
requires:
  - "quick-261015-cv5 (collapsible dashboard widgets)"
provides:
  - "TaskDueDate: the one home of the Prague today, overdue-day math, open-task rule and the shared due ViewColumn"
  - "View filament.tasks.due-date-cell shared by the Admin task list and the Nejblizsi terminy widget"
affects:
  - "Admin task list /admin/tasks (due column)"
  - "UpcomingDeadlines widget (due column now built by TaskDueDate::column)"
tech-stack:
  added: []
  patterns:
    - "One ViewColumn builder plus one Blade cell for a column rendered on two surfaces"
key-files:
  created:
    - app/Filament/Support/TaskDueDate.php
    - resources/views/filament/tasks/due-date-cell.blade.php
    - tests/Feature/Tasks/TaskListOverdueTest.php
  modified:
    - app/Filament/Support/TaskColumns.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Widgets/UpcomingDeadlines.php
    - resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md
decisions:
  - "The list shows Dnes for a task due today, like the widget: one view means one rendering"
  - "Open means not done and not archived (the widget's rule); the rule lives in TaskDueDate::overdueDaysOf()"
  - "No new Czech copy key: the cell reuses kokpit.dashboard.deadlines.today, .overdue_badge and .overdue_days"
  - "The Partner list keeps its TextColumn from partnerColumns(); the shared cell never reaches a Partner surface"
metrics:
  duration: "about 25 min (of which about 5 min the full CI run)"
  completed: 2026-10-15
status: complete
commits: 3
plan_head_before: 758bee0735dbb1a9f801888672746e3c0e8f7c97
plan_head_after: f9dc2e7cbc2ebed354ecd320f9696b715ccbb874
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
---

# Phase quick-261015-diz Plan 01: Overdue highlight on the Admin task list Summary

The Admin task list now shows the same overdue highlight as the "Nejblizsi terminy" widget (danger date, "Po terminu" badge with the warning triangle, "Po terminu o N den/dny/dni" line), rendered from one shared cell view and one column builder, `TaskDueDate`.

## What was built

- `App\Filament\Support\TaskDueDate` holds the Prague today, `overdueDays()` (moved from the widget), `overdueDaysOf(Task, today)` (0 for no due date, done, archived, due today or later) and `column($padding)`, which returns the shared `ViewColumn`.
- `filament.tasks.due-date-cell` renders "Dnes" (semibold) for today, a plain date for later days, the danger date + badge + line for an overdue open task, and the `fi-ta-placeholder` empty value for no due date. Padding comes from class constants (`CARD_PADDING` for the widget, `LIST_PADDING` for the list).
- `TaskColumns::adminColumns()` builds `due_date` through `TaskDueDate::column(TaskDueDate::LIST_PADDING)` and stays sortable; `partnerColumns()` and the PARTNER_* pins are untouched.
- `UpcomingDeadlines` builds its due column through `TaskDueDate::column(TaskDueDate::CARD_PADDING)`; its own `overdueDays()` and the due branch of its cell view are gone. The widget cell view now renders the task cell only.
- CONTRIBUTING (Dashboard bullet) names the shared cell and `TaskDueDate`; `RepositoryFilesTest` pins the class name.
- The todo is moved to `.planning/todos/completed/` with `git mv`; the rename is staged and uncommitted for the orchestrator's docs commit.

## Tasks

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: Admin list overdue highlight through the shared cell | f66e9a0 |
| 2 | Widget on the shared cell, Partner list proven unchanged | 9265975 |
| 3 | CONTRIBUTING note, RepositoryFilesTest pin, full gate, todo moved | f9dc2e7 (todo move staged, not committed) |

## Verification

- `tests/Feature/Tasks/TaskListOverdueTest.php`: 10 tests (overdue counts, today/later, done, archived, undated, Prague boundary at 22:30 and 21:30 UTC, sort both ways, overdueDaysOf unit cases, two Partner-unchanged tests).
- `AdminDashboardTest` passes unchanged (no edits).
- `grep -rl 'deadlines.overdue_badge' resources/views app` lists exactly one file.
- `ddev composer ci`: green (3942 passed, 3 skipped; pint, phpstan, licence check OK).
- `scripts/check-sensitive.sh` clean on every touched file; lefthook hook (sensitive-content, gitleaks) passed on every commit.

## Deviations from Plan

None - plan executed exactly as written.

## Known Stubs

None.

## Threat Flags

None. The shared cell is built only by the AdminOnly resource columns and the AdminOnly widget; the Partner test pins a plain `TextColumn` and a body without "Po terminu" or the danger colour.

## Self-Check: PASSED

- FOUND: app/Filament/Support/TaskDueDate.php, resources/views/filament/tasks/due-date-cell.blade.php, tests/Feature/Tasks/TaskListOverdueTest.php
- FOUND commits: f66e9a0, 9265975, f9dc2e7
