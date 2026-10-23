---
phase: 14-online-payment-method-and-invoice-currency
plan: 06
subsystem: invoicing
tags: [filament, infolist, payment-method, partner-detail, isolation]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 02)
    provides: invoices.payment_method (Partner-readable), DocumentPaymentMethod labels, online document without bank snapshot
  - phase: 14-online-payment-method-and-invoice-currency (plan 05)
    provides: PartnerPayButton gate (switch and token), unchanged here
provides:
  - the Partner invoice detail shows the method ("Bankovní převod" / "Online platba kartou") and hides bank account and variable symbol rows of an online document
  - the Admin invoice detail shows the method of the document and the bank row only for a transfer document
  - an isolation case proving no Partner page carries the chosen account id, the pay token or an account number of an online document
affects: [14-08, 14-07, 14-09, 14-10, 14-11]

plan_head_before: 307a6991d4ce1c55ad9eb129480959f23e285a22
plan_head_after: e3212d8ce74b074aa945b183531f9470f56585ea

actuals:
  tokens: 5200
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One closure ($notOnline) shared by every Partner entry an online document does not print"
    - "File-local test helper makes an online document by raw SQL on an open issued document"

key-files:
  created: []
  modified:
    - app/Filament/Support/InvoiceColumns.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - tests/Feature/Invoicing/PartnerInvoiceResourceTest.php
    - tests/Feature/Invoicing/InvoiceDetailTest.php
    - tests/Isolation/InvoiceLeakTest.php

key-decisions:
  - "R-14-06-1: the Partner payment section starts with a method entry (existing label key invoices.detail.fields.payment_method); bank_account, payment_variable_symbol and the document-section variable_symbol are visible only when the method is not online; the method is read from the Partner-readable invoices row"
  - "R-14-06-2: the Admin method entry shows payment_method?->getLabel(); the bank account row is visible only for a non-credit-note with method transfer; variable symbol and card switch entries unchanged"

patterns-established:
  - "A detail entry an online document does not print is hidden on both details by reading the method, never the Admin-only row, on the Partner side"

requirements-completed: [P14-02]

coverage:
  - id: D1
    description: "A Partner sees the method of an own invoice or proforma; an online one shows no bank account and no variable symbol, a transfer one shows them as before"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#the payment method (Phase 14)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The Admin detail shows the method from the document, the bank account only for transfer, and keeps the variable symbol and the card switch; a credit note shows neither method nor bank row"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#shows the issue facts of the document"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#shows the online method of a document and no bank account, but keeps the variable symbol and the card switch"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#shows neither the method nor the bank account on a credit note"
        status: pass
    human_judgment: false
  - id: D3
    description: "No Partner page (list, detail, Livewire snapshots) holds the chosen account id, the pay token, the card switch column or an account number of an online document"
    requirement: "P14-02"
    verification:
      - kind: integration
        ref: "tests/Isolation/InvoiceLeakTest.php#the payment method (Phase 14)"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 06: Show the method on both details Summary

**A Partner opening an own online invoice or proforma now sees "Online platba kartou", the amount and the due date and no bank account or variable symbol; the Admin detail shows the real method of the document and the bank row only for a transfer document.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 5 modified, plus this SUMMARY

## Accomplishments

- `InvoiceColumns::partnerEntries()`: a method entry opens the payment section; the bank account, payment variable symbol and document variable symbol entries share one `$notOnline` visibility closure.
- `ViewInvoice::documentSection()`: the method entry reads the document instead of the fixed "Převodem"; the bank account row shows for transfer only; the variable symbol and the card switch entry are unchanged.
- Tests: Partner detail of transfer, online invoice and online proforma; Admin detail of transfer, online and credit note; an isolation case with a fictional account id (UUID v7), a pay token and the configured IBANs, matched against the Partner list and detail including decoded Livewire snapshots.

## Task Commits

1. Task 1 (tracer): `c5347c0` feat(14-06): the Partner detail shows the payment method and hides bank data of an online document
2. Task 2: `e3212d8` feat(14-06): the Admin detail shows the method of the document and the bank row only for transfer

## Deviations from Plan

None - plan executed exactly as written. Verification: `pest tests/Feature/Invoicing tests/Isolation` 1120 passed, Pint clean, PHPStan shows only the known pre-existing error in `ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md).

## Notes for later plans

- `invoices.draft.payment_method_value` ("Převodem") is no longer read by `ViewInvoice`; its last reader is `EditInvoice.php` line 960, so the key stays until plan 14-08 removes it, as the plan stated.

## Known Stubs

None.

## Threat Flags

None. T-14-19 mitigated (Partner infolist reads Partner-readable columns only, isolation test asserts account id, token and IBANs absent); T-14-20 unchanged (existing InvoiceDetailTest access describe stays green).

## Self-Check: PASSED

- FOUND: app/Filament/Support/InvoiceColumns.php, app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php, the three test files
- FOUND commits: c5347c0, e3212d8
