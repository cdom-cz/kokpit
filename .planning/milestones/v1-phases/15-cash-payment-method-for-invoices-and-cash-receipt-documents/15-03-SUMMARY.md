---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 03
subsystem: pdf
tags: [dompdf, pdf, refactor, invoicing]

requires:
  - phase: 10-invoicing
    provides: InvoicePdf, InvoicePdfData and the hardened Dompdf rendering that this plan extracts
provides:
  - "ClosedDompdf: the closed Dompdf engine (options, fonts, font lock, memory floor, render) shared by every issued-document PDF"
  - "PdfParts: party(), footer(), image(), text() as public static helpers shared by invoice and receipt PDFs"
  - "PdfEngineTest pinning the closed options and the parts' behaviour"
affects: [15-04, 15-09]

actuals:
  tokens: 9500
  tasks: 2
  commits: 4

tech-stack:
  added: []
  patterns:
    - "One closed Dompdf engine, document-specific page decoration passed as a page script"
    - "Shared PDF parts take the document's own translator closure"

key-files:
  created:
    - app/Domain/Invoicing/Pdf/ClosedDompdf.php
    - app/Domain/Invoicing/Pdf/PdfParts.php
    - tests/Feature/Invoicing/PdfEngineTest.php
  modified:
    - app/Domain/Invoicing/Pdf/InvoicePdf.php
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php

key-decisions:
  - "R-15-03-1: ClosedDompdf takes the options, font directory, font lock, memory floor and render from InvoicePdf; InvoicePdf keeps FONT_LOCK as an alias of ClosedDompdf::FONT_LOCK"
  - "R-15-03-2: PdfParts takes party/footer/image/text unchanged; InvoicePdfData keeps every other private helper (payment, dates, items, groupIban)"

patterns-established:
  - "Engine and parts live in App\\Domain\\Invoicing\\Pdf and are resolved from the container (InvoicePdf now takes ClosedDompdf in its constructor)"

requirements-completed: [P15-08]

coverage:
  - id: D1
    description: "Invoice PDF renders through the extracted ClosedDompdf with unchanged text"
    requirement: "P15-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfTest.php, tests/Feature/Invoicing/InvoicePdfVariantsTest.php (unchanged)"
        status: unknown
    human_judgment: false
  - id: D2
    description: "Closed Dompdf options (remote, PHP, JavaScript off; exact chroot) pinned by a test"
    requirement: "P15-08"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/PdfEngineTest.php#the closed engine"
        status: unknown
    human_judgment: false
  - id: D3
    description: "PdfParts party/footer/image/text behave as before and are reusable by the receipt PDF"
    requirement: "P15-08"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/PdfEngineTest.php#the PDF parts"
        status: unknown
    human_judgment: false

duration: 25min
completed: 2026-10-27
status: complete
commits: 4
plan_head_before: 194ad477c0ec09cbe93ed4848143111812e6bdc5
plan_head_after: 38dcd809aec294300ba47b6faf75ac9a4c439318
---

# Phase 15 Plan 03: Closed Dompdf engine and shared PDF parts Summary

**The hardened Dompdf rendering now lives once in `ClosedDompdf`, and the party block, footer, image and text helpers once in `PdfParts`, so the cash receipt PDF of 15-09 reuses them instead of copying them; the invoice PDF is untouched in behaviour.**

## Accomplishments

- `ClosedDompdf` (final) owns the closed options (remote access, PHP and JavaScript off; chroot `views/pdf` and `fonts`; font directory under `storage/framework/dompdf`), `fontsInstalled()`, the cache font lock, the 256M memory floor, `fontUrl()` and `render(string $html, ?callable $pageScript = null)`. Every option value moved verbatim.
- `InvoicePdf` is now a thin renderer: it builds the data and HTML, and calls the engine with `new InvoicePageDecoration(...)` as the page script. `InvoicePdf::FONT_LOCK` stays as an alias because `InvoicePdfTest` uses it. It no longer contains `new Options`.
- `PdfParts` (final, static) holds `party()`, `footer()`, `image()` and `text()` with bodies unchanged; `InvoicePdfData` calls `PdfParts::` (including the four `text()` calls in the payment box) and lost its private copies.
- `PdfEngineTest` pins the three safety flags off, the exact chroot, the default font and directories, a `%PDF-` render of an escaped page, the page script being called, the font URLs, the font-lock equality and the parts' behaviour.

## Task Commits

1. Task 1 (tracer) RED: `fcb2e74` test(15-03) failing test for the closed Dompdf engine
2. Task 1 GREEN: `155ca8b` feat(15-03) extract the closed Dompdf engine from InvoicePdf
3. Task 2 RED: `0d541dc` test(15-03) failing tests for the shared PDF parts
4. Task 2 GREEN: `38dcd80` feat(15-03) move party, footer, image and text into PdfParts

`commits: 4` is measured with `git rev-list --count` from the base `194ad47` to `38dcd80`; the SUMMARY commit follows it. No refactor commit was needed.

## TDD Gate Compliance

RED commits (`fcb2e74`, `0d541dc`) precede their GREEN commits (`155ca8b`, `38dcd80`) in the history. The RED runs were NOT executed (see Verification below): the worktree has no `vendor/` and the Feature suite needs the shared database, which project rules forbid touching from a worktree. The RED expectation is by construction: `ClosedDompdf` and `PdfParts` did not exist at the RED commits, so `app(ClosedDompdf::class)` and `PdfParts::...` could not resolve and every target test would fail on the planned assertions' subject. No `gsd_run check tdd-red-evidence` record was produced, since there was no real test run to record. This is a gap against the strict RED-evidence contract and is flagged for the post-merge run.

## Verification

Run in the worktree (cheap, DB-free):

- `php -l` on all five PHP files: no syntax errors.
- `pint --test` (main checkout's binary, worktree files): passed for `app/Domain/Invoicing/Pdf` and `tests/Feature/Invoicing/PdfEngineTest.php`.
- A standalone PHP script exercised `PdfParts::text/footer/party/image` against fictional inputs and produced exactly the values `PdfEngineTest` asserts.
- Acceptance greps: `new Options` in InvoicePdf = 0; `private static function party` in InvoicePdfData = 0; `ClosedDompdf` in InvoicePdf = 3; three closed flags present in ClosedDompdf.
- `scripts/check-sensitive.sh` and gitleaks clean on every commit (lefthook hook ran, not bypassed).

NOT run (to do after the merge into the HEAD branch, in DDEV):

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Arch` (includes `PdfEngineTest`, `InvoicePdfTest`, `InvoicePdfVariantsTest`)
- `ddev exec vendor/bin/pint --test` over the whole repo
- `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (a worktree PHPStan run was impossible: larastan needs the worktree `vendor/` and the application bootstrap; PHPStan level 8 typing of `?callable $pageScript` passed to `Canvas::page_script()` is the one thing to watch)

## Deviations from Plan

None in the code. One process deviation: the plan's verify commands (Pest, PHPStan) were not run, because the project rule "in a worktree run only cheap checks, no shared state" overrides them; they move to the post-merge verification.

## Decisions Made

- `InvoicePdf` gained a constructor taking `ClosedDompdf` (container-resolved; every call site uses `app(InvoicePdf::class)`, none uses `new InvoicePdf`).
- The page script is typed `?callable` with a docblock `(callable(int, int, mixed, mixed): mixed)|null`, matching the `InvoicePageDecoration::__invoke` shape without importing Dompdf types into the engine signature.

## Known Stubs

None.

## Threat Flags

None. T-15-42 (extraction weakening a Dompdf option) is mitigated: options moved verbatim and pinned by `PdfEngineTest`. `WorkReportPdf` was not touched.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Pdf/ClosedDompdf.php
- FOUND: app/Domain/Invoicing/Pdf/PdfParts.php
- FOUND: tests/Feature/Invoicing/PdfEngineTest.php
- FOUND commits: fcb2e74, 155ca8b, 0d541dc, 38dcd80
