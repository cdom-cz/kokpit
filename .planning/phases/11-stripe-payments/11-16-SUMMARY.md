---
phase: 11-stripe-payments
plan: 16
subsystem: payments
tags: [alerts, admin-notices, refund, webhook, after-commit, throttle]

requires:
  - phase: 11-stripe-payments
    provides: "RecordCardPayment and the handler map of ProcessStripeWebhook (11-13), CardPaymentResource view page (11-11), CardPayment::recordOnce() and the type, reason and tab enums (11-09), the payment_notices lang file and the link-failed alert pattern (11-07)"
provides:
  - "App\\Domain\\Payments\\Support\\CardPaymentAlerts: duplicate(), unmatched(), refund() and raise(CardPayment); each sends one AdminAlerter alert keyed 'card-payment:<record id>' with the UI-SPEC Surface I copy and an 'Otevřít platbu' action to the record view"
  - "RecordCardPayment sends the duplicate or unmatched notice through DB::afterCommit()"
  - "App\\Domain\\Payments\\Actions\\RecordRefundEvent (StripeEventAction): records refund.created as a refund record linked to the payment, document and link it concerns, writes nothing else, alerts after the commit"
  - "StripeEvent refund fields (refundId, refundAmountMinor, refundCurrency); handler map entry 'refund.created'"
  - "StripeEvents::refundCreated(paymentIntent, amount, currency) test builder"
affects: [11-17, 11-18, 11-20, payments-overview, dashboard-card]

actuals:
  tokens: 8800
  tasks: 3
  commits: 3
plan_head_before: 391df37b1d3e07cecdc54d45acfda46d42faa3e2
plan_head_after: cb54857f0ef872d70352ca76f58c71d99b0c2132
commits: 3

tech-stack:
  added: []
  patterns:
    - "A notice builder that keys the AdminAlerter throttle by record id, so no two records ever merge into one notice"
    - "Alerts registered with DB::afterCommit() inside a handler that runs in the webhook job transaction: a rolled-back classification sends nothing, a delivered one never waits for the queue"

key-files:
  created:
    - app/Domain/Payments/Support/CardPaymentAlerts.php
    - app/Domain/Payments/Actions/RecordRefundEvent.php
    - tests/Feature/Payments/PaymentAlertsTest.php
    - tests/Feature/Payments/RefundEventTest.php
  modified:
    - app/Domain/Payments/Actions/RecordCardPayment.php
    - app/Domain/Payments/Stripe/StripeEvent.php
    - app/Domain/Payments/Jobs/ProcessStripeWebhook.php
    - lang/cs/payment_notices.php
    - tests/Support/StripeEvents.php

key-decisions:
  - "R11-16-1 to R11-16-4 applied as written. The alert is raised from RecordCardPayment::record() for every non-matched record it claims, so each current and future duplicate or unmatched branch is covered without a call per branch."
  - "StripeEvent reads a refund object (object = 'refund') into its own refund fields and leaves sessionId null for it, so a refund object's id is never mistaken for a checkout session id."
  - "A refund record keeps invoice_payment_id empty (the column is unique per payment and belongs to the booked payment); the link to the booked payment goes through the payment intent, the document and the link row."

patterns-established:
  - "Test files of the Payments suite use prefixed global helper names (pa..., rf...) because Pest helper functions share one namespace"

requirements-completed: []

duration: 40min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 16: Admin alerts for duplicates, unmatched payments and refunds Summary

**Every duplicate payment, unmatched payment and Stripe refund event now reaches every Admin in the bell and by e-mail with a link to its record, one notice per record, sent after the commit; refund events are recorded without any change to the document, the payment or the income.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 9 (4 created, 5 modified)

## Accomplishments

- `CardPaymentAlerts` sends "Duplicitní platba kartou", "Nespárovaná platba kartou" and "Stripe oznámil vrácení platby" through `AdminAlerter` with the Surface I bodies, the action "Otevřít platbu" to `CardPaymentResource::getUrl('view')` and the mail subject "Kokpit: :title". The body holds only the document number (duplicate), the amount in the payment's own currency and the reason label (unmatched); no Stripe id, payer name, e-mail or provider text appears in a bell row or a mail.
- The throttle key is `card-payment:<record id>`: three payments of a paid document inside a minute give two duplicate notices to each Admin, none merged and none carrying the "held back" line.
- `RecordCardPayment` registers the alert with `DB::afterCommit()` for each duplicate and unmatched record it claims. A matched payment keeps its bell-only notice from 11-13 and raises no mail. A classification inside a transaction that is then rolled back sends no mail.
- `RecordRefundEvent` finds the non-refund card payment of the refund's payment intent, locks its document, and calls `CardPayment::recordOnce()` with the refund id and amount; a repeat of the event or of the refund id returns null, a second refund (new id) is a second record, and a refund of an unknown payment intent is stored with no document and still alerted. The test compares the invoice row, its payments, its incomes and its link before and after: unchanged.
- The refund record shows in the "Vrácení" and "Nevyřešené" tab conditions of the overview.

## Task Commits

1. **Task 1 (tracer): duplicate alert** - `e833390` (feat)
2. **Task 2: unmatched alert, per-record notices, matched and rollback cases** - `cc83fbd` (test)
3. **Task 3: refund event** - `cb54857` (feat)

The tracer gate was re-run end to end after Task 1 (duplicate through the signed webhook, bell and mail for every Admin) before Tasks 2 and 3 expanded on it.

## Deviations from Plan

None - plan executed as written. Two notes that are not deviations:

- The unmatched notice and the after-commit wiring were implemented together with the duplicate in Task 1 (one `record()` hook covers both), so Task 2 added tests only; its commit is typed `test`.
- Task 2's "forced exception inside the handler after recordOnce" is exercised by running the handler inside a transaction that throws right after the record was claimed; the handler's own last step is the claim, so the surrounding transaction is where a late failure (for example the processed marker) lands in production.

## Issues Encountered

- A third Admin existed besides the two the test created (three mails arrived), so the tests read the Admin list from the database instead of assuming a count.
- Formatted amounts use a no-break space, so the expected bodies are built with `Money::format('cs')` and the human-readable figure is asserted separately after whitespace normalisation.

## Known Stubs

None.

## Threat Flags

None. Mitigations T-11-55 (per-record throttle key, two-duplicate test), T-11-56 (fixed copy, assertions against Stripe ids, the payer e-mail and name and the client name), T-11-57 (refund writes only its record, books compared) and T-11-58 (synchronous alerts after the commit) are implemented and tested. T-11-SC: nothing installed.

## Requirements

PA-07 and PA-08 are left open: this plan delivers the alerts and the refund record, but the requirement wordings also depend on the overview, resolutions, pruning and dashboard pieces closed out by the later plans of the phase (11-20 verifies them as a whole).

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Operations tests/Isolation tests/Arch`: 1184 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set; lefthook (sensitive-content, gitleaks) passed on each commit.

## Self-Check: PASSED

- FOUND: app/Domain/Payments/Support/CardPaymentAlerts.php, app/Domain/Payments/Actions/RecordRefundEvent.php, tests/Feature/Payments/PaymentAlertsTest.php, tests/Feature/Payments/RefundEventTest.php
- FOUND commits: e833390, cc83fbd, cb54857
