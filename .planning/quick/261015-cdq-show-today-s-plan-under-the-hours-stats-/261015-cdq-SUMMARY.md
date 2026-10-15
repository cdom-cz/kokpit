---
phase: quick-261015-cdq
plan: 01
subsystem: admin-dashboard
tags: [filament, widget, signal, dashboard]
requires: [08-20]
provides: [TodayPlan widget, Admin dashboard order HoursStats, TodayPlan, UnbilledPerClient, UpcomingDeadlines]
affects: [Phase 08 D-11]
tech-stack:
  added: []
  patterns: [read-only Livewire widget reading through SignalDayReader]
key-files:
  created: []
  modified:
    - app/Filament/Widgets/TodayPlan.php
    - resources/views/filament/widgets/today-plan.blade.php
    - app/Filament/Pages/Dashboard.php
    - lang/cs/kokpit.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Isolation/TimeLeakTest.php
    - tests/Feature/Dashboard/PartnerDashboardTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - .planning/STATE.md
decisions:
  - "The Admin dashboard shows the Signal plan of today (Prague day) instead of tomorrow, right under HoursStats (sort 15, full width)"
  - "A task already done today is struck through with the label Splněno (executor discretion, see below)"
metrics:
  duration: ~35 min
  completed: 2026-10-15
status: complete
requirements: [RE-04]
commits: 2
plan_head_before: a67147aa6c52bd15de86b1637f669bd62bf48048
plan_head_after: 54e2f88dbec73d9db9e44730f1ad7f70ed76f5af
actuals:
  tokens: 14000
  tasks: 3
  commits: 2
---

# Quick Task 261015-cdq: Today's plan under the hours stats

One-liner: the Admin dashboard now shows "Plán na dnešek" (Signal plan of the Europe/Prague day, read-only, three items visible, rest collapsed, never materialising templates) as `TodayPlan` directly under the hours stats, full width.

## What changed

- `TomorrowPlan` and its view were renamed with `git mv` to `TodayPlan` / `today-plan.blade.php`. `git log --follow` on both files shows the history back to `b9688fb` (08-20), so history is preserved.
- The widget reads `SignalCalendar::today()` through `SignalDayReader` (tasks plus not-yet-materialised templates), owner-scoped, and calls no Signal action. `$sort` is 15, the reserved band 20 stays free.
- `Dashboard::getWidgets()` for the Admin returns `[HoursStats, TodayPlan, UnbilledPerClient, UpcomingDeadlines]`.
- Czech copy: heading `Plán na dnešek`, empty heading `Na dnešek zatím nic nenaplánováno`, new `done` label `Splněno`.
- Item shape gained a `done` key. The region id is `today-plan-more`.
- Tests: the plan block of `AdminDashboardTest` was retargeted to today and gained a Prague midnight edge test, a "materialised template shown once" test, a done-marking test, and a position test replacing the old "last widget" test. The no-poll test keeps the unchanged SignalTask count as proof that nothing is materialised. `PanelAccessTest`, `TimeLeakTest`, `PartnerDashboardTest` and `RepositoryFilesTest` name `TodayPlan` and the new heading. CONTRIBUTING.md describes the new order.

## TDD record

- RED: `AdminDashboardTest` before the rename, 14 failed / 43 passed. Every plan test failed with `ComponentNotFoundException: Unable to find component: [App\Filament\Widgets\TodayPlan]`, and the position test failed on the missing class.
- GREEN: after the rename and the changes, 57 passed (245 assertions). Pint fixed one extra blank line in the test file. PHPStan on the two classes: no errors.
- Task 2 gate: 155 passed (1814 assertions) over the five test files.

## Commits

| Task | Commit | Files |
| ---- | ------ | ----- |
| 1 | `1875391` feat(quick-261015-cdq) | TodayPlan.php (renamed), today-plan.blade.php (renamed), Dashboard.php, lang/cs/kokpit.php, AdminDashboardTest.php |
| 2 | `54e2f88` test(quick-261015-cdq) | PanelAccessTest, TimeLeakTest, PartnerDashboardTest, RepositoryFilesTest, CONTRIBUTING.md |
| 3 | not committed here | STATE.md decision bullet and the todo move (`git mv` to `.planning/todos/completed/`, staged) are left for the orchestrator docs commit |

## Phase 08 D-11 is amended

This quick task replaces the "plan for tomorrow" part of Phase 08 D-11 on the owner's request. The Phase 08 verifier and later planners should read D-11 as "plan for today, directly under the hours". STATE.md carries the decision line. No Phase 08 artifact was edited.

## Executor discretion to confirm or drop

A task of today that is already ticked done is shown struck through (`text-decoration: line-through; opacity: 0.6;`, as the planner does) with a muted label `Splněno`, so the state is not carried by styling alone. It is a small block in `today-plan.blade.php` and a `done` key in `TodayPlan::items()`; the owner can drop it by removing both. Nothing else was added (no tick, no per-item link, no reordering of done items).

## Deviations from Plan

None. The plan was executed as written. One note: the test file had to be reassembled from head and tail pieces, which produced a double blank line that Pint removed.

## Verification

- `ddev composer ci`: the first run exited with a failure in the Pest step (`Script vendor/bin/pest handling the test event returned with error code 1`); I only had the tail of the output and did not capture which test failed. A full rerun passed completely: 3906 passed, 3 skipped (25262 assertions), Pint clean, PHPStan `[OK] No errors`, licence check 225 packages allowed. The first failure did not reproduce and is likely a flaky test unrelated to these files (the rename-sensitive files passed in isolation twice); flagging it for the owner.
- `grep -rn` for the old class, view and Czech headings over app, resources, lang, tests, routes, config, CONTRIBUTING.md and README.md returns nothing.
- `scripts/check-sensitive.sh` is clean on all touched files; lefthook (sensitive-content, gitleaks) passed on both commits, never bypassed.
- Manual check of `/admin` in a browser was not done.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; the Admin-only `AccessRule` and the boot check carried over unchanged through the rename.

## Self-Check: PASSED

- FOUND: app/Filament/Widgets/TodayPlan.php, resources/views/filament/widgets/today-plan.blade.php
- MISSING (as intended): app/Filament/Widgets/TomorrowPlan.php, resources/views/filament/widgets/tomorrow-plan.blade.php
- FOUND commits: 1875391, 54e2f88
