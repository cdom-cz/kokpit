---
phase: 09-documents-and-finance
plan: 12
subsystem: documents
tags: [documents, task-comments, partner-upload, filament, livewire, d-02, d-04, d-05, d-22, isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-02 AttachDocuments; 09-03 DocumentUrl; 09-07 DeleteDocument; 09-08 scoped Document model and Partner file tabs; 09-10 DocumentPolicy attach/delete grants for a comment and the Admin upload bell"
provides:
  - "A separate files field in the Admin and the Partner comment form; the comment and its files are saved in one DB transaction (AddTaskComment + AttachDocuments)"
  - "Files listed under each comment with download and preview buttons that mint the signed link at click time, never rendered into the page"
  - "downloadCommentDocument / previewCommentDocument and a confirmation action deleteCommentDocument, all restricted to the files of this task's comments (404 for anything else)"
  - "Phase 5 A10 markers replaced: the comment editors keep fileAttachments(false) by design (D-22) and name the separate upload field"
affects: [09-14 central listing (visibility toggle of a comment file), 09-20 unused copy check (documents.comments.* keys), 09-13 and later plans that show comment files]

actuals:
  tokens: 8800
  tasks: 3
  commits: 3
plan_head_before: 1ade7031e0055df9e57b108d68cca9794c28eba3
plan_head_after: b23161204aa7b20768c34c49c4f4b0a68d85e5b1
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A Livewire relation manager that is not a page hosts its own Filament action modals with <x-filament-actions::modals /> in the thread view; per-file buttons use wire:click=\"mountAction('name', { document: id })\""
    - "Component methods that take a client-controlled id re-read through the scoped query restricted to the owner's own comments (UUID check first), so a foreign id is indistinguishable from an unknown one (404)"
    - "A refused Livewire call (abort 404/403) ends the round trip in tests, so each forged call gets its own component"

key-files:
  created:
    - tests/Feature/Documents/CommentAttachmentsTest.php
  modified:
    - app/Filament/Concerns/RendersTaskCommentThread.php
    - app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php
    - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
    - resources/views/filament/tasks/comment-thread.blade.php
    - lang/cs/documents.php
    - tests/Feature/Tasks/PartnerTaskCommentsTest.php

key-decisions:
  - "The delete is a Filament confirmation action (deleteCommentDocumentAction, name deleteCommentDocument) that calls DeleteDocument; there is no unconfirmed public delete method, so a forged call has to mount the action and DeleteDocument still checks the delete ability (403)"
  - "The thread view renders its own <x-filament-actions::modals /> outlet because the relation manager shows a schema, not a table, and so nothing else renders action modals"
  - "Admin hint on the files field combines files_hint and internal_files_hint; the Partner hint reuses documents.hints.partner_files"
  - "The delete button uses @can('delete', $document), the same policy the Action enforces, so the view and the server cannot drift"

patterns-established:
  - "Comment files come only through the scoped documents relation (eager-loaded, ordered), so a Partner's query never loads an internal comment or a hidden file"

requirements-completed: [DO-01]

coverage:
  - id: D1
    description: "The Admin writes a comment with files; the comment and its files are saved together or not at all, the file name is listed under the comment, the page holds no signed link and the download redirects to a signed URL minted at click time"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it saves the Admin comment with its file, lists the file under the comment and hands out a signed download"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it saves neither the comment nor the file when the media write fails"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner comments with files on an own task: comment not internal, file visible and uploaded by the Partner, one upload bell for the Admin"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it lets a Partner comment with a file: not internal, visible, uploaded by the Partner, one bell for the Admin"
        status: pass
    human_judgment: false
  - id: D3
    description: "A file of an internal Admin comment is stored hidden; the Partner thread shows neither the comment nor the file name and download/preview of its id answer 404; the Admin sees it with the internal badge"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it stores the file of an internal Admin comment hidden and shows a Partner neither the comment nor the file"
        status: pass
    human_judgment: false
  - id: D4
    description: "A forged document id (visible file of another task, of another client, a malformed id, a random UUID) answers 404 to a Partner for download, preview and delete; the lookup mutation without the task restriction made the test fail"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it answers 404 to a Partner for a visible file of another task, of another client and for a malformed id"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it answers 404 to a forged delete call with a file of another task or another client, and deletes nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "The Admin deletes a Partner's comment file after a confirmation naming the file (row, media and object gone, comment kept); a Partner deletes only an own file of an open task, the button is absent for the Admin's file, another Partner's file and once the task is done, and a forged call is refused with 403"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it lets the Admin delete a comment file of a Partner after the confirmation, object included, and keeps the comment"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it lets a Partner delete only an own comment file of an open task, with the button, and refuses a forged call"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it takes the delete button away from the Partner once the task is done, and keeps it for the Admin"
        status: pass
      - kind: integration
        ref: "mutation run: replacing the @can('delete') guard with true made three tests fail"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Partner escalation and description editors still offer no file field and the A10 markers point to the comments tab"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/CommentAttachmentsTest.php#it keeps the file fields off the escalation and description editors of the Partner task page"
        status: pass
    human_judgment: false
  - id: D7
    description: "In the real panel: the look and the browser-to-bucket upload of the comment file field, the file rows under a comment, the preview in a new view and the wording of the delete dialog"
    requirement: DO-01
    verification: []
    human_judgment: true
    rationale: "Tests drive Livewire with one upload per file on the faked temporary disk and check state and rendered strings; no browser test exists, so the field look, the presigned upload path and the dialog wording need a person using an Admin and a Partner session."

# Metrics
duration: 14min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 12: Comment Attachments Summary

**Files on task comments for the Admin and the Partner: a separate upload field saved with the comment in one transaction, files listed under each comment with click-time signed download and preview, a confirmed delete under the existing policy, and every id from the browser re-read through a lookup limited to this task's comments**

## Performance

- **Duration:** 14 min
- **Started:** 2026-10-16T14:35:20Z
- **Completed:** 2026-10-16T14:49:26Z (code commits; SUMMARY follows)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 8 (1 created, 7 modified)

## Accomplishments

- `RendersTaskCommentThread::addComment()` reads the `files` field (only `TemporaryUploadedFile` instances) and wraps `AddTaskComment` and `AttachDocuments` in one `DB::transaction()`; a forced media failure leaves no comment, no document and no media row. A refused attach (`AuthorizationException`) becomes a field error under `commentData.files`.
- The Admin and the Partner comment forms get `FileUpload::make('files')` (multiple, `storeFiles(false)`, Admin limits). The rich editors keep `fileAttachments(false)` (D-22); the Phase 5 A10 comments in both managers and in `ViewPartnerTask` now name the separate field or the comments tab.
- The thread eager-loads `documents` through the scoped relation and lists name, size and buttons per file. The page never carries a signed URL (asserted); `downloadCommentDocument` and `previewCommentDocument` check the UUID, re-read the document through the scoped query limited to this task's comments, authorize `view` and redirect to `DocumentUrl`.
- Internal-comment files are stored hidden by `AttachDocuments`; the Partner thread (scoped comments) contains neither the comment nor the file, and their ids answer 404.
- `deleteCommentDocument` is a Filament confirmation action that calls `DeleteDocument`; the delete button appears only under `@can('delete', $document)`, so a Partner sees it only for an own file of an open task and the Admin for every file. The comment stays.

## Task Commits

1. **Task 1: Tracer - the Admin writes a comment with a file, sees it under the comment and downloads it** - `e6fbdc1` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests, the task comment tests, Pint and PHPStan were re-run end to end (green) before expansion.
2. **Task 2: Partner comments carry files, internal-comment files never reach a Partner, and forged ids are refused** - `c149590` (feat, TDD; the shared lookup written in Task 1 already satisfied the 404 cases once the Partner field existed, a mutation run without the task restriction made the forged-id test fail)
3. **Task 3: Delete a comment file with confirmation** - `b231612` (feat, TDD)

**Plan metadata:** the docs commits that follow this file.

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An existing test pinned the Partner comment form to the single field `body`**
- **Found during:** Task 2
- **Issue:** `tests/Feature/Tasks/PartnerTaskCommentsTest.php` asserted `['body']` as the only field of the Partner form; the form now rightly has `body` and `files`.
- **Fix:** The expectation is `['body', 'files']` with a comment (still no internal switch).
- **Files modified:** `tests/Feature/Tasks/PartnerTaskCommentsTest.php` (not in the plan's `files_modified`)
- **Verification:** `tests/Feature/Tasks` (382 passed)
- **Commit:** c149590

**Total deviations:** 1 auto-fixed (1 bug-in-test-expectation). **Impact:** none on behavior.

### Plan interpretation (not a deviation)

- The `documents.comments.*` keys, including the delete dialog copy, were added together in Task 1 because one translation file edit covered the whole artifact table; Task 3 only uses them.
- The plan names public `deleteCommentDocument(string $documentId)` behind a confirmation action. It is implemented as the action `deleteCommentDocument` taking the id as an argument (no separate unconfirmed method), which is the stricter reading; a forged call must mount the action and is still refused by `DeleteDocument`.
- The modal itself cannot be seen in `html()` of a Livewire test (Filament renders it as a partial effect), so the dialog is asserted with `assertMountedActionModalSee` and the mounted action's heading, description and confirmation flag.

## Authentication Gates

None.

## Issues Encountered

- A Livewire component that answered 404/403 cannot take another call in the same test (the snapshot is gone), so forged-id tests create a thread per call.
- The full suite exceeds the foreground limit; it was run in the background.

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-42 (files hidden for internal comments, Partner thread built from the scoped relation, task-restricted lookup, 404 tests), T-09-43 (UUID check, scoped lookup restricted to this task, `view` and `delete` policy, forged-call tests for download, preview and delete) and T-09-44 (one transaction around both Actions, forced-failure test) of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4312 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- All task acceptance criteria re-run: pass (greps for `AttachDocuments`, `DB::transaction`, `downloadCommentDocument`, `FileUpload::make('files')` in both managers, `DeleteDocument`, `requiresConfirmation`; `(A10)` count 0 in `ViewPartnerTask.php`)

## Next Phase Readiness

Ready for the next plan of wave 6. Plan 09-14 can add the central listing toggle for a comment file's visibility on top of `SetDocumentVisibility`. One open item: a human look at the comment upload field, the file rows, the preview and the delete dialog in the real panel (coverage D7).

## Self-Check: PASSED

- Files exist: `RendersTaskCommentThread.php`, `comment-thread.blade.php`, `CommentAttachmentsTest.php` (found)
- Commits `e6fbdc1`, `c149590`, `b231612` are ancestors of HEAD
- `commits: 3` measured from the plan ledger (`1ade703..b231612`)
