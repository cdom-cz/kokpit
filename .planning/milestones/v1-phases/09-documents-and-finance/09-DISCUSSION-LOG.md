# Phase 9: Documents and Finance - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-16
**Phase:** 9-Documents and Finance
**Areas discussed:** Files and Partner visibility, Central listing / ZIP / deletion, Transactions and CZK amount, Categories and finance overview

---

## Files and Partner visibility

| Option | Description | Selected |
|--------|-------------|----------|
| Document record + 1 file | Each file is a `documents` row with one medialibrary media; uniform listing, totals, optional owner | ✓ |
| Media directly on models | Medialibrary collections on each model; unattached needs a stand-in model | |
| You decide | Planner designs the model | |

**User's choice:** Document record + 1 file, using Spatie media.
**Notes:** Owner wants medialibrary used for storage.

| Option | Description | Selected |
|--------|-------------|----------|
| Hidden, Admin enables (Recommended) | Default-deny | |
| Visible on project/task | Shared by default, Admin can hide | ✓ |
| Ask on every upload | Required field | |

**User's choice:** Visible on project/task by default.

| Option | Description | Selected |
|--------|-------------|----------|
| Read and download only (Recommended) | Meets DO-01 literally, keeps Phase 5 A10 | |
| Upload to own tasks and comments | Reopens the Phase 5 decision | ✓ |

**User's choice:** Partner uploads to own tasks and comments.
**Notes:** Scope widening; follow-up questions asked.

| Option | Description | Selected |
|--------|-------------|----------|
| "Soubory" relation manager (Recommended) | Shared tab on record detail | ✓ |
| Upload field in the form | Management only in central listing | |

**User's choice:** Relation manager.

### Partner upload follow-ups

| Option | Description | Selected |
|--------|-------------|----------|
| Own tasks and comments everywhere (Recommended) | Partner is author of the task/comment | ✓ |
| Any visible task | Comment on any visible task | |
| Only when creating a task | No comment attachments | |

| Option | Description | Selected |
|--------|-------------|----------|
| Narrower whitelist and lower limit (Recommended) | Stricter for Partner | |
| Same rules as Admin | One limit, same disallowed extensions | ✓ |

| Option | Description | Selected |
|--------|-------------|----------|
| Bell to Admin, Partner deletes own (Recommended) | Notification; Partner deletes own while task open | ✓ |
| No deleting by Partner | Admin only | |

**User's choice:** Own tasks and comments; same limits as Admin; bell notification and Partner deletes own.

---

## Central listing, ZIP and deletion

| Option | Description | Selected |
|--------|-------------|----------|
| Row selection, queue, notification (Recommended) | ZIP of selected rows, 7-day retention | ✓ |
| Also whole client/project button | Extra "download all" actions | |

| Option | Description | Selected |
|--------|-------------|----------|
| Permanent with confirmation (Recommended) | S3 object removed, activity log | ✓ |
| Trash then purge after 30 days | Soft delete | |

| Option | Description | Selected |
|--------|-------------|----------|
| Tiles + by-client table (Recommended) | Totals above listing, "Unattached" row | ✓ |
| Column on client list | Per-client size only | |

| Option | Description | Selected |
|--------|-------------|----------|
| Columns: client, owner, type, size, date | Sortable columns | ✓ |
| Filters: owner, client, project, file type | Filters and name search | ✓ |
| Inline preview of images/PDF | Short-lived signed URL | ✓ |

**User's choice:** All recommended options; all three listing items.

---

## Transactions and CZK amount

| Option | Description | Selected |
|--------|-------------|----------|
| CNB rate of the day + manual override (Recommended) | Snapshot with manual flag | ✓ |
| Automatic only | No override | |
| Always manual | Enter CZK each time | |

| Option | Description | Selected |
|--------|-------------|----------|
| Any CNB currency; CZK required when no rate (Recommended) | Never blocks saving | ✓ |
| Only CZK, EUR, USD | Fixed list | |

| Option | Description | Selected |
|--------|-------------|----------|
| Description and counterparty free text (Recommended) | Plus optional document number | ✓ |
| Description only | Minimal | |

| Option | Description | Selected |
|--------|-------------|----------|
| Free edits + activity log, "no receipt" filter (Recommended) | No period closing | ✓ |
| Period closing | New feature | |

**User's choice:** All recommended options.

---

## Categories and finance overview

| Option | Description | Selected |
|--------|-------------|----------|
| Two levels, type per category (Recommended) | Child inherits type, rollup to parent | ✓ |
| Arbitrary depth tree | More flexible, more complex | |

| Option | Description | Selected |
|--------|-------------|----------|
| Small generic set + system category (Recommended) | System income category for invoice payments | ✓ |
| Empty | Admin creates all | |

| Option | Description | Selected |
|--------|-------------|----------|
| Archive, cannot delete when used (Recommended) | FK restrict | ✓ |
| Move transactions on delete | Rewrites history | |

| Option | Description | Selected |
|--------|-------------|----------|
| Page with period switch, tiles, tables, chart (Recommended) | Month/Year, category and client tables, monthly bar chart | ✓ |
| Tables only | No chart | |

**User's choice:** All recommended options.

---

## Claude's Discretion

Class and table names, morph and `client_id` consistency mechanism, signed-URL mechanism, file-type group definitions, ZIP library, category seed names, CHECK constraints, Czech wording, chart styling, Partner upload form integration, test structure.

## Deferred Ideas

- One-click ZIP of a whole client or project
- Period closing for finance
- Stricter whitelist / malware scanning for uploads
