---
phase: 10-invoicing
plan: 19
subsystem: invoicing-receivables
tags: [invoicing, receivables, overdue, finance, payment-income, api]

requires:
  - phase: 10-invoicing
    provides: "InvoiceBalances with PAID_SQL, CREDITED_SQL, REMAINING_SQL and RecordPayment, which books the income of an invoice payment (plan 10-16)"
  - phase: 10-invoicing
    provides: "Invoice::CANCELLED_SQL, InvoiceStatus, InvoiceFixtures issued()/stornoed(), the payment-income database guard (plans 10-04, 10-05, 10-06, 10-12)"
  - phase: 09.1-transactions-api
    provides: "PATCH and DELETE /api/v1/transactions/{id} calling UpdateTransaction and DeleteTransaction"
provides:
  - "ReceivablesReader: statuses(), constrain(), tabCounts() (one aggregate query) and summary() for the list, the dashboard tile and the client panels, bound to a Prague day from PHP"
  - "InvoiceListTab: the seven list tabs of ruling R-19-3 with Czech labels"
  - "UpdateTransaction and DeleteTransaction refuse the income of an invoice payment (ruling R-19-1): only date and category change, and it is never deleted on the finance side or through the API"
  - "The documented 422 of the Transactions API destroy endpoint and the update rule in its description"
affects: [10-21, 10-22, 10-23, 10-30, 10-39, 10-41]

actuals:
  tokens: 17570
  tasks: 3
  commits: 5
plan_head_before: 97da5a6f87e15f29ea3959ad46aae2dc45787f29
plan_head_after: 61bdbfc7440c5afba6bb536ffb381993e80d749b
commits: 5

coverage:
  - deliverable: "Overdue is derived, never stored: an issued invoice or proforma, not cancelled, with a remaining amount and a due date before today in Europe/Prague (today bound from PHP); a partly paid document counts by its remaining amount; a due date of today is not late; the Prague midnight, not the UTC one, turns it overdue"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#the tracer: an invoice past its due date"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#the due date and the Prague day"
        status: pass
    human_judgment: false
  - deliverable: "One reader derives every display status (draft, unpaid, partly paid, overdue, paid, cancelled, converted, paid proforma awaiting its invoice, credit note), each list tab returns exactly its documents and its count equals the filter, from one aggregate query"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#every status and every tab for a mixed portfolio"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#issues one query for all the tab counts"
        status: pass
    human_judgment: false
  - deliverable: "A paid proforma without an invoice issued from it is the attention item with its own tab filter, count and summary figure"
    requirement: "IN-06"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#returns exactly its documents under each tab and counts them with the same conditions"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#summarises the owed money, the late money and the paid proformas waiting for an invoice"
        status: pass
    human_judgment: false
  - deliverable: "CZK figures of overdue and unpaid sums convert each document's remaining amount once with its frozen issue-time CNB rate and sum in CZK; CZK documents count as they are; the summary can be limited to one client"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#the CZK figures"
        status: pass
    human_judgment: false
  - deliverable: "The reader refuses everybody but the Admin and a system run"
    requirement: "IN-05"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReceivablesReaderTest.php#refuses everybody but the Admin and a system run"
        status: pass
    human_judgment: false
  - deliverable: "UpdateTransaction refuses every change of a payment income but the date (the CZK figure following the CNB list of the new day) and the income category, with payment_transaction_locked keyed by the field and naming the invoice; DeleteTransaction refuses deleting it with payment_transaction_delete before any receipt is touched; the database guard refuses the same for raw SQL"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Finance/PaymentTransactionLockTest.php#changing a payment income"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/PaymentTransactionLockTest.php#deleting a payment income"
        status: pass
      - kind: test
        ref: "tests/Feature/Finance/PaymentTransactionLockTest.php#the database guard behind the Actions"
        status: pass
    human_judgment: false
  - deliverable: "The Transactions API meets the same refusals: PATCH of a locked field answers 422 with the locked message under the API field name, PATCH of the date answers 200 with the recomputed CZK figure, DELETE answers 422 and deletes nothing, an ordinary delete still answers 204, and the destroy endpoint documents the 422"
    requirement: "FI-03"
    verification:
      - kind: test
        ref: "tests/Feature/Api/TransactionPaymentIncomeTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "A list tab is one SQL condition used twice: as a whereRaw filter and as a COUNT(*) FILTER (WHERE ...) column of one aggregate query, so a tab and its badge cannot disagree"
    - "Today is a Prague calendar day string bound from PHP (ReceivablesReader::today()), never SQL now() or CURRENT_DATE"
    - "The payment-income lock is checked on the locked row before the input is applied; the unchanged values a form resends are not a change"

key-files:
  created:
    - app/Domain/Invoicing/Enums/InvoiceListTab.php
    - app/Domain/Invoicing/Queries/ReceivablesReader.php
    - tests/Feature/Invoicing/ReceivablesReaderTest.php
    - tests/Feature/Finance/PaymentTransactionLockTest.php
    - tests/Feature/Api/TransactionPaymentIncomeTest.php
  modified:
    - app/Domain/Finance/Actions/UpdateTransaction.php
    - app/Domain/Finance/Actions/DeleteTransaction.php
    - app/Http/Controllers/Api/V1/TransactionController.php
    - lang/cs/enums.php

key-decisions:
  - "R-19-1 to R-19-4 applied as planned; the summary()['unpaid'] figure follows the Unpaid tab (not yet late), so overdue and unpaid never overlap"
  - "A foreign-currency document without its CNB trio adds nothing to a CZK sum (it cannot be priced and cannot occur after issue); a CZK document needs no trio"
  - "ReceivablesReader::today() is the one place that turns the frozen-clock aware TimerClock into the Prague day callers pass in"
  - "The payment-income check compares the type as sent and everything else after reading the input with the type, date, category and CZK keys left out, so a type or CZK refusal is never hidden behind a category validation error"
  - "Unchanged values (czk_manual false, an empty czk_amount, the same amount typed as text) are not a change, so the finance form can resend its state when only the date or category is edited"

requirements-completed: [IN-05, FI-03, IN-06]

duration: about 25 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 19: Receivables reader and payment income lock Summary

**One ReceivablesReader derives every display status, the seven list tabs with their counts and the CZK summaries of what is late and unpaid from the payments and the Prague day, while UpdateTransaction and DeleteTransaction (and so the Transactions API) refuse any change of an invoice payment's income except its date and category and never delete it.**

## Performance

- **Duration:** about 25 min (not measured exactly)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 5 created, 4 modified

## Accomplishments

- `ReceivablesReader` (Admin and system only): `statuses()` gives each document its `InvoiceStatus`, remaining `Money` (null for a draft and a credit note) and the whole days late; `constrain()` narrows an `invoices` query to a tab; `tabCounts()` returns all seven counts from one `COUNT(*) FILTER` query, optionally for one client; `summary()` gives count and CZK sum of the overdue and the unpaid (not yet late) documents plus the number of paid proformas awaiting their invoice. `today()` returns the Prague day of the clock.
- The statuses follow ruling R-19-3: a cancelled invoice reads as cancelled and owes nothing, a proforma with nothing left is converted when an issued invoice names it and otherwise is the attention item "Zaplacená záloha bez faktury", an unpaid proforma becomes overdue after its due date (R-19-2).
- CZK figures convert each document's remaining amount once with its frozen issue-time rate (`invoice_admin` trio, `Money::convert`) and sum in CZK; a proof with a different CNB rate on the payment day shows the payment-day rate is not used.
- `InvoiceListTab` with the Czech tab names of the UI-SPEC (`lang/cs/enums.php`).
- `UpdateTransaction` on a payment income refuses a change of amount, currency, client, project, type, description, counterparty, document number or invoice link and any manual or own-rate CZK figure with `payment_transaction_locked` under that key (naming the invoice number), before the row is written; the date (CZK figure recomputed from the CNB list of the new day) and an income category pass, an expense category fails. `DeleteTransaction` refuses a payment income with `payment_transaction_delete` under `transaction` before any receipt is touched.
- The Transactions API needed no code beyond documentation: its update and destroy endpoints call these Actions, so the refusals are 422. The destroy endpoint now documents the 422 and the rule, the update endpoint names the date-and-category rule.
- Raw SQL proofs of plan 10-06 repeated here: a locked column change and a delete of the income fail with KP001; a payment delete without the flag fails with KP001, with the flag while its income exists with 23503, and a flagged income delete alone cannot commit (KP001).

## Task Commits

1. **Task 1 (tracer): an overdue invoice reads as overdue, the tab, counts and summary find it** - `b825d8d` (feat)
2. **Task 2 RED: every status, tab and CZK summary of a mixed portfolio** - `0fdf773` (test)
3. **Task 2 GREEN: statuses for every document and CZK conversion at the frozen rate** - `084a9c2` (feat)
4. **Task 3 RED: payment income lock tests (Actions, database guard, API)** - `e36db72` (test)
5. **Task 3 GREEN: lock of payment income in the finance Actions and the documented API 422** - `61bdbfc` (feat)

## Verification

- Tracer gate: the tracer `<verify>` (reader test, query escape hatch test, Pint, PHPStan) passed before the expansion, logged as "Tracer verified end-to-end - expanding".
- Plan-level: `tests/Feature/Invoicing tests/Feature/Finance tests/Feature/Api tests/Arch` 1015 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set; lefthook (sensitive-content, gitleaks) passed on all five commits.
- All acceptance greps of the three tasks pass.
- Full suite `ddev exec vendor/bin/pest --compact`: 5473 passed, 3 skipped (39925 assertions).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Czech labels of the list tabs**
- **Found during:** Task 1
- **Issue:** `InvoiceListTab` implements `HasLabel`, and the app-wide enum label test (`EnumLabelsTest`) requires a real Czech label for every case; `lang/cs/enums.php` is not in the plan's file list.
- **Fix:** Added the `invoice_list_tab` block with the UI-SPEC tab names.
- **Files modified:** `lang/cs/enums.php`
- **Commit:** b825d8d

**Total deviations:** 1 auto-fixed (1 blocking). **Impact:** none on the plan contract.

## Issues Encountered

- `EditTransaction`'s delete action (plan 10-39 owns that page) catches only `RuntimeException`, so the `ValidationException` of `DeleteTransaction` for a payment income is not turned into a notification there yet. Plan 10-39 hides or replaces that action for payment income; the Action's contract (Czech message under `transaction`) is what it reads.
- Test fixtures: `FinanceApiFixtures::transaction()` drops the web session (`Auth::forgetGuards()`), so a test that reads Admin-only rows afterwards has to call `actingAs()` again.

## Known Stubs

None.

## Threat Flags

None. T-10-50 is mitigated by binding today from PHP with boundary tests, T-10-51 and T-10-95 by the Action refusals backed by the database guard (raw-SQL and API tests), T-10-52 by `authorise()` in every reader method (tested); nothing was installed (T-10-SC).

## Notes for later plans

- List and dashboard plans call `ReceivablesReader::today()` once per request and pass the string to `constrain()`, `tabCounts()` and `summary()`; the "Zálohy k vyfakturování" tab is hidden when `tabCounts()['awaiting_invoice']` is 0.
- `constrain()` expects the table `invoices` unaliased (the fragments of `InvoiceBalances` and `Invoice::CANCELLED_SQL` are correlated on it). A table summarizer for "Celkem v Kč" can use `summary()` rows for the whole tab; the per-row "v Kč" second line converts the row's remaining amount with its own trio.
- Plan 10-39 reads `Transaction::isPaymentIncome()` to lock the form fields and hide the delete action; the Action messages are `invoices.errors.payment_transaction_locked` and `payment_transaction_delete`.
- Plan 10-41 `DeletePayment` is the only deletion path: it sets `kokpit.payment_deletion` to the payment id, deletes the income first and never calls `DeleteTransaction`.

## Self-Check: PASSED

All five created files exist on disk; commits `b825d8d`, `0fdf773`, `084a9c2`, `e36db72` and `61bdbfc` are ancestors of HEAD; the commit count measured from the plan ledger is 5.
