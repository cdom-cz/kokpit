---
phase: 06-time-tracking
plan: 07
subsystem: time-tracking
tags: [laravel, filament, livewire, postgres, bulk-actions, summarizers, pest]

requires:
  - phase: 06-time-tracking
    provides: "TimeEntryResource, entryFields, withElapsedSeconds, CzechCollation (06-06); MarkEntriesBilled, CancelEntriesBilling, DeleteTimeEntry, OverlapFinder, TimeEntryRateResolver (06-01 to 06-05)"
provides:
  - "BillingBadge display enum (unbilled, billed with OutlinedLockClosed, non_billable) and TimeEntry::billingBadge()"
  - "TimeEntryResource::tableColumns(), billingBulkActions(), deleteAction() and filters() as public static builders for the timesheet day view and the project entries tab"
  - "Billed lock on the screens: canEdit/canDelete overrides, edit URL redirect to the view page, locked callout, Zrušit fakturaci on the view page"
  - "Period, client, project, task and billing filters with indicators; footer Celkem over the whole filtered set with the billable split"
  - "TimeEntry::scopeWithOverlapFlag: overlaps and overlap_label in the list query, no extra queries"
  - "Platná sazba on the entry page, TimeEntryHistoryRelationManager (Admin only)"
affects: [06-08, 06-09, 06-10, 06-11, 06-12, phase-10-invoicing]

tech-stack:
  added: []
  patterns:
    - "Bulk billing closures read only the selected keys (fetchSelectedRecords(false) + getSelectedTableRecords(false)) and hand them to the Actions; the modal text comes from the Action's preview()"
    - "The policy cannot hold the billed lock because the Admin passes KokpitPolicy::before(); the resource's canEdit/canDelete add it, the Actions and the KP001 trigger stay the real guards"
    - "Period filters compute calendar days on plain dates and convert each Prague midnight to UTC; the query is a half-open range on started_at"
    - "Footer totals: table->summaries(pageCondition: false) so only the whole-set row renders; Sum summarizers narrowed with query() for the split"
    - "Correlated sub-selects (EXISTS and LIMIT 1) put the overlap flag and label into the list query"

key-files:
  created:
    - app/Domain/TimeTracking/Enums/BillingBadge.php
    - app/Filament/RelationManagers/TimeEntryHistoryRelationManager.php
    - tests/Feature/TimeTracking/TimeEntryListTest.php
  modified:
    - app/Filament/Resources/TimeEntryResource.php
    - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
    - app/Filament/Resources/TimeEntryResource/Pages/ViewTimeEntry.php
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php
    - tests/Feature/TimeTracking/BillingLockTest.php
    - tests/Feature/TimeTracking/TimeEntryResourceTest.php

key-decisions:
  - "UI-SPEC deviation, recorded in the plan: an extra Úkol filter, because the task page link Zobrazit záznamy (plan 06-10) needs the list filtered to one task"
  - "The footer renders only the row over the whole filtered set (summaries(pageCondition: false)); Filament's stock page row would read as a second total and added queries per page"
  - "A billed entry opened on a stale edit page answers 403 on the next request (EditRecord::hydrate authorizes again), so the 06-06 test that expected a danger toast now expects 403; the Action's own refusal stays covered by the domain tests"
  - "Delete visibility is canDelete(): hidden for billed and running entries, so a delete confirmation opened before another request billed the entry simply does nothing"
  - "The overlap label names the other entry as task reference and title, or the client when it has no task, without times (the form callout keeps its times)"
  - "The view page unlock updates the page record in place (setRawAttributes) because the page's schemas hold the same instance"
  - "Delete confirmation names the duration as H:MM like the list; the entry page keeps H:MM:SS in its own Trvání entry"

patterns-established:
  - "Shared static builders on TimeEntryResource for every list of entries"
  - "A hidden-by-canEdit header action plus a redirect in mount() before parent::mount() for a locked record"

requirements-completed: [TI-02]

coverage:
  - id: D1
    description: "The Admin selects entries and runs Označit jako vyfakturované: the modal states the number and total duration of the eligible entries and, when some are skipped, how many and why; submit bills only the eligible ones with the toast Označeno jako vyfakturované (:count); a selection with nothing eligible shows the danger toast and changes nothing"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#bills the eligible entries of a selection in bulk, says what it skips and locks the rows"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#shows a danger toast and changes nothing when the selection has nothing to bill or to unlock"
        status: pass
    human_judgment: false
  - id: D2
    description: "A billed entry is locked on every screen: the list row shows the Vyfakturováno badge with the lock and neither Upravit záznam nor Smazat záznam; its edit URL redirects to the view page; the view page shows the callout Záznam je uzamčený and offers only Zrušit fakturaci, which unlocks it and announces it to the timer components"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#sends the edit URL of a billed entry to its view page and shows the callout there"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#unlocks a billed entry from its view page and it is editable again"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#unlocks a selection in bulk, announces it to the timer components and asks with the right count"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses a save from an edit page left open after the entry was billed and leaves it unchanged"
        status: pass
    human_judgment: false
  - id: D3
    description: "The list filters combine with AND: Období presets on Prague day boundaries as UTC range predicates (the 25-hour day, ISO week, month, custom inclusive), Klient, Projekt, Úkol and Fakturace, each active period filter shown as an indicator"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#filters by the Prague day, including the 25-hour day the clocks go back"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#filters by the ISO week, the month and their predecessors in Prague time"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#filters a custom period inclusive of both days and shows an indicator for each bound"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#narrows the rows by client, project, task and billing state, all combined with AND"
        status: pass
    human_judgment: false
  - id: D4
    description: "The footer Celkem under Trvání sums the exact seconds of the whole filtered set (two pages of 30 entries plus a running one), shows the billable and non-billable split, is formatted once as H:MM and follows the active filters"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#totals the whole filtered set, not just the page, and splits it into billable and not"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#totals only the filtered rows when a filter is active"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every overlapping row carries the Překryv badge with a tooltip naming the other entry; touching, zero-length and other users' entries carry none; the list runs the same number of queries for 30 rows as for 3"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#flags every overlapping row, names the other entry and flags nothing that only touches"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#names a task entry by its reference in the overlap label"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryListTest.php#runs the same number of queries for 30 rows as for 3"
        status: pass
    human_judgment: false
  - id: D6
    description: "The view page shows Platná sazba for a billable entry as :rate (zdroj: :source) from TimeEntryRateResolver (task, client), nothing for a non-billable one, never an amount, and Vyfakturováno with date and time when billed"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#shows the effective rate with its source on the view page, never an amount"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#shows when an entry was billed and the billing state on its page"
        status: pass
    human_judgment: false
  - id: D7
    description: "Smazat záznam asks Smazat záznam? with the duration, deletes through DeleteTimeEntry, toasts Záznam byl smazán and announces time-entry-deleted; a running or billed entry offers no delete"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#deletes an unbilled finished entry from its page after a confirmation that names the duration"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#deletes an unbilled finished entry from the list and offers a running entry no delete"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#does not delete an entry that was billed after the confirmation opened"
        status: pass
    human_judgment: false
  - id: D8
    description: "The Historie změn tab lists the allowlisted billing changes with the Czech attribute label and never the description; a Partner cannot mount it nor open the entry page"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#lists the billing change of an entry in the history tab with the Czech label and no description"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#keeps the history tab and the rate from a Partner"
        status: pass
    human_judgment: false
  - id: D9
    description: "Visual quality of the list: badge colours, lock icon, footer rows and the Změněno toggle read well in the real browser (UI states M3 populated, M8 error)"
    requirement: "TI-06"
    verification: []
    human_judgment: true
    rationale: "Rendering and spacing of stock Filament components are judged by eye; tests assert the state and the copy, not the pixels"

actuals:
  tokens: 15900
  tasks: 3
  commits: 3
plan_head_before: edb8e438530aab058be84934b209f62ebd4ccb96
plan_head_after: e80aec071c22e9df82aafc6cb9a6cfd6690736c3
commits: 3

duration: 55min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 07: Billed lock and the working list Summary

**Bulk billing with honest confirmations over MarkEntriesBilled and CancelEntriesBilling, a billed lock visible on the list, the edit URL and the entry page, Prague-day period filters, whole-set footer totals, a query-constant overlap badge, Platná sazba, delete through the Action and an Admin-only history tab.**

## Performance

- **Duration:** about 55 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 11 (3 created, 8 modified)

## Accomplishments

- `BillingBadge` (unbilled info, billed success with `OutlinedLockClosed`, non-billable gray) with labels under `enums.time_billing_state.*`; `TimeEntry::billingBadge()` and `isBilled()`.
- `TimeEntryResource::billingBulkActions()`: the modal heading, body and skipped sentence come from `preview()` with Czech plural forms through `trans_choice`; the action passes only the selected keys to the Action, shows the count toast or the danger toast from the `DomainException`, deselects and dispatches `time-entry-saved`.
- The lock: `canEdit()` false for billed, `canDelete()` false for billed or running, `EditTimeEntry::mount()` redirects a billed record to the view page before `parent::mount()`, the view page callout and the single header action `Zrušit fakturaci`.
- Filters: `period` (presets plus Od and Do, half-open UTC ranges computed from Prague midnights on plain dates), `client_id`, `project_id`, `task_id`, `billing`.
- Footer: `Sum` summarizers `total`, `billable`, `non_billable` on `elapsed_seconds`, formatted once with `DurationFormat::hoursMinutes()`, with `summaries(pageCondition: false)`.
- `TimeEntry::scopeWithOverlapFlag()`: `EXISTS` and `LIMIT 1` sub-selects, the same rule as `OverlapFinder`; the Překryv badge column with the tooltip; `Změněno` toggleable and hidden.
- View page: `Stav fakturace` badge, `Vyfakturováno` date and time, `Platná sazba` as `:rate (zdroj: :source)` (static `ViewTimeEntry::effectiveRateText()` over `TimeEntryRateResolver`), `Smazat záznam` through `DeleteTimeEntry`, `TimeEntryHistoryRelationManager` in `getRelations()`.
- Full suite 2280 passed (16464 assertions) after the last code edit; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): bill selections in bulk and lock billed entries on every screen** - `d2c532d` (feat)
2. **Task 2: filter the entry list, total the whole filtered set and flag overlaps** - `623fd32` (feat)
3. **Task 3: show the effective rate and history of an entry and delete through the Action** - `e80aec0` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the three Pest files, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2 tests were written first and seen failing (nine failures on missing filters, summarizers and scope), then the code made them pass. Task 3 tests were written first (four failures: missing relation manager class and rate entry). As in 06-06, each task is one commit holding tests and implementation together, not a separate RED `test(...)` commit.

## Decisions Made

See `key-decisions`. Two findings worth keeping for later plans: Filament renders a second "Tato stránka" summary row whenever the list is paginated, which both read as a competing total and cost extra queries per page, so lists that reuse `tableColumns()` should also call `summaries(pageCondition: false)`; and `EditRecord::hydrate()` authorizes the edit again on every Livewire request, so the 403 on a stale page is the lock working.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The 06-06 test of a billed edit contradicted the new lock**
- **Found during:** Task 1 (targeted run of `TimeEntryResourceTest`)
- **Issue:** `refuses an edit of a billed entry with a danger notification` mounted the edit page of an already billed entry; the page now redirects, and a page left open answers 403 on the next request.
- **Fix:** rewritten as `refuses a save from an edit page left open after the entry was billed and leaves it unchanged` (mount unbilled, bill, save, expect 403, row unchanged).
- **Files modified:** `tests/Feature/TimeTracking/TimeEntryResourceTest.php`
- **Commit:** `d2c532d`

**2. [Rule 1 - Bug] Page summary row doubled the footer and the query count**
- **Found during:** Task 2 (query-count test: 23 queries for 3 rows, 28 for 30)
- **Issue:** with more than one page Filament adds a page-level summary row and its queries.
- **Fix:** `->summaries(pageCondition: false)`; only the whole-set row remains, as the plan requires.
- **Files modified:** `TimeEntryResource.php`
- **Commit:** `623fd32`

**3. [Rule 3 - Blocking] PHPStan: a bulk action closure cannot dispatch on `HasTable`; `__()` may return an array**
- **Fix:** `HasTable&Component $livewire` on the two bulk action closures; `(string)` casts on two tooltip and badge strings.
- **Commit:** `d2c532d`, `623fd32`

### Plan additions (within scope)

- The delete action wiring (`deleteAction()`, `DeleteAction` on the list and the view page) arrived in Task 1 with the row actions, and Task 3 added its tests; the plan placed the wiring in Task 3.
- The unlock action of the view page is defined in `ViewTimeEntry` rather than as a shared resource builder, since only that page uses it; the bulk one stays in `billingBulkActions()`.
- `TimeEntry::isBilled()` added next to `billingBadge()`.
- The stale-delete test asserts that nothing is deleted (the action is hidden once the record is billed) instead of a notification, since the visibility check runs first; the Action's own refusal stays covered by `BillingLockTest` domain cases.

**Total deviations:** 3 auto-fixed (2 Rule 1, 1 Rule 3), 4 small in-scope additions.
**Impact:** none on scope.

## Issues Encountered

None open. Running Pint with explicit paths reformatted unrelated `lang/cs/*.php` files; they were restored and never staged.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-18 (the closures pass keys only; the Actions re-read and lock the rows; the modal uses `preview()`), T-06-19 (`canEdit()`, redirect before `parent::mount()`, hydrate 403, `UpdateTimeEntry` and the KP001 trigger), T-06-20 (`AdminOnly` on resource and relation manager, `TimeEntryRateResolver` refuses non-Admins, Partner mount and page tests), T-06-SC (no package added).

## Requirements

`requirements-completed: [TI-02]`: manual creation (06-06), editing (06-06) and deleting (06-07) of entries with client, project, task, times and description are delivered. TI-05 was ticked early. TI-06 still needs the timesheet (06-11) and TI-08 the billing snapshot (Phase 10) and the remaining plans.

## Next Phase Readiness

Plan 06-08 can reuse the `time-entry-saved` and `time-entry-deleted` browser events; the timesheet day view (06-11) and the project entries tab (06-12) can call `TimeEntryResource::tableColumns()`, `billingBulkActions()`, `deleteAction()` and `filters()`, adding `->summaries(pageCondition: false)` where they total. The task page link `Zobrazit záznamy` (06-10) can use `?tableFilters[task_id][value]=<id>`.

## Self-Check: PASSED

Created files `BillingBadge.php`, `TimeEntryHistoryRelationManager.php` and `TimeEntryListTest.php` exist; commits `d2c532d`, `623fd32` and `e80aec0` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2280 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
