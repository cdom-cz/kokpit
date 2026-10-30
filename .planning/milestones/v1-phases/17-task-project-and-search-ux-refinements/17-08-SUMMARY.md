---
phase: 17-task-project-and-search-ux-refinements
plan: 08
subsystem: ui
tags: [filament, time-tracking, task-view, documentation, contributing]
requires:
  - phase: 17-01..17-07
    provides: hidden_on_board, LinkableTasks, AddSignalTask, TaskTimerToggle, Project::scopeOpen, quickCreateAction, isInlineImage, DocumentActions::preview, RichText fi-prose
provides:
  - "TimeEntryResource::entryFields(lockContext:) disabling only client, project and task"
  - "ViewTask addTime header action writing through CreateTimeEntry with the context read from the task record"
  - "CONTRIBUTING Phase 17 refinements bullet, rewritten Documents, Board and Search sentences"
  - "DocumentUrl docblock naming the single signed PDF link exception"
  - "RepositoryFilesTest hand-over case for the Phase 17 bullet"
affects: [phase-18]
tech-stack:
  added: []
  patterns:
    - "Locked modal context: disabled fields are display only, the action body re-reads the context from the page record after the form data"
    - "Locked-field rejection is a danger toast plus Action::halt(); other errors are re-keyed to mountedActions.0.data.{key}"
key-files:
  created:
    - tests/Feature/TimeTracking/TaskAddTimeTest.php
  modified:
    - app/Filament/Resources/TimeEntryResource.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - lang/cs/kokpit.php
    - CONTRIBUTING.md
    - app/Domain/Documents/Support/DocumentUrl.php
    - tests/Feature/Repo/RepositoryFilesTest.php
key-decisions:
  - "No new time-entry rule: the action calls CreateTimeEntry::handle() with TimeEntryResource::actionData(); every rule stays in the Action (D-06)"
  - "The client of the task is read from the project row (withTrashed) in a private ViewTask::taskClientId(), used by both fillForm and the action body"
  - "tests/.pest/shards.json is not edited by hand: it needs the measured --update-shards run on the HEAD branch after the merge"
requirements-completed: [P17-05]
status: complete
commits: 3
plan_head_before: 267ad5af931ebfbb00ce00fe8f70cdea1d83c987
plan_head_after: 6ec39345616f42d99559d6eff154d7f784b2a9e3
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
---

# Phase 17 Plan 08: Add time from the task view and the Phase 17 hand-over Summary

The Admin adds time from a task in one click: "Přidat čas" opens the standard time-entry fields with client, project and task locked to the task, written by the unchanged `CreateTimeEntry` Action, and CONTRIBUTING plus the `DocumentUrl` docblock now hand Phase 17 over with names that exist and name the single PDF-link exception.

## What was built

- **Task 1 (tracer), commit f44ea12.** `TimeEntryResource::entryFields(bool $running, bool $reserved, bool $lockContext = false)`: the three context Selects use `->disabled($reserved || $lockContext)`, the task Select carries the helper text "Klient, projekt a úkol jsou dané úkolem." only when locked. `ViewTask::addTimeAction()` is registered after the timer action: `->model(TimeEntry::class)` (so the shared `?TimeEntry $record` closures get null), `fillForm()` with the task's client, project, task, `BillableDefault::for($task)`, start one hour ago and end now, visible only for a non-archived task and `TimeEntryResource::canCreate()`. The body aborts 403 unless `TaskResource::canAccess() && TimeEntryResource::canCreate()`, merges client/project/task from the task record after the form data, calls `CreateTimeEntry`, then clears the per-request `$timeTotals` cache, dispatches `time-entry-saved` and `$action->success()`. Czech copy added under `time.task` (`add_time`, `add_time_heading`, `context_locked`, `save_failed`).
- **Task 2, commit ffb0a90.** Tests for the locked fields, a forged context in the data (ignored), archived project and archived client (danger toast, modal stays mounted, no entry, no event), end before start (inline error under `ended_at`), hidden action on an archived task, editable fields on the TimeEntry create and edit pages, an overlapping entry (saved), and a non-billable default pre-filled and stored as false. The Task 1 body already satisfied all of them, so no code change was needed.
- **Task 3, commit 6ec3934.** CONTRIBUTING: the Documents clause now keeps the click-time rule for downloads, archives and image previews and names the PDF-link exception (owner decision "Signed PDF link exception"); the Board bullet says `TaskBoard::applyFilters()` is the one place that filters `tasks.hidden_on_board`; the Search bullet says not-Done tasks and projects come first before the 50-row limit; the new `Phase 17 refinements.` bullet follows the Per-client e-mail switch bullet. The `DocumentUrl` class docblock got the same exception in code-side words (docblock only). `RepositoryFilesTest` gained the Phase 17 hand-over case (every backticked name resolves, every `Class::method()` exists, the four test files and both migrations exist, the old Documents wording is gone).

## Deviations from Plan

None in behaviour. Two execution-environment notes:

- **[Scope, worktree rule] `tests/.pest/shards.json` not refreshed.** The refresh is `vendor/bin/pest --exclude-testsuite=Audit --update-shards`, a full DB-backed run that CLAUDE.md forbids in a worktree. The file is left unchanged; the acceptance grep for `TaskAddTimeTest` and `SignalTaskLinkTest` in it will pass only after that run on the HEAD branch.
- **[Precondition, Task 3]** The precondition (`git diff --quiet -- CONTRIBUTING.md` in the HEAD-branch checkout) was checked in this worktree only, where CONTRIBUTING.md was clean before the edit. Whether the HEAD-branch checkout has an uncommitted CONTRIBUTING.md change is for the orchestrator to confirm before merging.

## Post-merge steps still to run (HEAD branch, in DDEV)

1. `ddev exec php artisan migrate` (all Phase 17 migrations; this plan adds none).
2. `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TaskAddTimeTest.php tests/Feature/TimeTracking/TimeEntryResourceTest.php tests/Feature/TimeTracking/BillingLockTest.php tests/Isolation/TimeLeakTest.php`
3. `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php`
4. `ddev exec vendor/bin/pest --exclude-testsuite=Audit --update-shards`, keep `tests/.pest/shards.json`, then `ddev exec composer ci`; record both runtimes.

Cheap checks run here: `php -l` on every touched PHP file, Pint `--test` (main checkout binary) on the same files, `scripts/check-sensitive.sh`, the lefthook hook on each commit, and the plan's grep acceptance criteria (`$lockContext` count 3, `Action::make('addTime')` 1, `model(TimeEntry::class)`, `save_failed` 1, `time-entry-saved` 1, `halt()`, copy strings, `never rendered into a page` 0, `_blank` in `DocumentUrl.php`).

## Points most likely to need a fix if a post-merge test fails

The new tests were written without being executed (worktree rule). Most fragile spots: the `assertSchemaComponentExists(..., 'mountedActionSchema0', ...)` disabled checks, `assertActionMounted('addTime')` after a `halt()`, `assertSee('1:00')` for the worked figure, `assertFormFieldIsEnabled` on the TimeEntry create and edit pages, and the `billing_type => non_billable` key passed to `UpdateTask`.

## Known Stubs

None.

## Threat Flags

None. T-17-24 (forged context) is mitigated by reading the context from the task record after the form data and pinned by a test; T-17-25 by the 403 guard plus `Gate::authorize('create')` inside the Action; T-17-26 by fictional fixtures and the sensitive-content hook.

## Self-Check: PASSED

- FOUND: tests/Feature/TimeTracking/TaskAddTimeTest.php
- FOUND commits f44ea12, ffb0a90, 6ec3934 (ancestors of HEAD)
