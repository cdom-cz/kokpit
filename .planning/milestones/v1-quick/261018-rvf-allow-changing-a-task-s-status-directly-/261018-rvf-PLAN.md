---
phase: quick-261018-rvf
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
  - lang/cs/kokpit.php
  - tests/Feature/Tasks/TaskUpdateTest.php
autonomous: true
requirements: [TA-01]

estimate:
  tokens: 45000
  raw_tokens: 45000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "On the Admin task page /admin/tasks/KEY-N (ViewTask, also for a subtask), the 'Stav' entry shows a link 'Změnit stav' (kokpit.tasks.actions.change_status) next to its label. Clicking it opens a small modal with one required select of the six task statuses, prefilled with the current status"
    - "Submitting the modal changes the task's status right away, with no trip to the edit page. The write goes through UpdateTask with the status only, so the task moves to the end of the new status column, completed_at is set for Done and cleared otherwise, the activity log gets the status change, and the Partner requester and assignee are notified exactly as when the status is saved on the edit page. The page shows the success notice 'Stav úkolu byl změněn' and the page record holds the new status in the same response"
    - "An unknown status value in the modal is an error under the select, and the row is unchanged"
    - "An archived task shows no 'Změnit stav' link (it is read-only until restored, like the edit action)"
    - "A Partner gains nothing: /admin/tasks/KEY-N stays 403; on an Admin-mounted page after the session switched to a Partner the action is hidden; UpdateTask with only a status, run as a Partner of the own client, throws AuthorizationException; the Partner's own page /admin/my-tasks/KEY-N has no 'Změnit stav'; TaskPolicy is not changed and no new ability exists"
    - "The edit page status field and the board drag-and-drop behave exactly as before"
    - "`ddev composer ci` is green and `scripts/check-sensitive.sh` passes over every touched file"
  artifacts:
    - path: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      provides: "The private changeStatusAction() hint action on the 'status' infolist entry, guarded by TaskResource::canEdit() for visibility and TaskResource::canAccess() in the body, writing through UpdateTask"
      contains: "Action::make('changeStatus')"
    - path: "lang/cs/kokpit.php"
      provides: "Czech labels: tasks.actions.change_status, change_status_heading, change_status_submit and tasks.notifications.status_changed"
      contains: "'change_status' =>"
    - path: "tests/Feature/Tasks/TaskUpdateTest.php"
      provides: "Task-page status change, invalid value, archived and Partner proofs"
      contains: "changeStatus"
  key_links:
    - from: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      to: "app/Domain/Tasks/Actions/UpdateTask.php"
      via: "the action body hands the signed-in Admin, the page record and ['status' => value] to UpdateTask"
      pattern: "app\\(UpdateTask::class\\)->handle"
    - from: "app/Filament/Resources/TaskResource/Pages/ViewTask.php infolist()"
      to: "the 'status' TextEntry of TaskColumns::adminEntries()"
      via: "the loop over the resource components attaches the hint action to the entry named 'status'"
      pattern: "hintAction\\(\\$this->changeStatusAction\\(\\)\\)"
---

<objective>
Let the Admin change a task's status directly on the task page /admin/tasks/KEY-N (the Filament ViewTask page of TaskResource), without opening the edit form and saving the whole task.

Interpretation of the request (Claude's discretion, no CONTEXT.md exists for this quick task). The owner wrote, in Czech, "change the status in its view, not only in the detail". Today a task's status can be changed only in the edit form (TaskResource::form, the 'status' select) and by dragging a card on the kanban board. The Czech Filament breadcrumb and action label of the view page is "Zobrazit", so "its view" (jeho zobrazení) is read as the read-only task page ViewTask, and "the detail" as the edit form where the status field lives today. The task list (ListTasks) keeps its status badge, column sort and status filter unchanged, and the board preview slide-over ("Náhled úkolu") is not touched. Both are out of scope.

Purpose: the Admin reads a task on its page and wants to move it to the next state there in two clicks.
Output: one hint action on the status entry of ViewTask, four Czech strings, tests in the existing tests/Feature/Tasks/TaskUpdateTest.php.

Scope decisions:
- UI shape: a link-style hint action 'Změnit stav' on the 'Stav' entry that opens a small modal with one status select. This is the house pattern for a single-field write on a view page: ViewInvoice::editNoteAction() is a `->link()` hint action with a one-field modal on the internal note entry. It keeps the control next to the value it changes, and it keeps the page's header actions (timer, edit, archive, restore, clear escalation) unchanged.
- The write goes through the existing UpdateTask Action with the status key only, not through a new Action and not through MoveTask. UpdateTask already authorizes `update`, takes the board lock and the row lock, appends the task to the end of the new column through TaskBoard::appendToColumn, sets or clears completed_at, writes one activity row, and notifies the Partner side once. The CONTRIBUTING "Board" rule (only CreateTask, UpdateTask, ArchiveTask, RestoreTask and MoveTask write status or position) therefore stays true, and no documentation changes.
- Partner restrictions are kept by authorization, not by hiding. ViewTask belongs to TaskResource, which carries `AccessRule(Audience::AdminOnly)`, so a Partner gets 403 on /admin/tasks/*. The action's visibility is TaskResource::canEdit() (policy `update`, denied to every Partner by KokpitPolicy::update, plus not archived). The body re-checks TaskResource::canAccess(), because /livewire/update is public and Filament authorizes the page only on mount. UpdateTask authorizes `update` again. TaskPolicy, PartnerTaskResource and ViewPartnerTask are not touched, so the Partner keeps exactly the writes listed in CONTRIBUTING "Partner writes on tasks".
- The hint action is attached inside ViewTask::infolist() (where the escalation entry is already injected next to priority), not in TaskColumns::adminEntries() or TaskResource::infolist(), so no shared builder changes.
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
@app/Domain/Tasks/Actions/UpdateTask.php
@app/Domain/Tasks/Policies/TaskPolicy.php
@tests/Feature/Tasks/TaskUpdateTest.php

Facts the executor can rely on (verified while planning):
- Filament v5.10. Tests run only in DDEV (`ddev exec ...`), because the host has no DB.
- ViewTask::infolist() loops over `parent::infolist($schema)->getComponents(withHidden: true)` and already inserts `$this->escalationEntry()` after the TextEntry named 'priority'. The resource infolist starts with TaskColumns::adminEntries(), which has exactly one top-level `TextEntry::make('status')->label(__('kokpit.tasks.fields.status'))->badge()`.
- House pattern for a single-field hint action on a view page: app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php, `notesSection()` (`->hintAction($this->editNoteAction())`) and `editNoteAction()` (`->link()`, modalHeading, modalSubmitActionLabel, `fillForm`, `schema([...])`, body with `abort_unless(InvoiceResource::canAccess(), 403)`, a ValidationException re-thrown as `ValidationException::withMessages(['mountedActions.0.data.internal_note' => ...])`). Its tests in tests/Feature/Invoicing/InvoiceDetailTest.php call it with `TestAction::make('editNote')->schemaComponent('internal_note', schema: 'infolist')` (`Filament\Actions\Testing\TestAction`).
- ViewTask::clearEscalationAction() shows the house pattern of this page: `abort_unless(TaskResource::canAccess(), 403)`, `$this->getRecord()` asserted to be a Task, `auth()->user()` asserted to be a User, then the domain Action.
- `TaskResource::canEdit(Model $record)` is public static and is `parent::canEdit($record) && ! trashed`. `TaskResource::enumValue(mixed $state): ?string` is public static; a Filament select over an enum may hand back the enum case, so the value must be normalised through it.
- UpdateTask::handle(User $actor, Task $task, array $data) with `['status' => 'in_progress']` changes only the status: `TaskInput::status()` refuses an unknown value with a ValidationException keyed 'status' (message kokpit.tasks.errors.status_invalid); a status equal to the stored one writes nothing. It re-reads the row with the default soft-delete scope, so an archived task is not found.
- The edit form's status select is `Select::make('status')->label(__('kokpit.tasks.fields.status'))->options(ProjectStatus::class)->required()->native(false)`. ProjectStatus has six cases: planned, to_clarify, in_progress, in_review, ready_to_release, done.
- Tests that change a status end with `DB::statement('SET CONSTRAINTS ALL IMMEDIATE');` so the deferred position exclusion constraint is checked inside the test transaction (see the existing TaskUpdateTest cases).
- TaskUpdateTest's beforeEach runs on the admin panel as `Canary::admin()` and helpers `taskUpdProject(string $key = 'UPD', bool $visible = false)` and `taskUpdTask(Project $project, array $data = [])` exist. Pest functions are global: a new helper needs the `taskUpd` prefix and a name no other test file uses.
- Only lang/cs has kokpit.php. `kokpit.tasks.actions` currently ends with 'clear_escalation' => 'Zrušit eskalaci'; `kokpit.tasks.notifications` starts with 'archived' and 'restored'.
- The Partner task page pins its header actions to ['editDescription', 'escalate', 'clearEscalation'] in tests/Feature/Tasks/TaskEscalationTest.php and tests/Isolation/PartnerTaskVisibilityTest.php; both must pass unchanged.
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: End-to-end "Admin changes the status on the task page" — hint action, Czech labels, happy-path tests</name>
  <files>app/Filament/Resources/TaskResource/Pages/ViewTask.php, lang/cs/kokpit.php, tests/Feature/Tasks/TaskUpdateTest.php</files>
  <behavior>
    - On the task page of project 'STA' with two tasks already in 'in_progress' and a third task in 'planned', calling the hint action on the third task's page with ['status' => 'in_progress'] gives no action errors, assertNotified(__('kokpit.tasks.notifications.status_changed')), and the row has status InProgress, position 2 and completed_at null; the page record (`$page->instance()->getRecord()->status`) is InProgress in the same test instance
    - Calling it with ['status' => 'done'] sets completed_at; calling it again with ['status' => 'to_clarify'] clears completed_at
    - Mounting the action shows the modal heading text and the status select prefilled with the current status (assertSchemaStateSet or assertMountedActionModalSee on the heading)
    - The task page html contains 'Změnit stav', and `__('kokpit.tasks.actions.change_status')` and `__('kokpit.tasks.notifications.status_changed')` are not their raw keys (a missing key makes `__()` return the key on both sides of an assertion)
  </behavior>
  <action>
RED first. In tests/Feature/Tasks/TaskUpdateTest.php add `use Filament\Actions\Testing\TestAction;` and a helper `taskUpdStatusAction(): TestAction` returning `TestAction::make('changeStatus')->schemaComponent('status', schema: 'infolist')`, the same addressing InvoiceDetailTest uses for its editNote hint action. Add the behavior cases as new `it()` blocks after the existing status tests. Build the tasks with `taskUpdProject('STA')` and `taskUpdTask(...)`, drive the page with `Livewire::test(ViewTask::class, ['record' => $task->reference])->callAction(taskUpdStatusAction(), ['status' => ...])`, read the row with `$task->refresh()`, and end every case that changes a status with `DB::statement('SET CONSTRAINTS ALL IMMEDIATE');`. Use only fictional 'Example ...' titles. Run the file and see the new cases fail.

GREEN.
(a) lang/cs/kokpit.php: under `kokpit.tasks.actions`, after 'clear_escalation', add 'change_status' => 'Změnit stav', 'change_status_heading' => 'Změnit stav úkolu', 'change_status_submit' => 'Uložit stav'. Under `kokpit.tasks.notifications`, after 'restored', add 'status_changed' => 'Stav úkolu byl změněn'.

(b) app/Filament/Resources/TaskResource/Pages/ViewTask.php:
- In infolist(), inside the existing loop and before the component is appended, attach the action to the status entry: when the component is a TextEntry whose getName() is 'status', call `$component->hintAction($this->changeStatusAction())`. Keep the escalation insertion after 'priority' exactly as it is.
- Add a private `changeStatusAction(): Action` with a docblock in the house style. The docblock says: the Admin changes the status on the task page with the same effect as saving it on the edit page; the write is UpdateTask with the status only (board lock, end of the new column, completed_at, one history row, the Partner side told once); the link is shown only while TaskResource::canEdit() holds, so never on an archived task and never for a Partner; the body re-checks TaskResource::canAccess() because /livewire/update is public. Build it as `Action::make('changeStatus')` with `->label(__('kokpit.tasks.actions.change_status'))`, `->link()`, `->modalHeading(__('kokpit.tasks.actions.change_status_heading'))`, `->modalSubmitActionLabel(__('kokpit.tasks.actions.change_status_submit'))`, `->successNotificationTitle(__('kokpit.tasks.notifications.status_changed'))`, `->visible(fn (): bool => $this->getRecord() instanceof Task && TaskResource::canEdit($this->getRecord()))`, `->fillForm(...)` returning `['status' => <the record's status value>]`, and `->schema([...])` with one `Select::make('status')` configured exactly like the edit form's status select (label kokpit.tasks.fields.status, options ProjectStatus::class, required, native(false)).
- The `->action(function (array $data, Action $action): void { ... })` body: `abort_unless(TaskResource::canAccess(), 403)`; get the Task from `$this->getRecord()` and the User from `auth()->user()` with asserts as in clearEscalationAction; normalise the value with `TaskResource::enumValue($data['status'] ?? null)` and, when that is null, throw `ValidationException::withMessages(['mountedActions.0.data.status' => __('kokpit.tasks.errors.status_invalid')])`; call `app(UpdateTask::class)->handle($actor, $task, ['status' => $status])`; catch ValidationException and re-throw it as `ValidationException::withMessages(['mountedActions.0.data.status' => $e->errors()['status'] ?? [first message]])` so the error shows under the select; on success call `$task->refresh()` so the badge and the record show the new status, then `$action->success()`.
- Add the imports App\Domain\Projects\Enums\ProjectStatus, App\Domain\Tasks\Actions\UpdateTask and Filament\Forms\Components\Select. Add no public property; everything public travels in the Livewire snapshot.
- Do not touch TaskResource, TaskColumns, EditTask, TaskPolicy or any Partner class.

Run the test file again and make it pass together with TaskResourceTest and TaskEscalationTest.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskUpdateTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskEscalationTest.php && ddev exec vendor/bin/pint --test app/Filament/Resources/TaskResource/Pages/ViewTask.php tests/Feature/Tasks/TaskUpdateTest.php lang/cs/kokpit.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q "Action::make('changeStatus')" app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'app(UpdateTask::class)->handle' app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q "'change_status' =>" lang/cs/kokpit.php && grep -q "'status_changed' =>" lang/cs/kokpit.php</automated>
  </verify>
  <done>On /admin/tasks/KEY-N the 'Stav' entry carries a 'Změnit stav' link. Its modal changes the status through UpdateTask: the task lands at the end of the new column, completed_at follows Done, and the page shows the new status and the Czech success notice. The new and existing task tests, pint and phpstan are green.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Refusals — invalid value, archived task, Partner proofs — and the full gate</name>
  <files>tests/Feature/Tasks/TaskUpdateTest.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php</files>
  <behavior>
    - callAction(taskUpdStatusAction(), ['status' => 'archived']) on a planned task's page gives assertHasActionErrors(['status']) and the row stays Planned at its position
    - After `app(ArchiveTask::class)->handle($this->admin, $task)`, the task page gives assertActionHidden(taskUpdStatusAction()) and its html has no 'Změnit stav'; the row's status is unchanged
    - Partner (project `taskUpdProject('PRS', visible: true)`, Partner from `Canary::partnerFor($project->client_id)`): GET /admin/tasks/KEY-N as the Partner is 403 and the body has no 'Změnit stav'
    - A ViewTask page mounted as the Admin, then `$this->actingAs($partner)`, gives assertActionHidden(taskUpdStatusAction())
    - `app(UpdateTask::class)->handle($partner, <the task read as the Partner>, ['status' => 'done'])` throws AuthorizationException
    - GET /admin/my-tasks/KEY-N as the Partner is 200 and does not contain 'Změnit stav'
    - Back as the Admin (`$this->actingAs($this->admin)`), the row is still Planned with completed_at null
  </behavior>
  <action>
RED first. Add one `it()` per group to tests/Feature/Tasks/TaskUpdateTest.php: the invalid value; the archived task; the Partner refusals. Import App\Domain\Tasks\Actions\ArchiveTask. Read the Partner's view of the task with `Task::query()->where('reference', $task->reference)->firstOrFail()` while acting as the Partner. Switch back to the Admin before reading the row for the final assertions. Assert on the rendered text through `__('kokpit.tasks.actions.change_status')`, never a hard-coded copy, and keep only fictional 'Example ...' data. Run the file. A case that already passes after Task 1 is expected, because Task 1 built the guards. Any case that fails is a defect in the Task 1 code: fix it in ViewTask, never by weakening the test. The rules stay as written in Task 1: visibility from TaskResource::canEdit(), the body check TaskResource::canAccess(), the write and its authorization in UpdateTask, the error keyed `mountedActions.0.data.status`.

Then run the isolation and neighbour suites listed in verify. They must pass unchanged. PartnerTaskVisibilityTest and TaskEscalationTest pin the Partner page's header actions, TaskBoardTest pins the board moves and preview, and the Arch suite pins the AccessRule and query rules.

Finally run the full gate `ddev composer ci` on its own, not in parallel with another ddev command, and `scripts/check-sensitive.sh` over every touched file. Do not bypass the lefthook pre-commit hook.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskUpdateTest.php tests/Feature/Tasks/TaskArchiveTest.php tests/Feature/Tasks/TaskBoardTest.php tests/Feature/Tasks/TaskEscalationTest.php tests/Isolation/PartnerTaskVisibilityTest.php tests/Isolation/NotificationLeakTest.php tests/Arch && grep -q 'AuthorizationException' tests/Feature/Tasks/TaskUpdateTest.php && grep -q 'assertActionHidden(taskUpdStatusAction())' tests/Feature/Tasks/TaskUpdateTest.php && grep -q "/admin/my-tasks/" tests/Feature/Tasks/TaskUpdateTest.php && ddev composer ci && scripts/check-sensitive.sh app/Filament/Resources/TaskResource/Pages/ViewTask.php lang/cs/kokpit.php tests/Feature/Tasks/TaskUpdateTest.php</automated>
  </verify>
  <done>An unknown status is an error under the select and writes nothing. An archived task shows no link. A Partner is refused on the Admin page (403), sees the action hidden after a session switch, is refused by UpdateTask (AuthorizationException), and has no link on the own task page; the row is unchanged. The isolation, board, archive, escalation and arch suites pass unchanged. `ddev composer ci` and the sensitive-data check are green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → /livewire/update (ViewTask) | The action name and its form data (the status value) are client-controlled. The endpoint is public, and Filament authorizes the page only on mount |
| Admin panel page → Partner session | The same panel serves Admin and Partner. The Admin task page must never act for a Partner, and the Partner page must not gain the control |
| task status → board order and notifications | A status change re-positions the task in the shared board order and notifies the Partner side |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261018-rvf-01 | Elevation of Privilege | ViewTask::changeStatusAction | high | mitigate | ViewTask belongs to the AdminOnly TaskResource (403 for a Partner). The link is visible only while TaskResource::canEdit() holds (policy `update`, false for every Partner through KokpitPolicy::update). The body calls `abort_unless(TaskResource::canAccess(), 403)` first, and UpdateTask authorizes `update` again. TaskPolicy is unchanged. Proven by the Task 2 Partner tests: 403 on GET, action hidden after a session switch, AuthorizationException from UpdateTask, row unchanged |
| T-261018-rvf-02 | Tampering | Status value from the modal | medium | mitigate | The select offers only ProjectStatus cases; the value is normalised by TaskResource::enumValue and whitelisted by TaskInput::status (ValidationException), and the DB CHECK on `status` is the last line. Proven by the 'archived' value test |
| T-261018-rvf-03 | Tampering | Archived (read-only) task | medium | mitigate | The link is hidden for a trashed task (canEdit), and UpdateTask re-reads the row with the soft-delete scope, so an archived task is not found even from a stale page. Proven by the archived test |
| T-261018-rvf-04 | Tampering / race | Board order under concurrent moves | medium | mitigate | No new writer: UpdateTask takes the board advisory lock and the row lock and appends through TaskBoard::appendToColumn; the deferred position exclusion constraint fails loudly otherwise. Tests end with `SET CONSTRAINTS ALL IMMEDIATE` |
| T-261018-rvf-05 | Information Disclosure | Partner task page | medium | mitigate | ViewPartnerTask, PartnerTaskResource and the pinned TaskColumns::partner* builders are untouched; the Task 2 test asserts /admin/my-tasks/KEY-N has no link, and PartnerTaskVisibilityTest keeps the pinned header actions |
| T-261018-rvf-06 | Repudiation | Who changed the status | low | mitigate | The same UpdateTask path as the edit page writes one activity row with the status change and notifies the Partner requester and assignee once |
| T-261018-rvf-07 | Information Disclosure | Test fixtures, planning files | low | mitigate | Fictional 'Example …' titles and Canary accounts only; `scripts/check-sensitive.sh` over every touched file plus the lefthook gitleaks hook |
| T-261018-rvf-SC | Tampering | npm/pip/cargo/composer installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskUpdateTest.php tests/Isolation/PartnerTaskVisibilityTest.php` is green
- `ddev composer ci` is green (pest, pint, phpstan, licenses)
- `scripts/check-sensitive.sh` passes over every touched file
- Human check (end of phase, not blocking): open /admin/tasks/KEY-N in the browser. Next to 'Stav' there is a 'Změnit stav' link. Pick another status and save: the badge changes, the notice 'Stav úkolu byl změněn' appears, and on the board the card sits at the end of the new column. Open an archived task: there is no link
</verification>

<success_criteria>
- The Admin changes a task's status on the task page in two clicks, with the same effects as the edit page (board position, completed_at, history, Partner notification)
- The edit page status field and the board behave exactly as before
- Invalid values and archived tasks are refused; a Partner gains no way to change a status on any surface, enforced by the policy and UpdateTask, not only by hiding
- Full CI gate and sensitive-data check are green
</success_criteria>

<output>
Create `.planning/quick/261018-rvf-allow-changing-a-task-s-status-directly-/261018-rvf-SUMMARY.md` when done
</output>
