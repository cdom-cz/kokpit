---
phase: 20-image-attachments
plan: 01
subsystem: knowledge
tags: [postgres, migration, eloquent, partner-isolation, images, schema]
status: complete

requires:
  - phase: 19-folders-tags-and-version-history
    provides: knowledge_article_versions, frozen-trigger conventions, KnowledgeVersionRedaction model shape
provides:
  - knowledge_article_images table (pending or bound, frozen-column trigger)
  - knowledge_article_version_images reference table with composite keys
  - KnowledgeArticleImage and KnowledgeArticleVersionImage models (admin-only, Partner-closed)
  - KnowledgeFixtures::image() and KnowledgeFixtures::reference()
affects: [20-02, 20-03, 20-06, 20-07, 20-09, 20-10]

tech-stack:
  added: []
  patterns:
    - "jsonb-minus freeze trigger (to_jsonb(NEW) - cols) so a later column is frozen by default"
    - "composite FK (child_id, article_id) to a UNIQUE (id, article_id) makes 'same article' a database fact"
    - "object key as a pure function of id and mime type (no user text in the key)"

key-files:
  created:
    - database/migrations/2026_11_27_000100_create_knowledge_article_images_tables.php
    - app/Domain/Knowledge/Models/KnowledgeArticleImage.php
    - app/Domain/Knowledge/Models/KnowledgeArticleVersionImage.php
  modified:
    - app/Domain/Knowledge/Models/KnowledgeArticle.php
    - app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Database/MorphMap.php
    - tests/Feature/Schema/KnowledgeTablesTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/ChannelMatrix.php
    - tests/Support/KnowledgeFixtures.php

key-decisions:
  - "Followed rulings R-20-01-1 to R-20-01-7 of the plan verbatim; no table or column deviates."
  - "The reference row's needle in the canary harness is the canary version id (it holds ids only), the image row's needle is the canary in file_name."

requirements-completed: [IMG-02, IMG-03]

plan_head_before: 8df70277797651f08d29980eecf394b528869a69
plan_head_after: 8a0573ff46e6028341ec067f4c7efba2d7c235bd
commits: 3

actuals:
  tokens: 14000
  tasks: 3
  commits: 3

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 01: Image and reference tables Summary

**Data model of Phase 20: a pending-or-bound image table with a jsonb freeze trigger, a reference table whose composite keys make a cross-article reference impossible, and both models closed to Partners in every registry.**

## What was built

- **Migration `2026_11_27_000100_create_knowledge_article_images_tables.php`**
  - `UNIQUE (id, article_id)` added to `knowledge_article_versions` (target of the composite FK; touches no row).
  - `knowledge_article_images`: NULL `article_id` = pending; named CHECKs on mime type (PNG, JPEG, WebP, GIF), width and height 1..2400, `size_bytes` 1..`32 * 1024 * 1024`, lowercase sha256 hex, file name length 1..255, `orphaned_at` only on a bound image; `UNIQUE (id, article_id)`; indexes on article, uploader and the partial pending index on `created_at`.
  - `kokpit_refuse_knowledge_image_change()` + `BEFORE UPDATE` trigger `knowledge_article_images_frozen`: raises KP001 unless the row differs from the old one in `article_id` and `orphaned_at` alone (jsonb compare, so later columns are frozen by default), and refuses any change of an already bound `article_id`. DELETE stays allowed.
  - `knowledge_article_version_images`: surrogate uuidv7 key, `UNIQUE (version_id, image_id)`, composite FKs `(version_id, article_id)` and `(image_id, article_id)`, both RESTRICT, index on `image_id`.
  - `down()` throws `RuntimeException("Rolling back would orphan stored knowledge images.")` while an image row exists.
- **Models** `KnowledgeArticleImage` (`STORAGE_DIRECTORY`, `extension()`, `storagePath()`, relations `article`, `uploader`, `references`) and `KnowledgeArticleVersionImage` (relations `version`, `image`), both `PartnerIsolated` + `DeniesPartners`, nothing fillable, no `updated_at`, not logged. `KnowledgeArticle::images()` and `KnowledgeArticleVersion::imageReferences()` added.
- **Registries:** admin-only `Gate::policy` for both models, morph aliases `knowledge_article_image` and `knowledge_article_version_image`, `ModelDeclarationTest` model and isolated lists, `PartnerMatrix::adminOnlyModels()`, `CanaryRegistry` fixtures and needles, `CanaryRegistryTest` expected list, `ChannelMatrix` rows (closed on every channel; image downloads cell `none`, to be probed by plan 20-07).
- **Fixtures:** `KnowledgeFixtures::image()` (GD-made 2 x 1 PNG, real width, height, size and sha256, optional object on `Storage::disk()`) and `KnowledgeFixtures::reference()`.
- **Schema tests:** 20 new cases in `tests/Feature/Schema/KnowledgeTablesTest.php` (describe `images and their references`) covering every CHECK, both FK kinds, the bind-once and frozen-column rules, the orphan stamp, RESTRICT delete, the cross-article refusals, `storagePath()` and the relations.

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: image and reference tables, models, schema tests | bf82fa93 |
| 2 | Policy, morph map, model declarations, Partner matrix | dfb95414 |
| 3 | Canary, channel and fixture rows | 8a0573ff |

## Deviations from Plan

None - plan executed exactly as written.

## Verification status

Worktree only (CLAUDE.md: no migrations, no full suite in a worktree, no `vendor/` here):

- `php -l` clean on every touched PHP file.
- All acceptance greps of the three tasks pass (composite image FK, versions unique constraint, trigger function name, `32 * 1024 * 1024` once, `RuntimeException`, `storagePath`, `images`, `imageReferences`, policy lines, morph alias, fixtures, matrix and canary entries).
- `scripts/check-sensitive.sh` clean on the touched files; the lefthook pre-commit hook (sensitive-content, gitleaks) passed on every commit.

**Not run here, to run after the merge of wave 1 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php artisan migrate`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Schema/KnowledgeTablesTest.php tests/Feature/Schema/SchemaConventionsTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Arch/ModelDeclarationTest.php tests/Isolation/CanaryRegistryTest.php tests/Isolation/KnowledgeVisibilityTest.php`
4. `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Audit`

The tracer gate (re-run of `<verify>` end to end before expansion) therefore happens at that point, not here. Points worth watching in that run: SchemaConventionsTest rules R1/R6/R7 against the new tables (named constraints, uuid FK columns, HasUuids), and the Audit suite's ChannelMatrix drivers against the two new canary rows.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-01 to T-20-04 are mitigated as specified).

## Self-Check: PASSED

- Files exist: migration, both models, SUMMARY.
- Commits bf82fa93, dfb95414, 8a0573ff are ancestors of HEAD.
