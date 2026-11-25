---
phase: 18-knowledge-articles-and-secret-guard
plan: 01
subsystem: database
tags: [postgres, triggers, laravel, eloquent, knowledge-base]

requires:
  - phase: 17-signal
    provides: morph map convention, KokpitModel base, Immutability truncate guard, RawSql test helper
provides:
  - knowledge_articles, knowledge_article_versions and knowledge_folders tables with DB-enforced integrity
  - SQLSTATE KP003 folder tree guard (self-parent, cycle, depth over KnowledgeFolder::MAX_DEPTH)
  - ArticleKind enum, KnowledgeArticle, KnowledgeArticleVersion and KnowledgeFolder models
  - morph aliases knowledge_folder, knowledge_article, knowledge_article_version
affects: [18-02, 18-05, 18-08, phase-19, phase-21, phase-23]

actuals:
  tokens: 12500
  tasks: 2
  commits: 2
plan_head_before: 37d02d02ce17e0197662cf354a108fc8000e35ab
plan_head_after: 438b411e44d0c7440cd116c13fd18e35a63aaa87

tech-stack:
  added: []
  patterns:
    - "Append-only table: purpose-built KP001 row trigger plus Immutability truncate guard"
    - "Tree guard under a transaction advisory lock with ancestor and subtree-height recursive CTEs (KP003)"
    - "Cap constants shared between model and migration (KnowledgeFolder::MAX_DEPTH interpolated into the trigger)"

key-files:
  created:
    - database/migrations/2026_11_25_000100_create_knowledge_articles_table.php
    - database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php
    - database/migrations/2026_11_25_000300_create_knowledge_folders_table.php
    - app/Domain/Knowledge/Enums/ArticleKind.php
    - app/Domain/Knowledge/Models/KnowledgeArticle.php
    - app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
    - app/Domain/Knowledge/Models/KnowledgeFolder.php
    - tests/Feature/Schema/KnowledgeTablesTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - lang/cs/enums.php

key-decisions:
  - "Kind names general, incident, incident_page, retrospective are fixed (A7); the kind never changes after insert (KP001 trigger)"
  - "Title cap 200 and body cap 100000 live as DB CHECKs and as KnowledgeArticle constants (A8)"
  - "No DEFERRABLE foreign key from the article to its current version row; SaveArticle keeps the pair in one transaction"
  - "Folder depth cap is 6, root = level 1; parent depth plus moved subtree height must not exceed it"

patterns-established:
  - "KP003 is the SQLSTATE of the folder tree guard next to KP001 (frozen rows) and KP002 (project key)"
  - "Activity log of KnowledgeArticle carries metadata only, never the title or the body"

requirements-completed: [KB-01, KB-07, VER-01]

coverage:
  - id: D1
    description: "Article, version and folder tables with named CHECKs, RESTRICT foreign keys and the kind freeze, version immutability and folder tree triggers"
    requirement: "VER-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/KnowledgeTablesTest.php"
        status: unknown
    human_judgment: false
  - id: D2
    description: "Models, ArticleKind enum with Czech labels and morph aliases"
    requirement: "KB-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/KnowledgeTablesTest.php#reads an article and its versions through the models"
        status: unknown
    human_judgment: false

duration: 25min
completed: 2026-11-25
status: complete
---

# Phase 18 Plan 01: Knowledge Data Model Summary

**Three knowledge tables (articles, append-only versions, folder tree) whose integrity is held by the database itself: named CHECKs, RESTRICT foreign keys, a kind freeze trigger, a KP001 version immutability trigger and a KP003 folder tree trigger under an advisory lock.**

## Performance

- **Duration:** about 25 min
- **Started:** 2026-11-25T12:05:00Z
- **Completed:** 2026-11-25T12:30:00Z
- **Tasks:** 2
- **Files modified:** 10 (8 created, 2 modified)

## Accomplishments

- `knowledge_articles` carries every rule of R-18-01-3: kind whitelist, incident sub-page parent pairing, title and body length caps, version number floor, folder only on general articles, composite project-client FK, and the three `client_visible` CHECKs. UPDATE of `kind` raises KP001.
- `knowledge_article_versions` is a full-snapshot history table: UPDATE, DELETE and TRUNCATE raise KP001, `(article_id, version_no)` is unique, RESTRICT FKs keep an article with versions from being hard deleted.
- `knowledge_folders` has case-insensitive sibling name uniqueness (including roots, via NULLS NOT DISTINCT) and a tree trigger that serialises moves with `pg_advisory_xact_lock` and raises KP003 for self-parent, cycle and depth over 6, counting the height of the moved subtree.
- Models, the `ArticleKind` enum with Czech labels and the three morph aliases exist for the later plans of the phase.
- `tests/Feature/Schema/KnowledgeTablesTest.php` proves each rule with raw SQL, one savepoint per refused statement.

## Task Commits

1. **Task 1: article and version tables with integrity guards (tracer)** - `519d170` (feat)
2. **Task 2: folder tree table with depth and cycle guard** - `438b411` (feat)

## Decisions Made

- Followed the rulings R-18-01-1 to R-18-01-8 as written; no deviation in the schema or the constants.
- `KnowledgeFolder` has `#[Fillable(['name'])]` only; `parent_id` stays out of mass assignment so a later Action sets it with `forceFill`.
- The root-folder branch of the tree trigger returns early after taking the lock: a subtree that was valid below its old parent cannot become too deep at the root.
- Task 1 leaves `KnowledgeArticle::folder()` out and Task 2 adds it with the folder model, as the plan prescribes, so the Task 1 commit has no reference to a class that does not exist yet.

## Deviations from Plan

None - plan executed as written.

## Issues Encountered

- The IDE inspections report "Unable to resolve table" for the raw `ALTER TABLE` strings of the migrations; this is a missing database connection in the editor, not a code problem (same for the existing migrations).
- A scratch PostgreSQL could not be started on the host (only the client libraries are installed), so the trigger SQL has not been executed here; see Verification Status.

## Verification Status

Worktree checks run and green: `php -l` on every touched PHP file, every grep of the acceptance criteria of both tasks, Pint (`--test`) on all touched files, `scripts/check-sensitive.sh` and the lefthook pre-commit hook (sensitive-content, gitleaks) on both commits.

NOT run, by design (CLAUDE.md, worktrees): `ddev exec php artisan migrate` and the Pest commands of both tasks. They run after the merge into the HEAD branch:

```
ddev exec php artisan migrate
ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Schema tests/Feature/Localisation tests/Arch
ddev exec vendor/bin/pint --test
ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

`tests/Arch` is green only together with plan 18-02 (policies and model declarations). The PostgreSQL trigger bodies (plpgsql, recursive CTEs, `NULLS NOT DISTINCT` index) were reviewed by hand against the research spike and have not yet been executed; the first migrate in DDEV is their first run.

## Known Stubs

None.

## Threat Flags

None - no new surface beyond the plan's threat model (T-18-01 to T-18-05 are mitigated by the triggers and CHECKs above).

## Self-Check: PASSED

- FOUND: database/migrations/2026_11_25_000100_create_knowledge_articles_table.php
- FOUND: database/migrations/2026_11_25_000200_create_knowledge_article_versions_table.php
- FOUND: database/migrations/2026_11_25_000300_create_knowledge_folders_table.php
- FOUND: app/Domain/Knowledge/Enums/ArticleKind.php
- FOUND: app/Domain/Knowledge/Models/KnowledgeArticle.php
- FOUND: app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
- FOUND: app/Domain/Knowledge/Models/KnowledgeFolder.php
- FOUND: tests/Feature/Schema/KnowledgeTablesTest.php
- FOUND commits: 519d170, 438b411
