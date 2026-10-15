---
phase: quick-261015-cv5
plan: 01
subsystem: dashboard
tags: [dashboard, filament, livewire, preferences, partner-isolation]
requires: []
provides:
  - dashboard_widget_preferences table (per-user collapsed state of the six dashboard cards)
  - Dashboard domain (DashboardWidget enum, DashboardWidgetPreference model, DashboardWidgetPreferencePolicy, SetDashboardWidgetCollapsed, CollapsedWidgets)
  - CollapsibleDashboardWidget trait and the shared x-dashboard-card Blade component
affects: [dashboard, partner-dashboard]
tech-stack:
  added: []
  patterns:
    - "Per-user UI state stored server side, applied in the first render, written by a renderless Livewire call with optimistic UI"
    - "#[NotPartnerScoped] model with OwnerScope when isolation is per user"
key-files:
  created:
    - database/migrations/2026_10_15_000300_create_dashboard_widget_preferences_table.php
    - app/Domain/Dashboard/Enums/DashboardWidget.php
    - app/Domain/Dashboard/Models/DashboardWidgetPreference.php
    - app/Domain/Dashboard/Policies/DashboardWidgetPreferencePolicy.php
    - app/Domain/Dashboard/Actions/SetDashboardWidgetCollapsed.php
    - app/Domain/Dashboard/Queries/CollapsedWidgets.php
    - app/Filament/Concerns/CollapsibleDashboardWidget.php
    - resources/views/components/dashboard-card.blade.php
    - resources/views/filament/widgets/hours-stats.blade.php
    - resources/views/filament/widgets/dashboard-table-widget.blade.php
    - tests/Feature/Dashboard/CollapsibleWidgetsTest.php
    - tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - app/Filament/Widgets/HoursStats.php
    - app/Filament/Widgets/TodayPlan.php
    - app/Filament/Widgets/UnbilledPerClient.php
    - app/Filament/Widgets/UpcomingDeadlines.php
    - app/Filament/Widgets/PartnerTasks.php
    - app/Filament/Widgets/PartnerProjects.php
    - resources/views/filament/widgets/today-plan.blade.php
    - resources/views/filament/widgets/partner-tasks.blade.php
    - lang/cs/kokpit.php
    - CONTRIBUTING.md
    - tests/Arch/ModelDeclarationTest.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
decisions:
  - "Model uses #[NotPartnerScoped] plus OwnerScope (not PartnerIsolated): no text column for a canary, isolation is per user"
  - "Policy ability collapse() returns isPartnerWidget(); KokpitPolicy::before() admits the Admin and refuses a Partner without a client"
  - "Writer is a single Eloquent upsert on (user_id, widget_key); SetDashboardWidgetCollapsed is not final so a test can bind a failing subclass"
  - "Table cards pass ->heading(null) so Filament does not fall back to a heading made from the class name"
metrics:
  tasks: 3
  completed: 2026-10-15
status: complete
actuals:
  tokens: 60000
  tasks: 3
  commits: 3
plan_head_before: 78f4d28818138f4a9a72843aa043697a3b504403
plan_head_after: 75a5c2e2da3fc2ac92e47d6a2b7fb5eb463ec195
commits: 3
---

# Phase quick-261015-cv5 Plan 01: Collapsible dashboard widgets Summary

All six dashboard cards (four Admin, two Partner) collapse independently through one shared `x-dashboard-card` wrapper. The state is stored per user in `dashboard_widget_preferences` the moment it is toggled, and it is applied in the first render, so a collapsed card does not flash open.

## What was built

- **Table** `dashboard_widget_preferences`: uuidv7 PK, FK to users (cascade on delete), `UNIQUE (user_id, widget_key)`, boolean `collapsed`, and a named CHECK limiting `widget_key` to the six cards. An unknown key fails with 23514, a duplicate with 23505 and a missing user with 23503.
- **Domain**: `DashboardWidget` enum with `isPartnerWidget()`, the owner-scoped model, `DashboardWidgetPreferencePolicy::collapse`, the only writer `SetDashboardWidgetCollapsed` and the reader `CollapsedWidgets`. Both authorise `collapse`, so a Partner can hold state for `partner_tasks` and `partner_projects` only. A Partner without a client is refused for every key.
- **UI**: the `CollapsibleDashboardWidget` trait (locked `collapsed`, mount hook, renderless `storeCollapsedState()` returning the stored state, danger toast and unchanged state on a `QueryException`) and the `x-dashboard-card` component (Filament section with chevron, `aria-expanded`/`aria-controls`, summary in the header while collapsed, body cloaked server side, Alpine watch that reverts to the returned state on failure). The three table widgets share `dashboard-table-widget.blade.php`.
- **Key figure per collapsed card**: hours today, plan done of total, unbilled hours, overdue task count, and for the Partner open task count and project count (counts only, no duration).

## Final Czech copy (lang/cs/kokpit.php, `kokpit.dashboard.*`)

- `hours.heading` = "Odpracovaný čas"; `hours.summary` = "Dnes :duration"
- `unbilled.summary` = ":duration h"
- `deadlines.summary_overdue` = "{1} :count úkol po termínu|[2,4] :count úkoly po termínu|[5,*] :count úkolů po termínu"; `deadlines.summary_none` = "Vše v termínu"
- `plan.summary` = "Splněno :done z :count"
- `partner_tasks.summary` = "Otevřeno: :count"
- `partner_projects.summary` = "Projektů: :count"
- `collapse.store_failed` = "Nastavení přehledu se nepodařilo uložit. Zkuste to prosím znovu."

## Deviations from Plan

None in behaviour. Three existing assertions in `tests/Feature/Dashboard/AdminDashboardTest.php` were adjusted because the intended markup change broke them. The intent of each is kept, and no isolation, never-list or number assertion was touched.

1. `shows no toggle for exactly three items ...`: it asserted the HTML has no `aria-expanded` and no `aria-controls`. Every card header now has a chevron button that carries both. It now asserts the "show more" toggle is absent (`open.toString()`, `aria-controls="today-plan-more"`, `today-plan-more` and "Zobrazit dalších" all absent).
2. `is read-only: no form control but the toggle ...` (renamed to mention the chevron): `<button` count 1 became 2 (the card chevron plus the "show more" toggle). The links, `<input>`, `<form>`, `wire:click` etc. assertions are unchanged.
3. `says "Na dnešek zatím nic nenaplánováno" ...`: `not->toContain('aria-expanded')` became `not->toContain('open.toString()')`, for the same reason as (1).

The PHPStan `return.unusedType` finding on four `collapsedSummary()` implementations that never return null was fixed by narrowing their return type to `string` (the trait declares `?string`).

## Verification

- `ddev composer ci` (Pest, Pint, PHPStan, licence check) is green: 3932 passed, 3 skipped, 0 failures. No unrelated failure.
- The dashboard, schema, arch and isolation suites named in the plan passed on their own before the full run.
- `scripts/check-sensitive.sh` is clean for every touched file, and the lefthook hook (sensitive-content, gitleaks) passed on all three commits.

## Known Stubs

None.

## Threat Flags

None. The only new surface is the renderless `storeCollapsedState` Livewire method, which is covered by T-261015-cv5-01/02 in the plan (boot 403 for a forged mount, policy at the Action, locked property, key from the widget class).

## Human check still open

The plan's `human-check` (run `ddev exec php artisan migrate`, open /admin at desktop width and at 375 px, collapse two cards, reload, repeat as a fictional Partner) was not done here, since there is no browser in this run. The Alpine optimistic toggle, the revert on failure and the `.fi-ta-ctn` ring and shadow override on the table cards are covered only by server-rendered assertions, not by a browser.

## Todo

`.planning/todos/pending/2026-10-15-collapsible-dashboard-widgets-persisted-in-db.md` was moved to `.planning/todos/completed/` with `git mv`. The move is staged, not committed, for the orchestrator's docs commit.

## Commits

- 2d9872a feat(quick-261015-cv5): collapsible hours card with per-user state stored in the database
- 8f35de4 feat(quick-261015-cv5): collapsible cards for the other five dashboard widgets
- 75a5c2e docs(quick-261015-cv5): document the collapsible dashboard card convention

## Self-Check: PASSED

All created files exist and all three commits are ancestors of HEAD (checked with `git merge-base --is-ancestor`).
