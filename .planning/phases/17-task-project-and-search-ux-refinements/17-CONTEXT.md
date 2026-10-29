# Phase 17: Task, project and search UX refinements - Context

**Gathered:** 2026-10-29
**Status:** Ready for planning

<domain>
## Phase Boundary

A batch of UX refinements across tasks, projects, search, signals and files (scope fixed by ROADMAP.md §Phase 17):

- Task create/edit form: the "Checklist" card spans the full width.
- Rich-text content is rendered with its formatting in read-only output.
- A task can be created from the project detail.
- Search results: within each category, active tasks and projects come first (category order and display unchanged).
- Time entries can be added from a task.
- A task can be flagged to be hidden from the kanban board (default: shown).
- Signal: type-ahead pick of an unfinished task inside the existing text field; linked task shows a timer control and an open-task icon in the listing.
- Files: image preview in a single-image lightbox; non-image files open in a new window.
- Task creation: project suggestions exclude "Completed" projects.
- Projects: status changeable from the project view, like on the task view.

Not in this phase: new capabilities beyond the list above.

</domain>

<decisions>
## Implementation Decisions

### Signal and task link
- **D-01:** The pick inserts the text `{PROJECT}: {task name} [{task code}]` into the signal and stores a link to the task. The text is a snapshot taken at pick time; later renaming, completing or soft-deleting the task does not change the signal text. The link lives on (FK to the task); if the task is deleted the link is cleared silently and the signal stays plain text. A completed linked task no longer offers the timer control (the open-task icon stays while the task exists). — **Reversibility:** costly — introduces the first link between `SignalTask` and `Task`, which were deliberately separate ("the two never link"), plus a migration.
- **D-02:** The timer control on a linked signal reuses the existing task timer toggle (`TaskTimerToggle` / `TaskResource::timerAction()`), so one shared running timer applies across all surfaces. No separate signal timer.
- **D-03:** The type-ahead offers unfinished tasks (not archived, not soft-deleted, not in a terminal state), searched by task code, task name and project; tasks hidden from the kanban board are offered too. Result count is capped (about 10). Pick works inside the existing text field without switching modes; the cross icon clears the pick and returns to free text; with nothing picked, plain unlinked text is stored. In the listing the task name is not a link; the open-task icon is.

### Kanban hide flag
- **D-04:** The flag is a form field on the task (checkbox, default off = shown). A hidden task disappears only from kanban boards (global board and project board). Lists, search, task detail, timer and time entries are unaffected.
- **D-05:** The hide also applies in the Partner view of the board where one exists. The flag is editable by the Admin only; Partner cannot change it.

### Time and task from other places
- **D-06:** "Add time" is a header action on the task detail that opens a modal with the task pre-filled and locked. It reuses the existing TimeEntry create logic with all its rules (immutability of billed entries, validation); no new rules.
- **D-07:** "New task" is an action on the project detail that opens the existing task create form with the project pre-filled and locked (client derived from the project). The action is not offered for a project in a terminal state.

### Files, rich text, search ordering
- **D-08:** Image previews open in a single-image lightbox without navigation between images; non-image files open in a new window/tab. Applies everywhere files are listed, in both the admin and the Partner panel (Partner only for files they may already see).
- **D-09:** Rich text is rendered with formatting on all read-only UI surfaces (admin and Partner panels: descriptions, comments and any other rich-text field) through sanitized, whitelisted HTML. E-mails and PDFs are unchanged.
- **D-10:** "Active" for the search ordering means a task or project not in a terminal state (task: not completed/cancelled; project: not Completed/Cancelled). Active first, then the rest, inside each category; existing relevance order within each group; category order (`$globalSearchSort`) and display unchanged.
- **D-11:** Project suggestions on task creation exclude projects in the "Completed" state (and the same terminal states as D-10, unless already handled).
- **D-12:** The project status is changeable from the project view with a header action and modal, mirroring `ViewTask::changeStatus` (same effect as saving the edit form).

### Claude's Discretion
Column/enum/migration names for the signal link and hide flag, the lightbox implementation (Filament modal vs. small component), how the type-ahead is wired into the existing signal input (Livewire), exact cs/en wording, icons, and the checklist card full-width implementation.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and roadmap
- `.planning/ROADMAP.md` §Phase 17 — goal of the phase
- `.planning/PROJECT.md` — constraints (Partner isolation, repository hygiene, immutability)
- `.planning/REQUIREMENTS.md` — task, time entry, signal and search requirements

### Code starting points
- `app/Domain/Signal/Models/SignalTask.php` — signal task (currently never linked to Task)
- `app/Livewire/Signal/SignalDay.php`, `app/Livewire/Signal/RunsSignalActions.php` — signal listing and input
- `app/Filament/Resources/TaskResource.php` — task form, timer action, global search
- `app/Filament/Resources/TaskResource/Pages/ViewTask.php` — change status action to mirror
- `app/Filament/Resources/ProjectResource.php` — project view, global search
- `app/Domain/Tasks/Board/TaskBoard.php`, `app/Filament/Concerns/ManagesTaskBoard.php` — kanban boards
- `app/Filament/Partner/Resources/PartnerTaskResource.php` — Partner view, comments, rich text

No external specs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TaskResource::timerAction()` / `TaskTimerToggle`: shared timer across surfaces.
- `ViewTask` change-status action: template for project status change.
- Filament global search with `$globalSearchSort` and `getGlobalSearchEloquentQuery()`.

### Established Patterns
- Domain actions in `app/Domain/*/Actions` (e.g. `UpdateTask`, `MoveTask`); Partner isolation via policies and scopes.
- Rich text in task and comment resources (admin and Partner).

### Integration Points
- Signal listing (new FK + timer/open icons), kanban board queries, global search queries, file listings in both panels.

</code_context>

<specifics>
## Specific Ideas

Signal text format is exactly `{PROJECT}: {task name} [{task code}]`.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 17-Task, project and search UX refinements*
*Context gathered: 2026-10-29*

## Plan-phase Clarifications (resolved after research)

- **Signal text format (supersedes D-01 and Specific Ideas):** `{PROJECT}: {task name} [{task code}]`. `{PROJECT}` is the project name (the task code already carries the project key). Built server-side, truncated to 255 characters. Per D-01 ("a snapshot taken at pick time") the text is composed once when the task is picked and stored unchanged when the signal is saved; the save re-reads the task only to refuse one that became unavailable, never to rebuild the text (17-04 R-17-04-4, R-17-04-5).
- **D-11 scope:** the "not Completed" project restriction applies to the Partner create form as well as the Admin one. In this codebase the terminal state is `done` (label "Dokončeno"); there is no separate Cancelled state.
- **Editing a linked signal:** keeps the task link; the title text can be changed freely.
- **Non-image files:** open in a new window/tab by adding `target="_blank"` to the existing open behaviour (no new route, no `window.open`). Images use the single-image lightbox.
- **Archived linked task in the signal listing:** open-task icon stays, timer control is hidden (research reading of D-01, not explicitly contested).
- **Signed PDF link exception (owner decision, 2026-10-29):** accepted as planned in 17-02 R-17-02-3. The inline preview of a non-image file (a PDF) is the single case where a page renders a signed file link into its HTML: a `target="_blank"` link, minted when the row renders, bound to the viewing user and guarded by the unchanged route (authenticated panel route, `signed`, `u` must equal the signed-in user, the Partner-scoped lookup, the `view` policy, `nosniff`, the no-referrer header) with the existing 120-second lifetime. Downloads, ZIP archives and image previews keep the click-time rule. A page left open longer than 120 seconds holds an expired PDF link that answers 403 in the new tab until the list renders again; this is accepted: no change to the link lifetime and no click-time `window.open`. The CONTRIBUTING Documents rule is rewritten in 17-08 (R-17-08-5 a) to name this one exception. It departs from the Phase 9 rule that no screen renders a signed link (Phase 9 D-10, T-09-18) for this case only. Threat T-17-04 (17-02) cites this decision.
- **Preview trigger (RESEARCH Open Question 5):** the explicit "Zobrazit" row action stays the only trigger of the lightbox and of the PDF tab, in every file list and under comments; the file name and the row are not made clickable (17-02 R-17-02-2).
- **RESEARCH Open Questions, all resolved:** 1 -> "Signal text format"; 2 -> "D-11 scope"; 3 -> "Editing a linked signal"; 4 -> "Non-image files" and "Signed PDF link exception"; 5 -> "Preview trigger"; 6 -> "Archived linked task in the signal listing" (all in this section).

### Owner-visible rulings (planner rulings, accepted; the owner may still contest them at verification)

None of these changes a locked decision; each is recorded in the named plan and flagged in its SUMMARY.

- Rich-text links keep the `target="_blank"` and `rel="noopener noreferrer nofollow"` that `RichText::render()` forces; the UI-SPEC wording "open in the same tab" was amended to match, because changing it would loosen the one sanitiser that D-09 keeps (17-03 R-17-03-4).
- Inside the global-search task group, each part (not Done first, then Done) is ordered newest first; task results had no order before (17-05 R-17-05-1).
- "Nový úkol" on the project view is hidden for a Done project and also for an archived project and a project of an archived client; "Změnit stav" is hidden on an archived project (17-07 R-17-07-5 and R-17-07-6, research A6).
- The add-time modal pre-fills start one hour ago and end now (17-08 R-17-08-2, research A10).
- The Signal type-ahead matches case-insensitively with `ILIKE` but not accent-insensitively, and orders suggestions by project key, then task number (17-04 R-17-04-3, research A9).
- From a stale Signal row of a Done or archived task, starting the timer is refused while stopping its own running timer stays possible (17-06 R-17-06-3).
