---
phase: "20"
slug: image-attachments
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-11-27"
---

# Phase 20 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13.3, Larastan level 8, Pint |
| **Config file** | `phpunit.xml` (suites Unit, Arch, Feature, Isolation, Concurrency, Audit) |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Knowledge/<File>Test.php` |
| **Full suite command** | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` plus `ddev exec vendor/bin/pest --testsuite=Audit` (no `--parallel`) |
| **Estimated runtime** | ~300 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** run the test file of the task (in DDEV after merge; Pint only in a worktree)
- **After every plan wave:** run `tests/Unit/Knowledge`, `tests/Feature/Knowledge`, `tests/Arch`, `tests/Isolation`
- **Before `/gsd-verify-work`:** full suite and Audit suite must be green
- **Max feedback latency:** 300 seconds

---

## Per-Task Verification Map

| Req | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| IMG-01 | Allowed formats accepted; SVG, renamed non-image, oversize, pixel-bomb, animated refused | unit | `pest tests/Unit/Knowledge/ImagePipelineTest.php` | ❌ W0 | ⬜ pending |
| IMG-01 | Upload returns id/name or message; nothing stored on failure; Partner refused | feature | `pest tests/Feature/Knowledge/ImageUploadTest.php` | ❌ W0 | ⬜ pending |
| IMG-01 | Editor keeps `fileAttachments(false)`; script and data attributes registered | feature | `pest tests/Feature/Knowledge/ImageEditorWiringTest.php` | ❌ W0 | ⬜ pending |
| IMG-02 | Stored bytes carry no EXIF/GPS; orientation applied; dimensions capped | unit | `pest tests/Unit/Knowledge/ImageMetadataStripTest.php` | ❌ W0 | ⬜ pending |
| IMG-02 | Delivery route refuses guest/Partner/foreign; admin gets D-12 headers; no bucket host in pages | feature | `pest tests/Feature/Knowledge/KnowledgeAttachmentRouteTest.php` | ❌ W0 | ⬜ pending |
| IMG-02 | Renderer emits `<img>` only for context images; sanitiser drops other `src` | unit | `pest tests/Unit/Knowledge/KnowledgeMarkdownTest.php` | ✅ extend | ⬜ pending |
| IMG-02 | Route walk and canary: Partner never reaches an image | isolation | `pest tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ extend | ⬜ pending |
| IMG-03 | `SaveArticle` binds, indexes references, refuses foreign image, restore survives deleted image | feature | `pest tests/Feature/Knowledge/SaveArticleImagesTest.php` | ❌ W0 | ⬜ pending |
| IMG-03 | `RedactVersion` resyncs references; cleanup keeps referenced images, deletes orphans after grace | feature | `pest tests/Feature/Knowledge/PruneKnowledgeImagesTest.php` | ❌ W0 | ⬜ pending |
| IMG-03 | Delete for good removes object, row, references; activity row without reason text | feature | `pest tests/Feature/Knowledge/DeleteImageForGoodTest.php` | ❌ W0 | ⬜ pending |
| IMG-03 | Cleanup vs concurrent save leaves no reference to a deleted image | concurrency | `pest tests/Concurrency/KnowledgeImageCleanupConcurrencyTest.php` | ❌ W0 | ⬜ pending |
| IMG-03 | Schema: tables, composite FKs, checks, triggers | feature | `pest tests/Feature/Schema/KnowledgeTablesTest.php` | ✅ extend | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/ImageFixtures.php` — runtime builders (EXIF JPEG, sentinel injectors, animated GIF/APNG/WebP, SVG, bomb header, oversized)
- [ ] New test files listed in the map above
- [ ] Declarations that fail existing audits until added: PartnerMatrix, ModelDeclarationTest, MorphMap, AccessServiceProvider, CanaryRegistry, ChannelMatrix, RouteWalkTest::walkedUrl(), JobContractTest, KnowledgeSeamTest, RepositoryFilesTest, KnowledgeLangTest, `lang/cs/kokpit.php` event label

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Paste, drop and toolbar upload in the editor, progress and notice | IMG-01 | No JS test tooling in the repo | In DDEV open an article, paste a screenshot, drop a file, use the toolbar button; confirm inline preview and rejection message for an SVG |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 300s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
