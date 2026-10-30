---
phase: 04-clients-and-projects
plan: 07
subsystem: database
tags: [eloquent, laravel-actions, money, activitylog, postgres, partner-isolation]

requires:
  - phase: 04-clients-and-projects
    provides: project_billing table, ProjectBilling model, CreateProject Action (plan 04-06)
  - phase: 03-operations
    provides: LogsAllowlistedActivity and #[LoggedAttributes] allowlist, ActivityPresenter labels
provides:
  - EstimateHours exact hours to seconds conversion (integer arithmetic, two decimals, column range)
  - UpdateProject Action (project, tags and billing in one transaction, client immutable, free status switching)
  - ProjectInput shared money, estimate, fixed-price and key-violation rules for both Actions
  - Project::selectable() scope for every project picker
  - ProjectBilling::clientHoldsMoney() for the client currency lock
  - Activity allowlists for Project and ProjectBilling with Czech labels
  - project_billing constraint tests
affects: [04-08 Admin project resource, 04-11 UpdateClient currency lock, phase 5 task pickers, phase 8 work report, phase 10 invoicing]

actuals:
  tokens: 14400
  tasks: 2
  commits: 2
plan_head_before: fde3252fe7252f873302ca0f9370c900a0a1105a
plan_head_after: 46cb90c113c9ee8042b69602e8136347a91b48d2

tech-stack:
  added: []
  patterns:
    - "Create and update Actions share one internal input class (ProjectInput) so a rule exists once; errors stay keyed by the data key"
    - "An absent data key leaves the stored value unchanged, a present empty amount or estimate clears it"
    - "Actions return a refreshed model so database defaults are loaded and the activity log does not record them as changes from null"

key-files:
  created:
    - app/Domain/Projects/EstimateHours.php
    - app/Domain/Projects/Actions/UpdateProject.php
    - app/Domain/Projects/Actions/ProjectInput.php
    - tests/Unit/Projects/EstimateHoursTest.php
    - tests/Feature/Projects/ProjectActionsTest.php
    - tests/Feature/Schema/ProjectBillingTableTest.php
  modified:
    - app/Domain/Projects/Actions/CreateProject.php
    - app/Domain/Projects/Models/Project.php
    - app/Domain/Projects/Models/ProjectBilling.php
    - app/Domain/Audit/LogsAllowlistedActivity.php
    - lang/cs/kokpit.php
    - tests/Arch/ActivityAllowlistTest.php

key-decisions:
  - "The estimate is capped at the integer column range (2147483647 seconds, about 596 523 hours) inside EstimateHours, so an oversize value is a field error instead of a database exception"
  - "UpdateProject treats a null client_id like an absent one; only a different non-null client id is refused"
  - "clientHoldsMoney() counts a stored zero rate or price as money held (any non-null amount)"

patterns-established:
  - "ProjectInput: static money(), estimateSeconds(), requireFixedPrice(), translateKeyViolation() shared by the project Actions"
  - "selectable scope uses a plain EXISTS on clients.deleted_at, never the Client model, so it works for a Partner"

requirements-completed: [PR-03, PR-02, PR-01]

coverage:
  - id: D1
    description: "Estimate typed in hours with at most two decimals converts exactly to whole seconds; invalid, negative, over-precise or oversize values are field errors and empty stores null"
    requirement: PR-03
    verification:
      - kind: unit
        ref: "tests/Unit/Projects/EstimateHoursTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it stores the estimate typed in hours as exact seconds on creation and null when empty"
        status: pass
    human_judgment: false
  - id: D2
    description: "UpdateProject updates project and billing in one transaction with the money rule of CreateProject, refuses a different client, switches status and priority freely and reports a taken key as a field error"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it updates the project and its billing in one transaction"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it refuses a different client and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it refuses a key used by another project, also an archived one, and accepts the own key"
        status: pass
    human_judgment: false
  - id: D3
    description: "Project::selectable() hides archived projects and every project of an archived client; ProjectBilling::clientHoldsMoney() reports money held by any project of a client, archived included"
    requirement: PR-02
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it hides an archived project and every project of an archived client from the selectable scope"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectActionsTest.php#it reports whether a client holds money on any project, archived ones included"
        status: pass
    human_judgment: false
  - id: D4
    description: "Project and billing changes are logged through allowlists; description and internal note never reach the log; every project_billing invariant is enforced by the database"
    requirement: PR-03
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/ProjectBillingTableTest.php"
        status: pass
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php"
        status: pass
    human_judgment: false

duration: 11 min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 07: UpdateProject, EstimateHours and Project Audit Summary

**UpdateProject with an immutable client, integer-exact hours-to-seconds estimates, one `selectable` scope for pickers, `clientHoldsMoney()`, and allowlisted activity logging for projects and billing with every project_billing constraint proven in the database**

## Performance

- **Duration:** 11 min
- **Started:** 2026-10-08T13:19:00Z
- **Completed:** 2026-10-08T13:30:16Z
- **Tasks:** 2
- **Files modified:** 12

## Accomplishments

- `EstimateHours::toSeconds()` parses at most two decimals with a regular expression and combines `hours * 3600 + hundredths * 36` in integers (1,5 h is 5400 s, 0,01 h is 36 s); more decimals, a negative sign, grouping, text, empty input and values beyond the integer column are refused. `fromSeconds()` returns the Czech notation.
- `UpdateProject` changes project, tags and billing in one transaction, refuses a different `client_id`, lets status and priority move between any values, requires a fixed price for the fixed-price type, and turns a taken key (also an archived project's) into a `key` field error. Money, estimate, fixed-price and key rules live once in `ProjectInput` and are shared with `CreateProject`, which now stores `estimate_seconds`.
- `Project::selectable()` excludes archived projects and every project of an archived client with a plain EXISTS on `clients`, so it also works for a Partner; `ProjectBilling::clientHoldsMoney()` is ready for the client-currency lock of plan 04-11.
- `Project` and `ProjectBilling` use `LogsAllowlistedActivity`; the arch test lists both; Czech subject and attribute labels added; description and internal note are never logged.
- `ProjectBillingTableTest` covers 23505, 23514, 23503 and 23001 for the billing table and the allowlist behaviour (including no rows for a note-only change and none visible to a Partner).

## Task Commits

1. **Task 1: Tracer - UpdateProject, EstimateHours, selectable scope** - `d58e4da` (feat)
2. **Task 2: Activity allowlists and project_billing constraint tests** - `46cb90c` (feat)

**Plan metadata:** committed separately as the docs commit for this plan.

## Files Created/Modified

- `app/Domain/Projects/EstimateHours.php` - exact hours to seconds conversion and back
- `app/Domain/Projects/Actions/UpdateProject.php` - project plus billing update
- `app/Domain/Projects/Actions/ProjectInput.php` - input rules shared by both Actions
- `app/Domain/Projects/Actions/CreateProject.php` - uses `ProjectInput`, stores the estimate, returns a refreshed model
- `app/Domain/Projects/Models/Project.php` - `scopeSelectable()`, activity allowlist
- `app/Domain/Projects/Models/ProjectBilling.php` - `clientHoldsMoney()`, activity allowlist
- `app/Domain/Audit/LogsAllowlistedActivity.php` - removed the now-unneeded unused-trait ignore, ignore for the list normalisation
- `lang/cs/kokpit.php` - error strings, activity subjects and attribute labels
- `tests/Unit/Projects/EstimateHoursTest.php`, `tests/Feature/Projects/ProjectActionsTest.php`, `tests/Feature/Schema/ProjectBillingTableTest.php`, `tests/Arch/ActivityAllowlistTest.php` - tests

## Decisions Made

- The estimate is capped at the integer column range (2147483647 s) in `EstimateHours` so an oversize value is a field error, not a database exception.
- A null `client_id` in the update data counts as absent; only a different non-null id is refused.
- A stored zero rate or price counts as money held for `clientHoldsMoney()`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Estimate beyond the integer column would reach the database**
- **Found during:** Task 1 (EstimateHours)
- **Issue:** `estimate_seconds` is a 32-bit integer; hours beyond about 596 523 would pass the two-decimal rule and fail as a raw database error.
- **Fix:** `EstimateHours::MAX_SECONDS` rejects larger values as `InvalidArgumentException`, which the Actions map to the `estimate_hours` field error.
- **Files modified:** `app/Domain/Projects/EstimateHours.php`, `tests/Unit/Projects/EstimateHoursTest.php`
- **Verification:** boundary datasets `596523,23` accepted and `596523,24` refused; `700000` refused on creation
- **Committed in:** `d58e4da`

**2. [Rule 1 - Bug] Created project logged status and priority as changed from null**
- **Found during:** Task 2 (activity behaviour test)
- **Issue:** `CreateProject` returned a model without the database defaults of `status` and `priority`; the activity package compares the in-memory old values with the fresh row, so the first update wrote a false change (and a note-only update wrote a row).
- **Fix:** `CreateProject` returns `$project->refresh()`.
- **Files modified:** `app/Domain/Projects/Actions/CreateProject.php`, `tests/Feature/Projects/ProjectActionsTest.php`
- **Verification:** `it returns a created project with the database defaults ...` and the allowlist behaviour tests
- **Committed in:** `46cb90c`

**3. [Rule 3 - Blocking] PHPStan flagged the trait once it was used by real models**
- **Found during:** Task 2
- **Issue:** the old `trait.unused` ignore was no longer needed and `array_values` on a docblock-typed list reported `arrayValues.list` in the context of the new models.
- **Fix:** removed the unused-trait ignore, added a narrow ignore with a reason for the list normalisation.
- **Files modified:** `app/Domain/Audit/LogsAllowlistedActivity.php`
- **Verification:** PHPStan clean
- **Committed in:** `46cb90c`

---

**Total deviations:** 3 auto-fixed (2 bug, 1 blocking)
**Impact on plan:** All necessary for correctness; no scope creep.

## Issues Encountered

- The DDEV file sync lags a few seconds behind host writes: a test file or edit made in the same command as a Pest run was not yet visible (one false failure, one "file not found"). Re-running after a short wait fixed it.
- `scripts/check-sensitive.sh` flagged an 8-digit amount literal in a test as a company ID; written as `120000 * 100`.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None - no new endpoint, auth path or file access; T-04-13, T-04-14 and T-04-54 are mitigated as planned.

## Next Phase Readiness

Ready for 04-08 (Admin project resource as a thin adapter over `CreateProject` and `UpdateProject`, mapping error keys to state paths) and 04-11 (`ProjectBilling::clientHoldsMoney()`). Full suite green (1211 tests), Pint and PHPStan clean.

## Self-Check: PASSED

- Created files exist on disk (EstimateHours, UpdateProject, ProjectInput, three test files).
- Commits `d58e4da` and `46cb90c` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run and passing; `ddev exec vendor/bin/pest` (1211 passed), `pint --test` and PHPStan clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
