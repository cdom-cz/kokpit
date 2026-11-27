---
phase: 20-image-attachments
verified: 2026-11-27T12:00:00Z
status: human_needed
score: 3/3 roadmap success criteria verified (code and tests); 11/11 plans' must-haves verified; 2 browser/deploy-only items routed to a human
covered_files:
  - ".planning/phases/20-image-attachments/20-01-PLAN.md"
  - ".planning/phases/20-image-attachments/20-01-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-02-PLAN.md"
  - ".planning/phases/20-image-attachments/20-02-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-03-PLAN.md"
  - ".planning/phases/20-image-attachments/20-03-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-04-PLAN.md"
  - ".planning/phases/20-image-attachments/20-04-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-05-PLAN.md"
  - ".planning/phases/20-image-attachments/20-05-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-06-PLAN.md"
  - ".planning/phases/20-image-attachments/20-06-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-07-PLAN.md"
  - ".planning/phases/20-image-attachments/20-07-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-08-PLAN.md"
  - ".planning/phases/20-image-attachments/20-08-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-09-PLAN.md"
  - ".planning/phases/20-image-attachments/20-09-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-10-PLAN.md"
  - ".planning/phases/20-image-attachments/20-10-SUMMARY.md"
  - ".planning/phases/20-image-attachments/20-11-PLAN.md"
  - ".planning/phases/20-image-attachments/20-11-SUMMARY.md"
  - "app/Domain/Knowledge/Actions/BindArticleImages.php"
  - "app/Domain/Knowledge/Actions/DeleteImageForGood.php"
  - "app/Domain/Knowledge/Actions/PruneImages.php"
  - "app/Domain/Knowledge/Actions/StoreArticleImage.php"
  - "app/Domain/Knowledge/Images/ImagePipeline.php"
  - "app/Domain/Knowledge/Jobs/PruneKnowledgeImages.php"
  - "app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php"
  - "app/Http/Controllers/KnowledgeAttachmentController.php"
  - "database/migrations/2026_11_27_000100_create_knowledge_article_images_tables.php"
  - "resources/js/knowledge-editor.js"
covered_digest: "v4:sha256:db9cfc7c2d5de8ae670e21a67397716272a735ce4ff50ebb03124d6422f472ef"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "In DDEV after `ddev exec php artisan filament:assets`, open Znalosti, create a new article and: (1) click 'Vložit obrázek' and choose a PNG; (2) paste a screenshot from the clipboard; (3) drop two files at once on the editor, one of them an SVG; (4) paste plain text; (5) switch to Náhled; (6) save, open the version history and compare two versions where one adds a reference; (7) repeat with a file whose name is 120 characters long and with a transparent PNG in dark mode."
    expected: "(1)-(2) the reference appears at the cursor with the file name selected, the status line shows 'Nahrávám obrázek…' with a spinner while the save button is disabled, then 'Obrázek byl vložen'; the warning callout appears above 'Citlivý článek' and stays; (3) the files upload one after the other, the PNG gets its own line, the SVG shows 'Obrázek nebyl nahrán' with the SVG text and inserts nothing; (4) plain text pastes normally; (5) the preview shows the images inline; (6) the diff row with the reference wraps inside the cell; (7) the long alt text wraps without widening the page and the transparent PNG keeps a visible 1px border in dark mode."
    why_human: "The repository has no JS test tooling. The toolbar button, clipboard paste, drag and drop, CodeMirror events and Livewire morphs (assumption A4) are observable only in a browser. The server side of every step (upload action, refusals, preview rendering, secret notice flag) is covered by Pest tests that pass."
  - test: "In the next Zerops deploy rehearsal, read the init-command output of `php artisan kokpit:deploy:verify` on a new container."
    expected: "The output contains 'Knihovna obrázků GD: v pořádku' and 'všech pět kontrol prošlo'; `sudo apk add` reported no unknown package php85-gd."
    why_human: "Only a real Zerops container proves the Alpine package name php85-gd (assumption A7) and WebP support in its GD (assumption A1). DDEV GD reports WebP true, which says nothing about the Alpine package. The new fifth readiness check fails the deploy loudly if either assumption is wrong, so the risk is a failed deploy, not a silent defect."
---

# Phase 20: Image Attachments Verification Report

**Phase Goal:** Admin can put screenshots and other images into an article while no image becomes publicly reachable or carries hidden metadata
**Verified:** 2026-11-27
**Status:** human_needed (no gaps; two items that only a browser or a real deploy can confirm)
**Re-verification:** No, initial verification

## Goal Achievement

### Observable Truths (ROADMAP success criteria, the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin uploads a PNG, JPEG, WebP or GIF from the article editor and sees it inline in the preview and on the view screen; an SVG, a renamed non-image or a file over the cap is rejected with a message | VERIFIED (server side; editor browser behaviour is in human verification) | `ImagePipeline::process()` decides the type by magic bytes (`ImageFormat::sniff`), cross-checks the header (`getimagesizefromstring` type must equal the sniffed one, PNG must start with IHDR), refuses SVG with its own reason (`looksLikeSvg`), anything else as `NotAnImage`, over 10 MiB as `TooLarge`, over 24 MP or more than the memory limit leaves as `TooManyPixels` before decode, animated GIF/APNG/WebP as `Animated`. `HandlesImageUploads::storeImageUpload()` returns the Czech refusal (`knowledge.images.errors.*`) and always deletes the Livewire temp original in `finally`. `KnowledgeArticleResource` lines 419 and 535 and `ViewArticleVersion` line 122 build the `ImageContext` via `ArticleImages::forBody()`, so the preview, view screen and version view render the img. `fileAttachments(false)` keeps Filament's public attachment flow off. Tests: `ImagePipelineTest`, `ImageUploadTest` (SVG, non-image, over-limit, storage failure, Partner 403), `KnowledgeImageScreensTest` (preview of unsaved article, view screen, edit preview, version view, restore). I re-ran `ImagePipelineTest` and `ImageMetadataStripTest`: 56 passed. |
| 2 | A stored image carries no EXIF (a fictional GPS photo comes back without it) and is served only through an authorised route: a guest, a Partner without access or a stale signed link is refused, and no direct bucket URL appears in the page | VERIFIED | Only GD-encoded output is ever stored (`reencode()` draws onto a fresh canvas; no input byte is copied). `ImageMetadataStripTest` builds a JPEG with a GPS section and Orientation 6 plus a sentinel in every container of every format and asserts the output has no `Exif\0\0`, no GPS section, no orientation tag and no sentinel; it also asserts the visual orientation is preserved. Delivery: `KnowledgeAttachmentController` (route `/admin/knowledge/attachments/{attachment}`, panel auth middleware, `SetNoReferrerPolicy`, own limiter, no `signed` middleware) refuses a Partner 403 before any lookup, 404s unknown/malformed ids, serves a pending image only to its uploader, authorises a bound image through the article policy (archived included), answers 304 only after authorisation, and streams bytes through `Storage::disk()->response()` with `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private, max-age=300`, inline disposition and no file name. No `temporaryUrl`/redirect appears in the controller. A guest is redirected to the login (route test), and a session after logout gets the same; with no signature there is no stale signed link to replay. `InternalImageSrcSanitizer::PATTERN` drops any img src other than the internal route path, so no bucket host can enter rendered HTML; `KnowledgeImageScreensTest` asserts no bucket host, presigned query or livewire-tmp path on the view, version view and preview. The s3 disk has no public visibility and the project's bucket is private. I re-ran `KnowledgeAttachmentRouteTest` (with the prune, delete, save and Arch seam tests): 73 passed. |
| 3 | After an image is removed from an article body, the cleanup job deletes it once no stored version references it, while an image still referenced by an earlier version survives and shows when that version is viewed or restored | VERIFIED | `SaveArticle` calls `BindArticleImages::lockReferenced()` and `bindAndIndex()` in the save transaction (lines 108/126 and 207/223) and writes one reference row per image per version; rows of older versions are never touched. `PruneImages` decides from SQL over the reference table only (`whereDoesntHave('references')`, `whereHas('references')`), never parses Markdown: pending images go after 24 h, an image with no reference gets `orphaned_at`, a stamped image is deleted (object first, then row) more than 7 days later after a recheck under `lockForUpdate`; a reference appearing meanwhile clears the stamp. The reference foreign keys are RESTRICT, so a referenced image cannot be deleted at all. `RedactVersion` deletes the reference rows a redaction removed (line 170), so a fully redacted image becomes collectable. Scheduled daily on one server (`routes/console.php`, `PruneKnowledgeImages`, `#[Idempotent]`). Tests: `PruneKnowledgeImagesTest` (still-referenced image never stamped or deleted, orphan deleted after 7 days, stamp cleared by a new reference, idempotent, schedule), `KnowledgeImageScreensTest` (version view shows the image while an earlier version references it, after restore it shows again, a restored version with a deleted image restores without error and shows alt text), `KnowledgeImageCleanupConcurrencyTest` (real-process race against a save; part of the green full suite). I re-ran the prune and save tests: green. |

**Score:** 3/3 roadmap criteria verified in code and tests; 0 behavior-unverified truths (each state-transition truth has a named passing test).

### Plan must-haves (all 11 plans)

| Plan | Requirements | Key must-haves checked | Status |
|------|--------------|------------------------|--------|
| 20-01 | IMG-02, IMG-03 | Migration: `article_id` NULL = pending; trigger `knowledge_article_images_frozen` raises KP001 on any change except `article_id` (NULL to value) and `orphaned_at`; composite FKs `(version_id, article_id)` and `(image_id, article_id)`, RESTRICT both ways; CHECKs on mime, 1..2400 dimensions, 1 byte..32 MiB, sha256 hex, `orphaned_at` only on a bound image; `down()` refuses while rows exist. Models, morph map, Partner matrix, canary and channel rows present. | VERIFIED |
| 20-02 | IMG-01, IMG-02 | `ImagePipeline` order of checks, header-only pixel and memory gate, animation structure checks, JPEG orientation applied before the strip, 2400 px downscale, same-format re-encode, local error handler, refusal carries a reason only. | VERIFIED |
| 20-03 | IMG-02 | `GdCapability` + fifth step `gd` in `DeployVerifyCommand`; `php85-gd` in `zerops.yml`; `"ext-gd": "*"` in `composer.json`. The real-container proof is a human item. | VERIFIED (deploy proof pending, human) |
| 20-04 | IMG-01, IMG-02 | Renderer takes a per-call `ImageContext` that is reset after the call; unknown/foreign/deleted uuid renders as muted alt text; external image URL stays text; `InternalImageSrcSanitizer` keeps only the route path; Czech copy in `lang/cs/knowledge.php` (`images` block); no page-wide CSP. | VERIFIED |
| 20-05 | IMG-01, IMG-02 | `StoreArticleImage` stores the object first and the row second (removes the object if the row fails), pending, on the default disk under `knowledge/images/<uuid>.<ext>`; `HandlesImageUploads` deletes the temporary original in `finally`; `ImageNames` sanitises alt text; notice flag only after a success; Partner refused by mount and Gate. | VERIFIED |
| 20-06 | IMG-03 | `BindArticleImages` locks image rows in id order after the article row, refuses foreign or another user's pending images with the Czech field error on `body`, skips unknown/deleted ones, binds pending ones and indexes per version; `RedactVersion` resyncs references; `KnowledgeSeamTest` pins the reviewed writers of the reference table. | VERIFIED |
| 20-07 | IMG-02 | Controller and route as described under criterion 2; limiter registered in `AppServiceProvider`; route walk and channel probe know the parameter. | VERIFIED |
| 20-08 | IMG-01, IMG-02, IMG-03 | Images on preview, view screen, version view and restore (`ArticleImages`); editor wiring (`knowledge-editor.js` 620 lines: button, paste, drop, serial queue, status line) with `data-kokpit-image*` attributes from the resource; secret notice callout above the sensitive toggle; Filament attachment flow and built-in preview off. Script registered via `KnowledgeServiceProvider` (`Js::make('knowledge-editor', ...)`). | VERIFIED (server side) / browser behaviour is a human item |
| 20-09 | IMG-03 | `PruneImages` four passes with recheck under row lock; `PruneKnowledgeImages` scheduled daily on one server; concurrency test file and worker script present. | VERIFIED |
| 20-10 | IMG-03 | `DeleteImageForGood`: reason validated by the shared `ReasonText` and dropped, one `image_deleted` activity row with `reason_recorded: true` only, object + image row + all reference rows in one transaction, failures surface as `ArticleWriteFailed` with a SQLSTATE or `storage` only, Partner refused, archived article allowed. | VERIFIED |
| 20-11 | IMG-02, IMG-03 | `KnowledgeArticleImagesRelationManager` registered in `KnowledgeArticleResource::getRelations()`; Partner matrix declaration present; CONTRIBUTING.md has the "Phase 20 (knowledge images)" hand-over bullet pinned by `RepositoryFilesTest`. | VERIFIED |

### Required Artifacts

| Artifact | Status | Details |
|----------|--------|---------|
| `database/migrations/2026_11_27_000100_create_knowledge_article_images_tables.php` | VERIFIED | 131 lines, constraints and trigger as above |
| `app/Domain/Knowledge/Images/*` (pipeline, animation, orientation, capability, names, formats) | VERIFIED | Substantive, used by `StoreArticleImage` and `DeployVerifyCommand` |
| `app/Domain/Knowledge/Actions/{StoreArticleImage,BindArticleImages,PruneImages,DeleteImageForGood,ReasonText}.php` | VERIFIED | Wired: handler trait, `SaveArticle`, job, relation manager |
| `app/Http/Controllers/KnowledgeAttachmentController.php` | VERIFIED | Registered in `AdminPanelProvider` and confirmed by `route:list` |
| `app/Domain/Knowledge/Support/{ImageContext,ImageReferences,InternalImageSrcSanitizer,KnowledgeMarkdown}.php` | VERIFIED | Used by the resource preview, view screen and version view |
| `app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php` | VERIFIED | Registered, Partner matrix row present |
| `resources/js/knowledge-editor.js` | VERIFIED (static) | Registered as a Filament asset; runtime behaviour is a human item |
| `routes/console.php` schedule `kokpit-knowledge-image-prune` | VERIFIED | Daily, one server |

### Key Link Verification

| From | To | Status |
|------|----|--------|
| Editor script -> `storeImageUpload()` -> `StoreArticleImage` -> `ImagePipeline` -> private disk + pending row | WIRED |
| `SaveArticle` -> `BindArticleImages` (lock, bind, index) inside the save transaction | WIRED |
| `RedactVersion` / `DeleteImageForGood` -> reference table (reviewed writers, pinned by Arch test) | WIRED |
| Renderer `kokpit-attachment:<uuid>` -> `ImageContext` -> `KnowledgeAttachmentController::path()` -> sanitiser pattern | WIRED |
| `PruneKnowledgeImages` job -> `PruneImages` -> reference-table SQL | WIRED |
| `DeployVerifyCommand` -> `GdCapability` | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|----------|------|--------|-----------|--------|
| Preview / view / version view img | `ImageContext` | `ArticleImages::forBody()` queries `knowledge_article_images` by uuids named in the body, scoped to the article (and the Admin's own pending images in the preview) | Yes | FLOWING |
| Obrázky table | thumbnail URL, usage badge | image rows and reference table; thumbnail is the application route URL | Yes | FLOWING |
| Image route | bytes | private disk object of the stored row | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Pipeline strips EXIF/GPS/sentinel, keeps orientation, refuses SVG/bomb/animated | `ddev exec ... pest tests/Unit/Knowledge/ImageMetadataStripTest.php tests/Unit/Knowledge/ImagePipelineTest.php` | 56 passed, 164 assertions | PASS |
| Route authorisation, prune, delete-for-good, save indexing, Arch reviewed writers | `ddev exec ... pest KnowledgeAttachmentRouteTest PruneKnowledgeImagesTest DeleteImageForGoodTest SaveArticleImagesTest KnowledgeSeamTest` | 73 passed, 305 assertions | PASS |
| Route registered | `ddev exec php artisan route:list --path=knowledge/attachments` | `GET admin/knowledge/attachments/{attachment}` present | PASS |
| DDEV GD supports WebP | `ddev exec php -r 'var_dump(gd_info()["WebP Support"]);'` | `bool(true)` | PASS (DDEV only) |

The full suite (9113 passed, 3 skipped), PHPStan level 8 and Pint were reported green by the caller; I did not re-run them.

### Probe Execution

No phase-declared probe scripts. Step skipped.

### Requirements Coverage

| Requirement | Source plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| IMG-01 | 20-02, 20-04, 20-05, 20-08 | Only images (PNG, JPEG, WebP, GIF) can be attached; SVG is rejected | SATISFIED | Content-based sniff, SVG refusal reason, `ImagePipelineTest`, `ImageUploadTest`; DB CHECK on mime type as backstop |
| IMG-02 | 20-01, 20-02, 20-03, 20-04, 20-05, 20-07, 20-08, 20-11 | Images stored privately, re-encoded without EXIF, delivered only through an authorised route | SATISFIED (deploy GD proof pending, human) | Pipeline, private default disk, controller, sanitiser, route tests |
| IMG-03 | 20-01, 20-06, 20-08, 20-09, 20-10, 20-11 | Orphaned images cleaned up, respecting references from stored versions | SATISFIED | Reference table with RESTRICT keys, `PruneImages`, schedule, concurrency test, delete-for-good |

All three IDs declared in the PLAN frontmatter (IMG-01, IMG-02, IMG-03) are mapped to Phase 20 in REQUIREMENTS.md and covered; there are no orphaned requirement IDs for this phase.

### Anti-Patterns Found

None blocking. A scan of the phase's source files for `TBD`, `FIXME`, `XXX`, `TODO` and `HACK` found no matches. No stub returns, hardcoded empty props or console-only handlers in the new files.

### Advisory Observations (not gaps)

| # | Observation | Severity |
|---|-------------|----------|
| 1 | `.planning/REQUIREMENTS.md` still shows IMG-01..IMG-03 unchecked and "Pending" in the traceability table, and the ROADMAP Phase 20 line is unchecked. Tracking update for the orchestrator after this verification. | Info |
| 2 | The deploy bullet in `CONTRIBUTING.md` (the "Each deploy pushes the `kokpit` setup once" paragraph) lists the checks of `kokpit:deploy:verify` without the new GD check, although the command now has five. A documentation drift only. | Info |
| 3 | The image route path `/admin/knowledge/attachments/` is pinned to the panel path `admin` in `InternalImageSrcSanitizer::PATTERN`; a Feature test pins that it equals `KnowledgeAttachmentController::path()`, so a panel path change fails a test instead of silently dropping images. | Info |
| 4 | `Cache-Control: private, max-age=300` lets a browser keep a copy for up to five minutes after a delete or logout; this is accepted decision D-12 and the server refuses every fresh request. | Info |

### Human Verification Required

#### 1. Editor behaviour in a real browser

**Test:** See the `human_verification` entry 1 in the frontmatter (toolbar button, pasted screenshot, dropped files including an SVG, plain text paste, preview, diff wrap, 120-character name, transparent PNG in dark mode), after `ddev exec php artisan filament:assets`.
**Expected:** As listed in the frontmatter.
**Why human:** Clipboard, drag and drop, CodeMirror and Livewire morph behaviour have no JS test tooling in this repository.

#### 2. First Zerops deploy rehearsal

**Test:** Read the `kokpit:deploy:verify` output of the next deploy rehearsal.
**Expected:** 'Knihovna obrázků GD: v pořádku', 'všech pět kontrol prošlo', and no unknown-package error for `php85-gd`.
**Why human:** Only a real Alpine container proves the package name (A7) and WebP support in its GD (A1).

### Gaps Summary

No gaps. Every ROADMAP success criterion and every plan must-have is backed by code I read and by passing tests I re-ran in DDEV, and the caller's full-suite, PHPStan and Pint results are green. The status is `human_needed` solely because the editor's browser-only interactions and the first real deploy's GD/package check cannot be proven from the codebase.

---

_Verified: 2026-11-27_
_Verifier: Claude (gsd-verifier)_
