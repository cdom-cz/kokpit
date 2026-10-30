---
phase: 07-rest-api
plan: 05
subsystem: api
tags: [rest-api, time-entries, writes, create, update, delete, validation, conflict, pest]

requires:
  - phase: 07-rest-api
    provides: "07-01 write route group (ability time:write, throttle:api-write), TimeEntryController, TimeEntryResource, ApiFixtures"
  - phase: 07-rest-api
    provides: "07-02 Scribe docs, ApiExamples, the data-driven ApiDocumentationTest and the confirmed wire contract"
  - phase: 07-rest-api
    provides: "07-04 ReferenceResolver (uuid, project key, task KEY-N) and the list controller"
provides:
  - "POST /api/v1/time-entries: creates a finished entry through CreateTimeEntry, 201 with the resource and a Location header"
  - "PATCH /api/v1/time-entries/{uuid} through UpdateTimeEntry (absent keeps, null clears project or task) and DELETE through DeleteTimeEntry (204)"
  - "TimeEntryPayload: shape rules and the mapping to Action data; duration_seconds becomes ended_at in the request layer"
  - "ApiError::conflict: 409 {message, code} with the stable codes entry_locked and entry_running"
  - "ReferenceResolver::writeProject / writeTask: key to uuid for writes, anything else passes through for the Action to refuse"
affects: [07-06, 07-07, 07-08, 07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 12150
  tasks: 3
  commits: 3

plan_head_before: fcd05f1dd63675b6c27954dc2cd7b6d52922c11c
plan_head_after: 4279ded026128abf433487ad9538af12c8e28e13

tech-stack:
  added: []
  patterns:
    - "The API layer maps input and lets the Actions decide: form requests check shape only, with closure rules that fail with the panel's own Czech message, so a malformed id carries the same text as an unavailable one in the UI"
    - "Controller-side guards for API-only policy (a running entry is not ended or deleted from outside, OD-2) so the Actions and the panel keep their behaviour"
    - "409 answers are {message, code}; 422 keeps Laravel's {message, errors} shape with the Action field keys"
    - "DomainException is caught only around UpdateTimeEntry and DeleteTimeEntry, no global handler"

key-files:
  created:
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - app/Http/Requests/Api/V1/StoreTimeEntryRequest.php
    - app/Http/Requests/Api/V1/UpdateTimeEntryRequest.php
    - app/Http/Responses/ApiError.php
    - tests/Feature/Api/TimeEntryWriteTest.php
  modified:
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - app/Domain/Api/ReferenceResolver.php
    - routes/api.php
    - lang/cs/api.php

key-decisions:
  - "Request rules check shape only; every business rule (context agreement, end after start, billed lock, archived task) stays in the Actions and is read back through __() in the tests"
  - "The interval bound is the integer column limit 2147483647 seconds, not a product cap; a longer interval is 422 under ended_at (duration_too_long), never the PostgreSQL overflow"
  - "A running entry is refused in the controller: PATCH naming ended_at or duration_seconds is 422 under ended_at, DELETE is 409 entry_running (OD-2); description, billable, context and start stay editable"
  - "client_id is nullable in the request: a task or project fixes the client, and the Action answers client_required under client_id when nothing does"

patterns-established:
  - "A refusal-matrix test compares every API message with __() of the Action key, so the panel and the API cannot drift"
  - "A mutation of the bound (rule and interval check) proves the overflow tests can fail"

requirements-completed: []

coverage:
  - id: D1
    description: "POST creates a finished entry from started_at plus either ended_at or duration_seconds with a task key, answers 201 with Location, derives project and client from the task, and stores identical instants for both end forms"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it creates a finished entry from a task key and a duration, and answers 201 with a Location header"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it stores the same instants for ended_at as for the equal duration_seconds"
        status: pass
    human_judgment: false
  - id: D2
    description: "PATCH edits an unbilled entry (absent keeps, null clears the task and keeps the project, duration recomputes the end) and DELETE removes a finished one with 204"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it edits an unbilled entry: description and end change, absent keys keep their value"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it recomputes the end from duration_seconds and the stored start"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it clears the task with null and keeps the project"
        status: pass
    human_judgment: false
  - id: D3
    description: "A billed entry answers 409 entry_locked with the Action message on PATCH and DELETE; a running entry cannot be ended (422 under ended_at) or deleted (409 entry_running) but keeps description, billable and start editable"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it answers 409 entry_locked for a billed entry on PATCH and DELETE and leaves the row unchanged"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses to end a running entry from outside, with 422 under ended_at"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses to delete a running entry with 409 entry_running and deletes a finished one with 204"
        status: pass
    human_judgment: false
  - id: D4
    description: "The API refuses what the UI refuses with the same field keys and messages: inconsistent context, end before start, archived task, names and lowercase keys, no client"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses a task together with the project of another task, and a project of another client, as the panel does"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an end equal to or before the start under ended_at"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an archived task by key and by uuid, a name and a lowercase key under their own keys, and no context under client_id"
        status: pass
    human_judgment: false
  - id: D5
    description: "Offset-less instants are 422, fractions are cut to the whole second, an interval beyond 2147483647 seconds and duration_seconds 2147483648 are 422 and never a database error"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it needs an offset on every instant, takes Z and cuts a fraction to the whole second"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it wants exactly one of ended_at and duration_seconds and a duration of 1 to 2147483647"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it refuses an interval beyond the column limit under ended_at, never a database error"
        status: pass
    human_judgment: false
  - id: D6
    description: "Smuggled user_id, billing_state, billed_at and id are ignored; overlaps are allowed; billable defaults to the task default; the 61st write of a token a minute is 429"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it ignores smuggled keys: the entry is the token owner's, unbilled and has a new uuid"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it accepts an overlapping entry and takes the billable default of the task when billable is absent"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#it limits the writes of one token on the real create route: the 3rd of 2 a minute answers 429"
        status: pass
    human_judgment: false
  - id: D7
    description: "The three write endpoints read well on the generated /docs page (body parameter descriptions, the oneOf of 409 codes, the 204 without a body)"
    verification: []
    human_judgment: true
    rationale: "ApiDocumentationTest proves every status, ability and parameter is in the spec; how the stock theme renders it is a visual judgment, collected for the end-of-phase UAT with the other docs checks."

duration: 35min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 05: Time entry writes over the Actions Summary

**POST, PATCH and DELETE on /api/v1/time-entries run through the same CreateTimeEntry, UpdateTimeEntry and DeleteTimeEntry Actions as the panel, with `duration_seconds` mapped to `ended_at` in the request layer, project keys and task KEY-N resolved to uuids, 409 `{message, code}` for billed and running entries and every UI refusal returned with the same field key and Czech message.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 10 (5 created, 5 modified)

## Accomplishments

- `TimeEntryPayload` holds the shape rules (nullable uuid or key for the three references, instants with an offset, `prohibits` and `required_without` for the end alternative, duration 1 to 2147483647) and the mapping `forCreate` / `forUpdate` that builds only allow-listed Action keys, so a smuggled `user_id`, `billing_state`, `billed_at` or `id` never reaches the Action.
- `POST /api/v1/time-entries` answers 201 with the resource and `Location: route('api.v1.time-entries.show')`; a task key alone is enough because the task fixes project and client.
- `PATCH` sends only the named keys (absent keeps, `null` clears the task and keeps the project, `duration_seconds` is added to the stored or the sent start); `DELETE` answers 204.
- `ApiError::conflict` gives 409 `{message, code}`; the controller maps the Action's `DomainException` to `entry_locked` around update and delete only, and guards a running entry through `isRunning()` (422 under `ended_at` for PATCH, 409 `entry_running` for DELETE) without touching the Actions or the panel.
- The write responses and request bodies are documented for Scribe (endpoint text naming `time:write`, 200/201/204, 404, 409 examples `ENTRY_LOCKED` and `ENTRY_RUNNING`, 422, per-field body parameters, nullable response fields); `ApiDocumentationTest` stays green.
- Full suite 2786 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook (gitleaks) clean on all three commits.

## Task Commits

1. **Task 1 (tracer): POST with started_at, duration_seconds and a task KEY-N** - `c6ab671` (feat)
2. **Task 2: PATCH and DELETE, 409 for billed and running, 422 for ending a running entry** - `146744c` (feat)
3. **Task 3: the refusal matrix of the UI through the API** - `4279ded` (test; the behaviour was already delivered by Tasks 1 and 2, this task pins it)

**Plan metadata:** committed separately as `docs(07-05): complete time entry writes plan`.

## Decisions Made

See key-decisions above. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the tracer verify before expansion: Pest on the write, documentation and route-walk files, Pint and PHPStan all passed.

## TDD Notes

Tasks 2 and 3 are `type="auto" tdd="true"` inside an `execute` plan, committed as one commit each with the tests written first inside the task (the convention of plans 07-02 and 07-04, no separate RED commit). RED was run before the implementation of Tasks 1 and 2 (the new tests failed on 405, the missing routes). Task 3's matrix already passed because the layered design of Tasks 1 and 2 (shape rules in the request, rules in the Actions) covers it; to prove the tests can fail, the interval check and the `max` rule were mutated temporarily and two matrix tests failed, then the production file was restored byte for byte.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] PostgreSQL has no year 0000, so an instant in year 0000 would crash the insert**
- **Found during:** Task 3 (edge exploration of the overflow tests)
- **Issue:** `0000-01-01T00:00:00Z` matches the offset form and a 1-second interval passes the interval bound, but PostgreSQL refuses the value with an out-of-range error (a 500).
- **Fix:** the `INSTANT` regex of `TimeEntryPayload` has a negative lookahead `(?!0000)`, so the value is 422 under the field; pinned in the interval test.
- **Files modified:** `app/Http/Requests/Api/V1/TimeEntryPayload.php`, `tests/Feature/Api/TimeEntryWriteTest.php`
- **Commit:** `c6ab671` (rule), `4279ded` (test)

**2. [Rule 3 - Blocking] Pint on `lang/` reformatted six unrelated files**
- **Found during:** Task 2
- **Issue:** `pint app routes tests lang` touched `lang/cs/actions.php`, `auth.php`, `http-statuses.php`, `pagination.php`, `passwords.php`, `validation.php` (binary operator spacing, outside this plan's scope).
- **Fix:** restored those six files with `git checkout -- <file>` before the next commit; none was staged or committed.

### Plan-vs-reality notes

- **`client_id` is nullable in the request**, per the plan's artifact table and the tracer test (a task key alone creates the entry), while the confirmed contract of 07-02 lists `client_id` as required. Both hold: the field is required unless a project or task fixes the client, and the Action answers `client_required` under `client_id` when nothing does. The documented body parameter says so.
- **Closure rules instead of `uuid` / `regex` rules** for the three references and the instants: they fail with the panel's own message (`client_required`, `project_unavailable`, `task_unavailable`, `invalid_time`) instead of Laravel's generic text. Shape checking is unchanged; no business rule moved into the API.
- **Task 3 is tests only**; the behaviour list was already satisfied by the design of Tasks 1 and 2 (same situation as Task 3 of plan 07-04).
- `show` now reads the entry through the new private `find()` shared with `update` and `destroy`; behaviour unchanged.
- A PATCH with an invalid body on an unknown uuid answers 422 before 404, because the form request validates before the controller body runs. A valid body on an unknown uuid answers 404 as tested.
- A running entry's PATCH that names `ended_at` as `null` is also 422 (it names the key); a client that round-trips a running entry's GET body must omit `ended_at` and `duration_seconds`.

**Total deviations:** 2 auto-fixed (1 missing critical, 1 blocking). **Impact:** none on scope or contract.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-07-24 to T-07-29 are mitigated as planned: allow-listed Action keys with a smuggled-key test (create and patch), `DomainException` mapped to 409 `entry_locked` over the Action's row lock and the KP001 backstop, the interval bound and the duration bound with tests at and beyond the limit, the offset-required instant rule, the `isRunning()` guards, and context forged through ids refused by `TimeEntryInput::context` (keys resolve only to uuids).

## Requirement status

`AP-02` is NOT marked complete: it is also declared by plans 07-06, 07-07, 07-11 and 07-12, none of which has a SUMMARY yet. Left `Pending` in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2786 passed (19463 assertions)
- `ddev exec vendor/bin/pint --test` clean and `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on all three task commits
- Acceptance criteria of all three tasks re-run and passing (greps for `MAX_DURATION_SECONDS = 2147483647`, `prohibits:duration_seconds`, `CreateTimeEntry`, `Location`, `function writeTask`, the store route name, `entry_locked`, `entry_running`, `isRunning()`, `function conflict`, the two lang keys, the matrix literals); no file under `app/Domain/TimeTracking/Actions/` appears in any task commit

## Self-Check: PASSED

- FOUND: app/Http/Requests/Api/V1/TimeEntryPayload.php, StoreTimeEntryRequest.php, UpdateTimeEntryRequest.php, app/Http/Responses/ApiError.php, tests/Feature/Api/TimeEntryWriteTest.php
- FOUND commits: c6ab671, 146744c, 4279ded
