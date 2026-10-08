---
phase: 04-clients-and-projects
plan: 11
subsystem: ui
tags: [filament, soft-delete, spatie-tags, activity-log, unique-index, currency-lock]

requires:
  - phase: 04-clients-and-projects
    provides: ClientResource, CreateClient/UpdateClient/ClientInput (04-09, 04-10), ProjectBilling::clientHoldsMoney (04-07), Project detach-tags guard and ProjectResource archive pattern (04-04, 04-08), Partner lockout in canAccessPanel (04-01)
  - phase: 03-operations-foundation
    provides: LogsAllowlistedActivity, ActivityHistoryRelationManager
provides:
  - ArchiveClient and RestoreClient domain Actions (idempotent soft delete and restore, stale-instance safe)
  - ClientResource with a ViewClient detail page, trashed filter, archive (Archivovat) and restore (Obnovit) actions in table, bulk and page, and no force delete anywhere
  - Client tags of type client (input, column, detail entry) that survive archive and restore through the detachTags guard
  - Client activity allowlist and the Admin-only ClientHistoryRelationManager
  - Currency lock in UpdateClient while any project of the client holds a rate or fixed price
  - Per-country company number uniqueness as a company_number field error naming an archived holder
affects: [04-12 contacts, 04-13 contacts, 04-14 company number checksum, 04-19 invitations, 04-20 partner accounts, phase 5 tasks]

actuals:
  tokens: 15923
  tasks: 3
  commits: 4
plan_head_before: 7385d29a85fa00add8f53f5d7f787af8c9d47718
plan_head_after: a36ea2ec20b0fc43053360e3545b3441e414cddb

tech-stack:
  added: []
  patterns:
    - "Resource archive actions are configured by static helpers on the resource (archiveAction, restoreAction, archiveBulkAction, restoreBulkAction) and call the domain Action through using(); the page header actions reuse them"
    - "A unique-index violation is caught outside the DB transaction in the Action, matched on the index name, and turned into a field error by a ClientInput helper that reads the holder with withTrashed()"
    - "Admin-only resource pages abort 403 in mount() before the deny-all scope can answer 404 (EditClient, now ViewClient)"

key-files:
  created:
    - app/Domain/Clients/Actions/ArchiveClient.php
    - app/Domain/Clients/Actions/RestoreClient.php
    - app/Filament/Resources/ClientResource/Pages/ViewClient.php
    - app/Filament/RelationManagers/ClientHistoryRelationManager.php
    - tests/Feature/Clients/ClientArchiveTest.php
    - tests/Feature/Clients/ClientTagsAndHistoryTest.php
    - tests/Feature/Clients/ClientRulesTest.php
  modified:
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/Pages/EditClient.php
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Clients/Actions/ClientInput.php
    - app/Domain/Clients/Actions/CreateClient.php
    - app/Domain/Clients/Actions/UpdateClient.php
    - lang/cs/kokpit.php
    - tests/Arch/ActivityAllowlistTest.php

key-decisions:
  - "ArchiveClient and RestoreClient refresh the model before checking trashed(), so a stale instance cannot overwrite deleted_at on a second archive"
  - "The index-name match lives in CreateClient and UpdateClient (any other unique violation is rethrown); ClientInput::refuseCompanyNumber only builds the field error and looks up the holder"
  - "The currency lock is checked before the transaction and only when the normalised currency differs from the stored one, so other edits stay possible while project money exists"

patterns-established:
  - "Archive and restore of an Admin-only record: soft delete Action, trashed filter, query overrides removing only SoftDeletingScope, no force delete, detachTags guard on tagged models"
  - "A new logging model adds itself to the explicit list in ActivityAllowlistTest and gets its Czech subject and attribute labels under kokpit.activity"

requirements-completed: [CL-05, CL-01]

coverage:
  - id: D1
    description: "Admin archives a client from the table, bulk actions and detail page; it leaves the list and the project client select, its projects leave Partner views and Project::selectable(), and its Partner is locked out on the next request"
    requirement: CL-05
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it archives a client from the table out of the default list, shows it under the trashed filter and opens it by URL"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it hides the visible project from the Partner, locks the Partner out on the next request and brings everything back with the restore"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it archives and restores several clients with the bulk actions"
        status: pass
    human_judgment: false
  - id: D2
    description: "No action hard-deletes a client: no force-delete action in the table, bulk or page lists, and the database refuses a raw delete while a project references the client"
    requirement: CL-05
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it offers no force delete in the table, the bulk actions or the page actions"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it refuses a raw hard delete of a client that a project references"
        status: pass
    human_judgment: false
  - id: D3
    description: "Archiving an archived client and restoring an active one are no-ops; client tags survive archive and restore and a code force delete detaches them"
    requirement: CL-05
    verification:
      - kind: unit
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it archives an archived client and restores an active one without error and without changing anything"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientArchiveTest.php#it keeps the client tags through archive and restore"
        status: pass
    human_judgment: false
  - id: D4
    description: "Client tags use type client, suggestions never mix with project tags, and a Partner sees no client tag"
    requirement: CL-05
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientTagsAndHistoryTest.php#it suggests only client tags to the client input and only project tags to the project input"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientTagsAndHistoryTest.php#it keeps every client tag away from the Partner of that client"
        status: pass
    human_judgment: false
  - id: D5
    description: "Client detail page shows billing data, terms and tags read-only; an Admin-only history relation manager lists allowlisted client changes"
    requirement: CL-05
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientTagsAndHistoryTest.php#it logs a change of the name and the rate under the log name client with exactly those attributes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientTagsAndHistoryTest.php#it refuses the history relation manager to a Partner at boot"
        status: pass
    human_judgment: false
  - id: D6
    description: "The layout and wording of the detail page, the Czech archive and restore confirmations and the tag chips"
    requirement: CL-05
    verification: []
    human_judgment: true
    rationale: "Visual adequacy and Czech wording of the confirmation texts are not asserted by any test; the page renders and shows the data in tests only."
  - id: D7
    description: "Currency change is a currency field error while any project (archived included) holds a rate or fixed price; without project money currency and rate change together"
    requirement: CL-01
    verification:
      - kind: unit
        ref: "tests/Feature/Clients/ClientRulesTest.php#currency lock"
        status: pass
    human_judgment: false
  - id: D8
    description: "A company number used in the same country is a company_number field error (archived holder named); the same number in another country and clients without a number are accepted"
    requirement: CL-01
    verification:
      - kind: unit
        ref: "tests/Feature/Clients/ClientRulesTest.php#company number"
        status: pass
    human_judgment: false

duration: 13min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 11: Client archive, tags, history and integrity rules Summary

**Reversible client archive and restore through idempotent domain Actions, typed client tags that survive it, an Admin-only client detail page with history, and the currency lock and per-country company number rules as field errors.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-10-08T14:12:06Z
- **Completed:** 2026-10-08T14:25:39Z
- **Tasks:** 3
- **Files modified:** 15 (8 application and localisation files, 4 test files, 3 new in app)

## Accomplishments

- Archive and restore without hard deletion: `ArchiveClient` and `RestoreClient` are no-ops on the wrong state and refresh the model first, so a stale copy cannot move `deleted_at`. The table, bulk and page actions call them through `using()` with Czech confirmations that name the effect (projects hidden from Partners, Partner accounts locked out). No force-delete action exists, and a raw delete with a project reference is refused with 23001.
- The archived client opens by URL and appears under the trashed filter; it leaves the project client select and `Project::selectable()`; its Partner gets 403 on the next request and everything returns on restore.
- Client tags are stored with type `client` (input, column and detail entry), keep their taggables rows through archive and restore thanks to the same `detachTags` guard as `Project`, and never appear among project tag suggestions or on a Partner surface.
- `ViewClient` is the detail page later plans attach contacts, invitations and accounts to; `ClientHistoryRelationManager` (Admin only) lists allowlisted client changes, and `ActivityAllowlistTest` now lists `Client`.
- `UpdateClient` refuses a currency change as a `currency` field error while any project of the client, archived included, holds a rate or fixed price; otherwise currency and rate change together. `CreateClient` and `UpdateClient` turn the per-country company number index into a `company_number` error, with a different message when the holder is archived.

## Task Commits

1. **Task 1: Tracer - archive, restore, pickers and Partner lockout** - `c33a6ab` (feat)
2. **Task 2: Client tags of type client and Admin-only history** - `a0fcdfa` (feat)
3. **Task 3: Currency lock and company number uniqueness** - `3440395` (test, RED) and `a36ea2e` (feat, GREEN)

**Plan metadata:** committed with the docs commit that follows this summary.

## Files Created/Modified

- `app/Domain/Clients/Actions/ArchiveClient.php`, `RestoreClient.php` - idempotent soft delete and restore
- `app/Filament/Resources/ClientResource.php` - view page, infolist, trashed filter, archive and restore helpers, tags, relation manager
- `app/Filament/Resources/ClientResource/Pages/ViewClient.php` - detail page with edit, archive, restore header actions and a 403 mount guard
- `app/Filament/Resources/ClientResource/Pages/EditClient.php` - header actions view, archive, restore
- `app/Domain/Clients/Models/Client.php` - `HasTags` with the detach guard, `LogsAllowlistedActivity` and its 15-attribute allowlist
- `app/Filament/RelationManagers/ClientHistoryRelationManager.php` - Admin-only history
- `app/Domain/Clients/Actions/ClientInput.php`, `CreateClient.php`, `UpdateClient.php` - company number field error, currency lock
- `lang/cs/kokpit.php` - actions, notifications, errors, tag labels, activity subject and attribute labels
- `tests/Feature/Clients/ClientArchiveTest.php`, `ClientTagsAndHistoryTest.php`, `ClientRulesTest.php`, `tests/Arch/ActivityAllowlistTest.php`

## Decisions Made

- Archive and restore refresh the model before checking `trashed()`, so the idempotency holds for a stale Livewire record as well as for a repeated call.
- The unique-index name is matched in the two Actions (any other unique violation is rethrown untouched); `ClientInput::refuseCompanyNumber` builds the field error and reads the holder with `withTrashed()`.
- The currency lock is evaluated only when the normalised currency differs from the stored one, so renaming or changing the rate of a client with project money stays possible.
- The edit page also carries view, archive and restore header actions, so the archive is reachable from the edit form as well as from the detail page.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] 403 guard in ViewClient::mount()**
- **Found during:** Task 1
- **Issue:** The deny-all Partner scope on the client would answer a Partner's view request with 404, while every other Admin-only client route answers 403 (decision 04-09).
- **Fix:** `ViewClient::mount()` aborts 403 unless `ClientResource::canAccess()`, like `EditClient`.
- **Files modified:** `app/Filament/Resources/ClientResource/Pages/ViewClient.php`
- **Verification:** `ClientArchiveTest` "gives a Partner 403 on the detail route of a client"
- **Committed in:** `c33a6ab`

**2. [Rule 1 - Test data] Placeholder company number flagged by the sensitive scanner**
- **Found during:** Task 3 (RED commit)
- **Issue:** A literal second company number in `ClientRulesTest` matched the scanner's company-id rule.
- **Fix:** The second number is assembled at runtime from fragments (`rulesOtherNumber()`), as CLAUDE.md prescribes.
- **Files modified:** `tests/Feature/Clients/ClientRulesTest.php`
- **Committed in:** `3440395`

---

**Total deviations:** 2 auto-fixed (1 missing critical, 1 test data)
**Impact on plan:** Both were needed for correctness or repository hygiene. No scope change.

## Issues Encountered

- A `vendor/bin/pint app tests lang` run (instead of `--test`) reformatted the generated `lang/cs` files that Pint excludes by exact path in the project config; they were restored with `git checkout -- <file>` before any commit. No unrelated file was committed.
- The `hourly_rate_currency = currency` CHECK on `clients` means a currency change always needs a rate in the new currency in the same call, which `ClientInput` already enforces; no change was needed.

## Known Stubs

None.

## Threat Flags

None. The new detail route is Admin-only (403 for a Partner, proven), and the new relation manager is Admin-only at boot.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `ViewClient` and `ClientResource::getRelations()` are ready for the contacts relation manager (plans 04-12, 04-13) and the invitation and account sections (plans 04-19, 04-20).
- Plan 04-14 adds the CZ company number checksum; `ClientRulesTest` currently uses the placeholder `12345678` and that plan switches it to a runtime-generated valid number.
- The currency lock reads `project_billing`; plan 04-12 onwards needs no change to it.

## Self-Check: PASSED

All created files exist on disk, commits `c33a6ab`, `a0fcdfa`, `3440395` and `a36ea2e` are ancestors of HEAD, the full suite (1353 tests), Pint and PHPStan are green, and every task's acceptance criteria passed.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
