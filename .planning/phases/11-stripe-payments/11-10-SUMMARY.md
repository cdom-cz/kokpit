---
phase: 11-stripe-payments
plan: 10
subsystem: payments
tags: [stripe, payment-link, reconcile, sweep, scheduler, regenerate, queue-job]

requires:
  - phase: 11-stripe-payments
    provides: "RecordPayment::recordLocked (11-02), the Admin-only payment_links record and its guard (11-06), CreatePaymentLink and FakeStripeGateway (11-07)"
provides:
  - "App\\Domain\\Payments\\Support\\PaymentLinks: reconcile(Invoice $locked) keeps the one invariant that a live link offers what is still owed, and deactivate(PaymentLink, reason) is the one local deactivation that queues the remote call after the commit"
  - "reconcile() called from RecordPayment::recordLocked, IssueCreditNote::partial and storno, and DeletePayment::handle, each in its own transaction"
  - "App\\Domain\\Payments\\Jobs\\DeactivatePaymentLinks: remote deactivation, targeted by link id and as the five-minute sweep (schedule kokpit-payment-link-sweep, one server)"
  - "App\\Domain\\Payments\\Actions\\RegeneratePaymentLink and DeactivatePaymentLink for the Admin, with the refusal copy payment_links.errors.not_regenerable, preparing and no_active_link"
affects: [11-12, 11-13, 11-16, 11-19, payment-links, pay-page, reconcile]

actuals:
  tokens: 9800
  tasks: 3
  commits: 3
plan_head_before: 8762e5aa0a5daa62a13939bced636fbd7c8b1b8e
plan_head_after: cb74ac61936e2dac143bc48b860d3b79dd7ccc2e
commits: 3

tech-stack:
  added: []
  patterns:
    - "One invariant, called from every transaction that changes what is owed: the check runs only with the document row locked in the caller's transaction (lock order document, link) and throws outside one"
    - "A local deactivation of a link that already has a Stripe id always queues the remote call afterCommit; the scheduled sweep of the same job repairs a lost call, so the remote side is eventually consistent without ever being called under a lock"

key-files:
  created:
    - app/Domain/Payments/Support/PaymentLinks.php
    - app/Domain/Payments/Jobs/DeactivatePaymentLinks.php
    - app/Domain/Payments/Actions/RegeneratePaymentLink.php
    - app/Domain/Payments/Actions/DeactivatePaymentLink.php
    - tests/Feature/Payments/LinkLifecycleTest.php
  modified:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - app/Domain/Invoicing/Actions/IssueCreditNote.php
    - app/Domain/Invoicing/Actions/DeletePayment.php
    - routes/console.php
    - lang/cs/payment_links.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - tests/Feature/Payments/PayPageTest.php

key-decisions:
  - "R11-10-1 to R11-10-4 applied as written"
  - "The local deactivation (columns, state, remote queueing) lives in PaymentLinks::deactivate(), shared by reconcile, regenerate and manual deactivation, so the three cannot drift apart"
  - "The remote stamp is set under a short lock of the row and only while it is still empty, so two overlapping sweeps cannot trip the once-only guard of the database"
  - "A document whose link failed (no live row) can be regenerated: the Admin's retry path after a failed creation"

patterns-established:
  - "The webhook match (11-13) and the assignment (11-19) need no link code of their own: they record the payment through recordLocked(), which reconciles"

requirements-completed: []

duration: 40min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 10: Link lifecycle Summary

**A Stripe link now always offers exactly what its document still owes: any payment, partial or full, a credit note, a storno or the deletion of a payment deactivates the live link in the same transaction and then at Stripe, a five-minute sweep repairs a lost remote call, and the Admin can regenerate a link for the remaining amount or switch one off by hand.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 13 (5 created, 8 modified)

## Accomplishments

- `PaymentLinks::reconcile()` locks the live link of the locked document, reads the remaining amount through `InvoiceBalances` inside the caller's transaction (so it sees uncommitted payments and credit notes) and the cancellation with a fresh `stornoCreditNote()->exists()` query, and deactivates with `cancelled`, `paid` or `amount_changed`. It throws `LogicException` outside a transaction (tested by leaving the test's wrapping transaction). A pending row without a Stripe id is deactivated locally and left to its own job.
- Call sites: `RecordPayment::recordLocked()` after the payment and the income (covers the manual path and, later, the webhook match and the assignment), `IssueCreditNote::partial()` and `storno()` after the credit note is issued, `DeletePayment::handle()` after the deletions and the cleared flag. Each carries a one-line D-08 comment. `carry()` does not call it (the converted invoice has no link). The deletion-flag arch test stays green: this plan adds a call, not a flag.
- `DeactivatePaymentLinks` (`#[Idempotent]`, registered in `EXPECTED_APPLICATION_JOBS`): up to 50 rows in state deactivated with a Stripe id and no `remote_deactivated_at`, or only the given id; Stripe is called outside every transaction; a `StripeGatewayFailure` is logged with the failure class and the row id only and the row is left for the next sweep. Scheduled `kokpit-payment-link-sweep`, every five minutes, `onOneServer()`.
- `RegeneratePaymentLink`: Gate `update`, document lock, refusal `not_regenerable` (not an issued uncancelled invoice or proforma, switch off, nothing owed, credit note) or `preparing` (live link pending); an active link goes to `replaced` first, then `PaymentLink::open()` for the remaining amount and `CreatePaymentLink` after the commit. `DeactivatePaymentLink`: active link to `manual`, otherwise `no_active_link`.
- `LinkLifecycleTest` (24 tests, 108 assertions): the partial payment tracer with regenerate for 750 000 minor units and the new row id as idempotency key; paid, cancelled, amount_changed after a partial credit note and after a deleted payment (each also at Stripe); regenerate replace, retry after a failed link, and its five refusals; manual deactivation and its refusal; Partner refused on both actions; the lost remote call repaired once, the logged failure fields, the ignored rows, the targeted run, the schedule.
- Verification: Payments, Invoicing, Finance, Arch and Operations suites 1468 passed; Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on all three commits. A mutation check (removing the reconcile calls from `IssueCreditNote` and `DeletePayment`) turned three tests red.

## Task Commits

1. Task 1: tracer, reconcile, remote job, regenerate, `recordLocked` call, refusal copy, arch registration - `9f00785` (feat)
2. Task 2: credit note and deleted payment calls, manual deactivation, Partner refusals - `2bb8f7e` (feat)
3. Task 3: the five-minute sweep and its schedule - `cb74ac6` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-10-1 to R11-10-4 were applied as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An existing pay-page test assumed the link survives a partial payment**
- **Found during:** Task 1 (Payments suite)
- **Issue:** `PayPageTest` (plan 11-08) paid 100,00 on a card-on invoice and then deactivated the link by a raw update. With the reconcile call the payment already deactivates the link (amount_changed), so the raw update hit the "a failed or deactivated link is final" guard.
- **Fix:** the test now asserts the new behavior (reason amount_changed after the payment) and that the pay page still answers the preparing state; the redundant manual update is gone.
- **Files modified:** `tests/Feature/Payments/PayPageTest.php`
- **Commit:** `9f00785`

### Choices within the plan's latitude

- The shared local deactivation was put in `PaymentLinks::deactivate()` (public) rather than repeated in the three callers; the plan names only `reconcile()`.
- Tasks 1 and 2 did not have a recorded RED run (the tracer is not a TDD task, and the call sites were written together with their tests); the mutation check above stands in as the red proof for Task 2.
- English counterparts of the three refusal keys were not added: they are Admin-only copy (the plan names `lang/cs/payment_links.php` only).

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-35 (reconcile in every transaction that changes what is owed, one test per route), T-11-36 (after-commit job plus sweep; residual risk accepted with the amount check of plan 11-13), T-11-37 (Gate `update` before anything, Partner test on both actions) and T-11-38 (document lock first, partial unique index of plan 11-06) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-12 puts `RegeneratePaymentLink` and `DeactivatePaymentLink` on the document detail and shows the refusal copy under the key `payment_link`; PA-04 stays open in REQUIREMENTS.md until that surface and the webhook route of plan 11-13 exist, because the wording ("by any route", "can be regenerated") is only fully observable then.
- Plan 11-13 (webhook match) and plan 11-19 (assignment) must record the payment through `RecordPayment::recordLocked()`; the link is then reconciled without any code of their own.
- Any test that pays or credits a card-on document now ends with a deactivated link row; a test that needs the old live link afterwards must regenerate.

## Self-Check: PASSED

- Files: all 13 listed files exist.
- Commits: `9f00785`, `2bb8f7e` and `cb74ac6` are ancestors of HEAD.
