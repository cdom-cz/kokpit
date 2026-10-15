---
phase: 08-exchange-rates-and-reports
plan: 13
subsystem: exchange-rates
tags: [filament, resource, stats-widget, cnb, exchange-rates, icu, health-slot]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-01 ExchangeRate model and copy kokpit.rates.*, 08-04 DownloadCnbRates::handle and CnbDownloadFailed, 08-08 final-run alert with the route-name action URL, 08-10 LastRateDateIndicator on HealthSlot::LastRateDate"
provides:
  - "ExchangeRateResource: Admin-only list page 'Kurzy ČNB' (Správa, sort 84), one row per currency and day, Datum filter defaulting to the newest stored day, Měna filter over stored codes, dynamic empty states"
  - "ListExchangeRates header action downloadToday: synchronous DownloadCnbRates for the Prague day with four toasts (stored, already stored, not yet published, failure)"
  - "ExchangeRateStats header widget: Poslední kurzy, Stav (last_rate_date health with colour and warning triangle), Měn v seznamu, polling every 60 s"
  - "RateFormat::display (3 to 10 decimals, trailing zeros beyond the third trimmed, no rounding) and RateFormat::currencyName (Czech ICU name)"
affects: [08-18 Doplnit historii (adds a second header action to ListExchangeRates), 08-17 gap self-heal, phase verification of Surface A]

plan_head_before: cb424760dd3c7381b80f745fcebb19aba324e806
plan_head_after: 01e25ac04f0ee61bcf8bacae86ab230d7421fcc0

estimate:
  tokens: 48000
  raw_tokens: 48000
  tasks: 3
  confidence: low

actuals:
  tokens: 11700
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "A resource list-page widget is registered in the resource's getWidgets() and the page's getHeaderWidgets(), never discovered at panel level"
    - "A dynamic table empty state reads the filter state through the $livewire closure parameter and returns null to fall back to Filament's stock text"
    - "A manual operator action maps the domain result to toasts and swallows the failure class: only fixed copy reaches the UI"

key-files:
  created:
    - app/Filament/Resources/ExchangeRateResource.php
    - app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php
    - app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php
    - app/Domain/ExchangeRates/RateFormat.php
    - tests/Feature/ExchangeRates/ExchangeRateResourceTest.php
    - tests/Unit/ExchangeRates/RateFormatTest.php
  modified:
    - tests/Feature/ExchangeRates/RateAlertTest.php
    - app/Console/Commands/DownloadCnbRatesCommand.php

key-decisions:
  - "The Czech currency name is a gray description under the semibold ISO code (two lines in the cell), not an inline span after it: Filament-native, escaped by the framework, and the UI-SPEC spacing token already names 'the two lines of a table cell'"
  - "'Staženo :datetime' of the first stat reads the newest fetched_at within the newest list, not the overall newest fetched_at, so a later backfill of an old day cannot make the current list look freshly fetched"
  - "A run that inserted nothing for today (a concurrent run stored the list during the request) answers 'already stored', like the console command, instead of 'stored, 0 měn'"
  - "A Datum before every stored list shows the 'Pro tento den ČNB kurzy nevyhlásila' heading without the 'Platí poslední vyhlášené kurzy' body, because no earlier list applies"

patterns-established:
  - "Manual download never gated by the working-day calendar and never alerting: the page is the feedback channel"

requirements-completed: [EX-01, EX-02]

coverage:
  - id: D1
    description: "The Admin opens 'Kurzy ČNB' and sees one row per currency and day, newest first, with the rate trimmed to 3 to 10 decimals, the Czech currency name, the Jednotka and a hidden Staženo column; 30/60/120 rows per page"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Unit/ExchangeRates/RateFormatTest.php#display"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the currency cell"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the tracer: open the page, fetch today's list, read it"
        status: pass
    human_judgment: false
  - id: D2
    description: "The Datum filter opens on the newest stored day, clearing it shows every day, the Měna filter narrows to one stored currency, and a day without a list or no stored rates shows the right empty state"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the filters"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the empty states"
        status: pass
    human_judgment: false
  - id: D3
    description: "Three header stats (Poslední kurzy, Stav with colour and warning triangle, Měn v seznamu) refresh every 60 s and are the header widget of the list page"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the header stats"
        status: pass
    human_judgment: false
  - id: D4
    description: "'Stáhnout dnešní kurzy' runs the scheduler's action synchronously and answers with the four toasts; a manual failure stores nothing, alerts nobody and shows no status, host or response; a stored day makes no request; a Saturday is not gated"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#every outcome of the download"
        status: pass
    human_judgment: false
  - id: D5
    description: "The final-run CNB alert links to this overview with the label 'Otevřít kurzy ČNB'"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the alert link"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#alerts the Admin by bell and e-mail without touching the queue"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner is refused the page, its Livewire component, the stats widget and a forged download call; there is no create, edit, delete or bulk action, no calculator and no global search"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#who may use it"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#immutable lists and no calculator"
        status: pass
    human_judgment: false
  - id: D7
    description: "While the 15 s download runs, the button shows Filament's stock in-flight state, and the white-on-primary contrast of the button is measured in light and dark mode (UI-SPEC F-1)"
    requirement: EX-01
    verification: []
    human_judgment: true
    rationale: "In-flight rendering and measured colour contrast need a real browser with a slowed CNB answer; no automated test asserts them (plan marks this as backstop / human-check)"

duration: 18min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 13: CNB Rates Overview Summary

**Admin-only "Kurzy ČNB" list with a newest-day Datum filter, Czech currency names from ICU, three polling header stats driven by the last_rate_date health slot, and a synchronous "Stáhnout dnešní kurzy" action answering four fixed-copy toasts.**

## Performance

- **Duration:** about 18 min (from the commit timestamps; the full suite alone takes 4.7 min)
- **Started:** 2026-10-14T21:09:00Z (approximate; not captured at start)
- **Completed:** 2026-10-14T21:27:00Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 8 (6 created, 2 modified)

## Accomplishments

- `ExchangeRateResource` (slug `exchange-rates`, group Správa, sort 84, `OutlinedBanknotes`) with a list page only; columns Datum, Měna (code plus Czech name), Jednotka, Kurz through `RateFormat::display()` right aligned with `tabular-nums`, Staženo hidden by default; default sort date descending then currency; 30 per page with 30, 60 and 120.
- Datum filter defaulting to the newest stored day (an invalid value is ignored), Měna filter over the stored codes, an indicator chip for the active Datum, and a dynamic empty state: no stored rates, or a day without a list naming the list that applies by lookup.
- `ExchangeRateStats` (header widget, `#[AccessRule(AdminOnly)]`, 60 s poll): newest list date with its fetch time, the `last_rate_date` health verdict with its colour and the warning triangle for warning and error, and the currency count of the newest list.
- `downloadToday` runs `DownloadCnbRates` for the Prague day as an explicit system run: stored (success), already stored (info, no HTTP), CNB has no newer list (info, names that day), failure (danger, fixed copy only, nothing stored, no bell, no e-mail).
- The 08-08 alert's action resolves to this page (`ExchangeRateResource::getUrl('index')`, label "Otevřít kurzy ČNB").

## Task Commits

1. **Task 1: Tracer, open the page and download today's list** - `8c825b3` (feat)
2. **Task 2: header stats, currency names, filters, empty states** - `802415b` (test, RED), `0f354b4` (feat, GREEN)
3. **Task 3: every download outcome, the alert link, the Partner refusal** - `31dd769` (test, RED), `01e25ac` (feat, GREEN)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified

- `app/Filament/Resources/ExchangeRateResource.php` - the Admin-only rates list, filters, empty states, widget registration
- `app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php` - header widget and the `downloadToday` action with its outcome mapping
- `app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php` - the three header stats
- `app/Domain/ExchangeRates/RateFormat.php` - `display()` and `currencyName()`
- `tests/Feature/ExchangeRates/ExchangeRateResourceTest.php` - 34 tests: tracer, currency cell, stats, filters, empty states, outcomes, alert link, access, immutability
- `tests/Unit/ExchangeRates/RateFormatTest.php` - decimals table, no-rounding proof, malformed values, ICU names
- `tests/Feature/ExchangeRates/RateAlertTest.php` - obsolete "no action while the route does not exist" test replaced; the helper now returns the real overview URL
- `app/Console/Commands/DownloadCnbRatesCommand.php` - comment of `overviewUrl()` only

## Decisions Made

See `key-decisions` above. Also: a download that cannot be stored for a database reason (`QueryException`) is deliberately not caught, so it surfaces as a real error instead of being hidden behind the "server ČNB neodpovídá" copy, which would be false.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The 08-08 test that pinned "no alert action while the overview route does not exist" broke as soon as the route existed**
- **Found during:** Task 3 (full alert test run after Task 1 added the route)
- **Issue:** `RateAlertTest` registered a fake route under the overview's name and had a test expecting no `actions` in the bell row while the route was absent; with the real resource registered, that test failed and the fake route shadowed the real one.
- **Fix:** removed the obsolete test, made `rateAlertRoute()` return the real `route('filament.admin.resources.exchange-rates.index')` and dropped the fake registration; the positive link assertion lives in `ExchangeRateResourceTest` ("the alert link").
- **Files modified:** `tests/Feature/ExchangeRates/RateAlertTest.php`, `app/Console/Commands/DownloadCnbRatesCommand.php` (comment only)
- **Verification:** `ddev exec vendor/bin/pest tests/Feature/ExchangeRates` 206 passed
- **Committed in:** `31dd769`, `01e25ac`

**2. [Rule 1 - Bug] "valid_for equal to today means stored" would have shown "stored, 0 měn" after a lost race**
- **Found during:** Task 3 (outcome mapping design)
- **Issue:** when a concurrent run stores today's list between the stored-day check and the insert, `valid_for` is today and `inserted` is 0; the plan's mapping would report a success with zero currencies.
- **Fix:** `skipped` or (`valid_for` is today and `inserted` is 0) maps to "already stored", the same rule the console command uses; covered by a test that stores the rows during the fake HTTP answer.
- **Files modified:** `app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php`
- **Verification:** "says the list is already stored when a concurrent run stored it during the request"
- **Committed in:** `01e25ac`

**3. [Rule 1 - Bug] `?[` is not valid PHP while writing `RateFormat::currencyName()`**
- **Found during:** Task 2 (GREEN run)
- **Issue:** a nullsafe array access broke the file's parse, failing every test that loaded the class.
- **Fix:** `self::currencies()?->get($code)`.
- **Files modified:** `app/Domain/ExchangeRates/RateFormat.php`
- **Committed in:** `0f354b4`

---

**Total deviations:** 3 auto-fixed (3 Rule 1)
**Impact on plan:** all necessary for correctness; no scope creep. Two plan-level wording choices (currency name as a description, `Staženo` from the newest list) are recorded as decisions.

## TDD Gate Compliance

Tasks 2 and 3 (`tdd="true"`) have RED (`test(08-13)`) and GREEN (`feat(08-13)`) commits in order. RED evidence: Task 2's RED run failed on the named targets (stats, currencyName, Měna filter and empty-state tests; `ComponentNotFound` / assertion failures), while some Task 2 behaviors (Datum default, `RateFormat::display`) already passed because Task 1 implemented them; Task 3's RED run failed on the five outcome tests (no notification, uncaught `CnbDownloadFailed`). The access and alert-link tests of Task 3 passed in RED because Task 1's resource already enforced them; they remain as regression proof.

## Issues Encountered

- Filament caches the panel navigation per request, so a navigation assertion for the Admin followed by one for a Partner in the same test saw the Admin's items; split into two tests.
- Pre-existing and unrelated: nothing deferred.

## Known Stubs

None. No placeholder text, empty mock data or unwired components in the files of this plan. "Doplnit historii" is intentionally absent and is added by plan 08-18.

## Threat Flags

None. The new surface (Admin page, action, widget) is exactly the plan's threat model rows T-08-43 (Partner refused on URL and both Livewire components, tested), T-08-44 (toast carries fixed copy only, tested with a canary body, status and host) and T-08-45 (a stored day makes no HTTP request, one request per click otherwise, tested).

## User Setup Required

None - no external service configuration required.

## Outstanding Human Check

`<human-check>` of Task 3 (in-flight state of the button with a slowed CNB answer, and the white-on-primary contrast in light and dark mode for the UI-SPEC F-1 gate record) is not executable by the executor; it is left for the end-of-phase verifier (`human_judgment: true`, coverage D7).

## Next Phase Readiness

- Plan 08-18 can add "Doplnit historii" to `ListExchangeRates::getHeaderActions()` (left of `downloadToday`).
- The full suite is green (3650 passed, 3 skipped), Pint and PHPStan clean, `scripts/check-sensitive.sh` clean on every commit.

## Self-Check: PASSED

All six created files exist; commits `8c825b3`, `802415b`, `0f354b4`, `31dd769`, `01e25ac` are ancestors of HEAD; all task acceptance criteria re-run green; `ddev exec vendor/bin/pest` passes.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
