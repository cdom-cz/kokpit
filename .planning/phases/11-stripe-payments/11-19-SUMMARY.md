---
phase: 11-stripe-payments
plan: 19
subsystem: payments
tags: [stripe, card-payments, manual-resolution, filament, record-payment, expense]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "RecordPayment::recordLocked, PaymentFacts and the card method (11-02), the reconcile of the payment link inside recordLocked (11-10), CardPayment with its resolution columns and guard trigger (11-09), ViewCardPayment, CardPaymentResource::canAccess() and CardPaymentFixtures (11-11)"
provides:
  - "AssignCardPayment: a duplicate or unmatched card payment is booked to an issued, not cancelled invoice or proforma of the same currency through RecordPayment::recordLocked (method card, the Admin as creator), and the record becomes resolved as assigned in the same transaction"
  - "ResolveCardPayment: a duplicate, unmatched payment or refund event is closed with a required note of 1 to 500 characters; no money moves"
  - "RecordPaymentReturn: a manually entered expense of at most the payment amount through RecordTransaction, linked from the record (return_transaction_id); no Stripe call"
  - "Header actions assignCardPayment, resolveCardPayment and recordPaymentReturn on the record page with the UI-SPEC modals, live warnings, counters, toasts and the card-payment-resolved event"
  - "lang keys card_payments.actions.*, .assign.*, .resolve.*, .return.*, .errors.*"
affects: [11-20, card-payments, dashboard-card, payments-overview]

actuals:
  tokens: 15700
  tasks: 3
  commits: 3
plan_head_before: 792848d10610e3b9d21b9fd64a1bba27701fc606
plan_head_after: 94fbd9b79bed20ab7a061911848d4bff93ec7feb
commits: 3

tech-stack:
  added: []
  patterns:
    - "A resolution Action locks the document first and the record second, refuses a resolved record under the lock with the key card_payment, and the page turns that key into a danger toast and a refresh while every other key goes back under its modal field"
    - "A header action stays callable while its modal is open even if the record turned ineligible, so a stale submit reaches the Action and its refusal instead of being dropped silently by Filament"
    - "A manual payment input is reused for a card payment by validating with PaymentInput and building PaymentFacts with the card method afterwards"

key-files:
  created:
    - app/Domain/Payments/Actions/AssignCardPayment.php
    - app/Domain/Payments/Actions/ResolveCardPayment.php
    - app/Domain/Payments/Actions/RecordPaymentReturn.php
    - tests/Feature/Payments/CardPaymentResolutionTest.php
  modified:
    - app/Filament/Resources/CardPaymentResource/Pages/ViewCardPayment.php
    - lang/cs/card_payments.php

key-decisions:
  - "R11-19-1 to R11-19-4 applied as written"
  - "The assign select lists documents that still owe something plus the record's own document even when it is paid, because a duplicate is by definition the payment of a paid document; this keeps the empty text 'Žádný nezaplacený doklad ve stejné měně' true"
  - "The note of the assign modal is the note of the payment, as ruled; the record itself keeps no resolution note for an assignment"
  - "The 500 character limit of the notes is a validation rule with a live counter, not the maxLength input attribute, so a longer paste is a field error and the browser never cuts it silently"
  - "A return expense takes the client of the record's document only while that client still exists, because an archived client cannot take a new transaction and the return must stay recordable"

patterns-established:
  - "Filament drops the submit of a hidden action silently (isDisabled is true for a hidden action); an action that must answer a stale submit has to stay visible while its modal is mounted"
  - "In Livewire tests the modal of an action is not part of the page html; the form is read through instance()->getSchema('mountedActionSchema0')"

requirements-completed: []

duration: 55min
completed: 2026-10-18
---

# Phase 11 Plan 19: Manual resolution of card payments Summary

**The Admin closes every open card payment with one of three final resolutions on the record page: assign it to an invoice or proforma (payment and income booked through the RecordPayment rules), mark it resolved with a note, or record the money return as a linked expense, with no Stripe call.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 6 (4 created, 2 modified)

## Accomplishments

- Tracer: an unmatched payment of 12 000,00 Kč is assigned to its preselected invoice; one card payment with the Admin as creator, one income in the system category dated the received day, the invoice paid, the record resolved as assigned with `assigned_invoice_id` and `invoice_payment_id`, the toast "Platba byla přiřazena k dokladu <number>", the event `card-payment-resolved`, and the unresolved count down by one.
- Resolve and return: the note path on a duplicate, an unmatched payment and a refund event creates no payment and no transaction; a blank note is a field error. A return of 12 500,00 Kč on a duplicate books one CZK expense "Vrácení platby kartou" with the client of the document, linked from the record, the Stripe fake records no call, and 12 500,01 is the field error "Částka vrácení nesmí být vyšší než částka platby."; a part of the amount is accepted.
- Offers by type: assign accent on unmatched and gray on duplicate, resolve accent on duplicate and refund and gray on unmatched, return gray on duplicate and unmatched only; a refund offers only the note, a matched or resolved record nothing.
- Finality: three stale tabs (resolve, assign, return) after the first resolution each get the danger toast "Platba už byla vyřešena" (see Deviations for the Filament detail); the page shows the resolved banner and no actions, and the record and the books are unchanged by the later submits. Every Action refuses a resolved record with `already_resolved`, a matched or refund record with its own refusal.
- Currency and document: a EUR payment is offered only EUR documents (never a CZK one, never a cancelled one); with none the select reports "Žádný nezaplacený doklad ve stejné měně" and the modal submit is disabled; a forged CZK `invoice_id` is refused with "Měna platby (EUR) nesouhlasí s měnou dokladu." and nothing is written. A paid document warns "Doklad je již zaplacený. Platba se zapíše jako další." and records the payment; a differing amount warns and records in full; a cancelled document is refused with `payment_not_allowed`; a draft, an unknown or a missing id is the document error.
- Notes and Admin: 501 characters is a field error in the resolve and the return modal and in both Actions, 500 is accepted; a Partner gets `AuthorizationException` from all three Actions and 403 on the record page.
- Verification: Payments, Isolation, Arch, Invoicing and Finance suites 1510 passed (14002 assertions); Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. Task 1 (tracer): assign an unmatched payment to its invoice - `de84d33` (feat)
2. Task 2: resolve with a note and record the money return - `20bf25e` (feat)
3. Task 3: single resolution, currency, notes and Partner refusals - `94fbd9b` (feat)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A hidden action silently dropped a stale submit**
- **Found during:** Task 3 (two-tab test)
- **Issue:** Filament treats a hidden action as disabled, so `callMountedAction` on a tab whose record was resolved elsewhere returned without calling the action: no toast "Platba už byla vyřešena", only a re-render. That broke the U-11 contract for the stale submit.
- **Fix:** `ViewCardPayment::offers()` keeps an action visible while its own modal is mounted. This opens nothing: every Action re-checks the Admin Gate and the resolution under the record lock, and the page re-checks `canAccess()`.
- **Files modified:** app/Filament/Resources/CardPaymentResource/Pages/ViewCardPayment.php
- **Commit:** 94fbd9b

### Other notes

- The Czech copy of all three modals and the errors was written in one go and sits in the Task 1 commit (`de84d33`); the Resolve and Return Actions followed in the Task 2 commit.
- The tests re-act as the test's Admin before opening the record page, because the document fixtures log in a fresh Admin of their own.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, no new schema; the three Actions sit behind the existing Admin-only policy of `CardPayment`.

## Issues Encountered

None beyond the deviation above.

## Next Phase Readiness

The record page now closes every open record, so the payments list and the dashboard card (which listen for `card-payment-resolved`) can drop resolved records live. Plan 11-16 produces real refund records one wave later; the refund path here was tested with `CardPaymentFixtures::refund()`.

## Self-Check: PASSED
