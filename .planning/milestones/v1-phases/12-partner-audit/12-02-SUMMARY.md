---
phase: 12-partner-audit
plan: 02
subsystem: testing
tags: [pest, routes, audit, authentication, laravel, filament, sanctum]

requires:
  - phase: 12-partner-audit
    provides: Audit suite, RouteInventory and RouteRules (plan 12-01)
provides:
  - anonymous refusal walk over every non-public route (401, 403 or the /admin/login redirect, plus an auth middleware)
  - closed-route status check and public-route robustness check
  - defect 1 fixed: an unauthenticated /api/v1 request without an Accept header answers a JSON 401 instead of 500
  - defect 2 fixed: the unused Filament export and import download routes answer 404 instead of 500
affects: [12-03, 12-05, 12-08]

actuals:
  tokens: 2500
  tasks: 2
  commits: 5

plan_head_before: d20a9c197d1c6a1fa32e41283f007cf004b266ba
plan_head_after: 737077a36dea3e0afbbd6e095cab1210a1726090
commits: 5

tech-stack:
  added: []
  patterns:
    - "Anonymous request helper: Auth::forgetGuards() then $this->call(method, path) with no headers, never ->json()"
    - "Explicit Route::bind closures that abort(404) close unused vendor routes without touching the database"

key-files:
  created: []
  modified:
    - tests/Audit/RouteAuditTest.php
    - bootstrap/app.php
    - app/Providers/AccessServiceProvider.php

key-decisions:
  - "redirectGuestsTo returns route('filament.admin.auth.login') unconditionally; the api/* null variant was not needed (R-12-02-2)"
  - "Unused Filament export/import routes stay registered and are closed by explicit binders (R-12-02-3); to be presented to the owner in plan 12-08"

patterns-established:
  - "A new route without auth middleware and without a RouteRules entry fails the inventory; a new authenticated route is walked anonymously automatically"

requirements-completed: [AUD-02]

coverage:
  - id: D1
    description: "Every non-public route refuses an anonymous request with no special header (401, 403 or the /admin/login redirect) and carries an auth middleware"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#refuses an anonymous request to every non-public route with 401, 403 or the login redirect"
        status: pass
    human_judgment: false
  - id: D2
    description: "Defect 1 fixed: unauthenticated /api/v1 request without Accept answers a JSON 401, for a guest and for an Admin or Partner session"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#defect 1: answers 401 JSON to a guest on every /api/v1 route that sends no Accept header"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#defect 1: answers 401 to a signed-in Admin and Partner session on every /api/v1 route that sends no Accept header"
        status: pass
    human_judgment: false
  - id: D3
    description: "Defect 2 fixed: the Filament export and import download routes answer 404 to guest, Partner and Admin without the absent tables"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#defect 2: closes the Filament export and import download routes with 404 for a guest, a Partner and an Admin"
        status: pass
    human_judgment: false
  - id: D4
    description: "Closed routes answer their declared status, public routes never answer 500, the panel login redirect and the Horizon 403 are unchanged"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#answers the declared status on closed route {0}"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#never answers 500 to an anonymous request on public route {0}"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#keeps the Filament login redirect and the Horizon 403 for a guest"
        status: pass
    human_judgment: false

duration: about 20 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 02: Anonymous Refusal Walk and Defect Fixes Summary

**An anonymous, header-less request to every route is walked against the RouteRules declaration, and the two 500s it exposed are fixed: `redirectGuestsTo` the panel login for the missing `login` route, and 404 binders for the dead Filament export/import routes.**

## Performance

- **Duration:** about 20 min
- **Completed:** 2026-10-24
- **Tasks:** 2
- **Files modified:** 3 (0 created, 3 modified)

## Accomplishments

- Task 1 (tracer): three tests for defect 1 (guest, signed-in Admin and Partner session, panel and Horizon guest behaviour unchanged) and the one-line `redirectGuestsTo` fix in `bootstrap/app.php`.
- Task 2: the defect 2 test and the two `Route::bind` closures in `AccessServiceProvider::boot()`; the anonymous refusal walk (status set of R-12-02-1 plus `RouteInventory::isAuthenticated()` for each route, with a non-vacuity count of Filament, API and Horizon routes), a per-key closed-route status test and a per-key public-route "never 500" test.
- The Audit suite is 63 tests, all green. `tests/Isolation` (356 tests) is green and untouched (D-08, `git diff 58c5e93 -- tests/Isolation` empty). Pint and PHPStan are clean.

### RED results before each fix

- Defect 1: the guest test and the session test both failed with 500 (`Route [login] not defined`) on every one of the 20 `/api/v1` routes; the login-redirect/Horizon test already passed.
- Defect 2: all six requests (two routes, guest, Partner, Admin) answered 500.

### Variant of R-12-02-2 needed

The plain variant: a closure returning `route('filament.admin.auth.login')` for every request. The null-for-`api/*` variant was not needed; the existing `shouldRenderJsonWhen` makes the handler answer JSON 401 for `api/*`.

### Further defects

The anonymous walk found no other route answering 200, 419 or 500. No route was allowlisted to make the walk pass.

## Task Commits

1. **Task 1: Tracer, defect 1** - `bf8d7a8` (test, RED), `e2f943c` (fix)
2. **Task 2: defect 2 and the walks** - `6c2be58` (test, RED), `42f6605` (fix), `737077a` (test, anonymous walk, closed and public checks)

**Plan metadata:** committed separately as docs(12-02).

## Files Created/Modified

- `tests/Audit/RouteAuditTest.php` - defect 1 and 2 regression tests, anonymous refusal walk, closed and public route checks, helpers `auditApiRoutes`, `auditRequest`, `auditIsRefusal`, `auditRouteByKey`
- `bootstrap/app.php` - `$middleware->redirectGuestsTo(...)` to the panel login, with the explaining comment
- `app/Providers/AccessServiceProvider.php` - `Route::bind('export', ...)` and `Route::bind('import', ...)` aborting 404, with the explaining comment

## Decisions Made

- Plain `redirectGuestsTo` closure (R-12-02-2); no `api/*` special case.
- Binders (R-12-02-3) rather than removing the vendor routes or publishing Filament's migrations (integer keys against the UUID v7 convention). Presented to the owner in plan 12-08 (Open Question 5).

## Deviations from Plan

None - plan executed exactly as written. The test and the fix of each defect were committed separately, which the plan allows. The first run after the `bootstrap/app.php` edit once still showed 500 for the guest test and passed on every later run (6 consecutive green runs); this was a stale file in the DDEV mount, not a code problem.

**Total deviations:** 0
**Impact on plan:** none.

## Issues Encountered

None beyond the stale DDEV mount noted above.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The two production changes close attack surface (a 500 page on unauthenticated API calls, two dead routes) and add none.

## Next Phase Readiness

- Plans 12-03 and 12-05 can send header-less requests to `/api/v1` and expect 401 JSON; the helper `auditRequest()` and the `Auth::forgetGuards()` pattern are in `tests/Audit/RouteAuditTest.php`.
- Plan 12-08 should present R-12-02-3 (binders versus the dormant vendor routes) and the exact-match correction of R-12-01-2 to the owner.

## Self-Check: PASSED

- Modified files exist: tests/Audit/RouteAuditTest.php, bootstrap/app.php, app/Providers/AccessServiceProvider.php
- Commits `bf8d7a8`, `e2f943c`, `6c2be58`, `42f6605`, `737077a` are ancestors of HEAD
- Acceptance criteria of both tasks re-run and passing (Audit suite 63 passed, Isolation 356 passed, Pint and PHPStan clean)

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
