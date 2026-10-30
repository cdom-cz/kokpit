# Phase 10: Invoicing - Discussion Log

> Audit trail only. Do not use as input to planning, research, or execution agents.
> Decisions are captured in 10-CONTEXT.md.

**Date:** 2026-10-16
**Phase:** 10-Invoicing
**Areas discussed:** Draft from time and items, Issue/numbering/snapshots, Proforma/credit note/cancellation, Payments/overdue/income, PDF and e-mail

---

## Draft from time and items

| Question | Options | Selected |
|----------|---------|----------|
| Grouping of time into items | Chosen per draft / always per task / always per project | Chosen per draft |
| When time is reserved | At draft creation / at issue | At draft creation |
| Editing a time item | Detach from calculation / lock / restore button | Owner: do not link entries to items; entries link to the invoice, items are generated from them and freely editable |
| Fixed-price projects | Manual amount at draft / automatic full price | Manual amount at draft |
| Releasing time from a draft | "Unlink entries" action / only by cancelling | "Unlink entries" action |
| Entries added later | Attach and generate new items / only at creation | Attach, generate new items, never overwrite edits |
| Scope | One client + currency | Owner: one client, but invoice currency may differ from the tracked rates |
| Manual items | Description, quantity, unit, unit price | Description, quantity, unit, unit price |
| Currency conversion | CNB at issue / CNB at draft / manual | CNB rate at issue date |
| Source of invoice currency | Client currency, overridable / always client | Client currency, overridable in draft |

## Issue, numbering, snapshots

| Question | Selected |
|----------|----------|
| Dates | Issue = today, supply and due pre-filled, editable in draft |
| Failure after number allocation | Whole issue in one DB transaction, number returned |
| Snapshot contents | Items, rates, CNB rate, CZK total, language (plus supplier, customer, bank) |
| Immutability | DB guard triggers like time_entries; only payment state and internal note writable |

## Proforma, credit note, cancellation

| Question | Selected |
|----------|----------|
| Storno vs credit note | Storno = full credit note; partial credit note allowed; only full storno releases time |
| Proforma to invoice | "Issue invoice" action on paid proforma, copied items, link |
| Time of a proforma | Moves from proforma to invoice |
| Paid invoice cancelled | Payment stays, Admin returns money and records expense manually |

## Payments, overdue, income

| Question | Selected |
|----------|----------|
| Partial payments / warning | Not answered; owner raised income rule instead. Defaults applied: several payments, confirmable warning |
| Income source | Owner: income only from invoices, not proforma; confirmed OK, with an attention item for paid proforma without invoice |
| Overdue | Derived from date and payments; overview plus dashboard tile |
| Income transaction | Date = payment date, system income category, both editable at entry |

## PDF and e-mail

| Question | Selected |
|----------|----------|
| Sending | Dialog after issue with pre-filled recipient and work report option, queued |
| PDF generation | Synchronous at issue; failure rolls back the issue |
| Design | Owner: take design from github.com/cdom-cz/tools invoice PDF, brand colour #ff6f00 |

## Claude's Discretion

Naming, status modelling, VAT-ready columns, e-mail wording, number pattern defaults, variable symbol, font source, Partner invoice view and client detail panels (not discussed).

## Deferred Ideas

Stripe link and webhook (Phase 11), VAT-payer logic, automatic refund on storno.
