---
phase: quick-261016-tnb
plan: 01
subsystem: tasks
tags: [filament, livewire, checklist, partner-isolation]
requires: []
provides:
  - ToggleTaskChecklistItem domain Action
  - Checklist section and guarded toggleChecklistItem() on the Admin task page
affects: [app/Filament/Resources/TaskResource/Pages/ViewTask.php]
tech-stack:
  added: []
  patterns: [target-state toggle, page-method access guard, shared row lock]
key-files:
  created:
    - app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php
    - resources/views/filament/tasks/checklist.blade.php
  modified:
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskChecklistTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
decisions:
  - The task page only ticks items; adding, editing, reordering and removing stays on the edit page
  - The page sends the target state, not a flip
status: complete
requirements: [TA-03]
commits: 3
plan_head_before: 9de80d940e3863f5dee7c48c6279d44770d268ed
plan_head_after: e4a58803b4b9da62b8b8dcea2a2feb97e64be295
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
completed: 2026-10-16
---

# Quick 261016-tnb: Task page checklist toggle Summary

The Admin task page /admin/tasks/KEY-N now shows the "Kontrolní seznam" section, and each item ticks or unticks with one click through the new `ToggleTaskChecklistItem` Action.

## What was built

- `ToggleTaskChecklistItem`: UUID check before any query, task re-read under a shared lock (withTrashed), item found only among that task's items under a row lock, `update` ability check, ValidationException for an archived task, write only when the state differs.
- `ViewTask`: private `checklistSection()` (Admin-only, shown only when the task has items, reusing the cached progress counts) and public `toggleChecklistItem(itemId, done)`. The method checks `TaskResource::canAccess()` first, turns a ModelNotFoundException into 404, shows the archived refusal as a danger notification, and reloads the counts so the "done/total" progress updates in the same response.
- `filament/tasks/checklist.blade.php`: escaped item text, checkbox with target-state `wire:click`, disabled for an archived task and while a request is in flight, strike-through for done items.
- Czech key `kokpit.tasks.errors.checklist_archived`, a CONTRIBUTING bullet and a RepositoryFilesTest pin.

## Commits

- 5cafe5b feat: Action, page method, section and view (tracer)
- 9900308 feat: archived read-only state, Czech notice, refusal and Partner tests
- e4a5880 docs: CONTRIBUTING note and RepositoryFilesTest pin

## Verification

- TaskChecklistTest (13 cases), isolation, archive, board, escalation and arch suites green; pint and phpstan clean.
- `ddev composer ci` green (4594 passed, 3 skipped; phpstan OK; licences OK).
- `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) passed on every commit.

## Deviations from Plan

None - plan executed exactly as written.

## Known Stubs

None.

## Threat Flags

None. The new public Livewire method is covered by threats T-261016-tnb-01 to -06 and proven by the tests.

## Self-Check: PASSED

All created files exist and commits 5cafe5b, 9900308 and e4a5880 are ancestors of HEAD.
