---
phase: 03-operations-foundation
plan: 09
subsystem: settings
tags: [numbering, number-patterns, filament, livewire, settings-page, czech-ui]
type: execute

requires:
  - phase: 03-operations-foundation
    provides: SettingsPage with the invoicing tab (plans 03-04, 03-06) and DocumentNumbering, NumberPattern, NumberPatternRule, NumberingSettings (plan 03-08)
provides:
  - Numbering section on the invoicing tab with the invoice, proforma and credit-note patterns
  - Live (on blur) preview of the next number under each pattern, read through DocumentNumbering::preview() so no counter is touched
  - Czech reset-period warning (numbering restarts at 1) compared against the stored row
  - Task pattern shown disabled as {KEY}-{N} with an explanation, crafted payloads refused
affects: [Phase 10 invoice issuing, Phase 5 task numbers, 03-19 settings acceptance]

plan_head_before: 88bfd5e94a1ff97600545457bf8d0f43b49d3a57
plan_head_after: cd3978ef00451c7f9edcb54f9614bda520bda78d

actuals:
  tokens: 4000
  tasks: 2
  commits: 4

tech-stack:
  added: []
  patterns:
    - "A pattern field carries the data-layer rules (NumberingSettings::rules()) so the form and the save cannot drift; the preview is the field's helper text (a closure re-evaluated on every render), the reset warning a sibling Text component with a visible() closure"
    - "A comparison with the stored value reads the settings row directly (scoped SettingsProperty model), not the in-memory settings object, so a failed or rolled-back save cannot make the page compare against an unsaved value"
    - "A disabled Filament field is not dehydrated but is still validated (validatedWhenNotDehydrated defaults to true), so a crafted payload for it fails with the rule's Czech message instead of being dropped silently"

key-files:
  created:
    - tests/Feature/Operations/NumberingPageTest.php
  modified:
    - app/Filament/Pages/SettingsPage.php
    - lang/cs/kokpit.php

key-decisions:
  - "The preview is the pattern field's helper text and the token help is the section description; the reset warning is a separate Text component (colour warning) shown only while the edited reset period differs from the stored one"
  - "The stored reset period is read from the numbering settings row, not from the NumberingSettings instance, because Livewire re-renders after a failed save with the instance already filled with the rejected values"
  - "The task pattern field is disabled (not dehydrated) but keeps NumberPatternRule(Task), so a crafted data.numbering.task_pattern is answered with the Czech task_fixed reason and nothing is stored; NumberingSettings::rules() refuses it again on save"
  - "Pattern inputs update on blur only (live(onBlur: true)), each preview is one indexed peek() SELECT"
  - "The reset warning wording says numbering restarts at 1 as the plan requires; a series used earlier in the same period (switching back and forth) would in fact continue, which the plan did not ask the page to detect"

patterns-established:
  - "Settings page sections for a settings group: Group with statePath of the group, one Section inside, helper methods that return the input plus its companion components"

requirements-completed: []

coverage:
  - id: D1
    description: "The invoicing tab shows the next invoice number of the stored pattern and updates it while the pattern is edited, without ever changing number_sequences"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#shows the next invoice number of the stored pattern on the invoicing tab without touching the counters"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#updates the preview while the pattern is edited and still leaves the counters alone"
        status: pass
    human_judgment: false
  - id: D2
    description: "A pattern saved on the page is stored and the next real allocation formats with it; all three document patterns save together and the counters stay unchanged"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#stores the saved pattern and the next allocation follows it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#saves all three document patterns together and leaves the counters alone"
        status: pass
    human_judgment: false
  - id: D3
    description: "An invalid pattern of any document kind shows its Czech reason on that field and stores nothing"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#shows the Czech reason on the invoice pattern field and stores nothing for an invalid pattern"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#refuses an invalid pattern of each document kind with its own reason"
        status: pass
    human_judgment: false
  - id: D4
    description: "A pattern whose reset period differs from the stored one shows the Czech restart-at-1 warning; the same reset period (also switching {YYYY} to {YY}) shows none; checked for all three kinds"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#warns in Czech that numbering restarts at 1 when the reset period changes and says nothing otherwise"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#warns for the proforma and the credit note pattern too, each against its own stored pattern"
        status: pass
    human_judgment: false
  - id: D5
    description: "Proforma and credit-note previews read their own counters; an allocated invoice number does not move them"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#shows an own preview under the proforma and the credit note pattern, read from their own counters"
        status: pass
    human_judgment: false
  - id: D6
    description: "The task pattern is disabled and shows {KEY}-{N} with an explanation; a crafted payload that sets another task pattern is refused with the Czech reason and the stored pattern is unchanged"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#keeps the task pattern disabled and fixed to KEY-N with an explanation"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php#refuses a crafted payload that sets another task pattern and leaves the stored one"
        status: pass
    human_judgment: false
  - id: D7
    description: "Czech wording of the section title, token help, preview line, reset warning and task explanation reads naturally and the section is laid out clearly on the invoicing tab"
    verification: []
    human_judgment: true
    rationale: "Wording quality and visual layout are judgments no test asserts; tests prove only that the texts are present and shown or hidden at the right time"

duration: 25min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 09: Numbering Section on the Settings Page Summary

**Invoicing tab numbering section: invoice, proforma and credit-note pattern inputs with on-blur previews read from their own counters (never consuming a number), Czech pattern errors, a restart-at-1 warning when the reset period changes, and the task pattern locked to {KEY}-{N}**

## Performance

- **Duration:** about 25 min
- **Started:** 2026-10-08T03:05Z
- **Completed:** 2026-10-08T03:31Z
- **Tasks:** 2
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments

- `NumberingSettings` is on the settings page (state key `numbering`) and rendered as a section inside the invoicing tab with Czech labels and a token help text naming `{YYYY}`, `{YY}`, `{MM}` and the `{N}` counter.
- Each of the invoice, proforma and credit-note pattern inputs updates on blur, carries `NumberingSettings::rules()` (so `NumberPatternRule` with its Czech reasons) and shows "next number" as its helper text, computed by `DocumentNumbering::preview()` from the kind's own counter; an unparsable pattern shows no preview and the field's own error instead.
- A warning (Czech, colour warning) appears under a pattern field while its reset period differs from the stored pattern's, e.g. `{YYYY}` to `{YYYY}{MM}`; `{YYYY}` to `{YY}` shows nothing.
- The task pattern is a disabled input holding `{KEY}-{N}` with an explanation. A crafted Livewire payload for it fails with the Czech `task_fixed` reason and the stored pattern stays.
- The page never writes `number_sequences`: every test asserts the counter rows before and after.
- Full gate green: `ddev composer ci` (Pest 676 passed, Pint, PHPStan level 8, licence check on 201 packages).

## Task Commits

1. **Task 1: Tracer - invoicing tab shows the next invoice number without consuming it, a saved pattern drives the next allocation** - `8149909` (feat)
2. **Task 2: Proforma and credit-note previews, Czech pattern errors, reset warning, locked task pattern** - RED `30cd29f` (test), GREEN `dd914be` (feat), docblock-only REFACTOR `cd3978e` (the plan's acceptance grep looks for the literal `NumberPatternRule` in the page file, which the code only reaches through `NumberingSettings::rules()`)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`; RED then GREEN commits are present and in order, the only REFACTOR commit (`cd3978e`) is a comment change.

- **Tracer feedback gate (Task 1, automated-only verify):** the tracer `<verify>` (Pest on the numbering page and settings page tests, Pint, PHPStan) was re-run green and the full suite (664 tests) passed before expansion. A mutation (preview ignores the typed pattern) made the "updates the preview" test fail, then the source was restored.
- **RED (`30cd29f`):** 9 of 15 tests failed on the planned behavior. Semantic assessment: failures are assertion failures (a missing proforma/credit-note field makes `assertHasFormErrors` fail, the reset warning and the new preview lines are not in the output, `numbering.task_pattern` does not exist on the form, stored patterns do not match after saving three patterns). One test initially failed on a non-existent method (`getHelperText` is not in this Filament version); it was rewritten to assert the rendered preview lines before the RED commit, so no RED failure comes from a test fault. `gsd_run check tdd-red-evidence` was not run (Pest console output is not a supported report format); the assessment is manual inspection of the Pest output.
- **GREEN (`dd914be`):** all 15 page tests pass, then `ddev composer ci`.
- **Tests green on their first run** (the tracer already implemented the behavior): the invoice invalid-pattern test, the "invoice month without a year" dataset case and the "names the allowed tokens" test. Mutation checks, source restored after each:

| Mutation | Tests that failed |
|---|---|
| reset-period comparison inverted (`===` instead of `!==`) | the three warning tests |
| task input rule removed | the crafted-payload test |
| task input no longer disabled | the disabled / fixed task pattern test |
| `->rules()` removed from the document pattern inputs | none: the data-layer rules of `NumberingSettings::save()` still refuse the pattern and the error is shown on the same field (intended defence in depth, T-03-21) |

Note: the RED commit alone leaves the full suite non-loadable because a test helper (`storedPatterns`) collides with one in `NumberingTest.php` ("Cannot redeclare function"); the GREEN commit renames it. The targeted RED run (single file) was valid.

## Files Created/Modified

- `app/Filament/Pages/SettingsPage.php` - `NumberingSettings` in `SETTINGS`; `numberingSection()`, `patternInputs()`, `patternInput()`, `previewNumber()`, `resetPeriodChanges()`
- `lang/cs/kokpit.php` - `settings.numbering.*`: title, token help, field labels, preview line, reset warning, task explanation
- `tests/Feature/Operations/NumberingPageTest.php` - 15 cases (with datasets) over the numbering section

## Decisions Made

See `key-decisions` in the frontmatter. In short: preview as helper text, warning as a sibling component, stored reset period read from the settings row, task pattern disabled but still validated.

## Deviations from Plan

None - plan executed exactly as written (the choice of helper text for the preview and a `Text` component for the warning was left open by the plan).

## Issues Encountered

- A Pest run right after a file write sometimes ran the previous file version (container sync lag, as in 03-08); a short wait and rerun fixed it. It looked like "my change did nothing" once.
- `TextInput::getHelperText()` does not exist in the installed Filament (helper text is a child schema), so the per-kind preview assertion uses the rendered text, with a distinct counter value per kind.
- `__()` with replacements is typed `array|string|null` for PHPStan; the helper text closure casts it to string.

## Known Stubs

None.

## Threat Flags

None. T-03-21 is mitigated by `NumberPatternRule` on every form field (including the disabled task field), again in `NumberingSettings::rules()` on save, and the crafted-payload test; T-03-22 by on-blur updates and one indexed `peek()` read per preview; T-03-SC: no package added.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording under `kokpit.settings.numbering.*` (title, token help, preview, reset warning, task explanation) and the section layout in a browser; the wording is placeholder-quality discretionary text and the layout was not visually inspected (tests assert content, not appearance).

## Next Phase Readiness

- FND-07 page work is done; `requirements.ready-ids` reports FND-07 not ready because plan 03-19 also declares it, so it is not marked complete here.
- Ready for the next plan of the phase. Phase 10 should add the unique constraint on issued number strings noted in 03-08.

## Self-Check: PASSED

- `tests/Feature/Operations/NumberingPageTest.php` exists; commits `8149909`, `30cd29f`, `dd914be` and `cd3978e` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 4.
- Acceptance greps pass: `NumberingSettings::class` and `DocumentNumbering` in `SettingsPage.php`, `number_sequences` in the test file, `NumberPatternRule` (docblock) and `resetPeriod` in `SettingsPage.php`, `task_pattern` in the test file; `NumberingPageTest` exits 0; `ddev composer ci` is green.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
