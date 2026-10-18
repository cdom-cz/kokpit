---
phase: 11-stripe-payments
plan: 07
subsystem: payments
tags: [stripe, payment-link, queue-job, idempotency, admin-alert, two-phase-lock]

requires:
  - phase: 11-stripe-payments
    provides: "StripeGateway, PaymentLinkRequest, StripeGatewayFailure and FakeStripeGateway (11-01), the frozen card switch and CardPaymentSetup (11-03), the Admin-only payment_links record with its guard (11-06)"
provides:
  - "App\\Domain\\Payments\\Jobs\\CreatePaymentLink: the idempotent three-phase job that turns a pending payment_links row into an active single-use Stripe link, with no Stripe call inside a transaction or under a lock"
  - "DocumentIssuer::issue() opens the pending link for the total and dispatches the job after the commit for a document issued with the card switch on"
  - "A Stripe failure never blocks issuing: the row becomes failed with a fixed class and every Admin gets the bell notice and the mail 'Kokpit: Odkaz na platbu kartou se nepodařilo vytvořit'"
  - "OperationalAlert optional mailSubject (last constructor argument), kept by withLine()"
  - "lang/cs and lang/en payment_links.php (stripe.product, confirmation, inactive in the document language) and lang/cs/payment_notices.php (mail_subject, link_failed)"
  - "FakeStripeGateway::onCreate() and answerWithUrl(); CardPaymentSetup::enable() binds the fake gateway"
affects: [11-08, 11-09, 11-10, 11-12, 11-13, 11-16, payment-links, reconcile, pay-page]

actuals:
  tokens: 16000
  tasks: 3
  commits: 3
plan_head_before: e2086f08a0098c178d93dc541f4f7f5de7e74258
plan_head_after: b6965f8a319d14eb9c0f6ea00e1b67783e3173d6
commits: 3

tech-stack:
  added: []
  patterns:
    - "Three-phase job around an external call: lock document then row and decide (A), call the provider with the row id as idempotency key outside every transaction (B), lock again and re-check against the changed world (C)"
    - "A provider object that is no longer wanted keeps its references on the row (set once), is deactivated locally first and remotely after the commit; a failed remote call is left for the sweep"
    - "A job tests its retry logic by running through the Dispatcher::dispatchNow pipeline, because the sync queue driver calls failed() at the first failed attempt"

key-files:
  created:
    - app/Domain/Payments/Jobs/CreatePaymentLink.php
    - lang/cs/payment_links.php
    - lang/en/payment_links.php
    - lang/cs/payment_notices.php
    - tests/Feature/Payments/CreatePaymentLinkTest.php
  modified:
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Operations/Alerts/OperationalAlert.php
    - tests/Arch/JobContractTest.php
    - tests/Support/FakeStripeGateway.php
    - tests/Support/CardPaymentSetup.php
    - tests/Feature/Payments/PaymentLinkRecordTest.php

key-decisions:
  - "R11-07-1 to R11-07-5 applied as written (issue hook, three-phase job, failed() and alert, optional mail subject, Stripe-side copy in the document language)"
  - "Retry bound read from the queue job (maxTries()) with the base default of 3 when the job runs without one; a retryable class is rethrown only before the last attempt"
  - "A returned URL outside stripe.com is a failed row with class api_error that keeps the Stripe id (not the URL), so the audit trail knows which link to look at"
  - "A request that cannot be built (ISK and UGX, unsupported_currency from the PaymentLinkRequest constructor) fails the row in phase A before any Stripe call"

patterns-established:
  - "Alert throttle key payment-link-failed:<invoice id>: one alert per document inside the window, as research recommended"

requirements-completed: [PA-01]

duration: 45min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 07: Payment link job Summary

**Issuing a card-on invoice or proforma now ends with a queued job that creates the single-use Stripe link for the total, never calls Stripe under a lock, never leaves a link with a wrong amount, and turns any Stripe failure into a failed row plus a bell and mail notice to every Admin instead of a blocked issue.**

## Performance

- **Duration:** about 45 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 11 (5 created, 6 modified)

## Accomplishments

- `DocumentIssuer::issue()` ends with `PaymentLink::open($issued, $issued->total_minor, $context->actor)` and `CreatePaymentLink::dispatch($link->id)->afterCommit()` when the frozen switch of the Admin row is on. A rollback of the issue takes the row and the dispatch back (tested with a forced failure after the issue: no row, no Stripe call); a switch-off document and a credit note get neither.
- `CreatePaymentLink` (`#[Idempotent]`, default 3 tries and 60 s timeout): phase A locks the document then the row, deactivates a row whose document was cancelled (`cancelled`), is fully paid (`paid`) or owes another amount (`amount_changed`) without calling Stripe, and otherwise builds the request; phase B calls `createLink()` outside any transaction with the row id as idempotency key; phase C locks again, re-checks and activates (`stripe_link_id`, `stripe_url`, `activated_at`) or, when the world changed, stores the Stripe references on the row, deactivates locally and then at Stripe, stamping `remote_deactivated_at` (a refused remote call is left for the sweep of plan 11-10 and does not fail the job).
- Failures: a lost connection or a rate limit is rethrown before the last attempt and the same key makes the retry safe (tested: two calls, one created link); every other class, a missing configuration, an unsupported currency and a returned URL outside stripe.com are final at once; `failed()` enters the system context by hand and marks a still-pending row failed with the class or `unexpected`. Every move to failed alerts through `AdminAlerter` (bell then mail, throttled per document) with the number-only copy of `lang/cs/payment_notices.php`; the tests assert that no provider text, failure class, Stripe id or client name appears in any bell row or mail.
- `OperationalAlert` gained the optional `mailSubject`; the link failure mails "Kokpit: Odkaz na platbu kartou se nepodařilo vytvořit", every other alert still mails its title.
- Stripe-side texts come from `lang/{cs,en}/payment_links.php` in the document language (invoice, proforma, hosted confirmation, inactive message).
- Verification: Payments, Invoicing, Operations, Arch and Isolation suites 1465 passed; Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on all three commits. A mutation check (phase C ignoring the re-check) turned four tests red.

## Task Commits

1. Task 1: tracer, issue hook, job happy path, Stripe-side copy, arch registration - `05f8058` (feat)
2. Task 2: failure branches, Admin alert, optional mail subject, notice copy - `6e6703f` (feat)
3. Task 3: payment or storno before and during the Stripe call, remote deactivation - `b6965f8` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. The rulings R11-07-1 to R11-07-5 were applied as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing tests issue card-on documents and then open their own link**
- **Found during:** Task 1 (Payments suite)
- **Issue:** with the issue hook in place every card-on document has a live pending or active link, so ten tests of `PaymentLinkRecordTest` (plan 11-06), which open or insert their own link, hit the one-live-link index. Separately, the sync queue of the test environment would run the job against the real Stripe gateway for any test that issues a card-on document.
- **Fix:** `CardPaymentSetup::enable()` now binds `FakeStripeGateway`; `PaymentLinkRecordTest` issues through a local helper that fakes the queue and moves the automatic row to `deactivated` (manual), which restores the "document without a live link" starting point the record tests were written for.
- **Files modified:** `tests/Support/CardPaymentSetup.php`, `tests/Feature/Payments/PaymentLinkRecordTest.php`
- **Commit:** `05f8058`

### Choices within the plan's latitude

- `FakeStripeGateway` gained `onCreate()` (planned) and `answerWithUrl()` (for the non-Stripe URL test).
- The job tests run the job through `Dispatcher::dispatchNow` (a helper `runLinkJob`) rather than `dispatchSync`: the sync queue driver calls `failed()` on the first failed attempt, which a real queue does only after the last one. The issue itself runs on the sync queue where a test wants the whole chain.
- A returned URL outside stripe.com keeps the Stripe id on the failed row; the plan only required the failure class.
- Tasks 1 and 2 did not have a recorded RED run before the implementation of the happy path (the tracer is not a TDD task) and the failure branches were written together with their tests; the mutation check above stands in for the red proof of Task 3.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-22 (no Stripe call in a transaction or under a lock: three-phase job), T-11-23 (re-checks before and after the call, local then remote deactivation, tests for payment and storno in both windows), T-11-24 (fixed classes and number-only notice, asserted absent: provider text, class, Stripe id, client name), T-11-25 (URL pattern check before activation plus the database CHECK) and T-11-26 (row id as key, a 5xx final, one creation counted) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-09 (regenerate, manual deactivation) deactivates the old live row first and then opens a new one; the job treats a row deactivated during its call by storing the Stripe references and switching the link off at Stripe.
- Plan 11-10 (reconcile and sweep) finds rows with `state = deactivated`, a `stripe_link_id` and no `remote_deactivated_at`; this job leaves exactly such rows when the remote call is refused.
- Plan 11-12 reads `failure_class` from the row for the document detail banner; the classes are the `StripeGatewayFailure` constants plus `unexpected`.
- Any later test that issues a card-on document gets a live link row and a fake-gateway call through the sync queue; a test that needs a document without one should follow the helper in `PaymentLinkRecordTest`.

## Self-Check: PASSED

- Files: all 11 listed files exist.
- Commits: `05f8058`, `6e6703f` and `b6965f8` are ancestors of HEAD.
