---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 07
subsystem: invoicing
tags: [cash, payment-method, stripe-link, filament-editor, proforma-conversion]
requires:
  - phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
    provides: DocumentPaymentMethod::Cash and its predicates, cash draft rule, cash issue branch (15-01); cash PDF box (15-04)
provides:
  - DocumentPayment::forIssued() / issuedSwitch() cash branches (snapshot dropped for any method without bank details, retaken when leaving cash, switch off, explicit switch refused)
  - ConvertProforma on the method predicates (no snapshot, switch from forcesCardPayment())
  - EditInvoice::methodOf() replacing isOnline(); cash offered in the draft and issued editors with the account hidden and the switch off and disabled
affects: [15-08, 15-09]
tech-stack:
  added: []
  patterns: [editor and domain rules read the enum predicates, never method comparisons]
key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Support/DocumentPayment.php
    - app/Domain/Invoicing/Actions/ConvertProforma.php
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - lang/cs/invoices.php
    - tests/Feature/Payments/LinkLifecycleTest.php
    - tests/Feature/Payments/CardSwitchTest.php
    - tests/Feature/Invoicing/UpdateIssuedDocumentTest.php
    - tests/Feature/Invoicing/ConvertProformaTest.php
    - tests/Feature/Invoicing/DraftEditorTest.php
    - tests/Feature/Invoicing/IssuedEditorTest.php
    - tests/Feature/Invoicing/DocumentPaymentTest.php
key-decisions:
  - "No change to UpdateIssuedDocument, IssuedEditFinisher or PayPageState: method_changed already deactivates the live link, the finisher opens a link only while the switch is on, and the pay URL of a cash document keeps showing the existing 'preparing' information page (R-15-07-3)"
  - "The invalid-method message now names cash ('Zvolte úhradu převodem, online nebo hotově.'), as 15-01 deferred to the editor plan"
patterns-established:
  - "Form state to method goes through EditInvoice::methodOf(); visibility and locking come from printsBankDetails(), allowsCardChoice(), forcesCardPayment()"
requirements-completed: [P15-03, P15-04]
status: complete
actuals:
  tokens: 26000
  tasks: 2
  commits: 4
plan_head_before: 51f9f4a3b90a55ac90bde3435e507e85188ab566
plan_head_after: 18a6aab84cf30956c3278eff2207b06666a192a9
completed: 2026-10-27
---

# Phase 15 Plan 07: Cash on open issued documents, conversion and editors Summary

**An open issued transfer or online invoice or proforma can be changed to cash (bank snapshot dropped, card switch off, live Stripe link replaced, no new link, PDF regenerated) and back to transfer (snapshot retaken, switch from the client flag); a cash proforma converts into a cash invoice; both editors offer cash with the account hidden and the card switch off and disabled.**

## Accomplishments

- `DocumentPayment::forIssued()` (R-15-07-1): `$noBank = ! $method->printsBankDetails()` sets `bank_snapshot` to null for cash and online alike; the snapshot is retaken when the stored method printed no bank details, so cash -> transfer refills like online -> transfer. The online availability refusal stays for online only. Class docblock extended with the cash sentence.
- `DocumentPayment::issuedSwitch()` (R-15-07-2): first branch for a method that neither allows a card choice nor forces card payment (cash): explicit `true` is refused under `card_payment_enabled` with `invoices.errors.card_payment_cash`, otherwise off. Online branch uses `forcesCardPayment()`. A stored cash method derives the transfer switch from the client flag like a stored online one, with the WR-01 availability rule unchanged.
- `UpdateIssuedDocument`, `IssuedEditFinisher`, `PayPageState` untouched (R-15-07-3); the tests prove the link is replaced with reason `replaced`, deactivated at Stripe too, no `CreatePaymentLink` job is queued, the pay token is kept and `GET /pay/<token>` answers 200 with the `Preparing` outcome and no URL.
- `ConvertProforma` (R-15-07-4): snapshot passed only when the method prints bank details; the Admin-only row's switch is `forcesCardPayment()`.
- `EditInvoice` (R-15-07-5): `isOnline()` replaced by `methodOf()`; bank account field and refill action visible only for `printsBankDetails()`; `issuedBankState()` shows `bank_cash` for cash and counts a non-bank stored method as a change; the switch is disabled unless `allowsCardChoice()` with the cash helper; `methodChosen()` sets the switch true for online, false for cash, and the client flag plus preselected account when leaving online or cash for transfer; `cardPaymentAtIssue()` and the form state use `forcesCardPayment()`.
- `lang/cs/invoices.php`: `edit_issued.bank_cash`, `draft.helpers.card_payment_cash`, and the invalid-method text now names cash.

## Task Commits

1. Task 1 RED: `26d30b2` test(15-07): add failing tests for changing an open issued document to and from cash
2. Task 1 GREEN (tracer): `f5e435a` feat(15-07): change an open issued document to and from cash
3. Task 2 RED: `77fff5b` test(15-07): add failing tests for cash in the editors and the proforma conversion
4. Task 2 GREEN: `18a6aab` feat(15-07): offer cash in both editors and carry it through a proforma conversion

## TDD Gate Compliance

RED commits precede GREEN commits for both tasks. RED evidence is by inspection, NOT a recorded run: the worktree has no `vendor/` and no database (project rule), so no failing report exists and `gsd_run check tdd-red-evidence` could not be fed. Semantic assessment:

- Task 1: before the change `forIssued()` treated cash as transfer (retook a snapshot, kept the switch), so the cash cases in LinkLifecycleTest, UpdateIssuedDocumentTest and CardSwitchTest fail on the null snapshot, the switch value and the missing refusal; the new CHECK constraint from 15-01 would also reject the inconsistent row.
- Task 2: the editor cases fail before the change because cash kept the account field visible and the switch enabled. The two ConvertProformaTest cases are regression pins: a cash proforma already converted correctly after 15-01 (null snapshot, issuer switch off), so they are expected to pass before and after; the code change there moves the conversion onto the predicates.
- In Task 2 the implementation had been written in the working tree before the RED commit was made; the RED commit contains tests only, but the test files were not run against a tree without the implementation.

No refactor commit.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical correctness] Invalid-method message names cash**
- **Found during:** Task 2
- **Issue:** `invoices.errors.payment_method_invalid` still said "převodem nebo online" although cash is now a valid choice (15-01 deferred the wording to this plan).
- **Fix:** text changed to "Zvolte úhradu převodem, online nebo hotově."; the one test that pins it (`DocumentPaymentTest.php`, not in the plan's file list) updated in the same Task 2 RED commit.
- **Files modified:** lang/cs/invoices.php, tests/Feature/Invoicing/DocumentPaymentTest.php
- **Commits:** 77fff5b, 18a6aab

### Verification not run in the worktree

Per the project rules, no Pest suite, PHPStan or migration was run here (no shared DB, no `vendor/` in the worktree). Cheap checks done: `php -l` on every touched PHP file, Pint `--test` (main checkout binary) on all touched files (passed), `scripts/check-sensitive.sh` through the hook on every commit. After the merge, in DDEV:

- `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php tests/Feature/Payments/CardSwitchTest.php tests/Feature/Invoicing/DocumentPaymentTest.php`
- `ddev exec vendor/bin/pest tests/Feature/Invoicing/ConvertProformaTest.php tests/Feature/Invoicing/DraftEditorTest.php tests/Feature/Invoicing/IssuedEditorTest.php`
- the plan gate: `ddev exec vendor/bin/pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Risks to watch at that run: the Czech cash-sentence fragment `v hotovosti` in the PDF assertions (text read through Ghostscript, skipped when `gs` is missing); `$field->getOptions()['cash'] === 'Hotově'` in the two editor tests assumes Filament returns the enum labels keyed by value; the new Livewire cases rely on the existing `editor()` / `iedEditor()` helpers.

## Known Stubs

None.

## Threat Flags

None. T-15-04 (explicit switch with cash refused, CardSwitchTest), T-15-05 (live link replaced in the edit transaction, no new link job, LinkLifecycleTest) and T-15-06 (locked document refuses change to and from cash, UpdateIssuedDocumentTest) are covered as planned; T-15-SC: composer.json and composer.lock untouched.

## Self-Check: PASSED

- Commits `26d30b2`, `f5e435a`, `77fff5b`, `18a6aab` are ancestors of HEAD.
- Acceptance greps: `allowsCardChoice` and `forcesCardPayment` in DocumentPayment; `printsBankDetails` and `forcesCardPayment` in ConvertProforma; `printsBankDetails` and `allowsCardChoice` in EditInvoice; `function isOnline` gone; `bank_cash` and `card_payment_cash` in lang/cs/invoices.php; "changed to cash with the information page" in LinkLifecycleTest.
