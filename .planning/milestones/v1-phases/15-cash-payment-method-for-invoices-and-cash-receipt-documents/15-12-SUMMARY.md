---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 12
subsystem: invoicing
tags: [cash-receipt, storno, cancel, payment-deletion, document-protection]
requires:
  - phase: 15-09
    provides: StoreCashReceiptPdf (re-render with the cancelled band, replace of the linked file)
  - phase: 15-10
    provides: SendPaymentConfirmation (cancelled receipt not attached, not locked; row lock on the receipt only)
  - phase: 15-02
    provides: cash_receipts guard (cancel set once, payment link check, ON DELETE SET NULL)
provides:
  - CancelCashReceipt (REASON_MIN 10, REASON_MAX 500), the Admin-only storno with the reason in the Admin log
  - DeletePayment refusal payment_has_receipt while the receipt is not cancelled
  - DeleteDocument refusal pdf_protected for a file owned by a cash_receipt unless the confirmation flag is set
affects: [15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "Lock order of every payment writer: invoice, payment, receipt, income"
    - "A cancel never deletes: the receipt, its number and its PDF stay; the payment link empties through the database key"
key-files:
  created:
    - app/Domain/Invoicing/Actions/CancelCashReceipt.php
    - tests/Feature/Invoicing/CashReceiptCancelTest.php
  modified:
    - app/Domain/Invoicing/Actions/DeletePayment.php
    - app/Domain/Documents/Actions/DeleteDocument.php
    - lang/cs/cash_receipts.php
    - tests/Feature/Invoicing/DeletePaymentActionTest.php
key-decisions:
  - "R-15-12-1 to R-15-12-4 applied as written"
  - "reason_length text in lang/cs/cash_receipts.php corrected from 3 to 10 to 500 characters, to match REASON_MIN 10 (plan 15-13 uses the same key with the same bounds)"
requirements-completed: [P15-10, P15-12]
status: complete
duration: not measured
completed: 2026-10-27
commits: 4
plan_head_before: ee78358e3f3396cf5665ac09f298184a21216733
plan_head_after: 54cf1b8
actuals:
  tokens: 22000
  tasks: 2
  commits: 4
---

# Phase 15 Plan 12: Storno of a cash receipt Summary

**The Admin cancels a cash receipt with a recorded reason (10 to 500 characters, Admin-only log), which keeps its number and replaces its PDF with one carrying the cancelled band; DeletePayment refuses a payment whose receipt is still valid, and no delete path but the sanctioned replace can remove the stored receipt PDF.**

## Accomplishments

- `CancelCashReceipt::handle(User, CashReceipt, string): CashReceipt`: policy `update` first (Partner gets the base denial), reason trimmed and measured under `reason` (`cash_receipts.errors.reason_length`), one transaction that locks the invoice, the payment (when the link is not empty) and the receipt in that order, refuses a second cancel under `receipt` (`already_cancelled`), sets `cancelled_at` with a single-model save (the only columns the database guard accepts for a cancel), writes `CashReceiptAudit::cancelled` and re-renders the PDF through `StoreCashReceiptPdf` in the same transaction. Works on an open and on a locked receipt. No confirmation row is touched (AS-1 is handled by the job of 15-10).
- `DeletePayment`: after the carried-copy refusal and before the income lock, reads the receipt of the payment under lock; a receipt that is not cancelled throws under `payment` with `payment_has_receipt` naming the receipt number. A cancelled receipt is left alone; the key clears its payment link when the payment row goes. Class docblock gained step 2b and the new lock order.
- `DeleteDocument`: a file owned by a `cash_receipt` is refused with `cash_receipts.errors.pdf_protected` unless `confirmedIssuedPdf` is true, in any lock state. `StoreCashReceiptPdf` already passes the flag.
- Tests: `CashReceiptCancelTest` (17 cases: refusal while valid, cancel with audit and no reason column, cancelled band, delete after cancel, number never reused, Partner, reason bounds 9/10/500/501 and padded, second cancel, receipt locked by the real send, cancel before the send AS-1, proforma, queued confirmation dropped with its payment, lock order of cancel and deletion by query log, PDF protection open/locked/cancelled/with flag) and one new case in `DeletePaymentActionTest` (Platby tab shows the `payment_has_receipt` danger notification).

## Task Commits

1. Task 1 RED: `3f30b3b` test(15-12) failing tests for the cancel and the deletion guard
2. Task 1 GREEN: `69ea02a` feat(15-12) CancelCashReceipt, the DeletePayment refusal, lang bound fix
3. Task 2 RED: `dfe0af6` test(15-12) failing tests for PDF protection and the cancel edge cases, plus the Platby tab case
4. Task 2 GREEN: `54cf1b8` feat(15-12) DeleteDocument protection of the receipt PDF

`commits: 4` is measured with `git rev-list --count ee78358..54cf1b8`; the SUMMARY commit follows it. The ledger file in the git dir could not be created in this sandbox, so the base is the worktree base commit recorded above.

## TDD Gate Compliance

RED commits `3f30b3b` and `dfe0af6` precede GREEN `69ea02a` and `54cf1b8`. The RED runs were NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. By construction `CancelCashReceipt` and the `payment_has_receipt` refusal did not exist at `3f30b3b`, and the `pdf_protected` refusal did not exist at `dfe0af6`, so the corresponding cases fail there; the Partner, reason-bound, second-cancel, proforma, queued-confirmation, AS-1 and lock-order cases of Task 2 assert behaviour implemented in Task 1 GREEN and are expected to pass on the first run. No `check tdd-red-evidence` record exists. Flagged for the post-merge run.

## Deviations from Plan

- **[Rule 1 - Bug] reason_length text:** `lang/cs/cash_receipts.php` said "3 až 500 znaků" while the plan fixes the bounds at 10 to 500; corrected to "10 až 500 znaků". File not in the plan's `files_modified`, one line, no overlap with 15-13 (which reuses the key with the same bounds).
- **Process:** the Pest runs, directory-wide Pest, full Pint and PHPStan of the verify blocks are moved to the post-merge run (project rule for worktrees). Done here: `php -l`, Pint (main checkout binary) on every touched PHP, lang and test file, `scripts/check-sensitive.sh` and the lefthook hook on all four commits. PHPStan not run (needs vendor, app, DB); types reviewed by hand. The tracer end-to-end re-run of `<verify>` could not be executed either and is the first thing to run after the merge.

## Known Stubs

None.

## Threat Flags

None. T-15-25 mitigated (policy `update` first, test with a Partner); T-15-26 mitigated (reason 10..500, one audit row, receipt and PDF never deleted); T-15-27 mitigated (application refusal plus the database check of 15-02); T-15-28 mitigated (reason only in the Admin log, no column); T-15-20 mitigated (`pdf_protected` in all lock states, tested); T-15-SC: composer.json and composer.lock untouched.

## Verification status

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after migrating:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptCancelTest.php tests/Feature/Invoicing/DeletePaymentActionTest.php tests/Feature/Invoicing/PaymentsTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Feature/Documents tests/Isolation tests/Arch tests/Concurrency/PaymentDeletionConcurrencyTest.php`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first: (a) the lock-order test reads `DB::getQueryLog()` and matches `from "<table>"` with `for update`; if the grammar quotes differently the expected arrays need adjusting; (b) the cancelled-band text case needs Ghostscript and skips without it; (c) the Platby tab case relies on the notification title being the joined error messages (as the existing refusal case); (d) the queued-confirmation case relies on the cascade of the confirmation row with its payment; (e) PHPStan on the closure types in `CancelCashReceipt` and the `array<int, array<string, mixed>>` log shape in the test helper.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Actions/CancelCashReceipt.php, tests/Feature/Invoicing/CashReceiptCancelTest.php
- FOUND commits (ancestors of HEAD): 3f30b3b, 69ea02a, dfe0af6, 54cf1b8
- `grep` checks: `payment_has_receipt` in DeletePayment.php and DeletePaymentActionTest.php, `CashReceiptAudit::cancelled`, `StoreCashReceiptPdf`, `REASON_MIN = 10` in CancelCashReceipt.php, `pdf_protected` and `CashReceipt` in DeleteDocument.php: present
- STATE.md and ROADMAP.md untouched
