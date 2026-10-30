---
phase: 10-invoicing
reviewed: 2026-10-17T00:00:00Z
depth: standard
files_reviewed: 157
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Clients/Models/Client.php
  - app/Domain/Dashboard/Enums/DashboardWidget.php
  - app/Domain/Documents/Actions/AttachDocuments.php
  - app/Domain/Documents/Actions/DeleteDocument.php
  - app/Domain/Documents/Actions/StoreGeneratedDocument.php
  - app/Domain/Documents/Enums/DocumentOwnerType.php
  - app/Domain/Documents/Support/DocumentOwnerResolver.php
  - app/Domain/Finance/Actions/DeleteTransaction.php
  - app/Domain/Finance/Actions/RecordTransaction.php
  - app/Domain/Finance/Actions/UpdateTransaction.php
  - app/Domain/Finance/Models/Transaction.php
  - app/Domain/Invoicing/Actions/AddEntriesToDraft.php
  - app/Domain/Invoicing/Actions/AddFixedAmount.php
  - app/Domain/Invoicing/Actions/ConvertProforma.php
  - app/Domain/Invoicing/Actions/CreateDraft.php
  - app/Domain/Invoicing/Actions/DeleteDraft.php
  - app/Domain/Invoicing/Actions/DeletePayment.php
  - app/Domain/Invoicing/Actions/IssueCreditNote.php
  - app/Domain/Invoicing/Actions/IssueDocument.php
  - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
  - app/Domain/Invoicing/Actions/RecordPayment.php
  - app/Domain/Invoicing/Actions/RegeneratePdf.php
  - app/Domain/Invoicing/Actions/ReleaseEntries.php
  - app/Domain/Invoicing/Actions/UpdateDraft.php
  - app/Domain/Invoicing/Actions/UpdateInternalNote.php
  - app/Domain/Invoicing/Enums/InvoiceEmailState.php
  - app/Domain/Invoicing/Enums/InvoiceItemOrigin.php
  - app/Domain/Invoicing/Enums/InvoiceKind.php
  - app/Domain/Invoicing/Enums/InvoiceListTab.php
  - app/Domain/Invoicing/Enums/InvoiceState.php
  - app/Domain/Invoicing/Enums/InvoiceStatus.php
  - app/Domain/Invoicing/Enums/ItemGrouping.php
  - app/Domain/Invoicing/Enums/PaymentMethod.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Issuing/IssueContext.php
  - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
  - app/Domain/Invoicing/Mail/InvoiceMail.php
  - app/Domain/Invoicing/Models/Invoice.php
  - app/Domain/Invoicing/Models/InvoiceAdmin.php
  - app/Domain/Invoicing/Models/InvoiceEmail.php
  - app/Domain/Invoicing/Models/InvoiceItem.php
  - app/Domain/Invoicing/Models/InvoiceItemSource.php
  - app/Domain/Invoicing/Models/InvoicePayment.php
  - app/Domain/Invoicing/Notifications/InvoiceEmailFailedNotification.php
  - app/Domain/Invoicing/Pdf/DocumentPalette.php
  - app/Domain/Invoicing/Pdf/InvoicePageDecoration.php
  - app/Domain/Invoicing/Pdf/InvoicePdf.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Pdf/QrPng.php
  - app/Domain/Invoicing/Pdf/Spayd.php
  - app/Domain/Invoicing/Policies/InvoiceItemPolicy.php
  - app/Domain/Invoicing/Policies/InvoicePolicy.php
  - app/Domain/Invoicing/Queries/InvoiceBalances.php
  - app/Domain/Invoicing/Queries/InvoiceableEntries.php
  - app/Domain/Invoicing/Queries/ReceivablesReader.php
  - app/Domain/Invoicing/Support/CreditableQuantities.php
  - app/Domain/Invoicing/Support/DraftLines.php
  - app/Domain/Invoicing/Support/DraftPreview.php
  - app/Domain/Invoicing/Support/DraftTotals.php
  - app/Domain/Invoicing/Support/EntryReservation.php
  - app/Domain/Invoicing/Support/InvoiceItemInput.php
  - app/Domain/Invoicing/Support/InvoicePdfUrl.php
  - app/Domain/Invoicing/Support/InvoiceQuantity.php
  - app/Domain/Invoicing/Support/InvoiceSnapshot.php
  - app/Domain/Invoicing/Support/IssueChecks.php
  - app/Domain/Invoicing/Support/PaymentInput.php
  - app/Domain/Invoicing/Support/TimeItemGrouping.php
  - app/Domain/Invoicing/Support/VariableSymbol.php
  - app/Domain/Operations/Health/HealthResult.php
  - app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php
  - app/Domain/Reports/EntryAmounts.php
  - app/Domain/Reports/Queries/EffectiveBillingSql.php
  - app/Domain/Reports/Queries/TimeReportReader.php
  - app/Domain/Reports/ReportEntry.php
  - app/Domain/Reports/TimeReport.php
  - app/Domain/Settings/DocumentImage.php
  - app/Domain/Settings/Numbering/DocumentKind.php
  - app/Domain/Settings/Numbering/InvalidNumberPattern.php
  - app/Domain/Settings/Numbering/NumberPattern.php
  - app/Domain/Settings/Rules/HexColorRule.php
  - app/Domain/Settings/Settings/InvoicingSettings.php
  - app/Domain/Settings/Settings/SupplierSettings.php
  - app/Domain/Shared/Database/Immutability.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Money/Money.php
  - app/Domain/TimeTracking/Actions/CancelEntriesBilling.php
  - app/Domain/TimeTracking/Actions/DeleteTimeEntry.php
  - app/Domain/TimeTracking/Actions/MarkEntriesBilled.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Billing/EntryBilling.php
  - app/Domain/TimeTracking/Models/TimeEntry.php
  - app/Domain/TimeTracking/Queries/TimeTotals.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Filament/Pages/Dashboard.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ListPartnerInvoices.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php
  - app/Filament/RelationManagers/InvoiceDocumentsRelationManager.php
  - app/Filament/RelationManagers/InvoiceHistoryRelationManager.php
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ClientResource/Pages/ViewClient.php
  - app/Filament/Resources/ClientResource/RelationManagers/ClientInvoicesRelationManager.php
  - app/Filament/Resources/ClientResource/RelationManagers/ClientProjectsRelationManager.php
  - app/Filament/Resources/ClientResource/Widgets/ClientOverviewStats.php
  - app/Filament/Resources/InvoiceResource.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - app/Filament/Resources/InvoiceResource/Pages/ListInvoices.php
  - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEmailsRelationManager.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEntriesRelationManager.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
  - app/Filament/Resources/TimeEntryResource.php
  - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
  - app/Filament/Resources/TransactionResource.php
  - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
  - app/Filament/Support/CreateDraftAction.php
  - app/Filament/Support/DocumentActions.php
  - app/Filament/Support/DraftEditorPreview.php
  - app/Filament/Support/InvoiceColumns.php
  - app/Filament/Support/InvoiceTable.php
  - app/Filament/Support/RecordPaymentAction.php
  - app/Filament/Support/SendInvoiceEmailAction.php
  - app/Filament/Widgets/UnpaidInvoices.php
  - app/Http/Controllers/Api/V1/TimeEntryController.php
  - app/Http/Controllers/Api/V1/TransactionController.php
  - app/Http/Controllers/InvoicePdfController.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/OperationsServiceProvider.php
  - composer.json
  - config/kokpit.php
  - database/migrations/2026_10_17_000100_create_kokpit_child_guard_functions.php
  - database/migrations/2026_10_17_000200_create_invoices_tables.php
  - database/migrations/2026_10_17_000300_create_invoice_admin_tables.php
  - database/migrations/2026_10_17_000400_link_transactions_to_invoices.php
  - database/migrations/2026_10_17_000500_link_time_entries_to_invoices.php
  - database/migrations/2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php
  - database/settings/2026_10_17_000100_add_document_appearance_settings.php
  - database/settings/2026_10_17_000200_distinct_proforma_number_pattern.php
  - lang/cs/documents.php
  - lang/cs/enums.php
  - lang/cs/invoice_items.php
  - lang/cs/invoice_mail.php
  - lang/cs/invoice_pdf.php
  - lang/cs/invoices.php
  - lang/cs/kokpit.php
  - lang/en/invoice_items.php
  - lang/en/invoice_mail.php
  - lang/en/invoice_pdf.php
  - resources/views/filament/invoices/draft-summary.blade.php
  - resources/views/filament/pages/system-page.blade.php
  - resources/views/filament/pages/time-report.blade.php
  - resources/views/mail/invoice-text.blade.php
  - resources/views/pdf/invoice.blade.php
findings:
  critical: 0
  warning: 5
  info: 6
  total: 11
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-10-17
**Depth:** standard
**Files Reviewed:** 157
**Status:** issues_found

## Summary

The phase is carefully built. The review traced the issue pipeline end to end (`IssueDocument`, `ConvertProforma`, `IssueCreditNote` over `DocumentIssuer`), the reserve/bill/release life cycle of time entries (`EntryReservation`, `EntryBilling`, the link-guard trigger), the payment and payment-income path (`RecordPayment`, `DeletePayment`, the deletion flag, the pair-check trigger), the money arithmetic (`Money`, `InvoiceQuantity`, `DraftTotals`, the `invoice_items_line_total_check` bound), the signed PDF route, the Partner resource and every Partner-reachable builder, and the Admin Livewire pages and relation managers.

No Partner data leak and no authorization gap was found. The Partner can reach only the scoped `Invoice` query, the Partner-safe `InvoiceColumns` builders and the signed PDF route; every Admin-only table is `DeniesPartners`/`AdminOnlyPolicy`; the invoice PDF `Document` has no branch in `Document::constrainForPartner()`; every domain Action authorizes before it writes; the Admin pages check `canAccess()` on every request. The status filter, which runs the Admin reader as system, only builds `whereRaw` conditions that stay under the Partner scope at execution. The money rounding is a single point and matches the database CHECK (verified by running `Money`, `InvoiceQuantity` and `Spayd` directly). Exception text does not reach the screen (the alert sanitiser redacts the failed-job line). No translation key used in code is missing from `lang/cs`, and the sensitive-data checker is clean on all files.

What remains is five warnings, none of them a security issue: one missing server-side invariant on payments, one dialog that swallows its own error, one dead end that locks billed time, one crash for very large CZK documents, and one stale-read race in the claim. Six info items follow.

## Warnings

### WR-01: A payment can be recorded on a proforma that is already converted, and its income is never booked

**File:** `app/Domain/Invoicing/Actions/RecordPayment.php:66-70`
**Issue:** The class docblock (and `RecordPaymentAction::available()`) state the invariant "a payment on a converted proforma would never book its income", but only the screen enforces it. `handle()` refuses a draft, a credit note and a cancelled invoice, and nothing else. `ConvertProforma` and `RecordPayment` lock the proforma row first, so they serialise, but a payment that is handled after the conversion passes every check. It is then stored as a proforma payment (`books = false`), the invoice made from the proforma has already carried its copies, and `carry()` will never run again. The money is on the proforma only: no income transaction exists, `DeletePayment` finds no income to remove, and the CZK income of the instance understates reality. The path is reachable by a stale browser tab (the modal is opened on the proforma, the conversion happens in another tab, the modal is submitted) or by a forged Livewire call; neither the database nor the Action stops it.
**Fix:** Judge it on the locked row, next to the other refusals:
```php
if ($locked->kind === InvoiceKind::Proforma
    && Invoice::query()->where('proforma_id', $locked->id)->where('state', InvoiceState::Issued->value)->exists()) {
    throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_proforma_converted')]);
}
```
Add a dedicated message (the existing `payment_not_allowed` names only credit notes and cancelled invoices) and keep `RecordPaymentAction::available()` as the display rule.

### WR-02: The storno dialog swallows every validation failure

**File:** `app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php:334-348` (the dialog), `:487` and `:512-523` (the mapping)
**Issue:** `issueCredit()` re-throws every `ValidationException` that is not keyed `invoice` through `creditErrorsUnderModal()`, which writes each message to `mountedActions.0.data.lines` (an item id is only extracted for keys starting with `lines.`). The partial-credit dialog has a `lines` repeater, so the message lands there. The storno dialog's schema holds only a `Callout`, so there is no field bound to `data.lines`, nothing renders the message, no notification is sent, and "Stornovat" appears to do nothing. The failures that reach this branch are real: `credit_nothing` when the invoice has already been credited in full by partial credit notes (see WR-03), and any blocker of the shared issue pipeline for the credit-note series (`supplier_missing` or `client_address_missing` after the settings or the client changed, `pattern_invalid`, `vat_payer_unsupported`). The ConvertProforma action in the same file handles the same situation correctly with a notification.
**Fix:** In `stornoAction()` catch the validation failure and show it, the way `convertProformaAction()` does:
```php
} catch (ValidationException $exception) {
    Notification::make()->danger()->title(__('invoices.issue.failed_title'))
        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
    return;
}
```
(or give `issueCredit()` a flag so that only the partial dialog maps errors under the modal).

### WR-03: Time billed by an invoice that was credited in full by partial credit notes can never be released

**File:** `app/Domain/Invoicing/Actions/IssueCreditNote.php:106-123` and `:182-187`; `app/Domain/TimeTracking/Actions/CancelEntriesBilling.php:126-137`; `database/migrations/2026_10_17_000500_link_time_entries_to_invoices.php:103-107`
**Issue:** The only way to release time billed by an invoice is a storno credit note (`cancels_invoice = true`), enforced by `CancelEntriesBilling::refuseInvoiceBilled()` and by the link-guard trigger. `partial()` issues an ordinary credit note whose `cancels_invoice` is false, even when it takes back every remaining quantity. After that, `storno()` computes `$quantities = []` and throws `credit_nothing`, so the invoice is economically void and still not "cancelled": its entries stay billed and locked, `UnpaidInvoices` and the client panel treat it as an open or paid document, and the time is neither billable again nor shown as unbilled. The user also gets no explanation (WR-02). The same dead end exists for an issued proforma that is never paid: `assertCreditable()` accepts invoices only (decision U-7), so its time stays billed for good. This works against the core value "no unbilled time slips through".
**Fix:** In `partial()`, when the credit note leaves nothing creditable (`CreditableQuantities::for()` is all zero after it), issue it with `cancels_invoice = true` and call `releaseEntries()`, i.e. treat it as the storno; or let `storno()` accept a fully credited invoice and write an empty-marker document. For the proforma, either allow a storno of an unpaid proforma (the trigger already accepts it, because it has no kind check) or document the lock explicitly in the UI.

### WR-04: A CZK document of 10 000 000,00 or more cannot be issued, and its PDF cannot be created again

**File:** `app/Domain/Invoicing/Pdf/InvoicePdfData.php:273-280`; `app/Domain/Invoicing/Pdf/Spayd.php:64-68`
**Issue:** `InvoicePdfData::payment()` prints a payment QR whenever `Spayd::supports()` is true (CZK and a valid Czech IBAN) and calls `Spayd::forPayment()` without a guard. `forPayment()` throws `InvalidArgumentException('The amount is longer than ten characters.')` for any total whose `toMajor()` text is longer than 10 characters, i.e. 10 000 000,00 CZK and above (`toMajor()` of 1 000 000 000 minor units is `10000000.00`, 11 characters; verified by running the class). The exception propagates out of the PDF step of the issue pipeline, so the whole transaction rolls back, the Admin sees only the generic "issue failed" notice, and the draft can never be issued while the QR is requested. `RegeneratePdf` fails the same way for such a document. A proforma has the same path.
**Fix:** Treat the length as "no QR", not as an error:
```php
&& Spayd::supports($issued->currency, $iban)
&& strlen($issued->total()->toMajor()) <= 10
```
(or add the length rule to `Spayd::supports()` by passing the amount), so the document still prints its bank details without the code.

### WR-05: The claim builds invoice lines from a candidate read taken before the lock

**File:** `app/Domain/Invoicing/Support/EntryReservation.php:46-56`; `app/Domain/Invoicing/Actions/CreateDraft.php:203-215`; `app/Domain/Invoicing/Actions/AddEntriesToDraft.php:79-91`; `app/Domain/Invoicing/Actions/AddFixedAmount.php:79-81`
**Issue:** The docblock of `EntryReservation` says eligibility "is decided on the locked rows". Only the seconds (and `client_id`, billable, unbilled, finished) are taken from the locked row. `CreateDraft` and `AddEntriesToDraft` build the lines from `[...$candidate, 'seconds' => ...]`, so the project, the task, the project and task names, the effective billing type and the hourly rate come from the earlier unlocked `InvoiceableEntries` query. An edit of an unreserved entry (the panel, or the API import helper) that changes its project or task between that query and the `FOR UPDATE` is not detected: the entry is claimed, but the line and its `invoice_item_sources` row name the old project and task, and `UpdateTimeEntry` then refuses to fix it because the entry is reserved. `AddFixedAmount` with `link_time` has the same gap for the fixed-price classification (the claim does not re-check that the entry is still of a fixed-price project). The window is small, but the pattern is explicitly meant to be race-free.
**Fix:** After locking, compare the claimed row with the candidate and drop the candidates that no longer match:
```php
$free = $locked->filter(fn (TimeEntry $e) => /* existing conditions */
    && $e->project_id === ($expected[$e->id]['project_id'] ?? null)
    && $e->task_id === ($expected[$e->id]['task_id'] ?? null));
```
(pass the candidate rows to `claim()`), or rebuild the line input from the locked entries and the same SQL as `InvoiceableEntries` inside the lock.

## Info

### IN-01: Unused import

**File:** `app/Filament/Resources/InvoiceResource/Pages/ListInvoices.php:8`
**Issue:** `use App\Domain\Invoicing\Models\Invoice;` is never used (the other 156 files have no unused import).
**Fix:** Remove the line.

### IN-02: Documents can still be issued, and a proforma converted, for an archived client

**File:** `app/Domain/Invoicing/Support/IssueChecks.php:175-190`; `app/Domain/Invoicing/Issuing/DocumentIssuer.php:98`
**Issue:** `CreateDraft` refuses an archived client, but a draft created before the archive, and a proforma of a client archived meanwhile, pass `IssueChecks` (the address check reads `$draft->client`, which includes archived clients) and `ConvertProforma`. The result is a numbered invoice that the Partner scope hides at once. Probably acceptable, but it is an unstated rule.
**Fix:** Either add an `client_archived` blocker for new invoices and proformas (not for a credit note, which must stay possible), or document that issuing for an archived client is allowed.

### IN-03: Unlinking time from a draft leaves lines that bill the same hours, so they can be billed twice

**File:** `app/Domain/Invoicing/Actions/ReleaseEntries.php:57-66`
**Issue:** By decision D-05 the lines stay when entries are unlinked. The released entries are free again and appear in the picker of any draft, while the original line (with its `source_seconds`) still bills the hours. Nothing at issue time compares the seconds of the lines with the seconds of the entries the document still holds, so the same hours can be issued on two documents without a warning.
**Fix:** Add an advisory blocker or a confirmation at issue when a time line's `source_seconds` exceeds the seconds of the entries still linked to the draft.

### IN-04: `StoreGeneratedDocument` does not assert its transaction, and the issue holds the sequence lock across the PDF render and the upload

**File:** `app/Domain/Documents/Actions/StoreGeneratedDocument.php:47-49`; `app/Domain/Invoicing/Issuing/DocumentIssuer.php:44-45`, `:114`, `:145`
**Issue:** `DB::afterRollBack()` registers a callback on the open transaction; outside a transaction it is never run, so a later failure would leave the object in the bucket. The class relies on its callers (both are inside a transaction) without checking `DB::transactionLevel()`. Separately, the docblock of `DocumentIssuer` says the number is allocated late "so that the counter row stays locked for as short a part of the transaction as possible", but the Dompdf render (which may wait up to 60 s for the font lock) and the object upload both run after the allocation and before the commit, so the counter of the series stays locked for that time.
**Fix:** Throw a `LogicException` in `handle()` when `DB::transactionLevel() === 0` (the way `DocumentIssuer::issue()` does), and either correct the docblock or render the PDF before the number is allocated (the bytes depend on the number, so a real change would need a two-step design; the docblock correction is enough).

### IN-05: Duplicated logic and constants

**File:** `app/Domain/Invoicing/Support/TimeItemGrouping.php:133-145` and `app/Domain/Invoicing/Support/DraftPreview.php:163-170`; `app/Filament/Support/InvoiceColumns.php:266-294` and `app/Filament/Support/InvoiceTable.php:155-179`; the limits 500/16/2 000 in `InvoiceItemInput`, `TimeItemGrouping`, `AddFixedAmount`, `UpdateDraft`, `UpdateInternalNote`
**Issue:** The CNB quote lookup with its per-call cache exists twice with different array shapes, the page-wide `WeakMap` derivation of the row states exists twice (Admin and Partner), and the description, unit and note length limits are repeated as private constants next to the database CHECK lengths. A change in one copy will drift from the others.
**Fix:** Extract one `quote()` helper (for example into `DraftPreview`, which `TimeItemGrouping` could call), one shared row-state page cache, and one `InvoiceLimits` class (or config keys) for the lengths.

### IN-06: The "add a fixed amount" modal shows the unsaved currency of the form but the action uses the stored one

**File:** `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php:752-757` (suffix from `currentCurrency()`); `app/Domain/Invoicing/Actions/AddFixedAmount.php:76` (parses with `$locked->currency`)
**Issue:** After the Admin changes the currency field and before saving, the modal suffix shows the new currency while `AddFixedAmount` stores the line in the saved one; `appendStoredLines()` then shows the stored figure under the new currency. For currencies with the same number of decimals the number survives, for a currency with a different number of decimals the amount is parsed with the wrong precision or refused.
**Fix:** Either save the form (or at least the currency) before opening the modal, or hide the action while `$this->data['currency'] !== $this->invoice()->currency`.

---

_Reviewed: 2026-10-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
