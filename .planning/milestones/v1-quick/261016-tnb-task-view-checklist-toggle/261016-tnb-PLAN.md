---
phase: quick-261016-tnb
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
  - resources/views/filament/tasks/checklist.blade.php
  - lang/cs/kokpit.php
  - tests/Feature/Tasks/TaskChecklistTest.php
  - CONTRIBUTING.md
  - tests/Feature/Repo/RepositoryFilesTest.php
autonomous: true
requirements: [TA-03]

estimate:
  tokens: 55000
  raw_tokens: 55000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On the Admin task page /admin/tasks/KEY-N (ViewTask, also for a subtask), the Admin sees a section headed 'Kontrolní seznam' (kokpit.tasks.checklist.heading). It lists every checklist item of the task in position order. Each item is a checkbox with its text as the label, and the checkbox shows the stored done state. The section is not rendered for a task with no items"
    - "Clicking a checkbox on the task page ticks or unticks that one item right away, with no edit page and no save button. The task_checklist_items row's is_done becomes the clicked target state. The 'Hotovo v kontrolním seznamu' progress (done/total) on the same page shows the new count in the same response. Repeating a click with the same target state changes nothing. The item text and position are never changed from the task page"
    - "Adding, editing, reordering and removing items stays on the edit page (the existing repeater). The edit-page checklist tests in TaskChecklistTest pass unchanged"
    - "An archived task's checklist is read-only on the task page: every checkbox is disabled. A forged toggle call is refused with the danger notification 'kokpit.tasks.errors.checklist_archived', and the row is unchanged"
    - "A forged toggle naming an item of another task, or an id that is not a UUID, answers 404 and changes no row"
    - "A Partner never sees or changes a checklist item. /admin/tasks/KEY-N answers 403 and its body has no item text. A toggle call on a page mounted by the Admin, made after the session switched to a Partner, answers 403. ToggleTaskChecklistItem run as a Partner throws ModelNotFoundException. The Partner's own page /admin/my-tasks/KEY-N contains neither the item text nor 'Kontrolní seznam'. The item is still not done afterwards"
    - "`ddev composer ci` is green and `scripts/check-sensitive.sh` passes over every touched file"
  artifacts:
    - path: "app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php"
      provides: "The only writer of a checklist item's done flag from the task page: UUID check, task re-read under a shared lock, item lookup restricted to that task, the update ability on the item, refusal for an archived task"
      contains: "final class ToggleTaskChecklistItem"
    - path: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      provides: "The checklist section of the task page and the public Livewire method toggleChecklistItem(itemId, done), guarded by TaskResource::canAccess()"
      contains: "public function toggleChecklistItem(string $itemId, bool $done): void"
    - path: "resources/views/filament/tasks/checklist.blade.php"
      provides: "The checkbox list of the task page; escaped item text, target-state wire:click, disabled when read-only"
      contains: "toggleChecklistItem("
    - path: "tests/Feature/Tasks/TaskChecklistTest.php"
      provides: "Task-page toggle, empty-section, forged-id, archived read-only and Partner refusal proofs"
  key_links:
    - from: "resources/views/filament/tasks/checklist.blade.php"
      to: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      via: "each checkbox calls the page method with the item id and the target state"
      pattern: "wire:click=\"toggleChecklistItem\\("
    - from: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      to: "app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php"
      via: "toggleChecklistItem() hands the signed-in Admin, the page record, the id and the target state to the Action"
      pattern: "app\\(ToggleTaskChecklistItem::class\\)->handle"
    - from: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      to: "resources/views/filament/tasks/checklist.blade.php"
      via: "checklistSection() renders the view through a Filament\\Schemas\\Components\\View component"
      pattern: "View::make\\('filament.tasks.checklist'\\)"
---

<objective>
Show the task's "Kontrolní seznam" (the Admin-only checklist, TA-03) on the Admin task page /admin/tasks/KEY-N (the Filament ViewTask page of TaskResource), and let the Admin tick and untick items there with one click. Today the checklist is visible and editable only on the edit page (a Repeater in TaskResource::form). The task page shows only the done/total progress.

Purpose: the Admin reads a task on its page and wants to check off work there, without opening the edit form and saving the whole task.
Output: the domain Action `ToggleTaskChecklistItem`, a checklist section and a guarded `toggleChecklistItem()` method on ViewTask, the Blade view `filament.tasks.checklist`, one Czech error key, tests in the existing `tests/Feature/Tasks/TaskChecklistTest.php`, and a CONTRIBUTING note.

Scope decisions (Claude's discretion, no CONTEXT.md exists for this quick task):
- The task page only toggles. Adding, editing, reordering and removing items stays on the edit page. The repeater there is unchanged.
- The write goes through a new domain Action, as every other task write does (ClearEscalation, ToggleSignalTaskDone). The page method is only glue. The page passes the target state, not "flip", so a double click or a stale page cannot flip an item back.
- The section is shown only when the task has at least one item. This matches the existing progress entry, which is also hidden for an empty checklist.
- An archived task is read-only, like everywhere else on TaskResource (canEdit is false for a trashed task). Its checkboxes render disabled, and the Action refuses a toggle.
- How the checklist is guarded today, which this plan keeps: TaskResource carries `AccessRule(Audience::AdminOnly)`, so a Partner gets 403 on /admin/tasks/*. TaskChecklistItem uses DeniesPartners (a Partner query returns zero rows) and AdminOnlyPolicy. The Partner task page (ViewPartnerTask, built from the pinned TaskColumns::partner* builders) is not touched. Because /livewire/update is a public endpoint and Filament authorizes the page only on mount, the new public method checks `TaskResource::canAccess()` itself. The Action checks the `update` ability on the item again.
- The section lives in ViewTask::infolist(), not in TaskResource::infolist(), so the board preview and other shared surfaces stay as they are.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@app/Filament/Resources/TaskResource/Pages/ViewTask.php
@app/Filament/Resources/TaskResource.php
@app/Domain/Tasks/Models/TaskChecklistItem.php
@app/Domain/Tasks/Actions/ClearEscalation.php
@app/Filament/Concerns/RendersTaskCommentThread.php
@resources/views/filament/tasks/comment-thread.blade.php
@app/Filament/Support/TaskColumns.php
@tests/Feature/Tasks/TaskChecklistTest.php

Facts the executor can rely on (verified while planning):
- Filament v5.10, Livewire v4.4. Tests run only in DDEV (`ddev exec ...`), because the host has no DB.
- `Task::checklistItems()` is a HasMany ordered by position, then id. TaskChecklistItem has fillable text, is_done and position, and casts is_done to bool. It is not activity-logged.
- `TaskColumns::checklistProgress(Task)` returns 'done/total', or null when the task has no item. It caches `checklist_items_count` and `checklist_done_count` on the record's attributes and only loads them when they are missing. `TaskColumns::checklistCounts()` is the withCount spec. The progress entry `checklist_progress` (label kokpit.tasks.checklist.progress) is already part of the task page through `TaskColumns::adminEntries()`.
- ViewTask::infolist() already appends the Admin-only sections `effectiveBillingSection()` and `timeSection()`, each with `->visible(static fn (): bool => app(PartnerContext::class)->isAdmin())`. ViewTask::clearEscalationAction() shows the house pattern for a refusal: `abort_unless(TaskResource::canAccess(), 403)`, the domain Action, and a caught ValidationException shown as `Notification::make()->danger()->title(first error)->send()`.
- RendersTaskCommentThread shows the house pattern for a Blade list with per-row page calls. A `Filament\Schemas\Components\View::make('filament.tasks.comment-thread')->viewData(fn (): array => [...])` renders the list. Its buttons use `wire:click="method('{{ $id }}')"`. The public method rejects a non-UUID or foreign id with 404 (`Str::isUuid`, then a lookup restricted to the owner).
- Arch rules: every governed Filament class needs `#[AccessRule]`, but resource pages such as ViewTask are governed by their Resource, and a domain Action is not under app/Filament. QueryEscapeHatchTest forbids a bare `withoutGlobalScopes()`, removing PartnerScope and `DB::table()` in app/. `withTrashed()` is allowed.
- `kokpit.tasks.checklist.heading` = 'Kontrolní seznam' and `kokpit.tasks.checklist.progress` = 'Hotovo v kontrolním seznamu' already exist in lang/cs/kokpit.php. Only lang/cs exists for kokpit.php.
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: End-to-end "Admin ticks a checklist item on the task page" — Action, page method, section, view</name>
  <files>app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php, resources/views/filament/tasks/checklist.blade.php, tests/Feature/Tasks/TaskChecklistTest.php</files>
  <behavior>
    - The task page of a task with items 'Example item 1' and 'Example item 2' (both open) shows the heading 'Kontrolní seznam', both texts, and markup containing toggleChecklistItem
    - call('toggleChecklistItem', first id, true) leaves the rows [['Example item 1', true], ['Example item 2', false]] and the page shows '1/2'
    - Repeating call('toggleChecklistItem', first id, true) leaves the rows unchanged (target state, not a flip)
    - call('toggleChecklistItem', first id, false) leaves both rows open and the page shows '0/2'
    - The task page of a task with no item does not show 'Kontrolní seznam' (assertDontSeeText on the heading)
    - A subtask's task page lists and toggles the subtask's own items, and the parent's rows stay unchanged
  </behavior>
  <action>
RED first. Add the behavior cases to tests/Feature/Tasks/TaskChecklistTest.php as new `it()` blocks. Reuse the file's helpers (taskChecklistProject, taskChecklistTask, taskChecklistFill, taskChecklistRows) and its beforeEach, which runs on the admin panel as `Canary::admin()`. Pest functions are global, so give any new helper the `taskChecklist` prefix and a name no other test file uses. Find an item through `TaskChecklistItem::query()->where('task_id', ...)->orderBy('position')`. Drive the page with `Livewire::test(ViewTask::class, ['record' => $task->reference])` and `->call('toggleChecklistItem', $id, true|false)`. Use only fictional 'Example ...' texts. Run the file and see the new cases fail.

GREEN.
(a) Create app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php as `final class ToggleTaskChecklistItem`, with `public function handle(User $actor, Task $task, string $itemId, bool $done): TaskChecklistItem`. Write a docblock in the ClearEscalation house style that explains each rule. The rules, in this order:
1. If `Str::isUuid($itemId)` is false, throw `(new ModelNotFoundException)->setModel(TaskChecklistItem::class, [$itemId])`. A non-UUID must never reach the PostgreSQL uuid column.
2. Inside `DB::transaction`, re-read the task with `Task::query()->withTrashed()->whereKey($task->getKey())->sharedLock()->firstOrFail()`. FOR SHARE is enough because the task row is only read. It still makes a concurrent ArchiveTask (FOR UPDATE) wait.
3. Find the item only among that task's items: `TaskChecklistItem::query()->whereKey($itemId)->where('task_id', $locked->getKey())->lockForUpdate()->firstOrFail()`. Another task's item and every item for a Partner (DeniesPartners) resolve to ModelNotFoundException.
4. Call `Gate::forUser($actor)->authorize('update', $item)` (AdminOnlyPolicy).
5. If `$locked->trashed()`, throw `ValidationException::withMessages(['checklist' => __('kokpit.tasks.errors.checklist_archived')])`. Task 2 adds the Czech text of this key. Do not add the key here.
6. Only when `$item->is_done !== $done`, set `$item->is_done = $done` and save. Return the item.
Mark the method `@throws ValidationException` for an archived task. Do not touch text or position.

(b) In app/Filament/Resources/TaskResource/Pages/ViewTask.php:
- Add a private `checklistSection(): Section`. It is `Section::make(__('kokpit.tasks.checklist.heading'))->columnSpanFull()`. Its `->visible()` closure takes `Task $record` and returns `app(PartnerContext::class)->isAdmin() && TaskColumns::checklistProgress($record) !== null`. The Admin check comes first, as in the billing and time sections, so nothing is read for anybody else. The counts are the ones the progress entry already caches, so there is no extra query. The section's schema is one `Filament\Schemas\Components\View::make('filament.tasks.checklist')`. Its `->viewData(fn (): array => [...])` closure reads `$this->getRecord()` (assert it is a Task) and passes `items` (`$task->checklistItems()->get()`) and `readOnly` (`$task->trashed()`).
- In infolist(), insert `$this->checklistSection()` after the resource components and before `$this->effectiveBillingSection()`.
- Add `public function toggleChecklistItem(string $itemId, bool $done): void` with a docblock. The docblock says /livewire/update is public and the page authorizes only on mount, so the method checks access itself, and the id is client-controlled. The body:
  1. `abort_unless(TaskResource::canAccess(), 403)`.
  2. Get the Task from `$this->getRecord()` and the User from `auth()->user()`, with asserts as in clearEscalationAction.
  3. Call `app(ToggleTaskChecklistItem::class)->handle($actor, $task, $itemId, $done)`. Catch ModelNotFoundException and turn it into `abort(404)`. Catch ValidationException and send the danger Notification with the first message, then return.
  4. On success, call `$task->loadCount(TaskColumns::checklistCounts())`. The progress entry then shows the new done/total in the same response.
  Add no public property. Everything public travels in the snapshot.

(c) Create resources/views/filament/tasks/checklist.blade.php. Start it with a Blade comment in the comment-thread.blade.php style. The comment says this is the Admin-only checklist of the task page, that items are only ticked here (added and edited on the edit page), and that each click sends the target state. Use the inline-style approach of comment-thread.blade.php, not new Tailwind classes. Use a list (`ul` with list-style none, flex column, gap 0.5rem). Each `li` gets `wire:key="task-checklist-item-{{ $item->id }}-{{ $item->is_done ? 'done' : 'open' }}"`, so a changed state re-renders the row from the server. Inside it, a `label` (flex, gap 0.75rem) wraps:
- an `x-filament::input.checkbox` with `:checked="$item->is_done"`, `:disabled="$readOnly"`, `wire:loading.attr="disabled"` and `wire:click="toggleChecklistItem('{{ $item->id }}', {{ $item->is_done ? 'false' : 'true' }})"`;
- a `span` with the text through double braces only.
A done item's span also gets `text-decoration: line-through; color: var(--gray-500);`. Colour is not the only signal: the checkbox state and the strike-through also show it. Spacing values are rem multiples of 0.25.

Run the test file again and make it pass, together with the existing TaskChecklistTest and TaskResourceTest cases.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskChecklistTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/SubtasksTest.php && ddev exec vendor/bin/pint --test app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php app/Filament/Resources/TaskResource/Pages/ViewTask.php tests/Feature/Tasks/TaskChecklistTest.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'wire:click="toggleChecklistItem(' resources/views/filament/tasks/checklist.blade.php && grep -q "View::make('filament.tasks.checklist')" app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'app(ToggleTaskChecklistItem::class)->handle' app/Filament/Resources/TaskResource/Pages/ViewTask.php</automated>
  </verify>
  <done>On /admin/tasks/KEY-N the Admin sees the 'Kontrolní seznam' section with one checkbox per item (none for an empty checklist). A click persists the target state through ToggleTaskChecklistItem, and the done/total progress updates in the same response. The new and the existing checklist tests, pint and phpstan are green.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Archived read-only state, forged-id refusals and Partner proofs</name>
  <files>lang/cs/kokpit.php, tests/Feature/Tasks/TaskChecklistTest.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php, resources/views/filament/tasks/checklist.blade.php, app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php</files>
  <behavior>
    - Before archiving, the `<input ...>` tag holding an item's id in the page html has no standalone `disabled` attribute. After `app(ArchiveTask::class)->handle($this->admin, $task)` the same tag has one, and the item text is still shown
    - On the archived task's page, call('toggleChecklistItem', id, true) gives assertNotified(__('kokpit.tasks.errors.checklist_archived')) and the row stays open
    - On a task's page, call('toggleChecklistItem', id of another task's item, true) gives assertNotFound(), and both tasks' rows are unchanged. call('toggleChecklistItem', 'not-a-uuid', true) on a fresh page also gives assertNotFound()
    - Partner (Canary::partnerFor of a client-visible project's client), item text Canary::canary('checklist'): mount the page as Admin, then actingAs(partner), then call('toggleChecklistItem', id, true) gives assertForbidden()
    - As that Partner, GET /admin/tasks/KEY-N gives 403 and the body has no canary. ToggleTaskChecklistItem::handle(partner, task, id, true) throws ModelNotFoundException. GET /admin/my-tasks/KEY-N gives 200, and the body contains neither the canary nor 'Kontrolní seznam'
    - Back as the Admin, taskChecklistRows(task) is [[canary, false]]
  </behavior>
  <action>
RED first. Add one `it()` per behavior bullet group to tests/Feature/Tasks/TaskChecklistTest.php:
- archived read-only and refusal;
- foreign id and malformed id;
- Partner refusals.
For the disabled check, add a helper `taskChecklistInputTag(string $html, string $itemId): string`. It uses preg_match to return the first `<input\b[^>]*>` tag that contains the item id, with the id passed through preg_quote. Assert that tag against the pattern `/\sdisabled(?=[\s=>\/])/`. That pattern does not match the `wire:loading.attr` value. Read the html with `$page->html()`. Use a fresh `Livewire::test()` per refused call, because an aborted call ends that test instance. In the Partner test, create the project with `taskChecklistProject('PRT', true)` and the Partner with `Canary::partnerFor($project->client_id)`. Switch back with `$this->actingAs($this->admin)` before reading rows, because a Partner's query returns zero checklist rows. A missing key makes `__()` return the raw key on both sides of assertNotified, so that assertion alone cannot fail. The archived case therefore also asserts `expect(__('kokpit.tasks.errors.checklist_archived'))->not->toBe('kokpit.tasks.errors.checklist_archived')`. Run the file and see that assertion fail. Fix any other failure in the code from Task 1, not in the test.

GREEN. In lang/cs/kokpit.php, under `kokpit.tasks.errors`, add after 'description_not_editable':
'checklist_archived' => 'Úkol je archivovaný, kontrolní seznam nelze měnit. Nejdřív úkol obnovte.'
If any refusal still fails, adjust ToggleTaskChecklistItem, ViewTask::toggleChecklistItem or the view. The rules stay as written in Task 1:
- 403 only from the page guard;
- 404 for a non-UUID or a foreign id;
- the archived refusal as a ValidationException shown as the danger notification;
- the disabled attribute driven by `readOnly`.
Then run the isolation and neighbour suites listed in verify. They must pass unchanged. In particular, PartnerTaskVisibilityTest pins the Partner surface, and TaskBoardTest pins that the board preview shows no checklist item.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskChecklistTest.php tests/Feature/Tasks/TaskArchiveTest.php tests/Feature/Tasks/TaskBoardTest.php tests/Feature/Tasks/TaskEscalationTest.php tests/Isolation/PartnerTaskVisibilityTest.php tests/Isolation/NotificationLeakTest.php tests/Isolation/CanaryRegistryTest.php tests/Arch && ddev exec vendor/bin/pint --test app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php app/Filament/Resources/TaskResource/Pages/ViewTask.php tests/Feature/Tasks/TaskChecklistTest.php lang/cs/kokpit.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q "'checklist_archived' =>" lang/cs/kokpit.php && grep -q 'assertForbidden' tests/Feature/Tasks/TaskChecklistTest.php && grep -q 'ModelNotFoundException' tests/Feature/Tasks/TaskChecklistTest.php && ! grep -q '{!!' resources/views/filament/tasks/checklist.blade.php</automated>
  </verify>
  <done>An archived task's checklist renders disabled, and a forged toggle is refused with the Czech notice. A foreign or malformed id answers 404. A Partner is refused on the page (403 on GET and on the Livewire call) and in the Action (ModelNotFoundException), and never sees the item or the heading on the own task page. The isolation, archive, board and arch suites pass unchanged.</done>
</task>

<task type="auto">
  <name>Task 3: Document the task-page toggle and run the full gate</name>
  <files>CONTRIBUTING.md, tests/Feature/Repo/RepositoryFilesTest.php</files>
  <action>
In CONTRIBUTING.md, add a bullet `- **Task checklist.**` right after the bullet that starts with `- **Partner writes on tasks.**`. Match the tone and length of its neighbours. It says the following:
- The checklist of a task (TaskChecklistItem, Admin-only through DeniesPartners and AdminOnlyPolicy) is filled in, edited, reordered and emptied only on the edit page, through the repeater bound to `Task::checklistItems()`.
- On the task page (ViewTask) the Admin can only tick or untick an item, and only through the ToggleTaskChecklistItem Action.
- The Action takes the target state, so a double click is harmless.
- It re-reads the task under a shared row lock and finds the item only among that task's items, so an unknown, malformed or foreign id is a 404.
- It checks the update ability on the item, and it refuses an archived task, whose checklist is read-only.
- The page method checks `TaskResource::canAccess()` itself, because /livewire/update is public.
- `tests/Feature/Tasks/TaskChecklistTest.php` enforces it.
In tests/Feature/Repo/RepositoryFilesTest.php, in the `$names` map of the test that checks the classes CONTRIBUTING.md names (the map that already lists 'TaskDueDate'), add `'ToggleTaskChecklistItem' => 'App\Domain\Tasks\Actions\ToggleTaskChecklistItem'` after the TaskDueDate line.
Then run the full gate `ddev composer ci` on its own, not in parallel with another ddev command. Run `scripts/check-sensitive.sh` over every file this plan touched, so only fictional data is present. Do not bypass the lefthook pre-commit hook.
  </action>
  <verify>
    <automated>grep -q 'ToggleTaskChecklistItem' CONTRIBUTING.md && grep -q "'ToggleTaskChecklistItem' => 'App" tests/Feature/Repo/RepositoryFilesTest.php && ddev composer ci && scripts/check-sensitive.sh app/Domain/Tasks/Actions/ToggleTaskChecklistItem.php app/Filament/Resources/TaskResource/Pages/ViewTask.php resources/views/filament/tasks/checklist.blade.php lang/cs/kokpit.php tests/Feature/Tasks/TaskChecklistTest.php CONTRIBUTING.md tests/Feature/Repo/RepositoryFilesTest.php</automated>
  </verify>
  <done>CONTRIBUTING.md documents where the checklist is edited and where it is ticked. RepositoryFilesTest pins the documented Action name. `ddev composer ci` (pest, pint, phpstan, licenses) is green, and the sensitive-data checker passes over every touched file.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → /livewire/update (ViewTask) | The method name, the item id and the target state are client-controlled. The endpoint is public, and Filament authorizes the page only on mount |
| Admin panel page → Partner session | The same panel serves Admin and Partner. The Admin task page must never act for, or render to, a Partner |
| item text → HTML | Admin-written checklist text is rendered into the page |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261016-tnb-01 | Elevation of Privilege | ViewTask::toggleChecklistItem | high | mitigate | `abort_unless(TaskResource::canAccess(), 403)` is the first statement (AccessRule AdminOnly). The Action authorizes `update` on the item again (AdminOnlyPolicy), and DeniesPartners makes a Partner's item lookup empty. Proven by the Task 2 Partner test: the call on an Admin-mounted page after switching to a Partner gives 403, and the Action as a Partner throws ModelNotFoundException |
| T-261016-tnb-02 | Tampering | ToggleTaskChecklistItem item lookup | high | mitigate | A non-UUID id is refused before any query. The item is looked up with `where('task_id', locked task id)`, so another task's or another client's item resolves to a 404. Proven by the foreign-id and malformed-id tests |
| T-261016-tnb-03 | Tampering | Archived task checklist | medium | mitigate | The Action re-reads the task FOR SHARE inside the transaction and refuses a trashed task with a ValidationException. The view disables the checkboxes. Proven by the archived test |
| T-261016-tnb-04 | Information Disclosure | Checklist text on Partner surfaces | high | mitigate | The section is only in ViewTask (AdminOnly resource), and its visible closure checks `PartnerContext::isAdmin()` before reading. ViewPartnerTask and the pinned TaskColumns::partner* builders are untouched. The Task 2 test asserts /admin/tasks is 403 with no canary and /admin/my-tasks/KEY-N shows neither the canary nor the heading. PartnerTaskVisibilityTest stays green |
| T-261016-tnb-05 | Tampering (XSS) | filament.tasks.checklist view | medium | mitigate | Item text is output only through double-brace escaping. The ids in wire:click are UUIDs from the database. Task 2 verify fails if the view contains an unescaped echo |
| T-261016-tnb-06 | Denial of Service / race | Double click, stale page | low | mitigate | The page sends the target state, not a flip, and the Action writes only when the state differs. `wire:loading.attr` disables the checkbox while a request is in flight. Item row FOR UPDATE inside the transaction |
| T-261016-tnb-07 | Information Disclosure | Test fixtures, docs, planning files | low | mitigate | Fictional 'Example …' texts and Canary strings only. `scripts/check-sensitive.sh` over every touched file plus the lefthook gitleaks hook |
| T-261016-tnb-SC | Tampering | npm/pip/cargo/composer installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskChecklistTest.php tests/Isolation/PartnerTaskVisibilityTest.php` is green
- `ddev composer ci` is green (pest, pint, phpstan, licenses)
- `scripts/check-sensitive.sh` passes over every touched file
- Human check (end of phase, not blocking): open /admin/tasks/KEY-N of a task with a checklist in the browser. Tick an item: the checkbox stays ticked, the text is struck through, and the 'Hotovo v kontrolním seznamu' count rises. Reload the page: the state is kept. Open an archived task: its checkboxes are greyed out
</verification>

<success_criteria>
- The Admin task page shows the checklist and toggles items in one click. The state persists, and the progress updates in the same response
- The edit page checklist behaves exactly as before
- Archived tasks are read-only. Forged ids give 404. Partners get 403 on the page and the call, nothing from the Action, and nothing on their own page
- Full CI gate and sensitive-data check are green
</success_criteria>

<output>
Create `.planning/quick/261016-tnb-task-view-checklist-toggle/261016-tnb-SUMMARY.md` when done
</output>
