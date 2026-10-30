---
phase: 09-documents-and-finance
plan: 05
subsystem: documents
tags: [documents, filament, relation-manager, upload, signed-download, owner-column, partner-isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-02 AttachDocuments and the documents() relations; 09-03 DocumentUrl and the signed file route; 09-04 inline/attachment disposition; 09-24 Livewire temporary uploads on the private bucket"
provides:
  - "ManagesDocuments trait: the one Soubory tab behaviour (isReadOnly false, title, table, upload header action, preview and download record actions)"
  - "Three final Admin-only relation managers (project, task, client) registered on their resources"
  - "DocumentColumns (admin columns, withOwners eager loading, ownerLabel/ownerName, humanSize) and DocumentActions (upload, download, preview)"
  - "DocumentOwnerType labels (Klient, Projekt, Ukol, Komentar) and the documents tab copy in lang/cs/documents.php, including the keys the Partner tabs of 09-08 read"
affects: [09-07 rename/visibility/delete, 09-08 Partner tabs, 09-10 Partner upload, 09-13 transaction tab, 09-14 central listing, 09-20 unused copy]

actuals:
  tokens: 13000
  tasks: 3
  commits: 3
plan_head_before: 2391d029a07600559c481174d6da104acdcae850
plan_head_after: c905e1a143e679cfdc518f034d927a8b3f19748e
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Shared relation manager behaviour as a trait, one final class per host with its own #[AccessRule] (attributes are not inherited)"
    - "Download and preview are actions that authorize, mint the signed URL at click time and redirect; no URL is ever rendered into the tab"
    - "Upload modal: FileUpload multiple with storeFiles(false), only TemporaryUploadedFile instances reach AttachDocuments; domain errors re-keyed to mountedActions.0.data.<field>"
    - "Polymorphic owner column loaded with MorphTo::withTrashed()->morphWith(...) so the query count does not grow with the rows"
    - "Livewire test upload on the S3 temporary disk: one call per file on its own key (files.<uuid>), the browser's behaviour"

key-files:
  created:
    - app/Filament/Concerns/ManagesDocuments.php
    - app/Filament/RelationManagers/ProjectDocumentsRelationManager.php
    - app/Filament/RelationManagers/TaskDocumentsRelationManager.php
    - app/Filament/RelationManagers/ClientDocumentsRelationManager.php
    - app/Filament/Support/DocumentColumns.php
    - app/Filament/Support/DocumentActions.php
    - tests/Feature/Documents/DocumentsRelationManagerTest.php
  modified:
    - app/Domain/Documents/Enums/DocumentOwnerType.php
    - app/Filament/Resources/ProjectResource.php
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Resources/ClientResource.php
    - lang/cs/documents.php
    - lang/cs/enums.php
    - tests/Feature/Tasks/SubtasksTest.php

key-decisions:
  - "The client tab lists every file of the client through Client::documents() (by client_id) with an owner column; its uploads are client-owned and hidden, and the visibility switch is not offered there"
  - "Archived owners stay listed on the Admin tabs (D-21 governs the Partner view and the central listing); uploads to an archived owner stay refused by AttachDocuments"
  - "The upload action re-keys domain ValidationException messages to mountedActions.0.data.<key> (the modal state path), the same mapping as the Kontakty tab, instead of the page-form prefix of withFormErrors()"
  - "The attachments copy keys under kokpit.tasks.attachments.* stay until plan 09-20; this plan only removed the section that used them"
  - "Sizes are written in binary units with a Czech decimal comma and plain space (1,5 MB)"

patterns-established:
  - "A documents surface builds its list from DocumentColumns::admin() and its actions from DocumentActions, so later surfaces cannot drift"
  - "Owner column state is label (Klient/Projekt/Ukol/Komentar) with the owner name as description; a comment shows its task reference"

requirements-completed: [DO-01, DO-03]

coverage:
  - id: D1
    description: "The Admin uploads one or more files with an optional description on the project, task and client Soubory tabs; project and task files are visible by default and can be hidden at upload"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it stores two files with a description on the project from the upload modal, visible by default"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it stores a task file with the project and client of the task, visible by default"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it lets the Admin hide a project file at upload (project and task cases)"
        status: pass
    human_judgment: false
  - id: D2
    description: "A download click authorizes, mints a signed URL bound to the Admin and redirects; the rendered tab holds no signature, file route path or bucket host; preview is offered only for the five inline types"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it redirects a download click to a signed link of the checked file route for the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it renders no signed link and no bucket host into the tab"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it shows a preview action only for a type the browser may render"
        status: pass
    human_judgment: false
  - id: D3
    description: "The client tab lists every file of the client (own, project, task, comment) with owner label and name, never another client's file, archived owners included, without one query per row"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php (client tab cases: every owner, other client, archived owners, query count)"
        status: pass
      - kind: integration
        ref: "mutation run: withOwners() returning the plain query made the query-count test and the archived-owner test fail"
        status: pass
    human_judgment: false
  - id: D4
    description: "The visibility switch is offered only on project and task tabs with the hint that the client sees the file and its description; on the client tab it is hidden and every upload is stored hidden"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it explains on the upload modal that the client will see a project file and its description"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php#it stores an upload on the client tab as a client file that is hidden, with no visibility switch"
        status: pass
    human_judgment: false
  - id: D5
    description: "The inert attachments placeholder is gone from the task page, which shows the Soubory tab; the description editor keeps inline attachments off"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/SubtasksTest.php#it shows the Soubory tab on a task and a subtask page and no attachments placeholder"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/RichTextSanitiserTest.php (editor without attachments, unchanged)"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner who mounts any Admin Soubory tab gets 403 (AccessRule AdminOnly)"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentsRelationManagerTest.php (Partner 403 on project, task and client tabs)"
        status: pass
      - kind: integration
        ref: "tests/Arch/PanelRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "A real browser click on Stahnout or Zobrazit hands the browser the file (full page redirect to a path outside SPA navigation)"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests assert the redirect effect and a valid user-bound signature; no browser test exists"
        status: pass
    human_judgment: true
    rationale: "The redirect leaves Livewire as a full-page navigation to /admin/files/*, which plan 09-03 excluded from SPA mode; only a real browser shows the file actually downloading or opening."

# Metrics
duration: 40min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 05: Admin Soubory Tabs Summary

**One shared `ManagesDocuments` Soubory tab on the project, task and client detail: upload modal over `AttachDocuments`, download and preview through the click-time signed route, a client tab that lists every file of the client with its eager-loaded owner, and the Phase 5 attachments placeholder removed**

## Performance

- **Duration:** about 40 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T12:35Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 14 (7 created incl. the test file, 7 modified)

## Accomplishments

- `ManagesDocuments` trait gives every host the same tab: not read-only, title "Soubory", `DocumentColumns::admin()`, header action upload, record actions preview and download, newest first with the id as tie-breaker. Each host (`ProjectDocumentsRelationManager`, `TaskDocumentsRelationManager`, `ClientDocumentsRelationManager`) is a final class with its own `#[AccessRule(Audience::AdminOnly, reason: ...)]` and `EnforcesRelationManagerAccessRule`, registered in `getRelations()` of its resource.
- Upload modal: `FileUpload` (multiple, `storeFiles(false)`, size and count from config), description (2000), and a visibility toggle shown only for a shareable owner (default on, hint that the client sees the file and its description). Only `TemporaryUploadedFile` instances, the description and (for a shareable owner) the flag reach `AttachDocuments`; the owner comes from the host's record.
- Download and preview authorize `view`, compute `DocumentUrl::download()` / `preview()` on click and redirect; the rendered tab contains no `signature=`, no `/admin/files/documents/` path and no bucket host (T-09-18). Preview is visible only for the five inline types.
- Client tab: every file of the client with an owner column (label plus project name, task reference, client name or the comment's task reference), loaded through `MorphTo::withTrashed()->morphWith(...)` so the query count stays the same with more rows; a mutation run without `withOwners()` failed the query-count test. Archived projects and tasks stay listed.
- `DocumentOwnerType` implements `HasLabel` (Klient, Projekt, Úkol, Komentář); the app-wide enum label test stays green.
- Task page: the inert "Přílohy" section is removed and the Soubory tab replaces it; the description editor keeps `fileAttachments(false)` and its comment now says why (D-22).
- Copy contract: `lang/cs/documents.php` holds `relation_title`, `columns.*`, `actions.*`, `fields.*`, `hints.*`, `empty.*`, `partner_empty.*`, `owner_none` and `notifications.uploaded` (trans_choice for 1, 2-4, 5+), so plan 09-08 does not edit that file.

## Task Commits

1. **Task 1: Tracer - the Admin uploads a file on the project's Soubory tab and downloads it through the signed route** - `81e9438` (feat). The tracer's `<verify>` is automated-only and `human_verify_mode` is end-of-phase, so its tests, the panel registry test, Pint and PHPStan were re-run end to end (green) before expansion.
2. **Task 2: The task page gets the same Soubory tab and loses the placeholder** - `16e9ac6` (feat, TDD: the rewritten SubtasksTest case was observed red, then the section removed and the tab registered)
3. **Task 3: The client tab lists every file of the client with its owner, and owner kinds have Czech labels** - `c905e1a` (feat, TDD)

## Files Created/Modified

See `key-files` in the frontmatter. `app/Filament/Concerns/ManagesDocuments.php` is created in Task 1 and gains the owner-eager-loading hook in Task 3.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `withFormErrors()` keys do not reach the upload modal**
- **Found during:** Task 1 (domain refusal test)
- **Issue:** The plan asks for the upload to run "inside `withFormErrors()`" so a domain error lands next to the field. That helper prefixes `data.`, the state path of a page form; an action modal lives under `mountedActions.0.data.`, so the message would not appear next to the field.
- **Fix:** `DocumentActions::underModal()` re-keys the ValidationException to `mountedActions.0.data.<key>` (the same mapping the Kontakty tab uses). A test pins the key and the Czech text.
- **Files modified:** `app/Filament/Support/DocumentActions.php`, `tests/Feature/Documents/DocumentsRelationManagerTest.php`
- **Committed in:** 81e9438

**2. [Rule 3 - Blocking] Livewire's test upload cannot send a list on the S3 temporary disk**
- **Found during:** Task 1
- **Issue:** `upload(..., isMultiple: true)` raises `S3DoesntSupportMultipleFileUploads` under the suite's production-like temporary disk (plan 09-24), and `DocumentFixtures::upload()` returns a plain `UploadedFile` that Livewire's test upload cannot read (`->name`).
- **Fix:** The test helper sends each file on its own key (`files.<uuid>`) with `UploadedFile::fake()`, the way the browser does; `DocumentFixtures` is untouched.
- **Files modified:** `tests/Feature/Documents/DocumentsRelationManagerTest.php`
- **Committed in:** 81e9438

**3. [Rule 1 - Bug] The old attachments test could not prove the tab**
- **Found during:** Task 2
- **Issue:** The placeholder text itself contains the word "Soubory", so asserting the tab title was satisfied by the text that had to disappear.
- **Fix:** The rewritten case asserts the placeholder and its heading are absent and that `getRelationManagers()` of the page contains `TaskDocumentsRelationManager`.
- **Files modified:** `tests/Feature/Tasks/SubtasksTest.php`
- **Committed in:** 16e9ac6

**4. [Rule 3 - Blocking] The owner column and its label enum were split across tasks**
- **Found during:** Task 1 / Task 3 boundary
- **Issue:** `DocumentColumns::admin(showOwner)` is part of the Task 1 contract, but the owner column needs `DocumentOwnerType` labels (Task 3 files).
- **Fix:** Task 1 shipped `admin()` with the parameter and no owner column; Task 3 added the column, `withOwners()` and the trait's `modifyQueryUsing` together with the labels. No intermediate commit referenced the missing parts.
- **Files modified:** `app/Filament/Support/DocumentColumns.php`, `app/Filament/Concerns/ManagesDocuments.php`
- **Committed in:** c905e1a

**Total deviations:** 4 auto-fixed (2 bug, 2 blocking). **Impact:** none on the plan's behavior; deviation 1 makes the stated intent (error next to the field) actually hold.

## Authentication Gates

None.

## Issues Encountered

- Livewire's HTML for a mounted action modal is not part of `html()` in these tests, so the visibility hint and default are asserted on the schema component (`assertSchemaComponentVisible` / `Hidden` plus the helper-text schema) instead of the rendered HTML.
- The combined Pint, PHPStan and full-suite command exceeded the 120 s foreground limit and ran in the background; it finished green (4101 passed, 3 skipped, pre-existing). After it, Pint's import ordering and a nullable `task?->reference` PHPStan finding were fixed and the Documents, Localisation, Clients and Arch suites, Pint and PHPStan re-run clean.

## Known Stubs

None.

## Threat Flags

None. All new surface is covered by T-09-17 (Partner on Admin tabs: AccessRule, boot hook, 403 tests on all three tabs), T-09-18 (no URL or bucket host rendered), T-09-19 (the flag is passed only for shareable owners, only TemporaryUploadedFile instances reach the Action) of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4101 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Localisation tests/Feature/Clients tests/Arch` (after the final edits): 425 passed
- `ddev exec vendor/bin/pint --test`: clean; `ddev exec vendor/bin/phpstan analyse`: no errors; `scripts/check-sensitive.sh`: clean on every commit
- All task acceptance criteria re-run: pass

## Next Phase Readiness

Ready for plan 09-06 onward. Plan 09-07 adds rename, the visibility toggle and delete to the same record actions; plan 09-08 builds the Partner tabs from the `documents.*` keys and a shorter column builder; plan 09-13 reuses the trait for the transaction tab. The one open item is a real-browser check that a click on Stáhnout / Zobrazit opens the file (coverage D7).

## Self-Check: PASSED

- Files exist: `ManagesDocuments.php`, the three relation managers, `DocumentColumns.php`, `DocumentActions.php`, `DocumentsRelationManagerTest.php` (found)
- Commits `81e9438`, `16e9ac6`, `c905e1a` are ancestors of HEAD
- `commits: 3` measured from the plan ledger (`2391d02..c905e1a`)
