---
phase: 14-online-payment-method-and-invoice-currency
plan: 13
subsystem: invoicing
tags: [payments, card-switch, issued-editor, gap-closure, wr-01]

requires:
  - phase: 14-online-payment-method-and-invoice-currency (plans 01 to 12)
    provides: DocumentPayment::forIssued(), onlineUnavailableReason(), CardPaymentSwitch::available(), the link lifecycle in UpdateIssuedDocument
provides:
  - DocumentPayment::issuedSwitch() guards every result that turns the card switch on or keeps it on after a change from online
  - the online branch keeps a stored switch and turns a stored-off one on only while card payment is available to the document
  - four regression cases of WR-01 (two files)
affects: [phase-14]

plan_head_before: f4824ccbf7c5aa52a6bd268a6539e57b321dfb69
plan_head_after: 213aae6b12e46f0a5447f2182cc04effbaa3d030

actuals:
  tokens: 1500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A derived value that would turn a capability on degrades to off when the capability is unavailable; an explicit request for it is refused"

key-files:
  created: []
  modified:
    - app/Domain/Invoicing/Support/DocumentPayment.php
    - tests/Feature/Payments/LinkLifecycleTest.php
    - tests/Feature/Invoicing/IssuedHeaderEditTest.php

key-decisions:
  - "R-14-13-1: transfer branch of issuedSwitch() checks availability when the result is on and either the stored switch was off or the stored method was online; explicit true is refused, a derived value falls back to false"
  - "R-14-13-2: online branch returns storedSwitch || onlineUnavailableReason() === null, so the client flag counts too (P14-09), instead of CardPaymentSwitch::available() proposed in 14-REVIEW.md"

requirements-completed: [P14-07, P14-09]

coverage:
  - id: D1
    description: "An open issued online document switched back to transfer while the global setting is off or Stripe is missing ends with the switch off, the online link replaced, no new link, no pay URL and the pay token unchanged"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/LinkLifecycleTest.php#goes back to transfer while card payment is unavailable (settings_off, stripe_missing)"
        status: pass
    human_judgment: false
  - id: D2
    description: "An explicit switch-on with a change from online to transfer while card payment is unavailable is refused under card_payment_enabled and writes nothing"
    requirement: "P14-09"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/IssuedHeaderEditTest.php#refuses the switch sent on with a change from online to transfer while card payment is unavailable, and writes nothing"
        status: pass
    human_judgment: false
  - id: D3
    description: "An online invoice converted while card payment was unavailable keeps the switch off and gets no token on an unrelated save, and the switch turns on once card payment is available again"
    requirement: "P14-09"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments/LinkLifecycleTest.php#keeps the switch of an online invoice converted while card payment was unavailable off on an unrelated save"
        status: pass
    human_judgment: false
  - id: D4
    description: "A transfer document that keeps its switch on an unrelated save, and the available cases of a change to transfer, are unchanged"
    requirement: "P14-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Payments, tests/Feature/Invoicing, tests/Arch (1307 passed)"
        status: pass
    human_judgment: false

duration: 25 min
completed: 2026-10-23
status: complete
---

# Phase 14 Plan 13: Card switch needs card payment on every path Summary

**`DocumentPayment::issuedSwitch()` now requires card payment to be available on every path that turns the card switch on or keeps it on after a change from online, so a document switched back to transfer while Stripe is down no longer prints a pay URL that can only say "preparing" (WR-01).**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments

- Tracer: online to transfer with the global setting off, and with the Stripe secrets cleared. RED confirmed first (the switch stayed on), then GREEN: switch off, both links `replaced`, no live link, `PayUrl::forInvoice()` null, token unchanged, bank snapshot printed.
- Transfer branch (R-14-13-1): availability is checked when the result is on and the stored switch was off or the stored method was online; an explicit `true` is refused with `card_payment_unavailable`, a derived value falls back to off. A transfer document that keeps its switch on an unrelated save is untouched.
- Online branch (R-14-13-2): `$storedSwitch || onlineUnavailableReason() === null`. RED confirmed first on the converted-invoice case (the unrelated save forced the switch on), then GREEN.
- Docblocks of `forIssued()` and `issuedSwitch()` state the rule and cite WR-01.

## Task Commits

1. **Task 1 (tracer): degrade the switch on a change to transfer while unavailable** - `833a33b` (fix)
2. **Task 2: online branch, remaining regression cases, docblocks** - `213aae6` (fix)

## Deviations from Plan

None - plan executed exactly as written.

Outcomes recorded per the plan: in Task 2 the converted-invoice case failed before the fix (RED) and the IssuedHeaderEditTest explicit-refusal case already passed on the Task 1 code, because it pins the explicit branch of R-14-13-1. Pint reordered the imports of LinkLifecycleTest once; the formatting-only fix went into the Task 2 commit.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Arch`: 1307 passed.
- Pint clean; PHPStan reports only the pre-existing `ResetAdminTwoFactorCommand.php:39` (deferred-items.md).
- `scripts/check-sensitive.sh` clean on both commits; lefthook hooks ran.
- Acceptance greps and filters of both tasks pass.

## Known Stubs

None.

## Threat Flags

None. T-14-40 and T-14-41 mitigated as planned.

## Next Phase Readiness

Gap 2 (WR-01) closed. Ready for plans 14-14 and 14-15.

## Self-Check: PASSED

- FOUND: app/Domain/Invoicing/Support/DocumentPayment.php
- FOUND: tests/Feature/Payments/LinkLifecycleTest.php
- FOUND: tests/Feature/Invoicing/IssuedHeaderEditTest.php
- FOUND commit 833a33b, FOUND commit 213aae6
