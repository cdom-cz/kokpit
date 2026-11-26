---
phase: 19-folders-tags-and-version-history
plan: 12
subsystem: knowledge
tags: [tests, save-article, concurrency, redaction-token]
requires:
  - phase: 19-01
    provides: KnowledgeArticle::redaction_no
provides:
  - Phase 18 save, edit-screen and concurrent-save proofs on the update() signature with the required redaction number
affects: [19-04]
tech-stack:
  added: []
  patterns: [redaction number read together with version number before every save]
key-files:
  created: []
  modified:
    - tests/Feature/Knowledge/SaveArticleTest.php
    - tests/Feature/Knowledge/EditKnowledgeArticleTest.php
    - tests/Concurrency/knowledge-save-worker.php
key-decisions:
  - "Callers pass (int) $article->redaction_no (cast because models returned by create() are not re-read, so the DB default is not hydrated); the archived-article call passes the literal 0"
requirements-completed: [VER-05]
status: complete
commits: 2
plan_head_before: d658fbaf9d0918c2b3ce2f34e82680be0b4b9940
plan_head_after: 3cd4f10f
actuals:
  tokens: 6000
  tasks: 2
  commits: 2
---

# Phase 19 Plan 12: SaveArticle::update() callers on the redaction-aware signature Summary

Every existing caller of `SaveArticle::update()` in the Phase 18 tests and in the concurrency worker now passes the expected redaction number (R-19-12-1, D-13), so the proofs of the one save path keep their substance.

## What was done

- Task 1 (commit a1b89238): 35 `update()` calls in SaveArticleTest and 1 in EditKnowledgeArticleTest gained the fifth positional argument. Calls that passed the acknowledgment positionally (`$acknowledge`, `true`, `false`, 8 calls, not only the two the plan named) now read `..., $version, <redaction>, <ack>)`. No assertion, fixture or helper changed.
- Task 2 (commit 3cd4f10f): the concurrency worker reads `redaction_no` with `version_no` before the barrier (mode once) and re-reads both from one row query on every chain attempt, i.e. after each StaleArticle.

## Diff review (T-19-61)

`git diff -U0` of the three files shows only `update(` call lines plus, in the worker, the two read lines and the comment. The call with an archived article passes literal 0 (the article is not found before the token matters).

## Deviations from Plan

**1. [Rule 2 - Correctness] Cast the redaction number**
- **Found during:** Task 1
- **Issue:** articles returned by `create()` and `KnowledgeFixtures::article()` are not re-read, so `$article->redaction_no` is unhydrated (null) and would be a TypeError for an `int` parameter.
- **Fix:** `(int) $article->redaction_no` in the test calls.
- **Files modified:** SaveArticleTest.php, EditKnowledgeArticleTest.php

**2. [Scope note] More positional acknowledgments than the plan listed**
- The plan expected two positional acknowledgment calls; the grep showed eight (lines with `$acknowledge`, `false`, `true` as the fifth argument). All were shifted to the sixth position.

## Verification

- `php -l` clean on all three files; `grep -cF redaction_no` on the worker prints 3; `grep -cE "update\(.*, 1, true\)"` on SaveArticleTest prints 0.
- NOT run in the worktree (no DB or vendor): `pest tests/Feature/Knowledge` and `pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` (alone). They type-check only against the 19-04 signature, so they must run after the whole wave 2 is merged (R-19-12-2), one Pest process at a time, never `--parallel`.

## Known Stubs

None.

## Threat Flags

None.

## Self-Check: PASSED

- Files modified exist; commits a1b89238 and 3cd4f10f are in the branch history.
