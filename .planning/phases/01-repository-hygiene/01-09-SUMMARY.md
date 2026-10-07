---
phase: 01-repository-hygiene
plan: 09
subsystem: infra
tags: [bash, awk, denylist, diacritics, utf-8, locale, gap-closure]

requires:
  - phase: 01-repository-hygiene
    provides: unfiltered rows.tsv and the findings collection in scripts/check-sensitive.sh (plan 01-07), denylist stage and test file (plan 01-03)
provides:
  - scripts/lib/fold.awk, a locale-free fold (ASCII case, Latin-1 Supplement and Latin Extended-A letters to base letters, combining marks removed)
  - denylist stage that folds the term list and the scanned path and text identically and matches byte-wise under LC_ALL=C
  - denylist coverage of the five D-05 exempt paths
  - test-denylist.sh cases (c) unconditional and (l) to (t)
affects: [01-11, hygiene-ci, ship-gate]

requirements-completed: [HYG-03]

actuals:
  tokens: 3200
  tasks: 2
  commits: 3
plan_head_before: b90a8c6ef98692fc7e0504bd8a9fac8f36f69847
plan_head_after: 87a4e04b7373d46c913dba803690c6eff7e063d6
commits: 3

tech-stack:
  added: []
  patterns:
    - "Fold both sides with the same awk program, then compare as fixed strings under LC_ALL=C: no locale, no iconv, no grep -i"
    - "Table-driven UTF-8 fold: one 64-token list per lead byte, self-checked in BEGIN, string literals under 120 characters for mawk"
    - "Denylist reads unfiltered rows; the D-05 exemption applies only to the generic rules"

key-files:
  created:
    - scripts/lib/fold.awk
  modified:
    - scripts/check-sensitive.sh
    - scripts/tests/test-denylist.sh

key-decisions:
  - "Fold both sides (not 'document case-insensitive only'): the phase goal is a guarantee, and names without diacritics are how clients usually appear in slugs, file names and e-mail local parts"
  - "iconv transliteration rejected: glibc and macOS libiconv transliterate differently, so a developer machine and CI would disagree"
  - "The fold is applied to the term list after sanitising and the result is trimmed again, so a term such as 'mark space mark' cannot collapse to a lone space that matches every line"
  - "A 64-escape continuation-byte literal is built from four 16-escape literals (the plan's two 32-escape literals would be 130 characters, over the 120-character mawk limit)"

patterns-established:
  - "Same fold on terms and text, output only path:line: denylist (case (r) asserts the term, its folded form and the line text are absent)"

coverage:
  - id: D1
    description: "A denylist term written with diacritics blocks the same name written without them, and the reverse, as path:line: denylist (CR-02)"
    requirement: HYG-03
    verification:
      - kind: integration
        ref: "scripts/tests/test-denylist.sh#(c),(l),(m),(o)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Composed and decomposed spellings match; a term that folds to nothing is dropped and the no-terms notice applies"
    requirement: HYG-03
    verification:
      - kind: integration
        ref: "scripts/tests/test-denylist.sh#(n),(q)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The denylist reports terms in the five D-05 exempt paths while the generic rules still skip them (WR-01)"
    requirement: HYG-03
    verification:
      - kind: integration
        ref: "scripts/tests/test-denylist.sh#(p); scripts/tests/test-check-sensitive.sh#(h)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Results do not depend on the system locale, and an ASCII term is found in UTF-16 and Windows-1250 text (WR-08)"
    requirement: HYG-03
    verification:
      - kind: integration
        ref: "scripts/tests/test-denylist.sh#(c),(s),(t); scripts/tests/run-in-ubuntu.sh"
        status: pass
    human_judgment: false
  - id: D5
    description: "No term, folded term or matched line text appears in any output"
    requirement: HYG-03
    verification:
      - kind: integration
        ref: "scripts/tests/test-denylist.sh#(r)"
        status: pass
    human_judgment: false

duration: 4min
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 09: Locale-free denylist fold Summary

**The denylist now folds terms and scanned text through one awk program (`scripts/lib/fold.awk`), so a client name typed with or without diacritics, in any case, composed or decomposed, is caught with no locale involved, including inside the five D-05 exempt files.**

## Performance

- **Duration:** 4 min
- **Started:** 2026-10-07T08:35:39Z
- **Completed:** 2026-10-07T08:39:49Z
- **Tasks:** 2
- **Files modified:** 3 (1 created)

## Accomplishments

- `scripts/lib/fold.awk`: `tolower` under `LC_ALL=C` for ASCII, then table-driven byte replacement for U+00C0 to U+017F (multiplication and division signs left alone, sharp s to `ss`, thorn to `th`, ligatures to two letters) and removal of combining marks U+0300 to U+036F. Three 64-token tables per lead byte plus the mark tables, with a BEGIN self-check (exit 2 on a miscounted list).
- `scripts/check-sensitive.sh`: the term list is sanitised (CR and NUL dropped), folded, trimmed again and emptied of empty lines into `deny.fold`; a fold failure exits 2 with `denylist fold error`. The scanned `path<TAB>text` copy of the unfiltered `rows.tsv` is folded the same way and matched with `LC_ALL=C grep -F -a -n -f`. The UTF-8 locale override and `-i` are gone. Header text updated.
- `scripts/tests/test-denylist.sh`: case (c) is unconditional (the SKIP branch is gone) and (l) to (t) are new; 70 assertions in total.
- Gap 2 reproduction from 01-VERIFICATION.md (term with diacritics, staged line without) now exits 1 with `path:line: denylist`. WR-01 and WR-08 are fixed and tested.

## Task Commits

1. **Task 1: Tracer, a term with diacritics blocks the same name written without them** - `180b37c` (feat)
2. **Task 2: Decomposed forms, exempt paths, empty folds, other encodings, locale independence** (TDD)
   - RED: `cccc1f9` (test)
   - GREEN: `87a4e04` (feat)

**Plan metadata:** not committed (`commit_docs: false`; `.planning/` stays untracked).

## Files Created/Modified

- `scripts/lib/fold.awk` - locale-free fold program shared by the term list and the scanned text
- `scripts/check-sensitive.sh` - denylist state block and stage rewritten around the fold, unfiltered rows, header text
- `scripts/tests/test-denylist.sh` - cases (c) and (l) to (t), helpers `r_check` and `run_loc`

## Decisions Made

See `key-decisions` in the frontmatter. In short: fold rather than weaken the documented contract, no `iconv`, a second trim after the fold so a fold result of only whitespace cannot become a match-everything pattern.

## TDD Gate Compliance

Plan type is `execute`; Task 1 is a tracer and Task 2 carried `tdd="true"`.

- **Tracer gate:** the new (l) and (m) cases were run against the unchanged script first (exit 0 where 1 was required: 4 assertions failed), then green after the implementation. The tracer's automated verify (denylist test, quick run, Ubuntu container run, shellcheck) was re-run and passed before Task 2 started. No `<human-check>` is attached, so no checkpoint was raised.
- **RED** (`cccc1f9`): against the Task 1 state, (n) (composed vs decomposed, both directions) and the (q) notice assertion failed on their planned assertions (exit 0 where 1 was required; "has no terms" notice missing because a lone combining mark was still a non-empty pattern). The RED evidence is plain assertion output, not a TAP or JUnit report, so `gsd check tdd-red-evidence` does not apply. Semantic assessment: the target assertions executed and failed for the intended reason. (o), (p), (r), (s), (t) already passed after Task 1 because the table and the unfiltered-row stage were implemented in the tracer; they are regression cases for the behavior list.
- **GREEN** (`87a4e04`): all test files pass.
- **REFACTOR:** none needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Continuation-byte literal exceeded the mawk literal limit**
- **Found during:** Task 1 (writing fold.awk)
- **Issue:** the plan asked for two concatenated 32-escape literals of the 64 continuation bytes; each is 128 characters plus quotes, contradicting the plan's own "every string literal shorter than 120 characters" contract for mawk
- **Fix:** four concatenated literals of 16 escapes each (66 characters)
- **Files modified:** scripts/lib/fold.awk
- **Verification:** the Ubuntu container run (mawk) passes; ASCII-only and line-length checks pass
- **Committed in:** 180b37c

**2. [Rule 2 - Missing critical] Second trim after the fold**
- **Found during:** Task 1 (term-list pipeline)
- **Issue:** a term such as a combining mark between spaces survives the first trim and folds to a lone space, which as a fixed pattern matches every line containing a space (the empty-pattern hazard of T-01-45, one step removed)
- **Fix:** the folded list is trimmed again before empty lines are dropped
- **Files modified:** scripts/check-sensitive.sh
- **Verification:** case (q) (lone mark) passes; the pipeline is the same code path
- **Committed in:** 180b37c

---

**Total deviations:** 2 auto-fixed (1 bug in the plan text, 1 missing critical)
**Impact on plan:** None on scope. Both keep the plan's own contracts true.

## Issues Encountered

None.

## Verification

- `bash scripts/tests/run.sh` (full): PASS 9 FAIL 0 SKIP 0
- `/bin/bash scripts/tests/run.sh --quick` (bash 3.2): PASS 7 FAIL 0 SKIP 2 (gitleaks and lefthook tests skip in quick mode by design)
- `bash scripts/tests/run-in-ubuntu.sh` (mawk, GNU tools): exit 0, PASS 7 FAIL 0 SKIP 2, repository status unchanged
- `scripts/check-sensitive.sh --all` and `--history` on this repository: exit 0
- `shellcheck scripts/check-sensitive.sh scripts/tests/test-denylist.sh`: clean
- Acceptance checks: `fiktivni klient`, `fiktivni x`, `muller zloty` and the unchanged multiplication sign all produced exactly as specified; `fold.awk` is ASCII-only; no `LC_ALL=C.UTF` and no `-i` on the matcher in the script; no `SKIP: (c)` in the test.

## Known Stubs

None.

## Threat Flags

None. No new network, auth or trust-boundary surface; mitigations T-01-42 to T-01-46 are implemented and covered by cases (l) to (t).

## Flagged Assumption (carried from the plan)

A-HYG-03G: folding covers UTF-8 text. Accented letters stored as UTF-16 or in a legacy single-byte encoding are not folded (only their ASCII letters match), and letters outside U+00C0 to U+017F (Greek, Cyrillic, Vietnamese extended Latin) compare exactly as written. Widening is local to `scripts/lib/fold.awk`. The owner should confirm this scope.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Gap 2 (CR-02), WR-01 and WR-08 are closed in code. CONTRIBUTING.md still describes the old matching and is updated in plan 01-11.

## Self-Check: PASSED

- FOUND: scripts/lib/fold.awk, scripts/check-sensitive.sh, scripts/tests/test-denylist.sh
- FOUND commits: 180b37c, cccc1f9, 87a4e04 (all ancestors of HEAD)

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
