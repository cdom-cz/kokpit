---
phase: quick-261015-diz
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Filament/Support/TaskDueDate.php
  - resources/views/filament/tasks/due-date-cell.blade.php
  - app/Filament/Support/TaskColumns.php
  - app/Filament/Resources/TaskResource.php
  - tests/Feature/Tasks/TaskListOverdueTest.php
  - app/Filament/Widgets/UpcomingDeadlines.php
  - resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
  - CONTRIBUTING.md
  - tests/Feature/Repo/RepositoryFilesTest.php
  - .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md
files_deleted:
  - .planning/todos/pending/2026-10-15-add-overdue-highlight-to-tasks-list.md
autonomous: true
requirements: [TA-05, RE-04]

estimate:
  tokens: 75000
  raw_tokens: 75000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On the Admin task list (/admin/tasks) an open task is highlighted when its due day is before today in Prague. Open means any status except done, and not archived. The highlight is the due date in danger text, the 'Po termínu' badge with the warning triangle, and the line 'Po termínu o N den/dny/dní' with Czech plurals. The markup and the copy are the ones the 'Nejbližší termíny' widget shows"
    - "On the list, a task due today reads 'Dnes' in semibold with no alarm colour. A task due later shows its date as plain 'j. n. Y'. A task without a due date shows the empty value '—'. A done task and an archived task never get the badge, the danger colour or the overdue line, even when their due day has passed"
    - "The day boundary is the Prague day. At 00:30 in Prague, a task due the day before is overdue by one day. At 23:30 in Prague, a task due that day reads 'Dnes'"
    - "There is one source. The due-date cell exists once, as the view filament.tasks.due-date-cell, and both the widget and the list build their due column through TaskDueDate::column(). The Czech copy keys kokpit.dashboard.deadlines.today, .overdue_badge and .overdue_days are referenced from that one view only. lang/cs/kokpit.php gets no new key. The widget renders as before: every existing deadlines test in AdminDashboardTest passes unchanged"
    - "The Partner task list (/admin/my-tasks) is unchanged. It shows a plain date for an overdue task, with no 'Po termínu' and no danger colour. TaskColumns::partnerColumns() and PARTNER_COLUMN_NAMES are untouched, and the Partner due_date column is still a TextColumn"
    - "Sorting the list by the due date column still works in both directions. The default order (priority, nearest due date, id) is unchanged. `ddev composer ci` is green"
  artifacts:
    - path: "app/Filament/Support/TaskDueDate.php"
      provides: "The one home of the Prague today, the overdue-day math, the open-task rule and the shared due ViewColumn builder"
      contains: "function overdueDaysOf"
    - path: "resources/views/filament/tasks/due-date-cell.blade.php"
      provides: "The single due-date cell (Dnes / date / danger date + badge + overdue line / empty value) shared by the widget and the list"
      contains: "kokpit.dashboard.deadlines.overdue_badge"
    - path: "tests/Feature/Tasks/TaskListOverdueTest.php"
      provides: "List highlight, open-task rule, Prague boundary, sort, and Partner-unchanged proofs"
  key_links:
    - from: "app/Filament/Support/TaskColumns.php"
      to: "app/Filament/Support/TaskDueDate.php"
      via: "adminColumns() builds the due_date column with TaskDueDate::column(TaskDueDate::LIST_PADDING)"
      pattern: "TaskDueDate::column\\(TaskDueDate::LIST_PADDING\\)"
    - from: "app/Filament/Widgets/UpcomingDeadlines.php"
      to: "app/Filament/Support/TaskDueDate.php"
      via: "table() builds the due_date column with TaskDueDate::column(TaskDueDate::CARD_PADDING)"
      pattern: "TaskDueDate::column\\(TaskDueDate::CARD_PADDING\\)"
    - from: "resources/views/filament/tasks/due-date-cell.blade.php"
      to: "app/Filament/Support/TaskDueDate.php"
      via: "the cell asks TaskDueDate::overdueDaysOf($task, $today) whether and by how much the row is overdue"
      pattern: "TaskDueDate::overdueDaysOf"
---

<objective>
Show the overdue highlight of the "Nejbližší termíny" dashboard widget on the Admin task list (/admin/tasks). The list and the widget render the due date through one shared cell view and one column helper, so the markup and the Czech copy exist once.

Purpose: an overdue open task looks like every other row on the task list today. The owner wants the same three signals the widget gives (danger date, "Po termínu" badge with the warning triangle, "Po termínu o N dnech"), with no second copy that could drift.
Output: `App\Filament\Support\TaskDueDate`, the view `filament.tasks.due-date-cell`, the Admin list and the widget both wired to them, tests in `tests/Feature/Tasks/TaskListOverdueTest.php`, a CONTRIBUTING note, and the todo moved to completed.

Scope decisions (Claude's discretion, no CONTEXT.md exists for this quick task):
- The list's due cell is the widget's due cell. So a task due today reads "Dnes" on the list too, in the same way as on the widget. One view means one rendering.
- "Open" is the widget's rule: status is not done, and the task is not archived. ProjectStatus has no cancelled case, so done is the only closed status. An archived task can be listed through the trashed filter, but it never counts as overdue, just as the widget never shows one.
- The copy is reused from the existing keys under kokpit.dashboard.deadlines (today, overdue_badge, overdue_days). No key is added or duplicated.
- The Partner list keeps its own TextColumn from TaskColumns::partnerColumns(). The Partner gets no new information and sees no visual change.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@.planning/todos/pending/2026-10-15-add-overdue-highlight-to-tasks-list.md
@app/Filament/Widgets/UpcomingDeadlines.php
@resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
@app/Filament/Support/TaskColumns.php
@app/Filament/Resources/TaskResource.php

<interfaces>
These are the existing facts the executor builds on. They were read from the code on 2026-10-15, after quick tasks 261015-cdq and 261015-cv5.

- The widget computes today as `app(TimesheetQuery::class)->today(TimerClock::now())`, which returns the Prague day `Y-m-d`. The imports are `App\Domain\TimeTracking\Queries\TimesheetQuery` and `App\Domain\TimeTracking\Support\TimerClock`. It is used in `table()` and in `collapsedSummary()`.
- `UpcomingDeadlines::overdueDays(string $due, string $today): int` is public static. It returns the whole days a due day lies before today, and 0 for today or later. It uses `CarbonImmutable::createFromFormat('!Y-m-d', ..., 'UTC')` and `diffInDays`. Its only caller is the widget cell view.
- The widget's columns are `ViewColumn::make('task')` and `ViewColumn::make('due_date')`. Both use the view `filament.widgets.upcoming-deadlines-cell` with viewData `['kind' => 'task'|'due', 'today' => $today]`. The due label is `__('kokpit.dashboard.deadlines.columns.due')`.
- In `upcoming-deadlines-cell.blade.php`, the `@else` branch (the 'due' kind) holds the markup to share. The wrapper is a grid div styled `display: grid; justify-items: start; gap: 0.25rem; padding: 0.5rem 1rem; white-space: nowrap; font-variant-numeric: tabular-nums;`. Inside it, a due day equal to today shows `__('kokpit.dashboard.deadlines.today')` in a span at font-weight 600. Any other day shows the date span, formatted with `LocalisationServiceProvider::DATE_FORMAT` and carrying `color: var(--danger-600);` when days > 0. When days > 0, two more elements follow: `<x-filament::badge color="danger" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">` with `__('kokpit.dashboard.deadlines.overdue_badge')`, and a 0.875rem danger span with `trans_choice('kokpit.dashboard.deadlines.overdue_days', $days, ['count' => $days])`.
- `TaskColumns::adminColumns()` is documented `@return list<TextColumn>`. Its due column is `TextColumn::make('due_date')->label(__('kokpit.tasks.fields.due_date'))->date()->placeholder(__('kokpit.tasks.empty_value'))->sortable()`. `TaskResource::columns()` (private) splices `parent.reference` into that list and is also documented `@return list<TextColumn>`.
- `ProjectStatus` has the cases planned, to_clarify, in_progress, in_review, ready_to_release and done. `Task` uses SoftDeletes, casts status to ProjectStatus and due_date to a date.
- Filament is v5. `Filament\Tables\Columns\ViewColumn extends Column`, and `viewData(array|Closure)` is supported. A ViewColumn renders no placeholder by itself. Filament styles an empty cell as `<p class="fi-ta-placeholder">` (gray text-sm, see vendor/filament/tables/resources/css/cell.css). Text cells are padded `px-3 py-4`, which is 1rem block and 0.75rem inline (vendor/filament/tables/resources/css/columns/text.css).
- Tests: AdminDashboardTest freezes time with `$this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'))`. Its deadline tests assert '13. 10. 2026', 'Po termínu o 1 den', 'Po termínu o 3 dny', 'Po termínu o 5 dní', 'Dnes', and exactly three `fi-color-danger` in the widget HTML. On the task list the archive record action is also `danger`, so a list test must NOT count `fi-color-danger`.
- List test idioms are in tests/Feature/Tasks/TaskListFiltersTest.php. They use `Livewire::test(ListTasks::class)`, `->filterTable('status', ['done'])`, `->sortTable('priority')` and `->assertCanSeeTableRecords([...], inOrder: true)`. tests/Feature/Tasks/TaskArchiveTest.php uses `->filterTable('trashed', false)`, which lists only archived tasks. The Partner idioms are in tests/Feature/Tasks/PartnerTaskResourceTest.php: `Canary::admin()`, `Canary::twoClients()`, `Canary::partnerFor($client)`, a project created in `app(PartnerContext::class)->runAsSystem(...)` with `'client_visible' => true`, and `$this->get('/admin/my-tasks')`.
</interfaces>
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: Tracer: the Admin task list shows the overdue highlight through a shared due-date cell</name>
  <files>app/Filament/Support/TaskDueDate.php, resources/views/filament/tasks/due-date-cell.blade.php, app/Filament/Support/TaskColumns.php, app/Filament/Resources/TaskResource.php, tests/Feature/Tasks/TaskListOverdueTest.php</files>
  <behavior>
    - With time frozen at 2026-10-14 08:00 UTC, the ListTasks HTML has five open tasks due 2026-10-13, 2026-10-11, 2026-10-09, 2026-10-14 and 2026-10-16. Its text contains 'Po termínu o 1 den', 'Po termínu o 3 dny', 'Po termínu o 5 dní', 'Dnes' and '16. 10. 2026'. 'Po termínu o' occurs exactly 3 times, and 'Po termínu' (badge plus line) exactly 6 times. `var(--danger-600)` occurs exactly 6 times in the HTML (date span plus line span per overdue row)
    - An open task due today or later has no 'Po termínu' and no `var(--danger-600)`
    - A done task due 2026-10-01 (shown with filterTable('status', ['done'])) shows '1. 10. 2026' with no 'Po termínu' and no `var(--danger-600)`
    - An archived open task due 2026-10-01 (shown with filterTable('trashed', false)) shows '1. 10. 2026' with no 'Po termínu' and no `var(--danger-600)`
    - An open task without a due date renders the empty value '—' in the due cell and no alarm
    - At 2026-10-14 22:30 UTC (00:30 Prague on the 15th), a task due 2026-10-14 shows 'Po termínu o 1 den'. At 2026-10-14 21:30 UTC (23:30 Prague) the same task shows 'Dnes' and no 'Po termínu'
    - sortTable('due_date') lists three dated tasks nearest first. sortTable('due_date', 'desc') lists them in the reverse order
    - TaskDueDate::overdueDaysOf returns 0 for a task without a due date, a done task, an archived task, a task due today and a task due later, and N for an open task due N days before today. TaskDueDate::overdueDays keeps the widget's semantics: 0 for an equal or later day, whole days otherwise
  </behavior>
  <action>
RED first. Create tests/Feature/Tasks/TaskListOverdueTest.php. Follow the setup of tests/Feature/Tasks/TaskListFiltersTest.php: admin panel, `Canary::admin()`, actingAs, a project written through CreateProject, and tasks through `Task::factory()->create([...])`. In beforeEach, freeze time with `$this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'))`. Give every helper function a unique prefix such as `taskOverdue...`, because Pest functions are global. Add a local text helper modelled on `adminDashText` in tests/Feature/Dashboard/AdminDashboardTest.php: strip svg, comments and scripts, then tags, then collapse whitespace. Do not call the dashboard helper, which is only defined when that file loads. Write one `it()` per behavior bullet. Count text occurrences and `var(--danger-600)`. Never count `fi-color-danger` on the list, because the archive action is danger too. Use only fictional names ('Example ...') and the fixed 2026-10 dates. Run the file and see it fail.

GREEN.
(a) Create app/Filament/Support/TaskDueDate.php as `final class TaskDueDate` with:
- `public const string VIEW = 'filament.tasks.due-date-cell'`;
- `public const string CARD_PADDING = '0.5rem 1rem'` (the widget's existing spacing);
- `public const string LIST_PADDING = '1rem 0.75rem'` (Filament's own text-cell spacing, so the cell lines up with its neighbours; both are rem multiples of 0.25 per UI-SPEC F-2);
- `public static function today(): string`, the Prague day through `app(TimesheetQuery::class)->today(TimerClock::now())`, so it respects travelTo;
- a public static method `overdueDays(string $due, string $today): int`, a verbatim copy of the widget's math. Task 2 deletes the widget's copy and points the widget here;
- a public static method `overdueDaysOf(Task $task, string $today): int`. It returns 0 when due_date is null, when status is `ProjectStatus::Done`, or when `$task->trashed()`. Otherwise it returns `self::overdueDays($task->due_date->format('Y-m-d'), $today)`. This is the open-task rule: the widget only ever lists not-done, not-archived tasks, and it now has one home. It reads only loaded attributes, so there is no query per row;
- `public static function column(string $padding): ViewColumn`, which returns `ViewColumn::make('due_date')->view(self::VIEW)->viewData(['today' => self::today(), 'padding' => $padding])`. Today is computed once per table build. The caller adds the label and, on the list, `->sortable()`.
Give the class a docblock in the house style. It says this is the one due-date cell of the widget and the Admin list, that "open" means not done and not archived, and that the Prague day is the boundary. It also says no Partner builder may use it.

(b) Create resources/views/filament/tasks/due-date-cell.blade.php. Copy the widget cell's `@else` branch markup (the due kind) as-is, so Task 2 can delete the original with no visual change. Make three changes to the copy:
- the wrapper's `padding: 0.5rem 1rem;` becomes `padding: {{ $padding }};`;
- the days come from `TaskDueDate::overdueDaysOf($task, $today)` with `$task = $getRecord()`;
- before the dated branch, add a branch for a null due date. It renders the same padded wrapper holding `<p class="fi-ta-placeholder">{{ __('kokpit.tasks.empty_value') }}</p>`.
Keep the same translation keys, the badge component with `color="danger" size="sm"` and `Heroicon::OutlinedExclamationTriangle`, `LocalisationServiceProvider::DATE_FORMAT`, and `var(--danger-600)` on the date span and the line span. Every value goes through double braces. Write a header comment in the style of the widget cell's comment. It says the cell is shared by "Nejbližší termíny" and the Admin task list, that colour is never the only carrier, and that the caller passes `today` and `padding`.

(c) In app/Filament/Support/TaskColumns.php, change only the due_date entry of `adminColumns()` to `TaskDueDate::column(TaskDueDate::LIST_PADDING)->label(__('kokpit.tasks.fields.due_date'))->sortable()`. Drop `->date()` and `->placeholder()`, because the cell now renders both. Widen the return docblock to `list<Column>` and import `Filament\Tables\Columns\Column`. Do NOT touch `partnerColumns()`, `partnerEntries()`, `adminEntries()` or the PARTNER_* constants, because the Partner view must stay unchanged.

(d) In app/Filament/Resources/TaskResource.php, widen the docblock of the private `columns()` to `@return list<Column>` and import `Filament\Tables\Columns\Column`. Make no other change. The default sort, the filters and the actions stay as they are.

Run the tests one ddev command at a time. Make them green. Then run pint and phpstan on the touched PHP.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskListOverdueTest.php tests/Feature/Tasks/TaskListFiltersTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskArchiveTest.php tests/Feature/Tasks/TaskChecklistTest.php tests/Feature/Tasks/SubtasksTest.php tests/Isolation/PartnerTaskVisibilityTest.php && ddev exec vendor/bin/pint --test app/Filament/Support app/Filament/Resources/TaskResource.php tests/Feature/Tasks/TaskListOverdueTest.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Filament/Support app/Filament/Resources/TaskResource.php && grep -q 'TaskDueDate::column(TaskDueDate::LIST_PADDING)' app/Filament/Support/TaskColumns.php && grep -q 'TaskDueDate::overdueDaysOf' resources/views/filament/tasks/due-date-cell.blade.php && grep -q 'fi-ta-placeholder' resources/views/filament/tasks/due-date-cell.blade.php</automated>
  </verify>
  <done>/admin/tasks shows the danger date, the "Po termínu" badge and the "Po termínu o N …" line only for open, not archived tasks whose due day is before the Prague today. Today reads "Dnes", later days show a plain date and an undated task shows "—". Sorting by the due column works in both directions. The Partner builders are untouched. All listed tests, pint and phpstan pass. Committed.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: The widget renders its due date through the same cell, and the Partner list is proven unchanged</name>
  <files>app/Filament/Widgets/UpcomingDeadlines.php, resources/views/filament/widgets/upcoming-deadlines-cell.blade.php, tests/Feature/Tasks/TaskListOverdueTest.php</files>
  <behavior>
    - Every existing deadlines test in tests/Feature/Dashboard/AdminDashboardTest.php passes unchanged. That includes three `fi-color-danger` for three overdue rows, 'Dnes' for today, the plain later date and no alarm for today or later
    - The deadline copy key kokpit.dashboard.deadlines.overdue_badge appears in exactly one file under resources/views and app (the shared cell)
    - Partner A of a client-visible project opens /admin/my-tasks while an open task of that project is due 2026-10-11, with time frozen at 2026-10-14. The body contains the task title and '11. 10. 2026', and contains neither 'Po termínu' nor 'var(--danger-600)'
    - The Partner list's due_date column is a `Filament\Tables\Columns\TextColumn` (read from the ListPartnerTasks table instance). TaskColumns::partnerColumns() names still equal PARTNER_COLUMN_NAMES
  </behavior>
  <action>
Partner guard first. Add a `describe('the Partner task list', ...)` block to tests/Feature/Tasks/TaskListOverdueTest.php. Copy the idioms from tests/Feature/Tasks/PartnerTaskResourceTest.php: `Canary::twoClients()`, `Canary::partnerFor($clientA)`, a client-visible project created through CreateProject inside `app(PartnerContext::class)->runAsSystem(...)` with `Canary::canary(...)` and `Canary::projectKey()`, and the overdue task through `Task::factory()` in a system run with `'due_date' => '2026-10-11'` and an open status. Sign in as the Partner with `$this->actingAs(...)` and assert the four behavior bullets about the Partner. The first assertion runs on the `/admin/my-tasks` body. The column type assertion reads `Livewire::test(ListPartnerTasks::class)->instance()->getTable()->getColumn('due_date')`. These tests must pass before AND after this task. They pin that the Partner gains nothing.

Then move the widget onto the shared cell. In app/Filament/Widgets/UpcomingDeadlines.php:
- build the due column as `TaskDueDate::column(TaskDueDate::CARD_PADDING)->label(__('kokpit.dashboard.deadlines.columns.due'))`;
- give the task column no viewData. It renders the task cell only, so it needs neither the kind nor today;
- replace both `app(TimesheetQuery::class)->today(TimerClock::now())` calls (in table() and collapsedSummary()) with `TaskDueDate::today()`, and drop the imports that are now unused;
- delete the widget's own public static overdue-day helper method. Its math now lives in TaskDueDate, and its one caller, the cell view's due branch, is removed below;
- update the class docblock: the due cell is the shared `filament.tasks.due-date-cell` (TaskDueDate), also used by the Admin task list.
In resources/views/filament/widgets/upcoming-deadlines-cell.blade.php, delete the due branch and the kind switch so the file renders only the task cell. Remove the now-unused `use` lines (the widget class, LocalisationServiceProvider, Heroicon) and rewrite the header comment so it describes the task cell only and points to the shared due cell. Do not change the task cell markup: the link, the ellipsis, the client and project line, and the padding all stay. Run the dashboard and list suites one ddev command at a time until green, then run pint and phpstan.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Dashboard/AdminDashboardTest.php tests/Feature/Dashboard/CollapsibleWidgetsTest.php tests/Feature/Tasks/TaskListOverdueTest.php tests/Feature/Tasks/PartnerTaskResourceTest.php tests/Isolation/PartnerTaskVisibilityTest.php tests/Isolation/TimeLeakTest.php tests/Isolation/PanelAccessTest.php && ddev exec vendor/bin/pint --test app/Filament/Widgets/UpcomingDeadlines.php tests/Feature/Tasks/TaskListOverdueTest.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Filament/Widgets/UpcomingDeadlines.php app/Filament/Support && grep -q 'TaskDueDate::column(TaskDueDate::CARD_PADDING)' app/Filament/Widgets/UpcomingDeadlines.php && ! grep -q 'function overdueDays' app/Filament/Widgets/UpcomingDeadlines.php && test "$(grep -rl 'deadlines.overdue_badge' resources/views app | wc -l | tr -d ' ')" = 1</automated>
  </verify>
  <done>The widget and the list render the due date from the single view filament.tasks.due-date-cell, built by TaskDueDate::column(). The widget's own overdue code and its due branch are gone, and the widget looks exactly as before (AdminDashboardTest unchanged and green). The Partner list is proven to show a plain date with no highlight. Committed.</done>
</task>

<task type="auto">
  <name>Task 3: Document the shared due cell, pin it, run the full gate, and close the todo</name>
  <files>CONTRIBUTING.md, tests/Feature/Repo/RepositoryFilesTest.php, .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md</files>
  <action>
In CONTRIBUTING.md, append one sentence to the existing **Dashboard.** bullet, after the `x-dashboard-card` / `CollapsibleDashboardWidget` sentences and before the closing line about the test files. Say this: the due-date cell of `UpcomingDeadlines` and of the Admin task list is one view (`filament.tasks.due-date-cell`) built by `TaskDueDate::column()`. An open task, meaning not done and not archived, whose due day is before today in Prague (`TaskDueDate::overdueDaysOf()`) gets the danger date, the "Po termínu" badge and the "Po termínu o …" line. The Partner builders never use it, and the Partner list keeps its plain date. Name `tests/Feature/Tasks/TaskListOverdueTest.php` as the enforcing test.

In tests/Feature/Repo/RepositoryFilesTest.php, add `'TaskDueDate' => 'App\Filament\Support\TaskDueDate'` to the class map of the test "only names Phase 8 classes in CONTRIBUTING.md that exist in app/". Put it next to the dashboard entries and follow the existing quoting.

Run the full gate with `ddev composer ci`, alone and not in parallel with another ddev command. Then run `scripts/check-sensitive.sh` over every file this plan touched (the list in the verify command), so only fictional data is present.

Finally, move the todo with `git mv .planning/todos/pending/2026-10-15-add-overdue-highlight-to-tasks-list.md .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md`. Commit it with this task's changes after the review procedure in CLAUDE.md: git status, git diff --staged, then scripts/check-sensitive.sh. Never bypass the lefthook hook.
  </action>
  <verify>
    <automated>grep -q 'TaskDueDate' CONTRIBUTING.md && grep -q 'filament.tasks.due-date-cell' CONTRIBUTING.md && grep -q "'TaskDueDate' => 'App" tests/Feature/Repo/RepositoryFilesTest.php && ddev composer ci && scripts/check-sensitive.sh app/Filament/Support/TaskDueDate.php resources/views/filament/tasks/due-date-cell.blade.php app/Filament/Support/TaskColumns.php app/Filament/Resources/TaskResource.php app/Filament/Widgets/UpcomingDeadlines.php resources/views/filament/widgets/upcoming-deadlines-cell.blade.php tests/Feature/Tasks/TaskListOverdueTest.php CONTRIBUTING.md tests/Feature/Repo/RepositoryFilesTest.php .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md && test -f .planning/todos/completed/2026-10-15-add-overdue-highlight-to-tasks-list.md && test ! -e .planning/todos/pending/2026-10-15-add-overdue-highlight-to-tasks-list.md</automated>
  </verify>
  <done>CONTRIBUTING names the shared due cell and TaskDueDate, and RepositoryFilesTest pins the class. `ddev composer ci` is green, check-sensitive is clean on every touched file, and the todo is in .planning/todos/completed/ via git mv. Committed.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Admin surfaces to Partner session | The Partner works in the same panel (/admin/my-tasks). The shared cell must never reach a Partner surface |
| DB task values to HTML | Task titles and due dates are rendered into the cell markup |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261015-diz-01 | Information Disclosure | Shared due cell on a Partner surface | medium | mitigate | The cell is built only from `TaskColumns::adminColumns()` (TaskResource, `AccessRule(AdminOnly)`) and `UpcomingDeadlines` (`AccessRule(AdminOnly)`, refused at boot). `partnerColumns()` and the PARTNER_* pins are untouched (PartnerTaskVisibilityTest). The new Partner test in TaskListOverdueTest asserts /admin/my-tasks has no 'Po termínu', no danger colour, and a TextColumn due column |
| T-261015-diz-02 | Tampering (XSS) | filament.tasks.due-date-cell | low | mitigate | Every value goes through double-brace escaping. The padding comes from class constants, never from input, and the date is formatted from a cast attribute |
| T-261015-diz-03 | Denial of Service | Per-row overdue check on the list | low | mitigate | `overdueDaysOf()` reads only loaded attributes (due_date, status, deleted_at), so there is no query per row. Today is computed once per table build in `TaskDueDate::column()` |
| T-261015-diz-04 | Information Disclosure | Test fixtures, docs, planning files | low | mitigate | Fictional 'Example …' names, Canary strings and fixed 2026-10 dates. `scripts/check-sensitive.sh` runs over every touched file, plus the lefthook gitleaks hook |
| T-261015-diz-SC | Tampering | npm/pip/cargo/composer installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev composer ci` is green: pint, phpstan and the full Pest suite.
- On /admin/tasks with time frozen at 2026-10-14, an open task due 2026-10-11 shows the danger date, the "Po termínu" badge and "Po termínu o 3 dny". A done or archived task with a past due date shows a plain date.
- The deadlines widget output is unchanged: AdminDashboardTest passes with no edits.
- /admin/my-tasks as a Partner shows a plain date for the same overdue task.
- `grep -rl 'deadlines.overdue_badge' resources/views app` lists exactly one file.
</verification>

<success_criteria>
- The overdue highlight is on the Admin task list, with the widget's markup and Czech copy, from one shared view and one helper (TaskDueDate).
- Only open (not done, not archived) tasks count as overdue, on the Prague day.
- The Partner task list and its pinned builders are unchanged.
- The todo is moved to completed. CI and the sensitive-data check are green.
</success_criteria>

<output>
Create `.planning/quick/261015-diz-add-overdue-highlight-to-the-admin-tasks/261015-diz-SUMMARY.md` when done
</output>
