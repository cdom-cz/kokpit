---
phase: 06-time-tracking
plan: 14
subsystem: time-tracking
tags: [laravel, filament, livewire, pest, postgres, isolation, deploy, documentation]

requires:
  - phase: 06-time-tracking
    provides: "Every surface and mechanism of plans 06-01 to 06-13: the Actions, TimerLock, TimerClock, DurationFormat, the billed freeze, TimeEntryRateResolver, CzechCollation, the bar, the panel, the timesheet, the project overview and the forgotten-timer job"
provides:
  - "TimeLeakTest: proof that a Partner finds no time, rate or price on any page they can open, that every time component, page, widget and project tab refuses them with 403, that their bell holds no time notice, and that an XSS canary in an entry description is escaped on the Admin surfaces"
  - "CzechCollation::isAvailable() and a fourth kokpit:deploy:verify check ('České řazení') that fails the readiness gate on a database server without cs-CZ-x-icu; no fallback to the default collation"
  - "CONTRIBUTING conventions Time entries, Timer lock and clock, Livewire components, the rewritten Ordering bullet, and hand-over notes for Phases 7, 8, 9 and 10 (Phase 6 note and the stale Phase 7/8/12 labels gone)"
  - "README: ICU requirement and the five-minute forgotten-timer schedule"
  - "RepositoryFilesTest: documentation tests over 20 Phase 6 classes, 15 enforcing tests and the hand-over notes"
  - "A green phase gate: 2469 Pest tests (both timer concurrency runs included), Pint, Larastan, licence check, gitignore and shell self-tests, sensitive scan of the whole tree"
affects: [phase-07-rest-api, phase-08-reports, phase-10-invoicing]

tech-stack:
  added: []
  patterns:
    - "A catalogue stand-in in a test: a temporary view named pg_collation, found first on the search path, simulates a server whose catalogue lacks the collation without touching the real catalogue"
    - "Leak-proof helper that matches forbidden text with str_contains inside expect(...)->toBeFalse(message), because Pest's toContain treats a second argument as another needle and not as a message"

key-files:
  created:
    - tests/Isolation/TimeLeakTest.php
  modified:
    - app/Domain/Shared/Database/CzechCollation.php
    - app/Console/Commands/DeployVerifyCommand.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/DeployVerifyCommandTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - README.md

key-decisions:
  - "Guard, not fallback (research Pitfall 3 and A4): kokpit:deploy:verify fails when pg_collation has no cs-CZ-x-icu; the remedy is an ICU-enabled PostgreSQL"
  - "The Phase 10 hand-over note states that the snapshot-columns migration must drop and re-create time_entries_frozen_guard through Immutability with the new columns in the mutable list, and that an unlisted column is frozen (research Open Question 5, Pattern 8)"
  - "The stale 'Phase 7 (calendar and reports)', 'Phase 8 (exports)' and 'Phase 12 (API)' notes are merged into the notes named after the ROADMAP phases (7 REST API, 8 exchange rates and reports)"
  - "The leak test uses descriptions that differ from the project canary: the registry's time entry fixture carries the project canary as its description, so it cannot tell entry text from a project name"
  - "The README states the forgotten-timer threshold as the config key time.long_running_hours (12), because no environment variable backs it"

patterns-established:
  - "Every time surface is proven closed to a Partner in one place (TimeLeakTest) over the canary harness, in addition to the per-surface refusals in the plans that built them"

requirements-completed: [TI-01, TI-02, TI-03, TI-04, TI-05, TI-06, TI-07, TI-08, TI-09, PR-05]

coverage:
  - id: D1
    description: "A Partner of client A finds none of the time words (casovac any case, Casove zaznamy, Vykaz, Odpracovano, Vyfakturovano, Nevyfakturovano, Fakturovatelne, Platna sazba, Posledni zaznamy, Celkem za den, Bez ukolu, Ukoly a cas, Tyden, the word Den), no entry canary of either client, no markup canary and none of the fixture durations on the dashboard, own projects list and page, own tasks list and page, task creation and profile"
    requirement: "TI-01"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#what a Partner sees it finds no time, rate or price on any page the Partner can open"
        status: pass
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#what a Partner sees it proves the fixtures exist: the Admin sees the time that the Partner does not"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every time surface refuses a Partner with 403: the entry list, create, view and edit routes, the timesheet, the bar, the panel, the timesheet page, the project stats widget and both project time tabs, for the own and for the other client's project"
    requirement: "TI-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#what a Partner can open (3 tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Partner's bell holds no forgotten-timer notice while the Admin who owns the forgotten timer has exactly one"
    requirement: "TI-09"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#what a Partner sees it holds no time notice in the bell of a Partner"
        status: pass
    human_judgment: false
  - id: D4
    description: "A markup canary in a running entry's description renders as escaped text on the entry list, view page, timesheet, panel, bar and dashboard, never as an element"
    requirement: "TI-02"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#what the Admin sees of a description"
        status: pass
    human_judgment: false
  - id: D5
    description: "A database server without the Czech ICU collation fails the readiness gate with the collation named and no connection value, and the passing run prints four ok lines; the helper binds the collation name"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/DeployVerifyCommandTest.php (passes with four ok lines; tells a present collation from an absent one; fails the readiness gate when the Czech collation is missing)"
        status: pass
    human_judgment: false
  - id: D6
    description: "CONTRIBUTING names each Phase 6 mechanism with its enforcing test and the hand-over to Phases 7, 8, 9 and 10; a documentation test fails when a named class, method or test file is gone"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php (only names Phase 6 classes; names the Phase 6 enforcing tests; hands over to Phases 7, 8, 9 and 10; documents the Czech ICU collation requirement)"
        status: pass
    human_judgment: false
  - id: D7
    description: "Browser-only checks of the whole phase: the Czech copy as rendered, the contrast of the running states as measured in a browser, the end-to-end Admin walk, SPA persistence of the bar, panel docking and overlay, week grid at 375px, the stock bell drawing of the forgotten-timer notice"
    verification: []
    human_judgment: true
    rationale: "No browser was available to this run. Contrast was computed from the Filament palette, and the Czech strings were read, but the rendered behaviour is for /gsd-verify-work; the list is under Human checks"

actuals:
  tokens: 6500
  tasks: 3
  commits: 2

plan_head_before: 358bd657dfdb9ead97b49909d17c44504c38730c
plan_head_after: 46424d3e47c5b2eb842a412768a30b27aea86c0e
commits: 2

duration: 14min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 14: Leak proof, collation guard and phase gate Summary

**A single canary-harness test proves a Partner sees no time, rate or price anywhere and that every time surface refuses them, `kokpit:deploy:verify` now refuses a database server without the Czech ICU collation (no fallback), CONTRIBUTING and README document the Phase 6 mechanisms and the hand-over to Phases 7, 8, 9 and 10 next to the tests that enforce them, and the full phase gate is green.**

## Performance

- **Duration:** about 14 min of execution after reading the context (start 2026-10-09T16:07Z)
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files:** 8 (1 created, 7 modified)

## Accomplishments

- `TimeLeakTest` (7 tests) arranges both canary clients through `CanaryRegistry::seedAll`, then adds a finished entry of 3:17:41 on client A's task, a distinct description canary per client, a running Admin entry whose description is a markup canary assembled from fragments, and a forgotten 13-hour timer of a second Admin that the real scheduled job announces. Partner A walks seven pages and none contains a forbidden word, a canary or a fixture duration; the entry and timesheet routes answer 403; the bar, panel, timesheet page, project stats widget and both project tabs answer 403 for the own and the other client's project; the Partner's `notifications` rows and rendered bell hold no time notice. A companion test proves the fixtures exist by showing the Admin the entry text, the duration and "Výkaz". The Admin side shows the markup as `&lt;script&gt;...` on the list, the view page, the timesheet and the panel, and never as an element on the bar and the dashboard.
- `CzechCollation::isAvailable(?string $name = null)` asks `pg_collation` with the name bound. `DeployVerifyCommand` has a fourth check `collation` ("České řazení") whose failure reason names the collation constant and no connection value. The test simulates a server without ICU by a temporary view named `pg_collation` first on the search path.
- CONTRIBUTING gains the conventions Time entries, Timer lock and clock and Livewire components (each with its enforcing tests), a rewritten Ordering bullet that includes the deploy check, and hand-over notes for Phases 7 (REST API, with the merged task-reference and API-token notes), 8 (exchange rates and reports, with the Prague-day rule and the merged export formula note), 9 and 10 (guard trigger re-creation, shared billed state). The Deploy section names the collation among the readiness checks. README states the ICU requirement and the forgotten-timer schedule.
- `RepositoryFilesTest`: the old combined Phase 5 test is split, and four new tests cover the 20 Phase 6 classes (existence and the named methods and constants), the 15 enforcing test files (named and present), the hand-over notes (Phase 6 note and stale labels absent, the Phase 10 guard re-creation sentence present) and the README requirement.
- Gate after the last code edit: full `ddev exec vendor/bin/pest` 2469 passed (17714 assertions), both timer concurrency runs (the 8-process run and its mutation run) green; `pint --test` 526 files pass; Larastan no errors; `composer check-licenses` 210 packages allowed; `scripts/tests/test-gitignore.sh` 112 assertions; `bash scripts/tests/run.sh` PASS 10 FAIL 0; `scripts/check-sensitive.sh --all` clean.

## Task Commits

1. **Task 1 (tracer): a Partner walks every reachable page and finds no time, rate or price** - `792a2c7` (test)
2. **Task 2: Czech collation readiness check and the Phase 6 documentation with its test** - `46424d3` (feat)
3. **Task 3: .gitignore review and the full phase gate** - no code commit (no change needed, see below)

**Plan metadata:** recorded in the following docs commits.

The tracer feedback gate ran as a re-run of the tracer verify (the whole isolation suite, 184 tests, plus Pint and Larastan) which passed, and expansion continued.

## .gitignore review (HYG-01)

`git status --ignored` and the untracked list were read. Phase 6 added no build step, coverage output or other new tool output: the ignored entries are all earlier categories (DDEV, `.env`, `vendor/`, Laravel storage and compiled views, the published Filament assets, `.planning/codebase/`). No rule or test case was added; `scripts/tests/test-gitignore.sh` passes (112 assertions).

## Decisions Made

See `key-decisions`. One finding worth keeping: Pest's `toContain($needle, $message)` treats the second argument as another needle, not as a failure message, so a "not contain" call with a message passes silently. The leak helper therefore asserts through `str_contains` inside `expect(...)->toBeFalse($message)`.

## Deviations from Plan

### Plan changes (within scope)

**1. Distinct description canaries.** The plan lists "no time entry canary of client A or B". The registry's time entry fixture uses the project canary as the description, so it cannot be told apart from the project name the Partner is allowed to see. The test adds one finished entry per client with its own description canary and asserts those; the registry entries still run through the route walk (`RouteWalkTest`) as before.

**2. Extra forbidden strings from the handoff notes.** Besides the plan's list the test forbids "Vyfakturováno", "Celkem za den", "Bez úkolu", "Úkoly a čas", "Týden" and, as a whole word only, "Den" (a substring match on "Den" would hit other words).

**3. The plan names the collation check test as "four ok lines plus the unavailable-name case".** Added a third test that makes the command itself fail on a missing collation, so the failure path of the readiness gate is proven and not only the helper.

**4. Documentation test layout.** The old combined test "names the Phase 5 enforcing tests and the hand-over notes for Phases 6, 7, 9 and 10" is split: the Phase 5 test names part stays, and the hand-over assertions move to the new "Phases 7, 8, 9 and 10" test. `EntryContextOptions` is named in CONTRIBUTING (Time entries bullet) so the 20-class list of the plan holds in full.

**5. README threshold wording.** The plan implies an environment variable; the threshold is the config value `time.long_running_hours` (12), so the README names that key.

**Total deviations:** 0 auto-fixed bugs, 5 in-scope adjustments. **Impact:** none on the contract of the plan.

## Issues Encountered

None open. A first draft of the leak helper used `->not->toContain($text, $message)` and passed vacuously; it was found when the positive assertion with a message failed on a string that was present, and replaced as described above. No auto-fix limit was reached.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-36 (`TimeLeakTest` over the canary harness, runs with the isolation suite), T-06-37 (fictional data only; `scripts/check-sensitive.sh --all` and the hook clean), T-06-38 (collation check fails the readiness gate; README and CONTRIBUTING state the requirement), T-06-SC (no package added).

## Requirements

`requirements-completed` copies the plan frontmatter: TI-01 to TI-09 and PR-05. Per the coverage audit TI-05 (automatic billing by invoicing) and TI-08 (snapshot of rate and amount on billing) are PARTIAL: only the Phase 6 part is delivered, the rest belongs to Phase 10. Therefore REQUIREMENTS.md was ticked only for the IDs that are fully delivered by the phase (TI-02, TI-03, TI-06, PR-05 and the already ticked ones); TI-08 stays unticked and TI-05, which an earlier plan already ticked, is left as it is and flagged below.

## Coverage audit (phase roll-up)

All rows of the plan's multi-source audit are COVERED, except TI-05 and TI-08 (PARTIAL, the deferred parts are Phase 10) and the CONTEXT deferred items (calendar timeline view, e-mail for a forgotten timer: EXCLUDED, deferred).

## Human checks (for /gsd-verify-work)

No browser was available to this run, so none of the three plan human checks could be performed in a browser. What was done instead, and what remains:

1. **Czech copy review.** Done by reading every string under `kokpit.time.*`, `kokpit.activity` (`time_entry`), `kokpit.deploy_verify` and `enums.time_billing_state`: natural Czech, formal address (Spusťte, Můžete, Zkontrolujte), consistent terms (časovač, časový záznam, výkaz, vyfakturováno, nefakturovatelné), three-way plural forms for the billing bodies, no English left, "Úterý", "Čtvrtek", "Pátek", "Nevyfakturováno" spelled with correct diacritics. Remaining: look at the rendered pages once.
2. **Contrast (carried flag F-1).** Computed from the Filament palette (OKLCH converted to sRGB, WCAG relative luminance); the browser measurement is still open. Warning is Amber, danger is Red, light surface white, dark surface gray-900. Running pill text (shade 700 on shade 50 in light, shade 400 on a 10 % tint in dark): warning 4.87:1 light and 5.35:1 dark; danger 5.88:1 light and 4.05:1 dark. The stop icon (Filament picks the shade for at least 3:1 against its surface): warning 3.08:1 light and 4.28:1 dark; danger 3.50:1 light and 4.05:1 dark. The grey "Zastavit časovač" dropdown button is about 19:1 light and 17.8:1 dark. **Finding:** the danger pill text in dark mode is 4.05:1, below the 4.5:1 that small text needs; the elapsed text, the stop icon and (in the too-long state) the warning triangle are present in every running state, so colour is never the only carrier, but the dark danger text should use a lighter shade (for example 300) if the browser measurement confirms it. Not changed here: it is a design token decision of the owner. Amber primary next to the warning running state remains the same hue by design (F-1).
3. **End-to-end walk as the Admin** (client-only timer from the bar in two clicks and "Doplnit záznam", start from a task card stops the first and the toast names its duration, side panel, manual overlapping entry with warning, bulk billing, billed entry locked callout and view URL, cancel billing, day and week timesheet, project overview, `ddev artisan schedule:run` after setting a running entry's start back 13 hours: one bell notice, the bar turns danger, the timer keeps running; keyboard-only dropdown). The automated tests cover each step; the walk itself is for `/gsd-verify-work`.
4. **Carried browser checks from earlier plans:** SPA persistence of the bar across navigation (06-08), panel docking at 1440px and overlay at 375px (06-09), the task card and header start affordances (06-10), the week grid not scrolling the page sideways at 375px (06-11), footer line alignment of "Úkoly a čas" also with the Nefakturovatelné column on, and a negative Zbývá in red (06-12), the stock bell drawing the forgotten-timer notice with its action button (06-13).

## For the owner

These are collected from the whole phase. Nothing below changed a requirement file.

1. **TI-07 wording (plan 06-01).** REQUIREMENTS.md TI-07 says "end after start" (strict) while ROADMAP.md Phase 6 success criterion 2 says the database rejects an end "before the start". The phase keeps `ended_at >= started_at` in the database (strict `>` belongs to forms and the Phase 7 API) so a same-second auto-stop works. Decide whether TI-07 should read "end not before start" or whether zero-length auto-stopped entries must be removed (research A1: they are kept).
2. **TI-05 and TI-08, deferred parts (flagged, not done by this phase).** The ROADMAP.md mapping note defers TI-05 automatic billing by invoicing and the TI-08 snapshot of rate and amount on billing to Phase 10 (exercised with IN-02, IN-03), but the Phase 10 `**Requirements**` line in ROADMAP.md and the REQUIREMENTS.md traceability table (which maps TI-05 and TI-08 to Phase 6 only) do not carry these deferred parts. The owner should add them to the Phase 10 requirement list in ROADMAP.md and REQUIREMENTS.md, so Phase 10 planning cannot drop them. Phase 6 planning and execution do not edit ROADMAP.md requirement lists or REQUIREMENTS.md. Note that TI-05 reads "Complete" in REQUIREMENTS.md because an earlier plan ticked it; it is PARTIAL by this audit, and the owner may want to un-tick it until Phase 10 delivers the automatic part.
3. **Midnight-spanning entries (research A5).** An entry belongs to the Prague day it started, in the timesheet, the totals and the hand-over note for Phase 8. Confirm or choose a splitting rule.
4. **Footer Odhad sums (plan 06-12).** The "Úkoly a čas" footer Odhad is the sum of the estimates that tasks hold in their own billing row and the footer Zbývá is blank, so an estimate inherited by subtasks is never counted twice. The plan said "sums of all numeric columns".
5. **Open Question 1.** Task rows compare time only with the task's own or its parent task's estimate, never the project estimate (that one is in the stats row).
6. **Open Question 2.** "Bez úkolu" is a footer line next to "Celkem", not a fixed last row (a relation manager table cannot host a synthetic record): a deviation from the UI-SPEC wording. A project with no tasks shows the empty state, and its unassigned time is in the stats row.
7. **Open Question 3.** A running billable entry counts as unbilled time at its elapsed seconds; the widget does not poll, so the figure does not tick.
8. **Open Question 4.** The rate source labels (úkol, nadřazený úkol, projekt, klient, výchozí nastavení) are in `kokpit.time.rate_source.*`; the task page keeps its own labels.
9. **Collation guard (Pitfall 3, A4).** The deploy gate fails on a server without `cs-CZ-x-icu` and there is no fallback to the default collation, by decision. Any non-ICU PostgreSQL build cannot host the application.
10. **A6.** There is one Admin; the entry list shows every user's entries.
11. **A12.** No cap on a duration and no ban on future times; a typo is corrected by editing.
12. **A13.** Archiving a client, project or task never stops a running timer.
13. **Plan 06-07.** An "Úkol" filter was added to the entry list beyond the plan.
14. **Plan 06-08.** "Všichni klienti" in the timer dropdown lists only the clients not already in "Naposledy použití", so no option appears twice.
15. **Plan 06-12.** The project stats widget lives under `app/Filament/Resources/ProjectResource/Widgets` and is registered through the resource, correcting the path proposed in 06-PATTERNS.md; the panel widget list stays empty.
16. **Flag F-1.** Amber primary next to the warning running state: contrast computed above, danger text in dark mode 4.05:1 (see Human checks 2).
17. **Flag F-7.** The checklist was revisited; no change.
18. **Phase 10 requirement list.** Add the deferred parts of TI-05 and TI-08 (item 2) to the Phase 10 `**Requirements**` line and the traceability table.

## Next Phase Readiness

Phase 6 is complete and the gate is green. The hand-over notes for Phases 7, 8, 9 and 10 are in CONTRIBUTING and tested. Open for `/gsd-verify-work`: the browser checks above and the owner decisions in "For the owner", above all the TI-07 wording and the Phase 10 requirement list.

## Self-Check: PASSED

- Files exist: `tests/Isolation/TimeLeakTest.php` and all seven modified files.
- Commits `792a2c7` and `46424d3` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 2.
- All acceptance criteria of tasks 1 and 2 re-run and passing (greps, `tests/Isolation`, the three targeted files); task 3 criteria: full Pest, `check-licenses`, `test-gitignore.sh` and `check-sensitive.sh --all` exit 0.
