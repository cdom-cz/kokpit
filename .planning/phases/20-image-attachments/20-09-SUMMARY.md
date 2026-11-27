---
phase: 20-image-attachments
plan: 09
subsystem: knowledge
tags: [images, cleanup, job, schedule, concurrency]
status: complete

requires:
  - phase: 20-image-attachments
    provides: image and reference tables and models (20-01), BindArticleImages and the reference index (20-06), RedactVersion resync (20-06)
provides:
  - PruneImages Action (run(): pending, stamped, cleared, deleted; PENDING_HOURS = 24, ORPHAN_GRACE_DAYS = 7)
  - PruneKnowledgeImages daily KokpitJob and the kokpit-knowledge-image-prune schedule entry
  - Concurrency test and worker proving cleanup vs save
affects: [20-10]

tech-stack:
  added: []
  patterns:
    - "cleanup decides from SQL over the reference relation only (whereHas / whereDoesntHave), never parses Markdown"
    - "per-row transaction: re-read under lockForUpdate with the pass conditions, recheck references, object first then row"

key-files:
  created:
    - app/Domain/Knowledge/Actions/PruneImages.php
    - app/Domain/Knowledge/Jobs/PruneKnowledgeImages.php
    - tests/Feature/Knowledge/PruneKnowledgeImagesTest.php
    - tests/Concurrency/KnowledgeImageCleanupConcurrencyTest.php
    - tests/Concurrency/knowledge-image-worker.php
  modified:
    - routes/console.php
    - tests/Arch/JobContractTest.php

key-decisions:
  - "Followed rulings R-20-09-1 to R-20-09-4 verbatim."
  - "The bulk statements of the clear and stamp passes use the Eloquent builder update() with whereHas / whereDoesntHave('references'), so no reference table name or model appears in the Action."

requirements-completed: [IMG-03]

plan_head_before: 4b46ec6c85132cf00b70b54dbdcc0bb216f316d1
plan_head_after: 4afe4e27c7c11f02d81572cdccc530ab2761f03d
commits: 3

actuals:
  tokens: 17000
  tasks: 3
  commits: 3

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 09: Image cleanup job Summary

**A daily job deletes pending images after 24 hours and unreferenced images 7 days after they were stamped as orphans, rechecking every deletion under a row lock so a racing save never loses an image it just referenced; a real two-process test pins the race.**

## What was built

- **`PruneImages`** (final Action, `run(): array{pending, stamped, cleared, deleted}`): four passes. Pass 1 deletes pending rows older than `PENDING_HOURS` (24) in `chunkById`, each in a transaction that re-reads the row with `whereNull('article_id')->lockForUpdate()` and deletes the object, then the row. Pass 2 clears the stamp of an `orphaned_at` row that `whereHas('references')`. Pass 3 stamps bound, unstamped rows that `whereDoesntHave('references')`. Pass 4 deletes rows stamped more than `ORPHAN_GRACE_DAYS` (7) ago, per row under `lockForUpdate()` with a `references()->exists()` recheck (true: clear the stamp and skip). A missing object is no error; nothing is logged. The docblock carries the concurrency reasoning of R-20-09-2.
- **`PruneKnowledgeImages`** (KokpitJob, `#[Idempotent(...)]`, `handle(PruneImages $prune)`): runs in the system context; docblock states the effective delays (pending 24 to 25 hours, orphans 7 to 8 days).
- **Schedule**: `kokpit-knowledge-image-prune`, daily, `onOneServer()`, in `routes/console.php` after the document archive prune; `PruneKnowledgeImages::class` added to `EXPECTED_APPLICATION_JOBS`.
- **Feature tests** (`PruneKnowledgeImagesTest`): pending old/young/bound, the 24-hour boundary, system context with nobody signed in, the schedule entry, removed-but-versioned image never stamped at 0, 8 and 30 days, stamp then deletion after 6 and 7d1h days, scheduled job path, stamp cleared by a new save and by a reference row, missing object (orphan and pending), second run finds nothing, and a source check that the Action names neither `ImageReferences` nor `KnowledgeMarkdown`.
- **Concurrency test** (`KnowledgeImageCleanupConcurrencyTest` + `knowledge-image-worker.php`): ten rounds, a `save` and a `prune` worker behind one barrier against a stamped orphan with an object; asserts no exception, save outcome `Updated`, and exactly one of the two valid end states (kept + indexed for version 2 with stamp cleared, or image and object gone with no reference), with the deleted count agreeing.

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: the daily job deletes pending images older than 24 hours | 079ed9ce |
| 2 | Orphan stamping, grace period and reference survival tests | 50fb2cb2 |
| 3 | Concurrency test and worker | 4afe4e27 |

## Deviations from Plan

None in behaviour. One ordering note: as in 20-06, the complete `PruneImages` Action (all four passes) was written together with the Task 1 tracer, so the Task 1 commit holds the whole Action and the tracer tests, and the Task 2 commit adds the remaining Feature tests. Plan 20-09 lists `PruneImages.php` under Task 2 files, but it needed no further change.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites here; the worktree has no `vendor`, so Pint and PHPStan could not run):

- `php -l` clean on every touched PHP file; `scripts/check-sensitive.sh` clean; the lefthook hook (sensitive-content, gitleaks) passed on all three commits.
- Acceptance greps: `kokpit-knowledge-image-prune` 1, `PruneKnowledgeImages::class` in the job contract 1, `#[Idempotent(` 1, `PENDING_HOURS = 24;` 1, `ORPHAN_GRACE_DAYS = 7;` present, `lockForUpdate` 2, `whereDoesntHave('references')` 1, `ImageReferences` 0 in the Action, `PruneImages` and `SaveArticle` in the worker, `KNOWLEDGE_IMAGE_CLEANUP_` in the test.

**Not run here, to run after the merge of wave 3 on the HEAD branch (DDEV, one Pest process at a time, never `--parallel`):**

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/PruneKnowledgeImagesTest.php tests/Arch/JobContractTest.php tests/Arch/KnowledgeSeamTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php`
2. Alone: `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Concurrency/KnowledgeImageCleanupConcurrencyTest.php`
3. Pint and PHPStan (level 8) on `PruneImages`, `PruneKnowledgeImages` and the tests; `tests/Arch/QueryEscapeHatchTest` (the Action uses no `DB::table`, only `DB::transaction`).

Points worth watching: the Feature tests rely on `travel()` shifting both the application clock (the stamp and the cutoff) and the Eloquent `created_at`; the concurrency test uses the real default disk (`local` under phpunit) and removes its own objects, and passes `FILESYSTEM_DISK` to the workers explicitly.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-31 only unreferenced images are stamped, deletion after 7 days and a recheck under a row lock, Feature tests for removed-but-versioned images; T-20-32 row locks and recheck, concurrency test with real processes; T-20-33 redaction resync of 20-06 makes an orphan that this job deletes 7 to 8 days later).

## Self-Check: PASSED

- Files exist: PruneImages.php, PruneKnowledgeImages.php, PruneKnowledgeImagesTest.php, KnowledgeImageCleanupConcurrencyTest.php, knowledge-image-worker.php, this SUMMARY.
- Commits 079ed9ce, 50fb2cb2, 4afe4e27 are ancestors of HEAD.
