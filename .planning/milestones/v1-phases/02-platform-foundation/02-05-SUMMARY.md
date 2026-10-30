---
phase: 02-platform-foundation
plan: 05
subsystem: money
tags: [money, brick-money, brick-math, rounding, eloquent-cast, pest-arch, postgresql-18]

requires:
  - phase: 02-platform-foundation
    provides: brick/money and brick/math locked in 02-01, KokpitModel (02-03), Pest harness on kokpit_test (02-02)
provides:
  - App\Domain\Shared\Money\Money, a final readonly value object of integer minor units plus an ISO 4217 code with one HALF_UP rounding point
  - App\Domain\Shared\Money\MoneyCast, the two-column Eloquent cast over <name>_minor bigint and <name>_currency char(3)
  - Column convention with a CHECK on the upper-case currency and numeric(20,10) rates with the decimal:10 cast
  - Pest Arch suite (tests/Arch) registered in phpunit.xml and tests/Pest.php, with the Money boundary tests
  - MoneyProbe test model that provisions its money table inside the test
affects: [02-06, phase-06, phase-08, phase-10, phase-11]

actuals:
  tokens: 6660
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "No Brick type in a public signature; Brick is imported only inside App\\Domain\\Shared\\Money and RoundingMode only by Money"
    - "Exact amounts travel as decimal or rational strings into Money::fromExactMinor, the only rounding point"
    - "Rates and exchange rates are strings validated by ^-?\\d+(\\.\\d{1,10})?$, never floats"
    - "Money columns: <name>_minor bigint plus <name>_currency char(3) with CHECK (<name>_currency ~ '^[A-Z]{3}$')"
    - "Pest arch targets use full namespaces (Brick\\Math, Brick\\Money); a bare Brick target passes vacuously"

key-files:
  created:
    - app/Domain/Shared/Money/Money.php
    - app/Domain/Shared/Money/MoneyCast.php
    - tests/Support/Probes/MoneyProbe.php
    - tests/Unit/Money/MoneyTest.php
    - tests/Feature/Money/MoneyCastTest.php
    - tests/Arch/MoneyBoundaryTest.php
  modified:
    - phpunit.xml
    - tests/Pest.php

key-decisions:
  - "Money::fromExactMinor(string, string) is the single rounding point (HALF_UP, half away from zero); forDurations and convert hand it an exact rational or decimal string, so no Brick type appears in a public signature"
  - "Whole seconds are the duration unit (A-OQ2 confirmed in code): forDuration rounds rate_minor * seconds / 3600 once per line"
  - "Brick exceptions never leak: invalid currency, rate, locale or number text throw InvalidArgumentException; integer overflow throws OverflowException"
  - "A negative duration is rejected in forDurations (InvalidArgumentException), not left to produce a negative amount silently"
  - "Pest arch confinement of Brick names Brick\\Math and Brick\\Money explicitly, because expect('Brick') matched nothing and passed even with a violating class present"

patterns-established:
  - "Value object over a library: private constructor, string-typed exact inputs, one documented rounding method, arch test plus token scan that keep it that way"
  - "Test-only money host: MoneyProbe::provision() creates the table with the money column convention inside the test transaction"

requirements-completed: [FND-04]

coverage:
  - id: D1
    description: "Money is a final readonly value object built only through ofMinor or zero; unknown, lower-case, empty or over-long currency codes throw"
    requirement: "FND-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#builds an amount from minor units and an ISO currency, #rejects a malformed or unknown currency code"
        status: pass
      - kind: other
        ref: "tests/Arch/MoneyBoundaryTest.php#Money is a final readonly value object"
        status: pass
    human_judgment: false
  - id: D2
    description: "Durations are exact: 1234.56 CZK/h for 4020 s is 137859 minor, 3 minor/h for 1800 s is 2, three 29 minute parts give 145 on one line while rounding each part gives 144, mixed rates round once over the exact sum"
    requirement: "FND-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#computes 1234.56 CZK per hour for 4020 seconds as 137859 minor units, #rounds once per line, #rounds once over the exact sum of parts with different rates"
        status: pass
    human_judgment: false
  - id: D3
    description: "fromExactMinor rounds 0.5 to 1, 0.49 to 0, -0.5 to -1 and 1/3 to 0; plus, minus and fromExactMinor throw at the integer boundary instead of wrapping; mixed currencies throw"
    requirement: "FND-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#rounds half away from zero at the single rounding point, #throws instead of wrapping at the integer boundary, #refuses to add or subtract money in different currencies"
        status: pass
    human_judgment: false
  - id: D4
    description: "convert multiplies by a NUMERIC(20,10) decimal string exactly: 100.00 EUR at 24.4050000000 gives 244050 minor CZK, a JPY amount quoted per 100 units converts, a decimal comma, an eleven-digit or exponent rate and a float are rejected"
    requirement: "FND-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#converts 100.00 EUR at 24.4050000000 to 244050 minor CZK, #converts a JPY amount at a rate quoted per 100 units, #rejects a rate with a decimal comma, too many digits or a float, #rejects a float rate"
        status: pass
    human_judgment: false
  - id: D5
    description: "MoneyCast round-trips an amount through amount_minor and amount_currency on PostgreSQL, stores no amount as two nulls, a CHECK rejects a lower-case currency with SQLSTATE 23514, and a decimal:10 rate reads back as a string"
    requirement: "FND-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Money/MoneyCastTest.php (all six cases)"
        status: pass
    human_judgment: false
  - id: D6
    description: "Brick is imported only inside App\\Domain\\Shared\\Money and Brick\\Math\\RoundingMode is referenced only by Money, and only inside the body of fromExactMinor"
    requirement: "FND-04"
    verification:
      - kind: other
        ref: "tests/Arch/MoneyBoundaryTest.php (Brick confinement, RoundingMode confinement, token scan of fromExactMinor)"
        status: pass
    human_judgment: false
  - id: D7
    description: "format('cs') gives '1 234,50 Kč' and '0,01 Kč' (after normalising non-breaking spaces); jsonSerialize returns minor and currency with no float"
    requirement: "FND-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Money/MoneyTest.php#formats Czech amounts, #serialises to minor units and currency without a float"
        status: pass
    human_judgment: false

duration: 5min
completed: 2026-10-07
status: complete
commits: 3
plan_head_before: c23c2a5acbe714404fff583290dc295761255747
plan_head_after: 24492268f396f0d8fa8d9b6a54da2a10b06a8f74
---

# Phase 2 Plan 05: Money value object Summary

**A final readonly `Money` of integer minor units plus ISO 4217 code over brick/money, with `fromExactMinor` as the single HALF_UP rounding point (once per invoice line), exact string-rate conversion, a two-column `MoneyCast` proven on PostgreSQL, and arch tests that confine Brick and `RoundingMode` to the one class.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-07T15:31:56Z
- **Completed:** 2026-10-07T15:37:18Z
- **Tasks:** 2 of 2
- **Files modified:** 8 changed between plan start and end (6 created, 2 modified)

## Accomplishments

- `Money` has a private constructor and is built only through `ofMinor` or `zero`; the currency must be an upper-case ISO 4217 code known to Brick, otherwise `InvalidArgumentException`. No method takes or returns a float and no Brick type is in a public signature.
- `Money::fromExactMinor` is the only method that rounds. Everything feeding it is exact: `forDurations` sums `rate_minor * seconds / 3600` as a rational and passes its string form, `convert` multiplies minor units by the string rate and divides by the quoted unit amount as a rational. The lab values from research hold: 137859, 2, 144 versus 145, 244050, and a JPY amount quoted per 100 units.
- `MoneyCast` reads and writes `<key>_minor` and `<key>_currency`; both null means no amount, exactly one null or a non-`Money` value throws. `MoneyProbe` proves the round trip, the null case, reassignment, the `23514` CHECK violation inside a savepoint, and the `decimal:10` rate string `0.0166666667`.
- The `Arch` Pest suite exists (registered in `phpunit.xml` and `tests/Pest.php`) with four tests: Brick confinement, `RoundingMode` confinement, `Money` final and readonly, and a `PhpToken` scan proving every `RoundingMode::` occurrence sits in the body of `fromExactMinor`. Mutation proof: adding a Brick import in `app/Domain/Shared/Models`, a `RoundingMode` use in a second Money-namespace class and a `RoundingMode::` in another method each made the matching test fail; the temporary files were removed.

## Exact public API of `Money` (as implemented)

`App\Domain\Shared\Money\Money`: `final readonly class Money implements JsonSerializable`

| Member | Signature | Notes |
|---|---|---|
| property | `public int $minor` | integer minor units |
| property | `public string $currency` | upper-case ISO 4217 code |
| constructor | `private __construct(int $minor, string $currency)` | not callable from outside |
| `ofMinor` | `static ofMinor(int $minor, string $currency): self` | `InvalidArgumentException` for a malformed or unknown code |
| `zero` | `static zero(string $currency): self` | same validation |
| `plus` | `plus(self $other): self` | `InvalidArgumentException` on currency mismatch, `OverflowException` past the int range |
| `minus` | `minus(self $other): self` | same |
| `equals` | `equals(self $other): bool` | minor and currency |
| `isZero` | `isZero(): bool` | |
| `fromExactMinor` | `static fromExactMinor(string $exactMinor, string $currency): self` | THE rounding point, HALF_UP; accepts a decimal ("137859.2") or rational ("1/3") string; `InvalidArgumentException` for other text, `OverflowException` when the rounded value does not fit |
| `forDuration` | `static forDuration(self $hourlyRate, int $seconds): self` | delegates to `forDurations` with the rate's currency |
| `forDurations` | `static forDurations(string $currency, iterable $parts): self` | `$parts` are `[Money $hourlyRate, int $seconds]` pairs; exact sum, one rounding; `InvalidArgumentException` for a part in another currency or negative seconds |
| `convert` | `convert(string $decimalRate, string $toCurrency, int $unitAmount = 1): self` | rate must match `^-?\d+(\.\d{1,10})?$` (no comma, exponent or spaces), `$unitAmount >= 1`, each currency's default fraction digits decide the minor unit |
| `format` | `format(string $locale): string` | Brick locale formatter (intl); `InvalidArgumentException` for an unknown locale |
| `jsonSerialize` | `jsonSerialize(): array{minor: int, currency: string}` | |

`App\Domain\Shared\Money\MoneyCast` (`final class implements CastsAttributes<Money|null, mixed>`): `get(Model, string $key, mixed $value, array $attributes): ?Money`, `set(Model, string $key, mixed $value, array $attributes): array`.

## Task Commits

1. **Task 1: Tracer, a duration-based amount rounded once, persisted through MoneyCast and read back equal** - `03a7cb9` (feat)
2. **Task 2 RED: failing tests for line sums, conversion, formatting and the Brick boundary** - `4a8adaa` (test)
3. **Task 2 GREEN: exact line sums, string-rate conversion, Czech formatting, enforced boundary** - `2449226` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

Tracer feedback gate: auto mode was active (`_auto_chain_active`); the tracer `<verify>` (16 Money tests, Pint clean, Larastan `[OK]`, full suite 83 passed) passed on the committed tree, so execution expanded.

## TDD Gate Compliance

Task 2 (`tdd="true"`): RED commit `4a8adaa` precedes GREEN commit `2449226`. No REFACTOR commit was needed.

- **RED:** 15 failed, 24 passed (Unit and Arch). The target failures were the planned missing behaviors: `Call to undefined method Money::convert()` and `Money::format()` and `Money::jsonSerialize()` (not yet implemented), plus "exception InvalidArgumentException is not thrown" for a duration part in another currency and for a negative duration. The tests for plus, minus, overflow, currency validation and the half-up rounding point passed at RED because Task 1 (the tracer) already shipped that behavior; they stay as regression coverage.
- **Semantic assessment:** every failure was the planned behavior missing, not a syntax, discovery or fixture fault. The four Arch tests passed at RED; the Brick confinement one passed vacuously (see Deviation 1), which was found and fixed in GREEN.
- **GREEN:** full suite 112 passed (1272 assertions); `composer lint` and `composer stan` clean.
- The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` is not enabled and Pest's console output is not one of its supported report formats (as in 02-02 to 02-04).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Arch expectation `expect('Brick')` matched nothing and passed vacuously**
- **Found during:** Task 2 (mutation check of the arch tests)
- **Issue:** the plan's `arch()->expect('Brick')->toOnlyBeUsedIn('App\Domain\Shared\Money')` stayed green with a class in `App\Domain\Shared\Models` importing `Brick\Math\BigInteger`; Pest does not treat a single-segment target as a namespace prefix, so T-02-16's library confinement was not enforced.
- **Fix:** the target is `['Brick\Math', 'Brick\Money']` (the two vendor namespaces in use); the same mutation now fails the test. A comment records why.
- **Files modified:** `tests/Arch/MoneyBoundaryTest.php`
- **Verification:** mutation run failed the Brick test, clean run passes (4 passed).
- **Committed in:** `2449226`

**2. [Rule 2 - Missing critical] Negative durations rejected**
- **Found during:** Task 2 (`forDurations` validation)
- **Issue:** the plan lists currency validation but nothing stops a negative number of seconds from producing a negative line amount silently, which is the kind of plausible wrong total this plan exists to prevent.
- **Fix:** `forDurations` (and so `forDuration`) throws `InvalidArgumentException` for negative seconds; covered by a test added in the RED commit.
- **Files modified:** `app/Domain/Shared/Money/Money.php`, `tests/Unit/Money/MoneyTest.php`
- **Committed in:** `4a8adaa` (test), `2449226` (code)

**3. [Rule 1 - Bug] PHPStan `instanceof.alwaysTrue` in `MoneyCast::set`**
- **Found during:** Task 1
- **Issue:** with `CastsAttributes<Money|null, Money|null>` the runtime guard `! $value instanceof Money` is always true to PHPStan.
- **Fix:** the settable generic is `mixed` (`CastsAttributes<Money|null, mixed>`), which describes the runtime guard honestly; Pint strips a `@param mixed` override, so the generic is the stable fix.
- **Files modified:** `app/Domain/Shared/Money/MoneyCast.php`
- **Committed in:** `03a7cb9`

### Plan-reading notes (not deviations)

- The plan lists `forDurations` validation and `jsonSerialize`, `convert`, `format` under Task 2 and the rest of `Money` under Task 1; the commits follow that split, so the tracer commit has `forDurations` without its currency check until `2449226`.
- `validCurrency` and the rate pattern end with the `D` modifier so a trailing newline cannot slip past `$`.
- `plus`, `minus` and `fromExactMinor` throw `OverflowException` (PHP) rather than a Brick exception, so callers never depend on Brick.

---

**Total deviations:** 3 auto-fixed (2 bugs, 1 missing critical)
**Impact on plan:** None on scope. Deviation 1 matters for later plans: any future arch expectation over a vendor namespace must use a full namespace and be mutation-checked.

## Issues Encountered

- A first mutation check was invalid because a temporary file from the previous step was still on disk; the check was repeated from a clean tree, which is how the vacuous `expect('Brick')` was identified.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-16 (implicit or repeated rounding, float arithmetic): one rounding method, token scan and arch test confine it, rates are strict strings, Brick's `Unnecessary` default stays in force everywhere else. T-02-17 (cross-currency arithmetic): `plus`, `minus`, `forDurations` throw, tested. T-02-18 (integer overflow): overflow throws `OverflowException`, tested at `PHP_INT_MAX` and `PHP_INT_MIN`.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Later plans and phases import `App\Domain\Shared\Money\Money` and add `<name>_minor` plus `<name>_currency` columns with the CHECK; they never import Brick (the arch test fails the suite if they do).
- Rates and exchange rates are `numeric(20,10)` with the `decimal:10` cast and are passed to `convert` as strings.
- `format()` needs the `intl` extension (present in the DDEV image); the output uses non-breaking spaces, so UI tests normalise U+00A0 and U+202F.
- `ddev composer test` (112 passed), `lint` and `stan` are green; DDEV containers are left running.

## Self-Check: PASSED

- Created files exist: `Money.php`, `MoneyCast.php`, `MoneyProbe.php`, `MoneyTest.php`, `MoneyCastTest.php`, `MoneyBoundaryTest.php`; `phpunit.xml` has the Arch suite and `tests/Pest.php` registers it.
- Commits `03a7cb9`, `4a8adaa`, `2449226` are ancestors of HEAD; `git rev-list --count c23c2a5..HEAD` is 3.
- All acceptance criteria of Tasks 1 and 2 re-run and passing; `grep -rl 'RoundingMode' app` prints only `app/Domain/Shared/Money/Money.php`; `ddev composer test`, `lint`, `stan` and `scripts/check-sensitive.sh` clean.
