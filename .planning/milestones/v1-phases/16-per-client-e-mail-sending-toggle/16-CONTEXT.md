# Phase 16: Per-client e-mail sending toggle - Context

**Gathered:** 2026-10-27
**Status:** Ready for planning

<domain>
## Phase Boundary

A per-client setting that disables sending document e-mails (invoices, proformas, credit notes and the payment confirmation that carries the cash receipt) to that client, for clients served exclusively by hand. Sending stays enabled by default. Because a document is locked only by a successful e-mail send (Phase 10.1), this phase also adds a manual lock action for documents, so a document of a hand-served client can be locked without an e-mail.

Not in this phase: system e-mails to Partner users (invitation, password reset, task notifications), a "mark as handed over" workflow with its own state or reason, delivery channels other than e-mail, reports on hand-served clients.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): a document is editable until a successful e-mail send locks it, the Admin unlocks with a mandatory recorded reason (Phase 10.1, IN-11, IN-12); every lock and unlock is audited (IN-15); the payment confirmation row has a `skipped` state for a client without a usable e-mail (Phase 13); the PPD is locked by the successful send of the payment confirmation (Phase 15 D-10, D-12); the client has a nullable `invoice_email` and an invoice language.

### Scope of the ban
- **D-01:** The toggle blocks only document e-mails: the document e-mail (`InvoiceMail` via `QueueInvoiceEmail` / `SendInvoiceEmail`) and the payment confirmation e-mail with the PPD attachment (`QueuePaymentConfirmation` / `SendPaymentConfirmation`). System e-mails to Partner users of the client stay unchanged: invitation, password reset and task notifications. Default is "sending enabled" for every existing and new client.

### Manual lock of a document
- **D-02:** The Admin can lock an issued invoice, proforma or cash receipt (PPD) by hand, for every client, not only for clients with sending disabled. The action needs no reason. Locking is audited (who, when) like the lock by send (IN-15). Unlock stays as today: Admin only, mandatory reason (IN-12), the document stays editable until the next lock. — **Reversibility:** costly — the lock state machine, its database guards, the audit and the Admin UI all gain a second way into the locked state.
- **D-03:** A manual lock applies only to a document that is issued and currently unlocked; a credit note is already locked at issue and a locked document shows no lock action. The lock changes no number, kind or snapshot.

### UI behaviour
- **D-04:** For a client with sending disabled, the "Send by e-mail" and "Send again" actions are hidden (header of the document detail, row of the document list, row of the Odeslání tab), and the document detail shows a gray note that the client has e-mail sending disabled. The row of the Odeslání tab is not offered for resend either.
- **D-05:** The toggle lives in the client form next to the invoice e-mail (label along the lines of "Send documents by e-mail", default on) and in the client detail; the client detail shows a badge when it is off. A change of the toggle is audited (who, when, old and new value). No column in the client list.

### Enforcement
- **D-06:** The ban is enforced in the domain, not only in the UI. `QueueInvoiceEmail` refuses a document of a client with sending disabled (validation-style error, no log row). `SendInvoiceEmail` and `SendPaymentConfirmation` re-check the toggle right before the transport call and send nothing when it is off. `QueuePaymentConfirmation` creates the row directly in state `skipped` and dispatches no job for such a client; the payment itself is never blocked.

### Toggle changed while e-mails are queued
- **D-07:** When the toggle is switched off, an e-mail that is still queued (document e-mail or payment confirmation inside its 5-minute grace window) is not sent: the job's re-check (D-06) marks its row `skipped` and leaves it in the history, so the suppression is visible. The mark never locks a document (IN-11: only a successful send locks). Switching the toggle back on does not revive skipped rows; the Admin sends again by hand.
- **D-08:** `skipped` rows do not count as failed or unsent: the health indicator "unsent invoice e-mails" (FND-10) and the failed-e-mail alert ignore them, and a document whose latest row is `skipped` is not shown as "send failed" in lists and detail.

### Claude's Discretion
Column and enum names, whether `invoice_emails` gets a `skipped` state next to `queued/sending/sent/failed` (its CHECK constraint and the state enum must then be extended, and the sent-at / failure-code constraints reviewed) or the suppressed document e-mail is simply not logged, the migration of the audit allowlist, the exact wording (cs/en), the icon and label of the manual lock action, where on the detail page the lock action sits, and how the lock by hand is recorded in the audit trail next to the lock by send.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 16 — goal of the phase
- `.planning/REQUIREMENTS.md` — CL-01 (client fields), IN-03, IN-04, IN-11, IN-12, IN-15 (lock, send, audit), FND-10 (health indicators), P15-07, P15-09 (PPD lock and delivery)
- `.planning/PROJECT.md` — constraints (immutability, Partner isolation, repository hygiene)

### Prior decisions
- `.planning/phases/10.1-invoices-stay-fully-editable-until-sent-with-a-manual-unlock/10.1-CONTEXT.md` — lock by send, unlock with reason; the manual lock reuses its states and guards
- `.planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-CONTEXT.md` — payment confirmation e-mail, `skipped` state, grace window
- `.planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-CONTEXT.md` — PPD lock by confirmation send (D-10, D-12)

No external specs — requirements fully captured in decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Clients/Models/Client.php` and `app/Domain/Clients/Actions/ClientInput.php`, `CreateClient.php`, `database/migrations/2026_10_09_000100_create_clients_table.php` — client fields (`invoice_email`, `online_payment_enabled`); the new boolean follows `online_payment_enabled`.
- `app/Filament/Resources/ClientResource.php` — form (around line 308), detail entries (around line 360) and the data mapping (around line 558).
- `app/Domain/Invoicing/Actions/QueueInvoiceEmail.php`, `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` — document e-mail queue and send.
- `app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php`, `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php`, `PaymentConfirmationState::Skipped` — confirmation path with an existing skipped state.
- `app/Filament/Support/SendInvoiceEmailAction.php` — the three send actions to hide.
- `app/Domain/Invoicing/Actions/UnlockInvoice.php`, `UnlockCashReceipt.php`, `InvoiceLockState`, migration `2026_10_22_000100_add_lock_state_to_invoices.php` — lock state and unlock to mirror for the manual lock.
- `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php` — must ignore skipped rows.

### Established Patterns
- Domain logic in Actions; Filament actions only map errors; Czech text in `lang/cs`.
- Database guard triggers (`kokpit_guard_invoice_row`) define which columns are open or frozen; a manual lock path must be allowed through them on purpose.
- State changes of a document are audited through the activity log allowlist.

### Integration Points
- Client form and detail, invoice and PPD detail (Admin), document list rows, Odeslání tab, queue jobs, health indicator, activity log allowlist, lang files.

</code_context>

<specifics>
## Specific Ideas

- Manual lock is explicitly wanted in this phase, for invoices, proformas and PPDs ("manually lock a document, an invoice, a PPD, etc.").
- Hidden actions plus a gray note, not disabled buttons.

</specifics>

<deferred>
## Deferred Ideas

- Blocking system e-mails to Partner users of a hand-served client (invitation, password reset, task notifications): kept out of scope by D-01; a later phase can add a second toggle if needed.
- A required reason or a "handed over on date" record for the manual lock: not wanted now (D-02).

</deferred>

---

*Phase: 16-Per-client e-mail sending toggle*
*Context gathered: 2026-10-27*
