---
phase: 09-documents-and-finance
plan: 10
subsystem: documents
tags: [documents, partner-upload, policy, d-05, notification, filament, isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-02 AttachDocuments; 09-07 DeleteDocument and DocumentActions::delete; 09-08 DocumentPolicy::view, ShowsPartnerDocuments and the Partner Soubory tabs"
provides:
  - "DocumentPolicy::attach, update and delete: the explicit Partner grants of D-05 (own open task or own non-internal comment; no rename or visibility change; own upload on an open task)"
  - "AttachDocuments Partner rules: flag forced visible, caller flag ignored, one Admin bell per submission inside the upload transaction"
  - "DocumentNotifier::partnerUploaded and PartnerUploadedFilesNotification (database only, Partner name + task reference + file count, never a file name)"
  - "files upload field on the Partner create-task form; CreatePartnerTask creates the task and its files in one DB transaction"
  - "Delete action on the Partner task Soubory tab (confirmation, shown only where the policy grants it)"
affects: [09-12 comment attachments reuse the attach grant and the bell, 09-14 central listing, 09-20 unused copy check]

actuals:
  tokens: 13400
  tasks: 3
  commits: 3
plan_head_before: 271c5211019a870b239e92befaffaef4fb2b40dc
plan_head_after: ad18e75b1e3a910e56fce5005587911843dc7656
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Partner write grants repeat the scope: every readability check re-reads the record through the Partner-scoped Task/TaskComment query, so another client's or an archived owner never counts"
    - "Host hook extraRecordActions() on the ShowsPartnerDocuments trait: a final host class adds per-file actions without a second copy of the table"
    - "Non-queued database notification sent with notifyNow() inside the upload transaction, so a rolled-back upload sends nothing"

key-files:
  created:
    - app/Domain/Documents/Notifications/DocumentNotifier.php
    - app/Domain/Documents/Notifications/PartnerUploadedFilesNotification.php
    - tests/Feature/Documents/PartnerUploadTest.php
  modified:
    - app/Domain/Documents/Policies/DocumentPolicy.php
    - app/Domain/Documents/Actions/AttachDocuments.php
    - app/Filament/Partner/Resources/PartnerTaskResource.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php
    - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskDocumentsRelationManager.php
    - app/Filament/Concerns/ShowsPartnerDocuments.php
    - lang/cs/documents.php
    - tests/Isolation/NotificationLeakTest.php

key-decisions:
  - "A task is open when it is readable through the scoped Task query, not archived and not done (D-19); a comment follows its task"
  - "The attach grant for a Partner is: Task with requester_id = user, or non-internal TaskComment with author_id = user, both re-read through the scoped query; a project, a client, no owner, a closed or archived task and anything of another person or client is refused with AuthorizationException"
  - "AttachDocuments decides the Partner flag itself (always visible) and sends the bell inside the same transaction, so the policy, the flag and the bell cannot drift apart between callers"
  - "CreatePartnerTask hands on only TemporaryUploadedFile instances of the files field and converts an AuthorizationException of the attach into a field error under files with documents.errors.partner_not_allowed"
  - "The task tab's delete action is added through a protected extraRecordActions() hook on the shared trait; the project tab keeps preview and download only"

patterns-established:
  - "Refusal matrix collects the owners that were NOT refused by name and asserts an empty list, so a failure names the owner"

requirements-completed: [DO-01, DO-03]

coverage:
  - id: D1
    description: "A Partner attaches files while raising a task in a visible project of the own client; the task and its files are created together or not at all, the files are visible to the Partner at once"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it lets a Partner raise a task with two files, shows them on the task tab and tells the Admin once"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it creates neither the task nor a file nor a bell when the second file cannot be stored"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner can attach only to an own open task or an own non-internal comment; another person's task or comment, a project, a client, no owner, an internal comment, another client's task, a hidden project's task, a done and an archived task are refused; a forged visibility value is ignored"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it refuses a Partner every owner D-05 does not allow, stores nothing and sends no bell (12 owners)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it stores a Partner file on the own open task visible even when the Partner asks for hidden"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it stores a Partner file on the own non-internal comment visible and names the task in the bell"
        status: pass
      - kind: integration
        ref: "mutation runs on DocumentPolicy::attach: without the requester check, without the open-task check and without the done check each made a test fail"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner can neither rename a file nor change its visibility; upload limits are the Admin's"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it refuses the uploading Partner a rename and a visibility change"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it applies the size limit to a Partner exactly as to the Admin"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner deletes an own file from the task's Soubory tab after confirmation while the task is open, the object removed although the Partner cannot read media; the action is hidden and a forged call refused for the Admin's file, another Partner's file and any file of a done or archived task; the Admin may still delete"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it lets a Partner delete an own file on an open task after the confirmation, object included"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it offers a Partner the delete only for the own file of an open task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php#it takes the delete away from the Partner once the task is done or archived, and keeps it for the Admin"
        status: pass
      - kind: integration
        ref: "mutation runs: without the uploader check, without the open-task check and without the action visibility each made a test fail"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every Partner upload submission sends each active Admin one bell (no e-mail) naming the Partner, the task reference and the file count, never a file name; a Partner never receives it"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationLeakTest.php#it tells only the Admin of a Partner upload, with the count and never a file name"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/PartnerUploadTest.php (bell asserted in the create-task, own-task and comment cases)"
        status: pass
    human_judgment: false
  - id: D6
    description: "In the real panel a Partner picks files on the create-task form (presigned upload to the bucket), submits, and sees them on the new task; the Admin sees the bell entry; the confirmation dialog of the delete reads well"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests drive Livewire with one upload per file on the faked temporary disk; no browser test exists"
        status: pass
    human_judgment: true
    rationale: "The browser-to-bucket upload path, the look of the file field on the create form and the wording of the bell and the delete dialog are not asserted by any test; only a person using a Partner and an Admin session can judge them."

# Metrics
duration: 50min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 10: Partner Upload and Delete Summary

**Partners hand over files while raising a task (task and files in one transaction, always visible) and take back their own files while the task is open, under explicit DocumentPolicy attach/update/delete grants, with one Admin bell per submission that carries the count and the task reference but never a file name**

## Performance

- **Duration:** about 50 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T14:02Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 11 (3 created, 8 modified)

## Accomplishments

- `DocumentPolicy::attach` grants a Partner only a `Task` they requested or a non-internal `TaskComment` they wrote, both re-read through the Partner-scoped query and on a task that is not archived and not done. `update` is false for every Partner (no rename, no visibility switch). `delete` requires the Partner to be the uploader, `view` to hold, and the owning task (or the comment's task) to be open. The Admin passes through `before()` and may delete anything.
- `AttachDocuments` stores every Partner file visible whatever the caller passes, and after the writes (inside the same transaction) calls `DocumentNotifier::partnerUploaded`, which sends a non-queued database `PartnerUploadedFilesNotification` with `notifyNow()` to every active Admin. The body is `trans_choice` over the count with the Partner name and reference escaped once; the link goes to `/admin/tasks/KEY-N`. A rolled-back upload sends nothing.
- The Partner create-task form has a `files` field (`multiple`, `storeFiles(false)`, the Admin's limits, helper `documents.hints.partner_files`); `CreatePartnerTask::handleRecordCreation()` filters the state to `TemporaryUploadedFile` instances and wraps `CreateTask` and `AttachDocuments` in one `DB::transaction()`. A forced failure of the second media write leaves no task, no document, no media row and no bell.
- The Partner task Soubory tab gets the shared `DocumentActions::delete()` (confirmation naming the file), visible only when `Gate::allows('delete', $record)`; the action runs `DeleteDocument`, which authorizes again and deletes the object as system, so the Partner's inability to read media does not leave an object behind.
- `NotificationLeakTest` gets a case: a Partner of client A uploads files whose names carry a canary; the Admin holds exactly one bell with the count and the reference, no Partner holds that notification type, no row of anybody holds a file name, and the Partner's own bell is free of it.

## Task Commits

1. **Task 1: Tracer - a Partner creates a task with two files, sees them on the Soubory tab, the Admin gets one bell** - `50ad6a7` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests, the Partner task tests, Pint and PHPStan were re-run end to end (green) before expansion.
2. **Task 2: Every place a Partner must not upload to is refused** - `134391c` (test, TDD; the grants written in Task 1 already satisfied the cases, the mutation runs below prove the tests bite)
3. **Task 3: A Partner deletes an own file while the task is open, no Partner bell** - `ad18e75` (feat, TDD)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A hook on a shared trait outside the plan's file list**
- **Found during:** Task 3
- **Issue:** The task tab and the project tab share `ShowsPartnerDocuments`, which fixes the record actions to preview and download. The delete action must appear on the task tab only, and the plan lists no change to the trait.
- **Fix:** A protected `extraRecordActions()` hook (empty by default) in the trait; `PartnerTaskDocumentsRelationManager` defines it with the delete action. The project tab is unchanged.
- **Files modified:** `app/Filament/Concerns/ShowsPartnerDocuments.php` (not in the plan's `files_modified`)
- **Commit:** ad18e75

**2. [Rule 1 - Bug] The `#[AccessRule]` reason of the task tab said it offers no write action**
- **Found during:** Task 3
- **Issue:** The declared reason became false once the tab offers the Partner's delete.
- **Fix:** The reason now names the one write action and the policy that grants it.
- **Files modified:** `PartnerTaskDocumentsRelationManager.php`
- **Commit:** ad18e75

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug). **Impact:** none on behavior.

### Plan interpretation (not a deviation)

- Task 1 already carries all three policy grants (`attach`, `update`, `delete`) because the bell, the tab and the refusal cases depend on one coherent policy; Task 2 and Task 3 therefore added tests (and the Task 3 UI action) on top of finished rules, and the "RED" step of those tasks was verified by mutation runs of the policy and the action visibility instead of by a failing first run (all mutations made a test fail; one redundant layer is noted below).
- The internal-comment refusal is enforced by three layers (instance flag, re-read flag, the scoped `TaskComment` query that already drops internal comments for a Partner); removing the first two still keeps the case refused through the scope, whose mutation self-check lives in `DocumentVisibilityTest` (09-08).
- `documents.errors.partner_not_allowed` is used by `CreatePartnerTask` as a field error under `files` if the attach is ever refused for the Partner's own new task (a defensive path; the requester is the Partner by construction).
- `Media::creating` hooks in tests must be registered on `App\Domain\Shared\Models\Media`, not the package base class, or the listener never fires.

## Authentication Gates

None.

## Issues Encountered

- The full-suite command exceeds the foreground time limit; it was run in the background and finished green (4257 passed, 3 skipped, pre-existing, 0 failed).

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-33 (explicit attach grant, 12-owner refusal matrix, policy mutation runs), T-09-34 (flag forced visible, `update` has no Partner grant), T-09-35 (delete grant only for own uploads on an open task, action hidden, forged call refused, delete runs as system after the policy), T-09-36 (bell carries the count, Partner name and reference only, escaped once, Admin recipients only, NotificationLeakTest case) and T-09-37 (same limits as the Admin) of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4257 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- All task acceptance criteria re-run: pass

## Next Phase Readiness

Ready for the next plan of wave 5. Plan 09-12 (comment attachments) reuses `DocumentPolicy::attach` for a `TaskComment` owner, the always-visible Partner flag and the same `DocumentNotifier` bell (the comment owner already resolves to its task). One open item: a human look at the Partner create-task upload, the bell entry and the delete dialog in the real panel (coverage D6).

## Self-Check: PASSED

- Files exist: `DocumentNotifier.php`, `PartnerUploadedFilesNotification.php`, `PartnerUploadTest.php` (found)
- Commits `50ad6a7`, `134391c`, `ad18e75` are ancestors of HEAD
- `commits: 3` measured from the plan ledger (`271c521..ad18e75`)
