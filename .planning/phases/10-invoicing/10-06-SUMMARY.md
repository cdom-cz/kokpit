---
phase: 10-invoicing
plan: 06
subsystem: invoicing-database
tags: [postgres, triggers, immutability, schema, time-entries, payments, partner-safe-columns]

requires:
  - phase: 10-invoicing
    provides: "invoices and invoice_items with the composite-key targets (plan 10-04), the child-of-frozen-parent guard"
  - phase: 06-time-tracking
    provides: "time_entries and the frozen guard that had to be re-created"
  - phase: 09-documents-finance
    provides: "documents (PDF owner pair) and transactions (payment income)"
provides:
  - "invoice_admin (internal note, CNB trio, CZK total, pdf_document_id), invoice_item_sources, invoice_payments, invoice_emails"
  - "kokpit_guard_invoice_pdf_link(), kokpit_guard_invoice_payment() and their triggers"
  - "time_entries.invoice_id, billed rate/amount snapshot, kokpit_guard_time_entry_link(), re-created time_entries_frozen_guard"
  - "transactions.invoice_id / invoice_payment_id, kokpit_guard_payment_income(), deferred transactions_payment_income_pair_check"
  - "Transaction-local flag kokpit.payment_deletion that only DeletePayment (plan 10-41) may set"
affects: [10-08, 10-09, 10-11, 10-12, 10-13, 10-14, 10-16, 10-17, 10-19, 10-20, 10-34, 10-36, 10-38, 10-40, 10-41]

actuals:
  tokens: 26000
  tasks: 3
  commits: 3
plan_head_before: 2f577e6fa39c13d515b9c8de893ec984d500c2ee
plan_head_after: 444cfea2f319571ceda347e08473e51a1d2bbfe7
commits: 3

coverage:
  - deliverable: "A time entry is reserved by one draft, billed only when its document is issued, moved only from a proforma to its invoice, and released only by an issued storno credit note"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/TimeEntryInvoiceLinkTest.php"
        status: pass
    human_judgment: false
  - deliverable: "invoice_admin keeps only the internal note and the PDF link writable after issue; the PDF link changes only from null to a file owned by the invoice or by deleting its file"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceAdminTablesTest.php#invoice_admin / the PDF link of an issued document"
        status: pass
    human_judgment: false
  - deliverable: "invoice_item_sources frozen with the document; payments inserted only for issued invoices and proformas, never updated, deleted only under kokpit.payment_deletion naming that payment"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/InvoiceAdminTablesTest.php#invoice_item_sources / invoice_payments"
        status: pass
    human_judgment: false
  - deliverable: "Payment income changes only in date (with the CZK figure) and category, is deleted only with its payment, and half a pair cannot commit"
    verification:
      - kind: test
        ref: "tests/Feature/Schema/TransactionTablesTest.php#the income of a payment / a payment and its income go together"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Transaction-local deletion flag: current_setting('kokpit.payment_deletion', true) compared with IS DISTINCT FROM against the row's payment id, so an unset or empty setting refuses"
    - "Deferred constraint trigger as a pair rule: AFTER DELETE ... DEFERRABLE INITIALLY DEFERRED, forced in tests with SET CONSTRAINTS <name> IMMEDIATE"
    - "Link guard for one nullable reservation column with the state machine split into billed/billed, billed/unbilled, unbilled/billed, unbilled/unbilled branches"

key-files:
  created:
    - database/migrations/2026_10_17_000300_create_invoice_admin_tables.php
    - database/migrations/2026_10_17_000400_link_transactions_to_invoices.php
    - database/migrations/2026_10_17_000500_link_time_entries_to_invoices.php
    - tests/Feature/Schema/InvoiceAdminTablesTest.php
    - tests/Feature/Schema/TimeEntryInvoiceLinkTest.php
  modified:
    - tests/Feature/Schema/TransactionTablesTest.php
    - tests/Feature/Schema/InvoiceTablesTest.php
    - tests/Feature/Schema/SchemaConventionsTest.php
    - tests/Support/PgSchema.php

key-decisions:
  - "R-06-1: invoice_payments_guard refuses every UPDATE, and a DELETE unless kokpit.payment_deletion equals the payment id; carried_from_payment_id is NO ACTION so a source cannot go while its copy exists"
  - "R-06-5/R-06-7: the income of a payment edits only date, CZK columns, category; the payment key is NO ACTION and the deferred pair check refuses an income deleted while its payment exists"
  - "R-06-6: pdf_document_id lives on invoice_admin; the link guard checks issued parent, owner pair, no repoint, no clear while the file exists, and works with the ON DELETE SET NULL action"
  - "Reserving a time entry does not require it to be finished or billable in the database (plan lists only unbilled + draft); the picker and the issue pipeline keep that rule"
  - "Billing a reserved entry with the link dropped, or reserving and billing in one statement, is refused (the link is kept from reservation to billing)"
  - "invoice_item_sources.task_id is a composite key (task_id, project_id) -> tasks, stricter than a plain FK, so a task can only be named with its own project"

patterns-established:
  - "Mutation self-check for each new guard: drop the trigger inside the test and show the same assertion fails with 'was accepted'"
  - "Raw-SQL tests must not use now() for a changed timestamp: it equals the insert time inside one test transaction, so the write looks like a no-op"

requirements-completed: [IN-02, IN-03, IN-04, IN-05, IN-10, FI-03]

duration: 40 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 06: Admin-only invoicing tables and the links into time, finance and documents Summary

**Four Admin-only side tables plus the links from time entries and transactions into invoices, with every lifecycle rule (single-column reservation, storno-only release, payment lock, sanctioned payment deletion with its income, PDF link) enforced by PostgreSQL triggers and proven with raw SQL, mutation self-checks included.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files:** 5 created, 4 modified

## Accomplishments

- `time_entries.invoice_id` is the whole reservation. `kokpit_guard_time_entry_link()` makes a second draft unable to take an entry, lets an entry be billed only when its document is issued (and with its link kept), moves a billed entry only from a proforma to the issued invoice naming it, and releases a billed entry only together with unbilling when an issued credit note with `cancels_invoice` names the invoice (a partial credit note or a draft storno does not count). Nothing is written on the invoice row. Rate and amount snapshots are null while unbilled and frozen while billed; the Phase 6 hand-billing path is untouched.
- The Phase 6 frozen guard is re-created through `Immutability` with `invoice_id` and the snapshot columns added, `duration_seconds` kept; `down()` restores the old guard.
- `invoice_admin` (note editable after issue, CNB trio and CZK total frozen), `invoice_item_sources` (frozen with the document), `invoice_payments` and `invoice_emails`. The PDF link is set only on an issued invoice to a file owned by that invoice, never repointed, and cleared only by deleting its file (FK `ON DELETE SET NULL` passes the guard).
- A payment is inserted only for an issued invoice or proforma in the document currency, never updated, and deleted only when `kokpit.payment_deletion` names exactly that payment; the carried-from key blocks deleting a source while its copy exists.
- The income of a payment changes only in date, CZK figure and category; it is deleted only under the flag naming its payment; the payment cannot be deleted while its income exists (23503) and the deferred `transactions_payment_income_pair_check` refuses at commit an income deleted while its payment still exists.
- Migrations round-trip (`down()` of all three and `up()` again) inside a rolled-back transaction on the test database.

## Task Commits

1. **Task 1 (tracer): time entry link guard, snapshot columns, re-created frozen guard** - `ce0a706` (feat)
2. **Task 2: invoice_admin, item sources, payments, e-mails and their guards** - `2e91ae7` (feat)
3. **Task 3: transaction links, payment-income guard, pair check** - `444cfea` (feat)

## Verification

- `ddev exec vendor/bin/pest` (whole suite): 5172 passed, 3 skipped, 0 failed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.
- Mutation self-checks (drop the trigger, show the assertion fails): `time_entries_link_guard`, `invoice_admin_pdf_link_guard`, `invoice_payments_guard`, `transactions_payment_income_guard`, `transactions_payment_income_pair_check`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Schema convention R1 flags `invoice_payments.currency`**
- **Found during:** Task 2
- **Issue:** the composite key `(invoice_id, currency)` makes `currency` a foreign key column, and rule R1 demands uuid keys.
- **Fix:** documented exemption in `PgSchema::EXEMPT` with a reason and the pinned list in `SchemaConventionsTest`.
- **Files modified:** tests/Support/PgSchema.php, tests/Feature/Schema/SchemaConventionsTest.php
- **Commit:** 2e91ae7

**2. [Rule 3 - Blocking] TRUNCATE tests of earlier tables now need CASCADE**
- **Found during:** Task 2 and Task 3
- **Issue:** once `invoice_item_sources` references `invoice_items` and `transactions` references `invoice_payments`, PostgreSQL refuses a plain TRUNCATE with 0A000 before the guard trigger runs, so the existing assertion for KP001 failed.
- **Fix:** the TRUNCATE statements in `InvoiceTablesTest` and the new payments test use `CASCADE`; the guard still answers KP001.
- **Files modified:** tests/Feature/Schema/InvoiceTablesTest.php, tests/Feature/Schema/InvoiceAdminTablesTest.php
- **Commit:** 2e91ae7, 444cfea

### Notes

- The test for the item-source `updated_at` write uses a fixed timestamp: `now()` equals the insert time inside one test transaction, so the write was a no-op and (correctly) not refused.
- The Phase 9 docblock of `create_transactions_table` still says the invoice link comes in Phase 10; that migration was not edited, the new migration's docblock states what holds now.

**Total deviations:** 2 auto-fixed (both blocking, test-side only). **Impact:** none on the plan's contracts; every table, constraint, function and trigger name of the artifact table exists.

## Known Stubs

None.

## Threat Flags

None. T-10-15, T-10-83, T-10-94, T-10-16, T-10-17, T-10-93 and T-10-18 are mitigated by the guards and keys above; no new endpoint, auth path or file access.

## Notes for later plans

- Only `DeletePayment` (plan 10-41) may set `kokpit.payment_deletion`: `set_config('kokpit.payment_deletion', <payment id>, true)`, delete the income first, then the payment, clear the flag. A flag left set in a test transaction persists to its end, so clear it with `set_config(..., '', true)`.
- The issue pipeline must insert the `invoice_admin` row (and item sources) while the invoice is a draft; after the state flip the child guard refuses INSERT. The PDF link is the one write allowed afterwards.
- Every `invoice_id` of an entry must be reserved by a draft; billing sets `billing_state`, `billed_at` and the snapshot in one UPDATE after the invoice flip. Hand billing/unbilling (no link) is unchanged.
- Reservation is not checked for "finished and billable" in the database; the picker (plan 10-13) and the strict billing variant must keep that rule.
- Plans that truncate or delete across these tables in tests need `CASCADE` for TRUNCATE.

## Next Phase Readiness

Ready for plans 10-08 (models), 10-09, 10-13 (picker and reservation), 10-16/10-19 (payment income Actions), 10-41 (DeletePayment).

## Self-Check: PASSED

Created files exist on disk (three migrations, `InvoiceAdminTablesTest.php`, `TimeEntryInvoiceLinkTest.php`); commits `ce0a706`, `2e91ae7`, `444cfea` are ancestors of HEAD; commit count measured from the plan ledger is 3.
