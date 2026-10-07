---
phase: 02-platform-foundation
plan: 07
subsystem: database
tags: [postgresql-18, trigger, immutability, plpgsql, sqlstate, check-constraint, partial-index, pest]

requires:
  - phase: 02-platform-foundation
    provides: Pest harness on kokpit_test (02-02), schema rules over pg_catalog (02-03), serialized after the sequence allocator (02-06)
provides:
  - kokpit_guard_frozen_row() generic row trigger function (SQLSTATE KP001) installed by a migration
  - kokpit_refuse_truncate() statement-level truncate guard function
  - App\Domain\Shared\Database\Immutability, a DDL builder that only accepts validated identifiers
  - Tests\Support\RawSql with expectSqlState and expectAllowed (savepoint per statement)
  - Pilot verdict matrix proving trigger, CHECK, partial unique index and foreign key by raw SQL
affects: [phase-08-billing, phase-10-invoicing, time-entries]

actuals:
  tokens: 5200
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One generic trigger function per concern, parameterised by TG_ARGV (lifecycle column, open state, operational columns); each table gets a trigger from the Immutability builder"
    - "Operational columns are compared by name on to_jsonb(NEW) - allowed, so a column added later is frozen by default"
    - "Expected database rejections are asserted by SQLSTATE inside a nested DB::transaction() savepoint (RawSql), never by exception message"
    - "Pilot tables live only inside the test transaction; PostgreSQL DDL is transactional, so RefreshDatabase removes them"

key-files:
  created:
    - database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php
    - app/Domain/Shared/Database/Immutability.php
    - tests/Support/RawSql.php
    - tests/Feature/Database/ImmutabilityPilotTest.php
    - tests/Unit/Database/ImmutabilityTest.php
  modified: []

key-decisions:
  - "Foreign keys that must answer 23503 use the default NO ACTION; ON DELETE RESTRICT answers 23001 (restrict_violation) in PostgreSQL, so the pilot uses NO ACTION and a second test pins the 23001 behaviour"
  - "Immutability caps table names at 48 characters so <table>_truncate_guard stays inside the 63 byte identifier limit instead of being truncated silently"
  - "updated_at is always part of the operational column list and is de-duplicated when the caller lists it too"

patterns-established:
  - "Later migrations attach the guard with DB::unprepared(Immutability::guardTriggerSql(table, state column, open state, operational columns)) and add truncateGuardSql where TRUNCATE matters"
  - "Companion constraint shapes for issued records: CHECK (status = 'draft' OR (number IS NOT NULL AND issued_at IS NOT NULL)), CHECK (amount_minor >= 0), CHECK (currency ~ '^[A-Z]{3}$'), partial unique index on number WHERE number IS NOT NULL, child FK without ON DELETE action"

requirements-completed: [FND-12]

coverage:
  - id: D1
    description: "kokpit_guard_frozen_row() ships as a migration; any raw-SQL change to a non-operational column of an issued row, and any delete of it, is refused with KP001, while the operational note stays editable and a draft can be edited, issued and deleted"
    requirement: "FND-12"
    verification:
      - kind: integration
        ref: "tests/Feature/Database/ImmutabilityPilotTest.php#refuses a raw-SQL change to an issued row and keeps its operational note editable, #lets a draft be edited, issued and deleted, #refuses to change the number or the issue time of an issued row, #refuses to delete an issued row"
        status: pass
    human_judgment: false
  - id: D2
    description: "Companion constraints proven by raw SQL: issued without number 23514, negative amount 23514, lower-case currency 23514, duplicate issued number 23505, two NULL-number drafts allowed, delete of a referenced parent 23503"
    requirement: "FND-12"
    verification:
      - kind: integration
        ref: "tests/Feature/Database/ImmutabilityPilotTest.php (CHECK, partial unique index and foreign key cases)"
        status: pass
    human_judgment: false
  - id: D3
    description: "TRUNCATE of a table with the statement-level truncate guard is refused with KP001, and allowed again once the guard is dropped"
    requirement: "FND-12"
    verification:
      - kind: integration
        ref: "tests/Feature/Database/ImmutabilityPilotTest.php#refuses TRUNCATE of a table that has the truncate guard installed, #lets TRUNCATE through when the truncate guard is dropped again"
        status: pass
    human_judgment: false
  - id: D4
    description: "Immutability rejects hostile table names, state columns, operational columns and open states before building any SQL, and produces the documented trigger names and operational column list"
    requirement: "FND-12"
    verification:
      - kind: unit
        ref: "tests/Unit/Database/ImmutabilityTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "RawSql::expectSqlState fails on an accepted statement and on a different SQLSTATE, and a rejected statement leaves the surrounding transaction usable"
    verification:
      - kind: integration
        ref: "tests/Feature/Database/ImmutabilityPilotTest.php#fails the helper when a statement that must be rejected is accepted, #fails the helper when the SQLSTATE differs from the expected one, #keeps the surrounding transaction usable after a rejected statement"
        status: pass
    human_judgment: false

duration: 3min
completed: 2026-10-07
status: complete
commits: 2
plan_head_before: a517602981dd9d0d61ad7834f28a76692b538a5c
plan_head_after: 5e13411683ef1c15197208f7a0a257e9d3867d2f
---

# Phase 2 Plan 07: Immutability pilot Summary

**A generic PostgreSQL trigger function `kokpit_guard_frozen_row()` (custom SQLSTATE KP001), a validated-identifier DDL builder `Immutability`, and a savepoint-based `RawSql` test helper, proven on a test-only pilot table: an issued row's amount, number and issue time cannot change and the row cannot be deleted by any writer, while a note can; CHECK, partial unique index, foreign key and TRUNCATE guards are each proven by raw SQL with their exact SQLSTATE.**

## Performance

- **Duration:** 3 min
- **Started:** 2026-10-07T19:08:41Z
- **Completed:** 2026-10-07T19:11:37Z (host clock)
- **Tasks:** 2 of 2
- **Files modified:** 5 (all created)

## Accomplishments

- Migration `2026_10_07_000200_create_kokpit_guard_frozen_row_function` installs `kokpit_guard_frozen_row()` (body of RESEARCH Pattern 6: `to_jsonb(OLD/NEW) - allowed` comparison, `RAISE EXCEPTION ... USING ERRCODE = 'KP001'`) and `kokpit_refuse_truncate()`; `down()` drops both. Static DDL only, through `DB::unprepared`.
- `Immutability` (final class, static builders): `guardTriggerSql`, `dropGuardTriggerSql`, `truncateGuardSql`, `dropTruncateGuardSql`. Identifiers must match `^[a-z_][a-z0-9_]*$` (with the `D` modifier so a trailing newline fails), the open state `^[a-z_]+$`, table names are capped at 48 characters. Every builder throws `InvalidArgumentException` before any SQL is built. The class docblock states the limits: row triggers skip TRUNCATE, the table owner can disable triggers, `session_replication_role = replica` skips them, so the production role must not be a superuser (A4, Phase 3), and a renamed operational column becomes frozen.
- `RawSql::expectSqlState` / `expectAllowed` run the statement in a nested `DB::transaction()` (savepoint), read the SQLSTATE from the previous `PDOException`'s `errorInfo[0]`, and fail on an accepted statement or a different state.
- `ImmutabilityPilotTest` (17 cases) builds `pilot_documents` and `pilot_document_lines` inside each test; the schema catalogue test never sees them.
- `ImmutabilityTest` (unit, 32 cases with datasets) covers hostile identifiers in every builder, hostile open states, the 48-character cap, generated trigger names and the `updated_at` rule.

## Tracer gate and mutation check

- Tracer (Task 1): `ddev exec vendor/bin/pest tests/Feature/Database` 2 passed; `pg_proc` count for `kokpit_guard_frozen_row` is 1 after the suite; full suite 144 passed; Pint and Larastan clean. Auto mode is active (`_auto_chain_active`), so the verify was re-run on the committed tree before expansion and passed.
- Mutation check (not committed): a copy of the pilot test with the guard trigger line removed failed exactly the three KP001 cases (issued-row update, number/issue-time change, delete of an issued row) while the CHECK, index and FK cases still passed, so the KP001 assertions are not vacuous. The temporary file was deleted.

## Task Commits

1. **Task 1: Tracer, guard function migration plus helper refuse a raw-SQL change to an issued pilot row with KP001** - `d16aa3a` (feat)
2. **Task 2: Full verdict matrix, safe identifiers** - `5e13411` (test)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

## TDD Gate Compliance

Task 2 is `tdd="true"` and the plan says "adjust the helper only if a behavior exposes a gap". The tracer commit already shipped the guard, the builder and the helper, so the behaviors of Task 2 were mostly satisfied before their tests existed.

- **RED:** `pest tests/Feature/Database tests/Unit/Database` gave 1 failed, 48 passed. The one failure was the target case "refuses to delete a draft that a line still references": expected SQLSTATE `23503`, the database answered `23001` (restrict_violation) because the plan specifies `ON DELETE RESTRICT`. All other new cases passed at first run (unexpected green for those, expected: the implementation existed from the tracer and the tests pin it).
- **Semantic assessment:** the failing assertion is the planned one (the FK verdict) and it failed for a real reason, not syntax or fixture faults. The cause is a contradiction inside the plan (RESTRICT cannot give 23503), not a helper gap.
- **GREEN:** no production code changed. The pilot FK now uses PostgreSQL's default NO ACTION (immediate check, 23503) and an extra test pins that RESTRICT answers 23001. 50 tests passed in the Database folders, full suite 192 passed.
- **Gate shape:** there is a `feat(02-07)` commit (the tracer) and one `test(02-07)` commit, in that order; there is no separate RED commit that precedes the implementation, because a committed RED state would have contained a test with a wrong expectation. No REFACTOR commit. The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` is not enabled and Pest console output is not a supported report format (as in 02-02 to 02-06).

## Files Created/Modified

- `database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php` - the two guard functions
- `app/Domain/Shared/Database/Immutability.php` - trigger DDL builders with identifier validation
- `tests/Support/RawSql.php` - SQLSTATE assertions inside a savepoint
- `tests/Feature/Database/ImmutabilityPilotTest.php` - verdict matrix on the pilot tables
- `tests/Unit/Database/ImmutabilityTest.php` - identifier validation and generated SQL

## Decisions Made

- Foreign keys meant to answer 23503 use NO ACTION; RESTRICT answers 23001. Later phases (invoice lines, billed time entries) should leave `onDelete` unset on such keys, or expect 23001.
- Table names are capped at 48 characters in `Immutability` so trigger names are never truncated by PostgreSQL.
- `kokpit_refuse_truncate()` ends with an unreachable `RETURN NULL` after `RAISE EXCEPTION` so plpgsql accepts the function body as a trigger function.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan expects 23503 from an ON DELETE RESTRICT foreign key**
- **Found during:** Task 2 (RED run)
- **Issue:** the plan pairs `REFERENCES pilot_documents ON DELETE RESTRICT` with SQLSTATE `23503`; PostgreSQL raises `23001` for RESTRICT and `23503` only for NO ACTION. The truth in the plan ("fails the foreign key (23503)") and its schema cannot both hold.
- **Fix:** the pilot child table uses the default NO ACTION so the verdict is 23503 as the must-have states; a second case documents that a RESTRICT key answers 23001.
- **Files modified:** `tests/Feature/Database/ImmutabilityPilotTest.php`
- **Verification:** Database and Unit folders 50 passed; full suite 192 passed
- **Committed in:** `5e13411`

**2. [Rule 2 - Missing critical] Table name length cap in the DDL helper**
- **Found during:** Task 1
- **Issue:** a table name longer than 48 characters would make `<table>_truncate_guard` exceed 63 bytes; PostgreSQL truncates identifiers silently, so two guards could collide or a drop statement could miss its trigger.
- **Fix:** `Immutability` rejects table names over 48 characters; unit test covers 48 (accepted) and 49 (rejected).
- **Files modified:** `app/Domain/Shared/Database/Immutability.php`, `tests/Unit/Database/ImmutabilityTest.php`
- **Committed in:** `d16aa3a`, `5e13411`

**3. [Rule 2 - Missing critical] Tests of the helper itself**
- **Found during:** Task 2
- **Issue:** every verdict in the plan rests on `RawSql`; a helper that never fails would make the matrix vacuous.
- **Fix:** three cases: an accepted statement must fail `expectSqlState`, a wrong SQLSTATE must fail it naming both states, and the transaction stays usable after a rejection. Plus the uncommitted mutation check above.
- **Committed in:** `5e13411`

---

**Total deviations:** 3 auto-fixed (1 bug in the plan, 2 missing critical)
**Impact on plan:** no scope change; the FK deviation resolves an internal contradiction in the plan in favour of the stated SQLSTATE.

## Issues Encountered

- Pint flagged a fully qualified class name in the new test (`fully_qualified_strict_types`); fixed with an import before the Task 2 commit.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-23 (raw-SQL update of an issued record): row trigger, verdict matrix and mutation check. T-02-24 (injection through identifiers): strict patterns and hostile-input unit tests. T-02-25 (superuser or replica role bypass): transferred to Phase 3 as planned, documented in the `Immutability` docblock. T-02-26 (TRUNCATE): `truncateGuardSql`, proven on a pilot table.

The "FND-12 edge" assumption stays open as planned: the operational-column comparison is by name, so later migrations that rename columns of guarded tables must re-create the trigger. The DDEV `db` role is a superuser, so the bypass paths of A4 exist in development by design.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phases that issue invoices or bill time entries add `DB::unprepared(Immutability::guardTriggerSql(...))` (and `truncateGuardSql` where wanted) in their own migration, after this one.
- Use `Tests\Support\RawSql` for any test that expects a database rejection inside `RefreshDatabase`.
- `ddev exec vendor/bin/pest` (192 passed), Pint, Larastan level 8 and `scripts/check-sensitive.sh` are green; DDEV containers left running.

## Self-Check: PASSED

- Created files exist: migration, `Immutability.php`, `RawSql.php`, `ImmutabilityPilotTest.php`, `ImmutabilityTest.php`.
- Commits `d16aa3a` and `5e13411` are ancestors of HEAD; `git rev-list --count a517602..HEAD` is 2.
- Acceptance criteria of both tasks re-run and passing (KP001 and function name in the migration, builder and helper signatures, SQLSTATE and TRUNCATE greps, `pg_proc` count 1, unit test exit 0).
