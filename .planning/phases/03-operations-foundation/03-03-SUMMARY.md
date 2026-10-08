---
phase: 03-operations-foundation
plan: 03
subsystem: infra
tags: [spatie-laravel-settings, uuid-v7, postgres, jsonb, partner-isolation, settings]
type: execute

requires:
  - phase: 02-platform-foundation
    provides: DeniesPartners trait, PartnerContext::runAsSystem, canary registry, schema rules R1-R9, AdminOnlyPolicy
provides:
  - spatie/laravel-settings on a UUID v7 `settings` table with a jsonb payload (SettingsProperty model)
  - Fail-closed Partner scope on settings rows with an empty PARTNER_VISIBLE_GROUPS allowlist
  - App\Domain\Settings\SettingsMigration base that runs every settings migration in the system context
  - Typed SupplierSettings (group supplier) and its settings migration
  - Package cache switched off by a literal false, no env read in config/settings.php
affects: [03-04 settings page, 03-05..03-08 settings classes, 03-13 job base, Phase 8 work report, Phase 10 invoices]

plan_head_before: e1130e8939ab54a9b2d7f3400424e868dc3fefd3
plan_head_after: 114b8d716b8179c09723dd8ef8a95eb390cad007

actuals:
  tokens: 12000
  tasks: 2
  commits: 2

tech-stack:
  added: [spatie/laravel-settings ^3.9 (3.9.0, MIT)]
  patterns:
    - "Settings storage on the Phase 2 conventions: uuidv7() default key, timestampsTz, jsonb payload, unique (group, name)"
    - "Admin-only model with a single widening point (group allowlist) kept on DeniesPartners so the canary test fails until a widening is made on purpose"
    - "Final entry point plus abstract body (SettingsMigration::up/migrate) so the system-context wrapper cannot be forgotten"

key-files:
  created:
    - config/settings.php
    - database/migrations/2026_10_08_000100_create_settings_table.php
    - database/settings/2026_10_08_000110_create_supplier_settings.php
    - app/Domain/Shared/Models/SettingsProperty.php
    - app/Domain/Settings/SettingsMigration.php
    - app/Domain/Settings/Settings/SupplierSettings.php
    - tests/Feature/Operations/SettingsStorageTest.php
  modified:
    - composer.json
    - composer.lock
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "SettingsProperty keeps the DeniesPartners trait and overrides constrainForPartner() with a group allowlist (empty now, so deny-all); a Partner-visible group later fails the canary test until the trait and fixture are swapped on purpose"
  - "settings.cache.enabled and settings.cache.memo are literal false with no environment switch, because a cached read bypasses the SettingsProperty scope"
  - "SettingsMigration::up() is final and runs migrate() inside PartnerContext::runAsSystem; add() and update() of the package migrator read through the scoped model, so without the wrapper they see no rows"
  - "Settings classes are registered explicitly in config('settings.settings'); auto-discovery is empty so deploys need no discovery cache"

patterns-established:
  - "Every settings migration extends App\\Domain\\Settings\\SettingsMigration and implements migrate(); a test fails for any file under database/settings that does not"
  - "New settings classes are appended to config/settings.php 'settings'"

requirements-completed: [FND-07]

coverage:
  - id: D1
    description: "Settings stored by spatie/laravel-settings in a UUID v7 settings table (uuid pk, timestamptz, jsonb payload, unique group/name) through the SettingsProperty subclass; schema rules R1-R9 stay green"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#keeps settings rows on a uuid v7 key with timestamptz timestamps and a jsonb payload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Schema/SchemaConventionsTest.php#R8: package models are the registered HasUuids subclasses"
        status: pass
    human_judgment: false
  - id: D2
    description: "Typed supplier settings saved by code are returned by a fresh resolution as Admin and inside the system context"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#stores supplier settings saved as Admin and returns them from a fresh resolution"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#returns the stored supplier settings inside the system context without a user"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner reads no settings row and a settings class resolved as Partner fails closed; the group allowlist is the only widening point; the package cache cannot bypass the scope"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#hides the stored supplier settings from a Partner and fails closed"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#shows a Partner only the rows of the groups on the allowlist and nothing else"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#keeps the settings cache off by a literal value and reads no environment variable"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php (SettingsProperty fixture, zero rows for Partner A)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Settings migrations run in the system context and every file under database/settings extends the base"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#runs a settings migration in the system context so it reads and updates stored values"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#declares a class extending the system-context base in every file under database/settings"
        status: pass
    human_judgment: false
  - id: D5
    description: "Phase 2 convention tests (model declaration, canary registry, schema package registry) list SettingsProperty"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Arch/ModelDeclarationTest.php"
        status: pass
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 03: Typed Settings Storage Summary

**spatie/laravel-settings on a UUID v7 jsonb `settings` table with a fail-closed Partner scope, group allowlist, cache off and a system-context settings migration base, proven with typed SupplierSettings**

## Performance

- **Duration:** about 8 min
- **Started:** 2026-10-08T02:29Z (approx.)
- **Completed:** 2026-10-08T02:37Z
- **Tasks:** 2
- **Files modified:** 14 (7 created, 7 modified)

## Accomplishments

- `settings` table on the Phase 2 conventions (uuid pk default `uuidv7()`, `timestampsTz`, jsonb payload, unique (group, name)); the package writes through the `SettingsProperty` UUID subclass registered in `config/settings.php`. Schema rules R1-R9 and the four-entry exempt map are unchanged and green.
- `SettingsProperty` is `PartnerIsolated` on `DeniesPartners` with `PARTNER_VISIBLE_GROUPS = []`; a Partner sees zero rows and a settings class resolved as Partner throws `MissingSettings`. `AdminOnlyPolicy` is registered for it; the canary registry, model declaration test and R8 registry list it.
- `SettingsMigration` base (final `up()` inside `runAsSystem`, abstract `migrate()`), `SupplierSettings` (group `supplier`) and its settings migration with empty/null defaults (`country` = `CZ`).
- Package cache off by literal `false` (`enabled` and `memo`), no `env()` call in `config/settings.php` (token-checked by test).
- Full suite 421 passed, Pint and PHPStan level 8 clean, `composer check-licenses` OK (201 packages).

## Task Commits

1. **Task 1: Tracer - supplier settings persist in the UUID v7 settings table** - `37c76b7` (feat)
2. **Task 2: Partner group allowlist, cache off, system-context settings migrations, per-request freshness** - `114b8d7` (test)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`, but its behaviors were already implemented by the Task 1 tracer (the plan puts `SettingsMigration`, the allowlist and the cache config into Task 1). The new tests therefore passed on first run (unexpected GREEN per the gate), so no valid RED exists and no `feat(03-03)` commit follows the `test(03-03)` commit. The unexpected GREEN was investigated: it is correct, not a vacuous test. As a substitute for a RED run I mutated each guard and confirmed the matching test fails, then restored it:

| Mutation | Test that failed |
|---|---|
| remove `runAsSystem` from `SettingsMigration::up()` | system-context migration test (`SettingDoesNotExist`) |
| ignore the allowlist in `constrainForPartner()` | allowlist test |
| `cache.enabled` => `true` | cache-off test, Partner fail-closed test (cached read bypassed the scope), freshness test |
| `env()` in `cache.memo` | cache/no-env test |
| a `database/settings` file not extending the base | extends-base test |

The per-request freshness test relies on the package's `scoped()` binding and was not mutation-checked (no local code to mutate); the cache mutation did make it fail.

## Files Created/Modified

- `config/settings.php` - explicit settings list, UUID model, database repository only, cache off, no env
- `database/migrations/2026_10_08_000100_create_settings_table.php` - UUID v7 settings table
- `app/Domain/Shared/Models/SettingsProperty.php` - UUID model, DeniesPartners plus group allowlist
- `app/Domain/Settings/SettingsMigration.php` - system-context settings migration base
- `app/Domain/Settings/Settings/SupplierSettings.php` - typed supplier settings
- `database/settings/2026_10_08_000110_create_supplier_settings.php` - supplier defaults
- `app/Providers/AccessServiceProvider.php` - `AdminOnlyPolicy` for `SettingsProperty`
- `tests/Feature/Operations/SettingsStorageTest.php` - 10 storage, isolation and migration tests
- `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Feature/Schema/SchemaConventionsTest.php` - Phase 2 touch points
- `composer.json`, `composer.lock` - `spatie/laravel-settings` ^3.9 (lock also moved four packages from dev to runtime requirements, a package dependency consequence)

## Decisions Made

- Kept `DeniesPartners` on `SettingsProperty` and aliased its method inside the override (`denyAll`), so the empty allowlist reuses the exact Phase 2 deny-all constraint and `class_uses_recursive` still reports the trait to the canary test.
- Settings value types: plain strings with `?string` for optional fields; no cast classes yet (validation and form mapping come with plan 03-04).
- No settings class in this plan holds a secret (threat T-03-07 accepted as planned).

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- First ordering assertion on `information_schema` column results failed because the query returns rows unordered; the test now `ksort`s before comparing.

## Known Stubs

None.

## Threat Flags

None - no new network endpoint, auth path or trust boundary beyond the plan's threat model (T-03-06, T-03-08, T-03-SC are mitigated and test-covered).

## User Setup Required

None - no external service configuration required. After pulling, `php artisan migrate` creates the table and seeds the supplier rows.

## Manual follow-up

None.

## Next Phase Readiness

- Ready for 03-04: the settings page can resolve `SupplierSettings` as Admin; `ValidatedSettings` should move `SupplierSettings` onto it as planned.
- Later plans append their settings classes to `config('settings.settings')` and their migrations extend `App\Domain\Settings\SettingsMigration`.

## Self-Check: PASSED

- All seven created files exist on disk.
- Commits `37c76b7` and `114b8d7` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 2.
- Acceptance greps for both tasks pass; full Pest (421), Pint, PHPStan and `composer check-licenses` are green.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
