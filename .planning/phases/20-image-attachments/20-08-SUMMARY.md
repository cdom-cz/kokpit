---
phase: 20-image-attachments
plan: 08
subsystem: knowledge
tags: [filament, livewire, codemirror, images, preview, versions]
status: complete

requires:
  - phase: 20-image-attachments
    provides: ImageContext, ImageReferences and the Czech copy (20-04), HandlesImageUploads and ImageUploadForm (20-05), the authorised image route (20-07)
provides:
  - "ArticleImages::forBody(body, ?articleId, ?pendingOwnerId): ImageContext in one query"
  - "image context on the editor preview, the view screen and the version view"
  - "insert-image button, paste and drop, serial upload queue, status line and notifications in the editor script"
  - "secret notice above the sensitive toggle, data-kokpit-image* attributes on the body field"
affects: [20-09, 20-10, 20-11]

plan_head_before: 4b46ec6c85132cf00b70b54dbdcc0bb216f316d1
plan_head_after: 3ba0e6f8aa268c116fe5ce3fd72efe5965e62158
commits: 3

actuals:
  tokens: 40000
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "image context built outside the renderer from one whereIn query constrained to the rendered article"
    - "editor script gets every text and limit from data attributes and keeps per-CodeMirror-instance flags"

key-files:
  created:
    - app/Domain/Knowledge/Queries/ArticleImages.php
    - tests/Feature/Knowledge/KnowledgeImageScreensTest.php
    - tests/Feature/Knowledge/ImageEditorWiringTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewArticleVersion.php
    - resources/js/knowledge-editor.js

key-decisions:
  - "Followed rulings R-20-08-1 to R-20-08-4 verbatim."
  - "Several files of one batch are inserted one after another: the next reference goes to the end of the previous one while the Admin has not touched the selection that insertion left (otherwise at the cursor)."
  - "A paste takes only image-type files; a drop takes every dropped file so that an SVG or other file reaches the server and gets its Czech refusal."

requirements-completed: [IMG-01, IMG-02, IMG-03]

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 08: Images on the screens and in the editor Summary

An image uploaded on the create screen shows inline in the preview of the unsaved article, the view screen and the version view show the article's images through the authorised route (everything else as the muted alt text), and the editor has the "Vlozit obrazek" button, paste, drop, a serial upload queue with status line and notifications, and the secret notice next to the sensitive toggle.

## Accomplishments

- Task 1 (tracer): `ArticleImages::forBody()` (R-20-08-1): one `whereIn` query constrained to the article id, plus the actor's own pending rows (`article_id IS NULL AND uploaded_by`) only when the preview passes `$pendingOwnerId`; an empty reference list or both ids null is `ImageContext::none()` without a query; every src is `KnowledgeAttachmentController::path()`. `previewHtml(mixed, ?string $articleId = null)` passes it on the normalised branch only (the over-limit branch stays plain); the preview `Html` component passes the record id (null on the create screen) and the actor id comes from `auth()->id()`.
- Task 2: `infolist()` and `ViewArticleVersion::getViewData()` pass `forBody($body, $articleId)`; Feature tests cover the view screen, the edit preview (own bound image, own pending image, a foreign article's and another Admin's pending image as the missing span), the version view while only an earlier version references the image and after its deletion, restore with the image present and deleted (all notifications success), a fictional bucket host, presigned query and livewire-tmp absent from every page, and the Partner 403.
- Task 3: the body field keeps `fileAttachments(false)` and its toolbar and gains the eight `data-kokpit-image*` attributes; a warning `Callout` with `role=status` sits directly above the sensitive toggle and shows only while `ImageUploadForm::showsImageNotice()`. `knowledge-editor.js` gains the `kokpit-insert-image` button after the table button (photo icon as a CSS mask), a hidden file input, the `kokpit-image-status` line with a spinner, paste and drop handlers bound once per CodeMirror instance (flag on the instance, with a short retry until Filament has assigned `_editor`), the strictly serial queue (`wire.upload('imageUpload', ...)` then `storeImageUpload()`), insertion inside one `operation()` with the alt part selected, danger notifications for refusals, upload errors and files over the size limit, and submit buttons disabled while the queue runs and re-applied after every Livewire commit. The script is ASCII only, uses createElement and textContent, and the existing sink test keeps covering it.

## Deviations from Plan

None - plan executed as written. Small implementation choices inside the plan's freedom: the batch insertion point rule and the paste/drop file filters listed under key-decisions.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites, no `vendor/` here): `php -l` clean on every touched PHP file, Pint (main checkout's binary) passes, `node --check resources/js/knowledge-editor.js` passes, the acceptance greps hold (`fileAttachments(false)` 1, `data-kokpit-image` 9, `knowledge.images.notice` 1, `on('paste'` 1, `on('drop'` 1, `ArticleImages` 3 in the resource and 2 in the version page, `bucket.example.com` 3 in the screens test), no forbidden sink and no non-ASCII byte in the script, `scripts/check-sensitive.sh` clean and lefthook (sensitive-content, gitleaks) passed on all three commits. PHPStan could not run here (no `vendor/larastan`).

**Not run here, to run after the merge of wave 3 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php artisan filament:assets` (the script changed)
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/KnowledgeImageScreensTest.php tests/Feature/Knowledge/ImageEditorWiringTest.php tests/Feature/Knowledge/CreateKnowledgeArticleTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php tests/Feature/Knowledge/ArticleVersionsPageTest.php tests/Feature/Knowledge/VersionRestoreModalTest.php`, then `tests/Feature/Knowledge tests/Unit/Knowledge`, `--testsuite=Audit` and PHPStan.
3. The browser check of Task 3 (human-check in the plan, assumption A4): button, paste, drop of two files with one SVG, plain text paste, preview, diff row wrap, 120-character name, transparent PNG in dark mode.

Points worth watching in the DB-backed run: (a) the `Html::make` closure injection of `?Model $record` in the preview component; (b) `->callMountedAction()->assertHasNoActionErrors()` and the notification statuses in the deleted-image restore test; (c) deleting an image row and its reference rows through model queries inside `runAsSystem` in the screens test helper (no trigger is expected to forbid it); (d) in the browser, whether `root._editor` is assigned before the first scan (the script retries up to 30 times at 100 ms) and whether `Livewire.hook('commit', ...)` receives `succeed` in the installed Livewire version.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register: T-20-28 (the query is constrained to the rendered article and the actor's own pending rows, tests with a foreign image and another Admin's pending image), T-20-29 (every src is the route path, page-level test against a fictional bucket host), T-20-30 (createElement and textContent only, no string evaluation, sink test covers the file).

## Self-Check: PASSED

- Created files exist and are committed: ArticleImages.php, KnowledgeImageScreensTest.php, ImageEditorWiringTest.php.
- Commits 266a1c25, 767cc7e3 and 3ba0e6f8 are on the branch (3 commits measured against the base 4b46ec6c).
- STATE.md and ROADMAP.md were not touched.
