---
phase: 09-documents-and-finance
plan: 04
subsystem: finance
tags: [finance, categories, postgres, composite-fk, immutability-guard, partner-isolation, canary-harness]

# Dependency graph
requires:
  - phase: 02-platform-conventions-isolation
    provides: DeniesPartners, AdminOnlyPolicy, canary harness, kokpit_guard_frozen_row and Immutability builders, schema convention rules
  - phase: 05-tasks
    provides: the tasks_parent_fk composite key technique and SortableTrait configuration
provides:
  - transaction_categories table (two levels, typed, composite keys, unique names, system freeze guard)
  - seeded system income category invoice_payments (target of Phase 10 payment booking) and a generic Czech starter set
  - TransactionCategory model (Admin-only, sortable within type and parent, selectable scope) and TransactionType enum
  - UNIQUE (id, type) key transaction_categories_id_type_unique as the target of the transactions FK in 09-09
affects: [09-06 category screen, 09-09 transactions, 09-21 schema proofs, Phase 10 invoice payments]

actuals:
  tokens: 8700
  tasks: 3
  commits: 3
plan_head_before: 6f4c1956fbf891db9febe4a614788380f552b15d
plan_head_after: fe0fd8ae9605be0a16b0c6bfe773874f0d43b66f
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Typed two-level tree as a composite FK over a stored generated discriminator (parent_id, type, parent_depth) -> (id, type, depth), NO ACTION so every refusal is 23503"
    - "System row frozen by Immutability::guardTriggerSql with is_system as the state column, name and position left mutable"
    - "Idempotent data migration seeding keyed by system_key and by the unique name of each level"
    - "Sortable scoped to siblings of one type and parent through buildSortQuery()"

key-files:
  created:
    - database/migrations/2026_10_16_000200_create_transaction_categories_table.php
    - database/migrations/2026_10_16_000300_seed_transaction_categories.php
    - app/Domain/Finance/Models/TransactionCategory.php
    - app/Domain/Finance/Enums/TransactionType.php
    - tests/Feature/Schema/TransactionCategoriesTableTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - lang/cs/enums.php
    - tests/Support/PgSchema.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "The whole table artifact (all CHECKs, both composite keys, partial unique indexes and the guard trigger) lands in the Task 1 migration; Task 2 and 3 are the raw-SQL proofs plus the selectable scope"
  - "Seeding uses bare ON CONFLICT DO NOTHING for the starter rows and ON CONFLICT (system_key) for the system row, so a rerun adds nothing and never overwrites an Admin rename"
  - "TransactionCategory sorts on creation (sort_when_creating true) within siblings of the same type and parent, unlike Task which appends under a lock"
  - "scopeSelectable checks the parent through a correlated EXISTS on the same table, so an archived parent hides its children without a relation load"

patterns-established:
  - "Category key target for transactions: FOREIGN KEY (category_id, type) REFERENCES transaction_categories (id, type)"
  - "Admin-only finance model registration set: morph alias, AdminOnlyPolicy, ModelDeclarationTest lists, CanaryRegistry fixture and CanaryRegistryTest list, PgSchema exemptions for composite-key discriminators"

requirements-completed: [FI-01]

coverage:
  - id: D1
    description: "A fresh install has exactly one system income root with system_key invoice_payments, readable by the Admin through TransactionCategory::invoicePayments()"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#it gives the Admin exactly one system income root with the invoice_payments key"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner reads no category row and is granted no ability; the canary fixture shows zero rows to Partner A"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#Partner visibility"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#it shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model"
        status: pass
    human_judgment: false
  - id: D3
    description: "The database refuses a third level, a child of the other type, a type change of a parent with children, duplicate names (case-insensitive) and blank names, and the deletion of a parent with children"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#the two-level tree and the inherited type"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#unique and non-blank names"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#deleting"
        status: pass
    human_judgment: false
  - id: D4
    description: "The system category can be renamed and reordered but archiving, moving, retyping, unflagging, clearing the key and deleting it answer KP001"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#the system category freeze (KP001)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The generic Czech starter set exists with the right types, parents and sibling positions, and rerunning the seed migration adds nothing"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#the starter set"
        status: pass
    human_judgment: false
  - id: D6
    description: "selectable(type) offers only active categories of that type and hides children of an archived parent"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TransactionCategoriesTableTest.php#selectable categories"
        status: pass
    human_judgment: false

# Metrics
duration: 11min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 04: Transaction Categories Summary

**Two-level typed finance categories enforced by composite foreign keys and a frozen, seeded system income category (invoice_payments) for Phase 10, Admin-only under every convention**

## Performance

- **Duration:** about 11 min (11:39Z to 11:50Z)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 13 (5 created, 8 modified)

## Accomplishments

- `transaction_categories` table: a root has a type, a child inherits it through `(parent_id, type, parent_depth) -> (id, type, depth)`, a third level and a type mismatch answer 23503, a parent with children cannot be deleted or retyped (NO ACTION). Root names are unique per type and child names unique under the parent, case-insensitive; blank names, a stray system key and a non-root system row are refused by CHECKs.
- The system income category `Úhrady faktur` (`invoice_payments`) is seeded by a data migration and frozen by `kokpit_guard_frozen_row` with `is_system` as the state column: only name, position and updated_at may change, any delete or other change answers KP001.
- Generic Czech starter set (income: Ostatní příjmy; expense: Software with Předplatné and Hosting, Poplatky with Bankovní poplatky, Daně a odvody with Daň z příjmů and Pojištění, Kancelář, Ostatní výdaje) with sibling positions 1..n; the seed is idempotent.
- `TransactionCategory` (final, Admin-only via DeniesPartners, sortable within type and parent, `invoicePayments()`, `selectable(TransactionType)`, `isArchived()`) and `TransactionType` with Czech labels.
- Registered under every convention: morph alias `transaction_category`, `AdminOnlyPolicy`, `ModelDeclarationTest`, canary fixture (root expense category named with the canary) and `CanaryRegistryTest`, R1 exemptions for `type` and `parent_depth`.

## Task Commits

1. **Task 1: Tracer - the Admin reads the seeded system income category, a Partner reads no category** - `ab81fb1` (feat)
2. **Task 2: The database refuses a third level, a type mismatch, duplicate names and the deletion of a parent** - `8476bf1` (test, TDD)
3. **Task 3: The system category is frozen except its name and order, and the starter set is generic and complete** - `fe0fd8a` (feat, TDD)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the tracer's targeted tests, Pint and PHPStan were re-run end to end (green) before expansion.

## Files Created/Modified

- `database/migrations/2026_10_16_000200_create_transaction_categories_table.php` - table, CHECKs, composite keys, partial unique name indexes, guard trigger
- `database/migrations/2026_10_16_000300_seed_transaction_categories.php` - idempotent system category and starter set
- `app/Domain/Finance/Models/TransactionCategory.php`, `app/Domain/Finance/Enums/TransactionType.php` - model and enum
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - alias and Admin-only policy
- `tests/Arch/ModelDeclarationTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php` - convention registrations and canary fixture
- `lang/cs/enums.php` - `transaction_type` labels
- `tests/Support/PgSchema.php`, `tests/Feature/Schema/SchemaConventionsTest.php` - R1 exemptions and the pinned exempt list
- `tests/Feature/Schema/TransactionCategoriesTableTest.php` - 31 cases: seed, Partner visibility, tree, names, system columns, freeze, starter set, selectable

## Decisions Made

- Built the complete table artifact in the Task 1 migration (the plan's artifact table is one unit), so Tasks 2 and 3 added proofs and the scope rather than schema changes.
- Bare `ON CONFLICT DO NOTHING` for starter rows, `ON CONFLICT (system_key)` for the system row; a rerun never overwrites an Admin rename. Limitation: a starter root the Admin has renamed away would be recreated if the migration were run again (it runs once per database, so this is theoretical).
- New categories sort to the end of their siblings automatically (`sort_when_creating` true), since there is no board lock to coordinate with.

## Deviations from Plan

None - plan executed exactly as written.

TDD note: in Task 2 the behaviours already held after Task 1, because the plan's artifact table is delivered whole in the Task 1 migration; the proofs were written and pass without exposing a defect. The Task 3 selectable and isArchived tests were observed red first (BadMethodCallException) before the implementation. Two test-writing slips fixed before commit: the freeze rename test needed an acting Admin, and a rejected statement inside the model delete test had to run through `RawSql::expectSqlState` so the aborted transaction did not poison the following assertion.

**Total deviations:** 0 auto-fixed.

## Issues Encountered

- PHPStan flagged an always-true `instanceof` in `buildSortQuery()` (the cast already yields the enum); simplified to `$this->type->value` before the first commit.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - the new surface is the Admin-only table covered by T-09-14 to T-09-16 of the plan (Partner zero-row test and canary, composite keys and partial unique indexes with raw-SQL proofs, KP001 guard on the system row).

## Next Phase Readiness

- Plan 09-09 can point `transactions (category_id, type)` at `transaction_categories_id_type_unique` and add the restrict FK that refuses deleting a used category.
- Plan 09-06 can build the category screen on `selectable()`, `isArchived()`, `children()` and the sortable positions; archiving the system row is refused by the database, so the screen should hide that action for `is_system` rows.
- Plan 09-21 owns the cross-table schema proofs; the category proofs are already complete here.

## Self-Check: PASSED

- Created files exist on disk (both migrations, TransactionCategory, TransactionType, TransactionCategoriesTableTest).
- Commits `ab81fb1`, `8476bf1`, `fe0fd8a` are ancestors of HEAD; `git rev-list --count 6f4c195..HEAD` is 3 before this SUMMARY.
- Full suite `ddev exec vendor/bin/pest`: 4034 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
