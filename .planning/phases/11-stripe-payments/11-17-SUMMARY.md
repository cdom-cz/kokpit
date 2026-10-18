---
phase: 11-stripe-payments
plan: 17
subsystem: payments
tags: [dashboard, filament, widget, admin-only, collapsible, czech-plurals, check-constraint]

requires:
  - phase: 11-stripe-payments
    provides: "UnresolvedPayments::counts() and CardPaymentResource::getUrl('index') (11-11), the card-payment-resolved event (11-11, 11-19), CardPaymentFixtures"
provides:
  - "App\\Filament\\Widgets\\UnresolvedPayments: the Admin dashboard card 'Nevyřešené platby' (sort 21, full width, polls every 60 s), visible only while at least one duplicate, unmatched payment or refund event is unresolved"
  - "DashboardWidget::UnresolvedPayments = 'unresolved_payments' and the migration widening dashboard_widget_preferences_widget_key_check to eight keys (rollback deletes the new rows first)"
  - "Dashboard::getWidgets() lists the card right after UnpaidInvoices in the Admin list"
  - "kokpit.dashboard.unresolved_payments.{heading, stat, summary, parts.duplicate|unmatched|refund} with Czech plural forms"
affects: [11-18, 11-20, dashboard, payments-overview]

actuals:
  tokens: 10500
  tasks: 2
  commits: 2
plan_head_before: 2744fa8761a51f043bec1dccd6e3ff1ace7780d6
plan_head_after: 250442c6b21479e25873a7fc8448824446eb5b7f
commits: 2

tech-stack:
  added: []
  patterns:
    - "A widget that exists only above a threshold narrows both boot checks (the EnforcesWidgetAccessRule trait and Filament's bootCanAuthorizeAccess) to the AccessRule declaration, while canView() adds the threshold for the dashboard list; a poll after the threshold is crossed back renders an empty root instead of a 403"
    - "A Livewire view whose content is conditional keeps one real root element outside the @if, because Livewire's block markers hide a root that starts inside it"

key-files:
  created:
    - app/Filament/Widgets/UnresolvedPayments.php
    - resources/views/filament/widgets/unresolved-payments.blade.php
    - database/migrations/2026_10_18_000600_add_unresolved_payments_dashboard_widget.php
    - tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php
  modified:
    - app/Domain/Dashboard/Enums/DashboardWidget.php
    - app/Filament/Pages/Dashboard.php
    - lang/cs/kokpit.php
    - tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "R11-17-1 to R11-17-3 applied as written. The part strings use the genitive plural from five ('duplicitních 6, nespárovaných 5'), the nominative plural for two to four, and the singular 'nespárovaná 1' for one; 'duplicitní' and 'vrácení' do not change for one to four"
  - "The card's visibility lives in canView() (AccessRule first, then counts()['total'] > 0) so the dashboard list leaves it out at zero; the widget view additionally renders an empty polling root at zero, so a directly mounted widget or a poll after the last resolution shows nothing and never fails"
  - "A collapsed card hides the stat client-side exactly like the other dashboard cards (the section body stays in the markup and is hidden by Filament); the header carries 'Nevyřešené platby: :count'"

patterns-established:
  - "Plan 11-18 or later widgets that appear only above zero can follow the narrowed-boot-check pattern of UnresolvedPayments"

requirements-completed: []

duration: 25min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 17: Dashboard card "Nevyřešené platby" Summary

**An unresolved duplicate, unmatched payment or refund event now shows up on the Admin dashboard directly under "Nezaplacené faktury" as a collapsible card with the count in danger, the non-zero parts in Czech plural forms, and a link to the "Nevyřešené" tab; with nothing unresolved the card does not exist.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 11 (4 created, 7 modified)

## Accomplishments

- `UnresolvedPayments` widget (Admin only through `#[AccessRule(Audience::AdminOnly)]`, `CollapsibleDashboardWidget`, `EnforcesWidgetAccessRule`, `$sort = 21`, full width, `visible.60s` poll, `#[On('card-payment-resolved')]` refresh) with one stat: the count from `UnresolvedPayments::counts()`, colour danger, icon `OutlinedExclamationTriangle`, description of the non-zero parts, link to the card payments list (default tab Nevyřešené).
- `DashboardWidget::UnresolvedPayments` (not a Partner widget) and the migration `2026_10_18_000600_add_unresolved_payments_dashboard_widget.php`: the CHECK is dropped and re-added with the eight keys in one `up()`; `down()` deletes the new rows first and restores the seven.
- `Dashboard::getWidgets()` lists the card right after `UnpaidInvoices::class` in the Admin list only.
- Czech copy under `kokpit.dashboard.unresolved_payments` with plural forms per part; collapsed header "Nevyřešené platby: :count".
- Tests: dashboard absent at zero and with a matched record only, appears for a duplicate under the receivables card, plural forms (1/3/5, 6/5, singular, resolved not counted), stored collapse state and header, removal after the last resolution, Partner refusal (forced mount 403, absent from the dashboard); schema test for the eight-key CHECK and its rollback.

## Task Commits

1. **Task 1 (tracer): the card appears on the Admin dashboard** - `a6ba944` (feat)
2. **Task 2 (TDD): plurals, collapsed header, collapse state, Partner refusal** - `250442c` (feat)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing widget-list assertions listed the old Admin widget set**
- **Found during:** Task 1 (Dashboard and Isolation suites)
- **Issue:** `AdminDashboardTest`, `UnpaidInvoicesWidgetTest` and `PanelAccessTest` assert the exact Admin widget list and the discovered panel widgets, which now include `UnresolvedPayments`.
- **Fix:** Added `UnresolvedPayments::class` to the expected lists (after `UnpaidInvoices` in the dashboard order).
- **Files modified:** tests/Feature/Dashboard/AdminDashboardTest.php, tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php, tests/Isolation/PanelAccessTest.php
- **Commits:** a6ba944, 250442c

**2. [Rule 1 - Bug] Filament's own boot check and the trait's boot hook would turn a poll into a 403 once the last record is resolved**
- **Found during:** Task 2 (resolution test)
- **Issue:** `canView()` carries the "above zero" condition, and both `bootEnforcesWidgetAccessRule` and Filament's `bootCanAuthorizeAccess` call it on every Livewire request, so the 60-second poll or the `card-payment-resolved` refresh after the last resolution returned 403.
- **Fix:** The widget overrides both boot methods to check only the AccessRule declaration (a Partner is still refused before any code runs); the view renders an empty polling root at zero. The first render of the view needed a real root element outside the `@if`, because Livewire's block markers otherwise hide the root (`RootTagMissingFromViewException`).
- **Files modified:** app/Filament/Widgets/UnresolvedPayments.php, resources/views/filament/widgets/unresolved-payments.blade.php
- **Commit:** 250442c

**3. Interpretation of "the stat is not rendered" when collapsed**
- The plan's behavior list says the stat is not rendered when collapsed. The shared `dashboard-card` component keeps the body in the markup and hides it with Filament's collapsible section (the same as all other dashboard cards), so the test asserts `aria-expanded="false"` and the header text rather than the absence of the stat markup. No code change.

**Total deviations:** 2 auto-fixed, 1 interpretation. **Impact:** none on scope.

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None.

## Threat Flags

None. T-11-59 (Partner seeing the card) is mitigated by the AdminOnly AccessRule, the boot check on the declaration, `canView()` asking the rule first and the Admin-only widget list, with the Partner test; T-11-60 (unknown preference key) by the widened CHECK with the exact key list and the schema test.

## Requirements

PA-08 is left open: this plan delivers its dashboard tile, but the requirement also covers the pruning of old webhook events, which belongs to a later plan of the phase.

## Verification

- `ddev exec vendor/bin/pest tests/Isolation tests/Feature/Dashboard tests/Feature/Schema tests/Arch`: 1008 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.

## Self-Check: PASSED

- FOUND: app/Filament/Widgets/UnresolvedPayments.php
- FOUND: resources/views/filament/widgets/unresolved-payments.blade.php
- FOUND: database/migrations/2026_10_18_000600_add_unresolved_payments_dashboard_widget.php
- FOUND: tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php
- FOUND commits: a6ba944, 250442c
