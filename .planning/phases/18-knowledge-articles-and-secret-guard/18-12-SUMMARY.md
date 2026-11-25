---
phase: 18-knowledge-articles-and-secret-guard
plan: 12
subsystem: knowledge
tags: [knowledge, filament, edit-screen, create-screen, write-failure, concurrent-archive, nul-byte, gap-closure]
requires:
  - phase: 18-05
    provides: SaveArticle, ArticleWriteFailed (SQLSTATE-only message)
  - phase: 18-09
    provides: CreateKnowledgeArticle, GuardsSecrets, RethrowsDomainValidation
  - phase: 18-10
    provides: EditKnowledgeArticle, the stale-save notice
provides:
  - RefusesFailedSaves trait with refuseWriteFailure(ArticleWriteFailed): never
  - persistent danger notice for a refused database write on the edit and the create screen
  - persistent warning notice with a link for a save of an article archived in another tab
  - NUL refusal in ArticleInput::from() for title and body
  - five Czech strings (write_failed_*, archived_meanwhile_*, errors.nul_character)
affects: [18-13]
tech-stack:
  added: []
  patterns:
    - "A shared page trait reports the content-free exception, sends a fixed-text notice and throws Halt, so the form state survives"
    - "A concurrent archive is caught only where it is raised (handleRecordUpdate), pinned by a hydration test instead of an override"
key-files:
  created:
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
    - app/Domain/Knowledge/Actions/ArticleInput.php
    - lang/cs/knowledge.php
    - tests/Feature/Knowledge/EditKnowledgeArticleTest.php
    - tests/Feature/Knowledge/CreateKnowledgeArticleTest.php
    - tests/Feature/Knowledge/KnowledgeLangTest.php
key-decisions:
  - "R-18-12-1 to R-18-12-6 implemented as written"
  - "The NUL check on the title runs on the raw value, before trim(), because trim() strips NUL at either end and would hide it instead of refusing it"
requirements-completed: [KB-01, KB-08]
status: complete
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: 37303a461892c707f20b2bc6f4bc23f460c3759c
plan_head_after: c4708dcbd97eee9ab66d03c3c46802937f408940
commits: 3
---

# Phase 18 Plan 12: Failed-save refusals Summary

A database refusal (`ArticleWriteFailed`) on the edit and create screens, an article archived in another tab on the edit screen, and a NUL byte in a title or body now each end in a clear notice or field error that keeps the typed text, instead of Livewire's generic error overlay or a silently truncated article (review finding IN-04).

## What was built

- **Task 1 (tracer), commit f2ee0f8f:** `RefusesFailedSaves` trait (protected `refuseWriteFailure()`: `report()` of the SQLSTATE-only exception, persistent danger notification with fixed Czech title and body, `Halt`). `EditKnowledgeArticle::handleRecordUpdate()` catches `ArticleWriteFailed` next to `StaleArticle`. The five Czech strings were added to `lang/cs/knowledge.php`. New edit-screen test (conflicting version row at number 2 forces SQLSTATE 23505) and `KnowledgeLangTest` case.
- **Task 2, commit 2794e184:** `handleRecordUpdate()` catches `ModelNotFoundException` as "archived in another tab": persistent warning with a `load_current` action that opens the view URL in a new tab, then `Halt`. The catch stays in `handleRecordUpdate()` (R-18-12-6); no hydration override was added. Two new tests: the archived-meanwhile test (status 200 on both presses, typed text kept, nothing written) and the hydration pin (route-binding query resolves the trashed record, `canEdit()` true, two roundtrips with status 200).
- **Task 3, commit c4708dcb:** `CreateKnowledgeArticle::handleRecordCreation()` catches `ArticleWriteFailed` and calls `refuseWriteFailure()`. `ArticleInput::from()` refuses a title or body containing NUL with a field error keyed `title` or `body`. Two new create-screen tests (SaveArticle bound to a throwing resolver; NUL on screen for body and title plus a direct `ArticleInput::from()` check).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] NUL check on the title runs before trim()**
- **Found during:** Task 3
- **Issue:** R-18-12-4 places the NUL check after the existing trim. PHP's `trim()` strips `"\0"` at either end by default, so a title with a leading or trailing NUL would be silently changed instead of refused, which contradicts the plan's own rule "refuse, never strip".
- **Fix:** The title is checked for NUL on the raw string value before `trim()`. The body is never trimmed, so its check stays after the type check. Behaviour for every other input is unchanged.
- **Files modified:** `app/Domain/Knowledge/Actions/ArticleInput.php`
- **Commit:** c4708dcb

## Verification status

- Worktree checks done: `php -l` clean on every touched PHP file; `scripts/check-sensitive.sh` clean on every touched file; lefthook (sensitive-content, gitleaks) passed on all three commits; plan greps pass (`assertStatus(200)` count 4, no `hydrate` / `resolveRecord` / `resolveScopedModelProperties` override on the edit page).
- **Not run here (per CLAUDE.md, worktree):** every Pest command, Pint and PHPStan. They need the DDEV database and run post-merge on the HEAD branch: `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Feature/Localisation tests/Isolation/KnowledgeScreensTest.php tests/Isolation/KnowledgeVisibilityTest.php`, then `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. No vendor directory exists in this worktree, so the R-18-12-6 vendor sources were not re-read here; the hydration pin test is the proof, and if it fails post-merge the failing step of R-18-12-6 is a blocker (do not move the catch, add an override or weaken a test).
- Points to watch in the first post-merge run: the `Exceptions::assertReported()` closures (typed closure parameter `ArticleWriteFailed`), the `auth()->user()` argument of `ArchiveArticle::handle()` in the edit tests, and the NUL value passing through Livewire's JSON snapshot in the create-screen NUL test.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; `refuseWriteFailure()` is protected inside a trait (T-18-62), notices carry fixed strings only (T-18-59).

## Self-Check: PASSED

- FOUND: app/Filament/Resources/KnowledgeArticleResource/Concerns/RefusesFailedSaves.php
- FOUND commits f2ee0f8f, 2794e184, c4708dcb (ancestors of HEAD)
