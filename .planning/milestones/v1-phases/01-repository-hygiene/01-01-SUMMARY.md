---
phase: 01-repository-hygiene
plan: 01
subsystem: infra
tags: [bash, awk, lefthook, github-actions, secret-scanning]

requires: []
provides:
  - scripts/check-sensitive.sh scanner driver (staged and --all modes, key-prefix rule family, masked output, D-05 exact-path exclusions, exit codes 0/1/2)
  - scripts/lib/diff2tsv.awk and scripts/lib/scan.awk (mawk-safe scanner core; later plans add rules)
  - lefthook.yml with the sensitive-content pre-commit job and assert_lefthook_installed
  - scripts/install-hooks.sh fail-closed installer, hook installed in this clone
  - plain-Bash test harness (scripts/tests/run.sh, lib.sh) with runtime-built fake values
  - .github/workflows/hygiene.yml with a CI Passed aggregator
affects: [01-02, 01-03, 01-04, 01-05, 01-06, phase-02-ci]

plan_head_before: 0ea5eaf56dd49fece6c4da2ce34ab5d1b972c9ac
plan_head_after: 37e5c71294131877ab20fcdd2260081a3239e39c

actuals:
  tokens: 7800
  tasks: 2
  commits: 3

tech-stack:
  added: [lefthook 2.1.17 (local), gitleaks 8.30.1 (local, used from plan 01-05), shellcheck 0.11.0, actionlint 1.7.12, zizmor 1.30.1]
  patterns:
    - "Two-stage awk pipeline: producers emit path<TAB>line<TAB>text, one scanner consumes it"
    - "Boundary checks in awk code (scan_re), not in regex, for mawk compatibility"
    - "Fake values assembled from fragments at runtime so no test line matches a rule"
    - "Fail closed: scanner exit other than 0/1 maps to 2; missing lefthook or gitleaks blocks"

key-files:
  created:
    - scripts/check-sensitive.sh
    - scripts/lib/diff2tsv.awk
    - scripts/lib/scan.awk
    - scripts/install-hooks.sh
    - scripts/tests/run.sh
    - scripts/tests/lib.sh
    - scripts/tests/test-check-sensitive.sh
    - scripts/tests/test-lefthook.sh
    - lefthook.yml
    - .github/workflows/hygiene.yml
  modified: []

key-decisions:
  - "Missing-lefthook test installs the hook with a private copy of the lefthook binary and deletes it: the generated hook bakes in the absolute path of the installing binary and falls back to it, so reducing PATH alone does not simulate an absent lefthook"
  - "Test libraries isolate temp repositories from the developer's git config (GIT_CONFIG_GLOBAL=/dev/null, GIT_CONFIG_NOSYSTEM=1) so signing or core.hooksPath cannot leak into tests"
  - "assert_out_has/lacks use fixed-string grep (-F) because masked output contains regex metacharacters"
  - "Test files use shellcheck source=/dev/null so they lint clean when checked on their own, as the plan's verify command does"

requirements-completed: [HYG-02, HYG-04, HYG-05]

coverage:
  - id: D1
    description: "A staged fake Stripe, GitHub or AWS key makes scripts/check-sensitive.sh exit 1 with path:line: key-prefix [masked]; clean input exits 0; no full value is ever printed"
    requirement: HYG-02
    verification:
      - kind: unit
        ref: "scripts/tests/test-check-sensitive.sh#(a)-(f)"
        status: pass
    human_judgment: false
  - id: D2
    description: "--all scans the index, D-05 exclusions are exact paths only, the script is read-only and cleans its temp dir, a crashing scanner fails closed"
    requirement: HYG-02
    verification:
      - kind: unit
        ref: "scripts/tests/test-check-sensitive.sh#(g)-(l)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Pre-commit hook installed via install-hooks.sh: fake key rejected, clean commit passes, commit without lefthook rejected, installer idempotent and refuses a core.hooksPath override"
    requirement: HYG-04
    verification:
      - kind: integration
        ref: "scripts/tests/test-lefthook.sh"
        status: pass
    human_judgment: false
  - id: D4
    description: "Hygiene workflow runs the self-tests and --all scan on push to main and pull_request, with permissions {}, SHA-pinned checkout and a CI Passed aggregator"
    requirement: HYG-05
    verification:
      - kind: other
        ref: "actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows"
        status: pass
    human_judgment: true
    rationale: "The workflow has never run on GitHub (nothing is pushed); only static lint and local test equivalents were exercised. A first PR run is the real proof that the CI Passed check appears."

duration: 7min
completed: 2026-10-06
status: complete
---

# Phase 1 Plan 01: Scanner, Hook and CI Tracer Summary

**Bash/awk sensitive-content scanner with masked output, wired into a fail-closed lefthook pre-commit hook (installed and proven with real commits) and a hardened GitHub Actions workflow exposing the `CI Passed` aggregator**

## Performance

- **Duration:** 7 min
- **Started:** 2026-10-06T22:15:55Z
- **Completed:** 2026-10-06T22:22:28Z
- **Tasks:** 2 (Task 1 tracer, Task 2 TDD)
- **Files modified:** 10 (all created)

## Accomplishments

- A staged fake Stripe, GitHub or AWS key is blocked with `path:line: key-prefix [xx*** (N chars)]` and exit 1. A clean change exits 0. This holds through the real hook in this repository, demonstrated with a live rejected commit of a planted `ghp_`-style fake.
- Scanner is mawk-safe: the quick suite passes on macOS (bash 3.2, BWK awk), under `bash` 5 on macOS, and in `ubuntu:24.04` (mawk 1.3.4, bash 5.2, GNU grep) via Docker.
- Hardened workflow `hygiene.yml` (actionlint and zizmor clean) with a SHA-pinned checkout and an aggregator job named exactly `CI Passed`.
- `scripts/install-hooks.sh` is fail-closed and idempotent; the hook is installed in this clone and every later commit in the phase goes through it.

## Task Commits

1. **Task 1: Tracer - staged fake key blocked by the scanner, wired to lefthook config and CI** - `d6b29dc` (feat)
2. **Task 2 (TDD RED): failing lefthook hook and installer test** - `9d78b20` (test)
3. **Task 2 (TDD GREEN): fail-closed installer, hook installed, test fixed** - `37e5c71` (feat)

**Plan metadata:** not committed (`commit_docs: false`, `.planning/` stays untracked until the hygiene tooling passes on it).

## Environment and Verification Facts

- **Tool versions (Step 0, all already installed, no `brew install` needed):** lefthook 2.1.17, gitleaks 8.30.1, shellcheck 0.11.0, actionlint 1.7.12, zizmor 1.30.1. Docker was available and used for the mawk run.
- **Verified checkout pin:** `gh api repos/actions/checkout/git/ref/tags/v7.0.1 --jq .object.sha` printed `3d3c42e5aac5ba805825da76410c181273ba90b1` (object type `commit`, not an annotated tag), matching the pin in `hygiene.yml`.
- **Verification results:** `bash scripts/tests/run.sh` PASS 2 FAIL 0 SKIP 0; `/bin/bash scripts/tests/run.sh --quick` PASS 1 FAIL 0 SKIP 1 (lefthook test skips in quick mode); `scripts/check-sensitive.sh --all` clean; `lefthook validate`, `shellcheck`, `actionlint`, `zizmor --offline` all clean; `lefthook check-install` exits 0; `core.hooksPath` unset; `git ls-files -s scripts/check-sensitive.sh` shows `100755`.

## TDD Notes (Task 2)

- **RED:** `bash scripts/tests/test-lefthook.sh` against a repository without `scripts/install-hooks.sh` exited 1 with 13 failed assertions on the planned behaviour (installer exit 127 instead of 0; a staged fake key commit exited 0 instead of 1; installer absent for the hooksPath refusal). Setup succeeded (temp repo, copies, initial commit), so the failures are assertion failures, not fixture crashes (semantic assessment: valid RED). The plain-Bash harness emits no TAP/JUnit report, so `gsd_run check tdd-red-evidence` was not applicable; `workflow.tdd_mode` is not enabled in `.planning/config.json`.
- **GREEN:** `install-hooks.sh` written; first run left 3 failures in the missing-lefthook case (see deviation 1). After the test fix all 21 assertions pass.
- **REFACTOR:** none needed.

## Decisions Made

- Plan 01-02 will add rule-specific branches to `handle()` in `scan.awk`; the generic branch (always `hit`) is what ships now.
- `workflow_dispatch` is omitted from `hygiene.yml` to honour D-11 literally (push to main and pull_request only), as the plan directs.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Missing-lefthook test did not simulate a missing lefthook**
- **Found during:** Task 2 (GREEN run)
- **Issue:** The plan's approach (run git with `PATH=/usr/bin:/bin`) did not trigger `assert_lefthook_installed`. The generated pre-commit hook bakes in the absolute path of the lefthook binary that installed it (`/opt/homebrew/bin/lefthook`) and falls back to it when PATH has no lefthook, so the commit succeeded.
- **Fix:** The test installs the hook with `scripts/install-hooks.sh` using a private copy of the lefthook binary on PATH, deletes the copy, then commits with the reduced PATH. The hook then reports `Operation is aborted due to lefthook settings` and exits 1. The case still skips with a notice if lefthook is on the reduced PATH or the binary cannot be copied (for example a mise shim).
- **Files modified:** `scripts/tests/test-lefthook.sh`
- **Verification:** `bash scripts/tests/test-lefthook.sh` all 21 assertions pass.
- **Committed in:** `37e5c71`

**2. [Rule 3 - Blocking] shellcheck reported SC1091 when the plan's verify command lints a test file alone**
- **Found during:** Task 2 (verify command `shellcheck scripts/install-hooks.sh scripts/tests/test-lefthook.sh` does not include `lib.sh`)
- **Issue:** `# shellcheck source=scripts/tests/lib.sh` made shellcheck exit 1 with an info note when `lib.sh` was not an input.
- **Fix:** Both test files use `# shellcheck source=/dev/null` for the `lib.sh` include.
- **Files modified:** `scripts/tests/test-lefthook.sh`, `scripts/tests/test-check-sensitive.sh`
- **Committed in:** `9d78b20` (test-lefthook.sh), `37e5c71` (test-check-sensitive.sh)

**3. [Rule 2 - Correctness] Test libraries isolate from the developer's git configuration**
- **Found during:** Task 1 (lib.sh design)
- **Issue:** A global `commit.gpgsign` or `core.hooksPath` would make temp-repo tests flaky or break the hooksPath assertions.
- **Fix:** `lib.sh` exports `GIT_CONFIG_GLOBAL=/dev/null` and `GIT_CONFIG_NOSYSTEM=1` and unsets `GIT_DIR`, `GIT_WORK_TREE`, `GIT_INDEX_FILE`, `GIT_PREFIX`; `run.sh` unsets the same variables.
- **Committed in:** `d6b29dc`

**4. [Plan shape] Task 2 produced two commits instead of one**
- Task 2 is `tdd="true"`, so it follows RED then GREEN (`test(01-01)` then `feat(01-01)`) per the TDD protocol. The plan's single-commit wording ("Commit the two files") was superseded; the GREEN commit itself went through the newly installed hook (the hook ran `sensitive-content` on the commit).

**5. [Extra tests] Added cases beyond the plan list**
- `test-check-sensitive.sh` also covers: a `++ b/decoy.txt` line (hunk-count parser must not redirect findings), `--all` reporting only non-excluded files, two arguments exit 2, index and status unchanged after a run, and a crashing scanner exiting 2 (T-01-06 fail-closed). `test-lefthook.sh` also asserts HEAD does not move on rejected commits.

---

**Total deviations:** 5 (1 bug in the plan's test approach, 1 blocking lint issue, 1 correctness hardening, 2 plan-shape or scope notes)
**Impact on plan:** No scope creep; all changes serve the plan's own truths (fail-closed hook, masked output, read-only scanner).

## Issues Encountered

- During a debug step I wrote one scratch file under `/tmp` instead of the session scratchpad; it was removed immediately and was never inside the repository.

## Threat Flags

None. The workflow, scripts and hook add no network endpoints or trust boundaries beyond those in the plan's threat model (T-01-01 to T-01-07 mitigated as listed; T-01-SC: no installs were needed).

## Known Stubs

None.

## Flagged Assumption for the Owner

- **A-HYG-04 (still open):** `git commit --no-verify`, `LEFTHOOK=0` and path-limited commits (`git commit -- path`, which uses a temporary index) can bypass or narrow the local hook. CI re-scans the whole tree and is the authoritative gate (D-09), but CI has not run yet because nothing is pushed. The owner should confirm that accepting a local bypass is fine given CI.

## User Setup Required

None - no external service configuration required. The org ruleset must expose the `CI Passed` check; that appears only after the first push or PR runs the workflow (tracked by later plans' HYG-07 checklist).

## Next Phase Readiness

- Plan 01-02 can extend `handle()` and the main block in `scripts/lib/scan.awk` with the remaining rule families and add `scripts/sensitive-allowlist.txt`; `test-lefthook.sh` already copies the allowlist and `.gitleaks.toml` when they exist.
- Plan 01-05 adds the gitleaks job to `lefthook.yml` (`install-hooks.sh` already requires gitleaks and prints the job list in its final message) and the gitleaks and `workflow-lint` steps to `hygiene.yml`.
- Every later commit in the phase passes through the installed hook; never use `--no-verify` or `LEFTHOOK=0`.

## Self-Check: PASSED

- Files present: all 10 created files verified on disk.
- Commits present on the branch: `d6b29dc`, `9d78b20`, `37e5c71`.
- Measured commit count from the ledger (`0ea5eaf..HEAD`): 3.

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-06*
