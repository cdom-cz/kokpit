---
phase: quick-261030-gby
plan: 01
subsystem: tasks
tags: [markdown, filament, clipboard, html-to-markdown, rich-text]
requires:
  - phase: tasks
    provides: "ViewTask page, RichText sanitiser, TaskResource::canAccess()"
provides:
  - "RichTextMarkdown::convert()/document(): stored task HTML to Markdown"
  - "copyMarkdown header action on the Admin task page (preview modal plus clipboard button)"
affects: [tasks]
tech-stack:
  added: ["league/html-to-markdown 5.2.3 (MIT)"]
  patterns: ["sanitise via RichText::render() then convert", "clipboard copy from modal state via alpineClickHandler (same as ListApiTokens)"]
key-files:
  created:
    - app/Domain/Shared/Text/RichTextMarkdown.php
    - app/Domain/Shared/Text/StrikethroughConverter.php
    - tests/Feature/Tasks/TaskMarkdownCopyTest.php
    - tests/Unit/Text/RichTextMarkdownTest.php
  modified:
    - composer.json
    - composer.lock
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - lang/cs/kokpit.php
key-decisions:
  - "Used league/html-to-markdown rather than a hand-rolled DOM walker; ATX headings, strip_tags, TableConverter and an own StrikethroughConverter"
  - "Description is re-sanitised through RichText::render() before conversion"
  - "Action visible only while TaskResource::canAccess(); fillForm re-checks it with abort_unless(..., 403)"
requirements-completed: [TA-01]
status: complete
commits: 3
plan_head_before: ef89cad7657e3464ae65a8f68403b26a56b4570f
plan_head_after: cf26f146fb15004560bad28b5cfd32d41339dccc
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
duration: n/a
completed: 2026-10-30
---

# Phase quick-261030-gby Plan 01: Markdown copy of the task assignment Summary

The Admin task page now has a gray "Kopírovat jako Markdown" header action. It opens a read-only preview of `# <title>` plus the description converted from HTML to Markdown, with a one-click copy button, and writes nothing.

## What was built

- **Dependency:** `league/html-to-markdown` `^5.2`, resolved to 5.2.3 (source github.com/thephpleague/html-to-markdown, requires ext-dom and ext-xml). `composer.lock` gained only this package (92 added lines, one changed line for the content hash). The licence gate (`composer licenses --locked --format=json | php scripts/check-licenses.php`) passes: 227 packages, all allowed. `composer require` reported "No security vulnerability advisories found".
- **`RichTextMarkdown`:** `convert(?string)` runs `RichText::render()` first, converts with ATX headings and `strip_tags`, then normalises newlines (CRLF to LF, whitespace-only lines emptied, runs of 3+ newlines cut to 2, trimmed). `document(string, ?string)` builds the one-line H1 from the title with collapsed whitespace, a blank line, the body and one newline; with no body only the H1 line.
- **`StrikethroughConverter`:** del, s and strike become GFM `~~text~~`, whitespace kept outside the markers. Registered together with the library `TableConverter`.
- **`ViewTask::copyMarkdownAction()`:** modal with a read-only monospace textarea (select-all on focus/click), a "Kopírovat" button using the `ListApiTokens` alpineClickHandler pattern, and a "Zavřít" cancel; no submit. `fillForm` starts with `abort_unless(TaskResource::canAccess(), 403)`. Registered right after `addTimeAction()`.
- **Czech strings:** `kokpit.tasks.markdown.{action,heading,description,label,copy,copied,close}`.
- **Tests:** `TaskMarkdownCopyTest` (Feature, 10 cases) and `RichTextMarkdownTest` (Unit, 10 cases).

Real output check (via a throwaway script, not committed): the editor's h2/h3, bold, italic, `~~strike~~`, links, nested bullets, ordered list, blockquote and a GFM table with a `|---|---|` separator all convert as specified; underline collapses to plain text.

## Commits

| Task | Commit | Description |
| ---- | ------ | ----------- |
| 1 | d919f0f | Dependency, RichTextMarkdown, ViewTask action, Czech strings, exact-output Feature test |
| 2 | 65f7379 | StrikethroughConverter, TableConverter registration, Unit fidelity test |
| 3 | cf26f14 | Surface proofs: wiring, empty, archived, forged description, Partner refusals |

## Verification done in the worktree

- `php -l` on every touched PHP file; the planned grep gates; `pint --test` on all touched files (passed).
- Unit file `tests/Unit/Text/RichTextMarkdownTest.php` was run on the host (no DB needed): 10 passed, 43 assertions. Before `StrikethroughConverter` and `TableConverter` were registered it failed on the strike expectation, as planned (RED), then went green.
- Licence gate passed; `scripts/check-sensitive.sh` clean over every touched file; the lefthook sensitive-content and gitleaks hooks passed on all three commits.
- Invariant: `git diff 6f1ad6f -- app/Filament/Partner app/Filament/Support/TaskColumns.php app/Domain/Tasks/Policies app/Filament/Resources/TaskResource.php` is empty.

## Pending post-merge verification (not run in the worktree, by project rule)

The Feature tests need the database, and PHPStan (larastan) boots the application, which fails on the host without a DB. All of the following must run in DDEV on the HEAD branch after the merge:

1. `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskMarkdownCopyTest.php tests/Unit/Text/RichTextMarkdownTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskEscalationTest.php tests/Feature/Tasks/RichTextRenderingTest.php tests/Feature/Tasks/RichTextSanitiserTest.php tests/Feature/TimeTracking/TaskAddTimeTest.php tests/Isolation/PartnerTaskVisibilityTest.php tests/Unit/LicenceCheckTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest --compact --testsuite=Arch`
3. `ddev exec vendor/bin/pint --test`
4. `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
5. `ddev composer check-licenses`
6. The full suite the CI way: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (no `--parallel`)
7. Human check in the browser over https on DDEV (Clipboard API): open a task with a formatted description, click "Kopírovat jako Markdown", confirm the preview, click "Kopírovat" (toast "Markdown je zkopírovaný do schránky", paste equals the preview), click into the textarea (selects all), "Zavřít" closes with nothing changed.

Points the Feature file leans on that could not be exercised here and are the likeliest to need a tweak if red: `Livewire` `->get('mountedActions.0.data.markdown')` for reading the modal state, `assertActionHidden` after `actingAs($partner)` on an already mounted component, and the Partner seeing the task on `ViewPartnerTask` in the `assertActionDoesNotExist` case.

## Deviations from Plan

- **[Rule 3 - Blocking] Dependency installed with `--no-install --no-scripts`.** The worktree had no `vendor/`, so `composer require league/html-to-markdown:^5.2 --no-install --no-scripts --no-interaction` was used to update only `composer.json` and `composer.lock`. Afterwards `composer install --no-scripts` was run once so the Unit file could be run on the host; `vendor/` is gitignored and `git status` showed only the intended files.
- The plan's Task 1 listed `RichTextMarkdown` without the table and strike converters; they were added in Task 2 as the plan describes, so Task 1's version was already fully functional for the exact-output case.
- No `Rule 1/2/4` deviations. No auth gates. No package-legitimacy checkpoint was needed.

## Known Stubs

None.

## Threat Flags

None. The new surface (one Livewire action on an AdminOnly page) is covered by threats T-261030-gby-01 to -04 in the plan; no new endpoint, auth path or schema change was added.

## Self-Check: PASSED

- Files present: RichTextMarkdown.php, StrikethroughConverter.php, ViewTask.php, kokpit.php (lang/cs), TaskMarkdownCopyTest.php, RichTextMarkdownTest.php, composer.json, composer.lock.
- Commits d919f0f, 65f7379, cf26f14 are on `worktree-agent-ab090683c71957658`; measured count since the plan base `ef89cad` is 3.
