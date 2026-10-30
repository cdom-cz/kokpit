# Phase 14: Switchable payment method and independent invoice currency - Context

**Gathered:** 2026-10-23
**Status:** Ready for planning

<domain>
## Phase Boundary

The invoice currency, the payment method (bank transfer or online) and the bank account become three independent choices on a draft and on an open issued invoice or proforma. The currency carries through to the PDF and the Stripe payment. A transfer document prints the bank payment details; an online document prints no bank details, only what is needed to pay via Stripe. When the chosen currency matches a configured bank account, that account is preselected; the account can also be chosen separately.

Not in this phase: new payment providers, refunds, bank statement matching, VAT calculations, new e-mail layouts (Phase 13).

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217 (Phase 2 D-09); the bank account is snapshotted into `invoices.bank_snapshot` at issue and a document that carries a payment must have it (Phase 10 D-12, `invoices_issued_fields_check`; carved out for `online` documents by OA-7 below); a document stays editable until a successful e-mail send locks it, the Admin may unlock with a recorded reason, and currency and `bank_snapshot` are already open columns (Phase 10.1); the Stripe link uses a stable public pay URL, is created for the remaining amount in a queued idempotent job, and a link with a wrong amount never stays active (Phase 11 D-01..D-09); conversion to another currency uses the CNB rate valid on the issue date and freezes it in the snapshot (Phase 10 D-09); `invoice_payments` has a composite FK `(invoice_id, currency)`.

### Payment method
- **D-01:** The payment method is an exclusive per-document choice `transfer` | `online`, stored as a new column (replacing the implicit "card switch only" model for what the document prints). `transfer` prints the bank box, variable symbol and (where supported) the QR; `online` prints no bank details, no QR and no variable-symbol/bank rows, only the pay button/link and the facts needed to pay via Stripe. — **Reversibility:** costly — the column and the PDF/e-mail/detail templates all depend on it.
- **D-02:** The default of a new draft is always `transfer`, whatever the client flag or the global setting says. `online` is chosen by hand in the draft. A document of kind credit note carries no payment method.
- **D-03:** A `transfer` document may also be paid by card: it keeps the existing card-button behaviour driven by the Phase 11 card switch (global `online_payments_enabled` AND Stripe configured AND the per-document card switch, whose default still follows the client's `online_payment_enabled` flag). So a transfer invoice can show the bank details and the "Pay by card" button together, as today. Choosing `online` forces the card switch on and requires the card payment to be available (global setting on, Stripe configured, client flag on). The client flag therefore stays as the client-level permission/default for the card switch; it no longer sets the payment method. — *Assumption derived from the owner's two clarifications ("default is transfer, online can be chosen" and "a transfer payment can also be paid online"); RESOLVED by OA-1 below (switch kept and editable while the document is open).*

### Bank accounts
- **D-04:** More than one bank account per currency is allowed: the `UniqueCurrencies` rule on `BankAccountSettings` is dropped (this overrides Phase 3 D-04 "one account per currency"). Each account needs a stable identifier (not its list position) so a draft can reference it; the planner chooses the identifier and the settings migration for existing data.
- **D-05:** The draft stores the chosen account separately from the currency. When the currency is set or changed, the first configured account of that currency is preselected (order of the settings list); the Admin may pick any other account at any time, including one in a different currency. If no account matches the currency, no account is preselected and a `transfer` document cannot be issued until one is chosen; an `online` document needs no account.
- **D-06:** An account whose currency differs from the document's currency is allowed with no warning. The QR is printed only when `Spayd::supports(currency, iban)` holds (today CZK with a matching IBAN); otherwise the document prints the bank details without a QR. The bank snapshot is taken from the chosen account at issue.

### Invoice currency
- **D-07:** The currency selectable in a draft is the union of: currencies for which CNB rates are available, the currencies of all configured bank accounts, and the client's currency. It no longer depends on the account or the payment method. Conversion of rates in another currency follows Phase 10 D-09.
- **D-08:** The currency of a document is carried unchanged to the PDF (amounts, QR/SPAYD decision) and to the Stripe link. Stripe-unsupported currencies are not pre-checked: the link job fails through the existing Phase 11 D-09 path (Admin alerted, issuing never blocked).

### Changes after issue
- **D-09:** Payment method, bank account and currency are open columns of an issued document, like currency and `bank_snapshot` since Phase 10.1: changeable until a successful e-mail send locks the document; afterwards only after an Admin unlock with a recorded reason. Number, kind and variable symbol stay frozen.
- **D-10:** Changing the payment method or the currency of an open issued document deactivates any active Stripe link first. If the resulting state offers card payment (method `online`, or `transfer` with the card switch on), a new link is created for the remaining amount in the new currency by the queued job. The public pay URL stays the same; when no card payment is offered the existing information page is shown.
- **D-11:** The currency cannot be changed while the document has a recorded payment (composite FK on `invoice_payments`, Phase 10 D-17..D-19); the Admin deletes the payment through `DeletePayment` first. Payment method and account may still change with a payment recorded.

### Claude's Discretion
Column and enum names, the account identifier format and the settings migration, the exact draft/edit form layout and wording (cs/en), validation message text, the order of the currency list, how the CNB currency list is obtained for the dropdown, and how `online` is shown on the Partner invoice detail.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 14 — goal
- `.planning/REQUIREMENTS.md` — IN-xx invoicing and PA-01..PA-08 payment requirements
- `.planning/PROJECT.md` — constraints (immutability, Partner isolation, money, repository hygiene)

### Prior decisions
- `.planning/phases/10-invoicing/10-CONTEXT.md` — D-01 (currency and bank by invoice currency, now superseded for the account), D-09 (CNB conversion), D-12 (issue snapshot), D-17..D-19 (payments), D-21 (PDF, SPAYD QR)
- `.planning/phases/10.1-invoices-stay-fully-editable-until-sent-with-a-manual-unlock/10.1-CONTEXT.md` — open columns, lock and unlock
- `.planning/phases/11-stripe-payments/11-CONTEXT.md` — D-01..D-09 (pay URL, link lifecycle, card switch)
- `.planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-CONTEXT.md` — invoice e-mail with the "Pay by card" button
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — D-04 (one account per currency, overridden here)

No external specs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Settings/Settings/BankAccountSettings.php` (`forCurrency`, `UniqueCurrencies`, `BankAccountListCast`) and `app/Domain/Settings/Banking/BankAccount.php`: account list, currently one per currency and no identifier.
- `app/Domain/Payments/Support/CardPaymentSwitch.php` and `invoice_admin.card_payment_enabled`: the card switch and its effective value at issue.
- `app/Domain/Invoicing/Pdf/InvoicePdfData.php` (payment box, `Spayd::supports`, `PayUrl`): the PDF payment box built from `bank_snapshot`.
- `app/Filament/Support/CreateDraftAction.php::currencyOptions()` and `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php` (`bankAccountFor`, `bankAccountState`): currency list and account display in the draft/edit forms.
- `app/Domain/Invoicing/Actions/CreateDraft.php`, `UpdateDraft.php`, `app/Domain/Invoicing/Issuing/DocumentIssuer.php`, `app/Domain/Invoicing/Support/IssuedEditFinisher.php`: draft creation, update, issue and edit of an open issued document.
- `app/Domain/Invoicing/Enums/PaymentMethod.php` — that enum is for recorded payments (transfer/cash/card); the document-level choice needs its own enum.

### Established Patterns
- DB-enforced constraints and guard triggers (`kokpit_guard_invoice_row`, open-column list in migration `2026_10_22_000100_add_lock_state_to_invoices.php`); a new column on `invoices` is frozen until listed as open on purpose.
- Domain logic in Actions; Czech text in `lang/cs`; fictional data only.

### Integration Points
- Draft form, issued-edit form, invoice detail (Admin and Partner), invoice PDF and e-mail templates, Stripe link job and deactivation, settings page bank accounts, client form (card flag).

</code_context>

<specifics>
## Specific Ideas

- Default payment is always bank transfer; online is an explicit choice.
- A transfer document can still be paid by card.
- Currency, payment method and bank account never constrain one another.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" — matched on keywords only; unrelated to payments.

</deferred>

<amendments>
## Owner Amendments (planning session, 2026-10-23)

Recorded from the owner's answers given during `/gsd-plan-phase 14`. They are binding for the plans and cited there as OA-1 to OA-7.

- **OA-1 (amends Phase 11 D-06 and T-10.1-24):** The card switch is no longer frozen at issue. It is editable on `transfer` documents in the draft and in the open issued editor; it is forced on and locked for `online`; on online -> transfer it resets to the client's flag. It freezes again when the document is locked. A first pay token may therefore be set once on an open issued document.
- **OA-2:** Proforma conversion: the invoice inherits the proforma's payment method and bank account, and `ConvertProforma` calls `PaymentLinks::reconcile` after the carries.
- **OA-3:** An explicit payment-method change opens a new payment link (deactivation reason `replaced`, not `manual`).
- **OA-4:** Requirement IDs P14-01..P14-09 are registered in REQUIREMENTS.md.
- **OA-5:** Phase 13 note: `PayLinkBlock` gates on "switch AND token" after this phase.
- **OA-6:** The Filament Repeater `Hidden('id')` Livewire round-trip is covered by a Wave 0 test.
- **OA-7 (carve-out of Phase 10 D-12):** An issued `online` document carries no bank snapshot (`bank_snapshot` NULL); an issued `transfer` document must carry one. D-01 (no bank details on online documents) requires this; D-06's snapshot is taken from the chosen account for `transfer` documents only.
</amendments>


---

*Phase: 14-Switchable payment method and independent invoice currency*
*Context gathered: 2026-10-23*
