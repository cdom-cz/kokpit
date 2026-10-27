# Phase 15: Cash payment method for invoices and cash receipt documents - Context

**Gathered:** 2026-10-27
**Status:** Ready for planning

<domain>
## Phase Boundary

An invoice or proforma can be given cash as its payment method (a third value next to `transfer` and `online`). A cash receipt document (Czech: pokladni prijmovy doklad, PPD) is issued automatically for every cash payment that is recorded, with its own gap-free number series, and is delivered with the payment confirmation e-mail. Research must first establish what a PPD is legally (required content, numbering, VAT relevance, who may issue it); the decisions below fix the product behaviour around it and do not replace that research.

Not in this phase: new payment providers, a cash register / cash book module, reports on cash, refunds, VAT calculations beyond what the research shows the PPD must carry.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217 (Phase 2 D-09); a document stays editable until a successful e-mail send locks it, the Admin may unlock with a recorded reason (Phase 10.1); payment method on a document is an exclusive column with open-column rules, a card switch and a Stripe link lifecycle (Phase 14 D-01..D-11, OA-1..OA-7); recorded payments (`invoice_payments`, `RecordPayment`, `DeletePayment`) already carry `PaymentMethod::Cash` and book the income in one transaction (Phase 10 D-17..D-19); number sequences go through `SequenceAllocator` and are gap-free (Phase 3).

### Cash as a payment method
- **D-01:** `DocumentPaymentMethod` gets a third case `Cash` next to `Transfer` and `Online`; the `invoices.payment_method` column and its CHECK constraint are extended, not a new column. A credit note still carries no method. — **Reversibility:** costly — the column, the CHECK, the PDF, e-mail and detail templates and the Stripe link logic all branch on it.
- **D-02:** A document with method `Cash` hides all payment details: no bank box, no bank account, no QR, no variable symbol, no "Pay by card" button, no bank snapshot (`bank_snapshot` NULL, like `online` under Phase 14 OA-7). It prints only the amount, the due date and what is needed to identify the document. (Owner, explicit: "hide the payment details on a cash invoice".)
- **D-03:** `Cash` excludes card payment: the card switch is off and locked, no Stripe link is created, and the public pay URL shows the existing information page. This differs from `Transfer`, which can still be paid by card.
- **D-04:** `Cash` is chosen and changed like the other methods (Phase 14 D-09): in the draft and, while the document is open, on an issued invoice or proforma until a successful e-mail send locks it; afterwards only after an Admin unlock. Changing to `Cash` deactivates any active Stripe link (Phase 14 D-10). The default of a new draft stays `Transfer`.
- **D-05:** Currency stays independent of the method, so a cash document may have any currency. No bank account is chosen or required for `Cash`.

### When a cash receipt document (PPD) is issued
- **D-06:** A PPD is issued automatically, in the same transaction as the payment and the income, for every recorded payment whose payment method is cash (`PaymentMethod::Cash`). It follows the method of the payment, not the method of the document, so a transfer invoice paid in cash also gets a PPD, and so does a proforma. No cash payment is left without a PPD.
- **D-07:** One PPD per payment (partial payments each get their own, for the amount of that payment). When a proforma is converted to an invoice, its payments and PPDs are carried by reference and no new PPD is issued (the existing `RecordPayment::carry()` rule: no second confirmation, no second document).
- **D-08:** A payment that has a PPD cannot be deleted through `DeletePayment` directly. The Admin first cancels the PPD (storno) with a recorded reason; the number is never reused and never returned. Only then can the payment be deleted.

### Numbering and immutability
- **D-09:** The PPD has its own number series and pattern: a new `DocumentKind::CashReceipt` with a pattern setting in `NumberingSettings` (default pattern chosen by the planner, e.g. `PPD{YYYY}{NNNN}`), allocated by `SequenceAllocator` so it is gap-free and duplicate-free under concurrent creation. It carries no variable symbol and no QR, so `carriesVariableSymbol()` is false for it. — **Reversibility:** one-way — issued numbers and the settings migration cannot be undone.
- **D-10:** A PPD follows the invoice rules of Phase 10.1: editable until a successful e-mail send locks it, unlockable by the Admin with a recorded reason, with number and kind frozen. Editable fields are the receipt date, the purpose text and the supplier and customer snapshots. The amount and currency are tied to the payment and change only through the payment.

### PDF, e-mail and display
- **D-11:** The PPD PDF is generated at issue from snapshots only (supplier, customer, amount, currency, payment date, purpose), in the client's invoice language, stored in documents like invoice PDFs, and regenerated on an edit. The legally required content comes from research.
- **D-12:** The PPD PDF is attached to the payment confirmation e-mail of Phase 13 (`QueuePaymentConfirmation`); no separate e-mail and no separate send state. The confirmation e-mail is the delivery, and a successful send of it counts as the send that locks the PPD (D-10).
- **D-13:** The Admin sees the PPD in the invoice detail next to the payment (PDF download, cancel action, edit while open). The Partner sees and downloads the PPDs of their own invoices only, read-only, never drafts or internal notes, and never time rates, enforced by Policy and scope, not only UI hiding.

### Claude's Discretion
Enum case and column names, the table shape of the PPD (own table or reuse of the invoice table with a new kind; the planner decides after research), the default numbering pattern, the migration of the CHECK constraint, the exact wording (cs/en), the layout of the PPD PDF, the form for the cancel reason, and how the cancelled state is shown.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 15 — goal (research first: what a PPD is, legal requirements, content, numbering)
- `.planning/REQUIREMENTS.md` — IN-xx invoicing, PA-xx payments and P14-xx payment method requirements
- `.planning/PROJECT.md` — constraints (immutability, gap-free numbers, Partner isolation, repository hygiene)

### Prior decisions
- `.planning/phases/14-online-payment-method-and-invoice-currency/14-CONTEXT.md` — D-01..D-11 and OA-1..OA-7 (document payment method, card switch, bank snapshot carve-out for online)
- `.planning/phases/10.1-invoices-stay-fully-editable-until-sent-with-a-manual-unlock/10.1-CONTEXT.md` — open columns, lock and unlock rules reused by the PPD
- `.planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-CONTEXT.md` — payment confirmation e-mail that carries the PPD
- `.planning/phases/11-stripe-payments/11-CONTEXT.md` — Stripe link lifecycle that cash must switch off
- `.planning/phases/10-invoicing/10-CONTEXT.md` — D-17..D-19 payments, D-21 PDF

No external specs; the legal source for the PPD is to be found by research.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Invoicing/Enums/DocumentPaymentMethod.php` — the document method enum to extend with `Cash`.
- `app/Domain/Invoicing/Enums/PaymentMethod.php` — recorded payment method, already has `Cash`.
- `app/Domain/Invoicing/Actions/RecordPayment.php` (`handle`, `recordLocked`, `carry`) and `DeletePayment.php` — where the PPD is created and where deletion is guarded.
- `app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php`, `Mail/PaymentConfirmationMail.php` — delivery of the PPD.
- `app/Domain/Settings/Numbering/DocumentKind.php`, `app/Domain/Settings/Settings/NumberingSettings.php`, `app/Domain/Shared/Sequences/SequenceAllocator.php` — numbering.
- `app/Domain/Invoicing/Pdf/InvoicePdf.php`, `InvoicePdfData.php`, `resources/views/pdf/invoice.blade.php` — PDF rendering to reuse.
- `app/Domain/Payments/Support/CardPaymentSwitch.php`, `PaymentLinks` — card switch and link deactivation.
- `app/Domain/Invoicing/Support/IssuedEditFinisher.php`, `UnlockInvoice.php`, `InvoiceLockState` — lock and unlock.

### Established Patterns
- DB-enforced constraints and guard triggers (`kokpit_guard_invoice_row`); a new column or kind needs its open/frozen list decided on purpose.
- Domain logic in Actions; Czech text in `lang/cs`; fictional data only.

### Integration Points
- Draft and issued-edit forms, invoice detail (Admin and Partner), invoice PDF, payment confirmation e-mail, Stripe link job, numbering settings page, documents storage, activity log allowlist.

</code_context>

<specifics>
## Specific Ideas

- A cash invoice hides all payment details (owner's explicit instruction).
- Every cash payment gets a receipt document automatically; nothing relies on the Admin remembering.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" — matched on keywords only; unrelated to cash payments.

</deferred>

---

*Phase: 15-Cash payment method for invoices and cash receipt documents*
*Context gathered: 2026-10-27*
