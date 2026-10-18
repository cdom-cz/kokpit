---
phase: 11-stripe-payments
plan: 09
subsystem: payments
tags: [stripe, idempotency, postgres-trigger, partial-unique-index, admin-only, on-conflict-do-nothing]

requires:
  - phase: 11-stripe-payments
    provides: "RecordPayment::recordLocked() for a card payment without a person (plan 11-02), payment_links and the shared registration files (plan 11-06), CardPaymentSetup test helpers (plan 11-03)"
provides:
  - "table card_payments: one Admin-only row per Stripe payment or refund event (matched, duplicate, unmatched with its reason, refund), independent of the webhook row"
  - "database idempotency keys: card_payments_event_unique, card_payments_session_unique (partial), card_payments_payment_intent_unique (partial, payments only), card_payments_refund_unique"
  - "card_payments_invoice_payment_fk ON DELETE SET NULL and card_payments_return_transaction_fk ON DELETE SET NULL: a payment or expense deleted through its sanctioned path leaves the record and every Stripe reference in place"
  - "kokpit_guard_card_payment() trigger card_payments_guard (KP001) and card_payments_truncate_guard"
  - "App\\Domain\\Payments\\Models\\CardPayment: recordOnce(array): ?self, amount(), offered(), isUnresolved(), status(), relations invoice, assignedInvoice, invoicePayment, paymentLink, returnTransaction, resolvedBy"
  - "CardPaymentType, CardPaymentReason, CardPaymentResolution, CardPaymentStatus (HasLabel, Czech labels in lang/cs/enums.php)"
  - "morph alias card_payment, AdminOnlyPolicy registration, model declaration, activity allowlist, canary fixture with a Stripe-event and a note needle, PgSchema::EXEMPT rows for the five Stripe id columns"
affects: [11-10, 11-11, 11-13, 11-19, card-payments, matching, overview, resolutions]

actuals:
  tokens: 52000
  tasks: 2
  commits: 2
plan_head_before: e597024337e28a239b5a2018367da5a458b4361f
plan_head_after: 1b205a972657124b69e5f7cccc6ed50a28a77cc0
commits: 2

tech-stack:
  added: []
  patterns:
    - "A record that must make a retry harmless is stored with INSERT ... ON CONFLICT DO NOTHING against unique indexes (no conflict target), so a repeat returns null and cannot abort the enclosing PostgreSQL transaction"
    - "A foreign key from a never-deleted record to a deletable row uses ON DELETE SET NULL; the guard trigger lets that null-out pass but refuses moving the link to another row"
    - "A CHECK that compares a nullable column is wrapped in COALESCE(..., false) when NULL must count as a violation (a CHECK passes on NULL)"

key-files:
  created:
    - database/migrations/2026_10_18_000500_create_card_payments_table.php
    - app/Domain/Payments/Models/CardPayment.php
    - app/Domain/Payments/Enums/CardPaymentType.php
    - app/Domain/Payments/Enums/CardPaymentReason.php
    - app/Domain/Payments/Enums/CardPaymentResolution.php
    - app/Domain/Payments/Enums/CardPaymentStatus.php
    - tests/Feature/Payments/CardPaymentRecordTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - lang/cs/enums.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Support/PgSchema.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Feature/Schema/SchemaConventionsTest.php
    - tests/Feature/Payments/PaymentLinkRecordTest.php

key-decisions:
  - "R11-09-1 to R11-09-4 applied as written (columns, checks, indexes, guard, model, labels)"
  - "The guard lets invoice_payment_id and return_transaction_id go to null (the database does it on deletion) or be filled once, and refuses moving a set link to another row; the plan named both columns as changeable without this limit"
  - "recordOnce() accepts only the recordable columns and throws InvalidArgumentException for any other (the resolution and its fields can never be set at creation); it reads the new row back as system"
  - "The creation of a record is a plain insert and writes no activity row; the row itself (received_at, type, amounts) is its history, and every later change goes through model saves with the allowlist"
  - "The reason check is wrapped in COALESCE(..., false): without it a duplicate or unmatched record with a NULL reason passed the check"

patterns-established:
  - "recordOnce() is called inside the caller's transaction, after the document lock and RecordPayment::recordLocked(); the caller passes invoice_payment_id of the new payment for a matched record"
  - "A later plan resolves a record with forceFill of resolution, resolved_at, resolved_by (and resolution_note or assigned_invoice_id) in one save; the database refuses a second resolution"

requirements-completed: []

duration: 45min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 09: Card payment record Summary

**Every Stripe payment or refund event now has a durable Admin-only record whose unique Stripe references are the real idempotency keys: the record survives a payment deleted through DeletePayment and the pruning of the webhook row, so no retry or replay can book the money again, and a resolution of it is final.**

## Performance

- **Duration:** about 45 min
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 17 (7 created, 10 modified)

## Accomplishments

- Migration `2026_10_18_000500_create_card_payments_table` creates `card_payments` with the type, reason, resolution, resolution-type, resolved, note, assigned, amount, currency, offered, refund-id and matched-invoice checks, and the unique event id, refund id, payment key, return-expense key and the two partial unique indexes (session id; payment intent among non-refunds) of R11-09-1. A refund may share the payment intent of the payment it returns.
- `card_payments_invoice_payment_fk ... ON DELETE SET NULL` (and the same for `return_transaction_id`): the tracer proves the D-14 contract. A matched record is written once; `DeletePayment` removes the payment and its income; the record is still there with its event, session, payment intent and link ids, `invoice_payment_id` null and status "Smazaná platba"; the same event, a new event of the same session and a new event and session of the same payment intent each return null and store nothing.
- `kokpit_guard_card_payment()` (KP001): a delete is refused; type, reason, amounts, currency, offered columns, every Stripe reference, document, link, receipt time, creation time and key never change; a resolution is final together with its time, person, note and assigned document; the payment and expense links are never moved to another row. TRUNCATE is refused by the shared guard.
- `CardPayment` model: Admin-only (`DeniesPartners`, `AdminOnlyPolicy`, morph alias `card_payment`), `recordOnce()` (LogicException outside a transaction, `insertOrIgnore` with a uuid v7 id, null on any existing reference), `amount()`, `offered()`, `isUnresolved()`, derived `status()` (Booked, PaymentDeleted, Unresolved, Resolved) and six relations. The activity allowlist holds no note and no Stripe reference (tested against a real resolution).
- Four enums with the Czech labels of UI-SPEC U-11 and U-12, the canary fixture (an unmatched record resolved with a note; needles: its event id and the note) and five `PgSchema::EXEMPT` rows for Stripe's text ids.
- `CardPaymentRecordTest` (57 tests, 119 assertions): the tracer, a Partner of the same client sees nothing and the policy refuses, every refused insert (23514) and duplicate reference (23505), every frozen column (KP001), delete and TRUNCATE, a final resolution, every inconsistent resolution (23514), the accepted resolutions, and the allowlisted history.
- Verification: Payments, Invoicing, Arch, Isolation and Schema suites 1626 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.

## Task Commits

1. Task 1: tracer, table, model, enums and registrations - `2c0be32` (feat)
2. Task 2: guard update branch, closed reason check and the raw-SQL tests - `1b205a9` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-09-1 to R11-09-4 were applied as written; the additions are the limit on moving the two money links, the recordable-column allowlist of `recordOnce()` and the COALESCE around the reason check.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Registry and exact-list tests that the new model and columns break**
- **Found during:** Task 1 (Arch, Isolation and Schema run)
- **Issue:** `tests/Isolation/CanaryRegistryTest.php` (the exact list of PartnerIsolated models) and `tests/Feature/Schema/SchemaConventionsTest.php` (the exact EXEMPT keys) fail once the model and its Stripe columns exist; the plan named the isolated-models list and `loggingModels()` but not these two assertions.
- **Fix:** added `CardPayment` to the isolated-model list and the five new keys to the expected EXEMPT list.
- **Files modified:** `tests/Isolation/CanaryRegistryTest.php`, `tests/Feature/Schema/SchemaConventionsTest.php`
- **Commit:** `2c0be32`

**2. [Rule 3 - Blocking] TRUNCATE of payment_links is refused by PostgreSQL before the guard runs**
- **Found during:** Task 1 (Payments suite)
- **Issue:** `card_payments` now references `payment_links`, so the plan 11-06 test `TRUNCATE payment_links` answers SQLSTATE 0A000 instead of KP001.
- **Fix:** the test truncates `payment_links, card_payments`, which reaches the guard of either table and still expects KP001.
- **Files modified:** `tests/Feature/Payments/PaymentLinkRecordTest.php`
- **Commit:** `2c0be32`

**3. [Rule 1 - Bug] The reason check let a NULL reason through**
- **Found during:** Task 2 (RED run of the raw-insert tests)
- **Issue:** `type = 'duplicate' AND reason = 'already_paid'` is NULL for a NULL reason and a CHECK passes on NULL, so a duplicate or unmatched record without a reason was accepted.
- **Fix:** the whole expression of `card_payments_reason_check` is wrapped in `COALESCE(..., false)`.
- **Files modified:** `database/migrations/2026_10_18_000500_create_card_payments_table.php`
- **Commit:** `1b205a9`

### Choices within the plan's latitude

- Task 1's tests and implementation were written before the first test run, so there is no recorded RED run for the tracer; Task 2's RED run failed 19 of 57 tests (the whole UPDATE branch of the guard and the reason check) before the guard was completed.
- The migration carries the full set of checks and indexes from Task 1 (they are one table definition); Task 2 added the UPDATE branch of the guard and the reason-check fix, as the plan split it.
- The creation of a record writes no activity row (a plain `insertOrIgnore` raises no model event); the plan's "activity log of every change" is met for every change after creation.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-32 (a redelivered or replayed event booking money twice, also after a deletion): unique event, session and payment intent, records never deleted, SET NULL payment key, tested by the D-14 tracer. T-11-33 (a Partner reading card payments): `DeniesPartners`, `AdminOnlyPolicy`, the canary fixture with a Stripe-event and a note needle, and an allowlist with neither. T-11-34 (a record or its resolution edited or reopened): the guard trigger, tested for every frozen column and a reopened resolution. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-13 (matching) calls `CardPayment::recordOnce()` inside its transaction, after locking the document and calling `RecordPayment::recordLocked()` for a matched payment, passing `invoice_payment_id`; a null result means the event was already recorded and nothing must be booked. A duplicate carries `reason => already_paid`, an unmatched payment one of `amount`, `currency`, `cancelled`, `undetermined`, a refund `type => refund` with `stripe_refund_id` and no reason.
- Plan 11-19 (resolutions) saves `resolution`, `resolved_at`, `resolved_by` and `resolution_note` or `assigned_invoice_id` in one `forceFill()->save()`; for an assignment it also sets `invoice_payment_id` of the new payment, and for a return it sets `return_transaction_id`. A second resolution is refused by the database with KP001.
- PA-06, PA-07 and PA-08 are not marked complete: this plan delivers the record and its idempotency keys, not the processing job, the matching or the resolutions.

## Self-Check: PASSED

- Files: all 17 listed files exist.
- Commits: `2c0be32` and `1b205a9` are ancestors of HEAD.
