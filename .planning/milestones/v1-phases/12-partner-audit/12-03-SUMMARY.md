---
phase: 12-partner-audit
plan: 03
subsystem: testing
tags: [pest, audit, filament, livewire, horizon, sanctum, permission-matrix, authorization]

requires:
  - phase: 12-partner-audit
    provides: Audit suite, RouteInventory (route keys, resolved middleware, path and method helpers) from plan 12-01
provides:
  - Tests\Support\PartnerMatrix, the literal permission matrix of every screen and endpoint (81 panel rows, 20 API rows, 3 Livewire rows, Horizon)
  - Tests\Support\PanelInventory, panel class and relation manager discovery, observed access and canary owner records
  - tests/Audit/AccessMatrixTest.php, completeness both ways plus per-row Admin and Partner behaviour for panel classes, API routes, Livewire components and Horizon
affects: [12-04, 12-08]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

plan_head_before: 3397078527e8a506b6425dfa17ab838c832393e7
plan_head_after: c8df16e674be33740e59b026e40f4a4a38eb3f73
commits: 2

tech-stack:
  added: []
  patterns:
    - "Literal declaration class plus a pure difference helper (auditAccessDifferences) that the self-tests feed planted classes and planted rows"
    - "Guard reset right before each token request, after all tokens and users are created"
    - "Relation manager rows are observed with canViewForRecord on a canary record of the owning resource's model and that resource's view page, else its edit page"

key-files:
  created:
    - tests/Support/PartnerMatrix.php
    - tests/Support/PanelInventory.php
    - tests/Audit/AccessMatrixTest.php
  modified: []

key-decisions:
  - "Resource pages that carry a rule of their own (ProjectBoard) count as governed classes in PanelInventory, although PanelRegistryTest skips every resource page"
  - "The literal-matrix guard fails on any mention of AccessRule, Audience, Policy, Policies, Gate::, canAccess, canView or canViewForRecord in PartnerMatrix.php, not only on the three class short names followed by two colons"
  - "UnresolvedPayments is declared Admin yes: the widget shows only while an unresolved card payment exists, so the per-row test seeds one"

patterns-established:
  - "A new Resource, Page, Widget, relation manager, /api/v1 route or Livewire component without a PartnerMatrix row fails the Audit suite, and so does a row without one"
  - "Adding a screen means one literal row in tests/Support/PartnerMatrix.php with a reason, visible in the pull request diff (D-11)"

requirements-completed: [US-01, AUD-02]

coverage:
  - id: D1
    description: "Every Resource, Page, Widget, cluster and relation manager has exactly one PartnerMatrix row, and every row has a class"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#declares every panel class and only existing ones"
        status: pass
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#reports an undeclared panel class and a row without a class"
        status: pass
    human_judgment: false
  - id: D2
    description: "For every row the Admin and a Partner with a client observe exactly the declared access, and every Resource index and panel Page answers 200 or 403 as declared"
    requirement: US-01
    verification:
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#behaves for the Admin and for a Partner as {0} declares"
        status: pass
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#answers 200 or 403 on every Resource index and panel Page as declared"
        status: pass
    human_judgment: false
  - id: D3
    description: "The matrix is literal data: it never refers to the access-rule reader, the audience enum, a policy or an access method"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#keeps the matrix literal"
        status: pass
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#keeps every row reasoned"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every /api/v1 route has a row with its one ability; it demands exactly that ability, an Admin token with only it is not refused, a Partner token with every ability gets 401"
    requirement: US-01
    verification:
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#declares every /api/v1 route and only existing ones"
        status: pass
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#demands exactly the declared ability on {0}"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every Livewire component has a row, an Admin mounts it and a Partner is refused; Horizon admits the Admin and answers a Partner 403 on every GET route"
    requirement: US-01
    verification:
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#lets the Admin mount and refuses a Partner on {0}"
        status: pass
      - kind: unit
        ref: "tests/Audit/AccessMatrixTest.php#admits the Admin to the Horizon dashboard and refuses a Partner on every Horizon GET route"
        status: pass
    human_judgment: false
  - id: D6
    description: "The draft matrix reflects what the owner wants; differences between the draft and the code are listed for sign-off"
    requirement: US-01
    verification: []
    human_judgment: true
    rationale: "Whether the declared Admin and Partner access matches the owner's intent is a judgment; plan 12-08 presents the findings table below for sign-off (D-02)"

duration: about 40 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 03: Screen and Endpoint Permission Matrix Summary

**A literal PartnerMatrix of 81 panel classes, 20 API routes, 3 Livewire components and the Horizon dashboard, checked in both directions against the discovered set and against the observed behaviour of an Admin and of a Partner with a client, including HTTP 200 or 403 on every Resource index and Page.**

## Performance

- **Duration:** about 40 min
- **Completed:** 2026-10-24
- **Tasks:** 2
- **Files modified:** 3 (3 created, 0 modified)

## Accomplishments

- Task 1 (tracer): `PanelInventory` ports the panel discovery of `PanelRegistryTest` (registry plus a scan of `app/Filament`), adds observed access (`canAccess`, `canView`, `canViewForRecord` on a canary owner record, guest pages through the declaration), `requestUrl()` and `canaryRecord()`. `PartnerMatrix::panelClasses()` holds the 81 literal rows. The test file checks completeness both ways with self-tests, the reasons, the literal guard, 81 per-class Admin/Partner observations and one HTTP walk over every Resource index and Page (ProjectBoard on a canary project).
- Task 2: the 20 API rows, the 3 Livewire rows and the Horizon row. Tests: API completeness both ways, the one resolved `CheckAbilities` middleware per route with its ability, an Admin token with only that ability is not answered 401 or 403, a Partner token with every ability is answered 401; Livewire completeness both ways, Admin mounts and Partner `assertForbidden`; Horizon `viewHorizon` gate for both roles, the dashboard 200 for the Admin and 403 for a Partner on every `horizon.` GET route.
- Mutation checks (rows flipped temporarily, then restored): a wrong Partner flag on a page, a wrong Admin flag on a relation manager, a wrong ability on `api.v1.transactions.destroy`, a wrong Partner flag on `TimerBar` and on Horizon each fail the suite with the offender named.
- Audit suite 176 tests green; `tests/Arch` and `tests/Isolation` (434 tests) untouched and green; Pint and PHPStan clean.

## Findings for the owner

All rows below are declared as the code behaves (R-12-03-3). No row shows the code granting a Partner more than the draft, so no leak was found and no production file was touched.

| Class or route | Draft value | Observed value | Source | Decision |
|---|---|---|---|---|
| `PartnerProjects`, `PartnerTasks` widgets | Admin "as observed" | Admin refused (`canView` refuses the Admin), Partner with a client admitted | Widget class docs: "the Admin is refused by canView()" | Declared Admin no, Partner yes |
| `PartnerProjectDocumentsRelationManager`, `PartnerTaskCommentsRelationManager`, `PartnerTaskDocumentsRelationManager` | Admin "as observed" | Admin admitted on an own visible project or task of client A, Partner admitted | Declaration PartnerAllowed plus the parent check on the owner record | Declared Admin yes, Partner yes |
| `UnresolvedPayments` widget | Admin yes | Admin sees it only while at least one unresolved card payment exists; without one `canView()` is false | `UnresolvedPayments::canView()` ("the card exists only above zero") | Declared Admin yes; the per-row test seeds one unmatched payment. Owner to confirm that data-dependent visibility is intended |
| `ProjectBoard` | Admin yes, Partner no (listed as an Admin page) | As drafted, but `PanelRegistryTest` skips it because it is a resource page; it carries its own rule | `ProjectBoard` has `#[AccessRule]` and its own `canAccess(array $parameters = [])` | Declared as drafted; `PanelInventory` counts resource pages that carry their own rule |
| Guest pages `RequestPasswordReset`, `AcceptInvitation` | no, no | Denied to everyone by the panel checks; reached only by their own guest or signed route (covered by `RouteRules`) | `#[AccessRule(Guest)]` | Declared no, no; not requested over HTTP as a panel page |
| Class count | "about eighty" | 81 discovered classes; every one is covered by the draft table, none was left over | `PanelInventory::panelClasses()` | One literal row per class |

Every other draft row (Admin resources, pages, widgets and relation managers: Admin yes, Partner no; Partner pages and resources; the canary resource; the 20 API routes; the 3 Livewire components; Horizon) matched the code without a difference.

## Task Commits

1. **Task 1: Tracer, panel classes against the literal matrix** - `69d252f` (test)
2. **Task 2: API endpoints, Livewire components and Horizon** - `c8df16e` (test)

**Plan metadata:** committed separately as docs(12-03).

## Files Created/Modified

- `tests/Support/PartnerMatrix.php` - literal rows (`panelClasses()`, `apiEndpoints()`, `livewireComponents()`, `horizon()`) and `problems()`
- `tests/Support/PanelInventory.php` - discovery (`panelClasses()`, `relationManagers()`, `livewireComponents()`), `observedAccess()`, `canaryRecord()`, and the helpers `owningResource()`, `ownerModel()`, `pageClassOf()`, `requestUrl()`
- `tests/Audit/AccessMatrixTest.php` - 113 tests: completeness both ways with self-tests, literal guard, per-row behaviour, HTTP 200 or 403 walk, API, Livewire and Horizon checks

No production file was touched (no D-09 leak fix was needed).

## Decisions Made

- A resource page counts as governed when it carries its own rule (or uses `EnforcesPageAccessRule`); plain list, create, edit and view pages stay governed by their Resource, as in `PanelRegistryTest`.
- The literal guard looks for the names `AccessRule` (which also matches `AccessRules`), `Audience`, `Policy`, `Policies`, `Gate::`, `canAccess`, `canView` and `canViewForRecord` anywhere in `PartnerMatrix.php`; it has its own self-test with planted sources.
- The test-only canary row (`'testOnly' => true`) counts only while `kokpit.canary_harness` is on; with the harness off it is neither required nor reported as a row without a class.

## Deviations from Plan

None - plan executed exactly as written. Two small points inside the plan's latitude: `PanelInventory` gained `requestUrl()` and `pageClassOf()`/`ownerModel()`/`owningResource()` as helpers beyond the listed contract, and the literal guard checks more names than the three the plan named.

One test-writing slip, fixed before the commit: the first version of the ability test forgot the guards before a Partner token request while a guard resolved in between still held the previous Admin token's user, so the Partner token got 200. The guards are now forgotten right before each request, after all users and tokens exist, the way `tests/Isolation/ApiAccessTest.php` does it. This was a defect of the new test, not of the application: the isolation suite's Partner-token tests stayed green.

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

None beyond the test-writing slip above.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code only.

## Next Phase Readiness

- Plan 12-04 can extend `PartnerMatrix` with the Partner actions on records and the presented fields; `PanelInventory::canaryRecord()` and `observedAccess()` are reusable for it.
- Plan 12-08 presents the "Findings for the owner" table above together with the draft matrix for sign-off (D-02).

## Self-Check: PASSED

- Created files exist: tests/Support/PartnerMatrix.php, tests/Support/PanelInventory.php, tests/Audit/AccessMatrixTest.php
- Commits `69d252f` and `c8df16e` are ancestors of HEAD
- Acceptance criteria of both tasks re-run and passing; plan-level verification re-run (Audit suite 176 passed, Arch and Isolation 434 passed, Pint and PHPStan clean)

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
