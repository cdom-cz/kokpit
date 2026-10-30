---
phase: 11-stripe-payments
plan: 03
subsystem: payments
tags: [stripe, card-payment, pay-token, invoice-admin, check-constraint, filament]

requires:
  - phase: 11-stripe-payments
    provides: "StripeConfig::isConfigured() and StripeTestConfig (plan 11-01), shared lang/cs/invoices.php (plan 11-02)"
provides:
  - "invoice_admin.card_payment_enabled and invoice_admin.pay_token, with invoice_admin_pay_token_unique and invoice_admin_pay_token_check; both frozen after issue by the existing child guard (KP001)"
  - "CardPaymentSwitch::effective(InvoiceKind, bool), available(), unavailableReason() (settings_off | stripe_missing): the one predicate of D-06"
  - "PayToken::generate() (32 CSPRNG letters and digits) and isWellFormed()"
  - "PayUrl::for(token) = <app.url>/pay/<token> from config only, PayUrl::forInvoice(Invoice): ?string"
  - "UpdateDraft data key card_payment_enabled (strict boolean, invoice and proforma drafts only)"
  - "Draft editor toggle 'Platba kartou', issue confirmation row, settings hint and Stripe-missing callout, client form hint"
  - "tests/Support/CardPaymentSetup: enable(), onlinePayments(bool), client(), issued()"
affects: [11-05, 11-07, 11-08, 11-12, 11-15, payment-links, pay-page, invoice-pdf, invoice-email]

actuals:
  tokens: 9700
  tasks: 3
  commits: 3
plan_head_before: 72e18969ca45fe4cbe1211eeeafe8cc028562cdf
plan_head_after: 5c914a97dc4310b7f103cad8840db779b926a780
commits: 3

tech-stack:
  added: []
  patterns:
    - "A value that must freeze at issue is finalised in DocumentIssuer::writeAdminRow() before the state flip, because the child guard of invoice_admin refuses the write afterwards"
    - "A disabled Filament toggle shows off whatever is stored and is not dehydrated, so the stored draft value survives a save while the installation cannot offer the feature"
    - "A bearer value (the pay token) lives on an Admin-only row, outside the activity allowlist, and its URL is built from config app.url, never the request host"

key-files:
  created:
    - database/migrations/2026_10_18_000200_add_card_payment_to_invoice_admin.php
    - app/Domain/Payments/Support/CardPaymentSwitch.php
    - app/Domain/Payments/Support/PayToken.php
    - app/Domain/Payments/Support/PayUrl.php
    - tests/Support/CardPaymentSetup.php
    - tests/Feature/Payments/CardSwitchTest.php
  modified:
    - app/Domain/Invoicing/Models/InvoiceAdmin.php
    - app/Domain/Invoicing/Actions/CreateDraft.php
    - app/Domain/Invoicing/Actions/UpdateDraft.php
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - app/Filament/Pages/SettingsPage.php
    - app/Domain/Settings/Settings/PaymentSettings.php
    - lang/cs/invoices.php
    - lang/cs/kokpit.php

key-decisions:
  - "R11-03-1: effective value at issue = kind carries payment AND draft switch AND PaymentSettings::online_payments_enabled AND StripeConfig::isConfigured(); the client flag is only the default of the draft switch, so an Admin override to on for a client that is off takes effect (assumption A8, recorded for the owner)"
  - "R11-03-2: card_payment_enabled boolean NOT NULL DEFAULT false and pay_token varchar(32) NULL UNIQUE with CHECK (pay_token IS NULL OR (card_payment_enabled AND pay_token ~ '^[A-Za-z0-9]{32}$')); no expiry; the existing child guard freezes both with no trigger change"
  - "R11-03-3: PayUrl uses rtrim(config('app.url'), '/') + '/pay/' + token; the path /pay/{token} is fixed for plan 11-08"
  - "R11-03-5: UpdateDraft refuses a non-boolean or a credit-note draft with invoices.errors.card_payment_invalid under card_payment_enabled and creates the Admin row when missing"
  - "R11-03-6: card_payment_enabled is on the activity allowlist, pay_token is not"
  - "Migration down() refuses to run while any document carries a pay token, because printed links would break"

patterns-established:
  - "CardPaymentSetup::enable() sets both Stripe secrets and the global setting; CardPaymentSetup::issued() issues through the real pipeline"
  - "Credit-note drafts for tests are built as system with a forceFill of kind credit_note and a credited invoice of the same client (creditNoteDraftOf() in CardSwitchTest)"

requirements-completed: []

duration: 55min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 03: Card switch and pay token Summary

**Every invoice and proforma now has a per-document card switch that starts as the client's value, can be overridden on the draft, and is frozen at issue together with a 32-character pay token and a stable `<app.url>/pay/<token>` URL that never depends on Stripe or the request host.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 15 (6 created, 9 modified)

## Accomplishments

- Migration `2026_10_18_000200_add_card_payment_to_invoice_admin` adds `card_payment_enabled` and `pay_token` with `invoice_admin_pay_token_unique` and `invoice_admin_pay_token_check`. Raw-SQL tests prove KP001 for any change of either column on an issued document, 23505 for a duplicate token and 23514 for a malformed token or a token on a document with the switch off.
- `DocumentIssuer::writeAdminRow()` finalises the switch through `CardPaymentSwitch::effective()` and sets `PayToken::generate()` or null before the state flip (docblock explains why the order matters). Credit notes, the storno of a card invoice and the invoice made by `ConvertProforma` from a card proforma end with false and no token (D-05).
- `CreateDraft` writes the client's `online_payment_enabled` as the draft switch (invoice and proforma); `UpdateDraft` accepts `card_payment_enabled` as the Admin's override.
- `PayUrl` ignores the request: a test replaces the request with one for another host and the URL is unchanged; the URL of a normal token is 69 characters at the test host, within the 80-character cap of Surface B.
- The pay token is never in the activity log (test over every `invoice_admin` activity row); `card_payment_enabled` is.
- Draft editor: toggle "Platba kartou" below "Zpusob uhrady" with the UI-SPEC helper; disabled and off with "Online platby jsou vypnute v Nastaveni." or "Stripe neni nastaveny..." when the installation cannot offer it (the stored value is kept because a disabled field is not saved); hidden on a credit-note draft. The issue confirmation lists "Platba kartou" with "Ano, odkaz se vytvori po vystaveni" or "Ne" from `CardPaymentSwitch::effective()`.
- Settings: replaced hint on the toggle, live warning callout when the toggle is on and Stripe is not configured; the page renders no Stripe key and no key field (test). Client form hint replaced in `lang/cs/kokpit.php`.
- Verification: Payments, Invoicing, Operations, Clients and Arch suites 1303 passed; Unit and Isolation suites 1111 passed (run at the orchestrator's request after the last fix of plan 11-02); Pint and PHPStan clean; `scripts/check-sensitive.sh` clean.

## Task Commits

1. Task 1: tracer, frozen switch and stable pay URL at issue - `2993743` (feat)
2. Task 2: switch matrix and the draft override - `c439071` (feat)
3. Task 3: editor toggle, issue confirmation, settings and client copy - `5c914a9` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-03-1 to R11-03-6 were applied as written. R11-03-1 (an Admin override to on for a client that is off takes effect) rests on research assumption A8 and should be confirmed by the owner.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A missing Admin row made the switch read null at issue**
- **Found during:** Task 1 (full invoicing run)
- **Issue:** `IssueCreditNote` issues a draft that has no `invoice_admin` row; `writeAdminRow()` builds a new row whose `card_payment_enabled` attribute is null, and `CardPaymentSwitch::effective(bool)` threw a TypeError in 32 existing tests.
- **Fix:** the new row is created with `card_payment_enabled` false.
- **Files modified:** `app/Domain/Invoicing/Issuing/DocumentIssuer.php`
- **Commit:** `2993743`

### Choices within the plan's latitude

- `CardPaymentSwitch` takes `PaymentSettings` and `StripeConfig` by constructor and is resolved with `app()` at the moment of use in `DocumentIssuer` and the editor, so a setting changed after the issuer was constructed is read fresh.
- The issue confirmation row is shown for every kind (a credit-note draft would read "Ne"); the plan does not distinguish.
- Task 2's `UpdateDraft` change was small enough that the `CardPaymentSwitch::available()` and `unavailableReason()` methods already existed from Task 1 (they were part of the tracer's predicate), so Task 2 added only `UpdateDraft` and tests.
- Task 1's RED run: the 7 tracer tests failed before implementation (class and column missing). Tasks 2 and 3 were written test-first in the same session; the new behaviours had no implementation when the tests were written.

None of these change a contract that later plans rely on.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-07 (token entropy, uniqueness and format CHECK), T-11-08 (child guard freeze, KP001 tests), T-11-09 (token not on the allowlist, invoice_admin stays DeniesPartners) and T-11-10 (URL from `app.url` only, tested with another host) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-08 registers the route at `/pay/{token}`; `PayToken::isWellFormed()` is the cheap pre-check before any database lookup.
- Plan 11-07 adds its link row and job to `DocumentIssuer` after `writeAdminRow()`; the switch and token are already frozen on `$admin` by then (`$admin->card_payment_enabled`, `$admin->pay_token`).
- Plan 11-12 builds the frozen read-only row "Platba kartou: Ano / Ne" of the issued detail; read `invoice_admin.card_payment_enabled` as the Admin.
- Requirements PA-03 and PA-02 are not marked complete: this plan delivers the switch and the stable URL foundation, not the pay page, the PDF/e-mail button or the payment link.

## Self-Check: PASSED

- Files: all 15 listed files exist.
- Commits: `2993743`, `c439071`, `5c914a9` are ancestors of HEAD.
