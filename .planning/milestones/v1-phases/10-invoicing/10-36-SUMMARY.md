---
phase: 10-invoicing
plan: 36
subsystem: invoicing-pdf-lifecycle
tags: [invoicing, pdf, documents, deletion, confirmation, regeneration]
status: complete

requires:
  - phase: 10-invoicing
    provides: "StoreGeneratedDocument and the stored invoice PDF (plan 10-20), InvoicePdf snapshot render (plans 10-15, 10-18), the invoice_admin.pdf_document_id link with its FK ON DELETE SET NULL and link guard (plan 10-06)"
provides:
  - "DeleteDocument::handle(..., bool $confirmedIssuedPdf = false): the PDF of an issued document is refused without the flag (DomainException with invoices.errors.pdf_unconfirmed); with it the row delete lets the FK clear the Admin-only link"
  - "DeleteDocument::isIssuedPdf(): the shared question for the screens"
  - "RegeneratePdf: creates the missing PDF again from the frozen snapshots, links it on invoice_admin, never replaces an existing PDF, maps every failure to 'PDF se nepodařilo vytvořit'"
  - "DocumentActions delete: extended modal with the explicit tick and a disabled-until-ticked 'Smazat PDF'; bulk delete skips issued PDFs and reports the skipped count"
affects: [10-37, 10-38]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2
plan_head_before: c3c3a59407512b22797870a1b7ea895e6fdfd339
plan_head_after: 81785d42921ad6cc91b308e4ef601ce57dc15d0b
commits: 2

coverage:
  - deliverable: "DeleteDocument refuses the PDF of an issued document without the confirmation flag and deletes it with the flag; the FK clears invoice_admin.pdf_document_id and the invoices row is byte-identical"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/RegeneratePdfTest.php#deleting the PDF of an issued document"
        status: pass
    human_judgment: false
  - deliverable: "RegeneratePdf stores a new PDF from the snapshot, links it, and its HTML equals the original render; an existing PDF, a draft and a Partner are refused"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/RegeneratePdfTest.php#creating the PDF again"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/RegeneratePdfTest.php#refusals and failures"
        status: pass
    human_judgment: false
  - deliverable: "A render failure or a changed or missing logo object keeps the state and reports 'PDF se nepodařilo vytvořit' with no document and no object stored"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/RegeneratePdfTest.php#never prints another logo: a changed logo object fails the regeneration cleanly"
        status: pass
    human_judgment: false
  - deliverable: "The delete screens: extended modal with the tick (submit disabled until ticked, refusal without it, toast 'PDF bylo smazáno'), plain modal for other invoice files, bulk delete skipping issued PDFs with a skipped count"
    requirement: "DO-03"
    verification:
      - kind: test
        ref: "tests/Feature/Documents/InvoicePdfDeletionTest.php"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Domain/Invoicing/Actions/RegeneratePdf.php
    - tests/Feature/Invoicing/RegeneratePdfTest.php
    - tests/Feature/Documents/InvoicePdfDeletionTest.php
  modified:
    - app/Domain/Documents/Actions/DeleteDocument.php
    - app/Filament/Support/DocumentActions.php
    - lang/cs/documents.php
    - tests/Feature/Invoicing/SendInvoiceEmailTest.php

key-decisions:
  - "R-36-1 applied: the rule covers only the document named by invoice_admin.pdf_document_id; other files on an invoice delete as usual. The check runs under the row lock of the invoice_admin row inside the delete transaction, and only for documents with owner_type invoice"
  - "R-36-2 applied: RegeneratePdf works only when pdf_document_id is null; an existing PDF is refused (DomainException), so no two stored PDFs of one document exist"
  - "The confirmation is enforced in the domain Action and the UI (accepted() checkbox plus a submit disabled by the live tick), not by UI hiding alone"
  - "The tick is validated server-side (Checkbox accepted) so a crafted submit without it is refused even though the button is only disabled in the browser"

patterns-established:
  - "A refusal (DomainException/LogicException) keeps its own reason; every other failure of a regeneration collapses to one fixed message with the cause on the exception chain only"

requirements-completed: [IN-04]

duration: "45 min"
completed: 2026-10-17
---

# Phase 10 Plan 36: PDF deletion confirmation and regeneration Summary

**Deleting the stored PDF of an issued document now needs an explicit tick on every screen and a domain flag, and RegeneratePdf recreates the same PDF from the frozen snapshot when it is missing, or fails without touching any state.**

## Accomplishments

- `DeleteDocument` takes `confirmedIssuedPdf`; without it the PDF named by `invoice_admin.pdf_document_id` is refused with `invoices.errors.pdf_unconfirmed` and row, media row and object stay. With it the row delete lets the `ON DELETE SET NULL` foreign key clear the link, and the issued `invoices` row is unchanged (compared column by column in the test).
- `RegeneratePdf` (own transaction, row locks on the invoice read and on `invoice_admin`) renders through `InvoicePdf`, stores through `StoreGeneratedDocument` (rollback object cleanup) and links the new document. Refuses a Partner, a draft and a document that still has its PDF. A render failure, a changed logo object (SHA-256 mismatch) and a missing logo object all end in "PDF se nepodařilo vytvořit" with no document, no object and the link still null. The regenerated HTML equals the original render.
- `DocumentActions::delete()` shows the UI-SPEC extended modal for an issued PDF (heading, body with name and number, tick "Rozumím, chci smazat PDF vystaveného dokladu", submit "Smazat PDF" disabled until ticked, cancel "Ponechat PDF", toast "PDF bylo smazáno"); other files keep the stock modal. `bulkDelete()` skips issued PDFs, deletes the rest and adds a body line with the skipped count.
- Copy added under `documents.actions.delete_issued_pdf.*`, including the regeneration failure strings.

## Task Commits

1. Task 1 (tracer): `5785251` feat(10-36): delete the PDF of an issued document only with the explicit flag and create it again from the snapshot
2. Task 2: `81785d4` feat(10-36): ask for the explicit tick before deleting an issued PDF, skip it in bulk delete

Tracer gate: the tracer tests, Pint and PHPStan were green before expansion (`Tracer verified end-to-end`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An existing test deleted the PDF through the Action without the new flag**
- **Found during:** Task 2 (documents + invoicing suites)
- **Issue:** `SendInvoiceEmailTest` ("fails a row with attachment_missing when the document has no PDF link") calls `DeleteDocument` on the invoice PDF and now hit `pdf_unconfirmed`.
- **Fix:** the test passes `true` for the confirmation flag; it models the Admin having confirmed.
- **Files modified:** tests/Feature/Invoicing/SendInvoiceEmailTest.php
- **Commit:** 81785d4

**2. [Scope] The failure message key was added in Task 1 instead of Task 2**
- `RegeneratePdf` (Task 1) needs `documents.actions.delete_issued_pdf.regenerate_failed`, so `lang/cs/documents.php` was committed with Task 1. Task 2 needed no further lang change.

**3. [Scope] No invoice Soubory tab exists yet**
- The plan phrases the screen test as "a Soubory tab (Livewire test)". The invoice detail tab is built by a later plan, so the shared `DocumentActions::delete()` is exercised through the central document listing, which uses the same action and is where an invoice PDF is already visible to the Admin. The invoice tab will inherit the behavior without change.

**4. [Scope] The Task 2 refusal and failure tests were green on the first run**
- `RegeneratePdf` was written complete in Task 1, so its refusal and failure tests (kept for Task 2) passed immediately; only the UI tests had a real RED.

**Total deviations:** 4 (1 Rule 1, 3 scope). **Impact:** none on the contract of the plan.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite): 5671 passed, 3 skipped (none in this plan)
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Documents tests/Isolation`: green after the one test fix above
- Pint `--test` and PHPStan: clean
- `scripts/check-sensitive.sh` and the hook (gitleaks): clean on both commits
- Acceptance greps: `confirmedIssuedPdf` and `pdf_unconfirmed` in `DeleteDocument`, `pdf_document_id` and `lockForUpdate` in `RegeneratePdf`, both strings in `lang/cs/documents.php`

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. T-10-54 (issued PDF deleted without confirmation) and T-10-88 (regenerated PDF differing from the issued one) are mitigated and tested; no new endpoint, auth path or schema.

## Human Check Pending

The disabled-until-ticked behavior of "Smazat PDF" is asserted on the server-side action state in Livewire tests; the browser rendering of the disabled button is part of the phase's visual check.

Ready for the next plan of the phase. The "Vytvořit PDF znovu" button on the invoice detail (Surface H) calls `RegeneratePdf` and is built with the detail plans.

## Self-Check: PASSED

- RegeneratePdf.php, RegeneratePdfTest.php and InvoicePdfDeletionTest.php exist; commits 5785251 and 81785d4 are ancestors of HEAD.
