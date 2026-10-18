# Phase 11: Stripe Payments - Research

**Researched:** 2026-10-18
**Domain:** Stripe Payment Links + signed webhooks on an existing Laravel 13 / Filament 5 / PostgreSQL invoicing core (queued link lifecycle, idempotent event matching, Admin exception handling)
**Confidence:** HIGH on Stripe API/SDK facts and on how the existing Phase 10 code constrains the design; MEDIUM on the few items listed in the Assumptions Log (mostly UI plumbing that needs a Wave 0 spike)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217; a matched payment is written only into `invoice_payments` and its income only through the rules of `RecordPayment` (system category, `TransactionCzk`, no `custom` source); payment matching to a document is by the invoice id, a bank payment by variable symbol scoped by document kind (Phase 10 hand-over in `CONTRIBUTING.md`); issued documents are immutable except payment state and internal note (Phase 10 D-13); income comes from invoices only, a payment on a proforma is carried to the invoice at conversion (Phase 10 D-18); `spatie/laravel-webhook-client` is installed with `App\Domain\Shared\Models\WebhookCall` registered, no endpoint is routed yet; `PaymentSettings::online_payments_enabled` and the client online-payment flag already exist; `KokpitJob` / `Idempotent` base for queued jobs.

**Pay button and public link (PA-02)**
- **D-01:** The "Pay by card" button in the PDF and the e-mail points to a stable, public Kokpit URL for the document (signed token, no login), not to the Stripe URL. The stable URL exists from the moment of issue and does not depend on Stripe, so the PDF stays generated from snapshots at issue (Phase 10 D-23) and a regenerated PDF stays equivalent even after the Stripe link is replaced. — **Reversibility:** costly — the token format and public route are printed into issued PDFs and sent e-mails, so changing them breaks links already delivered to clients.
- **D-02:** The public URL redirects to the current active Stripe link when one exists. Otherwise it shows a simple, login-free information page: "paid", "cancelled" or "link is being prepared, try again shortly". The page shows the document number only, never amounts or other details.
- **D-03:** The e-mail always carries the stable URL; it never waits for the Stripe link job. If Stripe fails, the client sees the information page, Admin is alerted and can recreate the link (PA-01).
- **D-04:** In the invoice detail (Admin and Partner) the button is a header action; the Partner button uses the free slot in front of the download action in `ViewPartnerInvoice::getHeaderActions()`. It is hidden once the document is paid or cancelled and whenever no active link exists. This supersedes the Phase 10 hand-over note that the PDF and e-mail stay free of the button.

**Link lifecycle (PA-01, PA-03, PA-04)**
- **D-05:** A payment link is created for invoices and proformas, never for credit notes. A payment on a proforma follows the Phase 10 rules (stored on the proforma, carried to the invoice on conversion).
- **D-06:** Creation conditions are an AND of three switches: the global `online_payments_enabled` setting, the client's online-payment flag, and a per-document flag. A draft takes the client's value, Admin can override it, and it is frozen at issue; later Admin can only deactivate or regenerate the link manually.
- **D-07:** The link is created for the remaining amount of the document (total minus recorded payments). A regenerated link is also created for the remaining amount, and the matching check compares against the amount the link offered.
- **D-08:** A partial manual payment on a document with an active link deactivates that link (its amount is no longer valid); Admin gets a "Regenerate link" action for the remaining amount. A link with a wrong amount never stays active. Full payment by any route and full storno deactivate the link; regeneration deactivates the old link first.
- **D-09:** The queued link job is idempotent, and a Stripe failure never blocks or rolls back issuing; Admin is alerted and sees the failure on the document.

**Matching and exceptions (PA-06, PA-07, PA-08)**
- **D-10:** A matching payment (invoice id from the link metadata, the link id as fallback, same amount and currency as the link offered) creates the payment in `invoice_payments`, marks the document paid, creates the income via `RecordPayment` rules, deactivates the link and notifies Admin.
- **D-11:** A payment with a correct document id but a different amount or currency is stored as unmatched with the reason (amount / currency). It creates no income and changes nothing on the document; Admin is alerted and resolves it manually.
- **D-12:** A second payment of an already paid document is stored as a duplicate with no income and no document change, and alerts Admin. A repeated webhook event is ignored. Idempotence is enforced by DB unique constraints (event id; payment intent / checkout reference), not only by application checks.
- **D-13:** Admin payments overview with duplicate and unmatched filters offers these resolutions: assign to a document (writes `invoice_payments` and income through `RecordPayment` rules); mark as resolved with a note (for duplicates and refunds settled in the Stripe dashboard); record a money return (a manually entered expense linked to the duplicate payment, no Stripe API call and no refund automation, consistent with Phase 10 D-16).
- **D-14:** A payment matched from Stripe can be deleted by Admin through the same `DeletePayment` path as a manual payment (deleting its income too). The unique event id and payment reference remain stored, so replaying or redelivering the same event never recreates a deleted payment. Refund events are recorded and alerted (PA-08) without changing the document. — **Reversibility:** costly — the unique-event design must keep the deleted payment's reference reserved, otherwise a Stripe redelivery silently re-books the money.

**Webhook, configuration and testing (PA-05)**
- **D-15:** The webhook endpoint verifies the Stripe signature, stores the event under a unique event id, answers immediately and processes it in a job; a bad signature is rejected. It builds on `spatie/laravel-webhook-client` and `WebhookCall`.
- **D-16:** Stripe secrets (secret key, webhook signing secret) live only in environment variables read through config, never in the database or the settings UI. The System page reports only whether they are configured.
- **D-17:** Processed webhook events are pruned after 90 days by a scheduled command; failed or unprocessed events are never pruned and are shown on the System page ("unprocessed webhooks"). Payment records do not depend on the event row and are kept.
- **D-18:** Automated tests use a fake Stripe client behind a thin interface (create / deactivate link) and sign webhook payloads with a random test secret; CI and the test suite never call Stripe. In development a Stripe tool is installed for manual verification (for example forwarding real test-mode events); it is optional and not required by any test. Whether to use the Stripe PHP SDK or direct HTTP is left to the planner.

### Claude's Discretion
Class, file, table and index names; the public token format and expiry behaviour; which exact payment statuses and reasons exist; how Admin is alerted (database notifications, mail, both) as long as every failure, duplicate, unmatched payment and refund is surfaced; the layout of the payments overview and the dashboard tile; Stripe API version pinning; the exact scheduling of the pruning command.

### Deferred Ideas (OUT OF SCOPE)
- Stripe refunds, disputes and fee tracking inside the app — v2 (PAY-01); handled in the Stripe dashboard.
- Bank statement matching — v2 (PAY-02).
- Scheduled payment reminders — v2 (BIL-03).
- Reviewed todo not folded: "Redesign unreadable time report layout" (unrelated to payments).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PA-01 | Single-use Stripe Payment Link created by queued job at issue when online payment is enabled; failure never blocks issue and alerts Admin | `restrictions.completed_sessions.limit = 1` + inline `price_data` (Stripe docs); two-phase job (lock, call Stripe outside the transaction, re-check) with `idempotency_key`; `CreatePaymentLink` KokpitJob dispatched `afterCommit` from the issue transaction; fixed failure classes (SendInvoiceEmail pattern); `AdminAlerter` |
| PA-02 | "Pay by card" button in e-mail, PDF and invoice detail (Admin and Partner); hidden when paid | Opaque stable token + public controller route; PDF button verified to emit a `/URI` link annotation under Dompdf; mail block appended at send time; Partner header slot in `ViewPartnerInvoice::getHeaderActions()`; Partner-safe "has active link" reader run as system after the scoped record is loaded |
| PA-03 | Online payment can be disabled per client and per document | Per-document flag on `invoice_admin` (draft-editable, frozen at issue by the existing child guard); effective value = global setting AND stripe-configured AND document flag (initialised from the client flag) |
| PA-04 | Link deactivated on payment by any route or on storno; link can be regenerated (old one deactivated first) | One `PaymentLinks::reconcile()` invariant ("a live link's amount equals the remaining amount") called inside `RecordPayment`, `carry`, `IssueCreditNote` (storno and partial) transactions; DB state flips immediately, Stripe `active=false` is a queued job plus a sweep; regenerate = deactivate old, create new in one idempotent job |
| PA-05 | Webhook endpoint verifies Stripe signature, stores event with unique event id and answers immediately; processing runs in a job | `Stripe\WebhookSignature::verifyHeader` inside a package `SignatureValidator`; custom `WebhookProcessor` subclass with `INSERT ... ON CONFLICT DO NOTHING` on `(name, event_id)`; map `InvalidWebhookSignature` to HTTP 400 (the package exception is a plain `Exception`); process job subclass of `ProcessWebhookJob` carrying the KokpitJob contract |
| PA-06 | Matching by invoice id (fallback link id) with amount and currency check creates payment, marks invoice paid, creates income, deactivates link, notifies Admin | Locked-document match transaction (lock order document, payment, income); refactor `RecordPayment`/`RecordTransaction` so a system path reuses the same write core without a User; `created_by` and method CHECK migrations; match on session `currency`/`amount_total` (integration currency), never on `presentment_details` |
| PA-07 | Second payment of the same invoice is stored as duplicate without income or invoice change and alerts Admin; repeated events are ignored; idempotence enforced by DB unique constraints | `card_payments` table with unique event id, unique session id, partial unique payment intent; `ON CONFLICT DO NOTHING` (never try/catch a unique violation inside a PostgreSQL transaction); same-PI repeat = same payment (ignored silently), different PI on a paid document = duplicate |
| PA-08 | Unmatched payments and refund events are recorded and alerted; Admin payments overview with duplicate/unmatched filters and manual resolution; old webhook events are pruned | `refund.created` as the refund event; overview resource with queue tabs; three resolution Actions through the same write core; `model:prune` with an overridden `prunable()` (processed only, 90 days); `UnprocessedWebhooks` health indicator replaces the placeholder; dashboard card with its own `DashboardWidget` case and CHECK migration |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Extracted from `.claude/CLAUDE.md` (treated as locked, same authority as CONTEXT decisions):

- **Public AGPL-3.0 repository, fictional data only.** No real names, prices, rates, invoice data, e-mail addresses, company IDs, bank accounts, IPs, hostnames, tokens or personal paths in code, tests, fixtures, docs, `.planning/` or commit messages. Use `example.com`, company id `12345678`, `/Users/example/`. Test fakes are assembled at runtime from fragments so no single line looks like a real value (this matters for Stripe-shaped strings, see Pitfall 14).
- **Run tests through DDEV** (`ddev exec vendor/bin/pest`, `ddev exec` for stan); the host has no database (also stored in the user's memory file).
- **All dependencies must be AGPL-compatible**, no paid or closed packages; `composer check-licenses` gates it.
- **Partner never sees measured time, rates, prices or finance, nor another client's data**: enforced by Policies and global scopes, not UI hiding.
- **UUID v7 keys everywhere; FK, unique, partial indexes and CHECK constraints in the database.**
- **Issued invoices and billed time entries are immutable** (guard triggers); snapshots for supplier, customer, rates, exchange rates.
- **No secrets in git;** pre-commit hook runs `scripts/check-sensitive.sh` and gitleaks; never bypass (`--no-verify`, `LEFTHOOK=0`).
- **GSD workflow enforcement:** file-changing work goes through a GSD command; `.planning/` text is English.
- Review procedure before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`.

## Summary

Stripe's part of this phase is small and well documented: one `POST /v1/payment_links` (inline `price_data`, `restrictions.completed_sessions.limit = 1`, `metadata`, `after_completion.hosted_confirmation.custom_message`, `inactive_message`), one `POST /v1/payment_links/{id}` with `active=false`, and three webhook event types (`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `refund.created`). The official `stripe/stripe-php` v22.0.0 (MIT, pinned API version `2026-09-30.endive`) was installed in a scratch directory under PHP 8.5.11 and its webhook verification, bad-signature and stale-timestamp paths behaved as documented with `E_ALL` and no warnings. The hard part is not Stripe; it is making the Stripe side obey the invariants Phase 10 built: an issued `invoices` row accepts no write, a payment row is never updated and is deleted only through `DeletePayment`, income is booked only through the `RecordPayment` rules, and every long-running side effect is a `KokpitJob` with an `#[Idempotent]` declaration.

Four findings change the shape of the plan. (1) `RecordPayment::handle()` and `RecordTransaction::handle()` both start with `Gate::forUser($actor)->authorize(...)` and `invoice_payments.created_by` is `NOT NULL` referencing `users`; a queue job has no user, so the write core must be extracted into a system-callable method (same lock order, same income booking) and `created_by` plus the `method` CHECK must be migrated. (2) The package's `WebhookProcessor::process()` dispatches the job for every stored call, `WebhookCall::storeWebhook()` uses a plain `create()`, `InvalidWebhookSignature` extends plain `Exception` (an unhandled one is an HTTP 500, which makes Stripe retry), `process_webhook_job` is currently `''` (the config constructor throws `InvalidConfig` the first time `WebhookConfigRepository` resolves), and `ProcessWebhookJob` cannot also extend `KokpitJob`, which the architecture test `makes every dispatchable class under app/ extend KokpitJob` requires. D-15 locks "builds on the package", so the plan subclasses the processor, model and job and narrowly amends that architecture test. (3) Link state changes after issue, so it cannot live on `invoices`; it needs its own Admin-only table, and the token plus the frozen "card payment on" flag belong in `invoice_admin` (writable while the parent is a draft, frozen afterwards by the existing child guard; the PDF is rendered inside the issue transaction after that row is written). (4) With Adaptive Pricing, which Stripe states is always on for Payment Links, the session `currency` and `amount_total` stay in the integration (document) currency and a customer's local-currency view only appears in `presentment_details`; matching must read the former.

**Primary recommendation:** Build `app/Domain/Payments/` with two Admin-only tables (`payment_links` lifecycle, `card_payments` event records), put `pay_token` and the frozen flag on `invoice_admin`, add `stripe/stripe-php:^22.0` behind a two-method `StripeGateway` interface (fake in tests), extract a user-free write core from `RecordPayment`/`RecordTransaction`, make the processor insert events with `ON CONFLICT DO NOTHING` under a unique `(name, event_id)`, and enforce "a live link's amount equals the remaining amount" in one `PaymentLinks::reconcile()` called from every transaction that changes what is owed.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Stable pay URL resolution (token to redirect or info page) | API / Backend (public controller) | CDN / Static (none: must be `no-store`) | Decision depends on live document and link state; must answer in a constant shape; no Livewire snapshot |
| Pay token and frozen card-switch | Database / Storage (`invoice_admin`) | API / Backend (`DocumentIssuer`) | Written while the document is a draft, frozen by the existing child guard; PDF reads it |
| Payment Link create / deactivate (Stripe calls) | API / Backend (queued `KokpitJob`) | Database (state machine) | Network side effect never inside a DB transaction or the issue transaction (D-09) |
| Link-vs-remaining invariant (PA-04) | API / Backend (`PaymentLinks::reconcile` inside existing action transactions) | Database (partial unique index: one live link per document) | Needs the locked document row; DB guarantees at most one live link |
| Webhook intake (signature, dedupe, store, answer) | API / Backend (controller + processor) | Database (unique `(name, event_id)`) | Must answer 2xx fast; uniqueness is the last line of defence (D-12) |
| Event processing and matching | API / Backend (queued job, one locked transaction) | Database (unique session/PI/refund ids, FKs) | Money-moving; lock order document, payment, income |
| Payment, duplicate, unmatched, refund records | Database / Storage (`card_payments`) | API / Backend | Records outlive webhook rows (D-17) and keep references reserved (D-14) |
| Admin overview, resolutions, link section | Frontend Server (Filament resources/pages) | API / Backend (Actions) | Admin-only surfaces; every write is a domain Action, not Livewire state |
| Partner pay button | Frontend Server (`ViewPartnerInvoice`) | API / Backend (narrow system reader) | Partner reads only kind, derived state and "link exists"; Admin-only tables are read as system after the scoped record loads |
| PDF button and mail block | API / Backend (`InvoicePdfData`, `SendInvoiceEmail`) | — | Printed from frozen values; mail block appended at send time |
| Admin alerts (bell + mail) | API / Backend (`AdminAlerter`-style synchronous delivery) | Browser (bell polling) | Must not depend on the queue that may be broken |
| System page / dashboard indicators | Frontend Server (health indicators, widget) | Database (aggregate reads) | Existing slot and widget patterns |
| Event pruning | API / Backend (scheduler: `model:prune`) | Database | Existing scheduler conventions (`onOneServer`, named) |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| stripe/stripe-php | ^22.0 (v22.0.0, released 2026-09-30/10-01) | `StripeClient` for payment links, `WebhookSignature::verifyHeader` for signature + timestamp tolerance | Stripe's official SDK, MIT, `require php >=7.4`; pins API `2026-09-30.endive`; handles form encoding, retries and idempotency headers. [CITED: github.com/stripe/stripe-php, packagist.org] |
| spatie/laravel-webhook-client | 3.7.0 (installed) | `WebhookCall` model, `SignatureValidator`/`WebhookProfile`/`WebhookProcessor` seams, `ProcessWebhookJob` | Already installed and registered; D-15 locks building on it. [VERIFIED: vendor/composer/installed.json] |
| laravel/framework | v13.36.0 (installed) | `Schedule`, `model:prune`, queues, `insertOrIgnore` (`ON CONFLICT DO NOTHING` on PostgreSQL) | Project baseline. [VERIFIED: vendor/composer/installed.json] |
| filament/filament | v5.10.2 (installed) | Resource, ViewRecord, Actions, StatsOverviewWidget, SimplePage components, `SetUpPanel` middleware | Project baseline. [VERIFIED: vendor/composer/installed.json; vendor/filament/filament/src/Http/Middleware/SetUpPanel.php] |
| dompdf/dompdf | 3.1.6 (installed) | PDF button as `<a href>` in a table cell | Verified to emit a `/URI` annotation with remote access off (smoke test below). [VERIFIED: smoke run] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Stripe CLI (`stripe`) | present at `/opt/homebrew/bin/stripe` (version string not captured) | Optional dev tool: `stripe listen --forward-to`, `stripe trigger`, `stripe events resend` | Manual verification only; no test may depend on it (D-18). [CITED: docs.stripe.com/webhooks] |
| Ghostscript (`gs`) | present at `/opt/homebrew/bin/gs` | `Tests\Support\PdfText::extract()` text assertions on the PDF | PDF button/lead-line assertions; test skips when absent (existing helper) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| stripe/stripe-php | Laravel `Http` client + hand-written HMAC | ~40 lines less dependency, but re-implements signature parsing (multiple `v1`, `v0` ignore, tolerance), form encoding of nested params and idempotency headers. Rejected: hand-rolling a verifier is the classic mistake; the SDK is MIT and AGPL-compatible. |
| Opaque DB token | `URL::signedRoute` (HMAC with `APP_KEY`) | Signed URLs break if `APP_KEY` is rotated, which would invalidate every printed PDF and sent e-mail (D-01 "costly" reversibility). Opaque 32-character random token is independent of `APP_KEY`, revocable, indexable. |
| Own `stripe_events` table | Reuse `webhook_calls` | D-15 locks the package model; add `event_id`, `event_type`, `processed_at` columns to `webhook_calls`. Payment records live in `card_payments`, so event pruning never removes them (D-17). |
| Separate Stripe Product/Price objects | Inline `price_data` + `product_data` in the link | Inline creates product and price with the link (documented), no extra objects to manage per invoice. |

**Installation:**
```bash
ddev composer require stripe/stripe-php:^22.0
ddev composer check-licenses
```

**Version verification (this session):**
```text
packagist: stripe/stripe-php v22.0.0 time 2026-10-01T00:43:03+00:00 license MIT require php>=7.4, ext-curl, ext-json, ext-mbstring
packagist repository: https://github.com/stripe/stripe-php ; 181,328,024 installs total
scratch install + smoke on PHP 8.5.11: Stripe\Webhook::constructEvent OK, bad signature -> SignatureVerificationException,
  old timestamp -> "Timestamp outside the tolerance zone", StripeClient->paymentLinks is Stripe\Service\PaymentLinkService,
  \Stripe\Util\ApiVersion::CURRENT = 2026-09-30.endive, no warnings under E_ALL
```
[VERIFIED: Packagist API and scratch run this session]

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| stripe/stripe-php | Packagist | since 2012-05-01 (14 yrs) | 181M total, ~8.1M/month | github.com/stripe/stripe-php | not machine-checked (see note) | Approved with a human diff review of `composer.json`/`composer.lock` |
| spatie/laravel-webhook-client | Packagist | already installed (Phase 2) | — | github.com/spatie/laravel-webhook-client | already audited in Phase 2 | Approved (existing) |

Note: `gsd-tools package-legitimacy check` accepts only `npm|pypi|crates` (it printed its usage error for `packagist` and `composer`), so the seam verdict could not be produced for a Composer package. The name `stripe/stripe-php` comes from the official repository README (`composer require stripe/stripe-php`) and Stripe's own documentation, the Packagist record points to the same `stripe/stripe-php` GitHub repository, the license is MIT (on the project allowlist in `scripts/check-licenses.php`), and a scratch install ran clean. The planner should still make the `composer.json`/`composer.lock` change a normal reviewed diff and run `composer check-licenses`. The scratch install used `--no-scripts`, so no package script was executed.

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
 ISSUE (Admin, one DB transaction, existing)                      Stripe (external)
 IssueDocument -> DocumentIssuer::issue
   |- writeAdminRow  (pay_token + card flag AND-ed with global + stripe-configured)   [draft still open]
   |- flip state to issued (last write on invoices)
   |- storePdf  -> InvoicePdfData reads pay URL + flag -> "Zaplatit kartou" button
   '- afterCommit: CreatePaymentLink(link_row_id)   -----------------------------+
                                                                                  v
 CreatePaymentLink (KokpitJob, idempotent)                                  POST /v1/payment_links
   1 lock invoice FOR UPDATE, read remaining, pending row -> amount                 (idempotency_key = row id,
   2 COMMIT (no HTTP inside a transaction)                                           limit=1, metadata, price_data)
   3 call Stripe  ------------------------------------------------------------>   <- plink id + url
   4 lock again: still pending and amount == remaining ? active : deactivate-and-regenerate-or-fail
   '- failure -> state failed (fixed class) + AdminAlerter (bell + mail)

 CLIENT                                     PUBLIC  GET /pay/{token}  (no session, no-store, noindex, no-referrer, throttled)
 PDF / e-mail button -------------------->  token valid & payable & live link ? 302 -> Stripe URL
                                            otherwise one neutral card: preparing | paid | cancelled | invalid
 Stripe checkout (card) -----------------------------------------------------> payment
                                                                                  |
 WEBHOOK  POST /webhooks/stripe  <------------------ checkout.session.completed / async_payment_succeeded / refund.created
   verify Stripe-Signature (SDK, 300 s tolerance)  -- bad -> 400
   INSERT webhook_calls ON CONFLICT (name,event_id) DO NOTHING
     new row           -> dispatch ProcessStripeWebhook -> 200 immediately
     existing+unprocessed -> re-dispatch (repairs a lost dispatch) -> 200
     existing+processed   -> 200, nothing
                                    |
 ProcessStripeWebhook (ProcessWebhookJob subclass with the KokpitJob contract, system context)
   parse -> classify -> ONE transaction, lock order: document, payment, income
     refund.created ............................ card_payments(type=refund) + alert
     same session/PI already recorded ........... ignore (same payment)
     no document / cancelled / currency / amount mismatch ... card_payments(unmatched, reason) + alert
     document already paid ...................... card_payments(duplicate) + alert
     match: amount == link offered == remaining . system write core -> invoice_payments(method card, created_by null)
                                                  + income via RecordTransaction core -> PaymentLinks::reconcile (link deactivated)
                                                  + card_payments(matched) + bell notice
   set webhook_calls.processed_at ; failure -> job retries, then failed_jobs + System slot

 ADMIN  CardPaymentResource (tabs, record view, 3 resolutions) | link section on the document | dashboard card | System page
 SCHEDULER  model:prune (processed webhook_calls older than 90 days) ; sweep of "deactivated, not yet deactivated at Stripe"
```

### Recommended Project Structure
```
app/Domain/Payments/
├── Actions/            # CreatePaymentLink job entry helpers, RegeneratePaymentLink, DeactivatePaymentLink (manual),
│                       # RecordCardPayment (system core caller), AssignCardPayment, ResolveCardPayment, RecordPaymentReturn
├── Enums/              # PaymentLinkState, DeactivationReason, CardPaymentType, CardPaymentReason, CardPaymentResolution
├── Jobs/               # CreatePaymentLink, DeactivatePaymentLinks (sweep + targeted), ProcessStripeWebhook
├── Models/             # PaymentLink, CardPayment
├── Notifications/      # CardPaymentMatched (bell), CardPaymentProblem (bell+mail), PaymentLinkFailed
├── Policies/           # AdminOnly registrations
├── Queries/            # UnresolvedPayments (counts), PayState (Partner-safe reader), LinkSection reader
├── Stripe/             # StripeGateway (interface), StripeApiGateway (SDK), StripeSignatureValidator,
│                       # StripeWebhookProcessor, StripeWebhookProfile, PayloadReader (array-based parsing)
└── Support/            # PayToken, PayUrl, PaymentLinks (reconcile service), StripeConfig (configured? flags)
app/Domain/Shared/Models/WebhookCall.php     # + storeOnce(), prunable() override, casts
app/Http/Controllers/PayPageController.php   # public token route
app/Http/Controllers/StripeWebhookController.php
app/Filament/Resources/CardPaymentResource(.php, Pages/, RelationManagers/ActivityHistory)
app/Filament/Widgets/UnresolvedPayments.php
app/Filament/Support/PaymentLinkSection.php, PayByCardAction.php
resources/views/pay/show.blade.php           # panel-branded neutral card (no Livewire)
resources/views/mail/invoice-text.blade.php  # unchanged; block appended by SendInvoiceEmail
database/migrations/2026_10_18_0001xx_*.php  # see Schema section
lang/{cs,en}/pay_page.php, lang/cs/card_payments.php, enums additions
tests/Feature/Payments/, tests/Concurrency/payment-match-worker.php, tests/Support/FakeStripeGateway.php
```

### Schema (recommended; names are Claude's discretion per CONTEXT)

1. **`invoice_admin`** gains `card_payment_enabled boolean NOT NULL DEFAULT false` and `pay_token varchar(32) NULL` with `UNIQUE (pay_token)` and `CHECK (pay_token IS NULL OR card_payment_enabled)`. The existing child guard freezes every column of this table except `internal_note`, `internal_note_updated_at`, `pdf_document_id`, `updated_at` once the invoice is issued; the new columns are therefore frozen automatically and need no trigger change. [VERIFIED: database/migrations/2026_10_17_000300_create_invoice_admin_tables.php]
2. **`payment_links`** (Admin-only, `DeniesPartners`): `id` uuidv7; `invoice_id` and `currency` with the composite FK `(invoice_id, currency) -> invoices (id, currency)` (same pattern as `invoice_payments`); `amount_minor bigint CHECK > 0`; `state` CHECK in (`pending`,`active`,`failed`,`deactivated`); `deactivation_reason` CHECK in (`paid`,`cancelled`,`partial`,`manual`,`replaced`) required exactly when `state = 'deactivated'`; `failure_class`; `stripe_link_id` UNIQUE nullable (set when the call returns); `stripe_url` nullable; `remote_deactivated_at timestamptz` nullable; `created_by` nullable user; partial unique index `ON payment_links (invoice_id) WHERE state IN ('pending','active')` (at most one live link per document).
3. **`card_payments`** (Admin-only): `id`; `type` CHECK in (`matched`,`duplicate`,`unmatched`,`refund`); `reason` (nullable, CHECK per type); `resolution` CHECK in (`none`,`assigned`,`noted`,`returned`) plus `resolved_at`, `resolution_note` (text, 500 max); `amount_minor bigint CHECK > 0`, `currency char(3)`; `stripe_event_id text NOT NULL UNIQUE`; `stripe_session_id` UNIQUE where not null; `stripe_payment_intent_id` UNIQUE `WHERE type <> 'refund' AND stripe_payment_intent_id IS NOT NULL`; `stripe_refund_id` UNIQUE where not null (CHECK: refund rows carry it); `stripe_link_id` text; nullable `invoice_id`, `payment_link_id`, `invoice_payment_id`, `return_transaction_id`; `received_at`. **FK `invoice_payment_id -> invoice_payments(id) ON DELETE SET NULL`** (the codebase default is NO ACTION, which would make `DeletePayment` fail; see Pitfall 12). "Smazaná platba" is then derived: type `matched` or resolution `assigned` with `invoice_payment_id IS NULL`. No FK to `webhook_calls` (rows there are pruned).
4. **`webhook_calls`** gains `event_id varchar(255)`, `event_type varchar(100)`, `processed_at timestamptz`; `UNIQUE (name, event_id) WHERE event_id IS NOT NULL`; partial index `(created_at) WHERE processed_at IS NULL` for the System indicator. Current columns for reference: `name`, `url`, `headers`, `payload`, `attachments`, `exception`, `timestampsTz`. [VERIFIED: database/migrations/2026_10_07_152631_create_webhook_calls_table.php]
5. **`invoice_payments`**: `ALTER COLUMN created_by DROP NOT NULL` with `CHECK ((method = 'card') = (created_by IS NULL))`, and widen `invoice_payments_method_check` to include `'card'`. DDL does not fire the row guard. `PaymentMethod` enum gains `Card` ("Kartou", not selectable in the manual modal).
6. **`dashboard_widget_preferences`**: migration widening `dashboard_widget_preferences_widget_key_check` with `unresolved_payments` (exact pattern of `2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php`, with a `down()` that deletes the new rows first).

### Pattern 1: Frozen-at-issue token and flag, written before the state flip
**What:** `DocumentIssuer::writeAdminRow()` runs while the invoice is still a draft; that is where `card_payment_enabled` is finalised (draft choice AND global setting AND stripe-configured) and `pay_token` is generated when enabled. The PDF (rendered later in the same transaction) reads both through a narrow accessor, so the printed URL exists before any Stripe call and a regenerated PDF prints the same URL.
**When to use:** every invoice and proforma issue; never for a credit note (`carriesPayment()` is false).
**Example:**
```php
// Source: existing shape of DocumentIssuer::writeAdminRow (app/Domain/Invoicing/Issuing/DocumentIssuer.php) + this phase
$cardOn = $kind->carriesPayment()
    && $admin->card_payment_enabled                       // draft toggle, initialised from the client flag
    && app(PaymentSettings::class)->online_payments_enabled
    && app(StripeConfig::class)->isConfigured();

$admin->forceFill([
    'card_payment_enabled' => $cardOn,
    'pay_token' => $cardOn ? PayToken::generate() : null,  // Str::random(32), CSPRNG
]);
$admin->save();
// ... later, after the flip and the PDF, in the same transaction:
if ($cardOn) {
    $link = PaymentLink::pendingFor($issued);               // state = pending, amount = remaining at this moment
    CreatePaymentLink::dispatch($link->id)->afterCommit();
}
```

### Pattern 2: Stripe call outside the transaction, re-check under the lock
**What:** The job locks the document, reads `remaining`, stores it on the `pending` row, commits, calls Stripe with `idempotency_key = payment_links.id`, then locks again; if the row is still `pending` and the amount still equals the remaining amount it becomes `active`, otherwise the freshly created Stripe link is deactivated and the row is marked `deactivated` (reason `partial` or `paid`) or a regenerate is queued.
**When to use:** create and regenerate; deactivation uses the same shape (DB state first, remote call after).
**Why:** a payment can be recorded between the dispatch and the Stripe response (D-08: a wrong-amount link never stays active), and an HTTP call inside a row lock would hold the document lock for up to the SDK timeout.
**Example:**
```php
// Source: stripe-php RequestOptions (idempotency_key option) + docs.stripe.com/api/idempotent_requests
$created = $gateway->createLink(new LinkRequest(
    idempotencyKey: $link->id,                // same row id on every retry of the same attempt
    amountMinor: $link->amount_minor,
    currency: strtolower($link->currency),
    productName: $name,                        // "Faktura :number" / "Invoice :number" (500-char limits apply to messages)
    metadata: ['invoice_id' => $link->invoice_id, 'payment_link_row' => $link->id],
    confirmationMessage: $confirmation,        // <= 500 chars
    inactiveMessage: $inactive,                // <= 500 chars
));
```

### Pattern 3: One reconcile invariant for "a live link's amount equals what is owed"
**What:** `PaymentLinks::reconcile(Invoice $locked)` is called inside the already-locked transactions of `RecordPayment::handle`, `RecordPayment::carry`, `IssueCreditNote::partial` and `::storno` (and the new card write core). It computes `remaining` with `InvoiceBalances::REMAINING_SQL` semantics; if a `pending`/`active` link exists and (`remaining = 0`, the document is cancelled, or `remaining <> link.amount_minor`) it sets `state = deactivated` with the reason and dispatches `DeactivatePaymentLinks` `afterCommit`. A scheduled sweep (every few minutes) re-sends any row with `state = 'deactivated' AND stripe_link_id IS NOT NULL AND remote_deactivated_at IS NULL`, so a lost dispatch cannot leave a Stripe link live.
**When to use:** every code path that changes what is owed. `DeletePayment` does not reactivate anything (UI-SPEC: the Admin regenerates).
**Note:** a partial credit note changes the remaining amount too; D-08's rule "a wrong-amount link never stays active" covers it even though CONTEXT only names partial manual payments.

### Pattern 4: Webhook intake = package pieces with three overrides
**What:** keep `webhook_model` (`App\Domain\Shared\Models\WebhookCall`), implement the package's `SignatureValidator` contract around `\Stripe\WebhookSignature::verifyHeader($request->getContent(), $request->header('Stripe-Signature'), $secret, 300)`, subclass `WebhookProcessor` to (a) store with an `INSERT ... ON CONFLICT DO NOTHING RETURNING id`, (b) dispatch only for a new row or for an existing row with `processed_at IS NULL`, (c) answer 200, and register `process_webhook_job` as the `ProcessWebhookJob` subclass. The route is a plain `POST /webhooks/stripe` without session, cookies or CSRF middleware.
**Example:**
```php
// Source: vendor/spatie/laravel-webhook-client/src/SignatureValidator/SignatureValidator.php (contract) and stripe-php WebhookSignature
final class StripeSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');   // env STRIPE_WEBHOOK_SECRET

        if ($secret === '') {
            return false;                                              // fail closed, never process unsigned
        }

        try {
            \Stripe\WebhookSignature::verifyHeader((string) $request->getContent(), (string) $request->header('Stripe-Signature'), $secret, 300);

            return true;
        } catch (\Stripe\Exception\SignatureVerificationException) {
            return false;
        }
    }
}
```
```php
// bootstrap/app.php withExceptions: the package throws a plain Exception, which would be a 500 and make Stripe retry.
$exceptions->render(static fn (\Spatie\WebhookClient\Exceptions\InvalidWebhookSignature $e, Request $r) => response()->json(['message' => 'invalid signature'], 400));
```

### Pattern 5: Match transaction (lock order document, payment, income)
**What:** inside one `DB::transaction`: `Invoice::query()->whereKey($id)->lockForUpdate()` first (same order as `RecordPayment`, `DeletePayment`, `ConvertProforma`), then insert the `card_payments` row with `insertOrIgnore` (PostgreSQL `ON CONFLICT DO NOTHING`; a unique violation caught with try/catch would abort the whole PostgreSQL transaction), then classify, then (match only) write the payment and the income through the extracted core, then `PaymentLinks::reconcile`, all before the transaction ends. Classification order: (1) already recorded session/PI = ignore, (2) no document or unknown = `unmatched` (undetermined), (3) cancelled = `unmatched` (cancelled), (4) `remaining = 0` = `duplicate`, (5) currency differs from the link/document = `unmatched` (currency), (6) amount differs from the link's offered amount **or** from the current remaining amount = `unmatched` (amount), (7) match.

### Pattern 6: System-callable write core (the Phase 10 hand-over made concrete)
**What:** split `RecordPayment::handle()` into the authorising public method and a public `recordLocked(Invoice $locked, PaymentFacts $facts, ?User $actor)` that holds today's body unchanged (state check, `PaymentInput`-style validation, `invoice_payments` insert, `bookIncome`); do the same in `RecordTransaction` (`write()` after `Gate`). The webhook job and the "assign to document" resolution call the core; `created_by` is the actor, or null for the webhook (`method = 'card'`). `PaymentInput::method()` must keep refusing `card` for the manual path (it currently accepts any `PaymentMethod` case via `tryFrom`). The income description becomes "Platba kartou faktury :number" for card payments (UI-SPEC Surface M).
**Why:** CONTEXT fixes "income only through the rules of `RecordPayment`"; both Actions authorise a `User`, which a job does not have.

### Pattern 7: Public pay page as a controller, not a Livewire page
**What:** `GET /pay/{token}` -> `PayPageController`: validate `^[A-Za-z0-9]{32}$` before touching the database; resolve the token as system; build one of five outcomes (redirect, preparing, paid, cancelled, invalid) from document state and link state; every non-redirect outcome renders the same Blade card (`x-filament-panels::page.simple` on the panel layout). Headers: `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer` (existing `SetNoReferrerPolicy`), `throttle` limiter (new named limiter beside `invitation` and `document-files` in `AppServiceProvider`). Redirect target: read from `payment_links.stripe_url` only after asserting `https` and a `stripe.com` host. Register with route middleware `Filament\Http\Middleware\SetUpPanel:admin` so the panel brand and assets render outside `/admin`; strip session, cookie and CSRF middleware from this route and from the webhook route. If the Wave 0 spike shows the simple layout does not render outside the panel group, register the same controller through the panel's `routes()` closure (the proven `/admin/invitation` precedent) at `/admin/pay/{token}` and add it to `spaUrlExceptions`.
**Why a controller:** a Livewire page serialises public properties into a snapshot, adds a Livewire update endpoint to a public surface, and cannot cheaply guarantee an identical response shape; a controller returns a 302 or a static view.

### Anti-Patterns to Avoid
- **HTTP to Stripe inside `DB::transaction` or while holding the document lock.** The SDK default timeout is 80 s (`CurlClient::DEFAULT_TIMEOUT = 80`) while `KokpitJob` times out at 60 s; set the client timeout below the job timeout (for example 20 s) and never call Stripe under a lock.
- **try/catch around a unique-violation insert inside a transaction** (PostgreSQL marks the transaction aborted); use `ON CONFLICT DO NOTHING`.
- **Matching on `presentment_details`** or on the Stripe payment link amount instead of the session's integration currency/amount.
- **Storing link state on `invoices`** (frozen row; Phase 10 hand-over: a column written after issue needs an owner decision amending D-13).
- **A queued Laravel notification for the Admin alerts.** `AdminAlerter` is deliberately synchronous so the queue being broken cannot hide an alert; deliver inside the job with `sendNow`.
- **Throttling Admin notices per notice type.** `AdminAlerter` throttles per key; use a key that includes the `card_payments` id, otherwise a second duplicate within 15 minutes is only counted and the Admin sees one bell entry for two payments.
- **Rendering the Stripe URL in any HTML** (Admin section, Partner button, mail); only the stable Kokpit URL is shown (UI-SPEC "Never on a Partner surface").

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Stripe signature verification | HMAC parsing of `t=`/`v1=` with your own tolerance | `\Stripe\WebhookSignature::verifyHeader(...)` (300 s default) | Multiple `v1` signatures during secret rolling, `v0` test scheme to ignore, constant-time comparison, replay window; verified in the scratch smoke test |
| Payment Link create/deactivate HTTP | cURL + manual form encoding of nested params | `StripeClient->paymentLinks->create()/update()` with `idempotency_key` | Nested `line_items[0][price_data][...]` encoding, retries, error classes |
| Webhook event storage | A second events table with its own processor | `WebhookCall` + `WebhookProcessor` subclass (D-15) | The package already provides model, config, validator and job seams |
| Fake Stripe for tests | Mocking the SDK class graph | A two-method `StripeGateway` interface and `FakeStripeGateway` | D-18; keeps tests free of SDK internals |
| Admin alert delivery | New notification plumbing | `AdminAlerter` + a `Notification` subclass shaped like `OperationalAlert` (bell via `FilamentNotification::getDatabaseMessage()`) | Never throws, never depends on the queue, existing throttle |
| Derived paid/remaining/cancelled | A stored `paid` flag | `InvoiceBalances` (`PAID_SQL`, `CREDITED_SQL`, `REMAINING_SQL`) and `Invoice::isCancelled()` | Phase 10 rule: state is derived, never stored; the issued row accepts no write |
| Event pruning | A bespoke delete loop | `MassPrunable` (the model already uses it) with `model:prune --model=` scheduled daily | The package model implements `prunable()`; override it to touch processed rows older than 90 days only |
| Payment deletion | Any other delete path | `DeletePayment` | The database guard refuses everything else; `kokpit.payment_deletion` literal is allowed in one file only (`tests/Arch/PaymentDeletionPathTest.php`) |

**Key insight:** every hard invariant of this phase already has a home in Phase 10 (derived balances, lock order, payment deletion path, health slots, alerting). Phase 11 should add narrow seams (a system write core, one reconcile service, three new tables) rather than parallel mechanisms.

## Common Pitfalls

### Pitfall 1: A bad signature becomes HTTP 500
**What goes wrong:** `InvalidWebhookSignature extends Exception` (no HTTP code) is thrown by `WebhookProcessor::ensureValidSignature()`; unhandled it renders as 500, so Stripe treats a forged call like an outage and a real signature problem looks like downtime.
**Why:** the package leaves the mapping to the application. [VERIFIED: vendor/spatie/laravel-webhook-client/src/Exceptions/InvalidWebhookSignature.php:7 `class InvalidWebhookSignature extends Exception`]
**How to avoid:** render the exception as 400 in `bootstrap/app.php`; also listen to `InvalidWebhookSignatureEvent` for a counter/log without payload.
**Warning signs:** 5xx on the endpoint in Stripe's delivery log; Stripe retries for up to three days in live mode. [CITED: docs.stripe.com/webhooks]

### Pitfall 2: The package config is invalid until a job class is set, and that class collides with the job architecture test
**What goes wrong:** `config/webhook-client.php` has `'process_webhook_job' => ''` [VERIFIED: config/webhook-client.php:75]; `WebhookConfig::__construct` throws `InvalidConfig` unless it is a `ProcessWebhookJob` subclass, and the scoped `WebhookConfigRepository` constructs every config entry on first use. A `ProcessWebhookJob` subclass uses `Dispatchable`, so `tests/Arch/JobContractTest.php` (`it('makes every dispatchable class under app/ extend KokpitJob'` at line 65) rejects it, and PHP single inheritance prevents extending both.
**How to avoid:** make `ProcessStripeWebhook extends ProcessWebhookJob` carry the KokpitJob contract by hand (`#[Tries(3)]`, `#[Backoff(10, 60, 300)]`, `#[Timeout(60)]`, `#[Idempotent(how: ...)]`, `middleware()` returning `[new RunsAsSystem]`), and amend the architecture test narrowly: the dispatchable rule accepts either a `KokpitJob` subclass or a `ProcessWebhookJob` subclass that declares `#[Idempotent]`; add every new job to `EXPECTED_APPLICATION_JOBS`. Rename the `default` config entry to a `stripe` entry whose secret comes from `STRIPE_WEBHOOK_SECRET`.
**Warning signs:** `InvalidConfig` the first time the endpoint is hit in a test; red `JobContractTest`.

### Pitfall 3: The package stores and dispatches duplicates
**What goes wrong:** `WebhookProcessor::process()` calls `storeWebhook()` then `processWebhook()` unconditionally; `WebhookCall::storeWebhook()` is a plain `static::create($data)`. A unique index alone turns a redelivery into an exception (500, Stripe retries again); no index lets two deliveries process twice.
**How to avoid:** the processor subclass inserts with `ON CONFLICT (name, event_id) DO NOTHING RETURNING id` and decides: new row dispatches; existing row with `processed_at IS NULL` re-dispatches (repairs a dispatch lost to a Redis outage); existing processed row does nothing. All three answer 200.
**Warning signs:** two `payment` rows from one event in a concurrency test.

### Pitfall 4: Lost dispatch after the event row is stored
**What goes wrong:** the row is committed, `dispatch()` throws (Redis down), the controller returns 500, Stripe retries, the retry finds the event id and ignores it: the payment is never processed and nothing alerts.
**How to avoid:** the re-dispatch rule of Pitfall 3, plus the System indicator on `processed_at IS NULL` older than the warning age (UI-SPEC Surface K, default 15 minutes, thresholds in `config/kokpit.php` `health`).

### Pitfall 5: The Phase 10 write path needs a user and a `created_by`
**What goes wrong:** `RecordPayment::handle()` (`Gate::forUser($actor)->authorize('update', $invoice)`, line 63) and `RecordTransaction::handle()` (`authorize('create', Transaction::class)`, line 44) both authorise a `User`; `invoice_payments.created_by` is `$table->foreignUuid('created_by')->constrained('users');` (not nullable, `create_invoice_admin_tables.php:173`) and `invoice_payments_method_check CHECK (method IN ('transfer', 'cash'))` (line 185) refuses `card`. A job running as system has no user; passing "the first Admin" would falsify the audit trail.
**How to avoid:** Pattern 6 plus migration item 5. Keep the manual path unable to choose `card`.
**Warning signs:** `AuthorizationException` in the webhook job; SQLSTATE 23514 on method.

### Pitfall 6: Counting on Stripe's session fields without Adaptive Pricing in mind
**What goes wrong:** matching on what the payer saw (local currency) rejects or mis-books valid payments.
**Fact:** "The Checkout Session and the underlying PaymentIntent objects reflect what your customer paid in your integration currency and amount"; the local figure appears only in `presentment_details` (`presentment_amount`, `presentment_currency`). "Adaptive Pricing is always enabled for Payment Links." [CITED: docs.stripe.com/payments/currencies/localize-prices/adaptive-pricing]
**How to avoid:** compare `session.currency` (lowercase in Stripe; upper-case it) and `session.amount_total` with the link row; ignore `presentment_details` (optionally store it for the Admin).

### Pitfall 7: Stripe idempotency replays 500 responses; the SDK default timeout outlives the job
**What goes wrong:** "Retries with the same key return the saved response, including 500 errors" (API v1); keys live at least 24 hours. A queue retry after a Stripe 5xx with the same key can never succeed. Separately `CurlClient::DEFAULT_TIMEOUT = 80` exceeds `#[Timeout(60)]`, so a slow Stripe call kills the worker mid-request. [CITED: docs.stripe.com/api/idempotent_requests] [VERIFIED: stripe-php lib/HttpClient/CurlClient.php:164]
**How to avoid:** key = `payment_links.id` (stable across queue retries of the same attempt, new for a regenerate); treat an `ApiErrorException` with a 5xx as a terminal `failed` state with a fixed class and alert (Admin presses "Vytvořit odkaz znovu", which creates a new row and key); set the client timeout to about 20 s and `max_network_retries` to 1 or 2.

### Pitfall 8: Stripe minimum charge and special currencies make link creation fail
**Fact:** minimum charge amounts apply (for example 15.00 CZK, 0.50 EUR, 0.50 USD, 2.00 PLN); ISK and UGX must be passed as two-decimal values with `00` even though they are zero-decimal in ISO terms. [CITED: docs.stripe.com/currencies]
**How to avoid:** a remaining amount below the minimum or an unsupported currency is a `failed` link with a fixed class (the failure alert already exists); do not special-case beyond a guard that refuses ISK and UGX with a fixed class unless the owner wants them ([ASSUMED] that Brick's exponent for those codes differs from Stripe's convention; not verified).

### Pitfall 9: The printed URL depends on the request host
**What goes wrong:** `route()` takes the root from the current request; a regenerated PDF or a queue-rendered one would print another host, breaking "a regenerated PDF stays equivalent" and printed-link stability (D-01).
**How to avoid:** build the URL from `config('app.url')` only (production requires a public https `APP_URL`, `ProductionConfigGuard`), with a 32-character token (about 190 bits, `Str::random(32)`), which keeps the full URL well under the 80-character cap of UI-SPEC Surface B for any reasonable host.

### Pitfall 10: Webhook route runs through the session, cookie and CSRF stack
**What goes wrong:** routes in `routes/web.php` get the `web` group; a Stripe POST would fail CSRF (`PreventRequestForgery`) and start a Redis session per call.
**How to avoid:** register the routes with `withoutMiddleware([...])` for session, cookie, error-share and `PreventRequestForgery`, or exclude the path via `$middleware->preventRequestForgery(except: ['webhooks/stripe'])` [VERIFIED: vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:605]. Never read the body through `$request->input()` for signature work; the validator uses `getContent()`.

### Pitfall 11: Out-of-order and duplicate Stripe objects
**Fact:** "Stripe doesn't guarantee the delivery of events in the order that they're generated"; "In some cases, two separate Event objects are generated and sent" (use `data.object` id plus type). [CITED: docs.stripe.com/webhooks]
**How to avoid:** the unique session id and payment-intent id are the real idempotency keys, not the event id alone. `async_payment_succeeded` after `completed` for the same session hits the unique session id and is ignored silently. A `refund.created` that arrives before its payment is recorded is stored independently (nullable links) and stays unresolved for the Admin.

### Pitfall 12: `ON DELETE NO ACTION` everywhere blocks `DeletePayment`
**What goes wrong:** the codebase uses NO ACTION by convention ("Keys use NO ACTION so every refusal answers 23503"); a plain FK from `card_payments.invoice_payment_id` to `invoice_payments` would make the sanctioned deletion fail with 23503 and D-14 would be unusable.
**How to avoid:** `ON DELETE SET NULL` on that one column; if `card_payments` gets a guard trigger, let it accept the `invoice_payment_id` -> NULL transition. Add a test: delete a matched card payment through `DeletePayment`, assert the record remains with its unique references, replay the same event, assert nothing is recreated.

### Pitfall 13: Frozen rows and pinned columns
**What goes wrong:** adding columns to `invoices` fails `PartnerSafeColumnsTest` (pinned list) and any post-issue write is refused by `invoices_frozen_guard`; link state changes after issue cannot go there.
**How to avoid:** the schema above (state in `payment_links`, token/flag in `invoice_admin`). The Partner button reads link existence through a narrow system reader after the Partner-scoped record has loaded (the `InvoicePdfController` precedent: authorise through the scoped invoice, read Admin-only data as system).

### Pitfall 14: Stripe-shaped literals trip gitleaks and the sensitive checker
**Fact:** `.gitleaks.toml` extends the default ruleset (Stripe `sk_`/`rk_` keys) and adds `\b(?:pk_live_|whsec_)[A-Za-z0-9]{16,}` and `\b(?:acct|plink)_[A-Za-z0-9]{12,}`. [VERIFIED: .gitleaks.toml rules `kokpit-stripe-publishable-or-webhook`, `kokpit-stripe-object-id`]
**How to avoid:** test fixtures assemble such values at runtime from fragments (`'whsec_'.Str::random(32)`, `'plink_'.Str::random(14)`, the signing secret generated per test); never write a full-length value in a file, a doc or a commit message. Run `scripts/check-sensitive.sh` before committing.

### Pitfall 15: Shared Stripe account noise
Payments on the same Stripe account that Kokpit did not create (other links, other products) arrive as `checkout.session.completed` events with no Kokpit metadata and unknown link ids. PA-08 requires unmatched payments to be recorded and alerted, so each such payment alerts the Admin. Flag as an Open Question; a mitigation is to configure the webhook destination on a Stripe account used only for invoicing.

### Pitfall 16: Hard-coded counts and lists in existing tests
`HealthRegistryTest` hard-codes the slot list ("has the six slots of the System page", lines 36-50); `JobContractTest` hard-codes `EXPECTED_APPLICATION_JOBS`; `ActivityAllowlistTest`, `ModelDeclarationTest`, `CanaryRegistry`, `MorphMap`, `PanelRegistryTest`, `EnvExampleTest` (every env key read in config must be in `.env.example` and vice versa) and `RepositoryFilesTest` (classes named in the `CONTRIBUTING.md` bullets must exist) all need an edit. These are expected plan tasks, not surprises.

## Code Examples

### Payment Link request (inline price, single use, metadata)
```php
// Source: https://docs.stripe.com/api/payment-link/create (line_items.price_data, restrictions, after_completion, inactive_message,
//         payment_intent_data.metadata, payment_method_types) ; SDK: StripeClient->paymentLinks->create($params, $opts)
$link = $client->paymentLinks->create([
    'line_items' => [[
        'quantity' => 1,
        'price_data' => [
            'currency' => 'czk',                              // lowercase; amount in minor units
            'unit_amount' => $amountMinor,
            'product_data' => ['name' => $productName],
        ],
    ]],
    'restrictions' => ['completed_sessions' => ['limit' => 1]],
    'metadata' => ['invoice_id' => $invoiceId, 'payment_link_row' => $rowId],   // copied to checkout sessions
    'payment_intent_data' => ['metadata' => ['invoice_id' => $invoiceId]],       // visible in the Stripe dashboard on the payment
    'payment_method_types' => ['card'],                       // see Assumption A1
    'after_completion' => ['type' => 'hosted_confirmation', 'hosted_confirmation' => ['custom_message' => $confirmation]],
    'inactive_message' => $inactiveMessage,                   // <= 500 characters
], ['idempotency_key' => $rowId]);

$linkId = $link->id;   // stored in payment_links.stripe_link_id
$url = $link->url;     // stored in payment_links.stripe_url, never rendered
```

### Deactivation
```php
// Source: https://docs.stripe.com/api/payment-link/update ("active ... If false, customers visiting the URL will be shown a page saying that the link has been deactivated")
$client->paymentLinks->update($stripeLinkId, ['active' => false]);
```

### Reading a verified event without depending on the SDK object model
```php
// Source: https://docs.stripe.com/payments/currencies/localize-prices/adaptive-pricing (session.currency/amount_total are integration currency)
$payload = $call->payload;                                    // array, as stored by the package
$object = $payload['data']['object'] ?? [];
$type = $payload['type'] ?? '';

if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
    if (($object['payment_status'] ?? '') !== 'paid') { return; }          // unpaid = wait for the async event
    $facts = [
        'session' => $object['id'] ?? null,
        'payment_intent' => $object['payment_intent'] ?? null,
        'link' => $object['payment_link'] ?? null,
        'invoice_id' => $object['metadata']['invoice_id'] ?? null,         // validate as UUID before use
        'amount' => (int) ($object['amount_total'] ?? 0),
        'currency' => strtoupper((string) ($object['currency'] ?? '')),
    ];
}
// refund.created: data.object is a refund: id, amount, currency, payment_intent, charge, status
```

### Idempotent insert inside the match transaction (PostgreSQL)
```php
// Source: Laravel insertOrIgnore -> INSERT ... ON CONFLICT DO NOTHING on pgsql; avoids an aborted transaction after a unique violation
$inserted = DB::table('card_payments')->insertOrIgnore([...$row, 'stripe_event_id' => $eventId]);
if ($inserted === 0) {
    return;                                                   // this event / session / payment intent is already recorded
}
```

### Signed test webhook
```php
// Source: https://docs.stripe.com/webhooks ("signed_payload" = timestamp + "." + body, HMAC SHA-256 with the endpoint secret)
$secret = 'whsec_'.Str::random(32);                           // random per test, assembled at runtime (Pitfall 14)
config(['services.stripe.webhook_secret' => $secret]);
$body = json_encode($payload, JSON_THROW_ON_ERROR);
$t = time();
$header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
$this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
```

### PDF button (Dompdf link annotation verified)
```blade
{{-- Source: smoke test this session: <a href> in a table cell with isRemoteEnabled=false produced a /URI annotation --}}
<table style="margin-top: 12pt;"><tr>
  <td style="background-color: {{ $palette->accent }}; padding: 8pt 16pt; border-radius: 4pt;">
    <a href="{{ $payUrl }}" style="color: {{ $palette->onAccent }}; font-weight: bold; font-size: 9pt; text-decoration: none;">{{ $label }}</a>
  </td>
</tr></table>
<div style="font-size: 8pt; color: {{ $palette->muted }}; margin-top: 4pt;">{{ $payUrl }}</div>
```

### Verified in-repo values used above (read this session)
| Value | Source (path:lines) | Verbatim |
|-------|---------------------|----------|
| Payment method cases | app/Domain/Invoicing/Enums/PaymentMethod.php:14-15 | `case Transfer = 'transfer';` `case Cash = 'cash';` |
| Method CHECK | database/migrations/2026_10_17_000300_create_invoice_admin_tables.php:185 | `CHECK (method IN ('transfer', 'cash'))` |
| `created_by` (payments) | same file:173 | `$table->foreignUuid('created_by')->constrained('users');` |
| Payment currency FK | same file:181 | `FOREIGN KEY (invoice_id, currency) REFERENCES invoices (id, currency)` |
| Deletion flag | app/Domain/Invoicing/Actions/DeletePayment.php:77 | `select set_config('kokpit.payment_deletion', ?, true)` |
| Health slot | app/Domain/Operations/Health/HealthSlot.php:20 | `case UnprocessedWebhooks = 'unprocessed_webhooks';` |
| Dashboard key | app/Domain/Dashboard/Enums/DashboardWidget.php:16 | `case UnpaidInvoices = 'unpaid_invoices';` |
| Process job config | config/webhook-client.php:75 | `'process_webhook_job' => '',` |
| Cancelled rule | app/Domain/Invoicing/Models/Invoice.php:112 | `EXISTS (SELECT 1 FROM invoices AS storno_notes WHERE storno_notes.credited_invoice_id = invoices.id AND storno_notes.cancels_invoice AND storno_notes.state = 'issued')` |
| Remaining rule | app/Domain/Invoicing/Queries/InvoiceBalances.php:42 | `GREATEST(invoices.total_minor - ... - ..., 0)` (full text in file) |
| Gate in write core | app/Domain/Invoicing/Actions/RecordPayment.php:63; app/Domain/Finance/Actions/RecordTransaction.php:44 | `Gate::forUser($actor)->authorize('update', $invoice);` / `Gate::forUser($actor)->authorize('create', Transaction::class);` |
| Package exception | vendor/spatie/laravel-webhook-client/src/Exceptions/InvalidWebhookSignature.php:7 | `class InvalidWebhookSignature extends Exception` |
| Dispatchable rule | tests/Arch/JobContractTest.php:65 | `it('makes every dispatchable class under app/ extend KokpitJob'` |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Create a Product and a Price object, then a link | `line_items[].price_data` with `product_data` inline in the Payment Link request | documented in the current API reference | One call per link |
| Webhook payload shape tied to the account's API version | SDK v22 pins `2026-09-30.endive`; events use the destination's/account's configured version | v22.0.0 (2026-09-30) | Parse event payloads as arrays and only the stable fields (`id`, `type`, `data.object.{id,payment_status,payment_intent,payment_link,amount_total,currency,metadata}`); do not type them through SDK objects |
| Event destinations "thin" vs "snapshot" | Docs recommend thin events for new integrations; classic snapshot events are still how checkout/refund events are delivered | current docs | Register the webhook as a snapshot destination (the event list used here is the snapshot catalog); the SDK's `Webhook::constructEvent` is the snapshot path |
| Developers Dashboard webhooks | Workbench "event destinations" | current docs | Setup instructions for the owner mention Workbench |

**Deprecated/outdated:**
- `payment_intent.succeeded`-based fulfilment for Payment Links: use the Checkout Session events (`checkout.session.completed` plus `async_payment_succeeded`); the session carries `payment_link` and the link metadata, the payment intent does not carry link metadata unless `payment_intent_data.metadata` is set.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `payment_method_types: ['card']` keeps Apple Pay and Google Pay (card-type wallets) and removes delayed methods (bank debits), so every payment arrives `paid` on `checkout.session.completed` | Code Examples, Pitfall 11 | If wallets are excluded the "pay by card" promise narrows; if delayed methods slip in, `payment_status = unpaid` events must wait for `async_payment_succeeded` (the processor already ignores unpaid sessions) |
| A2 | An already-open Checkout Session of a deactivated payment link can still complete | Pattern 5 step 6 | If wrong, the "amount equals remaining" extra check is merely redundant; if right it is what prevents booking a stale-amount payment |
| A3 | `Filament\Http\Middleware\SetUpPanel:admin` on a top-level route is enough for `x-filament-panels::page.simple` to render with brand and assets outside `/admin` | Pattern 7 | Fall back to the panel `routes()` closure (`/admin/pay/{token}`, proven by `/admin/invitation`); costs 6 URL characters and a SPA exception |
| A4 | Dompdf renders `border-radius: 4pt` on a table cell background acceptably | Code Examples (PDF) | Cosmetic only; fall back to a square button |
| A5 | 32-character alphanumeric `Str::random(32)` is an acceptable stable token (about 190 bits, no expiry) | Pitfall 9 | Costly to change after the first PDF is issued (D-01); owner may prefer a TTL after payment |
| A6 | ISK and UGX amounts differ between Brick's ISO exponent and Stripe's two-decimal convention | Pitfall 8 | Wrong amount offered for those two currencies; low likelihood for this user base |
| A7 | Payer PII in stored webhook payloads (name, e-mail, address in `customer_details`) for 90 days is acceptable or should be redacted before storage | Security Domain | GDPR/privacy exposure; the planner may prefer to store a trimmed payload |
| A8 | Reading D-06's "AND of three switches" as global setting AND stripe-configured AND per-document flag, where the per-document flag is initialised from the client flag and may be overridden (otherwise an override could never enable a client that is off) | Pattern 1, PA-03 | If the owner meant a strict three-way AND, the draft toggle default behaves the same but an Admin override-to-on for an "off" client would not take effect |
| A9 | Stripe events from non-Kokpit links on a shared Stripe account will alert as "unmatched" | Pitfall 15 | Alert noise for the owner if the account is shared |
| A10 | Env variable names `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` under `services.stripe.*` | Pattern 4 | Naming only; any change is cheap now, tracked by `EnvExampleTest` |

## Open Questions

1. **Should a match require the amount to equal the current remaining amount as well as the link's offered amount?**
   - What we know: D-10 says "same amount and currency as the link offered"; D-08 deactivates a link when the remaining amount changes, but an already-open session can complete later (A2).
   - What's unclear: whether to book a payment that equals the offered amount but exceeds what is now owed.
   - Recommendation: unmatched (reason amount) unless amount equals both the offered and the remaining amount; the Admin assigns it by hand. This never books money silently wrong.

2. **Admin alerts per payment versus the 15-minute alert throttle.**
   - What we know: UI-SPEC says throttling follows the Phase 3 pattern per notice type; `AdminAlerter` throttles per key.
   - Recommendation: throttle key includes the `card_payments` id (no cross-record suppression) because the core value is that no payment goes unnoticed; keep per-key throttling for link-creation failures per document.

3. **Where does the Stripe webhook destination live relative to other business on the same Stripe account?** (Pitfall 15) Owner to confirm; document the setup in the README/CONTRIBUTING closing task.

4. **Store the full webhook payload or a trimmed one?** (A7) Recommend dropping `customer_details`, `shipping_details`, `collected_information` and `customer_email` in an overridden `buildPayloadFromRequest()` unless the owner wants full payloads for debugging.

5. **Token lifetime.** Currently no expiry; the page answers "paid"/"cancelled" for old documents indefinitely. UI-SPEC lists "expired" as an invalid case; recommend no TTL and leave "expired" unused.

6. **Cancelled document with a payment that arrives** — UI-SPEC lists reason "Doklad je stornovaný"; CONTEXT does not. Recommended as an unmatched reason (no income on a cancelled invoice, consistent with `payment_not_allowed` in `RecordPayment`).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV (web PHP 8.5, PostgreSQL, Redis) | all tests (`ddev exec vendor/bin/pest`) | yes (project `kokpit` running) | DDEV v1.25.4, OrbStack | — |
| PHP 8.5 on host | scratch smoke tests only | yes | 8.5.11 | DDEV |
| Composer | add `stripe/stripe-php` | yes | 2.10.3 | — |
| `ext-curl`, `ext-mbstring`, `ext-json` | stripe-php requirements | yes (host); DDEV web assumed standard [ASSUMED] | — | — |
| Stripe CLI | optional manual verification (D-18) | binary at `/opt/homebrew/bin/stripe` (version not captured) | — | Stripe Dashboard test mode |
| Ghostscript | PDF text assertions (`PdfText`) | yes | — | tests skip with a reason when absent |
| Stripe account with test keys | manual end-to-end only | not probed (no credentials in repo, by design) | — | none needed for CI |

**Missing dependencies with no fallback:** none for planning or CI.
**Missing dependencies with fallback:** none blocking.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.2 on PHPUnit 13.3 (`composer.json`), DDEV PostgreSQL + Redis |
| Config file | `phpunit.xml` (existing), `tests/Pest.php`; `workflow.test_command = "ddev exec vendor/bin/pest"` |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Payments` (new directory) |
| Full suite command | `ddev exec vendor/bin/pest`; gate: `ddev composer ci` (test, pint, stan, check-licenses) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PA-01 | Issue dispatches `CreatePaymentLink` afterCommit; fake gateway failure leaves the document issued, row `failed`, Admin alerted; retry with same row id calls Stripe once; below-minimum/unsupported currency is a fixed failure class | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php` | no, Wave 0 |
| PA-02 | PDF contains lead line, label and printed URL (gs text) and a `/URI` annotation; mail block appended at send time, absent for paid/cancelled; Partner header action only with an active link; pay page 302 vs four states with identical shape and headers | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Payments/PayPageTest.php tests/Feature/Payments/PayButtonOutputsTest.php` | no, Wave 0 |
| PA-03 | Global off, client off, document off: no token, no link, no button; flag frozen after issue; credit note never | feature | `.../Payments/CardSwitchTest.php` | no, Wave 0 |
| PA-04 | Partial manual payment, full payment (any route), storno, partial credit note deactivate the live link in the same transaction; sweep deactivates remotely; regenerate deactivates old first; one live link per document (partial unique index) | feature + DB | `.../Payments/LinkLifecycleTest.php` | no, Wave 0 |
| PA-05 | Valid signature stored and 200; bad signature 400 and nothing stored; stale timestamp rejected; duplicate event ignored; unprocessed duplicate re-dispatched; no session/cookie/CSRF | feature | `.../Payments/StripeWebhookTest.php` | no, Wave 0 |
| PA-06 | Matching payment creates payment (method card, `created_by` null), income in the system category, link deactivated, bell notice; proforma stores payment only; unknown currency/amount mismatch is unmatched; Phase 10 `RecordPayment`/`DeletePayment` tests still green | feature | `.../Payments/MatchPaymentTest.php` plus `ddev exec vendor/bin/pest tests/Feature/Invoicing` | no, Wave 0 |
| PA-07 | Second payment on a paid document is `duplicate` with no income; same session/PI repeated is ignored; two concurrent distinct payments give one matched and one duplicate; deleted card payment is not recreated by redelivery | feature + concurrency | `.../Payments/DuplicatePaymentTest.php`, `tests/Concurrency/PaymentMatchConcurrencyTest.php` | no, Wave 0 |
| PA-08 | Unmatched and refund records and alerts; overview tabs and counts; three resolutions are single transactions, refused when already resolved; dashboard card only above zero; System slot states; prune removes only processed events older than 90 days | feature | `.../Payments/CardPaymentsOverviewTest.php`, `.../Payments/PruneWebhookCallsTest.php`, `.../Operations/HealthIndicatorsTest.php` | partially (health tests exist), Wave 0 for new |
| Cross-cut | Partner of client A never sees Stripe strings, link state, or client B canaries (route walk + rendered HTML); no public route besides the two allowed | isolation | `ddev exec vendor/bin/pest tests/Isolation` | exists, extend |

### Sampling Rate
- **Per task commit:** the touched file's targeted test plus `ddev exec vendor/bin/pest tests/Arch`.
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`.
- **Phase gate:** full suite and `ddev composer ci` green before `/gsd-verify-work`; `scripts/check-sensitive.sh` clean.

### Wave 0 Gaps
- [ ] `tests/Support/FakeStripeGateway.php` and `StripeGateway` binding (D-18).
- [ ] `tests/Support/StripeWebhookSigner.php` (header builder with runtime-assembled secret).
- [ ] `tests/Feature/Payments/*` files listed above and `tests/Concurrency/payment-match-worker.php` + test (copy the `payment-worker.php` / `PaymentDeletionConcurrencyTest.php` pattern, with a skip-lock mutation twin).
- [ ] Edits to `JobContractTest` (expected jobs, dispatchable rule), `HealthRegistryTest` (slot list), `ActivityAllowlistTest`, `ModelDeclarationTest`, `CanaryRegistry` (card payment record per type with a Stripe-reference canary and a resolution-note canary), `PartnerSafeColumnsTest` untouched (no `invoices` change), `tests/Feature/Repo/EnvExampleTest` (new env keys), `RepositoryFilesTest`.
- [ ] Spike test: pay route renders the simple layout outside `/admin` (A3).
- [ ] Framework install: `ddev composer require stripe/stripe-php:^22.0`.

## Security Domain

### Applicable ASVS Categories (Level 1, `security_asvs_level: 1`, `security_block_on: high`)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (public page and webhook are unauthenticated by design) | Webhook authenticity is by HMAC signature, not a login; the pay page grants nothing but a redirect or a neutral page |
| V3 Session Management | yes (negatively) | Strip session, cookie and CSRF middleware from the two public routes; no `Set-Cookie` on them |
| V4 Access Control | yes | Admin-only models (`DeniesPartners`), `#[AccessRule]` on every new Filament class, Partner button reads only kind, derived state and link existence; policy re-check on every Livewire update |
| V5 Input Validation | yes | Token pattern `^[A-Za-z0-9]{32}$` before any query; metadata invoice id validated as UUID; event payload parsed defensively (int casts, upper-case ISO currency validated through `Money::ofMinor`); resolution notes max 500 |
| V6 Cryptography | yes | HMAC verification through `\Stripe\WebhookSignature` (constant-time, 300 s tolerance); token from CSPRNG (`Str::random`); no hand-rolled crypto |
| V7 Error Handling and Logging | yes | Fixed failure classes only; wrap Stripe exceptions in fixed-message exceptions (as `SendInvoiceEmail` does for transport errors) so keys, request ids and payer data never reach `failed_jobs` alerts or the bell |
| V8 Data Protection | yes | Payload PII retention (A7); secrets only in env (D-16); System page reports configured/not configured, never a value or prefix |
| V9 Communications | yes | HTTPS only; Stripe delivers to HTTPS endpoints with TLS 1.2+; `Referrer-Policy: no-referrer`, `Cache-Control: no-store` |
| V11 Business Logic | yes | Unique constraints, document row lock, idempotent jobs, amount/currency checks, no income without a matched payment |

### Known Threat Patterns for this stack

| Pattern | STRIDE category | Standard Mitigation |
|---------|-----------------|---------------------|
| Forged webhook to book a fake payment | Spoofing / Tampering | Signature verification before storing; fail closed when the secret is not configured; 400 on failure |
| Replay of a captured valid webhook | Tampering | 5-minute timestamp tolerance plus unique `(name, event_id)` and unique session/PI |
| Token enumeration of pay URLs (document oracle) | Information Disclosure | 190-bit random token, constant response shape for every invalid case, per-IP throttle, number-only pages |
| Open redirect via a manipulated stored URL | Tampering | Redirect only to an `https` URL whose host ends in `stripe.com`, validated when stored and again before redirecting; always 302, never cached |
| Partner reading link state or Stripe references | Information Disclosure | Admin-only tables, narrow system reader, rendered-HTML canary tests, "Never on a Partner surface" list |
| Money booked twice or under a wrong amount | Tampering / Elevation | Lock order, unique constraints, match requires equality with offered and remaining amounts, duplicates and mismatches never create income |
| Stripe failure text leaking secrets or PII into alerts | Information Disclosure | Fixed classes, sanitised failed-job messages (`AlertMessageSanitiser`), no payload in notifications |
| Cross-site request abuse of Admin resolution actions | Tampering | Filament/Livewire CSRF as built; actions are single transactions that refuse an already-resolved record |
| Webhook endpoint flood | Denial of Service | Cheap HMAC check before any DB write; optional generous throttle; queue absorbs processing |

## Sources

### Primary (HIGH confidence)
- https://docs.stripe.com/api/payment-link/create and `?query=line_items`, `?query=restrictions`, `?query=after_completion`, `?query=payment_intent_data` (parameters, limits, `price_data`, 500-character messages, `restrictions.completed_sessions.limit`)
- https://docs.stripe.com/api/payment-link/update (`active`, `inactive_message`, `metadata`)
- https://docs.stripe.com/webhooks (signature scheme, 5-minute tolerance, retries for up to three days, manual resend 15/30 days, duplicates, ordering, quick 2xx, CSRF exemption, IP allowlist)
- https://docs.stripe.com/api/idempotent_requests (keys up to 255 chars, 24 h retention, saved 500 responses replayed)
- https://docs.stripe.com/api/checkout/sessions/object (`payment_link`, `payment_intent`, `payment_status`, `amount_total`, `currency` lowercase, `metadata`, `client_reference_id`)
- https://docs.stripe.com/api/events/types (`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `refund.created`, `charge.refunded`)
- https://docs.stripe.com/api/refunds/object (`id`, `amount`, `currency`, `payment_intent`, `status`)
- https://docs.stripe.com/payments/currencies/localize-prices/adaptive-pricing (always on for Payment Links; session and payment intent stay in integration currency; `presentment_details`)
- https://docs.stripe.com/currencies (minimum charge table, zero-decimal and special-case currencies)
- https://docs.stripe.com/payment-links/create (price_data example, `payment_method_types`, delayed payment methods)
- Packagist API for `stripe/stripe-php` (v22.0.0, MIT, repository) and raw files of stripe-php v22.0.0 (`Webhook.php`, `Stripe.php`, `Util/RequestOptions.php`, `HttpClient/CurlClient.php`, `composer.json`, CHANGELOG 22.0.0 "changes the pinned API version to `2026-09-30.endive`")
- Scratch install and smoke run of stripe-php v22.0.0 under PHP 8.5.11; Dompdf link annotation smoke run against the project's vendor
- In-repo sources read this session: `11-CONTEXT.md`, `11-UI-SPEC.md`, `REQUIREMENTS.md`, `CONTRIBUTING.md`, `RecordPayment`, `DeletePayment`, `RecordTransaction`, `DocumentIssuer`, `IssueDocument`, `ConvertProforma`, `CreateDraft`, `QueueInvoiceEmail`, `SendInvoiceEmail`, `InvoiceMail`, `InvoicePdf`, `InvoicePdfData`, `Invoice`, `InvoiceAdmin`, `InvoicePayment`, `PaymentMethod`, `InvoiceBalances`, `PaymentInput`, `KokpitJob`, `Idempotent`, `AdminAlerter`, `OperationalAlert`, `HealthSlot`, `HealthIndicatorRegistry`, `PlaceholderIndicator`, `UnsentInvoiceEmailsIndicator`, `OperationsServiceProvider`, `DashboardWidget`, `Dashboard`, `UnpaidInvoices`, `AdminPanelProvider`, `ViewPartnerInvoice`, `ViewInvoice`, `PartnerInvoiceResource`, `AcceptInvitation`, migrations for invoices, invoice_admin/payments, transactions link, webhook_calls, unpaid dashboard widget; `bootstrap/app.php`, `routes/*.php`, `config/webhook-client.php`, `config/kokpit.php`, `.gitleaks.toml`, `scripts/check-licenses.php`, tests `JobContractTest`, `PartnerSafeColumnsTest`, `RouteWalkTest`, `EnvExampleTest`, `HealthRegistryTest`; vendor sources of `spatie/laravel-webhook-client` 3.7.0 and `filament/filament` v5.10.2

### Secondary (MEDIUM confidence)
- WebSearch result summarising Stripe docs: a link that reaches its `completed_sessions` limit deactivates automatically and shows the inactive message (docs.stripe.com/payment-links/customize); not fetched page-by-page.

### Tertiary (LOW confidence)
- None used as authority; items without an authoritative read are tagged `[ASSUMED]` in the Assumptions Log.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, SDK version, license, API pin and runtime behaviour verified by scratch install on PHP 8.5.11; package seam could not machine-check Composer ecosystems (noted).
- Architecture: HIGH for constraints imposed by existing code (all read this session); MEDIUM for the pay-page route plumbing (A3) and the exact schema names (discretionary).
- Pitfalls: HIGH, each backed by a read source or a Stripe doc; Stripe in-flight-session behaviour (A2) and wallet coverage (A1) are assumptions.

**Research date:** 2026-10-18
**Valid until:** about 2026-11-17 (30 days; the Stripe SDK ships frequently, re-check `composer show stripe/stripe-php` for a newer v22.x before installing)
