---
phase: 02-platform-foundation
plan: 13
subsystem: docs
tags: [readme, security-policy, contributing, agpl, licence-consistency, phase-gate]

requires:
  - phase: 02-platform-foundation
    provides: licence decision AGPL-3.0-only (02-01); .env.example, DDEV project (02-02); kokpit:install and kokpit:admin:reset-2fa (02-09); CI jobs and composer scripts (02-12); convention names from the 02-01 contract table
provides:
  - README.md that installs the app from README and .env.example alone, with TOTP recovery, Czech UI note, gates and the fictional-data rule
  - SECURITY.md with GitHub private vulnerability reporting, scope, safe harbour and the leak runbook, no e-mail address
  - CONTRIBUTING.md Development section (DDEV, composer scripts, nine conventions, add-a-model checklist) and a CI section listing every job
  - tests/Feature/Repo/RepositoryFilesTest.php keeping LICENSE, composer.json, README, SECURITY.md and CONTRIBUTING.md in agreement with each other and with the code
  - eleven new required CONTRIBUTING phrases in scripts/tests/test-docs.sh, each mutation-proven
  - green phase gate (composer ci, bash scripts/tests/run.sh, check-sensitive --all, lefthook check-install)
affects: [every later phase (README clean-clone check must be repeated when a service is added), phase 03 and later models (add-a-model checklist), verifier of phase 02]

actuals:
  tokens: 6200
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Docs are tested against the code: README artisan commands must be registered commands, documented composer scripts must exist in composer.json, CONTRIBUTING must mention every Hygiene job"
    - "The licence id is a named constant in the test (the owner's decision), not an accepted set"
    - "E-mail checks build their regex and fixtures at runtime from fragments"

key-files:
  created:
    - README.md
    - SECURITY.md
    - tests/Feature/Repo/RepositoryFilesTest.php
  modified:
    - CONTRIBUTING.md
    - scripts/tests/test-docs.sh

key-decisions:
  - "README and RepositoryFilesTest assert exactly AGPL-3.0-only (02-01 decision) and the test fails if the README carries AGPL-3.0-or-later or an any-later-version grant"
  - "Documentation says composer check-licenses, never composer licenses (02-12 rename); a test fails if a documented composer script does not exist"
  - "The Czech collation convention is documented as a rule for the first sorted text column, because no such column exists yet and no mechanism was invented here"
  - "The permission package Gate::before limit is recorded in the CONTRIBUTING isolation convention instead of being fixed"

patterns-established:
  - "A documented command or script that no longer exists fails the build (RepositoryFilesTest)"
  - "Phase-gate manual checks are written into the plan SUMMARY for end-of-phase UAT"

requirements-completed: [FND-14, FND-01, FND-20]

coverage:
  - id: D1
    description: "README.md lists the install sequence in order, names AGPL-3.0-only, explains TOTP recovery (kokpit:admin:reset-2fa, APP_PREVIOUS_KEYS), the Czech UI, the gates and the fictional-data rule"
    requirement: "FND-14"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it lists the install commands of the README in the documented order"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it only documents artisan commands that exist"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it explains TOTP recovery, the install-only password and the gates in the README"
        status: pass
    human_judgment: false
  - id: D2
    description: "SECURITY.md routes reports through private vulnerability reporting, carries scope, safe harbour and the leak runbook, and holds no e-mail address"
    requirement: "FND-14"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it routes vulnerability reports privately and carries the leak runbook in SECURITY.md"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it keeps real addresses out of README, SECURITY.md and CONTRIBUTING.md"
        status: pass
    human_judgment: false
  - id: D3
    description: "LICENSE is the GNU AGPL v3 text, composer.json declares AGPL-3.0-only and the README names exactly that id"
    requirement: "FND-14"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it declares the chosen AGPL licence id in composer.json and names exactly that id in the README"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it ships the GNU AGPL v3 text as LICENSE"
        status: pass
    human_judgment: false
  - id: D4
    description: "CONTRIBUTING.md Development section, add-a-model checklist and CI job list; every required phrase present and mutation-proven"
    requirement: "FND-14"
    verification:
      - kind: integration
        ref: "bash scripts/tests/test-docs.sh (45 assertions, 0 failed)"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it lists every job of the Hygiene workflow in the CI section of CONTRIBUTING.md"
        status: pass
    human_judgment: false
  - id: D5
    description: "Phase gate green on the final tree: ddev composer ci, bash scripts/tests/run.sh, scripts/check-sensitive.sh --all, lefthook check-install"
    requirement: "FND-20"
    verification:
      - kind: integration
        ref: "ddev composer ci (411 tests, Pint 147 files, PHPStan no errors, 200 packages licensed)"
        status: pass
      - kind: integration
        ref: "bash scripts/tests/run.sh (PASS 10 FAIL 0 SKIP 0)"
        status: pass
    human_judgment: false
  - id: D6
    description: "A truly empty clone starts with ddev start, follows only README.md and .env.example, and reaches the panel login page; daemons RUNNING, bucket kokpit-dev exists"
    requirement: "FND-01"
    verification: []
    human_judgment: true
    rationale: "Needs a real Docker and DDEV host and an empty clone (assumption A2); cannot run in the executor's own checkout. The boot script in CI and 02-12's fresh-clone probe cover the same steps without DDEV start-up."
  - id: D7
    description: "All visible text of login, TOTP set-up with recovery codes, TOTP challenge, dashboard and profile is Czech with Europe/Prague dates; the Admin cannot reach the dashboard before TOTP; a Partner is not forced into TOTP and sees no data"
    requirement: "FND-20"
    verification: []
    human_judgment: true
    rationale: "Visual check of translated UI across Filament pages (assumption A10); no automated test judges whether every rendered label is Czech"
  - id: D8
    description: "CI green after the owner pushes: jobs scan, workflow-lint, tests, static-analysis, dependencies and CI Passed on ubuntu-24.04 with PHP 8.5 (assumption A6)"
    requirement: "FND-20"
    verification: []
    human_judgment: true
    rationale: "Requires a push to GitHub, which is the owner's action"

duration: 7min
completed: 2026-10-07
status: complete
commits: 3
plan_head_before: a144bef840345d88c627a4e783f79f80a51ec785
plan_head_after: 586d859
---

# Phase 2 Plan 13: Repository files and phase gate Summary

**README that installs Kokpit from README and .env.example alone, SECURITY.md with private reporting and the leak runbook, CONTRIBUTING extended with the conventions this phase enforces, and a test that keeps the AGPL-3.0-only id, the install commands, the documented scripts and the CI job list in agreement with the code.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-10-07T20:12:00Z
- **Completed:** 2026-10-07T20:19:00Z
- **Tasks:** 2 (3 commits)
- **Files modified:** 5 (3 created, 2 modified)

## Accomplishments

- `README.md`: purpose and stack, licence `AGPL-3.0-only`, requirements (Docker, DDEV 1.25+; contributors also lefthook and gitleaks), the exact eight-step install sequence, first sign-in and TOTP, recovery (`kokpit:admin:reset-2fa`, `APP_PREVIOUS_KEYS`, the install-only `KOKPIT_ADMIN_PASSWORD`), the Czech UI and Prague time note, the gates, the fictional-data rule and links to CONTRIBUTING and SECURITY.
- `SECURITY.md`: supported versions, "Report a vulnerability" through GitHub private reporting, response times, scope, safe harbour, the leak runbook (rotate first, then history, then notify) and "never attach real data". No e-mail address.
- `CONTRIBUTING.md`: new `## Development` section (DDEV commands, composer scripts table, nine conventions, eight-step add-a-model checklist), the `## CI` section now a job list that matches the workflow, the leak section points at SECURITY.md, and the intro no longer announces the guide as unfinished.
- `RepositoryFilesTest` (12 tests): LICENSE text, composer id equals the owner's decision, README names it and grants no later-version option, install order, every documented artisan command is registered, every documented composer script exists, required phrases, no address outside example.com (with a self-check of the detector), Development section, every Hygiene job mentioned.
- `scripts/tests/test-docs.sh`: `ddev start`, `composer ci`, `uuidv7()`, `timestamptz`, `MorphMap`, `SequenceAllocator`, `PartnerIsolated`, `NotPartnerScoped`, `AccessRule`, `CanaryRegistry`, `lang/cs` required; the existing mutation loop proves each (45 assertions, 0 failed).

## Task Commits

1. **Task 1 (tracer): README, SECURITY.md and the licence-consistency test** - `abd14fb` (feat)
2. **Task 2: CONTRIBUTING Development section, CI job list, required phrases** - `14db701` (docs)
3. **Task 2 follow-up: record the Gate::before limit in the isolation convention** - `586d859` (docs)

**Plan metadata:** not committed on purpose (`commit_docs` is false and `.planning/` is untracked).

Tracer gate: the tracer's automated `<verify>` (Pest `tests/Feature/Repo`, `check-sensitive.sh --all`) passed end to end before expansion. Its clean-clone human check is deferred to end-of-phase UAT as instructed (D6 below).

## Phase gate results (final tree, HEAD `586d859`)

| Command | Result |
|---|---|
| `ddev composer ci` | exit 0: Pest 411 passed (2210 assertions), Pint PASS 147 files, PHPStan "No errors", `check-licenses: 200 packages, every one has an allowed licence` |
| `bash scripts/tests/run.sh` | exit 0: `PASS 10 FAIL 0 SKIP 0` |
| `scripts/check-sensitive.sh --all` | exit 0: clean (generic patterns only, `KOKPIT_DENYLIST` not set in this session) |
| `lefthook check-install` | exit 0 |
| `bash scripts/tests/test-docs.sh` | 45 assertions passed, 0 failed |
| `ddev exec vendor/bin/pest tests/Feature/Repo` | 34 passed (197 assertions) |
| Pre-commit hook on all three commits | `sensitive-content` and `gitleaks` both passed, no bypass |

Mutation checks run: renaming one README install command fails both the order test and the registered-command test; each new phrase in `test-docs.sh` is removed and reported by the existing loop.

## Manual checks for end-of-phase UAT (cannot be automated)

1. **Clean-clone start (FND-01, FND-20, assumption A2).** On a machine with Docker and DDEV 1.25+, clone the pushed branch into an empty directory (for example under `/Users/example/`) and follow only README.md and `.env.example`: `ddev start`, `cp .env.example .env`, `ddev composer install`, `ddev artisan key:generate`, `ddev artisan migrate`, `ddev artisan kokpit:install`. Then `ddev describe` and `ddev exec supervisorctl status`. Expected: every step works without extra knowledge; web (PHP 8.5), db (PostgreSQL 18), redis and rustfs are OK; `queue-worker` and `scheduler` are RUNNING and did not restart-loop before `composer install`; the RustFS bucket `kokpit-dev` exists; the printed panel URL opens the login page.
2. **Czech walk-through (FND-11, FND-17, FND-20, assumption A10).** With `KOKPIT_REQUIRE_ADMIN_2FA=true`: login page, TOTP set-up page with recovery codes, TOTP challenge on the next sign-in, dashboard, profile page; then a Partner test account. Expected: all visible text Czech (no English labels, no raw translation keys), dates as `j. n. Y H:i` in Europe/Prague, the Admin cannot reach the dashboard before TOTP is set up, the Partner reaches the dashboard without being forced into TOTP and sees no data.
3. **CI after the push (FND-13, FND-20, assumption A6).** Push the branch through a pull request and open the Hygiene run. Expected: `scan`, `workflow-lint`, `tests`, `static-analysis`, `dependencies` succeed on ubuntu-24.04 with PHP 8.5 (setup-php installs 8.5, the `postgres:18` and `redis:7` services start, Pest passes with the runner's `127.0.0.1` hosts, including the concurrency test's child processes) and `CI Passed` is green. Also confirm GitHub private vulnerability reporting is enabled for the repository, because SECURITY.md names it as the only reporting channel.

## Files Created/Modified

- `README.md` - install, TOTP and recovery, Czech UI, gates, licence, fictional-data rule
- `SECURITY.md` - private reporting, scope, safe harbour, leak runbook
- `CONTRIBUTING.md` - Development section, add-a-model checklist, CI job list, SECURITY.md pointer, Gate::before limit
- `scripts/tests/test-docs.sh` - eleven new required CONTRIBUTING phrases
- `tests/Feature/Repo/RepositoryFilesTest.php` - licence, README, SECURITY.md and CONTRIBUTING agreement tests

## Decisions Made

See `key-decisions`. In short: the test names `AGPL-3.0-only` instead of accepting either AGPL id (the owner decided), documentation uses `check-licenses`, and known open items are recorded rather than fixed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug in plan text] `composer licenses` replaced by `composer check-licenses`**
- **Found during:** Task 2 (Development section)
- **Issue:** The plan lists the script as `composer licenses`; 02-12 renamed it because Composer skips a script that shadows its native `licenses` command.
- **Fix:** CONTRIBUTING and README document `composer check-licenses`; `RepositoryFilesTest` fails when a documented composer script is missing from `composer.json`.
- **Files modified:** CONTRIBUTING.md, README.md, tests/Feature/Repo/RepositoryFilesTest.php
- **Committed in:** `abd14fb`, `14db701`

**2. [Rule 1 - Bug] Pint style issue in the new test file**
- **Found during:** Task 2 phase gate (`ddev composer ci`, lint step exit 1)
- **Issue:** `fully_qualified_strict_types` wanted a `use` import for `Symfony\Component\Yaml\Yaml` in `RepositoryFilesTest`.
- **Fix:** ran Pint on the file; `ddev composer ci` then passed.
- **Files modified:** tests/Feature/Repo/RepositoryFilesTest.php
- **Committed in:** `14db701`

**3. [Rule 2 - Missing critical] Gate::before limit documented next to the isolation convention**
- **Found during:** Task 2 (writing the isolation convention)
- **Issue:** The permission package registers `Gate::before` ahead of `KokpitPolicy`; a convention that says "policies grant Partners explicitly" is incomplete without that known limit. The orchestrator asked that open items be recorded, not fixed.
- **Fix:** one sentence in the CONTRIBUTING isolation bullet; no code change.
- **Files modified:** CONTRIBUTING.md
- **Committed in:** `586d859`

**4. Plan wording adjustments (not defects)**
- The `## CI` section already listed the new jobs after 02-12 (deviation 4 there), so this plan only restructured it into a job list and added a test that every Hygiene job is named.
- The plan's must-have "Czech panel from `.env.example` alone" needed no change: `.env.example` already carries `APP_LOCALE=cs` (02-02). The README says so and tells a developer with an older `.env` to carry it too.
- The Task 1 version of `RepositoryFilesTest` omitted the CONTRIBUTING assertions; they were added in Task 2 as the plan assigns them.
- The Czech-collation convention is written as a rule for the first sorted text column. No Czech collation, `lang/cs` ordering test or collated column exists yet, and this plan does not invent one.

---

**Total deviations:** 3 auto-fixed (2 Rule 1, 1 Rule 2) plus plan wording adjustments
**Impact on plan:** none on scope; all documentation and tests.

## Open items recorded, not fixed

- **No Admin password recovery path (UI-SPEC A-1).** README states it under Recovery. The only recovery tools are `kokpit:admin:reset-2fa` (TOTP only) and shell access.
- **`Gate::before` of the permission package runs before `KokpitPolicy`.** Recorded in the CONTRIBUTING isolation convention. No permissions exist yet, so there is no current exposure; the first plan that introduces permissions must close it.
- **Indigo primary colour from the UI-SPEC (A-3) is not applied.** Not documented in README or CONTRIBUTING; recorded here only. The panel still uses Filament's Amber primary colour from the skeleton.
- **README steps are checked for presence and agreement, not behaviour (FND-14 edge).** The clean-clone check (D6) and the CI boot job are the behavioural proof; every later phase that adds a service must repeat the clean-clone check.
- **GitHub private vulnerability reporting must be switched on by the owner**; SECURITY.md routes to it and has no fallback address by design. Add it to the maintainer's settings checklist when convenient (the checklist in CONTRIBUTING was left unchanged).

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None.

## Threat Flags

None. The plan's threat model is mitigated as written: T-02-50 (no address anywhere, tests reject non-example addresses, scanner and gitleaks passed on every commit), T-02-51 (SECURITY.md routes privately and says never to attach real data), T-02-52 (README documents `APP_PREVIOUS_KEYS` and `kokpit:admin:reset-2fa`, both implemented and tested in 02-09).

## User Setup Required

None - no external service configuration required. The owner action listed above (enable private vulnerability reporting, push for the CI run) is part of the UAT checks.

## Next Phase Readiness

- Phase 2 has all thirteen plans executed; ready for phase verification and the end-of-phase UAT above.
- Later phases: add one line to `MorphMap` and, for isolated models, `CanaryRegistry` per model (the checklist is in CONTRIBUTING), and repeat the clean-clone check whenever a DDEV service is added.

## Self-Check: PASSED

- Files exist: README.md, SECURITY.md, tests/Feature/Repo/RepositoryFilesTest.php, CONTRIBUTING.md, scripts/tests/test-docs.sh.
- Commits `abd14fb`, `14db701`, `586d859` are ancestors of HEAD.
- All acceptance criteria of both tasks re-run and passing; the phase-gate commands above exit 0.

---
*Phase: 02-platform-foundation*
*Completed: 2026-10-07*
