---
phase: 19-folders-tags-and-version-history
plan: 07
subsystem: knowledge
tags: [filament, relation-manager, partner-isolation, knowledge-tab]
requires:
  - phase: 19-folders-tags-and-version-history/19-01
    provides: HasTags on KnowledgeArticle
  - phase: 19-folders-tags-and-version-history/19-02
    provides: knowledge.links.tab.* and knowledge.columns.project copy, KnowledgeFixtures::taggedArticle()
provides:
  - Client::knowledgeArticles() and Project::knowledgeArticles() HasMany relations
  - ClientKnowledgeRelationManager and ProjectKnowledgeRelationManager (Admin-only Znalosti tabs)
  - PartnerMatrix rows for both tabs
affects: [19-10]
tech-stack:
  added: []
  patterns:
    - archived switch applied in the query scope of a relation manager table, like the article resource list
    - plain link Action (not CreateAction) so the tab stays usable on read-only view pages
key-files:
  created:
    - app/Filament/Resources/ClientResource/RelationManagers/ClientKnowledgeRelationManager.php
    - app/Filament/Resources/ProjectResource/RelationManagers/ProjectKnowledgeRelationManager.php
    - tests/Feature/Knowledge/KnowledgeTabsTest.php
    - tests/Isolation/KnowledgeTabsTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Projects/Models/Project.php
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ProjectResource.php
    - tests/Support/PartnerMatrix.php
key-decisions:
  - "R-19-07-1..4 implemented as written: HasMany relations, final Admin-only relation managers with a fixed select (no body_md), registration after the documents tab, matrix rows with partner false"
requirements-completed: [ORG-04]
duration: 25min
completed: 2026-11-26
status: complete
actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: d658fbaf9d0918c2b3ce2f34e82680be0b4b9940
plan_head_after: 94bd6680acfa5b0c74982feae9ba38cd6e81835a
commits: 3
---

# Phase 19 Plan 07: Knowledge tabs on the client and project pages Summary

Admin-only "Znalosti" tabs on the client page (general articles of the client and of its projects) and on the project page (only that project's articles), with an archived switch, an add-article link that prefills client and project, per-tab empty states, and Partner refusal declared in the matrix and covered by an Isolation test.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: client page Znalosti tab (relation, relation manager, registration, Feature tests) | b2bcc1f0 |
| 2 | Project page tab; archived switch, Přidat článek prefill and empty state on both tabs | ed23e469 |
| 3 | PartnerMatrix rows and Isolation test (canViewForRecord, direct mount, client page canary) | 94bd6680 |

## Deviations from Plan

None - plan executed as written. Task 1 already contained the archived switch, header action and empty state in the client manager (R-19-07-2 describes them as one contract); Task 2 added their tests and the project manager.

## Verification status

Not run in the worktree (no DB, no vendor directory; project rules): all Pest runs (`KnowledgeTabsTest` Feature and Isolation, `tests/Feature/Clients`, `tests/Feature/Projects`, `tests/Arch/PanelRegistryTest.php`, `tests/Audit/AccessMatrixTest.php`) and Pint. They must run after the merge of wave 2, one Pest process at a time, never with `--parallel`.

Run in the worktree: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` clean, lefthook (sensitive-content, gitleaks) green on each commit, all grep acceptance criteria met.

Points to watch in the post-merge run (Filament testing API assumptions made without vendor access): `assertActionHasUrl(TestAction::make('addArticle')->table(), ...)` on a relation manager header action, `assertTableColumnStateSet` on the `sensitive` icon column, `assertSee` of the Czech copy in the empty state, and `toThrow(HttpException::class)` for the 403 abort in `bootEnforcesRelationManagerAccessRule` when a Partner mounts a manager directly.

## Known Stubs

None.

## Threat Flags

None. The tabs add no endpoint or auth path; T-19-25 (Partner seeing titles or tags) is mitigated by `#[AccessRule(Audience::AdminOnly)]` plus `EnforcesRelationManagerAccessRule`, the PartnerMatrix rows and the Isolation test; T-19-26 (bodies loaded) by the fixed select asserted with a query listener.

## Self-Check: PASSED

- All created files exist and parse; commits b2bcc1f0, ed23e469, 94bd6680 are on the branch.
