---
phase: 08-exchange-rates-and-reports
plan: 07
subsystem: reports
tags: [report-exports, owned-by-user, check-constraints, private-storage, expiry, czech-copy, config]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-01 registration files (MorphMap, AccessServiceProvider, model and canary registries) and the raw-SQL proof style; 08-04 config/kokpit.php and lang/cs/kokpit.php as the shared contract files"
  - phase: 02-foundation-platform
    provides: "OwnedByUser / OwnerScope / DeniesPartners, AdminOnlyPolicy, KokpitModel with uuidv7 keys, TimerClock"
provides:
  - "report_exports table with six named CHECKs (format, status, filters object, ready, server-generated path, row count) and an (user_id, created_at) index"
  - "ReportExport model: final, PartnerIsolated, OwnedByUser, expiry set on create, ttlDays(), storagePath(), downloadFileName(), isDownloadable(), markReady(), markFailed()"
  - "ReportExportStatus (info / success / danger, failed icon) and ReportExportFormat enums with Czech labels in lang/cs/enums.php"
  - "Registrations: morph alias report_export, AdminOnlyPolicy, ModelDeclarationTest, CanaryRegistry fixture and CanaryRegistryTest entry"
  - "config/kokpit.php exports block (ttl_days 7, CSV semicolon delimiter and decimal comma) and the whole kokpit.exports.* Czech copy (list, toast, bell, summary, file column / sheet / note texts)"
affects: [08-11 exports list and checked download, 08-12 7-day prune, 08-16 CSV build, 08-21 XLSX build, 08-17 report page notes]

plan_head_before: 43d24c1889ad284014edc8ee462d9e50297c42a6
plan_head_after: 34d06d6918c95ce7a0c2c99d38ef645742038410

estimate:
  tokens: 80000
  tasks: 3

actuals:
  tokens: 10500
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "An owner-scoped record (OwnedByUser) with a lifecycle written only by two methods that persist through forceFill"
    - "A storage path pinned by a database CHECK to the server-generated shape, with the same shape produced by one model method"
    - "A download name built from a validated stored period; anything that is not a real YYYY-MM-DD day falls back to a plain name"
    - "A whole area's reviewed contract (config and Czech copy) lands in its first plan so later plans only read it"

key-files:
  created:
    - database/migrations/2026_10_15_000200_create_report_exports_table.php
    - app/Domain/Reports/Models/ReportExport.php
    - app/Domain/Reports/Enums/ReportExportStatus.php
    - app/Domain/Reports/Enums/ReportExportFormat.php
    - tests/Feature/Reports/ReportExportRecordTest.php
    - tests/Feature/Schema/ReportExportsTableTest.php
  modified:
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - config/kokpit.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php

key-decisions:
  - "expires_at is set to creation plus the retention by a creating hook and reset to completion plus the retention by markReady(), so the prune of plan 08-12 also removes stale pending and failed records"
  - "ttlDays() reads kokpit.exports.ttl_days with a built-in fallback of 7 and never returns less than 1, so a zero or negative setting cannot make a finished export expire before it can be downloaded"
  - "isDownloadable() treats the expiry instant itself as expired (expires_at must be strictly after now)"
  - "downloadFileName() accepts only two real YYYY-MM-DD days from the stored filters; any other stored text gives report-casu.<ext>, so stored filter text can never reach a name"
  - "markReady() does not require the path to equal storagePath(): the database pins the shape, and the build plans pass storagePath()"
  - "The missing-rate note keeps the UI-SPEC single form with :count (read with __), not a Czech plural set, so the build plans can read it like the report page note"

patterns-established:
  - "A file column order is the key order of one translation array (kokpit.exports.file.columns), pinned by a test"
  - "jsonb does not keep key order: tests compare stored filters as a set of pairs"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "An export request is a report_exports record owned by the Admin who made it: the owner reads it, a second Admin and every Partner read nothing"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportRecordTest.php#visibility"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "The database refuses an unknown format or status, a ready row without a path or completion time, non-object filters, any path outside report-exports/<uuid>.<csv|xlsx> and a negative row count"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/ReportExportsTableTest.php#checks (23514)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Lifecycle and expiry: 7 days from completion for a finished export, 7 days from creation for pending and failed, download name from the stored period only, isDownloadable only for a ready unexpired export with a path"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportRecordTest.php#lifecycle"
        status: pass
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportRecordTest.php#naming"
        status: pass
    human_judgment: false
  - id: D4
    description: "The exports retention, the CSV delimiter and decimal sign, and the whole Czech copy of the exports area exist under config kokpit.exports and kokpit.exports.*"
    requirement: RE-03
    verification:
      - kind: unit
        ref: "tests/Feature/Reports/ReportExportRecordTest.php#the exports contract"
        status: pass
    human_judgment: false

duration: 40min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 07: Report export record Summary

**Owner-scoped report_exports record with a pending/ready/failed lifecycle, a 7-day expiry rule, database-pinned server-generated storage paths and the exports retention, CSV settings and Czech copy as one reviewed contract**

## Performance

- **Duration:** about 40 min (full suite alone 4.6 min)
- **Completed:** 2026-10-14T20:17:14Z
- **Tasks:** 3
- **Files modified:** 14 (6 created, 8 modified)

## Accomplishments

- `report_exports` table: uuidv7 key, owner FK to `users` (NO ACTION), format, status, summary, jsonb filters, nullable path, row count, completion and expiry timestamps, and six named CHECKs that refuse every unknown or unsafe value whatever code writes the row.
- `ReportExport` model under every repository convention: `OwnedByUser` (a second Admin reads nothing, a Partner nothing at all), `AdminOnlyPolicy`, morph alias `report_export`, `ModelDeclarationTest`, a canary fixture and the isolation registry entry.
- Lifecycle and naming: `markReady()` / `markFailed()` as the only writes, expiry created at creation plus the retention and moved to completion plus the retention, `isDownloadable()`, `storagePath()` and `downloadFileName()` built from the id and the validated stored period only.
- `config('kokpit.exports')` (ttl_days 7, csv delimiter `;`, decimal separator `,`) documented inline, and the whole `kokpit.exports.*` Czech copy plus enum labels, so plans 08-11, 08-12, 08-16 and 08-21 only read them.

## Task Commits

1. **Task 1: Tracer - record stored for its owner under every repository convention** - `eacacb3` (feat)
2. **Task 2: Lifecycle, expiry rule, server-generated names and database refusals** - `2b7ea86` (feat)
3. **Task 3: Exports retention, CSV settings and Czech copy as one contract** - `34d06d6` (feat)

**Plan metadata:** the docs commit that carries this SUMMARY.

_The tests were written before the implementation in each task; the RED and GREEN steps of one task are in one commit because the plan prescribes one commit per task._

## Files Created/Modified

- `database/migrations/2026_10_15_000200_create_report_exports_table.php` - table, indexes and six named CHECKs
- `app/Domain/Reports/Models/ReportExport.php` - owner-scoped record, lifecycle, expiry, names
- `app/Domain/Reports/Enums/ReportExportStatus.php`, `ReportExportFormat.php` - labelled enums (status colour and failed icon)
- `lang/cs/enums.php` - `report_export_status.*`, `report_export_format.*`
- `lang/cs/kokpit.php` - `exports.*` Czech copy
- `config/kokpit.php` - `exports` block
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - alias and policy
- `tests/Arch/ModelDeclarationTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php` - convention registrations
- `tests/Feature/Reports/ReportExportRecordTest.php`, `tests/Feature/Schema/ReportExportsTableTest.php` - record, lifecycle, contract and raw-SQL proofs

## Decisions Made

See `key-decisions` above. The one deliberate reading of the plan: `markReady()` takes the path as an argument as specified and leaves the shape to the database CHECK instead of also comparing it to `storagePath()`, so a later plan is never blocked by an equality rule the plan did not state.

## Deviations from Plan

None - plan executed exactly as written.

The plan placed `config('kokpit.exports.ttl_days')` in Task 3 while Task 1 already needs the retention for `expires_at`; `ttlDays()` therefore carries a fallback constant of 7, which keeps the tasks independent and does not change the final behaviour.

## Issues Encountered

- A first run of Pint on directory paths also reformatted unrelated `lang/cs/*.php` files (the project Pint run excludes them); those six files were restored with `git checkout -- <file>` before the Task 3 commit, and only the plan's additions were committed.
- `WorkReportPdfTest` shows two skipped tests in the container (`pdftotext` is not installed there); unrelated to this plan and already present before it.

## Known Stubs

None.

## Threat Flags

None - no network endpoint, auth path or file access was added; the table, model and copy sit inside the plan's threat model (T-08-24, T-08-25, T-08-26).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 08-11 can build the "Exporty" list and the checked download on `ReportExport`, `isDownloadable()` and `downloadFileName()`; plan 08-12 prunes on `expires_at`; plans 08-16 and 08-21 read `kokpit.exports.file.*` and `kokpit.exports.csv` and finish with `markReady($export->storagePath(), ...)` or `markFailed()`.
- The new migration needs `ddev exec php artisan migrate` on a development database (the test suite migrates by itself).

## Self-Check: PASSED

- Created files exist: the migration, the model, both enums and both test files were found on disk.
- Commits `eacacb3`, `2b7ea86` and `34d06d6` are ancestors of HEAD.
- All acceptance criteria of the three tasks pass; the full suite passes (3370 passed, 3 skipped), Pint and PHPStan are clean, `scripts/check-sensitive.sh` is clean on every commit.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
