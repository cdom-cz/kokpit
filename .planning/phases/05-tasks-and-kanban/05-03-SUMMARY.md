---
phase: 05-tasks-and-kanban
plan: 03
subsystem: tasks
tags: [filament, global-search, filters, tags, route-key, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model, CreateTask Action, TaskBoard, project key freeze (plans 05-01, 05-02)
  - phase: 04-clients-and-projects
    provides: Project model with HasTags and the detachTags override, ProjectResource patterns
provides:
  - TaskResource with the stable task address /admin/tasks/KEY-N (reference is the route key, lower case resolves too)
  - quickCreateAction(): a static two-field modal (project, title) that calls CreateTask and redirects to the new task
  - Panel global search switched to resource opt-in; TaskResource is the only searchable resource (key and title, never id)
  - Admin task list filters: client, project, status, priority, assignee, task tag, inclusive due date range
  - Default order updated_at descending with id descending tie-breaker
  - TagType::Task and HasTags on Task (soft delete keeps tags)
  - Route walk entry for tasks (Partner gets 403 on every Admin task route)
affects: [05-04 task edit page, 05-10 and 05-11 boards (reuse quickCreateAction), 05-12 Partner task list, 05-15 notification links]

actuals:
  tokens: 12500
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Panel global search is opt-in per resource: only a resource declaring $isGloballySearchable on its own class is searchable"
    - "Default sort as a Closure returning the Builder, so the id tie-breaker is explicit and stays the last key under a column sort"
    - "Inclusive date range filter: both bounds are validated calendar days; a null due date matches neither bound"

key-files:
  created:
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/TaskResource/Pages/ListTasks.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - app/Filament/Support/TaskColumns.php
    - tests/Feature/Tasks/TaskResourceTest.php
    - tests/Feature/Tasks/TaskListFiltersTest.php
  modified:
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Shared/Tags/TagType.php
    - app/Providers/Filament/AdminPanelProvider.php
    - lang/cs/kokpit.php
    - tests/Isolation/RouteWalkTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "A2 adopted: D-09's /tasks/KEY-N is /admin/tasks/KEY-N (every resource lives under the panel path); no root-level redirect route"
  - "The quick create modal redirects to the view page; plan 05-04 switches it to the edit page"
  - "The title column is searchable but not sortable, because the Czech collation mechanism is not built"
  - "A1 adopted: task tags are Admin-only; Tag::constrainForPartner stays project-only and a test proves a Partner sees no task tag"
  - "The project filter offers Project::selectable() as the plan states, so tasks of an archived project are reachable through the client filter but not through the project filter"

patterns-established:
  - "Search result details of a task carry the project key and the status label only, never money"

requirements-completed: [TA-02, TA-05, TA-01]

coverage:
  - id: D1
    description: "The Admin creates a task in one modal asking only for project and title and lands on /admin/tasks/KEY-N; a lower-case key resolves to the same task"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#creates a task from the quick modal with only a project and a title and lands on its page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#shows the reference and the title on the task page"
        status: pass
    human_judgment: false
  - id: D2
    description: "Global search finds a task by KEY-N or title with project key and status as details; only the tasks category appears; a Partner finds nothing"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#finds a task by its key in the global search with the project key and status as details"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#returns a Partner no global search result for the reference of an own-client task"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#lets exactly one resource of the panel be globally searchable, the Admin-only TaskResource"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every Admin task route answers 403 to a Partner for an own and another client's task"
    requirement: TA-01
    verification:
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskResourceTest.php#refuses a Partner the list and the page of an own-client task"
        status: pass
    human_judgment: false
  - id: D4
    description: "The list filters by client, project, status, priority, assignee and task tag; filters combine with AND"
    requirement: TA-05
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#combines two filters with AND"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#narrows the list by a task tag and offers task tags only"
        status: pass
    human_judgment: false
  - id: D5
    description: "The due range is inclusive on both days, a task without due date matches neither bound, no match shows the Czech empty state, the order is stable across pages"
    requirement: TA-05
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#lists a task due exactly on the from day and on the to day and not one day outside"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#excludes a task without a due date by any due bound and lists it with no due filter"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#orders by update time descending and by id descending for equal timestamps, on every page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#shows the Czech empty state when the filters match nothing"
        status: pass
    human_judgment: false
  - id: D6
    description: "Task tags never reach a Partner; archiving a task keeps its tags and only a force delete detaches them"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#never shows a task tag to a Partner through the Tag query"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskListFiltersTest.php#keeps the tags of an archived task and detaches them only on a force delete"
        status: pass
    human_judgment: false

duration: two sessions (about 50 min in total)
completed: 2026-10-08
status: complete
plan_head_before: 78993c2e4b196d20472910e39ed96d2590bc4e98
plan_head_after: a92f8f00e5c1826930a1289ebdbad9903fa3eee8
---

# Phase 5 Plan 03: Task List, Quick Creation, Global Search and Filters Summary

**The Admin task screens: one-modal quick creation landing on the stable /admin/tasks/KEY-N address, panel global search switched to per-resource opt-in so only the Admin-only TaskResource is searchable, and the TA-05 list filters with an inclusive due date range and an id-stable default order.**

## Execution Sessions

The plan was executed in two sessions. The first session was paused on purpose by the user (it did not crash) after the tracer commit, the RED commit of the global search task and uncommitted green work on `TaskResource`, `AdminPanelProvider` and `TaskResourceTest`. The second session re-ran the tracer verify end to end (green), adopted the uncommitted work, verified it, and committed it as the GREEN of Task 2, then did Task 3 in full. No finished work was redone.

## Performance

- **Duration:** two sessions, about 50 min in total
- **Completed:** 2026-10-08
- **Tasks:** 3 (tracer plus two TDD expansions)
- **Files:** 12 in the code commits (6 created, 6 modified)

## Accomplishments

- `TaskResource`, `ListTasks`, `ViewTask`, `TaskColumns`: the reference is the route key, the binding upper-cases the incoming key, and `quickCreateAction()` is a static builder (project and title only, calls `CreateTask`, maps a `ValidationException` to the modal fields) so the boards can reuse it.
- Panel global search: `->globalSearch()->globalSearchResourceOptIn()`. `TaskResource` searches `reference` and `title` (never `id`), shows `KEY-N · title` with the project key and status label, and eager loads the project. A test pins that exactly one resource is searchable and that a Partner sees none.
- Filters on the Admin list: client, project, status, priority, assignee, task tag (task-type tags only) and a due date range with `due_from` and `due_until`, both inclusive, with indicators. A task without a due date matches neither bound.
- Default order is `updated_at` descending then `id` descending, written explicitly as a Closure sort and tested across two pages with equal timestamps.
- `TagType::Task` and `HasTags` on `Task` with the `detachTags` override copied from `Project`; task tags are Admin-only and a test proves a Partner's `Tag` query returns none.
- Route walk entry `tasks` proves a Partner is refused on every Admin task route.

## Task Commits

1. **Task 1 (tracer): quick creation, task page, 403 for Partners** - `b0b7e43` (feat, session 1)
2. **Task 2 (TDD): global search by key with resource opt-in**
   - RED `c5f852d` (test, session 1)
   - GREEN `4b6cc2a` (feat, session 2, adopted from the uncommitted work of session 1)
3. **Task 3 (TDD): list filters, inclusive due range, stable order**
   - RED `4345f11` (test)
   - GREEN `a92f8f0` (feat)

## TDD Gate Compliance

RED then GREEN commits exist for both TDD tasks (`test(05-03)` precedes `feat(05-03)` in each).

- Task 2 RED: the global search tests failed because the panel had global search switched off. One RED expectation named the status label `Plánováno` while the enum label is `Plánovaný`; the GREEN commit corrected that expected value (see Deviations).
- Task 3 RED: ten filter and order tests failed on the planned assertion (`a table filter with name [...] exists`, and the order assertions). Two tests that attach a task tag (`narrows the list by a task tag...`, `never shows a task tag to a Partner...`) failed first on the missing `TagType::Task` case, which is a missing-symbol failure of the planned feature rather than a logic assertion; the Partner tag test then passes once the case exists, as it proves a negative that the existing project-only Tag constraint already gives. Semantic assessment: the target tests executed and failed for the intended reason (feature absent), none failed on setup or a syntax fault.
- No REFACTOR commit was needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Wrong expected status label in the RED global search test**
- **Found during:** Task 2 (resumed session)
- **Issue:** The RED test expected `Plánováno`; the planned status label in `lang/cs/enums.php` is `Plánovaný`.
- **Fix:** Corrected the expected value in the test.
- **Files modified:** tests/Feature/Tasks/TaskResourceTest.php
- **Commit:** 4b6cc2a

**2. [Rule 1 - Bug] PanelAccessTest asserted the old "no global search" panel setting**
- **Found during:** Task 3 full-suite run
- **Issue:** `tests/Isolation/PanelAccessTest.php` ("runs the panel with strict authorization and without global search") asserted `getGlobalSearchProvider()` is null, which the plan's own panel change (research correction C3) makes false. The file is outside the plan's `files_modified`.
- **Fix:** The test now asserts strict authorization, a present provider and `isGlobalSearchResourceOptIn()`. Minimal edit, one test.
- **Files modified:** tests/Isolation/PanelAccessTest.php
- **Commit:** a92f8f0

**3. [Rule 2 - Missing critical] Test for the tag behaviour of an archived task**
- **Found during:** Task 3
- **Issue:** The copied `detachTags` override had no test on `Task`.
- **Fix:** Added one test: archive keeps the taggables row, a force delete removes it.
- **Files modified:** tests/Feature/Tasks/TaskListFiltersTest.php
- **Commit:** a92f8f0

**Total deviations:** 3 auto-fixed (2 Rule 1, 1 Rule 2). **Impact:** none on scope; one extra file touched (`PanelAccessTest.php`).

## Verification

- Tracer verify re-run at the start of session 2: `TaskResourceTest`, `RouteWalkTest`, `PanelRegistryTest`, `PanelBootTest` green (34 passed).
- Full suite after Task 3: 1651 passed. An earlier full run in the same session showed one failure, the `PanelAccessTest` case above, fixed before the commit.
- Pint clean, PHPStan (Larastan) no errors, `scripts/check-sensitive.sh` clean on every commit, hooks not bypassed.
- Acceptance criteria of all three tasks re-run with grep and Pest: pass.
- Note: right after a file write, a ddev Pest run twice read a stale copy of the file (one run saw no filters, one the old test name); re-running after a short wait gave the correct result. This is a bind-mount timing effect, not a code defect.

## Issues Encountered

None open. No shared-database interference from other sessions was seen.

## Known Stubs

None. The description entry is shown as escaped text until plan 05-04 adds the sanitiser (documented in `TaskColumns`), which is intentional and not a stub.

## Threat Flags

None. The new surface (global search, Admin task routes, quick create picker) is covered by T-05-06, T-05-07 and T-05-08 of the plan's threat model, each with a test.

## Next Phase Readiness

Ready for 05-04: the edit page can reuse `quickCreateAction()`'s redirect target switch, and `TaskColumns` already has the tags column and entry.

## Self-Check: PASSED

- Created files exist: TaskResource.php, ListTasks.php, ViewTask.php, TaskColumns.php, TaskResourceTest.php, TaskListFiltersTest.php.
- Commits `b0b7e43`, `c5f852d`, `4b6cc2a`, `4345f11`, `a92f8f0` are ancestors of HEAD.
