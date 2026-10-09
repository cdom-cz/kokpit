---
phase: 04-clients-and-projects
plan: 08
subsystem: ui
tags: [filament, livewire, spatie-tags, soft-delete, activitylog, partner-isolation]

requires:
  - phase: 04-clients-and-projects
    provides: CreateProject and UpdateProject Actions, ProjectInput, EstimateHours, activity allowlists (plans 04-06, 04-07)
  - phase: 04-clients-and-projects
    provides: ProjectColumns shared builders, project tags, PartnerProjectResource (plans 04-03, 04-04)
  - phase: 03-operations
    provides: ActivityHistoryRelationManager and the access rule traits
provides:
  - ProjectResource (Admin) with Projekt and Fakturace sections, archive, restore, trashed filter and no force delete
  - Create, Edit, View and List pages that write only through the domain Actions
  - RethrowsDomainValidation page trait (domain errors land next to their fields)
  - ProjectHistoryRelationManager (Admin only)
  - ProjectKeySuggester (pure, deterministic) and its form wiring
  - Route walk entry for the Admin-only projects resource
affects: [04-09 client resource, phase 5 tasks and key freeze, phase 8 work report]

actuals:
  tokens: 15000
  tasks: 2
  commits: 3
plan_head_before: 704faf3e975ac345491f7706aafb336bf401d801
plan_head_after: 2771c19c84efa24518b64932ec8a372aff8fd5f8

tech-stack:
  added: []
  patterns:
    - "Filament pages are thin adapters: handleRecordCreation/handleRecordUpdate call the domain Action inside withFormErrors(), which prefixes domain error keys with data."
    - "Soft-deleted records stay reachable: getEloquentQuery and getRecordRouteBindingEloquentQuery remove only SoftDeletingScope (argument form); TrashedFilter decides what the list shows"
    - "A hidden non-dehydrated key_touched flag stops a derived field from overwriting what the Admin typed"
    - "Select over a PHP enum returns the enum case in form state, so actionData() normalises cases to values before the Action"

key-files:
  created:
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/ProjectResource/Pages/ListProjects.php
    - app/Filament/Resources/ProjectResource/Pages/CreateProject.php
    - app/Filament/Resources/ProjectResource/Pages/EditProject.php
    - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
    - app/Filament/RelationManagers/ProjectHistoryRelationManager.php
    - app/Filament/Concerns/RethrowsDomainValidation.php
    - app/Domain/Projects/ProjectKeySuggester.php
    - tests/Feature/Projects/ProjectResourceTest.php
    - tests/Feature/Projects/ProjectKeyTest.php
    - tests/Unit/Projects/ProjectKeySuggesterTest.php
  modified:
    - lang/cs/kokpit.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "ProjectKeySuggester::suggest is a static method on a final class (pure, no model or database import); candidates are deduplicated and capped at 27 for a short word"
  - "Clearing a typed key resumes the suggestion (key_touched follows whether the key is non-empty)"
  - "An archived client's projects show the client name in the list through an eager load that removes only SoftDeletingScope"

patterns-established:
  - "ProjectResource::actionData() and fillBillingState() convert between flat form state and the Action data shape"
  - "Archive and restore actions are built by ProjectResource::archiveAction()/restoreAction() so the table and both pages share one Czech wording"

requirements-completed: [PR-01, PR-02, PR-03, PR-04]

coverage:
  - id: D1
    description: "Admin creates and edits a project with all fields, billing terms (decimal-comma rate, estimate in hours) and project tags through the form; the client is read-only after creation and status/priority switch freely"
    requirement: PR-03
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it creates a project with every field, its billing row and its project tags"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it loads the billing row into the edit form, keeps the client and switches status and priority freely"
        status: pass
    human_judgment: false
  - id: D2
    description: "Empty-edge and encoding-edge of PR-01: optional fields empty saves, empty name is a field error, Czech diacritics survive, the 255 name limit counts characters"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it keeps Czech diacritics exactly as typed and counts the 255 limit of the name in characters"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it refuses an empty name and an end date before the start date as field errors"
        status: pass
    human_judgment: false
  - id: D3
    description: "Archive and restore from the list and pages; archived projects open by URL; no force delete in table, bulk or page actions; archived clients are not offered in the picker"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it archives a project out of the default list, shows it under the trashed filter, opens it by URL and restores it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it offers no force delete in the table, the bulk actions or the page actions"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it offers only active clients in the client select"
        status: pass
    human_judgment: false
  - id: D4
    description: "Admin-only history relation manager shows allowlisted project changes; a Partner gets 403 on every Admin project route (also in the route walk)"
    requirement: PR-04
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it shows the project history to the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectResourceTest.php#it gives a Partner 403 on every Admin project route"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Key suggestion: transliterated initials, single-word prefix, PRJ fallback, fixed deterministic collision order over archived keys, null when all taken"
    requirement: PR-02
    verification:
      - kind: unit
        ref: "tests/Unit/Projects/ProjectKeySuggesterTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectKeyTest.php#it suggests the next free variant when the initials belong to an archived project"
        status: pass
    human_judgment: false
  - id: D6
    description: "Typed key is never overwritten; lower-case key stored upper-case; duplicate key (existing, archived, race path) is a field error on key"
    requirement: PR-02
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectKeyTest.php#it does not overwrite a key the Admin has typed when the name changes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectKeyTest.php#it shows a key taken between the form check and the save as a field error on key and leaves one row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectKeyTest.php#it stores a lower-case key in upper case"
        status: pass
    human_judgment: false
  - id: D7
    description: "Look and wording of the Czech project screens (helper texts, section layout, labels Archivovat and Obnovit)"
    verification: []
    human_judgment: true
    rationale: "Page tests assert behaviour and state, not visual layout or the adequacy of the Czech copy"

duration: 14min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 08: Admin Project Screens Summary

**Admin ProjectResource with Projekt and Fakturace sections written through the domain Actions, archive/restore without force delete, an Admin-only history tab, and a deterministic key suggester (transliterated initials, fixed collision order) wired to the name field**

## Performance

- **Duration:** 14 min
- **Started:** 2026-10-08T13:31:00Z
- **Completed:** 2026-10-08T13:45:00Z
- **Tasks:** 2
- **Files modified:** 13

## Accomplishments
- Admin creates, edits, views, archives and restores projects under "Projekty"; billing terms (type, hourly rate, fixed price, estimate in hours, internal note), client-visible toggle and project tags are all in the form; every write goes through CreateProject/UpdateProject.
- Domain `ValidationException` keys land next to their fields (`data.hourly_rate`, `data.key`, `data.client_id`) through the `RethrowsDomainValidation` trait.
- The client is chosen from active clients on create and shown read-only afterwards; archived projects are reachable by URL and under the trashed filter; no force-delete exists in table, bulk or page actions.
- `ProjectKeySuggester` gives a transliterated, deterministic key (NW, UCET, SSS, PRJ fallback, fixed variant order, archived keys counted) and fills the key on create until the Admin types one.
- The duplicate key is a field error from the form rule and from the database race path (message of the Action, proven in a test that inserts the competing row right after the form check).
- Route walk now lists `projects` as Admin-only; a Partner gets 403 on all its routes.

## Task Commits

1. **Task 1: Tracer - project resource, pages, history, archive/restore** - `26ede70` (feat)
2. **Task 2 RED: failing suggester unit tests** - `57abc5f` (test)
3. **Task 2 GREEN: suggester and form wiring, key tests** - `2771c19` (feat)

**Plan metadata:** the docs commit following this summary (docs: complete plan)

## Files Created/Modified
- `app/Filament/Resources/ProjectResource.php` - Admin resource: form, table, filters, archive/restore, state conversion helpers
- `app/Filament/Resources/ProjectResource/Pages/*.php` - List, Create, Edit, View pages; Create/Edit call the Actions
- `app/Filament/RelationManagers/ProjectHistoryRelationManager.php` - Admin-only history tab
- `app/Filament/Concerns/RethrowsDomainValidation.php` - prefixes domain error keys with `data.`
- `app/Domain/Projects/ProjectKeySuggester.php` - pure key suggestion
- `lang/cs/kokpit.php` - `projects` navigation, sections, hints, filters, actions, notifications
- `tests/Feature/Projects/ProjectResourceTest.php`, `ProjectKeyTest.php`, `tests/Unit/Projects/ProjectKeySuggesterTest.php`, `tests/Isolation/RouteWalkTest.php`

## Decisions Made
- `suggest()` is static on a final class; it imports neither a model nor the database, so the form passes the "taken" closure.
- Clearing a typed key makes the next name edit suggest again; any non-empty typed key stays.
- The list eager-loads the client without `SoftDeletingScope`, so an archived client's name still shows.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan rejected `withTrashed()` on generic builders**
- **Found during:** Task 1
- **Issue:** `withTrashed()` on `Builder<Model>` and a `Builder<Project>` return annotation fail Larastan
- **Fix:** removed the narrowing `@return` docblocks (inherit the parent's) and used `withoutGlobalScopes([SoftDeletingScope::class])` in closures; `Client::query()->withTrashed()` stays where the model type is known
- **Files modified:** app/Filament/Resources/ProjectResource.php
- **Verification:** PHPStan clean, tests green
- **Committed in:** 26ede70

**2. [Rule 1 - Bug] Test compared an enum select state with a string**
- **Found during:** Task 1
- **Issue:** Filament returns the enum case for a Select over an enum; the form-set assertion and the Action both expect a string
- **Fix:** assertion uses `BillingType::Hourly`; `ProjectResource::actionData()` normalises enum cases to values before the Action (also needed in production)
- **Committed in:** 26ede70

**3. [Rule 1 - Bug] Test fixture currency mismatch**
- **Found during:** Task 1
- **Issue:** an EUR client needs an EUR zero hourly rate to satisfy the clients currency CHECK
- **Fix:** fixture passes `Money::ofMinor(0, 'EUR')`
- **Committed in:** 26ede70

---

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs, two of them in tests/fixtures)
**Impact on plan:** None on scope; all needed for a green build.

## Issues Encountered
- macOS `sed -i` needs a backup suffix; edits were made with a small script instead. No effect on the result.
- The key-collision test could pass on the form's own unique rule; it now asserts the Action's message so it proves the database path.

## User Setup Required
None - no external service configuration required.

## Known Stubs
None.

## Threat Flags
None - no new endpoint or trust boundary beyond the plan's threat model (T-04-15 to T-04-18 mitigated: AdminOnly access rule, Admin-only history, client disabled and not dehydrated on edit, no force delete).

## Next Phase Readiness
- Plan 04-09 (client resource) can reuse `RethrowsDomainValidation`, the archive/restore action builders pattern and the route-walk entry shape.
- Phase 5 can freeze the key against the existing unique index; the form already uppercases and validates it.

## Self-Check: PASSED

- All created files exist; commits `26ede70`, `57abc5f`, `2771c19` are ancestors of HEAD.
- Full suite 1272 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
