# Phase 10: Invoicing - Context

**Gathered:** 2026-10-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin turns unbilled time into an issued, immutable, payable invoice in one pass and follows it through payment; Partner sees their own issued documents. Delivers IN-01..IN-10, CL-03 and FI-03: bill-from-time drafts, numbered immutable invoices, proformas and credit notes, PDF with SPAYD QR, e-mail, manual payment with income transaction, derived overdue status, and the Partner read-only invoice view plus the client detail panels.

Not in this phase: Stripe Payment Links, webhooks and automatic matching (Phase 11; the "pay button" and online-payment fields are prepared only), VAT-payer calculations and template (data model prepared, logic deferred), refunds and bank statement matching, the Partner-audit matrix (Phase 12).

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): Dompdf 3.1 is the PDF engine (Phase 3 spike); money is bigint minor units plus ISO 4217, `HALF_UP` rounding in exactly one method, once per invoice line amount (Phase 2 D-09); gap-free numbering via `SequenceAllocator` with scope key such as `invoice:2026` (Phase 2 D-12) and editable number patterns per document kind (Phase 3 D-05); one bank account per currency (Phase 3 D-04); client currency, payment terms and invoice language are stored on the client (Phase 4 D-13); time billing state `billing_state` with a DB guard trigger (Phase 6); `ExchangeRateConverter` with CNB rates (Phase 8); work report PDF without prices (Phase 8 D-10); `documents` table, shared "Soubory" relation manager, confirmation for deleting issued invoice PDFs deferred to this phase, system income category (Phase 9 D-01, D-09, D-16); Partner never sees time, rates, prices-of-time or finance beyond own issued documents, enforced by Policies and scopes.

### Draft from time and items (IN-02, IN-10)
- **D-01:** A draft belongs to exactly one client; the invoice currency defaults to the client's currency and Admin can override it in the draft. The bank account is picked by the invoice currency.
- **D-02:** When creating a draft from time, Admin chooses client, period, projects and the grouping, either per project or per task (chosen per draft). One time item = one group, hours from exact seconds.
- **D-03:** Time entries are linked directly to the invoice (draft), **not** to individual invoice items. Items are generated from the linked entries once and are then freely editable (quantity, unit, price, text, delete, add); editing or deleting an item never changes which entries are linked. — **Reversibility:** costly — the schema, the billing lock and the report "unbilled" queries all depend on the entry-to-invoice link instead of an entry-to-item link.
- **D-04:** Entries are reserved the moment the draft is created: the entry carries the draft's id (unique in the DB, so two drafts cannot hold the same entry), and the picker for other drafts excludes reserved entries. `billing_state = billed` and the existing billing lock apply at issue (storno and cancel release them). Exactly how reserved-but-unbilled entries are counted in the Phase 6/8 unbilled views is the planner's call but must be explicit and tested.
- **D-05:** Admin releases entries from a draft with an "unlink entries from invoice" action (select entries, they return to unbilled). Deleting or cancelling a whole draft releases all entries (IN-10).
- **D-06:** Entries added to a draft later (newly tracked time) are linked and generate new items only; items edited by hand are never overwritten.
- **D-07:** Fixed-price projects get no per-entry amount (Phase 8 D-07): Admin adds a "fixed amount" item manually (whole price or a part) while creating the draft; optionally marks the project's time as billed with it.
- **D-08:** Manual items are lines with description, quantity, unit and unit price. In non-VAT-payer mode (the default) no VAT is shown; the item has a VAT-rate field in the model that is unused in v1 (IN-08).
- **D-09:** When the entry's rate currency differs from the invoice currency, the unit price is converted with the CNB rate valid on the issue date, rounded once per line (`HALF_UP`), and the rate is stored in the snapshot. The draft shows a preview using the current rate; the final value is computed at issue. — **Reversibility:** one-way — issued amounts and the stored rate snapshot cannot be recomputed later.

### Issue, numbering and snapshots (IN-01, IN-03, IN-07, IN-08)
- **D-10:** Invoice, proforma and credit note have separate series and the number is allocated only at issue, inside the same DB transaction as the snapshots and the billing lock; if anything fails the number is not consumed (series stays gap-free). PDF generation happens in the same flow (see D-24); e-mail goes to the queue after commit.
- **D-11:** Issue date defaults to today (Europe/Prague), taxable-supply date and due date are pre-filled (due = issue date plus the client's payment terms), editable in the draft, frozen at issue.
- **D-12:** The issue snapshot freezes supplier, customer and bank account, all items as text copies, rates, line and total amounts, invoice language, the CNB rate with date and the CZK total for client-currency invoices.
- **D-13:** Immutability after issue is enforced by DB guard triggers (the pattern of `Immutability::guardTriggerSql` used for `time_entries`) on invoices, items and snapshot data; only the payment state and the internal note stay writable, also against raw SQL UPDATE and DELETE. — **Reversibility:** one-way — relaxing a guard on issued financial data would need a migration and breaks the immutability promise.

### Proforma, credit note, cancellation (IN-06, IN-10)
- **D-14:** An issued invoice is never edited or deleted. "Storno" issues a full credit note (own series) and marks the invoice cancelled; a partial credit note on chosen items is also allowed and keeps the time billed. Only a full storno returns the linked time to unbilled and unlocked.
- **D-15:** A proforma may be built from time and reserves it like a draft. When a paid proforma becomes an invoice ("Issue invoice" action on a paid proforma), the new invoice gets its own number from the invoice series, copies the items, keeps a link to the proforma, and the time entries move from the proforma to the invoice (stay billed, never released in between).
- **D-16:** Cancelling a paid invoice by credit note does not touch the existing payment or income; the system shows a warning and Admin returns the money and records the expense manually (no refund automation).

### Payments, overdue, income (IN-05, FI-03)
- **D-17:** An invoice can have several (partial) manual payments (transfer or cash) in a `payments` table; the sum decides unpaid / partially paid / paid. Entering a payment on an already paid invoice, or above the remaining amount, shows a confirmation warning; Admin can confirm and save it anyway.
- **D-18:** Income is created from **invoices only**, never from a proforma. A payment recorded on a proforma is stored as a payment only; when the invoice is issued from that proforma the payment is carried to the invoice and the income transaction is created then, dated with the real payment date and in the system income category. A paid proforma with no invoice issued yet shows as an attention item (invoice list and dashboard) so money never sits outside the finance overview unnoticed. — **Reversibility:** costly — payment, proforma conversion and finance reports depend on the moment income is created.
- **D-19:** A manual invoice payment creates an income transaction linked to the payment and the invoice (the invoice reference on transactions, FI-01 deferred to this phase). The date defaults to the payment date and the category to the system income category, both editable when entering the payment; amount, currency and invoice link are locked afterwards (Phase 9 D-14).
- **D-20:** Overdue is derived, never stored: issued, not fully paid and due date earlier than today (Europe/Prague); partially paid invoices count by their remaining amount. There is an overdue overview and a dashboard tile (count and amount in CZK), wired into the Phase 8 dashboard.

### PDF, QR and e-mail (IN-04)
- **D-21:** The PDF is generated from the snapshots only, in the client's invoice language (cs/en), with a SPAYD QR code for CZK bank transfer, and is stored in `documents` attached to the invoice. Deleting an issued invoice PDF needs an explicit confirmation (DO-03, proved in this phase).
- **D-22:** After issue a dialog offers the e-mail with the recipient pre-filled from the client's invoice e-mail, subject and text, and a checkbox to attach the work report (Phase 8 PDF). Sending runs in a queued idempotent job; failures appear in the "unsent invoice e-mails" indicator on the System page and can be re-sent.
- **D-23:** The PDF is generated synchronously at issue; if generation fails, the whole issue is rolled back (no number consumed). Regenerating the PDF later uses the same snapshots and must produce an equivalent document.
- **D-24:** Invoice design follows the cdom tools invoice PDF (`src/tools/invoice/invoice-pdf.tsx` in the public repository `cdom-cz/tools`), re-implemented in HTML/CSS for Dompdf with brand colour `#ff6f00`: a 7 pt accent stripe at the top and bottom, header with the supplier logo on the left and the document heading (small, letter-spaced, accent text colour) with a large bold number on the right over a dark bottom rule, two party blocks side by side (supplier with an accent left border, customer with a dark one), a three-cell date strip (issue, taxable supply when VAT payer, due), an items table with a dark header row, alternating row tint, index, description, quantity with unit, unit price and total, a right-aligned summary with a solid accent total bar ("to pay"), a payment box with a tinted background and accent top border containing the QR, bank details, variable symbol and due date, the supplier footer text, an optional signature image, "page/total" numbering and a Roboto font family (Czech diacritics). Colours are derived from the single accent: a darkened accent text colour when the contrast on white is below 3.5, white or near-black text on the accent chosen by contrast, a very light accent tint for the payment box and a neutral row tint. The accent colour is a typed setting (default `#ff6f00`), not hard-coded; the fictional-data rule applies to every demo value.

### Claude's Discretion
Class, file, table and index names; how the entry-to-invoice link and the "reserved" state are modelled (column, trigger, partial unique index); which exact statuses exist (draft, issued, partially paid, paid, cancelled) and where they are stored versus derived; VAT-ready column shapes; e-mail wording in cs/en; the invoice number pattern defaults; variable symbol derivation; whether PDF font files are bundled from the public repository's Roboto or from a package (AGPL-compatible licence check); the exact Partner view of issued documents (list, detail, PDF, read-only, no drafts, internal note, linked time or rates, as in IN-09 and roadmap criterion 5) and the client detail panels (CL-03: projects, unbilled time and amount, invoices, documents, client accounts), which were not discussed and follow the existing isolation rules; presentation of the overdue overview and the "paid proforma without invoice" attention item.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 10 — goal, 5 success criteria, mapping notes (invoice reference on transactions, CL-03, FI-03, System page indicators, dashboard overdue tile)
- `.planning/REQUIREMENTS.md` — IN-01..IN-10, CL-03, FI-03, FI-01, DO-03, TI-05, TI-08, EX-03
- `.planning/PROJECT.md` — Constraints (immutability, concurrency, Partner isolation, money, time)

### Prior decisions
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — D-09 (rounding), D-12 (number sequence scope key)
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — D-02..D-05 (settings, bank accounts, numbering patterns), D-13 (System page indicators), `03-SPIKE-PDF.md`
- `.planning/phases/04-clients-and-projects/04-CONTEXT.md` — D-12, D-13 (invoice e-mail, currency, terms, language on the client)
- `.planning/phases/06-time-tracking/06-CONTEXT.md` — billed locking, rate resolution
- `.planning/phases/08-exchange-rates-and-reports/08-CONTEXT.md` — D-01, D-07, D-10 (CNB conversion, fixed price, work report)
- `.planning/phases/09-documents-and-finance/09-CONTEXT.md` — D-01, D-09, D-14, D-16 (documents, deletion confirmation, transactions, system income category)

### External
- `https://github.com/cdom-cz/tools` — `src/tools/invoice/invoice-pdf.tsx` and `src/lib/invoice.ts` (palette derivation) — visual reference for the invoice PDF (D-24); fonts in `public/fonts`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Shared/Sequences/SequenceAllocator.php` and `app/Domain/Settings/Numbering/` (`DocumentKind`, `DocumentNumbering`, `NumberPattern`): gap-free allocation and patterns for invoice, proforma and credit note.
- `app/Domain/Settings/Banking/`, `VatMode.php`: bank accounts per currency and VAT mode (non-payer default).
- `app/Domain/TimeTracking/Billing/` (`TimeEntryRateResolver`, `EntryRate`) and the `time_entries` guard trigger (`billing_state`, `billed_at`): rate resolution and billed locking.
- `app/Domain/ExchangeRates/ExchangeRateConverter.php`: CNB conversion for issue-time snapshot and CZK total.
- `app/Domain/Reports/WorkReport/`, `EntryAmounts.php`: work report PDF attachment and amount computation.
- `app/Domain/Documents/` and `app/Domain/Finance/` (Models, Actions, `TransactionCzk`): documents for the invoice PDF, transactions and the system income category.
- `KokpitJob` / `Idempotent` base for the e-mail job; existing health slots for "unsent invoice e-mails".

### Established Patterns
- Fail-closed `#[AccessRule]`, `#[DeniesPartners]`, `#[NotPartnerScoped]`; Partner resources under `app/Filament/Partner/`; domain logic in `app/Domain/<Area>/` with Actions, Models, Policies.
- DB-enforced constraints, check constraints, guard triggers and a raw-SQL test helper; money as `Money`; Czech text in `lang/cs`; activity log attribute allowlist.

### Integration Points
- Client detail (CL-03 panels), the Phase 8 dashboard (overdue tile), the System page (unsent e-mails), finance transactions (invoice reference), documents (invoice owner morph), the Phase 8/6 unbilled queries, the morph map, and the Partner panel (issued invoices and proformas).

</code_context>

<specifics>
## Specific Ideas

- Time entries belong to the invoice, not to its items; items are a freely editable projection of the linked time.
- The invoice may be in a different currency than the tracked rates; conversion uses the CNB rate at issue and is frozen.
- Income comes only from invoices; a paid proforma carries its payment into the invoice.
- The PDF looks like the owner's existing invoice tool, with brand colour `#ff6f00`.

</specifics>

<deferred>
## Deferred Ideas

- Online payment (Stripe link, pay button behaviour, webhook matching) — Phase 11; this phase only leaves the fields and placement ready.
- VAT-payer calculations and PDF template — later, data model prepared.
- Automatic refund and payment reversal on storno — out of scope (handled manually).

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" — matched on keywords only; Phase 8 gap already covered by its UI-SPEC amendment.

</deferred>

---

*Phase: 10-Invoicing*
*Context gathered: 2026-10-16*
