# Phase 11: Stripe Payments - Context

**Gathered:** 2026-10-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Issued invoices and proformas can be paid by card through a single-use Stripe Payment Link; signed, idempotent webhooks match payments automatically; duplicate, mismatched and unmatched payments and refund events are recorded, alerted and resolved by Admin. Delivers PA-01..PA-08: link creation in a queued job, "Pay by card" in e-mail, PDF and detail (Admin and Partner), per-client and per-document disabling, link deactivation and regeneration, webhook endpoint with unique event ids, matching, duplicate/unmatched handling, payments overview, dashboard tile, System page indicator and event pruning.

Not in this phase: refunds, disputes and fees inside the app (handled in the Stripe dashboard; PAY-01), bank statement matching (PAY-02), payment reminders (BIL-03), the Partner-audit matrix (Phase 12).

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217; a matched payment is written only into `invoice_payments` and its income only through the rules of `RecordPayment` (system category, `TransactionCzk`, no `custom` source); payment matching to a document is by the invoice id, a bank payment by variable symbol scoped by document kind (Phase 10 hand-over in `CONTRIBUTING.md`); issued documents are immutable except payment state and internal note (Phase 10 D-13); income comes from invoices only, a payment on a proforma is carried to the invoice at conversion (Phase 10 D-18); `spatie/laravel-webhook-client` is installed with `App\Domain\Shared\Models\WebhookCall` registered, no endpoint is routed yet; `PaymentSettings::online_payments_enabled` and the client online-payment flag already exist; `KokpitJob` / `Idempotent` base for queued jobs.

### Pay button and public link (PA-02)
- **D-01:** The "Pay by card" button in the PDF and the e-mail points to a stable, public Kokpit URL for the document (signed token, no login), not to the Stripe URL. The stable URL exists from the moment of issue and does not depend on Stripe, so the PDF stays generated from snapshots at issue (Phase 10 D-23) and a regenerated PDF stays equivalent even after the Stripe link is replaced. — **Reversibility:** costly — the token format and public route are printed into issued PDFs and sent e-mails, so changing them breaks links already delivered to clients.
- **D-02:** The public URL redirects to the current active Stripe link when one exists. Otherwise it shows a simple, login-free information page: "paid", "cancelled" or "link is being prepared, try again shortly". The page shows the document number only, never amounts or other details.
- **D-03:** The e-mail always carries the stable URL; it never waits for the Stripe link job. If Stripe fails, the client sees the information page, Admin is alerted and can recreate the link (PA-01).
- **D-04:** In the invoice detail (Admin and Partner) the button is a header action; the Partner button uses the free slot in front of the download action in `ViewPartnerInvoice::getHeaderActions()`. It is hidden once the document is paid or cancelled and whenever no active link exists. This supersedes the Phase 10 hand-over note that the PDF and e-mail stay free of the button.

### Link lifecycle (PA-01, PA-03, PA-04)
- **D-05:** A payment link is created for invoices and proformas, never for credit notes. A payment on a proforma follows the Phase 10 rules (stored on the proforma, carried to the invoice on conversion).
- **D-06:** Creation conditions are an AND of three switches: the global `online_payments_enabled` setting, the client's online-payment flag, and a per-document flag. A draft takes the client's value, Admin can override it, and it is frozen at issue; later Admin can only deactivate or regenerate the link manually.
- **D-07:** The link is created for the remaining amount of the document (total minus recorded payments). A regenerated link is also created for the remaining amount, and the matching check compares against the amount the link offered.
- **D-08:** A partial manual payment on a document with an active link deactivates that link (its amount is no longer valid); Admin gets a "Regenerate link" action for the remaining amount. A link with a wrong amount never stays active. Full payment by any route and full storno deactivate the link; regeneration deactivates the old link first.
- **D-09:** The queued link job is idempotent, and a Stripe failure never blocks or rolls back issuing; Admin is alerted and sees the failure on the document.

### Matching and exceptions (PA-06, PA-07, PA-08)
- **D-10:** A matching payment (invoice id from the link metadata, the link id as fallback, same amount and currency as the link offered) creates the payment in `invoice_payments`, marks the document paid, creates the income via `RecordPayment` rules, deactivates the link and notifies Admin.
- **D-11:** A payment with a correct document id but a different amount or currency is stored as unmatched with the reason (amount / currency). It creates no income and changes nothing on the document; Admin is alerted and resolves it manually.
- **D-12:** A second payment of an already paid document is stored as a duplicate with no income and no document change, and alerts Admin. A repeated webhook event is ignored. Idempotence is enforced by DB unique constraints (event id; payment intent / checkout reference), not only by application checks.
- **D-13:** Admin payments overview with duplicate and unmatched filters offers these resolutions: assign to a document (writes `invoice_payments` and income through `RecordPayment` rules); mark as resolved with a note (for duplicates and refunds settled in the Stripe dashboard); record a money return (a manually entered expense linked to the duplicate payment, no Stripe API call and no refund automation, consistent with Phase 10 D-16).
- **D-14:** A payment matched from Stripe can be deleted by Admin through the same `DeletePayment` path as a manual payment (deleting its income too). The unique event id and payment reference remain stored, so replaying or redelivering the same event never recreates a deleted payment. Refund events are recorded and alerted (PA-08) without changing the document. — **Reversibility:** costly — the unique-event design must keep the deleted payment's reference reserved, otherwise a Stripe redelivery silently re-books the money.

### Webhook, configuration and testing (PA-05)
- **D-15:** The webhook endpoint verifies the Stripe signature, stores the event under a unique event id, answers immediately and processes it in a job; a bad signature is rejected. It builds on `spatie/laravel-webhook-client` and `WebhookCall`.
- **D-16:** Stripe secrets (secret key, webhook signing secret) live only in environment variables read through config, never in the database or the settings UI. The System page reports only whether they are configured.
- **D-17:** Processed webhook events are pruned after 90 days by a scheduled command; failed or unprocessed events are never pruned and are shown on the System page ("unprocessed webhooks"). Payment records do not depend on the event row and are kept.
- **D-18:** Automated tests use a fake Stripe client behind a thin interface (create / deactivate link) and sign webhook payloads with a random test secret; CI and the test suite never call Stripe. In development a Stripe tool is installed for manual verification (for example forwarding real test-mode events); it is optional and not required by any test. Whether to use the Stripe PHP SDK or direct HTTP is left to the planner.

### Claude's Discretion
Class, file, table and index names; the public token format and expiry behaviour; which exact payment statuses and reasons exist; how Admin is alerted (database notifications, mail, both) as long as every failure, duplicate, unmatched payment and refund is surfaced; the layout of the payments overview and the dashboard tile; Stripe API version pinning; the exact scheduling of the pruning command.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 11 — goal and 5 success criteria; mapping notes for the dashboard "unresolved payments" tile and System page "unprocessed webhooks" indicator
- `.planning/REQUIREMENTS.md` — PA-01..PA-08, FI-03, IN-05; out of scope: Stripe refunds, disputes and fees in the app
- `.planning/PROJECT.md` — constraints (immutability, concurrency, Partner isolation, money, repository hygiene)

### Prior decisions
- `.planning/phases/10-invoicing/10-CONTEXT.md` — D-13 (immutability), D-16 (storno does not touch payments), D-17..D-19 (payments, income, proforma carry-over, `DeletePayment`), D-21..D-23 (PDF from snapshots, synchronous), D-22 (e-mail job)
- `.planning/phases/10-invoicing/10-26-SUMMARY.md` — free header slot for the pay button in `ViewPartnerInvoice`
- `.planning/phases/10-invoicing/10-41-SUMMARY.md` — `DeletePayment` and the open question about Stripe payments (settled by D-14)
- `CONTRIBUTING.md` — the Phase 11 hand-over bullet (matching, `invoice_payments`, `RecordPayment`, `DeletePayment`); it is removed by the Phase 11 closing plan
- `.planning/codebase/INTEGRATIONS.md` — Stripe planned, `spatie/laravel-webhook-client` installed but no route

### External
- Stripe Payment Links and webhook signature verification documentation (to be fetched by the researcher)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Settings/Settings/PaymentSettings.php`: global `online_payments_enabled` toggle.
- `app/Domain/Clients/` (`Client` model, `ClientInput`, `CreateClient`, `ClientResource`): the existing client online-payment flag.
- `app/Domain/Shared/Models/WebhookCall.php` and `spatie/laravel-webhook-client`: webhook storage, no endpoint yet.
- `app/Domain/Invoicing/` (`RecordPayment`, `DeletePayment`, `invoice_payments`, `InvoicePageDecoration`, PDF and e-mail job): payment recording, correction and the document outputs that get the button.
- `app/Domain/Finance/` (`TransactionCzk`, system income category) and `database/migrations/2026_10_17_000400_link_transactions_to_invoices.php`: income linked to invoices.
- `KokpitJob` / `Idempotent`, the System page health slots, the Phase 8 dashboard tiles.

### Established Patterns
- Fail-closed `#[AccessRule]`, `#[DeniesPartners]`, `#[NotPartnerScoped]`; Partner resources under `app/Filament/Partner/`; domain logic in `app/Domain/<Area>/` with Actions, Models, Policies.
- DB-enforced constraints, unique and partial indexes, guard triggers; Czech text in `lang/cs`; activity log attribute allowlist.
- No secrets in git: config only through environment variables.

### Integration Points
- Issue flow (link job dispatched after commit), invoice detail (Admin and Partner), invoice PDF and e-mail templates, client form, document form (per-document flag), System page (unprocessed webhooks, Stripe configured), dashboard (unresolved payments tile), scheduler (pruning), a new public route for the stable pay URL, the webhook route.

</code_context>

<specifics>
## Specific Ideas

- The printed document carries a link that never changes; the Stripe link behind it can be replaced.
- A wrong-amount link must never stay active.
- Money is never lost silently: every duplicate, mismatch, unmatched payment and refund ends up in the overview and alerts Admin.

</specifics>

<deferred>
## Deferred Ideas

- Stripe refunds, disputes and fee tracking inside the app — v2 (PAY-01); handled in the Stripe dashboard.
- Bank statement matching — v2 (PAY-02).
- Scheduled payment reminders — v2 (BIL-03).

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" — matched on keywords only; unrelated to payments.

</deferred>

---

*Phase: 11-Stripe Payments*
*Context gathered: 2026-10-18*
