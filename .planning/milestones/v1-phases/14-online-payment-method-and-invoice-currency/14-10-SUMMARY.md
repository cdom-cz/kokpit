---
phase: 14-online-payment-method-and-invoice-currency
plan: 10
subsystem: invoicing
tags: [issued-editor, payment-method, bank-account, card-switch, architecture-test, filament]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 07)
    provides: UpdateIssuedDocument keys payment_method, bank_account_id, card_payment_enabled, reload_bank and their refusals
  - phase: 14-online-payment-method-and-invoice-currency (plan 08)
    provides: the draft-mode Selects, paymentMethodHelper(), bankAccountOptions(), the preselection in both currency paths, the copy keys
  - phase: 14-online-payment-method-and-invoice-currency (plan 09)
    provides: no caller of the old snapshot method left outside the editor
provides:
  - the payment method, the bank account and the card toggle in the open issued editor, with the rules of the draft editor
  - issuedValues() passing the method, the switch and an account only when the Admin changed it
  - the bank section of the issued editor (printed account, account the save will print, online line) and the refill by chosen account
  - deletion of BankAccountSettings::forCurrency(), InvoiceSnapshot::bank(), EditInvoice::bankAccountFor() / bankAccountState()
  - tests/Arch/BankAccountByIdTest.php, the confinement of account lookups by currency
affects: [14-11]

plan_head_before: d35c0a8c567b3bd235b03843012393ab4594e295
plan_head_after: d423a20e04b6308a179ec91c8a4d436975bc7a3c

actuals:
  tokens: 8800
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "The form is advisory, the Action is the authority: the issued editor passes method, switch and a changed account, UpdateIssuedDocument re-checks every one under the row lock"
    - "Send an account only when it changed: a document whose stored account was removed, or a legacy one without an id, keeps its printed snapshot on an unrelated save"
    - "Architecture test with a file-local scanner and a vacuity self-check, in the style of InvoiceUnlockPathTest"

key-files:
  created:
    - tests/Arch/BankAccountByIdTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - lang/cs/invoices.php
    - app/Domain/Settings/Settings/BankAccountSettings.php
    - app/Domain/Invoicing/Support/InvoiceSnapshot.php
    - tests/Feature/Invoicing/IssuedEditorTest.php
    - tests/Feature/Invoicing/IssuedHeaderEditTest.php
    - tests/Feature/Operations/BankAccountSettingsTest.php

key-decisions:
  - "The refill action marks reload_bank only when the account the save will print resolves: the chosen configured account, or, with none chosen and none filled, the first account of the currency; a cleared field on a document that had an account notifies bank_account_required, because the Action would refuse it"
  - "The danger hint of an empty account field is a draft-only line; an issued document keeps its printed account whatever the field shows, and the bank section says what the save prints"
  - "The issued fill shows the switch the document stores (R-14-10-1), where a draft shows it off while card payment is unavailable"
  - "The refill action is hidden while the form method is online, because an online document prints no bank details"

patterns-established:
  - "filledAccountId(): the stored Admin-only account id while the settings still hold it, else none; the fill and the 'did the Admin change it' test of the save use the same rule"

requirements-completed: [P14-04]

coverage:
  - id: D1
    description: "The Admin opens an unsent card-off transfer invoice, switches it to online, saves, and the detail shows Online platba kartou, no bank rows, a new PDF and one pending card link"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#the payment method in the issued editor (Phase 14) tracer: switches an unsent card-off transfer invoice to online"
        status: pass
    human_judgment: false
  - id: D2
    description: "Back to transfer on the second account: the toggle follows the client flag, the new snapshot is the chosen account; the card switch can be turned off and on; the online option is disabled with the reason, a crafted online value and a cleared account are refused under their fields and nothing is written"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#the payment method in the issued editor (Phase 14)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A currency change preselects the first account of the new currency, any account can be picked afterwards, and a document whose stored account was removed keeps its printed account on an unrelated save"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#the payment method in the issued editor (Phase 14) it preselects the first account of the new currency"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#the payment method in the issued editor (Phase 14) it keeps the printed account on an unrelated save when the stored account was removed from the settings"
        status: pass
    human_judgment: false
  - id: D4
    description: "The refill re-snapshots the chosen account as the settings hold it now, falls back to the first account of the currency when the stored account was removed, and notifies bank_account_required when nothing resolves"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedHeaderEditTest.php#the bank account"
        status: pass
    human_judgment: false
  - id: D5
    description: "No application code looks a bank account up by currency except DocumentPayment::preselect() and the legacy fallback of DocumentPayment::account(); the old lookups do not exist"
    requirement: "P14-03"
    verification:
      - kind: unit
        ref: "tests/Arch/BankAccountByIdTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "Visual and interaction quality of the three fields and the bank section in the DDEV panel (layout, live toggle lock, section text), and the old pay URL page after a switch back to transfer with card payment off"
    requirement: "P14-06"
    verification: []
    human_judgment: true
    rationale: "The plan defers the UI check to the end of the phase (human_verify_mode end-of-phase): the human-check of the tracer verify"

duration: 40min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 10: The issued editor's payment fields and the end of the currency-to-account lookup Summary

**The open issued editor now offers the payment method, the bank account and the card toggle with the draft editor's rules, and the last currency-to-account lookups are deleted and guarded by an architecture test.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 7 modified, 1 created, plus this SUMMARY

## Accomplishments

- `EditInvoice` issued mode: the method Select, the account Select and the card toggle lose their draft-only condition (invoice and proforma only). The online option is disabled with the reason unless the document is online already; the toggle is locked for online and while card payment is unavailable.
- Fill and save: the issued fill shows the stored method, the stored switch (on for online) and the stored account id only while the settings still hold it. `issuedValues()` passes `payment_method` and `card_payment_enabled` when dehydrated and `bank_account_id` only when it differs from what the page filled, so an unrelated save on a document with a removed or legacy account keeps its printed snapshot (T-14-34). The `bank` error branch of `updateIssued()` is gone; refusals show under `payment_method`, `bank_account_id` and `card_payment_enabled`.
- Bank section: shows the printed lines while nothing changes, "Po uložení se použije účet:" with the chosen account when the account, the method (online to transfer), the currency or the refill changes, and "Online úhrada: doklad bankovní údaje netiskne." for an online method. The refill takes the chosen account.
- Copy: `bank_pending` reworded, new `bank_online`, `currency_change.body` says the account is preselected by the new currency and another can be picked.
- Cleanup: `BankAccountSettings::forCurrency()`, `InvoiceSnapshot::bank()`, `EditInvoice::bankAccountFor()` and `bankAccountState()` are deleted. `tests/Arch/BankAccountByIdTest.php` forbids the call text of the deleted lookup in `app/`, limits `firstForCurrency(` to the settings class and `DocumentPayment`, asserts the deleted methods do not exist, and has a vacuity self-check plus a scanner-can-fail check.

## Task Commits

1. **Task 1 (tracer): the issued editor fields, the bank section, the refill, the copy and the tests** - `8bd2799` (feat)
2. **Task 2: the old lookups deleted and the architecture test** - `d423a20` (test)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase. The tracer's `<human-check>` is the plan's own end-of-phase UI check, so the automated part of the verify (the two test files, Pint, PHPStan) was re-run after Task 1 and passed before the expansion; the human check stays for the end of the phase.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The two currency helpers of EditInvoice were deleted in Task 1, not Task 2**
- **Found during:** Task 1 (PHPStan)
- **Issue:** after the issued bank section and the refill stopped using `bankAccountFor()` and `bankAccountState()`, PHPStan reported `method.unused`, which fails the Task 1 verify.
- **Fix:** both private methods were deleted in the Task 1 commit; Task 2 deleted the two public lookups and added the architecture test (it was red for them: two failing tests).
- **Files modified:** app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
- **Commit:** 8bd2799

**2. [Plan sequencing] Two Task 2 tests passed before the deletion**
- The vacuity self-check, the scanner-can-fail check and the `firstForCurrency(` allowlist already held (plan 14-04 had confined the callers); the deleted-lookup scan and the `method_exists` check were red until the deletion.

**3. [Design note] Refill and the empty-account hint**
- The refill is stricter than the plan text when the Admin has cleared the account field on a document that had an account (it notifies `bank_account_required`, since the Action would refuse the save), and the danger hint of the empty account field is draft-only (an issued document keeps its printed account). Both are recorded in key-decisions.

**Total deviations:** 1 auto-fixed (Rule 3), 2 notes. **Impact:** none on scope.

## Known Stubs

None.

## Threat Flags

None. Mitigations in place: T-14-33 (the editor is advisory; tests show a crafted online value and a cleared account refused under their fields with nothing written, and the Action's checks are unchanged), T-14-34 (test: an unrelated save on a document whose account was removed keeps the printed snapshot), T-14-35 (`tests/Arch/BankAccountByIdTest.php`), T-14-SC (no composer change).

## Verification

- `ddev exec vendor/bin/pest tests/Arch tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Operations tests/Feature/Schema tests/Isolation`: 2597 passed.
- `ddev exec vendor/bin/pint --test`: pass. PHPStan: only the known pre-existing error in `ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md), no new error.
- `scripts/check-sensitive.sh` clean on both commits; the lefthook sensitive-content and gitleaks hooks passed.
- Acceptance greps of both tasks pass; `grep -rn -- '->forCurrency' app tests` is empty.

## Issues Encountered

- The pre-existing PHPStan error above (deferred, out of scope).

## Next Phase Readiness

Plan 14-11 can rely on the three payment fields in both editors, on the preselection being the only currency-to-account lookup, and on the arch test guarding it. The end-of-phase human check of the tracer (issued editor in the DDEV panel, old pay URL page after a switch back to transfer with card payment off) is still open.

## Self-Check: PASSED

- FOUND: tests/Arch/BankAccountByIdTest.php
- FOUND: app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php (`filledAccountId`, `'bank_online'` in lang/cs/invoices.php)
- FOUND commits: 8bd2799, d423a20 (ancestors of HEAD)
