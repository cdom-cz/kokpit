---
phase: 18-knowledge-articles-and-secret-guard
plan: 03
subsystem: knowledge
tags: [markdown, commonmark, html-sanitizer, tempest-highlight, xss, code-blocks]
requires: []
provides:
  - "App\\Domain\\Knowledge\\Support\\KnowledgeMarkdown::render(): the one hardened Markdown-to-HTML path of knowledge bodies"
  - "KnowledgeMarkdown singleton bound over the knowledge.render lang keys"
  - "Markup contract consumed by 18-06: kokpit-code, kokpit-code__head, kokpit-code__lang, kokpit-code__copy, kokpit-code__copy-label, kokpit-sr-only, kokpit-prose__plain, data-kokpit-copy, data-label-copied, data-label-failed"
  - "Tests\\Support\\KnowledgeHostileCorpus for later renderer and preview tests"
affects: [18-04, 18-06, 18-07, 18-09]
tech-stack:
  added: [tempest/highlight 2.30.0]
  patterns:
    - "AST link and image policy first, sanitiser second"
    - "Highlighter output accepted only when it round-trips to the exact code text"
    - "Renderer takes an injected translator closure, so Unit tests run without the application"
key-files:
  created:
    - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
    - app/Domain/Knowledge/Support/LinkAndImagePolicy.php
    - app/Domain/Knowledge/Support/CodeBlockRenderer.php
    - app/Domain/Knowledge/Support/TaskListMarkerRenderer.php
    - app/Providers/KnowledgeServiceProvider.php
    - tests/Support/KnowledgeHostileCorpus.php
    - tests/Unit/Knowledge/KnowledgeMarkdownTest.php
    - tests/Unit/Knowledge/CodeBlockTest.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/providers.php
key-decisions:
  - "Round-trip invariant extended with a class allowlist: the {~ ~} and {_ _} markers keep their text inside hidden spans, so the text check alone passes them; only class-only spans from a fixed hl-* set are accepted"
  - "Highlighter exceptions fall back to plain escaped code instead of failing the whole article render"
  - "KnowledgeMarkdown keeps the translator only as a constructor argument handed to the renderers (no property of its own)"
requirements-completed: [KB-03, KB-05]
status: complete
duration: ~35 min
completed: 2026-11-25
commits: 3
plan_head_before: 37d02d02ce17e0197662cf354a108fc8000e35ab
plan_head_after: b11b7c756c3b73ffc78673b50f80d385fc2eba2f
actuals:
  tokens: 10300
  tasks: 3
  commits: 3
---

# Phase 18 Plan 03: Hardened knowledge Markdown renderer Summary

One CommonMark pipeline with raw HTML escaped, links and images policed in the AST, a Kokpit-owned sanitiser, code blocks with a fixed Copy chrome over class-only Tempest highlighting that never changes the copied text, and a container singleton.

## What was built

- `KnowledgeMarkdown::render(string): HtmlString` (R-18-03-2): `html_input` escape, `allow_unsafe_links` false, `max_nesting_level` 20, `max_delimiters_per_line` 1000, core and GFM extensions, a sanitiser per R-18-03-4 with `withMaxInputLength(-1)`. A blank body returns an empty `HtmlString`; a body over `MAX_INPUT_LENGTH` (100 000) renders as `p.kokpit-prose__plain` with escaped text and no Markdown processing. The class calls no `__()` and no facade.
- `LinkAndImagePolicy` (R-18-03-3): an image becomes its alt text; a link whose URL is not http, https or mailto becomes its link text (javascript, data, vbscript, relative, protocol-relative, reference-style, autolink).
- `CodeBlockRenderer` (R-18-03-5): language allowlist `LANGUAGES`, fixed chrome with exactly one Copy button, highlighting through `tempest/highlight` with `CssTheme`, accepted only when the stripped and decoded highlight equals the code text and the spans carry only fixed `hl-*` classes; otherwise plain escaped text. Unknown language, an info string carrying markup and indented code render plain without a label.
- `TaskListMarkerRenderer` (R-18-03-6): disabled, aria-hidden checkbox followed by `span.kokpit-sr-only` with `task.done` or `task.open`.
- `KnowledgeServiceProvider` (R-18-03-7), registered after `PaymentsServiceProvider` in `bootstrap/providers.php`; the translator reads `knowledge.render.*`.
- `KnowledgeHostileCorpus` (R-18-03-8): 17 fictional hostile cases and four generators, every payload token assembled from fragments.

## Final LANGUAGES allowlist (R-18-03-5)

All 38 accepted words resolve in Tempest 2.30.0 (`getSupportedLanguageNames()`), so nothing was removed from the plan's list:
bash (bash, sh, shell, zsh), php, blade, sql, json, yaml (yaml, yml), xml, html, css, scss, javascript (javascript, js), typescript (typescript, ts), diff, dockerfile (dockerfile, docker), ini, dotenv (dotenv, env), nginx, apache, http, python (python, py), go, rust (rust, rs), toml, twig, markdown (markdown, md), terraform (terraform, tf).

## Composer (R-18-03-1)

`composer require --no-update league/commonmark:^2.10 symfony/html-sanitizer:^8.1 tempest/highlight:^2.30`, then `composer update tempest/highlight --no-install --no-scripts --no-interaction` worked without the fallback. The lock diff is `tempest/highlight` 2.30.0 (MIT, only `php ^8.4` required) plus `content-hash`; `league/commonmark` and `symfony/html-sanitizer` stay at their locked versions. `composer validate --no-check-publish` is valid; `composer check-licenses` reports 227 packages, all allowed. Composer printed advisories about already-present abandoned packages (`dragon-code/*`), unrelated to this plan.

## Adversarial limits (A4), measured on host PHP 8.6.0 with 1 GB memory limit

| Body (100 000 characters) | Extra peak memory | Time |
|---|---|---|
| bracket flood | 146.6 MB | 0.31 s |
| emphasis flood | 126.3 MB | 0.30 s |
| backtick flood | 1.3 MB | under 0.01 s |

All within the 256 MB / 5 s assertions. A deploy target below 256 MB `memory_limit` needs the 50 000 cap (research Pitfall 4, A4).

## Verification

- Host PHP 8.6.0 with a worktree-local `vendor/` (never committed, `/vendor/` is ignored): `vendor/bin/pest tests/Unit/Knowledge` gives 70 passed (389 assertions); `vendor/bin/pint --test` passed on all touched PHP files; `php -l` clean; `scripts/check-sensitive.sh` clean on every commit; lefthook (sensitive-content, gitleaks) passed on all three commits.
- NOT run in the worktree (needs the app and a database, per CLAUDE.md): PHPStan (larastan boots the application and failed without the DDEV database) and the DDEV Pest run. Post-merge plan gate on the HEAD branch: `ddev composer install && ddev composer check-licenses`, then `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Round-trip invariant alone accepted the blur and emphasis markers**
- **Found during:** Task 2 (marker test, red then green)
- **Issue:** Tempest renders `{~ x ~}` and `{_ x _}` with the marker text kept inside `<span style="display: none">` plus `hl-blur` / `hl-em` spans. `strip_tags` therefore still equals the code, so the plan's invariant accepted these blocks; only the sanitiser dropping `style` kept the text right, which is fragile and leaves unstyled foreign classes in the output.
- **Fix:** `CodeBlockRenderer::hasOnlyTokenClasses()` additionally requires no `style=` and that every span is `<span class="hl-...">` from a fixed set (`TOKEN_CLASSES`: the 12 token classes plus `hl-addition`, `hl-deletion`, `hl-diff-context` for diff). Anything else falls back to plain escaped text.
- **Files modified:** app/Domain/Knowledge/Support/CodeBlockRenderer.php, tests/Unit/Knowledge/CodeBlockTest.php
- **Commit:** 458a41f

**2. [Rule 2 - Missing critical functionality] Highlighter failure must not break the article**
- **Found during:** Task 2
- **Issue:** A PCRE or highlighter error on pathological code would throw out of `render()` and take the whole article view down.
- **Fix:** the highlight call is wrapped; any `Throwable` falls back to plain escaped code.
- **Commit:** 458a41f

### Other notes

- TDD: each task was written test first and seen red before the implementation (Task 2 showed 25 failures before `CodeBlockRenderer` existed), but red and green went into one commit per task, as the plan's commit instruction reads, not into separate `test` and `feat` commits.
- The task-list marker text holds one space inside `span.kokpit-sr-only` as R-18-03-6 says; CommonMark already leaves a leading space on the item text, so the raw text shows two spaces, which HTML collapses. The test normalises whitespace.
- Tests use `Dom\HTMLDocument` (PHP 8.4+ HTML5 parser) as the DOM helper.

## Known Stubs

None.

## Threat Flags

None. The plan's threat register T-18-10 to T-18-16 and T-18-SC are mitigated as planned and proved by the hostile-corpus, code-block and flood tests; no new endpoint, auth path or schema was added.

## Self-Check: PASSED

- Files present: all eight created files and three modified files verified on disk.
- Commits present on the branch: df6b047 (Task 1), 458a41f (Task 2), b11b7c7 (Task 3).
