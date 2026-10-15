---
phase: 08-exchange-rates-and-reports
plan: 12
subsystem: reports
tags: [report-exports, scheduler, queue-job, private-storage, retention, idempotent]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-07 ReportExport (OwnedByUser, path, expires_at, storagePath(), the report_exports_path_check constraint, kokpit.exports.ttl_days)"
  - phase: 03-foundation-operations
    provides: "KokpitJob, #[Idempotent], RunsAsSystem and the JobContractTest list of application jobs"
provides:
  - "PruneExpiredReportExports: a daily idempotent KokpitJob that deletes the private-disk file and then the record of every report export past expires_at, for every owner"
  - "Schedule entry kokpit-report-export-prune (daily, onOneServer) in routes/console.php"
  - "ReportExportPruneTest: expired vs fresh, records without files, two owners, second run, expiry instant, 250 records in chunks, files outside the export area"
affects: [08-16 CSV build and export action, 08-21 XLSX build]

plan_head_before: f08c392566f8cbd90bdd480c2b49fe684e7681bc
plan_head_after: 26c70e447ae096867b8e48a2f4baf63854137a0a

estimate:
  tokens: 41000
  raw_tokens: 41000
  tasks: 2
  confidence: low

actuals:
  tokens: 3400
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A retention prune is a KokpitJob that runs as system, reads expired rows with chunkById, deletes the file before the row, and tolerates a missing file"

key-files:
  created:
    - app/Domain/Reports/Jobs/PruneExpiredReportExports.php
    - tests/Feature/Reports/ReportExportPruneTest.php
  modified:
    - routes/console.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - tests/Arch/JobContractTest.php

key-decisions:
  - "The prune runs as system through the fixed RunsAsSystem middleware, so the owner scope shows every Admin's expired exports; tests dispatch it with nobody signed in to prove that"
  - "The file is deleted before the record, so a record whose file could not be deleted stays and the next daily run retries it; a missing file is not an error"
  - "Only the stored path of an expired record is deleted (pinned by the database CHECK to report-exports/<uuid>.<csv|xlsx>); no listing of the disk is used, so no file without a record can be reached"

patterns-established:
  - "Expiry is strict: a record whose expires_at equals the run instant is kept, one second later it goes"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "A daily job on one server deletes the file and then the record of every export past expires_at, for every owner, and keeps an unexpired export with its file"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#deletes the file and the record of an expired export and keeps a fresh one with its file"
        status: pass
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#prunes the expired exports of two different Admins in the same run"
        status: pass
      - kind: unit
        ref: "tests/Feature/Operations/ScheduleOnOneServerTest.php#schedules the export prune daily on one server"
        status: pass
    human_judgment: false
  - id: D2
    description: "Failed or pending records without a file and records whose file is already gone are deleted without an error; a second run deletes nothing and raises nothing"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#deletes an expired failed export without a path and an expired ready export whose file is already gone"
        status: pass
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#deletes nothing more and raises nothing on a second run"
        status: pass
    human_judgment: false
  - id: D3
    description: "The prune deletes only the stored path of an expired record and reads a large backlog in chunks"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#never touches a file outside the export area of the same disk"
        status: pass
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportPruneTest.php#deletes 250 expired records while reading them in chunks"
        status: pass
    human_judgment: false
  - id: D4
    description: "The new job is registered in the reviewed list of application jobs and satisfies the job contract"
    verification:
      - kind: unit
        ref: "tests/Arch/JobContractTest.php#has exactly the expected application jobs under app/"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 12: Report Export Prune Summary

**A daily one-server PruneExpiredReportExports job deletes the private-disk file and then the record of every export past expires_at for every Admin, keeping the 7-day promise of D-09.**

## Performance

- **Duration:** about 25 min (the full suite alone takes 4.5 min)
- **Completed:** 2026-10-14T21:13:34Z
- **Tasks:** 2
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments
- `PruneExpiredReportExports` extends `KokpitJob`, declares `#[Idempotent]`, reads expired records with `chunkById(100)`, deletes the stored path when one is set, then the record.
- Scheduled daily on one server as `kokpit-report-export-prune`, next to the idempotency-key purge; the schedule and job lists that pin the expected events and jobs were updated on purpose.
- Seven tests cover the tracer slice and every behavior of Task 2; the full suite passes (3594 passed, 3 skipped), Pint and PHPStan are clean.

## Task Commits

1. **Task 1: Tracer - the daily job removes an expired export's file and record and keeps a fresh one** - `50652a9` (feat)
2. **Task 2: Records without files, two owners, a second run and chunked deletion** - `26c70e4` (test; the tests passed against the Task 1 job, so no production change was needed)

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `app/Domain/Reports/Jobs/PruneExpiredReportExports.php` - the daily prune job
- `routes/console.php` - the `kokpit-report-export-prune` schedule entry
- `tests/Feature/Reports/ReportExportPruneTest.php` - seven prune tests on a faked disk
- `tests/Feature/Operations/ScheduleOnOneServerTest.php` - the known-events list gains the new name, plus a daily/one-server assertion
- `tests/Arch/JobContractTest.php` - `EXPECTED_APPLICATION_JOBS` gains `PruneExpiredReportExports::class`

## Decisions Made
- Runs as system via `RunsAsSystem`; a test dispatches it with nobody signed in and proves both Admins' exports are pruned.
- File first, record second, so a failed file delete leaves the record for the next run; a missing file is silently fine.
- Only the stored path is deleted, never a disk listing, so a file with no record (or outside `report-exports/`) is untouched.

## Deviations from Plan

None - plan executed exactly as written. The Task 2 tests all passed against the Task 1 implementation, so the "fix whatever they expose" step had nothing to change. The tracer gate re-ran the task verify (pass) before Task 2.

## Issues Encountered
None.

## Known Stubs
None.

## Threat Flags
None. The mitigations of T-08-41 (path pinned by the database CHECK, a file outside the area survives) and T-08-42 (daily system-run prune of every owner, one server) are both exercised by tests.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
Ready for 08-13. The exports record is now pruned on schedule, so the CSV build (08-16) and XLSX build (08-21) can rely on the retention without further work.

## Self-Check: PASSED
- FOUND: app/Domain/Reports/Jobs/PruneExpiredReportExports.php
- FOUND: tests/Feature/Reports/ReportExportPruneTest.php
- FOUND commits: 50652a9, 26c70e4
- All acceptance criteria re-run and passing; plan verification: full Pest suite, Pint and PHPStan clean.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
