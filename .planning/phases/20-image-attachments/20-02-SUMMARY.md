---
phase: 20-image-attachments
plan: 02
subsystem: knowledge
tags: [gd, image-pipeline, exif, upload-hardening, pest]
status: complete

requires:
  - phase: 19-knowledge-center
    provides: App\Domain\Knowledge namespace and the Unit test conventions
provides:
  - "ImagePipeline::process(string): ProcessedImage, the one place a raw upload becomes stored bytes"
  - "ImageRefusal / ImageRefused: one reason per refusal, no file content in the exception"
  - "JpegOrientation (bounded Exif Orientation reader and GD transform) and Animation (GIF, APNG, WebP structure checks)"
  - "Tests\\Support\\ImageFixtures: runtime-built fictional fixtures"
affects: [20-03, 20-04, 20-05]

plan_head_before: 8df70277797651f08d29980eecf394b528869a69
plan_head_after: 4dcb5186eb27a19116cbf03058774645f7a82dd7

actuals:
  tokens: 13800
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "GD only, no Composer package; every GD call runs under a local error handler that turns warnings into ErrorException"
    - "Header gate (magic bytes, header type, pixel cap, memory preflight) before any decode"

key-files:
  created:
    - app/Domain/Knowledge/Images/ImagePipeline.php
    - app/Domain/Knowledge/Images/ProcessedImage.php
    - app/Domain/Knowledge/Images/ImageFormat.php
    - app/Domain/Knowledge/Images/ImageRefusal.php
    - app/Domain/Knowledge/Images/ImageRefused.php
    - app/Domain/Knowledge/Images/JpegOrientation.php
    - app/Domain/Knowledge/Images/Animation.php
    - tests/Support/ImageFixtures.php
    - tests/Unit/Knowledge/ImageMetadataStripTest.php
    - tests/Unit/Knowledge/ImagePipelineTest.php
  modified: []

key-decisions:
  - "R-20-02-1 to R-20-02-8 implemented as planned: GD only, limit constants on ImagePipeline, fixed check order, local error handler, own JPEG orientation parser (ext-exif only in tests), structural animation checks"
  - "A PNG must carry an IHDR chunk right after its signature, otherwise it is refused as not an image (see Deviations)"

requirements-completed: [IMG-01, IMG-02]

duration: 35min
completed: 2026-11-27
---

# Phase 20 Plan 02: Image Pipeline Summary

**Pure GD image pipeline: PNG, JPEG, WebP and static GIF are re-encoded in their own format (no metadata, JPEG orientation applied, at most 2400 px), everything else is refused with exactly one reason.**

## Accomplishments

- `ImagePipeline::process()` follows the R-20-02-3 order: size, magic-byte sniff (SVG recognised for the message only), header type and size, pixel cap and memory preflight before any decode, animation check, decode, palette to truecolor, JPEG orientation, scale down onto a fresh canvas, same-format encode, size read back from the output, SHA-256.
- Proof of the strip (IMG-02, success criterion 2): a fictional JPEG with an Orientation tag, a GPS section and a COM segment comes back with no `Exif\0\0`, no GPS section and no Orientation; a random sentinel injected into JPEG COM and APP1, PNG tEXt and eXIf, a GIF comment and a WebP EXIF chunk is absent from every output.
- Orientation 1 to 8 is proven on a 40 x 20 red/blue photo (dimensions swap for 5 to 8, the red half lands where the transform says).
- Refusals (IMG-01): SVG (bare, prolog, BOM and whitespace, doctype, upper case), renamed text, empty input, BMP, corrupt PNG, truncated GIF, PNG with a runaway chunk, PNG magic over JPEG data are `NotAnImage`/`Svg`; 10 MiB + 1 is `TooLarge` (exactly 10 MiB of text is not); a 33-byte 30000 x 30000 header and a 4000 x 4000 header under a 32 MiB limit are `TooManyPixels`; animated GIF, APNG and animated WebP are `Animated`. Every case runs under a handler that fails on any escaping warning.

## Verification

- Worktree: `php -l` clean, Pint clean, PHPStan level 8 clean on `app/Domain/Knowledge/Images` (run from the main checkout vendor with a scratch config), `scripts/check-sensitive.sh` clean, pre-commit hook (sensitive-content, gitleaks) passed on both commits.
- Cheap DB-free run of both Unit files (main checkout vendor, worktree sources through a scratch bootstrap, no application, no database): 56 passed, 160 assertions.
- Not run here by design: the post-merge DDEV run `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge/ImagePipelineTest.php tests/Unit/Knowledge/ImageMetadataStripTest.php` stays the final verdict after the wave merge.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PNG magic bytes without IHDR gave an arbitrary size instead of "not an image"**
- **Found during:** Task 2 (case "PNG magic bytes in front of JPEG data")
- **Issue:** `getimagesizefromstring` trusts the 8-byte signature and reads width and height from fixed offsets, so PNG magic over JPEG data reports type PNG and a garbage size (about 1.2 billion x 16.8 million), which the planned order would turn into `TooManyPixels`, not `NotAnImage` as the plan states.
- **Fix:** `readHeader()` requires `IHDR` at offset 12 for PNG before the header is trusted.
- **Files modified:** app/Domain/Knowledge/Images/ImagePipeline.php
- **Commit:** 4dcb5186

**2. [Rule 3 - Blocking] `Animation.php` committed with Task 1**
- **Issue:** the plan lists `Animation.php` under Task 2, but the Task 1 pipeline calls `Animation::isAnimated()`, so Task 1 would not run without the class.
- **Fix:** `Animation` went into the Task 1 commit (f784683c); Task 2 only adjusted it for PHPStan.

**3. [Rule 1 - Bug] PHPStan level 8 findings in the first draft**
- **Issue:** dead `ValueError` catches, always-true `is_int`/`! $written` checks, an unreachable `>= 2` at the GIF trailer, an unused null in the WebP return type.
- **Fix:** removed them; behaviour unchanged, tests still green.
- **Commit:** 4dcb5186

**4. [Plan wording] `exif_read_data` reads a memory stream, not a data URI**
- The Unit helper passes a `php://memory` resource, which does not depend on the URL wrapper settings of the PHP configuration. The assertions are the ones of the plan.

## Known risks (not fixed, for the owner)

- Warnings are strict (R-20-02-4): if the GD build raises a PHP warning for a benign libpng or libjpeg condition, that upload is refused as `NotAnImage`. In the probe, libpng printed its eXIf notice to stderr without a PHP warning, so no real screenshot was affected, but the first upload of a macOS screenshot with an iCCP profile on the deploy target is worth a manual check (20-03 verifies the GD capabilities, not this).
- A5 stands: animated PNG and WebP are refused; relaxing it means deleting the two branches in `Animation::isAnimated()`.

## Known Stubs

None.

## Threat Flags

None. All four threats of the plan (T-20-05 to T-20-08) are mitigated and covered by tests; no new endpoint, auth path or schema was added.

## Self-Check: PASSED

- Created files exist: all ten files of `key-files.created` found.
- Commits found in history: f784683c (Task 1), 4dcb5186 (Task 2).
