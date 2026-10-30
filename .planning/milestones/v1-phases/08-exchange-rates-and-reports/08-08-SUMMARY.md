---
phase: 08-exchange-rates-and-reports
plan: 08
subsystem: exchange-rates
tags: [cnb, scheduler, alerts, czech-holidays, artisan, admin-alerter, europe-prague]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-04 kokpit:rates:download, DownloadCnbRates Action, CnbDownloadFailed, the rates config block and the kokpit.rates.* copy"
  - phase: 03-operations
    provides: "AdminAlerter (synchronous bell and e-mail, throttled) and OperationalAlert"
provides:
  - "CzechPublicHolidays: isWorkingDay, isPublicHoliday, previousWorkingDay and easterSunday without ext-calendar"
  - "kokpit:rates:download skips non-working Prague days without a request, keeps hourly failures quiet and alerts the Admin from the --final run only"
  - "Schedule entries kokpit-cnb-rates (15, 16, 17) and kokpit-cnb-rates-final (18) in Europe/Prague on one server, built from kokpit.rates config"
  - "OperationalAlert optional action label"
affects: [08-10 health slot (working-day calendar), 08-13 rates overview (alert action route), 08-17 backfill, 08-18 gap heal]

actuals:
  tokens: 9600
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Only the final scheduled attempt of a day alerts; earlier attempts fail with a warning line and exit 1"
    - "An alert action URL is resolved with Route::has(), so a not yet built Filament page degrades to an alert without an action"
    - "A database error on the final run alerts first and is then rethrown, so the log keeps the exception and the alert never carries it"

key-files:
  created:
    - app/Domain/ExchangeRates/CzechPublicHolidays.php
    - tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php
    - tests/Feature/ExchangeRates/RateAlertTest.php
  modified:
    - app/Console/Commands/DownloadCnbRatesCommand.php
    - app/Domain/Operations/Alerts/OperationalAlert.php
    - routes/console.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - tests/Feature/ExchangeRates/DownloadCnbRatesTest.php

key-decisions:
  - "The daily download stays a synchronous scheduled command, not a queued job, so a dead worker cannot swallow the alert"
  - "A QueryException on the final run alerts and is rethrown; on an hourly run it propagates untouched. No Czech console line was invented for it"
  - "The no-list check after a successful final attempt reads exchange_rates for a row dated today instead of trusting the payload date"
  - "--final is declared as a bare option; its meaning is in the command description"

patterns-established:
  - "Alert bodies name dates only; canary tests prove response body, host, cURL text and database error text never reach the mail or the bell"

requirements-completed: [EX-01, EX-02]

coverage:
  - id: D1
    description: "The scheduler runs the download at the configured Prague hours (15, 16, 17) and once more with --final at 18, Monday to Friday, in Europe/Prague, on one server"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/Operations/ScheduleOnOneServerTest.php#schedules the CNB download at the configured Prague hours and the final run an hour later"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/ScheduleOnOneServerTest.php#runs every scheduled event on one server only"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Saturday, a Sunday, Good Friday, Easter Monday and fixed holidays make no HTTP request and send no alert; the working-day calendar answers for Easter, fixed days and Good Friday from 2016"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#makes no request and sends no alert on a non-working day"
        status: pass
    human_judgment: false
  - id: D3
    description: "A failed hourly attempt only warns (exit 1, no alert, nothing queued)"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#only warns, exits 1 and sends no alert"
        status: pass
    human_judgment: false
  - id: D4
    description: "The final run alerts once per day by e-mail and bell, synchronously and without the queue, when the download failed, the CNB answers an older list or the database fails; the alert names today and the last known rate date (or the no-list text) and the action Otevřít kurzy ČNB"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#the final run that could not download today's list"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#the final run of a working day"
        status: pass
    human_judgment: false
  - id: D5
    description: "The alert body holds dates only: response body, host, exception text and database error text are absent from the mail and the bell"
    requirement: EX-02
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/RateAlertTest.php#keeps the response body, the host and the exception text out of the mail and the bell"
        status: pass
    human_judgment: false
  - id: D6
    description: "The alert button links to the rates overview once plan 08-13 builds the route"
    requirement: EX-02
    verification: []
    human_judgment: true
    rationale: "The route filament.admin.resources.exchange-rates.index does not exist yet; the link is proven with a stand-in route name only, so the real link must be checked after 08-13"

duration: about 45 min
completed: 2026-10-14
status: complete
plan_head_before: cfab54e645cf4adde4b378751c16ec2b59f196b8
plan_head_after: a0726c65a667118ea22356ab69535ba6a2d2eb9c
commits: 3
---

# Phase 8 Plan 08: CNB Download Rhythm and Failure Alert Summary

**Scheduled CNB download on Czech working days at 15, 16 and 17 Prague time with a final 18:00 run that alerts the Admin by bell and e-mail without the queue when the day ends without a list, backed by an own Czech public-holiday calendar**

## Performance

- **Duration:** about 45 min (the start time was not captured at the start of the run; the full suite alone takes about 4.5 min)
- **Completed:** 2026-10-14T20:27:29Z
- **Tasks:** 3
- **Files modified:** 8 (3 created, 5 modified)

## Accomplishments
- `CzechPublicHolidays` answers working days, public holidays, the previous working day and Easter Sunday (anonymous Gregorian algorithm, no `ext-calendar`): 11 fixed days, Easter Monday and Good Friday from 2016; malformed days throw.
- `routes/console.php` registers `kokpit-cnb-rates` (`0 15,16,17 * * 1-5`) and `kokpit-cnb-rates-final` (`0 18 * * 1-5`, `--final`), both `Europe/Prague` and `onOneServer()`, with the hours read from `kokpit.rates.attempt_hours` and `final_hour`.
- `kokpit:rates:download` returns early on a weekend or holiday (no HTTP, exit 0, Czech line), warns and exits 1 on a failed hourly attempt, and on the `--final` run alerts through `AdminAlerter` (key `cnb-rates:<day>`) when the attempt failed, the CNB answers an older list or the database throws, exit 1 in those cases.
- `OperationalAlert` takes an optional action label; existing alerts keep "Otevřít stránku Systém". The CNB alert reads "Otevřít kurzy ČNB" and its URL comes from `Route::has('filament.admin.resources.exchange-rates.index')`, so it carries no action until plan 08-13 adds that route.

## Task Commits

1. **Task 1: Tracer - the final run alerts the Admin by e-mail and bell without the queue** - `8d2a180` (feat)
2. **Task 2: The Czech working-day calendar** - `0058bf9` (feat; the test file was run RED against the missing class, 49 failures, then the class was added; tests and class share one commit)
3. **Task 3: Working-day gating, quiet hourly failures and the no-list alert** - `a0726c6` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified
- `app/Domain/ExchangeRates/CzechPublicHolidays.php` - pure Czech working-day calendar
- `app/Console/Commands/DownloadCnbRatesCommand.php` - `--final`, working-day gate, quiet hourly failure, alert
- `app/Domain/Operations/Alerts/OperationalAlert.php` - optional `actionLabel`
- `routes/console.php` - the two CNB schedule entries
- `tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php` - 49 cases (Easter, holidays, previous working day, malformed days)
- `tests/Feature/ExchangeRates/RateAlertTest.php` - 20 cases (alert, throttle, gating, hourly warning, canaries)
- `tests/Feature/Operations/ScheduleOnOneServerTest.php` - the two events, their cron expressions and zone
- `tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` - Saturday cases moved from the command to the Action

## Decisions Made
- Synchronous scheduled command, not a queued job (plan decision, kept): a dead worker cannot swallow the alert.
- A `QueryException` is not translated into Czech console copy; on the final run it alerts and is rethrown so the log keeps it, on an hourly run it propagates. No new copy key was added, so the reviewed rates copy contract stays unchanged.
- The "no list for today" check reads the table for a row dated today, as the plan says, rather than comparing the payload date.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan 08-04 tests that exercised the command on a Saturday became vacuous**
- **Found during:** Task 3 (full targeted run after adding the working-day gate)
- **Issue:** Two tests in `DownloadCnbRatesTest` ran `kokpit:rates:download` on Saturday 2026-10-10 to prove the list is stored under the payload's own validity date. With the gate the command makes no request on a Saturday, so those tests failed (and a third would have passed vacuously).
- **Fix:** The Saturday cases now call `DownloadCnbRates::handle()` directly, which is still the path of a manual download, backfill and gap heal; a new Wednesday case keeps the command-level "not yet published" coverage (CNB still answers Tuesday's list).
- **Files modified:** `tests/Feature/ExchangeRates/DownloadCnbRatesTest.php`
- **Verification:** all 240 tests of the rates, schedule and alert files pass; full suite green
- **Committed in:** `a0726c6`

---

**Total deviations:** 1 auto-fixed (1 Rule 1)
**Impact on plan:** Necessary consequence of the plan's own working-day gate; no scope creep, no production code change beyond the plan.

Minor notes (not deviations): Task 2 was committed as one commit (RED run observed, then GREEN) instead of separate test and feat commits; the hourly failure lines use `warn`, the final run keeps `error`.

## Issues Encountered
None.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None. The alert carries no action button until plan 08-13 registers `filament.admin.resources.exchange-rates.index`; this is the plan's intended `Route::has()` degradation, not a stub.

## Threat Flags

None. The only new surface is the scheduled outbound call to the CNB already covered by T-08-15 and T-08-30 (no request on weekends, holidays or once today's list is stored, at most four attempts a day).

## Next Phase Readiness
- Plan 08-13 must register the route `filament.admin.resources.exchange-rates.index`; the alert action then appears with the label "Otevřít kurzy ČNB" without a code change here. The real link should be checked once, after that plan.
- Plan 08-10 (health slot) and 08-18 (gap heal) can use `CzechPublicHolidays::isWorkingDay()` and `previousWorkingDay()`; `console.not_working_day` is now in use.
- Full suite green (3441 passed, 3 skipped), Pint and Larastan clean, `scripts/check-sensitive.sh` clean on every commit.

## Self-Check: PASSED

- Created files exist: `CzechPublicHolidays.php`, `CzechPublicHolidaysTest.php`, `RateAlertTest.php`.
- Commits `8d2a180`, `0058bf9` and `a0726c6` are ancestors of HEAD; `git rev-list --count` from the recorded plan head gives 3.
- Acceptance criteria of all three tasks re-run and passing; plan-level verification (full Pest, Pint, Larastan, sensitive-content check) passing.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
