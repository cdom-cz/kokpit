---
phase: 14-online-payment-method-and-invoice-currency
plan: 01
subsystem: settings
tags: [bank-accounts, uuid-v7, spatie-settings, filament-repeater, livewire]

requires:
  - phase: 03-supplier-and-bank-settings
    provides: BankAccountSettings, BankAccount value object, bank tab of the settings page
provides:
  - a stable UUID v7 id per supplier bank account, assigned in save() or by the settings migration, never on read
  - several bank accounts per currency (the one-account-per-currency rule of Phase 3 D-04 is gone)
  - BankAccountSettings::find() by id and firstForCurrency() in list order (D-05 preselection rule)
  - UniqueAccountIds data-layer rule (every id a UUID, no id twice) for every writer
  - settings migration that numbers the accounts stored before the phase
affects: [14-02, 14-04, 14-07, 14-10, 14-11]

plan_head_before: b182971f412a9e3f4ca1fb21c1e43d056ef7c3ba
plan_head_after: 700374cec3b74cd4bd5b621d360fb69d41985e6a

actuals:
  tokens: 5800
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Account identity: the id lives in the settings payload (toStoredArray()) and never in the document snapshot shape (toArray())"
    - "Ids are assigned only on write (save() and the settings migration), never on read"

key-files:
  created:
    - app/Domain/Settings/Rules/UniqueAccountIds.php
    - database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php
    - .planning/phases/14-online-payment-method-and-invoice-currency/deferred-items.md
  modified:
    - app/Domain/Settings/Banking/BankAccount.php
    - app/Domain/Settings/Casts/BankAccountListCast.php
    - app/Domain/Settings/Settings/BankAccountSettings.php
    - app/Filament/Pages/SettingsPage.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/BankAccountSettingsTest.php
  deleted:
    - app/Domain/Settings/Rules/UniqueCurrencies.php

key-decisions:
  - "R-14-01-1: BankAccount gets a trailing nullable id, withId() and toStoredArray(); toArray() keeps the id-free snapshot shape so bank_fingerprint and the Partner-readable bank_snapshot do not change (T-14-11)"
  - "R-14-01-2: ids are assigned in BankAccountSettings::save() and the settings migration only; the list cast and fromArray() never create one (T-14-02)"
  - "R-14-01-5 amended: the settings migration writes through the settings repository, not migrator->update(), because the migrator reads the payload as stdClass objects and runs them through the list cast, which rejects them"
  - "forCurrency() stays as a one-line delegate to firstForCurrency() for IssueChecks, InvoiceSnapshot and EditInvoice until plan 14-10 deletes it"

patterns-established:
  - "Settings migration of a cast property: read the raw row as system, write back through SettingsRepository::updatePropertiesPayload()"

requirements-completed: [P14-03]

coverage:
  - id: D1
    description: "The Admin can save two or more bank accounts in one currency; the form and the data layer no longer enforce one account per currency"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#stores two accounts in the same currency, each with its own id"
        status: pass
    human_judgment: false
  - id: D2
    description: "An account keeps its id through a Livewire save, an edit, a reorder and the deletion of another account (OA-6)"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#keeps the id of an existing account through a Livewire save of the settings form"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#keeps the ids of the accounts through a reorder and a deletion of the Repeater"
        status: pass
    human_judgment: false
  - id: D3
    description: "Legacy accounts get ids from the settings migration; existing ids and other keys stay; a second run changes nothing; a read creates no id"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#gives the legacy accounts distinct UUID v7 ids, keeps every other key and an existing id, and changes nothing on a second run"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#reads a payload without ids twice and writes nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "find() by id, firstForCurrency() in list order, and a malformed or duplicate id refused for every writer with nothing stored"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#finds an account by its id and returns null for an unknown id"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#finds the first account of a currency in list order and returns null for a currency without one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/BankAccountSettingsTest.php#refuses a malformed or duplicate account id written outside the form and changes nothing"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 01: Stable bank account ids Summary

**Every supplier bank account now carries a stable UUID v7 id (assigned on save or by a settings migration, never on read) and several accounts may share a currency, with find() by id and firstForCurrency() as the lookups.**

## Performance

- **Duration:** 25 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 9 (7 modified or created in code, 1 deleted, plus the SUMMARY and deferred-items notes)

## Accomplishments

- `BankAccount` has a trailing nullable `id`, `withId()` and `toStoredArray()`; `toArray()` is unchanged and never carries the id, so the bank snapshot copied onto documents and its fingerprint stay as they were.
- `BankAccountSettings::save()` assigns `Str::uuid7()` to every account without an id, `rules()` uses the new `UniqueAccountIds`, and `UniqueCurrencies` with its message key is deleted (D-04 overrides Phase 3 D-04).
- The bank Repeater carries `Hidden::make('id')`, and the currency select no longer has `distinct()`. The Wave 0 Livewire round-trip test (OA-6) proves the id survives a save, an edit, a reorder and a deletion.
- `database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php` numbers the accounts stored before the phase, keeps existing ids and every other key, and is idempotent.
- `find()` and `firstForCurrency()` added; `forCurrency()` is a one-line delegate with a docblock naming its remaining callers and plan 14-10.

## Task Commits

1. **Task 1 (tracer): id on BankAccount, assignment in save(), UniqueAccountIds, form field** - `4741978` (feat)
2. **Task 2: settings migration, find(), firstForCurrency(), delegate** - `700374c` (feat)

Tracer gate: the `<verify>` was re-run end to end after Task 1 (auto-mode off, `human_verify_mode` end-of-phase, automated-only verify) and passed, so the expansion went ahead.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Settings migration writes through the repository, not `migrator->update()`**
- **Found during:** Task 2
- **Issue:** R-14-01-5 prescribed `$this->migrator->update('bank.accounts', ...)`. The migrator reads the stored payload as stdClass objects and passes it through `BankAccountListCast::get()`, which throws "Every stored bank account must be an array". The cast would also drop every key it does not know, which breaks "every other stored key stays as stored".
- **Fix:** the migration reads the raw row through `SettingsProperty` (the base class runs it as system, so the Partner scope is lifted), adds the missing ids and writes the list back with `SettingsRepository::updatePropertiesPayload()`. Early return when `bank.accounts` does not exist is kept (`$this->migrator->exists()`).
- **Files modified:** database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php
- **Commit:** 700374c

**2. [Rule 1 - Test expectation] Payload comparisons use equality, not identity**
- **Found during:** Task 2
- **Issue:** PostgreSQL `jsonb` reorders object keys, so a byte-for-byte or `toBe` comparison of a re-read payload fails although nothing changed.
- **Fix:** the migration and no-id-on-read tests compare decoded payloads with `toEqual`.
- **Files modified:** tests/Feature/Operations/BankAccountSettingsTest.php
- **Commit:** 700374c

**3. [Plan sequencing] "Every key" test updated in Task 1, not Task 2**
- The test "writes every key for every account..." fails the moment the stored payload gains the id, so its key list got `id` in Task 1 to keep the suite green per commit. Its other Task 2 rewrites (lookups, no id on read) landed in Task 2 as planned.

**Total deviations:** 3 (1 blocking fix, 1 test-expectation fix, 1 sequencing). **Impact:** none on scope or behavior; the migration meets every behavior of R-14-01-5.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Operations` - 401 passed
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Arch` - 788 passed (callers of the `forCurrency()` delegate unaffected)
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, in `app/Console/Commands/ResetAdminTwoFactorCommand.php` (`function.alreadyNarrowedType`), a file Phase 14 does not touch and that is unchanged since before this plan; logged in `deferred-items.md`. No error in any file of this plan.
- `scripts/check-sensitive.sh` clean on the staged files of both commits; lefthook sensitive-content and gitleaks hooks passed.
- All acceptance criteria of both tasks pass.

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. The mitigations of the plan's threat model are in place: T-14-01 (`UniqueAccountIds` for every writer, test "refuses a malformed or duplicate account id written outside the form"), T-14-02 (no id on read, test "reads a payload without ids twice and writes nothing"), T-14-03 (IBANs composed at runtime, labels "Example ..."), T-14-11 (`toArray()` has no `id`, asserted in the Wave 0 test).

## Next Phase Readiness

Plans 14-02 (document-side `bank_account_id`), 14-04 (`DocumentPayment::preselect()` via `firstForCurrency()`), 14-07 and 14-10 (deletes `forCurrency()` and adds `tests/Arch/BankAccountByIdTest.php`) can build on `find()` and `firstForCurrency()`. Plan 14-11 migrates the development database, which includes the settings migration of this plan.

## Self-Check: PASSED

- FOUND: app/Domain/Settings/Rules/UniqueAccountIds.php
- FOUND: database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php
- MISSING AS INTENDED: app/Domain/Settings/Rules/UniqueCurrencies.php
- FOUND commits: 4741978, 700374c (both ancestors of HEAD)
