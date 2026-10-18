---
phase: 10-invoicing
plan: 15
subsystem: invoicing-pdf
tags: [invoicing, pdf, dompdf, roboto, spayd, qr, snapshot, i18n]

requires:
  - phase: 10-invoicing
    provides: "Spayd, QrPng (plan 10-02) and DocumentPalette, DocumentImage::dataUri() hash-verified images (plan 10-03)"
  - phase: 10-invoicing
    provides: "Issued documents with frozen snapshots and appearance snapshot (plan 10-12)"
provides:
  - "Roboto 3.011 Regular and Bold (OFL-1.1) bundled in resources/fonts with OFL.txt, SHA-256 checked"
  - "InvoicePdf: render() / html() / fileName() of an issued document from its snapshot only, hardened Dompdf options, fonts installed into storage/framework/dompdf under a cache lock"
  - "InvoicePdfData: the snapshot-only view model (header, parties, dates, items, summary, payment box with SPAYD QR, notes, footer, signature, logo, palette, language, kind)"
  - "InvoicePageDecoration: accent stripes on every page, hairline and 'Strana n / total' / 'Page n / total'"
  - "resources/views/pdf/invoice.blade.php (D-24 layout) and lang/{cs,en}/invoice_pdf.php (UI-SPEC PDF strings, file name patterns)"
  - "Tests\\Support\\PdfText: Ghostscript txtwrite extraction and page count"
affects: [10-18, 10-20, 10-21, 10-32]

actuals:
  tokens: 17000
  tasks: 3
  commits: 2
plan_head_before: c58bb34ac8e87c0b6b5fcc02bb873d06e8e2f3fc
plan_head_after: 097b4fe681a1e80c6ae14aaf83324ee9bac92525
commits: 2

coverage:
  - id: D1
    description: "An issued CZK invoice renders to a PDF from its snapshot: heading, number, parties, lines, total, non-payer note, variable symbol, and a payment QR whose decoded payload is the SPAYD string with the total as AM:; no VAT line or VAT column, no 'Datum zdanitelneho plneni' for a non-payer"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#prints an issued CZK invoice with its parties, lines, total and a payment QR that decodes to its SPAYD string"
        status: pass
    human_judgment: false
  - id: D2
    description: "The non-payer document prints 'Dodavatel neni platce DPH.' / 'The supplier is not a VAT payer.'"
    requirement: "IN-08"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#the copy"
        status: pass
    human_judgment: false
  - id: D3
    description: "The document reads only its snapshot: renaming the client, changing supplier, accent, footer, logo, signature and adding a CNB list after issue leave html() unchanged; a changed or missing logo object fails render() and html()"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#the snapshot"
        status: pass
    human_judgment: false
  - id: D4
    description: "Czech and English copy has identical keys and every UI-SPEC PDF string; an English document prints English words, ISO dates and English money; page furniture (7 pt stripes, hairline, page label) is on every page of a multi-page document"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#the page furniture"
        status: pass
    human_judgment: false
  - id: D5
    description: "Roboto is embedded, Dompdf installs fonts into storage (never vendor) under a cache lock, a lost font index is rebuilt consistently, Czech diacritics survive in extracted text (skipped without Ghostscript)"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#the fonts"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php#the text of the PDF"
        status: pass
    human_judgment: false
  - id: D6
    description: "Print layout of the PDF (D-24: stripes, rows never split, total bar and payment box kept with content, page numbers) judged on 1, 15 and 60 line documents"
    verification: []
    human_judgment: true
    rationale: "Visual judgement of a print layout (UI-SPEC backstop row N10); left to the phase gate"

tech-stack:
  added: []
  patterns:
    - "Document PDFs read the issued row and hash-verified images only; any drift of a snapshotted image fails the render instead of printing something else"
    - "Page furniture as a Dompdf page_script invokable class (stripes drawn on the canvas), as in the work report"

key-files:
  created:
    - resources/fonts/Roboto-Regular.ttf
    - resources/fonts/Roboto-Bold.ttf
    - resources/fonts/OFL.txt
    - app/Domain/Invoicing/Pdf/InvoicePdf.php
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - app/Domain/Invoicing/Pdf/InvoicePageDecoration.php
    - resources/views/pdf/invoice.blade.php
    - lang/cs/invoice_pdf.php
    - lang/en/invoice_pdf.php
    - tests/Support/PdfText.php
    - tests/Feature/Invoicing/InvoicePdfTest.php
  modified:
    - scripts/sensitive-allowlist.txt

key-decisions:
  - "R-15-1 applied: 7 pt accent stripes top and bottom (D-24 wins over UI-SPEC U-9); plan 10-21 amends U-9"
  - "R-15-2 applied: Roboto 3.011 static TTF, both SHA-256 values verified; no .gitattributes row"
  - "R-15-3 applied: first render installs fonts under Cache::lock('kokpit:dompdf-fonts')"
  - "R-15-4 applied: renderer reads only the issued invoice and its items; logo and signature through DocumentImage::dataUri(path, sha256) of the appearance snapshot"
  - "R-15-5 applied: assertions run on the Blade HTML and structural PDF facts; Ghostscript text extraction is a secondary check that skips without gs"

patterns-established:
  - "Helper functions of the PDF test are local to the file; PdfText returns null when gs is missing"

requirements-completed: [IN-04, IN-08]

duration: not recorded (two sessions, the first blocked on the sensitive-content hook)
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 15: Invoice PDF Summary

**An issued invoice, proforma or credit note renders to an A4 Roboto PDF straight from its frozen snapshot (D-24 layout, Czech or English by the client's language, hash-verified logo and signature, SPAYD payment QR for CZK), with fonts installed into storage under a cache lock and Dompdf locked down to two chroot directories.**

## Performance

- **Duration:** not recorded; the work spanned two executor runs, the first stopped at the sensitive-content hook on the bundled font files and the second committed after the owner approved a narrow allowlist.
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 11 created, 1 modified (the allowlist)

## Accomplishments

- Bundled Roboto Regular and Bold (3.011, OFL-1.1) with the licence text; `InvoicePdf` points Dompdf `fontDir`, `fontCache` and `tempDir` at `storage/framework/dompdf`, so nothing is written under `vendor/`. Remote, PHP and JavaScript are off; chroot covers `resources/views/pdf` and `resources/fonts` only.
- `InvoicePdfData` builds the whole document from the issued row: parties, dates, items formatted through `Money::format` and `InvoiceQuantity::format` in the document language, summary, notes, footer, palette from the snapshotted accent, and the payment box, whose QR is generated locally with `Spayd::supports()` then `QrPng::dataUri(Spayd::forPayment(...))`.
- The template carries the D-24 layout; every value goes through Blade `{{ }}`. The non-payer note prints and no VAT line or column appears; the CNB rate, CZK total, internal note and linked time are never printed (U-16).
- Czech and English copy has identical keys, covers every UI-SPEC PDF string and the file name patterns; `InvoicePdf::fileName()` resolves them.
- Tests: the CZK tracer with a decoded SPAYD QR, markup canary, draft refusal, English and Czech documents, a snapshot-only test changing client, supplier settings, accent, footer, logo, signature and the CNB list after issue, a tampered or missing logo failing, per-page furniture on a 60-line document, font installation and index recovery, lock release, and Ghostscript checks for diacritics and page count (skipped without `gs`).

## Task Commits

1. **Task 1 (tracer): fonts, renderer, view model, template, copy and the first tests, plus the allowlist entry** - `a97c058` (feat)
2. **Tasks 2 and 3: final layout, snapshot-only and bilingual tests, page furniture tests, font lock tests, PdfText** - `097b4fe` (feat)

Tasks 2 and 3 were committed together because the previous run held them as one unstaged set on top of the Task 1 snapshot.

## Verification

- `ddev exec vendor/bin/pest --compact` re-run before committing: 5386 passed, 3 skipped, 0 failed.
- Pint `--test` and PHPStan clean on the committed state.
- `scripts/check-sensitive.sh` clean on both staged sets; lefthook sensitive-content and gitleaks ran and passed on both commits (no hook bypass).
- Font SHA-256 values checked at download by the previous run (R-15-2).

## For the owner's review: new `scripts/sensitive-allowlist.txt` entries

The scanner's `company-id` rule reads digit runs inside the binary glyph and table data of the bundled TTF files as company IDs and blocked the commit. The user approved a narrow allowlist at the orchestrator checkpoint; the orchestrator appended it and this plan committed it in `a97c058`. Two lines (one per font file, see the end of `scripts/sensitive-allowlist.txt`), each anchored on the exact font path and on an exact alternation of the matched values (16 digit-run values for Roboto-Regular, 5 for Roboto-Bold). The values are not repeated here because the scanner would read them as company IDs in this file.

- `company-id ;; ^resources/fonts/Roboto-Regular\.ttf$ ;; ^(<16 listed 8-digit values>)$`
- `company-id ;; ^resources/fonts/Roboto-Bold\.ttf$ ;; ^(<5 listed 8-digit values>)$`

A different font file, any other path, or other digit runs in these files still fail the scan, so a replaced font needs a new review. The scope is path- and value-anchored and there is no blanket exemption of `resources/fonts`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Sensitive-content hook blocked the font files**
- **Found during:** Task 1 commit (previous run)
- **Issue:** `company-id` matches digit runs in the TTF binaries.
- **Fix:** Narrow allowlist approved by the user (see the review section above); the plan's scope note anticipated this case.
- **Files modified:** `scripts/sensitive-allowlist.txt`
- **Commit:** a97c058

**2. [Design] Stripes drawn on the canvas (R-15-1 realisation)**
- **Issue:** the plan names `InvoicePageDecoration` for the hairline and page label only; the stripes at the page edge are full-bleed on every page.
- **Fix:** `InvoicePageDecoration` draws the 7 pt top and bottom stripes on the canvas of every page, next to the hairline and the page label. Not an HTML element in the template.
- **Files modified:** `app/Domain/Invoicing/Pdf/InvoicePageDecoration.php`
- **Commit:** a97c058, 097b4fe

**3. [Design] Layout adjustments for Dompdf**
- Items table without `table-layout: fixed` (the previous executor's layout choice for Dompdf).
- Party-id key column widths 36 / 58 pt.
- The closing block (total bar, payment box) is a one-row table.
- **Files modified:** `resources/views/pdf/invoice.blade.php`
- **Commit:** 097b4fe

**4. [Rule 1 - Bug] `fontsInstalled` handled only one path form**
- **Issue:** Dompdf writes an index entry either as an absolute path or relative to the font directory; the check must accept both to tell whether the fonts are installed.
- **Fix:** `fontsInstalled` resolves both absolute and relative paths.
- **Files modified:** `app/Domain/Invoicing/Pdf/InvoicePdf.php`
- **Commit:** 097b4fe

**5. [Rule 2 - Missing] Extra public `InvoicePdf::fileName()`**
- **Issue:** the plan lists `render()` and `html()` only, yet the file name patterns in the copy need a consumer; plan 10-20 (storing the PDF at issue) would otherwise duplicate the logic.
- **Fix:** public `fileName(Invoice $issued): string` reads the `file_name` pattern of the document language.
- **Files modified:** `app/Domain/Invoicing/Pdf/InvoicePdf.php`
- **Commit:** a97c058

**6. [Process] Tests for tasks 2 and 3 were written after the code**
- The previous run implemented all three tasks, then wrote the Task 2 and Task 3 tests, so those tests were not observed failing first. The Task 1 tracer test followed the plan.

**Total deviations:** 6 (1 blocking, 1 bug, 1 missing functionality, 2 design, 1 process). **Impact:** none on the plan's contracts.

## Known Stubs

None.

## Gaps and items left open

- The VAT-payer variant of the date strip and the VAT column branch in the template has no test here; plan 10-18 owns the VAT-payer, proforma and credit-note variants and their tests.
- The plan's human check (print layout of 1, 15 and 60 line documents, UI-SPEC backstop row N10) is left to the phase gate; no automated test judges the visual layout.

## Threat Flags

None. T-10-40 (markup/CSS in item text): Blade escaping, remote/PHP/JS off, two-directory chroot, data-URI images, canary test. T-10-41 (font writes): `fontDir` in storage, cache lock, vendor-untouched test. T-10-42 (PDF differs from issue): snapshot-only view model, hash-verified images, tests changing client, settings, logo and signature after issue. T-10-SC: no Composer change; fonts accepted only with the published SHA-256 values and OFL file bundled. No new endpoint or auth path.

## Notes for later plans

- Plan 10-18 completes the variants (VAT payer, proforma, credit note) in the same template and `InvoicePdfData`.
- Plan 10-20 stores the PDF at issue through `InvoicePdf::render()` and may use `fileName()`.
- Plan 10-21 amends UI-SPEC U-9 (stripes 7 pt, R-15-1).
- Plan 10-32 records the OFL licence note in the README.

## Self-Check: PASSED

- Created files exist: all 11 files listed under key-files.created are in the tree and committed.
- Commits `a97c058` and `097b4fe` are ancestors of HEAD.
