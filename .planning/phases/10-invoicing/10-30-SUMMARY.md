---
phase: 10-invoicing
plan: 30
subsystem: invoicing-admin-ui
tags: [invoicing, filament, payments, proforma-conversion, relation-manager, destructive-confirmation, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "RecordPayment with warnings() (10-16), ConvertProforma (10-22), ViewInvoice and InvoicePaymentsRelationManager (10-29), DeletePayment (10-41), lang/cs/invoices.php payments and conversion sections (10-21)"
provides:
  - "RecordPaymentAction: the payment modal shared by the detail header (primary) and the Platby tab header (gray), with live warnings, the proforma variant, the income date and category, and the RecordPayment write"
  - "ViewInvoice header actions 'Zaznamenat platbu' (unpaid, partly paid, overdue) and 'Vystavit fakturu' (paid proforma without invoice, through ConvertProforma, success path with send=1)"
  - "InvoicePaymentsRelationManager: the gray 'Zaznamenat platbu' header action, the destructive 'Smazat platbu' row action through DeletePayment (disabled with the invoice number on a carried proforma payment), the gray helper, no edit action"
  - "The invoice-payment-saved event now has a sender: the page, the tab, the invoice list and the dashboard widget all refresh from it"
affects: [10-31, 10-32, 10-33]

actuals:
  tokens: 15700
  tasks: 3
  commits: 6
plan_head_before: 3186f2ff1f18b1130d874fe82c5c8a45b4fea24b
plan_head_after: bb70904792837617ea32162ec92127221ee4c1ba
commits: 6

tech-stack:
  added: []
  patterns:
    - "A shared Action builder takes a Closure that returns the host's invoice and re-reads it through the scoped query on every call, so the same modal works on a page and on a relation manager and a forged record id reaches nothing else"
    - "Live modal callouts and the submit label are computed from the raw action state (Action::getRawData()) and the Get utility, so a warning never depends on a roundtrip to the domain being a block"
    - "A relation manager listens to the event the page dispatches (and vice versa) with an empty #[On] method that only triggers a render from the database"
    - "A disabled table action with a tooltip is the UI half of a domain refusal; the Action's own refusal is still mapped to a danger notification"

key-files:
  created:
    - app/Filament/Support/RecordPaymentAction.php
    - tests/Feature/Invoicing/PaymentModalTest.php
    - tests/Feature/Invoicing/DeletePaymentActionTest.php
    - tests/Feature/Invoicing/ConvertProformaActionTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - tests/Concurrency/PaymentDeletionConcurrencyTest.php

key-decisions:
  - "RecordPaymentAction::make(Closure $invoice, ?Closure $afterSave, string $color) instead of the argument-less make() named in the plan, so the page and the relation manager each hand over their own invoice and refresh hook"
  - "No payment action on a converted proforma (page or tab): a payment recorded there would never book its income. The plan lists credit notes and cancelled invoices only; RecordPayment itself still allows it"
  - "The header action on the page is shown only while something is owed (unpaid, partly paid, overdue); a paid document records an extra payment from the tab (D-17)"
  - "The system income category chosen in the modal is sent to RecordPayment as 'no category', which means the same and keeps the Action's category check for real choices only"
  - "The payment date field has no maxDate rule, so the future-date refusal is the Czech message of PaymentInput under the field instead of a framework message"
  - "A payment_not_allowed refusal (document cancelled while the modal was open) is shown under the amount; an unknown error key falls back to the amount field"

patterns-established:
  - "Test helpers that live in a Pest file are global functions: give them names no other test file declares (the full suite reports a redeclare fatal that a path-limited run does not)"

requirements-completed: [IN-05, IN-06, FI-03]

coverage:
  - id: D1
    description: "The Admin records the full payment of an issued invoice from the detail: the modal opens with the remaining amount, today and the system income category, the payment and its income are stored, the badge turns Zaplaceno, the overdue banner is gone and invoice-payment-saved is dispatched; the header action is absent on a paid invoice, a credit note and a cancelled invoice"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentModalTest.php#the tracer: the full payment of an issued invoice from the detail"
        status: pass
    human_judgment: false
  - id: D2
    description: "Zero, one and several partial payments update paid, remaining and the badge (N7); a paid invoice warns 'Faktura je jiz zaplacena.' with 'Presto ulozit platbu', an amount above the remaining shows the second warning with the remaining amount live, and both still save"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentModalTest.php#partial payments (UI-SPEC N7)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The proforma modal hides the income date and category and explains why, stores the payment without an income and toasts 'Platba u zalohy byla ulozena'; a future date and a zero amount are refused under their fields; the income date and another category reach the Action; no action on a cancelled or converted document; Partner gets 403"
    requirement: "IN-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/PaymentModalTest.php#the fields of the modal"
        status: pass
    human_judgment: false
  - id: D4
    description: "The destructive 'Smazat platbu' row action: the confirmation names the amount and date and says that the income is deleted with the payment (or that no income exists on a proforma), confirming deletes both, the badge, remaining amount and banners follow, two payments give Castecne zaplaceno after one is deleted, a cancelled invoice's payment stays deletable, and there is no edit action"
    requirement: "FI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DeletePaymentActionTest.php#an invoice payment"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DeletePaymentActionTest.php#a proforma payment"
        status: pass
    human_judgment: false
  - id: D5
    description: "A proforma payment carried to an invoice has the deletion disabled with the tooltip naming the invoice, a forged call changes nothing, the invoice-side copy deletes and then the proforma payment does; a refusal of DeletePayment is a danger notification; a payment of another invoice is not reached through the host invoice; a Partner gets 403 on the page and on the tab"
    requirement: "FI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DeletePaymentActionTest.php#a proforma payment"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DeletePaymentActionTest.php#a refusal and a forged call"
        status: pass
    human_judgment: false
  - id: D6
    description: "A paid proforma without an invoice shows the primary 'Vystavit fakturu' and the danger banner; one confirmation converts it, shows the invoice toast and redirects to the new invoice with send=1; the proforma then reads Prevedeno na fakturu with the related invoice and no action; an unpaid or partly paid proforma shows no conversion; a failed render leaves the proforma unconverted with the fixed copy only"
    requirement: "IN-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaActionTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "The modal layout, the live feel of the warning callout and the placement of the 'cannot be edited, only deleted' note next to the submit read well in light and dark mode"
    verification: []
    human_judgment: true
    rationale: "Layout and perceived liveness are judged by eye; the tests assert the texts and the state, not the rendering"

duration: 47min
completed: 2026-10-17
---

# Phase 10 Plan 30: Payments and proforma conversion in the panel Summary

**The Admin records a payment from the detail or the Platby tab through one shared modal (remaining amount default, live warnings, income date and category, proforma variant), deletes a mistaken payment with its income in one destructive confirmation, and issues the invoice from a paid proforma in one confirmation, with the badge, banners, list and dashboard following through invoice-payment-saved.**

## Performance

- **Duration:** 47 min (most of it three full-suite runs of about 10 min each)
- **Started:** 2026-10-17T19:52:31Z
- **Completed:** 2026-10-17T20:39:29Z
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files modified:** 7 (4 created, 3 modified)

## Accomplishments

- `RecordPaymentAction` builds the "Zaznamenat platbu" modal for any host that can hand over its invoice. The form defaults Castka to the remaining amount with the currency suffix, Datum platby to today, Zpusob to Prevodem, Datum prijmu to the payment date (it follows the payment date until the Admin picks another day) and Kategorie prijmu to the system income category; both income fields are hidden on a proforma, which shows the gray info callout instead. The warning callout and the submit label ("Ulozit platbu" or "Presto ulozit platbu") come live from `RecordPayment::warnings()`; saving always works (D-17). The write is `RecordPayment::handle()`, its field errors are mapped back under the modal fields, and success dispatches `invoice-payment-saved` and toasts "Platba byla ulozena" or "Platba u zalohy byla ulozena".
- `ViewInvoice` gets the primary "Zaznamenat platbu" while something is owed, the primary "Vystavit fakturu" on a paid proforma without an invoice (confirmation "Vystavit fakturu ze zalohy :number?" through `ConvertProforma`, the invoice toast and a redirect to the new invoice with `send=1`), and a `#[On('invoice-payment-saved')]` listener so a change made in the tab re-renders the badge and banners.
- `InvoicePaymentsRelationManager` gets the gray header action, the gray helper under the table title and the one destructive row action "Smazat platbu" (OutlinedTrash, danger colour). Its confirmation names the amount and the date and, on an invoice, says the income is deleted with the payment and the state recomputed (on a proforma: no income exists). The row action re-reads the payment through the host invoice's `payments()`, calls `DeletePayment`, maps a refusal to a danger notification with the Czech message and dispatches `invoice-payment-saved`. A proforma payment carried to an invoice is disabled with "Platba je prenesena na fakturu :number. Nejdriv smazte platbu na fakture."; there is no edit action.
- 33 new tests across the three files (15 + 10 + 8) cover the tracer, N7, both warnings, the proforma variant, field errors, the income date and category, the deletion flows, the carried payment, a refusal, a forged foreign payment and the Partner refusals.

## Task Commits

1. **Task 1 (tracer): the Admin records the full payment of an issued invoice from the detail** - `dd412c6` (feat). This commit also carried the `convertProformaAction()` code of Task 3 in `ViewInvoice.php`; its tests landed in Task 3.
2. **Task 2: partial payments, warnings, proforma payments and the confirmed deletion in the Platby tab** - `2a91dfc` (feat)
3. **Task 3: the paid proforma becomes an invoice from its own detail** - `871b869` (test)
4. Fixes found by the full suite: `ca302e1` (test helper names), `b990879` (raw table query), `bb70904` (concurrency test row order)

**Plan metadata:** the docs commit that carries this file.

## Tracer gate

After Task 1 the tracer `<verify>` (PaymentModalTest, Pint, PHPStan) passed, plus `tests/Feature/Invoicing` and `tests/Arch` (510 tests), before the expansion tasks started.

## Decisions Made

See `key-decisions` above. The two that change visible behaviour beyond the plan text: no payment action on a converted proforma, and the header action only while something is owed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test helper names redeclared across the suite**
- **Found during:** final full-suite run
- **Issue:** `deletionTab()` was already declared in `tests/Feature/Documents/DeleteDocumentTest.php`, a fatal error that a path-limited run never loads; `paymentDeletionPay()` collided with the concurrency test.
- **Fix:** renamed to `paymentDeletionTab`, `paymentDeletionPage`, `rowDeletionPay`; removed an unused helper.
- **Files modified:** tests/Feature/Invoicing/DeletePaymentActionTest.php, tests/Feature/Invoicing/PaymentModalTest.php
- **Commit:** ca302e1

**2. [Rule 1 - Bug] Raw table query in the Platby tab**
- **Found during:** final full-suite run (`tests/Arch/QueryEscapeHatchTest`)
- **Issue:** the lookup of carried copies used `DB::table(...)`, which the project forbids outside an allowlist.
- **Fix:** the same lookup through `InvoicePayment::query()->whereIn('carried_from_payment_id', ...)->with('invoice')`.
- **Files modified:** app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
- **Commit:** b990879

**3. [Rule 1 - Bug] Order-dependent assertion in the plan 10-41 concurrency test**
- **Found during:** final full-suite run (`PaymentDeletionConcurrencyTest`, "a payment is recorded while an older one is deleted")
- **Issue:** `toBe()` on two unordered `pluck()` maps compared key order, which follows the physical row order of two different tables. It failed in the second full run and twice in isolation, though no code of this plan touches the domain.
- **Fix:** `ksort()` both maps before comparing; the amounts per payment are still compared exactly. Passed twice in a row afterwards.
- **Files modified:** tests/Concurrency/PaymentDeletionConcurrencyTest.php
- **Commit:** bb70904

**4. [Plan scope] `RecordPaymentAction::make()` takes arguments**
- The plan names `make(): Action`; the shared builder needs the host's invoice and refresh hook (`make(Closure $invoice, ?Closure $afterSave = null, string $color = 'gray')`). No behaviour differs from the contract.

---

**Total deviations:** 3 auto-fixed (3 bugs), 1 scope note. **Impact:** none on behaviour; the third touches a test of another plan and only removes an ordering assumption.

## Issues Encountered

- A stale `callMountedAction` on a proforma that was converted in another tab does nothing and shows no message: the action is hidden on the fresh state, so Filament never calls it, and the page then shows the converted state. The `ValidationException` branch of the action stays as a backstop for a race at the row lock and is tested through a bound fake.
- A disabled table action is blocked by Filament at mount and at call, so a forged call of "Smazat platbu" on a carried proforma payment never reaches `DeletePayment`; the danger notification for `payment_delete_carried` / `payment_missing` is proven through a bound fake whose `handle()` throws the typed refusal.
- The full-suite runs took about 10 minutes each. The final run: 5837 passed, 3 skipped (pre-existing), 0 failed. The known flaky `PartnerDashboardTest.php:403` did not fail in any of the three runs.

## Known Stubs

None.

## Threat Flags

None. T-10-76 and T-10-100 are mitigated and tested: the host invoice is re-read through the scoped query on every call, the payment is re-read through the host invoice's payments, both closures call `abort_unless(InvoiceResource::canAccess(), 403)` before the domain Action, and a forged foreign payment and a Partner are covered by tests. Nothing was installed (T-10-SC).

## User Setup Required

None - no external service configuration required.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite) after the last commit: 5837 passed, 3 skipped, 0 failed.
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Finance tests/Isolation`: 1034 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) passed on every commit.
- Acceptance greps pass: `RecordPayment` and `invoice-payment-saved` in `RecordPaymentAction.php`, `RecordPaymentAction` in the relation manager, `warnings(` in `RecordPaymentAction.php`, `DeletePayment` and `invoice-payment-saved` in the relation manager, `Smazat platbu` and `assertForbidden` in `DeletePaymentActionTest.php`, `ConvertProforma` in `ViewInvoice.php`.
- The visual and liveness check (D7) was not run by the executor.

## Next Phase Readiness

Ready for 10-31 (e-mail dialog opened from the `send=1` flag, which both the issue and the conversion now set). The "Zaplacena zaloha bez faktury" banner still has no button of its own; the header action carries the conversion.

## Self-Check: PASSED

- FOUND: app/Filament/Support/RecordPaymentAction.php
- FOUND: tests/Feature/Invoicing/PaymentModalTest.php
- FOUND: tests/Feature/Invoicing/DeletePaymentActionTest.php
- FOUND: tests/Feature/Invoicing/ConvertProformaActionTest.php
- FOUND: dd412c6, 2a91dfc, 871b869, ca302e1, b990879, bb70904 (ancestors of HEAD)
