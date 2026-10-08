---
phase: quick-261008-bp0
plan: 01
subsystem: deploy
tags: [zerops, github-actions, deploy, pest, contract-tests]
requires:
  - owner commit 8c72141 (single-setup zerops.yml)
provides:
  - ZeropsConfigTest guarding the single-setup backend manifest with an in-memory mutation self-check
  - deploy.yml with one push of the backend setup and a ref-derived version name
  - CONTRIBUTING, README Deploy and rehearsal checklist for the single-service layout
affects: [deploy, FND-15]
tech-stack:
  added: []
  patterns:
    - problem-list functions plus mutation self-check (zerops prefix)
    - behavioural run of the push script with a fake zcli
key-files:
  created: []
  modified:
    - tests/Feature/Repo/ZeropsConfigTest.php
    - tests/Feature/Repo/DeployWorkflowTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .github/workflows/deploy.yml
    - CONTRIBUTING.md
    - README.md
    - .planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md
key-decisions:
  - "ZEROPS_SERVICE_ID stays an environment variable of the production environment (not a repository variable); owner to confirm"
  - "ZeropsConfigTest accepts both ${appVersionId} and ${ZEROPS_appVersionId} for execOnce until the rehearsal settles which one Zerops expands"
requirements: [FND-15]
requirements-completed: [FND-15]
status: complete
duration: 25 min
completed: 2026-10-08
commits: 3
plan_head_before: 2f975bee6f843f07b768c06bf913bc88832e7827
plan_head_after: 66bb759e37a3fae93819dfd7f388f006c850545c
actuals:
  tokens: 17000
  tasks: 3
  commits: 3
---

# Quick Task 261008-bp0: Adapt Zerops tests and deploy workflow Summary

The deploy contract tests, `deploy.yml` and the deploy documentation now match the owner's single-setup `zerops.yml` (one setup `backend`): a release or a dispatch from main pushes the backend setup once, with a version name derived from the ref, and every earlier workflow hardening rule is kept and still tested.

## What was done

- **Task 1 (efe2c3c)**: `ZeropsConfigTest` rewritten as nine pure problem-list functions (setups, bases, build, migration, caches, environment, raw, site, crontab) with a self-check that applies 24 in-memory mutations and requires each to be reported. The owner-owned manifest was never edited, not even temporarily. Secret-shaped mutation values are assembled at runtime from fragments.
- **Task 2 (477bb4e)**: `deploy.yml` pushes once (`--setup backend --version-name "${VERSION}"`, service id from `vars.ZEROPS_SERVICE_ID` through step env). The version is `GITHUB_REF` minus `refs/tags/` for `refs/tags/v*`, or `main-` plus the first 12 characters of `GITHUB_SHA` for `refs/heads/main`; any other ref, an empty version and any character outside `[0-9A-Za-z._-]` are refused with an `::error::` line. The concurrency block got its explanatory comment. `DeployWorkflowTest` got a rewritten `deployPushProblems` (exactly one push, after the login, required flags, exact env, only one `vars.` name), five new push mutations and a behavioural run of the real push script with a fake `zcli` (two accepted cases, nine refused cases that never call `zcli`). `RepositoryFilesTest` ties the workflow's single variable to CONTRIBUTING and rejects the three obsolete variable names.
- **Task 3 (66bb759)**: CONTRIBUTING "Deploy (maintainer, manual)" describes one service, `ZEROPS_SERVICE_ID`, the Zerops-UI-only variable names grouped with the guard values, and the new rules; README `## Deploy` describes the `backend` service; the rehearsal checklist was revised in place with a new "Known follow-ups" section; `RepositoryFilesTest` gained the phrases `ZEROPS_SERVICE_ID`, `backend`, `Zerops UI` and a test that every named production variable is in backticks in CONTRIBUTING and is a key of `.env.example`.

## Baseline and final state

- Baseline before any change (`ZeropsConfigTest`, `DeployWorkflowTest`, `RepositoryFilesTest`): 14 failed, 53 passed. All 14 failures were `ZeropsConfigTest` cases (describes exactly the three setups; builds every setup identically; runs every setup on php-nginx 8.5; migrates exactly once in the app setup; builds the framework caches at container start; deploys every runtime directory; gates the app readiness; serves the public directory from the app setup only; starts the queue worker and the scheduler in their own setups; gives every setup the same environment; sets the production switches; holds only references for secret-like variables; contains no application key; documents every environment variable in .env.example).
- Task 2 RED run (before the workflow change): 14 failed for the planned reasons (three pushes, three variables, the new push script cases).
- Final: `ddev composer ci` green (992 tests, 4184 assertions; Pint, PHPStan and licence check clean), `bash scripts/tests/run.sh` PASS 10 FAIL 0, `actionlint` clean, `zizmor --offline .github/workflows` no findings, `scripts/check-sensitive.sh --all` clean. `git diff 8c72141 -- zerops.yml site.conf.tmpl supervisor-horizon.ini .deployignore` is empty. The three commits touch only this plan's seven files.

## Deviations from Plan

### For the owner to confirm

**1. [Plan-stated choice] ZEROPS_SERVICE_ID is an environment variable of `production`, not a repository variable**
- The brief called it a repository variable; the plan kept the existing hardening rule (environment variable of `production`, never a repository variable), since `vars.ZEROPS_SERVICE_ID` resolves identically either way and the environment scope means only the approved deploy job can read it. CONTRIBUTING says so. Owner to confirm; switching to a repository variable needs no workflow change, only the CONTRIBUTING sentence and the rehearsal prerequisite.

### Auto-fixed Issues

**2. [Rule 3 - Blocking] Plan verify grep on the push line fails under BSD grep**
- **Found during:** Task 2 verify
- **Issue:** `grep -q -- '--setup backend --version-name "${VERSION}"'` does not match under the macOS BSD grep (it matches with `grep -F`); the line is present.
- **Fix:** none needed in the file; verified with `grep -F`. The concurrency comment was reworded so that `artisan migrate --force` sits on one line, because the plan's second verify grep expects it.
- **Commit:** 477bb4e

No other deviations; the plan was executed as written. README.md was committed with a pathspec commit: the working tree held no parallel Horizon edits (261008-bec had already landed), and `git diff HEAD -- README.md` showed a single hunk inside `## Deploy`.

## Follow-ups not fixed here

- **README Operations sentence**: the `## Operations` section (owned by 261008-bec) still has a sentence naming separate Zerops worker and scheduler services. It is stale against the single `backend` service; fix it in a small follow-up.
- **ProductionConfigGuard APP_URL message** (`app/Support/ProductionConfigGuard.php`) still mentions the worker and the scheduler as processes. Accurate as process names, left unchanged.
- Open items recorded in the rehearsal checklist ("Known follow-ups"), all owner decisions because `zerops.yml` is owner-owned: no `package.json` or `pnpm-lock.yaml` so `pnpm install` and `pnpm run build` fail; `schedule:run` with `allContainers: true` needs `->onOneServer()`; no readiness gate any more (`kokpit:deploy:verify` is manual); which `execOnce` variable spelling Zerops expands; `--ignore-platform-reqs` hides missing PHP extensions until runtime. Horizon install itself was done by 261008-bec.

## Test failures attributed to the parallel task

None. No Pest collision with the other session occurred (checked with `pgrep` before each run).

## Known Stubs

None.

## Threat Flags

None. The only new executable surface is the push script, covered by T-261008-bp0-02 (runner-provided `GITHUB_REF`/`GITHUB_SHA` only, no `${{ }}`, charset refusal, behavioural tests).

## Self-Check: PASSED

- All seven modified files exist and are in the three commits (efe2c3c, 477bb4e, 66bb759 all ancestors of HEAD).
- `git rev-list --count 2f975be..HEAD` = 3, matching `commits: 3`.
