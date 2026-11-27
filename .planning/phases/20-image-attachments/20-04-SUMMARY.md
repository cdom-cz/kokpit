---
phase: 20-image-attachments
plan: 04
subsystem: knowledge
tags: [commonmark, html-sanitizer, markdown, images, css, lang]
status: complete

requires:
  - phase: 18-knowledge-center
    provides: the one renderer (KnowledgeMarkdown), the link and image policy, the sanitiser seam
  - phase: 19-knowledge-versions
    provides: the lang pattern and KnowledgeLangTest dataset style
provides:
  - "ImageReferences: the one parser and builder of kokpit-attachment:<uuid>"
  - "ImageContext: the per-call statement of which images the renderer may show"
  - "InternalImageSrcSanitizer: the img src rule (internal route only)"
  - "KnowledgeMarkdown::render(string, ?ImageContext) with MissingImage fallback"
  - "prose stylesheet image rules and the full Czech copy of Phase 20 (knowledge.images.*)"
affects: [20-05, 20-06, 20-07, 20-08, 20-09, 20-10, 20-11]

plan_head_before: 8df70277797651f08d29980eecf394b528869a69
plan_head_after: 5570adec68f21d77020419f9dddf827e7c8b178e

actuals:
  tokens: 21000
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "per-call context on a singleton renderer: set before convert, reset in finally"
    - "custom CommonMark inline node plus renderer for markup that html_input escape would block"
    - "reviewed sanitiser parts registry in the seam test (KNOWLEDGE_SEAM_SANITISER_PARTS)"

key-files:
  created:
    - app/Domain/Knowledge/Support/ImageReferences.php
    - app/Domain/Knowledge/Support/ImageContext.php
    - app/Domain/Knowledge/Support/InternalImageSrcSanitizer.php
    - app/Domain/Knowledge/Support/MissingImage.php
    - app/Domain/Knowledge/Support/MissingImageRenderer.php
    - tests/Unit/Knowledge/ImageReferencesTest.php
  modified:
    - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
    - app/Domain/Knowledge/Support/LinkAndImagePolicy.php
    - resources/css/kokpit-prose.css
    - lang/cs/knowledge.php
    - tests/Unit/Knowledge/KnowledgeMarkdownTest.php
    - tests/Feature/Knowledge/KnowledgeLangTest.php
    - tests/Arch/KnowledgeSeamTest.php

key-decisions:
  - "InternalImageSrcSanitizer::PATTERN carries the D modifier so a value with a trailing newline is not admitted"
  - "ImageReferences UUID match refuses a uuid followed by more hex digits (malformed), still a superset of the rendered references"

requirements-completed: [IMG-01, IMG-02]

duration: 35min
completed: 2026-11-27
---

# Phase 20 Plan 04: Renderer image context, fallback and Czech copy Summary

The one knowledge renderer now turns `![alt](kokpit-attachment:<uuid>)` into an img on the authorised route only when the caller's per-call `ImageContext` lists the uuid; every other reference degrades to a muted alt-text span, external images stay alt text, and the sanitiser keeps an img src only in the shape `/admin/knowledge/attachments/<lowercase uuid>`.

## Accomplishments

- Task 1 (tracer): `ImageReferences` (`in()`, `idFromUrl()`, `markdown()`), `ImageContext` (`none()`, `of()`, `find()`, `ids()`), `InternalImageSrcSanitizer`, the internal branch of `LinkAndImagePolicy::useImages()`, and `KnowledgeMarkdown::render(string, ?ImageContext)` with the context reset in `finally`. Sanitiser config: `allowRelativeMedias(true)`, `allowElement('img', ...)`, `withAttributeSanitizer(new InternalImageSrcSanitizer)`. KnowledgeSeamTest gained `KNOWLEDGE_SEAM_SANITISER_PARTS`, a token-scan helper and tests (a part must lie in Support, still name the config type, and never `new` it).
- Task 2: `MissingImage` node and `MissingImageRenderer` (escaped alt text, or the translated `image.missing` for an empty or blank alt), registered at priority 10; the policy replaces an unlisted internal reference with it. The four image rules were added to `kokpit-prose.css` (all under `.kokpit-prose`, no background or filter on images).
- Task 3: the `images` block (37 keys) and `render.image.missing` in `lang/cs/knowledge.php`, pinned word for word in `KnowledgeLangTest` (36-row dataset, no-empty/no-key-echo test, placeholder test).
- Verified in the worktree without DB or migrations: all of `tests/Unit/Knowledge` (286 tests, 1847 assertions) pass through a scratch bootstrap that loads the main checkout's vendor with the worktree sources first; Pint passes on all touched files; a scratch script confirmed every lang dataset row equals the lang file.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Sanitiser pattern anchored with the D modifier**
- **Found during:** Task 1
- **Issue:** PCRE `$` also matches before a trailing newline, so `/admin/knowledge/attachments/<uuid>\n` would have passed the img src rule.
- **Fix:** `PATTERN` ends in `$#D`; a Unit case pins the trailing-newline refusal. The shape and meaning stay as R-20-04-3 (20-07's route-path match is unaffected).
- **Files modified:** app/Domain/Knowledge/Support/InternalImageSrcSanitizer.php, tests/Unit/Knowledge/KnowledgeMarkdownTest.php
- **Commit:** 87cca0d7

**2. [Rule 2 - Missing critical functionality] Exact end of a reference uuid**
- **Found during:** Task 1
- **Issue:** `ImageReferences::in()` would have matched the first 36 characters of a uuid followed by more hex digits, a malformed id that the renderer never resolves.
- **Fix:** the uuid pattern has a `(?![0-9a-fA-F])` lookahead; `idFromUrl()` uses `\A ... \z`. `in()` is still a superset for code blocks and links, as R-20-04-1 requires.
- **Commit:** 87cca0d7

None other: the plan was executed as written.

## Known Stubs

None.

## Threat Flags

None. The img src surface is the one covered by T-20-11 to T-20-14 (external images stay alt text, the sanitiser admits only the internal route shape, the context is reset in `finally`, the fallback node escapes the alt text).

## Verification still to run after the merge (DDEV, HEAD branch)

- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge tests/Feature/Knowledge/KnowledgeRenderingTest.php tests/Feature/Knowledge/KnowledgeLangTest.php tests/Arch/KnowledgeSeamTest.php` (the Arch and Feature runs and phpstan were not possible in the worktree: they boot the application)
- `ddev exec php artisan filament:assets` (the stylesheet changed)

## Self-Check: PASSED

- Created files exist: ImageReferences.php, ImageContext.php, InternalImageSrcSanitizer.php, MissingImage.php, MissingImageRenderer.php, ImageReferencesTest.php (all committed, `git status` clean before this file).
- Commits 87cca0d7, 5a5bd5bc, 5570adec are on the branch (3 commits measured against the base 8df70277).
- Acceptance greps: `?ImageContext $images = null` 1, `allowRelativeMedias(true)` 1, `withAttributeSanitizer(new InternalImageSrcSanitizer` 1, `InternalImageSrcSanitizer.php` in the seam test 1, `'images' => [` 1, `knowledge.images.` in the lang test 38.
