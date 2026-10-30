# Phase 3 Spike: PDF engine for the work report and the invoice PDF

Status: complete. Decision: Dompdf (see `## Decision`).
Decisions served: D-14 (PDF engine spike), D-16 (spikes never enter the repository), FND-19 (PDF half).

## Context

Phase 8 renders a work report (multi-page table, Czech text) and Phase 10 renders the invoice PDF with a SPAYD-style payment QR code. Both need one PDF engine. Two candidate routes exist: Dompdf (pure PHP, no external process) and a Chromium engine (the engine `spatie/laravel-pdf` can drive through Browsershot). The decision is taken on measured evidence, and this record is its only artefact in the repository.

All spike code lives outside the repository in `~/kokpit-spikes/pdf` with its own `composer.json`. Nothing from that directory (code, fixtures, generated PDFs) is committed, and the application's `composer.json`, `composer.lock`, `app/` and `scripts/check-licenses.php` are untouched by this plan (D-16).

All data is fictional: Czech pangram text, `Example s.r.o.`, company ID `12345678`. The payee account in the QR payload is assembled at runtime from placeholder fragments with a computed mod-97 check digit; no account number is written into any file or into this record.

## Candidates

| Candidate | What is measured | Notes |
|-----------|------------------|-------|
| Dompdf | `dompdf/dompdf` 3.1.6 used directly in-process, DejaVu Sans (bundled with the package) | pure PHP, no external process |
| Chromium engine | host Google Chrome 154.0.8037.98 in headless mode, `--print-to-pdf` on the same HTML file | the engine that Browsershot drives; Browsershot itself was installed (5.4.0) but not run, see `## Method` |

## Criteria

Written down before the full comparison was measured (research Pattern 12 decision rule; this section was committed with the tracer, before the 90-row runs):

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

- Spike directory `~/kokpit-spikes/pdf` (outside the checkout, `git rev-parse` there fails), host macOS, PHP 8.5.11. Composer packages limited to the research audit rows (`dompdf/dompdf`, `spatie/laravel-pdf`, `spatie/browsershot`) plus `chillerlan/php-qrcode` (already in the application lock through Filament). No `npm install` was run.
- `bench.php --engine=dompdf|chromium --rows=N --runs=N --out=DIR` renders one fixed HTML template (DejaVu Sans, header, page number, table with the Czech test string in every row, QR as a PNG data URI, 38 mm square) and prints one JSON object with the verdicts and numbers.
- Chromium path used (`engine_path` in the JSON): `puppeteer` does not resolve on the host (`require.resolve('puppeteer')` fails from the spike directory, and the research audit does not list it, so it was not installed). Therefore the same HTML was printed by the host Google Chrome binary with `--headless --disable-gpu --no-pdf-header-footer --print-to-pdf=<file>`, which is the engine Browsershot drives. Browsershot's own overhead (Node start, puppeteer) is therefore not in the Chromium numbers; real Browsershot timings would be at least as high.
- The QR payload is SPAYD-shaped (`SPD*1.0*ACC:...*AM:...*CC:CZK*X-VS:...*MSG:...`). The account is built at runtime from placeholder fragments with a computed check digit.
- QR decode runs on a crop of the QR region of the 150 dpi page raster: the full-page raster (1241 x 1754 px) exhausts PHP's default 128 MB memory limit inside the chillerlan reader. This is a limit of the throwaway decoder, not of either engine.
- Wall time is the render only (HTML and QR are built once). For Dompdf it is `loadHtml` + `render` + `output` in process. For Chromium it is process start until a complete PDF file (ending in `%%EOF`) exists: headless Chrome 154 wrote the file but did not exit by itself on this host in any headless mode, so the script stops its own Chrome processes (found by their private profile directory) after the file is complete. The Chromium profile directory was removed before the final run, so `first` is the cold-profile run, but the Chrome application files were already in the operating system cache.
- Memory: for Dompdf `memory_get_peak_usage(true)` of the rendering process over all runs, captured before the verification step runs. For Chromium the sum of RSS of the Chrome process tree sampled every 20 ms during one extra run; it counts shared pages more than once, so it is an upper bound, not a footprint.
- Template differences needed per engine: Dompdf takes the header as a `position: fixed` block with a negative top offset and the page number from `counter(page)` in a fixed footer. In Chrome that same fixed header with a negative offset was drawn over the table body on pages 2 and 3 (found by the script: header text missing or split on those pages), so the Chromium template uses native CSS page margin boxes (`@top-left`, `@bottom-center` with `counter(page)` and `counter(pages)`) instead. The Chromium font is the same DejaVu Sans, loaded through `@font-face` from the Dompdf package's font files.

## Measurements

Main comparison: 90 rows, 10 runs per engine, host macOS, PHP 8.5.11, Chrome 154. All checks by script.

| Engine | Rows | Pages | Diacritics every page | Header every page | Page numbers | Fonts embedded | QR decode | First ms | Median ms | Max ms | Peak MB | Bytes |
|--------|------|-------|-----------------------|-------------------|--------------|----------------|-----------|----------|-----------|--------|---------|-------|
| Dompdf 3.1.6 | 90 | 4 | yes | yes | yes (`Strana N`) | yes (DejaVuSans, DejaVuSans-Bold subsets) | yes | 242.3 | 206.7 | 242.3 | 40 (PHP) | 35177 |
| Chromium (host Chrome 154, `--print-to-pdf`) | 90 | 4 | yes | yes (margin box) | yes (`Strana N / M`) | yes (DejaVuSans) | yes | 837.7 | 636.2 | 837.7 | 1184 (Chrome process tree RSS, upper bound; PHP 4) | 222481 |

Scaling probe on the same template (3 runs each; Dompdf at 500 rows needed `memory_limit=-1` only because the verification step shares the process):

| Engine | Rows | Pages | Median ms | Max ms | Peak MB | Bytes | Checks |
|--------|------|-------|-----------|--------|---------|-------|--------|
| Dompdf 3.1.6 | 200 | 8 | 436 | 441 | 62 | 46251 | all yes |
| Dompdf 3.1.6 | 500 | 20 | 1536 | 1635 | 124 | 77418 | all yes |
| Chromium (host Chrome 154) | 500 | 19 | 1060 | 1802 | 1251 (RSS upper bound) | 951026 | all yes |

Reading of the numbers:

- Both engines pass every criterion at 90 rows: 4 pages, Czech diacritics extracted on every page, embedded fonts, repeated header, page numbers, QR decoded to the exact input at 150 dpi.
- Dompdf is about 3 times faster at 90 rows (median 207 ms against 636 ms) and produces a file about 6 times smaller (35 KB against 222 KB).
- Dompdf memory grows with the table: 40 MB at 90 rows, 62 MB at 200, 124 MB at 500 rows (20 pages), which touches PHP's default `memory_limit` of 128 MB. Its time grows faster than linearly (0.2 s, 0.44 s, 1.5 s).
- Chromium time barely depends on the length (0.64 s at 90 rows, 1.06 s at 500) but its RSS is above 1 GB (an overcount from shared pages, still more than an order of magnitude above Dompdf).
- Tracer run before the comparison (Dompdf, 20 rows, 1 run): 1 page, all checks yes, 108.5 ms, 24 MB, 27741 bytes.

## Licences

`composer licenses --locked --format=json` in the spike directory, from the spike lock (15 packages):

| Package | SPDX id reported by composer |
|---------|------------------------------|
| `dompdf/dompdf` 3.1.6 | `LGPL-2.1` (deprecated alias) |
| `dompdf/php-font-lib` 1.0.2 | `LGPL-2.1-or-later` |
| `dompdf/php-svg-lib` 1.0.2 | `LGPL-3.0-or-later` |
| `chillerlan/php-qrcode` 5.0.5 | `MIT`, `Apache-2.0` |
| `chillerlan/php-settings-container`, `illuminate/contracts`, `masterminds/html5`, `psr/container`, `psr/simple-cache`, `sabberworm/php-css-parser`, `spatie/browsershot` 5.4.0, `spatie/laravel-package-tools`, `spatie/laravel-pdf` 2.14.0, `spatie/temporary-directory`, `symfony/process` | `MIT` |

Chromium chain: Browsershot, `spatie/laravel-pdf` and their PHP dependencies are MIT (measured above). `puppeteer` (Apache-2.0) and Chromium (BSD-style) were not installed here; those two are taken from the research table `[CITED: 03-RESEARCH.md Pattern 12]`.

Repository gate: `composer licenses --locked --format=json | php scripts/check-licenses.php` run from the spike directory against the spike lock exits `1` and prints one offender: `dompdf/dompdf [LGPL-2.1]`. `scripts/check-licenses.php` was not edited. Its allowlist already holds `LGPL-2.1-only`, `LGPL-2.1-or-later`, `LGPL-3.0-only` and `LGPL-3.0-or-later`, so the other two Dompdf packages pass; only the deprecated alias `LGPL-2.1` (which SPDX defines as the "only" form) is rejected. Whoever adds Dompdf to the application needs a maintainer decision on that alias in the phase that adds it (Phase 8): a reviewed one-line normalisation of `LGPL-2.1` to `LGPL-2.1-only` in the checker, not a silent allowlist extension (the script's own header requires a decision). This record does not make that decision and is not legal advice.

## Zerops runtime

Dompdf (host run, so measured on the host; the Zerops container is `[ASSUMED]` equal until the rehearsal confirms it):

- PHP only, no external process. `dompdf/dompdf` requires `ext-dom` and `ext-mbstring`; image handling and the PNG QR (GD output of `chillerlan/php-qrcode`) need `ext-gd` with PNG support; `ext-zlib` is suggested for PDF stream compression. The host run had dom, mbstring, gd (PNG support), zlib, iconv, intl loaded.
- Memory: the PHP `memory_limit` of the process that renders must be above the measured peak (62 MB at 200 rows, 124 MB at 500 rows).
- The `pdfinfo`, `pdftotext`, `pdffonts` and `pdftoppm` tools were used only as host-side checks; the application does not need them.

Chromium engine `[ASSUMED]`, not measured in a container (research Pattern 10 and Pattern 12): Node, Chromium and fonts installed in `run.prepareCommands` (for example `apk add chromium nodejs npm font-dejavu`), container flags such as `--no-sandbox`, and container RAM during render (the host RSS above is already above 1 GB). Browsershot additionally needs `puppeteer` in `node_modules`.

Deploy rehearsal (plan 03-18) for the chosen engine (Dompdf) must confirm in the Zerops `php-nginx@8.5` container: `php -m` lists `dom`, `mbstring`, `gd` and `zlib`; `php -r 'var_dump(gd_info()["PNG Support"]);'` prints `bool(true)`; and the container's effective `memory_limit` for the process that will render long reports.

## Decision

Decision: **Dompdf** (`dompdf/dompdf` ^3.1, DejaVu Sans embedded as a subset) is the PDF engine for the Phase 8 work report and the Phase 10 invoice PDF.

By the pre-written rule: Dompdf passed every criterion (4 pages at 90 rows, diacritics on every page, embedded fonts, repeating header, page numbers, QR decoded to the exact input), so the Chromium engine is not chosen. Its run was also correct, but it is about 3 times slower, writes files about 6 times larger, needs Node, Chromium and fonts in the container, and was not shown to run within Zerops container limits.

The decision is tied to the measured data: layouts of the kind measured (tables with a repeating header, a fixed footer page number, a PNG QR). A layout that Dompdf cannot render (modern CSS such as grid or flexbox-heavy designs) is the trigger to revisit; the next step then is to verify and install `puppeteer`, re-run the same method with Browsershot, and record the result in a new record. The owner confirms this decision before Phase 8 adds the dependency (human check of plan 03-01, Task 2).

## Consequences

Phase 8 (work report PDF), which is the first phase to add the engine:

- Add `dompdf/dompdf` ^3.1 to the application's `composer.json` (this plan does not). Decide in that phase whether to call Dompdf directly or through `spatie/laravel-pdf` 2.14, which ships a `DomPdfDriver` next to `ChromeDriver` (needs `chrome-php/chrome`), `BrowsershotDriver`, `GotenbergDriver`, `CloudflareDriver` and `WeasyPrintDriver`, so call sites would not change if the engine ever changes. Only direct Dompdf was measured here; `laravel-pdf` adds `illuminate/contracts`, `spatie/laravel-package-tools` and `spatie/temporary-directory` and was installed in the spike but not run.
- Take the licence decision on the `LGPL-2.1` alias (see `## Licences`) in the same change, otherwise `composer check-licenses` fails in CI.
- Render long reports in a job with a raised `memory_limit` or split the table: 124 MB at 500 rows (20 pages) reaches the 128 MB default, and time grows faster than linearly (about 1.5 s at 500 rows).
- Template rules proven here: DejaVu Sans (bundled, embedded as a subset, covers all Czech diacritics), `thead` repeats, a `position: fixed` header with a negative offset inside an `@page` margin, a `counter(page)` footer (`Strana N`). A total page count (`Strana N / M`) was not measured with Dompdf.

Phase 10 (invoice PDF with QR):

- Same engine and font. QR through `chillerlan/php-qrcode` (already locked through Filament), rendered as a PNG data URI by its GD output (needs `ext-gd`); a 38 mm square with module size about 0.9 mm decoded back to the exact SPAYD input at 150 dpi.
- The payment account in the SPAYD string comes from the supplier snapshot of the invoice; fixtures and tests must build their account numbers from fragments at runtime, as `bench.php` did (repository hygiene).
- The Phase 10 plan names the `LGPL-2.1` decision as already taken in Phase 8, or takes it itself if Phase 8 did not add the engine.

## Open items

- Owner confirmation of the decision before Phase 8 adds the dependency (human check, plan 03-01 Task 2).
- Maintainer decision on the `LGPL-2.1` SPDX alias in `scripts/check-licenses.php`, in the phase that adds Dompdf.
- Not measured: either engine inside a Zerops `php-nginx@8.5` container (needs a Zerops project); carried by the deploy rehearsal checklist (plan 03-18) with the `php -m` checks listed above.
- Not measured: Browsershot with `puppeteer` (not installed, `puppeteer` is outside the audited package list; verify and install it only if Dompdf later fails a layout criterion). Also not measured: the `chrome-php/chrome` based `ChromeDriver` of `laravel-pdf`, which would avoid Node but is likewise outside the audit.
- Not measured: `Strana N / M` (total pages) with Dompdf, and Dompdf with CSS grid or flexbox layouts.
- The page-1 QR checks decode a cropped raster, not the full page, because the throwaway decoder runs out of memory on the full page at the default PHP limit; a scanner app would read the full page, so a real scan of a printed sample remains a manual check for Phase 10.
