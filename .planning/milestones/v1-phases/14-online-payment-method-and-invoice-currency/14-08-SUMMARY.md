---
phase: 14-online-payment-method-and-invoice-currency
plan: 08
subsystem: invoicing
tags: [draft-editor, payment-method, bank-account, card-switch, currency, filament]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 03)
    provides: the union currency list offered by the editor
  - phase: 14-online-payment-method-and-invoice-currency (plan 04)
    provides: DocumentPayment (preselect, account, onlineUnavailableReason), UpdateDraft keys, online_unavailable blocker
  - phase: 14-online-payment-method-and-invoice-currency (plan 06)
    provides: the last reader of the old fixed method copy key is gone from the detail
provides:
  - draft-mode Select payment_method (transfer preselected, online disabled with the reason)
  - draft-mode Select bank_account_id (every configured account, hidden for online, danger hint when empty)
  - card toggle rules: forced on and locked for online, client flag on online to transfer, the Admin's choice on transfer
  - the account follows the currency in both currency paths (at once and after the typed-prices confirmation)
  - issue confirmation row "Způsob úhrady" and the online_unavailable link to the Settings
affects: [14-09, 14-10, 14-11]

plan_head_before: 6331aa80839e3a888eaf0d2c40da3361de98dad0
plan_head_after: 44f3279b7089532f04f549e816b144f6a3b75fcc

actuals:
  tokens: 5500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "The form is advisory, the Action is the authority: the disabled online option is also refused by the Select's own in-rule, and UpdateDraft re-checks it"
    - "Hidden fields are not dehydrated: the account field hides for online so an online save never overwrites the stored account"

key-files:
  created: []
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - lang/cs/invoices.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/DraftEditorTest.php
    - tests/Feature/Payments/CardSwitchTest.php

key-decisions:
  - "The empty-account notice is the field's danger hint (hint plus hintColor) with the Settings link, shown instead of the standing helper only while no account is selected"
  - "The online option is never disabled for a draft that is already online, so an online draft stays saveable after the client flag or the settings turn it off"
  - "The issue confirmation row for the method is hidden for a credit-note draft, and the method and account fields are filled only for a document that carries a payment"

patterns-established:
  - "Method rule in the form: online sets the toggle true; online to transfer sets it to the client flag AND card availability, and fills an empty account with the preselection"

requirements-completed: [P14-01, P14-04, P14-05, P14-09]

coverage:
  - id: D1
    description: "The draft editor opens on transfer with the first account of the currency, switches to online (toggle on and locked, account field hidden), saves, and issues an online invoice without a bank snapshot"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the payment method and the account (Phase 14) it opens on transfer with the first account of the currency, switches to online, saves, and issues without bank details"
        status: pass
    human_judgment: false
  - id: D2
    description: "The account follows the currency both at once and after the typed-prices confirmation, any configured account can be picked, and a currency without an account leaves the field empty with the danger hint and blocks the issue"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the payment method and the account (Phase 14)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The online option is disabled with the reason (client flag, settings, Stripe) and a crafted online value is refused under the field; the toggle follows the client flag on online to transfer"
    requirement: "P14-09"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the payment method and the account (Phase 14) it disables the online option with the reason, and refuses a crafted online value under the field"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the payment method and the account (Phase 14) it sets the toggle to the client flag when an online draft goes back to transfer"
        status: pass
    human_judgment: false
  - id: D4
    description: "Visual and interaction quality of the new fields in the DDEV panel (layout, hint colour, live toggle lock, account following the currency)"
    requirement: "P14-01"
    verification: []
    human_judgment: true
    rationale: "The plan defers the UI check to end of phase (human_verify_mode end-of-phase): the human-check of the tracer verify"

duration: 31min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 08: Payment method, account and card toggle in the draft editor Summary

**The draft editor now offers the method (transfer or online), the bank account (any configured one, preselected by currency) and the card toggle as independent choices, with online forcing and locking the toggle and disabled with a reason when card payment is not available.**

## Performance

- **Duration:** about 31 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 5 modified, plus this SUMMARY

## Accomplishments

- `EditInvoice` draft mode: `Select::make('payment_method')` (enum options, `live()`, online disabled by `onlineUnavailableReason()` unless the draft is online already, helper from `paymentMethodHelper()`) and `Select::make('bank_account_id')` (`bankAccountOptions()`, placeholder "Bez bankovního účtu", danger hint with the Settings link while empty, hidden for online). The static method and bank `TextEntry` rows are gone.
- Toggle rules: disabled while the method is online or card payment is unavailable, helper `card_payment_online` for online; `methodChosen()` sets it on for online and, from online to transfer, to the client flag ANDed with `CardPaymentSwitch::available()`, also filling an empty account with `DocumentPayment::preselect()`.
- `currencyChosen()` and `changeCurrencyAction()` both set `bank_account_id` to `DocumentPayment::preselect(<new currency>)`, so the account follows after the confirmation dialog of typed prices too (research Pitfall 10); a currency without an account leaves the field empty.
- Fill and save: `mutateFormDataBeforeFill()` adds `payment_method` and `bank_account_id` (via `DocumentPayment::account()`), the toggle is filled on for an online draft; the draft save passes both keys when they were dehydrated.
- Issue confirmation: new "Způsob úhrady" row, `cardPaymentAtIssue()` true for an online draft, and `online_unavailable` links to the Settings like `bank_missing`.
- Copy: new `invoices.draft.helpers.*` entries and `issue.summary.payment_method`, the card helper reworded for OA-1, the old fixed method value key removed with its last reader, and the client-form hint gains the sentence about choosing online on a document.

## Task Commits

1. **Task 1 (tracer): the fields, the method rule, fill and save, the issue confirmation and the copy** - `b5de099` (feat)
2. **Task 2: the account follows the currency in both paths, the disabled online option and the toggle are tested, the client hint** - `44f3279` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase. The tracer's `<human-check>` is the plan's own end-of-phase UI check, so the automated part of the verify (DraftEditorTest, Pint, PHPStan) was re-run after Task 1 and passed before the expansion; the human check stays for the end of the phase.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] CardSwitchTest copy assertions moved into Task 1**
- **Found during:** Task 1 (Invoicing and Payments run)
- **Issue:** the plan rewords the card helper (`Po vystavení už nejde změnit.` becomes `Do odeslání dokladu jde platbu kartou vypnout nebo zapnout.`) in Task 1, but names `CardSwitchTest.php` only in Task 2; the existing standing-helper assertion failed at the Task 1 commit.
- **Fix:** the two assertions of the old copy in `CardSwitchTest.php` now use the new copy (the negative one kept meaningful) in the Task 1 commit.
- **Files modified:** tests/Feature/Payments/CardSwitchTest.php
- **Commit:** b5de099

**2. [Plan sequencing] Task 2 rules mostly landed with Task 1**
- The disabled online option with its reasons, `bankAccountOptions()` and the toggle lock are needed by the Task 1 fields themselves, so they were written in Task 1. In Task 2 only the two currency-path preselections, the `currencyOptions()` docblock and the client hint were missing (RED: the two currency tests and the hint test); the other new Task 2 tests passed on their first run and guard the rules.

**3. [Copy] Existing editor test updated to the new empty-account text**
- `DraftEditorTest` "shows the danger message with a link to the settings when the currency has no bank account" now expects `bank_account_missing` (plan R-14-08-5) instead of the generic `bank_missing` text; the Settings link and the `fi-color-danger` assertions are unchanged.

**Total deviations:** 1 auto-fixed (Rule 3), 2 sequencing or copy notes. **Impact:** none on scope; no production code outside `EditInvoice.php` and the copy files changed.

## Known Stubs

None.

## Threat Flags

None. The editor stays Admin-only; the account labels reach only the Admin page, and the Action still re-checks the method (T-14-26) and the account id (T-14-27).

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Isolation`: 1553 passed.
- `ddev exec vendor/bin/pint --test`: pass. PHPStan: only the known pre-existing error in `ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md), no new error.
- `scripts/check-sensitive.sh` clean on both commits.
- Acceptance criteria of both tasks re-run and passing (greps, `payment_method_value` has no reader left, 3 `DocumentPayment::preselect` uses).

## Next Phase Readiness

Plan 14-10 opens the same three fields in issued mode and deletes `BankAccountSettings::forCurrency()`; `bankAccountFor()` and `bankAccountState()` in `EditInvoice` are kept for the issued-mode bank rows until then.

## Self-Check: PASSED

All five modified files exist; commits `b5de099` and `44f3279` are in the branch history.
