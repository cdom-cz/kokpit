---
phase: 19-folders-tags-and-version-history
plan: 05
subsystem: knowledge
tags: [folders, tree, advisory-lock, czech-collation, actions]
requires:
  - phase: 19-folders-tags-and-version-history/19-02
    provides: KnowledgeFixtures::folderChain(), copy keys knowledge.folders.errors.*
provides:
  - FolderName input rule (trim, 1 to 100 characters, no NUL)
  - FolderTree query (two queries, Czech order, depth, height, path, rolled-up counts, subtree and move rules)
  - CreateFolder, RenameFolder, MoveFolder, DeleteFolder Actions
  - FolderMoveRefused (cycle, depth, maxDepth) and FolderNotEmpty (articleCount, subfolderCount) exceptions
affects: [19-08, 19-10]
tech-stack:
  added: []
  patterns:
    - Action checks the ability on a blank model before any lookup, so a Partner never learns whether an id exists
    - PHP classification under the same advisory lock key as the tree trigger, the trigger error mapped by message as backstop
key-files:
  created:
    - app/Domain/Knowledge/Actions/FolderName.php
    - app/Domain/Knowledge/Actions/CreateFolder.php
    - app/Domain/Knowledge/Actions/RenameFolder.php
    - app/Domain/Knowledge/Actions/MoveFolder.php
    - app/Domain/Knowledge/Actions/DeleteFolder.php
    - app/Domain/Knowledge/Exceptions/FolderMoveRefused.php
    - app/Domain/Knowledge/Exceptions/FolderNotEmpty.php
    - app/Domain/Knowledge/Queries/FolderTree.php
    - tests/Feature/Knowledge/FolderActionsTest.php
    - tests/Feature/Knowledge/FolderTreeTest.php
  modified: []
key-decisions:
  - "R-19-05-2: FolderTree::load() is two queries; nodes carry depth, height, path, rolled-up count, hasChildren"
  - "R-19-05-8: folder Actions write no activity row (research A10, flagged for the owner)"
requirements-completed: [ORG-01, ORG-02]
duration: 40min
completed: 2026-11-26
status: complete
actuals:
  tokens: 14600
  tasks: 3
  commits: 3
plan_head_before: d658fbaf9d0918c2b3ce2f34e82680be0b4b9940
plan_head_after: bcf14fd148f2ba08aa70f27b1f70c19bb555c0fd
commits: 3
---

# Phase 19 Plan 05: Folder Actions and tree query Summary

Admin-only folder create, rename, move and delete with self-explaining refusals (cycle, depth with the cap, not-empty with counts), all reading `KnowledgeFolder::MAX_DEPTH`, plus a two-query `FolderTree` that the list screen and the folder selects will read.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: FolderName, FolderTree, CreateFolder (and FolderMoveRefused, needed by the tree) with tests | 92a535a7 |
| 2 | RenameFolder, MoveFolder with `refusalFor()` KP003 backstop and tests | 2ff06280 |
| 3 | DeleteFolder, FolderNotEmpty with `refusalFor()` 23503 backstop and tests | bcf14fd1 |

## Verification status

Run in the worktree: `php -l` on all ten files, every grep of the plan's acceptance criteria (all satisfied), `scripts/check-sensitive.sh`, lefthook and gitleaks on each commit.

NOT run in the worktree (no DB or vendor there, per the phase rules): the Pest suites `FolderActionsTest`, `FolderTreeTest`, `KnowledgeTablesTest`, `KnowledgeVisibilityTest`. They must be run after the wave-2 merge in DDEV, one process at a time, never `--parallel`. This includes the tracer feedback gate of Task 1 (end-to-end create plus tree check): it is deferred to that post-merge run and nothing was expanded on an unverified assumption beyond what the plan itself prescribes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] FolderMoveRefused created in Task 1 instead of Task 2**
- **Found during:** Task 1
- **Issue:** `FolderTree::moveRefusal()` returns `FolderMoveRefused::CYCLE` and `DEPTH`, so the exception class must exist when the tree query is committed.
- **Fix:** Wrote and committed `FolderMoveRefused` in Task 1; Task 2 consumes it.
- **Files modified:** app/Domain/Knowledge/Exceptions/FolderMoveRefused.php
- **Commit:** 92a535a7

**2. [Rule 2 - Missing critical functionality] Ability checked on a blank model before the lookup**
- **Found during:** Task 2
- **Issue:** The plan says "authorise update on the resolved folder". The folder query carries the Partner deny-all scope, so a Partner calling an Action directly would hit a not-found before the authorisation and the refusal would depend on the caller's scope rather than the policy (and leak nothing but behave inconsistently with the plan's "refused ... also when they call it directly").
- **Fix:** Rename, Move and Delete authorise on `new KnowledgeFolder` first (Create already uses the class), then again on the locked row.
- **Files modified:** RenameFolder.php, MoveFolder.php, DeleteFolder.php
- **Commit:** 2ff06280, bcf14fd1

**3. [Rule 3 - Blocking] Shared helpers placed on existing classes**
- `FolderTree::nameTaken()` (case-insensitive sibling check shared by create, rename and move) lives on the query class instead of a new file; the SQLSTATE reader reuses `App\Domain\Finance\Support\SqlState` instead of a fourth copy.

None of these changes the contract of the plan's interfaces.

## Known Stubs

None.

## Notes for the owner and later plans

- Folder changes are not in the activity history (research A10): metadata-only folder audit can be added later.
- 19-08 and 19-10 should catch `ModelNotFoundException` (notification `folders.notifications.missing`), `FolderMoveRefused` (`folders.errors.cycle` / `depth` with `:max` from `$e->maxDepth`), `FolderNotEmpty` (`not_empty` with `contents_*` built from the two counts) and `ValidationException` keyed `name` or `parent_id`.
- `FolderTree::nodes()` already carries depth, height, path, count and hasChildren for the tree panel and the parent select; `moveRefusal()` can disable impossible targets in the move select.

## Threat Flags

None. No new endpoint, auth path or schema change; T-19-16 to T-19-19 mitigated as planned (policy check inside every Action, advisory lock plus trigger backstop, `withTrashed()` counts, `Str::isUuid` before any query).

## Self-Check: PASSED

All ten files exist; commits 92a535a7, 2ff06280 and bcf14fd1 are on the branch (the Pest runs are pending, see Verification status).
