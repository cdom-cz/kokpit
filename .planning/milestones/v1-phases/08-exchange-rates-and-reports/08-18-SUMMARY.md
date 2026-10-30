---
phase: 08-exchange-rates-and-reports
plan: 18
subsystem: exchange-rates
tags: [cnb, backfill, queued-job, idempotent, filament-action, daily-year, insert-or-ignore]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-17 CnbClient::year(int): list<CnbList> with whole-answer validation, CnbFixtures::yearJson()"
  - phase: 08-exchange-rates-and-reports
    provides: "08-13 ListExchangeRates with the downloadToday action, ExchangeRateResource::canAccess()"
  - phase: 08-exchange-rates-and-reports
    provides: "08-05 ExchangeRateConverter::toCzk() lookup, the kokpit.rates.backfill config block and copy"
provides:
  - "App\\Domain\\ExchangeRates\\Jobs\\BackfillCnbRates(string $from, string $to): final KokpitJob, #[Idempotent], #[Timeout(240)]; one daily-year request per year from the year of from minus 10 days to the year of to, every list dated up to to stored with insert-or-ignore, one transaction per year"
  - "BackfillCnbRates::violations(from, to): the limits a range breaks (order, earliest, future, span), shared by the form and the job so they cannot disagree; the job re-validates the range in the constructor and in handle()"
  - "ListExchangeRates header action backfill: gray, OutlinedCalendarDays, 'Doplnit historii', before downloadToday; modal 'Doplnit historii kurzů' with Od and Do (default today in Prague), helper, submit and 'Nedoplňovat'; field errors under the offending field; info toast 'Doplnění historie je ve frontě'"
  - "JobContractTest::EXPECTED_APPLICATION_JOBS gains BackfillCnbRates"
affects: [08-19, phase verification (EX-01 history backfill, D-05)]

actuals:
  tokens: 8100
  tasks: 3
  commits: 3

plan_head_before: b5979a4998d7a16b8d7806f2b064e15173c1a324
plan_head_after: 7cf090308b0ed9babc391ada984d5851e75bc25a
commits: 3

tech-stack:
  added: []
  patterns:
    - "One range rule serves the form and the queued job: the page maps the job's violation keys to Od (order, earliest) and Do (future, span)"
    - "A queued job validates its payload again in handle(), because an unserialized payload never passes the constructor"
    - "A year is stored in one transaction after CnbClient::year() validated the whole answer, so a poisoned year stores nothing"

key-files:
  created:
    - app/Domain/ExchangeRates/Jobs/BackfillCnbRates.php
    - tests/Feature/ExchangeRates/BackfillCnbRatesTest.php
  modified:
    - app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/ExchangeRates/ExchangeRateResourceTest.php

key-decisions:
  - "The range limits live once, as BackfillCnbRates::violations(); the form shows each violation under its own field and the job refuses the same range with InvalidArgumentException (T-08-60)"
  - "Span is counted in days including both ends, so a maximum of 30 accepts 30 days and refuses 31"
  - "Do is judged against the Prague day, not the UTC day"
  - "Both fields also carry date_format:Y-m-d so a forged non-ISO day becomes a field error and never reaches the job constructor"
  - "Whole years are stored (not only the range): D-02 keeps every published list and a year is one cheap request; only lists after Do are skipped"

patterns-established:
  - "Job range check as a public static violations() list consumed by the Filament form rule closure"

requirements-completed: [EX-01]

coverage:
  - id: D1
    description: "'Doplnit historii' on 'Kurzy ČNB' (gray, calendar icon, before 'Stáhnout dnešní kurzy') opens the modal 'Doplnit historii kurzů' with Od, Do (default today), the helper, the submit and 'Nedoplňovat'"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the backfill modal"
        status: pass
    human_judgment: true
    rationale: "Look and feel of the modal (spacing, amber contrast of the submit, date picker usability) is not asserted by any test; the UI-SPEC gate measures it"
  - id: D2
    description: "Submitting queues one idempotent BackfillCnbRates job, shows the info toast, and a rerun over the same range queues another job with the same toast and creates no rows"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#queues exactly one job with the given days and the same toast on every submit"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/BackfillCnbRatesTest.php#idempotence"
        status: pass
    human_judgment: false
  - id: D3
    description: "Range errors appear as field errors under Od or Do (order, earliest day, future, span naming the configured days) and a refused submit queues nothing"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/ExchangeRateResourceTest.php#the backfill modal"
        status: pass
    human_judgment: false
  - id: D4
    description: "The backfill stores every list of the covered years up to Do with one daily-year request per year; a weekend or holiday then converts at the previous valid rate"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/BackfillCnbRatesTest.php#the tracer: backfill a range from the overview"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/BackfillCnbRatesTest.php#the years it asks for"
        status: pass
    human_judgment: false
  - id: D5
    description: "A malformed year answer fails the job with a typed failure and stores nothing from that year; the job timeout (240 s) stays below the 300 s supervisor; the job refuses a bad range itself"
    requirement: EX-01
    verification:
      - kind: integration
        ref: "tests/Feature/ExchangeRates/BackfillCnbRatesTest.php#a poisoned answer"
        status: pass
      - kind: integration
        ref: "tests/Feature/ExchangeRates/BackfillCnbRatesTest.php#the range check of the job itself"
        status: pass
    human_judgment: false

duration: 12min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 18: Backfill CNB history from the rates overview Summary

**A validated "Doplnit historii" modal on "Kurzy ČNB" queues an idempotent BackfillCnbRates job that stores whole CNB years via daily-year (insert-or-ignore, one transaction per year), so any past weekend or holiday converts at the previous valid rate.**

## Performance

- **Duration:** 12 min
- **Started:** 2026-10-14T22:37:46Z
- **Completed:** 2026-10-14T22:50:00Z
- **Tasks:** 3
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments

- The `backfill` header action: gray, calendar icon, placed before `downloadToday`; modal heading, Od and Do (Do defaults to today in Prague), helper, submit "Doplnit historii" and cancel "Nedoplňovat"; the page does not wait and shows the info toast "Doplnění historie je ve frontě" on every submit, reruns included.
- `BackfillCnbRates`: one `daily-year` request per calendar year from the year of Od minus 10 days to the year of Do, every list dated up to Do stored with insert-or-ignore; a rerun, an overlapping range or a changed CNB answer inserts and rewrites nothing. A Saturday inside the range converts at the previous Friday's rate (proven through `ExchangeRateConverter::toCzk()`).
- Range limits (order, earliest 1. 1. 2000, no future Do, span from config) defined once in `BackfillCnbRates::violations()`; the form shows each under Od or Do, the job re-validates in the constructor and in `handle()` (a queue payload skips the constructor), so a refused range queues and requests nothing.
- A malformed year answer (bad rate, foreign-year day, rejected request) fails the job with `CnbDownloadFailed` and stores nothing from that year; the timeout (240 s) stays below the 300 s supervisor.

## Task Commits

1. **Task 1: Tracer - the Admin backfills a range and a past Saturday converts at the previous Friday's rate** - `e906ab3` (feat)
2. **Task 2: The modal's limits, labels and field errors** - `f37d2b6` (feat)
3. **Task 3: Idempotent reruns, year boundaries, a poisoned year and the job's own range check** - `7cf0903` (test)

**Plan metadata:** recorded in the docs commit that carries this summary.

_Tracer gate: no `blocking-human` gate, `end-of-phase` verify mode and an automated-only `<verify>`; the tracer's tests, Pint and PHPStan were re-run green before expanding ("Tracer verified end-to-end - expanding")._

## Files Created/Modified

- `app/Domain/ExchangeRates/Jobs/BackfillCnbRates.php` - the queued idempotent backfill and the shared range limits
- `app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php` - the `backfill` header action with the validated modal
- `tests/Feature/ExchangeRates/BackfillCnbRatesTest.php` - tracer, idempotence, year boundaries, poisoned answers, the job's own range check
- `tests/Feature/ExchangeRates/ExchangeRateResourceTest.php` - modal labels, action order, every limit and its boundary, reruns, Partner refusal
- `tests/Arch/JobContractTest.php` - `BackfillCnbRates` added to the expected job list on purpose

## Decisions Made

- One source for the range limits (`BackfillCnbRates::violations()`), consumed by both the form and the job, so the two cannot drift apart; the job additionally re-validates in `handle()` because a queue payload does not pass the constructor.
- Span counts days including both ends (maximum 30 accepts 30 days, refuses 31).
- Whole years are stored, not only the range (D-02 stores everything; a year is one cheap request); only lists dated after Do are skipped.
- Do is compared with the Prague day, not the UTC day (tested across the midnight boundary).
- Both date fields carry `date_format:Y-m-d` so a forged non-ISO day is a field error, not an exception from the job constructor.

## TDD Notes

Task 2 ran RED first: five limit tests failed (the job constructor threw instead of the field errors appearing, since the form had no rules yet) plus the labels test, then went green with the rules. Task 3 is a hardening task over a job whose behavior already existed from Task 1, so its tests passed on first run by design; they were checked for teeth by mutation (margin set to 0 and the `validFor <= to` filter removed each failed the intended tests; the mutations were reverted).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing header-actions assertion updated in Task 1**
- **Found during:** Task 1
- **Issue:** `ExchangeRateResourceTest` asserted the header actions are exactly `['downloadToday']`, which the new action makes false; `ExchangeRateResourceTest.php` is listed in the plan only under Task 2.
- **Fix:** Changed the expectation to `['backfill', 'downloadToday']` in the Task 1 commit so the suite stays green per commit.
- **Files modified:** tests/Feature/ExchangeRates/ExchangeRateResourceTest.php
- **Verification:** targeted and full suite green
- **Committed in:** e906ab3

**2. [Rule 2 - Missing critical] `date_format:Y-m-d` on both date fields**
- **Found during:** Task 2
- **Issue:** Filament's `date` rule accepts days like `2025-3-1`, which the job constructor would refuse with an exception instead of a field error (T-08-60 defence in depth should not surface as a server error).
- **Fix:** Added `date_format:Y-m-d` to Od and Do, with a test.
- **Files modified:** app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php, tests/Feature/ExchangeRates/ExchangeRateResourceTest.php
- **Committed in:** f37d2b6

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 missing critical)
**Impact on plan:** Both small and in scope; no scope creep. The job was implemented complete in Task 1 (guard and `violations()` included) because the form rule of Task 2 reuses it.

## Issues Encountered

- Filament v4 does not render the mounted action modal into the Livewire test HTML, so `assertSee` cannot read the modal copy. The tests read the heading and labels from the mounted action, the helper from the field's below-content schema and the field errors from `errors()` instead.
- The acceptance grep for `max_span_days` in the page file required the config key there; the span message therefore reads the configured days from `config('kokpit.rates.backfill.max_span_days')` directly while the limit itself stays in the job.

## Known Stubs

None.

## Threat Flags

None. The backfill adds no endpoint: it is an Admin-only Filament action (`abort_unless(ExchangeRateResource::canAccess(), 403)`, Partner refusal tested) and a queued job that talks only to the configured CNB host. T-08-60, T-08-61 and T-08-62 are mitigated as planned.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- EX-01 history backfill is complete; the rates overview now offers both the daily download and the history load.
- Full suite green (3787 passed, 3 skipped pre-existing), Pint and PHPStan clean.

## Self-Check: PASSED

All created files exist; commits `e906ab3`, `f37d2b6` and `7cf0903` are on the branch; all task acceptance criteria and the plan verification (full Pest suite, Pint, PHPStan, `scripts/check-sensitive.sh`) passed.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-15*
