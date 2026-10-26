---
phase: 12-partner-audit
plan: 01
subsystem: testing
tags: [pest, routes, audit, ci, composer-scripts, authentication]

requires:
  - phase: 11-stripe-payments
    provides: the public pay page and the Stripe webhook route that the allowlist declares
provides:
  - the Audit Pest suite (tests/Audit) registered in phpunit.xml and bound to RefreshDatabase
  - Tests\Support\RouteInventory (route discovery, keys, resolved middleware, auth recogniser, inventory checker)
  - Tests\Support\RouteRules (public allowlist with means and reason, closed list with expected status and reason)
  - tests/Audit/RouteAuditTest.php (inventory audit with self-tests)
  - composer scripts test (Audit excluded), partner-audit, ci; CI step Partner audit; CiParityTest guard
affects: [12-02, 12-03, 12-05, 12-08]

actuals:
  tokens: 6000
  tasks: 2
  commits: 2

plan_head_before: b0db2530a4c182739401537288c680321df549eb
plan_head_after: d922175ad2405860f2272dac2f6c16c1b042626a
commits: 2

tech-stack:
  added: []
  patterns:
    - "Declaration class in tests/Support plus an inventory checker that is pure over its arguments, so self-tests feed it planted routes and declarations"
    - "Route key = route name, or METHOD /uri with the APP_KEY-derived Livewire prefix normalised to livewire-{hash}"

key-files:
  created:
    - tests/Support/RouteInventory.php
    - tests/Support/RouteRules.php
    - tests/Audit/RouteAuditTest.php
  modified:
    - phpunit.xml
    - tests/Pest.php
    - composer.json
    - .github/workflows/hygiene.yml
    - tests/Feature/Repo/CiParityTest.php

key-decisions:
  - "Authentication middleware is matched exactly (class or class:parameter), not by prefix, because Filament AuthenticateSession shares the prefix and is on every panel route"
  - "The six unnamed Livewire asset routes are declared in the public allowlist under livewire-{hash} keys (means none)"
  - "composer test and the CI Pest step exclude the Audit suite; composer partner-audit and the CI step Partner audit run it alone (R-12-01-4)"

patterns-established:
  - "Adding a public route or an exception means editing tests/Support/RouteRules.php only, so it shows in the pull request diff (D-11)"
  - "A new vendor or application route without auth middleware and without a RouteRules entry fails the Audit suite by key"

requirements-completed: [AUD-02]

coverage:
  - id: D1
    description: "Audit suite exists, runs alone (vendor/bin/pest --testsuite=Audit) inside RefreshDatabase transactions"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "ddev exec vendor/bin/pest --testsuite=Audit (33 passed)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every route of the router has a recognised auth middleware or exactly one RouteRules entry; stale, ghost, duplicate and reasonless entries fail"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#gives every route an auth middleware or exactly one RouteRules entry, and every entry a route"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#reports a route without an auth middleware and without an entry"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#reports a declared entry without a route, a key in both lists and a stale entry for an authenticated route"
        status: pass
      - kind: unit
        ref: "tests/Audit/RouteAuditTest.php#rejects an empty reason, a whitespace reason, an unknown means and an unknown closed status"
        status: pass
    human_judgment: false
  - id: D3
    description: "CI runs the Audit suite as the step Partner audit; Pest step and composer test exclude it; composer ci runs partner-audit after the tests"
    requirement: AUD-02
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/CiParityTest.php#runs the Audit suite in its own step and in composer ci, never twice"
        status: pass
    human_judgment: true
    rationale: "The pass of the Partner audit step on a real GitHub Actions run cannot be observed locally; CiParityTest only pins the workflow text"

duration: about 25 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 01: Route Inventory Audit Summary

**The Audit Pest suite with a route inventory that fails the build on any route (application or vendor) lacking an authentication middleware or a reasoned RouteRules entry, wired into composer ci and its own CI step.**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-24
- **Tasks:** 2
- **Files modified:** 8 (3 created, 5 modified)

## Accomplishments

- Registered the `Audit` testsuite (`tests/Audit`) in phpunit.xml and bound it to TestCase + RefreshDatabase in tests/Pest.php (D-10).
- `RouteInventory` walks every route of `Route::getRoutes()` with no sampling (D-12), resolves middleware through `app('router')->gatherRouteMiddleware()` and checks the inventory against `RouteRules`.
- `RouteRules` declares 23 public routes (means and reason) and 2 closed routes (expected status 404 and reason); an entry without a route, an entry in both lists, a stale entry for an authenticated route, an empty or whitespace reason, an unknown means and an unsupported closed status all fail.
- `RouteAuditTest` has 33 tests: the inventory, one validity test per declared key, a non-vacuity guard, planted-route and planted-declaration self-tests, an exact-match recogniser self-test, and an environment guard (canary harness on, no Debugbar routes).
- CI: the `Pest` step and `composer test` exclude the suite, new step `Partner audit` and script `composer partner-audit` run it alone, `composer ci` runs `@partner-audit` right after `@test`; CiParityTest pins all of it (T-12-03).

### Declared keys

Public (means): `GET /` (none), `GET /up` (none), `pay.show` (token), `webhooks.stripe` (signature), `filament.admin.auth.login` (guest-only), `filament.admin.auth.password-reset.request` (guest-only), `filament.admin.auth.password-reset.reset` (signature), `filament.admin.invitation.accept` (signature), `scribe`, `scribe.openapi`, `scribe.postman` (none), `sanctum.csrf-cookie` (none), `storage.local`, `storage.local.upload` (signature), `default-livewire.update` (component), `livewire.upload-file`, `livewire.preview-file` (signature), and the six unnamed Livewire assets `GET /livewire-{hash}/livewire.js`, `.../livewire.min.js.map`, `.../livewire.csp.min.js.map`, `.../js/{component}.js`, `.../css/{component}.css`, `.../css/{component}.global.css` (none).

Closed (status 404): `filament.exports.download`, `filament.imports.failed-rows.download`. Plan 12-02 makes them answer 404 and proves it.

Routes reported as defects instead of being allowlisted: none. Every undeclared route the first run listed belonged to the seed table.

## Task Commits

1. **Task 1: Tracer - Audit suite and route inventory** - `9ab15ca` (test)
2. **Task 2: CI gate - own step, composer scripts, parity test** - `d922175` (ci; RED test written first and observed failing, then composer.json and hygiene.yml)

**Plan metadata:** committed separately as docs(12-01).

## Files Created/Modified

- `tests/Support/RouteInventory.php` - route discovery, keys (R-12-01-1), resolved middleware, auth recogniser (R-12-01-2), pure `problems()` checker
- `tests/Support/RouteRules.php` - public allowlist, closed list, `MEANS`, `problemsIn()` / `problems()` (D-06, D-11, R-12-01-3)
- `tests/Audit/RouteAuditTest.php` - inventory audit and self-tests
- `phpunit.xml`, `tests/Pest.php` - suite registration and binding
- `composer.json`, `.github/workflows/hygiene.yml` - scripts and CI step (R-12-01-4)
- `tests/Feature/Repo/CiParityTest.php` - "runs the Audit suite in its own step and in composer ci, never twice"

## Decisions Made

- Authentication middleware matched exactly (R-12-01-2 refined, see deviation 1).
- Livewire asset routes declared as six separate keys rather than a wildcard, so a seventh asset route would still fail the build.
- No Isolation file touched (D-08, `git diff 58c5e93 -- tests/Isolation` empty); no production attribute added (D-11); composer.lock unchanged, nothing installed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Auth recogniser matched by prefix treated every panel route as authenticated**
- **Found during:** Task 1, first run of the Audit suite
- **Issue:** R-12-01-2 says a resolved middleware string "starts with" `Filament\Http\Middleware\Authenticate`. `Filament\Http\Middleware\AuthenticateSession` shares that prefix and sits on every panel route, login page included, so the login, password-reset and invitation routes were reported as authenticated and their entries as stale; the same defect would have hidden a genuinely unauthenticated panel route. `Illuminate\Auth\Middleware\AuthenticateWithBasicAuth` has the same shape.
- **Fix:** `isAuthenticated()` accepts the class name exactly or the class followed by `:` (the `auth:sanctum` form). Added the self-test "recognises an authentication middleware exactly, not by prefix" (AuthenticateSession and auth.basic are not authenticated; Filament Authenticate and auth:sanctum are).
- **Files modified:** tests/Support/RouteInventory.php, tests/Audit/RouteAuditTest.php
- **Verification:** `ddev exec vendor/bin/pest --testsuite=Audit` (33 passed)
- **Committed in:** 9ab15ca

**2. [Rule 2 - Missing critical] Livewire asset routes needed explicit keys**
- **Found during:** Task 1
- **Issue:** The seed table described the six unnamed Livewire asset routes in one row; the checker needs one key per route.
- **Fix:** Added the six `GET /livewire-{hash}/...` keys with means none.
- **Files modified:** tests/Support/RouteRules.php
- **Committed in:** 9ab15ca

---

**Total deviations:** 2 auto-fixed (1 bug, 1 missing critical)
**Impact on plan:** Both necessary for the audit to be correct; no scope creep. The plan text of R-12-01-2 ("starts with") should read "is exactly, or is followed by a colon" when presented to the owner in plan 12-08.

## Issues Encountered

None beyond the deviations above.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code, CI configuration and composer scripts only.

## Next Phase Readiness

- Plans 12-02, 12-03 and 12-05 can build on the registered Audit suite, `RouteInventory::routes()` / `path()` / `method()` and `RouteRules::closedRoutes()`.
- The real pass of the `Partner audit` step needs a GitHub Actions run; locally `ddev composer partner-audit`, `ddev composer validate --strict`, Pint and PHPStan are clean and `tests/Feature/Repo` is green (133 passed).

## Self-Check: PASSED

- Created files exist: tests/Support/RouteInventory.php, tests/Support/RouteRules.php, tests/Audit/RouteAuditTest.php
- Commits `9ab15ca` and `d922175` are ancestors of HEAD
- All acceptance criteria of both tasks re-run and passing

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
