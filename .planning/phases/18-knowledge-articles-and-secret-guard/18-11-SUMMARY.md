---
phase: 18-knowledge-articles-and-secret-guard
plan: 11
subsystem: knowledge
tags: [secret-guard, scanner, pem, redos, gap-closure]

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: SecretScanner, SecretFixtures (18-04) and the SaveArticle refusal path (18-05)
provides:
  - prefix-tolerant multi-line PEM private key rule (quotes, indents, tabs, escaped line break)
  - PEM_PRIVATE_KEY_ESCAPED rule for a key on one line (JSON string, YAML double-quoted scalar)
  - Default_Ignorable strip (INVISIBLE) and a rendered-equivalent second view per line (Markdown escapes, HTML entities)
  - windowed check of random runs longer than 256 characters and quoted password values with spaces
  - runtime-assembled fixtures for every new shape
affects: [18-13, verify-work]

actuals:
  tokens: 44000
  tasks: 3
  commits: 3

plan_head_before: 37303a461892c707f20b2bc6f4bc23f460c3759c
plan_head_after: b769e1f87af0d9e3733f44588e9d51456c8ab435
commits: 3

tech-stack:
  added: []
  patterns:
    - "Two views per line (raw and rendered), findings are the union keyed by rule and line, views are local variables"
    - "Fixed-count escaped key body (sixteen units) instead of a repeated group, to keep the PCRE JIT stack safe"
    - "Branch-reset group keeps capture group 1 the password value in every quote branch"

key-files:
  created: []
  modified:
    - app/Domain/Knowledge/Guard/SecretScanner.php
    - tests/Support/SecretFixtures.php
    - tests/Unit/Knowledge/SecretScannerTest.php
    - tests/Feature/Knowledge/SaveArticleTest.php

key-decisions:
  - "R-18-11-1 to R-18-11-6 implemented as written in the plan; no SaveArticle change was needed"
  - "The escaped line break after a base64 run and after the header is optional in the multi-line rule, so a YAML double-quoted scalar spread over lines is matched without a separate rule"

requirements-completed: [SEC-01, SEC-02]

duration: 55min
completed: 2026-11-25
status: complete
---

# Phase 18 Plan 11: Secret scanner gap closure Summary

**The secret scanner now blocks a PEM private key in every realistic embedding (CR-01), finds Blocking-class tokens hidden by Markdown escapes, entities and invisible characters (WR-01), and no longer passes a long random run or a quoted password with spaces silently (IN-01).**

## What was built

- **Task 1 (tracer, 488389c5):** `PEM_PRIVATE_KEY` takes a possessive prefix of up to 40 spaces, tabs and `>` before header fields, the blank line and both proven base64 lines, and an optional escaped line break after the header and after each proven run. `isBase64Line()` strips the same prefix and suffix. Fixtures `prefixedLines()` and `pemPrivateKeyShapes()` (plain, yaml block scalar) plus the Unit and SaveArticle proof.
- **Task 2 (17aa73b3):** `PEM_PRIVATE_KEY_ESCAPED` (header, escaped line break, up to four escaped header fields, exactly sixteen base64 or escaped-slash units) runs per line behind a `-----BEGIN ` pre-check. `RUN_WINDOW_LENGTH` (256) replaces the run cap; a longer run is checked in consecutive windows plus a final last-256 window and stops at the first passing one. `PASSWORD_ASSIGNMENT` has a branch-reset value group with double-quoted, single-quoted and unquoted branches. Eight more key shapes, `longRandomRun()`, `quotedPasswordAssignment()`, a negative test and the IN-01 tests.
- **Task 3 (b769e1f8):** `INVISIBLE` (the Default_Ignorable set as explicit ranges) replaces the zero-width class, `fold()` strips, applies NFKC and strips again. `rendered()` builds the second view (Markdown escapes removed, `html_entity_decode` with `ENT_QUOTES | ENT_HTML5`, decoded line breaks kept as the two characters backslash and n, folded again). `run()` scans both private key passes on both views and the line rules on every uncovered line for each differing view; findings are keyed by rule and line. Fixtures `markdownEscaped()`, `entityEncoded()`, `withInserted()`, `secretEvasionShapes()` (16 shapes) and tests at both layers.

## Verification done in the worktree

- `php -l` clean on the four files; `scripts/check-sensitive.sh` clean; lefthook (sensitive-content, gitleaks) passed on all three commits; `pint --test` passed on the four files.
- The Unit test file `SecretScannerTest.php` was run on host PHP 8.6 against the worktree classes through a scratch autoloader outside the repository (no database): 89 tests, 212 assertions, all passed, including all ten key shapes, all 16 evasion shapes, the 49 pre-existing cases and every new ReDoS row.
- Not run in the worktree (per CLAUDE.md): the Feature `SaveArticleTest.php`, `KnowledgeSeamTest`, PHPStan and the full suite. The three new SaveArticle tests and the post-merge DDEV commands of the plan are for the orchestrator to run on the HEAD branch.

## ReDoS timings (200 KB single input, host PHP, no `scan_failed`)

| Row | Time |
|---|---|
| repeated indented quoted pem header | 0.020 s |
| repeated escaped pem header | 0.004 s |
| repeated prefix-only lines | 0.065 s |
| repeated high entropy run | 0.002 s |
| repeated open quoted password | 0.008 s |
| repeated backslash underscore | 0.006 s |
| repeated decimal entity | 0.003 s |
| repeated named entity | 0.003 s |
| repeated entity line break | 0.004 s |
| repeated soft hyphen | 0.002 s |
| repeated escaped stripe prefix | 0.006 s |

Extra probes outside the repository: 200 KB of bidi marks 0.002 s, 20000 lines of `a\_b&#95;` 0.065 s, 30000 entity-only lines 0.030 s, 5000 escaped one-line headers 0.019 s.

## Accepted cost (R-18-11-6, T-18-68)

A long base64 blob on one line (an RSA public key, a data URI) and a quoted prose value after a secret-like name now give an Ambiguous finding. Both stay overridable with one acknowledgment (D-03) and the resulting flag can be cleared by hand (D-04). The false-positive class matches the existing treatment of a 64-character certificate line. Pre-existing tests of other Phase 18 plans that hold a single base64 line longer than 256 characters in an article body would now see an Ambiguous refusal; none was found by grep in the Knowledge test directories, but the full post-merge suite is the verdict.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Gitleaks flagged a ReDoS dataset row**
- **Found during:** Task 2 commit
- **Issue:** the row `'repeated escaped pem header'` and its sibling held a complete private key header literal, which the `private-key` gitleaks rule matched.
- **Fix:** both rows are assembled from two fragments (`'-----BEGIN PRIV'.'ATE KEY-----'`), as the repository rule requires; no allowlist entry added.
- **Files modified:** tests/Unit/Knowledge/SecretScannerTest.php
- **Commit:** 17aa73b3

**2. [Rule 3 - Blocking] Pint `single_quote` fixer**
- **Found during:** Task 3 pre-commit style check
- **Fix:** one double-quoted fragment turned into a single-quoted one.
- **Commit:** b769e1f8

Otherwise the plan was executed as written.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or file access; the scanner stays pure and holds no property.

## Self-Check: PASSED

- Files modified exist: SecretScanner.php, SecretFixtures.php, SecretScannerTest.php, SaveArticleTest.php (FOUND).
- Commits 488389c5, 17aa73b3 and b769e1f8 are ancestors of HEAD (FOUND).
