---
phase: quick-261030-gcw
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/livewire/signal/day.blade.php
  - tests/Feature/Signal/SignalTaskLinkTest.php
autonomous: true
requirements: [P17-07]

estimate:
  tokens: 25000
  raw_tokens: 25000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "When the Admin types at least two characters into the Signal add field and unfinished Kokpit tasks match, the suggestion panel under the field is exactly as wide as the text input: its left and right edges line up with the field, instead of being capped at 14rem"
    - "In the wider panel each suggestion shows much more of its task title and of the `reference · project` line, so the Admin can tell which task each row is. Text sizes, the two-line layout and the ellipsis on text that still overflows stay as they are"
    - "Nothing else changes: the keyboard navigation (arrow keys, Enter, Escape, Tab), the pick, the clear control, the spinner, the listbox ARIA attributes and the stored snapshot text behave exactly as before"
  artifacts:
    - path: "resources/views/livewire/signal/day.blade.php"
      provides: "Suggestion listbox panel carries the Filament width modifier `fi-width-none`, which removes the forced 14rem max-width, so its inline `width: 100%` spans the input container"
      contains: "class=\"fi-dropdown-panel fi-width-none\""
    - path: "tests/Feature/Signal/SignalTaskLinkTest.php"
      provides: "Regression assertion in the listbox test that the panel renders with the full-width modifier"
      contains: "fi-width-none"
  key_links:
    - from: "resources/views/livewire/signal/day.blade.php (listbox div with role=listbox)"
      to: "vendor/filament/support/resources/css/components/dropdown/index.css (.fi-dropdown-panel and .fi-dropdown-panel.fi-width-none)"
      via: "CSS class modifier: `.fi-dropdown-panel` sets `max-width: 14rem !important`; `.fi-dropdown-panel.fi-width-none` sets `max-width: none !important` and wins because its selector is more specific"
      pattern: "fi-dropdown-panel fi-width-none"
    - from: "listbox div (position: absolute; left: 0; width: 100%)"
      to: "its positioned parent, the combobox wrapper div (position: relative; flex: 1 1 14rem) that holds only the text input"
      via: "absolute positioning: 100% resolves to the width of the combobox wrapper, which equals the width of the text input"
      pattern: "top: 100%; left: 0; width: 100%;"
---

<objective>
Widen the task suggestion panel of the Signal add field (the type-ahead "hint" listing unfinished Kokpit tasks) to the full width of the text input, so the task title and its `reference · project` line can be read.

Root cause (found while planning, confirmed in the compiled `public/css/filament/filament/app.css`): the listbox in `resources/views/livewire/signal/day.blade.php` reuses Filament's `fi-dropdown-panel` class for its look. That class applies `max-width: 14rem !important` (from `vendor/filament/support/resources/css/components/dropdown/index.css`, `max-w-[14rem]!`). The panel's inline `width: 100%` is therefore capped at 14rem, and the title and reference line, which use `white-space: nowrap` with an ellipsis, are cut off after a few words. An inline `max-width` cannot override this, because a stylesheet `!important` beats an inline declaration that is not important.

Fix: add Filament's own width modifier `fi-width-none` to the panel. The compiled theme already contains `.fi-dropdown-panel.fi-width-none{max-width:none!important}`, a component rule that does not depend on Tailwind content scanning. Its selector is more specific than the plain `.fi-dropdown-panel`, so it wins, and the existing inline `width: 100%` then makes the panel as wide as the combobox wrapper, which holds only the text input. The select box and the Add button are siblings of that wrapper, so they are not included in the panel width.

Claude's discretion (documented): the font sizes (0.875rem title, 0.75rem reference line), the two-line layout and the ellipsis stay unchanged. The request is about width, and the full width is what makes the text readable. The style matches Filament's dropdown list items.

Purpose: the Admin sees which Kokpit task each suggestion is before picking it (P17-07 type-ahead).
Output: one class modifier in the Blade view, plus a regression assertion in the existing listbox test.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@resources/views/livewire/signal/day.blade.php
@tests/Feature/Signal/SignalTaskLinkTest.php

Facts for the executor (no need to re-derive):
- The listbox element is the `div` with `role="listbox"` and `id="{{ $listId }}"`, around line 263 of `resources/views/livewire/signal/day.blade.php`. Its class attribute currently holds only `fi-dropdown-panel`. Its inline style starts with `position: absolute; top: 100%; left: 0; width: 100%;`.
- Its positioned parent is the Alpine `x-data` combobox wrapper, around line 209, with inline style `position: relative; flex: 1 1 14rem;`. That wrapper holds only the `x-filament::input.wrapper` with the text input, the absolutely positioned suffix (spinner or clear cross) and the listbox.
- The `.fi-select-input .fi-dropdown-panel` rule (`max-width: 100% !important`) applies only inside a Filament select input, so it has no effect here.
- Existing test to extend: `it('renders a listbox with one option per suggestion once two characters match, and nothing for a miss', ...)` in `tests/Feature/Signal/SignalTaskLinkTest.php` (around line 350). It builds `$html` after `->set('newTitle', 'login')` and asserts on `role="listbox"` and `id="signal-suggestions-2026-10-12"`. It uses the DB (`sigLinkProject()`, `sigLinkTask()`), so it runs only after the merge, in DDEV.

Baselines measured at HEAD (gates below were checked against these):
- `grep -c 'class="fi-dropdown-panel fi-width-none"' resources/views/livewire/signal/day.blade.php`: 0, must become 1
- the count of the old class attribute holding only the base class: 1, must become 0
- `grep -c 'top: 100%; left: 0; width: 100%;' resources/views/livewire/signal/day.blade.php`: 1, must stay 1
- the count of raw (unescaped) Blade echoes in the view: 0, must stay 0
- `grep -c 'fi-width-none' tests/Feature/Signal/SignalTaskLinkTest.php`: 0, must become at least 1
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Let the Signal suggestion panel span the full width of the text input</name>
  <files>resources/views/livewire/signal/day.blade.php</files>
  <action>
In `resources/views/livewire/signal/day.blade.php`, on the suggestion listbox (the `div` with `role="listbox"` and `id="{{ $listId }}"` inside the `@if ($showList)` block), extend the class attribute from the single base class to `fi-dropdown-panel fi-width-none`, in that order and separated by one space. That is the whole fix. Filament's `.fi-dropdown-panel.fi-width-none` rule lifts the forced 14rem max-width, so the existing inline `width: 100%` resolves against the combobox wrapper (`position: relative; flex: 1 1 14rem;`), which holds only the text input.

Keep everything else on the panel as it is: `x-show="open"`, `id`, `role`, and the full inline style (`position: absolute; top: 100%; left: 0; width: 100%; margin-top: 0.25rem; z-index: 20; max-height: 25rem; overflow-y: auto; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.25);`). Do not add an inline `max-width`. An inline declaration that is not important cannot beat the stylesheet `!important`, and an inline `!important` would bypass the Filament modifier the theme already ships.

Do not touch the option buttons, their font sizes, the `nowrap` and ellipsis spans, the Alpine `x-data`, the keyboard handlers, the ARIA attributes, the suffix or the form layout. Every echo stays an escaped double-brace Blade echo. Do not add a raw (unescaped) echo.

Optionally extend the Blade comment above the combobox by one short sentence: the panel uses the `fi-width-none` modifier so that it matches the field width instead of Filament's 14rem dropdown cap. Write it in English and use no real data.

Verification order: the first `<automated>` command (grep gates on the view) is safe in a worktree. The second checks the compiled Filament theme, which is gitignored and exists only in the main checkout, so run it after the merge into the HEAD branch.
  </action>
  <verify>
    <automated>test "$(grep -c 'class="fi-dropdown-panel fi-width-none"' resources/views/livewire/signal/day.blade.php)" = 1 && test "$(grep -c 'class="fi-dropdown-panel"' resources/views/livewire/signal/day.blade.php)" = 0 && test "$(grep -c 'top: 100%; left: 0; width: 100%;' resources/views/livewire/signal/day.blade.php)" = 1 && test "$(grep -c '{!!' resources/views/livewire/signal/day.blade.php)" = 0</automated>
    <automated>grep -qF '.fi-dropdown-panel.fi-width-none' public/css/filament/filament/app.css</automated>
    <human-check>After the merge, run the app in DDEV and open the Signal Today page as Admin. In the add field of a day you can edit, type two or more characters that match an unfinished task with a long title. The suggestion panel must line up with the left and right edges of the text input (not stop at about 14rem), and the title and the `reference · project` line must show visibly more text than before. Check it at a narrow window width too: the panel stays as wide as the field and never wider. Arrow keys, Enter and Escape still work.</human-check>
  </verify>
  <done>The listbox renders with the class `fi-dropdown-panel fi-width-none` and keeps its inline `width: 100%`. In the browser the suggestion panel is exactly as wide as the text input, and suggestion titles and references are readable. No other markup or behavior of the add field changed.</done>
</task>

<task type="auto">
  <name>Task 2: Pin the full-width panel in the existing listbox test</name>
  <files>tests/Feature/Signal/SignalTaskLinkTest.php</files>
  <action>
In `tests/Feature/Signal/SignalTaskLinkTest.php`, extend the existing test `it('renders a listbox with one option per suggestion once two characters match, and nothing for a miss', ...)`. Do not add a new test. In the first `expect($html)` chain, the one that already asserts `role="listbox"` and `id="signal-suggestions-2026-10-12"`, add one more link that asserts the HTML contains the exact class attribute `class="fi-dropdown-panel fi-width-none"`. This pins the regression: dropping the modifier brings the 14rem cap back.

Keep the existing fixtures (`sigLinkProject()`, `sigLinkTask()` with the fictional `Example login ...` titles) and all existing assertions unchanged. Add no new fixture data. If a short comment helps, it says in English that the modifier lifts Filament's 14rem dropdown cap so the panel matches the field width.

This test needs the database. Inside a worktree, run only the cheap checks in the first `<automated>` command (php -l and grep). A worktree has no `vendor/`, so Pint is not run there. Pest and Pint are the second `<automated>` command and run only after the merge into the HEAD branch, in DDEV, as `.claude/CLAUDE.md` requires.
  </action>
  <verify>
    <automated>php -l tests/Feature/Signal/SignalTaskLinkTest.php && test "$(grep -c 'fi-width-none' tests/Feature/Signal/SignalTaskLinkTest.php)" -ge 1</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Signal/SignalTaskLinkTest.php && ddev exec vendor/bin/pint --test tests/Feature/Signal/SignalTaskLinkTest.php</automated>
  </verify>
  <done>The listbox test asserts the full-width class and passes php -l in the worktree. After the merge, the whole `SignalTaskLinkTest.php` file is green in DDEV and Pint reports no style issue on it.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| task data -> Signal add-field view | Admin-entered task titles, references and project names are printed in the suggestion panel of the Admin-only `SignalDay` component |

This change only adds a static CSS class to existing markup. No new input, route, query, authorization path or data reaches the view.

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261030-gcw-01 | Tampering | resources/views/livewire/signal/day.blade.php (suggestion panel) | low | mitigate | Only a static class is added. Every suggestion field stays an escaped double-brace Blade echo, and the Task 1 gate fails if a raw unescaped echo appears in the view |
| T-261030-gcw-02 | Information disclosure | suggestion panel width | low | accept | A wider panel shows more of the same rows that are already sent to the browser. The suggestions still come from `LinkableTasks::search()` behind `RequiresAdmin` (T-17-13), so no new data or audience is exposed |
| T-261030-gcw-SC | Tampering | npm/pip/cargo installs | high | mitigate | No package is installed or updated by this plan, so the package-legitimacy gate has nothing to check |
</threat_model>

<verification>
- In the worktree (cheap, no DB, no `vendor/` or built assets needed): the first `<automated>` command of each task (grep gates on the view; php -l and grep on the test) passes. No migration, no Pest or Pint run and no DB access in the worktree.
- After the merge into the HEAD branch (no migration in this plan): the second `<automated>` command of each task (the compiled theme contains `.fi-dropdown-panel.fi-width-none`; Pest and Pint on the test file in DDEV) passes, then
  `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Signal/SignalTaskLinkTest.php tests/Feature/Signal/SignalScreensTest.php` is green, then the full suite the way CI runs it (without `--parallel`):
  `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency`.
- The Task 1 human check (panel edges line up with the field, readable titles, keyboard still works) is confirmed at the end, per `human_verify_mode: end-of-phase`.
- Before committing: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook hook.
</verification>

<success_criteria>
- The Signal type-ahead suggestion panel is exactly as wide as the text input, not 14rem.
- Task titles and the `reference · project` line in each suggestion are readable without being cut off after a few words.
- The listbox test pins the `fi-width-none` modifier, and `SignalTaskLinkTest.php` is green in DDEV after the merge.
- No change to behavior, keyboard handling, ARIA, stored text or authorization.
</success_criteria>

<output>
Create `.planning/quick/261030-gcw-widen-the-task-hint-in-the-signal-form-t/261030-gcw-SUMMARY.md` when done
</output>
