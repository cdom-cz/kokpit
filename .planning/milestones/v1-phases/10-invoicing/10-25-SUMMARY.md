---
phase: 10-invoicing
plan: 25
subsystem: invoicing-admin-ui
tags: [invoicing, filament, draft-editor, repeater, relation-manager, livewire, cnb-preview, fixed-amount, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "UpdateDraft, DeleteDraft, AddFixedAmount, AddEntriesToDraft, ReleaseEntries, DraftTotals, DraftPreview (plans 10-11 to 10-17); InvoiceResource, InvoiceTable, CreateDraftAction and lang/cs/invoices.php (plan 10-21)"
provides:
  - "EditInvoice: the draft editor at /admin/invoices/{id}/edit (sections Doklad, Položky, Souhrn), a thin layer that hands one plain array to UpdateDraft"
  - "DraftEditorPreview: the server-side figures of the editor (line amounts, origin and conversion previews, summary and CZK preview) that mirror the rules of UpdateDraft"
  - "InvoiceEntriesRelationManager: 'Záznamy času' on a draft (Přidat nový čas, Odpojit od faktury), 'Navázaný čas' read only on an issued document"
  - "resources/views/filament/invoices/draft-summary.blade.php: the Souhrn block"
  - "Draft rows of the list, and a new draft, open the editor; the edit address of an issued document redirects to its page"
affects: [10-29, 10-30, 10-36, 10-38, 10-40]

actuals:
  tokens: 30000
  tasks: 3
  commits: 5
plan_head_before: be4c9dfd9e8c28ff9e45f397c78c2f1641c78a57
plan_head_after: 3fdd17da2215e24c77348fd3c27af0e0d3c03fcf
commits: 5

tech-stack:
  added: []
  patterns:
    - "A table Repeater over plain array state (never Repeater::relationship()): every cell that shows a figure is a Text component whose closure asks a per-request server-side reader keyed by the stored line id"
    - "A change that needs a confirmation is reverted on the field at once and re-applied by a page action only after the confirmation (the Select cannot confirm by itself)"
    - "Lines stored outside the form (fixed amount, linked time) are merged into the form state by id, so unsaved edits of other rows survive"
    - "Item errors of the domain (items.<n>.<field>) are re-keyed to the uuid of the repeater row, then prefixed by the form state path"

key-files:
  created:
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEntriesRelationManager.php
    - app/Filament/Support/DraftEditorPreview.php
    - resources/views/filament/invoices/draft-summary.blade.php
    - tests/Feature/Invoicing/DraftEditorTest.php
  modified:
    - app/Filament/Resources/InvoiceResource.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - app/Filament/Support/InvoiceTable.php
    - app/Filament/Support/CreateDraftAction.php
    - lang/cs/invoices.php

key-decisions:
  - "R-25-1 holds: every line amount and the summary are computed on the server (DraftEditorPreview, debounced inputs); the preview mirrors UpdateDraft's rule for a generated line and a typed price, and the save stays the Action's own computation"
  - "R-25-2 holds: one relation manager serves both states; it is registered on the resource, so an issued document's page now shows the read-only 'Navázaný čas' tab"
  - "The page Admin check is a boot() hook, so it runs on every Livewire request and not only on mount; every helper of the page is protected so the browser cannot call it"
  - "A date the Admin did not type is stored as null: the editor shows the derived date (today, issue date plus terms) and the hidden default beside it decides on save whether the value was typed"
  - "'Přidat nový čas' under the table of lines dispatches an event that opens the dialog of the tab, so there is one dialog and one code path"

requirements-completed: [IN-02, IN-07, IN-08, IN-10]

coverage:
  - id: D1
    description: "The Admin opens a draft from the list (or right after Nová faktura), edits header and lines, and saves everything through UpdateDraft; every figure on screen is computed on the server and a forged total, id or position changes nothing"
    requirement: "IN-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the tracer: open a draft, change a line, save, and see the server figures"
        status: pass
    human_judgment: false
  - id: D2
    description: "Time lines carry the Z času badge and the exact H:MM, converted lines the CNB preview rate and the conversion badge, a typed price drops the badge, and the summary of a foreign-currency draft shows the CZK preview with rate and date and no VAT row"
    requirement: "IN-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#line origins and previews"
        status: pass
    human_judgment: false
  - id: D3
    description: "Non-VAT-payer mode: no VAT line, no subtotal row, no taxable-supply field"
    requirement: "IN-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#shows no CZK preview and no VAT row for a CZK draft"
        status: pass
    human_judgment: false
  - id: D4
    description: "Changing the currency asks first when prices were typed by hand; Přidat pevnou částku (with optional time) adds a paušál line; the item cap hides the add buttons; the empty row; Posunout nahoru and dolů; Smazat koncept frees the time and returns to the list"
    requirement: "IN-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the currency of a draft"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#fixed amounts"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the table of items"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#deleting a draft"
        status: pass
    human_judgment: false
  - id: D5
    description: "The tab of linked time: columns with Sazba and Částka from the Admin-only readers and the H:MM footer, Přidat nový čas appends lines only, Odpojit od faktury returns the time and leaves the lines, read only on an issued document, 403 for a Partner, a forged entry id changes nothing"
    requirement: "IN-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/DraftEditorTest.php#the tab of linked time"
        status: pass
    human_judgment: false
  - id: D6
    description: "At 375 px the items table scrolls sideways inside its wrapper and the page never scrolls sideways; the delete buttons keep 24 x 24 px; the summary stays readable"
    verification: []
    human_judgment: true
    rationale: "Responsive layout backstop of the plan (UI-SPEC N3 overflow); no test measures rendered geometry and the human check was not run by the executor"

duration: 25min
completed: 2026-10-17
---

# Phase 10 Plan 25: The draft editor Summary

**A Filament draft editor over the domain Actions: a table repeater of lines whose amounts, origin badges, CNB preview and CZK summary are computed on the server, fixed amounts and linked time added without losing unsaved edits, and a linked-time tab that unlinks without touching the lines.**

## Performance

- **Duration:** 25 min
- **Started:** 2026-10-17T15:54:52Z
- **Tasks:** 3 (tracer, two TDD tasks)
- **Files:** 5 created, 5 modified

## Accomplishments

- **Tracer (commit 2a78786):** a draft row of the list and a fresh draft open `/admin/invoices/{id}/edit`; changing a quantity recomputes the line and the summary on the server (R-25-1); "Uložit koncept" goes through UpdateDraft; a forged total, a position or the id of a line of another document changes nothing; the Action's `items.<n>.<field>` errors land under the cell of their row; the edit address of an issued document redirects to its page; a Partner gets 403 on every request.
- **Origins and previews:** "Z času" badge with the exact H:MM, the CNB preview rate line and the "Přepočet při vystavení" badge on converted lines, the badge dropping when a price is typed, the danger message with the Settings link when the currency has no bank account, the zero-price confirmation on the line, and "Náhled v Kč" with rate and date for a foreign draft, with no VAT row and no taxable-supply field.
- **Currency, fixed amounts, cap, delete:** changing the currency asks "Změnit měnu konceptu?" when prices were typed by hand and applies only after the confirmation; "Přidat pevnou částku" (project, description, amount with the price and billed helper, optional project time with count and H:MM) goes through AddFixedAmount and joins the form without discarding unsaved edits; the item cap hides the add buttons and shows the note; the empty row text; keyboard move buttons and named delete buttons; "Smazat koncept" through DeleteDraft with the destructive copy and the release toast.
- **Linked-time tab:** Datum, Projekt, Úkol, Popis, Trvání, Sazba, Částka with a H:MM footer, 25 per page; "Přidat nový čas" lists only eligible entries of the client and appends lines only; "Odpojit od faktury" returns the time and leaves the lines; read only "Navázaný čas" on an issued document.

## Task Commits

1. Task 1 (tracer): `2a78786` feat(10-25) open a draft in an editor with server-computed lines and totals
2. Task 2 RED: `1986f3f` test(10-25) specify line origins, currency change, fixed amounts, cap and delete
   Task 2 GREEN: `5fd7707` feat(10-25) line origins, currency confirmation, fixed amounts, cap and delete
3. Task 3 RED: `c28c8f1` test(10-25) specify the linked-time tab
   Task 3 GREEN: `3fdd17d` feat(10-25) linked-time tab with add and unlink for a draft, read only on an issued document

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Files beyond `files_modified`**
- **Found during:** Tasks 1 to 3
- **Issue:** the editor needs a place for the server-side figures, screen copy that 10-21 did not carry, and two helpers of Surface B.
- **Fix:** new `app/Filament/Support/DraftEditorPreview.php`; keys added to `lang/cs/invoices.php` (badge, settings link, edit row action, zero-price confirmation, currency-change buttons, fixed-amount toast, a Czech plural of the project-time helper); `CreateDraftAction` now redirects a new draft to the editor and exposes `period()` and `projectOptions()` for the add-time dialog instead of duplicating them; a comment in `ViewInvoice` and `InvoiceResource`.
- **Files modified:** as listed
- **Commits:** 2a78786, 5fd7707, 3fdd17d

**2. [Rule 1 - Bug] A Pint run with an explicit `lang` path reformatted unrelated files**
- **Found during:** Task 1 (before the commit)
- **Issue:** `pint ... lang` ignores `notPath`; six unrelated files (`lang/cs/actions.php`, `auth.php`, `http-statuses.php`, `pagination.php`, `passwords.php`, `validation.php`) were modified.
- **Fix:** restored with `git checkout -- <file>` for exactly those six files before staging; nothing of them was committed. Pint was never run with a `lang` path again.
- **Verification:** `git status` showed only intended files; `git diff --cached --stat` lists none of them.

**3. [Rule 2 - Missing critical] Admin check on every request**
- **Issue:** a mount-time check alone would leave `/livewire/update` open for a page whose session changed.
- **Fix:** `EditInvoice::boot()` aborts with 403 on every request; the relation manager carries the access-rule trait; all page helpers are protected.

**Total deviations:** 3 auto-fixed (1 blocking, 1 bug, 1 missing critical). **Impact:** none on scope; the first only widens the touched files, the second was undone before any commit.

### Plan interpretation worth knowing

- The currency Select cannot ask for a confirmation by itself, so a change that needs one is reverted on the field at once and re-applied by the page action `changeCurrency` only after the confirmation (the tests assert both the kept and the changed value). The same confirmation is not asked when no price was typed by hand.
- A fixed amount is stored by the Action in the stored currency of the draft; the dialog suffix shows the currency in the form. The amount is a manual price, so it is never converted (R-14-3).
- The toast of the add-time dialog is the literal copy of the plan ("Připojeno :count záznamů, přidáno :items položek"); it is not pluralised.

## Known Stubs

None. There is no placeholder data; the "Vystavit" header action is deliberately absent (plan 10-29 adds it).

## Issues Encountered

- The human check of the plan (375 px and desktop width with 15 lines) was not run: no browser was driven. It is recorded as D6 with `human_judgment: true`.
- Tests of the table repeater address actions as `TestAction::make('moveDown')->schemaComponent('items', schema: 'form')`; a hidden action is not resolvable by the test helper, so the cap test asserts the markup instead.

## Threat Flags

None. No new endpoint or trust boundary: the two Livewire listeners (`draft-lines-changed`, `draft-add-time`) sit behind the per-request Admin check and only re-read data of the draft.

## Next Phase Readiness

Ready for 10-29 ("Vystavit" saves the form first, then the pre-issue check). The edit page already exposes `getHeaderActions()` with "Smazat koncept" only; the issued page already shows the read-only "Navázaný čas" tab.

## Self-Check: PASSED

- `EditInvoice.php`, `InvoiceEntriesRelationManager.php`, `DraftEditorPreview.php`, `draft-summary.blade.php`, `DraftEditorTest.php` exist on disk.
- Commits 2a78786, 1986f3f, 5fd7707, c28c8f1, 3fdd17d are on the branch (`git rev-list --count be4c9df..HEAD` = 5 before this summary).
- Acceptance greps of the three tasks pass (`UpdateDraft`, `Repeater::make`, `'edit' =>`, `AddFixedAmount`, `DeleteDraft`, `DraftPreview`, `ReleaseEntries`, `AddEntriesToDraft`, `Audience::AdminOnly`).
- Plan verification: `pest tests/Feature/Invoicing tests/Arch tests/Isolation` green; full suite `pest --compact`: 5619 passed, 3 skipped; `pint --test` and `phpstan analyse` clean; `scripts/check-sensitive.sh` clean on every commit.
