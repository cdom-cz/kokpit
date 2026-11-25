---
phase: "19"
slug: folders-tags-and-version-history
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-11-25"
---

# Phase 19 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Derived from `19-RESEARCH.md` (Validation Architecture).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest (PHPUnit 13 runner) with Livewire/Filament helpers; suites Unit, Arch, Feature, Isolation, Concurrency, Audit |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Knowledge/<File>.php` |
| **Full suite command** | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (no `--parallel`) |
| **Estimated runtime** | ~600 seconds (full), ~15 seconds (single file) |

---

## Sampling Rate

- **After every task commit:** the single relevant test file in DDEV after the merge; in a worktree only Pint and DB-free static analysis
- **After every plan wave:** `ddev exec vendor/bin/pest --testsuite=Arch,Unit` plus the Feature files of that wave
- **Before `/gsd-verify-work`:** full suite green, then `ddev exec vendor/bin/phpstan analyse` and `scripts/check-sensitive.sh`
- **Max feedback latency:** 60 seconds for the per-task run

---

## Per-Task Verification Map

Filled by the planner per task; requirement-to-test mapping is in `19-RESEARCH.md` (Phase Requirements to Test Map) covering ORG-01..04 and VER-02..05.

Every DDEV command (Pest, PHPStan, Composer) runs only after the wave is merged into the HEAD branch (CLAUDE.md), as `ddev exec php -d memory_limit=1G vendor/bin/pest <files>`; in a worktree each task runs only its `php -l` and grep gate. Test files marked W0 are written first inside the same task (tests first), so no separate Wave 0 plan exists.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 19-01-T1 | 19-01 | 1 | VER-03, VER-05 | T-19-01, T-19-02, T-19-03, T-19-49 | body-only rewrite only while the setting is on; reset proven; no column for the reason text (OD-1); down() refuses on a non-empty history | schema | `pest tests/Feature/Schema/KnowledgeTablesTest.php tests/Feature/Schema/SchemaConventionsTest.php` | ✅ extend | ⬜ pending |
| 19-01-T2 | 19-01 | 1 | ORG-03 | T-19-04 | archived article keeps knowledge tags; types never mix | feature | `pest tests/Feature/Knowledge/KnowledgeArticleTagsTest.php tests/Isolation/KnowledgeVisibilityTest.php` | ❌ W0 | ⬜ pending |
| 19-02-T1 | 19-02 | 1 | VER-05 | T-19-05 | redaction model deny-all, canary, matrix row | arch/isolation/audit | `pest tests/Arch/ModelDeclarationTest.php tests/Isolation/CanaryRegistryTest.php` then `pest tests/Audit/PartnerActionsTest.php tests/Audit/AccessMatrixTest.php` | ✅ extend | ⬜ pending |
| 19-02-T2 | 19-02 | 1 | ORG-01, VER-02 | — | fixtures only, fictional data | support | `php -l tests/Support/KnowledgeFixtures.php && scripts/check-sensitive.sh tests/Support/KnowledgeFixtures.php` | ✅ extend | ⬜ pending |
| 19-02-T3 | 19-02 | 1 | ORG-01..04, VER-02..05 | T-19-06 | reason helper copy warns against typing the secret; `redact.later_versions_keep` wording pinned (OD-3) | feature | `pest tests/Feature/Knowledge/KnowledgeLangTest.php` | ✅ extend | ⬜ pending |
| 19-03-T1 | 19-03 | 1 | VER-04 | T-19-09, T-19-52 | owner-approved caps (OD-2) bound the diff; runtime requirement via `ddev composer` | unit | `pest tests/Unit/Knowledge/VersionDiffTest.php` (after the post-merge `ddev composer require`), then the DDEV timing command of 19-03 | ❌ W0 | ⬜ pending |
| 19-03-T2 | 19-03 | 1 | VER-04 | T-19-08 | escaped rows, hostile corpus, scoped stylesheet | feature | `pest tests/Feature/Knowledge/VersionDiffViewTest.php tests/Arch/KnowledgeSeamTest.php` | ❌ W0 | ⬜ pending |
| 19-04-T1 | 19-04 | 2 | VER-05 | T-19-11 | required redaction token; editor opened before a redaction refused | feature/arch | `pest tests/Feature/Knowledge/SaveArticleTokensTest.php tests/Feature/Knowledge/SaveArticleTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php tests/Arch/KnowledgeSeamTest.php` (after the whole wave 2, with 19-12) | ❌ W0 | ⬜ pending |
| 19-04-T2 | 19-04 | 2 | ORG-03, ORG-04 | T-19-12, T-19-13, T-19-14, T-19-15 | classification and tags only inside the locked, guarded save | feature | `pest tests/Feature/Knowledge/SaveArticleClassificationTest.php` | ❌ W0 | ⬜ pending |
| 19-04-T3 | 19-04 | 2 | VER-03 | T-19-13 | restore marker and number-only activity row | feature | `pest tests/Feature/Knowledge/SaveArticleTokensTest.php tests/Feature/Knowledge/SaveArticleTest.php` | ❌ W0 | ⬜ pending |
| 19-12-T1 | 19-12 | 2 | VER-05 | T-19-61 | Phase 18 save and edit proofs unchanged on the new signature | feature | `pest tests/Feature/Knowledge/SaveArticleTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php` (after the whole wave 2) | ✅ extend | ⬜ pending |
| 19-12-T2 | 19-12 | 2 | VER-05 | T-19-61 | one winner, gap-free versions on the new signature | concurrency | `pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` alone | ✅ extend | ⬜ pending |
| 19-05-T1 | 19-05 | 2 | ORG-01 | T-19-16, T-19-19 | Admin-only create, name rules, two-query tree | feature | `pest tests/Feature/Knowledge/FolderActionsTest.php tests/Feature/Knowledge/FolderTreeTest.php` | ❌ W0 | ⬜ pending |
| 19-05-T2 | 19-05 | 2 | ORG-01 | T-19-17 | cycle and depth refused under the tree lock; KP003 backstop | feature | `pest tests/Feature/Knowledge/FolderActionsTest.php tests/Feature/Schema/KnowledgeTablesTest.php` | ❌ W0 | ⬜ pending |
| 19-05-T3 | 19-05 | 2 | ORG-02 | T-19-18 | non-empty delete refused, archived counted | feature | `pest tests/Feature/Knowledge/FolderActionsTest.php` | ❌ W0 | ⬜ pending |
| 19-06-T1 | 19-06 | 2 | VER-02 | T-19-20, T-19-22, T-19-23 | mount guard, no body in the table, one renderer | feature | `pest tests/Feature/Knowledge/ArticleVersionsPageTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php` | ❌ W0 | ⬜ pending |
| 19-06-T2 | 19-06 | 2 | VER-04 | T-19-21, T-19-24 | from/to validated, two bodies only, redacted body shown | feature | `pest tests/Feature/Knowledge/VersionComparePageTest.php` | ❌ W0 | ⬜ pending |
| 19-06-T3 | 19-06 | 2 | VER-02, VER-04 | T-19-20 | Partner 403 on versions, version and compare URLs | isolation/audit | `pest tests/Isolation/KnowledgeScreensTest.php tests/Isolation/RouteWalkTest.php` then `pest tests/Audit/RouteAuditTest.php tests/Audit/AccessMatrixTest.php` | ✅ extend | ⬜ pending |
| 19-07-T1 | 19-07 | 2 | ORG-04 | T-19-26 | client tab without body_md, general kind only | feature | `pest tests/Feature/Knowledge/KnowledgeTabsTest.php` | ❌ W0 | ⬜ pending |
| 19-07-T2 | 19-07 | 2 | ORG-04 | T-19-27 | project tab, archived switch, add-article link | feature | `pest tests/Feature/Knowledge/KnowledgeTabsTest.php tests/Feature/Clients tests/Feature/Projects` | ❌ W0 | ⬜ pending |
| 19-07-T3 | 19-07 | 2 | ORG-04 | T-19-25 | Partner sees no Znalosti tab; matrix rows | isolation/audit | `pest tests/Isolation/KnowledgeTabsTest.php tests/Arch/PanelRegistryTest.php` then `pest tests/Audit/AccessMatrixTest.php` | ❌ W0 | ⬜ pending |
| 19-08-T1 | 19-08 | 3 | ORG-01 | T-19-28, T-19-31 | ?folder= normalised; subtree filter | feature | `pest tests/Feature/Knowledge/FolderTreeListTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php` | ❌ W0 | ⬜ pending |
| 19-08-T2 | 19-08 | 3 | ORG-01, ORG-02 | T-19-29 | folder actions re-resolve ids; refusals explained | feature | `pest tests/Feature/Knowledge/FolderTreeActionsTest.php` | ❌ W0 | ⬜ pending |
| 19-08-T3 | 19-08 | 3 | ORG-03 | T-19-30 | knowledge-only AND tag filter; forged tag id matches nothing | feature | `pest tests/Feature/Knowledge/KnowledgeListFiltersTest.php` | ❌ W0 | ⬜ pending |
| 19-09-T1 | 19-09 | 3 | VER-03 | T-19-32, T-19-38 | restore through SaveArticle; lock before read | feature | `pest tests/Feature/Knowledge/RestoreVersionTest.php` | ❌ W0 | ⬜ pending |
| 19-09-T2 | 19-09 | 3 | VER-05 | T-19-34, T-19-35, T-19-36, T-19-37 | body-only redaction, reason never persisted or logged (OD-1), Arch writer map and persistence ban | feature/unit/arch | `pest tests/Unit/Knowledge/RedactionLinesTest.php tests/Feature/Knowledge/RedactVersionTest.php tests/Arch/KnowledgeSeamTest.php tests/Feature/Schema/KnowledgeTablesTest.php` | ❌ W0 | ⬜ pending |
| 19-09-T3 | 19-09 | 3 | VER-03, VER-05 | T-19-33 | restore waiting for a redaction restores the marker | concurrency | `pest tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php` alone | ❌ W0 | ⬜ pending |
| 19-10-T1 | 19-10 | 3 | ORG-03, ORG-04 | T-19-40, T-19-42 | classification through SaveArticle; only client live | feature | `pest tests/Feature/Knowledge/ArticleClassificationFormTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php` | ❌ W0 | ⬜ pending |
| 19-10-T2 | 19-10 | 3 | ORG-04 | T-19-39 | prefill accepts only valid UUIDs of existing records | feature | `pest tests/Feature/Knowledge/ArticleLinksTest.php tests/Feature/Knowledge/CreateKnowledgeArticleTest.php` | ❌ W0 | ⬜ pending |
| 19-10-T3 | 19-10 | 3 | ORG-03 | T-19-41 | knowledge and client tags never mix in suggestions | feature | `pest tests/Feature/Knowledge/KnowledgeTagsTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php` | ❌ W0 | ⬜ pending |
| 19-11-T1 | 19-11 | 4 | VER-03 | T-19-43, T-19-47 | tokens taken at mount, locked | feature | `pest tests/Feature/Knowledge/VersionRestoreModalTest.php tests/Feature/Knowledge/ArticleVersionsPageTest.php` | ❌ W0 | ⬜ pending |
| 19-11-T2 | 19-11 | 4 | VER-03 | T-19-43, T-19-44 | guard refusal keeps modal open; acknowledgment bound to the stored body | feature/repo | `pest tests/Feature/Knowledge/VersionRestoreModalTest.php tests/Feature/Repo/RepositoryFilesTest.php` | ❌ W0 | ⬜ pending |
| 19-11-T3 | 19-11 | 4 | VER-05 | T-19-44, T-19-45, T-19-46, T-19-48 | redacted text gone from view, diff and restore; reason never shown; non-cascade warning `redact.later_versions_keep` shown (OD-3) | feature | `pest tests/Feature/Knowledge/VersionRedactModalTest.php tests/Feature/Knowledge/VersionComparePageTest.php tests/Arch/KnowledgeSeamTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Knowledge/` — FolderActionsTest, FolderTreeListTest, KnowledgeTagsTest, SaveArticleClassificationTest, ArticleLinksTest, KnowledgeRelationManagersTest, ArticleVersionsPageTest, VersionComparePageTest, RestoreVersionTest, RedactVersionTest
- [ ] `tests/Unit/Knowledge/VersionDiffTest.php`
- [ ] `tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php` plus worker script
- [ ] `KnowledgeFixtures` helpers: tagged article, folder chain of given depth, restored version

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Restore and redact modals keep open on guard refusal | VER-03, VER-05 | A11 resolved from vendor source (Halt keeps the action mounted) and covered by Livewire tests in 19-11; the visual check stays manual | End of phase, browser: `<human-check>` of 19-11 Tasks 2 and 3 |
| Tree panel layout at 1280px and 800px, keyboard reach of row menus, live count update | ORG-01 | Responsive layout and focus are visual | End of phase, browser: `<human-check>` of 19-08 Tasks 1 and 2 |
| Diff legibility in light and dark mode, loading indicator on compare | VER-04 | Colour and timing are visual | End of phase, browser: `<human-check>` of 19-03 Task 2 and 19-06 Task 2 |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
