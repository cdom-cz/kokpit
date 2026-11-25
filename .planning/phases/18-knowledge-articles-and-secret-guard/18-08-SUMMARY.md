---
phase: 18-knowledge-articles-and-secret-guard
plan: 08
subsystem: knowledge
tags: [knowledge, archive, restore, activity-history, filament, partner-audit]
requires:
  - phase: 18-05
    provides: activity labels article_archived and article_restored, SaveArticle as the only writer of version_no and edited_at
  - phase: 18-07
    provides: KnowledgeArticleResource list with the archived switch, ViewKnowledgeArticle, archived route binding
provides:
  - ArchiveArticle and RestoreArticle domain Actions with explicit metadata-only history rows
  - archive and restore screen actions on list rows (icon buttons) and the view header (labelled buttons)
  - KnowledgeArticleHistoryRelationManager (Admin only) on the view screen, declared in PartnerMatrix
affects: [18-09, 18-10]
tech-stack:
  added: []
  patterns:
    - "Resource-level static action factories (archiveAction, restoreAction) shared by the table and the page header; a row adds iconButton()"
key-files:
  created:
    - app/Domain/Knowledge/Actions/ArchiveArticle.php
    - app/Domain/Knowledge/Actions/RestoreArticle.php
    - app/Filament/RelationManagers/KnowledgeArticleHistoryRelationManager.php
    - tests/Feature/Knowledge/ArchiveArticleTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewKnowledgeArticle.php
    - tests/Support/PartnerMatrix.php
    - tests/Isolation/KnowledgeScreensTest.php
key-decisions:
  - "R-18-08-1: both Actions authorise delete/restore, re-read under lockForUpdate (scoped for archive, onlyTrashed for restore), write one explicit event row without properties, never touch version_no or edited_at"
  - "R-18-08-2: archive uses a neutral confirmation (gray, archive-box modal icon, no danger); restore has no modal"
  - "R-18-08-3: history relation manager declared AdminOnly and added to PartnerMatrix; ChannelProbe needed no edit"
requirements-completed: [KB-07]
status: complete
actuals:
  tokens: 21000
  tasks: 3
  commits: 3
plan_head_before: 01dd266148f26e309e702d93cce574145b1be55d
plan_head_after: 54b0a511dedee816fab68b244b7d884e017323d1
commits: 3
---

# Phase 18 Plan 08: Archive and Restore of Knowledge Articles Summary

**Admin archives (confirmed, reversible) and restores knowledge articles from list rows and the view header through two locked domain Actions that write one metadata-only history row each and never touch the version or edit time, plus an Admin-only history relation manager on the view screen.**

## Performance

- **Tasks:** 3 of 3
- **Commits:** 3 (see frontmatter)
- **Verification in the worktree:** `php -l` and `pint --test` on every touched file, grep acceptance gates, `scripts/check-sensitive.sh`. No Pest run happened here (CLAUDE.md: no DB-backed tests in a worktree; the host has no database). The Pest and Audit runs listed in each task's `<verify>` run after the merge, in DDEV.

## Accomplishments

- `ArchiveArticle::handle()` and `RestoreArticle::handle()`: `Gate::forUser($actor)->authorize('delete'|'restore')`, transaction, `lockForUpdate()` re-read (an already archived article is a `ModelNotFoundException` for archive, an active one for restore), soft delete or restore, one `activity()` row (`article_archived` / `article_restored`, Admin as causer, no properties), `refresh()` of the caller's instance. Neither writes `version_no` or `edited_at` (grep gate with comment lines excluded prints 0 for both files).
- `KnowledgeArticleResource::archiveAction()` / `restoreAction()` factories: archive has a confirmation modal ("Archivovat článek?", reversible description, neutral gray confirm, archive-box icon), restore none; visibility by `trashed()`; notifications "Článek byl archivován" / "Článek byl obnoven". Table `recordActions()` holds both as icon buttons with label and tooltip; `ViewKnowledgeArticle::getHeaderActions()` holds both as labelled buttons. No force delete and no bulk action.
- `KnowledgeArticleHistoryRelationManager` (`#[AccessRule(Audience::AdminOnly, ...)]`), listed in `getRelations()` and declared in `PartnerMatrix::panelClasses()`.
- Tests: `tests/Feature/Knowledge/ArchiveArticleTest.php` (archive and restore from header and row, confirmation, notifications, version/edit time/version rows unchanged, list order after restore, one history row each with causer and empty properties, no title or body in any new activity row, already-archived and active guards, history on the view screen with Czech labels); `tests/Isolation/KnowledgeScreensTest.php` (Partner and roleless actor refused with `AuthorizationException`, nothing changes, paired with the Admin succeeding; history manager closed to a Partner, open to the Admin).

## Task Commits

1. **Task 1 (tracer): archive from the view screen** - `9b395c85` (feat)
2. **Task 2: restore and row actions** - `f530136a` (feat)
3. **Task 3: history relation manager and Partner cases** - `54b0a511` (feat)

## Deviations from Plan

None - plan executed as written. `ChannelProbe` discovers history managers by scanning `app/Filament`, and `PanelInventory::canaryRecord()` already knew `KnowledgeArticle`, so neither needed an edit.

Plan-level notes:
- The tracer feedback gate (re-running the Pest `<verify>` end to end) could not run in the worktree for the reason above; it is left to the post-merge gate.
- A2 (does the activity package add its own `deleted` / `restored` row on soft delete and restore) could not be observed without a database. The tests are written so either outcome passes: they require exactly one `article_archived` / `article_restored` row and assert only that any additional new row carries no title or body. The post-merge run will show which; record it there.

## Known Stubs

None.

## Threat Flags

None. The only new surface is the Admin-only relation manager, which is covered by the plan's threat model (T-18-49) and the PartnerMatrix declaration.

## Post-merge checks still to run (DDEV, HEAD branch)

`ddev exec php artisan migrate`, then `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Isolation tests/Arch tests/Feature/Operations`, `ddev exec vendor/bin/pest --testsuite=Audit`, `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. Never `--parallel`.

Points most likely to need a touch-up if the run is red (all Filament-testing API assumptions I could not execute): `assertActionHasLabel` and `isIconButton()`/`getTooltip()` on the table action, `mountTableAction` + `callMountedTableAction` for the confirmed row archive, and `assertSeeLivewire` for the relation manager on the view URL.

## Self-Check: PASSED

- FOUND: app/Domain/Knowledge/Actions/ArchiveArticle.php, RestoreArticle.php, app/Filament/RelationManagers/KnowledgeArticleHistoryRelationManager.php, tests/Feature/Knowledge/ArchiveArticleTest.php
- FOUND commits: 9b395c85, f530136a, 54b0a511 (ancestors of HEAD)
