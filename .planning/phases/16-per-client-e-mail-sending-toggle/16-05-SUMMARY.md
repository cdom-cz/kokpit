---
phase: 16-per-client-e-mail-sending-toggle
plan: 05
subsystem: invoicing
tags: [payments, email, queue, job, migration, filament]
requires:
  - phase: 16-01
    provides: ClientDocumentEmails gate (enabledFor, enabledForInvoice, allows), InvoiceFixtures client override
  - phase: 16-02
    provides: manual lock of a document (the receipt of a skipped confirmation is closed by hand in 16-04)
provides:
  - payment_confirmations_recipient_check allowing a skipped row with or without a recipient
  - QueuePaymentConfirmation skipped row and no job for a client with document e-mails off
  - SendPaymentConfirmation skip path (after the claim, right before the transport call)
  - invoices.payments.record.confirmation_hint_disabled dialog hint
affects: [16-04]
tech-stack:
  added: []
  patterns:
    - fresh-read gate twice in a job (after the claim, last statement before the transport call)
    - skipped row keeps the address it would have gone to
key-files:
  created:
    - database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php
  modified:
    - app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php
    - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
    - app/Filament/Support/RecordPaymentAction.php
    - lang/cs/invoices.php
    - tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php
    - tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php
    - tests/Feature/Invoicing/PaymentConfirmationAdminTest.php
    - tests/Feature/Invoicing/CashReceiptDeliveryTest.php
key-decisions:
  - "The new CHECK is (recipient IS NULL AND state = 'skipped') OR (recipient IS NOT NULL AND btrim(recipient) <> ''); down() refuses while a skipped row keeps its recipient"
  - "The queue Action decides the row state and the dispatch from the client it already reads in the payment transaction; nothing else in the payment changes"
  - "The job reads the switch twice (after the claim and right before Mail::to) and ends the row skipped by a conditional update from sending; it never throws, never sends, never locks a receipt"
requirements-completed: [P16-04, P16-05, P16-06]
duration: 30min
completed: 2026-10-28
status: complete
actuals:
  tokens: 7500
  tasks: 2
  commits: 2
plan_head_before: af0c4c999de0c328d20147fc5e7b4c8a98a3b563
plan_head_after: 7a74ee583a27f346589bf00dd10242b3cc164562
---

# Phase 16 Plan 05: Skipped payment confirmation Summary

A payment of a client with document e-mails switched off is recorded as always, but its confirmation row is written directly as `skipped` with the address kept and no job queued; the record dialog says so up front; and a confirmation still waiting in its grace window when the Admin switches the client off is skipped by its job, which sends nothing and locks no cash receipt.

`commits: 2` counts the code commits; the SUMMARY commit follows `plan_head_after`.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: relaxed recipient check, skipped row and no job, dialog hint, tests | 4807ffc |
| 2 | Job skip path (after the claim and right before the transport call), queued-then-off and read-window tests | 7a74ee5 |

## What was built

- Migration `2026_10_28_000300_relax_payment_confirmation_recipient_check.php` drops and re-adds `payment_confirmations_recipient_check`: a null recipient only on a skipped row, a non-blank recipient on every row. Header names D-06, D-07 and Pitfall 2. `down()` throws a `RuntimeException` while a skipped row keeps a recipient, else restores the original check verbatim.
- `QueuePaymentConfirmation::handle()`: `$enabled = ClientDocumentEmails::allows($client)` on the fresh client; state is `Skipped` for no recipient or switch off; the cleaned recipient is stored either way; the job is dispatched only with a recipient and the switch on. Docblock paragraph added.
- `SendPaymentConfirmation`: private `skippedBySwitch()` (conditional update `sending` to `skipped`, `@phpstan-impure`), called as the first statement in the `try` and as the statement right before `Mail::to()`. `#[Idempotent]` text and docblock updated, the AS-10 sentence now names the switch and the manual lock.
- `RecordPaymentAction::confirmationHint()` asks `ClientDocumentEmails::enabledFor()` first and answers the new key `confirmation_hint_disabled`.
- Tests: schema case rewritten to the new contract (allowed skipped with and without recipient, still refused: queued without recipient, blank recipient on skipped and queued); dialog hint case; hand-served payment (row skipped, address kept, no job, payment and income stored, cash receipt issued and open, no-address client, enabled client still queued); job cases (queued then off then on again, enabled client still sends, switch flipped while the payment is read) in both the trigger and the cash receipt tests.

## Deviations from Plan

None in substance. The blank line between the second `skippedBySwitch` block and `Mail::to(` was left out so the plan's acceptance grep (`grep -B 3 "Mail::to("` finds `skippedBySwitch(`) holds; the check is still the statement directly before the transport call.

## Verification status

Worktree only, per the project rules: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) clean on both commits. Not run here (no vendor or DB): migration, Pest, Pint, PHPStan. Post-merge for the orchestrator, in this order: `ddev exec php artisan migrate`, then the Task 1 and Task 2 Pest commands, and the plan gate (`pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`, `pint --test`, `phpstan analyse`).

Points worth watching in the post-merge run (untested here):
- The read-window tests rely on the job's `InvoicePayment::query()->findOrFail()` firing `eloquent.retrieved` between the two checks, and on nothing before the first check retrieving an `InvoicePayment` (the gate uses `value()` reads, no models).
- The schema case inserts a skipped row, deletes it and inserts the second shape, since `expectAllowed` commits and the table has one row per payment.
- `$payment->transaction()->count()` in the hand-served test assumes the income transaction is visible to the acting Admin.
- Pint may reorder the new `use` lines; they were placed alphabetically.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-16-19 to T-16-22 are covered by the job's double check, the CHECK and its schema test, the queue Action that only chooses state and dispatch, and the shared gate in the hint). No composer change, no new endpoint.

## Self-Check: PASSED

- Files exist: migration, QueuePaymentConfirmation.php, SendPaymentConfirmation.php, this SUMMARY.
- Commits 4807ffc and 7a74ee5 are ancestors of HEAD.
