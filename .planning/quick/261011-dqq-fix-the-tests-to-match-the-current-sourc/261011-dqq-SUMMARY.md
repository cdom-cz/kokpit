---
phase: quick-261011-dqq
plan: 01
subsystem: testing
tags: [pest, zerops, deploy-workflow, repo-tests]
requires: []
provides:
  - Repo tests that follow the kokpit setup name, the project id variable and the pinned Node build base
affects: [tests/Feature/Repo, CONTRIBUTING.md, README.md]
key-files:
  modified:
    - tests/Feature/Repo/ZeropsConfigTest.php
    - tests/Feature/Repo/DeployWorkflowTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - README.md
decisions:
  - "Setup name kokpit is pinned in ZeropsConfigTest (the push names no setup; zcli picks the setup equal to the service hostname)"
  - "A Node build base pinned to a numeric major version is allowed; Bun, Deno and unpinned Node stay refused"
  - "The push contract requires --project-id from vars.ZEROPS_PROJECT_ID and forbids --setup and --zerops-yaml-path"
  - "CONTRIBUTING.md and README.md updated because RepositoryFilesTest checks them against the workflow"
status: complete
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: db8f051
plan_head_after: 3a1f008c01f6be426988a6b9203a0cc92dbbd471
completed: 2026-10-11
---

# Phase quick-261011-dqq Plan 01: Fix the tests to match the current source Summary

The 8 stale deploy tests in tests/Feature/Repo now follow the owner's commits 5534e71 and 64b854b (setup `kokpit`, project id variable, no explicit setup, Node 24 build base), with every guard kept and four new push mutations plus two new frontend mutations added. No production or configuration file changed.

## Baseline and result

- Baseline (HEAD db8f051): 2598 passed, 8 failed, all in tests/Feature/Repo:
  - ZeropsConfigTest: 'describes exactly one setup, named backend', 'builds without a frontend toolchain and without a build environment', 'reports every weakening of the manifest'
  - DeployWorkflowTest: 'logs in with the environment secret and pushes the backend setup once', 'reports every weakening of the workflow', 'pushes the backend setup once with the release tag as the version name', 'pushes a dispatch from main with main and the first 12 characters of the commit as the version name'
  - RepositoryFilesTest: 'documents the service id variable the deploy workflow reads'
- Final: full suite in DDEV 2606 passed, 0 failed (18426 assertions). Pint on tests/Feature/Repo clean, scripts/tests/test-docs.sh 45 passed 0 failed, empty git diff of zerops.yml, .github, app, config, database, routes, resources, lang, bootstrap against db8f051.

## Changes per file

- ZeropsConfigTest: the only allowed setup is `kokpit`; `zeropsFrontendProblems` allows a Node base matching `^(?:.*/)?nodejs@\d+$` and still refuses every other nodejs, bun or deno base; tests renamed; the mutation 'a node base in the build' replaced by 'a bun base in the build' (alpine/bun@1) and 'a node base without a pinned major version' (alpine/nodejs@latest). Pnpm, npm, build environment and node_modules cache checks unchanged.
- DeployWorkflowTest: push step must contain `--project-id "${PROJECT_ID}"`, `--service-id`, `--version-name`, `--workspace-state clean` and must not contain `--setup` or `--zerops-yaml-path`; PROJECT_ID env must be `${{ vars.ZEROPS_PROJECT_ID }}`; vars allowlist is ZEROPS_PROJECT_ID and ZEROPS_SERVICE_ID. Mutation 'another setup name' replaced by 'an explicit setup', plus 'a manifest path override', 'a push without a project id', 'a project id from another variable'. Behavioural run gets `PROJECT_ID=example-project-id` and expects `service push --project-id example-project-id --service-id example-service-id --version-name ... --workspace-state clean`. Titles no longer name `backend`.
- RepositoryFilesTest: both workflow variables are asserted (sorted unique list equals ZEROPS_PROJECT_ID, ZEROPS_SERVICE_ID) and documented; the phrase list requires ZEROPS_PROJECT_ID and the backticked `kokpit`.
- CONTRIBUTING.md and README.md: setup and service named `kokpit`, ZEROPS_PROJECT_ID documented, new checklist item that the service hostname must be exactly `kokpit`, build rule now states the Node.js 24 base without a frontend build.

## Commits

- 81ad3e4 test(quick-261011-dqq): ZeropsConfigTest
- c00e0c9 test(quick-261011-dqq): DeployWorkflowTest and RepositoryFilesTest
- 3a1f008 docs(quick-261011-dqq): CONTRIBUTING.md and README.md

## Deviations from Plan

None - plan executed exactly as written. Task 3 found no extra failures beyond the 8 baseline ones, so no further test fix was needed.

## Deferred items

- No production bug was found.
- .github/workflows/deploy.yml still calls the setup `backend` in its step name ('Push the backend setup') and in a comment ('A single push of the `backend` setup'); not edited (configuration), for the owner to reword to `kokpit`. zerops.yml itself was not checked for similar comments beyond the tests passing; owner may grep for `backend` there too.

## Known Stubs

None.

## Threat Flags

None.

## Self-Check: PASSED

Commits 81ad3e4, c00e0c9, 3a1f008 exist on the branch; the three test files, CONTRIBUTING.md and README.md are modified as described.
