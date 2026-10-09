---
phase: 06-time-tracking
plan: 08
subsystem: time-tracking
tags: [laravel, filament, livewire, alpine, render-hook, spa, pest]

requires:
  - phase: 06-time-tracking
    provides: "StartTimer, StopTimer, UpdateTimeEntry, TimerRaceLost, TimerClock, DurationFormat, TimeEntryResource::entryFields/actionData (06-01 to 06-07)"
provides:
  - "TimerBar Livewire component in the persisted end region of the top bar (PanelsRenderHook::GLOBAL_SEARCH_AFTER), Admin only: idle quick start, running pill with a browser-side clock, stop, forgotten-timer danger state"
  - "RequiresAdmin trait (boot hook: 403 on mount and every hydrate) and the arch test LivewireComponentContractTest that forces it, and scalar-only public properties, on every component under app/Livewire"
  - "CompletesRunningEntry trait: the Doplnit zaznam modal on the shared entry form, saving through UpdateTimeEntry while the entry keeps running"
  - "EntryContextOptions::timerClients(User): recent (up to five by latest entry), all (the rest in Czech order), preselected"
  - "kokpit.time.long_running_hours (default 12) and the kokpit.time.timer.* Czech strings"
affects: [06-09, 06-10, 06-13, 06-14]

tech-stack:
  added: []
  patterns:
    - "Plain Livewire components outside the Filament registry guard themselves with a trait boot hook (bootRequiresAdmin), which runs before mount() and before every hydrate(), so a forged /livewire/update by a Partner is refused with 403 before any action method"
    - "A persisted top bar region is not re-rendered by SPA navigation: the component refreshes itself on timer-started, timer-stopped, time-entry-saved, time-entry-deleted and wire:poll.visible.60s; computed properties are cleared with unset() after a write"
    - "The clock is an Alpine x-data object ticking once per second from the stored UTC start instant plus a server-now skew, cleaned in destroy(); the server renders the same text so there is no flash"
    - "A Filament Action in a plain Livewire component takes the running entry as its record() so the shared schema fields get $record; Action field errors are re-keyed to mountedActions.N.data.<field>"
    - "Styles are a scoped style block in the component view using only the spacing tokens (0.25, 0.5, 1, 1.5, 2 rem) and the --color-N variables of the fi-color-<role> classes, because the panel ships precompiled CSS and no build exists"

key-files:
  created:
    - app/Livewire/TimeTracking/RequiresAdmin.php
    - app/Livewire/TimeTracking/TimerBar.php
    - app/Livewire/TimeTracking/CompletesRunningEntry.php
    - resources/views/livewire/time-tracking/timer-bar.blade.php
    - tests/Arch/LivewireComponentContractTest.php
    - tests/Feature/TimeTracking/TimerBarTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Domain/TimeTracking/Queries/EntryContextOptions.php
    - config/kokpit.php
    - lang/cs/kokpit.php

key-decisions:
  - "Hook and guard are layered: the render hook returns an empty string for anybody but the Admin (a Partner page holds no timer markup), and the component refuses a forged mount or update on its own (RequiresAdmin), so neither layer is the only protection"
  - "Všichni klienti lists only the active clients not already in Naposledy použití, so no option value appears twice in the native select; the preselected client is the one of the user's very latest entry, and nothing is preselected when that client is archived"
  - "The stop button of the pill passes the id of the entry it shows, so a stale view can never stop a newer timer; a mismatch gets the same info toast as nothing running"
  - "The long-running flag is computed from the stored start on every render and poll, so it can lag by up to the 60-second poll in a background tab; nothing is stored and the timer is never stopped"
  - "StartTimer and UpdateTimeEntry are final, so the race and the Action-error tests bind stand-in objects in the container instead of Mockery mocks"
  - "The Alpine skew (server now minus browser now) is read once at init; the element is keyed by entry id, so a new timer re-creates it"

patterns-established:
  - "RequiresAdmin plus the architecture test for every concrete component under app/Livewire"
  - "Real-endpoint test: take the wire:snapshot of the rendered page and post it to Livewire::getUpdateUri() with the X-Livewire header, as the Admin (200) and as a Partner (403)"

requirements-completed: [TI-01, TI-09, TI-07]

coverage:
  - id: D1
    description: "On every panel page the Admin sees the idle bar Spustit časovač in the persisted end region; its dropdown has a native client select with Naposledy použití (up to five by latest entry) and Všichni klienti (the rest in Czech order, each client once), the latest client preselected, an optional Popis with the placeholder Na čem pracujete? and the primary Spustit časovač; starting stops a running timer and the toast says so"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows the idle bar on a panel page of the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#starts a timer for the preselected client and announces it to the other timer surfaces"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#offers the five most recently used clients first, then the others in Czech order, each once"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#stops the running timer when a new one starts and says so"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#asks for a client when none is chosen and starts nothing"
        status: pass
    human_judgment: false
  - id: D2
    description: "Running, the bar shows one warning pill with the clock icon, the H:MM:SS readout (role=timer, aria-live=off, hours unbounded), the description with its full text as tooltip and a stop icon button Zastavit časovač; stopping is never confirmed and the toast shows the duration"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows the running pill with the warning colour, the clock and the stop button"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#stops the running timer without a question and tells the duration"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#keeps the full description in the tooltip of the pill"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows hours without an upper bound"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows a running entry with only a client as a normal state and its missing parts as dashes"
        status: pass
    human_judgment: false
  - id: D3
    description: "A timer running at least kokpit.time.long_running_hours (default 12) turns the pill to the danger role with the triangle and the tooltip Časovač běží déle než :hours h; the state is computed on every render and poll, nothing is stored and the timer is never stopped"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#turns the pill to the danger state exactly at the threshold, without a reload"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#reads the threshold from the configuration on every render"
        status: pass
    human_judgment: false
  - id: D4
    description: "The bar refreshes on timer-started, timer-stopped, time-entry-saved and time-entry-deleted (and a 60-second visible-tab poll); a lost start race shows the race toast and a stale stop shows Žádný časovač neběží. and renders the idle state, without ever stopping a newer timer"
    requirement: "TI-07"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows the running state when another surface starts a timer and announces the event"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#re-reads the description when an entry was saved"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#shows the race toast and re-reads the state when a concurrent start won"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#tells a stale stop that nothing runs and renders the idle state"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#never stops a newer timer from a stale stop button"
        status: pass
    human_judgment: false
  - id: D5
    description: "Doplnit záznam opens the modal Doplnit běžící záznam on the shared entry form, prefilled from the running entry, and saves project, task, description, start and billable through UpdateTimeEntry while the entry keeps running; a forged task of another client is a field error under Úkol and changes nothing; the action is hidden with no running timer"
    requirement: "TI-07"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#opens \"Doplnit záznam\" filled with the running entry and keeps project and task empty"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#fills in the task and the description of the running entry while it keeps running"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#refuses a forged task of another client as a field error under Úkol and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#puts a field error of the Action under its field in the modal"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#presets Fakturovatelné off when a non-billable task is chosen in the modal"
        status: pass
    human_judgment: false
  - id: D6
    description: "A Partner's page holds no timer markup, a Partner mounting the component or forging a start, stop or modal call (also posted to the real Livewire endpoint) is refused with 403 and nothing is written, and every concrete component under app/Livewire uses RequiresAdmin and holds scalar public properties only"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#renders nothing of the timer on the pages of a Partner"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#refuses a forged request by a Partner on a component the Admin mounted, and writes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimerBarTest.php#refuses a forged update posted to the Livewire endpoint by a Partner"
        status: pass
      - kind: integration
        ref: "tests/Arch/LivewireComponentContractTest.php#puts the Admin guard on every concrete Livewire component"
        status: pass
      - kind: integration
        ref: "tests/Arch/LivewireComponentContractTest.php#holds only scalars, null and arrays in the public properties of a component"
        status: pass
    human_judgment: false
  - id: D7
    description: "In the real browser the pill keeps ticking as one continuous readout through SPA navigation between pages, without a flash of the idle state, and the pill, dropdowns and modal look right in light and dark mode (contrast of the warning pill next to the Amber primary is measured at the phase gate, flag F-1)"
    requirement: "TI-01"
    verification: []
    human_judgment: true
    rationale: "The Alpine clock, the persisted region and the look of the scoped styles can be judged only in a browser; tests assert the state, the markup and the copy"

actuals:
  tokens: 15800
  tasks: 3
  commits: 3
plan_head_before: 58f750b695c43673cf6b76955c974c81cf299903
plan_head_after: a7767032b78fc194cfe41118173ae4967269fbe3
commits: 3

duration: 15min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 08: Top bar timer Summary

**An Admin-only Livewire timer in the persisted end region of the top bar: a client quick start, a warning pill with a browser-side clock and a stop button, a danger state for a forgotten timer, a Doplnit záznam modal on the shared entry form, and a trait plus an architecture test that refuse a forged Partner request to any component under app/Livewire.**

## Performance

- **Duration:** about 15 min of execution after reading the context
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 10 (6 created, 4 modified)

## Accomplishments

- `TimerBar` rendered by `renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER)` for the Admin only; idle quick start (native select with the two option groups, Popis, Enter submits), running pill (clock icon, `H:MM:SS` readout with `role="timer"` and `aria-live="off"`, description cut to 12rem with the full text as tooltip, stop icon button), and a detail dropdown (task link or client, Klient, Začátek, full description or a dash, Doplnit záznam, Zastavit časovač).
- `RequiresAdmin::bootRequiresAdmin()` aborts 403 on mount and on every hydrate; `LivewireComponentContractTest` scans `app/Livewire`, asserts the scan finds `TimerBar`, and enforces the trait and scalar-typed public properties (framework properties excluded by name).
- `EntryContextOptions::timerClients()`: up to five recent non-archived clients by the user's most recent entry, the others in Czech order, no client twice, the latest client preselected unless archived.
- Forgotten-timer state: `kokpit.time.long_running_hours` (default 12) read on every render; at the threshold the pill takes the danger role, the triangle, a screen-reader text and the tooltip; nothing is stored or stopped.
- Self-refresh for the persisted region: the four events plus `wire:poll.visible.60s`; a lost start race and a stale stop end in the Czech toasts of the copywriting contract and a re-read state.
- `CompletesRunningEntry`: the modal on `TimeEntryResource::entryFields(true)`, prefilled from the running entry, saved through `UpdateTimeEntry`, the entry keeps running, Action field errors re-keyed to the modal state path.
- Full suite 2318 passed (16625 assertions) after the last code edit; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): the Admin starts a client timer from the top bar on any page and stops it; a Partner gets no bar and a forged call is refused** - `047f842` (feat)
2. **Task 2: forgotten-timer warning state, refresh events, lost race and stale stop** - `6207d87` (feat)
3. **Task 3: Doplnit záznam, project, task and description of the running entry filled in later** - `a776703` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the three Pest files, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2: the tests were written first and seen failing (the two long-running tests, as intended, and the race test, which first failed on a Mockery attempt to mock a final class), then the code made them pass. Task 3: the trait and the view were written before the tests were run, so no clean RED was observed; the first run had three failures, all mistakes in the tests (an action-error assertion that cannot run once the modal has closed, a UTC time given to a picker that reads Prague time, and an unreachable domain-refusal scenario). As in 06-06 and 06-07, each task is one commit holding tests and implementation, not a separate RED `test(...)` commit.

## Decisions Made

See `key-decisions`. Findings worth keeping for the next plans: (1) a Livewire update needs the `X-Livewire` header to reach the controller, otherwise it answers 404, so a real-endpoint test must send it; (2) after a first 403 a Livewire testable holds an invalid snapshot, so each forged call needs its own component mounted as the Admin; (3) the side panel (06-09) can reuse `RequiresAdmin`, `CompletesRunningEntry` (it only needs `runningEntry()`), `clientGroups` logic through `EntryContextOptions::timerClients()` and the four refresh events.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] StartTimer and UpdateTimeEntry are final, so they cannot be mocked**
- **Found during:** Task 2 (race test), Task 3 (Action error test)
- **Issue:** the plan asks for a double bound in the container; `$this->mock()` refuses final classes.
- **Fix:** an anonymous stand-in object with the same `handle()` signature is bound with `$this->app->instance()`; the component resolves it with `app()`.
- **Files modified:** `tests/Feature/TimeTracking/TimerBarTest.php`
- **Commits:** `6207d87`, `a776703`

**2. [Rule 3 - Blocking] PHPStan: computed properties and a nullable relation**
- **Found during:** Task 1
- **Issue:** `$this->clientGroups` is a Livewire computed property PHPStan does not know; `__()` may return an array; the client relation is typed nullable.
- **Fix:** a `@property-read` docblock for `clientGroups`, a `(string)` cast on the toast body and on the client name.
- **Commit:** `047f842`

### Plan additions (within scope)

- The Czech strings for all three tasks (`time.timer.*`) were added in the Task 1 commit, because `lang/cs/kokpit.php` is a Task 1 file; `config/kokpit.php` was committed with Task 2.
- One string beyond the contract: `time.timer.elapsed` ("Uplynulý čas"), the accessible name of the `role="timer"` readout.
- The pill carries `data-state="running"` or `"too-long"`, which the tests assert (the icon markup carries no name to match).
- When the timer runs too long, the detail dropdown repeats the warning text above the lines, next to the tooltip and the screen-reader text of the spec.
- `runningEntry()` is declared `abstract protected` in `CompletesRunningEntry` and implemented by `TimerBar`, so the trait stays free of any query.
- A real-endpoint test posts the page's `wire:snapshot` to `Livewire::getUpdateUri()` as the Admin (200) and as a Partner (403), in addition to the component-level forged-call tests.

**Total deviations:** 2 auto-fixed (both Rule 3), 6 small in-scope additions.
**Impact:** none on scope.

## Issues Encountered

None open. `Livewire::dispatch` of a bar event also reaches the bar itself, so a start costs one extra refresh request; harmless, and the other timer surfaces need the event.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-21 (`RequiresAdmin` on mount and hydrate, the Actions authorize again, the arch test, forged mount and update tests including a post to the real endpoint), T-06-22 (the hook returns an empty string unless the Admin; the Partner page test asserts no timer string or component on the dashboard and on /admin/my-tasks), T-06-23 (scalar-only public properties by the arch test; the snapshot test asserts no model marker, rate or price), T-06-24 (escaped Blade output only, Alpine values through `@js`; a markup description renders escaped), T-06-SC (no package added).

## Requirements

`requirements-completed: [TI-01, TI-09, TI-07]` copies the plan frontmatter. TI-01 and TI-07 were already ticked in REQUIREMENTS.md and stay so. TI-09 is **not** ticked: only its visible half (the danger state) is delivered here; the scheduled job and the bell notification belong to 06-13 (and 06-09), so the requirement completes after those plans.

## Next Phase Readiness

Plan 06-09 (side panel and panel toggle) can reuse `RequiresAdmin`, `CompletesRunningEntry` (implement `runningEntry()`), `EntryContextOptions::timerClients()` and the events `timer-started`, `timer-stopped`, `time-entry-saved`, `time-entry-deleted`; the arch test already covers any new component under `app/Livewire`. Plan 06-10 can dispatch `timer-started` after the task-page Action so the persisted bar refreshes. Plan 06-14 should add the XSS canary to the leak test and measure the contrast of the warning pill (flag F-1). Human check for `/gsd-verify-work`: in DDEV as the Admin, start a timer from the bar, navigate between three panel pages through the sidebar (SPA) and confirm one continuous running readout, no flash of the idle state, and that stop works on the third page.

## Self-Check: PASSED

Created files `RequiresAdmin.php`, `TimerBar.php`, `CompletesRunningEntry.php`, `timer-bar.blade.php`, `LivewireComponentContractTest.php` and `TimerBarTest.php` exist; commits `047f842`, `6207d87` and `a776703` are ancestors of HEAD; the acceptance greps of all three tasks pass; full `vendor/bin/pest` 2318 passed, Pint, PHPStan and `scripts/check-sensitive.sh` clean.
