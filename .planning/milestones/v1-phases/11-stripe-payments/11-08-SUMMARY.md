---
phase: 11-stripe-payments
plan: 08
subsystem: payments
tags: [stripe, pay-page, public-route, rate-limit, redirect, blade, filament-assets]

requires:
  - phase: 11-stripe-payments
    provides: "PayToken::isWellFormed() and PayUrl (11-03), routes/public.php outside the web group with the webhook route (11-04), the payment_links record, PaymentLink::latestFor() and PaymentLinkState (11-06), live link rows on issued card-on documents (11-07)"
provides:
  - "GET /pay/{token} (route pay.show): 302 to the active Stripe link, otherwise one login-free card (preparing, paid, cancelled) with the document number as the only document datum; invalid, malformed and unknown tokens answer one byte-identical Czech and English card"
  - "App\\Domain\\Payments\\Queries\\PayPageState::resolve(token): token to outcome, number, language and redirect target, read as system"
  - "PayPageOutcome enum (Redirect, Preparing, Paid, Cancelled, Invalid) and StripeUrl::isAllowed()"
  - "resources/views/pay/show.blade.php: a standalone Blade document on the panel assets, no script, no session, no CSRF meta"
  - "limiters pay-page (30 a minute by IP) and stripe-webhook (600 a minute by IP); the webhook route gains throttle:stripe-webhook"
  - "admin panel spaUrlExceptions gains */pay/*"
affects: [11-09, 11-10, 11-12, 11-15, 11-20, pay-page, payment-links]

actuals:
  tokens: 10600
  tasks: 3
  commits: 3
plan_head_before: d58074a
plan_head_after: 1759a48d6a812ac14f6717607930c3e18b150d50
commits: 3

tech-stack:
  added: []
  patterns:
    - "A public page that must start no session is a controller plus a standalone Blade document under SetUpPanel:admin, loading @filamentStyles, the theme and the font, with the stock fi-simple-* classes and anonymous x-filament components only"
    - "A stored URL that becomes a redirect is checked at three layers: database CHECK, the reader and the controller; the controller layer is proved alone by dropping the CHECK inside the test's own transaction"
    - "A link whose offered amount differs from what is still owed is never followed, whatever its state (D-08 defence in depth)"

key-files:
  created:
    - app/Http/Controllers/PayPageController.php
    - app/Domain/Payments/Queries/PayPageState.php
    - app/Domain/Payments/Enums/PayPageOutcome.php
    - app/Domain/Payments/Support/StripeUrl.php
    - resources/views/pay/show.blade.php
    - lang/cs/pay_page.php
    - lang/en/pay_page.php
    - tests/Feature/Payments/PayPageTest.php
  modified:
    - routes/public.php
    - app/Providers/PaymentsServiceProvider.php
    - app/Providers/Filament/AdminPanelProvider.php
    - tests/Feature/Payments/StripeWebhookTest.php

key-decisions:
  - "R11-08-1 to R11-08-6 applied as written"
  - "PayPageState redirects only when the active link offers exactly the remaining amount; after a partial payment a stale active link shows the preparing state (D-08, in the spirit of the partially-paid clause of R11-08-2)"
  - "The retry button opts out of SPA mode (spa-mode false) so the link is a plain GET without wire:navigate, as the UI-SPEC requires"
  - "The pay page follows a dark system mode only through a small prefers-color-scheme style block on the page surface, because R11-08-1 forbids script and the stock dark variants need a class set by script"

patterns-established:
  - "Test visitors are made guests with app('auth')->forgetGuards() before the request; fixtures that need the Admin sign in again afterwards"

requirements-completed: []

duration: 70min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 08: Public pay page Summary

**The URL printed on the PDF and sent in the e-mail now works: `/pay/{token}` sends the client straight to the active Stripe link, or says honestly that the link is being prepared, or that the document is paid or cancelled, in the document's language, while any wrong link gets one byte-identical neutral answer.**

## Performance

- **Duration:** about 70 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 12 (8 created, 4 modified)

## Accomplishments

- `PayPageState` reads as system in the fixed order of R11-08-2: a token failing `PayToken::isWellFormed()` is invalid before any query (a test asserts an empty query log), then the `invoice_admin` row, the invoice (issued invoice or proforma only), cancelled, paid, a redirect only for an active link whose URL passes `StripeUrl::isAllowed()` and whose amount equals what is still owed, otherwise preparing (pending, failed, deactivated, partially paid, no link).
- `PayPageController` answers the redirect with 302 and every other outcome with 200 and the view; `Cache-Control: no-store` and `X-Robots-Tag: noindex, nofollow` on both, `Referrer-Policy: no-referrer` from the route middleware (so a 429 carries it too), no cookie and no session (the route is outside the web group). The redirect target is checked again in the controller.
- The view loads `@filamentStyles`, the theme and the font under `SetUpPanel:admin`, uses the stock `fi-simple-*` classes, `x-filament::icon` and `x-filament::button tag="a"`, and has no script, no `wire:` attribute and no CSRF meta (asserted). The invalid card is built from constants and shown in Czech then English; the other cards use the document language.
- `StripeUrl::isAllowed()` accepts only `https` on `stripe.com` or a subdomain without user info, port, whitespace or backslash; tested against look-alike hosts, userinfo tricks, a CRLF injection and `javascript:`.
- Limiters `pay-page` (30 a minute) and `stripe-webhook` (600 a minute) by IP in `PaymentsServiceProvider::boot()`; the 31st pay request from one address answers 429 with no cookie and the no-referrer header, another address is counted separately. `spaUrlExceptions` lists `*/pay/*`.
- Verification: `PayPageTest` 18 tests / 243 assertions; Payments, Isolation and Arch suites green except the failure noted under Deferred Issues; Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on every commit. A mutation check (`StripeUrl::isAllowed()` always true) turned two tests red.

## Task Commits

1. Task 1: tracer, route, controller, reader, view and Czech copy - `2f53ade` (feat)
2. Task 2: paid, cancelled, English and invalid answers, redirect only to Stripe - `cc3fb55` (feat)
3. Task 3: limiters, SPA exception, route middleware assertions - `1759a48` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-08-1 to R11-08-6 were applied as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The webhook test of plan 11-04 asserted an empty middleware list**
- **Found during:** Task 3 (Payments suite)
- **Issue:** `StripeWebhookTest` "runs the public route without a session, cookie or CSRF middleware" asserted `gatherMiddleware()` is exactly `[]`; R11-08-5 adds `throttle:stripe-webhook`.
- **Fix:** the assertion is now exactly `['throttle:stripe-webhook']`, which still proves no session, cookie or CSRF middleware.
- **Files modified:** `tests/Feature/Payments/StripeWebhookTest.php`
- **Commit:** `1759a48`

### Choices within the plan's latitude

- The `pay-page` limiter is attached to the route in Task 3 together with its definition (Task 1 registered the route with the no-referrer and panel middleware only), because a route naming an undefined limiter would fail.
- The UI-SPEC asks the page to follow the visitor's system dark mode, while R11-08-1 says "no script". Stock dark variants are keyed on a `dark` class that only script sets, so the page is light by default and a `prefers-color-scheme: dark` block recolours the page surface, card, heading and body from the panel gray variables. The state icons and the button keep their light-mode colours. This is a visible gap to the UI-SPEC that the visual gate should look at.
- Task 1 was the tracer and Task 2's behaviours were mostly satisfied by the tracer's reader, so the Task 2 tests passed on first run; the mutation check above stands in for a red proof of the redirect guard. The 375 px overflow check (verification: backstop) was not performed in a browser; the layout relies on the stock single-column simple-page card, `overflow-wrap: anywhere` on text and a centred flex column.
- The commit of Task 1 was swept once into a concurrent quick-task commit and then un-swept by that process; the final history holds the three plan commits above. Because a foreign commit (`d58074a`) landed between the start of the plan and my first commit, `plan_head_before` is `d58074a` so that the measured commit count is this plan's own.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-27 (shape check before any query, one byte-identical invalid answer, 30 a minute), T-11-28 (`StripeUrl::isAllowed()` in the reader and the controller, tested with look-alike hosts and with the CHECK dropped), T-11-29 (no-store, no-referrer and noindex on every answer, tested on 302, 200 and 429), T-11-30 (no cookie, no session or CSRF middleware asserted on both public routes) and T-11-31 (named limiters on both routes) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Deferred Issues

- `tests/Isolation/ApiAccessTest.php` ("lets a finance:read token read the transaction canary of a client by UUID") fails on the key list of the transaction resource, which now contains `external`. It comes from the uncommitted work of the unrelated quick task `261018-mic` in the same working tree, not from this plan; it was not touched.
- A few Payments and Isolation tests (for example in `PayButtonOutputsTest` and the file-route tests) failed intermittently while that other task's `composer ci` ran in the same container, and pass when run alone or after it finished.

## Notes for later plans

- Phase 12 receives `/pay/{token}` and `/webhooks/stripe` as the two public routes of this phase (hand-over in plan 11-20); both are outside the web group and each carries its named limiter.
- Plan 11-09 (regenerate, manual deactivation) can rely on the pay page showing the preparing state whenever the live link is not active or offers another amount; the stable URL never changes.
- Plans 11-12 and 11-15 point the Admin and Partner "Zaplatit kartou" actions at `PayUrl::forInvoice()` with `openUrlInNewTab()`; `*/pay/*` is already a SPA exception.
- PA-02 stays open: the invoice detail buttons (Admin and Partner) are built by plans 11-12 and 11-15.

## Self-Check: PASSED

- Files: all 12 listed files exist.
- Commits: `2f53ade`, `cc3fb55` and `1759a48` are ancestors of HEAD.
