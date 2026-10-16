---
phase: 09-documents-and-finance
plan: 08
subsystem: documents
tags: [documents, partner-isolation, policy, filament, relation-manager, mutation-self-check, d-21]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01 Document::constrainForPartner and the owner rules; 09-03 signed file route; 09-05 DocumentColumns, DocumentActions and the documents.* copy; 09-21 archived-owner scopes"
provides:
  - "DocumentPolicy::view: the explicit Partner read grant that repeats the Document scope (flagged, shareable owner, own client, still returned by the scoped query)"
  - "DocumentColumns::partner() and PARTNER_COLUMN_NAMES = name, description, size_bytes, created_at, sharing column definitions with the Admin builder"
  - "Read-only Soubory tabs on the Partner project and task pages (PartnerProjectDocumentsRelationManager, PartnerTaskDocumentsRelationManager) over the ShowsPartnerDocuments trait"
  - "tests/Isolation/DocumentVisibilityTest: 14 kinds of file on client A and one on client B x 3 viewers x list, count, exists, owner relations, policy and signed route, with archive and restore passes (project, task, client) and a mutation self-check"
affects: [09-10 Partner upload and delete, 09-12 comment attachments, 09-14 central listing, 09-20 unused copy]

actuals:
  tokens: 14000
  tasks: 3
  commits: 3
plan_head_before: 24bf696cfd6b6066b827fdc83105a181838db802
plan_head_after: ac05585d9618dcffaa2d416753a9371ecc5462a2
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Partner read-only tab as a trait (ShowsPartnerDocuments) plus one final class per host with its own #[AccessRule], like the Admin ManagesDocuments"
    - "Visibility matrix as a function over a 'visible ids' provider that returns named violations (viewer sees|misses kind); the real scope and a hand-built reference give none, two hand-built leaky providers give named ones"
    - "Policy view repeats the scope by re-reading the record through the scoped query"

key-files:
  created:
    - app/Filament/Concerns/ShowsPartnerDocuments.php
    - app/Filament/Partner/Resources/PartnerProjectResource/RelationManagers/PartnerProjectDocumentsRelationManager.php
    - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskDocumentsRelationManager.php
    - tests/Isolation/DocumentVisibilityTest.php
  modified:
    - app/Domain/Documents/Policies/DocumentPolicy.php
    - app/Filament/Support/DocumentColumns.php
    - app/Filament/Partner/Resources/PartnerProjectResource.php
    - app/Filament/Partner/Resources/PartnerTaskResource.php
    - tests/Feature/Documents/DocumentFileRouteTest.php
    - tests/Feature/Projects/PartnerProjectResourceTest.php
    - tests/Feature/Tasks/PartnerTaskCommentsTest.php

key-decisions:
  - "The Partner read grant is the scope plus the policy: view requires the flag, a shareable owner type, a Partner with a client, and Document::query()->whereKey()->exists() through the scope, so another client's file, an internal-comment file and a file of an archived owner are denied by the same rule as the list"
  - "The two Partner tabs share one trait (ShowsPartnerDocuments) instead of two copies; each host stays a final class with its own AccessRule"
  - "A Partner without a client gets 403 when a Soubory tab is mounted (the AccessRule boot hook) and an empty scoped query; the route answers 403 or 404 there, never 200"
  - "Files of a comment are not listed on the task tab; they belong to the comment thread (plan 09-12)"

patterns-established:
  - "Mutation self-check by hand-built providers that run as the Admin with a fixed client filter, plus a control that proves the reference provider equals the real scope"

requirements-completed: [DO-01]

coverage:
  - id: D1
    description: "A Partner sees a Soubory tab on an own visible project and on its task and subtask, listing only files flagged visible that the project or task owns, and downloads them through the signed route"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#project tab (tracer) (list, hidden twin, signed redirect bound to the Partner, streamed bytes)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#task tab (task file, subtask file, twins, registered after the comments tab)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentFileRouteTest.php#it streams a visible file to a Partner of the own client and answers a Partner of another client 404"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner never sees or downloads a hidden file, an internal-comment file (even flagged visible), a client-owned or unattached file, a file of a project that is not client-visible or of another client, on any path (list, count, exists, relation, policy, route)"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#matrix (15 kinds x 3 viewers x 6 paths)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Files of an archived project, task, task comment or client disappear for the Partner while archived and return after restore (D-21)"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#matrix (initial, restored, client_archived and client restored phases)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The Partner tab shows exactly the pinned Partner columns and no uploader account, owner type or flag, and offers no upload, rename, visibility switch or delete"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#task tab (pinned columns on both tabs, absent actions, absent Admin-only headings)"
        status: pass
    human_judgment: false
  - id: D5
    description: "A weakened scope cannot pass the suite: removing the flag condition or the internal-comment delegation fails the matrix"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#mutation self-check"
        status: pass
      - kind: integration
        ref: "mutation run on Document::constrainForPartner: without the flag 7 tests failed, with an unscoped comment set 4 tests failed; file restored"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner without a client sees no tab content and no file"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentVisibilityTest.php#task tab (Partner without a client: 403 on the tab, resources inaccessible, scope empty) and the matrix viewer 'none'"
        status: pass
    human_judgment: false
  - id: D7
    description: "In the real panel a Partner clicks Stahnout or Zobrazit on the Soubory tab and the browser receives the file"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests assert the redirect to a user-bound signed link and the streamed bytes; no browser test exists"
        status: pass
    human_judgment: true
    rationale: "The redirect leaves Livewire as a full-page navigation to /admin/files/*; only a real browser session as a Partner shows the file actually downloading or opening and the tab looking right."

# Metrics
duration: 45min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 08: Partner Soubory Tabs Summary

**Partners now read the files the Admin shared: an explicit `DocumentPolicy::view` that repeats the Document scope, read-only Soubory tabs on the Partner project and task pages built from a pinned four-column Partner builder, and a 15-kind x 3-viewer x 6-path isolation matrix with archive/restore passes and a mutation self-check that fails when the flag or the internal-comment rule is removed**

## Performance

- **Duration:** about 45 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T13:40Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 11 (4 created, 7 modified)

## Accomplishments

- `DocumentPolicy::view` grants a Partner a file only when it is flagged visible, its owner type is shareable (project, task, task comment), the Partner has a client and `Document::query()->whereKey()->exists()` still returns it. The scope is the rule; the policy repeats it for a record that arrived another way. The Admin passes through `before()`. The file route test that expected 403 for a Partner of the own client now expects the bytes (200), and a Partner of another client still gets 404.
- `DocumentColumns::partner()` and `PARTNER_COLUMN_NAMES = ['name', 'description', 'size_bytes', 'created_at']`; the Admin builder was refactored onto the same private name, size and date definitions, so the two cannot drift. Description is its own column here.
- `ShowsPartnerDocuments` trait plus two final relation managers (`PartnerProjectDocumentsRelationManager`, `PartnerTaskDocumentsRelationManager`), each with `#[AccessRule(Audience::PartnerAllowed, ...)]` and `EnforcesRelationManagerAccessRule`, registered in the new `PartnerProjectResource::getRelations()` and after the comments tab in `PartnerTaskResource::getRelations()`. Read-only, preview and download only, copy from the keys 09-05 wrote (no edit of `lang/cs/documents.php`).
- `DocumentVisibilityTest`: 14 kinds of file on client A (visible and hidden project and task files, a subtask file, a non-internal comment file, an internal-comment file flagged visible by a system write, client-owned, unattached, a project that is not client-visible, an archived project and a task in it, an archived task and a comment on it) plus one visible file of client B. For the Partner of A, the Partner of B and a Partner without a client, the list, count, `exists()`, the owner relations through the scoped models, `Gate::allows('view')` and the signed route (200 or 404) agree. Restore passes: archived project and task restored, then client A archived (nothing visible) and restored again.
- Mutation self-check: the matrix is a function over a "visible ids" provider that returns named violations. The real scope and a hand-built Admin-run reference give none; a provider without the flag condition names `project_hidden` and `task_hidden`; one over an unscoped comment set names exactly `internal_comment_visible`; a silent provider is reported as "misses". In addition the real `Document::constrainForPartner` was mutated by hand (flag removed: 7 tests failed; comment delegation made unscoped: 4 tests failed) and restored.

## Task Commits

1. **Task 1: Tracer - a Partner opens an own project, sees the shared file on its Soubory tab and downloads it** - `af5e4ed` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests, the panel registry test, Pint and PHPStan were re-run end to end (green) before expansion.
2. **Task 2: The task tab and the full visibility matrix** - `0d7c0ac` (feat, TDD)
3. **Task 3: Mutation self-check** - `ac05585` (test, TDD)

## Files Created/Modified

See `key-files` in the frontmatter.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Two existing tests pinned "no relation manager" and "comments tab only"**
- **Found during:** Task 1 and Task 2 (broader test runs)
- **Issue:** `PartnerProjectResourceTest` asserted `PartnerProjectResource::getRelations()` is `[]` and `PartnerTaskCommentsTest` asserted the task relations are exactly the comments tab. Registering the new tabs, which the plan requires, makes both assertions false.
- **Fix:** Both now pin the new, intended list (documents tab only on the project; comments then documents on the task).
- **Files modified:** `tests/Feature/Projects/PartnerProjectResourceTest.php`, `tests/Feature/Tasks/PartnerTaskCommentsTest.php` (not in the plan's `files_modified`)
- **Commit:** af5e4ed, 0d7c0ac

**2. [Rule 3 - Blocking] A shared trait outside the plan's file list**
- **Found during:** Task 1
- **Issue:** Both Partner tabs have an identical table definition; two copies would drift.
- **Fix:** One `app/Filament/Concerns/ShowsPartnerDocuments.php` (the Partner counterpart of the Admin `ManagesDocuments`); each host is still its own final class with its own AccessRule, as the artifact table requires.
- **Files modified:** `app/Filament/Concerns/ShowsPartnerDocuments.php`
- **Commit:** af5e4ed

**Total deviations:** 2 auto-fixed (both blocking). **Impact:** none on behavior; both are consequences of registering the tabs.

### Plan interpretation (not a deviation)

- The plan says Partner A "sees their four visible files" after restore. Before the restore the four are the project, task, subtask and comment files; after the archived project and task are restored the four files of those owners return as well (eight in total). The matrix pins both sets.
- The plan's "fourteen kinds" lists thirteen; a hidden task file is the fourteenth. The visible file of client B is a fifteenth fixture used for the other-client viewer, so the Admin check asserts 15 files.
- A Partner without a client is refused the tab with a 403 (AccessRule boot hook) rather than shown an empty tab; the scoped query is empty as well. On the route that user gets 403 or 404, never 200.
- Only the first relation manager tab of a Livewire page is rendered up front, so the "registered on the task page" check reads the page's `getRelationManagers()` rather than looking for the second component in the HTML.

## Authentication Gates

None.

## Issues Encountered

- The full-suite command exceeds the foreground time limit; it was run in the background and finished green (4216 passed, 3 skipped, pre-existing, 0 failed).

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-26 (internal-comment file: scope delegation, flagged twin in the matrix, mutation self-check), T-09-27 (every query path, three viewers, archive and restore passes, route 404), T-09-28 (pinned columns, no uploader, owner or flag) and T-09-29 (mutation self-check) of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4216 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- All task acceptance criteria re-run: pass

## Next Phase Readiness

Ready for the next plan of wave 4. Plan 09-10 (Partner upload and delete) extends `DocumentPolicy` with its own grants next to `view` and can reuse `ShowsPartnerDocuments` by adding an upload header action; plan 09-12 puts comment attachments into the comment thread, where the matrix already pins the non-internal and internal cases. One open item: a human look at the Partner tabs in the real panel (coverage D7).

## Self-Check: PASSED

- Files exist: `ShowsPartnerDocuments.php`, `PartnerProjectDocumentsRelationManager.php`, `PartnerTaskDocumentsRelationManager.php`, `DocumentVisibilityTest.php` (found)
- Commits `af5e4ed`, `0d7c0ac`, `ac05585` are ancestors of HEAD
- `commits: 3` measured from the plan ledger (`24bf696..ac05585`)
