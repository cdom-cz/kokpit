---
phase: quick-261028-krr
plan: 01
subsystem: invoicing
tags: [invoice-items, unit, postgres, pdf]
requires: [IN-01, IN-02]
provides:
  - "Optional unit on invoice and proforma lines, stored as NULL"
  - "InvoiceItem::printedQuantity() as the single quantity-with-unit formatter"
affects: [invoicing, invoice-pdf, admin-invoice-detail, partner-invoice-detail]
key-files:
  created:
    - database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php
  modified:
    - app/Domain/Invoicing/Support/InvoiceItemInput.php
    - app/Domain/Invoicing/Models/InvoiceItem.php
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - app/Filament/Support/InvoiceColumns.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - lang/cs/invoices.php
    - tests/Feature/Invoicing/DraftLifecycleTest.php
    - tests/Feature/Schema/InvoiceTablesTest.php
    - tests/Feature/Invoicing/InvoicePdfTest.php
decisions:
  - "Blank or absent unit is normalised to null, never stored as an empty string"
  - "Editor keeps its ks default on new lines; the Admin may clear it"
status: complete
commits: 2
plan_head_before: 05458f7f2d5db1c5b29cec6411bfca922a5f39fa
plan_head_after: 09ae55d49fb513b676981af7783ee3759249856e
actuals:
  tokens: 9000
  tasks: 2
  commits: 2
completed: 2026-10-28
---

# Phase quick-261028-krr Plan 01: Make the unit optional on invoice lines Summary

The unit of an invoice or proforma line is now optional: a blank, whitespace-only, null or missing unit is stored as NULL, and every display site prints such a line as the bare quantity.

## What changed

- **Migration** `2026_10_28_000400_make_invoice_item_unit_optional`: drops NOT NULL on `invoice_items.unit` and relaxes `invoice_items_unit_check` to `unit IS NULL OR btrim(unit) <> ''`. `down()` refuses while a NULL unit exists, otherwise restores the original column and check.
- **InvoiceItemInput**: `$unit` is `?string`. A separate validity check keeps a unit longer than 16 characters, or a non-string value, refused with `items.<n>.unit`. A valid blank or absent unit becomes `null`, so the credited-line freeze in `UpdateIssuedDocument` compares null with null.
- **Czech copy**: `invoices.errors.item_unit` now reads "Jednotka může mít nejvýše 16 znaků." and no longer asks the user to enter a unit.
- **InvoiceItem**: `@property string|null $unit` and the new `printedQuantity(string $locale)` helper. PDF data, Partner detail columns, Admin detail lines and the credit-note dialog all use it. The credit-row shape in `ViewInvoice` replaces `original` and `unit` with one `printed` string.
- **Tests**: new DraftLifecycleTest case (invoice and proforma datasets, four no-unit forms plus a trimmed unit), the bad-lines case now uses an over-long unit at index 4, InvoiceTablesTest allows NULL and refuses `''` and `'   '`, and a new InvoicePdfTest case covers invoice and proforma output and `printedQuantity`.

## Verification status

Only the worktree-safe gates ran: `php -l` on every touched file, the plan's grep gates (both tasks) and `scripts/check-sensitive.sh` (clean). The lefthook hook (sensitive-content, gitleaks) passed on both commits.

NOT run, to be done after the merge into the HEAD branch (CLAUDE.md: no migrations or DB tests in a worktree):
- `ddev exec php artisan migrate --no-interaction`
- Pest on DraftLifecycleTest, InvoiceTablesTest, UpdateIssuedDocumentTest, DraftEditorTest, IssuedEditorTest, InvoicePdfTest, InvoiceDetailTest, PartnerInvoiceResourceTest, InvoicePdfVariantsTest
- `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse`
- Task 2 human check (clear Unit in a draft, issue, view PDF / Admin / Partner detail)

## Deviations from Plan

None - plan executed as written. The plan allowed `original` to be kept in the credit-row shape if another reader existed; a grep showed none, so both keys were replaced with `printed`. The now-unused `InvoiceQuantity` import was removed from `InvoiceColumns.php`.

## Known Stubs

None.

## Threat Flags

None.

## Commits

- 2aa2b2e: feat(quick-261028-krr): make the unit of an invoice line optional
- 09ae55d: feat(quick-261028-krr): print a line without a unit as the quantity alone

## Self-Check: PASSED

All created and modified files exist; both commits are ancestors of HEAD.
