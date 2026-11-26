---
phase: 19-folders-tags-and-version-history
plan: 03
subsystem: knowledge
tags: [diff, sebastian-diff, blade, css, accessibility, xss]
requires: []
provides:
  - "App\\Domain\\Knowledge\\Support\\VersionDiff (compare(), lines(), CONTEXT_LINES, MAX_ROWS, MAX_COMPARED_LINES)"
  - "VersionDiffResult and DiffRow value objects"
  - "filament.knowledge.version-diff Blade component"
  - "kokpit-diff Filament stylesheet asset"
affects: [19-06 compare page, 19-11 restore modal, 19-08 tree rules in the same stylesheet]
tech-stack:
  added: []
  patterns: ["line diff over lists of lines with PHP-side prefix/suffix trim as work budget", "all selectors of a stylesheet under one root prefix"]
key-files:
  created:
    - app/Domain/Knowledge/Support/VersionDiff.php
    - app/Domain/Knowledge/Support/VersionDiffResult.php
    - app/Domain/Knowledge/Support/DiffRow.php
    - tests/Unit/Knowledge/VersionDiffTest.php
    - resources/views/filament/knowledge/version-diff.blade.php
    - resources/css/kokpit-diff.css
    - tests/Feature/Knowledge/VersionDiffViewTest.php
  modified:
    - app/Providers/KnowledgeServiceProvider.php
decisions:
  - "R-19-03-1 kept: sebastian/diff ^9.0.1 is promoted to require only after the merge, through ddev composer (composer.json and composer.lock untouched in the worktree)"
  - "Stylesheet classes use a kokpit-diff-block wrapper and kokpit-diff__* element classes next to the .kokpit-diff table, so title block, summary and notice outside the table are styled under the same prefix"
metrics:
  tasks: 2
  completed: 2026-11-26
status: complete
commits: 2
plan_head_before: 3425ad9dd39e0175690942645993119e6df41755
plan_head_after: b1fa2e0cf7417518e5915563041d70deeecc2232
actuals:
  tokens: 28000
  tasks: 2
  commits: 2
---

# Phase 19 Plan 03: Version diff engine and component Summary

Unified line diff of two stored article bodies over `sebastian/diff`: numbered rows, three lines of context with collapsed separators, a 1,500-row cap, a 10,000-line work budget, and an escaped, accessible Blade table styled by one new stylesheet asset.

## What was built

- `VersionDiff::compare()` splits both bodies with the secret guard's expression (CRLF, CR, LF), returns `identical` for equal line lists, reports `tooLarge` when the two differing middles (after the common start and end) together exceed 10,000 lines without calling the library, otherwise feeds lists of lines to `Differ::diffToArray()` (warning entries dropped), walks the result with two line counters, collapses unchanged runs (3 lines of context; a run of exactly 6 between two changes stays expanded, 7 collapse to a separator of 1) and cuts the rows at 1,500 while `added`/`removed` still count the whole diff.
- `DiffRow` and `VersionDiffResult` are readonly values; texts are stored lines verbatim.
- `resources/views/filament/knowledge/version-diff.blade.php` renders summary, title block (two marked lines or a muted unchanged line), then the identical state, the too-large state, or the table (visually hidden caption and header row, old/new numbers, marker, text, visually hidden "added"/"removed" prefix, collapsed rows spanning four columns) plus the truncation notice. Escaped echo only; an optional scroll wrapper takes `$maxHeight`.
- `resources/css/kokpit-diff.css`, registered as `Css::make('kokpit-diff', ...)` after `kokpit-prose`; every selector starts with `.kokpit-diff` or `.dark .kokpit-diff`; sizes 14/16, weights 400/600.

## Deviations from Plan

None - plan executed as written. Notes on judgement calls:

- The marker cell keeps the character inside an `aria-hidden` span (not on the `td`) so the column count stays intact for assistive technology; the visually hidden prefix in the text cell carries the meaning.
- Line-number and muted colour use `var(--gray-600)` light (as `kokpit-prose.css` does) rather than `--gray-500`, for contrast on the tinted rows.

## Not run in the worktree (no vendor, no database, per project rules)

Only `php -l` on every new or changed PHP file, the plan's grep acceptance checks and `scripts/check-sensitive.sh` ran. Blade compilation of the new view and all Pest tests (`tests/Unit/Knowledge/VersionDiffTest.php`, `tests/Feature/Knowledge/VersionDiffViewTest.php`, `tests/Arch/KnowledgeSeamTest.php`, `tests/Feature/Knowledge/KnowledgeRenderingTest.php`) are unverified until the merge. The tests were written without running them, so expect possible small fixes at the first DDEV run.

## Post-merge steps still owed (HEAD branch, in DDEV)

1. `ddev composer require sebastian/diff:^9.0.1` (moves the already locked 9.0.1 from packages-dev to packages; the lock diff must show only that move plus the content-hash), then `ddev composer validate --no-check-publish` and `ddev composer check-licenses`; review `git diff composer.json composer.lock`, run `scripts/check-sensitive.sh composer.json composer.lock`, commit as `build(deps): require sebastian/diff at runtime` through the hook. Until then the diff works in development only (the package is a dev dependency); this is the only reason production would lack it.
2. `ddev exec php artisan filament:assets` to publish the stylesheet.
3. Run `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge tests/Feature/Knowledge/VersionDiffViewTest.php tests/Arch/KnowledgeSeamTest.php` (never `--parallel`).
4. Measure the worst case (5,000 against 5,000 completely different lines) with the timing command of Task 1 and record the milliseconds here and in the class docblock; a value above 1000 ms is reported to the owner per OD-2 (constants stay unchanged). Measured value: pending.
5. End-of-phase human check in the browser: added and removed rows legible in light and dark mode, long lines wrap without horizontal scrolling.

## Known Stubs

None.

## Threat Flags

None. T-19-08 (stored XSS) is mitigated by plain-text rows and escaped echo only (hostile corpus test written); T-19-09 (DoS) by the prefix/suffix trim, the 10,000-line budget and the 1,500-row cap; T-19-52 (supply chain) is handled by the post-merge `ddev composer` step above.

## Self-Check: PASSED

- FOUND: app/Domain/Knowledge/Support/VersionDiff.php, VersionDiffResult.php, DiffRow.php, tests/Unit/Knowledge/VersionDiffTest.php, resources/views/filament/knowledge/version-diff.blade.php, resources/css/kokpit-diff.css, tests/Feature/Knowledge/VersionDiffViewTest.php
- FOUND commits: adaaaa34, b1fa2e0c
