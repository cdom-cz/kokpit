---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 13
subsystem: invoicing
tags: [cash-receipt, edit, unlock, regenerate-pdf, audit, architecture-test]
requires:
  - phase: 15-02
    provides: cash_receipts row guard (edit while open, sanctioned unlock setting, PDF link rules)
  - phase: 15-09
    provides: StoreCashReceiptPdf, CashReceiptPdf
  - phase: 15-10
    provides: lock on the successful confirmation send (SendPaymentConfirmation)
provides:
  - UpdateCashReceipt (edit of received_on, purpose, customer and supplier lines while open; PDF replaced; audited)
  - UnlockCashReceipt (Admin unlock with a 10 to 500 character reason; the only user of kokpit.cash_receipt_unlock)
  - RegenerateCashReceiptPdf (recreates a missing PDF in any lock state)
  - tests/Arch/CashReceiptUnlockPathTest.php (pins the unlock setting and the locked token)
affects: [15-12, 15-14]
tech-stack:
  added: []
  patterns:
    - "Lock order of a receipt writer: invoice row, receipt row, then the payment confirmation row (edit only)"
    - "Edit that changes nothing returns before any save, PDF render or audit row"
key-files:
  created:
    - app/Domain/Invoicing/Actions/UpdateCashReceipt.php
    - app/Domain/Invoicing/Actions/UnlockCashReceipt.php
    - app/Domain/Invoicing/Actions/RegenerateCashReceiptPdf.php
    - tests/Arch/CashReceiptUnlockPathTest.php
    - tests/Feature/Invoicing/CashReceiptLockTest.php
    - tests/Feature/Invoicing/CashReceiptEditTest.php
  modified:
    - lang/cs/cash_receipts.php
key-decisions:
  - "R-15-13-1 to R-15-13-4 applied as written"
  - "The edit locks the payment confirmation row (FOR UPDATE) while checking for state sending, so the send job cannot claim the row between the check and the commit (T-15-32)"
  - "A cancelled receipt reports cancelled before not_locked in the unlock, as the more informative refusal"
  - "RegenerateCashReceiptPdf also replaces a link whose document row has lost its stored object (not only a NULL link)"
requirements-completed: [P15-07, P15-08]
status: complete
duration: not measured
completed: 2026-10-27
commits: 4
plan_head_before: ee78358e3f3396cf5665ac09f298184a21216733
plan_head_after: 8550f806d8b7ec68dd862662609b4bb86da0a412
actuals:
  tokens: 18000
  tasks: 2
  commits: 4
---

# Phase 15 Plan 13: Receipt edit, unlock and PDF recreation Summary

**An open cash receipt can be corrected like an open invoice (date, purpose, party lines, PDF re-rendered in the same transaction, audited with before and after), a locked one is reopened only by the Admin with a recorded reason through a sanctioned setting pinned by an architecture test, and a lost receipt PDF is rebuilt from the receipt row in any state.**

## Accomplishments

- `UpdateCashReceipt::handle(User, CashReceipt, array)`: Gate `update` first; one transaction locking the invoice row, then the receipt row (and the confirmation row of its payment); refuses cancelled (`cancelled`), locked (`locked`) and a confirmation in state `sending` (`sending`), all under `receipt`. Reads only `received_on` (real `Y-m-d` day, not after `issued_on`), `purpose` (trimmed, 1..500), `customer` and `supplier` (through `SnapshotInput`); every other key, including amount, currency, number and language, is ignored. An edit with no effective change returns without a save, a new PDF or an audit row. Otherwise it saves through the model, calls `StoreCashReceiptPdf` and writes `CashReceiptAudit::edited` with the values before and after.
- `UnlockCashReceipt::handle(User, CashReceipt, string)`: copy of `UnlockInvoice` with `REASON_MIN = 10`, `REASON_MAX = 500`, the setting `kokpit.cash_receipt_unlock` set before the save and cleared right after, refusals `cancelled` and `not_locked` under `receipt`, audit row `cash_receipt_unlocked` with the reason (Admin-only log).
- `RegenerateCashReceiptPdf::handle(User, CashReceipt): Document`: Gate `update`; refuses with `pdf_present` under `receipt` when the linked document row exists and its media object is on the disk; otherwise `StoreCashReceiptPdf` (allowed in every state by the guard of 15-02, the cancelled band included).
- `CashReceiptUnlockPathTest`: the setting appears in `app/` only in `UnlockCashReceipt.php` and in `database/migrations/` only in `2026_10_27_000200_create_cash_receipts_table.php`; `CashReceiptLockState::Locked` appears in `app/` only in `SendPaymentConfirmation.php` and `CashReceipt.php`; a self-test with a temporary tree proves the scan can fail.
- Tests: `CashReceiptLockTest` (7 `it` blocks: tracer edit with PDF text and audit, edit refused after the real send job locked it, reason bounds 9 / 501, unlock with audit then edit, not-locked refusal, Partner refusal), `CashReceiptEditTest` (12 `it` blocks).

## Task Commits

1. Task 1 RED: `fa5a36f` test(15-13) failing tests for edit, unlock and the pinned unlock path
2. Task 1 GREEN: `7fbb43f` feat(15-13) implement the cash receipt edit and the Admin unlock
3. Task 2 RED: `b5beb85` test(15-13) failing tests for the edit limits and the PDF recreation
4. Task 2 GREEN: `8550f80` feat(15-13) recreate a missing cash receipt PDF from its row

## TDD Gate Compliance

RED commits `fa5a36f` and `b5beb85` precede their GREEN commits `7fbb43f` and `8550f80`. The RED runs were NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. By construction the classes `UpdateCashReceipt`, `UnlockCashReceipt` and `RegenerateCashReceiptPdf` did not exist at the RED commits, so every Action test fails there (class not found, which is an error rather than a clean assertion failure); the three static scans of `CashReceiptUnlockPathTest` also fail at `fa5a36f` (no file names the unlock setting yet), the self-test passes by design. No `check tdd-red-evidence` record exists. Flagged for the post-merge run.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Czech `reason_length` text said 3 to 500 characters**
- **Found during:** Task 1
- **Issue:** `lang/cs/cash_receipts.php` (created in an earlier plan) read "Důvod musí mít 3 až 500 znaků.", while the unlock (and the cancel of 15-12) require 10 to 500.
- **Fix:** changed the text to "10 až 500 znaků".
- **Files modified:** lang/cs/cash_receipts.php (one line; 15-12 does not touch this file)
- **Commit:** 7fbb43f

### Process

- The Pest runs, directory-wide Pest, full Pint and PHPStan of both verify blocks are moved to the post-merge run (project rule for worktrees). Done here: `php -l`, Pint (main checkout binary, worktree config) on every touched PHP and lang file, `scripts/check-sensitive.sh`, the lefthook hook on every commit, and `grep` checks that the unlock setting and the locked token appear only in the pinned files. PHPStan was not run (it needs vendor, the app and the DB); types were reviewed by hand.
- **Tracer gate:** the end-to-end re-run of `<verify>` after the tracer task could not be executed for the same reason; it is the first thing to run after the merge.
- `scripts/check-sensitive.sh` flagged a first draft of the edit test (an eight-digit number fragment looked like a company ID); replaced by the fictional number `PPD-OTHER`.

## Known Stubs

None.

## Threat Flags

None. T-15-29 mitigated (only four keys are read; test with extra keys); T-15-30 mitigated (architecture test); T-15-31 mitigated (Gate `update` first in all three Actions; Partner test); T-15-32 mitigated (refusal while `sending`, confirmation row locked during the edit); T-15-SC: composer.json and composer.lock untouched.

## Verification status

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after migrating:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptLockTest.php tests/Arch/CashReceiptUnlockPathTest.php tests/Arch/InvoiceUnlockPathTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptEditTest.php tests/Feature/Invoicing/CashReceiptLockTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first: (a) the guard of 15-02 on the edit save (the model writes `received_on`, `purpose`, the snapshots and `updated_at`; `received_on` is assigned as a `Y-m-d` string and compared through `CashReceiptAudit::snapshot`); (b) `payment_confirmations` may have a guard that refuses the raw `state` write the edit test uses to simulate `sending` (`cashReceiptEditConfirmation`); (c) `DeleteDocument` of a receipt PDF on a locked or cancelled receipt in the regenerate tests relies on the FK `SET NULL` being accepted by the guard in those states; (d) the PDF text assertion in the tracer test is skipped silently when Ghostscript is missing (the HTML assertion still runs); (e) PHPStan on `CarbonImmutable::createFromFormat` returning `false`, on the unused result of the invoice row lock, and on `Storage::disk($media->disk)` types in `RegenerateCashReceiptPdf`.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Actions/UpdateCashReceipt.php, UnlockCashReceipt.php, RegenerateCashReceiptPdf.php, tests/Arch/CashReceiptUnlockPathTest.php, tests/Feature/Invoicing/CashReceiptLockTest.php, tests/Feature/Invoicing/CashReceiptEditTest.php
- FOUND commits (ancestors of HEAD): fa5a36f, 7fbb43f, b5beb85, 8550f80
- `grep -rl "kokpit.cash_receipt_unlock" app` prints only UnlockCashReceipt.php; `grep -rl "CashReceiptLockState::Locked" app` prints only SendPaymentConfirmation.php and CashReceipt.php
- STATE.md and ROADMAP.md untouched
