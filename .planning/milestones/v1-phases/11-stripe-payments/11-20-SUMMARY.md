---
phase: 11-stripe-payments
plan: 20
subsystem: payments
tags: [stripe, card-payments, end-to-end, isolation, documentation, validation, phase-closing]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "Everything of plans 11-01 to 11-19: the link job, the stable pay URL and page, the PDF and e-mail block, the webhook intake and matching, the duplicate and unmatched records and alerts, the overview, the dashboard card, the resolutions, the System slots, the pruning and the concurrency proof"
provides:
  - "CardPaymentJourneyTest: one invoice travels the whole card-payment path from issue to a resolved duplicate in one readable test"
  - "CardPaymentLeakTest: the Partner sweep of the phase (route walk, Livewire, global search, dashboard, bell, the five notices) and the check that routes/public.php holds exactly pay.show and webhooks.stripe"
  - "CONTRIBUTING: the Card payments mechanisms bullet and the Phase 12 public-route bullet replace the Phase 11 hand-over bullet"
  - "README: Card payments with Stripe, the owner's setup with placeholders only"
  - "RepositoryFilesTest guards: every class, method and test the new CONTRIBUTING text names exists; README setup tokens; no Stripe-shaped value in the docs"
  - "11-VALIDATION.md signed off (wave_0_complete, nyquist_compliant, 55 task rows green)"
  - "PA-02, PA-03, PA-04, PA-05, PA-07, PA-08 ticked in REQUIREMENTS.md (PA-01 and PA-06 were already)"
affects: [phase-12, verify-work, card-payments]

actuals:
  tokens: 18500
  tasks: 3
  commits: 3
plan_head_before: 0e3f3a39d9e6d879a484c3c9d24507bf19914d55
plan_head_after: 6fc699b6c9da8e70377c73377e1804d41f237e20
commits: 3

tech-stack:
  added: []
  patterns:
    - "A journey test drives the real pipeline (draft, issue, queued link job, stored PDF, queued e-mail, signed webhook, Livewire pages) with only the gateway faked and the signature computed by hand"
    - "A Partner sweep reads its hidden values back from the database (every Stripe reference, note, link id and row id of both clients), so no column is missed, and pairs each refusal with a positive Admin check"
    - "A documentation guard resolves every backticked CamelCase name (and Class::method()) of a CONTRIBUTING bullet to a class under app/ or tests/Support"

key-files:
  created:
    - tests/Feature/Payments/CardPaymentJourneyTest.php
    - tests/Isolation/CardPaymentLeakTest.php
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/phases/11-stripe-payments/11-VALIDATION.md
    - .planning/REQUIREMENTS.md

key-decisions:
  - "R11-20-1 to R11-20-3 applied as written: the Stripe CLI is optional tooling on the developer's machine; README states the shared-account noise, the dropped payer data, the card wallets and the per-document override as owner-facing facts; CONTRIBUTING gets the Card payments mechanisms and the Phase 12 bullet"
  - "The journey reads the e-mails and the Admin alerts from the array mail transport (the same one the alert tests use) instead of Mail::fake(), so the real queued mail pipeline and the notification mails are exercised"
  - "The Partner sweep asserts the public routes by the names declared in routes/public.php (exactly two) plus the router's middleware of those two routes (no web or api group, no auth, session or CSRF middleware, a throttle), because Laravel keeps no record of which file registered a route"
  - "The VariableSymbol / R-07-4 rule of the removed Phase 11 hand-over bullet is kept inside the Card payments bullet, because the Phase 10 documentation guard and the bank-matching rule still need it"

patterns-established:
  - "Filament builds the panel navigation once per process, so a leak test that signs in as the Admin and then as a Partner within one test sees the Admin's menu on the Partner's request; the Partner asks first, or the check runs in its own test"

requirements-completed: [PA-01, PA-02, PA-03, PA-04, PA-05, PA-06, PA-07, PA-08]

duration: 75min
completed: 2026-10-18
---

# Phase 11 Plan 20: Closing the phase Summary

**The whole card-payment flow is now proved end to end in one test (issue, background link, PDF and e-mail with the stable URL, redirect to Stripe, signed event, payment and income, link off, "paid" page, duplicate, alert, overview, dashboard, resolution), a Partner sweep across two clients finds nothing of it, the public routes and mechanisms are handed to Phase 12, the owner's Stripe setup is documented with placeholders only, and `ddev composer ci` is green.**

## Performance

- **Duration:** about 75 min, of which about 13 min the full gate
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files:** 7 (2 created, 5 modified)

## Accomplishments

- Tracer (`CardPaymentJourneyTest`): a fictional client with the online flag gets a draft from the Admin; issuing creates the Stripe link in the background (active, 12 500,00 Kč, one create call at the fake gateway); the stored PDF holds a `/URI` annotation, the stable `https://kokpit.example.com/pay/...` URL and no Stripe host, and Ghostscript reads back "Nebo zaplaťte online kartou:" and "Zaplatit kartou"; the queued e-mail ends with the pay block of the same URL; a GET of the stable URL answers 302 to the fake's Stripe URL; the Partner detail offers `payByCard`. A hand-signed paid session then leaves one matched record, one payment, one income and remaining 0, the link deactivated with reason paid (also at the gateway) and "Přišla platba kartou" in the Admin's bell; the pay page now says "Doklad je zaplacený" and the Partner button is gone. A second paid session is a duplicate with no payment and no second income, "Duplicitní platba kartou" reaches the bell and one mail to the Admin, the overview tab "Nevyřešené" shows 1 and the dashboard card "Nevyřešené platby" appears; the Admin resolves it through the record page action with a note, the badge and the card disappear, and the System slots "Nezpracované webhooky" and "Stripe" are Ok.
- Partner sweep (`CardPaymentLeakTest`, 13 tests, about 38 000 assertions): two clients, each with documents whose link is active, failed, deactivated, pending, cancelled, booked and paid, and ten card payment records (matched, matched and deleted, duplicate, unmatched for amount, currency, cancelled and undetermined, two refunds, one resolved with a note). Partner A walks every `filament.admin.*` GET route asked with client B's ids, with its own ids, and a Partner without a client does the same; the card payment record routes answer 403, the other client's document 404, and no page, Livewire snapshot, notice or global search result holds a Stripe reference, a note, a link value, a row id, another client's pay address or any string of the UI-SPEC "Never on a Partner surface" list. The only addition is "Zaplatit kartou" and the own pay URL on the own payable detail. The five notices (link failure, matched, duplicate, unmatched, refund) are sent through the real flows: both Admins get all five in the bell and by mail, no Partner gets a bell row or a mail. The public-routes test pins `routes/public.php` to `pay.show` and `webhooks.stripe` and a guest asking for `/admin/card-payments` goes to the login.
- Hand-over: CONTRIBUTING's Phase 11 bullet is replaced by a Card payments bullet (switches, link job, stable URL, webhook intake, the reconcile invariant `PaymentLinks::reconcile()`, the system write core `RecordPayment::recordLocked()`, reserved Stripe references through `CardPayment::recordOnce()` and `DeletePayment`, unmatched and duplicate handling, the Partner boundary, `StripeGateway` with `FakeStripeGateway`) and a Phase 12 bullet naming `pay.show` and `webhooks.stripe` as the allowlist entries and `payment_links` and `card_payments` as Admin-only tables. README gains "Card payments with Stripe". `RepositoryFilesTest` fails when any named class, method or test is missing, when the Phase 11 bullet returns, when the README loses a setup token, or when a Stripe-shaped value appears in either document.
- `11-VALIDATION.md`: every task row green, Wave 0 and sign-off boxes ticked, `nyquist_compliant: true`, `wave_0_complete: true`.

## Gate

- `ddev composer ci`: 6347 passed, 3 skipped (the three skips are existing `WorkReportPdfTest` cases that need `pdftotext`), 88 272 assertions; Pint, PHPStan level 8 and the licence allowlist clean.
- `ddev exec vendor/bin/pest tests/Isolation`: 351 passed. `CardPaymentJourneyTest` runs with the Ghostscript text checks executed (Ghostscript is in DDEV), not skipped.
- `scripts/check-sensitive.sh` clean on every committed file; the hook (sensitive-content and gitleaks) ran on every commit.

## Requirements: evidence for each tick

- **PA-01** (ticked by 11-07): re-proved by the journey (link created in the background at issue) and `CreatePaymentLinkTest` (a Stripe failure never blocks issuing and alerts the Admin).
- **PA-02**: the button is in the PDF and the e-mail (`PayButtonOutputsTest`, journey), the Admin detail (`PaymentLinkSectionTest`) and the Partner detail (`PartnerPayButtonTest`, `PayButtonLeakTest`); it is hidden on the detail pages and absent from the e-mail once the document is paid or cancelled, and the stable URL of an already printed PDF then leads to the "paid" page (D-02, D-04).
- **PA-03**: the global setting, the client flag and the per-document switch (default from the client, overridable both ways, frozen at issue) in `CardSwitchTest`, the draft editor, the client form and the settings page.
- **PA-04**: `LinkLifecycleTest` deactivates the link after a partial or full payment, a storno, a partial credit note and a payment deletion, at Stripe too, regenerates with the old link deactivated first, and `MatchPaymentTest` and the journey show the webhook route.
- **PA-05**: `StripeWebhookTest` (signature on the raw body, unique event id, immediate 200, job processing, replay), `PaymentMatchConcurrencyTest`, and the journey with a hand-signed body.
- **PA-07**: duplicate stored without income or document change and alerted (`PaymentClassificationTest`, `PaymentAlertsTest`, journey), repeats ignored, database unique constraints (`CardPaymentRecordTest`) and the concurrency proof.
- **PA-08**: unmatched payments and refund events are recorded and alerted (`PaymentAlertsTest`, `RefundEventTest`), the overview has the Duplicitní and Nespárované tabs with counts (`CardPaymentsOverviewTest`), the three manual resolutions exist (`CardPaymentResolutionTest`), and old events are pruned: `WebhookCall::prunable()` plus the daily `PruneProcessedWebhookCalls` job are delivered by plan 11-14 and proved by `PruneWebhookCallsTest` (processed events older than 90 days only, the card payment record kept, scheduled daily on one server). The "pruning outstanding" remark in the 11-17 report is therefore wrong.
- **Refused ticks: none.**

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Two existing documentation guards depended on the removed Phase 11 bullet**
- **Found during:** Task 3 (`tests/Feature/Repo`)
- **Issue:** the test "hands over to Phases 7, 10 and 11" required `**Phase 11 (Stripe).**` and sliced the Phase 10 text up to it, and the Phase 10 class guard requires `VariableSymbol`, which only the removed bullet named.
- **Fix:** the hand-over test now expects the Phase 7, Phase 10 and `**Phase 12.**` bullets, forbids a Phase 11 bullet, slices the Phase 10 text up to the Phase 12 bullet and checks the phrases of the new Card payments bullet; the `VariableSymbol` / R-07-4 sentence moved into that bullet.
- **Files modified:** `tests/Feature/Repo/RepositoryFilesTest.php`, `CONTRIBUTING.md`
- **Commit:** `6fc699b`

### Plan wording adjusted

- The journey reads mail from the array transport instead of `Mail::fake()` (see key-decisions); the assertions the plan lists are all made.
- The Partner sweep found no leak, so nothing was fixed in an earlier plan. Its tracer-style tests passed on the first full run apart from test-construction issues (the test-only canary table, a Partner table without searchable columns, an Admin overview without text search), all fixed in the test files before the commit.

## Notes for the verifier and the owner

- Filament builds the navigation once per process. A first version of the dashboard check signed in as the Admin and then as a Partner within one test and saw "Platby kartou" in the sidebar of the Partner's request. Every route walk (Partner only, fresh process per test) is clean, and the dashboard test now lets the Partner ask first; this is a test-process artefact, not a leak, and is recorded as a pattern.
- Manual checks for the UAT (optional tooling, D-18; nothing automated needs them): with Stripe test keys in the local `.env` only, run `stripe listen --forward-to https://kokpit.ddev.site/webhooks/stripe`, put the printed secret in `STRIPE_WEBHOOK_SECRET`, issue a fictional invoice with card payment on, pay it with a Stripe test card and check that the invoice is paid with its income and the bell shows "Přišla platba kartou", and that `stripe events resend` creates no second payment. Visual backstops still open: the pay page at 375 px, the printed URL on one line in a sample PDF in cs and en, the overview columns at the narrow breakpoint, "Zaplatit kartou", "Odkaz není platný" and "Nespárovaná" with correct diacritics in Inter and in the PDF's Roboto (the Ghostscript text check already reads "Zaplatit kartou" back from the PDF), and the contrast of "Zaplatit kartou" (Partner) and "Zkusit znovu" in both modes (UI-SPEC F-1).
- The earlier deferred item of plan 11-08 (`ApiAccessTest` failing on the transaction key list) no longer fails in the full gate.

## Known Stubs

None.

## Threat Flags

None. T-11-67 (a new unauthenticated route) is mitigated by the pinned two-route file and the middleware check, T-11-68 by the placeholder-only documents with the guard against Stripe-shaped values plus the hook, T-11-69 by the sweep; T-11-SC: this plan installs nothing.

## Self-Check: PASSED

- Files: `tests/Feature/Payments/CardPaymentJourneyTest.php`, `tests/Isolation/CardPaymentLeakTest.php`, `CONTRIBUTING.md`, `README.md`, `tests/Feature/Repo/RepositoryFilesTest.php` and `.planning/phases/11-stripe-payments/11-VALIDATION.md` exist.
- Commits: `a552157`, `6c6df62` and `6fc699b` are ancestors of HEAD.
