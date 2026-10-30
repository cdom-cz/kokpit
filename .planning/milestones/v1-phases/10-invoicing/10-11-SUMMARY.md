---
phase: 10-invoicing
plan: 11
subsystem: invoicing-drafts
tags: [invoicing, draft, actions, validation, totals, internal-note, fixtures, lang]

requires:
  - phase: 10-invoicing
    provides: "Invoice, InvoiceItem models, InvoiceQuantity, Money::times() (plans 10-01, 10-05)"
  - phase: 10-invoicing
    provides: "InvoiceAdmin and InvoiceItemSource Admin-only models (plan 10-08)"
  - phase: 10-invoicing
    provides: "EntryBilling::unreserve() (plan 10-09)"
provides:
  - "CreateDraft: an empty invoice or proforma draft for one active client, with its Admin-only row, no number and null dates"
  - "UpdateDraft: the single write path of a draft (header, printed note, internal note, lines by id) with server-side line and document totals"
  - "DeleteDraft: releases reserved entries and removes sources, lines, admin row and draft in one transaction"
  - "UpdateInternalNote: the internal note and its marker on a draft or an issued document, never logging the text"
  - "InvoiceItemInput (field errors items.<n>.<field>) and DraftTotals (lineTotal, for)"
  - "lang/cs/invoices.php errors section: the complete domain error copy of the phase"
  - "config kokpit.invoicing.max_items = 200"
  - "Tests\\Support\\InvoiceFixtures: client(), draft(), item()"
affects: [10-12, 10-13, 10-14, 10-15, 10-17, 10-21, 10-40]

actuals:
  tokens: 16200
  tasks: 3
  commits: 3
plan_head_before: 7b607d8d5cd9d3606095aa66d7491a63e19de191
plan_head_after: 0a8c2f0446b2ae36fd993f7fd09f403a23fb7f7b
commits: 3

coverage:
  - deliverable: "The Admin creates an empty draft (invoice or proforma) for one client with the client's currency and invoice language and the current VAT mode, no number, null dates and an Admin-only row, consuming no number; a Partner is refused"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#creates an empty draft with the defaults of the client and consumes no number"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses a Partner both Actions"
        status: pass
    human_judgment: false
  - deliverable: "UpdateDraft saves manual lines with line totals and document totals computed on the server through Money::times() and DraftTotals, ignores forged totals, keeps VAT at zero in the non-payer mode, matches lines by id, deletes missing lines with their sources and follows the order sent"
    requirement: "IN-08"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#saves manual lines with the totals computed on the server and ignores a forged total"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#updates a line by id, keeps its row and source, deletes a missing line with its source and follows the order sent"
        status: pass
    human_judgment: false
  - deliverable: "Lines validate as Czech field errors items.<n>.<field>, a zero price needs confirmation, a draft holds at most 200 lines, a foreign line id is refused without a write, a due date before the issue date is refused, an issued document is not updatable"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses bad lines as field errors in Czech and stores nothing"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#needs the explicit confirmation for a zero unit price"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#caps a draft at the configured number of lines"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses the id of a line of another draft and writes nothing"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses a due date before the issue date"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses to update an issued document"
        status: pass
    human_judgment: false
  - deliverable: "Editing the unit price of a time-generated line marks its source as a manual price and keeps its origin; editing quantity or text does not"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#marks the price of a line generated from time as manual only when the unit price changes, and keeps its origin"
        status: pass
    human_judgment: false
  - deliverable: "DeleteDraft returns every reserved entry to unbilled with no link, removes the draft with its lines, sources and admin row, consumes no number, and never deletes an issued document (Action and database)"
    requirement: "IN-10"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#deletes a draft, releases its reserved entries and consumes no number"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#never deletes an issued document, in the Action or in the database"
        status: pass
    human_judgment: false
  - deliverable: "The internal note is edited on a draft and on an issued document under the row lock; the activity log records the marker and never the text; over 2 000 characters and a Partner are refused"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#stores the internal note of a draft and of an issued document with a new marker and never logs the text"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#leaves the marker alone when the internal note does not change and clears it with a blank note"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#saves the internal note together with the draft"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#refuses an internal note over 2000 characters and refuses a Partner"
        status: pass
    human_judgment: false
  - deliverable: "InvoiceFixtures builds fictional clients, drafts (invoice and proforma) and manual lines for later tests"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/DraftLifecycleTest.php#builds fictional clients and drafts for later tests"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Draft Actions re-validate everything: the editor hands a plain array, the Action recomputes every total and checks every id against the locked rows"
    - "Kept lines are parked at position 1 000 000 plus index before positions are reassigned, so a reorder never meets the unique (invoice_id, position)"
    - "Field errors of repeated lines are collected and thrown once, keyed items.<n>.<field>"

key-files:
  created:
    - app/Domain/Invoicing/Actions/CreateDraft.php
    - app/Domain/Invoicing/Actions/UpdateDraft.php
    - app/Domain/Invoicing/Actions/DeleteDraft.php
    - app/Domain/Invoicing/Actions/UpdateInternalNote.php
    - app/Domain/Invoicing/Support/InvoiceItemInput.php
    - app/Domain/Invoicing/Support/DraftTotals.php
    - lang/cs/invoices.php
    - tests/Support/InvoiceFixtures.php
    - tests/Feature/Invoicing/DraftLifecycleTest.php
  modified:
    - config/kokpit.php

key-decisions:
  - "R-11-1 to R-11-5 applied as planned: null issue and due dates mean 'derived at issue', the currency is any known ISO code, lines are upserted by id, the full error copy is written now, the internal note is edited only through UpdateInternalNote"
  - "A manual line gets a source row with origin manual and price_manual true (the price is typed); a time or fixed-amount line becomes price_manual true only when its unit price changes"
  - "A missing items key in UpdateDraft leaves the lines untouched; a caller that changes the currency sends the items with it, as the editor always does"
  - "InvoiceItemInput takes the document kind: a credit note line carries a negative quantity, an invoice or proforma line a positive one"
  - "UpdateInternalNote writes nothing when the trimmed note equals the stored one, so the marker keeps the time of the last real change; a blank note clears it"

patterns-established:
  - "Nested Action composition inside one transaction: UpdateDraft calls UpdateInternalNote, which re-takes the same row lock"
  - "Test fixtures go through the domain Actions as the Admin, never through raw inserts"

requirements-completed: [IN-02, IN-08, IN-10]

duration: 12 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 11: Draft lifecycle with manual items Summary

**CreateDraft, UpdateDraft, DeleteDraft and UpdateInternalNote with a field-error item validator, server-side totals, the phase's complete Czech error copy and the fictional fixtures every later invoicing test builds on.**

## Performance

- **Duration:** about 12 min (2026-10-17T08:07Z to 08:19Z)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 9 created, 1 modified

## Accomplishments

- `CreateDraft` writes the Partner-hidden draft and its `invoice_admin` row together: currency defaults to the client's, language to the client's invoice language, `vat_mode` to the current setting, number and dates null. An archived client, an unknown client, a credit note kind and a non-ISO currency are field errors.
- `UpdateDraft` saves header, printed note, internal note and lines in one transaction under the invoice row lock and refuses a non-draft with `not_draft`. Lines are matched by id (a foreign or repeated id is `items.<n>.id`, never a write), inserted as manual lines with a source row, deleted with their source when missing, and ordered as sent. Line totals come from `DraftTotals::lineTotal()` (`Money::times()`), `price_converted` is false, document totals are the sum of the stored line totals, VAT is 0; client totals in the payload are ignored.
- `InvoiceItemInput` collects all line problems into one `ValidationException` keyed `items.<n>.<field>`; the 200-line cap is a single error on `items`.
- `DeleteDraft` locks the draft and its entries, releases them through `EntryBilling::unreserve()`, then deletes sources, lines, the admin row and the draft; no number is consumed; an issued document is refused by the Action and by the database guard.
- `UpdateInternalNote` changes only the note and its marker; the history carries the marker and never the text.
- `lang/cs/invoices.php` holds every domain error of the phase (the plan's list plus three small additions, see Deviations); `kokpit.invoicing.max_items` is 200.
- `InvoiceFixtures::client()`, `draft()` and `item()`; the lifecycle test file uses them.

## Task Commits

1. **Task 1 (tracer): CreateDraft, UpdateDraft, validator, totals, error copy, config** - `6599692` (feat)
2. **Task 2: field-error tests, line origin handling, DeleteDraft** - `049f5cd` (feat)
3. **Task 3: UpdateInternalNote, its wiring into UpdateDraft, InvoiceFixtures** - `0a8c2f0` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Arch tests/Isolation`: 391 passed.
- Whole suite `ddev exec vendor/bin/pest`: 5263 passed, 3 skipped, 0 failed.
- `pint --test` and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set; lefthook (sensitive-content, gitleaks) passed on all three commits.
- Acceptance greps of all three tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Three error messages beyond the plan's list**
- **Found during:** Task 1
- **Issue:** The plan's contract has no copy for an invalid language, an invalid date or an unknown client, and `UpdateDraft` and `CreateDraft` need a field error for each.
- **Fix:** Added `language` "Zvolte jazyk dokladu.", `date_invalid` "Zadejte platné datum.", `client_unknown` "Vyberte klienta." to the `errors` section (the plan's own keys are unchanged).
- **Files modified:** `lang/cs/invoices.php`
- **Commit:** 6599692

**2. [Rule 3 - Blocking] `config/kokpit.php` committed with Task 1, not Task 2**
- **Found during:** Task 1
- **Issue:** The tracer's `InvoiceItemInput` call reads `kokpit.invoicing.max_items`, so the config entry had to exist for the tracer to run.
- **Fix:** The config entry went into the Task 1 commit; Task 2 only adds tests and `DeleteDraft`.
- **Files modified:** `config/kokpit.php`
- **Commit:** 6599692

Task 1 also contains the full line validation and upsert logic of `UpdateDraft` (the plan splits them over Tasks 1 and 2), because the tracer's save path is that logic; Task 2 added the tests that pin it. `UpdateInternalNote` and its wiring into `UpdateDraft` came in Task 3 as planned.

**Total deviations:** 2 auto-fixed (1 missing critical, 1 blocking). **Impact:** none on behavior; the error copy is a superset of the plan's.

## Known Stubs

None.

## Threat Flags

None. T-10-28 (forged totals and ids) is mitigated by the server recomputation and the id check against the locked draft, T-10-29 by `Gate::forUser()->authorize()` first in every Action plus the row lock and `not_draft` refusal, T-10-30 by the marker-only allowlist and the canary test; no new endpoint, auth path or file access.

## Notes for later plans

- Plan 10-12 may add to `tests/Support/InvoiceFixtures.php` (only it and this plan edit the file).
- Plan 10-13 adds the `time` source to `CreateDraft` (today any source other than `empty` raises `InvalidArgumentException`) and writes `time` source rows; `UpdateDraft` already keeps their origin and marks the price manual on a price edit.
- Plan 10-17's conversion step leaves lines whose source has `price_manual` true alone; manual lines are written with `price_manual` true.
- `UpdateDraft` with an `items` key replaces the whole list, so an editor must send every line it wants to keep.

## Self-Check: PASSED

Created files exist on disk (nine files listed above); commits `6599692`, `049f5cd`, `0a8c2f0` are ancestors of HEAD; the commit count measured from the plan ledger is 3.
