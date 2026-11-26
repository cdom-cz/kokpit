---
phase: 19-folders-tags-and-version-history
plan: 04
subsystem: knowledge
tags: [save-path, stale-token, redaction, classification, tags, restore-marker]
requires:
  - "19-01: redaction_no column, HasTags on KnowledgeArticle, restored_from_version_no column"
  - "19-02: copy keys (knowledge.links.*, knowledge.tags.errors.*, stale_redacted_*), KnowledgeFixtures helpers"
provides:
  - "SaveArticle::update(actor, article, input, expectedVersion, expectedRedactionNo, acknowledge, restoredFromVersionNo)"
  - "StaleArticle::$redactionChanged"
  - "App\\Domain\\Knowledge\\Actions\\ArticleClassification (from(), MAX_TAGS, MAX_TAG_LENGTH)"
  - "ArticleInput::$classification (null keeps the stored classification)"
  - "SaveOutcome::ClassificationChanged"
  - "activity event version_restored with from_version_no and version_no"
  - "EditKnowledgeArticle::$loadedRedactionNo and the redacted-stale danger notice"
affects: [19-09 restore and redact Actions, 19-10 editor classification fields, 19-11 restore modal, 19-12 caller updates]
tech-stack:
  added: []
  patterns:
    - "required stale token without default, proven by an Arch rule"
    - "classification and tag sync written after the stale checks and guard decision inside the one locked transaction"
key-files:
  created:
    - app/Domain/Knowledge/Actions/ArticleClassification.php
    - tests/Feature/Knowledge/SaveArticleTokensTest.php
    - tests/Feature/Knowledge/SaveArticleClassificationTest.php
  modified:
    - app/Domain/Knowledge/Actions/ArticleInput.php
    - app/Domain/Knowledge/Actions/SaveOutcome.php
    - app/Domain/Knowledge/Actions/SaveArticle.php
    - app/Domain/Knowledge/Exceptions/StaleArticle.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
    - tests/Arch/KnowledgeSeamTest.php
decisions:
  - "R-19-04-1 to R-19-04-8 implemented as planned (signature, StaleArticle, ArticleClassification, writing order, outcome, restore marker, edit screen, Arch rule)"
  - "A non-string tag entry in ArticleClassification::from() is an InvalidArgumentException (a caller error, a form can never produce it), not a field error"
  - "ArticleClassification lowercases UUID ids so the comparison with stored keys is exact"
  - "The edit screen shows the existing classification_changed notification for the new outcome (success status), so the outcome match stays exhaustive once 19-10 sends a classification"
metrics:
  tasks: 3
  completed: 2026-11-26
status: complete
commits: 3
plan_head_before: d658fbaf9d0918c2b3ce2f34e82680be0b4b9940
plan_head_after: ec24a09c0f6f2e4d841bda0d9376e38feaea1b64
actuals:
  tokens: 17000
  tasks: 3
  commits: 3
---

# Phase 19 Plan 04: Redaction token, classification and restore marker in the one save path Summary

`SaveArticle::update()` now requires the redaction number an editor was opened with (D-13), writes folder, client, project and knowledge tags atomically with the content (D-04, D-06), and records a restore on the new version and in the activity log (D-11, VER-03).

## What was built

- **Redaction stale token (Task 1).** `update()` takes `int $expectedRedactionNo` with no default. Under the row lock, a different `version_no` throws `StaleArticle($current)`; only then a different `redaction_no` throws `StaleArticle($current, redactionChanged: true)`. `StaleArticle` gains `$redactionChanged`; the message stays fixed and content-free. `EditKnowledgeArticle` keeps a locked `$loadedRedactionNo` set once in `mount()`, passes it to `update()`, and for a redaction refusal sends a persistent danger notice (`stale_redacted_title` / `stale_redacted_body`) with the `load_current` link; a plain stale version keeps the warning notice. Both halt, so the typed text stays and a second press is refused again. An Arch rule asserts the parameter has no default.
- **Classification (Task 2).** `ArticleClassification::from()` turns empty or non-string ids into null, rejects non-UUID ids as field errors (`folder_id`, `client_id`, `project_id`), requires a client for a project, and cleans the tags (trim, drop empties, case-insensitive de-duplication keeping the first spelling, 40 characters and 20 tags at most, NUL refused). `ArticleInput` carries it as `$classification` (null keeps the stored one). In `SaveArticle`, existence checks (folder; client and project with `withTrashed()`; project must belong to the client) run right after the stale checks; the three ids and the knowledge tag sync are written only on the success paths. A classification-only change returns `SaveOutcome::ClassificationChanged` with no version and an untouched `edited_at`; a content change writes content and classification in one `forceFill` and exactly one version. Tags go through `syncTagsWithType(..., 'knowledge')` after mapping each name to the stored spelling of an existing knowledge tag, so client, project and task tags are never touched. `create()` accepts a classification too.
- **Restore marker (Task 3).** `update(..., restoredFromVersionNo)` writes `restored_from_version_no` on the new version row and one `version_restored` activity row with `from_version_no` and `version_no` only. Restoring text equal to the current text returns Unchanged with no version and no row.

## Tests written (not run in the worktree)

- `tests/Feature/Knowledge/SaveArticleTokensTest.php`: redaction refusal at the Action and on the edit screen (danger notice, typed text kept, repeat refused, screen opened after the bump saves), version-before-redaction ordering, restore marker, identical-text restore, null marker for other saves, ambiguous restore with acknowledgment and no content in the log; every test runs under a live log capture.
- `tests/Feature/Knowledge/SaveArticleClassificationTest.php`: `ArticleClassification::from()` rules, classification-only save, combined save, keep-when-null, refused saves (stale version, stale redaction, blocking and ambiguous secret, archived) leaving ids and tags unchanged, project of another client, missing folder or client, archived client and project staying linked, tag spelling reuse, tag removal, `create()` with classification, activity rows without tag names.
- `tests/Arch/KnowledgeSeamTest.php`: no default for `expectedRedactionNo`.

## Verification status

Worktree only (no vendor, no database): `php -l` clean on every touched file, the plan's greps pass (`int $expectedRedactionNo,` present and never with a default, `redactionChanged: true` once, `syncTagsWithType(` present, `TagType::Knowledge->value` three times, `MAX_TAGS = 20`, `->event('version_restored')`, one `'restored_from_version_no' =>`, one `'from_version_no' =>`), and `scripts/check-sensitive.sh` is clean on the new tests. The Pest runs of every task are NOT run in the worktree. They belong after the wave 2 merge, one process at a time and without `--parallel`:

1. `ddev exec php artisan migrate`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge tests/Arch/KnowledgeSeamTest.php tests/Isolation/KnowledgeVisibilityTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` alone

The existing callers of `update()` (`SaveArticleTest`, `EditKnowledgeArticleTest`, the concurrency worker) still use the old signature in this branch; plan 19-12 of the same wave updates them (`coupling_justified`), so those files fail until both plans are merged.

## Deviations from Plan

None - plan executed as written. Two small judgement calls are listed under decisions (non-string tag entry, lowercased ids), and the `classification_changed` notification case on the edit screen was added so the new outcome has the notice 19-02 provided instead of falling into the default "saved as version" text.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; the plan's threat model (T-19-11 to T-19-15) is covered by the required redaction token with an Arch rule, UUID and existence checks, the guard-then-write order, knowledge-only tag sync and the tag limits.

## Self-Check: PASSED

- Files exist: ArticleClassification.php, SaveArticleTokensTest.php, SaveArticleClassificationTest.php (created); the six modified files present.
- Commits exist in HEAD history: 88d27aa1 (redaction token), 8b2362f3 (classification), ec24a09c (restore marker).
