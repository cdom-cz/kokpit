---
phase: 06-time-tracking
verified: 2026-10-09T18:00:00Z
status: human_needed
score: 5/5 must-haves verified
covered_files:
  - .planning/phases/06-time-tracking/06-01-PLAN.md
  - .planning/phases/06-time-tracking/06-01-SUMMARY.md
  - .planning/phases/06-time-tracking/06-02-PLAN.md
  - .planning/phases/06-time-tracking/06-02-SUMMARY.md
  - .planning/phases/06-time-tracking/06-03-PLAN.md
  - .planning/phases/06-time-tracking/06-03-SUMMARY.md
  - .planning/phases/06-time-tracking/06-04-PLAN.md
  - .planning/phases/06-time-tracking/06-04-SUMMARY.md
  - .planning/phases/06-time-tracking/06-05-PLAN.md
  - .planning/phases/06-time-tracking/06-05-SUMMARY.md
  - .planning/phases/06-time-tracking/06-06-PLAN.md
  - .planning/phases/06-time-tracking/06-06-SUMMARY.md
  - .planning/phases/06-time-tracking/06-07-PLAN.md
  - .planning/phases/06-time-tracking/06-07-SUMMARY.md
  - .planning/phases/06-time-tracking/06-08-PLAN.md
  - .planning/phases/06-time-tracking/06-08-SUMMARY.md
  - .planning/phases/06-time-tracking/06-09-PLAN.md
  - .planning/phases/06-time-tracking/06-09-SUMMARY.md
  - .planning/phases/06-time-tracking/06-10-PLAN.md
  - .planning/phases/06-time-tracking/06-10-SUMMARY.md
  - .planning/phases/06-time-tracking/06-11-PLAN.md
  - .planning/phases/06-time-tracking/06-11-SUMMARY.md
  - .planning/phases/06-time-tracking/06-12-PLAN.md
  - .planning/phases/06-time-tracking/06-12-SUMMARY.md
  - .planning/phases/06-time-tracking/06-13-PLAN.md
  - .planning/phases/06-time-tracking/06-13-SUMMARY.md
  - .planning/phases/06-time-tracking/06-14-PLAN.md
  - .planning/phases/06-time-tracking/06-14-SUMMARY.md
  - app/Domain/TimeTracking/Actions/CancelEntriesBilling.php
  - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
  - app/Domain/TimeTracking/Actions/DeleteTimeEntry.php
  - app/Domain/TimeTracking/Actions/MarkEntriesBilled.php
  - app/Domain/TimeTracking/Actions/SetTimePanelOpen.php
  - app/Domain/TimeTracking/Actions/StartTimer.php
  - app/Domain/TimeTracking/Actions/StopTimer.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Billing/BillableDefault.php
  - app/Domain/TimeTracking/Billing/EntryRate.php
  - app/Domain/TimeTracking/Billing/RateSource.php
  - app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php
  - app/Domain/TimeTracking/Enums/BillingBadge.php
  - app/Domain/TimeTracking/Enums/BillingState.php
  - app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php
  - app/Domain/TimeTracking/Models/TimeEntry.php
  - app/Domain/TimeTracking/Notifications/LongRunningTimerNotification.php
  - app/Domain/TimeTracking/Queries/EntryContextOptions.php
  - app/Domain/TimeTracking/Queries/OverlapFinder.php
  - app/Domain/TimeTracking/Queries/RecentEntries.php
  - app/Domain/TimeTracking/Queries/TimeTotals.php
  - app/Domain/TimeTracking/Queries/TimesheetQuery.php
  - app/Domain/TimeTracking/Support/DurationFormat.php
  - app/Domain/TimeTracking/Support/TimerClock.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Domain/TimeTracking/TimerLock.php
  - app/Domain/TimeTracking/TimerRaceLost.php
  - app/Filament/Pages/TimesheetPage.php
  - app/Filament/RelationManagers/TimeEntryHistoryRelationManager.php
  - app/Filament/Resources/ProjectResource/Pages/CreateProject.php
  - app/Filament/Resources/ProjectResource/Pages/EditProject.php
  - app/Filament/Resources/ProjectResource/Pages/ListProjects.php
  - app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php
  - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
  - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTasksTimeRelationManager.php
  - app/Filament/Resources/ProjectResource/RelationManagers/ProjectTimeEntriesRelationManager.php
  - app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php
  - app/Filament/Resources/TimeEntryResource.php
  - app/Filament/Resources/TimeEntryResource/Pages/CreateTimeEntry.php
  - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
  - app/Filament/Resources/TimeEntryResource/Pages/ListTimeEntries.php
  - app/Filament/Resources/TimeEntryResource/Pages/ViewTimeEntry.php
  - app/Filament/Support/TaskTimerToggle.php
  - app/Livewire/TimeTracking/CompletesRunningEntry.php
  - app/Livewire/TimeTracking/ControlsTimer.php
  - app/Livewire/TimeTracking/RecentEntriesPanel.php
  - app/Livewire/TimeTracking/RequiresAdmin.php
  - app/Livewire/TimeTracking/TimerBar.php
  - app/Providers/Filament/AdminPanelProvider.php
  - database/migrations/2026_10_11_000100_create_time_entries_table.php
  - database/migrations/2026_10_11_000200_add_time_panel_open_to_users_table.php
  - resources/views/filament/pages/timesheet.blade.php
  - resources/views/livewire/time-tracking/recent-entries-panel.blade.php
  - resources/views/livewire/time-tracking/timer-bar.blade.php
  - routes/console.php
  - tests/Concurrency/SequenceAllocatorConcurrencyTest.php
  - tests/Concurrency/TaskBoardConcurrencyTest.php
  - tests/Concurrency/TaskNumberConcurrencyTest.php
  - tests/Concurrency/TimerConcurrencyTest.php
  - tests/Concurrency/task-worker.php
  - tests/Concurrency/timer-worker.php
  - tests/Concurrency/worker.php
  - tests/Feature/Schema/TimeEntriesTableTest.php
  - tests/Feature/TimeTracking/BillableDefaultTest.php
  - tests/Feature/TimeTracking/BillingLockTest.php
  - tests/Feature/TimeTracking/CzechOrderingTest.php
  - tests/Feature/TimeTracking/EntryRateResolverTest.php
  - tests/Feature/TimeTracking/LongRunningTimerTest.php
  - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
  - tests/Feature/TimeTracking/RecentEntriesPanelTest.php
  - tests/Feature/TimeTracking/TaskStartAffordancesTest.php
  - tests/Feature/TimeTracking/TimeEntryActionsTest.php
  - tests/Feature/TimeTracking/TimeEntryListTest.php
  - tests/Feature/TimeTracking/TimeEntryResourceTest.php
  - tests/Feature/TimeTracking/TimerActionsTest.php
  - tests/Feature/TimeTracking/TimerBarTest.php
  - tests/Feature/TimeTracking/TimesheetTest.php
  - tests/Isolation/TimeLeakTest.php
covered_digest: "v3:sha256:89e11a93056fe34a85c12ed1d9d3a0a299735a11f1878ff3c997e590ee6dc423"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Timer persists across SPA navigation. Start a timer from the top bar, then navigate between several panel pages (client, project, task, timesheet) without a full reload."
    expected: "The running pill stays in the top bar on every page, the elapsed time keeps counting and no second timer markup appears."
    why_human: "Persistence of the persisted end region of the top bar under Filament SPA mode (wire:navigate) is browser behaviour; the tests render each page server-side only."
  - test: "Side panel 'Poslední záznamy' docking. Open and close the panel on a wide screen and on a narrow one, reload, and sign in again."
    expected: "The panel docks at the end of the layout row next to the page content, does not cover the page, and the open/closed state is remembered per user."
    why_human: "Layout docking and overlap are visual; the per-user toggle persistence is proven by tests, the visual behaviour is not."
  - test: "375px layout. Open the top bar timer dropdown, the entry list, the timesheet day and week views and a board card at a 375px wide viewport."
    expected: "No horizontal clipping of the timer controls, the start icon on a board card is a comfortable touch target and is not mistaken for a drag handle, tables scroll or collapse legibly."
    why_human: "Responsive layout and touch target size cannot be asserted from rendered HTML."
  - test: "Contrast and state colours. Inspect the running pill, the forgotten-timer state, the danger text for 'Překročeno o', the billed/locked badges, in light and dark mode."
    expected: "Text and badges meet WCAG AA contrast; the forgotten-timer state is distinguishable without colour alone."
    why_human: "Computed contrast and visual distinguishability need a rendered browser."
  - test: "Two-click start and visible stop from a task, end to end in the browser (task page, list row, board card)."
    expected: "One click starts the timer and the icon turns into stop; a second click stops it. Starting while another timer runs stops it and says so in the toast."
    why_human: "Click counts and live icon state through Livewire are interaction behaviour; the Actions and component handlers are covered by tests. See WR-04 for the stale-icon case to try (stop from the top bar, then click the stale card icon)."
  - test: "Forgotten-timer notice in a real session. Leave a timer running past the threshold (kokpit.time.long_running_hours, 12) with the scheduler active."
    expected: "Exactly one bell notice per running entry appears with a link to the entry, the timer keeps running, the top-bar timer shows the forgotten state."
    why_human: "The five-minute schedule wiring is verified in code and the job in tests, but the bell and top bar render in a real browser session."
---

# Phase 6: Time Tracking Verification Report

**Phase Goal:** Admin tracks exact time against clients, projects and tasks with a timer or manual entries and always knows what is billable, billed and unbilled
**Verified:** 2026-10-09T18:00:00Z
**Status:** human_needed
**Re-verification:** No - initial verification

## Goal Achievement

All five ROADMAP success criteria are backed by code in the repository and by tests that I ran myself (not SUMMARY claims). The remaining open items are browser-only checks, listed under `human_verification`. No gap blocks the phase goal.

### Test evidence (run by the verifier in DDEV, shared `kokpit_test` database)

| Run | Result |
| --- | ------ |
| `pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Isolation tests/Unit/TimeTracking` | 586 passed, 3399 assertions, 0 failed |
| `pest tests/Concurrency/TimerConcurrencyTest.php` (8 real processes x 25 starts, plus the mutation run without the lock) | 4 passed, 180 assertions |
| `scripts/check-sensitive.sh` | exit 0 |
| Debt-marker scan (TBD, FIXME, XXX, TODO, HACK, PLACEHOLDER) over all Phase 6 app, database, resources, lang and routes files | none found |

I did not re-run the full 2469-test suite claimed by 06-14; the Phase 6 surface and the isolation suite were run in full.

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
| - | ----- | ------ | -------- |
| 1 | A timer is visible on every screen and can be started from a task in at most two clicks; starting stops the running one; concurrent starts never leave two running timers | VERIFIED (browser persistence listed for humans) | `AdminPanelProvider.php` registers `TimerBar` at `PanelsRenderHook::GLOBAL_SEARCH_AFTER` (and `RecentEntriesPanel` at `LAYOUT_END`) for the Admin on every panel page. One-click start/stop: `TaskTimerToggle` used by the task page header action, the task list row action and board cards (`TaskStartAffordancesTest`). `StartTimer` takes `TimerLock` (`pg_advisory_xact_lock`), reads the clock after the lock, stops the running entry at the same instant and starts the new one in one transaction; the DB backstop is `time_entries_one_running_per_user` (partial unique index on `user_id WHERE ended_at IS NULL`) and a lost race surfaces as `TimerRaceLost`. `TimerConcurrencyTest` ran 8 real processes: exactly one running entry, gap-free chain; the mutation run without the lock confirms the index is the backstop. |
| 2 | Admin creates/edits entries manually, can save an entry with only a client, and the database rejects entries whose client/project/task disagree or whose end is before the start | VERIFIED | `CreateTimeEntry`/`UpdateTimeEntry`/`TimeEntryResource` exist and are tested (`TimeEntryActionsTest`, `TimeEntryResourceTest`). Migration `2026_10_11_000100_create_time_entries_table.php` carries composite FKs `(project_id, client_id)` and `(task_id, project_id)`, CHECK `task_id IS NULL OR project_id IS NOT NULL`, CHECK `ended_at IS NULL OR ended_at >= started_at`, `client_id` NOT NULL. `TimeEntriesTableTest` proves each by SQLSTATE. Wording note: the DB accepts `ended_at = started_at` (a zero-length entry, needed so that a start in the same second stops the previous timer at the same instant); the application layer (`TimeEntryInput::assertEndAfterStart`) requires manual ends to be strictly later. Roadmap says "end is before the start" which matches the DB rule exactly. See Owner Decision below. |
| 3 | Billable defaults to true, is pre-set to false for non-billable projects/tasks and can be overridden; entries marked billed manually or in bulk are locked until billing is cancelled; durations are exact seconds; effective rate resolves task, project, client, global default | VERIFIED | `BillableDefault` (D-03, via `TaskBillingResolver`, explicit caller value wins) tested in `BillableDefaultTest`. `MarkEntriesBilled`/`CancelEntriesBilling` (single and bulk, row locks, audit) and the generic guard trigger `Immutability::guardTriggerSql('time_entries', 'billing_state', ...)` freeze billed rows at the database; `BillingLockTest` passes. `duration_seconds` is a stored generated column from two `timestamptz(0)` instants, writers truncate through `TimerClock`; `DurationFormat` truncates, never rounds (`DurationFormatTest`). `TimeEntryRateResolver`: task (with parent task) then project then client then global default, the default only in the client's currency (`EntryRateResolverTest`). The snapshot half of TI-08 is deferred (see Requirements). |
| 4 | Admin sees a daily and weekly timesheet with totals, and a timer left running unusually long is flagged | VERIFIED (visual flag listed for humans) | `TimesheetPage` + `TimesheetQuery` (day view, ISO-week grid, Prague days, DST-correct) pass `TimesheetTest`. `NotifyLongRunningTimers` is scheduled in `routes/console.php` every five minutes with `onOneServer()`, one bell notice per running entry, never stops the timer (`LongRunningTimerTest`); the bar has a forgotten-timer state (`TimerBarTest`). |
| 5 | Project detail (Admin only) shows tasks, estimate vs actual and billed vs unbilled time; a Partner sees no time, rates or prices anywhere, proven by the canary tests | VERIFIED | `ProjectTimeStats` widget and `ProjectTasksTimeRelationManager`/`ProjectTimeEntriesRelationManager` carry `#[AccessRule(Audience::AdminOnly)]`; `ProjectTimeOverviewTest` passes. `TimeEntry` uses `DeniesPartners`; the Livewire components use `RequiresAdmin` and the render hooks return an empty string for a non-Admin. `tests/Isolation/TimeLeakTest.php` (and the extended `CanaryRegistry`) pass: a Partner finds no time words, durations or canaries on any page they can open and gets 403 on every time route, component, widget and tab, for own and foreign project. |

**Score:** 5/5 truths verified (0 present but behavior-unverified; browser-only items are routed to human verification below).

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/migrations/2026_10_11_000100_create_time_entries_table.php` | table, composite FKs, CHECKs, one-running index, frozen-row guard | VERIFIED | all present; guard mutable list `billing_state, billed_at, duration_seconds` |
| `app/Domain/TimeTracking/Actions/*` (Start, Stop, Create, Update, Delete, MarkBilled, CancelBilling, SetTimePanelOpen) | exist and are used by UI | VERIFIED | substantive, authorised by Gate, used by the Livewire components and Filament resources |
| `app/Domain/TimeTracking/TimerLock.php` | per-user advisory lock | VERIFIED | transaction-scoped, throws outside a transaction |
| `app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php` | task, project, client, default | VERIFIED | reads in that order via `TaskBillingResolver` |
| `app/Livewire/TimeTracking/TimerBar.php`, `RecentEntriesPanel.php` | top bar timer and side panel | VERIFIED | registered in `AdminPanelProvider` render hooks, Admin only |
| `app/Filament/Pages/TimesheetPage.php` | day and week timesheet | VERIFIED | |
| `app/Filament/Resources/TimeEntryResource.php` + pages | list, create, view, edit, bulk billing | VERIFIED | |
| `app/Filament/Resources/ProjectResource/{Widgets,RelationManagers}/*Time*` | project time overview | VERIFIED | |
| `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` + `routes/console.php` | forgotten-timer job scheduled | VERIFIED | `everyFiveMinutes()->onOneServer()` |
| `tests/Isolation/TimeLeakTest.php` | Partner leak proof | VERIFIED | passes |

### Key Link Verification

| From | To | Via | Status |
| ---- | -- | --- | ------ |
| `AdminPanelProvider` | `TimerBar` / `RecentEntriesPanel` | `renderHook(GLOBAL_SEARCH_AFTER / LAYOUT_END)` guarded by `PartnerContext::isAdmin()` | WIRED |
| `routes/console.php` | `NotifyLongRunningTimers` | `Schedule::job(...)->everyFiveMinutes()->onOneServer()` | WIRED |
| `StartTimer`/`StopTimer` | `TimerLock` and the one-running index | lock first, then row locks; unique violation translated to `TimerRaceLost` | WIRED |
| `TaskTimerToggle` | task page, task list row, board cards | header/row/card actions | WIRED |
| `MarkEntriesBilled`/`CancelEntriesBilling` | bulk and row actions in `TimeEntryResource`, DB guard trigger | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
| -------- | ---- | ------ | --------- | ------ |
| Project stats / tasks tab | worked, billed, unbilled seconds | correlated sub-selects on `time_entries` (`TimeTotals`) | yes | FLOWING |
| Timesheet | day/week rows and totals | `TimesheetQuery` on `time_entries` with Prague day bounds | yes | FLOWING |
| Timer bar | running entry and elapsed seconds | `time_entries` where `ended_at IS NULL` for the user | yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Phase 6 feature, schema, isolation and unit tests | `ddev exec vendor/bin/pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Isolation tests/Unit/TimeTracking` | 586 passed | PASS |
| Concurrent starts leave one running timer | `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php` | 4 passed | PASS |
| Repository hygiene | `scripts/check-sensitive.sh` | exit 0 | PASS |

### Probe Execution

No `probe-*.sh` is declared by any Phase 6 plan or present for this phase. SKIPPED.

### Requirements Coverage

All ten IDs appear in the `requirements:` frontmatter of at least one plan (TI-01 in 01/02/03/08/09/10/14, TI-02 in 04/06/07/09, TI-03 in 01/04/06, TI-04 in 02/06/10, TI-05 in 02/05/07, TI-06 in 07/11, TI-07 in 01/02/03/04/08, TI-08 in 01/04/05/07, TI-09 in 08/09/13, PR-05 in 12; 06-14 lists all ten). REQUIREMENTS.md maps exactly these ten IDs to Phase 6; there are no orphaned requirements.

| Requirement | Status | Evidence |
| ----------- | ------ | -------- |
| TI-01 timer visible, start from task in <= 2 clicks | SATISFIED (browser clicks for humans) | Truth 1 |
| TI-02 manual create/edit | SATISFIED | Truth 2 |
| TI-03 client-only entries, client required | SATISFIED | `client_id` NOT NULL, project/task nullable; tested |
| TI-04 billable default and override | SATISFIED | Truth 3 |
| TI-05 billed manually, in bulk, or automatically; locked until cancelled | SATISFIED for the Phase 6 part; PARTIAL overall | Manual and bulk marking, lock and cancel are implemented and DB-enforced. The "automatically by invoicing" part is intentionally deferred to Phase 10 (ROADMAP mapping note, IN-02/IN-03). |
| TI-06 daily/weekly timesheet | SATISFIED | Truth 4 |
| TI-07 DB-enforced consistency, one running timer, end rule, start stops previous | SATISFIED | Truths 1 and 2; see the owner decision on wording |
| TI-08 exact seconds, rate resolution, snapshot on billing | PARTIAL (intentional) | Exact seconds and rate resolution done. The snapshot of rate and amount on billing is deferred to Phase 10; `REQUIREMENTS.md` correctly leaves TI-08 unchecked/Pending. |
| TI-09 forgotten timer flagged | SATISFIED (bell visual for humans) | Truth 4 |
| PR-05 project detail time overview, Admin only | SATISFIED | Truth 5 |

Note: `REQUIREMENTS.md` marks TI-05 `[x]` Complete although its "automatically by invoicing" clause is Phase 10 work. This is a bookkeeping inconsistency with TI-08 (left Pending for the same reason), not a code gap. Suggest the orchestrator either leaves TI-05 as is with the mapping note or re-opens it until Phase 10, whichever the owner prefers.

### Deferred Items

| # | Item | Addressed In | Evidence |
| - | ---- | ------------ | -------- |
| 1 | TI-05 automatic billing by invoicing | Phase 10 | ROADMAP mapping note: "TI-05 automatic billing and the TI-08 rate/amount snapshot on billing are exercised in Phase 10 (IN-02, IN-03)"; the 06-14 hand-over note states the guard trigger must be re-created with an extended mutable list |
| 2 | TI-08 snapshot of rate and amount on billing | Phase 10 | same |

### Owner Decision (not a gap)

ROADMAP criterion 2 and TI-07 say "end before the start" / "end after start". The database CHECK is `ended_at >= started_at` (a zero-length entry is legal because starting a timer in the same second as the running one stops it at that same instant, per the migration header and `StartTimer`). The application layer for manual entries is stricter (strictly later). The criterion as worded in the ROADMAP ("whose end is before the start") is satisfied literally. If the owner wants "strictly after" in the database too, StartTimer would need to drop or merge zero-length entries; I recommend keeping the current behaviour and rewording TI-07 to "end not before start".

### Anti-Patterns Found

None blocking. No TODO/FIXME/XXX/HACK/placeholder markers in Phase 6 files; no stub handlers; data flows from the database in every rendered figure.

### Code Review (06-REVIEW.md) vs. must-haves

0 critical, 4 warnings, 6 info. I confirmed the code facts behind the warnings (for example `TimeEntryInput::instant()` / `normalised()` have no year bounds, and `CompletesRunningEntry` re-reads the running entry at submit). None of them makes a ROADMAP success criterion false, but all four are real defects in the Admin-only surface:

| Warning | Touches a must-have? | Assessment |
| ------- | -------------------- | ---------- |
| WR-01 "Doplnit záznam" modal writes the form to whichever entry is running at submit | Adjacent to Truth 1 (one clean running timer) and Truth 3 (exact time) | Does not break the DB invariants (the update still goes through `UpdateTimeEntry` and the CHECKs), but in a two-tab or panel-plus-bar race it can overwrite the new running entry's start with an old entry's data, silently corrupting tracked time. Recommend fixing before Phase 10 bills from time. |
| WR-02 instants unbounded; a start before about 1958 overflows `duration_seconds integer` and can wedge the running timer (StopTimer fails, list queries 500) | Adjacent to Truth 3 (exact durations) | Admin typo or API input only; the happy path and all stated criteria hold. Recommend a year window plus `bigint` casts before Phase 7 exposes the same input through the API. |
| WR-03 year-0000 date in `?date=` or a period filter gives a 500 on the timesheet | Truth 4 (timesheet) | Robustness gap on a hostile URL, Admin only; the timesheet works for every real date. Low priority. |
| WR-04 stale start/stop icon on board cards and task rows can invert the click | Truth 1 (start from task in two clicks) | The two-click start and the stop both work; the stale-icon case needs another surface to change the state first. Recommend fixing (send intent with the click, refresh on timer events). |

Recommendation: record WR-01 to WR-04 as follow-up fixes (a gap-closure plan or early in Phase 7); they do not warrant `gaps_found`. None undermines a must-have, so I did not convert them into gaps.

### Human Verification Required

See the `human_verification` list in the frontmatter: SPA persistence of the top-bar timer, side-panel docking, 375px layout, contrast and state colours, the two-click start and stop in a real browser, and the forgotten-timer bell in a live session.

### Gaps Summary

No gaps. Phase goal is met in code and in tests I executed. Status is `human_needed` solely because the browser-only checks above cannot be verified automatically. TI-05 (automatic) and TI-08 (snapshot) are intentional Phase 10 deferrals.

---

_Verified: 2026-10-09T18:00:00Z_
_Verifier: Claude (gsd-verifier)_
