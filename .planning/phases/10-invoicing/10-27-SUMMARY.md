---
phase: 10-invoicing
plan: 27
subsystem: client-detail-panels
tags: [invoicing, filament, client, stats-widget, relation-manager, receivables, unbilled-time, ui-spec]
status: complete

requires:
  - phase: 10-invoicing
    provides: "InvoiceTable and CreateDraftAction (plan 10-21), ReceivablesReader summary/tabCounts/constrain (plans 10-19, 10-21), the reserved flag of ReportEntry (plan 10-34), lang/cs/invoices.php client_panel copy (plan 10-21)"
provides:
  - "ClientOverviewStats: the stats row Přehled klienta on the Admin client page (Nevyfakturováno with its draft share, K úhradě, Po splatnosti)"
  - "ClientProjectsRelationManager (tab Projekty) and ClientInvoicesRelationManager (tab Faktury) with the locked Nová faktura header action, the K úhradě footer per currency and the overdue badge"
  - "ReceivablesReader::owedByCurrency() and owedByCurrencyFrom(): one remaining amount per document currency"
  - "TimeTotals::projectColumns(): unbilled seconds per project as a correlated sub-select"
  - "Client::invoices(), InvoiceResource::withListRelations(), a Stav filter in the client variant of InvoiceTable"
affects: [10-28, 10-29, 10-30, 10-36, 10-38, 10-40]

actuals:
  tokens: 15600
  tasks: 3
  commits: 3
plan_head_before: d36e30b90f21e9a8857460ea6412299c5bfb090e
plan_head_after: c0c7c7a2a446f7e649c62364c635224277adb513
commits: 3

tech-stack:
  added: []
  patterns:
    - "The client stats price the entries through TimeReportReader and EntryAmounts exactly like the dashboard card and the report; receivables come from ReceivablesReader only"
    - "Amounts of different currencies are kept as one figure per currency (client currency first on the stats row, alphabetical in the table footer); only the CZK figure is a sum"
    - "A document list is built once (InvoiceTable) and reused on the client tab with withClientColumn: false"

key-files:
  created:
    - app/Filament/Resources/ClientResource/Widgets/ClientOverviewStats.php
    - app/Filament/Resources/ClientResource/RelationManagers/ClientProjectsRelationManager.php
    - app/Filament/Resources/ClientResource/RelationManagers/ClientInvoicesRelationManager.php
    - tests/Feature/Clients/ClientInvoicePanelsTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Invoicing/Queries/ReceivablesReader.php
    - app/Domain/TimeTracking/Queries/TimeTotals.php
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/Pages/ViewClient.php
    - app/Filament/Resources/InvoiceResource.php
    - app/Filament/Support/InvoiceTable.php
    - lang/cs/invoices.php

key-decisions:
  - "Nevyfakturováno counts reserved time as unbilled and names its share ('z toho v konceptech: H:MM'), over all time from the first entry of the client (R-27-1)"
  - "K úhradě lists one amount per document currency, client currency first, with the CZK total from ReceivablesReader::summary(); the count is unpaid plus overdue (R-27-2)"
  - "The client variant of InvoiceTable has exactly the filters Druh and Stav; Stav reuses the list-tab conditions through ReceivablesReader::constrain(), so a filter and a tab cannot disagree"
  - "The Faktury tab has no empty-state action: the header action Nová faktura (client locked, forged client id ignored) is the single place to create a document there"

requirements-completed: [CL-03]

coverage:
  - id: D1
    description: "The client page shows the stats row Přehled klienta: Nevyfakturováno with H:MM, amounts, the draft share and the report link, K úhradě with the overdue part, Po splatnosti in danger above zero and 'Nic po splatnosti' at zero"
    requirement: "CL-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#it shows the unbilled time with its draft share, what the client owes and what is overdue"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#it marks the overdue count in danger above zero"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#it shows nothing owed and nothing overdue for a client without documents"
        status: pass
    human_judgment: false
  - id: D2
    description: "The relation tabs come in the order Projekty, Faktury, Soubory, Kontakty, Pozvánky, Účty, Historie změn; Projekty shows unbilled H:MM, the Pevná cena badge and archived projects, 10 per page, with its empty state"
    requirement: "CL-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#the relation tabs"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#the Projekty tab"
        status: pass
    human_judgment: false
  - id: D3
    description: "Faktury lists only the client's documents without the Klient column, filters Druh and Stav, the K úhradě footer per currency, the overdue badge in danger, the empty state, and Nová faktura with the client locked (a forged client id is ignored)"
    requirement: "CL-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#the Faktury tab"
        status: pass
    human_judgment: false
  - id: D4
    description: "The client figures equal the time report, the dashboard card and ReceivablesReader::summary(); a EUR client with a CZK document shows two amounts, never a sum"
    requirement: "CL-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#the figures agree with the report and the dashboard"
        status: pass
    human_judgment: false
  - id: D5
    description: "A Partner (of the client or of another client) is refused on the page, the widget and both relation managers"
    requirement: "CL-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientInvoicePanelsTest.php#the closed doors"
        status: pass
    human_judgment: false
  - id: D6
    description: "Visual reading of the stats row (multi-line descriptions with the report link, the danger colour of the overdue stat) and of the footer on narrow screens"
    verification: []
    human_judgment: true
    rationale: "Layout and colour of the Filament stat card with an HTML description and the table footer cannot be asserted by markup tests"

duration: 55min
completed: 2026-10-17
---

# Phase 10 Plan 27: Client detail panels Summary

**The client page opens with unbilled time (draft share named), what the client owes per currency and what is overdue, and carries Projekty and Faktury tabs with a client-locked Nová faktura, all from the shared readers.**

## Performance

- **Duration:** about 55 min (including a 7.9 min full suite run)
- **Completed:** 2026-10-17T16:42Z
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files modified:** 12 (4 created, 8 modified)

## Accomplishments

- `ClientOverviewStats` (Admin only, polling 60 s, refresh on `time-entry-saved` and `time-entry-deleted`) shows Nevyfakturováno as H:MM with the amount per currency and in Kč, "z toho v konceptech", the fixed-price footnote and the link Zobrazit v reportu; K úhradě with count, per-currency amounts, the CZK total and "z toho po splatnosti"; Po splatnosti in danger above zero, "Nic po splatnosti" at zero.
- The tabs are Projekty, Faktury, Soubory, Kontakty, Pozvánky, Účty, Historie změn. Projekty lists archived projects (marked), unbilled time per project in one query, the Pevná cena badge, 10 per page. Faktury reuses `InvoiceTable` without the Klient column, has the filters Druh and Stav, the K úhradě footer per currency, an overdue badge in danger, and the header action Nová faktura with the client locked.
- Figures are proved equal to `TimeReport` (all time), the dashboard `UnbilledPerClient` row and `ReceivablesReader::summary()`; a EUR client with a CZK document never gets a summed amount.

## Task Commits

1. **Task 1: Tracer - the stats row on the client page** - `8ba7942` (feat)
2. **Task 2: Projects and documents tabs with a locked Nová faktura** - `dfb282f` (feat)
3. **Task 3: Agreement with the report and dashboard, Partner refusal** - `c0c7c7a` (test)

**Plan metadata:** the docs commit that follows this summary.

## Verification

- `ddev exec vendor/bin/pest --compact` (full suite): 5644 passed, 3 skipped, 0 failed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.
- The tracer's `<verify>` was re-run after the commit (49 passed) before the expansion tasks.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Files outside `files_modified` were touched**
- **Found during:** Tasks 1 and 2
- **Issue:** "K úhradě" per currency and the Stav filter need reader and table support the plan did not list: a per-currency reader method, a per-project unbilled sub-select, the shared list eager loads, and the client-variant filters of `InvoiceTable`.
- **Fix:** added `ReceivablesReader::owedByCurrency()` / `owedByCurrencyFrom()`, `TimeTotals::projectColumns()`, `InvoiceResource::withListRelations()` (the Admin list now calls it too, no behaviour change), the Stav filter and the per-currency summarizer in `InvoiceTable` (client variant only), and two lang keys (`client_panel.projects.archived`, `client_panel.invoices.to_pay_total`).
- **Files modified:** app/Domain/Invoicing/Queries/ReceivablesReader.php, app/Domain/TimeTracking/Queries/TimeTotals.php, app/Filament/Resources/InvoiceResource.php, app/Filament/Support/InvoiceTable.php, lang/cs/invoices.php
- **Verification:** Clients, Invoicing, Reports, Isolation, Arch suites and the full suite green
- **Commit:** `8ba7942`, `dfb282f`

**Total deviations:** 1 auto-fixed (1 blocking, additive). **Impact:** none on behaviour of existing screens.

## Notes

- The footer of the Faktury tab lists currencies alphabetically (the table does not know the client currency); the stats row puts the client currency first.
- The tracer and Task 2 tests were written against a RED run (missing component) and committed together with the implementation in one commit per task, not as separate test and feat commits.

## Known Stubs

None.

## Threat Flags

None. The new surface (widget and two relation managers) carries `#[AccessRule(Audience::AdminOnly)]`, as the T-10-71 mitigation requires; the readers refuse non-Admins; proved by the Partner tests.

## Self-Check: PASSED

- Files exist: ClientOverviewStats.php, ClientProjectsRelationManager.php, ClientInvoicesRelationManager.php, ClientInvoicePanelsTest.php
- Commits present on HEAD: 8ba7942, dfb282f, c0c7c7a
- Acceptance criteria of all three tasks re-run: PASS

Ready for the next plan of the phase.
