---
phase: 04-clients-and-projects
plan: 19
subsystem: clients
tags: [invitations, filament-action, relation-manager, admin-only, resend, revoke]

requires:
  - phase: 04-clients-and-projects
    provides: "InvitePartner, EmailHasNoAccount, EmailHasNoOpenInvitation (04-16); ResendInvitation, RevokeInvitation, derived state() and InvitationState (04-17); accept flow (04-18); ViewClient and the contacts tab pattern (04-11 to 04-13)"
provides:
  - "Header action 'Pozvat partnera' on the client detail: name and e-mail (optionally filled from a contact), the D-03 rules on the e-mail field, calls InvitePartner with the signed-in Admin, hidden on an archived client"
  - "'Pozvánky' tab (InvitationsRelationManager): Admin-only list with state badge, expiry, send count and last sent; resend and revoke for pending or expired invitations only; no create, edit or delete"
  - "Client::invitations() relation"
  - "ResendInvitation refuses an archived client (closes the open point carried from 04-17 and 04-18)"
affects: [04-20]

actuals:
  tokens: 8300
  tasks: 2
  commits: 3
plan_head_before: 4ca71b34f8e1374a7385e76bb9489fc93340a321
plan_head_after: 025c219d0b1a43686a9ed98f4d84f06e99b8598d

tech-stack:
  added: []
  patterns:
    - "Header action whose email field reuses the Action's own ValidationRule classes, with the Action re-validating and its ValidationException remapped to the modal state path (mountedActions.0.data.*)"
    - "Record actions hidden by the derived state, with the Action re-checking on the locked row"

key-files:
  created:
    - app/Filament/Resources/ClientResource/RelationManagers/InvitationsRelationManager.php
    - tests/Feature/Clients/InvitationManagementTest.php
  modified:
    - app/Filament/Resources/ClientResource/Pages/ViewClient.php
    - app/Filament/Resources/ClientResource.php
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Clients/Actions/ResendInvitation.php
    - lang/cs/kokpit.php

key-decisions:
  - "ResendInvitation refuses an archived client with its own DomainException message (resend_client_archived); revoke stays allowed for an archived client, since cancelling a link is always safe"
  - "The tab hides resend for an archived client and both actions for accepted or revoked invitations; an expired invitation can be resent (it becomes pending again) or revoked"
  - "An error that belongs to no form field (an archived client reaching the Action) is shown on the e-mail field of the invite modal"
  - "The contact picker is dehydrated(false) and only offers contacts of this client, so the pick is never part of the submitted data and cannot reference another client"

patterns-established:
  - "Lifecycle record actions in a relation manager: visibility from the derived state, DomainException from the Action turned into a danger notification"

requirements-completed: [US-02]

coverage:
  - id: D1
    description: "From the client detail the Admin invites a Partner by name and e-mail: one pending invitation of that client, invited by the Admin, one queued mail, and the mailed link opens the guest page"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#invites a Partner from the client detail: one pending invitation, one mail, a link that opens the guest page (US-02, D-01)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Picking a contact of the client fills name and e-mail; a contact of another client fills nothing; the pick is not submitted"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#fills name and e-mail from a picked contact of the client and never submits the pick itself"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Admin e-mail, a Partner e-mail in upper case and an e-mail with an open invitation (in either case) are Czech errors on the e-mail field of the invite form and create nothing; name and e-mail format are required"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#shows a duplicate e-mail as a Czech error on the e-mail field and creates nothing (D-03)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#requires a name and a valid e-mail in the invite form"
        status: pass
    human_judgment: false
  - id: D4
    description: "The invite action is hidden on an archived client; a Partner is refused the client detail and so cannot mount it"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#hides the invite action on an archived client and offers it on an active one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#refuses a Partner the client detail, so the invite action cannot be mounted"
        status: pass
    human_judgment: false
  - id: D5
    description: "The 'Pozvánky' tab lists only the client's invitations with the Czech state label; resend sends a second mail and bumps the count, revoke turns the state into Zrušena and hides both actions, an expired invitation can be resent, an accepted one offers neither action"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#lists the invitations of the client with the Czech state label and none of another client (D-02)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#resends a pending invitation from the tab: a second mail, a bumped send count"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#resends an expired invitation and shows it as pending again"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#revokes an invitation from the tab, shows Zrušena and offers no action any more"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#offers neither action on an accepted invitation"
        status: pass
    human_judgment: false
  - id: D6
    description: "The tab offers no create, edit or delete action; a Partner is refused the relation manager at boot; resend is refused by the Action and hidden in the tab for an archived client"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#offers no create, edit or delete of an invitation in the tab (invitations change only through the Actions)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#refuses a Partner the invitations tab at boot and lists the tab in the client resource"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/InvitationManagementTest.php#refuses the Action to resend an invitation of an archived client and the tab hides the actions"
        status: pass
    human_judgment: false
  - id: D7
    description: "The look of the invite modal, the badges and the confirmation dialogs in a real browser"
    verification: []
    human_judgment: true
    rationale: "Layout, wording and colour of the modal, the state badges and the confirmations are visual judgments no test asserts"

duration: 7min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 19: Invitation management in the client detail Summary

**Admin invites a Partner from the client detail with the duplicate-account and open-invitation checks on the e-mail field, and manages the invitations (state badge, resend, revoke) in an Admin-only 'Pozvánky' tab**

## Performance

- **Duration:** about 7 min
- **Started:** 2026-10-08T15:49:08Z
- **Completed:** 2026-10-08T15:56:00Z
- **Tasks:** 2
- **Files modified:** 7 (2 created, 5 modified)

## Accomplishments
- `ViewClient` has the header action `invitePartner` ('Pozvat partnera'): name and e-mail, optionally filled from a contact of the client, with `EmailHasNoAccount` and `EmailHasNoOpenInvitation` on the e-mail field so a duplicate shows as a Czech field error before anything runs; `InvitePartner` re-validates, and its errors are remapped onto the modal fields. The action is hidden on an archived client.
- `InvitationsRelationManager` ('Pozvánky', `Audience::AdminOnly`, boot check through `EnforcesRelationManagerAccessRule`) lists the client's invitations with a state badge (Čeká, Přijata, Zrušena, Vypršela), expiry, send count and last sent time. 'Odeslat znovu' and 'Zrušit' (both with confirmation) appear only for pending or expired invitations and call `ResendInvitation` and `RevokeInvitation`. The tab has no create, edit, delete or bulk action.
- `ResendInvitation` now refuses an archived client, closing the open point carried from 04-17 and 04-18; the tab also hides the resend action for an archived client.

## Task Commits

1. **Task 1: Tracer - Admin invites a Partner from the client detail** - `bbcf0f3` (feat). The tracer verification (the management test file, full suite, Pint, PHPStan) passed end to end before Task 2 started.
2. **Task 2: 'Pozvánky' tab with state badges, resend and revoke (TDD)** - `1d679eb` (test, RED: 9 new tests failed), `025c219` (feat, GREEN)

Full suite at the end: 1518 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `app/Filament/Resources/ClientResource/Pages/ViewClient.php` - the `invitePartner` header action
- `app/Filament/Resources/ClientResource/RelationManagers/InvitationsRelationManager.php` - the Admin-only tab with resend and revoke
- `app/Filament/Resources/ClientResource.php` - lists the tab in `getRelations()`
- `app/Domain/Clients/Models/Client.php` - `invitations(): HasMany`
- `app/Domain/Clients/Actions/ResendInvitation.php` - refuses an archived client
- `lang/cs/kokpit.php` - section `invitations.admin` and the `resend_client_archived` error
- `tests/Feature/Clients/InvitationManagementTest.php` - 14 tests

## Decisions Made
- Resend is refused for an archived client (Action and tab); revoke stays possible, because cancelling a link is always safe.
- An error without a form field of its own (archived client reaching the Action) is shown on the e-mail field of the modal.
- The contact picker is not dehydrated and offers only the client's own contacts.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] ResendInvitation did not refuse an archived client**
- **Found during:** Task 2 (carried open point named in the 04-17 and 04-18 summaries and the dispatch note)
- **Issue:** an Admin could mail a fresh link for an archived client; the accept step would refuse it, but the Admin UI must not offer it
- **Fix:** the locked transaction reads the client (system run) and throws a `DomainException` with a new Czech message; the tab hides the action for an archived client
- **Files modified:** `app/Domain/Clients/Actions/ResendInvitation.php` (not in the plan's `files_modified`), `lang/cs/kokpit.php`
- **Verification:** `refuses the Action to resend an invitation of an archived client and the tab hides the actions` passes; the 04-17 resend tests still pass
- **Committed in:** `025c219`

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** one extra file; the plan explicitly allowed handling the open point.

## Issues Encountered
- A planned test for the notification shown when a resend or revoke is refused after the row changed could not be written at the UI level: Filament re-evaluates visibility on the call, so a row that was revoked meanwhile makes the action hidden and uncallable. The refusal itself is covered at Action level (04-17 tests); the `DomainException` to danger-notification branch in the relation manager remains as a guard for a true concurrent request and is not covered by a test.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat register. The invite action lives on the Admin-only client resource and the tab is Admin-only with a boot-time 403 (T-04-46 mitigated and tested; T-04-SC accepted, no package added).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Plan 04-20 can add the Partner accounts tab and the password reset beside the 'Pozvánky' tab; accepted invitations carry `accepted_user_id`.
- Visual check of the modal, badges and confirmations in a browser is left to UAT (D7).

## Self-Check: PASSED

- Created files present: InvitationsRelationManager, InvitationManagementTest
- Commits `bbcf0f3`, `1d679eb`, `025c219` are on the branch
- All acceptance criteria of both tasks pass; plan-level verification (full Pest 1518 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) clean

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
