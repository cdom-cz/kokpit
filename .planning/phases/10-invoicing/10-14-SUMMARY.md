---
phase: 10-invoicing
plan: 14
subsystem: invoicing-time-billing
tags: [invoicing, issue, billing-lock, reservation, unlink, fixed-amount, draft]

requires:
  - phase: 10-invoicing
    provides: "EntryBilling::bill()/reserve()/unreserve() and the link guard of time_entries (plans 10-06, 10-09)"
  - phase: 10-invoicing
    provides: "DocumentIssuer, IssueDocument and the issue transaction (plan 10-12)"
  - phase: 10-invoicing
    provides: "InvoiceableEntries, EntryReservation::claim(), TimeItemGrouping, CreateDraft time source (plan 10-13)"
provides:
  - "DocumentIssuer billing step: after the state flip the linked entries are locked in id order, judged on the locked rows and billed with the rate snapshot; entries_changed fails the issue and rolls everything back"
  - "ReleaseEntries: unlinks selected entries from a draft (they return to unbilled and unreserved), lines untouched"
  - "AddEntriesToDraft: claims the free eligible entries of the draft's client on locked rows and appends lines only"
  - "AddFixedAmount: one paušál line with the typed amount (manual price), optional link of the project's unbilled fixed-price time, context() for the modal"
  - "DraftLines: the one writer that appends generated lines with their sources and refreshes totals (shared with CreateDraft)"
  - "InvoiceableEntries::eligibleAmong(), fixedPriceProjectEntries(), fixedAmountBilled()"
affects: [10-15, 10-16, 10-17, 10-20, 10-21, 10-23, 10-35, 10-40]

actuals:
  tokens: 16300
  tasks: 3
  commits: 3
plan_head_before: 5592f543f9beb716831da7e7d36131fe0bb28411
plan_head_after: 3b0a5c9fab4483a7fddd4d0178dae89da356f05c
commits: 3

coverage:
  - deliverable: "Issuing an invoice or proforma bills every linked entry in the same transaction after the document is issued: locked in id order, each still finished, billable, unbilled and linked to this document, billed with the rate snapshot; a changed entry fails the issue with entries_changed and nothing is consumed (number counter, state and entries unchanged)"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueBillingTest.php#the tracer: issuing a draft created from time"
        status: pass
    human_judgment: false
  - deliverable: "The Admin unlinks selected entries from a draft; they return to unbilled and unreserved immediately, the items stay, another draft can take them, an issued document refuses with not_draft, entries of another draft or no draft are ignored"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReleaseEntriesTest.php"
        status: pass
    human_judgment: false
  - deliverable: "The Admin adds newly tracked time to a draft: only the free eligible entries of the draft's client are claimed, new lines are appended, lines edited by hand keep their text and price, grouping follows the draft, a foreign client's entry is refused with entries_foreign under entry_ids"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftTimeAdditionsTest.php#new time on a draft"
        status: pass
    human_judgment: false
  - deliverable: "The Admin adds a fixed amount for a fixed-price project of the client (quantity 1, unit paušál, typed amount as a manual price), optionally linking the project's unbilled fixed-price time which is billed without a rate snapshot at issue; the context shows the fixed price, the amounts billed by issued fixed-amount lines (credit notes netted) and the unbilled count and seconds"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftTimeAdditionsTest.php#a fixed amount on a draft"
        status: pass
    human_judgment: false
  - deliverable: "No action of this plan touches an issued document's entries"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ReleaseEntriesTest.php#refuses to unlink from an issued document and leaves its time billed"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftTimeAdditionsTest.php#refuses an issued document with not_draft and a Partner outright"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Generated lines of a draft are appended by one writer (DraftLines) that only ever adds after the last position and recomputes totals from the stored lines, so no later action can rewrite an edited line"
    - "The billing of time is a step of the issue pipeline after the state flip: lock the document's entries in id order, decide on the locked rows, bill through the single EntryBilling writer, and throw to roll the whole issue back"

key-files:
  created:
    - app/Domain/Invoicing/Actions/ReleaseEntries.php
    - app/Domain/Invoicing/Actions/AddEntriesToDraft.php
    - app/Domain/Invoicing/Actions/AddFixedAmount.php
    - app/Domain/Invoicing/Support/DraftLines.php
    - tests/Feature/Invoicing/IssueBillingTest.php
    - tests/Feature/Invoicing/ReleaseEntriesTest.php
    - tests/Feature/Invoicing/DraftTimeAdditionsTest.php
  modified:
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Invoicing/Queries/InvoiceableEntries.php
    - app/Domain/Invoicing/Actions/CreateDraft.php
    - lang/cs/invoices.php

key-decisions:
  - "R-14-1 applied with one tightening: 'the time of the project' linked with a fixed amount is every finished, billable, unbilled, unreserved entry of the project whose EFFECTIVE billing type is fixed price, so an entry of an hourly task inside a fixed-price project is not swallowed by the fixed amount"
  - "R-14-2 applied to issued invoices only (kind invoice): a proforma is excluded because the invoice created from it carries the same lines, and credit notes are netted through credited_item_id of issued credit notes; amounts are listed per currency, never summed"
  - "R-14-3 applied: fixed-amount line is quantity 1, unit paušál, origin fixed_amount with the project in its source, price_manual true"
  - "Field-error keys: entries of another client or unknown ids are refused under entry_ids (entries_foreign copy); project problems under project_id (fixed_project, projects_unknown); a missing or non-positive amount under amount (new fixed_amount copy)"
  - "Releasing and adding time ignore ids that do not apply (another draft's entry, a free entry) instead of failing, so the returned counts tell the caller what happened; only an entry of another client or an unknown id is an error when adding"

patterns-established:
  - "Pest helpers of the new tests are prefixed issueBilling, release and addition to stay unique in the global function namespace"

requirements-completed: [IN-02, IN-10]

duration: about 30 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 14: Billing lock, unlink, new time and fixed amount Summary

**Issuing now bills the time a draft holds in the same transaction (locked in id order, judged on the locked rows, rolled back as `entries_changed` if anything moved), and the Admin can unlink time from a draft, add newly tracked time without rewriting any line, and bill a fixed-price project with a fixed amount that can take its time along.**

## Performance

- **Duration:** about 30 min (start time was not recorded, approximate)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 7 created, 4 modified

## Accomplishments

- `DocumentIssuer` gained the billing step after the state flip (the link guard bills an entry only when its document is issued): `TimeEntry` rows with the document's link are locked FOR UPDATE in id order, each must be finished, billable, unbilled and linked to this document, then `EntryBilling::bill()` marks them billed with the rate snapshot. Any deviation throws `entries_changed`; the rollback takes the number, the state flip and every billed entry back (proved by comparing `number_sequences` before and after).
- `ReleaseEntries` locks the draft, locks only the requested entries that are linked to that very draft and frees them through `EntryBilling::unreserve()`; it returns the count, never touches an item, refuses an issued document with `not_draft`.
- `AddEntriesToDraft` refuses entries of another client (`entry_ids`), takes the eligible ones through `InvoiceableEntries::eligibleAmong()` and the same locked `EntryReservation::claim()` as `CreateDraft`, builds lines with `TimeItemGrouping` (per task when the draft's time lines already name a task, otherwise per project) and appends them; an edited line keeps its text, quantity and price.
- `AddFixedAmount` appends the paušál line (manual price, project in the source) and, with `link_time`, claims the project's unbilled fixed-price time; `context()` returns the agreed price, the billed amounts per currency (issued invoices, credit notes netted) and the count and exact seconds of the time that would be taken along.
- `DraftLines` is the shared writer for appended lines and totals; `CreateDraft` now uses it too (its behaviour is unchanged and its 19 tests still pass).

## Task Commits

1. **Task 1 (tracer): the billing lock at issue and the rollback on a changed entry** - `7b03f5c` (feat)
2. **Task 2: unlink time and add newly tracked time** - `1323c9b` (feat)
3. **Task 3: the fixed amount with optional time link** - `3b0a5c9` (feat)

## Verification

- Tracer feedback gate: `IssueBillingTest`, `IssueDocumentTest` and `tests/Feature/TimeTracking` (456 tests), Pint and PHPStan passed before expansion.
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/TimeTracking tests/Feature/Reports`: 829 passed, 2 skipped after Task 2; `tests/Feature/Invoicing` 84 passed after Task 3.
- Full suite once at the end (`ddev exec vendor/bin/pest --compact`): 5334 passed, 3 skipped, 0 failed (includes the architecture tests).
- Pint `--test` and PHPStan clean after every task; `scripts/check-sensitive.sh` clean on every staged set; lefthook (sensitive-content, gitleaks) passed on all three commits.
- All acceptance greps of the three tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Files outside the plan's list**
- **Found during:** Task 2 and Task 3
- **Issue:** Three actions that append generated lines and refresh totals would have copied the item/source/totals code of `CreateDraft`; `AddEntriesToDraft` needs the rows of chosen ids (not a period) in the shape the grouping reads; the fixed amount needs a message for a bad amount.
- **Fix:** Added `app/Domain/Invoicing/Support/DraftLines.php` (and made `CreateDraft` use it, behaviour unchanged), added `InvoiceableEntries::eligibleAmong()`, added the `fixed_amount` key to the "Time added to a draft" section of `lang/cs/invoices.php` (no existing key changed).
- **Files modified:** `app/Domain/Invoicing/Support/DraftLines.php`, `app/Domain/Invoicing/Actions/CreateDraft.php`, `app/Domain/Invoicing/Queries/InvoiceableEntries.php`, `lang/cs/invoices.php`
- **Commit:** 1323c9b, 3b0a5c9

**2. [Rule 1 - Correctness] R-14-1 tightened to the effective fixed-price type**
- **Found during:** Task 3
- **Issue:** "Every entry of the project" would also link an entry of an hourly task inside a fixed-price project; billing it at issue would mark hours billed that no line ever priced.
- **Fix:** `fixedPriceProjectEntries()` filters on the effective billing type `fixed_price` (same SQL as the picker), test with an hourly task inside a fixed-price project.
- **Files modified:** `app/Domain/Invoicing/Queries/InvoiceableEntries.php`, `tests/Feature/Invoicing/DraftTimeAdditionsTest.php`
- **Commit:** 1323c9b (query), 3b0a5c9 (test)

**3. [Rule 1 - Correctness] R-14-2 counts issued invoices only**
- **Found during:** Task 3
- **Issue:** Summing fixed-amount lines of issued proformas as well as of the invoices created from them would count a converted fixed amount twice.
- **Fix:** `fixedAmountBilled()` reads issued documents of kind invoice and nets issued credit-note lines through `credited_item_id` (a fully cancelled amount is 0).
- **Files modified:** `app/Domain/Invoicing/Queries/InvoiceableEntries.php`
- **Commit:** 1323c9b

**4. [Process] Commit boundary of the reader methods**
- **Found during:** Task 2
- **Issue:** `fixedPriceProjectEntries()` and `fixedAmountBilled()` live in the same file as `eligibleAmong()`, so they were committed with Task 2 and exercised by tests only in Task 3.
- **Fix:** None needed; noted for the record. The Task 2 and Task 3 actions were written before their tests passed (the Task 1 tracer test was run RED first), so tests 2 and 3 were not observed failing on a missing class.
- **Commit:** 1323c9b

**Total deviations:** 4 (1 blocking, 2 correctness, 1 process). **Impact:** none on the plan's contracts; the final code matches the artifact table, with the field-error keys recorded under key-decisions.

## Known Stubs

None.

## Threat Flags

None. T-10-37 is mitigated by strict eligibility on locked rows in the issue step, `not_draft` on unlink and the link guard behind both (tested: a non-billable flip rolls the issue back, an issued document's time stays billed). T-10-38 by filtering candidates and the foreign check by the draft's client and by taking the project through `client_id` (tested with a foreign entry and a foreign project). T-10-39 by `DraftLines` only ever appending (tested with an edited line). No new endpoint, auth path or file access.

## Notes for later plans

- Plans 10-17 and 10-20 change `DocumentIssuer` next; the billing step is `billLinkedTime()` and runs after the flip and before `refresh()`. The issue of a converted proforma must repoint rather than bill (D-15), which is not this step.
- Plan 10-21 maps the field keys `entry_ids`, `project_id`, `amount`, `description`, `currency` and `items` of the three actions to form state paths; the modal helper reads `AddFixedAmount::context()`.
- Plan 10-23 (full storno) is the only path that releases billed time; none of this plan's actions touches an issued document.
- Plan 10-35 proves `EntryReservation::claim()` under concurrency; `AddEntriesToDraft` and `AddFixedAmount` use the same unit.

## Self-Check: PASSED

- Created files exist: `ReleaseEntries.php`, `AddEntriesToDraft.php`, `AddFixedAmount.php`, `DraftLines.php`, `IssueBillingTest.php`, `ReleaseEntriesTest.php`, `DraftTimeAdditionsTest.php`.
- Commits `7b03f5c`, `1323c9b` and `3b0a5c9` are ancestors of HEAD.
