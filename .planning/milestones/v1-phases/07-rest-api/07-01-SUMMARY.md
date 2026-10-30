---
phase: 07-rest-api
plan: 01
subsystem: api
tags: [laravel, sanctum, rest-api, rate-limiting, abilities, pest]

requires:
  - phase: 03-time-tracking
    provides: TimeEntry model, CreateTimeEntry and StartTimer Actions
  - phase: 02-access-control
    provides: UUID v7 PersonalAccessToken model, Admin-only TimeEntry policy
provides:
  - "Token-only /api/v1 skeleton: read and write route groups with auth:sanctum, abilities and per-token throttle"
  - "GET /api/v1/time-entries/{uuid} with an explicit-key TimeEntryResource (no money fields)"
  - "Admin-only token validity (Partner, role-less, deactivated, expired, revoked and malformed bearers all 401)"
  - "api-read and api-write rate limiters (120 and 60 a minute per token) and the full api block of config/kokpit.php"
  - "Tests\\Support\\ApiFixtures shared by every later API test"
affects: [07-02, 07-03, 07-04, 07-05, 07-06, 07-07, 07-08, 07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 7945
  tasks: 3
  commits: 5

plan_head_before: 7a9b2c78de96d8b63aa4e10c6bb11a01a0067c81
plan_head_after: 44fa5e1ae489eaf0684e174f20983dfd2fe6ebad

tech-stack:
  added: []
  patterns:
    - "API routes in routes/api.php under withRouting(apiPrefix: 'api/v1'), two groups per ability, ability strings from ApiAbility"
    - "Controllers take plain string route parameters and resolve rows themselves, so every miss is the same Czech 404 without a class name"
    - "API resources list every key explicitly, never parent::toArray()"
    - "API test helpers are static methods on Tests\\Support\\ApiFixtures, never global Pest functions"

key-files:
  created:
    - routes/api.php
    - config/sanctum.php
    - lang/cs/api.php
    - app/Domain/Identity/ApiAbility.php
    - app/Providers/ApiServiceProvider.php
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Resources/Api/V1/TimeEntryResource.php
    - tests/Support/ApiFixtures.php
    - tests/Feature/Api/AuthenticationTest.php
    - tests/Feature/Api/RateLimitTest.php
  modified:
    - bootstrap/app.php
    - bootstrap/providers.php
    - config/kokpit.php
    - app/Domain/Identity/Models/PersonalAccessToken.php

key-decisions:
  - "config/sanctum.php sets guard to an empty list so a panel session never authenticates as a TransientToken that passes every ability check"
  - "PersonalAccessToken::findToken refuses a non-uuid id part before the database is asked, preventing a PostgreSQL 22P02 500"
  - "Sanctum::authenticateAccessTokensUsing is the single choke point: only an active Admin token is valid"
  - "Rate limit buckets are keyed by token id with the IP address as fallback; read and write are separate buckets"
  - "ApiAbility carries no HasLabel yet; the labels follow in plan 07-03 where they are first shown"

patterns-established:
  - "Refusal paths are tested through the real guard path with withToken(); no Sanctum::actingAs()"
  - "A test that sends several requests with different tokens calls Auth::forgetGuards() between them, because the guard keeps the first user for the rest of the test"
  - "Debug-off assertions (config app.debug false) when a test checks that a response body names no class"

requirements-completed: [AP-01, AP-02, AP-05]

coverage:
  - id: D1
    description: "An Admin token with time:read reads one time entry at GET /api/v1/time-entries/{uuid} with ISO 8601 instants, whole-second duration, billable and billing_state, and no money field"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/AuthenticationTest.php#it lets an Admin token with time:read read one time entry"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every refusal path (no token, malformed bearer, unknown, revoked, expired, session-only, Partner, role-less, deactivated, missing ability, bad id) is a clean 401, 403 or 404, never 500"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/AuthenticationTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Reads are limited to 120 and writes to 60 a minute per token, with 429, Retry-After and X-RateLimit headers and separate buckets per token and per direction"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/RateLimitTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The time entry JSON shape is a proposal until the owner confirms it at the plan 07-02 decision checkpoint (D-09 one-way door)"
    verification: []
    human_judgment: true
    rationale: "Whether the field set and names are what the owner wants for a published, one-way contract is a product decision no test can make; plan 07-02 Task 2 gates it"

duration: 8min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 01: Token-secured /api/v1 skeleton Summary

**Bearer-only Sanctum API under /api/v1 where only an active Admin's token with the right ability reads a time entry, every refusal is a clean 401/403/404, and reads and writes are rate limited per token (120 and 60 a minute).**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-14T09:30:33Z
- **Completed:** 2026-10-14T09:38:30Z
- **Tasks:** 3
- **Files modified:** 14

## Accomplishments

- A real Admin Sanctum token reads one time entry as JSON at `GET /api/v1/time-entries/{uuid}`; the resource lists every key explicitly and carries no rate, price or amount.
- The panel session is not an API credential (`'guard' => []`), a malformed bearer such as `abc|def` or `|x` no longer reaches a uuid column (no 22P02 500), and tokens of a Partner, a role-less user or a deactivated Admin answer 401.
- Both route groups carry `auth:sanctum`, their `abilities:` middleware and `throttle:api-read` or `throttle:api-write`; the `api` block of `config/kokpit.php` holds every setting later plans read.
- 2644 tests green across the full suite, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer - an Admin token reads one time entry** - `55b8c8d` (feat)
2. **Task 2: Every refusal path is a clean 401, 403 or 404** - RED `c7bfbd2` (test), GREEN `789aa9d` (feat)
3. **Task 3: Per-token rate limits on both route groups** - RED `1775751` (test), GREEN `44fa5e1` (feat)

**Plan metadata:** committed separately as `docs(07-01): complete token-secured API skeleton plan`.

## Files Created/Modified

- `routes/api.php` - read and write route groups, `api.v1.time-entries.show`
- `config/sanctum.php` - published, bearer-only (`guard` empty, `stateful` empty, no `env()` calls)
- `bootstrap/app.php` - `api:` routing with `apiPrefix: 'api/v1'`, `abilities` and `ability` aliases
- `bootstrap/providers.php` - registers `ApiServiceProvider`
- `app/Providers/ApiServiceProvider.php` - Admin-only token validity, `api-read` and `api-write` limiters
- `app/Domain/Identity/ApiAbility.php` - `time:read`, `time:write`, `values()`
- `app/Domain/Identity/Models/PersonalAccessToken.php` - uuid-guarded `findToken`
- `app/Http/Controllers/Api/V1/TimeEntryController.php` - `show` with a Czech 404
- `app/Http/Resources/Api/V1/TimeEntryResource.php` - explicit allow-list JSON
- `config/kokpit.php` - the `api` block
- `lang/cs/api.php` - `errors.not_found`
- `tests/Support/ApiFixtures.php`, `tests/Feature/Api/AuthenticationTest.php`, `tests/Feature/Api/RateLimitTest.php`

## Decisions Made

- Kept `ApiAbility` label-free as the plan says; `HasLabel` and the route walk arrive in plan 07-03.
- Test fixtures sign the actor in on the web guard only for the duration of one Action call and then call `Auth::forgetGuards()`, so a fixture can never leave a session that masks a token check.
- The time entry JSON stays a proposal until the plan 07-02 owner checkpoint.
- Research assumptions A1 (the `sanctum-config` publish tag; the config was written by hand instead of published, so the assumption was not exercised) and A2 (`abilities:time:read` parses as one parameter; proven by the 403 test) are resolved.

## TDD Notes

Task 2 and Task 3 followed RED, GREEN with a `test(07-01)` commit before each `feat(07-01)` commit (no refactor commit was needed; the plan is `type: execute`, so the plan-level gate does not apply and the RED evidence was inspected by hand, not through the classifier).

- **Task 2 RED:** 5 target tests failed on the planned assertions: `abc|def` and `|x` answered 500 with SQLSTATE 22P02 instead of 401; the Partner and role-less tokens answered 404 and the deactivated Admin token 200 instead of 401. The remaining new tests (revoked, expired, session-only, ability 403, 404, last_used_at, other malformed shapes) already passed because Task 1 had built the guard, aliases and route constraint; they are characterisation tests that pin those paths, not RED tests.
- **Task 3 RED:** all 4 tests failed: the config block was null, the limit headers were absent, the write probe answered 500 because limiter `api-write` was not defined, and the read route lacked `throttle:api-read`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] RED tests failed for the wrong reason in two cases**
- **Found during:** Task 2 (RED run)
- **Issue:** the ability test sent a second request with another token in the same test and got 403 because the test guard keeps the user of the first call; the 404 test checked for `App\` in a response body that is the debug page in the test environment.
- **Fix:** `Auth::forgetGuards()` between the two requests and `config(['app.debug' => false])` in the 404 test, so both tests assert the planned behaviour. Fixed before the RED commit.
- **Files modified:** `tests/Feature/Api/AuthenticationTest.php`
- **Commit:** `c7bfbd2`

**2. [Rule 1 - Bug] PHPStan rejected an always-true `is_string` check**
- **Found during:** Task 2 (static analysis)
- **Issue:** the research snippet guards `findToken` with `is_string($token)`, which PHPStan reports as always true for the documented `string` type.
- **Fix:** dropped the redundant check; `str_contains` alone.
- **Files modified:** `app/Domain/Identity/Models/PersonalAccessToken.php`
- **Commit:** `789aa9d`

**Total deviations:** 2 auto-fixed (2 Rule 1). **Impact:** none on scope or behaviour.

## Authentication Gates

None.

## Issues Encountered

None.

## Known Stubs

None. The empty write route group in `routes/api.php` is intentional scaffolding that later plans of this phase fill; it holds no route yet and returns nothing.

## Threat Flags

None. The new surface (`/api/v1` routes, token lookup) is covered by threats T-07-01 to T-07-07 of the plan, all mitigated and tested.

## Next Phase Readiness

Ready for plan 07-02 (the owner checkpoint on the time entry JSON, then the contract is pinned). Plan 07-03 adds `HasLabel` on `ApiAbility` and the route walk before any further route exists.

## Self-Check: PASSED

- Created files verified on disk: routes/api.php, config/sanctum.php, lang/cs/api.php, ApiAbility.php, ApiServiceProvider.php, TimeEntryController.php, TimeEntryResource.php, ApiFixtures.php, AuthenticationTest.php, RateLimitTest.php (all FOUND).
- Commits verified as ancestors of HEAD: 55b8c8d, c7bfbd2, 789aa9d, 1775751, 44fa5e1 (all FOUND).
- All task acceptance criteria re-run and passing; plan verification: full Pest suite (2644 passed), Pint clean, PHPStan clean, `scripts/check-sensitive.sh` clean.
