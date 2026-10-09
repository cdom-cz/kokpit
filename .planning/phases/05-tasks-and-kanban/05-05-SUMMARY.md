---
phase: 05-tasks-and-kanban
plan: 05
subsystem: tasks
tags: [filament, relation-manager, subtasks, soft-delete, kanban-board, postgres-exclusion]

requires:
  - phase: 05-tasks-and-kanban
    provides: CreateTask, TaskBoard lock and appendToColumn, UpdateTask, TaskResource with view and edit pages, tasks table with the composite parent key (plans 05-01 to 05-04)
provides:
  - CreateTask with an optional parent (one-level subtasks numbered from the project counter)
  - SubtasksRelationManager on the task page (Admin only, hidden on a subtask page)
  - ArchiveTask with the active-subtask guard and RestoreTask with the locked re-append
  - Archive and restore actions on the task list and the task page, TrashedFilter, parent column and parent link
  - Attachments section on the task page that states files arrive with the documents module
affects: [05-06 and later boards (archived tasks hold no slot), 05-08 comments, 05-10 and 05-11 boards (appendToColumn keeps completed_at), 05-12 Partner task list, Phase 9 documents (attachments)]

actuals:
  tokens: 12000
  tasks: 3
  commits: 6

tech-stack:
  added: []
  patterns:
    - "Archive and restore are domain Actions behind the board lock; the Filament actions only delegate and report a refusal as a failure notification"
    - "A restore appends the card while it is still archived (its own old position is not counted), then restores it, all in one transaction under the board lock"
    - "A relation manager that adds a condition to canViewForRecord aliases the trait method and ANDs it, so the access rule cannot be widened"

key-files:
  created:
    - app/Domain/Tasks/Actions/ArchiveTask.php
    - app/Domain/Tasks/Actions/RestoreTask.php
    - app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php
    - tests/Feature/Tasks/SubtasksTest.php
    - tests/Feature/Tasks/TaskArchiveTest.php
  modified:
    - app/Domain/Tasks/Actions/CreateTask.php
    - app/Domain/Tasks/Board/TaskBoard.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - lang/cs/kokpit.php

key-decisions:
  - "TaskBoard::appendToColumn keeps the completed_at of a task that is already Done, so a restored Done task keeps its completion time (plan: Done keeps completed_at); UpdateTask only calls it on a real status change, so its behaviour is unchanged"
  - "RestoreTask appends the card while it is still archived and restores it afterwards, so the column end is computed without the row's own old position"
  - "A refusal of ArchiveTask or RestoreTask is a ValidationException on the key task; the Filament actions show it as a danger notification instead of a field error"
  - "An archived task is read-only: TaskResource::canEdit is false for it and the page edit action is hidden, UpdateTask cannot find it anyway"
  - "The parent link entry and the attachments section live in TaskResource::infolist (the view page renders the resource infolist), not in ViewTask"

patterns-established:
  - "Pattern: a guard test is proven non-vacuous by a mutation run that switches the guard off; every guard of this plan was mutation-checked (12 mutations, all killed)"
  - "Pattern: Pest global helper functions in test files carry a file-specific prefix (taskArch*, subtask*) because the whole suite shares one function namespace"

requirements-completed: [TA-01]

coverage:
  - id: D1
    description: "The Admin adds a subtask from the task page; it is the next number of the project counter, in the same project, with its own page that links back to the parent"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#creates a subtask from the task page as the next number of the project and continues on its edit page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#opens a subtask at its own address and links back to the parent"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#numbers a task, its subtask and the next task 1, 2 and 3 from one counter"
        status: pass
    human_judgment: false
  - id: D2
    description: "A subtask has its own status, independent of the parent in both directions"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#keeps the status of a subtask and of its parent independent of each other"
        status: pass
    human_judgment: false
  - id: D3
    description: "A subtask cannot have subtasks and a parent from another project, an archived or an unknown parent is refused, in the UI, the Action and the database; a Partner cannot pass a parent"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#offers no subtasks tab and no create-subtask action on the page of a subtask"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#refuses a subtask as the parent of a subtask with the parent field error and uses no number"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#lets the database refuse a sub-subtask and a cross-project subtask even when the Action is bypassed"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#refuses a Partner that passes a parent, they create top-level tasks only"
        status: pass
    human_judgment: false
  - id: D4
    description: "The task page shows an attachments section that states files arrive with the documents module and offers no upload control"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/SubtasksTest.php#shows the attachments section with the note and offers no upload"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Admin archives and restores tasks; a parent with active subtasks cannot be archived, a restored task is re-appended at the end of its column without a position collision, a restored Done task keeps completed_at, and a subtask of an archived parent cannot be restored"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#refuses to archive a parent with an active subtask and changes nothing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#puts a restored task at the end of its column when its old position was taken"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#keeps the completion time of a restored Done task"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#refuses to restore a subtask whose parent is archived"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#archives and restores from the header actions of the task page and opens an archived task by its address"
        status: pass
    human_judgment: false
  - id: D6
    description: "No hard delete of a task exists in the UI, an archived task number is never reused, and a Partner is refused both Actions"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#offers no force delete anywhere on the task resource"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#never hands the number of an archived task out again"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskArchiveTest.php#refuses a Partner that calls the archive or the restore Action"
        status: pass
    human_judgment: false
  - id: D7
    description: "The subtasks tab, the archive confirmation wording and the Czech texts read well and the tab layout feels right to the Admin"
    requirement: TA-01
    verification: []
    human_judgment: true
    rationale: "Tests cover behaviour and the presence of the texts, not how the tab looks or whether the Czech wording is the owner's preferred phrasing"

duration: 11 min
completed: 2026-10-08
status: complete
plan_head_before: 63654fc98a21156825529965ce9c598d9e8e8d1d
plan_head_after: 785b9fe90461a63b3528bd11fcebf3856a4380d7
---

# Phase 5 Plan 05: Subtasks, Archive and Restore Summary

**One-level subtasks created from the task page through CreateTask (next number of the project counter, parent re-read under the board lock), plus archive-only deletion with an active-subtask guard and a restore that re-appends the card to its column under the board lock.**

## Performance

- **Duration:** 11 min
- **Started:** 2026-10-08T21:14:49Z
- **Completed:** 2026-10-08T21:26:00Z
- **Tasks:** 3 (tracer plus two TDD tasks)
- **Files:** 10 in the code commits (5 created, 5 modified)

## Accomplishments

- `CreateTask::handle(..., ?Task $parent = null)`: with a parent the row is re-read `lockForUpdate` after the board lock and project lock and before the number is allocated. A subtask as parent, a parent of another project, an archived parent and an unknown parent are the field error `parent` (with four distinct Czech messages), a Partner passing a parent is refused (`parent_not_allowed`); a subtask gets `depth` 1 and the next number of the same project counter (ABC-1, ABC-2, ABC-3 in creation order).
- `SubtasksRelationManager`: Admin only through the access rule, `canViewForRecord` additionally false for a subtask (the trait method is aliased and ANDed, so the rule cannot be widened), columns reference (linked), title, status, priority, assignee, due date, and a title-only create action that calls `CreateTask` with the owner as parent and continues on the new subtask's edit page.
- The task page shows the parent reference of a subtask as a link back, the list has a parent column, and every task page has an attachments section with the Czech note that files arrive with the documents module (no upload control).
- `ArchiveTask`: Admin only (`delete` ability), board lock, row locked, refuses a task with active subtasks (field error `task`, `has_active_subtasks`), soft delete only, tags stay. `RestoreTask`: Admin only (`restore` ability), refuses a subtask whose parent is archived, appends the card to the end of the column of its stored status while it is still archived, then restores it, so no position collides at commit.
- `TaskResource`: archived tasks stay reachable (soft-delete scope lifted for the list and the route binding), `TrashedFilter`, row and page archive/restore actions that delegate to the Actions and show a refusal as a notification, archived tasks are read-only and out of the global search, and no force delete exists anywhere.

## Task Commits

1. **Task 1 (tracer): subtasks from the task page** - `b44162f` (feat)
2. **Task 2 (TDD): one level and same project only** - `71eb502` (test, see TDD Gate Compliance)
3. **Task 3 (TDD): archive and restore**
   - RED `4eb2c15` (test)
   - GREEN `785b9fe` (feat)

**Plan metadata:** the docs commit that carries this file.

The measured commit count `6` (see `actuals`) includes two commits that are not part of this plan: `f28bfd5` and `3e77b37`, todo captures made by another session on the same branch while this plan ran. The plan's own commits are the four above.

## TDD Gate Compliance

- **Tracer (Task 1):** executed and committed like an auto task; the tracer verify (targeted Pest, Pint, PHPStan) was re-run end to end and passed, so expansion continued.
- **Task 2: no failing RED exists, by design of the plan.** The plan's Task 1 action already specified the full `CreateTask` parent contract (all four parent checks, the Partner refusal) and the `canViewForRecord` condition, so the tracer commit shipped the guards and Task 2's tests passed on their first run (unexpected green). Investigation per the TDD fail-fast rule: the feature was present because the tracer implemented the whole artifact contract, not because the tests were wrong. To prove the tests are not vacuous, six mutations were applied one at a time to the production code (parent_is_subtask check, other-project check, archived check, unknown-parent check, Partner check, the subtask condition of `canViewForRecord`); each made at least one test fail, and the code was restored after each. Task 2 therefore has a single `test(05-05)` commit and no `feat(05-05)` of its own; the GREEN is the tracer commit `b44162f`.
- **Task 3 RED** (`4eb2c15`): 15 of 16 tests failed, 14 on the missing `ArchiveTask` and `RestoreTask` classes (a missing-symbol failure of the planned classes) and the row-action tests on `Action [archive] not found on table`. Semantic assessment: all failed because the feature was absent, none on a setup, fixture or syntax fault. The 16th, "offers no force delete anywhere on the task resource", passed in RED: it is a negative invariant that is true before and after, and stays as a regression guard.
- **Task 3 GREEN** (`785b9fe`): all 17 archive tests pass; six mutations (active-subtask guard, `appendToColumn` call, archived-parent guard on restore, the Done `completed_at` keep, and the two `Gate` authorisations) were each killed.
- No REFACTOR commit was needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] A restored Done task kept no completion time**
- **Found during:** Task 3 (design of RestoreTask)
- **Issue:** The plan requires `appendToColumn($task, $task->status)` and that a Done task keeps `completed_at`, but `appendToColumn` always set `completed_at` to now for Done, which would overwrite the real completion time on every restore.
- **Fix:** `TaskBoard::appendToColumn` keeps the existing `completed_at` when the task is already Done and has one. `UpdateTask` calls it only on a real status change, so no other caller behaves differently.
- **Files modified:** app/Domain/Tasks/Board/TaskBoard.php (inside `files_modified`)
- **Verification:** `TaskArchiveTest` "keeps the completion time of a restored Done task" (mutation-checked); full suite green.
- **Committed in:** 785b9fe

**2. [Rule 2 - Missing critical] An archived task was editable and appeared in the global search**
- **Found during:** Task 3
- **Issue:** Lifting the soft-delete scope so archived tasks open by their address would also let the global search return them and leave the edit page open; `UpdateTask` cannot find an archived row, so the edit page would have ended in a 404 on save.
- **Fix:** `TaskResource::canEdit` is false for an archived task, the page edit action is hidden, and the global search query excludes archived rows. Tests added for the edit refusal and the search.
- **Files modified:** app/Filament/Resources/TaskResource.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php
- **Committed in:** 785b9fe

**3. [Rule 3 - Blocking] Global Pest helper name clash**
- **Found during:** Task 3 full-suite run
- **Issue:** My test helper `archiveProject()` already exists in `tests/Feature/Clients/ClientArchiveTest.php`; the whole suite aborted with "Cannot redeclare function". The targeted runs did not load the other file, and the RED commit `4eb2c15` carries the clashing name.
- **Fix:** Helpers renamed to `taskArchProject`, `taskArchTask`, `taskArchErrors` (my own file only) in the GREEN commit.
- **Committed in:** 785b9fe

**Plan interpretation (not deviations):** the parent link entry and the attachments section sit in `TaskResource::infolist` (the view page renders the resource infolist) rather than in `ViewTask`; `ViewTask` got the archive and restore header actions and the hidden edit action. The list parent column is built in `TaskResource::columns()` (a splice into `TaskColumns::adminColumns()`) so `TaskColumns` stays untouched. A Partner passing a parent is a field error, not an exception, so it is a neutral answer like the other parent errors.

**Total deviations:** 3 auto-fixed (2 Rule 2, 1 Rule 3). **Impact:** none on scope; all inside the files of the plan.

## Issues Encountered

- A right-after-write ddev Pest run read a stale copy of the renamed test file once (the "Cannot redeclare" fatal reappeared after the fix); re-running after a short wait gave the correct result.
- The first full-suite attempt aborted on the helper name clash described above; after the rename the full suite passed (1723 passed, 13039 assertions), with Pint and PHPStan clean.
- The first full-suite output was empty because of an output filter on a fatal error; the second run captured to a file showed the cause.

## Known Stubs

None. The attachments section is an intentional, documented reservation (D-11): it states that files arrive with the documents module (Phase 9, DO-01) and renders no control; it is covered by a test.

## Threat Flags

None. The surfaces added (subtask create action, archive and restore actions, archived-task routes) are covered by T-05-12 and T-05-13 of the plan's threat model: forged parent (Action checks under lock, composite key in the database, Partner refused, all tested and mutation-checked) and archive/restore ordering (guard and locked re-append, tested with `SET CONSTRAINTS ALL IMMEDIATE`).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for 05-06. Archived tasks hold no board slot and a restore goes through `TaskBoard::appendToColumn`, which the board plans can reuse; `appendToColumn` now keeps an existing Done completion time. Open items for the owner (assumption A9 as adopted): archiving a parent with active subtasks is refused rather than cascaded, and restoring a subtask of an archived parent is refused until the parent is restored.

## Self-Check: PASSED

- Created files exist: ArchiveTask.php, RestoreTask.php, SubtasksRelationManager.php, SubtasksTest.php, TaskArchiveTest.php.
- Commits `b44162f`, `71eb502`, `4eb2c15`, `785b9fe` are ancestors of HEAD.
- Acceptance greps of all three tasks pass; the verify commands (targeted Pest, full Pest, Pint, PHPStan) pass; `scripts/check-sensitive.sh` clean on every commit.
