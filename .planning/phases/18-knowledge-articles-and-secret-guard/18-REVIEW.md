---
phase: 18-knowledge-articles-and-secret-guard
reviewed: 2026-11-25T00:00:00Z
depth: standard
files_reviewed: 52
files_reviewed_list:
  - .gitignore
  - app/Domain/Knowledge/Actions/ArchiveArticle.php
  - app/Domain/Knowledge/Actions/ArticleInput.php
  - app/Domain/Knowledge/Actions/RestoreArticle.php
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Actions/SaveOutcome.php
  - app/Domain/Knowledge/Actions/SaveResult.php
  - app/Domain/Knowledge/Enums/ArticleKind.php
  - app/Domain/Knowledge/Exceptions/ArticleWriteFailed.php
  - app/Domain/Knowledge/Exceptions/SecretGuardRefused.php
  - app/Domain/Knowledge/Exceptions/StaleArticle.php
  - app/Domain/Knowledge/Guard/SecretFinding.php
  - app/Domain/Knowledge/Guard/SecretRule.php
  - app/Domain/Knowledge/Guard/SecretScanner.php
  - app/Domain/Knowledge/Guard/Severity.php
  - app/Domain/Knowledge/Models/KnowledgeArticle.php
  - app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
  - app/Domain/Knowledge/Models/KnowledgeFolder.php
  - app/Domain/Knowledge/Support/Callout.php
  - app/Domain/Knowledge/Support/CalloutExtension.php
  - app/Domain/Knowledge/Support/CalloutRenderer.php
  - app/Domain/Knowledge/Support/CodeBlockRenderer.php
  - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
  - app/Domain/Knowledge/Support/LinkAndImagePolicy.php
  - app/Domain/Knowledge/Support/MarkdownNormaliser.php
  - app/Domain/Knowledge/Support/TaskListMarkerRenderer.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Tags/TagType.php
  - app/Filament/Concerns/InKnowledgeGroup.php
  - app/Filament/RelationManagers/KnowledgeArticleHistoryRelationManager.php
  - app/Filament/Resources/KnowledgeArticleResource.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/SecretGuardForm.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewKnowledgeArticle.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/KnowledgeServiceProvider.php
  - bootstrap/providers.php
  - composer.json
  - database/migrations/2026_11_25_000100_create_knowledge_articles_table.php
  - database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php
  - database/migrations/2026_11_25_000300_create_knowledge_folders_table.php
  - lang/cs/enums.php
  - lang/cs/knowledge.php
  - lang/cs/kokpit.php
  - resources/css/kokpit-prose.css
  - resources/js/knowledge-copy.js
  - resources/js/knowledge-editor.js
  - resources/views/filament/knowledge/article-meta.blade.php
  - resources/views/filament/knowledge/guard-findings.blade.php
  - resources/views/filament/knowledge/prose.blade.php
  - scripts/tests/test-gitignore.sh
findings:
  critical: 1
  warning: 2
  info: 6
  total: 9
  necessity: 0
status: issues_found
---

# Phase 18: Code Review Report

**Reviewed:** 2026-11-25
**Depth:** standard
**Files Reviewed:** 52
**Status:** issues_found

## Summary

The phase is well built in the areas the owner flagged as high risk. Findings below come from reading every file in scope and from running the real classes (scanner, normaliser, renderer) against hostile and edge-case input in a scratch script outside the repository.

What held up under attack:

- **Markdown rendering / XSS.** Raw HTML is escaped, images collapse to alt text, every link that is not `http(s)://` or `mailto:` collapses to its text, and the Symfony sanitiser is a real second layer. I tried script tags, `javascript:` links in inline, reference and autolink form, attribute-breaking URLs and titles, entity-encoded quotes, hostile fence info strings, a closing `</code></pre>` inside highlighted code, table alignment, callouts with embedded HTML and task lists. None produced executable markup. `prose.blade.php` echoes an `HtmlString` that only `KnowledgeMarkdown::render()` produces. `article-meta` and `guard-findings` echo escaped values only. The Filament MarkdownEditor ships with its own EasyMDE preview shortcuts disabled (`togglePreview: null`), so the unsanitised built-in preview is not reachable.
- **ReDoS and resource use.** Pathological 100 000 character inputs (bracket floods, delimiter floods, nested quotes, `password=` and `a://b:` repeats, 3 700 PEM headers, 50 000 lines) through normaliser, scanner and renderer all finish in well under a second. Fenced code in 26 allow-listed languages with 12 hostile payloads each stayed under one second per block. The scanner patterns are bounded or possessive as documented.
- **Stale write / lost update.** `SaveArticle::update()` re-reads under `lockForUpdate()` and compares the version inside the same transaction. Parallel saves queue on the row lock and the second sees the new number. A concurrent archive turns the second writer's scoped re-read into a not-found, not a write. `UNIQUE (article_id, version_no)` is a real backstop.
- **Authorization.** Admin-only policy with a default-deny base, `DeniesPartners` scope on all three models, `abort_unless(canAccess(), 403)` in every page `mount()`, and a second `Gate::authorize` inside each Action. The unexposed/locked Livewire members (`guardFindings`, `refusedBodyHash`, `loadedVersion`, `loadedSensitive`) cannot be set from the browser.
- **Logging.** No Action, page or exception puts a title, body or matched text into a message. `ArticleWriteFailed` deliberately drops the previous exception. The activity allowlist excludes `title` and `body_md`. No Sentry/Flare-style package is installed in `composer.json`, so there is no extra sink.

What did not hold up: the secret guard has realistic evasions that downgrade or fully hide a secret (CR-01, WR-01), and the task-list toolbar script is bound to a DOM node the SPA discards (WR-02). The rest are low-severity robustness and consistency items.

## Critical Issues

### CR-01: PEM private key is only an overridable "high entropy" finding when it is indented, quoted or JSON-escaped

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:30-32`
**Issue:** D-01 says a PEM private key blocks the save with no override. `PEM_PRIVATE_KEY` demands that the two base64 lines start in column 0 (`(?:[A-Za-z0-9+\/=]{16,}+[ \t]*(?:\n|\z)){2}`) and that the header is followed by a real newline. Every common way of embedding a key in a note breaks that, and the key then falls through to the per-line `high_entropy_string` rule, which is `Severity::Ambiguous` and can be saved with "save anyway".

Reproduced against the real class with a fake 64-character base64 body (`$b64` is two lines of that):

| Input shape | Result |
|---|---|
| plain PEM block | `pem_private_key` (Blocking), correct |
| key inside a YAML block scalar, 2-space indent (`key: \|` then the PEM header and base64 lines indented) | two `high_entropy_string` findings only |
| key inside a blockquote (the PEM header and base64 lines each prefixed with a quote marker) | two `high_entropy_string` findings only |
| key in a list item or indented fence (3-space indent) | same |
| service-account style JSON with literal `\n` escapes on one line | one `high_entropy_string` finding only |

Indented keys in YAML, CI config and docker-compose snippets, and JSON service-account keys, are among the most likely ways a private key lands in a knowledge article. The Admin gets an ambiguous warning and a "save anyway" button for exactly the secret type the owner ruled must never be savable. The key is stored in the article and in every version row (append-only by trigger).

**Fix:** Tolerate a prefix on the header and on each base64 line, and accept the escaped-newline form:

```php
// Allow leading whitespace and blockquote markers on every key line.
private const string PEM_PRIVATE_KEY = '/-----BEGIN (?:[A-Z]{1,20} ){0,5}PRIVATE KEY(?: BLOCK)?-----[ \t]*\n'
    .'(?:[ \t>]*[A-Za-z][A-Za-z-]{0,30}: [^\n]{0,200}\n){0,4}[ \t>]*\n?'
    .'(?:[ \t>]*[A-Za-z0-9+\/=]{16,}+[ \t]*(?:\n|\z)){2}/';

// And a second, single-line rule for the JSON-escaped form (same Blocking severity):
private const string PEM_PRIVATE_KEY_ESCAPED = '/-----BEGIN (?:[A-Z]{1,20} ){0,5}PRIVATE KEY(?: BLOCK)?-----(?:\\\\n|\\\\r\\\\n)[A-Za-z0-9+\/=]{16,}+/';
```

Update `scanPrivateKeys()` `isBase64Line()` to strip the same prefix when extending the covered lines, and add the five shapes above to the scanner tests (assembled from fragments per D-05). An alternative that avoids pattern gymnastics: treat the header `-----BEGIN ... PRIVATE KEY-----` followed, within the next few lines or the same line, by any base64 run of 16 or more characters as Blocking.

## Warnings

### WR-01: The scanner reads Markdown source, so escapes, entities and invisible characters hide a secret completely

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:24`, `:118`, `:53`, `:61`, `:43`
**Issue:** The fold step removes only five zero-width characters (U+200B, U+200C, U+200D, U+2060, U+FEFF) and applies NFKC. Everything else that an author did not intend to type but that real copy-paste produces is left in the token, so the anchored token patterns do not match and no rule fires. Reproduced against the real class (fake fragments, 24 and 40 random characters):

| Input | Findings |
|---|---|
| plain `sk_live_<24 chars>` | `stripe_secret` (correct) |
| `sk\_live\_<24 chars>` (Markdown-escaped underscores) | none |
| `ghp\_<40 chars>` | none |
| `sk&#95;live&#95;<24 chars>` | none |
| `AKIA` + U+00AD (soft hyphen) + 16 characters | none |

Backslash-escaped underscores are what Markdown exporters and rich-text-to-Markdown converters (the repository itself depends on `league/html-to-markdown`) produce for a token pasted from a web page, and soft hyphens and directional marks come with text copied from PDFs and chat tools. The rendered article shows the intact secret; the scanner saw a different string. This is a silent pass of a Blocking-class secret, which is what SEC-01 exists to prevent.

**Fix:** Scan a second, "rendered-equivalent" view of each line in addition to the raw one, and widen the invisible-character strip:

```php
// Widen: soft hyphen, combining grapheme joiner, bidi marks and embeds, invisible operators.
private const string ZERO_WIDTH = '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u';

// In run(): after NFKC, also fold Markdown backslash escapes and numeric entities.
$unescaped = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $normalised);   // \_ -> _
$unescaped = html_entity_decode($unescaped, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // &#95; -> _
// Run the line rules on $normalised and, if different, on $unescaped; keep one finding per rule and line.
```

Line numbers stay valid because both views are per line.

### WR-02: Task-list toolbar button disappears after the first Preview to Write switch (SPA mode)

**File:** `resources/js/knowledge-editor.js:164-170` (observer), `:172-179` (start)
**Issue:** `start()` attaches the `MutationObserver` to `document.body` once. The panel runs in SPA mode (`AdminPanelProvider.php:112`, `->spa()`), and Livewire's navigate replaces the whole element: `document.body.replaceWith(newBody)` (`vendor/livewire/livewire/dist/livewire.js:14370`). After the first `wire:navigate` the observer watches a detached node and never fires again. The only remaining trigger is a single `livewire:navigated` rAF scan.

That is not enough, because `Tab::toEmbeddedHtml()` renders the child schema only for the active tab (`vendor/filament/schemas/src/Components/Tabs/Tab.php:209-217`, `$isActive ? ... : ''`). Switching to Preview removes the editor from the DOM and switching back to Write makes Filament build a fresh EasyMDE toolbar. The new toolbar has no task-list button, and nothing re-adds it. The user arrives on the edit screen through SPA navigation from the list or view screen in every normal flow, so the D-07 task-list button works once and then vanishes. I could not run a browser here; this is derived from the Livewire and Filament sources above.

**Fix:** Observe an element that survives body replacement and rescan on navigation:

```js
function start() {
    addIconStyle()
    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true })
    schedule()
}
```

`document.documentElement` is not replaced by navigate. Keep the existing `livewire:navigated` listener as a belt-and-braces rescan.

## Info

### IN-01: Scanner has two quiet gaps that follow from its shape limits

**File:** `app/Domain/Knowledge/Guard/SecretScanner.php:76-77`, `:84`, `:319`
**Issue:** (a) `RUN_MAX_LENGTH = 256` makes any single random run longer than 256 characters (a base64-encoded service credential or a long opaque token on one line) produce no finding at all; reproduced with 336 characters of mixed-case alphanumerics. (b) `PASSWORD_ASSIGNMENT` takes `[^\s"']{6,128}` as the value, so a quoted value with spaces such as `password = "two words here"` captures `two` (3 characters) and is not found; reproduced. Both are acceptable heuristics for the Ambiguous tier, but both are silent passes rather than warnings.
**Fix:** For (a) report a run above the cap as `high_entropy_string` when it passes the same mix and entropy check on its first 256 characters instead of skipping it. For (b) allow the quoted form to capture up to the closing quote: `(?:"([^"\n]{6,128})"|'([^'\n]{6,128})'|([^\s"']{6,128}))` and check the matching group against the placeholder list.

### IN-02: Same content can normalise to two canonical forms (final newline after a code block)

**File:** `app/Domain/Knowledge/Support/MarkdownNormaliser.php:63-69`
**Issue:** Trailing blank lines are popped only while the last line is prose, and the final line ending is dropped only when the last line is prose. When the document ends with a closing fence or indented code, the final line ending is kept. Reproduced: `"```\nx\n```"` normalises to itself while `"```\nx\n```\n"` also normalises to itself, but `"- a"` and `"- a\n"` both become `"- a"`. The function is idempotent (a 20 000-case fuzz found no violation), but D-10 and VER-01 ("a save without changes creates no version") are defined on normalised equality, so adding or removing one final Enter after a closing fence creates a new version for what the Admin sees as no change.
**Fix:** Treat the line ending of the last line as non-content for every line kind: after the loop, if the last output line is a code line whose text is a closing fence or whose block is complete, set its ending to `''`. For an unclosed fence the ending is genuine code content and should stay.

### IN-03: Sanitiser can leave an inert `<a>` without `href`, contradicting the documented guarantee

**File:** `app/Domain/Knowledge/Support/LinkAndImagePolicy.php:12-18`, `app/Domain/Knowledge/Support/KnowledgeMarkdown.php:96`
**Issue:** The class comment promises "the output holds no inert anchor either". `LinkAndImagePolicy` keeps any link whose URL starts with `http://`, `https://` or `mailto:`, but the sanitiser then rejects some of those hrefs (reproduced with `[x](http://<U+202E>evil.com)`, which renders `<a rel="noopener noreferrer nofollow">x</a>`). Not exploitable, but it is an anchor with no destination and the stated invariant is false.
**Fix:** Validate the URL in `LinkAndImagePolicy` with the same rule the sanitiser applies (for example `filter_var($url, FILTER_VALIDATE_URL)` plus a control- and bidi-character check) so such links are unwrapped to text, or soften the comment.

### IN-04: Database failures and concurrent archive reach the Admin as a raw server error, not a notice

**File:** `app/Domain/Knowledge/Actions/SaveArticle.php:246-249`, `app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php:159-179`, `CreateKnowledgeArticle.php:82-88`
**Issue:** `ArticleWriteFailed` (any `PDOException`, for example a body containing a NUL byte, which PostgreSQL text columns refuse) and `ModelNotFoundException` (the article was archived in another tab while this editor was open) are not caught by either page. Livewire shows its generic error overlay. The typed text is not lost (it is still in the browser), but D-09's intent is that a refused save keeps the text and says why in a notice.
**Fix:** In both pages catch `ArticleWriteFailed` and `ModelNotFoundException` next to the existing `StaleArticle` handling, send a persistent danger notification with a fixed Czech message, and `throw new Halt`. Optionally strip `"\0"` from title and body in `ArticleInput::from()` so a NUL never reaches the database.

### IN-05: The body limit is defined in three places with comments, not code, tying them together

**File:** `app/Domain/Knowledge/Support/KnowledgeMarkdown.php:40`, `app/Domain/Knowledge/Models/KnowledgeArticle.php:69`, migrations `..._000100_...:85` and `..._000200_...:61`
**Issue:** `KnowledgeMarkdown::MAX_INPUT_LENGTH`, `KnowledgeArticle::MAX_BODY_LENGTH` and the two `CHECK (char_length(body_md) <= 100000)` literals must stay equal; only docblocks say so. If one is raised, a stored article either hits the plain-text fallback in the view screen (silently losing rendering) or is refused by the database after passing validation.
**Fix:** Make `KnowledgeMarkdown::MAX_INPUT_LENGTH` reference the model constant, or add a Unit test that asserts the two constants (and the migration literal, read from the constraint) are equal.

### IN-06: EasyMDE fetches remote images referenced in an article while it is being edited

**File:** `app/Filament/Resources/KnowledgeArticleResource.php:145-165` (editor configuration)
**Issue:** Filament constructs EasyMDE with `previewImagesInEditor: true` (`vendor/filament/forms/dist/components/markdown-editor.js`), so a pasted `![x](https://tracker.example/pixel.png)` line is loaded by the Admin's browser inside the editor, although the renderer deliberately never emits an image. The renderer's "no tracking pixel" guarantee therefore does not extend to the write tab. It is Filament's default and has no PHP switch, so this is a documentation and expectation point, not a code defect.
**Fix:** Note it in the knowledge editor docs or `18-CONTEXT.md` follow-ups; if it matters, override the editor config through a small Alpine hook in `knowledge-editor.js` once Phase 20 settles the image handling.

---

_Reviewed: 2026-11-25_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
