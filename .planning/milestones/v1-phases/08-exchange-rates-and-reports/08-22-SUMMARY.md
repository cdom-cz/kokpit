---
phase: 08-exchange-rates-and-reports
plan: 22
subsystem: testing
tags: [isolation, partner-leak, canary, documentation, phase-gate, validation, pest]

# Dependency graph
requires:
  - phase: 08-exchange-rates-and-reports
    provides: "plans 08-01 to 08-21: the rates, report, export, work report and dashboard surfaces this plan seals and documents"
provides:
  - "Phase 8 block of TimeLeakTest: a Partner meets no Phase 8 URL, Livewire component, rate, amount, export summary, bell notice or domain service"
  - "canary task of every client in status in_review, so the Partner dashboard highlight is part of every isolation walk"
  - "CONTRIBUTING conventions for exchange rates, reports and exports, the work report and the dashboard, and the Phase 9 and 10 hand-over"
  - "README operator section for the first CNB download, the history backfill, the scheduler rhythm and the deploy rehearsal checks"
  - "RepositoryFilesTest guards that every named Phase 8 class, method and enforcing test exists"
  - "signed-off 08-VALIDATION.md with the final task map, the manual-only checks and the contrast gate record"
affects: [09-documents, 10-invoicing, 11-payments, verify-work]

# Actuals (#2632)
actuals:
  tokens: 24000
  tasks: 3
  commits: 3
plan_head_before: d8b7f2ddde88f1eb3d3c1baf62dce0ac86f79d65
plan_head_after: b18e23af8556e4139d86bd2d3ddef37294edf8f5

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Phase leak sweep: one describe block per phase in TimeLeakTest, fixtures built in the system context, forbidden strings checked against the rendered HTML, Livewire snapshots and the bell"
    - "Documentation guard: a short-name to FQCN map, a method list with the spelling the text uses, and an enforcing-test file list, all checked against CONTRIBUTING.md"

key-files:
  created:
    - .planning/phases/08-exchange-rates-and-reports/08-22-SUMMARY.md
  modified:
    - tests/Isolation/TimeLeakTest.php
    - tests/Support/CanaryRegistry.php
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - scripts/tests/test-gitignore.sh
    - .planning/phases/08-exchange-rates-and-reports/08-VALIDATION.md

key-decisions:
  - "The canary task fixture is created in status in_review; no existing isolation test relied on the old default status, so none had to change"
  - "The Phase 8 hand-over note is removed from CONTRIBUTING; its valid content moved into the four convention bullets, and Phases 9 and 10 gain the converter, snapshot, work report and dashboard-band hand-over"
  - ".gitignore needs no change (the Dompdf cache rule of plan 08-03 already covers the only tool output); the self-test gained the missing case for it"
  - "Contrast gate: Filament 5 chooses button background and text by contrast, so the three primary buttons measure 5.29:1 in light and 4.70:1 in dark mode, and the white-on-Amber 3.2:1 reading of UI-SPEC flag F-1 does not apply"

patterns-established:
  - "Pest file helpers carry a file-specific prefix (leakPhase8*), because they share one global namespace"
  - "A leak test is mutation-checked once: pointing the Partner assertion at the Admin made it fail on the first forbidden string"

requirements-completed: [EX-01, EX-02, EX-03, RE-01, RE-02, RE-03, RE-04, RE-05]

coverage:
  - id: D1
    description: "A Partner who opens or mounts any Phase 8 Admin surface gets 403, and the Partner dashboard, the bell and every page a Partner can open hold no time, rate, price, amount, CNB rate, export summary or other client's canary"
    requirement: "RE-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#Phase 8: the Partner and the rates, reports and dashboard surfaces"
        status: pass
    human_judgment: false
  - id: D2
    description: "The canary harness holds one in_review task and one time entry per client, so the route walk and the rendered-HTML check exercise the review highlight"
    requirement: "RE-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php and tests/Isolation/TimeLeakTest.php#shows the Partner dashboard with the review highlight"
        status: pass
    human_judgment: false
  - id: D3
    description: "The report reader, the work report sources and the fixed-price summary refuse a Partner context and the converter finds no rate; the same calls work in a system run"
    requirement: "EX-03"
    verification:
      - kind: integration
        ref: "tests/Isolation/TimeLeakTest.php#refuses the report, work report and fixed price services in a Partner context"
        status: pass
    human_judgment: false
  - id: D4
    description: "CONTRIBUTING documents the Phase 8 conventions and hands over to Phases 9 and 10, guarded by the documentation test; the Phase 8 note is gone"
    requirement: "RE-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 8 classes in CONTRIBUTING.md that exist in app/"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#names the Phase 8 enforcing tests, and each of them exists"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands over to Phases 7, 9 and 10, carries no Phase 8 note"
        status: pass
    human_judgment: false
  - id: D5
    description: "README tells the operator to run the first CNB download and then backfill history, what the scheduler does and which PHP extensions the deploy rehearsal must confirm"
    requirement: "EX-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only documents artisan commands that exist"
        status: pass
    human_judgment: true
    rationale: "Whether the first-deploy steps read clearly and are complete for an operator is a judgement; the command name is checked, the wording is not"
  - id: D6
    description: "The phase gate is green: full Pest suite, Larastan, Pint, licence check, .gitignore self-test and the sensitive-content scan over the whole tree"
    verification:
      - kind: other
        ref: "ddev exec vendor/bin/pest (3867 passed, 3 skipped); pint --test; phpstan analyse; ddev composer check-licenses; bash scripts/tests/test-gitignore.sh; scripts/check-sensitive.sh --all"
        status: pass
    human_judgment: false
  - id: D7
    description: "Browser and spreadsheet checks of plans 08-06, 08-09, 08-13, 08-14, 08-15, 08-16 and 08-21 (PDF look, loading and in-flight states, 375 px layout, bell, XLSX in a spreadsheet application) and the pixel check of the button contrast"
    verification: []
    human_judgment: true
    rationale: "No executor of the phase could drive a browser or a spreadsheet application; each check is an open unrun-verify entry (ids 2 to 7) in .planning/WINDOWS.md and is listed under Manual-Only Verifications in 08-VALIDATION.md"

# Metrics
duration: 14min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 22: Isolation Sweep, Conventions and Phase Gate Summary

**A Partner who tries every Phase 8 surface now fails the isolation suite on any leak of time, money, a CNB rate, an export or the other client; the conventions and the Phase 9 and 10 hand-over are written next to their enforcing tests, and the phase gate is green.**

## Performance

- **Duration:** 14 min
- **Started:** 2026-10-14T23:37:26Z
- **Completed:** 2026-10-14T23:52:00Z
- **Tasks:** 3
- **Files modified:** 7 (plus this SUMMARY and the planning state files)

## Accomplishments

- Task 1 (tracer): the canary task of every client is created in `in_review`, so the Partner dashboard is rendered with the "Ke kontrole" highlight in every isolation test. The new Phase 8 block of `TimeLeakTest` (8 tests, 380 assertions) builds invented CNB rates, a ready export of the Admin whose summary carries a canary, the export-ready notice and the CNB failure alert in the Admin's bell, and proves for Partner A: 403 on `/admin/time-report`, `/admin/exchange-rates` and `/admin/report-exports`; 403 when mounting `TimeReportPage`, `ListExchangeRates`, `ExchangeRateStats`, `ListReportExports`, `HoursStats`, `UnbilledPerClient`, `UpcomingDeadlines` and `TomorrowPlan`; the dashboard, every page a Partner can open and the bell hold none of the 14 "Never on a Partner surface" strings, no CNB rate, no export summary, no duration and no canary of client B, while the in_review task of client A is listed with its highlight and button; the reader, both work report sources and the fixed-price summary throw `AuthorizationException`, the converter throws `ExchangeRateMissing`, and the same calls work in a system run, so the refusal comes from the Partner context. The assertion was mutation-checked by pointing the dashboard test at the Admin, which failed on the first forbidden string.
- Task 2: four convention bullets in CONTRIBUTING (Exchange rates, Reports and exports, Work report, Dashboard), the Health indicators bullet updated for the real last-rate-date indicator, the Phase 8 hand-over note removed, and the Phase 9 and 10 notes extended (converter for transaction amounts, snapshot of billed entries, document rate copies, work report attachment, the reserved sort-20 dashboard band, the dompdf licence ruling as taken). README gained the operator section (first download, then "Doplnit historii", the 15:00 to 18:00 Prague rhythm on Czech working days, the final-run alert, the extensions `dom`, `mbstring`, `gd`, `zip` and the effective `memory_limit` as deploy-rehearsal checks). `RepositoryFilesTest` gained "only names Phase 8 classes" (30 classes, 9 methods, the key phrases and the COVERAGE.md file), "names the Phase 8 enforcing tests" (31 test files) and the changed hand-over test.
- Task 3: the `.gitignore` review found no new output (details below), the full gate ran green, and `08-VALIDATION.md` was rewritten to the final 56 task rows with green statuses, the manual-only checks with their ledger ids, the contrast gate record and the sign-off.

## Task Commits

1. **Task 1: Tracer - a Partner who tries every Phase 8 surface sees nothing of time, money or the other client** - `ab09326` (test)
2. **Task 2: Conventions, hand-over and operator notes, guarded by the documentation test** - `5539e5f` (docs; the test changes were written first and failed for the right reason before the text existed)
3. **Task 3: Per-phase .gitignore review, the phase gate and the validation sign-off** - `b18e23a` (chore)

**Plan metadata:** see the `docs(08-22)` commit that carries this file, STATE.md, ROADMAP.md and REQUIREMENTS.md.

The tracer gate ran in auto-mode terms: the `<verify>` (`ddev exec vendor/bin/pest tests/Isolation`, 211 passed) was re-run end to end before the expansion tasks.

## Files Created/Modified

- `tests/Isolation/TimeLeakTest.php` - the Phase 8 leak sweep (helpers `leakPhase8Forbidden()` and `leakPhase8AssertClean()`)
- `tests/Support/CanaryRegistry.php` - the canary task is created with `status => in_review`
- `CONTRIBUTING.md` - Phase 8 conventions, Health bullet, Phase 9 and 10 hand-over, Phase 8 note removed
- `README.md` - the "Exchange rates and reports" operator section
- `tests/Feature/Repo/RepositoryFilesTest.php` - the Phase 8 documentation guards
- `scripts/tests/test-gitignore.sh` - case for `storage/framework/dompdf/` (the rule itself already existed)
- `.planning/phases/08-exchange-rates-and-reports/08-VALIDATION.md` - final map, manual-only list, contrast record, sign-off

## Decisions Made

- The in_review canary task needed no change to any existing isolation test: the whole isolation suite (211 tests) stayed green after the fixture change.
- The per-phase `.gitignore` review recorded "no change needed" for the ignore file. Dompdf writes its font metrics cache to `storage/framework/dompdf`, already ignored since plan 08-03; the export job writes its temporary file to the system temp directory and then to the private disk; the work report is rendered on demand and written nowhere. Only the self-test lacked a case for the Dompdf rule, so one was added.
- Contrast: the UI-SPEC flag F-1 assumed white text on Amber 600 (3.19:1). Filament 5's `ButtonComponentColorMap` selects light background Amber 400 with text Amber 900 (5.29:1) and dark background Amber 600 with text Amber 950 (4.70:1), read with `get_component_color_classes()` in DDEV and computed from the palette. All three buttons clear AA. The values are computed, not measured on a rendered screen.
- Documentation states the facts of the code (event names, config keys, class and method names) rather than the plan's wording, and the guard test pins each of them.

## Deviations from Plan

None - plan executed exactly as written. Two details worth knowing, neither a deviation: the `<human-check>` of Task 3 could only be partly done (the contrast was computed, the five visual checks were not run because the executor has no browser), and the commit of Task 1 was amended once, before any later commit, to add the literal `in_review` to a comment of `CanaryRegistry` so the plan's acceptance grep matches.

## Issues Encountered

- The first run of the new Phase 8 block passed on the first attempt, which was suspicious for a leak test, so the Partner dashboard test was temporarily pointed at the Admin to prove it fails; it did, on "Nevyfakturováno". The temporary test file was deleted.
- A `sed` import edit failed on macOS (`\n` in the replacement); the missing `use Illuminate\Support\Str;` was added with a script instead. The test had passed through the framework's `Str` alias, so no behaviour was affected.
- The human checks named in the plan were not done by anyone yet. They stay open as ledger entries 2 to 7 of `.planning/WINDOWS.md` (these block `/gsd-ship` while `workflow.windows_enforce` is on) and are listed in `08-VALIDATION.md`. Open-item list for the owner: PDF look (08-06), report loading state (08-09), download in-flight state (08-13), 375 px layout and tree toggles (08-14), Admin dashboard layout (08-15), bell and CSV in a spreadsheet (08-16), XLSX in a spreadsheet application (08-21), and the pixel check of the contrast.
- Three tests are skipped in the full suite, all in `WorkReportPdfTest` and all environment guards from plan 08-06 (`pdftotext` is not installed in DDEV; one case cannot lower the memory limit below the running usage). Not new, not caused by this plan.
- Known follow-ups captured as owner todos and NOT implemented here (by title): "Redesign unreadable time report layout", "Add overdue highlight to tasks list", "Show today's plan under hours stats on Admin dashboard", "Collapsible dashboard widgets persisted in DB".

## Known Stubs

None. This plan adds tests, documentation and planning text only.

## User Setup Required

None - no external service configuration required. The operator steps for the first CNB download are in the README ("Exchange rates and reports").

## Next Phase Readiness

- Phase 8 is complete: all eight requirements (EX-01..03, RE-01..05) have passing tests and the gate is green. Ready for `/gsd-verify-work 8`, which should start with the seven open manual checks above.
- Phase 9 reuses `ExchangeRateConverter` for transaction amounts (FI-01) and stores the work report through the documents module; Phase 10 snapshots rate and amount on billed entries, copies the CNB rate into documents, attaches the work report through `WorkReportSource::fromEntryIds()` and adds the unpaid-invoices tile to the reserved sort-20 band (Phase 11 the unresolved-payments tile). All of it is in the CONTRIBUTING hand-over notes.

## Self-Check: PASSED

- Files: all seven modified files exist; commits `ab09326`, `5539e5f` and `b18e23a` are ancestors of HEAD.
- Acceptance criteria of tasks 1 to 3 re-run: every grep passed; `ddev exec vendor/bin/pest tests/Isolation` (211 passed), `tests/Feature/Repo/RepositoryFilesTest.php` (34 passed), full suite (3867 passed, 3 skipped), `ddev composer check-licenses`, `bash scripts/tests/test-gitignore.sh` (122 assertions) and `scripts/check-sensitive.sh --all` all exit 0.
- Plan-level verification: Pint `--test` clean over 735 files, Larastan level 8 reports no errors.

---
*Phase: 08-exchange-rates-and-reports*
*Completed: 2026-10-15*
