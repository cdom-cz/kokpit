---
phase: 09-documents-and-finance
plan: 26
subsystem: documents
tags: [documents, gap-closure, wr-02, archive, prune, after-commit, d-08]

requires:
  - phase: 09-documents-and-finance
    provides: "09-18 PruneExpiredDocumentArchives; 09-19 BuildDocumentArchive; 09-27 after-commit removal pattern"
provides:
  - "PruneExpiredDocumentArchives deletes the id-derived storagePath() of every expired record, whatever its status"
  - "BuildDocumentArchive removes the ZIP of a record that is gone or failed (give-up, vanished, failed meanwhile) after the deciding transaction commits, never the ZIP of a ready record"
  - "A failing removal is reported without the path and never stops the failure bell"
affects: [09-28 review disposition and consistency check, phase 9 re-verification]

estimate_tokens: 37000
actuals:
  tokens: 5000
  tasks: 2
  commits: 2
plan_head_before: 4415140d33fb20c48a580fe70997337e3689f741
plan_head_after: f55476ad83f7f437101a9e21684f525ac86297e7
commits: 2

tech-stack:
  added: []
  patterns:
    - "Object path derived from the record id only (storagePath()), so cleanup never depends on a recorded column"
    - "Database first, object after commit: DB::afterCommit inside the deciding transaction"

key-files:
  created:
    - tests/Feature/Documents/DocumentArchiveCleanupTest.php
  modified:
    - app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php
    - app/Domain/Documents/Jobs/BuildDocumentArchive.php
    - tests/Feature/Documents/DocumentArchivePruneTest.php

key-decisions:
  - "The prune deletes DocumentArchive::storagePath() for every expired record; the path column no longer decides whether an object is deleted (WR-02)."
  - "The build job removes the ZIP of a gone or failed record through DB::afterCommit registered in the deciding transaction; a ready record keeps its object because the path is shared by every run of the same id."
  - "giveUp() ignores an id that is not a UUID before querying: it cannot be a record, never becomes part of a path, and no longer reaches the uuid column."
  - "A removal that throws is reported as a RuntimeException carrying only the exception class, and swallowed; the prune removes the object later."

requirements-completed: [DO-02]
status: complete
---

# Phase 9 Plan 26: WR-02 archive ZIP cleanup Summary

**No ZIP of client files outlives its archive record: the daily prune deletes the id-derived object of every expired record whatever its status, and the build job removes the ZIP of a record it gave up on or that vanished or failed mid-build, after the commit and never for a ready record.**

## Tasks and commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Tracer: the daily prune removes the ZIP an expired failed or pending archive left behind | 68ee519 | app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php, tests/Feature/Documents/DocumentArchivePruneTest.php |
| 2 | The build job removes the ZIP of a record it gave up on or that vanished or failed, and keeps a ready one | f55476a | app/Domain/Documents/Jobs/BuildDocumentArchive.php, tests/Feature/Documents/DocumentArchiveCleanupTest.php |

## RED evidence

- Task 1: two of the three new `(WR-02)` prune cases (failed and pending record with a ZIP at `storagePath()`) failed against the old prune before the change; the fresh-record case passed as a guard.
- Task 2: six of the eight new `(WR-02)` cases failed against the old job (give-up, vanished record on give-up, non-UUID id, record deleted during the build, record failed during the build, failing removal); the late-failure-on-ready and finished-meanwhile cases passed as guards for behavior that must not change.

## Verification

- `tests/Feature/Documents`: 295 passed (includes the unchanged `DocumentArchiveTest.php` and the prune cases).
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean before both commits; lefthook (sensitive-content, gitleaks) passed on both.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Non-UUID queue id crashed the give-up lookup**
- **Found during:** Task 2 (the planned "never turns a non-UUID into a path" case)
- **Issue:** `giveUp()` queried `document_archives.id` with the raw payload id; a value that is no UUID made PostgreSQL raise `invalid input syntax for type uuid`, so `failed()` threw instead of finishing.
- **Fix:** `giveUp()` returns at once for an id that fails `Str::isUuid()`; the record lookup and the path then only ever see a UUID.
- **Files modified:** app/Domain/Documents/Jobs/BuildDocumentArchive.php
- **Commit:** f55476a

Plan 09-26 otherwise executed as written.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-09-82 (ZIP left after failed or vanished build: prune by `storagePath()` plus after-commit removal, tested for failed, pending, given-up, vanished and failed-meanwhile), T-09-83 (cleanup destroying a concurrent run's finished ZIP: ready record keeps its object, tested with bytes check), T-09-84 (path from the record id only, UUID-validated for a missing record; the existing "outside the archive area" prune test stays green), T-09-85 (report carries the exception class only, asserted free of `document-archives/` and the archive id) are covered.

## Notes for plan 09-28

- Commit hashes for the disposition: 68ee519 (prune, tracer), f55476a (build job).
- WR-02 is closed in code; nothing in this plan touched `DocumentArchiveTest.php`.

## Self-Check: PASSED

- FOUND: app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php, app/Domain/Documents/Jobs/BuildDocumentArchive.php, tests/Feature/Documents/DocumentArchiveCleanupTest.php
- FOUND: 68ee519, f55476a (ancestors of HEAD)
