---
phase: 09-documents-and-finance
plan: 13
subsystem: finance
tags: [documents, finance, receipts, filament, relation-manager, medialibrary, archive-guard]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-05 ManagesDocuments tab, 09-07 DeleteDocument, 09-11 TransactionResource, 09-21 archived-owner scopes, 09-22 UpdateTransaction and DeleteTransaction, 09-01 DocumentOwnerResolver"
provides:
  - "transaction as a document owner kind (enum case, label, resolver branch, Transaction::documents())"
  - "TransactionDocumentsRelationManager, the Admin-only Soubory tab of a transaction, no visibility switch"
  - "UpdateTransaction keeps the client and project of the receipts in step with the transaction"
  - "DeleteTransaction deletes every receipt through DeleteDocument before the row; confirmation names the count"
  - "receipt count column and the Bez dokladu ternary filter on the transaction list"
  - "DocumentOwnerDeletionTest, the guard that no document owner gains a hard-delete path, with archive and restore proofs"
affects: [09-14 central listing, 09-15 finance overview, 09-20 consistency check and unused copy, Phase 10 invoice owner]

actuals:
  tokens: 12800
  tasks: 3
  commits: 3
plan_head_before: 8c0be14fb255d60531f3c6e046bc5b77332528e1
plan_head_after: 1b61bc4d4959a46d7de1a974502394e5774264b0
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A new document owner kind is one enum case, one label, one resolver branch and one documents() relation; the shared ManagesDocuments tab and the policy need no change"
    - "An owner that is hard-deleted deletes its documents through DeleteDocument first, inside the same database transaction, under the owner's row lock"
    - "Token-based source guards (forceDelete/forceDestroy calls, comment deletes in task Actions) with fixture self-checks so a scan cannot pass vacuously"

key-files:
  created:
    - app/Filament/RelationManagers/TransactionDocumentsRelationManager.php
    - tests/Feature/Finance/TransactionReceiptsTest.php
    - tests/Feature/Documents/DocumentOwnerDeletionTest.php
  modified:
    - app/Domain/Documents/Enums/DocumentOwnerType.php
    - app/Domain/Documents/Support/DocumentOwnerResolver.php
    - app/Domain/Finance/Models/Transaction.php
    - app/Domain/Finance/Actions/UpdateTransaction.php
    - app/Domain/Finance/Actions/DeleteTransaction.php
    - app/Filament/Resources/TransactionResource.php
    - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
    - lang/cs/enums.php
    - lang/cs/finance.php
    - tests/Feature/Documents/DocumentsRelationManagerTest.php

key-decisions:
  - "The receipts keep client_id and project_id equal to what the resolver gives for their transaction: UpdateTransaction updates them with one query through documents() when wasChanged(client_id, project_id), inside the same database transaction"
  - "DeleteTransaction locks the transaction row first and lists the receipts under that lock, so an upload waiting on the shared lock of its owner finds the row gone instead of attaching to a deleted transaction"
  - "A stuck object throws from DeleteTransaction and rolls the database back; the edit page catches the RuntimeException, reports it without a path and shows a failure notification instead of a server error"
  - "The Czech confirmation uses a trans_choice with {1} dokladem and [2,*] doklady (instrumental plural after 's')"
  - "The ternary filter has three states: all, Bez dokladu and S dokladem"
  - "The guard test lives in tests/Feature/Documents, as the plan decided, and implements its own token scans instead of extending EscapeHatchScanner, whose contract is the Partner scope"

patterns-established:
  - "Owner deletion guard: DocumentOwnerType::cases() must each map to a soft-deleting model, the append-only comment, or the transaction"

requirements-completed: [FI-01, DO-01, DO-03]

coverage:
  - id: D1
    description: "The Admin attaches receipts to a transaction on its Soubory tab, downloads, renames and deletes them; receipts are hidden, the visibility switch is not offered and a Partner of the client reads nothing"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionReceiptsTest.php#the tracer: the Admin attaches a receipt on the Soubory tab of a transaction"
        status: pass
    human_judgment: false
  - id: D2
    description: "A receipt counts for the client and project of its transaction (both may be missing), and a changed transaction client or project moves the receipts in the same transaction; no document disagrees with the resolver"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionReceiptsTest.php#receipts follow the client and project of their transaction"
        status: pass
    human_judgment: false
  - id: D3
    description: "Deleting a transaction after a confirmation naming the receipt count deletes the receipt rows, media rows and stored objects with it; a stuck object keeps the transaction and is reported"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionReceiptsTest.php#deleting a transaction deletes its receipts"
        status: pass
    human_judgment: false
  - id: D4
    description: "The transaction list shows the receipt count and filters transactions with and without a receipt"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionReceiptsTest.php#the list shows the receipts"
        status: pass
    human_judgment: true
    rationale: "Filter and column behaviour are asserted through Livewire; the look of the column and the three-state filter in the real panel is not"
  - id: D5
    description: "A receipt stays in the default Admin listing when its transaction's client is archived"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionReceiptsTest.php#a receipt is not a file of an archived owner"
        status: pass
    human_judgment: false
  - id: D6
    description: "No document owner can be hard-deleted: owner kinds map to soft-deleting models, the append-only comment or the transaction; no forceDelete call in app/; no task Action deletes a comment; archiving and restoring a task, project and client keeps every row, media row and object"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentOwnerDeletionTest.php"
        status: pass
      - kind: integration
        ref: "mutation run: a temporary app file calling ->forceDelete() made the app-wide scan fail"
        status: pass
    human_judgment: false

# Metrics
duration: 40min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 13: Transaction Receipts Summary

**Receipts as files of a transaction on the shared Soubory tab (Admin only, never shared), kept in step with the transaction's client and project, deleted with it through DeleteDocument, shown by a count column and a Bez dokladu filter, and a source and behaviour guard that no other document owner can be hard-deleted**

## Performance

- **Duration:** about 40 min (start time not recorded; approximate)
- **Completed:** 2026-10-16T15:07Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 13 (3 created, 10 modified)

## Accomplishments

- `DocumentOwnerType::Transaction` (label "Transakce", not shareable), a resolver branch that re-reads the transaction under a shared lock and returns its client and project (both nullable), and `Transaction::documents()`. `TransactionDocumentsRelationManager` reuses `ManagesDocuments`, sits before the history tab, is AdminOnly and has its own title key. The upload modal hides the visibility toggle and the stored receipt is hidden; a Partner of the client reads no document.
- `UpdateTransaction` moves the receipts to the new client and project with one `documents()->update()` whenever either changes, inside the same database transaction. A consistency query over all documents against the resolver finds no disagreement.
- `DeleteTransaction` now takes `DeleteDocument`, locks the transaction row, deletes each receipt through the Action and then the row, all in one database transaction. The edit-page confirmation names the receipt count (`trans_choice`), and a stuck object is reported without a path with a failure notification while the transaction stays.
- The list gains a `documents_count` column (one `withCount`) and a ternary filter (all, "Bez dokladu", "S dokladem").
- `DocumentOwnerDeletionTest` pins every owner kind (soft-deleting, append-only comment, transaction), scans `app/` by tokens for `forceDelete`/`forceDestroy` calls, scans the task Actions for comment deletes, proves both scanners on fixtures, and archives then restores a task, a project and a client carrying files, finding every documents row, media row and stored object intact at each step and the Partner reading the visible project and task files again at the end (D-21).

## Task Commits

1. **Task 1: Tracer, the Admin attaches a receipt and downloads it from the transaction's Soubory tab** - `cee2f83` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests, the documents directory, the enum label test, Pint and PHPStan were re-run (green) before expansion.
2. **Task 2: Receipts follow the transaction's client and go with it when it is deleted** - `ea50faf` (feat)
3. **Task 3: Owner deletion guard with archive and restore proofs** - `1b61bc4` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions`. In short: receipts follow the transaction by one query update, the delete locks the transaction first, a stuck object rolls everything back and is reported, and the guard test brings its own token scans.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing test pinned the owner label list**
- **Found during:** Task 1
- **Issue:** `tests/Feature/Documents/DocumentsRelationManagerTest.php` asserts `DocumentOwnerType::cases()` labels equal `['Klient', 'Projekt', 'Úkol', 'Komentář']`; the new enum case made it fail.
- **Fix:** Added `'Transakce'` to the expected list.
- **Files modified:** `tests/Feature/Documents/DocumentsRelationManagerTest.php`
- **Verification:** `ddev exec vendor/bin/pest tests/Feature/Documents`
- **Committed in:** cee2f83

**2. [Rule 2 - Missing critical functionality] Failure handling and extra copy for the delete action**
- **Found during:** Task 2
- **Issue:** The plan has no text for the case where a receipt object cannot be removed; the Action throws, which would reach the Admin as a server error. The plan also has no keys for the filter states.
- **Fix:** The edit page's `using` closure catches `RuntimeException`, reports it and returns false, with `notifications.delete_failed` as failure title; added `receipts.filter_with` and `receipts.filter_all`.
- **Files modified:** `app/Filament/Resources/TransactionResource/Pages/EditTransaction.php`, `lang/cs/finance.php`
- **Verification:** `TransactionReceiptsTest` "tells the Admin the transaction was not deleted when an object cannot be removed"
- **Committed in:** ea50faf

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 missing critical). **Impact on plan:** both are direct consequences of the planned work; no scope creep.

### Plan interpretation (not a deviation)

- The `delete_with_receipts` copy in the plan's artifact table is one key; it is a pluralised string (`{1}` / `[2,*]`) so the Czech grammar of the count is right.
- When the second of two objects cannot be removed, the first object is already gone while its rows roll back with the database transaction. This is the accepted rare case of the one-way deletion recorded in the plan (D-09); the consistency check of plan 09-20 finds the document row that is left without its object. The test therefore asserts only the transaction, the stuck receipt row and the stuck object.

## Authentication Gates

None.

## Issues Encountered

- A modal description is not part of the rendered Livewire HTML at mount time, so the first version of the confirmation test (`assertSee`) failed; the test now reads `getMountedAction()->getModalDescription()`.
- Pint removed imports that the first tracer-only test file did not use; they were added back when the Task 2 tests needed them.

## Known Stubs

None.

## Threat Flags

None. The new surface is covered by T-09-45 (transaction owner never shareable: resolver, enum, hidden toggle, Partner test), T-09-46 (sync inside `UpdateTransaction`, consistency query test) and T-09-47 (`DeleteTransaction` through `DeleteDocument`, the owner deletion guard and the archive and restore proofs). `composer.json` and `composer.lock` untouched (T-09-SC).

## Verification

- `ddev exec vendor/bin/pest`: 4337 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- Acceptance greps of all three tasks pass; mutation run of the app-wide hard-delete scan made it fail as intended

## Next Phase Readiness

- Plan 09-14 (central listing) can show transaction receipts: they are never "archived owner" files and carry the transaction's client and project.
- Plan 09-20 should cover the orphan left by a commit that fails after an object was removed (now also reachable from `DeleteTransaction`), and the `CONTRIBUTING` hand-over note still names `toCzk()`.
- Open for a human: the look of the receipt column, the three-state filter and the delete confirmation in the real panel (coverage D4).

## Self-Check: PASSED

- Created files exist: `TransactionDocumentsRelationManager.php`, `TransactionReceiptsTest.php`, `DocumentOwnerDeletionTest.php` (found)
- Commits `cee2f83`, `ea50faf`, `1b61bc4` are ancestors of HEAD; `git rev-list --count 8c0be14..HEAD` was 3 before this SUMMARY

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
