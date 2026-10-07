---
phase: 02-platform-foundation
plan: 02
subsystem: infra
tags: [ddev, postgresql-18, redis, rustfs, pest, pint, larastan, env-sync]

requires:
  - phase: 02-platform-foundation
    provides: Laravel 13 skeleton with the Filament 5 admin panel, Pest 5, Larastan, composer.lock (plan 02-01)
provides:
  - Versioned DDEV project (PHP 8.5, nginx-fpm, PostgreSQL 18, Redis, Mailpit, RustFS with bucket kokpit-dev, queue-worker and scheduler daemons)
  - Idempotent post-start hook creating the kokpit_test database
  - Pest harness on kokpit_test with a database-name guard that fires before RefreshDatabase
  - DDEV-ready .env.example kept in sync with config/*.php in both directions by test
  - config/kokpit.php switches (require_admin_two_factor, canary_harness) and their phpunit.xml values
  - Pint (laravel preset plus declare_strict_types) and Larastan level 8 gates, composer scripts test, lint and stan
affects: [02-03, 02-04, 02-05, 02-06, 02-09, 02-10, 02-11, 02-12, 02-13]

actuals:
  tokens: 10000
  tasks: 3
  commits: 4

tech-stack:
  added: [symfony/yaml 8.1 (dev, MIT), ddev/ddev-redis add-on v2.2.0, rustfs/rustfs 1.0.1 (dev container), rustfs/rc v0.1.36 (dev container)]
  patterns:
    - "Test database guard in TestCase::createApplication(): connection must be pgsql and the name must end in _test, checked before any trait migrates"
    - "Env sync by test: documented keys must be read by a config file or sit in an allowlist; no-default env() keys must be documented"
    - "DDEV daemons wrapped in a wait loop for vendor/autoload.php and a bootable app"

key-files:
  created:
    - .ddev/config.yaml
    - .ddev/docker-compose.rustfs.yaml
    - .ddev/docker-compose.redis.yaml
    - .ddev/redis/redis.conf
    - .ddev/commands/redis/redis-cli
    - .ddev/commands/redis/redis-flush
    - .ddev/commands/host/redis-backend
    - .ddev/addon-metadata/redis/manifest.yaml
    - config/kokpit.php
    - tests/Pest.php
    - tests/Feature/Boot/PanelBootTest.php
    - tests/Feature/Repo/DdevConfigTest.php
    - tests/Feature/Repo/EnvExampleTest.php
    - phpstan.neon
    - pint.json
  modified:
    - .env.example
    - config/database.php
    - config/cache.php
    - config/session.php
    - phpunit.xml
    - tests/TestCase.php
    - composer.json
    - composer.lock

key-decisions:
  - "Pint applies declare_strict_types to the whole tree (no per-directory rules in Pint); strict types everywhere is the safer default for a money and access-control codebase"
  - "Optional no-default env() keys of the framework configs are documented as commented placeholders in .env.example instead of pruning the skeleton configs"
  - "The database cache store is removed from config/cache.php; cache and session default to Redis"
  - "KOKPIT_ADMIN_PASSWORD is the single allowlisted .env.example key that no config file reads (kokpit:install only)"

patterns-established:
  - "Static repository contract tests (tests/Feature/Repo) parse versioned files and never need Docker"
  - "Real environment variables win over phpunit.xml env entries, so CI can set DB_* itself; DDEV exports no DB_* into the web container"

requirements-completed: [FND-20, FND-01, FND-14]

coverage:
  - id: D1
    description: "ddev start boots the app on PHP 8.5 and PostgreSQL 18; the boot test proves login page, root redirect, pgsql driver, kokpit_test database and UTC session"
    requirement: "FND-01"
    verification:
      - kind: integration
        ref: "ddev exec vendor/bin/pest tests/Feature/Boot (5 passed)"
        status: pass
    human_judgment: false
  - id: D2
    description: "tests/TestCase.php refuses a database that is not pgsql or does not end in _test before any migration"
    requirement: "FND-20"
    verification:
      - kind: integration
        ref: "ddev exec env DB_DATABASE=kokpit_guard_probe vendor/bin/pest tests/Feature/Boot prints 'Refusing to run tests against database'; no kokpit_guard_probe database exists afterwards"
        status: pass
    human_judgment: false
  - id: D3
    description: "Versioned .ddev files: PHP 8.5, PostgreSQL 18, both daemons with wait loop, RustFS pinned to 1.0.1 with keep-alive init container, Redis add-on, no instance values"
    requirement: "FND-20"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/DdevConfigTest.php (8 tests)"
        status: pass
      - kind: integration
        ref: "ddev exec curl http://rustfs:9000/health returns 200; ddev exec supervisorctl status shows queue-worker and scheduler RUNNING; Redis connect from phpredis prints ok"
        status: pass
    human_judgment: false
  - id: D4
    description: ".env.example is DDEV-ready and stays in sync with config/*.php in both directions, with mutation cases proving the checks can fail"
    requirement: "FND-14"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/EnvExampleTest.php (8 tests)"
        status: pass
    human_judgment: false
  - id: D5
    description: "config/kokpit.php exposes require_admin_two_factor (default true) and canary_harness (default false); phpunit.xml sets them false and true"
    requirement: "FND-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/EnvExampleTest.php#it flips the kokpit switches for the test suite in phpunit.xml"
        status: pass
    human_judgment: false
  - id: D6
    description: "Pint and Larastan level 8 clean with no baseline; composer test, lint and stan scripts exist and pass inside DDEV"
    requirement: "FND-20"
    verification:
      - kind: other
        ref: "ddev composer lint; ddev composer stan ([OK] No errors); ddev composer test (21 passed)"
        status: pass
    human_judgment: false
  - id: D7
    description: "A fresh clone without vendor/ starts the daemons without a restart loop (assumption A2)"
    verification: []
    human_judgment: true
    rationale: "Wait-loop behaviour on a truly empty checkout was not exercised here; it is proven by the clean-clone ddev start human check in plan 02-13"

duration: 15min
completed: 2026-10-07
status: complete
commits: 4
plan_head_before: 805824b050bc876b226e15ee1e6081b658f36e1f
plan_head_after: 648c93c16e7fd90cf150a9a1c5ed0b47a5cfee28
---

# Phase 2 Plan 02: DDEV environment and test harness Summary

**A versioned DDEV project (PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS with bucket, queue worker and scheduler), Pest on a guarded `kokpit_test` database, a DDEV-ready `.env.example` provably in sync with the config, and Pint plus Larastan level 8 clean with no baseline.**

## Performance

- **Duration:** 15 min
- **Started:** 2026-10-07T14:52:00Z (approximate; the start time was not captured, derived from the first commit time minus the setup work)
- **Completed:** 2026-10-07T15:09:00Z
- **Tasks:** 3 of 3
- **Files modified:** 44 changed between plan start and end (about 30 of them are Pint's one-time `declare(strict_types=1)` pass over the existing tree)

## Accomplishments

- `ddev start` boots the web container on PHP 8.5.8 (nginx-fpm), PostgreSQL 18.6, Redis 7, Mailpit 1.31.0, RustFS 1.0.1 with the `kokpit-dev` bucket created by `rustfs-init`, and two supervised daemons. The post-start hook creates `kokpit_test` only when absent.
- `TestCase::createApplication()` throws "Refusing to run tests against database ..." unless the default connection is `pgsql` and the name ends in `_test`, before `RefreshDatabase` can migrate. Verified by running the suite with `DB_DATABASE=kokpit_guard_probe`: every test fails with the guard message and no such database is created.
- `.env.example` is rewritten DDEV-ready and checked against `config/*.php` by `EnvExampleTest` in both directions, including mutation cases (an unread key added, a no-default key removed, a commented-out `env()` call not counted).
- Pint (laravel preset plus `declare_strict_types`) and Larastan level 8 (app, routes, database/factories, database/seeders) pass on the current tree with no baseline and no `ignoreErrors`; `composer test`, `lint` and `stan` exist.

## Task Commits

1. **Task 1: Tracer, ddev start boots the app on PostgreSQL 18 and Pest runs against the guarded kokpit_test database** - `6eb4d53` (feat)
2. **Task 2 RED: failing DDEV contract and .env.example sync tests** - `e5ad929` (test)
3. **Task 2 GREEN: DDEV Redis, RustFS and daemons, DDEV-ready env template, kokpit config** - `ab4c934` (feat)
4. **Task 3: Pint and Larastan level 8 gates, composer scripts** - `648c93c` (chore)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

Tracer feedback gate: the tracer's `<verify>` carries only `<automated>` steps and `human_verify_mode` is `end-of-phase`, so the three verify commands were re-run end-to-end after the commit (5 passed, guard message present, `kokpit_test` exists) and execution expanded.

## TDD Gate Compliance

Task 2 (`tdd="true"`): RED commit `e5ad929` precedes GREEN commit `ab4c934`. No REFACTOR commit was needed.

- **RED:** `DdevConfigTest` and `EnvExampleTest` were run before any implementation. The target tests executed and failed on the planned assertions: `web_extra_daemons` empty (expected `queue-worker`, `scheduler`), `docker-compose.rustfs.yaml` and `docker-compose.redis.yaml` absent, `.env.example` still `DB_CONNECTION=sqlite`, 3 documented keys read by no config (`PHP_CLI_SERVER_WORKERS`, `BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`), 27 no-default `env()` keys undocumented, `config('kokpit.*')` unset. Not every test was RED: the config-parse test and the `kokpit_test` hook test passed already (they assert Task 1 output) and the commented-out-call test exercises a pure function. A first draft of the daemon wait test was vacuous (it looped over an empty list and Pest marked it risky); it was given a `toHaveCount(2)` precondition before the RED commit.
- **Semantic assessment:** each failure is the planned behavior missing, not a syntax, fixture or discovery fault. The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` was not enabled for this phase and Pest's default console output is not one of the classifier's supported report formats.
- **GREEN:** all 21 Feature tests pass.

## Files Created/Modified

- `.ddev/config.yaml` - project definition, daemons with wait loop, kokpit_test post-start hook
- `.ddev/docker-compose.rustfs.yaml` - RustFS and the keep-alive bucket init container
- `.ddev/docker-compose.redis.yaml`, `.ddev/redis/redis.conf`, `.ddev/commands/redis/*`, `.ddev/commands/host/redis-backend`, `.ddev/addon-metadata/redis/manifest.yaml` - official ddev-redis add-on v2.2.0 as generated
- `config/kokpit.php` - the two runtime switches
- `.env.example` - DDEV-ready template
- `config/database.php`, `config/cache.php`, `config/session.php` - pgsql default and UTC session time zone, Redis defaults, database cache store removed
- `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php` - PostgreSQL test environment, guard, suite wiring
- `tests/Feature/Boot/PanelBootTest.php`, `tests/Feature/Repo/DdevConfigTest.php`, `tests/Feature/Repo/EnvExampleTest.php` - boot and repository contract tests
- `phpstan.neon`, `pint.json`, `composer.json`, `composer.lock` - quality gates and `symfony/yaml`

## DDEV service versions (from `ddev describe` and the running containers)

| Service | Version |
|---|---|
| DDEV | v1.25.4 (Docker platform OrbStack, Mutagen enabled) |
| web | PHP 8.5.8, nginx-fpm, Node.js 24 (DDEV default, not set by this plan) |
| db | PostgreSQL 18.6 (`postgres:18`) |
| redis | `redis:7` via ddev/ddev-redis v2.2.0 |
| rustfs | `rustfs/rustfs:1.0.1` |
| rustfs-init | `rustfs/rc:v0.1.36` |
| Mailpit | v1.31.0 (DDEV built-in) |

## Decisions Made

- **`symfony/yaml` had to be added** (`composer require --dev symfony/yaml`, resolved `^8.1`, MIT). It was not installed before; it is a Symfony core package, covered by the licence and audit gates in 02-12.
- **Allowlist of `.env.example` keys not read by config:** exactly one, `KOKPIT_ADMIN_PASSWORD` (read only by `kokpit:install`, commented out in the file with a never-keep-it note). The three skeleton keys no config reads (`PHP_CLI_SERVER_WORKERS`, `BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`) were removed instead of allowlisted, as was `MEMCACHED_HOST`.
- **Forward sync is satisfied with commented placeholders.** The skeleton configs read 34 keys without a default (provider keys for Postmark, Resend, Slack, Papertrail, Memcached, DynamoDB and so on). They are documented in `.env.example` as commented `# KEY=` lines under one "optional" heading rather than pruning those config blocks, which is out of scope for this plan.
- **Pint strict types tree-wide** (including `config/`, `public/index.php`, migrations): Pint has no per-directory rules and the full Pest suite plus Larastan stayed green.
- **RustFS key pair** is the publicly documented RustFS development default, used in `.ddev/docker-compose.rustfs.yaml` and `.env.example`; both scanners (`scripts/check-sensitive.sh --all` and gitleaks in the hook) accept it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Login page assertion used the wrong input name**
- **Found during:** Task 1
- **Issue:** The plan's boot test looks for the Filament login form's email input name. Filament 5 renders `wire:model="data.email"` (id `form.email`), not `name="form.email"`.
- **Fix:** Asserted on `wire:model="data.email"`.
- **Files modified:** `tests/Feature/Boot/PanelBootTest.php`
- **Committed in:** `6eb4d53`

**2. [Rule 1 - Bug] Hostname scan in DdevConfigTest matched DDEV label keys**
- **Found during:** Task 2 (GREEN)
- **Issue:** The "no public-looking hostname" regex matched the `com.ddev.site-name` label keys that every DDEV compose file carries.
- **Fix:** Strip `com.ddev.` and `example.com` before scanning.
- **Files modified:** `tests/Feature/Repo/DdevConfigTest.php`
- **Committed in:** `ab4c934`

**3. [Rule 2 - Missing critical] Vacuous daemon wait test**
- **Found during:** Task 2 (RED run)
- **Issue:** The wait-loop test iterated an empty daemon list and passed vacuously (Pest flagged it risky).
- **Fix:** Added a `toHaveCount(2)` precondition before the loop.
- **Files modified:** `tests/Feature/Repo/DdevConfigTest.php`
- **Committed in:** `e5ad929`

---

**Total deviations:** 3 auto-fixed (2 bugs in own tests, 1 missing assertion)
**Impact on plan:** None on scope.

## Issues Encountered

- `ddev exec printenv DB_DATABASE` printed nothing (exit 1) and the container exports no `DB_*` variables, so the phpunit `<env>` entries stay overridable by real environment variables, as the plan required; no stop was needed.
- The pre-existing local `.env` (created in 02-01) was left as is; DDEV rewrites its DB settings and `ddev artisan config:show database.default` reports `pgsql`. It was never read with tools.
- `ddev start` prints a Mutagen warning that `upload_dirs` is unset for the laravel project type. Left as is; it concerns performance only and Phase 3 (FND-16 file storage) is the natural place to set it.
- `scripts/check-sensitive.sh` ran without `KOKPIT_DENYLIST` (generic patterns only, as in the hook); the denylist is a local-developer control outside the repository.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-05 (test database guard) is mitigated and verified; T-02-07 (published values) is mitigated: only DDEV service names and the public RustFS pair appear, and `scripts/check-sensitive.sh --all` plus gitleaks pass. T-02-06 and T-02-08 are accepted as planned (images pinned by tag, not digest; the FND-20 digest-pinning edge stays open for the owner).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plans 02-03 onward can verify through `ddev exec vendor/bin/pest` against `kokpit_test`; `composer lint` and `composer stan` are green and must stay green. DDEV containers are left running.
- 02-03 drops the `cache` table migration (the database cache store is already removed from config); 02-12 adds `licenses` and `ci` composer scripts and the CI jobs (the `tests` job must set `DB_*` itself).
- 02-13 owns the clean-clone `ddev start` check that proves the daemon wait loop (assumption A2) on a checkout without `vendor/`.
- FND-16 (Phase 3) must solve the browser-reachable S3 URL; the RustFS endpoint here is `http://rustfs:9000` inside DDEV only.

## Self-Check: PASSED

- All created files exist (`.ddev/config.yaml`, `.ddev/docker-compose.rustfs.yaml`, `.ddev/docker-compose.redis.yaml`, `config/kokpit.php`, `tests/Pest.php`, the three tests, `phpstan.neon`, `pint.json`): verified present and tracked.
- Commits `6eb4d53`, `e5ad929`, `ab4c934`, `648c93c` are ancestors of HEAD; `git rev-list --count 805824b..HEAD` is 4.
- All acceptance criteria of Tasks 1 to 3 re-run and passing; `ddev composer test` 21 passed, `ddev composer lint` and `ddev composer stan` clean, `scripts/check-sensitive.sh --all` exit 0.
