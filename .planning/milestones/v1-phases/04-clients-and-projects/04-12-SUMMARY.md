---
phase: 04-clients-and-projects
plan: 12
subsystem: ui
tags: [filament, relation-manager, postgres, partial-unique-index, partner-isolation, contacts]

requires:
  - phase: 04-clients-and-projects
    provides: ClientResource with ViewClient and getRelations (04-11), Admin-only model pattern with DeniesPartners (04-01, 04-09), canary registry and arch harness (02-11)
provides:
  - contacts table with the contacts_one_primary_per_client partial unique index
  - Admin-only Contact model (DeniesPartners, AdminOnlyPolicy, morph alias contact) with Client::contacts() and Client::primaryContact()
  - CreateContact Action (first contact primary, makePrimary demotes before promoting, client row locked)
  - Admin-only ContactsRelationManager ("Kontakty" tab) with a create modal and a make-primary checkbox
  - Contact canary fixture and entries in CanaryRegistryTest and ModelDeclarationTest
affects: [04-13 contacts edit/switch/delete/audit, 04-19 invitations, phase 10 invoices (billing recipients)]

actuals:
  tokens: 7080
  tasks: 2
  commits: 3
plan_head_before: 74b724d73acfced19b9adb947005d8057083abcc
plan_head_after: cb002745fbe646e7bb4447f83584032b2b56cb73

tech-stack:
  added: []
  patterns:
    - "A relation manager on the read-only view page overrides isReadOnly() to false when the Admin maintains the related rows there"
    - "Flag ownership stays in the Action: a form checkbox (make_primary) is mapped to an Action argument, the column (is_primary) is never a form field or fillable"
    - "Demote through model saves inside the locked transaction, not a bulk update, so model events (activity log) fire"

key-files:
  created:
    - database/migrations/2026_10_09_000500_create_contacts_table.php
    - app/Domain/Clients/Models/Contact.php
    - app/Domain/Clients/Actions/CreateContact.php
    - app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php
    - tests/Feature/Clients/ContactsTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - app/Filament/Resources/ClientResource.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - lang/cs/kokpit.php

key-decisions:
  - "Contact validation lives privately in CreateContact for now; plan 04-13 extracts it when UpdateContact needs the same rules"
  - "CreateContact locks the client row with withTrashed(), so a contact write for an archived client does not fail with a not-found error"
  - "ValidationException keys from the Action are re-keyed to mountedActions.0.data.<key> inside the create modal's using() closure"

patterns-established:
  - "Contact-style child of Client: Admin-only model, created through $client->contacts()->make(), foreign key never fillable"

requirements-completed: [CL-02]

coverage:
  - id: D1
    description: "Admin adds contacts (name, e-mail, phone, position, billing flag) in a Kontakty tab of the client detail"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it makes the first contact created through the tab the primary contact and the second one not"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it reports the problems of a contact as field errors"
        status: pass
    human_judgment: false
  - id: D2
    description: "First contact becomes primary; make primary demotes the old primary first in one locked transaction; the database refuses a second primary with 23505"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it demotes the old primary and promotes the new contact when make primary is ticked"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it refuses a second primary row of the same client in the database with 23505"
        status: pass
    human_judgment: false
  - id: D3
    description: "Any number of billing contacts per client; a crafted payload cannot set is_primary or client_id"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it lets several contacts of one client be billing contacts"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it does not let a crafted payload set the primary flag or the client"
        status: pass
    human_judgment: false
  - id: D4
    description: "Contacts are closed to Partners: zero rows through the model, AdminOnlyPolicy, canary fixture, relation manager refused at boot"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it gives a Partner zero contacts through the model and refuses the relation manager at boot"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Layout, column order and Czech wording of the Kontakty tab and its create modal"
    verification: []
    human_judgment: true
    rationale: "Visual adequacy and Czech wording are not asserted by any test; Livewire tests exercise behaviour only."

duration: 9min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 12: Client contacts - table, model, creation and Admin-only tab Summary

**Contacts table with a one-primary partial unique index, an Admin-only Contact model and CreateContact Action (first contact primary, make-primary demotes first under a client row lock), and a Kontakty relation manager tab with a create modal.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-10-08T14:29:09Z
- **Completed:** 2026-10-08T14:37:51Z
- **Tasks:** 2
- **Files modified:** 13 (9 application and localisation files, 4 test or test-support files)

## Accomplishments

- `contacts` table (uuidv7 key, restrict FK to `clients`, `is_primary` and `is_billing` booleans) with `contacts_one_primary_per_client` as a partial unique index; a raw second primary for the same client answers 23505, another client's primary and several non-primary rows are accepted.
- `Contact` is `DeniesPartners` plus `AdminOnlyPolicy`: a Partner reads zero rows, and `ContactsRelationManager` is refused at boot. `client_id` and `is_primary` are not fillable (mass assignment throws in the test environment).
- `CreateContact` makes the first contact primary, and with `makePrimary` demotes the current primary before promoting the new one, in one transaction with the client row locked (`lockForUpdate`). Billing contacts are unlimited.
- The "Kontakty" tab on the client detail lists name, e-mail, phone, position and primary/billing icons; the create modal has a "make primary" checkbox mapped to the Action argument, never to a column.
- Canary fixture for `Contact` (name carries the canary) added; `CanaryRegistryTest` and `ModelDeclarationTest` expected lists updated.

## Task Commits

1. **Task 1: Tracer - first contact of a client becomes primary; a Partner sees no contact** - `974a228` (feat)
2. **Task 2: Make-primary on creation, one-primary backstop, several billing contacts** - `70aad21` (test, RED) and `cb00274` (feat, GREEN)

**Plan metadata:** committed with the docs commit that follows this summary.

_Tracer gate: `<verify>` of Task 1 (targeted tests, full suite, Pint, PHPStan) passed end to end before Task 2 started; auto mode, so no checkpoint._

## Files Created/Modified

- `database/migrations/2026_10_09_000500_create_contacts_table.php` - contacts table and the partial unique index
- `app/Domain/Clients/Models/Contact.php` - Admin-only model
- `app/Domain/Clients/Models/Client.php` - `contacts()` and `primaryContact()`
- `app/Domain/Clients/Actions/CreateContact.php` - creation with the primary rules
- `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php` - Admin-only tab with create modal
- `app/Filament/Resources/ClientResource.php` - relation manager registered
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - alias `contact`, `AdminOnlyPolicy`
- `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php` - harness
- `lang/cs/kokpit.php` - `kokpit.contacts` strings
- `tests/Feature/Clients/ContactsTest.php` - 13 tests

## Decisions Made

- Validation rules for contact input sit privately in `CreateContact`; plan 04-13 extracts them when the update path needs the same rules.
- The client row is locked with `withTrashed()`, so contact writes for an archived client do not fail on a missing row.
- The demotion saves each former primary through the model (not a bulk `update`), so the activity log added in plan 04-13 sees it.
- The `make_primary` checkbox is a non-column form field; unknown payload keys such as `is_primary` or `client_id` are dropped by the form and ignored by the Action.

## Deviations from Plan

None - plan executed exactly as written. (The `$makePrimary` parameter arrived in Task 2 rather than Task 1, which matches the task split: Task 1 specifies the first-contact rule only.)

**Total deviations:** 0.

## Issues Encountered

- PHPStan flagged `$name === null` in the validator as always false after the earlier name check; the guard was reduced to `$errors !== []` and the name is cast on return. No behaviour change.

## Known Stubs

None.

## Threat Flags

None. T-04-25 (Partner reads contacts) is mitigated by `DeniesPartners`, `AdminOnlyPolicy`, the Admin-only relation manager with boot check and the canary fixture; T-04-26 (two primaries or none) is mitigated by the non-fillable flag, the locked Action and the partial unique index.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Ready for 04-13: `Contact`, the tab and `CreateContact` exist; edit, the primary switch (`SetPrimaryContact`), protected deletion, the list column and the activity allowlist join there. The `ContactsRelationManager` already overrides `isReadOnly()` so row actions will work on the view page.

## Self-Check: PASSED

All created files exist on disk, commits `974a228`, `70aad21` and `cb00274` are ancestors of HEAD, the full suite (1366 tests), Pint and PHPStan are green, and the acceptance criteria of both tasks passed.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
