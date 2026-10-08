---
phase: 05-tasks-and-kanban
plan: 04
subsystem: tasks
tags: [filament, rich-text, html-sanitizer, activity-log, kanban-board, tags, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model, CreateTask, TaskBoard lock and nextPosition, TaskPeople, TaskResource with view page and quick creation (plans 05-01 to 05-03)
  - phase: 03-operations
    provides: LogsAllowlistedActivity, ActivityHistoryRelationManager, ActivityPresenter
provides:
  - UpdateTask Action (title, description, status, priority, dates, people, tags) under the board lock
  - TaskBoard::appendToColumn, the only legal writer of a status change
  - RichText, the strict sanitiser for task and comment HTML (clean on write, render on output)
  - Admin edit page /admin/tasks/KEY-N/edit with every TA-01 field; quick creation continues there
  - Allowlisted task activity log and an Admin-only task history tab
  - TaskInput, the validation shared by CreateTask and UpdateTask
affects: [05-05 subtasks, 05-07 billing keys on UpdateTask, 05-08 comments (reuse RichText), 05-10 and 05-11 boards (appendToColumn), 05-12 Partner task list, 05-15 notifications]

actuals:
  tokens: 14300
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Every status write goes through TaskBoard::appendToColumn under the board lock; other attributes are filled on the model first so one save writes one activity row"
    - "RichText owns its own HtmlSanitizer; Filament's global permissive config is never rebound"
    - "Form to Action mapping lives in two static mappers on the resource (fillData, actionData) so later plans add form keys in TaskResource only"
    - "Shared Action input rules in TaskInput (status, priority, description, day, dates order, tags), like ProjectInput"

key-files:
  created:
    - app/Domain/Shared/Text/RichText.php
    - app/Domain/Tasks/Actions/UpdateTask.php
    - app/Domain/Tasks/TaskInput.php
    - app/Filament/Resources/TaskResource/Pages/EditTask.php
    - app/Filament/RelationManagers/TaskHistoryRelationManager.php
    - tests/Feature/Tasks/TaskUpdateTest.php
    - tests/Feature/Tasks/RichTextSanitiserTest.php
  modified:
    - app/Domain/Tasks/Actions/CreateTask.php
    - app/Domain/Tasks/Board/TaskBoard.php
    - app/Domain/Tasks/Models/Task.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - app/Filament/Support/TaskColumns.php
    - lang/cs/kokpit.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Feature/Tasks/TaskResourceTest.php
    - tests/Feature/Operations/ActivityViewsTest.php

key-decisions:
  - "The description size limit is measured in bytes (RichText::MAX_LENGTH = 100000), the unit the Symfony sanitiser counts in and silently cuts at; RichText::clean refuses longer input so nothing is cut unnoticed (A8 adjusted from characters to bytes)"
  - "An unchanged assignee or requester is not re-checked against the allowed set, so a task keeps a person who was deactivated since; only a changed person must be allowed"
  - "A status change and the other edited attributes go out in one model save, so a combined edit writes one activity row"
  - "SpatieTagsInput is dehydrated so UpdateTask receives the tag names and stays the single writer; Filament's own relationship save then syncs the same names (idempotent)"
  - "Dates that are not calendar days are a field error (date_invalid) instead of a database exception"

patterns-established:
  - "Pattern: test payloads for XSS are assembled from fragments and carry marker words; assertions check the marker words, and the Livewire snapshot attribute is stripped before asserting on page HTML"
  - "Pattern: a security negative (no file stored) is proven non-vacuous by a mutation run with the protection switched off"

requirements-completed: [TA-01]

coverage:
  - id: D1
    description: "The Admin edits title, description, status, priority, dates, assignee, requester and tags on the edit page; the save goes through UpdateTask"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#changes the title and moves the task to the end of the new status column"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#saves the people chosen on the edit page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#stores tags entered on the edit page as task tags and shows them on the task page"
        status: pass
    human_judgment: false
  - id: D2
    description: "Status switches freely between all six values; every change appends the task to the end of the new column and sets or clears completed_at, with no position collision at commit"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#switches freely between all six statuses with no workflow"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#sets completed_at when the status becomes Done and clears it when it changes back"
        status: pass
    human_judgment: false
  - id: D3
    description: "The assignee and requester pickers offer exactly the active Admin and the client's active Partners, and a forged id is a field error from UpdateTask"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#offers exactly the active Admin and the active Partners of the project client as people"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#refuses a forged assignee of another client as a field error and changes nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "Descriptions are stored and rendered only as clean HTML; the editor offers no attachments and a forged upload stores no file"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/RichTextSanitiserTest.php#stores a description from UpdateTask without any dangerous part and keeps the benign words"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/RichTextSanitiserTest.php#renders raw html written straight into the column harmlessly on the task page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/RichTextSanitiserTest.php#stores no file when an upload is forged against the description editor"
        status: pass
    human_judgment: false
  - id: D5
    description: "Changes to identity, status, priority, dates, people and escalation are logged through the allowlist and shown in the Admin-only history tab; the description and positions are never logged"
    requirement: TA-01
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#shows a status change in the task history and never the description"
        status: pass
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php#lists the application models that log activity explicitly"
        status: pass
    human_judgment: false
  - id: D6
    description: "The edit form's description editor looks and behaves well for the Admin (toolbar, table editing, the Czech shared-description hint wording)"
    requirement: TA-01
    verification: []
    human_judgment: true
    rationale: "The editor is a browser component; tests cover its configuration and the saved result, not how it looks or feels, and the hint wording is a copy decision"

duration: about 35 min
completed: 2026-10-08
status: complete
plan_head_before: 4c3eb7ed87ece3eeebbb64706746455f5f865b11
plan_head_after: c0b80691c3b7f4a97b003487343ccecbcb244f32
---

# Phase 5 Plan 04: Task Edit Page, UpdateTask and Strict Rich Text Summary

**Admin task edit page backed by an UpdateTask Action that changes status only through the locked TaskBoard::appendToColumn, validates people, dates and tags, stores descriptions through a strict own-config Symfony HTML sanitiser, and writes an allowlisted, Admin-only history.**

## Performance

- **Duration:** about 35 min
- **Completed:** 2026-10-08
- **Tasks:** 3 (tracer plus two TDD expansions)
- **Files:** 17 in the code commits (7 created, 10 modified)

## Accomplishments

- `UpdateTask` runs in one transaction under the board lock with the task row locked. A changed status goes through `TaskBoard::appendToColumn` (end of the new column, `completed_at` set for Done and cleared otherwise, Done holds position 0); every other attribute is filled on the model first, so a combined edit writes one activity row. Proven with `SET CONSTRAINTS ALL IMMEDIATE` after a series of status switches.
- `EditTask` at `/admin/tasks/KEY-N/edit` with sections for title and description, status and priority, dates, people and tags. `TaskResource::fillData` and `actionData` are the only form-to-Action mappers. The quick creation modal now redirects to the edit page. `ViewTask` gained the edit header action.
- People pickers use `TaskPeople` (the active Admin and the active Partners of the project's client); a changed person that is outside the set is a field error from the Action, also for a forged Livewire payload and for a deactivated or unknown id.
- `RichText` (final, own `HtmlSanitizer`): safe elements only, no style, class, handlers, script, img, iframe or svg; links keep `href` for https, http and mailto only, with forced `rel` and `target`; empty editor output becomes null. `CreateTask` and `UpdateTask` clean the description (through `TaskInput::description`), over-limit text is the field error `description_too_long`; `TaskColumns` renders through `RichText::render`.
- The editor is a `RichEditor` with an explicit toolbar and `fileAttachments(false)`. A forged upload to the exposed editor method stores nothing; the test was mutation-checked (with attachments switched on it fails and finds the file on the public disk).
- `Task` logs an allowlist of identity, status, priority, dates, people and escalation columns; `TaskHistoryRelationManager` is Admin only and registered on the resource; Czech subject and attribute labels added.
- Task tags are synced as `TagType::Task` by both Actions; date order (`due_date` field error `dates_order`) and calendar-day validity are checked before any write.
- The Czech hint that a client-visible project shares the description with the client's accounts appears on the description field.

## Task Commits

1. **Task 1 (tracer): edit page, locked status change, history** - `910fd78` (feat)
2. **Task 2 (TDD): people pickers, tags, dates, hint, redirect**
   - RED `1b71f84` (test)
   - GREEN `45db58b` (feat)
3. **Task 3 (TDD): strict sanitiser, render, editor without attachments**
   - RED `7aecfb5` (test)
   - GREEN `c0b8069` (feat)

**Plan metadata:** the docs commit that carries this file.

## TDD Gate Compliance

RED then GREEN commits exist for both TDD tasks (`test(05-04)` precedes `feat(05-04)` in each). No REFACTOR commit was needed.

- Task 2 RED: 12 tests failed on the planned behaviour (missing form fields, no field error for forged people, tags not stored, no date check, no hint, redirect still to the view page). Semantic assessment: all failed because the feature was absent; none on a setup or syntax fault.
- Task 3 RED: 15 of 16 tests failed. Eight of them failed with `Class "App\Domain\Shared\Text\RichText" not found`, a missing-symbol failure of the planned class; the others failed on the planned assertions (for example `'<p></p>'` is not null, the description editor does not exist). One test passed in RED: the forged-upload test. That was vacuous at that point (the form had a plain Textarea and the call went to a wrong component key), so it was corrected in the GREEN commit to use the real component key and then mutation-checked as described above.
- One RED expectation of mine was wrong and corrected in GREEN: the sanitiser writes `@` in a mailto link as the character reference `&#64;`, which a browser reads back as the same address.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Shared input rules class `TaskInput`**
- **Found during:** Task 1
- **Issue:** `CreateTask` kept status and priority parsing private; `UpdateTask` needs the same rules, and Task 2 and 3 add dates, tags and description cleaning to both Actions. Duplicating them risks two different answers for the same bad value.
- **Fix:** New `app/Domain/Tasks/TaskInput.php` (outside `files_modified`), used by both Actions; the private parsers left `CreateTask`.
- **Files modified:** app/Domain/Tasks/TaskInput.php, app/Domain/Tasks/Actions/CreateTask.php
- **Commit:** 910fd78, extended in 45db58b and c0b8069

**2. [Rule 2 - Missing critical] Calendar-day validation for task dates**
- **Found during:** Task 2
- **Issue:** A date that is not a real day (or text) would reach PostgreSQL and surface as a database exception instead of a field error.
- **Fix:** `TaskInput::day` with the field error `kokpit.tasks.errors.date_invalid`, covered by a test.
- **Commit:** 45db58b

**3. [Rule 1 - Bug] Existing test used the alias `task` as an alias without translation**
- **Found during:** Task 3 full-suite run
- **Issue:** `tests/Feature/Operations/ActivityViewsTest.php` asserted that the subject alias `task` falls back to raw attribute names. The plan makes `task` a real subject, and the added Czech attribute labels for it (`activity.attributes.task.*`) correctly translate `title`.
- **Fix:** The test uses the unregistered alias `unlabelled` for the fallback assertion. One line, outside `files_modified`.
- **Commit:** c0b8069

**4. [Rule 3 - Blocking] Existing quick-create test expected the old redirect**
- **Found during:** Task 2 (planned behaviour, test file outside `files_modified`)
- **Fix:** `tests/Feature/Tasks/TaskResourceTest.php` now expects `/admin/tasks/ABC-1/edit` (part of the RED commit).
- **Commit:** 1b71f84

**5. [Rule 2 - Missing critical] Czech attribute labels for the task history**
- **Issue:** Without `activity.attributes.task.*` the history tab would show raw column names. The plan lists only the subject label.
- **Fix:** Labels for the eleven allowlisted attributes added to `lang/cs/kokpit.php`.
- **Commit:** 910fd78

**Plan interpretation (not a deviation):** the size limit is measured in bytes rather than characters (see key decisions); `fillData` also loads the tag names, `actionData` maps `assignee_id`, `requester_id` and `tags` as the artifact table says.

**Total deviations:** 5 auto-fixed (1 Rule 1, 3 Rule 2, 1 Rule 3). **Impact:** none on scope; `TaskInput.php` is one extra file and two existing test files received a minimal edit.

## Issues Encountered

- Filament 5 oddity, not our code: with `fileAttachments(false)` the exposed method `saveUploadedFileAttachmentAndGetUrl` refuses to store and then fails with a `TypeError` in the URL lookup (`Storage::has(null)`). A forged request therefore gets a server error and nothing is stored. The test accepts the `TypeError` and asserts that no file exists on any local disk. The component key of the editor for the upload call is `form.description`, not the state path.
- Right after a file write, a ddev Pest run twice read a stale copy of a file (bind-mount timing); re-running after a short wait gave the correct result.
- The full suite was green at the end (1691 passed, Pint and PHPStan clean). An earlier full run showed one failure, the `ActivityViewsTest` case from deviation 3.

## Known Stubs

None. The Partner-facing task views and comments that reuse `RichText` arrive in later plans.

## Threat Flags

None. The surfaces added here (edit route, history tab, editor upload endpoint) are covered by T-05-09, T-05-10 and T-05-11 of the plan's threat model, each with a test: stored XSS (payload tests on the stored value, the rendered page and a raw column write), forged upload (mutation-checked), and the audited history (allowlist test, Admin-only access test, Partner 403).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for 05-05: `TaskBoard::appendToColumn` and `UpdateTask` are the status writers the boards will reuse, `RichText` is ready for comments (`AddTaskComment`), and `TaskResource::actionData` is the single place where plan 05-07 adds its billing keys. Open question carried to the owner: the 100000 limit (A8) now counts bytes, which is about 90000 characters of Czech text.

## Self-Check: PASSED

- Created files exist: RichText.php, UpdateTask.php, TaskInput.php, EditTask.php, TaskHistoryRelationManager.php, TaskUpdateTest.php, RichTextSanitiserTest.php.
- Commits `910fd78`, `1b71f84`, `45db58b`, `7aecfb5`, `c0b8069` are ancestors of HEAD.
- Acceptance greps of all three tasks pass; the three task verify commands (targeted Pest, full Pest, Pint, PHPStan) pass.
