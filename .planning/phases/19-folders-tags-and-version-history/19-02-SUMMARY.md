---
phase: 19-folders-tags-and-version-history
plan: 02
subsystem: knowledge
tags: [partner-isolation, canary, fixtures, i18n, czech-copy]
requires:
  - phase: 19-folders-tags-and-version-history/19-01
    provides: KnowledgeVersionRedaction model, restored_from_version_no column, HasTags on KnowledgeArticle (named here, created there; merge order irrelevant)
provides:
  - fail-closed declarations of KnowledgeVersionRedaction (policy, morph alias, arch list, canary, partner matrix, channel matrix)
  - KnowledgeFixtures::folderChain(), taggedArticle(), articleWithVersions()
  - the complete Phase 19 Czech copy under knowledge.folders/tags/links/versions/diff/restore/redact plus new columns, empty and notifications entries
  - activity labels version_restored and version_redacted
affects: [19-03, 19-04, 19-05, 19-06, 19-07, 19-08, 19-09, 19-10, 19-11, 19-12]
tech-stack:
  added: []
  patterns:
    - redaction record carries no text, so the canary needle is the id of the canary version (SignalSetting precedent)
    - dataset-driven language test pins every key of the copy contract
key-files:
  created: []
  modified:
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Database/MorphMap.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/ChannelMatrix.php
    - tests/Support/KnowledgeFixtures.php
    - lang/cs/knowledge.php
    - lang/cs/kokpit.php
    - tests/Feature/Knowledge/KnowledgeLangTest.php
key-decisions:
  - "R-19-02-1: KnowledgeVersionRedaction is Admin-only (AdminOnlyPolicy), alias knowledge_version_redaction, canary needle is the id of version 1 of the canary article"
  - "R-19-02-3: the copy table is the single source; later plans read keys by name and add none"
requirements-completed: [ORG-01, ORG-02, ORG-03, ORG-04, VER-02, VER-03, VER-04, VER-05]
duration: 25min
completed: 2026-11-26
status: complete
actuals:
  tokens: 30000
  tasks: 3
  commits: 3
plan_head_before: 3425ad9dd39e0175690942645993119e6df41755
plan_head_after: 916bf2ffd18c51038bb60f3981bccf9ebf2a77c6
commits: 3
---

# Phase 19 Plan 02: Redaction declarations, fixtures and Czech copy Summary

Fail-closed Partner declarations for the new `KnowledgeVersionRedaction` model, three shared knowledge fixtures (folder chains, tagged articles, chosen version histories) and the complete Czech copy of the phase (167 keys) pinned by a dataset-driven language test.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: redaction model closed to every Partner (policy, morph alias, arch list, canary fixture and needle, partner matrix, channel matrix) | db1b75ff |
| 2 | Fixtures `folderChain()`, `taggedArticle()`, `articleWithVersions()` | 47a5c5de |
| 3 | Phase 19 copy in `lang/cs/knowledge.php`, two activity labels in `lang/cs/kokpit.php`, extended `KnowledgeLangTest` | 916bf2ff |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ChannelMatrix row for the new model**
- **Found during:** Task 1
- **Issue:** The plan states "ChannelMatrix is unchanged", but `ChannelMatrixTest` ("declares every CanaryRegistry entity and only those") requires one `ChannelMatrix::entities()` row per `CanaryRegistry::fixtures()` entry. Adding the redaction fixture without the row would fail that audit after the merge.
- **Fix:** Added a `KnowledgeVersionRedaction::class` row to `tests/Support/ChannelMatrix.php`, closed on every channel (ui, search, selects, notifications, api = NONE; exports, activity, downloads not applicable), the same shape as the `KnowledgeFolder` row.
- **Files modified:** tests/Support/ChannelMatrix.php
- **Commit:** db1b75ff

**2. [Note] Acceptance grep count**
- `grep -cF "KnowledgeVersionRedaction::class =>" tests/Support/CanaryRegistry.php` prints 2, not 1: the plan requires both the `fixtures()` entry and the `needles()` case (R-19-02-1), and each matches the pattern. Intended, not a defect.

## Verification status

Not run in the worktree (no DB, no vendor directory; project rules): all Pest runs (`ModelDeclarationTest`, `CanaryRegistryTest`, `SchemaConventionsTest`, `PartnerActionsTest`, `AccessMatrixTest`, `ChannelMatrixTest`, `KnowledgeLangTest`, `ActivityViewsTest`) and Pint. They must run after the merge of wave 1 and `ddev exec php artisan migrate`, since the declarations name the model, columns and `HasTags` that 19-01 creates.

Run in the worktree: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` clean, lefthook (sensitive-content, gitleaks) green on each commit, all grep acceptance criteria met (except the note above). The copy file loads and the plural strings parse as expected.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or file access; the only security-relevant surface is the redaction model, which is closed to Partners (T-19-05 mitigated by policy, canary needle and matrix rows).

## Self-Check: PASSED

- Files modified exist and parse; commits db1b75ff, 47a5c5de, 916bf2ff are on the branch.
