---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
reviewed: 2026-10-27T00:00:00Z
depth: standard
files_reviewed: 65
files_reviewed_list:
  - app/Domain/Documents/Actions/DeleteDocument.php
  - app/Domain/Documents/Enums/DocumentOwnerType.php
  - app/Domain/Documents/Support/DocumentOwnerResolver.php
  - app/Domain/Invoicing/Actions/CancelCashReceipt.php
  - app/Domain/Invoicing/Actions/ConvertProforma.php
  - app/Domain/Invoicing/Actions/DeletePayment.php
  - app/Domain/Invoicing/Actions/IssueCashReceipt.php
  - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
  - app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php
  - app/Domain/Invoicing/Actions/RecordPayment.php
  - app/Domain/Invoicing/Actions/RegenerateCashReceiptPdf.php
  - app/Domain/Invoicing/Actions/StoreCashReceiptPdf.php
  - app/Domain/Invoicing/Actions/UnlockCashReceipt.php
  - app/Domain/Invoicing/Actions/UpdateCashReceipt.php
  - app/Domain/Invoicing/Enums/CashReceiptLockState.php
  - app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
  - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
  - app/Domain/Invoicing/Mail/PaymentConfirmationMail.php
  - app/Domain/Invoicing/Models/CashReceipt.php
  - app/Domain/Invoicing/Models/Invoice.php
  - app/Domain/Invoicing/Models/InvoicePayment.php
  - app/Domain/Invoicing/Pdf/CashReceiptPdf.php
  - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
  - app/Domain/Invoicing/Pdf/ClosedDompdf.php
  - app/Domain/Invoicing/Pdf/InvoicePdf.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Pdf/PdfParts.php
  - app/Domain/Invoicing/Policies/CashReceiptPolicy.php
  - app/Domain/Invoicing/Support/CashReceiptAudit.php
  - app/Domain/Invoicing/Support/CashReceiptPdfUrl.php
  - app/Domain/Invoicing/Support/DocumentPayment.php
  - app/Domain/Invoicing/Support/IssueChecks.php
  - app/Domain/Settings/Numbering/DocumentKind.php
  - app/Domain/Settings/Settings/NumberingSettings.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource/RelationManagers/PartnerCashReceiptsRelationManager.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
  - app/Filament/Support/InvoiceColumns.php
  - app/Http/Controllers/CashReceiptPdfController.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - database/migrations/2026_10_27_000100_add_cash_to_document_payment_method.php
  - database/migrations/2026_10_27_000200_create_cash_receipts_table.php
  - database/settings/2026_10_27_000100_add_cash_receipt_pattern.php
  - lang/cs/cash_receipt_pdf.php
  - lang/cs/cash_receipts.php
  - lang/cs/enums.php
  - lang/cs/invoice_mail.php
  - lang/cs/invoice_pdf.php
  - lang/cs/invoices.php
  - lang/cs/kokpit.php
  - lang/cs/payment_mail.php
  - lang/en/cash_receipt_pdf.php
  - lang/en/invoice_mail.php
  - lang/en/invoice_pdf.php
  - lang/en/payment_mail.php
  - resources/views/mail/payment-confirmation.blade.php
  - resources/views/pdf/cash-receipt.blade.php
findings:
  critical: 0
  warning: 4
  info: 3
  total: 7
status: issues_found
---

# Phase 15: Code Review Report

**Reviewed:** 2026-10-27
**Depth:** standard (cross-file on the Partner boundary, the row guard, the lock order and the send/cancel/edit races)
**Files Reviewed:** 65 non-test source files (tests read only to judge what they pin)
**Status:** issues_found

## Summary

The phase is carefully built and I could not break its core invariants. I traced each of the following and found it sound:

- **Partner isolation.** The `CashReceipt` scope (own client, issued document, client not archived) is applied through `PartnerScope`. The policy denies every write ability. The Partner relation manager exposes only number, date received, amount and valid/cancelled. The cancel and unlock reasons live only in the Admin activity log, not on the row. The signed route checks in the right order (user-bound `u` signature 403, then UUID shape 404, then the scoped lookup 404, then policy 403). It re-checks `owner_type`/`owner_id` before it reads the media row as system, streams through the application with `nosniff` and `private, no-store`, and exposes no bucket URL.
- **Database guard.** I walked every branch of `kokpit_guard_cash_receipt_row()`: insert, delete, set-null from the payment foreign key, cancelled, cancel, locked and unlock. They are consistent with each other and with `cash_receipts_payment_link_check`. A payment with a valid receipt cannot be deleted, and the receipt is never deleted. `unlock` needs the transaction-local setting, which only `UnlockCashReceipt` sets, and it is cleared right after the save.
- **Numbering.** The receipt number is allocated inside the payment transaction, and the PDF render is the last step before commit. Any failure rolls the number back, so the series stays gap-free.
- **Lock order.** The order is invoice, then payment, then receipt, in `RecordPayment`, `DeletePayment` and `CancelCashReceipt`. `UpdateCashReceipt` takes invoice, receipt, then confirmation. `markSent` takes receipt, then confirmation. The orders are consistent and I found no deadlock cycle.
- **PDF and mail.** Blade `{{ }}` escaping is used throughout the receipt template. The mail uses `MailText::escapeMarkdown`. The attachment name is generated from the sanitised number. The PDF link guard prevents repointing a link.
- **Stored PDFs.** The old object is removed only after the outermost commit. `DeleteDocument` refuses a cash-receipt PDF unless the sanctioned replace path sets the flag.
- **Hygiene.** `scripts/check-sensitive.sh` over the whole reviewed set reports clean.

No BLOCKER was found. The findings below are four robustness and correctness gaps (WARNING) and three minor points (INFO).

## Critical Issues

None.

## Warnings

### WR-01: An unlocked receipt can never be re-sent or re-locked, so the documented "relock on next send" is unreachable

**File:** `app/Domain/Invoicing/Actions/UnlockCashReceipt.php:17-21`, `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php:180-200`
**Issue:** `UnlockCashReceipt` documents "the receipt stays open until the next successful send of its payment confirmation locks it again". The only code that sets `lock_state = locked` is `SendPaymentConfirmation::markSent()`. That job works on exactly one `payment_confirmations` row per payment, which is `sent` after the first delivery. No resend path exists for payment confirmations: grep finds no resend in `QueuePaymentConfirmation`, `RecordPaymentAction` or the payments tab. The research file assumed one ("A resend of a locked PPD writes nothing").

Failure scenario: the Admin unlocks a sent receipt to fix a typo in the customer name and edits it. The corrected PDF is never sent to the client. The receipt is never locked again, so an accounting document stays freely editable and deletable-by-cancel forever. This weakens the project immutability rule ("editable until a successful e-mail send locks it"). The same dead end applies to a confirmation that failed with `attachment_missing`: after the Admin regenerates the PDF there is no way to send it.

**Fix:** Add a sanctioned resend of the payment confirmation for a payment with a cash receipt, either a requeue of the `sent` or `failed` row or a second confirmation row. The resend should attach the current PDF and lock the receipt through the existing `markSent()` path. Alternatively, add an explicit "lock" action and drop the "relock on next send" wording, so the behaviour matches the claim.

### WR-02: Cancel and edit of a receipt surface PDF/storage failures as an unhandled exception

**File:** `app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php:544-590` (`runReceiptAction`), compare `:496-512` (`regenerateReceiptPdfAction`)
**Issue:** `CancelCashReceipt` and `UpdateCashReceipt` call `StoreCashReceiptPdf`, which throws `RuntimeException` (`regenerate_failed`) or `DomainException`. `runReceiptAction()` catches only `AuthorizationException` and `ValidationException`. Only the regenerate action wraps those exceptions into the fixed message. An unreadable logo or signature object, a full disk, or an S3 error during cancel or edit therefore becomes an uncaught exception inside Livewire (generic server-error modal). The domain note that "no path or storage detail reaches a screen" holds only for regenerate.

Failure scenario: the S3 bucket is briefly unavailable while the Admin cancels a receipt in order to delete a mistaken payment. The Admin gets a raw error instead of a clear retry message. The transaction does roll back, so no data is lost, but the reporting is inconsistent.

**Fix:** Move the `DomainException|LogicException|RuntimeException` to `ValidationException(['receipt' => __('cash_receipts.admin.regenerate.failed')])` mapping into `runReceiptAction()` so all receipt actions share it. Keep `AuthorizationException` rethrown first.

### WR-03: The receipt PDF always prints "The supplier is not a VAT payer" (latent false statement)

**File:** `app/Domain/Invoicing/Pdf/CashReceiptPdfData.php:98`
**Issue:** `PdfParts::party($t('received_by'), $supplier, $t, $t('not_vat_payer'), true)` passes the "not a VAT payer" note unconditionally. `InvoicePdfData` passes it only when `vat_mode !== Payer`. The supplier snapshot copied onto the receipt carries `vat_payer` (`InvoiceSnapshot.php:50`), and `SnapshotInput::supplier()` preserves it, but the receipt never reads it.

Today this is masked, because `IssueChecks` blocks issuing with `vat_payer_unsupported` while the payer mode exists, so every issued document comes from a non-payer. The tests pin only the non-payer text (`CashReceiptPdfTest.php:147`). When VAT-payer support lands, every receipt of a payer would print a false statement on an accounting document.

**Fix:**
```php
$notPayer = ($supplier['vat_payer'] ?? false) === true ? null : $t('not_vat_payer');
receivedBy: PdfParts::party($t('received_by'), $supplier, $t, $notPayer, true),
```
Add a test with `vat_payer = true` in the supplier snapshot.

### WR-04: A receipt whose stored object is gone but whose document row remains cannot be repaired from the UI

**File:** `app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php:500`, `app/Domain/Invoicing/Actions/RegenerateCashReceiptPdf.php:21-27,65-75`
**Issue:** `RegenerateCashReceiptPdf` is designed to cover two cases: an empty link, and "the linked document has lost its stored object" (`hasStoredPdf()` checks the disk). The action is `visible` only when `pdf_document_id === null`. The foreign key `ON DELETE SET NULL` clears the link only when the document row is deleted. A missing bucket object with the row intact is the more likely real-world fault (lifecycle rule, manual cleanup). In that case the link stays set, the download action returns 404, `SendPaymentConfirmation` fails permanently with `attachment_missing`, and no Admin action is offered.

**Fix:** Make the visibility closure share `hasStoredPdf()` logic, for example expose a static `RegenerateCashReceiptPdf::needsPdf(CashReceipt)` that returns true for a null link or a missing object. Use it in `->visible(...)`.

## Info

### IN-01: Variable `$receipt` is reused for two types in `DeletePayment`

**File:** `app/Domain/Invoicing/Actions/DeletePayment.php:86` and `:99`
**Issue:** `$receipt` first holds the `CashReceipt` of the payment, then the `foreach` over the income documents reassigns it to a `Document`. It works, but it is easy to misread and invites a later use-after-reassign bug in a method whose purpose is to protect receipts.
**Fix:** Rename the loop variable to `$file` or `$document`.

### IN-02: Deleting a cash-receipt PDF from the document library gives a generic failure and an exception report

**File:** `app/Filament/Support/DocumentActions.php:381-395` (`deleteOne`), `app/Domain/Documents/Actions/DeleteDocument.php`
**Issue:** `DeleteDocument` refuses a cash-receipt PDF with a `DomainException` (`cash_receipts.errors.pdf_protected`). `deleteOne()` catches `Throwable`, calls `report()` with a `RuntimeException`, and shows the generic `delete_failed`. The Admin is not told that the file is protected evidence, and each attempt logs a reported exception. The bulk delete counts the file as failed rather than skipped (the issued-invoice PDF has its own skipped branch).
**Fix:** Treat `owner_type === cash_receipt` like the issued-PDF case in the single and bulk delete UI, and show the `pdf_protected` message instead of the generic failure.

### IN-03: A confirmation stuck in `sending` blocks receipt edits indefinitely

**File:** `app/Domain/Invoicing/Actions/UpdateCashReceipt.php:108-120`
**Issue:** `isSending()` refuses an edit while the confirmation is `sending`. If a worker dies between `claim()` and the final state write, the row can stay `sending`. Then the receipt is uneditable (`sending`) and never locked. I did not find a sweeper that returns an orphaned `sending` row to `queued`. This may be handled elsewhere in the jobs layer; verify before treating it as a defect.
**Fix:** Confirm an orphan-`sending` recovery exists (for example a time limit on `updated_at`). If not, let `isSending()` ignore a row that has not changed for longer than the job timeout.

---

_Reviewed: 2026-10-27_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
