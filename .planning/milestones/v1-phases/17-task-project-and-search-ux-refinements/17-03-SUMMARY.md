---
phase: 17-task-project-and-search-ux-refinements
plan: 03
subsystem: tasks-ui
tags: [filament, rich-text, fi-prose, sanitiser, requirements]
requires: []
provides:
  - "fi-prose formatting of read-only task descriptions on the Admin task page, the Partner task page and the board card preview"
  - "P17-01 to P17-10 registered in REQUIREMENTS.md with traceability to Phase 17"
affects: [17-02]
tech-stack:
  added: []
  patterns:
    - "TextEntry::prose() only adds the fi-prose class; the markup stays the output of RichText::render()"
key-files:
  created:
    - tests/Feature/Tasks/RichTextRenderingTest.php
  modified:
    - app/Filament/Support/TaskColumns.php
    - resources/views/filament/pages/partials/task-preview.blade.php
    - .planning/REQUIREMENTS.md
decisions:
  - "R-17-03-4: links keep the sanitiser's forced target=_blank and rel; the UI-SPEC 'same tab' line is not applied, so the one sanitiser is not loosened"
status: complete
commits: 3
plan_head_before: 7430d924fe5b099a45286a969d3480e362167b50
plan_head_after: 7bc470d3aa1af57a0088c60f6f10f06d4563d3ec
actuals:
  tokens: 9000
  tasks: 3
  commits: 3
completed: 2026-10-29
---

# Phase 17 Plan 03: Rich-text rendering and Phase 17 requirements Summary

Task descriptions now show their formatting (lists, headings, quotes, tables, emphasis) on the Admin task page, the Partner task page and the board card preview by adding the `fi-prose` class, while `RichText::render()` stays the only sanitiser.

## What was done

- **Task 1 (tracer), commit 3f558ba:** `->prose()` on the Admin description `TextEntry` in `TaskColumns::adminEntries()`. New `tests/Feature/Tasks/RichTextRenderingTest.php` with a formatting case and a hostile-description case (script, image, event handlers, style attribute, relative link written around the Action) for the Admin page. The tests parse the page with DOMDocument and check the content of the `fi-prose` elements.
- **Task 2, commit ae6c63c:** `->prose()` on the Partner description entry and `class="fi-prose"` on the description container of `task-preview.blade.php` (inline `overflow-x: auto` scroll kept). Tests added for the Partner page, the board `preview` action (formatting and hostile case) and the comment thread in the Admin and Partner relation managers (the thread view already wraps bodies in `fi-prose`; untouched, 17-02 owns it).
- **Task 3, commit 7bc470d:** `.planning/REQUIREMENTS.md` gains the section "Task, Project and Search UX Refinements" (P17-01 to P17-10), ten `Pending` traceability rows after the P16 rows, coverage 144 total / 143 mapped, and the new footer.

## Verification

- Worktree: `php -l` clean, Pint `--test` passed on the touched PHP files (run with the main checkout's binary, no DB), the plan's grep acceptance checks pass (`->prose()` count 2, `RichText::render($state)` count 2, no `html()`/`markdown()` in code lines, preview container carries `class="fi-prose"`), `scripts/check-sensitive.sh` clean on every commit, REQUIREMENTS.md verify command OK.
- NOT run (CLAUDE.md, worktrees): the DB-backed Pest suite and PHPStan. Post-merge on the HEAD branch the orchestrator should run:
  - `ddev exec vendor/bin/pest tests/Feature/Tasks/RichTextRenderingTest.php tests/Feature/Tasks/RichTextSanitiserTest.php tests/Feature/Tasks/PartnerTaskDescriptionTest.php tests/Feature/Tasks/TaskBoardTest.php`
  - the plan gate: `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation/PartnerTaskVisibilityTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
  - The new tests were written without being executed; points most likely to need a fix if one fails: the DOM extraction helper `richRenderProse()` (libxml HTML parsing of the Livewire page) and `richRenderPreview()` (reads the modal content of the mounted `preview` action, like the existing `taskBoardPreviewHtml()`).
- Human check still open (plan Task 2): visual check of lists, headings, quote, link colour and the sideways-scrolling wide table in light and dark theme on the three surfaces.

## Deviations from Plan

None - plan executed as written. One housekeeping note: the per-plan commit ledger file was not created (the tool refused a compound `git rev-parse --git-dir` command); `commits:` was measured against the known base `7430d92` with `git rev-list --count`.

## Known Stubs

None.

## Threat Flags

None. T-17-08 is mitigated: no `html()`/`markdown()`, `RichText::render()` remains the formatter, and the tests render a forged description on all three surfaces.

## Self-Check: PASSED

- tests/Feature/Tasks/RichTextRenderingTest.php: found
- Commits 3f558ba, ae6c63c, 7bc470d: on the branch
