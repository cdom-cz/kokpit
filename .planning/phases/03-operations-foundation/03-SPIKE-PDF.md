# Phase 3 Spike: PDF engine for the work report and the invoice PDF

Status: tracer recorded, full comparison pending (Task 2 of plan 03-01 fills the pending sections).
Decisions served: D-14 (PDF engine spike), D-16 (spikes never enter the repository), FND-19 (PDF half).

## Context

Phase 8 renders a work report (multi-page table, Czech text) and Phase 10 renders the invoice PDF with a SPAYD-style payment QR code. Both need one PDF engine. Two candidate routes exist: Dompdf (pure PHP, no external process) and a Chromium engine (the engine `spatie/laravel-pdf` can drive through Browsershot). The decision is taken on measured evidence, and this record is its only artefact in the repository.

All spike code lives outside the repository in `~/kokpit-spikes/pdf` with its own `composer.json`. Nothing from that directory (code, fixtures, generated PDFs) is committed, and the application's `composer.json`, `composer.lock` and `app/` are untouched by this plan (D-16).

All data is fictional: Czech pangram text, `Example s.r.o.`, company ID `12345678`. The payee account in the QR payload is assembled at runtime from placeholder fragments with a computed mod-97 check digit; no account number is written into any file.

## Candidates

| Candidate | What is measured | Notes |
|-----------|------------------|-------|
| Dompdf | `dompdf/dompdf` used directly in-process with DejaVu Sans | pending: version recorded in Task 2 |
| Chromium engine | pending: path recorded in Task 2 | Browsershot needs `puppeteer`, which is not in the research Package Legitimacy Audit |

## Criteria

Written down before the full comparison is measured (research Pattern 12 decision rule):

1. Dompdf is chosen unless a required layout fails or text extraction shows missing glyphs. Required layout means: a header that repeats on every page, page numbers on every page, and a QR code that stays sharp at scan size.
2. The Chromium engine is chosen only if Dompdf fails at least one criterion and the Chromium engine runs within the Zerops container limits.
3. Per-engine checks, all by script (`pdfinfo`, `pdftotext`, `pdffonts`, `pdftoppm`):
   - page count at least 3 for a 90-row table;
   - Czech diacritics: `pdftotext` output of every page contains the full test string `ŘŠČŽÝÁÍÉŮÚĚŇŤĎ řščžýáíéůúěňťď`;
   - no unembedded font in `pdffonts`;
   - the header line is present in the text of every page, and `Strana N` is present on page N;
   - the QR code, rasterised at 150 dpi, decodes (with the reader built into `chillerlan/php-qrcode` v5) to exactly the input payload;
   - wall time (median and maximum over 10 runs), peak memory, file size;
   - runtime requirements on Zerops and licence compatibility.
4. A failed criterion means the decision is recorded as `pending owner` with the failing criterion and the next step.

## Method

- Spike directory `~/kokpit-spikes/pdf` (outside the checkout, `git rev-parse` there fails), host PHP 8.5.11, Composer packages limited to the research audit rows (`dompdf/dompdf`, `spatie/laravel-pdf`, `spatie/browsershot`) plus `chillerlan/php-qrcode` (already in the application lock through Filament). No `npm install` was run.
- `bench.php --engine=dompdf|chromium --rows=N --runs=N --out=DIR` renders one fixed HTML template (DejaVu Sans, fixed header, page counter footer, table with the Czech test string in every row, QR as a PNG data URI) and prints one JSON object with the verdicts and numbers.
- The QR payload is SPAYD-shaped (`SPD*1.0*ACC:...*AM:...*CC:CZK*X-VS:...*MSG:...`). The account is built at runtime from placeholder fragments with a computed check digit.
- QR decode runs on a crop of the QR region of the 150 dpi page raster: the full-page raster (1241 x 1754 px) exhausts PHP's default 128 MB memory limit inside the chillerlan reader, so decoding the full page is not practical with the default limit. This is a limit of the throwaway decoder, not of either engine.
- Memory for Dompdf is `memory_get_peak_usage(true)` of the rendering process, captured before the verification step runs.

## Measurements

Tracer run (Dompdf only, 20 rows, 1 run, host macOS, PHP 8.5.11):

| Engine | Rows | Pages | Diacritics | Header | Page numbers | Fonts embedded | QR decode | First/median ms | Peak MB | Bytes |
|--------|------|-------|------------|--------|--------------|----------------|-----------|-----------------|---------|-------|
| Dompdf 3.1.6 | 20 | 1 | yes | yes | yes | yes (DejaVuSans, DejaVuSans-Bold subsets) | yes (crop of 150 dpi raster) | 108.5 / 108.5 | 24.0 | 27741 |

The full comparison (90 rows, 10 runs, both engines) is pending.

## Licences

Pending (Task 2): `composer licenses` of the spike lock, `scripts/check-licenses.php` run against it.

## Zerops runtime

Pending (Task 2).

## Decision

Pending (Task 2).

## Consequences

Pending (Task 2): consequences for Phase 8 (work report PDF) and Phase 10 (invoice PDF).

## Open items

- Everything under "Pending" above.
- Measurements inside a Zerops `php-nginx@8.5` container need a Zerops project; they are carried by the deploy rehearsal checklist (plan 03-18).
