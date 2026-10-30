---
phase: 09-documents-and-finance
plan: 27
subsystem: documents
tags: [documents, finance, gap-closure, wr-01, after-commit, queue, d-23]

requires:
  - phase: 09-documents-and-finance
    provides: "09-07 DeleteDocument; 09-13 DeleteTransaction and transaction receipts; Phase 3 KokpitJob contract"
provides:
  - "DeleteDocument: rows and activity record in one transaction, stored objects removed by DB::afterCommit after the outermost commit"
  - "RemoveDocumentObjects: queued, guarded retry (3 tries, backoff, then the failed-job alert to the Admin) for objects the disk refused after the commit"
  - "DeleteTransaction: transaction and receipts in one transaction; a rollback keeps every receipt row, media row and object"
  - "Delete failure copy that no longer blames a receipt file"
affects: [09-28 review disposition and consistency check, phase 9 re-verification]

estimate_tokens: 60000
actuals:
  tokens: 12000
  tasks: 3
  commits: 3
plan_head_before: 6916a877cf4e9c4244f665832be0216d5c4963b9
plan_head_after: da86cf676c89f6d5888f3d7f30e162140db98d28
commits: 3

tech-stack:
  added: []
  patterns:
    - "Irreversible side effects last: rows in the transaction, objects through DB::afterCommit, a queued guarded job for what the disk refuses"
    - "A queue payload that deletes by path is never trusted: disk, path shape and media-row absence are checked at run time"

key-files:
  created:
    - app/Domain/Documents/Jobs/RemoveDocumentObjects.php
    - tests/Feature/Documents/RemoveDocumentObjectsTest.php
  modified:
    - app/Domain/Documents/Actions/DeleteDocument.php
    - app/Domain/Finance/Actions/DeleteTransaction.php
    - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
    - lang/cs/finance.php
    - tests/Feature/Documents/DeleteDocumentTest.php
    - tests/Feature/Finance/TransactionReceiptsTest.php
    - tests/Arch/JobContractTest.php

key-decisions:
  - "D-23 (owner decision, amends D-09): the object is removed after the commit; an object the disk refuses is retried 3 times by RemoveDocumentObjects, then the failed-job alert reaches the Admin. Between the commit and a successful removal the object may stand on the private bucket, reachable through no route of the app."
  - "Media rows are deleted with a query-builder delete (no model event, so the medialibrary observer cannot remove the file inside the transaction) and the document with deletePreservingMedia(); the allowlisted deleted activity row is still written in the transaction."
  - "The after-commit removal queries no model and never throws: it works on the Media instances read inside the transaction and on the disk only."
  - "RemoveDocumentObjects types its payload as array<array-key, mixed> and checks every entry at run time, because the payload comes back from the queue."
  - "DeleteTransaction needed no code change: each receipt still goes through DeleteDocument inside the outer transaction, and Laravel attaches the nested after-commit callback to the outer transaction."

requirements-completed: [DO-03, FI-01, DO-01]
status: complete
---

# Phase 9 Plan 27: WR-01 object removal after the commit Summary

**Deleting a file or a transaction now commits the rows first and removes the stored objects only after the outermost commit, so a rollback never costs an object; an object the disk refuses is retried by a guarded queued job and ends in the failed-job alert.**

## Tasks and commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Tracer: object removed only after the commit, rollback keeps row, media row and object | cc293c3 | app/Domain/Documents/Actions/DeleteDocument.php, tests/Feature/Documents/DeleteDocumentTest.php |
| 2 | Guarded queued job removes objects the disk refused | 7e636be | app/Domain/Documents/Jobs/RemoveDocumentObjects.php, app/Domain/Documents/Actions/DeleteDocument.php, tests/Arch/JobContractTest.php, tests/Feature/Documents/RemoveDocumentObjectsTest.php, tests/Feature/Documents/DeleteDocumentTest.php |
| 3 | Transaction delete keeps every receipt on rollback; copy corrected | da86cf6 | app/Domain/Finance/Actions/DeleteTransaction.php, app/Filament/Resources/TransactionResource/Pages/EditTransaction.php, lang/cs/finance.php, tests/Feature/Finance/TransactionReceiptsTest.php |

## RED evidence

The new tests were written together with the code, then proven against the old behaviour by temporarily restoring the pre-plan `DeleteDocument` (the file was restored from the saved new version afterwards; no git stash was used):

- Task 1: all 7 `(WR-01)` cases of `DeleteDocumentTest.php` failed against the old Action (commit-only removal, enclosing rollback, database refusal, refused object after commit with a throwing and a false-answering disk, the Admin action, the bulk case).
- Task 3: all 4 `(WR-01)` cases of `TransactionReceiptsTest.php` failed against the old Action, including the rollback case where the first receipt's object was gone after the transaction row refused to delete.

## Verification

- `tests/Feature/Documents tests/Feature/Finance tests/Unit/Documents tests/Isolation/DocumentFinanceLeakTest.php tests/Isolation/DocumentVisibilityTest.php tests/Arch/JobContractTest.php`: 517 passed.
- `DeleteDocumentTest.php --group=s3` ran against the real bucket (RustFS in DDEV was reachable) and passed: the object leaves the real bucket after the commit.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean before every commit; lefthook (sensitive-content, gitleaks) passed on all three commits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan list type of the retained media**
- **Found during:** Task 1
- **Issue:** `Collection::all()` is `array<int, Media>`, not a list, so `removeObjects(list<Media>)` failed PHPStan level.
- **Fix:** passed `array_values($media->all())`.
- **Files modified:** app/Domain/Documents/Actions/DeleteDocument.php
- **Commit:** cc293c3

**2. [Test scope] RED evidence order**
- The plan asked for RED first; the code and tests were written together and RED was proven afterwards by running the new tests against the pre-plan Action (see above). The result is the same evidence, reached in a different order.

Plan 09-27 otherwise executed as written. `DeleteTransaction` needed no code change (only the docblock), as the plan allowed.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-09-86 (rollback after objects were destroyed), T-09-87 (object surviving a refused removal: queued retry plus the failed-job alert), T-09-88 (crafted payload: disk, path shape and media-row guards, tested for archive, traversal, foreign file name, upper-case uuid, over-long extension, empty path, foreign disk and malformed entries), T-09-89 (no file name or path in reports, asserted in the tests) and T-09-90 (the policy is still checked as the actor first; `PartnerUploadTest` passes) are covered.

## Notes for plan 09-28

- Commit hashes for the disposition: cc293c3, 7e636be, da86cf6.
- D-23 is named in the `DeleteDocument` and `RemoveDocumentObjects` docblocks and here; plan 09-28 Task 3 records it in 09-CONTEXT.md and 09-REVIEW-DISPOSITION.md.
- `DocumentStorageConsistencyTest.php`, `CONTRIBUTING.md` and the stale comment in `DocumentActions.php` are left to plan 09-28, as planned.

## Self-Check: PASSED

- FOUND: app/Domain/Documents/Jobs/RemoveDocumentObjects.php, tests/Feature/Documents/RemoveDocumentObjectsTest.php, app/Domain/Documents/Actions/DeleteDocument.php, app/Domain/Finance/Actions/DeleteTransaction.php
- FOUND: cc293c3, 7e636be, da86cf6 (ancestors of HEAD)
