---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 08
subsystem: invoicing
tags: [cash-receipt, ppd, record-payment, numbering, audit, concurrency]
requires:
  - phase: 15-05
    provides: DocumentKind::CashReceipt series (cash_receipt:YYYY, default PPD{YYYY}{NNNN})
  - phase: 15-06
    provides: CashReceipt model, CashReceiptLockState, relations, morph alias cash_receipt
  - phase: 15-02
    provides: cash_receipts table and its row guard
provides:
  - IssueCashReceipt action (number, snapshots, paid document number, row and audit inside the payment transaction)
  - cash hook in RecordPayment::recordLocked() (cash only, never carry())
  - CashReceiptAudit (issued, edited, locked, unlocked, cancelled writers, snapshot(), cancelReason())
  - lang/cs/cash_receipts.php (full errors set for plans 15-09 to 15-14), purpose texts cs/en, activity labels
  - payment-worker.php mode record-cash and CashReceiptNumberingConcurrencyTest
affects: [15-09, 15-10, 15-11, 15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "A receipt-style document is issued by an Action called from the one payment write core, inside its transaction, so the number allocation rolls back with the payment"
    - "Explicit audit writers with a fixed key allowlist in the invoice log, free text only in the Admin-only activity log"
key-files:
  created:
    - app/Domain/Invoicing/Actions/IssueCashReceipt.php
    - app/Domain/Invoicing/Support/CashReceiptAudit.php
    - lang/cs/cash_receipts.php
    - lang/cs/cash_receipt_pdf.php
    - lang/en/cash_receipt_pdf.php
    - tests/Feature/Invoicing/CashReceiptIssueTest.php
    - tests/Concurrency/CashReceiptNumberingConcurrencyTest.php
  modified:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - lang/cs/kokpit.php
    - tests/Concurrency/payment-worker.php
key-decisions:
  - "R-15-08-1 to R-15-08-4 applied as written"
  - "Composite audit keys (customer.<key>, supplier.<key>) get Czech labels as nested lang arrays under activity.attributes.cash_receipt, so ActivityPresenter needed no change"
  - "CashReceiptAudit::edited() only writes keys of the snapshot allowlist, so a caller cannot push an arbitrary key into the log"
  - "The issued row carries no purpose text (it is an editable printed text; edits are what is logged)"
requirements-completed: [P15-05, P15-06]
status: complete
duration: not measured
completed: 2026-10-27
actuals:
  tokens: 13500
  tasks: 2
  commits: 4
plan_head_before: 51f9f4a3b90a55ac90bde3435e507e85188ab566
plan_head_after: 3b539d98b11ee9d2f9b7fe728f668390a5f319ae
---

# Phase 15 Plan 08: Automatic cash receipt issue Summary

**Every cash payment recorded through `RecordPayment::recordLocked()` now stores one numbered cash receipt (PPD) in the same transaction, copying amount, the three snapshots, the paid document's number and the client's language, with one Admin-only audit row; carried copies, transfer and card payments issue none. Numbering is proven by an issue test and a two-process concurrency test (both written, not yet run).**

## Accomplishments

- `IssueCashReceipt::handle(Invoice $locked, InvoicePayment $payment, User $actor): CashReceipt`: `LogicException` outside a transaction, for a non-cash payment, or for a payment of another document. Number from `DocumentNumbering::next(DocumentKind::CashReceipt, noon of the Prague issue day)`; `issued_on` today, `received_on` = `paid_on` (a back-dated payment keeps its date and takes a number of the current year); `document_number` = the locked document's number; language = the client's `invoice_language` read with `withTrashed()` (fallback: document language); purpose from `cash_receipt_pdf.purpose.invoice|proforma` in that language; snapshots copied from the locked document; written with `forceFill`; then `CashReceiptAudit::issued()`.
- `RecordPayment` gets `IssueCashReceipt` in the constructor and calls it after the income and `paymentLinks->reconcile()`, before the confirmation, for `PaymentMethod::Cash` only. `carry()` is untouched (D-07). The class docblock records that this write path is the only enforcement of D-06 (no database trigger, owner decision of 2026-10-27).
- `CashReceiptAudit` (log `invoice`, subject the receipt): `issued` (fixed keys: number, document_number, invoice_id, amount_minor, currency, received_on), `edited` (diff of `snapshot()` shapes, only allowlisted keys), `locked`, `unlocked` (with `unlock_reason`), `cancelled` (with `cancel_reason`), `cancelReason()`; reasons and printed texts go only to the Admin-only activity log (AS-16).
- Lang: `lang/cs/cash_receipts.php` with the full `errors` set (12 keys) for later plans; `lang/{cs,en}/cash_receipt_pdf.php` with the two purpose texts; `lang/cs/kokpit.php` with the five receipt event labels, the `cash_receipt` subject label "Příjmový doklad" and attribute labels (including `customer.*` and `supplier.*`).
- Tests: `CashReceiptIssueTest` (10 cases: full field copy, one audit row with fixed keys and no purpose text, proforma in English, cash on a transfer invoice, two partial payments with consecutive numbers, none for transfer incl. on a cash document, none for a card payment through `recordLocked(null)`, none for the carried copy after `ConvertProforma`, back-dated payment, LogicException guards, rollback with no gap via `FailingAfterAllocationSequenceAllocator([1])`); `CashReceiptNumberingConcurrencyTest` (five rounds, two real processes, distinct consecutive numbers, one unbroken run over all rounds, counter moved by exactly the number of receipts); `payment-worker.php` mode `record-cash`.

## Task Commits

1. Task 1 RED: `98866c4` test(15-08) failing tests for the automatic cash receipt
2. Task 1 GREEN: `5fe6afb` feat(15-08) issue a numbered cash receipt with every cash payment
3. Task 2 RED: `0ed98db` test(15-08) failing concurrency test for cash receipt numbering
4. Task 2 GREEN: `3b539d9` test(15-08) record-cash mode of the payment worker (test infrastructure, so the type is `test`, not `feat`)

`commits: 4` is measured with `git rev-list --count` from the base to `3b539d9`; the SUMMARY commit follows it. No refactor commit was needed.

## TDD Gate Compliance

RED commits precede their GREEN commits in history. The RED runs were NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. No `check tdd-red-evidence` record exists. By construction `IssueCashReceipt` did not exist at `98866c4` (every issue case would fail on the missing receipt), and the worker rejected the mode `record-cash` at `0ed98db` (exit 2, no result line, so the concurrency test would fail at "printed no result"). Flagged for the post-merge run.

## Deviations from Plan

None in the code. Process deviation: both verify steps (Pest, PHPStan, directory-wide Pest/Pint/PHPStan) are moved to the post-merge run (project rule for worktrees).

Judgement calls:
- The concurrency test's cleanup deletes receipts (and their activity rows) before payments, because a payment with a valid receipt cannot be deleted (`cash_receipts_payment_link_check`, which the disabled USER triggers do not switch off). `cash_receipts` is in its guarded-table list. `PaymentDeletionConcurrencyTest.php` is not edited; it never records a cash payment.
- `CashReceiptAudit::snapshot()` was added beside the writers (not named in R-15-08-3) so `edited()` and its later callers share one flat shape, like `InvoiceAudit::snapshot()`.

## Verification status

Done in the worktree: `php -l` on every touched PHP file; Pint `--test` (main checkout binary) on every touched file, passed; `scripts/check-sensitive.sh` clean and the lefthook hook (sensitive-content, gitleaks) passed on every commit. (`pint --test lang` as a directory fails on six untouched `lang/cs` files: pre-existing, outside this plan.)

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after migrating:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptIssueTest.php`
- `ddev exec vendor/bin/pest tests/Concurrency/CashReceiptNumberingConcurrencyTest.php tests/Concurrency/PaymentDeletionConcurrencyTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first on that run: (a) the four existing tests that record a cash payment through `RecordPayment` (`ConvertProformaTest` carry case, `InvoiceDetailTest`, `PaymentModalTest`, `PaymentsTest`) plus `CardPaymentCoreTest`'s all-methods case, which now also writes a receipt (a red one means a fault in this plan's code); (b) `->toThrow()` and `trans()` with an explicit locale in the issue test; (c) the INSERT branch of the receipt row guard against the receipt (same invoice, amount, currency, document number, `received_on <= issued_on`); (d) PHPStan on `$payment->paid_on->format()` of a just-saved payment and on the `attribute_changes` reads in `CashReceiptAudit`; (e) the `ConvertProforma` case in the issue test (a proforma paid in full in cash, converted without time entries); (f) the concurrency test's cleanup and its `cash_receipt:<year>` counter restore.

## Known Stubs

None.

## Threat Flags

None. T-15-17 mitigated as planned (receipt written inside `recordLocked()` before commit; failure-seam test; concurrency test; application-only enforcement accepted by the owner). T-15-43 mitigated: `CashReceiptAudit` writes fixed keys to the Admin-only `invoice` log, the purpose is not in the issued row, reasons exist only in the log. T-15-SC: composer.json and composer.lock untouched.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Actions/IssueCashReceipt.php, app/Domain/Invoicing/Support/CashReceiptAudit.php, lang/cs/cash_receipts.php, lang/cs/cash_receipt_pdf.php, lang/en/cash_receipt_pdf.php, tests/Feature/Invoicing/CashReceiptIssueTest.php, tests/Concurrency/CashReceiptNumberingConcurrencyTest.php
- FOUND commits (ancestors of HEAD): 98866c4, 5fe6afb, 0ed98db, 3b539d9
- `grep IssueCashReceipt RecordPayment.php`, `DocumentKind::CashReceipt` and `document_number` in the Action, `cash_receipt_cancelled` and `function cancelReason` in the audit, `payment_has_receipt` in `cash_receipts.php`, `record-cash` in the worker and the test: all present
- STATE.md and ROADMAP.md untouched
