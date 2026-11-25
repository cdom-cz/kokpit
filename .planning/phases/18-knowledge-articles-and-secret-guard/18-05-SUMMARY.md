---
phase: 18-knowledge-articles-and-secret-guard
plan: 05
subsystem: knowledge
tags: [save-path, versioning, secret-guard, row-lock, concurrency, activity-log]

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: models, policies and fixtures (18-01, 18-02), markdown limits (18-03), scanner, normaliser and secret fixtures (18-04)
provides:
  - SaveArticle, the only write path of title, body_md, sensitive, version_no and edited_at (create and update)
  - ArticleInput, SaveOutcome and SaveResult value types with no Filament dependency
  - StaleArticle, SecretGuardRefused and ArticleWriteFailed exceptions with fixed, content-free messages
  - Czech activity labels for knowledge_article, secret_acknowledged, article_archived and article_restored
  - Feature test proof and a real-process concurrency proof of the version chain
affects: [18-08, 18-09, 18-10, phase-19-restore, phase-21-kinds]

actuals:
  tokens: 17500
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Row-locked save: authorise, scoped lockForUpdate re-read, second authorisation, stale check, normalise, no-op check, scan, write"
    - "Database failures leave the Action only as ArticleWriteFailed (SQLSTATE, fixed message, no previous exception)"
    - "Acknowledgment is a per-call argument, never stored; the audit row holds rule keys and the version number only"
    - "Log-leak proof: live MessageLogged capture with a probe, plus a watch list asserted in afterEach"

key-files:
  created:
    - app/Domain/Knowledge/Actions/ArticleInput.php
    - app/Domain/Knowledge/Actions/SaveOutcome.php
    - app/Domain/Knowledge/Actions/SaveResult.php
    - app/Domain/Knowledge/Actions/SaveArticle.php
    - app/Domain/Knowledge/Exceptions/StaleArticle.php
    - app/Domain/Knowledge/Exceptions/SecretGuardRefused.php
    - app/Domain/Knowledge/Exceptions/ArticleWriteFailed.php
    - tests/Feature/Knowledge/SaveArticleTest.php
    - tests/Concurrency/KnowledgeSaveConcurrencyTest.php
    - tests/Concurrency/knowledge-save-worker.php
  modified:
    - lang/cs/kokpit.php

key-decisions:
  - "Sensitive flag derivation (R-18-05-5): an acknowledged save always ends sensitive, only clearSensitive clears, sensitive true marks, everything else keeps the stored flag"
  - "SaveResult::markedSensitive is true whenever this save applied an acknowledgment of an Ambiguous finding, also when the flag was already set"
  - "The no-op branch derives the flag without an acknowledgment and never scans (research Open Question 2)"
  - "The version number comes from the locked row plus one; no SequenceAllocator and no max() query"

patterns-established:
  - "ArticleWriteFailed drops the original PDOException because the query message holds the bound title and body and the log formatter appends every previous exception message"
  - "Concurrency worker prints the exception class only, never a message"

requirements-completed: [KB-01, KB-08, VER-01, SEC-01, SEC-02, SEC-03]

duration: 40min
completed: 2026-11-25
status: complete
plan_head_before: 746accd21e37221c5aa5adada48f4305a98f2098
plan_head_after: d91df1c662a0dc430669d8c54c243fbcd520b7f6
---

# Phase 18 Plan 05: SaveArticle, Secret Guard Wiring and Concurrency Proof Summary

**The single row-locked save path of the knowledge center: every content change is a gap-free immutable version, a no-op leaves no trace, a stale save is refused, every content save passes the secret guard (Blocking never, Ambiguous only with this call's acknowledgment), and no title or body reaches an exception, activity row or log.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 of 3
- **Files created:** 10, modified: 1

## Accomplishments

- `SaveArticle::create()` stores a general article as version 1 with its version row. `SaveArticle::update()` takes the expected version as a required argument and runs authorise, lock, stale check, normalise, no-op check, scan and write in one transaction (the order of R-18-05-4).
- Content changes (a title change alone included) write the next version number from the locked row and a full snapshot. A resave that differs only in prose whitespace is `Unchanged`; a difference inside code is a content change.
- The guard decision (R-18-05-5): any Blocking finding refuses with no override, an Ambiguous finding refuses unless this call acknowledges it, in which case the article ends sensitive and one `secret_acknowledged` activity row carries the sorted rule keys and the new version number. The title is never scanned.
- The sensitive flag only goes false through the explicit `clearSensitive` input; a content save or a save without content change with `sensitive` false keeps it. An acknowledged save wins over a clear request.
- A `PDOException` around either transaction call becomes `ArticleWriteFailed` with the fixed message and the SQLSTATE, no chained previous exception.
- Concurrency proof: five rounds of four parallel `once` workers (one winner, three stale, gap-free numbering, newest body is the winner's) and one run of four `chain` workers with three saves each (twelve saves, versions 1 to 13).

## Task Commits

1. **Task 1 (tracer): value types, SaveArticle write path, StaleArticle, ArticleWriteFailed, versioning/no-op/stale/authorisation/log-capture tests** - `8a81208`
2. **Task 2: secret guard in both write paths, SecretGuardRefused, acknowledgment, sensitive flag tests, Czech activity labels** - `d5b33c8`
3. **Task 3: parallel save worker and concurrency test** - `d91df1c`

## Deviations from Plan

### Auto-fixed Issues

None - the plan was executed as written. Two environment notes, not code deviations:

**1. [Environment] No test or migration run in the worktree**
- **Why:** project rule (CLAUDE.md, worktrees): no DB-backed tests and no migrations in a worktree, and the worktree has no `vendor/`.
- **What ran instead:** `php -l` on every touched PHP file, `pint --test` (using the main checkout's pint binary against the worktree files) on `app/Domain/Knowledge`, `tests/Feature/Knowledge`, both concurrency files and `lang/cs/kokpit.php`, the acceptance greps of each task, and `scripts/check-sensitive.sh` before every commit. All green.
- **Consequence for TDD (Task 2):** the RED step could not be executed here (no database), so tests and implementation of Task 2 are in one commit instead of a separate failing `test(...)` commit. The Pest runs of the plan are for the orchestrator after the merge.

**2. [Scope note] Task 1 introduced `SaveArticle` with a single-argument constructor, Task 2 added the scanner**
- Task 1 left the scan step to Task 2 as the plan describes; the constructor gained `SecretScanner` in Task 2 (container-resolved, no caller affected).

## Verification Still Due After the Merge

Run in DDEV on the HEAD branch after `ddev exec php artisan migrate` (never `--parallel`, the Concurrency file alone):

- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Feature/Operations tests/Isolation/KnowledgeVisibilityTest.php`
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` (record its runtime in the phase notes; it was not measurable in the worktree)
- `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Points worth a look if a test fails there: the `report()` capture of the forced unique violation (the default handler must log `ArticleWriteFailed`), the Activity attribute serialisation used by the leak assertions, and the expected normalised bodies of the CRLF/code-block test (derived from `MarkdownNormaliser` by hand).

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; the Action is the only writer the threat register T-18-22 to T-18-28 and T-18-51 already covers.

## Self-Check: PASSED

- Files present: all ten created files and `lang/cs/kokpit.php` exist in the worktree.
- Commits `8a81208`, `d5b33c8` and `d91df1c` are ancestors of HEAD.
- Acceptance greps of Tasks 1 to 3 return the expected counts; `pint --test` passes; `scripts/check-sensitive.sh` clean on every commit.
