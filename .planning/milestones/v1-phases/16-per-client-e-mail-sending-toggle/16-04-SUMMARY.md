---
phase: 16-per-client-e-mail-sending-toggle
plan: 04
subsystem: invoicing
tags: [invoicing, cash-receipt, lock, audit, filament]
requires: [16-02]
provides:
  - App\Domain\Invoicing\Actions\LockCashReceipt (manual lock of an open, uncancelled cash receipt)
  - CashReceiptAudit::lockedManually() (event cash_receipt_locked_manually)
  - lockCashReceipt row action in the Platby tab
  - CashReceiptUnlockPathTest allowlist with LockCashReceipt as the second writer of the locked state
affects: [16-05]
tech-stack:
  added: []
  patterns:
    - second owner-approved writer of the locked state, confined by the architecture test
    - invoice-then-receipt row lock order, shared with UpdateCashReceipt
key-files:
  created:
    - app/Domain/Invoicing/Actions/LockCashReceipt.php
  modified:
    - app/Domain/Invoicing/Support/CashReceiptAudit.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - tests/Arch/CashReceiptUnlockPathTest.php
    - lang/cs/cash_receipts.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/CashReceiptAdminTest.php
    - tests/Feature/Invoicing/CashReceiptLockTest.php
key-decisions:
  - "LockCashReceipt sets no database setting: the row guard already lets an open, uncancelled row move to locked, so the unlock-setting literal stays in UnlockCashReceipt only"
  - "The confirmation row state is not consulted: SendPaymentConfirmation::markSent() locks only a receipt that is neither cancelled nor locked, so a later send adds no second lock row"
  - "The lock affordance lives on the Platby tab only (owner answer to research open question 3)"
requirements-completed: [P16-09, P16-10]
duration: 25min
completed: 2026-10-28
status: complete
actuals:
  tokens: 9500
  tasks: 2
  commits: 2
plan_head_before: af0c4c999de0c328d20147fc5e7b4c8a98a3b563
plan_head_after: 0ac02b52af6ad67c455b00121c1c68158d20c93d
---

# Phase 16 Plan 04: Manual cash receipt lock Summary

The Admin can now close an open, uncancelled cash receipt (PPD) by hand from the Platby tab, with a confirmation and no reason; the lock is audited under its own event, changes nothing on the receipt but its state, and is pinned as the second writer of the locked state by the architecture test.

`commits: 2` counts the code commits; the SUMMARY commit follows `plan_head_after`.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: LockCashReceipt, audit, Platby tab action, arch test, copy, admin tests | 0427cb5 |
| 2 | Refusals, Partner, edit/unlock afterwards, later confirmation send adds no second lock | 0ac02b5 |

## What was built

- `LockCashReceipt` (`final readonly`): Gate `update` first, then one transaction locking the invoice row, then the receipt row (the order of `UpdateCashReceipt`); refuses cancelled (`cash_receipts.errors.cancelled`) and locked (`cash_receipts.errors.already_locked`) under key `receipt`; saves `lock_state` through the model; writes `CashReceiptAudit::lockedManually()`. No unlock setting, no reason.
- `CashReceiptAudit::lockedManually()`: event `cash_receipt_locked_manually`, Admin as causer, `old.lock_state` open, `attributes.lock_state` locked. Class docblock lists it.
- `InvoicePaymentsRelationManager::lockReceiptAction()` (`lockCashReceipt`) in the receipt action group after the edit: visible for a receipt that is neither cancelled nor locked, `requiresConfirmation()`, runs through `runReceiptAction()` so the payment is re-read through the owner invoice. The page does not name the lock state enum case.
- `CashReceiptUnlockPathTest`: header point 2 rewritten (two writers), locked-token list is now `LockCashReceipt.php`, `SendPaymentConfirmation.php`, `CashReceipt.php`; test renamed. The two unlock-setting tests are unchanged.
- Czech copy: `errors.already_locked`, `admin.actions.lock`, `admin.lock.*`, activity label `cash_receipt_locked_manually`; reworded `errors.locked` and the second sentence of `admin.unlock.description` so no text claims an e-mail where none was sent.
- Tests: `CashReceiptAdminTest` (lock from the row, one audit row naming the Admin, unchanged number/amount/currency/snapshots, tab then offers unlock and hides lock and edit; no lock for a cancelled receipt, a carried copy or a transfer payment), `CashReceiptLockTest` (cancelled and locked refused with no history row, Partner refused, edit refused after the lock, unlock reason length then edit succeeds, a queued confirmation sent after the hand lock attaches the receipt, leaves it locked and adds no `cash_receipt_locked` row, audit payload and label).

## Deviations from Plan

None - plan executed as written. Task 2 step 2 ("tighten the Action only where a test shows a gap") needed no change because no test could be run here.

## Verification status

Worktree checks only, per the project rules: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` and the hook (sensitive-content, gitleaks) clean on each commit. Not run here (no vendor, no DB): Pest, Pint, PHPStan. Post-merge for the orchestrator (no migration in this plan): `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptAdminTest.php tests/Feature/Invoicing/CashReceiptLockTest.php tests/Arch/CashReceiptUnlockPathTest.php tests/Arch/InvoiceUnlockPathTest.php tests/Feature/Schema/CashReceiptsTableTest.php`, then `tests/Feature/Invoicing/CashReceiptEditTest.php` and `CashReceiptDeliveryTest.php`, the plan gate (`pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`), `pint --test` and `phpstan analyse`.

Points worth watching in the post-merge run (untested here):
- Fixtures call `actingAs()` with their own admin; the new tests re-run `$this->actingAs($this->admin)` before asserting on causer ids.
- `MailFixtures::sent()` is assumed to return Symfony messages with `getAttachments()`, as `CashReceiptDeliveryTest` uses via `MailFixtures::to()`.
- The carried-copy case converts a proforma and calls `payments()->sole()` on the new invoice, as the existing test does.
- Pint may reorder imports in the touched files.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-16-15 to T-16-18). No migration, no composer change, no new endpoint.

## Self-Check: PASSED

- Files exist: LockCashReceipt.php, this SUMMARY.
- Commits 0427cb5, 0ac02b5 are ancestors of HEAD.
