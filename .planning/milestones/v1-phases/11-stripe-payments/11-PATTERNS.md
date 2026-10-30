# Phase 11: Stripe Payments - Pattern Map

**Mapped:** 2026-10-18
**Files analyzed:** 32 (new or modified)
**Analogs found:** 30 / 32

All analog paths below were verified present in the working tree and are git-tracked source (no gitignored mirrors).

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Domain/Payments/Jobs/CreatePaymentLink.php` | job | event-driven (queued, external API) | `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` | exact |
| `app/Domain/Payments/Jobs/DeactivatePaymentLinks.php` | job | event-driven + batch sweep | `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` | role-match |
| `app/Domain/Payments/Jobs/ProcessStripeWebhook.php` | job | event-driven | `SendInvoiceEmail.php` + `app/Domain/Operations/Jobs/KokpitJob.php` (contract by hand) | partial (extends package class) |
| `app/Domain/Payments/Models/PaymentLink.php`, `CardPayment.php` | model | CRUD (Admin-only) | `app/Domain/Invoicing/Models/InvoicePayment.php` | exact |
| `app/Domain/Payments/Actions/RecordCardPayment`, `AssignCardPayment`, `ResolveCardPayment`, `RecordPaymentReturn`, `RegeneratePaymentLink` | service/action | CRUD, transactional | `app/Domain/Invoicing/Actions/RecordPayment.php` | exact |
| `app/Domain/Invoicing/Actions/RecordPayment.php` (modified: extract `recordLocked`) | action | CRUD | itself | modify |
| `app/Domain/Finance/Actions/RecordTransaction.php` (modified: extract write core) | action | CRUD | `RecordPayment.php` (same Gate-then-transaction shape) | modify |
| `app/Domain/Payments/Support/PaymentLinks.php` (reconcile) | service | transform inside caller transaction | `RecordPayment::carry()` (guard `transactionLevel`) | role-match |
| `app/Domain/Payments/Stripe/StripeGateway.php`, `StripeApiGateway.php` | service/interface | request-response (external) | none in repo | no analog |
| `app/Domain/Payments/Stripe/StripeSignatureValidator.php`, `StripeWebhookProcessor.php`, `StripeWebhookProfile.php` | middleware/service | request-response | package contracts (vendor); `config/webhook-client.php` | no analog (RESEARCH Pattern 4) |
| `app/Domain/Shared/Models/WebhookCall.php` (modified) | model | CRUD | itself | modify |
| `app/Http/Controllers/StripeWebhookController.php` | controller | request-response | `app/Http/Controllers/InvoicePdfController.php` (invokable, fixed-order checks) | role-match |
| `app/Http/Controllers/PayPageController.php` | controller | request-response (public) | `app/Http/Controllers/InvoicePdfController.php` | role-match |
| `routes/web.php` (modified), `bootstrap/app.php` (modified) | route/config | request-response | `routes/web.php` | modify |
| `app/Domain/Payments/Notifications/CardPaymentProblem`, `PaymentLinkFailed`, `CardPaymentMatched` | notification | event-driven | `app/Domain/Operations/Alerts/OperationalAlert.php` + `app/Domain/Invoicing/Notifications/InvoiceEmailFailedNotification.php` | exact |
| `app/Filament/Resources/CardPaymentResource.php` + `Pages/` | component (resource, tabs) | CRUD | `app/Filament/Resources/InvoiceResource.php` + `Pages/ListInvoices.php` (getTabs), `TransactionResource.php` | role-match |
| `app/Filament/Widgets/UnresolvedPayments.php` | component (widget) | read/aggregate | `app/Filament/Widgets/UnpaidInvoices.php` | exact |
| `app/Domain/Dashboard/Enums/DashboardWidget.php` (modified) + CHECK migration | enum/migration | config | `database/migrations/2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php` | exact |
| `app/Domain/Operations/Health/Indicators/UnprocessedWebhooksIndicator.php` + `OperationsServiceProvider` swap | service | read/aggregate | `UnsentInvoiceEmailsIndicator.php` | exact |
| `app/Filament/Support/PayByCardAction.php`, `PaymentLinkSection.php` | component | request-response | `app/Filament/Support/RecordPaymentAction.php`, `SendInvoiceEmailAction.php` | role-match |
| `app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php` (modified) | component | request-response | itself (`getHeaderActions`, `downloadPdfAction`) | modify |
| `app/Domain/Invoicing/Issuing/DocumentIssuer.php` (modified `writeAdminRow`) | service | transactional | itself | modify |
| `app/Domain/Invoicing/Pdf/InvoicePdfData.php` + PDF Blade (modified) | transform | batch render | itself | modify |
| `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` (modified: append pay block) | job | event-driven | itself | modify |
| `app/Domain/Invoicing/Enums/PaymentMethod.php` (modified: `Card`) | enum | config | itself | modify |
| migrations (`payment_links`, `card_payments`, `invoice_admin` cols, `webhook_calls` cols, `invoice_payments` method/created_by) | migration | DDL | `2026_10_17_000300_create_invoice_admin_tables.php`, `2026_10_07_152631_create_webhook_calls_table.php` | exact |
| `routes/console.php` (modified: prune + sweep) | config | batch | `routes/console.php` | modify |
| `config/webhook-client.php`, `config/services.php`, `.env.example` (modified) | config | config | `config/webhook-client.php` | modify |
| `tests/Feature/Payments/*`, `tests/Support/FakeStripeGateway.php`, `StripeWebhookSigner.php` | test | request-response | `tests/Feature/Invoicing/*`, `tests/Support/InvoiceFixtures.php` | role-match |
| `tests/Concurrency/payment-match-worker.php` + test | test | concurrency | `tests/Concurrency/payment-worker.php`, `PaymentDeletionConcurrencyTest.php` | exact |
| `tests/Arch/JobContractTest.php` etc. (modified) | test | config | itself | modify |
| `CONTRIBUTING.md` (remove Phase 11 hand-over bullet) | docs | - | itself | modify |

## Pattern Assignments

### `app/Domain/Payments/Jobs/CreatePaymentLink.php` (job, event-driven)

**Analog:** `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php`

**Imports and class contract** (lines 1-30, 54-62): `final class ... extends KokpitJob`, `#[Idempotent(how: '...')]`, `#[Timeout(..)]`, fixed `FAILURE_*` string constants, constructor takes only a row id.

```php
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Queue\Attributes\Timeout;

#[Idempotent(how: 'identified by the e-mail row id; the row is claimed by a conditional update from queued to sending, ...')]
#[Timeout(120)]
final class SendInvoiceEmail extends KokpitJob
{
    public const string FAILURE_TRANSPORT = 'transport';
    public function __construct(public readonly string $emailId) {}
```

(For Stripe use `#[Timeout(60)]` default or less than the SDK client timeout of about 20 s; RESEARCH Pitfall 7.)

**Claim by conditional update, UUID guard** (lines 64-90):

```php
if (! Str::isUuid($this->emailId)) { return; }
$claimed = InvoiceEmail::query()->whereKey($this->emailId)
    ->where('state', InvoiceEmailState::Queued->value)
    ->update(['state' => InvoiceEmailState::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
if ($claimed !== 1) { return; }
```

**Fixed-message wrapping of provider exceptions** (lines 108-125): never let the provider text reach `failed_jobs` or the bell.

```php
throw $e instanceof TransportExceptionInterface ? new RuntimeException(self::TRANSPORT_FAILED, 0, $e) : $e;
```

Copy for `Stripe\Exception\ApiErrorException`.

**`failed()` runs outside job middleware, so enter system context by hand** (lines 127-140):

```php
public function failed(Throwable $exception): void
{
    app(PartnerContext::class)->runAsSystem(function () use ($exception): void { $this->giveUp(...); });
}
```

**Failure marking + bell to every Admin in one transaction** (lines 142-175): `DB::transaction`, conditional `update` of state with fixed `failure_code`, `foreach (User::query()->role(RoleName::Admin->value)->get() as $admin) { $admin->notifyNow($notification); }`. Note RESEARCH prefers `AdminAlerter` with a key containing the row id for alerts (see Shared Patterns). The "call Stripe outside the transaction, re-check under lock" two-phase shape (RESEARCH Pattern 2) has no repo analog; combine this claim pattern with `RecordPayment`'s `lockForUpdate` (below).

---

### `app/Domain/Payments/Jobs/ProcessStripeWebhook.php` (job, event-driven, package subclass)

**Analog:** `app/Domain/Operations/Jobs/KokpitJob.php` (contract to copy by hand because `ProcessWebhookJob` is the parent).

**Contract to replicate** (lines 41-72): `#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]` attributes, `#[Idempotent(how: ...)]`, and a `middleware()` returning `[new RunsAsSystem]`:

```php
#[Tries(3)]
#[Backoff(10, 60, 300)]
#[Timeout(60)]
abstract class KokpitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    final public function middleware(): array { return [new RunsAsSystem, ...$this->jobMiddleware()]; }
```

Required companion edit: `tests/Arch/JobContractTest.php` line 65 rule ("makes every dispatchable class under app/ extend KokpitJob") must accept a `ProcessWebhookJob` subclass that declares `#[Idempotent]`; add new jobs to `EXPECTED_APPLICATION_JOBS`.

---

### `app/Domain/Payments/Models/PaymentLink.php`, `CardPayment.php` (model, Admin-only CRUD)

**Analog:** `app/Domain/Invoicing/Models/InvoicePayment.php`

**Header, traits, allowlist** (lines 1-30, 54-62):

```php
#[Fillable(['note'])]
#[LoggedAttributes(['invoice_id', 'amount_minor', 'currency', 'paid_on', 'method', 'carried_from_payment_id'])]
final class InvoicePayment extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;
    protected $table = 'invoice_payments';
    public function amount(): Money { return Money::ofMinor($this->amount_minor, $this->currency); }
```

Rules: `forceFill` in Actions (only free-text fillable), activity log is an allowlist and never logs free text or Stripe references (resolution notes are canaries), `casts()` method returns enum and integer casts, `@property` docblock with `CarbonImmutable`. Also update `ActivityAllowlistTest`, `ModelDeclarationTest`, `CanaryRegistry`, `MorphMap`.

---

### `app/Domain/Payments/Actions/*` and the system write core (action, CRUD transactional)

**Analog:** `app/Domain/Invoicing/Actions/RecordPayment.php`

**Gate, then lock the document first** (lines 60-77):

```php
Gate::forUser($actor)->authorize('update', $invoice);

return DB::transaction(function () use ($actor, $invoice, $data): InvoicePayment {
    $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

    if ($locked->state !== InvoiceState::Issued || ! $locked->kind->carriesPayment() || $locked->isCancelled()) {
        throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_not_allowed')]);
    }
```

Extraction target (RESEARCH Pattern 6): everything after the `Gate` line becomes public `recordLocked(Invoice $locked, ..., ?User $actor)`; `created_by` is `$actor?->getKey()` (null with method `card`).

**Insert via `forceFill`** (lines 85-95) and **income through the one finance path** (lines 190-218):

```php
$payment->forceFill(['invoice_id' => $locked->id, 'currency' => $locked->currency, 'amount_minor' => ..., 'paid_on' => ..., 'method' => ..., 'created_by' => $actor->getKey()]);
$payment->save();
...
$this->transactions->handle($actor, [
    'type' => 'income', 'transaction_date' => $incomeDate,
    'category_id' => $categoryId ?? TransactionCategory::invoicePayments()->getKey(),
    'amount' => $amount->toMajor(), 'currency' => $invoice->currency,
    'description' => __('invoices.payment_income.description', ['number' => (string) $invoice->number]),
    'counterparty' => ..., 'document_number' => $invoice->number, 'client_id' => $invoice->client_id,
], ['invoice_id' => $invoice->id, 'invoice_payment_id' => $payment->id]);
```

Card income description becomes "Platba kartou faktury :number" (new lang key). Proforma stores payment only (`$books = $locked->kind === InvoiceKind::Invoice`).

**In-transaction guard for helpers called by the caller's transaction** (lines 110-112, `carry()`):

```php
if (DB::transactionLevel() === 0) { throw new LogicException('... inside the caller\'s transaction.'); }
```

Copy into `PaymentLinks::reconcile()`. Call `reconcile` from `RecordPayment::handle`, `carry`, `IssueCreditNote` partial and storno.

**Deletion path:** `app/Domain/Invoicing/Actions/DeletePayment.php` (line 77 `select set_config('kokpit.payment_deletion', ?, true)`); do not add another delete path (`tests/Arch/PaymentDeletionPathTest.php`).

---

### `app/Http/Controllers/PayPageController.php` (controller, public request-response)

**Analog:** `app/Http/Controllers/InvoicePdfController.php`

**Pattern:** `final class` with `__invoke`, ordered checks documented in the docblock, validate id shape before any query, indistinguishable answers for unknown / invalid, system-context read of Admin-only data after the check:

```php
abort_unless(Str::isUuid($invoice), 404);
...
$stored = $context->runAsSystem(static function () use ($record): ?array {
    $documentId = InvoiceAdmin::query()->where('invoice_id', $record->getKey())->value('pdf_document_id');
```

Adapt: token regex `^[A-Za-z0-9]{32}$` instead of `isUuid`; `runAsSystem` to resolve `InvoiceAdmin.pay_token` and `PaymentLink`; response headers in the same array style (`'Cache-Control' => 'private, no-store'` becomes `no-store`, plus `X-Robots-Tag: noindex`). Public route has no `Gate`; throttle through a named limiter beside `invitation` and `document-files` in `app/Providers/AppServiceProvider.php`. Always 302 to a validated `https` stripe.com host.

### `app/Http/Controllers/StripeWebhookController.php`

Same invokable shape; body reads via `$request->getContent()`; delegates to the package processor subclass. `bootstrap/app.php`: render `InvalidWebhookSignature` as 400 and `preventRequestForgery(except: ['webhooks/stripe'])`. Existing `routes/web.php` currently holds only `Route::redirect('/', '/admin');` so new routes are appended there.

---

### `app/Domain/Payments/Notifications/*` (notification, alert)

**Analog:** `app/Domain/Operations/Alerts/OperationalAlert.php` (non-queueable, `via` = mail + database, Filament-format `toDatabase`) and `app/Domain/Invoicing/Notifications/InvoiceEmailFailedNotification.php` (document number only, `InvoiceResource::getUrl('view', ...)` action).

Delivery: `AdminAlerter::alert(string $throttleKey, OperationalAlert $alert)` (`app/Domain/Operations/Alerts/AdminAlerter.php` lines 31-58): never throws, per-key throttle, bell first then mail. Use a throttle key that includes the `card_payments` id so two duplicates are never merged into one bell entry (RESEARCH Open Question 2).

---

### `app/Filament/Resources/CardPaymentResource.php` (+ `Pages/ListCardPayments`, `ViewCardPayment`)

**Analogs:** `app/Filament/Resources/InvoiceResource.php`, `.../Pages/ListInvoices.php`, `TransactionResource.php`

**Access declaration** (InvoiceResource line 34, TransactionResource line 67):

```php
#[AccessRule(Audience::AdminOnly, reason: 'Finance belongs to the Admin: a Partner never sees ...')]
```

with `use EnforcesResourceAccessRule` (`App\Filament\Concerns`), and a `RelationManager` for history like `TransactionHistoryRelationManager`.

**Queue tabs with count badges** (`ListInvoices.php` lines 46-67): `getTabs()` loops an enum, `Tab::make($label)->badge($count)->badgeColor(...)->modifyQueryUsing(...)`, tab absent at zero for attention items; refresh listeners `#[On('invoice-payment-saved')]`. Resolution Actions delegate to domain Actions (never Livewire state writes).

---

### `app/Filament/Widgets/UnresolvedPayments.php` (widget)

**Analog:** `app/Filament/Widgets/UnpaidInvoices.php` (whole file)

Copy: `#[AccessRule(Audience::AdminOnly, ...)]`, `use CollapsibleDashboardWidget; use EnforcesWidgetAccessRule;`, `protected static bool $isLazy = false`, `$view = 'filament.widgets.hours-stats'`, `pollingInterval = 'visible.60s'`, `canView()` asks `AccessRules::allows(self::class)` first and then returns true only when unresolved items exist (RESEARCH: card only above zero), `dashboardWidget()` returns the new `DashboardWidget::UnresolvedPayments` case, `getStats()` returns `Stat::make(...)->url(...)->color('danger')`.

**Migration analog:** `database/migrations/2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php`: drop and re-add `dashboard_widget_preferences_widget_key_check` with the new key in one `up()`, `down()` deletes the new rows first, then restores the old list (now seven keys plus `unresolved_payments`).

---

### `app/Domain/Operations/Health/Indicators/UnprocessedWebhooksIndicator.php`

**Analog:** `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php`

```php
final readonly class UnsentInvoiceEmailsIndicator implements HealthIndicator
{
    public function slot(): HealthSlot { return HealthSlot::UnsentInvoiceEmails; }
    public function check(): HealthResult
    {
        ...thresholds from config('kokpit.health.*') with max(1, (int) ...)...
        $counts = app(PartnerContext::class)->runAsSystem(static fn (): object => (object) Model::query()->selectRaw('COUNT(*) FILTER (WHERE ...) AS failed, ...', [...])->first());
```

Rules: count and state only, never payload; fixed Czech detail text with a count; admin-only model read inside `runAsSystem`. Slot already exists: `HealthSlot::UnprocessedWebhooks = 'unprocessed_webhooks'`. Replace its `PlaceholderIndicator` registration in `app/Providers/OperationsServiceProvider.php` (the only registration; also update the `PlaceholderIndicator` docblock mention) and add thresholds to `config/kokpit.php` `health`. Update `tests/Feature/Operations/HealthRegistryTest.php`.

---

### `app/Domain/Invoicing/Issuing/DocumentIssuer.php` (modify `writeAdminRow`, lines 276-299)

```php
$admin = InvoiceAdmin::query()->where('invoice_id', $draft->id)->lockForUpdate()->first();
if (! $admin instanceof InvoiceAdmin) { $admin = new InvoiceAdmin; $admin->forceFill(['invoice_id' => $draft->id]); }
...
$admin->save();
```

Add the frozen `card_payment_enabled` / `pay_token` `forceFill` before `save()` (RESEARCH Pattern 1); the state flip happens later at line 126-137, so the admin row is still writable (child guard freezes it after issue). Dispatch `CreatePaymentLink` `afterCommit` after the PDF is stored.

### `ViewPartnerInvoice::getHeaderActions()` (modify, lines 58-63)

```php
protected function getHeaderActions(): array
{
    return [ self::downloadPdfAction() ];
}
```

Insert the pay action in front of `downloadPdfAction()`; model on `downloadPdfAction()` (lines 69-84: `Action::make`, `->action(static fn (Invoice $record, Component $livewire) => ... abort_unless(PartnerInvoiceResource::canAccess(), 403) ... $livewire->redirect(...))`). Visibility reads a narrow Partner-safe reader executed through `PartnerContext::runAsSystem` after the scoped record has loaded (same precedent as `InvoicePdfController`). Never render the Stripe URL.

---

### Migrations (`2026_10_18_0001xx_*`)

**Analog:** `database/migrations/2026_10_17_000300_create_invoice_admin_tables.php`

```php
$table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
$table->char('currency', 3);
$table->bigInteger('amount_minor');
$table->foreignUuid('created_by')->constrained('users');
$table->timestampsTz();
DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_invoice_currency_fk FOREIGN KEY (invoice_id, currency) REFERENCES invoices (id, currency)');
DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_amount_check CHECK (amount_minor > 0)');
DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_method_check CHECK (method IN ('transfer', 'cash'))");
```

Copy: composite FK `(invoice_id, currency)`, `amount_minor > 0`, currency regex CHECK, named constraints, uuidv7 default. The alteration migration widens `invoice_payments_method_check` to include `'card'`, drops NOT NULL on `created_by` and adds `CHECK ((method = 'card') = (created_by IS NULL))`. New `card_payments.invoice_payment_id` FK must be `ON DELETE SET NULL` (codebase default NO ACTION would block `DeletePayment`; RESEARCH Pitfall 12). `webhook_calls` additions follow `2026_10_07_152631_create_webhook_calls_table.php` (uuid pk, `timestampsTz`) with a partial unique `(name, event_id) WHERE event_id IS NOT NULL`.

### `routes/console.php` (modify)

Every scheduled event needs `->name(...)` and `->onOneServer()` (enforced by `ScheduleOnOneServerTest`):

```php
Schedule::job(new PurgeExpiredIdempotencyKeys)
    ->daily()
    ->name('kokpit-api-idempotency-prune')
    ->onOneServer();
```

Add `Schedule::command('model:prune', ['--model' => [WebhookCall::class]])->daily()->name('kokpit-webhook-prune')->onOneServer();` and the deactivation sweep job every few minutes, same style.

### `app/Domain/Shared/Models/WebhookCall.php` (modify)

Currently `class WebhookCall extends BaseWebhookCall implements PartnerIsolated { use DeniesPartners, HasUuids; }` (docblock says route and retention belong to this phase). Add `storeOnce()`, `prunable()` override (processed, older than 90 days only), casts for `processed_at`.

### Tests

- `tests/Concurrency/payment-match-worker.php`: copy header docblock (usage line, modes, one JSON result line, refuse unless database name ends in `_test`), `require vendor/autoload.php`, `$app->make(Kernel::class)->bootstrap()`, start barrier, mutation twin `skip-lock` mode.
- `tests/Feature/Payments/*`: fixtures from `tests/Support/InvoiceFixtures.php` / `InvoiceScenario.php`; PDF text through `tests/Support/PdfText.php`; build Stripe-shaped values at runtime (`'whsec_'.Str::random(32)`) so gitleaks and `scripts/check-sensitive.sh` stay clean.

## Shared Patterns

### System context for jobs and public routes
**Source:** `app/Domain/Shared/Auth/PartnerContext.php` (`runAsSystem`), used in `SendInvoiceEmail::failed()` and `InvoicePdfController`.
**Apply to:** all Payments jobs (`failed()` must enter it by hand), `PayPageController`, Partner pay reader, health indicator.

### Admin-only fail-closed declaration
**Source:** `#[AccessRule(Audience::AdminOnly, reason: '...')]` on `InvoiceResource` line 34; model-level `DeniesPartners` + `PartnerIsolated` (`InvoicePayment`); `app/Domain/Shared/Policies/AdminOnlyPolicy.php` registration for package models.
**Apply to:** `CardPaymentResource`, `UnresolvedPayments`, `PaymentLink`, `CardPayment`, policy registrations.

### Lock order and derived state
**Source:** `RecordPayment::handle` (document, then payment, then income); `InvoiceBalances` (`PAID_SQL`, `CREDITED_SQL`, `REMAINING_SQL`); `Invoice::isCancelled()`.
**Apply to:** match job, assign resolution, `PaymentLinks::reconcile`. Never store a paid flag.

### Admin alerts independent of the queue
**Source:** `AdminAlerter::alert()` (never throws, throttled per key) with an `OperationalAlert`-shaped notification.
**Apply to:** link failure, duplicate, unmatched, refund, matched notice.

### Fixed failure classes, no provider text
**Source:** `SendInvoiceEmail` `FAILURE_*` constants and `TRANSPORT_FAILED` replacement exception.
**Apply to:** Stripe call failures, `payment_links.failure_class`.

### Idempotence by DB constraint, not application check
**Source:** `KokpitJob` docblock (natural key, check-then-act, unique constraint last line of defence).
**Apply to:** `card_payments` unique event/session/payment intent; use `insertOrIgnore` (never try/catch a unique violation inside a PostgreSQL transaction).

### Repository hygiene
Fictional data only; no full-length Stripe-shaped literals; run `scripts/check-sensitive.sh` on every new file.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Payments/Stripe/StripeGateway.php`, `StripeApiGateway.php`, `tests/Support/FakeStripeGateway.php` | service/interface | external request-response | No external-API gateway interface exists; use RESEARCH Code Examples (two-method interface, SDK with `idempotency_key`, ~20 s timeout) |
| `StripeSignatureValidator`, `StripeWebhookProcessor`, `StripeWebhookProfile` | middleware/service | request-response | Only the package is wired (`config/webhook-client.php`, `process_webhook_job` empty at line 75); use RESEARCH Pattern 4 and vendor contracts |
| `resources/views/pay/show.blade.php` (public neutral card) | view | request-response | No public Blade page; closest precedent is `/admin/invitation` (`AcceptInvitation` panel route); RESEARCH Pattern 7 and Assumption A3 spike |

## Metadata

**Analog search scope:** `app/Domain/{Invoicing,Operations,Shared,Finance}`, `app/Http/Controllers`, `app/Filament/{Resources,Widgets,Support,Partner}`, `app/Providers`, `database/migrations`, `routes`, `tests/{Concurrency,Support}`
**Files scanned:** about 45 listed, 17 read in full or in targeted ranges
**Pattern extraction date:** 2026-10-18
