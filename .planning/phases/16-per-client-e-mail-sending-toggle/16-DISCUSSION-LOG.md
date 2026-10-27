# Phase 16: Per-client e-mail sending toggle - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-27
**Phase:** 16-per-client-e-mail-sending-toggle
**Areas discussed:** Scope of the ban, Locking documents, UI behaviour and enforcement, Toggle changed at runtime

---

## Scope of the ban

| Option | Description | Selected |
|--------|-------------|----------|
| Invoices and related documents | Document e-mail and payment confirmation with PPD; Partner system e-mails stay | ✓ |
| Everything towards the client | Also invitation, password reset, task notifications | |
| Invoices + task notifications | Security e-mails stay allowed | |

**User's choice:** Invoices and related documents.

---

## Locking documents

| Option | Description | Selected |
|--------|-------------|----------|
| Nothing, stays editable | Document of the client is never locked | |
| Manual "handed over" action | Admin marks as handed over and thereby locks | |
| Lock on issue | Locked at issue like a credit note | |

**User's choice (free text):** Wants a way to lock a document manually in this phase (invoice, PPD, etc.).

### Manual lock details

| Option | Description | Selected |
|--------|-------------|----------|
| All clients, no reason | Any issued unlocked document; audited; unlock keeps its reason | ✓ |
| Only clients with sending disabled | | |
| All clients, mandatory reason | | |

**User's choice:** All clients, no reason.

---

## UI behaviour and enforcement

| Option | Description | Selected |
|--------|-------------|----------|
| Action hidden, note in detail | | ✓ |
| Action disabled with tooltip | | |
| Allow with warning | | |

**User's choice:** Action hidden, gray note in the detail.

| Option | Description | Selected |
|--------|-------------|----------|
| Client form + badge in detail | | ✓ |
| Client form only | | |
| Form + list column | | |

**User's choice:** Client form and badge in the detail.

| Option | Description | Selected |
|--------|-------------|----------|
| QueueInvoiceEmail refuses, job re-checks | | ✓ |
| Check in Action only | | |

**User's choice:** Action refuses, job re-checks before sending.

---

## Toggle changed at runtime

| Option | Description | Selected |
|--------|-------------|----------|
| Do not send, mark skipped | Visible in history | ✓ |
| Finish what is queued | | |

**User's choice:** Do not send, mark skipped.

## Claude's Discretion

Names, whether `invoice_emails` gets a `skipped` state, wording, placement of the lock action, audit representation of the manual lock.

## Deferred Ideas

- Toggle for system e-mails to Partner users.
- Reason or handed-over date for the manual lock.
