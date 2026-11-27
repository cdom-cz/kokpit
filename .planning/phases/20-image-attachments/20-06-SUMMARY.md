---
phase: 20-image-attachments
plan: 06
subsystem: knowledge
tags: [images, references, save, redaction, arch-test]
status: complete

requires:
  - phase: 20-image-attachments
    provides: image and reference tables and models (20-01), ImageReferences parser (20-04), lang key knowledge.images.errors.foreign_reference
provides:
  - BindArticleImages Action (lockReferenced, bindAndIndex)
  - SaveArticle image step in create and in the content-changing branch of update (restores included)
  - RedactVersion reference resync
  - Arch rule pinning the writers of knowledge_article_version_images
affects: [20-07, 20-09, 20-10]

tech-stack:
  added: []
  patterns:
    - "image rows locked FOR UPDATE in id order after the article row; foreign or other-user pending reference refused as a field error on body"
    - "reference index written through the query builder only in reviewed writers, pinned by a token-scan Arch test"

key-files:
  created:
    - app/Domain/Knowledge/Actions/BindArticleImages.php
    - tests/Feature/Knowledge/SaveArticleImagesTest.php
    - tests/Feature/Knowledge/RedactVersionImagesTest.php
  modified:
    - app/Domain/Knowledge/Actions/SaveArticle.php
    - app/Domain/Knowledge/Actions/RedactVersion.php
    - tests/Arch/KnowledgeSeamTest.php

key-decisions:
  - "Followed rulings R-20-06-1 to R-20-06-4 verbatim."
  - "lockReferenced() treats a null article (create) as having no owned images, so a bound image is always foreign there and the null-equals-null comparison cannot accept a pending row by accident."
  - "The reference insert and the redaction delete use DB::table('knowledge_article_version_images') so the Arch scan sees the table name as a string token."

requirements-completed: [IMG-03]

plan_head_before: 60d9f014f4d2484cced6cabe7b30db1ae49a0ab6
plan_head_after: 4335b4a315fc887d3d8ab034ac9024f74464801a
commits: 3

actuals:
  tokens: 21000
  tasks: 3
  commits: 3

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 06: Bind and index images on save Summary

**SaveArticle now locks the images its body names, refuses another article's or another user's, binds the Admin's pending ones and writes one reference row per image for every new version; RedactVersion deletes the references its rewrite removed; an Arch rule pins the only two writers of the reference table.**

## What was built

- **`BindArticleImages`** (final Action). `lockReferenced($body, $article, $actor)` reads `ImageReferences::in()`, locks the rows with `orderBy('id')->lockForUpdate()`, skips unknown or deleted ids silently (D-18) and throws `ValidationException` on `body` with `knowledge.images.errors.foreign_reference` for a row of another article or a pending row of another user (D-04). `bindAndIndex($article, $version, $images)` binds pending rows with a query-builder update constrained by `whereNull('article_id')` (one row must change, else `LogicException`), clears `orphaned_at` on accepted rows that carry one, and inserts one reference row per accepted image (D-16). Nothing is logged.
- **`SaveArticle`**: constructor takes `BindArticleImages`; `writeVersion()` returns the version; `create()` and the content-changing branch of `update()` (which a restore goes through) call `lockReferenced()` after `acknowledgedRules()` and `bindAndIndex()` after `writeVersion()`. The no-op, classification-only and sensitivity-only branches run no image step. Class docblock gained the "Phase 20 appends" paragraph.
- **`RedactVersion`**: inside the existing transaction, after the two body updates and before the setting is switched off, reference rows of the rewritten version whose image is not in `ImageReferences::in($new)` are deleted. `orphaned_at` is left to the cleanup (20-09). Docblock extended.
- **Arch seam** (`tests/Arch/KnowledgeSeamTest.php`): `KNOWLEDGE_SEAM_IMAGE_REFERENCE_WRITERS` (BindArticleImages, RedactVersion), `knowledgeSeamImageReferenceWrites()` and three tests: no other app file writes the table, each listed writer still does, and a synthetic insert, delete and forceFill are reported while a read, a comment and a string mention are not.
- **Feature tests**: `SaveArticleImagesTest` (create bind and index, deduplication, update indexing, dropped reference keeps older rows, bind on update, no-write branches, foreign and other-admin refusals with nothing written, edit-screen `data.body` error with typed text kept, unknown image skipped, restore with a deleted and with an existing image, orphan stamp cleared) and `RedactVersionImagesTest` (line, whole, current version, later version keeps its row, failed redaction deletes nothing).

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: bind and index images on create and update | 1f37b424 |
| 2 | Update indexing, foreign refusals, unknown and deleted images, restores | 1eb088ea |
| 3 | Redaction resync and the Arch seam rule | 4335b4a3 |

## Deviations from Plan

None - plan executed exactly as written. The implementation of Tasks 1 and 2 was written together, so the Task 1 commit holds the complete Action and SaveArticle change with the tracer tests, and the Task 2 commit adds the remaining tests.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites here):

- `php -l` clean on every touched PHP file; Pint (`--test`) passes on all of them; `scripts/check-sensitive.sh` clean; the lefthook hook (sensitive-content, gitleaks) passed on all three commits.
- The new seam scanner was run against every file under `app/` with a scratch script: it reports exactly `RedactVersion.php` (delete) and `BindArticleImages.php` (insert), and its synthetic fixture gives 3 hits for the violations and 0 for the clean source.
- Acceptance greps of the three tasks pass (BindArticleImages in SaveArticle, `lockForUpdate`, "Phase 20 appends" once, `knowledge.images.errors.foreign_reference` once, `RestoreVersion` and `data.body` in the test, `ImageReferences::in` in RedactVersion, the seam constant at least twice).

**Not run here, to run after the merge of wave 2 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/SaveArticleImagesTest.php tests/Feature/Knowledge/SaveArticleTest.php tests/Feature/Knowledge/RestoreVersionTest.php tests/Feature/Knowledge/SaveArticleTokensTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/RedactVersionImagesTest.php tests/Feature/Knowledge/RedactVersionTest.php tests/Arch/KnowledgeSeamTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Arch/KnowledgeSeamTest.php tests/Concurrency/KnowledgeSaveConcurrencyTest.php tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php`
4. PHPStan in DDEV on the three Actions.

Points worth watching: the edit-screen test relies on `assertHasErrors(['data.body'])` for the `withFormErrors` remapping; the tests use `Str::uuid7()` ids so a body can name an image before its row exists.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-19 foreign reference refused in `lockReferenced()` with Feature tests for both refusals; T-20-20 index written in the save transaction and resynced in the redaction transaction, writers pinned by the Arch rule; T-20-21 lock order article row then image rows by id).

## Self-Check: PASSED

- Files exist: BindArticleImages.php, SaveArticleImagesTest.php, RedactVersionImagesTest.php, this SUMMARY.
- Commits 1f37b424, 1eb088ea, 4335b4a3 are ancestors of HEAD.
