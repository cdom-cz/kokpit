---
phase: 04-clients-and-projects
plan: 17
subsystem: clients
tags: [invitations, signed-url, notification, filament-simplepage, guest-page, sha-256]

requires:
  - phase: 04-clients-and-projects
    provides: "ClientInvitation record, InvitePartner, hashed token and 7-day TTL (04-16); Audience::Guest and SimplePage registry rule (04-05)"
provides:
  - "InvitationMail service and queued scalar-only PartnerInvitation notification sent on demand after commit"
  - "Temporary signed route filament.admin.invitation.accept (no path parameters) and the guest AcceptInvitation SimplePage"
  - "ClientInvitation::findAcceptable() as the one system-run guest lookup, and the derived state() with InvitationState"
  - "ResendInvitation (token rotation under a row lock) and RevokeInvitation"
affects: [04-18, 04-19]

actuals:
  tokens: 20000
  tasks: 2
  commits: 3
plan_head_before: 45a875f63d9f82dd567c8db10269e3d02585688d
plan_head_after: cd9c463df5db37fac2d61f34dc4ee97a868bcafb

tech-stack:
  added: []
  patterns:
    - "Guest page on a panel routes() hook: signed middleware, id and token as query parameters, Audience::Guest, no Enforces* trait"
    - "Queued notification built from scalars with afterCommit set in the constructor, sent to an on-demand mail address"
    - "Lifecycle Actions lock and re-read the row (lockForUpdate) and decide on the locked state, never on the instance in hand"

key-files:
  created:
    - app/Domain/Clients/InvitationMail.php
    - app/Domain/Clients/Notifications/PartnerInvitation.php
    - app/Domain/Clients/Enums/InvitationState.php
    - app/Domain/Clients/Actions/ResendInvitation.php
    - app/Domain/Clients/Actions/RevokeInvitation.php
    - app/Filament/Pages/Auth/AcceptInvitation.php
  modified:
    - app/Domain/Clients/Actions/InvitePartner.php
    - app/Domain/Clients/Models/ClientInvitation.php
    - app/Providers/Filament/AdminPanelProvider.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php
    - tests/Feature/Clients/PartnerInvitationTest.php

key-decisions:
  - "The notification sets afterCommit() itself instead of relying on the queue connection config, so a rolled-back invitation never mails a link"
  - "A mailed link whose signature has expired answers 403 from the signed middleware; the neutral page covers every case where the signature is valid but the invitation is not acceptable (the two layers are tested separately)"
  - "The guest page keeps the invitee e-mail, id and token in #[Locked] properties; the password form and account creation are left to plan 04-18"
  - "Accepted and revoked outrank expired in state(), so they keep their state after the expiry date"

patterns-established:
  - "findAcceptable(): uuid check first, system-run lookup, hash_equals, then state() === Pending; every failure returns the same null"

requirements-completed: [US-02]

coverage:
  - id: D1
    description: "The invitation e-mail is one queued, after-commit, on-demand PartnerInvitation built from scalars, carrying a valid temporary signed URL of filament.admin.invitation.accept with the invitation id and plain token"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#queues one on-demand mail with a signed link to the accept route (D-01, D-02)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#builds the mail from scalars only, in Czech"
        status: pass
    human_judgment: false
  - id: D2
    description: "The mailed token matches the stored SHA-256 hash and appears in no column"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#mails the token of which only the hash is stored, and the token is in no column"
        status: pass
    human_judgment: false
  - id: D3
    description: "A valid link opens the guest page with the invitee's e-mail; every bad link (wrong or foreign token, unknown or non-uuid id, missing parameters, revoked, accepted, expired) shows one identical neutral message with the same status"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#opens a valid link as a guest and shows the e-mail of the invitee"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#answers every bad link with one identical neutral message and the same status (D-02)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#shows the neutral page for an invitation at exactly its expiry time and later"
        status: pass
    human_judgment: false
  - id: D4
    description: "A link without signature, with a changed query, or with an expired signature answers 403; the route is covered by the route walk and the panel registry accepts the Guest page"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#refuses a link without a signature or with a changed query with 403"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php, tests/Arch/PanelRegistryTest.php (full suite)"
        status: pass
    human_judgment: false
  - id: D5
    description: "State is derived (pending, accepted, revoked, expired; expired from expires_at inclusive) with Czech labels Čeká, Přijata, Zrušena, Vypršela"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#derives the state from the timestamps and the current time, never from a stored column"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#labels the four states in Czech"
        status: pass
    human_judgment: false
  - id: D6
    description: "Resend rotates the token, extends the expiry, bumps send count and time and mails the new link; the old link then shows the neutral message although its signature is valid; revoke invalidates the link; accepted or revoked invitations are refused with a DomainException and unchanged"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#resends a pending invitation with a new token, a new expiry and a new mail"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#shows the neutral message for the old link after a resend although its signature is still valid"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#revokes a pending or expired invitation and its link becomes invalid"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#refuses to resend or revoke an accepted or a revoked invitation and changes nothing"
        status: pass
    human_judgment: false
  - id: D7
    description: "The rendered look of the guest page and the e-mail in a real browser and mail client"
    verification: []
    human_judgment: true
    rationale: "Layout, wording and readability of the Czech mail and page are visual judgments no test asserts"

duration: 10min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 17: Partner invitation delivery Summary

**Queued after-commit invitation e-mail with a temporary signed link to a Filament guest SimplePage that answers every bad link with one neutral message, plus derived invitation state, token-rotating resend and revoke**

## Performance

- **Duration:** about 10 min
- **Started:** 2026-10-08T15:20:06Z
- **Completed:** 2026-10-08T15:27:05Z
- **Tasks:** 2
- **Files modified:** 12 (6 created, 6 modified)

## Accomplishments
- `InvitePartner` now mails the invitation through `InvitationMail`: a temporary signed URL of `filament.admin.invitation.accept` (id and plain token as query parameters, expiring with the invitation) inside a queued, after-commit `PartnerInvitation` built from four strings and sent to the on-demand address.
- `AcceptInvitation` is a guest `SimplePage` (`Audience::Guest`, `#[Locked]` properties, route registered by the panel `routes()` hook with the `signed` middleware). It reads the invitation only through `ClientInvitation::findAcceptable()` and shows the invitee's e-mail, or one neutral message for every other case with the same HTTP status.
- `ClientInvitation::state()` and `InvitationState` derive pending, accepted, revoked or expired from the timestamps; `ResendInvitation` rotates the token hash and `RevokeInvitation` sets `revoked_at`, both under `lockForUpdate` on the re-read row.

## Task Commits

1. **Task 1: Tracer - signed e-mail link, guest page, neutral answers** - `a1e05ed` (feat)
2. **Task 2: Derived state, resend, revoke (TDD)** - `4dc340a` (test, RED: 9 new tests failed), `cd9c463` (feat, GREEN)

The tracer verification (invitation, panel registry and route walk tests, full suite, Pint, PHPStan) passed end to end before Task 2 started. Full suite at the end: 1491 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean.

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `app/Domain/Clients/InvitationMail.php` - builds the signed URL and sends the on-demand notification
- `app/Domain/Clients/Notifications/PartnerInvitation.php` - queued, after-commit, scalar-only Czech mail
- `app/Domain/Clients/Enums/InvitationState.php` - four states with Czech labels
- `app/Domain/Clients/Actions/ResendInvitation.php`, `RevokeInvitation.php` - lifecycle steps under a row lock
- `app/Domain/Clients/Actions/InvitePartner.php` - sends through `InvitationMail`
- `app/Domain/Clients/Models/ClientInvitation.php` - `findAcceptable()` and `state()`
- `app/Filament/Pages/Auth/AcceptInvitation.php` - guest page
- `app/Providers/Filament/AdminPanelProvider.php` - signed `invitation.accept` route
- `lang/cs/kokpit.php`, `lang/cs/enums.php` - mail, page, error and state texts
- `tests/Feature/Clients/PartnerInvitationTest.php` - 19 new tests

## Decisions Made
- The notification calls `afterCommit()` in its constructor, so the "sent after commit" rule does not depend on connection config.
- The original mailed link has a signature that expires with the invitation, so after expiry it answers 403 from the `signed` middleware; the neutral page is reached by every link with a valid signature but an unacceptable invitation. Both layers are tested (the plan's expiry test needed a freshly signed link to reach the page).
- `state()` ranks accepted and revoked above expired, so they keep their state after the expiry date.
- Only the `signed` middleware is on the route, as the plan's artifact table says; a 256-bit token under a signature needs no rate limiter at this stage.

## Deviations from Plan

None - plan executed exactly as written. (Two test-only adjustments during Task 1 and Task 2 were mistakes in my first test drafts, not plan deviations: a `$this` use inside a callback and a comparison of microsecond-precision times against a second-precision column.)

## Issues Encountered
None affecting the result.

## Known Stubs

The guest page shows only the invitee's e-mail and has no password form or Accept action yet. This is intended by the plan; plan 04-18 adds the password, the Partner account and the login. Not recorded in the broken-windows ledger because the plan names the resolving plan.

## Threat Flags

None beyond the plan's threat register. The one new unauthenticated endpoint (`GET /admin/invitation`) is the planned one: it requires a valid signature, reads only through `findAcceptable()` and discloses nothing about the invitation state (T-04-33, T-04-34, T-04-38 mitigated and tested; T-04-37 accepted).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Plan 04-18 can add the password form and account creation on `AcceptInvitation`, re-checking through `findAcceptable()` (or a locked re-read) on submit.
- Plan 04-19 can put invite, resend (`ResendInvitation`) and revoke (`RevokeInvitation`) into the client detail and show `state()` labels.
- Open point for 04-18/04-19: `ResendInvitation` does not refuse an archived client (the plan does not require it); the account step should check the client is not archived.

## Self-Check: PASSED

- Created files present: InvitationMail, PartnerInvitation, InvitationState, ResendInvitation, RevokeInvitation, AcceptInvitation
- Commits `a1e05ed`, `4dc340a`, `cd9c463` are on the branch
- All acceptance criteria of both tasks pass; plan-level verification (full Pest, Pint, PHPStan, `scripts/check-sensitive.sh`) clean

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
