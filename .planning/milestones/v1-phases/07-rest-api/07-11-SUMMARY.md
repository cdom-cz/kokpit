---
phase: 07-rest-api
plan: 11
subsystem: api
tags: [rest-api, batch, partial-success, idempotency, scribe, openapi, pest]

requires:
  - phase: 07-rest-api
    provides: "07-02 confirmed wire contract (batch layout, point 5), ApiExamples, data-driven documentation rules"
  - phase: 07-rest-api
    provides: "07-06 TimeEntryPayload with the raw-body external handling, ReferenceResolver, the single create"
  - phase: 07-rest-api
    provides: "07-09 EnsureIdempotency and api_idempotency_keys (stores 2xx, releases the rest)"
provides:
  - "POST /api/v1/time-entries/batch: up to 100 entries, each in its own CreateTimeEntry transaction, answer {saved, failed, errors:[{index, errors}]} with 201 / 207 / 422"
  - "TimeEntryPayload::forBatchItem: per-item shape validation and mapping with the same rules as the single create, non-object items fail under entry"
  - "Docs intro paragraphs: the Idempotency-Key once for both create routes (moved from 07-09) and the batch partial-success and retry rules"
affects: [07-12]

actuals:
  tokens: 9094
  tasks: 3
  commits: 3

plan_head_before: 6624d442c5037206754df497323e59a9dee87f8e
plan_head_after: a3ef1ac46d6b2ec919eccddb3757d9ad78635b43

tech-stack:
  added: []
  patterns:
    - "A batch has no outer transaction: the Action's own transaction per item gives partial success; only a ValidationException is an item error, any other exception is a 500 and releases the idempotency key"
    - "The form request validates only the envelope; items are validated one by one with Validator::make(item, TimeEntryPayload::rules(true)), so one bad item never fails the batch"
    - "A size limit read from config is a closure rule, not max:n, so Scribe appends no untranslated rule sentence to the documented field"

key-files:
  created:
    - app/Http/Controllers/Api/V1/TimeEntryBatchController.php
    - app/Http/Requests/Api/V1/BatchStoreTimeEntryRequest.php
    - tests/Feature/Api/TimeEntryBatchTest.php
  modified:
    - routes/api.php
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - lang/cs/api.php
    - config/scribe.php

key-decisions:
  - "The 422 answer of a batch that saved nothing puts message first, then saved, failed, errors (Laravel convention); 201 and 207 carry exactly saved, failed, errors"
  - "Items are indexed by their position in the validated list, aligned with the raw JSON body decoded as objects; an entries value that is not a JSON array fails every item as not an object (fail closed)"
  - "An unexpected exception mid-batch is not swallowed: 500, items saved before it stay, the idempotency key is released; the docs tell the caller to deduplicate"

patterns-established:
  - "Batch answers are pinned against the ApiExamples constants (key layout of the real 207 and 422 equals the documented example)"

requirements-completed: []

coverage:
  - id: D1
    description: "A batch of valid entries (task key with duration_seconds, project key with ended_at, client only) is saved through CreateTimeEntry and answers 201 {saved, failed, errors: []}; a read-only token gets 403 and a guest 401"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it saves a batch of valid entries through CreateTimeEntry and answers 201 with the counts"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it refuses a token that only reads"
        status: pass
    human_judgment: false
  - id: D2
    description: "Partial success: failed items are listed with zero-based index and field errors, 207 when mixed, 422 with a message when none saved; shape and Action refusals share the format; a failing item leaves no row while its neighbours are saved"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it saves the valid items, skips the one that ends before it starts and answers 207 with its index"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it reports a shape refusal and an Action refusal in the same index and errors format"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it answers 422 with the counts, the errors and a message when no item can be saved"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it leaves the database with exactly the good rows when an item in the middle fails"
        status: pass
    human_judgment: false
  - id: D3
    description: "Envelope limits: an empty list, a missing list, an object, 101 items answer 422 under entries and save nothing; exactly 100 items answer 201; the limit follows kokpit.api.batch_max_items"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it refuses an empty list and more than 100 items under entries and saves nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it saves exactly 100 items"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it reads the item limit from the configuration"
        status: pass
    human_judgment: false
  - id: D4
    description: "Item types: a scalar, a list or null item fails under entry; external {} is stored as an object and external [] fails that item under external"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it fails an item that is not a JSON object under entry and keeps its neighbours"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it keeps an external object that is empty and fails an item whose external is a list"
        status: pass
    human_judgment: false
  - id: D5
    description: "A retried mixed batch with the same Idempotency-Key replays the stored 207 byte for byte with Idempotent-Replayed: true; a 201 replays too; a 422 batch releases its key; a different body under the key is 422 idempotency_key_reused; a 500 mid-batch keeps the earlier items and releases the key; a batch is one write against the limit"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it replays the stored 207 byte for byte for a retried mixed batch and saves nothing new"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it releases the key of a batch that saved nothing so the corrected batch with the same key is saved"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it refuses the same key with a different batch body with 422 idempotency_key_reused"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it keeps the items saved before an unexpected fault, answers 500 and releases the key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it counts a batch as one write against the per-token limit"
        status: pass
    human_judgment: false
  - id: D6
    description: "The generated spec documents POST /api/v1/time-entries/batch with 201, 207, 409, 422, the Idempotency-Key header and the entries list with the fields of an item; the docs page carries the Idempotency-Key and batch paragraphs; the documented examples have the layout of the real answers"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it documents the batch route with 201, 207, 409 and 422 and the Idempotency-Key header"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it documents the fields of a batch item and the entries list in the spec"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it explains the Idempotency-Key once for both create routes and the batch retry rules on the docs page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it documents answers that have the layout of the real answers"
        status: pass
    human_judgment: false

duration: 40min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 11: Batch create with partial success Summary

**POST /api/v1/time-entries/batch records up to 100 time entries through the same mapping and CreateTimeEntry Action as the single create, each in its own transaction, answers 201 / 207 / 422 listing only the failed items with their index, and replays a stored 201 or 207 for a retried Idempotency-Key.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 8 (3 created, 5 modified)

## Accomplishments

- `BatchStoreTimeEntryRequest` validates the envelope only (`entries` required, a list, at most `kokpit.api.batch_max_items`, read at validation time). `required` already refuses an empty list.
- `TimeEntryPayload::forBatchItem` refuses an item that is not a JSON object (checked against the raw body decoded as objects, so `{}` and `[]` are told apart) under `entry` with `api.errors.batch_item_not_object`, validates with `rules(true)` and maps through `forCreate`, taking `external` from the raw item.
- `TimeEntryBatchController` (invokable, `final`) runs `forBatchItem` and `CreateTimeEntry::handle` per item with no outer transaction. A `ValidationException` adds `{index, errors}`; anything else propagates (500) and `EnsureIdempotency` releases the key. 201 when all saved, 207 when mixed, 422 with `message` when none saved.
- Route `api.v1.time-entries.batch` in the write group with `EnsureIdempotency`, so it is one write against the per-token limit and a 201 or 207 is stored while a 422 releases the key.
- Docs: `#[BodyParam]` for `entries` (`object[]`) and every `entries[].<field>`, 201 / 207 / 409 / 422 examples (`BATCH_ALL_SAVED`, `BATCH_MIXED`, `BATCH_NONE_SAVED`), and two `intro_text` sections: the Idempotency-Key for both create routes (moved from 07-09) and the batch rules (statuses, retry with the same key, caller-side deduplication, the mid-batch crash residual risk).
- 22 new tests in `TimeEntryBatchTest`; full suite 2887 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook clean on all three commits.

## Task Commits

1. **Task 1 (tracer): a batch of valid entries is saved through CreateTimeEntry and answers 201** - `93074d3` (feat)
2. **Task 2: partial success, 207 and 422, only the failed items with their index** - `944c364` (feat)
3. **Task 3: replay-safe retries and the documentation** - `a3ef1ac` (feat)

**Plan metadata:** committed separately as `docs(07-11): complete batch create plan`.

## Decisions Made

See key-decisions. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the targeted Pest files, Pint and PHPStan green before expansion.

## TDD Notes

As in plans 07-02 to 07-10, tests are written first inside each task and each task is one commit. RED was observed before the implementation: Task 1 failed 2 of 2 tests (404, route absent); Task 2 failed 6 of 12 (all 207/422 branches and the non-object and `external` cases, while the envelope tests already passed as characterisation of the form request); Task 3 failed 2 (the spec had no `entries` item schema, the intro had no Idempotency-Key text) and the replay, release, mismatch and rate-limit tests passed at once because the generic middleware already covers the batch route, which is the point of putting the route behind it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Scribe appended an untranslated rule sentence to the `entries` field**
- **Found during:** Task 3 (generated spec)
- **Issue:** with `min:1` and `max:100` rules Scribe added `:Attribute musí obsahovat více než 1 prvků. ...` (an unrendered Czech sentence with a raw `:Attribute` placeholder, and a wrong "more than 1") to the public docs.
- **Fix:** the limit is a closure rule (`atMostItems()`, message from `validation.max.array`); the empty list is refused by `required`. The documented description says "1 to 100".
- **Files modified:** `app/Http/Requests/Api/V1/BatchStoreTimeEntryRequest.php`
- **Commit:** `a3ef1ac`

**2. [Rule 3 - Blocking] `entries` rendered as an object, not an array, in the OpenAPI schema**
- **Found during:** Task 3
- **Issue:** with `bodyParameters()` on the form request declaring `object[]`, the spec said `type: object` with the item fields as its properties.
- **Fix:** `entries` is declared by `#[BodyParam('entries', 'object[]', ...)]` next to the `entries[].<field>` attributes, as the plan's docs row names it; `bodyParameters()` was removed from the request. The spec now has `type: array` with `items.properties` and `required: [started_at]`.
- **Files modified:** `app/Http/Controllers/Api/V1/TimeEntryBatchController.php`, `app/Http/Requests/Api/V1/BatchStoreTimeEntryRequest.php`
- **Commit:** `a3ef1ac`

### Plan-vs-reality notes

- The two `lang/cs/api.php` strings were added in Task 1 (the plan lists `lang/cs/api.php` under Task 2) because `forBatchItem`, built in Task 1, already uses `batch_item_not_object`; the 422 string followed in the same commit for completeness.
- Task 1's controller had no catch (every valid item saves); the catch and the 207 / 422 branches came with Task 2 as planned.
- The mid-batch fault test (500, earlier items stay, key released) uses a `TimeEntry::creating` listener that throws on the second insert, because `CreateTimeEntry` is `final`. The plan did not list this test; it proves the "unexpected exception is not swallowed" decision.
- A few extra tests beyond the plan: replay of a stored 201 batch, the limit following the config value, exactly-100 items, and the example-layout pin.

**Total deviations:** 2 auto-fixed (1 bug in the generated docs, 1 blocking in the spec shape). **Impact:** none on the contract in `must_haves`.

## Auth Gates

None.

## Known Stubs

None. `BATCH_ALL_SAVED`, `BATCH_MIXED` and `BATCH_NONE_SAVED` are fixed fictional documentation payloads, checked against the real answers by test.

## Threat Flags

None beyond the plan's register. T-07-48 (oversized batches) is covered by the 100 / 101 tests, the config-driven limit, the one-write-per-batch test and the `external` cap of the Action; T-07-49 by the replay tests; T-07-50 by the non-object test; T-07-51 by the per-item transaction tests (only the good rows exist after a mixed batch).

## Deferred Issues

Out of scope, logged in `.planning/phases/07-rest-api/deferred-items.md`: the same untranslated `:Attribute ...` rule sentences appear on the documented fields of earlier endpoints (single create and update `duration_seconds`, lookup and list parameters). Also, the OpenAPI `examples` of the `entries` property is an empty list (the curl example on the page is correct).

## Requirement status

`AP-02` and `AP-05` are NOT marked complete: plan 07-12 also declares both and has no SUMMARY yet. Left as is in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2887 passed (20479 assertions)
- `ddev exec vendor/bin/pint --test` clean; `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` clean; hook (sensitive-content, gitleaks) clean on all three commits
- Acceptance greps of the three tasks hold (`api.v1.time-entries.batch`, `time-entries/batch`, `batch_max_items`, `CreateTimeEntry`, `batch_item_not_object`, `batch_none_saved`, `BATCH_MIXED`, `101`, `207`, `Idempotent-Replayed` in the test and in `config/scribe.php`)

## Self-Check: PASSED

- FOUND: app/Http/Controllers/Api/V1/TimeEntryBatchController.php, app/Http/Requests/Api/V1/BatchStoreTimeEntryRequest.php, tests/Feature/Api/TimeEntryBatchTest.php
- FOUND commits: 93074d3, 944c364, a3ef1ac
