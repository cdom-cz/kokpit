---
phase: 18-knowledge-articles-and-secret-guard
plan: 06
subsystem: knowledge
tags: [commonmark, callouts, filament-assets, css, javascript, clipboard, gitignore]

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: "18-03 KnowledgeMarkdown renderer, CodeBlockRenderer chrome and KnowledgeServiceProvider binding"
  - phase: 18-knowledge-articles-and-secret-guard
    provides: "18-04 lang/cs/knowledge.php render keys (callouts, code, task)"
provides:
  - "CalloutExtension: top-level GitHub alert block quotes (NOTE, TIP, IMPORTANT, WARNING, CAUTION) become Callout nodes rendered as labelled div.kokpit-callout blocks"
  - "resources/css/kokpit-prose.css: one scoped prose stylesheet (callouts, code block chrome, token colours, tables, task items, overflow rules) for light and dark mode"
  - "resources/js/knowledge-copy.js: one delegated, text-only copy handler registered once"
  - "KnowledgeServiceProvider::boot() registers both as Filament assets of the app package"
  - "/public/css/app/ and /public/js/app/ ignore rules with test-gitignore.sh cases"
affects: [18-07 prose Blade view and view screen, 18-09 editor preview]

actuals:
  tokens: 10100
  tasks: 2
  commits: 2

plan_head_before: 746accd21e37221c5aa5adada48f4305a98f2098
plan_head_after: cc8ba4dceb798cdf65dc6d441bce9847bfb6c3a5
commits: 2

tech-stack:
  added: []
  patterns:
    - "Callout conversion in a DocumentParsedEvent listener that looks only at direct children of the document, so nesting stays a block quote"
    - "Theme values as custom properties (--kp-*) set on .kokpit-prose and overridden under .dark .kokpit-prose, so every other rule is written once"
    - "Icons through mask-image with an inline SVG data URI (stroke keyword black), coloured by background-color"

key-files:
  created:
    - app/Domain/Knowledge/Support/Callout.php
    - app/Domain/Knowledge/Support/CalloutExtension.php
    - app/Domain/Knowledge/Support/CalloutRenderer.php
    - resources/css/kokpit-prose.css
    - resources/js/knowledge-copy.js
    - tests/Unit/Knowledge/CalloutTest.php
    - tests/Feature/Knowledge/KnowledgeRenderingTest.php
  modified:
    - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
    - app/Providers/KnowledgeServiceProvider.php
    - .gitignore
    - scripts/tests/test-gitignore.sh

key-decisions:
  - "R-18-06-1..6 implemented as written in the plan; no ruling changed"
  - "Callout label comes only from the translator by node type; callout content is rendered by the normal child renderer and still passes the sanitiser"
  - "The two published asset directories are ignored; the source files stay trackable and are pinned in test-gitignore.sh"

patterns-established:
  - "Static checks of shipped CSS and JS in a Feature test (scoped selectors, type scale, hex literals, forbidden markup and evaluation sinks)"

requirements-completed: [KB-03, KB-04, KB-05]

duration: 40min
completed: 2026-11-25
status: complete
---

# Phase 18 Plan 06: Callouts, Prose Stylesheet and Copy Script Summary

**GitHub-style alert callouts in the knowledge renderer, plus one scoped prose stylesheet and one delegated copy script registered as Filament assets.**

## Accomplishments

- Task 1 (tracer): `> [!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]`, `[!CAUTION]` (any letter case) on a top-level block quote render as `div.kokpit-callout.kokpit-callout--{type}` with a `p.kokpit-callout__label` carrying the Czech label (Poznámka, Tip, Důležité, Varování, Pozor) and the quote's own Markdown content. An unknown type, text after the marker on the same line, a quote that merely contains the marker on a later line and any nested quote or callout stay ordinary block quotes. A marker-only callout renders the label and no empty paragraph.
- Task 2: `kokpit-prose.css` (386 lines, every selector under `.kokpit-prose` or `.dark .kokpit-prose`, four sizes, two weights, two hex literals for the keyword token) and `knowledge-copy.js` (one IIFE, one document-level click listener guarded by `window.kokpitKnowledgeCopyBound`). Both are registered in `KnowledgeServiceProvider::boot()` as `Css::make('kokpit-prose', ...)` and `Js::make('knowledge-copy', ...)` of package `app`.
- The copy handler copies `textContent` of the block's `pre code`, shows the server-provided "copied" label for 2000 ms, and on a missing or refused Clipboard API (also a synchronous throw) shows the "failed" label and selects the code text. It assigns only `textContent` and `dataset`.
- `/public/css/app/` and `/public/js/app/` are ignored; `test-gitignore.sh` pins the two published paths as ignored and the two sources as trackable (126 assertions pass).

## Task Commits

1. Task 1: callouts - `5813b20` (feat, knowledge)
2. Task 2: assets and ignore rules - `cc8ba4d` (feat, knowledge)

## Verification Performed in the Worktree

- `php -l` clean on every touched PHP file; `vendor/bin/pint --test` passes on all touched files.
- `php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge`: 148 passed (includes the new `CalloutTest`, 12 cases; run with a worktree-local, gitignored `vendor/` installed from the lock file).
- `bash scripts/tests/test-gitignore.sh`: 126 passed, 0 failed.
- Application container check without the database (tinker): the renderer returned the Czech callout label, "Kopírovat kód", `data-label-copied="Zkopírováno"`, `data-label-failed="Kopírování se nezdařilo"`, `aria-label="Kód: bash"` and "Hotovo:"; both assets resolve with package `app` and public paths `css/app/kokpit-prose.css` and `js/app/knowledge-copy.js`.
- The static CSS and JS assertions of the Feature test were also executed outside Pest with an equivalent script: no unscoped selector, sizes {28, 20, 16, 14}px, weights {400, 600}, exactly two hex literals both in `--kp-token-keyword`, no markup or evaluation sink, all required needles present.
- The copy handler was exercised in Node with a stubbed DOM: success copies the exact text, switches to the copied state and restores; a missing Clipboard API switches to the failed state.
- Not run in the worktree (project rule): `tests/Feature/Knowledge/KnowledgeRenderingTest.php` (needs the database), PHPStan (its bootstrap needs the application environment), the full suite.

## Post-merge Gate (orchestrator, DDEV)

`ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge tests/Feature/Knowledge && bash scripts/tests/run.sh && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, then `ddev exec php artisan filament:assets` for the browser checks below.

## Human Checks (end of phase)

From Task 2 `<human-check>`: Copy on a bash block pastes the exact block text and the label returns after about two seconds; denied clipboard permission shows "Kopírování se nezdařilo" and selects the code; Tab reaches the Copy button with a visible focus ring; five callouts and code tokens readable in light and dark mode; a wide table and a long code line scroll inside their wrappers, a long URL wraps, the page never scrolls sideways.

## Deviations from Plan

None. Plan executed as written. Notes that are not deviations:

- The Feature test splits selector lists on commas outside parentheses so `:is()` and `:where()` selectors are checked correctly.
- The gitignore trackable cases for the two source files were added to the existing trackable block rather than a new block; the "(Phase 18)" ignored block is new.
- The plan's TDD ordering was followed within each task (tests written and observed failing before the code for Task 1); each task is one commit because the tests and code of a task only make sense together and the hook runs on every commit.

## Known Stubs

None.

## Threat Flags

None. The copy script acts only on `[data-kokpit-copy]` inside `.kokpit-code`, reads `textContent` and data attributes and assigns only `textContent` and `dataset` (T-18-29, T-18-30); the callout label is taken from the translator, never from the body (T-18-31); this plan installs nothing (T-18-SC).

## Known Risks for Review

- Link colour `var(--primary-600)` follows the plan and UI-SPEC; the panel primary is amber, whose 600 shade has low contrast on white. Worth a look during the end-of-phase light-mode check.
- Icons and tints rely on `color-mix` and `mask-image`, which every current evergreen browser supports.

## Self-Check: PASSED

- FOUND: app/Domain/Knowledge/Support/Callout.php, CalloutExtension.php, CalloutRenderer.php
- FOUND: resources/css/kokpit-prose.css, resources/js/knowledge-copy.js
- FOUND: tests/Unit/Knowledge/CalloutTest.php, tests/Feature/Knowledge/KnowledgeRenderingTest.php
- FOUND commits: 5813b20, cc8ba4d
