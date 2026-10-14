# Phase 7: REST API - Research

**Researched:** 2026-10-14
**Domain:** Versioned, token-secured JSON API (`/api/v1`) over existing Laravel 13 / Filament 5 / PostgreSQL 18 domain actions; Sanctum tokens with abilities; idempotent create; OpenAPI via Scramble; Filament token management screen
**Confidence:** HIGH for in-repo facts, Sanctum/Scramble/query-builder behaviour (read from installed or downloaded package source); MEDIUM for the idempotency design and Scramble extension points (designed here, not yet built or run)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): time is Admin-only and a Partner never sees time, rates or prices; fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); UUID v7 keys and `timestamptz`; DB-enforced invariants (CHECK for client/project/task agreement and end after start, billed entries locked, Phase 6); domain actions `CreateTimeEntry`, `UpdateTimeEntry`, `DeleteTimeEntry` are the single place for rules, the API calls them and adds no parallel rule set; queued job base `KokpitJob`; numbering and activity log allowlist patterns.

**Tokens and token management (AP-01, AP-05)**
- **D-01:** Tokens are Sanctum personal access tokens issued to the Admin user with abilities `time:read` and `time:write`; a request whose token lacks the needed ability is refused (403). A Partner cannot create tokens and a token of a user that is not Admin gets no access.
- **D-02:** Expiry is optional per token, chosen at creation (no expiry / 30 / 90 / 365 days); the default is no expiry. Revoking is always possible.
- **D-03:** The token management screen (Filament, Admin-only, with `#[AccessRule]`) lists name, abilities, created, last used (Sanctum `last_used_at`), expiry and a Revoke action. No IP or user-agent is stored.
- **D-04:** After creation the plain-text token is shown once in a modal and never again. Creating a token requires re-confirming password/2FA first (Admin has mandatory 2FA, `EnsureAdminHasTwoFactor`). — **Reversibility:** reversible
- **D-05:** `personal_access_tokens` must carry a UUID morph column for `tokenable` (project rule: UUID v7 keys everywhere incl. package morph columns); a custom token model with `HasUuids` registered via `Sanctum::usePersonalAccessTokenModel()` is the expected route (see STACK.md, Sanctum row). Exact approach is Claude's discretion. — **Reversibility:** costly — every issued token and the token table depend on the key type.

**Idempotency key (AP-05)**
- **D-06:** `Idempotency-Key` header is optional and supported only on create requests (create a time entry, batch create). Update and delete are idempotent by nature and do not use it. (Timer start is out of the phase, see boundary.)
- **D-07:** A repeated key with the same request body returns the stored original response (same status and body) plus header `Idempotent-Replayed: true`. The same key with a different body returns 422. A concurrent second request with the same key while the first is running returns 409.
- **D-08:** Keys are stored in a PostgreSQL table with a unique index on (token, key) and a body hash; a key lives 24 hours (value in `config/kokpit.php`) and a scheduled job purges expired keys. Redis is not the source of truth. — **Reversibility:** costly — clients rely on the replay contract and the table is part of the API surface.

**Response shape, filtering and lookups (AP-02, AP-04)**
- **D-09:** A time entry is returned with `duration_seconds` (whole seconds, exact storage per TI-08) and `started_at` / `ended_at` in ISO 8601. Writes take `started_at` + `ended_at` (and `duration_seconds` for the manual-entry shape the UI allows); the API refuses what the UI refuses (locked billed entries, inconsistent client/project/task, end before start). — **Reversibility:** one-way — published response contract once clients (n8n) depend on it.
- **D-10:** The entry response includes `billable` and the billed state but NO rates, prices or amounts; tokens carry only `time:*` abilities and there is no finance ability in this phase.
- **D-11:** List endpoints use cursor pagination (default 50, max 200 per page), default sort newest first. Time entries filter by client, project, task, billable, billed and a date range from/to (`filter[...]`, `sort`) using `spatie/laravel-query-builder`. The list also serves as the read-back to verify what was recorded.
- **D-12:** Lookups of clients, projects and tasks are read-only. Detail resolves by UUID or by key (`/tasks/KEY-N`; project by key). The list supports `filter[name]` as a partial match. A name is search only and never identifies a resource for a write.
- **D-13:** Entry writes reference client, project and task by UUID or key (`KEY-N` for tasks, project key); names are not accepted.

**Batch write and external data (AP-02 extension)**
- **D-14:** Batch create writes up to 100 entries in one request (limit in `config/kokpit.php`; more returns 422). It is partial-success: every valid item is saved, each in its own transaction through the same `CreateTimeEntry` action as the single create, and invalid items are skipped. The response lists only the items that failed (item index plus errors), together with counts of saved and failed items. Exact status codes (for example 201 when all saved, 207 when mixed, 422 when none saved) and the response layout are Claude's discretion. Because the caller does its own deduplication (D-15), a retry of a mixed batch must be safe with `Idempotency-Key`: the stored response is replayed (D-07). — **Reversibility:** costly — importers rely on the partial-success contract.
- **D-15:** A time entry gets one optional `jsonb` column `external` for helper data from third-party systems (for example source system and its ID). It must be a JSON object (not array or scalar) with a size limit (about 4 KB, value in `config/kokpit.php`), accepted on single create, batch create and update, and returned by the API. It is not shown in the UI, gets no unique index and no deduplication: the caller (n8n) guards against duplicates itself. It is not part of the activity log allowlist. — **Reversibility:** costly — a new column on `time_entries` plus the contract; changing it later is a migration.

**Rate limiting and documentation (AP-05)**
- **D-16:** Rate limits per token: 120 requests/min for reads, 60 requests/min for writes, values in `config/kokpit.php`. Exceeding returns 429 with `Retry-After` and standard `X-RateLimit-*` headers.
- **D-17:** OpenAPI documentation is generated by Scramble (`dedoc/scramble`, free core). `/docs/api` and `/docs/api.json` are served only to a logged-in Admin through the `viewApiDocs` gate. The generated spec is committed to the repository (fictional/generic values only) and CI fails when the generated spec differs from the committed one. The spec must document filters, the Sanctum bearer scheme, abilities and 429/422/409 responses (STACK.md notes Scramble may need small extensions for query-builder filters).

### Claude's Discretion

Class and file names, controller and resource structure, error response format (keep Laravel's JSON validation shape unless a reason appears), token model details, idempotency middleware design, exact column types and index names for `external` and the idempotency table, Czech labels of the token screen, how the batch validator reports per-item errors, test structure (extend the canary harness with a line for API access).

### Deferred Ideas (OUT OF SCOPE)

- Timer API (start, stop, current running; AP-03): removed from Phase 7 by the owner. Move to a later phase or backlog; update ROADMAP.md and REQUIREMENTS.md accordingly.
- Deduplication of imported entries by source and external ID: the caller handles it for now; revisit if the helper becomes a real integration.
- Idempotency key on update/delete: not needed, they are idempotent by nature.

Boundary (from CONTEXT domain section): not in this phase are timer endpoints, write access to clients/projects/tasks (lookups are read-only), invoices/finance in the API, webhooks, any Partner access to the API.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AP-01 | REST API `/api/v1` with Sanctum tokens carrying abilities (`time:read`, `time:write`); only Admin creates tokens | Standard Stack (Sanctum 4.3.3 already installed, UUID token model already exists); Pitfalls 1-4 (session guard bypass of abilities, malformed bearer 500, Admin-only guard callback, ability middleware alias); Pattern 1 and 6 |
| AP-02 | Time entries list with filters, detail, create, update, delete; same domain actions and rules as the UI | Pattern 2 (thin controllers over `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry`), Pattern 3 (query-builder list), Pitfalls 5-9 (DomainException is a 500, `duration_seconds` is not a UI input, PG integer overflow, uuid casts in routes, offset-less instants), batch Pattern 5, `external` Pattern 4 |
| AP-04 | Read-only lookups of clients, projects and tasks (UUID, key, name); tasks addressable by key | Pattern 3 (lookup resolver), Pitfalls 8 and 10 (uuid vs key branching, comma-split partial filter), Don't Hand-Roll |
| AP-05 | Token management UI, rate limiting, idempotency key support, OpenAPI documentation | Pattern 6 (Filament token screen), Pattern 7 (idempotency), Pattern 8 (rate limiters), Pattern 9 (Scramble, spec drift test), Pitfalls 11-15 |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` (read this session) and `.planning/config.json`.

- Public AGPL-3.0 repo: **fictional data only**. No real names, rates, company IDs, hostnames, tokens, absolute personal paths in code, tests, fixtures, docs, `.planning/` or commit messages. Use `example.com` addresses, the company ID `12345678`, paths like `/Users/example/`, and test fakes assembled at runtime from fragments (see `exampleEmail()` in `tests/Pest.php`). The committed OpenAPI spec and every example in it must follow this rule; its `servers` URL must be a literal `example.com` URL.
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`; the lefthook pre-commit hook runs the checker and gitleaks; never bypass (`--no-verify`, `LEFTHOOK=0`).
- All dependencies AGPL-compatible, no paid/closed packages (Scramble Pro is forbidden; free core only). `composer check-licenses` gates this (`scripts/check-licenses.php`).
- Partner must never see time, rates, prices or finance, nor another client's data: enforced by Policies and global query scopes, not UI hiding. Every new model declares Partner scope or `#[NotPartnerScoped]`; every Filament class declares `#[AccessRule]` (architecture tests enforce both).
- UUID v7 keys everywhere including package morph columns; FK, unique, partial indexes and CHECK constraints in the DB; `timestamptz` only.
- Tests run in DDEV: `ddev exec vendor/bin/pest`. The host has no DB (user memory note).
- GSD workflow: file edits only through a GSD command. Czech for user-facing panel strings (`lang/cs`); code, tests and `.planning/` content in English.
- Config values live in `config/kokpit.php`. Jobs extend `KokpitJob` and declare `#[Idempotent(how: ...)]`. Larastan level 8 over `app`, `routes`, `database/factories`, `database/seeders`.

## Summary

Phase 7 is mostly assembly, not invention: the domain Actions, the UUID Sanctum token model and its migration, the `HasApiTokens` trait on `User`, JSON rendering for `api/*` and a fail-closed Partner isolation layer all exist already. What is missing is the HTTP shell (`routes/api.php`, controllers, FormRequests, API Resources), two small schema additions (`time_entries.external`, an idempotency-key table), the Admin token screen, and three packages-or-config items (`spatie/laravel-query-builder`, `dedoc/scramble`, a published `config/sanctum.php`). Both packages resolve cleanly against the current lock (`composer require --dry-run` succeeded: `dedoc/scramble v0.13.47`, `spatie/laravel-query-builder 7.3.5`; both MIT).

Five findings change how the phase must be planned and are not visible from CONTEXT.md alone:

1. **D-05 is already done.** `personal_access_tokens` has a `uuid` primary key and `uuidMorphs('tokenable')`, and `App\Domain\Identity\Models\PersonalAccessToken` (HasUuids) is registered in `ModelConventionsServiceProvider`. No token migration is needed. But the shipped Sanctum defaults are unsafe for this API: `config('sanctum.guard')` defaults to `['web']`, so a browser session authenticates API requests as a `TransientToken`, whose `can()` returns `true` for every ability (verified in `vendor/laravel/sanctum`). A published `config/sanctum.php` with `'guard' => []` is mandatory. Also, `findToken()` on a UUID key throws a `QueryException` (HTTP 500) for a malformed bearer such as `abc|def` (reproduced in DDEV), so the custom token model must validate the id part.
2. **`duration_seconds` is not a UI input.** D-09 says writes take `duration_seconds` "for the manual-entry shape the UI allows", but the UI and `CreateTimeEntry` accept only `started_at` and `ended_at`; `duration_seconds` is a stored generated column (read-only). Recommended resolution: accept `duration_seconds` on writes as an alternative to `ended_at` (exactly one of the two), mapped to `ended_at = started_at + duration` in the request layer before calling the unchanged Action. Needs owner confirmation (Open Question 1).
3. **Domain refusals are not all `ValidationException`.** A billed (locked) entry is a `DomainException`, which Laravel renders as HTTP 500. The API must catch it around the Action call and answer 409.
4. **The Actions know nothing about `external` or key-based references.** `TimeEntryInput::context()` takes UUIDs only and `CreateTimeEntry`/`UpdateTimeEntry` ignore unknown keys. The API layer resolves project key / task `KEY-N` to UUIDs before calling the Action (a small resolver), and the Actions get one additive, backwards-compatible `external` key (plus a `TimeEntryInput::external()` validator in the same style as `description()`).
5. **A PostgreSQL integer overflow is a 500 waiting to happen.** `duration_seconds` is `(EXTRACT(EPOCH FROM (ended_at - started_at)))::integer`; an `ended_at` more than about 68 years after `started_at` raises "integer out of range" at write time. The API request layer must bound the interval.

**Primary recommendation:** Build `/api/v1` as thin controllers calling the existing Actions, behind `auth:sanctum` + an Admin-only token validity callback + `abilities:` middleware + a per-token named rate limiter; install `spatie/laravel-query-builder` and `dedoc/scramble`; store idempotency claims with an atomic `INSERT ... ON CONFLICT DO NOTHING` row and replay only successful responses; commit the Scramble spec and guard drift with a Pest test (CI already runs Pest).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Token issuance, listing, revocation (screen) | Frontend Server (Filament/Livewire panel) | API / Backend (domain action `IssueApiToken`) | Admin-only panel page; the token is generated server-side, the modal only displays it once; rules (Admin only, TOTP + password) live in a domain action and the Livewire handler, never in the view |
| Token authentication and ability checks | API / Backend (Sanctum guard + middleware) | Database (token hash, `expires_at`) | Guard callback rejects non-Admin or deactivated users; `abilities:` middleware enforces `time:read`/`time:write` per route |
| Time entry create/update/delete rules | API / Backend (existing domain Actions) | Database (CHECK, composite FKs, frozen-row trigger KP001) | Single place for rules (locked decision); DB is the backstop |
| Reference resolution (UUID or key to UUID) | API / Backend (resolver service) | Database (unique `projects.key`, `tasks.reference`) | Actions accept UUIDs only; keys are an API convenience |
| Filtering, sorting, cursor pagination | API / Backend (query-builder) | Database (indexes on `user_id, started_at`; `client_id, started_at`; `project_id, started_at`) | Allow-listed filters over Eloquent; cursor pagination needs a deterministic total order |
| Idempotency claim and replay | Database (unique `(token_id, key)` row is the lock) | API / Backend (middleware) | D-08: PostgreSQL is the source of truth, not Redis |
| Rate limiting | API / Backend (named limiters keyed by token id) | Cache (Redis `CACHE_STORE`) | Laravel `throttle:` middleware; counter state in the shared cache store |
| OpenAPI generation and docs gate | API / Backend (Scramble + `viewApiDocs` gate) | Browser (Stoplight Elements UI) | Spec generated from routes/FormRequests/Resources; UI is a stock third-party web component |
| Spec drift guard | CI / test suite (Pest) | Repository (committed spec file) | CI already runs `vendor/bin/pest` against PostgreSQL 18 |
| Purge of expired idempotency keys | API / Backend (`KokpitJob` on the scheduler) | Database | D-08; same scheduled-job pattern as the existing jobs |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/sanctum` | 4.3.3 (installed) `[VERIFIED: composer show]` | Bearer tokens with abilities | Already in `composer.json` (`^4.3`); `User` uses `HasApiTokens`; UUID token model already registered |
| `spatie/laravel-query-builder` | `^7.3` (7.3.5) `[VERIFIED: composer require --dry-run resolved 7.3.5; Packagist: MIT, PHP ^8.3, illuminate ^12\|^13]` | `filter[...]`, `sort` on the time entry list and the lookups | Locked by D-11; STACK.md lists it |
| `dedoc/scramble` | `~0.13.47` (v0.13.47) `[VERIFIED: composer require --dry-run resolved v0.13.47; Packagist: MIT, illuminate ^10-^13, PHP ^8.1]` | OpenAPI 3.1 from routes, FormRequests, Resources | Locked by D-17; pre-1.0, so pin `~0.13.47` (STACK.md). Put in `require`, not `require-dev` (docs are served in production) |
| Laravel framework | 13.35.0 (installed) | Routing (`withRouting(api:..., apiPrefix: 'api/v1')`), `RateLimiter`, API Resources, FormRequest | Existing |
| Filament | 5.10.1 (installed) | Token management screen (Resource, Actions, modals) | Existing; UI-SPEC is built on stock components |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `danharrin/livewire-rate-limiting` | installed (used in `app/Filament/Pages/Auth/AcceptInvitation.php`) | Throttle identity confirmation attempts in the create-token modal (UI-SPEC: recommended 5/min per user) | In the Filament token action handler |
| `pragmarx/google2fa` via Filament `AppAuthentication` | installed | TOTP verification with replay protection | `AppAuthentication::verifyCode($code, null, shouldPreventCodeReuse: true)` (see Pattern 6) |
| Pest 5.3 / PHPUnit 13 | installed | Tests | Existing harness |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| API Resources (`JsonResource`) with explicit allow-list arrays | `spatie/laravel-data` (listed in STACK.md for API responses) | Not locked by CONTEXT. API Resources are what Scramble infers best and add no dependency; do not add `laravel-data` for this phase |
| Spatie query-builder for lookups | plain Eloquent `where` | Locked decision applies to time entries (D-11); for lookups the same package keeps `filter[name]` consistent. Either is acceptable; use query-builder for consistency |
| Scramble Stoplight Elements UI (default) | Scalar renderer (also bundled in 0.13.47) | UI-SPEC fixes "stock Scramble UI, theme system"; both renderers load their JS from a CDN (Pitfall 14) |
| Redis idempotency store | PostgreSQL table | Locked: PostgreSQL (D-08) |

**Installation:**
```bash
ddev composer require "spatie/laravel-query-builder:^7.3" "dedoc/scramble:~0.13.47"
ddev exec php artisan vendor:publish --tag=scramble-config      # config/scramble.php
ddev exec php artisan vendor:publish --tag=sanctum-config       # config/sanctum.php (then set 'guard' => [])
ddev exec php artisan vendor:publish --provider="Spatie\QueryBuilder\QueryBuilderServiceProvider" --tag=query-builder-config
```
`[ASSUMED]` publish tag names (`scramble-config`, `sanctum-config`, `query-builder-config`): the packages' README tags were not exercised; if a tag differs, write the config file by hand from the vendor copy (all three have a `config/*.php` in the package).

**Version verification (this session):** `ddev composer show -a dedoc/scramble` lists v0.13.47 as the latest 0.13 tag; `ddev composer show -a spatie/laravel-query-builder` lists 7.3.5 as latest. `composer require --dry-run` printed `Locking dedoc/scramble (v0.13.47)` and `Locking spatie/laravel-query-builder (7.3.5)` with no conflicts and "No security vulnerability advisories found"; `git status` afterwards showed no change to `composer.json`/`composer.lock`.

## Package Legitimacy Audit

The `gsd-tools package-legitimacy check` seam supports only `npm|pypi|crates` (it printed `Usage: ... --ecosystem <npm|pypi|crates>` for `composer`/`packagist`), so the audit below was done by hand against Packagist and the downloaded package sources. Both packages are named in the project's own STACK.md (written from Packagist metadata on 2026-10-06) and locked by user decisions D-11 and D-17, which is why they are not tagged `[ASSUMED]`.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| `dedoc/scramble` | Packagist | ~4 yrs (first tag 2022-08) | 17.7M total / 2.4M per month | github.com/dedoc/scramble | OK (manual: MIT, not abandoned, 2.2k stars) | Approved, pin `~0.13.47`, `require` |
| `spatie/laravel-query-builder` | Packagist | ~8.5 yrs (2018-01) | 36.4M total / 1.9M per month | github.com/spatie/laravel-query-builder | OK (manual: MIT, not abandoned, 4.5k stars) | Approved, `^7.3`, `require` |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
**Not recommended for this phase:** `spatie/laravel-data` (no need; API Resources suffice) and any Scramble Pro add-on (paid, forbidden by project constraint).
**Transitive notes:** Scramble requires `nikic/php-parser ^5`, `phpstan/phpdoc-parser`, `spatie/laravel-package-tools`; the dry-run resolved without new conflicts. Run `ddev composer check-licenses` after install (Scramble UI = Stoplight Elements, Apache-2.0 per STACK.md, MEDIUM: not re-verified in the bundle; it is loaded from a CDN, not vendored).

## Architecture Patterns

### System Architecture Diagram

```
 n8n / external tool
        |  HTTPS  Authorization: Bearer <uuid>|<secret>   [Idempotency-Key on create]
        v
 +--------------------------------------------------------------------------+
 | routes/api.php  (prefix api/v1, group "api": no session, no CSRF)        |
 |  1. auth:sanctum                                                         |
 |       Guard (config sanctum.guard = [] -> bearer only, no session)       |
 |       PersonalAccessToken::findToken  (UUID-validated, 401 if malformed) |
 |       Sanctum::authenticateAccessTokensUsing: Admin role AND not         |
 |       deactivated AND not expired  --> else 401                          |
 |  2. abilities:time:read | abilities:time:write   --> 403 MissingAbility   |
 |  3. throttle:api-read (120/min) | throttle:api-write (60/min), key=token  |
 |        --> 429 + Retry-After + X-RateLimit-*                             |
 |  4. (create routes only) IdempotencyKey middleware                       |
 |        claim row (token_id,key) --+--> replay stored 2xx + header        |
 |                                   +--> 422 body hash differs             |
 |                                   +--> 409 first request still running   |
 +-----------------------------------+--------------------------------------+
                                     v
        FormRequest (shape, ISO 8601 with offset, uuid-or-key, sizes)
                                     v
        EntryContextResolver (project key / task KEY-N -> UUID; clients UUID only)
                                     v
   +-----------------------------+   +--------------------------------------+
   | Controller (thin)           |-->| CreateTimeEntry / UpdateTimeEntry /  |
   |  catches DomainException    |   | DeleteTimeEntry  (existing Actions)  |
   |  -> 409 locked              |   |  Gate: AdminOnlyPolicy, TimeEntryInput|
   +--------------+--------------+   +------------------+-------------------+
                  |                                     v
                  |                  PostgreSQL: composite FKs, CHECKs,
                  |                  frozen-row trigger (KP001), generated
                  |                  duration_seconds, external jsonb CHECK
                  v
   List/detail:  QueryBuilder::for(TimeEntry)  allowedFilters/allowedSorts
                 -> cursorPaginate(<=200) -> TimeEntryResource (allow-list, no money)
   Lookups:      QueryBuilder over Client / Project / Task (selectable only)
                 -> LookupResources (allow-list)

 Panel (session, Filament SPA)               Scheduler (onOneServer)
   ApiTokenResource (list page only)          PurgeExpiredIdempotencyKeys (KokpitJob)
   createToken action: password + TOTP        daily, deletes expires_at < now()
   -> IssueApiToken -> user->createToken
   one-time modal -> token in #[Locked] prop

 Docs:  GET /docs/api, /docs/api.json  (web group + RestrictedDocsAccess + viewApiDocs gate)
 CI:    Pest test: scramble:export --stdout == committed spec file
```

### Recommended Project Structure

```
app/
├── Domain/
│   ├── Identity/
│   │   ├── Actions/IssueApiToken.php         # Admin check, ability allow-list, expiry, createToken
│   │   ├── ApiAbility.php                    # backed enum: TimeRead='time:read', TimeWrite='time:write' (+ label)
│   │   └── Models/PersonalAccessToken.php    # existing; add findToken() UUID guard
│   └── TimeTracking/
│       ├── Actions/*                          # existing; add additive `external` key
│       ├── TimeEntryInput.php                 # add external() validator (object, size)
│       └── Api/
│           ├── EntryContextResolver.php       # UUID|key -> UUID for project/task
│           └── IdempotencyKey.php             # model (KokpitModel, #[NotPartnerScoped])
├── Http/
│   ├── Controllers/Api/V1/                    # TimeEntryController, TimeEntryBatchController, ClientController, ProjectController, TaskController
│   ├── Middleware/                            # EnsureIdempotency (name is discretion)
│   ├── Requests/Api/V1/                       # StoreTimeEntryRequest, UpdateTimeEntryRequest, BatchStoreTimeEntryRequest, ListTimeEntriesRequest, lookup list requests
│   └── Resources/Api/V1/                      # TimeEntryResource, ClientResource, ProjectResource, TaskResource (explicit allow-lists)
├── Filament/Resources/ApiTokenResource.php (+ Pages/ListApiTokens.php)
└── Providers/ApiServiceProvider.php           # limiters, Sanctum callback, viewApiDocs gate, Scramble config (or extend existing providers)
config/kokpit.php   -> 'api' => [...]          # rate limits, idempotency ttl, batch max, external max bytes, page sizes
config/sanctum.php  -> 'guard' => []
config/scramble.php
database/migrations/..._add_external_to_time_entries_table.php
database/migrations/..._create_api_idempotency_keys_table.php
docs/api/openapi.json                          # committed spec (path is discretion)
routes/api.php
tests/Feature/Api/*, tests/Isolation/ApiAccessTest.php (route walk), tests/Concurrency/IdempotencyConcurrencyTest.php
```

Suggested slicing for the planner (dependency order): (W0) packages, `config/sanctum.php`, `bootstrap/app.php` routing + middleware aliases, migrations, `config/kokpit.php` keys; (W1) token layer: model `findToken`, Sanctum callback, `IssueApiToken`, Filament screen; (W2) time entry endpoints + resolver + Action `external`; (W3) lookups; (W4) idempotency middleware + batch + purge job; (W5) rate limiters, Scramble config/extensions, spec file + drift test, route-walk and canary additions.

### Pattern 1: Token-only Sanctum, Admin-only validity, abilities
**What:** Make the API accept bearer tokens only, accept only tokens whose owner is an active Admin, and gate each route group by ability.
**When to use:** every `/api/v1` route.
**Example:**
```php
// config/sanctum.php  (published)           // Source: vendor/laravel/sanctum/src/Guard.php:25-32 reads config('sanctum.guard', 'web')
'guard' => [],          // iterate nothing: no session user, so no TransientToken that "can" everything
'expiration' => null,   // per-token expires_at is checked independently (Guard.php isValidAccessToken)

// app/Providers/ApiServiceProvider.php boot()   // Source: vendor/laravel/sanctum/src/Sanctum.php:123 authenticateAccessTokensUsing
Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $isValid): bool {
    $user = $token->tokenable;

    return $isValid
        && $user instanceof User
        && $user->deactivated_at === null
        && $user->hasRole(RoleName::Admin->value);
});

// bootstrap/app.php  (Sanctum 4 registers no aliases: SanctumServiceProvider only prepends a priority entry)
->withRouting(web: ..., api: __DIR__.'/../routes/api.php', apiPrefix: 'api/v1', commands: ..., health: '/up')
$middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class]);

// routes/api.php
Route::middleware(['auth:sanctum', 'abilities:time:read', 'throttle:api-read'])->group(function (): void { /* GET routes */ });
Route::middleware(['auth:sanctum', 'abilities:time:write', 'throttle:api-write'])->group(function (): void { /* POST/PATCH/DELETE */ });
```
`abilities:time:read` is parsed by Laravel as middleware `abilities` with parameter `time:read` (only the first colon splits) `[ASSUMED: standard Laravel parameter parsing, covered by a test]`. `auth:sanctum` runs `shouldUse('sanctum')`, so `Auth::user()` and therefore `PartnerContext::isAdmin()` resolve the token user `[VERIFIED: vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php `authenticate()` returns `$this->auth->shouldUse($guard)`]`. Authenticate precedes `ThrottleRequests` in the priority list `[VERIFIED: Kernel.php:103-115]`, so limiter closures can read the token.

### Pattern 2: Thin controllers over the existing Actions
**What:** FormRequest validates shape only; resolver converts keys to UUIDs; the Action owns every rule; the controller maps exceptions.
**Example (illustrative):**
```php
// Source: app/Domain/TimeTracking/Actions/CreateTimeEntry.php (signature handle(User $actor, array $data): TimeEntry)
public function store(StoreTimeEntryRequest $request, CreateTimeEntry $create): JsonResponse
{
    $entry = $create->handle($request->user(), $request->entryData());   // entryData(): ids resolved, ended_at derived

    return TimeEntryResource::make($entry)->response()->setStatusCode(201);
}
// ValidationException -> Laravel renders 422 {message, errors} (same field keys as the Actions: task_id, project_id, client_id, started_at, ended_at, description)
// DomainException (locked, KP001)  -> catch in the controller, answer 409 {message}   (Pitfall 5)
// AuthorizationException           -> 403 (Gate AdminOnlyPolicy; Admin passes KokpitPolicy::before)
```
Keep the field names `client_id`, `project_id`, `task_id` on writes and let each accept a UUID or a key (project key `^[A-Z]{2,6}$`, task `^[A-Z]{2,6}-[0-9]+$`; clients have no key). Rationale: the Actions key their errors by exactly these names, so no error-key remapping is needed. `[ASSUMED]` naming choice, planner may prefer `project`/`task` fields and remap errors.

### Pattern 3: List and lookup endpoints with query-builder and cursor pagination
**What:** Allow-listed filters, explicit sorts, deterministic total order, size cap, FormRequest to validate query parameters (also feeds Scramble).
**Example:**
```php
// Source: spatie/laravel-query-builder 7.3.5 docs/features/filtering.md, sorting.md
$entries = QueryBuilder::for(TimeEntry::query()->with(['client', 'project', 'task']))
    ->allowedFilters([
        AllowedFilter::custom('client', new ContextRefFilter('client')),     // UUID, or unknown -> empty
        AllowedFilter::custom('project', new ContextRefFilter('project')),   // UUID or key
        AllowedFilter::custom('task', new ContextRefFilter('task')),         // UUID or KEY-N
        AllowedFilter::exact('billable'),                                   // maps 'true'/'false'/1/0 to bool
        AllowedFilter::callback('billed', fn (Builder $q, $v) => $q->where('billing_state', filter_var($v, FILTER_VALIDATE_BOOL) ? 'billed' : 'unbilled')),
        AllowedFilter::callback('from', fn (Builder $q, $v) => $q->where('time_entries.started_at', '>=', $bounds->start($v))),
        AllowedFilter::callback('to', fn (Builder $q, $v) => $q->where('time_entries.started_at', '<', $bounds->endExclusive($v))),
    ])
    ->allowedSorts('started_at', 'created_at')
    ->defaultSort('-started_at')
    ->orderByDesc('time_entries.id')              // unique tiebreaker for the cursor
    ->cursorPaginate($request->perPage());        // validated 1..200, default 50 from config
```
- Date range: the panel already treats a picked day range as inclusive days bounded by Europe/Prague midnights (`TimeEntryResource::periodBounds()` / `pragueMidnight()`, `app/Filament/Resources/TimeEntryResource.php:529-575`). Mirror it: a date-only value (`Y-m-d`) is a Prague calendar day (`from` = that day's midnight, `to` = the midnight after the day, so `to` is inclusive of the named day); a full ISO 8601 instant with offset is used exactly. `[ASSUMED]` semantics, confirm with the owner (Open Question 3).
- Entries of archived clients/projects/tasks remain readable (`TimeEntry` relations use `withTrashed()`), so the list filters never join the soft-delete scopes.
- Lookups: restrict to the same "selectable" sets the pickers use (`Project::selectable()`, non-archived clients, non-archived tasks whose project is selectable; see `EntryContextOptions`). Detail routes resolve through one resolver: `Str::isUuid($v)` -> `whereKey`, project key regex -> `where('key', $v)`, task regex -> `where('reference', $v)`, anything else -> 404. Never pass an unvalidated string to a uuid column (Pitfall 8).
- Explicit response allow-lists. Client: `id`, `name` (no `hourly_rate*`, payment terms, tax numbers, invoice e-mail). Project: `id`, `client_id`, `key`, `name`, `status`, `priority` (billing lives in `project_billing`, never join it). Task: `id`, `reference`, `title`, `project_id`, `status`, `priority` (billing overrides live in `task_billing`). `[ASSUMED]` exact field set, discretion.

### Pattern 4: `external` jsonb column
**What:** one nullable `jsonb` column, object-only by CHECK, size-limited by config in the application.
**Example:**
```php
// migration (additive; the frozen-row trigger compares to_jsonb(NEW) - allowed, so the new column is frozen on billed rows automatically,
// Source: database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php:45
//   `IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN`)
DB::statement('ALTER TABLE time_entries ADD COLUMN external jsonb');
DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_external_object_check CHECK (external IS NULL OR jsonb_typeof(external) = 'object')");
```
- Model: not in `#[Fillable]`, not in `#[LoggedAttributes]` (D-15). Use `forceFill` in the Actions like the other columns. Use the `object` cast (or a custom cast), **not** `array`: `{}` decodes to `[]` in PHP arrays, and `json_encode([])` is `[]`, a jsonb array, which the CHECK rejects. Validate object-ness from the raw body (`json_decode($request->getContent())` gives `stdClass` for `{}`), because `$request->input()` already lost the distinction. Reject arrays/scalars with 422 on `external`.
- Size: `strlen(json_encode($external)) <= config('kokpit.api.external_max_bytes')` (4096) plus a nesting-depth cap. Put the check in `TimeEntryInput::external()` next to `description()` so single create, batch and update behave identically.
- Update semantics: key absent keeps the value; explicit `null` clears it (same as the Action's "absent key keeps" rule).

### Pattern 5: Batch create (partial success)
**What:** validate the envelope strictly (array, 1..100 items), then process each item independently.
**Example (illustrative):**
```php
// Source: CONTEXT D-14; CreateTimeEntry already wraps its own DB::transaction per call
foreach ($items as $index => $item) {
    try {
        $create->handle($actor, $resolver->resolve($item));   // own transaction per item
        $saved++;
    } catch (ValidationException $e) {
        $failed[] = ['index' => $index, 'errors' => $e->errors()];
    }
}
```
Per-item shape validation (types, ISO format, uuid-or-key syntax) uses a `Validator::make($item, $rules)` per item, merged into the same `errors` list, so an item with a malformed field and an item rejected by the Action report in one format. Recommended statuses (discretion, D-14): 201 all saved, 207 some saved and some failed, 422 none saved or envelope invalid. Body: `{ "saved": n, "failed": m, "errors": [ { "index": i, "errors": { field: [messages] } } ] }`; saved items are only counted, per D-14. Do not catch `DomainException` as a batch item failure silently: a batch item targeting nothing locked cannot hit it (creates only), but still convert any unexpected exception to a 500 for the whole request rather than swallowing it.

### Pattern 6: Token management screen (Filament)
**What:** a list-only Resource (`ApiTokenResource`) on `PersonalAccessToken`, a header action `createToken` with a form (name, abilities, expiry, password, TOTP), a row action revoke, the one-time modal.
- Access: `#[AccessRule(Audience::AdminOnly, reason: ...)]` plus `use EnforcesResourceAccessRule` (the pattern of `TimeEntryResource`, `app/Filament/Resources/TimeEntryResource.php:80-86`). `strictAuthorization()` is on (`AdminPanelProvider`), so `PersonalAccessToken` needs a policy: add `Gate::policy(PersonalAccessToken::class, AdminOnlyPolicy::class)` in `AccessServiceProvider` (not registered today). The model already declares `#[NotPartnerScoped(reason: ...)]` (`app/Domain/Identity/Models/PersonalAccessToken.php:19`), so `tests/Arch/ModelDeclarationTest.php` needs no change for it.
- Query: `getEloquentQuery()` restricted to `tokenable_type = 'user'` (morph alias) and `tokenable_id = signed-in user id`; the revoke handler re-reads the token with that same constraint on the server before deleting.
- Identity confirmation (UI-SPEC U-5): `Hash::check($password, $user->password)` then
  ```php
  // Source: vendor/filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php:173-228 (verifyCode with $shouldPreventCodeReuse = true stores the last accepted timestep in cache under a lock)
  AppAuthentication::make()->verifyCode($code, null, shouldPreventCodeReuse: true)   // uses Filament::auth()->user()
  ```
  The code must be the TOTP, not a recovery code. Throttle with `WithRateLimiting` (already used by `AcceptInvitation`). A user without a stored secret fails closed (generic failure toast). Test environments run with `KOKPIT_REQUIRE_ADMIN_2FA=false` (`phpunit.xml`), so tests must set a secret on the Admin explicitly and compute a code with `PragmaRX\Google2FA\Google2FA`.
- Issuing: `IssueApiToken` domain action takes the actor, name, abilities (validated against the `ApiAbility` enum values only), optional `expires_at`; calls `$user->createToken($name, $abilities, $expiresAt)`. The plain token (`<uuid>|<40 random chars + 8 crc32b hex>` = 85 characters; UI-SPEC says "77", the real length is 36+1+40+8 `[VERIFIED: vendor/laravel/sanctum/src/HasApiTokens.php generateTokenString/createToken]`) is held only in a `#[Locked]` Livewire property and cleared on close. Expiry choices map to `now(FilamentTimezone)->addDays(n)->endOfDay()` converted to UTC (UI-SPEC: end of that day in Europe/Prague).
- Optionally set `token_prefix` (for example `kpk_`) in `config/sanctum.php` so the gitleaks/secret scanners recognise leaked tokens; Sanctum puts it in front of the random part, so the displayed token becomes `<uuid>|kpk_<...>`. `[ASSUMED]` nice-to-have, not required by any decision; if adopted, add a matching `.gitleaks.toml` rule.
- U-12 (activity log row on token creation/revocation, name and abilities only): the activity log is allowlist-only (`LogsAllowlistedActivity`; `tests/Arch/ActivityAllowlistTest.php` fixes the list of logging models). A manual `activity()->log()` call from `IssueApiToken` avoids touching that list. Never log the token or hash.

### Pattern 7: Idempotency (middleware + PostgreSQL row as the lock)
**What:** claim before work, complete after, replay only after success.
**Table** `api_idempotency_keys`: `id uuid pk default uuidv7()`, `token_id uuid not null` FK `personal_access_tokens(id)` ON DELETE CASCADE (derived data of the token; revoking it makes its keys useless), `key varchar(255) not null`, `request_hash char(64) not null` (sha256 of method + path + raw body), `locked_at timestamptz not null`, `response_status smallint null`, `response_body jsonb null` (or text), `completed_at timestamptz null`, `expires_at timestamptz not null` indexed, `timestampsTz`. Unique index `(token_id, key)` (D-08). The model is a `KokpitModel` with `#[NotPartnerScoped(reason: ...)]`, needs a `MorphMap::MAP` alias and a line in `tests/Arch/ModelDeclarationTest.php` (`appModels()` list is exact). Escape-hatch test forbids `DB::table(`, so use the Eloquent builder (`IdempotencyKey::query()->insertOrIgnore([...])` returns the affected row count).
**Algorithm:**
1. No header: pass through. Header present but malformed (empty, over 255, non-printable): 422.
2. Claim: `insertOrIgnore` of the in-progress row in its own statement (autocommit, **not** inside the request's transaction; an uncommitted row would block other connections on the unique index instead of returning 409). Inserted: proceed to handle.
3. Not inserted: read the existing row. `request_hash` differs: 422. Completed: return the stored status/body plus `Idempotent-Replayed: true`. In progress (not completed) and `locked_at` newer than a stale threshold (suggest 120 s, in config): 409. In progress but stale (a crashed request): take over with an atomic `UPDATE ... SET locked_at = now() WHERE id = ? AND completed_at IS NULL AND locked_at < now() - interval` (0 rows = someone else took over: 409).
4. After the controller returns: a 2xx response is stored (`response_status`, `response_body`, `completed_at`). A non-2xx or an exception: delete the claim so the client can retry with a corrected request and the same key. This is a recommendation, not literal D-07 text ("returns the stored original response"); it keeps a 422 from poisoning a key. Flag in the plan; confirm with the owner (Open Question 4).
5. Residual risk (document, do not paper over): a crash after the entry commit but before the response is stored lets a stale takeover create a duplicate; a batch is not atomic across items, so a crash mid-batch can leave some items saved. D-15 already makes the caller responsible for deduplication.
**Purge:** `PurgeExpiredIdempotencyKeys extends KokpitJob` with `#[Idempotent(how: 'deletes rows past expires_at; a second run finds nothing to delete')]`, scheduled in `routes/console.php` with `->daily()->name('kokpit-api-idempotency-prune')->onOneServer()`. Update `tests/Arch/JobContractTest.php` (`EXPECTED_APPLICATION_JOBS` is an exact list) and the known-events list in `tests/Feature/Operations/ScheduleOnOneServerTest.php`. TTL `config('kokpit.api.idempotency_ttl_hours')` = 24.

### Pattern 8: Rate limiters keyed by token
```php
// Source: AppServiceProvider already registers RateLimiter::for('invitation', ...) (app/Providers/AppServiceProvider.php, boot());
// ThrottleRequests md5(limiterName . key) separates buckets: vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:134
RateLimiter::for('api-read', fn (Request $r): Limit => Limit::perMinute((int) config('kokpit.api.rate_limit.read'))->by($r->user()?->currentAccessToken()?->getKey() ?? $r->ip()));
RateLimiter::for('api-write', fn (Request $r): Limit => Limit::perMinute((int) config('kokpit.api.rate_limit.write'))->by($r->user()?->currentAccessToken()?->getKey() ?? $r->ip()));
```
429 responses carry `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (`ThrottleRequests.php:297-315`), and the JSON body comes from the existing `shouldRenderJsonWhen` for `api/*` (`bootstrap/app.php:37-39`). Production `CACHE_STORE=redis` (enforced by the existing production guard), so the counters are shared across containers. Tests use `CACHE_STORE=array` (`phpunit.xml`), which is enough to prove the 429 path.

### Pattern 9: Scramble configuration and the committed spec
- `config/scramble.php`: `'api_path' => 'api/v1'` (server base becomes the prefix and paths are stripped, per the config comment "One static include → default server is /{include}"), `'servers' => ['Kokpit instance' => 'https://kokpit.example.com/api/v1']` (a literal URL: `Generator.php:173-182` runs it through `url()`, which returns an absolute URL unchanged, so the spec does not depend on `APP_URL` and drift in CI cannot come from the environment, and no real hostname enters git), `'info' => ['version' => '1.0.0', 'description' => ...]` as literals (default reads `env('API_VERSION', '0.0.1')`), `'ui' => ['title' => 'Kokpit API']`, `'renderers.elements.theme' => 'system'` (the view has a `system` branch, `resources/views/docs.blade.php`), `'security_strategy' => MiddlewareAuthSecurityStrategy::class` (documents the bearer scheme from `auth:*` middleware; routes without it are marked public), `'export_path' => 'docs/api/openapi.json'` (any repo-relative path).
- Gate: `Gate::define('viewApiDocs', static fn (?User $user): bool => $user?->hasRole(RoleName::Admin->value) === true)`. Scramble's default route middleware is `['web', RestrictedDocsAccess::class]`; `RestrictedDocsAccess` **skips the gate entirely when `app()->environment('local')`** and otherwise does `abort(403)` unless `Gate::allows('viewApiDocs')` (`src/Http/Middleware/RestrictedDocsAccess.php`). DDEV runs `APP_ENV=local` (`.env.example`), so the gate must be tested in the `testing` environment, never by hand in DDEV.
- Not auto-documented, so add small extensions (`Scramble::configure()->withOperationTransformers(...)`; callable shape `(Operation $operation, RouteInfo $routeInfo)`, methods `addResponse`, `addSecurity`, `addParameters` exist on `Operation`): (a) 429 response with `Retry-After`, `X-RateLimit-*` headers on every throttled route (a grep for throttle in Scramble's source finds only `HttpExceptionToResponseExtension`, no rate-limit documentation); (b) the ability each route needs (read it from the `abilities:` middleware and add it to the operation description or as security scopes); (c) `Idempotency-Key` as a request header parameter on the two create operations (`#[HeaderParameter]` exists in `src/Attributes`), plus 409/422 responses and the `Idempotent-Replayed` response header (`#[Header]` is for response headers). Query-builder parameters are not understood by Scramble (no reference to Spatie in its source): declare them through a list `FormRequest` with `filter.*`, `sort`, `cursor`, `per_page` rules (Scramble flattens `filter.client` to `filter[client]`, config `flatten_deep_query_parameters => true`) or `#[QueryParameter]` attributes. `[ASSUMED]` that FormRequest-based documentation of GET query parameters renders as expected; confirm in the first exported spec (`php artisan scramble:export --fail-on-unknown` fails when an `UnknownType` schema is generated, use it to catch gaps).
- Drift guard: a Feature test that runs `Artisan::call('scramble:export', ['--stdout' => true])`, decodes both JSON documents and asserts equality with the committed file. It runs inside `composer test` and in the CI "Tests (PostgreSQL 18)" job (`.github/workflows/hygiene.yml:110-195`), so no workflow change is needed; a second CI step is optional.

### Anti-Patterns to Avoid
- **Re-implementing rules in controllers or FormRequests** (end after start, context agreement, locked): call the Action and let `ValidationException`/`DomainException` flow. FormRequests validate shape only.
- **`$resource->toArray()` / returning models** from lookups: the Client model carries rates and invoice data; always list fields explicitly.
- **`Sanctum::actingAs()` as the only auth in tests**: it bypasses the guard path (callbacks, expiry, malformed token). Use real tokens (`$admin->createToken(...)->plainTextToken` with `withToken()`) for the security tests; `actingAs` is fine for CRUD behaviour tests.
- **`DB::table()` / bare `withoutGlobalScopes()`** anywhere in `app/`: the escape-hatch arch test fails the build (`tests/Arch/QueryEscapeHatchTest.php`).
- **Holding the idempotency claim inside the request transaction**: other requests would block rather than get a 409.
- **Putting the plain token anywhere but the open modal** (flash, notification, log, activity row, URL).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Bearer authentication, hashing, `last_used_at`, expiry | custom token table/guard | Sanctum (installed) + the existing UUID model | Hash comparison with `hash_equals`, expiry and last-used tracking are in `Guard`/`PersonalAccessToken` |
| Ability checks | per-controller `if ($user->tokenCan())` | `abilities:` route middleware (`CheckAbilities`) | Throws `MissingAbilityException` (an `AuthorizationException`, rendered 403); testable per route |
| Filtering, sorting parameter parsing | manual `request('filter')` handling | `spatie/laravel-query-builder` | Allow-listing, `InvalidFilterQuery` (400), LIKE escaping (`FiltersPartial::escapeLike`) |
| Cursor pagination | custom cursor encoding | Eloquent `cursorPaginate()` | Handles tuple comparison and encoded cursor, Scramble documents `CursorPaginator` |
| Rate limiting and headers | custom counter | `RateLimiter::for` + `throttle:` | Emits `Retry-After`/`X-RateLimit-*` itself |
| TOTP verification and replay protection | own TOTP code | Filament `AppAuthentication::verifyCode(..., shouldPreventCodeReuse: true)` | Per-timestep replay lock already implemented |
| OpenAPI document | hand-written YAML | Scramble export | Generated from the same FormRequests/Resources, hence committed-spec drift test |
| Business rules for time entries | duplicate checks in the API | `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry` | Locked decision; DB constraints are the backstop |
| Immutability of billed entries | API-side checks | Action + `kokpit_guard_frozen_row` trigger (KP001) | Writer-independent; Actions already translate KP001 to the locked `DomainException` |

**Key insight:** every rule an external client could trip already has an owner (an Action or a DB constraint). The API's job is authentication, shape validation, reference resolution, exception mapping and a stable contract; anything else duplicated here will drift from the UI.

## Common Pitfalls

### Pitfall 1: A browser session satisfies `auth:sanctum` and passes every ability check
**What goes wrong:** with the shipped `'guard' => ['web']`, a logged-in Admin (for example one using the Scramble "Try it" panel, which sends cookies, `tryItCredentialsPolicy: include`) is authenticated with a `TransientToken` whose `can()` returns `true` (`vendor/laravel/sanctum/src/TransientToken.php:15-18`, quote: `public function can($ability) { return true; }`). Abilities, expiry and the Admin-token callback are bypassed, and the request is not tied to any token for rate limiting.
**Why:** `Guard::__invoke` checks `config('sanctum.guard', 'web')` guards before looking at the bearer token (`Guard.php:25-32`).
**How to avoid:** publish `config/sanctum.php` with `'guard' => []`. Test: an authenticated panel session calling `GET /api/v1/time-entries` without a token gets 401.
**Warning signs:** `$request->user()->currentAccessToken()` is a `TransientToken`; the limiter falls back to the IP key.

### Pitfall 2: A malformed bearer token returns 500 instead of 401
**What goes wrong:** `PersonalAccessToken::findToken('abc|def')` calls `static::find('abc')` on a uuid column, PostgreSQL answers `SQLSTATE[22P02]: invalid input syntax for type uuid: "abc"`. Reproduced in DDEV this session (`Illuminate\Database\QueryException: ... invalid input syntax for type uuid: "abc"`). `Guard::isValidBearerToken` only rejects an empty token for a non-integer key type.
**How to avoid:** override `findToken()` in `App\Domain\Identity\Models\PersonalAccessToken`: when the token contains `|` and the part before it is not `Str::isUuid`, return `null`; otherwise defer to `parent::findToken()`. Test with `Bearer abc|def`, `Bearer |x`, `Bearer ` and a valid-uuid-wrong-secret token, all 401.

### Pitfall 3: A Partner (or deactivated Admin) holding a token
**What goes wrong:** `HasApiTokens` is on every `User`; nothing stops `createToken()` being called for a Partner (tinker, a later feature), and a deactivated Admin keeps working tokens (PITFALLS.md line 87: "deactivated users ... have their sessions and tokens invalidated").
**How to avoid:** the `authenticateAccessTokensUsing` callback (Pattern 1) is the single choke point (401). Also make `IssueApiToken` refuse a non-Admin actor. Tests: Partner token, deactivated Admin token, expired token, revoked token each 401 on every route.

### Pitfall 4: Sanctum registers no `abilities` middleware alias
**What goes wrong:** `abilities:time:read` throws "Target class [abilities] does not exist" (500) because `SanctumServiceProvider` only prepends `EnsureFrontendRequestsAreStateful` to the priority list (`SanctumServiceProvider.php:124-129`).
**How to avoid:** `$middleware->alias([...CheckAbilities, CheckForAnyAbility])` in `bootstrap/app.php`, or reference the classes in the route definition.

### Pitfall 5: A locked entry surfaces as HTTP 500
**What goes wrong:** `UpdateTimeEntry` and `DeleteTimeEntry` throw `DomainException` (message key `kokpit.time.errors.locked`) for a billed entry, and translate the DB trigger KP001 to the same exception. Laravel renders an uncaught `DomainException` as 500. `CreateTimeEntry` throws only `ValidationException`.
**How to avoid:** catch `DomainException` in the controller methods that call update/delete and answer 409 with the message and a stable machine code (for example `entry_locked`). Do not register a global `DomainException` handler (the class is too generic). Also decide what the API does for a **running** entry (Open Question 2).

### Pitfall 6: `duration_seconds` is read-only in the domain
**What goes wrong:** D-09 lists `duration_seconds` as a write input "the UI allows", but `CreateTimeEntry` reads only `started_at`/`ended_at`; the column is `storedAs(...)`, so writing it fails (`database/migrations/2026_10_11_000100_create_time_entries_table.php:66`, quote: `->storedAs('CASE WHEN ended_at IS NULL THEN NULL ELSE (EXTRACT(EPOCH FROM (ended_at - started_at)))::integer END')`).
**How to avoid:** request layer maps `started_at` + `duration_seconds` to `ended_at` (integer >= 1; exactly one of `ended_at` / `duration_seconds`; 422 on both inconsistent or both missing). Without that mapping, drop `duration_seconds` from the write contract. Resolve in Open Question 1 before the contract (one-way, D-09) is published.

### Pitfall 7: Interval overflow into the `integer` generated column
**What goes wrong:** `ended_at - started_at` beyond 2,147,483,647 seconds (about 68 years) makes PostgreSQL raise an out-of-range error on insert/update, a 500. The Action deliberately imposes no cap (`CreateTimeEntry` docblock: "There is no cap on the duration").
**How to avoid:** FormRequest/resolver rule: reject an interval larger than the integer maximum (or a saner product cap such as 366 days, planner's call) with a 422 on `ended_at`/`duration_seconds`. Add a test with `ended_at` far in the future.

### Pitfall 8: Non-UUID strings reaching uuid columns
**What goes wrong:** `/api/v1/time-entries/not-a-uuid` or `filter[client]=abc` make PostgreSQL throw 22P02 (same class as Pitfall 2) instead of answering 404/422. `TimeEntryInput` only guards the ids it parses itself.
**How to avoid:** `Route::whereUuid('timeEntry')` for entry routes; the resolver tests `Str::isUuid` / key regex before touching the database; filters validated in the list FormRequest (`uuid`/regex rules).

### Pitfall 9: Offset-less timestamps are silently read as UTC
**What goes wrong:** `TimeEntryInput::instant()` accepts `Y-m-d H:i:s` without an offset and reads it in the application timezone (UTC) (`app/Domain/TimeTracking/TimeEntryInput.php`, docblock above `instant()`). An importer sending Prague wall-clock times without offset stores them 1-2 hours off with no error.
**How to avoid:** API FormRequest requires ISO 8601 with offset or `Z` (regex `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$`, which is exactly the first branch of `instant()`); document it. Fractions are truncated, never rounded (existing behaviour; TI-08).

### Pitfall 10: Comma in the value splits a partial filter into OR terms
**What goes wrong:** with `filter_value_splitting_enabled => true` (query-builder default), `filter[name]=Acme, s.r.o.` becomes two partial terms joined with OR, and `%`/`_` are escaped but commas are not special-cased.
**How to avoid:** set `'filter_value_splitting_enabled' => false` in the published query-builder config (no filter in this API needs list values), or use `AllowedFilter::callback('name', ...)` with a manual `ILIKE`. Test with a client name containing a comma. Note `FiltersPartial` lower-cases both sides (`LOWER(col) LIKE`), which is adequate for Czech diacritics with a UTF-8 database but is not diacritic-insensitive; acceptable for D-12 ("partial match").

### Pitfall 11: Cursor pagination over a non-unique order
**What goes wrong:** `timestamptz(0)` has one-second resolution; a batch import creates many rows with equal `started_at`. Without a unique tiebreaker, cursor pagination can skip or repeat rows.
**How to avoid:** always append a unique last order column (`time_entries.id`, which is time-ordered UUID v7) to the sort, also when the client passes `sort=`. Test with 120 entries sharing one `started_at`, paging at 50.

### Pitfall 12: `{}` versus `[]` for `external`
See Pattern 4: PHP arrays cannot tell them apart; Eloquent's `array` cast stores `[]` for an empty object and violates the CHECK. Use the `object` cast and validate from the raw body.

### Pitfall 13: Activity log labels API writes as "web"
**What goes wrong:** `ActivitySource::current()` returns `Web` for any non-console, non-job context (`app/Domain/Audit/ActivitySource.php`), and the DB constraint allows only `('web', 'console', 'job', 'webhook')` (`database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php:24`). Entries created or edited by n8n appear in the history as typed in the browser.
**How to avoid (recommendation, `[ASSUMED]` scope call):** add `ActivitySourceLabel::Api` (`'api'`), a migration replacing `activity_log_source_check`, the `enums.activity_source_label.api` Czech label in `lang/cs/enums.php`, and wrap the API request in `ActivitySource::as(ActivitySourceLabel::Api, ...)` from a middleware. Update the tests that enumerate the labels. If the owner does not want the extra scope, accept "web" and record it in the plan.

### Pitfall 14: The docs UI loads third-party JavaScript at render time
**What goes wrong:** `resources/views/docs.blade.php` in Scramble 0.13.47 includes `https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js` and `styles.min.css`; the Scalar renderer defaults to `cdn.jsdelivr.net`. UI-SPEC says "no third-party request at render time" for fonts; this page is the exception, and a future CSP would block it (no CSP exists today; grep found none). The page is Admin-only and carries no secrets.
**How to avoid:** accept and document, or publish the view (`vendor:publish --tag=scramble-views`, tag `[ASSUMED]`) and self-host the assets. Raise to the owner as Open Question 5; default recommendation: accept.

### Pitfall 15: Scramble `local` bypass and test environment
See Pattern 9: the gate is skipped in `APP_ENV=local`. Write the gate tests so they run in `testing` (they do under Pest) and say so in the plan's verification notes so nobody concludes from DDEV that the page is public in production.

### Pitfall 16: Idempotency concurrency cannot be tested in a `RefreshDatabase` transaction
The Feature/Isolation suites wrap each test in a transaction (`tests/Pest.php`); two real connections are needed to see a 409. Follow the `tests/Concurrency` pattern (child worker processes against committed rows, own cleanup, refusal to run unless the database name ends in `_test`; see `timer-worker.php`). Unit/feature tests can cover the replay, hash-mismatch and stale-takeover branches with pre-inserted rows.

## Code Examples

### Custom token model guard (Pitfall 2)
```php
// Source: vendor/laravel/sanctum/src/PersonalAccessToken.php findToken() + reproduced 22P02 in DDEV
public static function findToken($token)
{
    if (is_string($token) && str_contains($token, '|')) {
        [$id] = explode('|', $token, 2);

        if (! Str::isUuid($id)) {
            return null;
        }
    }

    return parent::findToken($token);
}
```

### Atomic idempotency claim without `DB::table`
```php
// Source: Laravel Eloquent Builder::insertOrIgnore; unique index (token_id, key) from D-08
$claimed = IdempotencyKey::query()->insertOrIgnore([[
    'id' => (string) Str::uuid(),               // Str::createUuidsUsing makes this v7 (ModelConventionsServiceProvider)
    'token_id' => $tokenId,
    'key' => $key,
    'request_hash' => hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent()),
    'locked_at' => now(),
    'expires_at' => now()->addHours((int) config('kokpit.api.idempotency_ttl_hours')),
    'created_at' => now(),
    'updated_at' => now(),
]]) === 1;
```

### Rate limiters (Pattern 8) and viewApiDocs gate (Pattern 9)
```php
// Source: app/Providers/AppServiceProvider.php (existing RateLimiter::for('invitation')) and https://scramble.dedoc.co/usage/getting-started
RateLimiter::for('api-read', static fn (Request $r): Limit => Limit::perMinute((int) config('kokpit.api.rate_limit.read'))
    ->by((string) ($r->user()?->currentAccessToken()?->getKey() ?? $r->ip())));

Gate::define('viewApiDocs', static fn (?User $user): bool => $user?->hasRole(RoleName::Admin->value) === true);
```

### Tests with a real token
```php
// Source: tests/Feature/Schema/PackageModelsTest.php (createToken in a test), tests/Support/Canary.php helpers
$admin = Canary::admin();
$token = $admin->createToken('probe', ['time:read'])->plainTextToken;

$this->withToken($token)->getJson('/api/v1/time-entries')->assertOk();
$this->withToken($token)->postJson('/api/v1/time-entries', [...])->assertForbidden();   // lacks time:write
$this->getJson('/api/v1/time-entries')->assertUnauthorized();
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Kernel` route middleware aliases | `$middleware->alias([...])` in `bootstrap/app.php` | Laravel 11+ | Where the `abilities` alias goes |
| Sanctum `scopes` naming | `abilities` / `ability` middleware (the `scope` classes remain as aliases) | Sanctum 3+ | Use `CheckAbilities`/`CheckForAnyAbility` |
| Scramble documenting auth by hand | `security_strategy` config (`MiddlewareAuthSecurityStrategy`) | Scramble 0.13.x | Enable instead of `Scramble::afterOpenApiGenerated` |
| `Scramble` `api_path` single string | include/exclude arrays; `servers` literal | 0.13.x | Fixed `servers` makes the exported spec deterministic |

**Deprecated/outdated:** `laravel/sanctum` `scope` terminology; Laravel `RouteServiceProvider` based API routing (Laravel 13 uses `withRouting(api:, apiPrefix:)`).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Publish tags `scramble-config`, `sanctum-config`, `query-builder-config` exist as named | Standard Stack | Trivial: copy the vendor config by hand |
| A2 | `abilities:time:read` is parsed as one parameter `time:read` (only the first colon splits middleware name and parameters) | Pattern 1 | Routes mis-guarded; caught by the first ability test |
| A3 | Field names `client_id`/`project_id`/`task_id` on writes accept UUID or key | Pattern 2 | Contract naming (one-way D-09); owner/planner may prefer `project`/`task` plus error-key remapping |
| A4 | `filter[from]`/`filter[to]` semantics: date-only = Europe/Prague calendar day, inclusive; instant with offset used exactly | Pattern 3 | Day-boundary surprises for the importer; confirm (Open Question 3) |
| A5 | Exact lookup response field sets (Client `id,name`; Project `id,client_id,key,name,status,priority`; Task `id,reference,title,project_id,status,priority`) | Pattern 3 | Under-delivering fields n8n needs; additive later, but removal is breaking |
| A6 | Replay only 2xx responses, release the key on non-2xx | Pattern 7 | Deviates from literal D-07 for failures; owner may want strict replay of any stored response |
| A7 | Stale-claim takeover after about 120 s | Pattern 7 | Wrong threshold = either duplicate risk or long 409 windows after a crash |
| A8 | Batch response layout and statuses (201 / 207 / 422, counts + error list) | Pattern 5 | Explicitly Claude's discretion in D-14; contract once published |
| A9 | Add an `api` activity source label (migration + enum + lang) | Pitfall 13 | Extra scope; if declined, API writes show as "web" in history |
| A10 | FormRequest `filter.*` rules make Scramble document `filter[...]` query parameters on GET routes | Pattern 9 | Spec lacks filters; fallback `#[QueryParameter]` attributes |
| A11 | Optional `token_prefix` in Sanctum config (for secret scanning) | Pattern 6 | None if skipped; if adopted a gitleaks rule is needed |
| A12 | Scramble UI (Stoplight Elements, loaded from a CDN) is acceptable | Pitfall 14 | Privacy/CSP preference; vendoring costs a published view |
| A13 | Lookups return only non-archived (selectable) rows | Pattern 3 | n8n cannot resolve an archived project/task referenced by old entries via lookup (entries still embed them) |

## Open Questions

1. **`duration_seconds` on writes (D-09 vs code)**
   - What we know: UI and `CreateTimeEntry` take only `started_at` and `ended_at`; `duration_seconds` is a generated column. D-09 says writes also take `duration_seconds` "for the manual-entry shape the UI allows"; no such shape exists.
   - What's unclear: whether the owner wants `started_at` + `duration_seconds` as an alternative input for the importer.
   - Recommendation: support it as an alternative to `ended_at` mapped in the request layer (exactly one of them), because the importer reports durations; if declined, make `duration_seconds` response-only and say so in the spec. Decide before the first plan fixes the contract.

2. **Running entries (started in the UI timer) through the API**
   - What we know: the list returns them (`ended_at` null, `duration_seconds` null). `UpdateTimeEntry` silently ignores a named `ended_at` for a running entry ("the stop is StopTimer") and `DeleteTimeEntry` allows deleting one, while the UI offers neither (`TimeEntryResource::canDelete`).
   - What's unclear: whether "the API refuses what the UI refuses" extends to these.
   - Recommendation: PATCH on a running entry may change description/billable/context but a supplied `ended_at` is a 422 ("running entries are stopped in the app"); DELETE of a running entry is 409. Implement as thin controller guards (no Action change), tell the owner.

3. **Date-range semantics** (A4): confirm Prague-day inclusive semantics for date-only `from`/`to`.

4. **Strict vs success-only replay** (A6): D-07 literal ("stored original response") versus keeping failed attempts retryable.

5. **Docs UI CDN dependency** (Pitfall 14): accept or self-host.

6. **Housekeeping from CONTEXT:** `.planning/REQUIREMENTS.md` already shows AP-03 removed ("kept in ROADMAP.md Backlog as 999.1", line 178) and the phase description's success criterion 3 is already reworded to idempotent creation, so the scope-change edits CONTEXT asked for appear done; the planner should only verify ROADMAP.md Backlog 999.1 exists.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV (web, db, redis) | Running tests, composer, artisan | yes | DDEV v1.25.4, project "kokpit" running | none needed |
| PHP | App, tests | yes (in DDEV) | 8.5.8 | none |
| PostgreSQL | uuidv7(), jsonb, partial indexes, tests | yes (in DDEV) | 18.6 | none |
| Redis | Production cache/queue/limiter store | yes (DDEV service) | running | tests use array cache |
| Composer + Packagist | Installing the two packages | yes | `composer require --dry-run` resolved both | none |
| Node/npm | Not needed (no theme build for this phase; Scramble UI loads from CDN) | n/a | Node 24 in DDEV | n/a |
| Internet access from the user's browser | Scramble docs UI (unpkg CDN) | assumed | - | publish the view and self-host (Pitfall 14) |
| `scripts/check-sensitive.sh`, gitleaks, lefthook | Every commit | yes (repo scripts) | - | none, never bypass |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none blocking.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13 `[VERIFIED: composer.json require-dev]` |
| Config file | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; `DB_CONNECTION=pgsql`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `KOKPIT_REQUIRE_ADMIN_2FA=false`, `KOKPIT_CANARY_HARNESS=true`), `tests/Pest.php` (RefreshDatabase on Feature and Isolation) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Api` |
| Full suite command | `ddev exec vendor/bin/pest` (CI also runs `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `composer check-licenses`) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AP-01 | No token / malformed / unknown / revoked / expired / Partner / deactivated-Admin token -> 401 on every route; session cookie alone -> 401; missing ability -> 403 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/AuthenticationTest.php` | no, Wave 0 |
| AP-01 | `IssueApiToken` refuses a Partner, stores only abilities from the enum, hashes the token, sets `expires_at` | feature | `ddev exec vendor/bin/pest tests/Feature/Api/IssueApiTokenTest.php` | no, Wave 0 |
| AP-01/05 | Token screen: Admin sees only own tokens; Partner 403; forged Livewire create/revoke by Partner refused; wrong password / wrong or reused TOTP keep modal open and create nothing; throttle; plain token never in list/HTML after close; revoke of foreign token refused | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Api/ApiTokenScreenTest.php` | no, Wave 0 |
| AP-02 | List filters (client, project, task by UUID and key, billable, billed, from/to), sort, cursor paging with equal `started_at`, page size cap 200 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryListTest.php` | no, Wave 0 |
| AP-02 | Create/read/update/delete via the Actions; refusals: billed locked (409), inconsistent context (422 field errors), end before start (422), future-far interval (422), offset-less instant (422), running-entry rules | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryWriteTest.php` | no, Wave 0 |
| AP-02 | `external`: object accepted on create/batch/update, `{}` stored as object, array/scalar/oversize 422, absent keeps, null clears, DB CHECK rejects a raw array insert, billed row frozen | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryExternalTest.php` | no, Wave 0 |
| AP-02 | Batch: 100 ok, 101 -> 422, mixed -> 207 with only failed items and counts, none saved -> 422, per-item transaction (a failing item leaves no row), same Actions | feature | `ddev exec vendor/bin/pest tests/Feature/Api/TimeEntryBatchTest.php` | no, Wave 0 |
| AP-04 | Lookups by UUID, project key, task `KEY-N`, `filter[name]` partial incl. comma and `%`; unknown -> 404; no rate/price/billing fields in any response (canary strings in rates) | feature | `ddev exec vendor/bin/pest tests/Feature/Api/LookupTest.php` | no, Wave 0 |
| AP-05 | Idempotency: replay same status/body + `Idempotent-Replayed`, different body 422, stale takeover, non-2xx releases key, key scoped per token, header only on create | feature | `ddev exec vendor/bin/pest tests/Feature/Api/IdempotencyTest.php` | no, Wave 0 |
| AP-05 | Idempotency concurrency: two real processes, same key -> exactly one entry, the other 409 | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/IdempotencyConcurrencyTest.php` | no, Wave 0 |
| AP-05 | Purge job deletes only expired keys; scheduled `kokpit-api-idempotency-prune` on one server | feature/arch | `ddev exec vendor/bin/pest tests/Feature/Api/PurgeIdempotencyKeysTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch/JobContractTest.php` | partly (update existing lists) |
| AP-05 | Rate limits: 121st read and 61st write within a minute -> 429 with `Retry-After`, `X-RateLimit-*`; read and write buckets separate; per-token | feature | `ddev exec vendor/bin/pest tests/Feature/Api/RateLimitTest.php` | no, Wave 0 |
| AP-05 | OpenAPI: `/docs/api` and `/docs/api.json` 200 for Admin, 403 for Partner and guest (env `testing`); exported spec equals the committed spec; spec lists every `api/v1` route, bearer scheme, `Idempotency-Key`, 429/409/422, filters; no real host/token strings | feature | `ddev exec vendor/bin/pest tests/Feature/Api/OpenApiDocumentTest.php` | no, Wave 0 |
| AP-01..05 | Route walk: every `api/v1` route has `auth:sanctum`, an `abilities:` middleware and a throttle; Partner token and guest get no data and no canary string | isolation | `ddev exec vendor/bin/pest tests/Isolation/ApiAccessTest.php` | no, Wave 0 |

### Sampling Rate
- **Per task commit:** `ddev exec vendor/bin/pest tests/Feature/Api` (plus the touched arch/isolation file)
- **Per wave merge:** `ddev exec vendor/bin/pest` and `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- **Phase gate:** full suite green, `composer check-licenses` green, `scripts/check-sensitive.sh` green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Api/*` files listed above; a shared helper (for example `tests/Support/ApiToken.php`) that creates an Admin, a token with chosen abilities and fixture entries
- [ ] `tests/Isolation/ApiAccessTest.php` and a canary line for API access (CONTEXT: "extend the canary harness with a line for API access")
- [ ] `tests/Concurrency/IdempotencyConcurrencyTest.php` plus a worker script (pattern: `timer-worker.php`)
- [ ] Update exact-list tests: `tests/Arch/ModelDeclarationTest.php` (`appModels()` gets the idempotency model), `tests/Arch/JobContractTest.php` (`EXPECTED_APPLICATION_JOBS`), `tests/Feature/Operations/ScheduleOnOneServerTest.php` (known events), `MorphMap` alias test coverage, the activity-source tests if Pitfall 13 is adopted
- [ ] Framework install: none new (Pest present); packages via composer (Standard Stack)

## Security Domain

`security_enforcement` is enabled (absent/`true`), ASVS level 1 (`.planning/config.json`: `security_asvs_level: 1`, `security_block_on: high`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Sanctum bearer tokens (hashed with sha256 at rest, 40-char random + crc32b, UUID id); `sanctum.guard = []`; Admin-only validity callback; token creation requires password + TOTP with replay protection and attempt throttling |
| V3 Session Management | yes (panel only) | Token screen runs in the existing Filament session; API is stateless (no cookies accepted); revocation = row delete, effective on the next request |
| V4 Access Control | yes | `abilities:` per route; `AdminOnlyPolicy` + `KokpitPolicy::before` inside the Actions; Partner models closed by `DeniesPartners`; token owner must be an active Admin; token screen limited to own tokens with server-side re-check on revoke |
| V5 Input Validation | yes | FormRequests (shape, UUID/key regex, ISO 8601 with offset, size caps: 100 items, 4 KB `external`, 1000-char description from `TimeEntryInput`), query-builder allow-lists, `whereUuid` route constraints, DB CHECKs |
| V6 Cryptography | yes | Never hand-roll: Sanctum token hashing, Filament/google2fa TOTP; `hash_equals` in Sanctum; Idempotency body hash is integrity only (sha256), not a secret |
| V7 Error Handling and Logging | yes | JSON errors without stack traces (production `APP_DEBUG=false`); never log tokens; activity log carries name/abilities only |
| V8 Data Protection | yes | Responses are explicit allow-lists with no rates/prices; plain token shown once, never stored; OpenAPI spec committed with fictional values |
| V13 API and Web Service | yes | Versioned prefix, rate limiting, content negotiation JSON only, explicit methods, idempotency on create |

### Known Threat Patterns for Laravel + Sanctum + PostgreSQL

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Browser session or cookie authenticating the API (CSRF-like, ability bypass via `TransientToken`) | Elevation of privilege | `sanctum.guard = []`, API group without session/cookie middleware, test that a session alone gets 401 |
| Token of a non-Admin or deactivated user | Elevation of privilege | `authenticateAccessTokensUsing` callback + `IssueApiToken` Admin check |
| Malformed bearer causing DB error / information leak (500) | Information disclosure, DoS | `findToken` UUID validation, `whereUuid`, resolver regex before queries |
| Token leakage (UI, logs, URL, activity log) | Information disclosure | One-time `#[Locked]` modal property cleared on close; never in notification/flash/log; hash-only storage; optional `token_prefix` + gitleaks rule |
| Token brute force / credential stuffing on the screen | Spoofing | TOTP replay protection, per-user attempt limiter; bearer entropy (40 random chars) makes API brute force infeasible; 401s are not throttled (Authenticate precedes the limiter), acceptable at this entropy |
| Mass assignment / smuggling ids, `user_id`, billing state through the payload | Tampering | Actions use `forceFill` for ids/instants/billing; controllers pass only allow-listed keys; `Model::preventSilentlyDiscardingAttributes` in dev/test |
| Data exposure of rates/prices/billing through lookups or entry responses | Information disclosure | Explicit Resource arrays; tests with canary strings in `project_billing`, `task_billing`, client rate columns |
| Replay/duplicate creation | Tampering (integrity) | Idempotency table with unique `(token_id, key)`; caller dedup via `external` (D-15) |
| Resource exhaustion (oversize batch, huge JSON, deep nesting, huge page) | Denial of service | 100-item cap, 4 KB `external` + depth cap, page size cap 200, per-token rate limits, request body size from the web server |
| Unauthorised docs access / spec leak | Information disclosure | `viewApiDocs` Admin gate; spec contains only fictional examples |
| SQL injection via filters/sorts | Tampering | query-builder allow-lists, parameter-bound LIKE (escaped), no raw SQL from input |
| Immutability bypass through the API | Tampering | Actions check `billing_state`; frozen-row trigger KP001 is the writer-independent backstop |

## Sources

### Primary (HIGH confidence)
- In-repo files read this session: `.planning/phases/07-rest-api/07-CONTEXT.md`, `07-UI-SPEC.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `.planning/research/STACK.md`, `.planning/research/PITFALLS.md`; `app/Domain/TimeTracking/{Actions/*,TimeEntryInput.php,Models/TimeEntry.php,Queries/EntryContextOptions.php}`, `app/Domain/Identity/Models/{User,PersonalAccessToken}.php`, `app/Domain/Shared/{Auth/*,Database/{Immutability,MorphMap}.php,Policies/AdminOnlyPolicy.php}`, `app/Domain/Audit/{ActivitySource,ActivitySourceLabel,KokpitLogActivityAction,LogsAllowlistedActivity}.php`, `app/Domain/Operations/Jobs/KokpitJob.php`, `app/Providers/*`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Http/Middleware/EnsureAdminHasTwoFactor.php`, `app/Filament/Resources/TimeEntryResource.php`, `bootstrap/app.php`, `routes/console.php`, `config/{auth,kokpit}.php`, migrations for `personal_access_tokens`, `time_entries`, `activity_log` source, the frozen-row guard; tests `tests/Arch/*`, `tests/Isolation/RouteWalkTest.php`, `tests/Feature/Schema/*`, `tests/Concurrency/*`, `phpunit.xml`, `.github/workflows/hygiene.yml`
- Installed vendor source: `vendor/laravel/sanctum` 4.3.3 (`Guard.php`, `PersonalAccessToken.php`, `HasApiTokens.php`, `TransientToken.php`, `Sanctum.php`, `SanctumServiceProvider.php`, `Http/Middleware/CheckAbilities.php`, `config/sanctum.php`); `vendor/laravel/framework` 13.35.0 (`Auth/Middleware/Authenticate.php`, `Foundation/Http/Kernel.php` priority list, `Routing/Middleware/ThrottleRequests.php`, `Foundation/Configuration/ApplicationBuilder.php`); `vendor/filament/filament` 5.10.1 (`Auth/MultiFactor/App/AppAuthentication.php`)
- Downloaded and read: `dedoc/scramble` v0.13.47 (`config/scramble.php`, `routes/web.php`, `src/Http/Middleware/RestrictedDocsAccess.php`, `src/Generator.php`, `src/Console/Commands/ExportDocumentation.php`, `src/SecurityDocumentation/MiddlewareAuthSecurityStrategy.php`, `src/Attributes/*`, `resources/views/docs.blade.php`), `spatie/laravel-query-builder` 7.3.5 (`config/query-builder.php`, `docs/features/filtering.md`, `src/Filters/FiltersPartial.php`, `src/Exceptions/InvalidFilterQuery.php`)
- Executed this session in DDEV: `composer require --dry-run` for both packages; `composer show -a` for both; `PersonalAccessToken::findToken('abc|def')` reproducing the 22P02 `QueryException`; `php -v` (8.5.8), `psql --version` (18.6)
- Packagist API (`packagist.org/packages/<name>.json`): downloads, repository, abandonment status

### Secondary (MEDIUM confidence)
- https://scramble.dedoc.co/usage/getting-started (routes, `viewApiDocs` gate, `api_path`); the query-parameters docs page returned 404, so query-parameter behaviour is from package source only

### Tertiary (LOW confidence)
- Nothing relied on web search; items needing confirmation are tagged `[ASSUMED]` and collected in the Assumptions Log

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, resolved by Composer against the real lock; both packages named by locked decisions
- Architecture: HIGH for the integration points (read from code), MEDIUM for the idempotency middleware and Scramble extensions (designed, not built)
- Pitfalls: HIGH for 1-9 and 11 (read from source or reproduced), MEDIUM for 10, 12-16 (derived from source reading, not yet exercised by a test)

**Research date:** 2026-10-14
**Valid until:** 2026-11-13 (Scramble is pre-1.0 and pinned; re-check `composer show -a dedoc/scramble` if the plan is delayed beyond that)
