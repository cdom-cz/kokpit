---
phase: 03-operations-foundation
plan: 07
subsystem: settings
tags: [spatie-laravel-settings, filament, repeater, iban, bank-accounts, validation, czech-ui]
type: execute

requires:
  - phase: 03-operations-foundation
    provides: ValidatedSettings, SettingsMigration, SettingsPage with tabs and one transactional Save, Money::isoCurrencyCodes and KnownCurrency (plans 03-03 to 03-06)
provides:
  - Iban (own country-length table, mod-97 check, check-digit composition; no library)
  - BankAccountFormat enum (europe_1, europe_2, world) with visibleFields() and Czech labels
  - BankAccount readonly value object (tolerant fromArray, uniform toArray, normalised())
  - BankAccountListCast, IbanRule, UniqueCurrencies
  - BankAccountSettings (group bank) with fieldRules(), forCurrency() and a per-format save validation
  - Bank accounts tab (second tab) on the Czech settings page
affects: [03-09 numbering section, Phase 10 invoices (account by client currency, SPAYD QR from the IBAN)]

plan_head_before: 9c137224579426df46b361464f966aed15d1eebb
plan_head_after: 29b664461d55b672d77fe4987628d90a7c1483d7

actuals:
  tokens: 11000
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Per-format field rules live in one static method (BankAccountSettings::fieldRules) that both the form (rules(Closure) reading the item's format) and save() use, so form and data layer cannot drift"
    - "A Filament field evaluates every closure placed directly in rules([...]) and cannot resolve a rule closure's $attribute; wrap such rule lists in fn (): array => ... or pass rule objects"
    - "A Filament select over an enum hands the enum case back inside Get as well, so conditional visible()/required() closures must accept the case and a string"
    - "Repeater items from fillForm/getState arrive keyed by list index in this setup; fillFromFormState() re-indexes with array_values"

key-files:
  created:
    - app/Domain/Settings/Banking/Iban.php
    - app/Domain/Settings/Banking/BankAccountFormat.php
    - app/Domain/Settings/Banking/BankAccount.php
    - app/Domain/Settings/Casts/BankAccountListCast.php
    - app/Domain/Settings/Rules/IbanRule.php
    - app/Domain/Settings/Rules/UniqueCurrencies.php
    - app/Domain/Settings/Settings/BankAccountSettings.php
    - database/settings/2026_10_08_000150_create_bank_account_settings.php
    - tests/Unit/Settings/IbanTest.php
    - tests/Feature/Operations/BankAccountSettingsTest.php
  modified:
    - app/Filament/Pages/SettingsPage.php
    - config/settings.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php

key-decisions:
  - "Own IBAN validation, no library (resolved open question 3): 32-country length table plus mod-97 behind IbanRule; composer.json unchanged"
  - "BankAccountSettings::rules() holds only the list-level rules (array, UniqueCurrencies); the per-format rules of one account are applied per account in save() through fieldRules() and reported on accounts.{index}.{field}, because Laravel's wildcard rules cannot branch on a sibling format without a custom data-aware rule"
  - "Validation messages for format-specific patterns (account number, bank code, BIC) are Czech strings under kokpit.settings.bank.* raised from small pattern closures, not Laravel's generic regex message"
  - "Account number pattern: Europe 1 is an optional prefix of up to 6 digits plus a dash, then 2 to 10 digits; World is 1 to 34 letters and digits; bank code is exactly 4 digits; bank name required only for World; BIC optional, 8 or 11 characters after upper-casing"
  - "Data-layer error for a duplicate currency is attached to the accounts list (not to one item), and names the upper-case code"

patterns-established:
  - "Conditional repeater fields: visible() and required() closures derive from the same format-to-fields map used for stored-shape nulling (BankAccountFormat::visibleFields())"

requirements-completed: []

coverage:
  - id: D1
    description: "The Admin adds an IBAN-only EUR account with a spaced, lower-case IBAN; it is stored normalised and shown again after a reload"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#stores one IBAN-only account in EUR with a normalised IBAN and shows it again after a reload"
        status: pass
    human_judgment: false
  - id: D2
    description: "An IBAN is accepted only for a supported country with the right length and a mod-97 remainder of 1, after normalising; refused in the form and outside it, nothing stored"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Unit/Settings/IbanTest.php (compose/isValid for every supported country, flipped digit, wrong length, unsupported country, published example)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#shows a form error on the IBAN of the repeater item and stores nothing when the checksum is broken"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses an invalid IBAN written outside the form and changes nothing"
        status: pass
    human_judgment: false
  - id: D3
    description: "Europe 1, Europe 2 and World show and require their own fields; hidden fields are stored as null even when the payload sends them; every stored account has every key"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#shows the fields each format needs and hides the others"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#saves a Europe 1 account with account number, bank code and IBAN and keeps the world-only fields empty"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#saves a World account with account number, recipient and bank and stores the IBAN and bank code as null even when sent"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#writes every key for every account and null for the fields the format hides"
        status: pass
    human_judgment: false
  - id: D4
    description: "Required fields and patterns per format (bank code four digits, account number shapes, World recipient and bank name) are refused on that field"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses a Europe 1 account without a bank code or with a three-digit bank code on that field"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses a Europe 1 account number that is not a number with an optional prefix"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses a World account without an account number, recipient name or bank name on that field"
        status: pass
    human_judgment: false
  - id: D5
    description: "One account per currency: refused in the form (distinct select) and outside the form (UniqueCurrencies, compared upper case, names the code); nothing changes"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses a second account in the same currency with a form error on its currency field"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses two accounts in the same currency written outside the form, names the currency and changes nothing"
        status: pass
    human_judgment: false
  - id: D6
    description: "BIC/SWIFT is stored upper case and a malformed one is refused (form and data layer); a payload without the hidden keys loads; forCurrency finds the account or returns null; three accounts of three formats save together and come back in order"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#stores a lower-case BIC in upper case and refuses a malformed BIC"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#loads a stored payload without the hidden keys into bank accounts"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#finds the account of a currency and returns null for a currency without one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#saves three accounts of different formats together and shows them in order"
        status: pass
    human_judgment: false
  - id: D7
    description: "Czech wording of the bank tab (labels, helper texts, error messages, format names) reads naturally"
    verification: []
    human_judgment: true
    rationale: "Label wording is a language-quality judgment no test asserts; tests only prove the keys exist"

duration: 7min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 07: Bank Accounts per Currency Summary

**Bank accounts tab on the Czech settings page: a repeater whose format (Europe 1, Europe 2, World) decides the fields, an own IBAN check (32-country length table plus mod-97, no library), one account per currency in the form and at the data layer, a uniform stored shape and `forCurrency()` for invoices**

## Performance

- **Duration:** about 7 min
- **Started:** 2026-10-08T03:03:55Z
- **Completed:** 2026-10-08T03:11Z
- **Tasks:** 2
- **Files modified:** 14 (10 created, 4 modified)

## Accomplishments

- `Iban`: normalise, supported-country length table (CZ, SK, EU/EEA, CH, GB), exact length, alphanumeric BBAN, mod-97 in 7-digit chunks, and `compose()` for runtime test fixtures. Verified against every table entry and a published documentation example assembled from fragments.
- `BankAccountSettings` (group `bank`, empty list after migrate), `BankAccountListCast`, `BankAccount` with tolerant `fromArray` (missing keys read as null, hidden fields nulled) and uniform `toArray`.
- Bank tab: repeater with label, format (live), currency (canonical ISO codes, `distinct()`), BIC, and account number, bank code, recipient, bank name, bank address, IBAN shown per `BankAccountFormat::visibleFields()`; required rules and Czech messages per format come from `BankAccountSettings::fieldRules()`, the same source `save()` uses.
- Data-layer protection (T-03-15): `UniqueCurrencies` (upper-case comparison) on the list and per-account rules in `save()`; tinker, jobs or a crafted Livewire payload cannot store a duplicate currency, a bad IBAN, a malformed BIC, or values in fields the format hides.
- Full gate green: `ddev composer ci` (Pest 565 passed, Pint, PHPStan level 8, licence check on 201 packages); `scripts/check-sensitive.sh --all` clean; no complete IBAN or account number with bank code in any file.

## Task Commits

1. **Task 1: Tracer - IBAN-only EUR account from the bank tab to the table and back** - `d6a2eee` (feat)
2. **Task 2: Three formats, one account per currency, uniform shape, lookup** - RED `da11df0` (test), GREEN `29b6644` (feat)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`; RED then GREEN commits are present and in order, no REFACTOR commit (nothing to clean up).

- **Tracer feedback gate (Task 1, automated-only verify, end-of-phase mode):** the tracer `<verify>` (Pest, Pint, PHPStan, check-sensitive) was re-run green after the fix described in Deviation 1 and before expansion.
- **RED (`da11df0`):** 19 of 25 tests failed, the other 6 passed. Semantic assessment of the failures: the Europe 1 and World required-field and pattern tests failed on "expected 1 error on `bank_code`/`account_number`/`recipient_name`/`bank_name`, got 0" (the fields did not exist yet); the visibility test failed with "a field with the name [bank.accounts.0.account_number] exists on the form" (field absent, after fixing an int-vs-string key typing mistake in the test itself); the hidden-field tests failed with "'Ignored'/'4321'/'DE31...' is not null" (hidden values were stored); the BIC tests failed on the missing BIC field/rule; the data-layer duplicate-currency test failed with "Expected a ValidationException"; `forCurrency` failed with "Call to undefined method". No failure came from setup, syntax or fixture faults. `gsd_run check tdd-red-evidence` was not run (Pest console output is not one of its supported report formats); the assessment is manual inspection of the Pest output.
- **GREEN (`29b6644`):** all 25 tests pass, then the full suite.
- **Tests green on first run** (their behaviour already existed from the tracer or is covered by the tracer's shape), proven with mutation checks, source restored after each:

| Mutation | Tests that failed |
|---|---|
| remove `IbanRule` from the data-layer IBAN rules | the form IBAN error test and the outside-the-form IBAN test |
| cast reads `$item['iban']` as a required key | the legacy-payload load test |
| cast reverses the account order | the legacy-payload load test and the three-accounts-in-order test |
| remove `->distinct()` from the currency select | the form duplicate-currency test |
| remove `UniqueCurrencies` from `rules()` | the outside-the-form duplicate-currency test |

The form part of D-04 (`distinct()`) was already in the tracer, so the form duplicate-currency test was never RED-provable; the mutation above proves it.

## Files Created/Modified

- `app/Domain/Settings/Banking/Iban.php` - length table, normalise, isValid, compose, mod-97
- `app/Domain/Settings/Banking/BankAccountFormat.php` - enum, `visibleFields()`, labels
- `app/Domain/Settings/Banking/BankAccount.php` - value object, `fromArray`, `normalised`, `toArray`
- `app/Domain/Settings/Casts/BankAccountListCast.php` - payload list to `list<BankAccount>`
- `app/Domain/Settings/Rules/IbanRule.php`, `UniqueCurrencies.php` - the two rules
- `app/Domain/Settings/Settings/BankAccountSettings.php` - settings class, `fieldRules`, `commonRules`, `forCurrency`, per-account `save()`
- `database/settings/2026_10_08_000150_create_bank_account_settings.php` - empty list default
- `app/Filament/Pages/SettingsPage.php`, `config/settings.php` - bank tab and registration (second entry)
- `lang/cs/kokpit.php`, `lang/cs/enums.php` - Czech labels, helper texts, messages, format names
- `tests/Unit/Settings/IbanTest.php`, `tests/Feature/Operations/BankAccountSettingsTest.php` - 10 unit and 25 feature cases (plus datasets)

## Decisions Made

See `key-decisions` in the frontmatter. In short: own IBAN rule, one shared per-format rule source for form and data layer, Czech pattern messages, and a documented set of field patterns where the plan left them to discretion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Conditional IBAN field was hidden at dehydration because the format came back as an enum case**
- **Found during:** Task 1 (first feature test run)
- **Issue:** `visible()`/`required()` compared `$get('format')` with string values; the Filament select over an enum returns the `BankAccountFormat` case, so the IBAN field counted as hidden, was not dehydrated, and the data layer saw no IBAN.
- **Fix:** `SettingsPage::accountFormat()` accepts the case, a string or nothing, and the visibility closures use `BankAccountFormat::visibleFields()`.
- **Files modified:** `app/Filament/Pages/SettingsPage.php`
- **Verification:** tracer and Task 2 feature tests
- **Committed in:** `d6a2eee`

**2. [Rule 3 - Blocking] Filament evaluates closure rules placed directly in `rules([...])`**
- **Found during:** Task 2 (GREEN, first run)
- **Issue:** passing `BankAccountSettings::commonRules('bic')` (which contains a rule closure with `$attribute`) straight to `->rules()` made Filament try to inject `$attribute` and throw "unresolvable".
- **Fix:** the common rules are passed as `fn (): array => ...`, the per-format ones already come from a closure; a comment in `bankTab()` records why.
- **Files modified:** `app/Filament/Pages/SettingsPage.php`
- **Committed in:** `29b6644`

**3. [Rule 2 / design] Per-format rules applied in `save()` instead of `rules()`**
- **Found during:** Task 2 design
- **Issue:** the plan's artifact table says `rules()` per format; a static `rules()` with wildcard keys cannot branch on the sibling `format` without a data-aware rule class that the plan does not list.
- **Fix:** `rules()` carries the list-level rules (`array`, `UniqueCurrencies`); `fieldRules(field, format)` is the one source for the form and for a per-account validation in `save()`, which reports errors as `accounts.{index}.{field}` before the list rules run.
- **Impact:** same behaviour and test coverage as the plan; only the shape of `rules()` differs.

**4. [Test fix] Test helper typed repeater keys as strings**
- Repeater items are keyed by list index in the Livewire test, so the key closure was widened to `int|string`; the duplicate-currency form assertion was loosened to "contains the second item's currency key" because Laravel's `distinct` flags every duplicate. Both were fixed before the RED commit.

---

**Total deviations:** 4 (1 bug, 1 blocking, 1 design, 1 test fix)
**Impact on plan:** No scope change; `rules()` is smaller than the plan describes and `save()` is overridden.

## Issues Encountered

- `requirements.ready-ids` decides whether FND-07 can be marked complete (declared by sibling plans); see the state-update step.
- A first Pest invocation with two paths printed "Test file not found" while the new directory had not yet synced into the container; a rerun was fine.

## Known Stubs

None.

## Threat Flags

None. T-03-15 (crafted payload: duplicate currency, invalid IBAN, hidden fields) is mitigated at both write paths and test-covered; T-03-16 (real bank data in files) is mitigated by runtime-composed IBANs and `check-sensitive.sh --all` clean; T-03-17 is inherited from 03-03/03-04 (Admin-only page, deny-all scope; the existing route-walk and canary tests still pass); T-03-SC: no package installed, `composer.json` and `composer.lock` unchanged.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording under `kokpit.settings.bank.*`, the tab label "Bankovní účty" and `enums.bank_account_format.*` ("Evropa 1 (číslo účtu)", "Evropa 2 (pouze IBAN)", "Svět"); it is discretionary placeholder-quality wording.
- The field patterns the plan left to discretion (documented in key-decisions) are conservative; adjust if a real account format is refused.

## Next Phase Readiness

- Ready for 03-08 and 03-09. Phase 10 can call `app(BankAccountSettings::class)->forCurrency($invoiceCurrency)` and build the SPAYD QR from `->iban`; an account in a currency the client does not use is simply not returned.

## Self-Check: PASSED

- All ten created files exist on disk.
- Commits `d6a2eee`, `da11df0` and `29b6644` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 3.
- Acceptance greps for both tasks pass (`'CZ' => 24`, `'SK' => 24`, `BankAccountSettings::class`, `UniqueCurrencies`, `function forCurrency(string`, `distinct()`; no `jschaedl` in `composer.json`); `ddev composer ci` (Pest 565, Pint, PHPStan level 8, licence check) and `scripts/check-sensitive.sh --all` are green.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
