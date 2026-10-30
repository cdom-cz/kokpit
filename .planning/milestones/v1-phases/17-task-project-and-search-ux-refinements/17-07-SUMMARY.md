---
phase: 17-task-project-and-search-ux-refinements
plan: 07
subsystem: ui
tags: [filament, project-view, quick-create, status, pickers]
requires:
  - phase: 17-04
    provides: lang/cs/kokpit.php baseline of the wave
  - phase: 17-05
    provides: TaskResource.php baseline of the wave
provides:
  - Project::scopeOpen() (status is not Done)
  - TaskResource::quickCreateAction(?Project, bool $lockProject) with a locked project picker
  - "Nový úkol" and "Změnit stav" header actions on the project view
  - task-creation project pickers (Admin list, boards, Partner form) without Done projects
affects: []
tech-stack:
  added: []
  patterns: ["a disabled Select is not dehydrated, so the locked project is read fresh from the page record in the action body", "header action authorization re-checked in the action body because /livewire/update is public"]
key-files:
  created:
    - tests/Feature/Projects/ProjectViewActionsTest.php
  modified:
    - app/Domain/Projects/Models/Project.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
    - app/Filament/Partner/Resources/PartnerTaskResource.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskResourceTest.php
    - tests/Feature/Tasks/PartnerTaskResourceTest.php
key-decisions:
  - "The locked project is never read from form data: the action body re-reads it from the page record (T-17-20)"
  - "New task is hidden (not disabled) for Done, archived and archived-client projects; the archived cases are a planning ruling (research A6)"
  - "Change status calls UpdateProject with only the status key and checks canAccess/canEdit/not-trashed itself, since UpdateProject has no Gate (T-17-21)"
  - "The task-list project filter and EntryContextOptions keep offering Done projects (tracked time on a finished project stays legitimate)"
requirements-completed: [P17-03, P17-09, P17-10]
status: complete
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: 31923ad183e010a9fcca7458f0e1d68528e1cb93
plan_head_after: 70b4371aa9f125d490acf2b247728cae1798aa26
---

# Phase 17 Plan 07: Project view actions and open-only task pickers Summary

From the project view the Admin creates a task with the project locked and changes the project status with the effect of the edit form, and every task-creation project picker (Admin list, boards, Partner form) stops offering Done projects.

## Tasks

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Tracer: "Nový úkol" on the project view, project locked | 096b974 | Project.php, TaskResource.php, ViewProject.php, kokpit.php, ProjectViewActionsTest.php |
| 2 | Task creation pickers stop offering Done projects (Admin and Partner) | 23307ef | PartnerTaskResource.php, TaskResourceTest.php, PartnerTaskResourceTest.php |
| 3 | "Změnit stav" on the project view | 70b4371 | ViewProject.php, kokpit.php, ProjectViewActionsTest.php |

## What was built

- `Project::scopeOpen()`: `status != 'done'`, table-qualified like `scopeSelectable()`.
- `TaskResource::quickCreateAction(?Project $project = null, bool $lockProject = false)`. Unlocked: options from `projectOptions(openOnly: true)`, default is the given project only when it is not Done. Locked: the Select holds only that project, is disabled and not dehydrated, shows the hint "Projekt je daný detailem projektu."; the action body reads the project with `Project::query()->withTrashed()->find($project->getKey())` and ignores `$data['project_id']`. A `project_id` refusal while locked becomes a danger toast plus `$action->halt()`.
- `TaskResource::projectOptions(bool $openOnly = false)`; the task-list `project` filter still calls it without the flag.
- `PartnerTaskResource::projectOptions()` chains `->open()`; a forged Done project id is a `project_id` form error (Filament refuses values outside the options).
- `ViewProject` header actions in order: board, "Nový úkol" (gray, visible only for an open, selectable project and `TaskResource::canAccess()`), "Změnit stav" (gray, Select over `ProjectStatus`, visible while `ProjectResource::canEdit()` holds and the project is not trashed), edit, archive, restore. The change-status body aborts 403 unless access, edit and not-trashed hold, validates the status with `ProjectStatus::tryFrom`, calls `UpdateProject::handle($project, ['status' => ...])`, refreshes the record and `refreshFormData(['status'])`.
- Copy (cs): `tasks.hints.project_locked`, `projects.actions.change_status_heading`, `projects.notifications.status_changed`, `projects.errors.status_invalid`. "Změnit stav" and "Uložit stav" are reused from `tasks.actions`.
- Tests: new `ProjectViewActionsTest` (locked create, forged `project_id` ignored, modal shows the disabled project with the hint, New task hidden for Done/archived/archived-client, header order, status change with one history row and unchanged name/key/visibility/tags/billing, empty status error, hidden on archived); `TaskResourceTest` (Done refused in quick modal, filter still offers Done, board preset with and without Done); `PartnerTaskResourceTest` (Done not offered, forged Done id refused).

## Deviations from Plan

None - plan executed as written. The unlocked-picker part of the quick-create (open-only options, no Done preselect) is implemented in the Task 1 commit together with the lock option because both live in the same function; its tests and the Partner filter are in the Task 2 commit.

## Known Stubs

None.

## Threat Flags

None. No new endpoint or trust boundary; T-17-20 to T-17-23 are mitigated as planned.

## Verification status

Worktree only (per CLAUDE.md): `php -l` clean on every touched PHP file, Pint (`--test`, main checkout binary) passed on the touched PHP files, `scripts/check-sensitive.sh` clean on each commit, acceptance greps pass, `EntryContextOptions` untouched. NOT run here and left for the orchestrator after the merge: the Pest files of the three tasks, the plan gate (`pest tests/Feature/Projects tests/Feature/Tasks tests/Isolation tests/Arch`, `pint --test`, `phpstan`) and its runtime. Tests were written without being executed; points most likely to need adjustment on first run: the `getModalContent()->render()` check in the locked-modal test, the `assertSchemaStateSet(['project_id' => null])` check on a Done board, and the `old`/`attributes` keys of `attribute_changes` in the status test.

Flagged ruling: hiding "Nový úkol" for archived projects and projects of archived clients is a planning ruling (research A6), not a locked user decision.

Manual check (human, visual): header order and colours on the project view, locked modal appearance, status modal saving without reload, Done project hides "Nový úkol", neither creation form offers the Done project.

## Self-Check: PASSED

- Files exist: ProjectViewActionsTest.php, ViewProject.php, Project.php (scopeOpen), TaskResource.php, PartnerTaskResource.php.
- Commits 096b974, 23307ef, 70b4371 are on the branch (3 commits since the base 31923ad).
