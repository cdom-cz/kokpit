---
phase: 10-invoicing
plan: 07
subsystem: invoicing-numbering
tags: [numbering, variable-symbol, spayd, settings-migration, proforma]

requires:
  - phase: 10-invoicing
    provides: "plan 10-03 (shared lang/cs/kokpit.php edits)"
  - phase: 03-settings
    provides: "NumberPattern grammar, DocumentKind, NumberingSettings, Settings page numbering section"
provides:
  - "DocumentKind::carriesVariableSymbol() (Invoice and Proforma)"
  - "NumberPattern digits-only and 10-character rule for invoice and proforma; credit note stays free"
  - "Refusal reasons proforma_digits and proforma_length with Czech messages that name the variable symbol"
  - "Proforma default pattern {YYYY}9{NNNN} and the guarded settings migration 2026_10_17_000200_distinct_proforma_number_pattern"
  - "App\\Domain\\Invoicing\\Support\\VariableSymbol::fromNumber(DocumentKind, string): ?string"
affects: [10-12, 10-21, 10-32]

actuals:
  tokens: 7400
  tasks: 2
  commits: 2
plan_head_before: 4dd42f8d68c2e7f5fa59c7045e4ede2a9c7b35f0
plan_head_after: 38b1377357dd50deb2b182c0c1510d72d428bddc
commits: 2

coverage:
  - deliverable: "The Settings page refuses a proforma pattern with letters, separators or more than 10 characters with a Czech message that names the variable symbol, and stores nothing"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/NumberingPageTest.php#names the variable symbol in the Czech refusal of a proforma pattern"
        status: pass
      - kind: test
        ref: "tests/Unit/Numbering/NumberPatternTest.php#refuses a pattern with the named reason"
        status: pass
    human_judgment: false
  - deliverable: "A fresh install numbers its first proforma {YYYY}9{NNNN}, so its variable symbol differs from an invoice's under the default patterns"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/NumberingTest.php#numbers the first proforma of a fresh install so that its variable symbol differs from the invoice one"
        status: pass
    human_judgment: false
  - deliverable: "The settings migration moves only the unchanged old proforma default, and only while no proforma counter row exists; a custom pattern is left as stored"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/NumberingTest.php#moves the unchanged old proforma default / keeps the old proforma default once a proforma number was handed out / keeps a custom proforma pattern"
        status: pass
    human_judgment: false
  - deliverable: "VariableSymbol::fromNumber() returns the number for an invoice or proforma (1 to 10 digits), null for a credit note, and throws for anything else"
    verification:
      - kind: test
        ref: "tests/Unit/Invoicing/VariableSymbolTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "A kind capability on the enum (carriesVariableSymbol) instead of kind comparisons scattered through the parser"
    - "Guarded settings migration: replace only the unchanged old default and only while the counter table shows the series was never used"

key-files:
  created:
    - database/settings/2026_10_17_000200_distinct_proforma_number_pattern.php
    - app/Domain/Invoicing/Support/VariableSymbol.php
    - tests/Unit/Invoicing/VariableSymbolTest.php
  modified:
    - app/Domain/Settings/Numbering/DocumentKind.php
    - app/Domain/Settings/Numbering/NumberPattern.php
    - app/Domain/Settings/Numbering/InvalidNumberPattern.php
    - lang/cs/kokpit.php
    - tests/Unit/Numbering/NumberPatternTest.php
    - tests/Feature/Operations/NumberingTest.php
    - tests/Feature/Operations/NumberingPageTest.php

key-decisions:
  - "R-07-1: digits only and at most 10 characters for invoice and proforma; the credit-note pattern stays free (narrows Phase 3 D-05 for proformas, owner acknowledgement pending, see below)"
  - "R-07-2: the variable symbol is the document number itself; a credit note gets none; a task is a LogicException"
  - "R-07-3: proforma default {YYYY}9{NNNN}; the settings migration switches only the unchanged old default while no proforma: row exists in number_sequences"
  - "NumberPattern::format() applies the 10-character overflow guard to proformas too, because their number is a variable symbol as well"

patterns-established:
  - "Existing test cases for letters or separators in a proforma pattern move to DocumentKind::CreditNote (the letter check fires before the literal check, so a literal-refusal case for a proforma must start with a digit)"

requirements-completed: [IN-01]

duration: 30 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 07: Number series that can serve as variable symbols Summary

**Invoice and proforma numbers are now digits-only and at most 10 characters (a valid SPAYD variable symbol), a proforma defaults to `{YYYY}9{NNNN}` so its symbol never equals an invoice's, and `VariableSymbol::fromNumber()` is the single place that derives the symbol.**

## Performance

- **Duration:** about 30 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 3 created, 7 modified

## Accomplishments

- `DocumentKind::carriesVariableSymbol()` is true for `Invoice` and `Proforma`. `NumberPattern::parse()` uses it for the digits-only and the 10-character checks and raises `invoice_digits` / `invoice_length` for invoices and the new `proforma_digits` / `proforma_length` for proformas. A credit-note pattern keeps the free grammar.
- The Czech messages for the two new reasons name the variable symbol; the Settings page shows them under `data.numbering.proforma_pattern` and stores nothing.
- `DocumentKind::defaultPattern()` returns `{YYYY}9{NNNN}` for a proforma. A fresh install therefore numbers its first proforma 2026 + 9 + 0001 while the first invoice is 2026 + 0001.
- Settings migration `2026_10_17_000200_distinct_proforma_number_pattern` moves an existing installation to the new default only when the stored pattern is still the old default and no `proforma:` counter row exists; any custom pattern, including one the new rule would refuse, is left as stored (the Settings page shows the field error on the next save; plan 10-12 turns it into a named pre-issue blocker).
- `VariableSymbol::fromNumber()` returns the number for an invoice or proforma, `null` for a credit note, and throws `LogicException` for a number that is not 1 to 10 digits (letter, eleven digits, empty, trailing newline, space) and for a task.

## Task Commits

1. **Task 1 (tracer): digits-only rule for proformas, distinct proforma default, Czech messages** - `7ad697a` (feat)
2. **Task 2: guarded settings migration and VariableSymbol** - `38b1377` (feat)

## Verification

- Targeted: `ddev exec vendor/bin/pest tests/Unit/Numbering tests/Feature/Operations/NumberingTest.php tests/Feature/Operations/NumberingPageTest.php` 131 passed; `tests/Unit/Invoicing/VariableSymbolTest.php tests/Feature/Operations` 393 passed.
- Whole suite: `ddev exec php -d memory_limit=1G vendor/bin/pest` 5212 passed, 3 skipped, 0 failed.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.
- Acceptance greps of both tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Overflow guard of `NumberPattern::format()` extended to proformas**
- **Found during:** Task 1
- **Issue:** the plan narrows the pattern rule, but `format()` still threw `OverflowException` only for invoices. A proforma counter that outgrew 10 characters would have produced an 11-digit number that `VariableSymbol` and the QR refuse at issue time.
- **Fix:** `format()` uses `carriesVariableSymbol()`; a unit test covers 99999 passing and 100000 throwing for the default proforma pattern.
- **Files modified:** app/Domain/Settings/Numbering/NumberPattern.php, tests/Unit/Numbering/NumberPatternTest.php
- **Commit:** 7ad697a

**2. [Rule 1 - Test adjustment, planned by the plan] Existing proforma test cases moved**
- **Found during:** Task 1
- **Issue:** cases such as `ZF-{YYYY}/{NNNN}`, `ZF {N}`, `ZF;...`, `ZF}{N}` for a proforma now hit `proforma_digits` before `literal`.
- **Fix:** each case kept and moved to `DocumentKind::CreditNote`; a digit-led proforma case (`{YYYY} {N}`) keeps the `literal` refusal covered. The default-preview, default-counter and "saves all three patterns" tests use digits-only proforma patterns or the new default.
- **Files modified:** the three numbering test files
- **Commit:** 7ad697a, 38b1377

**Total deviations:** 2 auto-fixed (1 missing critical, 1 planned test adjustment). **Impact:** none on scope.

## Owner acknowledgement point

Phase 3 D-05 (editable number patterns per document kind) is narrowed for proformas: the Admin can no longer type letters or separators in the proforma pattern, because the proforma number is the variable symbol of its SPAYD QR (D-21). Ruling R-07-1; the alternative (derive the symbol by stripping separators) would change only `VariableSymbol::fromNumber()` and R-07-2. To be acknowledged by the owner; plan 10-21 amends UI-SPEC F-15 and plan 10-32 writes the Phase 11 hand-over (R-07-4: match a payment by variable symbol scoped by document kind; the docblock of `VariableSymbol` already says so).

## Known Stubs

None.

## Threat Flags

None. T-10-19 and T-10-20 are mitigated as planned (digits-only and length rule, distinct default, throwing helper, guarded migration with tests for all three cases). T-10-SC: no Composer change.

## Issues Encountered

None.

## Next Phase Readiness

Ready for plan 10-08. Plan 10-12 can call `VariableSymbol::fromNumber()` at issue and turn a stored custom pattern the new rule refuses into a pre-issue blocker.

## Self-Check: PASSED

- Created files exist: VariableSymbol.php, 2026_10_17_000200_distinct_proforma_number_pattern.php, VariableSymbolTest.php.
- Commits 7ad697a and 38b1377 are ancestors of HEAD.
