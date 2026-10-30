---
phase: 05-tasks-and-kanban
plan: 01
subsystem: tasks
tags: [laravel, postgres, tasks, partner-isolation, number-sequences, eloquent]

requires:
  - phase: 02-platform-and-isolation
    provides: PartnerScope and PartnerIsolated, canary registry, SequenceAllocator, schema convention rules
  - phase: 03-operations
    provides: DocumentNumbering::nextTaskNumber over the number_sequences counter
  - phase: 04-clients-and-projects
    provides: Project model with Partner scope and selectable(), ProjectStatus and ProjectPriority enums
provides:
  - tasks table (composite parent key, deferred position exclusion, CHECK constraints, escalation columns)
  - Task model isolated for Partners through the scoped Project query, TaskPolicy, morph alias task
  - CreateTask Action (board lock, project FOR SHARE, counter row; KEY-N reference; D-04 and D-05 people rules)
  - TaskBoard (advisory lock and next column position), TaskPeople (allowed set and defaults), TaskFactory
affects: [05-02 numbering proof, 05-03 history, 05-04 Admin resource, 05-05 subtasks, 05-11 board, 05-13 escalation, phase-06 time, phase-10 invoicing]

actuals:
  tokens: 13000
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Partner scope delegating to the scoped parent query (Task over Project::query())"
    - "Fixed lock order in one transaction: board advisory lock, project FOR SHARE, counter row"
    - "Neutral field error project_id for every unavailable project (no existence oracle)"

key-files:
  created:
    - database/migrations/2026_10_10_000100_create_tasks_table.php
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Tasks/Actions/CreateTask.php
    - app/Domain/Tasks/Board/TaskBoard.php
    - app/Domain/Tasks/TaskPeople.php
    - app/Domain/Tasks/Policies/TaskPolicy.php
    - database/factories/TaskFactory.php
    - tests/Feature/Tasks/TaskActionsTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/PgSchema.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "The counter is the existing number_sequences row task:<project uuid>; no projects column was added (research correction C1)"
  - "Allowed people are computed with whereHas('roles') on active users instead of the permission package role() scope, which throws when a role row is missing"
  - "A present but empty assignee_id or requester_id is validated and refused; only an absent or null key takes the default"
  - "Schema rule R1 exempts tasks.parent_depth: the generated discriminator in the composite parent key is a foreign key column but not an identifier"

patterns-established:
  - "TaskBoard is a non-final class resolved from the container, so later test doubles can skip the lock"
  - "Test helpers in Feature/Tasks use a task prefix because Pest helper functions are global across files"

requirements-completed: [TA-01, TA-02]

coverage:
  - id: D1
    description: "The Admin creates tasks through CreateTask and each receives the next KEY-N from the project counter (ABC-1, ABC-2; XY-1 separate)"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#gives the first two tasks of a project the references ABC-1 and ABC-2"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#counts every project on its own: the first task of project XY is XY-1"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner reads exactly the tasks of the own client's client-visible, non-archived projects; the canary registry covers Task"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#shows a Partner only the tasks of the own client-visible projects"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "People rules: D-04 defaults for Admin and Partner, D-05 allowed set for assignee and requester, forged Partner inputs ignored"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#refuses a person outside the allowed set as a field error and keeps the counter unchanged"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#gives a Partner task the Partner as requester, the Admin as assignee, planned and normal"
        status: pass
    human_judgment: false
  - id: D4
    description: "Unavailable projects are one neutral field error and consume no number; a rolled-back caller gives the number back"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#refuses every project a Partner may not use with the same field error and consumes no number"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskActionsTest.php#gives the same number again when a transaction around CreateTask is rolled back"
        status: pass
    human_judgment: false

duration: 15min
completed: 2026-10-08
status: complete
plan_head_before: f8df613ae43762cb76224ee969bd903e69b78e9d
plan_head_after: 29d17e8487564385a6825e0c304ed7ae6cd7755a
---

# Phase 5 Plan 01: Tasks Tracer Summary

**Tasks table with database-enforced parent, position and reference guarantees, a Partner-isolated Task model scoped through the Project query, and a CreateTask Action that allocates KEY-N from the per-project counter under a fixed lock order and applies the D-04 and D-05 people rules.**

## Performance

- **Duration:** about 15 min
- **Completed:** 2026-10-08
- **Tasks:** 2 (tracer, TDD)
- **Files:** 16 in the code commits (8 created, 8 modified)

## Accomplishments

- `tasks` table: composite parent foreign key over a generated `parent_depth` column (one level, same project), deferred partial `EXCLUDE` on `(status, position)` for active non-done rows, unique `reference` and `(project_id, number)`, CHECK constraints for status, priority, dates, completion and escalation pairing. The escalation columns are already present for plan 05-13.
- `Task` model: Partner constraint delegates to `Project::query()`, so a Partner sees exactly the tasks of the own client's visible, non-archived projects. `TaskPolicy` grants a Partner view, create, comment and escalate only; `clearEscalation` stays denied for now.
- `CreateTask`: board advisory lock, project row `FOR SHARE`, then the counter row, all in the caller's transaction; the first two tasks of project ABC are `ABC-1` and `ABC-2`, project XY starts at `XY-1`. A rolled-back outer transaction gives the number back.
- People rules: the Admin defaults to requester = assignee = self, a Partner always becomes requester with the oldest active Admin as assignee and their status, priority and people inputs are ignored. Admin-supplied ids must be an active Admin or an active Partner of the project's client, else a field error on `assignee_id` or `requester_id`.
- Canary fixture for `Task` created through the real Action, with the registry and model declaration lists extended.

## TDD Gate Compliance

- RED: `34cd15e` test(05-01). 16 of 31 cases failed, each on the planned assertion (the explicit ids were ignored and replaced by the actor, the Partner got the wrong requester or assignee, no `DomainException` was thrown without an Admin). No case failed on setup, import or fixture errors. Semantic assessment: the failures were the intended behaviour gaps. Pest does not emit one of the report formats `gsd_run check tdd-red-evidence` supports (the plan is not `type: tdd` and `tdd_mode` was not enforced), so no machine classification record was produced.
- GREEN: `29d17e8` feat(05-01). All 31 cases pass, full suite 1578 passed.
- REFACTOR: none needed.

## Task Commits

1. **Task 1 (tracer): tasks table, Task, CreateTask, canary** - `f6f6418`
2. **Task 2 RED: failing tests for people rules and guards** - `34cd15e`
3. **Task 2 GREEN: TaskPeople and CreateTask people rules** - `29d17e8`

Tracer gate (auto mode): the tracer `<verify>` was re-run end to end after the commit (Pest, Pint, PHPStan all green) before expanding.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Schema rule R1 rejected the generated parent_depth column**
- **Found during:** Task 1
- **Issue:** `SchemaConventionsTest` R1 requires every foreign key column to be uuid, but `parent_depth` (smallint) must be part of the composite parent key by design.
- **Fix:** Added one documented entry `tasks.parent_depth` to `PgSchema::EXEMPT` and extended the pinned exempt-map test.
- **Files modified:** `tests/Support/PgSchema.php`, `tests/Feature/Schema/SchemaConventionsTest.php`
- **Commit:** `f6f6418`

**2. [Rule 2 - Missing critical] Validation of status and priority input**
- **Found during:** Task 1
- **Issue:** A crafted Admin payload with an unknown status or priority would surface as a `ValueError` or database CHECK exception.
- **Fix:** `CreateTask` returns field errors on `status` and `priority`; added Czech strings `status_invalid` and `priority_invalid` to `tasks.errors`.
- **Files modified:** `app/Domain/Tasks/Actions/CreateTask.php`, `lang/cs/kokpit.php`
- **Commit:** `f6f6418`

**3. [Plan nuance] Role lookup without the permission package scope**
- **Found during:** Task 2
- **Issue:** The plan suggests the package `role()` scope, which throws `RoleDoesNotExist` when a role row is missing (for example before install).
- **Fix:** `TaskPeople` uses `whereHas('roles', name = ...)` on active users, which answers "nobody" instead of throwing.
- **Commit:** `29d17e8`

**Total deviations:** 3 (1 Rule 3, 1 Rule 2, 1 implementation nuance). **Impact:** no scope change; two test-support files outside `files_modified` were touched.

## Known Gaps (not stubs)

- `description` is stored as given; strict HTML sanitisation lands with plan 05-04 (`RichText`), as the plan states.
- A `due_date` earlier than `start_date` is rejected by the database CHECK only; a friendly field error belongs to the form plan 05-04.

## Known Stubs

None.

## Threat Flags

None. The new surface (tasks table, Partner read of tasks, creation by Partner) is covered by threat register entries T-05-01 to T-05-03.

## Issues Encountered

None blocking.

## Next Phase Readiness

Ready for 05-02. `TaskBoard`, `TaskPeople::options()`, `TaskFactory` and the `Task` canary fixture are in place for the key freeze, parallel numbering proof and Admin resource.

## Self-Check: PASSED

- All created files exist on disk; commits `f6f6418`, `34cd15e`, `29d17e8` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run and passing; full Pest suite (1578 passed), Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.
