---
phase: 07-rest-api
plan: 16
subsystem: api
tags: [rest-api, cursor-pagination, validation, gap-closure, pest]

requires:
  - phase: 07-rest-api
    provides: "07-04 cursor lists with the id tiebreaker, 07-08 lookup lists"
provides:
  - "ApiCursor: one shared validation rule for the cursor parameter (decode like Cursor::fromEncoded, exact key set, typed values)"
  - "ListTimeEntriesRequest and ListLookupsRequest refuse a cursor the API did not issue for the effective sort with 422 under cursor"
  - "ID_COLUMN constants on TimeEntryController, ClientController, ProjectController and TaskController: the orderBy tiebreaker and an expected cursor key"
  - "Cursor docs on every list: the meta.next_cursor of the same list, sent with the same sort; any other value answers 422"
affects: [07-17, 07-18]

status: complete
requirements-completed: [AP-02, AP-04, AP-05]

actuals:
  tokens: 8100
  tasks: 2
  commits: 2

plan_head_before: 7be3ba239c783b8a63ab6ae3bf363bbc208cdd9e
plan_head_after: 3611f9990406a0c78d6ae145e39cb7a546ed4b92

tech-stack:
  added: []
  patterns:
    - "The expected cursor keys of a list are derived from the same constants that drive its orderBy (sort column, ID_COLUMN), so a sort added later cannot silently skip cursor validation: ApiCursor::kindOf throws a LogicException for an undeclared column"

key-files:
  created:
    - app/Http/Requests/Api/V1/ApiCursor.php
  modified:
    - app/Http/Requests/Api/V1/ListTimeEntriesRequest.php
    - app/Http/Requests/Api/V1/ListLookupsRequest.php
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Controllers/Api/V1/ClientController.php
    - app/Http/Controllers/Api/V1/ProjectController.php
    - app/Http/Controllers/Api/V1/TaskController.php
    - tests/Feature/Api/TimeEntryListTest.php
    - tests/Feature/Api/LookupTest.php

key-decisions:
  - "A cursor the API did not issue is refused with 422, including one Laravel would silently read as the first page (not decodable, not an object, no direction flag)"
  - "The decoded key set must equal exactly the sort column, the id column and _pointsToNextItems; extra keys are refused, so a cursor of another endpoint or sort can never pass"
  - "The kind of a sort column is declared in one place, ApiCursor::kindOf (started_at, created_at instants; name, title text); an unknown column throws a LogicException"
  - "An instant value is accepted only as Y-m-d H:i:s, a real date and time, not year 0000, so PostgreSQL never parses anything the validator did not"
  - "The cursor rule list starts with bail so a non-string cursor reports one error, not two"

patterns-established:
  - "Real-cursor shape pinned by a test that decodes a live meta.next_cursor (keys started_at, time_entries.id, _pointsToNextItems)"

coverage:
  - id: D1
    description: "A cursor with no parameters answers 422 under cursor on /time-entries, /clients, /projects and /tasks, never the UnexpectedValueException 500"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it answers 422 under cursor for a cursor without parameters, never 500"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 under cursor for a cursor without parameters on every lookup list"
        status: pass
    human_judgment: false
  - id: D2
    description: "A cursor of another endpoint or another sort column answers 422 under cursor on every list endpoint"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it answers 422 under cursor for a cursor of another list or of another sort"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 under cursor for a cursor of another list or of another sort"
        status: pass
    human_judgment: false
  - id: D3
    description: "A wrong-typed value or a cursor that is not base64url JSON of an object answers 422 under cursor, so no unvalidated value reaches a uuid, timestamptz or text column"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it answers 422 under cursor for a wrong-typed value or a cursor that is not an object"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 under cursor for a wrong-typed value in a lookup cursor"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 under cursor for a lookup cursor that is not base64url JSON of an object"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every real meta.next_cursor the API issues is accepted: the 120-entry tie test pages all four time entry sorts and each lookup list follows its next cursor for every sort (D-11)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it pages 120 entries with the same start in 50, 50 and 20 without a skipped or repeated row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryListTest.php#it issues a cursor that holds exactly the sort column, the id and the direction flag"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it follows the next cursor of every lookup list for every sort"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 16: Cursor validation on the list endpoints Summary

**One shared ApiCursor rule makes an empty, foreign, wrong-typed or hand-edited cursor a 422 under `cursor` on all four list endpoints, while every cursor the API issues keeps paging for every sort.**

## Performance

- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 9 (1 created, 8 modified)
- **Verification:** `pest tests/Feature/Api tests/Isolation/ApiAccessTest.php` 305 passed; Pint clean; `scripts/check-sensitive.sh` clean

## Accomplishments

- Closed the validation part of gap 4 (WR-03): `GET /api/v1/time-entries`, `/clients`, `/projects` and `/tasks` with the cursor `{"_pointsToNextItems":true}` answered the `UnexpectedValueException` 500; they now answer 422 under `cursor`.
- A cursor from another list (time entry cursor on a lookup, a lookup cursor on another lookup or on time entries) or from another sort column is refused, as is a wrong-typed value (non-uuid id, unparseable or year-0000 instant, non-string or NUL-containing name or title, non-boolean direction flag).
- The expected cursor keys come from the controllers' `ID_COLUMN` and the effective sort column, the same constants that build the `orderBy`, so the validation and the tiebreaker cannot drift.
- Test 0 pins the real shape of an issued cursor (`started_at`, `time_entries.id`, `_pointsToNextItems`, instant as `Y-m-d H:i:s`), confirming the two vendor facts the plan relied on.

## Task Commits

1. **Task 1 (tracer): time entry list refuses a cursor the API did not issue** - `523b993`
2. **Task 2: the same cursor rule on the client, project and task lookups** - `3611f9990406a0c78d6ae145e39cb7a546ed4b92` (short `3611f99`)

The tracer feedback gate ran in the automated form: the Task 1 verify (list, documentation and isolation tests, Pint, PHPStan) passed end to end before the expansion task started.

## Deviations from Plan

### Process note

**Task 2 implementation order.** The Task 2 production edits (ID_COLUMN constants, `ListLookupsRequest` rule) were written before the Task 2 tests rather than after a confirmed RED run. The tests were then added and pass; the RED failure of the same behaviour was confirmed on the time entry list in Task 1 (20 failing cursor tests before `ApiCursor` existed), and the lookup tests are the same shape. No behaviour differs from the plan.

Otherwise none - plan executed exactly as written. The two additions beyond the plan text are `bail` at the head of the cursor rule list (one error instead of two for a non-string cursor) and the extra dataset values `created_at` and `-started_at` in the 120-entry tie test, which the plan asked for.

## Known Stubs

None.

## Threat Mitigation

| Threat | Status | Evidence |
|---|---|---|
| T-07-67 (cursor with missing keys, 500) | mitigated | the "without parameters", "another list or sort" and "not an object" tests on all four endpoints |
| T-07-68 (cursor values bound to uuid, timestamptz, text columns) | mitigated | the wrong-typed value tests: non-uuid id, `x` and year-0000 instants, impossible date, offset instant, numeric values, NUL byte |
| T-07-SC | accepted | no package added |

## Left to plan 07-18

The complete example cursor in the published docs (`ApiExamples`) and the 401/403-before-cursor guard (T-07-69, T-07-70) are plan 07-18, as the plan records. The IN-01 sort description wording and IN-03 key regexes are plan 07-17.

## Issues Encountered

`phpstan analyse` reports 2 pre-existing errors (`cast.string` in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 38 and `app/Console/Commands/StorageCheckCommand.php` line 33). Both files are outside this plan's scope and were not touched; the plan's files introduce no PHPStan errors.

## Self-Check: PASSED

- `app/Http/Requests/Api/V1/ApiCursor.php` exists; commits `523b993` and `3611f99` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run (grep checks, Pest list and lookup suites) and pass.
