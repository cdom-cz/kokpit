---
phase: 04-clients-and-projects
plan: 06
subsystem: database
tags: [postgres, eloquent, money, partner-isolation, laravel-actions, tags]

requires:
  - phase: 04-clients-and-projects
    provides: Client and Project models, Partner scope, TagType, access primitives (plans 04-01 to 04-05)
  - phase: 02-platform-foundation
    provides: Money value object and MoneyCast, AdminOnlyPolicy, DeniesPartners, canary harness
provides:
  - project_billing Admin-only 1:1 table (billing type, hourly rate, fixed price, estimate seconds, internal note)
  - ProjectBilling model closed to Partners and the BillingType enum
  - Project::billing() HasOne relation
  - CreateProject domain Action (project, tags and billing in one transaction, with the client, amount and key guards)
  - ProjectBilling canary fixture and the project_billing morph alias
affects: [04-07 UpdateProject and EstimateHours, 04-08 Admin project resource, 04-11 client currency lock, phase 8 work report, phase 10 invoicing]

actuals:
  tokens: 8000
  tasks: 2
  commits: 3
plan_head_before: 652225fd3ef0d4d5336cdf2d1f2c97af628a227d
plan_head_after: 0d7e1233ef3d1f770da75a021d455a35f69796a1

tech-stack:
  added: []
  patterns:
    - "Domain Action as a final class with handle(); validation errors keyed by the data key, the Filament adapter maps them to state paths"
    - "Unique violation translated outside the transaction so the savepoint has already rolled the insert back"
    - "Amount parsing never rounds: Money::fromMajor in the client currency, negatives and overflow refused as field errors"

key-files:
  created:
    - database/migrations/2026_10_09_000400_create_project_billing_table.php
    - app/Domain/Projects/Models/ProjectBilling.php
    - app/Domain/Projects/Enums/BillingType.php
    - app/Domain/Projects/Actions/CreateProject.php
    - tests/Feature/Projects/ProjectBillingTest.php
  modified:
    - app/Domain/Projects/Models/Project.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php

key-decisions:
  - "Negative amounts are refused by CreateProject itself (Money::fromMajor accepts a minus sign), so the database CHECK is a second line of defence, not the user-facing error"
  - "A fixed-price project without a price is a field error on fixed_price from the Action; the CHECK project_billing_fixed_price_required_check stays as the database guarantee"
  - "Errors are keyed by the plain data key (key, client_id, hourly_rate, fixed_price); plan 04-08 prefixes them with the form state path"

patterns-established:
  - "Admin-only side table: own uuid primary key, unique FK to the Partner-readable table, DeniesPartners model, AdminOnlyPolicy, canary fixture in a free-text column"
  - "Transaction failure test through a model creating listener (the application is rebuilt per test, so the listener needs no cleanup)"

requirements-completed: [PR-03, PR-02, PR-01]

coverage:
  - id: D1
    description: "project_billing table holds billing type, rates, fixed price, estimate and internal note, one row per project, with CHECK constraints; the projects table stays Partner-safe"
    requirement: PR-03
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it stores a project and its hourly billing row with the money in the client currency"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PartnerSafeColumnsTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner reads zero ProjectBilling rows and a null billing relation, while the Admin reads the billing canary of both clients"
    requirement: PR-03
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it shows a Partner no billing row and a null billing relation on the own visible project"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "CreateProject builds money with Money::fromMajor in the client currency and turns excess decimals, grouping characters, negative signs and overflow into field errors without rounding"
    requirement: PR-03
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it turns a malformed or over-precise amount into a field error on that field and stores nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "A duplicate project key, also of an archived project and also when taken between check and save, ends as a key field error from the database unique index with exactly one project left"
    requirement: PR-02
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it ends a key taken between the form check and the save as a key field error, with exactly one project left"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it rejects the key of an archived project and creates no second row"
        status: pass
    human_judgment: false
  - id: D5
    description: "No project for an archived client, and a failed billing insert leaves no project row (one transaction)"
    requirement: PR-01
    verification:
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it refuses to create a project for an archived client and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Projects/ProjectBillingTest.php#it leaves no project row behind when the billing insert fails"
        status: pass
    human_judgment: false

duration: 6 min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 06: Project Billing and CreateProject Summary

**Admin-only 1:1 project_billing table (type, hourly rate, fixed price, estimate, internal note) with a CreateProject Action that writes project, tags and billing in one transaction and turns bad amounts, archived clients and taken keys into field errors**

## Performance

- **Duration:** 6 min
- **Started:** 2026-10-08T13:10:19Z
- **Completed:** 2026-10-08T13:16:13Z
- **Tasks:** 2
- **Files modified:** 13

## Accomplishments

- `project_billing` table with unique `project_id`, Money column pairs, and the CHECK constraints from the plan (pair, sign, currency shape, currencies match, estimate, fixed price required); the Partner-readable `projects` table gained no column.
- `ProjectBilling` is closed to Partners (`DeniesPartners` plus `AdminOnlyPolicy`); a Partner reads zero rows and `$project->billing` is null, while the Admin reads the canary billing rows of both clients through a new `CanaryRegistry` fixture.
- `CreateProject` stores project, project-type tags and the billing row in one `DB::transaction`, sets `client_id` through `$client->projects()`, upper-cases the key and builds money with `Money::fromMajor` in the client's currency.
- Guards: archived client (`client_id`), amount errors on the offending field, and the key unique index translated into a `key` field error even when the key is taken after the form check.

## Task Commits

1. **Task 1: Tracer - CreateProject stores a project and its billing row in one transaction** - `77fb4ef` (feat)
2. **Task 2: CreateProject guards** - `d40484e` (test, RED: 14 failing guard tests) then `0d7e123` (feat, GREEN)

**Plan metadata:** committed separately as the docs commit for this plan.

## Files Created/Modified

- `database/migrations/2026_10_09_000400_create_project_billing_table.php` - billing table, FK restrict, unique project_id, all CHECKs
- `app/Domain/Projects/Models/ProjectBilling.php` - Admin-only model with Money casts and `BillingType` cast
- `app/Domain/Projects/Enums/BillingType.php` - hourly / fixed_price with Czech labels
- `app/Domain/Projects/Actions/CreateProject.php` - the creation Action and its guards
- `app/Domain/Projects/Models/Project.php` - `billing(): HasOne`
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - `project_billing` alias and `AdminOnlyPolicy` registration
- `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php` - canary fixture and expected model lists
- `tests/Feature/Projects/ProjectBillingTest.php` - 21 tests (creation, isolation, guards, race, transaction)
- `lang/cs/enums.php`, `lang/cs/kokpit.php` - billing type labels and `projects.errors.*`

## Decisions Made

- Negative amounts are refused in the Action because `Money::fromMajor` accepts a minus sign; the CHECK constraints remain the database backstop.
- The one-transaction test makes the billing insert fail through a `ProjectBilling::creating` listener, because every realistic input failure is now caught earlier as a field error.
- Errors use the plain data key; plan 04-08 maps them to `data.*` state paths.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Field error for a fixed-price project without a price**
- **Found during:** Task 2 (CreateProject guards)
- **Issue:** The plan lists a CHECK (`project_billing_fixed_price_required_check`) but no Action-level error, so a missing fixed price would surface as a raw database exception and an HTTP 500.
- **Fix:** The Action throws a `ValidationException` on `fixed_price` (`kokpit.projects.errors.fixed_price_required`) before opening the transaction; the CHECK stays as the database guarantee.
- **Files modified:** `app/Domain/Projects/Actions/CreateProject.php`, `lang/cs/kokpit.php`, `tests/Feature/Projects/ProjectBillingTest.php`
- **Verification:** `it requires a fixed price for a fixed-price project as a field error`
- **Committed in:** `0d7e123`

**2. [Rule 1 - Bug] Money::fromMajor accepts negative amounts**
- **Found during:** Task 2 (behavior list requires a negative sign to be a field error)
- **Issue:** `Money::fromMajor('-5', 'CZK')` returns a negative Money, which would have reached the CHECK constraint as a database error.
- **Fix:** The Action's amount parser refuses `minor < 0` with `amount_invalid`.
- **Files modified:** `app/Domain/Projects/Actions/CreateProject.php`
- **Verification:** the amount dataset covers a negative sign for both fields
- **Committed in:** `0d7e123`

---

**Total deviations:** 2 auto-fixed (1 missing critical, 1 bug)
**Impact on plan:** Both keep bad input out of the database as field errors; no scope creep.

## Issues Encountered

- `scripts/check-sensitive.sh` flagged an 8-digit assertion value in the test as a company ID; the expected value is now written as `120000 * 100`.
- The test database was one migration behind; `php artisan migrate --env=testing` brought it up to date.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None. `estimate_hours` is accepted in the documented data shape but intentionally not converted until `EstimateHours` lands in plan 04-07; `estimate_seconds` stays null until then.

## Threat Flags

None - no network endpoint, auth path or file access was added; the new table is covered by T-04-11 and T-04-12.

## Next Phase Readiness

Ready for 04-07: `UpdateProject`, `EstimateHours`, `ProjectBilling::clientHoldsMoney()`, the activity allowlist for `ProjectBilling` and the billing constraint tests build on this table and Action. Full suite green (1127 tests), Pint and PHPStan clean.

## Self-Check: PASSED

- All created files exist on disk (migration, `ProjectBilling`, `BillingType`, `CreateProject`, `ProjectBillingTest`).
- Commits `77fb4ef`, `d40484e` and `0d7e123` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run and passing; `ddev exec vendor/bin/pest` (1127 passed), `pint --test` and PHPStan clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
