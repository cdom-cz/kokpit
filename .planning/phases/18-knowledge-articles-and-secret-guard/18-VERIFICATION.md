---
phase: 18-knowledge-articles-and-secret-guard
verified: 2026-11-25T16:00:00Z
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
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Guard/SecretScanner.php
  - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
  - app/Filament/Resources/KnowledgeArticleResource.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - resources/js/knowledge-editor.js
covered_digest: "v4:sha256:fe3bdb028fa415453e876eaaa2e20abaeac7a80b55e883886ff694e76c5cf3f5"
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "Unambiguous secrets block the save (a PEM private key blocks with no override, D-01) in the forms they realistically take in a note"
    status: failed
    reason: "SecretScanner::PEM_PRIVATE_KEY requires the header followed by a real newline and the base64 lines starting in column 0. A key that is indented (YAML block scalar, list item, indented fence), prefixed with a blockquote marker, or written as a JSON string with literal escaped newlines is not recognised as pem_private_key. It falls through to the per-line high_entropy_string rule, which is Severity::Ambiguous and can be saved with 'save anyway'. The key is then stored in the article and in every version row (append-only). Reproduced against the real class in DDEV (see Behavioral Spot-Checks). Matches open code review finding CR-01 (critical, disposition 'open')."
    artifacts:
      - path: "app/Domain/Knowledge/Guard/SecretScanner.php"
        issue: "PEM_PRIVATE_KEY pattern (lines 30-32) and scanPrivateKeys()/isBase64Line() accept column-0 key lines only; no rule for the escaped single-line form"
    missing:
      - "Tolerate leading whitespace and blockquote markers on the header and on every base64 line of a PEM private key"
      - "Add a Blocking single-line rule for the JSON-escaped form (header, escaped newline, base64 run)"
      - "Extend scanPrivateKeys()/isBase64Line() to strip the same prefix when covering key lines"
      - "Add the five shapes (plain, indented, quoted, list/fence indent, JSON-escaped) to SecretScannerTest, assembled from fragments (D-05)"
  - truth: "No save stores a secret unnoticed: a Blocking-class token pasted from rich text or a PDF is still recognised"
    status: partial
    reason: "The scanner reads Markdown source. Markdown-escaped underscores in a Stripe-style or GitHub-style token, numeric HTML entities for the underscore, and a soft hyphen inside an AWS-style key id each produce zero findings, so a save passes with no warning although the rendered article shows the intact secret. Only five zero-width characters are stripped before matching. Reproduced in DDEV (escaped-underscore case returns no findings). Matches open code review finding WR-01 (warning, disposition 'open')."
    artifacts:
      - path: "app/Domain/Knowledge/Guard/SecretScanner.php"
        issue: "ZERO_WIDTH (line 24) too narrow; no Markdown-unescape / entity-decode view of each line is scanned"
    missing:
      - "Widen the invisible-character strip (soft hyphen, combining grapheme joiner, bidi marks, invisible operators)"
      - "Scan a second per-line view with Markdown backslash escapes folded and numeric/named entities decoded; keep one finding per rule and line"
      - "Add fragment-assembled tests for escaped underscore, entity underscore and soft-hyphen shapes"
deferred: []
advisory: []
coincidental_reliance_items: []
human_verification:
  - test: "Open an article's edit screen via SPA navigation (from the list or the view screen), switch the editor tab to Preview and back to Write, and look at the toolbar"
    expected: "The task-list toolbar button (D-07) is present after the first and every later Preview to Write switch"
    why_human: "Code review finding WR-02 derives from the Livewire and Filament sources that the MutationObserver in resources/js/knowledge-editor.js is attached to document.body, which Livewire navigate replaces; the active-tab-only rendering rebuilds the toolbar on each switch. Not exercised by any test and cannot be seen by grep; needs a real browser. Not a ROADMAP success criterion, so it does not change the status by itself."
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
**Re-verification:** No, initial verification

## Goal Achievement

The article half of the goal is achieved and well built: create, view, edit, preview, archive and restore exist and are wired through a single write path (`SaveArticle`) and a single renderer (`KnowledgeMarkdown`); the stale-save and Partner-isolation halves hold. The secret-guard half is achieved for canonical secret shapes but not for the realistic shapes named in the open critical review finding CR-01 and the warning WR-01. Those are confirmed against the real classes, not only taken from the review. The guard is a stated part of the goal ("no save stores a secret unnoticed") and decision D-01 (a PEM private key blocks with no override), so the phase does not yet fully achieve it.

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: Admin creates an article (title and Markdown body) from General knowledge in the Knowledge group, opens it on its own view screen, edits it on a separate edit screen whose server-rendered preview matches the view screen; an archived article leaves the active list and can be restored | VERIFIED | `KnowledgeArticleResource` has `index/create/view/edit` pages; `form()` has the Write and Preview tabs, the preview calls `previewHtml()`, which runs `MarkdownNormaliser` then the same `KnowledgeMarkdown::render()` and `filament.knowledge.prose` view the infolist uses (parity by construction). `table()` hides `deleted_at` rows unless the archived filter is on. `ArchiveArticle` locks the row, soft-deletes and writes a metadata-only activity row; restore action exists. The `Znalosti` navigation group is registered in `AdminPanelProvider`. Tests: `CreateKnowledgeArticleTest` (47), `EditKnowledgeArticleTest` (25), `ArchiveArticleTest` (21), `KnowledgeArticleResourceTest` (22). |
| 2 | SC2: The view screen renders tables, task lists, links, highlighted code and `> [!NOTE]` callouts; every code block has a Copy button that copies its exact text; hostile input shows as escaped text or dropped links, never live markup | VERIFIED | `KnowledgeMarkdown`: CommonMark with `html_input=escape`, `allow_unsafe_links=false`, `LinkAndImagePolicy` (images collapse to alt text, only `http(s)://` and `mailto:` links survive), plus a Symfony sanitiser as second layer; `CalloutExtension`, `CodeBlockRenderer` (one Copy button per block), `TaskListMarkerRenderer`; assets registered in `KnowledgeServiceProvider` and built to `public/js/app` and `public/css/app`. `knowledge-copy.js` copies `textContent` of the block's code element only. `tests/Unit/Knowledge` ran green in this verification (148 passed, 1451 assertions) including the hostile corpus, 5 callout types, table, task list, 26 highlighted languages, flood and size-cap cases. Clipboard click and visual look are listed under human verification. Minor: code review IN-03 (a rejected link can leave an `<a>` without href), not exploitable. |
| 3 | SC3a: A body containing an unambiguous secret in its canonical shape is refused with a message naming rule and line, never the value | VERIFIED | `SaveArticle::acknowledgedRules()` throws `SecretGuardRefused` for any Blocking finding whatever `$acknowledge` says. `SecretFinding` carries rule and line only; `GuardsSecrets::guardFindingLines()` builds the message from the translated rule name and the line. Test `refuses every blocking secret with its rule and line, with no override, and writes nothing` plus `SecretScannerTest` (20), fixtures assembled at runtime (`SecretFixtures`). Real class confirmed: plain PEM block gives `pem_private_key` Blocking, plain Stripe-style key gives `stripe_secret` Blocking. |
| 4 | SC3b: Unambiguous secrets block the save in the forms they realistically take (D-01: a PEM private key has no override) | FAILED | Reproduced in DDEV against the real `SecretScanner`: indented key gives two `high_entropy_string` Ambiguous findings; blockquoted key gives two Ambiguous findings; JSON string with escaped newlines gives one Ambiguous finding. All three can be saved with "save anyway". Markdown-escaped underscores in a Stripe-style token give no finding at all. See Gaps. Open review findings CR-01 and WR-01. |
| 5 | SC3c: An ambiguous finding saves only after explicit acknowledgment, marks the article sensitive, the override covers one save only and is logged without the content | VERIFIED | `SaveArticle`: `$acknowledge` is a per-call argument, never stored; on acknowledgment `sensitive` is forced true and `writeAcknowledgment()` writes one `secret_acknowledged` activity row with `rules` and `version_no` only. `GuardsSecrets::saveAnyway()` sets the flag only while the body still hashes to the refused body, resets it in `finally`; `guardFindings` and `refusedBodyHash` are `#[Locked]`. Tests: `saves an acknowledged ambiguous body, marks the article sensitive and logs the rule keys only`, `keeps the content out of the log on every save path`, `refuses a body with a blocking and an ambiguous finding even with the acknowledgment`. |
| 6 | SC4: A content-changing save raises the version by exactly one and keeps an immutable version; a save without changes creates no version; two edit screens saving from the same starting version: the second is rejected with a notice | VERIFIED | `SaveArticle::update()` re-reads under `lockForUpdate()`, compares `version_no` to the required `$expectedVersion` inside the transaction (throws `StaleArticle`), normalises, returns `Unchanged` without a write when title and normalised body equal the stored ones, else writes `version_no + 1` and a full-snapshot `KnowledgeArticleVersion`. DB backstops: `UNIQUE (article_id, version_no)`, trigger `knowledge_article_versions_frozen` refusing UPDATE and DELETE. `EditKnowledgeArticle` keeps `loadedVersion` locked and unrefreshed, catches `StaleArticle`, shows a persistent warning with a link to the current article, throws `Halt` (no overwrite, no merge). Tests: `SaveArticleTest`, `EditKnowledgeArticleTest` (stale, no-overwrite, locked-property cases), `KnowledgeSaveConcurrencyTest` (parallel saves, one winner, gap-free chain). Edge: IN-02 (one trailing newline after a closing code fence can create a version for an apparently unchanged save). IN-04 (write failure or concurrent archive reaches the Admin as a generic error). Neither breaks the criterion. |
| 7 | SC5: A Partner sees no Knowledge group and is refused on every knowledge screen and URL; screens and routes are declared in the Partner permission matrix and the Partner audit stays green | VERIFIED | `AccessServiceProvider` binds `AdminOnlyPolicy` to article, version and folder models; resource carries `#[AccessRule(Audience::AdminOnly)]` and `EnforcesResourceAccessRule`; every page `mount()` begins with `abort_unless(canAccess(), 403)`; `SaveArticle` and the other Actions authorise again. `tests/Support/PartnerMatrix.php` declares the resource, the history relation manager and the three models as `partner => false`. Tests: `KnowledgeScreensTest` (9), `KnowledgeVisibilityTest` (8), `KnowledgeSeamTest` Arch (14). Run results are those reported by the caller (green targeted suites); I did not re-run Isolation or Feature tests because the full suite was occupying the shared test database. |

**Score:** 6/7 truths verified (0 present, behavior-unverified)

### Deferred Items

None. The restore clause of SEC-01 and the redaction feature are scheduled in Phase 19 (VER-03, VER-05), but neither covers the scanner shapes above, so the gaps are not deferred.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Knowledge/Actions/SaveArticle.php` | Single write path: lock, stale check, normalise, no-op, scan, version | VERIFIED | Substantive, wired from both pages, ordering as specified |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | Rule and line findings, fail closed | PARTIAL | Works for canonical shapes, fails closed on regex error and invalid UTF-8; PEM and escape evasions (gaps) |
| `app/Domain/Knowledge/Support/KnowledgeMarkdown.php` | Hardened renderer | VERIFIED | Bound as shared instance in `KnowledgeServiceProvider`, used by view, preview |
| `app/Domain/Knowledge/Support/MarkdownNormaliser.php` | Canonical stored body | VERIFIED | Used by `SaveArticle`, preview, `saveAnyway` hash |
| `app/Domain/Knowledge/Actions/ArchiveArticle.php`, `RestoreArticle.php` | Archive and restore | VERIFIED | Authorised, transactional, metadata-only activity |
| `app/Filament/Resources/KnowledgeArticleResource.php` and four pages | Admin screens | VERIFIED | Registered in panel, Partner matrix declared |
| `app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php` | Screen side of guard | VERIFIED | Locked properties, one-save acknowledgment |
| `app/Filament/RelationManagers/KnowledgeArticleHistoryRelationManager.php` | Metadata history table | VERIFIED | Declared in matrix |
| `database/migrations/2026_11_25_0001*/0002*/0003*` | Tables, FKs, checks, triggers, UUID v7 | VERIFIED | `uuidv7()` defaults, unique `(article_id, version_no)`, frozen-version trigger, kind-frozen trigger, check constraints |
| `resources/js/knowledge-copy.js`, `knowledge-editor.js`, `resources/css/kokpit-prose.css` | Copy button, editor toolbar, prose styling | VERIFIED (editor script: see WR-02) | Registered as Filament assets, compiled copies in `public/` |
| `lang/cs/knowledge.php` | All Czech UI text | VERIFIED | `KnowledgeLangTest` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| Create and Edit pages | `SaveArticle` | `create()` / `update()` with `ArticleInput` and `loadedVersion` | WIRED | `EditKnowledgeArticle::handleRecordUpdate()` |
| Pages | Secret guard UI | `GuardsSecrets::runGuarded()` | WIRED | `SecretGuardRefused` becomes callouts, `Halt` |
| Preview tab and view screen | One renderer | `previewHtml()` / infolist `View` | WIRED | Both call `KnowledgeMarkdown::render()` and `filament.knowledge.prose` |
| Archive and restore actions | Domain Actions | `app(ArchiveArticle::class)->handle()` | WIRED | Row and header actions |
| Model changes | Activity log | allowlist excluding `title`, `body_md` | WIRED | `KnowledgeArticle` docblock and `SaveArticleTest` log test |
| Resource | Partner matrix | `PartnerMatrix` rows | WIRED | Lines 204, 250, 514-516 |
| Editor field | Task-list button | `knowledge-editor.js` MutationObserver on `document.body` | PARTIAL | Observer lost after SPA navigation (WR-02, needs browser) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| View screen prose | `html` | `KnowledgeMarkdown::render($record->body_md)` from the DB row | Yes | FLOWING |
| Preview tab | `html` | `previewHtml($get('body'))` from the live form state | Yes | FLOWING |
| List table | `title`, `version_no`, `edited_at`, `sensitive` | `KnowledgeArticle` query, body not selected | Yes | FLOWING |
| Guard callouts | `guardFindings` | `SecretGuardRefused::findings` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Knowledge unit suite (scanner, normaliser, renderer, callouts, code blocks) | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge` | 148 passed, 1451 assertions | PASS |
| Plain PEM private key block | Real `SecretScanner` with a fragment-assembled fake key | `pem_private_key` Blocking at line 1 | PASS |
| Plain Stripe-style live key | same | `stripe_secret` Blocking | PASS |
| PEM key inside a YAML block scalar (2-space indent) | same | two `high_entropy_string` Ambiguous findings, no Blocking | FAIL |
| PEM key in a blockquote | same | two `high_entropy_string` Ambiguous findings, no Blocking | FAIL |
| PEM key as a JSON string with escaped newlines | same | one `high_entropy_string` Ambiguous finding | FAIL |
| Stripe-style key with Markdown-escaped underscores | same | no findings | FAIL |

All probe inputs were assembled at runtime from fragments in a scratch script outside the repository; the script is not part of the codebase.

### Probe Execution

No probes declared by the plans; step skipped.

### Requirements Coverage

All 12 phase requirement IDs appear in at least one PLAN frontmatter `requirements` list and in REQUIREMENTS.md mapped to Phase 18. No orphaned requirement (SEC-04 maps to Phase 23, not 18).

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| KB-01 | 18-01, 18-02, 18-05, 18-09 | Admin creates an article with title and Markdown body | SATISFIED | Create page, `SaveArticle::create()` |
| KB-02 | 18-02, 18-07, 18-10 | Own view and edit screens | SATISFIED | View and Edit pages, tests |
| KB-03 | 18-03, 18-06, 18-07 | Safe rendering | SATISFIED | Truth 2 |
| KB-04 | 18-06 | `> [!NOTE]` callout | SATISFIED | `CalloutExtension`, `CalloutTest` |
| KB-05 | 18-03, 18-06 | Copy button on every code block | SATISFIED | Server markup tested; click behaviour in human verification |
| KB-06 | 18-09, 18-10 | Server-rendered preview | SATISFIED | `previewHtml()`, tests |
| KB-07 | 18-01, 18-08 | Archive and restore | SATISFIED | Truth 1 |
| KB-08 | 18-05, 18-10 | Stale save rejected, not merged | SATISFIED | Truth 6 |
| SEC-01 | 18-04, 18-05, 18-09, 18-10 | Every save scans for secrets, message names rule and line, never the value | PARTIAL | Scan on every content-changing save, message contract holds; evasion shapes not found (WR-01). Restore clause is Phase 19 (VER-03) |
| SEC-02 | 18-04, 18-05, 18-09, 18-10 | Unambiguous secrets block, ambiguous need acknowledgment and mark sensitive | PARTIAL | Mechanism correct; a PEM key in indented, quoted or escaped form is downgraded to ambiguous (CR-01) |
| SEC-03 | 18-05, 18-09, 18-10 | One-save override, logged without content | SATISFIED | Truth 5 |
| VER-01 | 18-01, 18-04, 18-05, 18-10 | Immutable gap-free versions, none for a no-op save | SATISFIED | Truth 6 (edge IN-02 noted) |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 30-32 | Anchored PEM pattern (column 0 only) | Blocker | CR-01, see gaps |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 24 | Narrow invisible-character strip, no unescape view | Blocker for SEC-01 intent | WR-01, see gaps |
| `resources/js/knowledge-editor.js` | 164-170 | Observer bound to `document.body` in SPA mode | Warning | WR-02, needs browser |
| `app/Domain/Knowledge/Guard/SecretScanner.php` | 76-84, 319 | 256-character cap on a random run, password value with spaces | Info | IN-01, silent pass in the ambiguous tier |
| `app/Domain/Knowledge/Support/MarkdownNormaliser.php` | 63-69 | Final newline after code fence kept | Info | IN-02 |
| `app/Domain/Knowledge/Support/LinkAndImagePolicy.php` | 12-18 | Possible inert `<a>` without href | Info | IN-03 |
| `SaveArticle.php`, both form pages | catch blocks | `ArticleWriteFailed` and `ModelNotFoundException` not caught | Info | IN-04 |
| `KnowledgeMarkdown.php`, `KnowledgeArticle.php`, migrations | constants | Body limit defined in four places | Info | IN-05 |
| `KnowledgeArticleResource.php` | editor config | EasyMDE loads remote images while editing | Info | IN-06, Filament default |

No `TBD`, `FIXME`, `XXX`, `TODO` or `HACK` markers in any file the phase added or changed under `app/Domain/Knowledge`, the knowledge resource, the knowledge JS/CSS, views, lang and migrations.

The disposition ledger `18-REVIEW-DISPOSITION.md` lists all nine findings as `open` (untriaged); none is resolved in code.

### Human Verification Required

#### 1. Task-list toolbar button after SPA navigation (WR-02)

**Test:** Reach the edit screen through the list or view screen, switch to Preview and back to Write.
**Expected:** The task-list toolbar button is still present.
**Why human:** Derived from source reading only; needs a real browser.

#### 2. Copy button click

**Test:** Click Copy on a rendered code block and paste.
**Expected:** The pasted text equals the code exactly; label feedback resets; fallback selects the code.
**Why human:** Browser clipboard behaviour.

#### 3. Visual check of rendered blocks and preview parity

**Test:** Compare the view screen and the Preview tab for an article with a table, task list, link, highlighted code and a callout.
**Expected:** Formatted blocks, highlighted callout, identical look.
**Why human:** Appearance.

### Gaps Summary

One must-have truth fails and one is partial, both in `SecretScanner`:

1. **CR-01 (blocker).** A PEM private key that is indented, blockquoted, or embedded as a JSON string with escaped newlines is classified as an ambiguous `high_entropy_string` instead of the blocking `pem_private_key`. The Admin can save it with "save anyway", although D-01 rules that a private key never saves. The key then lives in the article and in every append-only version row. This is the likeliest way a key lands in a note (YAML, CI config, service-account JSON). Confirmed against the real class.
2. **WR-01 (partial, same root area).** Markdown-escaped underscores, numeric entities and soft hyphens hide Blocking-class tokens completely, so a save passes silently. Confirmed for the escaped-underscore shape.

Both belong to one focused closure plan (`/gsd-plan-phase --gaps`): widen the PEM rule and the invisible-character fold, scan a rendered-equivalent view per line, and add fragment-assembled tests for each shape. Nothing else blocks the goal. The remaining review items (WR-02, IN-01 to IN-06) are hardening or browser-verification items; the owner may triage them with the gaps. The most relevant to a closure plan is WR-02 (one-line observer change) and IN-04 (catch `ArticleWriteFailed` and `ModelNotFoundException` so a refused save keeps its text and says why).

Verification limits: the full test suite was still running against the shared test database, so I ran only the pure Unit knowledge suite (148 passed) and the scratch scanner probes; the Feature, Isolation, Concurrency and Arch results are taken from the caller's report of a green targeted gate and from reading the tests and the code under test.

---

_Verified: 2026-11-25_
_Verifier: Claude (gsd-verifier)_
