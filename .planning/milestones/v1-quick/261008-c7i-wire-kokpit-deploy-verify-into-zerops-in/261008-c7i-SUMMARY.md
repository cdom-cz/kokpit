---
phase: quick-261008-c7i
plan: 01
subsystem: deploy
tags: [zerops, scheduler, onOneServer, deploy-verify, horizon, pest, contract-tests]
requires:
  - quick 261008-bp0 (single-setup zerops.yml contract tests)
  - quick 261008-bec (Laravel Horizon installed)
provides:
  - every scheduled event runs onOneServer, enforced by ScheduleOnOneServerTest with a non-vacuity self-check
  - ProductionConfigGuard refuses a production boot unless the cache store is redis
  - kokpit:deploy:verify wired into run.initCommands directly after the execOnce migration, hardened per IN-04
  - zerops.yml without any frontend toolchain
  - ZeropsConfigTest checks for frontend, verify and Horizon start with mutation self-check
affects: [deploy, FND-09, FND-15]
requirements: [FND-09, FND-15]
requirements-completed: [FND-09, FND-15]
tech-stack:
  added: []
  patterns:
    - problem-list function plus in-memory mutation self-check for each manifest invariant
    - explicit cache store in every production-boot test (03-14 / WR-03 pattern)
key-files:
  created:
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
  modified:
    - routes/console.php
    - app/Support/ProductionConfigGuard.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php
    - tests/Isolation/PanelAccessTest.php
    - app/Console/Commands/DeployVerifyCommand.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/DeployVerifyCommandTest.php
    - zerops.yml
    - tests/Feature/Repo/ZeropsConfigTest.php
    - CONTRIBUTING.md
    - README.md
    - .planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md
key-decisions:
  - "The ProductionConfigGuard cache.default = redis check landed (not reverted); every production-boot test sets the store explicitly"
  - "Horizon start is guarded by its own ZeropsConfigTest category (horizon): exact plain sudo supervisorctl start horizon, last horizon/supervisor init step, after migrate and verify, never in execOnce"
  - "A falsy Redis ping and a broken session connection (redis or database driver) fail kokpit:deploy:verify with a fixed translated reason, never a connection value"
commits: 3
plan_head_before: 0313e6ecbf3d9ddf1ca5798d5d3369d7b5349b8e
plan_head_after: 9948a8016af68017586e5c9bbeb219a968d77dd5
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
duration: 8 min
completed: 2026-10-08
status: complete
---

# Quick Task 261008-c7i: Wire kokpit:deploy:verify into Zerops initCommands Summary

**A failed migration or a failed readiness check now ends the Zerops deploy before traffic switches, every scheduled task runs on one server only (with a production guard on the shared Redis cache), and the build no longer carries a Node/pnpm toolchain the repository does not have.**

## Performance

- **Duration:** about 8 min wall clock (06:58Z to 07:06Z)
- **Tasks:** 3
- **Files modified:** 13 (1 created)
- **Commits:** 3 (measured with `git rev-list --count 0313e6e..HEAD`)

## Accomplishments

- **Scheduler (Task 1).** `kokpit-heartbeat` (closure, `name()` before `onOneServer()`) and `kokpit-worker-heartbeat` (job) gained `onOneServer()`; `kokpit-horizon-snapshot` already had it. `ScheduleOnOneServerTest` fails when any registered event lacks it and proves it is not vacuous (the real schedule holds the three named events; a probe event without `onOneServer` is reported; an event added to `app(Schedule::class)` is seen by the same check). The explanatory comment moved to one block above the schedule.
- **Cache-store guard (Task 1).** `ProductionConfigGuard` has a last check (after APP_URL): `cache.default` must be exactly `redis`, else `Refusing to boot in production: CACHE_STORE must be redis.` Unit tests cover array, file, database, `Redis`, empty string, null, a missing key, redis allowed, non-production allowed. The in-process provider test, the real-process 2FA test (new third run with `CACHE_STORE=array` fails with `CACHE_STORE must be redis`, proving the env name maps to the guarded key) and the PanelAccess production-process test set the store explicitly.
- **Verify command (Task 2, IN-04).** `kokpit:deploy:verify` keeps three checks and three lines. The database check also pings the session database connection when `session.driver` is `database`; the Redis check also pings the session connection when `session.driver` is `redis` (null means `default`); a falsy ping fails with the new `deploy_verify.no_answer` reason. Output never contains a connection value (tests assert no `127.0.0.1` and no runtime-assembled password).
- **Manifest (Task 2).** `zerops.yml` lost the `alpine/nodejs@24` base, the `pnpm install` and `pnpm run build` build commands, the build `envVariables` map and the `node_modules` / `pnpm-lock.yaml` cache entries. `php artisan kokpit:deploy:verify` sits directly after the `zsc execOnce ... migrate --force` line and before `filament:optimize` and every Horizon/supervisor command, plain (no sudo, no execOnce), with an explanatory comment. No `readinessCheck`. Nothing else changed.
- **Contract test (Task 2).** `ZeropsConfigTest` keeps its nine invariants and adds `frontend`, `verify` and `horizon` checks. The self-check now has 21 more mutations (verify: removed, before the migration, inside execOnce, after filament:optimize, after the supervisor steps, duplicated, failure swallowed with `|| true`; frontend: pnpm step, node base, npm init step, build environment map with a reference, node_modules cache; horizon: start removed, before the verify, inside execOnce, a supervisor step after it). The old VITE literal mutation became a neutral `APP_NAME` literal build variable (category environment).
- **Docs (Task 3).** CONTRIBUTING (Scheduled tasks convention; Deploy checklist and Rules: stop at the first failing init command, verify gate, Horizon start, PHP-only build, multi-container note), README (Deploy and Operations), and the rehearsal checklist (check 3 log lines, check 6 two-container expectation, check 8 failing migration, new check 9 failing verify, Chromium renumbered to 10, extra observations, three follow-ups marked "Resolved by quick task 261008-c7i", open ones kept).

## Task Commits

1. **Task 1: onOneServer contract test, routes fix, cache-store guard** - `f9d3ea8` (fix)
2. **Task 2: verify-gated deploy, hardened verify, frontend build removed, manifest contract** - `cb849a8` (feat)
3. **Task 3: maintainer docs and rehearsal checklist** - `9948a80` (docs)

Each `git show --stat` lists only the plan files for its task (6, 5 and 3 files). The orchestrator commits this SUMMARY, STATE and PLAN.

## Baseline and red runs

- **Task 1 baseline** (Operations directory, ProductionConfigGuardTest, TwoFactorEnforcementTest, PanelAccessTest): 374 passed, 0 failed.
- **Red run of ScheduleOnOneServerTest** before the routes fix: "registers the known scheduled events" and the self-check passed; "runs every scheduled event on one server only" failed and named exactly `kokpit-heartbeat` and `kokpit-worker-heartbeat`.
- **Task 2 red runs:** `DeployVerifyCommandTest` 3 failed (falsy ping, redis session connection, database session connection) before the command change, 10 passed after; `ZeropsConfigTest` 4 failed before the manifest edit (frontend, verify, horizon, mutation self-check), 13 passed after.
- **Final gate (all green):** `ddev composer ci` exit 0 (1013 tests, 4251 assertions; Pint 263 files; PHPStan no errors; licence check 209 packages), `bash scripts/tests/run.sh` PASS 10 FAIL 0, `actionlint` clean, `zizmor --offline .github/workflows` no findings (3 suppressed, pre-existing), `scripts/check-sensitive.sh --all` clean. `git diff 0313e6e HEAD -- site.conf.tmpl supervisor-horizon.ini .deployignore` is empty.

## Deviations from Plan

### Owner requirement added after planning

**1. [Owner requirement] Horizon-start guard in ZeropsConfigTest and docs**
- `zeropsHorizonProblems` (category `horizon`) requires exactly one `sudo supervisorctl start horizon` in `run.initCommands`, plain (not in execOnce), strictly after the migration and the verify, and the last command matching horizon or supervisor. Four mutations prove it (start removed, before the verify, inside execOnce, a supervisor step after it). The manifest itself was not changed for this.
- CONTRIBUTING Deploy Rules and README Deploy say Horizon is started by the last init command on every container start only after migrate and verify passed, and that supervisord `autostart` brings it back after a restart.
- Committed in: cb849a8 (test), 9948a80 (docs)

### Auto-fixed Issues

**2. [Rule 3 - Blocking] zsh does not word-split a path list in a variable**
- **Found during:** Task 1 commit (first commit attempt failed with a pathspec error and committed nothing)
- **Fix:** staged and committed with a zsh array and an explicit pathspec; no repository file affected.

### Small additions not in the plan

- Rehearsal "Extra observations" got one extra item beyond the planned text: whether `sudo supervisorctl start horizon`, the last init command, exits 0 when supervisord already started Horizon through `autostart` after `supervisorctl update` (a non-zero exit would stop the deploy). See flags below.
- The Known follow-ups Horizon bullet now says 261008-bec "installed" (past tense) instead of "installs".

**Total deviations:** 1 owner-requested addition, 1 trivial blocking fix. **Impact:** none on scope; zerops.yml changed only as authorised.

## Flags for the owner

1. **Unconfirmed Zerops behaviour: a failing initCommand stops the deploy.** The zsc reference documents only that a failed `execOnce` is reported as failed on every container. That a failing init command ends the deploy and keeps the previous version serving is the owner's statement, not documented by Zerops. The docs and tests now rely on it; rehearsal checks 8 and 9 are the only proof and are still unticked.
2. **Two-container execOnce race.** A second container's `kokpit:deploy:verify` could run while the first container's execOnce migration is still running, see a pending migration and stop a healthy deploy (fails safe: the previous version keeps serving). Recorded as a rehearsal extra observation (threat T-261008-c7i-05, accepted).
3. **SCHEDULE_CACHE_STORE gap.** The guard checks only `cache.default`. `SCHEDULE_CACHE_STORE` / `SCHEDULE_CACHE_DRIVER` can still point the scheduler lock at a per-container store without the guard noticing. CONTRIBUTING says to leave both unset; they are set only in the Zerops UI (threat T-261008-c7i-06, accepted). A guard on `cache.schedule_store` / those variables would need a config key that does not exist yet.
4. **ProductionConfigGuard cache.default = redis check: LANDED**, not reverted. All production-boot tests stayed green with explicit store settings, so the Task 1 fallback was not needed and CONTRIBUTING/README state the guard as present.
5. **Possible non-zero exit of the last init command (new, unconfirmed).** `sudo supervisorctl update` can already start `horizon` through `autostart=true`; a following `sudo supervisorctl start horizon` may then print "already started" and exit non-zero on some supervisor versions, which would stop the deploy after the verify passed. I did not change the manifest (owner constraint); the rehearsal now asks to record the exit status. If it fails, the owner decides between dropping the explicit start line or tolerating it (and `ZeropsConfigTest` horizon check adapts in the same change).
6. Test failures attributed to a parallel session: none (checked with `pgrep` before each Pest run; no collisions).

## Known Stubs

None.

## Threat Flags

None. The only new check surface is the cache-store guard, which is the planned mitigation T-261008-c7i-04.

## Self-Check: PASSED

- All 13 modified or created files exist; `f9d3ea8`, `cb849a8` and `9948a80` are ancestors of HEAD.
- `git rev-list --count 0313e6e..HEAD` = 3, matching `commits: 3`.
- In `zerops.yml` the order is migrate (line 30), verify (line 35), `filament:optimize` (line 36).
- Working tree clean except the untracked quick-task planning directory (docs commit left to the orchestrator).
