---
phase: 14-online-payment-method-and-invoice-currency
plan: 03
subsystem: invoicing
tags: [currency, cnb, create-draft, issued-editor, filament]

requires:
  - phase: 10.1-invoice-lock
    provides: UpdateIssuedDocument::currencyChange() and IssuedDocumentRules::currencyChoices() as the issued write path
  - phase: 10-invoicing
    provides: ExchangeRate rows (CNB lists) and the cnb_missing blockers
provides:
  - App\Domain\Invoicing\Support\InvoiceCurrencies::choices(?Client, ?string) as the one currency list (CNB + accounts + client + current + CZK)
  - the create-draft modal, the draft editor and the open issued editor offering that list
  - the issued write path accepting nothing outside that list
affects: [14-09]

plan_head_before: 1970540570af508dda6e42a1303cbd65d9a6f2d3
plan_head_after: 827e34cccfac01e8fd961b341e24e12b054f313c

actuals:
  tokens: 2400
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One static list service behind every currency picker; call sites delegate and keep only their own docblock"

key-files:
  created:
    - app/Domain/Invoicing/Support/InvoiceCurrencies.php
    - tests/Feature/Invoicing/InvoiceCurrenciesTest.php
  modified:
    - app/Filament/Support/CreateDraftAction.php
    - app/Domain/Invoicing/Support/IssuedDocumentRules.php
    - tests/Feature/Invoicing/CreateDraftModalTest.php
    - tests/Feature/Invoicing/IssuedCurrencyChangeTest.php

key-decisions:
  - "R-14-03-1: the list is the distinct ExchangeRate currencies (all dates), the currency of every configured account, the client currency, the current one and CZK; upper-cased, kept only when Money::isKnownCurrency() accepts it, de-duplicated and sorted"
  - "R-14-03-2: CreateDraftAction::currencyOptions() and IssuedDocumentRules::currencyChoices() delegate, so EditInvoice::currencyOptions() and UpdateIssuedDocument::currencyChange() follow without an edit; CreateDraft and UpdateDraft still accept any known code"
  - "R-14-03-3: a CNB-only currency is offered but an issued change to it is still refused with bank_missing until plan 14-09 decouples the account"

requirements-completed: [P14-05]

coverage:
  - id: D1
    description: "The currency list is the union of CNB, account, client, current and CZK currencies, deduplicated and sorted, with CZK always present and unknown codes dropped"
    requirement: "P14-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceCurrenciesTest.php#joins the CNB, account and client currencies with CZK, once each and sorted"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceCurrenciesTest.php#never offers a code the money library cannot format"
        status: pass
    human_judgment: false
  - id: D2
    description: "The create-draft modal offers a currency only CNB publishes and creates the draft in it"
    requirement: "P14-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/CreateDraftModalTest.php#offers and creates a draft in a currency that only CNB publishes (D-07)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The open issued editor offers the same list and the write path refuses a code outside it"
    requirement: "P14-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceCurrenciesTest.php#offers the same list as the shared service"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedCurrencyChangeTest.php#refuses a currency that is not in the list of the editor"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedCurrencyChangeTest.php#offers a currency only CNB publishes and refuses it for the missing account"
        status: pass
    human_judgment: false

duration: 20min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 03: One union currency list for every invoice surface Summary

**`InvoiceCurrencies::choices()` builds the invoice currency list from CNB, the bank accounts, the client, the document and CZK, and the create-draft modal, the draft editor and the open issued editor all use it, so a draft can be created in a currency that only CNB publishes.**

## Performance

- **Duration:** about 20 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 6 (2 created, 4 modified), plus this SUMMARY

## Accomplishments

- `InvoiceCurrencies` (`final readonly class`) with `choices(?Client, ?string): list<string>`: one `ExchangeRate` query for the distinct currencies, plus the account currencies, the client currency, the current one and `CZK` (CNB publishes no CZK row); upper-cased, filtered by `Money::isKnownCurrency()`, de-duplicated, sorted.
- `CreateDraftAction::currencyOptions()` delegates; its unused bank-account imports are gone.
- `IssuedDocumentRules::currencyChoices()` delegates, so `EditInvoice::currencyOptions()` (draft and issued editor) and `UpdateIssuedDocument::currencyChange()` follow with no edit.
- Tests: the Wave 0 `InvoiceCurrenciesTest` (union, dedupe across days and sources, order, client and current currency, CZK-only fallback, unknown code dropped, issued editor equals the service), the modal tracer (USD offered and the draft created in USD), and the issued-path boundary test.

## Task Commits

1. **Task 1 (tracer): service, create-draft modal delegation, Wave 0 test, modal test** - `9d08b41` (feat)
2. **Task 2: issued editor delegation, equality and boundary tests** - `827e34c` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the tracer verify (the two test files, Pint, PHPStan) was run end to end before Task 2 and passed; expansion continued.

## Deviations from Plan

None - plan executed exactly as written. One placement detail: the issued-editor equality test lives in `InvoiceCurrenciesTest.php` and was added in Task 2 (not Task 1), so the Task 1 acceptance criterion (that file exits 0) held at the Task 1 commit; this is what the plan's Task 2 action prescribes.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing` - 723 passed
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (logged in `deferred-items.md`); none in any file of this plan
- `scripts/check-sensitive.sh` clean on both commits; lefthook sensitive-content and gitleaks hooks passed
- All acceptance criteria of both tasks pass

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. T-14-09 is mitigated (the write path re-checks against the shared list; the existing refusal test passes), T-14-10 accepted (the list is built only in Admin pages and Actions, `ExchangeRate` denies Partners), T-14-SC no composer change.

## Next Phase Readiness

A currency without a bank account is offered but still refused by the issued currency change (`bank_missing`); plan 14-09 replaces that refusal, and its test ("offers a currency only CNB publishes and refuses it for the missing account") records the boundary it must change.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Support/InvoiceCurrencies.php
- FOUND: tests/Feature/Invoicing/InvoiceCurrenciesTest.php
- FOUND commits: 9d08b41, 827e34c (both ancestors of HEAD)
