---
phase: 05-tasks-and-kanban
plan: 06
subsystem: tasks
tags: [filament, repeater, checklist, admin-only, postgres-check, withcount]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model, TaskResource edit and view pages, subtasks, canary registry fixtures (plans 05-01 to 05-05)
provides:
  - task_checklist_items table (Admin-only, text, is_done, position, DB checks)
  - TaskChecklistItem model (DeniesPartners, AdminOnlyPolicy, morph alias, canary fixture)
  - Task::checklistItems() relation ordered by position then id
  - Checklist repeater on the task edit form (add, tick, drag to reorder, remove), the same for subtasks
  - checklist_progress column and entry (done/total, hidden without items) fed by one withCount per list query
affects: [05-08 comments, 05-10 and 05-11 boards (card progress can reuse TaskColumns::checklistCounts), 05-12 Partner task list (must not include the checklist)]

actuals:
  tokens: 6800
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "A Filament Repeater bound to an Admin-only hasMany relationship with orderColumn is the write path for working notes; EditRecord saves it after UpdateTask"
    - "List counts are a shared withCount spec (TaskColumns::checklistCounts); a row without the counts gets them with one loadCount, so a page never runs a query per row"
    - "Text columns that must not be blank use btrim with an explicit whitespace set, not plain btrim (which trims spaces only)"

key-files:
  created:
    - database/migrations/2026_10_10_000300_create_task_checklist_items_table.php
    - app/Domain/Tasks/Models/TaskChecklistItem.php
    - tests/Feature/Tasks/TaskChecklistTest.php
  modified:
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Support/TaskColumns.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php

key-decisions:
  - "The checklist lives in its own Admin-only table, so no column of the Partner-readable tasks table changes (research A1, Pitfall 6)"
  - "Checklist items are not activity-logged: they are working notes, not business identity"
  - "The text CHECK trims space, tab, CR and LF explicitly; plain btrim(text) accepted a tab-only or newline-only item"
  - "Checklist counts are one shared withCount spec in TaskColumns, used by the list query and by the page fallback loadCount"

patterns-established:
  - "Pattern: a regression guard for a query count renders the same page with few and with many rows and requires the same number of queries, and is mutation-checked by removing the withCount"
  - "Pattern: Pest helpers of this plan carry the taskChecklist prefix"

requirements-completed: [TA-03]

coverage:
  - id: D1
    description: "The Admin adds checklist items on a task edit page, ticks one, moves one to the top and removes one; after a reload the items, their done state and their order are as left"
    requirement: TA-03
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#adds, ticks and reorders checklist items on the edit page and keeps them after a reload"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#removes a checklist item on save"
        status: pass
    human_judgment: false
  - id: D2
    description: "A subtask has its own checklist on its own edit page, separate from its parent"
    requirement: TA-03
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#keeps the checklist of a subtask on its own edit page"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner reads zero checklist rows, including on a task of the own visible project; the canary registry fixture proves it"
    requirement: TA-03
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#shows a Partner no checklist item, not even on a task of the own visible project"
        status: pass
      - kind: unit
        ref: "tests/Isolation/CanaryRegistryTest.php (every PartnerIsolated model has a fixture and a Partner reads none of its canary rows)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The task list and the task page show done/total, nothing for a task without items, and a page of tasks needs a constant number of queries"
    requirement: TA-03
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#shows the checklist progress as done/total in the list and on the task page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#counts the checklist of all tasks of a page with a constant number of queries"
        status: pass
    human_judgment: false
  - id: D5
    description: "The database refuses blank (space, tab or newline only) or empty text, text over 500 characters, a negative position and an item without a task"
    requirement: TA-03
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskChecklistTest.php#refuses a malformed checklist item in the database"
        status: pass
    human_judgment: false
  - id: D6
    description: "The checklist section, the drag handle feel and the Czech wording read well to the Admin"
    requirement: TA-03
    verification: []
    human_judgment: true
    rationale: "Tests cover behaviour and the presence of the texts, not the look of the repeater, the drag interaction in a real browser or the owner's preferred Czech phrasing"

duration: 8 min
completed: 2026-10-08
status: complete
plan_head_before: ec4f716179fc7c008b5910c35752eb011b2eb0e0
plan_head_after: 33094a58ce23097e965186fe297a2ac80f28d874
---

# Phase 5 Plan 06: Todo Checklist Summary

**Admin-only todo checklist on tasks and subtasks: its own `task_checklist_items` table, a Filament repeater bound to the relationship with drag ordering, and a done/total progress column and entry fed by one `withCount` per list query.**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-08T21:29:25Z
- **Completed:** 2026-10-08T21:37:32Z
- **Tasks:** 2 (tracer plus one TDD task)
- **Files:** 12 in the code commits (3 created, 9 modified)

## Accomplishments

- `task_checklist_items`: uuid pk with the `uuidv7()` default, `task_id` FK RESTRICT, `text` varchar(500) with a non-blank CHECK, `is_done` default false, `position` with a `>= 0` CHECK, index on (`task_id`, `position`).
- `TaskChecklistItem` is `final`, `PartnerIsolated` with `DeniesPartners`, registered with `AdminOnlyPolicy`, morph alias `task_checklist_item`, and a canary fixture (the canary is the item text). `Task::checklistItems()` is ordered by `position`, then `id`.
- The task edit form has a "Kontrolní seznam" section with a `Repeater::make('checklistItems')->relationship()->orderColumn('position')->reorderable()` holding the text and a done toggle. The subtask edit page is the same page, so subtasks get the same list (D-11). The repeater saves after `UpdateTask`, so the Action stays the writer of the task itself.
- `checklist_progress` shows `done/total` in the list and on the task page and is hidden when a task has no items. The list query adds both counts with one `withCount`; a row without them (the task page) gets them with one `loadCount`.

## Task Commits

1. **Task 1 (tracer): the Admin keeps a checklist, a Partner reads none** - `5d9e9bc` (feat)
2. **Task 2 (TDD): progress and database rules**
   - RED `bcdacfd` (test)
   - GREEN `33094a5` (feat)

**Plan metadata:** the docs commit that carries this file.

## TDD Gate Compliance

- **Tracer (Task 1):** executed and committed like an auto task. The tracer verify (targeted Pest, Pint, PHPStan) passed, and the reorder guard was mutation-checked (removing `orderColumn` makes the reorder test fail), so expansion continued.
- **Task 2 RED** (`bcdacfd`): 3 of the 7 tests of the file failed. Semantic assessment: the two progress tests failed on `a table column with name [checklist_progress] exists` (the planned column is absent), and the database test failed on `Expected SQLSTATE 23514 but the statement was accepted` for a tab-and-newline-only text, a real defect of the tracer's constraint (see Deviations), not a setup, fixture or syntax fault. The other four database assertions (empty text, 501 characters, negative position, unknown task) passed in RED because the tracer migration already enforced them; they are the planned regression guard.
- **Task 2 GREEN** (`33094a5`): all 7 tests pass; the query-count test was mutation-checked (dropping `withCount` from the list query makes it fail, restored afterwards). Full suite: 1730 passed, 13088 assertions.
- No REFACTOR commit was needed.

## Decisions Made

- The checklist is not activity-logged and lives in its own table, as the plan states.
- The text constraint keeps its planned name `task_checklist_items_text_check` but trims an explicit whitespace set (see Deviations).
- The count spec is one method, `TaskColumns::checklistCounts()`, used by the list query (`withCount`) and by the page fallback (`loadCount`), so the two cannot drift.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The planned text CHECK accepted whitespace-only text made of tabs or newlines**
- **Found during:** Task 2 (RED test for whitespace-only text)
- **Issue:** The plan's constraint `length(btrim(text)) > 0` uses PostgreSQL `btrim` with its default set, which trims spaces only, so `" \t\n "` was stored. The plan's behavior list says whitespace-only text is refused.
- **Fix:** `length(btrim(text, E' \t\r\n')) > 0`. The constraint name is unchanged. The migration was edited in place: it was added by the previous commit of this plan and was still pending on the dev database, so no data migration is needed.
- **Files modified:** database/migrations/2026_10_10_000300_create_task_checklist_items_table.php
- **Verification:** `TaskChecklistTest` "refuses a malformed checklist item in the database"; full suite green.
- **Committed in:** 33094a5

**Plan interpretation (not deviations):** the plan's artifact table puts `withCount` in the `TaskResource` table query; the count spec itself sits in `TaskColumns::checklistCounts()` and the resource calls `withCount(TaskColumns::checklistCounts())`. Other Unicode spaces (for example a no-break space) are not trimmed by the constraint; the repeater requires non-empty text and the database check covers the ASCII whitespace an editor can produce.

**Total deviations:** 1 auto-fixed (Rule 1). **Impact:** none on scope; the fix is inside a file of the plan.

## Issues Encountered

- A right-after-write ddev Pest run read a stale copy of the test file once (a type error that did not match the file on disk); re-running after a short wait gave the correct result.
- During the mutation check of `orderColumn` I restored `TaskResource.php` from a stale backup file, which reverted the whole file to an older state (160 lines lost). I detected it from `git diff --stat` at once, restored the file from git (`git checkout -- <that file>`) and re-applied the plan's 24-line change; the Task 1 commit was made only after that and `git show --stat` of it lists the intended 24 insertions. No other file was touched.

## Known Stubs

None.

## Threat Flags

None. T-05-14 (checklist notes reaching a Partner) is mitigated as planned: separate table, `DeniesPartners`, `AdminOnlyPolicy`, canary fixture, and a test that a Partner reads zero rows and an empty `$task->checklistItems` for an own visible task. No Partner builder includes the checklist (pinned in plan 05-12).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for the next plan of the phase. Board cards can show the progress with `TaskColumns::checklistCounts()` and `checklistProgress()`. Open items for the owner (assumption A1 as adopted): a Partner does not see the checklist; the repeater saves the whole list on form save, so two simultaneous Admin sessions on one checklist end with the last save. Requirement TA-03 is shared with a sibling plan of the phase that has no SUMMARY yet, so it was not marked complete here (shared-ID gate).

## Self-Check: PASSED

- Created files exist: the migration, `TaskChecklistItem.php`, `TaskChecklistTest.php`.
- Commits `5d9e9bc`, `bcdacfd`, `33094a5` are ancestors of HEAD.
- Acceptance greps of both tasks pass; the verify commands (targeted Pest, full Pest, Pint, PHPStan) pass; `scripts/check-sensitive.sh` clean on every commit.
