---
phase: 09-documents-and-finance
plan: 18
subsystem: documents
tags: [documents, zip-archive, signed-url, streaming, nosniff, route-walk, owner-scope, prune, scheduler, filament-resource]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-03 signed, user-bound file route and DocumentUrl/DocumentDisposition; 09-17 RequestDocumentArchive and BuildDocumentArchive; 09-23 DocumentArchive model (isDownloadable, downloadFileName, storagePath), policy and path CHECK"
  - phase: 08-time-reports-and-exchange-rates
    provides: "ReportExportResource and PruneExpiredReportExports twins, schedule-on-one-server contract"
provides:
  - "GET /admin/files/archives/{archive} (route name filament.admin.files.archive): the checked, signed, user-bound ZIP download streamed by the app"
  - DocumentUrl::archive() and DocumentUrl::ARCHIVE_ROUTE
  - "DocumentArchiveResource: the Admin-only 'ZIP archivy' list (polls every 5 s while pending, download action that redirects to a freshly signed link)"
  - "PruneExpiredDocumentArchives and the schedule entry kokpit-document-archive-prune (daily, on one server)"
  - Route walk extended with the archive route signed for a Partner
affects: [09-19 hardening (bells linking to this list, direct zipstream requirement, failure handling, safe entry names)]

actuals:
  tokens: 13500
  tasks: 3
  commits: 4
plan_head_before: 658119738d1aa115390b5f09b76260317d63ce6f
plan_head_after: 9fa6abf6c70f8941f943438536511d9033e5f85f
commits: 4

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Second route in the same authenticatedRoutes() closure and middleware as the document route; the controller checks in a fixed order (user binding, UUID, owner-scoped lookup, policy, isDownloadable on every request, object exists)"
    - "List action builds the signed URL at click time and redirects through the Livewire component; no signed URL is ever rendered into HTML, and a ZIP is never buffered into a Livewire response"
    - "Prune twin of the report export prune: system context, chunked by id, object first then record, missing object tolerated"

key-files:
  created:
    - app/Filament/Resources/DocumentArchiveResource.php
    - app/Filament/Resources/DocumentArchiveResource/Pages/ListDocumentArchives.php
    - app/Http/Controllers/DocumentArchiveFileController.php
    - app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php
    - tests/Feature/Documents/DocumentArchiveResourceTest.php
    - tests/Feature/Documents/DocumentArchivePruneTest.php
  modified:
    - app/Domain/Documents/Support/DocumentUrl.php
    - app/Providers/Filament/AdminPanelProvider.php
    - routes/console.php
    - lang/cs/documents.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php
    - tests/Arch/JobContractTest.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "The route name is filament.admin.files.archive, confirmed with route:list (path /admin/files/archives/{archive}); it sits in the same authenticatedRoutes closure and middleware (SetNoReferrerPolicy, signed, throttle:document-files) as the document route, and the existing */admin/files/* SPA exception covers it"
  - "The expiry is re-checked on every request via isDownloadable(now), so a still-valid signed link cannot outlive the archive's 7-day retention"
  - "The download action redirects to the signed route instead of streaming through Livewire (research Pitfall 2); the controller streams with Content-Length taken from the stored object"
  - "Another Admin, a Partner, a pending, failed or expired archive and a missing object all answer 404 identically, so none of them can be told apart from an unknown id; a bad signature or a link signed for another user answers 403"

patterns-established:
  - "walkedUrl() fills an {archive} parameter with the canary archive of a side and signs it for the signed-in user; only the one planner owner Admin is ever served, so the walk proves the refusal comes from the owner scope"

requirements-completed: [DO-02, DO-03]

coverage:
  - id: D1
    description: "The Admin sees the own ZIP archives on a 'ZIP archivy' list with creation time, summary, status and expiry; the list polls every 5 s only while an archive is pending; another Admin's archive is not listed and a Partner gets 403"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveResourceTest.php#tracer: the Admin opens the archives list and downloads a ready ZIP through the signed route"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveResourceTest.php (the list: polling, expiry column, empty state, owner scope, navigation)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Downloading a ready archive redirects to GET /admin/files/archives/{archive}, signed, short-lived and bound to the user; the route streams the exact ZIP as an application/zip attachment named soubory-YYYY-MM-DD.zip with nosniff and private no-store; no signature or bucket URL is in the list HTML"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveResourceTest.php#tracer: the Admin opens the archives list and downloads a ready ZIP through the signed route"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveResourceTest.php#renders no signed link into the list HTML"
        status: pass
      - kind: command
        ref: "ddev exec php artisan route:list --name=files.archive lists filament.admin.files.archive"
        status: pass
    human_judgment: false
  - id: D3
    description: "Another Admin's, a pending, failed or expired archive and a missing object answer 404 with nothing streamed; unsigned, tampered, expired-signature and other-user links answer 403; a Partner gets 403 on the list and 404 on the route; the route walk finds no canary of either client behind a Partner link"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchiveResourceTest.php (who gets an archive, and when)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php (archive route cases and the Partner A / no-client walks)"
        status: pass
    human_judgment: false
  - id: D4
    description: "A daily scheduled prune, on one server, deletes the object and then the record of every archive past its expiry (7 days after completion; pending and failed records 7 days after creation), for every Admin, in chunks, and tolerates a missing object"
    requirement: DO-02
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentArchivePruneTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/ScheduleOnOneServerTest.php#schedules the document archive prune daily on one server"
        status: pass
      - kind: unit
        ref: "tests/Arch/JobContractTest.php (PruneExpiredDocumentArchives listed with an Idempotent declaration)"
        status: pass
    human_judgment: false

# Metrics
duration: 20min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 18: Archive List, Checked Download Route and Daily Prune Summary

**Admin-only "ZIP archivy" list with a click-time signed redirect to `/admin/files/archives/{archive}` (owner-scoped, expiry re-checked per request, streamed as an application/zip attachment with nosniff and no-store) and a daily one-server prune that deletes each expired archive object, then its record**

## Performance

- **Duration:** about 20 min
- **Completed:** 2026-10-16
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files modified:** 13 (6 created, 7 modified)

## Accomplishments

- The Admin opens "ZIP archivy", sees the own archives (created, summary, status badge, expiry for a ready row), and the list polls every 5 s only while one is pending. The download action is visible only for a downloadable archive; at click time it re-reads the record, re-checks it and the object, then redirects to a freshly signed link, or shows a Czech toast when the archive is gone.
- `DocumentArchiveFileController` streams the exact stored bytes as `application/zip` named `soubory-YYYY-MM-DD.zip` with `nosniff` and `private, no-store`. Check order: user binding (403), UUID (404), owner-scoped lookup (404), policy, `isDownloadable(now)` (404), object exists (404). No bucket URL reaches the browser.
- `PruneExpiredDocumentArchives` (system context, chunked by id, object first) is scheduled as `kokpit-document-archive-prune`, daily, on one server, and is listed in the job contract.
- The route walk now fills `{archive}` with the canary archive of a side signed for the signed-in user. Partner A and a Partner without a client get 404/403 with no canary in the body; the owning Admin is served and a second Admin gets 404, so the refusal provably comes from the owner scope.

## Task Commits

1. **Task 1 (tracer): archives list and signed download route** - `fa65e59` (feat)
2. **Task 2: route walk extension for the archive route** - `f3293ec` (test)
3. **Task 3: prune** - `3db94c7` (test, RED) then `9fa6abf` (feat, GREEN)

**Plan metadata:** committed separately (docs: complete plan).

## Files Created/Modified

- `app/Filament/Resources/DocumentArchiveResource.php` - Admin-only list resource, slug `document-archives`, sort 56, not searchable, no create
- `app/Filament/Resources/DocumentArchiveResource/Pages/ListDocumentArchives.php` - list page with the retention subheading
- `app/Http/Controllers/DocumentArchiveFileController.php` - checked streaming of one archive
- `app/Domain/Documents/Support/DocumentUrl.php` - `archive()` and `ARCHIVE_ROUTE`
- `app/Providers/Filament/AdminPanelProvider.php` - second route in the `authenticatedRoutes` closure
- `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` - 7-day retention
- `routes/console.php` - schedule entry
- `lang/cs/documents.php` - `archives.{navigation, model_label, plural_label, subheading, columns, actions, gone, empty}`
- Tests: `DocumentArchiveResourceTest`, `DocumentArchivePruneTest`, `ScheduleOnOneServerTest`, `JobContractTest`, `RouteWalkTest`

## Decisions Made

See `key-decisions` above. The route name was confirmed with `route:list`: `filament.admin.files.archive`.

## Deviations from Plan

None - plan executed exactly as written. Two small notes, neither a deviation: the "who gets an archive" refusal tests were written together with the tracer tests and committed with Task 1 because they passed against the same controller, so Task 2 contained only the route walk extension and found no gap to close in the controller; and one prune test fixture initially violated the existing `document_archives_count_check` constraint (fixture error, fixed in the same GREEN commit).

**Total deviations:** 0 auto-fixed.

## Issues Encountered

- The first run of the navigation test showed that Filament caches the navigation per test, so the Admin and Partner assertions were split into two tests (as in `ReportExportResourceTest`).

## Known Stubs

None.

## Threat Flags

None. The new surface (the archive route) is the one covered by the plan's threat model (T-09-58, T-09-59, T-09-60); `composer.json` and `composer.lock` were not touched (T-09-SC).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Ready for 09-19: the bell notifications can link to `DocumentArchiveResource::getUrl('index')`; failure marking, safe entry names, the job-side limit re-check and the direct `maennchen/zipstream-php` requirement remain in that plan.
- Verification: full `ddev exec vendor/bin/pest` green (4475 passed, 3 skipped), Pint and Larastan clean, `scripts/check-sensitive.sh` clean on every commit.

## Self-Check: PASSED

- All created files exist on disk (resource, list page, controller, prune job, both new test files).
- Commits `fa65e59`, `f3293ec`, `3db94c7`, `9fa6abf` are on `base-crm-erp`; `git rev-list --count` from the recorded base gives 4.
- Task acceptance criteria re-run: route name listed by `route:list`, all greps succeed, targeted tests and the full suite exit 0.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
