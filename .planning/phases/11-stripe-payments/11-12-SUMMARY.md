---
phase: 11-stripe-payments
plan: 12
subsystem: payments
tags: [filament, admin-only, payment-link, infolist, livewire-poll, invoice-list, filter]

requires:
  - phase: 11-stripe-payments
    provides: "RegeneratePaymentLink and DeactivatePaymentLink (11-10), PaymentLink::latestFor() and the state enums (11-06), PayUrl and the frozen card switch (11-03), the failure rows of CreatePaymentLink (11-07)"
provides:
  - "App\\Domain\\Payments\\Queries\\PaymentLinkStatus: for(Invoice) returns the switch, the newest link state and reason, failed, offered amount, creation instant, remaining amount, payable, regenerable and the stable URL; listKeys(ids) gives the list keys of a whole page in one query; LATEST_STATE_SQL and SWITCH_SQL for filters; Admin or system only"
  - "App\\Filament\\Support\\PayByCardAction: the 'Zaplatit kartou' URL action (stable pay URL, new tab, tooltip), visibility decided by the caller; shared with the Partner detail of plan 11-15"
  - "App\\Filament\\Support\\PaymentLinkSection: the 'Platba kartou' section with state badge, offered amount, created, copyable stable URL with a new-tab link and the reason line, the two header actions and the failure and inactive banners"
  - "ViewInvoice: banners after the overdue banner, the section between Souhrn and Související doklady, the header action between the e-mail and payment actions, the frozen row 'Platba kartou: Ano / Ne' in the document section"
  - "InvoiceTable: Admin-only filter 'Platební odkaz' and toggleable icon column 'Platba kartou' (hidden by default), also on the client invoice tab"
affects: [11-15, 11-13, 11-16, payment-links, invoice-detail, invoice-list]

actuals:
  tokens: 15900
  tasks: 3
  commits: 3
plan_head_before: 1a3d68684e7c565047577d7fc806ae449a597caf
plan_head_after: b0784315914b503fad106298d93817409f14d3d7
commits: 3

tech-stack:
  added: []
  patterns:
    - "One status reader per request on a Livewire page: the page memoises PaymentLinkStatus::for() in a non-persisted property and hands the closure to the section, the banners and the header action, so they cannot disagree and the page reads once; reloadAdminHalf() forgets it after a write"
    - "A shared PHPStan array shape (@phpstan-type LinkStatusRow) exported by the reader and imported by the page and the section"
    - "A hidden Filament action is not resolvable in tests, so absence is asserted by the label missing from the HTML, presence by TestAction with the schema component key"

key-files:
  created:
    - app/Domain/Payments/Queries/PaymentLinkStatus.php
    - app/Filament/Support/PayByCardAction.php
    - app/Filament/Support/PaymentLinkSection.php
    - tests/Feature/Payments/PaymentLinkSectionTest.php
    - tests/Feature/Payments/InvoiceListLinkTest.php
  modified:
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - app/Filament/Support/InvoiceTable.php
    - lang/cs/payment_links.php

key-decisions:
  - "R11-12-1 to R11-12-4 applied as written, with the extensions listed under Deviations"
  - "'Vypnuto' is derived (switch off), not a state; a card-on document with no link row shows the dash and gets the warning banner so the Admin can create the first link"
  - "The warning banner and the regenerate button share one predicate (regenerable: switch on, something owed, not cancelled, no link being prepared); an active link can be regenerated (it is replaced), which is why the button shows beside the deactivate button"
  - "The list filter and column exist only when PartnerContext::isAdmin(); the filter query also refuses a non-Admin by narrowing to nothing, because the SQL fragments ignore the Partner scope"

patterns-established:
  - "Plan 11-15 passes its own Partner-safe visibility closure to PayByCardAction::make() and never calls PaymentLinkStatus (Admin only)"

requirements-completed: []

duration: 55min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 12: Link section on the Admin detail Summary

**The Admin now reads on an issued invoice or proforma whether the client can pay by card right now (state, offered amount, stable URL, reason), can open the client's pay page from the header, deactivate the link or create a new one for the remaining amount, sees a banner when creation failed or the link no longer matches what is owed, and finds such documents in the invoice list by a link filter.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `PaymentLinkStatus::for()` derives everything from the frozen switch, the newest link (`PaymentLink::latestFor()`), the balance reader and the cancellation: `payable` (invoice or proforma, owes money, newest link active) drives the header action, `regenerable` (switch on, owes money, not cancelled, no link being prepared) drives the regenerate button and both banners. A credit note, a draft and a paid or cancelled document offer nothing.
- `PaymentLinkSection` renders the state badge with the UI-SPEC colours and icons (success link, info clock, danger exclamation circle, gray link slash, gray credit card for Vypnuto), the offered amount, the creation instant, the stable Kokpit URL (copyable, with a new-tab "Zaplatit kartou" suffix action) and the reason line (fixed copy for a failed link and a switched-off document, the enum copy for a deactivated one). The section polls with `wire:poll.5s` only while the newest link is pending; the first render after the job finished carries no poll attribute.
- The two header actions go through the Actions of plan 11-10 on a freshly queried document, re-check `InvoiceResource::canAccess()`, and turn a `ValidationException` into a persistent danger toast with the Action's message. Regenerate sends the info toast "Odkaz se vytváří" and its modal body carries the remaining amount; deactivate uses the warning colour.
- `ViewInvoice` placement as ruled: link banners right after the overdue banner, the section after Souhrn, `payByCard` between the e-mail and payment actions (order asserted), and the frozen row "Platba kartou: Ano / Ne" in the document section (absent on a credit note).
- `InvoiceTable`: `SelectFilter payment_link` and `IconColumn card_payment` built on `LATEST_STATE_SQL`, `SWITCH_SQL` and `PaymentLinkStatus::listKeys()` (one query per rendered page, per-row tooltips naming the state). Works on the client invoice tab through the shared table.
- Verification: Payments, Invoicing, Clients, Isolation and Arch suites 1490 passed; Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on all three commits. The rendered HTML is asserted to contain the stable URL and no `stripe.com` and no Stripe link id; a failed link never shows the failure class.

## Task Commits

1. Task 1: tracer, reader, header action, section with both actions, placements, copy - `9998ad6` (feat)
2. Task 2: state, banner and visibility tests for every case; refused regeneration toast; Partner refusal - `3abafd4` (feat)
3. Task 3: invoice list filter and column, list keys reader, list copy - `b078431` (feat)

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Choices within the plan's latitude

- `PaymentLinkStatus::for()` returns two more keys than the ruling lists, `remaining` (Money, for the banner and the modal body) and `regenerable` (the shared button and banner predicate), so the page does not re-derive them in three places. `for()` is static, matching the call shape of the ruling (`PaymentLinkStatus::for()['payable']`).
- `PaymentLinkSection::section()` and `banners()` take the page's reload closure and an optional memoised status closure besides the invoice closure (ruling: `section(Closure $invoice, Closure $reload)`), so the page reads the link state once per request.
- The "Odkaz pro klienta" row is hidden when the document has no token (switch off); the other rows show the dash without a link.
- Hidden actions cannot be resolved by the Filament test helpers, so the plan's "hidden, not disabled" is asserted by the absence of the button label in the HTML (and `assertActionHidden` for the header action).
- The refusal path of the Action (a pending link appearing between render and click) cannot be reached through the UI because the page re-evaluates visibility first; the test binds a stub that throws the Action's `ValidationException` and asserts the danger toast, the unchanged newest link and the untouched other document.
- Tasks 1 and 2 did not have a recorded RED run: the tracer is not a TDD task, and the Task 2 tests passed against the complete implementation of Task 1 apart from one test expectation (a non-breaking space in the money text), fixed in the test. No mutation check was run on this plan.

### Auto-fixed Issues

None - plan executed as written, apart from the choices above.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-42 (only `PayUrl` output and fixed reason copy rendered; tests assert no `stripe.com`, no link id, no failure class), T-11-43 (page `boot()`, `InvoiceResource::canAccess()` in each action, the Gate in the Actions; Partner 403 test), T-11-44 (reader refuses non-Admin, filter and column registered for the Admin only, filter query narrows to nothing otherwise) are mitigated as listed. T-11-SC: this plan changed no composer file.

## Notes for later plans

- Plan 11-15 reuses `PayByCardAction::make($invoice, $visible, 'primary')` on the Partner detail with its own Partner-safe visibility reader; `PaymentLinkStatus` and `PayUrl::forInvoice()` read Admin-only rows, so the Partner path must resolve the URL and the active-link fact without them (the stable URL needs only the token, which the Partner-safe reader must obtain as system).
- Requirements PA-01 to PA-04 are not changed by this plan: PA-02 (button in e-mail, PDF and the Partner detail), PA-03 (per client and per document switch end to end) and PA-04 (deactivation by any route including the webhook match) are completed by plans 11-13, 11-15 and the other surfaces; PA-01 was already complete.

## Self-Check: PASSED

- Files: all 8 listed files exist.
- Commits: `9998ad6`, `3abafd4` and `b078431` are ancestors of HEAD.
