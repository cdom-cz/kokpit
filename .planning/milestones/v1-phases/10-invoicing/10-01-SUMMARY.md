---
phase: 10-invoicing
plan: 01
subsystem: money
tags: [money, brick-math, rounding, invoicing, cnb-conversion]

requires:
  - phase: 02-foundation
    provides: "Money value object with the single HALF_UP rounding point (fromExactMinor)"
  - phase: 08-reports
    provides: "ExchangeRateConverter quote shape (rate string, unit amount) the conversions are built for"
provides:
  - "InvoiceQuantity value object: ten-thousandths of a unit, the one half-up rounding of exact seconds"
  - "Money::times(), negated(), isNegative(), isPositive(), compareTo()"
  - "Money::convertThroughCzk(): exact conversion between any two CNB currencies, rounded once"
  - "Money::timesThroughCzk(): converted line amount rounded exactly once (D-09, ruling R-01-1)"
affects: [10-02, 10-04, invoice-items-check, invoice-draft, invoice-issue, credit-notes, invoice-pdf]

actuals:
  tokens: 5967
  tasks: 2
  commits: 2
plan_head_before: 18cd0d923bf44af2a3e86932af667e7c9f42612e
plan_head_after: 5a7081d94439a76b6d8913c9d6f80cbdcd77627c
commits: 2

tech-stack:
  added: []
  patterns:
    - "Quantity rounding is integer arithmetic outside Money; money rounding stays in Money::fromExactMinor"
    - "Every Money operation builds one exact BigRational and rounds it once"

key-files:
  created:
    - app/Domain/Invoicing/Support/InvoiceQuantity.php
    - tests/Unit/Invoicing/InvoiceQuantityTest.php
    - tests/Unit/Money/MoneyInvoiceArithmeticTest.php
  modified:
    - app/Domain/Shared/Money/Money.php

key-decisions:
  - "R-01-1: a converted line amount is rounded HALF_UP once from the exact conversion of the source price; the printed converted unit price is a separately rounded display figure"
  - "R-01-2: InvoiceQuantity::fromSeconds rounds with intdiv(50 * seconds + 9, 18), keeping Brick inside Money"
  - "R-01-3: Money::times takes the canonical decimal text of a quantity, so Shared does not depend on Invoicing"
  - "InvoiceQuantity bounds its value to ten integer digits plus four decimals and throws OverflowException beyond that"

patterns-established:
  - "Line total = Money::times(InvoiceQuantity::toDecimal()); converted line = Money::timesThroughCzk(...)"

requirements-completed: [IN-02, IN-07]

duration: 14min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 01: Invoice arithmetic Summary

**Quantity value object (45305 s is 12,5847 h) and Money methods for quantity times price, credit-note signs and CNB cross-currency conversion, with every amount rounded exactly once.**

## Performance

- **Duration:** 14 min
- **Tasks:** 2
- **Files:** 4 (1 modified, 3 created)

## Accomplishments

- `InvoiceQuantity` (final readonly, `int $tenThousandths`): `fromSeconds` (the single quantity rounding point, half-up integer arithmetic), `fromDecimal` (comma or point, never rounds, refuses more than four decimals), `toDecimal`, `format('cs'|'en')`, `plus`, `minus`, `negated`, `isZero`, `isPositive`, `equals`, `compareTo`.
- `Money::times()`: exact product through `fromExactMinor`; 12,5847 h at 1 500,00 Kc gives 18 877,05 Kc, half away from zero for negative quantities, matching PostgreSQL `round(numeric)`.
- `Money::negated()`, `isNegative()`, `isPositive()`, `compareTo()` for credit notes and remaining amounts (negation of the smallest integer throws `OverflowException`).
- `Money::convertThroughCzk()`: both CNB quotes as one exact rational, rounded once; equals `convert()` for a CZK target; handles JPY (no fraction digits) and 100-unit quotes.
- `Money::timesThroughCzk()`: converted line amount rounded once. 150000 minor CZK x 10 into EUR at 25.125 is 59701, while the display unit price is 5970 (and 5970 x 10 = 59700 is the second-rounding result that is explicitly not produced). A full negative credit line cancels the original exactly.

## Task Commits

1. **Task 1 (tracer): quantity from exact seconds and Money::times** - `1de0fb0` (feat)
2. **Task 2: signs, comparison, cross conversion, once-rounded converted line** - `5a7081d` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Unit tests/Arch/MoneyBoundaryTest.php tests/Feature/Money`: 691 passed
- `ddev exec vendor/bin/pest tests/Arch`: 63 passed (MoneyBoundaryTest unchanged and green)
- Pint and PHPStan clean; `scripts/check-sensitive.sh` and gitleaks clean on both commits
- All acceptance criteria of both tasks pass (greps and test runs)
- Tracer gate: the tracer `<verify>` (automated only) was re-run after Task 1 and passed before Task 2 started

## Deviations from Plan

None - plan executed exactly as written. Minor additions inside the planned surface: `InvoiceQuantity::equals()` (used by the tests, trivial companion of `compareTo`), and a private `validCrossConversion()` / `convertedThroughCzkExact()` pair in `Money` instead of refactoring the existing `convertedExact()`, which leaves `convert()` and `forDurationConverted()` byte-for-byte untouched.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. T-10-01 (second rounding site) and T-10-02 (malformed factor or rate) are mitigated: `RoundingMode` still appears only in `fromExactMinor` (arch test green), factors and quantities are matched by strict regexes, both quotes pass `validConversion()`, and overflow raises `OverflowException`.

## Next Phase Readiness

Ready for 10-02. The schema plan (10-04) can pin `round(quantity * unit_price_minor)` against `Money::times()` and record the converted-line display-versus-amount consequence of ruling R-01-1.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Support/InvoiceQuantity.php
- FOUND: tests/Unit/Invoicing/InvoiceQuantityTest.php
- FOUND: tests/Unit/Money/MoneyInvoiceArithmeticTest.php
- FOUND commit 1de0fb0, FOUND commit 5a7081d
