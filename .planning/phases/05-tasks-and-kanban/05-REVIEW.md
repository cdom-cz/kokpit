---
phase: 05-tasks-and-kanban
reviewed: 2026-10-09T01:30:00Z
depth: standard
files_reviewed: 113
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Identity/Models/User.php
  - app/Domain/Notifications/NotificationChannel.php
  - app/Domain/Notifications/NotificationEvent.php
  - app/Domain/Notifications/NotificationPreferences.php
  - app/Domain/Notifications/UpdateNotificationPreferences.php
  - app/Domain/Projects/Actions/UpdateProject.php
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Tags/TagType.php
  - app/Domain/Shared/Text/RichText.php
  - app/Domain/Tasks/Actions/AddTaskComment.php
  - app/Domain/Tasks/Actions/ArchiveTask.php
  - app/Domain/Tasks/Actions/ClearEscalation.php
  - app/Domain/Tasks/Actions/CreateTask.php
  - app/Domain/Tasks/Actions/EscalateTask.php
  - app/Domain/Tasks/Actions/MoveTask.php
  - app/Domain/Tasks/Actions/RestoreTask.php
  - app/Domain/Tasks/Actions/UpdateTask.php
  - app/Domain/Tasks/Billing/BillingSource.php
  - app/Domain/Tasks/Billing/EffectiveBilling.php
  - app/Domain/Tasks/Billing/TaskBillingResolver.php
  - app/Domain/Tasks/Board/BoardFilters.php
  - app/Domain/Tasks/Board/TaskBoard.php
  - app/Domain/Tasks/Enums/TaskBillingType.php
  - app/Domain/Tasks/Models/Task.php
  - app/Domain/Tasks/Models/TaskBilling.php
  - app/Domain/Tasks/Models/TaskChecklistItem.php
  - app/Domain/Tasks/Models/TaskComment.php
  - app/Domain/Tasks/Notifications/TaskChangedNotification.php
  - app/Domain/Tasks/Notifications/TaskCommentedNotification.php
  - app/Domain/Tasks/Notifications/TaskCreatedNotification.php
  - app/Domain/Tasks/Notifications/TaskEscalatedNotification.php
  - app/Domain/Tasks/Notifications/TaskNotification.php
  - app/Domain/Tasks/Notifications/TaskNotifier.php
  - app/Domain/Tasks/Policies/TaskCommentPolicy.php
  - app/Domain/Tasks/Policies/TaskPolicy.php
  - app/Domain/Tasks/TaskInput.php
  - app/Domain/Tasks/TaskPeople.php
  - app/Filament/Auth/EditProfile.php
  - app/Filament/Concerns/ManagesTaskBoard.php
  - app/Filament/Pages/TaskBoardPage.php
  - app/Filament/Partner/Resources/PartnerTaskResource.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ListPartnerTasks.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
  - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php
  - app/Filament/RelationManagers/TaskHistoryRelationManager.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/ProjectResource/Pages/EditProject.php
  - app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php
  - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Resources/TaskResource/Pages/EditTask.php
  - app/Filament/Resources/TaskResource/Pages/ListTasks.php
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
  - app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php
  - app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php
  - app/Filament/Support/TaskColumns.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - composer.json
  - config/eloquent-sortable.php
  - config/kokpit.php
  - database/factories/TaskFactory.php
  - database/migrations/2026_10_10_000100_create_tasks_table.php
  - database/migrations/2026_10_10_000200_add_project_key_freeze_trigger.php
  - database/migrations/2026_10_10_000300_create_task_checklist_items_table.php
  - database/migrations/2026_10_10_000400_create_task_billing_table.php
  - database/migrations/2026_10_10_000500_create_task_comments_table.php
  - database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php
  - lang/cs/enums.php
  - lang/cs/kokpit.php
  - resources/views/filament/pages/partials/task-preview.blade.php
  - resources/views/filament/pages/task-board.blade.php
  - tests/Arch/ActivityAllowlistTest.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Concurrency/TaskBoardConcurrencyTest.php
  - tests/Concurrency/TaskNumberConcurrencyTest.php
  - tests/Concurrency/task-worker.php
  - tests/Feature/Notifications/NotificationPreferencesTest.php
  - tests/Feature/Operations/ActivityViewsTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/SchemaConventionsTest.php
  - tests/Feature/Schema/TaskBillingTableTest.php
  - tests/Feature/Schema/TasksTableTest.php
  - tests/Feature/Tasks/PartnerTaskCommentsTest.php
  - tests/Feature/Tasks/PartnerTaskResourceTest.php
  - tests/Feature/Tasks/RichTextSanitiserTest.php
  - tests/Feature/Tasks/SubtasksTest.php
  - tests/Feature/Tasks/TaskActionsTest.php
  - tests/Feature/Tasks/TaskArchiveTest.php
  - tests/Feature/Tasks/TaskBillingResolverTest.php
  - tests/Feature/Tasks/TaskBillingTest.php
  - tests/Feature/Tasks/TaskBoardTest.php
  - tests/Feature/Tasks/TaskChecklistTest.php
  - tests/Feature/Tasks/TaskCommentsTest.php
  - tests/Feature/Tasks/TaskEscalationTest.php
  - tests/Feature/Tasks/TaskKeyTest.php
  - tests/Feature/Tasks/TaskListFiltersTest.php
  - tests/Feature/Tasks/TaskNotificationsTest.php
  - tests/Feature/Tasks/TaskResourceTest.php
  - tests/Feature/Tasks/TaskUpdateTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/NotificationLeakTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PartnerSafeColumnsTest.php
  - tests/Isolation/PartnerTaskVisibilityTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Support/CanaryRegistry.php
  - tests/Support/PgSchema.php
  - tests/Support/UnlockedTaskBoard.php
findings:
  critical: 1
  warning: 4
  info: 7
  total: 12
status: issues_found
---

# Phase 5: Code Review Report

**Reviewed:** 2026-10-09T01:30:00Z
**Depth:** standard
**Files Reviewed:** 113
**Status:** issues_found

## Summary

Phase 5 is well built where it matters most. The board lock order, the deferred position exclusion constraint, the composite parent foreign key, the Partner scopes (tasks, comments), the pinned Partner column builders, the scalar-only queued notifications and the internal-comment triple lock all held up under tracing. I found no Partner isolation bypass, no lock-order cycle and no lost-update path on the board. The `moveCard` / `MoveTask` / `TaskBoard::move` position arithmetic is correct for same-column drops, cross-column drops, filtered boards and the Done column. All `__('kokpit...')` and `__('enums...')` keys used by the phase exist in `lang/cs` (dynamic prefixes were checked value by value) and there are no duplicate array keys. `scripts/check-sensitive.sh --all` is clean.

The defects that remain are at the seams between the form layer and the domain Actions, in one notification path the security audit scoped too narrowly, and in test coverage that is claimed to be broader than it is. Four of the findings below were confirmed by running a throwaway Pest probe against the test database (the probe file was deleted afterwards; the working tree is unchanged).

Known findings carried over from `05-SECURITY.md` (listed here for completeness, **not counted** in the totals):

- **T-05-44 / U-1 (medium, open):** the task title and the actor's display name are unescaped in Admin bell and mail bodies. Reconfirmed in code: `TaskCreatedNotification::bellBody` and the `mail_line` strings. See WR-02 for an adjacent extension that the audit did not cover.
- **G-1 (low):** `CreateTask` (lines 100-108, 160-162) drops a Partner's status, priority and people but not `tags`. Not reachable today. Note that WR-01 below is the same class of gap (an Action that relies on its only caller's form for validation).

## Critical Issues

### CR-01: A deactivated assignee or requester makes the Admin edit page refuse every save of that task

**File:** `app/Filament/Resources/TaskResource.php:238-247` (also `app/Domain/Tasks/Actions/UpdateTask.php:34-38`, `app/Domain/Tasks/TaskPeople.php:57-63`)
**Issue:** `UpdateTask` documents and implements that "an unchanged person is not checked again, so a task keeps a person who was deactivated since". The Admin edit form contradicts this. The `assignee_id` and `requester_id` selects take their options from `TaskPeople::options()`, which returns only active accounts. Filament's single `Select` adds an `in` validation rule derived from the option labels (`Select::getInValidationRuleValues()` returns `[]` when the current state has no option label). A task whose assignee or requester was deactivated after assignment, for example an offboarded Partner account, therefore fails form validation with "Zvolená hodnota pro řešitel je neplatná." on **any** save, including a plain rename or a priority change. The Admin must first re-pick another person. Reproduced with a probe: task assigned to a Partner, Partner deactivated, `EditTask` `fillForm(['title' => ...])->call('save')` returns `data.assignee_id` error and nothing is written. The existing test `offers exactly the active Admin and the active Partners...` pins the option list but not the save of a task that already holds an inactive person, so the contradiction was never exercised.
**Fix:** Offer the task's current person even when inactive (the domain already allows keeping it), and keep every other inactive account out of the list:
```php
private static function peopleOptions(?Task $task, string $field): array
{
    $project = self::projectOf($task);
    $options = $project === null ? [] : app(TaskPeople::class)->options($project);

    $current = $task?->getAttribute($field);

    if (is_string($current) && ! array_key_exists($current, $options)) {
        $options[$current] = (string) User::query()->whereKey($current)->value('name');
    }

    return $options;
}
// ->options(static fn (?Task $record): array => self::peopleOptions($record, 'assignee_id'))
```
Add a Livewire test that saves a rename of a task whose Partner assignee was deactivated and asserts no form error and an unchanged `assignee_id`.

## Warnings

### WR-01: Actions do not validate what the DB constrains, so out-of-range input is an unhandled 500 instead of a field error

**File:** `app/Domain/Tasks/Actions/CreateTask.php:86-90`, `app/Domain/Tasks/Actions/UpdateTask.php:99-103`, `app/Domain/Tasks/TaskInput.php:66-85`
**Issue:** Both Actions advertise themselves as "the one owner of every rule" and answer with field errors keyed by data key, but two limits are enforced only by the Filament forms:
1. The title length. `tasks.title` is `varchar(255)`; neither Action checks it. Probe: `CreateTask::handle(..., ['title' => str_repeat('x', 300)])` throws `QueryException` (`value too long for type character varying(255)`).
2. Calendar range. `TaskInput::day()` accepts any string that round-trips through `createFromFormat('!Y-m-d')`, which includes `0000-01-01`. PostgreSQL has no year 0. Probe: `UpdateTask::handle(..., ['due_date' => '0000-01-01'])` throws `QueryException` (`date/time field value out of range`). The same applies to the `due` filter day helper in `TaskResource::day()` (it only filters, but binds the same value to a `date` column).

Today these are reachable only through a forged Livewire payload by the Admin, because the forms validate first. Phase 7 adds an API that calls these Actions directly, and the checklist text (500 characters) and tag names have the same shape. The result will be 500 responses and, for `CreateTask`, a partly consumed sequence number rolled back by the failed transaction. This is the same root cause as G-1.
**Fix:** Validate in the Action layer, in `TaskInput`:
```php
public static function title(string $title): string
{
    $title = trim($title);

    if ($title === '') {
        throw ValidationException::withMessages(['title' => __('kokpit.tasks.errors.title_required')]);
    }

    if (mb_strlen($title) > 255) {
        throw ValidationException::withMessages(['title' => __('kokpit.tasks.errors.title_too_long')]);
    }

    return $title;
}
// in day(): reject years outside 1000-9999, e.g. require (int) $day->year >= 1000
```
Use it from both Actions and add the `title_too_long` string to `lang/cs/kokpit.php`.

### WR-02: T-05-44 is wider than the audit recorded: unescaped title and actor name also reach Partner recipients, including across Partners of one client

**File:** `app/Domain/Tasks/Notifications/TaskChangedNotification.php:51-57`, `app/Domain/Tasks/Notifications/TaskCommentedNotification.php:51-58`, `app/Domain/Tasks/Notifications/TaskEscalatedNotification.php:54-61`, `app/Domain/Tasks/Notifications/TaskNotification.php:94-100`
**Issue:** The audit scoped U-1 to "Partner injects into the Admin's bell and mails". The same unescaped interpolation also feeds mails whose recipient is a Partner:
- `TaskChangedNotification::mailLine()` interpolates `taskTitle`. A task title is written by any Partner of the client (via `CreatePartnerTask`), and the change notice goes to the Partner requester and assignee, who can be a different Partner account of the same client.
- `TaskCommentedNotification::mailLine()` and `TaskEscalatedNotification::mailLine()` interpolate `taskTitle` and `actorName`. A Partner's comment notifies the task's assignee, which may be another Partner (`TaskNotifier::commented`, lines 88-94), and `actorName` is the sender's self-editable display name.

Probe: `TaskChangedNotification` built with the title `<a href="https://evil.example/x">Click to pay</a>` renders a live `href` in `toMail()->render()`; `TaskCommentedNotification` with such an actor name does the same. Laravel's mail Markdown renderer passes inline HTML through. A fix limited to the Admin-facing notifications (as T-05-44 proposes) would leave this path open.
**Fix:** Apply the T-05-44 fix to every notification class, not only the Admin-bound ones: escape in the base class so a subclass cannot forget it.
```php
// TaskNotification
protected function safe(string $text): string
{
    return self::escapeMarkdown(e($text));
}
// then in every mailLine()/bellBody(): ['title' => $this->safe($this->taskTitle), 'actor' => $this->safe($this->actorName)]
```
Extend the canary test of `NotificationLeakTest` to build each notification with an HTML title and actor name and assert no `<a`/`href` in the rendered mail or bell body for either audience.

### WR-03: `AddTaskComment` does not re-read the task, so a comment can be added to a task archived after the page loaded

**File:** `app/Domain/Tasks/Actions/AddTaskComment.php:45-66`
**Issue:** The Action authorises against the caller's `$task` instance and inserts without re-reading the row. The Livewire relation managers restore the owner record without global scopes (Livewire restores models with `newQueryForRestoration()`), so an archived task is still found, and `TaskPolicy::comment` only checks that the project is visible, not that the task is active. Every sibling Action (`EscalateTask`, `ClearEscalation`, `MoveTask`, `UpdateTask`) re-reads the row through the scoped query under a lock for exactly this reason. Probe: a stale `Task` instance of a task archived in the meantime, passed to `AddTaskComment::handle` as a Partner, creates the comment. The comment also notifies the Admin about a task that is no longer visible to the Partner.
**Fix:** Re-read through the scoped (soft-delete-aware) query inside the transaction:
```php
return DB::transaction(function () use ($actor, $task, $clean, $internal, $escalation): TaskComment {
    $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
    // ... forceFill with $locked->getKey(), notify with $locked
});
```
`EscalateTask` already passes a locked row, which is compatible.

### WR-04: The "no file attachments" guard is pinned for one editor out of five

**File:** `tests/Feature/Tasks/RichTextSanitiserTest.php:258-267` (claim in `05-SECURITY.md`, T-05-10: "`fileAttachments(false)` on all five editors, pinned by test")
**Issue:** There are five `RichEditor` instances: the Admin description (`TaskResource.php:195`), the Admin comment body (`TaskCommentsRelationManager.php:63`), the Partner description (`PartnerTaskResource.php:134`), the Partner comment body (`PartnerTaskCommentsRelationManager.php:63`) and the escalation reason (`ViewPartnerTask.php:60`). Only the Admin description is asserted (`hasFileAttachments()` false, no `attachFiles` button) and only that editor is hit with the forged upload call. The three Partner-facing editors, which are the ones an untrusted account can reach, have no pin: removing `->fileAttachments(false)` from any of them leaves the suite green, and the default is attachments **on**. The forged-upload test also swallows a `TypeError` and only inspects local disks, so it would not notice a write to an `s3` disk.
**Fix:** Add one parametrised test that mounts each of the four remaining surfaces and asserts `! $editor->hasFileAttachments()` and no `attachFiles` toolbar button for the Partner description, Partner comment body, escalation reason and Admin comment body. A cheaper structural alternative is an Arch-style test that scans `app/Filament` for `RichEditor::make(` and requires `->fileAttachments(false)` in the same chain.

## Info

### IN-01: `Task` fillable list contradicts its own docblock and bypasses the board invariants

**File:** `app/Domain/Tasks/Models/Task.php:47-81`
**Issue:** The docblock says "Only the plain content columns are fillable", but `status` and `priority` are in `#[Fillable]`. A future `$task->update(['status' => 'done'])` would write the status without `completed_at` and `position`, so the `tasks_completed_check` constraint would turn it into a 500 (or, for non-done statuses, a position collision caught only at commit). `CreateTask` is the only reason `status` is fillable.
**Fix:** Keep both out of `#[Fillable]` and set them in `CreateTask` with `forceFill`, as is already done for position and `completed_at`.

### IN-02: `CreateTask` and `TaskFactory` recover the task number by parsing the formatted reference

**File:** `app/Domain/Tasks/Actions/CreateTask.php:128-129`, `database/factories/TaskFactory.php:70-73`
**Issue:** `substr($reference, strrpos($reference, '-') + 1)` couples the writer to the default number pattern. A pattern with a suffix after the number would silently store a wrong `number` and trip `tasks_project_number_unique` or the reference check. `DocumentNumbering::nextTaskNumber()` already has the integer from the allocator and throws it away.
**Fix:** Return both from `DocumentNumbering` (for example a small value object `{number, reference}`) and drop the string parsing in both callers.

### IN-03: Duplicated building blocks across the Filament classes

**File:** `app/Filament/Resources/TaskResource.php:798-808`, `app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php:137-148`, `app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php:141-151`, `app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php:128-138`; `TaskResource.php:648-657` vs `PartnerTaskResource.php:172-181`
**Issue:** `underModal()` exists four times (two copies differ by a `parent`/`project_id` remap), `projectOptions()` twice, the rich editor toolbar definition five times, and the escalation timestamp format `'j. n. Y H:i'` twice (`ViewTask.php:81`, `TaskColumns.php:120`) next to the existing `LocalisationServiceProvider::DATE_FORMAT` used elsewhere. WR-04 is partly a symptom: five copies of the editor configuration means five places to forget a guard. `TaskResource` itself is 809 lines holding form, list, filters, actions and quick-create.
**Fix:** Extract `RethrowsDomainValidation::underModal(ValidationException, array $remap = [])`, a shared `Support\RichTextEditor::make(string $name)` factory that bakes in `fileAttachments(false)`, a `Project::selectable()` option helper, and one localisation constant for date-times.

### IN-04: Migration `down()` coverage

**File:** `database/migrations/2026_10_10_000300_create_task_checklist_items_table.php`, `..._000400_create_task_billing_table.php`, `..._000500_create_task_comments_table.php`, `..._000600_add_notification_preferences_to_users_table.php`
**Issue:** Four of the six phase migrations have no `down()`. This matches the repo pattern (most older migrations have none), and `000100` and `000200` drop their objects correctly (the trigger first, the table after, in reverse order). It does mean `migrate:rollback` cannot undo `000600` (the `notification_preferences` column and its `users_notification_preferences_check`) and leaves `task_*` tables behind while `tasks` is dropped, which then fails on the foreign keys. Rollback is only a development convenience here, so this is informational.
**Fix:** Add `Schema::dropIfExists(...)` to the three `create_*` migrations and `DROP CONSTRAINT ... / DROP COLUMN` to `000600`, or state the no-rollback policy once in `CONTRIBUTING.md`.

### IN-05: Hand-over notes in `CONTRIBUTING.md` name the wrong phases

**File:** `CONTRIBUTING.md` (section "Hand-over notes for later phases")
**Issue:** The new note "Phase 7 (calendar and reports)" does not match `ROADMAP.md`: Phase 7 is the REST API, reports are Phase 8, and there is no calendar phase. The new text "the board and the calendar read status and position only through `TaskBoard`" refers to a calendar that is not planned. The untouched neighbours are also stale ("Phase 12 (API)" is Phase 7 in the roadmap; Phase 12 is the Partner audit). A reader following these notes will attach the API task-lookup rule to the wrong phase.
**Fix:** Retitle the note "Phase 7 (REST API)", move the reference-addressing rule there, drop the calendar sentence, and correct the Phase 12 note to Phase 7 in the same edit.

### IN-06: Board package state is not restored when `setNewOrder` throws

**File:** `app/Domain/Tasks/Board/TaskBoard.php:258`, `config/eloquent-sortable.php:27`
**Issue:** With `ignore_timestamps => true`, `SortableTrait::setNewOrder()` appends the model class to a static `$ignoreTimestampsOn` list and removes it only after the loop, with no `try/finally`. If one of the per-row updates throws (lock timeout, deadlock), the class stays in the list for the rest of the process, so later `Task` saves in that process skip `updated_at`. Moves run in web requests, so the practical exposure is small today; a long-lived worker or Octane would carry it. The setting is also global, so it applies to the tags and media sorts too.
**Fix:** Wrap the call so a failure cannot leave the flag behind, or set `ignore_timestamps` false and write `position` through a query that leaves `updated_at` alone.
```php
try {
    Task::setNewOrder($rewrite, $from);
} finally {
    Task::$ignoreTimestampsOn = array_values(array_diff(Task::$ignoreTimestampsOn, [Task::class]));
}
```

### IN-07: The board has no non-pointer way to change a card's column

**File:** `resources/views/filament/pages/task-board.blade.php:40-112`
**Issue:** Moving a card is possible only by drag and drop (`wire:sort`). A keyboard or screen-reader user has no board-level control for it (the status can still be changed on the edit page, so nothing is blocked). A drop inside the capped Done column is accepted and then silently ignored by `TaskBoard::move()`, so the card snaps back with no feedback.
**Fix:** Add a status select or "Move to..." action in the preview slide-over that calls `MoveTask` with index 0, and show a short notice when a drop inside the Done column is ignored.

---

_Reviewed: 2026-10-09T01:30:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
