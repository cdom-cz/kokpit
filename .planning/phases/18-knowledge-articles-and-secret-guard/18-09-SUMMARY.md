---
phase: 18-knowledge-articles-and-secret-guard
plan: 09
subsystem: knowledge
tags: [knowledge, filament, create-screen, markdown-editor, preview, secret-guard, livewire]
requires:
  - phase: 18-04
    provides: MarkdownNormaliser, Severity, SecretFinding, SecretFixtures
  - phase: 18-05
    provides: SaveArticle::create(), ArticleInput, SaveResult, SecretGuardRefused (findings and normalisedBodyHash)
  - phase: 18-06
    provides: FilamentAsset::register() array in KnowledgeServiceProvider, knowledge-copy.js style
  - phase: 18-07
    provides: KnowledgeArticleResource, ViewKnowledgeArticle, the prose view
provides:
  - KnowledgeArticleResource::form() shared form (guard callouts, title, Psaní/Náhled tabs, sensitive toggle) and the create route
  - CreateKnowledgeArticle page saving only through SaveArticle::create()
  - GuardsSecrets trait and SecretGuardForm interface, reused by the edit screen of 18-10
  - server-rendered preview tab identical to the view screen, bounded by KnowledgeMarkdown::MAX_INPUT_LENGTH
  - knowledge-editor.js with the task-list toolbar button
affects: [18-10]
tech-stack:
  added: []
  patterns:
    - "Guard feedback = locked Livewire findings plus a refused-body hash; the acknowledgment is a protected per-call flag set only by saveAnyway()"
    - "Preview = Html component over the prose view, input bounded before the normaliser parses"
key-files:
  created:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/GuardsSecrets.php
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/SecretGuardForm.php
    - resources/views/filament/knowledge/guard-findings.blade.php
    - resources/js/knowledge-editor.js
    - tests/Feature/Knowledge/CreateKnowledgeArticleTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php
    - app/Providers/KnowledgeServiceProvider.php
    - tests/Isolation/KnowledgeScreensTest.php
key-decisions:
  - "R-18-09-1..6 implemented as written; deviations below"
  - "Task-list button toggles a task line by removing the whole task prefix (bullet and checkbox), so plain to task to plain round-trips"
requirements-completed: [KB-01, KB-06, SEC-01, SEC-02, SEC-03]
status: complete
actuals:
  tokens: 52000
  tasks: 3
  commits: 3
plan_head_before: e070a4bae1310e82b39793a2390b58f090309594
plan_head_after: 41167f789a000cad84ef5455c9df1cc66608d76f
commits: 3
---

# Phase 18 Plan 09: Create Screen, Preview and Secret-Guard Feedback Summary

**The Admin creates a knowledge article from the list (header button and empty state): title, Markdown editor with a D-07 toolbar and a task-list button, a server-rendered preview identical to the view screen, a page-scoped leave warning, and full secret-guard feedback with a one-save acknowledgment bound to the exact refused body.**

## Performance

- **Tasks:** 3 of 3, one commit each
- **Verification in the worktree:** `php -l`, `pint --test`, `node --check` on the editor script, `scripts/check-sensitive.sh` and the grep acceptance gates of every task. No Pest, no PHPStan and no migrations ran here (CLAUDE.md: no DB-backed tests in a worktree; the worktree has no vendor directory, so PHPStan would analyse the main checkout's classes). The Pest commands of each task's `<verify>` and the plan gate run after the merge in DDEV.
- **Not verified in a browser:** the three human checks of Task 2 (preview formatting and loading indicator, toolbar buttons and task-list toggling, leave warning) are for the end of the phase after `ddev exec php artisan filament:assets`.

## Accomplishments

- **Create flow (Task 1, KB-01):** `CreateKnowledgeArticle` extends `CreateRecord`, 403 for a Partner in `mount()`, `$canCreateAnother = false`, `handleRecordCreation()` calls `SaveArticle::create()` through `withFormErrors()` and never writes the model; the notification is `knowledge.notifications.saved` (version 1), the redirect is the view screen; submit "Vytvořit článek", cancel "Zrušit vytvoření" linking to the list. `ListKnowledgeArticles` gets the header `CreateAction` (label "Nový článek", plus icon); the resource table gets the same action in the empty state, hidden while the archived switch is on.
- **Form (R-18-09-1):** one column; title (required, max 200, own required message, autofocus only on the create page); `Tabs::make()->key('editor')->livewireProperty('activeTab')` with tabs keyed `write` and `preview`; the sensitive toggle with helper text. The editor has exactly the D-07 toolbar groups, no file attachments, `maxLength(MAX_BODY_LENGTH)`, `minHeight('24rem')`, the `data-kokpit-task-list` label attribute and is not live.
- **Preview (Task 2, KB-06, R-18-09-2):** `KnowledgeArticleResource::previewHtml()` bounds the raw text by `KnowledgeMarkdown::MAX_INPUT_LENGTH` first (a longer text goes unnormalised to the renderer's escaped plain branch, so neither the normaliser's nor the renderer's CommonMark parse runs, T-18-52); within the cap it renders `normalise($body)` through `KnowledgeMarkdown` inside the prose view, so a prose hard break and trailing code spaces behave exactly as on the saved article. Empty body shows "Není co zobrazit. Napište text na kartě Psaní."; the preview never scans and never writes.
- **Editor script (R-18-09-6, D-07):** `resources/js/knowledge-editor.js` (asset `knowledge-editor`, flag `window.kokpitKnowledgeEditorBound`) adds a task-list button after the ordered-list button of every marked editor, takes the EasyMDE instance from `_editor` of the Alpine root and edits the selected lines in one `codemirror.operation()`. No markup or evaluation sinks (static test).
- **Leave warning (D-08):** `hasUnsavedDataChangesAlert()` returns true on the create page; the panel-wide switch stays off (`unsavedChangesAlerts(` count 0 in `AdminPanelProvider`).
- **Secret guard on screen (Task 3, SEC-01 to SEC-03, R-18-09-5):** `GuardsSecrets` holds `#[Locked] $guardFindings` (rule, line, severity) and `#[Locked] $refusedBodyHash`; `runGuarded()` clears, runs the save, stores the findings and (only when acknowledgeable) the refused hash on `SecretGuardRefused`, focuses the callout via `$this->js()` and throws `Halt`, so the form is untouched. `saveAnyway()` sets the protected one-call flag only when the raw length is within `MAX_BODY_LENGTH` (checked before the normaliser) and `hash_equals()` matches the refused hash. Two callouts (danger first, `role=alert`, `data-kokpit-guard`) list "<rule label> — řádek N"; the "Uložit článek i přesto" action is visible only for an Ambiguous-only refusal. The body's `afterStateUpdated` hook drops the refusal. The acknowledged save notifies "Článek byl uložen jako verze 1 a označen jako citlivý".
- **Tests:** `tests/Feature/Knowledge/CreateKnowledgeArticleTest.php` (23 cases written, none run here) and the create URL added to `knowledgeScreensUrls()` in `tests/Isolation/KnowledgeScreensTest.php`, so the existing Admin 200, Partner 403, partner-without-client 403 and guest redirect cases cover it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Task-list button icon is a CSS mask, not an inline SVG child**
- **Found during:** Task 2 (reading Filament's `markdown-editor.css`)
- **Issue:** Filament paints every toolbar button's `::before` as a solid grey square unless a per-class `mask-image` rule exists; an SVG child built with `createElementNS` would sit next to that square.
- **Fix:** the script adds one constructed stylesheet (fallback: a style element with `textContent`) with a `mask-image` data URI for the `kokpit-task-list` class, the same technique Filament uses. Still no markup string is parsed or written.
- **Files modified:** `resources/js/knowledge-editor.js`
- **Commit:** 1376f9d9

**2. [Rule 1 - Bug in plan design] Loading indicator sits after the tabs, not inside the preview tab**
- **Found during:** Task 2
- **Issue:** an inactive Filament tab renders nothing, so an indicator inside the preview tab does not exist while the tab-switch request is in flight and would never show.
- **Fix:** an `Html` component with `wire:loading.flex wire:target="activeTab"` (the Filament loading-indicator component) sits between the tabs and the toggle. The form-order test ignores that `Html` component.
- **Files modified:** `app/Filament/Resources/KnowledgeArticleResource.php`
- **Commit:** 1376f9d9

**3. [Rule 1 - Invalid markup] Finding list is the callout footer, not its description**
- **Found during:** Task 3
- **Issue:** the Callout description is rendered inside a `<p>`; a `<ul>` there is invalid HTML and can desynchronise the Livewire morph.
- **Fix:** the description holds the body text (`knowledge.guard.*_body`); the bulleted lines come from `guard-findings.blade.php` rendered as the callout footer (a `View` component). Output stays escaped with `{{ }}`.
- **Files modified:** `app/Filament/Resources/KnowledgeArticleResource.php`, `resources/views/filament/knowledge/guard-findings.blade.php`
- **Commit:** 41167f78

**4. [Process] No separate RED commits**
- Tests and implementation are committed together per task: RED could not be proven here (no database in a worktree), and the plan is `type: execute`, not `type: tdd`. The tests are written from the behavior lists; the post-merge Pest run is the verdict.

### Smaller choices

- `KnowledgeArticleResource::previewHtml()` is a public static helper (the preview closure calls it) so the bound and the normalisation live in one place.
- Read-only guard methods (`hasGuardFindings`, `guardFindingLines`, `canSaveAnyway`, `clearGuardFindings`) carry Filament's `#[Unexposed]`, so the browser cannot call them as Livewire actions; `saveAnyway()` stays callable by design and re-checks everything itself.
- The task-list button skips blank lines inside a multi-line selection and inserts the marker after a line's indentation.
- The A5 fallback (`->live(onBlur: true)`) was not needed in the Livewire tests; the browser behaviour of the deferred editor state (preview shows unsaved text) is covered by the human check.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-18-37 to T-18-42, T-18-52 are implemented as mitigations: locked properties, hash_equals binding, escaped partial, bounded preview and save-anyway, Partner 403, markup-free script).

## Self-Check: PASSED

- Files created and present: CreateKnowledgeArticle.php, GuardsSecrets.php, SecretGuardForm.php, guard-findings.blade.php, knowledge-editor.js, CreateKnowledgeArticleTest.php.
- Commits present in history: 96da6f74 (Task 1), 1376f9d9 (Task 2), 41167f78 (Task 3).
