---
phase: quick-261018-rvf
plan: 01
subsystem: tasks
tags: [filament, tasks, status, view-page]
requires: [TA-01]
provides:
  - "Change a task's status on the Admin task page via the 'Změnit stav' hint action"
affects: [app/Filament/Resources/TaskResource/Pages/ViewTask.php]
key-files:
  modified:
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskUpdateTest.php
decisions:
  - "Write goes through UpdateTask with the status only; no new Action, TaskPolicy unchanged"
  - "Hint action is attached in ViewTask::infolist(), shared builders untouched"
status: complete
commits: 1
plan_head_before: 42fd1c333564b7bbddb213612e2b636124d5583a
plan_head_after: 9701c56
actuals:
  tasks: 2
  commits: 1
completed: 2026-10-18
---

# Quick 261018-rvf: Change a task's status on the task page Summary

A link-style hint action "Změnit stav" on the "Stav" entry of the Admin task page opens a one-select modal and saves the status through UpdateTask, with the same effects as the edit page.

## What was done

- `ViewTask::changeStatusAction()`: `Action::make('changeStatus')`, link style, modal with a required `Select` over `ProjectStatus` prefilled with the current status. Attached to the `status` TextEntry inside `ViewTask::infolist()`.
- Visibility: `TaskResource::canEdit()` (policy `update`, not archived). The body re-checks `TaskResource::canAccess()` (403), normalises the value, calls `UpdateTask` with `['status' => ...]`, maps a ValidationException to `mountedActions.0.data.status`, refreshes the record and shows the notice "Stav úkolu byl změněn".
- Czech strings: `tasks.actions.change_status`, `change_status_heading`, `change_status_submit`, `tasks.notifications.status_changed`.
- Tests in `TaskUpdateTest.php`: move to end of the new column, completed_at set/cleared, prefilled modal and labels, unknown status error under the select, archived task has no link and cannot run the action, Partner refusals (403 on the Admin page, action unresolvable after a session switch, AuthorizationException from UpdateTask, no link on `/admin/my-tasks/KEY-N`, row unchanged).

## Deviations from Plan

- Task 1 and Task 2 tests were written together, so the work is one commit (`9701c56`) instead of two. No Task 2 code change was needed: the guards built in Task 1 satisfied all refusal tests.
- Hidden hint actions cannot be resolved by `assertActionHidden` with a `schemaComponent` address (Filament throws `ActionNotResolvableException`). The tests assert the stronger fact instead: the label is not rendered and calling the action throws `ActionNotResolvableException`.
- `assertSchemaStateSet` compares the status as the `ProjectStatus` enum case, not the string.

## Verification

- `ddev composer ci` green (pest 6353 passed, 3 skipped; pint; phpstan; licenses).
- `scripts/check-sensitive.sh` clean; lefthook sensitive-content and gitleaks passed on commit.
- Note: a first `composer ci` run and a combined pest run (feature + Arch suites in one command) failed with missing-table errors; the same suites passed when rerun on their own and in the full run, so this was a shared-test-DB interference during overlapping runs, not caused by this change.

## Known Stubs

None.

## Threat Flags

None. No new endpoint or trust boundary; the Livewire action is guarded by canEdit(), canAccess() and UpdateTask authorization.

## Self-Check: PASSED

- ViewTask.php, lang/cs/kokpit.php, TaskUpdateTest.php modified and committed in 9701c56.
