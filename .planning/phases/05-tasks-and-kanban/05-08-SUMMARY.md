---
phase: 05-tasks-and-kanban
plan: 08
subsystem: tasks
tags: [billing, resolver, inheritance, activity-log, postgres-check, admin-only]

requires:
  - phase: 05-tasks-and-kanban
    provides: TaskBilling model, TaskBillingType, Task::billing(), UpdateTask billing keys (plan 05-07)
  - phase: 04-projects
    provides: ProjectBilling, Project::billing(), CreateProject, the allowlist pattern of project_billing
provides:
  - TaskBillingResolver (read-time billing resolution task, parent task, project, client)
  - EffectiveBilling value object with a source level per value, and the BillingSource enum with Czech labels
  - Admin-only "Platna fakturace" section on the task page
  - TaskBilling activity allowlist (type, amounts, currencies, estimate; never the internal note)
  - Raw-SQL proofs of every task_billing constraint
affects: [05-12 Partner task builders (must not reference the resolver), phase-06 time entries (billable flag and rate), phase-10 invoice items]

actuals:
  tokens: 11000
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Read-time inheritance: first level holding a non-null value wins per field; a task row of type inherit defers only its type"
    - "Defence in depth: the resolver itself refuses a call that is neither the Admin nor a system run, on top of the Admin check of the page"
    - "Pest helpers of this plan carry the taskBillingResolver and taskBillingSchema prefixes"

key-files:
  created:
    - app/Domain/Tasks/Billing/BillingSource.php
    - app/Domain/Tasks/Billing/EffectiveBilling.php
    - app/Domain/Tasks/Billing/TaskBillingResolver.php
    - tests/Feature/Tasks/TaskBillingResolverTest.php
    - tests/Feature/Schema/TaskBillingTableTest.php
  modified:
    - app/Domain/Tasks/Models/TaskBilling.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - tests/Arch/ActivityAllowlistTest.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php

key-decisions:
  - "The resolver throws AuthorizationException unless the Admin or a system run asks, because the visible client row of a Partner would otherwise leak the client rate (T-05-17)"
  - "A project without a billing row is a LogicException, not a guessed hourly type: a wrong silent type would mis-bill"
  - "Zero is a value at every level (rate 0, price 0, estimate 0 stop the chain); only null defers"
  - "Parents, projects and clients are loaded without the soft-delete scope, so a task of an archived project still resolves"
  - "The estimate inherits literally (research A7); Phase 6 decides whether to compare actual time against an inherited estimate"

patterns-established:
  - "Pattern: the effective billing section is appended to the resource infolist inside ViewTask::infolist() and guarded by PartnerContext::isAdmin() before anything is resolved"

requirements-completed: []

coverage:
  - id: D1
    description: "The Admin opens a subtask without overrides and sees its effective billing type, rate, fixed price and estimate with the level each value comes from"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingResolverTest.php#shows a subtask without overrides the project rate on its page, marked as inherited from the project"
        status: pass
    human_judgment: false
  - id: D2
    description: "The resolver picks per field the task, parent task, project or client value; inherit and a missing row defer; non-billable beats the project and is inherited by subtasks; the estimate inherits literally"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingResolverTest.php (describe hourly rate, billing type, estimate: 14 cases)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The effective billing is never computed for a Partner or a guest, and a Partner cannot open the Admin task page"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingResolverTest.php#resolves nothing for a Partner, so the rate of the visible client row cannot leak"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingResolverTest.php#shows a Partner no effective billing on the Admin task page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskBillingResolverTest.php#refuses a call without an Admin and outside a system run"
        status: pass
    human_judgment: false
  - id: D4
    description: "The database refuses every malformed task_billing row with its SQLSTATE (unknown type, half pairs, negatives, bad currency, currency mismatch, fixed price without price, second row, unknown task, hard delete)"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/TaskBillingTableTest.php (describe constraints: 23514, 23505, 23503, 23001)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Billing changes are written to the task billing activity log with old and new minor amounts; the internal note is never logged; a Partner reads no row"
    requirement: TA-06
    verification:
      - kind: unit
        ref: "tests/Feature/Schema/TaskBillingTableTest.php (describe activity allowlist, 3 cases)"
        status: pass
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php#lists the application models that log activity explicitly"
        status: pass
    human_judgment: false
  - id: D6
    description: "The effective billing section reads well in Czech and sits sensibly on the task page"
    requirement: TA-06
    verification: []
    human_judgment: true
    rationale: "Tests assert the values and the source labels, not the look of the section or the owner's preferred Czech wording"

duration: 25 min
completed: 2026-10-08
status: complete
plan_head_before: b7765f0521ea5a00c43854ce3a760c10f407c936
plan_head_after: 3e02a0543db1ea0b23bae4bea5698cae8ad7291c
---

# Phase 5 Plan 08: Effective Task Billing Summary

**A read-time `TaskBillingResolver` that resolves a task's billing type, hourly rate, fixed price and estimate through task, parent task, project and client, an Admin-only task page section that shows each value with its source, raw-SQL proofs of every `task_billing` constraint, and an activity allowlist for billing changes that never logs the internal note.**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-08T22:05Z
- **Tasks:** 3 (tracer, TDD matrix, constraint proofs and audit)
- **Files:** 10 in the code commits (5 created, 5 modified)

## Accomplishments

- `TaskBillingResolver::resolve(Task): EffectiveBilling` resolves each field independently: the first level with a non-null value wins, a task row of type `inherit` defers only its type, a missing row defers everything, the project always supplies hourly or fixed price as the type, and only the hourly rate reaches the client. Nothing is copied.
- `EffectiveBilling` is a `final readonly` value object with `type`, `hourlyRate`, `fixedPrice`, `estimateSeconds`, a `BillingSource` per value and `isBillable()`; `BillingSource` has the four cases with Czech labels (`enums.billing_source`). Phase 6 and Phase 10 call the resolver instead of repeating the order.
- `ViewTask` appends an Admin-only "Platna fakturace" section (`kokpit.tasks.billing.effective.*`) to the resource infolist; each line shows the value and "zdroj: Projekt" (or Tento ukol, Nadrazeny ukol, Klient), with "Nezadano" where no level holds a value.
- `TaskBilling` uses `LogsAllowlistedActivity` with `#[LoggedAttributes]` (task id, type, both amounts and currencies, estimate; no `internal_note`), is listed in `ActivityAllowlistTest`, and has Czech subject and attribute labels.
- `TaskBillingTableTest` proves 23514 for the type list, both halves of each pair, negative amounts and estimate, currency format, currency mismatch and fixed price without a price; 23505 for a second row; 23503 for an unknown task; 23001 for a hard delete of a task with billing; plus the allowed rows (inherit with nothing, non-billable, zeros, same currency).

## Task Commits

1. **Task 1 (tracer): a subtask without overrides shows the project rate with its source** - `5386f2f` (feat)
2. **Task 2 (TDD): resolution matrix** - `97e9c68` (test; no production change needed, see TDD Gate Compliance)
3. **Task 3: constraint proofs and billing audit trail** - `3e02a05` (feat)

**Plan metadata:** the docs commit that carries this file.

## TDD Gate Compliance

- **Tracer (Task 1):** committed like an auto task. Auto mode re-ran the tracer verify (targeted Pest 5 passed, Pint, PHPStan) end to end before expansion.
- **Task 2 RED/GREEN:** the tracer implemented the full resolver, so the 21-case matrix written for Task 2 was green on the first run except one case whose own test setup was wrong (a guest cannot read the task through the fail-closed scope, so the case loaded the task after the logout; fixed in the test, not a production defect). There was therefore no failing production behavior to drive a GREEN commit, and there is no `feat(05-08)` commit for Task 2; the RED-first discipline could not be shown by a failing run. Instead the matrix was mutation-checked against the resolver: dropping the parent rate level, ignoring the parent type, replacing the null test by a truthiness test, removing the Admin guard and skipping the client level each failed at least one case (1, 1, 1, 2 and 1 cases), and each mutation was undone by re-editing. The Pest output is not a format `tdd-red-evidence` accepts, so no machine record exists. This mirrors the situation documented in 05-07.
- No REFACTOR commit was needed.

## Decisions Made

- The resolver refuses a Partner or a guest outside a system run (AuthorizationException). The plan only relied on `TaskBilling` and `ProjectBilling` denying Partners, but `Client` is visible to its own Partner and would hand over the client rate (T-05-17).
- A missing project billing row is a `LogicException`, not a silent default; `CreateProject` always writes the row, and a wrong guessed type would mis-bill.
- Zero stays a value at every level; only null defers.
- Archived parents, projects and clients are loaded without the soft-delete scope, so the billing of a task of an archived project still resolves.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] The resolver trusted the models to deny Partners**
- **Found during:** Task 1
- **Issue:** A Partner call would resolve nothing from `TaskBilling` and `ProjectBilling`, but the `Client` row of the Partner's own client is readable and carries the hourly rate, so the call would return a rate to a Partner.
- **Fix:** `TaskBillingResolver` refuses unless `PartnerContext::isAdmin()` or `isSystem()`; tests for a Partner, a guest and a system run.
- **Files modified:** app/Domain/Tasks/Billing/TaskBillingResolver.php, tests/Feature/Tasks/TaskBillingResolverTest.php
- **Verification:** `TaskBillingResolverTest` (21 cases); removing the guard fails 2 cases
- **Committed in:** 5386f2f, 97e9c68

**2. [Plan nuance] Czech wording lives partly in files beyond the plan's list**
- **Found during:** Task 1 and Task 3
- **Issue:** None blocking: `lang/cs/enums.php` (source labels) and `lang/cs/kokpit.php` (section texts, activity subject and attribute labels) are both in `files_modified`. The activity subject `task_billing` was added with the tracer commit instead of Task 3, as one localisation edit.
- **Committed in:** 5386f2f (subject), 3e02a05 (attribute labels)

**Total deviations:** 1 auto-fixed (Rule 2), 1 plan nuance. **Impact:** security hardening of the resolver only; no scope change.

## Issues Encountered

- A ddev Pest run right after writing a file once reported "test file not found" (bind-mount timing); re-running after a short wait worked.
- PHPStan flagged `?string` return types on the two always-non-null type closures in `ViewTask`; narrowed.

## Known Stubs

None.

## Threat Flags

None. T-05-17 (effective rate shown to a Partner) is mitigated by the Admin check on the section, the Admin-only `TaskResource`, and the resolver's own refusal of a non-Admin non-system caller, all covered by tests; plan 05-12 still pins that no Partner builder references the resolver. T-05-18 (untraced billing changes) is mitigated by the allowlist without the note, pinned by `ActivityAllowlistTest` and the activity cases of `TaskBillingTableTest`.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for 05-09. Requirement TA-06 is shared with plan 05-17, which has no SUMMARY yet, so it was not marked complete here (shared-ID gate). The estimate inherits literally (research A7); Phase 6 still has to decide whether to compare tracked time against an inherited estimate.

## Self-Check: PASSED

- Created files exist on disk: the three resolver files, `TaskBillingResolverTest.php`, `TaskBillingTableTest.php`.
- Commits `5386f2f`, `97e9c68`, `3e02a05` are ancestors of HEAD; `commits: 3` measured with `git rev-list --count` from the plan ledger.
- Acceptance greps of all three tasks pass; full Pest suite 1801 passed (13272 assertions), Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.
