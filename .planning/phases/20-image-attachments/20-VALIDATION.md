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

| Req | Behavior | Plan / Task | Test Type | Automated Command | File Exists | Status |
|-----|----------|-------------|-----------|-------------------|-------------|--------|
| IMG-01 | Allowed formats accepted; SVG, renamed non-image, oversize, pixel-bomb, animated GIF/APNG/WebP refused | 20-02 T2 | unit | `pest tests/Unit/Knowledge/ImagePipelineTest.php` | ❌ created by 20-02 | ⬜ pending |
| IMG-01 | Upload returns id/reference or the Czech message; nothing stored on failure; temp original deleted; Partner refused | 20-05 T1, T2 | feature | `pest tests/Feature/Knowledge/ImageUploadTest.php tests/Feature/Knowledge/StoreArticleImageTest.php` | ❌ created by 20-05 | ⬜ pending |
| IMG-01 | Hostile file names give inert alt text | 20-05 T2 | unit | `pest tests/Unit/Knowledge/ImageNamesTest.php` | ❌ created by 20-05 | ⬜ pending |
| IMG-01 | Editor keeps `fileAttachments(false)`; script and data attributes registered; secret notice after upload | 20-08 T3 | feature | `pest tests/Feature/Knowledge/ImageEditorWiringTest.php` | ❌ created by 20-08 | ⬜ pending |
| IMG-01 | All Phase 20 Czech copy pinned | 20-04 T3 | feature | `pest tests/Feature/Knowledge/KnowledgeLangTest.php` | ✅ extend | ⬜ pending |
| IMG-02 | Stored bytes carry no EXIF/GPS/sentinel; orientation applied; dimensions capped | 20-02 T1 | unit | `pest tests/Unit/Knowledge/ImageMetadataStripTest.php` | ❌ created by 20-02 | ⬜ pending |
| IMG-02 | GD capability on the deploy target fails the deploy when missing | 20-03 T1 | unit + feature | `pest tests/Unit/Knowledge/GdCapabilityTest.php tests/Feature/Operations/DeployVerifyCommandTest.php` | ❌ / ✅ extend | ⬜ pending |
| IMG-02 | Delivery route refuses guest, logout, Partner, foreign; admin gets D-12 headers; 304 only after auth; no CSP on pages | 20-07 T1, T2 | feature | `pest tests/Feature/Knowledge/KnowledgeAttachmentRouteTest.php` | ❌ created by 20-07 | ⬜ pending |
| IMG-02 | Renderer emits `<img>` only for context images; sanitiser drops other `src`; missing image fallback | 20-04 T1, T2 | unit | `pest tests/Unit/Knowledge/KnowledgeMarkdownTest.php tests/Unit/Knowledge/ImageReferencesTest.php` | ✅ extend | ⬜ pending |
| IMG-02 | Preview, view screen and version view show images; no bucket host in any page | 20-08 T1, T2 | feature | `pest tests/Feature/Knowledge/KnowledgeImageScreensTest.php` | ❌ created by 20-08 | ⬜ pending |
| IMG-02 | Route walk, channel probe and canary: Partner never reaches an image | 20-01 T3, 20-07 T3 | isolation + audit | `pest tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php` and `pest --testsuite=Audit` | ✅ extend | ⬜ pending |
| IMG-02 | Obrázky table: thumbnails through the route, no bucket URL, Partner refused | 20-11 T1, T2 | feature | `pest tests/Feature/Knowledge/KnowledgeArticleImagesRelationManagerTest.php` | ❌ created by 20-11 | ⬜ pending |
| IMG-03 | Schema: tables, composite FKs, checks, frozen trigger, rollback refusal | 20-01 T1 | feature | `pest tests/Feature/Schema/KnowledgeTablesTest.php` | ✅ extend | ⬜ pending |
| IMG-03 | `SaveArticle` binds, indexes references, refuses foreign image, restore survives deleted image | 20-06 T1, T2 | feature | `pest tests/Feature/Knowledge/SaveArticleImagesTest.php` | ❌ created by 20-06 | ⬜ pending |
| IMG-03 | `RedactVersion` resyncs references; only reviewed writers touch the reference table | 20-06 T3, 20-10 T2 | feature + arch | `pest tests/Feature/Knowledge/RedactVersionImagesTest.php tests/Arch/KnowledgeSeamTest.php` | ❌ / ✅ extend | ⬜ pending |
| IMG-03 | Cleanup deletes pending after 24 h and orphans after 7 days, keeps versioned images | 20-09 T1, T2 | feature | `pest tests/Feature/Knowledge/PruneKnowledgeImagesTest.php` | ❌ created by 20-09 | ⬜ pending |
| IMG-03 | Cleanup vs concurrent save leaves no reference to a deleted image and fails neither | 20-09 T3 | concurrency | `pest tests/Concurrency/KnowledgeImageCleanupConcurrencyTest.php` | ❌ created by 20-09 | ⬜ pending |
| IMG-03 | Delete for good removes object, row, references; activity row without reason text or file name | 20-10 T1, T2 | feature | `pest tests/Feature/Knowledge/DeleteImageForGoodTest.php` | ❌ created by 20-10 | ⬜ pending |

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
| Paste, drop and toolbar upload in the editor, progress and notice (20-08 T3 human-check, A4) | IMG-01 | No JS test tooling in the repo | In DDEV open an article, paste a screenshot, drop a file, use the toolbar button; confirm inline preview and rejection message for an SVG |
| GD with WebP and the php85-gd package on the Zerops runtime (20-03 T2 human-check, A1, A7) | IMG-02 | Only a real Zerops container proves it | In the next deploy rehearsal read the `kokpit:deploy:verify` output: the GD line is ok and all five checks pass |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 300s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
