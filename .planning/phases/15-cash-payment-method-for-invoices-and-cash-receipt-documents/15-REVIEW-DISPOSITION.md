---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
review: 15-REVIEW.md
recorded: 2026-10-27
critical: 0
warning: 4
info: 3
open: 6
accepted: 1
---

# Phase 15 code review disposition

The review found no Critical issue. Every finding is advisory and none was applied during execution; each stays
`open` until it is fixed (`/gsd-code-review 15 --fix`) or consciously accepted. The core invariants (Partner
isolation, the cash_receipts row guard, gap-free numbering, lock order, escaping) were traced and held.

| Id | Severity | Status | Summary |
|----|----------|--------|---------|
| WR-01 | warning | accepted (owner, 2026-10-27: keep as is; a resend or explicit lock is a possible follow-up) | An unlocked receipt can never be re-sent or re-locked: only `SendPaymentConfirmation::markSent()` locks, there is one confirmation per payment, and no resend path exists. After an unlock and edit the corrected PDF never reaches the client and the receipt stays editable. Same dead end after an `attachment_missing` failure. Needs a product decision: a sanctioned resend or an explicit relock. |
| WR-02 | warning | open | `runReceiptAction()` in `InvoicePaymentsRelationManager` does not map `RuntimeException` / `DomainException` from the PDF render or store step; only the regenerate action does. A storage or render failure during cancel or edit surfaces as an unhandled Livewire error (the transaction rolls back, no data lost). |
| WR-03 | warning | open | `CashReceiptPdfData` always prints "supplier is not a VAT payer" and ignores `vat_payer` in the snapshot. Latent while `IssueChecks` blocks VAT-payer issuing. |
| WR-04 | warning | open | The regenerate action is visible only when `pdf_document_id` is null, but the action also covers a document row whose stored object is gone. In that case there is no UI repair, the download returns 404 and the confirmation fails permanently. |
| IN-01 | info | open | `DeletePayment` reuses `$receipt` for a `CashReceipt` and then a `Document`. |
| IN-02 | info | open | Deleting a cash-receipt PDF from the document library shows a generic failure and logs a reported exception; the bulk delete counts it as failed rather than skipped. |
| IN-03 | info | open | A confirmation stuck in `sending` after a worker death blocks receipt edits indefinitely. Whether a sweeper exists was not confirmed. |
