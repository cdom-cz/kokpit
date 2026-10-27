---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 06
subsystem: invoicing
tags: [cash-receipt, ppd, partner-isolation, policy, global-scope, canary, audit-matrix]
requires:
  - phase: 15-02
    provides: cash_receipts table (single-table shape), row guard, Partner-safe column pin
provides:
  - CashReceipt model (Partner-isolated, scope own client + issued document + client not archived)
  - CashReceiptLockState enum (open, locked)
  - CashReceiptPolicy (viewAny true, view own client, every write denied) registered in AccessServiceProvider
  - morph alias cash_receipt
  - Invoice::cashReceipts() and InvoicePayment::cashReceipt() relations
  - receipt rows in ModelDeclarationTest, CanaryRegistry, CanaryRegistryTest, PartnerMatrix, PartnerActionsTest scene, ChannelMatrix
affects: [15-08, 15-09, 15-10, 15-11, 15-12, 15-13, 15-14]
tech-stack:
  added: []
  patterns:
    - "Partner-isolated model checks parent state and client archive with plain EXISTS subqueries"
    - "A model that must not log through model events simply omits LogsAllowlistedActivity; the activity channel is then not applicable in ChannelMatrix"
key-files:
  created:
    - app/Domain/Invoicing/Enums/CashReceiptLockState.php
    - app/Domain/Invoicing/Models/CashReceipt.php
    - app/Domain/Invoicing/Policies/CashReceiptPolicy.php
    - tests/Feature/Invoicing/CashReceiptModelTest.php
  modified:
    - app/Domain/Invoicing/Models/Invoice.php
    - app/Domain/Invoicing/Models/InvoicePayment.php
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Database/MorphMap.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/PartnerMatrix.php
    - tests/Support/ChannelMatrix.php
    - tests/Audit/PartnerActionsTest.php
key-decisions:
  - "R-15-06-1 to R-15-06-5 applied as written"
  - "Canary: the existing canary payment was switched to cash (no second payment added), so the one canary payment carries the receipt"
  - "ChannelMatrix activity cell is not applicable (not 'closed'): ChannelMatrixTest requires the cell to be n/a exactly for models that write no activity rows"
patterns-established:
  - "Receipt canary: the canary text is the receipt purpose"
requirements-completed: [P15-11]
status: complete
duration: not measured
completed: 2026-10-27
actuals:
  tokens: 9800
  tasks: 2
  commits: 4
plan_head_before: b16d274f6b39d49100617994be6b4bf4b78ce9a4
plan_head_after: 3de5182cb06601c0989781898782f9b1b4f2a6c9
---

# Phase 15 Plan 06: Cash receipt model and isolation harnesses Summary

**`CashReceipt` is a Partner-isolated, read-only model (own client, issued document, client not archived) with a view-only policy, a `cash_receipt` morph alias, relations on the invoice and the payment, and rows in every declaration, canary, permission and channel harness.**

## Accomplishments

- `CashReceipt extends KokpitModel implements PartnerIsolated`, `use IsolatesPartners`, nothing fillable, no `LogsAllowlistedActivity` (history is the explicit audit rows of the Actions, 15-08). Casts per R-15-06-2 (`language` is `App\Domain\Clients\Enums\InvoiceLanguage`). Methods `amount()`, `isLocked()`, `isCancelled()`; relations `invoice()`, `payment()` (Admin-only target, empty for a Partner), `pdfDocument()`. A cancelled receipt stays visible to a Partner; no reason column exists.
- `CashReceiptLockState` (Open, Locked), no label, separate from `InvoiceLockState` so `InvoiceUnlockPathTest` is untouched.
- `CashReceiptPolicy extends KokpitPolicy`: `viewAny` true, `view` for the own client's receipt, writes keep the base denial; registered next to `InvoicePolicy`.
- `Invoice::cashReceipts()` (ordered `issued_on`, `number`) and `InvoicePayment::cashReceipt()` (HasOne); neither touches its parent.
- Harnesses: `ModelDeclarationTest` (app model list and Partner-isolated list), `CanaryRegistry` fixture, `CanaryRegistryTest` list, `PartnerMatrix::partnerAbilities()` row (class `viewAny` true, `create` false; `view` own true / foreign false; `update`, `delete` false), `ChannelMatrix` row.
- `CashReceiptModelTest`: 13 cases (casts, Partner scope and policy, writes denied, payment null for a Partner, other client, archived client, cancelled stays visible, morph alias, no model logging, relations, ordering, no touch).

## Task Commits

1. Task 1 RED: `818d375` test(15-06) failing tests for the cash receipt model and its declaration entries
2. Task 1 GREEN: `c0c9470` feat(15-06) Partner-isolated cash receipt model, policy and canary fixture
3. Task 2 RED: `84e2009` test(15-06) failing tests for the receipt relations
4. Task 2 GREEN: `3de5182` feat(15-06) relations, PartnerMatrix, ChannelMatrix, PartnerActionsTest scene

`commits: 4` is measured with `git rev-list --count` from the base to `3de5182`; the SUMMARY commit follows it. No refactor commit was needed.

## TDD Gate Compliance

RED commits (`818d375`, `84e2009`) precede their GREEN commits in history. The RED runs were NOT executed (the worktree has no `vendor/` and no shared database; project rule forbids running the suite here). The RED expectation holds by construction: `CashReceipt` and the relations did not exist at the RED commits. No `check tdd-red-evidence` record exists for the same reason; flagged for the post-merge run.

## Decisions and judgement calls

- **Canary choice (R-15-06-5):** no count pins one payment per canary document in the files read, so the existing canary payment was switched from transfer to cash and carries the receipt, instead of adding a second payment (which would also change the remaining amount used by the payment link). Receipt number is `PPD` plus random digits (the `number` unique index is global, not per client).
- **PartnerActionsTest (not in the plan's file list):** adding a `partnerAbilities()` row requires the scene of `PartnerActionsTest` to build the `own_visible` and `foreign` record states ("builds every declared record state"). Added `CashReceipt::class => [own_visible, foreign]` there (Rule 3, blocking). No `own_hidden` state is declared, since a receipt of a draft document cannot exist (the database guard needs a payment of an issued document).
- **Activity cell:** the plan text says "`activity` closed for the Partner". `ChannelMatrixTest` ("applies the activity channel exactly to the models that write activity rows") forces the cell to be not applicable for a model without `LogsAllowlistedActivity`, so the cell is the shared `$activityNa` (reason: writes no activity rows). The Partner still reads no activity of a receipt, because the Activity log is Admin-only. `ui` and `downloads` cells are `none` until 15-11.
- The test builds an issued `invoice` kind (not a proforma): the receipt guard only needs a cash payment of an issued document with the same number.

## Deviations from Plan

1. **[Rule 3 - Blocking] PartnerActionsTest scene** extended (above).
2. **[Rule 1 - Consistency] ChannelMatrix activity cell** n/a instead of "closed" (above).

No auth gates. No stubs.

## Verification status

Done in the worktree: `php -l` on every touched PHP file; Pint `--test` on `app/Domain/Invoicing`, `tests/Support`, `tests/Audit` and the touched test files (main checkout's binary, passed); `scripts/check-sensitive.sh` clean and the lefthook hook (sensitive-content, gitleaks) passed on every commit.

NOT run, by project rule (no `vendor/`, no shared database in a worktree). To run after the merge into the HEAD branch, in DDEV:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptModelTest.php tests/Arch/ModelDeclarationTest.php tests/Arch/ActivityAllowlistTest.php tests/Isolation/CanaryRegistryTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Invoicing tests/Arch tests/Isolation tests/Audit`
- `ddev exec composer partner-audit`
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to check first on that run: (a) the canary receipt insert against the 15-02 guard and the `invoice_payments` cash payment on a transfer invoice; (b) switching the canary payment to cash against any test that reads its method; (c) `ChannelMatrixTest` `none` cells for `ui` and `downloads` of the receipt (the receipt purpose carries the canary and must appear on no Partner page); (d) PHPStan generics on `cashReceipts()` / `cashReceipt()`; (e) `getFillable()` of `KokpitModel` being `[]` in the model test.

## Known Stubs

None.

## Threat Flags

None. T-15-15 (scope plus policy, canary, matrices), T-15-16 (payment relation empty for a Partner, tested) and T-15-18 (no model-event logging; `ActivityAllowlistTest` file unchanged) are mitigated as planned; T-15-SC: composer.json and composer.lock untouched.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Models/CashReceipt.php, app/Domain/Invoicing/Policies/CashReceiptPolicy.php, app/Domain/Invoicing/Enums/CashReceiptLockState.php, tests/Feature/Invoicing/CashReceiptModelTest.php
- FOUND commits (ancestors of HEAD): 818d375, c0c9470, 84e2009, 3de5182
- `tests/Arch/ActivityAllowlistTest.php` unchanged
