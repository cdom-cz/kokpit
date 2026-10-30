---
phase: 05-tasks-and-kanban
plan: 10
subsystem: tasks
tags: [livewire, wire-sort, kanban, eloquent-sortable, filament-page, postgres-advisory-lock, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: tasks table with deferred position exclusion, TaskBoard lock and appendToColumn, archive and restore, checklist, comments, escalation columns (plans 05-01 to 05-09)
  - phase: 03-operations
    provides: 03-SPIKE-KANBAN decision (custom wire:sort board, one advisory lock per move, Partner forgery findings)
provides:
  - spatie/eloquent-sortable as a direct composer requirement with a published config; Task sorts by position per status
  - TaskBoard::columns (six status columns, Done capped by kokpit.board.done_limit, one query per column with eager loads and counts), applyFilters and move (neighbour recompute over the full and the visible column)
  - BoardFilters value object with a validating fromInput for URL input
  - MoveTask Action (UUID check, scoped lookup, Gate update, board lock, re-read under the lock, move)
  - ManagesTaskBoard trait (moveCard handler, #[Url] filters, computed columns as arrays) and the Admin-only TaskBoardPage with its view
affects: [05-11 per-project board, preview slide-over and parallel-move proof, 05-12 Partner task list, 05-16 status-change notification]

actuals:
  tokens: 14500
  tasks: 3
  commits: 5

tech-stack:
  added: [spatie/eloquent-sortable 5.0.1 (direct requirement, MIT, was already locked through spatie/laravel-tags)]
  patterns:
    - "Board writes: whitelist status (422), UUID check and scoped lookup (404), Gate update (403), board advisory lock, re-read under the lock, status through appendToColumn, positions through setNewOrder from the first changed index"
    - "A drop index counts the visible cards of the destination column; the stored order is recomputed over the full column, so hidden cards keep their place"
    - "Filters live in #[Url] scalar properties and pass through BoardFilters::fromInput, which drops values that are no UUID or priority"
    - "Livewire inserts block markers between adjacent Blade directives, so text that tests must find as one string is rendered from one expression"

key-files:
  created:
    - app/Domain/Tasks/Actions/MoveTask.php
    - app/Domain/Tasks/Board/BoardFilters.php
    - app/Filament/Concerns/ManagesTaskBoard.php
    - app/Filament/Pages/TaskBoardPage.php
    - config/eloquent-sortable.php
    - resources/views/filament/pages/task-board.blade.php
    - tests/Feature/Tasks/TaskBoardTest.php
  modified:
    - composer.json
    - composer.lock
    - config/kokpit.php
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Tasks/Board/TaskBoard.php
    - lang/cs/kokpit.php

key-decisions:
  - "config/eloquent-sortable.php keeps the package defaults order_column_name order_column and sort_when_creating true, and sets ignore_timestamps true; Task names position and sort_when_creating false in its own $sortable property. The plan's global values (position, false) broke the tags package, whose Tag model orders by order_column (172 failing tests)"
  - "A drop lands directly before the visible card now at the drop index, or at the end of the full column; the plan's behaviour list (A1, B1, X, A2) was followed over the Pattern 5 formula (after the previous visible card), both keep the visible order"
  - "A status change in a move goes through TaskBoard::appendToColumn, the only legal status writer, so one model save writes status, completed_at and position and the activity log sees one updated event"
  - "Only cards from the first position that differs from its new index are rewritten, which also handles gaps left by earlier moves out of a column"
  - "MoveTask re-reads the task under the board lock with lockForUpdate through the scoped query instead of refresh(), because refresh() ignores the soft-delete scope and would move a card archived by a parallel request"
  - "BoardFilters::fromInput drops invalid filter values (non-UUID ids, unknown priority) instead of failing, so a forged URL cannot reach a uuid column as a database error"
  - "The board nav entry has no navigation group, like the Tasks resource"

patterns-established:
  - "Pattern: a RED test that fails only by PropertyNotFound is replaced by a reflection check of the #[Url] attribute plus withQueryParams, which asserts the planned behaviour"
  - "Pattern: board mutation runs (remove Gate, use the full instead of the visible column, drop an eager load) each fail at least one test"

requirements-completed: [KB-01, KB-02, KB-03]

coverage:
  - id: D1
    description: "The Admin opens the global board and sees one column per status in order, cards by position, subtasks as cards of their own, the Done column capped with an N / M count"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#renders six columns, one per status, with the cards in position order"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#shows only the most recent done tasks up to the configured limit with an N of M count"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#puts a subtask on its own card in the column of its own status with the parent reference"
        status: pass
    human_judgment: false
  - id: D2
    description: "A card shows reference, linked title, priority, Czech due date, task tags, checklist progress, escalation marker and assignee; no navigation attribute on the card"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#shows the reference, the linked title, priority, due date, tags, checklist progress, escalation and assignee on a card"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#has no navigation attribute on a card and keeps every button inside a sort-ignore wrapper"
        status: pass
    human_judgment: false
  - id: D3
    description: "A drop stores status and position synchronously and survives a reload; one updated event with the status for a cross-column move, none for a reorder, updated_at untouched by a reorder; positions unique at commit"
    requirement: KB-02
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#stores a card dropped into another column at the dropped index and keeps it after a reload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#fires exactly one updated event with the status for a cross-column move and none for a reorder"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#leaves updated_at alone when a card is only reordered"
        status: pass
    human_judgment: false
  - id: D4
    description: "The board filters by client, assignee, task tag and priority bound to the URL query; a drop on a filtered board lands by the visible neighbour with hidden cards keeping their place; a drop into an empty visible column appends to the full column"
    requirement: KB-02
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#narrows the board by client, assignee, tag and priority and combines the filters"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#drops a card after the visible neighbour on a filtered board even when hidden cards sit between"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#appends a card dropped into an empty visible column to the end of the whole column"
        status: pass
    human_judgment: false
  - id: D5
    description: "Guard order of a move: forged status 422, unknown, malformed or archived id 404, Partner 403 on the page and on the Action; a Partner has no board"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#answers 422 for a forged status and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#answers 404 for an unknown id, a malformed id and an archived task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#refuses the mover to a Partner: another client task is not found, an own client task is forbidden, nothing changes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#refuses the board page to a Partner as a request and as a component"
        status: pass
    human_judgment: false
  - id: D6
    description: "The board stays cheap at 200 cards: the same number of queries as at 20, no Eloquent model in the Livewire snapshot"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#renders a board of 200 cards with the same number of queries as a board of 20 and keeps models out of the snapshot"
        status: pass
    human_judgment: false
  - id: D7
    description: "Real mouse and touch dragging in a browser, including a drop into an empty column and the conflict with page scrolling at 375 px"
    requirement: KB-02
    verification: []
    human_judgment: true
    rationale: "The SortableJS drag layer inside livewire.js cannot be driven by the Pest/Livewire test harness; the server-side handler is proven, the gesture is a human check (A3, A4, spike open items)"

duration: 28min
completed: 2026-10-09
status: complete
plan_head_before: eb7c136e6fc5e5ec18c073092c2bd1fe35f2fba0
plan_head_after: 45d7987fa86859c57eb21e91dee85724fd3b14b2
---

# Phase 5 Plan 10: Global Kanban Board Summary

**Admin-only global kanban board on Livewire 4 `wire:sort` with `spatie/eloquent-sortable`: six status columns, a Done column capped from config, URL-bound filters, a guarded mover (status whitelist, scoped lookup, Gate, board advisory lock, filter-aware neighbour recompute) and cards that carry the D-03 content at a constant query cost.**

## Performance

- **Duration:** 28 min
- **Started:** 2026-10-08T22:16:00Z (approx., first command of the run)
- **Completed:** 2026-10-08T22:44:00Z
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files modified:** 13 (7 created, 6 modified; plus `composer.lock`)

## Accomplishments

- The Admin drags a card between status columns or inside a column; status, completion time and position are stored synchronously in one transaction under the board lock and survive a reload. A cross-column move fires one `updated` event with the status, a reorder fires none and leaves `updated_at` alone.
- The board filters by client, assignee, task tag and priority (URL query). A drop on a filtered board lands relative to the visible neighbour while hidden cards keep their relative order; a drop into an empty visible column appends to the end of the full column.
- Cards show `KEY-N`, the title as a plain link, priority, due date in Czech format, task tags, checklist progress, an escalation badge and the assignee; a subtask is a card in its own status column naming its parent. Rendering 200 cards costs the same number of queries as 20.
- A Partner gets 403 on the board page (request and Livewire component); the Action refuses a Partner forging a move (404 for another client, 403 for the own client) and leaves the table unchanged.

## Task Commits

1. **Task 1: Tracer, board page and mover** - `cb4ab9a` (feat)
2. **Task 2: Filters, filter-aware drops, Done cap, guards** - RED `852c6e1` (test), GREEN `7cbb2a3` (feat)
3. **Task 3: Card content, subtask cards, query cost** - RED `a3b2f24` (test), GREEN `45d7987` (feat)

**Plan metadata:** the `docs(05-10)` commit that adds this SUMMARY, STATE.md and ROADMAP.md.

## TDD Gate Compliance

Tasks 2 and 3 each have a `test(05-10)` RED commit followed by a `feat(05-10)` GREEN commit; no refactor commit was needed.

- **Task 2 RED:** 6 of 19 tests failed on the planned behaviour: filters not applied (assertSee/assertDontSee), the filtered drop orders `[A1, X, ...]` instead of the planned ones, the empty-visible-column append, and the Done header text. One RED test (URL query binding) failed with `PublicPropertyNotFoundException` because the property did not exist yet; that is a missing-feature error rather than an assertion, so the test was rewritten to a reflection check of the `#[Url]` attribute plus `withQueryParams` before GREEN. The guard tests (422/404, Done enter/leave, drop inside Done, "before first visible card", invalid filter values) passed at RED because the tracer already implemented those guarantees; they are characterization tests of the tracer, not RED evidence.
- **Task 3 RED:** 3 of 25 failed on assertions (card content missing, parent reference missing, no `wire:sort:ignore` wrapper). The bare-card test and the 20-versus-200 query-count test passed at RED, because the tracer cards carried no relation data and so could not have an N+1; the query-count test was then proven non-vacuous by a mutation run (removing the `assignee` eager load fails it).
- Semantic assessment of RED: each failing test failed on the planned assertion for the planned reason (no setup, import or fixture fault). The RED commits leave the full suite red by design; the following GREEN commit restores it.

Mutation runs on the guards: removing `Gate::authorize('update')` fails the Partner-forgery test; recomputing the neighbour over the full instead of the visible column fails 3 filtered-drop tests; removing an eager load fails the query-count test. Each guard was restored by re-editing.

## Files Created/Modified

- `config/eloquent-sortable.php` - published config; `ignore_timestamps` true, package defaults otherwise (see decisions)
- `config/kokpit.php` - `board.done_limit` 20
- `app/Domain/Tasks/Models/Task.php` - `Sortable` and `SortableTrait`, `$sortable` naming `position`, `buildSortQuery()` per status
- `app/Domain/Tasks/Board/BoardFilters.php` - value object and validating `fromInput`
- `app/Domain/Tasks/Board/TaskBoard.php` - `columns`, `applyFilters`, `move`, `insertionIndex`, card arrays
- `app/Domain/Tasks/Actions/MoveTask.php` - guarded, locked mover
- `app/Filament/Concerns/ManagesTaskBoard.php` - `moveCard`, `#[Url]` filters, computed columns and filter options
- `app/Filament/Pages/TaskBoardPage.php` - Admin-only page, slug `task-board`
- `resources/views/filament/pages/task-board.blade.php` - filter bar, six `wire:sort` columns, D-03 cards
- `lang/cs/kokpit.php` - `task_board` strings
- `tests/Feature/Tasks/TaskBoardTest.php` - 25 tests

## Decisions Made

See `key-decisions` in the frontmatter. In short: package-default global sortable config with the Task opting in per model, the plan's behaviour list for the drop position, `appendToColumn` as the only status writer, rewriting positions from the first changed index, a locked scoped re-read instead of `refresh()`, and validated filter input.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Global eloquent-sortable config broke the tags package**
- **Found during:** Task 1 (first full-suite run)
- **Issue:** The plan's published config (`order_column_name` `position`, `sort_when_creating` false) applies to every sortable model. `spatie/laravel-tags` and the media library order by `order_column`, so 172 tests failed with `column "position" does not exist` on the tags relation.
- **Fix:** The config keeps the package defaults `order_column` and `sort_when_creating` true and sets `ignore_timestamps` true as the plan requires; `Task::$sortable` names `position` and turns creation sorting off for tasks. A test pins both sides (Task `position`/false, Tag `order_column`/true).
- **Files modified:** `config/eloquent-sortable.php`, `tests/Feature/Tasks/TaskBoardTest.php`
- **Verification:** full suite 1843 passed
- **Committed in:** `cb4ab9a`

**2. [Rule 1 - Plan inconsistency] Drop position with hidden cards**
- **Found during:** Task 2
- **Issue:** The behaviour list demands `[A1, B1, X, A2]` for a drop at visible index 1, while the Pattern 5 formula in RESEARCH.md gives `[A1, X, B1, A2]` (after the previous visible card). Both keep the visible order.
- **Fix:** Followed the explicit behaviour list: a card goes directly before the visible card now at the drop index, or at the end of the full column. Index 0 and the end case agree with Pattern 5.
- **Files modified:** `app/Domain/Tasks/Board/TaskBoard.php`
- **Committed in:** `cb4ab9a` (rule) and `7cbb2a3` (filters)

**3. [Rule 1 - Bug] "N / M" count split by Livewire block markers**
- **Found during:** Task 2 (RED run)
- **Issue:** The Done header was rendered with an `@if` between the two numbers; Livewire inserts HTML comment markers there, so the text "3 / 5" never appeared as one string.
- **Fix:** One Blade expression renders the whole count.
- **Files modified:** `resources/views/filament/pages/task-board.blade.php`
- **Committed in:** `7cbb2a3`

**4. [Rule 2 - Missing critical] MoveTask uses a locked scoped re-read and a UUID check**
- **Found during:** Task 1
- **Issue:** `$task->refresh()` ignores the soft-delete scope (a card archived by a parallel request would still move), and a malformed id would reach the uuid column as a database error (500) instead of the required 404.
- **Fix:** Re-read under the board lock with `lockForUpdate` through the scoped query; `Str::isUuid` check first; `BoardFilters::fromInput` validates filter values the same way.
- **Files modified:** `app/Domain/Tasks/Actions/MoveTask.php`, `app/Domain/Tasks/Board/BoardFilters.php`
- **Committed in:** `cb4ab9a`, `7cbb2a3`

**5. [Rule 3 - Blocking] Card link without the SPA navigation attribute**
- **Found during:** Task 3 (GREEN)
- **Issue:** `x-filament::icon-button tag="a"` adds `wire:navigate` in SPA mode, which the card must not carry (livewire issue 10662).
- **Fix:** A plain `<a>` with the Filament icon inside the `wire:sort:ignore` wrapper. The wrapper holds an "open task" link now; the preview button of plan 05-11 goes into the same wrapper.
- **Files modified:** `resources/views/filament/pages/task-board.blade.php`
- **Committed in:** `45d7987`

### Other departures from the plan text (no behaviour change)

- The `project:id,key,name,client_id` eager load listed in the artifact table is not loaded: no card field uses it (the reference already carries the project key), and an unused load would only add a query per column.
- The page has no navigation group (the Tasks resource has none either); the plan said "group from `kokpit.task_board`".
- The Partner forged-move tests call `MoveTask` directly, because a Partner cannot mount the page (correction C6); the page itself is covered by the 403 tests.

---

**Total deviations:** 5 auto-fixed (4 Rule 1/2/3 fixes plus one plan inconsistency resolved in favour of the explicit behaviour list)
**Impact on plan:** All fixes needed for correctness (tags package, archived cards, forged ids) or testability; no scope creep.

## Issues Encountered

- A ddev Pest or PHPStan run right after a file write twice read a stale copy (bind-mount timing); re-running after a short wait gave the true result.
- `vendor/bin/pint` with a `lang` argument reformatted six unrelated Laravel language files; they were reverted with `git checkout` before any commit.
- Spike open items stay open: real touch dragging, a drop into an empty column in a browser, and parallel moves of the same card (the parallel proof is plan 05-11).

## Known Stubs

None.

## Threat Flags

None. The new surface (Livewire `moveCard` handler, Admin-only page) is the T-05-22 to T-05-24 surface of the plan's threat model, mitigated as listed there.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 05-11 can reuse `ManagesTaskBoard`, `TaskBoard::columns` and `MoveTask` for the per-project board (fixed `projectId` in `BoardFilters`), add the preview slide-over in the card's `wire:sort:ignore` wrapper and the quick creation, and prove parallel moves against the real mover.
- Human checks for the phase gate: touch and mouse drag in a browser, a drop into an empty column, drag versus scrolling at 375 px, a drop inside Done snapping back (A11).

## Self-Check: PASSED

All created files exist; the five task commits are ancestors of HEAD; full suite (1843 tests), Pint and PHPStan are clean; `ddev composer check-licenses` passes.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
