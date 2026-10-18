---
phase: 11-stripe-payments
plan: 13
subsystem: payments
tags: [stripe, webhook, matching, idempotency, classification, notification]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "RecordPayment::recordLocked() with a null actor and the link reconcile (11-02, 11-10), the ProcessStripeWebhook job and handler seam (11-04), CardPayment::recordOnce() and its unique Stripe references (11-09), PaymentLink rows (11-06), test setup and event builders (11-01, 11-03, 11-04)"
provides:
  - "StripeEvent::fromPayload(): defensive reading of the stored event (id, type, created, session id, payment_status, payment_intent string, payment_link, positive amount_total, upper-cased currency, metadata ids kept only as UUIDs)"
  - "RecordCardPayment::handle(StripeEvent): ?CardPayment: classification (undetermined, cancelled, already_paid duplicate, currency, amount, matched) and the matched write in the job's transaction"
  - "StripeEventAction interface: a handler-map entry that receives the parsed event; ProcessStripeWebhook::handlers() maps checkout.session.completed and checkout.session.async_payment_succeeded to RecordCardPayment::class"
  - "CardPaymentMatchedNotification (database, Filament success format) and payment_notices.matched.* copy"
  - "tests/Support/StripeEvents::checkoutFor(PaymentLink, overrides, event) and sessionFor(PaymentLink, overrides)"
affects: [11-14, 11-15, 11-16, 11-18, 11-19, card-payments, matching]

actuals:
  tokens: 31000
  tasks: 3
  commits: 3
plan_head_before: cfc5408e7556a0c164b690a070d6ece86f254f86
plan_head_after: 92aa39b7d8d0dcfc54d1988a044e21d38fc24c32
commits: 3

tech-stack:
  added: []
  patterns:
    - "A queue handler reads the stored payload through one defensive value object and never through SDK objects"
    - "A record is claimed with INSERT ... ON CONFLICT DO NOTHING before any money is written; a null claim means a repeat and ends the handler"
    - "Admin notices of a transaction are registered with DB::afterCommit(), so a rolled-back write tells nobody"

key-files:
  created:
    - app/Domain/Payments/Stripe/StripeEvent.php
    - app/Domain/Payments/Actions/RecordCardPayment.php
    - app/Domain/Payments/Jobs/StripeEventAction.php
    - app/Domain/Payments/Notifications/CardPaymentMatchedNotification.php
    - tests/Feature/Payments/MatchPaymentTest.php
    - tests/Feature/Payments/PaymentClassificationTest.php
  modified:
    - app/Domain/Payments/Jobs/ProcessStripeWebhook.php
    - lang/cs/payment_notices.php
    - tests/Support/StripeEvents.php

key-decisions:
  - "R11-13-1 to R11-13-5 applied as written"
  - "Handler mismatch with plan 11-04 resolved by a second small interface, StripeEventAction (handle(StripeEvent): ?CardPayment), instead of an adapter class or a change of StripeEventHandler: the map accepts both kinds, the job passes the parsed event to an action and the stored row to a handler. RecordCardPayment::handle(StripeEvent) keeps the signature plans 11-16 and 11-18 name; plan 11-04's tests and ScriptedStripeHandler are untouched and green"
  - "A session must agree with the link it was found by (T-11-47): a Stripe link id on the session that differs from the link row's stripe_link_id is classified undetermined with no document, like metadata naming another document"
  - "The matched record is claimed first with no payment id and then updated with invoice_payment_id once the payment exists (the guard allows filling the link once)"

patterns-established:
  - "Plan 11-16 adds the duplicate and unmatched alerts as DB::afterCommit() calls inside RecordCardPayment::record() callers; the classification branches are the single place that decides the type and reason"

requirements-completed: [PA-06]

duration: 40min
completed: 2026-10-18
---

# Phase 11 Plan 13: Card payment matching Summary

**A signed Stripe checkout event from a Kokpit payment link now books the payment, the income and the paid state of the document in one transaction, switches the link off and tells every Admin; every other payment is kept as a duplicate or an unmatched record with its reason, and repeats never book twice.**

## Performance

- **Duration:** about 40 min, including two 4-minute suite runs
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 9 (6 created, 3 modified)

## Accomplishments

- Tracer: `StripeWebhookSigner::post()` of `StripeEvents::checkoutFor($link)` leaves one matched `card_payments` record (event, session, payment intent and link ids), one `invoice_payments` row (method card, no creator, 1 250 000 minor CZK), one income "Platba kartou faktury <number>" in the system category, remaining 0, the link deactivated with reason paid (and deactivated at the fake gateway), the webhook row processed, and one bell row "Přišla platba kartou" per Admin with the number and the amount only.
- `StripeEvent::fromPayload()` reads `amount_total` and `currency` of the session only (never presentment figures), upper-cases the currency, keeps the two metadata ids only when they are UUIDs, and turns every malformed value into null.
- `RecordCardPayment` classifies in the order of R11-13-2 and claims the record with `CardPayment::recordOnce()` first. A null claim (same event, async event of a recorded session, any event of a recorded payment intent, a redelivery after the matched payment was deleted) ends the handler with nothing written.
- A matched payment goes through `RecordPayment::recordLocked(..., null)` with the Prague date of the event, so a proforma stores a payment and no income, a foreign-currency invoice takes the CNB figure of the day, and a missing list throws a `ValidationException` that rolls the whole transaction back; the job's failure path records `failed_at` with class `validation` and nothing else remains.
- `CardPaymentMatchedNotification` is sent with `notifyNow` to each Admin from `DB::afterCommit()` and has its own wording for a proforma ("Záloha <number> byla zaplacena (...)").
- Verification: Payments, Invoicing, Finance, Arch, Isolation and Schema suites 1952 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. Task 1 (tracer): matched path, repeat, handler map, notification and builders - `6959eb5` (feat)
2. Task 2: repeats, async events, duplicates and a deleted matched payment - `40a0be8` (test)
3. Task 3: unmatched reasons, fallbacks, proforma and foreign currency - `92aa39b` (test)

The classification of all branches (duplicate, unmatched, fallbacks, proforma notice) was written in the tracer commit, because the classifier is one method whose order defines the result; Tasks 2 and 3 added the tests that pin each branch. No RED run was recorded for them.

## Decisions Made

See `key-decisions` in the frontmatter. In short: the 11-04 handler mismatch is solved by a second interface (`StripeEventAction`) next to `StripeEventHandler`; the session must agree with its link on the Stripe link id as well as on the document.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Session and link must agree on the Stripe link id**
- **Found during:** Task 1 (threat model T-11-47 review)
- **Issue:** The plan matches by `metadata.payment_link_row` and only compares the document of the metadata. A session of another product could name a valid link row and still be matched by amount alone.
- **Fix:** `RecordCardPayment::namesThisLink()` also requires the session's Stripe link id (when both it and the row's id exist) to equal the row's; otherwise the payment is unmatched `undetermined` with no document. Tested ("names the Stripe link of another link row").
- **Files modified:** `app/Domain/Payments/Actions/RecordCardPayment.php`, `tests/Feature/Payments/PaymentClassificationTest.php`
- **Commit:** `6959eb5`, `92aa39b`

### Choices within the plan's latitude

- The 11-04 interface mismatch: a new `StripeEventAction` interface in `app/Domain/Payments/Jobs/` (not in the plan's file list) instead of a thin adapter class or an amendment of `StripeEventHandler`; the job branches on the handler's kind. This keeps `RecordCardPayment::class` directly in the map, as the acceptance criterion requires, and the 11-04 tests unchanged.
- `StripeEvents::checkoutFor()` takes an optional third argument for the event envelope and a companion `sessionFor()` returns the session object alone, so a test can wrap the same session in the async event type.
- The failure test posts with a faked queue and runs the job by hand, like the 11-04 tests, because the sync queue would turn the thrown `ValidationException` into a 500 for the posting test.

## Auth Gates

None.

## Known Stubs

None. The refund type of `ProcessStripeWebhook::handlers()` stays unmapped until plan 11-16, as planned; an accepted `refund.created` is only marked processed.

## Threat Flags

None beyond the plan's threat model. T-11-45 (a payment booked twice): `recordOnce()` claim, document lock, one transaction, repeat tests; the concurrency proof is plan 11-18. T-11-46 (wrong amount or currency): session `amount_total` and `currency` against the link and the remaining amount; unmatched otherwise, tested for both. T-11-47 (crafted session on the same account): a match needs a Kokpit link row of the same document and agreeing Stripe link id; tested. T-11-48 (payer data in the notice): number and amount only, asserted against the stored bell row. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-16 raises the duplicate and unmatched alerts after commit from `RecordCardPayment::record()` callers (every branch that returns a non-matched record) and adds `RecordRefundEvent` as another `StripeEventAction` in the handler map.
- PA-06 is marked complete (matching, payment, paid, income, link off, Admin notified). PA-07 (alert on a duplicate) and PA-08 (alerts, overview filters, resolutions, pruning) stay open for plans 11-14, 11-16 and 11-19.

## Self-Check: PASSED

- Files: all 9 listed files exist.
- Commits: `6959eb5`, `40a0be8` and `92aa39b` are ancestors of HEAD.
