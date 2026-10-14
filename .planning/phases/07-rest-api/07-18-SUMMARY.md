---
phase: 07-rest-api
plan: 18
subsystem: api
tags: [rest-api, cursor-pagination, docs, gap-closure, pest]

requires:
  - phase: 07-rest-api
    provides: "07-16 ApiCursor rule: a cursor the API did not issue answers 422 under cursor"
provides:
  - "ApiExamples::TIME_ENTRY_LIST holds a complete example cursor in meta.next_cursor and links.next; GET with it answers 200"
  - "Contract tests tying the example cursor to the key set of a real default-sort cursor and to a 200"
  - "Route-walk guard: a forged cursor on every parameterless GET api/v1 route answers 401 to a guest and 403 to an Admin token without the ability"
affects: []

status: complete
requirements-completed: [AP-01]

actuals:
  tokens: 3200
  tasks: 2
  commits: 2

plan_head_before: 56c6f21aa0d70dde58812c7df37f80e0038bf5fc
plan_head_after: 144f732d96137604b6362cec0bc034b3c1ae2770

tech-stack:
  added: []
  patterns:
    - "Published example values are tied to the real API by a test that decodes them with the same library the API uses (Illuminate\\Pagination\\Cursor)"

key-files:
  created: []
  modified:
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - tests/Feature/Api/TimeEntryContractTest.php
    - tests/Isolation/ApiAccessTest.php

key-decisions:
  - "The example cursor is a literal produced by Cursor::encode() from started_at 2026-10-11 10:00:00, time_entries.id 0199e3a1-6c8d-7e3f-9a52-b2c3d4e5f6a7 and pointsToNextItems true (the fictional values of the example's second item), so the attribute argument stays a constant expression"
  - "The forged-cursor guard walks apiWalkRoutes() filtered to GET routes without a route parameter, not a hand-written list, so a list route added later is covered without editing the test"

patterns-established:
  - "Forged-cursor route walk next to the existing 401/403 walk in tests/Isolation/ApiAccessTest.php"

coverage:
  - id: D1
    description: "The published example cursor decodes to the keys of a real default-sort cursor, links.next carries the same cursor, and GET /api/v1/time-entries with it answers 200"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryContractTest.php#it documents an example cursor with the keys of a real cursor of the default sort"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryContractTest.php#it accepts the example cursor of the documentation with a 200"
        status: pass
    human_judgment: false
  - id: D2
    description: "The generated public docs with the new cursor literal pass the hygiene scan (D-17)"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationHygieneTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "A forged cursor never gets past authentication and the ability check: 401 to a guest and 403 to an Admin token without the ability on every parameterless GET api/v1 route"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Isolation/ApiAccessTest.php#it answers 401 to a guest with a forged cursor on every list route, never 422 or 500"
        status: pass
      - kind: integration
        ref: "tests/Isolation/ApiAccessTest.php#it answers 403 to an Admin token without the ability with a forged cursor on every list route, never 422 or 500"
        status: pass
      - kind: integration
        ref: "tests/Isolation/ApiAccessTest.php#it finds the list routes of the walk, so the forged cursor tests are not vacuous"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 18: Example cursor and forged-cursor guard Summary

**The published time entry example cursor is now a complete `Cursor::encode()` value the API accepts with 200, and a route walk proves a forged cursor is refused by authentication or the ability check on every list route before any cursor validation.**

## Performance

- **Tasks:** 2 (1 tracer, 1 auto)
- **Files:** 3 modified
- **Verification:** `pest tests/Feature/Api/TimeEntryContractTest.php tests/Isolation/ApiAccessTest.php tests/Feature/Api/ApiDocumentationHygieneTest.php` 26 passed; Pint clean; `scripts/check-sensitive.sh` clean

## Accomplishments

- Closed the example-cursor part of gap 4 (WR-03). The old example decoded to a single truncated `time_entries.id` (a 500 before plan 07-16, a 422 after). It is now the encoding of `{"started_at":"2026-10-11 10:00:00","time_entries.id":"0199e3a1-6c8d-7e3f-9a52-b2c3d4e5f6a7","_pointsToNextItems":true}` produced with `Illuminate\Pagination\Cursor::encode()` and used in both `meta.next_cursor` and the `cursor=` part of `links.next`. `per_page=2` and the example.com host are kept.
- Two contract tests: the example decodes to the fictional values of the second example item and its key set equals that of a real `meta.next_cursor` taken from two live entries; the `cursor` of `links.next` equals `meta.next_cursor`; GET with the example cursor answers 200.
- Three guard tests in the access walk: the list route set is not vacuous (contains `api/v1/time-entries` and `api/v1/clients`), a guest with a forged cursor gets 401, and an Admin token without the route's ability gets 403, on every parameterless GET api/v1 route.

## Task Commits

1. **Task 1 (tracer): complete example cursor the API accepts** - `b565692`
2. **Task 2: forged cursor never gets past token and ability checks** - `144f732`

The tracer feedback gate ran in its automated form: the Task 1 verify (contract and hygiene tests, Pint, PHPStan, sensitive-content scan) passed end to end before Task 2 started. RED was confirmed first: both new contract tests failed against the old example (the 200 test with 422 under `cursor`).

## Deviations from Plan

None - plan executed exactly as written. Task 2's tests passed immediately, as the plan predicted: they are a guard, not a fix.

## Known Stubs

None.

## Threat Mitigation

| Threat | Status | Evidence |
|---|---|---|
| T-07-69 (validation before authentication, an oracle for guests) | mitigated | "answers 401 to a guest with a forged cursor on every list route" and "answers 403 to an Admin token without the ability with a forged cursor on every list route" |
| T-07-70 (published example cursor produces 500 or 422) | mitigated | "documents an example cursor with the keys of a real cursor of the default sort" and "accepts the example cursor of the documentation with a 200" |
| T-07-SC | accepted | no package added |

## Requirements

AP-01 is marked complete (`requirements.ready-ids` reported it ready). AP-05 is declared by a sibling plan in this phase that has no SUMMARY yet (`requirements.ready-ids` reported it blocked), so it is intentionally left unmarked.

## Issues Encountered

`phpstan analyse` reports the 2 known pre-existing `cast.string` errors in `app/Console/Commands/ResetAdminTwoFactorCommand.php` (line 38) and `app/Console/Commands/StorageCheckCommand.php` (line 33). Both are outside this plan and untouched; the plan's files add no PHPStan errors.

## Self-Check: PASSED

- Modified files exist; commits `b565692` and `144f732` are ancestors of HEAD; `git rev-list --count` from `plan_head_before` to HEAD is 2.
- Acceptance criteria re-run: `grep` for `next_cursor` in `ApiExamples.php` and `TimeEntryContractTest.php`, `Pagination\Cursor` and `apiWalkRoutes()` in `ApiAccessTest.php`; Pest suites exit 0; `scripts/check-sensitive.sh` exits 0.
