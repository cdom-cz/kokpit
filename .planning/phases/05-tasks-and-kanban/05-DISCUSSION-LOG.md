# Phase 5: Tasks and Kanban - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-08
**Phase:** 5-Tasks and Kanban
**Areas discussed:** Task statuses and kanban columns; Partner escalation, notifications and comments; Task detail and subtasks; Task billing fields

---

## Task statuses and kanban columns

| Option | Description | Selected |
|--------|-------------|----------|
| Same as project | Six project statuses | ✓ |
| Own task set | Separate enum | |
| Same plus Cancelled | | |

**User's choice:** Same as project; Done column limited to recent N; subtasks are cards of their own.

## Partner escalation, notifications and comments

**User's choice:** Escalation is a flag that notifies the assignee (Admin if none) and requires a comment; priority is not changed. Every task and subtask has an assignee and a requester, either may be a Partner account. Defaults: requester = creator, assignee = Admin. Pickers: Admin + active Partners of the project's client. Notifications: e-mail + bell for Admin and for Partner (user addendum: Partner also gets both).

## Task detail and subtasks

**User's choice:** Full page by key + quick-create modal + board slide-over; rich text (sanitised HTML); subtasks with own status and fields, no nesting; attachments deferred to Phase 9.

## Task billing fields

**User's choice:** Types inherit/hourly/fixed/non-billable; separate Admin-only `task_billing` table; empty values inherit at read time, no copying.

## Claude's Discretion

Names, wording, layout details, sanitiser config, checklist storage.

## Deferred Ideas

Task files (Phase 9), time on tasks (Phase 6).
