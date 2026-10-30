---
phase: 10-invoicing
plan: 29
subsystem: invoicing-admin-ui
tags: [invoicing, filament, issue-flow, document-detail, relation-managers, internal-note, pdf-regeneration, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "IssueDocument, IssueChecks, DraftPreview, UpdateInternalNote (plans 10-11 to 10-20); EditInvoice, InvoiceEntriesRelationManager (plan 10-25); RegeneratePdf and the confirmed PDF deletion (plan 10-36); ReceivablesReader, InvoicePdfUrl, lang/cs/invoices.php (plans 10-19, 10-21, 10-38)"
provides:
  - "EditInvoice 'Vystavit': saves the form, lists every blocker in a danger notification without opening a modal, or confirms with the summary, the CNB preview and the expected number; the submit goes through IssueDocument and redirects to the detail with send=1 (R-29-1)"
  - "ViewInvoice: the complete issued detail (banners, Doklad, Strany, Polozky, Souhrn with the CNB snapshot, Souvisejici doklady, Poznamky), 'Upravit poznamku' through UpdateInternalNote, the missing-PDF callout with 'Vytvorit PDF znovu' through RegeneratePdf, 'Stahnout PDF' through the signed route"
  - "InvoicePaymentsRelationManager (list), InvoiceDocumentsRelationManager, InvoiceHistoryRelationManager and the relation order Platby, Navazany cas, Soubory, Historie zmen"
  - "Invoice::documents() morph relation and the activity labels of a document"
affects: [10-30, 10-31, 10-32, 10-33]

actuals:
  tokens: 24800
  tasks: 3
  commits: 3
plan_head_before: a1764062f820c975266ed9a58f3a6181ad76d9c5
plan_head_after: 7a41e58232a6e0cc60889b04568273d9944b9305
commits: 3

tech-stack:
  added: []
  patterns:
    - "A header action whose pre-check runs in mountUsing: save the form, collect the blockers, notify and halt() so no modal opens; the modal exists only when nothing blocks"
    - "A relation manager that is offered only on some documents overrides canViewForRecord and keeps the AccessRule check by aliasing the trait's method (use ... { canViewForRecord as private canViewForAdmin; })"
    - "The derived state of a document (status, remaining, overdue days, paid) is computed once per request in the page and feeds the heading badge, the banners and the summary, so they cannot disagree"
    - "A failure of a write that must not leak: report() the exception, show fixed copy only, assert in the test that the exception text appears neither in the notification nor in the HTML"

key-files:
  created:
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
    - app/Filament/RelationManagers/InvoiceDocumentsRelationManager.php
    - app/Filament/RelationManagers/InvoiceHistoryRelationManager.php
    - tests/Feature/Invoicing/IssueFlowTest.php
    - tests/Feature/Invoicing/InvoiceDetailTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - app/Filament/Resources/InvoiceResource.php
    - app/Filament/Support/InvoiceColumns.php
    - app/Domain/Invoicing/Models/Invoice.php
    - lang/cs/invoices.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/CreateDraftModalTest.php

key-decisions:
  - "R-29-1 applied: after a successful issue the page redirects to the view page with the query flag send=1; plan 10-31 opens the e-mail dialog from it"
  - "R-29-2 applied: InvoiceEntriesRelationManager is reused read only on the issued detail as 'Navazany cas'; the tab list is now Platby, Navazany cas, Soubory, Historie zmen"
  - "The stub infolist of InvoiceResource (R-21-1) is removed; the full detail schema lives in ViewInvoice, which also owns the heading badges and the client subheading"
  - "The view address of a draft now redirects to the editor (the interim R-21-1 behaviour ended with plan 10-25); the old plan 10-21 test was updated to say so"
  - "Platby and Soubory are offered only on an issued document (Platby also only on an invoice or proforma); the history tab is offered on every document"
  - "A failed issue shows the fixed Czech copy and report()s the exception; a ValidationException from a stale draft is listed like the pre-check blockers; a DomainException (already issued) shows the fixed not_draft copy and goes to the detail"

patterns-established:
  - "Pre-check in mountUsing with halt() for a confirmation that must not open when a precondition fails"
  - "Callout footer actions with a component key, reachable in tests through TestAction::make(...)->schemaComponent(key, schema: 'infolist')"

requirements-completed: [IN-01, IN-03, IN-04, IN-07]

coverage:
  - id: D1
    description: "Vystavit on a draft saves the form, shows every blocker in one danger notification (no modal) or the confirmation with client, total, dates, currency, the CNB preview and the expected number; the submit issues through IssueDocument, shows the kind-specific toast and opens the detail with send=1"
    requirement: "IN-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssueFlowTest.php#the tracer: Vystavit on a complete draft"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/IssueFlowTest.php#the pre-issue check"
        status: pass
    human_judgment: false
  - id: D2
    description: "A failed issue (PDF render bound to throw) shows 'Doklad se nepodarilo vystavit' with the fixed body, never the exception text, keeps the draft and leaves the number sequences untouched; a stale second submit issues nothing twice"
    requirement: "IN-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssueFlowTest.php#a failed issue"
        status: pass
    human_judgment: false
  - id: D3
    description: "The issued detail answers 'is it paid and what is next': heading with kind and state badges and the client link, the overdue, awaiting-invoice, cancelled-with-payment and credit-note banners, the converted proforma with its related invoice, and nothing that does not apply"
    requirement: "IN-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the heading and the state"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the banners"
        status: pass
    human_judgment: false
  - id: D4
    description: "The frozen sections: both parties as snapshotted (a later client change does not show), negative credit-note lines, the CNB rate with its date and the CZK total for a foreign currency only, the printed note read only"
    requirement: "IN-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the frozen sections"
        status: pass
    human_judgment: false
  - id: D5
    description: "'Upravit poznamku' stores the internal note through UpdateInternalNote and writes nothing on the issued invoices row; an over-long note is refused under the field"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the internal note"
        status: pass
    human_judgment: false
  - id: D6
    description: "A deleted PDF shows 'PDF dokladu chybi.' and 'Vytvorit PDF znovu' restores it; a failed regeneration shows 'PDF se nepodarilo vytvorit' with fixed copy and keeps the state; 'Stahnout PDF' redirects to the signed route"
    requirement: "IN-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the stored PDF"
        status: pass
    human_judgment: false
  - id: D7
    description: "The tabs Platby, Navazany cas (read only, footer total, no add or unlink), Soubory (PDF with preview, download and the confirmed deletion) and Historie zmen (allowlisted attributes only) in the specified order; a Partner is refused on every relation manager"
    requirement: "IN-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceDetailTest.php#the relation tabs"
        status: pass
    human_judgment: false
  - id: D8
    description: "A synchronous PDF render at issue of a 60-line document stays responsive, and the detail's banners, sections and tabs read in the UI-SPEC order in light and dark mode"
    verification: []
    human_judgment: true
    rationale: "Perceived responsiveness of the synchronous render and the visual order are the plan's human-check backstop; no test measures them and the human check was not run by the executor"

duration: 55min
completed: 2026-10-17
---

# Phase 10 Plan 29: Issue flow and issued document detail Summary

**The Admin issues from the panel (save, pre-check that lists every blocker or a confirmation with the CNB preview and the expected number, one transaction through IssueDocument) and lands on a frozen detail that shows its state, snapshots, CNB figures, internal note, missing-PDF recovery and the tabs Platby, Navazany cas, Soubory and Historie zmen.**

## Accomplishments

- `EditInvoice` has the primary "Vystavit" header action. Its pre-check runs in `mountUsing`: it saves the form (field errors stay on the fields), re-reads the draft, asks `IssueChecks::blockers()` and, when anything blocks, sends one danger notification with a list item per blocker plus links to the Nastaveni or the Kurzy CNB where a screen can fix it, then halts so no modal opens. When nothing blocks, the modal shows "Vystavit fakturu?" or "Vystavit zalohovou fakturu?", Klient, Celkem k uhrade, Datum vystaveni, Splatnost, Mena, for a foreign currency the CNB rate and the CZK amount, and the warning callout with `IssueDocument::expectedNumber()`.
- The submit calls `IssueDocument::handle()`. Success shows the kind-specific toast and redirects to the view page with `send=1` (R-29-1). A stale draft (blockers appearing in between) is listed like the pre-check; an already issued draft goes to its detail; any other failure is `report()`ed and shown as "Doklad se nepodarilo vystavit" with the fixed body, never the exception text. The test binds the PDF renderer to throw and proves that the draft, the number sequences and the notification body are untouched by the exception text.
- `ViewInvoice` is the complete Surface E: heading with kind and state badges, the client name as a link, the banners in UI-SPEC order (overdue with the Czech plural of days, paid proforma without invoice, cancelled with a payment, existing credit note with links, missing PDF), the sections Doklad, Strany (snapshots plus lock icon with the tooltip), Polozky (with the `#` column, credit-note amounts negative), Souhrn (Celkem k uhrade, Uhrazeno, Zbyva uhradit, CNB rate with date and CZK total for a foreign currency), Souvisejici doklady (only real relations) and Poznamky. The state is derived once per request by `ReceivablesReader`.
- "Upravit poznamku" is a link on the internal note that opens a small modal and calls `UpdateInternalNote`; the test proves the issued `invoices` row is byte-identical afterwards. "Vytvorit PDF znovu" calls `RegeneratePdf`; a failure shows "PDF se nepodarilo vytvorit" with the fixed body. "Stahnout PDF" redirects to the signed route of plan 10-38.
- New tabs: `InvoicePaymentsRelationManager` (date, amount, method badge, income category linking to the transaction or a dash on a proforma, note, empty state), `InvoiceDocumentsRelationManager` (the shared `ManagesDocuments` host, owner column hidden, deletion through the confirmed path of plan 10-36) and `InvoiceHistoryRelationManager`. `InvoiceResource::getRelations()` returns Platby, Navazany cas (reused read only, R-29-2), Soubory, Historie zmen. Platby and Soubory are offered only on an issued document, Platby only on an invoice or proforma.

## Task Commits

1. Task 1 (tracer): `27825fb` feat(10-29): Vystavit saves the draft, lists every blocker or confirms with the expected number, and issues through IssueDocument
2. Task 2: `f0ca8f1` feat(10-29): the issued detail shows the frozen document, its state banners, related documents, the internal note and the missing-PDF recovery
3. Task 3: `7a41e58` feat(10-29): the issued detail gets the tabs Platby, Navazany cas, Soubory and Historie zmen, read only where the document is frozen

## Tracer gate

After Task 1 the tracer `<verify>` (IssueFlowTest, Pint, PHPStan) was run and passed, plus the whole Invoicing and Arch suites (459 tests), before the expansion tasks started.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `Invoice::documents()` morph relation was missing**
- **Found during:** Task 3
- **Issue:** `InvoiceDocumentsRelationManager` uses `ManagesDocuments` with `$relationship = 'documents'`, but `Invoice` had no such relation (the PDF is reached only through `invoice_admin`, R-06-6).
- **Fix:** Added `Invoice::documents(): MorphMany` over the existing `invoice` morph alias, same shape as `Transaction::documents()`.
- **Files modified:** app/Domain/Invoicing/Models/Invoice.php
- **Commit:** 7a41e58

**2. [Rule 3 - Blocking] Two helpers of the Partner column builder were private**
- **Found during:** Task 2
- **Issue:** The Admin detail needs the same party and bank-account lines as the Partner detail; duplicating them would let the two screens format a snapshot differently.
- **Fix:** `InvoiceColumns::partyLines()` and `bankLines()` became public static (no behaviour change).
- **Files modified:** app/Filament/Support/InvoiceColumns.php
- **Commit:** f0ca8f1

**3. [Rule 1 - Bug] The interim "view page of a draft" test (plan 10-21) contradicted the finished flow**
- **Found during:** Task 2 (full Invoicing run)
- **Issue:** `CreateDraftModalTest` asserted that a draft opens the view page (R-21-1, valid only until plan 10-25). The detail is now the page of an issued document.
- **Fix:** The view address of a draft redirects to the editor; the test now asserts that redirect.
- **Files modified:** app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php, tests/Feature/Invoicing/CreateDraftModalTest.php
- **Commit:** f0ca8f1

**4. [Rule 2 - Missing critical] Readable history labels for a document**
- **Found during:** Task 3
- **Issue:** Without `kokpit.activity.attributes.invoice.*` the history tab would print raw column names (`state`, `number`).
- **Fix:** Added the subject label and the Czech attribute labels of the allowlisted attributes of an invoice.
- **Files modified:** lang/cs/kokpit.php
- **Commit:** 7a41e58

**5. [Plan scope] Files outside `files_modified`**
- `lang/cs/invoices.php` (plural banner, link and `pdf_created` strings), `lang/cs/kokpit.php`, `app/Domain/Invoicing/Models/Invoice.php`, `app/Filament/Support/InvoiceColumns.php` and `tests/Feature/Invoicing/CreateDraftModalTest.php` were touched for the reasons above.

**Total deviations:** 4 auto-fixed (1 bug, 1 missing critical, 2 blocking) and one scope note. **Impact:** none on behaviour beyond the plan.

### Known simplifications (not deviations from a must-have)

- UI-SPEC Surface D says the field a blocker concerns is "outlined". The notification names every blocker with its message and a link; the fields themselves are not outlined (the bank-account line of the editor already shows its own danger message).
- The danger banner "Zaplacena zaloha bez faktury" carries no "Vystavit fakturu" button of its own. That action is the header action of plan 10-30 (UI-SPEC E5); this plan renders the banner and keeps the header list ready for it.

## Authentication Gates

None.

## Known Stubs

None. Every column and section reads real data; the payments tab has no row action on purpose (plan 10-30 adds "Smazat platbu").

## Threat Flags

None. The only new writes reachable from the detail are `UpdateInternalNote` and `RegeneratePdf`, both already in the plan's threat model (T-10-74, T-10-75) and both mitigated and tested here: the issue is refused for a non-draft by the Action, and no exception text reaches a notification or the HTML.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite): 5776 passed, 3 skipped (pre-existing skips), 0 failed.
- `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: clean.
- `scripts/check-sensitive.sh` clean on every staged commit; the lefthook hook (sensitive-content, gitleaks) passed on all three commits.
- Acceptance greps of the three tasks pass (`IssueChecks`, `expectedNumber`, `Doklad se nepodarilo vystavit`, `UpdateInternalNote`, `RegeneratePdf`, `InvoicePaymentsRelationManager::class`, `InvoiceDocumentsRelationManager::class`, `Audience::AdminOnly` on the history tab).
- Human check of the plan (60-line synchronous render, light and dark mode visual order) was not run by the executor.

## Next

Ready for 10-30 (payment modal, payments tab actions, proforma conversion header action), which adds to `ViewInvoice::getHeaderActions()` and to `InvoicePaymentsRelationManager`.

## Self-Check: PASSED

- FOUND: app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
- FOUND: app/Filament/RelationManagers/InvoiceDocumentsRelationManager.php
- FOUND: app/Filament/RelationManagers/InvoiceHistoryRelationManager.php
- FOUND: tests/Feature/Invoicing/IssueFlowTest.php
- FOUND: tests/Feature/Invoicing/InvoiceDetailTest.php
- FOUND: 27825fb, f0ca8f1, 7a41e58 (ancestors of HEAD)
