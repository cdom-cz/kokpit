---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 04
subsystem: invoicing
tags: [cash, pdf, email, filament-detail, payment-method]
requires:
  - phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
    provides: DocumentPaymentMethod::Cash and printsBankDetails()/allowsCardChoice()/forcesCardPayment() (15-01); PdfParts split of InvoicePdfData (15-03)
provides:
  - cash branch of InvoicePdfData::payment() (cash sentence, cash label, amount due; no QR, no bank facts)
  - cardPayment() guard for a method that neither allows nor forces card payment
  - invoice_mail.body.with_due_date_cash (cs, en) and its arm in QueueInvoiceEmail::defaultBody()
  - Partner and Admin detail entries on printsBankDetails()
affects: [15-07, 15-08]
tech-stack:
  added: []
  patterns: [detail visibility reads the method predicates, not method comparisons]
key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - lang/cs/invoice_pdf.php
    - lang/en/invoice_pdf.php
    - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
    - lang/cs/invoice_mail.php
    - lang/en/invoice_mail.php
    - app/Filament/Support/InvoiceColumns.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - tests/Feature/Invoicing/InvoicePdfVariantsTest.php
    - tests/Feature/Invoicing/EmailDialogTest.php
    - tests/Feature/Invoicing/InvoiceDetailTest.php
    - tests/Feature/Invoicing/PartnerInvoiceResourceTest.php
key-decisions:
  - "Cash PDF box prints only the cash sentence, the payment-method fact (Hotově / Cash) and the amount due"
  - "Partner detail hides bank account and variable symbols when the method is non-null and does not print bank details; a null method (credit note, legacy) is left as before"
  - "Admin detail keeps the stored variable symbol of a cash document (AS-13, owner confirmed 2026-10-27)"
patterns-established:
  - "Display rules that depend on the payment method read the enum predicates"
requirements-completed: [P15-02]
status: complete
actuals:
  tokens: 9000
  tasks: 2
  commits: 4
plan_head_before: b16d274f6b39d49100617994be6b4bf4b78ce9a4
plan_head_after: d9cd0c9
completed: 2026-10-27
---

# Phase 15 Plan 04: Cash documents print, mail and show no payment details Summary

**A cash invoice or proforma now prints a cash payment box (amount, due date, "Hotově"/"Cash"), mails a text asking for payment in cash, and shows no bank account or variable symbol to the Partner; the Admin keeps the stored variable symbol as an internal reference.**

## Accomplishments

- `InvoicePdfData::payment()`: new cash branch after the online branch and before the bank-snapshot fallthrough; sentence `invoice_pdf.cash_sentence`, facts `payment_method` -> `cash_payment` and `amount_due`, no QR. Docblock extended. `cardPayment()` returns null for a non-null method that neither allows a card choice nor forces card payment, so a cash document never carries a card lead or button even if a token existed.
- `cash_sentence` and `cash_payment` added to `lang/cs/invoice_pdf.php` and `lang/en/invoice_pdf.php`.
- `QueueInvoiceEmail::defaultBody()`: `Cash` arm `with_due_date_cash`; cs ("Doklad je určen k úhradě v hotovosti.") and en ("This document is to be paid in cash.") texts.
- `InvoiceColumns::partnerEntries()`: closure renamed `$printsBankDetails`, now `payment_method?->printsBankDetails() !== false`; applied to the document-section variable symbol, the bank account and the payment-section variable symbol. `ViewInvoice` bank-account entry visible when `payment_method?->printsBankDetails() === true`; variable symbol entry unchanged with an AS-13 comment. Unused `DocumentPaymentMethod` imports removed from both files.
- Tests: four PDF cases (cs, en, proforma, rendered PDF text via Ghostscript with skip) in `InvoicePdfVariantsTest`; three default-body cases in `EmailDialogTest`; Admin cash detail case in `InvoiceDetailTest`; Partner cash detail case in `PartnerInvoiceResourceTest`.

## Task Commits

1. Task 1 RED: `24afafc` test(15-04): add failing PDF tests for the cash payment box
2. Task 1 GREEN (tracer): `15827ff` feat(15-04): print a cash payment box on cash documents
3. Task 2 RED: `899a82b` test(15-04): add failing tests for the cash e-mail text and detail
4. Task 2 GREEN: `d9cd0c9` feat(15-04): cash documents carry no payment details in the e-mail text and the detail

## TDD Gate Compliance

RED commits precede the GREEN commits for both tasks. RED evidence is by inspection, NOT by a recorded run: the worktree has no `vendor/` and no database (project rule: no test runs in a worktree), so `gsd_run check tdd-red-evidence` could not be fed a real report. Semantic assessment: before the change `payment()` returned null for a cash document (no snapshot), so the PDF cases fail on the missing cash sentence; `defaultBody()` had no cash arm and fell through to `with_due_date` ("Platební údaje najdete..."), failing the e-mail cases; the Admin and Partner detail cases fail on the Partner and Admin bank-account/VS conditions only where the old comparisons differed (note: the old Admin bank-account entry already required Transfer, so the Admin case may pass before the change on that point; it pins AS-13 and the method label). No refactor commit.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Regression guard] Partner closure keeps a null method visible**
- **Found during:** Task 2
- **Issue:** the plan wrote the closure as `payment_method?->printsBankDetails() === true`; that would also hide the variable symbol of the document section for a document with a null method (credit note, legacy row), a Phase 14 case the plan requires unchanged.
- **Fix:** `payment_method?->printsBankDetails() !== false`, which hides only non-null methods that print no bank details (online, cash). The Admin `ViewInvoice` entry uses `=== true` as before (it already excluded credit notes).
- **Files modified:** app/Filament/Support/InvoiceColumns.php
- **Commit:** d9cd0c9

**2. [Plan placement] Partner cash detail test lives in PartnerInvoiceResourceTest**
- The plan listed the Partner assertions under InvoiceDetailTest, but that file runs the Admin panel and has no Partner fixture; the Phase 14 Partner method tests live in `PartnerInvoiceResourceTest`, so the cash case sits beside them. The Admin cash case is in `InvoiceDetailTest` as planned.

### Verification not run in the worktree

Per the project rules for worktrees, no Pest suite, no PHPStan and no migration was run here (no shared DB, no `vendor/` in the worktree). Cheap checks done: `php -l` on every touched PHP file, Pint `--test` (main checkout binary) on all touched files, `scripts/check-sensitive.sh` on every commit (via the hook). Run after the merge, in DDEV:

- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to watch at that run: the exact cs wording `Částka k úhradě` / `Hotově` in the Partner and Admin assertions (taken from existing Phase 14 assertions and 15-01's enum label); the `'platební kartou'` negative assertion in the cash PDF test assumes no template text contains it for a cash box.

## Known Stubs

None.

## Threat Flags

None. T-15-03 mitigated (PDF cash branch, `with_due_date_cash`, `printsBankDetails()` in Partner and Admin detail); T-15-SC: composer.json and composer.lock untouched.

## Self-Check: PASSED

- Commits `24afafc`, `15827ff`, `899a82b`, `d9cd0c9` are ancestors of HEAD.
- Acceptance greps: `DocumentPaymentMethod::Cash` in InvoicePdfData, `cash_sentence` in both pdf lang files, `cash_payment` in cs, `with_due_date_cash` in QueueInvoiceEmail and both mail lang files, `printsBankDetails` in InvoiceColumns and ViewInvoice.
