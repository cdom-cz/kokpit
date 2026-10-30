---
phase: 03-operations-foundation
plan: 01
subsystem: infra
tags: [pdf, dompdf, chromium, browsershot, spayd, qr, licences, spike]

requires:
  - phase: 02-foundation
    provides: licence gate script (scripts/check-licenses.php) and the locked chillerlan/php-qrcode through Filament
provides:
  - "PDF engine decision record 03-SPIKE-PDF.md: Dompdf chosen for the Phase 8 work report and the Phase 10 invoice PDF"
  - "Measured limits of Dompdf on a fictional Czech report (time, memory, size) and of host Chrome print-to-pdf"
  - "Licence gate finding: dompdf/dompdf is reported as the deprecated alias LGPL-2.1 and fails scripts/check-licenses.php"
affects: [08-work-report, 10-invoice-pdf, 03-18-deploy-rehearsal, licence-gate]

plan_head_before: 24c2abb0109bab3e61c140d409b1d216d698d4d3
plan_head_after: ee043db8855ee50db16aa6eb6f2b0f3f442a866d

actuals:
  tokens: 7400
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Spikes live outside the repository (~/kokpit-spikes/<name>) with their own composer.json; only a decision record enters .planning/"
    - "Fictional QR payload: account assembled at runtime from placeholder fragments with a computed mod-97 check digit"

key-files:
  created:
    - .planning/phases/03-operations-foundation/03-SPIKE-PDF.md
  modified: []

key-decisions:
  - "Dompdf (dompdf/dompdf ^3.1, DejaVu Sans subset) is the PDF engine for Phase 8 and Phase 10; Chromium is not chosen because Dompdf passed every criterion"
  - "The LGPL-2.1 SPDX alias needs a reviewed maintainer decision in the phase that adds Dompdf; scripts/check-licenses.php was not edited"

requirements-completed: [FND-19]

coverage:
  - id: D1
    description: "Decision record states criteria before measurement and ends in one engine decision with consequences for Phases 8 and 10"
    requirement: FND-19
    verification: []
    human_judgment: true
    rationale: "Choosing an engine is a judgement over the measurements; the owner confirms the decision before Phase 8 adds the dependency"
  - id: D2
    description: "Repeatable bench script outside the repository measuring both engines (diacritics, header, page numbers, fonts, QR decode, time, memory, size)"
    requirement: FND-19
    verification:
      - kind: other
        ref: "php ~/kokpit-spikes/pdf/bench.php --engine=dompdf --rows=20 --runs=1 | grep -q '\"qr_ok\":true'"
        status: pass
      - kind: other
        ref: "php ~/kokpit-spikes/pdf/bench.php --engine=chromium --rows=90 --runs=1 | grep -q '\"diacritics_ok\":true'"
        status: pass
    human_judgment: false
  - id: D3
    description: "Licence section with the SPDX ids of the measured engines and the exit code and offender of scripts/check-licenses.php against the spike lock"
    requirement: FND-19
    verification:
      - kind: other
        ref: "composer licenses --locked --format=json | php scripts/check-licenses.php (exit 1, dompdf/dompdf [LGPL-2.1])"
        status: pass
    human_judgment: false
  - id: D4
    description: "Repository untouched outside .planning/ (no spike code, PDFs or dependencies; composer.json, composer.lock, app/ and scripts/check-licenses.php unchanged)"
    requirement: FND-19
    verification:
      - kind: other
        ref: "test -z \"$(git status --porcelain --untracked-files=all -- . ':!.planning')\" && git diff --quiet HEAD -- composer.json composer.lock app scripts/check-licenses.php"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 01: PDF engine spike Summary

**Dompdf 3.1.6 chosen over host Chrome print-to-pdf for the Phase 8 work report and Phase 10 invoice PDF, on a scripted 90-row Czech report with a SPAYD-style QR (4 pages, 207 ms median, 40 MB, 35 KB), with the LGPL-2.1 licence-gate finding recorded**

## Performance

- **Duration:** about 25 min (the start timestamp was captured after the first reading and setup minutes, so this is an estimate)
- **Started:** 2026-10-08T01:39:08Z (recorded start; setup began a few minutes earlier)
- **Completed:** 2026-10-08T01:51:00Z
- **Tasks:** 2
- **Files modified:** 1 in the repository (the decision record); spike code stays outside

## Accomplishments

- A repeatable benchmark (`bench.php`, in `~/kokpit-spikes/pdf`, never committed) renders one fictional template through Dompdf or headless Chrome and checks page count, Czech diacritics per page (`pdftotext`), embedded fonts (`pdffonts`), repeating header, page numbers and an exact QR decode from a 150 dpi raster.
- Both engines pass every criterion at 90 rows. Dompdf: median 207 ms (max 242 ms), 40 MB, 35 KB. Chrome: median 636 ms (max 838 ms), 222 KB, process-tree RSS above 1 GB (an upper bound). Dompdf scales to 124 MB and 1.5 s at 500 rows (20 pages), which touches the 128 MB PHP default.
- Decision by the pre-written rule: Dompdf, with consequences for Phase 8 (add dependency, licence decision, memory limit for long reports) and Phase 10 (same engine, PNG QR via chillerlan, fixtures built from fragments).
- Licence finding reproduced: `scripts/check-licenses.php` against the spike lock exits 1 with `dompdf/dompdf [LGPL-2.1]`; the script was not edited.
- `spatie/laravel-pdf` 2.14 ships a `DomPdfDriver` (and `ChromeDriver`, `BrowsershotDriver`, `GotenbergDriver`, `CloudflareDriver`, `WeasyPrintDriver`), so one API over either engine is possible.

## Chromium path used

`puppeteer` does not resolve on the host and is not in the research Package Legitimacy Audit, so no `npm install` was run. The same HTML was printed by the host Google Chrome 154 binary with `--headless --disable-gpu --no-pdf-header-footer --print-to-pdf` (the engine Browsershot drives). Browsershot 5.4.0 was installed in the spike but not run. The record states this path (`engine_path` field and `## Method`).

## Task Commits

1. **Task 1: Tracer - one fictional Czech page with a QR code rendered by Dompdf, record skeleton** - `09e168c` (docs)
2. **Task 2: Full comparison, licence and Zerops findings, decision** - `ee043db` (docs)

**Plan metadata:** committed separately after this summary (docs: complete plan).

## Files Created/Modified

- `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md` - the decision record (criteria, method, measurements, licences, Zerops runtime, decision, consequences, open items)
- Outside the repository, not committed: `~/kokpit-spikes/pdf/composer.json`, `bench.php`, `out/` (generated PDFs, rasters, JSON)

## Decisions Made

- Dompdf is the engine for Phase 8 and Phase 10 (Dompdf passed every criterion; Chromium is about 3 times slower, 6 times larger files, needs Node/Chromium in the container). The owner confirms before Phase 8 adds the dependency (human check in the plan).
- Whether Phase 8 calls Dompdf directly or through `spatie/laravel-pdf` is left to that phase; only direct Dompdf was measured.
- The `LGPL-2.1` alias is left as an open maintainer decision for the phase that adds Dompdf (proposed: a reviewed normalisation to `LGPL-2.1-only`, not a silent allowlist edit).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Full-page QR decode exhausted PHP's 128 MB memory limit**
- **Found during:** Task 1
- **Issue:** The plan says to decode the full 150 dpi page raster and crop only on failure; the chillerlan reader died with a fatal memory error on the 1241 x 1754 px page.
- **Fix:** `bench.php` decodes the cropped QR region first (the plan's allowed fallback); peak memory is captured before the verification step so decoding does not distort it. Recorded in the record's Method and Open items.
- **Files modified:** `~/kokpit-spikes/pdf/bench.php` (outside the repository)
- **Committed in:** not committed (spike code), described in `09e168c` and `ee043db`

**2. [Rule 3 - Blocking] Headless Chrome 154 does not exit after `--print-to-pdf`**
- **Found during:** Task 2
- **Issue:** The first 10-run attempt hung for minutes: Chrome wrote the PDF but kept running in every headless mode.
- **Fix:** The script treats a complete PDF file (ending in `%%EOF`) as end of render and stops only the Chrome processes started with its private profile directory. Stated in the record's Method.
- **Files modified:** `~/kokpit-spikes/pdf/bench.php` (outside the repository)
- **Committed in:** not committed (spike code), described in `ee043db`

**3. [Rule 1 - Bug] Dompdf header idiom misplaced the header in Chrome on pages 2 and 3**
- **Found during:** Task 2
- **Issue:** The `position: fixed` header with a negative top offset drew over the table body on pages 2-3 in Chrome, so the header check failed for Chromium.
- **Fix:** The Chromium template uses native CSS page margin boxes for header and page numbers; the difference is recorded so the comparison is fair.
- **Files modified:** `~/kokpit-spikes/pdf/bench.php` (outside the repository)
- **Committed in:** not committed (spike code), described in `ee043db`

**Additions beyond the plan (scope kept inside the record):** a scaling probe at 200 and 500 rows (3 runs) to give "measured limits" for Phase 8, and a note that `spatie/laravel-pdf` ships a Dompdf driver.

---

**Total deviations:** 3 auto-fixed (2 bugs in the throwaway script, 1 blocking)
**Impact on plan:** All three were in throwaway spike code; none touched the repository. The criteria were not changed after measuring.

## Issues Encountered

- Browsershot cannot be run without `puppeteer`, which an unattended run may not install; the Chromium measurement therefore bypasses Browsershot overhead (stated in the record). The first benchmark attempt left spike-started Chrome processes running; they were stopped by their private profile directory only, and no other process was touched.
- Chrome memory is reported as a summed process-tree RSS, which over-counts shared pages (stated as an upper bound).

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The threat register was applied: the QR account is built at runtime from fragments, the record passed `scripts/check-sensitive.sh`, no PDF or spike code was committed, no npm install was run, and `composer.json`, `composer.lock`, `app/` and `scripts/check-licenses.php` are unchanged.

## Next Phase Readiness

- Phases 8 and 10 have a decided engine and a licence action item. Plan 03-18 (deploy rehearsal) must confirm `php -m` lists `dom`, `mbstring`, `gd` (PNG support) and `zlib` in the Zerops `php-nginx@8.5` container and the effective `memory_limit`.
- Open: owner confirmation of the decision; the `LGPL-2.1` alias decision; no container measurement of either engine; Browsershot with puppeteer not measured.

## Self-Check: PASSED

- FOUND: `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md`
- FOUND commits `09e168c` and `ee043db` (ancestors of HEAD)
- Repository outside `.planning/` clean; `composer.json`, `composer.lock`, `app/` and `scripts/check-licenses.php` unchanged; `scripts/check-sensitive.sh` clean on the record.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
