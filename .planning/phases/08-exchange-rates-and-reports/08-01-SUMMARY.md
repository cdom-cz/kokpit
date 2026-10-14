---
phase: 08-exchange-rates-and-reports
plan: 01
subsystem: database
tags: [postgres, exchange-rates, cnb, eloquent, partner-isolation, schema-constraints]

requires:
  - phase: 02-foundation-platform
    provides: UUID v7 key convention, numeric(20,10) rate convention, DeniesPartners and AdminOnlyPolicy, canary harness, MorphMap
provides:
  - exchange_rates table with unique (currency, rate_date) and CHECKs on currency, unit amount and rate
  - App\Domain\ExchangeRates\Models\ExchangeRate, Admin-only, no fillable attributes
  - morph alias exchange_rate, AdminOnlyPolicy registration, ModelDeclarationTest and canary fixture entries
affects: [08-04 download Action, 08-05 rate lookup, 08-10 health slot, 08-17 backfill, 08-18 gap heal, 10 invoice snapshots]

actuals:
  tokens: 6100
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A rate is stored per currency and published day with its unit amount; non-publication days are resolved by lookup, never stored"
    - "Each schema refusal is proven by SQLSTATE and by a mutation check that drops its named constraint"

key-files:
  created:
    - database/migrations/2026_10_15_000100_create_exchange_rates_table.php
    - app/Domain/ExchangeRates/Models/ExchangeRate.php
    - tests/Feature/Schema/ExchangeRatesTableTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php

key-decisions:
  - "One row per currency and published CNB date; weekends and holidays are never stored, they are resolved by lookup in plan 08-05, so MAX(rate_date) keeps meaning the newest downloaded list"
  - "CNB lists are immutable once published: writers use insert-or-ignore, a conflict does nothing, the model has no fillable attributes"
  - "ExchangeRate is PartnerIsolated with DeniesPartners and gets a canary fixture whose needle is the published day (it has no text column)"

patterns-established:
  - "Canary needle for a model with a date and no text: the Monday of the canary, shared with the Signal day models"
  - "Mutation self-check inside a schema test: drop the named constraint in the test transaction and assert the refused row is then accepted"

requirements-completed: [EX-01]

coverage:
  - id: D1
    description: "exchange_rates stores one row per currency and published day with the unit amount as published (1, 100, 1000)"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/ExchangeRatesTableTest.php#it stores the unit amount as published"
        status: pass
    human_judgment: false
  - id: D2
    description: "The database refuses a duplicate currency and day, a bad currency, a unit amount below 1 and a rate of zero or below, whatever path writes the row"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/ExchangeRatesTableTest.php#uniqueness (23505), checks (23514), mutation self-check"
        status: pass
    human_judgment: false
  - id: D3
    description: "A rate reads back through the model as the exact decimal string (25.125 reads 25.1250000000); no float column exists"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/ExchangeRatesTableTest.php#it reads a stored rate back as the exact decimal string and the stored day"
        status: pass
      - kind: unit
        ref: "tests/Feature/Schema/ExchangeRatesTableTest.php#it holds no float column"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner reads no exchange_rates row and has no ability on ExchangeRate; the Admin reads every row"
    requirement: EX-01
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/ExchangeRatesTableTest.php#Partner visibility"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php (canary fixture, Admin sees both, Partner sees zero)"
        status: pass
    human_judgment: false

duration: 16min
completed: 2026-10-14
status: complete
plan_head_before: cbd8e9baee327009a08ae06567d9e1a55d07630c
plan_head_after: e0760865f5c1f08a95fc3671b7ca14d3c56be8b2
commits: 2
---

# Phase 8 Plan 01: Exchange Rates Storage Summary

**exchange_rates table (unique currency and published day, numeric(20,10) rate, per-unit amounts) with an Admin-only ExchangeRate model, every repository registration, and raw-SQL constraint proofs**

## Performance

- **Duration:** 16 min
- **Started:** 2026-10-14T18:42:00Z
- **Completed:** 2026-10-14T18:58:00Z
- **Tasks:** 2
- **Files modified:** 8 (3 created, 5 modified)

## Accomplishments
- The `exchange_rates` table holds the whole CNB daily list: `UNIQUE (currency, rate_date)`, CHECKs for the currency format, unit amount and rate, `numeric(20,10)` rate, `uuidv7()` key default, index on `rate_date`.
- `ExchangeRate` reads a stored 25.125 back as `25.1250000000`, carries no fillable attributes and is closed to Partners (`DeniesPartners` plus `AdminOnlyPolicy`).
- Registered where the repository demands it: morph alias `exchange_rate`, policy line with the reason comment, `ModelDeclarationTest` lists, a `CanaryRegistry` fixture and `CanaryRegistryTest` expectation.
- Schema test of 26 cases: exact decimal read-back, per-unit amounts, Partner sees zero rows and every ability is denied, 23505 and 23514 by raw SQL, UUID v7, numeric catalogue, no float column, and a mutation check per constraint.

## Task Commits

1. **Task 1: Tracer - a stored exchange rate reaches the Admin and never a Partner** - `08a39b6` (feat)
2. **Task 2: The database itself refuses duplicates and nonsense rates** - `e076086` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified
- `database/migrations/2026_10_15_000100_create_exchange_rates_table.php` - table, unique key, three CHECKs, header docblock with the invariants
- `app/Domain/ExchangeRates/Models/ExchangeRate.php` - final Admin-only model with immutable date casts and `decimal:10`
- `app/Domain/Shared/Database/MorphMap.php` - alias `exchange_rate`
- `app/Providers/AccessServiceProvider.php` - `Gate::policy(ExchangeRate::class, AdminOnlyPolicy::class)`
- `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Support/CanaryRegistry.php` - model and canary registrations
- `tests/Feature/Schema/ExchangeRatesTableTest.php` - read-back, visibility, constraint and catalogue proofs

## Decisions Made
- Followed the plan: one row per currency and published day, lookup instead of stored fill for non-publication days, immutable lists (insert-or-ignore), Admin-only with a canary fixture.
- The canary fixture of `ExchangeRate` uses the Monday of the canary as its published day under the fictional currency `XTS`, and `needles()` returns that day (the model has no text column), sharing `signalMonday()` with the Signal day models.

## Deviations from Plan

None - plan executed exactly as written.

Notes on the TDD flow (not deviations): Task 1 was a tracer, RED was the missing `ExchangeRate` class (7 failing tests), then GREEN after the migration, model and registrations. Task 2 is `tdd="true"` but its behaviour was already delivered by the Task 1 migration, so its tests were green on first run by the plan's design (Task 2 says "fix whatever the proofs expose"). To keep that from being a vacuous green, the mutation self-check cases drop each named constraint inside the test transaction and assert the refused row is then accepted, proving every refusal is owed to its constraint. The Task 1 tracer verify was re-run after the commit-ready state and passed (Pest targeted suites, Pint, Larastan), so expansion continued.

## Issues Encountered
- Pint reported `ordered_imports` and operator-spacing issues in `tests/Support/CanaryRegistry.php` after the edit; fixed with Pint before the commit.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None - no new network endpoint, auth path or trust-boundary surface beyond the planned `exchange_rates` table (T-08-01 and T-08-02 mitigated and tested).

## Next Phase Readiness
- Plan 08-04 can write the downloaded list with `ExchangeRate::query()->insertOrIgnore()`; 08-05 can build the lookup on `(currency, rate_date)`; 08-10 can read `MAX(rate_date)`.
- Full suite green at the end of the plan (3055 tests), Pint and Larastan clean, `scripts/check-sensitive.sh` clean.

## Self-Check: PASSED

- Created files exist: migration, model and schema test found on disk.
- Commits `08a39b6` and `e076086` are ancestors of HEAD.
- All Task 1 and Task 2 acceptance criteria re-run and passing; plan-level verification (full Pest, Pint, Larastan, sensitive-content check) passing.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
