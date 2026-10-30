---
phase: 01-repository-hygiene
plan: 07
subsystem: infra
tags: [bash, git, awk, secret-scanning, tdd, gap-closure]

requires:
  - phase: 01-repository-hygiene
    provides: scripts/check-sensitive.sh producers, scripts/lib/diff2tsv.awk parser, test library (plans 01-01, 01-03)
provides:
  - content-complete producers for staged, --all, --history and explicit-file modes in scripts/check-sensitive.sh
  - diff_to_rows helper reading NUL bytes twice (as a space, and removed)
  - scripts/tests/test-content.sh (cases a to m) and the utf16le test helper
affects: [01-08, 01-09, 01-11, hygiene-ci, ship-gate]

requirements-completed: [HYG-02, HYG-05]

actuals:
  tokens: 5700
  tasks: 2
  commits: 3
plan_head_before: b1ebdcc1142579d2ffcac7152f3e0120e3f01e79
plan_head_after: bad8e840123dbb50bb446b002a6eaf2bd430c683
commits: 3

tech-stack:
  added: []
  patterns:
    - "Read content as text: git --text --no-textconv --no-renames --diff-filter=d in every producer"
    - "Two NUL readings (space and removed) under LC_ALL=C; findings deduplicated in first-seen order"
    - "Empty tree computed with hash-object -t tree /dev/null (no -w), so --all stays read-only"

key-files:
  created:
    - scripts/tests/test-content.sh
  modified:
    - scripts/check-sensitive.sh
    - scripts/tests/lib.sh
    - scripts/tests/test-modes.sh

key-decisions:
  - "NUL content is scanned (read twice) rather than refused: refusing would block every image, font or PDF"
  - "Merge commits use --diff-merges=first-parent, not -m: full coverage without re-diffing every other parent, and independent of log.diffMerges config"
  - "The empty tree is hashed without -w so --all never writes to the object database"

coverage:
  - id: D1
    description: "Staged content hidden by a -diff or binary attribute, a NUL byte, UTF-16, a textconv driver or a type change is reported with path:line and exit 1"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "scripts/tests/test-content.sh#(a) to (f)"
        status: pass
    human_judgment: false
  - id: D2
    description: "--all and --history read every file in full, including merge-only content and content moved out of a D-05 exempt path by a rename"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "scripts/tests/test-content.sh#(g) to (j)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Explicit-file mode scans every non-empty operand and prints no skip notice; binary content without a rule shape adds no finding"
    requirement: HYG-05
    verification:
      - kind: integration
        ref: "scripts/tests/test-content.sh#(k),(l); scripts/tests/test-modes.sh#(g)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The scanner stays read-only in every mode: index checksum, object count and a private TMPDIR are unchanged"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "scripts/tests/test-content.sh#(m)"
        status: pass
    human_judgment: false

duration: 5min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 07: Content-complete scanner producers Summary

**check-sensitive.sh now reads every staged, tracked, historical and explicitly named file in full: attributes, NUL bytes, UTF-16, textconv drivers, type changes, renames and merge-only content can no longer turn the first hygiene layer off.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-07T08:21:37Z
- **Completed:** 2026-10-07T08:25:40Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- A shared `diff_to_rows` step parses raw git output twice when NUL bytes are present: NUL as a space (keeps boundaries of NUL-separated strings) and NUL removed (exposes UTF-16 text). All `tr` calls run under `LC_ALL=C`; any failure exits 2.
- Staged and `--all` producers use `--text --no-textconv --no-renames --diff-filter=d`; `--all` diffs the index against the computed empty tree (no object written); `--history` adds `--diff-merges=first-parent`, `-c log.showRoot=true` and `--no-show-signature`, so evil merges and renames out of exempt paths are scanned.
- Explicit-file mode drops the binary skip (WR-05): only empty operands contribute nothing; the display path travels through `ENVIRON["CS_PATH"]`.
- Findings go through `$tmp/findings.txt` and are printed once each in first-seen order. No new flag, exit code or output format.
- New `scripts/tests/test-content.sh` (49 assertions, cases a to m) and the `utf16le` helper in `scripts/tests/lib.sh`.

## Task Commits

1. **Task 1: Tracer, staged content hidden by attributes, NUL, UTF-16, textconv or a type change** - `cb7efff` (feat)
2. **Task 2: --all, --history and explicit files read every file in full** (TDD)
   - RED: `93d98b9` (test)
   - GREEN: `bad8e84` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked).

## Files Created/Modified

- `scripts/check-sensitive.sh` - `diff_to_rows`, content-complete producers for all four modes, deduplicated findings output, updated header
- `scripts/tests/test-content.sh` - cases (a) to (m) across staged, --all, --history and explicit-file modes
- `scripts/tests/lib.sh` - `utf16le` helper (UTF-16LE bytes built at runtime)
- `scripts/tests/test-modes.sh` - case (g) now expects a binary operand to be scanned and reported

## Decisions Made

- Scan NUL content twice instead of refusing it (an image, font or PDF commit must stay possible; all generic rule shapes are ASCII).
- `--diff-merges=first-parent` instead of `-m` for merge commits (complete coverage, no repeated mainline diffs, independent of `log.diffMerges`).
- Empty tree hashed with `git hash-object -t tree /dev/null` and never `-w`.

## TDD Gate Compliance

Plan type is `execute`; Task 2 carried `tdd="true"`.

- **RED** (`93d98b9`): cases (g), (i), (k), (m) in test-content.sh and (g) in test-modes.sh failed on their planned assertions (exit 0 where 1 was required; named path missing from output). Cases (h), (j) and (l) already passed after Task 1 because the `--text`/`--no-renames` flags from the tracer cover them in history and staged mode. The RED evidence is plain assertion output, not a TAP/JUnit report, so `gsd check tdd-red-evidence` does not apply; semantic assessment: the target assertions executed and failed for the intended reason.
- One RED case, (i) evil merge, initially failed for a fixture reason as well (the helper's base file had no trailing newline, so the appended key was glued to `base` and no left boundary existed). The fixture was corrected in the GREEN commit, and (i) was then confirmed RED against the pre-change script (`cb7efff`: exit 0, "clean") and GREEN against the new one (exit 1, `m.txt:2@<merge sha7>`).
- **GREEN** (`bad8e84`): all test files pass.
- **REFACTOR:** none needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Evil-merge test fixture glued the appended key to the previous line**
- **Found during:** Task 2 (GREEN run, case (i))
- **Issue:** `stage` strips the trailing newline via `$(printf ...)`, so appending the fake to `m.txt` produced `base<key>` on one line and the key-prefix rule's left boundary rejected it
- **Fix:** the merge fixture writes `m.txt` with a real trailing newline
- **Files modified:** scripts/tests/test-content.sh
- **Verification:** case (i) green; reproduced manually that the pre-change script reports clean and the new one reports `m.txt:2@<sha7>`
- **Committed in:** bad8e84

---

**Total deviations:** 1 auto-fixed (1 test-fixture bug)
**Impact on plan:** None on scope; the fixture fix was needed for a valid RED/GREEN signal.

## Issues Encountered

None beyond the fixture bug above.

## Verification

- `bash scripts/tests/run.sh` (full): PASS 8 FAIL 0 SKIP 0
- `/bin/bash scripts/tests/run.sh --quick` (bash 3.2.57): PASS 6 FAIL 0 SKIP 2 (gitleaks and lefthook tests skip in quick mode by design)
- `bash scripts/tests/run-in-ubuntu.sh` (mawk, GNU tr): PASS 6 FAIL 0 SKIP 2, repository unchanged
- `scripts/check-sensitive.sh --all` and `--history` on this repository: exit 0
- `shellcheck` on all scripts: clean
- All acceptance greps pass (`--text --no-textconv --no-renames`, `--diff-filter=d`, no `ACMR`, `diff_to_rows()`, every `tr` under `LC_ALL=C`, `hash-object -t tree /dev/null` without `-w`, `--diff-merges=first-parent`, `log.showRoot=true`, no `git grep --cached` producer, no "skipping binary", `ENVIRON["CS_PATH"]`)

## Known Stubs

None.

## Threat Flags

None. No new network, auth, or trust-boundary surface; mitigations T-01-34, T-01-35 and T-01-36 are implemented and covered by tests.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

The first scanner layer is content-complete. Plan 01-08 (gitleaks layer and attribute guard) is independent and can proceed; the CI `--history` step benefits from merge-commit coverage with no workflow change. The A-CONTENT-01 note stands: a rare false positive inside binary assets needs a path-scoped allowlist entry (plan 01-11).

## Self-Check: PASSED

- FOUND: scripts/tests/test-content.sh
- FOUND commits: cb7efff, 93d98b9, bad8e84 (all ancestors of HEAD)

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
