---
phase: 04-clients-and-projects
plan: 16
subsystem: clients
tags: [invitations, partner, sha-256, postgres, partial-unique-index, validation-rule]

requires:
  - phase: 04-clients-and-projects
    provides: "Client model and archive (04-01..04-13), canary harness and AdminOnlyPolicy wiring (Phase 2)"
provides:
  - "client_invitations table: hashed token, derived-state constraints, partial unique index on open e-mail"
  - "ClientInvitation model, Admin-only (DeniesPartners, AdminOnlyPolicy, canary fixture, morph alias client_invitation)"
  - "InvitePartner Action issuing an invitation without creating a user"
  - "EmailHasNoAccount and EmailHasNoOpenInvitation ValidationRule classes shared with the Admin invite form"
  - "config kokpit.invitations.ttl_days = 7"
affects: [04-17, 04-18, 04-19]

actuals:
  tokens: 6000
  tasks: 2
  commits: 3
plan_head_before: 19dc893a58022843a4f46be373f842d16479e00e
plan_head_after: 4aec29a1b80dc16ad63b15f19310565c1c2ad7c2

tech-stack:
  added: []
  patterns:
    - "Invitation state derived from accepted_at, revoked_at, expires_at; never stored"
    - "Token = bin2hex(random_bytes(32)); only hash('sha256') persisted and hidden from serialisation"
    - "Cross-scope uniqueness lookups in ValidationRule classes run as explicit PartnerContext system runs (DB::table is forbidden by QueryEscapeHatchTest)"

key-files:
  created:
    - database/migrations/2026_10_09_000600_create_client_invitations_table.php
    - app/Domain/Clients/Models/ClientInvitation.php
    - app/Domain/Clients/Actions/InvitePartner.php
    - app/Domain/Clients/Rules/EmailHasNoAccount.php
    - app/Domain/Clients/Rules/EmailHasNoOpenInvitation.php
    - tests/Feature/Clients/PartnerInvitationTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - config/kokpit.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php

key-decisions:
  - "InvitePartner locks the client row (withTrashed, lockForUpdate) and re-reads it, so an archive committed after the instance was loaded still refuses the invitation; error is keyed on client"
  - "The duplicate-account and open-invitation rules read through Eloquent inside PartnerContext::runAsSystem, because QueryEscapeHatchTest forbids DB::table in app/"
  - "A lost race on client_invitations_open_email_unique is caught (savepoint) and mapped to the same resend message on the email field"

patterns-established:
  - "Admin-only record models: DeniesPartners + AdminOnlyPolicy + canary fixture carrying the canary in the name"

requirements-completed: [US-02]

coverage:
  - id: D1
    description: "Inviting stores one client_invitations row with lower-cased e-mail, SHA-256 token hash, 7-day expiry, inviter, send count and creates no users row"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#stores one hashed, expiring invitation and creates no user (D-01, D-02)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The plain token is never stored, serialised or activity-logged"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#keeps the token hash out of the serialised model and stores no other 64-character value"
        status: pass
    human_judgment: false
  - id: D3
    description: "E-mails of existing accounts (any case) and of open invitations are refused on the email field; database partial unique index backs it (23505)"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#refuses an e-mail that already belongs to the Admin or to a Partner of any client, in any letter case (D-03)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#refuses a second open invitation for one e-mail in the database with a unique violation"
        status: pass
    human_judgment: false
  - id: D4
    description: "Invitations for an archived client are refused; ClientInvitation is invisible to Partners"
    requirement: US-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/PartnerInvitationTest.php#refuses to invite for an archived client and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 16: Partner invitation record Summary

**Admin-only client_invitations records with SHA-256 hashed 256-bit tokens, 7-day expiry, and D-03 refusals (existing account, open invitation, archived client) enforced both in shared ValidationRule classes and by a partial unique index**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-08T15:16:06Z
- **Tasks:** 2
- **Files modified:** 13

## Accomplishments
- `client_invitations` table with email lower-case, single-outcome and accepted-pair CHECK constraints, unique token hash, and the partial unique index `client_invitations_open_email_unique`.
- `ClientInvitation` closed to Partners (DeniesPartners, AdminOnlyPolicy, morph alias, canary fixture); `token_hash` hidden; not activity-logged.
- `InvitePartner` stores the hash only, creates no user, and refuses existing accounts, open invitations and archived clients.
- Rules `EmailHasNoAccount` / `EmailHasNoOpenInvitation` ready for the Admin invite form of plan 04-19.

## Task Commits

1. **Task 1: Tracer - InvitePartner stores a hashed, expiring, Admin-only invitation** - `49d6b8e` (feat)
2. **Task 2: duplicate-account / open-invitation rules, archived-client refusal** - `64582c2` (test, RED), `4aec29a` (feat, GREEN)

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `database/migrations/2026_10_09_000600_create_client_invitations_table.php` - table, checks, partial unique index
- `app/Domain/Clients/Models/ClientInvitation.php` - Admin-only model, relations client/inviter/acceptedUser
- `app/Domain/Clients/Actions/InvitePartner.php` - issues an invitation in one transaction
- `app/Domain/Clients/Rules/EmailHasNoAccount.php`, `EmailHasNoOpenInvitation.php` - D-03 rules
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php`, `config/kokpit.php`, `lang/cs/kokpit.php` - alias, policy, ttl config, Czech messages
- `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Feature/Clients/PartnerInvitationTest.php` - harness and tests

## Decisions Made
- Archived-client check happens inside the transaction against a locked, re-read client row, so a stale instance cannot slip through.
- Rule lookups use Eloquent inside an explicit system run (the arch test forbids `DB::table` in `app/`).
- A concurrent duplicate that passes validation is caught on the unique index and surfaced as the resend message.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Rules rewritten from DB::table to Eloquent system runs**
- **Found during:** Task 2 full-suite run
- **Issue:** `QueryEscapeHatchTest` rejects `DB::table(` in `app/`; the first version of both rules used it
- **Fix:** Lookups through `User::query()` / `ClientInvitation::query()` inside `PartnerContext::runAsSystem`
- **Files modified:** app/Domain/Clients/Rules/EmailHasNoAccount.php, EmailHasNoOpenInvitation.php
- **Verification:** full suite green (1473 passed)
- **Committed in:** 4aec29a

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** None on behaviour; the allowlist of the arch test stays empty.

## Issues Encountered
None. The lost-race branch (unique violation mapped to the email error) has no dedicated test, because it needs two concurrent writers; the 23505 database test proves the index behind it.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
Plan 04-17 can add the queued e-mail with the signed link, derived `state()`, `findAcceptable()`, resend and revoke on top of this record. Plan 04-19 attaches the two rules to the invite form.

## Self-Check: PASSED

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
