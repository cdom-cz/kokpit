---
phase: 09-documents-and-finance
plan: 23
subsystem: documents
tags: [documents, zip-archive, owner-scope, postgres-check, canary, morph-map]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: kokpit.documents.archive config, Document model and registration lists (09-01), Transaction registrations (09-09), enum labels (09-22)
  - phase: 08-time-reports-and-exchange-rates
    provides: ReportExport twin (OwnedByUser, expiry hook, state methods)
provides:
  - document_archives table with pinned path shape, ids array and count checks
  - DocumentArchive model, owner-scoped and closed to Partners
  - DocumentArchiveStatus enum (Pending, Ready, Failed) with Czech labels
  - morph alias document_archive, AdminOnlyPolicy, arch and canary registrations
affects: [09-17 archive request and build job, 09-18 archive list, route and prune, 09-19 hardening and database refusal proofs]

actuals:
  tokens: 5658
  tasks: 2
  commits: 2
plan_head_before: 385d8fa441b24492b92ea25952b405a2da1530d2
plan_head_after: 4ec72056afbe6cd672531eccb46cebbb78f61ebd
commits: 2

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Report-export twin: OwnedByUser, creating hook that sets expires_at, expiry from creation then from completion, markReady/markFailed lifecycle"
    - "Length check guarded by CASE on jsonb_typeof so the type check, not a function error, refuses a non-array"

key-files:
  created:
    - database/migrations/2026_10_16_000500_create_document_archives_table.php
    - app/Domain/Documents/Models/DocumentArchive.php
    - app/Domain/Documents/Enums/DocumentArchiveStatus.php
    - tests/Feature/Documents/DocumentArchiveRecordTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - lang/cs/enums.php

key-decisions:
  - "DocumentArchive copies ReportExport: an archive belongs to the Admin who asked, a second Admin and every Partner read nothing, enforced by OwnedByUser plus AdminOnlyPolicy and proven by the canary harness"
  - "The download name is soubory-YYYY-MM-DD.zip from the Prague creation day only, so no file or client name can reach it"
  - "document_archives_count_check wraps jsonb_array_length in a CASE on jsonb_typeof, so a non-array value is refused by document_archives_ids_check instead of raising a function error"

patterns-established:
  - "Owner-scoped Admin-only record with a pinned storage path shape: the registration set (morph alias, policy, arch list, canary fixture and list) lands in the same plan as the model"

requirements-completed: [DO-02]

coverage:
  - id: D1
    description: "An archive is a record owned by the Admin who asked: that Admin reads it, a second Admin reads nothing and a Partner reads no archive at all, also proven by the canary harness"
    requirement: DO-02
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveRecordTest.php#visibility"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "The table keeps the requested ids as a JSON array with count and size, a status and a pinned path, with a UUID v7 key"
    requirement: DO-02
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveRecordTest.php#creating (Admin)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/SchemaConventionsTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Expiry, download state and download name: seven days from creation then from completion, downloadable only while ready with a path and strictly before expiry, soubory-YYYY-MM-DD.zip from the Prague day"
    requirement: DO-02
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveRecordTest.php#lifecycle"
        status: pass
      - kind: unit
        ref: "tests/Feature/Documents/DocumentArchiveRecordTest.php#naming"
        status: pass
    human_judgment: false

# Metrics
duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 23: Document archive record Summary

**Owner-scoped `document_archives` table and `DocumentArchive` model (twin of `ReportExport`) with expiry, download state and a Prague-day `soubory-YYYY-MM-DD.zip` name, registered in the morph map, policy, arch and canary suites**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-16T14:31:32Z
- **Tasks:** 2
- **Files modified:** 10 (4 created, 6 modified)

## Accomplishments
- The storage half of the ZIP download (D-08): a pending archive is created for the signed-in Admin with its document ids, count, total size and server-built summary; the id defaults to a UUID v7.
- The Admin who asked reads the archive; a second Admin, a Partner with a client and a Partner without one read nothing, and the Gate denies the Partner every ability. The canary harness covers the new model on every run through a ready-row fixture written as system for the canary Admin.
- The database pins the shape: status list, ready needs path and completion time, path `document-archives/<uuid>.zip`, ids is an array, count equals the array length and is at least one, bytes is not negative.
- Lifecycle: `expires_at` is creation plus `kokpit.documents.archive.ttl_days` (7), `markReady()` moves it to completion plus the retention, `markFailed()` keeps the creation expiry, `isDownloadable()` is true only for a ready archive with a path strictly before expiry, `ttlDays()` never returns less than 1.
- Download name `soubory-YYYY-MM-DD.zip` from the Prague creation day (23:30 UTC on 2026-10-16 gives `soubory-2026-10-17.zip`).

## Task Commits

1. **Task 1: Tracer - an Admin's archive record is stored and read back by that Admin only, under every convention** - `52edd1d` (feat)
2. **Task 2: Expiry, download state and the download name of an archive** - `4ec7205` (feat, TDD: the four lifecycle and naming tests failed first with undefined methods, then passed)

**Plan metadata:** committed with this summary (docs).

## Files Created/Modified
- `database/migrations/2026_10_16_000500_create_document_archives_table.php` - table, indexes and six CHECK constraints
- `app/Domain/Documents/Models/DocumentArchive.php` - final, PartnerIsolated, OwnedByUser model with `ttlDays`, `storagePath`, `downloadFileName`, `isDownloadable`, `markReady`, `markFailed`
- `app/Domain/Documents/Enums/DocumentArchiveStatus.php` - Pending, Ready, Failed with label and colour
- `app/Domain/Shared/Database/MorphMap.php` - alias `document_archive`
- `app/Providers/AccessServiceProvider.php` - `Gate::policy(DocumentArchive::class, AdminOnlyPolicy::class)`
- `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Support/CanaryRegistry.php` - model lists and the ready-row canary fixture
- `lang/cs/enums.php` - `document_archive_status` labels
- `tests/Feature/Documents/DocumentArchiveRecordTest.php` - 15 cases: creation, visibility, labels, naming, lifecycle

## Decisions Made
- Followed the twin as recorded in the plan. Task 1 shipped the model with the creating hook, `ttlDays()` and `storagePath()`; the remaining four methods were added in Task 2 after their tests failed, so the TDD step of Task 2 is real rather than nominal.
- The count CHECK guards `jsonb_array_length` with a CASE on `jsonb_typeof`. PostgreSQL checks constraints in name order and `document_archives_count_check` sorts before `document_archives_ids_check`; unguarded, a scalar would raise a function error instead of a named ids violation, which plan 09-19's raw-SQL proofs will want to see.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None. One transient failure during Task 2 (a missing `CarbonInterface` import in the model) was fixed on the spot before the commit.

## Known Stubs
None.

## Threat Flags
None. T-09-56 (archive read by another Admin or a Partner) is mitigated by `OwnedByUser`, `AdminOnlyPolicy`, the canary fixture and the second-Admin and Partner tests; T-09-57 (path outside the archive area) by the path CHECK, whose raw-SQL proof is plan 09-19. No Composer file was touched (T-09-SC).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
Ready for plan 09-17 (request Action, build job and bulk action over this model), 09-18 (list, download route and prune) and 09-19 (hardening and the database refusal proofs).

## Self-Check: PASSED

- Created files exist: migration, `DocumentArchive.php`, `DocumentArchiveStatus.php`, `DocumentArchiveRecordTest.php` (checked on disk).
- Commits `52edd1d` and `4ec7205` are on `base-crm-erp`.
- All acceptance criteria of both tasks re-run and passing; `ddev exec vendor/bin/pest` green (4301 passed, 3 skipped), Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
