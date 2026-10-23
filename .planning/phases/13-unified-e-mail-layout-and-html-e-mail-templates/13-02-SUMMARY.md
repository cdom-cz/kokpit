---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 02
subsystem: invoicing
tags: [payment-confirmation, postgres, schema, partner-isolation, canary]
status: complete

requires:
  - phase: 10-invoicing
    provides: invoice_payments, RecordPayment, DeletePayment, InvoiceFixtures
provides:
  - the payment_confirmations table (unique and cascading invoice_payment_id, seven named checks)
  - App\Domain\Invoicing\Models\PaymentConfirmation (Admin-only) and PaymentConfirmationState
  - morph alias payment_confirmation, AdminOnlyPolicy, model and canary registry entries
  - PaymentConfirmationSchemaTest (database contract, tracer and 9 contract cases)
affects: [13-06, 13-08]

plan_head_before: f0bb3835376bab25096a4de1fbd1b348bcad5cda
plan_head_after: 4c73061bcf5706a70924b10e77c82f10c93f2a37

actuals:
  tokens: 9000
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One-confirmation-per-payment rests on the database: unique invoice_payment_id plus ON DELETE CASCADE, so a payment taken back in the grace window leaves nothing to send"
    - "Schema tests start from a payment with its confirmation rows deleted as the system, so they stay green after the trigger of plan 13-06 is wired"

key-files:
  created:
    - database/migrations/2026_10_25_000100_create_payment_confirmations_table.php
    - app/Domain/Invoicing/Enums/PaymentConfirmationState.php
    - app/Domain/Invoicing/Models/PaymentConfirmation.php
    - tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Support/CanaryRegistry.php

key-decisions:
  - "No guard trigger on payment_confirmations: the job of plan 13-06 moves state and attempts freely, like invoice_emails"
  - "The confirmation mark lives in its own table; invoice_payments and invoices refuse updates and invoice_emails state drives the invoice lock"

requirements-completed: [P13-05, P13-11]

duration: 25min
completed: 2026-10-23
---

# Phase 13 Plan 02: Payment Confirmation Storage Summary

The `payment_confirmations` table stores at most one Admin-only confirmation row per payment, removed with its payment, with every malformed row refused by a named check.

## What was built

- Migration with `payment_confirmations_invoice_payment_unique`, the cascading `payment_confirmations_invoice_payment_fk`, an index on `invoice_id` and seven named checks (state, language, remaining, attempts, sent_at, failure_code, recipient).
- `PaymentConfirmation` (KokpitModel, PartnerIsolated, DeniesPartners, nothing fillable, no `$touches`, no activity log) and the `PaymentConfirmationState` enum.
- Registries in the same commit as the model: morph alias, `Gate::policy(..., AdminOnlyPolicy::class)`, both lists of `ModelDeclarationTest`, `CanaryRegistryTest`, and a canary fixture writing one sent confirmation of the canary payment (recipient built from the canary on example.com).
- Tests: tracer (stored and read back, duplicate refused with 23505, cascade through `DeletePayment`, Partner reads none and is denied) and the contract (23514 for each check, 23503 for an unknown payment, defaults queued and 0 attempts).

## Deviations from Plan

None - plan executed exactly as written. The canary fixture needed the canary verbatim in the recipient (not lower-cased) so the Admin-sees-canary sweep finds it; this was within the planned fixture entry.

## Known Stubs

None.

## Threat Flags

None. T-13-10 and T-13-25 are mitigated as planned; nothing was installed (T-13-SC).

## Verification

- Pest: PaymentConfirmationSchemaTest, tests/Arch, tests/Isolation, tests/Feature/Schema green.
- Pint clean; PHPStan only reports the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh` and the lefthook hook clean on both commits.

## Commits

- 8157f5c: feat(13-02): tracer - payment_confirmations table with unique cascading payment key and Admin-only model
- 4c73061: test(13-02): full database contract of payment_confirmations

## Self-Check: PASSED
