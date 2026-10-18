---
phase: 10-invoicing
plan: 12
subsystem: invoicing-issuing
tags: [invoicing, issue, numbering, snapshots, blockers, cnb, fixtures]

requires:
  - phase: 10-invoicing
    provides: "DocumentNumbering and the variable symbol (plans 10-03, 10-07)"
  - phase: 10-invoicing
    provides: "Draft lifecycle Actions, InvoiceFixtures client()/draft()/item(), the error copy (plan 10-11)"
provides:
  - "DocumentIssuer: the issue pipeline (checks, dates, Admin-only row with the CNB trio and CZK total, snapshots, late number allocation, chronology under the sequence lock, state flip as the last write)"
  - "IssueContext: today as a Prague day plus an optional CNB trio to copy (credit notes)"
  - "IssueChecks: blockers() for the UI pre-check and assertIssuable() for the issuer, nine plan blockers plus due_before_issue"
  - "InvoiceSnapshot: supplier, customer, bank and appearance snapshots, logo and signature frozen as path plus SHA-256"
  - "IssueDocument: the Admin Action (authorize update, row lock, issue) and expectedNumber() preview"
  - "InvoiceFixtures: configureSupplier(), cnbRate(), issued(), stornoed()"
affects: [10-13, 10-14, 10-17, 10-20, 10-21, 10-23, 10-35, 10-40]

actuals:
  tokens: 13900
  tasks: 2
  commits: 2
plan_head_before: ad21da6a1a8019a58dae8958d03a519303b00dc6
plan_head_after: 92096b50ee42bc9c0c82702e6017c229e5476a8f
commits: 2

coverage:
  - deliverable: "The Admin issues a CZK draft in one transaction: next number from the series of its kind, variable symbol, issue and due dates, non-payer VAT mode, supplier, customer, bank and appearance snapshots, issued_at; invoice, proforma and credit note keep separate series; an issued row refuses every write"
    requirement: "IN-01"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#issues a CZK draft with the next number, frozen snapshots, a variable symbol and dates"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#numbers invoices and proformas from their own series"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses every write to an issued row, even a touch"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#issues a credit note from its own series without a variable symbol, a due date or a bank account"
        status: pass
    human_judgment: false
  - deliverable: "The logo and the signature are frozen by stored path and SHA-256, so replacing the logo in the settings leaves an issued snapshot unchanged; an unreadable image fails the issue and numbers nothing"
    requirement: "IN-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#keeps the logo of the snapshot when the logo in the settings is replaced afterwards"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#fails the issue and numbers nothing when the stored logo cannot be read"
        status: pass
    human_judgment: false
  - deliverable: "A foreign-currency document stores the CNB rate, unit and list date valid on the issue date (the Friday list for a Sunday) and the CZK total in the Admin-only row; a CZK document stores none; a missing list is the cnb_missing blocker"
    requirement: "IN-07"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#stores the CNB rate, unit, list date and CZK total of a foreign-currency document"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#stores no CNB figure and no CZK total for a CZK document"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses each blocker alone with its exact Czech message and consumes nothing with ('cnb_missing')"
        status: pass
    human_judgment: false
  - deliverable: "Each pre-issue blocker alone keeps the draft a draft, leaves the counters unchanged and reports its exact Czech message under its key; blockers() lists the same without throwing; a credit note is refused for a positive total and not for a missing bank account; a Partner is refused"
    requirement: "IN-03"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses each blocker alone with its exact Czech message and consumes nothing"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses a credit note with a positive total"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses a Partner and numbers nothing"
        status: pass
    human_judgment: false
  - deliverable: "Chronology per series (ruling R-12-1): a back-dated issue date is allowed down to the latest issued document, an earlier one is refused naming that date, and the check repeated under the sequence lock rolls the allocated number back"
    requirement: "IN-01"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#lets the first invoice be back-dated by two days"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#refuses a date before the latest issued document of the series and keeps the counter"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#checks the chronology again under the sequence lock and gives the number back"
        status: pass
    human_judgment: false
  - deliverable: "Non-VAT-payer mode: the issued document has vat_mode non_payer, vat_minor 0 and no taxable-supply date"
    requirement: "IN-08"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#issues a CZK draft with the next number, frozen snapshots, a variable symbol and dates"
        status: pass
    human_judgment: false
  - deliverable: "InvoiceFixtures::issued() and stornoed() build an issued document and a cancelled invoice (issued full-storno credit note) through the only writes the schema allows, leaving the issued row untouched"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/IssueDocumentTest.php#builds a cancelled invoice with the fixture and leaves the issued row untouched"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "The pipeline reads and writes the Admin-only row first, allocates the number late, and flips the state as the one and last write on the invoices row"
    - "Settings are read with app() at the moment of use inside long-lived services, never held, because the Spatie settings instances are scoped"
    - "A race between blocker check and number allocation is tested by binding a SequenceAllocator subclass that issues a competing document before delegating"

key-files:
  created:
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Invoicing/Issuing/IssueContext.php
    - app/Domain/Invoicing/Support/IssueChecks.php
    - app/Domain/Invoicing/Support/InvoiceSnapshot.php
    - app/Domain/Invoicing/Actions/IssueDocument.php
    - tests/Feature/Invoicing/IssueDocumentTest.php
  modified:
    - tests/Support/InvoiceFixtures.php

key-decisions:
  - "R-12-1 to R-12-4 applied as planned; the advisory chronology blocker in IssueChecks and the authoritative check after allocation share IssueChecks::latestIssueDate()"
  - "IssueChecks::blockers() and assertIssuable() take an optional third argument, the CNB trio to copy, so a credit note that copies the rate of its invoice does not need a list on its own issue date"
  - "A typed due date earlier than the (derived) issue date is the due_before_issue blocker, because UpdateDraft can only compare the two when both are set"
  - "Czech dates in blocker messages are written j. n. Y (14. 10. 2026)"
  - "The issuer builds snapshots and the Admin-only row before the allocation and takes the number just before the flip, so the counter row is locked for the shortest part of the transaction"

patterns-established:
  - "InvoiceFixtures::configureSupplier() and settings writes in tests run as system: the settings model is closed to a guest"
  - "A jsonb snapshot is compared with toEqual in tests, because PostgreSQL does not keep the key order"

requirements-completed: [IN-01, IN-03, IN-07, IN-08]

duration: about 26 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 12: Issuing a document Summary

**One issue transaction that blocks incomplete drafts with named Czech reasons, takes the number from the series of its kind late under the sequence lock, freezes supplier, customer, bank, appearance (images by path and SHA-256) and the CNB rate with the CZK total, and flips the state as the last write on the row.**

## Performance

- **Duration:** about 26 min (2026-10-17T08:15Z to 08:41Z)
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 6 created, 1 modified

## Accomplishments

- `IssueDocument::handle()` authorizes `update`, locks the draft and runs `DocumentIssuer::issue()` in one transaction; any failure rolls back the number as well. `expectedNumber()` previews the counter for the confirmation modal and consumes nothing.
- `DocumentIssuer` order: blockers on the locked row, date resolution (null issue date becomes today in Prague, null due date the issue date plus the client's payment terms, none for a credit note), Admin-only row with the CNB trio and `czk_total_minor` for a non-CZK document (or the trio from `IssueContext`), snapshots, `DocumentNumbering::next()`, the chronology check under the sequence lock, then one `save()` that flips the state. The variable symbol comes from `VariableSymbol::fromNumber()`; a credit note has none, no due date and no bank snapshot.
- `IssueChecks` returns the blockers in UI order with their exact Czech messages: `no_items`, `total_not_positive`, `supplier_missing`, `bank_missing`, `cnb_missing`, `client_address_missing`, `issue_date_future`, `issue_date_before_latest`, `due_before_issue`, `pattern_invalid`.
- `InvoiceSnapshot` builds the four snapshots in the shapes of R-12-3; the logo and signature are `DocumentImage::reference()` of the current settings paths, so a replaced logo never changes an issued document and an unreadable image fails the issue.
- `InvoiceFixtures` gained `configureSupplier()`, `cnbRate()`, `issued()` and `stornoed()`, the last building the issued full-storno credit note through draft, lines, Admin-only row and flip, writing nothing on the invoice it cancels.

## Task Commits

1. **Task 1 (tracer): issue pipeline, snapshots, Action, fixtures** - `d377db6` (feat)
2. **Task 2: blockers, chronology under the lock, CNB snapshot and CZK total** - `92096b5` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Concurrency tests/Arch tests/Isolation`: 434 passed.
- `tests/Feature/Invoicing/IssueDocumentTest.php`: 25 passed. A mutation that disables the post-allocation chronology check makes `checks the chronology again under the sequence lock and gives the number back` fail.
- `pint --test` and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.
- Acceptance greps of both tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] `due_before_issue` blocker**
- **Found during:** Task 2
- **Issue:** `UpdateDraft` refuses a due date before the issue date only when both are typed. A typed due date with a null issue date (derived as today at issue) could yield an issued invoice due before it was issued.
- **Fix:** Added `due_before_issue` (copy already in `lang/cs/invoices.php`) to `IssueChecks::blockers()` for invoices and proformas.
- **Files modified:** `app/Domain/Invoicing/Support/IssueChecks.php`, `tests/Feature/Invoicing/IssueDocumentTest.php`
- **Commit:** 92096b5

**2. [Rule 3 - Blocking] Optional third argument on the check methods**
- **Found during:** Task 2
- **Issue:** `IssueContext` carries a CNB trio to copy (credit notes), so the blocker `cnb_missing` must not fire for a document that will copy a rate instead of looking one up.
- **Fix:** `blockers()` and `assertIssuable()` take an optional `?array $exchangeSnapshot`; the two-argument call of the plan contract still works.
- **Files modified:** `app/Domain/Invoicing/Support/IssueChecks.php`
- **Commit:** 92096b5

**3. [Rule 3 - Blocking] Extra fixture `cnbRate()` and settings written as system**
- **Found during:** Task 1
- **Issue:** The foreign-currency tests need stored CNB rows, and the settings model is closed to a guest, so `configureSupplier()` could not run before a user is signed in.
- **Fix:** Added `InvoiceFixtures::cnbRate()`; `configureSupplier()` writes the settings inside `runAsSystem`.
- **Files modified:** `tests/Support/InvoiceFixtures.php`
- **Commit:** d377db6

**Total deviations:** 3 auto-fixed (1 missing critical, 2 blocking). **Impact:** none on the plan's contract; the additions are optional arguments, one extra blocker and one extra fixture.

## Known Stubs

None.

## Threat Flags

None. T-10-32 is mitigated by `assertIssuable()` on the locked row plus the chronology check after allocation; T-10-33 by `authorize('update')` first (tested for a Partner); T-10-31 by allocating inside the issue transaction after validation (the concurrency proof stays with plan 10-35).

## Notes for later plans

- The issued row accepts no write: steps that plans 10-14, 10-17 and 10-20 add to the pipeline must write only time entries, `invoice_admin` and documents, and must run before the final `save()` when they need to change `invoices` columns.
- `DocumentIssuer::issue()` takes the draft's `total()` for the CZK total, so a step that changes line totals (plan 10-17 conversion) must run before the Admin-only row is written, or the row must be rewritten afterwards while the parent is still a draft.
- Plan 10-21 amends UI-SPEC row N5 with the ruling R-12-1.
- Plan 10-23 calls `DocumentIssuer::issue()` with an `IssueContext` carrying the `exchangeSnapshot` of the credited invoice.

## Self-Check: PASSED

All seven files exist on disk; commits `d377db6` and `92096b5` are ancestors of HEAD; the commit count measured from the plan ledger is 2.
