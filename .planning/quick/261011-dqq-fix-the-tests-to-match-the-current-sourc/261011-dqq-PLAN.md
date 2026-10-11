---
phase: quick-261011-dqq
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - tests/Feature/Repo/ZeropsConfigTest.php
  - tests/Feature/Repo/DeployWorkflowTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - CONTRIBUTING.md
  - README.md
autonomous: true
requirements: [TEST-ZEROPS, TEST-DEPLOY, TEST-REPOFILES, TEST-SUITE]

estimate:
  tokens: 45000
  raw_tokens: 45000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "The full Pest suite run inside DDEV finishes with 0 failed tests (baseline at planning time: 8 failed, all in tests/Feature/Repo, caused by the owner commits 5534e71 and 64b854b)"
    - "No production or configuration file changes: zerops.yml, .github/workflows/deploy.yml and everything under app/, config/, database/, routes/, resources/, lang/ and bootstrap/ have an empty git diff against the plan's start commit"
    - "ZeropsConfigTest accepts exactly one setup named kokpit and a Node runtime base pinned to a numeric major version (alpine/nodejs@24 today), and its weakening self-check still reports a renamed setup, a second setup, a Bun base, a Node base without a pinned major version, a pnpm build step, an npm init step, a build environment map and node_modules in the build cache"
    - "DeployWorkflowTest requires the push step as it is configured now: --project-id from vars.ZEROPS_PROJECT_ID, --service-id from vars.ZEROPS_SERVICE_ID, the ref-derived version name, --workspace-state clean, no explicit --setup and no --zerops-yaml-path; running the real push script with fictional ids logs exactly one zcli call per allowed ref, and every weakening mutation (including an explicit setup, a manifest path override, a missing project id and a project id from another variable) is still reported"
    - "CONTRIBUTING.md and README.md describe the deploy as configured: setup and service `kokpit`, both workflow variables ZEROPS_PROJECT_ID and ZEROPS_SERVICE_ID, the Zerops service hostname must equal the setup name because the push names no setup, and a Node.js 24 build base without a frontend build; RepositoryFilesTest asserts both variables and the backticked setup name"
    - "A failing test that reveals a genuine production bug is not fixed in production code; it is listed under Deferred items in the SUMMARY with the test name and the reason"
  artifacts:
    - path: "tests/Feature/Repo/ZeropsConfigTest.php"
      provides: "Manifest contract for the single kokpit setup and a pinned Node runtime base, with an extended weakening self-check"
      contains: "kokpit"
    - path: "tests/Feature/Repo/DeployWorkflowTest.php"
      provides: "Push-step contract with the project id variable and implicit setup selection, behavioural push run with fictional ids"
      contains: "ZEROPS_PROJECT_ID"
    - path: "tests/Feature/Repo/RepositoryFilesTest.php"
      provides: "Documentation check for both deploy workflow variables"
      contains: "ZEROPS_PROJECT_ID"
    - path: "CONTRIBUTING.md"
      provides: "Maintainer deploy checklist matching the current workflow and manifest"
      contains: "ZEROPS_PROJECT_ID"
  key_links:
    - from: "tests/Feature/Repo/DeployWorkflowTest.php (deployRunPushScript)"
      to: ".github/workflows/deploy.yml push step run script"
      via: "bash -c of the step script under set -euo pipefail; the process env must carry PROJECT_ID and SERVICE_ID or bash aborts before zcli"
      pattern: "PROJECT_ID"
    - from: "tests/Feature/Repo/ZeropsConfigTest.php (zeropsSetupProblems)"
      to: "zerops.yml setup name and the push without --setup"
      via: "zcli selects the setup whose name equals the service hostname; ZeropsConfigTest pins the one setup name to kokpit"
      pattern: "'kokpit'"
    - from: "tests/Feature/Repo/RepositoryFilesTest.php"
      to: ".github/workflows/deploy.yml and CONTRIBUTING.md"
      via: "preg_match_all of vars.ZEROPS_* in the workflow compared with the names CONTRIBUTING.md documents"
      pattern: "ZEROPS_PROJECT_ID"
---

<objective>
Make the test suite pass again by bringing the stale tests in line with the current source code and configuration, without changing production code or configuration.

At planning time (2026-10-11, HEAD db8f051) the full suite inside DDEV had 2598 passed and 8 failed tests. All 8 are in tests/Feature/Repo and come from two owner commits that changed the deploy configuration without updating its tests:

- 5534e71 renamed the single Zerops setup from backend to kokpit, added the PROJECT_ID env (from vars.ZEROPS_PROJECT_ID) to the push step, and changed the push to `zcli service push --project-id "${PROJECT_ID}" --service-id "${SERVICE_ID}" --version-name "${VERSION}" --workspace-state clean` (no --setup, no --zerops-yaml-path; zcli then picks the setup whose name equals the service hostname and fails in a non-interactive run when none matches)
- 64b854b added `alpine/nodejs@24` to build.base in zerops.yml

The failing tests:

- ZeropsConfigTest (3): 'describes exactly one setup, named backend', 'builds without a frontend toolchain and without a build environment', 'reports every weakening of the manifest'
- DeployWorkflowTest (4): 'logs in with the environment secret and pushes the backend setup once', 'reports every weakening of the workflow', 'pushes the backend setup once with the release tag as the version name', 'pushes a dispatch from main with main and the first 12 characters of the commit as the version name' (the last two exit 1 because the push script runs under `set -u` and the test env has no PROJECT_ID)
- RepositoryFilesTest (1): 'documents the service id variable the deploy workflow reads'

Purpose: these Repo tests are the static security contract of the deploy pipeline (FND-15 hardening, D-18 readiness gate). They must follow the owner's configuration while keeping every guard and every mutation self-check meaningful; weakening a check to make it pass is not acceptable.

Output: three updated test files, the deploy sections of CONTRIBUTING.md and README.md brought in line with the configuration, a green full suite, and a SUMMARY that lists any production bug found as a deferred item.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@zerops.yml
@.github/workflows/deploy.yml
@tests/Feature/Repo/ZeropsConfigTest.php
@tests/Feature/Repo/DeployWorkflowTest.php
@tests/Feature/Repo/RepositoryFilesTest.php

Ground rules for the executor:

- Run every PHP command inside DDEV (`ddev exec ...`); the host has no database.
- Change tests (and the two documentation files in Task 2) only. Do not edit zerops.yml, .github/workflows/deploy.yml, or anything under app/, config/, database/, routes/, resources/, lang/, bootstrap/. If a failure turns out to be a production bug, leave production as it is and record it under Deferred items in the SUMMARY.
- Leave the pre-existing uncommitted files .planning/config.json, .planning/state.json and .planning/milestone.lock untouched and unstaged.
- Fictional data only (CLAUDE.md): placeholder ids such as example-project-id and example-service-id, no real Zerops ids, hostnames or tokens.
- Commit procedure (CLAUDE.md): `git status`, `git diff --staged`, `scripts/check-sensitive.sh` before every commit; lefthook runs the checker and gitleaks; never bypass it (no --no-verify, no LEFTHOOK=0). Commit prefix `test(quick-261011-dqq): ...` for test changes and `docs(quick-261011-dqq): ...` for documentation.

Relevant line anchors (planning-time HEAD):

- ZeropsConfigTest: zeropsSetupProblems around line 113-124, zeropsFrontendProblems docblock and body around line 367-407, the test titles around lines 546 and 582, the mutation list from line 683 ('a second setup', 'the setup renamed', and the frontend mutations around lines 801-825)
- DeployWorkflowTest: deployPushProblems docblock and body around lines 351-416, the test title around line 474, the mutation 'another setup name' around line 636 and 'a service id from another variable' around line 648, deployRunPushScript env around line 941-947, the two behavioural push tests around lines 960-973
- RepositoryFilesTest: 'documents the manual GitHub and Zerops deploy settings in CONTRIBUTING.md' around line 226-248, 'documents the service id variable the deploy workflow reads' around line 377-387
- CONTRIBUTING.md: section '## Deploy (maintainer, manual)' starting around line 244 (the setup/service name appears in the intro paragraph, the GitHub checklist item for ZEROPS_TOKEN and ZEROPS_SERVICE_ID, the Zerops checklist items, and the Rules list including the build rule around line 277)
- README.md: '## Deploy' around lines 52-56 and the Operations sentence around line 70
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Baseline the full suite, then make ZeropsConfigTest follow the current manifest</name>
  <files>tests/Feature/Repo/ZeropsConfigTest.php</files>
  <action>
Step 1, baseline. Run the full suite once with `ddev exec php -d memory_limit=1G vendor/bin/pest` (about 4 minutes) and write down every failing test. The expected baseline is the 8 failures listed in the objective. If any other test fails, rerun that file alone once to rule out a flake, then classify it: a test that no longer matches the source or configuration is fixed in Task 3; a failure that shows a genuine production bug is not fixed and goes to Deferred items in the SUMMARY. Record the baseline counts for the SUMMARY.

Step 2, ZeropsConfigTest (tests only; zerops.yml is the source of truth and stays unchanged):

- zeropsSetupProblems: the one allowed setup name becomes kokpit (the problem message then says "expected exactly kokpit"). Rewrite its docblock: the manifest has exactly one setup, named kokpit; the deploy workflow pushes without --setup, so zcli selects the setup whose name equals the Zerops service hostname exactly and refuses a non-interactive push when none matches, therefore the setup and the service must both be named kokpit.
- Rename the test 'describes exactly one setup, named backend' to 'describes exactly one setup, named kokpit'.
- zeropsFrontendProblems: a build.base entry that is a Node runtime pinned to a numeric major version (anchored pattern: optional prefix ending in a slash, then nodejs@ followed by digits only, end of string, which matches alpine/nodejs@24) is allowed. Every other nodejs, bun or deno base stays a problem, with a message that says the base is a frontend runtime that is not a Node runtime pinned to a major version. Keep the other three checks exactly as they are: no package-manager, Node or Vite command in any command group (the repository still has no package.json or lockfile), no build.envVariables map, no node_modules or lockfile entry in build.cache. Rewrite the docblock accordingly: since commit 64b854b the build image carries a pinned Node runtime next to PHP, but nothing in the build uses it yet, so the build still runs no frontend step and reads no environment; a frontend build is added together with this check when a package.json exists.
- Rename the test 'builds without a frontend toolchain and without a build environment' to 'builds with at most a pinned Node runtime, no frontend step and no build environment'.
- Mutation list in 'reports every weakening of the manifest': the mutation 'a node base in the build' appends alpine/nodejs@24, which is now allowed and would no longer be reported, so replace it with two 'frontend' mutations: 'a bun base in the build' (append alpine/bun@1 to build.base) and 'a node base without a pinned major version' (append alpine/nodejs@latest to build.base). Keep 'a second setup' (worker) and 'the setup renamed' (app) unchanged; both stay reported by 'setups' because neither name is kokpit. Keep every other mutation unchanged.

Do not relax any other check of the file. The mutation self-check proves the edited checks are not vacuous.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Repo/ZeropsConfigTest.php</automated>
  </verify>
  <done>The baseline failure list is recorded; every ZeropsConfigTest test passes, including the weakening self-check with the two new frontend mutations; zerops.yml is unchanged; committed as test(quick-261011-dqq).</done>
</task>

<task type="auto">
  <name>Task 2: Make DeployWorkflowTest and RepositoryFilesTest follow the current push step, and update the deploy documentation</name>
  <files>tests/Feature/Repo/DeployWorkflowTest.php, tests/Feature/Repo/RepositoryFilesTest.php, CONTRIBUTING.md, README.md</files>
  <action>
A. DeployWorkflowTest, deployPushProblems (static contract of the push step):

- Required substrings of the push run: '--project-id "${PROJECT_ID}"', '--service-id "${SERVICE_ID}"', '--version-name "${VERSION}"', '--workspace-state clean', 'refs/tags/v*', 'refs/heads/main', 'GITHUB_SHA'. The former setup and manifest-path needles leave the required list.
- New forbidden flags: the push run must contain neither '--setup' nor '--zerops-yaml-path'; each presence is its own problem message. Reason (put it in the docblock): zcli selects the setup whose name equals the service hostname and reads zerops.yml from the checkout root, which is exactly what ZeropsConfigTest guards; an explicit setup or manifest path would deploy something no test guards.
- Step env: PROJECT_ID must be exactly '${{ vars.ZEROPS_PROJECT_ID }}' (same style as the existing SERVICE_ID check, same message pattern).
- Workflow-wide vars allowlist: ZEROPS_PROJECT_ID and ZEROPS_SERVICE_ID; the message names both allowed variables.
- Rewrite the docblock: one push of the single kokpit setup after the login, exactly one push step, one project-id variable and one service-id variable, a version name derived from the ref, setup selected by the service hostname.
- Rename the test 'logs in with the environment secret and pushes the backend setup once' to 'logs in with the environment secret and pushes the kokpit service once'.

B. DeployWorkflowTest, 'reports every weakening of the workflow': the mutation 'another setup name' replaces a needle that no longer exists and would mutate nothing, so replace it with 'an explicit setup' (in the last deploy step run, replace '--workspace-state clean' with '--workspace-state clean --setup other'). Add three 'push' mutations: 'a manifest path override' (same replacement pattern, appending '--zerops-yaml-path other.yml'), 'a push without a project id' (remove ' --project-id "${PROJECT_ID}"' from the run), 'a project id from another variable' (set the last step env PROJECT_ID to '${{ vars.ZEROPS_OTHER_PROJECT_ID }}'). Keep every other mutation unchanged.

C. DeployWorkflowTest, behavioural push run: in deployRunPushScript add 'PROJECT_ID' => 'example-project-id' to the Process env (the step script runs under set -euo pipefail, so without it bash aborts before zcli with exit 1, which is the current failure). The expected logged calls become 'service push --project-id example-project-id --service-id example-service-id --version-name v1.2.0 --workspace-state clean' for the tag case and the same with 'main-' plus the first 12 characters of the fictional sha for the main case. Rename both tests from 'pushes the backend setup once with ...' / 'pushes a dispatch from main ...' so that no title names the old setup (for example 'pushes once with the release tag as the version name'). The dataset test 'refuses to push from any other ref ...' stays as it is.

D. RepositoryFilesTest:

- 'documents the service id variable the deploy workflow reads' becomes 'documents the project and service id variables the deploy workflow reads': the unique vars.ZEROPS_* names found in deploy.yml, sorted, must be exactly ['ZEROPS_PROJECT_ID', 'ZEROPS_SERVICE_ID']; CONTRIBUTING.md must contain both names; keep the negative check for the old per-setup APP/WORKER/SCHEDULER service-id variables; update the inline comment (one service, one push, a project id and a service id variable).
- 'documents the manual GitHub and Zerops deploy settings in CONTRIBUTING.md': add 'ZEROPS_PROJECT_ID' to the phrase list and replace the bare setup-name phrase with the backticked '`kokpit`', so the project name elsewhere in the file cannot satisfy it.

E. Documentation (not production code; needed because RepositoryFilesTest checks that CONTRIBUTING.md names every variable the workflow reads, and both deploy sections still describe the deploy before 5534e71 and 64b854b):

- CONTRIBUTING.md, section '## Deploy (maintainer, manual)': the setup and service are named `kokpit` everywhere in this section (intro paragraph, GitHub and Zerops checklist items, the multi-container item, the rule 'Each deploy pushes the ... setup once'). The GitHub checklist item for the token becomes: ZEROPS_TOKEN is an environment secret of production, the Zerops project id is the environment variable ZEROPS_PROJECT_ID and the service id of the `kokpit` service is the environment variable ZEROPS_SERVICE_ID, both of production, none of them ever a repository secret or repository variable. Add one Zerops checklist item: the service hostname is exactly `kokpit`, because the workflow pushes without --setup and zcli then selects the setup whose name equals the service name (a non-interactive push fails when none matches). Replace the build rule (the line starting 'The build installs PHP dependencies only') with: the build base carries Node.js 24 (`alpine/nodejs@24`) next to PHP, but the repository has no `package.json` or lockfile, so the build installs PHP dependencies only, runs no Node or pnpm step and has no build environment; the maintainer adds a frontend build together with `ZeropsConfigTest` when one exists.
- README.md: in '## Deploy' the one service is `kokpit`; the build sentence says the build installs the PHP dependencies only and the build image carries Node.js 24, but there is no frontend build yet; in the Operations sentence the crontab runs on every container of the `kokpit` service.
- Keep the existing wording and every phrase that other tests require (RepositoryFilesTest phrase lists, scripts/tests/test-docs.sh phrases); change only what describes the setup name, the variables and the build image.

Do NOT edit .github/workflows/deploy.yml or zerops.yml. Their comments and the step name still call the setup backend ('Push the backend setup', 'A single push of the `backend` setup'); record that under Deferred items in the SUMMARY for the owner instead of changing configuration.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Repo</automated>
  </verify>
  <done>Every test under tests/Feature/Repo passes, including both weakening self-checks with the new push mutations; `ddev exec grep -c ZEROPS_PROJECT_ID CONTRIBUTING.md` is at least 1; deploy.yml and zerops.yml are unchanged; committed as test(quick-261011-dqq) for the tests and docs(quick-261011-dqq) for CONTRIBUTING.md and README.md.</done>
</task>

<task type="auto">
  <name>Task 3: Prove the full suite green, the style clean and production untouched</name>
  <files>tests/Feature/Repo/ZeropsConfigTest.php, tests/Feature/Repo/DeployWorkflowTest.php, tests/Feature/Repo/RepositoryFilesTest.php</files>
  <action>
Run the full suite again inside DDEV. If the Task 1 baseline found extra failures classified as stale tests, fix them here first, by the same rule as Tasks 1 and 2 (the test follows the current source and configuration, every guard and mutation self-check stays meaningful); add each fixed file to the SUMMARY. A failure that shows a genuine production bug is never fixed in production code and its test is never weakened to hide it: list it under Deferred items with the test name, the failure message and why it is a production bug, not a stale test, and name it in the return message (the full-suite verify may then fail on exactly that test and no other).

Run Pint in test mode on tests/Feature/Repo and apply its fixes if it reports any (style only, no behaviour change). Run the documentation shell check scripts/tests/test-docs.sh. Confirm with git that no production or configuration path changed since the plan's start commit (zerops.yml, .github, app, config, database, routes, resources, lang, bootstrap).

Write the SUMMARY: baseline (8 failed, list), final suite counts, per-file change notes, the decisions taken (setup name kokpit pinned in ZeropsConfigTest; a Node base pinned to a major version allowed, Bun, Deno and unpinned Node refused; push contract with project id and without explicit setup or manifest path; docs updated because a test checks them), and Deferred items: at least the stale backend wording in the comments and step name of .github/workflows/deploy.yml, plus any production bug found.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest && ddev exec vendor/bin/pint --test tests/Feature/Repo && ddev exec bash scripts/tests/test-docs.sh && ddev exec 'test -z "$(git diff --name-only db8f051 -- zerops.yml .github app config database routes resources lang bootstrap)"'</automated>
  </verify>
  <done>The full Pest suite inside DDEV reports 0 failed; Pint reports no style issue in tests/Feature/Repo; test-docs.sh passes; the git diff of production and configuration paths against db8f051 is empty; the SUMMARY records baseline, result, decisions and deferred items.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| GitHub Actions deploy job -> Zerops | The push step carries the access token and the project and service ids; the Repo tests are the static contract that keeps this step hardened |
| Repository -> public | Public AGPL repository; tests and docs must hold fictional values only |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-dqq-01 | Elevation of Privilege | tests/Feature/Repo/DeployWorkflowTest.php | high | mitigate | Every existing check of the deploy contract stays (triggers, permissions, injection, secrets, pinned actions, environment, verify, CI gate, zcli digest, single push); the push check gains the project-id env and vars allowlist rules and forbids an explicit setup or manifest path; four new mutations prove these checks are not vacuous |
| T-dqq-02 | Tampering | tests/Feature/Repo/ZeropsConfigTest.php | medium | mitigate | Only the setup name and the Node base rule change; the Node base must be pinned to a numeric major version, Bun, Deno and unpinned Node stay refused, and the frontend step, build environment and cache checks are unchanged; new mutations cover the relaxed rule |
| T-dqq-03 | Information Disclosure | tests and CONTRIBUTING.md / README.md | medium | mitigate | Only placeholder ids (example-project-id, example-service-id); scripts/check-sensitive.sh and gitleaks run in the lefthook pre-commit hook on every commit, never bypassed |
| T-dqq-04 | Tampering | zerops.yml, .github/workflows/deploy.yml, app/ and other production paths | high | mitigate | The executor must not edit them; Task 3 verifies an empty git diff of these paths against db8f051; stale wording in deploy.yml is deferred to the owner, not edited |
| T-dqq-SC | Tampering | npm/pip/cargo installs | low | accept | No package is installed or updated by this plan |
</threat_model>

<verification>
- `ddev exec php -d memory_limit=1G vendor/bin/pest` reports 0 failed (baseline 8 failed in tests/Feature/Repo)
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Repo` passes, including 'reports every weakening of the manifest' and 'reports every weakening of the workflow'
- `ddev exec vendor/bin/pint --test tests/Feature/Repo` passes
- `ddev exec bash scripts/tests/test-docs.sh` passes
- `ddev exec 'git diff --name-only db8f051 -- zerops.yml .github app config database routes resources lang bootstrap'` prints nothing
- lefthook (sensitive-content checker and gitleaks) ran on every commit without a bypass
</verification>

<success_criteria>
- All 8 baseline failures fixed by changing tests (and the two deploy documentation sections the tests check), not production code or configuration
- Every guard of the deploy and manifest contracts still exists, and every weakening mutation is reported
- Full suite green inside DDEV
- SUMMARY lists any production bug and the stale deploy.yml wording as deferred items
</success_criteria>

<output>
Create `.planning/quick/261011-dqq-fix-the-tests-to-match-the-current-sourc/261011-dqq-SUMMARY.md` when done
</output>
