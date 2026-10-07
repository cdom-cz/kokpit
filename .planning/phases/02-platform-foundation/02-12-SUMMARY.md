---
phase: 02-platform-foundation
plan: 12
subsystem: infra
tags: [github-actions, ci, postgresql, pest, larastan, pint, composer-audit, licence-allowlist, dependabot]

requires:
  - phase: 02-platform-foundation
    provides: composer scripts test, lint, stan and phpstan.neon/pint.json (02-02); kokpit:install with KOKPIT_ADMIN_PASSWORD (02-09); .ddev/config.yaml (02-02); .env.example and EnvExampleTest (02-02)
provides:
  - Hygiene workflow jobs tests (PostgreSQL 18 + Redis services, boot from .env.example, Pest), static-analysis (Pint, Larastan level 8) and dependencies (composer validate, audit, licence allowlist), all behind CI Passed
  - scripts/boot-from-env-example.sh (automated proof of FND-01)
  - scripts/check-licenses.php (AGPL-compatible licence allowlist with OR semantics, exit 0/1/2)
  - scripts/tests/test-workflow.sh (ci-passed.needs must list every job, mutation cases)
  - tests/Feature/Repo/CiParityTest.php (CI PHP and PostgreSQL equal .ddev, every job pinned and least-privilege)
  - Dependabot for Composer; composer scripts check-licenses and ci
affects: [02-13, phase-03 and later CI runs, any plan that adds a workflow job]

actuals:
  tokens: 7800
  tasks: 2
  commits: 3

plan_head_before: 55366a37950b9337745a1830121e6edc5fe6a5a6
plan_head_after: a144bef

tech-stack:
  added: [shivammathur/setup-php 2.37.2 (CI only), postgres:18 and redis:7 service images (CI only)]
  patterns:
    - "Application CI jobs are inline in hygiene.yml (no reusable workflow) with ubuntu-24.04, job-level contents: read, persist-credentials: false and full-SHA pinned actions with a version comment"
    - "ci-passed.needs lists every other job; scripts/tests/test-workflow.sh fails when one is missing (mutation-proven)"
    - "Real job environment wins over phpunit.xml and .env; DB_DATABASE ends in _test so tests/TestCase.php accepts it"
    - "Licence rule: a package passes when at least one declared licence is on the allowlist; the list is changed only by a reviewed decision"
    - "Static contract tests parse .ddev/config.yaml and the workflow with symfony/yaml and need no Docker or network"

key-files:
  created:
    - scripts/boot-from-env-example.sh
    - scripts/check-licenses.php
    - scripts/tests/test-workflow.sh
    - tests/Feature/Repo/CiParityTest.php
    - tests/Unit/LicenceCheckTest.php
  modified:
    - .github/workflows/hygiene.yml
    - .github/dependabot.yml
    - composer.json
    - CONTRIBUTING.md

key-decisions:
  - "Composer script is named check-licenses, not licenses: Composer skips a script that shares a name with a native command (\"A script named licenses would override a Composer command and has been skipped\"), so composer licenses kept printing the native table and exited 0 whatever the licences were"
  - "Service images use version tags (postgres:18, redis:7 as in the DDEV Redis add-on), not digests, as the plan's threat model states; zizmor reports them only in the pedantic persona"
  - "Throwaway Postgres credentials are literal values duplicated in services and job env (the services block cannot read the job env); CiParityTest asserts the two copies are equal"
  - "static-analysis runs without a database or .env; proven in a fresh clone"
  - "The dependencies job installs nothing: validate, audit --locked and licenses --locked read composer.json and composer.lock only"

patterns-established:
  - "Boot proof: scripts/boot-from-env-example.sh refuses to run when .env exists, so it only ever runs in a fresh checkout or CI"
  - "A failing licence is a prompt to decide, never a reason to extend the allowlist silently (comment in the script, text in CONTRIBUTING)"

requirements-completed: [FND-13, FND-20, FND-01]

coverage:
  - id: D1
    description: "tests job: PostgreSQL 18 and Redis services, boot from .env.example alone (copy, key:generate, migrate, kokpit:install with a generated password), then Pest; gated by CI Passed"
    requirement: "FND-01"
    verification:
      - kind: integration
        ref: "ddev exec bash -s < fresh-clone probe running scripts/boot-from-env-example.sh against a throwaway database"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/CiParityTest.php#it boots the tests job from .env.example before Pest runs"
        status: pass
      - kind: other
        ref: "actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows"
        status: pass
    human_judgment: true
    rationale: "The job itself only runs on GitHub; whether setup-php installs PHP 8.5 on ubuntu-24.04 and the services start is only shown by the first push (flagged assumption A6, phase-gate check in 02-13)"
  - id: D2
    description: "CI Passed needs every job; test-workflow.sh fails when any job id is missing from its needs list"
    requirement: "FND-13"
    verification:
      - kind: integration
        ref: "scripts/tests/test-workflow.sh (mutation: dropped id and an added job are reported), also under mawk and bash 5.2 via scripts/tests/run-in-ubuntu.sh"
        status: pass
    human_judgment: false
  - id: D3
    description: "CI uses the same PHP and PostgreSQL versions as .ddev/config.yaml, enforced by a test"
    requirement: "FND-20"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/CiParityTest.php#it runs the tests job on the PHP version of the DDEV project"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/CiParityTest.php#it runs the tests job on the PostgreSQL major version of the DDEV project"
        status: pass
    human_judgment: false
  - id: D4
    description: "Licence allowlist with OR semantics: GPL-2.0-only alone, no licence, proprietary and unknown licences fail with the package named; dual-licensed and MIT pass; malformed input exits 2; the real locked licences pass"
    requirement: "FND-13"
    verification:
      - kind: unit
        ref: "tests/Unit/LicenceCheckTest.php (37 tests)"
        status: pass
      - kind: other
        ref: "ddev composer check-licenses (200 packages)"
        status: pass
    human_judgment: false
  - id: D5
    description: "static-analysis and dependencies jobs, every job with ubuntu-24.04, contents: read, persist-credentials: false and SHA-pinned actions; Dependabot for Composer; composer ci runs the PHP gates locally"
    requirement: "FND-13"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/CiParityTest.php#it gives every job a pinned runner, least-privilege token and SHA-pinned actions"
        status: pass
      - kind: other
        ref: "ddev composer ci (test, lint, stan, check-licenses)"
        status: pass
    human_judgment: false

duration: 10min
completed: 2026-10-07
status: complete
---

# Phase 2 Plan 12: CI application gates Summary

**Hygiene workflow now runs Pest on PostgreSQL 18 after booting the app from .env.example alone, Pint and Larastan level 8, and Composer validate/audit plus an AGPL-compatible licence allowlist, all behind the single CI Passed check.**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-07T20:00:34Z
- **Completed:** 2026-10-07T20:10:00Z
- **Tasks:** 2 (3 commits: tracer, RED, GREEN)
- **Files modified:** 9 (5 created, 4 modified)

## Accomplishments

- `tests` job ("Tests (PostgreSQL 18)"): `postgres:18` and `redis:7` services with health checks, PHP 8.5 through setup-php, `composer install`, the boot script (generated Admin password, never echoed), then `vendor/bin/pest`. The same boot script succeeds in a fresh clone inside DDEV (migrations, `kokpit:install`, `admin/login` route check) and refuses to run when `.env` exists or the password is empty.
- `static-analysis` and `dependencies` jobs; `ci-passed.needs` is `[scan, workflow-lint, tests, static-analysis, dependencies]`, the name stays exactly `CI Passed`, and `scripts/tests/test-workflow.sh` makes a dropped job a failure.
- `scripts/check-licenses.php`: 19-entry AGPL-compatible allowlist, OR semantics so `nette/*` (BSD-3-Clause or GPL-2.0-only or GPL-3.0-only) pass while a GPL-2.0-only package, a package without a licence and proprietary terms fail; 200 locked packages pass.
- Dependabot proposes Composer updates; `composer check-licenses` and `composer ci` run the gates locally.

## Pinned versions (for the record)

| Item | Pin |
|---|---|
| `actions/checkout` | `3d3c42e5aac5ba805825da76410c181273ba90b1` (v7.0.1, unchanged) |
| `shivammathur/setup-php` | `f3e473d116dcccaddc5834248c87452386958240` (2.37.2, resolved with `gh api repos/shivammathur/setup-php/git/ref/tags/2.37.2`, lightweight tag so the ref is the commit) |
| PostgreSQL service | `postgres:18` (tag, equals `.ddev/config.yaml` `database.version`) |
| Redis service | `redis:7` (tag, the image tag of the DDEV Redis add-on) |
| PHP | `8.5` (equals `.ddev/config.yaml` `php_version`) |

## Task Commits

1. **Task 1 (tracer): tests job, boot script, workflow test, parity test** - `2adb240` (feat)
2. **Task 2 RED: failing licence tests** - `5a039dc` (test)
3. **Task 2 GREEN: licence script, static-analysis and dependencies jobs, Dependabot, composer scripts** - `a144bef` (feat)

**Plan metadata:** not committed on purpose (`commit_docs` is false, `.planning/` is untracked).

## Files Created/Modified

- `.github/workflows/hygiene.yml` - jobs `tests`, `static-analysis`, `dependencies`; extended `ci-passed.needs`
- `.github/dependabot.yml` - Composer ecosystem added, header comment updated
- `scripts/boot-from-env-example.sh` - boot proof from `.env.example` (mode 755, shellcheck clean)
- `scripts/check-licenses.php` - licence allowlist gate
- `scripts/tests/test-workflow.sh` - `ci-passed.needs` completeness, with mutation cases (auto-discovered by `run.sh`)
- `tests/Feature/Repo/CiParityTest.php` - six static contract tests between `.ddev` and the workflow
- `tests/Unit/LicenceCheckTest.php` - 37 tests (behaviour list, every allowlist entry, malformed input, real locked licences)
- `composer.json` - scripts `check-licenses` and `ci`
- `CONTRIBUTING.md` - CI section lists the new jobs, `composer ci` and the licence rule

## Decisions Made

See `key-decisions` above. The notable one is the script rename (deviation 1).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Composer script `licenses` is shadowed by the native command**
- **Found during:** Task 2 (composer scripts)
- **Issue:** `ddev composer licenses` printed Composer's own licence table and exited 0, with the warning "A script named licenses would override a Composer command and has been skipped". The plan's verify step `ddev composer licenses` would therefore have passed without running the gate, and `composer licenses` could never run it. A second defect in the plan's one-liner: `@php` is only expanded at the start of a command, so after a pipe it failed with `@php: not found`.
- **Fix:** named the script `check-licenses` (`@composer licenses --locked --format=json | php scripts/check-licenses.php`); `ci` runs `@test`, `@lint`, `@stan`, `@check-licenses`. `ddev composer check-licenses` and `ddev composer ci` pass.
- **Consequence for the plan text:** the acceptance check `isset($s["licenses"], $s["ci"])` is now `isset($s["check-licenses"], $s["ci"])`; the artifact table and the must-have "composer licenses and composer ci" should read `composer check-licenses`. Every other acceptance criterion passes as written.
- **Files modified:** composer.json, CONTRIBUTING.md
- **Verification:** `ddev composer validate --strict`, `ddev composer check-licenses` (200 packages), `ddev composer ci`, no Composer warning in `ddev composer list`
- **Committed in:** a144bef

**2. [Rule 3 - Blocking] Unit tests boot no application, so `base_path()` is undefined in `tests/Unit`**
- **Found during:** Task 2 RED
- **Issue:** the first RED run crashed on `base_path()` (an invalid RED: fixture error, not the planned assertion)
- **Fix:** the test derives the repository root from `__DIR__`; the corrected RED then failed on the planned assertions ("Could not open input file" instead of the expected offender and "no licence declared" text)
- **Files modified:** tests/Unit/LicenceCheckTest.php
- **Committed in:** 5a039dc

**3. [Rule 3 - Blocking] The plan's fresh-clone verify command does not run through `ddev exec bash -c '...'`**
- **Found during:** Task 1 local proof
- **Issue:** `ddev exec` re-wraps the string so `$t` and `$(...)` are expanded by an outer shell (`t: unbound variable`). The command itself is sound.
- **Fix:** ran the identical commands as a script on stdin (`ddev exec bash -s < probe.sh`). Before the Task 1 commit the clone could not contain the new script, so that first run copied it in; after the commit the run was repeated with no copy and passed.
- **Files modified:** none

**4. [Rule 2 - Missing Critical] CONTRIBUTING CI section updated**
- **Found during:** Task 2
- **Issue:** RESEARCH Pattern 10 requires the CONTRIBUTING CI text to list the new jobs; the file was not in the plan's `files_modified`.
- **Fix:** the CI section names the new jobs, `composer ci` and the licence rule. `scripts/tests/test-docs.sh` still passes.
- **Files modified:** CONTRIBUTING.md
- **Committed in:** a144bef

---

**Total deviations:** 4 (1 bug, 2 blocking, 1 missing critical)
**Impact on plan:** no scope creep. The script rename is the only change of an interface the plan named.

## Proven locally vs only by a real push

Proven locally:
- `actionlint` and `zizmor --offline .github/workflows` clean on the merged workflow (zizmor regular persona: no findings; the "2 suppressed" are `unpinned-images` for the two service tags, reported only by `--pedantic`, accepted per the plan's threat model T-02-SC); `shellcheck` clean on both new scripts.
- Bash suite (`scripts/tests/run.sh`): PASS 10, FAIL 0, also under mawk 1.3.4 and bash 5.2 through `run-in-ubuntu.sh`.
- Boot script in a fresh clone inside DDEV against a throwaway database (key:generate, 11 migrations, kokpit:install, `admin/login` listed); refusals with `.env` present and with an empty or unset password.
- `static-analysis` and `dependencies` commands in a fresh clone with no `.env` (Pint 145 files, PHPStan no errors, validate, audit, licences).
- Mutation checks: changing the workflow's PostgreSQL major or PHP version fails CiParityTest; removing a job id from `needs` is reported by `test-workflow.sh`.
- `ddev composer ci` green (full Pest suite, Pint, PHPStan, licences).

Only a real push will show (nothing was pushed, as instructed):
- that `shivammathur/setup-php` installs PHP 8.5 on `ubuntu-24.04` (flagged assumption A6, phase-gate check in 02-13) and that the `postgres:18` and `redis:7` services start and pass their health checks on the runner;
- that Pest passes with the runner's real environment (job env `DB_HOST=127.0.0.1`, `REDIS_HOST=127.0.0.1`) rather than DDEV's hostnames, including the concurrency test's child processes;
- that the organisation ruleset accepts the unchanged `CI Passed` name with the new jobs behind it (assumption A7), and that Dependabot picks up the Composer ecosystem;
- the tracer feedback gate: the tracer's `<verify>` is fully automated, so it was re-run end to end (it passed) instead of stopping for a checkpoint.

## TDD Gate Compliance

Task 2 carries `tdd="true"` (the plan is `type: execute`, so the plan-level gate file is not required).
- **RED** `5a039dc`: 37 tests; they execute and fail on the planned assertions because the script does not exist yet (the first attempt crashed on `base_path()` and was discarded as an invalid RED, see deviation 2). Semantic assessment: the failing assertions are the intended ones (offender name and "no licence declared" on stderr, exit 0 for allowed packages, exit 2 for malformed input).
- **GREEN** `a144bef`: all 37 pass, plus the full suite.
- **REFACTOR:** none needed.

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None.

## Threat Flags

None. The new CI surface (three jobs, two service containers, a third-party action) is covered by T-02-46, T-02-47, T-02-48, T-02-49 and T-02-SC; the triggers, top-level `permissions: {}` and the existing jobs are unchanged.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Ready for 02-13 (phase gate). Its human check should include the first CI run after the push: setup-php on `ubuntu-24.04` with PHP 8.5, both service containers, the boot step, and that `CI Passed` is green.
- Plan wording to correct if 02-13 or a verifier quotes it: the composer script is `check-licenses` (deviation 1).

## Self-Check: PASSED

- Files exist: `scripts/boot-from-env-example.sh`, `scripts/check-licenses.php`, `scripts/tests/test-workflow.sh`, `tests/Feature/Repo/CiParityTest.php`, `tests/Unit/LicenceCheckTest.php` (checked below).
- Commits `2adb240`, `5a039dc`, `a144bef` are ancestors of HEAD.

---
*Phase: 02-platform-foundation*
*Completed: 2026-10-07*
