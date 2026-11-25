---
phase: 18-knowledge-articles-and-secret-guard
plan: 04
subsystem: knowledge
tags: [secret-scanner, markdown, commonmark, pcre, localisation]

requires:
  - phase: 01-repository-hygiene
    provides: repository hygiene tooling (check-sensitive, gitleaks) that gates the fixtures
provides:
  - SecretScanner returning rule and line only, with a blocking/ambiguous split and fail-closed handling
  - MarkdownNormaliser that normalises prose lines and keeps every code byte
  - Tests\Support\SecretFixtures, the only source of secret samples for later plans
  - lang/cs/knowledge.php, the complete Czech string tree of the phase
affects: [18-05, 18-06, 18-07, 18-08, 18-09, 18-10]

actuals:
  tokens: 13160
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Findings carry a rule enum and a line number, never matched text"
    - "Scanner folds each line (zero-width removal, NFKC) before the rules and fails closed on any PCRE error"
    - "Code lines are decided by CommonMark AST spans, not by patterns over backticks"

key-files:
  created:
    - app/Domain/Knowledge/Guard/Severity.php
    - app/Domain/Knowledge/Guard/SecretRule.php
    - app/Domain/Knowledge/Guard/SecretFinding.php
    - app/Domain/Knowledge/Guard/SecretScanner.php
    - app/Domain/Knowledge/Support/MarkdownNormaliser.php
    - tests/Support/SecretFixtures.php
    - tests/Unit/Knowledge/SecretScannerTest.php
    - tests/Unit/Knowledge/MarkdownNormaliserTest.php
    - tests/Feature/Knowledge/KnowledgeLangTest.php
    - lang/cs/knowledge.php
  modified: []

key-decisions:
  - "Every Stripe key blocks (R-18-04-1); test-mode keys have their own rule stripe_test_key; only the AWS documentation key is exempt"
  - "D-10 applied literally (R-18-04-5): no byte inside fenced or indented code changes, line endings included"
  - "The PEM pattern proves exactly two base64 lines; the remaining base64 lines of the key are covered by a plain loop, because a repeated group over thousands of lines exhausts the PCRE JIT stack"
  - "Overlap suppression uses disjoint sorted spans with binary search, so a line with tens of thousands of matches stays linear"

patterns-established:
  - "SecretFixtures: every sample assembled at runtime from fragments, str_repeat, base64 or random bytes"
  - "needles(): three 12-character slices of a secret for leak assertions"

requirements-completed: [SEC-01, SEC-02, VER-01]

duration: 15min
completed: 2026-11-25
status: complete
plan_head_before: 37d02d02ce17e0197662cf354a108fc8000e35ab
plan_head_after: 94d6440e4573f34a4542250c0b00146ab18139e4
---

# Phase 18 Plan 04: Secret Scanner, Markdown Normaliser and Czech Strings Summary

**Pure secret scanner that names rule and line but never the value (D-01 blocking/ambiguous split, fail-closed), an AST-driven Markdown normaliser that leaves every code byte untouched, runtime-assembled secret fixtures, and the full Czech string file of the phase.**

## Performance

- **Duration:** about 15 min
- **Tasks:** 3 of 3
- **Files created:** 10

## Accomplishments

- `SecretFinding` holds exactly `rule` and `line` (reflection test); `var_export`, `json_encode` and `toArray()` of findings contain none of the fixture needles (T-18-17).
- Ten `SecretRule` cases. Blocking: PEM private key, AWS access key, Stripe secret (live secret and restricted keys, webhook secret), Stripe test key (`sk_test_`, `rk_test_`), GitHub token, JWT, URL credentials and `scan_failed`. Ambiguous: password assignment and high-entropy string.
- Placeholders and prose stay quiet: placeholder URL passwords, `changeme`, `${VAR}`, `<...>`, `{{...}}`, `%...%`, masked values, `your-...`, the AWS documentation key, UUIDs, hex digests, lowercase word runs and snake_case identifiers.
- Evasion is folded: fullwidth letters and zero-width characters inside a line are found on the same line number; mixed `\r\n`, `\r`, `\n` endings keep editor line numbers.
- Fail closed: invalid UTF-8 and any PCRE error (proved with `pcre.jit=0`, `pcre.backtrack_limit=1`) return exactly `[scan_failed@0]`.
- `MarkdownNormaliser` normalises prose lines only and is idempotent; proved on fixed fixtures (backtick, tilde, quoted and list-item fences, indented code, unclosed fences, CRLF, lone CR, trailing whitespace) and on 200 bodies generated from `mt_srand(18)`. A mutation (treating every line as prose) makes 10 of 14 normaliser tests fail, so the property tests are not vacuous.
- `lang/cs/knowledge.php` holds all 77 strings of R-18-04-7, one label per `SecretRule`, all in NFC.

## Task Commits

1. **Task 1 (tracer): finding types, PEM rule, fixtures, Czech strings** - `feat(knowledge): add secret finding types and a private key scan`
2. **Task 2: all scanner rules, placeholders, evasion, fail-closed, ReDoS** - `feat(knowledge): add all secret scanner rules with placeholder handling`
3. **Task 3: normaliser** - `feat(knowledge): add markdown normaliser that keeps code bytes intact`

Hashes: 729d514 (task 1), 9baa3a8 (task 2), 94d6440 (task 3); the range is `plan_head_before..plan_head_after` in the frontmatter.

## ReDoS measurements (200 KB per input, host PHP 8.6, JIT on, whole `scan()` wall time)

| Input | Time |
|-------|------|
| repeated `A` | 5 ms |
| repeated `eyJabcdefgh.` | 7 ms |
| repeated `a://` | 2 ms |
| repeated PEM header line | 19 ms (worst) |
| repeated `password=` | 3 ms |
| repeated `a:b:` | 2 ms |
| repeated `AKIA` | 3 ms |
| repeated `sk_live_` | 2 ms |
| repeated `ghp_` | 2 ms |
| repeated run characters | 2 ms |
| `password` + spaces repeated | 2 ms |
| repeated `a://a:` | 2 ms |
| repeated `a://b:c@` (25 000 matches) | 14 ms |

Extra ad-hoc inputs (not in the suite): 100 000 one-character lines 25 ms; 20 000 ten-character lines 49 ms; a 3 000-line key 4 ms. The test limit is 1 second per input.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Quadratic overlap check in the scanner**
- **Found during:** Task 2 (first ReDoS run: 1.4 s for repeated `a://b:c@`, 284 ms for repeated JWT head)
- **Issue:** each match was compared with every earlier span, so a line with tens of thousands of matches was quadratic in PHP.
- **Fix:** rules run as groups; spans of earlier rules are kept as a sorted disjoint list and checked by binary search (`overlaps()`); matches of one rule never overlap each other.
- **Files modified:** `app/Domain/Knowledge/Guard/SecretScanner.php`
- **Commit:** Task 2 commit

**2. [Rule 1 - Bug] Long PEM key produced scan_failed**
- **Found during:** Task 2 (ad-hoc 8 000-line key)
- **Issue:** a repeated group `{2,}` over thousands of base64 lines exhausted the PCRE JIT stack, so a legitimate long key was refused as `scan_failed` instead of reported as `pem_private_key`.
- **Fix:** the pattern proves exactly two base64 lines; the remaining base64 lines are covered by a plain `strspn` loop. Test added (3 000-line key reports one finding on the header line).
- **Files modified:** `app/Domain/Knowledge/Guard/SecretScanner.php`, `tests/Unit/Knowledge/SecretScannerTest.php`
- **Commit:** Task 2 commit

**3. [Rule 3 - Blocking] Wrong namespace for the GFM extension**
- **Found during:** Task 3
- **Issue:** `GithubFlavoredMarkdownExtension` lives in `League\CommonMark\Extension`, not in a sub-namespace (the research snippet omitted the import).
- **Fix:** corrected the import. No behavioural change.
- **Commit:** Task 3 commit

### Plan details tightened

- Password assignment allows optional spaces around the separator (`db password = value`, the fixture shape) and an optional `Bearer`/`Basic` word before the value; `$VAR` references count as placeholders. AWS and Stripe/GitHub left boundaries are look-behinds on alphanumerics instead of `\b`, and Stripe/GitHub tails are possessive without a trailing boundary so an over-long token is still found.
- Task 1 commit message was first written with the attribution line before the body; the commit was amended (own, unpushed commit) so the trailer is last.

## Known Stubs

None.

## Threat Flags

None. The plan adds pure classes and test support; no endpoint, auth path, file access or schema.

## Verification status

- Run in the worktree (host PHP 8.6, `composer install --no-scripts`, vendor not committed): `pest tests/Unit/Knowledge` 63 tests, 982 assertions pass; Pint clean; PHPStan on `app/Domain/Knowledge` and `SecretFixtures` reports no errors (run with `APP_ENV=testing` and a dummy key); `scripts/check-sensitive.sh` and gitleaks clean on every commit through the hook.
- Not run here (needs DDEV after the merge): `tests/Feature/Knowledge/KnowledgeLangTest.php` and `tests/Feature/Localisation/LangEncodingTest.php` (Feature tests boot the app on the database); the plan gate command of the plan's `<verification>`. The lang file was checked directly: 77 strings, all valid UTF-8 and NFC.

## Self-Check: PASSED

- Files exist: all ten files of `key-files.created` found.
- Commits exist: the three task commits are ancestors of HEAD.
