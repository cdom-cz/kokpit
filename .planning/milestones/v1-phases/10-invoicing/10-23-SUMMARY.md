---
phase: 10-invoicing
plan: 23
subsystem: invoicing-credit-notes
tags: [invoicing, credit-note, storno, time-billing, cnb-snapshot, immutability]
status: complete

requires:
  - phase: 10-invoicing
    provides: "DocumentIssuer with IssueContext::exchangeSnapshot and the PDF step (plans 10-12, 10-20), EntryBilling::release() and the storno link guard (plans 10-06, 10-09)"
  - phase: 10-invoicing
    provides: "ReceivablesReader statuses and InvoiceBalances credited sums (plan 10-19), the converted-line conversion snapshot (plan 10-17)"
provides:
  - "IssueCreditNote::storno(User, Invoice): a full credit note (cancels_invoice true) of the remaining creditable quantity of every line, issued through the shared pipeline, then the invoice's entries are released"
  - "IssueCreditNote::partial(User, Invoice, array $lines): a credit note of chosen quantities (item id to decimal quantity) that keeps the time billed"
  - "CreditableQuantities::for(Invoice $lockedInvoice): item id to remaining creditable InvoiceQuantity, from issued credit notes only"
affects: [10-24, 10-30, 10-36, 10-39, 10-41]

actuals:
  tokens: 11900
  tasks: 2
  commits: 2
plan_head_before: 3af7d5526469486479dac652dd4a4cff7bb47e8c
plan_head_after: 5c727107648908f23bbdd7b0721217e92e9edcd5
commits: 2

coverage:
  - deliverable: "The Admin stornoes an issued invoice built from time: a numbered credit note of the credit-note series (negated quantities, original unit prices, credited_item_id, negative total, no due date, no variable symbol, a PDF) is issued, the invoice reads as cancelled with remaining 0 while its own row is byte-identical, and its four entries are unbilled with no link and no snapshot and editable again"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#the tracer: the storno of an invoice built from time"
        status: pass
    human_judgment: false
  - deliverable: "A payment recorded on the invoice and its income transaction are untouched by the storno"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#the tracer: the storno of an invoice built from time"
        status: pass
    human_judgment: false
  - deliverable: "A partial credit note credits a quantity of one line, leaves the invoice issued and not cancelled, lowers the remaining amount and never releases time, even when every quantity is credited in pieces"
    requirement: "IN-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#a partial credit note"
        status: pass
    human_judgment: false
  - deliverable: "Creditable quantities respect earlier credits: more than is left is refused with credit_quantity under lines.<item id>, exactly what is left passes, and the storno afterwards credits only the remaining quantities so the credit notes together equal the invoice"
    requirement: "IN-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#a partial credit note"
        status: pass
    human_judgment: false
  - deliverable: "Zero, negative and malformed quantities, an item of another invoice, an empty selection and a partial credit that would exceed the invoice total are refused with nothing consumed; a proforma, a credit note, a draft, an already cancelled invoice (second storno, also by the unique index) and a Partner are refused"
    requirement: "IN-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#what can be credited"
        status: pass
    human_judgment: false
  - deliverable: "A credit note of a EUR invoice copies the original CNB rate, unit and list date and negates the CZK total without any new CNB lookup; a converted time line is credited from its frozen conversion with the amount rounded once (59701 EUR minor storno, 7.3333 h partial = 43781), price_converted true"
    requirement: "IN-01"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#a foreign currency invoice"
        status: pass
    human_judgment: false
  - deliverable: "A PDF render failure during the storno leaves no credit note, no number, no stored object, the invoice not cancelled and the time still billed"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreditNoteTest.php#the storno is atomic"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "A credit note is the only record of a correction: nothing is written on the issued invoice, and the credit note's cancels_invoice flag is the one cancelled marker"
    - "The original is locked first, then creditable quantities, the cancelled state and the credited total are judged on the locked row"
    - "Credit lines are manual-source rows with price_manual true; a converted line's frozen conversion snapshot and source rate are copied so its amount is rounded once"

key-files:
  created:
    - app/Domain/Invoicing/Actions/IssueCreditNote.php
    - app/Domain/Invoicing/Support/CreditableQuantities.php
    - tests/Feature/Invoicing/CreditNoteTest.php
  modified: []

key-decisions:
  - "R-23-1 to R-23-4 applied as planned: the storno credits the remaining quantity of every line, the credit note copies the invoice's CNB trio, credit lines have manual sources with price_manual true, a converted line is credited with Money::timesThroughCzk from its frozen snapshot"
  - "A partial credit note is refused with credit_total when the earlier credits plus the new one would exceed the invoice total; the storno is not checked on its total, because it takes back only the remaining quantities and line rounding can move the sum by a minor unit at most, so it can never be blocked from cancelling"
  - "Error keys: a document that cannot be credited is keyed invoice (credit_not_allowed), an empty selection lines (credit_nothing), a bad quantity lines.<item id> with item_quantity, an over-quantity or foreign item lines.<item id> with credit_quantity, an over-total partial lines (credit_total)"
  - "An item that is not a line of the invoice is treated as having no creditable quantity and answers credit_quantity, so no extra message was needed"

requirements-completed: [IN-10, IN-01, IN-03]

duration: about 45 min (not measured exactly)
completed: 2026-10-17
---

# Phase 10 Plan 23: Credit notes Summary

**An issued invoice is corrected the legal way: IssueCreditNote issues a full storno (cancels_invoice credit note of the remaining creditable quantities, same CNB rate, converted lines rounded once) that cancels the invoice and returns its time to unbilled, or a partial credit note of chosen quantities that keeps the time billed, with nothing ever written on the invoice row.**

## Performance

- **Duration:** about 45 min (not measured exactly)
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 3 created, 0 modified

## Accomplishments

- `IssueCreditNote::storno()` locks the invoice, refuses anything but an issued, uncancelled invoice, credits the remaining quantity of every line through `CreditableQuantities`, builds the credit-note draft with `cancels_invoice` true, issues it through the shared `DocumentIssuer` with the invoice's CNB snapshot (`IssueContext::exchangeSnapshot`) and only then releases the entries through `EntryBilling::release()`, which the link guard allows because the storno credit note is issued by then.
- `IssueCreditNote::partial()` takes `item id => decimal quantity`, checks every quantity against the remaining creditable quantity and the credited total against the invoice total, issues a credit note with `cancels_invoice` false and never touches time.
- `CreditableQuantities::for()` computes the remaining quantity per original line from issued credit notes only, inside a transaction (it refuses to run outside one), and returns a zero quantity for a line with nothing left.
- Converted lines (`price_converted`) are credited from the conversion snapshot and source rate frozen on the original's source row, with `Money::timesThroughCzk()` of the negative quantity rounded once, so a full storno gives exactly minus the original amount (59701 in the plan example) and a partial of 7.3333 h gives 43781, not the 43780 that the printed unit price would give.
- The tracer gate passed (end-to-end test plus Pint/PHPStan) before the expansion task started.

## Task Commits

1. **Task 1 (tracer): storno an issued invoice with a full credit note that cancels it and returns its time to unbilled** - `7551ef4` (feat)
2. **Task 2: prove partial credit notes respect the original quantities, the invoice value and the frozen conversion** - `5c72710` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/CreditNoteTest.php`: 13 passed (189 assertions).
- `tests/Feature/Invoicing tests/Feature/TimeTracking`: 700 passed (3889 assertions); full suite `ddev exec vendor/bin/pest --compact`: 5543 passed, 3 skipped (40577 assertions).
- Pint (`--test`, 1001 files) and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.
- All acceptance greps of both tasks pass.
- A mutation check (disabling the converted-line amount and the total check) made exactly the three expected tests fail, so those tests discriminate.

## Deviations from Plan

**1. [Process] Partial credit and its checks landed in the Task 1 commit**
- `IssueCreditNote` was written as one class, so `partial()`, the quantity and total checks and the converted-line rule are in the Task 1 commit `7551ef4`. Task 2 therefore added tests only (`5c72710`), and its tests passed on first run instead of running RED. The mutation check above was run to show the Task 2 tests do fail against a broken implementation.

**Total deviations:** 0 auto-fixed, 1 process note. **Impact:** none on the plan contract.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. T-10-61 is mitigated by locking the original invoice first and reading the creditable quantities, the cancelled state and the credited total on the locked row, with item ids resolved only against the invoice's own lines; T-10-62 by releasing entries only in `storno()` after the storno credit note is issued (a partial credit note never releases, tested with every quantity credited in pieces). Nothing was installed (T-10-SC).

## Notes for later plans

- The UI (credit-note modal, storno confirmation) calls `IssueCreditNote::partial($actor, $invoice, [$itemId => '1,5'])` and `storno($actor, $invoice)` and shows the `ValidationException` keys `invoice`, `lines` and `lines.<item id>`; `invoices.credit.*` already holds the modal copy. `CreditableQuantities::for()` needs a transaction, so a modal that wants the remaining quantities outside one should read them inside `DB::transaction` or a later plan adds a read-only variant.
- After partial credit notes whose rounded line amounts add up to a minor unit more than the invoice, the storno still goes through and the invoice reads as cancelled with remaining 0; the credited sum can then exceed the invoice total by that rounding unit.
- A credit-note line keeps no project or task on its source row (manual source), so reports that group by project read credit notes through the credited line's own source.

## Self-Check: PASSED

`app/Domain/Invoicing/Actions/IssueCreditNote.php`, `app/Domain/Invoicing/Support/CreditableQuantities.php` and `tests/Feature/Invoicing/CreditNoteTest.php` exist on disk; commits `7551ef4` and `5c72710` are ancestors of HEAD; the commit count measured from the plan ledger is 2.
