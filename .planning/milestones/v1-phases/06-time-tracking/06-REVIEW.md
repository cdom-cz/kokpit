---
phase: 06-time-tracking
reviewed: 2026-10-09T12:00:00Z
depth: standard
files_reviewed: 62
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Console/Commands/DeployVerifyCommand.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Shared/Database/CzechCollation.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Tasks/Models/Task.php
  - app/Domain/TimeTracking/Actions/DeleteTimeEntry.php
  - app/Domain/TimeTracking/Actions/SetTimePanelOpen.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Billing/EntryRate.php
  - app/Domain/TimeTracking/Billing/RateSource.php
  - app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php
  - app/Domain/TimeTracking/Models/TimeEntry.php
  - app/Domain/TimeTracking/Queries/EntryContextOptions.php
  - app/Domain/TimeTracking/Queries/OverlapFinder.php
  - app/Domain/TimeTracking/Queries/TimeTotals.php
  - app/Domain/TimeTracking/Queries/TimesheetQuery.php
  - app/Domain/TimeTracking/Support/DurationFormat.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Filament/Concerns/ManagesTaskBoard.php
  - app/Filament/Pages/TimesheetPage.php
  - app/Filament/RelationManagers/TimeEntryHistoryRelationManager.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTasksTimeRelationManager.php
  - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTimeEntriesRelationManager.php
  - app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
  - app/Filament/Resources/TimeEntryResource.php
  - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
  - app/Filament/Resources/TimeEntryResource/Pages/ViewTimeEntry.php
  - app/Livewire/TimeTracking/CompletesRunningEntry.php
  - app/Livewire/TimeTracking/RecentEntriesPanel.php
  - app/Livewire/TimeTracking/TimerBar.php
  - config/kokpit.php
  - database/migrations/2026_10_11_000200_add_time_panel_open_to_users_table.php
  - lang/cs/kokpit.php
  - resources/views/filament/pages/task-board.blade.php
  - resources/views/filament/pages/timesheet-week-footer.blade.php
  - resources/views/filament/resources/project-resource/tasks-time-footer.blade.php
  - resources/views/livewire/time-tracking/recent-entries-panel.blade.php
  - resources/views/livewire/time-tracking/timer-bar.blade.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Feature/Operations/DeployVerifyCommandTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/TimeEntriesTableTest.php
  - tests/Feature/TimeTracking/BillableDefaultTest.php
  - tests/Feature/TimeTracking/BillingLockTest.php
  - tests/Feature/TimeTracking/CzechOrderingTest.php
  - tests/Feature/TimeTracking/EntryRateResolverTest.php
  - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
  - tests/Feature/TimeTracking/RecentEntriesPanelTest.php
  - tests/Feature/TimeTracking/TaskStartAffordancesTest.php
  - tests/Feature/TimeTracking/TimeEntryActionsTest.php
  - tests/Feature/TimeTracking/TimeEntryListTest.php
  - tests/Feature/TimeTracking/TimeEntryResourceTest.php
  - tests/Feature/TimeTracking/TimerActionsTest.php
  - tests/Feature/TimeTracking/TimerBarTest.php
  - tests/Feature/TimeTracking/TimesheetTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Support/CanaryRegistry.php
  - tests/Unit/TimeTracking/DurationFormatTest.php
findings:
  critical: 0
  warning: 4
  info: 6
  total: 10
status: issues_found
---

# Phase 06: Code Review Report

**Reviewed:** 2026-10-09T12:00:00Z
**Depth:** standard
**Files Reviewed:** 62 (the evaluation-scope `files` list). Classes of the phase that the resolver lists as outside the union (`StartTimer`, `StopTimer`, `TimerLock`, `MarkEntriesBilled`, `CancelEntriesBilling`, the migration, `ControlsTimer`, `RequiresAdmin`, `TaskTimerToggle`, the job and the notification) were read as context for the cross-file checks.
**Status:** issues_found

## Summary

The phase is carefully built. The areas named for extra attention hold up under inspection:

- **Partner isolation.** `TimeEntry` denies Partners at the data layer (`DeniesPartners`), every Filament class that shows time carries `AccessRule(AdminOnly)`, the two Livewire components refuse non-Admins in a `boot` trait hook (verified in the installed Livewire: `callTraitHook('boot')` runs on mount and on every hydrate, before any action method), `Activity` is also `DeniesPartners`, and `TimeEntryRateResolver` refuses everyone but the Admin or a system run. No path from a Partner to measured time, rates or prices was found.
- **Billed-row immutability.** The three layers agree. The guard trigger compares `to_jsonb(NEW) - allowed` with `to_jsonb(OLD) - allowed`, `updated_at` is always in the allowed list, `billable` and the instants are not, and the Actions re-read the row under `FOR UPDATE` before refusing.
- **Timer concurrency.** `pg_advisory_xact_lock(hashtextextended(...))` is taken first, inside the transaction, the clock is read after the lock, and the partial unique index is translated to `TimerRaceLost` only after the rollback. No lock-order cycle was found.
- **XSS.** The bell notification escapes the client name once with `e()` and Filament renders notification title and body through `sanitizeHtml()` (verified in the installed Filament); the callout heading and all Blade output use `{{ }}`; the `HtmlString` in `timeRange()` escapes its text. No unescaped user text reaches a notification.
- **SQL outside bindings.** Every raw fragment is a constant or a bound parameter; `CzechCollation` wraps the column through the grammar and uses a constant collation name.

What was found: a stale-modal overwrite of the running entry, missing bounds on instants that lets a typo wedge the timer through an integer overflow in the database, a year-0000 URL or filter value that produces a 500 on the timesheet, and a start/stop inversion on the task cards when the icon is stale. Six smaller items follow.

## Warnings

### WR-01: "Doplnit záznam" modal applies the form to whichever entry is running at submit time

**File:** `app/Livewire/TimeTracking/CompletesRunningEntry.php:44-64`
**Issue:** The modal is filled from the running entry when it opens (`fillForm`), but the action then re-reads `$this->runningEntry()` at submit (line 51) and calls `UpdateTimeEntry` on that row. `->record(...)` is also a closure, so it is re-evaluated each time. If the entry shown in the modal is stopped and another one is started between opening and submitting (a second tab, the task board toggle, the side panel, which is a second Livewire component with its own modal), the data of the old entry A (client, project, task, description, `started_at`, billable) is written over the new running entry B. B then silently gets A's start instant, which corrupts tracked time and can cross the one-running-entry intent of the user. `StopTimer` already protects itself against exactly this with `$expectedEntryId`; the modal does not.
**Fix:** Pin the entry the modal was opened for and refuse a mismatch.
```php
->mountUsing(function (Schema $form, Action $action): void {
    $entry = $this->runningEntry();
    $action->arguments(['entry' => $entry?->getKey()]); // or keep it in a Hidden field of the schema
    // fillForm as before
})
->action(function (array $data, Action $action): void {
    $entry = $this->runningEntry();
    $expected = $action->getArguments()['entry'] ?? null;

    if (! $entry instanceof TimeEntry || $entry->getKey() !== $expected) {
        Notification::make()->info()->title(__('kokpit.time.timer.nothing_running'))->send();
        $this->dispatch('timer-stopped');
        $action->halt();

        return;
    }
    // ...
```

### WR-02: Instants have no bounds, so a typo can overflow `integer` in the database and wedge the timer

**File:** `app/Domain/TimeTracking/TimeEntryInput.php:150-178` (no range check), `database/migrations/2026_10_11_000100_create_time_entries_table.php:66` (`::integer`), `app/Domain/TimeTracking/Queries/TimeTotals.php:33,38` and `app/Domain/TimeTracking/Models/TimeEntry.php:108` (`::int`)
**Issue:** `CreateTimeEntry` documents "no cap on the duration", and `instant()` accepts any parsable date. `duration_seconds` is `(EXTRACT(EPOCH ...))::integer` and the running-entry elapsed time is `EXTRACT(EPOCH ...)::int`. Both overflow past about 68 years (2,147,483,647 s). Consequences:
- A finished entry whose span exceeds 68 years (for example a start typed as 1958 instead of 2026) fails on INSERT/UPDATE with SQLSTATE 22003, an unhandled `QueryException`, so the user gets a 500 instead of a field error.
- Worse, the running entry can be edited through "Doplnit záznam" (or the edit page) to a start before about 1958. That save succeeds (the duration is NULL while running), but afterwards every query that selects `elapsed_seconds` (the entry list, the view and edit pages through `getEloquentQuery()`, `TimeTotals` on the task and project pages) raises `integer out of range`, and `StopTimer` itself fails because setting `ended_at` recomputes the generated column. The timer cannot be stopped and the time screens stay broken until the start is corrected in the database or through the modal (which does not use the elapsed scope).
**Fix:** Validate the instants in one place and cast wider in SQL.
```php
// TimeEntryInput::normalised(): refuse anything outside a sane window
private const int MIN_YEAR = 2000;
private const int MAX_YEAR = 2100;

if ($instant->year < self::MIN_YEAR || $instant->year > self::MAX_YEAR) {
    throw self::error($field, 'invalid_time');
}
```
and use `::bigint` in the elapsed expressions (`TimeEntry::scopeWithElapsedSeconds`, `TimeTotals::ELAPSED`, `ELAPSED_OF_ENTRY`); the generated column needs a migration to `bigint` if long spans are meant to be legal, otherwise add a CHECK on the span and map it to a field error. Add a test with a 1950 start.

### WR-03: A year-0000 date in the URL or a filter produces an invalid SQL timestamp and a 500

**File:** `app/Domain/TimeTracking/Queries/TimesheetQuery.php:56-79,91` (`normalizeDate`, `dayRange`, `weekRange`), `app/Filament/Resources/TimeEntryResource.php:570-594` (`pragueMidnight`, `day`)
**Issue:** `normalizeDate()` accepts any `YYYY-MM-DD` that round-trips through `createFromFormat`, and `0000-01-01` does. `dayRange('0000-01-01')` then yields the UTC instant `-0001-12-31 23:02:16+00:00` (verified by running the Carbon code), which PostgreSQL rejects (`date/time field value out of range`; there is no year zero), so `?date=0000-01-01` (and the week range of any date in year 0001's first days) throws a `QueryException` in `getViewData()` on every request. The page's comment promises that anything else falls back to today, and `TimesheetTest` pins arrays and impossible dates but not this. The same value reaches `TimeEntryResource::periodBounds()` through the custom period filter (`day()` has the same round-trip check) and is bound into `whereRaw('... >= ?::timestamptz')`. It is an authenticated Admin-only 500, not a data exposure, but it is a robustness gap in exactly the input the page claims to normalise.
**Fix:** Bound the accepted year in both normalisers.
```php
if ((int) substr($date, 0, 4) < 1970 || (int) substr($date, 0, 4) > 2100) {
    return $this->today($now);
}
```
and the same guard in `TimeEntryResource::day()`. Add `'year zero' => ['date=0000-01-01']` to the bad-URL dataset.

### WR-04: Stale start/stop icon on the board cards and task rows inverts the intended action

**File:** `app/Filament/Concerns/ManagesTaskBoard.php:214-232`, `app/Filament/Support/TaskTimerToggle.php:47-54`, `resources/views/filament/pages/task-board.blade.php:79-88`
**Issue:** `toggleTimer($taskId)` sends only the task id and the server decides start versus stop from the current state. The board never listens to `timer-started` / `timer-stopped` / `time-entry-saved` (no `#[On]` handler, no poll), so after the timer is stopped from the top bar or the side panel, the card of that task keeps its stop icon. Clicking the stop icon now finds nothing running on that task and therefore starts a new timer on it (and stops whatever else runs). The user asked for the opposite of what happened. The same holds for the `TaskResource` row action, whose label comes from a per-request cache. `StopTimer` was given an expected-entry id to avoid exactly this kind of confusion; the toggle path was not.
**Fix:** Send the intent with the click and ignore a stale one.
```php
// blade: wire:click="toggleTimer('{{ $card['id'] }}', {{ $running ? 'true' : 'false' }})"
public function toggleTimer(string $taskId, bool $wasRunning = false): void
{
    // ... same lookups ...
    $event = app(TaskTimerToggle::class)->handle($user, $task, expectRunning: $wasRunning);
}
// TaskTimerToggle::handle(): if the state differs from $expectRunning, do nothing and only refresh.
```
and add `#[On('timer-started')] #[On('timer-stopped')] #[On('time-entry-saved')]` refresh handlers to the board trait so the icon follows the other surfaces.

## Info

### IN-01: Server-owned Livewire properties of the side panel are client-writable

**File:** `app/Livewire/TimeTracking/RecentEntriesPanel.php:40,43`
**Issue:** `$visibleDays` and `$open` are only ever changed by the server (`loadOlder()`, `mount()`, `setOpen()`), yet they are plain public properties, so a forged update can set `visibleDays` to any integer. A huge value makes `RecentEntries::days()` read every day of history; `PHP_INT_MAX` makes `$days + 1` a float and `limit()` throws a `TypeError`. Only the Admin can do this (`RequiresAdmin`), so it is a hardening item, but `ProjectTimeStats` already uses `#[Locked]` for its record.
**Fix:** Mark both with `#[Locked]` (server-side mutation stays allowed) and clamp in `RecentEntries::days()` (`min(100, max(1, $days))`).

### IN-02: Unused and unvalidated `$before` cursor in `RecentEntries::days()`

**File:** `app/Domain/TimeTracking/Queries/RecentEntries.php:43,50`
**Issue:** The only caller passes `null`. If the parameter is wired to a browser value later, `->when($before, ...)` treats `"0"` as absent and `CarbonImmutable::parse($cursor)` accepts relative text such as `"tomorrow"` or throws on garbage. It is dead code today and an untrusted-input trap tomorrow.
**Fix:** Remove the parameter, or validate it with the same `Y-m-d` round-trip as `TimesheetQuery::normalizeDate()` before use.

### IN-03: The Prague day is defined in four places, and not through the panel timezone

**File:** `app/Domain/TimeTracking/Queries/TimesheetQuery.php:32,162,167`, `app/Domain/TimeTracking/Queries/RecentEntries.php:35,51`, versus `app/Filament/Resources/TimeEntryResource.php:538-577` (uses `FilamentTimezone::get()`)
**Issue:** The timesheet and the side panel hard-code `Europe/Prague` in two PHP constants and three SQL literals, while the list filters and the bar use `FilamentTimezone::get()`. Today both are Prague; if the panel timezone is ever changed, the filter, the timesheet and the panel would bucket the same entry into different days.
**Fix:** One `PragueDay` helper (constant plus a bound `AT TIME ZONE ?`) used by all four places, or read the zone from `FilamentTimezone` everywhere and bind it in the SQL.

### IN-04: `TimeEntryRateResolver` documents an exception it never throws

**File:** `app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php:49,17`
**Issue:** The docblock says `@throws LogicException when the project of a task entry has no billing row`, and the `LogicException` import exists for it, but the code only reads `$project?->billing?->hourly_rate` and falls through silently. A caller relying on the documented failure would never see it.
**Fix:** Either throw as documented for a task entry whose project has no billing row, or drop the `@throws` line and the import.

### IN-05: Copy-pasted blocks that will drift

**File:** `app/Livewire/TimeTracking/ControlsTimer.php:107-118,196-205` and `app/Filament/Support/TaskTimerToggle.php:67-84,98-106`; `app/Domain/TimeTracking/Actions/MarkEntriesBilled.php` and `CancelEntriesBilling.php` (`selection()`); `TimesheetQuery::dayRange/monday` and `RecentEntries::dayStart`
**Issue:** The start toast (title plus "previous timer kept" body), `firstMessage()`, the locked `selection()` query and the Prague day arithmetic exist twice each. A change to one copy (for example the toast wording or the lock order) will not reach the other.
**Fix:** Extract the toast and `firstMessage()` into one small presenter used by both timer surfaces, share the selection query through a trait or a query class, and see IN-03 for the day arithmetic.

### IN-06: UUID comparisons are case-sensitive after a case-insensitive validity check

**File:** `app/Domain/TimeTracking/TimeEntryInput.php:78-94,255-266`, `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php:100-108`
**Issue:** `Str::isUuid()` accepts upper-case input and PostgreSQL resolves it, but `context()` compares ids with `!==` against the stored lower-case keys. A forged or API-supplied upper-case project or client id for an otherwise consistent combination is reported as `inconsistent_context`, and `contextChanged()` treats an upper-case copy of the stored id as a change and re-runs the full context validation. Phase 7 sends API input through this class, where mixed-case ids are plausible.
**Fix:** Normalise in `TimeEntryInput::id()` (`return strtolower($value);`).

---

_Reviewed: 2026-10-09T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
