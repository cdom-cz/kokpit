# Phase 17: Task, project and search UX refinements - Research

**Researched:** 2026-10-29
**Domain:** Filament 5 (v5.11.0) / Livewire 4 (v4.4.8) admin panel refinements on an existing Laravel 13 codebase (tasks, projects, search, Signal planner, documents)
**Confidence:** HIGH for codebase facts (every claim below was read from source this session); MEDIUM for the rich-text diagnosis (static analysis only, no browser) and for the new-window mechanics (browser behaviour, not testable server-side)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Signal and task link
- **D-01:** The pick inserts the text `{PROJECT}: {task code} {task name}` into the signal and stores a link to the task. The text is a snapshot taken at pick time; later renaming, completing or soft-deleting the task does not change the signal text. The link lives on (FK to the task); if the task is deleted the link is cleared silently and the signal stays plain text. A completed linked task no longer offers the timer control (the open-task icon stays while the task exists). - **Reversibility:** costly - introduces the first link between `SignalTask` and `Task`, which were deliberately separate ("the two never link"), plus a migration.
- **D-02:** The timer control on a linked signal reuses the existing task timer toggle (`TaskTimerToggle` / `TaskResource::timerAction()`), so one shared running timer applies across all surfaces. No separate signal timer.
- **D-03:** The type-ahead offers unfinished tasks (not archived, not soft-deleted, not in a terminal state), searched by task code, task name and project; tasks hidden from the kanban board are offered too. Result count is capped (about 10). Pick works inside the existing text field without switching modes; the cross icon clears the pick and returns to free text; with nothing picked, plain unlinked text is stored. In the listing the task name is not a link; the open-task icon is.

#### Kanban hide flag
- **D-04:** The flag is a form field on the task (checkbox, default off = shown). A hidden task disappears only from kanban boards (global board and project board). Lists, search, task detail, timer and time entries are unaffected.
- **D-05:** The hide also applies in the Partner view of the board where one exists. The flag is editable by the Admin only; Partner cannot change it.

#### Time and task from other places
- **D-06:** "Add time" is a header action on the task detail that opens a modal with the task pre-filled and locked. It reuses the existing TimeEntry create logic with all its rules (immutability of billed entries, validation); no new rules.
- **D-07:** "New task" is an action on the project detail that opens the existing task create form with the project pre-filled and locked (client derived from the project). The action is not offered for a project in a terminal state.

#### Files, rich text, search ordering
- **D-08:** Image previews open in a single-image lightbox without navigation between images; non-image files open in a new window/tab. Applies everywhere files are listed, in both the admin and the Partner panel (Partner only for files they may already see).
- **D-09:** Rich text is rendered with formatting on all read-only UI surfaces (admin and Partner panels: descriptions, comments and any other rich-text field) through sanitized, whitelisted HTML. E-mails and PDFs are unchanged.
- **D-10:** "Active" for the search ordering means a task or project not in a terminal state (task: not completed/cancelled; project: not Completed/Cancelled). Active first, then the rest, inside each category; existing relevance order within each group; category order (`$globalSearchSort`) and display unchanged.
- **D-11:** Project suggestions on task creation exclude projects in the "Completed" state (and the same terminal states as D-10, unless already handled).
- **D-12:** The project status is changeable from the project view with a header action and modal, mirroring `ViewTask::changeStatus` (same effect as saving the edit form).

### Claude's Discretion
Column/enum/migration names for the signal link and hide flag, the lightbox implementation (Filament modal vs. small component), how the type-ahead is wired into the existing signal input (Livewire), exact cs/en wording, icons, and the checklist card full-width implementation.

### Deferred Ideas (OUT OF SCOPE)
None - discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

Requirement IDs are TBD (phase_req_ids is null). Map the roadmap bullets / decisions instead:

| Ref | Description | Research Support |
|-----|-------------|------------------|
| P17-1 | Checklist card full width on the task form | Section lacks `columnSpanFull()` (TaskResource.php:272) |
| P17-2 | Rich text rendered with formatting (D-09) | HTML already passes through `RichText::render`; the three read-only surfaces lack `fi-prose` styling |
| P17-3 | Create task from project detail (D-07) | `TaskResource::quickCreateAction(?Project)` exists; needs a locked-project variant |
| P17-4 | Search: active first (D-10) | `getGlobalSearchEloquentQuery()` of TaskResource and ProjectResource |
| P17-5 | Add time from task (D-06) | `TimeEntryResource::entryFields()` + `CreateTimeEntry` Action |
| P17-6 | Hide-from-board flag (D-04, D-05) | New `tasks` column; filter in `TaskBoard::applyFilters`; pinned-column test must be updated |
| P17-7 | Signal task pick + timer + open icon (D-01..D-03) | New `signal_tasks.task_id` FK; `SignalDay` Livewire component, `AddSignalTask` Action |
| P17-8 | File lightbox / new window (D-08) | `DocumentActions::preview()` and the comment-thread blade |
| P17-9 | Task creation project suggestions (D-11) | `TaskResource::projectOptions()` and `PartnerTaskResource::projectOptions()` |
| P17-10 | Project status from project view (D-12) | `ViewProject` header action calling `UpdateProject` |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` [VERIFIED: file read this session].

- **Fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages: `example.com`, company ID `12345678`, `/Users/example/` paths. Test fakes assembled at runtime.
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass lefthook (`--no-verify`, `LEFTHOOK=0`).
- **Worktrees:** write code and tests, run only cheap checks with no shared state (Pint, static analysis if it needs no DB). **Do not run migrations from a worktree.** Final test run, migrations, PHPStan happen after merge into the HEAD branch, in DDEV (`ddev exec vendor/bin/pest`, `ddev exec vendor/bin/phpstan`).
- Partner must never see measured time, rates, prices, finance or another client's data: enforced by Policies and global query scopes, not UI hiding.
- UUID v7 keys everywhere; FK, unique, partial indexes and CHECK constraints in the DB.
- Everything in `.planning/` is English. The UI is Czech (`lang/cs/kokpit.php`, `lang/cs/documents.php`).
- Work starts through a GSD command (not relevant for this research).
- `tests/Arch/QueryEscapeHatchTest.php` bans bare `withoutGlobalScopes()`, `DB::table(` and removing `PartnerScope` in `app/`; use `withoutGlobalScopes([SoftDeletingScope::class])` [VERIFIED: tests/Arch/QueryEscapeHatchTest.php:1-40].

## Summary

Phase 17 is ten small, mostly independent refinements on top of an architecture that already provides every building block: a shared task timer toggle, a static time-entry field builder, a shared change-status action pattern, a domain Action per write, an `applyFilters` choke point for the kanban boards, and one `DocumentActions::preview()` for file previews. No new packages are needed. The two risky items are **Signal task linking** (first FK between `signal_tasks` and `tasks`, a Livewire type-ahead, a timer from a plain Livewire component) and the **file lightbox** (the codebase deliberately never renders a signed file URL into page HTML).

**Four facts in CONTEXT.md do not match the code and the planner must reconcile them:**
1. **There is no "Completed" or "Cancelled" status.** `ProjectStatus` (shared by projects AND tasks) has six cases: `planned`, `to_clarify`, `in_progress`, `in_review`, `ready_to_release`, `done`. The Czech label of `done` is "Dokončeno" (= "Completed"). The only terminal state is `ProjectStatus::Done`; "Cancelled" does not exist. D-10 and D-11 therefore reduce to `status = 'done'` [VERIFIED: app/Domain/Projects/Enums/ProjectStatus.php:17-22; lang/cs/enums.php:71-78]. Archived (soft-deleted) rows are a separate concept and are already excluded from search and pickers.
2. **A Partner has no board anywhere** (KB-03). D-05's "Partner view of the board where one exists" is a no-op: there is nothing to change on the Partner side, and the flag needs no Partner-side enforcement beyond "Partner cannot write it" (the Partner has no task edit form and `CreateTask` ignores unknown keys) [VERIFIED: app/Filament/Pages/TaskBoardPage.php:18-21].
3. **There is no task "create form" page.** The task resource has pages `index`, `view`, `edit` only; creation is the `quickCreate` modal (project + title) that redirects to the edit page. D-07's "existing task create form" is that modal [VERIFIED: TaskResource.php:606-613, 554-594].
4. **The rich-text gap is styling, not sanitising.** All read-only descriptions already pass through `RichText::render()`; the Admin and Partner `TextEntry` and the board preview partial render it without the `fi-prose` class, so Tailwind's reset flattens lists, headings, tables and blockquotes. Comments already use `fi-prose` [VERIFIED: TaskColumns.php:111,237; comment-thread.blade.php:19; task-preview.blade.php:44].

**Primary recommendation:** Implement each item inside the existing seams (domain Action owns the rule, Filament action/page owns the UI, one shared builder per behaviour). Add exactly two migrations (`tasks.hidden_on_board`, `signal_tasks.task_id`), update the pinned Partner-safe column list for `tasks`, and treat the Signal link as the highest-risk item (build the title server-side from the task id, never trust client text).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Checklist card full width | Frontend Server (Filament form schema) | - | Pure layout of `TaskResource::form()` |
| Rich-text read-only formatting | Frontend Server (Filament infolist/Blade) | Domain (`RichText::render` sanitiser) | Sanitising stays in `RichText`; styling is a CSS class on the rendered node |
| New task from project detail | Frontend Server (Filament action) | API/Backend (domain `CreateTask`) | The action collects the title; `CreateTask` owns numbering, locks, rules |
| Search ordering | Database / Storage (ORDER BY in the search query) | Frontend Server (resource hook) | Must be applied before `LIMIT 50` |
| Add time from task | Frontend Server (Filament action) | Domain (`CreateTimeEntry`) | Reuses the Action; no new rule |
| Hide from board | Database (column) | Domain (`UpdateTask`, `TaskBoard::applyFilters`) | The filter must live in the one board query helper |
| Signal task link | Database (FK) | Domain (`AddSignalTask`), Livewire (`SignalDay`) | Link and title snapshot are written by the Action, validated server-side |
| Signal timer / open icon | Livewire component | Domain (`TaskTimerToggle`, `StartTimer`/`StopTimer`) | Shared toggle; component only dispatches |
| Image lightbox | Frontend Server (Filament modal) | API (`DocumentFileController` signed route) | URL minted at click time inside the modal |
| New-window open of non-images | Browser | Frontend Server | Needs a user gesture; see Pitfall 4 |
| Project suggestions exclude Done | Database (scope) | Frontend Server (option builders) | One reusable scope on `Project` |
| Project status change | Frontend Server (header action) | Domain (`UpdateProject`) | Same effect as the edit form |

## Standard Stack

No new dependencies. All versions below are installed [VERIFIED: vendor/composer/installed.json, composer.json].

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| filament/filament | v5.11.0 | Panel, actions, modals, infolists, global search | Fixed by the brief |
| livewire/livewire | v4.4.8 | `SignalDay` component, `wire:model.live`, events | Used by the existing planner |
| laravel/framework | ^13.17 | Eloquent, migrations | Fixed by the brief |
| symfony/html-sanitizer (via `RichText`) | installed | The one rich-text sanitiser | Already wrapped in `App\Domain\Shared\Text\RichText` |
| pestphp/pest | ^5.3 | Tests | Existing suite |
| larastan/larastan | ^3.12 | Static analysis | `composer stan` |
| laravel/pint | ^1.27 | Formatting | `composer lint` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Filament modal for the lightbox | A third-party lightbox JS package | Rejected: AGPL/legitimacy review, extra asset pipeline (there is no Vite/theme build in this repo: `resources/` has only `fonts` and `views`), and a Filament modal already gives focus trap, ESC, backdrop |
| `tasks.hidden_on_board` column | Separate Admin-only table | Over-engineered for one boolean; the column is not sensitive (see Pitfall 2) |
| `window.open` from a Livewire response | Rendering a signed `<a href target=_blank>` | The second contradicts the "never render a signed link" rule (D-10, T-09-18); see Pitfall 4 |

**Installation:** none. **Package Legitimacy Audit:** not applicable, this phase installs no external packages.

## Architecture Patterns

### System Architecture Diagram

```
Admin browser
  |
  |-- Task view/edit --------------------------------------------+
  |     |-- "Add time" header action --> modal(entryFields, locked ctx)
  |     |        --> TimeEntryResource::actionData + task ctx --> CreateTimeEntry Action --> time_entries
  |     |        --> dispatch time-entry-saved --> timer panel / project tab refresh
  |     '-- edit form: checklist Section(columnSpanFull), hidden_on_board checkbox
  |              --> TaskResource::actionData --> UpdateTask Action --> tasks.hidden_on_board
  |
  |-- Project view -------------------------------------------------+
  |     |-- "New task" action (hidden when status = done)
  |     |        --> quickCreate modal, project Select disabled --> CreateTask Action --> redirect to task edit
  |     '-- "Change status" action --> UpdateProject(['status'=>..]) --> projects
  |
  |-- Kanban (global + project board) --> TaskBoard::applyFilters (+ hidden_on_board = false)
  |         ^ used by columns() AND by move() neighbour recompute
  |
  |-- Global search --> Resource::getGlobalSearchEloquentQuery (ORDER BY done-last, then existing) --> LIMIT 50
  |
  |-- Signal "Today" --> SignalDay (Livewire)
  |     |-- input wire:model.live --> taskSuggestions() --> Task query (unfinished, <=10)
  |     |-- pickTask(id) --> pickedTaskId (display text) ; cross --> clearPick()
  |     |-- addTask() --> AddSignalTask(actor, title, date, category, ?taskId)
  |     |        '-- re-reads Task by id, rebuilds "{PROJECT}: {code} {name}", writes signal_tasks.task_id
  |     '-- row: timer button --> TaskTimerToggle::handle (shared running timer) ; open icon --> TaskResource view URL
  |
  '-- Files (any list) --> DocumentActions::preview()
        |-- image/*  --> modal whose content mints DocumentUrl::preview() at render time --> <img>
        '-- other    --> mint DocumentUrl at click time --> open in new window (see Pitfall 4)
                              |
                              v
                     DocumentFileController (signed, user-bound, policy `view`) --> stream
```

### Recommended Project Structure (touch list)

```
app/Domain/Projects/Models/Project.php            # + scopeOpen() (status != done)
app/Domain/Tasks/Models/Task.php                  # + @property bool $hidden_on_board ; cast
app/Domain/Tasks/Actions/UpdateTask.php           # + hidden_on_board key
app/Domain/Tasks/Board/TaskBoard.php              # applyFilters: hidden_on_board = false
app/Domain/Signal/Models/SignalTask.php           # + task() BelongsTo, update class docblock
app/Domain/Signal/Actions/AddSignalTask.php       # + ?string $taskId
app/Domain/Signal/Queries/SignalDayReader.php     # eager load linked task; + task suggestions query
app/Livewire/Signal/SignalDay.php                 # pick/clear/suggestions/toggleTaskTimer + #[On] timer events
app/Filament/Resources/TaskResource.php           # form (checklist span, flag), quickCreate lock, options, search order, actionData/fillData
app/Filament/Resources/TaskResource/Pages/ViewTask.php        # Add time action
app/Filament/Resources/ProjectResource.php        # search order
app/Filament/Resources/ProjectResource/Pages/ViewProject.php  # New task + Change status actions
app/Filament/Resources/TimeEntryResource.php      # entryFields(): lockContext option
app/Filament/Support/TaskColumns.php              # ->prose() on both description entries
app/Filament/Support/DocumentActions.php          # preview(): lightbox / new window
app/Filament/Concerns/RendersTaskCommentThread.php + comment-thread.blade.php  # same preview behaviour
app/Filament/Partner/Resources/PartnerTaskResource.php        # projectOptions(): open projects
resources/views/filament/pages/partials/task-preview.blade.php # fi-prose
resources/views/livewire/signal/day.blade.php     # type-ahead, cross, timer/open icons
resources/views/filament/documents/lightbox.blade.php (new)    # <img> in modal
database/migrations/2026_10_29_000100_add_hidden_on_board_to_tasks_table.php (new)
database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php (new)
lang/cs/kokpit.php, lang/cs/documents.php          # new strings
tests/Isolation/PartnerSafeColumnsTest.php         # update the pinned tasks list
```

### Pattern 1: Checklist card full width (P17-1)
**What:** add `->columnSpanFull()` to the checklist `Section`. Today the other sections (`status`, `dates`, `people`, `billing`, `tags`) sit in the 2-column grid; only `main` is `columnSpanFull()`. The ViewTask checklist section already has it.
```php
// TaskResource::form(), the Section at line 272
Section::make(__('kokpit.tasks.checklist.heading'))
    ->columnSpanFull()
    ->schema([ /* Repeater unchanged */ ]),
```
[VERIFIED: TaskResource.php:200-203,272-291; ViewTask.php:101-103]. Only the checklist is in scope; leave billing/tags as they are.

### Pattern 2: Rich text formatting (P17-2, D-09)
**What:** keep `RichText::render()` (it is the sanitiser the Partner isolation tests rely on; do NOT switch to Filament's `->html()`/`RichContentRenderer`, whose default sanitiser allows `style`/`class`/`data-*`, see the security comment in `CanFormatState::html()`). Add the prose class on the three surfaces that lack it:
```php
// TaskColumns::adminEntries() and ::partnerEntries()
TextEntry::make('description')
    ->label(__('kokpit.tasks.fields.description'))
    ->formatStateUsing(static fn (?string $state): HtmlString => RichText::render($state))
    ->prose()                       // adds `fi-prose` to the item (TextEntry::prose())
    ->placeholder(__('kokpit.tasks.empty_value'))
    ->columnSpanFull(),
```
```blade
{{-- task-preview.blade.php: wrap the render in the prose class --}}
<div class="fi-prose" style="overflow-x: auto; overflow-wrap: anywhere;"> ... RichText::render($description) ... </div>
```
[VERIFIED: TextEntry::prose() at vendor/filament/infolists/src/Components/TextEntry.php:91; item class `'fi-prose' => $isProse || $isMarkdown` at :340; `Htmlable` state is rendered unescaped at CanFormatState.php:403-405; `.fi-prose` rules (ul/ol/h1-h6/table/blockquote) present in public/css/filament/filament/app.css]. Other `RichEditor` fields are all write-side (task description, comment body, Partner description, escalation reason); the only read-only rich-text outputs are the four `RichText::render` call sites. Project and time-entry descriptions are plain `Textarea` text, not rich text. After the change, grep for any new read-only rich-text output in the phase and route it through the same helper. Tailwind's `fi-prose` also styles `table`; the preview already wraps in `overflow-x: auto`.

### Pattern 3: Project status change (P17-10, D-12)
Mirror `ViewTask::changeStatusAction()` as a header action on `ViewProject` (ProjectResource has no infolist; `ViewProject` shows the disabled form, so a header action is the right place, not a `hintAction`):
```php
Action::make('changeStatus')
    ->label(__('kokpit.projects.actions.change_status'))
    ->icon(Heroicon::OutlinedArrowsRightLeft)
    ->color('gray')
    ->modalHeading(...)->modalSubmitActionLabel(...)
    ->successNotificationTitle(...)
    ->visible(fn (): bool => ProjectResource::canEdit($this->getRecord()) && ! $this->getRecord()->trashed())
    ->fillForm(fn (): array => ['status' => ProjectResource::enumValue($this->getRecord()->status)])
    ->schema([Select::make('status')->options(ProjectStatus::class)->required()->native(false)])
    ->action(function (array $data, Action $action): void {
        abort_unless(ProjectResource::canAccess(), 403);   // /livewire/update is public
        $status = ProjectResource::enumValue($data['status'] ?? null)
            ?? throw ValidationException::withMessages(['mountedActions.0.data.status' => ...]);
        app(UpdateProject::class)->handle($project, ['status' => $status]);   // only the status key
        $project->refresh();
        $action->success();
    });
```
`UpdateProject` with only `status` is safe: absent keys keep the stored value (name, key, billing re-saved unchanged), there is no status transition rule (D-16), and the project `LoggedAttributes` include `status`, so one history row is written. `UpdateProject` performs no Gate check of its own, so the action body must `abort_unless(ProjectResource::canAccess(), 403)` like the task version [VERIFIED: UpdateProject.php:70-130 (docblock: "Status and priority can be set to any value in any order"), ViewTask.php:312-362]. Hide it on an archived project (mirrors `TaskResource::canEdit`); this is a small unrequested decision, flag it in the plan.

### Pattern 4: Search ordering (P17-4, D-10)
Filament builds results as `getGlobalSearchEloquentQuery()` + attribute constraints + `->limit(50)` [VERIFIED: HasGlobalSearch.php `getGlobalSearchResults`, `$globalSearchResultsLimit = 50`], so ordering must be applied in `getGlobalSearchEloquentQuery()` to affect which rows survive the limit. Add an "active first" key before the existing order; use a boolean expression so it is index-agnostic and Postgres-native:
```php
// TaskResource::getGlobalSearchEloquentQuery()  (currently has NO order at all)
->orderByRaw("({$statusColumn} = 'done') ASC")   // false (active) sorts first; column qualified via (new Task)->qualifyColumn('status')
// ProjectResource::getGlobalSearchEloquentQuery()  (currently ->orderBy(key)); put the new key BEFORE it
->orderByRaw("({$statusColumn} = 'done') ASC")->orderBy(key)
```
Use `ProjectStatus::Done->value` rather than the literal; the value is an enum constant, never input (same justification as `TaskColumns::orderByPriority`, which uses `orderByRaw` with a `@phpstan-ignore argument.type`). Tasks currently have no relevance order, so "existing order within each group" is whatever Postgres returns; add a deterministic tie-break (`tasks.reference`, then `tasks.id`) if the plan wants stable tests, this is a harmless addition. `ClientResource` is not part of D-10 (clients have a different status vocabulary).

### Pattern 5: Hide from board (P17-6, D-04/D-05)
Migration adds `tasks.hidden_on_board boolean NOT NULL DEFAULT false`. The filter goes in ONE place:
```php
// TaskBoard::applyFilters(), next to the other where clauses
$query->where('tasks.hidden_on_board', false);
```
`applyFilters` feeds both `columns()` (rendered cards, Done `total` count) and `move()`'s `$visible` neighbour list, so a drop index is computed over exactly the visible cards and hidden cards keep their relative position via `insertionIndex` [VERIFIED: TaskBoard.php `applyFilters`, `columns`, `move`, `insertionIndex`]. Nothing else (lists, search, timer, task detail) filters on it. Wire the flag through `TaskResource::form()` (Checkbox or Toggle; existing code uses `Toggle` for booleans), `fillData`, `actionData` (`(bool)`), and `UpdateTask` (`if (array_key_exists('hidden_on_board', $data)) $attributes['hidden_on_board'] = (bool) $data[...]`). Keep it out of `Task::$fillable` mass assignment? `UpdateTask` uses `fill($attributes)`; either add it to `#[Fillable]` or `forceFill` it (the model's rule: only plain content columns are fillable; a display flag is plain content, so adding to `#[Fillable]` is acceptable, but then `CreateTask`'s `new Task($attributes)` never receives it, fine). Do not add to `CreateTask` (new tasks are always shown) and do not add to `LoggedAttributes` unless the plan wants history rows (a UI preference; recommended: not logged).

### Pattern 6: Task from project detail (P17-3, D-07, D-11)
Reuse `TaskResource::quickCreateAction($project)` and add a lock option:
```php
public static function quickCreateAction(?Project $project = null, bool $lockProject = false): Action
// inside the project Select:
->disabled($lockProject)->dehydrated(false)   // a disabled field is not sent
// inside action(): $chosen = $lockProject ? $project : Project::find($data['project_id'])
```
Because a disabled field is not dehydrated, the action body MUST read the project from the closure `$project`, not from `$data['project_id']` (the precedent: `entryFields($reserved)` "disabled so they are not sent and the Action keeps the stored values"). `CreateTask` re-validates the project under a share lock (`selectable()`), so a forged id cannot escape. On `ViewProject` add `TaskResource::quickCreateAction($record, lockProject: true)->visible(status !== done && ! trashed)`. Keep the `ProjectBoard` header unlocked (not in scope), or lock it too by passing the flag, plan's call.

For D-11 add one scope and use it for the pickers:
```php
// Project
public function scopeOpen(Builder $query): void { $query->where("{$this->getTable()}.status", '!=', ProjectStatus::Done->value); }
```
Apply `->open()` ONLY in the creation pickers: `TaskResource::quickCreateAction`'s options and `PartnerTaskResource::projectOptions()` (the Partner create form; CONTEXT says "task creation" without an audience, see Open Question 2). **Do not** reuse the same helper for the list filter: `TaskResource::projectOptions()` is shared by the quick-create Select (line 564) and the project list filter (line 782); split it (e.g. `projectOptions(bool $openOnly = false)`), otherwise the filter would lose completed projects. `EntryContextOptions::projects()` (time-entry picker) must stay unchanged: tracked time on a completed project is legitimate. No domain-level refusal in `CreateTask` is required by D-11 and adding one would change API/Partner behaviour.

### Pattern 7: Add time from a task (P17-5, D-06)
`TimeEntryResource::entryFields(bool $running, bool $reserved)` is the shared static builder. `$reserved` disables client, project, task, times AND billable, so it cannot be reused for "lock only the context". Add a third parameter (e.g. `bool $lockContext = false`) that disables just the three context Selects (client, project, task), then:
```php
Action::make('addTime')   // on ViewTask::getHeaderActions()
    ->label(__('kokpit.time.task.add_time'))->icon(Heroicon::OutlinedClock)
    ->visible(fn (): bool => TimeEntryResource::canCreate() && ! $this->getRecord()->trashed())
    ->fillForm(fn (): array => ['client_id' => ..., 'project_id' => $task->project_id, 'task_id' => $task->getKey(), 'billable' => app(BillableDefault::class)->for($task)])
    ->schema(TimeEntryResource::entryFields(lockContext: true))
    ->action(function (array $data, Action $action) {
        // disabled fields are NOT in $data: merge the context from the record, never from the form
        $entry = app(CreateTimeEntry::class)->handle($actor, TimeEntryResource::actionData([...$data, 'client_id' => $clientId, 'project_id' => $task->project_id, 'task_id' => $task->getKey()]));
        $livewire->dispatch('time-entry-saved'); $action->success();
    });
```
The client id comes from the task's project (`Project::withTrashed()->find($task->project_id)->client_id`, as `EntryContextOptions::clientIdOfProject` does). Pitfall: `CreateTimeEntry` raises `ValidationException` keyed `client_id`/`project_id`/`task_id` (archived client/project/task, inconsistent context) and these fields are disabled in the modal, so the message would be invisible: catch it and show a `Notification` (as `TaskResource::runLifecycle` does) or re-key to `ended_at`. `Gate::authorize('create', TimeEntry::class)` is inside the Action. The `started_at`/`ended_at` pickers are `required()` with no default; prefill a sensible default (e.g. start = now - 1h, end = now, in `FilamentTimezone`) or leave empty and let the user fill them (plan's call). Dispatch `time-entry-saved` so the timer panel, `ProjectTasksTimeRelationManager` and the stats widgets refresh [VERIFIED: grep of `#[On('time-entry-saved')]`]. The time section on `ViewTask` computes `timeTotals` once per request in a private property; refresh the record/totals after save (set `$this->timeTotals = null` via a method, or `$this->redirect(...)`).

### Pattern 8: Signal task link (P17-7, D-01..D-03) - highest risk

> **SUPERSEDED signal format:** the text composed below (`"{$project}: {$task->reference} {$task->title}"`, i.e. `{PROJECT}: {task code} {task name}`) is replaced by `{PROJECT}: {task name} [{task code}]` with `{PROJECT}` the project name, built server-side and truncated to 255 characters so the `[task code]` suffix survives (17-CONTEXT.md, Plan-phase Clarifications, "Signal text format"). Where this pattern and plans 17-04 / 17-06 differ (format, suggestion order, component properties), the plans are binding.

**Schema** (new migration; never run from a worktree):
```php
Schema::table('signal_tasks', function (Blueprint $table): void {
    $table->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete();   // uuid FK, SET NULL on hard delete
    $table->index('task_id', 'signal_tasks_task_id_index');
});
```
Tasks are only ever soft-deleted (archive), so `ON DELETE SET NULL` only matters for a hard delete (D-01 "if the task is deleted the link is cleared silently"). A soft-deleted (archived) task keeps the link; the open icon still works (the task page opens read-only for archived tasks) and the timer control is hidden for archived or Done tasks (`timerAction` is already `visible(! trashed)`; D-01 adds "not Done"). Update the `SignalTask` docblock ("the two never link") and the migration header comment of the planner tables is historical, leave it. `SignalTablesTest` does not pin the column list (grep shows `getColumnListing` only in `PartnerSafeColumnsTest`), but `ModelDeclarationTest`/`SchemaConventionsTest` run over all tables (uuid keys, timestamptz).

**Write path:** extend the domain Action, not the component:
```php
AddSignalTask::handle(User $actor, string $title, string $forDate, ?SignalCategory $chosen, ?string $taskId = null)
```
When `$taskId` is given: require `Str::isUuid`, load `Task::query()->with('project')->whereKey($taskId)` (soft-delete scope on, so archived tasks are not found), refuse `status === done` with a `SignalRuleViolation::because('task_unavailable')` (messages live in `kokpit.signal.errors.*`), and **build the title server-side** from the row: `"{$project}: {$task->reference} {$task->title}"` [SUPERSEDED: `{PROJECT}: {task name} [{task code}]`, see the note at the top of this pattern], `mb_substr(..., 0, SignalRules::MAX_TITLE_LENGTH)` (title column is `varchar(255)` with a `btrim(title) <> ''` CHECK; a long task name must be truncated, not rejected). Set the link with `forceFill(['task_id' => $task->getKey()])` (ids are never mass-assigned in this codebase). The client-sent text is ignored for a pick, so a forged title cannot be attached to a task. Free text (no id) is unchanged and stores `task_id = null`.

**`{PROJECT}` is ambiguous** (project key `ABC` vs. project name): the task code `ABC-12` already contains the key, so `ABC: ABC-12 Fix login` would be redundant; recommend the project **name**. See Open Question 1; get an explicit answer before planning. [RESOLVED: project name, format `{PROJECT}: {task name} [{task code}]`.]

**Livewire (`SignalDay`):** public properties must be scalar/array/null with declared types (Arch test `LivewireComponentContractTest`) and the component must keep `RequiresAdmin`. Add `public ?string $pickedTaskId = null;` and `public string $pickedLabel = '';` (display only). Suggestions are a `#[Computed]` array: when `pickedTaskId === null` and `mb_strlen(trim($newTitle)) >= 2`, query unfinished tasks (`status != done`, soft-delete scope on, `hidden_on_board` NOT filtered per D-03), match `tasks.reference` / `tasks.title` / project key+name with `ILIKE` and an escaped pattern (escape `\`, `%`, `_`), `limit(10)`, order by `reference`. Return `[{id, reference, title, project}]` scalars only. `pickTask(string $id)` validates the UUID, re-reads the task through the same unfinished query, sets `pickedTaskId` and `newTitle` to the composed label (same composer function as the Action, put it in one static helper so both agree). `clearPick()` resets both. `addTask()` passes `$this->pickedTaskId` to the Action; reset after success. In the Blade, the input becomes `readonly` while a pick is set and shows an `x-filament::icon-button heroicon-m-x-mark` cross inside the `x-filament::input.wrapper` suffix slot; the suggestion list is an absolutely positioned `<ul>` of `<button type="button" wire:click="pickTask('...')">`. Type-ahead uses `wire:model.live.debounce.250ms="newTitle"`; the suggestions query runs for plain free text too, but is capped and trivial. Edit mode (`editTitle`): leave links untouched (renaming a linked signal keeps its link and snapshot semantics); flag in Open Question 3.

**Listing:** `SignalDayReader::tasks()` must eager load the linked task including trashed ones (`->with(['task' => fn ($q) => $q->withTrashed()->select(...)])`; use the `withoutGlobalScopes([SoftDeletingScope::class])` form, a bare `withoutGlobalScopes()` fails the Arch scan) to avoid N+1; the `day()` computed array gains per task `linked: null|{task_id, url, can_time, running}` where `url = TaskResource::getUrl('view', ['record' => $task])`, `can_time = ! trashed && status !== done`, `running = runningTaskId === task_id`. The task name stays plain text (not a link); add two icon buttons (timer play/stop, open task `heroicon-m-arrow-top-right-on-square` as `<a href>`).

**Timer:** add `public function toggleTaskTimer(string $signalTaskId): void` that re-reads the signal task through the owner scope (`findOwned`-style), reads `task_id` from the DB row (never from the client), loads the Task, and calls `app(TaskTimerToggle::class)->handle($this->actor(), $task)`; dispatch the returned event name (`timer-started` / `timer-stopped`), and refresh on `#[On('timer-started')]`, `#[On('timer-stopped')]` (and `time-entry-saved`) by `unset($this->day)`, because two `SignalDay` instances (today + tomorrow) and the top-bar timer share one running timer [VERIFIED: ControlsTimer.php:163-166, TaskTimerToggle.php:46-60]. `TaskTimerToggle` lives in `App\Filament\Support` and uses `Filament\Notifications\Notification`, which `RunsSignalActions` already uses, so it works from a plain Livewire component. `StartTimer` authorizes via Gate; a Partner cannot reach the component (`RequiresAdmin`).

### Pattern 9: Files lightbox and new window (P17-8, D-08)
**Constraint that shapes the design:** "A screen never renders such a link into its HTML: an action builds it at click time and redirects, because a page that stays open longer than the lifetime would otherwise hold a dead link" (`DocumentUrl` docblock, `DocumentActions` docblock; TTL `signed_url_ttl_seconds => 120`) [VERIFIED: DocumentUrl.php:11-17; config/kokpit.php:334]. The server controller is signed + user-bound + policy-checked + `nosniff`; only the five types in `INLINE_MIME_TYPES` (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `application/pdf`) are ever served inline [VERIFIED: DocumentDisposition.php:19-25].

- **Image lightbox:** make `DocumentActions::preview()` branch on `str_starts_with($record->mime_type, 'image/')`. For images, configure the same action as a modal with no submit and the image minted at render:
  ```php
  ->modalSubmitAction(false)->modalCancelActionLabel(__('documents.actions.close'))
  ->modalWidth(Width::FiveExtraLarge)->modalHeading(fn (Document $record) => $record->name)
  ->modalContent(function (Document $record): View {
      Gate::forUser(self::user())->authorize('view', $record);          // re-check on render
      return view('filament.documents.lightbox', ['src' => DocumentUrl::preview($record, self::user()), 'name' => $record->name]);
  })
  ```
  with `<img src="{{ $src }}" alt="{{ $name }}" style="max-width:100%;max-height:80vh;margin:auto">`. The URL exists only inside the open modal, is minted at click time, is user-bound, and expires in 120 s; no navigation between images. Modal content closures are supported (`modalContent(fn (Contract $record): View => ...)`) [CITED: vendor/filament/actions/docs/02-modals.md:323-350, 505-512].
- **Comment thread** (`RendersTaskCommentThread` + `comment-thread.blade.php`, used by Admin and Partner): the button calls `previewCommentDocument`, which redirects. Add a component-level action `previewCommentImageAction()` (like the existing `deleteCommentDocumentAction()`), invoked with `mountAction('previewCommentImage', { document: '<id>' })`; its `modalContent` re-reads the document through `commentDocument($id)` (scoped query restricted to this task's comments, 404 otherwise) and authorizes `view`. `<x-filament-actions::modals />` is already in the blade.
- **Non-image files in a new window:** today preview/download use `$livewire->redirect(url)`, which replaces the current page. See Pitfall 4 for the mechanism; recommended: after minting at click time, `$livewire->js('window.open('.Js::from($url).', "_blank", "noopener")')`. Cover PDFs (inline) and downloads. [SUPERSEDED: no `window.open` and no new route; the preview of an inline non-image file (PDF) is a real `target="_blank"` link whose signed URL is rendered with the row, the single owner-approved exception; downloads stay attachments at click time. See 17-CONTEXT.md "Non-image files" and "Signed PDF link exception"; 17-02 R-17-02-3.]
- **Surfaces to touch:** `DocumentActions::preview()` serves `ManagesDocuments` (Admin client/project/task/transaction tabs), `ShowsPartnerDocuments` (Partner project/task tabs) and `DocumentResource` (central list), so one change covers all but the comment thread and the invoice table (`InvoiceTable.php:669` is a download; leave it). `Document::mime_type` is the type detected from bytes at upload, never client-declared, so an `image/` prefix test cannot be spoofed to render markup; still intersect with `DocumentDisposition::isInline()` so SVG (`image/svg+xml`) is never rendered.
- The row-click behaviour is not specified; recommended: keep the explicit "Náhled" (preview) action and additionally make the file name open the same preview where `isInline`, optional. [RESOLVED: the explicit "Zobrazit" action only, no name click; 17-CONTEXT.md "Preview trigger".]

### Anti-Patterns to Avoid
- **Rendering a signed file URL into list HTML** (`->url()` on a column/action): breaks the D-10 rule and yields dead links after 120 s. [Owner exception: the inline PDF preview only, with the 403 after 120 s accepted (17-CONTEXT.md "Signed PDF link exception"); still an anti-pattern for downloads, archives and images.]
- **Trusting `$data['project_id']` / `$data['task_id']` when the field is disabled**: disabled fields are not dehydrated; read the context from the record or closure.
- **Trusting client-sent signal text for a picked task**: always rebuild from the Task row server-side.
- **Putting the hide filter in `columns()` only**: it must be in `applyFilters`, otherwise `move()` miscounts the drop index.
- **Reusing one `projectOptions()` for the creation Select and the list filter.**
- **Switching rich text to Filament's `->html()`**: weaker sanitiser, allows `style`/`class`.
- **Bare `withoutGlobalScopes()`** or `DB::table(` in `app/` (Arch scan).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Start/stop timer from the signal row | A second timer implementation | `TaskTimerToggle::handle()` | One shared running timer (D-02); handles stale/race/toasts |
| Time entry creation rules | New validation in the modal | `CreateTimeEntry` Action + `TimeEntryResource::actionData()` | Billed-immutability, context consistency, overlap, duration cap |
| Rich-text sanitising | A second sanitiser or `strip_tags` | `RichText::render()` / `::clean()` | Strict whitelist; Partner isolation relies on it |
| Image lightbox | A JS lightbox library | Filament action modal with `modalContent` | Focus trap, ESC, backdrop, dark mode included, no assets |
| Task numbering/creation | Direct `Task::create` | `CreateTask` Action | Gap-free numbering, locks, people rules |
| Project status write | `$project->update()` in the page | `UpdateProject` Action | Same effect as the edit form, history row |
| Escaped LIKE patterns | Manual string concat into SQL | Bound parameter with an escaped `ILIKE` pattern (or Filament's `generate_search_pattern`) | SQL injection and `%`/`_` wildcards |

**Key insight:** every behaviour in this phase already has a single owner in the codebase; the plan's tasks should be "add a parameter / one branch to the owner", not "add a parallel path".

## Runtime State Inventory

Not a rename/refactor phase, but two schema changes add state. Answered explicitly:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Existing `signal_tasks` rows have no task link; existing `tasks` rows must default to shown | Both columns are nullable/defaulted, no data migration |
| Live service config | None - verified: no external service references signal tasks or the board flag | None |
| OS-registered state | None - verified: no scheduler/launchd entries reference these tables | None |
| Secrets/env vars | None | None |
| Build artifacts | None (no asset build; `public/css/filament` already contains `.fi-prose`) | None. Note: no Vite/theme pipeline exists, so any custom CSS must be inline styles (the Signal blade already uses inline styles) |

## Common Pitfalls

### Pitfall 1: CONTEXT terminal states do not exist
**What goes wrong:** code written for `Completed`/`Cancelled` does not compile or filters nothing. **Why:** the enum has only `Done`. **Avoid:** use `ProjectStatus::Done` everywhere; confirm with the user that no new "Cancelled" status is wanted (it would be a CHECK constraint, enum, label, board column change, i.e. out of scope). **Warning sign:** a `ProjectStatus::Completed` reference.

### Pitfall 2: Pinned Partner-safe column list fails the suite
**What goes wrong:** adding `tasks.hidden_on_board` breaks `tests/Isolation/PartnerSafeColumnsTest.php` ("pins the Partner-safe column list of the tasks table"), which compares `Schema::getColumnListing('tasks')` to an ordered list ending `..., 'escalated_by_id', 'deleted_at', 'created_at', 'updated_at'` [VERIFIED: PartnerSafeColumnsTest.php:50-75]. **Avoid:** update the pin in the same plan, appended AFTER `updated_at` (migration order), and note in the test message that the reviewer decided every Partner may read a display flag. The "suspicious name" regex (`money|rate|price|estimate|billing|internal|note|cost|budget`) does not match `hidden_on_board`. **Also** check `tests/Support/PartnerMatrix.php` / `ChannelMatrix.php` / API resources if they enumerate task attributes (grep showed they reference `SignalTask`, not column lists; verify when implementing).

### Pitfall 3: Disabled fields vanish from `$data`
Applies to the locked project (D-07) and the locked time-entry context (D-06). Merge the context from the record in the action body. Validation errors keyed on a disabled field are invisible: surface them as a `Notification`.

### Pitfall 4: Opening a new window after an async Livewire round trip
A `window.open` issued from a Livewire response is not a synchronous click handler. Chromium and Firefox honour transient user activation for about 5 s (the 120 s link TTL is irrelevant to this), Safari is stricter and may block it. `Action::url()->openUrlInNewTab()` works reliably but requires the URL in the rendered HTML (contradicts the click-time-minting rule). Options, in order of preference: (1) `$livewire->js('window.open(...)')` and add a manual-verification step in Safari/Chrome/Firefox (the config sets `human_verify_mode: end-of-phase`); (2) if blocked in practice, an authenticated, non-signed redirect route `files/documents/{document}/open` that does only a policy check and `redirect()`s to the signed URL, linked as `<a target="_blank" rel="noopener">`, so no signature ever sits in HTML. Route (2) needs a new route in `AdminPanelProvider::authenticatedRoutes` and must stay inside the `spaUrlExceptions(['*/admin/files/*', '*/pay/*'])` pattern. Mark this `[ASSUMED]` until tested in browsers. [RESOLVED: neither option; `Action::url(..., shouldOpenInNewTab: true)` with the signed link in the rendered HTML, accepted by the owner for the inline PDF preview only (17-CONTEXT.md "Signed PDF link exception"; 17-02 R-17-02-3).] **`spa()` mode:** a plain `redirect()` in SPA mode navigates in the same tab; `_blank` links bypass SPA navigation, but keep the `/admin/files/*` exception.

### Pitfall 5: `Task` global scopes and trashed linked tasks in Signal
`Task` has `SoftDeletes` + Partner scope. For the signal listing use `->withTrashed()` on the relation query (an archived linked task must still show the open icon). For the type-ahead use the default scope (archived excluded). A completed task is in the DB, not trashed: filter `status != 'done'` explicitly.

### Pitfall 6: Two `SignalDay` components and stale timer icons
Today and tomorrow are separate Livewire components on one page; the top-bar timer is a third surface. Each must listen to `timer-started`/`timer-stopped`, otherwise a play/stop icon shows stale state. Livewire events dispatched from one component are received by all components on the page.

### Pitfall 7: Search `LIMIT` hides active rows if ordering is applied after
Apply the done-last key inside `getGlobalSearchEloquentQuery()` (before `limit(50)`), not by sorting the collection afterwards; otherwise the 50-row cut can drop active rows in favour of completed ones.

### Pitfall 8: `ViewTask` caches time totals in a private property
After `addTime` the "Worked" figure on the same page is stale until the property is reset; reset it or redirect.

### Pitfall 9: Activity log / allowlist tests
`Task` and `Project` use `LogsAllowlistedActivity` with a reviewed `#[LoggedAttributes]`. Do not add `hidden_on_board` to the allowlist without need; `tests/Arch/ActivityAllowlistTest.php` and the column test (`ActivityAllowlistColumnsTest`) verify the allowlist against real columns, so adding a non-allowlisted column is fine.

## Code Examples

### Enum values the plan must use (verbatim)
```php
// app/Domain/Projects/Enums/ProjectStatus.php:17-22
case Planned = 'planned';
case ToClarify = 'to_clarify';
case InProgress = 'in_progress';
case InReview = 'in_review';
case ReadyToRelease = 'ready_to_release';
case Done = 'done';
```
```php
// lang/cs/enums.php:71-78
'project_status' => [
    'planned' => 'Plánovaný',
    'to_clarify' => 'K upřesnění',
    'in_progress' => 'V realizaci',
    'in_review' => 'Ke kontrole',
    'ready_to_release' => 'K vypuštění',
    'done' => 'Dokončeno',
],
```
The `tasks_status_check` CHECK has the same six values and `tasks_completed_check` ties `done` to `completed_at IS NOT NULL` [VERIFIED: create_tasks_table.php].

### Migration skeleton for the hide flag (follow repo conventions: header comment, uuidv7 default not needed here)
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('hidden_on_board')->default(false);
        });
    }
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void { $table->dropColumn('hidden_on_board'); });
    }
};
```
Optionally a partial index is unnecessary (the board filters ~hundreds of rows). New migrations in this repo are dated `2026_10_DD_NNNNNN_*.php`; the latest is `2026_10_28_000400_make_invoice_item_unit_optional.php` [VERIFIED: ls database/migrations].

### Source of the lightbox pattern
```php
// vendor/filament/actions/docs/02-modals.md:346 (adapt)
->modalContent(fn (Contract $record): View => view('filament.pages.actions.advance', ['record' => $record]))
// :511-512
->modalContent(view('actions.help'))->modalSubmitAction(false)
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Filament 3 `TextEntry->html()` with default sanitiser | `Htmlable` state + `->prose()` (Filament 4/5) | v4 | Keep `RichText::render`, add `prose()` |
| Livewire 3 `$wire` events | Livewire 4 `#[On]` attribute events (already used in `ControlsTimer`) | v4 | Use `#[On('timer-started')]` |
| Filament page `urlExceptions` | `->spaUrlExceptions(...)` (already configured for `/admin/files/*`) | - | Keep when adding any file route |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `{PROJECT}` in `{PROJECT}: {task code} {task name}` means the project name (not the key) | Pattern 8 | Wrong text format on every picked signal; the format is user-visible and "exact" per CONTEXT specifics |
| A2 | D-11 applies to the Partner task create form too (not only the Admin quick create) | Pattern 6 | A Partner could still raise tasks in a Done project, or lose a project they legitimately need |
| A3 | Editing a linked signal keeps the link and allows free retitling | Pattern 8 | Stale "linked" icon on a signal whose text no longer names the task |
| A4 | `$livewire->js('window.open')` after a Livewire round trip is not blocked by popup blockers in the target browsers | Pitfall 4 | Non-image files silently fail to open; fallback route (2) needed |
| A5 | The prose-class gap is the visible cause of "formatting not rendered" (static analysis; no browser run) | Pattern 2 | If a different surface is broken, it remains unfixed; human verification step required |
| A6 | Hiding "New task" for archived projects and "Change status" for archived projects is desired | Patterns 3, 6 | Minor UX divergence |
| A7 | "Active" excludes only `done`; there is no cancelled state | Summary | If the owner wants a Cancelled status it is a separate, larger change |
| A8 | The image test `mime_type LIKE 'image/%'` intersected with `isInline()` is the right "image" definition (png/jpeg/gif/webp) | Pattern 9 | SVG/other image types fall to the new-window path |
| A9 | `ILIKE` with an escaped pattern is acceptable for the type-ahead (no accent-insensitive Czech matching) | Pattern 8 | Users typing without diacritics will not match titles with them |
| A10 | The time-entry modal needs default `started_at`/`ended_at` (planner's choice) | Pattern 7 | Slightly more clicks if left empty |

Resolved after research (17-CONTEXT.md, Plan-phase Clarifications): A1 (project name, with the format changed to `{PROJECT}: {task name} [{task code}]`), A2 (yes), A3 (yes), A4 (moot: no `window.open`; a real link per "Signed PDF link exception"), A7 (only `done`). A6, A9 and A10 are planner rulings listed under "Owner-visible rulings" there.

## Open Questions (RESOLVED)

All six questions were answered after research. The binding answers live in 17-CONTEXT.md, section "Plan-phase Clarifications (resolved after research)"; the text below is kept as the research record.

1. **What is `{PROJECT}`?**
   - What we know: format is `{PROJECT}: {task code} {task name}`; the task code `KEY-N` already starts with the project key.
   - Unclear: project name vs project key.
   - Recommendation: project name; confirm with the owner before planning (blocks the Action's composer and tests).
   - **RESOLVED:** the project name, and the order changed as well: the text is `{PROJECT}: {task name} [{task code}]`, built server-side and truncated to 255 characters. See 17-CONTEXT.md, Plan-phase Clarifications, "Signal text format"; planned in 17-04 R-17-04-3.
2. **Does D-11 also cover the Partner task create form?**
   - Known: both forms use `Project::selectable()`; CONTEXT says "Task creation" with no audience.
   - Recommendation: yes, for consistency (a finished project should not receive new client-raised tasks); apply `->open()` in `PartnerTaskResource::projectOptions()`. Confirm.
   - **RESOLVED:** yes, the Partner create form too. See 17-CONTEXT.md, Plan-phase Clarifications, "D-11 scope"; planned in 17-07 R-17-07-4.
3. **Signal edit of a linked item?** Keep link + free retitle (recommended), or unlink on edit.
   - **RESOLVED:** keep the link, free retitle. See 17-CONTEXT.md, Plan-phase Clarifications, "Editing a linked signal"; planned in 17-04 R-17-04-5.
4. **Non-image open mechanism** (Pitfall 4): accept `window.open` via Livewire with browser verification, or add the small authenticated open-route.
   - **RESOLVED:** neither. A real `<a target="_blank">` link on the existing preview action, with the signed, user-bound PDF link rendered into the page as the single owner-approved exception (the 403 of a stale page after 120 s is accepted). See 17-CONTEXT.md, Plan-phase Clarifications, "Non-image files" and "Signed PDF link exception"; planned in 17-02 R-17-02-3.
5. **Row click / file name click** as the preview trigger is unspecified; recommended to keep the explicit preview action and add name-click optional.
   - **RESOLVED:** keep the explicit "Zobrazit" action as the only trigger; no name or row click. See 17-CONTEXT.md, Plan-phase Clarifications, "Preview trigger"; planned in 17-02 R-17-02-2.
6. **Archived task in the signal listing:** open icon stays, timer hidden (derived from D-01); confirm.
   - **RESOLVED:** confirmed. See 17-CONTEXT.md, Plan-phase Clarifications, "Archived linked task in the signal listing"; planned in 17-06 R-17-06-2.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Pint, local lint | yes (host) | 8.5.12 | - |
| Composer | scripts | yes | - | - |
| DDEV | Tests, migrations, PHPStan (host has no DB) | yes (`/opt/homebrew/bin/ddev`; not started by this research) | - | - |
| Node | none needed (no asset build) | yes (v24) | - | - |
| PostgreSQL | Migrations/tests | via DDEV only | - | Run only after merge into the HEAD branch |

**Missing dependencies with no fallback:** none. Migrations and the full suite run only after the merge (CLAUDE.md), so plans must not include "run migrate" steps inside worktree tasks.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13.3 (+ Livewire/Filament testing) [VERIFIED: composer.json] |
| Config file | `phpunit.xml` / `tests/Pest.php` (suites include `Audit`) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Feature/Signal tests/Feature/Search` |
| Full suite command | `ddev exec vendor/bin/pest --exclude-testsuite=Audit` (composer `test`); static: `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; style: `vendor/bin/pint --test` (host-safe) |

### Phase Requirements -> Test Map
| Ref | Behavior | Test Type | Automated Command | File Exists? |
|-----|----------|-----------|-------------------|-------------|
| P17-1 | Checklist section spans full width | feature (schema inspection) | `pest tests/Feature/Tasks/TaskChecklistTest.php` | extend existing |
| P17-2 | Description entries carry `fi-prose`, markup preserved and sanitised | feature (`assertSeeHtml`) | `pest tests/Feature/Tasks/TaskResourceTest.php`, `RichTextSanitiserTest.php`, `PartnerTaskResourceTest.php` | extend existing |
| P17-3 | New task from project view: locked project, hidden for Done project, redirects to edit | Livewire action test | `pest tests/Feature/Tasks/TaskResourceTest.php` (new cases on `ViewProject`) | partial (Wave 0 for ViewProject) |
| P17-4 | Active first within each category, both Task and Project; completed still present | feature | `pest tests/Feature/Search/GlobalSearchTest.php` | extend existing |
| P17-5 | Add time: pre-filled/locked, creates entry, error surfaced as notification, billed rules intact | Livewire action | `pest tests/Feature/TimeTracking` (new `TaskAddTimeTest.php`) | Wave 0 |
| P17-6 | Hidden task absent from global and project boards, present elsewhere; drop index with hidden neighbours; flag persisted via UpdateTask; Partner cannot set | feature | `pest tests/Feature/Tasks/TaskBoardTest.php` + `TaskUpdateTest.php` | extend existing |
| P17-6b | Pinned columns updated | isolation | `pest tests/Isolation/PartnerSafeColumnsTest.php` | update existing |
| P17-7 | Pick links task; server rebuilds title; forged id/done/archived task refused; free text unlinked; cross clears; timer toggle; open icon; delete task nulls link | Livewire + schema | `pest tests/Feature/Signal` (new `SignalTaskLinkTest.php`) + `tests/Isolation/SignalIsolationTest.php` | Wave 0 |
| P17-8 | Image preview action returns a modal with a signed inline URL; non-image path; Partner only for visible files; forged document id 404 | feature/isolation | `pest tests/Feature/Documents` + `tests/Isolation/DocumentVisibilityTest.php` | extend existing |
| P17-9 | Quick-create options exclude Done; list filter still lists them; Partner form | feature | `pest tests/Feature/Tasks/TaskResourceTest.php`, `PartnerTaskResourceTest.php` | extend existing |
| P17-10 | Status change via action = effect of edit form; hidden when archived; Partner cannot | Livewire action | `pest tests/Feature/Projects` | extend existing |

### Sampling Rate
- **Per task commit (worktree):** `vendor/bin/pint --test` and PHPStan only if it works without the DB.
- **Per wave merge (HEAD branch, DDEV):** the quick run command above.
- **Phase gate:** full suite + PHPStan green in DDEV before `/gsd-verify-work`; manual browser checks for the lightbox, popup behaviour (Safari/Chrome/Firefox), prose styling in light/dark, type-ahead usability.

### Wave 0 Gaps
- [ ] `tests/Feature/Signal/SignalTaskLinkTest.php` - covers P17-7
- [ ] `tests/Feature/TimeTracking/TaskAddTimeTest.php` - covers P17-5
- [ ] Project view action tests (new file under `tests/Feature/Projects`) - P17-3, P17-10
- [ ] Update `tests/Isolation/PartnerSafeColumnsTest.php` pinned list (same plan as the migration)
- Framework install: none.

## Security Domain

`security_enforcement` is enabled (ASVS level 1, block on high) [VERIFIED: .planning/config.json].

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (unchanged) | existing Filament auth + MFA middleware |
| V3 Session Management | no | existing |
| V4 Access Control | yes | Policies + Partner global scope; `abort_unless(Resource::canAccess(), 403)` in every new action body (`/livewire/update` is public); `RequiresAdmin` on `SignalDay`; signed + user-bound file route; `Gate::authorize('view')` on every lightbox render |
| V5 Input Validation | yes | UUID checks (`Str::isUuid`) on every client-controlled id (picked task, signal id, document id); server-side title composition; escaped `ILIKE` pattern; `RichText::clean/render` |
| V6 Cryptography | no (signed URLs use Laravel's signer; do not hand-roll) | `URL::temporarySignedRoute` via `DocumentUrl` |
| V12 Files | yes | Only `INLINE_MIME_TYPES` rendered; `X-Content-Type-Options: nosniff`; never render SVG |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Forged Livewire update picking another user's / archived / done task | Tampering / EoP | Re-read the Task server-side in `AddSignalTask`; ignore client text |
| IDOR via signal id in `toggleTaskTimer` | Info disclosure | Resolve through owner-scoped `SignalTask::query()`; take `task_id` from the DB row |
| Stored XSS via rich text | Tampering | `RichText::render` whitelist; `fi-prose` is CSS only; do not use `->html()` |
| Signed file URL leakage in page source | Info disclosure | URL minted only inside the modal render, 120 s TTL, bound to `u` = user id |
| Uploaded active content rendered inline | Tampering | Allowlist + `nosniff`; lightbox limited to `isInline()` images |
| Partner reading admin-only data via new column | Info disclosure | `hidden_on_board` is a non-sensitive display flag; pinned column test updated deliberately |
| LIKE wildcard / injection in type-ahead | Tampering | Bound parameter with escaped pattern |
| Partner creating a task in a Done project (A2) | Tampering (business rule) | Option filter only; `CreateTask` project lookup remains the authority |

## Sources

### Primary (HIGH confidence) - read this session
- `.planning/phases/17-task-project-and-search-ux-refinements/17-CONTEXT.md`
- `app/Filament/Resources/TaskResource.php`, `.../Pages/ViewTask.php`, `.../Pages/EditTask.php`
- `app/Filament/Resources/ProjectResource.php`, `.../Pages/ViewProject.php`, `EditProject.php`, `ProjectBoard.php`, `ProjectTasksTimeRelationManager.php`
- `app/Filament/Resources/TimeEntryResource.php`, `.../Pages/CreateTimeEntry.php`; `app/Domain/TimeTracking/Actions/CreateTimeEntry.php`; `Queries/EntryContextOptions.php`
- `app/Domain/Tasks/Actions/CreateTask.php`, `UpdateTask.php`; `Board/TaskBoard.php`; `Models/Task.php`; `Policies/TaskPolicy.php`
- `app/Domain/Projects/Enums/ProjectStatus.php`, `Models/Project.php`, `Actions/UpdateProject.php`; `lang/cs/enums.php`
- `app/Domain/Signal/**` (model, Actions, reader), `app/Livewire/Signal/*`, `resources/views/livewire/signal/day.blade.php`, signal planner migration
- `app/Filament/Support/TaskTimerToggle.php`, `DocumentActions.php`, `DocumentColumns.php`, `TaskColumns.php`; `app/Filament/Concerns/*` (ManagesTaskBoard, ManagesDocuments, ShowsPartnerDocuments, RendersTaskCommentThread); `app/Domain/Documents/Support/DocumentDisposition.php`, `DocumentUrl.php`; `app/Http/Controllers/DocumentFileController.php`; `app/Providers/Filament/AdminPanelProvider.php`
- `app/Domain/Shared/Text/RichText.php`; `resources/views/filament/tasks/comment-thread.blade.php`, `filament/pages/partials/task-preview.blade.php`
- `vendor/filament/filament/src/Resources/Resource/Concerns/HasGlobalSearch.php`; `vendor/filament/infolists/src/Components/TextEntry.php`, `Concerns/CanFormatState.php`; `vendor/filament/actions/docs/02-modals.md`; `public/css/filament/filament/app.css`
- `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Arch/QueryEscapeHatchTest.php`, `tests/Arch/LivewireComponentContractTest.php`, `tests/Feature/Search/GlobalSearchTest.php`, `tests/Feature/Tasks/TaskResourceTest.php`
- `config/kokpit.php` (signed URL TTL), `.planning/config.json`, `composer.json`

### Secondary / Tertiary
- None. No external web sources were needed; all behaviour is defined by in-repo code and the installed Filament/Livewire sources. The MCP research seam was not used because no external library or API is introduced. Knowledge graph not consulted.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - no new packages, versions read from `installed.json`.
- Architecture: HIGH - every seam read from source.
- Pitfalls: HIGH for codebase pitfalls (2, 3, 5, 7); MEDIUM for browser behaviour (4) and the visual diagnosis (A5).

**Research date:** 2026-10-29
**Valid until:** 2026-11-28 (stable; revisit if Filament is upgraded past 5.11.x before planning)
