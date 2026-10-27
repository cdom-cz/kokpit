---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 14
subsystem: invoicing
tags: [cash-receipt, filament, relation-manager, platby-tab, contributing, hand-over]
requires:
  - phase: 15-06
    provides: CashReceipt model and the cashReceipt relations of InvoicePayment
  - phase: 15-08
    provides: CashReceiptAudit::cancelReason(), lang/cs/cash_receipts.php
  - phase: 15-11
    provides: files.cash-receipt route, CashReceiptPdfUrl
  - phase: 15-12
    provides: CancelCashReceipt, the delete refusal of DeletePayment
  - phase: 15-13
    provides: UpdateCashReceipt, UnlockCashReceipt, RegenerateCashReceiptPdf
provides:
  - Platby tab shows the receipt number, state, cancel reason tooltip and the warning for a payment without a valid receipt
  - Receipt actions per payment row (download, edit, unlock, recreate PDF, cancel) calling the domain Actions
  - Delete of a payment disabled while its own receipt is valid
  - CONTRIBUTING "Cash receipts." hand-over bullet and the documentation test that proves it
affects: [later phases that touch the Platby tab or the cash receipt]
tech-stack:
  added: []
  patterns:
    - "One runReceiptAction() frame for every receipt row action: Admin check, payment re-read through the owner invoice, domain Action, form-field errors mapped to mountedActions.0.data.<key>, other refusals as a danger notification"
    - "Signed download link built at click time and followed by a redirect, never rendered into the page"
key-files:
  created:
    - tests/Feature/Invoicing/CashReceiptAdminTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - lang/cs/cash_receipts.php
    - tests/Feature/Invoicing/DeletePaymentActionTest.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
key-decisions:
  - "R-15-14-1 to R-15-14-4 applied as written, with one presentation deviation (see below): the warning for a payment without a valid receipt is its own badge column"
  - "A carried copy shows the receipt of its proforma payment (number, state, 'ze zálohové faktury') and offers only the download"
  - "Regenerate failures (DomainException, LogicException, RuntimeException) are shown as one fixed Czech message, no path or value"
requirements-completed: [P15-10, P15-11, P15-12, P15-07]
status: complete
duration: not measured
completed: 2026-10-27
commits: 5
plan_head_before: 86c6d253e1295474718d4707746c1fbc686f9ecc
plan_head_after: 10529134ccddbd005ba0a4afea24dd91a6cde704
actuals:
  tokens: 26000
  tasks: 3
  commits: 5
---

# Phase 15 Plan 14: Receipt in the Platby tab and the phase hand-over Summary

**The Platby tab of an invoice or proforma now shows each payment's cash receipt with its state and offers exactly the allowed receipt actions (download, edit while open, unlock with a reason, recreate a missing PDF, cancel with a reason), the delete of a payment is blocked until its receipt is cancelled, and CONTRIBUTING hands the cash receipts over with a documentation test that proves every name in it exists.**

## Accomplishments

- **Columns (R-15-14-1).** `InvoicePaymentsRelationManager` eager-loads `cashReceipt` and `carriedFrom.cashReceipt`. New columns after `method`: `cash_receipt` (receipt number, or the number of the proforma payment's receipt for a carried copy with the description "ze zálohové faktury"), `cash_receipt_state` (badge Otevřený gray, Uzamčený success, Zrušený danger; tooltip with `CashReceiptAudit::cancelReason()` on a cancelled receipt) and `without_valid_receipt` (warning badge "Platba bez platného dokladu" when the payment's own receipt is cancelled). A payment without a receipt shows a dash. The existing confirmation column is unchanged, so an open receipt with a skipped confirmation reads "Otevřený" next to "Bez e-mailu" (AS-10).
- **Actions (R-15-14-2).** One `ActionGroup` per row: `downloadCashReceipt` (redirect to `CashReceiptPdfUrl::download()` built at click time, T-15-40), `editCashReceipt` (modal prefilled from the snapshots: date, purpose, customer and supplier lines; only for an open, not cancelled own receipt), `unlockCashReceipt` (reason modal; only for a locked, not cancelled receipt), `regenerateCashReceiptPdf` (only when the receipt has no PDF), `cancelCashReceipt` (danger, reason modal; only for a not cancelled receipt). Every action goes through the private `runReceiptAction()` frame: `InvoiceResource::canAccess()`, the payment re-read through `$this->invoice()->payments()` and its receipt (T-15-38), the domain Action, field errors shown under the form field, any other refusal as a danger notification, `invoice-payment-saved` dispatched. A carried copy offers only the download.
- **Delete (R-15-14-3).** `deletePaymentAction()` is also disabled while the payment's own receipt is not cancelled, with the tooltip "Nejdříve zrušte příjmový doklad :number."; the carried-copy rule keeps priority. The class docblock gained the receipt paragraph. The Action-level refusal of 15-12 stays the authority.
- **Hand-over (R-15-14-4).** CONTRIBUTING gained the `- **Cash receipts.**` bullet (cash method and predicates, `cash_receipts` and its guard, `IssueCashReceipt`, the series and pattern, `StoreCashReceiptPdf`, `SendPaymentConfirmation`, the four Actions, the unlock path pinned by `CashReceiptUnlockPathTest`, the signed route, the Admin and Partner surfaces, the owner acceptance of 2026-10-27 that D-06 is enforced in the application only, and AS-1 to AS-16 with their status, AS-14 and AS-15 as legal facts with no product guard). The "Payment method and bank accounts." bullet says `cash` is the third method and prints no bank details. `RepositoryFilesTest` has the new case "hands the cash receipts over: the Cash receipts bullet names only what exists".
- **Tests.** `CashReceiptAdminTest` has 15 `it` blocks (tracer: number and state, download redirect, disabled delete, short reason error, cancel then delete; edit prefilled and saved, refused date, edit hidden when locked or cancelled; unlock; PDF recreation; carried copy; skipped confirmation; transfer payment; Partner 403; forged payment of another invoice). `DeletePaymentActionTest`: the 15-12 cash case now asserts the disabled action and the `delete_blocked` tooltip.

## Task Commits

1. Task 1 RED: `9e1801b` test(15-14) failing tests for the receipt in the Platby tab, download, cancel and the blocked delete
2. Task 1 GREEN: `0f1a275` feat(15-14) show the cash receipt in the Platby tab with download, cancel and a blocked delete
3. Task 2 RED: `7157f5a` test(15-14) failing tests for the receipt edit, unlock, PDF recreation and the carried copy
4. Task 2 GREEN: `4c188db` feat(15-14) edit, unlock and recreate the PDF of a cash receipt from the payment row
5. Task 3: `1052913` docs(15-14) hand the cash receipts over in CONTRIBUTING and prove the bullet by test

## TDD Gate Compliance

RED commits `9e1801b` and `7157f5a` precede their GREEN commits `0f1a275` and `4c188db`. The RED runs were NOT executed: the worktree has no `vendor/` and no shared database, and the project rule forbids running the suite or migrations here. By construction the actions `downloadCashReceipt`, `cancelCashReceipt`, `editCashReceipt`, `unlockCashReceipt` and `regenerateCashReceiptPdf` and the `cash_receipts.admin.*` keys did not exist at the RED commits, so the new tests fail there. No `check tdd-red-evidence` record exists. Flagged for the post-merge run.

## Deviations from Plan

### Auto-fixed Issues

None.

### Presentation deviation

- **Warning as a badge column, not a column description.** R-15-14-1 words the warning "Platba bez platného dokladu" as a description in warning color; the must-have truth words it as a "warning badge". A Filament column description cannot be colored without hand-written inline markup, so the warning is its own `without_valid_receipt` badge column with color `warning` (empty for every other row). Tests assert the text only.
- **`DeletePaymentTest.php` not changed.** It is listed in the plan files, but it tests the `DeletePayment` Action, whose receipt refusal is already proven in `CashReceiptCancelTest` (15-12); the screen rule is covered in `DeletePaymentActionTest` and `CashReceiptAdminTest`.

### Process (worktree limits)

- The plan ends with "run the full gate". It was NOT run here: no `vendor/` in the worktree, no shared database, and CLAUDE.md forbids migrations and the full suite in a worktree. Done here: `php -l`, Pint (main checkout binary, worktree config) on every touched PHP and lang file, `scripts/check-sensitive.sh`, and the lefthook hook (sensitive-content and gitleaks) on every commit. PHPStan was not run; types were reviewed by hand (relation properties checked with `!== null` before use, no redundant nullsafe on known non-null types).
- **`tests/.pest/shards.json` is NOT refreshed.** It needs `pest --update-shards` over the whole suite. The acceptance check `grep -q "CashReceiptIssueTest" tests/.pest/shards.json` therefore does not hold yet; the orchestrator runs the refresh after the merge. No timings were invented.
- **Tracer gate.** The end-to-end re-run of `<verify>` after the tracer task could not be executed for the same reason; it is the first thing to run after the merge.

## Known Stubs

None.

## Threat Flags

None. T-15-38 mitigated (every action re-reads the payment through the owner invoice before calling the Action; forged-id test); T-15-39 mitigated (the relation manager keeps `AccessRule(Audience::AdminOnly)`, actions check `InvoiceResource::canAccess()`, the Actions authorise again; Partner 403 test); T-15-40 mitigated (the link is built at click time and redirected to, the page-source assertion has no `signature=`); T-15-41 mitigated (fictional data, check-sensitive on every commit); T-15-SC: composer.json and composer.lock untouched.

## Verification status

NOT run, by project rule. To run after the merge into the HEAD branch, in DDEV, after `ddev exec php artisan migrate`:

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptAdminTest.php tests/Feature/Invoicing/DeletePaymentTest.php tests/Feature/Invoicing/DeletePaymentActionTest.php tests/Feature/Invoicing/PaymentConfirmationAdminTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php`
- `ddev exec vendor/bin/pest --exclude-testsuite=Audit --update-shards` and commit `tests/.pest/shards.json`
- `ddev exec composer ci`

Risks to check first: (a) Filament testing helpers: `assertActionDataSet` on a table action, `assertTableActionHidden` / `Visible` on actions inside an `ActionGroup`, and `assertHasActionErrors(['reason'])` for the form rule closure; (b) the error mapping to `mountedActions.0.data.<key>` for a table row action (the same path as `ViewInvoice::unlockAction()` uses for a page action); (c) tooltip text and the cancel reason asserted with `assertSee` (same practice as the carried-payment tooltip test); (d) `DatePicker` with `native(false)` returning a `Y-m-d` string to `UpdateCashReceipt`; (e) PHPStan on the magic `cashReceipt` / `carriedFrom` properties and the `match(true)` colors; (f) the cancelled receipt's `Platba bez platného dokladu` column text in the Livewire HTML; (g) other tests that enumerate the columns or actions of the payments tab.

## Self-Check: PASSED

- FOUND: tests/Feature/Invoicing/CashReceiptAdminTest.php, app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php, lang/cs/cash_receipts.php, CONTRIBUTING.md (bullet `- **Cash receipts.**`), tests/Feature/Repo/RepositoryFilesTest.php (case "hands the cash receipts over")
- FOUND commits (ancestors of HEAD): 9e1801b, 0f1a275, 7157f5a, 4c188db, 1052913
- `grep -c "    it(" tests/Feature/Invoicing/CashReceiptAdminTest.php` prints 15
- STATE.md and ROADMAP.md untouched
