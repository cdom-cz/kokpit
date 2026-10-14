# Phase 7: REST API - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-14
**Phase:** 7-REST API
**Areas discussed:** Tokens and management, Idempotency key, Response shape and queries, Timer behaviour (descoped), Batch write and external data, Rate limits and docs

---

## Tokens and management

| Option | Description | Selected |
|--------|-------------|----------|
| Optional expiry, default none | Choose none / 30 / 90 / 365 days at creation | ✓ |
| Mandatory expiry | Max about one year | |
| No expiry at all | Manual revoke only | |

**User's choice:** Optional expiry, default unlimited.

| Option | Description | Selected |
|--------|-------------|----------|
| Name + abilities + expiry + last used | Sanctum `last_used_at` | ✓ |
| Name and abilities only | | |
| Plus IP and user-agent | Custom token model, stores IP | |

**User's choice:** Name, abilities, expiry, last used.

| Option | Description | Selected |
|--------|-------------|----------|
| One-time modal + password/2FA re-confirmation | | ✓ |
| One-time modal only | | |

**User's choice:** One-time modal with re-confirmation.

---

## Idempotency key

| Option | Description | Selected |
|--------|-------------|----------|
| All writing POSTs (create, timer start/stop) | | |
| Create and timer start only | | |
| Required on all POST | | |

**User's choice:** Free text: "currently only create". Interpreted as create of time entries (single and batch); timer is out of the phase.

| Option | Description | Selected |
|--------|-------------|----------|
| Replay stored response; different body = 422 | Concurrent duplicate = 409 | ✓ |
| Replay without body check | | |

**User's choice:** Replay stored response, different body 422.

| Option | Description | Selected |
|--------|-------------|----------|
| DB table, 24 h, scheduled purge | | ✓ |
| Redis cache, 24 h | | |

**User's choice:** DB table, 24 hours.

---

## Response shape and queries

| Option | Description | Selected |
|--------|-------------|----------|
| `duration_seconds` + ISO 8601 `started_at`/`ended_at` | | ✓ |
| Decimal hours | | |
| Seconds and formatted string | | |

**User's choice:** Whole seconds plus ISO timestamps.

| Option | Description | Selected |
|--------|-------------|----------|
| `billable` and billed state yes, rates and amounts no | | ✓ |
| Also effective rate | | |

**User's choice:** No rates or amounts.

| Option | Description | Selected |
|--------|-------------|----------|
| Cursor pagination, filters client/project/task/billable/billed/date range, newest first | | ✓ |
| Offset pagination | | |

**User's choice:** Cursor pagination and filters.

| Option | Description | Selected |
|--------|-------------|----------|
| Detail by UUID or key, list `filter[name]` partial match | | ✓ |
| Writes accept only UUID | | |

**User's choice:** UUID or key for detail; writes reference UUID or key, never names.

---

## Timer behaviour (descoped)

Questions asked: start while a timer runs (auto-stop vs 409), stop/current with nothing running.
**User's answers:** "In the API there is currently no start" / "no stop". Clarified with a scope question; the user chose **"Remove timer from Phase 7"**. Timer endpoints deferred; ROADMAP.md and REQUIREMENTS.md not edited by this discussion.

---

## Rate limits and docs

| Option | Description | Selected |
|--------|-------------|----------|
| 120/min read, 60/min write per token | | ✓ |
| One shared 60/min | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Admin-only docs (`viewApiDocs`) + committed spec with CI drift check | | ✓ |
| Public docs | | |

**User's choice:** Both recommended options.

---

## Batch write and external data (added by the user)

User request: batch write of time; read-only listing for verification with a date from–to filter; optional source and third-party ID because n8n imports daily time from third-party systems (owner works for two companies and wants one total).

| Option | Description | Selected |
|--------|-------------|----------|
| All or nothing, per-item errors in 422 | One transaction | ✓ |
| Partial success (207) | | |

| Option | Description | Selected |
|--------|-------------|----------|
| `external_source` + `external_id` unique pair (409 on repeat) | | |
| Notes only, no uniqueness | | |
| Upsert on same pair | | |

**User's choice:** Free text: considering separate columns versus one large JSON column; no deduplication in the API, not shown in the UI, caller guards duplicates. Then chose **one optional `jsonb` column `external` with a size limit**.

| Option | Description | Selected |
|--------|-------------|----------|
| References by UUID or key; no names | | ✓ |
| Allow exact client name | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Max 100 items per batch | | ✓ |
| Max 500 items | | |

---

## Claude's Discretion

Class/file names, resource structure, error format, token model details, idempotency middleware design, column types and index names, Czech labels, batch error reporting, test structure.

## Deferred Ideas

- Timer API (AP-03), removed from Phase 7.
- Deduplication by source and external ID.
- Idempotency key on update/delete.
