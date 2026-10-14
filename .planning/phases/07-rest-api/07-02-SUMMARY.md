---
phase: 07-rest-api
plan: 02
subsystem: api
tags: [scribe, openapi, rest-api, api-docs, rate-limiting, contract-test, pest]

requires:
  - phase: 07-rest-api
    provides: "07-01 token-secured /api/v1 skeleton, TimeEntryController@show, TimeEntryResource, ApiServiceProvider, docs_rate_limit config, ApiFixtures"
  - phase: 07-rest-api
    provides: "07-03 token screen that links to the docs page, and the api/v1 route walk"
provides:
  - "Scribe-generated public docs page (/docs) and OpenAPI 3.1 spec (/docs.openapi) built from routes, PHP attributes and fixed fictional examples, generated without a database"
  - "ApiDocumentationTest: data-driven completeness rules over every api/v1 route, so a later endpoint without attributes fails the suite"
  - "EnsureApiDocsGenerated middleware (404 before first generation, X-Robots-Tag noindex) and the api-docs per-IP limiter"
  - "The owner-confirmed wire contract (section Confirmed contract), pinned for the time entry by TimeEntryContractTest"
affects: [07-04, 07-05, 07-06, 07-07, 07-08, 07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 11730
  tasks: 3
  commits: 2

plan_head_before: b9e136430ea369fc63d8cdf3c684f3172df8a3d9
plan_head_after: f1eae3a75ec44777d6fd3d966c3defcbadaae055

tech-stack:
  added:
    - "knuckleswtf/scribe ^5.11 (MIT, in require, generates at container start)"
  patterns:
    - "API docs are code: Scribe attributes on the controller, constant fictional payloads in ApiExamples, no response calls, nothing generated is committed"
    - "Several #[Response] attributes with the same status become a oneOf in the spec; #[ResponseField('data.key', nullable: true)] marks a nullable key and fills the required list"
    - "Documentation completeness is data-driven over Route::getRoutes(); controllers declare FILTERS and SORTS constants for list parameters"
    - "A wire contract is pinned by a static support class (Tests\\Support\\TimeEntryContract) used against the resource, the examples and the spec"

key-files:
  created:
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - app/Http/Middleware/EnsureApiDocsGenerated.php
    - config/scribe.php
    - tests/Support/ApiDocs.php
    - tests/Support/TimeEntryContract.php
    - tests/Feature/Api/ApiDocumentationTest.php
    - tests/Feature/Api/ApiDocumentationAccessTest.php
    - tests/Feature/Api/TimeEntryContractTest.php
  modified:
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Providers/ApiServiceProvider.php
    - composer.json
    - composer.lock
    - .gitignore
    - scripts/tests/test-gitignore.sh

key-decisions:
  - "Docs live at /docs and /docs.openapi, public, no web or auth middleware, throttle:api-docs (60 a minute per IP) then EnsureApiDocsGenerated"
  - "Owner decision OD-6b stands: stock Scribe theme with third-party CDN assets (T-07-12 accepted); Try It Out and the Postman collection are off"
  - "Owner amended the contract: project_id, task_id and description are optional on writes; project_key and task_reference are output-only"
  - "Every optional key of a time entry is documented as nullable (type [x, null]) and a running-entry example is documented next to the finished one"
  - "abort(404, headers: noindex) in EnsureApiDocsGenerated so the 404 answer also carries X-Robots-Tag"

patterns-established:
  - "A contract test asserts key list, order and JSON type per key against the real response, the ApiExamples constants and the generated spec, so the three cannot drift"
  - "Absent-state tests of a generated artefact use Storage::fake('local') plus a View finder pointed at an empty temp directory"

requirements-completed: [AP-05]

coverage:
  - id: D1
    description: "Scribe generates the HTML page and an OpenAPI 3.1 spec from the code with the database unreachable and the base URL equal to APP_URL at generation time; nothing generated is tracked"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it generates without a database and documents the placeholder base URL as OpenAPI 3.1"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every api/v1 route is documented with its method, 401/403/429, its ability and the statuses its method implies; a route without attributes fails the test"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it documents every api/v1 route completely"
        status: pass
    human_judgment: false
  - id: D3
    description: "A guest reads /docs and /docs.openapi with no cookie and a noindex header; before generation both answer 404; the Postman route is 404; the 3rd request over a limit of 2 a minute is 429; the API stays 401 for a guest"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationAccessTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The time entry keys, their order and JSON types equal the owner-confirmed contract in the resource output (finished and running entry), in both ApiExamples payloads and in every oneOf variant of the generated spec"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryContractTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "The generated /docs page looks right in a browser: the stock theme renders with CDN assets, the sidebar search and code highlighting work, the oneOf finished/running examples are readable"
    verification: []
    human_judgment: true
    rationale: "Visual rendering of a third-party theme loaded from CDNs cannot be asserted by a test here; collected for the end-of-phase UAT."

duration: 40min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 02: Generated API documentation and the confirmed wire contract Summary

**Scribe now generates a public docs page and an OpenAPI 3.1 spec from the code (no database, fixed fictional examples), a data-driven test fails for any undocumented api/v1 route, and the owner-confirmed time entry contract, including the optional-on-write amendment, is pinned by a contract test against the resource, the examples and the spec.**

## Performance

- **Duration:** about 40 min across a tracer, a decision checkpoint and the contract task
- **Tasks:** 3 (tracer, decision checkpoint, TDD task)
- **Files:** 14 (8 created, 6 modified; `composer.lock` excluded from the token estimate)

## Accomplishments

- `knuckleswtf/scribe` is installed and configured: `laravel` type, OpenAPI 3.1, bearer auth with the `{YOUR_API_TOKEN}` placeholder, response calls removed, database transaction list empty, Try It Out and Postman off. `php artisan scribe:generate` exits 0 with the database unreachable, and the spec's `servers[0].url` is the `APP_URL` of the generation.
- Output is never committed: `.scribe/`, `resources/views/scribe/`, `public/vendor/scribe/` and `public/docs/` are gitignored with `test-gitignore.sh` cases.
- `ApiDocumentationTest` walks `Route::getRoutes()`: every api/v1 route needs its method, 401/403/429, its ability in the description, the statuses its method implies, and (by convention) `FILTERS`/`SORTS` query parameters and the `Idempotency-Key` header. Probe routes prove the rules can fail.
- `/docs` and `/docs.openapi` are public: no cookie, `X-Robots-Tag: noindex, nofollow`, 404 (not 500) before the first generation, the Postman route 404, 60 requests a minute per IP (`api-docs` limiter), and `/api/v1` stays 401 for a guest.
- The time entry contract is pinned and documented: the 200 response is a `oneOf` of a finished entry and a running entry (null end, duration, project and task); every optional key is `type: [x, null]` in the spec and `required` lists all 15 keys.
- Full suite: 2715 tests green, Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

## Confirmed contract

Confirmed by the owner at the Task 2 decision checkpoint on 2026-10-14 as "AMEND": the seven points below are the plan's proposal verbatim, with the owner's amendment applied (the amended parts are marked). Later plans read this section; renaming anything here is a breaking change after the first release (D-09).

1. Time entry: `{"data": {id, client_id, project_id, project_key, task_id, task_reference, description, started_at, ended_at, duration_seconds, billable, billing_state ("unbilled"|"billed"), billed_at, created_at, updated_at}}`; instants ISO 8601 in UTC (`2026-10-12T08:00:00+00:00`); plan 07-06 adds `external` (object or null). No rate, price or amount (D-10). **Amendment:** `project_key` and `task_reference` are output-only fields (they stay in responses and are not write fields). Every key is always present; a value that does not exist is `null`. Nullable keys: `project_id`, `project_key`, `task_id`, `task_reference`, `description`, `ended_at` (null while the entry runs), `duration_seconds` (null while the entry runs), `billed_at`. The spec marks these nullable and documents a running-entry example next to the finished one.
2. List: `{"data": [...], "links": {...}, "meta": {path, per_page, next_cursor, prev_cursor}}`, cursor pagination, default 50, max 200, newest first (D-11).
3. Writes (research A3): fields `client_id` (uuid, **required**), `project_id` (uuid or project key such as DEMO, **optional**), `task_id` (uuid or DEMO-1, **optional**), `started_at` (ISO 8601 with offset or Z, **required**), exactly one of `ended_at` or `duration_seconds` (OD-1, **required**), `description` (**optional**), `billable` (**optional**, absent or null takes the domain Action's existing default, the D-03 default of the task), `external`; names are never accepted (D-13). `project_key` and `task_reference` are not write fields. Create answers 201 with Location, update 200, delete 204.
4. Errors: 422 `{message, errors: {field: [...]}}` keyed by the same field names as the UI Actions (Czech messages); 409 `{message, code}` with codes `entry_locked`, `entry_running`, `idempotency_in_progress`; 422 `{message, code}` with `idempotency_key_reused` and `idempotency_key_invalid`; 401, 403, 404, 429 as Laravel JSON.
5. Batch (research A8, D-14): `POST /api/v1/time-entries/batch` with `{"entries": [...]}` up to 100; answer `{"saved": n, "failed": m, "errors": [{"index": i, "errors": {field: [...]}}]}` with 201 when all saved, 207 when mixed, 422 when none saved.
6. Lookups (research A5, A13): client `{id, name}`, project `{id, client_id, key, name, status, priority}`, task `{id, project_id, reference, title, status, priority}`; only non-archived rows; `filter[name]` partial; projects also `filter[client]`, tasks also `filter[project]`; detail by uuid, project key or task KEY-N (D-12).
7. Paths: /api/v1/time-entries, /time-entries/{uuid}, /time-entries/batch, /clients, /clients/{uuid}, /projects, /projects/{uuid or key}, /tasks, /tasks/{uuid or KEY-N}; docs at /docs and /docs.openapi.

Open details the owner delegated to the orchestrator (decided): `client_id` and `started_at` stay required; exactly one of `ended_at`/`duration_seconds` stays required (OD-1); `billable` is optional with the domain default; everything else as proposed.

Notes for plan 07-06 (writes), derived from the domain Actions read for the amendment:

- `CreateTimeEntry` accepts a client-only entry, a project without a task, and a task without an explicit project (the project is taken from the task). `project_id` with a `task_id` of another project fails as `inconsistent_context` on `task_id`; a `client_id` that does not own the project fails as `inconsistent_context` on `project_id`. The API layer must pass keys through the D-12 resolver to uuids before calling the Action, and must turn `duration_seconds` into `ended_at` (`started_at` plus seconds) because the Action takes `ended_at` only.
- `StartTimer` needs only `client_id`, which is the source of the running-entry example.

## Task Commits

1. **Task 1 (tracer): Scribe generates the docs and the OpenAPI spec of GET /api/v1/time-entries/{uuid} without a database** - `6fce223` (feat)
2. **Task 2 (checkpoint:decision): owner confirms the wire contract** - answered "AMEND" (no commit; recorded in the Confirmed contract section)
3. **Task 3: pin the confirmed contract and serve the docs publicly** - `f1eae3a` (feat)

**Plan metadata:** committed separately as `docs(07-02): complete API documentation plan`.

## Files Created/Modified

- `config/scribe.php` - Scribe config; `laravel.middleware` is `['throttle:api-docs', EnsureApiDocsGenerated::class]`
- `app/Http/Controllers/Api/V1/ApiExamples.php` - constant fictional payloads, now including `TIME_ENTRY_RUNNING`
- `app/Http/Controllers/Api/V1/TimeEntryController.php` - attributes: finished and running 200, 404, nullable `ResponseField`s, endpoint description with the running-entry note
- `app/Http/Middleware/EnsureApiDocsGenerated.php` - 404 before generation at the exact location each Scribe route reads, noindex header on every response
- `app/Providers/ApiServiceProvider.php` - `api-docs` limiter per IP
- `tests/Support/ApiDocs.php`, `tests/Support/TimeEntryContract.php` - generation helper and contract constants
- `tests/Feature/Api/ApiDocumentationTest.php`, `ApiDocumentationAccessTest.php`, `TimeEntryContractTest.php`
- `.gitignore`, `scripts/tests/test-gitignore.sh`, `composer.json`, `composer.lock`

## Decisions Made

See key-decisions above. Two details worth keeping: the `api-docs` limiter reads `kokpit.api.docs_rate_limit` per request (a test lowers it to 2), and the middleware looks at `route()->getName()` (`scribe`, `scribe.openapi`, `scribe.postman`) to decide which artefact must exist.

## TDD Notes

Task 3 is `type="auto" tdd="true"` inside an `execute` plan, committed as one `feat` commit (the plan asks for tests first inside the task, not a separate RED commit). RED was run before the implementation: of the 11 new tests, 10 failed on the planned assertions (no `TIME_ENTRY_RUNNING`, no noindex header or cookie rule, 500 instead of 404 before generation, no 429 on the docs routes) and 1 passed already (the guest `/api/v1` stays 401 check, a characterisation of plan 07-01 behaviour). Task 1 is a tracer (single commit).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pest `toContain` takes needles, not a message**
- **Found during:** Task 3
- **Issue:** the type assertion in the contract support class passed a message as a second needle and failed on valid data.
- **Fix:** `PHPUnit\Framework\Assert::assertContains` with the message.
- **Files modified:** `tests/Support/TimeEntryContract.php`
- **Commit:** `f1eae3a`

**2. [Rule 2 - Missing critical] The 404 before generation must also carry the noindex header**
- **Found during:** Task 3
- **Issue:** a header set after `$next()` never reaches a response that is an `abort(404)` from the same middleware.
- **Fix:** `abort(404, headers: ['X-Robots-Tag' => ...])`; asserted for all three docs paths.
- **Files modified:** `app/Http/Middleware/EnsureApiDocsGenerated.php`
- **Commit:** `f1eae3a`

### Plan-vs-reality notes

- The plan said Task 3 changes `TimeEntryResource` and the 07-01 tests on "amend". The amendment only concerns write fields and nullability of output keys, which the existing resource already satisfied (it returns null for every optional key), so `TimeEntryResource` and `AuthenticationTest` are unchanged. The resource files are listed in the plan's `files_modified` but needed no edit.
- The amendment needed a documentation change the plan did not name: a second `#[Response]` (running entry), `#[ResponseField]` attributes for nullability and the intro text line about `null`. Scribe turns two responses with the same status into a `oneOf`, which `TimeEntryContractTest` accounts for.

### Domain conflict check (amendment: project_id, task_id, description optional on writes)

No conflict found. `CreateTimeEntry` and `TimeEntryInput::context()` already accept an entry with only a client (the migration columns `project_id`, `task_id`, `description` are nullable, and the check `time_entries_task_needs_project_check` is satisfied because a task always brings its project). No domain rule was changed and no workaround was added. The write endpoints themselves are built in plan 07-06, so the optional fields are, for now, only recorded in the contract and in this summary; the contract test pins the output side (null for each optional key on a client-only running entry).

Residual points for plan 07-06 (not conflicts): `duration_seconds` is not accepted by the domain Action (the API layer converts it to `ended_at`), and project and task keys are resolved to uuids by the API layer (D-12).

## Auth Gates

None.

## Known Stubs

None. `TIME_ENTRY`, `TIME_ENTRY_RUNNING` and the error payloads in `ApiExamples` are intentional fixed fictional documentation data, not stubs.

## Threat Flags

None beyond the plan's register. T-07-12 (third-party CDN scripts on the panel origin, owner choice OD-6b) is accepted and unchanged: the docs routes carry no session middleware, but a compromised CDN script could still act with a signed-in Admin's cookies on the same origin. Surfaced to the owner here for the record.

## Verification

- `ddev exec vendor/bin/pest`: 2715 passed
- `ddev exec vendor/bin/pint --test` and `phpstan analyse`: clean
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on both task commits
- `git status` shows no generated Scribe file

## Self-Check: PASSED

- FOUND: app/Http/Middleware/EnsureApiDocsGenerated.php, tests/Feature/Api/TimeEntryContractTest.php, tests/Feature/Api/ApiDocumentationAccessTest.php, tests/Support/TimeEntryContract.php, config/scribe.php, app/Http/Controllers/Api/V1/ApiExamples.php
- FOUND commits: 6fce223, f1eae3a
