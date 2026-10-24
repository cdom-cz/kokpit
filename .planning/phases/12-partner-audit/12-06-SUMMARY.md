---
phase: 12-partner-audit
plan: 06
subsystem: testing
tags: [pest, audit, canary, channel-matrix, filament, livewire, signed-urls, downloads, exports]

requires:
  - phase: 12-partner-audit
    provides: ChannelMatrix, ChannelProbe (seed, blob, needles, four drivers) and ChannelMatrixTest from plan 12-05; the closed Filament export and import routes (404) from plan 12-02
provides:
  - ChannelProbe ui driver (every filament.admin GET route with client A and client B records and signed file URLs, plus every accessible Partner list mounted with Livewire and searched for every needle)
  - ChannelProbe downloads driver (signed document, hidden twin, archive and invoice PDF links for both clients, report export list refused) and exports driver (export pages, TimeReportPage via Livewire, closed Filament export and import routes)
  - ChannelProbe::DRIVEN extended to seven channels (search, selects, api, activity, ui, downloads, exports); notifications remains for plan 12-07
  - Three controls ("not vacuous") and one self-test for the walk helpers in ChannelMatrixTest
affects: [12-07, 12-08]

actuals:
  tokens: 7560
  tasks: 2
  commits: 2

plan_head_before: ea6ba871e07f840c56adfadd94d71eea3ccccfa7
plan_head_after: 30e64574a231cfba3c335291462834d3f036bfe7
commits: 2

tech-stack:
  added: []
  patterns:
    - "A channel driver returns attributed segments ({owners, text}); compose() builds the blob of one entity from the segments that belong to it, adds every segment when a client B needle or id appears anywhere, and adds a client A needle that differs from the canary text (it identifies its entity wherever it shows)"
    - "A response is attributed to the model of the resource that produced it, to the model of the file for a file route, to PAGE_OWNERS for a page of no resource, and to every entity for any other page, so an unlisted page that shows a canary fails every cell it does not belong to"
    - "The one echo of a Livewire table search (the active search badge, label plus term) is removed from the rendered HTML; a row that shows the term is left alone"
    - "Each driver asserts the statuses it must see (own file 200, every other file 403 or 404, export pages 403, closed routes their declared status) so a refusal is never read from an empty body"

key-files:
  created: []
  modified:
    - tests/Support/ChannelProbe.php
    - tests/Audit/ChannelMatrixTest.php

key-decisions:
  - "Attribute ui, downloads and exports output to entities (resource model, file model, PAGE_OWNERS) because many entities share one canary text; client B needles stay global, so attribution can only narrow the check of a none cell for client A, never hide client B data"
  - "The Partner dashboard is attributed to Project and Task (its two table widgets PartnerProjects and PartnerTasks); any other page of no resource stays attributed to every entity"
  - "The downloads driver also asserts that the hidden twin of the own visible file is refused, because the needle (the canary text) cannot tell the visible file from its twin"
  - "No separate fakeDisk() call: prepare() already fakes the media disk, and a second Storage::fake would delete the uploaded document files before the driver requests them"
  - "walkUrl() and walkRecordId() are public so the unknown-parameter and unknown-resource guards can be self-tested; every other walk helper is private"

patterns-established:
  - "Adding a panel page that shows entity rows to a Partner means adding it to ChannelProbe::PAGE_OWNERS in the same pull request"
  - "A new file route is a new branch in walkUrl(); an unknown route parameter or resource fails the walk loudly"

requirements-completed: [AUD-01]

coverage:
  - id: D1
    description: "Every entity is walked through every panel GET route and every Partner list search as Partner A: no client B needle or id, and for a none cell no client A needle, appears (38 ui cells)"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps the canaries of the cell out of Partner A's channel (x ui, 38 cells)"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets Partner A see the own canary on the own pages, so the UI rows are not vacuous"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#still refuses a route parameter and a resource it does not know, so a new kind of route is looked at on purpose"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every applicable entity is walked through the signed file routes (own file served; hidden twin, foreign file, archives and foreign invoice PDF refused; report export list refused) with body and Content-Disposition checked (7 downloads cells)"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps the canaries of the cell out of Partner A's channel (x downloads, 7 cells)"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#serves the Admin the files of both clients, so the download rows are not vacuous"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every applicable entity is walked through the export paths (five export pages answer 403, TimeReportPage refused through Livewire, the closed Filament export and import routes answer 404) (13 exports cells)"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps the canaries of the cell out of Partner A's channel (x exports, 13 cells)"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#lets the Admin open the export pages, so the export rows are not vacuous"
        status: pass
    human_judgment: false
  - id: D4
    description: "Attribution of a page of no resource and the reading of a none ui cell for an entity that shares its canary text with a foreign entity are judgments"
    requirement: AUD-01
    verification: []
    human_judgment: true
    rationale: "A none ui cell for an entity whose text equals the canary of a foreign entity can only be proved through the resource that owns it; whether that residual blind spot is acceptable is presented to the owner in plan 12-08"

duration: 13 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 06: UI, Downloads and Exports Drivers Summary

**Partner A is now walked through every panel route and Partner list search (38 ui cells), every signed file route (7 downloads cells) and every export path (13 exports cells), with an attribution layer that keeps shared canary text from drowning the none cells and three controls proving the channels reach real data.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-10-24T00:16:07Z
- **Completed:** 2026-10-24T00:29:05Z
- **Tasks:** 2 (1 tracer, 1 auto)
- **Files modified:** 2 (0 created, 2 modified; `tests/Support/ChannelMatrix.php` needed no change)
- **Audit suite duration:** 549 tests, 1109 assertions, 127 s (the ui rows dominate: 38 rows in about 32 s, because each row re-seeds the scene and re-walks every route, D-12)

## Accomplishments

- Task 1 (tracer): the ui driver copies the walk of `RouteWalkTest` into private `walk*` methods of `ChannelProbe`: every `filament.admin.*` GET route except login and logout, record routes with the client B and the client A record, file routes with signed URLs of both clients, an unknown parameter or resource throws a `LogicException` that names it. A status outside 200, 302, 403, 404 or a client B record route that is not refused with 403 or 404 fails with the route named. Every list page whose resource Partner A may access is mounted with Livewire and searched for every needle of both sides (the search badge echo removed). 38 ui cells, a control and a self-test of the guards. `⚡ Tracer verified end-to-end — expanding`.
- Task 2: the downloads driver requests the signed link of the own visible file (served), the hidden twin (refused), the client B file, both archives (bytes stored so the refusal comes from the scope), both issued invoices' PDFs (own served, foreign refused) and mounts the report export list (refused); the exports driver requests the time report, report export list, document archive list, finance overview and timesheet (403 each), mounts `TimeReportPage` through Livewire (refused) and requests the two closed Filament routes with fresh ids (404). 7 downloads cells and 13 exports cells, two controls.
- Mutation checks by hand (each restored afterwards, `git status` clean for `app/`): dropping the client constraint of `Project::constrainForPartner()` fails `Project x ui` and `Task x ui` with the client B needle named; dropping the constraints of both `Document` and `Project` fails `Document x downloads` and `Invoice x downloads` with `document b answered 200`; running the exports driver as the Admin fails with all five export pages answering 200.
- `tests/Isolation` 356 tests green and byte-identical to `58c5e93` (D-08); Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, the ui driver and its controls** - `ac2e3b8` (test)
2. **Task 2: downloads and exports drivers and their controls** - `30e6457` (test)

**Plan metadata:** committed separately as docs(12-06).

## Files Created/Modified

- `tests/Support/ChannelProbe.php` - `DRIVEN` now lists seven channels; `uiBlob()`, `compose()`, `uiSegments()`, `walkLists()`, `walkOwners()`, `describe()`, the copied `walk*` helpers, `downloadSegments()`, `downloadUrls()`, `storeInvoicePdfs()`, `storeArchives()`, `exportSegments()`, `exportUrls()`, `PAGE_OWNERS`
- `tests/Audit/ChannelMatrixTest.php` - controls "lets Partner A see the own canary on the own pages, so the UI rows are not vacuous", "serves the Admin the files of both clients, so the download rows are not vacuous", "lets the Admin open the export pages, so the export rows are not vacuous", and the guard self-test

## Production files touched by a D-09 fix

None. No leak was found in the ui, downloads or exports channel; no production file was edited.

## Cell corrections (finding table, D-02 / D-07)

None. All 38 ui cells, 7 downloads cells and 13 exports cells held as declared by plan 12-05, so no `none` cell had to become `foreign`.

| Entity x channel | Declared | Observed | Change |
|---|---|---|---|
| (none) | - | - | - |

## Findings for the owner (no change made)

1. **A shared canary text limits what a `none` ui cell proves.** A client name, a contact name, a project name and a task title are all the same canary string, so a page cannot tell which entity printed it. The ui blob of an entity is therefore built from the pages of the resource that owns it (plus the file routes and, for the dashboard, `PAGE_OWNERS`). A `none` cell such as `Client x ui` proves that the `clients` resource and every unlisted page are closed to Partner A; it does not prove that the own client name is absent from a project page of the Partner. Entities whose needle is not the canary text (payment link, card payment event, the Signal Monday, the exchange rate date, the planner owner id) are checked in every response. Closing the blind spot would need a distinct text per entity in `CanaryRegistry`, which touches the plan 12-05 fixtures; presented for the plan 12-08 sign-off.
2. **Defence in depth hid a rule mutation.** Changing the `#[AccessRule]` of `TimeReportPage` from `AdminOnly` to `PartnerAllowed` did not make Partner A reach the page (still 403), so another layer refuses it too. The exports driver itself is proved by running it as the Admin (all five pages answer 200, the rows fail). Nothing to fix; noted because one declaration alone is not the only gate.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

None - plan executed exactly as written. Inside the plan's latitude: the walk helpers `walkUrl()` and `walkRecordId()` are public (the plan said private `walk*` methods) so the "does not know the parameter" guard could get a self-test; the hidden-twin refusal is asserted by the driver; `ChannelMatrix.php` needed no edit.

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

- The first ui run failed every `none` row because the Partner dashboard shows two widgets with the canary text and was attributed to every entity. Fixed by listing the dashboard in `PAGE_OWNERS` with its two widget models (not a cell change: both entities are `foreign` for ui).
- The echo of a searched needle in the active search badge ("Hledat: term") would have read as a hit in every Livewire list. The label plus term is removed from the HTML, a row that shows the term is not.
- My first Document mutation (dropping only the Document constraints) did not fail, because the owner subquery goes through the scoped `Project` query; the second mutation dropped both and was caught.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code only; no network endpoint, auth path, file access or schema was added.

## Next Phase Readiness

- Plan 12-07 adds the notifications driver: add `notifications` to `ChannelProbe::DRIVEN` and a `match` arm in `blob()`; the cells are declared. `compose()` is available for any channel that reads several entities.
- Plan 12-08 can present the finding about shared canary text (D4) together with the declared cells and not-applicable reasons.

## Self-Check: PASSED

- Modified files exist: tests/Support/ChannelProbe.php, tests/Audit/ChannelMatrixTest.php
- Commits `ac2e3b8` and `30e6457` are ancestors of HEAD; `git rev-list --count ea6ba87..HEAD` printed 2 before this SUMMARY commit
- Acceptance criteria re-run and passing: `'ui'`, `'downloads'`, `'exports'` and `InvoicePdfUrl::download` present in ChannelProbe; "does not know the parameter" present; 38 ui tests green; 20 downloads and exports tests green; 7 "not vacuous" tests green; `tests/Isolation/TimeLeakTest.php` and `DocumentVisibilityTest.php` green (35 tests); `tests/Isolation` unchanged against `58c5e93`
- Plan-level verification re-run: Audit suite 549 passed, `tests/Isolation` 356 passed, `vendor/bin/pint --test` clean, PHPStan no errors

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
