# Phase 11: Stripe Payments - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-18
**Phase:** 11-Stripe Payments
**Areas discussed:** Pay button in PDF and e-mail, Link lifecycle, Matching and exceptions, Webhook and configuration

---

## Pay button in PDF and e-mail

| Option | Description | Selected |
|--------|-------------|----------|
| Stable Kokpit URL | PDF and e-mail carry a public signed Kokpit URL that redirects to the current Stripe link | ✓ |
| Stripe URL directly in the PDF | PDF regenerated after link creation; breaks D-23 and dead links after regeneration | |
| Detail and e-mail only | No button in the PDF, deviates from PA-02 | |

**User's choice:** Stable Kokpit URL.

| Option | Description | Selected |
|--------|-------------|----------|
| Information page | Login-free page: paid / cancelled / link being prepared | ✓ |
| Redirect or 404 only | Minimal code, client does not know why | |
| Redirect to Partner login | Works only for clients with an account | |

**User's choice:** Information page.

| Option | Description | Selected |
|--------|-------------|----------|
| E-mail always carries the stable URL | Does not wait for Stripe | ✓ |
| E-mail waits for the link | Retry until the link exists, delays e-mail | |

**User's choice:** E-mail always carries the stable URL.

## Link lifecycle

| Option | Description | Selected |
|--------|-------------|----------|
| Invoice and proforma | Card payment for proformas too | ✓ |
| Invoice only | Proforma paid by transfer only | |

**User's choice:** Invoice and proforma.

| Option | Description | Selected |
|--------|-------------|----------|
| Link for the remaining amount | Regeneration uses the remaining amount | ✓ |
| Always the full amount | Partial payment invalidates the link | |

**User's choice:** Remaining amount.

| Option | Description | Selected |
|--------|-------------|----------|
| Deactivate, offer regeneration | No link with a wrong amount stays active | ✓ |
| Regenerate automatically | More automation around money | |

**User's choice:** Deactivate, offer regeneration.

| Option | Description | Selected |
|--------|-------------|----------|
| Global AND client AND document | Three switches, frozen at issue | ✓ |
| Client and document only | Ignores the global toggle | |

**User's choice:** Global AND client AND document.

## Matching and exceptions

| Option | Description | Selected |
|--------|-------------|----------|
| Unmatched, manual resolution | Wrong amount or currency stored with reason, no income | ✓ |
| Accept a partial payment | More code, deviates from PA-06 | |

**User's choice:** Unmatched, manual resolution.

| Option | Description | Selected |
|--------|-------------|----------|
| Assign to a document | Via `RecordPayment` rules | ✓ |
| Mark as resolved with a note | For duplicates and refunds settled in Stripe | ✓ |
| Record a money return | Manual expense, no Stripe API call | ✓ |

**User's choice:** All three.
**Notes:** The money return is a manual expense only; no refund automation, consistent with Phase 10 D-16.

| Option | Description | Selected |
|--------|-------------|----------|
| Stripe payment not deletable | Correction only via return or reassignment | |
| Same `DeletePayment` rule as manual | Unique event id stays reserved | ✓ |

**User's choice:** Same rule as manual payments.
**Notes:** Claude's recommendation was non-deletable; the user chose the manual-payment rule. The unique event and payment reference must stay stored so a redelivery cannot re-book the money (recorded as D-14).

## Webhook and configuration

| Option | Description | Selected |
|--------|-------------|----------|
| Environment variables only | Never in DB or UI | ✓ |
| Env plus encrypted in settings | More attack surface | |

**User's choice:** Environment variables only.

| Option | Description | Selected |
|--------|-------------|----------|
| Fake client behind an interface | Signed test payloads, CI never calls Stripe | ✓ |
| Stripe test mode in CI | Needs CI secret and network | |

**User's choice:** Fake client behind an interface.
**Notes:** The user added that a Stripe tool is installed for development; automated tests work without it.

| Option | Description | Selected |
|--------|-------------|----------|
| 90 days, unprocessed never | Scheduled pruning of processed events | ✓ |
| 30 days | Smaller DB, less history | |
| Configurable | Extra setting without clear need | |

**User's choice:** 90 days, unprocessed events never pruned.

## Claude's Discretion

- Class, table and index names; public token format; payment statuses and reasons; Admin alert channel; payments overview and dashboard tile layout; Stripe API pinning; Stripe SDK versus direct HTTP; pruning schedule.

## Deferred Ideas

- Refunds, disputes and fees in the app (PAY-01), bank statement matching (PAY-02), payment reminders (BIL-03).
