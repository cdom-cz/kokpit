---
phase: 07-rest-api
plan: 08
subsystem: api
tags: [rest-api, lookups, clients, projects, tasks, cursor-pagination, spatie-query-builder, scribe, pest]

requires:
  - phase: 07-rest-api
    provides: "07-01 token-secured /api/v1 read group and ApiFixtures"
  - phase: 07-rest-api
    provides: "07-02 Scribe docs, the data-driven ApiDocumentationTest and the confirmed wire contract (point 6, lookups)"
  - phase: 07-rest-api
    provides: "07-04 FILTERS / SORTS convention, query-builder with value splitting off, ReferenceResolver"
provides:
  - "GET /api/v1/clients, /projects, /tasks: selectable rows only, newest first, cursor paged (50 default, 200 max), filter[name] partial and literal"
  - "Detail by uuid (clients), uuid or project key (projects), uuid or task KEY-N (tasks); unknown, malformed and archived references answer the Czech 404"
  - "ReferenceResolver::selectableClient / selectableProject / selectableTask"
  - "ListLookupsRequest: one form request for the three lists, reading FILTERS and SORTS from the matched controller"
  - "LookupExamples: constant fictional documentation payloads for the three lookups"
affects: [07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 15800
  tasks: 3
  commits: 3

plan_head_before: 194fd7c5d047b63bad00123fa1662ef0f9ed864a
plan_head_after: 09b201a813d8a28b51633805351e7a76f73b09c1

tech-stack:
  added: []
  patterns:
    - "A lookup resource is an explicit key allow-list (client id and name, project six keys, task six keys); a canary test over every list and detail body proves no rate, price, billing, contact or tax value can leave"
    - "One list form request serves several controllers by reading the FILTERS and SORTS constants of the matched route's controller"
    - "Lookup detail routes take a plain string parameter and the resolver validates it by regex or uuid before any query, so every miss answers the same Czech 404"
    - "The documentation test finds a route in the spec through Scribe's own UrlParamsNormalizer, because Scribe documents the parameter of a *.show route as {id}"

key-files:
  created:
    - app/Http/Controllers/Api/V1/ClientController.php
    - app/Http/Controllers/Api/V1/ProjectController.php
    - app/Http/Controllers/Api/V1/TaskController.php
    - app/Http/Controllers/Api/V1/LookupExamples.php
    - app/Http/Resources/Api/V1/ClientResource.php
    - app/Http/Resources/Api/V1/ProjectResource.php
    - app/Http/Resources/Api/V1/TaskResource.php
    - app/Http/Requests/Api/V1/ListLookupsRequest.php
    - tests/Feature/Api/LookupTest.php
  modified:
    - app/Domain/Api/ReferenceResolver.php
    - routes/api.php
    - tests/Support/ApiDocs.php

key-decisions:
  - "No route constraint on the lookup detail parameters: a constraint turns a malformed value into the router's generic 404, while the plan's truth wants the Czech not-found message for an unknown, malformed or archived reference. The resolver checks uuid or key pattern first, so no unvalidated string reaches a uuid column (T-07-39 still mitigated)"
  - "The detail parameter is documented as 'id' (#[UrlParam('id', ...)]), which is the name Scribe gives the last parameter of a route named *.show"
  - "Lists are sorted by the plain column (name, title, created_at) with an id tiebreaker in the direction of the sort; Czech collation is not applied in the API"
  - "filter[name]=% on tasks, clients and projects is literal: FiltersPartial escapes LIKE wildcards and the config disables value splitting, so a comma is literal too"

patterns-established:
  - "Mutation checks for the guarantees: removing the id tiebreaker fails the 120-task paging test, adding a street key to ClientResource fails the canary test"

requirements-completed: []

coverage:
  - id: D1
    description: "GET /api/v1/tasks/{KEY-N or uuid} answers the six-key task; unknown, mistyped, archived and archived-project tasks answer the Czech 404"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers the task lookup by its KEY-N reference and by its uuid with exactly the six keys"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 404 with the Czech message for an unknown, a mistyped or an archived task reference"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 404 for the task of an archived project"
        status: pass
    human_judgment: false
  - id: D2
    description: "Clients and projects list newest first with only selectable rows, narrow by filter[name] (case-insensitive, comma, percent and underscore literal) and filter[client], and answer by uuid or key"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it lists the non-archived clients newest first with exactly the keys id and name"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it matches a comma, a percent sign and an underscore in filter[name] literally"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it lists only selectable projects and narrows them by client"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers a project by its key and by its uuid and 404 for an archived, unknown or mistyped one"
        status: pass
    human_judgment: false
  - id: D3
    description: "Tasks list with filter[name] on the title and filter[project] by key or uuid; 120 tasks sharing one creation time page 50, 50, 20 without a skipped or repeated row"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it lists the tasks of selectable projects newest first and narrows them by project key or uuid"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it pages 120 tasks that share one creation time in 50, 50 and 20 without a skipped or repeated task"
        status: pass
    human_judgment: false
  - id: D4
    description: "Unknown filter keys, a non-uuid filter[client], a malformed filter[project], per_page outside 1..200 and an unknown sort answer 422 under their keys"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 under the offending key for an unknown filter, a bad uuid, a page size or a sort the list does not know"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 for a task list filter or sort it does not know and for a malformed project key"
        status: pass
    human_judgment: false
  - id: D5
    description: "No lookup body contains a canary string of a client's street, city, invoice e-mail and tax number, of a project or task description or internal note, or a rate, price or billing key or value; no write route exists"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it reveals no rate, price, billing, contact or tax data of a client, project or task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it has no write route for clients, projects or tasks"
        status: pass
    human_judgment: false
  - id: D6
    description: "The lookup endpoints are documented in the data-driven completeness test (filters, cursor, per_page, sort, 200, 404, 422, ability)"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it documents every api/v1 route completely"
        status: pass
    human_judgment: false
  - id: D7
    description: "The Lookups group reads well on the generated /docs page in a browser"
    verification: []
    human_judgment: true
    rationale: "Visual rendering of the stock Scribe theme is not asserted by a test; collected for the end-of-phase UAT with the other docs checks."

duration: 35min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 08: Read-only lookups of clients, projects and tasks Summary

**GET /api/v1/clients, /projects and /tasks list the selectable rows with cursor paging and a literal, case-insensitive filter[name], and clients, projects and tasks are fetched by uuid, project key or task KEY-N through explicit allow-list resources that a canary test proves carry no money, contact or tax data.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 12 (9 created, 3 modified)

## Accomplishments

- `ReferenceResolver` gained `selectableClient`, `selectableProject` and `selectableTask`: a uuid or a regex-valid key, resolved within the selectable sets (non-archived client, `Project::selectable()`, non-archived task of a selectable project), else null.
- `ClientController`, `ProjectController` and `TaskController` follow the `FILTERS` / `SORTS` convention (`name`, `client` on projects, `project` on tasks, where `name` matches the task title); every list is `QueryBuilder` with a partial filter, `-created_at` default, an id tiebreaker and `cursorPaginate`.
- `ListLookupsRequest` reads the allowed filter keys and sorts from the matched controller, so one class serves the three lists; every bad value is a 422 under its key.
- `ClientResource` (`id`, `name`), `ProjectResource` and `TaskResource` (six keys each, enums as their string value) list every key explicitly.
- `LookupTest` has 19 tests: key order and values, archived and archived-parent exclusion, comma, percent and underscore matched literally, the 120-task paging, canaries in a client's street, city, invoice e-mail local part and tax number and in a project and a task description and a task billing note, a non-zero client rate and a task billing row, 405 for every write method, 401 for a guest and 403 for a write-only token.
- Two mutation runs proved the tests can fail: removing the id tiebreaker fails the paging test, adding `street` to `ClientResource` fails the canary test; both restored.
- Full suite: 2838 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the lefthook hook (gitleaks) clean on all three commits.

## Task Commits

1. **Task 1 (tracer): GET /api/v1/tasks/DEMO-1 answers the task lookup by its key** - `340108d` (feat)
2. **Task 2: clients and projects list with filter[name] and filter[client], detail by uuid or key** - `f338d41` (feat)
3. **Task 3: task list, read-only and no-money proof with canaries, full suite** - `09b201a` (feat)

**Plan metadata:** committed separately as `docs(07-08): complete lookups plan`.

## Decisions Made

See key-decisions above. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the tracer verify before expansion: Pest on the lookup, documentation and route-walk files, Pint and PHPStan all passed.

## TDD Notes

Each task wrote its tests first inside the task and was committed as one commit (the convention of plans 07-02 and 07-04, since the plan is `execute` type, not `tdd`). RED was run before each implementation: Task 1 (3 of 3 new tests failed with 404 because no route existed), Task 2 (8 of 11 failed: no routes, valid RED), Task 3 (7 of the 18 file tests failed before `TaskController@index` and the task list route existed; once implemented, 17 passed and one failed on a test-side problem fixed within the task, see Deviations).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Scribe documents a `*.show` path parameter as `{id}`, so the completeness test could not find the route**
- **Found during:** Task 1
- **Issue:** Scribe's `UrlParamsNormalizer` renames the last parameter of a route named `tasks.show` to `{id}`, so `ApiDocs` looked up `/api/v1/tasks/{task}` in the spec and reported "is not in the spec" for a correctly documented route (and the `#[UrlParam('task')]` did not attach to the renamed parameter).
- **Fix:** `ApiDocs::documentedUri()` asks Scribe's own `UrlParamsNormalizer` for the documented path; `operation()` and `violations()` use it. The controllers declare `#[UrlParam('id', ...)]`.
- **Files modified:** `tests/Support/ApiDocs.php`, the three controllers
- **Commit:** `340108d`

**2. [Rule 1 - Bug] The first version of my 404 assertion compared the whole body**
- **Found during:** Task 1
- **Issue:** `assertExactJson(['message' => ...])` failed because the test environment renders exception, file and trace keys in debug mode.
- **Fix:** `assertJsonPath('message', ...)`.
- **Files modified:** `tests/Feature/Api/LookupTest.php`
- **Commit:** `340108d`

**3. [Rule 1 - Bug] The guest check ran after a token request in the same test**
- **Found during:** Task 3
- **Issue:** the Sanctum guard keeps the user it resolved in the same application instance, so a guest request after a token request answered 403.
- **Fix:** the guest check is its own test.
- **Files modified:** `tests/Feature/Api/LookupTest.php`
- **Commit:** `09b201a`

### Plan-vs-reality notes

- The plan's artifact table asks for route constraints (`whereUuid`, `[A-Z]{2,6}`, `KEY-N`). They are deliberately not used: a constraint makes the router answer a generic 404 for a malformed reference, but the plan's own truth is "an unknown, malformed or archived reference answers 404 with the Czech not-found message". The resolver validates every reference by uuid or regex before any query, which is the T-07-39 mitigation the threat model names as an alternative ("route constraints and the resolver regex").
- `TaskController::FILTERS` / `SORTS` were added in Task 2 (not Task 3) because `ListLookupsRequest` references the three controllers' constants.
- `Project` and `Client` lists sort by the plain column, not Czech collation; the panel's pickers use `CzechCollation`, the API keeps a stable machine order with an id tiebreaker.

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs of my own tests). **Impact:** none on scope or contract.

## Auth Gates

None.

## Known Stubs

None. `LookupExamples` payloads are intentional fixed fictional documentation data.

## Threat Flags

None beyond the plan's register. T-07-36 (money and contact data) is mitigated by explicit resources and the canary test, T-07-37 (LIKE wildcards, commas) by `FiltersPartial` escaping with value splitting off and the `%`, `_` and comma tests, T-07-38 (archived work) by selectable sets and the archived cases, T-07-39 (non-uuid on a uuid column) by the resolver's uuid or regex check before any query, tested with malformed references on all three details.

## Requirement status

`AP-04` is NOT marked complete: plan 07-12 also declares it (with AP-01, AP-02 and AP-05) and has no SUMMARY yet. Left `Pending` in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2838 passed (20007 assertions)
- `ddev exec vendor/bin/pint --test` clean and `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on all three task commits
- Acceptance criteria of all three tasks re-run and passing (`selectableTask`, `'reference'` and `'title'` in `TaskResource`, route names `api.v1.tasks.show`, `api.v1.clients.index`, `api.v1.projects.show`, `selectable()` and `FILTERS = ['name', 'client']` in `ProjectController`, `FILTERS` in `ListLookupsRequest`, `FILTERS = ['name', 'project']` in `TaskController`, `hourly_rate` and 405 in `LookupTest`)

## Self-Check: PASSED

- FOUND: app/Http/Controllers/Api/V1/ClientController.php, ProjectController.php, TaskController.php, LookupExamples.php, app/Http/Resources/Api/V1/ClientResource.php, ProjectResource.php, TaskResource.php, app/Http/Requests/Api/V1/ListLookupsRequest.php, tests/Feature/Api/LookupTest.php
- FOUND commits: 340108d, f338d41, 09b201a
