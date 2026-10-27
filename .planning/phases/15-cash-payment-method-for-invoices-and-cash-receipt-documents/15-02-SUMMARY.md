---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 02
subsystem: database
tags: [postgres, migration, plpgsql, guard-trigger, cash-receipt, ppd, partner-safe]
requires:
  - phase: 10-invoicing
    provides: invoices (id, client_id) key, invoice_payments (id, invoice_id) key, documents, the PDF link guard pattern
  - phase: 10.1-invoice-lock
    provides: lock_state model and the sanctioned-unlock setting pattern
provides:
  - table cash_receipts (20 columns, R-15-02-1) with FKs, checks, unique number and unique payment
  - functions kokpit_guard_cash_receipt_row() and kokpit_guard_cash_receipt_pdf_link(), triggers cash_receipts_row_guard and cash_receipts_pdf_link_guard, truncate guard
  - transaction-local setting name kokpit.cash_receipt_unlock (only UnlockCashReceipt, plan 15-13, may name it)
  - Partner-safe column pin for cash_receipts
affects: [15-06, 15-08, 15-09, 15-10, 15-11, 15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "composite FK ON DELETE SET NULL (column) plus CHECK to make a parent undeletable while the child is valid"
    - "row guard built on to_jsonb(NEW) - column list diffs, one list per lifecycle state"
key-files:
  created:
    - database/migrations/2026_10_27_000200_create_cash_receipts_table.php
    - tests/Feature/Schema/CashReceiptsTableTest.php
  modified:
    - tests/Isolation/PartnerSafeColumnsTest.php
key-decisions:
  - "PPD table shape: single-table (owner decision, 2026-10-27)"
  - "The row guard lets the FK set-null of invoice_payment_id through in every cancel state; the CHECK, not the guard, refuses it for an active receipt"
  - "A blank paid-document number is left to the CHECK by the INSERT branch of the guard, so the column check is reachable"
patterns-established:
  - "cash_receipts guard: frozen set = all columns not in mutable_columns; per-state allowed lists"
requirements-completed: [P15-05, P15-07, P15-10, P15-12]
status: complete
duration: not measured
completed: 2026-10-27
actuals:
  tokens: 12000
  tasks: 2
  commits: 1
plan_head_before: 194ad477c0ec09cbe93ed4848143111812e6bdc5
plan_head_after: 6d674a3e28fc24fbd727e4dc9be8f43ea4a1d8ae
---

# Phase 15 Plan 02: Cash receipt table Summary

**`cash_receipts` table with PostgreSQL guards: one receipt per cash payment, frozen number and amount, edit while open, sanctioned unlock, cancel once, never deleted, and a payment with a valid receipt cannot be deleted.**

## Decision record: PPD table shape (Task 1)

- **Decision:** `single-table` - one Partner-readable table `cash_receipts`; cancel and unlock reasons and their causers live only in the Admin-only activity log (no `created_by`, `cancelled_by` or reason column).
- **Decided by:** the owner, 2026-10-27, through the decision checkpoint of this plan (the table creation is a one-way door).
- **Rejected:** `split-table` (Partner-readable `cash_receipts` plus Admin-only `cash_receipt_admin`): the D-08 rule would span two tables and need a deferred constraint trigger, and plans 15-06 and 15-08 to 15-14 would need revision.
- **Consequence:** plans 15-06 and 15-08 to 15-14, written for `single-table`, run unchanged. Column list is R-15-02-1 (20 columns, in order), including the frozen `document_number`.

## Accomplishments

- Migration `2026_10_27_000200_create_cash_receipts_table.php`: table, named keys and checks (R-15-02-2, R-15-02-3), row guard (R-15-02-4), PDF link guard and truncate guard (R-15-02-5), `down()` (R-15-02-7), header stating the application-only enforcement of D-06 accepted by the owner (R-15-02-6, AS-2).
- Raw-SQL schema test `CashReceiptsTableTest` with 21 cases: insert matching, uniqueness, frozen columns, open edits, lock, unlock setting, cancel once, delete and truncate refusal, D-08 (payment deletion refused with 23514 while valid, allowed after the cancel with the receipt kept), PDF link rules, catalogue pins.
- `PartnerSafeColumnsTest`: the `cash_receipts` column list pinned in order with the review sentence, plus the suspicious-name test allowing exactly `invoice_payment_id` (D-13).

## Task Commits

1. **Task 1: PPD table shape (decision checkpoint)** - no commit; resolved by the owner as `single-table`, recorded above.
2. **Task 2: Tracer - migration, schema test, Partner-safe pin** - `6d674a3` (feat)

## Files Created/Modified

- `database/migrations/2026_10_27_000200_create_cash_receipts_table.php` - table, constraints, two guard functions and triggers, truncate guard.
- `tests/Feature/Schema/CashReceiptsTableTest.php` - raw-SQL proof of every rule.
- `tests/Isolation/PartnerSafeColumnsTest.php` - column pin and suspicious-name test for `cash_receipts`.

## Deviations from Plan

### Auto-fixed / judgement calls

**1. [Rule 1 - Consistency] FK set-null update passes the row guard in every cancel state**
- **Found during:** Task 2 (writing the behavior list against R-15-02-4)
- **Issue:** R-15-02-4 says the guard accepts the `invoice_payment_id -> NULL` change only when `OLD.cancelled_at IS NOT NULL`, which makes the guard answer KP001 for the active receipt. The behavior list and the T-15-10 mitigation say the operative defence is `cash_receipts_payment_link_check` and the deletion answers 23514.
- **Fix:** the guard accepts a change of the link only to NULL with nothing else changing, in any cancel state, and the CHECK refuses it for a receipt that is not cancelled. Outcome is the same (the payment deletion fails and nothing changes); the error is 23514 as the behavior list requires.
- **Files modified:** migration, test.
- **Commit:** 6d674a3

**2. [Rule 1 - Consistency] Blank paid-document number is left to the CHECK**
- **Issue:** the behavior list wants 23514 for a blank `document_number`, but the INSERT payment match (`invoices.number = document_number`) would answer KP001 first.
- **Fix:** the INSERT match accepts a blank value so `cash_receipts_document_number_check` answers 23514; any non-blank value must equal the invoice number (KP001).
- **Commit:** 6d674a3

**3. [Rule 3 - Blocking/robustness] Payments in the test are inserted by raw SQL, not through `RecordPayment::handle()`**
- **Issue:** the plan names `RecordPayment::handle()`; the database tests cannot be run in a worktree, and a raw insert (as in `InvoiceAdminTablesTest`) removes the dependency on the payment input shape and the Gate. Same table content (method `cash`, proforma, no income).
- **Commit:** 6d674a3

**4. [Rule 3 - Hygiene] Number literal in tests**
- `scripts/check-sensitive.sh` flagged an 8-digit literal as a company id; the test literal was shortened (`'PPD'||'777'`).

## Verification status

- Done in the worktree: `php -l` on all three files, Pint (`--test` on the three files, passed), `scripts/check-sensitive.sh` (clean), lefthook hook (sensitive-content, gitleaks) on the commit.
- NOT run, by project rule: migration, `pest tests/Feature/Schema tests/Arch tests/Isolation`, full Pint and PHPStan (the worktree has no `vendor/` and no shared database). These must run after the merge into the HEAD branch, in DDEV:
  - `ddev exec vendor/bin/pest tests/Feature/Schema/CashReceiptsTableTest.php tests/Isolation/PartnerSafeColumnsTest.php`
  - `ddev exec vendor/bin/pest tests/Feature/Schema tests/Arch tests/Isolation`
  - `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- Risk to check first on that run: the exact deparsed text of `pg_get_constraintdef` in the catalogue-pin test (parentheses are stripped for the CHECK; the FK is matched by regex), and the 23514 vs KP001 order of the two judgement calls above.

## Known Stubs

None.

## Threat Flags

None. The new surface (the `cash_receipts` table and the `kokpit.cash_receipt_unlock` setting) is the one the plan's threat model (T-15-07 to T-15-11) covers.

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_27_000200_create_cash_receipts_table.php
- FOUND: tests/Feature/Schema/CashReceiptsTableTest.php
- FOUND: commit 6d674a3 (ancestor of HEAD)
