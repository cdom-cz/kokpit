---
phase: 03-operations-foundation
plan: 05
subsystem: settings
tags: [money, brick-money, spatie-laravel-settings, filament, validation, czech-ui]
type: execute

requires:
  - phase: 03-operations-foundation
    provides: ValidatedSettings, SettingsMigration, SettingsPage with tabs and one transactional Save (plans 03-03, 03-04)
  - phase: 02-platform-foundation
    provides: Money value object with the single rounding point, MoneyBoundaryTest
provides:
  - Money::fromMajor (no-rounding parse of a typed amount), toMajor, isoCurrencyCodes, isKnownCurrency
  - MoneySettingsCast (Money <-> {minor, currency})
  - DefaultsSettings (group defaults: default_currency, default_hourly_rate) with settings migration
  - KnownCurrency validation rule
  - Defaults tab on the Czech settings page
affects: [03-06 invoicing and online payments tabs, 03-07 bank accounts tab, Phase 6 rate resolution, Phase 10 invoices]

plan_head_before: aa9ec01dadcc38ac44ec98593dd021d809aa2f7b
plan_head_after: 1364d7775fd83c6bdeb39deb89cb092d151a08fe

actuals:
  tokens: 6800
  tasks: 2
  commits: 4

tech-stack:
  added: []
  patterns:
    - "Typed user input to Money goes through Money::fromMajor only; excess decimals are an error, fromExactMinor stays the single rounding point"
    - "Settings with a non-string form shape override toFormState()/fillFromFormState() on the class; a Money property uses a SettingsCast and a text form field"
    - "Data-layer rules that Filament must also evaluate are ValidationRule classes, not bare closures (Filament tries to inject closure parameters)"

key-files:
  created:
    - app/Domain/Settings/Casts/MoneySettingsCast.php
    - app/Domain/Settings/Settings/DefaultsSettings.php
    - app/Domain/Settings/Rules/KnownCurrency.php
    - database/settings/2026_10_08_000120_create_defaults_settings.php
    - tests/Feature/Operations/SettingsGroupsTest.php
  modified:
    - app/Domain/Shared/Money/Money.php
    - app/Filament/Pages/SettingsPage.php
    - config/settings.php
    - lang/cs/kokpit.php
    - tests/Unit/Money/MoneyTest.php
    - tests/Feature/Operations/SettingsPageTest.php

key-decisions:
  - "fromMajor rejects excess decimals by calling BigDecimal::toScale with brick's default no-rounding behaviour and mapping RoundingNecessaryException to InvalidArgumentException, so it never names the rounding-mode class and MoneyBoundaryTest stays untouched"
  - "Trailing zeros beyond the minor unit are accepted when the value is exact (1.500 CZK is 150 minor units); only a value that would need rounding is refused"
  - "The rate currency is not a separate form field: the page converts the typed rate with the selected default currency, and DefaultsSettings::save() refuses a rate whose currency differs from default_currency (error keyed on default_hourly_rate)"
  - "DefaultsSettings::toFormState() prints the rate as text with a decimal comma, so the data-layer regex ^\\d+([.,]\\d+)?$ also rejects a negative rate"
  - "isoCurrencyCodes() returns every code the money library knows, including funds and test codes (for example XTS, XXX), as the artifact contract says; narrowing the select to circulating currencies is left for a later UX pass"

patterns-established:
  - "New settings group = class extending ValidatedSettings + settings migration + config/settings.php entry + SETTINGS entry + tab method + kokpit.settings.* labels"

requirements-completed: [FND-07]

coverage:
  - id: D1
    description: "The Admin picks the default currency and types the default hourly rate with a decimal comma or dot; it is stored as integer minor units plus currency and shown again after reload"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#saves the default currency and a default rate typed with a decimal comma and shows it after a reload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#stores the default rate as integer minor units plus currency and reads it back"
        status: pass
    human_judgment: false
  - id: D2
    description: "Money::fromMajor never rounds: excess decimals, grouping, empty, non-numeric input and an unknown currency are refused; toMajor round-trips"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#refuses a typed amount instead of rounding it"
        status: pass
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#parses a typed amount in major units into minor units without rounding"
        status: pass
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#reads back what it prints"
        status: pass
    human_judgment: false
  - id: D3
    description: "A rate in another currency than the default currency, a negative rate and an unknown currency cannot be saved, not even outside the form"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#refuses a default rate in another currency than the default currency and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#refuses a negative default rate and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#refuses an unknown default currency and stores nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "Typing 12,345 (or a grouped, negative or empty rate) shows a form error on the rate field and stores nothing"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#shows a form error on the rate and stores nothing when more decimals are typed than the currency allows"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#refuses a grouped, a negative and an empty rate on the page"
        status: pass
    human_judgment: false
  - id: D5
    description: "Czech wording of the defaults tab (labels, hint, error messages) reads naturally"
    verification: []
    human_judgment: true
    rationale: "Label wording is a language-quality judgment no test asserts; tests only prove the keys render"

duration: 12min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 05: Default Currency and Hourly Rate Summary

**Defaults tab on the Czech settings page storing the default currency and hourly rate as Money minor units, fed by a new no-rounding `Money::fromMajor` and protected again at the data layer**

## Performance

- **Duration:** about 12 min
- **Started:** 2026-10-08T02:41Z (approx.)
- **Completed:** 2026-10-08T02:53Z
- **Tasks:** 2
- **Files modified:** 11 (5 created, 6 modified)

## Accomplishments

- `Money::fromMajor` parses `1250,50` / `1250.5` into minor units and refuses (never rounds) excess decimals, grouping characters, blank or non-numeric text and unknown currencies; `toMajor`, `isoCurrencyCodes` and `isKnownCurrency` complete the contract. Brick stays confined to `Money.php`, and the rounding-mode class is still only named in `fromExactMinor` (`MoneyBoundaryTest` unchanged and green).
- `MoneySettingsCast` and `DefaultsSettings` (group `defaults`) with the settings migration (CZK, zero rate) registered in `config/settings.php` and `SettingsPage::SETTINGS`.
- Defaults tab: searchable currency select over `Money::isoCurrencyCodes()` (live) and a rate text input whose suffix follows the selected currency per hour; the closure rule runs `Money::fromMajor` with the selected currency, so a zero-decimal currency takes whole amounts only.
- Data-layer protection: `DefaultsSettings::save()` refuses a rate in another currency (error on `default_hourly_rate`), the rules refuse a negative rate and an unknown currency; nothing is written on refusal.
- Full gate green: `ddev composer ci` (Pest 480 passed, Pint, PHPStan level 8, licence check on 201 packages).

## Task Commits

1. **Task 1: Tracer - typed default rate stored through Money::fromMajor and shown after reload** - `3a6c8fa` (feat)
2. **Task 2: No-rounding money parsing and data-layer rules** - RED `58075e0` (test), GREEN `363551c` (feat), literal-case test `1364d77` (test)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`. The Task 1 tracer already implemented `fromMajor`, `toMajor`, `isoCurrencyCodes`, the cast, the settings class and the page rule, so most Task 2 tests were green on their first run (unexpected GREEN per the gate). They were investigated and proven not vacuous with mutation checks. A valid RED exists for the behaviors the tracer did not cover:

- **RED (`58075e0`):** 3 target tests failed on the planned assertions: `knows exactly the upper-case ISO codes` (undefined method `Money::isKnownCurrency`), `refuses a default rate in another currency than the default currency and stores nothing` and `names the rate field when the class refuses a mismatching currency` (the mismatching rate was saved, no `ValidationException`). Semantic assessment: the target tests executed and failed for the intended missing behavior, not for setup or syntax faults. `gsd_run check tdd-red-evidence` was not run (Pest/PHPUnit console output is not one of its supported report formats); the assessment is the manual inspection of the Pest failure output.
- **GREEN (`363551c`):** `Money::isKnownCurrency`, `KnownCurrency` rule, `DefaultsSettings::save()` currency-consistency check; all Money, Settings and arch tests pass.
- **REFACTOR:** none needed. `1364d77` adds one literal `fromMajor('12,345', 'CZK')` case because the plan's acceptance grep looks for that exact text.

Mutation checks of the tests that were green on first run (source restored after each):

| Mutation | Tests that failed |
|---|---|
| `toScale(...)` in `fromMajor` rounding half up instead of refusing | three "refuses a typed amount" cases (excess decimals, point, zero-decimal currency), the Money-refusal-to-field-error test, and the two page tests for excess decimals and zero-decimal currency |
| rate regex removed from `DefaultsSettings::rules()` | "refuses a negative default rate" and the page case `-5` |

The page closure rule was not mutation-checked separately: with it removed, `fillFromFormState()` still turns a `Money` refusal into an error keyed `data.defaults.default_hourly_rate`, which the same `assertHasFormErrors` assertion accepts. The page-level contract (error shown, nothing stored) holds with either layer; the closure only gives inline feedback before the save.

## Files Created/Modified

- `app/Domain/Shared/Money/Money.php` - `fromMajor`, `toMajor`, `isoCurrencyCodes`, `isKnownCurrency`
- `app/Domain/Settings/Casts/MoneySettingsCast.php` - Money <-> `{minor, currency}` settings payload
- `app/Domain/Settings/Settings/DefaultsSettings.php` - default currency and rate, form-state seams, currency-consistency check
- `app/Domain/Settings/Rules/KnownCurrency.php` - validation rule over `Money::isKnownCurrency`
- `database/settings/2026_10_08_000120_create_defaults_settings.php` - CZK and a zero CZK rate
- `config/settings.php` - `DefaultsSettings` registered
- `app/Filament/Pages/SettingsPage.php` - `defaultsTab()` and `DefaultsSettings` in `SETTINGS`
- `lang/cs/kokpit.php` - `settings.tabs.defaults` and `settings.defaults.*`
- `tests/Unit/Money/MoneyTest.php` - no-rounding, round-trip, currency list cases
- `tests/Feature/Operations/SettingsGroupsTest.php` - defaults after migrate, storage shape, data-layer refusals
- `tests/Feature/Operations/SettingsPageTest.php` - save/reload and form-error cases for the defaults tab

## Decisions Made

See `key-decisions` in the frontmatter. In short: `fromMajor` relies on brick's default no-rounding `toScale`, exact trailing zeros are accepted, the rate currency always follows the selected default currency on the page, and the data layer carries the consistency rule.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Bare closure rule cannot be shared between the data layer and Filament**
- **Found during:** Task 2 (GREEN)
- **Issue:** a closure `default_currency` rule in `DefaultsSettings::rules()` made Filament's `Select` try to inject `$attribute` and throw `BindingResolutionException` when the page rendered.
- **Fix:** replaced it with the `KnownCurrency` `ValidationRule` class (planned file list gained `app/Domain/Settings/Rules/KnownCurrency.php`).
- **Files modified:** `app/Domain/Settings/Settings/DefaultsSettings.php`, `app/Domain/Settings/Rules/KnownCurrency.php`
- **Verification:** `ddev composer ci` green
- **Committed in:** `363551c`

**2. [Rule 1 - Bug] Wrong class name for the currency provider**
- **Found during:** Task 1 (PHPStan)
- **Issue:** `Brick\Money\ISOCurrencyProvider` does not exist (`IsoCurrencyProvider`); it only worked at runtime because the development filesystem is case-insensitive, and would have failed on Linux CI.
- **Fix:** corrected the import and the call.
- **Files modified:** `app/Domain/Shared/Money/Money.php`
- **Committed in:** `3a6c8fa`

**3. [Rule 1 - Bug] Page tests must satisfy the supplier tab too**
- **Found during:** Task 1
- **Issue:** the page saves every group, and the supplier group has required fields, so a defaults-only `fillForm` failed on `supplier.company_name`.
- **Fix:** the new page tests fill a fictional supplier state alongside the defaults.
- **Files modified:** `tests/Feature/Operations/SettingsPageTest.php`
- **Committed in:** `3a6c8fa`

---

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs)
**Impact on plan:** No scope change; the plan's contracts are intact. One extra small class (`KnownCurrency`) beyond the planned file list.

## Issues Encountered

- The first RED run showed a failing storage test that was a test bug (the `payload` column returns JSON text, not an array); fixed before the RED commit, so the RED set contains only the intended failures.
- Acceptance greps containing `$amount` / `$currency` need escaping (`\$`) in the agent's shell to match; the checks themselves pass.

## Known Stubs

None.

## Threat Flags

None. T-03-11 is mitigated and test-covered (`fromMajor` refuses excess decimals; data layer ties the rate currency to `default_currency`); T-03-12 is inherited from 03-03/03-04 (the new group is stored through the same deny-all `SettingsProperty` scope behind the Admin-only page); T-03-SC: no package installed, `composer.lock` unchanged.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording under `kokpit.settings.defaults.*` and the tab label "Výchozí hodnoty"; it is discretionary placeholder-quality wording.
- Optional UX pass: the currency select lists every ISO 4217 code the library knows (including funds and test codes); consider narrowing it to circulating currencies.

## Next Phase Readiness

- Ready for 03-06 onwards: tab methods are inserted into `SettingsPage::form()` and `SETTINGS` in the final order (supplier, bank accounts, invoicing, defaults, online payments); `defaults` currently sits directly after `supplier`.
- Phase 6 rate resolution and Phase 10 invoicing can read `app(DefaultsSettings::class)->default_hourly_rate` as a `Money`.

## Self-Check: PASSED

- All five created files exist on disk.
- Commits `3a6c8fa`, `58075e0`, `363551c` and `1364d77` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 4.
- Acceptance greps for both tasks pass; `ddev composer ci` (Pest 480, Pint, PHPStan level 8, licence check) is green.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
