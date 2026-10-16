---
phase: 09-documents-and-finance
plan: 21
subsystem: documents
tags: [documents, archiving, partner-scope, postgres, check-constraints, raw-sql-tests, partner-safe-columns]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01 documents table, Document model with the Partner scope, AttachDocuments, DocumentFixtures"
provides:
  - "Document::scopeWithoutArchivedOwners() and scopeOnlyArchivedOwners() for the Admin listing (D-21)"
  - "ArchivedOwnerDocumentsTest: archive and restore proof for project, client, task and comment files"
  - "DocumentsTableTest: raw-SQL refusals of the documents table and the media partial unique index"
  - "PartnerSafeColumnsTest pin and suspicious-name check for the documents table"
  - "two documents CHECK fixes found by the SQL proofs (visible file with no owner, whitespace-only name)"
affects: [09-13 hard-delete guard, 09-14 central listing filter, 09-16 storage totals label]

actuals:
  tokens: 6800
  tasks: 3
  commits: 3
plan_head_before: 2b3afeb898b329755c70a50573af4f1ad1c60605
plan_head_after: a42435fcc9bd179be667c3219cb2591f6ef78203
commits: 3

tech-stack:
  added: []
  patterns:
    - "Archived-owner read filter as plain EXISTS subqueries on deleted_at (clients, projects, tasks, task_comments joined to tasks), bypassing the Partner-closed Client model and the SoftDeletes scopes"
    - "A CHECK over a nullable column needs COALESCE: a CHECK passes when the expression is NULL"

key-files:
  created:
    - tests/Feature/Documents/ArchivedOwnerDocumentsTest.php
    - tests/Feature/Schema/DocumentsTableTest.php
  modified:
    - app/Domain/Documents/Models/Document.php
    - database/migrations/2026_10_16_000100_create_documents_table.php
    - tests/Isolation/PartnerSafeColumnsTest.php

key-decisions:
  - "A file is of an archived owner only for owner kinds client, project, task and task_comment; unattached files and transaction receipts never are (D-21 names clients, projects and tasks)"
  - "Both scopes are one shared private condition (constrainToArchivedOwner) so the two views are an exact partition, and withoutArchivedOwners() handles a NULL owner type explicitly instead of relying on NOT over NULL"
  - "The documents migration was fixed in place (not by a new migration): the table has not shipped, constraint names are unchanged"

patterns-established:
  - "Owner archive tests drive the application paths (ArchiveClient/RestoreClient, ArchiveTask/RestoreTask, project soft delete and restore) and assert row, media row and stored object before archive, after archive and after restore"

requirements-completed: [DO-01, DO-03]

coverage:
  - id: D1
    description: "Archiving a project hides its files from the Partner and from Document::withoutArchivedOwners(), onlyArchivedOwners() lists them, restore brings them back, and rows, media rows and stored objects stay untouched"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it hides the file of an archived project from the Partner and the default Admin query and brings it back on restore"
        status: pass
    human_judgment: false
  - id: D2
    description: "The same holds for an archived client (its own, project, task and comment files) and an archived task (its files and its comments' files, not the project file)"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it moves the files of an archived client, its projects, tasks and comments to the archived view and back on restore"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it hides the files of an archived task and of its comments but not the project file, and shows them again on restore"
        status: pass
    human_judgment: false
  - id: D3
    description: "Unattached files and transaction receipts are never files of an archived owner; the two views partition all documents exactly once; a sibling active project keeps its files visible"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it never counts an unattached file or a transaction receipt as a file of an archived owner"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it splits the documents into the active and the archived view with every document in exactly one of them"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/ArchivedOwnerDocumentsTest.php#it keeps the files of an active project of the same client while a sibling project is archived"
        status: pass
    human_judgment: false
  - id: D4
    description: "The database refuses an owner type without an id, a visible file whose owner is not a project, task or comment (client, transaction, none), a project without a client, a project of another client, a blank name, a malformed extension, a negative size and a second media row for one document"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/DocumentsTableTest.php (checks 23514, foreign keys 23503, media key 23505)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Partner-readable documents column list is pinned in migration order and no column name looks like money, a rate, a price, an estimate, billing, a cost or an internal note"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Isolation/PartnerSafeColumnsTest.php#it pins the Partner-safe column list of the documents table"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 21: Archived-Owner Files and Documents Schema Proofs Summary

**Admin scopes that separate the files of archived clients, projects and tasks from the rest (plain EXISTS subqueries on deleted_at), per-owner archive and restore proofs that nothing is deleted, raw-SQL refusals of the documents table (which exposed two CHECK holes, now fixed), and the Partner-safe column pin**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-16T12:00Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments

- `Document::scopeWithoutArchivedOwners()` and `scopeOnlyArchivedOwners()`: a file is of an archived owner when its owner kind is client, project, task or task comment and its client or project is archived, or it is a task file of an archived task, or a comment file on an archived task. The two views are an exact partition; unattached files and transaction receipts stay in the active view.
- Proof per owner kind through the application's own paths (project soft delete and restore, `ArchiveClient`/`RestoreClient`, `ArchiveTask`/`RestoreTask`): the Partner loses and regains the shared files, the default Admin query hides and returns them, and the documents row, the media row and the stored object exist before archive, after archive and after restore. The Partner scope of plan 09-01 needed no change.
- `DocumentsTableTest`: 15 raw-SQL cases (23514 checks, 23503 composite client FK, 23505 one media row per document, UUID v7 default), each with an accepting counterpart where useful.
- `PartnerSafeColumnsTest`: the documents column list pinned in migration order plus the suspicious-name check.

## Task Commits

1. **Task 1: Tracer - archiving a project hides its file, restoring brings it back, nothing is deleted** - `bd11858` (feat)
2. **Task 2: Clients, tasks and comments follow the same rule; unattached files never count** - `a06664a` (feat, TDD: client and task tests observed red first)
3. **Task 3: The database refuses an inconsistent file; the Partner-readable column list is pinned** - `a42435f` (test, with the migration fixes below)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

Tracer gate: `<verify>` is automated-only, so the tracer's tests, Pint and PHPStan were green end to end before expansion.

## Files Created/Modified

- `app/Domain/Documents/Models/Document.php` - the two archived-owner scopes and the shared private condition
- `tests/Feature/Documents/ArchivedOwnerDocumentsTest.php` - archive and restore proofs for every archivable owner
- `tests/Feature/Schema/DocumentsTableTest.php` - raw-SQL refusals of the documents table and the media partial unique index
- `tests/Isolation/PartnerSafeColumnsTest.php` - documents column pin and suspicious-name case
- `database/migrations/2026_10_16_000100_create_documents_table.php` - two CHECK fixes (below)

## Decisions Made

- Owner kinds outside client/project/task/task_comment never count as archived owners, so a future transaction receipt stays findable even when its client is archived.
- Archived-owner conditions go straight to `clients`, `projects`, `tasks` and `task_comments` as EXISTS subqueries; `QueryEscapeHatchTest` stays green.
- The migration is fixed in place because the table has not shipped; constraint names are unchanged.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `documents_visible_owner_check` accepted a visible file with no owner**
- **Found during:** Task 3 (raw-SQL proof "visible_to_client true with a null owner answers 23514")
- **Issue:** `CHECK (NOT visible_to_client OR owner_type IN (...))` evaluates to NULL when `owner_type` is NULL, and a CHECK passes on NULL, so an unattached file could be flagged visible by any writer other than the Action. This broke D-03 at the database level.
- **Fix:** `COALESCE(owner_type IN ('project', 'task', 'task_comment'), false)`; docblock explains why.
- **Files modified:** `database/migrations/2026_10_16_000100_create_documents_table.php`
- **Verification:** `DocumentsTableTest` "refuses a visible file whose owner is a client, a transaction or nothing" and the accepting cases pass; full suite green.
- **Committed in:** a42435f

**2. [Rule 1 - Bug] `documents_name_check` accepted a whitespace-only name made of tabs or newlines**
- **Found during:** Task 3 (raw-SQL proof of a blank name)
- **Issue:** `length(btrim(name)) > 0` trims only spaces, so a tab-only name was stored.
- **Fix:** `CHECK (name ~ '[^[:space:]]')`.
- **Files modified:** `database/migrations/2026_10_16_000100_create_documents_table.php`
- **Verification:** `DocumentsTableTest` "refuses a blank name" (empty and space/tab) passes.
- **Committed in:** a42435f

**3. [Rule 3 - Blocking, command only] The plan's verify command lists both a file and its parent directory**
- `pest tests/Feature/Schema/DocumentsTableTest.php ... tests/Feature/Schema` fails with `TestCaseAlreadyInUse`. Ran the directory plus the isolation file instead (291 passed), and the full suite separately. No code change.

---

**Total deviations:** 3 (2 bug fixes in the migration, 1 verify-command quirk)
**Impact on plan:** both fixes are the "migration fixes the proofs expose" the plan asked for. A local DDEV database migrated before this commit still carries the two old constraints; tests rebuild the schema, a `migrate:fresh` brings a dev database in line.

## Issues Encountered

- A first attempt of the name CHECK used a PHP single-quoted string containing quotes (parse error); switched to a double-quoted string.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - no new surface; T-09-75, T-09-76 and T-09-77 of the plan are covered by the tests above.

## Next Phase Readiness

- Plan 09-14 can use `withoutArchivedOwners()` for the default central listing and `onlyArchivedOwners()` for the "archived owners" filter; plan 09-16 can use `onlyArchivedOwners()` for the totals label.
- Plan 09-13 introduces the `transaction` owner kind; the receipts-never-archived rule is already proven with a hand-written `transaction` row.

## Self-Check: PASSED

- Created files exist: `tests/Feature/Documents/ArchivedOwnerDocumentsTest.php`, `tests/Feature/Schema/DocumentsTableTest.php`.
- Commits `bd11858`, `a06664a`, `a42435f` are ancestors of HEAD; `git rev-list --count 2b3afeb..HEAD` is 3.
- Full suite `ddev exec vendor/bin/pest`: 4057 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
