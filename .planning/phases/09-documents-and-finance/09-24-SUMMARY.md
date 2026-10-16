---
phase: 09-documents-and-finance
plan: 24
subsystem: documents
tags: [livewire, temporary-uploads, s3, presigned-put, production-guard, ddev, cors]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01 kokpit.documents.max_size_kb setting and the media library ceiling"
provides:
  - "Published Livewire config with the s3 disk as default temporary-upload disk, one 10 MB rule, no preview URLs"
  - "UploadConfigTest pinning disk, S3 branch, rule, previews, throttle and the DDEV/phpunit values"
  - "ProductionConfigGuard rule refusing a temporary-upload disk without the s3 driver"
  - "DDEV local override in .env.example and the CONTRIBUTING bucket requirements (CORS PUT from the instance origin only, one-day expiry on livewire-tmp/)"
affects: [09-02 upload rules, 09-20 operator notes and deploy rehearsal, every later form with a file field]

actuals:
  tokens: 6500
  tasks: 3
  commits: 3
plan_head_before: 7c0c72f43fd8bdfbbc9a1fcf2671eab23e80b054
plan_head_after: bd5e1781e96d69e75aac480d132367296d421a36
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Temporary upload disk defaults to the s3 disk itself, not FILESYSTEM_DISK, so the suite and production take the same Livewire branch"
    - "Production guard resolves the disk exactly as Livewire does (own key, then default disk) and checks the driver, not the name"
    - "Test uploads fill file fields one file at a time, like the browser on S3"

key-files:
  created:
    - config/livewire.php
    - tests/Feature/Documents/UploadConfigTest.php
  modified:
    - app/Support/ProductionConfigGuard.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - .env.example
    - phpunit.xml
    - CONTRIBUTING.md

key-decisions:
  - "Temporary disk defaults to env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 's3'); phpunit.xml pins s3 so a developer .env cannot change what the suite tests"
  - "DDEV keeps temporary uploads on the local disk through .env.example (its RustFS endpoint is not browser-reachable); production fails closed because the guard refuses a non-s3 driver"
  - "preview_mimes is an empty list: no temporary preview URL for any file (presigned read URL on the bucket, SVG as script on the app origin)"
  - "Rule is the literal max:10240, pinned equal to kokpit.documents.max_size_kb and media-library.max_file_size by UploadConfigTest"

patterns-established:
  - "UploadConfigTest compares the published Livewire config with the vendor defaults and allows only the four documented keys to differ"

requirements-completed: [DO-01]

coverage:
  - id: D1
    description: "Livewire temporary uploads default to the private s3 disk, the suite runs that setting and a Livewire upload takes the presigned-URL S3 branch without contacting the bucket"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/UploadConfigTest.php#it holds temporary uploads on the bucket disk in the suite, as production does"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/UploadConfigTest.php#it takes the bucket branch when an upload starts"
        status: pass
    human_judgment: false
  - id: D2
    description: "One 10 MB upload rule everywhere, no temporary preview URL, upload throttle kept, nothing else of the Livewire config changed"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/UploadConfigTest.php#it applies one size rule everywhere, equal to the limit of the media library"
        status: pass
      - kind: unit
        ref: "tests/Feature/Documents/UploadConfigTest.php#it offers no temporary preview URL for any file type"
        status: pass
      - kind: unit
        ref: "tests/Feature/Documents/UploadConfigTest.php#it changes nothing else of the published Livewire config"
        status: pass
    human_judgment: false
  - id: D3
    description: "Production refuses to boot when the temporary-upload disk (or the default disk it falls back to) has no s3 driver"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php#it throws in production when the temporary upload disk is not a bucket disk"
        status: pass
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php#it falls back to the default disk like Livewire when the temporary upload disk is empty"
        status: pass
    human_judgment: false
  - id: D4
    description: "DDEV local override, CORS PUT-from-instance-origin rule and one-day livewire-tmp/ expiry written down for operators"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/UploadConfigTest.php#it keeps DDEV uploads on the local disk while the suite pins the bucket"
        status: pass
    human_judgment: true
    rationale: "The wording of the CONTRIBUTING bullet and the real browser-to-bucket CORS path are verified by the deploy rehearsal (plan 09-20), not by a test in this repository"

# Metrics
duration: 13min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 24: Livewire temporary uploads on the private bucket Summary

**Livewire temporary uploads default to the private s3 bucket (presigned PUT, 10 MB, no preview URLs), the suite runs that setting, production refuses any other temporary disk, and DDEV keeps a documented local override.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-10-16T12:02:46Z
- **Completed:** 2026-10-16T12:15:28Z
- **Tasks:** 3
- **Files modified:** 7

## Accomplishments
- `config/livewire.php` published from Livewire 4.4.7; only four `temporary_file_upload` keys differ from the vendor defaults (disk `s3`, rule `max:10240`, throttle `60,1`, empty `preview_mimes`), each with a comment; `UploadConfigTest` fails if any other key drifts.
- `phpunit.xml` pins `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3`: `isUsingS3()` is true in the suite and a component upload dispatches `upload:generatedSignedUrlForS3` while the bytes stay on Livewire's faked disk.
- `ProductionConfigGuard` throws in production unless the resolved temporary disk (own key, else `filesystems.default`) names a configured disk with the `s3` driver; the DDEV `local` override cannot reach production.
- `.env.example` carries `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local` for DDEV; the CONTRIBUTING Storage bullet records the CORS and expiry requirements, the guard, per-file test uploads and the CSP note.

## Task Commits

1. **Task 1: Tracer - upload under the production setting takes the bucket branch** - `d59a443` (feat)
2. **Task 2: Production refuses a temporary-upload disk that is not the private bucket** - `28fe129` (feat)
3. **Task 3: DDEV path and bucket requirements written down** - `bd5e178` (docs)

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `config/livewire.php` - published Livewire config with the four changed upload keys
- `tests/Feature/Documents/UploadConfigTest.php` - pins disk, S3 branch, per-file rule, size rule, previews, throttle, untouched keys, `.env.example` and `phpunit.xml` values
- `app/Support/ProductionConfigGuard.php` - temporary-upload disk rule (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`)
- `tests/Unit/Support/ProductionConfigGuardTest.php` - fixtures gain a valid disk set; new cases for the rule
- `.env.example` - DDEV-only local temporary disk with comment
- `phpunit.xml` - `s3` pin
- `CONTRIBUTING.md` - Storage bullet: temporary uploads on the bucket, CORS, expiry, guard, DDEV, tests, CSP

## Decisions Made
See key-decisions; all follow the plan. Pint's `declare_strict_types` rule was applied to the published config file.

## Deviations from Plan

None - plan executed exactly as written. Two test-shape details differed from the plan wording without changing behaviour: the S3 branch is asserted on `_startUpload` directly (Testable's `upload()` helper overwrites the dispatch effect with its later `_finishUpload` call), and the probe component is a named class in the test file.

## Issues Encountered
None. The full suite (4076 passed, 3 skipped), Pint and PHPStan are clean. The browser-to-bucket CORS path cannot be exercised in this repository and is left to the deploy rehearsal of plan 09-20.

## Known Stubs
None.

## User Setup Required
None - no external service configuration required. An existing DDEV `.env` needs the line `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local` added (documented in CONTRIBUTING); the bucket CORS and expiry rules are production operator tasks for plan 09-20.

## Next Phase Readiness
- Forms of plan 09-02 and later can upload files under the production setting; tests should fill file fields one file at a time.
- Plan 09-20 must state the CORS (PUT from the instance origin only) and one-day `livewire-tmp/` expiry requirements in the operator notes and check them in the deploy rehearsal.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
