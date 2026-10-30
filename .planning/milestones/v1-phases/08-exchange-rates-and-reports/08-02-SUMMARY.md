---
phase: 08-exchange-rates-and-reports
plan: 02
subsystem: ui
tags: [filament, dashboard, widgets, partner-isolation, livewire, czech-collation]

requires:
  - phase: 02-foundation-platform
    provides: "#[AccessRule] fail-closed declarations, EnforcesWidgetAccessRule, Partner scope, canary harness"
  - phase: 05-tasks
    provides: "TaskColumns pinned Partner field set, orderByPriority, Partner task resource (slug my-tasks)"
  - phase: 04-projects
    provides: "ProjectColumns pinned Partner field set, Partner project resource (slug my-projects)"
provides:
  - "App\\Filament\\Widgets\\PartnerTasks: open tasks of the Partner's visible projects, in_review first and highlighted"
  - "App\\Filament\\Widgets\\PartnerProjects: the Partner's own visible projects, Czech collation, at most 10"
  - "Role-specific Dashboard::getWidgets() that plans 08-15 and 08-20 extend with the Admin widgets"
  - "kokpit.dashboard.partner_tasks.* and kokpit.dashboard.partner_projects.* Czech copy"
affects: [08-15 Admin dashboard widgets, 08-20 Admin dashboard plan block, 08-22 Phase 8 isolation sweep]

actuals:
  tokens: 10500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Partner widget: PartnerAllowed declaration narrowed by canView() to a non-Admin Partner with a client; the boot hook turns a forged mount into 403"
    - "Each widget exposes a FIELDS constant that a test checks against the pinned Partner column constants"
    - "A highlight that no utility class can carry is written as inline style, built once in the Blade view"
    - "TableWidget columns are taken by name from the pinned Partner column builder, with search and sort switched off"

key-files:
  created:
    - app/Filament/Widgets/PartnerTasks.php
    - app/Filament/Widgets/PartnerProjects.php
    - resources/views/filament/widgets/partner-tasks.blade.php
    - tests/Feature/Dashboard/PartnerDashboardTest.php
  modified:
    - app/Filament/Pages/Dashboard.php
    - lang/cs/kokpit.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
    - app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php

key-decisions:
  - "Both Partner widgets narrow the shared PartnerAllowed declaration with canView(): a non-Admin with a client only, so an Admin mount is a 403 (like PartnerProjectResource::canAccess())"
  - "Both widgets are non-lazy and span the full row; the dashboard is one column, tasks before projects (UI-SPEC U-12)"
  - "The page keeps the stock empty state only when the Partner has neither an open task nor a visible project; with one of them each empty widget shows its own short empty state"
  - "The review count, open count and rows are read again at every render; the widgets hold no public property, so nothing but markup carries task data to the browser"

patterns-established:
  - "Pinned widget list: PanelAccessTest now names the discovered widget set exactly; every plan adding a widget under app/Filament/Widgets extends that one expectation"
  - "Leak proof by string list: the Partner dashboard HTML is checked against the UI-SPEC 'Never on a Partner surface' list plus durations and rate values stored for the same client"

requirements-completed: [RE-04]

coverage:
  - id: D1
    description: "A Partner sees the open tasks of the own visible projects with in_review first, then priority, due date (undated last), reference; done, archived and other clients' tasks are absent"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#lists the open tasks with the one in review first, badged and highlighted, and nothing of client B"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#orders by review, then priority, then due date with the undated last, then reference"
        status: pass
    human_judgment: false
  - id: D2
    description: "Each in_review task carries the primary badge with the eye icon, the 4px left border and tint as inline style and a 'Zkontrolovat úkol' button; the heading badge and the highlight follow the number (0, 1, 3)"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#follows the number of tasks in review: none, one and many"
        status: pass
    human_judgment: true
    rationale: "Whether the highlighted row reads as the Partner's call to action in the real panel theme (light and dark) is a visual judgment no markup assertion makes"
  - id: D3
    description: "'Moje projekty' lists at most 10 visible, non-archived projects of the own client in Czech collation with Název, Klíč, Stav, Priorita, Konec only; the task list shows 20 rows and the 'Zobrazit všechny úkoly' link only above 20"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#the visible projects of a Partner and #the number of rows of the task list"
        status: pass
    human_judgment: false
  - id: D4
    description: "Own empty states for each widget; both empty gives the stock empty state; the Admin dashboard and the refusal of a Partner without a client are unchanged"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#the empty states"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Partner dashboard HTML contains none of the 'Never on a Partner surface' strings, no duration, no rate or price, no time or billing column in any tasks query; an Admin or a Partner without a client cannot mount a Partner widget (403)"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#nothing of time or money on the Partner dashboard"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php#refuses the widget to an Admin, also when forged (both widgets)"
        status: pass
      - kind: integration
        ref: "tests/Arch/PanelRegistryTest.php and tests/Isolation/PanelAccessTest.php#replaces the stock dashboard and its widgets"
        status: pass
    human_judgment: false

duration: 12min
completed: 2026-10-14
status: complete
plan_head_before: 91ee2357a4870233701bc58c08ec9004a91b7633
plan_head_after: ed62ddcf542ae88fdfb7da1b3d52c0dfd42d102b
commits: 2
---

# Phase 8 Plan 02: Partner Dashboard Summary

**A Partner's landing page now lists their open tasks with the ones waiting for their review first and highlighted as a call to action, plus their own visible projects, read only through the Partner scope and the pinned Partner fields, with no time, rate or money string provable on the page**

## Performance

- **Duration:** 12 min
- **Started:** 2026-10-14T18:59:00Z
- **Completed:** 2026-10-14T19:11:00Z
- **Tasks:** 2
- **Files modified:** 9 (4 created, 5 modified)

## Accomplishments
- `PartnerTasks` (custom view widget): scoped `Task::query()`, not done, ordered by in_review first, then `TaskColumns::orderByPriority`, due date nulls last, reference; at most 20 rows, "Zobrazit všechny úkoly" above 20; in_review rows have the primary badge with the eye icon, a 4px left border and tint as inline style, and a "Zkontrolovat úkol" button; the heading carries "Ke kontrole: N" only when N > 0.
- `PartnerProjects` (table widget): scoped `Project::query()` ordered through `CzechCollation::orderBy`, limited to 10, columns taken by name from `ProjectColumns::partnerColumns()` (Název linking to the Partner project page, Klíč, Stav, Priorita, Konec), no pagination, search or sort.
- `Dashboard::getWidgets()` is role-specific: a non-Admin Partner with a client gets both widgets, everyone else none; `content()` falls back to the existing empty state when the Partner has neither a task nor a project. Admin dashboard unchanged until plan 08-15.
- 24 dashboard tests, including the leak proof (billed and unbilled entries, task and project rates and a fixed price stored for the client; none of the 14 "never" strings, no duration, no rate value in the HTML; no tasks SELECT touches a time, billing, estimate or description column) and forged Admin mounts answering 403.

## Task Commits

1. **Task 1: Tracer - a Partner opens the dashboard and sees the own open tasks, review first and highlighted** - `d74a8aa` (feat)
2. **Task 2: The Partner's visible projects, the empty states and the no-time, no-money leak proof** - `ed62ddc` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified
- `app/Filament/Widgets/PartnerTasks.php` - open-task widget, `FIELDS`, `LIMIT`, `openTaskCount()`, `reviewCount()`, `tasks()`, narrowed `canView()`
- `app/Filament/Widgets/PartnerProjects.php` - visible-project table widget, `FIELDS`, `LIMIT`, `visibleProjectCount()`
- `resources/views/filament/widgets/partner-tasks.blade.php` - rows, badges, inline highlight, empty state, all-tasks link (values only through `{{ }}`)
- `app/Filament/Pages/Dashboard.php` - role-specific widget list, one column, empty-state fallback
- `lang/cs/kokpit.php` - `dashboard.partner_tasks.*`, `dashboard.partner_projects.*` (exact Copywriting Contract)
- `tests/Feature/Dashboard/PartnerDashboardTest.php` - behaviour, field rule, empty states, leak proof
- `tests/Isolation/PanelAccessTest.php`, `tests/Feature/TimeTracking/ProjectTimeOverviewTest.php` - the two pinned empty widget lists changed on purpose (exact discovered set; `ProjectTimeStats` not in the panel list)
- `app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php` - docblock no longer says the panel widget list stays empty

## Decisions Made
- Followed the plan. The widgets are non-lazy (`$isLazy = false`) so the page tests and the empty-state decision see the rendered widget, and both span the full row in a one-column dashboard (tasks first, UI-SPEC U-12).
- `Dashboard::content()` decides on two cheap counts (open tasks, visible projects) before choosing widgets or the stock empty state; the widgets repeat their own queries so they stay correct when mounted alone.

## Deviations from Plan

None - plan executed exactly as written.

Notes (not deviations): the plan's behaviour line "only tasks and no project: the project widget shows its empty state" describes a state the data model cannot produce (a visible task always belongs to a visible project), so the test covers the reachable form instead (no visible project: "Zatím žádné projekty" and its body). The code path is the same. The `ProjectTimeStats` docblock edit is a wording fix forced by the now non-empty panel widget list. The tests were written before the implementation; the first run was already mostly green because the implementation was written in the same step, so the RED evidence is the two test-side failures corrected on the way (a 255-character title limit, array keys in the panel widget list), not a failing production assertion.

## Issues Encountered
- PHPStan refused `orderByRaw()` with a built string (literal-string rule) and an always-true `instanceof`; the SQL fragments became literals (`"tasks"."status"`, `"tasks"."due_date"`) and the redundant check was dropped. Pint's `fully_qualified_strict_types` was fixed by importing `Widget` in the dashboard.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None - no new endpoint, auth path or schema; the two widgets are Livewire components covered by T-08-03 to T-08-06, all mitigated and tested.

## Next Phase Readiness
- Plans 08-15 and 08-20 add the Admin widgets by extending `Dashboard::getWidgets()` for the Admin and the one widget-set expectation in `PanelAccessTest`.
- Full suite green (3079 tests), Pint and Larastan clean, `scripts/check-sensitive.sh` clean.

## Self-Check: PASSED

- Created files exist: `PartnerTasks.php`, `PartnerProjects.php`, `partner-tasks.blade.php`, `PartnerDashboardTest.php`.
- Commits `d74a8aa` and `ed62ddc` are ancestors of HEAD.
- All Task 1 and Task 2 acceptance criteria re-run and passing; plan-level verification (full Pest, Pint, Larastan, sensitive-content check) passing.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
