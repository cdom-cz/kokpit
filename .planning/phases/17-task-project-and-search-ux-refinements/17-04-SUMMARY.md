---
phase: 17-task-project-and-search-ux-refinements
plan: 04
subsystem: signal
tags: [signal, livewire, combobox, type-ahead, task-link, migration]
requires:
  - phase: 17-01
    provides: tasks.hidden_on_board (deliberately not filtered by this plan)
provides:
  - signal_tasks.task_id (nullable FK to tasks, ON DELETE SET NULL, indexed)
  - SignalTask::task() relation
  - LinkableTasks query class (search, find, label)
  - AddSignalTask optional task id argument (the only writer of the link)
  - SignalDay pick state (pickedTaskId, pickedTaskLabel, taskSuggestions, pickTask, clearPick)
  - accessible combobox markup in the Signal add field
affects: [17-06]
tech-stack:
  added: []
  patterns:
    - pick-time snapshot held in a #[Locked] Livewire property, stored unchanged by the Action
    - type-ahead with a bound, LIKE-escaped term
key-files:
  created:
    - database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php
    - app/Domain/Signal/Queries/LinkableTasks.php
    - tests/Feature/Signal/SignalTaskLinkTest.php
  modified:
    - app/Domain/Signal/Models/SignalTask.php
    - app/Domain/Signal/Actions/AddSignalTask.php
    - app/Livewire/Signal/SignalDay.php
    - resources/views/livewire/signal/day.blade.php
    - resources/views/filament/pages/signal/settings.blade.php
    - lang/cs/kokpit.php
    - tests/Feature/Schema/SignalTablesTest.php
key-decisions:
  - "The stored text of a linked signal is the label composed once in pickTask() and kept in #[Locked] pickedTaskLabel; AddSignalTask never composes or rebuilds it (D-01)"
  - "hidden_on_board is not filtered: a task hidden from the board is still offered (D-03)"
  - "The recurring-template form gets its own placeholder key so the new day placeholder does not leak into it"
requirements-completed: [P17-07]
status: complete
commits: 3
plan_head_before: 85a260ac78abc2de0cf71ffedb3d9bb522149ad2
plan_head_after: f73447b2930a5fbd03120bd17102b105901353ba
actuals:
  tokens: 21000
  tasks: 3
  commits: 3
---

# Phase 17 Plan 04: Link Signal tasks to Kokpit tasks Summary

The Signal add field is now a type-ahead over unfinished Kokpit tasks: picking one stores the signal linked to that task with the server-composed snapshot text `{project name}: {task name} [{task code}]`, and typing plain text still stores an unlinked signal exactly as before.

## What was built

- **Migration** `2026_10_29_000200_link_signal_tasks_to_tasks.php`: `signal_tasks.task_id` uuid NULL, FK to `tasks` with `nullOnDelete`, index `signal_tasks_task_id_index`. `down()` drops the index and the constrained column. No backfill.
- **`SignalTask`**: `task(): BelongsTo` relation and `@property string|null $task_id`; `task_id` is not fillable. The docblock now states the D-01 semantics.
- **`LinkableTasks`** (`final`, `App\Domain\Signal\Queries`): `search()` (at least two characters, nested ILIKE over reference, title, project key and project name with a bound, `addcslashes`-escaped term, ordered by project key, number, id, limit 10, scalars only), `find()` (UUID check, then the linkable query), `label()` (the snapshot text, shortening the task name so the `[code]` suffix survives, last-resort `mb_substr`). Linkable means: not Done, not archived, project `selectable()` (not archived, client not archived); `hidden_on_board` is not filtered.
- **`AddSignalTask`**: fifth argument `?string $taskId`. With an id, the title is the pick-time label the caller passes (cleaned like any title); inside the transaction, after the day lock, the task is re-read through `LinkableTasks::find()` only to refuse an unavailable one (`task_unavailable`) and to supply the id, written with `forceFill(['task_id' => ...])`. The Action never calls `label()`.
- **`SignalDay`**: `#[Locked] pickedTaskId` and `#[Locked] pickedTaskLabel`, `#[Computed] taskSuggestions` (empty while a pick is set), `pickTask()`, `clearPick()`. `addTask()` passes the locked label (never the field value) for a pick; when the save is refused and the task is no longer linkable, the pick is dropped and the text stays as free text.
- **Markup** (`day.blade.php`): combobox wrapper with Alpine open/active state, `role="combobox"` input bound `wire:model.live.debounce.250ms`, read-only after a pick, link prefix icon, spinner while searching, clear cross, `role="listbox"` of `role="option"` buttons (name line with ellipsis, code and project line), ArrowUp/ArrowDown/Enter/Escape/Tab handling, Filament dropdown classes plus inline styles only.
- **Copy**: `signal.day.new_placeholder` changed, `signal.day.clear_pick` and `signal.errors.task_unavailable` added.
- **Tests**: `SignalTaskLinkTest` (22 cases: scope, matching, wildcards, limit and order, snapshot, forged field and locked property, rename between pick and save, truncation, refusals, drop on unavailable, clear, edit keeps link, limit and lock rules, combobox/listbox/read-only HTML) and two cases in `SignalTablesTest` (FK 23503, SET NULL on hard delete).

## Task commits

1. Task 1 (tracer): `716071a` feat(17-04): link a Signal task to an unfinished Kokpit task by type-ahead
2. Task 2: `e9cb6da` test(17-04): pin the linkable scope, the pick-time snapshot and the refusals
3. Task 3: `f73447b` feat(17-04): make the Signal add field an accessible task combobox

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Recurring-template form would have shown the new day placeholder**
- **Found during:** Task 1 (changing `signal.day.new_placeholder`)
- **Issue:** `resources/views/filament/pages/signal/settings.blade.php` reuses `kokpit.signal.day.new_placeholder` for the recurring-template title; after the change it would read "Název úkolu nebo hledání v úkolech", which is wrong there (no task search in that form).
- **Fix:** new key `kokpit.signal.settings.template_placeholder` ("Název úkolu", the old wording) used by the settings view.
- **Files modified:** `lang/cs/kokpit.php`, `resources/views/filament/pages/signal/settings.blade.php`
- **Commit:** `716071a`

**2. [Scope placement] Czech copy landed with the tracer**
- All three copy changes (placeholder, `clear_pick`, `task_unavailable`) are in the Task 1 commit instead of being split across Tasks 2 and 3, because `pickTask()` already needs `task_unavailable` and the file cannot be staged in parts non-interactively. No behavioural effect.

**3. [Plan adaptation] Task 2 and Task 3 commits carry tests (and markup) only**
- The Task 2 rules (scope, snapshot, refusals) were implemented straight from R-17-04-3 to R-17-04-5 in Task 1, so the Task 2 commit contains the tests that pin them. No code change was needed, per the plan's "wherever a case fails".

**4. [Test adaptation] The 300-character task name case uses 255 characters**
- `tasks.title` is limited to 255 characters, so a 300-character task name cannot exist; the truncation case uses the longest possible task name (255 characters) plus the project prefix and the code suffix, which exceeds the 255-character limit all the same.

**5. [Markup adaptation] Spinner and clear cross are an overlay, not wrapper slots**
- `x-filament::input.wrapper` takes props (prefix icon, suffix text, actions), not slots, and its built-in loading indicator sits in the prefix position. The spinner (`wire:loading wire:target="newTitle"`) and the clear icon button are rendered in an absolutely positioned element over the right edge of the field, with end padding on the input. The link icon uses the wrapper's `prefix-icon`.

None of the above needed a user decision (no Rule 4 item).

## Verification status

Per the worktree rules nothing DB-backed was run here: no migration, no Pest, no PHPStan. Done in the worktree: `php -l` on every touched PHP file, Pint (`--test`) on the touched files (clean), the plan's grep acceptance checks (all as specified), `scripts/check-sensitive.sh` before every commit, hooks (gitleaks, sensitive-content) passed on all three commits.

To run after the merge on the HEAD branch (plan gate): `ddev exec php artisan migrate`, then `ddev exec vendor/bin/pest tests/Feature/Signal tests/Feature/Schema tests/Isolation tests/Arch`, `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. Things most likely to need a look there: PHPStan generics on the Eloquent `Builder` closures in `LinkableTasks::search()`; the `readonly` / `clearPick` substring assertions in the HTML tests; and the human-check of the keyboard and dropdown layout on the Signal "Dnes" page (light, dark, narrow window), which cannot be judged from server HTML. Plan gate runtime was not measured here.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-17-10 to T-17-14 are mitigated as planned: re-read through `LinkableTasks::find()` in the transaction, locked pick properties, bound escaped LIKE term, `RequiresAdmin` kept, owner-scoped table).

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php
- FOUND: app/Domain/Signal/Queries/LinkableTasks.php
- FOUND: tests/Feature/Signal/SignalTaskLinkTest.php
- FOUND commits: 716071a, e9cb6da, f73447b (ancestors of HEAD)
