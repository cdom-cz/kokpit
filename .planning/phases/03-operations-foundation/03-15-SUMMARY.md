---
phase: 03-operations-foundation
plan: 15
subsystem: infra
tags: [health, system-page, filament, livewire-polling, indicator-registry, access-rule]

requires:
  - phase: 03-operations-foundation
    provides: "OperationsServiceProvider (03-10, 03-13), page boot hook EnforcesPageAccessRule (03-04), alert link to filament.admin.pages.system (03-13)"
  - phase: 02-platform-foundation
    provides: "AccessRule/Audience, PartnerContext, Canary test helpers, RouteWalkTest, PanelRegistryTest"
provides:
  - "Health domain: HealthStatus (colour + Czech label), HealthSlot (six cases), HealthResult, HealthIndicator interface"
  - "HealthIndicatorRegistry singleton: register(), replace() (slot-checked), indicators(), static missingSlots(), fail-safe results()"
  - "PlaceholderIndicator registered for all six slots (Not available yet)"
  - "Admin-only Czech System page at /admin/system (route filament.admin.pages.system) polling every 30 s"
  - "HealthProbeIndicator test probe (counts check() calls, answers or throws)"
affects: [03-16, phase-08-cnb-rates-pdf, phase-10-invoices, phase-11-stripe]

actuals:
  tokens: 7700
  tasks: 2
  commits: 3

plan_head_before: 4ce107ab5563876fb3e7414c813eb76cf54aac79
plan_head_after: 71dd35b6fda6ffb35cb0068efba608ffdedad8ad

tech-stack:
  added: []
  patterns:
    - "Health indicators behind one interface; the registry is the only place that runs them and turns every Throwable into Error with the exception class only"
    - "Page data computed at render with #[Computed], never in mount(); the page boot hook refuses a Partner first, proven with a spy indicator whose call count stays zero"
    - "A slot without an indicator reports Error, never Ok (D-12); missingSlots() is pure so a test can hand it a hand-built list"

key-files:
  created:
    - app/Domain/Operations/Health/HealthStatus.php
    - app/Domain/Operations/Health/HealthSlot.php
    - app/Domain/Operations/Health/HealthResult.php
    - app/Domain/Operations/Health/HealthIndicator.php
    - app/Domain/Operations/Health/HealthIndicatorRegistry.php
    - app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php
    - app/Filament/Pages/SystemPage.php
    - resources/views/filament/pages/system-page.blade.php
    - tests/Feature/Operations/HealthRegistryTest.php
    - tests/Feature/Operations/SystemPageTest.php
    - tests/Support/Probes/HealthProbeIndicator.php
  modified:
    - app/Providers/OperationsServiceProvider.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php

key-decisions:
  - "register() throws a LogicException for a slot that already has an indicator; swapping goes through replace() only (plan 03-16 and Phases 8, 10, 11 must use replace() or skip the placeholder in the binding)"
  - "results() always returns one entry per HealthSlot case; a slot with no indicator is Error with a Czech 'no check registered' text, so a missing indicator is visible on the page and not only in a test"
  - "The page exposes results() and checkedAt() as Livewire #[Computed] properties evaluated per request, so every 30 s poll measures again and nothing runs in mount()"
  - "The view uses Filament components plus a few inline styles, because the project has no Filament theme build and arbitrary Tailwind classes in an app view would not be compiled"

patterns-established:
  - "Registry self-check: missingSlots() fed a list missing one slot must name exactly that slot; the bound registry must give an empty list"
  - "Spy-before-403: replace every slot with a counting probe, request as each non-Admin state, assert zero calls, then request as Admin and assert the probe is called so the zero is not vacuous"

requirements-completed: []  # FND-10 is shared with plans 03-16 and 03-19 and is partially delivered here by design; the shared-ID gate keeps it open

coverage:
  - id: D1
    description: "Six health slots (failed jobs, oldest pending job, scheduler heartbeat, last rate date, unprocessed webhooks, unsent invoice e-mails) behind one HealthIndicator interface in a singleton registry; a test iterates every HealthSlot case"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthRegistryTest.php#resolves an indicator for every health slot, and the indicator reports that slot"
        status: pass
    human_judgment: false
  - id: D2
    description: "missingSlots() self-check, slot-checked replace(), duplicate register() refusal"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthRegistryTest.php (missingSlots, replace, register tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A throwing indicator becomes Error with the exception class only; the page answers 200, keeps the other five slots and never prints the exception message"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/HealthRegistryTest.php#turns a throwing indicator into Error...; tests/Feature/Operations/SystemPageTest.php#answers 200, names the exception class and never prints the exception message..."
        status: pass
    human_judgment: false
  - id: D4
    description: "Admin sees the Czech System page with six slot labels, a status badge each and wire:poll; Partner A, a Partner without a client and a role-less user get 403 before any indicator runs; route walk finds no client B canary"
    requirement: FND-10
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SystemPageTest.php (Admin render, 403 with spy, Livewire 403); tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Visual look of the page (spacing, badge placement, dark mode) and the 30 s refresh in a browser"
    verification: []
    human_judgment: true
    rationale: "Tests assert the rendered text, the badge colour classes and the wire:poll attribute; they do not judge the layout or watch a live refresh"

duration: 6min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 15: System Page and Health Indicator Registry Summary

**Admin-only Czech System page at /admin/system that renders six health slots from a singleton HealthIndicatorRegistry (slot-checked replace, fail-safe results, polling every 30 s); all six slots are placeholders until plan 03-16 and Phases 8, 10, 11 fill them**

## Performance

- **Duration:** 6 min
- **Started:** 2026-10-08T04:22:41Z
- **Completed:** 2026-10-08T04:28:35Z
- **Tasks:** 2
- **Files modified:** 14 (11 created, 3 modified)

## Accomplishments

- Health domain classes and the registry per the artifact table; the registry is bound as a singleton in `OperationsServiceProvider` with one `PlaceholderIndicator` per `HealthSlot` case.
- `SystemPage` (`Audience::AdminOnly`, `EnforcesPageAccessRule`, slug `system`) with a Blade view under `resources/views/filament/pages/` (the first file under `resources/`); results are computed at render, the checked-at time is shown in Europe/Prague.
- Fail-safe presentation: a throwing indicator shows Error with the exception class only; the message text is absent from the page. A slot without an indicator shows Error, never OK.
- FND-10 is partially delivered by design: the last rate date, unprocessed webhooks and unsent invoice e-mails indicators are placeholders until Phases 8, 10 and 11 replace them through `HealthIndicatorRegistry::replace()`; plan 03-16 registers the real failed-jobs, oldest-pending and scheduler indicators.

## Task Commits

1. **Task 1 (tracer): Admin sees six slots from the registry; Partner gets 403 before any indicator runs** - `208ccbe` (feat)
2. **Task 2 (tdd): registry contract and fail-safe presentation**
   - RED `a60ca4e` (test) - 7 of 19 tests fail on the planned behaviour
   - GREEN `71dd35b` (feat) - full registry (`missingSlots`, slot check in `replace`, per-indicator catch)

**Plan metadata:** recorded in the docs commit that follows this summary.

Tracer gate: before expanding, the tracer's `<verify>` was re-run end to end (Pest on the two test files, Isolation, Arch, EnumLabelsTest, then the full suite, Pint and Larastan) and passed; a mutation of the AccessRule (AdminOnly to PartnerAllowed) failed exactly the two Partner tests. "Tracer verified end-to-end - expanding".

The tracer deliberately shipped a minimal registry (register, replace without slot check, indicators, results without a catch) so that Task 2 has a real RED; the complete registry from the plan's artifact table lands in Task 2.

## TDD Gate Compliance

`test(03-15)` (`a60ca4e`) precedes `feat(03-15)` (`71dd35b`) for Task 2. No REFACTOR commit was needed.

**RED evidence (semantic assessment), Task 2:** 7 of 19 tests failed, each on the planned assertion for behaviour the minimal registry lacks: `missingSlots()` undefined (2 tests, error on the missing method, the intended API absence), `replace()` with another slot's indicator "Exception [InvalidArgumentException] not thrown", the throwing indicator propagated out of `results()` (registry test) and turned the page into HTTP 500 (page test: "Expected response status code [200] but received 500", the exception text visible in the trace), and an empty registry returned 0 results where 6 Error results are expected. Setup and imports were fine; the targets executed.

**Test fault found at RED and fixed before GREEN:** the colour-badge test first asserted a `fi-color-gray` class, but Filament renders the grey badge without a colour class (grey is the badge default). That was a wrong test assumption, not missing behaviour; the test now asserts exactly one `fi-color-success`, `fi-color-warning` and `fi-color-danger` and the four Czech labels. It is green on its first valid run because the tracer's view already renders `HealthStatus::getColor()`; the same holds for the Prague time test.

**Mutation checks at GREEN** (each fails exactly the matching tests; the file was restored from a copy afterwards):

| Mutation | Failed tests |
|---|---|
| catch narrowed to LogicException | throwing-indicator registry test, throwing-indicator page test |
| slot check in replace() disabled | wrong-slot replace test |
| exception message used as the detail | both throwing-indicator tests |
| unregistered slot reported as Ok | unregistered-slot test |
| missingSlots() returns an empty list | hand-built list test |
| checked-at uses UTC instead of Europe/Prague | Prague time test |
| SystemPage audience PartnerAllowed (done at the tracer) | both Partner 403 tests |

`gsd_run check tdd-red-evidence` does not parse Pest output, so no `RED_EVIDENCE_OK` record exists (same as plans 03-10 and 03-13).

## Files Created/Modified

- `app/Domain/Operations/Health/HealthStatus.php`, `HealthSlot.php`, `HealthResult.php`, `HealthIndicator.php` - the health vocabulary
- `app/Domain/Operations/Health/HealthIndicatorRegistry.php` - slot-checked registry with fail-safe `results()`
- `app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php` - "not available yet" for a slot
- `app/Filament/Pages/SystemPage.php` and `resources/views/filament/pages/system-page.blade.php` - the Admin-only page and its polling view
- `app/Providers/OperationsServiceProvider.php` - registry singleton with six placeholders
- `lang/cs/kokpit.php` (`system.*`), `lang/cs/enums.php` (`health_status.*`, `health_slot.*`) - Czech texts
- `tests/Feature/Operations/HealthRegistryTest.php`, `tests/Feature/Operations/SystemPageTest.php`, `tests/Support/Probes/HealthProbeIndicator.php` - tests and the counting probe

## Decisions Made

- `register()` refuses a second indicator for the same slot (LogicException); `replace()` is the only way to swap. The plan only names `register` and `replace`; the strict reading catches a binding that registers twice. Plan 03-16 should register its three real indicators instead of their placeholders in the binding, or call `replace()`.
- `results()` iterates `HealthSlot::cases()` and reports an unregistered slot as Error, so the page itself shows a gap; the plan's test still catches it earlier through `missingSlots()`.
- The view relies on Filament components and inline styles; no Tailwind utility classes, because there is no theme build in this project.
- The navigation entry sits in the "Správa" group next to Settings (sort 95, after Settings at 90).

## Deviations from Plan

None - plan executed exactly as written. The minimal-registry-first split between Task 1 and Task 2 is an interpretation of the plan's "complete the registry in Task 2" wording, not a deviation.

**Total deviations:** 0.

## Issues Encountered

- `sed` on macOS (BSD) failed on a pattern with escaped backslashes and a Python replacement was used instead; the first Pest run after an edit once ran against a stale DDEV mount (9 tests instead of 19), so a short wait was added before each run after an edit.
- Test assumption about the grey badge class (described above), fixed at RED.

## Known Stubs

Intentional by design (D-13), tracked here and not in the ledger as defects:

| Slot | Indicator | Replaced by |
|---|---|---|
| failed_jobs, oldest_pending_job, scheduler_heartbeat | `PlaceholderIndicator` (`app/Providers/OperationsServiceProvider.php`) | plan 03-16 |
| last_rate_date | `PlaceholderIndicator` | Phase 8 |
| unsent_invoice_emails | `PlaceholderIndicator` | Phase 10 |
| unprocessed_webhooks | `PlaceholderIndicator` | Phase 11 |

The plan's goal (a registry the later work fills) is achieved with them in place.

## Threat Flags

None beyond the plan's register. T-03-38 (Partner sees the page): AdminOnly rule, boot-hook 403, spy-indicator test, Livewire 403 test, route walk. T-03-39 (message disclosure): the detail is the exception class only, tested with a secret-like message. T-03-40 (one indicator breaks the page): per-indicator catch, tested at registry and page level.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Visual check of `/admin/system` (layout, badge colours, dark mode, live 30 s refresh) is left to the phase verification; tests assert text, colour classes and the `wire:poll` attribute only.
- Plan 03-18 lists `resources` in the deploy files and plan 03-19 checks the view is tracked; `resources/views/filament/pages/system-page.blade.php` is the first tracked file under `resources/`.

## Next Phase Readiness

- Plan 03-16 can implement the failed-jobs, oldest-pending and scheduler indicators against `HealthIndicator` and register them in the binding in `OperationsServiceProvider` (skip their placeholders, or use `replace()`); the failed-job alert now links to the real System page route.
- Full gate green at the last code commit: `ddev composer ci` Pest 810 passed (3471 assertions), Pint 239 files, Larastan no errors, licence check 201 packages.

## Self-Check: PASSED

- All 11 created files exist on disk.
- Commits `208ccbe`, `a60ca4e`, `71dd35b` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run: `Audience::AdminOnly`, `wire:poll`, `HealthSlot::cases()`, `HealthIndicatorRegistry::class`, `missingSlots`, `function replace`, `Throwable` all found; RouteWalkTest and QueryEscapeHatchTest pass in the full suite.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
