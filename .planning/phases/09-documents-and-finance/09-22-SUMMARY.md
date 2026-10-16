---
phase: 09-documents-and-finance
plan: 22
subsystem: finance
tags: [finance, transactions, czk-snapshot, cnb-rate, actions, partner-isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: transactions table, Transaction model and the finance.transactions.errors contract (09-09, 09-06), category tree and selectable scope (09-04)
  - phase: 08-time-reports-and-exchange-rates
    provides: ExchangeRateConverter::rateFor and the CNB rate rows (EX-03)
provides:
  - CzkSource enum (identity, cnb, manual) with Czech labels
  - TransactionInput, the shared validator of every transaction write
  - TransactionCzk, the one CZK snapshot rule
  - RecordTransaction, UpdateTransaction and DeleteTransaction, all Admin-only
affects: [09-11 transaction screen, 09-13 receipts, 09-15 finance overview, 09-20 CONTRIBUTING hand-over note, Phase 10 invoice link]

actuals:
  tokens: 12264
  tasks: 3
  commits: 3
plan_head_before: b6a4a895660f1737108dcda35aa219e2f946a0d9
plan_head_after: a21e0aaadf25c03129d5fbba3225be6c08be83ba
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "CZK figure fixed once at write: rateFor() then Money::convert with the same rate and unit amount, snapshot stored with it"
    - "Validator re-checks references under a share lock inside the caller's transaction; an unchanged archived reference is accepted"
    - "Sticky manual figure: a stored manual source survives date, currency and amount edits until czk_manual is switched off"

key-files:
  created:
    - app/Domain/Finance/Enums/CzkSource.php
    - app/Domain/Finance/TransactionInput.php
    - app/Domain/Finance/TransactionCzk.php
    - app/Domain/Finance/Actions/RecordTransaction.php
    - app/Domain/Finance/Actions/UpdateTransaction.php
    - app/Domain/Finance/Actions/DeleteTransaction.php
    - tests/Feature/Finance/TransactionCzkTest.php
  modified:
    - app/Domain/Finance/Models/Transaction.php
    - lang/cs/enums.php
    - app/Domain/Shared/Money/Money.php
    - tests/Feature/Schema/TransactionTablesTest.php

key-decisions:
  - "The CZK rule calls rateFor() once and Money::convert with that rate and unit amount, so the exact rate, unit and list date used are what is stored (research Pattern 7)"
  - "Editing recomputes a computed (identity or CNB) transaction only when the date, currency or amount changed; any other edit leaves the stored figure and snapshot untouched"
  - "A manual figure stays manual until czk_manual is false; switching manual on without a figure freezes the stored one; a CZK amount is always identity"
  - "A project typed without a client is refused as project_not_of_client instead of deriving the client"
  - "Currency options are CZK plus every currency with a stored CNB row; an unchanged stored currency is accepted on edit"

patterns-established:
  - "Input class with $current: absent keys keep the stored value, so partial edits and full form submits share one path"
  - "Admin-only write Actions authorize through Gate::forUser, lock the row and validate inside DB::transaction"

requirements-completed: [FI-01]

coverage:
  - id: D1
    description: "The Admin records an income or expense through RecordTransaction; a CZK amount is stored as identity with no rate; a Partner is refused"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#recording a CZK transaction"
        status: pass
    human_judgment: false
  - id: D2
    description: "A foreign amount is converted at the newest CNB list not after the transaction date (weekend, per-100 quote) and stored with the rate, unit amount and list date; a later or corrected list never changes it"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#recording a foreign-currency transaction"
        status: pass
    human_judgment: false
  - id: D3
    description: "A missing CNB list asks for the CZK amount in Czech; a typed figure is stored as manual"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#recording a foreign-currency transaction"
        status: pass
    human_judgment: false
  - id: D4
    description: "Category of the type, client, project of the client and CNB currency are re-validated by every write; an unchanged archived reference is accepted"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#the references of a transaction"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#editing a transaction"
        status: pass
    human_judgment: false
  - id: D5
    description: "UpdateTransaction follows the recompute-versus-manual rule; DeleteTransaction removes the row; both refuse a Partner"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#editing a transaction"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCzkTest.php#deleting a transaction"
        status: pass
    human_judgment: false

# Metrics
duration: 13min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 22: Transaction CZK Rule and Write Actions Summary

**Admin-only RecordTransaction, UpdateTransaction and DeleteTransaction over a single CZK snapshot rule: CNB rate of the transaction date stored with the figure, a typed bank amount kept as sticky manual, one shared input validator**

## Performance

- **Duration:** about 13 min
- **Started:** 2026-10-16T13:35Z (approximate, not recorded at start)
- **Completed:** 2026-10-16T13:50Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 11 (7 created, 4 modified)

## Accomplishments

- A CZK transaction is stored as identity; a foreign one is converted once at the CNB list of its date (Sunday uses Friday's, per-100 quotes use the unit amount) and keeps the rate, unit amount and list date; a list added or corrected later changes nothing.
- A missing list refuses the save with the Czech `czk_required_missing_rate` message on `czk_amount`; a typed figure is stored as manual and is never overwritten by a recomputation.
- `TransactionInput` re-validates every key (type, Prague date, category of the type and not archived, positive amount, CNB currency, client, project of that client, text lengths) and accepts an unchanged archived category, client or project on edit.
- `UpdateTransaction` recomputes only on date, currency or amount change of a computed transaction, keeps a manual figure until `czk_manual` is switched off, and `DeleteTransaction` removes the row under a row lock; every Action refuses a Partner.

## Task Commits

1. **Task 1: Tracer, record a CZK expense, Partner refused** - `5a1be9f` (feat)
2. **Task 2: CNB conversion, snapshot, missing rate, reference checks** - `99d878f` (feat)
3. **Task 3: Update and delete Actions** - `a21e0aa` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

Tracer verified end-to-end (tests, Pint, PHPStan) before expansion.

## Files Created/Modified

- `app/Domain/Finance/Enums/CzkSource.php` - identity, cnb, manual with Czech labels
- `app/Domain/Finance/TransactionInput.php` - shared validator, `currencyOptions()`
- `app/Domain/Finance/TransactionCzk.php` - the CZK snapshot rule
- `app/Domain/Finance/Actions/RecordTransaction.php`, `UpdateTransaction.php`, `DeleteTransaction.php` - Admin-only write paths
- `app/Domain/Finance/Models/Transaction.php` - `czk_source` cast to `CzkSource`, `czk()` accessor
- `lang/cs/enums.php` - `czk_source` labels
- `app/Domain/Shared/Money/Money.php` - docblock only: `convert()` declares the `OverflowException` it already throws
- `tests/Feature/Finance/TransactionCzkTest.php` - 28 tests for the whole plan
- `tests/Feature/Schema/TransactionTablesTest.php` - one assertion now compares with `CzkSource::Identity`

## Decisions Made

See `key-decisions`. In short: one lookup then `convert` so the stored snapshot is exactly the rate used; computed figures are touched only by an input change; manual is sticky; project without client is refused; currency options come from the stored CNB rows.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `Money::convert()` docblock lacked `@throws OverflowException`**
- **Found during:** Task 1 (PHPStan)
- **Issue:** Catching the overflow of a huge converted amount in `TransactionCzk` was reported as a dead catch because the docblock omitted the exception that `fromExactMinor()` already throws.
- **Fix:** Added the `@throws OverflowException` line to `Money::convert()`. No behaviour change.
- **Files modified:** `app/Domain/Shared/Money/Money.php`
- **Verification:** PHPStan clean, full suite green
- **Committed in:** `5a1be9f`

**2. [Rule 1 - Bug] Existing schema test compared `czk_source` with a string**
- **Found during:** Task 1
- **Issue:** The new enum cast made `$transaction->czk_source` a `CzkSource`, so the 09-09 assertion `toBe('identity')` failed.
- **Fix:** Compared with `CzkSource::Identity`.
- **Files modified:** `tests/Feature/Schema/TransactionTablesTest.php`
- **Committed in:** `5a1be9f`

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** Both are direct consequences of the planned cast and rule; no scope creep.

## Issues Encountered

None. The plan's start time was not captured, so the duration is approximated from the commit times.

## Known Stubs

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 09-11 (screen, activity log, history tab) can call the three Actions and `TransactionInput::currencyOptions()`; field errors are keyed by data key (`czk_amount`, `category_id`, `project_id`, ...).
- Plan 09-13 adds the receipt removal inside `DeleteTransaction`.
- Plan 09-20 updates the CONTRIBUTING note that named `toCzk()`.

## Self-Check: PASSED

- Created files exist: CzkSource.php, TransactionInput.php, TransactionCzk.php, RecordTransaction.php, UpdateTransaction.php, DeleteTransaction.php, TransactionCzkTest.php (all present).
- Commits `5a1be9f`, `99d878f`, `a21e0aa` are ancestors of HEAD.
- Acceptance greps of all three tasks pass; plan verification: full `ddev exec vendor/bin/pest` 4244 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
