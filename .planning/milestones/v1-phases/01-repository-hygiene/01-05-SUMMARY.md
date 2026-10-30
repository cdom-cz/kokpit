---
phase: 01-repository-hygiene
plan: 05
subsystem: infra
tags: [gitleaks, lefthook, github-actions, actionlint, zizmor, dependabot, codeowners, secret-scanning]

requires:
  - phase: 01-repository-hygiene (plan 01)
    provides: lefthook.yml with the sensitive-content job, install-hooks.sh, hygiene.yml scan job and CI Passed aggregator
  - phase: 01-repository-hygiene (plan 03)
    provides: --history step in the scan job, test harness conventions
provides:
  - .gitleaks.toml extending the default ruleset with six kokpit-* rules
  - lefthook gitleaks job on staged changes (redacted, inline allow comments ignored, fail-closed without the binary)
  - CI install of gitleaks 8.30.1 by hard-coded SHA-256 and a full-history gitleaks step
  - workflow-lint job (actionlint 1.7.12, zizmor 1.30.1, both checksum-pinned) gating CI Passed
  - Dependabot for github-actions pins, CODEOWNERS on the hygiene paths
affects: [01-06, phase-02-ci]

plan_head_before: 0b1e0e162f701c09faa2f1bfef221709cceeaa61
plan_head_after: cd1711650fddf501ee861a4639512e82c3746d98

actuals:
  tokens: 4700
  tasks: 2
  commits: 2

tech-stack:
  added: [gitleaks 8.30.1 (release binary), actionlint 1.7.12 (release binary), zizmor 1.30.1 (release binary)]
  patterns:
    - "Release binaries installed in CI by curl plus a hard-coded SHA-256 verified with sha256sum --check --strict; no checksum file from the same release is trusted"
    - "Planted-fake tests: fakes built from fragments, config copied outside the temp repository, redaction asserted on both report and stdout"
    - "Lint gate on the workflow itself: CI Passed needs [scan, workflow-lint] with a success-only results loop"

key-files:
  created:
    - .gitleaks.toml
    - scripts/tests/test-gitleaks.sh
    - .github/dependabot.yml
    - .github/CODEOWNERS
  modified:
    - lefthook.yml
    - .github/workflows/hygiene.yml
    - scripts/tests/test-lefthook.sh

key-decisions:
  - "The kokpit-home-path allowlist also allows ddev and Shared, mirroring the shell scanner's home-path allowlist, so the two layers agree on what is a system or placeholder directory"
  - "gitleaks hook and CI pass --ignore-gitleaks-allow: exemptions are only possible through the reviewed .gitleaks.toml or .gitleaksignore"
  - "Only the current gitleaks git subcommand is used; the deprecated protect and detect are never referenced (and the test grep guards it)"

patterns-established:
  - "Every kokpit-* custom rule has a planted-fake case and a placeholder case in test-gitleaks.sh"
  - "Digests are re-verified read-only with gh api before each commit that carries them"

requirements-completed: [HYG-04, HYG-05]

coverage:
  - id: D1
    description: "gitleaks with .gitleaks.toml reports one planted fake per custom rule plus the default github-pat rule, redacted, and does not report the documented placeholders (12345678, 00000000, /Users/example/, /home/runner/, /Users/Shared/)"
    requirement: HYG-05
    verification:
      - kind: unit
        ref: "scripts/tests/test-gitleaks.sh#(a),(b),(c)"
        status: pass
    human_judgment: false
  - id: D2
    description: "gitleaks git reports a fake that was deleted from the tree in a later commit (history case) and --pre-commit --staged rejects a staged fake"
    requirement: HYG-05
    verification:
      - kind: unit
        ref: "scripts/tests/test-gitleaks.sh#(d),(e)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The lefthook pre-commit hook rejects a credential only gitleaks recognises with the value redacted, and rejects a clean commit when gitleaks is not on PATH with the install hint from fail_text"
    requirement: HYG-04
    verification:
      - kind: integration
        ref: "scripts/tests/test-lefthook.sh#4b,4c"
        status: pass
    human_judgment: false
  - id: D4
    description: "hygiene.yml installs gitleaks, actionlint and zizmor by hard-coded SHA-256, scans full history with gitleaks, lint-gates itself, and CI Passed requires scan and workflow-lint"
    requirement: HYG-05
    verification:
      - kind: other
        ref: "actionlint && zizmor --offline .github/workflows (default and pedantic): no findings; negative control rejected"
        status: pass
    human_judgment: true
    rationale: "The workflow has never run on GitHub (nothing is pushed). Static lint, digest re-verification and the local equivalent of each step passed; only a first PR run proves the install steps and the full-history scan on the runner."
  - id: D5
    description: "Dependabot keeps SHA-pinned actions current weekly and CODEOWNERS covers the workflows, scripts, allowlist, lefthook.yml, .gitleaks.toml and .gitleaksignore"
    requirement: HYG-04
    verification:
      - kind: other
        ref: "grep acceptance criteria on .github/dependabot.yml and .github/CODEOWNERS"
        status: pass
    human_judgment: true
    rationale: "CODEOWNERS only takes effect once the repository ruleset requires code-owner review; that GitHub setting is the owner's (CONTRIBUTING.md checklist)."

duration: 4min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 05: gitleaks Layer and Workflow Lint Gate Summary

**gitleaks 8.30.1 with a six-rule Kokpit config runs in the lefthook hook, the test suite and a checksum-pinned CI full-history step, and the workflow itself is gated by pinned actionlint and zizmor**

## Performance

- **Duration:** 4 min
- **Started:** 2026-10-06T22:43:32Z
- **Completed:** 2026-10-06T22:47:30Z
- **Tasks:** 2 (Task 1 tracer, Task 2 auto)
- **Files modified:** 7 (4 created, 3 modified)

## Accomplishments

- `.gitleaks.toml` extends the default ruleset and adds Stripe publishable/webhook, Stripe account/Payment Link ids, home paths (placeholder allowlist), keyword-anchored company ids (stopwords `12345678`, `00000000`), hosting/S3 endpoints and a Zerops token assignment rule. A planted fake per rule is detected, redacted in report and stdout, and the placeholders are not reported (35 assertions).
- The lefthook hook now runs `sensitive-content` then `gitleaks git --pre-commit --staged --redact ... --ignore-gitleaks-allow`. Tested through real commits: a generic high-entropy credential only gitleaks recognises is rejected with `REDACTED` and no value; a clean commit is rejected with the install hint when gitleaks is not on PATH.
- The history case holds: a fake deleted by a later commit is still reported by `gitleaks git`, and a clean history exits 0. Over this repository's own history the only file reported is the known `.planning/codebase/STACK.md` (plan 01-06 resolves it).
- CI: gitleaks installed from the upstream release by hard-coded SHA-256 (no gitleaks GitHub Action), run over full history with `--redact --ignore-gitleaks-allow`; `workflow-lint` job runs actionlint and zizmor (offline) from pinned tarballs; `CI Passed` needs `[scan, workflow-lint]`.
- Negative control: zizmor exits non-zero on a deliberately bad workflow (privileged PR-target trigger, unpinned tag) written outside the repository, so the lint gate bites. actionlint and zizmor (default and pedantic) are clean on the real workflow.

## Re-verified digests

Read-only `gh api repos/<owner>/<repo>/releases/tags/<tag> --jq '.assets[] | select(.name==...) | .digest'` before the commits that carry them; all three equal the pinned values:

| Artefact | SHA-256 |
|---|---|
| gitleaks 8.30.1 linux x64 | `551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb` |
| actionlint 1.7.12 linux amd64 | `8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8` |
| zizmor 1.30.1 linux x86_64 | `e65324f4430c2717591937edcec90ccbefaf14c174f8ec9415e03ca875b46e1a` |

The CODEOWNERS handle came from `gh api user --jq .login` (the maintainer's public GitHub login), written as `@<login>` on all six entries.

## Task Commits

1. **Task 1: Tracer - gitleaks in hook, tests and CI full-history step** - `12c0036` (feat)
2. **Task 2: Workflow lint gate, Dependabot, CODEOWNERS** - `cd17116` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked until hygiene tooling passes on it).

## Files Created/Modified

- `.gitleaks.toml` - default ruleset plus six `kokpit-*` rules, array-of-tables allowlists only
- `lefthook.yml` - second pre-commit job `gitleaks`
- `.github/workflows/hygiene.yml` - gitleaks pins and install step, full-history step, `workflow-lint` job, `ci-passed` over both
- `scripts/tests/test-gitleaks.sh` - planted fakes, placeholders, deleted-later history case, staged mode, redaction
- `scripts/tests/test-lefthook.sh` - gitleaks-only rejection and missing-gitleaks cases
- `.github/dependabot.yml` - github-actions, weekly
- `.github/CODEOWNERS` - owner on `/.github/`, `/scripts/`, allowlist, `lefthook.yml`, `.gitleaks.toml`, `.gitleaksignore`

## Decisions Made

See `key-decisions`. In short: the home-path allowlist was aligned with the shell scanner, inline `gitleaks:allow` is disabled in both hook and CI, and only the non-deprecated `gitleaks git` command is used.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] kokpit-home-path allowlist lacked `ddev` and `Shared`**
- **Found during:** Task 1 (history verification)
- **Issue:** gitleaks over history reported `/Users/Shared/tmp` in `scripts/tests/test-check-sensitive.sh` (commit 5aca8c9), a macOS system folder the shell scanner already allows. The plan's verify requires STACK.md to be the only reported file.
- **Fix:** Extended the placeholder allowlist regex with `ddev|Shared`, matching `scripts/lib/scan.awk`, and added a `/Users/Shared/` case to the placeholder test. Not a weakening: both are system directories, not personal paths; any other `/Users/<name>/` is still reported.
- **Files modified:** .gitleaks.toml, scripts/tests/test-gitleaks.sh
- **Verification:** history report lists only `.planning/codebase/STACK.md`; test (c) passes
- **Committed in:** 12c0036

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Necessary for the layers to agree and for the plan's own history check; no scope creep.

## Issues Encountered

- `scripts/check-sensitive.sh --all` and `--history`, and gitleaks over history, still report `.planning/codebase/STACK.md:50` (home path). Expected and carried over: plan 01-06 resolves it; the file was not touched and no rule was weakened to hide it.

## Flagged assumption A-HYG-05 (still unresolved, owner to confirm)

"Full history" means all refs present in the CI checkout (`fetch-depth: 0`: branches, tags, the PR merge ref). Refs never fetched (other forks, deleted remote branches) are out of scope. The shell `--history` mode does not diff merge commits (`git log -p` default), so content that exists only in a merge-conflict resolution is covered by `--all` (tree) and by gitleaks, not by the shell history scan. Locally gitleaks over this repository reported no merge-only content, but this repository's history has no merge commits to exercise it. The Zerops token rule format and non-`.zerops.app` Zerops hostnames remain `[ASSUMED]` (research A2); the rule is commented as such in `.gitleaks.toml`.

## Known Stubs

None.

## Threat Flags

None. The new surfaces (release downloads in CI, workflow lint, CODEOWNERS) are the ones in the plan's threat model (T-01-23 to T-01-28, T-01-SC), each mitigated as planned: `--redact` plus leak assertions, hard-coded digests, lint gate with negative control, `--ignore-gitleaks-allow`, CODEOWNERS and Dependabot.

## User Setup Required

None for the code. For CODEOWNERS and `CI Passed` to be enforced the owner must have the repository ruleset require code-owner review and the `CI Passed` check (CONTRIBUTING.md GitHub settings checklist from plan 01-04). The workflow has not yet run on GitHub.

## Next Phase Readiness

- Plan 01-06 can resolve the STACK.md finding; afterwards `gitleaks git`, `check-sensitive.sh --all` and `--history` should all be clean for the clean-branch proof (roadmap criterion 4).
- CONTRIBUTING.md should mention manual bumps of the curl-installed binaries (version plus digest together); the comments in hygiene.yml and dependabot.yml already point there.

## Self-Check: PASSED

- Files exist: .gitleaks.toml, scripts/tests/test-gitleaks.sh, scripts/tests/test-lefthook.sh, lefthook.yml, .github/workflows/hygiene.yml, .github/dependabot.yml, .github/CODEOWNERS
- Commits are ancestors of HEAD: 12c0036, cd17116
- `git rev-list --count 0b1e0e1..HEAD` = 2 (matches `commits: 2`)
- Re-run: `bash scripts/tests/run.sh` PASS 7 FAIL 0 SKIP 0; history report lists only STACK.md; `lefthook validate`, `actionlint`, `zizmor --offline` (default and pedantic) clean; negative control rejected; all acceptance criteria of both tasks pass

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
