---
phase: 18-knowledge-articles-and-secret-guard
plan: 07
subsystem: knowledge
tags: [filament, knowledge, navigation, partner-audit, markdown]
requires:
  - phase: 18-01
    provides: KnowledgeArticle model, ArticleKind, soft delete
  - phase: 18-02
    provides: KnowledgeFixtures, canary article fixture
  - phase: 18-03
    provides: KnowledgeMarkdown renderer (singleton)
  - phase: 18-04
    provides: lang/cs/knowledge.php keys
provides:
  - Admin resource KnowledgeArticleResource (list with archived switch, own view screen)
  - Znalosti navigation group pinned before Signal and Správa
  - the one prose view (filament.knowledge.prose) shared by the view screen and the later preview
  - Partner-audit declarations for the resource and its routes
affects: [18-08, 18-09, 18-10]
tech-stack:
  added: []
  patterns:
    - "table-level archived switch (modifyQueryUsing reading the filter state) instead of a filter baseQuery"
key-files:
  created:
    - app/Filament/Concerns/InKnowledgeGroup.php
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewKnowledgeArticle.php
    - resources/views/filament/knowledge/prose.blade.php
    - resources/views/filament/knowledge/article-meta.blade.php
    - tests/Feature/Knowledge/KnowledgeArticleResourceTest.php
    - tests/Isolation/KnowledgeScreensTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/PanelInventory.php
    - tests/Support/ChannelProbe.php
    - tests/Isolation/RouteWalkTest.php
key-decisions:
  - "The archived switch is applied in the table query (modifyQueryUsing reads the show_archived filter state), because a Filament filter callback runs only while the filter is on"
  - "The record URL of the list row is the view page, set explicitly, because the table has no record action that could provide a default URL"
patterns-established:
  - "Knowledge screens refuse a Partner with abort_unless(canAccess(), 403) in every mount()"
duration: 40min
completed: 2026-11-25
status: complete
commits: 3
plan_head_before: 746accd21e37221c5aa5adada48f4305a98f2098
plan_head_after: 3af3cf8e62638f5c2becff0eeb8435a52c21c0eb
actuals:
  tokens: 9700
  tasks: 3
  commits: 3
---

# Phase 18 Plan 07: Knowledge list and view screens Summary

Admin-only "Obecné znalosti" list (active articles by default, "Zobrazit archivované" switch) and a separate view screen that renders the body through KnowledgeMarkdown inside `div.kokpit-prose`, with the Znalosti menu group pinned before Signal and Správa and the Partner audit declarations in place.

## What was built

- **Task 1 (tracer), commit aee9273e:** `InKnowledgeGroup` trait, `KnowledgeArticleResource` (AccessRule AdminOnly, not globally searchable, queries restricted to kind General and the soft-delete scope removed by name only), list page and view page (both refuse a Partner with 403 in `mount()`), the two Blade views (`prose` is the single wrapper of rendered knowledge HTML, `article-meta` the meta line with Citlivý and Archivováno badges), and the PartnerMatrix row. The list selects only `id, kind, title, version_no, edited_at, sensitive, deleted_at`, has no bulk action and no search.
- **Task 2, commit 83fab281:** the `show_archived` toggle, the Archivováno badge column, the muted archived title and the archived empty state.
- **Task 3, commit 3af3cf8e:** `navigationGroups()` in `AdminPanelProvider` (Znalosti, Signal, Správa), the canary lookup in `PanelInventory`, the `knowledge-articles` entry in `RouteWalkTest::walkedResourceMap()` and the isolation test `KnowledgeScreensTest` (Partner without Znalosti group and 403 on list and on view URLs of an active and an archived article, paired with Admin 200; guest redirected to login).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Archived switch cannot live in the filter baseQuery**
- **Found during:** Task 2
- **Issue:** R-18-07-2 asks for `Filter::make('show_archived')->toggle()` whose `baseQuery()` adds `whereNull('deleted_at')` unless the filter is active. In Filament, `applyToBaseQuery()` and `apply()` return early when `isActive` is false, so such a callback would run only when the switch is on, the opposite of the intended behaviour (archived articles would show by default).
- **Fix:** The filter stays a plain toggle; the table `modifyQueryUsing` closure reads `$livewire->getTableFilterState('show_archived')` and adds `whereNull('deleted_at')` unless the switch is on or a record is being resolved (so a row action on an archived row still resolves). The empty-state heading closure reads the same state. The grep acceptance criteria still hold.
- **Files modified:** app/Filament/Resources/KnowledgeArticleResource.php
- **Commit:** 83fab281

**2. [Rule 3 - Blocking] Row URL of the list**
- **Found during:** Task 1
- **Issue:** Filament derives the default record URL from the view/edit record actions, and this plan has no record actions, so rows would not link anywhere.
- **Fix:** `->recordUrl()` returns the view page URL explicitly.
- **Commit:** aee9273e

**3. [Rule 3 - Blocking] ChannelProbe taught the new resource (anticipated by R-18-07-6)**
- **Found during:** Task 3
- **Issue:** `tests/Support/ChannelProbe.php` keeps a copy of the route-walk resource map (`walkResourceMap()`) and would throw its "does not know the resource" LogicException on the new `knowledge-articles` routes.
- **Fix:** added the `knowledge-articles` row (canary article found by title), the same as in `RouteWalkTest`; no route skipped or marked public.
- **Files modified:** tests/Support/ChannelProbe.php
- **Commit:** 3af3cf8e

**Total deviations:** 3 (1 bug, 2 blocking). No impact on the plan scope.

## Verification status

Worktree checks only, as the plan and CLAUDE.md require: `php -l` clean on every touched PHP file, `pint --test` passed, all grep acceptance criteria of the three tasks hold (AccessRule count 1, no global search flag, two `withoutGlobalScopes([SoftDeletingScope::class])`, 403 guard in the view page, no raw Blade echo and `kokpit-prose` present in the prose view, `Filter::make('show_archived')` 1, `whereNull('deleted_at')` 1, no force delete or bulk action, `navigationGroups(` 1, canary lookup 1, `'knowledge-articles' =>` 1), `scripts/check-sensitive.sh` clean on every commit, lefthook (sensitive-content, gitleaks) green.

Not run (needs DDEV after the merge and migrations, owned by the orchestrator): the Pest commands of each task's `<verify>`, `--testsuite=Audit`, PHPStan. PHPStan was also not available in the worktree (no worktree-local vendor). The new tests were written without being executed; API calls most likely to need a touch-up after the first real run are the Filament testing helpers (`assertTableColumnFormattedStateSet`, `filterTable` on a plain toggle filter) and the exact navigation group label comparison.

## Known Stubs

- `emptyActionUrl` and `emptyActionLabel` are `null` in `KnowledgeArticleResource::infolist()`: the empty-body note shows no "Upravit článek" link yet. Intentional (R-18-07-3); plan 18-10 passes the edit link. `getHeaderActions()` and `getRelations()` return empty arrays on purpose: archive and restore and the history relation manager arrive in 18-08, the edit button in 18-10.

## Threat Flags

None. The new surface (list and view routes) is covered by the plan's own threat model (T-18-32 to T-18-36).

## Self-Check: PASSED

- Files: all 13 files of the commits exist in the worktree (checked with git diff --numstat against the base).
- Commits aee9273e, 83fab281, 3af3cf8e are ancestors of HEAD.
