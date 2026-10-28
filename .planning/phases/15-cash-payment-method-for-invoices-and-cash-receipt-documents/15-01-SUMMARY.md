---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 01
subsystem: invoicing
tags: [payment-method, cash, check-constraint, enum, issue-pipeline, requirements]
requires:
  - phase: 14-payment-method-and-currency
    provides: DocumentPaymentMethod (transfer, online), invoices_issued_fields_check with the bank-snapshot tie, DocumentPayment::forDraft(), DocumentIssuer admin row
provides:
  - DocumentPaymentMethod::Cash and the predicates printsBankDetails(), allowsCardChoice(), forcesCardPayment()
  - migration extending invoices_payment_method_check and invoices_issued_fields_check to cash
  - draft rule (card switch off, explicit on refused) and issue branch (no bank snapshot, no pay token, no account needed) for cash
  - P15-01 to P15-12 registered in REQUIREMENTS.md
affects: [15-02, 15-04, 15-07, 15-08]
tech-stack:
  added: []
  patterns: [method predicates on the enum instead of per-case comparisons]
key-files:
  created:
    - database/migrations/2026_10_27_000100_add_cash_to_document_payment_method.php
  modified:
    - app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
    - app/Domain/Invoicing/Support/DocumentPayment.php
    - app/Domain/Invoicing/Support/IssueChecks.php
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - lang/cs/enums.php
    - lang/cs/invoices.php
    - tests/Feature/Schema/InvoiceTablesTest.php
    - tests/Feature/Invoicing/DocumentPaymentTest.php
    - tests/Feature/Invoicing/IssueDocumentTest.php
    - tests/Feature/Invoicing/IssuedHeaderEditTest.php
    - .planning/REQUIREMENTS.md
key-decisions:
  - "Cash answers false to all three predicates: no bank details, no owner card choice, no forced card payment"
  - "kokpit_guard_invoice_row() is untouched; payment_method was already an open column"
  - "A stored bank_account_id on a cash draft is harmless and never printed; issuing writes none for cash"
patterns-established:
  - "Rules that depend on the payment method read the enum predicates, not method comparisons"
requirements-completed: [P15-01, P15-02]
status: complete
actuals:
  tokens: 14000
  tasks: 2
  commits: 2
plan_head_before: 194ad477c0ec09cbe93ed4848143111812e6bdc5
plan_head_after: 5df0cc7c57861f4bfafb872b74e4ab4942b61210
completed: 2026-10-27
---

# Phase 15 Plan 01: Cash as a payment method Summary

**Cash is a third document payment method: PostgreSQL ties it to "no bank snapshot", a cash draft keeps the card switch off, and issuing a cash document needs no bank account and stores no snapshot or pay token.**

## Accomplishments

- Migration `2026_10_27_000100_add_cash_to_document_payment_method.php` drops and re-adds `invoices_payment_method_check` (`'transfer', 'online', 'cash'`) and `invoices_issued_fields_check` (`(payment_method IN ('online', 'cash')) = (bank_snapshot IS NULL)`), every other part verbatim from the Phase 14 migration. `down()` throws while a cash row exists, else restores both checks verbatim.
- `DocumentPaymentMethod::Cash` with `printsBankDetails()` (Transfer only), `allowsCardChoice()` (Transfer only), `forcesCardPayment()` (Online only); Czech label "Hotově".
- `DocumentPayment::forDraft()`: cash forces the switch to false and refuses an explicit `card_payment_enabled = true` under that key with `invoices.errors.card_payment_cash`. The default method of a new draft stays transfer.
- `IssueChecks` raises `bank_missing` only when the method prints bank details; `DocumentIssuer::bankSnapshot()` returns null unless it does; the Admin-only row gets switch false (so no pay token) for cash, and `bank_account_id` is filled only for a method that prints bank details.
- `REQUIREMENTS.md`: section "Cash Payments and Cash Receipts" with P15-01 to P15-12, twelve traceability rows (Pending), coverage 123 total / 122 mapped, new footer line.
- Tests added: schema (cash accepted, other value refused, credit note refused, issued cash with and without snapshot, transfer still needs one), draft rule (default transfer, switch off, explicit on refused, no account needed in EUR), and a cash EUR issue without a EUR account (null snapshot, switch off, no token, no account id, PDF text without VS or IBAN labels and without the IBAN).

## Task Commits

1. Task 1 (tracer): `12df4cf` feat(15-01): cash as the third payment method of a document
2. Task 2: `5df0cc7` docs(15-01): register P15-01 to P15-12 in REQUIREMENTS.md

## Deviations from Plan

### Auto-fixed Issues

None. One planned test edit in `IssuedHeaderEditTest.php` (invalid-method case now sends `card`) was done as written.

### Verification not run in the worktree

Per the project rules for worktrees, the Pest suites, full PHPStan and the migration were NOT run here (no shared DB, no `vendor/` in the worktree). Run after the merge into the HEAD branch, in DDEV:

- `ddev exec php artisan migrate`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch tests/Feature/Localisation/EnumLabelsTest.php`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Cheap checks done: `php -l` on all touched PHP files, Pint `--test` on all touched files (passed, using the main checkout's Pint binary), `scripts/check-sensitive.sh` clean on both commits, requirement grep gate of Task 2 OK.

Untested-until-merge risk to watch: the new IssueDocumentTest case reads the stored PDF through `Document` media and skips when Ghostscript is missing; `lang/cs/invoices.php` `payment_method_invalid` text still says "převodem nebo online" (pinned by an existing test; wording update left to the plan that edits the editor, 15-07).

## Assumptions AS-1 to AS-16

Recorded with their status (owner confirmed / adopted / recorded only) in the table of `15-01-PLAN.md`, section "Assumptions"; no change made to them here.

## Known Stubs

None.

## Threat Flags

None. T-15-01 (CHECK both directions, schema test) and T-15-02 (draft refusal, issuer switch off) are mitigated as planned; T-15-SC: composer.json and composer.lock untouched.

## Notes for later plans

- The Filament editor lists `DocumentPaymentMethod::class` as options, so cash appears in the draft editor select already; the editor UI work is 15-07.
- `DocumentPayment::forIssued()` still treats every non-online method as transfer (retakes a bank snapshot); changing an open issued document to cash is 15-07. The new CHECK refuses an inconsistent row until then.
- `InvoicePdfData::payment()` returns null for a cash document (no snapshot), so its PDF prints no payment box until 15-04.

## Self-Check: PASSED

- Migration file present; both commits `12df4cf` and `5df0cc7` are ancestors of HEAD.
