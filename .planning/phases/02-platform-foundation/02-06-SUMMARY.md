---
phase: 02-platform-foundation
plan: 06
subsystem: database
tags: [sequences, postgresql-18, row-lock, concurrency, symfony-process, pest, gap-free]

requires:
  - phase: 02-platform-foundation
    provides: Pest harness on kokpit_test (02-02), schema rules R1-R6 over pg_catalog (02-03)
provides:
  - number_sequences table with unique scope_key, next_value CHECK (>= 1) and a scope-key format CHECK
  - App\Domain\Shared\Sequences\SequenceAllocator, a row-locked allocator that runs only inside the caller's transaction
  - Pest Concurrency suite (tests/Concurrency) with a worker script, real parallel-process proof and a mutation run
  - Tests\Support\UnlockedSequenceAllocator, a test-only allocator without the row lock
affects: [phase-05-task-keys, phase-10-invoice-numbers, importers]

actuals:
  tokens: 6950
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "One counter row per scope key, SELECT ... FOR UPDATE inside the caller's transaction; first use closed with INSERT ON CONFLICT DO NOTHING"
    - "Year lives in the key (invoice:2026), computed in Europe/Prague by scopeKeyForYear; the allocator never resets"
    - "Cross-process tests: no RefreshDatabase, random-hex probe table and key per test, cleanup in afterEach, workers behind a shared microtime barrier"
    - "A concurrency proof ships with a mutation run (allocator without the lock) that must be detected, and a non-vacuity guard on worker summaries"

key-files:
  created:
    - database/migrations/2026_10_07_000100_create_number_sequences_table.php
    - app/Domain/Shared/Sequences/SequenceAllocator.php
    - tests/Feature/Sequences/SequenceAllocatorTest.php
    - tests/Concurrency/worker.php
    - tests/Concurrency/SequenceAllocatorConcurrencyTest.php
    - tests/Support/UnlockedSequenceAllocator.php
  modified:
    - phpunit.xml
    - tests/Pest.php

key-decisions:
  - "Row contract option next-value: next_value is the NEXT number to hand out (CHECK next_value >= 1, first use inserts 1), key format kind:qualifier"
  - "The allocator validates the key in PHP (same pattern as the CHECK, trailing newline rejected via the D modifier, 191 character limit) before any query"
  - "The worker takes the allocator class name as its last argument, so the mutation run passes UnlockedSequenceAllocator::class and the worker needs no switch"
  - "Barrier is 3 seconds ahead, not the 1 second of the lab run: eight cold application boots in DDEV take longer than that"

patterns-established:
  - "Concurrency tests assert exact counts and consecutive numbers, never just 'no exception'"
  - "Test-only mutation classes live in tests/Support and are grepped out of app/"

requirements-completed: [FND-05]

coverage:
  - id: D1
    description: "number_sequences enforces the confirmed contract in PostgreSQL: unique scope_key, CHECK next_value >= 1, CHECK on the kind:qualifier key format, uuid v7 key and timestamptz columns (R1-R6 pass without exemptions)"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Sequences/SequenceAllocatorTest.php#lets the database reject a malformed key too, #refuses a counter below 1 in the database, #refuses a duplicate scope key in the database"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/SchemaConventionsTest.php (R1-R6)"
        status: pass
    human_judgment: false
  - id: D2
    description: "SequenceAllocator::next() hands out 1, 2, 3 per key, counts keys independently, honours a counter set by an importer, throws LogicException outside a transaction, and gives a number back after a rollback (outer and nested savepoint)"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Sequences/SequenceAllocatorTest.php (first use, independent keys, importer value, outside a transaction, rollback, nested savepoint)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Malformed scope keys and kinds throw InvalidArgumentException before any query; scopeKeyForYear returns the year in Europe/Prague (2026-12-31 23:30 UTC gives invoice:2027, 22:59 UTC gives invoice:2026) and a new year key starts at 1"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Sequences/SequenceAllocatorTest.php#rejects a malformed key before touching the database, #computes the year of a key in Europe/Prague, #starts the next year at 1 while the old year keeps counting"
        status: pass
    human_judgment: false
  - id: D4
    description: "Eight real worker processes times 25 allocations behind a shared barrier receive exactly 1..200, counter at 201, from at least two pids, zero worker failures"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Concurrency/SequenceAllocatorConcurrencyTest.php#hands out exactly 1..200 to 8 parallel workers with no duplicate and no gap"
        status: pass
    human_judgment: false
  - id: D5
    description: "With every fourth transaction rolled back after allocating (48 rollbacks), the 152 committed numbers are exactly 1..152 and the counter is 153"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Concurrency/SequenceAllocatorConcurrencyTest.php#keeps committed numbers consecutive when every fourth transaction is rolled back"
        status: pass
    human_judgment: false
  - id: D6
    description: "The same harness run against UnlockedSequenceAllocator is detected: about 61 of 200 rows survive and all 8 workers report duplicate-key failures; a non-vacuity guard requires every worker summary and all 200 attempts accounted for"
    requirement: "FND-05"
    verification:
      - kind: integration
        ref: "tests/Concurrency/SequenceAllocatorConcurrencyTest.php#detects the defect when the allocator has no row lock (mutation run)"
        status: pass
    human_judgment: false
  - id: D7
    description: "The worker refuses to run unless the connection is pgsql and the database name ends in _test, before touching any table"
    verification:
      - kind: integration
        ref: "tests/Concurrency/SequenceAllocatorConcurrencyTest.php#refuses to run against a database that is not a test database"
        status: pass
    human_judgment: false

duration: 6min
completed: 2026-10-07
status: complete
commits: 3
plan_head_before: 24492268f396f0d8fa8d9b6a54da2a10b06a8f74
plan_head_after: a517602981dd9d0d61ad7834f28a76692b538a5c
---

# Phase 2 Plan 06: Sequence allocator Summary

**Gap-free, duplicate-free number allocation through one `SequenceAllocator` that locks a `number_sequences` counter row with `SELECT ... FOR UPDATE` in the caller's transaction, proven by 8 real parallel PHP processes on PostgreSQL (exactly 1..200, rollbacks included) and by a mutation run that shows the same harness failing without the lock.**

## Performance

- **Duration:** 6 min
- **Started:** 2026-10-07T18:59:40Z
- **Completed:** 2026-10-07T19:05:12Z
- **Tasks:** 3 of 3 (Task 1 was a decision checkpoint resolved by the owner before this dispatch; Tasks 2 and 3 executed here)
- **Files modified:** 8 (6 created, 2 modified)

## Decisions

- **Task 1 (checkpoint:decision, resolved by the owner): `next-value`.** The stored column means the NEXT number to hand out (`next_value`, `CHECK (next_value >= 1)`, first use inserts 1 with `ON CONFLICT DO NOTHING`); key format `kind:qualifier`. The migration CHECK, the allocator SQL and the tests implement exactly these semantics. The last issued number is `next_value - 1`, so an importer writes the obvious "next" number directly (tested: a row set to 120 hands out 120).

## Accomplishments

- `number_sequences` migration: `id uuid` default `uuidv7()`, `scope_key varchar(191)` unique, `next_value bigint` default 1, `timestampsTz()`, plus `CHECK (next_value >= 1)` and `CHECK (scope_key ~ '^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$')` through static `DB::statement` DDL. The schema rules R1-R6 pick the new table up automatically and pass without exemptions.
- `SequenceAllocator::next(string $scopeKey): int` refuses to run at `DB::transactionLevel() === 0` (`LogicException`), validates the key in PHP with the same pattern as the CHECK (`InvalidArgumentException`, no query issued), then `INSERT ... ON CONFLICT (scope_key) DO NOTHING`, `SELECT ... FOR UPDATE`, `UPDATE next_value = next_value + 1`. Bound parameters only; no reset and no year logic inside `next()`.
- `scopeKeyForYear(string $kind, DateTimeInterface $at)` returns `"{$kind}:{year}"` with the year taken in `Europe/Prague`.
- Concurrency suite: `worker.php` boots the console kernel, refuses a database whose name does not end in `_test`, busy-waits on `KOKPIT_BARRIER_AT`, then allocates inside `DB::transaction`, runs `select pg_sleep(0.002)` to widen the race window and inserts `(n, pid)` into a probe table with a primary key on `n`. The Pest test spawns the workers with `Symfony\Component\Process\Process`, passes the parent's runtime DB settings, and cleans its probe table and counter rows in `afterEach`.

## Mutation run and run times

Mutation (`UnlockedSequenceAllocator`, the real SQL without `FOR UPDATE`): in two instrumented runs 62 and 61 of 200 rows survived and all 8 workers exited 1 with duplicate-key errors on the probe primary key (research lab: 55 of 200). The test fails if the harness does not notice (rows below 200 or a failing worker), and a non-vacuity guard fails it if any worker produced no summary or the attempts do not add up to 200.

Three consecutive runs of `ddev exec vendor/bin/pest tests/Concurrency` (4 tests, 75 assertions, all passed each time):

| Run | Duration | 8x25 locked | failEvery=4 | mutation | DB guard |
|---|---|---|---|---|---|
| 1 | 25.65 s | 8.74 s | 9.34 s | 6.84 s | 0.61 s |
| 2 | 19.30 s | 9.74 s | 4.75 s | 4.25 s | 0.37 s |
| 3 | 15.80 s | 5.18 s | 5.69 s | 4.51 s | 0.32 s |

An earlier uninstrumented run took 16.77 s. Each parallel test includes the 3 s barrier; the spread (4.3 to 9.7 s) is the cold application boot of eight processes in DDEV. Full suite (`ddev exec vendor/bin/pest`): 142 passed (1383 assertions) in 18.5 s, so the Concurrency suite coexists with the RefreshDatabase suites.

## Task Commits

1. **Task 2: Tracer, a caller transaction allocates numbers from number_sequences, and a rollback gives the same number back** - `891a7bf` (feat)
2. **Task 3 RED: failing parallel-process proof for the sequence allocator** - `f0375e5` (test)
3. **Task 3 GREEN: worker process and unlocked mutation allocator** - `a517602` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

Tracer feedback gate: `human_verify_mode` is `end-of-phase` and the tracer `<verify>` is automated only, so it was re-run on the committed tree before expansion (26 allocator tests and 72 including the schema suite, Pint clean, Larastan `[OK]`) and passed, so execution expanded.

## TDD Gate Compliance

Task 3 (`tdd="true"`): RED commit `f0375e5` precedes GREEN commit `a517602`. No REFACTOR commit was needed.

- **RED:** 4 failed, 0 passed (`pest tests/Concurrency`). Each target test failed on its planned assertion for the intended reason: the workers could not start (`Could not open input file: .../tests/Concurrency/worker.php`), so "worker exit is 0" failed in the two proof tests, "worker produced a summary" failed in the mutation test, and the DB-guard test failed because the stderr did not contain `_test`.
- **Semantic assessment:** the failures are the missing behavior (no worker, no mutation class), not syntax, discovery or fixture faults. The mutation test is deliberately written so it cannot pass vacuously at RED: it first requires a summary from every worker and 200 accounted attempts before it checks detection.
- **GREEN:** `pest tests/Concurrency` 4 passed, three times in a row; full suite 142 passed; Pint and Larastan clean.
- The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` is not enabled and Pest's console output is not one of its supported report formats (as in 02-02 to 02-05).

## Files Created/Modified

- `database/migrations/2026_10_07_000100_create_number_sequences_table.php` - counter table and its two CHECK constraints
- `app/Domain/Shared/Sequences/SequenceAllocator.php` - row-locked allocator and `scopeKeyForYear`
- `tests/Feature/Sequences/SequenceAllocatorTest.php` - 26 cases (RefreshDatabase)
- `tests/Concurrency/worker.php` - child process behind the barrier
- `tests/Concurrency/SequenceAllocatorConcurrencyTest.php` - proof, rollback proof, mutation run, DB guard
- `tests/Support/UnlockedSequenceAllocator.php` - test-only allocator without `FOR UPDATE`
- `phpunit.xml` - `Concurrency` testsuite (`suffix="Test.php"` so `worker.php` is never collected)
- `tests/Pest.php` - `Concurrency` directory extends `TestCase` without RefreshDatabase

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Worker refuses a non-test database**
- **Found during:** Task 3
- **Issue:** the plan's worker boots the application and commits real rows. `Tests\TestCase` guards the parent, but a child started by hand (or with a wrong environment) would write to whatever database the environment resolves, and the test harness guard does not cover it.
- **Fix:** the worker exits 2 unless the default connection is `pgsql` and the database name ends in `_test`, and it validates the probe table name and the allocator class before use. A fourth test covers it.
- **Files modified:** `tests/Concurrency/worker.php`, `tests/Concurrency/SequenceAllocatorConcurrencyTest.php`
- **Committed in:** `f0375e5` (test), `a517602` (worker)

**2. [Rule 2 - Missing critical] Non-vacuity guard in the mutation test**
- **Found during:** Task 3 (RED run)
- **Issue:** "rows below 200 or a failing worker" would also be true if no worker ran at all, which is exactly the vacuous pass T-02-22 names. At RED that assertion alone would have passed.
- **Fix:** the mutation test first requires a summary from every worker and `committed + errors` summing to 200.
- **Committed in:** `f0375e5`

### Plan-reading notes (not deviations)

- The worker's first argument is `<startAt>` and the barrier is also passed as `KOKPIT_BARRIER_AT`; the environment value wins and the argument is the fallback (the plan names both).
- The worker's `<allocator>` argument is a class name rather than a keyword, so no switch is needed in the worker.
- Barrier lead is 3 s instead of the roughly 1 s in the plan: 8 cold boots in DDEV take longer than 1 s.

---

**Total deviations:** 2 auto-fixed (2 missing critical)
**Impact on plan:** None on scope; both additions strengthen T-02-22 and the test-database safety rule.

## Issues Encountered

- A temporary diagnostic in the mutation test (to read the surviving row count) wrote a file under `storage/`; it was reverted with `git checkout` and the file removed before the GREEN commit.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-19 (duplicate or gapped numbers): row lock, 1..200 and rollback proofs. T-02-20 (allocation outside a transaction): `LogicException`, tested. T-02-21 (malformed keys): bound parameters, PHP pattern and CHECK, tested. T-02-22 (vacuous concurrency test): exact counts, consecutive numbers, distinct pids, zero failures, mutation run and the non-vacuity guard.

The FND-05 flagged assumption stays open by design: a typo in a caller's scope key silently starts a new series at 1. Phase 5 and Phase 10 must build keys only through `scopeKeyForYear` or a project id; an allowed-kinds registry is not part of FND-05.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 5 and Phase 10 call `(new SequenceAllocator)->next($key)` inside their own `DB::transaction`; keys come from `scopeKeyForYear('invoice', $issuedAt)` or `'task:'.$projectId`.
- Importers set `next_value` directly (the next number to hand out); `CHECK (next_value >= 1)` guards it.
- Any later test that spawns processes should follow the Concurrency suite pattern (no RefreshDatabase, own probe table, cleanup in afterEach) and must not overlap another plan's test run on `kokpit_test`.
- `ddev composer test` (142 passed), `lint`, `stan` and `scripts/check-sensitive.sh` are green; DDEV containers left running.

## Self-Check: PASSED

- Created files exist: migration, `SequenceAllocator.php`, `SequenceAllocatorTest.php`, `worker.php`, `SequenceAllocatorConcurrencyTest.php`, `UnlockedSequenceAllocator.php`; `phpunit.xml` has the Concurrency suite and `tests/Pest.php` registers it.
- Commits `891a7bf`, `f0375e5`, `a517602` are ancestors of HEAD; `git rev-list --count 2449226..HEAD` is 3.
- All acceptance criteria of Tasks 2 and 3 re-run and passing; `grep -rn UnlockedSequenceAllocator app` prints nothing.
