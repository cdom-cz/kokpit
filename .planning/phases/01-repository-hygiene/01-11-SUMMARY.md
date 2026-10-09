---
phase: 01-repository-hygiene
plan: 11
subsystem: infra
tags: [documentation, gitignore, review-disposition, phase-gate, gap-closure]

requires:
  - phase: 01-repository-hygiene
    provides: content-complete producers, git-attributes rule, CI gitleaks options, locale-free denylist fold, home-path rules (plans 01-07 to 01-10)
provides:
  - CONTRIBUTING.md rewritten against the shipped scanner, denylist, CI and CODEOWNERS behaviour
  - Code-owner review decision item in the GitHub settings checklist
  - five new required phrases in scripts/tests/test-docs.sh, each proven by the mutation loop
  - credential and infrastructure-state ignore patterns in .gitignore with test verdicts (WR-09)
  - 01-REVIEW-DISPOSITION.md with a decision on all 18 review findings
  - full phase gate evidence on the real repository
affects: [hygiene-ci, ship-gate, phase-02]

requirements-completed: [HYG-01, HYG-03, HYG-06, HYG-07]

actuals:
  tokens: 3650
  tasks: 3
  commits: 3
plan_head_before: 82ed47139856f720eca435ba03ba56a285131bcc
plan_head_after: 8c2b4b86b461cef521b0f68379ec77388ddceb69
commits: 3

tech-stack:
  added: []
  patterns:
    - "Documentation claims are pinned by required phrases in test-docs.sh and proven by the remove-one-phrase mutation loop"
    - "New ignore patterns are placed after the negation they must not override, with an ignored and a trackable-neighbour verdict each"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - scripts/tests/test-docs.sh
    - .gitignore
    - scripts/tests/test-gitignore.sh
    - .planning/phases/01-repository-hygiene/01-REVIEW-DISPOSITION.md

key-decisions:
  - "CONTRIBUTING names the five D-05 exempt files exactly instead of 'the scanner and its libraries', because fold.awk is not exempt and the denylist scans all five"
  - "Code-owner review stays an unticked checklist item with the solo-maintainer constraint stated; the decision is the owner's"
  - "IN-01, IN-02, IN-04, IN-05 and IN-06 stay open with a stated reason each; all other findings are fixed with the plan that closed them"

coverage:
  - id: D1
    description: "CONTRIBUTING.md describes the shipped behaviour: full-content reading in every mode, merge and rename coverage in --history, no binary skip, locale-free diacritic folding, git-attributes rule, extended home-path forms, CI gitleaks options and the pre-commit limit"
    requirement: HYG-06
    verification:
      - kind: unit
        ref: "scripts/tests/test-docs.sh (34 assertions, five new phrases each with a mutation case)"
        status: pass
    human_judgment: true
    rationale: "Test-docs proves the phrases exist, not that every sentence is accurate; accuracy was checked against the code by hand and the owner should read the rewritten sections"
  - id: D2
    description: "The settings checklist carries the Require review from Code Owners decision with the solo-maintainer constraint and the CODEOWNERS path list"
    requirement: HYG-07
    verification:
      - kind: unit
        ref: "scripts/tests/test-docs.sh#phrase removed from CONTRIBUTING.md is reported: Require review from Code Owners"
        status: pass
    human_judgment: true
    rationale: "Whether to enable code-owner review is the owner's policy decision (human verification item 3)"
  - id: D3
    description: "Credential stores, signing keys, VPN configs and Terraform state are ignored; .env.example, .npmrc.example, terraform/main.tf, config/credentials.php and .claude/CLAUDE.md stay trackable"
    requirement: HYG-01
    verification:
      - kind: unit
        ref: "scripts/tests/test-gitignore.sh (80 assertions, git check-ignore --no-index on paths never created)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every review finding CR-01 to IN-06 has a recorded decision"
    requirement: HYG-03
    verification:
      - kind: other
        ref: "awk row count (18) and per-finding grep loops from the plan, all silent"
        status: pass
    human_judgment: false
  - id: D5
    description: "Full phase gate on the real repository is green"
    requirement: HYG-01
    verification:
      - kind: integration
        ref: "bash scripts/tests/run.sh; /bin/bash scripts/tests/run.sh --quick; bash scripts/tests/run-in-ubuntu.sh; check-sensitive --all and --history; gitleaks with CI options; planning scan; shellcheck; lefthook validate and check-install; actionlint; zizmor --offline"
        status: pass
    human_judgment: false

duration: 5min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 11: Documentation, ignore patterns, dispositions and phase gate Summary

**CONTRIBUTING.md now documents exactly what the scanner, the locale-free denylist fold and both gitleaks invocations do (docs test pins five new phrases with mutation proof), `.gitignore` blocks credential stores and Terraform state, all 18 review findings carry a decision, and the full phase gate is green on the real repository.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-07T08:54:03Z
- **Completed:** 2026-10-07T08:58:30Z
- **Tasks:** 3
- **Files modified:** 5 (four committed, one on-disk ledger)

## Accomplishments

- CONTRIBUTING.md: rule table updated (AKIA and ASIA keys, company-id after `-`, `_` or `/` with a note to write dates with separators, lower-case IBAN, spaced cz-account slash, public-ip after `-` or `_`, any-case hosting-host, the full home-path forms including Windows with the placeholder names, new `git-attributes` row). Modes now say every mode reads full content, `--history` covers merge commits (`--diff-merges=first-parent`) and renames, explicit files have no binary skip. Exemptions name the five exact exempt files and the narrow path-scoped allowlist entry for binary false positives. The denylist section describes the real fold (U+00C0 to U+017F, combining marks removed, byte-wise, locale-free, UTF-16 and Windows-1250 limits) and the stale "not Unicode-normalised" sentence is gone. CI section states the gitleaks `--log-opts` and the hook limit.
- GitHub settings checklist gained the unticked "Require review from Code Owners" item with the solo-maintainer constraint and the nine CODEOWNERS paths.
- `scripts/tests/test-docs.sh`: five phrases appended to CONTRIB_PHRASES; the existing mutation loop reports each one when removed (34 assertions, a missing or empty CONTRIBUTING.md still reported).
- `.gitignore` (WR-09): `.envrc`, `.npmrc`, `.netrc`, `.pgpass`, `.git-credentials` after the `!.env.example` negation; `id_ed25519*`, `id_ecdsa*`, `id_dsa*`, `*.p8`, `*.jks`, `credentials.json`, `service-account*.json`, `*.ovpn` in the credentials section; new Infrastructure state section with `*.tfstate` and `*.tfstate.*`. `test-gitignore.sh` asserts 17 ignored paths and 3 trackable neighbours with `--no-index` on paths never created.
- `01-REVIEW-DISPOSITION.md`: 18 well-formed rows. Fixed: CR-01 to CR-03, WR-01 to WR-09, IN-03, each naming its plan. Open with a stated decision: IN-01, IN-02, IN-04, IN-05, IN-06.

## Task Commits

1. **Task 1: Tracer, CONTRIBUTING.md states the shipped contract and the docs test enforces it** - `97317b8` (docs)
2. **Task 2: Credential files and infrastructure state are ignored (WR-09)** (TDD)
   - RED: `6f95256` (test)
   - GREEN: `8c2b4b8` (feat)
3. **Task 3: Dispositions and phase gate** - no commit: the ledger lives under `.planning/`, which stays untracked (`commit_docs: false`); the gate itself changes no file.

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked).

## Files Created/Modified

- `CONTRIBUTING.md` - hygiene reference rewritten against the code, new checklist item
- `scripts/tests/test-docs.sh` - five required phrases
- `.gitignore` - fifteen new patterns and one new section
- `scripts/tests/test-gitignore.sh` - verdicts for the new patterns and their neighbours
- `.planning/phases/01-repository-hygiene/01-REVIEW-DISPOSITION.md` - 18 decided rows (on disk only)

## Decisions Made

- The Exemptions paragraph lists the five exempt files exactly. The plan's wording ("the scanner, its libraries") would have been imprecise: `scripts/lib/fold.awk` is not in the D-05 skip list, which the denylist and the generic rules both scan.
- The code-owner review item is documented, not decided: the maintainer cannot approve their own pull request, so the owner chooses between enabling it with a bypass or leaving CODEOWNERS as review requests only.

## TDD Gate Compliance

Plan type is `execute`; Task 1 was the tracer and Task 2 carried `tdd="true"`.

- **Tracer gate:** the tracer's automated verify (`test-docs.sh`, `check-sensitive.sh CONTRIBUTING.md .claude/CLAUDE.md`) carries no `<human-check>`; it was re-run after the final edit and passed before Task 2 started. Tracer verified end-to-end, expansion continued, no checkpoint.
- **RED** (`6f95256`): `test-gitignore.sh` failed 17 assertions, one per new ignored path (`exit 1, want 0`), and passed the three trackable neighbours, as expected for a not-yet-added ignore rule. Semantic assessment: the target assertions ran and failed on the planned verdict for the intended reason (path not ignored), with no syntax, load or fixture fault. The evidence is plain assertion output, not a TAP or JUnit report, so `gsd check tdd-red-evidence` does not apply.
- **GREEN** (`8c2b4b8`): 80 assertions pass.
- **REFACTOR:** none needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Accuracy] Exemption sentence named "its libraries" although only two of the three scripts/lib files are exempt**
- **Found during:** Task 1 (checking the plan's sentence against the skip list in `scripts/check-sensitive.sh`)
- **Issue:** the plan text "the scanner, its libraries, the allowlist and `.gitleaks.toml` are exempt" would have promised an exemption for `scripts/lib/fold.awk`, which does not exist (the CR-02 class of error this plan exists to remove)
- **Fix:** the document lists the five exact paths
- **Files modified:** CONTRIBUTING.md
- **Verification:** matches the `skip[...]` list at `scripts/check-sensitive.sh` lines 283-293; `test-docs.sh` green
- **Committed in:** 97317b8

---

**Total deviations:** 1 auto-fixed (1 documentation accuracy)
**Impact on plan:** None on scope; the document is stricter than the plan text.

## Issues Encountered

None.

## Phase Gate Evidence (real repository, after the last commit `8c2b4b8`)

| Command | Result |
|---|---|
| `bash scripts/tests/run.sh` | exit 0, PASS 9 FAIL 0 SKIP 0, no FAIL lines |
| `/bin/bash scripts/tests/run.sh --quick` (bash 3.2) | exit 0, PASS 7 FAIL 0 SKIP 2 (gitleaks and lefthook tests skip in quick mode by design) |
| `bash scripts/tests/run-in-ubuntu.sh` (mawk) | exit 0, PASS 7 FAIL 0 SKIP 2 |
| `git status --porcelain` before and after the three suites | unchanged |
| `scripts/check-sensitive.sh --all` | exit 0, clean |
| `scripts/check-sensitive.sh --history` | exit 0, clean |
| gitleaks with the CI options (`--log-opts="--all --diff-merges=first-parent --text --no-textconv"`) | exit 0, 38 commits scanned, no leaks found |
| planning-documents scan (every `.md` under `.planning/`, `.planning/config.json`, `.claude/CLAUDE.md`, including plans and summaries 01-07 to 01-11) | exit 0, clean |
| `shellcheck scripts/*.sh scripts/tests/*.sh` | exit 0 |
| `lefthook validate` / `lefthook check-install` | exit 0 / exit 0 |
| `actionlint` | exit 0 |
| `zizmor --offline .github/workflows` | exit 0, no findings |
| `git status --porcelain -- .github scripts lefthook.yml .gitleaks.toml .gitignore CONTRIBUTING.md` | empty (all committed) |
| ledger checks (18 well-formed rows; per-finding decision loops) | all silent, pass |

Task 1 and Task 2 acceptance criteria were re-run and pass, including `ok - phrase removed from CONTRIBUTING.md is reported: Require review from Code Owners`, `ok - ignored: .envrc`, `ok - ignored: service-account-test.json`, `ok - trackable: .npmrc.example`, the negation order check, and the check that none of the newly ignored names is already tracked.

## Known Stubs

None.

## Threat Flags

None. No new network, auth or trust-boundary surface. T-01-52 (credential files), T-01-53 (documentation versus code), T-01-54 (no outward-facing action: nothing was pushed, no pull request opened, no setting or `commit_docs` changed, no history rewritten, hook never bypassed) and T-01-55 (planning scan) are mitigated and evidenced above. T-01-SC: no package installs.

## User Setup Required

None - no external service configuration required.

## Owner's Next Steps (three human verification items of 01-VERIFICATION.md, unchanged)

1. **First GitHub run.** Push `base-crm-erp`, open the pull request, confirm `Sensitive-content and secret scan`, `Workflow lint` and the new `CI Passed` check are green and the PR is mergeable. This is also the first GitHub run with the new gitleaks log options.
2. **GitHub settings.** Tick the CONTRIBUTING.md checklist: secret scanning, push protection (optionally prove with a fake key on a throwaway branch), ruleset requiring a PR, `CI Passed` and Code Owner review, Actions SHA-pinning policy.
3. **Owner decisions.** Code-owner review for a solo maintainer (WR-06; the new checklist item explains the bypass trade-off) and whether to switch `commit_docs` on.

## Next Phase Readiness

Gaps 1 to 3 of 01-VERIFICATION.md are closed in code (plans 01-07 to 01-10) and in documentation (this plan). The phase is ready for re-verification. Open review items IN-01, IN-02, IN-04, IN-05 and IN-06 carry explicit decisions and revisit points (Phase 2 CI and `.gitignore` review, Phase 3 infrastructure).

## Self-Check: PASSED

- FOUND: CONTRIBUTING.md, scripts/tests/test-docs.sh, .gitignore, scripts/tests/test-gitignore.sh, .planning/phases/01-repository-hygiene/01-REVIEW-DISPOSITION.md
- FOUND commits (all ancestors of HEAD): 97317b8, 6f95256, 8c2b4b8

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
