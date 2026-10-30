---
phase: 10-invoicing
plan: 03
subsystem: invoicing-pdf-settings
tags: [settings, palette, document-appearance, image-upload, filament]

requires:
  - phase: 03-operations
    provides: "Typed settings with data-layer validation (ValidatedSettings), the one SettingsPage"
  - phase: 07-documents
    provides: "Private media disk and the upload hardening style of AttachDocuments"
provides:
  - "invoicing.document_accent_color (default #ff6f00, lower-case #rrggbb) and invoicing.document_footer (max 300) typed settings"
  - "supplier.logo_path and supplier.signature_path typed settings that accept only server-generated paths"
  - "DocumentPalette::fromAccent(): ink, muted, hairline, tint, rowTint, accentText, onAccent from one accent; contrast(), onAccentContrast()"
  - "DocumentImage: store(), reference(), dataUri() - hardened, content-addressed, hash-verified logo and signature storage"
  - "HexColorRule for the data-layer colour check"
  - "Settings page: section Vzhled dokladů (colour, sample strip, footer) and logo and signature uploads on the Dodavatel tab"
affects: [10-07, 10-12, 10-15, 10-21, 10-36, invoice-pdf, issue-snapshot]

actuals:
  tokens: 12810
  tasks: 3
  commits: 3
plan_head_before: 00efb609f090f8a38a6b58c397c05efb382ded61
plan_head_after: ccfdd9b60806271f786cdf3cf740922ddac00dc7
commits: 3

tech-stack:
  added: []
  patterns:
    - "Upload field that is never stored by Filament: SettingsPage::save() hands the UploadedFile to a domain service and writes the returned server-generated path; the stored path is not a form field, so a crafted form state cannot set it"
    - "Content-addressed objects referenced by path plus SHA-256, read back only when the hash still matches"
    - "Implicit ValidationRule object so an empty value gets the same Czech reason as a malformed one"

key-files:
  created:
    - app/Domain/Invoicing/Pdf/DocumentPalette.php
    - app/Domain/Settings/DocumentImage.php
    - app/Domain/Settings/Rules/HexColorRule.php
    - database/settings/2026_10_17_000100_add_document_appearance_settings.php
    - tests/Unit/Invoicing/DocumentPaletteTest.php
    - tests/Feature/Operations/DocumentAppearanceSettingsTest.php
  modified:
    - app/Domain/Settings/Settings/InvoicingSettings.php
    - app/Domain/Settings/Settings/SupplierSettings.php
    - app/Filament/Pages/SettingsPage.php
    - lang/cs/kokpit.php

key-decisions:
  - "R-03-1: accent, footer, logo and signature are frozen into the issue snapshot by plan 10-12 (images as path and SHA-256); a stored image object is never deleted by the Settings page and dataUri() throws UnexpectedValueException 'document image mismatch' for a missing or altered object (overrides UI-SPEC assumption U-8)"
  - "R-03-2: the palette is not stored; it is derived at render time from the snapshotted accent and an invalid accent falls back to #ff6f00"
  - "R-03-3: images are always re-encoded to PNG and stored as settings/document-<kind>-<sha256 of the PNG>.png on the media disk; caps 1 MB and 4000 x 4000 px"
  - "DocumentImage::store() restores an existing object whose bytes no longer match its name, so a tampered object is repaired by uploading the same image again"
  - "Upload refusals share one Czech message and are keyed <kind>_upload (logo_upload, signature_upload), shown on data.supplier.<kind>_upload"
  - "Clearing a logo or signature is a separate switch (Odebrat logo, Odebrat podpis nebo razítko); an upload wins over the switch and both fields are reset after a successful save"

patterns-established:
  - "Settings appearance flow: ColorPicker (live, lower-cased on update) -> HexColorRule at the data layer -> DocumentPalette::fromAccent() -> sample strip in inline styles"

requirements-completed: [IN-04]

duration: 31 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 03: Document appearance settings Summary

**Accent colour, footer, logo and signature as typed, data-layer-validated settings with a palette derived from the single accent and a hash-verified, content-addressed image store, all editable on the existing Settings page.**

## Accomplishments

- The Admin sets "Barva dokladů" on the tab Fakturace; the value is stored as lower-case `#rrggbb` (default `#ff6f00`) and refused with "Zadejte barvu ve tvaru #rrggbb." under the field for `#12345`, `red`, an empty value, a trailing-text injection or an upper-case value at the data layer. A value typed in upper case is lower-cased on update.
- `DocumentPalette::fromAccent()` derives ink, muted, hairline, tint, row tint, accent text (darkened in 0.02 lightness steps until the contrast on white is at least 3.5) and on-accent text (white or ink by contrast). For `#ff6f00` the on-accent text is ink; for `#1d4ed8` the accent text is the accent itself and the on-accent text is white. Anything that is not `#rrggbb` falls back to the default.
- The sample strip beside the colour field shows the accent, accent text, total-bar text and tint in inline styles, plus "Kontrast textu na součtu: 6,1:1" style line with a decimal comma; nothing typed reaches the style (the strip is built from the palette only).
- "Patička dokladů" is a nullable text of at most 300 characters (empty becomes null).
- `DocumentImage::store()` reads the upload with a one-byte-over-the-limit bound, detects the type from the content (finfo and `getimagesizefromstring`, which must agree), caps size at 1 MB and dimensions at 4000 px, re-encodes through GD to PNG and stores `settings/document-<kind>-<sha256>.png` on the private media disk. SVG, text renamed to an image, a GIF, an empty file, a 2 MB image and a 5000 x 10 image are refused and nothing is stored.
- `reference()` returns path and SHA-256 for the issue snapshot (plan 10-12); `dataUri()` returns `data:image/png;base64,...` only when the bytes still match, and throws `UnexpectedValueException('document image mismatch')` for a wrong hash, an overwritten object, a missing object or a path that is not a document image path.
- Logo and "Podpis nebo razítko" uploads on the tab Dodavatel show a 160 x 40 pt preview of the stored image (as a data URI), and an "Odebrat" switch. Replacing or clearing changes only the setting; the old object stays readable by its own hash. `SupplierSettings` accepts only `settings/document-<kind>-<64 hex>.png` paths, and the stored path is not a form field, so a crafted `data.supplier.logo_path` is ignored.

## Task Commits

1. **Task 1 (tracer): accent colour as a typed setting, palette, Settings section** - `6f7aa4d` (feat)
2. **Task 2: hardened logo and signature storage with hash-verified reads** - `f125611` (feat)
3. **Task 3: palette rules pinned by tests, contrast line on the sample** - `ccfdd9b` (feat)

Tracer gate: the tracer `<verify>` (settings tests, Pint, PHPStan) was green before expansion.

## Verification

- `pest tests/Unit tests/Feature/Operations`: 1095 passed. `pest tests/Arch tests/Isolation tests/Feature/Schema`: 664 passed (run after Task 2). Pint and PHPStan clean. `scripts/check-sensitive.sh` clean on each staged set.
- All task acceptance criteria re-run and passing (greps for settings keys, Czech strings, `imagecreatefromstring`, `getimagesize`, `function reference`, `hash('sha256'`, `UnexpectedValueException`, `0.03928`, `3.5`, `1d4ed8`).
- The visual check of the colour sample (task 3 `human-check`) is left for the phase verification: open Nastavení, tab Fakturace, section Vzhled dokladů, set `#ff6f00` then `#1d4ed8`. The page rendering and the changing contrast line are covered by tests, the look is not.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Division by zero for grey, black and white accents**
- **Found during:** Task 3 (palette tests over `#ffffff` and `#000000`)
- **Issue:** `hexdec() / 255` yields an int for 0 and 255, so `max - min` was int `0` and the `=== 0.0` grey check failed, dividing by zero in the saturation formula.
- **Fix:** channels are floats (`/ 255.0`) and the grey test is `delta < 1.0E-9`.
- **Files modified:** `app/Domain/Invoicing/Pdf/DocumentPalette.php`
- **Commit:** `ccfdd9b`

**2. [Rule 3 - Blocking] Added a HexColorRule class and an upload reset after save**
- **Found during:** Tasks 1 and 2
- **Issue:** a `regex` rule cannot carry the Czech message at the data layer (`ValidatedSettings::save()` passes no messages), and Filament's `required` answers an empty value with a generic message; a file field that is not stored by Filament keeps its temporary file after a save, so a later "remove" would be overridden by the old upload.
- **Fix:** `App\Domain\Settings\Rules\HexColorRule` (implicit, same style as `NumberPatternRule`); `SettingsPage::resetImageFields()` clears the upload and remove fields and refreshes the stored paths after a successful save.
- **Files modified:** `app/Domain/Settings/Rules/HexColorRule.php`, `app/Filament/Pages/SettingsPage.php`
- **Commits:** `6f7aa4d`, `f125611`

### Plan interpretation notes (not deviations)

- The full `DocumentPalette` was written in Task 1 because the tracer test needs `onAccent`; Task 3 added the rule tests and the bug fix above rather than the class.
- The settings migration adds all four properties in Task 1 as listed in the artifact table; the `SupplierSettings` properties joined in Task 2.
- The page button label stays "Uložit" (existing key `kokpit.settings.save`); the UI-SPEC label "Uložit nastavení" is a copy matter for a later plan, no test depends on it.

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking). **Impact:** none on scope; both fixes are covered by tests.

## Known Stubs

None. The images and colours are real settings; plan 10-12 wires them into the snapshot and the PDF plans read the snapshot.

## Threat Flags

None beyond the plan's register: T-10-05 (hardened upload), T-10-06 (strict colour regex, palette fallback, sample built from the palette only), T-10-07 (private disk, data URI only, no route) and T-10-81 (content-addressed objects, hash-verified `dataUri()`, no delete method) are all implemented and tested.

## Next Phase Readiness

Plan 10-12 can call `DocumentImage::reference()` for logo and signature and snapshot `document_accent_color` and `document_footer`; the PDF plans read `DocumentPalette::fromAccent($snapshotAccent)` and `DocumentImage::dataUri($path, $sha256)` and report an `UnexpectedValueException` as a failed render. Plan 10-21 amends the UI-SPEC text that says "Replacing a file removes the old object" (R-03-1).

## Self-Check: PASSED

- Created files exist: DocumentPalette.php, DocumentImage.php, HexColorRule.php, the settings migration, DocumentPaletteTest.php, DocumentAppearanceSettingsTest.php.
- Commits `6f7aa4d`, `f125611`, `ccfdd9b` are ancestors of HEAD.
