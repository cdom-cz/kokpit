---
phase: 10-invoicing
plan: 22
subsystem: invoicing-proforma-conversion
tags: [invoicing, proforma, conversion, payments, income, time-billing, atomicity]
status: complete

requires:
  - phase: 10-invoicing
    provides: "DocumentIssuer with the PDF step and IssueContext::now(User) (plans 10-12, 10-20), EntryBilling::repoint() and the link guard that allows exactly the proforma-to-invoice move (plans 10-06, 10-09)"
  - phase: 10-invoicing
    provides: "ReceivablesReader statuses (Converted, Paid) and the payment-income lock (plan 10-19), RecordPayment with the income branch (plan 10-16)"
provides:
  - "ConvertProforma::handle(User, Invoice): one transaction that locks the proforma, checks it, creates the invoice draft with copied lines, issues it through the shared DocumentIssuer, repoints the billed time and carries the payments"
  - "RecordPayment::carry(User, InvoicePayment, Invoice): a new payment row of the invoice with carried_from_payment_id, the real payment date and its income transaction in the system income category"
  - "invoices.errors.proforma_not_issued (Czech message for a document that is not an issued, uncancelled proforma)"
affects: [10-24, 10-30, 10-36, 10-39, 10-41]

actuals:
  tokens: 10400
  tasks: 2
  commits: 2
plan_head_before: a906252c2f91b2ba7cd389f5db9319fcaa50015b
plan_head_after: 6e6b2ea276164df60c5c7318a0cddcdd4df0e819
commits: 2

coverage:
  - deliverable: "A fully paid proforma built from time converts in one transaction into an issued invoice of the same client, currency and language, with its own number from the invoice series, copies of the lines and total, proforma_id, a stored PDF, issue date today and due date today plus the client's terms"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the tracer: a fully paid proforma built from time"
        status: pass
    human_judgment: false
  - deliverable: "The time entries move from the proforma to the invoice and stay billed with their billed_at and rate snapshot"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the tracer: a fully paid proforma built from time"
        status: pass
    human_judgment: false
  - deliverable: "Each proforma payment is carried as a new invoice payment row with carried_from_payment_id and its real date, with one income transaction dated with that payment date in the system category; the proforma keeps its payments and never had income"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the tracer: a fully paid proforma built from time"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#carries two payments as two rows and two incomes, each with its own date"
        status: pass
    human_judgment: false
  - deliverable: "An unpaid, partly paid, already converted, cancelled or draft proforma, an invoice, a credit note and a Partner are refused, and the database keeps one invoice per proforma"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#what a proforma needs to be converted"
        status: pass
    human_judgment: false
  - deliverable: "After conversion the proforma reads as converted and the invoice as paid; an overpaid proforma leaves the invoice with remaining 0"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#converts an overpaid proforma and leaves the invoice with nothing to pay"
        status: pass
    human_judgment: false
  - deliverable: "A EUR proforma converts with the invoice's own CNB snapshot of the conversion day while the copied line amounts stay those of the proforma; the income of a carried payment follows the CNB list of its payment date"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#prices a EUR proforma with the CNB list of the conversion day while the amounts stay those of the proforma"
        status: pass
    human_judgment: false
  - deliverable: "The conversion is atomic: a PDF render failure, or a payment that cannot be carried (no CNB list for its date) after the time was already moved, leaves no invoice, no number, no stored object, the time on the proforma, no carried payment and no transaction"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the conversion is atomic"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "A conversion locks the source document row first (the lock order shared with RecordPayment and DeletePayment), judges it on the locked row and only then writes"
    - "Frozen text copies of lines are written with the source rows marked price_manual, so the shared issue pipeline never converts a copied price again"
    - "Entries are repointed after the new document is issued, so both the link guard (billed with a link only for an issued document) and the strict billing step hold"

key-files:
  created:
    - app/Domain/Invoicing/Actions/ConvertProforma.php
    - tests/Feature/Invoicing/ConvertProformaTest.php
  modified:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - lang/cs/invoices.php

key-decisions:
  - "R-22-1 to R-22-3 applied as planned: lock, check, draft with copied lines, issue, repoint, carry; issue date today and due date today plus terms; the printed note is copied and the internal note is not"
  - "Checks run in the order not-an-issued-proforma, already-converted, not-paid, all keyed under 'proforma', so a converted proforma (which is also fully paid) reads as converted"
  - "A carried payment whose payment date has no CNB list fails the whole conversion under 'cnb_missing' and rolls everything back, rather than booking an income without a CZK figure"
  - "RecordPayment's income booking was refactored from PaymentInput to primitive arguments (amount, income date, category, date key) so handle() and carry() share one write path"

requirements-completed: [IN-06, FI-03]

duration: about 40 min
completed: 2026-10-17
---

# Phase 10 Plan 22: Proforma conversion Summary

**A fully paid proforma now converts in one transaction into a numbered, linked invoice (shared issue pipeline, PDF included) whose time moves along still billed and whose payments are carried over with the income booked at their real payment dates, with every other proforma refused and the whole conversion atomic.**

## Performance

- **Duration:** about 40 min (not measured exactly)
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 2 created, 2 modified

## Accomplishments

- `ConvertProforma::handle()` locks the proforma, asserts it is convertible, creates the invoice draft (client, currency, language, totals, printed note, `proforma_id`), copies every line with its source row (price marked manual, amounts exactly those of the proforma), issues it through `DocumentIssuer` (own number from the invoice series, snapshots, CNB rate of the conversion day, PDF), repoints the proforma's billed entries to the invoice with `EntryBilling::repoint()` and carries each payment.
- `RecordPayment::carry()` writes a new payment row with `carried_from_payment_id`, the same amount, currency, date, method and note, and books the income through the one finance write path dated with the real payment date in the system income category; the proforma payment is never moved or edited.
- Refusals under the key `proforma`: not an issued, uncancelled proforma (`proforma_not_issued`), already converted (`proforma_converted`), not paid in full (`proforma_not_paid`); a Partner is refused by the policy; the unique `proforma_id` index is proven against raw SQL (23505).
- Atomicity proofs: a render failure and a payment without a CNB list for its date (which fails after the time was already repointed) both leave no invoice, no number, no stored object, the time still billed on the proforma, one payment and no transaction.
- The tracer gate passed (the end-to-end test and Pint/PHPStan) before the expansion task started.

## Task Commits

1. **Task 1 (tracer): paid proforma built from time converts into a numbered, linked, paid invoice with its time moved and income at the payment date** - `5562e5e` (feat)
2. **Task 2: refuse everything but a fully paid, unconverted proforma and prove the conversion atomic** - `6e6b2ea` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/ConvertProformaTest.php`: 12 passed (124 assertions).
- `tests/Feature/Invoicing tests/Feature/Finance` green after Task 1 (477 passed); full suite `ddev exec vendor/bin/pest --compact` after Task 2: 5530 passed, 3 skipped (40388 assertions).
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.
- All acceptance greps of both tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A Czech message for a document that is not a proforma**
- **Found during:** Task 2
- **Issue:** the plan names only `proforma_not_paid` and `proforma_converted`, but refusing an invoice, a credit note, a draft or a cancelled proforma needs a message, and `lang/cs/invoices.php` is not in the plan's file list.
- **Fix:** added `invoices.errors.proforma_not_issued`.
- **Files modified:** `lang/cs/invoices.php`
- **Commit:** 6e6b2ea

**2. [Process] Task 2 tests and implementation share one commit**
- The refusal tests were written first and run red (5 failures) before the checks were added, but they sit in the same file as the passing tracer test and were committed together with the checks rather than as a separate `test` commit.

**Total deviations:** 1 auto-fixed (1 blocking), 1 process note. **Impact:** none on the plan contract.

## Issues Encountered

- `RecordPayment` and `RecordTransaction` are `final`, so a failure after the time moved could not be injected with a mock; the test uses a real cause instead (a payment dated before the first CNB list of the currency), which also exercises a genuine production path.

## Known Stubs

None.

## Threat Flags

None. T-10-59 is mitigated by the row lock, the `proforma_converted` check and the unique `proforma_id` index (all tested), T-10-60 by booking one income per carried payment with its own date through the unique `invoice_payment_id` link; nothing was installed (T-10-SC).

## Notes for later plans

- The conversion UI (plan 10-24 or the detail page plans) calls `ConvertProforma::handle($actor, $proforma)` and shows the `proforma` key of the `ValidationException`; `invoices.conversion.*` already holds the confirmation copy.
- A converted invoice has no `invoice_item_sources` price conversion state of its own: its copied sources carry `price_manual = true` and the proforma's conversion snapshot.
- Plan 10-41 `DeletePayment` is to refuse deleting a proforma payment while its carried copy exists (`payment_delete_carried` is already in the language file); this plan only creates the copies.

## Self-Check: PASSED

`app/Domain/Invoicing/Actions/ConvertProforma.php` and `tests/Feature/Invoicing/ConvertProformaTest.php` exist on disk; commits `5562e5e` and `6e6b2ea` are ancestors of HEAD; the commit count measured from the plan ledger is 2.
