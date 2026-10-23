---
phase: 14-online-payment-method-and-invoice-currency
plan: 12
subsystem: invoicing
tags: [filament, issued-editor, bank-account, currency, gap-closure, cr-01]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plans 01 to 10)
    provides: DocumentPayment::forIssued(), the account ids, EditInvoice::issuedValues() and the R-14-10-2 unrelated-save rule
provides:
  - EditInvoice::issuedValues() sends bank_account_id whenever the currency changes in the same save and an account is chosen
  - two Pest regression cases of CR-01 (currency to one without an account, currency to one with its own account)
affects: [phase-14]

plan_head_before: f732f1771b55f58259be76bd287730d236335d2b
plan_head_after: b3a3c3e14ccee078b0cd991b5dc4e239b1b4b0a2

actuals:
  tokens: 840
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A page-level payload rule decides only what to send; the Action still validates the id against the settings and copies the snapshot from them"

key-files:
  created: []
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
    - tests/Feature/Invoicing/IssuedEditorTest.php

key-decisions:
  - "R-14-12-1: the account travels when it differs from the filled one, or when the currency changed in the same save and an account is chosen; R-14-10-2 (unrelated save keeps the printed snapshot) is narrowed, not reversed"

requirements-completed: [P14-04, P14-06]

coverage:
  - id: D1
    description: "In the open issued editor the Admin keeps a document's stored account through a currency change to a currency without an account by picking it again; the save succeeds and snapshot and stored id name that account"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#keeps the stored account when the currency changes to one without an account and the Admin picks the stored account again"
        status: pass
    human_judgment: false
  - id: D2
    description: "The stored account is kept, not replaced by the other currency's account, when the new currency has its own account"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#keeps the stored account when the currency changes to one with its own account and the Admin picks the stored account again"
        status: pass
    human_judgment: false
  - id: D3
    description: "An unrelated save of a document whose stored account was removed keeps its printed snapshot, and the refusal case stays green"
    requirement: "P14-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedEditorTest.php#keeps the printed account on an unrelated save when the stored account was removed from the settings"
        status: pass
    human_judgment: false
  - id: D4
    description: "UI check of the CR-01 sequence in the DDEV panel (VERIFICATION.md human item 2)"
    requirement: "P14-04"
    human_judgment: true
    rationale: "End-of-phase human check: the visible bank section and the new PDF cannot be asserted by a test alone"

duration: 6 min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 12: Re-picked stored account survives a currency change Summary

**`issuedValues()` now sends `bank_account_id` on a currency change with a chosen account, so an Admin who picks the stored account again keeps it instead of hitting `bank_account_required` or silently printing the new currency's account (CR-01).**

## Performance

- **Duration:** 6 min
- **Started:** 2026-10-23T15:07:00Z (approx.)
- **Completed:** 2026-10-23T15:13:43Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Tracer: a CZK transfer invoice switched to USD (no USD account), stored account picked again, save succeeds; snapshot and `invoice_admin.bank_account_id` name the original account. RED confirmed first (`bank_account_required` under `bank_account_id`), then GREEN.
- Second regression case for the "silently replaced" branch (EUR has its own account; the stored CZK account is kept, the EUR one is not printed).
- `issuedValues()` docblock and the comment above the account block now state the CR-01 rule next to R-14-10-2 and T-14-34.

## Task Commits

1. **Task 1 (tracer): keep a re-picked stored account through a currency change** - `3141ece` (fix)
2. **Task 2: second regression case and docblock rewording** - `b3a3c3e` (test)

## Deviations from Plan

None - plan executed exactly as written.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Arch`: 1303 passed.
- Pint clean; PHPStan reports only the pre-existing `ResetAdminTwoFactorCommand.php:39` (deferred-items.md).
- `scripts/check-sensitive.sh` clean on both commits; lefthook hooks ran.
- Acceptance greps: `currencyChanged` in `issuedValues()` = 2 lines, `CR-01` in `issuedValues()` = 1, "picks the stored account again" = 2.

## Known Stubs

None.

## Threat Flags

None. T-14-39 mitigated as planned: the page only decides what to send; `DocumentPayment` still refuses an unknown id and copies the snapshot from the settings.

## Next Phase Readiness

Gap 1 (CR-01) closed. Ready for plans 14-13 to 14-15; the CR-01 UI sequence stays in the end-of-phase human check.

## Self-Check: PASSED

- FOUND: app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
- FOUND: tests/Feature/Invoicing/IssuedEditorTest.php
- FOUND commit 3141ece, FOUND commit b3a3c3e
