---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 08
subsystem: invoicing
tags: [mail, payment-confirmation, filament, admin-ui, path-proofs, idempotency]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: the payment_confirmations table, model and state enum (13-02); QueuePaymentConfirmation, SendPaymentConfirmation and the Mailable (13-06); the escaped alert mails (13-07)
provides:
  - InvoicePayment::confirmation(), a read-only HasOne relation to the confirmation row
  - PaymentConfirmationState labels (HasLabel) in lang/cs/enums.php
  - the "Potvrzení klientovi" column of the Platby tab (state badge, dash when there is no row)
  - the record-dialog hint that names the invoice e-mail the client will be mailed at, or says nothing is sent
  - path proofs for the card match, replay, duplicate, assignment, proforma, carried copy, grace-window deletion, rollback, two payments in a row, no lock, and the failure handling of the send job
affects: [13-09]

plan_head_before: 717c225e57ca7dca919660123b66f80825afb76b
plan_head_after: c6ea26a4c35de698b323a80024aa62d338d566a3

actuals:
  tokens: 9500
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "The dialog hint asks the recipient rule the queueing itself uses (QueuePaymentConfirmation::recipientFor), so it cannot promise a mail the write will not queue"
    - "A queued-job path test holds the queue back with Queue::fake(), records through the real action, then runs one try of the job through its own middleware"

key-files:
  created:
    - tests/Feature/Invoicing/PaymentConfirmationAdminTest.php
  modified:
    - app/Domain/Invoicing/Models/InvoicePayment.php
    - app/Domain/Invoicing/Enums/PaymentConfirmationState.php
    - lang/cs/enums.php
    - lang/cs/invoices.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - app/Filament/Support/RecordPaymentAction.php
    - tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php
    - tests/Feature/Payments/CardPaymentJourneyTest.php
    - app/Domain/Notifications/Mail/MailText.php
    - tests/Feature/Mail/MailThemeTest.php

key-decisions:
  - "R-13-08-1 to R-13-08-4 as planned: confirmation() relation, labels (queued and sending read 'Čeká na odeslání', sent 'Odesláno', failed 'Neodesláno', skipped 'Bez e-mailu'), a gray badge column with success for sent and danger for failed, and a gray hint line above the 'not editable' line"
  - "A-13-3 and A-13-7 kept: back-filled payments are confirmed too, with the dialog saying so beforehand; the tab shows the state per payment"
  - "R-13-08-5 used once, outside the two production classes named by the plan: the shared Markdown escape of plan 13-07 dropped the no-break spaces of formatted amounts; fixed in MailText"

requirements-completed: [P13-05, P13-11]

coverage:
  - id: D1
    description: "The Admin is told in the record dialog whom the client e-mail reaches (or that none is sent), saving is never blocked, and the Platby tab then shows the confirmation as sent, 'Bez e-mailu' or a dash"
    requirement: "P13-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationAdminTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "A matched Stripe card payment mails exactly one confirmation; a replayed event and a duplicate payment of a paid document mail none; the Admin's assignment of a card record mails one"
    requirement: "P13-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/CardPaymentJourneyTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#the paths (Phase 13)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A proforma payment is confirmed and its carried copy is not; a payment deleted inside the grace window sends nothing and the late job does not fail; a CNB refusal leaves no row and no e-mail; two payments each carry their own remaining amount"
    requirement: "P13-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#the paths (Phase 13)"
        status: pass
    human_judgment: false
  - id: D4
    description: "A refused transport puts the row back to queued and, once the queue gives up, marks it failed with a fixed class and no provider text; a refused stored recipient ends as invalid_recipient; a finished row is never sent again; a sent confirmation never locks the invoice"
    requirement: "P13-11"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#the job (Phase 13)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php#keeps the invoice open and unaudited by a sent confirmation"
        status: pass
    human_judgment: false
---

# Phase 13 Plan 08: Payment confirmation visible to the Admin and proven on every path Summary

**The Platby tab shows the confirmation state of each payment, the record dialog says whom the client will be mailed at (or that nobody will be), and tests prove one confirmation per real payment on the card, assignment, proforma, deletion and failure paths without ever locking the invoice.**

## Performance

- **Tasks:** 3 (a tracer, then two auto/tdd tasks), 4 commits (the fourth is a defect fix found by the broad run)
- **Files:** 11 created or modified (1 new)
- **Completed:** 2026-10-23

## Accomplishments

- `InvoicePayment::confirmation()` (read-only `HasOne`), eager-loaded by the Platby tab together with `transaction.category`. `PaymentConfirmationState` implements `HasLabel` with Czech labels.
- The tab column `Potvrzení klientovi` shows the state as a badge (success for sent, danger for failed, gray otherwise) and a dash for a payment without a row (a carried copy, or one recorded before this phase). It prints no recipient and no failure class.
- The record dialog shows a gray hint above the "not editable" line: "Klientovi odejde na :email potvrzení přijetí platby." or "Klient nemá použitelný fakturační e-mail, potvrzení platby se neodešle.". It asks `QueuePaymentConfirmation::recipientFor()`, so it follows the rule the queueing uses, and never blocks the save.
- The Stripe journey now proves one confirmation (paid-in-full wording, no attachment, a sent row) for the matched payment, none for the very same event posted again and none for the duplicate payment of the paid document.
- `PaymentConfirmationTriggerTest` gained the paths (two payments in a row, the queued job with its grace delay, deletion inside the grace window, delete-then-rerecord mailing only the corrected payment, a CNB refusal, a proforma and its carried copy, the Admin's assignment, no lock and no `invoice_locked` history, and a permanent source guard on the job) and the job (retry then fixed failure class, `unexpected`, `invalid_recipient`, no resend of a sent row, a non-UUID id).

## Task Commits

1. **Task 1: tracer** - `4795872` (feat) - relation, labels, column, dialog hint, strings, `PaymentConfirmationAdminTest`
2. **Task 2: the paths** - `b7b8816` (test) - trigger paths and the journey additions
3. **Task 3: the job** - `36cec12` (test) - failure handling of the send job
4. **Defect fix** - `c6ea26a` (fix) - the Markdown escape keeps no-break spaces

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The Markdown escape collapsed the no-break spaces of formatted amounts**
- **Found during:** the plan-level broad run (`tests/Feature/Payments`), after task 3
- **Issue:** `MailText::escapeMarkdown()` (plan 13-07) collapsed every Unicode white space, including U+00A0 and U+202F, into a plain space. The payment and refund alert e-mails (`OperationalAlert` now escapes each line) therefore lost the no-break space inside amounts such as `12 500,00 Kč`, so an amount could wrap inside the mail, and six tests of `PaymentAlertsTest` and `RefundEventTest` that compare the text part with the formatted amount failed. Plan 13-07 had not run the Payments suite.
- **Fix:** the collapse now leaves U+00A0 and U+202F alone and still folds other white space and line breaks to one space. A test in `MailThemeTest` pins it. No test was bent.
- **Files modified:** `app/Domain/Notifications/Mail/MailText.php`, `tests/Feature/Mail/MailThemeTest.php`
- **Commit:** `c6ea26a`

The path proofs of tasks 2 and 3 found no defect in `QueuePaymentConfirmation` or `SendPaymentConfirmation`; neither file changed.

Notes (not deviations):
- The tests of tasks 2 and 3 passed on first run because plan 13-06 had built the production code completely; there was no RED step to commit.
- `payment_confirmations` rows and a payment without a confirmation are written as the system in tests; `invoice_payments_creator_check` requires a creator, so the dash fixture carries the Admin as creator.
- `tests/Isolation/TimeLeakTest.php` failed once in one broad run and passed alone and in earlier broad runs; it is order-dependent or flaky and unrelated to this plan.

**Total deviations:** 1 auto-fixed bug.

## Verification

- `ddev exec vendor/bin/pest` on the plan's task suites plus `tests/Arch` and the two payment concurrency tests: 164 passed.
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Isolation tests/Arch tests/Feature/Localisation`: 1729 passed and the 6 alert failures described above, which are fixed and then green (`tests/Feature/Mail tests/Feature/Payments tests/Feature/Operations tests/Feature/Tasks tests/Feature/ExchangeRates tests/Isolation`: 1879 passed, with only the flaky TimeLeakTest case noted above).
- Pint clean. PHPStan reports only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh` clean on all four commits.

## Known Stubs

None.

## Threat Flags

None. The column and the hint live in the Admin-only tab and the Admin's dialog (T-13-18); the path proofs cover T-13-19 to T-13-21; nothing is installed (T-13-SC).

## Issues Encountered

The alert regression described under Deviations; resolved.

## Next Phase Readiness

Ready for 13-09. The record dialog and the Platby tab need no further work for the confirmation.

## Self-Check: PASSED

The created test file exists; commits `4795872`, `b7b8816`, `36cec12` and `c6ea26a` are ancestors of HEAD.
