---
phase: 09-documents-and-finance
plan: 02
subsystem: documents
tags: [documents, upload-hardening, livewire-temporary-upload, medialibrary, finfo, s3]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01 AttachDocuments entry point, DocumentOwnerResolver, kokpit.documents limits, documents table"
provides:
  - AttachDocuments hardened for untrusted content (forged state, limits, extensions, archived owners, all-or-nothing)
  - DocumentFileName (safe display names, extensions, disallowed-extension check)
  - One byte-level upload path that behaves the same for a local file and a Livewire temporary upload on the private bucket
  - Client, Project, Task and TaskComment documents() relations
  - lang/cs/documents.php with the upload error texts
affects: [09-05 tabs, 09-10 forms, 09-12 forms, 09-24 livewire temporary disk, 09-03 download MIME allowlist]

actuals:
  tokens: 9950
  tasks: 3
  commits: 3
plan_head_before: bb124ac2704f33e74998a309974903e0fba14f9e
plan_head_after: 71501e2d0421ee31c9801ad7d7b87de5d9c78674
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Validate the whole upload list before any write, collect refusals under the field key 'files'"
    - "Copy upload bytes into a local tempnam file bounded at limit+1, finfo on the copy, hand the copy to medialibrary"
    - "Track media written by the call and delete the objects before rethrowing the original exception"
    - "Delete the Livewire temporary object after commit, keep it after a refusal"

key-files:
  created:
    - app/Domain/Documents/Support/DocumentFileName.php
    - lang/cs/documents.php
    - tests/Unit/Documents/DocumentFileNameTest.php
    - tests/Feature/Documents/TemporaryUploadS3Test.php
  modified:
    - app/Domain/Documents/Actions/AttachDocuments.php
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Projects/Models/Project.php
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Tasks/Models/TaskComment.php
    - tests/Feature/Documents/AttachDocumentsTest.php

key-decisions:
  - "Only UploadedFile instances reach storage; a string or nested array in the files list is refused under 'files' (forged Filament state)"
  - "Display name is cleaned (NFC, controls, bidi, separators, leading dots, 255 chars, fallback 'soubor'); the stored object stays <uuid>.<ext>"
  - "Every upload is copied to a local file bounded at limit+1 bytes; finfo on the copy decides the MIME type; declared type and bucket metadata are ignored"
  - "The disallowed-extension list is the package list from config (D-06); no extra extensions were added"
  - "Archived-owner check runs inside the transaction as system with a shared lock; a comment follows its task"
  - "An empty (0 byte) upload is refused as unreadable, as the plan specifies"

patterns-established:
  - "Test helper for a Livewire temporary upload with a lying declared type: object plus .json meta on the tmp-for-tests disk"
  - "Partial Mockery of TemporaryUploadedFile needs the class[methods] form, because the constructor calls path()"

requirements-completed: [DO-01]

coverage:
  - id: D1
    description: "A string or nested array in the files list (forged Filament state) and an empty list are refused under 'files' and nothing is stored"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it refuses a forged string or nested array state under files and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it refuses an empty list of files"
        status: pass
    human_judgment: false
  - id: D2
    description: "A hostile file name is stored as a clean NFC display name with no control, bidi or separator characters; the object name stays <uuid>.<ext>"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Unit/Documents/DocumentFileNameTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it stores a hostile file name as a clean NFC display name and lists it on its task"
        status: pass
    human_judgment: false
  - id: D3
    description: "Size, file count and disallowed extension (any segment, any case) are refused with Czech errors before any write, from the one config value, with no Admin bypass"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php (limit, count and extension cases)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Archived project, task, comment task and client receive no new files; existing files and objects stay (D-21)"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it refuses to attach to an archived project, task, comment task or client and keeps the existing files (D-21)"
        status: pass
    human_judgment: false
  - id: D5
    description: "A multi-file upload is all or nothing: a forced failure of the third file leaves no row, no media row and no object, and rethrows the original exception"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it leaves no row and no object when the third file of an upload fails, and rethrows the original error"
        status: pass
    human_judgment: false
  - id: D6
    description: "One byte-level path: stored size checked before any read, copy bounded at the limit, MIME from finfo on the copy, local copy always removed, temporary object deleted after commit and kept after a refusal"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php (temporary upload cases: lying type, zero read, bounded read, deletion, no local copy, unreadable, empty)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/TemporaryUploadS3Test.php#it stores a temporary upload held on the bucket with the type of its bytes and removes the temporary object (s3 group, RustFS in DDEV)"
        status: pass
    human_judgment: false
  - id: D7
    description: "Client::documents() lists every file of the client; Project, Task and TaskComment documents() list the files they own"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it lists the files of a client, project, task and comment through their documents relations"
        status: pass
    human_judgment: false

# Metrics
duration: 30min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 02: Upload Hardening Summary

**AttachDocuments now accepts only real uploaded files, cleans display names, enforces the shared size/count/extension limits and the archived-owner rule, writes all files of a submit or none, and reads every upload (local or Livewire temporary on the private bucket) through one bounded byte-level path with MIME detection by finfo**

## Performance

- **Duration:** about 30 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T11:27Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 10 (4 created, 6 modified)

## Accomplishments

- Forged Filament state (a string path or nested array) and an empty list are refused before authorization side effects, under the key `files`, with Czech texts.
- `DocumentFileName` cleans the client's file name (NFC, C0/C1 controls, bidi controls U+200E/F, U+202A-E, U+2066-9, path separators, leading dots, 255 characters, fallback `soubor`), derives a plain `[a-z0-9]{1,10}` extension and checks every dot segment against `media-library.disallowed_extensions`.
- Limits come from `kokpit.documents.*` only (size, count), the same for Admin and Partner; all refusals of a list are reported together before anything is written.
- Archived project, task, client, or the task of a comment: refused inside the transaction with a shared lock; existing files and objects are untouched (D-21).
- All-or-nothing: media written by the call are tracked; on any `Throwable` their objects and directories are deleted as best effort and the original exception is rethrown.
- Byte-level path: `getSize()` (stored size) is checked before a stream is opened; bytes are copied into a `kokpit-upload-*` temp file bounded at limit+1 bytes; `finfo` and `filesize` run on the copy; the copy is handed to medialibrary and removed in `finally`. A Livewire temporary object is deleted after commit (best effort, reported by exception class only) and kept after a refusal or failure.
- Real-bucket proof (s3 group): an object with `Content-Type: image/png` and plain-text bytes is stored as `text/plain`, lands in the media path, and the `livewire-tmp/` object is gone.
- `documents()` relations on Client (by `client_id`), Project, Task and TaskComment (`MorphMany` on `owner`).

## Task Commits

1. **Task 1: Tracer - accept only real uploads and clean the file names** - `8bb0adf` (feat)
2. **Task 2: Limits, disallowed extensions, archived owners and all-or-nothing uploads** - `22704f0` (feat, TDD: cases written and observed red, then implemented)
3. **Task 3: One byte-level path for every upload, proven on the real bucket** - `71501e2` (feat, TDD)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the tracer's tests, Pint and PHPStan were re-run end to end (green) before expansion.

## Files Created/Modified

- `app/Domain/Documents/Support/DocumentFileName.php` - display name, extension, disallowed-extension check
- `app/Domain/Documents/Actions/AttachDocuments.php` - hardened entry point, byte-level path, cleanup
- `app/Domain/Clients/Models/Client.php`, `Projects/Models/Project.php`, `Tasks/Models/Task.php`, `Tasks/Models/TaskComment.php` - `documents()` relations
- `lang/cs/documents.php` - `documents.errors.*` texts
- `tests/Unit/Documents/DocumentFileNameTest.php`, `tests/Feature/Documents/AttachDocumentsTest.php`, `tests/Feature/Documents/TemporaryUploadS3Test.php` - unit, feature and real-bucket tests

## Decisions Made

See `key-decisions` in the frontmatter. Besides those: the over-limit error shows the limit in MB with at most two decimals and no trailing zeros ("10"); the description is trimmed, blank becomes null and it is capped at 2000 characters.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The plan's example `x.exe` is not on the package's disallowed list**
- **Found during:** Task 2 (writing the extension cases)
- **Issue:** Task 2 behavior names `x.exe` as refused, but D-06 fixes the list to the package's `disallowed_extensions` (php, phtml, phar, ...) with no whitelist or extra entries; `exe` is not on it.
- **Fix:** Followed D-06 and did not widen the list; the test uses `archive.phar` instead of `x.exe`. Adding extensions is a config decision for the owner.
- **Files modified:** `tests/Feature/Documents/AttachDocumentsTest.php`
- **Verification:** the three disallowed cases (`report.php.pdf`, `Script.PHTML`, `archive.phar`) pass.
- **Committed in:** 22704f0

**2. [Rule 1 - Bug] Livewire's TemporaryUploadedFile cannot be built as a plain Mockery partial**
- **Found during:** Task 3
- **Issue:** `Mockery::mock(Class, args)->makePartial()` runs the constructor before partial mode, and the constructor calls `path()`.
- **Fix:** Tests use the `Class[method,...]` partial form for the zero-read and unreadable cases.
- **Files modified:** `tests/Feature/Documents/AttachDocumentsTest.php`
- **Committed in:** 71501e2

**Total deviations:** 2 auto-fixed (both test-side). **Impact:** none on production behavior.

## Authentication Gates

None.

## Issues Encountered

- The sensitive-content checker flagged an 8-digit number in a docblock; the docblock was reworded (no number) before the commit of Task 2.
- Running Pint with an explicit `lang` path reformatted unrelated Laravel lang files; those changes were reverted and never staged (`vendor/bin/pint --test` over the default paths is clean).

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-04 to T-09-07 and T-09-72 to T-09-74 of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 3982 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pest tests/Feature/Documents/TemporaryUploadS3Test.php --group=s3`: passed against RustFS in DDEV
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean

## Next Phase Readiness

Ready for plan 09-03 (download route) and the form plans that call `AttachDocuments`. Plan 09-24 configures the Livewire temporary disk, the production guard and the bucket lifecycle rule that expires leftovers of refused submits.

## Self-Check: PASSED

- Files exist: `DocumentFileName.php`, `lang/cs/documents.php`, `DocumentFileNameTest.php`, `TemporaryUploadS3Test.php` (found)
- Commits `8bb0adf`, `22704f0`, `71501e2` are ancestors of HEAD
- All task acceptance criteria re-run: pass
