---
phase: 08-exchange-rates-and-reports
plan: 05
subsystem: exchange-rates
tags: [cnb, exchange-rates, conversion, money, exact-rational, rate-lookup, per-unit-quotes]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-01 exchange_rates table and the Admin-only ExchangeRate model (rate_date, currency, unit_amount, rate decimal:10)"
  - phase: 02-foundation-platform
    provides: "Money::fromExactMinor() single rounding point, Money::convert(), Money::forDuration(), MoneyBoundaryTest"
provides:
  - "RateBook: two-query preload of the CNB lists for a currency set and a day range, in-memory lookup of the newest list not after a day"
  - "ExchangeRateConverter: toCzk(), durationToCzk() and rateFor(), the conversion helper later phases reuse"
  - "ExchangeRateMissing: typed refusal carrying the currency and the day, never a zero amount"
  - "Money::forDurationConverted() and Money::hourlyRateFor(): the two exact methods the reports need"
affects: [08-06 reports, 08-08 overview, 08-10 health slot, 09 invoices, 10 payments, report CZK amounts, effective hourly rate]

actuals:
  tokens: 7600
  tasks: 3
  commits: 5

plan_head_before: ca57ff04692ac18880c597318dd30f2bd2ba332c
plan_head_after: 404fc2c5fe4b7901f8e4d44bae046c3f56ba81da
commits: 5

tech-stack:
  added: []
  patterns:
    - "A day without a list is resolved by lookup (newest rate_date <= day per currency), never stored as a copy"
    - "The floor of a preload is the newest list before the range per currency, not a fixed window, so an outage of any length still resolves"
    - "A duration converted to another currency is one exact rational rounded once; the shared rate, unit and currency validation of convert() lives in one private helper"
    - "The converter adds no role check; the fail-closed ExchangeRate model scope turns a Partner context into ExchangeRateMissing"

key-files:
  created:
    - app/Domain/ExchangeRates/RateBook.php
    - app/Domain/ExchangeRates/ExchangeRateConverter.php
    - app/Domain/ExchangeRates/ExchangeRateMissing.php
    - tests/Feature/ExchangeRates/ExchangeRateConverterTest.php
  modified:
    - app/Domain/Shared/Money/Money.php
    - tests/Unit/Money/MoneyTest.php

key-decisions:
  - "Weekends, holidays and outages are resolved by lookup of the newest stored list not after the day, however old; staleness is the health slot's job"
  - "A missing rate throws ExchangeRateMissing (currency and day only); callers show a visible marker, never zero"
  - "The converter has no own role check; the DeniesPartners model scope makes a Partner context find no rate"
  - "Money::forDurationConverted and hourlyRateFor build one exact rational and call fromExactMinor() once; no Brick type or rounding mode leaves Money"

patterns-established:
  - "RateBook::load(currencies, from, to) once per report, then rateFor() per entry in memory"
  - "Query-count tests count only statements touching the table under test, because the Partner scope reads the acting user's roles once on its first query"

requirements-completed: [EX-03, RE-02, RE-05]

coverage:
  - id: D1
    description: "toCzk converts an amount at the newest stored CNB list not after the day: Saturday and Sunday use Friday's list, a list thirty days old is still used, CZK passes through without a lookup"
    requirement: "EX-03"
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#toCzk"
        status: pass
    human_judgment: false
  - id: D2
    description: "A day before the first stored list, or a currency never listed, throws ExchangeRateMissing naming the currency and day instead of returning zero; a Partner context gets the same refusal"
    requirement: "EX-03"
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#Partner context"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#toCzk"
        status: pass
    human_judgment: false
  - id: D3
    description: "Per-unit quotes (HUF and JPY per 100, IDR per 1000) are divided by their unit amount, JPY without fraction digits converts exactly"
    requirement: "EX-03"
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#per-unit quotes"
        status: pass
    human_judgment: false
  - id: D4
    description: "Money::forDurationConverted rounds a duration at an hourly rate converted at a CNB rate once from one exact rational (42.57 CZK, where forDuration then convert gives 42.46); durationToCzk uses it with the stored unit amount"
    requirement: "RE-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#rounds a converted duration once, not once for the client currency and again for CZK (Pitfall 6)"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#durationToCzk"
        status: pass
    human_judgment: false
  - id: D5
    description: "Money::hourlyRateFor states price x 3600 / seconds rounded once and refuses zero or negative seconds"
    requirement: "RE-05"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#states the effective hourly rate of a fixed price, rounded once"
        status: pass
    human_judgment: false
  - id: D6
    description: "RateBook answers a 31-day range with two rate queries and answers a Monday after a ten-day list gap with the last list before the gap; no list after the asked day is used"
    requirement: "EX-03"
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateConverterTest.php#RateBook"
        status: pass
    human_judgment: false
  - id: D7
    description: "No Brick type or rounding mode appears outside the Money class"
    verification:
      - kind: unit
        ref: "tests/Arch/MoneyBoundaryTest.php"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 05: Exchange Rate Conversion Helper Summary

**CNB conversion service (`RateBook` + `ExchangeRateConverter`) that answers any currency, amount and day with the newest stored list not after that day, divides per-100 and per-1000 quotes by their unit, and rounds a converted duration once through two new exact `Money` methods**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-14T19:37:46Z
- **Completed:** 2026-10-14T19:45:29Z
- **Tasks:** 3
- **Files modified:** 6 (4 created, 2 modified)

## Accomplishments

- `ExchangeRateConverter::toCzk()` returns the CZK amount for a currency, amount and day (EX-03). Saturday and Sunday use Friday's list, an outage keeps using the last known list however old (a list thirty days old is still used), CZK passes through without a query, and an uncovered day throws `ExchangeRateMissing` instead of returning zero.
- `RateBook::load()` answers a whole report range with two queries: the newest list before the range per currency (so a ten-day gap or a long holiday block still resolves), then the rows from the oldest of those floors to the end of the range; lookups are an in-memory binary search.
- `Money::forDurationConverted()` rounds `rate x seconds / 3600 x CNB rate / unit` once from one exact rational. The pinned counter-example: 100.00 EUR per hour for 61 s at 25.125 is 42.57 CZK this way and 42.46 CZK when the EUR amount is rounded first (RE-02).
- `Money::hourlyRateFor()` gives the effective hourly rate of a fixed price (`price x 3600 / seconds`, rounded once, zero seconds refused) for RE-05.
- A Partner context finds no rate because `ExchangeRate` is fail-closed, and gets `ExchangeRateMissing`; the converter has no role check of its own.

## Task Commits

Each task was committed atomically (Task 2 and 3 as RED then GREEN):

1. **Task 1: Tracer - toCzk converts at the stored CNB rate of its day** - `97c485e` (feat)
2. **Task 2: Money rounds a converted duration and an effective hourly rate once** - `52a49d4` (test, RED), `5ec21a0` (feat, GREEN)
3. **Task 3: Per-unit currencies, the converted duration, bulk lookups and the fail-closed Partner context** - `fe4d110` (test, RED), `404fc2c` (feat, GREEN)

**Plan metadata:** recorded in the docs commit that follows this summary.

### TDD gate notes

- Task 2 RED (`52a49d4`): the target tests `prices a converted duration`, `rounds a converted duration once` and the refusal and hourly-rate datasets failed with `Call to undefined method Money::forDurationConverted()` / `hourlyRateFor()`, the planned missing behavior. Semantic assessment: the target tests executed and failed because the methods do not exist, not because of a fixture or syntax fault.
- Task 3 RED (`fe4d110`): the four `durationToCzk` tests failed with `Call to undefined method ExchangeRateConverter::durationToCzk()`. The per-unit, gap, later-day and Partner cases already passed against the Task 1 `RateBook` and `toCzk` (the plan allows this: "fix whatever the cases expose"; they exposed nothing), and the query-count case failed only on a test-side fault described under Deviations.

## Files Created/Modified

- `app/Domain/ExchangeRates/RateBook.php` - preloaded previous-valid-rate lookup (two queries, in-memory `rateFor`)
- `app/Domain/ExchangeRates/ExchangeRateConverter.php` - `toCzk`, `durationToCzk`, `rateFor`
- `app/Domain/ExchangeRates/ExchangeRateMissing.php` - typed refusal with `currency` and `day`
- `app/Domain/Shared/Money/Money.php` - `forDurationConverted`, `hourlyRateFor`; shared `validConversion` and `convertedExact` private helpers behind `convert()`
- `tests/Feature/ExchangeRates/ExchangeRateConverterTest.php` - 20 cases (weekend lookup, CZK passthrough, missing rate, 30-day staleness, HUF/JPY/IDR, durationToCzk, gap, later-day, 31-day query count, Partner)
- `tests/Unit/Money/MoneyTest.php` - converted duration, double-rounding counter-example, refusals, effective hourly rate

## Decisions Made

- Days without a list are resolved by lookup, never stored as copies; the converter never reports staleness (plan 08-10's health slot does).
- `RateBook::load()` also validates its input (`YYYY-MM-DD` days, range not reversed) and treats an empty currency list as an empty book without a query.
- `convert()` was refactored onto the same `validConversion()` and `convertedExact()` helpers the new method uses, so one rate/unit/currency rule and one exact formula serve both; the existing `convert()` tests still pass unchanged.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Query-count test counted the Partner scope's role lookup**
- **Found during:** Task 3 (RED run of the 31-day range case)
- **Issue:** The test reported 3 queries instead of 2. The third was the `roles` lookup the Partner scope runs once for the acting user on its first scoped query, not a rate query.
- **Fix:** The query listener counts only statements that touch `exchange_rates`; the production code was correct (two rate queries).
- **Files modified:** tests/Feature/ExchangeRates/ExchangeRateConverterTest.php
- **Verification:** the case passes with exactly two rate queries over 31 days
- **Committed in:** fe4d110 (Task 3 RED commit)

---

**Total deviations:** 1 auto-fixed (1 test-side bug)
**Impact on plan:** None on production behavior; the two-query contract holds.

## Issues Encountered

None beyond the test-side query counting above. Full suite: 3265 passed (22194 assertions); Pint and Larastan clean; `scripts/check-sensitive.sh` clean on every commit.

## Known Stubs

None.

## Threat Flags

None - no new endpoint, auth path, file access or schema; the plan's threats T-08-16 to T-08-19 are mitigated as planned (single rounding point with the pinned counter-example, unit amount passed on every conversion, strictly `rate_date <= day` with `ExchangeRateMissing` instead of zero, Partner finds no rate).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plans 08-06 and later can price every report entry in CZK with `RateBook::load(currencies, from, to)` once and `Money::forDurationConverted()` per entry, or use `ExchangeRateConverter::durationToCzk()` for single lookups.
- Phases 9 and 10 reuse `toCzk()` for invoice and transaction CZK amounts; the document snapshot of the rate is theirs.
- Callers must catch `ExchangeRateMissing` and show a visible marker; there is no zero fallback.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*

## Self-Check: PASSED

- Created files exist: `RateBook.php`, `ExchangeRateConverter.php`, `ExchangeRateMissing.php`, `ExchangeRateConverterTest.php` (FOUND on disk before commit)
- Commits `97c485e`, `52a49d4`, `5ec21a0`, `fe4d110`, `404fc2c` are ancestors of HEAD
- All task acceptance criteria and the plan-level verification (full Pest suite, Pint, Larastan, sensitive-content check) pass
