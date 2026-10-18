---
phase: 11-stripe-payments
plan: 06
subsystem: payments
tags: [stripe, payment-link, postgres-trigger, state-machine, admin-only, partial-unique-index]

requires:
  - phase: 11-stripe-payments
    provides: "invoice_admin.card_payment_enabled frozen at issue and CardPaymentSetup test helpers (plan 11-03), shared PgSchema/registration files (plan 11-04)"
provides:
  - "table payment_links with the composite key (invoice_id, currency) to invoices, state/reason/failure/URL/active/remote check constraints, the partial unique index payment_links_one_live_per_invoice and the sweep index payment_links_remote_pending_index"
  - "kokpit_guard_payment_link() trigger payment_links_guard (KP001) and payment_links_truncate_guard"
  - "App\\Domain\\Payments\\Models\\PaymentLink: open(Invoice, int, ?User), latestFor(string), live() scope, amount(), isLive()"
  - "PaymentLinkState (Pending, Active, Failed, Deactivated) and PaymentLinkDeactivation (Paid, Cancelled, AmountChanged, Manual, Replaced), both HasLabel with Czech labels in lang/cs/enums.php"
  - "morph alias payment_link, AdminOnlyPolicy registration, model declaration, activity allowlist and canary fixture with a Stripe-id needle"
affects: [11-07, 11-08, 11-09, 11-10, 11-12, payment-links, pay-page, reconcile]

actuals:
  tokens: 21000
  tasks: 2
  commits: 2
plan_head_before: 9435a67df79ce39230dd70c4f7fb4bd86bab3776
plan_head_after: 6f747462eb8d0e858cf48faa39e9d3c7f9383c05
commits: 2

tech-stack:
  added: []
  patterns:
    - "A record that must outlive its state machine (never deleted, references stay) is guarded by one BEFORE INSERT OR UPDATE OR DELETE trigger that names every allowed move; raw-SQL tests cover each refusal through RawSql::expectSqlState"
    - "A Stripe-shaped value in a test is assembled at runtime from fragments (implode of pieces, Str::random), so no line of a test file looks like a real value"

key-files:
  created:
    - database/migrations/2026_10_18_000400_create_payment_links_table.php
    - app/Domain/Payments/Models/PaymentLink.php
    - app/Domain/Payments/Enums/PaymentLinkState.php
    - app/Domain/Payments/Enums/PaymentLinkDeactivation.php
    - tests/Feature/Payments/PaymentLinkRecordTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - lang/cs/enums.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Support/PgSchema.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Feature/Schema/SchemaConventionsTest.php

key-decisions:
  - "R11-06-1 to R11-06-4 applied as written (columns, checks, indexes, guard, model, labels)"
  - "The guard also freezes stripe_url once set (the plan names only stripe_link_id): a URL belongs to one Stripe link and the reference is kept for the audit trail"
  - "Insert accepts any state; only the document (issued, not a credit note, card switch on) is checked by the trigger, the shape of the state is the job of the check constraints"
  - "The shared canary invoice helper now freezes card_payment_enabled true on its Admin row so the canary link can be inserted; no existing Isolation test depended on it being false"

patterns-established:
  - "PaymentLink::open() requires an open transaction and a locked invoice; the later moves (active, failed, deactivated) are written by the services of plans 11-07 and 11-09 with forceFill"
  - "A final row (failed, deactivated) may only gain its Stripe id and URL once and its remote_deactivated_at once"

requirements-completed: []

duration: 30min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 06: Payment link record Summary

**Every Stripe payment link of a document now has an Admin-only record whose database rules make a wrong link impossible to store: wrong kind, wrong currency, a second live link, a changed amount, a reopened state, a non-Stripe URL or a deleted record are all refused.**

## Performance

- **Duration:** about 30 min
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 14 (5 created, 9 modified)

## Accomplishments

- Migration `2026_10_18_000400_create_payment_links_table` creates `payment_links` with `payment_links_invoice_currency_fk`, the state, deactivation, failure, URL, active and remote check constraints, `payment_links_stripe_link_id_unique`, the partial unique index `payment_links_one_live_per_invoice` (D-08) and the partial index `payment_links_remote_pending_index` for the sweep of plan 11-10.
- `kokpit_guard_payment_link()` (KP001): an insert needs an issued invoice or proforma (not a credit note) whose frozen `card_payment_enabled` is true; a delete is refused; document, currency, amount, creator and creation time never change; only pending to active, failed or deactivated and active to deactivated are accepted; a failed or deactivated row can only gain its Stripe id and URL once and its remote stamp once. TRUNCATE is refused by the shared guard.
- `PaymentLink` model: Admin-only (`DeniesPartners`, `AdminOnlyPolicy`), nothing fillable, `open()` (LogicException outside a transaction), `latestFor()` (the live row first, otherwise the newest), `live()` scope, `amount()`, `isLive()`. The history allowlist holds state, reason, class, amount, currency and dates; the Stripe id and URL are never logged (tested).
- Czech labels for the four states and five deactivation reasons; "Vypnuto" stays derived by plan 11-12.
- `PaymentLinkRecordTest` (16 tests, 92 assertions): the tracer, a Partner of the same client sees no row and the policy refuses, the one-live index and replacement after deactivation, every refused insert (draft, credit note, switch off, other currency, non-positive amount), every check constraint including near-miss hosts of the URL (`stripe.com.example.net`, `notstripe.com`, userinfo tricks), every refused update and the allowed moves, no delete, once-only Stripe references and TRUNCATE.
- Verification: Payments, Arch, Isolation and Schema suites 993 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits.

## Task Commits

1. Task 1: tracer, record, model, enums and registrations - `74dbe3b` (feat)
2. Task 2: guard update and delete branches and the raw-SQL tests - `6f74746` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. Rulings R11-06-1 to R11-06-4 were applied as written; the only addition is that `stripe_url` is frozen once set, like `stripe_link_id`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Three existing registry tests list the models and exempt columns exactly**
- **Found during:** Task 1 (Arch, Isolation and Schema run)
- **Issue:** `tests/Arch/ModelDeclarationTest.php` (all application models), `tests/Isolation/CanaryRegistryTest.php` (all PartnerIsolated models) and `tests/Feature/Schema/SchemaConventionsTest.php` (the exact EXEMPT keys) failed once the model and the two exempt columns existed.
- **Fix:** added `PaymentLink` to the two model lists and `payment_links.currency` and `payment_links.stripe_link_id` to the expected EXEMPT list; the plan named the isolated-models list and `loggingModels()` but not these exact-list assertions.
- **Files modified:** `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Feature/Schema/SchemaConventionsTest.php`
- **Commit:** `74dbe3b`

### Choices within the plan's latitude

- The canary Admin row now has `card_payment_enabled` true in the shared helper `CanaryRegistry::invoiceDocuments()`, because the link insert needs the frozen switch; the canary link is opened through `PaymentLink::open()` and activated with a Stripe id and URL built from the canary at runtime.
- The raw-SQL test "accepted hosts" uses one document per host: a link record is never deleted, so a test cannot clean up inside its own transaction.
- Task 1's RED run: all four tracer tests failed before implementation (classes missing). Task 2's RED run: the five guard tests failed before the update and delete branches existed (the shape and one-live tests already passed from Task 1's constraints).

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-19 (Partner reads link rows: `DeniesPartners`, `AdminOnlyPolicy`, canary fixture and needle, allowlist without Stripe references), T-11-20 (second live link or changed amount: partial unique index, guard trigger, raw-SQL tests) and T-11-21 (non-Stripe URL: `payment_links_stripe_url_check`, tested with near-miss hosts) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-07 (link job) opens with `PaymentLink::open($lockedInvoice, $amountMinor, $admin)` inside its transaction and writes pending to active (`stripe_link_id`, `stripe_url`, `activated_at`) or failed (`failure_class`) with `forceFill`.
- Plan 11-09 deactivates by writing `state`, `deactivation_reason` and `deactivated_at` together (the check constraint needs the reason exactly when deactivated); a replacement deactivates the old link first, then opens the new one.
- Plan 11-10 finds the remote sweep rows through `payment_links_remote_pending_index` and writes `remote_deactivated_at` once.
- Requirements PA-01 and PA-04 are not marked complete: this plan delivers the record and its database rules, not the job, the reconcile service or the pay page.

## Self-Check: PASSED

- Files: all 14 listed files exist.
- Commits: `74dbe3b` and `6f74746` are ancestors of HEAD.
