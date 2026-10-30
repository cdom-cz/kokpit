---
phase: 09-documents-and-finance
plan: 17
subsystem: documents
tags: [documents, zip-archive, zipstream, queued-job, filament-bulk-action, idempotent]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: central documents listing and DocumentActions::bulkActions() (09-14), DocumentArchive record, status enum, policy and path CHECK (09-23), kokpit.documents.archive config (09-01)
  - phase: 08-time-reports-and-exchange-rates
    provides: BuildReportExport twin (temp file, store, lock and mark ready in one transaction)
provides:
  - RequestDocumentArchive action (Admin only, validated ids, count and byte limits, pending record, dispatch after commit)
  - BuildDocumentArchive queued KokpitJob streaming stored objects into one ZIP on the default private disk
  - bulk action "Stahnout jako ZIP" on the central listing, over hand-picked rows or a whole filter result
  - JobContractTest entry and Czech copy under documents.archives
affects: [09-18 archive list, download route and prune, 09-19 hardening (safe entry names, job-side limit re-check, failure handling, bells, direct zipstream requirement)]

actuals:
  tokens: 9000
  tasks: 3
  commits: 3
plan_head_before: d06b26fcd2c7994b9e64979baba8f07514c45057
plan_head_after: c5767f7b2089b09d21db3e10be3319c1617414b3
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Report-export twin for the ZIP: skip unless pending, tempnam, finally unlink, DB::transaction + lockForUpdate + markReady after the object is stored"
    - "Bulk action over a possibly huge filter result keeps only ids from chunked records and stops one past the configured limit; the Action owns validation"

key-files:
  created:
    - app/Domain/Documents/Actions/RequestDocumentArchive.php
    - app/Domain/Documents/Jobs/BuildDocumentArchive.php
    - tests/Feature/Documents/DocumentArchiveTest.php
  modified:
    - app/Filament/Support/DocumentActions.php
    - tests/Arch/JobContractTest.php
    - lang/cs/documents.php

key-decisions:
  - "The request is validated in the Action, not in the UI: empty, non-UUID, unknown, duplicated, over max_files and over max_bytes selections are refused with a Czech message under the key documents and create and push nothing"
  - "The record owner is the actor passed to the Action (forceFill of user_id), not whoever Auth::id() happens to be"
  - "The job pushes after the transaction that created the record has returned, so a worker can never look for a record that is not there yet"
  - "An empty ZIP is never produced: a request none of whose documents still exists makes the job throw and the record stays pending (failure marking and bells are plan 09-19)"

patterns-established:
  - "Job tests run the job through its own middleware with a Pipeline, so the system context is real and Queue::fake() can still prove what the Action pushed"

requirements-completed: [DO-02]

coverage:
  - id: D1
    description: "The Admin selects files in the central listing (hand-picked or a whole filter result), chooses the ZIP action, and a pending archive owned by that Admin is created and a queued job builds one ZIP with every selected file"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#tracer: the Admin selects two files, asks for a ZIP, and the job leaves a ready archive with both files on private storage"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the bulk action archives exactly the files of a filter result selected as a whole"
        status: pass
    human_judgment: false
  - id: D2
    description: "The ZIP is streamed from the media disk into a temporary file and stored on the default private disk under document-archives/<uuid>.zip; the record becomes ready with completion time and seven-day expiry"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#tracer: the Admin selects two files, asks for a ZIP, and the job leaves a ready archive with both files on private storage"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the build job removes its temporary file after a success and after an exception"
        status: pass
    human_judgment: false
  - id: D3
    description: "A request over the file or byte limit, an empty selection or an id that is not an existing document is refused with a Czech message and creates nothing; a Partner can never request an archive"
    requirement: DO-02
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the request limits"
        status: pass
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#refuses a Partner and creates nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the bulk action shows the refusal as a danger notification and creates nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "The job is idempotent and runs as system: a non-pending or missing record is skipped, a rerun of a half-built attempt overwrites the same path, and a file the Partner cannot see is still read"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveTest.php#the build job"
        status: pass
      - kind: integration
        ref: "tests/Arch/JobContractTest.php"
        status: pass
    human_judgment: false

# Metrics
duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 17: Background document ZIP Summary

**Admin bulk action "Stáhnout jako ZIP" on the central listing, a validated `RequestDocumentArchive` action and a queued idempotent `BuildDocumentArchive` job that streams the stored objects through ZipStream into one ZIP on private storage**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-16
- **Tasks:** 3
- **Files modified:** 6 (3 created, 3 modified)

## Accomplishments
- The Admin selects rows (or "select all" of a filter result), runs the new bulk action and is told "Archiv se připravuje"; a pending `DocumentArchive` owned by that Admin holds the ids in order, the count, the summed size and a server-built summary such as "3 soubory, 4 KB".
- `BuildDocumentArchive` is the twin of `BuildReportExport`: skip unless pending, `tempnam()`, ZipStream (`outputStream:`, `sendHttpHeaders: false`) over each object read as a stream from its media disk, `put()` to `document-archives/<uuid>.zip` on the default disk, then lock, re-check pending and `markReady()` in one transaction, with the temporary file removed in `finally`. `#[Timeout(240)]` stays under the 300 s supervisor.
- Limits are read from `config/kokpit.php` and enforced before anything is queued; the count is checked before the database is asked, so a huge selection never becomes a huge query.
- `BuildDocumentArchive::class` is registered in `JobContractTest::EXPECTED_APPLICATION_JOBS`.

## Task Commits

1. **Task 1: Tracer - the Admin selects two files, asks for a ZIP, and the job leaves a ready archive with both files on private storage** - `5c95cc1` (feat)
2. **Task 2: The request refuses empty, foreign, oversized and over-long selections** - `5193000` (test)
3. **Task 3: The build job is idempotent and runs as system** - `c5767f7` (test)

**Plan metadata:** committed with this summary (docs).

## Files Created/Modified
- `app/Domain/Documents/Actions/RequestDocumentArchive.php` - authorizes `create`, validates ids and limits, creates the pending record, dispatches the job after commit
- `app/Domain/Documents/Jobs/BuildDocumentArchive.php` - final, `#[Idempotent]`, `#[Timeout(240)]`, extends `KokpitJob`; streams the ZIP and marks the record ready
- `app/Filament/Support/DocumentActions.php` - `bulkArchive()` appended to `bulkActions()`; `DocumentResource` untouched
- `tests/Arch/JobContractTest.php` - the new job in the expected list
- `lang/cs/documents.php` - `archives.{bulk_action, queued, summary, errors}`
- `tests/Feature/Documents/DocumentArchiveTest.php` - 18 cases: tracer, Partner refusal, request limits, bulk action, build job

## Decisions Made
- The record owner is set from the actor given to the Action with `forceFill`, so the Action does not depend on the session user.
- The summary reuses `DocumentColumns::humanSize()` (binary units, Czech decimal comma) with a three-form `trans_choice`.
- The too-large message names the limit as a human size ("1 MB") rather than a bare integer, so a limit below one megabyte is still readable.
- Entry names are the display names of the documents, as the plan scopes; sanitising and de-duplication stay in plan 09-19.

## Deviations from Plan

None - plan executed exactly as written.

Process note: the Task 2 and Task 3 behaviours were already satisfied by the Task 1 implementation, which was written to the full contract (limits, set-up checks, idempotent skip, `finally` cleanup), so their new tests passed on first run and neither task changed production code. They are kept as separate test commits; the RED step of those two tasks was therefore not observed failing.

## Issues Encountered
None. Pint first reported import ordering in one test file and a superfluous `@return never` tag in the Action; both were fixed before the commits.

## Known Stubs
None.

## Threat Flags
None. T-09-54 is mitigated by the count and byte limits checked at request time and the 240 s timeout (the job-side re-check is plan 09-19); T-09-55 by the `create` authorization through `AdminOnlyPolicy` and the UUID shape, existence and distinctness checks, with the table CHECKs of plan 09-23 behind them. No Composer file was touched (T-09-SC).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
Ready for plan 09-18 (archive list, download route, 7-day prune) and plan 09-19 (safe entry names and de-duplication, limits re-checked in the job, failure marking and bells, `maennchen/zipstream-php` as a direct requirement).

## Self-Check: PASSED

- Created files exist: `RequestDocumentArchive.php`, `BuildDocumentArchive.php`, `DocumentArchiveTest.php` (checked on disk).
- Commits `5c95cc1`, `5193000` and `c5767f7` are on `base-crm-erp`.
- All acceptance criteria of the three tasks re-run and passing; `ddev exec vendor/bin/pest` green (4443 passed, 3 skipped), Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
