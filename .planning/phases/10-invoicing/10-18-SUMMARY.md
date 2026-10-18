---
phase: 10-invoicing
plan: 18
subsystem: invoicing-pdf-variants
tags: [invoicing, pdf, proforma, credit-note, regeneration, tests]
status: complete

requires:
  - phase: 10-invoicing
    provides: "InvoicePdf, InvoicePdfData, the invoice template and the cs/en copy with all document-kind branches (plan 10-15)"
provides:
  - "tests/Feature/Invoicing/InvoicePdfVariantsTest.php: 15 tests proving the proforma, credit-note, foreign-currency, English and optional-block variants of the PDF and the regeneration equivalence of an issued document (HTML string, page count, extracted text)"
affects: [10-23]

actuals:
  tokens: 4000
  tasks: 3
  commits: 1
plan_head_before: f7112420fe2e570559b2da2daebb8b3c4ffdf37f
plan_head_after: a41a795ac3518d4b8dc92315626f5351e9acf244
commits: 1

coverage:
  - deliverable: "A proforma PDF carries ZALOHOVA FAKTURA / PROFORMA INVOICE, the line 'Nejde o danovy doklad.' / 'This is not a tax document.' and the payment box with a QR in CZK"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#the proforma"
        status: pass
    human_judgment: false
  - deliverable: "A credit-note PDF carries DOBROPIS / CREDIT NOTE, negative quantity and amounts, 'Opravuje doklad c. :number' instead of a due date, DOBROPIS CELKEM / CREDIT TOTAL, the no-payment sentence, and never a QR, a variable symbol or a payment sentence"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#the credit note"
        status: pass
    human_judgment: false
  - deliverable: "A EUR document prints the payment box without a QR, with IBAN and BIC; logo, signature, note and footer appear only when set; a 300-character footer wraps inside one page"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#a document in another currency"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#the optional blocks"
        status: pass
    human_judgment: false
  - deliverable: "Regenerating the PDF of an issued document yields the identical HTML string, page count and extracted text although the clock, accent, footer, logo, signature, client and rates changed; 1-, 25- and 60-line documents render with page numbers 1 / n through n / n"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#regeneration"
        status: pass
    human_judgment: false
  - deliverable: "Visual print layout of the variants and of a list spilling to a second page (header row, stripes, page numbers)"
    requirement: "IN-04"
    human_judgment: true
    rationale: "Plan backstop N10 (zero-one-many) names a visual check of the printed PDFs; no test asserts the rendered look"

key-files:
  created:
    - tests/Feature/Invoicing/InvoicePdfVariantsTest.php
  modified: []

key-decisions:
  - "R-18-1 and R-18-2 applied as planned: credit notes print the stored negative sign with a positive unit price; equivalence is asserted on HTML, page count and Ghostscript text, never on PDF bytes"
  - "No production change was needed: plan 10-15 already delivered every template and view-model branch of this plan, so 10-18 closes as the proof plan"

requirements-completed: [IN-04, IN-06]

duration: "35 min"
completed: 2026-10-17
---

# Phase 10 Plan 18: PDF variants and regeneration equivalence Summary

**The proforma, credit-note, EUR and English PDF variants and the optional blocks are proven in 15 tests, and an issued document regenerated after every setting, image, client and rate changed yields the identical HTML, page count and text.**

## Accomplishments

- Proforma: heading, "Nejde o danovy doklad." / "This is not a tax document.", the CZK payment box with exactly one (QR) image, file name `zalohova-faktura-...`.
- Credit note (built with the existing `InvoiceFixtures::stornoed()` full-storno fixture): "-2,5 h", "-2 500,00 Kc" with a positive unit price, "Opravuje doklad c." with the original's number instead of a due date, DOBROPIS CELKEM, the no-payment sentence; no image, no variable symbol, no payment sentence, no K UHRADE bar; English copy and PDF render checked.
- EUR invoice: no QR, IBAN and BIC printed, money as EUR in Czech and English.
- Optional blocks: logo and signature (stored through DocumentImage before issue), note and footer printed when set, absent otherwise (one image only, no `printed-note`, `footer` or `signature` block); a 300-character footer keeps all its words on one page.
- Regeneration (D-23): HTML equal (`toBe($first)`), equal page count and equal Ghostscript text after a one-day clock move and changes of client, rates, accent, footer, logo and signature; no clock dependence in the HTML; 1-, 25- and 60-line documents render with page labels `Strana k / n` for every page.

## Task Commits

1. Tasks 1-3 (one test file): `a41a795` test(10-18): proforma, credit note, foreign-currency and optional-block PDF variants and the regeneration-equivalence proof

## Deviations from Plan

### Auto-fixed Issues

None needed in production code.

### Deviations

**1. [Scope] Template and view-model branches already existed**
- **Found during:** Task 1 (read_first)
- **Issue:** Plan 10-15 had already built the proforma/credit-note/non-CZK/optional-block branches, the copy keys (checked by the 10-15 copy tests) and the view model. The plan's step "Template and view-model branches" had nothing left to add; the tests written first passed straight away (no RED phase).
- **Fix:** None; the new tests are the deliverable.
- **Commit:** a41a795

**2. [Scope] View-model property names differ from the plan's interface list**
- **Issue:** Acceptance criterion `grep logoDataUri/signatureDataUri app/Domain/Invoicing/Pdf/InvoicePdfData.php` fails: the view model exposes `logo` and `signature` (array with `uri`, `width`, `height`), `payment` (null for a credit note, replacing `hasPaymentBox`) and the corrected number inside the `dates` cells (replacing `correctsNumber`). The behaviour in the contract is met and tested; adding redundant duplicate properties only to satisfy the grep was not done.
- **Impact:** Only the grep acceptance criterion; no behavioural gap.

**3. [Process] Single commit for three tasks**
- All three tasks only add to one new test file and no production file changed, so they are committed together.

**Total deviations:** 3 (all scope or process, no production change). **Impact:** none on behaviour.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/InvoicePdfTest.php`: 67 passed
- Pint `--test` and PHPStan: clean
- Full suite `ddev exec vendor/bin/pest --compact`: 5441 passed, 3 skipped (none in this plan; Ghostscript was available)
- `scripts/check-sensitive.sh` and the hook (gitleaks): clean
- Acceptance greps for copy strings (`Opravuje doklad c. :number`, `Corrects document no. :number`, `Nejde o danovy doklad.`), `pageCount` and `toBe($first)` pass; the `logoDataUri`/`signatureDataUri` grep fails by design (deviation 2)

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. T-10-48 and T-10-49 are covered: images reach the PDF only through DocumentImage (existing 10-15 tests) and the equivalence test changes settings, images, client and rates between renders.

## Human Check Pending

Open a fictional proforma, credit note and EUR invoice in English, and a long list spilling to a second page, to check the printed layout (header row, stripes, page numbers) per the plan's human-check.

Ready for the next plan of the phase.

## Self-Check: PASSED

- tests/Feature/Invoicing/InvoicePdfVariantsTest.php exists; commit a41a795 is an ancestor of HEAD.
