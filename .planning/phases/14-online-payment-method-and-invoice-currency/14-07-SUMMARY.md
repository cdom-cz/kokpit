---
phase: 14-online-payment-method-and-invoice-currency
plan: 07
subsystem: invoicing
tags: [payment-method, bank-account, card-switch, currency, payment-link, issued-edit]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 02)
    provides: open card switch, set-once pay token, bank_account_id on the Admin-only row, OA-7 issued-fields check
  - phase: 14-online-payment-method-and-invoice-currency (plan 03)
    provides: the union currency list in IssuedDocumentRules::currencyChoices()
  - phase: 14-online-payment-method-and-invoice-currency (plan 04)
    provides: DocumentPayment, the error keys, InvoiceSnapshot::bankAccount()
  - phase: 14-online-payment-method-and-invoice-currency (plan 05)
    provides: the pay-URL gate PayUrl::forInvoice() that answers null after a switch-off
provides:
  - DocumentPayment::forIssued(), the method, account, switch, token and snapshot rule of an open issued document
  - UpdateIssuedDocument keys payment_method, bank_account_id, card_payment_enabled
  - PaymentLinks::deactivateLive(), the second entry point of the link lifecycle
  - IssuedEditFinisher::finish(..., forceNewLink)
  - a currency change that needs no account of the new currency
affects: [14-08, 14-09, 14-10, 14-11]

plan_head_before: fa40d05bbd4dabd761126c808dcd7bc5c5282184
plan_head_after: 83b81e12330af72fe5fa8350efe2a7ac96afbd06

actuals:
  tokens: 11900
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Validate first, write after: forIssued() throws before the row, the Admin row or any link is touched"
    - "Link follows the edit: deactivateLive() before the finisher, the finisher opens one link under the same document lock"

key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Support/DocumentPayment.php
    - app/Domain/Invoicing/Actions/UpdateIssuedDocument.php
    - app/Domain/Invoicing/Support/IssuedEditFinisher.php
    - app/Domain/Payments/Support/PaymentLinks.php
    - tests/Feature/Payments/LinkLifecycleTest.php
    - tests/Feature/Invoicing/IssuedHeaderEditTest.php
    - tests/Feature/Invoicing/IssuedCurrencyChangeTest.php

key-decisions:
  - "An account sent as null on a transfer document is refused as bank_account_required even when nothing else asks for a new snapshot"
  - "The account id recorded on the Admin-only row is the one actually used for the snapshot, so a refill that fell back to the first account of the currency also records that account"
  - "UpdateIssuedDocument no longer holds InvoiceSnapshot (unused after the move); it holds PaymentLinks instead"

patterns-established:
  - "Online carries no bank snapshot and clears an existing one in the same row save as the method change (OA-7)"

requirements-completed: [P14-06, P14-07, P14-08]

coverage:
  - id: D1
    description: "An open issued transfer invoice with an active card link is switched to online: no bank snapshot, the old link deactivated as replaced (and at Stripe), one new link for the remaining amount in the document currency on the same pay URL, the PDF replaced and without the IBAN"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/LinkLifecycleTest.php#a change of the payment method (Phase 14) it switches an open transfer invoice to online"
        status: pass
    human_judgment: false
  - id: D2
    description: "Back to transfer takes the snapshot of the stored account and resets the switch to the client flag unless sent; the switch alone deactivates as manual and turning it on opens a link although the newest one was manual; the pay token never changes"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/LinkLifecycleTest.php#a change of the payment method (Phase 14)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The account is chosen by id (any currency), refused as null or unknown, the snapshot comes from the settings only; online and the switch refuse when card payment is unavailable; a locked document refuses all three keys and accepts them after the unlock"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedHeaderEditTest.php#the payment method and the card switch (Phase 14)"
        status: pass
    human_judgment: false
  - id: D4
    description: "A recorded payment blocks only the currency change; method and account may change"
    requirement: "P14-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedHeaderEditTest.php#the payment method and the card switch (Phase 14) accepts the method and the account of a document with a recorded payment, and still refuses its currency"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedCurrencyChangeTest.php#the account no longer depends on the currency (Phase 14) still refuses the currency of a document with a payment, whatever the account"
        status: pass
    human_judgment: false
  - id: D5
    description: "A currency change needs no account of the new currency: the first account of the currency is preselected, an explicit account of another currency wins, a transfer document with none is refused as bank_account_required, an online document needs none, and the new link opens in the new currency"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedCurrencyChangeTest.php#the account no longer depends on the currency (Phase 14)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedCurrencyChangeTest.php#a list or an account that is missing"
        status: pass
    human_judgment: false

duration: 40min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 07: Method, account, card switch and currency of an open issued document Summary

**The Admin changes how an open issued document is paid (transfer or online, which account, card switch on or off) and in which currency, and the Stripe link follows on the same pay URL: the old link is deactivated as replaced or manual, one new link for the remaining amount is opened after the commit.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 7 modified (4 production, 3 test), plus this SUMMARY

## Accomplishments

- `DocumentPayment::forIssued()` computes in the ruled order: method (online needs card payment available), account (data, else the first account of the new currency on a currency change, else the stored one), switch (online forces on; transfer takes the Admin's boolean, else the client flag when the document was online, else the stored value; false to true needs card payment available), snapshot (none for online, taken from the settings for transfer when the document was online, the account or the currency changed or `reload_bank` was true, else the issued one stays) and the token (generated once). `saveAdmin()` writes the token only when none is stored.
- `UpdateIssuedDocument` accepts `payment_method`, `bank_account_id`, `card_payment_enabled`; `currencyChange()` no longer looks up an account; `reload_bank` moved into `forIssued()`. A method change deactivates the live link as `replaced`, a switch turned off as `manual`, both before the finisher, under the document lock.
- `IssuedEditFinisher::finish(..., $forceNewLink)` skips the "newest link was switched off by hand" rule when the edit itself changed the method, turned the switch on or changed the currency.
- `PaymentLinks::deactivateLive()` deactivates the live link of a locked document with a given reason inside the caller's transaction.

## Task Commits

1. **Task 1 (tracer): forIssued, deactivateLive, forceNewLink, UpdateIssuedDocument rework** - `a720675` (feat)
2. **Task 2: back to transfer, switch-only, account and lock tests; bank-account tests moved to the by-id rule** - `807efcd` (test)
3. **Task 3: currency change without an account of its own, tests** - `83b81e1` (test)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the verify (the two test files, Pint, PHPStan) was re-run end to end after Task 1 and passed before the expansion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Plan sequencing] Production behavior for Tasks 2 and 3 was complete after Task 1**
- **Found during:** Task 2 and 3
- **Issue:** `forIssued()` and the reworked Action were written whole in Task 1 as R-14-07-1/3 specify, so the Task 2 and 3 tests passed on their first run (no production change in those commits). The new rules are exercised by the added tests, not driven by them red-first.
- **Impact:** none on behavior. Three tests (two in `IssuedCurrencyChangeTest`, one in `IssuedHeaderEditTest`) asserted the old `bank_missing` account refusal and were red between the Task 1 commit and the Task 2 and 3 commits; both are rewritten as the plan names them.

**Total deviations:** 1 (sequencing note). **Impact:** none on scope.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Isolation` - 2079 passed
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md); no new error
- `scripts/check-sensitive.sh` clean on the staged files of all three commits; lefthook sensitive-content and gitleaks hooks passed
- Acceptance greps of all three tasks pass.

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. Mitigations in place: T-14-21 (the locked-document test refuses each of the three keys under `document` and accepts them after the unlock), T-14-22 (`deactivateLive()` runs before the finisher under the document lock; tests assert exactly one live link), T-14-23 (the token is generated only when none exists; tests assert it unchanged across method and switch changes), T-14-24 (`accountId()` accepts only configured ids, the snapshot is copied from the settings; test with forged `bank_snapshot` and `bank` keys), T-14-25 (Stripe is only called by the jobs dispatched after the commit), T-14-SC (no composer change).

## Next Phase Readiness

Plan 14-08 (draft editor), 14-09 (audit of method and switch changes, proforma conversion) and 14-10 (the issued editor fields) can call the Action with the new keys. `InvoiceSnapshot::bank()` is now unused by `UpdateIssuedDocument` but still serves `EditInvoice` until plan 14-10.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Support/DocumentPayment.php (`function forIssued`)
- FOUND: app/Domain/Payments/Support/PaymentLinks.php (`function deactivateLive`)
- FOUND commits: a720675, 807efcd, 83b81e1 (ancestors of HEAD)
