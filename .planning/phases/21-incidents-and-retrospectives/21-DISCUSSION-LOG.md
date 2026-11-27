# Phase 21: Incidents and Retrospectives - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-27
**Phase:** 21-Incidents and Retrospectives
**Areas discussed:** Incident structure, Incident sub-pages, Follow-up to task, Retrospective and time

---

## Incident structure

| Question | Options | Selected |
|----------|---------|----------|
| Where causes, timeline, actions, follow-ups live | Structured DB rows / Markdown sections / Hybrid | Structured DB rows |
| Occurrence time | Instant + chosen zone / Instant in panel zone | Instant + chosen zone |
| Severity and status | SEV1-SEV4 + open/resolved/closed / Word severities + open/closed / Claude's discretion | SEV1-SEV4 + open/resolved/closed |
| Affected system | Free text / Client or project link + text | Free text |
| Versions for structured rows | None, edit in place / Versioned | None, edit in place |
| Hub layout | Sectioned view + Markdown body / Tabs | Sectioned view + Markdown body |

## Incident sub-pages

| Question | Options | Selected |
|----------|---------|----------|
| Several pages of the same type | At most one per type / Any number | Any number |
| New page content | Empty with type title / Heading skeleton | Empty with type title |
| Opening from hub | Own screen + breadcrumb / Embedded in hub | Own screen + breadcrumb |
| Naming and order | Type-prefilled title, type then time / By creation only | Type-prefilled title, type then time |
| Automatic Report page | All manual / Automatic | All manual |

## Follow-up to task

| Question | Options | Selected |
|----------|---------|----------|
| Project of the task | Chosen in dialog / Incident project only / Internal project | Chosen in dialog |
| What is copied | Title, description, due date + link / Also status | Title, description, due date + link |
| Task deleted | Link nulled, follow-up stays / Deletion blocked | Link nulled, follow-up stays |

## Retrospective and time

| Question | Options | Selected |
|----------|---------|----------|
| "Live" retrospective | Not archived = live / One forever | Not archived = live |
| Button location | Project detail header action / Project tab | Header action |
| Fixed sections | Four fields composed to one Markdown / One body with headings | Four fields composed |
| Time overview timing | Stored snapshot + Refresh / Live on every view | Stored snapshot + Refresh |

## Claude's Discretion

Table and column names, wording, icons, menu order, idempotency mechanism for one-press task creation, definition of billed versus unbilled, list columns and filters, ordering of rows inside the hub.

## Deferred Ideas

None.
