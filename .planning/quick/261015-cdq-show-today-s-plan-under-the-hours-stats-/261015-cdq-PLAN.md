---
phase: quick-261015-cdq
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Filament/Widgets/TodayPlan.php
  - resources/views/filament/widgets/today-plan.blade.php
  - app/Filament/Pages/Dashboard.php
  - lang/cs/kokpit.php
  - tests/Feature/Dashboard/AdminDashboardTest.php
  - tests/Feature/Dashboard/PartnerDashboardTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/TimeLeakTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - CONTRIBUTING.md
  - .planning/STATE.md
  - .planning/todos/completed/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md
files_deleted:
  - app/Filament/Widgets/TomorrowPlan.php
  - resources/views/filament/widgets/tomorrow-plan.blade.php
  - .planning/todos/pending/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md
autonomous: true
requirements: [RE-04]

estimate:
  tokens: 50000
  raw_tokens: 50000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On /admin the Admin sees the block 'Plán na dnešek' with today's Prague date as its description ('středa 14. 10. 2026' at the frozen test time). It sits directly under the hours stats row (Dnes / Tento týden / Tento měsíc), full width, above the two-column row of unbilled time and deadlines. For the Admin, Dashboard::getWidgets() is exactly [HoursStats, TodayPlan, UnbilledPerClient, UpcomingDeadlines]"
    - "The block lists the signed-in Admin's own Signal tasks for the Europe/Prague day of today, then the recurring templates that fire today and have no task yet, each item once. Tasks of yesterday and tomorrow never appear, nor do another Admin's rows. Shortly after midnight Prague time (22:30 UTC the evening before), the new day's plan shows"
    - "The first three items are always visible. The rest sit in a region that is hidden on every load and opened by one text button (aria-expanded, aria-controls). The count badge and the 'Otevřít plán' link stay. With nothing planned, the empty state reads 'Na dnešek zatím nic nenaplánováno' with the unchanged planner hint"
    - "The block stays read-only. It does not poll. It runs no Signal action, so rendering it never materialises today's recurring templates and the SignalTask count does not change. It has no form control except the toggle. A task already ticked done today is shown struck through with the label 'Splněno'"
    - "A Partner is refused at boot, also when the widget is mounted by force (403). The Partner dashboard and the Phase 8 leak lists never contain 'Plán na dnešek'"
    - "No file in app, resources, lang, tests, routes, config, CONTRIBUTING.md or README.md still names the old widget class, its view, its region id or its Czech heading and empty heading. CONTRIBUTING.md describes TodayPlan (sort 15, full width under the hours). STATE.md records that this quick task replaces the 'plan for tomorrow' part of Phase 08 D-11 on the owner's request"
  artifacts:
    - path: "app/Filament/Widgets/TodayPlan.php"
      provides: "Admin-only, read-only widget of today's Signal plan (Prague day), first three items visible"
      contains: "SignalCalendar::today()"
    - path: "resources/views/filament/widgets/today-plan.blade.php"
      provides: "View of the plan block with the collapsed region today-plan-more and the done marking"
      contains: "today-plan-more"
    - path: "app/Filament/Pages/Dashboard.php"
      provides: "Admin widget order with the plan right after the hours stats"
      contains: "HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class"
    - path: "lang/cs/kokpit.php"
      provides: "Czech copy of the today plan (heading, empty heading, done label)"
      contains: "Plán na dnešek"
    - path: "tests/Feature/Dashboard/AdminDashboardTest.php"
      provides: "Behaviour tests of the today plan: day, Prague midnight edge, materialised template once, no materialisation, done marking, position under the hours"
      contains: "TodayPlan::class"
  key_links:
    - from: "app/Filament/Pages/Dashboard.php"
      to: "app/Filament/Widgets/TodayPlan.php"
      via: "getWidgets() Admin branch, second entry after HoursStats::class"
      pattern: "HoursStats::class, TodayPlan::class"
    - from: "app/Filament/Widgets/TodayPlan.php"
      to: "app/Domain/Signal/Queries/SignalDayReader.php"
      via: "tasks(SignalCalendar::today()) and shadows(SignalCalendar::today(), $tasks), both owner-scoped"
      pattern: "SignalCalendar::today\\(\\)"
    - from: "app/Filament/Widgets/TodayPlan.php"
      to: "resources/views/filament/widgets/today-plan.blade.php"
      via: "protected string $view = 'filament.widgets.today-plan'"
      pattern: "filament.widgets.today-plan"
---

<objective>
Show today's Signal plan on the Admin dashboard instead of tomorrow's, directly under the row of hours stats, as the owner asked in the todo `2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md`.

Plan 08-20 built the read-only block for tomorrow and placed it last on the dashboard. This plan changes four things. The day becomes today, using the Prague day. The heading, the copy and the empty state follow the new day. The widget and its view are renamed so the code stays honest. The widget moves right after `HoursStats`, full width, before the two-column row of unbilled time and deadlines. It stays read-only, collapsed to three items, Admin-only and owner-scoped, as in 08-20.

Purpose: during the day the owner wants today's plan next to today's hours. Tomorrow's plan at the bottom of the page is the wrong day in the wrong place.
Output: the renamed and retargeted `TodayPlan` widget and view, the new dashboard order, the Czech copy, the updated tests and docs, a STATE.md decision line and the todo moved to completed.

Run this before the collapsible-widgets todo (`2026-10-15-collapsible-dashboard-widgets-persisted-in-db.md`), because both touch the dashboard layout. That todo is not part of this plan.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@.planning/todos/pending/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md

Source files the executor reads before changing anything (read each once):
@app/Filament/Widgets/TomorrowPlan.php
@resources/views/filament/widgets/tomorrow-plan.blade.php
@app/Filament/Pages/Dashboard.php
@app/Domain/Signal/Queries/SignalDayReader.php
@app/Domain/Signal/Support/SignalCalendar.php
@tests/Feature/Dashboard/AdminDashboardTest.php (lines 1-150 for setup and helpers, lines 809-1040 for the plan block)

Facts established during planning (do not re-derive):
- Filament renders the dashboard widgets in the array order of `getWidgets()`. The overridden `Dashboard::getWidgets()` is not re-sorted by `$sort` (see `vendor/filament/filament/src/Pages/Dashboard.php`, `getWidgetsContentComponent()`). `$sort` only orders the panel registry, and `PanelAccessTest` compares that registry with `toEqualCanonicalizing`, so the order there does not matter. The current sorts are HoursStats 10, UnbilledPerClient 30, UpcomingDeadlines 40 and the plan widget 50. Sort 20 is the reserved band for the invoice and payment tiles of Phases 10 and 11.
- The Admin grid is `['default' => 1, 'lg' => 2]`. `HoursStats` and the plan widget both span `'full'`. `UnbilledPerClient` and `UpcomingDeadlines` share the `lg` row. `HoursStats`, `UnbilledPerClient` and `UpcomingDeadlines` are not lazy, so their text is in the `/admin` page HTML.
- `SignalCalendar::today()` is the Europe/Prague day as `YYYY-MM-DD`. Only `today()` reads the clock, so `travelTo` freezes it. `SignalCalendar::formatLong('2026-10-14')` is `středa 14. 10. 2026`, because `kokpit.signal.calendar.weekdays` is 0-based and lowercase (2 = `středa`).
- `SignalDayReader::shadows($day, $tasks)` returns the templates that fire on the day, minus those that already have a task on it. `MaterializeSignalRecurring` uses the same "still missing" test. For today, the result is therefore exactly the templates the planner will turn into tasks when the Admin opens the day. The planner's `SignalDay` shows shadows only for later days, because it materialises today when it mounts. The widget must never call `MaterializeSignalRecurring` or any other Signal action, because a GET of the dashboard must not write.
- Constraints on `signal_tasks`: `CHECK (is_done = (completed_at IS NOT NULL))`. A composite FK `(recurring_id, user_id)` points to a template of the same owner. A partial unique index covers `(recurring_id, for_date)`. Template weekdays are `smallint[]` with Monday = 0, so Wednesday = 2 and Thursday = 3.
- `AdminDashboardTest` freezes time at `2026-10-14 08:00 UTC` (Wednesday, 10:00 in Prague) in `beforeEach`. The plan helpers (`adminDashPlanTask`, `adminDashPlanTemplate`, `adminDashPlanHtml`, `adminDashPlanParts`, `adminDashPlanSix`) currently default to 2026-10-15 and the Thursday weekday 3. Pest helper functions are global across files, so any new helper keeps the `adminDashPlan` prefix.
- The planner marks a done task in `resources/views/livewire/signal/day.blade.php` (around line 114) with `text-decoration: line-through; opacity: 0.6;`. `SignalTask` has `is_done` (boolean cast) and `completed_at`.
- `PartnerContext::isAdmin()` reads the signed-in user, so `$this->actingAs($admin)` followed by `(new Dashboard)->getWidgets()` returns the Admin list.
- PHP tooling runs only in DDEV, because the host has no database: `ddev exec php -d memory_limit=1G vendor/bin/pest ...`, `ddev exec vendor/bin/pint --test ...`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G ...`, `ddev composer ci`. Never run two DDEV test processes in parallel.
- Fictional data only. Planner titles look like `Example plan ...`. The project is public AGPL.

Claude's discretion, decided here: a task of today that is already ticked done is shown struck through, like the planner shows it, with a short muted label `Splněno` so the state is not carried by styling alone. For tomorrow this never came up, but for today a finished task shown as open would mislead the owner. Nothing else is added: no tick, no link per item and no reordering of done items.
</context>

<tasks>

<!-- planner-discipline-allow: TomorrowPlan -->
<!-- planner-discipline-allow: tomorrow-plan -->
<!-- planner-discipline-allow: Plán na zítřek -->
<!-- planner-discipline-allow: Na zítřek zatím -->

<task type="tracer" tdd="true">
  <name>Task 1: End-to-end today's plan under the hours — rename, read today (Prague), new copy, done marking, dashboard order, tests</name>
  <files>app/Filament/Widgets/TodayPlan.php, resources/views/filament/widgets/today-plan.blade.php, app/Filament/Pages/Dashboard.php, lang/cs/kokpit.php, tests/Feature/Dashboard/AdminDashboardTest.php</files>
  <behavior>
    - Day and copy: with today = Wednesday 2026-10-14, the widget shows the heading `Plán na dnešek`, the description `středa 14. 10. 2026`, `6 úkolů` and `Otevřít plán`. It shows today's five tasks plus a Wednesday template (weekdays [2]), three in the visible list and three in the region `today-plan-more`, which is hidden and has a toggle button with aria-expanded="false" and aria-controls="today-plan-more". The text `Zobrazit dalších 3` is present. A task of 2026-10-13 and a task of 2026-10-15 are absent.
    - Prague day at midnight: after travelling to `2026-10-14 22:30:00 UTC` (00:30 on 2026-10-15 in Prague), a task of 2026-10-15 shows with `čtvrtek 15. 10. 2026` and a task of 2026-10-14 does not.
    - Materialised template once: a Wednesday template and a task of today that carries its `recurring_id` render as exactly one item, marked `Opakuje se`, and the badge reads `1 úkol`.
    - No materialisation: rendering the widget with five tasks and a Wednesday template that fires today leaves `SignalTask::query()->count()` at 5 and `SignalRecurringTask` at 1. The HTML has no `wire:poll`.
    - Done marking: a task of today with `is_done` true and `completed_at` set renders its title with `text-decoration: line-through` and the label `Splněno`. An open task of the same day has neither.
    - Kept from 08-20 and updated to today: three items show no toggle and the badges read 1/2/3 (`1 úkol`, `2 úkoly`, `3 úkoly`). A template carries `Opakuje se` and the circular-arrow svg, and every item carries its category and dot colour. A long title is cut with a tooltip. Markup is escaped. The block is read-only: the only link is `/admin/signal`, there is exactly one `<button`, and there is no input, form, select, textarea, `wire:click`, `wire:sort` or `wire:submit`. It reads only the own planner and never another Admin's rows. The empty state reads `Na dnešek zatím nic nenaplánováno` with `Plán si můžete připravit v nabídce Signal, Dnes a zítra.` and the link.
    - Position: acting as the Admin, `(new Dashboard)->getWidgets()` is exactly `[HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class]`, and `(new TodayPlan)->getColumnSpan()` is `'full'`. In the `/admin` page text, `Tento měsíc` comes before `Plán na dnešek`, which comes before both `Nevyfakturováno tento měsíc` and `Nejbližší termíny`. Assert `toContain` for each marker before comparing positions, so a missing marker cannot pass as position 0.
    - Partner: `Livewire::actingAs($partner)->test(TodayPlan::class)->assertForbidden()`. The Partner's `/admin` text contains neither `Plán na dnešek`, nor the planted title, nor `Na dnešek zatím nic nenaplánováno`.
  </behavior>
  <action>
    RED first, in `tests/Feature/Dashboard/AdminDashboardTest.php`.
    Switch the import and every use to `App\Filament\Widgets\TodayPlan`. Rename the describe to `the plan for today`.
    Change the helper defaults: `adminDashPlanTask` defaults to day `'2026-10-14'`. It gains two optional trailing parameters, `?SignalRecurringTask $template = null` and `bool $done = false`. The template sets `recurring_id`. Done sets `is_done` true and `completed_at` to now, because of the done CHECK constraint.
    `adminDashPlanSix` and every template that should fire today use weekdays [2]. Rewrite its comment to say "Today is Wednesday 2026-10-14".
    Update every assertion to the behaviours above: the heading, the date, the region id `today-plan-more` and the empty heading. The days that must stay out are now 2026-10-13 and 2026-10-15.
    Replace the test that called the plan the last widget with the position test from the behaviour list. Add the tests for the Prague midnight edge, the materialised template shown once and the done marking. Keep the no-poll test and add a comment that the unchanged count proves the widget does not materialise today's template.
    Update the docblock of `adminDashPlanHtml` and the file header comment so they speak of today's plan.
    Run the file and confirm the plan tests fail (the class does not exist yet).

    GREEN.
    (1) Use `git mv app/Filament/Widgets/TomorrowPlan.php app/Filament/Widgets/TodayPlan.php` and `git mv resources/views/filament/widgets/tomorrow-plan.blade.php resources/views/filament/widgets/today-plan.blade.php`, so history follows the files.
    (2) In `TodayPlan`: rename the class and point `$view` at `filament.widgets.today-plan`. In both `items()` and `getViewData()`, read the day once with `SignalCalendar::today()` instead of tomorrow. Add a `done` key to every item: `$task->is_done` for tasks and false for templates, and extend the `@return` array shape to match. Set `$sort` to 15, so the registry order matches the page and the reserved band 20 stays free for Phases 10 and 11.
    Keep the `AccessRule` attribute and its reason, `EnforcesWidgetAccessRule`, `VISIBLE = 3`, `$isLazy = false` and `$columnSpan = 'full'`.
    Rewrite the class docblock for today. It covers: the signed-in Admin's own Signal plan for today (Europe/Prague day), read-only, three items visible and the rest collapsed on every load (D-11, UI-SPEC E4, U-9). The items are today's real tasks, then the templates that fire today and have not been materialised yet. The planner turns those into tasks when the Admin opens the day, and this widget never does. It calls no Signal action, so a dashboard GET never writes. A done task is marked. Partner refusal and no polling are unchanged.
    (3) In `today-plan.blade.php`: set `$regionId` to `today-plan-more`. Rewrite the header comment for today and mention the done marking. On an item whose `done` is true, append `text-decoration: line-through; opacity: 0.6;` to the title span's style, the same as the planner. After the title, add a muted span (`$mutedStyle`) with `__('kokpit.dashboard.plan.done')`. Every value still goes through double-brace escaping, and spacing stays in 0.25rem steps.
    (4) In `lang/cs/kokpit.php`, under `dashboard.plan`: set `heading` to `Plán na dnešek` and `empty_heading` to `Na dnešek zatím nic nenaplánováno`, and add `'done' => 'Splněno'`. Keep `empty_description`, which names the planner page and stays true. Rewrite the section comment above `hours` to end with "and the plan for today". Leave `signal.today.tomorrow` (`Zítra`) untouched, because it belongs to the planner page.
    (5) In `Dashboard.php`: the Admin branch of `getWidgets()` returns `[HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class]`. Swap the import. Rewrite the class docblock sentence so the Admin gets the hours worked, right under them the Signal plan for today (full width), then the unbilled time per client and the nearest task deadlines side by side.

    No comment, docblock, test name or Czech string may still name the old class, view, region id or headings. Run the test file until it is green, then commit, following the CLAUDE.md review (git status, git diff --staged, scripts/check-sensitive.sh on the changed files). Never bypass lefthook.

    Expected side effect: until Task 2 lands, `PanelAccessTest`, `TimeLeakTest` and `RepositoryFilesTest` still name the old class and fail. Do not run them in this task.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Dashboard/AdminDashboardTest.php && ddev exec vendor/bin/pint --test app/Filament/Widgets/TodayPlan.php app/Filament/Pages/Dashboard.php tests/Feature/Dashboard/AdminDashboardTest.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Filament/Widgets/TodayPlan.php app/Filament/Pages/Dashboard.php && test -f app/Filament/Widgets/TodayPlan.php && test -f resources/views/filament/widgets/today-plan.blade.php && test ! -e app/Filament/Widgets/TomorrowPlan.php && test ! -e resources/views/filament/widgets/tomorrow-plan.blade.php && grep -q 'SignalCalendar::today()' app/Filament/Widgets/TodayPlan.php && grep -q 'HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class' app/Filament/Pages/Dashboard.php && ! grep -n -e 'TomorrowPlan' -e 'tomorrow-plan' -e 'Plán na zítřek' -e 'Na zítřek zatím' app/Filament/Widgets/TodayPlan.php resources/views/filament/widgets/today-plan.blade.php app/Filament/Pages/Dashboard.php lang/cs/kokpit.php tests/Feature/Dashboard/AdminDashboardTest.php</automated>
  </verify>
  <done>On the Admin dashboard, "Plán na dnešek" with today's Prague date renders right under the hours stats, full width, above unbilled time and deadlines. It lists today's own tasks and the templates that are not yet materialised, each once, three visible and the rest collapsed. Done tasks are struck through with "Splněno". The empty state is updated. It writes nothing and refuses a Partner. AdminDashboardTest, Pint and PHPStan on the two classes pass. The widget and view exist only under their new names.</done>
</task>

<task type="auto">
  <name>Task 2: Carry the rename and the new heading into the isolation tests, the doc-contract test and CONTRIBUTING</name>
  <files>tests/Isolation/PanelAccessTest.php, tests/Isolation/TimeLeakTest.php, tests/Feature/Dashboard/PartnerDashboardTest.php, tests/Feature/Repo/RepositoryFilesTest.php, CONTRIBUTING.md</files>
  <action>
    (1) `tests/Isolation/PanelAccessTest.php`: swap the import for `App\Filament\Widgets\TodayPlan`. In the `toEqualCanonicalizing` list of discovered panel widgets (around line 92), replace the old class with `TodayPlan::class`. Change nothing else.
    (2) `tests/Isolation/TimeLeakTest.php`: swap the import. In the forced-mount list of the "answers 403 to a Partner who mounts any Phase 8 page, table or widget" test (around line 437), use `TodayPlan::class`. In `leakPhase8Forbidden()` (around line 337), replace the old heading string with `'Plán na dnešek'`, so the leak check still covers the heading that now renders.
    (3) `tests/Feature/Dashboard/PartnerDashboardTest.php`: in the `$never` list (around line 392), replace the old heading string with `'Plán na dnešek'`.
    (4) `tests/Feature/Repo/RepositoryFilesTest.php`: in the Phase 8 `$names` map (around line 645), replace the entry with `'TodayPlan' => 'App\Filament\Widgets\TodayPlan'`. Leave every other entry unchanged.
    (5) `CONTRIBUTING.md`, the Dashboard bullet (around line 107). Rewrite only the two affected spans and keep every other sentence word for word. The Admin list becomes: `HoursStats` (sort 10), `TodayPlan` (15, full width right under the hours), `UnbilledPerClient` (30) and `UpcomingDeadlines` (40). The sentence about the Signal plan becomes: the Signal plan of today (the Prague day) is read-only and owner-scoped, and it never materialises the day's recurring templates. The sentence on the reserved sort band 20 and the "four Admin widgets" wording stay as they are.
    Then grep `app resources lang tests routes config CONTRIBUTING.md README.md` for the old class, view and headings, as in the verify, and fix any hit. Commit after the verify passes, with the same CLAUDE.md review as Task 1.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Isolation/PanelAccessTest.php tests/Isolation/TimeLeakTest.php tests/Feature/Dashboard/PartnerDashboardTest.php tests/Feature/Repo/RepositoryFilesTest.php tests/Feature/Dashboard/AdminDashboardTest.php && ddev exec vendor/bin/pint --test tests/Isolation/PanelAccessTest.php tests/Isolation/TimeLeakTest.php tests/Feature/Dashboard/PartnerDashboardTest.php tests/Feature/Repo/RepositoryFilesTest.php && ! grep -rn -e 'TomorrowPlan' -e 'tomorrow-plan' -e 'Plán na zítřek' -e 'Na zítřek zatím' app resources lang tests routes config CONTRIBUTING.md README.md && grep -q 'TodayPlan' CONTRIBUTING.md && grep -q "'Plán na dnešek'" tests/Isolation/TimeLeakTest.php && grep -q "'Plán na dnešek'" tests/Feature/Dashboard/PartnerDashboardTest.php</automated>
  </verify>
  <done>The isolation, Partner dashboard and doc-contract tests name `TodayPlan` and the new heading, and they pass. CONTRIBUTING.md describes the today plan at sort 15 under the hours. No file in app, resources, lang, tests, routes, config, CONTRIBUTING.md or README.md names the old widget, view or Czech headings.</done>
</task>

<task type="auto">
  <name>Task 3: Record the D-11 change, run the full gate, move the todo to completed</name>
  <files>.planning/STATE.md, .planning/todos/completed/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md</files>
  <action>
    (1) In `.planning/STATE.md`, append one bullet as the last item of `### Decisions`, directly before `### Pending Todos`: "- [Quick 261015-cdq]: the Admin dashboard shows the Signal plan of today (Europe/Prague day) instead of tomorrow, as TodayPlan (sort 15) right under HoursStats, full width, before the unbilled and deadlines row; still read-only, owner-scoped, three items visible, and it never materialises today's recurring templates (it shows them as not-yet-materialised items); a done task is struck through with Splněno; replaces the 'plan for tomorrow' part of Phase 08 D-11 on the owner's request". Do not edit any Phase 08 artifact or the Quick Tasks Completed table, which the quick orchestrator owns.
    (2) Move the todo with `git mv .planning/todos/pending/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md .planning/todos/completed/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md`. Do not change its content.
    (3) Run `ddev composer ci` alone (Pest, Pint, PHPStan, licence check). Fix anything this plan caused. If a failure is clearly unrelated to these files, record it in the SUMMARY with the reason instead of fixing it.
    (4) Follow the CLAUDE.md commit review: run `git status`, `git diff --staged`, then `scripts/check-sensitive.sh` over every existing file of this plan as in the verify. Commit only when all of them are clean, and never bypass lefthook.
  </action>
  <verify>
    <automated>grep -q 'Quick 261015-cdq' .planning/STATE.md && test -f .planning/todos/completed/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md && test ! -e .planning/todos/pending/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md && ddev composer ci && scripts/check-sensitive.sh app/Filament/Widgets/TodayPlan.php resources/views/filament/widgets/today-plan.blade.php app/Filament/Pages/Dashboard.php lang/cs/kokpit.php tests/Feature/Dashboard/AdminDashboardTest.php tests/Feature/Dashboard/PartnerDashboardTest.php tests/Isolation/PanelAccessTest.php tests/Isolation/TimeLeakTest.php tests/Feature/Repo/RepositoryFilesTest.php CONTRIBUTING.md .planning/STATE.md .planning/todos/completed/2026-10-15-show-todays-plan-under-hours-stats-on-admin-dashboard.md</automated>
  </verify>
  <done>STATE.md records that the plan of today replaces the plan of tomorrow from D-11. The todo is in `.planning/todos/completed/` (moved with git mv) and no longer in pending. `ddev composer ci` is green. `scripts/check-sensitive.sh` reports nothing for the twelve files.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Partner browser -> Livewire widget mount | a Partner can forge a mount or update of any Livewire component, including the renamed plan widget |
| signed-in Admin -> Signal tables of another Admin | the planner is personal; one Admin must never read another's plan |
| planner title (user text) -> dashboard HTML | titles typed in the planner are rendered on the dashboard |
| dashboard GET -> Signal tables | rendering a read page must not write (materialise today's templates) |
| repository (public, AGPL) -> world | code, tests, docs and planning files are public |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261015-cdq-01 | Information Disclosure | `TodayPlan` mounted by a Partner | high | mitigate | `#[AccessRule(Audience::AdminOnly)]` and `EnforcesWidgetAccessRule` carried over unchanged through the rename; forced-mount 403 asserted in AdminDashboardTest and TimeLeakTest; the panel registry list in PanelAccessTest names the new class; `Plán na dnešek` is in both Partner never-lists |
| T-261015-cdq-02 | Information Disclosure | another Admin's plan | medium | mitigate | Reads only through `SignalDayReader` (owner scope of the Signal models); the "reads only the own planner" test is kept with today's day and a Wednesday template |
| T-261015-cdq-03 | Tampering (stored XSS) | planner titles and the new done label in `today-plan.blade.php` | medium | mitigate | Every value through double-brace escaping, no raw output; the escape test is kept; the done label is a fixed translation |
| T-261015-cdq-04 | Tampering (integrity) | dashboard render materialising today's recurring templates | medium | mitigate | The widget calls no Signal action (only `SignalDayReader` reads); a test with a template that fires today asserts the SignalTask count is unchanged after rendering |
| T-261015-cdq-05 | Information Disclosure | test fixtures, docs, STATE.md entry | low | mitigate | Fictional `Example plan ...` titles, no real names or values; `scripts/check-sensitive.sh` over every touched file, plus the lefthook gitleaks hook |
| T-261015-cdq-SC | Tampering | npm/pip/cargo/composer installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev composer ci` is green (Pest, Pint, PHPStan, licence check), run alone.
- `! grep -rn -e 'TomorrowPlan' -e 'tomorrow-plan' -e 'Plán na zítřek' -e 'Na zítřek zatím' app resources lang tests routes config CONTRIBUTING.md README.md` succeeds.
- `grep -n 'HoursStats::class, TodayPlan::class' app/Filament/Pages/Dashboard.php` shows the Admin order.
- Manual sanity check, not a gate: `/admin` as the Admin shows "Plán na dnešek" directly under the Dnes / Tento týden / Tento měsíc stats.
- `scripts/check-sensitive.sh` over the twelve existing files is clean.
</verification>

<success_criteria>
- The Admin sees today's plan (Prague day), not tomorrow's, directly under the hours stats, full width, before the unbilled and deadlines row.
- The block stays read-only, owner-scoped, Admin-only, collapsed to three items, and never writes to the planner.
- The widget, view, copy, tests and docs carry the today naming. Nothing names the old widget.
- STATE.md records the change to D-11, and the todo is in completed.
</success_criteria>

<source_audit>
| Source | Item | Coverage |
|--------|------|----------|
| GOAL | Show today's plan under the hours stats on the Admin dashboard | Task 1 |
| TODO Solution | Read today from the Signal planner using the Prague day | Task 1 (`SignalCalendar::today()`, midnight-edge test) |
| TODO Solution | Heading "Plán na dnešek", Czech copy and empty state | Task 1 (lang, view, tests); Task 2 (leak lists) |
| TODO Solution | Rename widget and view if that keeps the code honest | Task 1 (git mv, class, view, region id); Task 2 (every other reference, CONTRIBUTING) |
| TODO Solution | Move it right after HoursStats, full width, before the two-column row | Task 1 (`getWidgets()`, sort 15, position test) |
| TODO Solution | Update the tests that pin the order and the copy | Task 1 (AdminDashboardTest); Task 2 (PanelAccessTest, TimeLeakTest, PartnerDashboardTest, RepositoryFilesTest) |
| TODO Solution | Keep it read-only and collapsed to three items as in 08-20 | Task 1 (kept tests plus the no-materialisation test) |
| TODO Solution | Run before the collapsible-widgets todo | Ordering note in the objective; that todo is out of scope |
| REQ | RE-04 Admin dashboard | Task 1 |
| CONTEXT | Phase 08 D-11 "plan for tomorrow" | Replaced on the owner's request (Task 1); recorded in STATE.md (Task 3) |
| DISCRETION | Done task of today shown struck through with "Splněno" | Task 1 |
| CONSTRAINT | Move the todo to completed with git mv as the last task | Task 3 |
</source_audit>

<output>
Create `.planning/quick/261015-cdq-show-today-s-plan-under-the-hours-stats-/261015-cdq-SUMMARY.md` when done. Include:
- the RED run of AdminDashboardTest (which tests failed, and how) and the GREEN run;
- the commits and their files, and confirmation that `git log --follow` shows the renamed widget and view keep their history;
- that this replaces the "plan for tomorrow" part of Phase 08 D-11, so the Phase 08 verifier and later planners see it;
- the done-marking discretion, so the owner can confirm or drop it;
- any `ddev composer ci` failure judged unrelated, with the reason.
</output>
