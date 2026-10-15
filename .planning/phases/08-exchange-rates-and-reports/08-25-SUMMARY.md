---
phase: 08-exchange-rates-and-reports
plan: 25
subsystem: clients
tags: [clients, currency-lock, task-billing, gap-closure, cr-01]

requires:
  - phase: 08-exchange-rates-and-reports
    provides: "08-23 per-currency report rows and 08-24 per-currency dashboard widget (consumers render legacy mixed data safely)"
provides:
  - "TaskBilling::clientHoldsMoney(clientId): whether any task of the client, archived ones and tasks of archived projects included, holds a rate or a fixed price"
  - "UpdateClient refuses a currency change (field error on currency) while project or task money exists"
  - "The Czech lock message names projects and tasks"
affects: [08-26 report column redesign]

actuals:
  tokens: 3500
  tasks: 2
  commits: 3

plan_head_before: e197807036981549eee6fc0bd47866aa5ba98cd0
plan_head_after: d281d1b0b13a41025c6f5e322c7538f2e983081c
commits: 3

tech-stack:
  added: []
  patterns:
    - "Cross-table currency rule stays in the domain Action; the lock query is a raw-table whereExists subquery (no soft-delete scope), so archived rows count"

key-files:
  created: []
  modified:
    - app/Domain/Tasks/Models/TaskBilling.php
    - app/Domain/Clients/Actions/UpdateClient.php
    - lang/cs/kokpit.php
    - tests/Feature/Clients/ClientRulesTest.php

key-decisions:
  - "No migration and no trigger: the rule stays in UpdateClient, as the project_billing and task_billing migrations record"
  - "The way out of the lock is clearing the task rate through UpdateTask, not guessing which task holds money"

requirements-completed: [RE-02]

coverage:
  - id: D1
    description: "A client's currency cannot change while any of its tasks, archived tasks and tasks of archived projects included, holds an hourly rate or a fixed price (a stored zero counts); the action and the edit form both show the error on currency and keep the stored currency"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientRulesTest.php#currency lock"
        status: pass
    human_judgment: false
  - id: D2
    description: "An estimate, an internal note or a billing type alone, and money on another client's task, do not lock; clearing the task rate unlocks the change and moves currency and rate together"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientRulesTest.php#unlocks the change once the task rate is cleared"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Czech lock message names projects and tasks"
    requirement: "RE-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientRulesTest.php#names projects and tasks in the refusal message"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-15
status: complete
---

# Phase 8 Plan 25: Client currency lock over task money Summary

**`UpdateClient` now refuses a currency change while any project or task of the client, archived ones included, holds an hourly rate or fixed price, through a new `TaskBilling::clientHoldsMoney()` mirroring the project check over the raw tasks table.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 of 2
- **Files modified:** 4

## Accomplishments

- Closed the root cause of CR-01 (08-VERIFICATION.md gap 1): task money in `task_billing` is stored in the client currency, so a currency change can no longer leave it behind in the old currency.
- The lock covers archived tasks, tasks of archived projects, fixed prices and a stored zero; an estimate, a note or a billing type alone and another client's task money do not lock.
- The Admin's way out is to clear the task rate with `UpdateTask` (`hourly_rate` null); after that currency and rate change together as before.
- The edit form shows the error under `data.currency` and keeps the stored currency.
- The Czech message now reads "Měnu klienta nelze změnit, dokud některý jeho projekt nebo úkol, i archivovaný, má hodinovou sazbu nebo pevnou cenu."

## Task Commits

1. **Task 1 (tracer): lock the client currency while a task holds a rate or a fixed price** - `77bb104` (fix)
2. **Task 2: pin archived, fixed-price, zero, non-money and unlock cases; name tasks in the message** - `c30e893` (test)
3. **Import order fix of the Task 2 test file (Pint ordered_imports)** - `d281d1b` (style)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Clients/ClientRulesTest.php tests/Feature/Projects/ProjectActionsTest.php`: 58 passed
- `ddev exec vendor/bin/pest tests/Feature/Clients/ClientRulesTest.php tests/Feature/Localisation`: 55 passed
- `ddev exec vendor/bin/pest` (full suite): 3889 passed, 3 skipped (pre-existing skips)
- `ddev exec vendor/bin/pint --test`: clean (after one `ordered_imports` fix in the test file); `ddev exec vendor/bin/phpstan analyse`: no errors
- `scripts/check-sensitive.sh`: clean on both commits; lefthook (sensitive-content, gitleaks) passed
- RED was observed first: both Task 1 cases (action error and edit form error) failed before the lock was extended.
- Acceptance criteria hold: `public static function clientHoldsMoney(string $clientId): bool` present, `TaskBilling::clientHoldsMoney($client->id)` present in `UpdateClient`, 0 `DB::table(` in `TaskBilling.php`, the new message text present in `lang/cs/kokpit.php`, `TaskBilling::clientHoldsMoney` used in `ClientRulesTest`. The grep acceptance lines need `grep -F` on macOS, where basic-regex `$` before a letter acts as an anchor.
- Tracer gate (auto and end-of-phase mode): the tracer verify was re-run end to end before expansion and passed.

## Deviations from Plan

### Sequencing note

The Task 2 behaviour cases (archived task, archived project, fixed price, zero, non-money rows, other client, unlock, direct system-run checks) passed on first run against the Task 1 production code, because `TaskBilling::clientHoldsMoney()` already satisfied them. Only the message test flipped, with the translation edit. Task 2's commit holds the tests and the translation, with no further production change. No behaviour differs from the plan.

**Total deviations:** 0 auto-fixed, 1 sequencing note. **Impact:** none on scope or production behaviour.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or trust-boundary surface; `TaskBilling` stays Admin-only through `DeniesPartners`, and the lock query is read inside the Admin's `UpdateClient` call. T-08-77 and T-08-78 are mitigated and covered by tests; T-08-79 (check-then-act window) is accepted as planned; T-08-SC: nothing installed.

## Issues Encountered

None. Out of scope and untouched: report column redesign (plan 08-26).

## Next Phase Readiness

Ready for 08-26. Existing mixed data written before this lock is rendered per currency by 08-23 and 08-24 and heals when the Admin clears or re-enters the task rate.

## Self-Check: PASSED

- FOUND: app/Domain/Tasks/Models/TaskBilling.php, app/Domain/Clients/Actions/UpdateClient.php, lang/cs/kokpit.php, tests/Feature/Clients/ClientRulesTest.php
- FOUND commits: 77bb104, c30e893, d281d1b
