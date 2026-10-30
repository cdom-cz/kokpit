---
phase: 01-repository-hygiene
plan: 08
subsystem: infra
tags: [bash, awk, gitleaks, gitattributes, codeowners, github-actions, gap-closure]

requires:
  - phase: 01-repository-hygiene
    provides: content-complete scanner producers and test library (plans 01-05, 01-07)
provides:
  - git-attributes scanner rule (check_attributes in scripts/lib/scan.awk) refusing -diff, binary and filter= in any .gitattributes
  - scripts/tests/test-attributes.sh (cases a to k)
  - CI gitleaks step with --log-opts for text and first-parent merge diffs, asserted by the test suite
  - CI-form gitleaks cases (f) to (i) in scripts/tests/test-gitleaks.sh
  - CODEOWNERS entries for .gitattributes, .gitignore, CONTRIBUTING.md and .claude/
affects: [01-09, 01-10, 01-11, hygiene-ci, ship-gate]

requirements-completed: [HYG-02, HYG-04, HYG-05]

actuals:
  tokens: 4240
  tasks: 2
  commits: 3
plan_head_before: bad8e840123dbb50bb446b002a6eaf2bd430c683
plan_head_after: b90a8c6ef98692fc7e0504bd8a9fac8f36f69847
commits: 3

tech-stack:
  added: []
  patterns:
    - "Current-state policy rules skip history rows (line field carries @sha7), so a removed violation cannot keep CI red"
    - "A workflow contract string is asserted by the test that proves it (GLK_LOG_OPTS grep against hygiene.yml)"

key-files:
  created:
    - scripts/tests/test-attributes.sh
  modified:
    - scripts/lib/scan.awk
    - scripts/tests/test-gitleaks.sh
    - scripts/tests/test-content.sh
    - .github/workflows/hygiene.yml
    - .github/CODEOWNERS
    - lefthook.yml

key-decisions:
  - "The attribute guard is a scanner rule on .gitattributes rows, skipped for history rows, so no diff2tsv driver change was needed"
  - "Rejected tokens: -diff, binary and filter=; text=auto, eol, diff=<driver>, -text and export-ignore stay allowed (Laravel defaults pass)"
  - "CI gitleaks gets --log-opts=\"--all --diff-merges=first-parent --text --no-textconv\"; the hook command is unchanged because gitleaks --pre-commit takes no diff options"

coverage:
  - id: D1
    description: "A staged or tracked .gitattributes line (any directory, CRLF, [attr] macro) with -diff, binary or filter= is reported as path:line: git-attributes with exit 1; benign attributes and other file names pass"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "scripts/tests/test-attributes.sh#(a) to (h),(k)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The attribute rule checks current state only: --history ignores it, --all reports a tracked line and is clean once the line is removed"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "scripts/tests/test-attributes.sh#(i)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The CI gitleaks step reports a fake in a -diff file, a NUL-containing file and a merge-only commit, passes a clean merge history, and the suite asserts the workflow carries the same --log-opts string"
    requirement: HYG-04
    verification:
      - kind: integration
        ref: "scripts/tests/test-gitleaks.sh#(f) to (i)"
        status: pass
      - kind: other
        ref: "gitleaks git --config .gitleaks.toml --redact --no-banner --ignore-gitleaks-allow --exit-code 1 --log-opts=\"--all --diff-merges=first-parent --text --no-textconv\" . (25 commits, no leaks)"
        status: pass
    human_judgment: false
  - id: D4
    description: "CODEOWNERS covers the attribute file, ignore rules, contributing guide and .claude/ and drops the redundant allowlist line"
    requirement: HYG-05
    verification:
      - kind: integration
        ref: "scripts/tests/test-attributes.sh#(j)"
        status: pass
    human_judgment: true
    rationale: "Code-owner entries only take effect when the repository ruleset requires code-owner review; that setting is the maintainer's decision (human verification item 3)"

duration: 5min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 08: Attribute guard and CI gitleaks content coverage Summary

**A `git-attributes` scanner rule keeps `-diff`, `binary` and `filter=` out of every `.gitattributes`, and the CI gitleaks step now reads NUL-containing, attribute-hidden and merge-only content through `--log-opts`, with the exact option string asserted by the test suite.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-07T08:27:42Z
- **Completed:** 2026-10-07T08:32:08Z
- **Tasks:** 2
- **Files modified:** 7 (1 created)

## Accomplishments

- `check_attributes()` in `scan.awk` flags `-diff`, `binary` and `filter=` tokens after the path pattern (so `[attr]` macro bodies, CRLF lines and sub-directory `.gitattributes` are covered). It runs for staged, `--all` and explicit-file rows and is skipped when the line field carries `@sha`, so history never fails for a line a later commit removed.
- CI gitleaks command now passes `--log-opts="--all --diff-merges=first-parent --text --no-textconv"`; the real repository history (25 commits) stays clean under it.
- `test-gitleaks.sh` gains `GLK_LOG_OPTS`, `glk_ci`, the workflow-string assertion and cases (f) to (i); redaction is asserted for stdout and the report.
- `.github/CODEOWNERS` covers `/.gitattributes`, `/.gitignore`, `/CONTRIBUTING.md` and `/.claude/`; the entry made redundant by `/scripts/` is gone; the comment states that the entries need the ruleset to require code-owner review.
- `lefthook.yml` comment now says truthfully what the hook's gitleaks job cannot see and which layers cover it; the command is unchanged.

## Task Commits

1. **Task 1: Tracer, a .gitattributes line that hides content from gitleaks is blocked** - `5fbe1f8` (feat)
2. **Task 2: CI gitleaks reads binary-classified and merge-only content** (TDD)
   - RED: `d1969f0` (test)
   - GREEN: `b90a8c6` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked).

## Files Created/Modified

- `scripts/lib/scan.awk` - `check_attributes()` and the `git-attributes` rule on `.gitattributes` rows outside history
- `scripts/tests/test-attributes.sh` - cases (a) to (k): rule, benign lines, non-attribute files, `--all`/`--history` policy, explicit file, CODEOWNERS
- `scripts/tests/test-gitleaks.sh` - CI-form helper and cases (f) to (i)
- `scripts/tests/test-content.sh` - cleanup commits in (h) and (m) also remove the `-diff` `.gitattributes` fixture
- `.github/workflows/hygiene.yml` - `--log-opts` on the gitleaks history step and its comment
- `.github/CODEOWNERS` - extended ownership
- `lefthook.yml` - corrected comment above the gitleaks job

## Decisions Made

- Guard implemented as a scanner rule rather than a driver change: it reuses allowlist and masking, runs in the hook, CI tree scan and explicit mode, and needs no change to `diff2tsv.awk`.
- A future deliberate Git LFS or other filter use needs a reviewed allowlist entry for rule `git-attributes` (A-ATTR-01).
- `-text` alone is not rejected: it does not hide content from `git diff`.

## TDD Gate Compliance

Plan type is `execute`; Task 2 carried `tdd="true"`.

- **RED** (`d1969f0`): the target assertion `(f) the workflow does not carry --log-opts="..."` failed because the workflow lacked the string; test-gitleaks.sh exited 1. Cases (f) to (i) of the CI form passed already in RED because they exercise the gitleaks CLI directly with the options and depend only on gitleaks, not on the workflow file; that is expected, and the workflow assertion is the link between the two. Semantic assessment: the target assertion executed and failed for the intended reason (workflow missing the option string); not a syntax, fixture or discovery fault. The RED evidence is plain assertion output, not a TAP/JUnit report, so `gsd check tdd-red-evidence` does not apply.
- During authoring, case (g) first failed for a fixture reason (the NUL fixture was named `n.bin` and `.bin` is on gitleaks' default path allowlist, so even a control without NUL was skipped). The fixture was renamed to `n.txt` before the RED commit; the committed RED state fails only on the workflow assertion.
- **GREEN** (`b90a8c6`): workflow and lefthook comment updated; test-gitleaks.sh 49 assertions pass.
- **REFACTOR:** none needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Existing content-test fixtures violated the new attribute policy**
- **Found during:** Task 1 (full quick suite after the rule landed)
- **Issue:** `test-content.sh` cases (h) and (m) delete the hidden data files but leave their `*.dat -diff` `.gitattributes` in the tree, so `--all` on the "clean" tree now correctly reported `git-attributes` and the assertions expecting exit 0 failed.
- **Fix:** the cleanup commits in (h) and (m) also `git rm` `.gitattributes`.
- **Files modified:** scripts/tests/test-content.sh
- **Verification:** `bash scripts/tests/run.sh` PASS 9 FAIL 0; quick suite on bash 3.2 and under mawk (Ubuntu container) green
- **Committed in:** 5fbe1f8

**2. [Plan label adjustment] Extra case (k) in test-attributes.sh**
- The plan listed cases (a) to (j) with (j) as the CODEOWNERS check and no explicit-file case. The CODEOWNERS assertion keeps label (j) as specified; an explicit-file case (the plan states the rule runs in explicit-file mode) was added as (k).

---

**Total deviations:** 1 auto-fixed (1 test-fixture bug), 1 label addition
**Impact on plan:** None on scope; the fixture fix follows directly from the new rule.

## Issues Encountered

None beyond the fixture issues above. The plan's planning-time claim that gitleaks reports the NUL file with these options holds for text-named files; paths on gitleaks' default allowlist (for example `*.bin`) are skipped regardless of options, which is gitleaks' own path policy and outside this plan.

## Verification

- `bash scripts/tests/run.sh` (full): PASS 9 FAIL 0 SKIP 0
- `bash scripts/tests/run.sh --quick`: PASS 7 FAIL 0 SKIP 2 (gitleaks and lefthook tests skip in quick mode by design)
- `bash scripts/tests/run-in-ubuntu.sh` (mawk 1.3.4): PASS 7 FAIL 0 SKIP 2
- `scripts/check-sensitive.sh --all` and `--history`: clean
- gitleaks with the exact CI options over the real history: no leaks (25 commits)
- `lefthook validate`, `actionlint`, `zizmor --offline .github/workflows`, `shellcheck scripts/*.sh scripts/tests/*.sh`: clean
- Acceptance greps for both tasks pass; every `uses:` in the workflow is SHA-pinned (2 of 2)

## Known Stubs

None.

## Threat Flags

None. No new network, auth or trust-boundary surface; T-01-38 to T-01-41 are mitigated and covered by tests.

## User Setup Required

None - no external service configuration required. The first real GitHub run and the ruleset's code-owner review setting remain human verification items.

## Next Phase Readiness

The attribute guard and the CI gitleaks options are in place; plan 01-10 continues test-gitleaks.sh from case (j). The hook's gitleaks job stays blind to NUL and `-diff` content by gitleaks' design (A-HYG-04G); the `sensitive-content` job and the attribute rule cover it locally, and CI covers it authoritatively.

## Self-Check: PASSED

- FOUND: scripts/tests/test-attributes.sh
- FOUND commits: 5fbe1f8, d1969f0, b90a8c6 (all ancestors of HEAD)

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
