---
phase: 07-rest-api
plan: 03
subsystem: api
tags: [filament, sanctum, api-tokens, totp, livewire, route-walk, pest]

requires:
  - phase: 07-rest-api
    provides: "07-01 token-secured /api/v1 skeleton, ApiAbility, ApiFixtures, the two route groups"
  - phase: 02-access-control
    provides: "AdminOnlyPolicy, #[AccessRule] and the panel registry tests"
provides:
  - "Admin-only 'API tokeny' screen: list, create with password and TOTP re-confirmation, one-time token modal, revoke"
  - "IssueApiToken and RevokeApiToken domain Actions with activity rows that hold name and abilities only"
  - "ApiAbility, ApiTokenExpiry and ApiTokenStatus enums with Czech labels"
  - "tests/Isolation/ApiAccessTest.php: data-driven route walk over every api/v1 route (token guard, one ability, matching rate limit, 401 and 403)"
affects: [07-04, 07-05, 07-06, 07-07, 07-08, 07-09, 07-10, 07-11, 07-12]

actuals:
  tokens: 19185
  tasks: 3
  commits: 5

plan_head_before: 017663bba0ca5c27f7981edba7bea2926dda7c4e
plan_head_after: eeeecf0cd26a7d7613841b823286e54d50c3f517

tech-stack:
  added: []
  patterns:
    - "Identity confirmation inside a Filament action modal: RateLimiter, Hash::check, AppAuthentication::verifyCode(shouldPreventCodeReuse), errors thrown as mountedActions.0.data.<field> with the secrets cleared first"
    - "One-time secret display: #[Locked] page property filled by the handler, second page action opened with replaceMountedAction, the only button clears it"
    - "Route walk derived from Route::getRoutes(), so later plans' routes are checked without editing the test, with probe routes proving the check can fail"
    - "Audit rows for token creation and revocation through activity() inside the Action, event names api_token.created and api_token.revoked"

key-files:
  created:
    - app/Domain/Identity/ApiTokenExpiry.php
    - app/Domain/Identity/ApiTokenStatus.php
    - app/Domain/Identity/Actions/IssueApiToken.php
    - app/Domain/Identity/Actions/RevokeApiToken.php
    - app/Filament/Resources/ApiTokenResource.php
    - app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php
    - tests/Feature/Api/ApiTokenScreenTest.php
    - tests/Feature/Api/IssueApiTokenTest.php
    - tests/Isolation/ApiAccessTest.php
  modified:
    - app/Domain/Identity/ApiAbility.php
    - app/Providers/AccessServiceProvider.php
    - lang/cs/kokpit.php

key-decisions:
  - "Form state of an enum options list holds enum cases (CheckboxList and Select), so the handler accepts a case or its string value and refuses anything else"
  - "The one-time modal and the revoke body use inline styles (word-break, overflow-wrap, user-select) because the precompiled Filament CSS has font-mono but not break-all, select-all or break-words, and the project has no build step"
  - "A revoke confirmation whose row was deleted elsewhere is detected in the page's hydrate() hook, before Filament resolves the mounted actions and silently drops the submit"
  - "The ability id tooltip is set per row (all ability ids joined) because a Filament badge column has no per-badge tooltip"
  - "Token activity rows carry the subject personal_access_token and Czech event labels in kokpit.activity"

patterns-established:
  - "A Pest test asserting several secrets must collect offenders into an array and expect it to be empty, because Pest toContain takes variadic needles, not a message"
  - "Livewire modal content is not in the page HTML in tests: assert the mounted action object (heading, labels, description) and assertSchemaStateSet instead of assertSee"

requirements-completed: [AP-01, AP-05]

coverage:
  - id: D1
    description: "The Admin creates a token with a name, abilities and validity after a password and a fresh TOTP code; the token immediately reads GET /api/v1/time-entries/{uuid}"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php#it creates a token with password and code, shows it once, and the token reads the API"
        status: pass
    human_judgment: false
  - id: D2
    description: "Wrong password, wrong, reused or recovery code, missing secret and the sixth attempt a minute leave the modal open, clear the secrets, create nothing and show the documented error"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php (confirmation edge tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A limited validity ends at the last whole second of the chosen Prague day, stored in UTC, across the clock change"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/IssueApiTokenTest.php#it ends a limited token at the last second of the chosen Prague day"
        status: pass
    human_judgment: false
  - id: D4
    description: "Revoke deletes only the signed-in Admin's own token after a confirmation, the next API request answers 401, a gone token shows the info toast"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php (revoke tests)"
        status: pass
    human_judgment: false
  - id: D5
    description: "A Partner has no navigation item, is refused at /admin/api-tokens, cannot drive the screen with forged Livewire calls, and no Partner-rendered page holds any of the eight token strings"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiTokenScreenTest.php (Partner tests)"
        status: pass
    human_judgment: false
  - id: D6
    description: "Every api/v1 route has auth:sanctum, exactly one ability and the matching rate limit, and answers 401 to a guest and a Partner token and 403 to a token without the ability"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Isolation/ApiAccessTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "The token screen looks and behaves as UI-SPEC Surfaces A-D in light and dark mode and at 375 px, including in-flight spinners, the copy button and the wrapping of a long name"
    verification: []
    human_judgment: true
    rationale: "Visual layout, the clipboard button, loading states and small-screen overflow are the four backstop truths of the plan; no test renders a browser. Collected for the end-of-phase UAT."

duration: 20min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 03: Admin-only API token screen and route walk Summary

**An Admin-only 'API tokeny' Filament screen where a token is minted only after a password plus a fresh TOTP code, shown exactly once, and revoked with a server-side owner check, together with a route walk that fails the suite for any api/v1 route lacking a token guard, an ability or its rate limit.**

## Performance

- **Duration:** about 20 min
- **Tasks:** 3 (one tracer, two TDD)
- **Files modified:** 12 (9 created, 3 modified)

## Accomplishments

- The Admin creates a token in a modal (name, abilities with nothing preselected, validity none, 30, 90 or 365 days, then password and TOTP code). The plain token appears only in the one-time modal that the single button "Token mám uložený" closes (no Esc, no click-away, no close icon); after that it is in no HTML, snapshot, notification, activity row or log.
- Identity confirmation is throttled to 5 attempts a minute per user, refuses a reused or recovery code, fails closed with a danger toast for an Admin without a secret, and clears both secrets while keeping the other choices on every failure.
- A limited token ends at the last whole second of the chosen Europe/Prague day (22:59:59 UTC in winter, 21:59:59 UTC in summer), so `timestamptz(0)` never rounds it into the next day.
- Revoke asks "Zneplatnit token?", deletes only a row of the signed-in Admin, writes an audit row, and the very next API request with that token is 401. A token deleted in another tab shows "Token už byl zneplatněn.".
- A Partner sees no navigation item, is refused at the page, and cannot drive create or revoke through forged Livewire calls; none of the eight token strings appears on any Partner page.
- `tests/Isolation/ApiAccessTest.php` walks `Route::getRoutes()` for every `api/v1/` route (not vacuous, and probe routes prove it can fail), so plans 07-04 onward are guarded by construction.
- Full suite: 2694 tests green, Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

## Task Commits

1. **Task 1 (tracer): the Admin creates a token with password and code, sees it once, and it reads the API** - `cf9ca48` (feat)
2. **Task 2: create-modal rules and the list contract** - RED `8bb677c` (test), GREEN `4576d81` (feat)
3. **Task 3: revoke, Partner isolation and the route walk** - RED `2aa6b10` (test), GREEN `eeeecf0` (feat)

**Plan metadata:** committed separately as `docs(07-03): complete admin API token screen plan`.

## Files Created/Modified

- `app/Domain/Identity/ApiAbility.php` - now `HasLabel`
- `app/Domain/Identity/ApiTokenExpiry.php` - none, 30, 90, 365 days; Prague end-of-day expiry as a UTC whole second
- `app/Domain/Identity/ApiTokenStatus.php` - Aktivní, Vyprší brzy (7 days), Vypršel with colour and icon
- `app/Domain/Identity/Actions/IssueApiToken.php` - Admin-only issuing, validation, audit row
- `app/Domain/Identity/Actions/RevokeApiToken.php` - owner-checked deletion, audit row
- `app/Filament/Resources/ApiTokenResource.php` - Admin-only list, columns, empty state, revoke row action
- `app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php` - docs link, create action with identity confirmation, one-time modal
- `app/Providers/AccessServiceProvider.php` - `Gate::policy(PersonalAccessToken::class, AdminOnlyPolicy::class)`
- `lang/cs/kokpit.php` - the `api_tokens.*` strings verbatim from the UI-SPEC, plus the activity event and subject labels
- `tests/Feature/Api/ApiTokenScreenTest.php`, `tests/Feature/Api/IssueApiTokenTest.php`, `tests/Isolation/ApiAccessTest.php`

## Decisions Made

- The checkbox list and select hand back enum cases in form state, so the create handler accepts a case or its value and refuses everything else (T-07-18 verified with a forged `*`).
- Inline styles replace the Tailwind utilities `break-all`, `select-all` and `break-words`, which the precompiled Filament CSS does not contain; `font-mono` is present and used as a class.
- The "token already gone" path lives in the page's `hydrate()` hook because Filament drops a submit for a missing row without calling the action.
- The ability tooltip is the joined list of ability ids for the row, since a badge column has no per-badge tooltip.

## TDD Notes

Task 1 is a tracer (single feat commit; its test failed first because the page class did not exist). Tasks 2 and 3 followed RED, GREEN with a `test(07-03)` commit before each `feat(07-03)` commit; the plan is `type: execute`, so the plan-level gate does not apply and the RED evidence was inspected by hand.

- **Task 2 RED:** 17 of the new tests failed on the planned assertions (missing `ApiTokenStatus`, no refusal for a Partner or deactivated Admin, password and code not cleared, no throttle, a missing secret raised a LogicException, no empty-state heading, no activity row, no last used, valid until or status column). Characterisation tests that already passed because Task 1 built the behaviour: reused code, recovery and non-numeric codes, required messages, name and ability field errors, per-user attempt counting.
- **Task 3 RED:** 8 of the new tests failed (no `RevokeApiToken`, no `revoke` table action). Passed immediately and are characterisation tests: the one-time close leaves no token in the page (built in Task 1), the Partner navigation, forged-call and rendered-string tests (the access trait and policy already refuse), and the whole route walk (plan 07-01 already carries the three middleware; the plan states the walk needs no production change).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Tailwind utilities from the UI-SPEC are not in the precompiled CSS**
- **Found during:** Task 1
- **Issue:** `break-all`, `select-all` and `break-words` have no rule in `public/css/filament/filament/app.css` and the project has no build step, so the classes would do nothing.
- **Fix:** `font-mono` stays a class; the wrapping and select-all behaviour use inline `style` attributes (`word-break`, `overflow-wrap: anywhere`, `user-select`) on the token block, the name cells and the revoke modal.
- **Files modified:** `ListApiTokens.php`, `ApiTokenResource.php`
- **Commit:** `cf9ca48`, `eeeecf0`

**2. [Rule 1 - Bug] Form state holds enum cases, not strings**
- **Found during:** Task 1
- **Issue:** the first handler treated `abilities` and `expiry` as strings and failed with "Vyberte alespoň jedno oprávnění." and a string conversion error.
- **Fix:** accept an enum case or its value, refuse anything else.
- **Files modified:** `ListApiTokens.php`
- **Commit:** `cf9ca48`

**3. [Rule 1 - Bug] A revoke whose row is gone was dropped silently by Filament**
- **Found during:** Task 3
- **Issue:** with the row deleted, Filament cannot resolve the mounted action and never calls it, so the documented info toast could not come from the action closure or from an override of `callMountedAction` (the mounted actions are already cleared by then).
- **Fix:** a `hydrate()` hook on the page closes the confirmation and sends the toast when the record key no longer exists in the Admin's own query.
- **Files modified:** `ListApiTokens.php`
- **Commit:** `eeeecf0`

**4. [Rule 2 - Missing critical] Audit labels for the new activity rows**
- **Found during:** Task 2
- **Issue:** the history screen would show the raw event `api_token.created` and the raw subject `personal_access_token`.
- **Fix:** Czech event labels (`api_token.created`, `api_token.revoked`) and the subject label under `kokpit.activity`.
- **Files modified:** `lang/cs/kokpit.php`
- **Commit:** `4576d81`

**Total deviations:** 4 auto-fixed (2 Rule 1, 1 Rule 2, 1 Rule 3). **Impact:** none on scope; the UI-SPEC outcome is kept, only the mechanism differs in three places.

## Authentication Gates

None.

## Issues Encountered

- Running `pint` with an explicit `lang` path reformatted six unrelated language files (the project config excludes them when pint runs without a path). They were restored with `git checkout -- <file>` before any commit, so no unrelated file is in the history.

## Known Stubs

None. The docs link points to `config('scribe.laravel.docs_url', '/docs')`, which works before and after plan 07-02 ships the documentation; it is not a stub.

## Threat Flags

None beyond the plan's register. The new surface (token screen, create and revoke handlers, one-time token display) is covered by T-07-14 to T-07-19 and T-07-56, all mitigated and tested.

## Next Phase Readiness

Plans 07-04 onward can add routes to the two groups in `routes/api.php`; the route walk fails the suite the moment a route lacks `auth:sanctum`, its single ability or the matching limit, and plan 07-12 extends the walk with the canary line. The visual check of Surfaces A-D (the two backstop truths and the copy button) is collected for the end-of-phase UAT.

## Self-Check: PASSED

- Created files verified on disk: ApiTokenExpiry.php, ApiTokenStatus.php, IssueApiToken.php, RevokeApiToken.php, ApiTokenResource.php, ListApiTokens.php, ApiTokenScreenTest.php, IssueApiTokenTest.php, ApiAccessTest.php (all FOUND).
- Commits verified as ancestors of HEAD: cf9ca48, 8bb677c, 4576d81, 2aa6b10, eeeecf0 (all FOUND).
- All task acceptance criteria re-run and passing; plan verification: full Pest suite (2694 passed), Pint clean, PHPStan clean, `scripts/check-sensitive.sh` clean.
