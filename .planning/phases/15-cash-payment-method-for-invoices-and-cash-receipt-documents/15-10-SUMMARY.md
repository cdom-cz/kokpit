---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 10
subsystem: invoicing
tags: [cash-receipt, payment-confirmation, mail, lock, queue-job]
requires:
  - phase: 15-09
    provides: stored receipt PDF (pdf_document_id), CashReceiptPdf::fileName
  - phase: 15-02
    provides: cash_receipts guard (open -> locked allowed, cancel set once)
  - phase: 13
    provides: SendPaymentConfirmation, PaymentConfirmationMail, QueuePaymentConfirmation
provides:
  - SendPaymentConfirmation attaches the stored receipt PDF of a cash payment and locks the receipt on the successful send
  - FAILURE_ATTACHMENT_MISSING ('attachment_missing') for a missing stored receipt PDF
  - PaymentConfirmationMail::forPayment(..., ?array $receipt), $receiptNumber, attachments()
  - payment_mail.receipt_attached (cs, en)
affects: [15-11, 15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "markSent(?string $receiptId) in one DB::transaction: lock the receipt row, conditional sending -> sent, lock only when that update changed one row and the receipt is open and not cancelled"
key-files:
  created:
    - tests/Feature/Invoicing/CashReceiptDeliveryTest.php
  modified:
    - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
    - app/Domain/Invoicing/Mail/PaymentConfirmationMail.php
    - resources/views/mail/payment-confirmation.blade.php
    - lang/cs/payment_mail.php
    - lang/en/payment_mail.php
    - app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php
    - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
    - tests/Feature/Invoicing/PaymentConfirmationMailTest.php
key-decisions:
  - "R-15-10-1 to R-15-10-4 applied as written"
  - "The lock-row causer is the user in invoice_payments.created_by (null when not found), read without any lock on the payment row"
  - "markSent receives the receipt id only when a receipt was attached; a cancelled or absent receipt passes null and is never locked"
requirements-completed: [P15-09, P15-07]
status: complete
duration: not measured
completed: 2026-10-27
commits: 4
plan_head_before: 69a1a9c470cf8f28058d6c0ba9b583f26bc0b08f
plan_head_after: 58a360e8c89572785b6837556f5d1b3f138ceb1e
actuals:
  tokens: 14000
  tasks: 2
  commits: 4
---

# Phase 15 Plan 10: Receipt delivered with the payment confirmation Summary

**The Phase 13 payment confirmation of a cash payment now carries the stored receipt PDF as its only attachment with one sentence naming the receipt number (cs and en), and its successful send locks the receipt in the same transaction as the sent mark with one audit row, without ever touching the invoice.**

## Accomplishments

- `SendPaymentConfirmation::handle()` resolves the receipt of the payment after the recipient check. Not cancelled: reads the stored PDF bytes from the media disk (exactly as `SendInvoiceEmail::storedPdf()`), no bytes means `giveUp('attachment_missing')` and nothing is sent. Cancelled: no attachment (AS-1). No receipt (transfer, card, carried copy): behaviour unchanged.
- `markSent(?string $receiptId)` is one `DB::transaction`: lock the receipt row, conditional `sending -> sent`, and only when that changed exactly one row and the receipt is neither cancelled nor locked, `lock_state = locked` through the model plus `CashReceiptAudit::locked()` (causer: the payment creator). The job never locks or writes the invoice or payment row, so a cancel (invoice, payment, receipt order) and a send serialize on the receipt row with no deadlock. A failure rolls both back into the existing catch (back to queued).
- `PaymentConfirmationMail::forPayment()` takes an optional `?array $receipt` (`name`, `data`, `number`); `attachments()` returns one `Attachment::fromData(...)->withMime('application/pdf')` or `[]`; the view adds one escaped sentence only when a receipt is attached. The file name comes from `CashReceiptPdf::fileName()` (generated).
- Docblocks per R-15-10-4 in the job, the mail, `QueuePaymentConfirmation` and `SendInvoiceEmail` (the document e-mail stays the only lock of an invoice; the confirmation locks only the cash receipt).

## Task Commits

1. Task 1 RED: `4bf7206` test(15-10) failing tests for the receipt delivered with the payment confirmation (5 cases: cash invoice, proforma AS-3, English, transfer without attachment, second run)
2. Task 1 GREEN: `9811fc9` feat(15-10) deliver the cash receipt with the payment confirmation and lock it on send
3. Task 2 tests: `c52ba31` test(15-10) edge cases of receipt delivery and the mail wording (cancelled AS-1, missing PDF, skipped AS-10, edit within the grace window D-11, transport retry, already locked, causer; plus 3 mail-wording cases)
4. Task 2 docs: `58a360e` docs(15-10) state in the docblocks what the payment confirmation may lock

`CashReceiptDeliveryTest` has 12 `it(` blocks; `PaymentConfirmationMailTest` gained 3. `commits: 4` is measured with `git rev-list --count` from the ledger base to `58a360e`; the SUMMARY commit follows it.

## TDD Gate Compliance

RED commit `4bf7206` precedes GREEN `9811fc9`. The RED run was NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. By construction `FAILURE_ATTACHMENT_MISSING`, the receipt attachment, the receipt sentence and the lock in `markSent` did not exist at `4bf7206`, so the attachment/lock cases fail there; the transfer case (unchanged behaviour) and the rerun case of an unlocked-by-nothing path are characterization checks that pass at RED by design. No `check tdd-red-evidence` record exists. Task 2's edge tests (`c52ba31`) assert behaviour implemented in the Task 1 GREEN commit (R-15-10-1 and -3 cover it), so they are expected to pass on first run; only the docblock commit follows. Flagged for the post-merge run.

## Deviations from Plan

- **Process:** the Pest runs, directory-wide Pest, full Pint and PHPStan of both verify blocks are moved to the post-merge run (project rule for worktrees). Done here: `php -l` and Pint (main checkout binary, worktree config) on every touched PHP, lang and test file, `scripts/check-sensitive.sh`, and the lefthook hook on every commit. PHPStan was not run (it needs vendor, the app and the DB); types were reviewed by hand.
- **Tracer gate:** the end-to-end re-run of `<verify>` after the tracer task could not be executed for the same reason; it is the first thing to run after the merge.

## Known Stubs

None.

## Threat Flags

None. T-15-22 mitigated (the receipt is resolved by the confirmation's own `invoice_payment_id`); T-15-23 mitigated (lock only after the conditional update changed one row, only for an open, not cancelled receipt, one transaction, the invoice row is never read for update, tests assert the raw invoice row unchanged); T-15-24 mitigated (`attachment_missing` carries no text); T-15-SC: composer.json and composer.lock untouched.

## Verification status

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after migrating:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptDeliveryTest.php tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php tests/Feature/Invoicing/PaymentConfirmationMailTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first: (a) existing tests that record a cash payment through `RecordPayment` for a client with an invoice e-mail without `Queue::fake()` (sync queue) now also attach and lock the receipt in the same run; a red one points at a fixture, not at the job; (b) the guard of 15-02 on the `open -> locked` write with `updated_at` (the job writes only `lock_state` and `updated_at`); (c) the `cashReceiptDeliveryWrite` raw `lock_state`/`cancelled_at` writes as the system, and the fixed `cancelled_at` instant in the future of the frozen test clock; (d) the Czech sentence with non-breaking spaces in the visible-text assertions; (e) PHPStan on `Attachment::fromData` closure return type, the `trans()` return of `fileName()` (already noted in 15-09) and the `$receipt?->id` narrowing in `handle()`.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php, app/Domain/Invoicing/Mail/PaymentConfirmationMail.php, resources/views/mail/payment-confirmation.blade.php, lang/cs/payment_mail.php, lang/en/payment_mail.php, tests/Feature/Invoicing/CashReceiptDeliveryTest.php
- FOUND commits (ancestors of HEAD): 4bf7206, 9811fc9, c52ba31, 58a360e
- `grep` checks: `CashReceiptLockState::Locked`, `CashReceiptAudit::locked`, `attachment_missing` in the job; `Attachment::fromData` in the mail; `receipt_attached` in both lang files; `cash receipt` in `SendInvoiceEmail` and `QueuePaymentConfirmation`: present
- STATE.md and ROADMAP.md untouched
