---
phase: "11"
slug: "stripe-payments"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: draft
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-18"
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.2 on PHPUnit 13.3, DDEV PostgreSQL + Redis |
| **Config file** | `phpunit.xml`, `tests/Pest.php` (existing) |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Payments` |
| **Full suite command** | `ddev exec vendor/bin/pest` (gate: `ddev composer ci`) |
| **Estimated runtime** | ~120 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** the touched file's targeted test plus `ddev exec vendor/bin/pest tests/Arch`
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- **Before `/gsd-verify-work`:** full suite and `ddev composer ci` green, `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

Requirement-level coverage (test files as planned in 11-01 to 11-20):

| Requirement | Behavior | Test Type | Automated Command | Plans |
|-------------|----------|-----------|-------------------|-------|
| PA-01 | Link created in a job after commit; Stripe failure never blocks issuing and alerts Admin | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php tests/Feature/Payments/PaymentLinkRecordTest.php tests/Feature/Payments/StripeGatewayTest.php` | 11-01, 11-06, 11-07, 11-12 |
| PA-02 | Pay button in PDF, e-mail, Admin and Partner detail; pay page states | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Payments/PayPageTest.php tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Payments/PaymentLinkSectionTest.php tests/Feature/Payments/PartnerPayButtonTest.php tests/Isolation/PayButtonLeakTest.php` | 11-03, 11-05, 11-08, 11-12, 11-15 |
| PA-03 | Global, client and document switches, frozen at issue | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardSwitchTest.php` | 11-03, 11-12 |
| PA-04 | Link deactivated on payment, storno, credit note, payment deletion; regenerate; one live link; sweep | feature + DB | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Payments/PaymentLinkRecordTest.php` | 11-01, 11-06, 11-10, 11-12 |
| PA-05 | Webhook signature, unique event, async processing, replay, failure recorded | feature + concurrency | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeWebhookTest.php tests/Feature/Payments/SystemIndicatorsTest.php tests/Concurrency/PaymentMatchConcurrencyTest.php` | 11-04, 11-14, 11-18 |
| PA-06 | Matching payment creates payment and income, deactivates link, notifies Admin | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CardPaymentCoreTest.php tests/Feature/Payments/MatchPaymentTest.php tests/Feature/Invoicing` | 11-02, 11-09, 11-13 |
| PA-07 | Duplicate payment stored without income, alerted; repeats ignored; DB constraints; concurrency | feature + concurrency | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentRecordTest.php tests/Feature/Payments/PaymentClassificationTest.php tests/Feature/Payments/PaymentAlertsTest.php tests/Concurrency/PaymentMatchConcurrencyTest.php` | 11-04, 11-09, 11-13, 11-16, 11-18 |
| PA-08 | Unmatched and refund records and alerts, overview, resolutions, dashboard tile, System slot, prune | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentsOverviewTest.php tests/Feature/Payments/CardPaymentResolutionTest.php tests/Feature/Payments/RefundEventTest.php tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php tests/Feature/Payments/SystemIndicatorsTest.php tests/Feature/Payments/PruneWebhookCallsTest.php` | 11-09, 11-11, 11-13, 11-14, 11-16, 11-17, 11-19 |
| all | End-to-end journey and Partner sweep | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentJourneyTest.php tests/Isolation/CardPaymentLeakTest.php` | 11-20 |

Per task (every task writes its test first, RED, then implements; there is no separate Wave 0 plan):

| Task ID | Plan | Wave | Requirement | Threat Ref | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------|-------------------|-------------|--------|
| 11-01-01 | 11-01 | 1 | PA-01, PA-04, PA-05 | T-11-01, T-11-02, T-11-03, T-11-SC | checkpoint (blocking-human) | `ddev composer show stripe/stripe-php 2>&1 \| grep -E "versions? *:.*22\."` | n/a | ✅ green |
| 11-01-02 | 11-01 | 1 | PA-01, PA-04, PA-05 | T-11-01, T-11-02, T-11-03, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeGatewayTest.php tests/Feature/Repo/EnvExampleTest.php` | in task (RED first) | ✅ green |
| 11-01-03 | 11-01 | 1 | PA-01, PA-04, PA-05 | T-11-01, T-11-02, T-11-03, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments tests/Arch` | in task (RED first) | ✅ green |
| 11-02-01 | 11-02 | 1 | PA-06, PA-08 | T-11-04, T-11-05, T-11-06, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Finance` | in task (RED first) | ✅ green |
| 11-02-02 | 11-02 | 1 | PA-06, PA-08 | T-11-04, T-11-05, T-11-06, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CardPaymentCoreTest.php tests/Feature/Invoicing/PaymentsTest.php tests/Feature/Invoicing/PaymentIncomeTest.php` | in task (RED first) | ✅ green |
| 11-02-03 | 11-02 | 1 | PA-06, PA-08 | T-11-04, T-11-05, T-11-06, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing` | in task (RED first) | ✅ green |
| 11-03-01 | 11-03 | 2 | PA-03, PA-02 | T-11-07, T-11-08, T-11-09, T-11-10, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CardSwitchTest.php tests/Feature/Invoicing tests/Arch` | in task (RED first) | ✅ green |
| 11-03-02 | 11-03 | 2 | PA-03, PA-02 | T-11-07, T-11-08, T-11-09, T-11-10, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardSwitchTest.php tests/Feature/Invoicing/DraftLifecycleTest.php tests/Feature/Invoicing/CreditNoteTest.php tests/Feature/Invoicing/ConvertProformaTest.php` | in task (RED first) | ✅ green |
| 11-03-03 | 11-03 | 2 | PA-03, PA-02 | T-11-07, T-11-08, T-11-09, T-11-10, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardSwitchTest.php tests/Feature/Invoicing/DraftEditorTest.php tests/Feature/Operations/SettingsPageTest.php tests/Feature/Clients` | in task (RED first) | ✅ green |
| 11-04-01 | 11-04 | 2 | PA-05, PA-07 | T-11-11, T-11-12, T-11-13, T-11-14, T-11-15, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeWebhookTest.php tests/Arch` | in task (RED first) | ✅ green |
| 11-04-02 | 11-04 | 2 | PA-05, PA-07 | T-11-11, T-11-12, T-11-13, T-11-14, T-11-15, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeWebhookTest.php tests/Feature/Repo/EnvExampleTest.php` | in task (RED first) | ✅ green |
| 11-04-03 | 11-04 | 2 | PA-05, PA-07 | T-11-11, T-11-12, T-11-13, T-11-14, T-11-15, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeWebhookTest.php tests/Arch/JobContractTest.php` | in task (RED first) | ✅ green |
| 11-05-01 | 11-05 | 3 | PA-02 | T-11-16, T-11-17, T-11-18, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/SendInvoiceEmailTest.php` | in task (RED first) | ✅ green |
| 11-05-02 | 11-05 | 3 | PA-02 | T-11-16, T-11-17, T-11-18, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/RegeneratePdfTest.php` | in task (RED first) | ✅ green |
| 11-05-03 | 11-05 | 3 | PA-02 | T-11-16, T-11-17, T-11-18, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/EmailDialogTest.php` | in task (RED first) | ✅ green |
| 11-06-01 | 11-06 | 3 | PA-01, PA-04 | T-11-19, T-11-20, T-11-21, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentLinkRecordTest.php tests/Arch tests/Isolation tests/Feature/Schema` | in task (RED first) | ✅ green |
| 11-06-02 | 11-06 | 3 | PA-01, PA-04 | T-11-19, T-11-20, T-11-21, T-11-SC | feature + DB | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentLinkRecordTest.php` | in task (RED first) | ✅ green |
| 11-07-01 | 11-07 | 4 | PA-01 | T-11-22, T-11-23, T-11-24, T-11-25, T-11-26, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php tests/Feature/Invoicing/IssueDocumentTest.php tests/Feature/Invoicing/IssueRollbackTest.php tests/Arch` | in task (RED first) | ✅ green |
| 11-07-02 | 11-07 | 4 | PA-01 | T-11-22, T-11-23, T-11-24, T-11-25, T-11-26, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php tests/Feature/Operations/AdminAlertTest.php` | in task (RED first) | ✅ green |
| 11-07-03 | 11-07 | 4 | PA-01 | T-11-22, T-11-23, T-11-24, T-11-25, T-11-26, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php` | in task (RED first) | ✅ green |
| 11-08-01 | 11-08 | 4 | PA-02 | T-11-27, T-11-28, T-11-29, T-11-30, T-11-31, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PayPageTest.php` | in task (RED first) | ✅ green |
| 11-08-02 | 11-08 | 4 | PA-02 | T-11-27, T-11-28, T-11-29, T-11-30, T-11-31, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PayPageTest.php` | in task (RED first) | ✅ green |
| 11-08-03 | 11-08 | 4 | PA-02 | T-11-27, T-11-28, T-11-29, T-11-30, T-11-31, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments tests/Isolation tests/Arch` | in task (RED first) | ✅ green |
| 11-09-01 | 11-09 | 4 | PA-07, PA-08, PA-06 | T-11-32, T-11-33, T-11-34, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentRecordTest.php tests/Feature/Invoicing/DeletePaymentTest.php tests/Arch tests/Isolation tests/Feature/Schema` | in task (RED first) | ✅ green |
| 11-09-02 | 11-09 | 4 | PA-07, PA-08, PA-06 | T-11-32, T-11-33, T-11-34, T-11-SC | feature + DB | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentRecordTest.php` | in task (RED first) | ✅ green |
| 11-10-01 | 11-10 | 5 | PA-04 | T-11-35, T-11-36, T-11-37, T-11-38, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Invoicing tests/Arch` | in task (RED first) | ✅ green |
| 11-10-02 | 11-10 | 5 | PA-04 | T-11-35, T-11-36, T-11-37, T-11-38, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Invoicing/CreditNoteTest.php tests/Feature/Invoicing/DeletePaymentTest.php tests/Arch/PaymentDeletionPathTest.php` | in task (RED first) | ✅ green |
| 11-10-03 | 11-10 | 5 | PA-04 | T-11-35, T-11-36, T-11-37, T-11-38, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php` | in task (RED first) | ✅ green |
| 11-11-01 | 11-11 | 5 | PA-08, PA-07 | T-11-39, T-11-40, T-11-41, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentsOverviewTest.php tests/Isolation tests/Arch` | in task (RED first) | ✅ green |
| 11-11-02 | 11-11 | 5 | PA-08, PA-07 | T-11-39, T-11-40, T-11-41, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentsOverviewTest.php` | in task (RED first) | ✅ green |
| 11-11-03 | 11-11 | 5 | PA-08, PA-07 | T-11-39, T-11-40, T-11-41, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentsOverviewTest.php tests/Isolation tests/Arch` | in task (RED first) | ✅ green |
| 11-12-01 | 11-12 | 6 | PA-02, PA-04, PA-01, PA-03 | T-11-42, T-11-43, T-11-44, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentLinkSectionTest.php tests/Feature/Invoicing/InvoiceDetailTest.php` | in task (RED first) | ✅ green |
| 11-12-02 | 11-12 | 6 | PA-02, PA-04, PA-01, PA-03 | T-11-42, T-11-43, T-11-44, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentLinkSectionTest.php tests/Feature/Invoicing/InvoiceDetailTest.php` | in task (RED first) | ✅ green |
| 11-12-03 | 11-12 | 6 | PA-02, PA-04, PA-01, PA-03 | T-11-42, T-11-43, T-11-44, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/InvoiceListLinkTest.php tests/Feature/Invoicing/InvoiceListTest.php tests/Feature/Clients` | in task (RED first) | ✅ green |
| 11-13-01 | 11-13 | 6 | PA-06, PA-07, PA-08 | T-11-45, T-11-46, T-11-47, T-11-48, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/MatchPaymentTest.php tests/Feature/Payments/StripeWebhookTest.php tests/Feature/Invoicing tests/Feature/Finance` | in task (RED first) | ✅ green |
| 11-13-02 | 11-13 | 6 | PA-06, PA-07, PA-08 | T-11-45, T-11-46, T-11-47, T-11-48, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentClassificationTest.php tests/Feature/Payments/MatchPaymentTest.php` | in task (RED first) | ✅ green |
| 11-13-03 | 11-13 | 6 | PA-06, PA-07, PA-08 | T-11-45, T-11-46, T-11-47, T-11-48, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Feature/Finance` | in task (RED first) | ✅ green |
| 11-14-01 | 11-14 | 6 | PA-08, PA-05 | T-11-49, T-11-50, T-11-51, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/SystemIndicatorsTest.php tests/Feature/Operations` | in task (RED first) | ✅ green |
| 11-14-02 | 11-14 | 6 | PA-08, PA-05 | T-11-49, T-11-50, T-11-51, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/SystemIndicatorsTest.php tests/Feature/Operations/HealthRegistryTest.php tests/Feature/Operations/SystemPageTest.php` | in task (RED first) | ✅ green |
| 11-14-03 | 11-14 | 6 | PA-08, PA-05 | T-11-49, T-11-50, T-11-51, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PruneWebhookCallsTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch` | in task (RED first) | ✅ green |
| 11-15-01 | 11-15 | 7 | PA-02 | T-11-52, T-11-53, T-11-54, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PartnerPayButtonTest.php tests/Feature/Invoicing/PartnerInvoiceResourceTest.php` | in task (RED first) | ✅ green |
| 11-15-02 | 11-15 | 7 | PA-02 | T-11-52, T-11-53, T-11-54, T-11-SC | isolation | `ddev exec vendor/bin/pest tests/Isolation tests/Feature/Payments/PartnerPayButtonTest.php` | in task (RED first) | ✅ green |
| 11-16-01 | 11-16 | 7 | PA-07, PA-08 | T-11-55, T-11-56, T-11-57, T-11-58, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentAlertsTest.php tests/Feature/Operations/AdminAlertTest.php` | in task (RED first) | ✅ green |
| 11-16-02 | 11-16 | 7 | PA-07, PA-08 | T-11-55, T-11-56, T-11-57, T-11-58, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/PaymentAlertsTest.php tests/Feature/Payments/PaymentClassificationTest.php` | in task (RED first) | ✅ green |
| 11-16-03 | 11-16 | 7 | PA-07, PA-08 | T-11-55, T-11-56, T-11-57, T-11-58, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments` | in task (RED first) | ✅ green |
| 11-17-01 | 11-17 | 7 | PA-08 | T-11-59, T-11-60, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Arch` | in task (RED first) | ✅ green |
| 11-17-02 | 11-17 | 7 | PA-08 | T-11-59, T-11-60, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Feature/Schema/DashboardWidgetPreferencesTableTest.php` | in task (RED first) | ✅ green |
| 11-18-01 | 11-18 | 7 | PA-07, PA-06, PA-05 | T-11-61, T-11-62, T-11-SC | tracer (concurrency) | `ddev exec vendor/bin/pest tests/Concurrency/PaymentMatchConcurrencyTest.php` | in task (RED first) | ✅ green |
| 11-18-02 | 11-18 | 7 | PA-07, PA-06, PA-05 | T-11-61, T-11-62, T-11-SC | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/PaymentMatchConcurrencyTest.php` | in task (RED first) | ✅ green |
| 11-19-01 | 11-19 | 6 | PA-08 | T-11-63, T-11-64, T-11-65, T-11-66, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentResolutionTest.php tests/Feature/Invoicing tests/Feature/Finance` | in task (RED first) | ✅ green |
| 11-19-02 | 11-19 | 6 | PA-08 | T-11-63, T-11-64, T-11-65, T-11-66, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentResolutionTest.php tests/Feature/Finance` | in task (RED first) | ✅ green |
| 11-19-03 | 11-19 | 6 | PA-08 | T-11-63, T-11-64, T-11-65, T-11-66, T-11-SC | feature | `ddev exec vendor/bin/pest tests/Feature/Payments tests/Isolation tests/Arch` | in task (RED first) | ✅ green |
| 11-20-01 | 11-20 | 8 | PA-01, PA-02, PA-03, PA-04, PA-05, PA-06, PA-07, PA-08 | T-11-67, T-11-68, T-11-69, T-11-SC | tracer (feature) | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentJourneyTest.php` | in task (RED first) | ✅ green |
| 11-20-02 | 11-20 | 8 | PA-01, PA-02, PA-03, PA-04, PA-05, PA-06, PA-07, PA-08 | T-11-67, T-11-68, T-11-69, T-11-SC | isolation | `ddev exec vendor/bin/pest tests/Isolation` | in task (RED first) | ✅ green |
| 11-20-03 | 11-20 | 8 | PA-01, PA-02, PA-03, PA-04, PA-05, PA-06, PA-07, PA-08 | T-11-67, T-11-68, T-11-69, T-11-SC | gate | `ddev composer ci` | n/a | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Each plan's tracer task creates the test scaffolding it needs, so no separate Wave 0 plan exists. Where each item lands:

- [x] `stripe/stripe-php:^22.0` installed behind the blocking-human legitimacy checkpoint (plan 11-01, Task 1)
- [x] `tests/Support/FakeStripeGateway.php`, `tests/Support/StripeTestConfig.php` and the `StripeGateway` binding (plan 11-01)
- [x] `tests/Support/CardPaymentSetup.php` (plan 11-03)
- [x] `tests/Support/StripeWebhookSigner.php`, `tests/Support/StripeEvents.php` (secret and ids assembled at runtime from fragments; plan 11-04, extended by 11-13 and 11-16)
- [x] `tests/Support/CardPaymentFixtures.php` (plan 11-11)
- [x] `tests/Concurrency/payment-match-worker.php`, `tests/Support/UnlockedCardPaymentMatcher.php`, `tests/Concurrency/PaymentMatchConcurrencyTest.php` (plan 11-18)
- [x] Edits to `JobContractTest` (11-04, 11-07, 11-10, 11-14), `HealthRegistryTest` (11-14), `ActivityAllowlistTest`, `ModelDeclarationTest`, `CanaryRegistry`, `PgSchema` (11-06, 11-09; PgSchema also 11-04), `RouteWalkTest` (11-11), `EnvExampleTest` keys (11-01, 11-04), `RepositoryFilesTest` (11-20), `DashboardWidgetPreferencesTableTest` (11-17)
- [x] Pay-route rendering resolved without a spike: a standalone Blade document under `SetUpPanel:admin` outside the web group (plan 11-08, R11-08-1; research Assumption A3)

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real Stripe test-mode payment through a Payment Link | PA-01, PA-06 | Needs a live Stripe test account and keys, which never enter the repository | Use the Stripe CLI to forward webhooks to a local instance and pay with a Stripe test card |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 180s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-10-18 by plan 11-20: `ddev composer ci` green (6347 tests passed, 3 skipped, Pint, PHPStan level 8 and the licence allowlist clean), `scripts/check-sensitive.sh` clean. The manual Stripe test-mode check (Manual-Only Verifications) is an optional UAT item recorded in the 11-20 SUMMARY.
