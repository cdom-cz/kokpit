---
phase: 10-invoicing
plan: 28
subsystem: dashboard-receivables
tags: [invoicing, filament, dashboard, stats-widget, receivables, collapsible-card, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "ReceivablesReader summary/tabCounts (plans 10-19, 10-21), lang/cs/invoices.php dashboard copy (plan 10-21), InvoiceResource list with tab query (plan 10-21)"
provides:
  - "UnpaidInvoices: the Admin dashboard card Nezaplacené faktury (sort 20, full width, polling 60 s, refresh on invoice-payment-saved)"
  - "DashboardWidget::UnpaidInvoices ('unpaid_invoices') and the migration widening dashboard_widget_preferences_widget_key_check"
  - "Dashboard::getWidgets() Admin list [HoursStats, TodayPlan, UnpaidInvoices, UnbilledPerClient, UpcomingDeadlines]"
affects: [10-36, 10-38, 10-39, 10-40, 11]

actuals:
  tokens: 7000
  tasks: 2
  commits: 3
plan_head_before: b020867f10febdbc81ebfaee5987195da76dbad3
plan_head_after: d8ff957bd337ed8bed1e92c1125e3c1c05b1fe8c
commits: 3

tech-stack:
  added: []
  patterns:
    - "A collapsible stats card reuses the shared view filament.widgets.hours-stats (heading, summary and body come from the CollapsibleDashboardWidget methods)"
    - "A widget that must stay quiet before data exists overrides canView() as the declaration AND the existence check, declaration first, so a Partner never reaches the query"

key-files:
  created:
    - app/Filament/Widgets/UnpaidInvoices.php
    - database/migrations/2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php
    - tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php
  modified:
    - app/Domain/Dashboard/Enums/DashboardWidget.php
    - app/Filament/Pages/Dashboard.php
    - lang/cs/invoices.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "R-28-1: the card is collapsible with its own DashboardWidget case unpaid_invoices and a migration that drops and re-adds the widget key CHECK; down() deletes the new card's rows and restores the six old keys"
  - "R-28-2: the collapsed header shows the overdue count ('Po splatnosti: :count', new lang key invoices.dashboard.summary)"
  - "Every figure comes from one ReceivablesReader::summary(today) per render; each stat links to its list tab via InvoiceResource::getUrl('index', ['tab' => ...])"
  - "The card is absent (canView false) until an issued invoice or proforma exists; a credit note or a draft does not count"

requirements-completed: [IN-05]

coverage:
  - id: D1
    description: "After the first issued invoice the Admin dashboard shows Nezaplacené faktury with Po splatnosti (count, 'v Kč: sum', danger), K úhradě, and Zálohy k vyfakturování only above zero; nothing is rendered before the first issued document"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it renders nothing before the first issued invoice or proforma"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it shows the overdue count with its CZK sum in danger and the amount still due"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it lists a paid proforma without its invoice as an advance waiting for the invoice"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it shows success text at zero overdue and no advance row at zero"
        status: pass
    human_judgment: false
  - id: D2
    description: "The card sits at sort 20 between the plan and the unbilled time, full width, in the Admin list only; a Partner is refused at boot and never gets its markup"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it is the second card of the Admin dashboard, between the plan and the unbilled time"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#it is refused to a Partner, also when mounted by force, and absent from the Partner dashboard"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#it replaces the stock dashboard and its widgets"
        status: pass
    human_judgment: false
  - id: D3
    description: "The card collapses under its own preference key, keeps the overdue count in the collapsed header, links each stat to its list tab, agrees with the tab counts of the list and refreshes after a payment"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php#the card as one of the collapsible dashboard cards"
        status: pass
    human_judgment: false
  - id: D4
    description: "The preference CHECK accepts unpaid_invoices, still refuses an unknown key, and the migration's down() restores the old list"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php#it accepts the receivables card key and gives it back to the old list on rollback"
        status: pass
    human_judgment: false
  - id: D5
    description: "Visual reading of the card (three stats side by side, danger colour and warning icon of the overdue stat, collapsed header on narrow screens)"
    verification: []
    human_judgment: true
    rationale: "Layout and colour of the Filament stats card cannot be asserted by markup tests"

duration: 25min
completed: 2026-10-17
---

# Phase 10 Plan 28: Receivables dashboard card Summary

**The Admin dashboard gets a collapsible Nezaplacené faktury card at sort 20 (overdue in danger with its CZK sum, still due, paid advances waiting for an invoice), fed only by ReceivablesReader::summary() and hidden until the first issued document exists.**

## Performance

- **Duration:** about 25 min (including an 8 min full suite run)
- **Completed:** 2026-10-17
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files modified:** 9 (3 created, 6 modified)

## Accomplishments

- `UnpaidInvoices` (Admin only, full width, polling 60 s, refresh on `invoice-payment-saved`) shows Po splatnosti as a count with "v Kč: sum", the warning icon and danger colour above zero and "Nic po splatnosti" in success at zero; K úhradě with count and CZK sum of the unpaid, not yet late documents; Zálohy k vyfakturování with "Platba čeká na fakturu" in danger, only above zero. Each stat links to its invoice list tab (overdue, unpaid, awaiting_invoice).
- `canView()` is the access declaration AND an issued invoice or proforma existing, so before the first one the card renders nothing; the declaration is asked first, so a Partner never reaches the query and the boot hook refuses a forced Livewire mount.
- The card is collapsible like the other Admin cards: new `DashboardWidget::UnpaidInvoices` case, a migration widening the `dashboard_widget_preferences_widget_key_check` CHECK, and the collapsed header "Po splatnosti: N".
- The stat counts are proved equal to `ReceivablesReader::tabCounts()` for the same tabs.

## Task Commits

1. **Task 1: Tracer - the Admin dashboard shows Nezaplacené faktury** - `fdc235d` (feat)
2. **Task 2: Collapse, preference key, tab links, agreement with the overview** - `25b09e0` (test)
3. **Registry test of the discovered widgets** - `d8ff957` (test, see deviation 1)

**Plan metadata:** the docs commit that follows this summary.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite): 5655 passed, 3 skipped, 1 failed (`tests/Isolation/PanelAccessTest.php`, fixed in `d8ff957`; `tests/Isolation` re-run afterwards: 310 passed). The Dashboard and Schema suites (119 tests) and `tests/Arch/PanelRegistryTest.php` were re-run green after the last change.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.
- All acceptance criteria of both tasks re-run: PASS.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Two existing tests asserted the old widget lists**
- **Found during:** Task 1 (AdminDashboardTest) and the final full suite (PanelAccessTest)
- **Issue:** `AdminDashboardTest` asserted the Admin list without the new card, and `PanelAccessTest` asserted the exact set of widgets the panel discovers under `app/Filament/Widgets`; both are expected to change with a new Admin widget but were not listed in `files_modified`.
- **Fix:** added `UnpaidInvoices::class` to both expectations.
- **Files modified:** tests/Feature/Dashboard/AdminDashboardTest.php (in `fdc235d`), tests/Isolation/PanelAccessTest.php (`d8ff957`)
- **Verification:** Dashboard and Isolation suites green

**2. [Rule 3 - Blocking] A lang key was missing for the collapsed header**
- **Found during:** Task 1
- **Issue:** plan 10-21 supplied the card copy but no key for the R-28-2 collapsed summary.
- **Fix:** added `invoices.dashboard.summary` ("Po splatnosti: :count") to lang/cs/invoices.php.
- **Commit:** `fdc235d`

**Total deviations:** 2 auto-fixed (1 bug in test expectations, 1 blocking, both additive). **Impact:** none on existing behaviour.

## Notes

- The card reuses the existing view `filament.widgets.hours-stats` (a generic collapsible stats shell despite its name) instead of adding a duplicate view.
- The tracer test and the implementation were committed together in one commit per task, not as separate test and feat commits.
- The Task 1 and Task 2 migration file is the same one; it was created with the tracer because the card cannot store its collapsed state without the widened CHECK.

## Known Stubs

None.

## Threat Flags

None. The only new surface is the widget, which carries `#[AccessRule(Audience::AdminOnly)]` with the enforcing trait (T-10-72); the reader refuses non-Admins and the Partner tests prove the refusal.

## Self-Check: PASSED

- Files exist: UnpaidInvoices.php, 2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php, UnpaidInvoicesWidgetTest.php
- Commits present on HEAD: fdc235d, 25b09e0, d8ff957
- Acceptance criteria of both tasks re-run: PASS

Ready for the next plan of the phase.
