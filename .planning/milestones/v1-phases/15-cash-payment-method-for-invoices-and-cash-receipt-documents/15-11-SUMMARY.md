---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 11
subsystem: invoicing
tags: [cash-receipt, partner, signed-route, relation-manager, audit-harness]
requires:
  - phase: 15-06
    provides: CashReceipt model, CashReceiptPolicy and the model rows of PartnerMatrix and ChannelMatrix
  - phase: 15-08
    provides: lang/cs/cash_receipts.php and the receipts issued through RecordPayment
  - phase: 15-09
    provides: the stored receipt PDF (document owned by the receipt, linked by pdf_document_id)
provides:
  - route filament.admin.files.cash-receipt (signed, user-bound, throttled, no-referrer)
  - CashReceiptPdfUrl (signed link builder) and CashReceiptPdfController (7-step streamed download)
  - PartnerCashReceiptsRelationManager (read-only list with download) on the Partner invoice detail
  - cash_receipts.partner.* Czech texts
  - audit harness rows for the route and the screen (RouteWalkTest, PartnerMatrix, ChannelMatrix, ChannelProbe)
affects: [15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "A second PDF route mirrors InvoicePdfController: signed user check, UUID shape, scoped query, policy, owner-checked document read as system, media, object"
    - "A Partner relation manager on a Partner resource adds its own AccessRule and ANDs a client and document-state check onto canViewForRecord"
key-files:
  created:
    - app/Http/Controllers/CashReceiptPdfController.php
    - app/Domain/Invoicing/Support/CashReceiptPdfUrl.php
    - app/Filament/Partner/Resources/PartnerInvoiceResource/RelationManagers/PartnerCashReceiptsRelationManager.php
    - tests/Feature/Invoicing/CashReceiptPartnerTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Filament/Partner/Resources/PartnerInvoiceResource.php
    - lang/cs/cash_receipts.php
    - tests/Feature/Invoicing/PartnerInvoiceResourceTest.php
    - tests/Isolation/RouteWalkTest.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/ChannelMatrix.php
    - tests/Support/ChannelProbe.php
    - tests/Audit/ChannelMatrixTest.php
key-decisions:
  - "R-15-11-1 to R-15-11-6 applied as written"
  - "The relation manager is hidden for credit notes (kind->carriesPayment()) as well as for drafts and for the Admin"
  - "The cancel reason needs no filtering on the screen: it is not on the receipt row, so no column can show it"
requirements-completed: [P15-11, P15-12]
status: complete
duration: not measured
completed: 2026-10-27
actuals:
  tokens: 45000
  tasks: 2
  commits: 3
commits: 3
plan_head_before: 69a1a9c470cf8f28058d6c0ba9b583f26bc0b08f
plan_head_after: 65c4f11
---

# Phase 15 Plan 11: Partner cash receipt list and download route Summary

A Partner opens the own issued invoice or proforma, sees its cash receipts (number, date received, printed amount, valid or cancelled badge) and downloads each PDF through a signed, user-bound route; another client's receipt, an unknown or malformed id and a receipt without a file are all 404.

## What was built

- **Route and controller (D-13).** `files.cash-receipt` sits in the `authenticatedRoutes` closure after `files.invoice`, with the same middleware (`SetNoReferrerPolicy`, `signed`, `throttle:document-files`). `CashReceiptPdfController` checks in a fixed order: user under the signature (403), UUID shape (404), Partner-scoped receipt (404), `Gate::authorize('view')`, then as system the document owned by the receipt (`owner_type` `cash_receipt`, `owner_id` the receipt), its media row and the object on the disk (each 404). Headers are `Content-Type`, `Content-Length`, attachment disposition, `nosniff` and `private, no-store`. No storage path or bucket URL leaves the application.
- **URL helper.** `CashReceiptPdfUrl::download($receipt, $user)` signs with the configured TTL and carries `u` under the signature, like `InvoicePdfUrl`.
- **Partner screen (D-13, AS-4).** `PartnerCashReceiptsRelationManager` (`#[AccessRule(Audience::PartnerAllowed, ...)]`, read-only, no filters, search, header or bulk actions). Columns `number`, `received_on`, `amount`, `state`. A cancelled receipt stays listed with the "Zrušen" badge and no reason. The one row action `download` re-reads the receipt through the owner invoice and redirects to the signed URL at click time; the link is never rendered into HTML. `canViewForRecord` also needs a Partner with a client and an issued invoice or proforma. `PartnerInvoiceResource::getRelations()` now returns this manager.
- **Audit harness (R-15-11-6).** `RouteWalkTest` signs and walks the new route (own 200, foreign 404, client-less Partner 403/404); `PartnerMatrix` gained the panel-class row and the three field surfaces; `ChannelMatrix` opens `CashReceipt` on `ui` and `downloads` as `foreign`.

## Task commits

| Task | Commit | Content |
|------|--------|---------|
| 1 (tracer, RED) | eeaa5b8 | failing tests for the list and the download route |
| 1 (tracer, GREEN) | 2fda268 | route, controller, URL helper, relation manager, resource, Czech texts |
| 2 | 65c4f11 | route walk, PartnerMatrix, ChannelMatrix, ChannelProbe |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ChannelProbe and ChannelMatrixTest also needed the new route**
- **Found during:** Task 2
- **Issue:** `ChannelProbe::walkUrl()` throws on an unknown route parameter, and the UI walk of the channel audit visits every panel route, so the new `{cashReceipt}` route would have failed `tests/Audit/ChannelMatrixTest.php`. Neither file is in the plan's `files_modified`.
- **Fix:** `ChannelProbe` signs the route (`walkUrl`), attributes it to `CashReceipt` (`walkOwners`), treats it as a file route, stores a fictional PDF for the canary receipt of each side next to the invoice PDFs, and adds the receipt PDFs to the download requests and `downloadUrls()`. `ChannelMatrixTest`'s Admin control lists the two new URLs. The `NA_DOWNLOADS` wording in `ChannelMatrix` names the new route.
- **Files modified:** tests/Support/ChannelProbe.php, tests/Audit/ChannelMatrixTest.php, tests/Support/ChannelMatrix.php
- **Commit:** 65c4f11

**2. [Rule 1 - Bug in the plan's test wording] "no lock" assertion made specific**
- **Issue:** a lowercase search for "lock" in the page HTML matches CSS classes such as `block`.
- **Fix:** the page test asserts the absence of `lock_state`, `uzamčen` and `cancel_reason` instead.

## Verification status

The worktree has no database and no `vendor/`, and project rules forbid migrations and the full test run in a worktree, so these gates were NOT run here and must run after the merge, in DDEV:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Isolation tests/Arch tests/Audit`
- `ddev exec composer partner-audit`
- `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Run here: `php -l` on every touched file and `pint --test` (binary of the main checkout) on `app`, `tests/Feature/Invoicing`, and the touched support, isolation and audit files, all clean; `scripts/check-sensitive.sh` clean on each commit.

Points to watch in the post-merge run: the new tests were written but never executed (Filament table-test helper names such as `assertTableColumnFormattedStateSet` and `callAction(TestAction::make('download')->table(...))` follow existing tests in the repo); the "receipt without a PDF" test disables `cash_receipts_pdf_link_guard` inside the test transaction (mutation style, as the invoice route test does).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-15-33 to T-15-37 are mitigated as listed: scoped query, policy, owner-checked document read, signature bound to the user, streamed bytes with private headers, columns pinned in `partnerFields()`, `throttle:document-files`).

## Self-Check: PASSED

- FOUND: app/Http/Controllers/CashReceiptPdfController.php
- FOUND: app/Domain/Invoicing/Support/CashReceiptPdfUrl.php
- FOUND: app/Filament/Partner/Resources/PartnerInvoiceResource/RelationManagers/PartnerCashReceiptsRelationManager.php
- FOUND: tests/Feature/Invoicing/CashReceiptPartnerTest.php
- FOUND commits: eeaa5b8, 2fda268, 65c4f11
