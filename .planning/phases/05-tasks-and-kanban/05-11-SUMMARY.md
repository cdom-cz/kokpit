---
phase: 05-tasks-and-kanban
plan: 11
subsystem: tasks
tags: [livewire, filament-resource-page, slide-over, kanban, postgres-advisory-lock, concurrency, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: global board, ManagesTaskBoard, TaskBoard lock, MoveTask, task worker, TaskResource::quickCreateAction (plans 05-02 to 05-10)
  - phase: 03-operations
    provides: 03-SPIKE-KANBAN lock measurement, re-run here against the real service
provides:
  - ProjectBoard, a page of the Admin project resource at /admin/projects/{record}/board, sharing view and trait with the global board
  - previewAction (slide-over card preview) and quickCreateAction on both boards, header action "Nástěnka projektu" on the project page
  - boardProject() hook of ManagesTaskBoard that fixes the project in the filters and hides the client filter
  - task-worker move mode, UnlockedTaskBoard double and TaskBoardConcurrencyTest (two processes x 25 moves, with a no-lock mutation run)
affects: [05-12 Partner task list, 05-17 phase gate human checks]

actuals:
  tokens: 10950
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "A board page fixes its scope through one protected hook (boardProject) read by the filters, the quick-create preset and the view"
    - "A resource page that uses EnforcesPageAccessRule overrides canAccess(array $parameters = []) to match the resource Page signature; the declared AccessRule still decides"
    - "A Filament action preview resolves its untrusted argument inside the modal closures: UUID check, scoped findOrFail, Gate view; the lookup is memoised per request"
    - "Mounted-action modals are rendered as a Livewire partial, so tests evaluate the action (heading, content, footer actions) instead of reading the component html"
    - "Concurrency proof = clean run with the real class plus a mutation run with a test double that skips only the lock"

key-files:
  created:
    - app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php
    - resources/views/filament/pages/partials/task-preview.blade.php
    - tests/Concurrency/TaskBoardConcurrencyTest.php
    - tests/Support/UnlockedTaskBoard.php
  modified:
    - app/Filament/Concerns/ManagesTaskBoard.php
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
    - resources/views/filament/pages/task-board.blade.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskBoardTest.php
    - tests/Concurrency/task-worker.php

key-decisions:
  - "The per-project board is a resource page (InteractsWithRecord) of ProjectResource registered as 'board', so the existing route walk entry 'projects' (partner false) covers it without a new map line"
  - "ProjectBoard overrides canAccess(array $parameters = []) itself: the trait method takes no parameter and is incompatible with the resource Page signature; the override delegates to AccessRules::allows, so the AccessRule attribute still decides and the boot hook still refuses a Partner before mount"
  - "The board hook is boardProject(): the filters take its id as projectId and ignore the client filter, the quick create presets it, and the view hides the client filter through a computed hasFixedProject"
  - "The preview uses the Filament action API (slideOver, modalSubmitAction(false), modalContent, extra footer action) as assumed in A12; no fallback Livewire component was needed"
  - "The preview lookup runs while the modal renders, so a forged or archived id ends the mounting request with 404 and nothing is shown"
  - "getMaxContentWidth() returns Width::Full from the trait (R-1), so TaskBoardPage needed no change"
  - "The 'Otevřít úkol' link is an extra modal footer action (UI-SPEC Surface F), not part of the partial"
  - "Mutation evidence: without the lock the first round already fails about half of the 100 moves with exclusion-constraint violations and deadlocks"

patterns-established:
  - "Pattern: a RED test begins with assertActionExists so a missing Filament action fails on an assertion instead of a framework exception"
  - "Pattern: concurrency fixtures create tasks through the factory with the Admin as assignee and requester, and the cleanup deletes the tasks' activity rows before the tasks"

requirements-completed: [KB-01, KB-02]

coverage:
  - id: D1
    description: "The Admin opens /admin/projects/{project}/board from the project page; it lists only that project's tasks in the six columns with the client filter hidden and the project in the heading"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#lists only the tasks of the project on the project board and fixes the project in the heading"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#hides the client filter on the project board and keeps the other three"
        status: pass
    human_judgment: false
  - id: D2
    description: "A drop on the project board persists status and position, and cards of other projects in the global column keep their relative order with unique positions"
    requirement: KB-02
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#moves a card of the project to another column and keeps it there after a fresh mount"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#keeps the cards of other projects in their relative order when a card is dropped on a project board"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner gets 403 on the project board route as a request and as a component; the projects route walk stays green"
    requirement: KB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#refuses the project board to a Partner as a request and as a component"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The card's eye button opens a slide-over with reference, title, status, priority, dates, assignee, requester, parent, sanitised description and a link to the task page; it never shows tags, checklist, billing or comments"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#opens the preview of a card with reference, title, status, description and a link to the task page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#names the parent reference of a subtask in the preview"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#renders the description of the preview without the elements the rich text sanitiser removes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#shows no tags, checklist items, billing or comments in the preview"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#puts a preview button inside the sort-ignore wrapper of every card"
        status: pass
    human_judgment: false
  - id: D5
    description: "A forged, unknown, malformed or archived task argument of the preview answers 404 and shows nothing (T-05-25)"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#answers 404 to a preview of an archived task, an unknown id and a malformed id and shows nothing"
        status: pass
    human_judgment: false
  - id: D6
    description: "Quick creation on either board is the one-modal list action; the project is preset on a project board; both continue on the new task's edit page"
    requirement: KB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#creates a task from the quick create action of the global board and continues on its edit page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskBoardTest.php#presets the project of a project board in the quick create action and creates the task there"
        status: pass
    human_judgment: false
  - id: D7
    description: "Two worker processes moving 25 cards each into a filled and into an empty column leave unique contiguous positions and no failed move with the board lock; the same run without the lock is detected as broken (T-05-26)"
    requirement: KB-02
    verification:
      - kind: integration
        ref: "tests/Concurrency/TaskBoardConcurrencyTest.php#keeps every column consistent when two processes move 25 cards each into a filled and into an empty column"
        status: pass
      - kind: integration
        ref: "tests/Concurrency/TaskBoardConcurrencyTest.php#detects the defect when the board has no advisory lock (mutation run)"
        status: pass
    human_judgment: false
  - id: D8
    description: "Touch dragging at 375 px (card across columns, into an empty column, scroll versus drag), SPA navigation to a task page and back followed by another drag, and the look of the slide-over and the project board in light and dark mode"
    requirement: KB-02
    verification: []
    human_judgment: true
    rationale: "Real mouse and touch gestures, SPA navigation and visual contrast cannot be driven by the Pest/Livewire harness; the server-side handlers are proven, the gestures are the plan's two human checks (research A3, A4) plus the UI-SPEC visual check"

duration: 18min
completed: 2026-10-09
status: complete
plan_head_before: 692ae02506fc20de98dd6d261ba754a82db3ddb1
plan_head_after: 1cc11b63a0a3e9ef7e3c26d256ba033b0a7e5c18
---

# Phase 5 Plan 11: Project Board, Card Preview and Parallel Move Proof Summary

**Per-project kanban board as a page of the Admin project resource (project fixed in the filters, client filter hidden), a slide-over card preview with scoped lookup and view Gate, one-modal quick creation on both boards, and a two-process proof that the board advisory lock keeps columns consistent, shown to fail without the lock.**

## Performance

- **Duration:** 18 min
- **Started:** 2026-10-09T01:55Z (approx., first command of the run)
- **Completed:** 2026-10-09T02:14Z
- **Tasks:** 3 (tracer, one TDD task, one auto task)
- **Files modified:** 11 (4 created, 7 modified)

## Accomplishments

- The Admin opens a project's board from a "Nástěnka projektu" button on the project page. It lists only that project's tasks in the six columns, offers the assignee, tag and priority filters, and a drop persists status and position. Cards of other projects that sit between the visible ones keep their relative order in the global column.
- The eye button on each card (inside the `wire:sort:ignore` wrapper, 24 px target, 8 px from the open icon) opens a slide-over with the task's reference and title, status and priority badges, dates, assignee, requester, parent, the sanitised description and an "Otevřít úkol" footer link. The task argument is untrusted: UUID check, scoped `findOrFail`, `Gate::authorize('view')`.
- "Nový úkol" is a header action on both boards, delegating to `TaskResource::quickCreateAction`; the project of a project board is preset and the Admin continues on the new task's edit page.
- The advisory lock is proven against the real `MoveTask`: 2 processes x 25 moves into a filled column and into an empty one succeed completely with positions 0..54 and 0..49. With `UnlockedTaskBoard` the first round already fails a large share of the moves (exclusion-constraint violations and deadlocks).
- R-1 (full content width on both boards) and R-2 (long unbroken card titles wrap) were applied.

## Task Commits

1. **Task 1: Tracer, project board page** - `34f866b` (feat)
2. **Task 2: Preview and quick creation** - RED `663b410` (test), GREEN `625f91c` (feat)
3. **Task 3: Parallel move proof** - `1cc11b6` (test; test code and test support only)

**Plan metadata:** the `docs(05-11)` commits that add this SUMMARY, STATE.md and ROADMAP.md.

## TDD Gate Compliance

Task 2 has a `test(05-11)` RED commit (`663b410`) followed by a `feat(05-11)` GREEN commit (`625f91c`); no refactor commit was needed. Tasks 1 and 3 are a tracer and an auto task and carry no RED commit by design.

- **Task 2 RED:** 10 of 40 tests failed. 9 failed on `Failed asserting that an action with name [preview|quickCreate] exists on the [...] component` (the planned assertion: the boards had neither action); the preview-button test failed on its wrapper expectation (no `mountAction('preview'` in the sort-ignore wrapper). Each test opens with `assertActionExists` on purpose, because mounting a missing Filament action otherwise throws a framework exception instead of failing an assertion.
- **Semantic assessment of RED:** each failure was the planned missing feature; no setup, import or fixture fault. The 30 tests that passed at RED are the earlier board tests.
- **Test corrections between RED and GREEN (assertion intent unchanged):** the Filament test harness does not put a mounted action's modal into the component html (it is a Livewire partial), so GREEN introduced `taskBoardPreviewHtml()`, which evaluates the action's heading, content and footer actions; the 404 cases became `mountAction(...)->assertNotFound()` because the lookup runs while the modal renders; the project-board quick-create test uses `setActionData` + `callMountedAction` instead of a second `callAction`. An import-order fix by Pint went in with them.

Mutation runs: dropping the project id from the board filters failed 2 tests (own-tasks-only and relative-order); outputting the description raw instead of through `RichText::render` failed the sanitiser test; removing the board lock (`UnlockedTaskBoard`) fails the concurrency harness in the first round. Each mutation was reverted by re-editing.

## Files Created/Modified

- `app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php` - Admin-only resource page, `canAccess` override, title "Nástěnka projektu KEY · name", `boardProject()` returns the record
- `app/Filament/Resources/ProjectResource.php` - `board` page route `/{record}/board`
- `app/Filament/Resources/ProjectResource/Pages/ViewProject.php` - header action "Nástěnka projektu"
- `app/Filament/Concerns/ManagesTaskBoard.php` - `boardProject()` hook, `previewAction`, `quickCreateAction`, header actions, full width, memoised `previewData`
- `resources/views/filament/pages/task-board.blade.php` - client filter hidden on a project board, preview button, 24 px icon targets, wrapping titles
- `resources/views/filament/pages/partials/task-preview.blade.php` - the preview body
- `lang/cs/kokpit.php` - `task_board.project_action`, `project_title`, `card.preview`, `preview.close`
- `tests/Feature/Tasks/TaskBoardTest.php` - 15 new tests (project board, preview, quick creation)
- `tests/Concurrency/task-worker.php` - `move` mode with a board class switch
- `tests/Concurrency/TaskBoardConcurrencyTest.php` - the parallel proof and the mutation run
- `tests/Support/UnlockedTaskBoard.php` - `TaskBoard` double whose `lockBoard()` takes no lock

## Decisions Made

See `key-decisions` in the frontmatter. In short: a resource page for the project board, an explicit `canAccess` override for the resource Page signature, one `boardProject()` hook for filters, preset and view, the Filament action API for the preview (A12 held), and the footer link as an action.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] EnforcesPageAccessRule::canAccess() is incompatible with a resource Page**
- **Found during:** Task 1
- **Issue:** The trait declares `canAccess(): bool`, the resource `Page` declares `canAccess(array $parameters = [])`; `InteractsWithRecord` also calls `static::canAccess(['record' => ...])`. The trait method as written would not satisfy the parent signature.
- **Fix:** `ProjectBoard` declares `canAccess(array $parameters = [])` itself and delegates to `AccessRules::allows(static::class)`; the trait's boot hook still refuses a Partner before `mount()`.
- **Files modified:** `app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php`
- **Verification:** Partner 403 as request and component; `PanelRegistryTest` green
- **Committed in:** `34f866b`

**2. [Rule 1 - Bug] Plan wording "every column has unique positions 0..n-1"**
- **Found during:** Task 3
- **Issue:** Moving a card out of a column leaves holes in the source column by design (05-10), so contiguity cannot be asserted for every column. In this scenario the two rounds move all 50 + 50 source cards, so the source columns end empty.
- **Fix:** The test asserts no duplicate position among active non-done rows after each round, contiguous 0..54 and 0..49 in the two target columns, empty source columns and 105 tasks in total.
- **Files modified:** `tests/Concurrency/TaskBoardConcurrencyTest.php`
- **Committed in:** `1cc11b6`

### Other departures from the plan text (no behaviour change)

- `TaskBoardPage.php` was not touched: the header actions, the full width and the hooks live in the trait, which both pages use.
- The "Otevřít úkol" link is an extra modal footer action (UI-SPEC Surface F) rather than markup inside the partial; the link is asserted through the action.
- The preview 404 is raised while the modal renders, so the mounting request itself answers 404; the tests use `assertNotFound()`.
- The quick create project select is preset but still editable, as the plan says; UI-SPEC Surface E says "not editable". `TaskResource` is outside this plan's files, so it was left alone.
- The task worker keeps its positional arguments; the move mode appends `mode boardClass status index ids` after `widenMicros` (up to 12 arguments). The existing `TaskNumberConcurrencyTest` is unchanged and green.
- Process slip: after a mutation experiment on `TaskBoardConcurrencyTest.php` (a temporary debug line to print the defects), the file was first put back from a snapshot taken seconds before, then the debug line was removed by re-editing. The content was identical to the pre-experiment state; no other file was restored this way.

### Left untouched on purpose (per the run instructions)

- UI-SPEC F-8 (`ProjectStatus::InReview` colour 'primary' to fuchsia, Phase 4 enum): pending owner decision, not in `files_modified`. The preview status badge uses `getColor()` of the enum as built.
- UI-SPEC F-9 labels ("Uložit úkol" and the object-bearing header action labels): not required by this plan's tasks.
- UI-SPEC E-5 (danger toast "Úkol už není dostupný. Nástěnka se obnovila." for a refused move): not part of this plan's tasks; `moveCard` still ends in the framework error for a stale card. Candidate for a later plan or the 05-17 gate.
- F-2 tidy-up: only the lines touched by this plan use the spacing tokens (0.5rem, 1.5rem, 1rem); the rest of the board markup keeps its built values.

---

**Total deviations:** 2 auto-fixed (1 Rule 3 blocking signature, 1 plan wording corrected)
**Impact on plan:** None on scope; both fixes were needed for correctness of the page access and the honesty of the concurrency assertion.

## Issues Encountered

- A ddev Pest run right after a file write read a stale copy (bind-mount timing) several times; re-running after a short wait gave the true result.
- `Using $this when not in object context` when rendering a mounted action's modal view outside Livewire; solved by evaluating the action parts in the test helper instead of the modal view.
- The concurrency file takes about 55 seconds (105 factory tasks per fixture, two scenario rounds each, plus up to 5 mutation rounds; the mutation run needed only 1). The full suite takes about 170 seconds, 1860 tests green.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. The new route `/admin/projects/{record}/board` is Admin-only and covered by the projects route walk; the preview action argument is T-05-25 (scoped lookup, Gate, 404 tests); the lock regression is T-05-26 (parallel proof with the no-lock mutation run).

## Human Checks for the Phase Gate

1. Touch drag at 375 px (phone or emulated touch): drag a card across columns and into an empty column, scroll the board horizontally and vertically without starting a drag by accident (research A3, A4). Expect the card to land where dropped and stay after a reload; if dragging fights scrolling, record it for the drag-handle fallback.
2. SPA navigation: open the global board, click a card title to its task page, go back with the panel navigation, drag again. Dragging must still work without a full page reload.
3. Look at the slide-over (long description, wide rich-text table, subtask with parent) and at the project board with a populated column in light and dark mode (UI-SPEC visual check, contrast of the muted card text).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- The board surface (global and per project, preview, quick creation) is complete for KB-01 and KB-02. Plan 05-12 (Partner task list) is independent of it.
- The refused-move toast (E-5), F-8 and F-9 remain open for the owner or the 05-17 gate.

## Self-Check: PASSED

The four created files exist; the four task commits (`34f866b`, `663b410`, `625f91c`, `1cc11b6`) are ancestors of HEAD; the acceptance greps of all three tasks pass; the full suite (1860 tests), Pint and PHPStan are clean; `scripts/check-sensitive.sh` was clean on every commit.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
