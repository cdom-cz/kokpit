---
phase: 10-invoicing
plan: 31
subsystem: invoicing-admin-ui
tags: [invoicing, filament, credit-note, storno, email, relation-manager, resend, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "IssueCreditNote partial() and storno() (10-23), QueueInvoiceEmail defaults() and handle() with the SendInvoiceEmail job (10-24), ViewInvoice and the tabs (10-29), the payment and conversion actions with the send=1 flag (10-30), InvoiceTable with the E-mail column (10-21), lang/cs/invoices.php credit and email sections (10-21)"
provides:
  - "ViewInvoice: the ActionGroup 'Dalsi akce' with 'Dobropis na polozky' (lines, checkbox, original and editable quantity, row amount, live negative total, field errors) and the destructive 'Stornovat' (confirmation with the paid warning), both through IssueCreditNote and ending on the credit note with send=1"
  - "SendInvoiceEmailAction: the e-mail dialog shared by the detail header, the list row and the Odeslani tab row (make(Closure), forListRow(), forLogRow(Closure)), queued through QueueInvoiceEmail with the toast 'E-mail je ve fronte' and the event invoice-email-state-changed"
  - "ViewInvoice opens the dialog by itself when loaded with send=1 (wire:init on the subheading calls openSendDialog)"
  - "InvoiceEmailsRelationManager: the Odeslani tab, Admin only, one row per send with the fixed failure note and 'Odeslat znovu' on failed rows"
  - "InvoiceTable: the row action 'Odeslat znovu' for failed or unsent issued documents"
  - "IssueCreditNote::creditable() and ::preview(): the remaining creditable quantities and the exact credit amounts for the dialog, without a write"
affects: [10-32, 10-33, 10-37]

actuals:
  tokens: 18800
  tasks: 3
  commits: 3
plan_head_before: 14a0073621f1864199544060bdd319fe678f565e
plan_head_after: 048d97680ce78778ef88d02039a5daea1ed0fde9
commits: 3

tech-stack:
  added: []
  patterns:
    - "A Filament Repeater re-keys its rows with its own uuids and dehydrates them to a list, so a row that stands for a domain id carries it in a Hidden field; the action maps a domain error keyed by that id back to the key of the row through Action::getRawData()"
    - "A header action cannot be mounted in mount() (the header actions are cached in the booted hook of the actions trait, after mount): the page renders an empty wire:init node and a public method mounts the action in the next request"
    - "A shared Action builder resolves its document through a Closure(Action) that re-reads it through the scoped query and looks an earlier row up through that document, so one builder serves a page, a list row and a relation manager row and a forged record id reaches nothing else"
    - "A relation manager that must never show a stored failure class selects only the columns it renders"

key-files:
  created:
    - app/Filament/Support/SendInvoiceEmailAction.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEmailsRelationManager.php
    - tests/Feature/Invoicing/CreditNoteActionsTest.php
    - tests/Feature/Invoicing/EmailDialogTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - app/Filament/Resources/InvoiceResource.php
    - app/Filament/Support/InvoiceTable.php
    - app/Domain/Invoicing/Actions/IssueCreditNote.php
    - lang/cs/invoices.php
    - tests/Feature/Invoicing/InvoiceDetailTest.php

key-decisions:
  - "IssueCreditNote gets two read-only public methods, creditable() and preview(), instead of the dialog duplicating the credit arithmetic: the live total and the row amounts are the very figures the issue will compute (including a line converted at issue, ruling R-23-4)"
  - "The e-mail dialog of the issue is opened through wire:init on the subheading and a public openSendDialog() method, because mounting a header action in mount() or in a booted hook finds no cached actions, and doing it with a mounted action at dehydrate breaks Filament's partial rendering of the first response"
  - "SendInvoiceEmailAction::make takes the host's document as a Closure and has three entry points (header, list row, log row) instead of the argument-less make(?InvoiceEmail) named in the plan, so each host hands over its own document and a previous row is looked up through that document"
  - "In the Odeslani tab a row left in sending longer than kokpit.health.unsent_invoice_email_stale_after reads as failed (ruling R-37-1, UI-SPEC Surface G); the list column keeps 'Ve fronte' for every sending row as the plan states, so the two can differ for that one stale row"
  - "The domain refusal for a document that cannot be credited any more (cancelled in another tab) is a persistent danger notification plus a redirect to the document, not a field error"

patterns-established:
  - "Test helpers that live in a Pest file are global functions: credit* and email* helpers were named so that no other test file declares them"

requirements-completed: [IN-04, IN-10, IN-01]

coverage:
  - id: D1
    description: "Dalsi akce on an issued, uncancelled invoice holds Dobropis na polozky and Stornovat (danger); a credit note, a cancelled invoice and a proforma show neither; Stornovat asks 'Stornovat fakturu :number?' with the UI-SPEC body and the paid warning with the paid amount only when payments exist, issues the credit note through IssueCreditNote::storno(), toasts 'Dobropis :number byl vystaven' and redirects to the credit note with send=1; the invoice then reads Stornovano with the credit-note banner and the cancelled-with-payment warning and its time is unbilled again"
    requirement: "IN-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/CreditNoteActionsTest.php#the tracer: the storno from Dalsi akce"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/CreditNoteActionsTest.php#where the actions are not offered"
        status: pass
    human_judgment: false
  - id: D2
    description: "Dobropis na polozky lists the lines with checkbox, original quantity, an editable quantity defaulting to the remaining creditable quantity, the row amount and the live negative total 'Dobropis celkem', with the note 'Zaznamy casu zustanou vyfakturovane.'; 2 of 5 h gives -2 x the unit price, issues a credit note that does not cancel and leaves the time billed; 6 h, a quantity that credit notes issued meanwhile left too little for, an empty selection and a forged line of another invoice are refused under the quantity or the table and issue nothing"
    requirement: "IN-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/CreditNoteActionsTest.php#the partial credit note"
        status: pass
    human_judgment: false
  - id: D3
    description: "Odeslat e-mailem opens 'Odeslat :kind e-mailem' (accusative per kind) pre-filled from defaults() with recipient, subject and text, the work-report checkbox only with linked time (default on, helper 'Vykaz obsahuje odpracovane hodiny bez cen.') and the attachments line that follows the checkbox; Odeslat queues through QueueInvoiceEmail with the info toast 'E-mail je ve fronte'; a client without an invoice e-mail gives an empty recipient with the helper and a field error; cancelling leaves the document unsent; the dialog opens by itself when the detail is loaded with send=1"
    requirement: "IN-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/EmailDialogTest.php#the dialog after the issue"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/EmailDialogTest.php#what the dialog asks for"
        status: pass
    human_judgment: false
  - id: D4
    description: "The Odeslani tab lists one row per send (Vytvoreno, Prijemce, PDF or PDF a vykaz, state badge, the fixed failure note, never the failure class or the body), reads a long-stale sending row as failed, offers Odeslat znovu on failed rows only and opens the dialog with the values of that row so the resend is a new row; the tab is registered after Soubory, Admin only and absent on a draft; the list E-mail column shows sent date, wait (sending included), failure and nothing for a draft, the filter Selhalo finds the failed ones, and the row action Odeslat znovu appears for failed and unsent issued documents only and queues a new e-mail"
    requirement: "IN-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/EmailDialogTest.php#the Odeslani tab"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/EmailDialogTest.php#the E-mail column and Odeslat znovu in the list"
        status: pass
    human_judgment: false
  - id: D5
    description: "A long edited e-mail text keeps the dialog scrollable and the submit reachable (sticky footer, 10-row text area, 4 000 characters)"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/EmailDialogTest.php#keeps a long edited text submittable and refuses one above the limit under the text"
        status: pass
    human_judgment: true
    rationale: "That the dialog scrolls and the footer stays in reach is a visual behaviour (UI-SPEC backstop N9 long text); the test proves a 4 000-character text submits and 5 001 is refused, not how the modal renders"
  - id: D6
    description: "The modal layout of the credit dialog (checkbox, quantity and amount columns in the table repeater) and the liveness of the total read well in light and dark mode"
    verification: []
    human_judgment: true
    rationale: "Layout and perceived liveness are judged by eye; the tests assert the texts and the state"

duration: 28min
completed: 2026-10-17
---

# Phase 10 Plan 31: Storno, partial credit note and the e-mail dialog with its log Summary

**The Admin corrects an issued invoice from its detail (the destructive storno that returns the time, or a credit note of chosen quantities with the exact live total), sends any document by e-mail from a dialog that opens by itself after the issue, and sees every send, with a resend of the failed ones, in the Odeslani tab and the list.**

## Performance

- **Duration:** 28 min (about 11 of it the final full-suite run)
- **Started:** 2026-10-17T20:42:00Z
- **Completed:** 2026-10-17T21:10:00Z
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files modified:** 10 (4 created, 6 modified)

## Accomplishments

- `ViewInvoice` gets the `ActionGroup` "Dalsi akce". **Stornovat** is the destructive confirmation of UI-SPEC E6: heading with the number, the body, the warning callout with the paid amount only when payments exist, submit "Vystavit dobropis a stornovat". **Dobropis na polozky** is a table repeater of the lines: checkbox, description, original quantity, editable quantity (default what is still creditable), the amount of the row, and under it "Dobropis celkem" with the negative total. A form rule shows the quantity error immediately and the domain's own refusals (a quantity taken by another credit note meanwhile, an empty selection, a total above the invoice) are mapped under the row or the table. Both end on the credit note with `send=1`.
- `IssueCreditNote` gains `creditable()` and `preview()`: the dialog reads the remaining quantities and the exact amounts (a converted line through its frozen conversion, ruling R-23-4) from the domain, so the preview and the issued credit note cannot disagree.
- `SendInvoiceEmailAction` builds the "Odeslat :kind e-mailem" dialog once for the detail header, the list row and the Odeslani row. Values come from `QueueInvoiceEmail::defaults()` or from the earlier row; the work-report checkbox exists only for a document with linked time and drives the attachments line; submit queues through `QueueInvoiceEmail::handle()`, dispatches `invoice-email-state-changed` and toasts "E-mail je ve fronte". Domain errors are mapped under recipient, subject and text.
- The detail opens the dialog by itself after the issue, the storno, a partial credit note and the conversion: the `send=1` flag makes the subheading render a `wire:init` node that calls the public `openSendDialog()` (see Decisions).
- `InvoiceEmailsRelationManager` is the Odeslani tab (Admin only, issued documents, after Soubory): created, recipient, attachments, state badge, and the fixed note for a failed row. It selects only the columns it renders, so neither the failure class nor the body can reach the page. A row stuck in `sending` past the System page threshold reads as failed. `Odeslat znovu` is on failed rows only.
- `InvoiceTable` gets the row action "Odeslat znovu" for failed or unsent issued documents; the E-mail column and the Selhalo filter already did what the plan asks.
- 31 new tests (10 in `CreditNoteActionsTest`, 21 in `EmailDialogTest`) cover the tracer, the partial credit, every refusal, the forged line, the dialog and its auto-open, the tab, the list column, filter and row action, and the Partner refusals.

## Task Commits

1. **Task 1 (tracer): storno and partial credit note from Dalsi akce** - `1b411bd` (feat)
2. **Task 2: send a document by e-mail from the detail, opened automatically after the issue** - `d3cea6c` (feat)
3. **Task 3: the Odeslani tab, the resend action on the list and the failed row** - `048d976` (feat)

**Plan metadata:** the docs commit that carries this file.

## Tracer gate

After Task 1 the tracer `<verify>` (CreditNoteActionsTest, Pint, PHPStan) passed, plus `tests/Feature/Invoicing` and `tests/Arch` (549 tests), before the expansion tasks started.

## Decisions Made

See `key-decisions` above. The two that change visible behaviour beyond the plan text: the stale `sending` row reads as failed in the tab only, and a document that cannot be credited any more is a notification plus a redirect.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The e-mail dialog cannot be mounted from `mount()`**
- **Found during:** Task 2
- **Issue:** the plan says "mount opens the dialog when `send=1`". Header actions are cached in the `booted` hook of Filament's actions trait, after `mount()`, so a `mountAction()` there resolves nothing; mounting it later in the same request breaks the partial rendering of the first response ("missing root tag").
- **Fix:** `mount()` only records that the flag was present; `getSubheading()` renders `<span wire:init="openSendDialog">` and the public `openSendDialog()` mounts the action in the follow-up request. The result for the user is the same (the dialog is open as soon as the page is loaded).
- **Files modified:** app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
- **Commit:** d3cea6c

**2. [Rule 1 - Bug] Test of the tab order of plan 10-29**
- **Found during:** Task 3 suite run
- **Issue:** `InvoiceDetailTest` pinned the four tabs of 10-29; the plan registers the fifth.
- **Fix:** the expected list now holds `InvoiceEmailsRelationManager` after the files tab.
- **Files modified:** tests/Feature/Invoicing/InvoiceDetailTest.php
- **Commit:** 048d976

**3. [Plan scope] `IssueCreditNote` and the action builder signatures**
- `IssueCreditNote` was not in the plan's file list; it gained the read-only `creditable()` and `preview()` (additive, no write path changes). `SendInvoiceEmailAction::make()` takes the host's document as a Closure and has `forListRow()` and `forLogRow()` entry points instead of `make(?InvoiceEmail)`. No behaviour differs from the contract.

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug), 1 scope note. **Impact:** none on behaviour.

## Issues Encountered

- A Filament repeater replaces the keys of the state with its own uuids and returns a list when dehydrated, so the first test (which keyed rows by item id) silently issued a credit note for the default row. The rows now carry the item id in a Hidden field and the tests address the real row keys.
- In Livewire tests a `mount()`-time `request()` does not see `Livewire::withQueryParams()` through `request()->merge()`; `withQueryParams` is the right way and the auto-open test calls `openSendDialog` the way the browser's `wire:init` does.
- The Filament table resolves a forged record id of another document's log row through the tab's own relationship, so it mounts nothing; the previous-row lookup inside the builder is a second layer.
- The full suite: 5868 passed, 3 skipped (pre-existing), 0 failed in about 10.5 minutes. The known flaky `PartnerDashboardTest.php:403` did not fail.

## Known Stubs

None.

## Threat Flags

None. T-10-77 is mitigated and tested: the dialog sends only line ids and quantities, `IssueCreditNote` reads the lines of the locked original and judges every quantity again, and the forged-line test (an id of another invoice's line in the hidden field) issues nothing; both write closures call `abort_unless(InvoiceResource::canAccess(), 403)`. The Odeslani tab never selects or renders the failure class, the body or an exception text ("No exception or provider text in the e-mail log, toast or tab"). Nothing was installed (T-10-SC).

## User Setup Required

None - no external service configuration required.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite) after the last code commit: 5868 passed, 3 skipped, 0 failed.
- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Isolation tests/Arch`: 896 passed (after the tab-order test fix; the fix itself re-ran green).
- Pint and PHPStan clean; `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) passed on every commit.
- Acceptance greps pass: `IssueCreditNote` and `ActionGroup` in `ViewInvoice.php`, `QueueInvoiceEmail` in `SendInvoiceEmailAction.php`, `send` in `ViewInvoice.php`, `InvoiceEmailsRelationManager::class` in `InvoiceResource.php`, `Odeslat znovu` in `lang/cs/invoices.php`, `Audience::AdminOnly` in the relation manager.
- The human check of a 4 000-character text in the real dialog (D5) and the visual check of the credit dialog (D6) were not run by the executor.

## Next Phase Readiness

Ready for the later plans of the phase. The System page link target (list filtered to "Selhalo") already finds the failed documents; the stale `sending` row counted as failed by the System page still shows "Ve fronte" in the list column, which a later plan may align by selecting `updated_at` in the list's e-mail eager load.

## Self-Check: PASSED

- FOUND: app/Filament/Support/SendInvoiceEmailAction.php
- FOUND: app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEmailsRelationManager.php
- FOUND: tests/Feature/Invoicing/CreditNoteActionsTest.php
- FOUND: tests/Feature/Invoicing/EmailDialogTest.php
- FOUND: 1b411bd, d3cea6c, 048d976 (ancestors of HEAD)
