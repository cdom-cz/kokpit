# Phase 13: Unified e-mail layout and HTML e-mail templates - Context

**Gathered:** 2026-10-22
**Status:** Ready for planning

<domain>
## Phase Boundary

Every e-mail Kokpit sends shares one HTML layout, and the e-mails related to payments (the "invoice paid" confirmation, and the invoice e-mail with its "Pay by card" button) are reworked first and foremost. The other e-mails are re-checked and moved onto the same layout. E-mails only: in-app (database) notifications are out of scope.

E-mails that really leave the system today: the invoice e-mail (`InvoiceMail`, plain text), the partner invitation (`PartnerInvitation`), the password reset (Filament `ResetPassword`), task notifications (`TaskNotification`, mail channel, per recipient preference) and operational alerts (`OperationalAlert`, mail + database). Missing today: the confirmation that an invoice was paid.

</domain>

<decisions>
## Implementation Decisions

### Invoice e-mail
- **D-01:** The invoice e-mail keeps its editable plain-text body (Phase 10 U-11). The Admin-confirmed text is escaped and placed inside the shared HTML layout (header, footer, "Pay by card" button). No HTML sanitiser and no rich text editor are introduced. The subject and the attachments stay as they are (Phase 10 D-22). — **Reversibility:** reversible — only the view and the Mailable content change.

### Shared layout
- **D-02:** The layout is built on the Laravel mail theme: publish `vendor/mail`, customise the theme, header and footer. `MailMessage`-based e-mails (invitation, password reset, tasks, alerts) pick it up directly; the invoice and payment e-mails use Markdown Mailables on the same theme. — **Reversibility:** costly — every e-mail view depends on the published theme.
- **D-03:** The header shows the supplier name and the supplier logo; the footer shows the supplier details (address, company ID, contact) taken from the supplier settings of the instance, never hard-coded. The logo must be embedded or served so it renders in mail clients. Fictional data only in fixtures and tests (repository hygiene).
- **D-04:** Operational alerts (sent to Admin) use the same layout and branding as the client-facing e-mails; they differ only in content.

### Payment e-mails
- **D-05:** The payment confirmation goes to the client, to the client's invoice e-mail, in the client's language, after an invoice is paid. It covers both card payments and manually recorded payments.
- **D-06:** The confirmation has no attachment. It states the invoice number, the amount paid, the date and, for a partial payment, the remaining amount.

### Review of other e-mails
- **D-07:** The partner invitation, password reset, task notifications and operational alerts move to the shared layout and their subjects, texts and CZ/EN translations are reviewed and corrected in the same pass.

### Claude's Discretion
- Theme colours, typography and button style (consistent with the Filament panel look).
- How the logo is delivered to mail clients (inline attachment, hosted URL) and how the layout degrades when no logo is set.
- Whether a manually recorded payment sends the confirmation automatically or asks the Admin first (D-05 says it is sent; the exact trigger point and de-duplication across partial payments are left to research and planning).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### E-mail code
- `app/Domain/Invoicing/Mail/InvoiceMail.php` — plain-text invoice Mailable, PDF attachments from bytes.
- `resources/views/mail/invoice-text.blade.php` — current plain-text view to be replaced by the layout.
- `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` — the queued send.
- `app/Domain/Clients/Notifications/PartnerInvitation.php` and `app/Domain/Clients/InvitationMail.php` — partner invitation.
- `app/Domain/Clients/Actions/SendPartnerPasswordReset.php` and `app/Filament/Pages/Auth/RequestPasswordReset.php` — password reset e-mails.
- `app/Domain/Tasks/Notifications/TaskNotification.php` — task e-mails, with per-recipient channel preferences.
- `app/Domain/Operations/Alerts/OperationalAlert.php` — Admin alert e-mails.
- `app/Domain/Payments/Actions/RecordCardPayment.php` — where a card payment is recorded (candidate trigger for the confirmation).

### Prior decisions
- `.planning/phases/10-invoicing/10-UI-SPEC.md` — U-11 (plain-text e-mail body) and the send dialog.
- `.planning/phases/11-stripe-payments/11-CONTEXT.md` — D-01..D-04: stable public pay URL in the e-mail, the "Pay by card" button.
- `.planning/phases/10.1-invoices-stay-fully-editable-items-and-details-until-paid-or/10.1-CONTEXT.md` — a successful invoice e-mail locks the invoice.
- `.planning/REQUIREMENTS.md` — IN-04 (invoice e-mail), PA-02 (Pay by card button).
- `.planning/ROADMAP.md` — Phase 13 and the neighbouring Phase 10.2 (paid-invoice confirmation e-mail).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/views/mail/` — only the plain-text invoice view exists; no `vendor/mail` theme is published yet.
- `MailMessage` usage in invitation, tasks and alerts: reusing the theme restyles them without rewriting.

### Established Patterns
- Not queued Mailable inside a queued job for the invoice e-mail, so transport failures surface in the job.
- Texts live in `kokpit.*` translation keys (CZ/EN).
- Fictional-data rule applies to every fixture and test.

### Integration Points
- Supplier settings (name, logo, address, company ID) feed the layout header and footer.
- The invoice paid state (card webhook match and manual `RecordPayment`) is where the payment confirmation is triggered.

</code_context>

<specifics>
## Specific Ideas

No specific requirements beyond the decisions above. Phase 10.2 already exists in the roadmap for the paid-invoice confirmation; planning must decide whether D-05/D-06 are delivered there or here so the e-mail is built only once.

</specifics>

<deferred>
## Deferred Ideas

None. Discussion stayed within phase scope.

</deferred>

---

*Phase: 13-unified-e-mail-layout-and-html-e-mail-templates*
*Context gathered: 2026-10-22*
