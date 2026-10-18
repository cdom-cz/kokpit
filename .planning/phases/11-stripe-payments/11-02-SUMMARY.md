---
phase: 11-stripe-payments
plan: 02
subsystem: payments
tags: [stripe, card-payment, invoice-payments, recordpayment, finance, check-constraint]

requires:
  - phase: 10-invoicing
    provides: "RecordPayment::handle, RecordTransaction, DeletePayment, the Platby tab and the payment modal"
provides:
  - "RecordPayment::recordLocked(Invoice, PaymentFacts, ?User): the system-callable write core (payment, state check, CNB check, income)"
  - "RecordTransaction::writePaymentIncome(array, array): link-only income write without a Gate; handle() stays Gate then the shared write"
  - "PaymentMethod::Card (label Kartou), manual(), isManual(); PaymentFacts value object; PaymentInput::toFacts()"
  - "Migration: invoice_payments_method_check allows card, created_by nullable, invoice_payments_creator_check (card only without a creator)"
  - "Platby tab: card payment row labelled Kartou, card body on Smazat platbu; payment modal lists only Prevodem and Hotove"
affects: [11-09, 11-10, 11-13, 11-19, stripe-webhook, payment-matching, card-payments-overview]

actuals:
  tokens: 10700
  tasks: 3
  commits: 3
plan_head_before: 5c2f14681d9e18ce0abd4b2a53e49f584a72be86
plan_head_after: 52510834ab41b8ce70ed2b9d1ed0ab70f62713d1
commits: 3

tech-stack:
  added: []
  patterns:
    - "One write core with two callers: the manual Action authorises and locks, the core is guarded by type and transaction instead of a Gate"
    - "A system write path is made unreachable for arbitrary data by a required typed link (writePaymentIncome needs the invoice and payment ids)"
    - "A nullable column is allowed for one enum value only through a CHECK that names that value"

key-files:
  created:
    - database/migrations/2026_10_18_000100_allow_card_payments.php
    - app/Domain/Invoicing/Support/PaymentFacts.php
    - tests/Feature/Invoicing/CardPaymentCoreTest.php
  modified:
    - app/Domain/Invoicing/Actions/RecordPayment.php
    - app/Domain/Finance/Actions/RecordTransaction.php
    - app/Domain/Invoicing/Enums/PaymentMethod.php
    - app/Domain/Invoicing/Support/PaymentInput.php
    - app/Filament/Support/RecordPaymentAction.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - lang/cs/enums.php
    - lang/cs/invoices.php
    - tests/Unit/Invoicing/InvoiceEnumsTest.php

key-decisions:
  - "R11-02-1: recordLocked() holds the Phase 10 body after the lock; it throws LogicException outside a transaction and for a null actor with a method other than card; created_by is $actor?->getKey()"
  - "R11-02-2: writePaymentIncome() requires the invoice link by type and runs only inside a transaction; every payment's income (manual, carried, card) now goes through it, authorised once by RecordPayment"
  - "R11-02-3: card income description is invoices.payment_income.card_description"
  - "R11-02-4: the database holds the rule: method IN (transfer, cash, card) and CHECK (method = 'card' OR created_by IS NOT NULL); down() refuses while a card or creator-less payment exists"
  - "R11-02-5: card is never selectable by hand: PaymentMethod::manual(), the modal options, PaymentInput::method() refusing card under method"

patterns-established:
  - "System callers of the payment core build PaymentFacts and run recordLocked() inside PartnerContext::runAsSystem and one DB::transaction with the invoice locked (recordAsJob() in the test is the reference)"
  - "Forcing the enum state cast on a Filament field with a restricted option list: ->enum(PaymentMethod::class)->options([...])"

requirements-completed: []

duration: 55min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 02: Card payment core Summary

**A queue job with no user can now record a card payment of an issued invoice through the same core as a manual payment (state check, CNB check, income in the system category), the database accepts a missing creator only for method card, and the Admin sees the payment as "Kartou" with an honest deletion warning.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 12 (3 created, 9 modified)

## Accomplishments

- `RecordPayment::handle()` keeps the Gate, the transaction and the lock, then calls `recordLocked()` with `PaymentInput::toFacts()`. `recordLocked()` is the former body: `payment_not_allowed` for a draft, a credit note and a cancelled invoice, the CNB-list check for the income date, the `forceFill` insert, and the income for an invoice only (a proforma payment books none, Phase 10 D-18).
- `RecordTransaction` has one write and two entry points: `handle()` (Gate first) and `writePaymentIncome()` (no Gate, typed invoice link required, LogicException outside a transaction). `bookIncome()` of RecordPayment and `carry()` use the latter.
- Migration `2026_10_18_000100_allow_card_payments`: method check with card, `created_by` nullable, `invoice_payments_creator_check`. Raw-SQL tests prove 23514 for a transfer or cash payment without a creator and for an unknown method, and acceptance of a card payment with or without a creator.
- The Admin cannot choose card: the modal offers exactly "Prevodem" and "Hotove" (state still cast to the enum), and `PaymentInput::method()` refuses card (string or enum) under `method`.
- Platby tab: a card payment shows Zpusob "Kartou" and the system income category; "Smazat platbu" on it shows the UI-SPEC Surface G body, a transfer payment keeps the Phase 10 body, and confirming deletes payment and income through `DeletePayment` (D-14).
- Invariant test over `PaymentMethod::cases()` and invoice/proforma: exactly one income per payment on an invoice, none on a proforma.
- Verification: invoicing, finance and arch suites green (820 tests in the last run; invoicing + finance + arch + unit + isolation 1930 passed before the final modal fix, then re-run green for the changed files), Pint and PHPStan (level 8) clean, `scripts/check-sensitive.sh` clean.

## Task Commits

1. Task 1: tracer, a job with no user records a card payment and books the income once - `60593bc` (feat)
2. Task 2: core guards, database creator rule, one-income invariant - `cb96393` (test)
3. Task 3: card payment in the Platby tab, manual-only modal - `5251083` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-02-1 to R11-02-5 were applied as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Existing enum unit test asserted the two-case list**
- **Found during:** Task 1
- **Issue:** `tests/Unit/Invoicing/InvoiceEnumsTest.php` expected `PaymentMethod::cases()` to be `['transfer', 'cash']`, which the new case makes false.
- **Fix:** The test now expects the three cases, the Kartou label, `manual()` and `isManual()`.
- **Files modified:** `tests/Unit/Invoicing/InvoiceEnumsTest.php`
- **Commit:** `60593bc`

**2. [Rule 1 - Bug] Restricting the modal options lost the enum state cast**
- **Found during:** Task 3
- **Issue:** `->options([...])` with a plain array no longer cast the ToggleButtons state to `PaymentMethod`, which `PaymentModalTest` asserts.
- **Fix:** `->enum(PaymentMethod::class)` next to the restricted option list keeps the cast.
- **Files modified:** `app/Filament/Support/RecordPaymentAction.php`
- **Commit:** `5251083`

### Choices within the plan's latitude

- Task 2 lists `RecordPayment.php` and `PaymentInput.php` as files; their guards (`LogicException` cases, card refusal under `method`) were already needed by the Task 1 tracer, so they were committed there and Task 2 is tests only. The Task 2 tests passed on first run for that reason, not as a RED step. Task 3 had a true RED (modal options and card body failed before the change).
- `writePaymentIncome()` additionally throws `LogicException` outside a transaction (the plan required it for `recordLocked()`; the same guard on the income write closes the apart-from-payment path). Under RefreshDatabase every test already runs in a transaction, so the `recordLocked()` guard test takes the test transaction back with `DB::rollBack(0)`.
- `recordLocked()` does its own `payment_not_allowed` check (copied via a private `refuses()` helper), so a system caller is protected without relying on `handle()`; `handle()` keeps its early check so the error is unchanged and thrown before input validation, as in Phase 10.

None of these change a contract that later plans rely on.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-04 (no Gate on `recordLocked()` and `writePaymentIncome()`), T-11-05 (creator-less payment) and T-11-06 (manual choice of card) are mitigated as listed; T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-10 adds the payment-link reconcile call inside `recordLocked()`; plans 11-13 (matching) and 11-19 (assign) call it as shown by `recordAsJob()` in `tests/Feature/Invoicing/CardPaymentCoreTest.php`: `PartnerContext::runAsSystem`, one `DB::transaction`, the invoice locked with `lockForUpdate()`, then `recordLocked($locked, $facts, $actor)` with `$actor` null for the webhook and the Admin for an assignment.
- Requirements PA-06 and PA-08 are not marked complete: this plan delivers only the foundation (the write core), not the matching or the resolution.

## Self-Check: PASSED

- Files: all 12 listed files exist.
- Commits: `60593bc`, `cb96393`, `5251083` are ancestors of HEAD.
