---
phase: 10-invoicing
plan: 26
subsystem: invoicing-partner-view
tags: [invoicing, partner, filament, read-only, isolation, canary]
status: complete

requires:
  - phase: 10-invoicing
    provides: "Partner-scoped Invoice query and InvoicePolicy (plan 10-05), ReceivablesReader and InvoiceStatus::partnerLabel() (plans 10-19, 10-33), the Admin document list and lang section partner (plan 10-21), credit notes and storno (plan 10-23), proforma conversion (plan 10-22), the signed invoice PDF route and InvoicePdfUrl (plan 10-38)"
provides:
  - "PartnerInvoiceResource (slug my-invoices, navigation sort 30, PartnerAllowed plus a Partner with a client): read-only list and detail of the issued invoices, proformas and credit notes of the own client, no create, edit, delete, bulk, export, search or relation"
  - "InvoiceColumns: partnerColumns(), partnerFilters(), partnerEntries(), partnerState() and partnerColor(); the only builders of the Partner surface"
  - "Stav and K uhrade of a Partner derived by ReceivablesReader run as system over the ids of the page of the Partner-scoped query (R-26-2); the Stav filter reuses the conditions of the Admin list tabs"
  - "Stahnout PDF as a list row action and the detail header action, building InvoicePdfUrl::download() at click time; the header slot for the Phase 11 button is free"
  - "Tests\\Support\\InvoiceScenario::forClients(): per client a draft, an invoice from time with a partial payment, an unpaid invoice, a paid proforma, a cancelled invoice with its storno credit note and a EUR invoice, with canaries for every secret"
  - "InvoiceLeakTest: the Partner sweep (list, every detail, overdue pages, other Partner pages, Admin routes, 404s, bell, search) with Admin positives"
affects: [10-32]

actuals:
  tokens: 19900
  tasks: 3
  commits: 3
plan_head_before: f5d4e3fd7d0c5534b11902893d4721b70bc68983
plan_head_after: f570fa321e12a2b80ecbd52b24e03afda1eefed5
commits: 3

coverage:
  - deliverable: "A Partner opens Faktury and sees the own issued invoice with its derived state and the amount still due, opens the detail with its sections and gets the signed PDF link from the header and row action"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#the tracer"
        status: pass
    human_judgment: false
  - deliverable: "Every document state reads right to the client (Cekame na uhradu, Castecne zaplaceno, Po splatnosti with the danger date and the days late, Zaplaceno, Stornovano, Dobropis), K uhrade is a dash where nothing is owed, the kind and state filters work, and the empty state shows"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#every document state"
        status: pass
    human_judgment: false
  - deliverable: "A cancelled invoice names its credit note and a converted proforma its invoice (issued documents only); the overdue callout shows on an overdue document only; a credit note has no payment block"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#names the credit note on a cancelled invoice and the invoice on a converted proforma"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#shows the overdue callout on an overdue document only, and no payment block on a credit note"
        status: pass
    human_judgment: false
  - deliverable: "A draft, another client's document and an unknown or malformed id are 404 (never 403) and name nothing; the Admin gets 403 on the Partner resource and keeps one Faktury entry; a Partner gets 403 on every Admin invoice URL"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/PartnerInvoiceResourceTest.php#answers 404 to the detail of a draft, of another client's document and of an unknown or malformed id"
        status: pass
      - kind: test
        ref: "tests/Isolation/RouteWalkTest.php#it shows Partner A nothing of client B on any panel route"
        status: pass
    human_judgment: false
  - deliverable: "No Partner surface carries a draft, an internal note, a payment, linked time or a duration, a rate, CNB data, the e-mail log, an Admin action or another client's data, in the HTML or the Livewire snapshots; the bell and the global search stay empty"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceLeakTest.php"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Filament/Partner/Resources/PartnerInvoiceResource.php
    - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ListPartnerInvoices.php
    - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php
    - app/Filament/Support/InvoiceColumns.php
    - tests/Feature/Invoicing/PartnerInvoiceResourceTest.php
    - tests/Support/InvoiceScenario.php
    - tests/Isolation/InvoiceLeakTest.php
  modified:
    - lang/cs/invoices.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "R-26-2 applied: the Partner reads derived figures only; ReceivablesReader runs as system in one private method of InvoiceColumns, over the invoices of the page the Partner-scoped query returned, so no payment row is ever read for a Partner"
  - "R-26-3 applied: Souvisejici doklady names the storno credit note of a cancelled invoice and the invoice made from a proforma through the Partner-scoped relations, so a draft is never named"
  - "The Stav filter maps the Partner states onto the Admin list tab conditions (Unpaid and Partially paid split by the paid sum, Paid includes converted and waiting proformas, credit note by kind), so a filter and the state of the rows cannot disagree"
  - "A paid proforma, converted or waiting for its invoice, reads Zaplaceno with the success colour for the Partner"
  - "The number links to the detail, the table has no searchable column and no bulk action; the badges of the detail header are rendered with Blade::render over escaped values"

patterns-established:
  - "A Partner surface that needs an Admin-only derived figure reads it through a system run over the ids of the Partner-scoped page only"
  - "The leak test matches the plain HTML and the decoded Livewire snapshots (unescaped unicode) in all spellings of a string, so an escape cannot hide it"

requirements-completed: [IN-09]

duration: "21 min"
completed: 2026-10-17
---

# Phase 10 Plan 26: Partner invoice view Summary

**A read-only Faktury resource for the Partner (list, detail, signed PDF) built only from Partner-safe builders, with the states derived as system over the scoped page and a canary leak test that sweeps every Partner surface against the UI-SPEC "Never on a Partner surface" list.**

## Accomplishments

- `PartnerInvoiceResource` with `ListPartnerInvoices` and `ViewPartnerInvoice`: columns Cislo, Druh, Vystaveno, Splatnost, Celkem, K uhrade, Stav, filters Druh and Stav, default order issue date descending, 25 per page, the empty state, the row and header action Stahnout PDF. The detail has the overdue callout, Doklad, Dodavatel and Odberatel (snapshots), Platebni udaje (not for a credit note), Polozky, Souhrn, Poznamka and Souvisejici doklady.
- `InvoiceColumns` derives the states of a page once and keeps the Admin-only reader behind a system run over scoped ids.
- `InvoiceScenario` and `InvoiceLeakTest` (13 tests) plus 16 resource tests; the route walk learned `my-invoices`.

## Task Commits

1. Task 1 (tracer): `abed458` - resource, pages, `InvoiceColumns`, lang and the tracer tests; the tracer `<verify>` re-run green before expansion
2. Task 2: `f861ebb` - `InvoiceScenario` and the state, filter, related-row, 404 and access tests
3. Task 3: `f570fa3` - `InvoiceLeakTest` and the `my-invoices` entry of the route walk

## Deviations from Plan

**1. [Rule 3 - Blocking] The route walk had to learn the new resource**
- **Found during:** Task 3 (Isolation suite)
- **Issue:** `RouteWalkTest` fails by design on a resource it does not know, three tests failed after `my-invoices` was registered.
- **Fix:** One entry `'my-invoices' => ['partner' => true, ...]` in `walkedResourceMap()`; the walk then proves client A is served and client B refused.
- **Files modified:** tests/Isolation/RouteWalkTest.php
- **Commit:** f570fa3

**2. [Rule 1 - Bug] The overdue callout copy ignored the Czech plural rule**
- **Found during:** Task 1
- **Issue:** `invoices.partner.detail.overdue` (plan 10-21) read "o :count dni" for every count, while UI-SPEC requires "o 1 den, o 2 dny, o 5 dni".
- **Fix:** The key is now a three-form `trans_choice` string; added the IČO, DIČ and IBAN labels of the party and bank blocks to the same section.
- **Files modified:** lang/cs/invoices.php
- **Commit:** abed458

**3. [Scope] Admin positives of the leak test are partly data-level**
- **Found during:** Task 3
- **Issue:** The Admin screens that show the internal note and the payments of an issued document (payments tab, note modal) belong to plan 10-29, which runs in the same wave and is not built yet, so the Admin page cannot yet contain those canaries.
- **Fix:** The Admin positives are asserted where a surface exists today (the draft editor shows the draft note, the linked-time relation manager shows the time description, the list shows the draft tab and every document) and at data level for the invoice internal note, the payment note and the CNB figures. Plan 10-32 extends `InvoiceLeakTest` with the page-level positives once 10-29 lands.
- **Files modified:** tests/Isolation/InvoiceLeakTest.php
- **Commit:** f570fa3

**Total deviations:** 3 (1 blocking test registry entry, 1 copy bug, 1 documented scope limit). **Impact:** none on the delivered behaviour.

## Known Stubs

None.

## Known Limitations

- Stahnout PDF is always offered on an issued document: a Partner cannot know whether the stored PDF exists (the link lives in an Admin-only row), so a missing PDF answers 404 from the route of plan 10-38 on click.

## Threat Flags

None. T-10-69 and T-10-91 are mitigated and tested: only Partner-safe builders feed the surface, the derived figures come from a system run over scoped ids, the canary sweep covers the UI-SPEC list, and foreign, draft, unknown and malformed ids are 404.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing/PartnerInvoiceResourceTest.php tests/Arch/PanelRegistryTest.php`: green
- `ddev exec vendor/bin/pest tests/Isolation`: 326 passed
- Full suite `ddev exec vendor/bin/pest --compact`: 5735 passed, 3 skipped
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on all three commits; acceptance greps pass (`Audience::PartnerAllowed`, `partnerClientId() !== null`, `InvoicePdfUrl::download`, `function forClients`, `Kategorie prijmu`, `Zalohy k vyfakturovani`)

## Next Phase Readiness

Plan 10-32 builds on `InvoiceScenario` and `InvoiceLeakTest`. The Phase 11 "Zaplatit kartou" button has a free slot in front of the download action in `ViewPartnerInvoice::getHeaderActions()`.

## Self-Check: PASSED

- FOUND: app/Filament/Partner/Resources/PartnerInvoiceResource.php, app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ListPartnerInvoices.php, app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php, app/Filament/Support/InvoiceColumns.php, tests/Feature/Invoicing/PartnerInvoiceResourceTest.php, tests/Support/InvoiceScenario.php, tests/Isolation/InvoiceLeakTest.php
- FOUND: abed458, f861ebb, f570fa3
