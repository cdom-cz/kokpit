---
phase: 18-knowledge-articles-and-secret-guard
reviewed: 2026-11-25T00:00:00Z
depth: standard
files_reviewed: 13
files_reviewed_list:
  - app/Domain/Knowledge/Actions/ArticleInput.php
  - app/Domain/Knowledge/Guard/SecretScanner.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - lang/cs/knowledge.php
  - resources/js/knowledge-editor.js
  - tests/Feature/Knowledge/CreateKnowledgeArticleTest.php
  - tests/Feature/Knowledge/EditKnowledgeArticleTest.php
  - tests/Feature/Knowledge/KnowledgeLangTest.php
  - tests/Feature/Knowledge/SaveArticleTest.php
  - tests/Support/SecretFixtures.php
  - tests/Unit/Knowledge/SecretScannerTest.php
findings:
  critical: 1
  warning: 4
  info: 1
  total: 6
  necessity: 0
status: issues_found
---

# Phase 18: Code Review Report (gap closure, plans 18-11, 18-12, 18-13)

**Reviewed:** 2026-11-25
**Depth:** standard
**Files Reviewed:** 13
**Status:** issues_found

## Summary

The refused-save handling (`RefusesFailedSaves`, the `ArticleWriteFailed` and `ModelNotFoundException` catches), the NUL-byte refusal in `ArticleInput` and the editor observer change (`document.documentElement`) are sound. I found no defect in them:

- `Halt` is handled by both Filament `create()` and `save()`. The panel does not enable `databaseTransactions()`, so the default non-rollback `Halt` has no effect.
- The NUL check runs on the raw title before `trim()`.
- The observer's own button insertion cannot loop, because `enhance()` returns early once the button exists.

The language file and the tests are consistent with the code.

The `SecretScanner` changes are different. I ran the scanner against adversarial inputs in a scratch script (random fictional base64, never committed). The result:

- **No ReDoS.** 500 KB adversarial bodies scanned in 0.01 to 0.11 s. This covers repeated headers, header fields, escaped headers, long runs, URL and password patterns, entities and NFKC expansion.
- **The new PEM rule has false negatives on ordinary key shapes.** A private key in these shapes is downgraded from a Blocking `pem_private_key` finding to an Ambiguous `high_entropy_string` finding, which the Admin can override with "save anyway". SEC-01 intends a private key to be unsaveable.
- **Two smaller fold gaps** (control characters, long-run dilution) let a token through with no finding at all.

No finding below contains a real secret. The shapes are described, not reproduced.

## Critical Issues

### CR-01: A private key whose body is a single base64 line (Ed25519 and other short or unwrapped keys) is not a PEM finding and can be saved with "save anyway"

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:53-55`
**Issue:** `PEM_PRIVATE_KEY` requires the header and then exactly two lines of 16 or more base64 characters (`{2}`). A standard PKCS#8 Ed25519 private key is a header, one 64-character base64 line, and a footer. This is what `openssl genpkey -algorithm ed25519` and similar tools emit. An unwrapped body (a whole key on one long line) has the same shape. Neither matches.

I verified with a fictional random body: the scan returns only `high_entropy_string@2` for the Ed25519 shape and for a 1600-character single-line body. `high_entropy_string` is Ambiguous (`SecretRule::severity()`), so `SaveArticle::acknowledgedRules()` accepts it with `$acknowledge = true`. The Admin can then store a real private key, with only a "high entropy" warning. The same weakening applies when the run is short enough that it is not flagged at all.

The test suite does not cover this shape. `SecretFixtures::pemPrivateKey()` always produces two 64-character lines.

**Fix:** Accept one long base64 line when it is followed by the footer, or accept either form:
```php
// after the header and optional header fields:
'(?:'
    .'(?:[ \t>]{0,40}+[A-Za-z0-9+\/=]{16,}+[ \t]*(?:(?:\\\\r)?\\\\n)?(?:\n|\z)){2}'   // two lines, as now
    .'|[ \t>]{0,40}+[A-Za-z0-9+\/=]{40,}+[ \t]*(?:(?:\\\\r)?\\\\n)?(?:\n|\z)'          // one long line
.')'
```
Also make the escaped-form rule at line 64 behave the same. It already needs only 16 units, so it is fine. Add the Ed25519 and single-line shapes to `SecretFixtures::pemPrivateKeyShapes()`.

## Warnings

### WR-01: A private key behind a comment marker, quotes, list marker, trailing character or blank line is downgraded to Ambiguous

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:53-55, 64-66, 71`
**Issue:** Every line after the header may start only with `[ \t>]`, and may end only with spaces and an escaped line break. I verified with fictional two-line bodies that each of these shapes yields only Ambiguous `high_entropy_string` findings, not `pem_private_key`:
- `# ` prefix (a commented-out key in a shell or config snippet);
- `// ` prefix;
- `| ` prefix (a table);
- `- ` or `* ` prefix (a list item);
- each line wrapped in quotes with a trailing comma (`"...",`);
- source-code string concatenation (`"-----BEGIN ...\n" +` followed by `"MIIE...\n" +`);
- adjacent Python literals;
- a trailing `\` line continuation;
- a blank line between the two base64 lines.

An author pasting a key from a script or config snippet hits exactly these shapes. The comment `R-18-11-1` lists YAML scalars, fences and blockquotes only, so the pattern was fitted to the tested shapes, not to the way keys are embedded in text.

**Fix:** Do not require a fixed prefix. After a header match (`-----BEGIN ... PRIVATE KEY`) in the folded text, take the next few lines (for example 6). Strip every character that is not a base64 character from the start and end of each line, then count lines with a base64 run of 16 or more. Report the header line when two such lines follow, or one line of 40 or more characters follows. This replaces the prefix character class with a line-by-line check in code, which also keeps the regex bounded.

### WR-02: A key flattened onto one line with spaces (CI or env-var style) is downgraded to Ambiguous

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:53, 64-66`
**Issue:** The block rule requires a real line break after the header. The escaped rule requires a backslash-n escape directly after the header. A key whose line breaks were replaced by spaces, as in a single-line secret variable, matches neither. I verified: the header, a space, then three base64 chunks produces only `high_entropy_string@1`.

**Fix:** In `scanPrivateKeys()`, add a one-line pass. For a line containing `-----BEGIN ` and `PRIVATE KEY-----`, look at the text after the header and accept a match when it holds one run of 40 or more base64 characters, or two runs of 16 or more separated by whitespace. Keep it bounded, like `PEM_PRIVATE_KEY_ESCAPED`.

### WR-03: Control characters (C0, DEL, C1) are not folded, so a token containing one produces no finding at all

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:35-37`
**Issue:** `INVISIBLE` is the Default_Ignorable set, but it holds none of the C0 controls (other than line breaks), DEL or the C1 controls. I verified that an AWS-shaped fictional key with one of `\x01`, `\x08`, `\x7F`, `U+0085` or `U+009D` inserted yields an empty result. No rule matches, not even an Ambiguous one. Those characters render as nothing or as a zero-width glyph in common browsers, so this is the same hiding technique the 18-11 fold exists to defeat. NUL is refused earlier, but the other controls are not.

**Fix:** Add the controls to the fold, while keeping tab (`\x09`) and the line breaks the line splitter uses:
```php
private const string INVISIBLE = '/[\x{0001}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
    .'\x{00AD}\x{034F}...existing ranges.../u';
```
Add the characters to `SecretFixtures::secretEvasionShapes()`.

### WR-04: A random token adjacent to a long low-entropy run is diluted and produces no finding

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:446-480`
**Issue:** A run longer than 256 characters is checked in consecutive 256-character windows plus the final 256 characters. Entropy and the three-class test are computed over the whole window. I verified that a 43-character random fictional token directly after 300 repeated letters (and likewise after 300 hyphens) gives an empty result: the final window is mostly padding, so its entropy falls below `RUN_MIN_ENTROPY`. A token that sits in a window shared with long padding, or that straddles a window boundary, is missed in the same way. The rule is Ambiguous, but here it yields no warning at all.

**Fix:** Test fixed shorter windows with a stride (for example 64 characters, stride 32), so the work stays linear and a dense token cannot be hidden by neighbouring padding:
```php
for ($offset = 0; $offset + 64 <= $length; $offset += 32) {
    if ($this->isRandomWindow(substr($run, $offset, 64))) { return true; }
}
return $this->isRandomWindow(substr($run, -64));
```
Keep the 32-character minimum for a run that is shorter than a window.

## Info

### IN-01: The base64-line extension after a private key swallows any following single-token line

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:272-274, 416-431`
**Issue:** After a PEM match, `$last` is extended over every following line for which `isBase64Line()` holds, and all those lines are marked covered, so no line rule runs on them. `isBase64Line()` accepts any line made of letters, digits, `+`, `/` and `=`. A key with no footer, followed directly by a line such as an AWS key id or `password=Abcdef1234`, has that line skipped. I verified this with an AWS-shaped fictional key line. It is harmless for the first scan, because the key already blocks the save. But after the Admin removes the key, the next scan is the first to report the secrets that followed it, so the Admin fixes the text in several rounds.

**Fix:** Cover extra lines only when each is a plausible key line: at least 16 characters, or a line made only of base64 characters of the same width as the previous line. Better, scan covered lines with the non-entropy rules (AWS, Stripe, GitHub, JWT, URL, password) and skip only `HighEntropyString` on them.

---

_Reviewed: 2026-11-25_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
