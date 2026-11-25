---
phase: 18-knowledge-articles-and-secret-guard
verified: 2026-11-25T20:00:00Z
status: gaps_found
score: 6/7 must-haves verified
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
  - app/Domain/Knowledge/Actions/ArticleInput.php
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Guard/SecretScanner.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - resources/js/knowledge-editor.js
  - tests/Support/SecretFixtures.php
  - tests/Unit/Knowledge/SecretScannerTest.php
covered_digest: "v4:sha256:b08de134373e749f9b6fea186b775b7cab928c4a4f7b3d396004f972a58d90bb"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 6/7
  gaps_closed:
    - "CR-01 as first reported: a PEM private key that is indented (YAML block scalar), blockquoted, tab-indented or a JSON string with escaped newlines is now the Blocking pem_private_key finding (reproduced in DDEV)"
    - "WR-01 as first reported: Markdown-escaped underscores, numeric entity underscores, soft hyphens, zero-width characters and fullwidth forms no longer hide a Blocking-class token (reproduced in DDEV)"
    - "IN-04: a refused database write and a save of an article archived in another tab end in a persistent notice that keeps the typed text; NUL bytes are refused (18-12)"
  gaps_remaining:
    - "SC3b is still not fully met: a PEM private key whose body is a single base64 line (the PKCS#8 Ed25519 shape) is only an overridable Ambiguous finding (CR-01 of 18-REVIEW-GAP-CLOSURE, reproduced in DDEV)"
  regressions: []
gaps:
  - truth: "Unambiguous secrets block the save (a PEM private key blocks with no override, D-01) in the forms they realistically take in a note"
    status: failed
    reason: "SecretScanner::PEM_PRIVATE_KEY still requires the header followed by at least two base64 lines of 16 or more characters. A standard PKCS#8 Ed25519 private key (what an openssl key-generation command for Ed25519 prints) is the header, ONE 64-character base64 line and the footer, so it does not match. Reproduced against the real class in DDEV: that key in plain form, in a fenced block, indented in a YAML block scalar and as a single 1600-character line each give only high_entropy_string (Ambiguous), which SaveArticle accepts with the save-anyway acknowledgment. The key is then stored in the article and in every append-only version row. The same shape with escaped newlines on one line IS blocked (the escaped rule needs only 16 units), so the guard is inconsistent between two spellings of the same key. The same two-line requirement also downgrades a multi-line key whose lines carry a comment, list or quote-and-comma prefix (a hash, double slash or dash prefix, quoted lines with a trailing comma), a blank line between the base64 lines, or a trailing backslash continuation (WR-01 of 18-REVIEW-GAP-CLOSURE; reproduced). Matches CR-01 (critical, open) of the gap-closure code review."
    artifacts:
      - path: "app/Domain/Knowledge/Guard/SecretScanner.php"
        issue: "PEM_PRIVATE_KEY (lines 53-55) needs {2} base64 lines and allows only [ \\t>] as a line prefix; no one-long-line alternative; isBase64Line() has the same limits"
      - path: "tests/Support/SecretFixtures.php"
        issue: "pemPrivateKeyShapes() always builds two 64-character lines, so the single-line key type is untested"
    missing:
      - "Accept a header followed by ONE base64 line of 40 or more characters (or by two of 16 or more) as pem_private_key, in the plain, indented and fenced forms"
      - "Replace the fixed prefix character class by a per-line check in code: strip non-base64 characters at both ends of each of the next few lines (comment, list, quote, comma, backslash), tolerate one blank line"
      - "Add the Ed25519 single-line, 1600-character single-line and prefixed shapes to SecretFixtures::pemPrivateKeyShapes() and the create-screen dataset, assembled from fragments (D-05)"
deferred: []
advisory:
  - finding: "A key flattened onto one line with spaces (CI or env-var style) is only an Ambiguous high_entropy_string (review WR-02 of the gap-closure review)"
    category: security
    reason: "Hardening, not a gap: the save is still flagged, needs acknowledgment and marks the article sensitive; reproduced"
    evidence_status: "reproduced in DDEV"
  - finding: "A Blocking-class token containing a C0 control character other than line breaks and tab, DEL or a C1 control (for example a start-of-heading character or U+0085) produces no finding at all (review WR-03)"
    category: security
    reason: "Hardening: needs an unusual paste source; reproduced for the AWS key id shape with a start-of-heading character and with DEL; the Default_Ignorable fold of 18-11 is not extended to controls"
    evidence_status: "reproduced in DDEV"
  - finding: "A 43-character random token directly after 300 repeated letters gives no finding (window dilution, review WR-04)"
    category: security
    reason: "Hardening: needs deliberate padding in the Ambiguous tier; reproduced"
    evidence_status: "reproduced in DDEV"
  - finding: "After a PEM match, following single-token lines are covered and skipped (review IN-01 of the gap-closure review)"
    category: other
    reason: "Harmless for the first scan because the key already blocks; the Admin may need a second round to see later secrets"
    evidence_status: "not re-run; taken from the review"
human_verification:
  - test: "Open an article's edit screen via SPA navigation (from the list or the view screen), switch the editor tab to Preview and back to Write twice, and look at the toolbar; repeat after navigating to a second article's edit screen the same way"
    expected: "The task-list toolbar button (D-07) is present after the first and every later Preview to Write switch and still toggles the task marker on the selected lines"
    why_human: "WR-02 is fixed in code (the observer in resources/js/knowledge-editor.js line 170 now watches document.documentElement instead of document.body, which Livewire navigate replaces) and a script test pins the needle, but the observer behaviour is runtime browser behaviour. Run the filament assets publish step first so the compiled copy under public is current."
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
**Status:** gaps_found
**Re-verification:** Yes, after gap closure plans 18-11, 18-12 and 18-13

## Goal Achievement

The gap closure did what it set out to do for the two originally reported findings, and I confirmed that against the real classes instead of the SUMMARY: every shape that failed in the first verification (indented, blockquoted and JSON-escaped PEM keys; Markdown-escaped, entity-encoded and soft-hyphenated tokens) is now a Blocking finding. The refused-save handling and the observer fix are in place.

One must-have truth is still not met. The gap-closure code review (`18-REVIEW-GAP-CLOSURE.md`) reports a critical finding in the new PEM rule, and I reproduced it independently: a private key with a single base64 body line, which is the canonical PKCS#8 Ed25519 key, is not a `pem_private_key` finding but an Ambiguous `high_entropy_string`, and the Admin can save it with "save anyway". That contradicts D-01 (a PEM private key has no override) in the plainest spelling of a common key type, with no evasion involved. The phase goal is therefore not yet fully achieved, and the status stays `gaps_found`.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: Admin creates an article (title and Markdown body) from General knowledge in the Knowledge group, opens it on its own view screen, edits it on a separate edit screen whose server-rendered preview matches the view screen; an archived article leaves the active list and can be restored | VERIFIED | Unchanged since the first verification (resource with index/create/view/edit pages, one renderer for preview and view, archive and restore Actions). Re-run: `tests/Feature/Knowledge` and `tests/Unit/Knowledge` green in this verification. 18-12 added a notice for a save of an article archived in another tab (`EditKnowledgeArticle::handleRecordUpdate()`), covered by two new tests. |
| 2 | SC2: The view screen renders tables, task lists, links, highlighted code and `> [!NOTE]` callouts; every code block has a Copy button that copies its exact text; hostile input shows as escaped text or dropped links, never live markup | VERIFIED | Unchanged (hardened `KnowledgeMarkdown`, Symfony sanitiser as second layer, callout and code block renderers). Unit and Feature knowledge suites green. Copy click and visual look stay under human verification. IN-03 (possible inert anchor) stays deferred, not exploitable. |
| 3 | SC3a: A body containing an unambiguous secret in its canonical shape is refused with a message naming rule and line, never the value | VERIFIED | Unchanged mechanism (`SaveArticle::acknowledgedRules()`, `GuardsSecrets::guardFindingLines()`). Re-confirmed against the real class in DDEV: plain multi-line RSA-shaped key gives `pem_private_key` Blocking, plain AWS-shaped id gives `aws_access_key` Blocking. See SC3b for the canonical shape that is not covered. |
| 4 | SC3b: Unambiguous secrets block the save in the forms they realistically take (D-01: a PEM private key has no override) | FAILED | Previously failing shapes are closed (table below). New failure, reproduced: header, one 64-character base64 line, footer (PKCS#8 Ed25519) gives `high_entropy_string` Ambiguous only, in plain, fenced and YAML-indented form, and as a single 1600-character body line. Prefixed multi-line keys (hash, double slash, list dash, quoted with trailing comma) and a blank line between base64 lines also fall to Ambiguous. See Gaps. |
| 5 | SC3c: An ambiguous finding saves only after explicit acknowledgment, marks the article sensitive, the override covers one save only and is logged without the content | VERIFIED | Unchanged (`SaveArticle`, `GuardsSecrets::saveAnyway()` with locked properties). 18-13 adds a six-case create-screen test proving that an embedded key or escaped token offers no save-anyway path; it passes. Note: the Ambiguous fallback for the single-line key is why SC3b fails, not a flaw of this mechanism. |
| 6 | SC4: A content-changing save raises the version by exactly one and keeps an immutable version; a save without changes creates no version; two edit screens saving from the same starting version: the second is rejected with a notice | VERIFIED | Unchanged (`SaveArticle::update()` under `lockForUpdate()`, `StaleArticle`, unique `(article_id, version_no)`, frozen-version trigger). Feature suite and the full post-merge run green. IN-02 stays deferred (one extra version for an apparently unchanged save after a closing code fence, owner decision pending). |
| 7 | SC5: A Partner sees no Knowledge group and is refused on every knowledge screen and URL; screens and routes are declared in the Partner permission matrix and the Partner audit stays green | VERIFIED | Unchanged. Re-run in this verification: `tests/Isolation/KnowledgeScreensTest.php` and `KnowledgeVisibilityTest.php` green; the orchestrator reports the Audit suite green (633 passed). |

**Score:** 6/7 truths verified (0 present, behavior-unverified)

### Closure of the first verification's gaps (reproduced in DDEV)

Scratch scripts outside the repository, probes assembled at runtime from fragments, random fictional bodies, fed to the real `SecretScanner` through `ddev exec php`.

| Shape | First verification | Now |
|-------|--------------------|-----|
| RSA-style key, 2-space indent (YAML block scalar) | high_entropy_string Ambiguous x2 | pem_private_key Blocking |
| Same, blockquote prefix | Ambiguous x2 | pem_private_key Blocking |
| Same, JSON string with escaped newlines (also with escaped slashes) | Ambiguous x1 | pem_private_key Blocking |
| Same, tab-indented in a fenced block | not probed | pem_private_key Blocking |
| Stripe-style key, Markdown-escaped underscores | no finding | stripe_secret Blocking |
| GitHub-style token, numeric entity underscores | no finding | github_token Blocking |
| AWS-style id with a soft hyphen or zero-width space | no finding | aws_access_key Blocking |
| Stripe-style key with a fullwidth underscore | not probed | stripe_secret Blocking |

### New findings: independent verdict on the gap-closure review

Reproduced against the real class (same method). "Gap" means it contradicts a must-have in the forms a note realistically takes; "hardening" means the save is still flagged or the shape needs deliberate evasion.

| Review ID | Shape | Reproduced result | Verdict |
|-----------|-------|-------------------|---------|
| CR-01 | Header, one 64-char base64 line, footer (PKCS#8 Ed25519), plain | high_entropy_string Ambiguous only | **Gap (blocker for SC3b).** Canonical output of a common key generator, no evasion needed; D-01 says it must not save. |
| CR-01 | Same in a fenced block; same YAML-indented | Ambiguous only | **Gap** (same root cause) |
| CR-01 | Header, one 1600-character line, footer | Ambiguous only | **Gap** (same root cause) |
| CR-01 | Same Ed25519 body on one line with escaped newlines | pem_private_key Blocking | Inconsistent with the plain form, shows the escaped rule is the more lenient one |
| WR-01 | Two-line key with `# `, `// `, `- ` prefixes | Ambiguous x2 | **Gap, lower realism.** A key pasted from a commented script or YAML list is a normal note; the fix is the same as for CR-01 (per-line check in code instead of a fixed prefix class) so it belongs in the same closure. Still flagged, never silent. |
| WR-01 | Quoted lines with trailing comma; trailing backslash continuation; blank line between base64 lines | Ambiguous x2 | Hardening (less common); same closure |
| WR-02 | Key flattened with spaces on one line | Ambiguous x1 | Hardening: flagged, acknowledgment and sensitive flag still apply |
| WR-03 | AWS-style id with a start-of-heading character or DEL inside | no finding | Hardening: silent pass but needs an unusual paste source; extend the fold to controls in the same pass |
| WR-04 | 300 padding letters then a 43-char random token | no finding | Hardening: Ambiguous tier, needs deliberate padding |
| IN-01 | Lines after a key skipped | not re-run | Hardening; harmless on the first scan |

Controls that still hold: OpenSSH-format and EC SEC1-shaped keys (five and three lines) give `pem_private_key` Blocking; a prose sentence naming the header gives no finding; ReDoS timings in the review (0.01 to 0.11 s on 500 KB) are not contradicted by the scans run here, which all returned immediately.

### Deferred Items

None. SEC-01's restore clause is Phase 19 (VER-03); it does not cover the scanner shapes above.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Knowledge/Guard/SecretScanner.php` | Rule and line findings, fail closed | PARTIAL | Fold, rendered view, escaped-key rule and windowed runs work; the multi-line PEM rule misses one-line-body keys and prefixed lines |
| `app/Domain/Knowledge/Actions/SaveArticle.php` | Single write path | VERIFIED | Unchanged |
| `app/Domain/Knowledge/Actions/ArticleInput.php` | Input refusals | VERIFIED | NUL refusal on raw title and body added (18-12) |
| `app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php` | Notice for a failed write, keeps typed text | VERIFIED | Wired from both pages; tests green |
| `app/Filament/Resources/KnowledgeArticleResource/Pages/{Create,Edit}KnowledgeArticle.php` | Screens | VERIFIED | Catch `ArticleWriteFailed`; edit also catches the archived-meanwhile case |
| `resources/js/knowledge-editor.js` | Task-list button | VERIFIED in code, browser check pending | `observe(document.documentElement, ...)` at line 170 |
| `tests/Support/SecretFixtures.php`, `tests/Unit/Knowledge/SecretScannerTest.php` | Shape corpus | PARTIAL | No single-line-body key shape |
| Remaining artifacts of the first verification (renderer, normaliser, archive and restore Actions, migrations, matrix rows, `lang/cs/knowledge.php`) | as before | VERIFIED | Unchanged; suites green |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| Create and Edit pages | `SaveArticle` | `create()` / `update()` | WIRED | |
| Pages | Failed-save notice | `RefusesFailedSaves::refuseWriteFailure()` then `Halt` | WIRED | New in 18-12 |
| Pages | Secret guard UI | `GuardsSecrets::runGuarded()` | WIRED | |
| Preview tab and view screen | One renderer | `previewHtml()` / infolist | WIRED | |
| Resource | Partner matrix | `PartnerMatrix` rows | WIRED | Isolation tests green |
| Editor field | Task-list button | MutationObserver on the document element | WIRED in code | Runtime effect is a human item |

### Data-Flow Trace (Level 4)

Unchanged from the first verification: view screen, preview, list and guard callouts all FLOW from real sources (DB row, live form state, scanner findings).

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Knowledge unit, feature and Partner isolation suites | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge tests/Feature/Knowledge tests/Isolation/KnowledgeScreensTest.php tests/Isolation/KnowledgeVisibilityTest.php` | 324 passed, 4904 assertions | PASS |
| Pint on the knowledge domain | `ddev exec vendor/bin/pint --test app/Domain/Knowledge` | passed | PASS |
| Previously failing PEM, escape, entity and soft-hyphen shapes | scratch probe, real `SecretScanner` | all Blocking (table above) | PASS |
| PKCS#8 Ed25519-shaped key (one base64 line), plain, fenced, YAML-indented, 1600-char line | same | high_entropy_string Ambiguous only | FAIL |
| Commented, list-prefixed, quoted-with-comma, backslash-continued, blank-line-split keys | same | Ambiguous only | FAIL (see table) |
| Control character or padding evasions | same | no finding | FAIL (hardening) |

Scratch scripts live outside the repository and use only runtime-assembled fictional values.

**Post-merge results reported by the orchestrator (not re-run by me in full):** the full suite `Unit,Arch,Feature,Isolation,Concurrency` on feature/CERP-11 in DDEV without `--parallel` gave 7660 passed, 3 skipped, 11 failed. All 11 failures are in three files that need a live S3-compatible test service: `tests/Feature/Documents/DeleteDocumentTest.php`, `tests/Feature/Documents/TemporaryUploadS3Test.php`, `tests/Feature/Operations/StorageCheckTest.php`. The test storage hostname does not resolve inside DDEV, and neither those files nor the code they exercise were touched by this phase, so this is an environmental limitation and not evidence about phase 18; those 11 tests are unverified here. The Audit suite gave 633 passed; Pint and PHPStan were clean. I confirmed the knowledge-related subset myself (324 passed above) and Pint on the domain directory, not the full suite.

### Probe Execution

No probes declared by the plans; step skipped.

### Requirements Coverage

All 12 phase requirement IDs (KB-01 to KB-08, SEC-01 to SEC-03, VER-01) appear in at least one PLAN frontmatter `requirements` list (18-11: SEC-01, SEC-02; 18-12: KB-01, KB-08; 18-13: SEC-01, SEC-02, plus plans 18-01 to 18-10) and map to Phase 18 in REQUIREMENTS.md. No orphaned requirement. REQUIREMENTS.md still shows the traceability of KB-02 to KB-07, SEC-03 and VER-01 as "Gaps Found" from the first verification and SEC-01, SEC-02 as "Complete"; the tracking update belongs to the orchestrator after the final verdict.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| KB-01 | 18-01, 18-02, 18-05, 18-09, 18-12 | Create an article with title and Markdown body | SATISFIED | Create page, `SaveArticle::create()`, NUL and write-failure refusals |
| KB-02 | 18-02, 18-07, 18-10 | Own view and edit screens | SATISFIED | Truth 1 |
| KB-03 | 18-03, 18-06, 18-07 | Safe rendering | SATISFIED | Truth 2 |
| KB-04 | 18-06 | `> [!NOTE]` callout | SATISFIED | Callout extension and tests |
| KB-05 | 18-03, 18-06 | Copy button on every code block | SATISFIED (server side) | Click in human verification |
| KB-06 | 18-09, 18-10 | Server-rendered preview | SATISFIED | Truth 1 |
| KB-07 | 18-01, 18-08 | Archive and restore | SATISFIED | Truth 1 |
| KB-08 | 18-05, 18-10, 18-12 | Stale save rejected, not merged | SATISFIED | Truth 6, plus archived-meanwhile notice |
| SEC-01 | 18-04, 18-05, 18-09, 18-10, 18-11, 18-13 | Every save scans for secrets; message names rule and line, never the value | SATISFIED for the scan and message; PARTIAL for coverage | Escape, entity and invisible-character hiding closed; control-character and dilution silent passes remain (advisory). Restore clause is Phase 19 (VER-03) |
| SEC-02 | 18-04, 18-05, 18-09, 18-10, 18-11, 18-13 | Unambiguous secrets block; ambiguous need acknowledgment and mark sensitive | PARTIAL | Mechanism correct; a PEM private key with a one-line body or a comment, list or quote prefix is downgraded to ambiguous (SC3b gap) |
| SEC-03 | 18-05, 18-09, 18-10 | One-save override, logged without content | SATISFIED | Truth 5 |
| VER-01 | 18-01, 18-04, 18-05, 18-10 | Immutable gap-free versions, none for a no-op save | SATISFIED | Truth 6 |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 53-55 | PEM rule needs two base64 lines and a fixed prefix class | Blocker | CR-01 and WR-01 of the gap-closure review, see gaps |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 35-37 | Invisible fold has no C0, DEL or C1 controls | Warning | WR-03, silent pass, advisory |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 446-480 | 256-character window dilution | Warning | WR-04, advisory |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 272-274 | Lines after a key skipped | Info | IN-01, advisory |
| `app/Domain/Knowledge/Support/MarkdownNormaliser.php`, `LinkAndImagePolicy.php`, constants, editor config | n/a | IN-02, IN-03, IN-05, IN-06 of the first review | Info | Deferred with reasons in `18-REVIEW-DISPOSITION.md` (not modified here) |

No `TBD`, `FIXME`, `XXX`, `TODO` or `HACK` markers in `app/Domain/Knowledge`, the knowledge resource directory, the knowledge scripts or `lang/cs/knowledge.php`.

`18-REVIEW-DISPOSITION.md` and `18-REVIEW.md` were read, not modified: five findings fixed, four deferred, none open. The disposition entry for CR-01 ("blocks with no override") is accurate for the shapes it names but not for the one-line-body shape found afterwards.

### Human Verification Required

#### 1. Task-list toolbar button after SPA navigation (WR-02)

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

One truth fails: SC3b. The two gaps from the first verification are closed and I reproduced that. The remaining gap is narrower but real, and it is the same class as before: a PEM private key can be saved with "save anyway" although D-01 says it never can.

The cause is the shape assumption in `PEM_PRIVATE_KEY`: header followed by at least two base64 lines, each starting with at most spaces, tabs or `>`. The plainest counter-example needs no embedding at all: the PKCS#8 Ed25519 private key is a header, one 64-character base64 line and a footer. Ed25519 is the common choice for new signing keys, so this is a likely way a key reaches a note. Comment, list and quote prefixes are the second-likeliest way and share the fix.

One focused closure plan (`/gsd-plan-phase --gaps`) is enough:

1. In `scanPrivateKeys()` replace the fixed regex body with a per-line check in code after a header match: look at the next few lines, strip non-base64 characters at both ends of each, accept one run of 40 or more characters or two runs of 16 or more, tolerate one blank line. This also removes the prefix-class limit and keeps the work bounded.
2. Extend the fold to the C0, DEL and C1 controls (keep tab and the line breaks) and test windows with a stride so padding cannot dilute a token (advisory items, cheap to include).
3. Add the Ed25519 single-line, long single-line, commented, list-prefixed and quoted-with-comma shapes to `SecretFixtures::pemPrivateKeyShapes()`, the scanner unit test and the create-screen dataset, assembled from fragments.

The deferred items IN-02, IN-03, IN-05 and IN-06 do not block the goal.

Verification limits: the three S3-dependent test files could not run in DDEV (environmental, untouched by this phase); the full suite figures are the orchestrator's, the knowledge, Partner-isolation and Pint results above are mine. Browser behaviour (WR-02, Copy, visual parity) is not verified.

---

_Verified: 2026-11-25_
_Verifier: Claude (gsd-verifier)_
