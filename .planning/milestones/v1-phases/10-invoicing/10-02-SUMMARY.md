---
phase: 10-invoicing
plan: 02
subsystem: invoicing-pdf
tags: [spayd, qr, php-qrcode, payment-qr, composer]

requires:
  - phase: 03-operations
    provides: "Iban validator (mod 97, 32 countries) and the one-account-per-currency rule"
  - phase: 02-foundation
    provides: "Money value object (toMajor, isPositive)"
provides:
  - "Spayd::supports() and Spayd::forPayment(): SPAYD 1.0 payload for a CZK amount and a Czech IBAN"
  - "Spayd::sanitiseMessage(): upper-case ASCII message, asterisk replaced, cut to 60"
  - "QrPng::dataUri(): offline QR PNG data URI (level M, Version::AUTO, quiet zone 4)"
  - "chillerlan/php-qrcode as a direct ^5.0.5 requirement"
  - "Tests\\Support\\FictionalIban and Tests\\Support\\QrDecode test helpers"
affects: [10-15, 10-18, invoice-pdf, proforma-pdf]

actuals:
  tokens: 3924
  tasks: 2
  commits: 2
plan_head_before: 56cdd3808f102547a352a0f42cd8ad0967be9a8f
plan_head_after: 04a739ac3b0734b3fe88d8a89f17e2a321d79c0f
commits: 2

tech-stack:
  added: ["chillerlan/php-qrcode ^5.0.5 (already locked as a Filament dependency, now direct)"]
  patterns:
    - "Pure static helper that refuses malformed input with InvalidArgumentException"
    - "Test IBANs composed at runtime through Iban::compose from a repeated digit fragment"

key-files:
  created:
    - app/Domain/Invoicing/Pdf/Spayd.php
    - app/Domain/Invoicing/Pdf/QrPng.php
    - tests/Support/FictionalIban.php
    - tests/Support/QrDecode.php
    - tests/Unit/Invoicing/QrPngTest.php
    - tests/Unit/Invoicing/SpaydTest.php
  modified:
    - composer.json
    - composer.lock

key-decisions:
  - "R-02-1: a payment QR exists only for CZK and a well-formed Czech IBAN; Spayd::supports() lets the PDF plan decide whether to print it and forPayment() throws otherwise"
  - "R-02-2: the caller passes the message text, Spayd only sanitises it"
  - "R-02-3: chillerlan/php-qrcode pinned ^5.0.5; the lock diff is the content hash only, no package added"
  - "forPayment() also refuses a zero amount (nothing to pay), in addition to a negative one"
  - "Message sanitising strips combining marks via Normalizer NFKD before iconv, because glibc iconv ASCII//TRANSLIT answers a question mark for every diacritic under the C locale"

patterns-established:
  - "Payment QR flow: Spayd::supports() -> Spayd::forPayment() -> QrPng::dataUri()"

requirements-completed: [IN-04]

duration: 8 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 02: SPAYD payment QR Summary

**SPAYD 1.0 payload builder for CZK and a Czech IBAN plus a local QR PNG renderer on the already-locked chillerlan/php-qrcode 5.0.5, with a decode round-trip proving the image carries the exact string.**

## Accomplishments

- `Spayd::forPayment(iban, Money, variableSymbol, dueDate, message)` builds `SPD*1.0*ACC:..*AM:..*CC:CZK*X-VS:..*DT:YYYYMMDD*MSG:..`. Refuses a non-CZK amount, a non-Czech or checksum-invalid IBAN, a non-positive amount or one over ten characters, a variable symbol outside 1 to 10 digits (including a trailing newline) and a due date that is not a calendar date.
- The message is reduced to upper-case ASCII (digits, letters, space and `$%+-./:`), an asterisk becomes a space, whitespace is collapsed and the text is cut to 60. An injected `*ACC:...*AM:...` fragment cannot add a key (T-10-03).
- `QrPng::dataUri()` renders locally with `Version::AUTO` (a fixed version overflows), `EccLevel::M`, GD PNG, base64, scale 6, quiet zone 4; no remote request.
- The longest payload (60-character message, 10-digit symbol, 9 999 999,99 Kc) renders and decodes without a length overflow.
- `FictionalIban` composes valid IBANs at runtime (`czech()`, `of($country)`); `QrDecode::payload()` reads a data URI back through the library reader. No IBAN or 8-digit literal appears in any test.
- `chillerlan/php-qrcode` is now a direct requirement `^5.0.5`; `composer.lock` changed only in its content hash and `composer check-licenses` passes (225 packages).

## Task Commits

1. **Task 1 (tracer): SPAYD string, QR PNG and decode round-trip** - `578cb0e` (feat)
2. **Task 2: SPAYD limits tested, php-qrcode pinned** - `04a739a` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Unit/Invoicing` 45 passed; full `tests/Unit` 707 passed.
- Pint clean, PHPStan no errors, `ddev composer check-licenses` clean, `scripts/check-sensitive.sh` clean on both commits (hooks ran, gitleaks clean).
- Acceptance greps (`Version::AUTO`, `EccLevel::M`, `SPD\*1.0\*`, `function czech`, `"chillerlan/php-qrcode": "^5.0.5"`, `ASCII//TRANSLIT`) all succeed; the `composer.lock` diff is one line.
- Tracer gate: tracer `<verify>` passed end-to-end before expansion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] glibc iconv returns question marks for diacritics under the C locale**
- **Found during:** Task 1 (probing `iconv('UTF-8', 'ASCII//TRANSLIT')` inside DDEV)
- **Issue:** the plan's transliteration alone turns "Zálohová" into "Z?lohov?" in a PHP process running with the C locale, which would silently corrupt the QR message.
- **Fix:** strip combining marks after `Normalizer` NFKD first, then run `iconv(..., 'ASCII//TRANSLIT//IGNORE')` for leftovers, then replace anything outside the safe set with a space.
- **Files modified:** `app/Domain/Invoicing/Pdf/Spayd.php`
- **Commit:** `578cb0e`

**2. [Rule 2 - Missing critical] Zero amount refused**
- **Issue:** the plan names a negative amount only; a zero-amount payment instruction is meaningless.
- **Fix:** `forPayment()` requires a positive amount (tested). The PDF plan must not call it for a zero-total document.
- **Commit:** `04a739a`

**Total deviations:** 2 auto-fixed (1 bug, 1 missing validation). **Impact:** none on scope; both strengthen T-10-03 and T-10-04.

Note: in Task 1 the implementation was written in the same step as the test, so no separate RED run was recorded; the test would fail without the classes. Task 2's tests passed on first run because the validation was already complete from Task 1.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; T-10-03, T-10-04 and T-10-SC are mitigated as planned.

## Next Phase Readiness

Plans 10-15 and 10-18 can call `Spayd::supports($currency, $iban)` and, when true and the total is positive, `QrPng::dataUri(Spayd::forPayment(...))`.

## Self-Check: PASSED

All six created files exist, both commits (`578cb0e`, `04a739a`) are ancestors of HEAD, and `git rev-list --count` over the plan ledger gives 2.
