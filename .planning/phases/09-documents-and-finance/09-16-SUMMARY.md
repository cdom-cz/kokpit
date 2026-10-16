---
phase: 09-documents-and-finance
plan: 16
subsystem: documents
tags: [documents, filament, widgets, storage-totals, czech-collation, admin-only]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-14 central DocumentResource and ListDocuments, the documents.totals.* copy, DocumentColumns::humanSize"
provides:
  - "StorageTotals reader: overall() and byClient() over every documents row, Admin and system only"
  - "DocumentStorageStats tiles (file count, total size) and DocumentStorageByClient table registered on the central listing"
affects: [09-17 ZIP bulk action, 09-20 consistency check]

actuals:
  tokens: 5600
  tasks: 2
  commits: 2
plan_head_before: 7b5e98639ecb934674128ed17daab3bc495ba15b
plan_head_after: 0dd275e3efcf9e4d64ce4e1c6653e5aee6f095e4
commits: 2

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "An Admin-only aggregate reader reads through the model (toBase()) and joins other tables by name, so the raw-table escape-hatch arch test stays clean and the Partner scope still applies as a second line"
    - "A TableWidget over records() keyed by client id, with the one row of files without a client under its own key"

key-files:
  created:
    - app/Domain/Documents/Queries/StorageTotals.php
    - app/Filament/Resources/DocumentResource/Widgets/DocumentStorageStats.php
    - app/Filament/Resources/DocumentResource/Widgets/DocumentStorageByClient.php
    - tests/Feature/Documents/StorageTotalsTest.php
  modified:
    - app/Filament/Resources/DocumentResource.php
    - app/Filament/Resources/DocumentResource/Pages/ListDocuments.php

key-decisions:
  - "byClient() returns the no-client row with a null client_id and an empty name; the widget words it with documents.totals.no_client, so the domain reader carries no UI copy"
  - "Totals ignore the listing filters and count files of archived owners; both tiles carry documents.totals.includes_archived and an archived client's row carries documents.totals.archived_client as a description under its name"
  - "The by-client table spans the full width and is unpaginated, one row per client with files"

patterns-established:
  - "Czech order with the no-client row last: ORDER BY (client_id IS NULL), then CzechCollation::orderBy on the joined client name, then client_id"

requirements-completed: [DO-02]

coverage:
  - id: D1
    description: "Above the central listing the Admin sees tiles with the total number of files and their total size, with the includes-archived note, unaffected by listing filters and by archiving"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#tracer: the Admin opens the listing and the tiles show the number and size of all stored files"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#keeps the tiles at all files while a listing filter narrows the table"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#counts the files of an archived owner, which the default listing hides"
        status: pass
    human_judgment: false
  - id: D2
    description: "A by-client table lists every client with files, rolled up from project, task, comment and receipt files, in Czech order, with the Nepřiřazené ke klientovi row last and the archived label on archived clients"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#lists the clients with their files, rolled up from project, task and comment files, in Czech order, the no-client row last"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#lists an archived client with its files and the archived label, and drops the label on restore"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#sums the rows to the overall tile values"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner gets 403 on mounting either widget and the reader refuses everybody but the Admin and a system run"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#refuses a Partner the tiles and the reader"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/StorageTotalsTest.php#refuses a Partner the by-client table and the by-client reader"
        status: pass
    human_judgment: false
  - id: D4
    description: "The look of the tiles and the by-client table above the listing in the real panel: spacing, the full-width table, the archived label under the client name"
    verification: []
    human_judgment: true
    rationale: "Behaviour is asserted through Livewire; layout and visual weight of the widgets are not exercised by the tests"

# Metrics
duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 16: Listing Storage Totals Summary

**Admin-only storage totals above the central Soubory listing: tiles with the file count and total size, and a by-client table with a "Nepřiřazené ke klientovi" row, from one reader over the documents table that counts files of archived owners too**

## Performance

- **Duration:** about 25 min
- **Started:** 2026-10-16T15:36Z
- **Completed:** 2026-10-16T16:01Z
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files modified:** 6 (4 created, 2 modified)

## Accomplishments

- `StorageTotals::overall()` (count and bytes of every document) and `byClient()` (per denormalized `client_id` in Czech order, the no-client row last, archived clients flagged); both refuse everybody but the Admin and a system run.
- `DocumentStorageStats` (two tiles, each with the `includes_archived` note) and `DocumentStorageByClient` (client with the `archived_client` description, count, size) registered in `DocumentResource::getWidgets()` and `ListDocuments::getHeaderWidgets()`.
- 13 tests: tiles, filters and archiving not changing the totals, Czech order, roll-up of project, task, comment and receipt files, the no-client row, archived label and restore, rows summing to the tiles, Partner 403 and reader refusal, system run allowed.

## Task Commits

1. **Task 1: Tracer, the tiles show the number and size of all stored files** - `984c050` (feat)
2. **Task 2: The by-client table with the no-client row** - `0dd275e` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions`. The reader returns no UI copy: the no-client row is a null `client_id` with an empty name and the widget supplies the Czech label.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The tracer commit read `documents` through `DB::table()`, which the query escape-hatch arch test forbids**
- **Found during:** Task 2 (full suite run; the tracer's `<verify>` lists the panel registry and panel access tests but not `tests/Arch/QueryEscapeHatchTest.php`)
- **Issue:** `StorageTotals::overall()` and the first `byClient()` used `DB::table('documents')`, a raw-table read outside the allowlist, so `QueryEscapeHatchTest` failed at the tracer commit.
- **Fix:** Both queries now start from `Document::query()` and end in `toBase()`; the clients table is joined by name so an archived client keeps its name. The Partner scope of the model applies as a second line behind the reader's own refusal.
- **Files modified:** `app/Domain/Documents/Queries/StorageTotals.php`
- **Verification:** `tests/Arch` and the full suite green (4425 passed, 3 skipped)
- **Committed in:** `0dd275e` (Task 2 commit); the tracer commit `984c050` alone does not pass that arch test

**2. [Rule 1 - Bug] PHPStan: `__()` returns `array|string|null`**
- **Found during:** Task 2
- **Fix:** the two state and description closures of the by-client table cast the translation to string.
- **Files modified:** `app/Filament/Resources/DocumentResource/Widgets/DocumentStorageByClient.php`
- **Committed in:** `0dd275e`

---

**Total deviations:** 2 auto-fixed (2 bugs). **Impact on plan:** no scope creep; the artifact contracts are as planned, with `byClient()` returning an empty name for the no-client row (the plan's table says `name: string`).

## Issues Encountered

None beyond the deviations.

## Known Stubs

None.

## Threat Flags

None. T-09-53 is mitigated by `#[AccessRule(Audience::AdminOnly)]` plus `EnforcesWidgetAccessRule` on both widgets, the reader's own refusal and the 403 tests. `composer.json` and `composer.lock` untouched (T-09-SC).

## Verification

- `ddev exec vendor/bin/pest`: 4425 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test` clean; `ddev exec vendor/bin/phpstan analyse` no errors; `scripts/check-sensitive.sh` clean on both commits
- Acceptance greps of both tasks pass

## Next Phase Readiness

- Plan 09-17 can append the ZIP action to `DocumentActions::bulkActions()` and edit `lang/cs/documents.php` freely; this plan left both untouched.
- Open for a human: the look of the widgets in the real panel (coverage D4).

## Self-Check: PASSED

- Created files exist: `StorageTotals.php`, `DocumentStorageStats.php`, `DocumentStorageByClient.php`, `StorageTotalsTest.php` (found)
- Commits `984c050` and `0dd275e` are ancestors of HEAD; `git rev-list --count 7b5e986..HEAD` was 2 before this SUMMARY

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
