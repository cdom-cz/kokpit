---
phase: 07-rest-api
plan: 09
subsystem: api
tags: [rest-api, idempotency, postgresql, middleware, time-entries, pest]

requires:
  - phase: 07-rest-api
    provides: "07-05 POST /api/v1/time-entries, ApiError::conflict, the write route group"
  - phase: 07-rest-api
    provides: "07-02 data-driven ApiDocumentationTest that asks every EnsureIdempotency route for the header, 409 and 422"
  - phase: 07-rest-api
    provides: "07-01 config kokpit.api.idempotency_ttl_hours and idempotency_stale_lock_seconds"
provides:
  - "api_idempotency_keys table: unique (token_id, key), cascade on token delete, 2xx-only and completion CHECKs"
  - "IdempotencyStore (claim, complete, release) over an atomic insertOrIgnore, with IdempotencyClaim and IdempotencyOutcome"
  - "EnsureIdempotency middleware: replay with Idempotent-Replayed, 422 reused / invalid, 409 in progress, store 2xx, release otherwise"
  - "POST /api/v1/time-entries carries the middleware and documents the header, the replay header and the 409 / 422 answers"
affects: [07-10, 07-11, 07-12]

actuals:
  tokens: 9000
  tasks: 3
  commits: 3

plan_head_before: 16a1429e8735b453562107afcd4b64f195fd8ee4
plan_head_after: 8167d4637a998494708111e509fb9ece5575ed86

tech-stack:
  added: []
  patterns:
    - "A claim is one atomic INSERT ... ON CONFLICT DO NOTHING in its own statement; ownership of a claim is its whole-second locked_at, so a request overtaken after going stale changes nothing when it finishes late"
    - "Only the Eloquent builder touches the table (insertOrIgnore, update, delete), so the escape-hatch rule against raw table queries stays satisfied"
    - "The store is a non-final class so the multi-process proof of plan 07-10 can bind a lock-less double"

key-files:
  created:
    - database/migrations/2026_10_14_000300_create_api_idempotency_keys_table.php
    - app/Domain/Api/Models/IdempotencyKey.php
    - app/Domain/Api/Idempotency/IdempotencyStore.php
    - app/Domain/Api/Idempotency/IdempotencyClaim.php
    - app/Domain/Api/Idempotency/IdempotencyOutcome.php
    - app/Http/Middleware/EnsureIdempotency.php
    - tests/Feature/Api/IdempotencyTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - routes/api.php
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - app/Http/Controllers/Api/V1/ApiExamples.php
    - lang/cs/api.php
    - tests/Arch/ModelDeclarationTest.php

key-decisions:
  - "locked_at, expires_at and completed_at are timestamptz(0): the claim time is the version of a claim, and a takeover moves it by at least the stale-lock setting, so two claims never share a value"
  - "complete() and release() match on id, completed_at IS NULL and the locked_at the request claimed with; a slow request overtaken by a stale takeover can neither store its answer nor delete its successor's claim"
  - "Mismatch (different body) is decided before staleness: a stale claim of another body answers 422 idempotency_key_reused, not a takeover"
  - "A request authenticated without a persisted token id (not possible with the stateless bearer setup) passes through without a claim rather than failing"
  - "The 422 {message, code} for a bad or reused key is built in the middleware; ApiError stays unchanged"

patterns-established:
  - "Idempotency tests arrange a claim row with the exact request hash (sha256 of method, path and json_encode of the body that postJson sends) and move time with travelTo"
  - "Mutation proof: widening the key pattern made the 256-character and the space cases fail, then the file was restored"

requirements-completed: []

coverage:
  - id: D1
    description: "The same Idempotency-Key and body sent twice creates one entry; the second answer is the stored 201 with the byte-identical body and Idempotent-Replayed: true, the first has no such header; without a key every request creates an entry and no row is stored"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it creates one entry for the same key and body sent twice, and replays the stored 201 byte for byte"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it creates an entry on every request that carries no key"
        status: pass
    human_judgment: false
  - id: D2
    description: "A different body under the same key is 422 idempotency_key_reused; a fresh claim is 409 idempotency_in_progress; a claim older than 120 s (tested at 121 s) is taken over atomically and completed; an expired row counts as absent"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it refuses the same key with a different body with 422 idempotency_key_reused and creates nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it answers 409 idempotency_in_progress while another request holds a fresh claim"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it takes over a claim older than 121 seconds without a response and completes it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it treats a completed row past its expiry as absent and stores a fresh one"
        status: pass
    human_judgment: false
  - id: D3
    description: "Only 2xx answers are stored: a 422 or an exception leaves no row and the corrected request with the same key creates the entry; the stored row has status 201, a completion time and an expiry 24 h after the claim"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it leaves no row after a 422 and lets the corrected request with the same key create the entry"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it leaves no row when the controller throws, and the exception surfaces as 500"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it stores status 201, the completion time and an expiry 24 hours after the claim"
        status: pass
    human_judgment: false
  - id: D4
    description: "Keys are scoped per token and honoured only on create: two tokens never replay each other, revoking a token deletes its keys, PATCH, DELETE and GET ignore the header and store nothing; malformed keys are 422 idempotency_key_invalid"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it keeps the keys of two tokens of the same Admin apart, so neither replays the other"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it deletes the keys of a token with the token"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it ignores the header on PATCH, DELETE and GET and stores nothing for them"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it refuses an empty, an over-long and a non-printable key with 422 idempotency_key_invalid and creates nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "The generated spec documents the Idempotency-Key header, the 409 and 422 answers and Idempotent-Replayed on POST /api/v1/time-entries, and the documentation rule is not vacuous"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IdempotencyTest.php#it documents the Idempotency-Key header, the 409 and 422 answers and the replay header on POST time-entries"
        status: pass
    human_judgment: false
  - id: D6
    description: "The new endpoint text on the generated /docs page reads clearly (the long description of the create endpoint, the two idempotency error examples)"
    verification: []
    human_judgment: true
    rationale: "Wording and layout of a long endpoint description in the stock theme is a visual and editorial judgment; collected for the end-of-phase UAT."

duration: 45min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 09: Idempotent create Summary

**An optional `Idempotency-Key` on POST /api/v1/time-entries makes a retried create safe: PostgreSQL holds an atomic per-token claim, a repeat gets the stored 201 byte for byte with `Idempotent-Replayed: true`, a different body is 422, a running twin is 409, a crashed claim is taken over after 120 s, and only 2xx answers are ever stored.**

## Performance

- **Duration:** about 45 min
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 13 (7 created, 6 modified)

## Accomplishments

- `api_idempotency_keys` (unique `api_idempotency_keys_token_key_unique`, FK to `personal_access_tokens` ON DELETE CASCADE, CHECKs that a stored status is 2xx and that a response and its completion time exist together) with the `IdempotencyKey` model, morph alias `api_idempotency_key` and the `ModelDeclarationTest` entry.
- `IdempotencyStore::claim()` runs one `insertOrIgnore`; on conflict it deletes an expired row and retries once, answers Mismatch for another body, Replay for a completed row, InProgress for a fresh claim, and takes a stale claim over with a guarded UPDATE (1 row wins, 0 rows is InProgress). `complete()` and `release()` act only on the claim the request still owns.
- `EnsureIdempotency` validates the key (`\A[\x21-\x7E]{1,255}\z`), hashes method, path and raw body, replays the stored body with `Content-Type: application/json` and `Idempotent-Replayed: true`, and stores 2xx or releases on any other status and on exceptions.
- Wired on POST time-entries only, after the group's auth, ability and write limit. Czech strings in `lang/cs/api.php`.
- Docs on the endpoint: `#[Header('Idempotency-Key', 'demo-key-0001')]`, the description of the contract (24 h, per token, replay header, only successful answers stored, no `Location` on a replay), the 409 `IDEMPOTENCY_IN_PROGRESS` and 422 `IDEMPOTENCY_KEY_REUSED` examples. `config/scribe.php` untouched (plan 07-11 writes the `intro_text` paragraph).
- Full suite 2858 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook (gitleaks) clean on all three commits.

## Task Commits

1. **Task 1 (tracer): the same key twice creates one entry and replays the stored 201** - `a4d6921` (feat)
2. **Task 2: D-07 branches and OD-3 (reused, in progress, stale takeover, expiry, release on failure)** - `eb49de4` (feat)
3. **Task 3: key hygiene, per-token scope, create-only, revoke cascade, documentation** - `8167d46` (test)

**Plan metadata:** committed separately as `docs(07-09): complete idempotent create plan`.

## Decisions Made

See key-decisions above. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by re-running the tracer verify before expansion: the targeted Pest files, Pint and PHPStan passed.

## TDD Notes

Tasks 2 and 3 are `type="auto" tdd="true"` committed as one commit each with tests written first inside the task (the convention of plans 07-02 to 07-08). Task 2 RED: 3 of the 9 new tests failed on the planned assertions (takeover answered 409 instead of 201, the expired row was still replayed or refused, the late-owner test); the other two branches (reused, in progress) already passed because the tracer middleware carried those answers. Task 3 passed on first run because the middleware and docs of Tasks 1 and 2 already carried the behaviour (same situation as Task 3 of plan 07-05); to prove the tests can fail, the key pattern was widened temporarily and the 256-character and space cases failed, then the file was restored.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pint `fully_qualified_strict_types` on the model docblock**
- **Found during:** Task 1
- **Issue:** `\Carbon\CarbonImmutable` in the `@property` docblock failed the Pint check.
- **Fix:** imported `Carbon\CarbonImmutable` in `IdempotencyKey`.
- **Commit:** `a4d6921`

### Plan-vs-reality notes

- Task 1 already carries the 409 / 422 documentation, the key validation and the 409 / 422 middleware branches, because the Task 1 verify runs `ApiDocumentationTest`, which requires the header, 409 and 422 for any route with `EnsureIdempotency`. Task 2 therefore added the store branches (expiry, takeover, ownership) and tests; Task 3 added tests.
- `complete()` and `release()` carry an ownership condition on `locked_at` that the plan did not name. Without it a slow request overtaken by a stale takeover could overwrite or delete its successor's claim (research A7 residual); `locked_at` is stored in whole seconds so the comparison is exact.
- A claim row holds `timestamptz(0)` instants (the plan said `timestamptz`).

**Total deviations:** 1 auto-fixed (blocking). **Impact:** none on scope or contract.

## Residual risk (documented, not hidden)

A crash after the entry commit but before the response is stored lets a stale takeover (after 120 s) create a second entry; D-15 already makes the caller responsible for deduplication. The multi-process proof of the atomic claim and the purge job follow in plan 07-10; the batch route joins in plan 07-11.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-07-40 (cross-token replay: lookups always by the current token id, two-token test), T-07-41 (hash over method, path and raw body, 422 test), T-07-42 (atomic claim outside any request transaction, 409 test; multi-process proof is plan 07-10), T-07-43 (key pattern, 24 h expiry; purge job is plan 07-10), T-07-44 (CHECK on response_status, release on non-2xx and exceptions, tests) are mitigated as planned.

## Requirement status

`AP-05` is NOT marked complete: plans 07-10, 07-11 and 07-12 also declare it and have no SUMMARY yet. Left as is in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2858 passed (20121 assertions)
- `ddev exec vendor/bin/pint --test` clean; `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` clean; hook (sensitive-content, gitleaks) clean on all commits
- Acceptance criteria of all three tasks re-run and passing (greps for the unique index name, cascade, `insertOrIgnore`, `Idempotent-Replayed`, `EnsureIdempotency::class`, the morph alias, `idempotency_key_reused`, `idempotency_in_progress`, `idempotency_stale_lock_seconds`, `expires_at`, `121`, `idempotency_key_invalid`, `IDEMPOTENCY_IN_PROGRESS`)
- No file under `app/Domain/TimeTracking/Actions/` or `config/scribe.php` appears in any task commit

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_14_000300_create_api_idempotency_keys_table.php, app/Domain/Api/Models/IdempotencyKey.php, app/Domain/Api/Idempotency/IdempotencyStore.php, IdempotencyClaim.php, IdempotencyOutcome.php, app/Http/Middleware/EnsureIdempotency.php, tests/Feature/Api/IdempotencyTest.php
- FOUND commits: a4d6921, eb49de4, 8167d46
