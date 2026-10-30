---
phase: 10-invoicing
plan: 40
subsystem: time-tracking
tags: [time-entries, reserved-time, filament, reports, markers]

requires:
  - phase: 10-invoicing
    provides: "TimeEntry::isReserved() and invoice() (10-09), the reserved-entry lock (10-10), the draft editor (10-25), the reserved flag of ReportEntry (10-34)"
provides:
  - "Gray V konceptu badge (OutlinedLink, tooltip) next to Nevyfakturovano in the time list and on the entry page"
  - "Otevrit koncept header action on the edit page of a reserved entry"
  - "Faktura :number link on the entry page of an entry billed by an invoice"
  - "The bulk Oznacit jako vyfakturovane toast names the skipped reserved entries"
  - "Reserved seconds in the time report summary and the gray tile line z toho v konceptech"
affects: [10-41]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

plan_head_before: 91c8beae76b0d36779311c1f20e0062e439c5e0b
plan_head_after: c489e06260800692aa4740cd16610e0c225b9b8e
commits: 2

coverage:
  - deliverable: "A reserved entry keeps Nevyfakturovano and gains the gray V konceptu badge with its tooltip in the list and on the view page; a free entry has none"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#marks a reserved entry V konceptu in the list and leaves a free one without the badge"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#shows the V konceptu badge on the view page of a reserved entry and not on a free one"
        status: pass
    human_judgment: false
  - deliverable: "The edit page of a reserved entry links Otevrit koncept to the draft editor; a free entry has no such action"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#links the edit page of a reserved entry to the draft editor and offers no link for a free one"
        status: pass
    human_judgment: false
  - deliverable: "A billed entry's view page shows Faktura plus the number as a link to the invoice; a manually billed entry shows none"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#shows Faktura plus the number as a link on the view page of an invoiced entry and no link for a manually billed one"
        status: pass
    human_judgment: false
  - deliverable: "The bulk billing toast bills the free entry and names the skipped reserved one"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#bills the free entry of a bulk selection and names the skipped reserved one in the toast"
        status: pass
    human_judgment: false
  - deliverable: "The report summary carries the reserved seconds (2 h of 5 h unbilled) and the tile shows z toho v konceptech: 2:00, absent without reserved time; reserved time stays inside the unbilled value"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#names the 2 h reserved part of 5 h unbilled time in the report summary and in the tile"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#omits the reserved line of the tile when no reserved time exists in the period"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/ReservedEntryMarkersTest.php#counts the reserved seconds as exactly the seconds of the entries flagged reserved, billed and free ones apart"
        status: pass
    human_judgment: false
  - deliverable: "Visual adequacy of the gray badge and the tile line in the browser"
    verification: []
    human_judgment: true
    rationale: "Tests assert text, state and links, not the rendered look; the UI-SPEC appearance is checked by the phase UI review"

key-files:
  created:
    - tests/Feature/TimeTracking/ReservedEntryMarkersTest.php
  modified:
    - app/Filament/Resources/TimeEntryResource.php
    - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
    - app/Domain/Reports/TimeReport.php
    - resources/views/filament/pages/time-report.blade.php
    - lang/cs/invoices.php

key-decisions:
  - "The marker is a separate gray column and infolist entry, not a fourth BillingBadge case: BillingBadge stays the display state of the stored billing state, so the Nevyfakturovano badge and the list filter keep their meaning for reserved time"
  - "The summary reserved seconds count only billable, unbilled, reserved entries and are never subtracted from the unbilled figure (D-04, R-06-3)"
  - "The invoice link sits in the shared infolist and appears only for a billed entry whose invoice carries a number, so a manually billed entry has no link"

requirements-completed: [IN-02]

duration: 35 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 40: Reserved and invoiced time markers Summary

**Time held by a draft or billed by an invoice is recognisable in the time list, on the entry pages and in the report tile, and reserved time still counts as unbilled.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 1 created, 5 modified

## Accomplishments

- The time list and the entry page show the gray `V konceptu` badge (`OutlinedLink`, tooltip "Záznam je připojen ke konceptu faktury.") beside the unchanged info badge `Nevyfakturováno` for a reserved entry; the edit page gains the header action `Otevřít koncept` that opens the draft editor.
- The entry page of an entry billed by an issued invoice shows `Faktura :number` as a link to the invoice view; a manually billed entry has no invoice and no link.
- The bulk action `Označit jako vyfakturované` keeps its title and adds a body that counts the skipped reserved entries with a Czech plural ("Přeskočen 1 záznam připojený ke konceptu faktury. Odpojte ho v konceptu."). A selection of only reserved entries still ends in the Phase 10-10 refusal.
- `TimeReport` sums the reserved seconds of billable unbilled entries into `summary['reserved']`; the `Nevyfakturováno` tile prints the gray line `z toho v konceptech: 2:00` only when that figure is above zero, and the unbilled value itself is unchanged.
- Eight tests in `ReservedEntryMarkersTest.php`: five for the screens, three for the report.

## Task Commits

1. **Task 1 (tracer): badge, draft link, invoice link, bulk toast** - `464b529` (feat)
2. **Task 2: reserved seconds in the report summary and the tile line** - `c489e06` (feat)

## Verification

- Full suite `ddev exec vendor/bin/pest --compact`: 5784 passed, 3 skipped (pre-existing skips), run after the last commit.
- `tests/Feature/TimeTracking` and `tests/Feature/Reports` green; Pint and PHPStan clean; `scripts/check-sensitive.sh` and gitleaks clean on both commits; the acceptance greps of both tasks pass.
- The Partner stays closed to all three surfaces: the resource keeps `Audience::AdminOnly` and the report page is untouched in its access rule (T-10-92); the existing panel access tests stayed green.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan nullsafe finding on the invoice relation**
- **Found during:** Task 1
- **Issue:** `$record->invoice?->number ?? ''` was flagged (`nullsafe.neverNull`) because the relation is typed non-null.
- **Fix:** the link state uses an `instanceof Invoice` check.
- **Files modified:** `app/Filament/Resources/TimeEntryResource.php`
- **Commit:** `464b529`

**Total deviations:** 1 auto-fixed (1 bug). **Impact:** none on the behavior.

### Plan variance (no code deviation)

- `ViewTimeEntry.php` was listed in `files_modified` but needed no change: the view page renders the shared `TimeEntryResource::infolist()`, where the badge and the invoice link live.
- The confirmation modal of the bulk action still lists the skip reasons as "nefakturovatelný, už vyfakturovaný nebo běží" (Phase 6 wording, pinned by `BillingLockTest`); a reserved entry is counted in its skipped total, and its reason is named in the toast afterwards. Adding "připojený ke konceptu" to the modal reason list is a small follow-up if the owner wants it.

## Known Stubs

None.

## Threat Flags

None. The new links lead from Admin-only screens to Admin-only invoice routes; no new endpoint, auth path or schema change.

## Next Phase Readiness

Ready for the next plan of wave 10.

## Self-Check: PASSED

- `tests/Feature/TimeTracking/ReservedEntryMarkersTest.php`, `app/Domain/Reports/TimeReport.php`, `resources/views/filament/pages/time-report.blade.php` exist; commits `464b529` and `c489e06` are ancestors of HEAD.
