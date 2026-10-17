---
phase: 10-invoicing
plan: 08
subsystem: invoicing-models
tags: [eloquent, partner-isolation, admin-only, morph-map, canary, activity-allowlist]

requires:
  - phase: 10-invoicing
    provides: "Invoice and InvoiceItem models with the Partner scope (plan 10-05)"
  - phase: 10-invoicing
    provides: "invoice_admin, invoice_item_sources, invoice_payments, invoice_emails tables and guards (plan 10-06)"
  - phase: 10-invoicing
    provides: "InvoiceItemOrigin, PaymentMethod, InvoiceEmailState enums (plan 10-33)"
provides:
  - "InvoiceAdmin, InvoicePayment, InvoiceItemSource, InvoiceEmail: Admin-only models (DeniesPartners + AdminOnlyPolicy)"
  - "Invoice::admin(), payments(), emails(), entries(); InvoiceItem::source(); InvoiceAdmin::pdfDocument() as the only access path to the stored PDF"
  - "Morph aliases invoice_admin, invoice_item_source, invoice_payment, invoice_email"
  - "Canary fixtures, model declaration and activity allowlist registrations for the four models"
affects: [10-09, 10-11, 10-12, 10-13, 10-14, 10-16, 10-17, 10-19, 10-20, 10-34, 10-36, 10-38, 10-40, 10-41]

actuals:
  tokens: 13600
  tasks: 3
  commits: 3
plan_head_before: e6717e89d9766243b212dc16e427a7af2587a448
plan_head_after: 216359ada79045e929d5657662e860892e9d492c
commits: 3

coverage:
  - deliverable: "A Partner reads no InvoiceAdmin, InvoicePayment, InvoiceItemSource or InvoiceEmail row and gets null/empty relations on an issued invoice of the own client, with no canary text in any serialised result"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceAdminDataTest.php"
        status: pass
      - kind: test
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - deliverable: "The Admin reads the note, CNB snapshot, CZK total, payments, e-mail log, linked time, line source and the stored PDF through the relations"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceAdminDataTest.php"
        status: pass
    human_judgment: false
  - deliverable: "Allowlisted history for payment and Admin-only row without note text; recording a payment leaves the issued invoice row unchanged"
    verification:
      - kind: test
        ref: "tests/Isolation/InvoiceAdminDataTest.php#activity history / no touch of the issued invoice"
        status: pass
      - kind: test
        ref: "tests/Arch/ActivityAllowlistTest.php"
        status: pass
    human_judgment: false
  - deliverable: "The registries (morph map, model declarations, activity allowlist list, canary fixtures) know the four models"
    verification:
      - kind: test
        ref: "tests/Arch/ModelDeclarationTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Admin-only side models: KokpitModel + PartnerIsolated via DeniesPartners, AdminOnlyPolicy, nothing fillable except free text, no $touches"
    - "Canary fixtures for Admin-only children hang on the one issued document of the existing idempotent helper, so Partner-visible counts stay exact"

key-files:
  created:
    - app/Domain/Invoicing/Models/InvoiceAdmin.php
    - app/Domain/Invoicing/Models/InvoicePayment.php
    - app/Domain/Invoicing/Models/InvoiceItemSource.php
    - app/Domain/Invoicing/Models/InvoiceEmail.php
    - tests/Isolation/InvoiceAdminDataTest.php
  modified:
    - app/Domain/Invoicing/Models/Invoice.php
    - app/Domain/Invoicing/Models/InvoiceItem.php
    - app/Providers/AccessServiceProvider.php
    - app/Domain/Shared/Database/MorphMap.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/ActivityAllowlistTest.php

key-decisions:
  - "R-08-1: all four models use DeniesPartners and AdminOnlyPolicy (the Transaction precedent); none is Partner-isolated by client"
  - "InvoiceAdmin logs invoice_id, internal_note_updated_at, the CNB trio, czk_total_minor and pdf_document_id; InvoicePayment logs invoice, amount, currency, date, method and the carried-from link; neither logs a note column"
  - "InvoiceItemSource and InvoiceEmail keep no activity history of their own (the source is frozen with the document, the e-mail row is itself the log)"
  - "Only the free text is fillable (InvoiceAdmin.internal_note, InvoicePayment.note); source and e-mail rows are fully forceFill-written by their Actions"

patterns-established:
  - "A canary fixture for a child that the database only accepts while the parent is a draft is written inside the helper that issues the document, before the state flip (or after it, for the payment)"

requirements-completed: [IN-03, IN-05, IN-09]

duration: 30 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 08: Admin-only invoicing models and their isolation Summary

**Four Admin-only models (InvoiceAdmin, InvoicePayment, InvoiceItemSource, InvoiceEmail) with their relations on Invoice and InvoiceItem, the stored PDF reachable only through InvoiceAdmin::pdfDocument(), and a proof that a Partner of the same client reads none of it.**

## Performance

- **Duration:** about 30 min
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 5 created, 8 modified (13 touched, as the plan allowed)

## Accomplishments

- `InvoiceAdmin` (note, CNB rate/unit amount/date, CZK total, PDF link) and `InvoicePayment` (amount, date, method, carried-from link, income transaction) deny Partners; `Invoice::admin()` and `payments()` give the Admin the data and a Partner a null/empty result even for an issued invoice of the own client.
- `InvoiceAdmin::pdfDocument()` (belongsTo `Document` by `pdf_document_id`) is the single path to the stored PDF; the Admin and system code read it, a Partner never gets the admin row.
- `InvoiceItemSource` (origin, project, task, source rate as Money, seconds, price_manual, conversion snapshot) and `InvoiceEmail` (state enum, recipient, subject, sender) complete the Admin-only set; `InvoiceItem::source()`, `Invoice::emails()` (latest first) and `Invoice::entries()` (time entries by `invoice_id`) expose them.
- History allowlists never carry note text: payment activity holds amount, date and method only; the Admin-only row logs the marker `internal_note_updated_at`. Recording a payment as system leaves the issued `invoices` row byte-identical (no `$touches`).
- Registries: four morph aliases, model declaration and activity allowlist lists, and canary fixtures (note, payment note, source snapshot, e-mail subject on the issued canary document) so the generic leak, deny-all and Admin-sees-everything checks cover the new models.

## Task Commits

1. **Task 1 (tracer): InvoiceAdmin and InvoicePayment, admin()/payments(), policies, aliases, isolation test** - `07fcdf4` (feat)
2. **Task 2: InvoiceItemSource and InvoiceEmail, source()/emails()/entries(), aliases** - `2c95337` (feat)
3. **Task 3: registries and canary fixtures** - `216359a` (feat)

## Verification

- Tracer gate re-run end to end: `tests/Isolation/InvoiceAdminDataTest.php`, `DeniedModelsTest.php`, `InvoiceScopeTest.php` green; Pint and PHPStan clean.
- `ddev exec vendor/bin/pest` (whole suite): 5229 passed, 3 skipped, 0 failed (38403 assertions).
- `tests/Arch tests/Isolation tests/Feature/Schema`: 842 passed. Pint `--test` and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.
- Acceptance greps of all three tasks pass.

## Deviations from Plan

None - plan executed exactly as written.

Notes: between the commit of Task 1 and the commit of Task 3 the registry tests (`ModelDeclarationTest`, `CanaryRegistryTest`, `ActivityAllowlistTest`) were red by design of the plan split, because the models existed before their registry lines; Task 3 closes it. The activity changes are read from `attribute_changes` (Spatie v5), not `properties`.

## Known Stubs

None.

## Threat Flags

None. T-10-21 (Partner reads Admin-only invoicing data) and T-10-22 (note text in the activity log) are mitigated as specified; no new endpoint, auth path or file access.

## Notes for later plans

- Create the `invoice_admin` row and the `invoice_item_sources` rows while the invoice is a draft; after the state flip the database refuses INSERT. The PDF link and the internal note are the only writes allowed afterwards (plan 10-06).
- The PDF is reached as `$invoice->admin->pdfDocument` (Admin) or as system after authorising the invoice (e-mail job, PDF route of plan 10-38).
- Models are written with `forceFill` by the Actions; only `InvoiceAdmin.internal_note` and `InvoicePayment.note` are fillable.

## Self-Check: PASSED

Created files exist on disk (four models, `InvoiceAdminDataTest.php`); commits `07fcdf4`, `2c95337`, `216359a` are ancestors of HEAD; commit count measured from the plan ledger is 3.
