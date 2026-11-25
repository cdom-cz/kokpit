---
phase: 18-knowledge-articles-and-secret-guard
plan: 10
subsystem: knowledge
tags: [knowledge, filament, edit-screen, stale-save, secret-guard, arch-test, contributing]
requires:
  - phase: 18-05
    provides: SaveArticle::update(), SaveResult, SaveOutcome, StaleArticle
  - phase: 18-07
    provides: KnowledgeArticleResource, ViewKnowledgeArticle, the prose view
  - phase: 18-08
    provides: archiveAction(), restoreAction(), the record and header actions
  - phase: 18-09
    provides: the shared form, GuardsSecrets, SecretGuardForm, CreateKnowledgeArticle
provides:
  - EditKnowledgeArticle with locked loadedVersion and loadedSensitive, stale notice, outcome notifications, guard feedback and leave warning
  - edit entry points on the view header, the list row and the empty-body text of the view screen
  - KnowledgeSeamTest, the Arch test of the one renderer, the one writer and the other knowledge seams
  - the Phase 18 hand-over bullet of CONTRIBUTING.md and its repository test
affects: [phase-19, phase-21, phase-23]
tech-stack:
  added: []
  patterns:
    - "Starting version and starting sensitive flag are locked Livewire properties written only in mount(); the manual clear of the flag is derived from the locked opening value"
    - "Arch seam scans work on PHP tokens and Blade source with an inline self-check fixture per rule"
key-files:
  created:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
    - tests/Feature/Knowledge/EditKnowledgeArticleTest.php
    - tests/Arch/KnowledgeSeamTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewKnowledgeArticle.php
    - tests/Isolation/KnowledgeScreensTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
key-decisions:
  - "R-18-10-1..4 implemented as written; deviations below"
  - "The edit action is a URL action built by KnowledgeArticleResource::editAction(EditAction), never the modal that would call Model::update(), so SaveArticle stays the only write path"
requirements-completed: [KB-02, KB-06, KB-08, VER-01, SEC-01, SEC-02, SEC-03]
status: complete
actuals:
  tokens: 15000
  tasks: 3
  commits: 3
plan_head_before: f34b2aa04c195e37665478f655ed0f25af1f8c02
plan_head_after: a25d7881ba3279783de4bc65d4718915ee3bb9d1
commits: 3
---

# Phase 18 Plan 10: Edit Screen, Stale Save and Knowledge Seams Summary

**Every general article has its own edit screen on the shared form: it saves only through `SaveArticle::update()` with the version it was opened with, rejects the second of two concurrent saves with a persistent notice that keeps the typed text and opens the current article in a new tab, and the seams of the knowledge center (one renderer, one writer) are pinned by an Arch test and handed over in CONTRIBUTING.md.**

## Performance

- **Tasks:** 3 of 3, one commit each (tracer, tests, seams)
- **Verification in the worktree:** `php -l`, `pint --test`, `scripts/check-sensitive.sh` and the grep acceptance gates of every task. No Pest run of a DB-backed suite and no migration ran here (CLAUDE.md). The two DB-free parts were checked by scratch scripts against the worktree tree, outside the repository: the helpers of `KnowledgeSeamTest` (the real-tree scans of rules a to g found what they guard where it lives and nothing else; the eight self-check and non-tree cases of the Arch file also passed under Pest with a temporary vendor link, since removed) and the CamelCase and method resolution of the new CONTRIBUTING bullet (45 backticked names, none unresolved).
- **Tracer gate (Task 1):** the tracer `<verify>` is the DDEV Pest run, which cannot run in a worktree; the `php -l` and grep part passed. The orchestrator's post-merge run of `EditKnowledgeArticleTest` and `KnowledgeArticleResourceTest` is the end-to-end proof before this plan counts as green.
- **Not verified in a browser:** the four human checks of Task 2 (two tabs, leave warning, scroll and focus to the callout, 419 after a long session) are for the end of the phase.

## Accomplishments

- **Edit screen (Task 1, KB-02, R-18-10-1):** `EditKnowledgeArticle extends EditRecord implements SecretGuardForm`, 403 for a Partner before the record is looked up, 404 for an archived article, the form filled with title, body (from `body_md`) and toggle, "Verze N" as subheading, no relation manager, save "Uložit změny", cancel "Zrušit úpravy" back to the view screen, redirect to the view screen after every outcome. `#[Locked] $loadedVersion` and `#[Locked] $loadedSensitive` are set only in `mount()`.
- **Entry points (R-18-10-2):** route `edit`, an icon button with label and tooltip "Upravit článek" before archive and restore on every active row, the primary first action of the view header, and the link in the empty-body text; all hidden or absent for an archived article.
- **Stale save (Task 2, KB-08, D-09):** `StaleArticle` is caught after the guard wrapper; a persistent warning with the fixed title and body and the action "Načíst aktuální verzi" (`shouldOpenInNewTab: true`), then `Halt`: nothing written, the form untouched, the starting version never refreshed, so the same save is refused again. No overwrite and no merge control exists (the form actions are exactly save, save-anyway, cancel).
- **Outcomes (VER-01, D-10, D-04):** `Updated` notifies the new version (with "a označen jako citlivý" for an acknowledged save), `Unchanged` and `SensitivityChanged` notify with an info notification and write no version. The manual clear is sent only when the toggle showed the flag on at opening and was turned off; a flag set in the meantime survives a save made with a toggle that was off at opening.
- **Guard on edit (SEC-01 to SEC-03):** the create screen's `GuardsSecrets`, callouts and "Uložit článek i přesto" are reused; `submitGuardedForm()` calls `save()`. The preview tab shares the create screen's server rendering.
- **Seam test (Task 3, R-18-10-3):** `KnowledgeSeamTest` checks rules (a) to (g) on the real tree, each with an inline fixture that must be reported or ignored.
- **Hand-over (R-18-10-4):** the "Phase 18 (knowledge articles)." bullet of CONTRIBUTING.md and the case "hands Phase 18 over: the knowledge bullet names only what exists", which also pins "no external error reporter" to a `composer.json` free of the four reporter packages.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug in plan design] Rule (b) allows one pre-existing sanitiser file, via a reviewed allowlist**
- **Found during:** Task 3
- **Issue:** the plan says `HtmlSanitizerConfig` occurs under `app/` only in `KnowledgeMarkdown.php`, but `app/Domain/Shared/Text/RichText.php` (older, unrelated to knowledge) builds one too, so the rule would fail on the real tree.
- **Fix:** `KNOWLEDGE_SEAM_SANITISER_ALLOWLIST` lists that file with its reason; the test also fails when an allowlisted file no longer names the config, so the list cannot go stale.
- **Files modified:** `tests/Arch/KnowledgeSeamTest.php`
- **Commit:** a25d7881

**2. [Rule 1 - Bug in plan design] Rule (d) ignores the cast declaration `'version_no' => 'integer'`**
- **Found during:** Task 3
- **Issue:** the two models declare `'version_no' => 'integer'` in their casts, which the plan's "array keys occur only in SaveArticle" would flag, although a cast writes nothing.
- **Fix:** the scan skips a key whose value is the string `'integer'`. It also flags `->body_md =`, `->version_no =`, `+=`, `-=`, `.=`, `++` and `--` on those two properties, so a write through the property is as visible as one through `forceFill()`.
- **Files modified:** `tests/Arch/KnowledgeSeamTest.php`
- **Commit:** a25d7881

**3. [Rule 2 - Missing critical behavior] The edit screen's archive action returns to the view screen**
- **Found during:** Task 1
- **Issue:** archiving from the header of the edit screen would leave an open form for an archived article, whose next save ends in a not-found error.
- **Fix:** `archiveAction()->successRedirectUrl()` points the edit header to the view screen.
- **Files modified:** `app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php`
- **Commit:** c3463017

**4. [Rule 2 - Missing critical behavior] The edit action is an explicit URL action**
- **Found during:** Task 1
- **Issue:** a bare `EditAction::make()` carries a default modal action that ends in `Model::update()`, a second write path next to `SaveArticle`.
- **Fix:** `KnowledgeArticleResource::editAction(EditAction)` (the repo's pattern of passing the action in) sets the label, tooltip, the edit URL and the hidden-for-archived rule; the pages call it with `EditAction::make()`.
- **Files modified:** `app/Filament/Resources/KnowledgeArticleResource.php`, `ViewKnowledgeArticle.php`
- **Commit:** c3463017

**5. [Process] No separate RED commits; the page was written whole in Task 1**
- The tracer commit carries the whole page, including the stale catch, the outcome notifications and the guard wiring that the plan assigns to Task 2, because they are one method. The Task 2 commit therefore holds only its tests. A RED run is not possible without a database in the worktree; the post-merge Pest run is the verdict.

### Smaller choices

- The stale test reads the pulled notification objects (title, body, status, duration, action name, label, URL, new-tab flag) instead of comparing whole arrays, so it does not depend on the array round trip of the notification mailbox.
- `getSubheading()` and `getRelationManagers()` carry `#[Unexposed]` and a narrowed return type (`string`, empty array).
- The Admin's 404 on the archived edit URL is its own case in `KnowledgeScreensTest`, because the shared URL map asserts 200 for the Admin on every entry.
- Rule (e) asserts the source declares no `$isGloballySearchable`, that the property's declaring class is Filament's `Resource`, and that `canGloballySearch()` is false.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model: T-18-43 (locked starting version and flag, tests for both locks and the surviving flag), T-18-44 (403 before `parent::mount()`, Partner cases for active and archived edit URLs), T-18-45 (callouts show rule and line only, the stale notice holds fixed text), T-18-46 (the seam test and the CONTRIBUTING rules). T-18-SC: this plan installs nothing.

## Self-Check: PASSED

- Files created and present: EditKnowledgeArticle.php, EditKnowledgeArticleTest.php, KnowledgeSeamTest.php; CONTRIBUTING.md carries the bullet.
- Commits present in history: c3463017 (Task 1), 21cc7620 (Task 2), a25d7881 (Task 3).
- STATE.md and ROADMAP.md untouched, as the orchestrator owns them.
