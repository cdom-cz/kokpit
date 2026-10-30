# Phase 15: Cash payment method for invoices and cash receipt documents - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-27
**Phase:** 15-cash-payment-method-for-invoices-and-cash-receipt-documents
**Areas discussed:** Cash as a payment method, When a PPD is issued, Numbering and immutability, PDF / e-mail / display

---

## Cash as a payment method

| Option | Description | Selected |
|--------|-------------|----------|
| Third value `cash` | Extend DocumentPaymentMethod next to transfer and online | ✓ |
| Flag on transfer | Two overlapping states | |
| Only at payment recording | No document-level method | |

**User's choice:** Third value `cash`.

| Option | Description | Selected |
|--------|-------------|----------|
| Cash may also offer card | Behaves like transfer | |
| Cash excludes card | Card switch off and locked, no Stripe link | ✓ |

**User's choice:** Cash excludes card.
**Notes:** Changeable like other methods (Phase 14 D-09); any currency. Mid-discussion the owner stated: hide the payment details on a cash invoice.

---

## When a PPD is issued

| Option | Description | Selected |
|--------|-------------|----------|
| Automatically on cash payment | Same transaction as payment and income | ✓ |
| Manually by button | Risk of forgotten receipts | |

| Option | Description | Selected |
|--------|-------------|----------|
| One PPD per payment | Partial payments each get one | ✓ |
| One PPD per invoice | Only after full payment | |

| Option | Description | Selected |
|--------|-------------|----------|
| Every cash payment | Follows the payment method, includes proformas | ✓ |
| Only cash-method invoices | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Payment with PPD cannot be deleted, only cancelled first | Number never reused | ✓ |
| Deleting marks PPD cancelled | | |

---

## Numbering and immutability

| Option | Description | Selected |
|--------|-------------|----------|
| Own series and pattern | New DocumentKind, SequenceAllocator | ✓ |
| Shared with invoices | | |

**Editability:** the owner first answered "always editable, like an invoice"; clarified to the Phase 10.1 rules (editable until a successful e-mail send, unlock with reason) and editable fields date, purpose and snapshots (amount and currency tied to the payment).

---

## PDF / e-mail / display

| Option | Description | Selected |
|--------|-------------|----------|
| Attachment of the payment confirmation e-mail | Also stored in documents | ✓ |
| Separate e-mail | | |
| Download only | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Admin in invoice detail, Partner only own | Read-only for Partner | ✓ |
| Admin only | | |

---

## Claude's Discretion

Names, table shape, default numbering pattern, CHECK migration, wording, PDF layout, cancel form.

## Deferred Ideas

None.
