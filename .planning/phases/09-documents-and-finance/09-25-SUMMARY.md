---
phase: 09-documents-and-finance
plan: 25
subsystem: documents
tags: [documents, zip-archive, gap-closure, cr-01, entry-names, regression]

requires:
  - phase: 09-documents-and-finance
    provides: "09-19 ArchiveEntryNames and BuildDocumentArchive; 09-17 RequestDocumentArchive"
provides:
  - "ArchiveEntryNames::unique() total for every order of names: a missing counter counts as 1 and every handed-out name has its own counter"
  - "Queued end-to-end tests of the CR-01 file set (a.pdf, a.pdf, a (2).pdf) in both orders, ending Ready with the ready bell only"
  - "Unit regressions: both CR-01 orders, a mixed-case order, all 720 orders of six names under a warnings-to-exceptions handler"
affects: [09-26 BuildDocumentArchive hardening, 09-28 review disposition, phase 9 re-verification]

actuals:
  tokens: 2000
  tasks: 2
  commits: 2
plan_head_before: c01ec00a5059e4c96c825f9b87a7e5918010135b
plan_head_after: 3719a52b613d80cdba70613fc2f03e9fa57c2a29
commits: 2

tech-stack:
  added: []
  patterns:
    - "Unit tests that must fail like production install set_error_handler() that throws ErrorException and restore it in finally"

key-files:
  created: []
  modified:
    - app/Domain/Documents/Support/ArchiveEntryNames.php
    - tests/Feature/Documents/DocumentArchiveTest.php
    - tests/Unit/Documents/ArchiveEntryNamesTest.php

key-decisions:
  - "The fix is the 09-REVIEW.md CR-01 sketch: counter read with ?? 1, candidate key computed once, every handed-out name registered in taken with its own counter of 1"
  - "BuildDocumentArchive stays untouched: a total unique() needs no guard, and a try/catch would hide a later regression"

requirements-completed: [DO-02]
status: complete
---

# Phase 9 Plan 25: ArchiveEntryNames CR-01 Summary

**ArchiveEntryNames::unique() no longer reads an unset counter, so the background ZIP of a.pdf, a.pdf and a (2).pdf (in either order) ends ready with three distinct entries and only the ready bell.**

## Tasks and commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Tracer: queued ZIP of the CR-01 file set (RED test, then fix) | b6d4fc9 | app/Domain/Documents/Support/ArchiveEntryNames.php, tests/Feature/Documents/DocumentArchiveTest.php |
| 2 | Unit regressions: both orders, mixed case, every permutation | 3719a52 | tests/Unit/Documents/ArchiveEntryNamesTest.php |

## RED evidence (Task 1)

Before the fix, the queued test of `a.pdf`, `a.pdf`, `a (2).pdf` failed through the sync queue with:

`ErrorException: Undefined array key "a (2).pdf"` at `app/Domain/Documents/Support/ArchiveEntryNames.php:90` (called from `BuildDocumentArchive.php:238`).

The reverse-order test passed before and after the fix, as the plan expected (it guards the order that already worked). After the fix: `--filter='CR-01'` shows both feature tests green, the full `tests/Feature/Documents tests/Unit/Documents` run is 297 passed, Pint and PHPStan clean.

## Mutation self-check (Task 2)

- Reverting only the default read (`$this->counts[$key] ?? 1` back to a plain read) did NOT fail the suite: with the registration line `$this->counts[$candidateKey] = 1` in place, a taken key always has a counter, so the default is a defensive second layer, not the only fix.
- Reverting both (plain read and no `counts[$candidateKey] = 1`) failed 3 unit cases with `Undefined array key "a (2).pdf"`: the a.pdf / a.pdf / a (2).pdf case, the mixed-case case and the 720-order permutation invariant. Source was restored with `git checkout`; `git diff` of the source is empty afterwards.

## Deviations from Plan

### Plan wording corrected

**1. [Mutation self-check scope]** The plan's mutation (default read only) cannot fail the suite, because the registration of numbered names already makes the counter exist. Ran the stronger mutation (both parts) to prove the tests catch the original bug. No source or test change resulted. The two-part fix itself is exactly as planned.

No Rule 1-3 auto-fixes were needed. No further gap was exposed by the permutation test.

## Known Stubs

None.

## Threat Flags

None. T-09-80 (denial of service by an unset counter) is mitigated by the fix, the queued feature tests, the 720-order invariant and the mutation check. T-09-81 (numbered names escaping the folder) holds: numbering only inserts ` (n)` before the extension of a name that already passed `safe()`, and the invariant asserts pairwise distinct names case-insensitively.

## Human verification still open

The DDEV browser pass of 09-VERIFICATION.md (build a ZIP from the central listing, download it from the bell link) stays a human item, to be run after plans 09-25 and 09-26 have landed.

## Self-Check: PASSED

- FOUND: app/Domain/Documents/Support/ArchiveEntryNames.php, tests/Feature/Documents/DocumentArchiveTest.php, tests/Unit/Documents/ArchiveEntryNamesTest.php
- FOUND: b6d4fc9, 3719a52 (both ancestors of HEAD)
