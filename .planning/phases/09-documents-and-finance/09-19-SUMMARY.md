---
phase: 09-documents-and-finance
plan: 19
subsystem: documents
tags: [documents, zip-archive, zipstream, notifications, bell, zip-slip, limits, composer]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-17 RequestDocumentArchive and BuildDocumentArchive; 09-18 DocumentArchiveResource (the list the bell links to); 09-23 document_archives table and constraints"
  - phase: 08-time-reports-and-exchange-rates
    provides: "ReportExportReadyNotification / ReportExportFailedNotification and the BuildReportExport failed() pattern"
provides:
  - "ArchiveEntryNames: safe() and unique() for ZIP entry names (zip-slip proof, case-insensitive numbering)"
  - "BuildDocumentArchive hardening: limits re-read from the database, deleted documents skipped, failure handling through failed() and a direct fail on exceeded limits"
  - "DocumentArchiveReadyNotification and DocumentArchiveFailedNotification (database only, linking to the archives list)"
  - "maennchen/zipstream-php ^3.2 as a direct, licence-checked requirement"
  - "tests/Feature/Schema/DocumentArchivesTableTest.php: raw-SQL proof of every archive table refusal"
affects: [phase 9 verification, 09 documents UI polish]

actuals:
  tokens: 11600
  tasks: 3
  commits: 3
plan_head_before: 21c17861649165f45bb1025960add6e2fd9164ec
plan_head_after: 531e8feb9ed98929db0ac7e65970453455a0b4ef
commits: 3

# Tech tracking
tech-stack:
  added: ["maennchen/zipstream-php ^3.2 (MIT, already locked at 3.2.2 through spatie/laravel-medialibrary; now a direct requirement)"]
  patterns:
    - "Twin of the report export bells: scalars in, e() once, database channel only, notifyNow() inside the mark-ready transaction"
    - "A permanent failure (limits exceeded, nothing left) fails the record and rings the failure bell without throwing; a technical failure throws, is retried, and failed() gives up after the last attempt"
    - "Entry names pass safe() then a per-archive unique() instance before they reach ZipStream"

key-files:
  created:
    - app/Domain/Documents/Support/ArchiveEntryNames.php
    - app/Domain/Documents/Notifications/DocumentArchiveReadyNotification.php
    - app/Domain/Documents/Notifications/DocumentArchiveFailedNotification.php
    - tests/Unit/Documents/ArchiveEntryNamesTest.php
    - tests/Feature/Schema/DocumentArchivesTableTest.php
  modified:
    - app/Domain/Documents/Jobs/BuildDocumentArchive.php
    - lang/cs/documents.php
    - composer.json
    - composer.lock
    - tests/Feature/Documents/DocumentArchiveTest.php

key-decisions:
  - "Exceeded limits and an archive with no remaining document fail the record directly (no exception), because a retry cannot change the outcome; a read error still throws and is handled by failed() after the retries"
  - "The ready bell counts the files actually added to the ZIP, not the stored document_count"
  - "safe() takes the extension from the document, or from the name when none is given, and never doubles an extension the name already carries"
  - "No migration change: the 09-23 constraints already refused everything the plan lists"

patterns-established:
  - "failed() enters the system context itself, because the job middleware does not wrap it"
  - "Hostile test names are built from code points at runtime"

requirements-completed: [DO-02]

coverage:
  - id: D1
    description: "A finished archive gives the requesting Admin exactly one bell with the file count, the expiry day and a link to the archives list; a second Admin and a Partner get nothing; there is no e-mail"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#rings one bell for the requesting Admin with the count, the expiry day and a link to the archives list"
        status: pass
    human_judgment: false
  - id: D2
    description: "A failed build marks the record failed and rings one failure bell; a rolled-back mark-ready sends no bell; a late failure leaves a ready record alone"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the bells"
        status: pass
    human_judgment: false
  - id: D3
    description: "ZIP entry names carry no path separator, parent segment, leading dot or control character, are capped, and duplicates are numbered case-insensitively with the right bytes in each entry"
    requirement: DO-02
    verification:
      - kind: unit
        ref: "tests/Unit/Documents/ArchiveEntryNamesTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#keeps two documents of the same name apart inside the ZIP with the right bytes"
        status: pass
    human_judgment: false
  - id: D4
    description: "The job re-checks count and bytes from the database, skips deleted documents and fails with a bell when a limit is exceeded or nothing remains"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the entry names and the limits of the job"
        status: pass
    human_judgment: false
  - id: D5
    description: "The archive table refuses an unknown status, an incomplete ready row, a path outside document-archives/<uuid>.zip, non-array or mismatching ids, a bad count or size and an unknown user"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/DocumentArchivesTableTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "maennchen/zipstream-php is a direct requirement and passes the licence check; the lock diff shows only the content hash change"
    requirement: DO-02
    verification:
      - kind: other
        ref: "ddev composer check-licenses (225 packages, every one has an allowed licence)"
        status: pass
    human_judgment: false

# Metrics
duration: 40min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 19: ZIP archive hardening and bells Summary

**ZIP builds now have zip-slip-proof, case-insensitively unique entry names, limits re-read from the database, a failure path, ready and failed bells that link to the archives list, proven archive table refusals, and ZipStream as a direct licence-checked dependency.**

## Performance

- **Duration:** about 40 min (start time was not recorded; estimate)
- **Completed:** 2026-10-16T16:40:00Z
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files modified:** 10 (5 created, 5 modified)

## Accomplishments

- The requesting Admin gets one bell when the ZIP is ready (file count, expiry day, link to `/admin/document-archives`) or failed; database channel only, scalars escaped once, no e-mail, and no Partner ever receives one. The ready bell is sent in the transaction that marks the record ready, so a rollback sends nothing.
- `ArchiveEntryNames::safe()` removes separators, `..` runs, leading dots, controls and bidirectional marks, normalises to NFC, caps the stem at 150 characters, keeps the extension and falls back to "soubor"; `unique()` numbers duplicates ("Faktura (2).pdf") case-insensitively and skips numbers already taken.
- `BuildDocumentArchive` re-reads the documents, skips deleted ones, counts only included files in the bell, and fails the archive with the failure bell when nothing remains or the file or byte limit (read from `kokpit.documents.archive.*`) is exceeded; a read error still throws and `failed()` marks the record failed and rings the bell after the last attempt.
- The archive table refusals (status, ready completeness, path shape, ids array and count, bytes, owner) are proven by raw SQL (23514 and 23503); the existing 09-23 migration needed no change.
- `maennchen/zipstream-php ^3.2` is a direct requirement; the lock keeps 3.2.2 and only its content hash changed; `composer check-licenses` is green.

## Task Commits

1. **Task 1: Tracer - a finished ZIP rings the Admin's bell with a link to the archives list** - `0ff513b` (feat)
2. **Task 2: Safe, unique entry names and limits re-checked in the job** - `9316713` (feat)
3. **Task 3: Archive table refusals and ZipStream as a direct, licence-checked dependency** - `531e8fe` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

Tracer gate: the tracer `<verify>` (targeted Pest tests, markup and leak tests, Pint, PHPStan) was re-run end to end after the commit and passed before expansion.

## Files Created/Modified

- `app/Domain/Documents/Support/ArchiveEntryNames.php` - safe and unique ZIP entry names
- `app/Domain/Documents/Notifications/DocumentArchiveReadyNotification.php` - ready bell with count, expiry day and list link
- `app/Domain/Documents/Notifications/DocumentArchiveFailedNotification.php` - failure bell with the list link
- `app/Domain/Documents/Jobs/BuildDocumentArchive.php` - limit re-check, entry names, ready bell in the mark-ready transaction, `failed()` and the shared give-up path
- `lang/cs/documents.php` - `archives.bell.*` copy
- `composer.json`, `composer.lock` - direct ZipStream requirement
- `tests/Unit/Documents/ArchiveEntryNamesTest.php` - pure cases of safe() and unique()
- `tests/Feature/Documents/DocumentArchiveTest.php` - bell, failure, limit and entry-name cases; the old "fails when none is left" half now lives in the new "fails with the failure bell, without throwing" test
- `tests/Feature/Schema/DocumentArchivesTableTest.php` - raw-SQL refusals of the archive table

## Decisions Made

- Permanent failures (limits exceeded, no document left) fail the record directly instead of throwing, because retries cannot change the result and the Admin should get the failure bell at once; technical read errors still throw so the queue retries and `failed()` finishes the job.
- The ready bell counts the files that went into the ZIP, so a document deleted since the request is not promised.
- No migration change was needed.

## Deviations from Plan

None - plan executed exactly as written. (The existing 09-17 test that expected a thrown exception when every document was deleted was updated to the new, plan-mandated behaviour of failing with a bell; this is the plan's intent, not a deviation.)

**Total deviations:** 0.
**Impact on plan:** none.

## Issues Encountered

- `vendor/bin/pest --parallel` aborts in this environment (a duplicate-import error is reported from an unrelated test file); the project test command `ddev exec vendor/bin/pest` was used instead and the full suite is green (4527 passed, 3 skipped).
- BSD `grep` treats `^` inside a pattern as an anchor, so the composer.json acceptance check was verified with `grep -F`.

## Known Stubs

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- The ZIP flow (request, build, bell, list, download, prune) is complete; Roadmap Phase 9 success criterion 3 (ZIP part) is met.
- No blockers.

## Self-Check: PASSED

- Created files exist: ArchiveEntryNames.php, DocumentArchiveReadyNotification.php, DocumentArchiveFailedNotification.php, ArchiveEntryNamesTest.php, DocumentArchivesTableTest.php.
- Commits `0ff513b`, `9316713`, `531e8fe` are ancestors of HEAD.
- Acceptance criteria of all three tasks re-run and passing; full Pest suite, Pint, PHPStan, `composer check-licenses` and `scripts/check-sensitive.sh` green.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
