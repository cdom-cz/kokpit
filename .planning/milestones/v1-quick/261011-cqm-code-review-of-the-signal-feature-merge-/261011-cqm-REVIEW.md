---
phase: quick-261011-cqm
reviewed: 2026-10-11T00:00:00Z
depth: standard
files_reviewed: 41
files_reviewed_list:
  - app/Domain/Shared/Auth/OwnedByUser.php
  - app/Domain/Shared/Auth/OwnerScope.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Providers/AccessServiceProvider.php
  - app/Domain/Signal/Actions/AddSignalGoal.php
  - app/Domain/Signal/Actions/AddSignalTask.php
  - app/Domain/Signal/Actions/AuthorizesSignal.php
  - app/Domain/Signal/Actions/DeleteSignalGoal.php
  - app/Domain/Signal/Actions/DeleteSignalRecurring.php
  - app/Domain/Signal/Actions/DeleteSignalTask.php
  - app/Domain/Signal/Actions/MaterializeSignalRecurring.php
  - app/Domain/Signal/Actions/ReorderSignalGoals.php
  - app/Domain/Signal/Actions/ReorderSignalTasks.php
  - app/Domain/Signal/Actions/SaveSignalRecap.php
  - app/Domain/Signal/Actions/SaveSignalRecurring.php
  - app/Domain/Signal/Actions/SaveSignalSettings.php
  - app/Domain/Signal/Actions/SetSignalDayUnlocked.php
  - app/Domain/Signal/Actions/SetSignalDeepWork.php
  - app/Domain/Signal/Actions/ToggleSignalGoal.php
  - app/Domain/Signal/Actions/ToggleSignalRecurring.php
  - app/Domain/Signal/Actions/ToggleSignalTaskDone.php
  - app/Domain/Signal/Actions/UpdateSignalGoal.php
  - app/Domain/Signal/Actions/UpdateSignalTask.php
  - app/Domain/Signal/Casts/WeekdaysCast.php
  - app/Domain/Signal/Enums/SignalCategory.php
  - app/Domain/Signal/Exceptions/SignalRuleViolation.php
  - app/Domain/Signal/Models/*.php (7 models)
  - app/Domain/Signal/Queries/SignalDayReader.php
  - app/Domain/Signal/Queries/SignalOverviewReader.php
  - app/Domain/Signal/Support/SignalCalendar.php
  - app/Domain/Signal/Support/SignalLock.php
  - app/Domain/Signal/Support/SignalRules.php
  - app/Domain/Signal/Support/SignalStats.php
  - app/Filament/Concerns/InSignalGroup.php
  - app/Filament/Pages/Signal/*.php (5 pages)
  - app/Livewire/Signal/RunsSignalActions.php
  - app/Livewire/Signal/SignalDay.php
  - database/migrations/2026_10_12_000100_create_signal_planning_tables.php
  - database/migrations/2026_10_12_000200_create_signal_weekly_tables.php
  - resources/views/filament/pages/signal/*.blade.php
  - resources/views/livewire/signal/day.blade.php
findings:
  critical: 2
  warning: 8
  info: 5
  total: 15
status: issues_found
---

# Quick Task 261011-cqm: Code Review Report (Signal planner, merge 0f9f45a)

**Reviewed:** 2026-10-11
**Depth:** standard
**Files Reviewed:** 41 (groups counted as one entry where listed with a glob)
**Status:** issues_found

## Summary

Scope: the Signal planner merged by `0f9f45a` (diff `beff1cf..0f9f45a`): domain Actions/Queries/Support,
the shared owner scope, the Filament pages, the Livewire `SignalDay` component, the two migrations and
the Blade views.

The isolation design holds up under review (details in "Checked and found sound" below): I found no
cross-user read or write path, no Partner path, and no mass-assignment of `user_id`. The defects are in
business-rule correctness (a recurring task that cannot be deleted, a limit that counts the same slot
twice), in robustness against tampered or edge-case input (500s instead of a refusal), and in a few
concurrency and data-loss edges. Nothing was executed against a database (PostgreSQL was not running in
this session), so the PostgreSQL-specific claims (year 0000, `ON CONFLICT` semantics) rest on documented
engine behavior, not on a reproduction.

## Critical Issues

### CR-01: A recurring task instance of today cannot be deleted; it is re-created on the next render

**File:** `app/Livewire/Signal/SignalDay.php:209-211`, `app/Domain/Signal/Actions/MaterializeSignalRecurring.php:46-47`, `app/Domain/Signal/Actions/DeleteSignalTask.php:30-36`
**Issue:** `SignalDay::day()` runs `MaterializeSignalRecurring` on every render of today. Materialization
decides "already done" only by the existence of a `signal_tasks` row with `(recurring_id, for_date)`.
`DeleteSignalTask` deletes such a row without any marker, and the view offers the trash button for every
task including the ones carrying the recurring icon (`day.blade.php:124-141`). `deleteTask()` then calls
`changed()`, which clears the computed `day`, the next render finds the template "pending" and inserts a
fresh row with a new id. The user presses delete, confirms, and the task reappears. The class docblock
promises "once per template and day", which the implementation does not deliver after a delete. The only
test (`keeps the generated tasks when a recurring template is deleted`) covers deleting the template, not
the task.
**Fix:** Persist the fact that the instance was generated, not only the row. Either keep a tombstone
(for example `signal_tasks.deleted_at` soft delete so the partial unique index still blocks the insert,
or a small `signal_recurring_skips (recurring_id, for_date)` table checked by materialization), or refuse
deleting an instance and hide the trash button when `$task['recurring']`, pointing the user to switching
the template off. Add a regression test: materialize, delete the instance, render/materialize again,
expect zero rows.

### CR-02: Materialized recurring tasks are counted twice against the daily colour limit

**File:** `app/Domain/Signal/Actions/UpdateSignalTask.php:57-63`, `app/Domain/Signal/Actions/AddSignalTask.php:72-80`, `app/Domain/Signal/Queries/SignalDayReader.php:43-69`, `app/Livewire/Signal/SignalDay.php:221-228`
**Issue:** `categoryCounts()` counts every stored task of the day, including the ones materialized from
templates. `recurringCounts()` counts every active template that fires on the day, "whether or not it was
materialized yet" (its own docblock). On a day where materialization already ran (today, and any past day
that was materialized when it was today) the same template is therefore reserved once as a template and
once as a task. Example: limit 3 for `main`, two main templates, both materialized today. Moving a green
task to main: `count = 2`, `reserved = 2`, effective limit `3 - 2 = 1`, `2 < 1` is false, so the move is
refused, although only 2 of 3 main slots are in use. The select label in the UI uses the same arithmetic
(`$left = max(0, effectiveLimit - inGroup->count())`) and shows "0 left" while one slot is free. The rule
is enforced in the authoritative Action, so the refusal is real, not cosmetic. It stays hidden in
`AddSignalTask` only because today's additions are always grey.
**Fix:** Count only the not-yet-materialized templates as reserved, for example subtract templates that
already have a task on that day:
```php
// SignalDayReader::recurringCounts(string $day)
$materialized = SignalTask::query()->where('for_date', $day)->whereNotNull('recurring_id')->pluck('recurring_id')->all();
foreach (SignalRecurringTask::query()->firingOn($day)->whereNotIn('id', $materialized)->get(['id', 'category']) as $template) {
    $counts[$template->category->value]++;
}
```
and add a test: two materialized main templates, one free main slot, `UpdateSignalTask` to main succeeds.

## Warnings

### WR-01: Date validation accepts values PostgreSQL rejects; several public properties are not re-validated after mount

**File:** `app/Domain/Signal/Support/SignalCalendar.php:47-57`, `app/Livewire/Signal/SignalDay.php:44,59-65`, `app/Filament/Pages/Signal/SignalTodayPage.php:35,60-63`, `app/Filament/Pages/Signal/SignalReflectionPage.php:35-39,68-69`, `app/Filament/Pages/Signal/SignalWeekPage.php:46,81-83`
**Issue:** `isValidDay()` is a PHP round trip, and PHP accepts `0000-01-01`. PostgreSQL has no year 0 and
raises `date/time field value out of range` for such a literal, in an insert and in a `WHERE for_date =`
comparison alike. Every caller that treats `isValidDay()` as "safe for the database" (`?date=`, `?from=`,
`?to=`, `?week=`, `AddSignalTask`, `SetSignalDayUnlocked`, `SignalDay::mount`) can therefore be driven
into a 500 with a hand-edited URL. Separately, `SignalDay::$forDate`, `SignalTodayPage::$date`,
`SignalWeekPage::$week` and `SignalReflectionPage::$from/$to` are plain public properties: Livewire lets
the browser overwrite them in an update request, and only `mount()` / `goTo()` validate them. A tampered
`forDate = 'x'` reaches `SignalCalendar::formatLong()` in `day()` and throws `InvalidArgumentException`.
There is no isolation impact (all reads stay inside the owner scope), but the outcome is an error page
instead of a refusal.
**Fix:** Make `isValidDay()` also require a year the database accepts (for example
`(int) substr($value, 0, 4) >= 1`). Mark the non-user-editable properties `#[Locked]`
(`SignalDay::$forDate`, `$heading`, `SignalWeekPage::$week`) and, for the URL-bound ones that must stay
writable, add `updatedDate()`, `updatedFrom()`, `updatedTo()` hooks that re-run the same normalization as
`goTo()`.

### WR-02: Direct queries with an unchecked id turn a non-UUID into a PostgreSQL error

**File:** `app/Livewire/Signal/SignalDay.php:86,134`
**Issue:** The Actions go through `findOwned()`, which rejects a non-UUID before it reaches the `uuid`
column. `startEdit(string $id)` and `reorder(string $item, int $position)` bypass it and run
`->whereKey($id)` directly. A forged call (or a stale client value) with a non-UUID string produces
`invalid input syntax for type uuid` (SQLSTATE 22P02) and a 500. The same file comments say "a record that
is gone or not the user's own is reported as not found"; these two methods break that contract.
**Fix:** Guard first, for example `if (! Str::isUuid($id)) { return; }`, or move the lookup into a small
Action/Reader method that reuses `findOwned()`.

### WR-03: First save of the settings races on the unique `user_id`

**File:** `app/Domain/Signal/Actions/SaveSignalSettings.php:33-41`
**Issue:** `SignalSetting::query()->lockForUpdate()->first() ?? new SignalSetting` locks nothing when no
row exists yet (PostgreSQL has no gap lock). Two concurrent first saves (two tabs, or a double submit
across components) both see "no row", both insert, and the second hits
`signal_settings_user_id_unique`. The uncaught `QueryException` is not handled by `RunsSignalActions::attempt()`.
Every sibling Action that has a create-or-update shape takes `SignalLock` (`SaveSignalRecap`,
`SetSignalDeepWork`, `SetSignalDayUnlocked`); this one does not.
**Fix:** Take the advisory lock inside the transaction:
```php
SignalLock::take((string) $actor->getKey(), 'settings');
$setting = SignalSetting::query()->first() ?? new SignalSetting;
```

### WR-04: Materialization reads the templates outside the lock it later takes

**File:** `app/Domain/Signal/Actions/MaterializeSignalRecurring.php:37-45,81`
**Issue:** The template list is loaded before `DB::transaction()` and before `SignalLock::take()`. Between
the read and the insert a template can be deleted, switched off, or re-coloured in another request. A
deleted template makes `insertOrIgnore` violate the composite foreign key
`signal_tasks_recurring_user_fk`. `ON CONFLICT DO NOTHING` only absorbs unique violations, so this is an
uncaught `QueryException` (500) on a page render. A switched-off or re-coloured template is materialized
from stale data. The docblock's reasoning ("idempotent under parallel requests") covers the duplicate
case only.
**Fix:** Move `SignalRecurringTask::query()->firingOn($day)...->get()` inside the transaction, after
`SignalLock::take()`. (The existing `$templates->isEmpty()` early return can stay as a cheap pre-check
before the transaction.)

### WR-05: Changing the week silently discards unsaved recap text

**File:** `app/Filament/Pages/Signal/SignalWeekPage.php:81-92`
**Issue:** `goToWeek()` is wired to the previous/next/this-week buttons and unconditionally overwrites
`$wentWell` and `$toChange` with the stored recap of the new week (or empty strings). A user who typed a
Friday recap and clicked "previous" or "next" before pressing save loses the text without a warning.
**Fix:** Either save the current recap on navigation, or track a dirty flag (compare against the loaded
value) and ask for confirmation with `wire:confirm`; at minimum, do not navigate when the form is dirty.

### WR-06: The main-task streak is silently capped at 30 days

**File:** `app/Filament/Pages/Signal/SignalDashboardPage.php:90,114`, `app/Domain/Signal/Support/SignalStats.php:152`
**Issue:** `mainStreak()` loops up to 400 days, but the page feeds it `$rows30`, the rows of the last 30
days. A 45-day streak is reported as 30 and never grows beyond it. The unit test cannot catch this because
it passes its own rows.
**Fix:** Load main-task rows for a longer window (for example `taskRows(addDays($today, -399), $today)` for
the streak only, or an SQL query that stops at the first non-qualifying day), and name the 400 as a
constant shared with `SignalCalendar::range()`.

### WR-07: Recurring templates are not checked against the days that already exist

**File:** `app/Domain/Signal/Actions/SaveSignalRecurring.php:43-47`, `app/Domain/Signal/Actions/ToggleSignalRecurring.php:21-24`
**Issue:** The limit rule ("at most 3 main, 3 medium per day, templates reserve slots") is enforced when a
task is added or re-coloured, but not when a template is created, re-coloured, given more weekdays, or
re-activated. A day that already holds 3 planned main tasks gets a fourth "reserved" one as soon as a main
template firing on it is saved; the day then shows a negative remainder (clamped to 0) and a 4th main task
appears on the day when it is materialized. The invariant the UI advertises ("max 3") is not guaranteed.
**Fix:** Decide the rule and enforce it in one place: either refuse the template save when any future day
within a horizon would exceed the limit, or document that templates may overflow and cap materialization
(materialize as `extra`/skip when the group is full). Add a test for the chosen behavior.

### WR-08: Input coercion in the settings page maps garbage to valid values or throws

**File:** `app/Filament/Pages/Signal/SignalSettingsPage.php:42-44,108`, `resources/views/filament/pages/signal/settings.blade.php:7,14`
**Issue:** (a) `array_map(intval(...), $this->templateWeekdays)` turns any non-numeric string or array
into `0`, which is Monday, so a malformed value silently selects a weekday instead of being dropped (the
Action's comment says "anything that is no weekday index is dropped"). (b) `public int $weekdayBlocks` /
`$weekendBlocks` are bound with `wire:model` to `type="number"`; clearing the field sends an empty string,
and assigning `''` to an `int` typed Livewire property raises a `TypeError` (500) on the next request
instead of the translated `blocks_range` message. (Please confirm (b) against the installed Livewire
version; I could not run the app.)
**Fix:** Filter before casting (`array_filter($this->templateWeekdays, 'is_numeric')`) or pass strings and
let `SaveSignalRecurring` validate, and type the block properties as `int|string|null` (or use
`wire:model.number`) with an explicit `is_numeric` check mapped to `SignalRuleViolation::because('blocks_range')`.

## Info

### IN-01: `SignalDay::day()` writes to the database from a computed read

**File:** `app/Livewire/Signal/SignalDay.php:209-211`, `app/Domain/Signal/Actions/MaterializeSignalRecurring.php:37-45`
**Issue:** Rendering today triggers a write path (Gate check, template query, and, whenever any template
fires today, a transaction with an advisory lock even when nothing is pending). Side effects in a
`#[Computed]` read make the component harder to reason about and are the root of CR-01.
**Fix:** Run materialization from `mount()` / a `hydrate` hook or the page, and check for pending
templates before opening the transaction.

### IN-02: Recap text has no length limit

**File:** `app/Domain/Signal/Actions/SaveSignalRecap.php:33-39`, `database/migrations/2026_10_12_000200_create_signal_weekly_tables.php:44-45`
**Issue:** `what_went_well` / `what_to_change` are unbounded `text`, with no validation in the Action or
the textarea. Every other planner text field has a 255 limit enforced twice (Action and `maxlength`).
**Fix:** Add a limit (for example 5000 characters) in the Action and a matching `maxlength` on the
textareas; optionally a CHECK on `length()`.

### IN-03: Hardcoded Czech names and date format outside the lang files

**File:** `app/Domain/Signal/Support/SignalCalendar.php:25,112-123`
**Issue:** `WEEKDAY_NAMES` and the `j. n. Y` format are literal Czech in a Support class, while the rest of
the feature uses `lang/cs/kokpit.php`. A second locale would need a code change here.
**Fix:** Move the names to `lang/cs/kokpit.php` and format through the translator or `Carbon::isoFormat`.

### IN-04: Magic numbers and small contract gaps

**File:** `app/Domain/Signal/Actions/AuthorizesSignal.php:67`, `app/Domain/Signal/Support/SignalCalendar.php:96`, `app/Domain/Signal/Support/SignalStats.php:152`, `app/Domain/Signal/Casts/WeekdaysCast.php:11-17,33-40`, `app/Domain/Signal/Exceptions/SignalRuleViolation.php:15`
**Issue:** `255` (title length), `400` (range cap, streak cap) are repeated literals. `WeekdaysCast::set()`
is documented as validating the list but accepts an empty list and writes `{}`, which only the database
CHECK `cardinality(weekdays) BETWEEN 1 AND 7` refuses. `SignalRuleViolation::because()` has an untyped
`$replace` parameter (no `array<string, mixed>` docblock). `SignalTask::user()` and `::recurring()` are
unused.
**Fix:** Name the constants, make the cast reject an empty list with the same `InvalidArgumentException`,
type the parameter, remove or use the relations.

### IN-05: A goal added after a delete takes the freed low position

**File:** `app/Domain/Signal/Actions/AddSignalGoal.php:46-52`, `app/Domain/Signal/Actions/DeleteSignalGoal.php:11`
**Issue:** Goals use "lowest free position", tasks use "end of the group". Deleting goal 1 of 3 and adding
a new one puts the new goal on top. It is documented in `DeleteSignalGoal`, but it is inconsistent with the
task behavior and unexpected for a drag-ordered list.
**Fix:** Either renumber to 1..n on delete (`ReorderSignalGoals` already does it) or accept and keep the
documented behavior; pick one rule for both lists.

## Checked and found sound (no finding)

- **Owner scoping:** `OwnerScope` is fail-closed (guest gets `1 = 0`), only an explicit `runAsSystem()`
  bypasses it, and every Signal model uses `OwnedByUser`. `user_id` is not in any `#[Fillable]`, the
  `creating` hook stamps it, and `findOwned()` resolves ids only through the scope, so another user's
  uuid is "not found".
- **Authorization:** all seven models are registered with `AdminOnlyPolicy`; every Action calls
  `authorizeActor()` (Gate plus `Auth::id() === $actor->getKey()`); `SignalDay` uses `RequiresAdmin`
  (boot hook, covers forged `/livewire/update`), the pages use `#[AccessRule]` +
  `EnforcesPageAccessRule`. Tampering with `editingId`, `week`, `forDate` can only reach the signed-in
  user's own rows.
- **Output encoding:** all user text is rendered with `{{ }}`; no `{!! !!}`; ids interpolated into
  `wire:click` are server-generated UUIDs.
- **DB rules:** UUID v7 defaults, `timestampsTz`, composite FK with `ON DELETE SET NULL (recurring_id)`,
  partial unique index for idempotent materialization, `is_done`/`completed_at` check, the deferred
  unique key for goal positions, ISODOW checks, `cardinality`/`<@` weekday check. `down()` drops in
  reverse dependency order. No N+1 found: each screen issues a fixed number of queries per render
  (about 7 per `SignalDay` card, 8 on the overview).
- **WeekdaysCast:** `{0,2,4}` parse/format round trip is correct, input is normalized (sorted, unique),
  dirty-checking is stable because PostgreSQL returns the same canonical literal; `@> ARRAY[?]::smallint[]`
  is parameterized.
- **Calendar:** `today()` is computed in Europe/Prague, date arithmetic runs in UTC on date-only values,
  so DST does not move a day; string comparison of `YYYY-MM-DD` is valid for four-digit years.
- **Fictional data:** no real names, addresses or ids found in the reviewed files.

---

_Reviewed: 2026-10-11_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
