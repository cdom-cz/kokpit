---
phase: 07-rest-api
plan: 06
subsystem: api
tags: [rest-api, time-entries, external, jsonb, check-constraint, pest]

requires:
  - phase: 07-rest-api
    provides: "07-05 TimeEntryPayload, store/update/destroy on the controller, the Action-backed write endpoints"
  - phase: 07-rest-api
    provides: "07-02 TimeEntryContract, TimeEntryContractTest, ApiExamples and the confirmed wire contract"
  - phase: 07-rest-api
    provides: "07-01 config kokpit.api.external_max_bytes (4096)"
provides:
  - "time_entries.external: nullable jsonb with the CHECK time_entries_external_object_check, no index, not logged"
  - "TimeEntryInput::external(): object-only rule with a depth cap of 8 and a byte cap from config, shared by every writer"
  - "TimeEntryPayload::rawBody(): the request body decoded as objects once per request, so {} stays an object"
  - "The time entry contract key external after billed_at, in the resource, the examples and the generated spec"
affects: [07-07, 07-11, 07-12]

actuals:
  tokens: 6400
  tasks: 3
  commits: 3

plan_head_before: 217679577885bf0407f8b2a3548a12fdf8fe9c5f
plan_head_after: 372d84933a18e06927bc3442c2141c674dc0f5a0

tech-stack:
  added: []
  patterns:
    - "A JSON member whose {} / [] difference matters is read from the raw body decoded as objects; the Action receives the stdClass untouched"
    - "An optional key reaches an Action exactly when the request names it (absent keeps, null clears), the same convention as description"
    - "A column on a frozen table needs no guard change: kokpit_guard_frozen_row compares to_jsonb(NEW) - allowed with to_jsonb(OLD)"

key-files:
  created:
    - database/migrations/2026_10_14_000100_add_external_to_time_entries_table.php
    - tests/Feature/Api/TimeEntryExternalTest.php
  modified:
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
    - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - app/Http/Requests/Api/V1/StoreTimeEntryRequest.php
    - app/Http/Requests/Api/V1/UpdateTimeEntryRequest.php
    - app/Http/Resources/Api/V1/TimeEntryResource.php
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
    - lang/cs/kokpit.php
    - tests/Support/TimeEntryContract.php
    - tests/Feature/Api/AuthenticationTest.php
    - tests/Feature/Schema/TimeEntriesTableTest.php

key-decisions:
  - "Cast object, not array: {} stays an object and is returned as {} (never [])"
  - "Depth is counted in nested objects or lists with the external object itself as level 1 (json_encode depth 8 accepts 8 levels and refuses 9); size is the byte length of the compact JSON (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)"
  - "A json_encode failure that is not a depth error (a non-finite float) answers external_not_object instead of a 500"
  - "Without a JSON object body (a form post) a named external arrives as an array and is refused as external_not_object"
  - "external is placed directly after billed_at, before created_at and updated_at, in the resource, the examples and the contract"

patterns-established:
  - "A raw-body helper cached in request attributes keeps one decode per request"
  - "Mutation of the byte and depth bounds proved the boundary tests can fail"

requirements-completed: []

coverage:
  - id: D1
    description: "An entry created with external {source, id, tags} returns the same content on create, GET and list; an entry created without it returns external null"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it stores an external object on create and returns the same object on read"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it returns external as null for an entry created without it"
        status: pass
    human_judgment: false
  - id: D2
    description: "{} is stored, typed object in jsonb and returned as {}; [], [1], a string, a number and true answer 422 under external and create nothing"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it stores and returns an empty object as {} and never as []"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it refuses a value that is not an object with 422 under external and creates nothing"
        status: pass
    human_judgment: false
  - id: D3
    description: "4096 compact bytes accepted and 4097 refused (external_too_large), multi-byte text counted in bytes, 8 levels accepted and 9 refused (external_too_deep)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it accepts an object of exactly the byte limit and refuses one byte more"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it counts multi-byte text in bytes, not in characters"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it accepts 8 levels of nesting and refuses 9 under external"
        status: pass
    human_judgment: false
  - id: D4
    description: "PATCH keeps external when absent, clears it with null and replaces it whole with an object (no merge)"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it keeps the stored external when a PATCH does not name it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it clears the stored external with null on a PATCH"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it replaces the stored external whole on a PATCH, with no merge"
        status: pass
    human_judgment: false
  - id: D5
    description: "The database refuses a non-object external with time_entries_external_object_check, and refuses a change of external on a billed row with KP001; through the API that change is 409 entry_locked"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/TimeEntriesTableTest.php#it refuses an external value that is not a JSON object (time_entries_external_object_check)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/TimeEntriesTableTest.php#it refuses to edit the content of a billed row with dataset external"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it answers 409 entry_locked to a PATCH that changes only external of a billed entry and keeps the value"
        status: pass
    human_judgment: false
  - id: D6
    description: "Changing only external writes no activity row, and no panel page of an entry (list, view, edit with its Livewire snapshot) contains the value"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it writes no activity row when only external changes, and none for a create that carries it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryExternalTest.php#it renders no part of external on the panel pages of an entry, snapshot included"
        status: pass
    human_judgment: false
  - id: D7
    description: "The key external after billed_at is pinned in the resource output, in every ApiExamples payload and in every oneOf variant of the generated spec"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryContractTest.php"
        status: pass
    human_judgment: false
  - id: D8
    description: "The external body parameter and response field read well on the generated /docs page"
    verification: []
    human_judgment: true
    rationale: "Rendering of the stock Scribe theme is a visual judgment, collected for the end-of-phase UAT with the other docs checks."

duration: 35min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 06: The external object of a time entry Summary

**A time entry now carries an optional jsonb `external` object for import helpers: accepted on create and update, returned on every entry, object-only (the `{}` versus `[]` decision comes from the raw request body), capped at 4096 bytes and 8 levels, frozen on billed entries by the existing guard, and shown nowhere in the panel or the activity log.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 17 (2 created, 15 modified)

## Accomplishments

- Migration `2026_10_14_000100`: `external jsonb` with `time_entries_external_object_check` (`external IS NULL OR jsonb_typeof(external) = 'object'`), no index, no unique constraint. The Phase 6 frozen-row guard needed no change.
- `TimeEntryInput::external()` is the one rule: null stays null, only a `stdClass` is an object, depth is checked by `json_encode` with `EXTERNAL_MAX_DEPTH = 8`, size is the compact JSON byte length against `kokpit.api.external_max_bytes`. Three Czech messages under `time.errors`.
- `CreateTimeEntry` validates it before the transaction and sets it with `forceFill`; `UpdateTimeEntry` sets it inside the locked transaction only when the key is named (absent keeps, null clears, object replaces whole). The key is not fillable and not in `LoggedAttributes`.
- `TimeEntryPayload::rawBody()` decodes the body as objects once per request; `forCreate` and `forUpdate` add `external` exactly when the body names it. The controller passes the raw body in.
- The resource returns `external` after `billed_at`; `ApiExamples` (all four payloads), `TimeEntryContract`, the Scribe body parameters of both requests and the `data.external` response fields document it, and `ApiDocumentationTest` / `TimeEntryContractTest` stay green.
- Full suite 2813 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook (gitleaks) clean on all three commits.

## Task Commits

1. **Task 1 (tracer): create with external and read it back** - `3106770` (feat)
2. **Task 2: update semantics and the size, depth and type limits** - `c1729db` (feat)
3. **Task 3: schema CHECK and KP001 freeze, 409 on billed, no activity row, nothing in the panel** - `372d849` (test, plus the panel fix below)

**Plan metadata:** committed separately as `docs(07-06): complete external object plan`.

## Decisions Made

See key-decisions above. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the tracer verify before expansion: the four targeted Pest files, Pint and PHPStan passed.

jsonb does not keep the order of the keys of an object (it sorts by key length, then bytes). JSON does not define key order, so the content is equal, but a client that compares the raw text will see a different order. The create body parameter says so, and the tests compare with `toEqual`.

## TDD Notes

Tasks 2 and 3 are `type="auto" tdd="true"` inside an `execute` plan, one commit each with tests written first inside the task (the convention of plans 07-02, 07-04, 07-05). RED was run before the Task 2 implementation: the three PATCH tests (clear, replace whole, set on an entry that had none) failed; the create-side limit tests already passed because the depth rule and the size rule were built in Task 1 together with `TimeEntryInput::external()` (the plan splits them across tasks, one function holds both). To prove the bound tests can fail, the size comparison (`>` to `>=`) and the depth constant (8 to 9) were mutated temporarily: the byte, multi-byte and depth tests failed, then the file was restored. Task 3's schema and API tests passed on the Task 1-2 code as the plan predicted, except the panel test, which found a real leak (below).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] The edit page leaked `external` through the Livewire snapshot**
- **Found during:** Task 3 (panel rendering test)
- **Issue:** Filament fills the edit form with `attributesToArray()` and ships the page state to the browser as an HTML-escaped Livewire snapshot, so the stored object appeared in the markup of `/admin/time-entries/{id}/edit` although no field shows it. This violates threat T-07-32 (not rendered by any Filament class, D-15 "not in the UI").
- **Fix:** `EditTimeEntry::mutateFormDataBeforeFill()` drops `external`. The test searches the raw markup (`assertDontSee(..., false)`) for a canary and for `&quot;external&quot;` on the list, view and edit pages; it failed before the fix and passes after.
- **Files modified:** `app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php`, `tests/Feature/Api/TimeEntryExternalTest.php`
- **Commit:** `372d849`

**2. [Rule 3 - Blocking] The key list pinned in `AuthenticationTest` did not know `external`**
- **Found during:** Task 1
- **Issue:** `tests/Feature/Api/AuthenticationTest.php` asserts the exact 15 keys of a time entry.
- **Fix:** the list now includes `external` after `billed_at`.
- **Files modified:** `tests/Feature/Api/AuthenticationTest.php`
- **Commit:** `3106770`

### Plan-vs-reality notes

- **`tests/Feature/Api/TimeEntryContractTest.php` is unchanged.** The plan lists it, but the test reads its keys from `Tests\Support\TimeEntryContract`, which gained `'external' => '?array'` (a JSON object decodes to an array in a test response). The test file itself needed no edit.
- **`ApiExamples`:** all four time entry payloads got the key (the finished example and the first list item carry `{"source": "demo-tracker", "id": "A-1001"}`, the running entry and the second list item `null`), because the contract says every key is always present.
- **`TimeEntryController`** was edited (three `#[ResponseField('data.external', 'object', ..., nullable: true)]` attributes and passing the raw body); the plan did not list it.
- **The depth rule is already in Task 1** (`TimeEntryInput::external()` is one function); Task 2 only adds the tests at the bounds and the update branch.
- **Task 3 production change:** only `EditTimeEntry` (deviation 1); the behaviour list otherwise passed on the earlier code.

**Total deviations:** 2 auto-fixed (1 missing critical, 1 blocking). **Impact:** none on scope or contract.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-07-30 to T-07-33 are mitigated as planned: the 4096-byte and 8-level caps with tests at both bounds, the raw-body object decision plus the `object` cast and the CHECK as backstop, no mass assignment and no rendering (including the snapshot leak fixed here), and the Action refusal plus the KP001 trigger on billed time.

## Requirement status

`AP-02` is NOT marked complete: it is also declared by plans 07-07, 07-11 and 07-12, none of which has a SUMMARY yet. Left `Pending` in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2813 passed (19571 assertions)
- `ddev exec vendor/bin/pint --test` clean and `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on all three task commits
- Acceptance greps of all three tasks re-run: `jsonb_typeof(external) = 'object'`, `'external' => 'object'`, `function external`, `TimeEntryInput::external`, `'external'` in the resource, `getContent`, `EXTERNAL_MAX_DEPTH = 8`, `external_max_bytes`, `array_key_exists('external'`, `4097`, `time_entries_external_object_check`

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_14_000100_add_external_to_time_entries_table.php, tests/Feature/Api/TimeEntryExternalTest.php, app/Domain/TimeTracking/TimeEntryInput.php, app/Http/Requests/Api/V1/TimeEntryPayload.php
- FOUND commits: 3106770, c1729db, 372d849
