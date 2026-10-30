---
phase: 04-clients-and-projects
plan: 05
subsystem: auth
tags: [filament, access-rules, audience, simplepage, pest-arch, escape-hatch, partner-scope]

requires:
  - phase: 02-platform
    provides: AccessRule/AccessRules declaration-driven panel access, PanelRegistryTest, EscapeHatchScanner
  - phase: 04-clients-and-projects
    provides: PartnerScope on Project (plans 04-02, 04-04) that the new scanner rule protects
provides:
  - Audience::Guest with a fail-closed AccessRules match arm for signed guest pages
  - Panel registry governance of SimplePage subclasses and a placement rule for Guest declarations
  - Escape-hatch scanner rule that fails the build when code removes PartnerScope by name
affects: [04-08, 04-11, 04-17]

actuals:
  tokens: 4100
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Guest-declared classes are SimplePage subclasses outside the panel registry, denied by AccessRules, reached only by a signed route"
    - "Escape-hatch scanner walks the argument list of withoutGlobalScope(s) by paren depth and matches PartnerScope as a trailing name segment"

key-files:
  created:
    - tests/Support/Filament/Fixtures/GuestSimplePageFixture.php
  modified:
    - app/Domain/Shared/Auth/Audience.php
    - app/Domain/Shared/Auth/AccessRules.php
    - tests/Arch/PanelRegistryTest.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Support/EscapeHatchScanner.php
    - tests/Arch/QueryEscapeHatchTest.php

key-decisions:
  - "Audience::Guest always denies in AccessRules::allows(); the guest page of plan 04-17 carries no Enforces* trait and enforces access through its signed route"
  - "The registry governs Filament SimplePage (not a Page subclass) and fails when Guest sits on a non-SimplePage class under app/Filament or on any class the panel registers"
  - "The PartnerScope scanner rule matches the class name token (short or fully qualified) inside the call arguments; aliases and variables are out of reach and left to review"

patterns-established:
  - "Placement rule as a pure helper (panelRegistryMisplacedGuests) with positive and negative unit cases plus one scan over the real tree"

requirements-completed: [PR-04]

coverage:
  - id: D1
    description: "Audience::Guest exists and AccessRules::allows() denies a Guest-declared class for a guest, a Partner with and without a client, and the Admin"
    requirement: PR-04
    verification:
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#it denies a class declaring Audience::Guest to a guest, a Partner with or without a client and the Admin"
        status: pass
    human_judgment: false
  - id: D2
    description: "Panel registry governs SimplePage subclasses and restricts Guest to unregistered SimplePage subclasses"
    requirement: PR-04
    verification:
      - kind: unit
        ref: "tests/Arch/PanelRegistryTest.php#it lets Audience::Guest stand only on an unregistered SimplePage subclass"
        status: pass
      - kind: unit
        ref: "tests/Arch/PanelRegistryTest.php#it governs SimplePage subclasses: an undeclared one is reported and a declared one is not"
        status: pass
      - kind: unit
        ref: "tests/Arch/PanelRegistryTest.php#it keeps Audience::Guest off every non-SimplePage and every registered class under app/Filament"
        status: pass
    human_judgment: false
  - id: D3
    description: "Escape-hatch scan reports withoutGlobalScope/withoutGlobalScopes naming PartnerScope and leaves SoftDeletingScope, comments and strings alone"
    requirement: PR-04
    verification:
      - kind: unit
        ref: "tests/Arch/QueryEscapeHatchTest.php#it reports withoutGlobalScope naming PartnerScope, short and fully qualified, with file and line"
        status: pass
      - kind: unit
        ref: "tests/Arch/QueryEscapeHatchTest.php#it does not report removing only the soft-delete scope, nor a PartnerScope mention in a comment or a string"
        status: pass
    human_judgment: false

duration: 4min
completed: 2026-10-08
commits: 2
plan_head_before: e2d78236d4a65ffa3e2431fbf4534ef8ebc50ffa
plan_head_after: dc534a6a33b22a44863c6d444b41835d37696a65
status: complete
---

# Phase 4 Plan 05: Access Primitives Summary

**Fail-closed `Audience::Guest` for signed guest pages with SimplePage-aware panel registry governance, plus an escape-hatch scanner rule that fails the build when code strips `PartnerScope` by name**

## Performance

- **Duration:** 4 min
- **Started:** 2026-10-08T13:01:22Z
- **Completed:** 2026-10-08T13:05:56Z
- **Tasks:** 2
- **Files modified:** 7 (1 created, 6 modified)

## Accomplishments
- `Audience::Guest` added with the `AccessRules::allows()` arm `Audience::Guest => false`: a Guest-declared class is denied to a guest, a Partner with a client, a Partner without a client and the Admin (T-04-09).
- `PanelRegistryTest` now governs `Filament\Pages\SimplePage` subclasses (they are not `Page` subclasses, so neither `discoverPages()` nor the old test saw them) and fails when `Guest` is declared on a non-SimplePage class under `app/Filament` or on any class the panel registers. `GuestSimplePageFixture` is the passing example; an anonymous Dashboard subclass declaring Guest is the reported one.
- `EscapeHatchScanner` has a third hatch, `withoutGlobalScope(PartnerScope)`, for `withoutGlobalScope(` and `withoutGlobalScopes([...])` calls whose arguments name `PartnerScope` (T-04-10). The app-wide scan stays green because app code removes only the soft-delete scope.

## Task Commits

1. **Task 1: Audience::Guest, AccessRules arm, SimplePage governance (tracer)** - `f28b9ef` (feat)
2. **Task 2: Escape-hatch rule for removing the Partner scope by name** - `dc534a6` (feat)

**Plan metadata:** the docs commit that follows this SUMMARY (docs: complete plan)

The tracer verification (registry and panel access tests, full suite, Pint, PHPStan) passed end to end before Task 2 started.

## Files Created/Modified
- `app/Domain/Shared/Auth/Audience.php` - new `Guest` case with docblock
- `app/Domain/Shared/Auth/AccessRules.php` - `Audience::Guest => false` arm
- `tests/Arch/PanelRegistryTest.php` - SimplePage in governed bases, `panelRegistryAppFiles()`, `panelRegistryMisplacedGuests()` and three tests
- `tests/Support/Filament/Fixtures/GuestSimplePageFixture.php` - SimplePage declaring Guest, never registered
- `tests/Isolation/PanelAccessTest.php` - four-context denial test
- `tests/Support/EscapeHatchScanner.php` - PartnerScope-by-name hatch with paren-depth argument walk
- `tests/Arch/QueryEscapeHatchTest.php` - reporting and non-reporting cases

## Decisions Made
- Guest placement is enforced in the registry test, not in `AccessRules`, because `AccessRules` has no panel knowledge and always denies anyway.
- The scanner matches `PartnerScope` as a whole name or a trailing `\PartnerScope` segment, so unrelated names containing the word do not trip it. Variable or `use ... as` aliases cannot be seen by a token scan; this limit is documented in the scanner docblock.

## Deviations from Plan

None - plan executed exactly as written.

Process note: in Task 2 the tests and the scanner rule were written in one step, so no separate RED run was recorded. A mutation check (replacing the argument walk with `false`) made the two reporting tests fail, then the original was restored and all 8 tests passed. In Task 1 the RED run was observed first (`Undefined constant Audience::Guest`).

## Issues Encountered
- One test run right after the source edit still showed the previous failure output; an immediate rerun passed without changes (stale view of the file inside the DDEV container). No code change was needed.

## Known Stubs

None.

## Threat Flags

None - no new endpoints, auth paths, file access or schema changes; the plan only adds a deny-only enum case and test-side scanning.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Plan 04-17 can declare its invitation accept page as a `SimplePage` with `#[AccessRule(Audience::Guest, ...)]`, kept out of the panel's page registration.
- Plans 04-08 and 04-11 may remove `SoftDeletingScope` freely; any attempt to remove `PartnerScope` by name now fails `QueryEscapeHatchTest`.
- Full suite at the end of this plan: 1106 passed; Pint and Larastan clean.

## Self-Check: PASSED

- Created file present: `tests/Support/Filament/Fixtures/GuestSimplePageFixture.php`
- Commits `f28b9ef` and `dc534a6` are on the branch
- All acceptance criteria of both tasks passed; plan-level verification (full Pest, Pint, PHPStan, `scripts/check-sensitive.sh`) clean

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
