---
phase: 05-tasks-and-kanban
plan: 07
subsystem: tasks
tags: [filament, billing, money, admin-only, postgres-check, partner-isolation]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model, UpdateTask, TaskResource edit form, canary registry (plans 05-01 to 05-06)
  - phase: 04-projects
    provides: ProjectInput money, estimate and fixed-price rules, EstimateHours, the project_billing pattern
provides:
  - task_billing table (Admin-only 1:1, Money column pairs, CHECKs, lazy row)
  - TaskBilling model (DeniesPartners, AdminOnlyPolicy, morph alias, canary fixture)
  - TaskBillingType enum with four values (inherit, hourly, fixed_price, non_billable) and Czech labels
  - Task::billing() HasOne relation
  - Billing keys of UpdateTask (billing_type, hourly_rate, fixed_price, estimate_hours, internal_note)
  - "Fakturace úkolu" section on the Admin task edit form
affects: [05-08 read-time resolution and billing audit, 05-12 Partner task builders (must not include billing), Phase 6 time entries, Phase 10 invoicing]

actuals:
  tokens: 11000
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "A task has a task_billing row only while it overrides something: UpdateTask creates it on the first override and deletes it when type is inherit and every value, the note included, is empty"
    - "Billing keys that the data does not name keep the stored value; a null billing type is treated like a null priority (unchanged), not as an error"
    - "UpdateTask validates the resulting billing (fixed price requires a price) before the transaction from the stored row, and resolves it again from the locked row inside it"

key-files:
  created:
    - database/migrations/2026_10_10_000400_create_task_billing_table.php
    - app/Domain/Tasks/Models/TaskBilling.php
    - app/Domain/Tasks/Enums/TaskBillingType.php
    - tests/Feature/Tasks/TaskBillingTest.php
  modified:
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Tasks/Actions/UpdateTask.php
    - app/Filament/Resources/TaskResource.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php

key-decisions:
  - "Task billing lives in its own Admin-only task_billing table (D-13), so no column of the Partner-readable tasks table changes"
  - "task_billing carries a nullable internal_note like project_billing; the canary harness needs a text column to prove the table non-vacuously"
  - "A row exists only while the task overrides something (D-14); a fixed price or rate with type inherit still counts as an override"
  - "A null billing_type in the data leaves the stored type; anything that is not a known text value is a field error on billing_type"

patterns-established:
  - "Pattern: Pest helpers of this plan carry the taskBilling prefix"
  - "Pattern: a client factory for a non-CZK currency also sets the hourly_rate Money in that currency, otherwise clients_hourly_rate_currency_check rejects the row"

requirements-completed: []

coverage:
  - id: D1
    description: "The Admin sets billing type, fixed price, hourly rate override and estimate in hours on the task edit page; they are stored in task_billing and the tasks row is unchanged"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#stores a fixed price set on the edit page in task_billing and leaves the tasks row alone"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#shows the stored billing on the edit page again and removes the row when everything is back to inherit"
        status: pass
    human_judgment: false
  - id: D2
    description: "A task has a billing row only while it overrides something; the four billing types exist only on tasks"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#keeps the row while any override is left, the note included"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#creates an override row for non-billable without any value"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#lets the type and the rate inherit independently: a rate with type inherit is an override"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner reads zero task_billing rows and a null billing relation, while the Admin reads the canary of both clients"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#shows a Partner no billing row, not even on a task of the own visible project"
        status: pass
      - kind: unit
        ref: "tests/Isolation/CanaryRegistryTest.php (every PartnerIsolated model has a fixture and a Partner reads none of its canary rows)"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#refuses a Partner that calls UpdateTask with billing keys"
        status: pass
    human_judgment: false
  - id: D4
    description: "Money is exact to the minor unit in the client's currency: too many decimals, a grouping character or a minus sign is a field error; zero price and zero estimate are stored"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#refuses an amount with too many decimals, a grouping character or a minus sign on the field, and stores nothing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#rounds nothing: the decimals a currency allows are kept to the minor unit"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#stores a fixed price of 0 and an estimate of 0 hours, and converts hours to seconds"
        status: pass
    human_judgment: false
  - id: D5
    description: "An estimate above the integer range, a fixed price type without a price and an unknown billing type are field errors; the database refuses malformed rows"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#refuses an estimate above the integer column range or with a bad shape on the estimate field"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#requires a price for billing type fixed price, as a field error and as a database check"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#refuses an unknown billing type on the billing type field"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingTest.php#refuses a malformed billing row in the database"
        status: pass
    human_judgment: false
  - id: D6
    description: "The billing section, its Czech wording and the currency suffixes read well to the Admin"
    requirement: TA-06
    verification: []
    human_judgment: true
    rationale: "Tests cover behaviour and field wiring, not the look of the section or the owner's preferred Czech phrasing"

duration: 12 min
completed: 2026-10-08
status: complete
plan_head_before: fe5debc9a5227f953caaa5d18bb03fb968f89607
plan_head_after: 5e8b50f470f626b54dc92a8c9db8b3a353887bbf
---

# Phase 5 Plan 07: Task Billing Summary

**Per-task billing type (inherit, hourly, fixed price, non-billable), fixed price, hourly rate override and estimate for the Admin, stored in an Admin-only 1:1 `task_billing` table with a lazy row, exact Money input and a Partner-closed model.**

## Performance

- **Duration:** 12 min
- **Started:** 2026-10-08T21:38:00Z
- **Completed:** 2026-10-08T21:50:46Z
- **Tasks:** 2 (tracer plus one TDD task)
- **Files:** 14 in the code commits (4 created, 10 modified)

## Accomplishments

- `task_billing`: uuid pk with the `uuidv7()` default, `task_id` unique and FK RESTRICT, `billing_type` default `inherit`, Money column pairs for the hourly rate and fixed price, `estimate_seconds`, nullable `internal_note`, and the planned CHECKs (type values, pair, sign, currency shape, currency match, estimate sign, fixed price required).
- `TaskBilling` is `final`, `PartnerIsolated` with `DeniesPartners`, registered with `AdminOnlyPolicy`, morph alias `task_billing`, and has a canary fixture (the canary is the internal note, with an hourly rate in the client currency). `Task::billing()` is a `HasOne`.
- `TaskBillingType` has the four values with Czech labels in `lang/cs/enums.php`; the project `BillingType` is unchanged.
- `UpdateTask` converts the billing keys before the transaction with `ProjectInput::money` (client currency, never rounded), `ProjectInput::estimateSeconds` and `ProjectInput::requireFixedPrice`, then creates, updates or deletes the row in the same transaction as the task save (D-14).
- The task edit form has a "Fakturace úkolu" section (type select, rate and price with a decimal comma and the client's currency as suffix, estimate in hours, internal note). `TaskResource::fillData()` fills it from the row and `actionData()` passes it to the Action.

## Task Commits

1. **Task 1 (tracer): the Admin sets a fixed price on a task; a Partner reads nothing** - `c651870` (feat)
2. **Task 2 (TDD): billing input rules**
   - RED `32bbf5b` (test)
   - GREEN `5e8b50f` (feat)

**Plan metadata:** the docs commit that carries this file.

## TDD Gate Compliance

- **Tracer (Task 1):** executed and committed like an auto task. The tracer verify (targeted Pest 42 passed, Pint, PHPStan) passed, and `Partner reads nothing` is guarded by the canary registry (removing `DeniesPartners` makes the model fail to load). Auto mode re-ran the verify end to end before expansion.
- **Task 2 RED** (`32bbf5b`): 2 of the 26 test cases of the file failed. Semantic assessment: (a) "a null billing type leaves the stored type" failed with the `billing_type_invalid` field error where the planned behavior is "unchanged"; (b) "a non-text billing type is a field error" failed with an `Array to string conversion` ErrorException at the `(string)` cast instead of the planned field error. Both are real defects of the tracer's Action, not setup, syntax or fixture faults. The other 24 cases passed in RED because the tracer already implemented the money, estimate, fixed price and CHECK rules through `ProjectInput`; they are the planned regression guard of the behavior list. A first RED run also failed on a test setup fault (a non-CZK client factory without a matching rate currency), which was fixed in the test before the commit. The Pest output is not a format the `tdd-red-evidence` classifier accepts, so no machine record was produced; the evidence is the observed failures above.
- **Task 2 GREEN** (`5e8b50f`): all 26 cases pass. Full suite: 1756 passed, 13180 assertions. Pint and PHPStan clean.
- No REFACTOR commit was needed.

## Decisions Made

- `task_billing` carries `internal_note`, as the plan states, so the registry test proves the table non-vacuously; it is never activity-logged (plan 05-08 adds the audit allowlist without it).
- A null `billing_type` leaves the stored type (like a null priority); an empty text or a non-text value is a field error on `billing_type`.
- A fixed price or a rate with type inherit is a legitimate override and keeps the row.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A null or non-text billing type broke the Action**
- **Found during:** Task 2 (RED tests)
- **Issue:** The tracer cast `$data['billing_type']` to string: null became an invalid type error although the data type allows null and means "unchanged", and an array raised a PHP ErrorException instead of a field error.
- **Fix:** null leaves the stored type; a non-null value must be a text that is a known case, otherwise the field error on `billing_type`. The PHPDoc key type is `mixed` because the value comes from form state.
- **Files modified:** app/Domain/Tasks/Actions/UpdateTask.php
- **Verification:** `TaskBillingTest` (26 cases), full suite green
- **Committed in:** 5e8b50f

**2. [Rule 3 - Blocking] Czech wording for the new form fields lives in `lang/cs/kokpit.php`, which is not in files_modified**
- **Found during:** Task 1
- **Issue:** The billing section needs labels, hints, a section title and one error message; the plan lists only `lang/cs/enums.php`.
- **Fix:** added the keys under `kokpit.tasks` (`sections.billing`, `fields.*`, `hints.*`, `errors.billing_type_invalid`). The amount and estimate error messages reuse `kokpit.projects.errors.*` through `ProjectInput`.
- **Files modified:** lang/cs/kokpit.php
- **Committed in:** c651870

**Plan interpretation (not deviations):** the tracer text uses `12 500,50` for the fixed price; a grouping space is a field error by the plan's own precision rule, so the tracer test enters `12500,50` (stored as 1250050 minor units CZK). The plan's artifact table says the Action converts with the project's client; it reads the client through the task's project, an archived project or client included, like `UpdateProject`.

**Total deviations:** 2 auto-fixed (1 Rule 1, 1 Rule 3). **Impact:** none on scope.

## Issues Encountered

- Right after a file write a ddev run twice read a stale copy (PHPStan reported an already fixed line); re-running after a short wait gave the correct result.
- The `JPY` test client needs the factory `hourly_rate` Money in the same currency, otherwise the clients table CHECK rejects the row; the test helper now sets it.

## Known Stubs

None.

## Threat Flags

None. T-05-15 (billing terms reaching a Partner) is mitigated as planned: separate table, `DeniesPartners`, `AdminOnlyPolicy`, canary fixture, a test that a Partner reads zero rows and a null `$task->billing` for an own visible task, and that a Partner calling `UpdateTask` with billing keys is refused. T-05-16 is mitigated by `ProjectInput::money` (client currency, no rounding) and the database CHECKs, both covered by tests. The Partner builders are pinned in plan 05-12.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for 05-08: the billing audit allowlist (without the note) and the read-time resolution of the effective billing can build on `TaskBilling` and `Task::billing()`. Requirement TA-06 is shared with plans 05-08 and 05-17, which have no SUMMARY yet, so it was not marked complete here (shared-ID gate).

## Self-Check: PASSED

- Created files exist: the migration, `TaskBilling.php`, `TaskBillingType.php`, `TaskBillingTest.php`.
- Commits `c651870`, `32bbf5b`, `5e8b50f` are ancestors of HEAD.
- Acceptance greps of both tasks pass; the verify commands (targeted Pest, full Pest 1756 passed, Pint, PHPStan) pass; `scripts/check-sensitive.sh` clean on every commit.
