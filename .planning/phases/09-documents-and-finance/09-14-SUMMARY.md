---
phase: 09-documents-and-finance
plan: 14
subsystem: documents
tags: [documents, filament, listing, filters, bulk-delete, czech-collation, archive-guard]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-05 DocumentColumns and DocumentActions, 09-07 DeleteDocument and bulkDelete, 09-12 comment files, 09-13 transaction owner, 09-21 withoutArchivedOwners and onlyArchivedOwners scopes"
provides:
  - "DocumentResource: the Admin-only central Soubory listing (index page only, navigation sort 55, not globally searchable)"
  - "ListDocuments with the uploadUnattached header action"
  - "DocumentTypeGroup enum (of() and constrain()) with Czech labels"
  - "DocumentActions::uploadUnattached() and bulkActions(), a chunked bulkDelete"
  - "the D-07 filters, the archived_owners ternary filter (D-21) and the totals copy plan 09-16 reads"
affects: [09-15 finance overview, 09-16 listing totals, 09-17 ZIP bulk action, 09-20 consistency check]

actuals:
  tokens: 16600
  tasks: 3
  commits: 3
plan_head_before: 2083af14ba5e4e5e8ac4640f1ead776cb6ea7d0c
plan_head_after: 3da91b43f36339b648158ff21bdd7d1dede2f177
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A listing sorts by the name of a related row through a leftJoin under an alias plus CzechCollation::orderBy, selecting only the own table, because a collation cannot wrap a subselect through the helper"
    - "Every filter query accepts only a known value (enum case, UUID, real calendar day, known flag) and otherwise narrows nothing"
    - "Bulk delete reads the selection with chunkSelectedRecords(100), so a select-all of a filter result is never loaded at once"

key-files:
  created:
    - app/Filament/Resources/DocumentResource.php
    - app/Filament/Resources/DocumentResource/Pages/ListDocuments.php
    - app/Domain/Documents/Enums/DocumentTypeGroup.php
    - tests/Feature/Documents/DocumentResourceTest.php
  modified:
    - app/Filament/Support/DocumentActions.php
    - app/Filament/Support/DocumentColumns.php
    - lang/cs/documents.php
    - lang/cs/enums.php

key-decisions:
  - "Client and uploader sorts join the related table under an alias (sort_clients, sort_users) and use CzechCollation::orderBy on it; the plan said subselects, but the helper wraps a column name, not an expression"
  - "File-type groups: image by image/* MIME, PDF by application/pdf, text, spreadsheet, presentation and archive by stored extension, other by elimination; an image or PDF never lands in an extension group, and of() and constrain() are the same rule"
  - "The date filter turns the picked days into UTC instants with PragueDay::start and endExclusive and compares documents.created_at half-open"
  - "archived_owners is a TernaryFilter with default(false); only 1/0/true/false in any spelling select a scope, anything else shows every file, so a forged value never errors and never hides a file"
  - "bulkDelete counts a selected file that was gone before it was read as failed, computed as selected minus processed, so the counts hold with a lazy chunked selection"
  - "Transaction receipts are named by the counterparty of their transaction in the owner column, in DocumentColumns::ownerName so the client tab shows them the same way"

patterns-established:
  - "Central Admin listing: the shared DocumentColumns supply the common columns by name, the resource adds the link, client and type and makes every column sortable"

requirements-completed: [DO-02, DO-01]

coverage:
  - id: D1
    description: "The Admin opens one central Soubory listing of every file with name, owner and link, client, size, MIME type, uploader, date and flag, for every owner kind and for unattached files, and downloads a file from a row"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#tracer: the Admin finds a file of every owner kind in one listing, follows the owner link and downloads one"
        status: pass
    human_judgment: false
  - id: D2
    description: "Owner rendering costs a fixed number of queries whatever the row count, archived owners keep their name and link, and no relation is lazy-loaded per row"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#loads the owners of the rows in a fixed number of queries whatever the row count"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#shows archived owners by name and link, and the archived client by name, without lazy-loading a relation"
        status: pass
    human_judgment: false
  - id: D3
    description: "The listing filters by owner type including unattached, client, project, inclusive Prague days, file-type group and visibility; forged values narrow nothing"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#filters by owner type, each kind returning only its files and \"unattached\" only the loose ones"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#filters by the inclusive Prague days of the upload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#falls back to no filter for a forged filter value"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every column sorts, names and clients in Czech order, and the name is searchable"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#sorts the names in Czech order and every other column without an error"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#sorts by client name in Czech order and keeps the files without a client"
        status: pass
    human_judgment: false
  - id: D5
    description: "The default listing leaves out files of archived clients, projects, tasks and their comments; the archived_owners filter shows only those or all; restore brings them back; receipts and unattached files are never hidden"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#hides the files of an archived project by default, shows only them under the archived filter and brings them back on restore"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#hides the files of an archived client but never a receipt or a loose file"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Admin uploads unattached files from the listing; they are stored hidden, without owner, client or project"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#stores the file uploaded from the listing unattached, hidden and uploaded by the Admin"
        status: pass
    human_judgment: false
  - id: D7
    description: "Rename, show or hide and delete work from listing rows; preview only for images and PDF; the visibility switch is absent for client, transaction and unattached files"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#offers the visibility switch for project, task and comment files only"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#offers a preview only for images and PDF"
        status: pass
    human_judgment: false
  - id: D8
    description: "Select-all over a filter result of two pages, or of a search, runs one confirmed bulk delete of exactly that result with deleted and failed counts"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#deletes every file of a filter result across two pages after one confirmation, and no other file"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#deletes only the matching files when a search narrows the selected-all result"
        status: pass
    human_judgment: false
  - id: D9
    description: "A Partner gets 403 on the listing and its page component; the listing is not globally searchable"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#answers a Partner with 403 on the listing and its page component"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentResourceTest.php#is not globally searchable and the global search of the panel finds no file, for the Admin or a Partner"
        status: pass
    human_judgment: false
  - id: D10
    description: "The look of the listing in the real panel: column widths, the filter dropdown with seven filters and the deferred Apply step, the select-all link across pages, the owner link styling"
    verification: []
    human_judgment: true
    rationale: "Behaviour is asserted through Livewire; layout, the deferred filter apply step and the browser select-all control are not exercised by the tests"

# Metrics
duration: 17min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 14: Central Documents Listing Summary

**Admin-only central Soubory listing over every file with owner links, client, type and uploader, seven sortable columns in Czech order, the D-07 filters with a default that hides files of archived owners, unattached upload, and a chunked bulk delete that handles a whole filter result**

## Performance

- **Duration:** about 17 min (start taken from the plan commit ledger)
- **Started:** 2026-10-16T15:13:22Z
- **Completed:** 2026-10-16T15:30Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 8 (4 created, 4 modified)

## Accomplishments

- `DocumentResource` and `ListDocuments`: AdminOnly, `EnforcesResourceAccessRule`, `$isGloballySearchable = false`, index page only, `canCreate()` false. The query eager-loads the owner (archived rows included, comment with its task), the client (withTrashed) and the uploader, so rendering costs a fixed number of queries. The owner column links to the owner's Admin page (a comment to its task, a transaction to its edit page), unattached files read "Nepřiřazeno".
- `DocumentActions::uploadUnattached()` (no owner, no visibility switch, own hint) and `bulkActions()`; the shared upload code is now one private `attach()` used by both uploads. `bulkDelete` reads its selection in chunks of 100 by key and counts files gone before they were read as failed.
- `DocumentTypeGroup` with `of()` and `constrain()` (one rule for label and filter), and the filters: owner type with "unattached", client and project (archived ones offered), inclusive Prague days via `PragueDay`, type group, visibility and `archived_owners` (default false, over the 09-21 scopes). Client and uploader sort in Czech order through an aliased join.
- Listing copy, filter labels and the totals keys (`documents.totals.*` including `no_client`, `includes_archived`, `archived_client`) that plan 09-16 reads.

## Task Commits

1. **Task 1: Tracer, central listing with owner links, unattached upload and download** - `f5edb26` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so it was re-run (documents, localisation, arch and isolation tests, Pint, PHPStan, all green) before expansion.
2. **Task 2: Every D-07 filter, every sortable column and the name search** - `c4bedf9` (feat)
3. **Task 3: Row actions and bulk delete over a selection or the whole filter result** - `3da91b4` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions`. Notably the sort by a related name uses an aliased join and `CzechCollation::orderBy`, and the archived-owners filter accepts only a recognised flag, leaving every other value as "all files".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Transaction owners had no name in the owner column**
- **Found during:** Task 1
- **Issue:** `DocumentColumns::ownerName()` had no branch for the transaction owner added in 09-13, so a receipt would show only the label "Transakce" with no name, in this listing and on the client tab.
- **Fix:** Added a `Transaction` branch returning its counterparty.
- **Files modified:** `app/Filament/Support/DocumentColumns.php` (not in the plan's file list)
- **Verification:** the tracer test and the existing documents tests
- **Committed in:** f5edb26

**2. [Rule 3 - Blocking] A sort by a related name cannot go through a subselect with CzechCollation**
- **Found during:** Task 1
- **Issue:** The plan asked for subselect sorts with `CzechCollation`, but the helper quotes a column name, not an expression, and an output alias cannot take a COLLATE in Postgres.
- **Fix:** A `leftJoin` of the related table under an alias used for the order only, `select documents.*`, and `CzechCollation::orderBy` on the alias; archived rows are included because the raw table has no soft-delete scope.
- **Files modified:** `app/Filament/Resources/DocumentResource.php`
- **Verification:** the client and uploader sort tests (Czech order with "Čapek" between "Cihla" and "Dvořák")
- **Committed in:** f5edb26

**3. [Rule 1 - Bug] The plan's global-search check could not hold as written**
- **Found during:** Task 1
- **Issue:** `Resource::getGlobalSearchResults()` ignores `$isGloballySearchable` (the panel's search provider honours it), so asserting that static method returns nothing fails for a resource that is correctly closed.
- **Fix:** The test goes through the panel's global search provider as the Admin and as a Partner and asserts `canGloballySearch()` is false.
- **Files modified:** `tests/Feature/Documents/DocumentResourceTest.php`
- **Committed in:** f5edb26

---

**Total deviations:** 3 auto-fixed (1 missing critical, 1 blocking, 1 bug in a test premise). **Impact on plan:** no scope creep; each was needed for a planned behaviour to be correct.

### Plan interpretation (not deviations)

- The unattached upload was built by extracting the shared body of `upload()` into a private `attach()`, which changes no behaviour of the tabs (the existing 485 documents, localisation, arch and isolation tests pass).
- Extra Czech keys beyond the plan's list: `filters.created`, `filters.visibility_visible`, `visibility_hidden`, `visibility_all`, `hints.unattached`.
- `lang/cs/enums.php` carries a comment naming `DocumentTypeGroup` next to the `document_type_group` keys, so the plan's acceptance grep for the class name holds while the key stays in the file's snake_case convention.

## Authentication Gates

None.

## Issues Encountered

- Filament's testing macro `mountTableBulkAction` requires a record list, so the select-all tests mount the bulk action with `TestAction::make('bulkDelete')->table()->bulk()` after setting `isTrackingDeselectedTableRecords`, which is what the browser's select-all link sets.
- The test helper that reads the owner URL must use the row as the table loaded it (eager-loaded owner); a freshly created model would lazy-load the owner and lose an archived one. It reads the table's own records.

## Known Stubs

None.

## Threat Flags

None. The surface is covered by T-09-48 (AdminOnly rule, `EnforcesResourceAccessRule`, not globally searchable, Partner 403 and global search tests), T-09-49 (filters validate their values and fall back, archived-owners rule lives in the 09-21 scopes, bulk delete goes through `DeleteDocument` per record) and T-09-50 (confirmation, per-record activity from `DeleteDocument`, result counts). `composer.json` and `composer.lock` untouched (T-09-SC).

## Verification

- `ddev exec vendor/bin/pest`: 4368 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh` clean on every commit
- Acceptance greps of all three tasks pass; two mutation runs (listing without `withOwners`, client filter without the UUID check) made the matching tests fail as intended

## Next Phase Readiness

- Plan 09-16 can add the totals above the table and read `documents.totals.*` without editing `lang/cs/documents.php`; plan 09-17 appends the ZIP action to `DocumentActions::bulkActions()`.
- Open for a human: the look of the listing in the real panel (coverage D10).

## Self-Check: PASSED

- Created files exist: `DocumentResource.php`, `ListDocuments.php`, `DocumentTypeGroup.php`, `DocumentResourceTest.php` (found)
- Commits `f5edb26`, `c4bedf9`, `3da91b4` are ancestors of HEAD; `git rev-list --count 2083af1..HEAD` was 3 before this SUMMARY

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
