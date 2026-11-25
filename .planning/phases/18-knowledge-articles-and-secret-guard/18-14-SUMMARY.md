---
phase: 18-knowledge-articles-and-secret-guard
plan: 14
subsystem: knowledge
tags: [secret-guard, pem-private-key, scanner, gap-closure, redos, control-characters]

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: scanner fold and escaped one-line key rule (18-11), create-screen blocking dataset (18-13)
provides:
  - per-line private key check in SecretScanner (header line with an empty tail, bounded look-ahead, decoration trimmed in code, one long line or two lines)
  - control characters (C0 except tab, DEL, C1) in the fold, so they no longer hide a Blocking-class token
  - eleven new key shapes and five control-character shapes, assembled at runtime
  - screen-level proof for one-line and decorated keys
affects: [verify-work, 18-VERIFICATION]

tech-stack:
  added: []
  patterns:
    - "Line-oriented key detection in code (header line, bounded look-ahead, trim() range list) instead of a multi-line pattern over the joined text"

key-files:
  created: []
  modified:
    - app/Domain/Knowledge/Guard/SecretScanner.php
    - tests/Support/SecretFixtures.php
    - tests/Unit/Knowledge/SecretScannerTest.php
    - tests/Feature/Knowledge/CreateKnowledgeArticleTest.php

key-decisions:
  - "R-18-14-1 to R-18-14-6 implemented as written"
  - "The header-match offset check uses an isset($match[0]) guard (PHPStan level 8: the match array may be empty), not a cast"
  - "base64Line() maps plus and slash to the letters x and Z, so a boundary row of exact length is never trimmed by the key check and the boundary test cannot flake"

requirements-completed: [SEC-01, SEC-02]

actuals:
  tokens: 8000
  tasks: 3
  commits: 3

plan_head_before: d3538fef6f400f3744eaac713d2b75b5998ed4e8
plan_head_after: 090ad447cc5c9ef8d9f3b071ac3c099e86a993a5
commits: 3
duration: 35min
completed: 2026-11-25
status: complete
---

# Phase 18 Plan 14: One-line and decorated private keys block Summary

A PEM private key with a one-line body (PKCS#8 Ed25519, an unwrapped 1600-character body) or with comment, list, quote, concatenation or continuation decoration or a blank line between its base64 lines is now one Blocking `pem_private_key` finding on its header line, so `SaveArticle` and the create screen refuse it with no save-anyway path (SEC-02, D-01); control characters no longer hide a Blocking-class token (SEC-01).

`commits: 3` counts the three task commits measured before this SUMMARY was written; the SUMMARY commit is a fourth.

## What was built

- **Task 1 (856fa968):** the multi-line private key pattern and the fixed prefix-character constant are gone. A line is a key header line when `PEM_PRIVATE_KEY_HEADER` (anchored with `\G`) matches at the last `-----BEGIN ` in the line and the rest of the line has an empty core (`keyCore()`: right-trim of every non-alphanumeric byte via the `KEY_EDGE` range list, an escaped line break dropped, then left-trim). `keyBodyEnd()` looks at most 8 lines ahead: one blank line is skipped (a second ends the look-ahead), up to 4 header fields (`PEM_HEADER_FIELD`) are skipped before the body, a body line is a core of 16 or more base64-only characters, and the key is proven by a first body line of 40 or more characters or by the second body line. `scanPrivateKeys()` covers header to proving line, extends over following base64 lines and over one blank line directly followed by one, and records one finding on the header line; the escaped one-line rule is unchanged. `isBase64Line()` is built on `keyCore()`. Fixtures: `pemPrivateKeySingleLine()`, `wrappedLines()`, `base64Line()` and eleven new `pemPrivateKeyShapes()` entries (ed25519 single line, fenced and YAML block scalar; single long line; hash comment, double slash comment, list item; quoted lines with trailing comma; string concatenation; backslash continuation; blank line between base64 lines). Tests: "applies the key body limits: one line of 40 or two lines of 16 characters, header fields and one blank line", "does not report a private key header followed by prose, a commented sentence or a short line", and five new 200 KB ReDoS rows.
- **Task 2 (e93e6cc6):** five new cases in `createArticleBlockingShapes()` (`ed25519 single line key`, `ed25519 fenced key`, `long single line key`, `hash comment key`, `quoted lines with trailing comma key`), docblock updated; the test body is unchanged.
- **Task 3 (090ad447):** `INVISIBLE` gains U+0000-U+0008, U+000B, U+000C, U+000E-U+001F and U+007F-U+009F (tab stays; LF and CR never reach a line). Five new `secretEvasionShapes()` entries (start of heading, backspace, delete, next line, operating system command) written as `\u{...}` escapes, and a `repeated start of heading` ReDoS row. Docblocks name the controls next to the Default_Ignorable set.

## Verification

Done in the worktree (host PHP 8.6, worktree-local `composer install --no-scripts --no-interaction` from the committed lock into the git-ignored vendor directory; no package added):
- RED then GREEN, proved against the HEAD scanner restored for one run: Task 1 shapes and boundary test gave 12 failures at HEAD (the 11 new shapes plus the key-body-limit test), Task 3 shapes gave 5 failures before the fold change.
- GREEN: `tests/Unit/Knowledge` plus `tests/Arch/KnowledgeSeamTest.php`: 212 passed, 14 warnings (the warnings come from other files, none from the scanner tests); `SecretScannerTest.php` alone 113 tests.
- `php -l`, `scripts/check-sensitive.sh` and lefthook (sensitive-content, gitleaks) clean on every commit; `pint --test` passes on all touched files; PHPStan level 8 with a scratch config (no Larastan, outside the repository) on `app/Domain/Knowledge/Guard`: no errors (the first run found one `offsetAccess.notFound`, fixed with an `isset` guard).
- Plan greps of Tasks 1 to 3 hold (removed constants 0, new constants and methods 1, `KEY_SINGLE_LINE_MIN_LENGTH` 2, `PEM_HEADER_FIELD` 2, control-range needles 1 each).

### 200 KB ReDoS timings (host, one run each, limit 1 s, no `scan_failed`)

| Row | Time |
|-----|------|
| repeated commented pem header | 0.026 s |
| repeated header with blank and field lines | 0.031 s |
| repeated header and decorated short line | 0.027 s |
| repeated single-line key (2150 findings) | 0.008 s |
| repeated decoration characters | 0.006 s |
| repeated start of heading | 0.003 s |
| repeated pem header (existing row, for reference) | 0.026 s |

**Gates not run here (per CLAUDE.md, worktree; for the orchestrator on the HEAD branch after the merge):**
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge/SecretScannerTest.php tests/Feature/Knowledge/SaveArticleTest.php` (all 21 key shapes and the five control shapes through the real save path with the acknowledgment set).
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/CreateKnowledgeArticleTest.php` (eleven blocking-shape cases; a failure there points to the scanner, not the test).
- Plan gate: `pest tests/Unit/Knowledge tests/Feature/Knowledge tests/Arch/KnowledgeSeamTest.php`, `pint --test`, `phpstan analyse` (repository config), then the CI-equivalent full run `pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (never `--parallel`).
- No migrations were run.

## Review finding dispositions (18-REVIEW-GAP-CLOSURE.md)

| Finding | Disposition |
|---------|-------------|
| CR-01 (one-line key body is only Ambiguous) | Fixed by Task 1; proven on the create screen by Task 2 |
| WR-01 (decorated key lines are only Ambiguous) | Fixed by Task 1 (decoration trimmed in code, one blank line tolerated); two shapes proven on the screen by Task 2 |
| WR-03 (control character hides a Blocking-class token) | Fixed by Task 3 |
| WR-02 (key flattened onto one line with spaces stays Ambiguous) | Not addressed, advisory per 18-VERIFICATION, unchanged |
| WR-04 (window dilution in the Ambiguous tier) | Not addressed, advisory per 18-VERIFICATION, unchanged |
| IN-01 (base64-only lines after a key are covered) | Not addressed, advisory per 18-VERIFICATION, unchanged (the key already blocks the save) |

## Deviations from Plan

None - plan executed exactly as written. One in-task correction: PHPStan level 8 flagged `$match[0]` in `endsInPrivateKeyHeader()` as possibly missing; fixed with an `isset($match[0])` guard before commit (Rule 1, no behaviour change).

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; the scanner stays pure, findings still carry rule and line only (D-03), every new secret sample is assembled at runtime (D-05).

## Self-Check: PASSED

- Files: scanner, fixtures, unit test, create-screen test all present and modified.
- Commits 856fa968, e93e6cc6 and 090ad447 are ancestors of HEAD.
