---
phase: 12-partner-audit
plan: 05
subsystem: testing
tags: [pest, audit, canary, channel-matrix, filament, global-search, sanctum, activity-log, livewire]

requires:
  - phase: 12-partner-audit
    provides: Audit suite and RouteInventory from plan 12-01; PanelInventory (relation manager discovery, canary owner records) from plan 12-03
provides:
  - Tests\Support\ChannelMatrix, the literal entity x channel declaration (38 CanaryRegistry entities x 8 channels), with completeness and cell checks
  - Tests\Support\ChannelProbe, the two-client scene plus the search, selects, api and activity drivers
  - tests/Audit/ChannelMatrixTest.php, 140 tests: declaration checks with self-tests, the entity x channel dataset for four channels, one control per driven channel
affects: [12-06, 12-07, 12-08]

actuals:
  tokens: 12800
  tasks: 3
  commits: 3

plan_head_before: 6e5b54e787d99e5482051b51a2d49ce17dcb9756
plan_head_after: 5b95606178475cdfd0171ad42cfe1fbc93b15f53
commits: 3

tech-stack:
  added: []
  patterns:
    - "One literal matrix row per CanaryRegistry entity, one cell per channel (foreign | none | not-applicable with a reason); a missing row, channel, value or reason fails the build"
    - "A channel blob is attributed to the entity whose resource (search) or primary key (selects) produced it, because several entities share the same canary text"
    - "A driver that must see a refusal asserts it itself (every API answer 401, every history manager refuses) and returns the bodies for the needle check"
    - "Each driven channel has a control proving the channel reaches real data, and a mutation check (done by hand) proved each driver fails on a real leak"

key-files:
  created:
    - tests/Support/ChannelMatrix.php
    - tests/Support/ChannelProbe.php
    - tests/Audit/ChannelMatrixTest.php
  modified: []

key-decisions:
  - "Search and select results are attributed to the entity that produced them (resource model for search, option key = row primary key for selects), so a project option or a canary-record search hit is not read as a leak for a task or a client whose canary text is identical"
  - "The test-only CanaryRecordResource is asked for global search directly, as RouteWalkTest does, because it does not opt in to the panel's opt-in global search; the real provider is used for everything else"
  - "The API driver requests a route with a path parameter only in the blob of the entity that parameter stands for; paramless routes go into every blob"
  - "An option that no registered entity owns goes into every entity's blob, so an unattributable canary fails all rows loudly instead of passing"

patterns-established:
  - "Adding an entity to CanaryRegistry means adding one row to tests/Support/ChannelMatrix.php in the same pull request (D-07)"
  - "A new Partner-accessible select, select filter or table widget is walked automatically by the selects driver"
  - "ChannelProbe::DRIVEN lists the channels that have a driver; plans 12-06 and 12-07 add ui, downloads, exports and notifications"

requirements-completed: [AUD-01]

coverage:
  - id: D1
    description: "Every CanaryRegistry entity and PartnerIsolated model has a ChannelMatrix row of eight complete cells; a missing row, row without entity, missing channel, unknown value or empty not-applicable reason fails the build"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#declares every CanaryRegistry entity and only those"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#declares every PartnerIsolated model"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps every row complete and every not-applicable cell reasoned"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#reports a missing channel, an unknown value and an empty not-applicable reason"
        status: pass
    human_judgment: false
  - id: D2
    description: "The activity cell of an entity is applicable exactly when the model writes activity rows (AuditDeclaration::loggingModels() plus Activity)"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#applies the activity channel exactly to the models that write activity rows"
        status: pass
    human_judgment: false
  - id: D3
    description: "Global search, selects, API and activity log are walked as Partner A for every entity: no client B needle or id, and for a none cell no client A needle, appears"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps the canaries of the cell out of Partner A's channel (38 search, 38 selects, 38 api, 15 activity cells)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Each driven channel has a control proving it reaches real data, so a refusal is not vacuous"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets global search reach real data, so the search rows are not vacuous"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets an Admin token read the canaries of both clients, so the API rows are not vacuous"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets the Admin read the activity rows of both clients, so the activity rows are not vacuous"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets the Partner select offer the own visible project, so the select rows are not vacuous"
        status: pass
    human_judgment: false
  - id: D5
    description: "The declared foreign, none and not-applicable cells of the four channels not driven yet (ui, exports, notifications, downloads) match what the owner wants"
    requirement: AUD-01
    verification: []
    human_judgment: true
    rationale: "These cells are declarations that plans 12-06 and 12-07 will drive; whether a not-applicable reason is right is a judgment that plan 12-08 presents for sign-off"

duration: about 20 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 05: Entity x Channel Canary Matrix Summary

**A literal 38-entity x 8-channel matrix over CanaryRegistry, with completeness checked both ways, and Partner A walked through global search, selects, the REST API and the activity log for every entity, each channel with a control and a hand-run mutation check.**

## Performance

- **Duration:** about 20 min
- **Started:** 2026-10-23T23:36Z (approx.)
- **Completed:** 2026-10-23T23:56Z
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files modified:** 3 (3 created, 0 modified)

## Accomplishments

- Task 1 (tracer): `ChannelMatrix` declares all 38 entities with eight cells each, following the draft cell rules literally. `problems()` and `problemsIn()` reject a missing channel, an unknown channel, an unknown value and an empty not-applicable reason. `ChannelProbe::seed()` builds the two-client scene and the search driver. The test file checks the declaration against `CanaryRegistry::fixtures()` and the PartnerIsolated models (with a planted anonymous class), the activity rule, and walks 38 search cells plus a control. Tracer verified end to end before expanding: `⚡ Tracer verified end-to-end — expanding`.
- Task 2: the API driver requests every `api/v1/` route with a Partner token carrying every ability and without a token (also with the real keys of the client A and the client B row for the six routes with a path parameter) and asserts that every answer is 401. The activity driver reads the scoped `Activity` query (zero rows), asserts that every `ActivityHistoryRelationManager` subclass refuses Partner A on the client A canary row of its owning model, and requests the Activity resource index (403). 38 API cells and 15 activity cells, two controls.
- Task 3: the selects driver mounts, as Partner A, the list page of every accessible resource (filter forms), the create page where Partner A may create (form selects), every relation manager on the own canary row, and every Partner-visible table widget; searchable selects are asked for every needle of every entity and the common prefix. 38 selects cells and a control.
- Mutation checks by hand (each restored afterwards, nothing left in the tree): Partner A acting as the Admin in the search driver fails four search rows; an Admin token in the API driver fails with `GET /api/v1/clients ... answered 200 instead of 401`; the Admin acting in the activity driver fails the activity row; opening `PartnerTaskResource::projectOptions()` to all projects fails `Project x selects` with the client B needle named.
- Audit suite 316 tests green (176 before this plan plus 140 here); `tests/Isolation` 356 tests green and byte-identical to the baseline `58c5e93` (D-08); Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, the matrix and the global search driver** - `b3fc1c2` (test)
2. **Task 2: API and activity log drivers** - `78e3d8d` (test)
3. **Task 3: selects driver** - `5b95606` (test)

**Plan metadata:** committed separately as docs(12-05).

## Files Created/Modified

- `tests/Support/ChannelMatrix.php` - `CHANNELS`, `entities()` (38 rows), `cells()`, `problemsIn()`, `problems()`
- `tests/Support/ChannelProbe.php` - `DRIVEN = ['search', 'selects', 'api', 'activity']`, `seed()`, `blob()`, `needles()`, `clientId()`, `canary()`, `actAs()`, `search()` and the four drivers
- `tests/Audit/ChannelMatrixTest.php` - 140 tests (completeness, self-tests, activity rule, four dataset walks, four controls)

No production file was touched (no D-09 leak fix was needed): no leak was found in any of the four channels.

## Deviations from the draft cell rules

None. All 38 rows follow the draft rules in the plan (ui, search, selects, api, notifications, activity, downloads, exports) cell for cell. The activity cell is applicable for exactly the 15 models the test derived from `AuditDeclaration::loggingModels()` plus `Activity`, the same set the draft named, so no cell needed a change.

## Channel-specific needles and attribution (R-12-05-3)

No bare date or id turned out to be non-distinctive in any driven channel, so no channel-specific needle was needed. A different problem appeared: many entities share the same canary text (a project name, a task title, a client name, a canary-record secret, a time-entry description and others are all the canary string), so the text alone cannot tell which entity a result shows, and a `none` cell would fail for every entity whenever one entity is legitimately `foreign`. The assertion is kept in full and the blob is attributed instead:

- search: a result is attributed to the model of the resource that produced it (the provider's category title is mapped back to the resources with that plural label; a category that belongs to no resource throws). The test-only canary resource is asked directly and attributed to `CanaryRecord`.
- selects: an option whose key is the primary key of a row of a registered entity (`keyOwners()`, a system-context lookup of all registered models) is attributed to that entity. An option of no known entity goes into every blob, so a canary in an option nobody can attribute fails every row.
- api and activity: every answer must be 401 or empty, so the whole output goes into the blob; only routes with a path parameter are attributed to the model of that parameter.

## Filament calls used by the selects driver (verified in vendor/filament)

- `Filament\Facades\Filament::getPanel('admin')->getResources()`, `$resource::canAccess()`, `$resource::canCreate()`, `$resource::getPages()` with `->getPage()` of the `index` and `create` registration
- `Livewire::test($pageClass)->instance()`, `$instance->getTableFiltersForm()` (`Filament\Tables\Contracts\HasTable`) and `$instance->getSchema('form')` (`InteractsWithSchemas::getSchema`, nullable)
- `Filament\Schemas\Schema::getFlatFields(withHidden: true)` (`HasComponents`), filtered to `Filament\Forms\Components\Select`
- `Select::getOptions()` (also for relationship selects, which set an options closure), `Select::isSearchable()`, `Select::getSearchResults(string)` (empty when no search callback is set, in which case the options list already carries everything)
- relation managers: `Livewire::test($manager, ['ownerRecord' => ..., 'pageClass' => ...])` after `$manager::canViewForRecord()`; table widgets: `Filament\Widgets\TableWidget` subclasses with `canView()`
- global search: `Filament::getGlobalSearchProvider()->getResults($term)->getCategories()` with `GlobalSearchResult::$title`, `$url`, `$details`; `$resource::getPluralModelLabel()` and `getModel()` to attribute a category; `CanaryRecordResource::getGlobalSearchResults($term)` directly
- Selects reached for Partner A: the two filters of the Partner invoice list (kind, status) and the `project_id` select of the Partner task create form. The Partner relation managers and the Partner table widgets carry no select, so they add none today; a future one is walked automatically. Relation manager header-action forms (for example a comment form) are not mounted, only their table filters.

## Decisions Made

See `key-decisions` in the frontmatter. In short: attribute a result to the entity that produced it, ask the test-only canary resource directly, keep each paramless API route in every blob and a parameter route only in its model's blob, and let an unattributable canary fail every row.

## Deviations from Plan

None - plan executed exactly as written. Inside the plan's latitude: `ChannelProbe` gained public helpers beyond the listed contract (`canary()`, `partnerA()`, `admin()`, `actAs()`, `search()`) for the controls, and the blob of the search and selects channels is attributed per entity as described above (R-12-05-3 allows a channel-specific treatment with a written reason).

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

- One self-test of the first Task 1 run compared problem lines by position, but `problemsIn()` sorts them, so the order differed; the test now checks the lines as a set. A defect of the new test only.
- A `tinker` call I made to list the logging models hung inside DDEV and was killed; the list was taken from the source instead and then confirmed by the activity-rule test. No file was left behind.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code only; no network endpoint, auth path, file access or schema was added.

## Next Phase Readiness

- Plans 12-06 and 12-07 add the ui, downloads, exports and notifications drivers: add the channel to `ChannelProbe::DRIVEN` and a `match` arm in `blob()`; the cells are already declared in `ChannelMatrix`. The attribution rule (which entity a piece of output shows) will matter again for the ui channel, where one page shows several entities.
- Plan 12-08 can present the declared cells and not-applicable reasons for sign-off (coverage item D5).

## Self-Check: PASSED

- Created files exist: tests/Support/ChannelMatrix.php, tests/Support/ChannelProbe.php, tests/Audit/ChannelMatrixTest.php
- Commits `b3fc1c2`, `78e3d8d` and `5b95606` are ancestors of HEAD; `git rev-list --count 6e5b54e..HEAD` printed 3 before this SUMMARY commit
- Acceptance criteria of all three tasks re-run and passing (38 search, 38 api, 15 activity, 38 selects dataset rows; 4 "not vacuous" controls; Isolation directory unchanged against `58c5e93`)
- Plan-level verification re-run: Audit suite 316 passed, `tests/Isolation` 356 passed, `vendor/bin/pint --test` clean, PHPStan no errors

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
