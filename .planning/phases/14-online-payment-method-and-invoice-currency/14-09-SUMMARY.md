---
phase: 14-online-payment-method-and-invoice-currency
plan: 09
subsystem: invoicing
tags: [proforma-conversion, payment-link, link-race, audit, payment-method]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plan 04)
    provides: DocumentPayment, the online_unavailable blocker exempting a draft with proforma_id, the bank snapshot from the chosen account
  - phase: 14-online-payment-method-and-invoice-currency (plan 07)
    provides: UpdateIssuedDocument with payment_method, card_payment_enabled and a currency change without an account, PaymentLinks::deactivateLive()
provides:
  - ConvertProforma inheriting the payment method and the chosen account of the proforma, with PaymentLinks::reconcile() after the carried payments
  - payment_method and card_payment in InvoiceAudit::snapshot(), so a post-send method or switch change is in the invoice_edited history
  - proof (tests only) that the link job survives a method change, a switch-off and a currency change during its Stripe call
  - proof that an online document moved to ISK keeps the edit and fails only its link as unsupported_currency
affects: [14-10, 14-11]

plan_head_before: 429fb3a3e97e5ed168745824870255ec9d1b8c58
plan_head_after: f54891e9dbb6f1d57cfa5066d16a4ee5eca12edc

actuals:
  tokens: 5700
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Reconcile after the carries: the link the issue opened is closed in the conversion transaction, RecordPayment::carry() itself never reconciles"
    - "Audit by fixed key list: the Admin-only row is read for the switch only, never for the token or the account id"

key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Actions/ConvertProforma.php
    - app/Domain/Invoicing/Support/InvoiceAudit.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/ConvertProformaTest.php
    - tests/Feature/Payments/CreatePaymentLinkTest.php
    - tests/Feature/Invoicing/InvoiceEditAuditTest.php

key-decisions:
  - "R-14-09-1: the converted draft copies payment_method from the proforma, copies bank_account_id only when BankAccountSettings::find() still finds it (else NULL, the issue takes the first account of the currency), and sets card_payment_enabled true for an online proforma and false for a transfer one"
  - "R-14-09-2: PaymentLinks::reconcile($issued) runs after the last RecordPayment::carry(), inside the conversion transaction"
  - "R-14-09-3: the audited snapshot gains payment_method and card_payment (1 or 0); the account stays represented only by bank_fingerprint"
  - "R-14-09-4: CreatePaymentLink needs no change; the edit transaction deactivates the pending row and the job takes its existing stale path"

requirements-completed: [P14-01, P14-07, P14-08]

coverage:
  - id: D1
    description: "An online proforma paid in full converts into an online invoice with no bank snapshot, the card switch on and a well-formed token; the link its issue opened is deactivated as paid after the carried payment, no link is live, and the proforma's own links are untouched by the conversion"
    requirement: "P14-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the payment method of the converted invoice (Phase 14) it turns an online proforma paid in full into an online invoice with no bank snapshot and no live link"
        status: pass
    human_judgment: false
  - id: D2
    description: "A transfer proforma converts into a transfer invoice with the same account and no card switch or token; a removed account falls back to the first account of the currency; with no account of the currency left the conversion is refused with bank_missing and writes nothing; an online proforma converts while online payments are off, without switch, token or link"
    requirement: "P14-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#the payment method of the converted invoice (Phase 14): edges"
        status: pass
    human_judgment: false
  - id: D3
    description: "A method change, a switch-off or a currency change landing between the Stripe call and the settle of the link job leaves the job's row deactivated (replaced, manual, amount_changed) with its Stripe link kept and deactivated at Stripe, no mail, and no active link in the old state; a method or currency change leaves one new pending link in the current currency"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/CreatePaymentLinkTest.php#a change of the document around the Stripe call (D-08) it ends with no active link in the old state when a method change, a switch-off or a currency change lands during the call (D-10, P14-07)"
        status: pass
    human_judgment: false
  - id: D4
    description: "An online CZK invoice changed to ISK: the edit commits, the old link is deactivated as amount_changed, the new ISK link fails with unsupported_currency before any Stripe call and every Admin is alerted"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/CreatePaymentLinkTest.php#a change of the document around the Stripe call (D-08) it moves an online document to a currency Stripe refuses"
        status: pass
    human_judgment: false
  - id: D5
    description: "After the first send a switch to online and back, and a card-switch-only change, each write one invoice_edited row with the values before and after (payment_method, card_payment, bank_fingerprint); no row of the document holds the Admin-only account id or the pay token; a never-sent document keeps its ordinary model history and gets no invoice_edited row"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/InvoiceEditAuditTest.php#the payment method and the card switch in the history (Phase 14; D-09, IN-15)"
        status: pass
    human_judgment: false

duration: 29min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 09: Conversion, link race and history of a method change Summary

**A fully paid online proforma converts into an online invoice with no bank details whose card link is closed as paid in the same transaction, the link job is proven safe against a method, switch or currency change during its Stripe call, and the post-send history records method and card-switch changes without the account id or the pay token.**

## Performance

- **Duration:** about 29 min
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 6 modified (3 production, 3 test), plus this SUMMARY

## Accomplishments

- `ConvertProforma::createDraft()` copies `payment_method` from the proforma, the proforma's `bank_account_id` when it is still configured, and sets the card switch true for an online proforma and false for a transfer one (Phase 11 D-05). The issue then runs unchanged: an online draft gets no bank snapshot and a pending link, a transfer draft the snapshot of the inherited account or of the first account of its currency.
- `ConvertProforma::handle()` calls `PaymentLinks::reconcile($issued)` after the last carried payment, in the conversion transaction, so a fully paid converted invoice has no live link (`paid`). `RecordPayment::carry()` itself still does not reconcile.
- `InvoiceAudit::snapshot()` gains `payment_method` (null for a credit note) and `card_payment` (1 or 0, null without an Admin-only row), placed before `bank_fingerprint`. The token and `bank_account_id` are never read. The class docblocks list the new keys.
- The link job race (R-14-09-4) is proven by tests for all three changes, with `Queue::fake([CreatePaymentLink::class])` so the new pending link is not run inside the Stripe callback; the job needed no change.
- An online document moved to ISK keeps the edit; its new link fails as `unsupported_currency` through the Phase 11 path and every Admin is alerted (D-08, no pre-check).

## Task Commits

1. **Task 1 (tracer): method and account inherited, reconcile after the carries** - `edaf74b` (feat)
2. **Task 2: conversion edges, link job race, ISK** - `a6785a8` (test)
3. **Task 3: method and card switch in the post-send history** - `f54891e` (feat)

Tracer gate: auto mode off, `human_verify_mode` end-of-phase, `<verify>` automated-only, so the tracer verify (the three test files, Pint, PHPStan) was re-run end to end after Task 1 and passed before the expansion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Czech labels for the two new history keys**
- **Found during:** Task 3
- **Issue:** the history tab translates attribute names from `kokpit.activity.attributes.invoice.*`; `payment_method` and `card_payment` had no entry, so an edit row (and the ordinary model-history row of a never-sent document, where `payment_method` is on the allowlist since plan 14-02) would show the raw key.
- **Fix:** two labels, `payment_method` => "Způsob úhrady" and `card_payment` => "Platba kartou", in `lang/cs/kokpit.php`; the test asserts the rendered text.
- **Files modified:** lang/cs/kokpit.php
- **Commit:** f54891e

**2. [Plan sequencing] Task 2 production behavior was complete after Task 1**
- **Found during:** Task 2
- **Issue:** the conversion edges and the race cases passed on their first run (the inheritance of Task 1 and the plan 14-07 job path already did the work), so Task 2 commits no production change, as R-14-09-4 anticipated. The new tests exercise the behavior, they did not drive it red-first. Task 3 was driven red-first.
- **Impact:** none on behavior.

**Total deviations:** 2 (1 Rule 2 addition, 1 sequencing note). **Impact:** none on scope.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Operations/ActivityAllowlistColumnsTest.php tests/Arch tests/Isolation` - 1643 passed (earlier run of `tests/Feature/Invoicing tests/Feature/Payments` after Task 2 - 1206 passed)
- `ddev exec vendor/bin/pint --test` - pass
- `ddev exec vendor/bin/phpstan analyse` - one error, the pre-existing one in `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39 (deferred-items.md); no new error
- `scripts/check-sensitive.sh` clean on the staged files of all three commits; lefthook sensitive-content and gitleaks hooks passed
- Acceptance greps of all three tasks pass.

## Issues Encountered

- Pre-existing PHPStan error described above (deferred, out of scope).

## Known Stubs

None.

## Threat Flags

None. Mitigations in place: T-14-29 (the tracer test asserts every link of the converted invoice is deactivated as `paid` and none is live), T-14-30 (the three race cases end with the old row deactivated, its Stripe link switched off at Stripe and no active link in the old state), T-14-31 (`payment_method` and `card_payment` in the snapshot, one `invoice_edited` row per edit with the actor), T-14-32 (the snapshot reads neither value; a test searches every activity row of the document for the account id, the token and both key names), T-14-SC (no composer change).

## Next Phase Readiness

Plans 14-10 and 14-11 can rely on the converted invoice carrying the proforma's method and on the history naming method and card-switch changes in Czech.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Actions/ConvertProforma.php (`->reconcile(`)
- FOUND: app/Domain/Invoicing/Support/InvoiceAudit.php (`'payment_method'`, `'card_payment'`)
- FOUND commits: edaf74b, a6785a8, f54891e (ancestors of HEAD)
