---
phase: 04-clients-and-projects
plan: 18
subsystem: clients
tags: [invitations, accept-flow, filament-simplepage, rate-limiting, referrer-policy, single-use, partner-account]

requires:
  - phase: 04-clients-and-projects
    provides: "Signed invitation route, guest AcceptInvitation SimplePage, findAcceptable(), derived state(), Resend and Revoke (04-17); ClientInvitation record and InvitePartner (04-16); Partner project resource (04-03)"
provides:
  - "AcceptInvitation Action: single-use Partner account creation as the one sanctioned guest system run, under a row lock"
  - "InvitationNotAcceptable: the one neutral failure of the accept flow"
  - "ClientInvitation::isAcceptableWith() shared by the guest lookup and the Action"
  - "Password form on the guest page (name prefilled, password twice), rate limited, redirect to the login page"
  - "Named limiter invitation (10 a minute per IP), SetNoReferrerPolicy middleware, signed-in visitor notice"
affects: [04-19, 04-20]

actuals:
  tokens: 11000
  tasks: 2
  commits: 3
plan_head_before: 0ec1dbadb7d59a390b87dc5ee064667d5efef9dd
plan_head_after: 202435ae73c65bf9e26162a2ea666ef5eca30c95

tech-stack:
  added: []
  patterns:
    - "Guest account creation: explicit runAsSystem around one transaction that locks and re-reads the invitation row and checks the client; every invitation-side failure collapses into one exception"
    - "Route middleware order for a token route lives in the Laravel middleware priority list (bootstrap/app.php), because the framework sorts the limiter ahead of every other route middleware"
    - "Filament SimplePage content schema is cached per request: a state change that swaps the content calls cacheSchema('content', null)"

key-files:
  created:
    - app/Domain/Clients/Actions/AcceptInvitation.php
    - app/Domain/Clients/InvitationNotAcceptable.php
    - app/Http/Middleware/SetNoReferrerPolicy.php
    - tests/Feature/Clients/AcceptInvitationTest.php
  modified:
    - app/Domain/Clients/Models/ClientInvitation.php
    - app/Filament/Pages/Auth/AcceptInvitation.php
    - app/Providers/AppServiceProvider.php
    - app/Providers/Filament/AdminPanelProvider.php
    - bootstrap/app.php
    - lang/cs/kokpit.php
    - tests/Feature/Clients/PartnerInvitationTest.php

key-decisions:
  - "Name and password problems are field errors (ValidationException) that leave the invitation open; every invitation-side problem, including an e-mail that got an account meanwhile (checked case-insensitively and backed by the unique violation), is the one neutral InvitationNotAcceptable"
  - "The invitation row is locked FOR UPDATE and the client row FOR SHARE inside the system-run transaction; the new user's client_id comes from the locked row via forceFill and email_verified_at is set the same way, so User's fillable set stays name, email, password"
  - "The route middleware is [SetNoReferrerPolicy, signed, throttle:invitation] with the first two moved ahead of the limiter in the framework priority list, so an unsigned request answers 403 uncounted and the 403 and 429 answers carry the header too"
  - "The submit is limited to 5 a minute per visitor; a signed-in visitor gets a sign-out notice and the invitation is not even read"

patterns-established:
  - "One neutral exception with a detail-free message for a whole guest flow, mapped by the page to the same state mount() uses"

requirements-completed: [US-02]

coverage:
  - id: D1
    description: "The invited person opens the mailed link, sets a name and password twice, and gets a Partner account of the inviting client (verified e-mail, Partner role, hashed password); the invitation is marked accepted with the new user"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#turns the mailed link into a Partner account that logs in and sees only the client's visible projects (US-02, D-01)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The new Partner logs in through the normal login page and sees exactly the client's visible projects, with no rate or price on the page"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#turns the mailed link into a Partner account that logs in and sees only the client's visible projects (US-02, D-01)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Accepting is single use under a lock; changes between page load and submit (revoked, expired, resent, client archived, e-mail registered in another letter case) and a second submit all end in one neutral message with identical visible text and create no user"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#uses an invitation once: the second submit of the same link is neutral and creates no second user"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#ends every change between page load and submit in the neutral message and creates no user (Pitfalls 3 and 4)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#answers the same visible text for every invalid case: an unknown link and each submit-time failure"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#throws the neutral exception from the Action for a wrong token, a malformed id and a second use"
        status: pass
    human_judgment: false
  - id: D4
    description: "Password needs at least 12 characters, at most 72 bytes and a matching confirmation; violations are field errors in the form and in the Action and leave the invitation open; client_id comes from the invitation"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#refuses a password below 12 characters, above 72 bytes or with a wrong confirmation as a field error and keeps the invitation open"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#applies the password limits in the Action itself, not only in the form"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#takes the client of the new account from the locked invitation, never from the input"
        status: pass
    human_judgment: false
  - id: D5
    description: "The accept route is throttled per IP (named limiter invitation, 10 a minute, unsigned requests not counted), the page submit is rate limited with a Filament notification, and every answer carries Referrer-Policy no-referrer"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#throttles the accept route per IP at ten requests a minute with 429"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#does not count an unsigned request against the limiter"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#rate limits the submit of the page and sends the Filament notification"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#sends Referrer-Policy no-referrer on every response of the accept route"
        status: pass
    human_judgment: false
  - id: D6
    description: "No self-registration exists (no register route); a signed-in user opening a valid link sees a sign-out notice and no form and the invitation stays open"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#offers no self-registration: no register route exists"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AcceptInvitationTest.php#shows a signed-in visitor a sign-out notice and no form, and leaves the invitation open"
        status: pass
    human_judgment: false
  - id: D7
    description: "The rendered look and Czech wording of the password form, the notice and the neutral page in a real browser"
    verification: []
    human_judgment: true
    rationale: "Layout, wording and readability of the guest page are visual judgments no test asserts; two browsers submitting at the same instant is covered by the row lock but not by a parallel-process test (flagged assumption in the plan)"

duration: 15min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 18: Partner invitation acceptance Summary

**Single-use Partner account creation from an invitation under a row lock as the one guest system run, with a throttled, no-referrer password page whose every failure ends in one neutral message**

## Performance

- **Duration:** about 15 min
- **Started:** 2026-10-08T15:30:54Z
- **Completed:** 2026-10-08T15:45:32Z
- **Tasks:** 2
- **Files modified:** 11 (4 created, 7 modified)

## Accomplishments
- `AcceptInvitation` (Action) validates name and password (12 characters, 72 bytes), then in one system-run transaction locks the invitation row, re-checks token, expiry and open state through `ClientInvitation::isAcceptableWith()`, checks the client exists and is not archived, refuses an e-mail that has an account in any letter case, creates the user with `forceFill` of `client_id` and `email_verified_at`, assigns the Partner role and marks the invitation accepted. A unique violation on the e-mail becomes the neutral answer.
- The guest page now has the password form (name prefilled, e-mail shown read-only, password twice), maps `ValidationException` to field errors and `InvitationNotAcceptable` to the same neutral state `mount()` shows, and redirects to the login page with a success notification. A signed-in visitor sees a sign-out notice and no form.
- End to end: the Admin invites, the test reads the link from the faked notification, the guest sets a password, exactly one Partner user of client A exists, the new user logs in through `Login::authenticate`, and the Partner project list shows client A's visible project and neither its hidden project, client B's project nor the rate.
- The route has the named limiter `invitation` (10 a minute per IP), the page submit is limited to 5 a minute, and every answer (200, 403, 429) carries `Referrer-Policy: no-referrer`.

## Task Commits

1. **Task 1: Tracer - password page, Partner account, login** - `78a9e1d` (feat). The tracer verification (both accept test files, full suite, Pint, PHPStan) passed end to end before Task 2 started.
2. **Task 2: Single use, neutral answers, password rules, throttling, no-referrer (TDD)** - `7231cce` (test, RED: 7 of 14 tests failed before the implementation), `202435a` (feat, GREEN)

Full suite at the end: 1505 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `app/Domain/Clients/Actions/AcceptInvitation.php` - locked, single-use account creation
- `app/Domain/Clients/InvitationNotAcceptable.php` - the detail-free neutral exception
- `app/Domain/Clients/Models/ClientInvitation.php` - `isAcceptableWith()`, used by `findAcceptable()`
- `app/Filament/Pages/Auth/AcceptInvitation.php` - form, `accept()`, rate limit, neutral and signed-in states
- `app/Http/Middleware/SetNoReferrerPolicy.php` - `Referrer-Policy: no-referrer`
- `app/Providers/AppServiceProvider.php` - `RateLimiter::for('invitation')`
- `app/Providers/Filament/AdminPanelProvider.php` - route middleware `[SetNoReferrerPolicy, signed, throttle:invitation]`
- `bootstrap/app.php` - priority list entries that keep the header and the signature check ahead of the limiter
- `lang/cs/kokpit.php` - Czech labels, messages and notices of the accept page
- `tests/Feature/Clients/AcceptInvitationTest.php` - 14 tests
- `tests/Feature/Clients/PartnerInvitationTest.php` - the 04-17 neutral-answers test flushes the cache per request

## Decisions Made
- Field errors leave the invitation open; invitation-side failures are the single neutral exception, so the page is neither an account nor an invitation oracle.
- The client row is share-locked in the same transaction as the invitation row lock, so an archive cannot commit between the check and the insert.
- A signed-in visitor's page does not read the invitation at all.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Laravel's middleware priority sorts the limiter ahead of `signed`**
- **Found during:** Task 2 (the "unsigned request is not counted" and "429 carries the header" tests failed in the RED-to-GREEN step)
- **Issue:** The plan says to keep `signed` first in the route middleware list so an unsigned request answers 403 before the limiter counts it. Laravel sorts `ThrottleRequests` ahead of all non-priority route middleware, so the list order has no effect: unsigned requests were counted and the 429 had no header.
- **Fix:** `bootstrap/app.php` calls `prependToPriorityList` for `SetNoReferrerPolicy` and `ValidateSignature` before `ThrottleRequests`; the route list is `[SetNoReferrerPolicy, signed, throttle:invitation]` (the header first, so the 403 and 429 answers carry it too, which the plan's behavior list requires: every response).
- **Files modified:** `bootstrap/app.php` (not in the plan's `files_modified`), `app/Providers/Filament/AdminPanelProvider.php`
- **Verification:** `does not count an unsigned request against the limiter` and `sends Referrer-Policy no-referrer on every response of the accept route` pass; full suite green
- **Committed in:** `202435a`

**2. [Rule 1 - Bug] The 04-17 neutral-answers test makes 11 requests from one IP**
- **Found during:** Task 2 full-suite run (the new limiter answered 429 on the 11th request)
- **Fix:** `Cache::flush()` before each request of that loop with a comment; the test is about the answers, not the limiter
- **Files modified:** `tests/Feature/Clients/PartnerInvitationTest.php`
- **Committed in:** `7231cce`

**3. [Rule 1 - Bug] Neutral state after submit kept the form content**
- **Found during:** Task 2 RED run (the second submit still rendered the form)
- **Issue:** Filament caches the `content` schema per request, so clearing the locked properties did not change the rendered content
- **Fix:** `cacheSchema('content', null)` in the switch to the neutral state
- **Files modified:** `app/Filament/Pages/Auth/AcceptInvitation.php`
- **Committed in:** `202435a`

---

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs)
**Impact on plan:** All needed for the plan's own behavior list (uncounted unsigned requests, header on every answer, neutral state). One extra file (`bootstrap/app.php`); no scope creep.

## Issues Encountered
None beyond the deviations above.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat register. The Livewire update request of the guest page (`POST /livewire/update` for `accept`) is the unauthenticated surface the plan names; it is covered by the submit rate limit and the Action's locked re-check (T-04-39, T-04-40, T-04-41, T-04-42, T-04-43, T-04-44 mitigated and tested; T-04-SC accepted).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Plan 04-19 can put invite, resend and revoke into the client detail. Open point carried from 04-17 and left for it: `ResendInvitation` still does not refuse an archived client (04-18 refuses it at accept time, so no account can be created either way).
- Plan 04-20 (deactivation rules) can rely on accepted invitations carrying `accepted_user_id`.
- Not covered by a test, as the plan flags: two browsers submitting the same valid link at the same instant (the row lock serializes them; the sequential two-browser case is tested).

## Self-Check: PASSED

- Created files present: AcceptInvitation (Action), InvitationNotAcceptable, SetNoReferrerPolicy, AcceptInvitationTest
- Commits `78a9e1d`, `7231cce`, `202435a` are on the branch
- All acceptance criteria of both tasks pass; plan-level verification (full Pest 1505 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) clean

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
