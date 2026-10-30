---
phase: 08-exchange-rates-and-reports
plan: 24
subsystem: dashboard
tags: [dashboard, widget, multi-currency, unbilled, gap-closure, cr-01]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-15 UnbilledPerClient widget; 08-23 per-currency report rows (TimeReport summary amounts)"
provides:
  - "UnbilledPerClient rows carry an amounts map keyed by ISO currency; Money::plus() runs only within one currency"
  - "The Částka cell lists one line per currency (kind 'amount' of unbilled-per-client-cell), the dash when empty"
  - "Regression tests: GET /admin answers 200 for a CZK client with a legacy EUR task rate; widget equals the report per currency; missing rate is flagged, never zero"
affects: [08-25 client currency lock, 08-26 report column redesign]

actuals:
  tokens: 2500
  tasks: 2
  commits: 2

plan_head_before: 056ad13880aa21ef3b40c488c0fe21299c8cc57e
plan_head_after: 192cd4be2acc2843529fb2f8d0d9521d020c27f9
commits: 2

tech-stack:
  added: []
  patterns:
    - "A widget row keeps amounts as array<string, Money> keyed by currency (ksorted); only the CZK figure crosses currencies"

key-files:
  created: []
  modified:
    - app/Filament/Widgets/UnbilledPerClient.php
    - resources/views/filament/widgets/unbilled-per-client-cell.blade.php
    - tests/Feature/Dashboard/AdminDashboardTest.php

key-decisions:
  - "The single nullable row amount became an amounts map by currency, the same shape as 08-23 gave the report rows, so widget and report agree key by key"
  - "The Částka column became a ViewColumn over the existing cell view (kind 'amount') instead of a TextColumn, so each currency gets its own line"

requirements-completed: [RE-04]

coverage:
  - id: D1
    description: "The Admin dashboard opens for a client whose unbilled time is priced in two currencies and lists one Částka line per currency, CZK total 4 500,00 Kč in the row and the footer"
    requirement: "RE-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#opens the dashboard of a client whose unbilled time is priced in two currencies"
        status: pass
    human_judgment: false
  - id: D2
    description: "Widget rows equal TimeReport summary amounts and CZK per currency for the same client and month"
    requirement: "RE-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#equals the report per currency for a client with money in two currencies"
        status: pass
    human_judgment: false
  - id: D3
    description: "Without a CNB rate the CZK cell keeps the CZK part with the Chybí kurz marker and never shows 0,00 Kč; order by hours holds beside a single-currency client"
    requirement: "RE-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#flags the part of a two-currency client without a CNB rate and never shows it as zero"
        status: pass
      - kind: integration
        ref: "tests/Feature/Dashboard/AdminDashboardTest.php#keeps the order by hours with a two-currency client beside a single-currency one"
        status: pass
    human_judgment: false

duration: 15min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 24: Mixed-currency dashboard widget Summary

**The "Nevyfakturováno tento měsíc" widget keeps client-currency amounts per ISO currency (an `amounts` map per row, a one-line-per-currency Částka cell), so a CZK client with a legacy EUR task rate no longer takes the Admin landing page down with a 500.**

## Performance

- **Duration:** about 15 min
- **Tasks:** 2 of 2
- **Files modified:** 3

## Accomplishments

- Closed the dashboard half of CR-01 (08-VERIFICATION.md gap 1, SC5): `GET /admin` answers 200 for the Admin when one client's unbilled entries are priced in CZK and EUR.
- The mixed client row reads "Example Client Mixed 2:30", then 1 500,00 Kč and 120,00 € on separate lines, then 4 500,00 Kč; the footer "Celkem" shows 2:30 and 4 500,00 Kč with the client-currency cell blank.
- The row amounts equal `summary['amounts']['unbilled']` of `TimeReport::build()` key by key and the row `czk` equals `summary['czk']['unbilled']`.
- Without a stored EUR rate the row shows 1 500,00 Kč with the Chybí kurz marker and the footer note; no zero amount appears.
- Single-currency rows, the order by hours, the ten-row limit, the "Pevná cena" badge and the Partner refusal are unchanged (the existing 48 dashboard cases, PanelAccessTest and TimeLeakTest stay green).

## Task Commits

1. **Task 1 (tracer): per-currency widget rows and cell, dashboard regression** - `6341bc8` (fix)
2. **Task 2: widget equals the report per currency, missing-rate flag, order beside a single-currency client** - `192cd4b` (test)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php tests/Isolation/TimeLeakTest.php`: 118 passed
- `ddev exec vendor/bin/pest` (full suite): 3879 passed, 3 skipped (pre-existing skips)
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors
- `scripts/check-sensitive.sh`: clean on both commits; lefthook (sensitive-content, gitleaks) passed
- Task 1 acceptance greps hold: "Example Client Mixed" present in the test, `'kind' => 'amount'` present in the widget, no `$record['amount']` or `$client['amount']` left.
- RED was observed first: the new dashboard case failed with the 500 "Money in different currencies cannot be combined." from `UnbilledPerClient.php:201` before the fix.
- Tracer gate (auto and end-of-phase mode): the tracer verify was re-run end to end before expansion and passed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] CreateTask refused the project in the test helper**
- **Found during:** Task 1 (RED run)
- **Issue:** `CreateTask` looks the project up through the scoped query, which needs a signed-in user; the dashboard test's `beforeEach` does not sign in (the report test's does), so the helper threw "Vyberte dostupný projekt."
- **Fix:** `adminDashMixedClient()` calls `test()->actingAs(test()->admin)` before `CreateTask`
- **Files modified:** tests/Feature/Dashboard/AdminDashboardTest.php
- **Commit:** 6341bc8

### Sequencing note

The Task 2 cases passed on first run against the Task 1 production code, because the per-currency map and the cell already satisfied them; Task 2's commit therefore holds tests only and no production change. No behaviour differs from the plan.

**Total deviations:** 1 auto-fixed (blocking test helper), 1 sequencing note. **Impact:** none on scope or production behaviour.

## Known Stubs

None.

## Threat Flags

None. The widget keeps `#[AccessRule(Audience::AdminOnly)]` with `EnforcesWidgetAccessRule`; no new public property, endpoint or trust-boundary surface; amounts reach the view only through `{{ }}`. T-08-75 (DoS by `plus()` across currencies) and T-08-76 (Partner access) are mitigated and covered by the regression test, PanelAccessTest and TimeLeakTest.

## Issues Encountered

None. Out of scope and untouched: the currency lock (plan 08-25).

## Next Phase Readiness

Ready for 08-25 and 08-26.

## Self-Check: PASSED

- Files exist: UnbilledPerClient.php, unbilled-per-client-cell.blade.php, AdminDashboardTest.php
- Commits `6341bc8` and `192cd4b` are ancestors of HEAD
