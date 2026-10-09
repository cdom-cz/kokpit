---
phase: 04-clients-and-projects
plan: 13
subsystem: ui
tags: [filament, relation-manager, contacts, primary-flag, row-lock, activity-log, select-sub]

requires:
  - phase: 04-clients-and-projects
    provides: Contact model, CreateContact Action, ContactsRelationManager and the contacts_one_primary_per_client index (04-12); ClientResource with the Admin-only list and the activity allowlist harness (04-09 to 04-11)
provides:
  - UpdateContact Action (name, e-mail, phone, position, billing flag; never the primary flag or the client)
  - SetPrimaryContact Action (demote then promote in one transaction, client row and contact row locked)
  - DeleteContact Action (refuses the primary while other contacts exist; the only contact may go)
  - ContactInput (shared validation and normalisation for create and update)
  - Edit, delete and "Nastavit jako primární" row actions in the Kontakty tab
  - primary_contact_name subquery column in the client list
  - Contact in the activity allowlist with Czech subject and attribute labels
affects: [04-19 invitations, phase 10 invoices (billing recipients), 04-21 phase verification]

actuals:
  tokens: 6980
  tasks: 2
  commits: 3
plan_head_before: 75dd0f40595df2568a052f3fe0799596b7515b8f
plan_head_after: 8433dd2d3f0132f0050c20287583079f89b432f9

tech-stack:
  added: []
  patterns:
    - "A relation manager form() holds the edit fields only; the create modal adds its non-column inputs (make_primary) through CreateAction::schema()"
    - "A DeleteAction whose Action may refuse sets failureNotificationTitle and returns false from using(), so the Admin sees the reason instead of a field error"
    - "A per-row value from another table is a selectSub column in getEloquentQuery(), never a lazy relation in the column"

key-files:
  created:
    - app/Domain/Clients/Actions/ContactInput.php
    - app/Domain/Clients/Actions/UpdateContact.php
    - app/Domain/Clients/Actions/SetPrimaryContact.php
    - app/Domain/Clients/Actions/DeleteContact.php
  modified:
    - app/Domain/Clients/Actions/CreateContact.php
    - app/Domain/Clients/Models/Contact.php
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Feature/Clients/ContactsTest.php
    - lang/cs/kokpit.php

key-decisions:
  - "Contact validation extracted from CreateContact into the shared ContactInput class, as announced in the 04-12 summary"
  - "SetPrimaryContact and DeleteContact re-read the contact row under lockForUpdate after locking the client row, so a stale Livewire instance cannot act on outdated flags"
  - "The delete refusal is a notification on the delete action (failureNotificationTitle), the edit refusals are field errors under the modal state path"
  - "The primary_contact_name subquery sits in ClientResource::getEloquentQuery() as the plan's key link states, with select('clients.*') made explicit"

patterns-established:
  - "Flag ownership stays in dedicated Actions: UpdateContact never writes is_primary, SetPrimaryContact is the only mover, CreateContact the only creator of a primary"
  - "Two-statement flag move (demote through the model, then promote) inside a transaction under the parent row lock"

requirements-completed: [CL-02]

coverage:
  - id: D1
    description: "Admin moves the primary flag in the Kontakty tab; the old primary is demoted first in one locked transaction and exactly one primary remains"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it moves the primary flag to another contact and leaves exactly one primary"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it locks the client row while it moves the primary flag"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it moves the primary flag from the Kontakty tab and hides the action on the primary row"
        status: pass
    human_judgment: false
  - id: D2
    description: "The primary cannot be deleted while other contacts exist; the only contact can be deleted and the next one becomes primary"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it refuses to delete the primary while other contacts exist and deletes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it lets the primary go once it is the only contact, and the next contact becomes the primary"
        status: pass
    human_judgment: false
  - id: D3
    description: "UpdateContact edits the data fields and never the primary flag or the client, even with a crafted payload; the client's invoice e-mail stays independent of contact flags"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it ignores a primary flag in the payload of UpdateContact and of the edit modal"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it has no primary field in the edit modal"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it keeps the invoice e-mail of the client apart from the contact flags"
        status: pass
    human_judgment: false
  - id: D4
    description: "The client list shows the primary contact's name from a subquery without a contact query per row"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it names the primary contact of a page of 10 clients without a contact query per client"
        status: pass
    human_judgment: false
  - id: D5
    description: "Contact changes are recorded in the activity log under the subject Kontakt, and the allowlist test lists Client, Contact, Project and ProjectBilling"
    requirement: CL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ContactsTest.php#it logs a change of the e-mail of a contact under the log name contact listing email"
        status: pass
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php#it lists the application models that log activity explicitly"
        status: pass
    human_judgment: false
  - id: D6
    description: "Layout, wording and confirmation texts of the edit, delete and make-primary actions and of the new list column"
    verification: []
    human_judgment: true
    rationale: "Visual adequacy and Czech wording are not asserted by any test; Livewire tests exercise behaviour only."

duration: 7min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 13: Contact maintenance - edit, primary switch, protected delete, list column and audit Summary

**UpdateContact, SetPrimaryContact (demote then promote under a client row lock) and DeleteContact (primary protected while others exist) behind edit, make-primary and delete row actions of the Kontakty tab, plus a subquery primary-contact column in the client list and an allowlisted activity trail for contacts.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-10-08T14:41:28Z
- **Completed:** 2026-10-08T14:48:30Z
- **Tasks:** 2
- **Files modified:** 11 (4 created, 7 modified)

## Accomplishments

- `SetPrimaryContact` locks the client row and the contact row, demotes the current primary through the model, then promotes the given contact; calling it on the current primary writes nothing. Other clients' primaries are untouched.
- `DeleteContact` throws a `ValidationException` with `kokpit.contacts.errors.primary_delete` for the primary of a client with other contacts and deletes nothing; a non-primary or the only contact is deleted. The relation manager shows the refusal as a notification.
- `UpdateContact` takes name, e-mail, phone, position and the billing flag through the shared `ContactInput`; `is_primary`, `make_primary` and `client_id` in the payload are ignored, and the edit modal has no primary field (the create modal keeps its `make_primary` checkbox through `CreateAction::schema()`).
- The client list has a "Hlavní kontakt" column fed by the `primary_contact_name` `selectSub` in `ClientResource::getEloquentQuery()`; a page of 10 clients runs no separate contact query.
- `Contact` uses `LogsAllowlistedActivity` with seven allowlisted attributes; the primary switch logs both the demotion and the promotion; Czech subject "Kontakt" and attribute labels added; `ActivityAllowlistTest` lists `[Client, Contact, Project, ProjectBilling]`.

## Task Commits

1. **Task 1: Tracer - primary flag move, edit and protected delete in the Kontakty tab** - `2f4cb18` (feat)
2. **Task 2: Primary contact column and contact auditing** - `8aa2f33` (test, RED) and `8433dd2` (feat, GREEN)

**Plan metadata:** committed with the docs commit that follows this summary.

_Tracer gate: `<verify>` of Task 1 (targeted tests, full suite, Pint, PHPStan) passed end to end before Task 2 started; auto mode, so no checkpoint. Final full suite: 1385 tests passed, Pint and PHPStan clean._

## Files Created/Modified

- `app/Domain/Clients/Actions/ContactInput.php` - shared validation and normalisation of the contact data fields
- `app/Domain/Clients/Actions/UpdateContact.php` - edit without the primary flag
- `app/Domain/Clients/Actions/SetPrimaryContact.php` - locked demote-then-promote switch
- `app/Domain/Clients/Actions/DeleteContact.php` - delete that protects the primary
- `app/Domain/Clients/Actions/CreateContact.php` - now uses ContactInput
- `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php` - edit, delete and make-primary row actions
- `app/Filament/Resources/ClientResource.php` - `primary_contact_name` subquery and column
- `app/Domain/Clients/Models/Contact.php` - activity allowlist
- `tests/Arch/ActivityAllowlistTest.php`, `tests/Feature/Clients/ContactsTest.php` - 19 new tests
- `lang/cs/kokpit.php` - actions, notifications, error, column label, activity subject and attribute labels

## Decisions Made

- Validation extracted into `ContactInput` (announced in the 04-12 summary); `CreateContact` and `UpdateContact` share it.
- The mutating Actions re-read the contact under `lockForUpdate` after taking the client lock, so a stale instance from an open Livewire modal cannot move or delete on outdated flags.
- The delete refusal surfaces as the delete action's failure notification; edit validation errors are re-keyed to the modal state path like the create modal.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added ContactInput outside the plan's file list**
- **Found during:** Task 1
- **Issue:** UpdateContact needs the validation that lived privately in CreateContact; duplicating it would drift. The 04-12 summary announced this extraction for 04-13, but `files_modified` does not list the new file.
- **Fix:** Created `app/Domain/Clients/Actions/ContactInput.php` and pointed CreateContact at it; behaviour of CreateContact unchanged (its tests pass untouched).
- **Files modified:** `app/Domain/Clients/Actions/ContactInput.php`, `app/Domain/Clients/Actions/CreateContact.php`
- **Verification:** `ContactsTest.php` green, PHPStan clean.
- **Committed in:** 2f4cb18

---

**Total deviations:** 1 auto-fixed (1 blocking). **Impact on plan:** none on behaviour; one extra small class instead of duplicated rules.

## Issues Encountered

- A scripted edit of `lang/cs/kokpit.php` first put the new `primary_contact` label into the activity attribute block, and a duplicated `use` pair landed in `Contact.php`; both were caught by the test run (fatal on the duplicate import) and corrected before any commit.

## Known Stubs

None.

## Threat Flags

None. T-04-27 (contact changes without a trail) is mitigated by the allowlisted activity logging on `Contact`; T-04-55 (primary flag duplicated or orphaned) by the locked demote-then-promote, the flag-free `UpdateContact`, the protected `DeleteContact` and the partial unique index. Not covered by a test: two Admin sessions switching the primary of the same client at the same moment in parallel processes (serialised by the client row lock, the index is the backstop), as flagged in the plan.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Contacts are complete for CL-02: create, edit, switch, protected delete, list column, audit. Ready for 04-14.

## Self-Check: PASSED

Created files exist on disk, commits `2f4cb18`, `8aa2f33` and `8433dd2` are ancestors of HEAD, the full suite (1385 tests), Pint and PHPStan are green, and the acceptance criteria of both tasks passed.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
