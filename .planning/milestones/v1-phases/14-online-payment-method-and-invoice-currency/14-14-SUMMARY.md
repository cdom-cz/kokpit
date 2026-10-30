---
phase: 14-online-payment-method-and-invoice-currency
plan: 14
subsystem: invoicing
tags: [proforma-conversion, bank-snapshot, gap-closure, wr-02]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plans 01 to 13)
    provides: DocumentPayment::account(), IssueContext, IssueChecks, DocumentIssuer::bankSnapshot(), ConvertProforma inheritance of method and account
provides:
  - IssueContext::$bankSnapshot, the optional bank snapshot a document copies from another document
  - IssueChecks::blockers() and assertIssuable() skip bank_missing when a bank snapshot to copy is given
  - DocumentIssuer::bankSnapshot() falls back to the handed-over snapshot when the draft's account cannot be resolved
  - ConvertProforma inherits the stored account id unfiltered and hands the proforma's snapshot to the issue
  - three conversion cases of WR-02 (account removed with no account of the currency left, with another account left, legacy proforma without an id)
affects: [phase-14]

plan_head_before: 4e09a910ef44021b13b3a356b8998792fa0c20f6
plan_head_after: a1d12de79aedb4143cbc09b875d795fce9c0b879

actuals:
  tokens: 4600
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A document that must print the details of another document receives them through IssueContext and the issue pipeline falls back to them only when its own source cannot be resolved; it never re-picks a different source"

key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Issuing/IssueContext.php
    - app/Domain/Invoicing/Support/IssueChecks.php
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Invoicing/Actions/ConvertProforma.php
    - tests/Feature/Invoicing/ConvertProformaTest.php

key-decisions:
  - "R-14-14-1: IssueContext gets a fourth optional parameter $bankSnapshot (array<string, mixed>|null), beside $exchangeSnapshot; IssueContext::now() unchanged"
  - "R-14-14-2: IssueChecks::blockers() and assertIssuable() gain a trailing optional $bankSnapshot; bank_missing is added only when no snapshot is given and DocumentPayment::account() is null"
  - "R-14-14-3: DocumentIssuer::bankSnapshot() prints the settings copy of the draft's account when it resolves, else the handed-over snapshot, else the bank_missing refusal; its return phpdoc widened to array<string, mixed>|null (the column's own type)"
  - "R-14-14-4: ConvertProforma::inheritedAccount() returns the proforma's stored account id as it is, so a removed id is no account for the draft and is never re-picked; the proforma's snapshot (transfer only) is read from the locked row and handed to the issue"

requirements-completed: [P14-04, P14-03]

coverage:
  - id: D1
    description: "A paid transfer proforma whose account was removed and no account of its currency is left converts; the invoice prints the proforma's bank snapshot and records the proforma's account id"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#converts with the bank snapshot of the proforma when its account was removed and no account of the currency is left"
        status: pass
    human_judgment: false
  - id: D2
    description: "When the proforma's account was removed but another account of its currency is left, the invoice prints the proforma's own snapshot, never the other account, and records the proforma's account id"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#prints the bank snapshot of the proforma when its account was removed, although another account of the currency is left"
        status: pass
    human_judgment: false
  - id: D3
    description: "A legacy proforma that names no account converts with its snapshot when no account of the currency is left, and the invoice records no account id"
    requirement: "P14-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/ConvertProformaTest.php#converts a legacy proforma that names no account with the bank snapshot of the proforma when no account of the currency is left"
        status: pass
    human_judgment: false
  - id: D4
    description: "A proforma whose account is still configured, an online proforma and the bank_missing blocker of ordinary drafts are unchanged"
    requirement: "P14-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing, tests/Feature/Payments, tests/Arch, tests/Isolation (1664 passed)"
        status: pass
    human_judgment: false

duration: 30 min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 14: A paid transfer proforma always converts Summary

**A converted invoice now prints the bank snapshot of its proforma whenever the proforma's account is no longer configured, handed to the issue through `IssueContext`, so a fully paid transfer proforma stays convertible and never prints an account the customer did not pay to (WR-02).**

## Performance

- **Duration:** about 30 min
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments

- Tracer: a CZK transfer proforma paid in full, its account removed and only a EUR account left, converts. RED confirmed first (`bank_missing` thrown by `IssueChecks`; the other-account case printed CZK A), then GREEN: the invoice prints the proforma's snapshot and records the proforma's account id.
- `IssueContext::$bankSnapshot`, the trailing `$bankSnapshot` of `IssueChecks::blockers()` and `assertIssuable()` and the fallback in `DocumentIssuer::bankSnapshot()` (R-14-14-1 to R-14-14-3). Every existing caller passes fewer arguments and is unchanged.
- `ConvertProforma::inheritedAccount()` no longer asks the settings (the `BankAccountSettings` import is gone); `handle()` issues with `new IssueContext($actor, IssueContext::today(), null, $snapshot)` where `$snapshot` is the locked proforma's `bank_snapshot` for a transfer proforma and null for an online one (R-14-14-4; T-14-42 and T-14-43).
- The two cases that pinned the old behaviour were replaced in the tracer commit; the legacy-proforma case was added in Task 2 and passed on the Task 1 code (it pins the null-id branch).
- Docblocks of `ConvertProforma`, `DocumentIssuer::bankSnapshot()` and `writeAdminRow()`, `IssueChecks` and `IssueContext` state the rule and cite WR-02, replacing the old promise.

## Task Commits

1. **Task 1 (tracer): convert with the proforma's own bank snapshot when its account was removed** - `69a4fff` (fix)
2. **Task 2: legacy proforma case and docblocks** - `a1d12de` (test)

## Deviations from Plan

None - plan executed exactly as written.

Outcome recorded per the plan: Pint rewrote the phpdoc alignment of `DocumentIssuer::bankSnapshot()` once; the formatting-only fix is part of the Task 1 commit. PHPStan accepted the widened `array<string, mixed>|null` return without further changes.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Arch tests/Isolation`: 1664 passed.
- `ddev exec vendor/bin/pest tests/Feature/Invoicing/ConvertProformaTest.php`: 18 tests passed (3 of them match the filter "bank snapshot of the proforma" in name or intent).
- Pint clean; PHPStan reports only the pre-existing `ResetAdminTwoFactorCommand.php:39` (deferred-items.md).
- `scripts/check-sensitive.sh` clean on both commits; lefthook hooks ran, no bypass.
- Acceptance greps of both tasks pass.

## Known Stubs

None.

## Threat Flags

None. T-14-42 (snapshot read only from the row locked at the start of the transaction) and T-14-43 (a removed id is never re-picked) mitigated as planned.

## Next Phase Readiness

Gap 3 (WR-02) closed. Ready for plan 14-15.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Issuing/IssueContext.php
- FOUND: app/Domain/Invoicing/Support/IssueChecks.php
- FOUND: app/Domain/Invoicing/Issuing/DocumentIssuer.php
- FOUND: app/Domain/Invoicing/Actions/ConvertProforma.php
- FOUND: tests/Feature/Invoicing/ConvertProformaTest.php
- FOUND commit 69a4fff, FOUND commit a1d12de
