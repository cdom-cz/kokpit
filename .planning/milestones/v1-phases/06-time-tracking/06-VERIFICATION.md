---
phase: 06-time-tracking
verified: 2026-10-14T08:00:00Z
status: passed
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
  - app/Console/Commands/DeployVerifyCommand.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Shared/Database/CzechCollation.php
  - app/Domain/Tasks/Models/Task.php
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
  - app/Filament/Concerns/ManagesTaskBoard.php
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
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
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
  - config/kokpit.php
  - database/migrations/2026_10_11_000100_create_time_entries_table.php
  - database/migrations/2026_10_11_000200_add_time_panel_open_to_users_table.php
  - lang/cs/kokpit.php
  - resources/views/filament/pages/task-board.blade.php
  - resources/views/filament/pages/timesheet-week-footer.blade.php
  - resources/views/filament/pages/timesheet.blade.php
  - resources/views/filament/resources/project-resource/tasks-time-footer.blade.php
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
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/TimeLeakTest.php
  - tests/Support/CanaryRegistry.php
covered_digest: "v3:sha256:1308550f252b896eaba4cbf75a7a02e7944ab48cadb660c9c023efb5c0e785aa"
behavior_unverified: 0
overrides_applied: 0
deferred:
  - truth: "TI-05 clause 'entries are marked billed automatically by invoicing'"
    addressed_in: "Phase 10"
    evidence: "Phase 10 success criterion 1 (drafts from unbilled time, cancelling or deleting a draft or storno returns the entries to unbilled and unlocked); ROADMAP mapping note: 'TI-05 automatic billing and the TI-08 rate/amount snapshot on billing are exercised in Phase 10 (IN-02, IN-03)'"
  - truth: "TI-08 clause 'snapshot of rate and amount on billing'"
    addressed_in: "Phase 10"
    evidence: "Phase 10 success criterion 2: issuing 'freezes supplier, customer and bank snapshots, rates, amounts and the CNB rate'; ROADMAP mapping note (IN-02, IN-03)"
advisory:
  - finding: "WR-01 (06-REVIEW): the 'Doplnit zaznam' modal applies its form to whichever entry is running at submit time, not to the entry the modal was opened for"
    category: other
    reason: "Still open in app/Livewire/TimeTracking/CompletesRunningEntry.php (the action re-reads runningEntry() at submit; no expected-entry pin). Admin-only, needs a two-tab or panel-plus-bar race, DB invariants still hold. Recommend a follow-up fix before Phase 10 bills from time."
    evidence_status: "code read; no failing test"
  - finding: "WR-02 (06-REVIEW): instants have no year bounds; a start before about 1958 overflows the integer duration_seconds / elapsed expressions"
    category: other
    reason: "Still open (no year bounds in TimeEntryInput, duration_seconds is still integer). Admin typo only; recommend year window plus bigint casts before Phase 7 exposes the same input through the API."
    evidence_status: "code read; not reproduced"
  - finding: "WR-03 (06-REVIEW): a year-0000 date in ?date= or a period filter gives a 500 on the timesheet"
    category: other
    reason: "Still open (TimesheetQuery::normalizeDate has no year guard). Admin-only robustness gap on a hostile URL; every real date works."
    evidence_status: "code read; not reproduced"
  - finding: "WR-04 (06-REVIEW): stale start/stop icon on board cards and task rows can invert the click"
    category: other
    reason: "Still open (toggleTimer($taskId) carries no intent). Human UAT test 5 judged the stale-icon case acceptable; the two-click start and stop work."
    evidence_status: "UAT 06-UAT.md test 5 passed"
---

# Phase 6: Time Tracking Verification Report

**Phase Goal:** Admin tracks exact time against clients, projects and tasks with a timer or manual entries and always knows what is billable, billed and unbilled
**Verified:** 2026-10-14T08:00:00Z
**Status:** passed
**Re-verification:** No - fresh regeneration of a stale report. The previous report (2026-10-09) was `human_needed` with no `gaps:` section, so this run is treated as an initial verification; all six browser-only items it listed were carried through `06-UAT.md`, which now records 6 passed, 0 issues.

## Goal Achievement

All five ROADMAP success criteria hold in the code and in tests that the verifier ran in its own process. No human verification item is open. The two requirement clauses that depend on invoicing (TI-05 automatic billing, TI-08 rate/amount snapshot) are deliberately deferred to Phase 10 and are listed under Deferred Items; they do not make a Phase 6 success criterion false.

### Test evidence (run by the verifier in DDEV, shared `kokpit_test` database)

| Run | Result |
| --- | ------ |
| `ddev exec vendor/bin/pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Isolation tests/Unit/TimeTracking` | 594 passed, 3523 assertions, 0 failed |
| `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php` (8 real processes x 25 starts, plus the mutation run without the lock) | 4 passed, 180 assertions |
| `scripts/check-sensitive.sh` | exit 0 |
| Debt-marker scan (TBD, FIXME, XXX) over the Phase 6 app, migration and view files | none found |
| Full suite (reported by the orchestrator, not re-run here) | 2623 passed |

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
| - | ----- | ------ | -------- |
| 1 | A timer is visible on every screen and can be started from a task in at most two clicks; starting a timer stops the running one; concurrent starts never leave two running timers for one user | VERIFIED | `AdminPanelProvider.php` renders `TimerBar` (line ~103) and `RecentEntriesPanel` (line ~111) through render hooks for the Admin on every panel page. `TaskTimerToggle` is used by `TaskResource` (row/header action) and `ManagesTaskBoard::toggleTimer` (board cards). `StartTimer` takes `TimerLock` (`pg_advisory_xact_lock(hashtextextended(...))`, throws outside a transaction), reads the clock after the lock, stops the running entry at the same instant and inserts the new one in one transaction; the DB backstop is the partial unique index `time_entries_one_running_per_user` and a lost race becomes `TimerRaceLost`. `TimerConcurrencyTest` ran 8 real processes: exactly one running entry and a gap-free chain; the mutation run without the lock shows the index catches 7 of 8. UAT tests 1 and 5 passed in a real browser (SPA persistence, two-click start and stop on task page, list row and board card). |
| 2 | Admin creates and edits entries manually, can save an entry with only a client, and the database rejects entries whose client/project/task disagree or whose end is before the start | VERIFIED | Migration `2026_10_11_000100_create_time_entries_table.php`: `client_id` NOT NULL, `project_id` and `task_id` nullable, composite FKs `time_entries_project_client_fk (project_id, client_id)` and `time_entries_task_project_fk (task_id, project_id)`, CHECK `task_id IS NULL OR project_id IS NOT NULL`, CHECK `ended_at IS NULL OR ended_at >= started_at`. `TimeEntriesTableTest` proves each by SQLSTATE; `TimeEntryActionsTest`/`TimeEntryResourceTest` cover create, edit and client-only entries. Wording note: the DB rule is "end not before start" (a zero-length entry is legal because a start in the same second stops the previous timer at that instant); manual entries are stricter in `TimeEntryInput` (strictly later). The criterion as worded ("end is before the start") is met literally. |
| 3 | Billable defaults to true, is pre-set to false for non-billable projects/tasks and can be overridden; entries marked billed manually or in bulk are locked until billing is cancelled; durations are exact seconds; the effective rate resolves task, project, client, global default | VERIFIED | `BillableDefault` (explicit caller value wins, `StartTimer` uses it when `billable` is not a bool) - `BillableDefaultTest`. `MarkEntriesBilled`/`CancelEntriesBilling` (single and bulk, row locks, audit) plus the `Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state','billed_at','duration_seconds'])` trigger freeze billed rows in the database - `BillingLockTest`. `duration_seconds` is a stored generated column from two `timestamptz(0)` instants, writers truncate through `TimerClock`, `DurationFormat` truncates and never rounds - `DurationFormatTest`. `TimeEntryRateResolver` (read in full): task, parent task, project, client through `TaskBillingResolver`, then the global default only when its currency equals the client's; Admin/system only - `EntryRateResolverTest`. |
| 4 | Admin sees a daily and weekly timesheet with totals, and a timer left running unusually long is flagged to the user | VERIFIED | `TimesheetPage` + `TimesheetQuery` (day view, ISO-week grid, Prague days, DST) pass `TimesheetTest`. `routes/console.php` schedules `NotifyLongRunningTimers` (`kokpit.time.long_running_hours` = 12, one bell notice per running entry, never stops the timer) - `LongRunningTimerTest`; the top bar has a forgotten-timer state - `TimerBarTest`. UAT test 6 confirmed the bell notice in a live session. |
| 5 | Project detail (Admin only) shows tasks, estimate versus actual and billed versus unbilled time; a Partner sees no time, rates or prices anywhere, proven by the canary tests | VERIFIED | `ProjectTimeStats`, `ProjectTasksTimeRelationManager` and `ProjectTimeEntriesRelationManager` are Admin-only and covered by `ProjectTimeOverviewTest`; data comes from real correlated sub-selects on `time_entries` (`TimeTotals`). `TimeEntry` denies Partners at the data layer, the Livewire components use `RequiresAdmin`, the render hooks return nothing for a non-Admin. `tests/Isolation/TimeLeakTest.php` and the extended `CanaryRegistry` pass in the 594-test run. |

**Score:** 5/5 truths verified (0 present but behavior-unverified).

### Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | TI-05 "marked billed automatically by invoicing" | Phase 10 | Phase 10 SC 1 (drafts from unbilled time, cancel/delete/storno returns entries to unbilled); ROADMAP mapping note (IN-02, IN-03) |
| 2 | TI-08 "snapshot of rate and amount on billing" | Phase 10 | Phase 10 SC 2 (issuing freezes rates, amounts and snapshots); ROADMAP mapping note; the migration header and the 06-14 hand-over state that the guard trigger must be re-created with an extended mutable list |

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/migrations/2026_10_11_000100_create_time_entries_table.php` | table, composite FKs, CHECKs, one-running index, billed guard trigger | VERIFIED | all present (read in full) |
| `app/Domain/TimeTracking/Actions/*` (Start, Stop, Create, Update, Delete, MarkBilled, CancelBilling, SetTimePanelOpen) | exist and are used by the UI | VERIFIED | substantive, Gate-authorised, used by Livewire components and Filament resources |
| `app/Domain/TimeTracking/TimerLock.php` | per-user advisory lock | VERIFIED | transaction-scoped; throws outside a transaction |
| `app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php` | task, project, client, default | VERIFIED | read in full |
| `app/Livewire/TimeTracking/TimerBar.php`, `RecentEntriesPanel.php` | top bar timer and side panel | VERIFIED | registered in `AdminPanelProvider` render hooks, Admin only |
| `app/Filament/Pages/TimesheetPage.php`, `Queries/TimesheetQuery.php` | day and week timesheet | VERIFIED | `TimesheetTest` passes |
| `app/Filament/Resources/TimeEntryResource.php` + pages | list, create, view, edit, bulk billing | VERIFIED | tests pass |
| `app/Filament/Resources/ProjectResource/{Widgets,RelationManagers}/*Time*` | project time overview | VERIFIED | `ProjectTimeOverviewTest` passes |
| `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` + `routes/console.php` | forgotten-timer job scheduled | VERIFIED | scheduled every five minutes, `onOneServer()` |
| `tests/Isolation/TimeLeakTest.php` | Partner leak proof | VERIFIED | passes |

### Key Link Verification

| From | To | Via | Status |
| ---- | -- | --- | ------ |
| `AdminPanelProvider` | `TimerBar` / `RecentEntriesPanel` | render hooks guarded for the Admin | WIRED |
| `routes/console.php` | `NotifyLongRunningTimers` | `Schedule::job(...)` every five minutes | WIRED |
| `StartTimer` / `StopTimer` | `TimerLock` and the one-running index | lock first, then row locks; unique violation translated to `TimerRaceLost` | WIRED |
| `TaskTimerToggle` | `TaskResource` row/header action and `ManagesTaskBoard::toggleTimer` | one-click start/stop | WIRED |
| `MarkEntriesBilled` / `CancelEntriesBilling` | `TimeEntryResource` row and bulk actions, DB guard trigger | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Project stats / tasks tab | worked, billed, unbilled seconds | correlated sub-selects on `time_entries` (`TimeTotals`) | yes | FLOWING |
| Timesheet | day/week rows and totals | `TimesheetQuery` on `time_entries` with Prague day bounds | yes | FLOWING |
| Timer bar | running entry and elapsed seconds | `time_entries` where `ended_at IS NULL` for the user | yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Phase 6 feature, schema, isolation and unit tests | `ddev exec vendor/bin/pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Isolation tests/Unit/TimeTracking` | 594 passed | PASS |
| Concurrent starts leave one running timer | `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php` | 4 passed | PASS |
| Repository hygiene | `scripts/check-sensitive.sh` | exit 0 | PASS |

### Probe Execution

No `probe-*.sh` is declared by any Phase 6 plan or present for this phase. SKIPPED.

### Requirements Coverage

All ten IDs appear in the `requirements:` frontmatter of at least one plan, and REQUIREMENTS.md maps exactly these ten IDs to Phase 6; there are no orphaned requirements.

| Requirement | Status | Evidence |
| ----------- | ------ | -------- |
| TI-01 timer visible, start from task in <= 2 clicks | SATISFIED | Truth 1; UAT 1 and 5 |
| TI-02 manual create/edit | SATISFIED | Truth 2 |
| TI-03 client-only entries, client required | SATISFIED | `client_id` NOT NULL, project/task nullable; tested |
| TI-04 billable default and override | SATISFIED | Truth 3 |
| TI-05 billed manually / in bulk / automatically; locked until cancelled | SATISFIED for Phase 6 scope | Manual and bulk marking, lock and cancel are implemented and DB-enforced; "automatically by invoicing" is a Phase 10 deferral |
| TI-06 daily/weekly timesheet | SATISFIED | Truth 4 |
| TI-07 DB-enforced consistency, one running timer, end rule, start stops previous | SATISFIED | Truths 1 and 2 |
| TI-08 exact seconds, rate resolution, snapshot on billing | SATISFIED for Phase 6 scope; snapshot deferred | Exact seconds and rate resolution verified; the snapshot of rate and amount on billing is a Phase 10 deliverable. REQUIREMENTS.md correctly leaves TI-08 `[ ]` / Pending |
| TI-09 forgotten timer flagged | SATISFIED | Truth 4; UAT 6 |
| PR-05 project detail time overview, Admin only | SATISFIED | Truth 5 |

Bookkeeping note: REQUIREMENTS.md marks TI-05 `[x]` Complete but TI-08 `[ ]` Pending, although both have a Phase 10 clause. This is an inconsistency in the tracking file, not a code gap; the owner may re-open TI-05 until Phase 10 for symmetry.

### Anti-Patterns Found

None blocking. No TBD/FIXME/XXX markers in Phase 6 files; no stub handlers; every rendered figure is fed from the database. See the advisory list for four open code-review warnings.

### Human Verification Required

None open. The six browser-only items of the previous report (SPA persistence, side-panel docking, 375px layout, contrast, two-click start/stop, forgotten-timer bell) were executed by the owner and recorded as passed in `06-UAT.md` (6 passed, 0 issues).

### Gaps Summary

No gaps. The phase goal is met in code, in tests the verifier ran, and in the owner's browser UAT.

Open follow-ups that are not gaps (frontmatter `advisory`): code-review warnings WR-01 to WR-04 from `06-REVIEW.md` are still unresolved in the code (confirmed by reading `CompletesRunningEntry.php`, `TimeEntryInput.php`, `TimesheetQuery.php`, `ManagesTaskBoard.php`). None of them makes a ROADMAP success criterion false. WR-01 (modal can overwrite the newly running entry's data in a two-tab race) is the one worth fixing before Phase 10 starts billing from tracked time; WR-02 should be fixed before Phase 7 exposes time input through the API.

---

_Verified: 2026-10-14T08:00:00Z_
_Verifier: Claude (gsd-verifier)_
