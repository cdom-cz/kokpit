---
phase: 08-exchange-rates-and-reports
plan: 11
subsystem: reports
tags: [filament, report-exports, private-storage, livewire-download, owner-scope, admin-only, czech-copy]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-07 ReportExport (OwnedByUser, isDownloadable, downloadFileName, ttlDays), the status and format enums and the kokpit.exports.* Czech copy"
  - phase: 07-api-and-integrations
    provides: "the ApiTokenResource list-only pattern (AccessRule, EnforcesResourceAccessRule, row action with a server-side re-check)"
provides:
  - "ReportExportResource: Admin-only 'Exporty' list (sort 43, no group, list page only) with the columns, badges, 25 rows per page and empty state of UI-SPEC C2"
  - "A row action 'Stáhnout export' that re-reads the row through the owner scope, re-checks isDownloadable() and the stored file, then downloads from the private default disk under the server-generated name"
  - "A 10 s table poll only while an export of the owner is pending"
  - "ReportExportResourceTest: list, download, refusal, poll, failed row, empty state, no-actions and access proofs"
affects: [08-12 prune after 7 days, 08-16 CSV build and export action, 08-21 XLSX build, 08-17 report page link to Exporty]

plan_head_before: b5c2eb40ba47ed476fffd4ac6e7afd831f9201b6
plan_head_after: 69b99f081592e65929a63959b97e058abfa7770d

estimate:
  tokens: 33000
  raw_tokens: 33000
  tasks: 2
  confidence: low

actuals:
  tokens: 4500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A list-only Admin resource whose query is the owner scope of the model, with one row action that re-reads and re-checks at click time before any file leaves the private disk"
    - "A table poll declared by a closure that returns null when nothing is pending"

key-files:
  created:
    - app/Filament/Resources/ReportExportResource.php
    - app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php
    - tests/Feature/Reports/ReportExportResourceTest.php
  modified: []

key-decisions:
  - "The download handler checks the stored file with Storage::disk()->exists() next to isDownloadable(), so a pruned or deleted file answers the danger toast instead of a server error (already in Task 1, so Task 2 needed no resource change)"
  - "A click on an export that expired after the render is refused by Filament itself: the row action's visibility is re-evaluated on the server for the call, so the handler is never reached and no file is sent; the gone toast is shown for the case where the row still looks downloadable but the file is missing"
  - "A forged download call for another Admin's export fails to resolve a record (ActionNotResolvableException), because the table query is the owner scope; nothing is downloaded"
  - "The resource declares $isGloballySearchable = false explicitly, like the other resources, although the panel is opt-in already"

patterns-established:
  - "Forged Livewire call proofs rebuild the component per call as the Admin, then switch the session to the Partner before calling (the ApiTokenScreenTest pattern)"

requirements-completed: [RE-03]

coverage:
  - id: D1
    description: "The Admin opens 'Exporty' and sees the own exports newest first with Vytvořeno, Formát, Zadání, Stav and Platí do (ready only) under the 7-day subheading, 25 rows per page, and the empty state when there is none"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportResourceTest.php#the list (Admin)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportResourceTest.php#the table behaviour"
        status: pass
    human_judgment: false
  - id: D2
    description: "A ready, unexpired export of the signed-in Admin downloads through the app from the private disk under its server-generated name; there is no public or signed URL and no new route"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportResourceTest.php#the list (Admin) it shows the own ready export ... and downloads it"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "A pending, failed, expired, deleted-file or foreign export yields no file; a missing file shows the danger toast 'Soubor už není k dispozici. Spusťte export znovu.'"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportResourceTest.php#refused downloads"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner is refused the list and forged Livewire calls (403), a guest is redirected, the resource has no create, edit, view, delete, bulk or header action and is not globally searchable"
    requirement: RE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Reports/ReportExportResourceTest.php#who may open it"
        status: pass
      - kind: integration
        ref: "tests/Arch/PanelRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "How the list and the download read and feel in the browser (layout, badge colours, polling while an export is queued)"
    verification: []
    human_judgment: true
    rationale: "Tests assert the declared columns, polling interval and texts, not the rendered look in a real browser; a visual check belongs to phase verification"

duration: 10min
completed: 2026-10-14
status: complete
---

# Phase 8 Plan 11: Exports list and checked download Summary

**Admin-only 'Exporty' list (owner-scoped, list page only) with a Livewire row action that re-reads the row, re-checks readiness, expiry and the stored file at click time and then downloads from the private disk under a server-generated name**

## Performance

- **Duration:** about 10 min (the full suite alone takes 4.5 min)
- **Completed:** 2026-10-14T21:06:00Z
- **Tasks:** 2
- **Files modified:** 3 (3 created)

## Accomplishments

- `ReportExportResource` and `ListReportExports` per UI-SPEC C2: Vytvořeno (Prague time, `j. n. Y H:i`, newest first), Formát (gray badge), Zadání (with the plain failure tooltip on failed rows, never exception text), Stav (enum colour and icon), Platí do (ready rows only, `—` otherwise), 25 rows per page, the empty state from the copy, and the subheading with `:days` from `ReportExport::ttlDays()`.
- The `download` row action is visible only when `isDownloadable(now())`; the handler asserts the Admin audience, re-reads the row through the owner scope, re-checks it and the file on the private default disk, then returns `Storage::disk()->download(path, downloadFileName())`; otherwise the danger toast `kokpit.exports.gone` and no file. No route, no public or temporary URL (T-08-38, T-08-39).
- The table polls every 10 s only while a pending export of the owner exists.
- 16 tests: download, pending/failed/expired rows without the action, a click after expiry, a deleted file with the gone toast, a second Admin's export (not listed, not resolvable), the poll on and off, ordering and page size, the failed row, empty state, no create/edit/view/delete/bulk/header action, Partner 403 on the URL, on the Livewire component and on forged calls, a guest redirect and the navigation item (sort 43, no group; absent for a Partner).

## Task Commits

1. **Task 1: Tracer - the Admin opens "Exporty" and downloads a ready export of their own through the app** - `a7bc4fe` (feat)
2. **Task 2: Refused downloads, the pending poll, the failed row and the Partner and second-Admin proofs** - `69b99f0` (test)

**Plan metadata:** the docs commit that carries this file (docs: complete plan)

The tracer feedback gate ran after Task 1: the tracer's automated verify (targeted Pest, Pint, PHPStan) was re-run end-to-end, passed, and the plan expanded to Task 2.

## Files Created/Modified

- `app/Filament/Resources/ReportExportResource.php` - the Admin-only list resource with the checked download action
- `app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php` - the list page with the title and the retention subheading, no header action
- `tests/Feature/Reports/ReportExportResourceTest.php` - the list, download, refusal, table-behaviour and access proofs

## Decisions Made

- The handler checks the file with `Storage::disk()->exists()` together with `isDownloadable()`, so a pruned file shows the gone toast rather than a storage error.
- Expiry between render and click: Filament re-evaluates the action's visibility on the server for the call, so the handler is not reached and no file is sent. The plan's wording "answers the gone notification" is therefore met by the deleted-file case; for the expired case the proof is "no file and no error".
- A forged key of another Admin's export cannot resolve to a record at all (owner scope on the table query), which is stricter than a toast.
- Planner assumption kept (T-08-40, accepted): a Livewire download holds the file in memory (research Pitfall 10); a controller route is the documented fallback above about 20 MB. No route was added.

## Deviations from Plan

None - plan executed exactly as written.

Two test-shape adjustments, not deviations from the behaviour: the Filament test helper `callTableAction` refuses to call a hidden action, so the expired-after-render case drives `mountTableAction` and `callMountedTableAction` directly; and the Partner navigation check is a separate test because the panel navigation is built once per request. The behaviour-list items for Task 2 were all satisfied by the Task 1 resource (the file check was written there), so Task 2 changed tests only.

## Issues Encountered

PHPStan flagged four errors on the first Task 1 pass (a `__()` return typed `array|string|null` in a tooltip closure, and `find()` returning a collection-or-model union); both were fixed before the Task 1 commit (`(string)` cast, `whereKey()->first()`).

## Known Stubs

None.

## Threat Flags

None - no new route, endpoint or storage access beyond the planned Livewire action.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plans 08-16 and 08-21 can create the rows and files this list hands out; plan 08-12 prunes them after 7 days; plan 08-17 can link to the list from the report page.
- Verification results: full suite 3586 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.

## Self-Check: PASSED

- FOUND: app/Filament/Resources/ReportExportResource.php
- FOUND: app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php
- FOUND: tests/Feature/Reports/ReportExportResourceTest.php
- FOUND: a7bc4fe, 69b99f0 (ancestors of HEAD)
- Acceptance criteria of both tasks re-run: all pass

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-14*
