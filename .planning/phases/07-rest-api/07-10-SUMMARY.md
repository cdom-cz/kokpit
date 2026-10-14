---
phase: 07-rest-api
plan: 10
subsystem: api
tags: [rest-api, idempotency, scheduler, concurrency, postgresql, pest]

requires:
  - phase: 07-rest-api
    provides: "07-09 api_idempotency_keys, IdempotencyStore (non-final), EnsureIdempotency on POST /api/v1/time-entries"
provides:
  - "PurgeExpiredIdempotencyKeys job (KokpitJob, #[Idempotent]) scheduled daily on one server as kokpit-api-idempotency-prune"
  - "Multi-process proof (8 real PHP processes through the HTTP kernel) that one Idempotency-Key yields one time entry"
  - "UnclaimedIdempotencyStore test double and a rendezvous mutation run that creates 8 entries, proving the harness sees the race"
affects: [07-11, 07-12]

actuals:
  tokens: 7762
  tasks: 2
  commits: 2

plan_head_before: 7e6a926a1ed3369b491040f089f8bb61c4694eaa
plan_head_after: dc89fffff32113a6828f3f7ab771111fa09bf5e4

tech-stack:
  added: []
  patterns:
    - "The concurrency harness of the timer is reused for HTTP: a worker boots the HTTP kernel, binds the store, waits at a shared start barrier and sends one real request with bearer token and key"
    - "A lock-less test double with a file rendezvous (UnclaimedIdempotencyStore) forces the overlap the way UnlockedTimerLock does, so the mutation run does not depend on timing luck"

key-files:
  created:
    - app/Domain/Api/Jobs/PurgeExpiredIdempotencyKeys.php
    - tests/Feature/Api/PurgeIdempotencyKeysTest.php
    - tests/Concurrency/IdempotencyConcurrencyTest.php
    - tests/Concurrency/idempotency-worker.php
    - tests/Support/UnclaimedIdempotencyStore.php
  modified:
    - routes/console.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php

key-decisions:
  - "The purge deletes only rows with expires_at < now(), whether completed or not; a live claim is never touched, and a row past its expiry is already treated as absent by the store"
  - "The worker prints the raw response content as body, so replays are compared byte for byte; 409 bodies are decoded by the test"
  - "The start barrier is a shared timestamp (KOKPIT_BARRIER_AT, argument as fallback), exactly as in the timer harness, instead of a barrier directory"

patterns-established:
  - "Concurrency helpers are global Pest functions, so the new file prefixes everything with idempotencyConcurrency to avoid clashes with the timer suite"

requirements-completed: []

coverage:
  - id: D1
    description: "A scheduled job PurgeExpiredIdempotencyKeys deletes idempotency rows whose expires_at has passed and keeps the others; a second run deletes nothing more; it runs daily as kokpit-api-idempotency-prune on one server"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/PurgeIdempotencyKeysTest.php#it purges a key a day after use, keeps a live one, and lets the same key create a new entry again"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/PurgeIdempotencyKeysTest.php#it keeps a row that expires in the future, even one that was never completed"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/PurgeIdempotencyKeysTest.php#it schedules the purge daily on one server under a stable name"
        status: pass
      - kind: unit
        ref: "tests/Arch/JobContractTest.php#it has exactly the expected application jobs under app/"
        status: pass
    human_judgment: false
  - id: D2
    description: "Eight real PHP processes posting the same create with the same Idempotency-Key at the same moment leave exactly one time entry; every answer is 201 or 409 idempotency_in_progress, exactly one 201 is not a replay, every replayed body equals the original"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Concurrency/IdempotencyConcurrencyTest.php#it creates exactly one entry when 8 processes post the same create with the same key at the same moment"
        status: pass
    human_judgment: false
  - id: D3
    description: "The same harness with the claim removed (UnclaimedIdempotencyStore held at a rendezvous until all eight arrive) creates eight entries, which proves the harness can see the race"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Concurrency/IdempotencyConcurrencyTest.php#it creates 8 entries when the claim is removed and 8 processes post the same create with the same key (mutation run)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The concurrency test and its worker refuse a database whose name does not end in _test and refuse a rendezvous with the real store; every committed row is removed again and checked in afterEach"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Concurrency/IdempotencyConcurrencyTest.php#it refuses to run against a database that is not a test database"
        status: pass
      - kind: integration
        ref: "tests/Concurrency/IdempotencyConcurrencyTest.php#it refuses the rendezvous together with the real store, and a rendezvous of fewer than two parties"
        status: pass
    human_judgment: false

duration: 30min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 10: Idempotency purge and multi-process proof Summary

**Expired idempotency keys are purged daily on one server, and eight real PHP processes posting one create with one key through the HTTP kernel leave exactly one time entry (one 201, seven 409 idempotency_in_progress), while the same harness with the claim removed creates eight.**

## Performance

- **Duration:** about 30 min
- **Tasks:** 2 (tracer, one TDD task)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `PurgeExpiredIdempotencyKeys` (`final`, extends `KokpitJob`, `#[Idempotent(how: ...)]`) deletes `expires_at < now()` through the Eloquent builder; scheduled `->daily()->name('kokpit-api-idempotency-prune')->onOneServer()`. The two exact-list tests (`JobContractTest` expected jobs, `ScheduleOnOneServerTest` known events) were updated.
- `PurgeIdempotencyKeysTest` moves the clock: a key used at T is gone at T+25 h, a key claimed 23 h later and a never-completed future row survive, a second run deletes nothing, and the same key then creates a new entry with 201 and no replay header. Mutation check: flipping the comparison made 2 of 3 tests fail, then it was restored.
- `idempotency-worker.php` boots the HTTP kernel, refuses a database not ending in `_test`, binds `IdempotencyStore` or `UnclaimedIdempotencyStore`, waits at a start barrier and sends one `POST /api/v1/time-entries` with bearer token, `Idempotency-Key` and a fixed fictional body; prints one JSON line.
- `IdempotencyConcurrencyTest` locked run (3 rounds, 8 workers each): distinct pids, one entry per key, answers only 201 or 409, exactly one non-replayed 201, replays byte-equal to it, 409 bodies carry `idempotency_in_progress`. Observed distribution in each round: one 201 and seven 409, so the claim race is genuinely exercised. Mutation run (2 rounds): `UnclaimedIdempotencyStore` held at a file rendezvous gives 8 entries per round and no idempotency rows. Cleanup removes entries, activity rows, keys, token, client, role and user and `afterEach` asserts nothing is left.
- Full suite 2865 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` and the hook (gitleaks) clean on both commits.

## Task Commits

1. **Task 1 (tracer): scheduled purge of expired idempotency keys** - `c7b52ef` (feat)
2. **Task 2: eight processes, one key, one entry, plus the lock-less mutation run** - `dc89fff` (test)

**Plan metadata:** committed separately as `docs(07-10): complete idempotency purge and concurrency proof plan`.

## Decisions Made

See key-decisions. The tracer gate (`HUMAN_VERIFY_MODE` end-of-phase, automated `<verify>` only) was satisfied by the targeted Pest files, Pint and PHPStan passing before expansion.

## TDD Notes

Tests were written alongside the implementation inside each task (the convention of plans 07-02 to 07-09) and each task is one commit. Task 1 mutation proof: flipping the purge comparison failed the purge tests. Task 2 is a test-only task; its mutation proof is the built-in rendezvous run with the double, plus a one-off diagnostic (not committed) that printed the per-round answer distribution (7 x 409, 1 x 201) to confirm the locked run really overlaps.

## Deviations from Plan

### Plan-vs-reality notes

- The plan's worker signature named a `<barrierDir>` argument. The worker takes the shared start timestamp instead (`<barrierAt>`, with `KOKPIT_BARRIER_AT` taking precedence), exactly as the timer harness it is told to mirror. Same barrier effect, no extra directory protocol; the rendezvous directory stays optional for the mutation run. Impact: none on the contract in `must_haves`.
- The barrier lead time is 4 s instead of the timer's 3 s because each worker boots the HTTP kernel.
- The test adds two refusal tests (rendezvous with the real store or fewer than two parties, non-`_test` database), mirroring the timer suite.

**Total deviations:** none of Rules 1-4. **Impact:** none on scope.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-07-45 (duplicates under concurrent retries) is proven by the 8-process test and its mutation run; T-07-46 (table growth) by the daily purge on one server; T-07-47 (purge deleting live keys) by the future-row test.

## Requirement status

`AP-05` is NOT marked complete: plans 07-11 and 07-12 also declare it and have no SUMMARY yet. Left as is in REQUIREMENTS.md.

## Verification

- `ddev exec vendor/bin/pest`: 2865 passed (20315 assertions), Concurrency suite included
- `ddev exec vendor/bin/pint --test` clean; `phpstan analyse`: no errors
- `scripts/check-sensitive.sh` clean; hook (sensitive-content, gitleaks) clean on both commits
- Acceptance greps of both tasks hold (`extends KokpitJob`, `#[Idempotent(`, `kokpit-api-idempotency-prune`, `onOneServer`, `PurgeExpiredIdempotencyKeys::class`, `_test`, `Idempotency-Key`, `extends IdempotencyStore`)

## Self-Check: PASSED

- FOUND: app/Domain/Api/Jobs/PurgeExpiredIdempotencyKeys.php, tests/Feature/Api/PurgeIdempotencyKeysTest.php, tests/Concurrency/IdempotencyConcurrencyTest.php, tests/Concurrency/idempotency-worker.php, tests/Support/UnclaimedIdempotencyStore.php
- FOUND commits: c7b52ef, dc89fff
