---
phase: 07-rest-api
plan: 04
subsystem: api
tags: [rest-api, time-entries, list, cursor-pagination, filters, spatie-query-builder, europe-prague, pest]

requires:
  - phase: 07-rest-api
    provides: "07-01 token-secured /api/v1 read group, TimeEntryController, TimeEntryResource, config kokpit.api.page_size, ApiFixtures"
  - phase: 07-rest-api
    provides: "07-02 Scribe docs, ApiExamples, the data-driven ApiDocumentationTest and the confirmed wire contract"
  - phase: 07-rest-api
    provides: "07-03 api/v1 route walk (ApiAccessTest) that picked up the list route without an edit"
provides:
  - "GET /api/v1/time-entries: newest first, cursor paginated (50 default, 200 max), {data, links, meta.next_cursor}"
  - "Filters client, project and task (uuid or key), billable, billed, from and to (inclusive Europe/Prague days or exact ISO 8601 instants)"
  - "ReferenceResolver: uuid or project key / task reference to id for clients, projects and tasks (archived rows included)"
  - "PragueDay: the Prague calendar day bounds, extracted from the Filament TimeEntryResource and shared with the API"
  - "ListTimeEntriesRequest: every bad query value is a 422 under its field"
affects: [07-05, 07-06, 07-07, 07-08, 07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 30450
  tasks: 3
  commits: 3

plan_head_before: 0ba5146e6fe01154fc14f6652264dd2a12c68c5c
plan_head_after: 958879fd3a2e041cfb58780727d8e73c4fc81db8

tech-stack:
  added:
    - "spatie/laravel-query-builder ^7.3 (MIT, in require; license check passes)"
  patterns:
    - "List controllers declare public const FILTERS and SORTS; the query-builder allow-list, the request key list and the docs attributes read the same lists"
    - "Every list query is a form request: unknown filter keys fail the array: rule with 422, so the query-builder's own 400 never reaches a client"
    - "A cursor page orders by the sort column, then by time_entries.id in the direction of the sort, so ties never skip or repeat a row"
    - "References are resolved by a domain service before a query; an unknown key narrows to nothing (whereRaw false), a malformed one is a 422 in the request"
    - "Prague day arithmetic lives in one static class on plain dates, used by the panel filter and the API"

key-files:
  created:
    - app/Domain/Api/ReferenceResolver.php
    - app/Domain/TimeTracking/Support/PragueDay.php
    - app/Http/Requests/Api/V1/ListTimeEntriesRequest.php
    - config/query-builder.php
    - tests/Feature/Api/TimeEntryListTest.php
    - tests/Feature/TimeTracking/PragueDayTest.php
  modified:
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - app/Filament/Resources/TimeEntryResource.php
    - routes/api.php
    - config/scribe.php
    - tests/Support/ApiDocs.php
    - composer.json
    - composer.lock

key-decisions:
  - "filter_value_splitting_enabled is false in config/query-builder.php: no filter takes a list, and a comma in a value must not split it (research Pitfall 10)"
  - "The request rejects unknown filter keys with 422 through an array: key list; an instant filter[to] is inclusive; filters on project and task include archived rows"
  - "A date-only filter[from] / filter[to] is a Europe/Prague calendar day, both ends inclusive (OD-5); the 25-hour day 2026-10-25 is exactly bounded by 2026-10-24T22:00:00Z and 2026-10-25T23:00:00Z"
  - "The documentation completeness rule for FILTERS / SORTS applies to collection routes only (GET without a path parameter), not to the detail route of the same controller"
  - "An instant filter must be ISO 8601 with an offset or Z; a literal + in the query string decodes to a space and is refused, so the docs tell clients to encode it as %2B"

patterns-established:
  - "Tie-heavy paging tests insert rows with DB::table so every row shares one start and one creation time"
  - "A mutation check (removing the id tiebreaker) proves the paging tests fail without the production line"

requirements-completed: []

coverage:
  - id: D1
    description: "GET /api/v1/time-entries lists newest first with cursor pagination, narrows by client, and follows meta.next_cursor to the next page"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it lists the entries newest first and narrows them to one client"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it follows the cursor of a page to the next entry"
        status: pass
    human_judgment: false
  - id: D2
    description: "Project and task filters take a uuid or a key (archived rows included), an unknown key matches nothing, billable and billed narrow by flag and state"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it finds a project by uuid and by key alike, and an unknown key matches nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it finds the entries of an archived project and an archived task by key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it filters by the billable flag and the billing state"
        status: pass
    human_judgment: false
  - id: D3
    description: "Date-only from / to are inclusive Prague days (the 25-hour 2026-10-25 edge), instants are exact, and the panel's period filter keeps its behaviour after the helpers moved to PragueDay"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it treats a date-only range as inclusive Prague days across the 25-hour day"
        status: pass
      - kind: unit
        ref: "tests/Feature/TimeTracking/PragueDayTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php (panel period filter, unchanged and green)"
        status: pass
    human_judgment: false
  - id: D4
    description: "120 entries with one start page in 50, 50 and 20 under three sorts without a skipped or repeated row; page size and sort are validated"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it pages 120 entries with the same start in 50, 50 and 20 without a skipped or repeated row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it holds 50 entries a page by default, accepts 200 and refuses a size outside 1 to 200"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every bad query value answers 422 under its field; every item has exactly the confirmed keys and no money"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it answers 422 under the filter key for a malformed value, never 400 or 500"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it gives every listed entry exactly the confirmed keys and no rate, price or amount"
        status: pass
    human_judgment: false
  - id: D6
    description: "The generated docs page shows the list endpoint with its seven filters, cursor, per_page and sort readably in a browser"
    verification: []
    human_judgment: true
    rationale: "ApiDocumentationTest proves every parameter is in the spec; how the stock theme renders and reads is a visual judgment, collected for the end-of-phase UAT with the other docs checks."

duration: 35min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 04: Time entry list with filters and stable paging Summary

**GET /api/v1/time-entries lists time newest first with cursor paging (id tiebreaker, 50 to 200 a page) and filters by client, project and task (uuid or key), billable, billed and an inclusive Europe/Prague day range, with the day rule now shared with the panel through PragueDay.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 3 (tracer, TDD task, TDD edge task)
- **Files:** 14 (6 created, 8 modified including `composer.lock`)

## Accomplishments

- `spatie/laravel-query-builder ^7.3` is installed (`composer check-licenses`: 220 packages, all allowed). The package publishes no config under the plan's tag (its tag differs from research A1), so `config/query-builder.php` was copied from the vendor file and `filter_value_splitting_enabled` set to false with a comment.
- `TimeEntryController@index` builds the list with `QueryBuilder::for(...)`, `allowedFilters` callbacks for the `FILTERS` list, `allowedSorts(SORTS)`, default `-started_at`, then `orderBy('time_entries.id', <direction of the sort>)` and `cursorPaginate()->withQueryString()`.
- `ListTimeEntriesRequest` is the single gate of the query string: an `array:` key list for `filter`, uuid for `filter.client`, uuid or key patterns for project and task, `true,false,1,0` for the two booleans, a real `Y-m-d` day or an ISO 8601-with-offset instant for `from` / `to`, `per_page` 1 to 200, `cursor` max 1024 and a `sort` allow-list.
- `PragueDay` (`day`, `midnight`, `start`, `endExclusive`) was moved out of `TimeEntryResource`: the panel's `periodBounds` and date filter call it, the two private helpers are gone, and the panel's existing period tests stay green. The 25-hour day 2026-10-25 and the 23-hour day 2027-03-28 are pinned at the instant level.
- `ReferenceResolver` resolves a uuid as given and a project key or `KEY-N` reference through `withTrashed()`; a value that is neither never reaches a uuid column.
- The list route is documented: `#[Endpoint]` naming `time:read`, a `#[QueryParam]` for all seven filters, `cursor`, `per_page` and `sort`, the list and 422 responses, and a pagination paragraph in the Scribe `intro_text`.

## Task Commits

1. **Task 1 (tracer): list newest first with a cursor and filter[client]** - `00d3f3b` (feat)
2. **Task 2: every filter, ReferenceResolver and the shared PragueDay** - `b86d7f3` (feat)
3. **Task 3: stable paging, page size and sort edges, full suite** - `958879f` (test; the edge behaviour was already delivered by Tasks 1 and 2, so this task adds the pinning tests only)

**Plan metadata:** committed separately as `docs(07-04): complete time entry list plan`.

## Decisions Made

See key-decisions above. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the tracer verify before expansion: Pest on the list, documentation and route-walk files, `composer check-licenses`, Pint and PHPStan all passed.

## TDD Notes

Tasks 2 and 3 are `type="auto" tdd="true"` inside an `execute` plan and were committed as one commit each (tests first inside the task, no separate RED commit, same convention as plan 07-02). RED was run before the implementation of Task 2 (11 list tests and every PragueDay test failed on the missing behaviour or class). For Task 3 the behaviour was already correct, so the new tests passed on first run; to prove they can fail, the id tiebreaker line was removed temporarily and the four paging tests failed, then the line was restored.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Query-builder config could not be published with the planned tag**
- **Found during:** Task 1
- **Issue:** `vendor:publish --provider=... --tag=query-builder-config` printed "No publishable resources" (research assumption A1; the package registers the config under another tag).
- **Fix:** copied `vendor/spatie/laravel-query-builder/config/query-builder.php` by hand, as the plan allows for exactly this case, and added `declare(strict_types=1)` (Pint).
- **Files modified:** `config/query-builder.php`
- **Commit:** `00d3f3b`

**2. [Rule 1 - Bug] The docs completeness rule demanded list parameters on the detail route**
- **Found during:** Task 1
- **Issue:** `ApiDocs::violations` applied the `FILTERS` / `SORTS` rule to every route of a controller that declares the constants, so `GET /time-entries/{timeEntry}` failed for missing `cursor`, `per_page`, `filter[...]` and `sort`.
- **Fix:** the rule applies to collection routes only (GET without a path parameter); the probe test for the rule still passes.
- **Files modified:** `tests/Support/ApiDocs.php`
- **Commit:** `00d3f3b`

**3. [Rule 3 - Blocking] A date-like integer in a test tripped the sensitive-content hook**
- **Found during:** Task 2 commit
- **Issue:** an 8-digit integer in a PragueDay dataset matched the company-id pattern of `scripts/check-sensitive.sh`.
- **Fix:** replaced with `2026`; no hook bypass.
- **Files modified:** `tests/Feature/TimeTracking/PragueDayTest.php`
- **Commit:** `b86d7f3`

### Plan-vs-reality notes

- Task 3 was written as a fix-up task; because Tasks 1 and 2 already built the tiebreaker, page-size and sort rules from the artifact table, Task 3 contains tests only and no production change.
- `ReferenceResolver::clientId()` is part of the artifact table and is built and used by later plans (writes, lookups); the list filter for `filter[client]` validates a uuid in the request and does not call it.

**Total deviations:** 3 auto-fixed (1 bug, 2 blocking). **Impact:** none on scope or contract.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-07-20 to T-07-23 and T-07-SC are mitigated as planned: allow-listed filters and sorts with bound parameters (the only raw SQL is a constant `false` and `?::timestamptz` placeholders), uuid and key rules before any uuid column, `per_page` capped from config, the same explicit resource keys on every item (test pins the key list and refuses money-like key names), and the package legitimacy checked in research plus `composer check-licenses`.

## Issues Encountered

- A literal `+` in an unencoded `filter[from]=...+02:00` decodes to a space and fails the format rule with 422. Clients must send `%2B` or use `Z`; the parameter descriptions say so. No change in behaviour was made (the plan defines the form as ISO 8601 with offset or Z).

## Requirement status

`AP-02` is NOT marked complete: it is also declared by plans 07-05, 07-06, 07-07, 07-11 and 07-12, none of which has a SUMMARY yet (detail, create, update, delete are still to be built). Left `Pending` in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2764 passed (19250 assertions)
- `ddev exec vendor/bin/pint --test` clean and `phpstan analyse`: no errors
- `ddev composer check-licenses`: 220 packages, all allowed
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on all three task commits

## Self-Check: PASSED

- FOUND: app/Domain/Api/ReferenceResolver.php, app/Domain/TimeTracking/Support/PragueDay.php, app/Http/Requests/Api/V1/ListTimeEntriesRequest.php, config/query-builder.php, tests/Feature/Api/TimeEntryListTest.php, tests/Feature/TimeTracking/PragueDayTest.php
- FOUND commits: 00d3f3b, b86d7f3, 958879f
- Acceptance criteria of all three tasks re-run and passing (composer require, `filter_value_splitting_enabled`, `cursorPaginate` / `FILTERS` / id tiebreaker, route name, `PragueDay::` use in resource and controller, zero `function pragueMidnight` in the resource, `withTrashed` and `Str::isUuid` in the resolver, the DST test instants, `120` and `per_page=201` in the tests)
