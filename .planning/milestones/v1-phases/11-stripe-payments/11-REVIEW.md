---
phase: 11-stripe-payments
reviewed: 2026-10-18T00:00:00Z
depth: standard
files_reviewed: 104
files_reviewed_list:
  - app/Domain/Dashboard/Enums/DashboardWidget.php
  - app/Domain/Finance/Actions/RecordTransaction.php
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
  - app/Http/Controllers/PayPageController.php
  - app/Http/Controllers/StripeWebhookController.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/OperationsServiceProvider.php
  - app/Providers/PaymentsServiceProvider.php
  - bootstrap/app.php
  - bootstrap/providers.php
  - composer.json
  - config/kokpit.php
  - config/services.php
  - config/webhook-client.php
  - database/migrations/2026_10_18_000100_allow_card_payments.php
  - database/migrations/2026_10_18_000200_add_card_payment_to_invoice_admin.php
  - database/migrations/2026_10_18_000300_add_stripe_columns_to_webhook_calls.php
  - database/migrations/2026_10_18_000400_create_payment_links_table.php
  - database/migrations/2026_10_18_000500_create_card_payments_table.php
  - database/migrations/2026_10_18_000600_add_unresolved_payments_dashboard_widget.php
  - resources/views/filament/widgets/unresolved-payments.blade.php
  - resources/views/pay/show.blade.php
  - resources/views/pdf/invoice.blade.php
  - routes/console.php
  - routes/public.php
findings:
  critical: 0
  warning: 4
  info: 6
  total: 10
status: issues_found
---

# Phase 11: Code Review Report

**Reviewed:** 2026-10-18
**Depth:** standard
**Files Reviewed:** 104
**Status:** issues_found

## Summary

All 104 files were read (modified files from the diff against the commit before the first Phase 11 commit, new files in full), plus the vendor code the phase leans on: spatie/laravel-webhook-client `WebhookProcessor` and `ProcessWebhookJob`, stripe-php `WebhookSignature`, `KokpitJob`, `RunsAsSystem`, `Money`, `InvoiceBalances` and `ConvertProforma`. The sensitive-data checker (`scripts/check-sensitive.sh`) is clean on the scope.

The core money path is solid. I tried to break each of these and could not:
- **Webhook signature:** verified with the SDK on the raw body, 300 s tolerance, fail-closed without a secret, constant time.
- **Idempotency:** a unique `(name, event_id)` index plus `ON CONFLICT DO NOTHING`, the job row locked under `FOR UPDATE`, and the handler in the same transaction as the processed marker. Event, session, payment intent and refund id are unique.
- **Amounts:** integer minor units only. The match requires `amount_total == link amount == remaining` and the currency equal, and everything else lands as a recorded unmatched/duplicate row.
- **Lock order:** webhook row, then document, then card payment/link/payment/income. Every path I traced (`RecordCardPayment`, `AssignCardPayment`, `DeletePayment`, `CreatePaymentLink`, `RegeneratePaymentLink`, `RecordPayment`, `IssueCreditNote`) takes them in that order, so I found no deadlock cycle.
- **Redirect target:** the stripe.com host rule is enforced three times (PHP regex, `StripeUrl`, DB CHECK), and I could not construct a bypass.
- **Partner isolation:** `PaymentLink`, `CardPayment` and `WebhookCall` use `DeniesPartners` plus `AdminOnlyPolicy`. The Partner button goes through `PartnerPayButton`, which returns only a URL. The pay token is not in the activity-log allowlist and not in any API resource.
- **Token:** 32 chars from a CSPRNG, a DB regex CHECK and a unique index.

No blocker was found. The four warnings are all recovery/operability gaps where a money event or a card link can end up stuck with no automatic repair, which matters against the core value ("nothing slips through unnoticed"). The info items are drift risks and small inconsistencies.

## Warnings

### WR-01: A stored Stripe event whose queued job is lost is never re-dispatched

**File:** `app/Domain/Payments/Stripe/StripeWebhookProcessor.php:17-22,55-57` and `database/migrations/2026_10_18_000300_add_stripe_columns_to_webhook_calls.php:21,40`
**Issue:** The processor docblock says that if the first dispatch was lost, "Stripe's own redelivery repairs it". That only holds when `dispatch()` throws, because then the controller answers 500 and Stripe retries. If the push succeeds and the job is lost afterwards (Redis restart without persistence, flushed or evicted queue, job deleted), Stripe already got its 200 and never redelivers. The row stays `processed_at IS NULL` and not `failed_at`. The migration comment promises a "lost-dispatch repair" for `webhook_calls_unprocessed_index`, but nothing implements it: grepping `app/` and `routes/` for `processed_at` finds only the job, the model and the health indicator, and `routes/console.php` schedules no such sweep. The result is money taken by Stripe, an invoice still unpaid, and only a Health warning after 15 minutes. `ProcessStripeWebhook::failed()` is not reached either, because the job never ran.
**Fix:** Add a scheduled idempotent job (every few minutes, `onOneServer`) that re-dispatches stored, unprocessed, not-failed Stripe events older than a threshold. The handler is already safe to run twice.
```php
// App\Domain\Payments\Jobs\RedispatchStaleWebhooks (KokpitJob, #[Idempotent])
WebhookCall::query()
    ->where('name', 'stripe')
    ->whereNull('processed_at')
    ->whereNull('failed_at')
    ->where('created_at', '<', now()->subMinutes(10))
    ->orderBy('created_at')->limit(50)->get()
    ->each(fn (WebhookCall $call) => dispatch(new ProcessStripeWebhook($call)));
```

### WR-02: A payment link stuck in `pending` can never be recovered from the UI

**File:** `app/Domain/Payments/Actions/RegeneratePaymentLink.php:55-59`, `app/Domain/Payments/Queries/PaymentLinkStatus.php:87`, `app/Domain/Invoicing/Issuing/DocumentIssuer.php:171-173`, `app/Filament/Support/PaymentLinkSection.php:56`
**Issue:** `RegeneratePaymentLink` refuses with `preparing` while a live link is `pending`, and `PaymentLinkStatus::regenerable` is false for `Pending`. A pending row leaves that state only through `CreatePaymentLink` (success, `markFailed`, or `failed()`). Nothing sweeps stale pending rows (only `DeactivatePaymentLinks` and the webhook prune are scheduled). If the job never runs, the document can never get a card link, the Admin sees a permanent "preparing" state, and the section polls every 5 s forever. The job can fail to run if the queue push fails or the job is lost. A related effect is that the push happens in `->afterCommit()` inside the issue transaction. If the queue is down, the exception surfaces from the commit callback after the invoice is already committed, so the Admin sees an error for an invoice that was in fact issued. That contradicts the "a Stripe outage can never fail the issue" intent, and it leaves the pending row without a job.
**Fix:** Let `RegeneratePaymentLink` replace a pending link older than a threshold (for example 10 minutes) instead of refusing, and flag `regenerable` accordingly. Alternatively add a sweep that dispatches `CreatePaymentLink` for pending rows older than a few minutes (the job is idempotent on the row id). Catch the push failure in `DocumentIssuer::openPaymentLink` or dispatch via a try/catch wrapper so it cannot turn a committed issue into a UI error.

### WR-03: The link-deactivation sweep can exceed its job timeout and retries permanent failures forever

**File:** `app/Domain/Payments/Jobs/DeactivatePaymentLinks.php:32,41-61,67-73`
**Issue:** The sweep processes up to 50 rows sequentially. Each Stripe call has a 5 s connect and 20 s total timeout with one network retry (`StripeApiGateway.php:34-38`), so one failing row costs roughly 10 to 40 s. The job inherits `KokpitJob`'s 60 s timeout. During a Stripe or network outage about 6 failing rows are enough to hit `TimeoutExceeded`, which raises a failed-job alert every five minutes. Separately, every `StripeGatewayFailure` is logged and left for "the next sweep", including non-retryable ones (`invalid_request` for a link id that no longer exists, for example after switching from test to live keys, or `authentication`). Those rows are never stamped, so they are retried every five minutes forever. Because rows are ordered by `deactivated_at`, 50 such permanently failing oldest rows would head-of-line block every newer deactivation.
**Fix:** Stop the run on the first retryable failure (`$failure->isRetryable()`), and honour a deadline.
```php
$deadline = microtime(true) + 40;
foreach ($query->get() as $link) {
    if (microtime(true) > $deadline) { break; }
    try { ... } catch (StripeGatewayFailure $failure) {
        if ($failure->isRetryable()) { return; }                 // outage: stop, next sweep retries
        $this->giveUp($link, $failure->failureClass);            // permanent: stamp remote_deactivated_at (or a failure marker) so it leaves the queue
    }
}
```
Lower `BATCH` to a value consistent with the timeout.

### WR-04: A paid session with unreadable fields is dropped silently and marked processed

**File:** `app/Domain/Payments/Actions/RecordCardPayment.php:78-81`
**Issue:** The guard `if (! $event->isPaid() || $event->id === null || $event->sessionId === null || $event->amountMinor === null || $event->currency === null) return null;` makes the job mark the event processed with nothing written for any of those conditions. `isPaid() === false` is correct (the async event follows). The other null conditions are not: for a `paid` session they mean the payload was malformed, for example a missing or oversized id, a non-integer or non-positive `amount_total`, or a currency that fails the `^[A-Za-z]{3}$` check in `StripeEvent`. The DB constraints require `amount_minor > 0` and a currency, so it cannot be stored as `undetermined`. But it also raises no alert and no failure, so real money can go through without any trace beyond the stored webhook row, which is pruned after 90 days. This is unlikely given signed Stripe payloads, but the failure mode is the silent one this phase exists to prevent.
**Fix:** Only return null for `! $event->isPaid()`. For the other conditions throw (so the event is retried and ends as `failed`, which turns the Health slot Error and is never pruned), or raise an Admin alert.
```php
if (! $event->isPaid()) { return null; }
if ($event->id === null || $event->sessionId === null || $event->amountMinor === null || $event->currency === null) {
    throw new UnexpectedValueException('A paid Stripe session could not be read.');
}
```

## Info

### IN-01: Duplicated rules can drift apart

**File:** `app/Domain/Payments/Jobs/CreatePaymentLink.php:56,296-309`, `app/Domain/Payments/Support/PaymentLinks.php:65-78`, `app/Domain/Payments/Support/StripeUrl.php`, `app/Domain/Payments/Stripe/StripeWebhookProcessor.php:27-31`, `app/Domain/Payments/Jobs/ProcessStripeWebhook.php:71-75`
**Issue:** The "is this link still wanted" rule exists twice (`CreatePaymentLink::staleReason` uses `$invoice->isCancelled()`, `PaymentLinks::staleReason` uses `$locked->stornoCreditNote()->exists()`). The stripe.com host rule exists as a regex in the job, as `StripeUrl::isAllowed`, and as the DB CHECK. The handled event types are listed in `StripeWebhookProcessor::HANDLED_TYPES` and again as the keys of `ProcessStripeWebhook::handlers()`. Adding a handler without adding the type to `HANDLED_TYPES` would acknowledge and drop that event with no error.
**Fix:** Have `CreatePaymentLink` call `PaymentLinks`' stale logic (make it a public method) and `StripeUrl::isAllowed`. Derive `HANDLED_TYPES` from `array_keys(ProcessStripeWebhook::handlers())`, or add a test that asserts equality.

### IN-02: A Stripe link that was created but could not be used is never switched off remotely

**File:** `app/Domain/Payments/Jobs/CreatePaymentLink.php:98-102,258-266`, `app/Domain/Payments/Jobs/DeactivatePaymentLinks.php:41-45`
**Issue:** Two paths leave a live link at Stripe with the row in `failed`. The first is a created link whose URL fails the host pattern (`markFailed(API_ERROR, $created->id)`). The second is `settle()` attaching ids to a row that `markFailed`/`failed()` already moved to `failed`, where `deactivateRemotely` runs once and cannot stamp the row because the stamp requires `state = deactivated`. The sweep only looks at `state = deactivated`, so a failed remote call is never repeated. The payer cannot find such a link (its URL is not shown), so the risk is low, but the Stripe dashboard keeps a payable link with the right amount and metadata.
**Fix:** Let the sweep also pick up `failed` rows with a `stripe_link_id` and no remote stamp, and relax `payment_links_remote_check` for that, or call `deactivateLink` in the bad-URL branch.

### IN-03: The `card_payments` guard trigger does not enforce "filled once"

**File:** `database/migrations/2026_10_18_000500_create_card_payments_table.php:131-136`
**Issue:** The comment says the payment and return-transaction links "may be cleared or filled once". The trigger only forbids a non-null value changing to a different non-null one. After `ON DELETE SET NULL` clears `invoice_payment_id` (or `return_transaction_id`), a later UPDATE can set any other payment id, and repeatedly. The application never does this, and the unique indexes bound the damage, but the "defence in depth" claim for an immutable audit-style table is weaker than stated.
**Fix:** Either correct the comment, or keep the original value in a column the trigger can compare, or restrict re-filling to the matched write (for example only while `OLD.resolved_at IS NULL AND OLD.invoice_payment_id IS NULL AND created_at = now()`).

### IN-04: `received_at` is the processing time, so the assign default can differ from the matched path

**File:** `app/Domain/Payments/Actions/RecordCardPayment.php:196`, `app/Domain/Payments/Actions/RecordRefundEvent.php:68`, `app/Domain/Payments/Actions/AssignCardPayment.php:72,135-138`
**Issue:** The matched path dates the payment by the Stripe event time (`paidOn()` uses `$event->created`). The unmatched/duplicate path defaults the assignment's `paid_on` to the Prague day of `received_at`, which is `CarbonImmutable::now()` at processing time. After a queue outage or a failed event that is rerun days later, the Admin's default payment date (and income date, which drives the CNB rate) is the processing day, not the day the customer paid. It is editable, but the default is subtly wrong.
**Fix:** Store the Stripe event time (`$event->created ?? now()`) as `received_at`, or keep a separate `paid_at`, and default the assignment to that.

### IN-05: IP-keyed throttles on the public routes depend on a permissive proxy trust

**File:** `app/Providers/PaymentsServiceProvider.php:30-31`, `bootstrap/app.php:36-39`, `routes/public.php:16,23`
**Issue:** `trustProxies(at: '*', headers: X_FORWARDED_FOR|...)` makes `$request->ip()` take the client-supplied left-most `X-Forwarded-For` value whenever the balancer appends rather than replaces the header. The `pay-page` and `stripe-webhook` limiters are keyed on that IP, and the webhook limiter runs before the signature check. If the balancer does not overwrite the header, an attacker can send forged requests with Stripe's published IP in `X-Forwarded-For`, fill that bucket (600/min) and cause legitimate deliveries to be answered 429 until the attack stops. Stripe would retry, so this is delay rather than loss. The pay-page limit is irrelevant to token guessing (about 190 bits).
**Fix:** Confirm that the Zerops balancer overwrites `X-Forwarded-For`, and narrow `at:` to its address range if it can be known. Optionally exclude requests that carry a syntactically valid `Stripe-Signature` header from the pre-check limiter, or key the limiter on the transport peer address.

### IN-06: Gaps in the event coverage, and `APP_URL` is baked into immutable documents

**File:** `app/Domain/Payments/Stripe/StripeWebhookProcessor.php:27-31`, `app/Domain/Payments/Support/PayUrl.php:20-25`
**Issue:** Only `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `refund.created` are handled. A refund that later fails or is cancelled (`refund.failed`, `refund.updated`) still stays an open "refund" record, and disputes/chargebacks (`charge.dispute.created`) are invisible, so a disputed payment keeps its invoice marked paid. This is outside the stated D-14 scope but relevant to "no unpaid invoice slips through". Separately, `PayUrl::for` uses `config('app.url')` with no validation. A mis-set `APP_URL` (the default is a localhost URL) would print an unusable pay link into the issued PDF, which is frozen, and into e-mails.
**Fix:** Document the dispute gap, or add `charge.dispute.created` as an unmatched/alert record. Add an Health indicator or issue-time check that `APP_URL` is an https URL when the card switch is on.

---

_Reviewed: 2026-10-18_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
