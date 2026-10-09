---
phase: 03-operations-foundation
plan: 04
subsystem: ui
tags: [filament, livewire, spatie-laravel-settings, validation, access-rule, czech-ui]
type: execute

requires:
  - phase: 03-operations-foundation
    provides: SettingsProperty fail-closed scope, SettingsMigration, SupplierSettings storage (plan 03-03)
  - phase: 02-platform-foundation
    provides: AccessRule/Audience declarations, EnforcesPageAccessRule, PartnerContext, canary users
provides:
  - ValidatedSettings base (data-layer rules(), form-state seams, save() validates before writing)
  - SupplierSettings on the base with its rules
  - Admin-only Czech SettingsPage with a tab per settings group and one Save in one transaction
  - EnforcesPageAccessRule boot hook that refuses with 403 before any page mount()
  - MountProbePage test fixture and boot-order proof
affects: [03-05 defaults tab, 03-06 invoicing and online payments tabs, 03-07 bank accounts tab, 03-09 numbering, Phase 8 work report, Phase 10 invoices]

plan_head_before: 5c4a95a02d040f8c70e53e4076930ec19b5b9c8d
plan_head_after: 53d632df967aadd92acbf3df6c3121d25322d124

actuals:
  tokens: 6500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Settings class = ValidatedSettings subclass with static rules(); the page form reuses those rules per field so form and data layer cannot drift"
    - "Settings page: private const SETTINGS list in tab order, one private tab method per tab, form state keyed by group, errors re-mapped to data.{group}.{key}"
    - "Livewire boot hook (not mount) for page access checks"

key-files:
  created:
    - app/Domain/Settings/Settings/ValidatedSettings.php
    - app/Filament/Pages/SettingsPage.php
    - tests/Feature/Operations/SettingsPageTest.php
    - tests/Support/Filament/Fixtures/MountProbePage.php
  modified:
    - app/Domain/Settings/Settings/SupplierSettings.php
    - app/Filament/Concerns/EnforcesPageAccessRule.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/SettingsStorageTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "SupplierSettings is no longer final, so the page-rollback test can bind a looser/stricter test double in the container"
  - "SettingsPage overrides hasDatabaseTransactions() to true: the trait's begin/commit/rollback are no-ops unless the panel-wide switch is on, which would silently break the one-transaction guarantee"
  - "ValidatedSettings::fillFromFormState() maps null/empty text to null for nullable string properties and to an empty string for non-nullable ones, and ignores keys that are not declared properties (T-03-10)"
  - "The form fields carry SupplierSettings::rules() directly; country and VAT ID are normalised to upper case in the form before validation so the strict data-layer patterns are not a UX trap"

patterns-established:
  - "New settings tab = tab method + class appended to SettingsPage::SETTINGS + labels under kokpit.settings.*"
  - "Every Filament page using EnforcesPageAccessRule is refused by the boot hook before mount()"

requirements-completed: [FND-07]

coverage:
  - id: D1
    description: "The Admin opens one Czech settings page with a supplier tab, saves with one Save button and sees the saved values after a reload"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#saves the supplier data on the page and shows it after a reload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#renders the Czech navigation label and tab for the Admin"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner with a client, a Partner without a client and a user without a role get 403 on the settings page and no settings query runs before the refusal"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#refuses the settings page to a Partner with a client, a Partner without a client and a user without a role"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#runs no settings query for a Partner before refusing"
        status: pass
    human_judgment: false
  - id: D3
    description: "Invalid values saved outside the form throw ValidationException and write nothing"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#refuses a supplier country in lower case outside the form and keeps the stored one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#refuses a company id of seven digits and names the field"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsStorageTest.php#writes nothing for a settings class filled with invalid values before it ever saved"
        status: pass
    human_judgment: false
  - id: D4
    description: "The page saves all groups in one transaction; a data-layer ValidationException rolls back and is shown on data.{group}.{key}"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#rolls the save back and shows a data-layer error on the field when the class refuses the values"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every page using EnforcesPageAccessRule denies before its own mount() runs"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#refuses a Partner before the page mount() runs and runs it for an Admin"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Czech wording of the settings page reads naturally"
    requirement: FND-07
    verification: []
    human_judgment: true
    rationale: "Label wording is a language-quality judgment no test asserts; tests only prove the keys render"

duration: 8min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 04: Czech Settings Page Summary

**Admin-only Filament settings page with a supplier tab, one Save in one transaction, a ValidatedSettings data-layer validation base, and a Livewire boot hook that refuses a Partner with 403 before any page mount() runs**

## Performance

- **Duration:** about 8 min
- **Started:** 2026-10-08T02:38Z (approx.)
- **Completed:** 2026-10-08T02:47Z
- **Tasks:** 2
- **Files modified:** 9 (4 created, 5 modified)

## Accomplishments

- `ValidatedSettings` base: `rules()` per form-state key, `toFormState()` / `fillFromFormState()` seams, and `save()` that validates before `parent::save()`. `SupplierSettings` moved onto it with the plan's rules.
- `SettingsPage` (AdminOnly, slug `settings`, Czech navigation label and group from `lang/cs`): `Tabs` with the supplier tab, form state keyed by settings group, one Save (also Ctrl/Cmd+S) that saves every class in `SETTINGS` in one database transaction and shows a Czech notification.
- `EnforcesPageAccessRule::bootEnforcesPageAccessRule()` closes research Pitfall 1: a Partner is refused at Livewire boot, before the page's own `mount()` (and its settings read) can run.
- Verification: full Pest 433 passed (route walk and panel registry included), Pint clean, PHPStan level 8 clean, `composer check-licenses` OK (201 packages).

## Task Commits

1. **Task 1: Tracer - supplier data persists through the Czech settings page; Partner refused before mount()** - `4d196dd` (feat)
2. **Task 2: Data-layer validation outside the form, one-transaction save with mapped errors, boot-order proof** - `53d632d` (test)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`, but all of its behaviors were already implemented by the Task 1 tracer (the plan puts `ValidatedSettings::save()`, the transactional save with error re-mapping and the boot hook into Task 1). The Task 2 tests therefore passed on the first run (unexpected GREEN per the gate), so no valid RED exists and no `feat(03-04)` commit follows `test(03-04)`. The unexpected GREEN was investigated and is not a vacuous test: each guard was mutated, the matching tests failed, and the source was restored.

| Mutation | Tests that failed |
|---|---|
| remove the `Validator::make(...)->validate()` call from `ValidatedSettings::save()` | the three storage tests (lower-case country, 7-digit company ID, invalid never-saved values) and the page rollback test |
| remove `rollBackDatabaseTransaction()` from `SettingsPage::save()` | the page rollback test |
| drop the `data.{group}.` key prefix | the page rollback test |
| empty the body of `bootEnforcesPageAccessRule()` | the settings 403 test, the no-settings-query test and the MountProbePage test (the Partner request then reaches `mount()` and throws `MissingSettings`) |

The Task 1 boot-hook mutation was also run once before Task 1 was committed (same three failures). The form-state key filter test (`ignores keys of a form state that are not declared properties`) was not mutation-checked separately.

## Files Created/Modified

- `app/Domain/Settings/Settings/ValidatedSettings.php` - settings base with data-layer validation and form-state mapping
- `app/Domain/Settings/Settings/SupplierSettings.php` - now extends the base, rules per the plan, no longer `final`
- `app/Filament/Pages/SettingsPage.php` - admin-only tabbed settings page, one transactional Save
- `app/Filament/Concerns/EnforcesPageAccessRule.php` - boot hook aborting 403 before mount()
- `lang/cs/kokpit.php` - `settings.*` navigation, tab, field and notification labels
- `tests/Feature/Operations/SettingsPageTest.php` - save/reload, validation, Czech render, 403 states, no-query proof, rollback with mapped error
- `tests/Feature/Operations/SettingsStorageTest.php` - data-layer validation cases and form-state key filtering
- `tests/Support/Filament/Fixtures/MountProbePage.php` - AdminOnly probe page recording that mount() ran
- `tests/Isolation/PanelAccessTest.php` - boot-order case

## Decisions Made

- `SupplierSettings` lost `final` so a test double can subclass it and be bound in the container (the plan calls for such a double). The class stays a plain settings value holder.
- `SettingsPage::hasDatabaseTransactions()` returns `true`. The `CanUseDatabaseTransactions` helpers are no-ops unless the panel enables transactions, and a trait property cannot be redeclared, so the method is overridden.
- Form fields reuse `SupplierSettings::rules()` and normalise country and VAT ID to upper case (VAT ID also without spaces) before validation, so the strict `^[A-Z]{2}$` / VAT patterns do not reject natural input while the data layer stays strict.
- Empty form text maps to `null` for nullable properties and `''` for non-nullable ones inside the base `fillFromFormState()` rather than per class, so later settings classes get the same behaviour. Keys that are not declared properties are ignored.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Trait property cannot be redeclared**
- **Found during:** Task 1
- **Issue:** `protected ?bool $hasDatabaseTransactions = true;` on the page is incompatible with the same property in `CanUseDatabaseTransactions` (PHP fatal error at class composition).
- **Fix:** Overrode the public method `hasDatabaseTransactions()` instead.
- **Files modified:** `app/Filament/Pages/SettingsPage.php`
- **Verification:** page tests pass; rollback mutation test fails as expected
- **Committed in:** `4d196dd`

**2. [Rule 1 - Bug] PHPStan return type of `ValidatedSettings::save()`**
- **Found during:** Task 1
- **Issue:** returning `parent::save()` typed `Settings` against a `self` declaration failed level 8.
- **Fix:** declared `: static`, call `parent::save()` and return `$this`.
- **Files modified:** `app/Domain/Settings/Settings/ValidatedSettings.php`
- **Committed in:** `4d196dd`

**3. [Rule 2 - Missing critical] Form state is filtered to declared properties**
- **Found during:** Task 1 (threat T-03-10)
- **Issue:** a plain `fill($state)` would set arbitrary dynamic properties from client-controlled keys.
- **Fix:** `fillFromFormState()` ignores keys that are not declared settings properties; covered by a test.
- **Files modified:** `app/Domain/Settings/Settings/ValidatedSettings.php`, `tests/Feature/Operations/SettingsStorageTest.php`
- **Committed in:** `4d196dd`, `53d632d`

---

**Total deviations:** 3 auto-fixed (1 blocking, 1 bug, 1 missing critical)
**Impact on plan:** No scope change; all three keep the planned contract intact.

## Issues Encountered

- `ddev exec` sometimes ran against a stale view of freshly written files (a "test file not found" and one stale fatal error); a short wait before re-running resolved it.
- My first draft of the rollback test asserted `assertHasNoFormErrors()` after a data-layer error, which asserts that there are no errors at all and so contradicted the other assertion; it was removed.

## Known Stubs

None.

## Threat Flags

None. The new route is the planned settings page and is covered by the route walk and the access-rule registry (T-03-09, T-03-10 mitigated and test-covered; T-03-SC: no package installed, `composer.lock` unchanged).

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording of `kokpit.settings.*` (navigation group "Správa", tab "Dodavatel", field labels); it is placeholder-quality discretionary wording.

## Next Phase Readiness

- Ready for 03-05 onwards: add a tab method, append the class to `SettingsPage::SETTINGS` and extend `kokpit.settings.*`; settings classes extend `ValidatedSettings`.
- Relation managers and widgets still lack the equivalent boot hook (research suggests adding it for them too); this plan covered pages only, as scoped.

## Self-Check: PASSED

- All four created files exist on disk.
- Commits `4d196dd` and `53d632d` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 2.
- Acceptance greps for both tasks pass; full Pest (433), Pint, PHPStan and `composer check-licenses` are green.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
