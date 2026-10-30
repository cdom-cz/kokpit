---
phase: 05-tasks-and-kanban
plan: 12
subsystem: tasks
tags: [filament-resource, partner-isolation, pinned-builders, rich-text, route-walk]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model with the Partner scope, TaskPolicy, CreateTask Partner path, RichText sanitiser, TaskColumns admin builders (plans 05-01 to 05-11)
  - phase: 04-clients-and-projects
    provides: PartnerProjectResource and ProjectColumns as the analog of a Partner resource with pinned builders
provides:
  - PartnerTaskResource "Moje úkoly" at /admin/my-tasks with a read-only list, a create page and a read-only task page at /admin/my-tasks/KEY-N
  - TaskColumns::partnerColumns() and partnerEntries() with the pinned name lists PARTNER_COLUMN_NAMES and PARTNER_ENTRY_NAMES
  - CreatePartnerTask, which hands only project, title and description to CreateTask with the Partner as actor
  - route walk entry my-tasks and the Czech keys under kokpit.partner_tasks
affects: [05-13 Partner comments and escalation, 05-14 to 05-16 Partner notifications, 05-17 phase gate]

actuals:
  tokens: 10600
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "A Partner resource builds its table and infolist only from pinned builders whose names are public constants; a test compares the constants with the built lists, so a new column or entry needs a visible edit of the pin"
    - "The Partner resource query is the scoped model with the default soft delete scope left on, so an archived task is a 404 with no extra code"
    - "A Partner create page reads only its three form fields from the form state and calls the domain Action; forged keys never reach the Action"
    - "Mutation proof for isolation tests: each guarantee has a one-line mutation that makes a named test fail (reverted by git checkout of the committed file)"

key-files:
  created:
    - app/Filament/Partner/Resources/PartnerTaskResource.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ListPartnerTasks.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
    - tests/Feature/Tasks/PartnerTaskResourceTest.php
    - tests/Isolation/PartnerTaskVisibilityTest.php
  modified:
    - app/Filament/Support/TaskColumns.php
    - lang/cs/kokpit.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "PartnerTaskResource copies the PartnerProjectResource canAccess (declaration AND policy AND a Partner with a client), so the Admin gets 403 and no duplicate navigation entry"
  - "The resource also answers false to canEdit and canDelete as defence in depth; the pages are only index, create and view, so /admin/my-tasks/KEY-N/edit is a 404"
  - "The create page does not forward the Livewire state: it reads project_id, title and description from the form state, resolves the project through Project::query()->selectable() (Partner scoped) and gives the domain Action only title and description"
  - "A project that is unknown, foreign or hidden is the single field error project_id (kokpit.tasks.errors.project_unavailable); the select options come from the same scoped query, so no existence oracle exists"
  - "The pinned escalated_at entry is built now (label Eskalace, value Eskalováno (name, datetime), visible only while escalated) because the pin names it; the escalate and clear actions stay in plan 05-13"
  - "The status badge keeps the enum colour as built (UI-SPEC F-8 untouched)"

patterns-established:
  - "Pattern: test projects that an Admin page will render are created through the CreateProject Action, because a factory project has no billing row and the Admin task page needs it"
  - "Pattern: Partner isolation tests prove the canaries exist on an Admin page first, then assert their absence on the Partner pages"

requirements-completed: [TA-07, KB-03]

coverage:
  - id: D1
    description: "A Partner opens My tasks, sees a read-only list of the tasks of the own visible projects, creates a task with project, title and description and lands on /admin/my-tasks/KEY-N; the task is planned, normal, requester the Partner, assignee the Admin"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#lets a Partner raise a task in a visible project and lands on its page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#lists the task of an own visible project to a Partner and nothing of client B"
        status: pass
    human_judgment: false
  - id: D2
    description: "The Admin gets 403 on the Partner task list and create page; the route walk covers my-tasks for both clients"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#gives the Admin 403 on the Partner task list and create page"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Partner list and page show exactly the pinned columns and entries and nothing of tags, checklist, billing, estimate, history or internal comments"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#builds exactly the pinned Partner columns in the Partner list"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#builds exactly the pinned Partner entries in the Partner infolist"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#shows no tag, checklist item, billing value or internal comment of a task, neither in the list nor on the page"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner gets 404 on a client B task, on a task of an own project that is not client-visible and on an archived own task, and cannot edit, delete, reorder, export or move anything"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#answers 404 to the page of a client B task, of a task of an own project that is not client-visible and of an archived own task"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#has no record action, bulk action, reorder or export on the list and no header action but creating"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#exposes no moveCard method on a Partner page and refuses the board to a Partner"
        status: pass
    human_judgment: false
  - id: D5
    description: "A forged create payload with status, priority, people and tags is stored as planned, normal, assignee the Admin and without tags (T-05-27)"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerTaskVisibilityTest.php#stores a task with the defaults when the create payload is forged with status, priority, people and tags"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner description passes the same sanitising: stored clean, rendered clean on the Partner and Admin pages, and cleaned again at render time"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#stores and shows a Partner description without script, handler, script link and style, keeping the words (D-10)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#cleans a description that was written around the Action again when the Partner page renders it"
        status: pass
    human_judgment: false
  - id: D7
    description: "Empty edge: a Partner without a client-visible project sees the Czech empty state, the create form offers no project and a forged project id is the project_id field error (T-05-28)"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#shows the empty state to a Partner whose client has no client-visible project and offers no project"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#refuses a forged project id of client B or of a hidden own project with the project field error and takes no number"
        status: pass
    human_judgment: false
  - id: D8
    description: "Adjacency and ordering edges: switching a project to not client-visible removes its tasks from the Partner list and page on the next request; the list is ordered by created_at descending, ties by id descending"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#drops the tasks of a project from the Partner list and page on the next request once the project is not client-visible"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskResourceTest.php#orders the Partner list by creation time descending with ties broken by id descending"
        status: pass
    human_judgment: false
  - id: D9
    description: "The look of the Partner list, create page and task page in light and dark mode, and the Czech copy of the empty state in a real browser"
    requirement: KB-03
    verification: []
    human_judgment: true
    rationale: "Visual contrast and copy reading cannot be asserted by the Pest/Livewire harness; the UI-SPEC visual check belongs to the phase gate"

duration: 10min
completed: 2026-10-09
status: complete
plan_head_before: 2f6621a302481453864f0ced248805edbde396be
plan_head_after: 3e5cb07e4ad3658ed9423554f6e84e7632585282
---

# Phase 5 Plan 12: Partner Task Surface Summary

**"Moje úkoly" for the Partner: a read-only list, a create page (project, title, description) and a read-only task page at /admin/my-tasks/KEY-N, built only from pinned Partner builders and proven by canary HTML tests, cross-client 404s and mutation runs.**

## Performance

- **Duration:** about 10 min of wall clock
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 9 (6 created, 3 modified)
- **Commits:** 4 task commits (1 feat, 3 test)

## Accomplishments

- A Partner with a client opens "Moje úkoly", sees the tasks of the own client-visible projects newest first, raises a task through "Nový úkol" and lands on its page. The task is planned, normal, requester the Partner, assignee the Admin; the form has only Projekt, Název and Popis.
- `TaskColumns` gained `PARTNER_COLUMN_NAMES`, `PARTNER_ENTRY_NAMES`, `partnerColumns()` and `partnerEntries()`. The Partner surfaces cannot grow a column or entry without a visible edit of the pin, because `PartnerTaskVisibilityTest` compares the constants with the built lists.
- Isolation is proven four ways: the pinned names, canary HTML (tag, checklist item, billing note and internal comment each carrying a canary, first shown present on an Admin page, then absent from the Partner list and page), 404s for a client B task, a task of a hidden own project and an archived own task, and the route walk entry `my-tasks` for both clients.
- Nothing a Partner can open changes a task: no record, bulk, reorder, export or edit action, no edit route, no `moveCard` on any Partner page, the Admin board answers 403, and a forged create payload is ignored.
- Partner input is sanitised like the Admin's, an escalated task shows the pinned "Eskalace" entry, and the list reacts to a visibility switch on the very next request.

## Task Commits

1. **Task 1: Tracer, create and open a task under My tasks** - `8c9fc23` (feat)
2. **Task 2: Pinned, read-only and isolated surface** - `d709899` (test)
3. **Task 3: Sanitising, empty state, visibility switch, order** - `10a970a` (test)
4. **Escalation entry test (follow-up to the pinned entry)** - `3e5cb07` (test)

**Plan metadata:** the `docs(05-12)` commits that add this SUMMARY, STATE.md and ROADMAP.md.

## TDD Gate Compliance

Tasks 2 and 3 are marked `tdd="true"`, but both have only `test(05-12)` commits and no `feat(05-12)` commit after them. This is a documented departure from RED then GREEN, not a hidden one:

- The plan's Task 1 is a tracer that delivers the whole resource, so every behaviour listed for Tasks 2 and 3 already existed when their tests were written. Every new test passed on its first meaningful run (**unexpected green** in the sense of tdd.md). The only failures seen were in the tests themselves: a factory project without a billing row crashing the Admin page, a header-actions array key assumption, and an Admin page that shows only checklist progress; each was a test fault and was fixed in the test, none was a gap in the code.
- Investigation per tdd.md: the tests are not vacuous. Each guarantee has a mutation run that makes a named test fail, and each mutation was reverted with `git checkout -- <file>` (all mutated files were committed and unchanged otherwise).
- No gap was found, so no GREEN code commit was needed. The `semanticAssessment` of the missing RED: the target tests did execute and assert the planned behaviour; they pass because the tracer implemented it.

| Mutation | Result |
|---|---|
| Tags column and entry added to the Partner builders | 2 pinned-name tests fail |
| Soft delete scope lifted on the resource query | the 404 test (archived task) fails |
| All global scopes lifted on the resource query | 404 test, Livewire table test, list test and the route walk fail |
| Raw Livewire state forwarded to `CreateTask` | the forged payload test fails (tags stored) |
| `EditAction` added to the task page header | the no-edit-action test fails |
| Project options without the Partner scope | empty state, forged project and visibility switch tests fail |
| `created_at` descending only, no id tie-break | the order test fails |
| `TaskInput::description` stores raw HTML | the sanitising test fails |
| Partner description entry without `RichText::render` | the render-time cleaning test fails |

## Files Created/Modified

- `app/Filament/Partner/Resources/PartnerTaskResource.php` - the Partner resource: access, labels, form, table, infolist, scoped project options
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ListPartnerTasks.php` - list with the "Nový úkol" header action
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php` - create page calling `CreateTask` with the Partner actor
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php` - read-only page titled `KEY-N · title`, no header action
- `app/Filament/Support/TaskColumns.php` - pinned Partner constants and builders
- `lang/cs/kokpit.php` - `kokpit.partner_tasks` (navigation, empty state, hint, actions, toast, escalation)
- `tests/Isolation/RouteWalkTest.php` - `my-tasks` entry
- `tests/Feature/Tasks/PartnerTaskResourceTest.php` - 10 tests
- `tests/Isolation/PartnerTaskVisibilityTest.php` - 12 tests

## Decisions Made

See `key-decisions` in the frontmatter. In short: the stricter `canAccess` copied from the Partner project resource, no edit page, a create page that reads only its three fields, one neutral project error, and the pinned escalation entry built ahead of its actions.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug, test side] Factory projects have no billing row**
- **Found during:** Task 2 and Task 3 (tests rendering the Admin task page)
- **Issue:** `Project::factory()` creates no billing row, so `/admin/tasks/KEY-N` threw "The project of the task has no billing row".
- **Fix:** the two new test files create their projects through the `CreateProject` Action.
- **Files modified:** `tests/Isolation/PartnerTaskVisibilityTest.php`, `tests/Feature/Tasks/PartnerTaskResourceTest.php`
- **Committed in:** `d709899`, `10a970a`

**2. [Rule 3 - Blocking] PHPStan and Pint on the first resource draft**
- **Found during:** Task 1
- **Issue:** `ViewPartnerTask::getTitle()` returned the parent's `string|Htmlable`, and `static::canAccess()` in a final class tripped `self_static_accessor`.
- **Fix:** an explicit string title and `self::canAccess()`.
- **Committed in:** `8c9fc23`

### Other departures from the plan text (no behaviour change)

- **Extra test commit** `3e5cb07`: the pinned `escalated_at` entry is rendered code, so it got a test (hidden while not escalated, "Eskalováno (name, datetime)" while escalated). The escalate and clear actions are still plan 05-13.
- **Resource extras:** `canEdit` and `canDelete` return false and `projectOptions()` is a public static helper so the tests can compare the offered projects; the plan listed neither.
- **No refactor commit** and no `feat` commit after the Task 2 and 3 tests (see TDD Gate Compliance).
- **Plan wording "Calling moveCard on any Partner page throws":** proven with `method_exists` false plus Livewire's `MethodNotFoundException` on the list page.

### Left untouched on purpose (per the run instructions)

- UI-SPEC F-8 (`ProjectStatus::InReview` colour): not changed; the Partner status badge uses the enum as built.
- UI-SPEC F-9 label changes on built Admin pages: not changed.
- UI-SPEC E-5 (refused-move toast on the boards): not part of this plan.

---

**Total deviations:** 2 auto-fixed (1 test-side bug, 1 blocking lint/type issue) plus the documented departures above.
**Impact on plan:** None on scope; the tracer delivered the behaviour early, so the two TDD tasks became characterisation tests with mutation proof.

## Issues Encountered

- A ddev Pest run right after a file write twice read a stale copy (bind-mount timing, "Test file not found"); re-running after a few seconds gave the true result.
- The full suite (1881 tests) was green at the Task 3 commit; it takes about 150 seconds. No unrelated failures appeared.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. The new routes `/admin/my-tasks`, `/admin/my-tasks/create` and `/admin/my-tasks/{record}` are Partner-only and covered by the route walk for both clients (T-05-28); the forged create payload is T-05-27; the canary HTML and pinned-name tests are T-05-29.

## Human Checks for the Phase Gate

1. Look at "Moje úkoly" (populated and empty), the create page and the task page in light and dark mode at 375 px and desktop width; check that "Nový úkol" is the only accent element on the list and that the empty-state copy reads well (UI-SPEC Surfaces G and H).
2. Sign in as a Partner of a client with two visible projects, create a task with a formatted description (a table, a list, a link) and check it on the Partner page and on the Admin page.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 05-13 can add Partner comments and escalation to `ViewPartnerTask` (its `getHeaderActions()` is the empty seam) and the comments relation manager; the pinned `escalated_at` entry and its Czech keys already exist.
- Plans 05-14 to 05-16 can link Partner notifications to `/admin/my-tasks/KEY-N`.

## Self-Check: PASSED

The six created files exist; the four task commits (`8c9fc23`, `d709899`, `10a970a`, `3e5cb07`) are ancestors of HEAD; the acceptance greps of all three tasks pass; the full suite (1881 tests), Pint and PHPStan were clean at the Task 3 commit and the follow-up test commit was run in isolation with Pint and PHPStan clean; `scripts/check-sensitive.sh` was clean on every commit.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
