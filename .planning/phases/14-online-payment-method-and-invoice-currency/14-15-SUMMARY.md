---
phase: 14-online-payment-method-and-invoice-currency
plan: 15
subsystem: documentation
tags: [contributing, hand-over, gap-closure, in-01, in-02, full-gate]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plans 12 to 14)
    provides: card availability on open issued documents, the converted invoice snapshot through IssueContext
provides:
  - CONTRIBUTING.md hand-over of the card-availability rule, the application-level online/card-switch tie and the converted-invoice snapshot
  - corrected pay token sentence of the Card payments bullet
  - RepositoryFilesTest pins of the new wording and of the removed token wording
  - IN-01 paragraph in the docblock of migration 2026_10_24_000300
  - IN-02 rewording in IssuedEditFinisher and InvoiceCurrencies
affects: [phase-14]

plan_head_before: 2ffd6f4cfb040a5bbace8980fbc0d4fd325b008b
plan_head_after: ee8b1a312d2115ed1c1a4aafad08bce565e4c40e

actuals:
  tokens: 1000
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A hand-over bullet in CONTRIBUTING.md is pinned by RepositoryFilesTest: required phrases are asserted, removed wording is asserted absent, and every backticked class and method is resolved"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
    - app/Domain/Invoicing/Support/IssuedEditFinisher.php
    - app/Domain/Invoicing/Support/InvoiceCurrencies.php

key-decisions:
  - "R-14-15-1: the Card payments token sentence says the pay token is set once, with the first issue or, later, on an open issued document; the Payment method and bank accounts bullet gains three sentences (card availability on issued documents, the application-level tie, the converted invoice snapshot through IssueContext)"
  - "R-14-15-2: RepositoryFilesTest requires 'later, on an open issued document' and forbids the old token clause in the Card payments bullet, and requires 'application-level only', 'card_payment_unavailable' and 'IssueContext' in the Payment method bullet"
  - "R-14-15-3: the migration docblock states the tie is application-level only and DocumentPayment owns the rule (comment only, SQL untouched)"
  - "R-14-15-4: IssuedEditFinisher::paymentLink() no longer calls the card switch frozen; InvoiceCurrencies says a currency needs no bank account of its own and names bank_account_required"

requirements-completed: [P14-01, P14-02, P14-03, P14-04, P14-05, P14-06, P14-07, P14-08, P14-09]

coverage:
  - id: D1
    description: "CONTRIBUTING.md states the card-availability rule, the application-level tie and the converted-invoice snapshot, and the corrected pay token sentence, under test"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands the payment method over to later phases: the Payment method and bank accounts bullet names only what exists"
        status: pass
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 11 classes, methods and tests in CONTRIBUTING.md that exist"
        status: pass
    human_judgment: false
  - id: D2
    description: "The migration, IssuedEditFinisher and InvoiceCurrencies docblocks say what the code does (IN-01, IN-02); the migration SQL is untouched"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/InvoiceLockGuardTest.php, tests/Feature/Schema/InvoiceAdminTablesTest.php (60 passed)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The full gate is green after the three gap-closure plans"
    requirement: "P14-01"
    verification:
      - kind: command
        ref: "ddev exec vendor/bin/pest (6775 passed, 3 skipped, 0 failed)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Draft editor UI behaves as specified (online disabled with a reason while card payment is unavailable, choosing online turns the card toggle on and disables it, currency change preselects the first account of that currency)"
    requirement: "P14-02"
    verification: []
    human_judgment: true
    rationale: "End-of-phase human check (VERIFICATION.md human item 1): visual and interaction behaviour in the DDEV panel, not asserted by a test"

duration: 25 min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 15: Gap-closure hand-over and full gate Summary

**CONTRIBUTING.md now hands the Phase 14 gap-closure rules over under test (card payment is offered on an issued document only while it can work, the online-method/card-switch tie is application-level only, a converted invoice prints its proforma's bank snapshot through `IssueContext`), the stale token and docblock wording of 14-REVIEW.md IN-01 and IN-02 is fixed, and the full gate is green.**

## Performance

- **Duration:** about 25 min (16 min of it the full suite)
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments

- Tracer (Task 1): RED first (both RepositoryFilesTest tests failed on the missing wording), then the CONTRIBUTING edit per R-14-15-1; the existing class-existence loop resolves the newly named `IssueContext` and `DocumentPayment`. All 44 tests of the file pass.
- The Card payments token sentence now says the pay token is set once, with the first issue or, later, on an open issued document (IN-02, OA-1), matching `PayToken` and the migration.
- Three sentences added to the Payment method and bank accounts bullet: card availability on open issued documents (a derived switch falls back to off, an explicit request is refused as `card_payment_unavailable`, an online document with the switch off turns it on once card payment is available again), the application-level tie (no CHECK or trigger couples `invoices.payment_method` with `invoice_admin.card_payment_enabled`), and the converted invoice snapshot handed over through `IssueContext`.
- Task 2: the migration docblock paragraph (IN-01), the `IssuedEditFinisher::paymentLink()` docblock without the word "frozen" and the `InvoiceCurrencies` class docblock naming `bank_account_required` (IN-02). Comments only; the migration keeps its 10 `DB::` statements.

## Task Commits

1. **Task 1 (tracer): CONTRIBUTING hand-over and its test pins** - `b496dbd` (docs)
2. **Task 2: IN-01 and IN-02 docblocks** - `ee8b1a3` (docs)

## Deviations from Plan

None - plan executed exactly as written.

## Verification

- `ddev exec vendor/bin/pest`: 6775 passed, 3 skipped, no failure (91744 assertions, 951 s).
- `ddev exec vendor/bin/pest tests/Feature/Schema/InvoiceLockGuardTest.php tests/Feature/Schema/InvoiceAdminTablesTest.php`: 60 passed.
- `ddev exec vendor/bin/pint --test`: PASS (1201 files).
- `ddev exec vendor/bin/phpstan analyse`: one error, the pre-existing `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (deferred-items.md); nothing else.
- `ddev composer check-licenses`: 227 packages, every one has an allowed licence.
- `scripts/check-sensitive.sh` clean on both commits; lefthook hooks (sensitive-content, gitleaks) ran, no bypass.
- Acceptance greps of both tasks pass (token clause 0 and 1, `application-level only` and `handed over through` present, `frozen card` 0, `the refusal of the issued currency change` 0, `bank_account_required` 1, `DB::` 10).

## Human check pending (end of phase)

VERIFICATION.md human item 1 (draft editor in the DDEV panel as the Admin): switch the payment method between transfer and online, toggle the card payment switch, change the currency and pick an account. Expected: online is disabled with the reason while card payment is unavailable; choosing online turns the card toggle on and disables it; going back to transfer sets the toggle to the client flag; a currency change preselects the first account of that currency (empty with the hint and the Settings link when there is none); any account stays selectable independently of the currency. The issued-editor check (item 2) sits in plan 14-12.

## Known Stubs

None.

## Threat Flags

None. T-14-44 (sensitive data in public docs) mitigated by `scripts/check-sensitive.sh` and gitleaks on both commits; T-14-45 (documentation naming something that does not exist) mitigated by RepositoryFilesTest, which resolves every backticked class and method of both bullets; T-14-SC: no composer change.

## Next Phase Readiness

The gap closure (plans 14-12 to 14-15) is complete and the repository is green. Ready for phase re-verification, with the two end-of-phase DDEV visual checks queued.

## Self-Check: PASSED

- FOUND: CONTRIBUTING.md
- FOUND: tests/Feature/Repo/RepositoryFilesTest.php
- FOUND: database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
- FOUND: app/Domain/Invoicing/Support/IssuedEditFinisher.php
- FOUND: app/Domain/Invoicing/Support/InvoiceCurrencies.php
- FOUND commit b496dbd, FOUND commit ee8b1a3
