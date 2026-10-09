---
phase: 05-tasks-and-kanban
reviewed: 2026-10-09T00:00:00Z
depth: standard
files_reviewed: 17
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Notifications/NotificationEvent.php
  - app/Domain/Tasks/Actions/UpdateTaskDescription.php
  - app/Domain/Tasks/Notifications/TaskChangedNotification.php
  - app/Domain/Tasks/Notifications/TaskNotifier.php
  - app/Domain/Tasks/Policies/TaskPolicy.php
  - app/Filament/Auth/EditProfile.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
  - app/Filament/Resources/ActivityResource.php
  - lang/cs/kokpit.php
  - tests/Feature/Notifications/NotificationPreferencesTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Tasks/PartnerTaskDescriptionTest.php
  - tests/Feature/Tasks/TaskEscalationTest.php
  - tests/Isolation/NotificationMarkupTest.php
  - tests/Isolation/PartnerTaskVisibilityTest.php
findings:
  critical: 0
  warning: 2
  info: 4
  total: 6
status: issues_found
---

# Phase 5: Code Review Report (gap closure 05-20 and 05-21, G-05-5)

**Reviewed:** 2026-10-09
**Depth:** standard
**Files Reviewed:** 17

## Summary

This run covers the Partner description edit (`UpdateTaskDescription`, `TaskPolicy::editDescription`, the `editDescription` header action on `ViewPartnerTask`) and the Admin notification of that edit (`TaskNotifier::descriptionChanged`, the Admin `assignment_change` switch and its helper on the profile page).

The security-relevant core holds up:

- The Action takes no data array, so no other attribute can ride along.
- The ability is checked before the transaction and again on the row re-read under `lockForUpdate()` through the Partner-scoped query. A foreign, hidden or archived task is a not-found error, not a write.
- The text goes through `TaskInput::description` / `RichText::clean`.
- The history row carries no description text.
- The notification carries only the actor name, and the base class escapes it for each channel.
- Recipients are limited to active Admins and an assignee who is an Admin or a Partner of the same client who can still read the task.
- No real data, secrets or instance values appear in the changed files or tests.

No BLOCKER was found. Two WARNINGs concern a silent loss of a Partner's work, and four INFO items cover robustness and test precision.

## Warnings

### WR-05: The Admin edit path silently overwrites a Partner's description edit (lost update)

**File:** `app/Domain/Tasks/Actions/UpdateTask.php:105-139` (reached from `app/Filament/Resources/TaskResource/Pages/EditTask.php:55-60`); the guard that is missing sits in the new `app/Domain/Tasks/Actions/UpdateTaskDescription.php:84`
**Issue:** The new Partner path protects against overwriting someone else's change with a fingerprint check (T-05-51, "never a silent overwrite"). The Admin path has no equivalent. The Admin edit form submits the whole form state, and `description` is part of it. `UpdateTask` writes `description` whenever the key is present, under a row lock but with no comparison against what the form was opened with.

Sequence:
1. The Admin opens `/admin/tasks/KEY-N/edit`.
2. The Partner saves a new description. This writes a `description_changed` history row and notifies the Admin.
3. The Admin saves an unrelated change, for example the priority.
4. The Partner's description is replaced by the Admin's stale copy. The history still shows the Partner's edit, but the text is gone and nothing records the overwrite.

The Admin is the single primary user, but this is exactly the scenario the notification now announces, so it will happen in practice. The guarantee in the `UpdateTaskDescription` docblock only holds in one direction.
**Fix:** Make the stale guard symmetric. Either:
- carry the fingerprint in the Admin form (`UpdateTaskDescription::fingerprint($task->description)`) and have `UpdateTask` reject a `description` whose base fingerprint differs from the locked row; or
- have `TaskResource::actionData()` send `description` only when the field is dirty compared with the opened value, and treat an unchanged value as absent.

Add a test next to `tests/Feature/Tasks/PartnerTaskDescriptionTest.php:788`: open `EditTask`, let the Partner edit, save the Admin form, and assert the Partner's text survives or the save is refused.

### WR-06: A Partner whose task left the editable statuses while the editor was open gets no feedback and loses the text; the documented field error is unreachable from the UI

**File:** `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php:86-109`, `app/Domain/Tasks/Actions/UpdateTaskDescription.php:25-31, 71, 80-82`, `lang/cs/kokpit.php` (`tasks.errors.description_not_editable`), `tests/Feature/Tasks/PartnerTaskDescriptionTest.php:519-523`
**Issue:** The Action docblock says that a status change made by the Admin between opening the editor and saving "is a field error on `description`". Two things prevent that on the real page.

1. The action is `->visible(... can('editDescription'))`. Once the status has moved, the next Livewire request hydrates the page, `visible()` is false, and Filament treats the action as disabled. The test at line 519-523 documents this: `callMountedAction()` does nothing. The Partner presses "Uložit popis", the modal stays open, no error is shown, and the typed text is lost on the next close.
2. Even if the action did run, the pre-check at line 71 evaluates `editDescription` on the record the page re-read for this request, so it would throw `AuthorizationException` (a 403 in Livewire), not the friendly `ValidationException`. The localized message `description_not_editable` is then reachable only from a direct call with an instance that is already stale, which is what the unit-level test does with `$stale`.

The race protection itself is correct (nothing is written); the user-facing behaviour is not what the code and the language file promise.
**Fix:** Keep the visibility rule, but let the action run and report the refusal. For example, drop the status condition from `visible()` for an action that is already mounted and put `->disabled()` only on the trigger, or catch `AuthorizationException` in the action closure and rethrow it as `ValidationException::withMessages(['mountedActions.0.data.description' => __('kokpit.tasks.errors.description_not_editable')])`. Add a page-level test that asserts the field error, not only that the row is unchanged. Correct the docblock if the behaviour stays as it is.

## Info

### IN-08: A forged non-string `based_on` raises an ErrorException

**File:** `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php:102`
**Issue:** `description` is guarded with `is_string(...)`, but `(string) ($data['based_on'] ?? '')` is not. `based_on` is a `Hidden` field with no validation, so a forged Livewire update can set it to an array. `(string) []` raises "Array to string conversion", which Laravel turns into an `ErrorException` and a 500 response. Only the forging user is hurt, and nothing is written, but it is an unvalidated input on a write path.
**Fix:**
```php
is_string($data['based_on'] ?? null) ? $data['based_on'] : '',
```
An empty string already yields the stale error, because it never equals a SHA-256 fingerprint.

### IN-09: The unchanged-text check depends on the editor round-tripping the stored HTML unchanged (unverified)

**File:** `app/Domain/Tasks/Actions/UpdateTaskDescription.php:88`
**Issue:** "Saving the same text twice records one change" holds only if `RichText::clean(editorOutput) === stored` when the Partner changes nothing. The tests pass `$stored->description` straight back. A real RichEditor (TipTap) may re-serialise links (`target`, `rel`), table markup or whitespace differently from the sanitiser output. In that case opening the editor and pressing save without edits would write, log `description_changed` and notify the Admin about a non-change. I could not confirm this without running the browser editor.
**Fix:** Compare a normalised form, for example `RichText::clean($clean)` against `RichText::clean($locked->description)`, or confirm manually with a table and a link in the description and add a test that feeds a TipTap-shaped value.

### IN-10: `TaskChangedNotification` still defaults `recipientIsPartner` to `true`

**File:** `app/Domain/Tasks/Notifications/TaskChangedNotification.php:33`, `app/Domain/Tasks/Notifications/TaskNotifier.php:206-214`
**Issue:** The notification now serves two audiences, but the default stays on the Partner side. `TaskNotifier::changed()` relies on that default, and a future caller who forgets the flag sends an Admin the `/admin/my-tasks/` link. The audience also decides the URL passed separately, so the two can disagree. The neighbouring classes make the audience explicit.
**Fix:** Make `recipientIsPartner` a required parameter, with no default, and pass it explicitly in `changed()`. Optionally derive `$url` from it inside the notifier only.

### IN-11: Partner-side helper text and one test are less precise than the behaviour

**File:** `app/Domain/Notifications/NotificationEvent.php:50-57` with `lang/cs/kokpit.php` (`helpers.assignment_change`), and `tests/Feature/Tasks/PartnerTaskDescriptionTest.php:296-300`
**Issue:**
- A Partner assignee now also receives "Popis upraven uživatelem ..." through the `assignment_change` switch when another Partner of the same client edits the description (tested at line 734). The Partner helper still says only "status, priority or assignee changed", so the switch under-describes what it controls.
- The archived-task case accepts either `AuthorizationException` or `ModelNotFoundException`. The two outcomes come from different guards, so a regression in one guard would be hidden by the other.

**Fix:** Extend the Partner helper to mention the description edit, or give it its own variant like the Admin one. In the test, assert the single expected exception for each row; for an archived task for a Partner that is the one the policy or the scoped query actually raises.

---

_Reviewed: 2026-10-09_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
