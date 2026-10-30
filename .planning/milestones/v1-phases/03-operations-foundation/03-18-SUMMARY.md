---
phase: 03-operations-foundation
plan: 18
subsystem: infra
tags: [zerops, deploy, github-actions, zcli, laravel, migrations, trusted-proxies, readiness-check]

requires:
  - phase: 03-operations-foundation
    provides: "03-14 ProductionConfigGuard and Redis queue, 03-16 scheduler and worker heartbeat, 03-17 kokpit:storage:check and hygiene.yml tool-pin conventions, 03-01 Dompdf decision (extensions to confirm)"
provides:
  - "zerops.yml with three full setups (app, worker, scheduler), once-per-deploy migrations and no secret value"
  - "kokpit:deploy:verify readiness command (database, pending migrations incl. package paths, Redis)"
  - "trustProxies(at: '*') so URLs behind the Zerops balancer are https"
  - ".github/workflows/deploy.yml: release published or workflow_dispatch only, secret-free verify job, protected production environment deploy job"
  - "Static contract tests for the manifest and the workflow, and a documentation test for the manual settings"
  - "CONTRIBUTING 'Deploy (maintainer, manual)' checklist, README Deploy section, 03-ZEROPS-REHEARSAL.md"
affects: [phase-8-pdf-report, phase-10-invoicing, release-process, operations]

actuals:
  tokens: 27600
  tasks: 3
  commits: 5

plan_head_before: 8d56dc7e475fdc33233e55ba6ebe19e5120e0454
plan_head_after: 0cb42458e017dbcb4782e4779e34995ac31351f5

tech-stack:
  added: ["zcli 1.1.2 (pinned, checksum-verified binary used only in the deploy workflow; no Composer or npm package added)"]
  patterns:
    - "Readiness gate as an Artisan command that prints check names and results only, never connection details"
    - "Workflow contract tests as pure functions over a parsed array returning problem lists, with a mutation self-check and a behavioural run of the verify script in a throwaway git repository"
    - "Hard-coded SHA-256 for downloaded release binaries (same procedure as gitleaks, actionlint, zizmor)"

key-files:
  created:
    - zerops.yml
    - app/Console/Commands/DeployVerifyCommand.php
    - .github/workflows/deploy.yml
    - tests/Feature/Repo/ZeropsConfigTest.php
    - tests/Feature/Repo/DeployWorkflowTest.php
    - tests/Feature/Operations/DeployVerifyCommandTest.php
    - tests/Feature/Operations/TrustedProxiesTest.php
    - .planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md
  modified:
    - bootstrap/app.php
    - lang/cs/kokpit.php
    - tests/Isolation/SystemRunCommandsTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - README.md

key-decisions:
  - "zcli pinned to 1.1.2, asset zcli-linux-amd64, SHA-256 b85d5cda8158d2be2e4eb42f4ed27ed0896f5d03ad31366c0da8fba48f49ffd7"
  - "Service id variables are ZEROPS_APP_SERVICE_ID, ZEROPS_WORKER_SERVICE_ID and ZEROPS_SCHEDULER_SERVICE_ID (environment variables of production); a test ties the workflow to the CONTRIBUTING checklist"
  - "kokpit:deploy:verify reads the migrator's registered paths plus database/migrations, so a pending settings migration blocks the deploy too"
  - "Redis check pings the default connection and the queue and cache connections from config; a failure is reported by exception class only"
  - "APP_URL is deliberately not in zerops.yml (instance-specific); it is a project-level value set in the Zerops GUI and listed in the rehearsal prerequisites"
  - "Verify job accepts release tags matching v* exactly as the plan states (not the stricter v[0-9]*), so the script, the environment tag rule and the docs agree"

patterns-established:
  - "Deploy path changes are guarded by DeployWorkflowTest: any extra trigger, expression in a run script, secret outside the deploy job, unpinned action or credential-keeping checkout fails the suite"
  - "Verification of Docker/DDEV-synced files: after editing on the host wait for the mutagen sync (check with ddev exec grep) before trusting a mutation run"

requirements-completed: [FND-15]

coverage:
  - id: D1
    description: "zerops.yml describes app, worker and scheduler, once-per-deploy migrations in the app setup only, readiness gate, no secret value"
    requirement: FND-15
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/ZeropsConfigTest.php (15 tests)"
        status: pass
    human_judgment: false
  - id: D2
    description: "kokpit:deploy:verify exits non-zero on a pending migration (package paths included), unreachable Redis; prints no connection details; runs in the system context"
    requirement: FND-15
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/DeployVerifyCommandTest.php (5 tests), tests/Isolation/SystemRunCommandsTest.php#reads the migrations table of the deploy check inside the system context"
        status: pass
    human_judgment: false
  - id: D3
    description: "Behind a forwarding balancer the application generates https URLs"
    requirement: FND-15
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/TrustedProxiesTest.php (3 tests)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Deploy workflow starts only on release published or manual dispatch, runs in the protected production environment, pinned actions, no injection, token only through step env, verified zcli binary"
    requirement: FND-15
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/DeployWorkflowTest.php (17 tests incl. mutation self-check and verify-script behaviour)"
        status: pass
      - kind: other
        ref: "actionlint; zizmor --offline .github/workflows (also clean under --persona auditor for deploy.yml); gitleaks dir .github/workflows; scripts/check-sensitive.sh"
        status: pass
    human_judgment: false
  - id: D5
    description: "Manual GitHub and Zerops settings checklist documented and asserted; README Deploy section"
    requirement: FND-15
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#documents the manual GitHub and Zerops deploy settings in CONTRIBUTING.md"
        status: pass
    human_judgment: false
  - id: D6
    description: "On a real Zerops project a deploy whose migration fails leaves the previous version serving; phpredis on php-nginx@8.5; https URLs; Valkey policy; start commands; reference names; storage check; PDF extensions"
    requirement: FND-15
    verification: []
    human_judgment: true
    rationale: "Needs a real Zerops project and GitHub admin rights; non-blocking manual verification (resolved open question 4), checklist in 03-ZEROPS-REHEARSAL.md"

duration: 13min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 18: Zerops deploy path Summary

**Secret-free three-setup zerops.yml with execOnce migrations gated by a `kokpit:deploy:verify` readiness check, `trustProxies(at: '*')`, and a deploy workflow only a published `v*` release or a manual dispatch through the protected `production` environment can start, pinned by version and SHA-256 zcli, guarded by a mutation-tested contract test.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-10-08T04:54:55Z
- **Completed:** 2026-10-08T05:08:10Z (code and docs; SUMMARY and state updates follow)
- **Tasks:** 3
- **Files modified:** 14 (8 created, 6 modified)

## Accomplishments

- `zerops.yml`: setups `app`, `worker`, `scheduler`, written out in full without `extends` (identical build sections, asserted), `php@8.5` build and `php-nginx@8.5` runtime. Only the app setup migrates, as `zsc execOnce ${ZEROPS_appVersionId} -- php artisan migrate --force` before `config:cache`, `route:cache`, `view:cache` and `filament:optimize`; the app readiness check runs `php artisan kokpit:deploy:verify`, `/up` is the health check. Environment values are `${...}` references or non-secret literals; no `APP_KEY`.
- `kokpit:deploy:verify`: Czech output, three checks (database, migrations, Redis), runs inside `runAsSystem`. It counts migration files of `database/migrations` plus every path registered on the migrator (settings migrations included) that are not in the migrations table, and prints the pending count. Failures are reported by exception class; host, port, user and password never appear (tested).
- `bootstrap/app.php` trusts forwarded headers (`trustProxies(at: '*')`); a test proves https URLs for a forwarded HTTPS request and http without the header.
- `deploy.yml`: triggers exactly `release: published` and `workflow_dispatch` (no inputs), `permissions: {}`, concurrency `deploy-production` without cancel. The `verify` job (no environment, no secret) refuses prereleases, a non-tag or non-`v*` release ref and a commit that is not an ancestor of `origin/main`, reading event data from `env:` only. The `deploy` job runs in `production`, installs zcli 1.1.2 against a hard-coded SHA-256 (`sha256sum --check --strict`), logs in with the environment secret through step `env`, and pushes app, then worker, then scheduler with service ids from `vars` through `env`.
- `DeployWorkflowTest`: nine static checks, a self-check that feeds sixteen weakened copies (extra push trigger, expression in a run script, secret outside the deploy job or in a run script or at job level, unpinned action, credential-keeping checkout, missing environment, missing `needs`, cancelling concurrency, verify script without the ancestry check, bad digest, wrong push order) and requires each to be reported, plus five behavioural runs of the real verify script in a throwaway git repository (stable release and dispatch pass; prerelease, foreign tag and non-main commit are refused).
- Documentation: CONTRIBUTING "Deploy (maintainer, manual)" (unticked GitHub and Zerops checklist, rollback, expand-deploy-contract migration rule, zcli bump), README "Deploy" section naming both check commands, and `03-ZEROPS-REHEARSAL.md`.

## Task Commits

1. **Task 1 (tracer): zerops.yml, readiness command, proxy trust**
   - RED `78c98f9` (test) - 23 of 26 tests failed for the planned reasons (missing `zerops.yml`, unknown command, `trustProxies` absent)
   - GREEN `41fbca4` (feat)
2. **Task 2: deploy workflow and contract test**
   - RED `1c0f3a3` (test) - 16 of 17 tests failed because `deploy.yml` did not exist
   - GREEN `6033556` (feat)
3. **Task 3: documentation, documentation test, rehearsal checklist** - `0cb4245` (docs)

**Plan metadata:** the `docs(03-18): complete ...` commit that carries this SUMMARY, STATE.md, ROADMAP.md and REQUIREMENTS.md.

## TDD Gate Compliance

Task 1 and Task 2 followed RED then GREEN with separate commits (`test(03-18)` before `feat(03-18)`); no REFACTOR commit was needed (the PHPStan-driven restructuring of the command happened before the GREEN commit). Task 3 is a docs task and is one commit.

RED evidence is semantic, not machine-classified: the plan is `type: execute` and Pest output is not a format `gsd_run check tdd-red-evidence` supports. Inspected RED runs:
- Task 1: every failing test failed on the planned cause (zerops.yml absent, `kokpit:deploy:verify` not found, `trustProxies(at: '*')` absent). Two Task 1 tests were green in RED by design: the pre-existing system-context cases and "generates http URLs when the request carries no forwarded protocol" (a guard against over-trusting). An earlier draft of the TrustedProxies test passed vacuously because the test base URL is https; this was found at the RED run and fixed before the RED commit (absolute `http://` URI).
- Task 2: all failures were "deploy.yml does not exist" (ParseException), which is the planned cause. One test (hygiene aggregator unchanged) was green by design.

Mutation checks (file copied to the scratchpad first, restored afterwards, restoration verified by `diff`):
- `DeployVerifyCommand` reading only `database/migrations` instead of the migrator paths: "fails and names the number of pending migrations" failed (kill).
- `DeployVerifyCommand` without `runAsSystem`: the system-context test failed (kill).
- `trustProxies(at: '*')` replaced by a single address: the https and bootstrap tests failed (kill).
- `deploy.yml` with an added `push` trigger and the `environment: production` line removed: three DeployWorkflowTest cases failed (kill).
- `CONTRIBUTING.md` without `volatile-lru` and `Git integration`: the documentation test failed (kill).

## Files Created/Modified

- `zerops.yml` - three setups, execOnce migrate, readiness and health checks, references-only environment
- `app/Console/Commands/DeployVerifyCommand.php` - readiness command
- `bootstrap/app.php` - `trustProxies(at: '*')`
- `lang/cs/kokpit.php` - `deploy_verify.*` Czech strings
- `.github/workflows/deploy.yml` - deploy workflow
- `tests/Feature/Repo/ZeropsConfigTest.php`, `tests/Feature/Repo/DeployWorkflowTest.php`, `tests/Feature/Operations/DeployVerifyCommandTest.php`, `tests/Feature/Operations/TrustedProxiesTest.php`, `tests/Isolation/SystemRunCommandsTest.php`, `tests/Feature/Repo/RepositoryFilesTest.php` - contracts
- `CONTRIBUTING.md`, `README.md` - deploy documentation
- `.planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md` - manual rehearsal checklist

## Decisions Made

- **zcli pin:** version `1.1.2`, asset `zcli-linux-amd64`, SHA-256 `b85d5cda8158d2be2e4eb42f4ed27ed0896f5d03ad31366c0da8fba48f49ffd7`. Digest source: the executor downloaded the public release asset over HTTPS with plain `curl` (no `gh api`, no token), computed the SHA-256 locally (`shasum -a 256`), and compared it with the digest the release page lists (`releases/expanded_assets/v1.1.2`); both agree. The asset was never executed. The version was taken from the locally installed zcli, which reported `v1.1.2` as up to date.
- `APP_URL` stays out of `zerops.yml` (instance-specific); the rehearsal lists it with the project secrets.
- Valkey and storage reference names (`db_*`, `redis_*`, `storage_*`) are `[ASSUMED]` and are rehearsal item 3.
- The `release` ref must match `v*` exactly as the plan states; a stricter `v[0-9]*` would have diverged from the documented tag rule.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan return-type errors in the new command**
- **Found during:** Task 1 verification
- **Issue:** `checkDatabase()` and `checkRedis()` never returned a string and `__()` returns `array|string|null`.
- **Fix:** database and Redis checks became `void` methods wrapped by closures that return null; the pending-migrations message is cast to string.
- **Files modified:** `app/Console/Commands/DeployVerifyCommand.php`
- **Verification:** PHPStan clean, `DeployVerifyCommandTest` green
- **Committed in:** `41fbca4`

**2. [Rule 3 - Blocking] Development database had nine pending migrations**
- **Found during:** Task 1 verify (`ddev artisan kokpit:deploy:verify` exited 1 with "čekajících migrací: 9")
- **Issue:** the plan's verify step assumes a migrated development database; the local DDEV database was behind (the command correctly caught it, including the settings migrations).
- **Fix:** ran `ddev artisan migrate --force` on the local development database (untracked state, no repository change).
- **Verification:** the command then printed three ok lines and exited 0.

**3. [Rule 1 - Test hardening, after the RED commit] DeployVerifyCommandTest adjusted**
- **Found during:** Task 1 GREEN
- **Issue:** `app(Migrator::class)` is not a container alias (it is `app('migrator')`), and the leak test asserted nothing (all dev connection values are shorter than the length filter).
- **Fix:** use `app('migrator')`; the leak test now matches whole words for the Postgres and Redis host, port, user and password of the test configuration. Included in the GREEN commit.
- **Committed in:** `41fbca4`

**4. [Rule 2 - Missing critical] Extra tests beyond the plan list**
- **Found during:** Tasks 2 and 3
- **Issue:** the plan asks for static checks; the verify script (the actual security gate of the release path) would otherwise be unproven.
- **Fix:** `DeployWorkflowTest` runs the real verify script in a throwaway repository for five cases; `RepositoryFilesTest` also ties the workflow's `vars.ZEROPS_*` names to the CONTRIBUTING checklist.

---

**Total deviations:** 4 (1 bug, 1 blocking, 1 test hardening, 1 added coverage)
**Impact on plan:** no scope change; all fixes inside the plan's files, except the local development database migration.

## Issues Encountered

- DDEV mutagen sync lags behind host edits by a moment. A first mutation run appeared to "survive" because the container still held the old file; reruns waited until `ddev exec grep` showed the mutated line. Noted in `patterns-established`.
- `gitleaks dir .` over the whole working directory (including untracked `vendor/`) reports 4290 findings in dependency files; this is not the CI invocation. The CI-equivalent history scan (`gitleaks git ... --log-opts="--all --diff-merges=first-parent --text --no-textconv"`) and the `.github/workflows` directory scan report no leaks.
- `zizmor` prints "3 suppressed" findings: these are the pre-existing unpinned container images in `hygiene.yml` (visible with `--persona auditor`), unrelated to this plan; `deploy.yml` has none under the auditor persona.
- Running `zcli version` locally (to read the installed version and its help) triggered the binary's own "up to date" check; no login, deploy or API call to a Zerops project was made.

## Authentication Gates

None. No Zerops or GitHub account was used; the zcli binary was fetched anonymously from the public release download URL.

## Known Stubs

None.

## Threat Flags

None. New surface is covered by the plan's threat model: T-03-47 to T-03-49 and T-03-52 (workflow injection, triggers, token, binary) are mitigated and tested; T-03-51 (secrets in manifest) by `ZeropsConfigTest`; T-03-53 by the readiness gate; T-03-56 by the secret-free output test; T-03-50 and T-03-54 by the CONTRIBUTING checklist and its documentation test; T-03-55 accepted.

## Manual follow-up

- **Zerops rehearsal is pending as a non-blocking manual verification** (resolved open question 4). Follow `.planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md` on a throwaway Zerops project. Open items there: failing migration keeps the previous version serving (D-18, the `backstop` truth), phpredis on `php-nginx@8.5`, https URLs behind the balancer, Valkey `maxmemory-policy`, worker and scheduler start commands and heartbeats, the assumed `${db_*}`, `${redis_*}`, `${storage_*}` reference names, `kokpit:storage:check` on Zerops object storage, and the PDF engine's extensions (dom, mbstring, gd with PNG, zlib) plus the effective `memory_limit`.
- **GitHub and Zerops settings** from the CONTRIBUTING "Deploy (maintainer, manual)" checklist must be applied by the maintainer before the first deploy: `production` environment with required reviewer and no admin bypass, `v*` tag rule and tag ruleset, `ZEROPS_TOKEN` as environment secret, the three service id variables, native Git integration disabled for all services, Valkey policy, `APP_KEY` as project secret.
- `03-VALIDATION.md` already lists both items in its Manual-Only table; no change was needed.

## User Setup Required

None for this plan's code. The manual follow-up above is documented, not blocking.

## Next Phase Readiness

- Plan 03-19 (conventions and phase gate) can reference the deploy section and the commands; `ddev composer ci`, `bash scripts/tests/run.sh`, `actionlint` and `zizmor --offline .github/workflows` are green.
- Phase 8 must confirm the PDF extensions in the rehearsal and handle the `LGPL-2.1` licence alias decision (unchanged from 03-01).

## Self-Check: PASSED

- Created files exist: `zerops.yml`, `app/Console/Commands/DeployVerifyCommand.php`, `.github/workflows/deploy.yml`, the four new test files and `03-ZEROPS-REHEARSAL.md` (verified with `[ -f ]`).
- Commits exist on this branch: `78c98f9`, `41fbca4`, `1c0f3a3`, `6033556`, `0cb4245`.
- All acceptance criteria of the three tasks re-run and passed; plan verification re-run: `ddev composer ci` (890 tests, Pint, Larastan level 8, licence check), `bash scripts/tests/run.sh` (PASS 10), `actionlint`, `zizmor --offline .github/workflows`, `scripts/check-sensitive.sh --all`, gitleaks history scan, `ddev exec vendor/bin/pest --group=s3` (9 passed).

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
