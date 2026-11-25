---
phase: 18-knowledge-articles-and-secret-guard
verified: 2026-11-25T22:00:00Z
status: human_needed
score: 7/7 must-haves verified
covered_files:
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-01-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-01-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-02-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-02-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-03-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-03-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-04-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-04-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-05-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-05-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-06-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-06-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-07-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-07-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-08-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-08-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-09-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-09-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-10-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-10-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-11-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-11-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-12-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-12-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-13-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-13-SUMMARY.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-14-PLAN.md
  - .planning/phases/18-knowledge-articles-and-secret-guard/18-14-SUMMARY.md
  - app/Domain/Knowledge/Actions/ArticleInput.php
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Guard/SecretScanner.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - resources/js/knowledge-editor.js
  - tests/Feature/Knowledge/CreateKnowledgeArticleTest.php
  - tests/Support/SecretFixtures.php
  - tests/Unit/Knowledge/SecretScannerTest.php
covered_digest: "v4:sha256:d749631c724f0db7e170c4ed8754d7784cda75793caae758cdcc21036f93e28a"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 6/7
  gaps_closed:
    - "SC3b: a PEM private key with a one-line base64 body (PKCS#8 Ed25519, an unwrapped 1600-character body), in plain, fenced and YAML-indented form, and a key behind hash, double-slash, list-dash, quote-and-comma, string-concatenation or trailing-backslash decoration or with one blank line between its base64 lines, is now exactly one Blocking pem_private_key finding on its header line (gap closure 18-14, reproduced against the real class)"
    - "WR-03 of the gap-closure review (advisory): a C0 control, DEL or C1 control inside a Blocking-class token no longer hides it (reproduced)"
  gaps_remaining: []
  regressions: []
advisory:
  - finding: "A key flattened onto one line with spaces (CI or env-var style) is only an Ambiguous high_entropy_string (review WR-02 of the gap-closure review)"
    category: security
    reason: "Hardening, not a gap: the save is still flagged, needs acknowledgment and marks the article sensitive. Not changed by 18-14 (declared out of scope)"
    evidence_status: "reproduced in the previous verification; not re-run"
  - finding: "A 43-character random token directly after 300 repeated letters gives no finding (window dilution, review WR-04)"
    category: security
    reason: "Hardening in the Ambiguous tier, needs deliberate padding. Not changed by 18-14 (declared out of scope)"
    evidence_status: "reproduced in the previous verification; not re-run"
  - finding: "After a matched private key, following base64-only lines are covered and skipped by the line rules (review IN-01 of the gap-closure review)"
    category: other
    reason: "Harmless for the first scan because the key already blocks the save; a later secret may surface only after the key is removed"
    evidence_status: "code read: scanPrivateKeys() extension loop; unchanged behaviour class"
  - finding: "A header followed by one base64-only line of 39 characters or fewer, or a public key header with two body lines, is only an Ambiguous high_entropy_string (or nothing below 32 characters)"
    category: other
    reason: "Intended boundary of R-18-14-3 (40-character floor for one line, 16 for two) and the public-key header is correctly not a private key; the save is still flagged as Ambiguous where the line is long and random. Recorded so the boundary is visible"
    evidence_status: "reproduced in this verification"
human_verification:
  - test: "Open an article's edit screen via SPA navigation (from the list or the view screen), switch the editor tab to Preview and back to Write twice, and look at the toolbar; repeat after navigating to a second article's edit screen the same way"
    expected: "The task-list toolbar button (D-07) is present after the first and every later Preview to Write switch and still toggles the task marker on the selected lines"
    why_human: "WR-02 of the first review is fixed in code (the observer in resources/js/knowledge-editor.js watches document.documentElement instead of document.body, which Livewire navigate replaces) and a script test pins the needle, but the observer behaviour is runtime browser behaviour. Run the filament assets publish step first so the compiled copy under public is current."
  - test: "On a view screen with a fenced code block, click the Copy button and paste the clipboard into a scratch field"
    expected: "The pasted text equals the code exactly (no highlighting markup, no line numbers, no header text); the label switches to the copied state and resets after about two seconds; on a browser that refuses the clipboard the code is selected and the failed label shows"
    why_human: "Clipboard behaviour is runtime browser behaviour. The server markup (one button per block, data attributes) and the script's text-only contract are tested, the click itself is not."
  - test: "Open the view screen of an article holding a table, a task list, a link, highlighted code and a NOTE callout, and compare it with the edit screen's Preview tab"
    expected: "All five render as formatted blocks, the callout is highlighted, preview and view look identical"
    why_human: "Visual appearance. Parity is guaranteed in code (both use the one renderer and the normaliser), the look is not."
---

# Phase 18: Knowledge Articles and Secret Guard Verification Report

**Phase Goal:** Admin can write, read and maintain Markdown knowledge articles that render safely, while no save stores a secret unnoticed or silently overwrites a concurrent change
**Verified:** 2026-11-25
**Status:** human_needed
**Re-verification:** Yes, after gap closure plan 18-14 (merged as db75ff4f)

## Goal Achievement

The one remaining gap, SC3b, is closed, and I confirmed it against the real `SecretScanner` class rather than the SUMMARY. A scratch probe outside the repository (host PHP, fictional random bodies assembled at runtime from fragments, no secrets in git) fed the shapes that failed last time to the scanner. Every one of them now gives exactly one Blocking `pem_private_key` finding on the header line. All seven truths are verified in code. What remains is the same three browser-behaviour checks as before, which no automated check can settle, so the status is `human_needed`, not `passed`. There are no gaps and no blockers.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: Admin creates an article (title and Markdown body) from General knowledge in the Knowledge group, opens it on its own view screen, edits it on a separate edit screen whose server-rendered preview matches the view screen; an archived article leaves the active list and can be restored | VERIFIED | Unchanged since the previous verification. 18-14 touched no resource, page or renderer file (`git` file list of the plan: scanner, fixtures, two test files). Orchestrator reports Knowledge tests green (350 passed). |
| 2 | SC2: The view screen renders tables, task lists, links, highlighted code and `> [!NOTE]` callouts; every code block has a Copy button that copies its exact text; hostile input shows as escaped text or dropped links, never live markup | VERIFIED (click and look under human verification) | Unchanged. Server markup and script contract tested; the click itself and the visual look are human items below. |
| 3 | SC3a: A body containing an unambiguous secret in its canonical shape is refused with a message naming rule and line, never the value | VERIFIED | Re-probed: plain RSA-shaped key gives `pem_private_key` Blocking on line 1, AWS-shaped id gives `aws_access_key` Blocking. `SaveArticle::acknowledgedRules()` (line 181 `$this->scanner->scan($body)`, line 193 throws `SecretGuardRefused`) refuses every Blocking finding whatever `$acknowledge` says; findings carry rule and line only. |
| 4 | SC3b: Unambiguous secrets block the save in the forms they realistically take (D-01: a PEM private key has no override) | VERIFIED | Gap closed, see the reproduction table below. The old multi-line pattern and the fixed prefix class are gone (`grep -cF "private const string PEM_PRIVATE_KEY = "` prints 0); the check is now per line in code (`endsInPrivateKeyHeader()`, `keyBodyEnd()`, `keyCore()`, `SecretScanner.php` lines 295-424). |
| 5 | SC3c: An ambiguous finding saves only after explicit acknowledgment, marks the article sensitive, the override covers one save only and is logged without the content | VERIFIED | Unchanged mechanism (`SaveArticle`, `GuardsSecrets::saveAnyway()`); 18-13's create-screen test now runs eleven blocking-shape cases including the five new key shapes (`'ed25519 single line key' =>` present). |
| 6 | SC4: A content-changing save raises the version by exactly one and keeps an immutable version; a save without changes creates no version; two edit screens saving from the same starting version: the second is rejected with a notice | VERIFIED | Unchanged (`SaveArticle::update()` under `lockForUpdate()`, `StaleArticle`, unique `(article_id, version_no)`, frozen-version trigger). Feature suite reported green. IN-02 stays deferred. |
| 7 | SC5: A Partner sees no Knowledge group and is refused on every knowledge screen and URL; screens and routes are declared in the Partner permission matrix and the Partner audit stays green | VERIFIED | Unchanged. No matrix, policy or scope file touched by 18-14; Isolation and Audit results reported green in the previous verification and the full-suite run after the merge shows no Knowledge or Partner failure. |

**Score:** 7/7 truths verified (0 present, behavior-unverified)

### SC3b reproduction (real `SecretScanner`, host PHP 8.6, scratch probe outside the repository)

| Shape | Previous result | Now |
|-------|-----------------|-----|
| Header, one 64-character base64 line, footer (PKCS#8 Ed25519), plain | high_entropy_string Ambiguous | pem_private_key Blocking, line 1 |
| Same in a fenced block | Ambiguous | Blocking, header line 2 |
| Same indented under a YAML `key: \|` scalar | Ambiguous | Blocking, header line 2 |
| Header, one 1600-character line, footer | Ambiguous | Blocking, line 1 |
| Two-line key with `# ` prefix on every line | Ambiguous x2 | Blocking, line 1 |
| Two-line key with `// ` prefix | Ambiguous x2 | Blocking, line 1 |
| Two-line key with `- ` list prefix | Ambiguous x2 | Blocking, line 1 |
| Lines wrapped in quotes with a trailing comma | Ambiguous x2 | Blocking, line 1 |
| String-concatenation lines (quote, escaped line break, quote, plus) | Ambiguous | Blocking, line 1 |
| Trailing backslash continuation | Ambiguous | Blocking, line 1 |
| One blank line between the base64 lines | Ambiguous | Blocking, line 1 |
| Plain RSA-shaped, five-line OpenSSH-shaped, tab-indented in a fence, blockquote, JSON string with escaped newlines, Ed25519 body with escaped newlines on one line | Blocking | Blocking (no regression; the plain and escaped Ed25519 spellings now agree) |

Every key was a fresh `random_bytes()` value and every header kind was assembled from fragments (D-05).

Boundary and negative controls (these must NOT become Blocking):

| Input | Result |
|-------|--------|
| A sentence that names the header and continues | no finding |
| Header followed by a prose line | no finding |
| Header followed by a commented sentence | no finding |
| Header followed by a 30-character base64 row | no finding |
| Header followed by a 39-character row | Ambiguous high_entropy_string only (below the 40 floor, by design) |
| Header, a 16-character row, a 15-character row | no finding |
| Header alone | no finding |
| Header, two blank lines, a 64-character row | no pem_private_key (the row is Ambiguous) |
| A public key header with two base64 lines | no pem_private_key (Ambiguous lines only) |

Control-character hardening (WR-03): an AWS-shaped key id with U+0001, U+0008, U+000B, U+007F, U+0085, U+009D or U+200B inside gives one Blocking `aws_access_key` on its line each (previously none for the controls).

Performance (200 KB inputs, host, limit 1 s, none gave `scan_failed`): repeated commented headers 0.021 s, repeated header with blank and field lines 0.035 s, repeated header with a decorated short line 0.063 s, repeated single-line keys (2000 findings) 0.011 s, repeated decoration characters 0.008 s, repeated start-of-heading 0.005 s.

### Deferred Items

None. SEC-01's restore clause is Phase 19 (VER-03); it does not cover the scanner shapes above.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Knowledge/Guard/SecretScanner.php` | Per-line key check, control fold, fail closed | VERIFIED | Exists and substantive (651 lines); `keyBodyEnd()`, `keyCore()`, `endsInPrivateKeyHeader()`, `PEM_PRIVATE_KEY_HEADER`, `KEY_EDGE`, `KEY_SINGLE_LINE_MIN_LENGTH`, `PEM_HEADER_FIELD` present; control ranges `\x{0000}-\x{0008}` and `\x{007F}-\x{009F}` in `INVISIBLE`; every `preg_*` goes through `checked()` or `withoutInvisible()` |
| `app/Domain/Knowledge/Actions/SaveArticle.php` | Single write path refusing every Blocking finding | VERIFIED, WIRED | Calls `$this->scanner->scan($body)` and throws `SecretGuardRefused` |
| `tests/Support/SecretFixtures.php` | One-line, wrapped and decorated shapes, runtime-assembled | VERIFIED | `pemPrivateKeySingleLine()`, `wrappedLines()`, `base64Line()` and the new shape entries present |
| `tests/Unit/Knowledge/SecretScannerTest.php` | Shape, boundary, negative and ReDoS proof | VERIFIED | Run by me on host PHP: 113 passed, 271 assertions, including the `repeated start of heading` row |
| `tests/Feature/Knowledge/CreateKnowledgeArticleTest.php` | Screen proof for the new shapes | VERIFIED | `ed25519 single line key`, `ed25519 fenced key`, `long single line key`, `hash comment key`, `quoted lines with trailing comma key` cases present; Feature run reported green post-merge |
| `app/Domain/Knowledge/Actions/ArticleInput.php`, `RefusesFailedSaves.php`, `Create/EditKnowledgeArticle.php`, `GuardsSecrets.php`, `resources/js/knowledge-editor.js`, renderer, normaliser, archive and restore Actions, migrations, matrix rows, `lang/cs/knowledge.php` | as before | VERIFIED | Unchanged by 18-14 |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `SaveArticle` | `SecretScanner` | `acknowledgedRules()` calls `scan($body)`; any Blocking finding throws | WIRED | Lines 181-193 |
| `SecretScanner` | `SecretRule::PemPrivateKey` | per-line key check and the escaped one-line rule both record it (Blocking) | WIRED | Lines 324 and 330; the real class reports `blocking` in the probe |
| `SaveArticleTest` | `SecretFixtures::pemPrivateKeyShapes()` | loop over every shape with the acknowledgment set | WIRED | Line 612 |
| `CreateKnowledgeArticleTest` | `SecretFixtures::pemPrivateKeyShapes()` | `createArticleBlockingShapes()` picks the new shapes by name | WIRED | Cases present |
| Pages | `SaveArticle`, failed-save notice, guard UI, one renderer, Partner matrix, editor toolbar | as in the previous verification | WIRED | Untouched |

### Data-Flow Trace (Level 4)

Unchanged: view screen, preview, list and guard callouts flow from real sources (DB row, live form state, scanner findings). The scanner findings that feed the callouts are produced from the submitted body by the code read above, not a static value.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Scanner unit suite | `php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge/SecretScannerTest.php` (host, pure class, no DB) | 113 passed, 271 assertions | PASS |
| One-line, fenced, YAML-indented and 1600-character keys | scratch probe against the real class | one Blocking pem_private_key each | PASS |
| Hash, slash, list, quote-and-comma, concatenation, backslash and blank-line decorated keys | same | one Blocking pem_private_key each | PASS |
| Header mentions without a key body | same | no pem_private_key | PASS |
| Control characters inside an AWS-shaped id | same | one Blocking aws_access_key each | PASS |
| 200 KB adversarial rows | same | at most 0.063 s, no scan_failed | PASS |
| Scanner and fixture debt markers | grep for TBD, FIXME, XXX, TODO, HACK in `app/Domain/Knowledge` and `tests/Support/SecretFixtures.php` | none | PASS |

Post-merge results reported by the orchestrator, not re-run by me in DDEV as instructed: Knowledge tests 350 passed, Pint and PHPStan clean. The full suite had 11 failures, all in S3 and storage tests that need a local bucket (`tests/Feature/Documents/DeleteDocumentTest.php`, `TemporaryUploadS3Test.php`, `tests/Feature/Operations/StorageCheckTest.php`); neither those files nor the code they exercise were touched by this phase, so this is an environmental limitation and not evidence about phase 18. Those 11 tests are unverified here.

### Probe Execution

No probes declared by the plans; step skipped.

### Requirements Coverage

All 12 phase requirement IDs (KB-01 to KB-08, SEC-01 to SEC-03, VER-01) appear in at least one PLAN frontmatter `requirements` list (18-14: SEC-01, SEC-02) and map to Phase 18. No orphaned requirement.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| KB-01 | 18-01, 18-02, 18-05, 18-09, 18-12 | Create an article with title and Markdown body | SATISFIED | Create page, `SaveArticle::create()`, NUL and write-failure refusals |
| KB-02 | 18-02, 18-07, 18-10 | Own view and edit screens | SATISFIED | Truth 1 |
| KB-03 | 18-03, 18-06, 18-07 | Safe rendering | SATISFIED | Truth 2 |
| KB-04 | 18-06 | `> [!NOTE]` callout | SATISFIED | Callout extension and tests |
| KB-05 | 18-03, 18-06 | Copy button on every code block | SATISFIED (server side) | Click in human verification |
| KB-06 | 18-09, 18-10 | Server-rendered preview | SATISFIED | Truth 1 |
| KB-07 | 18-01, 18-08 | Archive and restore | SATISFIED | Truth 1 |
| KB-08 | 18-05, 18-10, 18-12 | Stale save rejected, not merged | SATISFIED | Truth 6 |
| SEC-01 | 18-04, 18-05, 18-09, 18-10, 18-11, 18-13, 18-14 | Every save scans for secrets; message names rule and line, never the value | SATISFIED | Escape, entity, invisible and control-character hiding closed; WR-04 dilution remains as advisory in the Ambiguous tier. Restore clause is Phase 19 (VER-03) |
| SEC-02 | 18-04, 18-05, 18-09, 18-10, 18-11, 18-13, 18-14 | Unambiguous secrets block; ambiguous need acknowledgment and mark sensitive | SATISFIED | SC3b closed; PEM private keys block in the plain, one-line, fenced, indented, escaped and decorated spellings |
| SEC-03 | 18-05, 18-09, 18-10 | One-save override, logged without content | SATISFIED | Truth 5 |
| VER-01 | 18-01, 18-04, 18-05, 18-10 | Immutable gap-free versions, none for a no-op save | SATISFIED | Truth 6 |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 295-335 | Lines after a key that are base64-only are covered (IN-01) | Info | Advisory, the key already blocks |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 501-509, 569-588 | 256-character window dilution (WR-04) | Info | Advisory, Ambiguous tier, needs deliberate padding |
| `app/Domain/Knowledge/Support/MarkdownNormaliser.php`, `LinkAndImagePolicy.php`, constants, editor config | n/a | IN-02, IN-03, IN-05, IN-06 of the first review | Info | Deferred with reasons in `18-REVIEW-DISPOSITION.md` (not modified here) |

No `TBD`, `FIXME`, `XXX`, `TODO` or `HACK` markers in the files modified by the phase that I scanned (`app/Domain/Knowledge`, `tests/Support/SecretFixtures.php`).

### Human Verification Required

#### 1. Task-list toolbar button after SPA navigation

**Test:** Reach the edit screen through the list or view screen without a full reload, switch to Preview and back to Write twice; repeat for a second article. Run the Filament assets publish command first so the compiled script is current.
**Expected:** The task-list toolbar button is present each time and toggles the marker on the selected lines.
**Why human:** Code is fixed (observer on the document element); the effect is runtime browser behaviour no test exercises.

#### 2. Copy button click

**Test:** Click Copy on a rendered code block and paste.
**Expected:** The pasted text equals the code exactly; label feedback resets after about two seconds; the fallback selects the code when the clipboard is refused.
**Why human:** Browser clipboard behaviour.

#### 3. Visual check of rendered blocks and preview parity

**Test:** Compare the view screen and the Preview tab for an article with a table, task list, link, highlighted code and a callout.
**Expected:** Formatted blocks, highlighted callout, identical look.
**Why human:** Appearance.

### Gaps Summary

No gaps remain. SC3b is closed: a PEM private key with a one-line body or with comment, list, quote, concatenation or continuation decoration is now a Blocking finding that `SaveArticle` and the create screen refuse with no save-anyway path, and header mentions without a key body stay non-Blocking. The advisory hardening items WR-02, WR-04 and IN-01 were deliberately left out of 18-14; none contradicts a must-have, because the save is still flagged or the key still blocks. Four deferred first-review items (IN-02, IN-03, IN-05, IN-06) do not block the goal.

Verification limits: the 11 S3-dependent tests could not run without a bucket (environmental, untouched by this phase); the DDEV figures (350 Knowledge tests, Pint, PHPStan) are the orchestrator's, while the scanner behaviour, the 113 scanner unit tests and the ReDoS timings above are mine, run on host PHP against the committed classes. Browser behaviour (toolbar after SPA navigation, Copy click, visual parity) is not verified and is the only reason the status is `human_needed`.

---

_Verified: 2026-11-25_
_Verifier: Claude (gsd-verifier)_
