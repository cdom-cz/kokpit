---
phase: 17-task-project-and-search-ux-refinements
verified: 2026-10-29T14:00:00Z
status: passed
score: 10/10 must-haves verified
covered_files:
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-01-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-01-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-02-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-02-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-03-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-03-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-04-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-04-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-05-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-05-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-06-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-06-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-07-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-07-SUMMARY.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-08-PLAN.md"
  - ".planning/phases/17-task-project-and-search-ux-refinements/17-08-SUMMARY.md"
  - "app/Domain/Signal/Actions/AddSignalTask.php"
  - "app/Domain/Signal/Queries/LinkableTasks.php"
  - "app/Domain/Tasks/Board/TaskBoard.php"
  - "app/Filament/Partner/Resources/PartnerTaskResource.php"
  - "app/Filament/Resources/ProjectResource.php"
  - "app/Filament/Resources/ProjectResource/Pages/ViewProject.php"
  - "app/Filament/Resources/TaskResource.php"
  - "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
  - "app/Filament/Resources/TimeEntryResource.php"
  - "app/Filament/Support/DocumentActions.php"
  - "app/Filament/Support/TaskColumns.php"
  - "app/Livewire/Signal/SignalDay.php"
covered_digest: "v4:sha256:0ed4c13d013234a6eff52758ba73fab6b91ae2c184939fed13676cea80cee2dc"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Open an image file from a file list (Admin: task, project and Soubory lists; Partner: task and project Soubory tab; images under a task comment) in Chrome, Firefox and Safari"
    expected: "A single-image lightbox opens (5xl modal, loading indicator, then the picture at most 80% of the window high, one Zavřít button, ESC and backdrop close it, no previous/next, zoom or thumbnails)"
    why_human: "Tests prove the modal and the minted link; the real image load, sizing and close behaviour are browser rendering"
  - test: "Click Zobrazit on a PDF row in each file list of both panels and under a comment, in each browser"
    expected: "The PDF opens in a new tab without a popup-blocker warning; a page left open past the link lifetime shows 403 in the new tab until the list re-renders (accepted, owner decision)"
    why_human: "target=_blank behaviour and popup blockers differ per browser"
  - test: "Read a task description, a comment, the Partner task page and the board-preview panel containing headings, lists, a quote and a wide table in light and dark theme"
    expected: "Formatting is visible (fi-prose), the table scrolls sideways in the preview, nothing overflows, contrast is acceptable in both themes"
    why_human: "Visual styling cannot be asserted by Pest"
  - test: "Use the Signal add field with the keyboard only (type 2+ characters, arrow keys, Enter to pick, Escape, the cross to clear) and with a screen reader if available"
    expected: "The combobox list opens and closes, the active option is announced, a pick fills `{PROJECT}: {task name} [{CODE}]` read-only with the link icon, the cross returns to free text focused in the field"
    why_human: "Keyboard and focus behaviour of the Alpine/Livewire combobox is browser-only"
  - test: "Open a project view and a task view as Admin and check the header actions"
    expected: "Project: Board, Nový úkol, Změnit stav, Upravit, Archivovat in a sensible order and colour; Nový úkol is absent for a Done or archived project. Task: Přidat čas next to the timer, with client/project/task shown disabled and the helper text"
    why_human: "Order, colour and visual hierarchy of header actions"
  - test: "Hide a task from the board with the checkbox on the edit form, then look at the global board, the project board, the task list and the global search"
    expected: "The card is gone from both boards only; it stays in the list, search and its own page; unchecking brings it back"
    why_human: "End-to-end browser pass of a flag the tests prove at the query level"
---

# Phase 17: Task, project and search UX refinements Verification Report

**Phase Goal:** A batch of UX refinements across tasks, projects, search, signals and files (full-width checklist card; rich text with formatting in read-only output; a task created from the project view; active tasks and projects first in search; time added from a task; a task hidden from the kanban board; Signal type-ahead pick of an unfinished task with snapshot text, link, timer control and open-task icon; image lightbox and new-tab non-images in every file list; task-creation project pickers without Done projects; project status changeable from the project view).
**Verified:** 2026-10-29
**Status:** human_needed
**Re-verification:** No, initial verification

All 10 requirement IDs are claimed by a plan, implemented in the code, wired, and covered by tests that I re-ran. The only open items are browser-level checks that cannot be asserted in Pest.

## Goal Achievement

### Observable Truths

| #   | Truth (requirement) | Status | Evidence |
| --- | ------------------- | ------ | -------- |
| 1 | P17-01: the checklist card of the task form spans the full width | VERIFIED | `TaskResource::form()` line 292: `Section::make(__('kokpit.tasks.checklist.heading'))->columnSpanFull()` around the `checklistItems` Repeater; `TaskChecklistTest` passes |
| 2 | P17-02: rich text is rendered with formatting through the one sanitiser on every read-only surface of both panels | VERIFIED | `TaskColumns` Admin and Partner description entries: `formatStateUsing(RichText::render)` + `->prose()` (lines 109-113, 237-241); `task-preview.blade.php` wraps `RichText::render` in `.fi-prose`; `comment-thread.blade.php` line 19 uses `.fi-prose` + `RichText::render` and serves both the Admin and the Partner comment tabs; no other `RichText` consumer or unsanitised `->html()` of rich text exists (the remaining `->html()` hits are time/transaction/invoice cells); e-mails and PDFs untouched (not in the diff). `RichTextRenderingTest` passes |
| 3 | P17-03: Admin creates a task from the project view with the project pre-filled and locked; not offered for a Done or archived project | VERIFIED | `ViewProject::newTaskAction()` -> `TaskResource::quickCreateAction($project, lockProject: true)`; field is `disabled()->dehydrated(false)`, project re-read from the page record in the action body (forged `project_id` ignored); `visible()` requires `Project::query()->selectable()->open()->whereKey(...)->exists()`. `ProjectViewActionsTest` passes |
| 4 | P17-04: in global search, tasks and projects that are not Done come first inside their category; category order and display unchanged | VERIFIED | `TaskResource::getGlobalSearchEloquentQuery()` and `ProjectResource::getGlobalSearchEloquentQuery()` add `orderByRaw('(status = ?) ASC', [Done])` in the base query, i.e. before Filament's 50-row limit; the previous key order is kept as the tie-break; `getGlobalSearchResultDetails` and the category registration are not in the diff. `GlobalSearchTest` (incl. the 50-row limit case) passes |
| 5 | P17-05: Admin adds a time entry from the task view with client, project and task pre-filled and locked, under the existing time-entry rules | VERIFIED | `ViewTask::addTimeAction()`: `TimeEntryResource::entryFields(lockContext: true)` disables only client/project/task; `fillForm` takes them from the task; the action body overwrites them from the record after the form data and calls the existing `CreateTimeEntry` Action; hidden for an archived task and for users who cannot create entries; 403 guard in the body. `TaskAddTimeTest` passes (see deviations) |
| 6 | P17-06: a task can be hidden from the kanban boards (shown by default); only the boards hide it; only the Admin changes it | VERIFIED | Migration adds `hidden_on_board boolean NOT NULL DEFAULT false` (schema test pins the default and SQLSTATE 23502 on NULL); the only filter is `TaskBoard::applyFilters()` line 128, used by both `columns()` and `move()`; the only writer is `UpdateTask` (authorizes `update`, null key keeps the stored value); `CreateTask` does not write it (Partner-created task stays false, tested); the flag is on the reviewed Partner-safe column list (`PartnerSafeColumnsTest`). `TaskBoardTest`, `TaskUpdateTest` pass |
| 7 | P17-07: Signal add field offers unfinished tasks by type-ahead; a pick stores `{PROJECT}: {task name} [{task code}]` linked to the task; the cross clears the pick; a linked signal shows the shared timer control and an open-task icon | VERIFIED | `LinkableTasks::search()` (not Done, selectable project, min 2 chars, 10 rows, escaped LIKE), `find()`, `label()` (255 cap, keeps the `[code]` suffix); `SignalDay::pickTask/clearPick/addTask` with `#[Locked] pickedTaskId/pickedTaskLabel`, a linked add stores the pick-time snapshot not the field value; `AddSignalTask` is the only writer of `signal_tasks.task_id` and re-checks `find()` inside the lock/transaction; migration: nullable uuid FK `ON DELETE SET NULL` + index (schema test asserts 23503 on a dangling id and SET NULL); `day.blade.php`: combobox roles, link prefix icon, readonly while picked, clear button, per-row timer button (`toggleTaskTimer`, owner-scoped row lookup, `view` gate, shared `TaskTimerToggle`, listeners for `timer-started/stopped/time-entry-*`) and open-task link. `SignalTaskLinkTest` (34 tests) and `SignalIsolationTest` pass |
| 8 | P17-08: image files open in a single-image lightbox and non-image files in a new tab in every file list of both panels | VERIFIED (browser behaviour routed to human) | `DocumentActions::preview()` is the one action used by `DocumentResource`, `ManagesDocuments` (Admin tabs) and `ShowsPartnerDocuments` (Partner tabs): image -> `lightboxModal` + `lightbox.blade.php` (link minted when the modal renders, after the `view` gate, 404 for a non-image); other inline types -> `url(..., shouldOpenInNewTab: true)`. Under comments: `previewCommentImageAction()` (UUID re-read through the scoped query, gate, image check) and PDF `target="_blank"` links from `previewUrls()`. `DocumentDisposition::isInlineImage()` is PNG/JPEG/GIF/WebP only (SVG never). `DocumentsRelationManagerTest`, `CommentAttachmentsTest`, `DocumentVisibilityTest` pass |
| 9 | P17-09: the project pickers of task creation (Admin and Partner) offer only projects that are not Done | VERIFIED | `Project::scopeOpen()` (status != Done); `TaskResource::quickCreateAction` unlocked path uses `projectOptions(openOnly: true)` (list and both boards share this action); `PartnerTaskResource::projectOptions()` uses `selectable()->open()`. `TaskResourceTest`, `PartnerTaskResourceTest` pass (in the suite run) |
| 10 | P17-10: project status can be changed from the project view as on the task view | VERIFIED | `ViewProject::changeStatusAction()`: Select of `ProjectStatus`, writes through `UpdateProject` with the status only, visible only when `ProjectResource::canEdit()` and not archived, the body re-checks `canEdit`/archived (403), invalid status and domain refusals are mapped to the field, the disabled view form is refreshed. `ProjectViewActionsTest` passes |

**Score:** 10/10 truths verified (0 present, behavior-unverified)

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/migrations/2026_10_29_000100_add_hidden_on_board_to_tasks_table.php` | `hidden_on_board` boolean NOT NULL DEFAULT false | VERIFIED | Exists, substantive, model cast/fillable present |
| `database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php` | `signal_tasks.task_id` nullable FK SET NULL, indexed | VERIFIED | Exists; schema test pins it |
| `app/Domain/Tasks/Board/TaskBoard.php` | the one board filter | VERIFIED | Single `where('tasks.hidden_on_board', false)` in `applyFilters` |
| `app/Domain/Tasks/Actions/UpdateTask.php` | the only writer of the flag | VERIFIED | Wired via `TaskResource::actionData` |
| `app/Filament/Support/DocumentActions.php`, `DocumentDisposition.php`, `lightbox.blade.php` | lightbox / new-tab preview | VERIFIED | Used by all list hosts |
| `app/Filament/Concerns/RendersTaskCommentThread.php`, `comment-thread.blade.php` | comment-file preview | VERIFIED | Both panels reuse the concern |
| `app/Filament/Support/TaskColumns.php`, `task-preview.blade.php` | prose rendering | VERIFIED | `fi-prose` on all three surfaces |
| `app/Domain/Signal/Queries/LinkableTasks.php`, `Actions/AddSignalTask.php`, `Livewire/Signal/SignalDay.php`, `day.blade.php` | type-ahead link | VERIFIED | Fully wired, data flows from DB queries |
| `app/Filament/Resources/ProjectResource/Pages/ViewProject.php` | New task and Change status actions | VERIFIED | Registered in `getHeaderActions()` |
| `app/Filament/Resources/TaskResource/Pages/ViewTask.php`, `TimeEntryResource.php` | addTime action, `lockContext` | VERIFIED | Registered in `getHeaderActions()` |
| `CONTRIBUTING.md`, `DocumentUrl` docblock | hand-over incl. PDF-link exception | VERIFIED | Phase 17 bullet and rewritten Documents sentence present |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `SignalDay::pickTask` | `LinkableTasks::find/label` | pick-time snapshot into `#[Locked]` props | WIRED | `addTask` stores the snapshot, not the field |
| `SignalDay::addTask` | `AddSignalTask` | `taskId` argument, re-validated in the transaction | WIRED | Only writer of `task_id` |
| `SignalDay::toggleTaskTimer` | `TaskTimerToggle` | owner-scoped row -> task -> `view` gate | WIRED | Dispatches the shared timer events |
| `ViewProject` header | `TaskResource::quickCreateAction` | `lockProject: true` | WIRED | Project read from the record |
| `ViewProject` header | `UpdateProject` | `changeStatus` | WIRED | Status-only payload |
| `ViewTask` header | `CreateTimeEntry` | `addTime`, context rewritten from the record | WIRED | Existing rules unchanged |
| `TaskBoard::columns/move` | `applyFilters` | hidden flag | WIRED | One filter for render and drop |
| `ManagesDocuments`, `ShowsPartnerDocuments`, `DocumentResource` | `DocumentActions::preview` | row action | WIRED | Admin and Partner |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `day.blade.php` suggestions | `taskSuggestions` | `LinkableTasks::search()` (Eloquent query) | Yes | FLOWING |
| `day.blade.php` row link | `$task['link']` | `SignalDayReader::tasks()` eager-loads the task (archived included) | Yes | FLOWING |
| Task/Partner description entries | `description` | model column through `RichText::render` | Yes | FLOWING |
| Global search results | records | Eloquent base query with the done-last order | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Phase 17 feature and isolation tests | `ddev exec vendor/bin/pest` on `TaskBoardTest`, `TaskUpdateTest`, `RichTextRenderingTest`, `TaskChecklistTest`, `GlobalSearchTest`, `SignalTaskLinkTest`, `ProjectViewActionsTest`, `TaskAddTimeTest`, `DocumentsRelationManagerTest`, `CommentAttachmentsTest`, `PartnerSafeColumnsTest`, `SignalIsolationTest`, `DocumentVisibilityTest` | 268 passed (2196 assertions) | PASS |
| Full gate | `ddev exec composer ci` (reported green by the caller, not re-run) | Pint, PHPStan, licences, 7197 Pest tests | PASS |

### Probe Execution

Step 7c: SKIPPED. The phase declares no probe scripts.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| P17-01 | 17-05 | Checklist card full width | SATISFIED | Truth 1 |
| P17-02 | 17-03 | Rich text rendered with formatting | SATISFIED | Truth 2 |
| P17-03 | 17-07 | Task from project view, project locked | SATISFIED | Truth 3 |
| P17-04 | 17-05 | Active first in search | SATISFIED | Truth 4 |
| P17-05 | 17-08 | Add time from task view | SATISFIED | Truth 5 |
| P17-06 | 17-01 | Hide task from kanban | SATISFIED | Truth 6 |
| P17-07 | 17-04, 17-06 | Signal type-ahead link, timer, open icon | SATISFIED | Truth 7 |
| P17-08 | 17-02 | Lightbox / new tab in every file list | SATISFIED | Truth 8 |
| P17-09 | 17-07 | Pickers without Done projects | SATISFIED | Truth 9 |
| P17-10 | 17-07 | Project status change on project view | SATISFIED | Truth 10 |

All ten IDs appear in PLAN frontmatter and in `REQUIREMENTS.md`; there are no orphaned requirements (every `P17-*` row maps to Phase 17 and to a plan).

### Known Deviations Weighed

- **17-08, archived project/client add-time is refused by the select's own context validation (validation error, not a toast).** Acceptable. The user-visible outcome (nothing created, modal stays open, an error is shown) holds and the existing time-entry rules stay the authority, which is what P17-05 asks. Minor nit: the in-code comment in `ViewTask::addTimeAction()` and the plan wording still describe a toast for context refusals; that branch now guards only a forged payload. Not a gap.
- **`TaskBillingResolver` reloads a null-cached project relation.** A genuine crash fix (task view of a task in an archived project). The change is small, guarded to the null-loaded case, and covered by the add-time tests. Acceptable.
- **Locked quick-create forged `project_id` is rejected by the select option check.** Acceptable and in addition the action body reads the project from the page record, so a forged value cannot decide the project.
- **Signed PDF link exception (a PDF row mints its signed link at render time).** Documented as an owner decision in 17-CONTEXT.md, the `DocumentActions`/`DocumentUrl` docblocks and CONTRIBUTING.md; route and user binding unchanged.

### Anti-Patterns Found

None. No `TBD`/`FIXME`/`XXX` markers in the files changed by the phase; no stubs, empty handlers or hardcoded empty data on the verified paths.

### Human Verification Required

See the `human_verification` list in the frontmatter (six items): image lightbox per browser, PDF new-tab/popup behaviour per browser, rich-text visuals in light and dark theme, keyboard use of the Signal combobox, header action order and colours on the project and task views, and an end-to-end browser pass of hiding a task from the boards.

### Gaps Summary

No gaps. Housekeeping for the orchestrator (not a phase gap): `.planning/REQUIREMENTS.md` still shows `[ ]` and `Pending` for P17-01 to P17-10 and should be flipped to complete when the phase is closed.

---

_Verified: 2026-10-29_
_Verifier: Claude (gsd-verifier)_
