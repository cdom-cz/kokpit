---
phase: 17-task-project-and-search-ux-refinements
plan: 05
subsystem: ui
tags: [filament, global-search, ordering, task-form]
requires:
  - phase: 17-01
    provides: TaskResource.php baseline of wave 1
provides:
  - active-first order of the tasks and projects groups in the global search
  - full-width "Kontrolni seznam" card in the task edit form
affects: [17-07]
tech-stack:
  added: []
  patterns: ["done-last bound orderByRaw in getGlobalSearchEloquentQuery(), applied before Filament's 50-row limit"]
key-files:
  created: []
  modified:
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/ProjectResource.php
    - tests/Feature/Search/GlobalSearchTest.php
    - tests/Feature/Tasks/TaskChecklistTest.php
key-decisions:
  - "Tasks had no search order before; newest first (created_at DESC, id DESC) is the deterministic order inside the active and the Done part (R-17-05-1)"
  - "The order lives in the base query, never in a collection sort, so Done matches cannot push an active match past the limit of 50 (R-17-05-3)"
requirements-completed: [P17-04, P17-01]
status: complete
actuals:
  tokens: 9000
  tasks: 3
  commits: 3
plan_head_before: 85a260ac78abc2de0cf71ffedb3d9bb522149ad2
plan_head_after: 4cdd1c5accdaab9e020679ddf1cce33b9c382859
---

# Phase 17 Plan 05: Active-first global search and full-width checklist card Summary

Global search lists tasks and projects that are not Done before the Done ones inside each group (a bound `(status = 'done') ASC` key in the base search queries), and the task form's "Kontrolni seznam" card spans the full grid width.

## Tasks

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Tracer: active tasks before Done tasks in the "Ukoly" group | 2892f10 | TaskResource.php, GlobalSearchTest.php |
| 2 | Projects active-first; 50-row limit cannot hide an active match | e95f056 | ProjectResource.php, GlobalSearchTest.php |
| 3 | Checklist card spans the full width of the task edit form | 4cdd1c5 | TaskResource.php, TaskChecklistTest.php |

## What was built

- `TaskResource::getGlobalSearchEloquentQuery()` keeps its archive filter and eager load and appends `orderByRaw('(<tasks.status> = ?) ASC', [ProjectStatus::Done->value])`, then `created_at DESC, id DESC` (R-17-05-1). The column is qualified by the model and the Done value is a bound parameter from the enum (T-17-15).
- `ProjectResource::getGlobalSearchEloquentQuery()` gets the same done-last key before its existing `orderBy(key)` (R-17-05-2).
- `ClientResource`, `$globalSearchSort`, and the result titles, details and links are untouched.
- `Section::make(__('kokpit.tasks.checklist.heading'))` in `TaskResource::form()` gains `->columnSpanFull()`, as `ViewTask::checklistSection()` already has (R-17-05-4).
- Tests: task in-group order (2 active + 2 Done, newest first in each part, category order unchanged); project order (a Done project with the smallest key is listed last, details and link unchanged); 50 Done tasks + 1 active (the oldest) still leaves the active task first in a 50-row group; checklist Section `getColumnSpan('default')` is `'full'`.

## Deviations from Plan

None - plan executed as written. Task 1 test and implementation were committed together (the tracer is a single behavior); the Task 2 tests were held back from the Task 1 commit so each commit contains only its own cases.

## Verification status

Worktree-only checks, all green: `php -l` on all six touched PHP files, Pint (`--test`, main checkout binary) on all touched files, plan grep acceptance criteria (`orderByRaw` count 2 in TaskResource and 1 in ProjectResource; `ProjectStatus::Done->value]` present; done-last key line precedes the `key` order in ProjectResource; `columnSpanFull` follows the checklist Section anchor; `getColumnSpan` present in TaskChecklistTest), and `scripts/check-sensitive.sh` on every commit.

Not run (per CLAUDE.md, worktree rules): Pest and PHPStan (shared DDEV DB). Left for the orchestrator after merge:

- `ddev exec vendor/bin/pest tests/Feature/Search tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskChecklistTest.php tests/Feature/Projects tests/Isolation`
- `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. If PHPStan reports one of the two `@phpstan-ignore argument.type` comments as unmatched, drop that comment (R-17-05-1).
- Plan gate runtime was not measured here (needs the DB).

## Known Stubs

None.

## Threat Flags

None. T-17-15 mitigated (qualified column, bound enum value); T-17-16 unchanged (resources stay Admin-only; the existing Partner no-result case covers it).

## Self-Check: PASSED

Commits 2892f10, e95f056, 4cdd1c5 are on the branch; the four modified files exist and are committed.
