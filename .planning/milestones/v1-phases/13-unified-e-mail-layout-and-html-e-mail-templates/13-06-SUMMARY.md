---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 06
subsystem: invoicing
tags: [mail, payment-confirmation, queue, idempotency, i18n]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: the payment_confirmations table, model and state enum from plan 13-02; MailText and the text layout from plan 13-04; the shared layout, MailBrand and MailFixtures from plan 13-01
provides:
  - QueuePaymentConfirmation, the row, the remaining snapshot, the recipient rule (recipientFor(), cleanAddress()) and the delayed after-commit dispatch
  - the hook in RecordPayment::recordLocked(), so the manual path, the card match and the Admin's assignment all confirm
  - SendPaymentConfirmation, the idempotent send job that never locks the invoice
  - PaymentConfirmationMail and its view, a Markdown message on the shared layout with no attachment
  - lang/cs and lang/en payment_mail.php with equal keys
affects: [13-07, 13-08, 13-09]

plan_head_before: 0dcf7c76d755e5208b9fc9904d66a356af498609
plan_head_after: e05c2d3b1bdde1e1f101708b7b038a96abe9dff1

actuals:
  tokens: 10300
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A queued job whose unit is its own log row claims it with one conditional update and writes nothing else, so it needs no invoice lock"
    - "The confirmation is queued inside the payment transaction with afterCommit and a delay, so a payment taken back within the grace window removes the unsent row by the foreign-key cascade"
    - "A Markdown view escapes every interpolated value with MailText::escapeMarkdown() before it enters a translated sentence; the text part is rendered from the same view by the published text layout"

key-files:
  created:
    - app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php
    - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
    - app/Domain/Invoicing/Mail/PaymentConfirmationMail.php
    - resources/views/mail/payment-confirmation.blade.php
    - lang/cs/payment_mail.php
    - lang/en/payment_mail.php
    - tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php
    - tests/Feature/Invoicing/PaymentConfirmationMailTest.php
  modified:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - tests/Arch/JobContractTest.php

key-decisions:
  - "R-13-06-1: QueuePaymentConfirmation::handle() writes the payment_confirmations row inside the payment transaction, takes the language from the client's invoice_language and the remaining amount from InvoiceBalances, and dispatches the job after commit with a 300 s delay; a client without a usable invoice e-mail gets a skipped row and no job"
  - "R-13-06-2: recordLocked() calls the confirmation after the payment-link reconcile; carry() does not, so the carried copy of a proforma payment sends nothing"
  - "R-13-06-3: SendPaymentConfirmation claims its row by one conditional update (queued to sending), takes no invoice lock, re-checks the stored recipient and ends a refused one as failed with invalid_recipient"
  - "R-13-06-4/5: PaymentConfirmationMail::forPayment() formats the amount, the date (j. n. Y in Czech, Y-m-d in English) and the remaining amount; the view escapes each value; the text part comes from the published text layout, so there is no separate text view"
  - "A-13-1, A-13-5, A-13-6 kept as planned: proformas confirm, no pay button, 300 s grace window"

requirements-completed: [P13-04, P13-05, P13-10, P13-11]

coverage:
  - id: D1
    description: "After a partial payment recorded by the Admin the client gets exactly one e-mail with the document number, the amount, the date and the remaining amount; the invoice stays open and the e-mail has no attachment"
    requirement: "P13-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#the tracer (Phase 13)"
        status: pass
    human_judgment: false
  - id: D2
    description: "A client without a usable invoice e-mail gets a skipped row and no message, and the payment is still stored"
    requirement: "P13-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#it records a client without an invoice e-mail as skipped"
        status: pass
    human_judgment: false
  - id: D3
    description: "The wording is right for a partial payment, a final payment and an overpayment, in Czech and in English (the client's language, also when the document is in the other language), in a foreign currency, and for a document number with Markdown characters, in both the HTML and the text part"
    requirement: "P13-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationMailTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The job is registered in the job contract and the existing suite stays green with the extra e-mail after every payment"
    requirement: "P13-11"
    verification:
      - kind: integration
        ref: "tests/Arch/JobContractTest.php"
        status: pass
      - kind: command
        ref: "ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Clients tests/Feature/Dashboard tests/Feature/Finance tests/Feature/Api tests/Isolation tests/Arch (2877 passed) and tests/Feature/Mail tests/Feature/Tasks tests/Feature/Operations (836 passed)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The path proofs of the de-duplication (webhook retry, double dispatch, a payment deleted within the grace window, the concurrent claim) are shown"
    requirement: "P13-05"
    verification:
      - kind: manual
        ref: "Plan 13-08 carries the path proofs; this plan proves the trigger, the content and the unique-key and claim design only"
        status: pending
    human_judgment: true
    rationale: "Planned for plan 13-08, not part of this plan's tests"
---

# Phase 13 Plan 06: Payment confirmation e-mail Summary

**Every payment credited through RecordPayment::recordLocked() now queues one confirmation to the client's invoice e-mail, in the client's language, after a five-minute grace window; it states the number, the amount, the date and the remaining amount (or "paid in full"), has no attachment, and never locks the invoice.**

## Performance

- **Tasks:** 2 (a tracer, then an auto/tdd task), 2 commits
- **Files:** 10 created or modified (8 new)
- **Completed:** 2026-10-23

## Accomplishments

- `QueuePaymentConfirmation` writes the one `payment_confirmations` row of the payment inside the payment transaction, with the remaining amount as a snapshot from `InvoiceBalances`, and dispatches the job with `afterCommit()` and a delay of `GRACE_SECONDS` (300). It exposes `recipientFor()` and `cleanAddress()` as the one recipient rule (also for the record dialog of plan 13-08).
- `RecordPayment::recordLocked()` calls it after the payment-link reconcile, so the Admin's manual payment, a matched Stripe card payment and the Admin's assignment of one all confirm; `carry()` does not.
- `SendPaymentConfirmation` claims its row by a conditional update, re-checks the stored recipient, sends unqueued, marks the row sent by a conditional update, and on a throw puts the row back to queued and hides the provider answer behind a fixed message. It names neither the lock state nor the invoice audit.
- `PaymentConfirmationMail` and `resources/views/mail/payment-confirmation.blade.php` render the Markdown message on the shared layout; the text part comes from the published text layout. The Czech and English texts have equal keys.
- The job is in `EXPECTED_APPLICATION_JOBS` of `tests/Arch/JobContractTest.php`.

## Task Commits

1. **Task 1: tracer** - `77a7071` (feat) - trigger, queueing, job, Mailable, view, strings, job registry, tracer test
2. **Task 2: wording and content** - `e05c2d3` (test) - 11 cases for both languages, partial, final, overpaid, EUR, Markdown characters, text part, key parity

## Deviations from Plan

None - plan executed exactly as written. The plan-level broad run needed no change to any existing test: plan 13-03 had already made the payment and invoicing tests select messages by recipient and subject (R-13-06-8 not triggered).

Notes (not deviations):
- Task 2's tests passed on first run because the Mailable, view and strings were complete from task 1; there was no RED step to commit separately.
- The first test commit attempt was refused by `scripts/check-sensitive.sh`, which read the fictional 8-digit document number as a company ID. The number was changed to `INV-0042` before the commit; nothing sensitive was ever committed.

**Total deviations:** 0.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Clients tests/Feature/Dashboard tests/Feature/Finance tests/Feature/Api tests/Isolation tests/Arch tests/Concurrency/PaymentDeletionConcurrencyTest.php tests/Concurrency/PaymentMatchConcurrencyTest.php`: 2877 passed.
- `ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Tasks tests/Feature/Operations`: 836 passed.
- Pint clean. PHPStan reports only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh` clean on both commits; acceptance greps of task 1 all pass.

## Known Stubs

None.

## Threat Flags

None. The new surface (a queued e-mail to the client's own invoice e-mail) is the one the plan's threat model lists (T-13-06 to T-13-11); nothing is installed (T-13-SC).

## Issues Encountered

None.

## Next Phase Readiness

Ready for 13-07 (same wave) and 13-08, which proves the de-duplication paths (webhook retry, double dispatch, deletion within the grace window, concurrent claim) and uses `QueuePaymentConfirmation::recipientFor()` in the record dialog.

## Self-Check: PASSED

All created files exist; commits `77a7071` and `e05c2d3` are ancestors of HEAD.
