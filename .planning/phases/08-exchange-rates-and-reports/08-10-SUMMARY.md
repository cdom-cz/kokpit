---
phase: 08-exchange-rates-and-reports
plan: 10
subsystem: operations
tags: [health-indicator, system-page, cnb-rates, czech-holidays, working-days, europe-prague]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-08 CzechPublicHolidays (isWorkingDay, previousWorkingDay) and the rates config block with the health thresholds"
  - phase: 08-exchange-rates-and-reports
    provides: "08-04 ExchangeRate model and the kokpit.system.last_rate_date.* plural copy"
  - phase: 03-operations
    provides: "HealthIndicator, HealthIndicatorRegistry and the System page with the reserved LastRateDate slot"
provides:
  - "LastRateDateIndicator: the System page row 'Datum posledního kurzu' shows the newest stored rate_date and an Ok, Warning or Error badge measured in Czech working days"
  - "CzechPublicHolidays::expectedListDate, workingDaysBetween and workingDays (calendar helpers for the health slot, plan 08-13 and the gap self-heal of plan 08-17)"
  - "The System page renders a dash as the value of any row whose indicator has no value"
affects: [08-13 rates overview Stav stat, 08-17 gap self-heal, 08-18]

actuals:
  tokens: 6400
  tasks: 2
  commits: 3

plan_head_before: 5e9d6aed527b5da3333c2d4b717fc68b87673f8a
plan_head_after: 4afe5bbadfe34b155da78d22f4b774100d7da53b
commits: 3

tech-stack:
  added: []
  patterns:
    - "A health lag is counted in Czech working days against the list the calendar expects (today only from the final attempt hour on a working day), never in calendar days"
    - "A health indicator reads its table inside PartnerContext::runAsSystem so the registry works outside a request"

key-files:
  created:
    - app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php
    - tests/Feature/Operations/LastRateDateIndicatorTest.php
  modified:
    - app/Domain/ExchangeRates/CzechPublicHolidays.php
    - app/Providers/OperationsServiceProvider.php
    - app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php
    - resources/views/filament/pages/system-page.blade.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/HealthRegistryTest.php
    - tests/Feature/Operations/SystemPageTest.php
    - tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php

key-decisions:
  - "Expected list date = today (Prague) on a working day from kokpit.rates.final_hour on, otherwise the previous working day; lag = working days after the newest stored rate_date up to and including it"
  - "The error threshold is max(warning, error) so a misconfiguration can never make Error milder than Warning"
  - "Never downloaded is Error with the never-downloaded copy, not NotAvailable"
  - "The System page shows a dash (kokpit.system.empty_value) for every row without a value, not only the rate row"

patterns-established:
  - "Phase 3 placeholder tests are changed on purpose when a later phase makes a slot real"

requirements-completed: [EX-02]

coverage:
  - id: D1
    description: "The System page row 'Datum posledního kurzu' shows the newest stored rate date as j. n. Y with an Ok badge and 'Kurzy jsou aktuální.' when the expected list is stored"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SystemPageTest.php#shows the Admin the date of the newest rate list with an Ok badge in the last rate date row"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#answers Ok with the date of today list on a working evening once today list is stored"
        status: pass
    human_judgment: false
  - id: D2
    description: "The slot is Warning after one missing Czech working day and Error after two; thresholds come from config('kokpit.rates.health') and the error threshold never sits below the warning one"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#answers Warning with one missing working day after the final hour"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#answers Error with two missing working days"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#takes the thresholds from the configuration and never lets the error threshold sit below the warning one"
        status: pass
    human_judgment: false
  - id: D3
    description: "A normal weekend, the Easter block and the hours before the final attempt raise no false alarm; a missing Friday on Monday morning is a Warning"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#counts a normal weekend as no gap on Monday morning, and a missing Friday as a Warning"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#raises no false alarm on the Tuesday after Easter with the list of the Thursday before"
        status: pass
    human_judgment: false
  - id: D4
    description: "Never downloaded is Error with the Czech never-downloaded text and a dash as the value on the System page"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Feature/Operations/LastRateDateIndicatorTest.php#answers Error with the never-downloaded text and no value when no list was stored"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SystemPageTest.php#shows a dash as the value of the last rate date row and the never-downloaded text when no list was stored"
        status: pass
    human_judgment: false
  - id: D5
    description: "The calendar helpers count and list working days and compute the expected list date in Prague time"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php#counts the working days after one day up to and including another"
        status: pass
      - kind: unit
        ref: "tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php#expects today list from the final hour on a working day and the previous working day otherwise"
        status: pass
    human_judgment: false

duration: 30min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 10: Last Rate Date Health Slot Summary

**The System page slot "Datum posledního kurzu" is a real indicator: the newest stored CNB list measured against the list the Czech working-day calendar expects, Warning after one missing working day, Error after two or when never downloaded**

## Performance

- **Duration:** about 30 min
- **Completed:** 2026-10-14T20:57:05Z
- **Tasks:** 2
- **Files modified:** 10 (2 created, 8 modified)

## Accomplishments
- `LastRateDateIndicator` reads `MAX(rate_date)` in the system context, formats it `j. n. Y` and answers Ok, Warning or Error with Czech plural details from `kokpit.system.last_rate_date.*`; no exception text is ever in the detail.
- `CzechPublicHolidays` gained `expectedListDate` (Prague clock, final hour), `workingDaysBetween` and `workingDays`; a Monday morning after a normal weekend is Ok, a Monday without Friday's list is Warning, the Tuesday after Easter with Thursday's list is Ok.
- The indicator is registered in `OperationsServiceProvider`, so `missingSlots()` no longer covers the slot; the Phase 3 tests that pinned the slot as not yet available were changed on purpose.

## Task Commits

1. **Task 1: Tracer - the System page shows the date of the newest CNB list as a real Ok slot** - `5fc2c81` (feat)
2. **Task 2: Warning and Error measured in Czech working days, never downloaded is Error** - `4afe5bb` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

_Note: the measured `commits: 3` includes `23c154b`, an unrelated todo-capture commit that landed on the branch between the two task commits; it is not part of this plan._

## Files Created/Modified
- `app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php` - the real last-rate-date indicator
- `app/Domain/ExchangeRates/CzechPublicHolidays.php` - `expectedListDate`, `workingDaysBetween`, `workingDays`
- `app/Providers/OperationsServiceProvider.php` - registers the indicator in the real list
- `app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php` - docblock no longer names the rate slot
- `resources/views/filament/pages/system-page.blade.php` - dash for an empty value
- `lang/cs/kokpit.php` - `kokpit.system.empty_value`
- `tests/Feature/Operations/LastRateDateIndicatorTest.php`, `HealthRegistryTest.php`, `SystemPageTest.php`, `tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php` - tests

## Decisions Made
- The error threshold is `max(warning, error)`, so a misconfiguration never makes Error milder than Warning.
- Never downloaded is Error (research Pitfall 15), not NotAvailable.
- The dash for an empty value is rendered for every System page row (not only the rate row), keeping the page consistent; placeholder rows now show a dash before their "Zatím není k dispozici" text.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] System page rendered nothing for a null value**
- **Found during:** Task 2 (never-downloaded behavior: "the System page row then shows - as the value")
- **Issue:** the Blade view printed the value span only when filled, so the never-downloaded row showed no value, against UI-SPEC Surface G.
- **Fix:** the view prints `kokpit.system.empty_value` (a dash) when the value is empty; new copy key added.
- **Files modified:** `resources/views/filament/pages/system-page.blade.php`, `lang/cs/kokpit.php`
- **Verification:** `SystemPageTest` dash test; the full Operations folder and the full suite pass.
- **Committed in:** `4afe5bb`

**2. [Process note - TDD ordering] Task 2 tests passed on first run**
- The plan puts the three calendar helpers and the full threshold logic in Task 1's implementation, so the Task 2 behavior tests were green when first written (no unexpected-green defect: the behavior was built in Task 1 by plan). Task 1's RED run (5 failed, 18 passed) was observed before its implementation.

---

**Total deviations:** 1 auto-fixed (1 missing critical), 1 process note
**Impact on plan:** the dash is required by the plan's own behavior list and the UI-SPEC; no scope creep.

## Issues Encountered
None.

## Known Stubs
None.

## Threat Flags
None - the plan's T-08-36 (silent stale rate) and T-08-37 (exception text in detail) are mitigated and tested.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Plan 08-13 can show the same result as the "Stav" stat by calling the indicator; plan 08-17 can use `workingDays()` for the gap self-heal.

## Self-Check: PASSED

- Files exist: `LastRateDateIndicator.php`, `LastRateDateIndicatorTest.php`, this SUMMARY.
- Commits `5fc2c81` and `4afe5bb` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run and pass; full suite 3570 passed (3 skipped), Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
