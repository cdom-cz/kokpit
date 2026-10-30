# Phase 13: Unified e-mail layout and HTML e-mail templates - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-22
**Phase:** 13-unified-e-mail-layout-and-html-e-mail-templates
**Areas discussed:** Invoice e-mail text in layout, Shared layout, Payment e-mails, Review of other e-mails

---

## Scope correction

The first gray-area list included in-app (database) notifications. The user pointed out that this phase concerns e-mails only. The list was narrowed to classes that really send mail: invoice, partner invitation, password reset, task notifications, operational alerts.

---

## Invoice e-mail: text in layout

| Option | Description | Selected |
|--------|-------------|----------|
| Text in layout | Plain text escaped and placed in the HTML layout | ✓ |
| Fixed template | HTML template with variables, little or no editing | |
| Rich text editor | Needs an HTML sanitiser | |

**User's choice:** Text in layout

---

## Shared layout

| Option | Description | Selected |
|--------|-------------|----------|
| Laravel mail theme | Published `vendor/mail`, custom theme | ✓ |
| Custom Blade layout | Own layout component | |

**User's choice:** Laravel mail theme. Header/footer content: supplier name, logo, supplier details in the footer (all three).

---

## Payment e-mails

| Option | Description | Selected |
|--------|-------------|----------|
| Client after payment | Confirmation to the client's invoice e-mail, card and manual | ✓ |
| Client and Admin | Also an Admin e-mail | |
| Card only | Only automatically matched payments | |

**User's choice:** Client after payment; content without attachment (invoice number, amount, date, remaining amount).

---

## Review of other e-mails

| Option | Description | Selected |
|--------|-------------|----------|
| Layout + text review | Move to the layout and check subjects, texts, CZ/EN | ✓ |
| Layout only | Texts unchanged | |

**User's choice:** Layout + text review; operational alerts use the same layout.

---

## Claude's Discretion

Theme styling, logo delivery to mail clients, exact trigger point and de-duplication of the payment confirmation.

## Deferred Ideas

None.
