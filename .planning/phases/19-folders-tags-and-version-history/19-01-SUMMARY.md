---
phase: 19-folders-tags-and-version-history
plan: 01
subsystem: database
tags: [postgres, trigger, immutability, redaction, spatie-tags, laravel]
status: complete

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: knowledge_articles, knowledge_article_versions, the version immutability function and trigger
provides:
  - restored_from_version_no on versions and redaction_no on articles
  - setting-gated variant of kokpit_refuse_knowledge_version_change() (body-only rewrite while kokpit.redacting_knowledge_version is on)
  - append-only knowledge_version_redactions (length of the reason only, never the text)
  - KnowledgeVersionRedaction model, RedactionScope enum, KnowledgeArticleVersion::redactions()
  - KnowledgeArticle carries knowledge tags (HasTags, archive keeps tags)
affects: [19-02, 19-03, 19-04, 19-09, version-history, redaction, tags]

plan_head_before: 3425ad9dd39e0175690942645993119e6df41755
plan_head_after: 1d82a14c2650db3ded1416498eb08ab0b1b6a913

actuals:
  tokens: 9100
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Transaction-local setting gate in a trigger function (set_config is_local true), compared as to_jsonb(NEW) - 'body_md' = to_jsonb(OLD) - 'body_md'"
    - "Soft-delete-aware detachTags alias on a HasTags model (as Task)"

key-files:
  created:
    - database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php
    - app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php
    - app/Domain/Knowledge/Enums/RedactionScope.php
    - tests/Feature/Knowledge/KnowledgeArticleTagsTest.php
  modified:
    - app/Domain/Knowledge/Models/KnowledgeArticle.php
    - app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
    - tests/Feature/Schema/KnowledgeTablesTest.php

key-decisions:
  - "R-19-01-1: the version trigger function keeps its name and admits only an UPDATE whose row minus body_md is unchanged, while the setting is exactly 'on'"
  - "R-19-01-2: restored_from_version_no has a CHECK but no foreign key (schema rule R1 requires uuid FK columns)"
  - "R-19-01-3: knowledge_version_redactions stores reason_length (1 to 500) and has no column for the reason text (OD-1)"
  - "R-19-01-6: down() throws while a redaction row or a restore marker exists"

requirements-completed: [VER-03, VER-05, ORG-03]

duration: 25min
completed: 2026-11-26
---

# Phase 19 Plan 01: Knowledge organisation and redaction schema Summary

One migration lays down the restore marker, the redaction stale token and the append-only redaction record, replaces the version immutability function with a setting-gated body-only variant, and gives `KnowledgeArticle` typed knowledge tags that survive archiving.

## Accomplishments

- Migration `2026_11_26_000100_add_knowledge_organisation_and_redaction.php`:
  - `knowledge_article_versions.restored_from_version_no` (NULL, CHECK 1 <= n < version_no); existing rows stay NULL.
  - `knowledge_articles.redaction_no` (NOT NULL, default 0, CHECK >= 0).
  - `kokpit_refuse_knowledge_version_change()` replaced in place (trigger and truncate guard untouched): admits an UPDATE only when `kokpit.redacting_knowledge_version` is exactly `on` and the row minus `body_md` is identical; everything else, DELETE and TRUNCATE included, still raises KP001.
  - `knowledge_version_redactions` (version, actor, scope lines/whole, reason_length 1 to 500, created_at), FK RESTRICT to versions and users, its own KP001 row trigger and truncate guard, no column for the reason text.
  - `down()` throws a `RuntimeException` while a redaction row or a restore marker exists, otherwise restores the Phase 18 function verbatim.
- `KnowledgeVersionRedaction` (final, `DeniesPartners`, `PartnerIsolated`, no fillable, no `updated_at`), `RedactionScope` enum, `KnowledgeArticleVersion::redactions()` and the new casts and docblocks.
- `KnowledgeArticle` uses `HasTags` with a private alias and a public soft-delete-aware `detachTags()`, so archiving never strips tags.
- Tests (written, not run in the worktree): schema cases in `KnowledgeTablesTest.php` (gate on/off, every other column, DELETE and TRUNCATE under the setting, other setting values, restore marker bounds, redaction_no, redaction table constraints, KP001 on the redaction table, exact column list, models) and `KnowledgeArticleTagsTest.php` (type separation, archive and restore, `detachTags` on archived, sync of one type, `withAllTags`, no tag name in activity rows).

## Deviations from Plan

None - plan executed as written. One note: no morph-class assertion for `KnowledgeVersionRedaction` is in the schema test, because the morph map entry is declared by plan 19-02 (same wave).

## Verification Status

Not run in the worktree (project rule: no migrations, no DB-backed tests in a worktree; the host has no database; `vendor/` is absent, so Pint and Pest were not available either):

- `php -l` clean on every touched PHP file.
- Plan greps pass: two `CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change()` (up and down), the setting name and the jsonb comparison present, `Immutability::truncateGuardSql('knowledge_version_redactions')` once, `throw new RuntimeException` present, `use HasTags` once, `public function detachTags(` once, `set_config` at least twice in the schema test, `tagsWithType('knowledge')` in the tag test.
- `scripts/check-sensitive.sh` clean on the touched files; lefthook (sensitive-content, gitleaks) passed on both commits.

To run after wave 1 is merged into the HEAD branch, in DDEV, one process at a time, never `--parallel`:

```
ddev exec php artisan migrate
ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Schema/KnowledgeTablesTest.php tests/Feature/Schema/SchemaConventionsTest.php
ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/KnowledgeArticleTagsTest.php tests/Isolation/KnowledgeVisibilityTest.php tests/Arch/ActivityAllowlistTest.php
```

`ModelDeclarationTest`, `CanaryRegistryTest` and the Audit suite stay red until plan 19-02 (access provider, morph map, canary, matrix for `KnowledgeVersionRedaction`) is merged too.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-19-01 to T-19-04, T-19-49, T-19-50).

## Commits

- 5d89b4c6: feat(knowledge): add restore marker, redaction record and gated version rewrite
- 1d82a14c: feat(knowledge): add knowledge tags to articles that survive archiving

## Self-Check: PASSED

- FOUND: database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php
- FOUND: app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php
- FOUND: app/Domain/Knowledge/Enums/RedactionScope.php
- FOUND: tests/Feature/Knowledge/KnowledgeArticleTagsTest.php
- FOUND commits 5d89b4c6 and 1d82a14c in HEAD
