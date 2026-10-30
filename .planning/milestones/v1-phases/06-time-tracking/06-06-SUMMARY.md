---
phase: 06-time-tracking
plan: 06
subsystem: time-tracking
tags: [laravel, filament, livewire, postgres, collation, domain-actions, pest]

requires:
  - phase: 06-time-tracking
    provides: "CreateTimeEntry, UpdateTimeEntry, OverlapFinder, BillableDefault, TimeEntryInput, DurationFormat, TimerClock (plans 06-01 to 06-05)"
  - phase: 05-tasks
    provides: "TaskResource patterns, Project::selectable(), the task billing resolver"
provides:
  - "TimeEntryResource at /admin/time-entries (list, create, view, edit) with the shared static entryFields(bool $running) builder"
  - "TimeEntry::scopeWithElapsedSeconds: exact stored seconds, or seconds to a bound PHP instant for a running entry"
  - "EntryContextOptions: client, project and task picker options that mirror the Action rules, Czech-sorted"
  - "CzechCollation::orderBy: the one mechanism for text sorted for people (cs-CZ-x-icu)"
  - "Live client, project and task cascade, non-blocking overlap callout, live Trvání and the D-03 billable preset"
affects: [06-07, 06-08, 06-09, 06-14, phase-07-api]

tech-stack:
  added: []
  patterns:
    - "Picker options narrow the UI only; the Select 'in' rule message is mapped to the domain copy, and the Action re-validates the combination on save"
    - "A Get closure on a DateTimePicker returns the application timezone (UTC); the raw form state is the panel (Prague) wall clock"
    - "Text sorted for people goes through CzechCollation::orderBy, never orderBy('name')"
    - "Admin-only record pages abort 403 in mount() before the record lookup, because the deny-all scope would answer 404 first"

key-files:
  created:
    - app/Filament/Resources/TimeEntryResource.php
    - app/Filament/Resources/TimeEntryResource/Pages/ListTimeEntries.php
    - app/Filament/Resources/TimeEntryResource/Pages/CreateTimeEntry.php
    - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
    - app/Filament/Resources/TimeEntryResource/Pages/ViewTimeEntry.php
    - app/Domain/TimeTracking/Queries/EntryContextOptions.php
    - app/Domain/Shared/Database/CzechCollation.php
    - tests/Feature/TimeTracking/TimeEntryResourceTest.php
    - tests/Feature/TimeTracking/CzechOrderingTest.php
  modified:
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - tests/Isolation/RouteWalkTest.php
    - lang/cs/kokpit.php
    - CONTRIBUTING.md

key-decisions:
  - "When a Select option check fails, the field error is the domain copy: 'in' on project and task reads 'Klient, projekt a úkol k sobě nepatří. Vyberte je znovu.', 'in' on client reads 'Vyberte klienta.'"
  - "Clearing the client clears project and task; clearing the project keeps the client and clears the task (a task fixes its project)"
  - "With no client chosen the project picker offers every selectable project and the task picker every active task of a selectable project, so a task or project can be chosen first and sets the client"
  - "The stored client, project or task of an entry being edited stays in the options while the form still holds it, so an entry whose parents were archived later keeps saving"
  - "billable_touched starts true on edit when the stored flag differs from the default of the stored task, so a task change never overrides a deliberate stored choice"
  - "CzechCollation orders through orderBy(new Expression(...)) (Laravel validates the direction too) instead of orderByRaw, which Larastan rejects as non-literal SQL"

patterns-established:
  - "Static entryFields() builder reused by the running-entry modal of plan 06-08; entryFields(true) hides Konec and shows the Běží badge"
  - "Resource getEloquentQuery adds a model scope through ->scopes([...]) so Larastan accepts it on Builder<Model>"

requirements-completed: [TI-02, TI-03, TI-04]

coverage:
  - id: D1
    description: "The Admin opens Časové záznamy, saves a finished client-only entry from Nový záznam and sees it in the list (Datum, Čas, Klient, dashes for project and task, Popis at 80 characters, Trvání H:MM) and on its view page (H:MM:SS); the toast reads Záznam byl uložen"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#saves a client-only entry from the create page and shows it in the list and on its page"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#writes the picked Prague wall-clock times as UTC instants"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#limits the description to 80 characters and shows 25 rows per page"
        status: pass
    human_judgment: false
  - id: D2
    description: "The list orders by start descending then id, sorts by duration, shows a running entry at its elapsed time bound to the PHP clock with the Běží badge, and shows the documented empty state with Nový záznam reachable"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#lists the newest start first and sorts by the duration"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#shows a running entry at its elapsed time and a missing project and task as a dash"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#shows the empty state of the list and keeps \"Nový záznam\" reachable"
        status: pass
    human_judgment: false
  - id: D3
    description: "The pickers never offer an inconsistent combination: clients exclude archived ones, projects are the selectable ones of the client, tasks the active ones of the project or the client; choosing a task sets project and client, choosing a project sets the client and clears a foreign task, changing the client clears a foreign project and task, clearing the project keeps the client"
    requirement: "TI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/CzechOrderingTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#offers only the projects and tasks of the chosen client"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#fills the project and the client when a task is chosen"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#clears a project and a task of another client when the client changes"
        status: pass
    human_judgment: false
  - id: D4
    description: "A forged combination, a missing client, an end before the start and an over-long description are field errors under the offending field with the Czech copy, nothing is stored and a refused save leaves the row unchanged"
    requirement: "TI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses a forged combination as a field error and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#reports a missing client as a field error under the client and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses an end before the start as a field error under Konec and leaves the row unchanged"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses an over-long description as a field error and leaves the row unchanged"
        status: pass
    human_judgment: false
  - id: D5
    description: "Client names sort in Czech order (Cihla, Čočka, Dub, Hrad, Chata, Řeka, Sova, Šiška, Zima) through CzechCollation, the default collation does not, and a direction other than asc or desc is refused"
    requirement: "TI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/CzechOrderingTest.php#sorts the client picker in Czech order"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/CzechOrderingTest.php#shows the default collation would sort the same names differently"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/CzechOrderingTest.php#refuses a sort direction that is not asc or desc"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Fakturovatelné toggle defaults to on and is pre-set off only for a task that resolves as non-billable, with the helper text; once touched, a task change no longer overrides it; a fixed-price project task stays on; the stored flag follows the toggle"
    requirement: "TI-04"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#presets the billable toggle off for a non-billable task and says so"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#keeps the toggle the user set when another task is chosen"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#keeps the toggle on for a task of a fixed-price project and stores the toggle"
        status: pass
    human_judgment: false
  - id: D7
    description: "An overlapping interval shows the warning callout naming the other entry and its times and the save still succeeds; touching entries, other users' entries and the entry being edited show no warning; Trvání shows H:MM:SS live"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#warns about an overlap with another entry and still saves"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#shows no overlap warning for entries that only touch, for another user or for the entry being edited"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#shows the duration live from the two pickers"
        status: pass
    human_judgment: false
  - id: D8
    description: "A running entry edits without a Konec field and with the Běží badge and stays running; an entry whose task, project and client were archived later still saves its other fields; a billed entry is refused with a danger notification"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#shows a running entry without Konec and with the badge, and keeps it running when a task is saved"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#keeps a running entry editable after its task, project and client were archived"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses an edit of a billed entry with a danger notification and leaves it unchanged"
        status: pass
    human_judgment: false
  - id: D9
    description: "A Partner receives 403 on the list, create, view and edit routes of time entries, the route walk lists time-entries as Admin-only, and time entries are not globally searchable"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#refuses a Partner every time entry route"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/TimeEntryResourceTest.php#is not globally searchable"
        status: pass
    human_judgment: false
  - id: D10
    description: "A wrapped long Czech word in the description textarea renders without breaking the layout (UI state M4 long-text)"
    requirement: "TI-02"
    verification: []
    human_judgment: true
    rationale: "Plan marks this state as a backstop: layout of a long unbroken word in a stock Filament textarea is judged by eye, no test asserts it"

actuals:
  tokens: 20100
  tasks: 3
  commits: 3
plan_head_before: ee60ba7b4789218db4c9969a35f0ce3c49dd785e
plan_head_after: efb520bc8eccb4286cb5ccfd2f44d44716b2f0d1
commits: 3

duration: 33min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 06: Admin time entry screens Summary

**The Admin's Časové záznamy list, create, view and edit pages over the domain Actions, with client, project and task pickers that cascade and never offer a combination the database refuses, Czech-ordered through a new CzechCollation, a non-blocking overlap callout, a live H:MM:SS duration and the D-03 billable preset.**

## Performance

- **Duration:** about 33 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 13 (9 created, 4 modified)

## Accomplishments

- `TimeEntryResource` (slug `time-entries`, navigation sort 40, `OutlinedClock`, `AdminOnly`) with `ListTimeEntries`, `CreateTimeEntry`, `ViewTimeEntry` and `EditTimeEntry`. The list reads every user's entries, orders by start then id descending, shows 25 rows per page and the documented columns; `getEloquentQuery()` adds `elapsed_seconds` through `TimeEntry::scopeWithElapsedSeconds()` bound to `TimerClock::now()`, never SQL `now()`.
- Create and edit hand the form state to `CreateTimeEntry` and `UpdateTimeEntry` through `withFormErrors`; a billed entry's `DomainException` becomes a danger notification and halts the save.
- `entryFields(bool $running)` returns the three sections; the running variant hides Konec and shows the Běží badge. Plan 06-08 reuses it.
- `EntryContextOptions` and the live cascade: choosing a task sets project and client; a project sets its client and clears a foreign task; a client change clears a foreign project and task; clearing the project keeps the client. The Select `in` rule messages carry the domain copy, so a forged combination is a field error under `data.task_id` and nothing is stored.
- `CzechCollation::orderBy` (`cs-CZ-x-icu`) with a whitelisted direction; the test proves the default collation sorts the same nine names differently. The CONTRIBUTING "Ordering" bullet now names the mechanism and its test.
- Overlap `Callout` (warning, `OutlinedExclamationTriangle`) driven by `OverlapFinder`, excluding the record being edited and any other user; live Trvání `H:MM:SS`; billable preset with a hidden `billable_touched` state and the helper text.
- `RouteWalkTest` lists `time-entries` as Admin-only with canary entries; a Partner gets 403 on list, create, view and edit.
- Full suite 2258 passed (16222 assertions); Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): list, create and view time entries in the Admin panel** - `db2986b` (feat)
2. **Task 2: consistent pickers in Czech order** - `3068672` (feat)
3. **Task 3: edit page, live duration, overlap warning, billable preset** - `efb520b` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only, so the three Pest files, Pint and PHPStan were run end to end and passed before expansion.

## TDD note

Task 2 and Task 3 tests were written before the code that satisfied them and were seen failing (missing classes, hidden callout), but each task is one commit that holds tests and implementation together rather than a separate RED `test(...)` commit. The Task 3 overlap test failed first for a real reason, described under Decisions and Deviations.

## Decisions Made

See `key-decisions`. One finding worth keeping for later plans: a `Get` closure on a `DateTimePicker` returns the value in the application timezone (UTC) while the raw Livewire state is the Prague wall clock. `pickerInstant()` therefore parses in `config('app.timezone')`. Parsing in the panel timezone shifted the typed interval by two hours and hid the overlap callout.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] 403 before the record lookup on the record pages**
- **Found during:** Task 1 (route walk expects 403 for an Admin-only resource)
- **Issue:** the deny-all Partner scope on measured time answers 404 on a record route before Filament's access check runs.
- **Fix:** `ViewTimeEntry` and `EditTimeEntry` call `abort_unless(TimeEntryResource::canAccess(), 403)` in `mount()`, the pattern of `ViewClient`.
- **Files modified:** `ViewTimeEntry.php`, `EditTimeEntry.php`
- **Commit:** `db2986b`, `efb520b`

**2. [Rule 1 - Bug] Interval parsed in the wrong timezone for the overlap callout**
- **Found during:** Task 3 (overlap test)
- **Issue:** see Decisions Made; the callout never appeared.
- **Fix:** `pickerInstant()` parses in the application timezone.
- **Files modified:** `TimeEntryResource.php`
- **Commit:** `efb520b`

**3. [Rule 3 - Blocking] Larastan rejects non-literal SQL and a scope on `Builder<Model>`**
- **Found during:** Tasks 1 and 2 (PHPStan)
- **Fix:** the scope is applied with `->scopes(['withElapsedSeconds' => [...]])`; `CzechCollation` orders through `orderBy(new Expression(...), $direction)` with a single `@phpstan-ignore argument.type` whose reason is in the line above (column quoted by the grammar, collation is a constant).
- **Files modified:** `TimeEntryResource.php`, `CzechCollation.php`
- **Commit:** `db2986b`, `3068672`

### Plan additions (within scope)

- The edit page and the edit URL assertion for the Partner moved to Task 3 as the plan says; the view page header "Upravit záznam" arrived with it.
- `EntryContextOptions` gained `projectIdOfTask()` and `clientIdOfProject()` (archived rows included) for the cascade.
- The stored client, project or task of an edited entry stays in the options while the form holds it (needed so the Select `in` rule does not refuse an entry whose parents were archived later; tested).
- `CONTRIBUTING.md` Ordering bullet updated (the plan's rule that the first sorted text column defines the mechanism).
- The production availability guard of the collation stays with plan 06-14 (`kokpit:deploy:verify`), as the plan states; a test checks the dev database has it.

**Total deviations:** 3 auto-fixed (1 Rule 1, 1 Rule 2, 1 Rule 3), 4 small in-scope additions.
**Impact:** none on scope.

## Issues Encountered

None open.

## Known Stubs

None. The billed lock on the screens (no edit action for a billed row), the list filters, footer totals, bulk billing actions, billing badges, Platná sazba and the history tab are plan 06-07 by the plan's own decision, not stubs.

## Threat Flags

None. The register is covered: T-06-15 (`AdminOnly` rule, `AdminOnlyPolicy`, `DeniesPartners`, route walk entry with `partner => false`, 403 on all four routes tested, no global search), T-06-16 (options only narrow the UI; the forged state test expects the field error and an empty table; the Actions re-validate through `TimeEntryInput::context`), T-06-17 (Filament text columns and entries escape the description; the badge markup is built with `e()` and a Blade-escaped label), T-06-SC (no package added).

## Requirements

`requirements-completed` copies the plan frontmatter: TI-02 (manual create and edit), TI-03 (client-only entry) and TI-04 (billable preset and override) are delivered in full by plans 06-04 to 06-06 for their stated scope.

## Next Phase Readiness

Ready for 06-07. It adds to `TimeEntryResource` the billed lock (`canEdit` and `canDelete` overrides, the edit URL of a billed entry opening the view page before `parent::mount()`, the callout), the filters, footer totals, billing badges, the bulk actions over `MarkEntriesBilled` and `CancelEntriesBilling`, "Platná sazba" and the history tab. Plan 06-08 reuses `TimeEntryResource::entryFields(true)` in the "Doplnit záznam" modal; keep in mind that `Get` returns picker values in UTC. Plan 06-14 adds the `cs-CZ-x-icu` availability check to `kokpit:deploy:verify`.

## Self-Check: PASSED

All nine created files exist, commits `db2986b`, `3068672` and `efb520b` are ancestors of HEAD, the acceptance criteria of all three tasks re-ran green, and the plan-level verification (full `vendor/bin/pest` 2258 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) passes.
