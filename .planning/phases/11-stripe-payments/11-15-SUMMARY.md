---
phase: 11-stripe-payments
plan: 15
subsystem: payments
tags: [filament, partner, payment-link, isolation, header-action, system-read]

requires:
  - phase: 11-stripe-payments
    provides: "PayByCardAction and PaymentLinkStatus (11-12), PayUrl and the pay token (11-03), PaymentLink::latestFor() (11-06), the link lifecycle actions (11-08, 11-10)"
provides:
  - "App\\Domain\\Payments\\Queries\\PartnerPayButton: urlFor(Invoice $scopedRecord) returns the stable pay URL or null, nothing else; reads the Admin-only link and token rows as system"
  - "ViewPartnerInvoice header: 'Zaplatit kartou' as the primary action in front of 'Stáhnout PDF', shown exactly while the document is payable by card"
  - "PayByCardAction::make() takes an optional fourth argument, the URL closure, so the Partner passes its own reader"
  - "tests/Isolation/PayButtonLeakTest: rendered-HTML proof for two clients with every link state"
affects: [11-20, payment-links, partner-invoice-detail]

actuals:
  tokens: 6800
  tasks: 2
  commits: 2
plan_head_before: 98b0341797e3001fe3ec37ff9746aac113a6c22f
plan_head_after: 90147af1d10d1638e42c420b24280d133d5691c3
commits: 2

tech-stack:
  added: []
  patterns:
    - "A Partner-safe reader that takes a scope-loaded record, re-checks it through the session's own scope, then reads Admin-only rows as system and returns one fact (URL or null)"
    - "Header action visibility and URL both derived from the same reader, with the resource canAccess() checked again before it is asked"

key-files:
  created:
    - app/Domain/Payments/Queries/PartnerPayButton.php
    - tests/Feature/Payments/PartnerPayButtonTest.php
    - tests/Isolation/PayButtonLeakTest.php
  modified:
    - app/Filament/Partner/Resources/PartnerInvoiceResource/Pages/ViewPartnerInvoice.php
    - app/Filament/Support/PayByCardAction.php

key-decisions:
  - "R11-15-1 and R11-15-2 applied as written; PartnerPayButton reuses PaymentLinkStatus::for()['payable'] inside runAsSystem instead of a second copy of the payable predicate, and returns only the URL"
  - "PartnerPayButton re-loads the record through the signed-in session's scope (exists check) before the system read, so a record loaded any other way never yields a URL"

patterns-established:
  - "The Partner list is unchanged: no pay URL, link state or button appears on it"

requirements-completed: []

duration: 25min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 15: Pay button on the Partner detail Summary

**A client signed in as Partner now sees an accent "Zaplatit kartou" in front of "Stáhnout PDF" on the detail of an own open invoice or proforma, opening the stable pay URL in a new tab, and sees no trace of link state, Stripe or another client.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 5 (3 created, 2 modified)

## Accomplishments

- `PartnerPayButton::urlFor()` answers the URL only when the document is an issued invoice or proforma, not cancelled, still owing money and with an active newest link. A pending, failed or deactivated link, a switched-off document, a paid or cancelled one and a partly paid one with a deactivated link all give null, so the Partner sees the header with the PDF only and no placeholder.
- `ViewPartnerInvoice::getHeaderActions()` returns `[payByCard (primary), downloadPdf]`; the Phase 10 "reserved slot" note is replaced by D-04. Visibility and URL come from the same reader and are guarded by `PartnerInvoiceResource::canAccess()`.
- `PayByCardAction::make()` gained an optional URL closure (default unchanged for the Admin), because the stable URL needs the pay token of an Admin-only row, which a Partner session cannot read.
- Feature test: header order and colour, label, tooltip, URL, new tab, proforma, partial payment, full payment, storno, pending, failed, card switch off, the reader on a foreign record and the restored Partner scope.
- Isolation test: two clients, each with an active, pending, failed and deactivated link and a paid document. Partner A's list and each own detail (HTML and decoded Livewire snapshots) hold none of the card-payment strings of the UI-SPEC list, no Stripe link id, URL, failure class or pay token other than the active document's own URL (the only pay URL on its page); client B's documents answer 404 with no number, id or address; Partner B gets the button on B's active document only; a Partner without a client gets none. Positive checks pair each refusal.
- Verification: Isolation, Payments, Invoicing and Arch suites green (Isolation plus the feature test 349 passed; Payments, Invoicing and Arch 955 passed); Pint and PHPStan level 8 clean; `scripts/check-sensitive.sh` clean on both commits. A mutation check (reader without the payable test) made two isolation tests fail, then the file was restored.

## Task Commits

1. Task 1: tracer, reader, Partner header action, URL closure, feature test - `6b71eb6` (feat)
2. Task 2: rendered-HTML isolation test - `90147af` (test)

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Choices within the plan's latitude

- The plan's interface lists `PayByCardAction::make(Closure $invoice, string $color = 'gray')`; plan 11-12 shipped `make($invoice, $visible, $color)`. The call uses the shipped signature plus the new optional `$url` argument (a minor extension of a shared file that Admin callers do not notice).
- Task 2 is TDD but the tests passed on first run, because the reader of Task 1 already met every behaviour; no code change in `PartnerPayButton` was needed in Task 2. The tests were verified to bite with the mutation check above.

### Auto-fixed Issues

None - plan executed as written, apart from the choices above.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-52 (the reader returns only a URL or null; rendered-HTML test with every link state), T-11-53 (record from the Partner scope, own-scope re-check in the reader, 404 and no trace for a foreign id), T-11-54 (the reader decides nothing about access; `canAccess()` re-checked) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- PA-02 ("button in e-mail, PDF and invoice detail, Admin and Partner; hidden when paid") is not marked complete here: this plan delivers only the Partner detail part. Plan 11-20 runs the full Partner sweep with card payment records of every type.
- A partial payment deactivates the link (D-08), so the Partner button stays hidden until the Admin creates a new link for the remaining amount.

## Self-Check: PASSED

- Files: all 5 listed files exist.
- Commits: `6b71eb6` and `90147af` are ancestors of HEAD.
