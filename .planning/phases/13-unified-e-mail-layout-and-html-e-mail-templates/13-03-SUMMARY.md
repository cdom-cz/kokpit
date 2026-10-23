---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 03
subsystem: testing
tags: [pest, mail-transport, test-isolation, payments]
status: complete

requires:
  - phase: 10-invoicing
    provides: QueueInvoiceEmail, the document e-mail the tests read
  - phase: 14-online-payments
    provides: Stripe alert e-mails to Admins
provides:
  - the seven payment and invoicing test files read the array mail transport by recipient and subject, with exact counts kept
affects: [13-06, 13-08]

plan_head_before: 56eef0c2693f4432965dfb8e5d5004de0fc2a2d7
plan_head_after: 227ab5f1554f5cf05cc38bfb3a035ecb59a81de5

actuals:
  tokens: 6000
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A test counts the messages it means: the document e-mail by client address and dialog-default subject, an alert by Admin address (and subject), never every message of the transport"

key-files:
  created: []
  modified:
    - tests/Feature/Payments/PayButtonOutputsTest.php
    - tests/Feature/Payments/CardPaymentJourneyTest.php
    - tests/Feature/Invoicing/EditableInvoiceJourneyTest.php
    - tests/Isolation/InvoiceLeakTest.php
    - tests/Feature/Payments/PaymentAlertsTest.php
    - tests/Feature/Payments/CreatePaymentLinkTest.php

key-decisions:
  - "Selection, not suppression: no filter names the future confirmation; every selection is positive (recipient and subject of what the test sent, or Admin addresses for alerts)"
  - "RefundEventTest needs no change: its only transport reader already filters by subject, and every count goes through it"

requirements-completed: [P13-05]

duration: 15min
completed: 2026-10-23
---

# Phase 13 Plan 03: Existing-suite Mail Selections Summary

The seven payment and invoicing test files now count only the e-mails they mean, so the payment confirmation of plan 13-06 can land without turning any of them red.

## What was built

No production code. Test selections only; no test added or removed (94 tests pass in the seven files, 20 of them in the tracer pair), and no expected count changed.

### Changed assertions (R-13-03-3)

Line numbers refer to the files at the plan's final commit.

| File | Line | Before | After |
|---|---|---|---|
| PayButtonOutputsTest.php | 103-108 (`payOutSend()`) | `toHaveCount(1)` over every message, returned the first | the messages to `invoice@example.com` with subject `$defaults['subject']`; `toHaveCount(1)` stays, returns the text of that message |
| CardPaymentJourneyTest.php | 202 (`$toClient`) | filter by recipient only, `toHaveCount(1)` | filter by recipient and `$defaults['subject']`; `toHaveCount(1)` stays |
| EditableInvoiceJourneyTest.php | 270, 368, 374 | `iterator_to_array(Mail::getSymfonyTransport()->messages())` counts 1, 1, 2 | `editJourneyMails($invoice)` (new per-file helper, line 203: recipient `invoice@example.com` and dialog-default subject); counts 1, 1, 2 unchanged |
| InvoiceLeakTest.php | 635-644 | count 1 and `->first()` over the whole transport | transport messages filtered by the defaults recipient and subject; count 1 and `->first()` instance check kept |
| PaymentAlertsTest.php | 279, 305 | `expect(paMails())->toBe([])` (no mail at all) | `expect(paAdminMails())->toBe([])` (new helper, line 124: no mail to any Admin address); the alert counts at the titled reads stay as they were |
| CreatePaymentLinkTest.php | 225 (`mailsSent()`) | every message of the transport | only messages to Admin addresses; all callers (counts `count(everyAdmin())`, `toBe([])`, per-mail subject checks) unchanged |
| RefundEventTest.php | - | already reads only by subject (`rfMailsTitled()`) | no change |

The `flush()` calls (EditableInvoiceJourneyTest.php:62, InvoiceLeakTest.php:585) are not counts and stay.

## Deviations from Plan

None - plan executed exactly as written. PaymentAlertsTest and CreatePaymentLinkTest select by Admin address (the plan allows "the Admin addresses of the test and the alert subject"); their subject assertions stay in the tests that already make them.

## Verification

- Seven files: 94 tests passed (4064 assertions), same test counts as before.
- Pint clean. PHPStan: only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh` clean on both commits; hooks (sensitive-content, gitleaks) passed.
- Acceptance greps: `getSubject` present in PayButtonOutputsTest and on the `$toClient` line; `iterator_to_array(Mail::getSymfonyTransport()->messages())` count 0 in EditableInvoiceJourneyTest and InvoiceLeakTest.

## Known Stubs

None.

## Threat Flags

None. T-13-24 mitigated: exact counts kept, nothing deleted, no mailer or queue faked.

## Commits

- 887e2a4: tracer, document e-mail selected by recipient and subject in the button and journey tests
- 227ab5f: the edit journey, leak check and alert suites count only the e-mails they mean

## Self-Check: PASSED

Both commits present in history; the six modified test files exist; the seven target files pass.
