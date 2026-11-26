---
phase: 19-folders-tags-and-version-history
verified: 2026-11-26T12:00:00Z
status: gaps_found
score: 5/5 must-haves verified
covered_files:
  - .planning/phases/19-folders-tags-and-version-history/19-01-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-01-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-02-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-02-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-03-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-03-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-04-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-04-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-05-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-05-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-06-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-06-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-07-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-07-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-08-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-08-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-09-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-09-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-10-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-10-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-11-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-11-SUMMARY.md
  - .planning/phases/19-folders-tags-and-version-history/19-12-PLAN.md
  - .planning/phases/19-folders-tags-and-version-history/19-12-SUMMARY.md
  - app/Domain/Knowledge/Actions/DeleteFolder.php
  - app/Domain/Knowledge/Actions/MoveFolder.php
  - app/Domain/Knowledge/Actions/RedactVersion.php
  - app/Domain/Knowledge/Actions/RestoreVersion.php
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Support/VersionDiff.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php
  - database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php
covered_digest: "v4:sha256:81a249d7936ccb95b49e62471a09e1b5c51682d5d03f3b6934e099ed3a28848a"
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "Static analysis and formatting gates (project final-verdict rule: full suite AND static analysis after the merge; CI job hygiene.yml runs `phpstan analyse`)"
    status: failed
    reason: "PHPStan level 8 reports 17 errors, all in Phase 19 code, and Pint --test reports 5 files with style violations. Goal behaviour is not affected, but CI would fail."
    artifacts:
      - path: "app/Domain/Knowledge/Actions/RedactVersion.php"
        issue: "line 114: selectLines() expects array<int,string>, preg_split result is list<string|false>"
      - path: "app/Domain/Knowledge/Actions/SaveArticle.php"
        issue: "lines 271 and 300: Tag closure typed against Model, undefined property Model::$name (twice)"
      - path: "app/Domain/Knowledge/Queries/FolderTree.php"
        issue: "line 243: $nodes property type does not accept the assigned list shape; Pint also flags the file"
      - path: "app/Filament/Resources/KnowledgeArticleResource.php"
        issue: "lines 522, 530, 531: classificationMeta() return shape and Tag closures"
      - path: "app/Filament/Resources/KnowledgeArticleResource/Concerns/ManagesVersionActions.php"
        issue: "line 425: versionActionArticleCache may receive a Collection (reported in context of ListArticleVersions and ViewArticleVersion)"
      - path: "app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php"
        issue: "line 102: array unpacking of array|null"
      - path: "app/Filament/Resources/KnowledgeArticleResource/Pages/ListArticleVersions.php"
        issue: "line 78 unused Htmlable return type; lines 181-182 restoreVersionAction()/redactVersionAction() expect Closure(): int but get Closure(KnowledgeArticleVersion): int; Pint self_static_accessor"
      - path: "app/Filament/Resources/KnowledgeArticleResource/Pages/ListKnowledgeArticles.php"
        issue: "line 463 undefined Model::$name; line 543 folderMissing() should be typed never or really throw"
      - path: "app/Filament/Resources/KnowledgeArticleResource/Pages/ViewArticleVersion.php"
        issue: "line 111: unnecessary nullsafe"
      - path: "app/Domain/Knowledge/Models/KnowledgeArticle.php"
        issue: "Pint only: class_attributes_separation, unary_operator_spaces, not_operator_with_successor_space"
      - path: "tests/Feature/Knowledge/VersionRestoreModalTest.php and VersionRedactModalTest.php"
        issue: "Pint only: fully_qualified_strict_types, ordered_imports"
    missing:
      - "Fix the 17 PHPStan level 8 errors without ignores or baseline entries (typed Tag collections, array shapes, never return, closure signatures)"
      - "Run Pint on the 5 flagged files"
      - "Re-run `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `ddev exec vendor/bin/pint --test` after the fix"
human_verification:
  - test: "Diff performance at the work budget (plan 19-03, OD-2)"
    expected: "5,000 completely different lines against 5,000 others open the compare page in an acceptable time. The verifier measured the library call alone in DDEV CLI: 1.57 s, 10 MB peak, tooLarge=false, truncated=true, 5000 added and 5000 removed. The full page request in the browser is still not measured."
    why_human: "The 1.57 s figure is the CLI engine call only, not the rendered page; whether it is acceptable is an owner decision."
  - test: "Light and dark mode of the folder tree, version table, version view, diff and modals"
    expected: "Both colour modes are readable, the diff add/remove colours keep contrast, the redaction callout is legible."
    why_human: "Visual appearance."
  - test: "Long-line wrapping in the diff and the version view"
    expected: "A very long line wraps or scrolls inside the table without breaking the page width."
    why_human: "Visual layout."
  - test: "Sticky folder panel at 1280 px and the disclosure form at 800 px"
    expected: "The tree panel stays visible beside the list at 1280 px; at 800 px the tree becomes the collapsible chip/disclosure."
    why_human: "Responsive layout behaviour."
  - test: "Row menus by keyboard (folder row menu, Další akce group on the versions table)"
    expected: "Menus open, move and close by keyboard, focus returns to the trigger."
    why_human: "Keyboard accessibility."
  - test: "Loading indicator on the compare selects"
    expected: "Changing the from or to select shows a loading state until the diff is rendered."
    why_human: "Live Livewire timing."
  - test: "redactInstead link in the restore modal callout"
    expected: "When the restore modal warns about a secret, the link switches to the redaction modal of the same version."
    why_human: "Cross-modal interaction in a real browser."
  - test: "Sentry and SPA long-edit hand-over (OD-4)"
    expected: "A long edit session in SPA mode keeps the leave warning, the stale token and error reporting working after a navigation."
    why_human: "Needs a real SPA session and the error reporting service."
---

# Phase 19: Folders, Tags and Version History Verification Report

**Phase Goal:** Admin can organise articles in a folder tree with tags and client or project links, and can inspect, compare, restore and redact earlier versions of any article
**Verified:** 2026-11-26
**Status:** gaps_found (goal behaviour verified; the only gap is the static analysis and formatting gate, see Gaps Summary)
**Re-verification:** No, initial verification

## Goal Achievement

### Observable Truths

| # | Truth (ROADMAP success criterion) | Status | Evidence |
|---|---|---|---|
| 1 | Admin creates, renames and moves folders in a tree; a move into the own subtree or beyond the depth cap is refused with an explanation; deleting a non-empty folder is refused | VERIFIED | `CreateFolder`, `RenameFolder`, `MoveFolder`, `DeleteFolder` exist and are substantive (advisory lock `knowledge_folders_tree`, `FolderTree::moveRefusal` for cycle and depth, `KnowledgeFolder::MAX_DEPTH`, name uniqueness ignoring case, DB trigger KP003 and unique index as backstop). Each is called from `ListKnowledgeArticles` actions (lines 204, 233, 265, 311, 347). `DeleteFolder` counts articles with `withTrashed()` and subfolders and throws `FolderNotEmpty`, with the FK RESTRICT (23001/23503) mapped as backstop. Tests: `FolderActionsTest`, `FolderTreeActionsTest`, `FolderTreeTest`, `KnowledgeTablesTest` (folders block, six levels allowed, seventh refused). |
| 2 | Admin files an article in a folder, gives it knowledge tags that never mix with client tags, browses the list by folder and filters by tag | VERIFIED | `ArticleClassification` + `SaveArticle::syncKnowledgeTags()` write only `TagType::Knowledge` tags in the save transaction. Client forms stay on `TagType::Client`; `KnowledgeTagsTest` proves knowledge tags are never suggested on client, project or task forms and client suggestions never contain knowledge tags. The list has the folder tree panel with subtree filter (`subtreeIds`, ids from the tree, never from the URL), unfiled selection, folder and tag columns, and an AND tag filter restricted to `tags.type = knowledge` (a forged client-tag id matches nothing). Tests: `KnowledgeListFiltersTest`, `FolderTreeListTest`, `ArticleClassificationFormTest`, `SaveArticleClassificationTest`, `KnowledgeArticleTagsTest`. |
| 3 | Admin links an article to a client, a project or both; client and project pages show a Knowledge tab | VERIFIED | `classificationColumns()` and `assertClassificationTargets()` in `SaveArticle` (project needs client, project must belong to the client; DB CHECK as backstop). `ClientKnowledgeRelationManager` and `ProjectKnowledgeRelationManager` are registered in `ClientResource` (line 506) and `ProjectResource` (line 396), use `Client::knowledgeArticles()` and `Project::knowledgeArticles()`, are `AccessRule(Audience::AdminOnly)` and declared in `PartnerMatrix` (admin true, partner false). Tests: `KnowledgeTabsTest` (Feature and Isolation), `ArticleLinksTest`. |
| 4 | Admin opens the version history, views any earlier version read-only and sees the difference between two chosen versions | VERIFIED | `ListArticleVersions`, `ViewArticleVersion`, `CompareArticleVersions` pages with routes; the compare page validates both numbers (digits only, existing version, else 404) and reads only the two chosen bodies. `VersionDiff` over `sebastian/diff ^9.0.1` (promoted to `require` in composer.json) with line numbers, 3-line context collapse, 1,500-row cap, 10,000 compared-line budget and `tooLarge` state. The Blade table escapes all text; `kokpit-diff` stylesheet registered in `KnowledgeServiceProvider` and present in `public/css/app/`. Tests: `ArticleVersionsPageTest`, `VersionComparePageTest`, `VersionDiffViewTest`, `VersionDiffTest`, `KnowledgeScreensTest` (Partner 403 and route walk). |
| 5 | Restoring adds a new version on top and passes the secret guard like a save; Admin can redact a secret from a stored version so it no longer appears in view, diff or restore, and the redaction is logged without the value | VERIFIED | `RestoreVersion` goes through `SaveArticle::update()` (stale version and redaction token, normaliser, no-op check, secret scan, acknowledge path, `restored_from_version_no` marker, `version_restored` activity row with numbers only); versions 1..n untouched. `RedactVersion` rewrites `body_md` only inside a transaction that sets `kokpit.redacting_knowledge_version`, via query builder; the migration trigger admits only a body-only change under that setting and still refuses every other update, DELETE and TRUNCATE (KP001). One append-only `knowledge_version_redactions` row stores actor, scope and reason LENGTH only (no column for text, validated then discarded, OD-1). Current-version redaction rewrites the article too and bumps `redaction_no`, so an open editor or restore modal is stale. Activity row `version_redacted` carries version number, scope, flags, no content. Lock order article-then-version is shared with `SaveArticle` and `RestoreVersion`. Tests: `RestoreVersionTest`, `RedactVersionTest`, `VersionRestoreModalTest`, `VersionRedactModalTest` (marker, never the token, in view, compare and restore; reason never reaches notification, page, row or log), `KnowledgeRestoreRedactConcurrencyTest`, `KnowledgeTablesTest` (redaction and restore marker block), `KnowledgeSeamTest` (Arch writer map). |

**Score:** 5/5 truths verified (0 present but behavior-unverified; behavior-dependent truths 1 and 5 are covered by passing Feature, Arch and Concurrency tests that exercise the refusal and race paths)

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php` | restore marker, `redaction_no`, setting-gated immutability, redactions table, safe `down()` | VERIFIED | Read in full; constraints, triggers and truncate guard present; `down()` refuses on non-empty history. |
| `app/Domain/Knowledge/Actions/{Create,Rename,Move,Delete}Folder.php`, `FolderName.php`, `Queries/FolderTree.php` | folder write path and tree query | VERIFIED | Substantive, wired from the list page. |
| `app/Domain/Knowledge/Actions/{SaveArticle,ArticleClassification,RestoreVersion,RedactVersion,RedactionLines}.php` | save seam, restore, redact | VERIFIED | Wired from `ManagesVersionActions` (lines 286, 366) and the edit and create screens. |
| `app/Domain/Knowledge/Support/VersionDiff.php`, `VersionDiffResult.php`, `DiffRow.php` | diff engine | VERIFIED | Wired from `CompareArticleVersions` and `ManagesVersionActions`. |
| `app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php`, `Enums/RedactionScope.php` | redaction model | VERIFIED | Declared in `PartnerMatrix` (line 522) and `CanaryRegistry`. |
| `ListKnowledgeArticles`, `ListArticleVersions`, `ViewArticleVersion`, `CompareArticleVersions` pages, `ManagesVersionActions` concern | screens | VERIFIED | Substantive, no TODO/FIXME/TBD markers in Phase 19 code. |
| `resources/views/filament/knowledge/{folder-tree,version-diff,version-compare,version-view,redaction-preview,article-meta}.blade.php`, `resources/css/kokpit-diff.css` | views and styles | VERIFIED | Present; stylesheet registered and published. |
| Client and Project Knowledge relation managers | Knowledge tabs | VERIFIED | Registered, Admin only. |

### Key Link Verification

| From | To | Via | Status |
|---|---|---|---|
| `ListKnowledgeArticles` folder actions | Create/Rename/Move/DeleteFolder | `app(...)->handle(...)` | WIRED |
| Versions and version screens | `RestoreVersion` / `RedactVersion` | `ManagesVersionActions` lines 286 and 366 | WIRED |
| `RestoreVersion` | `SaveArticle::update()` | injected `$save`, `restoredFromVersionNo` | WIRED |
| `RedactVersion` | DB immutability trigger | `set_config('kokpit.redacting_knowledge_version', 'on', true)` then off | WIRED |
| Compare page | `VersionDiff::compare` | `app(VersionDiff::class)` | WIRED |
| Edit and create screens | `SaveArticle` with classification and redaction token | `ArticleClassification`, `GuardsSecrets`, `RefusesFailedSaves` | WIRED |
| Client and project pages | Knowledge tab | `getRelations()` in both resources | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|---|---|---|---|---|
| Folder panel | `FolderTree::load()` | `knowledge_folders` plus article counts | Yes | FLOWING |
| Article list | folder path, tags | `FolderTree::pathLabel`, eager `tags` | Yes | FLOWING |
| Versions table | version rows | `knowledge_article_versions` without bodies | Yes | FLOWING |
| Diff table | two chosen bodies | DB read of two rows, `VersionDiff` | Yes | FLOWING |
| Knowledge tabs | `knowledgeArticles` relation | `knowledge_articles.client_id/project_id` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Knowledge unit, Arch, Feature and Schema tests | `pest tests/Unit/Knowledge tests/Arch/KnowledgeSeamTest.php tests/Feature/Knowledge tests/Feature/Schema/KnowledgeTablesTest.php` | 821 passed, 8,553 assertions | PASS |
| Isolation and Concurrency tests | `pest tests/Isolation/Knowledge*.php tests/Concurrency/Knowledge*ConcurrencyTest.php` | 25 passed, 280 assertions | PASS |
| Worst-case diff at the budget (5,000 vs 5,000 different lines) | `VersionDiff::compare` in DDEV CLI | 1.57 s, 10 MB, rows truncated, 5000 added and 5000 removed | PASS (engine only; page timing is a human item) |
| PHPStan level 8 | `phpstan analyse` | 17 errors in Phase 19 files | FAIL |
| Pint | `pint --test` on Knowledge code and tests | 5 files fail | FAIL |
| Sensitive-data check | `scripts/check-sensitive.sh` | clean (staged) | PASS |

The orchestrator's full-suite result (8146 passed, 3 skipped, 11 failed, all 11 in S3 tests unrelated to this phase) is taken as given and was not re-run.

### Probe Execution

SKIPPED: the phase declares no probe scripts.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| ORG-01 | 19-05, 19-08 | Folder tree: create, rename, move; depth capped, cycles impossible | SATISFIED | Folder Actions, tree trigger KP003, FolderTree move rule; tests above |
| ORG-02 | 19-05, 19-08 | A non-empty folder cannot be deleted | SATISFIED | `DeleteFolder` (archived articles counted), RESTRICT FKs |
| ORG-03 | 19-01, 19-04, 19-08, 19-10 | Articles filed in folders and tagged with a dedicated tag type | SATISFIED | `syncKnowledgeTags`, list filters, Zařazení editor section |
| ORG-04 | 19-04, 19-07, 19-10 | Link to client and/or project; Knowledge tab on both pages | SATISFIED | classification columns, two relation managers, Partner matrix |
| VER-02 | 19-06 | Version history and view of an earlier version | SATISFIED | versions list and version view pages |
| VER-03 | 19-04, 19-09, 19-11 | Restore creates a new version and passes the guard again | SATISFIED | `RestoreVersion` through `SaveArticle::update()` |
| VER-04 | 19-03, 19-06 | Difference between two versions | SATISFIED | `VersionDiff` and compare page |
| VER-05 | 19-01, 19-09, 19-11 | Redact a secret that reached a stored version | SATISFIED | `RedactVersion`, trigger, redaction record, e2e test |

No orphaned requirements: REQUIREMENTS.md maps exactly these eight IDs to Phase 19 and all are claimed by plans.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| Phase 19 files listed under `gaps` | various | PHPStan level 8 errors (17) and Pint violations (5 files) | Blocker for CI (not for goal behaviour) | `hygiene.yml` runs `phpstan analyse`; the pipeline would fail |
| Phase 19 code | n/a | TODO, FIXME, TBD, XXX markers | none found | n/a |

### Known Plan Deviation (recorded, accepted)

Plan 19-08 expected a danger notification for a forged folder move. Filament's `Select` rejects the forged value as a field error on `parent_id` before the Action runs, so the test was adjusted. The Action-level defence (`MoveFolder` re-validates and returns ModelNotFound or `FolderMoveRefused`) is still covered by the Action tests. No goal impact.

### Human Verification Required

See the `human_verification` list in the frontmatter (diff page timing at the budget, light and dark mode, long-line wrapping, sticky panel at 1280 px and disclosure at 800 px, keyboard row menus, loading indicator on the compare selects, the redactInstead link in the restore modal callout, Sentry and SPA long-edit hand-over OD-4).

### Gaps Summary

Every success criterion and all eight requirements (ORG-01..04, VER-02..05) are delivered and wired, proven by 846 passing targeted tests (Unit, Arch, Feature, Schema, Isolation, Concurrency). The fail-closed Partner seam, the immutable history with the single setting-gated redaction exception, the reason-length-only record and the restore and redact lock ordering all hold in the code.

The single gap is a quality gate rather than a behavioural one: PHPStan level 8 reports 17 errors and Pint reports 5 files, all in Phase 19 code. The project rule treats the post-merge static analysis result as part of the final verdict and CI runs PHPStan, so this should be closed with a small gap-closure plan (typed Tag collections, array-shape fixes, `never` return type, closure signatures of `restoreVersionAction()` and `redactVersionAction()`, then Pint) before the phase is shipped. After that, the remaining items are the human browser and owner checks above, which would make the phase `human_needed` rather than `passed`.

---

_Verified: 2026-11-26_
_Verifier: Claude (gsd-verifier)_
