---
phase: 20-image-attachments
plan: 11
subsystem: knowledge
tags: [images, relation-manager, delete, filament, contributing]
status: complete

requires:
  - phase: 20-image-attachments
    provides: image route (20-07), screens and renderer (20-08), DeleteImageForGood and ReasonText (20-10), lang keys knowledge.images.* (20-04)
provides:
  - KnowledgeArticleImagesRelationManager (Admin-only "Obrázky" tab on the article view screen, deleteForGood table action)
  - Partner matrix declaration of the manager
  - CONTRIBUTING.md Phase 20 hand-over bullet and its RepositoryFilesTest case
affects: [phase-23-sharing]

tech-stack:
  added: []
  patterns:
    - "ImageColumn fed an absolute application route URL as its state, so Filament never asks a disk for a URL"
    - "relation manager table action with the redaction-modal error mapping (mountedActions.0.data.<field>)"

key-files:
  created:
    - app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php
    - tests/Feature/Knowledge/KnowledgeArticleImagesRelationManagerTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - tests/Support/PartnerMatrix.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php

key-decisions:
  - "Followed rulings R-20-11-1 to R-20-11-5."
  - "The empty-state description uses the existing key knowledge.images.manager.empty_body (the plan text says empty_description; the 20-04 lang file defines empty_body)."

requirements-completed: [IMG-02, IMG-03]

plan_head_before: e4bb3af8eec35e2001c73c227064188c1161a537
plan_head_after: e705b2a62e3d2410cb1b7b389693b870c72adc23
commits: 2

actuals:
  tokens: 7500
  tasks: 3
  commits: 2

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 11: Obrázky relation manager and Phase 20 hand-over Summary

**An Admin-only "Obrázky" tab on the article view screen lists the article's bound images with an authorised-route thumbnail, facts and usage badge, and deletes one for good with a required reason; the manager is declared in the Partner matrix and Phase 20 is handed over in CONTRIBUTING.md.**

## What was built

- **`KnowledgeArticleImagesRelationManager`** (final, `#[AccessRule(Audience::AdminOnly, ...)]` on the class, `EnforcesRelationManagerAccessRule`, `$relationship = 'images'`, `isReadOnly()` false so the action is not hidden on a view page). The query adds two `withExists` flags (`used_by_current`: a reference whose version has the owner's `version_no`; `used_by_any`). Columns: 48 x 48 `ImageColumn` whose state is the absolute `route(KnowledgeAttachmentController::ROUTE, ...)` URL (Filament returns a valid URL as is, so no disk URL, presigned query or storage path can appear), file name (wraps), dimensions (`knowledge.images.manager.dimensions`), size via `DocumentColumns::humanSize()`, upload time in Europe/Prague, and the usage badge (success, gray, warning). Sort: created_at desc then id desc; pages of 10/25/50, default 10; no search, filters, header, toolbar or bulk actions; empty state from `knowledge.images.manager.empty_*`.
- **`deleteForGood` record action** (danger, trash icon): danger "irreversible" callout, warning "rotate the secret" callout, required `reason` textarea (3 rows, autofocus, max `DeleteImageForGood::MAX_REASON_LENGTH`, messages from `knowledge.images.delete.errors.*`). It calls `DeleteImageForGood::handle()`; a `ValidationException` is re-thrown under `mountedActions.0.data.<field>` (modal stays open, message under the field); `ArticleWriteFailed` is reported, answered with a persistent danger notification with the fixed failure text, and halts the action; success sends "Obrázek byl smazán". The reason goes to the Action only.
- **Registration:** `KnowledgeArticleResource::getRelations()` lists the manager after the history manager; `PartnerMatrix::panelClasses()` declares it (`admin` true, `partner` false). The edit screen keeps no relation managers.
- **Hand-over:** CONTRIBUTING.md bullet "Phase 20 (knowledge images)." after the Phase 19 bullet, naming all 17 classes of R-20-11-5 and the rules (route only, `kokpit-attachment:<uuid>`, GD-only output and A5, reviewed reference writers, cleanup never parses Markdown, `reason_recorded` only, `kokpit:deploy:verify` GD check, no page-wide CSP D-15, Phase 23 only through the share rule) and the 11 enforcing tests. `RepositoryFilesTest` gains `hands Phase 20 over: the knowledge images bullet names only what exists`: required phrases, every backticked CamelCase name resolves to a class, every named test file exists.
- **Tests** (`KnowledgeArticleImagesRelationManagerTest`, helpers prefixed `knowledgeImagesManager`): tracer delete with body alt-text afterwards; usage badges, dimensions, size and newest-first order; pending and foreign images not listed; thumbnail is the absolute route and no `bucket.example.com`, `X-Amz-` or `knowledge/images/` in the table; empty state; 12 images give a 10-row first page; modal texts; empty and over-long reason keep the image; action-level refusal (NUL) shown under the field; failing storage gives the danger notification and nothing changes; history tab shows "Obrázek smazán navždy" without reason or file name; Partner refused (component, view screen, `canViewForRecord`).

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 + 2 | Relation manager, registration, matrix, full Feature test file | 048dd473 |
| 3 | CONTRIBUTING.md bullet and repository test | e705b2a6 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Lang key name**
- **Found during:** Task 1
- **Issue:** the plan names `knowledge.images.manager.empty_description`; the lang file from 20-04 holds `empty_body`.
- **Fix:** used `empty_body`.
- **Files modified:** app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php
- **Commit:** 048dd473

### Process note

Tasks 1 and 2 were committed together: the manager was written complete (table and error paths) in one pass, and the whole test file was written at once, so there was no meaningful intermediate state for a separate Task 2 commit. The tracer gate (end-to-end re-run of the verify) needs the database and is deferred to the post-merge run.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites here, no vendor in the worktree):

- `php -l` clean on every touched PHP file; Pint (main checkout's vendor, run on the touched files) passes; `scripts/check-sensitive.sh` clean; lefthook (sensitive-content, gitleaks) passed on both commits.
- The CONTRIBUTING bullet was checked by script: all 17 required names present and resolving to a class or trait under `app/`, and the five required phrases present.
- PHPStan could not run (larastan is not installed in the worktree).

**Not run here, to run after the merge of wave 4 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/KnowledgeArticleImagesRelationManagerTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php tests/Feature/Repo/RepositoryFilesTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Audit` (Partner matrix declaration), then the full suite and `ddev exec vendor/bin/phpstan analyse --memory-limit=1G`.

Points worth watching in that run (tests written without being able to execute them): the exact error key and message path of `assertHasTableActionErrors(['reason' => ['required']])` and `$page->errors()` for a table action; the `max` rule key for the over-long reason; `assertTableColumnFormattedStateSet('size_bytes', '2 KB', ...)` against the real `humanSize()` output; `getSchema('mountedActionSchema0')` on a mounted table action; `Storage::set()` with a Mockery double for the failure case (same approach as `DeleteImageForGoodTest`).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-37 thumbnail is the absolute route, table test against a fictional bucket host; T-20-38 AdminOnly rule plus boot abort, view screen 403, Partner matrix; T-20-39 fixed notification texts, history row carries the label only).

## Self-Check: PASSED

- Files exist: KnowledgeArticleImagesRelationManager.php, KnowledgeArticleImagesRelationManagerTest.php, this SUMMARY.
- Commits 048dd473 and e705b2a6 are ancestors of HEAD.
