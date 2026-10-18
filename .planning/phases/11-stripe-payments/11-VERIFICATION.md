---
phase: 11-stripe-payments
verified: 2026-10-18T18:50:06Z
status: human_needed
score: 13/13 must-haves verified
covered_files:
  - .planning/phases/11-stripe-payments/11-01-PLAN.md
  - .planning/phases/11-stripe-payments/11-01-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-02-PLAN.md
  - .planning/phases/11-stripe-payments/11-02-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-03-PLAN.md
  - .planning/phases/11-stripe-payments/11-03-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-04-PLAN.md
  - .planning/phases/11-stripe-payments/11-04-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-05-PLAN.md
  - .planning/phases/11-stripe-payments/11-05-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-06-PLAN.md
  - .planning/phases/11-stripe-payments/11-06-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-07-PLAN.md
  - .planning/phases/11-stripe-payments/11-07-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-08-PLAN.md
  - .planning/phases/11-stripe-payments/11-08-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-09-PLAN.md
  - .planning/phases/11-stripe-payments/11-09-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-10-PLAN.md
  - .planning/phases/11-stripe-payments/11-10-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-11-PLAN.md
  - .planning/phases/11-stripe-payments/11-11-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-12-PLAN.md
  - .planning/phases/11-stripe-payments/11-12-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-13-PLAN.md
  - .planning/phases/11-stripe-payments/11-13-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-14-PLAN.md
  - .planning/phases/11-stripe-payments/11-14-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-15-PLAN.md
  - .planning/phases/11-stripe-payments/11-15-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-16-PLAN.md
  - .planning/phases/11-stripe-payments/11-16-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-17-PLAN.md
  - .planning/phases/11-stripe-payments/11-17-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-18-PLAN.md
  - .planning/phases/11-stripe-payments/11-18-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-19-PLAN.md
  - .planning/phases/11-stripe-payments/11-19-SUMMARY.md
  - .planning/phases/11-stripe-payments/11-20-PLAN.md
  - .planning/phases/11-stripe-payments/11-20-SUMMARY.md
  - app/Domain/Api/ExternalObject.php
  - app/Domain/Dashboard/Enums/DashboardWidget.php
  - app/Domain/Finance/Actions/RecordTransaction.php
  - app/Domain/Finance/Actions/UpdateTransaction.php
  - app/Domain/Finance/Models/Transaction.php
  - app/Domain/Invoicing/Actions/CreateDraft.php
  - app/Domain/Invoicing/Actions/DeletePayment.php
  - app/Domain/Invoicing/Actions/IssueCreditNote.php
  - app/Domain/Invoicing/Actions/RecordPayment.php
  - app/Domain/Invoicing/Actions/UpdateDraft.php
  - app/Domain/Invoicing/Enums/PaymentMethod.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
  - app/Domain/Invoicing/Models/InvoiceAdmin.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Support/PaymentFacts.php
  - app/Domain/Invoicing/Support/PaymentInput.php
  - app/Domain/Operations/Alerts/OperationalAlert.php
  - app/Domain/Operations/Health/HealthSlot.php
  - app/Domain/Operations/Health/Indicators/StripeConfigIndicator.php
  - app/Domain/Operations/Health/Indicators/UnprocessedWebhooksIndicator.php
  - app/Domain/Payments/Actions/AssignCardPayment.php
  - app/Domain/Payments/Actions/DeactivatePaymentLink.php
  - app/Domain/Payments/Actions/RecordCardPayment.php
  - app/Domain/Payments/Actions/RecordPaymentReturn.php
  - app/Domain/Payments/Actions/RecordRefundEvent.php
  - app/Domain/Payments/Actions/RegeneratePaymentLink.php
  - app/Domain/Payments/Actions/ResolveCardPayment.php
  - app/Domain/Payments/Enums/CardPaymentReason.php
  - app/Domain/Payments/Enums/CardPaymentResolution.php
  - app/Domain/Payments/Enums/CardPaymentStatus.php
  - app/Domain/Payments/Enums/CardPaymentTab.php
  - app/Domain/Payments/Enums/CardPaymentType.php
  - app/Domain/Payments/Enums/PayPageOutcome.php
  - app/Domain/Payments/Enums/PaymentLinkDeactivation.php
  - app/Domain/Payments/Enums/PaymentLinkState.php
  - app/Domain/Payments/Jobs/CreatePaymentLink.php
  - app/Domain/Payments/Jobs/DeactivatePaymentLinks.php
  - app/Domain/Payments/Jobs/ProcessStripeWebhook.php
  - app/Domain/Payments/Jobs/PruneProcessedWebhookCalls.php
  - app/Domain/Payments/Jobs/StripeEventAction.php
  - app/Domain/Payments/Jobs/StripeEventHandler.php
  - app/Domain/Payments/Models/CardPayment.php
  - app/Domain/Payments/Models/PaymentLink.php
  - app/Domain/Payments/Notifications/CardPaymentMatchedNotification.php
  - app/Domain/Payments/Queries/PartnerPayButton.php
  - app/Domain/Payments/Queries/PayPageState.php
  - app/Domain/Payments/Queries/PaymentLinkStatus.php
  - app/Domain/Payments/Queries/UnresolvedPayments.php
  - app/Domain/Payments/Stripe/CreatedPaymentLink.php
  - app/Domain/Payments/Stripe/PaymentLinkRequest.php
  - app/Domain/Payments/Stripe/StripeApiGateway.php
  - app/Domain/Payments/Stripe/StripeEvent.php
  - app/Domain/Payments/Stripe/StripeGateway.php
  - app/Domain/Payments/Stripe/StripeGatewayFailure.php
  - app/Domain/Payments/Stripe/StripeSignatureValidator.php
  - app/Domain/Payments/Stripe/StripeWebhookProcessor.php
  - app/Domain/Payments/Support/CardPaymentAlerts.php
  - app/Domain/Payments/Support/CardPaymentSwitch.php
  - app/Domain/Payments/Support/PayLinkBlock.php
  - app/Domain/Payments/Support/PayToken.php
  - app/Domain/Payments/Support/PayUrl.php
  - app/Domain/Payments/Support/PaymentLinks.php
  - app/Domain/Payments/Support/StripeConfig.php
  - app/Domain/Payments/Support/StripeUrl.php
  - app/Domain/Settings/Settings/PaymentSettings.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Models/WebhookCall.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Filament/Pages/Dashboard.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php
  - app/Filament/RelationManagers/CardPaymentHistoryRelationManager.php
  - app/Filament/Resources/CardPaymentResource.php
  - app/Filament/Resources/CardPaymentResource/Pages/ListCardPayments.php
  - app/Filament/Resources/CardPaymentResource/Pages/ViewCardPayment.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
  - app/Filament/Support/InvoiceTable.php
  - app/Filament/Support/PayByCardAction.php
  - app/Filament/Support/PaymentLinkSection.php
  - app/Filament/Support/RecordPaymentAction.php
  - app/Filament/Support/SendInvoiceEmailAction.php
  - app/Filament/Widgets/UnresolvedPayments.php
  - app/Http/Controllers/Api/V1/TransactionBatchController.php
  - app/Http/Controllers/Api/V1/TransactionController.php
  - app/Http/Controllers/Api/V1/TransactionExamples.php
  - app/Http/Controllers/PayPageController.php
  - app/Http/Controllers/StripeWebhookController.php
  - app/Http/Requests/Api/V1/RawJsonBody.php
  - app/Http/Requests/Api/V1/StoreTransactionRequest.php
  - app/Http/Requests/Api/V1/TimeEntryPayload.php
  - app/Http/Requests/Api/V1/TransactionPayload.php
  - app/Http/Requests/Api/V1/UpdateTransactionRequest.php
  - app/Http/Resources/Api/V1/TransactionResource.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/OperationsServiceProvider.php
  - app/Providers/PaymentsServiceProvider.php
  - bootstrap/app.php
  - bootstrap/providers.php
  - composer.json
  - config/kokpit.php
  - config/scribe.php
  - config/services.php
  - config/webhook-client.php
  - database/migrations/2026_10_18_000100_allow_card_payments.php
  - database/migrations/2026_10_18_000200_add_card_payment_to_invoice_admin.php
  - database/migrations/2026_10_18_000300_add_stripe_columns_to_webhook_calls.php
  - database/migrations/2026_10_18_000400_create_payment_links_table.php
  - database/migrations/2026_10_18_000500_create_card_payments_table.php
  - database/migrations/2026_10_18_000600_add_unresolved_payments_dashboard_widget.php
  - database/migrations/2026_10_18_000900_add_external_to_transactions_table.php
  - resources/views/filament/widgets/unresolved-payments.blade.php
  - resources/views/pay/show.blade.php
  - resources/views/pdf/invoice.blade.php
  - routes/console.php
  - routes/public.php
  - tests/Arch/ActivityAllowlistTest.php
  - tests/Arch/JobContractTest.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Concurrency/PaymentMatchConcurrencyTest.php
  - tests/Concurrency/payment-match-worker.php
  - tests/Feature/Api/TransactionExternalTest.php
  - tests/Feature/Api/TransactionPaymentIncomeTest.php
  - tests/Feature/Dashboard/AdminDashboardTest.php
  - tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php
  - tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php
  - tests/Feature/Invoicing/CardPaymentCoreTest.php
  - tests/Feature/Invoicing/InvoicePdfTest.php
  - tests/Feature/Operations/HealthRegistryTest.php
  - tests/Feature/Operations/ScheduleOnOneServerTest.php
  - tests/Feature/Operations/SystemPageTest.php
  - tests/Feature/Payments/CardPaymentJourneyTest.php
  - tests/Feature/Payments/CardPaymentRecordTest.php
  - tests/Feature/Payments/CardPaymentResolutionTest.php
  - tests/Feature/Payments/CardPaymentsOverviewTest.php
  - tests/Feature/Payments/CardSwitchTest.php
  - tests/Feature/Payments/CreatePaymentLinkTest.php
  - tests/Feature/Payments/InvoiceListLinkTest.php
  - tests/Feature/Payments/LinkLifecycleTest.php
  - tests/Feature/Payments/MatchPaymentTest.php
  - tests/Feature/Payments/PartnerPayButtonTest.php
  - tests/Feature/Payments/PayButtonOutputsTest.php
  - tests/Feature/Payments/PayPageTest.php
  - tests/Feature/Payments/PaymentAlertsTest.php
  - tests/Feature/Payments/PaymentClassificationTest.php
  - tests/Feature/Payments/PaymentLinkRecordTest.php
  - tests/Feature/Payments/PaymentLinkSectionTest.php
  - tests/Feature/Payments/PruneWebhookCallsTest.php
  - tests/Feature/Payments/RefundEventTest.php
  - tests/Feature/Payments/StripeGatewayTest.php
  - tests/Feature/Payments/StripeWebhookTest.php
  - tests/Feature/Payments/SystemIndicatorsTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php
  - tests/Feature/Schema/SchemaConventionsTest.php
  - tests/Feature/Schema/TransactionTablesTest.php
  - tests/Isolation/ApiAccessTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/CardPaymentLeakTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PayButtonLeakTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Support/CanaryRegistry.php
  - tests/Support/CardPaymentFixtures.php
  - tests/Support/CardPaymentSetup.php
  - tests/Support/FakeStripeGateway.php
  - tests/Support/JobDeclaration.php
  - tests/Support/PgSchema.php
  - tests/Support/Probes/UndeclaredWebhookProbeJob.php
  - tests/Support/StripeEvents.php
  - tests/Support/StripeTestConfig.php
  - tests/Support/StripeWebhookSigner.php
  - tests/Support/TransactionContract.php
  - tests/Support/UnlockedCardPaymentMatcher.php
  - tests/Unit/Invoicing/InvoiceEnumsTest.php
covered_digest: "v3:sha256:4207e5f60f6b7b5e809772717678f4781285cae10069fb9df928bedfadd08fe7"
behavior_unverified: 0
overrides_applied: 0
advisory_review_findings:
  - id: WR-01
    verdict: "warning, not a goal blocker (recommend fixing before go-live)"
    summary: "A stored Stripe event whose queued job is lost (Redis) is never re-dispatched automatically; it is only surfaced passively on the System page"
  - id: WR-02
    verdict: "warning, same root cause as WR-01 for the link job"
    summary: "A payment link left pending by a lost job has no UI recovery"
  - id: WR-03
    verdict: "warning, operability of the deactivation sweep"
    summary: "Sweep can hit its 60 s timeout and retries permanent failures forever"
  - id: WR-04
    verdict: "warning, practically unreachable with signed Stripe payloads (recommend fixing)"
    summary: "A paid session with unreadable fields is dropped silently and marked processed"
human_verification:
  - test: "Open the pay page (/pay/<token>) at a 375 px wide viewport in the Czech and English variants, in light and dark mode"
    expected: "The card is readable and centred, nothing overflows horizontally, the retry control on the 'preparing' state is reachable"
    why_human: "Visual layout cannot be verified from code or from the Pest suite"
  - test: "Generate a sample issued PDF with card payment on, in Czech and in English, and look at the printed pay URL"
    expected: "The stable pay URL is on one line (no wrap that breaks it) and the 'Zaplatit kartou' / 'Pay by card' button is visible next to it"
    why_human: "PDF text extraction proves the text exists (journey test reads it back with Ghostscript) but not the visual one-line layout"
  - test: "Open the Platby kartou overview at the narrow breakpoint"
    expected: "The columns collapse to a usable layout; amount, type and status stay readable"
    why_human: "Responsive layout of a Filament table"
  - test: "Check diacritics in the UI font (Inter) and in the PDF font (Roboto) for 'Zaplatit kartou', 'Odkaz není platný', 'Nespárovaná'"
    expected: "All Czech diacritics render, no fallback glyphs or tofu boxes"
    why_human: "Glyph rendering is visual"
  - test: "Check the contrast of 'Zaplatit kartou' (Partner detail) and 'Zkusit znovu' (Admin section) in light and dark mode"
    expected: "Meets WCAG AA contrast (UI-SPEC F-1)"
    why_human: "Contrast is visual"
  - test: "Manual Stripe test-mode check with test keys only in the local .env: run 'stripe listen --forward-to https://kokpit.ddev.site/webhooks/stripe', put the printed secret in STRIPE_WEBHOOK_SECRET, issue a fictional invoice with card payment on, pay it with a Stripe test card, then 'stripe events resend <id>'"
    expected: "The Payment Link is created at Stripe, the pay URL redirects to it, after payment the invoice is paid with its income and the bell shows 'Přišla platba kartou'; the resent event creates no second payment"
    why_human: "The test suite never calls Stripe (fake gateway, hand-signed bodies); only the real API proves the 2026-09-30.endive pinned request/response shapes and the real Payment Link metadata round trip"
  - test: "Owner confirms ruling R11-03-1 (research assumption A8): an Admin override of the per-document card switch to ON for a client whose online-payment flag is OFF takes effect"
    expected: "Owner agrees; otherwise the effective-switch rule in CardPaymentSwitch::effective() becomes a strict three-way AND"
    why_human: "Product decision recorded as an assumption for the owner"
  - test: "Owner confirms research assumption A7: the payer's personal data (customer_details, customer_email, shipping_details, shipping, collected_information) is dropped from the stored webhook payload"
    expected: "Owner agrees that the trimmed payload is sufficient for tracing a payment"
    why_human: "Privacy/product decision recorded as an assumption for the owner"
---

# Phase 11: Stripe Payments Verification Report

**Phase Goal:** Issued invoices can be paid by card through Stripe, payments are matched automatically, and duplicate or unmatched payments are surfaced instead of lost
**Verified:** 2026-10-18T18:50:06Z
**Status:** human_needed
**Re-verification:** No, initial verification

## Goal Achievement

The goal is achieved in the codebase. Every roadmap success criterion is backed by implementation I read and by tests I ran myself (not the SUMMARY claims). The remaining items are the visual and live-Stripe checks the owner already knows about, two owner confirmations, and four review warnings (see "Code review warnings" for my view on WR-01 and WR-04). No truth failed, so the status is `human_needed`, not `gaps_found`.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: Issuing with online payment enabled creates a single-use Stripe Payment Link in a background job; a Stripe failure never blocks issuing and alerts Admin | VERIFIED | `DocumentIssuer::openPaymentLink()` writes a pending `payment_links` row in the issue transaction and pushes `CreatePaymentLink::dispatch($link->id)->afterCommit()` (DocumentIssuer.php:165-173). `CreatePaymentLink` (KokpitJob, row id as Stripe idempotency key) moves the row to `failed` with a fixed failure class and calls `AdminAlerter` (bell and mail, document number only). Tests `CreatePaymentLinkTest`, `StripeGatewayTest`, journey: pass |
| 2 | SC1b: Online payment can be disabled per client and per document, frozen at issue | VERIFIED | `CardPaymentSwitch::effective()` called from `DocumentIssuer` (line 327) and `pay_token` frozen on `invoice_admin`; migration `000200_add_card_payment_to_invoice_admin`. `CardSwitchTest` passes (global, client, document, frozen, token immutability) |
| 3 | SC2: A "Pay by card" button appears in the e-mail, the PDF and the invoice detail (Admin and Partner) and is hidden once paid | VERIFIED | E-mail: `PayLinkBlock::forMail()` appended in `SendInvoiceEmail` (line 189), returns null for cancelled or paid documents. PDF: `InvoicePdfData::cardPayment()` and `resources/views/pdf/invoice.blade.php:219-226` (lead, button, printed URL). Admin: `PayByCardAction::make` in `ViewInvoice.php:229`. Partner: `PayByCardAction` with `PartnerPayButton::urlFor` in `ViewPartnerInvoice.php:70-73`. `PayButtonOutputsTest`, `PartnerPayButtonTest`, `PaymentLinkSectionTest`, `PayButtonLeakTest`, journey (PDF text read back with Ghostscript): pass |
| 4 | SC2b: The link is deactivated when the invoice is paid by any route or stornoed, and can be regenerated after the old one is deactivated | VERIFIED | `PaymentLinks::reconcile()` is called from `RecordPayment`, `DeletePayment`, `IssueCreditNote` inside the document-locked transaction; `RegeneratePaymentLink` deactivates the old link first; partial unique index `payment_links_one_live_per_invoice`; remote switch-off by `DeactivatePaymentLinks` after commit plus a 5-minute sweep (routes/console.php). `LinkLifecycleTest` (partial and full payment, storno, credit note, payment deletion, regeneration, sweep): pass |
| 5 | SC3: The webhook endpoint rejects a bad signature, stores a valid event under a unique event id, answers immediately and processes it in a job; a repeated event is ignored | VERIFIED | `StripeSignatureValidator` uses the SDK `WebhookSignature::verifyHeader` on the raw body, 300 s tolerance, fail-closed without secret. `StripeWebhookProcessor` stores via `WebhookCall::storeStripeEvent` (INSERT ON CONFLICT DO NOTHING; partial unique index `webhook_calls_name_event_id_unique`) and dispatches `ProcessStripeWebhook` (Tries 3, Backoff, Timeout 60, Idempotent, RunsAsSystem, handler and `processed_at` in one transaction under `lockForUpdate`). Route is outside the `web` group (no CSRF, no cookie). `StripeWebhookTest`: pass |
| 6 | SC4a: A matching payment (invoice id, link id fallback, correct amount and currency) creates the payment, marks the invoice paid, creates the income, deactivates the link and notifies Admin | VERIFIED | `RecordCardPayment::link()` finds the link by metadata row id, then by Stripe link id; `match()` calls `RecordPayment::recordLocked($locked, PaymentFacts(... Card ...), null)` (payment and income, reconcile deactivates the link) and sends `CardPaymentMatchedNotification` to every Admin after commit. Amount must equal the link amount and the remaining balance, currency must equal the link currency. `MatchPaymentTest`, `CardPaymentCoreTest`, `PaymentClassificationTest`, journey: pass |
| 7 | SC4b: A second payment of the same invoice is stored as a duplicate with no income or invoice change and alerts Admin | VERIFIED | `RecordCardPayment`: `remaining <= 0` yields `CardPaymentType::Duplicate / AlreadyPaid` with no payment write; `CardPaymentAlerts::raise` after commit (bell and mail, throttle per record). `PaymentClassificationTest`, `PaymentAlertsTest`, journey: pass |
| 8 | SC5a: Unmatched payments and refund events are recorded and alerted | VERIFIED | Unmatched reasons: undetermined, cancelled, currency, amount; `RecordRefundEvent` for `refund.created`; `CardPaymentAlerts` has duplicate, unmatched, refund notices. `PaymentAlertsTest`, `RefundEventTest`, `PaymentClassificationTest`: pass |
| 9 | SC5b: Admin works through a payments overview with duplicate and unmatched filters and manual resolution | VERIFIED | `CardPaymentResource` with `CardPaymentTab` (Nevyřešené, Všechny, matched, duplicate, unmatched, refund, resolved) whose counts and rows share one SQL condition; `ViewCardPayment` actions `assignCardPayment`, `resolveCardPayment` (note), `recordPaymentReturn` (the three resolutions); DB checks make a resolution final. `CardPaymentsOverviewTest`, `CardPaymentResolutionTest`, `CardPaymentRecordTest`: pass |
| 10 | SC5c: Unresolved payments show as a dashboard tile and unprocessed webhooks on the System page | VERIFIED | `UnresolvedPayments` widget registered in `Dashboard::getWidgets()` and `DashboardWidget` enum (+ migration `000600`); `UnprocessedWebhooksIndicator` and `StripeConfigIndicator` registered in `OperationsServiceProvider` (Error if any failed, Warning after 900 s waiting). `UnresolvedPaymentsWidgetTest`, `SystemIndicatorsTest`: pass |
| 11 | SC5d: Old webhook events are pruned | VERIFIED | `WebhookCall::prunable()` (processed more than 90 days ago only, never failed or waiting) plus `PruneProcessedWebhookCalls` scheduled daily on one server (routes/console.php). `PruneWebhookCallsTest`: pass |
| 12 | PA-07 / D-12: Idempotence and no double booking are enforced by DB unique constraints and survive concurrency | VERIFIED | Unique `card_payments_event_unique`, `card_payments_session_unique` (partial), `card_payments_payment_intent_unique` (partial, non-refund), `card_payments_refund_unique`, `card_payments_invoice_payment_unique`; `CardPayment::recordOnce()` uses ON CONFLICT DO NOTHING; guard trigger `card_payments_guard`; lock order webhook row, document, record, payment, income. `CardPaymentRecordTest` and the multi-process `PaymentMatchConcurrencyTest` (5 tests): pass |
| 13 | Constraint: a Partner sees nothing of the machinery beyond the pay button of an own payable document | VERIFIED | `PaymentLink`, `CardPayment`, `WebhookCall` use `DeniesPartners` and `AdminOnlyPolicy`; `PartnerPayButton` returns only a URL; `routes/public.php` holds exactly `pay.show` and `webhooks.stripe`. `CardPaymentLeakTest` (13 tests, route walk, Livewire, search, dashboard, bell) and `PayButtonLeakTest`: pass |

**Score:** 13/13 truths verified (0 present but behavior-unverified, 0 overrides)

No truth was flagged `coincidental-reliance`: the preconditions of the behavior-dependent truths (document locked before link, claim of the record before the payment write, processed marker in the handler's transaction) are established by the code itself, and the concurrency test exercises the ordering with real worker processes.

### Deferred Items

None. The out-of-scope capabilities (disputes, fees, refund automation, Connect) are explicit v2 opt-outs in `COVERAGE.md` (PAY-01), not gaps of this phase, and no later phase of the milestone claims them.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Payments/Stripe/StripeWebhookProcessor.php`, `StripeSignatureValidator.php`, `app/Http/Controllers/StripeWebhookController.php` | Signed, idempotent intake | VERIFIED | Read in full; wired through `routes/public.php` and `config/webhook-client.php` |
| `app/Domain/Payments/Jobs/ProcessStripeWebhook.php` | Locked, transactional processing job | VERIFIED | Handler map wired to `RecordCardPayment` and `RecordRefundEvent` |
| `app/Domain/Payments/Actions/RecordCardPayment.php` | Matching and classification | VERIFIED | Substantive, wired from the job, uses `RecordPayment::recordLocked()` |
| `app/Domain/Payments/Jobs/CreatePaymentLink.php` | Background link creation | VERIFIED | Dispatched from `DocumentIssuer` after commit |
| `app/Domain/Payments/Support/PaymentLinks.php` | Reconcile invariant | VERIFIED | Called by `RecordPayment`, `DeletePayment`, `IssueCreditNote` |
| `database/migrations/2026_10_18_0001..0006*` | Schema with constraints | VERIFIED | Partial and plain unique indexes, CHECKs and guard triggers present; applied in the test database |
| `app/Filament/Resources/CardPaymentResource*` | Overview and resolution | VERIFIED | Tabs, actions, Admin-only |
| `app/Http/Controllers/PayPageController.php`, `resources/views/pay/show.blade.php` | Stable pay URL | VERIFIED | 302 only to an allowed stripe.com host, no-store, no-referrer |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `DocumentIssuer` | `CreatePaymentLink` | `dispatch()->afterCommit()` | WIRED | line 173 |
| `StripeWebhookController` | `StripeWebhookProcessor` | config lookup `stripe`, signature validator in `config/webhook-client.php` | WIRED | |
| `StripeWebhookProcessor` | `ProcessStripeWebhook` | `dispatch()` for a new or unprocessed row | WIRED | |
| `ProcessStripeWebhook` | `RecordCardPayment` / `RecordRefundEvent` | `handlers()` map | WIRED | |
| `RecordCardPayment` | `RecordPayment::recordLocked` | `match()` | WIRED | Payment, income, link deactivation in one transaction |
| `RecordPayment` / `DeletePayment` / `IssueCreditNote` | `PaymentLinks::reconcile` | constructor injection | WIRED | |
| `PayLinkBlock` / `InvoicePdfData` / `PayByCardAction` | `PayUrl` (stable URL) | direct | WIRED | E-mail, PDF, Admin and Partner detail |
| `CardPaymentAlerts` | `AdminAlerter` | after-commit callback in `RecordCardPayment::record()` | WIRED | |
| Schedule | `DeactivatePaymentLinks`, `PruneProcessedWebhookCalls` | `routes/console.php` | WIRED | every 5 minutes, daily |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Pay page | link URL / outcome | `PayPageState` reading `payment_links` | Yes (DB row written by the job) | FLOWING |
| Unresolved tile and tabs | counts | `UnresolvedPayments` / `CardPaymentTab` SQL over `card_payments` | Yes | FLOWING |
| System slot | total, failed, oldest | aggregate over `webhook_calls` | Yes | FLOWING |
| PDF / e-mail block | pay URL | `PayUrl::forInvoice` from frozen `pay_token` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Whole phase test surface | `ddev exec vendor/bin/pest tests/Feature/Payments tests/Concurrency/PaymentMatchConcurrencyTest.php tests/Isolation/CardPaymentLeakTest.php tests/Isolation/PayButtonLeakTest.php tests/Arch tests/Feature/Invoicing/CardPaymentCoreTest.php tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php` | 502 passed, 42 608 assertions, 221 s (isolated run) | PASS |
| Sensitive-content check | `scripts/check-sensitive.sh` | clean | PASS |

Environment caveat (not a code defect): three earlier runs of the same command, executed while another session was running the full suite against the one shared `kokpit_test` database, failed 63 to 69 tests with `relation "settings" does not exist` / `column ... does not exist` (database being rebuilt under the run). Re-run alone, everything passes. The single shared test database means two concurrent Pest runs corrupt each other; worth knowing for CI-like use in DDEV.

### Probe Execution

Step 7c: SKIPPED. The phase declares no `probe-*.sh` scripts and is not a migration or tooling phase.

### Requirements Coverage

Every ID appears in at least one PLAN `requirements:` frontmatter and in REQUIREMENTS.md (all ticked and mapped to Phase 11 in the traceability table). No orphaned requirement.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| PA-01 | 11-01, 11-06, 11-07, 11-12, 11-03 | Single-use Payment Link created by queued job at issue; failure never blocks issue, alerts Admin | SATISFIED | Truth 1 |
| PA-02 | 11-03, 11-05, 11-08, 11-12, 11-15 | Pay button in e-mail, PDF, invoice detail (Admin and Partner); hidden when paid | SATISFIED | Truth 3 (visual checks pending) |
| PA-03 | 11-03, 11-12 | Online payment can be disabled per client and per document | SATISFIED | Truth 2 |
| PA-04 | 11-01, 11-06, 11-10, 11-12 | Link deactivated on payment by any route or storno; regenerable (old one first) | SATISFIED | Truth 4 |
| PA-05 | 11-04, 11-14, 11-18 | Signature verified, event stored with unique id, immediate answer, job processing | SATISFIED | Truth 5 |
| PA-06 | 11-09, 11-13 | Match by invoice id (fallback link id) with amount and currency check; payment, paid, income, link off, notify | SATISFIED | Truth 6 |
| PA-07 | 11-04, 11-09, 11-13, 11-16, 11-18 | Duplicate stored without income, alerted; repeats ignored; DB unique constraints | SATISFIED | Truths 7 and 12 |
| PA-08 | 11-09, 11-11, 11-13, 11-14, 11-16, 11-17, 11-19 | Unmatched and refund recorded and alerted; overview with filters and manual resolution; pruning | SATISFIED | Truths 8 to 11 |

### Anti-Patterns Found

Debt-marker scan (`TBD|FIXME|XXX|TODO|HACK`) over the 178 files changed by the phase under app, database, routes, resources, config, bootstrap and tests: no match. No stubs found in the artifacts read; empty-array/null returns seen are guards (for example `RecordCardPayment` returning null for an unpaid session) and are exercised by tests.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Payments/Actions/RecordCardPayment.php` | 78-81 | Silent `return null` for a paid session with unreadable fields (review WR-04) | Warning | Event marked processed with no record and no alert |
| `app/Domain/Payments/Stripe/StripeWebhookProcessor.php` and migration `000300` | 17-22; 21, 40 | Docblock and migration comment promise a "lost-dispatch repair" that exists only for a failed `dispatch()` (review WR-01) | Warning | See below |
| `app/Domain/Payments/Jobs/DeactivatePaymentLinks.php` | 32, 41-73 | Sequential Stripe calls vs 60 s timeout; permanent failures retried forever (WR-03) | Warning | Operability only |
| `app/Domain/Payments/Actions/RegeneratePaymentLink.php` | 55-59 | A `pending` link cannot be regenerated (WR-02) | Warning | Needs a lost job to occur |

### Code review warnings: my view on the goal

I re-read the code behind WR-01 and WR-04 and confirmed both findings are accurate. I checked: no scheduled job or command touches `webhook_calls.processed_at` except the prune and the health indicator; `config/queue.php` defaults to Redis; `StripeWebhookProcessor` re-dispatches a stored, unprocessed event only when Stripe delivers it again (which happens after a failed dispatch or a manual resend, not after a lost job).

**WR-01 (lost job never re-dispatched): warning, not a blocker of the goal.** The goal says duplicate or unmatched payments are "surfaced instead of lost". In the WR-01 scenario nothing is lost in the data sense: the signed event is stored (with its unique id), the row stays `processed_at IS NULL`, `UnprocessedWebhooksIndicator` turns the System slot to Warning after 15 minutes (and Error if it failed), and the repair path exists by hand (resend the event from the Stripe dashboard; the processor re-dispatches a stored unprocessed row, README documents `stripe events resend`). The weakness is that surfacing is passive (System page only, no bell or mail) and the repair is manual, so a customer who paid can wait with an unpaid invoice until the Admin looks at the System page. That sits against the core value ("no unpaid invoice slips through unnoticed") but it needs a Redis job loss to occur and the invoice would later appear as overdue as well. I recommend fixing it before production go-live with the small scheduled re-dispatch job the review proposes (the handler is already idempotent under the row lock). Note that `COVERAGE.md` says "stored events are re-dispatched when unprocessed", which overstates the current behavior, and the migration comment promises a repair that is not implemented; correct the wording or implement the sweep. WR-02 is the same loss mode for the link job (a pending row with no job) and should be fixed together with WR-01.

**WR-04 (silent return for an unreadable paid session): warning, not a blocker.** For a verified Stripe payload with `payment_status = paid`, `id`, session id, a positive integer `amount_total` and a three-letter currency are always present, so the branch is practically unreachable; the signature check and the DB constraints (`amount_minor > 0`) mean a malformed row could not be stored anyway. But when it does trigger the failure mode is exactly the silent one this phase exists to prevent, and the fix is two lines (return null only for `! isPaid()`, throw for the other conditions so the event ends as `failed`, turns the System slot to Error and raises the failed-job alert). Recommended before go-live; it does not make any success criterion false.

WR-03 and the info items (IN-01 to IN-06) do not undermine the goal. One I would act on at deployment: IN-05, confirm the balancer overwrites `X-Forwarded-For`, because both public throttles are keyed on the client IP.

### Human Verification Required

See the `human_verification` frontmatter (9 items). Summary:

1. Pay page at 375 px (cs and en, light and dark).
2. One-line printed pay URL in a sample PDF, cs and en.
3. Narrow breakpoint of the payments overview columns.
4. Diacritics in Inter (UI) and Roboto (PDF).
5. Contrast of "Zaplatit kartou" (Partner) and "Zkusit znovu".
6. Manual Stripe test-mode round trip with test keys (`stripe listen`, test card, `stripe events resend`).
7. Owner confirmation of ruling R11-03-1 (assumption A8: an Admin override to ON for a client whose flag is OFF takes effect).
8. Owner confirmation of research assumption A7 (payer personal data dropped from the stored webhook payload).

(Items 1 to 5 are the known UI-SPEC visual backstops; item 6 is the only check that exercises the real Stripe API, which the suite never calls.)

### Gaps Summary

No gaps. All 13 truths (the 5 roadmap success criteria split into their parts, plus the idempotence and Partner-isolation constraints) are verified against the code and an isolated, passing run of the phase's 502 tests. The phase is `human_needed` only because of the known visual and live-Stripe checks and the two owner confirmations. The four review warnings are advisory; WR-01 (with WR-02) and WR-04 are worth fixing before production use because they weaken the "nothing slips through unnoticed" promise in rare failure modes, but they do not falsify the phase goal.

---

_Verified: 2026-10-18T18:50:06Z_
_Verifier: Claude (gsd-verifier)_
