---
phase: 08-exchange-rates-and-reports
plan: 03
subsystem: reports
tags: [dompdf, pdf, licence-gate, lgpl, work-report, czech, blade]

requires:
  - phase: 03-operations-foundation
    provides: "03-SPIKE-PDF.md: Dompdf decided as the PDF engine; scripts/check-licenses.php gate; PragueDay, DurationFormat"
  - phase: 06-time-tracking
    provides: "TimeEntry::elapsedSecondsSql, Prague day grouping, PartnerContext Admin-or-system rule"
provides:
  - "dompdf/dompdf ^3.1 (3.1.6) in composer.json and composer.lock, admitted under the owner's licence ruling"
  - "scripts/check-licenses.php reads the deprecated SPDX id LGPL-2.1 as LGPL-2.1-only; ruling recorded in the header"
  - "App\\Domain\\Reports\\WorkReport\\WorkReportSource: money-free, Admin-or-system source of one client's period"
  - "App\\Domain\\Reports\\WorkReport\\WorkReportPdf: render() and html() through a hardened Dompdf"
  - "resources/views/pdf/work-report.blade.php and lang/cs/work_report.php (the full Czech column)"
affects: [08-06 work report completion, 08-21 report page PDF download, 10 invoice PDF]

commits: 2
plan_head_before: de11d12bb4cdbbcda2a2dc6b63beebe8c4126c94
plan_head_after: ac45cecf7e710b9d617b6f981a6fb8d628134e60

actuals:
  tokens: 9700
  tasks: 2
  commits: 2

tech-stack:
  added: [dompdf/dompdf 3.1.6, dompdf/php-font-lib 1.0.2, dompdf/php-svg-lib 1.0.2, masterminds/html5, sabberworm/php-css-parser]
  patterns:
    - "Licence aliases: a deprecated SPDX id is mapped to its current id before the allowlist comparison (LICENCE_ALIASES), the allowlist does not grow"
    - "Report sources read names, Prague days and seconds only and never join a billing table; a test serialises the source and greps for a rate canary"
    - "Dompdf closed down: isRemoteEnabled, isPhpEnabled, isJavascriptEnabled false, DejaVu Sans, chroot on resources/views/pdf, font cache in storage/framework/dompdf"
    - "Locale-bound document: Localizable::withLocale around the Blade render; the source builds its labelled lines in the client's language"

key-files:
  created:
    - app/Domain/Reports/WorkReport/WorkReportSource.php
    - app/Domain/Reports/WorkReport/WorkReportPdf.php
    - resources/views/pdf/work-report.blade.php
    - lang/cs/work_report.php
    - tests/Feature/Reports/WorkReportPdfTest.php
  modified:
    - composer.json
    - composer.lock
    - scripts/check-licenses.php
    - tests/Unit/LicenceCheckTest.php
    - .gitignore

key-decisions:
  - "Owner ruling (2026-10-14, option normalise-alias): dompdf's LGPL-2.1 is compatible with AGPL-3.0 under the strict LGPL-2.1-only reading (LGPL-2.1 sections 3 and 6); the licence gate maps LGPL-2.1 to LGPL-2.1-only for every package, not a per-package exception"
  - "Dompdf is called directly, no wrapper package (only direct Dompdf was measured in the spike)"
  - "WorkReportSource takes plain ids and Prague days, not ReportFilters, so Phase 10 can build sources without the report page"
  - "The source builds its supplier and client lines inside the client's invoice language, because the company ID label is a word of that language"
  - "The tracer html renders the billable section only; the non-billable rows are in the source already, the second section is plan 08-06"

patterns-established:
  - "Pattern: a reviewed alias map in the licence gate instead of an allowlist extension"
  - "Pattern: report values reach the PDF only through Blade {{ }}; a markup canary asserts the escaped form"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "The owner's ruling on dompdf's LGPL-2.1 is applied to the licence gate and recorded in the script header; composer check-licenses passes with dompdf/dompdf locked and GPL-2.0-only still fails"
    requirement: "RE-03"
    verification:
      - kind: unit
        ref: "tests/Unit/LicenceCheckTest.php#reads the deprecated alias LGPL-2.1 as LGPL-2.1-only and passes it (owner ruling)"
        status: pass
      - kind: unit
        ref: "tests/Unit/LicenceCheckTest.php#accepts the real composer licenses output for the locked dependencies"
        status: pass
      - kind: other
        ref: "ddev composer check-licenses"
        status: pass
    human_judgment: false
  - id: D2
    description: "WorkReportSource::forPeriod groups one client's billable and non-billable seconds by project, Prague day and task, never reads a billing table, and refuses a Partner"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the source"
        status: pass
    human_judgment: false
  - id: D3
    description: "WorkReportPdf renders a Czech work report PDF with correct diacritics, embedded DejaVu subsets, escaped values and a closed-down Dompdf"
    requirement: "RE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/WorkReportPdfTest.php#the Czech document"
        status: pass
      - kind: other
        ref: "pdftotext and pdffonts on a rendered sample (diacritics extracted, DejaVuSans and DejaVuSans-Bold embedded)"
        status: pass
    human_judgment: true
    rationale: "The look of the page against UI-SPEC Surface D (spacing, grayscale, column widths) is a visual judgement; a rendered sample was inspected, no automated visual assertion exists"

duration: 45min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 03: Dompdf licence ruling and the first Czech work report PDF Summary

**Dompdf 3.1.6 admitted under the owner's LGPL-2.1-only ruling (alias normalised in the licence gate), plus a money-free WorkReportSource and a hardened WorkReportPdf that render a client's period into a real Czech PDF**

## Performance

- **Duration:** about 45 min of executor work after the owner's checkpoint answer
- **Completed:** 2026-10-14
- **Tasks:** 2 (Task 1 the owner decision, Task 2 the tracer)
- **Files modified:** 10 (5 created, 5 modified, composer.lock included)

## Accomplishments

- The owner ruled `normalise-alias` at the Task 1 checkpoint: dompdf/dompdf's `LGPL-2.1` is compatible with AGPL-3.0 under the strict -only reading, and the gate reads the deprecated id as `LGPL-2.1-only`. The ruling (date, owner, reason, the AGPL-3.0 reading through LGPL-2.1 sections 3 and 6) is in the header of `scripts/check-licenses.php`; `LicenceCheckTest` gains the alias case and a mixed case that keeps `GPL-2.0-only` failing.
- `dompdf/dompdf` ^3.1 installed (3.1.6 with its four transitive packages); `composer check-licenses` reports 225 packages, all allowed. `composer audit` found no advisories.
- `WorkReportSource::forPeriod()` reads one client's period in one grouped query (names, Prague day, billable flag, seconds through `elapsedSecondsSql` with the PHP clock bound), archived clients, projects and tasks included, and never names a pricing table. An entry at 00:30 Prague time on the first of the month lands on that day although its UTC instant is the evening before.
- `WorkReportPdf::render()` and `html()` give a PDF starting `%PDF-` with DejaVu Sans and DejaVu Sans Bold embedded as subsets; a rendered sample extracted correctly with `pdftotext` (ě š č ř ž ý á í é ů).

## Task Commits

1. **Task 1: Owner decision on the LGPL-2.1 alias** - no commit (checkpoint:decision, answered `normalise-alias` by the owner; applied in the Task 2 commit below)
2. **Task 2: Tracer - Dompdf passes the licence gate and renders a Czech work report**
   - `8f4c474` (chore) licence gate alias, tests, dompdf install
   - `ac45cec` (feat) source, service, template, Czech strings, tests, `.gitignore`

**Plan metadata:** the docs commit that carries this SUMMARY, STATE.md, ROADMAP.md and REQUIREMENTS.md.

## Files Created/Modified

- `scripts/check-licenses.php` - `LICENCE_ALIASES` map and the recorded owner ruling
- `tests/Unit/LicenceCheckTest.php` - alias passes; `GPL-2.0-only` still fails beside it
- `composer.json`, `composer.lock` - `dompdf/dompdf ^3.1`
- `app/Domain/Reports/WorkReport/WorkReportSource.php` - the money-free source
- `app/Domain/Reports/WorkReport/WorkReportPdf.php` - Dompdf service with the hardened options
- `resources/views/pdf/work-report.blade.php` - A4 template, 72/48/56/48 pt margins, grayscale, repeating `thead`
- `lang/cs/work_report.php` - the whole Czech column of the Copywriting Contract plus `no_project` and `company_id`
- `tests/Feature/Reports/WorkReportPdfTest.php` - 16 tests (grouping, Prague boundary, authorisation, escaping, no money, remote/local-file probe, PDF bytes)
- `.gitignore` - `/storage/framework/dompdf/` (font metrics cache)

## Decisions Made

See `key-decisions` above. The tracer scope is deliberate: the first-page header, the billable table and its total. Plan 08-06 adds the non-billable section, daily subtotals, project rows, the summary, the running header, `Strana n / total`, English, and the empty/non-billable-only cases.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `App::withLocale()` does not exist in this Laravel version**
- **Found during:** Task 2 (first GREEN run)
- **Issue:** the plan's key link names `App::withLocale(language)`; `Illuminate\Foundation\Application` has no such method (only the `Localizable` trait offers `withLocale`)
- **Fix:** `WorkReportPdf` uses the `Localizable` trait (`$this->withLocale(...)`); `WorkReportSource`, a static `final readonly` class, sets and restores the locale with `App::setLocale()` in a try/finally
- **Files modified:** `app/Domain/Reports/WorkReport/WorkReportPdf.php`, `app/Domain/Reports/WorkReport/WorkReportSource.php`
- **Verification:** `WorkReportPdfTest` green; the `withLocale` acceptance grep still matches
- **Committed in:** `ac45cec`

**2. [Rule 2 - Missing Critical] Font cache directory would show up as untracked runtime output**
- **Found during:** Task 2 (`git status` after the first render)
- **Issue:** `storage/framework/dompdf/` is created at the first render and was not ignored, so generated font metrics could be committed by accident
- **Fix:** added `/storage/framework/dompdf/` to `.gitignore`
- **Files modified:** `.gitignore`
- **Committed in:** `ac45cec`

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 missing critical)
**Impact on plan:** both necessary; no scope creep.

## Issues Encountered

- A first version of the rate canary in the tests was `123456`, which collides with the placeholder supplier company ID `12345678` and failed spuriously; the canary is now `98765` / `987,65` / `987.65`. A test-only fix.
- Earlier red runs left two probe files in `storage/framework` (created before the `try`); they were deleted and the probe test now creates its file inside the `try`.
- `composer audit` exits 1 because of two abandoned packages (`dragon-code/pretty-array`, `dragon-code/support`) that were already in the lock; it reports no security advisories and nothing about dompdf. Pre-existing, out of scope.

## Known Limits (tracer scope, closed by plan 08-06)

- The HTML shows the billable section only. A period with only non-billable entries therefore renders the header without a table and without the empty-period row (the empty row appears only when the source has no rows at all).
- The English document is not available yet: `lang/en/work_report.php` is created in 08-06, so rendering with `InvoiceLanguage::English` would print the translation keys. Only the Czech language is supported until then; nothing calls the service with English in this plan.

## Authentication Gates

None. The only human gate was the licence decision (Task 1), answered `normalise-alias` by the owner.

## User Setup Required

None - no external service configuration required. Deploy note carried from the spike: the PHP image needs `ext-dom`, `ext-mbstring` and `ext-gd` for Dompdf (checked by the deploy rehearsal, plan 03-18).

## Next Phase Readiness

- 08-06 can complete the document on `WorkReportSource` (both billable flags are already in the rows) and the Czech strings (all keys exist).
- 08-21 can add the download item using `forPeriod()` and `render()`; the source expects the Admin context.
- Phase 10 reuses the engine and the licence ruling.

## Self-Check: PASSED

- Files: all six created or modified key files found on disk
- Commits: `8f4c474` and `ac45cec` are ancestors of HEAD; `commits: 2` measured from the ledger base `de11d12bb4cdbbcda2a2dc6b63beebe8c4126c94`
- `ddev exec vendor/bin/pest` full suite: 3097 passed; `ddev composer check-licenses`, Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on the staged files
- Acceptance criteria: composer.json requires dompdf/dompdf, LGPL-2.1 and GPL-2.0-only present in the script and test, `isRemoteEnabled`/`isPhpEnabled`/`isJavascriptEnabled`/`withLocale` present in the service, `forPeriod` and `elapsedSecondsSql` in the source, no pricing table or column named in the source

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
