---
phase: 04-clients-and-projects
plan: 01
subsystem: database
tags: [postgres, laravel, filament, eloquent, partner-isolation, canary-harness]

requires:
  - phase: 02-foundation-platform
    provides: PartnerScope, DeniesPartners, KokpitPolicy, MoneyCast, canary harness, schema rules R1-R9
  - phase: 03-foundation-operations
    provides: activity log conventions and settings the typed client defaults copy from (D-13)
provides:
  - clients table with database-enforced invariants (CHECK constraints, Money pair, per-country company number index)
  - Admin-only Client model (DeniesPartners, AdminOnlyPolicy, soft deletes) with the client morph alias
  - ClientFactory with fictional runtime values
  - users.client_id foreign key (ON DELETE RESTRICT), users.deactivated_at, case-insensitive e-mail uniqueness
  - User::canAccessPanel() lockout for deactivated users and Partners of archived or missing clients, no memo
  - canary harness on two real fictional client rows with a Client fixture
affects: [04-02 projects, 04-04 tags, 04-06 project billing, client admin resources, contacts, invitations]

actuals:
  tokens: 9000
  tasks: 3
  commits: 4

plan_head_before: 1f528193fd0fc4ecd464a6bd36bb630ea280a4d1
plan_head_after: 674edb455fc23f672ddc1015dc19725064652371

tech-stack:
  added: []
  patterns:
    - "Enum-like columns are varchar plus a named CHECK constraint, created with DB::statement"
    - "Partial unique index without a deleted_at predicate: archived rows keep reserving their natural key"
    - "Panel gate re-reads the client as a system run on every call (no memo), so an archive refuses the next request"
    - "Canary::twoClients() creates real client rows so foreign keys hold for every Partner a test creates"

key-files:
  created:
    - database/migrations/2026_10_09_000100_create_clients_table.php
    - database/migrations/2026_10_09_000200_add_client_fk_and_deactivation_to_users_table.php
    - app/Domain/Clients/Models/Client.php
    - database/factories/ClientFactory.php
    - tests/Isolation/PartnerLockoutTest.php
    - tests/Feature/Schema/ClientTablesTest.php
  modified:
    - app/Domain/Identity/Models/User.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Support/Canary.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php

key-decisions:
  - "Company ID and tax ID are stored as company_number and tax_number (schema rule R1 demands every *_id column be uuid)"
  - "No database default for country, currency, rate, payment terms or invoice language; the creating code copies the typed defaults (D-13)"
  - "canAccessPanel keeps no memo: one primary-key exists query per call, so an archive takes effect on the next request"
  - "Client carries no LogsAllowlistedActivity in this plan; ActivityAllowlistTest still expects an empty logging list and the activity allowlist arrives with the Admin resources"

patterns-established:
  - "Lockout test helpers (archive, restore, deactivate, reactivate, login message) in tests/Isolation/PartnerLockoutTest.php for later plans to reuse"
  - "Raw-SQL constraint tests through RawSql::expectSqlState with exact SQLSTATE codes (23514, 23505, 22001, 23502, 23503, 23001)"

requirements-completed: [CL-01, CL-05, US-02]

coverage:
  - id: D1
    description: "Client is closed to Partners at the data layer (DeniesPartners plus AdminOnlyPolicy): a Partner reads zero client rows, the Admin reads both canary clients"
    requirement: "CL-01"
    verification:
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#lets the Admin read the canary of both clients in every registered model, so the checks below are not vacuous"
        status: pass
    human_judgment: false
  - id: D2
    description: "clients table invariants: payment terms 0..365, non-negative rate, rate currency equals client currency, enum checks, per-country company number unique including archived clients"
    requirement: "CL-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/ClientTablesTest.php#clients"
        status: pass
    human_judgment: false
  - id: D3
    description: "users.client_id foreign key with ON DELETE RESTRICT, deactivated_at column and case-insensitive e-mail uniqueness"
    requirement: "US-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/ClientTablesTest.php#users"
        status: pass
    human_judgment: false
  - id: D4
    description: "Deactivated users and Partners of archived or missing clients are refused at login with the generic credential message and get 403 on the next request; restore or reactivation restores access"
    requirement: "CL-05"
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerLockoutTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Canary harness runs on two real fictional client rows with a Client fixture listed in the registry and model-declaration tests"
    verification:
      - kind: unit
        ref: "tests/Isolation/CanaryRegistryTest.php#does not pass vacuously: the real isolated models and the canary model are all expected"
        status: pass
      - kind: unit
        ref: "tests/Arch/ModelDeclarationTest.php#finds every model of the application, so the scan cannot pass vacuously"
        status: pass
    human_judgment: false

duration: 12min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 01: Clients Tracer Summary

**Admin-only `clients` table with database-enforced invariants, `users.client_id` foreign key with deactivation, and a per-request panel lockout for deactivated users and Partners of archived clients, with the canary harness moved onto real client rows**

## Performance

- **Duration:** 12 min
- **Started:** 2026-10-08T12:21:00Z
- **Completed:** 2026-10-08T12:33:00Z
- **Tasks:** 3
- **Files modified:** 14 (6 created, 8 modified)

## Accomplishments

- `clients` table: UUID v7 key, Money pair (`hourly_rate_minor` plus `hourly_rate_currency`), named CHECK constraints for country, stage, currency, rate, payment terms and invoice language, soft deletes, and a unique `(country, company_number)` index that also covers archived clients (D-09).
- `Client` model closed to Partners (`DeniesPartners`, `AdminOnlyPolicy`, `client` morph alias); the canary harness now creates two real fictional clients through `Canary::twoClients()` and has a `Client` fixture, so the registry and model-declaration tests cover it.
- `users.client_id` references `clients` with `ON DELETE RESTRICT`; `users.deactivated_at` and the `users_email_lower_unique` index are in place.
- `User::canAccessPanel()` refuses deactivated users and Partners whose client is archived or missing, re-reading the client inside `runAsSystem` on every call with no cached state. At login the failure shows the same generic message as a wrong password.
- 12 lockout tests and 14 schema tests; full suite 1038 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, canary harness on two real fictional clients** - `1b4a3ee` (feat)
2. **Task 2: users foreign key, deactivation and lockout (TDD)** - `7fc3d6c` (test, RED: 10 of 12 failed, the 2 passing were the Admin and client-less Partner baselines) then `64da9cf` (feat, GREEN)
3. **Task 3: Schema constraint tests** - `674edb4` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer feedback gate ran in auto mode: the tracer `<verify>` (quick suite, full suite, Pint, PHPStan) was re-run end to end before expansion and passed (1013 tests at that point).

## Files Created/Modified

- `database/migrations/2026_10_09_000100_create_clients_table.php` - clients table, CHECK constraints, partial unique index
- `database/migrations/2026_10_09_000200_add_client_fk_and_deactivation_to_users_table.php` - users foreign key, `deactivated_at`, lower(email) unique index
- `app/Domain/Clients/Models/Client.php` - Admin-only client model with Money cast and soft deletes
- `database/factories/ClientFactory.php` - fictional runtime values, rate 0 CZK, no company number
- `app/Domain/Identity/Models/User.php` - `deactivated_at` cast and the three-condition `canAccessPanel()`
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - `client` alias and `AdminOnlyPolicy` registration
- `tests/Support/Canary.php`, `tests/Support/CanaryRegistry.php` - real clients and the Client fixture
- `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php` - expected model lists extended
- `tests/Isolation/PartnerLockoutTest.php` - login refusal and next-request 403
- `tests/Feature/Schema/ClientTablesTest.php` - exact SQLSTATE assertions for clients and users
- `tests/Feature/Auth/TwoFactorEnforcementTest.php` - see deviation 1

## Decisions Made

- Company ID and tax ID are columns `company_number` and `tax_number` (rule R1 forbids non-uuid `*_id` columns), as the plan assumed.
- `Client` was not given `LogsAllowlistedActivity`: `ActivityAllowlistTest` asserts the logging model list is empty and the plan does not list that file; the allowlist belongs to the Admin resources plan.
- Docblock of `canAccessPanel()` states why the client read is a system run and why nothing is memoised.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Test invented a client id that the new foreign key refuses**
- **Found during:** Task 2 (full suite after the foreign key)
- **Issue:** `tests/Feature/Auth/TwoFactorEnforcementTest.php` ("never forces a Partner to set up two-factor authentication") set `client_id` to a random uuid, now SQLSTATE 23503. The file was not in the plan's `files_modified`, but the plan's Task 2 action explicitly says to fix any such caller.
- **Fix:** use `Canary::twoClients()[0]`.
- **Files modified:** `tests/Feature/Auth/TwoFactorEnforcementTest.php`
- **Verification:** full suite green (1038 passed)
- **Committed in:** `64da9cf`

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** None on scope; exactly the caller fix the plan anticipated. No migration defect was revealed by the schema tests in Task 3.

## Issues Encountered

One full-suite run directly after editing the 2FA test still showed the old failure; the same test passed alone and the next full run was green (file sync lag between host and DDEV container, not a code issue).

## Known Stubs

None.

## Threat Flags

None - no new network endpoint, auth path or file access beyond the plan's threat model (T-04-02 to T-04-05 mitigations implemented and tested).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 04-02 can add `projects` (migration `000300`), the `Project` fixture directly after the `Client` fixture, and a projects section in `ClientTablesTest.php`.
- `Client::projects()` is not declared yet; it joins with the `Project` model in plan 04-02.
- Remaining Phase 4 open item carried from the plan: the `deactivated_at` setter Actions (plan 04-20) must use `forceFill`.

## Self-Check: PASSED

All created files exist on disk; commits `1b4a3ee`, `7fc3d6c`, `64da9cf`, `674edb4` are ancestors of HEAD; the plan's acceptance greps and the full suite, Pint and PHPStan pass.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
