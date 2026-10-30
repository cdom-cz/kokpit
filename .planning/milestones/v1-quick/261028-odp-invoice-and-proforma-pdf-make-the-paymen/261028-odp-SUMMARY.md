---
phase: quick-261028-odp
plan: 01
subsystem: invoicing-pdf
tags: [pdf, invoice, proforma, cash-receipt, layout]
requires: []
provides:
  - compact payment box on the invoice and proforma PDF
  - PDF party blocks without supplier e-mail, phone and website
  - cash receipt PDF without the document footer
affects:
  - resources/views/pdf/invoice.blade.php
  - app/Domain/Invoicing/Pdf/PdfParts.php
  - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
tech-stack:
  added: []
  patterns: [array_chunk grid in Blade, one-row card block]
key-files:
  created: []
  modified:
    - resources/views/pdf/invoice.blade.php
    - resources/views/pdf/cash-receipt.blade.php
    - app/Domain/Invoicing/Pdf/PdfParts.php
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/InvoicePdfTest.php
    - tests/Feature/Invoicing/PdfEngineTest.php
    - tests/Feature/Invoicing/CashReceiptPdfTest.php
decisions:
  - "Condense, do not drop: every payment fact still prints, two to a row"
  - "QR is 72 pt with no extra padding, relying on the PNG's own 4-module quiet zone"
  - "The credit note shares the template and the party builder, so it also loses the supplier contact lines"
metrics:
  duration: ~25 min
  completed: 2026-10-28
status: complete
requirements: [IN-04, P15-08]
actuals:
  tokens: 9000
  tasks: 3
  commits: 3
plan_head_before: 3e15a2aacbd6bfe32d8a99b57c512351fc52598e
plan_head_after: 28e58c9738e139bd17738de6098d265ee30d17b2
---

# Phase quick-261028-odp Plan 01: Compact payment box and leaner PDFs Summary

The invoice and proforma payment box is now compact (facts two to a row, 72 pt QR, card lead and button on one row), and no invoice, proforma, credit-note or cash-receipt PDF prints the supplier's e-mail, phone or website. The cash receipt also no longer prints the document footer.

## What changed

1. **Task 1 (tracer), commit 38bf70d.** `resources/views/pdf/invoice.blade.php`: payment box margin 12 pt, cell padding 10/12 pt, QR 72 pt white square with no padding, sentence margin 4 pt, facts grouped with `array_chunk($data->payment['facts'], 2)` (key/value pairs, nowrap values, 12 pt gap, a colspan-2 filler on an odd last row), card lead and button on one `table.card-row`, printed URL below at 7.5 pt. The `<a href>` is kept for the link annotation. New test 'lays the payment facts out two to a row in a compact box' (invoice and proforma) checks the row count, that every fact prints, the 72 pt QR and its decoded SPAYD string.
2. **Task 2, commit d8ea054.** `PdfParts::party()` lost its boolean contact parameter; `notes` holds only the registration note. Callers in `InvoicePdfData` and `CashReceiptPdfData` use four arguments. `PdfEngineTest` was updated (renamed test asserts a snapshot with contacts still yields only the registration note), plus the new `InvoicePdfTest` test 'prints no contact line of the supplier, but its name, address, ids and registration note' (invoice and proforma).
3. **Task 3, commit 28e58c9.** `CashReceiptPdfData` no longer has a `footer` property, `cash-receipt.blade.php` no longer prints the footer block or has its CSS rule, and the settings hint `settings.invoicing.appearance.footer_hint` now says the footer prints on invoices, proformas and credit notes and not on the cash receipt. New `CashReceiptPdfTest` test covers no contacts, no footer, snapshots intact, invoice footer kept.

Snapshots (supplier, appearance), settings, e-mails and every other screen are untouched.

## Superseded UI-SPEC layout values

The compact box supersedes these layout values of the Phase 10 Surface H payment box and the Phase 11 card block (spacing and layout only, the content is kept in full):

- 24 pt top margin, now 12 pt
- 16 pt cell padding, now 10 pt vertical and 12 pt horizontal
- 96 pt QR column with an 88 pt image and 4 pt padding, now a 72 pt column and a 72 pt image with no padding
- one fact per row, now two per row
- card lead, button and URL stacked on separate lines, now lead and button on one row with the URL below

The credit note shares the invoice template and the same supplier party builder, so it also loses the supplier contact lines (intended: one document family, one supplier block).

## Deviations from Plan

None - plan executed exactly as written.

## Verification

Run in the worktree: `php -l` on every changed PHP file and the grep gates of all three tasks, all green. `scripts/check-sensitive.sh` and gitleaks ran in the pre-commit hook for each commit, clean.

Not run, by design (CLAUDE.md: no DB, migrations or full suite in a worktree): the Pest files, Pint and PHPStan from each task's second `<automated>` block, and the three human checks (shorter payment box with about 15 lines and a scannable QR, receipt without contacts and footer). These run after the merge into the HEAD branch in DDEV and are the verdict.

## Known Stubs

None.

## Threat Flags

None. The change only removes printed data and adds escaped Blade echoes (no raw `{!!` in either template, confirmed by the gates).

## Self-Check: PASSED

- Commits 38bf70d, d8ea054, 28e58c9 exist on the worktree branch.
- All listed files exist and are modified as described.
