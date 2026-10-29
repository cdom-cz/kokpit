# Phase 17: Task, project and search UX refinements - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-29
**Phase:** 17-task-project-and-search-ux-refinements
**Areas discussed:** Signal and task link, Kanban hide flag, Time and task from other places, Files / rich text / search ordering

---

## Signal and task link

| Option | Description | Selected |
|--------|-------------|----------|
| Text stays, link lives | Snapshot text, FK link, cleared on delete | ✓ |
| Text synchronizes | Text derived from current task | |
| Completing task ticks signal | Strong coupling | |

**User's choice:** Text stays, link lives
**Notes:** Timer: same toggle as task (selected). Type-ahead: unfinished tasks by code and name (selected).

---

## Kanban hide flag

| Option | Description | Selected |
|--------|-------------|----------|
| Form field; hidden only from boards | | ✓ |
| Form + quick card action | | |
| Hidden also from search and lists | | |

**User's choice:** Form field; hidden only from boards
**Notes:** Partner: hide also applies in the Partner view (user deviated from the recommended "admin board only").

---

## Time and task from other places

| Option | Description | Selected |
|--------|-------------|----------|
| Header action with modal (time) | | ✓ |
| Relation manager | | |
| Both | | |

**User's choice:** Header action with modal for time; action on project with locked project for new task.

---

## Files / rich text / search ordering

**User's choice:** Lightbox and new window everywhere (admin and Partner); rich text on all UI surfaces, sanitized; active = not Completed/Cancelled for tasks and projects.

---

## Claude's Discretion

Names, lightbox implementation, type-ahead wiring, wording, icons, checklist card layout.

## Deferred Ideas

None.
