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

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | — | — | ORG-01..04, VER-02..05 | — | See RESEARCH | feature/schema/arch/concurrency | see RESEARCH | ❌ W0 | ⬜ pending |

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
| Restore and redact modals keep open on guard refusal | VER-03, VER-05 | Filament modal composition not prototyped (A11) | Spike first, then exercise in browser |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
