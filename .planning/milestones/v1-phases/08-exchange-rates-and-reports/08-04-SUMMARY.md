---
phase: 08-exchange-rates-and-reports
plan: 04
subsystem: exchange-rates
tags: [cnb, exchange-rates, http-client, artisan, exact-decimal, idempotent-insert, czech-copy, config]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-01 exchange_rates table (unique currency and day, CHECKs, numeric(20,10)) and the Admin-only ExchangeRate model"
  - phase: 02-foundation-platform
    provides: "PartnerContext::runAsSystem, the AresClient HTTP pattern, PragueDay, the Money::convert() rate shape"
provides:
  - "CnbClient::daily(): fetch, exact parse and per-row validation of the CNB daily list, typed CnbList / CnbFailure / CnbDownloadFailed"
  - "DownloadCnbRates Action: skips a stored day without HTTP, stores under the payload validFor with insert-or-ignore"
  - "kokpit:rates:download console command (system context, Czech output, exit 0 or 1)"
  - "config/kokpit.php rates block: attempt hours 15/16/17, final hour 18, health 1/2 working days, gap lookback 31, backfill 2000-01-01 and 3660 days"
  - "The whole rates Czech copy: kokpit.rates.* (UI-SPEC verbatim) and kokpit.system.last_rate_date.* with Czech plurals"
  - "services.cnb.base_url and Tests\\Support\\CnbFixtures (invented payloads built at runtime)"
affects: [08-05 rate lookup, 08-08 overview, 08-10 health slot, 08-13 download action UI, 08-17 backfill and year(), 08-18 scheduler, alert and gap heal]

actuals:
  tokens: 14100
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "External decimal numbers are quoted in the raw JSON body before json_decode, so a rate is a string from the wire to the column"
    - "A downloaded list is stored under the validFor the payload names, never under the requested day; a stored list is never rewritten"
    - "A typed exception carries an enum value and an HTTP status only; the console prints a Czech label, never response or transport text"
    - "A whole area's reviewed contract (config values and Czech copy) lands in its first plan so later plans in parallel waves only read it"

key-files:
  created:
    - app/Domain/ExchangeRates/Cnb/CnbClient.php
    - app/Domain/ExchangeRates/Cnb/CnbList.php
    - app/Domain/ExchangeRates/Cnb/CnbFailure.php
    - app/Domain/ExchangeRates/Cnb/CnbDownloadFailed.php
    - app/Domain/ExchangeRates/Actions/DownloadCnbRates.php
    - app/Console/Commands/DownloadCnbRatesCommand.php
    - tests/Support/CnbFixtures.php
    - tests/Feature/ExchangeRates/DownloadCnbRatesTest.php
    - tests/Feature/ExchangeRates/RatesContractTest.php
  modified:
    - config/services.php
    - config/kokpit.php
    - lang/cs/kokpit.php

key-decisions:
  - "The list is fetched from /exrates/daily with lang=EN and stored under the payload validFor; a payload with mixed dates, a date after the requested day, a repeated currency or any invalid row refuses the whole list"
  - "A rate must match digits with at most ten integer and ten fraction digits and not be zero: the Money::convert() shape, tightened to ten integer digits so a value can never reach numeric(20,10) as a database error"
  - "CNB_API_BASE_URL has a default in config/services.php and is not listed in .env.example (like ARES_BASE_URL)"
  - "Scheduler hours 15, 16 and 17 plus a final run at 18 (Prague, Monday to Friday); health warning after 1 and error after 2 missing working days; gap lookback 31 days; backfill from 2000-01-01, at most 3660 days a request"
  - "Console, failure and alert.body_none copy is planner-written Czech in the UI-SPEC tone; alert.body_none avoids a last-known date that may not exist"

patterns-established:
  - "Mutation check by editing the production file: every validation line of CnbClient and the insert-or-ignore call was removed in turn and the suite failed each time"
  - "Canary strings assembled at runtime from fragments prove that a body or exception text never reaches the console"

requirements-completed: [EX-01, EX-02]

coverage:
  - id: D1
    description: "kokpit:rates:download stores the whole CNB list (every currency of the payload) once, under the payload's own validFor date, with unit amounts 1, 100 and 1000 as published"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/ExchangeRates/DownloadCnbRatesTest.php#storing the whole list"
        status: pass
    human_judgment: false
  - id: D2
    description: "A second run the same day makes no HTTP request and stores nothing; an answer that is already stored inserts zero rows and never rewrites a rate"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/ExchangeRates/DownloadCnbRatesTest.php#idempotence"
        status: pass
      - kind: unit
        ref: "tests/Feature/ExchangeRates/DownloadCnbRatesTest.php#mutation self-check: the Action keeps ignoring conflicts"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Saturday run stores the Friday list under Friday only; no row is written under a requested day the payload does not name"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/ExchangeRates/DownloadCnbRatesTest.php#the validity date"
        status: pass
    human_judgment: false
  - id: D4
    description: "Rates stay exact decimal strings from the HTTP body to numeric(20,10); a malformed, zero, negative, over-precise, exponent, future-dated, duplicated or mixed-date payload and a connection error, 4xx and 5xx end in a typed CnbDownloadFailed with a Czech reason and store nothing; no response or exception text reaches the console"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/ExchangeRates/DownloadCnbRatesTest.php#refusing a bad payload, #refusing a bad answer"
        status: pass
    human_judgment: false
  - id: D5
    description: "The rates timetable, health thresholds, gap lookback and backfill limits live in config/kokpit.php under rates and satisfy D-03 to D-05; the whole Czech rates copy and the System page plurals exist under kokpit.rates.* and kokpit.system.last_rate_date.*"
    requirement: EX-02
    verification:
      - kind: unit
        ref: "tests/Feature/ExchangeRates/RatesContractTest.php"
        status: pass
    human_judgment: false

duration: 35min
completed: 2026-10-14
status: complete
plan_head_before: 8f7c9e0b61afbad0b36fadd639ff9f4d44563733
plan_head_after: c47420044f9423b9b21ebea2253175355cd8bbbd
commits: 3
---

# Phase 8 Plan 04: CNB Rates Download Summary

**CNB daily list download with exact decimal parsing and per-row validation, stored once under its own validFor date through insert-or-ignore, plus the rates area's timetable, limits and complete Czech copy as one reviewed contract**

## Performance

- **Duration:** about 35 min
- **Completed:** 2026-10-14T19:35:48Z
- **Tasks:** 3
- **Files modified:** 12 (9 created, 3 modified)

## Accomplishments
- `php artisan kokpit:rates:download` fetches the CNB daily list and stores every currency of it (30 in the real payload; 6 invented in tests) with its unit amount, in one insert-or-ignore, under the `validFor` day the payload names. A second run the same day makes no request.
- Rates never become floats: every `"rate": <number>` token is quoted in the raw body before `json_decode`, then validated field by field (currency `^[A-Z]{3}$`, integer amount of at least 1, positive decimal rate with at most ten fraction digits, one shared real `validFor` not after the requested day, no repeated currency). Any bad row refuses the whole list.
- Failures are typed: connection errors map to Unavailable, 4xx to Rejected (asked once), 5xx to ServerError after three tries, everything else to Malformed. The exception carries the enum value and the status only; the command prints the Czech label and status and never the response or exception text (canary tests).
- The rates area's contract is in place for plans 08-08, 08-10, 08-13, 08-17 and 08-18: `config/kokpit.php` `rates` block (documented inline) and the complete `kokpit.rates.*` and `kokpit.system.last_rate_date.*` copy, with Czech plural forms for 1, 2 to 4 and 5 or more.

## Task Commits

1. **Task 1: Tracer - kokpit:rates:download stores today's whole CNB list once, under its own validFor date** - `8c37753` (feat)
2. **Task 2: Every refusal of a bad payload, and the mutation self-check that the Action keeps ignoring conflicts** - `fcc35a3` (test)
3. **Task 3: The rates timetable, limits and Czech copy as one reviewed contract** - `c474200` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified
- `app/Domain/ExchangeRates/Cnb/CnbClient.php` - HTTP fetch (3 s connect, 15 s timeout, retry 3 x 300 ms on connection errors and 5xx), exact parse and validation
- `app/Domain/ExchangeRates/Cnb/CnbList.php` - validated list value with `rows()` for insert (explicit UTC timestamps, no id)
- `app/Domain/ExchangeRates/Cnb/CnbFailure.php`, `CnbDownloadFailed.php` - failure enum with Czech label and the typed exception
- `app/Domain/ExchangeRates/Actions/DownloadCnbRates.php` - idempotent download of one day
- `app/Console/Commands/DownloadCnbRatesCommand.php` - `kokpit:rates:download`, system context, Czech output
- `config/services.php`, `config/kokpit.php`, `lang/cs/kokpit.php` - `services.cnb.base_url`, `rates` config block, rates and System page copy
- `tests/Support/CnbFixtures.php` - invented payloads assembled at runtime, rates written as JSON numbers
- `tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` (63 cases), `RatesContractTest.php` (69 cases)

## Decisions Made
- The rate shape is the `Money::convert()` regex limited to ten integer digits, so no accepted value can fail the `numeric(20,10)` column later.
- Duplicate currencies in one payload and a payload of which any row is bad refuse the whole list (a half-stored list would hide a gap).
- `alert.body_none` was written for the case that no list has ever been stored (the UI-SPEC body names a last known date that would not exist). Console and failure copy is planner-written Czech in the UI-SPEC tone.
- The scheduler, health and backfill numbers are the Claude's-discretion values named in the plan (15/16/17 and 18, 1 and 2 working days, 31, 2000-01-01 and 3660).

## Deviations from Plan

None - plan executed exactly as written.

Notes on the TDD flow (not deviations): Task 1 was a tracer; RED was the missing classes (19 failing tests), then GREEN, and the tracer `<verify>` (Pest targeted, Pint, Larastan, Arch, Repo and Localisation suites) was re-run before expansion. Task 2 is `tdd="true"` but its refusals were already delivered by the Task 1 client, so its matrix was green on first run by design (one failing run only for a Pint-removed import). To keep that from being a vacuous green, each validation line and the insert-or-ignore call were removed in turn from the production files and the suite failed every time (insert-or-ignore: 6 failures; stored-day check: 47; zero-rate check: 2; future-date check: 1; mixed-date check: 1; ten-digit limit: 2; amount minimum: 2; duplicate currency: 1); the files were restored from git afterwards.

## Issues Encountered
- Pint removed the then-unused `ConnectionException` import from the Task 1 test file; it was restored when Task 2 needed it.

## User Setup Required

None - no external service configuration required. `CNB_API_BASE_URL` has a default and is deliberately not listed in `.env.example`.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model: the one new outbound endpoint (CNB) is T-08-15 (base URL from config only, TLS verification on) and the data from it is validated per T-08-11 to T-08-14.

## Next Phase Readiness
- Plan 08-05 (lookup) and 08-10 (health slot) can read `exchange_rates` and the `kokpit.rates.health.*` thresholds; 08-17 reuses the private parse-and-validate path of `CnbClient` for `year()` and `backfill.*`; 08-18 adds the schedule entries from `kokpit.rates.attempt_hours` and `final_hour`, the alert (`kokpit.rates.alert.*`) and gap heal (`gap_lookback_days`, `console.not_working_day`, `console.healed`).
- Full suite green at the end of the plan (3229 tests), Pint and Larastan clean, `scripts/check-sensitive.sh` clean on every commit.

## Self-Check: PASSED

- All nine created files exist on disk.
- Commits `8c37753`, `fcc35a3` and `c474200` are ancestors of HEAD; `git rev-list --count` from the recorded plan head gives 3.
- All acceptance criteria of the three tasks re-run and passing; plan-level verification (full Pest, Pint, Larastan, sensitive-content check) passing.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
