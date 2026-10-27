---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 09
subsystem: invoicing
tags: [cash-receipt, ppd, pdf, dompdf, documents, replace-pdf]
requires:
  - phase: 15-03
    provides: ClosedDompdf engine and PdfParts shared building blocks
  - phase: 15-08
    provides: IssueCashReceipt, the receipt row with frozen document_number and snapshots
  - phase: 15-02
    provides: cash_receipts guard (PDF link guard, owner cash_receipt, ON DELETE SET NULL)
provides:
  - CashReceiptPdf (render, html, fileName) and CashReceiptPdfData::fromReceipt on the closed engine
  - resources/views/pdf/cash-receipt.blade.php (one A4 page, escaped output only, cancelled band)
  - StoreCashReceiptPdf (render first, replace the linked file, store and link, inside the caller's transaction)
  - DocumentOwnerType::CashReceipt (not shareable) with resolver branch and Czech label
  - full lang/{cs,en}/cash_receipt_pdf.php key set
affects: [15-10, 15-11, 15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "A receipt-style PDF reuses ClosedDompdf, PdfParts and InvoicePageDecoration; the data builder reads the document row only"
    - "A store/replace Action mirrors ReplaceIssuedPdf: render first, delete the old linked file with the confirmation flag, store, link"
key-files:
  created:
    - app/Domain/Invoicing/Pdf/CashReceiptPdf.php
    - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
    - app/Domain/Invoicing/Actions/StoreCashReceiptPdf.php
    - resources/views/pdf/cash-receipt.blade.php
    - tests/Feature/Invoicing/CashReceiptPdfTest.php
  modified:
    - app/Domain/Invoicing/Actions/IssueCashReceipt.php
    - app/Domain/Documents/Enums/DocumentOwnerType.php
    - app/Domain/Documents/Support/DocumentOwnerResolver.php
    - lang/cs/cash_receipt_pdf.php
    - lang/en/cash_receipt_pdf.php
    - lang/cs/enums.php
    - tests/Feature/Documents/DocumentOwnerDeletionTest.php
    - tests/Feature/Documents/DocumentsRelationManagerTest.php
key-decisions:
  - "R-15-09-1 to R-15-09-5 applied as written"
  - "IssueCashReceipt now takes StoreCashReceiptPdf and calls it last, so a failing render rolls the payment, receipt and number back"
  - "StoreCashReceiptPdf syncs only pdf_document_id on the caller's instance, so the caller sees the link without a second write"
requirements-completed: [P15-08, P15-12]
status: complete
duration: not measured
completed: 2026-10-27
commits: 2
plan_head_before: 9e74f1d210e14bf44e4e448fb0b38fd6e743425d
plan_head_after: 959d010064de84d22d05274cc86614dc84c5e2a4
actuals:
  tokens: 21000
  tasks: 2
  commits: 2
---

# Phase 15 Plan 09: Cash receipt PDF Summary

**Every cash receipt now gets a stored one-page PDF with the Sec. 11 content (title and number, issue date and cash-received date, payer, receiver, amount with currency, purpose, paid document number, issuer line with the supplier signature image), built from the receipt row alone, in the client's language, with a full-width cancelled band when cancelled, stored as a document of the receipt in the transaction of the payment.**

## Accomplishments

- `CashReceiptPdfData::fromReceipt()` reads only the receipt row and its three snapshots (the paid document number comes from the frozen `document_number` column, no `invoices` read). Parties through `PdfParts::party()`, images through `PdfParts::image()`, palette from the appearance snapshot, dates as `j. n. Y` (cs) or `Y-m-d` (en), amount via `Money::format(language)`. `LogicException` for a missing snapshot, `UnexpectedValueException` for a changed or missing image.
- `CashReceiptPdf` renders through the shared `ClosedDompdf` with `InvoicePageDecoration`; `fileName()` gives `prijmovy-doklad-:number.pdf` / `cash-receipt-:number.pdf` from the number reduced to safe characters.
- `resources/views/pdf/cash-receipt.blade.php`: one A4 page, `{{ }}` only, bundled fonts, no VAT table, variable symbol, QR code, bank data, pay button, payer signature box or amount in words.
- `StoreCashReceiptPdf::handle()`: `LogicException` outside a transaction; render first; lock the receipt row; delete a linked old file through `DeleteDocument` with the confirmation flag; store via `StoreGeneratedDocument`; link `pdf_document_id`. Non-refusal failures become `RuntimeException(documents.actions.delete_issued_pdf.regenerate_failed)` with the cause chained.
- `IssueCashReceipt` calls it after the row and audit are written, inside the payment transaction.
- `DocumentOwnerType::CashReceipt` (`isShareable()` false, `documents_visible_owner_check` untouched), resolver branch (client of the receipt, no project, shared lock, system run), label "Příjmový doklad".
- Lang: full `cash_receipt_pdf` key set in cs and en.

## Task Commits

1. Task 1 RED: `e2fd12e` test(15-09) failing tests for the cash receipt PDF (CashReceiptPdfTest plus the two enumerating owner-type tests)
2. Task 1 GREEN: `959d010` feat(15-09) render and store the cash receipt PDF at issue

Task 2 (English, cancelled band, escaping, no invoices read, failing render, replace and rollback) is a test-only task over code of Task 1. All its cases are in `CashReceiptPdfTest` (13 `it(` blocks) and were written in the RED commit of Task 1, because both tasks share one test file; Task 2 therefore has no commit of its own and needed no fix to the implementation. `commits: 2` is measured with `git rev-list --count` from the base to `959d010`; the SUMMARY commit follows it.

## TDD Gate Compliance

RED commit `e2fd12e` precedes GREEN `959d010`. The RED run was NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. By construction `CashReceiptPdf`, `StoreCashReceiptPdf` and `DocumentOwnerType::CashReceipt` did not exist at `e2fd12e`, so every case fails there. No `check tdd-red-evidence` record exists. Flagged for the post-merge run.

## Deviations from Plan

- **Process:** the Pest runs, directory-wide Pest, full PHPStan and full Pint of both verify blocks are moved to the post-merge run (project rule for worktrees). Done here: `php -l`, Pint `--test` (main checkout binary) on every touched PHP and lang file and on the three test files, `scripts/check-sensitive.sh`, and the lefthook hook on both commits. PHPStan was not run (it needs the app, vendor and DB); types were reviewed by hand.
- **Task split:** Task 2's tests were written together with Task 1's (see above).
- **Test removed:** a planned "refuses outside a transaction" case was dropped because it needs the test-wrapping transaction to be rolled back by hand, which is fragile; the guard is a three-line `DB::transactionLevel()` check mirrored from `ReplaceIssuedPdf`.

## Known Stubs

None.

## Threat Flags

None. T-15-19 mitigated (Blade `{{ }}` only, closed `ClosedDompdf`, escaping test with `<b>x</b>` and an `<img>` tag); T-15-21 mitigated (`isShareable()` false, constraint untouched); T-15-SC: composer.json and composer.lock untouched.

## Verification status

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after migrating:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptPdfTest.php tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/CashReceiptIssueTest.php tests/Feature/Documents/DocumentOwnerDeletionTest.php tests/Feature/Documents/DocumentsRelationManagerTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Feature/Documents tests/Isolation tests/Arch`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first: (a) the existing tests that record a cash payment through `RecordPayment` (`ConvertProformaTest`, `InvoiceDetailTest`, `PaymentModalTest`, `PaymentsTest`, `CardPaymentCoreTest`, `CashReceiptIssueTest`) now also render and store a PDF; a red one means a fault in this plan's code; (b) the `PPD`-number and Czech money/date strings in the new HTML assertions (non-breaking spaces in `Money::format('cs')`); (c) the cancelled case, which sets `cancelled_at` through the row guard from a system run (as `CashReceiptModelTest` does); (d) the replace case against the 15-02 PDF link guard (the `ON DELETE SET NULL` update, then null to new file); (e) the Ghostscript text case skips without `gs`; (f) PHPStan on the `trans()` return in `fileName()` and the `FilamentTimezone::get()` argument; (g) the human check of one generated cs and en PDF (single page, signature image, no payment instructions).

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Pdf/CashReceiptPdf.php, app/Domain/Invoicing/Pdf/CashReceiptPdfData.php, app/Domain/Invoicing/Actions/StoreCashReceiptPdf.php, resources/views/pdf/cash-receipt.blade.php, tests/Feature/Invoicing/CashReceiptPdfTest.php, lang/cs/cash_receipt_pdf.php, lang/en/cash_receipt_pdf.php
- FOUND commits (ancestors of HEAD): e2fd12e, 959d010
- `grep ClosedDompdf` in CashReceiptPdf.php, `PdfParts::` and `document_number` in CashReceiptPdfData.php, `StoreCashReceiptPdf` in IssueCashReceipt.php, `StoreGeneratedDocument` in StoreCashReceiptPdf.php, `case CashReceipt = 'cash_receipt'`: present
- STATE.md and ROADMAP.md untouched
