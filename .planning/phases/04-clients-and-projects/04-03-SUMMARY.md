---
phase: 04-clients-and-projects
plan: 03
subsystem: ui
tags: [filament, partner-isolation, read-only-resource, enums, route-walk, livewire]

requires:
  - phase: 04-clients-and-projects
    provides: Project model with fail-closed Partner scope, ProjectPolicy, canary Project fixture (plan 04-02)
  - phase: 02-foundation-platform
    provides: AccessRule declarations, EnforcesResourceAccessRule, canary harness and route walk
provides:
  - Read-only Partner resource "Moje projekty" (slug my-projects) with list and detail pages
  - ProjectColumns Partner-safe column and entry builders with pinned name constants, shared with the later Admin resource
  - ProjectStatus and ProjectPriority enums (values equal the CHECK constraints) with Czech labels
  - Route walk that knows every resource with record routes and fails on an unknown one
affects: [04-04 project tags, 04-08 admin project resource, 04-09 clients resource]

actuals:
  tokens: 13400
  tasks: 2
  commits: 2

plan_head_before: 5ebe851d62238da1e752e2b36352c138976b17e9
plan_head_after: 8c7bfd51d59a4094e203355077eca1d45ce48eea

tech-stack:
  added: []
  patterns:
    - "Partner resources live in app/Filament/Partner/Resources, registered by a second discoverResources call"
    - "A Partner-visible surface is built only from a shared support class whose name constants a test pins"
    - "Route walk resource map: slug -> Partner may open + record id per client side; unknown slug throws LogicException"

key-files:
  created:
    - app/Filament/Partner/Resources/PartnerProjectResource.php
    - app/Filament/Partner/Resources/PartnerProjectResource/Pages/ListPartnerProjects.php
    - app/Filament/Partner/Resources/PartnerProjectResource/Pages/ViewPartnerProject.php
    - app/Filament/Support/ProjectColumns.php
    - app/Domain/Projects/Enums/ProjectStatus.php
    - app/Domain/Projects/Enums/ProjectPriority.php
    - tests/Feature/Projects/PartnerProjectResourceTest.php
  modified:
    - app/Domain/Projects/Models/Project.php
    - app/Providers/Filament/AdminPanelProvider.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "PartnerProjectResource::canAccess() repeats the trait's two conditions and adds a Partner-with-client check, so the Admin gets 403 and no duplicate navigation entry"
  - "Shared field labels live under kokpit.projects.fields (for the Admin resource of plan 04-08); partner_projects holds only navigation and empty-state strings"
  - "ProjectStatus and ProjectPriority also implement HasColor so the badge columns are coloured"

patterns-established:
  - "Route walk helpers walkedResourceMap, walkedRecordId and walkedPartnerMayOpen: later Admin-only resources are added as partner => false entries"
  - "Pinned surface test: table column names and infolist entry names equal constants, plus a forbidden-substring check"

requirements-completed: [PR-04, PR-01]

coverage:
  - id: D1
    description: "A Partner opens Moje projekty and sees the own visible projects read-only with exactly the pinned Partner-safe columns, and a detail page with the pinned entries"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#lists the own visible project to a Partner and nothing of client B"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#builds the table and the detail only from the pinned Partner-safe names"
        status: pass
    human_judgment: false
  - id: D2
    description: "Table search covers only name and key; client B's canary and key find nothing; the resource is not globally searchable and has no actions, bulk actions or relation managers"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#searches only the own visible projects by name and key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#offers no header, record or bulk action and no global search"
        status: pass
    human_judgment: false
  - id: D3
    description: "Hidden, archived, archived-client and other-client projects answer 403 or 404 by URL without leaking client B; the Admin and a client-less Partner get 403"
    requirement: "PR-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#refuses a Partner the view page of a hidden, an archived and another client's project"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#gives the Admin and a Partner without a client 403 on the Partner project resource"
        status: pass
    human_judgment: false
  - id: D4
    description: "No rate, price, estimate, billing type, internal note or client field is rendered on the Partner list or detail"
    requirement: "PR-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#does not render a rate, price or client field on the list or the detail"
        status: pass
    human_judgment: false
  - id: D5
    description: "Status and priority labels are the Czech D-16 wording rendered from enums whose values equal the CHECK constraint values"
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/PartnerProjectResourceTest.php#renders the status and priority labels from the Czech enum translations"
        status: pass
      - kind: unit
        ref: "tests/Feature/Localisation/EnumLabelsTest.php#has a translated Czech label for every case of every app enum implementing HasLabel"
        status: pass
    human_judgment: false
  - id: D6
    description: "The route walk covers the my-projects routes and throws on a resource it does not know"
    verification:
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php#refuses a record route of a resource it does not know, so a new resource is looked at on purpose"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php#shows Partner A nothing of client B on any panel route"
        status: pass
    human_judgment: false

duration: 5min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 03: Partner Projects Resource Summary

**Read-only "Moje projekty" Filament resource for the Partner built only from pinned `ProjectColumns` builders over the scoped `Project` model, with Czech status and priority enums and a route walk that fails on unknown resources**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-08T12:45:55Z
- **Completed:** 2026-10-08T12:51:04Z
- **Tasks:** 2
- **Files modified:** 12 (7 created, 5 modified)

## Accomplishments

- `PartnerProjectResource` (slug `my-projects`, `#[AccessRule(PartnerAllowed)]`, not globally searchable) with `index` and `view` pages only; no action, bulk action, relation manager or export; `canAccess()` also requires a Partner with a client, so the Admin gets 403 and no second navigation entry.
- `ProjectColumns` with `partnerColumns()` and `partnerEntries()` plus the `PARTNER_COLUMN_NAMES` and `PARTNER_ENTRY_NAMES` constants a test pins; only `name` and `key` are searchable. Plan 04-04 adds the tags to both lists.
- `ProjectStatus` and `ProjectPriority` string enums (values equal the CHECK constraints) with D-16 Czech labels (Plánovaný, K upřesnění, V realizaci, Ke kontrole, K vypuštění, Dokončeno; Nízká, Normální, Vysoká, Naléhavá) and badge colours; `Project` casts both.
- Panel registers `app/Filament/Partner/Resources` with a second `discoverResources` call; the panel registry test covers the new directory.
- `RouteWalkTest` now carries a resource map (slug to Partner access and record id per client), requests `my-projects` with the client B project id (expects 403 or 404) and the own project id (expects 200 and the own canary), expects 403 for Admin-only entries, and throws `LogicException` for an unknown resource.
- 14 feature tests: list, Livewire table, search, detail, hidden, archived, archived-client and other-client refusals, Admin and client-less Partner 403, pinned names, no forbidden field names, no actions or global search.
- Full suite 1083 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, Partner opens Moje projekty and its read-only detail** - `f3f3265` (feat)
2. **Task 2: Search, URL and audience refusals, pinned Partner-safe surface** - `8c7bfd5` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer feedback gate ran with human-verify mode `end-of-phase` and an automated-only `<verify>`: the quick suite, full suite, Pint and PHPStan were re-run end to end before expansion and passed.

## Files Created/Modified

- `app/Filament/Partner/Resources/PartnerProjectResource.php` - read-only Partner resource
- `app/Filament/Partner/Resources/PartnerProjectResource/Pages/ListPartnerProjects.php`, `ViewPartnerProject.php` - list and view pages without header actions
- `app/Filament/Support/ProjectColumns.php` - Partner-safe column and entry builders with pinned name constants
- `app/Domain/Projects/Enums/ProjectStatus.php`, `ProjectPriority.php` - labelled, coloured enums
- `app/Domain/Projects/Models/Project.php` - enum casts, typed docblock
- `app/Providers/Filament/AdminPanelProvider.php` - Partner resources directory
- `lang/cs/enums.php`, `lang/cs/kokpit.php` - enum labels, `projects` and `partner_projects` sections
- `tests/Isolation/RouteWalkTest.php` - resource map, unknown-resource guard
- `tests/Feature/Projects/PartnerProjectResourceTest.php` - Partner resource tests

## Decisions Made

- `canAccess()` is a stricter override of the trait method and repeats its two conditions (declaration and policy) before the Partner-with-client check.
- Field labels shared with the future Admin resource sit under `kokpit.projects.fields`; the Partner-only strings sit under `kokpit.partner_projects`.
- The enums implement `HasColor` in addition to `HasLabel` for the badge columns.
- Assumption from the plan adopted: "Ready to release" is rendered "K vypuštění".

## Deviations from Plan

None - plan executed exactly as written. Pint changed `static::class` to `self::class` in the final resource class (style only).

## Issues Encountered

None. The Task 2 behaviours all passed on first run, so no resource change was needed; they now pin the guarantees Task 1 built (the plan anticipated this).

## Known Stubs

None.

## Threat Flags

None - the new surface (Partner list, detail, table search, Livewire state) is exactly the one T-04-06 covers, mitigated and tested.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 04-04 adds the project tags column and entry to `ProjectColumns` and updates the pinned name constants.
- Plans 04-08 and 04-09 add `projects` and `clients` to `walkedResourceMap()` as `partner => false` entries; the Admin project resource reuses `ProjectColumns`.
- Open point from the plan's edge coverage: toggling `client_visible` off while a Partner page is open is covered only by the per-request scope, with no dedicated test; the archived-client case has a test.

## Self-Check: PASSED

All created files exist on disk; commits `f3f3265` and `8c7bfd5` are ancestors of HEAD; the acceptance greps of both tasks pass; full suite (1083 passed), Pint and PHPStan are clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
