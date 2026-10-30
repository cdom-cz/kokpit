---
phase: 04-clients-and-projects
plan: 02
subsystem: database
tags: [postgres, laravel, eloquent, partner-isolation, canary-harness, projects]

requires:
  - phase: 04-clients-and-projects
    provides: clients table, Client model, canary harness on real client rows (plan 04-01)
  - phase: 02-foundation-platform
    provides: PartnerScope, IsolatesPartners, KokpitPolicy, schema rules R1-R9
provides:
  - projects table with database-enforced key, status, priority and date invariants
  - Project model with a fail-closed three-condition Partner constraint (own client, client-visible, client not archived)
  - ProjectPolicy with explicit Partner grants (viewAny, view of own visible projects only)
  - Client::projects() and the project morph alias
  - ProjectFactory and a canary Project fixture
  - pinned Partner-safe projects column allowlist (PartnerSafeColumnsTest)
affects: [04-03 partner UI, 04-04 tags, 04-06 project billing, 04-07, 04-08 admin project resource, phase 05 tasks]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

plan_head_before: 99e5757208b88cfb37377aa2e42bc0b9535cdfab
plan_head_after: 21b956200801f6825cc965727d88e4b3dd6c69e1

tech-stack:
  added: []
  patterns:
    - "Partner-readable model: IsolatesPartners plus a constrainForPartner that adds client, visibility flag and an EXISTS on the non-archived client row"
    - "Policy overrides only viewAny and view with an instanceof check on Model $record; all other abilities keep the KokpitPolicy denial"
    - "client_id is never fillable on a Partner-isolated child model; creation goes through the parent relation or forceFill (factories build unguarded)"
    - "Pinned column allowlist test for every Partner-readable table"

key-files:
  created:
    - database/migrations/2026_10_09_000300_create_projects_table.php
    - app/Domain/Projects/Models/Project.php
    - app/Domain/Projects/Policies/ProjectPolicy.php
    - database/factories/ProjectFactory.php
    - tests/Isolation/PartnerProjectVisibilityTest.php
    - tests/Isolation/PartnerSafeColumnsTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Support/Canary.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Feature/Schema/ClientTablesTest.php

key-decisions:
  - "No task counter column on projects: Phase 5 allocates task numbers from number_sequences with key task:<project uuid> (research item 5)"
  - "Enum columns are varchar plus named CHECK constraints, no native PostgreSQL enum"
  - "Foreign key projects.client_id is ON DELETE RESTRICT; the project key unique index includes archived rows"
  - "The archived-client check in the Partner scope is a plain EXISTS on clients.deleted_at, not the Client model (Client is closed to Partners)"

patterns-established:
  - "projectVisibility* helpers in tests/Isolation/PartnerProjectVisibilityTest.php (system-run project creation, visible name list)"
  - "insertProjectRow and projectRowKey raw-SQL helpers in tests/Feature/Schema/ClientTablesTest.php"
  - "Canary::projectKey() returns six random uppercase letters"

requirements-completed: [PR-04, PR-01, PR-02, CL-05]

coverage:
  - id: D1
    description: "A Partner query on projects returns exactly the own client's client-visible, non-archived projects of a non-archived client; hidden, other-client, archived and archived-client projects are invisible and reappear on client restore"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerProjectVisibilityTest.php#Partner query"
        status: pass
    human_judgment: false
  - id: D2
    description: "Admin and system run see every project; a guest, a role-less user and a Partner without a client see none"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerProjectVisibilityTest.php#Admin and system run"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerProjectVisibilityTest.php#fail-closed states"
        status: pass
    human_judgment: false
  - id: D3
    description: "ProjectPolicy grants a Partner viewAny and view of own visible projects only and denies every write ability; $project->client is null for a Partner"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerProjectVisibilityTest.php#ProjectPolicy"
        status: pass
    human_judgment: false
  - id: D4
    description: "Project canary fixture is registered and the registry and model-declaration tests list it"
    verification:
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#does not pass vacuously: the real isolated models and the canary model are all expected"
        status: pass
      - kind: unit
        ref: "tests/Arch/ModelDeclarationTest.php#finds every model of the application, so the scan cannot pass vacuously"
        status: pass
    human_judgment: false
  - id: D5
    description: "The projects table holds exactly the Partner-safe column allowlist and no money-like column name"
    requirement: "PR-01"
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerSafeColumnsTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "Project key is 2 to 6 uppercase ASCII letters and unique including archived projects; date, status, priority checks and foreign key proven with exact SQLSTATE codes"
    requirement: "PR-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/ClientTablesTest.php#projects"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 02: Projects Data Layer Summary

**`projects` table with a database-enforced 2-6 letter unique key and a `Project` model whose fail-closed Partner scope shows only the own client's client-visible, non-archived projects of a non-archived client**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-08T12:35:43Z
- **Completed:** 2026-10-08T12:43:23Z
- **Tasks:** 2
- **Files modified:** 14 (6 created, 8 modified)

## Accomplishments

- `projects` migration: UUID v7 key, `client_id` foreign key with ON DELETE RESTRICT, named CHECK constraints for key (`^[A-Z]{2,6}$`), status, priority and date order, a plain unique index on `key` that also reserves archived keys, and the `(client_id, client_visible)` index for the Partner scope.
- `Project` model with `constrainForPartner` (own client AND `client_visible = true` AND an EXISTS on a non-archived client row); `client_id` is not fillable, and a mass-assignment attempt throws.
- `ProjectPolicy` grants a Partner only `viewAny` and `view` of own visible projects; `Gate::policy` registered, `project` morph alias and `Client::projects()` added.
- Canary harness: `Project` fixture (one client-visible project per canary client) directly after the `Client` fixture, `Canary::projectKey()`, and both expected-model lists extended.
- `PartnerProjectVisibilityTest` (18 tests) covers hidden, other-client, archived project, archived and restored client, or-condition bypass, Admin, system run, guest, role-less user, client-less Partner, policy matrix and `$project->client` being null for a Partner.
- `PartnerSafeColumnsTest` pins the 13-column list and refuses money-like column names, with a failure message pointing to `project_billing`.
- Full suite 1068 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, Partner-visible projects on real canary clients** - `b751d92` (feat)
2. **Task 2: Schema constraint tests and Partner-safe column allowlist** - `21b9562` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer feedback gate ran with the human-verify mode `end-of-phase` and an automated-only `<verify>`: the quick suite, full suite, Pint and PHPStan were re-run end to end before expansion and passed.

## Files Created/Modified

- `database/migrations/2026_10_09_000300_create_projects_table.php` - projects table, CHECK constraints, unique key, Partner scope index
- `app/Domain/Projects/Models/Project.php` - Partner-isolated project model
- `app/Domain/Projects/Policies/ProjectPolicy.php` - explicit Partner grants
- `database/factories/ProjectFactory.php` - fictional runtime values, `visible()` state
- `app/Domain/Clients/Models/Client.php` - `projects(): HasMany`
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - `project` alias and policy registration
- `tests/Support/Canary.php`, `tests/Support/CanaryRegistry.php` - `projectKey()` and the Project fixture
- `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php` - expected model lists
- `tests/Isolation/PartnerProjectVisibilityTest.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Feature/Schema/ClientTablesTest.php` - visibility, column allowlist and schema constraint tests

## Decisions Made

- No task counter on `projects`; Phase 5 uses `number_sequences` (`task:<project uuid>`), as the plan assumed.
- The scope checks the client archive state with a plain EXISTS subquery on `clients.deleted_at`, because the `Client` model is deny-all for a Partner and would hide the row from its own check.
- `ProjectFactory::randomKey()` is a static helper on the factory, since `database/` code must not depend on `tests/`; `Canary::projectKey()` duplicates the six-letter generation for test support.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan rejected the narrowed `constrainForPartner` parameter type**
- **Found during:** Task 1 (PHPStan run)
- **Issue:** `Builder<Project>` is not compatible with the interface's `Builder<covariant Model>` (`method.childParameterType`).
- **Fix:** docblock changed to `Builder<covariant Model>`, as `DeniesPartners` declares it.
- **Files modified:** `app/Domain/Projects/Models/Project.php`
- **Verification:** PHPStan clean, full suite green
- **Committed in:** `b751d92`

**2. [Rule 1 - Bug] Mass-assignment test expectation did not match the application guard**
- **Found during:** Task 1 (first test run)
- **Issue:** the test assumed `client_id` is silently discarded; the application prevents silently discarding attributes and throws `MassAssignmentException`, which is stricter.
- **Fix:** the test now asserts the exception and that `client_id` is not in `getFillable()`.
- **Files modified:** `tests/Isolation/PartnerProjectVisibilityTest.php`
- **Committed in:** `b751d92`

---

**Total deviations:** 2 auto-fixed (2 bug, both in this plan's own new code and tests)
**Impact on plan:** None on scope. No migration defect was revealed by the schema tests in Task 2.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None - no new network endpoint, auth path or file access beyond the plan's threat model (T-04-01, T-04-52 and T-04-53 mitigations implemented and tested).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plans 04-03 and 04-04 can build the Partner read-only project resource on `Project` and its policy; plan 04-04 adds the Tag fixture after the Project fixture.
- Plan 04-06 adds `project_billing` (Admin-only); `PartnerSafeColumnsTest` already points authors there.
- Project creation code must set `client_id` through `$client->projects()` or `forceFill`.

## Self-Check: PASSED

All created files exist on disk; commits `b751d92` and `21b9562` are ancestors of HEAD; the plan's acceptance greps pass; full suite (1068 passed), Pint and PHPStan are clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
