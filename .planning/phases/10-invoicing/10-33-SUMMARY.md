---
phase: 10-invoicing
plan: 33
subsystem: invoicing-vocabulary
tags: [enums, localisation, partner-wording, number-series]
status: complete

requires:
  - phase: 05-numbering
    provides: "DocumentKind numbering enum with one series per document kind"
provides:
  - "InvoiceKind (invoice, proforma, credit_note) with numberingKind() and carriesPayment()"
  - "InvoiceState (draft, issued) as the only stored document states"
  - "InvoiceStatus derived display enum with getLabel(), partnerLabel(), getColor(), getIcon()"
  - "InvoiceItemOrigin, PaymentMethod and InvoiceEmailState enums"
  - "Czech labels under enums.invoice_kind, invoice_state, invoice_status, invoice_status_partner, invoice_item_origin, payment_method, invoice_email_state"
affects: [10-05, 10-08, 10-19, 10-20, 10-31]

tech-stack:
  added: []
  patterns:
    - "Display-state enum with separate Admin and Partner wording; Partner label of a draft throws LogicException"

key-files:
  created:
    - app/Domain/Invoicing/Enums/InvoiceKind.php
    - app/Domain/Invoicing/Enums/InvoiceState.php
    - app/Domain/Invoicing/Enums/InvoiceStatus.php
    - app/Domain/Invoicing/Enums/InvoiceItemOrigin.php
    - app/Domain/Invoicing/Enums/PaymentMethod.php
    - app/Domain/Invoicing/Enums/InvoiceEmailState.php
    - tests/Unit/Invoicing/InvoiceEnumsTest.php
  modified:
    - lang/cs/enums.php

key-decisions:
  - "R-33-1: InvoiceKind is its own enum with numberingKind(): DocumentKind and carriesPayment() (invoice, proforma)"
  - "R-33-2: InvoiceStatus is the display enum of the derived state; plan 10-19 derives it"
  - "R-33-3: InvoiceEmailState has a Sending claim state labelled 'Odesílá se'; UI surfaces show it like 'Ve frontě'"
  - "Converted and AwaitingInvoice read 'Zaplaceno' for the Partner (a paid proforma as the client sees it, T-10-82)"

requirements-completed: [IN-01, IN-05, IN-09]

actuals:
  tokens: 9500
  tasks: 2
  commits: 2
plan_head_before: 6f9b93e57864125a3b4962aeb150d5f2e088d258
plan_head_after: f6f4fa66bdb9d565b9d1be538e6c0ad461666d2b
commits: 2

coverage:
  - deliverable: "Each document kind maps to its own number series; credit notes carry no payment"
    verification:
      - kind: test
        ref: "tests/Unit/Invoicing/InvoiceEnumsTest.php#labels the document kinds and maps each to its own number series"
        status: pass
    human_judgment: false
  - deliverable: "Derived states read in Czech for Admin and Partner with UI-SPEC colours and icons; a draft never gets a Partner label"
    verification:
      - kind: test
        ref: "tests/Unit/Invoicing/InvoiceEnumsTest.php#reads the derived states for the Partner as the client sees them"
        status: pass
      - kind: test
        ref: "tests/Unit/Invoicing/InvoiceEnumsTest.php#never shows a draft to a Partner"
        status: pass
    human_judgment: false
  - deliverable: "Line origins, payment methods and e-mail states are labelled; the enum label test covers all new enums without exemption"
    verification:
      - kind: test
        ref: "tests/Feature/Localisation/EnumLabelsTest.php"
        status: pass
    human_judgment: false

duration: 10 min
completed: 2026-10-17
---

# Phase 10 Plan 33: Invoicing Enum Vocabulary Summary

Six invoicing enums with Czech labels: document kinds mapped to separate number series, stored draft/issued state, the derived display status with distinct Admin and Partner wording, colours and icons, plus line origin, payment method and e-mail state.

## Accomplishments

- `InvoiceKind` maps invoice, proforma and credit note to their own `DocumentKind` series, so no two kinds share a counter; `carriesPayment()` is false for a credit note.
- `InvoiceState` holds only `draft` and `issued`; every other state is derived.
- `InvoiceStatus` carries nine derived states, Admin labels, Partner labels (`partnerLabel()` throws `LogicException` for a draft; a paid proforma reads "Zaplaceno"), colours and icons from the UI-SPEC colour map.
- `InvoiceItemOrigin`, `PaymentMethod` and `InvoiceEmailState` (with the `Sending` claim state) have Czech labels in `lang/cs/enums.php`; the existing enum label test covers all of them with no new exemption.

## Task Commits

1. Task 1 (tracer): document kinds and derived states - d28cd8a
2. Task 2: line origins, payment methods, e-mail states - f6f4fa6

Tracer gate: the tracer `<verify>` is automated-only; the unit and localisation tests, Pint and PHPStan were green, so expansion continued.

## Deviations from Plan

None - plan executed exactly as written.

## Authentication Gates

None.

## Known Stubs

None.

## Threat Flags

None. T-10-82 is mitigated: `partnerLabel()` throws for `Draft` and reads "Zaplaceno" for `Converted` and `AwaitingInvoice`; a test pins both and checks that no Partner label contains draft, e-mail, line-source or payment-method wording.

## Verification

- `ddev exec vendor/bin/pest tests/Unit/Invoicing tests/Feature/Localisation`: 104 passed
- Pint clean, PHPStan no errors, `scripts/check-sensitive.sh` clean on both commits

## Self-Check: PASSED

All seven created files and the modified `lang/cs/enums.php` exist; commits d28cd8a and f6f4fa6 are on the branch; all task acceptance criteria re-run green.
