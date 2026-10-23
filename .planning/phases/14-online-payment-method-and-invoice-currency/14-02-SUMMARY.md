---
phase: 14-online-payment-method-and-invoice-currency
plan: 02
subsystem: invoicing
tags: [postgres, triggers, check-constraints, payment-method, card-switch, pay-token, migrations]

requires:
  - phase: 10.1-invoice-lock
    provides: kokpit_guard_invoice_row(), kokpit_guard_invoice_admin_issued(), the lock_state model
  - phase: 11-card-payments
    provides: invoice_admin.card_payment_enabled and pay_token
  - phase: 14-online-payment-method-and-invoice-currency (plan 01)
    provides: stable UUID v7 ids of the supplier bank accounts (bank.accounts setting)
provides:
  - invoices.payment_method (transfer or online, NULL for a credit note), defaulted to transfer by the row guard, open until the lock
  - invoices_issued_fields_check amended (OA-7): bank snapshot required for an issued transfer document, forbidden for an online one
  - invoice_admin.bank_account_id (uuid, no foreign key), Admin-only, frozen by the lock
  - open card switch and set-once pay token on an open issued document (OA-1), format-only pay token check
  - App\Domain\Invoicing\Enums\DocumentPaymentMethod with Czech labels, Invoice cast and logged attribute
affects: [14-03, 14-04, 14-07, 14-09, 14-10, 14-11]

plan_head_before: fa6538dddefd47a92626f72cec21f09e46814a2e
plan_head_after: 9ac3c6673d1c38b277e97b8e8f03c672d345be01

actuals:
  tokens: 15400
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Default in the row guard, not as a column default: a credit note stays NULL and raw-SQL writers stay valid"
    - "Backfill under a guarded table: disable only the one row trigger around the single UPDATE, re-enable at once"
    - "A migration that re-creates a guard function restores the previous body verbatim in down(), before dropping the column the new body names"

key-files:
  created:
    - database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php
    - database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
    - app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
  modified:
    - lang/cs/enums.php
    - app/Domain/Invoicing/Models/Invoice.php
    - app/Domain/Invoicing/Models/InvoiceAdmin.php
    - tests/Feature/Schema/InvoiceTablesTest.php
    - tests/Feature/Schema/InvoiceLockGuardTest.php
    - tests/Feature/Schema/InvoiceAdminTablesTest.php
    - tests/Isolation/PartnerSafeColumnsTest.php
    - tests/Arch/InvoiceUnlockPathTest.php
    - tests/Feature/Payments/CardSwitchTest.php

key-decisions:
  - "R-14-02-2: payment_method joins open_columns right after bank_snapshot; the INSERT branch sets 'transfer' for a non-credit-note with no method; every other branch and refusal text of the Phase 10.1 function is verbatim"
  - "R-14-02-3 / OA-7: invoices_issued_fields_check requires (payment_method = 'online') = (bank_snapshot IS NULL) for an issued invoice or proforma"
  - "R-14-02-5 / OA-1: a first pay token and card switch changes are allowed on an open issued document, a set token never changes; invoice_admin_pay_token_check is format-only so a printed pay URL outlives a switch-off"
  - "bank_account_id is not on the InvoiceAdmin history allowlist (the account change of an issued document is audited by the bank fingerprint)"

patterns-established:
  - "Migration objects loaded with require base_path(...) inside a test to prove backfill, round trip and refusing down()"

requirements-completed: [P14-01, P14-06]

coverage:
  - id: D1
    description: "An invoice or proforma has one payment method (transfer default); a credit note has none; the database refuses any other value and a credit note with a method"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#defaults a raw-inserted invoice or proforma draft to transfer, whoever writes it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#keeps a raw-inserted credit note without a method and refuses a method on it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#keeps an explicit method on insert and refuses any value but transfer and online"
        status: pass
    human_judgment: false
  - id: D2
    description: "An issued online document carries no bank snapshot and an issued transfer document must carry one (OA-7)"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#lets an issued online invoice or proforma carry no bank snapshot and refuses one with a snapshot (OA-7)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceTablesTest.php#requires the bank snapshot of an issued transfer invoice or proforma (OA-7)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The method is writable while the document is open and refused with KP001 once it is locked; the open list is pinned"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#reads transfer on an issued invoice, and accepts a raw switch to online without the bank snapshot while the document is open"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#refuses the same switch with KP001 once the document is locked, and a method change that keeps the snapshot"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#pins the open column list inside the invoice row guard exactly"
        status: pass
    human_judgment: false
  - id: D4
    description: "On an open issued document the card switch moves both ways and a first token may be set; a set token never changes or disappears; the lock freezes switch, token and bank account id"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceAdminTablesTest.php#takes a card switch change, a first pay token and a bank account id on an open issued document (OA-1, D-03, D-05)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceAdminTablesTest.php#never changes or removes a set pay token of an issued document, open or locked, and keeps it after a switch-off"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceAdminTablesTest.php#freezes the switch, a first token and the bank account id of a locked issued document (T-14-08)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Payments/CardSwitchTest.php#lets the switch move on an open issued document, never changes a set token, and freezes both once locked"
        status: pass
    human_judgment: false
  - id: D5
    description: "Existing documents are backfilled as transfer without touching updated_at (locked rows included); both migrations round-trip; down() refuses where it would lose data"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#backfills every invoice and proforma as transfer, a credit note as null, without touching updated_at, locked rows included"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#rolls both migrations back and forward again without leaving the old checks or the new column behind"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#refuses to roll the method migration back while an online document exists, and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php#refuses to roll the account migration back while a token has its switch off, and changes nothing"
        status: pass
    human_judgment: false

duration: 40min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 02: Payment method and open card switch in the database Summary

**Every invoice and proforma now carries a transfer or online payment method in PostgreSQL (credit notes none), an issued online document has no bank snapshot, and the method, card switch, a first pay token and the chosen account id are writable on an open issued document and frozen by the lock.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 12 (3 created, 9 modified), plus this SUMMARY

## Accomplishments

- `invoices.payment_method` with `invoices_payment_method_check` and `invoices_payment_method_kind_check`; the row guard defaults a non-credit-note to `transfer` on insert and lists the column among the open columns (and nothing else is added to that list).
- `invoices_issued_fields_check` re-added under the same name: `(payment_method = 'online') = (bank_snapshot IS NULL)` for an issued invoice or proforma (OA-7); the due date stays required for both.
- Backfill of every existing invoice and proforma as `transfer` with only `invoices_row_guard` disabled around the one UPDATE; `updated_at` untouched, locked rows included.
- `invoice_admin.bank_account_id` (uuid, no foreign key, column comment); `kokpit_guard_invoice_admin_issued()` now allows a card switch change and a first token on an open issued document and refuses any change of a set token, a delete and a change of `invoice_id`; the unchanged child guard freezes all three columns under a lock.
- `invoice_admin_pay_token_check` is format-only, so a token outlives a switch-off.
- `DocumentPaymentMethod` enum with Czech labels; `Invoice` casts, documents and logs the method; `InvoiceAdmin` documents `bank_account_id` (not logged).
- Tests moved to the new rules in the same commits: schema, lock guard (pinned open list, tracer, backfill, round trip, refusing `down()`), Partner-safe column pin with the review sentence, unlock-path allowlist, card switch.

## Task Commits

1. **Task 1 (tracer): payment method, issued-fields carve-out, backfill, enum, model** - `0a304e0` (feat)
2. **Task 2: open card switch, set-once token, bank account id, rewritten freeze tests** - `9ac3c66` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the verify was re-run end to end after Task 1 (7 test files, Pint, PHPStan) and passed before the expansion; the wider suite (2068 tests) was green on the Task 1 commit as well.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Name scan in PartnerSafeColumnsTest rejected the reviewed column**
- **Found during:** Task 1
- **Issue:** the test "has no invoices or invoice_items column whose name looks like ... a payment ..." matched `payment_method`, which the plan pins as reviewed Partner-safe.
- **Fix:** a `$reviewed` map with the single exception `invoices.payment_method` and a comment; any other `payment` column still fails.
- **Files modified:** tests/Isolation/PartnerSafeColumnsTest.php
- **Commit:** 0a304e0

**2. [Rule 3 - Blocking] Phase 10.1 migration tests had to roll Phase 14 back first**
- **Found during:** Task 1 and Task 2
- **Issue:** the two existing tests in the "the migration" block of InvoiceLockGuardTest call `down()` of `2026_10_22_000100_add_lock_state_to_invoices.php` and write documents in the old shape; with `payment_method` and its kind check present, a non-credit-note without a method (the generic guard does not default it) is refused, and the Phase 10.1 `up()` would overwrite the new guard bodies.
- **Fix:** both tests roll `000300` and `000200` back before the Phase 10.1 `down()` and bring them forward again after its `up()`.
- **Files modified:** tests/Feature/Schema/InvoiceLockGuardTest.php
- **Commit:** 0a304e0 (000200), 9ac3c66 (000300)

**Other tests migrated to the open/locked split:** besides the cases named in the plan, the test "takes the CNB figures while the document is open, and never changes its token, card switch or document" in InvoiceAdminTablesTest changed (its token and switch assertions moved to the new open-document tests; the document and delete assertions stayed). No further test outside the plan's list needed a change.

**Total deviations:** 2 (1 test-expectation fix, 1 blocking test sequencing). **Impact:** none on scope or behavior.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch` - 2076 passed
- `ddev exec vendor/bin/pest tests/Unit/Database tests/Feature/Documents/InvoicePdfDeletionTest.php tests/Concurrency/InvoiceEditSendConcurrencyTest.php tests/Concurrency/InvoiceIssueConcurrencyTest.php` - 55 passed (other readers of `invoice_admin`)
- Task 1 verify files (InvoiceTablesTest, InvoiceLockGuardTest, PartnerSafeColumnsTest, InvoiceUnlockPathTest, EnumLabelsTest, ActivityAllowlistColumnsTest, ActivityAllowlistTest) - 161 passed
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (`function.alreadyNarrowedType`, logged in `deferred-items.md`); no error in any file of this plan
- `scripts/check-sensitive.sh` clean on both commits; lefthook sensitive-content and gitleaks hooks passed
- All acceptance criteria of both tasks pass

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. The register's mitigations are in place: T-14-04 (method in `open_columns`, KP001 on a locked row, pinned list), T-14-05 (amended issued-fields check, both directions tested), T-14-06 (set token never changes, open or locked), T-14-07 (`bank_account_id` and token on the Admin-only row; only `payment_method` joins the Partner-readable row and is pinned and reviewed), T-14-08 (child guard unchanged, locked-row tests for switch, token and account), T-14-SC (no composer change).

## Next Phase Readiness

The database now accepts an online document and an open switch; no application code writes `payment_method` or `bank_account_id` yet. A document created through Eloquent reads `payment_method` as null in memory until it is refreshed, because the default is set by the trigger; plans 14-04, 14-07 and 14-09 (the `DocumentPayment` writers) set it explicitly or refresh. Plan 14-11 migrates the development database, which includes both migrations of this plan.

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php
- FOUND: database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
- FOUND: app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
- FOUND commits: 0a304e0, 9ac3c66 (both ancestors of HEAD)
