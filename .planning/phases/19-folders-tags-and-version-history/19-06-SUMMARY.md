---
phase: 19-folders-tags-and-version-history
plan: 06
subsystem: knowledge
tags: [filament, version-history, diff, livewire, sub-navigation, partner-isolation]
requires:
  - phase: 19-01
    provides: restored_from_version_no, KnowledgeArticleVersion::redactions()
  - phase: 19-02
    provides: knowledge.versions.* and knowledge.diff.* copy, KnowledgeFixtures::articleWithVersions()
  - phase: 19-03
    provides: VersionDiff, VersionDiffResult, filament.knowledge.version-diff view
provides:
  - "Resource routes knowledge-articles.versions, .compare, .version and KnowledgeArticleResource::getRecordSubNavigation()"
  - "ListArticleVersions (versions table without body_md), ViewArticleVersion (read-only version), CompareArticleVersions (live two-select diff)"
  - "Partner 403 on the three URLs in KnowledgeScreensTest, route walk filling {version}"
affects: [19-09 redaction actions, 19-10 resource edits, 19-11 restore and redact actions on these pages]
tech-stack:
  added: []
  patterns:
    - "ManageRelatedRecords page with reorder() and a column select that omits the large body column"
    - "Resource page reading a child row by (record, number) with ctype_digit and existence checked on mount and on every Livewire update"
    - "Client-controlled #[Url] properties declared mixed so a forged array answers 404, not a TypeError"
key-files:
  created:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ListArticleVersions.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewArticleVersion.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/CompareArticleVersions.php
    - resources/views/filament/knowledge/version-view.blade.php
    - resources/views/filament/knowledge/version-compare.blade.php
    - tests/Feature/Knowledge/ArticleVersionsPageTest.php
    - tests/Feature/Knowledge/VersionComparePageTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - tests/Isolation/KnowledgeScreensTest.php
    - tests/Isolation/RouteWalkTest.php
key-decisions:
  - "R-19-06-1 to R-19-06-6 kept as written (routes order, sub-navigation, versions table, version view, compare page, isolation)"
  - "Compare number properties are `mixed`, not ?string, so a forged array or number reaches the 404 check instead of a type error"
  - "The number check is repeated on every render (chosen()), so the order of Livewire's query-string hydration and mount() cannot matter"
requirements-completed: [VER-02, VER-04]
duration: 55 min
completed: 2026-11-26
status: complete
commits: 3
plan_head_before: d658fbaf9d0918c2b3ce2f34e82680be0b4b9940
plan_head_after: 1d78b91bc00ef2b8ee1c501d94665069cc7863e0
actuals:
  tokens: 17500
  tasks: 3
  commits: 3
---

# Phase 19 Plan 06: Version history screens Summary

Versions table, read-only version view and live two-select compare page on every article, reachable through a Zobrazit | Upravit | Verze sub-navigation and closed to Partners on every new URL.

## What was built

- **Task 1 (tracer), commit c7f81c2e.** Routes `versions` (`/{record}/versions`) and `version` (`/{record}/versions/{version}`) and `getRecordSubNavigation()` on `KnowledgeArticleResource` (Upravit left out for an archived article). `ListArticleVersions` is a `ManageRelatedRecords` page over `versions`: the 403 guard comes before `parent::mount()`, the query is `reorder()->orderByDesc('version_no')` with an explicit select that omits `body_md`, `with('author:id,name')` and `withExists('redactions')`; 25 rows per page; no bulk, create or search; row link and icon actions with tooltip and an equal `aria-label`; the newest row carries the success badge "Aktuální"; restored-from and redacted gray and warning badges with icons. `ViewArticleVersion` resolves the article (archived opens), rejects any non-digit or over-long number with 404, reads the row by `(article_id, version_no)` and renders title and body through `KnowledgeMarkdown` inside the existing prose view, with the old, redacted and archived callouts.
- **Task 2, commit 4fc41b7b.** Route `compare` registered between `versions` and `version`. `CompareArticleVersions` has `#[Url]` `from` and `to`, defaults (to = current, from = to - 1), normalisation on mount, `updatedFrom()` and `updatedTo()`, a 404 for anything unknown or malformed, and exactly one body query limited to the two numbers. The Blade view has the two native selects with `wire:model.live`, a `wire:loading` indicator targeting `from,to`, the same-version state, and `@include` of the 19-03 diff view. "Porovnat s předchozí" is added to the table rows and the version view header (hidden on version 1).
- **Task 3, commit 1d78b91b.** `KnowledgeScreensTest` URL list covers versions, version 1 and compare of an active and an archived article (so the existing 403, Partner-without-client, guest and Admin-opens cases cover them), plus a canary-leak case and a 403-before-lookup case for a missing article. `RouteWalkTest::walkedUrl()` fills `{version}` with 1 for the knowledge version route only and still throws for any other unknown parameter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Compare properties declared `mixed`, not `?string`**
- **Found during:** Task 2
- **Issue:** R-19-06-5 types `from` and `to` as `?string`; a request such as `?from[]=1` would raise a TypeError (HTTP 500) when Livewire hydrates the property, before any check runs. T-19-21 requires a forged value to answer 404.
- **Fix:** both properties are `public mixed`, and `number()` accepts int or string only (the pattern of `TimeReportPage`, which keeps its URL properties untyped for the same reason). A test requests `?from[]=1` and expects 404.
- **Files modified:** CompareArticleVersions.php
- **Commit:** 4fc41b7b

**2. [Rule 2 - Missing critical functionality] Title column on the versions table**
- **Found during:** Task 1
- **Issue:** R-19-06-3 selects `title` and the plan truth requires "a long title is clipped with its full text in the tooltip", yet the listed columns (Verze, Autor, Vytvořeno, Značky) show no title.
- **Fix:** added a "Název" column (1 line clamp, tooltip with the full text) after the Verze and Aktuální columns. The "Aktuální" badge is its own unlabelled column next to "Verze N", the same pattern the article list uses for its archived badge.
- **Files modified:** ListArticleVersions.php
- **Commit:** c7f81c2e

**3. [Rule 3 - Blocking] Compare route and compare-previous actions moved between commits**
- **Found during:** Task 1
- **Issue:** the Task 1 files cannot reference the compare page before Task 2 creates it (a route to a missing class breaks the resource).
- **Fix:** Task 1 registers only `versions` and `version`; Task 2 adds `compare`, the compare entry of the active-route pattern and the compare-previous actions. The final state is as planned.

**Total deviations:** 3 (2 Rule 2, 1 Rule 3). **Impact:** none on the contract; the end state matches R-19-06-1 to R-19-06-6.

## Hand-over notes

- `tests/Support/RouteRules.php` is unchanged on purpose: it declares only public and deliberately closed routes, and the three new routes are authenticated panel routes. They are covered by `RouteWalkTest`, `KnowledgeScreensTest` and the route audit instead (no Phase 19 route is public). `PanelRegistryTest` needs no row either: the pages are resource pages governed by `KnowledgeArticleResource`.
- 19-09 and 19-11 add redact and restore to `ViewArticleVersion::getHeaderActions()` and the overflow group (`knowledge.versions.actions.more`) of the table row actions in `ListArticleVersions::table()`.
- 19-10 edits `KnowledgeArticleResource.php` again; the sub-navigation method and the `getPages()` entries added here sit at the end of the class.

## Not run in the worktree (no vendor, no database, per project rules)

Only `php -l` on every new or changed PHP file, the plan's grep acceptance checks and `scripts/check-sensitive.sh` ran; the Filament 5 APIs were checked by reading the vendor sources of the main checkout (read only). Blade compilation of the two new views and every Pest test are unverified until the wave merge. Expect possible small fixes at the first DDEV run in these places in particular: Livewire test helpers used in the new tests (`Livewire::withQueryParams`, the 404 assertion on a forged update, `assertTableActionHidden` and `assertTableColumnStateNotSet`), the exact Czech default labels Zobrazit and Upravit asserted for the sub-navigation, and the regexes over the diff HTML.

Post-merge commands (one Pest process at a time, never `--parallel`):

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Isolation/KnowledgeScreensTest.php tests/Isolation/RouteWalkTest.php tests/Arch`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Audit`
3. End-of-phase human check in the browser: changing "Z verze" or "Na verzi" shows the loading indicator and keeps the previous diff until the new one arrives.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-19-20 (Partner access) is mitigated by the 403 guard before any lookup in the mount of every new page; T-19-21 by `ctype_digit`, a length bound and existence under the article id, repeated on Livewire updates; T-19-22 by the single renderer and escaped echo; T-19-23 by the table select without `body_md` and a two-row body query; T-19-24 by reading only the stored row.

## Self-Check: PASSED

- FOUND: app/Filament/Resources/KnowledgeArticleResource/Pages/ListArticleVersions.php, ViewArticleVersion.php, CompareArticleVersions.php, resources/views/filament/knowledge/version-view.blade.php, version-compare.blade.php, tests/Feature/Knowledge/ArticleVersionsPageTest.php, VersionComparePageTest.php
- FOUND commits: c7f81c2e, 4fc41b7b, 1d78b91b
