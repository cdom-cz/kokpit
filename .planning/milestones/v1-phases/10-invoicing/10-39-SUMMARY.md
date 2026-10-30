---
phase: 10-invoicing
plan: 39
subsystem: finance-transactions-ui
tags: [finance, transactions, filament, payment-income, invoice-link]
status: complete

requires:
  - phase: 10-invoicing
    provides: "Transaction::invoice() and isPaymentIncome() (plan 10-16), the payment income refusals of UpdateTransaction and DeleteTransaction (plan 10-19), lang/cs/invoices.php section transactions (plan 10-21)"
provides:
  - "Transactions list: column Faktura (invoice number linking to the invoice view, a dash without one) and the ternary filter Faktura (s fakturou / bez faktury)"
  - "Edit form of a payment income: type, amount, currency, client, project, description, counterparty and document number disabled, the gray helper with the invoice number, only date and category editable, manual CZK controls hidden"
  - "Delete action of a payment income: the refusal of DeleteTransaction shown as a danger notification with the action Otevrit fakturu to the invoice detail, the row stays"
affects: [10-41]

actuals:
  tokens: 9000
  tasks: 2
  commits: 3
plan_head_before: 616987464414faa91ad52fdb2a34829359fb0e86
plan_head_after: c3543199e5bcd91083064dccf9e5e4e0edbcb34e
commits: 3

coverage:
  - deliverable: "The transactions list shows the invoice number as a link to the invoice view for a payment income and a dash for the others, and the Faktura filter narrows the rows in both directions"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#shows the invoice number as a link to the invoice view for a payment income and a dash for the others"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#narrows the rows with the Faktura filter in both directions"
        status: pass
    human_judgment: false
  - deliverable: "The edit form of a payment income locks everything but date and category, shows the helper, recomputes the CZK figure on a changed date and accepts another income category"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#disables every field but the date and the category and shows the helper with the invoice number"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#saves a changed date with the CZK figure following the CNB list of the new day, and another income category"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#leaves an ordinary transaction fully editable and without the helper"
        status: pass
    human_judgment: false
  - deliverable: "A forged amount or description in the form state is refused by UpdateTransaction with the field error; the delete of a payment income is refused with a danger notification and the action to the invoice, the row staying"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#refuses a forged amount or description in the form state with the field error and changes nothing"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/TransactionResourceTest.php#shows the refusal of the Action as a danger notification with the action to the invoice and keeps the row"
        status: pass
    human_judgment: false

key-files:
  created: []
  modified:
    - app/Filament/Resources/TransactionResource.php
    - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
    - tests/Feature/Finance/TransactionResourceTest.php

key-decisions:
  - "The locked fields are disabled and still dehydrated, so the form hands its full state to UpdateTransaction and the Action stays the single authority: an unchanged value is no change, a forged one is refused under its own field key"
  - "The manual CZK switch and the typed CZK field are hidden for a payment income (the Action refuses any manual figure), so a changed date only recomputes the CNB figure"
  - "The delete refusal is caught in the using() callback of DeleteAction, shown as a danger notification titled with the Action's own message, and the action is cancelled so no second failure toast appears"

patterns-established:
  - "A domain refusal that needs a next step is shown as a Filament notification with a link action, built from the ValidationException message of the Action"

requirements-completed: [FI-03]

duration: "35 min"
completed: 2026-10-17
---

# Phase 10 Plan 39: Invoice reference and payment income lock on transactions Summary

**The transactions screen now shows which invoice a payment income came from (column and filter), locks that income to date and category in the edit form with the invoice helper, and turns the delete refusal into a danger notification that leads to the invoice detail.**

## Accomplishments

- `TransactionResource`: column `invoice.number` (linking to the invoice view, dash without), ternary filter `with_invoice`, the invoice number eager-loaded in `getEloquentQuery()` so the column adds no per-row query.
- Form lock: eight fields disabled for `isPaymentIncome()`, a gray helper entry with the two sentences of the UI-SPEC, manual CZK controls hidden for such a row.
- `EditTransaction`: `DeleteTransaction`'s `payment_transaction_delete` refusal surfaces as a danger notification with the button "Otevřít fakturu"; the row stays and ordinary transactions still delete.
- Eight tests appended to `TransactionResourceTest.php` (list column and filter, disabled fields and helper, ordinary row unaffected, date change with CZK recompute plus another income category, forged state refused, delete refusal notification).

## Task Commits

1. Task 1 (tracer): `1899c42` - Faktura column and filter with tests
2. Task 2: `f79eccd` - edit lock, helper, delete refusal notification with tests
3. Follow-up: `c354319` - the invoice action of the delete refusal is tied to `isPaymentIncome()` (acceptance grep in EditTransaction.php)

## Deviations from Plan

**1. [Documentation] The list has no description column**
- **Found during:** Task 1
- **Issue:** UI-SPEC Surface M speaks of "Platba faktury :number" in the existing description column, but the Phase 9 list has no description column.
- **Fix:** No column added (out of scope). The test asserts the stored description of the income ("Platba faktury " plus the number, written by RecordPayment) and the Faktura column carries the visible reference.
- **Files modified:** none beyond the plan
- **Commit:** 1899c42

**Total deviations:** 1 documented (no code impact). **Impact:** none on behaviour; a description column in the list, if wanted, is a separate Phase 9 UI decision.

## Known Stubs

None.

## Threat Flags

None. T-10-73 is mitigated and tested: disabled fields are cosmetic, the forged-state test proves `UpdateTransaction` refuses the change.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Finance tests/Isolation`: 548 passed
- Full suite `ddev exec vendor/bin/pest --compact`: 5706 passed, 3 skipped
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits; acceptance greps pass (`invoice` in TransactionResource, `isPaymentIncome` in EditTransaction)

## Next Phase Readiness

Plan 10-41 (DeletePayment in the invoice detail) is the path the notification points to; this screen only refuses and links.

## Self-Check: PASSED

- FOUND: app/Filament/Resources/TransactionResource.php, app/Filament/Resources/TransactionResource/Pages/EditTransaction.php, tests/Feature/Finance/TransactionResourceTest.php
- FOUND: 1899c42, f79eccd, c354319
