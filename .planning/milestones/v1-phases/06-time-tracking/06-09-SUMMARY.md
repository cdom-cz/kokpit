---
phase: 06-time-tracking
plan: 09
subsystem: time-tracking
tags: [laravel, filament, livewire, alpine, render-hook, spa, pest]

requires:
  - phase: 06-time-tracking
    provides: "TimerBar, RequiresAdmin, CompletesRunningEntry, EntryContextOptions::timerClients, StartTimer, StopTimer, TimerRaceLost, DurationFormat (06-01 to 06-08)"
provides:
  - "RecentEntriesPanel: the Admin-only side panel 'Poslední záznamy' at PanelsRenderHook::LAYOUT_END with the detailed timer (idle and running, danger callout when too long) and finished entries grouped by Prague day"
  - "RecentEntries query: pages of days that contain finished entries (two queries plus three eager loads, whatever the number of entries), has_more, Prague-day attribution by UTC range predicates"
  - "users.time_panel_open (nullable boolean) written only by SetTimePanelOpen for the actor's own row; applied in the first render"
  - "ControlsTimer trait: the running state, client choice, start, stop and refresh events shared by the bar and the panel"
  - "Bar toggle (OutlinedBars3BottomRight) dispatching kokpit-time-panel-toggle, labelled by the state the panel reports"
affects: [06-10, 06-13, 06-14]

tech-stack:
  added: []
  patterns:
    - "Two Livewire surfaces over one running entry share one trait (ControlsTimer); a component with more computed values overrides forgetComputedState()"
    - "The panel shell holds an Alpine state (stored pref, overlay, docked via matchMedia 80rem); the aside carries wire:ignore.self so a Livewire morph never undoes the class and data attribute Alpine set; the Livewire root and the shell are display: contents so the aside is a flex child of .fi-layout"
    - "Only a toggle at docked width calls the server (setOpen); the overlay below 80rem is client-side and starts closed on every page load"
    - "The number of shown days is a public scalar (visibleDays) and every refresh re-reads those days, instead of keeping rows in the snapshot"

key-files:
  created:
    - app/Domain/TimeTracking/Queries/RecentEntries.php
    - app/Domain/TimeTracking/Actions/SetTimePanelOpen.php
    - app/Livewire/TimeTracking/RecentEntriesPanel.php
    - app/Livewire/TimeTracking/ControlsTimer.php
    - resources/views/livewire/time-tracking/recent-entries-panel.blade.php
    - database/migrations/2026_10_11_000200_add_time_panel_open_to_users_table.php
    - tests/Feature/TimeTracking/RecentEntriesPanelTest.php
  modified:
    - app/Livewire/TimeTracking/TimerBar.php
    - resources/views/livewire/time-tracking/timer-bar.blade.php
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Domain/Identity/Models/User.php
    - lang/cs/kokpit.php

key-decisions:
  - "The start, stop, running state and client choice moved out of TimerBar into the ControlsTimer trait, so the bar and the panel are two views of the same code and cannot drift (the 06-08 tests pass unchanged)"
  - "Paging keeps `visibleDays` (7, 14, ...) instead of the planned `oldestLoaded` cursor: the panel re-reads all shown days on every refresh event and poll, so a new entry appears without losing the loaded pages and the snapshot holds a scalar, not rows; RecentEntries::days still supports the `before` cursor"
  - "The panel is a display: contents Livewire root plus a shell with the Alpine state; the aside ignores the morph of its own attributes (wire:ignore.self), the backdrop is wire:ignore"
  - "A user who never chose (null) counts as open for the bar toggle label; the panel reports its real state in the browser (below 80rem it is closed) and the label follows"
  - "SetTimePanelOpen authorizes through the existing `create` policy on TimeEntry (Admin only) and has no target parameter, so it can only write the actor's row"
  - "The panel style block must not spell the warning or danger role class names: SystemPageTest counts the occurrences of those strings on a rendered page; the readout takes its colour from the --color-N variables of the element's own fi-color-<role> class"

patterns-established:
  - "Entry factories write the wall-clock value of the instant they are given, so a test that builds Prague times converts them with ->utc() first"
  - "Query-count test: count DB::listen events for a fresh component with few and with many entries and compare"

requirements-completed: [TI-01, TI-02, TI-09]

coverage:
  - id: D1
    description: "The Admin sees the aside 'Poslední záznamy' on every panel page (LAYOUT_END, 20rem, docked from 80rem, overlay below) with the timer block on top: idle the client select, Popis, readout 0:00:00 and Spustit časovač; running the task KEY-N and title or the client, the description, the H:MM:SS readout and Zastavit časovač with Doplnit záznam"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#renders the panel as a labelled aside on a page of the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#starts a timer from the panel for the chosen client"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#stops the running timer from the panel and lists the entry under \"Dnes\" afterwards"
        status: pass
    human_judgment: false
  - id: D2
    description: "Below the timer the panel lists finished entries by Prague day, newest first, with 'Dnes' or the Czech weekday and date (year outside the current year), the H:MM day total, rows with KEY-N · title as a task link or the client name, a second line and H:MM; the running entry only in the timer block; an entry from 23:30 to 00:30 and the 25-hour day are attributed to the start day"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#lists the finished entries of today under \"Dnes\" with their total and leaves the running one out"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#groups yesterday under the Czech weekday and date"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#puts the year on a day heading outside the current year"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#lists an entry from 23:30 to 00:30 Prague under the day it started"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#counts a 25 hour day by its Prague date and never as 24 hours"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#shows a client-only entry with the client name as the title"
        status: pass
    human_judgment: false
  - id: D3
    description: "The panel loads the 7 newest days that contain entries with a query count independent of the number of entries; 'Načíst starší záznamy' appends 7 more and disappears when none are left; the footer link opens the entries list; no 'Log time' link and no billed marks"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#shows the seven newest days that have entries and appends the rest with the older button"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#reads the days in a number of queries that does not depend on the number of entries"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#reads the days before a cursor and says whether older ones exist"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#opens the entries list from the footer link and offers no billed marks"
        status: pass
    human_judgment: false
  - id: D4
    description: "The open or closed choice at docked width is stored per user on the server (users.time_panel_open, null = default open from 80rem), applied in the first render of any page; the bar toggle is named 'Skrýt/Zobrazit poslední záznamy' by state and dispatches kokpit-time-panel-toggle; only the Admin can write the preference and only on the own row"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#stores the closed choice and starts the next page closed, without a client round trip"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#stores the open choice and names the toggle accordingly"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#keeps the toggle of the bar and the panel client-side below the docked width"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#lets only the Admin write the preference and only on the own row"
        status: pass
    human_judgment: false
  - id: D5
    description: "A timer running too long shows the danger callout 'Časovač běží příliš dlouho' with the hours above the readout in the panel (the panel half of the warning); the entry is never stopped"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#shows the danger callout above the readout when the timer runs too long"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner's pages hold no panel markup, a Partner mounting the panel or forging setOpen, start or stop is refused with 403 and nothing is written; a lost start race and a stale stop show the toasts of the bar"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#renders nothing of the panel on the pages of a Partner"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#refuses a Partner who mounts the panel"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#refuses a forged setOpen by a Partner and writes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/RecentEntriesPanelTest.php#shows the same race toast as the bar and tells a stale stop that nothing runs"
        status: pass
    human_judgment: false
  - id: D7
    description: "In the real browser the panel is docked at 1440px and shrinks the content, a closed choice survives a reload, and at 375px the toggle opens an overlay sheet that closes with Esc, the close button and a backdrop click, scrolls inside itself and causes no horizontal page scroll; the loading indicator of 'Načíst starší záznamy' is the stock one"
    requirement: "TI-01"
    verification: []
    human_judgment: true
    rationale: "The docked layout, the Alpine state, the matchMedia switch, the sticky and fixed positioning and the look of the scoped styles can be judged only in a browser; tests assert the markup, the state and the copy"

actuals:
  tokens: 19000
  tasks: 3
  commits: 3
plan_head_before: 91db041558aafea9379972d5aed96d3d088aa603
plan_head_after: bf4728ea6851ab5fc84def3685670b884ab65746
commits: 3

duration: 17min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 09: Side panel "Poslední záznamy" Summary

**An Admin-only side panel at LAYOUT_END with the detailed timer (idle start, running readout, forgotten-timer callout) and the finished entries by Prague day in pages of seven days with a fixed query count, plus a per-user open or closed choice stored on the server and a toggle in the top bar.**

## Performance

- **Duration:** about 17 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 12 (7 created, 5 modified)

## Accomplishments

- `RecentEntriesPanel` rendered by `renderHook(PanelsRenderHook::LAYOUT_END)` for the Admin only: the aside has `aria-label="Poslední záznamy"`, a header with the close button, the timer block (idle: client select with the bar's two option groups, Popis, `0:00:00`, Spustit časovač; running: task link or client, description clamped to two lines, `H:MM:SS` in the running colour via the same Alpine clock as the bar, Zastavit časovač, Doplnit záznam icon button), the day groups and the footer link.
- `RecentEntries::days()`: one query for the Prague dates of finished entries before the cursor (limit days + 1 for `has_more`), one for their entries through UTC range predicates, client, project and task eager-loaded with archived rows; rows are scalar arrays without rates or prices. A constant-query test compares 3 and 30 entries per day.
- Open or closed: `users.time_panel_open` (nullable boolean), `SetTimePanelOpen` (Admin only, own row only), the panel's `setOpen()` and a first-render `data-pref` attribute so a stored choice has no flash. The toggle in the bar dispatches `kokpit-time-panel-toggle`; the panel's Alpine state switches between the stored docked choice (server call) and the client-only overlay below 80rem (Esc, close button, backdrop) and reports its state back so the bar label follows.
- `ControlsTimer` trait extracted from `TimerBar` and used by both surfaces; the whole 06-08 test file passes unchanged.
- Full suite 2347 passed (16767 assertions) after the last code edit; Pint, PHPStan and `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): the Admin sees "Poslední záznamy" with today's entries and starts and stops the timer from the panel** - `2ac5a8d` (feat)
2. **Task 2: panel open or closed per user, toggled from the bar and the panel** - `582e091` (feat)
3. **Task 3: older days, empty state and the forgotten-timer callout** - `bf4728e` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the panel test file, the Livewire contract test, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2: the tests were written first and seen failing (five of six new tests red on the missing column, Action and markup), then the migration, Action, panel state and bar toggle made them pass. Task 3: the cursor, `loadOlder()`, the empty state and the callout were already built in the tracer commit (they are part of one view and one query), so the Task 3 tests were written after that code; they passed except four mistakes in the tests themselves (Prague times given to the factory without converting to UTC, a start without a chosen client, and a tooltip string compared after `trim`). As in 06-06 to 06-08, each task is one commit holding tests and implementation, not a separate RED `test(...)` commit.

## Decisions Made

See `key-decisions`. Findings worth keeping: (1) the factory and the model write the wall-clock value of the instant given, so a Carbon in the Prague zone must go through `->utc()` in tests; (2) `SystemPageTest` counts the literal role class names on a rendered page, so no always-rendered `<style>` block may spell them; (3) the bar's x-filament::icon-button tooltip is static, so a state-dependent toggle name uses `label` plus `x-bind:aria-label` and `x-bind:title`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Panel style block broke SystemPageTest**
- **Found during:** Task 3 (first full suite run)
- **Issue:** the always-rendered `<style>` of the panel spelled `fi-color-warning` and `fi-color-danger`; `tests/Feature/Operations/SystemPageTest.php` counts exactly one occurrence of each on the page.
- **Fix:** the readout and muted text take their colour from the `--color-N` variables supplied by the `fi-color-<role>` class already on the element, so the style block no longer names the roles.
- **Files modified:** `resources/views/livewire/time-tracking/recent-entries-panel.blade.php`
- **Commit:** `bf4728e`

**2. [Rule 1 - Bug] PHPStan: a nullable cursor passed to the day-start helper**
- **Found during:** Task 1
- **Fix:** `when($before, fn ($query, string $cursor) => ...)` hands the closure the non-null value.
- **Commit:** `2ac5a8d`

### Plan changes (within scope)

- **Shared trait instead of a second copy:** `ControlsTimer` (a new file not in the plan's list) holds the running state, client groups, start, stop and refresh events; `TimerBar` shrank to its render method. The artifact table said the panel has `start()` and `stop()` and the bar is unchanged otherwise; behaviour is identical.
- **`visibleDays` instead of `oldestLoaded`** (see key-decisions); the `before` cursor stays in `RecentEntries::days()` and is tested directly.
- The Czech strings for all three tasks were added in the Task 1 commit (`lang/cs/kokpit.php` is a Task 1 file).
- The panel timer shows `data-state="running"` or `"too-long"` on the readout, which the tests assert.

**Total deviations:** 2 auto-fixed (both Rule 1), 4 small in-scope changes.
**Impact:** none on scope; the contract of the plan is met.

## Issues Encountered

None open. The browser behaviour of the docked and overlay panel (sticky and fixed positioning, the matchMedia switch, a Livewire morph leaving the Alpine-set class alone) could not be exercised here; it is the human check below.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-25 (`RequiresAdmin` on mount and every hydrate, `SetTimePanelOpen` authorizes through the Admin-only `create` policy and writes only the actor's row, tests for a Partner mounting, a forged `setOpen` by a Partner, and the Action with a Partner actor), T-06-26 (the hook renders nothing for a non-Admin, `TimeEntry` stays `DeniesPartners`, the Partner page test asserts the panel strings are absent), T-06-SC (no package added). `users.time_panel_open` is on a `NotPartnerScoped` table and no Partner-readable table gained a column, so `PartnerSafeColumnsTest` is untouched.

## Requirements

`requirements-completed: [TI-01, TI-02, TI-09]` copies the plan frontmatter. TI-01 and TI-02 were already ticked in REQUIREMENTS.md and stay so. TI-09 is **not** ticked: only the visible halves (the bar state in 06-08, the panel callout here) are delivered; the scheduled job and the bell notification belong to 06-13.

## Next Phase Readiness

Plan 06-10 (task start affordances) can dispatch `timer-started` / `timer-stopped` so the bar and the panel both refresh. Plan 06-14 should add the new panel strings and a markup canary to the Partner leak test (the strings 'Poslední záznamy' and the entry titles are already asserted absent on two Partner pages here). Human check for `/gsd-verify-work`: in DDEV as the Admin at a 1440px window the panel is docked on the right and the content shrinks; toggle it closed from the bar, reload, it stays closed (and open again after toggling back); at 375px the bar toggle opens an overlay sheet that closes with Esc, the close button and a backdrop click, scrolls inside itself, shows no horizontal page scroll, and a reload starts it closed; "Načíst starší záznamy" shows the stock loading indicator; the toggle name follows the state in both modes. Expected: matches UI-SPEC Surface B with the stock Filament look.

## Self-Check: PASSED

Created files `RecentEntries.php`, `SetTimePanelOpen.php`, `RecentEntriesPanel.php`, `ControlsTimer.php`, `recent-entries-panel.blade.php`, the migration and `RecentEntriesPanelTest.php` exist; commits `2ac5a8d`, `582e091` and `bf4728e` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2347 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
