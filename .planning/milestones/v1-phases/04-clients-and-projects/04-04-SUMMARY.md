---
phase: 04-clients-and-projects
plan: 04
subsystem: isolation
tags: [spatie-tags, filament-tags-plugin, partner-scope, soft-delete, canary]

requires:
  - phase: 04-clients-and-projects
    provides: Project model with Partner scope, ProjectColumns, Partner "Moje projekty" resource, canary Project fixture (plans 04-02, 04-03)
provides:
  - TagType enum (client, project) as the typed tag vocabulary
  - Tag model with a real Partner constraint (project-type tags of visible own projects only)
  - Project HasTags with a soft-delete detach guard (archive keeps tags, force delete detaches)
  - Tags column and entry on the Partner project list and detail (type pinned to project)
  - Canary Tag fixture re-pointed to a project-type tag on the canary Project
affects: [04-08 admin project resource, 04-clients tags, phase 5 tasks, CONTRIBUTING D-07 note in 04-21]

actuals:
  tokens: 6200
  tasks: 2
  commits: 2

plan_head_before: 378bbe7563a43f78cb08bb6c301e2c46c2972a36
plan_head_after: abeb498dac3f9feb0a47c9b455ed305344ed5479

tech-stack:
  added: [filament/spatie-laravel-tags-plugin ^5.10 (v5.10.0, MIT)]
  patterns:
    - "Partner constraint of a morph-linked model selects ids through the owning model's own scoped query (Project::query()), so visibility is defined in one place"
    - "Trait method aliased and overridden on the model to neutralise a package listener on soft delete"

key-files:
  created:
    - app/Domain/Shared/Tags/TagType.php
    - tests/Isolation/PartnerTagVisibilityTest.php
  modified:
    - app/Domain/Shared/Models/Tag.php
    - app/Domain/Projects/Models/Project.php
    - app/Filament/Support/ProjectColumns.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/DeniedModelsTest.php
    - tests/Feature/Projects/PartnerProjectResourceTest.php
    - composer.json
    - composer.lock

key-decisions:
  - "Tag approach A: Tag swaps DeniesPartners for IsolatesPartners with constrainForPartner; the policy stays AdminOnlyPolicy, so a Partner has no tag screen"
  - "Archive of a project keeps its taggables rows via a detachTags override; only a force delete detaches"

patterns-established:
  - "Typed tags: every tag input, column and entry passes TagType::Project->value; an untyped read would show every type"
  - "Taggables subquery uses its own alias (partner_tg) because relation queries already join taggables"

requirements-completed: [PR-04, PR-01]

coverage:
  - id: D1
    description: "A Partner sees the project-type tag of the own visible project and no other tag (client-type, untyped, other-client, hidden or archived project)"
    requirement: PR-04
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerTagVisibilityTest.php (13 tests)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "Partner project list and detail render the own project tags and never a tag of client B or a client-type tag"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#shows the project tags of the own project on the list and the detail and never a tag of client B"
        status: pass
    human_judgment: false
  - id: D3
    description: "Archiving a project keeps its taggables rows and a restore shows them again; only force delete detaches"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerTagVisibilityTest.php#keeps the taggables rows when a project is archived and shows the tags again after the restore"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerTagVisibilityTest.php#detaches the tags only when a project is force deleted"
        status: pass
    human_judgment: false
  - id: D4
    description: "Visual appearance of the tag badges on the Partner project list and detail"
    verification: []
    human_judgment: true
    rationale: "Badge rendering and placement are a visual judgment; tests assert only that the tag names are present or absent"

duration: 8 min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 04: Partner Project Tags Summary

**Partners see project-type tags of their own visible projects through a real `Tag` Partner constraint built on `Project::query()`, with a soft-delete guard so archiving a project no longer erases its tags.**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-08T12:51:44Z
- **Completed:** 2026-10-08T12:59:02Z
- **Tasks:** 2
- **Files modified:** 11 (2 created, 9 modified)

## Accomplishments
- `filament/spatie-laravel-tags-plugin` v5.10.0 installed (Approved in the research audit; `composer check-licenses` reports all 210 packages allowed).
- `Tag` no longer denies Partners outright: `constrainForPartner()` allows `type = 'project'` tags that have a `taggables` row (alias `partner_tg`, morph type `project`) pointing at an id returned by `Project::query()`, so the Project scope (own client, client-visible, not archived, client not archived) is the single place that decides visibility.
- `ProjectColumns` shows a typed `SpatieTagsColumn` and `SpatieTagsEntry`; `tags` is pinned in both Partner name lists.
- `Project` carries `HasTags` and a `detachTags` override that skips the package `deleted` listener for a soft delete (research Pitfall 2).
- Canary `Tag` fixture now attaches a project-type tag carrying the canary to the canary `Project`; the registry test and route walk stay green.
- Full suite 1099 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer - Partner sees the own visible project tag through a real Tag constraint** - `170a756` (feat)
2. **Task 2: Full Partner tag visibility matrix and soft-delete detach guard** - `abeb498` (feat; RED run showed the archive/restore and force-delete tests failing before the guard)

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `app/Domain/Shared/Tags/TagType.php` - typed tag vocabulary (client, project)
- `app/Domain/Shared/Models/Tag.php` - IsolatesPartners plus the project-tag Partner constraint
- `app/Domain/Projects/Models/Project.php` - HasTags and the soft-delete detach guard
- `app/Filament/Support/ProjectColumns.php` - typed tags column and entry, `tags` in the pinned names
- `lang/cs/kokpit.php` - label "Štítky" for `kokpit.projects.fields.tags`
- `tests/Isolation/PartnerTagVisibilityTest.php` - 13 tests: own, other client, client-type, untyped, unattached, hidden, archived, archived client, shared tag, Admin, no-client, policy, archive/restore/force delete
- `tests/Support/CanaryRegistry.php`, `tests/Isolation/DeniedModelsTest.php`, `tests/Feature/Projects/PartnerProjectResourceTest.php` - fixture re-pointed, docblocks updated, list and detail tag test
- `composer.json`, `composer.lock` - new dependency

## Decisions Made
- Followed research Pattern 2 (approach A) exactly; the policy stays `AdminOnlyPolicy` so tag names reach a Partner only through the project resource.
- A tag shared by a hidden and a visible project of the same client is shown through the visible project (the EXISTS matches any visible link); no other tag type or attachment target ever matches.

## Deviations from Plan

None - plan executed exactly as written. Minor additions inside the planned files: the `kokpit.projects.fields.tags` Czech label in `lang/cs/kokpit.php` (required by the column label, not named in `files_modified`), and two extra tests (shared tag, archived client).

**Total deviations:** 0 auto-fixed.
**Impact on plan:** none.

## Issues Encountered
- A first Pest run after creating the test file printed errors from a stale file view in DDEV and passed on the immediate re-run with identical content; no code change was needed.
- Running Pint with the explicit `lang` path rewrote the generated `lang/cs` files (they are excluded from the default Pint config); those files were restored with a per-file `git checkout` before committing.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; T-04-07 is mitigated and tested, T-04-08 accepted by D-07, T-04-SC mitigated by the audit and `composer check-licenses`.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Plan 04-05 onward can attach `TagType::Client` tags to clients; `Client` needs its own `HasTags` plus the same detach guard (research Pattern 2) when it gets the tags input.
- The Admin project resource (04-08) should reuse the plugin input with `->type(TagType::Project->value)`.

## Self-Check: PASSED

- Created files exist: `app/Domain/Shared/Tags/TagType.php`, `tests/Isolation/PartnerTagVisibilityTest.php` found.
- Commits `170a756` and `abeb498` are ancestors of HEAD.
- Acceptance greps (composer.json plugin, `use IsolatesPartners`, `partner_tg`, `Project::query()`, `TagType::Project`, `detachTagsFromTrait`, `isForceDeleting`, `forceDelete`, `restore`) pass; full Pest suite, Pint and PHPStan green.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
