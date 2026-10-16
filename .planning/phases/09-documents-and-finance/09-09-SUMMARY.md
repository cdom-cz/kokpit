---
phase: 09-documents-and-finance
plan: 09
subsystem: finance
tags: [finance, transactions, postgres, composite-fk, check-constraints, czk-snapshot, partner-isolation, canary-harness]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: transaction_categories_id_type_unique (09-04) and projects_id_client_unique (Phase 6, reused by 09-01)
  - phase: 02-platform-conventions-isolation
    provides: DeniesPartners, AdminOnlyPolicy, canary harness, schema convention rules
  - phase: 08-time-reports-and-exchange-rates
    provides: MoneyCast column-pair convention and the CNB rate snapshot rule (EX-03)
provides:
  - transactions table (typed, positive amount pair, CZK figure with source, CNB rate snapshot, optional client and project)
  - Transaction model (Admin-only) registered under every convention
  - composite keys that keep the category of the transaction type and the project of the chosen client
affects: [09-11 transaction screen, 09-13 receipts, 09-15 finance overview, 09-22 CZK rule and write Actions, Phase 10 invoice link]

actuals:
  tokens: 9060
  tasks: 2
  commits: 2
plan_head_before: ef8091a300796ecc12cc21285085ea7581322da7
plan_head_after: 544a83c674de504f5336db00f63d92ce90ad0874
commits: 2

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Composite FK (category_id, type) -> (id, type) so a transaction can only take a category of its own type"
    - "Composite FK (project_id, client_id) -> (id, client_id) plus a CHECK that a project needs a client (the composite key is skipped when client_id is null)"
    - "CZK figure with a source column (identity, cnb, manual) and a CHECK-enforced snapshot that exists only for cnb"
    - "Raw-SQL proof helpers insert with database-default id so the uuidv7() default is exercised"

key-files:
  created:
    - database/migrations/2026_10_16_000400_create_transactions_table.php
    - app/Domain/Finance/Models/Transaction.php
    - tests/Feature/Schema/TransactionTablesTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/PgSchema.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "The whole table artifact (all CHECKs, both composite keys, indexes) lands in the Task 1 migration; Task 2 is the raw-SQL proofs, so it exposed no schema defect"
  - "An extra index on project_id was added next to the three planned indexes so the project key side is indexed and the project deletion check does not scan the table"
  - "client() and project() relations use withTrashed() so a transaction still shows its client or project after archiving"
  - "The canary fixture is placed after the TransactionCategory fixture (which itself follows Project) and looks up the seeded Software category rather than the canary category"

patterns-established:
  - "Admin-only finance model registration set repeated from 09-04: morph alias, AdminOnlyPolicy, ModelDeclarationTest lists, CanaryRegistry fixture, CanaryRegistryTest list, PgSchema exemption for the composite-key type column"

requirements-completed: [FI-01]

coverage:
  - id: D1
    description: "A transaction is stored with its type, date, category, amount, CZK figure and source, and the Admin reads the amount back as Money"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#it stores a CZK expense with its CZK amount and gives the Admin the amount as Money"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner reads no transaction row and the Gate grants a Partner no ability; the canary harness covers the model on every run"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#Partner visibility"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#it shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model"
        status: pass
    human_judgment: false
  - id: D3
    description: "The database refuses a category of the other type, a project of another client, a project without a client, a non-positive amount, a malformed currency, an inconsistent CZK snapshot and an unknown source"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#type, category and amount"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#the CZK figure and its snapshot"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#client and project"
        status: pass
    human_judgment: false
  - id: D4
    description: "Deleting a category, client or project that a transaction references is refused with 23503"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionTablesTest.php#deleting what a transaction references"
        status: pass
    human_judgment: false

# Metrics
duration: 12min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 09: Transactions Table Summary

**Admin-only `transactions` table with a CZK figure, its source and CNB rate snapshot, guarded by composite foreign keys and CHECKs that no writer can bypass, and the `Transaction` model registered under every convention**

## Performance

- **Duration:** about 12 min (12:52Z to 13:01Z, most of it the full-suite run)
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files modified:** 10 (3 created, 7 modified)

## Accomplishments

- `transactions` table per the plan's artifact contract: positive `amount_minor` with an ISO `amount_currency`, `czk_minor` (zero allowed for tiny foreign amounts), `czk_source` (identity, cnb, manual), the three CNB snapshot columns, free-text counterparty, document number, description (at most 2000 characters), optional client and project.
- CHECKs enforce the CZK rule at the storage level: a CZK row must be identity and equal in amount, a foreign row can never be identity, and the snapshot (rate, unit amount, rate date, all positive) exists exactly when the source is cnb.
- Composite FKs: `(category_id, type)` -> `transaction_categories (id, type)` and `(project_id, client_id)` -> `projects (id, client_id)`, both NO ACTION, so a category of the other type, a project of another client and the deletion of a used category, client or project all answer 23503. `transactions_project_needs_client_check` closes the gap where the composite key is skipped for a null client.
- `Transaction` model (final, `KokpitModel`, `PartnerIsolated`, `DeniesPartners`): only description, counterparty and document number are fillable, `amount` is Money over the column pair, `exchange_rate` reads as a ten-place decimal string, relations to category, client and project (archived rows included).
- Registered under every convention: morph alias `transaction`, `AdminOnlyPolicy`, `ModelDeclarationTest` lists, a canary fixture (expense in the seeded Software category, identity CZK amount, canary in counterparty and description) with its `CanaryRegistryTest` entry, and the R1 exemption `transactions.type` with the pinned list updated.
- 32 schema tests in `TransactionTablesTest` cover the storage round trip, Partner invisibility, and every refusal listed in the plan's behavior section.

## Task Commits

1. **Task 1: Tracer - a CZK expense row is stored with its CZK amount and read back by the Admin only, under every convention** - `62bd85b` (feat)
2. **Task 2: The database refuses an inconsistent transaction** - `544a83c` (test, TDD)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the targeted tests, Pint and PHPStan were green end to end before expansion.

## Files Created/Modified

- `database/migrations/2026_10_16_000400_create_transactions_table.php` - table, CHECKs, composite keys, indexes, header docblock
- `app/Domain/Finance/Models/Transaction.php` - the Admin-only model
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - alias `transaction` and the Admin-only policy
- `tests/Arch/ModelDeclarationTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php` - convention registrations and canary fixture
- `tests/Support/PgSchema.php`, `tests/Feature/Schema/SchemaConventionsTest.php` - R1 exemption for `transactions.type` and the pinned exempt list
- `tests/Feature/Schema/TransactionTablesTest.php` - 32 cases: round trip, Partner visibility, every database refusal

## Decisions Made

- Built the complete migration (all CHECKs, both composite keys, indexes) in Task 1, because the plan's artifact table is one unit and the arch, schema and canary suites need the finished table with the model. Task 2 therefore added proofs and no schema change.
- Added an index on `project_id` in addition to the three indexes of the artifact table. The composite project key has `project_id` first, but no index led with it; the index keeps the reverse lookup and the restrict check on project deletion off a sequential scan.
- `czk_source` stays a plain string attribute as planned; the `CzkSource` enum, cast and `czk()` come with plan 09-22, so `lang/cs/enums.php` is not touched here.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Index on project_id**
- **Found during:** Task 1 (migration)
- **Issue:** The artifact table lists indexes on `(transaction_date, id)`, `(client_id, transaction_date)` and `(category_id)`, none of which leads with `project_id`, the referencing side of the composite project key; deleting a project would scan the table to check for referencing transactions.
- **Fix:** Added `transactions_project_id_index`.
- **Files modified:** database/migrations/2026_10_16_000400_create_transactions_table.php
- **Verification:** schema, arch and full suite green
- **Committed in:** 62bd85b (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** A single extra index, no behavior change and no scope creep.

TDD note: in Task 2 the refusals already held after Task 1 because the migration was delivered whole in Task 1; the proofs were written and pass without exposing a defect (the same pattern as plan 09-04). Every refusal runs through `RawSql::expectSqlState`, which fails if the statement is accepted, so the tests cannot pass vacuously.

## Issues Encountered

None.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - the new surface is the Admin-only table covered by T-09-30 (DeniesPartners, AdminOnlyPolicy, canary fixture, zero-row and Gate refusal tests) and T-09-32 (composite FKs and CHECKs with raw-SQL proofs) of the plan. T-09-SC: no change to `composer.json` or `composer.lock`.

## Next Phase Readiness

- Plan 09-22 can add the `CzkSource` enum, the cast and `czk()` over `czk_source`, the CZK conversion rule, `TransactionInput` and the record, update and delete Actions on top of a table that already refuses inconsistent rows.
- Plan 09-06's category in_use and type_locked messages now have the real FK behind them; 09-22 may add pre-checks that reuse the existing `transactions.errors.*` strings.
- Plan 09-11 adds activity logging and the screen; the model has no `LogsAllowlistedActivity` yet by design (D-14).

## Self-Check: PASSED

- Created files exist on disk (migration, Transaction, TransactionTablesTest).
- Commits `62bd85b` and `544a83c` are ancestors of HEAD; `git rev-list --count ef8091a..HEAD` is 2 before this SUMMARY.
- Full suite `ddev exec vendor/bin/pest`: 4177 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
