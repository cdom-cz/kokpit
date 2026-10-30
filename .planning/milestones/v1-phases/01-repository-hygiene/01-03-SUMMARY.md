---
phase: 01-repository-hygiene
plan: 03
subsystem: infra
tags: [bash, awk, denylist, git-history, github-actions, secret-scanning]

requires:
  - phase: 01-repository-hygiene (plan 01)
    provides: check-sensitive.sh driver (staged and --all), diff2tsv.awk, scan.awk, test harness, hygiene.yml scan job
provides:
  - KOKPIT_DENYLIST local denylist stage (case-insensitive, Czech diacritics folded, never echoes term or line text)
  - explicit FILE operand mode with repository-relative reporting from any sub-directory
  - --history mode over git log --all with findings naming the introducing commit (path:line@sha7)
  - argument parser with mode/operand conflict handling and --help
  - CI step running scripts/check-sensitive.sh --history over the full fetched history
affects: [01-04, 01-05, 01-06, phase-02-ci]

plan_head_before: 1ec38368d02cf6250eb22fbe04522ae9b777f420
plan_head_after: aa41cbb84d9a21da0bec0a3f197404dc658a728d

actuals:
  tokens: 7300
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Denylist matched against a path+text projection of the scanned rows (line-number field dropped), reported as path:line only"
    - "Commit capture in the diff parser: a 'commit <hash>' line outside a hunk turns the line field into line@sha7"
    - "Explicit operands pass to awk as ./-prefixed paths so dashes and name=value operands are never options or assignments"

key-files:
  created:
    - scripts/tests/test-denylist.sh
    - scripts/tests/test-modes.sh
  modified:
    - scripts/check-sensitive.sh
    - scripts/lib/diff2tsv.awk
    - scripts/tests/lib.sh
    - .github/workflows/hygiene.yml

key-decisions:
  - "Denylist grep uses -a (treat as text) next to -F -i: GNU grep under C.UTF-8 otherwise answers 'Binary file matches' for invalid UTF-8 and would silently drop a hit"
  - "Relative KOKPIT_DENYLIST paths resolve against the caller's directory (captured before cd to the repository root)"
  - "In-repository check compares pwd -P of the denylist's directory with pwd -P of the toplevel, equal or prefix-with-slash; a sibling directory sharing the repository name prefix is allowed (tested)"
  - "Explicit relative operands are lexically normalised (sub/../x -> x) with a small awk helper, so reported paths are clean repository-relative paths"
  - "A path that itself contains a denylist term is reported by path (path is the finding); only the term as typed in the list and the line text are never printed"

patterns-established:
  - "One scanner, many producers: staged diff, git grep --cached, git log -p and plain files all emit path<TAB>line<TAB>text and share D-05 exclusions, allowlist and denylist"
  - "Tests for an exit-code contract assert both the code and that the sensitive string is absent from the combined output"

requirements-completed: [HYG-03, HYG-05]

coverage:
  - id: D1
    description: "KOKPIT_DENYLIST terms (case-insensitive, diacritics folded) block a staged change as path:line: denylist without printing the term or the line text; blank, whitespace, comment and CRLF lines never match everything"
    requirement: HYG-03
    verification:
      - kind: unit
        ref: "scripts/tests/test-denylist.sh#(b)-(d),(h),(j),(k)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Unset or missing denylist degrades to generic rules with one stderr notice and unchanged exit code; unreadable or in-repository denylist exits 2"
    requirement: HYG-03
    verification:
      - kind: unit
        ref: "scripts/tests/test-denylist.sh#(a),(e),(f),(g),(i)"
        status: pass
    human_judgment: false
  - id: D3
    description: "scripts/check-sensitive.sh FILE... scans explicit files with repository-relative paths from a sub-directory; parser rejects mode/operand conflicts and unknown options; --help exits 0"
    requirement: HYG-03
    verification:
      - kind: unit
        ref: "scripts/tests/test-modes.sh#(a)-(j)"
        status: pass
    human_judgment: false
  - id: D4
    description: "--history reports a secret that exists only in an old commit as path:line@sha7: rule [masked] and exits 0 on a clean history; the Hygiene scan job runs it without a denylist"
    requirement: HYG-05
    verification:
      - kind: unit
        ref: "scripts/tests/test-modes.sh#(k)-(q)"
        status: pass
      - kind: other
        ref: "actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows"
        status: pass
    human_judgment: true
    rationale: "The workflow has still never run on GitHub (nothing is pushed); only static lint and the local equivalent of the step were exercised. A first PR run proves the full-history step on the runner."

duration: 10min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 03: Denylist, Explicit Files and History Scan Summary

**check-sensitive.sh gains a local KOKPIT_DENYLIST stage that never echoes terms, explicit FILE operands, and a --history mode that names the introducing commit, run by the CI scan job over the full history**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-06T22:25:00Z (approximate)
- **Completed:** 2026-10-06T22:36:00Z
- **Tasks:** 2 (Task 1 tracer, Task 2 TDD)
- **Files modified:** 6 (2 created, 4 modified)

## Accomplishments

- A fictional term from a denylist outside the repository blocks a staged line (any case, Czech diacritics folded) and only `path:line: denylist` is printed; tests assert that the term and the line text never appear. Without the variable the script prints one notice and its exit code is unchanged (the CI path).
- Misconfiguration fails loudly: unreadable denylist and a denylist inside the repository (physical-path comparison) both exit 2, so a real list can never be committed through this tool.
- `--history` over `git log --all` finds a secret that only existed in a removed commit and reports `path:line@<sha7>: rule [masked]`; `--all` on the same repository is clean. Explicit files, `--staged`, `--all` and `--history` share the same exclusions, allowlist and denylist.
- The Hygiene `scan` job runs `scripts/check-sensitive.sh --history` right after the `--all` step; actionlint and zizmor are clean.
- Quick suite green under bash 5 and `/bin/bash` 3.2 on macOS, and in `ubuntu:24.04` (mawk 1.3.4, GNU grep, bash 5.2) through `run-in-ubuntu.sh`, where the diacritic-folding case (c) also ran.

## Task Commits

1. **Task 1: Tracer - denylist stage** - `c0aaf51` (feat)
2. **Task 2 (TDD RED): failing tests for explicit files, mode conflicts, --history** - `8624a43` (test)
3. **Task 2 (TDD GREEN): explicit files, --history, parser, CI step** - `aa41cbb` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked until hygiene tooling passes on it).

## TDD Gate Compliance

- **RED:** `test-modes.sh` was run before any implementation: 15 assertions passed (conflict cases already exited 2 for the wrong reason) and 29 failed with exit 2 where exit 0/1 and specific `path:line@sha7` output were planned. The failing assertions are the planned behaviours (explicit file reporting, `--help`, history findings), not syntax or fixture errors. Semantic assessment: valid RED.
- **GREEN:** same file after implementation: 44 passed, 0 failed (`aa41cbb`).
- **REFACTOR:** none needed.

## Files Created/Modified

- `scripts/check-sensitive.sh` - argument parser, denylist state checks and matching stage, explicit-file and history producers, `normpath` helper
- `scripts/lib/diff2tsv.awk` - `commit <hash>` capture, line field becomes `line@sha7` in history mode
- `scripts/tests/test-denylist.sh` - 36 assertions over every denylist state with fictional terms
- `scripts/tests/test-modes.sh` - 44 assertions: explicit files, parser conflicts, history cases
- `scripts/tests/lib.sh` - `unset KOKPIT_DENYLIST` so a developer's real list cannot leak into any test
- `.github/workflows/hygiene.yml` - "Sensitive-content check (full history, no local denylist)" step

## Decisions Made

See `key-decisions` above. Summary: `grep -a` for invalid UTF-8 safety, caller-relative denylist path, physical-path in-repo check with sibling-name safety, lexical normalisation of explicit operands.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Test isolation from a developer's real denylist**
- **Found during:** Task 1
- **Issue:** The test libraries inherit the caller's environment. A real `KOKPIT_DENYLIST` exported in the developer's shell would be applied to every test run (unrelated tests could fail on a short real term, and real-denylist state would shape test output).
- **Fix:** One line in `scripts/tests/lib.sh`: `unset KOKPIT_DENYLIST`; denylist tests set the variable explicitly per command.
- **Files modified:** scripts/tests/lib.sh (not in the plan's files list)
- **Verification:** full quick suite green on bash 5, bash 3.2 and ubuntu
- **Committed in:** c0aaf51

**2. [Rule 2 - Missing Critical] `grep -a` in the denylist matcher**
- **Found during:** Task 1
- **Issue:** GNU grep under `C.UTF-8` reports "Binary file matches" instead of the line for rows containing invalid UTF-8, which would silently drop a denylist hit.
- **Fix:** `grep -F -i -a -n -f ...` (the plan's literal `grep -F -i` acceptance pattern is preserved).
- **Files modified:** scripts/check-sensitive.sh
- **Committed in:** c0aaf51

---

**Total deviations:** 2 auto-fixed (2 missing critical)
**Impact on plan:** Both are correctness requirements for the denylist guarantee; no scope creep.

## Issues Encountered

- A term that occurs in a file's path is reported with that path (case h), so the term is visible in the finding when a path contains it. That is inherent to the `path:line` output contract; the line text and the list itself are never printed. Worth a sentence in the docs plan (01-04).
- Scanning this repository's history with `--history` reports exactly one finding, the known one: `.planning/codebase/STACK.md:50@5554573: home-path [/U*** (16 chars)]`. It is resolved by plan 01-06. No other findings, no false positives.
- Pre-existing nuance, not changed: git-quoted paths (names with quotes or tabs) are not unquoted by the diff parser.

## Flagged assumption A-HYG-03 (carried from the plan, still unresolved)

Denylist terms are fixed strings matched case-insensitively under `LC_ALL=C.UTF-8`. They are not Unicode-normalised: an NFC term does not match NFD content. One- and two-character terms are noisy (a one-digit term is tested harmless against line numbers because the numeric field is dropped). Without a `C.UTF-8` locale, folding degrades to ASCII only. The owner should confirm these semantics are acceptable for real client names and IDs. Assumption A-HYG-05 (merge commits not diffed by `git log -p`) is noted in the driver and flagged for 01-05.

## Known Stubs

None.

## Threat Flags

None. The new surfaces (denylist file read, `git log` over all refs) are the ones in the plan's threat model (T-01-13 to T-01-17), each with a test.

## User Setup Required

None - no external service configuration required. To use the denylist locally, export `KOKPIT_DENYLIST` pointing at a file outside the repository (documented in plan 01-04).

## Next Phase Readiness

- Plan 01-04 (docs) can document the denylist format and the three scan modes.
- Plan 01-05 adds gitleaks next to the `--history` step; plan 01-06 clears the STACK.md finding that the history scan still reports.

## Self-Check: PASSED

- Files exist: scripts/tests/test-denylist.sh, scripts/tests/test-modes.sh, scripts/check-sensitive.sh, scripts/lib/diff2tsv.awk, .github/workflows/hygiene.yml
- Commits are ancestors of HEAD: c0aaf51, 8624a43, aa41cbb
- `git rev-list --count 1ec3836..HEAD` = 3 (matches `commits: 3`)
- All acceptance criteria re-run: `--all --history` exits 2, `--help` exits 0 with the usage line, `--history` step follows the `--all` step in hygiene.yml, quick suite and shellcheck/actionlint/zizmor clean

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
