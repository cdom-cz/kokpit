---
phase: 11-stripe-payments
plan: 04
subsystem: payments
tags: [stripe, webhook, signature, idempotency, queue, spatie-webhook-client]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "StripeConfig (hasWebhookSecret/webhookSecret), the SDK confined to app/Domain/Payments/Stripe/, StripeTestConfig"
provides:
  - "POST /webhooks/stripe (route webhooks.stripe) in routes/public.php, outside the web middleware group: no session, cookie or CSRF"
  - "StripeSignatureValidator: SDK WebhookSignature::verifyHeader on the raw body, 300 s tolerance, fail closed without a secret"
  - "StripeWebhookProcessor: signature, payload shape check, HANDLED_TYPES filter, single store, dispatch of new or unprocessed rows, payer-data trimming"
  - "WebhookCall::storeStripeEvent(): INSERT ... ON CONFLICT DO NOTHING against the partial unique index (name, event_id)"
  - "webhook_calls.event_id, event_name, processed_at, failed_at, failure_class and the unique, unprocessed-partial and two check constraints"
  - "ProcessStripeWebhook (ProcessWebhookJob subclass with the KokpitJob contract by hand), handlers() map seam and StripeEventHandler interface"
  - "JobDeclaration::webhookJobProblems()/webhookJobs() and EXPECTED_WEBHOOK_JOBS in the job-contract arch test"
  - "tests/Support/StripeWebhookSigner and StripeEvents helpers"
affects: [11-08, 11-13, 11-14, 11-16, 11-18, stripe-webhook, system-page, card-payments]

actuals:
  tokens: 13565
  tasks: 3
  commits: 3
plan_head_before: 8877030932049b47f8a85f98b894c8d160af8e86
plan_head_after: 1bd3bbf9e5d8db4ac737f6b0205474b65fb42844
commits: 3

tech-stack:
  added: []
  patterns:
    - "A package job base class that cannot extend KokpitJob carries the job contract by hand and is admitted by an explicit list plus a declaration check"
    - "Idempotent intake: insertOrIgnore against a partial unique index, then read back; redelivery of an unprocessed row dispatches again, a processed row never"
    - "A failure is recorded as a fixed class column, never as exception text"

key-files:
  created:
    - database/migrations/2026_10_18_000300_add_stripe_columns_to_webhook_calls.php
    - app/Domain/Payments/Stripe/StripeSignatureValidator.php
    - app/Domain/Payments/Stripe/StripeWebhookProcessor.php
    - app/Http/Controllers/StripeWebhookController.php
    - routes/public.php
    - app/Domain/Payments/Jobs/ProcessStripeWebhook.php
    - app/Domain/Payments/Jobs/StripeEventHandler.php
    - tests/Support/StripeWebhookSigner.php
    - tests/Support/StripeEvents.php
    - tests/Support/Probes/UndeclaredWebhookProbeJob.php
    - tests/Feature/Payments/StripeWebhookTest.php
  modified:
    - config/webhook-client.php
    - .env.example
    - app/Domain/Shared/Models/WebhookCall.php
    - bootstrap/app.php
    - tests/Arch/JobContractTest.php
    - tests/Support/JobDeclaration.php
    - tests/Support/PgSchema.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "R11-04-1 to R11-04-7 applied as written"
  - "ProcessStripeWebhook overrides the processor's processWebhook() to dispatch without the package's saveException, so a dispatch failure (500, Stripe redelivers) never copies exception text into the row"
  - "Handler seam: ProcessStripeWebhook::handlers() returns the built-in map (empty in this plan) or a map bound in the container under HANDLERS_BINDING; handlers implement StripeEventHandler::handle(WebhookCall)"
  - "InvalidWebhookSignature is rendered 400 and not reported, so forged calls do not flood the log with traces"

patterns-established:
  - "Tests sign a body by hand with hash_hmac (independent of the application's SDK check) using the random secret of StripeTestConfig"
  - "RawSql::expectSqlState proves database constraints (23505, 23514) inside the RefreshDatabase transaction"

requirements-completed: []

duration: 45min
completed: 2026-10-18
---

# Phase 11 Plan 04: Stripe webhook intake Summary

**A signed Stripe event is verified on the raw body with the SDK, stored exactly once under its unique event id (ON CONFLICT DO NOTHING), answered 200 at once and processed by a queued job that records success or a fixed failure class.**

## Performance

- **Duration:** about 45 min, including a 10 min full-suite run
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 19 (11 created, 8 modified)

## Accomplishments

- Tracer: `POST /webhooks/stripe` is registered in `routes/public.php`, loaded through `withRouting(then: ...)` with no middleware group. The route has an empty middleware list and the answer sets no cookie (tested; the CSRF check itself is disabled under test, so the proof is the empty middleware list).
- The signature is checked with `\Stripe\WebhookSignature::verifyHeader` inside `app/Domain/Payments/Stripe/` (the source scan of plan 11-01 stays green). A bad signature, a missing header, a timestamp 301 s old, a changed body and a missing secret each give 400 `{"message":"invalid signature"}` and store nothing; 290 s old is accepted.
- The processor then checks the event shape (JSON object, string `id` starting `evt_` of at most 255 characters, string `type`, array `data.object`; otherwise 400 `invalid payload`), drops unhandled types with 200 and no row, trims `customer_details`, `customer_email`, `shipping_details`, `shipping`, `collected_information` from `data.object`, and stores through `WebhookCall::storeStripeEvent()`.
- A repeated delivery leaves one row. An unprocessed (or failed) stored row is dispatched again, a processed row never is. The database also refuses a second `(name, event_id)` row (23505) and a stripe row without event id/type or a row both failed and processed (23514).
- `ProcessStripeWebhook` extends `ProcessWebhookJob` with `#[Tries(3)]`, `#[Backoff(10, 60, 300)]`, `#[Timeout(60)]`, `#[Idempotent]` and `RunsAsSystem`. `handle()` locks the event row, does nothing for a processed row, runs the type's handler (none yet) and sets `processed_at` while clearing `failed_at`/`failure_class` in one transaction. `failed()` sets `failed_at` with `validation` (ValidationException) or `unexpected`, never the message, and only for an unprocessed row.
- Arch test: `EXPECTED_WEBHOOK_JOBS` plus `JobDeclaration::webhookJobProblems()`; `UndeclaredWebhookProbeJob` proves both gaps (no `#[Idempotent]`, no `RunsAsSystem`) are reported, and a variant without `#[Timeout]` is reported.
- `config/webhook-client.php` holds only the `stripe` entry (`STRIPE_WEBHOOK_SECRET`, `delete_after_days` 90); `WEBHOOK_CLIENT_SECRET` is gone from config and `.env.example`.
- Full suite: 5983 passed, 3 skipped; Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean at each commit.

## Task Commits

1. Task 1 (tracer): signed event stored once, 200 at once, job queued and marks it processed - `6a3866c` (feat)
2. Task 2: forged, stale, malformed and repeated deliveries, payer trimming - `26e6e9e` (test)
3. Task 3: job failure record, idempotent rerun, job-contract probe - `1bd3bbf` (test)

The Task 2 behavior (processor checks, trimming, `.env.example`) was implemented inside the tracer commit because the tracer processor needed the full shape check to be correct; Task 2 and Task 3 therefore added the tests that pin that behavior.

## Decisions Made

See `key-decisions` in the frontmatter. The retention override of `prunable()` (processed rows only) remains with plan 11-14 as planned; until then nothing schedules `model:prune` for this model, so no failed or unprocessed event is pruned.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `.env.example` change moved into Task 1**
- **Found during:** Task 1
- **Issue:** Removing the `default` config entry makes `WEBHOOK_CLIENT_SECRET` a documented key that no config reads, which fails `EnvExampleTest`.
- **Fix:** The key and its comment were removed in the tracer commit instead of Task 2.
- **Files modified:** `.env.example`
- **Commit:** `6a3866c`

**2. [Rule 3 - Blocking] `SchemaConventionsTest` exempt-map assertion updated**
- **Found during:** Task 1
- **Issue:** Adding `webhook_calls.event_id` to `PgSchema::EXEMPT` (required by the plan) changed the exact key list that `SchemaConventionsTest` asserts.
- **Fix:** The test lists the new key and its title names it. The file was not in the plan's file list.
- **Files modified:** `tests/Feature/Schema/SchemaConventionsTest.php`
- **Commit:** `6a3866c`

**3. [Rule 3 - Blocking] `StripeEventHandler` interface added**
- **Found during:** Task 1
- **Issue:** `handlers(): array<string, class-string>` needs a typed call contract to be callable under PHPStan level 8 and testable.
- **Fix:** `app/Domain/Payments/Jobs/StripeEventHandler.php` with `handle(WebhookCall): void`. Plan 11-13 states `RecordCardPayment::handle(StripeEvent)`; it will need a thin adapter or an amendment of this interface when it registers its handler.
- **Commit:** `6a3866c`

**4. [Rule 2 - Missing critical functionality] No exception text in the webhook row on a dispatch failure**
- **Found during:** Task 1
- **Issue:** The package's `processWebhook()` copies the exception message and trace into `webhook_calls.exception` when dispatch fails (for example a Redis error naming a host), contradicting T-11-15.
- **Fix:** `StripeWebhookProcessor::processWebhook()` dispatches without that handling; the failure surfaces as a 500 and Stripe's redelivery repairs the dispatch.
- **Commit:** `6a3866c`

**5. [Rule 2 - Missing critical functionality] Bad signature is not reported**
- **Found during:** Task 1
- **Issue:** Each forged call would write a stack trace to the log.
- **Fix:** `dontReport(InvalidWebhookSignature::class)` next to the 400 rendering in `bootstrap/app.php`.
- **Commit:** `6a3866c`

### Notes

- The tracer's RED step was not run in isolation (test and implementation were written before the first run); all behaviors are covered by the tests that now exist.
- `ProcessStripeWebhook::HANDLERS_BINDING` lets a test bind its own handler map; plans 11-13 and 11-16 add their handlers to the built-in map in `handlers()`.

## Auth Gates

None.

## Known Stubs

None. The empty built-in handler map is intentional: plans 11-13 (checkout) and 11-16 (refund) fill it, and until then an accepted event is only marked processed.

## Threat Flags

None beyond the plan's threat model (T-11-11 to T-11-15 mitigated and tested as listed; T-11-SC: no package installed in this plan).

Flag for the owner (research A7): the stored payload omits the payer's personal data; if full payloads are wanted for debugging, `StripeWebhookProcessor::PAYER_KEYS` is the single place to change.

## Issues Encountered

None.

## Next Phase Readiness

Plan 11-08 adds the pay route and the two throttles to `routes/public.php`. Plan 11-13 registers the checkout handlers in `ProcessStripeWebhook::handlers()` and reads `webhook_calls.payload`; plan 11-14 adds the `prunable()` override (processed rows older than 90 days) and the System-page indicator on `processed_at IS NULL` and `failed_at`. The owner must register the Stripe event destination at `/webhooks/stripe` for the three handled types.

## Self-Check: PASSED

- Files: all 11 created files exist (checked with the three commits' file lists).
- Commits: `6a3866c`, `26e6e9e`, `1bd3bbf` are ancestors of HEAD.
