---
phase: 18-knowledge-articles-and-secret-guard
plan: 02
subsystem: testing
tags: [authorization, partner-isolation, canary, arch-tests, knowledge]

requires:
  - phase: 18-knowledge-articles-and-secret-guard
    provides: "18-01 models KnowledgeArticle, KnowledgeArticleVersion, KnowledgeFolder (same wave, coded against its contract)"
provides:
  - "AdminOnlyPolicy registered for the three knowledge models in AccessServiceProvider"
  - "TagType::Knowledge in the tag vocabulary"
  - "Tests\\Support\\KnowledgeFixtures (article, archived, folder) for every later test of the phase"
  - "Canary fixtures, PartnerMatrix admin-only rows and ChannelMatrix rows for the three models"
  - "tests/Isolation/KnowledgeVisibilityTest.php: zero Partner rows, denied abilities, invisible knowledge tag"
affects: [18-05, 18-07, 18-08, 18-09, 18-10, phase-19, phase-23]

actuals:
  tokens: 6800
  tasks: 2
  commits: 2
plan_head_before: 37d02d02ce17e0197662cf354a108fc8000e35ab
commits: 2
plan_head_after: 52627821aa61e7f1c3415d4320940fdbd18ea950

tech-stack:
  added: []
  patterns:
    - "Knowledge models are deny-all (DeniesPartners) plus AdminOnlyPolicy until Phase 23 opens shared articles"
    - "Shared test rows come from KnowledgeFixtures inside runAsSystem, never from per-test inserts"

key-files:
  created:
    - tests/Support/KnowledgeFixtures.php
    - tests/Isolation/KnowledgeVisibilityTest.php
  modified:
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Tags/TagType.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/ChannelMatrix.php

key-decisions:
  - "R-18-02-1: Gate::policy(AdminOnlyPolicy) for KnowledgeArticle, KnowledgeArticleVersion and KnowledgeFolder, after the DocumentArchive registration"
  - "R-18-02-2: TagType::Knowledge; Tag::constrainForPartner() stays unchanged (project tags only)"
  - "The article activity cell of ChannelMatrix is applicable (self::NONE) because KnowledgeArticle is a logging model; the version and folder cells are not applicable"

patterns-established:
  - "Declare every new model in ModelDeclarationTest, ActivityAllowlistTest (if logging), CanaryRegistry(+Test), PartnerMatrix::adminOnlyModels() and ChannelMatrix::entities() in the same wave as the model"

requirements-completed: [KB-01, KB-02]

coverage:
  - id: D1
    description: "A Partner (with or without a client), a user without a role and a guest read zero rows of the three knowledge models; the Admin reads all"
    requirement: "KB-01"
    verification: []
    human_judgment: true
    rationale: "Pest runs need DDEV on the merged HEAD branch; the worktree has no database. Verified only with php -l and greps here; post-merge run pending."
  - id: D2
    description: "Every ability on the three models is denied to a Partner and granted to the Admin through AdminOnlyPolicy"
    requirement: "KB-01"
    verification: []
    human_judgment: true
    rationale: "Post-merge Pest run pending (no database in the worktree)."
  - id: D3
    description: "The three models are declared in the Arch, Isolation and Audit registries; knowledge tags never reach a Partner"
    requirement: "KB-02"
    verification: []
    human_judgment: true
    rationale: "Post-merge Pest run and Audit suite pending (no database in the worktree)."

duration: 20min
completed: 2026-11-25
status: complete
---

# Phase 18 Plan 02: Knowledge Partner Seam and Test Declarations Summary

**Admin-only policies for the three knowledge models, `TagType::Knowledge`, shared `KnowledgeFixtures`, and declarations of the models in the Arch, canary, Partner and channel registries, proved by a new KnowledgeVisibilityTest.**

## Performance

- **Duration:** about 20 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files modified:** 10 (2 created, 8 modified)

## Accomplishments

- `AccessServiceProvider` registers `AdminOnlyPolicy` for `KnowledgeArticle`, `KnowledgeArticleVersion` and `KnowledgeFolder`, so a Partner is granted no ability on them (R-18-02-1).
- `KnowledgeFixtures::article()`, `archived()` and `folder()` write rows inside `runAsSystem`, with one version row per number up to `version_no`; fictional text only (R-18-02-5).
- `KnowledgeVisibilityTest` proves zero rows (archived included) for a Partner with a client, a Partner of the article's own client, a Partner without a client, a user without a role and a guest, denied abilities (viewAny, create, view, update, delete, restore, forceDelete), the registered policy class, and an invisible knowledge tag.
- `ModelDeclarationTest`, `ActivityAllowlistTest` (KnowledgeArticle only, sorted position between InvoicePayment and CardPayment), `CanaryRegistryTest`, `CanaryRegistry` (three canary fixtures, version after article), `PartnerMatrix::adminOnlyModels()` and `ChannelMatrix::entities()` declare the models (R-18-02-3, R-18-02-4).
- `TagType::Knowledge` added; the docblock states a knowledge tag is Admin only (R-18-02-2).

## Task Commits

1. **Task 1 (tracer): Admin reads, Partner reads nothing and may do nothing** - `eaee0c0` (feat)
2. **Task 2: canary harness, matrices and knowledge tag type** - `5262782` (test)

## Deviations from Plan

None - plan executed exactly as written. Small additions inside the plan's scope: the isolation test also covers a Partner of the article's own client, the relation `versions()` and the registered policy class.

## Verification

- Worktree (the only checks the project rules allow here): `php -l` clean on all touched PHP files; every acceptance grep of both tasks passes (policy registrations 3, ModelDeclarationTest Knowledge lines 7, `KnowledgeFixtures::article(` 1, `runAsSystem` 3, canary fixtures 3, CanaryRegistryTest entries 3, PartnerMatrix rows 3, ChannelMatrix rows 3, article activity cell not `activityNa` 0, logging-list position present, `case Knowledge` present); `scripts/check-sensitive.sh` clean on both commits; lefthook hook (sensitive-content, gitleaks) passed on both commits.
- NOT run, by design (CLAUDE.md worktree rule, no `vendor/` or database here): the Pest commands of both tasks and the plan gate (`ddev exec php artisan migrate`, then Arch, Isolation, Feature/Operations, Audit suite, Pint, PHPStan). They run after the wave merge together with 18-01. Never `--parallel`.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema change; the plan only closes access (T-18-06 to T-18-09 mitigated in test and policy declarations).

## Issues Encountered

None.

## Next Phase Readiness

18-05, 18-07, 18-08, 18-09 and 18-10 can use `KnowledgeFixtures`. The panel rows of the screens (PartnerMatrix panel rows, PanelInventory, RouteWalkTest) arrive with 18-07 and 18-08.

## Self-Check: PASSED

- FOUND: tests/Support/KnowledgeFixtures.php, tests/Isolation/KnowledgeVisibilityTest.php
- FOUND commits: eaee0c0, 5262782
