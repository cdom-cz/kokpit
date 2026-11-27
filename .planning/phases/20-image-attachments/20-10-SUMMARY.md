---
phase: 20-image-attachments
plan: 10
subsystem: knowledge
tags: [images, delete, redaction, audit, arch-test]
status: complete

requires:
  - phase: 20-image-attachments
    provides: image and reference models (20-01), image lang keys (20-04), reference writer Arch rule (20-06), image route (20-07)
provides:
  - DeleteImageForGood Action (handle(User, KnowledgeArticleImage, mixed $reason): void, MAX_REASON_LENGTH = 500)
  - ReasonText shared reason validation helper
  - activity event image_deleted and its Czech label
affects: [20-11]

tech-stack:
  added: []
  patterns:
    - "irreversible knowledge actions validate the typed reason through one helper that returns the length only"
    - "object deleted inside the transaction, a storage refusal raises ArticleWriteFailed('storage') and rolls the rows back"

key-files:
  created:
    - app/Domain/Knowledge/Actions/DeleteImageForGood.php
    - app/Domain/Knowledge/Actions/ReasonText.php
    - tests/Feature/Knowledge/DeleteImageForGoodTest.php
  modified:
    - app/Domain/Knowledge/Actions/RedactVersion.php
    - lang/cs/kokpit.php
    - tests/Arch/KnowledgeSeamTest.php

key-decisions:
  - "Followed rulings R-20-10-1 to R-20-10-4."
  - "A storage refusal (false result or any Throwable from the disk) is raised directly as ArticleWriteFailed('storage') inside the transaction instead of a RuntimeException mapped afterwards: ModelNotFoundException is itself a RuntimeException, so a catch on RuntimeException would have swallowed it. The outcome (rollback, fixed code, no original message, no chained exception) is the one the ruling describes."
  - "The image policy (admin only) is checked on the image before the article is read, so a Partner gets AuthorizationException even where the partner scope would hide the article row."

requirements-completed: [IMG-03]

plan_head_before: 4b46ec6c85132cf00b70b54dbdcc0bb216f316d1
plan_head_after: e1da71960399cef864d61cccbf185108880d2b8b
commits: 2

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 10: Delete an image for good Summary

**An Admin deletes one image at once with a required reason: object, row and every reference row of every version go in one transaction, the history keeps one `image_deleted` row with `reason_recorded` only, and both irreversible knowledge actions now validate the reason through one helper.**

## What was built

- **`DeleteImageForGood`** (final Action, `MAX_REASON_LENGTH = 500`). Steps: a pending image throws `ModelNotFoundException`; `update` is authorized on the image and on the article (read `withTrashed()`, so archived articles work); the reason is validated by `ReasonText::length()` and the local variable is nulled; one transaction locks the article row, then the image row (`whereKey` plus `article_id`, `lockForUpdate`), deletes the reference rows and the image row through the query builder, deletes the object at `storagePath()` and writes one `image_deleted` activity row (`reason_recorded => true` only, causer the actor). A `PDOException` becomes `ArticleWriteFailed(SQLSTATE)` without chaining; a storage refusal becomes `ArticleWriteFailed('storage')` inside the transaction, so the rows roll back and the image stays complete. The accepted residual (commit fails after the object went, route answers 404, a retry completes) is in the class docblock.
- **`ReasonText::length()`** (final, static): refuses NUL with `knowledge.errors.nul_character`, empty, whitespace-only or non-string with the given required key, and a trimmed length above the maximum with the given too-long key and `max`; returns the trimmed length, never the text.
- **`RedactVersion::reasonLength()`** is now a one-line delegation with its `knowledge.redact.errors.*` keys; behaviour unchanged (the existing RedactVersionTest cases for blank, empty, non-text, too long and NUL cover it).
- **Label** `kokpit.activity.events.image_deleted` = "Obrázek smazán navždy".
- **Arch seam**: `DeleteImageForGood` joins `KNOWLEDGE_SEAM_IMAGE_REFERENCE_WRITERS`; the OD-1 test also asserts the file contains `'reason_recorded' =>`.
- **Feature tests** (`DeleteImageForGoodTest`): full delete with object, row, two version references, 404 on the image route, one activity row with exact properties, neither reason nor file name in any activity column or captured log line; archived article; other images untouched; the five bad reasons refused on `reason` with Czech messages and nothing deleted; reason of exactly 500 characters accepted; Partner and role-less user refused; pending image refused; storage refusal (false result and thrown exception) rolls everything back with `ArticleWriteFailed` code `storage`, no previous exception and no reason text in the message.

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: delete an image for good with a reason | 578ff240 |
| 2 | Shared reason rule, Arch writer entry, refusal and failure tests | e1da7196 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug avoided] Storage failure raised as ArticleWriteFailed directly**
- **Found during:** Task 1
- **Issue:** the ruling throws a RuntimeException in the transaction and maps it afterwards. `ModelNotFoundException` extends `RuntimeException`, so a broad catch would also convert a vanished image row into a storage failure.
- **Fix:** the storage step throws `ArticleWriteFailed('storage')` itself; the transaction still rolls back and the caller sees the same exception and code.
- **Files modified:** app/Domain/Knowledge/Actions/DeleteImageForGood.php
- **Commit:** 578ff240

Otherwise none - plan executed as written. Task 1 commits only the tracer tests (success, archived, other images); the refusal and failure tests arrive with Task 2.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites here, no vendor in the worktree):

- `php -l` clean on every touched PHP file; Pint (`--test`, run from the main checkout's vendor) passes; `scripts/check-sensitive.sh` clean; the lefthook hook (sensitive-content, gitleaks) passed on both commits.
- Acceptance greps pass: `event('image_deleted')` 1, `'reason_recorded' => true` 1, `function length` 1, `'image_deleted' =>` 1, `ReasonText::` in RedactVersion 1, DeleteImageForGood path in the seam test 2, `ReasonText::length` in DeleteImageForGood 1.
- PHPStan could not run (larastan is not installed in the worktree).
- The tracer gate (end-to-end re-run of the verify) needs the database and is therefore deferred to the post-merge run.

**Not run here, to run after the merge of wave 3 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/DeleteImageForGoodTest.php tests/Feature/Knowledge/RedactVersionTest.php tests/Feature/Knowledge/VersionRedactModalTest.php tests/Arch/KnowledgeSeamTest.php tests/Arch/ActivityAllowlistTest.php`
2. PHPStan in DDEV on the three Actions.

Points worth watching: the storage-failure test swaps the default disk with `Storage::set(config('filesystems.default'), <Mockery double>)`; the fixture and the route test assume `Storage::fake('local')` backs the default disk, as the existing route test does.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-34 reason validated and dropped, activity row holds the flag only, Feature test searches activity rows and captured logs; T-20-35 admin-only policy on image and article with a Partner test; T-20-36 one `image_deleted` row written in the same transaction).

## Self-Check: PASSED

- Files exist: DeleteImageForGood.php, ReasonText.php, DeleteImageForGoodTest.php, this SUMMARY.
- Commits 578ff240 and e1da7196 are ancestors of HEAD.
