---
phase: 10-invoicing
plan: 05
subsystem: invoicing-access
tags: [partner-isolation, policies, eloquent, canary, morph-map]
status: complete

requires:
  - phase: 10-invoicing
    provides: "invoices and invoice_items tables with frozen guards (plan 10-04) and the document enums (plan 10-33)"
provides:
  - "Invoice model: fail-closed Partner scope (own client, issued, three kinds, client not archived), derived cancelled state (stornoCreditNote, isCancelled, cancelled/notCancelled scopes, CANCELLED_SQL), Money totals, relations"
  - "InvoiceItem model delegating its Partner scope to the scoped Invoice query, with unitPrice(), lineTotal(), quantity()"
  - "InvoicePolicy and InvoiceItemPolicy registered in AccessServiceProvider; morph aliases invoice and invoice_item"
  - "Canary fixtures for both models and the pinned registry lists (ModelDeclarationTest, ActivityAllowlistTest, CanaryRegistryTest)"
affects: [10-06, 10-08, 10-11, 10-16, 10-19, 10-20]

actuals:
  tokens: 12200
  tasks: 3
  commits: 3
plan_head_before: 6e242d4b0dc8a6a54b6923d6885c20f63174a9be
plan_head_after: 02d5bd0b82ba416aaf9303843eb420fa4d1e2718
commits: 3

coverage:
  - deliverable: "A Partner query returns only issued documents of the own, non-archived client; a draft never exists for a Partner, not even as a count"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceScopeTest.php#Partner query"
        status: pass
    human_judgment: false
  - deliverable: "InvoiceItem follows its document: a Partner reads the lines of an own issued document only"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceScopeTest.php#InvoiceItem"
        status: pass
    human_judgment: false
  - deliverable: "Policies: Partner views an own issued document, every write denied, Admin admitted by before()"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceScopeTest.php#InvoicePolicy and #InvoiceItem"
        status: pass
    human_judgment: false
  - deliverable: "Cancelled is derived from the issued storno credit note, visible to the Partner through a row it may read, never stored"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceScopeTest.php#derived cancellation"
        status: pass
    human_judgment: false
  - deliverable: "Allowlisted history under the alias invoice; no note, no snapshot logged"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceScopeTest.php#mass assignment and history; tests/Arch/ActivityAllowlistTest.php"
        status: pass
    human_judgment: false
  - deliverable: "Registries and canary fixtures cover both models so the Partner proof cannot pass vacuously"
    verification:
      - kind: test
        ref: "tests/Isolation/CanaryRegistryTest.php; tests/Arch/ModelDeclarationTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Self-relation scopes use unqualified columns: a self-relation existence query aliases the table, so a column qualified with the table name would read the outer row"
    - "One idempotent canary writer shared by two fixtures (document and line), because lines can only be written while the document is a draft"

key-files:
  created:
    - app/Domain/Invoicing/Models/Invoice.php
    - app/Domain/Invoicing/Models/InvoiceItem.php
    - app/Domain/Invoicing/Policies/InvoicePolicy.php
    - app/Domain/Invoicing/Policies/InvoiceItemPolicy.php
    - tests/Isolation/InvoiceScopeTest.php
  modified:
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Database/MorphMap.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/ActivityAllowlistTest.php

key-decisions:
  - "Cancelled has one definition: an issued credit note with cancels_invoice names the invoice; the relation, both scopes, isCancelled() and the CANCELLED_SQL text constant all state that rule"
  - "InvoiceItemPolicy reads the document through the Partner-scoped relation, the same pattern as TaskPolicy; the check therefore assumes the Partner is the acting user"
  - "Only the printed note of an invoice and the description and unit of a line are fillable; Actions write everything else with forceFill"

patterns-established:
  - "Partner-readable child model delegating its constraint to the scoped parent query (Task to Project, InvoiceItem to Invoice)"

requirements-completed: [IN-09, IN-01]

duration: 40 min
completed: 2026-10-17
---

# Phase 10 Plan 05: Partner-isolated invoice models Summary

**Invoice and InvoiceItem models whose Partner scope returns only issued documents of the own, non-archived client (and their lines), with policies, a derived cancelled state from the issued storno credit note, and canary fixtures and registry pins so the Partner proof cannot pass vacuously.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 3 (1 tracer, 2 auto)
- **Files:** 5 created, 6 modified

## Accomplishments

- `Invoice` is `PartnerIsolated`: own client AND issued AND one of the three kinds AND client not archived (plain EXISTS on `clients`, since Client is closed to Partners). A draft never reaches a Partner, not even as a count or by id, also under or-conditions.
- The derived cancelled state: `stornoCreditNote()` (issued credit note with `cancels_invoice`), `isCancelled()` (uses a loaded `withExists` attribute or relation before querying), `cancelled()` / `notCancelled()` scopes and `CANCELLED_SQL` for correlated fragments. A Partner of the own client sees the cancelled state through a row it may read; nothing is written on the invoice.
- `InvoiceItem` delegates its constraint to `Invoice::query()`; `unitPrice()`, `lineTotal()` return `Money` in the parent currency and `quantity()` returns an `InvoiceQuantity`.
- `InvoicePolicy` and `InvoiceItemPolicy`: Partner `viewAny` and `view` of an own issued document or its line, every write denied.
- History is an allowlist (number, state, kind, client, currency, language, dates, totals, links including `cancels_invoice`); the note and the snapshots are never logged; activity rows carry the alias `invoice`.
- Registries: morph aliases `invoice` and `invoice_item`, model declaration list, activity allowlist list, canary fixtures (an issued document and a draft twin, each with a line, carrying the canary in the note and the line description) and the vacuity list of the canary registry test.

## Task Commits

1. **Task 1 (tracer): Invoice, InvoicePolicy, scope test** - `5803892` (feat)
2. **Task 2: InvoiceItem, InvoiceItemPolicy, line tests** - `b2cf3cd` (feat)
3. **Task 3: canary fixtures prove the draft twin and the other client stay hidden** - `02d5bd0` (test)

## Verification

- `ddev exec vendor/bin/pest` (whole suite, after the last commit): 5118 passed, 3 skipped, 0 failed.
- `ddev exec vendor/bin/pest tests/Isolation tests/Arch tests/Feature/Schema tests/Feature/Localisation`: 792 passed.
- `pest tests/Feature/Operations tests/Feature/Schema tests/Unit` (after Task 1): 1534 passed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set; the grep acceptance criteria of all tasks re-run and passing.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Registry lines land with the model, not only in Task 3**
- **Found during:** Task 1
- **Issue:** the plan puts the registry edits in Task 3, but the pinned model list, activity list and canary registry tests fail as soon as a model exists without its line, which would leave commits 1 and 2 red.
- **Fix:** the registry lines of each model landed in the task that created the model; Task 3 became the targeted canary proof (note and line text on the issued document and the draft twin, Partner sees only the issued ones). Same files, same end state.
- **Commits:** 5803892, b2cf3cd, 02d5bd0

**2. [Plan wording] `Invoice::items()` added in Task 2**
- `items()` points at `InvoiceItem`, which exists only after Task 2, so the relation was added to `Invoice.php` in Task 2 (a file not listed in that task).

**3. [Rule 1 - Bug avoided] Unqualified columns in `stornoCreditNote()`**
- A self-relation existence query aliases the table; the relation constraints are merged with the original table name, so `invoices.cancels_invoice` would have read the outer row. The relation uses unqualified columns and the test proves `cancelled()` and `notCancelled()` return the right invoices.

**Total deviations:** 1 blocking (ordering), 2 notes. **Impact:** none on the plan's contracts.

## Known Stubs

None.

## Threat Flags

None. T-10-12 (Partner reads a draft, another client's or an archived client's document) is mitigated by the scope, the policies, the scope test and the canary fixtures; T-10-14 (mass assignment) by `#[Fillable(['note'])]` and `#[Fillable(['description', 'unit'])]` with a test that the other columns throw; T-10-SC: nothing installed.

## Notes for later plans

- `InvoiceItemPolicy::view` reads the document through the Partner-scoped relation (the TaskPolicy pattern), so ask the gate while the Partner is the acting user.
- There is no model factory for the invoice tables yet; tests write documents in system runs with `forceFill`, lines while the document is still a draft (an issued document accepts no new line).
- `Invoice::CANCELLED_SQL` uses the inner alias `storno_notes` and reads the outer table as `invoices`; wrap it only in a query whose outer table is `invoices` (plan 10-16).

## Self-Check: PASSED

Created files exist on disk (both models, both policies, `InvoiceScopeTest.php`); commits `5803892`, `b2cf3cd`, `02d5bd0` are ancestors of HEAD; the commit count measured from the plan ledger is 3.
