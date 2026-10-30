---
phase: 01-repository-hygiene
plan: 04
subsystem: infra
tags: [docs, gitignore, contributing, github-settings, bash]

requires:
  - phase: 01-repository-hygiene (plan 01)
    provides: test harness (run.sh, lib.sh), check-sensitive.sh contract, hygiene.yml, lefthook hook
  - phase: 01-repository-hygiene (plans 02, 03)
    provides: rule names, allowlist format, KOKPIT_DENYLIST and --history behaviour documented here
provides:
  - CONTRIBUTING.md as the single hygiene reference (rule, review procedure, setup, rules, exemptions, denylist, bypass, leak pointer, CI, GitHub settings checklist, per-phase .gitignore review)
  - .claude/CLAUDE.md tracked, sanitised, with a Repository Hygiene section outside every GSD marker pair
  - .gitignore with a negation-safe .claude/* rule and the reviewed gap list
  - scripts/tests/test-docs.sh (required phrases, marker placement) and scripts/tests/test-gitignore.sh (60 check-ignore verdicts)
affects: [01-05, 01-06, phase-02-ddev, phase-02-community-files]

plan_head_before: aa41cbb84d9a21da0bec0a3f197404dc658a728d
plan_head_after: 0b1e0e162f701c09faa2f1bfef221709cceeaa61

actuals:
  tokens: 6650
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "Doc contracts as static tests: required ASCII phrases matched byte-wise (LC_ALL=C grep -F), with self-tests that mutate a copy to prove the check fails"
    - "Ignore verdicts via git check-ignore --no-index on paths that never exist, in a temp repo holding a copy of the real .gitignore"
    - "Wildcard-then-negation (.claude/* then !.claude/CLAUDE.md); the ignore-the-directory variant is asserted to be wrong"

key-files:
  created:
    - CONTRIBUTING.md
    - scripts/tests/test-docs.sh
    - scripts/tests/test-gitignore.sh
    - .claude/CLAUDE.md (first commit; existed untracked before)
  modified:
    - .gitignore

key-decisions:
  - "Docs describe the contract fixed in plan 01-01, so CONTRIBUTING already names the gitleaks pre-commit job, the gitleaks CI step and the workflow-lint job that plan 01-05 adds; until then they are forward references"
  - "test-docs.sh exposes docs_problems <root> so the same check runs against mutated temp copies (missing, empty, phrase removed, heading inside a marker block, regenerated stack block, non-ASCII neighbours)"
  - "Directory-rule negative case added to test-gitignore.sh: ignoring .claude/ itself makes CLAUDE.md ignored, which documents why .claude/* is used"

requirements-completed: [HYG-01, HYG-06, HYG-07]

coverage:
  - id: D1
    description: "CONTRIBUTING.md and .claude/CLAUDE.md state 'Fictional data only' and the review procedure git status, git diff --staged, scripts/check-sensitive.sh; the hygiene section sits outside GSD markers and the generated stack, conventions and architecture blocks are gone"
    requirement: HYG-06
    verification:
      - kind: unit
        ref: "scripts/tests/test-docs.sh (29 assertions incl. mutation self-tests)"
        status: pass
    human_judgment: false
  - id: D2
    description: "CONTRIBUTING.md documents setup and tested versions, checker rules and modes, exemptions, KOKPIT_DENYLIST with a fictional example, never-bypass, leak pointer, CI and the GitHub settings checklist"
    requirement: HYG-06
    verification:
      - kind: unit
        ref: "scripts/tests/test-docs.sh#required phrases"
        status: pass
    human_judgment: true
    rationale: "Phrases prove the topics are present, not that the instructions are correct or readable; a human reads the document once."
  - id: D3
    description: "Env files, local AI and IDE settings, storage, logs, dumps, exports, /local/, credentials and coverage output are ignored; .env.example, .claude/CLAUDE.md and .ddev/config.yaml stay trackable; order and adjacency verified"
    requirement: HYG-01
    verification:
      - kind: unit
        ref: "scripts/tests/test-gitignore.sh (60 ok, 0 FAIL)"
        status: pass
    human_judgment: false
  - id: D4
    description: "GitHub secret scanning, push protection, SHA-pinning requirement, Actions PR approval, Dependabot and the CI Passed ruleset are documented as a maintainer checklist; current settings audited read-only"
    requirement: HYG-07
    verification:
      - kind: other
        ref: "gh api read-only audit recorded below"
        status: unknown
    human_judgment: true
    rationale: "Settings cannot be enforced or checked from CI (admin token needed). The audit shows sha_pinning_required=false and can_approve_pull_request_reviews=true, so two checklist items are not yet satisfied; the owner switches them and confirms at the human-check."

duration: 5min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 04: Hygiene Documentation and .gitignore Summary

**CONTRIBUTING.md and a tracked, sanitised .claude/CLAUDE.md state the fictional-data-only rule, review procedure, denylist, bypass policy and GitHub settings checklist; .gitignore ignores the reviewed gap list while keeping only CLAUDE.md of .claude/ trackable, all guarded by static tests**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-06T22:36:57Z
- **Completed:** 2026-10-06T22:42:00Z
- **Tasks:** 3 (Task 1 tracer, Task 2 auto, Task 3 TDD)
- **Files modified:** 5 (3 created, `.claude/CLAUDE.md` newly tracked, `.gitignore` modified)

## Accomplishments

- `.claude/CLAUDE.md` is tracked. The generated stack, conventions and architecture blocks (they described the GSD framework and contained a personal home path) are deleted; project, skills, workflow and profile blocks are kept. A `## Repository Hygiene` section sits directly after the project block, outside all markers. `git ls-files .claude` prints only `.claude/CLAUDE.md`; the rest of `.claude/` is ignored and `git status` shows nothing under it.
- CONTRIBUTING.md has 10 sections: hygiene rule and procedure, Setup (lefthook 2.1.17, gitleaks 8.30.1, `scripts/install-hooks.sh`), What the checker flags (nine rules, four modes, exit codes), Exemptions, Local denylist, Never bypass, If something leaks, CI, GitHub settings checklist, Per-phase .gitignore review.
- test-docs.sh (29 assertions) checks the phrases byte-wise and also proves it fails: it mutates temp copies (missing, empty, each phrase removed from each file, heading moved inside a marker pair, regenerated stack block) and expects the matching problem line.
- test-gitignore.sh (60 verdicts) proves every behaviour-list path ignored or trackable, that `!.claude/CLAUDE.md` comes after `.claude/*`, that swapping them (or ignoring `.claude/` itself) makes CLAUDE.md ignored, and that verdicts hold whether or not the files exist or are tracked.
- Quick suite: PASS 5 FAIL 0 SKIP 1 under bash 5, `/bin/bash` 3.2, and `ubuntu:24.04` (mawk 1.3.4). shellcheck clean on all scripts.

## Task Commits

1. **Task 1: Tracer - rule and procedure in both docs, CLAUDE.md trackable, test-docs.sh** - `4934fef` (feat)
2. **Task 2: CONTRIBUTING as full hygiene reference with GitHub settings checklist** - `7de0a13` (docs)
3. **Task 3 (TDD RED): failing gitignore verdict tests** - `f611a34` (test)
4. **Task 3 (TDD GREEN): gap list in .gitignore, per-phase review section** - `0b1e0e1` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked).

## Tracer Gate

Interactive mode, `end-of-phase`, tracer `<verify>` automated-only: re-ran the quick suite, the ignore/track git checks and the acceptance criteria after the Task 1 commit. All passed (`Tracer verified end-to-end - expanding`).

## TDD Gate Compliance (Task 3)

- **RED:** `bash scripts/tests/test-gitignore.sh` before the gap list existed: 44 assertions passed and 16 failed, exactly the gap-list paths (`.cursor/rules`, `.aider.chat.history.md`, `*.xls`, `*.ods`, sqlite files, `exports/`, `auth.json`, `*.p12`, `*.pfx`, `*.keystore`, `id_rsa`, `coverage/`, the three tool caches), each `exit 1, want 0`. Setup (temp repo, copy of .gitignore) succeeded, so these are assertion failures on the planned behaviour, not fixture crashes (semantic assessment: valid RED). The plain-Bash harness has no TAP/JUnit report, so `gsd_run check tdd-red-evidence` does not apply (same as plans 01-01 to 01-03); `workflow.tdd_mode` is off.
- **GREEN:** after adding the rules, 60 ok and 0 FAIL.
- **REFACTOR:** none needed.

## Read-only GitHub Settings Audit (Task 2)

Run with `gh api` against the repository's own endpoints (slug not recorded). Nothing was changed.

| Setting | Value | Checklist item satisfied |
|---|---|---|
| secret_scanning | enabled | yes |
| secret_scanning_push_protection | enabled | yes |
| secret_scanning_non_provider_patterns | enabled | yes |
| secret_scanning_validity_checks | enabled | yes |
| secret_scanning_ai_detection | disabled | not required |
| secret_scanning_delegated_bypass | disabled | not required |
| secret_scanning_delegated_alert_dismissal | disabled | not required |
| dependabot_security_updates | disabled | **no** (checklist asks for enabled) |
| Dependabot alerts (vulnerability-alerts endpoint) | enabled (HTTP 204) | yes |
| actions allowed_actions | all | n/a |
| sha_pinning_required | **false** | **no** (checklist asks for enabled) |
| default workflow permissions | read | yes |
| can_approve_pull_request_reviews | **true** | **no** (checklist asks for disabled) |
| fork PR approval policy | all_external_contributors | yes |
| Ruleset on `main` | active, no bypass actors | yes |
| Ruleset rules | non_fast_forward, deletion, pull_request (0 approvals), required_status_checks: `CI Passed` | yes |

Items outstanding for the owner: switch on "Require actions to be pinned to a full-length commit SHA" (hygiene.yml is already SHA-pinned, so enabling it will not break it), disable "Allow GitHub Actions to create and approve pull requests", enable Dependabot security updates, and confirm two-factor authentication and the optional e-mail privacy settings, which the API does not expose. The `CI Passed` check name will only be selectable after the first workflow run on GitHub.

## Decisions Made

See `key-decisions`. In short: docs follow the 01-01 contract (including pieces plan 01-05 adds), the docs checker is mutation-tested, and a directory-rule negative case documents the `.claude/*` choice.

## Deviations from Plan

### Auto-fixed Issues

None needed beyond the plan. Scope notes:

**1. [Plan shape] Task 3 produced two commits** - `tdd="true"`, so RED (`test(01-04)`) then GREEN (`feat(01-04)`) as in plans 01-01 to 01-03.

**2. [Extra tests] Cases beyond the plan list** - mutation self-tests for test-docs.sh (including non-ASCII neighbours), the directory-rule negative case, and a check that an existing and an already-tracked file give the same verdicts. test-gitignore.sh has 60 assertions against the plan's minimum of 50.

**3. [Orchestrator note] Denylist path caveat documented** - CONTRIBUTING states that a file path that itself contains a denylist term is printed as the finding path, because the path is the finding (inherent to the `path:line` contract).

**4. [Pin guard] Condensed project-root pin** - the guard was run as a short script in the session scratchpad that performs the same root comparison (`git rev-parse --show-toplevel` against the pinned root) before each commit, not the full multi-stage listing.

---

**Total deviations:** 0 auto-fixed, 4 scope or shape notes
**Impact on plan:** None; every change serves the plan's own truths.

## Issues Encountered

- `scripts/check-sensitive.sh --all` still reports exactly one finding: `.planning/codebase/STACK.md:50: home-path`. It is the known item handled by plan 01-06; nothing in CONTRIBUTING.md or `.claude/CLAUDE.md` is flagged.
- Other test files in `scripts/tests/` (for example `lib.sh`, `test-denylist.sh`, `test-modes.sh`) are not executable; `run.sh` invokes them through bash, so this has no effect. Left as is (out of scope).

## Threat Flags

None. No new network endpoints, auth paths or trust boundaries; the read-only `gh api` audit changed nothing. T-01-18 to T-01-22 are mitigated as planned (fictional examples, sanitised CLAUDE.md, `.claude/*` rule with verdict tests, documented checklist plus audit, main-checkout precondition verified before Task 1).

## Known Stubs

None. CONTRIBUTING.md refers to `SECURITY.md` (Phase 2), the gitleaks job and the `workflow-lint` job (plan 01-05) as things that arrive later; the references are intentional and stated.

## Flagged Assumptions

- **A-HYG-07 (still unresolved):** GitHub settings can drift after they are documented, and no automated check runs in CI. The audit above shows three checklist items not yet satisfied; the owner confirms them at the human-check below. Two labels remain `[ASSUMED]` from research (A3): "Allow GitHub Actions to create and approve pull requests" and the outside-contributor approval option (the API reports policy `all_external_contributors`, which matches the checklist wording).
- **A1 (from research):** whether GSD regeneration keeps text outside `<!-- GSD:* -->` markers is unverified. If it does not, the rule still lives in CONTRIBUTING.md and test-docs.sh fails in CI.

## Human Check (Task 2, deferred to end of phase)

Owner opens the repository settings on GitHub and confirms each item of the "GitHub settings checklist" in CONTRIBUTING.md against the audit table above: secret scanning and push protection stay enabled; switch on SHA pinning; switch off Actions creating and approving pull requests; enable Dependabot security updates; confirm the main-branch ruleset requires `CI Passed` once the check has appeared after the first run.

## User Setup Required

None for code. The GitHub settings above are manual owner actions (no automation, by design).

## Next Phase Readiness

- Plan 01-05 adds the gitleaks pre-commit job, the gitleaks CI step, `workflow-lint`, Dependabot and CODEOWNERS that CONTRIBUTING.md already describes; it should keep the documented tool versions and the phrase set in test-docs.sh in step with the bump procedure.
- Plan 01-06 resolves `.planning/codebase/STACK.md:50` and decides whether `/.planning/codebase/` is ignored; this plan deliberately did not touch it.
- Phase 2 (DDEV, community files) uses the per-phase `.gitignore` review rule: add rules and `test-gitignore.sh` cases together.

## Self-Check: PASSED

- Files present: CONTRIBUTING.md, .claude/CLAUDE.md (tracked), .gitignore, scripts/tests/test-docs.sh, scripts/tests/test-gitignore.sh.
- Commits are ancestors of HEAD: 4934fef, 7de0a13, f611a34, 0b1e0e1.
- Measured commit count from the ledger (`aa41cbb..HEAD`): 4 (matches `commits: 4`).
- Acceptance criteria re-run after Task 3: gap-list greps, `.claude/` never ignored as a directory, `Review .gitignore` present, 60 `ok -` lines, quick suite green on three environments.

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
