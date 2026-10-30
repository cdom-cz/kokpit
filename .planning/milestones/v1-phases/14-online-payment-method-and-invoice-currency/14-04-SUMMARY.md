---
phase: 14-online-payment-method-and-invoice-currency
plan: 04
subsystem: invoicing
tags: [payment-method, bank-account, card-switch, issue-pipeline, blockers]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 01)
    provides: stable bank account ids, BankAccountSettings::find() and firstForCurrency()
  - phase: 14-online-payment-method-and-invoice-currency (plan 02)
    provides: invoices.payment_method, invoice_admin.bank_account_id, the OA-7 issued-fields check, DocumentPaymentMethod
provides:
  - DocumentPayment, the one rule of method, account and card switch of a draft
  - a new draft is always transfer with the first account of its currency preselected
  - UpdateDraft keys payment_method and bank_account_id, with the OA-1 card-switch rules
  - bank snapshot taken from the chosen account for transfer, none for online (OA-7)
  - blockers bank_missing (by chosen account) and online_unavailable
  - every error key of the phase in lang/cs/invoices.php and the Wave 0 account fixtures
affects: [14-05, 14-06, 14-07, 14-08, 14-09, 14-10, 14-11]

plan_head_before: 5a9b16b1e9dbfb56c64876e9bed0edaccb27c61a
plan_head_after: 5cc5938c73f67bd2cca418a8622fe8013533cc1d

actuals:
  tokens: 11700
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "One static rule class (DocumentPayment) read by the Actions, the blockers and the issuer, settings read through app() at call time"
    - "Validate first, write after: forDraft() throws before saveAdmin() and the row save write anything"

key-files:
  created:
    - app/Domain/Invoicing/Support/DocumentPayment.php
    - tests/Feature/Invoicing/DocumentPaymentTest.php
  modified:
    - app/Domain/Invoicing/Actions/CreateDraft.php
    - app/Domain/Invoicing/Actions/UpdateDraft.php
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Invoicing/Support/InvoiceSnapshot.php
    - app/Domain/Invoicing/Support/IssueChecks.php
    - lang/cs/invoices.php
    - tests/Support/InvoiceFixtures.php
    - tests/Feature/Payments/CardSwitchTest.php
    - tests/Feature/Invoicing/IssueDocumentTest.php
    - tests/Feature/Invoicing/IssueFlowTest.php
    - tests/Feature/Invoicing/IssuedCurrencyChangeTest.php
    - tests/Feature/Invoicing/DraftEditorTest.php

key-decisions:
  - "R-14-04-1: DocumentPayment::account() resolves a stored id by find(), treats a stored but removed id as no account (never re-picked), and falls back to firstForCurrency() only for a document that stores none"
  - "A non-boolean card_payment_enabled is refused whenever the key is present, also for an online draft where the value would be overridden"
  - "bank_missing copy lost its :currency placeholder, so the correction paths of an open issued document (UpdateIssuedDocument, EditInvoice) now show the same generic text until plans 14-07 and 14-10 rework them"

patterns-established:
  - "Credit-note draft: the draft Actions refuse payment_method and bank_account_id under their own key with credit_note_no_payment"

requirements-completed: [P14-01, P14-04, P14-09]

coverage:
  - id: D1
    description: "A new draft is transfer with the first account of its currency preselected; the Admin switches it to online, and the issue gives an online invoice without bank snapshot, with the card switch on, a pay token and a pending link"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DocumentPaymentTest.php#the tracer: a draft becomes an online invoice (D-01, D-02, D-03, D-05) it is created as transfer with the first CZK account, switched to online and issued without bank details but with a pay link"
        status: pass
    human_judgment: false
  - id: D2
    description: "The chosen account, of any currency, decides the snapshot; preselection follows list order and a currency change; a removed account is never re-picked; a currency without an account blocks transfer and not online"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DocumentPaymentTest.php#the chosen account (D-05, D-06)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Online needs card payment to be available: the change is refused, an already online draft is blocked from issuing, a proforma-converted draft is exempt"
    requirement: "P14-09"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DocumentPaymentTest.php#online needs card payment (D-03, P14-09)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Payments/CardSwitchTest.php#blocks the issue of an online draft once card payment is unavailable, and leaves it a draft that can still be saved (P14-09)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Card switch rules of a draft: forced on for online, reset to the client flag on online to transfer unless the Admin sends a value, still the Admin's choice on transfer; a credit-note draft takes neither payment key"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DocumentPaymentTest.php#online needs card payment (D-03, P14-09) resets the switch to the client flag on online to transfer, unless the Admin sends a value (OA-1)"
        status: pass
    human_judgment: false

duration: 16min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 04: Payment method and bank account of a draft Summary

**A draft is now a transfer document with its first-currency account preselected until the Admin chooses online or another account, and the issue snapshots exactly that choice: the chosen account for transfer, nothing for online, with online refused unless card payment really works.**

## Performance

- **Duration:** about 16 min
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 14 (2 created, 12 modified), plus this SUMMARY

## Accomplishments

- `DocumentPayment` (final class, static) holds the rules: `method()`, `accountId()` (only the UUID of a configured account travels), `preselect()`, `account()`, `onlineUnavailableReason()`, `forDraft()` and `saveAdmin()`.
- `CreateDraft` writes `transfer` explicitly and the preselected account next to the card switch; `UpdateDraft` replaces its private card-switch code with `forDraft()` and `saveAdmin()` and writes `payment_method` on the row.
- `DocumentIssuer` takes the bank snapshot from the chosen account (`InvoiceSnapshot::bankAccount()`, id-free), none for online and for a credit note, forces the switch on for online, and records the resolved account id on the Admin-only row of a transfer draft that had none.
- `IssueChecks`: `bank_missing` is decided by the chosen account in any currency, and the new `online_unavailable` blocker stops an online draft while card payment is unavailable (a draft with `proforma_id` is exempt).
- All error keys of the phase added to `lang/cs/invoices.php`; Wave 0 fixtures `account()`, `configureAccounts()`, `accountId()`, `online()` in `InvoiceFixtures`.

## Task Commits

1. **Task 1 (tracer): DocumentPayment, draft Actions, issuer, snapshot, copy, fixtures, tracer test** - `ea502f6` (feat)
2. **Task 2: chosen account decides the transfer blocker, account tests** - `8eda6ee` (feat)
3. **Task 3: online_unavailable blocker, OA-1 switch tests, credit-note refusals** - `5cc5938` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the verify (tests, Pint, PHPStan) was re-run end to end after Task 1 and passed before the expansion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing tests asserted the old `bank_missing` copy**
- **Found during:** Task 1 (full Invoicing and Payments run)
- **Issue:** the plan rewords `bank_missing` (no `:currency`), but besides the IssueDocumentTest row it names, four more assertions of the old text failed: `IssueFlowTest` ("names the currency of a missing bank account"), `IssuedCurrencyChangeTest` (two), `DraftEditorTest` (the editor notice). The latter three go through the correction path of an open issued document and the draft editor, which read the same key and are reworked by plans 14-07 and 14-10.
- **Fix:** the assertions now expect the new text; the `IssueFlowTest` case was renamed to "names the missing bank account of a transfer document". No production code of those paths changed.
- **Files modified:** tests/Feature/Invoicing/IssueFlowTest.php, IssuedCurrencyChangeTest.php, DraftEditorTest.php, IssueDocumentTest.php
- **Commit:** ea502f6

**2. [Rule 1 - Bug] PHPStan: nullsafe on a non-nullable property**
- **Found during:** Task 1
- **Issue:** `$admin?->card_payment_enabled ?? false` reported `nullsafe.neverNull`.
- **Fix:** `$admin instanceof InvoiceAdmin && $admin->card_payment_enabled`.
- **Commit:** ea502f6

**3. [Plan sequencing] Tests written after the code, not red first**
- `DocumentPayment` was written complete in Task 1 (the plan assigns the whole class to it), so the Task 2 and Task 3 tests passed on their first run; the `IssueChecks` halves were still added in their own task commits.

**4. [Test shape] Proforma exemption asserted in memory**
- The conversion is plan 14-09; the blocker exemption is asserted by setting `proforma_id` on a loaded draft (not saved) and calling `IssueChecks::blockers()`.

**Total deviations:** 4 (1 blocking test update, 1 static-analysis fix, 2 sequencing or shape notes). **Impact:** none on scope or behavior.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Isolation` - 1504 passed
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md); no error in any file of this plan
- `scripts/check-sensitive.sh` clean on the staged files of all three commits; lefthook sensitive-content and gitleaks hooks passed
- All acceptance criteria of the three tasks pass (the `bankAccount(BankAccount $account)` grep needs `grep -F` on macOS because of the `$`).

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. Mitigations in place: T-14-12 (`accountId()` accepts only the UUID of a configured account; tests with a random UUID, "not-a-uuid", a number and a list), T-14-13 (the change to online is refused and `online_unavailable` blocks the issue; tests for settings off, Stripe missing, client flag off), T-14-14 (`Gate::authorize` first in both Actions, unchanged), T-14-15 (snapshot is the id-free `toArray()`, asserted by "no key id"), T-14-SC (no composer change).

## Next Phase Readiness

Plans 14-05..14-11 can read `DocumentPayment` and the error keys. `InvoiceSnapshot::bank()` and `BankAccountSettings::forCurrency()` still serve `UpdateIssuedDocument` and `EditInvoice` until plans 14-07 and 14-09/14-10 move them.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Support/DocumentPayment.php
- FOUND: tests/Feature/Invoicing/DocumentPaymentTest.php
- FOUND commits: ea502f6, 8eda6ee, 5cc5938 (ancestors of HEAD)
