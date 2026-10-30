---
phase: 10-invoicing
plan: 10
subsystem: time-tracking-billing
tags: [time-entries, reservation, entry-locked, filament, api, openapi]

requires:
  - phase: 10-invoicing
    provides: "TimeEntry::isReserved(), EntryBilling::reserve()/unreserve(), kokpit.time.errors.reserved (plan 10-09)"
provides:
  - "TimeEntryInput::reserved(): the DomainException with the reserved message"
  - "UpdateTimeEntry refuses a change of start, end, duration, billable flag, client, project or task of a reserved entry on the locked row; description and external pass"
  - "DeleteTimeEntry refuses to delete a reserved entry on the locked row"
  - "EditTimeEntry shows the reserved warning callout and disables the locked fields"
  - "API PATCH/DELETE of a reserved entry answer 409 entry_locked; the endpoint descriptions and 409 responses name the invoice draft"
affects: [10-11, 10-21, 10-40]

actuals:
  tokens: 4500
  tasks: 2
  commits: 2
plan_head_before: 3c111beb13b628a6480948031127c1a952dbd2f0
plan_head_after: e29b81c94a03aa7bdf17119a4d0d3b6ae74b8e15
commits: 2

coverage:
  - deliverable: "A reserved entry's start, end, duration, billable flag, client, project and task cannot be changed and it cannot be deleted; the description stays editable; refusal decided on the row locked by the Action, not the caller's stale model"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#refuses a change of time, flag or context of a reserved entry and lets the description through"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#refuses to delete a reserved entry, and both work again after it is unreserved"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#decides the reserved refusal on the locked row, not on the model the caller holds"
        status: pass
    human_judgment: false
  - deliverable: "The edit page of a reserved entry shows the warning callout, disables the locked fields, keeps the description editable and saves it"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#shows the reserved callout on the edit page, disables the locked fields and still saves the description"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/TimeEntryActionsTest.php#keeps the edit page of a free entry editable and without the reserved callout"
        status: pass
    human_judgment: false
  - deliverable: "The API answers 409 entry_locked with the reserved message on PATCH of a time field and on DELETE, lets the description through, and the generated OpenAPI says a reserved entry is locked"
    verification:
      - kind: test
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#answers 409 entry_locked for a change of a reserved entry, lets its description through and refuses its deletion"
        status: pass
      - kind: test
        ref: "tests/Feature/Api/TimeEntryWriteTest.php#documents that a reserved entry is locked like a billed one"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "A disabled Filament field is not dehydrated, so a locked-field form sends only the editable keys and the page narrows the Action payload to the description"

key-files:
  created: []
  modified:
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
    - app/Domain/TimeTracking/Actions/DeleteTimeEntry.php
    - app/Filament/Resources/TimeEntryResource.php
    - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
    - app/Http/Controllers/Api/V1/TimeEntryController.php
    - tests/Feature/TimeTracking/TimeEntryActionsTest.php
    - tests/Feature/Api/TimeEntryWriteTest.php

key-decisions:
  - "R-10-1 applied: a reserved entry is locked instead of the UI-SPEC non-blocking callout; changing its time requires unlinking it first (D-05). Plan 10-21 amends UI-SPEC Surface M and row N15."
  - "R-10-2 applied: description and the API external object stay writable on a reserved entry; neither is billed."
  - "Context and billable changes of a reserved entry are refused before the context is validated, so the reserved message wins over a field error; times are refused after parsing, by comparing the dirty start and end, so an identical value is not a change."

patterns-established:
  - "Reservation refusal lives in the Actions under the row lock; the API maps any DomainException to entry_locked, so no controller change was needed beyond the documentation"

duration: 25 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 10: Reserved time entries are locked Summary

**An entry held by an invoice draft keeps its time, billable flag and context in the panel, the edit page and the API, with the refusal decided on the locked row and explained in Czech and in the OpenAPI text.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 8 modified, none created

## Accomplishments

- `TimeEntryInput::reserved()` and the refusals in `UpdateTimeEntry` and `DeleteTimeEntry`, placed after the billed check under the existing `FOR UPDATE` lock. A stale model that still says "free" is refused because the decision reads the locked row.
- `UpdateTimeEntry`: a changed client, project, task or billable flag is refused before the context rules run; a changed start or end is refused after parsing, by comparing against the stored instants, so re-sending the stored values or only a description saves.
- `EditTimeEntry` prepends a warning callout with the reserved message and passes `reserved` to `TimeEntryResource::entryFields()`, which disables the client, project, task, start, end and billable fields. The description stays editable and saves; the page then sends only the description to the Action.
- API: PATCH of a time field and DELETE of a reserved entry answer 409 `{"message": ..., "code": "entry_locked"}` through the existing DomainException mapping; the update and delete descriptions and both 409 `#[Response]` texts name "reserved by an invoice draft" (4 occurrences in the controller).
- Tracer re-verified end to end before Task 2: TimeTracking suite 427 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1 (tracer): Action refusals, edit page callout and disabled fields** - `a18ef2f` (feat)
2. **Task 2: API 409 tests and documentation** - `e29b81c` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/TimeTracking`: 427 passed.
- `ddev exec vendor/bin/pest tests/Feature/Api`: 515 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `TimeEntryResource.php` modified though not in `files_modified`**
- **Found during:** Task 1
- **Issue:** The locked fields live in the shared static builder `TimeEntryResource::entryFields()`; the edit page cannot disable them without a parameter there.
- **Fix:** Added an optional `bool $reserved = false` parameter that applies `->disabled($reserved)` to the six fields. The create page and the running-entry modal pass nothing and are unchanged.
- **Files modified:** `app/Filament/Resources/TimeEntryResource.php`
- **Commit:** a18ef2f

**2. [Rule 1 - Bug] Disabled fields were read as changes by the page payload**
- **Found during:** Task 1 (edit page test failed: the description did not save)
- **Issue:** `TimeEntryResource::actionData()` always returns every key with null for a missing one, so the disabled fields of a reserved entry reached the Action as a cleared context and were refused.
- **Fix:** `EditTimeEntry::handleRecordUpdate()` narrows the payload to `description` while the record is reserved.
- **Files modified:** `app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php`
- **Commit:** a18ef2f

The delete action of the list still shows "Smazat záznam" for a reserved entry (its visibility only excludes billed and running entries); the Action refuses it and the existing handler shows the reserved message as a danger toast. Hiding it is left to the plan that adds the draft link to the screens (10-40).

## Known Stubs

None.

## Threat Flags

None. T-10-26 and T-10-27 are mitigated by the refusal under the row lock in the Actions and tests on the panel and the API; no new endpoint, auth path or file access.

## Notes for later plans

- Plan 10-21 amends UI-SPEC Surface M and row N15 (R-10-1).
- Plan 10-40 adds the link to the draft on the callout and may hide the list delete action for a reserved entry.
- Plan 10-11 releases reserved entries through `EntryBilling::unreserve()`; this plan's tests reserve through `EntryBilling::reserve()` as system and do not depend on it.

## Self-Check: PASSED

Modified files exist; commits `a18ef2f` and `e29b81c` are ancestors of HEAD; the commit count measured from the plan ledger is 2.
