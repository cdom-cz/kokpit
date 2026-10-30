---
phase: 08-exchange-rates-and-reports
plan: 15
subsystem: ui
tags: [filament, dashboard, widgets, stats, table-widget, per-entry-amounts, cnb-rates, livewire]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-02 role-specific Dashboard::getWidgets()/content(), the Partner widgets and the panel widget-set expectation"
  - phase: 08-exchange-rates-and-reports
    provides: "08-09 TimeReportReader::entries(), ReportFilters::period(), EntryAmounts::of(), RateBook::load(), TimeReport and TimeReportPage::getUrl()"
  - phase: 06-time-tracking
    provides: "TimesheetQuery ranges and summary, PragueDay, DurationFormat, TimerClock, the four time Livewire events"
provides:
  - "App\\Filament\\Widgets\\HoursStats: today, this week (Monday to Sunday) and this month of the signed-in Admin as H:MM, running timer counted and said so"
  - "App\\Filament\\Widgets\\UnbilledPerClient: unbilled billable time of the current Prague month per client, hours, client-currency amount and CZK, with the Celkem footer, notes and row links to the report"
  - "Admin branch of Dashboard::getWidgets() (HoursStats, UnbilledPerClient), content() without the empty state, two columns from lg"
  - "The whole kokpit.dashboard.hours/unbilled/deadlines/plan copy (deadlines and plan rows are for plan 08-20); kokpit.dashboard.empty_description_admin removed"
affects: [08-20 dashboard deadlines and tomorrow plan, 08-22 Phase 8 isolation sweep, Phase 10 and 11 dashboard band at sort 20]

actuals:
  tokens: 12800
  tasks: 2
  commits: 3

plan_head_before: 3fa34ff2b4c99a1af106e0e7308bd7dd535d7fb4
plan_head_after: 1ff55122331f148ce16dd552509e7495e0648bbb
commits: 3

tech-stack:
  added: []
  patterns:
    - "Table widget over computed records: Table::records() returning arrays, ViewColumn cells and a contentFooter view, no query"
    - "Dashboard money is the report's own sum of rounded per-entry amounts, proven equal to TimeReport::build() by a test"
    - "Livewire event listeners are asserted by name through SupportEvents::getListenerEventNames(), because a dispatch re-renders the component even without a listener"
    - "Visible-only poll through the stock pollingInterval string ('visible.60s' renders wire:poll.visible.60s)"

key-files:
  created:
    - app/Filament/Widgets/HoursStats.php
    - app/Filament/Widgets/UnbilledPerClient.php
    - resources/views/filament/widgets/unbilled-per-client-cell.blade.php
    - resources/views/filament/widgets/unbilled-per-client-footer.blade.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
  modified:
    - app/Filament/Pages/Dashboard.php
    - lang/cs/kokpit.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Feature/Dashboard/PartnerDashboardTest.php

key-decisions:
  - "The footer Celkem of the unbilled table covers every client with unbilled time, not only the ten rows shown, like the report whose totals cover more than its rendered rows"
  - "An unbilled entry of zero seconds does not make a client appear in the table (nothing to bill), its hours and amounts would be zero anyway"
  - "The unbilled widget takes one column (full width of one grid cell from lg), the hours stats the full row, as UI-SPEC E2 and E1 say; until plan 08-20 adds the deadlines widget the table sits alone in the left half from lg"
  - "Both widgets poll with 'visible.60s' (wire:poll.visible.60s) so a hidden tab does not poll, matching the timer bar and the UI-SPEC wire:poll.visible rule"
  - "Row and heading links carry period=this_month explicitly (ReportFilters::toQuery() would leave the default out), so the report opens on the month of the table"

patterns-established:
  - "Cells that hold more than one piece of text (name + badge, amount + marker) are one small Blade view selected by a kind value through ViewColumn::viewData(), values only through {{ }}"
  - "Per-widget leak proof for a Partner: Livewire::actingAs(partner)->test(Widget)->assertForbidden() plus the Partner dashboard text free of the Admin headings"

requirements-completed: [RE-04]

coverage:
  - id: D1
    description: "The Admin dashboard replaces the empty page: Dnes, Tento týden (Monday to Sunday, description 12. 10. – 18. 10.) and Tento měsíc (Fakturovatelné :duration) show the signed-in Admin's own time as H:MM; another user's time and other days or months are not counted; a Prague day starts at Prague midnight"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows today, this week and this month as H:MM with a running timer at its elapsed time"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#starts a Prague day at Prague midnight, whatever the UTC date says"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#treats the week as Monday to Sunday"
        status: pass
    human_judgment: false
  - id: D2
    description: "A running timer is counted at its elapsed time; the Dnes description says 'Včetně běžícího časovače' in warning text with the clock icon, otherwise 'Odpracováno dnes'"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#says \"Odpracováno dnes\" when no timer runs"
        status: pass
    human_judgment: true
    rationale: "The warning colour and the clock icon of the running description are markup the test cannot judge; whether it reads as the running state in light and dark mode is a visual check (recorded in the broken-windows ledger)"
  - id: D3
    description: "Nevyfakturováno tento měsíc lists per client the hours, the client-currency amount and the CZK amount of the unbilled billable entries of the month (billed, non-billable and older entries excluded), the CZK figure equal to the report's unbilled CZK for that client and month, a CZK client with the same amount in both columns"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows the hours, the amount in the client currency and the CZK amount of the unbilled billable entries"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#equals the unbilled figures of the report for the same client and month"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows the same amount in both money columns for a CZK client"
        status: pass
    human_judgment: false
  - id: D4
    description: "Fixed-price hours show dashes with the Pevná cena badge and the note; a foreign currency without a stored rate shows its amount and the Chybí kurz marker with the missing-rate note and never a zero"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows hours and dashes with the \"Pevná cena\" badge and the note for a client with fixed-price hours only"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#shows the amount and the missing-rate marker for a foreign currency without a stored rate"
        status: pass
    human_judgment: false
  - id: D5
    description: "Rows ordered by hours descending and capped at 10, the Celkem footer totalling every client, row links to the report of the client and month, the heading link, the empty state 'Nic k vyfakturování'"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#orders by hours, lists at most 10 clients and totals every client in the footer"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#links every row to the report of the client and the month and the heading to the report of the month"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#says \"Nic k vyfakturování\" when nothing is waiting to be invoiced"
        status: pass
    human_judgment: false
  - id: D6
    description: "Both widgets re-render on timer-started, timer-stopped, time-entry-saved and time-entry-deleted and poll every 60 s while visible; the reserved invoices and payments band renders nothing"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#listens to the four time events and reads the figures again after one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#polls every 60 seconds and ticks nowhere"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#renders zero hours on an empty day and no band for invoices and payments"
        status: pass
    human_judgment: false
  - id: D7
    description: "A Partner gets 403 mounting HoursStats or UnbilledPerClient, sees none of the Admin headings, and the Partner dashboard of plan 08-02 is unchanged"
    requirement: RE-04
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#is refused to a Partner, also when the widget is mounted by force"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#is refused to a Partner, also when mounted by force, and absent from the Partner dashboard"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/PartnerDashboardTest.php (24 tests unchanged except the Admin dashboard case)"
        status: pass
    human_judgment: false
  - id: D8
    description: "Layout of the Admin dashboard: one column below lg, two from lg, the hours stats across the row and the unbilled table in one cell, the badges and the footer notes reading well"
    verification: []
    human_judgment: true
    rationale: "A visual layout and contrast judgment in a browser, light and dark; no automated test asserts it. Recorded in the broken-windows ledger as an unrun verify."

duration: 25min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 15: Admin Dashboard Time and Money Summary

**Admin dashboard with the signed-in Admin's own hours for today, this week and this month (running timer included) and an unbilled-time-per-client table that sums the report's own per-entry amounts in the client currency and CZK**

## Performance

- **Duration:** about 25 min (the start time was not recorded; reconstructed from the commit times)
- **Started:** 2026-10-14T21:15:00Z (reconstructed)
- **Completed:** 2026-10-14T21:41:00Z
- **Tasks:** 2 (3 commits)
- **Files modified:** 9 (5 created, 4 modified)

## Accomplishments

- `HoursStats` shows Dnes, Tento týden and Tento měsíc from `TimesheetQuery` over the Prague calendar, the week as Monday to Sunday with the description `12. 10. – 18. 10.`, the month with its billable line; a running timer counts at its elapsed time and turns the description into "Včetně běžícího časovače" in warning text with the clock icon.
- `UnbilledPerClient` reads the entries of the month through `TimeReportReader`, prices each once with `EntryAmounts::of()` under one `RateBook::load()`, and lists per client the hours, the client-currency amount and the CZK amount; a test proves the CZK and amount figures equal `TimeReport::build()` for the same client and month. Fixed-price hours show dashes with the "Pevná cena" badge, a missing CNB rate shows "Chybí kurz" and the note, the footer "Celkem" sums hours and CZK, each row links to `/admin/time-report?client=…&period=this_month`.
- `Dashboard` returns `[HoursStats, UnbilledPerClient]` for the Admin, renders them with no empty state and two columns from lg; the reserved invoices and payments band renders nothing. The whole Admin copy (hours, unbilled, deadlines, plan) is in `kokpit.dashboard.*` and `empty_description_admin` is gone.

## Task Commits

1. **Task 1: Tracer - the Admin opens the dashboard and sees the hours worked today, this week and this month** - `261b6a0` (feat)
2. **Task 2: Unbilled billable time of the month per client, with money in the client currency and CZK** - `1c66344` (feat), `1ff5512` (test: stored markup in a client name is escaped)

**Plan metadata:** recorded in the docs commit that follows this summary.

### TDD gate notes

- **Task 1 (tracer) had no failing run.** Tests and the widget were written in the same step, so the first run was already green. To prove the listener test is not vacuous, the `timer-stopped` listener was removed from `HoursStats` once: the test failed on the listener names (the first version of the test, which only dispatched an event and read the figure, stayed green without any listener, so it was replaced by an assertion on `SupportEvents::getListenerEventNames()` plus a re-read check). The listener was restored byte for byte before the commit.
- **Task 2 (tdd="true"):** the ten widget tests were written first and run before the widget existed: they failed because the "Nevyfakturováno tento měsíc" heading was absent from the rendered widget (the planned missing behavior, not a syntax or fixture fault; the Partner and listener tests failed on the missing class). Test and implementation landed in one commit as the plan has one task commit; no separate RED commit was made.

## Files Created/Modified

- `app/Filament/Widgets/HoursStats.php` - stats widget, sort 10, full width, Admin only, four listeners, `visible.60s` poll
- `app/Filament/Widgets/UnbilledPerClient.php` - table widget over computed records, sort 30, `LIMIT = 10`, `rows()`, header link, row links, footer, empty state
- `resources/views/filament/widgets/unbilled-per-client-cell.blade.php` - client name with the Pevná cena badge, CZK amount with the Chybí kurz marker
- `resources/views/filament/widgets/unbilled-per-client-footer.blade.php` - Celkem cells and the notes row
- `app/Filament/Pages/Dashboard.php` - Admin widget list, `getColumns()`, `content()` without the Admin empty state
- `lang/cs/kokpit.php` - `dashboard.hours.*`, `unbilled.*`, `deadlines.*`, `plan.*`; `empty_description_admin` removed
- `tests/Feature/Dashboard/AdminDashboardTest.php` - 23 tests; `tests/Isolation/PanelAccessTest.php`, `tests/Feature/Dashboard/PartnerDashboardTest.php` - the Admin dashboard expectations changed on purpose

## Decisions Made

See `key-decisions`. The one later plans must know: `Dashboard::getWidgets()` for the Admin is `[HoursStats::class, UnbilledPerClient::class]` and plan 08-20 appends its two widgets there and to the widget-set expectation in `PanelAccessTest`; the two lines of copy for deadlines and the tomorrow plan are already in `kokpit.dashboard.deadlines.*` and `kokpit.dashboard.plan.*`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A Partner dashboard test pinned the removed Admin key and the old Admin empty state**
- **Found during:** Task 1 (removal of `kokpit.dashboard.empty_description_admin`)
- **Issue:** `tests/Feature/Dashboard/PartnerDashboardTest.php` "keeps the Admin dashboard ... unchanged" asserted the removed key and the absence of any widget. It is not in the plan's file list; UI-SPEC F-12 names only `PanelAccessTest`.
- **Fix:** the case now asserts the Admin sees the hours stats and none of the Partner widgets or the empty state, and still asserts the 403 for a Partner without a client; the Partner expectations are untouched.
- **Files modified:** tests/Feature/Dashboard/PartnerDashboardTest.php
- **Verification:** `tests/Feature/Dashboard` green; full suite green
- **Committed in:** 261b6a0

**2. [Rule 2 - Missing critical] One more small Blade view for the table cells**
- **Found during:** Task 2
- **Issue:** the client cell (name plus "Pevná cena" badge) and the CZK cell (amount plus "Chybí kurz" marker) hold two pieces of text; a `TextColumn` with `html()` would pass the markup through Filament's sanitizer, which can strip the badge, and would put client names near an HTML path (T-08-50).
- **Fix:** `unbilled-per-client-cell.blade.php`, one view chosen by `viewData(['kind' => ...])`, values only through `{{ }}`.
- **Files modified:** resources/views/filament/widgets/unbilled-per-client-cell.blade.php
- **Verification:** `escapes stored markup in a client name` and the widget text tests in `AdminDashboardTest`
- **Committed in:** 1c66344, 1ff5512

**3. [Rule 1 - Bug] The 60 s poll of the plan would not have been visible-only**
- **Found during:** Task 2 (reading how Filament writes the attribute)
- **Issue:** the stock `'60s'` renders `wire:poll.60s`, which also polls a hidden tab; UI-SPEC E asks for `wire:poll.visible`.
- **Fix:** `'visible.60s'` on both widgets renders `wire:poll.visible.60s`, the form the timer bar uses; the poll test asserts it.
- **Files modified:** app/Filament/Widgets/HoursStats.php, app/Filament/Widgets/UnbilledPerClient.php
- **Verification:** `polls every 60 seconds` tests of both widgets
- **Committed in:** 1c66344

---

**Total deviations:** 3 auto-fixed (1 bug, 1 missing critical, 1 blocking)
**Impact on plan:** All three are small and inside the plan's goal; no scope was added beyond one view file and one extra test edit.

## Issues Encountered

- Page-level text assertions for client names are unreliable: the timer bar and the "Poslední záznamy" panel of the panel shell name clients too. The unbilled tests therefore assert on the widget's own HTML (`Livewire::test(UnbilledPerClient::class)->html()`), and one test checks that the widget is part of the Admin page.
- Estimate was 49k tokens; the realized diff is about 12.8k on the same chars/4 scale.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None. The deadlines and tomorrow-plan copy under `kokpit.dashboard.deadlines.*` and `kokpit.dashboard.plan.*` is in place on purpose for plan 08-20, which builds those widgets; nothing renders it yet.

## Threat Flags

None. The two widgets are Livewire components covered by T-08-49 (Admin-only rule plus boot hook, 403 tests), T-08-50 (Blade `{{ }}` only, no HTML columns) and T-08-51 (same reader and `EntryAmounts::of()`, equality test with `TimeReport`).

## Next Phase Readiness

- Plan 08-20 appends `UpcomingDeadlines` and `TomorrowPlan` to the Admin branch of `Dashboard::getWidgets()` and to the one widget-set expectation in `PanelAccessTest`; the copy is ready.
- Phases 10 and 11 add their tiles at `$sort = 20` through the same list.
- Open: the human check of the dashboard layout, badges and the running-timer contrast is an open entry in `.planning/WINDOWS.md`.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*

## Self-Check: PASSED

- Created files exist: `HoursStats.php`, `UnbilledPerClient.php`, `unbilled-per-client-cell.blade.php`, `unbilled-per-client-footer.blade.php`, `AdminDashboardTest.php` (FOUND on disk)
- Commits `261b6a0`, `1c66344` and `1ff5512` are ancestors of HEAD
- Task acceptance criteria re-run: all greps succeed; `AdminDashboardTest` exits 0 (23 tests)
- Plan verification: full Pest suite 3672 passed, 3 skipped (pre-existing; run before the last test commit, which adds one test, re-run green with the dashboard tests); Pint and Larastan clean; `scripts/check-sensitive.sh` clean on both commits
