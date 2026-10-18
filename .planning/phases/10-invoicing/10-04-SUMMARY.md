---
phase: 10-invoicing
plan: 04
subsystem: invoicing-database
tags: [postgres, triggers, immutability, schema, partner-safe-columns]

requires:
  - phase: 03-operations
    provides: "kokpit_guard_frozen_row(), kokpit_refuse_truncate() and the Immutability builders"
  - phase: 06-time-tracking
    provides: "time_entries guard call that must stay byte-identical"
provides:
  - "kokpit_guard_child_of_frozen_parent() SQL function and Immutability::childGuardTriggerSql() / dropChildGuardTriggerSql()"
  - "withUpdatedAt option of Immutability::guardTriggerSql(): false passes the mutable list exactly as given"
  - "invoices table (26 columns, R-04-2) and invoice_items table (R-04-3) with every CHECK, composite key, partial unique index and guard"
  - "Targets for plan 10-06: invoices_id_client_unique, invoices_id_currency_unique, invoice_items_id_invoice_unique"
  - "Pinned Partner-readable column lists of invoices and invoice_items in PartnerSafeColumnsTest"
affects: [10-05, 10-06, 10-08, 10-11, 10-12, 10-14, 10-17, 10-20]

actuals:
  tokens: 16100
  tasks: 3
  commits: 3
plan_head_before: e461c7cb840f96972b3bb2c491aa29a73ec9605f
plan_head_after: 0f3cbdb424d4d7a2ffebec9eff476e4e8cd26626
commits: 3

coverage:
  - deliverable: "An issued invoices row accepts no write (timestamp-only UPDATE, DELETE, TRUNCATE included); its items accept no INSERT, UPDATE or DELETE; drafts stay editable"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#an issued invoice"
        status: pass
    human_judgment: false
  - deliverable: "Storno marker is the issued credit note: cancels_invoice only on a credit note, one per credited invoice, frozen at its own issue"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#the storno marker"
        status: pass
    human_judgment: false
  - deliverable: "Number/state, separate series per kind, issued completeness, signs, totals, VAT, same-client links, one invoice per proforma"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#number, state and series / links between documents / totals, signs and VAT / what an issued document must carry"
        status: pass
    human_judgment: false
  - deliverable: "Ordinary and converted line totals pinned by CHECK (half away from zero; converted line bounded by half a minor unit per unit of quantity plus half)"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#the lines"
        status: pass
    human_judgment: false
  - deliverable: "Guards cannot silently gain a writable column; Partner-readable column lists pinned"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#the guards of the invoice tables; tests/Isolation/PartnerSafeColumnsTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Child-of-frozen-parent trigger: parent looked up by id with FOR SHARE, missing parent counts as open, exact always-mutable list (updated_at never implied)"
    - "Frozen guard with an empty mutable list and withUpdatedAt: false: a row that accepts no write at all after the state flip"
    - "Trigger arguments asserted from pg_trigger.tgargs (hex-decoded) so a later migration cannot add a writable column unnoticed"

key-files:
  created:
    - database/migrations/2026_10_17_000100_create_kokpit_child_guard_functions.php
    - database/migrations/2026_10_17_000200_create_invoices_tables.php
    - tests/Feature/Schema/InvoiceTablesTest.php
  modified:
    - app/Domain/Shared/Database/Immutability.php
    - tests/Unit/Database/ImmutabilityTest.php
    - tests/Isolation/PartnerSafeColumnsTest.php

key-decisions:
  - "R-04-6 enforced in the database: guardTriggerSql('invoices', 'state', 'draft', [], withUpdatedAt: false); the state flip is the last write of the issue pipeline on the invoices row"
  - "The child guard takes a share lock on the parent row, so an issue statement waits for a running item write and an item write after the issue sees the frozen state"
  - "issued_at is stored but not required by a CHECK (R-04-2 lists it as nullable and D-12 does not list it among the issued fields); the issue pipeline sets it"
  - "invoices_variable_symbol_check ties the symbol to the document: present exactly on an issued invoice or proforma, one to ten digits"
  - "Keys use NO ACTION so every refusal answers 23503; a draft is deleted after its items by plan 10-11"

patterns-established:
  - "Mutation self-check for guards: drop or re-create the trigger inside the test and show the same assertion now fails with 'was accepted'"

requirements-completed: [IN-01, IN-03, IN-08, IN-09]

duration: 12 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 04: Invoice tables and immutability guards Summary

**Partner-readable invoices and invoice_items tables whose issued rows accept no write at all (timestamp-only included), enforced by a new child-of-frozen-parent trigger and an exact-list frozen guard, with every consistency rule as a CHECK, partial unique index or composite key and proven with raw SQL.**

## Performance

- **Duration:** 12 min
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files:** 3 created, 3 modified

## Accomplishments

- `kokpit_guard_child_of_frozen_parent()` and `Immutability::childGuardTriggerSql()`: INSERT, UPDATE and DELETE of an item of a non-draft document are refused with KP001, a move between parents checks both, and `updated_at` is never implied.
- `Immutability::guardTriggerSql(..., withUpdatedAt: false)`; the default output of existing callers (`time_entries`) is byte-identical, pinned by a unit test.
- `invoices` (26 columns) and `invoice_items` with: kind/state/language/VAT-mode/currency checks, `(state = 'draft') = (number IS NULL)`, `UNIQUE (kind, number) WHERE number IS NOT NULL`, issued completeness (credit note exempt from due date, bank snapshot and variable symbol), sign and totals checks, VAT-zero for non-payers, same-client composite FKs for proforma and credited invoice, one invoice per proforma, one storno per invoice, and the ordinary/converted line-total CASE check.
- Tracer proof: a raw-SQL issued invoice refuses UPDATE (including `updated_at` only), DELETE, TRUNCATE; its items refuse INSERT, UPDATE, DELETE; two mutation self-checks show the assertions fail without the child guard or with the implied timestamp.
- Guard presence and the empty mutable-column arguments are asserted from `pg_trigger.tgargs`; both Partner-readable column lists and the suspicious-name regex are pinned.

## Task Commits

1. **Task 1 (tracer): frozen invoices, child guard, tables** - `c0faac0` (feat)
2. **Task 2: consistency CHECKs, series, links, line totals** - `96aec42` (feat)
3. **Task 3: guard pins and Partner column pins** - `0f3cbdb` (test)

## Verification

- `ddev exec vendor/bin/pest` (whole suite): 5072 passed, 3 skipped, 0 failed.
- `pest tests/Feature/Schema tests/Unit/Database tests/Feature/Database tests/Isolation/PartnerSafeColumnsTest.php`: 488 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set; all task acceptance criteria re-run and passing.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Self-referencing foreign key of invoice_items**
- **Found during:** Task 1
- **Issue:** `foreignUuid('credited_item_id')->constrained('invoice_items')` inside `Schema::create` was emitted before the primary key existed (SQLSTATE 42830) and broke every migration.
- **Fix:** plain nullable uuid column, then `invoice_items_credited_item_id_foreign` added by `DB::statement` after the table and its keys exist; same NO ACTION behaviour, same constraint name.
- **Files modified:** database/migrations/2026_10_17_000200_create_invoices_tables.php
- **Commit:** c0faac0

**2. [Rule 2 - Critical hardening] Share lock on the parent row in the child guard**
- **Found during:** Task 1 design
- **Issue:** without a lock, an item write could race with the state flip of the document and slip an edit under an issued document.
- **Fix:** the parent lookup uses `FOR SHARE`; the issue statement waits for a running item write, and a write after the issue sees `issued`.
- **Commit:** c0faac0

**3. [Plan wording] Eleven-digit variable symbol answers SQLSTATE 22001**
- **Issue:** the plan says an eleven-digit symbol is "refused"; with `varchar(10)` (R-04-2) the column length answers before the CHECK, so the state is 22001, not 23514. The test pins 22001 with a comment; `'AB'`, `'12A'` and the empty string answer 23514.

### Small additions

- Partial index `invoice_items_credited_item_id_index` (FK lookup of credit lines) and `invoice_items_position_check` (position >= 1, R-04-3).

**Total deviations:** 2 auto-fixed (1 blocking, 1 hardening), 1 wording note, 2 small additions. **Impact:** none on the plan's contracts; every constraint and trigger name of the artifact table exists.

## Known Stubs

None.

## Threat Flags

None. The plan's register (T-10-08, T-10-09, T-10-10, T-10-13, T-10-80) is mitigated by the guards, constraints and pins above; no new endpoint, auth path or file access was added.

## Notes for later plans

- The state flip is the last write of the issue pipeline on the `invoices` row (plans 10-12, 10-14, 10-17, 10-20); nothing may touch `invoices.updated_at` afterwards, so no model declares `$touches` toward `Invoice` (plan 10-08).
- Items of a draft are rewritten while the parent is still `draft`; `UNIQUE (invoice_id, position)` is immediate, so a reorder must go through a free position or delete-and-insert.
- The credited invoice of a credit note is guaranteed to be of the same client, not to be of kind `invoice`; the Action of plan 10-17 and later must check the kind.

## Next Phase Readiness

Ready for plan 10-05 and 10-06 (Admin-only tables can reference `invoices (id, client_id)`, `(id, currency)` and `invoice_items (id, invoice_id)`).

## Self-Check: PASSED

Created files exist on disk (both migrations, `InvoiceTablesTest.php`); commits `c0faac0`, `96aec42`, `0f3cbdb` are ancestors of HEAD; commit count measured from the plan ledger is 3.
