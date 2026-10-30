---
phase: 11-stripe-payments
plan: 01
subsystem: payments
tags: [stripe, payment-links, gateway, idempotency, sdk, secrets]

requires:
  - phase: 10-invoicing
    provides: "fixed-message wrapping precedent for provider exceptions (SendInvoiceEmail) and the job timeout default the client timeouts stay below"
provides:
  - "stripe/stripe-php ^22.0 (v22.0.0, MIT) as a direct dependency, confined to app/Domain/Payments/Stripe/"
  - "StripeGateway interface: createLink(PaymentLinkRequest): CreatedPaymentLink, deactivateLink(string): void"
  - "StripeApiGateway: one idempotent form POST to /v1/payment_links, one POST with active=false to deactivate, API version pinned to 2026-09-30.endive"
  - "StripeGatewayFailure with eight fixed failure classes, fixed messages and isRetryable()"
  - "StripeConfig: isConfigured/hasSecretKey/hasWebhookSecret over services.stripe.secret and services.stripe.webhook_secret (environment only)"
  - "PaymentsServiceProvider binding StripeGateway to StripeApiGateway"
  - "FakeStripeGateway and StripeTestConfig test helpers for every later payments test"
affects: [11-04, 11-07, 11-10, 11-14, payment-links, stripe-webhook, system-page]

actuals:
  tokens: 11472
  tasks: 3
  commits: 3
plan_head_before: f0da36abb602d78340045b2b8e17c442619e0cf8
plan_head_after: 6f50c9b9271856dd121f8a0e98716ed2b82820ac
commits: 3

tech-stack:
  added: ["stripe/stripe-php ^22.0 (v22.0.0)"]
  patterns:
    - "A provider SDK sits behind a two-method gateway interface; tests bind an in-memory fake and a source scan keeps the SDK namespace out of the rest of the code"
    - "A provider exception leaves as one exception class with a fixed class constant and a fixed message; the SDK exception stays only as the previous exception"
    - "Stripe-shaped test values are assembled at runtime from fragments (implode of prefix, underscore, Str::random)"

key-files:
  created:
    - app/Domain/Payments/Stripe/StripeGateway.php
    - app/Domain/Payments/Stripe/StripeApiGateway.php
    - app/Domain/Payments/Stripe/PaymentLinkRequest.php
    - app/Domain/Payments/Stripe/CreatedPaymentLink.php
    - app/Domain/Payments/Stripe/StripeGatewayFailure.php
    - app/Domain/Payments/Support/StripeConfig.php
    - app/Providers/PaymentsServiceProvider.php
    - tests/Support/FakeStripeGateway.php
    - tests/Support/StripeTestConfig.php
    - tests/Feature/Payments/StripeGatewayTest.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/providers.php
    - config/services.php
    - .env.example

key-decisions:
  - "R11-01-1: the official SDK is used behind the gateway; no class outside app/Domain/Payments/Stripe/ imports a Stripe\\ class (source scan in the test)"
  - "R11-01-2: API version pinned in StripeApiGateway::API_VERSION, 20 s timeout, 5 s connect timeout, one network retry"
  - "R11-01-3: failure classes are constants of StripeGatewayFailure; only connection and rate_limited are retryable; the message is always 'Stripe request failed: <class>'"
  - "R11-01-4: ISK and UGX are refused with unsupported_currency by the PaymentLinkRequest constructor, before any gateway call"
  - "R11-01-5: STRIPE_SECRET and STRIPE_WEBHOOK_SECRET read only through config/services.php; isConfigured() needs both"
  - "A 409 answer (which the SDK maps to no dedicated class) is classified as idempotency by its HTTP status"

patterns-established:
  - "FakeStripeGateway::install() binds the fake as StripeGateway; the same idempotency key returns the same link; failNextCreate()/failNextDeactivate() raise a failure class once"
  - "StripeTestConfig::configure() sets both secrets from runtime fragments; clear() unsets them"

requirements-completed: [PA-01, PA-04, PA-05]

duration: 40min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 01: Stripe gateway Summary

**Stripe's official SDK behind a two-method gateway: an idempotent single-use payment link request, a deactivation call, fixed non-leaking failure classes, environment-only secrets and an in-memory fake for all later tests.**

## Performance

- **Duration:** about 40 min of execution after the package checkpoint (including a 10 min full-suite run)
- **Tasks:** 3 (1 blocking-human checkpoint, 1 tracer, 1 TDD)
- **Files:** 15 (10 created, 5 modified; composer.lock is generated)

## Accomplishments

- Task 1: a human approved `stripe/stripe-php` (packagist vendor stripe, repository github.com/stripe/stripe-php, MIT). The install added v22.0.0 and nothing else to `composer.lock` (one new package block, new content hash); `composer check-licenses` passes for 226 packages.
- `StripeApiGateway::createLink()` sends exactly one form POST to `/v1/payment_links` with the `Idempotency-Key` of the Kokpit link row, the pinned `Stripe-Version`, inline `price_data` in minor units with a lower-case currency, `restrictions[completed_sessions][limit]=1`, `metadata[invoice_id]` and `metadata[payment_link_row]`, `payment_intent_data[metadata][invoice_id]`, `payment_method_types[0]=card`, the hosted confirmation message and the inactive message. One network retry, 20 s timeout, 5 s connect timeout. Proven without the network through a recording client placed in the SDK's HTTP client seam (`ApiRequestor::setHttpClient`).
- `deactivateLink()` sends one POST to `/v1/payment_links/{id}` with `active=false`.
- Every SDK exception leaves as `StripeGatewayFailure` with one of eight fixed classes; the message is `Stripe request failed: <class>` and the tests assert it contains neither the provider text, the request id nor the secret key. Only `connection` and `rate_limited` are retryable.
- `StripeConfig` answers only whether each secret is set; the value getters are for the gateway and the later signature check. `config/services.php` reads `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`; `.env.example` documents both commented out.
- `PaymentsServiceProvider` binds the interface; `FakeStripeGateway` replaces it in tests.
- Full suite: 5902 passed, 3 skipped; Pint and PHPStan (level 8) clean.

## Task Commits

1. Task 1: package legitimacy checkpoint and install - `1ed058a` (chore)
2. Task 2: tracer, one payment link request leaves as one idempotent POST - `91748ad` (feat)
3. Task 3: deactivation, failure classes, container binding, fake - `6f50c9b` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. The plan's rulings R11-01-1 to R11-01-6 were applied as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] StripeGatewayFailure created in Task 2 instead of Task 3**
- **Found during:** Task 2
- **Issue:** `StripeConfig::secretKey()` throws `StripeGatewayFailure::notConfigured()`, so the tracer could not compile without the exception class, which the plan lists under Task 3.
- **Fix:** The full class (all named constructors and `isRetryable()`) was created in the Task 2 commit; Task 3 added `forClass()` and the tests of the classes.
- **Files modified:** `app/Domain/Payments/Stripe/StripeGatewayFailure.php`
- **Commit:** `91748ad`, `6f50c9b`

**2. [Rule 2 - Missing critical functionality] Failure classification by HTTP status for a 409**
- **Found during:** Task 3
- **Issue:** The SDK maps a 409 to `UnknownApiErrorException`, which would have been reported as `api_error` although the plan requires `idempotency` for a 409 clash on the key.
- **Fix:** `StripeApiGateway::failure()` also classifies an `ApiErrorException` with HTTP status 409 as `idempotency`.
- **Files modified:** `app/Domain/Payments/Stripe/StripeApiGateway.php`
- **Commit:** `91748ad`

**3. [Rule 1 - Bug] PHPStan rejected redundant is_string() guards on SDK properties**
- **Found during:** Task 2
- **Issue:** The SDK types `id` and `url` as strings, so the type guards failed level 8.
- **Fix:** The values are cast with `(string)` and an empty result is treated as a provider fault (api_error); a missing field still cannot produce a half-built result.
- **Commit:** `91748ad`

### Choices within the plan's latitude

- The ISK/UGX refusal (R11-01-4) lives in the `PaymentLinkRequest` constructor, so it applies to the real gateway and the fake alike and before any call. A caller that builds the request must therefore treat `StripeGatewayFailure` as possible from the constructor (plans 11-07 and later).
- `StripeGatewayFailure::forClass()` was added so the fake and any code that stores a failure class can rebuild a failure from data.
- `FakeStripeGateway` also exposes `createCalls()` (all calls, replays and failures included) next to the planned `created()` and `deactivated()`.
- The Task 1 install was committed on its own (composer.json and composer.lock) before the tracer, so the lock change is reviewable as a single commit.

None of these change a contract that later plans rely on.

## Auth Gates

None. The package legitimacy checkpoint of Task 1 was a human-verify gate, approved before this continuation.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-11-01 to T-11-03 and T-11-SC are mitigated as listed; the lock diff shows a single new package).

## Issues Encountered

- `ddev composer require` published Filament assets as a post-update script; the published files are gitignored and not part of any commit.
- Pint reformatted `bootstrap/cache/services.php` (a gitignored cache file); not committed.

## Next Phase Readiness

Plans 11-04 (webhook signature check), 11-07 (link creation job), 11-10 and 11-14 can bind `FakeStripeGateway::install()` and call `StripeGateway`. Plan 11-04 must wrap `WebhookSignature` inside `app/Domain/Payments/Stripe/` because the SDK namespace is confined there by a source scan.

## Self-Check: PASSED

- Files: all 15 listed files exist (composer.json and composer.lock changed; the ten created files present).
- Commits: `1ed058a`, `91748ad`, `6f50c9b` are ancestors of HEAD.
