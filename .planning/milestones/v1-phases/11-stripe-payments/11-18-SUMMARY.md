---
phase: 11-stripe-payments
plan: 18
subsystem: payments
tags: [concurrency, webhook, idempotency, locking, mutation-twin, pest]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "ProcessStripeWebhook and RecordCardPayment with the document lock (11-13), WebhookCall::storeStripeEvent() (11-04), RecordPayment::recordLocked() (11-02), card payment records with unique Stripe references (11-09), payment links (11-06, 11-07), the Phase 10 concurrency harness (10-35, 10-41)"
provides:
  - "tests/Concurrency/payment-match-worker.php: a worker process with the modes match, store, manual and skip-lock, behind a barrier, printing one JSON result line"
  - "tests/Support/UnlockedCardPaymentMatcher.php: the test-only twin of the card payment match without the document lock"
  - "tests/Concurrency/PaymentMatchConcurrencyTest.php: five scenarios of five rounds (or one mutation run) with real parallel processes, complete cleanup of the guarded tables and a check that every guard trigger is enabled again"
affects: [11-20, concurrency, card-payments]

actuals:
  tokens: 9600
  tasks: 2
  commits: 2
plan_head_before: 2c3ef9bf9ae3862df7895b861dca1aa9b9704027
plan_head_after: 9762f039d1ddf7594899795678a198b106ba09d1
commits: 2

tech-stack:
  added: []
  patterns:
    - "A worker tells a processed event from an ignored one by listening for its own UPDATE of webhook_calls.processed_at, because the job returns nothing"
    - "A mutation twin reads without a lock before the barrier, waits for the other worker's commit and then writes, so the race is deterministic instead of timing based"

key-files:
  created:
    - tests/Concurrency/payment-match-worker.php
    - tests/Concurrency/PaymentMatchConcurrencyTest.php
    - tests/Support/UnlockedCardPaymentMatcher.php
  modified: []

key-decisions:
  - "R11-18-1 and R11-18-2 applied as written. The worker installs the in-memory Stripe gateway, so the link deactivation that follows a payment never reaches Stripe, and it receives CACHE_STORE=array, MAIL_MAILER=array and QUEUE_CONNECTION=sync from the test"
  - "An ignored event is recognised by the absence of the worker's own processed_at update, a processed one by the type (and reason) of the card payment record of its event id"

patterns-established:
  - "Cleanup of the card payment tables: card_payments, then payment_links, then webhook rows of the test's event ids, with the user triggers of all eight guarded tables disabled and enabled inside one transaction; notifications of the test Admin are deleted too"

requirements-completed: []

duration: 45min
completed: 2026-10-18
---

# Phase 11 Plan 18: Card payment matching under real concurrency Summary

**Two different card payments of one invoice processed by two real processes at the same moment end in one matched record with one payment and one income and one duplicate without income, in every round; double delivery, double processing and a manual payment racing a card payment keep the books consistent; a twin without the document lock books two payments and proves the harness would notice.**

## Performance

- **Duration:** about 45 min, mostly five runs of the suite at about 2 minutes each
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 3 created

## Accomplishments

- Tracer: five rounds of two `match` workers on two events of different paid sessions of one invoice link end in exactly `duplicate` and `matched`, one payment, one income, one matched and one duplicate record, both webhook rows processed, remaining 0, and no `db:` or `error:` line (no 23505, no 40P01).
- Two `store` workers with the same payload end in `stored:new` and `stored:existing` and one webhook row, in all rounds.
- Two `match` workers on the same stored row end in `matched` and `ignored`, one record, one payment, one income.
- A `match` worker against a manual 1 000 payment ends in one of the two serialised orders (`matched` then `recorded`, an overpaid invoice; or `recorded` then `unmatched:amount`); in both, one income per invoice payment, income amounts equal the payment amounts and the paid sum equals the payments.
- Mutation twin: `skip-lock` against `match` on two sessions of one invoice ends with two matched records, two payments and two incomes; the test requires this in at least one of five rounds, as the plan says.
- The suite ran twice in a row green with all five tests and left no row in `card_payments`, `webhook_calls`, `payment_links`, `invoices` or `notifications` of the shared test database; every guard trigger was enabled afterwards. Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.

## Task Commits

1. Task 1 (tracer): worker, test harness, first scenario - `89c0b52` (test)
2. Task 2: store, double processing, manual race and the mutation twin - `9762f03` (test)

## Deviations from Plan

### Choices within the plan's latitude

- `UnlockedCardPaymentMatcher` (a Task 2 file) and all four worker modes were committed with Task 1, because the worker names the matcher class and PHPStan would otherwise flag an unknown class at the first commit. Task 2 then added only the tests of the other scenarios.
- The matcher has no separate "signal" step: it reads the document without a lock before the barrier, calls a barrier callback, and waits for another worker's committed matched record. The worker's start barrier and that wait give the same determinism without a second channel.
- Payload files of the `store` workers live in a temporary directory removed by the cleanup.

### Auto-fixed Issues

None.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-61 (two card payments booked as matched): proven absent by the five locked rounds and detectable by the twin. T-11-62 (deadlocks between the webhook job and a manual payment): no round of the manual race ended in 40P01 or another database error. T-11-SC: this plan changed no composer file.

## Notes for later plans

- PA-05, PA-06 and PA-07 are not marked complete by this plan: it proves their concurrency properties but the endpoint (PA-05) and the alert path (PA-07) belong to other plans.
- The suite takes about two minutes because each round waits for a 5 s barrier, like the other Phase 10 concurrency suites.

## Self-Check: PASSED

- Files: `tests/Concurrency/payment-match-worker.php`, `tests/Concurrency/PaymentMatchConcurrencyTest.php` and `tests/Support/UnlockedCardPaymentMatcher.php` exist.
- Commits: `89c0b52` and `9762f03` are ancestors of HEAD.
