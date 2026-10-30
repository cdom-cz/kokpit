---
phase: 11-stripe-payments
plan: 05
subsystem: payments
tags: [stripe, card-payment, invoice-pdf, dompdf, invoice-email, filament]

requires:
  - phase: 11-stripe-payments
    provides: "PayUrl::forInvoice(), the frozen pay token and card switch of invoice_admin, tests/Support/CardPaymentSetup (plan 11-03)"
provides:
  - "InvoicePdfData::$cardPayment (lead, label, url) and the payment-box block of resources/views/pdf/invoice.blade.php: lead line, clickable 'Zaplatit kartou' button (/URI annotation) and the printed stable URL"
  - "App\\Domain\\Payments\\Support\\PayLinkBlock: forMail(Invoice): ?string and applies(Invoice): bool, the one predicate and text of the mail block"
  - "SendInvoiceEmail appends the pay-link block to the stored body at send time; the invoice_emails row keeps the confirmed text"
  - "E-mail dialog gray line 'Do e-mailu se automaticky přidá odkaz Zaplatit kartou.' shown exactly when the block will be added"
  - "Lang keys invoice_pdf.card_lead / card_button and invoice_mail.pay_block_lead (cs, en), invoices.email.dialog.pay_link (cs)"
affects: [11-06, 11-07, 11-08, 11-12, invoice-pdf, invoice-email]

actuals:
  tokens: 8500
  tasks: 3
  commits: 3
plan_head_before: 362529cedab0d13e8b32513bd1581c6a6a764ede
plan_head_after: 32af5d942b691f7eb5b5b890d4d5658ec33f1128
commits: 3

tech-stack:
  added: []
  patterns:
    - "Output decisions that must survive regeneration read only frozen invoice_admin columns (pay token), never the live link or payment state"
    - "A fixed block that the Admin must not be able to remove is appended at send time from a predicate shared with the dialog, while the log row keeps the confirmed text"

key-files:
  created:
    - app/Domain/Payments/Support/PayLinkBlock.php
    - tests/Feature/Payments/PayButtonOutputsTest.php
  modified:
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - resources/views/pdf/invoice.blade.php
    - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
    - app/Filament/Support/SendInvoiceEmailAction.php
    - lang/cs/invoice_pdf.php
    - lang/en/invoice_pdf.php
    - lang/cs/invoice_mail.php
    - lang/en/invoice_mail.php
    - lang/cs/invoices.php
    - tests/Feature/Invoicing/InvoicePdfTest.php

key-decisions:
  - "R11-05-1: cardPayment is set only when the payment box exists (kind carries payment and a bank snapshot) and PayUrl::forInvoice() returns a URL; the button lives inside the box, never alone"
  - "R11-05-2: the button reads only the frozen pay token, so a PDF regenerated after full payment has identical extracted text (tested)"
  - "R11-05-3: PayLinkBlock::applies = issued, kind carries payment, not cancelled, pay token present, InvoiceBalances remaining above zero; forMail returns two newlines, the lead in the document language, a newline and the URL; SendInvoiceEmail appends it to rtrim(body)"
  - "The Phase 10 snapshot test that forbade any invoice_admin query while building the PDF now allows exactly one single-column pay_token read"

patterns-established:
  - "PDF text assertions use PdfText::flat; a URL wrapped across lines would break contain(), so the test also proves the URL prints on one line"

requirements-completed: []

duration: 45min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 05: Pay button in the PDF and pay-link block in the e-mail Summary

**A card-on invoice or proforma now prints a clickable "Zaplatit kartou" button with its stable Kokpit URL in the PDF payment box, and its e-mail carries the same URL in a fixed block after the Admin's text, absent for paid and cancelled documents.**

## Performance

- **Duration:** about 45 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 12 (2 created, 10 modified)

## Accomplishments

- PDF: inside the payment box, below the facts, the lead line ("Nebo zaplaťte online kartou:" / "Or pay online by card:"), a one-cell accent table with an anchor labelled "Zaplatit kartou" / "Pay by card" (Ghostscript text and a `/URI` annotation in the PDF bytes are asserted), then the full URL in 8pt muted with `white-space: nowrap`. Values go through Blade `{{ }}`. No second QR code.
- The button depends on the frozen pay token alone: a test pays the invoice in full, deletes the stored PDF, calls `RegeneratePdf` and asserts the extracted text equals the text of the PDF stored at issue. Proforma, English, EUR without QR print the button; global switch off and a credit note print no lead line, no button, no `/URI` and no `/pay/`.
- E-mail: `SendInvoiceEmail::bodyToSend()` appends the block from `PayLinkBlock::forMail()` to `rtrim($row->body)`; clearing the text down to "Hi" still leaves the block. A resend after full payment or after storno carries none, a partial payment keeps it. The `invoice_emails.body` stays what the Admin confirmed. No test output contains `stripe.com`.
- Dialog: a gray `Text` under the attachments line, visible exactly when `PayLinkBlock::applies()`; the textarea default never contains the URL. Verified for unpaid card-on (shown), paid, cancelled and card-off (hidden).
- Verification: Payments, Invoicing and Arch suites 695 passed, Unit 780 passed, Isolation 331 passed, all with the PDF tests executed (Ghostscript present in DDEV). Pint and PHPStan clean, `scripts/check-sensitive.sh` clean. A mutation check (removing the paid and cancelled conditions, and hiding the dialog line) made the intended tests fail.

## Task Commits

1. Task 1 (tracer): PDF button and mail block - `2345534` (feat)
2. Task 2: every variant, regenerated PDF, paid and cancelled resend - `a221bb9` (test)
3. Task 3: dialog line - `32af5d9` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-05-1 to R11-05-3 were applied as written, with the small clarification that the button needs the payment box (a document without a bank snapshot has no box and no button).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Phase 10 snapshot test forbade any invoice_admin read in the PDF build**
- **Found during:** Task 1
- **Issue:** `InvoicePdfTest` "prints the same document whatever changes after the issue" asserted that building the HTML runs no query on `invoice_admin`; D-01 requires reading the frozen pay token from that row.
- **Fix:** the assertion now requires exactly one `select "pay_token" from "invoice_admin"` query and still forbids every other `clients`, `settings`, `exchange_rates` or `invoice_admin` read. The `InvoicePdfData` docblock names the exception.
- **Files modified:** `tests/Feature/Invoicing/InvoicePdfTest.php`, `app/Domain/Invoicing/Pdf/InvoicePdfData.php`
- **Commit:** `2345534`

### Choices within the plan's latitude

- Task 2 added tests only: `PayLinkBlock` was already complete (paid, cancelled, remaining) from the tracer, because the mail needed its full predicate to be correct. The new tests passed on first run; a mutation check proved they bite. This is a test-after for Task 2 behaviours rather than a RED run.
- Task 3 implementation and tests were written together; a mutation check (forcing `visible(false)`) made the positive dialog test fail.
- The dialog line copy exists in `lang/cs/invoices.php` only, as the plan lists; `lang/en/invoices.php` is not part of this plan.
- The mail block uses `trans(..., $invoice->language->value)`, so the lead follows the document language, not the app locale.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-16 (no Stripe URL printed or mailed) is covered by `stripe.com` absence assertions on PDF bytes, PDF text and mail bodies; T-11-18 by `PayLinkBlock` checking remaining and cancellation at send time. T-11-SC: this plan changed no composer file.

## Notes for later plans and the owner

- Backstop truth (N2 overflow, sample PDFs): the URL fits one line in the text extraction (a wrapped URL would fail `contain()` in the flattened text) and the payment box keeps `page-break-inside: avoid`; a visual look at one sample PDF with a long-address layout is still advisable at phase verification.
- PA-02 is not marked complete: the pay page, the invoice-detail and Partner button and the link job are other plans.

## Self-Check: PASSED

- Files: `PayLinkBlock.php`, `PayButtonOutputsTest.php` and all modified files exist.
- Commits: `2345534`, `a221bb9`, `32af5d9` are ancestors of HEAD.
