---
phase: 17-task-project-and-search-ux-refinements
plan: 02
subsystem: documents
tags: [filament, lightbox, signed-url, partner-isolation, documents]
status: complete

requires:
  - phase: 09-documents
    provides: DocumentUrl signed links, DocumentFileController, DocumentActions, RendersTaskCommentThread
provides:
  - "DocumentDisposition::isInlineImage(): PNG/JPEG/GIF/WebP subset of the inline allowlist"
  - "DocumentActions::preview(): single-image lightbox for images, target _blank link for PDF"
  - "DocumentActions::lightboxModal/lightboxHeading/lightboxView: shared lightbox building blocks"
  - "RendersTaskCommentThread::previewCommentImageAction() and the previewUrls view variable"
  - "resources/views/filament/documents/lightbox.blade.php with loading and error states"
affects: [17-08 (rewrites DocumentUrl docblock and the CONTRIBUTING Documents bullet)]

tech-stack:
  added: []
  patterns:
    - "Filament modal with modalContent view as a lightbox, link minted at render, bound to the viewer"
    - "Action::url(closure, shouldOpenInNewTab: true) for a real target _blank link"

key-files:
  created:
    - resources/views/filament/documents/lightbox.blade.php
  modified:
    - app/Domain/Documents/Support/DocumentDisposition.php
    - app/Filament/Support/DocumentActions.php
    - app/Filament/Concerns/RendersTaskCommentThread.php
    - resources/views/filament/tasks/comment-thread.blade.php
    - lang/cs/documents.php
    - tests/Feature/Documents/DocumentsRelationManagerTest.php
    - tests/Feature/Documents/DocumentResourceTest.php
    - tests/Feature/Documents/CommentAttachmentsTest.php
    - tests/Isolation/DocumentVisibilityTest.php

key-decisions:
  - "Image link exists only inside the open lightbox (minted in modalContent after Gate view and isInlineImage); image rows have a null URL"
  - "PDF 'Zobrazit' is a real signed link with target _blank, minted when the row renders (owner decision 'Signed PDF link exception'); the 403 of a page left open past 120 s is accepted"
  - "Lightbox building blocks are public static helpers on DocumentActions so the comment thread reuses them instead of duplicating the modal configuration"
  - "The thread's heading and content closures both go through one private previewableImage() that re-reads the document (task-scoped, 404), authorizes view and requires an inline image"

patterns-established:
  - "One shared lightbox frame (5xl, no submit, 'Zavřít') used by the Soubory lists and the comment thread"

requirements-completed: [P17-08]

commits: 3
plan_head_before: 7430d924fe5b099a45286a969d3480e362167b50
plan_head_after: 4ea5a928ff92941eb8f767488e62d4d9fa608acf

actuals:
  tokens: 40000
  tasks: 3
  commits: 3

metrics:
  completed: 2026-10-29
---

# Phase 17 Plan 02: Image lightbox and new-tab PDF preview Summary

"Zobrazit" opens PNG/JPEG/GIF/WebP in a single-image Filament lightbox and a PDF in a new browser tab, in every file list of both panels and under task comments, with the signed image link minted only when the lightbox renders.

## Tasks

| # | Task | Commit |
|---|------|--------|
| 1 | Tracer: image lightbox in the project Soubory tab (predicate, modal, view, copy, test) | 60785c0 |
| 2 | PDF in a new tab, tests for the Admin lists and the Partner tab | 43effb8 |
| 3 | Comment thread: previewCommentImage action, previewUrls, blade, ported 404 cases | 4ea5a92 |

## What was built

- `DocumentDisposition::isInlineImage()` (R-17-02-1).
- `DocumentActions::preview()`: for an image row the URL is null and the click opens a 5xl modal headed by the (ellipsized) file name, no submit, one gray "Zavřít"; the content closure authorizes `view`, requires an inline image and only then mints `DocumentUrl::preview()` for the signed-in user. For a PDF the action is a real `<a target="_blank">` whose URL is minted when the row renders. The class docblock now names this as the single exception to the click-time rule.
- `lightbox.blade.php`: Alpine `loaded`/`failed` state, centred loading indicator, image limited to 80vh, `preview_failed` message when the image errors or expires while open; cached images settle via `complete`/`naturalWidth` in `x-init`.
- `RendersTaskCommentThread`: `previewCommentImageAction()` (shared modal frame), `previewUrls` view variable for PDFs the actor may `view`, blade button for images (mounts the action) and `target="_blank"` link for PDFs; the old redirecting `previewCommentDocument()` is removed.
- Czech copy: `documents.actions.close`, `documents.errors.preview_failed`.
- Tests: Admin project tab (PNG lightbox minted for the Admin, PDF URL/new tab, one `signature=` per tab), central list, Partner project tab (link bound to the Partner, hidden twin has no row/link, lightbox minted for the Partner), comment thread for both panels (PNG, PDF, TXT, internal and hidden files, forged ids and non-images answer 404).

## Deviations from Plan

None in behaviour. Process notes:

- The PDF branch of `preview()` and the class docblock rewrite were written in the same edit as the image branch, so they landed in the Task 1 commit; the Task 2 commit carries only its tests. Content is as planned.
- Shared lightbox pieces (`lightboxModal`, `lightboxHeading`, `lightboxView`) were made public static helpers on `DocumentActions` (the plan said "share it"); `previewableImage()` private helper added to the thread.

## Verification status

Worktree only: `php -l` clean on all touched PHP files, blade greps (`preview_failed`, `max-height:80vh`, `previewCommentImage`, `target="_blank"`) pass, `scripts/check-sensitive.sh` clean on every commit, commit hooks (sensitive-content, gitleaks) passed. The worktree has no `vendor/`, so Pest, Pint and PHPStan were NOT run (per CLAUDE.md the orchestrator runs them after the merge; the plan gate command is in 17-02-PLAN.md `<verification>`; runtime not yet measured).

Points to watch at first Pest run:
- `assertNotFound()` after `mountAction('previewCommentImage', ...)` relies on the 404 raised while the modal renders (heading/content closures), not in an action callback.
- `$mounted?->getModalSubmitAction()` is asserted falsy (false/null) for `modalSubmitAction(false)`.
- Pint was not run; multi-line `if` in `previewUrls()` may need a reformat.

## Open human checks (from the plan)

- PDF opens in a new tab in Chrome/Safari/Firefox, 403 after >2 min on a stale page (accepted), opens again after reload.
- Lightbox visual check in light/dark theme, long file names at 320px and 1280px, ESC/X/backdrop/"Zavřít".

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-17-04 accepted by owner decision, T-17-05/06/07 mitigated and covered by tests).

## Self-Check: PASSED

- 60785c0, 43effb8, 4ea5a92 are ancestors of HEAD; lightbox.blade.php exists.
