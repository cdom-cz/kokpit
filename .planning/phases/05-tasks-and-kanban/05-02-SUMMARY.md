---
phase: 05-tasks-and-kanban
plan: 02
subsystem: tasks
tags: [postgres, trigger, concurrency, number-sequences, filament, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: tasks table, Task model, CreateTask Action, TaskBoard (plan 05-01)
  - phase: 04-clients-and-projects
    provides: Project model, UpdateProject Action, ProjectResource form
provides:
  - projects_key_frozen_guard trigger (SQLSTATE KP002) freezing the project key once any task exists, trashed ones included
  - Project::tasks() relation, UpdateProject field error key_frozen, disabled key field with hint in the Admin form
  - Raw-SQL proof of every tasks constraint (unique, CHECK, composite parent key, RESTRICT, deferred position exclusion)
  - Pinned Partner-readable tasks column list with a suspicious-name check
  - Parallel-process proof (8 workers x 25 creations) of gap-free task numbers, with a mutation run that fails when the counter lock is removed
affects: [05-04 Admin resource, 05-05 subtasks, 05-11 board, phase-10 invoicing]

actuals:
  tokens: 12750
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Writer-independent guard: BEFORE UPDATE OF key row trigger with a user-defined SQLSTATE (KP002), translated to a field error in the Action"
    - "Concurrency harness for a composed Action: worker binds the allocator class in the container; the mutation run also removes the board advisory lock and widens the read-to-advance window"
    - "Deferred constraints are only checked in RefreshDatabase tests where the case runs SET CONSTRAINTS ... IMMEDIATE itself"

key-files:
  created:
    - database/migrations/2026_10_10_000200_add_project_key_freeze_trigger.php
    - tests/Feature/Tasks/TaskKeyTest.php
    - tests/Feature/Schema/TasksTableTest.php
    - tests/Concurrency/task-worker.php
    - tests/Concurrency/TaskNumberConcurrencyTest.php
  modified:
    - app/Domain/Projects/Models/Project.php
    - app/Domain/Projects/Actions/UpdateProject.php
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/ProjectResource/Pages/EditProject.php
    - lang/cs/kokpit.php
    - tests/Isolation/PartnerSafeColumnsTest.php

key-decisions:
  - "The key freeze is enforced three times: database trigger (KP002), UpdateProject pre-check plus KP002 translation, and a disabled form field"
  - "The mutation run of the concurrency test removes the board advisory lock too, because that lock alone serializes every creation and would hide a missing counter lock"
  - "A second proof run shows the counter row lock alone keeps numbers gap-free (board lock removed, race window widened), so the guarantee does not depend on the board lock"
  - "A disabled (frozen) key field is not part of the form data, so EditProject passes the stored key to UpdateProject"

patterns-established:
  - "Test cleanup of committed rows in tests/Concurrency: delete in foreign-key order, delete roles only when the test created them, assert zero rows left in afterEach"

requirements-completed: [TA-02, TA-01]

coverage:
  - id: D1
    description: "The project key cannot change once the project has any task (archived included): database trigger KP002, UpdateProject field error, disabled Admin form field"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskKeyTest.php#refuses a raw key update on a project with a task with SQLSTATE KP002"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskKeyTest.php#answers a field error on key for a new key on a project with tasks and leaves the key"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskKeyTest.php#shows the key field disabled with a hint on the edit page of a project with a task"
        status: pass
    human_judgment: false
  - id: D2
    description: "A deleted task number is never handed out again (ABC-2 archived, next is ABC-3); a project without tasks keeps an editable key"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskKeyTest.php#never hands out the number of an archived task again"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskKeyTest.php#changes the key of a project without tasks through UpdateProject"
        status: pass
    human_judgment: false
  - id: D3
    description: "Eight parallel processes creating 25 tasks each end with numbers exactly 1..200, each reference KEY-number; the unlocked allocator is detected as broken"
    requirement: TA-02
    verification:
      - kind: integration
        ref: "tests/Concurrency/TaskNumberConcurrencyTest.php#hands out exactly 1..200 to 8 parallel workers creating tasks with no duplicate and no gap"
        status: pass
      - kind: integration
        ref: "tests/Concurrency/TaskNumberConcurrencyTest.php#detects the defect when the allocator has no row lock (mutation run)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The database rejects sub-subtasks, cross-project parents, parent-to-subtask turns, bad status/priority/reference/dates/completion/escalation, and duplicate active positions"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/TasksTableTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Partner-readable tasks table has a pinned column list and no money, rate, estimate, billing or internal-note column"
    requirement: TA-01
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerSafeColumnsTest.php#pins the Partner-safe column list of the tasks table"
        status: pass
    human_judgment: false

duration: about 40min
completed: 2026-10-08
status: complete
plan_head_before: d0e626f
plan_head_after: 99383de16332135ca525dd0480dd72734e535a83
---

# Phase 5 Plan 02: Key Freeze and Numbering Proof Summary

**Project key frozen by a KP002 database trigger (plus Action field error and disabled form field) as soon as any task exists, and gap-free task numbering proven against 8 real parallel processes with a mutation run that fails when the counter lock is removed.**

## Performance

- **Duration:** about 40 min
- **Completed:** 2026-10-08
- **Tasks:** 3 (tracer plus two expansions)
- **Files:** 11 in the code commits (5 created, 6 modified)

## Accomplishments

- `projects_key_frozen_guard` BEFORE UPDATE OF key trigger raises SQLSTATE `KP002` when the key changes and the project has any task row, soft-deleted ones included. `CreateTask` holds the project row `FOR SHARE`, so a concurrent key UPDATE waits and then sees the new task.
- `UpdateProject` refuses a changed key on a project with tasks as a field error on `key` (`kokpit.projects.errors.key_frozen`) and translates a `KP002` from the race path to the same error. Keeping the own key while renaming works.
- The Admin form shows the key disabled with a Czech hint for a project with tasks and keeps it editable otherwise.
- `TasksTableTest`: raw SQL proofs for 23505, 23514, 23503, 23001 and 23P01 (deferred position exclusion, with deferral cases: sequential rewrites that collide in between, Done rows and archived rows hold no slot) and the status/priority CHECK lists pinned against the project enums and the project constraints.
- `PartnerSafeColumnsTest` pins the 22 `tasks` columns in migration order and checks for suspicious names.
- `TaskNumberConcurrencyTest` with `task-worker.php`: 8 x 25 creations give numbers exactly 1..200, references `KEY-number`, 200 distinct positions, counter at 201; the counter lock alone (no board lock, widened window) also holds; the unlocked allocator run shows failed creations and missing tasks; the worker refuses a non-`_test` database; the test deletes everything it committed and asserts nothing remains.

## Task Commits

1. **Task 1 (tracer): key freeze trigger, Action, form, TaskKeyTest** - `0646b29`
2. **Task 2: schema proofs and Partner-safe column pin** - `22c9d8e`
3. **Task 3: parallel-process proof and worker** - `99383de`

Tracer gate (auto mode): the tracer `<verify>` (targeted Pest files, Pint, PHPStan) was re-run end to end after the commit and was green before the expansion tasks started.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Frozen key field made the edit page fail**
- **Found during:** Task 1
- **Issue:** A disabled Filament field is not part of the form data, so `ProjectResource::actionData` produced `key => ''`, which `UpdateProject` read as a changed key and refused with the freeze error on every save of a project with tasks.
- **Fix:** `EditProject::handleRecordUpdate` fills a missing `key` with the stored key before calling the Action.
- **Files modified:** `app/Filament/Resources/ProjectResource/Pages/EditProject.php` (outside `files_modified`, three lines)
- **Commit:** `0646b29`

**2. [Rule 3 - Blocking] The mutation run could not show the defect behind the board lock**
- **Found during:** Task 3 design
- **Issue:** `CreateTask` takes a board-wide advisory lock first, so even `UnlockedSequenceAllocator` runs serialized and numbers stay correct; the plan's mutation run would pass vacuously.
- **Fix:** `task-worker.php` takes two optional arguments (`board` = `locked|unlocked`, `widenMicros`). The mutation run uses the unlocked allocator, an anonymous `TaskBoard` double without the lock (the class is non-final for this purpose) and a sleep after the counter read. An extra case proves the real counter lock alone keeps numbers gap-free.
- **Files modified:** `tests/Concurrency/task-worker.php`, `tests/Concurrency/TaskNumberConcurrencyTest.php` (both in `files_modified`)
- **Commit:** `99383de`

**3. [Plan nuance] Project key in the concurrency test is random, not fixed**
- The test uses `Canary::projectKey()` instead of a fixed key, so a leftover row from an aborted run cannot collide with the unique key. The key is still fictional.

**Total deviations:** 3 (1 Rule 1, 1 Rule 3, 1 nuance). **Impact:** one three-line edit outside `files_modified`; no scope change.

## Known Stubs

None.

## Threat Flags

None. T-05-04 (key tampering) is mitigated as planned (trigger, Action error, disabled field, raw-SQL test); T-05-05 (deadlock between creation and key update) is covered by the fixed lock order and the concurrency test.

## Issues Encountered

- During final verification another Claude session was changing `tests/Feature/Clients/ClientResourceTest.php` and adding `tests/Feature/Operations/SettingsMigrationCoverageTest.php` in the same working tree and using the shared `kokpit_test` database. A full-suite run in that window failed in unrelated Clients, Operations and Isolation tests and in the concurrency suites (shared database interference). Those files are not part of this plan and were left untouched. The full suite was green (1627 passed) immediately after the last code commit, and the plan-relevant suites (Tasks, Schema, Projects, Isolation, Concurrency, Arch: 457 tests) were green again afterwards. Pint and PHPStan are clean.

## Next Phase Readiness

Ready for 05-03. The task table, numbering and key freeze are proven at the database level; the Admin resource plan can rely on `Project::tasks()`, the frozen key and the pinned column list.

## Self-Check: PASSED

- Created files exist on disk; commits `0646b29`, `22c9d8e`, `99383de` are ancestors of HEAD.
- Acceptance criteria of all three tasks re-run and passing; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.
