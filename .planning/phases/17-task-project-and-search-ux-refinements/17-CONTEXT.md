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
- **D-01:** The pick inserts the text `{PROJECT}: {task code} {task name}` into the signal and stores a link to the task. The text is a snapshot taken at pick time; later renaming, completing or soft-deleting the task does not change the signal text. The link lives on (FK to the task); if the task is deleted the link is cleared silently and the signal stays plain text. A completed linked task no longer offers the timer control (the open-task icon stays while the task exists). — **Reversibility:** costly — introduces the first link between `SignalTask` and `Task`, which were deliberately separate ("the two never link"), plus a migration.
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

Signal text format is exactly `{PROJECT}: {task code} {task name}`.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 17-Task, project and search UX refinements*
*Context gathered: 2026-10-29*
