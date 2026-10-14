---
phase: 08-exchange-rates-and-reports
plan: 17
subsystem: exchange-rates
tags: [cnb, daily-year, gap-self-heal, scheduler, request-discipline, insert-or-ignore]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-04 CnbClient::daily() with the quoting and row validation, CnbList::rows(), CnbDownloadFailed, kokpit:rates:download"
  - phase: 08-exchange-rates-and-reports
    provides: "08-08 and 08-10 CzechPublicHolidays::workingDays() and previousWorkingDay(), the final alert, the rates config block (gap_lookback_days) and the console.healed copy"
provides:
  - "CnbClient::year(int): list<CnbList>: GET exrates/daily-year?year=, same timeouts, retry and per-row validation as daily(), grouped by validFor, a list outside the requested year refused as Malformed, the whole answer validated before anything is returned"
  - "App\\Domain\\ExchangeRates\\Actions\\HealCnbRateGaps: fills Czech working days of the lookback window that have no stored list, one round per Prague day (cache claim kokpit:cnb-gap-heal:<day>), one request per affected year, insert-or-ignore"
  - "kokpit:rates:download heals after a successful daily step and prints kokpit.rates.console.healed; a heal failure takes the existing failure path (final run alerts), the daily rows stay stored"
  - "Tests\\Support\\CnbFixtures::yearJson(): invented year answers for the given days"
affects: [08-18 history backfill (reuses CnbClient::year()), 08-13 manual download (unchanged: it never heals)]

actuals:
  tokens: 8400
  tasks: 2
  commits: 2

plan_head_before: 7e4656362198038fa221d4871b1fb51aabb3df7c
plan_head_after: f6cd6d3dd5c5cc1713c71ef0e1e3cdb1c1e841a8
commits: 2

tech-stack:
  added: []
  patterns:
    - "Rates are fetched, quoted and validated by one shared path (rows() and row()) for the daily and the year endpoint"
    - "A rate-limited remote call is guarded by a per-day cache claim taken only when a request would be made, and kept whatever the answer is"
    - "Every year answer of a round is validated before any list is stored; only days with no stored row are filled, so a stored list is never completed or rewritten"

key-files:
  created:
    - app/Domain/ExchangeRates/Actions/HealCnbRateGaps.php
    - tests/Feature/ExchangeRates/HealCnbRateGapsTest.php
  modified:
    - app/Domain/ExchangeRates/Cnb/CnbClient.php
    - app/Console/Commands/DownloadCnbRatesCommand.php
    - tests/Support/CnbFixtures.php

key-decisions:
  - "The heal runs only in the scheduled command; the manual DownloadCnbRates Action keeps its four outcomes and never asks for a year"
  - "Window = working days from max(oldest stored rate_date, day minus gap_lookback_days) up to the previous working day of the run's day; nothing stored means no window, so a fresh install asks for nothing"
  - "The claim is taken only for a round that would request, and it is kept after a failure: a poisoned answer or a day the calendar wrongly expects costs one request per Prague day, not one per hourly attempt"
  - "Only a day with no stored row is filled, and only up to the run's day; a list dated after it, or an already stored day, is ignored"
  - "handle() returns the number of lists stored (not rows), because the existing console copy says 'Doplněno chybějících seznamů kurzů: :count'"

patterns-established:
  - "Tests that need a daily answer for any run day use healServes(null, ...), which echoes the requested date; a second Http::fake() in one test does not replace the first (first match wins) and resets Http::recorded"

requirements-completed: [EX-01]

coverage:
  - id: D1
    description: "A scheduled run after a missed Czech working day requests daily-year once, stores the missing list and prints the healed line; a lookup of that day then answers its own rate instead of the older one"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it is fetched from the year endpoint once and filled by the next scheduled run"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it is answered by its own list in a lookup afterwards instead of the older one"
        status: pass
    human_judgment: false
  - id: D2
    description: "At most one heal round per Prague day and one request per affected year; a fresh install, a gap older than the lookback, a non-working-day run and a failed daily step make no year request; a gap over the new year asks for each year once"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it makes no second year request in a later run of the same day, but asks again the next day"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it asks for each affected year once"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it skips the heal when the daily step failed"
        status: pass
    human_judgment: false
  - id: D3
    description: "A year answer is validated row by row; a malformed answer fails the run with Malformed, stores nothing from it, keeps today's daily rows and makes the final run alert; lists after the run's day and already stored lists are never written"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it fails the run with Malformed, stores nothing from it and keeps today's rows, and the final run alerts"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it stores a good list of the answer only up to the run's day, never one dated after it"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it never completes or rewrites a list that is already stored"
        status: pass
    human_judgment: false
  - id: D4
    description: "The manual download is unaffected: a direct DownloadCnbRates call never heals"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/HealCnbRateGapsTest.php#it never heals from the manual download Action"
        status: pass
    human_judgment: false

duration: 40 min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 17: CNB gap self-heal Summary

**The scheduled CNB download now repairs a missed Czech working day from `exrates/daily-year`, once a day and one request per affected year, so a gap no longer passes for a holiday.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 2 (a tracer and a TDD hardening task)
- **Files:** 5 (2 created, 3 modified)

## Accomplishments

- `CnbClient::year()` shares the fetch, the rate quoting and the row validation with `daily()` (the body decoding moved into a private `rows()`); the whole year answer is validated before any list is returned, so one bad row means no list of that answer is used.
- `HealCnbRateGaps` finds working days without a stored list in the window, claims the day, requests each affected year once, validates every answer first and then stores only days that have no stored row, up to the run's day, with insert-or-ignore.
- `kokpit:rates:download` calls the heal after the daily step and prints the healed line; a failing heal ends in the same failure path as a failing daily step, and the final run alerts the Admin.
- Mutation check: removing the claim or the "not after the run's day" bound makes two tests fail.

## Task Commits

1. **Task 1: Tracer - a scheduled run after a missed working day fetches the year once and fills the gap** - `b113a59` (feat)
2. **Task 2: One heal a day, no needless requests, a new-year gap, a poisoned year answer and lists after the run's day** - `f6cd6d3` (test)

Tracer gate: the tracer's `<verify>` (targeted test, Pint, PHPStan) was green end to end before the expansion task started. The Task 2 behaviours were already satisfied by the Task 1 implementation, so its tests passed on first run and no production change was needed in that task.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] handle() returns lists, not rows**
- **Found during:** Task 1
- **Issue:** The plan's contract says `handle(): int (rows inserted)`, but the existing copy `kokpit.rates.console.healed` reads "Doplněno chybějících seznamů kurzů: :count." (lists). Printing a row count (28 for one day) would be wrong text.
- **Fix:** `handle()` returns the number of lists (days) that were stored; the command prints that number.
- **Files modified:** app/Domain/ExchangeRates/Actions/HealCnbRateGaps.php
- **Commit:** b113a59

**Total deviations:** 1 auto-fixed (1 bug). **Impact:** none beyond the return-value meaning; no test or consumer depended on the row count.

## Known Behaviour Worth Knowing

- A heal that fails at 15:00 keeps the day's claim, so the 16:00 and 17:00 runs make no second year request; only the failing run's exit code and, for `--final`, the alert report it. If the failure is transient the gap waits for the next Prague day. This is the "one heal round per Prague day" rule applied literally.
- The final-run alert text names today's date and the newest stored date (plan 08-08 copy); after a heal failure that body can read as "today's list missing" although today's list is stored. The title and the action are still right; the copy was not changed here.

## Issues Encountered

None. `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` were already modified or untracked before this plan and were left alone.

## Known Stubs

None.

## Threat Flags

None. The year endpoint is the surface of T-08-58 and T-08-59, both mitigated by the tests above.

## Self-Check: PASSED

- `app/Domain/ExchangeRates/Actions/HealCnbRateGaps.php`, `tests/Feature/ExchangeRates/HealCnbRateGapsTest.php`: present.
- Commits `b113a59` and `f6cd6d3` are ancestors of HEAD.
- All acceptance criteria of both tasks pass; `ddev exec vendor/bin/pest` (3731 passed, 3 skipped), Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.
