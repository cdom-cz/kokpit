---
phase: 10-invoicing
plan: 16
subsystem: invoicing-payments
tags: [invoicing, payments, finance, income, balances]

requires:
  - phase: 10-invoicing
    provides: "InvoicePayment model, invoice_payments and the transactions invoice link with their DB guards (plans 10-06, 10-08)"
  - phase: 10-invoicing
    provides: "Issue pipeline and InvoiceFixtures issued()/stornoed() (plan 10-12), error copy in lang/cs/invoices.php (plan 10-11)"
provides:
  - "RecordPayment: manual payment of an issued invoice or proforma with the income booked through RecordTransaction in the same transaction; warnings() for already_paid and above_remaining"
  - "PaymentInput: the checked input of a payment (amount, paid_on, method, note, income_date, category_id)"
  - "InvoiceBalances: paid, credited and remaining per document for Admin and system only, with the SQL fragments PAID_SQL, CREDITED_SQL, REMAINING_SQL"
  - "RecordTransaction optional invoice link and Transaction relations invoice(), invoicePayment(), isPaymentIncome()"
affects: [10-19, 10-21, 10-22, 10-23, 10-30, 10-39, 10-41]

actuals:
  tokens: 11562
  tasks: 3
  commits: 3
plan_head_before: 55108966eee7718eff293f97a2085c3670922ba4
plan_head_after: f896ac877cea8e3a230a97d872ef43c098a76d5e
commits: 3

coverage:
  - deliverable: "The Admin records a manual payment (transfer or cash) on an issued invoice; the sum of the payments decides unpaid, partly paid or paid (several payments, no stored state); a payment on a draft, a credit note or a cancelled invoice is refused with payment_not_allowed"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#partial and repeated payments"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#documents that cannot be paid"
        status: pass
    human_judgment: false
  - deliverable: "A payment on an already paid document or above the remaining amount is stored; warnings() reports already_paid or above_remaining for the confirmation of the modal and the Action never blocks on them"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#warnings"
        status: pass
    human_judgment: false
  - deliverable: "A payment on an invoice books, in the same transaction, an income transaction through RecordTransaction in the system income category dated with the payment date, linked to the invoice and the payment, with amount, currency, client, number and customer name from the payment and the document; the category and the income date are editable when entering the payment"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentIncomeTest.php#records a full transfer payment, leaves nothing owed and books the income in the system category"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#the editable income date and category"
        status: pass
    human_judgment: false
  - deliverable: "A payment on a proforma is stored as a payment only and books no income (D-18); each bad value (amount, date, method, note, category) fails under its own key and stores nothing"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#proformas"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#validation"
        status: pass
    human_judgment: false
  - deliverable: "The CZK figure of the income follows the CNB list of the income date; a missing list fails the payment with the Czech cnb_missing message under the date and nothing is stored"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#a foreign currency"
        status: pass
    human_judgment: false
  - deliverable: "InvoiceBalances returns paid, credited and remaining per document (credit notes and cancellation reduce what is owed, never below zero, null remaining for a credit note or draft), for Admin and system only, and its SQL fragments select the same documents as the figures computed in PHP"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PaymentsTest.php#the balance of a document"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "The document row is locked first (FOR UPDATE), then the payment is inserted, then the income through the one finance write path, so the lock order document, payment, income is shared with the conversion and the deletion of plan 10-41"
    - "One SQL definition of paid, credited and remaining serves both the list filters and InvoiceBalances::for()"
    - "The invoice link of an income is passed to RecordTransaction as a third argument and written with forceFill, never read from the request data"

key-files:
  created:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - app/Domain/Invoicing/Support/PaymentInput.php
    - app/Domain/Invoicing/Queries/InvoiceBalances.php
    - tests/Feature/Invoicing/PaymentIncomeTest.php
    - tests/Feature/Invoicing/PaymentsTest.php
  modified:
    - app/Domain/Finance/Actions/RecordTransaction.php
    - app/Domain/Finance/Models/Transaction.php
    - lang/cs/invoices.php

key-decisions:
  - "R-16-1 to R-16-4 applied as planned: the income description is the fixed text 'Platba faktury :number', a payment dated in the future is refused and one before the issue date is allowed"
  - "Payment refusals (draft, credit note, cancelled invoice) are keyed under 'payment', the same key DeletePayment of plan 10-41 uses; the missing CNB list is keyed under 'paid_on', or under 'income_date' when the income date differs from the payment date"
  - "The CNB list is checked before anything is written (ExchangeRateConverter::rateFor), because RecordTransaction would otherwise answer a missing list of a currency with no stored rows as currency_unknown on the currency key"
  - "InvoiceBalances::for() reads the same SQL fragments the list filters use; remaining is null for a credit note and a draft, 0 for a cancelled invoice"
  - "The default category is resolved in RecordPayment (TransactionCategory::invoicePayments()); PaymentInput leaves category_id null when none is given"

patterns-established:
  - "A money figure that tests compare with an 8-digit literal trips the company-id check of scripts/check-sensitive.sh; compute it from fragments instead"

requirements-completed: [IN-05, FI-03]

duration: about 30 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 16: Manual payments with income booking Summary

**RecordPayment stores several manual payments on an issued invoice or proforma under a document row lock and books the income of an invoice payment through RecordTransaction in the system category, while InvoiceBalances derives paid, credited and remaining from one SQL definition shared with the list filters.**

## Performance

- **Duration:** about 30 min (not measured exactly)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 5 created, 3 modified

## Accomplishments

- `RecordPayment::handle()` authorizes `update` on the document, locks the invoice row first, refuses a draft, credit note or cancelled invoice (`payment_not_allowed` under `payment`), validates through `PaymentInput`, inserts the payment with `forceFill` and, for kind invoice only, books the income. A proforma payment is stored alone (D-18).
- The income goes through the one finance write path: `RecordTransaction::handle()` gained the optional third argument `?array $invoiceLink` (`invoice_id`, `invoice_payment_id`) written in the same insert; category defaults to `TransactionCategory::invoicePayments()`, date to the payment date, description to "Platba faktury " plus the number, counterparty to the customer name of the snapshot, client and document number from the invoice. The DB checks of plan 10-06 (composite keys, the payment-income guard) hold for it.
- `warnings()` answers `already_paid` when nothing is owed and `above_remaining` when the amount exceeds what is; the Action never blocks on either (D-17).
- `PaymentInput` re-validates every key: amount in the document currency (comma or dot, spaces ignored, greater than zero, no extra decimals), `paid_on` a real Prague day not after today (before the issue date is allowed), method, note up to 500 characters, and for an invoice `income_date` and a selectable income `category_id`.
- A foreign-currency invoice books the CZK figure of the CNB list of the income date (snapshot rate and list date stored); a missing list fails the payment under `paid_on` (or `income_date`) with the Czech `cnb_missing` text and nothing is stored.
- `InvoiceBalances` (Admin and system only): `PAID_SQL`, `CREDITED_SQL`, `REMAINING_SQL` as correlated fragments over `invoices`, with the cancelled rule embedding `Invoice::CANCELLED_SQL`; `for()` reads the same fragments, so the PHP and SQL paths share one definition. Remaining is floored at zero, 0 for a cancelled invoice, null for a credit note and a draft.
- `Transaction` got the relations `invoice()` and `invoicePayment()`, `isPaymentIncome()` and the two link columns in `#[LoggedAttributes]`.

## Task Commits

1. **Task 1 (tracer): full payment books income in the system category** - `268f47f` (feat)
2. **Task 2: partial, repeated and proforma payments, warnings, editable income date and category** - `836fefe` (feat)
3. **Task 3: credit notes and cancellation reduce what is owed, balance reader closed to Partners** - `f896ac8` (feat)

## Verification

- Full suite `ddev exec vendor/bin/pest --compact`: 5404 passed, 3 skipped.
- `tests/Feature/Invoicing/PaymentIncomeTest.php` (3) and `PaymentsTest.php` (15) pass; `tests/Feature/Finance` and `tests/Arch` (including `QueryEscapeHatchTest`) pass.
- `pint --test` and PHPStan clean; `scripts/check-sensitive.sh` clean on each staged set; lefthook (sensitive-content, gitleaks) passed on all three commits.
- Acceptance greps of all three tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Missing CNB list of a currency with no stored rows was reported under `currency`**
- **Found during:** Task 2 (designing the foreign-currency behavior)
- **Issue:** With no EUR list at all, `TransactionInput` fails the currency check (`currency_unknown` under `currency`) before `TransactionCzk` can produce the `czk_amount` error, so the payment would not fail "with cnb_missing under the date".
- **Fix:** `RecordPayment` checks the list with `ExchangeRateConverter::rateFor()` before writing anything and raises `cnb_missing` under `paid_on` or `income_date`; a `czk_amount` error from the booking (list removed in between) is mapped to the same message.
- **Files modified:** `app/Domain/Invoicing/Actions/RecordPayment.php`
- **Commit:** 268f47f

**2. [Rule 2 - Missing critical] Language copy for method, note and the income description**
- **Found during:** Task 1
- **Issue:** `invoices.errors` had no message for an invalid payment method or a too long note, and R-16-2 asks for a fixed Czech income description.
- **Fix:** Added `errors.payment_method`, `errors.payment_note` and `payment_income.description` to `lang/cs/invoices.php`.
- **Files modified:** `lang/cs/invoices.php`
- **Commit:** 268f47f

**Total deviations:** 2 auto-fixed (1 bug, 1 missing critical). **Impact:** none on the plan contract.

## Issues Encountered

- A payment on an invoice of an archived client fails inside `RecordTransaction` with a `client_id` error, because `TransactionInput` accepts only live clients for a new transaction. The plan does not cover archived clients; whether an archived client may keep open invoices is for the screens of plans 10-21 and 10-30 to settle. Not changed here (finance validation is out of scope for this plan).
- `scripts/check-sensitive.sh` flagged an 8-digit amount literal in a test as a company id; the expected CZK figure is now computed from fragments.

## Known Stubs

None.

## Threat Flags

None. T-10-43 is mitigated by the kind and state checks on the locked row (plus the DB trigger of plan 10-06), T-10-44 by the proforma branch that stores the payment only (tested), T-10-45 by `PaymentInput` and the composite keys; nothing was installed (T-10-SC).

## Notes for later plans

- Plan 10-22 adds `RecordPayment::carry()` in this file; `handle()` and the private `bookIncome()` show the income shape to reuse.
- Plan 10-19 reads `InvoiceBalances::PAID_SQL`, `CREDITED_SQL` and `REMAINING_SQL` inside `whereRaw` on `Invoice::query()` (table name `invoices`, unaliased).
- Plan 10-30 shows `warnings()` live in the modal and reads the field errors under `amount`, `paid_on`, `income_date`, `method`, `note`, `category_id` and `payment`.

## Self-Check: PASSED

All eight files exist on disk; commits `268f47f`, `836fefe` and `f896ac8` are ancestors of HEAD; the commit count measured from the plan ledger is 3.
