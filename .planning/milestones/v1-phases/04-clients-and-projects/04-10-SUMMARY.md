---
phase: 04-clients-and-projects
plan: 10
subsystem: ui
tags: [filament, typed-settings, spatie-settings, defaults, enums]

requires:
  - phase: 04-clients-and-projects
    provides: ClientResource, CreateClient/UpdateClient Actions, ClientInput, InvoiceLanguage enum (04-09)
  - phase: 03-operations-foundation
    provides: typed settings groups, SettingsMigration base, settings page (Defaults, Invoicing, Payments, Supplier)
provides:
  - DefaultsSettings::$default_invoice_language (typed enum setting, initial cs) with its own settings migration and a Defaults tab select
  - New client form pre-filled from the typed defaults (currency, rate, terms, language, online payment, country, stage)
  - CreateClient fills any missing key from the same defaults and copies them into the client row once
affects: [04-11 client archive and uniqueness, 04-12 client list screens, phase 7 invoicing, phase 8 invoice language]

actuals:
  tokens: 5200
  tasks: 2
  commits: 2
plan_head_before: 69a11d869a1e82604192c89acc2d8d9fbc5836bd
plan_head_after: 501def2ebfcb9448e3a74252238595a98325b230

tech-stack:
  added: []
  patterns:
    - "A new default on an existing settings group goes into a NEW settings migration file (extends SettingsMigration); the original group migration is never edited"
    - "Form defaults are closures over the typed settings; the Action repeats the fallback for callers that bypass the form; UpdateClient has no settings import"
    - "A Filament Select over an enum holds the enum case as state, so form-state assertions compare against the case"

key-files:
  created:
    - database/settings/2026_10_09_000100_add_default_invoice_language.php
    - tests/Feature/Clients/ClientDefaultsTest.php
  modified:
    - app/Domain/Settings/Settings/DefaultsSettings.php
    - app/Filament/Pages/SettingsPage.php
    - app/Filament/Resources/ClientResource.php
    - app/Domain/Clients/Actions/CreateClient.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/SettingsGroupsTest.php
    - tests/Feature/Operations/SettingsPageTest.php

key-decisions:
  - "The default rate is applied by CreateClient only when the client currency equals the default currency; for another currency the rate stays missing and is reported as a field error rather than inventing an amount in the wrong money"
  - "A key is 'missing' only when absent from the Action input; a present but empty value (for example an empty rate) is the caller's value and is validated, never silently replaced by a default"
  - "The country default comes from SupplierSettings::$country, an empty supplier country leaves the form field empty"
  - "ClientData keys country, stage, currency, hourly_rate, payment_terms_days and invoice_language became optional in the Action type; the form adapter still sends all of them"

patterns-established:
  - "default_invoice_language validates with the Enum rule on the string form state and converts Select state (case or string) in fillFromFormState"

requirements-completed: [CL-01]

coverage:
  - id: D1
    description: "The Admin edits the default invoice language on the Defaults tab; it starts as Czech, persists as 'en' and is shown after a reload; an unknown value is a field error and stores nothing"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#it saves the default invoice language on the defaults tab and shows it after a reload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsPageTest.php#it refuses an unknown default invoice language on the page and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#it starts with Czech as default invoice language and stores a changed language as its value"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/SettingsGroupsTest.php#it turns an unknown invoice language into a field error and keeps the stored one"
        status: pass
    human_judgment: false
  - id: D2
    description: "A new client form opens pre-filled from every typed default (currency, rate as decimal-comma text, terms, language, online payment, country, stage) and a client can be created with only a name typed"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it opens a new client form pre-filled from every typed default"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it opens the form from the initial defaults on a fresh instance"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it creates a client from the pre-filled form with only a name typed"
        status: pass
    human_judgment: false
  - id: D3
    description: "CreateClient fills missing keys from the defaults, keeps explicit values, never invents a rate in a foreign currency and never replaces an explicitly empty rate"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it fills every missing value of the Action from the typed defaults"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it keeps a value the caller passes instead of the default"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it does not apply the default rate to a client in another currency than the default"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it does not treat an explicitly empty rate as missing"
        status: pass
    human_judgment: false
  - id: D4
    description: "D-13 copy-once: changing every default later leaves existing clients and their edit form unchanged and gives the new values only to later clients; UpdateClient never reads the settings"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it leaves existing clients unchanged when every default changes later and gives the new values to later clients"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it never reads the settings when a client is updated"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientDefaultsTest.php#it keeps an edited client form on its stored values after the defaults changed"
        status: pass
    human_judgment: false
  - id: D5
    description: "Look and Czech wording of the new Defaults tab select and its hint"
    verification: []
    human_judgment: true
    rationale: "Page tests assert state and behaviour, not layout or the adequacy of the Czech copy"

duration: 15min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 10: Client Typed Defaults Summary

**New clients start from the company's typed defaults (currency, rate, terms, invoice language, online payment, country, stage) through a new enum setting `default_invoice_language`, a settings-page select, form default closures and a CreateClient fallback that copies the values into the row once**

## Performance

- **Duration:** 15 min
- **Started:** 2026-10-08T13:50:00Z
- **Completed:** 2026-10-08T14:05:00Z
- **Tasks:** 2
- **Files modified:** 9 (2 created, 7 modified)

## Accomplishments
- `DefaultsSettings::$default_invoice_language` (enum `InvoiceLanguage`, initial `cs`) with rule, form-state and fill support, a NEW settings migration, and a select on the Defaults tab with Czech label and hint; the original defaults migration is untouched.
- The create client form takes currency, the rate as decimal-comma text, payment terms, invoice language, the online-payment flag and the country from the typed settings, stage stays `active`.
- `CreateClient` fills every key the caller leaves out from the same settings; the default rate is applied only in the default currency; present but empty values are validated, not replaced.
- D-13 proven by tests: after every default changes, existing clients (row and edit form) stay as stored and a later client gets the new values; `UpdateClient` has no settings import and a rename leaves all terms as stored.

## Task Commits

1. **Task 1: Tracer - default invoice language setting and prefilled client form** - `9beec38` (feat)
2. **Task 2: CreateClient typed defaults fallback and copy-once tests** - `501def2` (feat)

**Plan metadata:** the docs commit following this summary (docs: complete plan)

## Files Created/Modified
- `database/settings/2026_10_09_000100_add_default_invoice_language.php` - adds `defaults.default_invoice_language` = `cs`
- `app/Domain/Settings/Settings/DefaultsSettings.php` - typed enum property, Enum rule, form state, fill with field error
- `app/Filament/Pages/SettingsPage.php` - Defaults tab select over the enum
- `app/Filament/Resources/ClientResource.php` - `default()` closures from the typed settings
- `app/Domain/Clients/Actions/CreateClient.php` - `withDefaults()` fallback, optional keys in `ClientData`
- `lang/cs/kokpit.php` - label, hint and error text of the new setting
- `tests/Feature/Clients/ClientDefaultsTest.php` - 12 cases for the form prefill, the Action fallback and the copy-once rule
- `tests/Feature/Operations/SettingsGroupsTest.php`, `SettingsPageTest.php` - new key in the form-state arrays plus tests for the new setting

## Decisions Made
- Default rate only in the default currency: otherwise the amount would be in the wrong money; the rate then fails as a field error (see key-decisions).
- Missing means "key absent"; an empty submitted value stays the caller's value and is validated.
- The form defaults and the Action fallback are two small readers of the same typed settings (the plan's structure); `UpdateClient` reads nothing.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test assertions on enum-backed Select state**
- **Found during:** Task 1
- **Issue:** `assertFormSet` on `invoice_language` and `stage` failed with the string `'en'`/`'active'` because a Filament Select over an enum holds the enum case as state.
- **Fix:** the test compares against `InvoiceLanguage::*` and `ClientStage::*`.
- **Files modified:** tests/Feature/Clients/ClientDefaultsTest.php
- **Committed in:** 9beec38

**2. [Rule 2 - Missing critical] Foreign-currency client must not get the default rate**
- **Found during:** Task 2
- **Issue:** filling `hourly_rate` blindly from the default would store an amount typed in the default currency as an amount in another currency.
- **Fix:** the fallback applies the rate only when the client currency equals the default currency; otherwise the existing "rate required" field error applies. Test added.
- **Files modified:** app/Domain/Clients/Actions/CreateClient.php, tests/Feature/Clients/ClientDefaultsTest.php
- **Committed in:** 501def2

**3. [Process] Task 2 RED phase not executed separately**
- **Found during:** Task 2
- **Issue:** the Action tests were written before the implementation but not run on their own before it; the Task 1 prefill tests had a genuine RED run (3 failures) before the form defaults were added.
- **Impact:** none on behaviour; all Task 2 cases pass against the final code.

---

**Total deviations:** 3 (1 bug in test expectations, 1 missing critical, 1 process note)
**Impact on plan:** No scope creep. `ClientData` type keys became optional as a direct consequence of the fallback.

## Issues Encountered
- None. The DDEV file sync lag was handled by short waits before test runs.

## User Setup Required
None - no external service configuration required.

## Known Stubs
None.

## Threat Flags
None - T-04-21 mitigated (values copied at creation, `UpdateClient` has no settings import, the tests prove existing rows and the edit form unchanged); T-04-SC accepted (no package added).

## Next Phase Readiness
- The settings page and the client form share one set of typed defaults; later plans (client archive, uniqueness, CZ checksum, ARES) build on a client form that already opens with real values.
- Invoice rendering in later phases can read `Client::$invoice_language`, which is always set for new clients.

## Self-Check: PASSED

- All created files exist; commits `9beec38` and `501def2` are ancestors of HEAD.
- Full suite 1323 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` clean on both commits; the original defaults migration is unchanged.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
