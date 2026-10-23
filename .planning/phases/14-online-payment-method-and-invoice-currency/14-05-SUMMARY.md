---
phase: 14-online-payment-method-and-invoice-currency
plan: 05
subsystem: invoicing
tags: [pdf, payment-method, card-switch, pay-url, gate, i18n]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 02)
    provides: invoices.payment_method, open card switch and set-once pay token on an open issued document, issued online document without bank snapshot
  - phase: 11-card-payments
    provides: the stable pay URL, PayLinkBlock, PartnerPayButton, PayByCardAction
provides:
  - the PDF payment box by method (online prints only the online sentence, the method, the amount due and the card button)
  - PayUrl::forInvoice() as the one gate "switch AND token" for every printer of the pay URL
  - Czech and English copy online_sentence, online_payment, online_card_lead
  - the Phase 13 hand-over sentence on PayLinkBlock (applies() / forMail() stay the one predicate)
affects: [14-06, 14-07, 14-09, 14-10, 14-11, phase-13]

plan_head_before: 113309327a57f3bac13c2a67add20c0b359f5a1d
plan_head_after: bbe7824cd038e9483f48b5cbddfc436e0f7b9582

actuals:
  tokens: 4800
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One gate for a printed URL: every printer calls PayUrl::forInvoice(), which reads the switch with the token"
    - "File-local test helper makes an online document by raw SQL on an open issued document (payment_method, bank_snapshot, switch, token)"

key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Pdf/InvoicePdfData.php
    - app/Domain/Payments/Support/PayUrl.php
    - app/Domain/Payments/Support/PayLinkBlock.php
    - app/Filament/Support/PayByCardAction.php
    - lang/cs/invoice_pdf.php
    - lang/en/invoice_pdf.php
    - tests/Feature/Invoicing/InvoicePdfVariantsTest.php
    - tests/Feature/Invoicing/InvoicePdfTest.php
    - tests/Feature/Payments/PayButtonOutputsTest.php

key-decisions:
  - "R-14-05-1: PayUrl::forInvoice() reads card_payment_enabled and pay_token in one query and returns the URL only when the switch is true and the token is non-empty; PayPageState keeps resolving a token to its document"
  - "R-14-05-2: InvoicePdfData::payment() branches on payment_method; the online branch reads nothing of bank_snapshot; cardPayment() leads with online_card_lead for an online document"
  - "The Admin pay action also checks the default stable URL before it shows (a token outlives a switch-off, an action without an address would be a dead button); a caller with its own reader (the Partner) is unchanged"

patterns-established:
  - "A printed URL has one gate; docblocks of the callers point at it"

requirements-completed: []

coverage:
  - id: D1
    description: "An online document prints the online sentence, the method, the amount due and the card button, and no account, IBAN, BIC, variable symbol, QR or bank-transfer wording"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints an online CZK document with the amount, the online sentence and the card button, and no bank detail, symbol or QR"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints an online proforma the same way"
        status: pass
    human_judgment: false
  - id: D2
    description: "A transfer document keeps its bank box; the QR only where SPAYD supports the currency and IBAN, with no warning for another account currency"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#keeps the bank box and the QR of a transfer document"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints the account rows and the QR of a CZK document paid to a Czech IBAN whatever the currency of the account itself"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints no QR for a EUR document, though its Czech IBAN would be accepted for CZK"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every amount of the payment box is in the currency of the document, in Czech and in English"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints every amount of an online EUR document in EUR, with the button and no bank detail"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoicePdfVariantsTest.php#prints the English words of an online English document"
        status: pass
    human_judgment: false
  - id: D4
    description: "The PDF button, the e-mail pay block, the Partner pay button and the Admin pay action appear only while the card switch is on and a token exists; the same token comes back with the switch"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/PayButtonOutputsTest.php#offers no URL, no PDF button, no pay block and no Partner or Admin button once the switch is off, and the same ones when it is on again"
        status: pass
      - kind: integration
        ref: "tests/Feature/Payments/PayButtonOutputsTest.php#sends the e-mail of a document whose switch is off without a pay block"
        status: pass
    human_judgment: false

duration: 35min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 05: Print what the payment method promises Summary

**An online invoice or proforma now prints only the online sentence, the method, the amount due and the card button (no account, IBAN, variable symbol or QR), and every place that prints the pay URL asks one gate, `PayUrl::forInvoice()`, which reads the card switch together with the token.**

## Performance

- **Duration:** about 35 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 9 modified, plus this SUMMARY

## Accomplishments

- `InvoicePdfData::payment()` branches on `invoices.payment_method`: the online branch reads nothing of `bank_snapshot`; the transfer branch (and a legacy null) is the existing body, with the QR still decided by `Spayd::supports(currency, iban)` and no warning for an account in another currency (D-01, D-06, D-08).
- `cardPayment()` leads with `online_card_lead` ("Zaplaťte online kartou:") for an online document and keeps `card_lead` ("Nebo zaplaťte online kartou:") for transfer; the Blade template is unchanged.
- Czech and English copy `online_sentence`, `online_payment`, `online_card_lead`; the key sets of the two files stay equal.
- `PayUrl::forInvoice()` returns the URL only while the switch is on and a token exists (T-14-16); the PDF, `PayLinkBlock`, `PartnerPayButton`, `PaymentLinkStatus` and the Admin action all go through it, and the public pay page keeps answering a printed URL.
- `PayLinkBlock` docblock records the Phase 13 hand-over (OA-5).

## Task Commits

1. **Task 1 (tracer): online payment box, card lead and copy** - `9584ec8` (feat)
2. **Task 2: the card button follows the switch** - `bbe7824` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the verify (InvoicePdfVariantsTest, InvoicePdfTest, Pint, PHPStan) was re-run end to end after Task 1 and passed before the expansion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] The Admin pay action could show as a dead button**
- **Found during:** Task 2
- **Issue:** the Admin action was visible when the newest link was active (`payable`); with the switch off and the token kept, `PayUrl::forInvoice()` is now null, so the action would render without an address.
- **Fix:** `PayByCardAction::make()` also asks the default stable URL before showing, when the caller passes no reader of its own; the Partner (own reader, already folded into its visibility closure) is unchanged.
- **Files modified:** app/Filament/Support/PayByCardAction.php (not in the plan's file list)
- **Commit:** bbe7824

**2. [Rule 1 - Bug] A query pin in InvoicePdfTest named the old one-column read**
- **Found during:** Task 2
- **Issue:** "it prints the same document whatever changes after the issue" counted `select "pay_token" from "invoice_admin"`; the gate now reads two columns.
- **Fix:** the pin names `select "card_payment_enabled", "pay_token" from "invoice_admin"` and its comment says why.
- **Files modified:** tests/Feature/Invoicing/InvoicePdfTest.php (not in the plan's file list)
- **Commit:** bbe7824

**Total deviations:** 2 auto-fixed (1 missing critical, 1 test pin). **Impact:** none on scope; both follow from the gate moving into `PayUrl::forInvoice()`.

## Phase 13 hand-over

Phase 13 rebuilds the invoice e-mail around the "Pay by card" button. The only correct predicate for that button is `PayLinkBlock::applies()` / `forMail()` (switch on AND token AND issued, uncancelled invoice or proforma that still owes money); the Phase 13 planner must not read `pay_token` directly. A token now outlives a switch-off.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Isolation` - 1513 tests, all green after the InvoicePdfTest pin fix (1512 on the first run plus the fixed one, re-run: InvoicePdfTest 52 passed)
- `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Arch` - 546 passed
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (logged in `deferred-items.md`); no new error
- `scripts/check-sensitive.sh` clean on both commits; lefthook sensitive-content and gitleaks passed
- All acceptance criteria of both tasks pass

## Issues Encountered

None beyond the pre-existing PHPStan error (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. T-14-16 (dead button after a switch-off) and T-14-17 (bank detail on an online document) are mitigated and tested; T-14-18 accepted as planned; T-14-SC: no composer change.

## Next Phase Readiness

Requirement P14-02 is also declared by plan 14-06, which has no SUMMARY yet, so it stays pending in REQUIREMENTS.md until 14-06 finishes. Plans 14-07, 14-09 and 14-10 can rely on `PayUrl::forInvoice()` as the single pay-URL gate.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Pdf/InvoicePdfData.php, app/Domain/Payments/Support/PayUrl.php, app/Domain/Payments/Support/PayLinkBlock.php, app/Filament/Support/PayByCardAction.php, lang/cs/invoice_pdf.php, lang/en/invoice_pdf.php
- FOUND commits: 9584ec8, bbe7824 (both ancestors of HEAD)
