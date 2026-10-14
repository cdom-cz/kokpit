# Phase 7: REST API - Context

**Gathered:** 2026-10-14
**Status:** Ready for planning

<domain>
## Phase Boundary

External tools (in practice the owner's n8n automations) can read and write time entries and look up clients, projects and tasks through a versioned, token-secured `/api/v1` that applies the same domain rules as the UI. Admin manages API tokens in the panel. Delivers AP-01, AP-02, AP-04, AP-05.

**Scope change decided in this discussion:** the timer endpoints (start, stop, current running; AP-03 and success criterion 3 in part) are removed from Phase 7. The owner wants the API to cover time entries, lookups, tokens, limits and docs only. `ROADMAP.md` and `REQUIREMENTS.md` have NOT been edited by this discussion; they must be updated (AP-03 moved to a later phase or backlog, success criterion 3 reworded to idempotent entry creation) before or during planning.

**Scope additions decided in this discussion (extend AP-02):** batch write of time entries and an optional free-form `external` JSON object on a time entry for import helpers.

Not in this phase: timer endpoints, write access to clients/projects/tasks (lookups are read-only), invoices/finance in the API, webhooks, any Partner access to the API.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): time is Admin-only and a Partner never sees time, rates or prices; fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); UUID v7 keys and `timestamptz`; DB-enforced invariants (CHECK for client/project/task agreement and end after start, billed entries locked, Phase 6); domain actions `CreateTimeEntry`, `UpdateTimeEntry`, `DeleteTimeEntry` are the single place for rules, the API calls them and adds no parallel rule set; queued job base `KokpitJob`; numbering and activity log allowlist patterns.

### Tokens and token management (AP-01, AP-05)
- **D-01:** Tokens are Sanctum personal access tokens issued to the Admin user with abilities `time:read` and `time:write`; a request whose token lacks the needed ability is refused (403). A Partner cannot create tokens and a token of a user that is not Admin gets no access.
- **D-02:** Expiry is optional per token, chosen at creation (no expiry / 30 / 90 / 365 days); the default is no expiry. Revoking is always possible.
- **D-03:** The token management screen (Filament, Admin-only, with `#[AccessRule]`) lists name, abilities, created, last used (Sanctum `last_used_at`), expiry and a Revoke action. No IP or user-agent is stored.
- **D-04:** After creation the plain-text token is shown once in a modal and never again. Creating a token requires re-confirming password/2FA first (Admin has mandatory 2FA, `EnsureAdminHasTwoFactor`). — **Reversibility:** reversible
- **D-05:** `personal_access_tokens` must carry a UUID morph column for `tokenable` (project rule: UUID v7 keys everywhere incl. package morph columns); a custom token model with `HasUuids` registered via `Sanctum::usePersonalAccessTokenModel()` is the expected route (see STACK.md, Sanctum row). Exact approach is Claude's discretion. — **Reversibility:** costly — every issued token and the token table depend on the key type.

### Idempotency key (AP-05)
- **D-06:** `Idempotency-Key` header is optional and supported only on create requests (create a time entry, batch create). Update and delete are idempotent by nature and do not use it. (Timer start is out of the phase, see boundary.)
- **D-07:** A repeated key with the same request body returns the stored original response (same status and body) plus header `Idempotent-Replayed: true`. The same key with a different body returns 422. A concurrent second request with the same key while the first is running returns 409.
- **D-08:** Keys are stored in a PostgreSQL table with a unique index on (token, key) and a body hash; a key lives 24 hours (value in `config/kokpit.php`) and a scheduled job purges expired keys. Redis is not the source of truth. — **Reversibility:** costly — clients rely on the replay contract and the table is part of the API surface.

### Response shape, filtering and lookups (AP-02, AP-04)
- **D-09:** A time entry is returned with `duration_seconds` (whole seconds, exact storage per TI-08) and `started_at` / `ended_at` in ISO 8601. Writes take `started_at` + `ended_at` (and `duration_seconds` for the manual-entry shape the UI allows); the API refuses what the UI refuses (locked billed entries, inconsistent client/project/task, end before start). — **Reversibility:** one-way — published response contract once clients (n8n) depend on it.
- **D-10:** The entry response includes `billable` and the billed state but NO rates, prices or amounts; tokens carry only `time:*` abilities and there is no finance ability in this phase.
- **D-11:** List endpoints use cursor pagination (default 50, max 200 per page), default sort newest first. Time entries filter by client, project, task, billable, billed and a date range from/to (`filter[...]`, `sort`) using `spatie/laravel-query-builder`. The list also serves as the read-back to verify what was recorded.
- **D-12:** Lookups of clients, projects and tasks are read-only. Detail resolves by UUID or by key (`/tasks/KEY-N`; project by key). The list supports `filter[name]` as a partial match. A name is search only and never identifies a resource for a write.
- **D-13:** Entry writes reference client, project and task by UUID or key (`KEY-N` for tasks, project key); names are not accepted.

### Batch write and external data (AP-02 extension)
- **D-14:** Batch create writes up to 100 entries in one request (limit in `config/kokpit.php`; more returns 422). It is partial-success: every valid item is saved, each in its own transaction through the same `CreateTimeEntry` action as the single create, and invalid items are skipped. The response lists only the items that failed (item index plus errors), together with counts of saved and failed items. Exact status codes (for example 201 when all saved, 207 when mixed, 422 when none saved) and the response layout are Claude's discretion. Because the caller does its own deduplication (D-15), a retry of a mixed batch must be safe with `Idempotency-Key`: the stored response is replayed (D-07). — **Reversibility:** costly — importers rely on the partial-success contract.
- **D-15:** A time entry gets one optional `jsonb` column `external` for helper data from third-party systems (for example source system and its ID). It must be a JSON object (not array or scalar) with a size limit (about 4 KB, value in `config/kokpit.php`), accepted on single create, batch create and update, and returned by the API. It is not shown in the UI, gets no unique index and no deduplication: the caller (n8n) guards against duplicates itself. It is not part of the activity log allowlist. — **Reversibility:** costly — a new column on `time_entries` plus the contract; changing it later is a migration.

### Rate limiting and documentation (AP-05)
- **D-16:** Rate limits per token: 120 requests/min for reads, 60 requests/min for writes, values in `config/kokpit.php`. Exceeding returns 429 with `Retry-After` and standard `X-RateLimit-*` headers.
- **D-17 (revised 2026-10-14, owner decision after research):** API documentation is generated by Scribe (`knuckleswtf/scribe`, MIT) at release time, so it is always current for the deployed code. This supersedes the earlier Scramble decision. The generated output is not committed and there is no CI drift test; the build step must fail when generation fails. The documentation is served on a public URL with no authentication (owner decision 2026-10-14; the API endpoints themselves stay token-protected) and its assets (CSS, JS) are served from the application itself, with no CDN. It must document filters, the Sanctum bearer scheme, abilities, the `Idempotency-Key` header and 429/422/409 responses, and it must provide an OpenAPI spec. Because the page is public, fictional example values only (`example.com`, placeholder IDs, placeholder base URL), never a real token, host or identifier. On Zerops the generation runs as the last entry of `run.initCommands` on every container (no `execOnce`), so it must be idempotent and write only to container-local paths.

### Claude's Discretion
Class and file names, controller and resource structure, error response format (keep Laravel's JSON validation shape unless a reason appears), token model details, idempotency middleware design, exact column types and index names for `external` and the idempotency table, Czech labels of the token screen, how the batch validator reports per-item errors, test structure (extend the canary harness with a line for API access).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 7 goal and success criteria (note: timer parts removed, see boundary)
- `.planning/REQUIREMENTS.md` — AP-01 to AP-05
- `.planning/PROJECT.md` — constraints (Partner never sees time, rates or finance; UUID v7 incl. package morph columns; DB-enforced integrity)
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — Partner scope, access rules
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — `KokpitJob`, scheduled jobs, activity log allowlist
- `.planning/phases/06-time-tracking/06-CONTEXT.md` — time entry rules the API must reuse (billable default D-03, overlaps allowed D-04, billed locking D-06)

### Research
- `.planning/research/STACK.md` — Sanctum UUID morph note (line ~140), `spatie/laravel-query-builder`, `spatie/laravel-data`; the Scramble entries in STACK.md are superseded by D-17 (Scribe)
- `.planning/research/PITFALLS.md` — Partner leakage surfaces

### Repository rules
- `.claude/CLAUDE.md` — fictional data only
- `scripts/check-sensitive.sh` — must pass on every commit

No external ADRs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/TimeTracking/Actions/` (`CreateTimeEntry`, `UpdateTimeEntry`, `DeleteTimeEntry`, `MarkEntriesBilled`): single home of the rules; the API calls these. `TimeEntryInput` is the input value object.
- `app/Domain/TimeTracking/Queries/` (`RecentEntries`, `TimesheetQuery`, `EntryContextOptions`): query patterns for lists and context validation.
- `app/Domain/TimeTracking/Models/TimeEntry.php`: model to extend with `external`.
- `app/Domain/Identity/Models/User.php`: already uses `HasApiTokens`.
- `app/Domain/Shared/Auth/` (`PartnerScope`, `AccessRule`, `DeniesPartners`): isolation primitives.
- `app/Domain/Operations/Jobs/KokpitJob` and `routes/console.php`: scheduled purge of idempotency keys.
- `bootstrap/app.php`: `shouldRenderJsonWhen` already treats `api/*` as JSON; rate-limiter and priority-list middleware are configured here.

### Established Patterns
- Every Filament class declares `#[AccessRule]`; every model declares Partner scope or `#[NotPartnerScoped]`; architecture tests enforce both.
- UUID v7, `timestamptz`, DB constraints (CHECK, partial and unique indexes).
- Config values live in `config/kokpit.php`.
- Czech via `lang/cs`; code, tests and docs in English.
- Tests run in DDEV (`ddev exec vendor/bin/pest`).

### Integration Points
- No `routes/api.php` yet: `bootstrap/app.php` `withRouting` needs an `api:` entry with prefix `api/v1`.
- Token management screen is a new Filament resource/page in the Admin panel.
- Phase 8 and 10 build on time entries; the `external` column must not leak into their Partner-facing views (Admin-only anyway).

</code_context>

<specifics>
## Specific Ideas

- Primary consumer: n8n automations that every day write time reported in third-party systems (the owner works for two companies and wants one overview of total reported time). Hence batch write, the `external` object and a date-range read-back.
- The list endpoint is also used purely to verify what was recorded.

</specifics>

<deferred>
## Deferred Ideas

- Timer API (start, stop, current running; AP-03): removed from Phase 7 by the owner. Move to a later phase or backlog; update ROADMAP.md and REQUIREMENTS.md accordingly.
- Deduplication of imported entries by source and external ID: the caller handles it for now; revisit if the helper becomes a real integration.
- Idempotency key on update/delete: not needed, they are idempotent by nature.

</deferred>

---

*Phase: 7-REST API*
*Context gathered: 2026-10-14*
