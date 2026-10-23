---
phase: 14-online-payment-method-and-invoice-currency
plan: 11
subsystem: documentation
tags: [contributing, hand-over, repository-files-test, key-decisions, docblocks, migration]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plans 01 to 10)
    provides: DocumentPayment, PayUrl::forInvoice(), PaymentLinks::deactivateLive(), PayLinkBlock::applies(), the account ids, BankAccountByIdTest and the three phase migrations
provides:
  - the CONTRIBUTING bullet "Payment method and bank accounts." with the Phase 13 hand-over (OA-5) and the amended "Card payments." and "Editable invoices." bullets
  - a RepositoryFilesTest that resolves every class and method the new bullet names
  - two Key Decisions rows of Phase 14 in PROJECT.md
  - docblocks that no longer call the card switch or the pay token frozen at issue (OA-1)
  - the DDEV development database migrated with the three phase migrations
affects: [phase-13]

plan_head_before: 70aa7f78c20322d1a391c7198b0f9aaed28cea0e
plan_head_after: 4821aa07d01fe1ec5b2b4ba989c6f257b00b1181

actuals:
  tokens: 1500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A documentation bullet is under test: every backticked class and Class::method() of it must exist, so the text cannot drift from the code"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/PROJECT.md
    - app/Domain/Payments/Support/PayToken.php
    - app/Domain/Payments/Support/CardPaymentSwitch.php
    - app/Domain/Payments/Queries/PaymentLinkStatus.php
    - app/Domain/Payments/Actions/RegeneratePaymentLink.php
    - app/Domain/Payments/Enums/PaymentLinkState.php
    - app/Filament/Support/InvoiceTable.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php

key-decisions:
  - "The Phase 14 rules live in one CONTRIBUTING bullet (payment method, accounts by id, DocumentPayment, preselect(), InvoiceCurrencies) and the Phase 11 Card payments bullet carries the switch and token rule; the Phase 13 e-mail keeps PayLinkBlock::applies() as its one predicate"
  - "The PayToken docblock says the token is generated when the document first offers card payment (at issue, or later on an open issued document) and set once, matching DocumentPayment::forIssued(), instead of the plan's looser wording 'once at issue'"

patterns-established:
  - "A test class named in a hand-over bullet is checked as a file under tests/, not through the class loop that scans app/ and tests/Support"

requirements-completed: [P14-01, P14-03, P14-06, P14-07]

coverage:
  - id: D1
    description: "CONTRIBUTING.md names the payment method, accounts by id, DocumentPayment::preselect(), the card switch editable while open, the set-once token, PayUrl::forInvoice(), PaymentLinks::deactivateLive() and the Phase 13 PayLinkBlock::applies() hand-over, and the repository refuses the text if a named class or method is missing"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands the payment method over to later phases: the Payment method and bank accounts bullet names only what exists"
        status: pass
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 11 classes, methods and tests in CONTRIBUTING.md that exist"
        status: pass
    human_judgment: false
  - id: D2
    description: "PROJECT.md records the payment method per document with account ids and the card switch editable while open, and no docblock of the application calls the switch or the token frozen at issue"
    requirement: "P14-06"
    verification:
      - kind: command
        ref: "grep -q 'Phase 14, D-01, D-04' .planning/PROJECT.md && grep -q 'Phase 14, OA-1' .planning/PROJECT.md && ! grep -rqE 'frozen card switch|frozen switch|The value frozen at issue|frozen with the document|switch frozen at issue' app"
        status: pass
    human_judgment: false
  - id: D3
    description: "The development database runs the three phase migrations and the whole repository gate is green"
    requirement: "P14-01"
    verification:
      - kind: command
        ref: "ddev exec php artisan migrate:status (three 2026_10_24 migrations Ran; a second migrate prints Nothing to migrate)"
        status: pass
      - kind: command
        ref: "ddev exec vendor/bin/pest (6768 passed, 3 skipped); pint --test (1201 files pass); ddev composer check-licenses (227 packages allowed)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Visual and interaction quality of the payment method, account and card toggle in the draft editor (plan 14-08) and in the open issued editor (plan 14-10) in the DDEV panel"
    requirement: "P14-06"
    verification: []
    human_judgment: true
    rationale: "The tracer human-checks of plans 14-08 and 14-10 are deferred to the end of the phase (human_verify_mode end-of-phase); nobody has looked at the panel in a browser"

duration: 25min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 11: Hand-over, decisions and gate Summary

**The payment-method and bank-account rules of Phase 14 are written into CONTRIBUTING.md under a test that proves every named class and method exists, with the Phase 13 `PayLinkBlock::applies()` hand-over, the two decisions recorded in PROJECT.md, the "frozen at issue" wording gone from the code, and the development database migrated.**

## Performance

- **Duration:** about 25 min (the full suite alone takes 16 min)
- **Completed:** 2026-10-23
- **Tasks:** 2
- **Files modified:** 10

## Accomplishments

- New CONTRIBUTING bullet "Payment method and bank accounts." (placed before "Phase 12."): `invoices.payment_method`, the two checks and the OA-7 carve-out of Phase 10 D-12, what each method prints, account ids and `BankAccountSettings::find()`, `invoice_admin.bank_account_id`, `DocumentPayment::forDraft()` / `forIssued()` / `preselect()` kept by `BankAccountByIdTest`, `InvoiceCurrencies`, and the Phase 13 sentence.
- "Card payments." now says the switch is editable on an open issued transfer document, forced on for online and frozen by the lock; the token is set once; every printer goes through `PayUrl::forInvoice()`; `PaymentLinks::deactivateLive()` sits beside `reconcile()` (reason `replaced` for a method change, `manual` for a switch-off). "Editable invoices." lists payment method, bank account and card switch among the write-path fields.
- `RepositoryFilesTest` gained the Phase 14 hand-over test and the extra Card payments assertions (the old phrase "is frozen at issue" is gone from the whole file).
- PROJECT.md Key Decisions: two rows (R-14-11-3).
- Docblocks of `PayToken`, `CardPaymentSwitch`, `PaymentLinkStatus`, `RegeneratePaymentLink`, `PaymentLinkState`, `InvoiceTable` and `ViewInvoice` rewritten to the OA-1 rule; comments only. The `InvoiceAdmin` docblock had already been rewritten by plan 14-02.
- `ddev exec php artisan migrate` applied `2026_10_24_000100` (settings), `000200` and `000300` on the development database without a data fix; a second run prints "Nothing to migrate".

## Task Commits

1. **Task 1 (tracer): CONTRIBUTING bullet and RepositoryFilesTest** - `72dea7e` (docs)
2. **Task 2: decisions, docblock sweep, migration, full gate** - `4821aa0` (docs)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The class loop of the new test cannot resolve a test class**
- **Found during:** Task 1
- **Issue:** the Phase 11 regex loop resolves names through `repoClassesNamed()`, which scans only `app/` and `tests/Support`, so `BankAccountByIdTest` (in `tests/Arch`) would always fail it.
- **Fix:** the new test skips names ending in `Test` in the loop and asserts the file `tests/Arch/BankAccountByIdTest.php` exists, as the Phase 11 test does for its enforcing tests.
- **Files modified:** `tests/Feature/Repo/RepositoryFilesTest.php`
- **Commit:** `72dea7e`

**2. [Rule 1 - Accuracy] PayToken docblock wording**
- **Found during:** Task 2
- **Issue:** the plan's rule says the token is "set once"; `DocumentPayment::forIssued()` may set a first token on an open issued document, so "generated once at issue" would be wrong.
- **Fix:** the docblock says the token is generated when the document first offers card payment and never changes afterwards.
- **Commit:** `4821aa0`

**Total deviations:** 2 auto-fixed (accuracy of a test helper and of a comment). **Impact:** none on behaviour.

## Verification

- `ddev exec vendor/bin/pest`: 6768 passed, 3 skipped (the full suite, run scoped as one process, not `--parallel`).
- `ddev exec vendor/bin/pint --test`: pass (1201 files).
- `ddev exec vendor/bin/phpstan analyse`: one error, the pre-existing `ResetAdminTwoFactorCommand.php` line 39 (logged in `deferred-items.md`); no new error.
- `ddev composer check-licenses`: 227 packages, every licence allowed.
- `scripts/check-sensitive.sh`, gitleaks: clean through the lefthook hook on both commits.
- Acceptance greps of both tasks pass (the grep for the old wording prints no match under `app`).

## Known Stubs

None.

## Threat Flags

None. Documentation, comments and an already-tested migration only.

## Deferred (human judgment)

- Visual check of the payment method, account and card toggle fields in the DDEV panel, draft editor (plan 14-08) and open issued editor (plan 14-10): deferred to end of phase, not attempted here.

## Next Phase Readiness

Phase 14 has all eleven plans summarised; ready for phase verification and the deferred human check. Phase 13 inherits the `PayLinkBlock::applies()` rule written into CONTRIBUTING.md.

## Self-Check: PASSED

- CONTRIBUTING.md, tests/Feature/Repo/RepositoryFilesTest.php, .planning/PROJECT.md found; commits `72dea7e` and `4821aa0` are ancestors of HEAD.
