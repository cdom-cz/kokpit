---
phase: 07-rest-api
plan: 07
subsystem: api
tags: [activity-log, audit, middleware, rest-api, check-constraint, pest]

requires:
  - phase: 07-rest-api
    provides: "07-05 time entry write endpoints (POST, PATCH, DELETE under /api/v1/time-entries), ApiFixtures"
  - phase: 03-audit
    provides: "ActivitySource::as() wrapper and the source column with its CHECK (D-08)"
provides:
  - "ActivitySourceLabel::Api ('api'), Czech label 'API'"
  - "LabelApiActivitySource middleware appended to the api route group: every API request writes its activity rows with source 'api'"
  - "Migration replacing activity_log_source_check by name with web, console, job, webhook, api"
  - "ApiActivitySourceTest, plus CHECK and overview filter cases in the existing Operations tests"
affects: [07-11, 07-12]

actuals:
  tokens: 3142
  tasks: 2
  commits: 2

plan_head_before: fbe36b75cb4a332083b6b274a0520c3fc677732b
plan_head_after: 1a2f848b3fbb1e271ed51df6a1b2d3aea4bb3ae3

tech-stack:
  added: []
  patterns:
    - "A request-scoped activity label is set by a middleware of the route group through ActivitySource::as(), never from input"

key-files:
  created:
    - database/migrations/2026_10_14_000200_add_api_to_activity_log_source_check.php
    - app/Http/Middleware/LabelApiActivitySource.php
    - tests/Feature/Api/ApiActivitySourceTest.php
  modified:
    - app/Domain/Audit/ActivitySourceLabel.php
    - lang/cs/enums.php
    - bootstrap/app.php
    - tests/Feature/Operations/ActivitySourceTest.php
    - tests/Feature/Operations/ActivityViewsTest.php

key-decisions:
  - "The label is a middleware appended to the api group via $middleware->api(append: [...]); the docs routes are outside that group and keep their own label"
  - "The migration has no down(), like its analog 2026_10_08_000200"
  - "The Operations CHECK test counts ActivitySourceLabel::cases() instead of a literal, so the next label does not break it"

patterns-established:
  - "Tests read activity rows with DB::table('activity_log') because the Activity model carries the fail-closed partner scope"

requirements-completed: []

coverage:
  - id: D1
    description: "A time entry created, updated or deleted through /api/v1 writes its activity rows with source 'api' and the Admin as causer"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiActivitySourceTest.php#it logs an entry created through the API with the source api and the Admin as causer"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiActivitySourceTest.php#it logs an update and a delete through the API with the source api"
        status: pass
    human_judgment: false
  - id: D2
    description: "The label comes from the execution context only; request body, query and header cannot choose it; web and console writes keep their labels after an API request"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiActivitySourceTest.php#it does not let a request choose its own source label"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiActivitySourceTest.php#it leaves the label after the request: a panel write in a web request stays web and a console write stays console"
        status: pass
    human_judgment: false
  - id: D3
    description: "activity_log_source_check accepts api and still refuses any other value (cli)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivitySourceTest.php#it accepts the api source and still refuses cli, which is no label"
        status: pass
    human_judgment: false
  - id: D4
    description: "The activity overview offers API in the source filter, lists only API rows for it and labels them API"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#it offers API in the source filter and lists only API rows for it, labelled API"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 07: API activity source label Summary

**Every request of the api route group now runs inside `ActivitySource::as(ActivitySourceLabel::Api, ...)`, so a time entry written by n8n shows up in the activity overview and history as "API" instead of "web", and the database CHECK allows the new value and still refuses any other.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (tracer, TDD-style test task)
- **Files:** 8 (3 created, 5 modified)

## Accomplishments

- `LabelApiActivitySource` wraps the request in `ActivitySource::as(Api, ...)`, registered once with `$middleware->api(append: [...])`, so every present and future route of `routes/api.php` is covered without per-route declarations. Nothing in the request is read (T-07-35).
- Migration `2026_10_14_000200` drops `activity_log_source_check` by name and re-adds it with `'api'`.
- The activity overview source filter is built from `ActivitySourceLabel::cases()`, so it offers "API" with no resource change; an API row without a causer shows the label "API".
- Full suite: 2819 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook (gitleaks) clean on both commits.

## Task Commits

1. **Task 1 (tracer): an entry created through POST /api/v1/time-entries writes its activity row with source api** - `ee414a1` (feat)
2. **Task 2: the label for update and delete, other sources unchanged, CHECK and overview filter** - `1a2f848` (test)

## TDD Notes

Task 1 is a tracer (one commit). RED was run before the implementation: `ApiActivitySourceTest` failed on the planned assertion (`'web'` found where `'api'` was expected, 3 assertions executed, the target test the only one in the file), semantic assessment: valid RED, the request ran end to end and the row was written with the old label. Task 2 is `type="auto" tdd="true"` whose behaviours the plan says should pass on Task 1's code; they did, apart from one test that asserted a different thing (see Deviations).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A hard-coded label count in an existing test broke on the new case**
- **Found during:** Task 1 (running the Operations tests before the commit)
- **Issue:** `ActivitySourceTest` "accepts each of the four labels" expected exactly 5 rows (four labels plus null); the new `api` case made it 6.
- **Fix:** the expectation is `count(ActivitySourceLabel::cases()) + 1` and the title no longer says "four".
- **Files modified:** `tests/Feature/Operations/ActivitySourceTest.php`
- **Commit:** `ee414a1`

**2. [Rule 1 - Bug] My update test changed a field the audit does not log**
- **Found during:** Task 2
- **Issue:** the first version of the update/delete test patched `description`, but `TimeEntry` logs only an attribute allowlist that excludes it, so no `updated` row existed.
- **Fix:** the test patches `ended_at`, which is logged.
- **Files modified:** `tests/Feature/Api/ApiActivitySourceTest.php`
- **Commit:** `1a2f848`

### Plan-vs-reality notes

- The plan said the overview "source column shows API". There is no separate source column: `ActivityPresenter::causerLabel` shows the source label where an activity has no user. The test asserts that (`causerLabel` returns "API") and the filter option.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None. T-07-34 (API writes recorded as browser edits) and T-07-35 (client choosing its own label) are mitigated as planned and covered by tests.

## Requirement status

AP-02 is not marked complete: sibling plans 07-11 and 07-12 still declare it, and the API write surface is not finished in this plan.

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_14_000200_add_api_to_activity_log_source_check.php, app/Http/Middleware/LabelApiActivitySource.php, tests/Feature/Api/ApiActivitySourceTest.php
- FOUND commits: ee414a1, 1a2f848
