---
phase: quick-261011-cx0
plan: 01
subsystem: signal
tags: [signal, planner, review-fixes, livewire, postgresql, pest]
requires:
  - quick-261011-cqm (code review of the Signal merge)
provides:
  - fixes for CR-01, CR-02, WR-01..WR-08, IN-01..IN-05 and the two failing tests
affects:
  - app/Domain/Signal
  - app/Livewire/Signal
  - app/Filament/Pages/Signal
  - resources/views (Signal day, week, settings)
  - lang/cs/kokpit.php
  - tests/Support/CanaryRegistry.php
key-files:
  created:
    - tests/Feature/Signal/SignalRecurringTest.php
    - tests/Feature/Signal/SignalTamperedInputTest.php
    - tests/Feature/Signal/SignalCalendarLocaleTest.php
  modified:
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Feature/Schema/SignalTablesTest.php
    - tests/Feature/Signal/SignalActionsTest.php
    - tests/Feature/Signal/SignalScreensTest.php
    - tests/Unit/Signal/SignalCalendarTest.php
    - tests/Unit/Signal/SignalRulesTest.php
    - tests/Unit/Signal/SignalStatsTest.php
    - tests/Unit/Signal/WeekdaysCastTest.php
decisions:
  - "CR-01: refuse deleting a task of today that its active template still produces (no tombstone, no migration)"
  - "WR-07: an overflowing template task is materialized as grey (extra); the shadow of a later day shows the same colour"
  - "WR-05: save the changed recap on navigation; a refused save keeps the week and the typed text"
  - "IN-02: 5000-character recap limit in the Action and as maxlength, no database CHECK (would need a migration)"
  - "WR-03: proven by a lock probe on a second connection, not by a multi-process race"
metrics:
  started: 2026-10-11T07:00:00Z
  completed: 2026-10-11T07:50:00Z
  tasks: 3
  commits: 8
plan_head_before: 64b854b197e3db4de9dd9483a33da5142b4d1171
plan_head_after: f6e2d94c7ab4550a1ff3ec38e07d76b7d33be3e2
commits: 8
status: complete
---

# Phase quick-261011-cx0 Plan 01: Fix all findings of the Signal merge code review Summary

All 15 review findings and both failing tests are fixed test-first, without a migration: the recurring task that came back after a delete is now protected, the daily colour limit counts a template once, tampered dates, ids and numbers are refused or normalised instead of ending in a 500, and the data-loss, concurrency and streak edges are closed. Every finding has a Pest regression test.

## Commits

| Commit | Content |
|--------|---------|
| 02c10a7 | style: Pint on three Signal test files that failed `pint --test` before this task (no behaviour change) |
| ef00c1f | Task 1: TEST-01, TEST-02, CR-01, CR-02 |
| aaa6b69 | Task 2: WR-01, WR-02, WR-03, WR-04 |
| 5c7ac97 | Task 3 step A: WR-07, IN-01 |
| 47702c2 | Task 3 step B: WR-05 (and IN-02, because the refused-save case needs the recap limit) |
| d6ac9d3 | Task 3 step C: WR-06 |
| 48f5fa5 | Task 3 step D: WR-08 |
| f6e2d94 | Task 3 step E: IN-03, IN-04, IN-05 and the PHPStan clean-up |

## The two failing tests

| Test | Root cause | Fix |
|------|-----------|-----|
| `SignalTablesTest` "does not let a user with planner rows be deleted" | The user foreign key is `restrictOnDelete`, which PostgreSQL reports as SQLSTATE 23001, not 23503 | The expectation is 23001, with a comment (same as the project billing test, Phase 2 decision 02-07). Migration untouched |
| `CanaryRegistryTest` "lets the Admin read the canary of both clients..." | Each Signal fixture was owned by a fresh Admin, OwnerScope limits even the Admin to own rows, and three Signal models carry no canary text | `CanaryRegistry::plannerOwner()` (one memoised Admin, reset in `prepare()` and `cleanup()`), all seven fixtures use it, a counter-based per-canary Monday map replaces the crc32 derivation, `needles()` names what the Admin must find per model (canary; the Monday for the two models without text; the owner id for the settings). The Admin test acts as the planner owner; Partner tests, `RouteWalkTest` and `TimeLeakTest` are unchanged and green |

## Findings, one by one

"Failed before" means the regression test was run before the fix and failed for the stated reason.

| ID | Fix | Regression test | Failed before |
|----|-----|-----------------|---------------|
| CR-01 | `DeleteSignalTask` refuses (`recurring_pinned`) a task of today whose template is active and fires today; `SignalDayReader::pinnedTaskIds()` is the one rule for the Action and for the view; the trash button is hidden (`deletable` per task). Switched-off or deleted templates, past days and ordinary tasks stay deletable | SignalRecurringTest: refuses to delete..., deletes a recurring task once its template is switched off..., deletes the instance on an unlocked past day, hides the delete button..., does not bring back a task of a switched-off template | Yes (refuse and hide cases); the "stays deletable" cases passed before and pin the boundary |
| CR-02 | `recurringCounts()` reserves only templates that have no task on the day yet | SignalRecurringTest: reserves the slot only until it has a task, counts a materialized template once (move to main), select says one slot left, still reserves a template created after materialization | Yes (limit 1 instead of 3, "zbývá 0") |
| WR-01 | `isValidDay()` requires year 1 or later; `#[Locked]` on `SignalDay::$forDate` and `$heading`; `updatedDate`, `updatedWeek`, `updatedFrom`, `updatedTo` normalise the URL-bound properties | SignalTamperedInputTest (Actions, component 404, locked properties, three pages), SignalCalendarTest (year 0000) | Yes (SQLSTATE 22008, ViewException, InvalidArgumentException at render) |
| WR-02 | `Str::isUuid` guard in `startEdit` and `reorder` | SignalTamperedInputTest: ignores an id that is no UUID | Yes (SQLSTATE 22P02) |
| WR-03 | `SignalLock::take(user, 'settings')` before the first read in `SaveSignalSettings`, plain `first()` afterwards | SignalActionsTest lock probe on a second connection: free before, held after the Action | Yes (lock free after the save) |
| WR-04 | `MaterializeSignalRecurring` reads its templates inside the transaction after the day lock, with a shared row lock | SignalRecurringTest: survives a template deleted or switched off right after the day lock (dataset of two) | Yes (SQLSTATE 23503) |
| WR-05 | `SignalWeekPage::goToWeek` saves a changed recap first (`saveRecapIfChanged`), stays on the week and keeps the text when the save is refused; `loadWeek` only reads and is what mount and `updatedWeek` call | SignalScreensTest: saves the typed recap before the week changes, writes nothing when unchanged, stays on the week when refused | Yes (text replaced by the stored recap) |
| WR-06 | `SignalOverviewReader::mainTaskRows()` and `SignalStats::MAX_STREAK_DAYS = SignalCalendar::MAX_RANGE_DAYS` (400); the overview feeds the streak from the main tasks of that window | SignalScreensTest: 45-day streak, SignalStatsTest: cap | Yes (reported 30) |
| WR-07 | `SignalRules::materializedCategory()`; materialization counts per colour under the day lock and turns an overflowing template task grey; the shadow of a later day shows the same colour and label | SignalRecurringTest: turns the task grey, keeps the colour while a slot is free, fills the slots in template order, shadow colour; SignalRulesTest | Yes (four main tasks) |
| WR-08 | Block properties are `int\|string\|null`, `blockCount()` maps anything that is no whole number to the `blocks_range` message; ticked weekdays are filtered with `FILTER_VALIDATE_INT` before the cast | SignalTamperedInputTest: seven datasets for block counts, numeric string saved, garbage weekdays dropped | Yes (TypeError for text, silent default 3 for an empty field, `['abc']` stored as Monday) |
| IN-01 | Materialization runs once in `SignalDay::mount()` for today, never from the computed `day()`; a pending check (existing task ids against active templates firing today) runs before any transaction | SignalRecurringTest: materializes at mount and never while rendering, nothing for another day, no transaction and no lock query when nothing is pending (`DB::listen`, `TransactionBeginning`) | Yes |
| IN-02 | `SignalRules::MAX_RECAP_LENGTH = 5000`, checked with `mb_strlen` after trimming in `SaveSignalRecap`, `maxlength` on both textareas, `signal.errors.recap_too_long` | SignalActionsTest: 5000 kept (multi-byte), 5001 refused in either field; SignalScreensTest: both textareas carry the limit | Yes |
| IN-03 | Weekday names in `lang/cs/kokpit.php` (`signal.calendar.weekdays`), `formatLong` uses the translator, `format` uses `LocalisationServiceProvider::DATE_FORMAT` | SignalCalendarLocaleTest (new, Feature), the `format` case stays in the Unit file | Only the key-exists case failed (the output text is unchanged by design) |
| IN-04 | `SignalRules::MAX_TITLE_LENGTH` in the Action, the message (`:max`) and all five `maxlength` attributes; `WeekdaysCast::format([])` throws; `SignalRuleViolation::because()` has a typed `$replace`; unused `SignalTask::user()` and `recurring()` removed (repository-wide search found no caller) | SignalActionsTest (255/256 and empty list on the model), WeekdaysCastTest, SignalScreensTest (no view hard-codes the limit) | Yes (empty list, constant) |
| IN-05 | `DeleteSignalGoal` runs under the goals lock and renumbers the remaining goals to 1..n; `AddSignalGoal` keeps the lowest-free rule as the safety net | SignalActionsTest: renumbers on delete, a new goal lands last; the existing "lowest free position" test (delete through the model) is unchanged and green | Yes |

## Decisions (as taken in the plan, all held)

- CR-01: refusal instead of a tombstone. The user skips a recurring task of today by switching its template off in the settings (the new message says so).
- WR-07: the template save is not refused; the limit is enforced where rows are created, now including materialization.
- WR-05: save on navigation instead of a confirmation dialog.
- IN-02: limit without a database CHECK, because a CHECK needs a migration.
- WR-03: lock probe instead of a multi-process race (PostgreSQL has no gap lock, so the failure cannot be produced deterministically in one process; the probe shows that the Action takes the lock).
- No migration was needed or added (`git diff 64b854b..HEAD -- database/migrations` is empty).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pint failed on four Signal test files before this task**
- **Found during:** Task 1 verify (`pint --test`)
- **Issue:** `SignalTablesTest`, `SignalActionsTest`, `SignalScreensTest`, `SignalIsolationTest` used fully qualified names and unordered imports (rules `fully_qualified_strict_types`, `ordered_imports`)
- **Fix:** ran Pint on them; the three files this task does not otherwise change went into their own commit 02c10a7 (style only), `SignalTablesTest` went into the Task 1 commit
- **Commit:** 02c10a7, ef00c1f

**2. [Rule 3 - Blocking] 14 PHPStan errors existed before this task (plus two of my own that I fixed on the spot)**
- **Found during:** Task 1 verify (`composer stan` was red at the base commit; the plan requires it clean)
- **Issue:** list return types (`values()->all()` is `array<int, ...>`), `array_values` that has no effect, a `WeekdaysCast::format` parameter type, an untyped `$replace`, array shape of `SignalStats::categoryProgress`, an always-true comparison in `mainStreak`
- **Fix:** `array_values(...)` around the collection results, `array<int, string>` for the `$orderedIds` parameter of the two reorder Actions (the `array_values` inside stays as a safety net), `array<int|string>` for the cast, a row copy in `categoryProgress`, the redundant `total > 0` removed (an entry only exists with a total of at least 1)
- **Commit:** f6e2d94 (and the `pinnedTaskIds` return type in ef00c1f)

**3. [Rule 1 - Bug in the plan's test approach] WR-08 tests send the update and the call in one request**
- **Found during:** Task 3 step D
- **Issue:** Livewire 4.4.7 does not keep a null in a typed property between two requests (`hydrateProperties` skips null for a typed property, so it falls back to the default 3), and Laravel's `ConvertEmptyStringsToNull` turns the browser's empty string into null. A test that does `->set(...)` and then `->call(...)` therefore never sees the empty value
- **Fix:** the tests use `Testable::update(calls: ..., updates: ...)`, which is what the browser does on submit (deferred model values and the call in one request). Production behaviour is as planned: empty, blank, text, decimal, null, negative and too-large values give the `blocks_range` message
- **Commit:** 48f5fa5

**4. [Rule 3 - Ordering] IN-02 landed in step B instead of step E**
- The refused-save case of WR-05 needs the recap limit, so `SignalRules::MAX_RECAP_LENGTH`, the Action check, the message and the `maxlength` came with step B (47702c2). `MAX_TITLE_LENGTH` was added in the same constants block and used in step E.

**5. `startEdit` with a non-UUID dispatches nothing**
- The plan said both methods "still dispatch the refresh the existing not-found path dispatches". The existing not-found path of `startEdit` returns without dispatching (only `reorder` dispatches), so `startEdit` keeps that and `reorder` dispatches, as before.

## Verification

Run inside DDEV on 2026-10-11:

- `pest tests/Unit tests/Feature/Signal tests/Isolation tests/Arch`: **793 passed** (8150 assertions).
- `composer stan`: **[OK] No errors**.
- `pint --test`: **PASS** (583 files).
- `scripts/check-sensitive.sh`: clean; lefthook (sensitive-content, gitleaks) ran on every commit, never bypassed.
- Full `pest` suite: **2598 passed, 8 failed** (2606 tests, 218 s). The 8 failures are **not related to this task**:
  - `Tests\Feature\Repo\ZeropsConfigTest` (3): the manifest describes the setup `kokpit` and a `nodejs` build base, the test expects exactly `backend` and no frontend runtime
  - `Tests\Feature\Repo\DeployWorkflowTest` (4) and `Tests\Feature\Repo\RepositoryFilesTest` (1, service id variable): the deploy workflow and its documentation no longer match their tests
  - They come from the recent commits `5534e71` (fix(deployment): update Zerops setup and workflow variables) and `64b854b` (add Node.js support to the Zerops build). None of the files they test (zerops manifest, workflow, repo docs) was touched by this task. Not fixed here (out of scope); they need a decision on whether the tests or the manifest change.

## Known Stubs

None.

## Threat Flags

None. The new reader queries (`pinnedTaskIds`, `recurringCounts`, `mainTaskRows`) go through the owner-scoped models like the existing readers; no new endpoint, auth path or schema change. The isolation tests (Signal and the canary registry) stay green.

## Open items

- The 8 unrelated Zerops and deploy-workflow test failures listed above.
- Nothing from the review is left open.

## Self-Check: PASSED

- Created files exist: `tests/Feature/Signal/SignalRecurringTest.php`, `tests/Feature/Signal/SignalTamperedInputTest.php`, `tests/Feature/Signal/SignalCalendarLocaleTest.php`
- All eight commits are ancestors of HEAD (`git rev-list --count 64b854b..HEAD` = 8)
- `database/migrations` unchanged; the uncommitted `.planning/config.json`, `.planning/state.json`, `.planning/milestone.lock` were not touched
