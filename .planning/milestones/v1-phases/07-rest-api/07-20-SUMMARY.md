---
phase: 07-rest-api
plan: 20
subsystem: admin-panel
tags: [filament, livewire, api-tokens, gap-closure, empty-state, mounted-actions]

requires:
  - phase: 07-rest-api
    provides: "07-03 ListApiTokens page with the createToken, showToken actions and the ApiTokenResource table"
provides:
  - "Empty-state action newToken that replaces the mounted stack with the page action createToken"
  - "G-07-3 regression tests: empty-state click mounts createToken as the only action, both-ability token created from it, error and cancel paths, forged Partner mount refused, empty-state action pinned"
affects: [admin-panel, api-tokens]

status: complete
plan_head_before: 3c7a3cb331354c09e0aabce1cded99760baa6db4
plan_head_after: fef78b53fa68ccdd89aabac6ae9a5bb643c70b6f

actuals:
  tokens: 3300
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A table or empty-state action that must open a page action swaps the mounted stack with replaceMountedAction instead of nesting a mount call in its handler"

key-files:
  created: []
  modified:
    - app/Filament/Resources/ApiTokenResource.php
    - tests/Feature/Api/ApiTokenScreenTest.php

key-decisions:
  - "Fix with replaceMountedAction('createToken') in the newToken handler, not an Alpine click handler: a server-side swap can be driven by a Livewire test and matches the pattern ListApiTokens::issueToken uses for showToken"
  - "The newToken handler starts with abort_unless(self::canAccess(), 403), like the revoke handler (defence in depth behind the page-level AccessRule)"
  - "ListApiTokens.php stays unchanged, so the empty-state route goes through the same identity confirmation, throttle and field errors as the header button"

patterns-established:
  - "No PHP code under app/ nests a mountAction call inside another action's handler"

requirements-completed: [AP-01, AP-05]

coverage:
  - id: D1
    description: "G-07-3 closed: the empty-state button 'Nový API token' leaves exactly one mounted action, createToken, and the Admin creates a token with time:read and time:write from it that reads the API"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#opens the create modal from the empty-state button and creates a token with both abilities (G-07-3)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The empty-state route keeps the create modal contract: wrong password stays under the open modal at mountedActions.0.data.password, cancel leaves nothing mounted, the action is pinned by name, order and label"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#keeps a wrong password under the modal opened from the empty state (G-07-3)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#leaves no mounted action when the modal opened from the empty state is cancelled (G-07-3)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#shows the empty state with the create action reachable from it"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner cannot drive the empty-state button: a forged mountTableAction('newToken') answers 403 and writes no token and no activity row (T-07-78)"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#refuses a forged Livewire mount, create and revoke by a Partner and writes nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "In a real browser the modal opens from the empty-state button and the modal, badges and Czech labels read correctly (UAT test 3)"
    requirement: AP-01
    verification: []
    human_judgment: true
    rationale: "Only a browser proves the modal opens from the button and that the visual and Czech copy read correctly; left for /gsd-verify-work 07"
---

# Phase 7 Plan 20: Empty-state token button Summary

**The empty-state button "Nový API token" now opens the create modal: the newToken handler swaps the mounted stack for the page action createToken (replaceMountedAction) instead of a nested mount that Filament dropped, closing G-07-3.**

## Performance

- **Started:** 2026-10-14T18:24:43Z
- **Completed:** 2026-10-14T18:27Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Reproduced G-07-3 as a failing test first, then fixed the one handler in `ApiTokenResource` (plus a `canAccess()` guard and corrected comments). `ListApiTokens.php` is untouched.
- Created a token with both abilities from the empty-state route: the one-time modal opens, the row stores `['time:read', 'time:write']`, and the plain token reads `GET /api/v1/time-entries` with 200.
- Pinned the empty-state route: wrong password stays under the open modal with the same field error as the header route, cancel leaves nothing mounted, a Partner's forged call answers 403 and writes nothing, and the action is asserted by name, order and label.

## RED evidence

Before the fix, after `mountTableAction('newToken')` the mounted-action stack was empty and the new test failed at `assertActionMounted('createToken')` with "Failed asserting that null matches expected 'createToken'". After the fix the stack is exactly `[createToken]` with an empty context.

## Task Commits

1. **Task 1: Tracer - empty-state button opens the create modal, token with both abilities reads the API** - `a1cc2d9` (fix)
2. **Task 2: Empty-state route keeps the create modal contract** - `fef78b5` (test)

## Files Created/Modified

- `app/Filament/Resources/ApiTokenResource.php` - newToken handler: `abort_unless(self::canAccess(), 403)` then `replaceMountedAction('createToken')`; comment and class docblock updated.
- `tests/Feature/Api/ApiTokenScreenTest.php` - G-07-3 tests (click, wrong password, cancel), strengthened empty-state test, forged Partner list gains `mountTableAction('newToken')`.

## Decisions Made

- `replaceMountedAction` over an Alpine click handler: testable with Livewire and the same pattern `issueToken` uses for `showToken`.
- Same `canAccess()` guard as the revoke handler, behind the page-level AccessRule.
- `ListApiTokens.php` unchanged, so there is no second create path and no copy of the form (D-03, D-04).

## Deviations from Plan

None - plan executed exactly as written. Tests 2 to 5 passed on the Task 1 code without a handler change.

## Verification

- `tests/Feature/Api/ApiTokenScreenTest.php`, `IssueApiTokenTest.php`, `tests/Isolation/ApiAccessTest.php`: 59 passed.
- `tests/Feature/Api` and `tests/Isolation`: 530 passed.
- Pint clean; PHPStan shows only the two known baseline `cast.string` errors (ResetAdminTwoFactorCommand line 38, StorageCheckCommand line 33).
- `git grep -e '->mountAction(' -- app` is empty; `abort_unless(self::canAccess(), 403)` appears twice; `ListApiTokens.php` has no diff; `scripts/check-sensitive.sh` clean.

## Known Stubs

None.

## Threat Flags

None. T-07-78 and T-07-79 are mitigated, with the forged-Partner and wrong-password tests as evidence.

## Issues Encountered

None.

## Human check left open

The browser check from Task 2 (empty list, click the empty-state button, create a token with both abilities, revoke it) is left for `/gsd-verify-work 07`.

## Next Phase Readiness

G-07-3 is closed. Remaining gap-closure work for phase 7 is tracked in 07-UAT.md.

## Self-Check: PASSED

- `app/Filament/Resources/ApiTokenResource.php` and `tests/Feature/Api/ApiTokenScreenTest.php` exist.
- Commits `a1cc2d9` and `fef78b5` are ancestors of HEAD.
