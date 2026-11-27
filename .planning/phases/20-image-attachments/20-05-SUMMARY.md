---
phase: 20-image-attachments
plan: 05
subsystem: knowledge
tags: [livewire, file-upload, images, filament, storage]
status: complete

requires:
  - phase: 20-image-attachments
    provides: ImagePipeline and ImageRefused (20-02), KnowledgeArticleImage model (20-01), ImageReferences and the Czech copy (20-04)
provides:
  - "StoreArticleImage::handle(User, UploadedFile): StoredImage (gate, size cap, pipeline, object, pending row)"
  - "StoredImage result (id, altText, width, height, reference)"
  - "ImageNames::altText() and fileName() sanitisers of a client file name"
  - "HandlesImageUploads trait and ImageUploadForm interface on the create and edit screens (storeImageUpload(), imageNoticeVisible, showsImageNotice())"
affects: [20-06, 20-08, 20-09, 20-11]

plan_head_before: 60d9f014f4d2484cced6cabe7b30db1ae49a0ab6
plan_head_after: 605537094de32575ea53b4d8793b53c4a2944f62

actuals:
  tokens: 24000
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Livewire temporary upload then a page method that stores and always deletes the temporary original in finally"
    - "object first, then row; a failed row write removes the object again and surfaces a content-free exception"

key-files:
  created:
    - app/Domain/Knowledge/Actions/StoreArticleImage.php
    - app/Domain/Knowledge/Actions/StoredImage.php
    - app/Domain/Knowledge/Images/ImageNames.php
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/HandlesImageUploads.php
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/ImageUploadForm.php
    - tests/Feature/Knowledge/StoreArticleImageTest.php
    - tests/Feature/Knowledge/ImageUploadTest.php
    - tests/Unit/Knowledge/ImageNamesTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php

key-decisions:
  - "A backslash is an ordinary character of the client name (only / separates a path); it is removed from the alt text with the other Markdown breakers"
  - "An invalid UTF-8 sequence in a client name becomes a question mark (mb_scrub), never an error"
  - "Livewire's own max:10240 upload rule refuses an oversized file before the method runs; the Action's size cap is the second line"

requirements-completed: [IMG-01, IMG-02]

duration: 40min
completed: 2026-11-27
---

# Phase 20 Plan 05: Editor image upload (server side) Summary

An Admin's PNG, JPEG, WebP or GIF uploaded on the create or edit screen is re-encoded, stored privately as `knowledge/images/<uuid>.<ext>` with a pending row, and the screen gets `![alt](kokpit-attachment:<uuid>)` to insert; every refusal answers its Czech message, stores nothing and the temporary original is always deleted.

## Accomplishments

- Task 1 (tracer): `StoreArticleImage` (R-20-05-2) with the admin-only gate, a size cap before reading, the pipeline, lowercase uuid7 id, object written before the row, and a `PDOException` path that deletes the object and throws `ArticleWriteFailed` with the SQLSTATE only (not chained, so the file name never reaches a log). `StoredImage`, `ImageNames`, the `HandlesImageUploads` trait with `storeImageUpload()` (not renderless, so the secret notice of 20-08 re-renders and stays), the `ImageUploadForm` interface, and both pages use them. The file name and client MIME type decide nothing (D-07).
- Task 2: refusal, failure and cleanup paths: the Action throws `ImageRefused` for SVG, non-image, animated and oversized files; the page maps `ImageRefused` to its Czech refusal, `ArticleWriteFailed` and `FilesystemException` to the Failed message after `report()`, lets `AuthorizationException` pass and deletes the temporary file in `finally`. `ImageNames::altText()` removes brackets, parentheses, backslashes, Cc and Cf characters, collapses whitespace, NFC-normalises and caps at 120; `fileName()` caps at 255 and falls back to `image.<ext>`.
- Tests: `ImageNamesTest` (11 tests, 27 assertions) passes in the worktree through a scratch bootstrap on the main checkout's vendor. `StoreArticleImageTest` and `ImageUploadTest` need the database and the application and are written but not run here. Pint is clean, `php -l` is clean on every touched file, `scripts/check-sensitive.sh` is clean, and the lefthook hook (sensitive-content, gitleaks) passed on both commits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] StoreArticleImage swap in the storage-failure test**
- **Found during:** Task 2 (test design)
- **Issue:** the plan asks for `StoreArticleImage` to be swapped in the container for one that throws `UnableToWriteFile`, but the Action is `final`, so it cannot be extended or mocked.
- **Fix:** the test replaces the default disk with a Mockery `Filesystem` whose `put()` throws `UnableToWriteFile` (`Storage::set`), after the temporary upload exists. The page path under test is the same (the Action lets the exception through, the page maps it).
- **Files modified:** tests/Feature/Knowledge/ImageUploadTest.php
- **Commit:** faacea83

**2. [Rule 1 - Bug] Backslash as a path separator**
- **Found during:** Task 2 (unit test of the hostile name `a](x)[b\c.png`)
- **Issue:** first draft treated a backslash as a separator, so the hostile name collapsed to `c` and the "backslash is removed" rule could never be observed.
- **Fix:** only `/` separates a path; a backslash stays in the name until the alt text removes it.
- **Files modified:** app/Domain/Knowledge/Images/ImageNames.php, tests/Unit/Knowledge/ImageNamesTest.php
- **Commit:** faacea83 and 6055370

The tests of the two tasks are split across the two commits by file (the page test file with the Task 1 source commit, the Action and name tests with the Task 2 commit), not by test case.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-20-15 to T-20-18 are mitigated as planned: the page mount and the Action gate refuse a Partner, `finally` deletes the temporary file, `ImageNames::altText()` makes the alt text inert, object-then-row with cleanup).

## Verification still to run after the merge (DDEV, HEAD branch)

- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/StoreArticleImageTest.php tests/Feature/Knowledge/ImageUploadTest.php tests/Unit/Knowledge/ImageNamesTest.php tests/Feature/Knowledge/CreateKnowledgeArticleTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php tests/Feature/Documents/UploadConfigTest.php` (one process, no `--parallel`), then `--testsuite=Audit` and `ddev exec vendor/bin/phpstan`.
- Points to watch in the DB-backed run: `Livewire::test(...)->set('imageUpload', UploadedFile::fake()->createWithContent(...))` goes through the real Livewire upload path (the `max:10240` rule applies); the Partner 403 test expects the `abort_unless` of `mount()` to surface as an `HttpExceptionInterface`; `Storage::set()` with a mocked disk in the storage-failure test.

## Self-Check: PASSED

- Created files exist and are committed (`git status` clean before this file).
- Commits faacea83 and 6055370 are on the branch (2 commits measured against the base 60d9f014).
- Acceptance greps: `function storeImageUpload` 1, `use HandlesImageUploads` 1 in each page, `authorize('create', KnowledgeArticleImage::class)` 1, `->delete()` in the trait at least 1, `screenshot-example` in the page test at least 1, `ImageRefusal::TooLarge` in the Action at least 1, `function altText` 1, `knowledge.images.errors.title` 1, `AuthorizationException` in the Action test at least 1, `FilesystemException` in the trait present.
