---
phase: 09-documents-and-finance
plan: 07
subsystem: documents
tags: [documents, filament, delete, s3, activity-log, rename, visibility, bulk-actions]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-05 ManagesDocuments tab, DocumentActions and DocumentColumns; 09-21 archived-owner scopes on Document; 09-02 DocumentFileName and AttachDocuments; 09-01 DocumentOwnerType and the owner rules"
provides:
  - "DeleteDocument: permanent deletion of the row, the media row and the stored object, as system in one transaction, with a verified object removal"
  - "RenameDocument and SetDocumentVisibility Actions (name cleaned like an upload name, share refused for client, unattached and internal-comment files)"
  - "Allowlisted activity on Document (name, visible_to_client, owner_type, size_bytes; never the description)"
  - "rename, toggleVisibility, delete and bulkDelete in DocumentActions, wired into every Soubory tab through ManagesDocuments"
affects: [09-08 Partner tabs, 09-10 Partner upload and delete, 09-13 transaction receipts and DeleteTransaction, 09-14 central listing, 09-20 consistency check and unused copy]

actuals:
  tokens: 10900
  tasks: 3
  commits: 3
plan_head_before: cd072a2e2e666d1066b2d7ae7d0eaf9e7ad9994d
plan_head_after: 6ab39cb9d8e24c6a44ecc54d000cf81c20ae6f7c
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Delete order: authorize as the actor, then as system in a transaction: lockForUpdate re-read, Eloquent delete, then verify every stored object is gone and throw (rolling back) when one still stands"
    - "Bulk actions count the selection from the client's selection state and never call getSelectedTableRecords(false), because Filament caches whichever form (keys or records) is requested first"
    - "A per-record table action visibility asks the Action (SetDocumentVisibility::isShareable) and uses an already loaded owner, so the client tab costs no query per row"

key-files:
  created:
    - app/Domain/Documents/Actions/DeleteDocument.php
    - app/Domain/Documents/Actions/RenameDocument.php
    - app/Domain/Documents/Actions/SetDocumentVisibility.php
    - tests/Feature/Documents/DeleteDocumentTest.php
  modified:
    - app/Domain/Documents/Models/Document.php
    - app/Filament/Concerns/ManagesDocuments.php
    - app/Filament/Support/DocumentActions.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Feature/Documents/DocumentsRelationManagerTest.php
    - lang/cs/kokpit.php
    - lang/cs/documents.php

key-decisions:
  - "The medialibrary file remover swallows and reports a failing disk, so DeleteDocument verifies the object is gone after the Eloquent delete, makes one strict delete where one is left, and throws when it still stands; the transaction then rolls the row, the media row and the activity record back"
  - "The delete table action reports a failure by exception class only (no file name) and tells the Admin the file was not deleted; an AuthorizationException is never swallowed"
  - "Bulk delete counts a selected file that no longer exists as failed, using the selection state (select-all minus deselected when tracking deselection) against the records actually fetched"
  - "The visibility switch is decided per file: project, task and non-internal comment files keep it even when listed on the client tab; client-owned, unattached and internal-comment files never show it"
  - "A rename changes only the display name; the stored object keeps <document uuid>.<extension>, and the description is trimmed and capped at 2000 like an upload description"

patterns-established:
  - "Rules live in the domain Action (shareable owner, name cleaning, deletion order); the Filament actions only collect input and report results"

requirements-completed: [DO-03]

coverage:
  - id: D1
    description: "The Admin deletes a file from a Soubory tab after a confirmation dialog that names the file and says the deletion is permanent; the documents row, the media row and the stored object are gone"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it deletes the row, the media and the stored object and records the deletion without the description"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it deletes through the table action only after the confirmation dialog"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it deletes the files of an archived task for the Admin"
        status: pass
    human_judgment: false
  - id: D2
    description: "The stored object is removed on the real S3-compatible bucket (RustFS in DDEV), not only on the faked disk"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it removes the object from the real bucket (group s3)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A deletion is complete or not done: when the disk throws, or answers false and leaves the object standing, the row, the media row and the object stay and no deleted activity row is written"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it keeps the row, the media and the object when the disk throws on delete"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it keeps the row and the media when the disk answers false and leaves the object standing"
        status: pass
      - kind: integration
        ref: "mutation run: removing the object verification made four of the failure tests fail"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every deletion writes one activity row holding the name, size, owner type and flag and never the description"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php#it deletes the row, the media and the stored object and records the deletion without the description"
        status: pass
      - kind: integration
        ref: "tests/Arch/ActivityAllowlistTest.php and tests/Feature/Operations/ActivityAllowlistColumnsTest.php (Document is a listed logging model with real columns)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Bulk delete asks once, names the number of files, keeps going when a file fails or vanished, and reports how many were deleted and how many failed"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DeleteDocumentTest.php (three bulk cases: all deleted, one vanished, one object stuck)"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Admin renames a file (name cleaned like an upload name, blank refused, description clearable) with one activity row for a name change and none for a description-only change"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php (rename cases: hostile name, blank name, clear description, activity row)"
        status: pass
    human_judgment: false
  - id: D7
    description: "The Admin shows or hides a project, task or non-internal comment file for the client; the switch is not offered and is refused for client-owned, unattached and internal-comment files; a Partner is refused by both Actions"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php (hide and show, toggle offered only where shareable, refusal of non-shareable files, Partner refusal)"
        status: pass
      - kind: integration
        ref: "mutation run: skipping the shareable check made the refusal test fail"
        status: pass
    human_judgment: false
  - id: D8
    description: "In a real browser the confirmation dialogs read well and a confirmed deletion visibly removes the row and the file"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests assert the dialog texts and the effect through Livewire; no browser test exists"
        status: pass
    human_judgment: true
    rationale: "Wording and the feel of an irreversible action in the real panel are not asserted by any test; only a person looking at the modal can judge them."

# Metrics
duration: 35min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 07: File Management Summary

**Permanent file deletion with a verified object removal (row, media, S3 object gone together or not at all), plus rename and a per-file client visibility switch on every Soubory tab, with an allowlisted activity trail**

## Performance

- **Duration:** about 35 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T13:20Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 11 (4 created incl. the delete test file, 7 modified)

## Accomplishments

- `DeleteDocument` authorizes `delete` as the actor, then inside `runAsSystem()` and `DB::transaction()` re-reads the row with `lockForUpdate()`, notes where its objects live, deletes through Eloquent (the medialibrary hook removes the media row and its observer the object; the activity row of the deleted event is written) and verifies that every object is gone. Proven on the faked disk and on the real RustFS bucket (`group('s3')`).
- `Document` logs `name`, `visible_to_client`, `owner_type`, `size_bytes` through `LogsAllowlistedActivity`; the pinned logging-model list and the activity labels (`Soubor`, four attribute labels) are updated. The description never reaches the log.
- `DocumentActions` gains `delete()` (confirmation naming the file, permanent wording), `bulkDelete()` (one confirmation with a pluralised count, per-file try/catch, result notification success/warning/danger by outcome), `rename()` (name and description modal, domain errors re-keyed under the modal) and `toggleVisibility()` (label by current state, offered only for a shareable file). All are wired into `ManagesDocuments`, so the project, task and client tabs, and later the transaction tab, share them.
- `RenameDocument` and `SetDocumentVisibility` own the rules: the name is cleaned by `DocumentFileName::displayName()` and a blank or emptied-out name is refused under `name`; showing a client, unattached or internal-comment file is refused under `visible_to_client` with `documents.errors.not_shareable`; both authorize `update` (a Partner is refused).

## Task Commits

1. **Task 1: Tracer - delete after confirmation, row, media and object gone with an activity record** - `b7ab373` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests (including the s3 group), the arch test, Pint and PHPStan were re-run end to end (green) before expansion.
2. **Task 2: A failed object removal keeps the row, and bulk delete reports every outcome** - `1535124` (feat)
3. **Task 3: Rename, describe and show or hide a file for the client** - `6ab39cb` (feat)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The package file remover swallows a failing disk, so "the s3 disk throws, so the row rolls back" does not hold**
- **Found during:** Task 1 (reading `DefaultFileRemover` before writing the Action; the research Pitfall 4 and the plan's recorded delete order assume the exception propagates)
- **Issue:** `DefaultFileRemover::removeFromMediaDirectory()` wraps the delete in `try { } catch (Exception) { report(); }`. A disk that throws (the `s3` disk has `'throw' => true`) or answers false would therefore leave the object behind while the media row and the document row were deleted and committed: the orphan the plan forbids ("A deleted document must never leave its stored object behind").
- **Fix:** After the Eloquent delete, `DeleteDocument` checks `exists()` for every object it noted before the media rows went; where one is left it makes one strict `delete()` (the error is not swallowed) and checks again; if the object still stands it throws a `RuntimeException` without the path, which rolls the transaction back (row, media row, activity record). A mutation run without this check made four failure tests fail.
- **Files modified:** `app/Domain/Documents/Actions/DeleteDocument.php`, `tests/Feature/Documents/DeleteDocumentTest.php`
- **Verification:** `ddev exec vendor/bin/pest tests/Feature/Documents/DeleteDocumentTest.php` (incl. `--group=s3`)
- **Commit:** b7ab373 (check), 1535124 (failure tests)

**2. [Rule 2 - Missing critical functionality] Failure handling and extra copy for the table actions**
- **Found during:** Task 1 and Task 3
- **Issue:** The plan's copy list has no text for a failed single deletion (the Action rethrows when the object cannot be removed, which would otherwise reach the Admin as a server error), for a blank name, or for the bulk dialog heading and the name field label.
- **Fix:** Added `notifications.delete_failed`, `errors.name_required`, `actions.bulk_delete_heading` and `fields.name`; the single delete action catches everything except `AuthorizationException`, reports `A document could not be deleted (<exception class>)` (no file name) and shows the failure notification.
- **Files modified:** `app/Filament/Support/DocumentActions.php`, `lang/cs/documents.php`
- **Commit:** b7ab373, 1535124, 6ab39cb

**3. [Rule 1 - Bug] Filament caches the first form of the selected records**
- **Found during:** Task 2 design (reading `HasBulkActions::getSelectedTableRecords`)
- **Issue:** Calling `getSelectedTableRecords(false)` (keys) in the confirmation text would cache the keys and the action would later receive keys instead of records. A count that only used the fetched records would also hide a file that vanished after it was selected.
- **Fix:** `DocumentActions::selectedCount()` reads the client's selection state (`selectedTableRecords`, or `getAllSelectableTableRecordsCount()` minus the deselected keys when "select all" is active); the action receives the fetched records and counts the difference as failed.
- **Files modified:** `app/Filament/Support/DocumentActions.php`
- **Commit:** 1535124

**Total deviations:** 3 auto-fixed (2 bug, 1 missing critical). **Impact:** deviation 1 is what makes the plan's own prohibition ("never leave its stored object behind") hold; the plan's behavior is otherwise unchanged.

### Plan interpretation (not a deviation)

- The plan says the toggle is not offered "on the client tab". It is not offered for client-owned files (all uploads made on that tab) and for internal-comment and unattached files; a project, task or non-internal comment file that the client tab also lists keeps the switch, since hiding it there is the same decision as hiding it on its own tab.
- The first run of the Task 2 failure-path tests was already green, because the verification built in Task 1 covers them; the mutation run (removing that verification) is what proves the tests bite.
- `Document` has no record page, so its history is read in the activity overview (`ActivityResource`), not through an `ActivityHistoryRelationManager` subclass (already recorded in the plan as a deviation from Add-a-model checklist item 8).

## Authentication Gates

None.

## Issues Encountered

- The full-suite command exceeds the 120 s foreground limit; it was run in the background and finished green (4196 passed, 3 skipped, 0 failed; the 3 skips are pre-existing).
- A first mutation attempt using `sed -i ''` did not apply; it was redone with a script and verified with `grep` before running the tests, and the original file was restored from a backup (`git diff` clean).

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-22 (deletion as system after the policy, faked-disk and real-bucket proofs, plus the object verification), T-09-23 (allowlisted activity row on the deleted event), T-09-24 (`SetDocumentVisibility` refuses, the action is hidden, the CHECK backstops) and T-09-25 (description not in `LoggedAttributes`, asserted) of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4196 passed, 3 skipped (pre-existing), 0 failed; `--group=s3` case on `DeleteDocumentTest` passes against RustFS
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- All task acceptance criteria re-run: pass

## Next Phase Readiness

Ready for the next plan of wave 4. Plan 09-10 (Partner upload and delete) and 09-13 (`DeleteTransaction`) call `DeleteDocument` as the only paths that remove objects; 09-20 adds the consistency check for the rare orphan left by a commit that fails after the object was removed. One open item: a human look at the confirmation dialogs in the real panel (coverage D8).

## Self-Check: PASSED

- Files exist: `DeleteDocument.php`, `RenameDocument.php`, `SetDocumentVisibility.php`, `DeleteDocumentTest.php` (found)
- Commits `b7ab373`, `1535124`, `6ab39cb` are ancestors of HEAD
- `commits: 3` measured from the plan ledger (`cd072a2..6ab39cb`)
