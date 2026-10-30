---
phase: quick-261022-sgn
plan: 01
subsystem: admin-panel-search
tags: [filament, global-search, access-control]
requires: []
provides:
  - Admin global search by client name and project key or name, grouped Klienti, Projekty, Ukoly
affects:
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/TaskResource.php
tech-stack:
  added: []
  patterns:
    - "Resource-level global search opt-in with $globalSearchSort for group order"
key-files:
  created:
    - tests/Feature/Search/GlobalSearchTest.php
  modified:
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/TaskResource.php
    - app/Providers/Filament/AdminPanelProvider.php
    - tests/Feature/Tasks/TaskResourceTest.php
    - tests/Feature/Clients/ClientResourceTest.php
    - tests/Isolation/PanelAccessTest.php
decisions:
  - "Clients are searched by name only; projects by key and name; tasks unchanged (reference, title)"
  - "Archived clients and projects are not suggested"
  - "Partner keeps getting no search result: both newly opened resources stay AdminOnly and canGloballySearch() ANDs canAccess()"
  - "Group order follows the sidebar through globalSearchSort 10/20/30"
metrics:
  tasks: 2
  commits: 2
  plan_head_before: 3262ceadb1f703e44f61c4a1921f914d4b34954f
  plan_head_after: 30823f68dfd2a0f71fa916f3c0ac5ec517b9b8f3
actuals:
  tokens: 40000
  tasks: 2
  commits: 2
status: complete
completed: 2026-10-22
---

# Quick 261022-sgn: Global search matches clients and projects Summary

The existing Filament top-bar search now also finds clients by name and projects by key (any letter case) or name, next to tasks by KEY-N or title, with groups in the sidebar order Klienti, Projekty, Ukoly. A Partner still gets no result.

## What was done

- **Task 1 (3629cbf):** ClientResource and ProjectResource opt in (`$isGloballySearchable = true`). Client: searchable by `name`, details are company number (only when filled) and stage, query leaves archived rows out and orders by name. Project: searchable by `key` and `name`, title `KEY · name`, details are client name and status, archived rows left out, client eager-loaded with only SoftDeletingScope lifted, ordered by key. New `tests/Feature/Search/GlobalSearchTest.php` covers matching, details, URLs, archived exclusion, wildcard escaping, group presence, the AdminOnly invariant and the Partner empty sweep. The two obsolete panel-wide tests in TaskResourceTest were removed (replaced by the new file).
- **Task 2 (30823f6):** `$globalSearchSort` 10/20/30 (Client/Project/Task) gives the group order; panel provider, TaskResource and PanelAccessTest comments now name the three Admin-only searchable resources; order tests added.

No view, CSS or panel call was changed, so the dropdown keeps its current look.

## Verification

- Targeted suites (GlobalSearchTest, TaskResourceTest, TaskArchiveTest, PanelAccessTest, InvoiceLeakTest, CardPaymentLeakTest, RouteWalkTest, DocumentResourceTest, ClientResourceTest) green.
- Full pest run: 6361 passed, 3 skipped, 1 failed before the fix below; after the fix the failing test passes.
- Pint clean over the whole repo; `check-licenses` OK; `scripts/check-sensitive.sh` clean on every touched file.
- Human check (dropdown look, Partner sees nothing) is left for the end of the phase and was not performed in a browser.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Existing test asserted the superseded behaviour**
- **Found during:** Task 2 full run
- **Issue:** `tests/Feature/Clients/ClientResourceTest.php` had "is not globally searchable" asserting `ClientResource::canGloballySearch()` is false, contradicting the requested change.
- **Fix:** Replaced by "is globally searchable by name for the Admin only" (true and attribute `['name']` for the Admin, false for a Partner).
- **Files modified:** tests/Feature/Clients/ClientResourceTest.php
- **Commit:** 30823f6

## Deferred Issues

- `ddev composer stan` (whole repo) reports one error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (`function.alreadyNarrowedType`). The file is untouched by this task, it likely stems from the pre-existing uncommitted `composer.lock` bump. Not fixed (out of scope). Details in `deferred-items.md`. Because of it `ddev composer ci` does not end green as a whole; every other stage (pest after the fix, pint, licenses) is green and the files of this task analyse clean in PHPStan.

## Known Stubs

None.

## Threat Flags

None. The newly searchable resources are Admin-only by `#[AccessRule]`; no new endpoint or data path.

## Self-Check: PASSED

- tests/Feature/Search/GlobalSearchTest.php: found
- Commits 3629cbf and 30823f6: present in history
