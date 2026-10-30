---
phase: 07-rest-api
plan: 15
subsystem: api
tags: [rest-api, batch, idempotency, error-mapping, gap-closure, pest]

requires:
  - phase: 07-rest-api
    provides: "07-13 shared interval bound, 07-14 storable-instant envelope (invalid_time item error), 07-09 EnsureIdempotency (2xx stored, anything else releases the key), 07-11 batch endpoint"
provides:
  - "TimeEntryBatchController maps every item failure into errors[]: a ValidationException keeps its field errors, any other Throwable is report()ed and listed under entry with api.errors.batch_item_failed"
  - "lang/cs/api.php errors.batch_item_failed"
  - "Batch regression tests: injected server fault with same-key replay, every item faulting then retried with the same key, bad-range start and end items with same-key replay"
  - "Documentation of the server-fault entry error (207 response description, Scribe Batches section)"
affects: []

status: complete
requirements-completed: [AP-02, AP-05]

actuals:
  tokens: 9000
  tasks: 2
  commits: 2

plan_head_before: 2f3caf3dd7064f34a56eee2543539e58b0b08af1
plan_head_after: cd731ca8a1124221b7e6847b968dc1686723b2f4

tech-stack:
  added: []
  patterns:
    - "Every item failure of a batch is an item error; the exception goes to the log, only a generic message goes to the client"
    - "Fault injection in feature tests through TimeEntry::creating() with a counter or a flag, asserted with Exceptions::fake() and Exceptions::assertReported()"

key-files:
  created: []
  modified:
    - app/Http/Controllers/Api/V1/TimeEntryBatchController.php
    - lang/cs/api.php
    - config/scribe.php
    - tests/Feature/Api/TimeEntryBatchTest.php

key-decisions:
  - "Supersedes the 07-11 decision (only a ValidationException is an item error, any other exception is a 500 that keeps earlier items and releases the key, research Pattern 5). A 500 after committed items released the Idempotency-Key and the advertised same-key retry saved those items twice, breaking roadmap success criterion 3. New rule: every item failure is an item error; the answer shape {saved, failed, errors:[{index, errors}]} and the 201 / 207 / 422 rules are unchanged"
  - "The exception message and class never reach the response; one generic Czech message under entry ('send it again in a new batch'), the exception itself goes to report()"
  - "A batch in which every item faults answers 422 batch_none_saved and releases the key, as for any batch that saved nothing"
  - "Residual risk stays documented: a process that dies in the middle of a batch (restart, lost connection) can leave saved items without a stored answer; deduplication by external stays the caller's guard (D-15)"

patterns-established:
  - "A late fault must not turn a request that already committed work into a non-2xx answer when the answer carries an idempotency key"

coverage:
  - id: D1
    description: "A non-validation fault on one batch item answers 207 with {index, errors: {entry: [batch_item_failed]}}, the neighbours are saved, the exception is reported and its message is absent from the body, the key is stored"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it reports a fault of the server in the middle of a batch as a failed item, answers 207 and stores the key"
        status: pass
    human_judgment: false
  - id: D2
    description: "The same key and body after such a 207 replay it byte for byte with Idempotent-Replayed: true and save nothing new; a batch where every item faults answers 422 batch_none_saved, saves nothing, releases the key, and the same key then saves the corrected batch"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it replays the stored 207 of a batch with a server fault and saves nothing twice"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it answers 422 and releases the key when every item meets a fault of the server, so the same key can be used again"
        status: pass
    human_judgment: false
  - id: D3
    description: "A bad-range item (started_at 0001-01-01T00:00:00+02:00, or ended_at 9999-12-31T23:59:59-12:00) between valid items answers 207 with invalid_time at its index and field; a same-key retry replays and the database holds exactly the valid rows"
    requirement: AP-02
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it reports a start outside the storable years as a failed item and replays the 207 on a retry with the same key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it reports an end outside the storable years under ended_at and replays the 207 on a retry"
        status: pass
    human_judgment: false
  - id: D4
    description: "The spec and the docs page tell an integrator that an entry error can come from a fault on the server and that the item is sent again in a new batch"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/TimeEntryBatchTest.php#it tells an integrator what an entry error caused by a fault on the server means and what to do"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 15: Batch item faults mapped to errors[] Summary

**`TimeEntryBatchController` now reports every failing item, validation refusal or server fault, as an `errors[]` entry (a fault under `entry` with one generic Czech message, the exception only to `report()`), so a late fault can no longer turn a half-committed batch into a 500 that releases the Idempotency-Key and lets a same-key retry save the earlier items twice.**

## Performance

- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 4 modified, 0 created
- **Duration:** about 20 min
- **Completed:** 2026-10-14
- **Verification:** full suite `ddev exec vendor/bin/pest` 3006 passed; Pint clean; PHPStan reports only the two known pre-existing errors in `ResetAdminTwoFactorCommand.php` and `StorageCheckCommand.php`; `scripts/check-sensitive.sh` clean

## Accomplishments

- Closed gap 3 (WR-02, batch part) of 07-VERIFICATION: before, a non-validation exception on a late item ended the request in 500 after earlier items were committed, `EnsureIdempotency` released the key, and the retry the docs advertise saved those items a second time. Now such a batch answers 207, the key is stored, and the retry replays the 207 byte for byte.
- A batch in which every item faults answers 422 with `batch_none_saved` and the three entry errors, stores nothing, releases the key; after the fault is gone the same key and body save all three.
- Pinned the regression the gap names: a bad-range item (`started_at` 0001-01-01T00:00:00+02:00, and a year-9999 `ended_at` with a negative offset) between valid items answers 207 with `invalid_time` at its own field and index, and a same-key retry replays with exactly the valid rows in the database. This relies on plan 07-14.
- Documented the new item error on the 207 response and in the Scribe "Batches" section (send such an item again in a new batch, with a new key); the last retry bullet now speaks of the server stopping in the middle of a batch (restart, lost connection).

## Task Commits

1. **Task 1 (tracer): a fault in the middle of a batch is a failed item** - `4dcbfb6`
2. **Task 2: bad-range item regression with a same-key retry, documentation of the new item error** - `cd731ca`

The tracer feedback gate ran in the automated form: the Task 1 verify (batch and idempotency tests, Pint, PHPStan) passed end to end before Task 2 started. RED was confirmed first: the three new fault tests failed with 500 before the controller change, and the docs test failed before the doc edit (Task 2 tests 1 and 2 passed at once, as the plan predicted, since plan 07-14 and Task 1 were already in).

## Decision Superseded

Plan 07-11 decided that only a `ValidationException` is an item error and any other exception is a 500 that keeps the earlier items and releases the idempotency key (research Pattern 5). That decision is superseded: every item failure is an item error. A `ValidationException` carries its own field errors; any other `Throwable` is passed to `report()` and listed under `entry` with `api.errors.batch_item_failed`. The answer shape `{saved, failed, errors: [{index, errors}]}` and the 201 / 207 / 422 rules are unchanged, so importers that read `errors[].index` keep working. Reversibility is costly (D-14): an importer that treated a 500 as "retry everything" now sees a 207 or 422.

## Deviations from Plan

None - plan executed exactly as written.

## Threat Mitigation

| Threat | Status | Evidence |
|---|---|---|
| T-07-64 (late fault releases the key, same-key retry duplicates entries) | mitigated | "replays the stored 207 of a batch with a server fault and saves nothing twice"; both bad-range replay tests |
| T-07-65 (exception detail disclosed in the item error) | mitigated | the fault tests assert neither the message nor the class name appears in the body |
| T-07-66 (swallowed server faults) | mitigated | `report($e)` on every mapped fault; `Exceptions::assertReported(RuntimeException::class)` in the tests |
| T-07-SC | accepted | no package added |

## Issues Encountered

`phpstan analyse` reports 2 pre-existing errors (`cast.string` in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 38 and `app/Console/Commands/StorageCheckCommand.php` line 33). Both files are outside this plan's scope and were not touched.

## Known Stubs

None.

## Threat Flags

None - no new endpoint, auth path or schema change.

## Next Phase Readiness

This is the last plan of the 07-rest-api gap closure (07-13 to 07-18). Phase 7 is ready for re-verification (`/gsd-verify-work 07`). Residual risk kept in the docs: a process that dies in the middle of a batch can leave saved items without a stored answer; deduplication by `external` stays the caller's guard (D-15).

## Self-Check: PASSED

- Modified files exist on disk (all 4 key-files).
- Commits `4dcbfb6` and `cd731ca` are ancestors of HEAD.
- All task acceptance greps pass; `ddev exec vendor/bin/pest` (full suite) exits 0 with 3006 passed.
