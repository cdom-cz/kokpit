---
phase: 02-platform-foundation
plan: 03
subsystem: database
tags: [uuid-v7, uuidv7, timestamptz, morph-map, spatie-permission, pg_catalog, pest, postgresql-18]

requires:
  - phase: 02-platform-foundation
    provides: DDEV project, Pest harness on the guarded kokpit_test database, Pint and Larastan gates (plan 02-02)
provides:
  - users, sessions, password_reset_tokens and failed_jobs on UUID v7 keys and timestamptz, with uuidv7() column defaults
  - users.client_id nullable uuid (D-01), never mass assignable; Filament TOTP columns on users
  - App\Domain\Identity\Models\User (HasUuids, HasRoles), Role and Permission HasUuids subclasses registered in config/permission.php
  - App\Domain\Shared\Models\KokpitModel abstract base for own models
  - App\Domain\Shared\Database\MorphMap single source of aliases (user, role, permission) enforced by ModelConventionsServiceProvider
  - Catalogue-driven schema test over pg_catalog with rules R1 to R8, each with a synthetic-violation self-check
affects: [02-04, 02-05, 02-09, 02-10, 02-11, 02-13, phase-03, phase-04]

actuals:
  tokens: 13000
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Schema rules are pure functions over pg_catalog rows (tests/Support/PgSchema.php), so every later table is checked without being listed, and a self-check proves each rule can fail"
    - "Model-layer rules R7 and R8 in tests/Support/ModelRules.php with the package registry as data (02-04 adds entries)"
    - "Published package migrations are edited in place before the first migrate; every primary key gets default uuidv7()"
    - "Own models extend KokpitModel; morph aliases live in MorphMap::MAP, one line per model"

key-files:
  created:
    - app/Domain/Identity/Models/Role.php
    - app/Domain/Identity/Models/Permission.php
    - app/Domain/Shared/Models/KokpitModel.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/ModelConventionsServiceProvider.php
    - config/permission.php
    - database/migrations/2026_10_07_151401_create_permission_tables.php
    - tests/Support/PgSchema.php
    - tests/Support/ModelRules.php
    - tests/Support/Uuids.php
    - tests/Support/Probes/UnmappedProbe.php
    - tests/Feature/Schema/SchemaConventionsTest.php
    - tests/Feature/Schema/KeysAndTimestampsTest.php
    - tests/Feature/Schema/MorphMapTest.php
  modified:
    - app/Domain/Identity/Models/User.php (moved from app/Models/User.php)
    - database/migrations/0001_01_01_000000_create_users_table.php
    - database/migrations/0001_01_01_000002_create_jobs_table.php
    - database/factories/UserFactory.php
    - database/seeders/DatabaseSeeder.php
    - config/auth.php
    - bootstrap/providers.php
    - tests/Pest.php
  deleted:
    - database/migrations/0001_01_01_000001_create_cache_table.php

key-decisions:
  - "Final exempt map (4 entries): migrations.id, failed_jobs.id, sessions.id, password_reset_tokens.email, each with a reason; jobs, job_batches, cache and cache_locks no longer exist (A-OQ1)"
  - "Published permission migration file name: database/migrations/2026_10_07_151401_create_permission_tables.php"
  - "The user model keeps the Laravel 13 #[Fillable] and #[Hidden] attributes of the skeleton instead of $fillable/$hidden properties; the effect is identical (name, email, password fillable, client_id not)"
  - "Team foreign-key columns of the permission stub were also made uuid (teams stay off) so no unsignedBigInteger remains in the migration"
  - "Lazy-loading prevention stays off (A-STRICT); only silent attribute discarding is prevented outside production"
  - "R3 was authored in Task 2 (not Task 1) so the TDD RED commit carries it with R7 and R8"

patterns-established:
  - "Self-check per rule: feed a synthetic violating row or class to the rule function and assert it is reported"
  - "exampleEmail() helper in tests/Pest.php assembles example.com addresses at runtime"

requirements-completed: [FND-02, FND-03]

coverage:
  - id: D1
    description: "Catalogue schema test fails the build on a non-uuid key or morph column, an auto-increment default, a timestamp without time zone, a stale exempt entry, a uuid primary key without uuidv7(), and a morph pair with a non-uuid id"
    requirement: "FND-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/SchemaConventionsTest.php (R1 to R6 on every table plus a self-check each)"
        status: pass
    human_judgment: false
  - id: D2
    description: "users has a uuid v7 key with uuidv7() default, nullable non-fillable client_id, TOTP columns and timestamptz; sessions.user_id is uuid; unused skeleton tables are gone"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/KeysAndTimestampsTest.php and SchemaConventionsTest.php#no longer creates the unused jobs, job_batches, cache and cache_locks tables"
        status: pass
    human_judgment: false
  - id: D3
    description: "Spatie Role and Permission are registered HasUuids subclasses; a created role has a version 7 string id, find() round-trips, model_has_roles.model_type stores 'user'"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/MorphMapTest.php (role and permission cases) and SchemaConventionsTest.php#R8"
        status: pass
    human_judgment: false
  - id: D4
    description: "Relation::enforceMorphMap is active from one source; an unmapped model throws ClassMorphViolationException; a non-fillable attribute throws MassAssignmentException outside production"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/MorphMapTest.php, KeysAndTimestampsTest.php#refuses to mass assign client_id, SchemaConventionsTest.php#R7"
        status: pass
    human_judgment: false
  - id: D5
    description: "UUID v7 edge cases: 1000 unique v7 ids in one loop, raw insert gets a v7 default, explicit NULL id rejected with 23502, rows 2 ms apart sort in creation order"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/KeysAndTimestampsTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "timestamptz round-trip: NULL stays null and a UTC instant reads back identical"
    requirement: "FND-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/KeysAndTimestampsTest.php#round-trips a NULL timestamptz as null and #round-trips an instant written in UTC unchanged"
        status: pass
    human_judgment: false
  - id: D7
    description: "Where a list orders by a timestamp, ties are broken by the UUID v7 id (convention; documented by 02-13, exercised by later list features)"
    requirement: "FND-03"
    verification: []
    human_judgment: true
    rationale: "Backstop truth in the plan: no list exists yet, so the convention cannot be asserted here"

duration: 5min
completed: 2026-10-07
status: complete
commits: 3
plan_head_before: 648c93c16e7fd90cf150a9a1c5ed0b47a5cfee28
plan_head_after: f0ab3e32271ac2b71af58bd26c99e0f0865cdd0c
---

# Phase 2 Plan 03: Data conventions Summary

**UUID v7 keys with a PostgreSQL uuidv7() safety net, timestamptz everywhere, Spatie permission tables on uuid keys via edited migrations and registered HasUuids Role/Permission subclasses, an enforced single-source morph map, and a pg_catalog schema test (rules R1 to R8, each proven able to fail) that checks every future table automatically.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-07T15:11:45Z
- **Completed:** 2026-10-07T15:16:05Z
- **Tasks:** 2 of 2
- **Files modified:** 23 changed between plan start and end (14 created, 1 deleted, the rest edited or moved)

## Accomplishments

- `users` has `uuid` primary key with `uuidv7()` default, nullable indexed `client_id` (no foreign key until Phase 4), the two Filament app-authentication columns and `timestampsTz()`; `sessions.user_id`, `password_reset_tokens.created_at` and `failed_jobs.failed_at` follow the conventions. The cache migration is deleted and `jobs`/`job_batches` removed.
- The user model moved to `App\Domain\Identity\Models\User` with `HasUuids` and `HasRoles`; `client_id` is not fillable and, with silent-discard prevention on, `User::create([... 'client_id' => ...])` throws `MassAssignmentException`.
- `PgSchema` reads `pg_catalog` once and applies R1 (uuid keys), R2 (no nextval or identity), R3 (morph pairs), R4 (no timestamp without time zone), R5 (no stale exemptions) and R6 (`uuidv7()` default on single-column uuid primary keys). `ModelRules` adds R7 (morph map shape) and R8 (package models are the registered subclasses). Every rule has a self-check on a synthetic violation.
- The published permission migration uses uuid keys, uuid pivot and morph keys, `timestampsTz()` and no `unsignedBigInteger`. A role created through `Role::findOrCreate` has a version 7 string id, `Role::find()` returns it and `model_has_roles.model_type` is `user`.
- `Relation::enforceMorphMap(MorphMap::MAP)` makes an unmapped model throw `ClassMorphViolationException`; `Schema::morphUsingUuids()` guards future `morphs()` calls.

## Final exempt map

| Entry | Reason |
|---|---|
| `migrations.id` | Laravel migrator table, created by the framework with an integer key |
| `failed_jobs.id` | framework failed-job store keys rows by its uuid column |
| `sessions.id` | opaque session string; `sessions.user_id` is still checked |
| `password_reset_tokens.email` | framework table keyed by e-mail |

Published permission migration: `database/migrations/2026_10_07_151401_create_permission_tables.php`.

## Task Commits

1. **Task 1: Tracer, users, sessions and failed_jobs on UUID v7 and timestamptz, proven by the catalogue schema test** - `2f6502d` (feat)
2. **Task 2 RED: failing morph map, permission key and schema rule tests** - `04b4937` (test)
3. **Task 2 GREEN: permission tables on UUID keys, registered subclasses, enforced morph map** - `f0ab3e3` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

Tracer feedback gate: auto mode was active (`_auto_chain_active`), so the tracer's `<verify>` commands were re-run end to end after the commit (20 passed, Pint clean, Larastan `[OK]`) and execution expanded.

## TDD Gate Compliance

Task 2 (`tdd="true"`): RED commit `04b4937` precedes GREEN commit `f0ab3e3`. No REFACTOR commit was needed.

- **RED:** before any implementation the Schema suite ran with 14 failed and 19 passed. The target tests failed on the planned assertions: `config('permission.models.role')` still the Spatie class, `Relation::morphMap()` empty and not required (R7), `MassAssignmentException` and `ClassMorphViolationException` not thrown, R8 reporting the unregistered base models, and R1/R2/R3/R4 reporting violations (`permissions.id`, `roles.id`, `model_has_roles.model_id`, the pivot keys) because the stock published migration was present on disk. That stock migration was deliberately left out of the RED commit (it is implementation, edited in GREEN), so at the RED commit the R1 to R4 catalogue tests alone would pass; their value is the self-checks, the R3 self-check and the three behavior tests that fail without the implementation. The R3 self-check, R7 self-check and the R1 to R6 self-checks passed at RED because they exercise pure functions in the test support code.
- **Semantic assessment:** every failure was the planned behavior missing, not a syntax, fixture or discovery fault. The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` is not enabled for this phase and Pest's console output is not one of the classifier's supported report formats (same as 02-02).
- **GREEN:** the full suite passes (54 tests, 1135 assertions).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] R8 self-check asserted the wrong debug-type spelling**
- **Found during:** Task 2 (GREEN)
- **Issue:** the self-check expected `registered NULL`, but `get_debug_type(null)` returns `null`.
- **Fix:** corrected the expected string in the test; no production code involved.
- **Files modified:** `tests/Feature/Schema/SchemaConventionsTest.php`
- **Committed in:** `f0ab3e3`

**2. [Rule 2 - Missing critical] Team foreign-key columns also made uuid**
- **Found during:** Task 2 (migration edit)
- **Issue:** the acceptance criterion requires zero `unsignedBigInteger` in the permission migration, but the stub also builds the optional team foreign key (teams are off, `permission.testing` is false) as an unsigned big integer.
- **Fix:** those columns became `uuid(...)` too, so enabling teams later cannot reintroduce a bigint key.
- **Files modified:** `database/migrations/2026_10_07_151401_create_permission_tables.php`
- **Committed in:** `f0ab3e3`

### Plan-reading notes (not deviations)

- The plan names `$fillable = [...]`; the Laravel 13 skeleton uses `#[Fillable([...])]`/`#[Hidden([...])]` attributes, which are equivalent, so they were kept.
- Pint added `declare(strict_types=1)` to the published `config/permission.php` and migration (the repository-wide rule from 02-02).
- A shared `exampleEmail()` helper in `tests/Pest.php` and `tests/Support/Uuids.php` (V7 pattern) were added so test files do not duplicate fixtures.

---

**Total deviations:** 2 auto-fixed (1 bug in an own test, 1 missing-critical hardening)
**Impact on plan:** None on scope.

## Issues Encountered

- `git add` with a non-existent pathspec (`app/Models`, already removed by the move) aborted the whole add once; re-run without it. No commit was affected.
- The development database (not `kokpit_test`) was not migrated by this plan; the test database is migrated by `RefreshDatabase` on every run, which exercises all three migrations.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-09 (unregistered package models) is mitigated by the registered subclasses, rule R8 and the role-assignment test; T-02-10 by `enforceMorphMap`, R7 and the unmapped probe; T-02-11 by the non-fillable `client_id` and the `MassAssignmentException` tests; T-02-12 by `timestampsTz()` everywhere, UTC session and R4.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- 02-04 adds the remaining package models (media, tag, activity, webhook call, personal access token) as entries in `packageModelRegistry()` in `SchemaConventionsTest.php` and as lines in `MorphMap::MAP`, plus rule R9; its migrations must use `uuid('id')->primary()->default(DB::raw('uuidv7()'))`, `*Morphs` uuid variants and `timestampsTz()`, which R1 to R6 will enforce automatically.
- `KokpitModel` is ready as the base class for own models; the partner-isolation declaration is added by 02-10.
- `ddev composer test` (54 passed), `lint` and `stan` are green; DDEV containers are left running.

## Self-Check: PASSED

- Created files exist: Role, Permission, KokpitModel, MorphMap, ModelConventionsServiceProvider, config/permission.php, the published migration, PgSchema, ModelRules, Uuids, UnmappedProbe and the three Schema tests; `app/Models/User.php` and the cache migration are gone.
- Commits `2f6502d`, `04b4937`, `f0ab3e3` are ancestors of HEAD; `git rev-list --count 648c93c..HEAD` is 3.
- All acceptance criteria of Tasks 1 and 2 re-run and passing; `ddev composer test`, `lint`, `stan` and `scripts/check-sensitive.sh --all` clean.
