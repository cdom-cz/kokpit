---
phase: 10-invoicing
plan: 41
subsystem: invoicing
tags: [payments, deletion, finance, concurrency, locking, immutability]

requires:
  - phase: 10-invoicing
    provides: "The payment guard, the payment-income guard, the carried key and the deferred pair check with the transaction-local flag kokpit.payment_deletion (10-06), RecordPayment and the income booking (10-16), ConvertProforma and carried payments (10-22), the issue concurrency harness (10-35), the typed refusals payment_missing and payment_delete_carried (10-11)"
provides:
  - "DeletePayment: the one sanctioned deletion of a payment together with the income booked from it, in one transaction under the document lock"
  - "Arch test that keeps the kokpit.payment_deletion literal out of every other application file and migration"
  - "payment-worker.php and PaymentDeletionConcurrencyTest with a skip-lock mutation twin"
affects: [10-30, 11]

actuals:
  tokens: 12900
  tasks: 3
  commits: 3

plan_head_before: 773790cd4022d02055e352ef361ad3fb9142f074
plan_head_after: 58fec52347978f925709bb366812e152a3c48801
commits: 3

coverage:
  - deliverable: "The Admin deletes the single full payment of an overdue invoice: payment, income, receipt row and stored object go together, the invoices row is unchanged, the invoice is overdue again, the flag is cleared, a Partner is refused"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#removes the payment, its income and its receipt together and leaves the invoice overdue again"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#refuses a Partner with an AuthorizationException and leaves the payment and its income"
        status: pass
    human_judgment: false
  - deliverable: "The deleted activity of the payment and of the income carries allowlisted attributes only and no note"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#writes the deleted activity of the payment and of the income with allowlisted attributes and no note"
        status: pass
    human_judgment: false
  - deliverable: "Partial, overpaid and unconverted-proforma payments are deleted with the right derived state"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#derives partly paid and unpaid from the remaining payments and drops the income of each deleted payment (D-17, D-20)"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#deletes the payment of a proforma that was never converted without touching any transaction (D-18)"
        status: pass
    human_judgment: false
  - deliverable: "Carried payments: the proforma payment is refused while its copy exists (Action and database), the copy deletes alone, the source is deletable afterwards"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#refuses the proforma payment while its carried copy exists, in the Action and in the database"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#deletes the invoice-side copy with its income, leaves the proforma payment and then allows deleting it"
        status: pass
    human_judgment: false
  - deliverable: "A payment of a cancelled invoice stays correctable and the storno credit note is untouched"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#keeps a mistaken payment correctable after the storno and leaves the credit note untouched"
        status: pass
    human_judgment: false
  - deliverable: "A failing step deletes nothing, logs nothing and leaves no flag behind; a second deletion of the same payment is refused as payment_missing"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#keeps the payment, its income, the receipt and its object and logs nothing when a step fails"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DeletePaymentTest.php#refuses the second of two deletions of one payment as already gone and changes nothing else"
        status: pass
    human_judgment: false
  - deliverable: "Only DeletePayment sets kokpit.payment_deletion in application code; only the two guard migrations name it"
    verification:
      - kind: test
        ref: "tests/Arch/PaymentDeletionPathTest.php"
        status: pass
    human_judgment: false
  - deliverable: "Parallel deletions, conversions and payments of one document: no deadlock, no untyped error, consistent rows; the harness sees the race without the lock"
    verification:
      - kind: test
        ref: "tests/Concurrency/PaymentDeletionConcurrencyTest.php"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Domain/Invoicing/Actions/DeletePayment.php
    - tests/Feature/Invoicing/DeletePaymentTest.php
    - tests/Arch/PaymentDeletionPathTest.php
    - tests/Concurrency/payment-worker.php
    - tests/Concurrency/PaymentDeletionConcurrencyTest.php
  modified: []

key-decisions:
  - "DeletePayment is final readonly with only DeleteDocument injected; the flag is set with set_config(..., true) after the refusals and cleared again after the payment row is deleted, with no finally block, because a failed attempt rolls the transaction (or its savepoint) back and the setting with it"
  - "The carried-copy check reads the copy under the document and payment locks, so a conversion that committed first is always seen; the carried key of the database stays the backstop and the mutation twin proves it answers 23503"
  - "The mutation twin does its unlocked check before the shared barrier, so the converter provably cannot have committed yet and the 23503 outcome is deterministic instead of probabilistic"
  - "The worker maps a ValidationException to the key under invoices.errors whose text it carries, so the test asserts payment_missing, proforma_not_paid and payment_delete_carried rather than field names"

requirements-completed: [IN-05, IN-06, FI-03]

duration: 75 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 41: Payment deletion Summary

**DeletePayment deletes a mistaken payment together with its income, receipts included, in one transaction under the document lock, writes nothing on the issued invoice and lets the balance and overdue state follow the remaining payments.**

## Performance

- **Duration:** about 75 min (most of it the concurrency rounds, 84 s per run, and two full-suite runs of about 10 min)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 5 created, none modified

## Accomplishments

- `DeletePayment::handle(User $actor, InvoicePayment $payment)`: Gate `delete` (Admin only) first, then one `DB::transaction` that locks the document row, re-reads the payment under `lockForUpdate` (gone: `payment_missing`), refuses a proforma payment that is the source of a carried copy (`payment_delete_carried` naming the invoice), locks the income, sets `kokpit.payment_deletion` to the payment id, deletes the receipts through `DeleteDocument` (stored objects removed after commit, dropped on rollback), the income and the payment through their models (allowlisted `deleted` activity, no note), and clears the flag.
- No write to `invoices`: a feature test compares every column of the row, `updated_at` included, before and after. A paid invoice past its due date becomes overdue again (5 days late in the test), a partly paid one follows its remaining payments, a paid unconverted proforma leaves "Zalohy k vyfakturovani".
- Carried payments: the invoice-side copy deletes with its income and never touches its proforma source; the source is refused while the copy exists (and the database refuses it with 23503), and is deletable afterwards with no income to delete. A cancelled invoice keeps its payments correctable.
- `tests/Arch/PaymentDeletionPathTest.php` scans `app/` and `database/migrations/` for the flag literal and includes a fixture directory proving the scan can fail.
- `payment-worker.php` and `PaymentDeletionConcurrencyTest.php`: five real-parallel rounds each of two deletions of one payment, a deletion against a conversion and a new payment against a deletion, with no `db:` or `error:` outcome and consistent rows; the `skip-lock` mutation twin ends in `db:23503`. Cleanup runs with `DISABLE`/`ENABLE TRIGGER USER` in one transaction and the guard triggers are checked enabled; the suite was run twice in a row.

## Task Commits

1. **Task 1 (tracer): DeletePayment and the full-payment overdue case** - `c9b20e5` (feat)
2. **Task 2: partial, carried, proforma, cancelled, rollback, double deletion and the flag arch test** - `a171c21` (test)
3. **Task 3: concurrency worker, three locked scenarios and the mutation twin** - `58fec52` (test)

## Verification

- `tests/Feature/Invoicing`, `tests/Feature/Finance` and the arch test: 678 passed; `tests/Concurrency/PaymentDeletionConcurrencyTest.php` 4 passed twice in a row; Pint and PHPStan clean; `scripts/check-sensitive.sh` and gitleaks clean on all three commits; the acceptance greps of all tasks pass.
- Full suite `ddev exec vendor/bin/pest --compact` after the last commit: 5804 passed, 3 skipped (pre-existing skips). An earlier full run had one failure in `PartnerDashboardTest` (see Issues Encountered); the re-run was green.

## Deviations from Plan

None - plan executed exactly as written.

Note on method: the tracer's test and the action landed in one commit and the tests passed on the first run, so there was no separate RED commit; the failure paths (rollback, carried refusal, mutation twin) are each proven by an assertion that can fail (the twin and the arch fixture are explicit).

## Issues Encountered

- One full-suite run reported a single failure in `tests/Feature/Dashboard/PartnerDashboardTest.php:403` ("selects no time, billing or estimate column"-adjacent leak check on the rendered Partner dashboard). It passed on three consecutive isolated runs; the check does a plain `str_contains` of short strings such as `EUR` or `sazba` against a page body that holds random tokens, so it is a pre-existing flake unrelated to this plan (no file of this plan touches the dashboard). Logged here, not fixed (scope boundary).

## Known Stubs

None.

## Threat Flags

None. The only new surface is an Admin-gated Action; the flag it sets is transaction-local and read only by the existing guards.

## Next Phase Readiness

Plan 10-30 can build the "Smazat platbu" action on `DeletePayment` (it must re-read the row through the scoped query). Phase 11 decides whether an online payment matched from Stripe may use this path.

## Self-Check: PASSED

- `app/Domain/Invoicing/Actions/DeletePayment.php`, `tests/Feature/Invoicing/DeletePaymentTest.php`, `tests/Arch/PaymentDeletionPathTest.php`, `tests/Concurrency/payment-worker.php` and `tests/Concurrency/PaymentDeletionConcurrencyTest.php` exist; commits `c9b20e5`, `a171c21` and `58fec52` are ancestors of HEAD.
