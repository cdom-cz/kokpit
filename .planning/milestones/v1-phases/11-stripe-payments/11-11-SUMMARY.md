---
phase: 11-stripe-payments
plan: 11
subsystem: payments
tags: [filament, admin-only, overview, tabs, record-view, aggregate-counts, livewire]

requires:
  - phase: 11-stripe-payments
    provides: "CardPayment record, its four enums with Czech labels, canary fixture and allowlisted history (plan 11-09)"
provides:
  - "Admin-only resource CardPaymentResource at /admin/card-payments (navigation 'Platby kartou', sort 52, credit-card icon, danger badge above zero, not globally searchable) with the list and the record view"
  - "App\\Domain\\Payments\\Enums\\CardPaymentTab: seven tabs, each one SQL condition used both to constrain the list and as COUNT(*) FILTER, so a tab and its count cannot disagree"
  - "App\\Domain\\Payments\\Queries\\UnresolvedPayments: counts() {total, duplicate, unmatched, refund} and tabCounts(), one aggregate query each, Admin or system only (read by the navigation badge, the tabs and the dashboard card of plan 11-17)"
  - "ListCardPayments listening to the Livewire event card-payment-resolved (dispatched by plan 11-19)"
  - "ViewCardPayment with the amount heading, the one explaining banner and the sections Platba, Spárování and Řešení; CardPaymentHistoryRelationManager (AdminOnly, allowlisted history)"
  - "lang/cs/card_payments.php (navigation, tabs, columns, filters, empty states, sections, fields, banners); tests/Support/CardPaymentFixtures (matched, matchedThenDeleted, duplicate, unmatched, refund, resolved); card-payments entry in the route walk"
affects: [11-13, 11-16, 11-17, 11-19, card-payments, overview, resolutions]

actuals:
  tokens: 21000
  tasks: 3
  commits: 3
plan_head_before: f0987fcefe2e0ca4eb2dc46021189c887a095086
plan_head_after: 8d179ad69ffa6bb551d12a86b5413fd991f9c531
commits: 3

tech-stack:
  added: []
  patterns:
    - "A tab enum owns one SQL condition per tab; the list constrains with whereRaw(condition) and the counts select COUNT(*) FILTER (WHERE condition), so the two are one definition"
    - "A Stripe reference column is state-shortened (first 8, last 6), carries the full value as tooltip and copyable state, and is toggleable and hidden by default"
    - "A record view picks exactly one Callout by an ordered rule list (resolved, matched then deleted, matched, refund, duplicate, unmatched by reason)"

key-files:
  created:
    - app/Filament/Resources/CardPaymentResource.php
    - app/Filament/Resources/CardPaymentResource/Pages/ListCardPayments.php
    - app/Filament/Resources/CardPaymentResource/Pages/ViewCardPayment.php
    - app/Filament/RelationManagers/CardPaymentHistoryRelationManager.php
    - app/Domain/Payments/Queries/UnresolvedPayments.php
    - app/Domain/Payments/Enums/CardPaymentTab.php
    - lang/cs/card_payments.php
    - tests/Support/CardPaymentFixtures.php
    - tests/Feature/Payments/CardPaymentsOverviewTest.php
  modified:
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "R11-11-1 to R11-11-5 applied as written"
  - "The tabs Duplicitní, Nespárované and Vrácení list the unresolved records of their type only; every resolved record sits under Vyřešené. This is the reading that makes the plan's behaviour counts true (Nevyřešené 3, Všechny 6, Spárované 2, Duplicitní 1, Nespárované 1, Vrácení 1, Vyřešené 1) and makes the tabs after Všechny a partition of the table"
  - "A list that never had a record shows 'Zatím žádné platby kartou' with its body on every tab; once records exist each tab uses its own empty state"
  - "Doklad and Klient follow one rule everywhere (documentOf): the assigned document for an assigned record, otherwise the document the payment named; the Klient filter uses the same COALESCE so a filter and a row cannot disagree"

patterns-established:
  - "Plan 11-19 adds its header actions to ViewCardPayment::getHeaderActions() and dispatches card-payment-resolved after a resolution; the list and the dashboard card refresh from it"
  - "Plan 11-17 reads UnresolvedPayments::counts() for the dashboard card"
  - "Plan 11-16 links its notices to CardPaymentResource::getUrl('view', ['record' => $record])"

requirements-completed: []

duration: 55min
completed: 2026-10-18
status: complete
---

# Phase 11 Plan 11: Payments overview Summary

**The Admin opens "Platby kartou" and works a queue of every card payment and refund event with the unresolved ones first (oldest first, counted by one aggregate query), and every record opens to one banner that says what happened and what it did or did not change.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 10 (9 created, 1 modified)

## Accomplishments

- `CardPaymentResource` (AdminOnly, `EnforcesResourceAccessRule`, slug `card-payments`, navigation sort 52, credit-card icon, not globally searchable) with a navigation badge from `UnresolvedPayments::counts()['total']`, shown in danger above zero and absent at zero.
- `CardPaymentTab` and `UnresolvedPayments`: the seven tabs and their counts come from one condition each, in one aggregate query; the default tab "Nevyřešené" is worked oldest first, every other tab newest first; 25 rows with 25, 50 and 100.
- Columns Přijato, Typ (badge with colour and icon per the colour map), Doklad and Klient (links, a dash when undetermined), Částka (with the grey "Nabízeno: :offered" line on a mismatch), Důvod, Stav (badge, and the resolution on a second line, "Přiřazeno k dokladu :number" for an assignment); the hidden toggleable Platba and Událost columns show a shortened reference with the full value in the tooltip and as copied text.
- Filters Klient (archived clients marked "(archivovaný)"), Měna and Přijato (Od, Do, inclusive in Prague days) with indicator chips; per-tab empty states, "Nic k řešení" being the quiet success state.
- `ViewCardPayment`: heading with the amount and the type and state badges, the received instant as subheading, one `Callout` chosen in the documented order, the sections Platba (copyable Stripe references), Spárování (with "Zbývá na dokladu" from `InvoiceBalances`) and Řešení (only after a resolution, with the linked expense of a recorded return). `boot()` aborts 403 unless `CardPaymentResource::canAccess()`.
- `CardPaymentHistoryRelationManager` (AdminOnly) and the route-walk entry `card-payments` (Admin only, canary record by its resolution note).
- `CardPaymentsOverviewTest` (23 tests, 243 assertions) and `CardPaymentFixtures` writing records as system with runtime-assembled Stripe references.
- Verification: Payments, Isolation and Arch suites 676 passed; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on all three commits.

## Task Commits

1. Task 1: tracer, resource, pages, tab enum, counts, fixtures, route-walk entry - `deb3ea4` (feat)
2. Task 2: columns, filters, empty states, paging and the query-count test - `e3ccde8` (feat)
3. Task 3: the record view banners and sections, the history and access tests - `8d179ad` (feat)

## Decisions Made

See `key-decisions` in the frontmatter. The most visible one: the three type tabs show unresolved records only, so the tabs after "Všechny" partition the table.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The date filter indicator named the day before**
- **Found during:** Task 2 (indicator test)
- **Issue:** formatting `PragueDay::start($day)` (a UTC instant) printed 2. 10. for 3. 10. The filter query itself was correct. The same pattern exists in `InvoiceTable::dayText()`; it was left alone as out of scope.
- **Fix:** the chip formats the plain calendar day.
- **Files modified:** `app/Filament/Resources/CardPaymentResource.php`
- **Commit:** `e3ccde8`

### Differences from the plan text

- **Livewire update of the record view answers 404, not 403, for a Partner.** The record is hidden from a Partner by the model scope, so Livewire cannot rehydrate it and answers 404 before `boot()` runs. A direct GET answers 403 (tested). The record is refused either way and never served; `boot()` stays as defence in depth. The test asserts the 404 with a comment.
- **The history tab test uses the `updated` activity of a resolution, not a `created` row.** Plan 11-09 records a card payment with a plain insert, which writes no activity row, so the first history entry is the resolution. The test proves that entry shows no Stripe reference and no note text.
- **`CardPaymentHistoryRelationManager` was created in Task 1** (the resource registers it) instead of Task 3; its tests are in Task 3.
- **Task 1 and 2 tests were written together with the implementation and first run after it**, so there is no recorded RED run for them; the Task 3 tests were run green on first execution except the Partner update expectation above.

## Auth Gates

None.

## Known Stubs

None. The resolution actions (assign, resolve, record a return) are intentionally absent from the record view: plan 11-19 adds them to `ViewCardPayment::getHeaderActions()`.

## Threat Flags

None beyond the plan's threat model. T-11-39 (a Partner reaching the overview or a record): AdminOnly AccessRule and `AdminOnlyPolicy`, `boot()` re-check, route-walk entry with `partner => false`, Partner tests for the list, the record, a Livewire update and the history. T-11-40 (Stripe references or notes through search or history): `isGloballySearchable = false`, history test that finds neither a reference nor the note text. T-11-41 (N+1 on a long list): eager loads of `invoice.client` and `assignedInvoice.client`, aggregate counts, a query-count test (3 rows against 30). T-11-SC: this plan changed no composer file.

## Notes for later plans

- PA-07 and PA-08 are not marked complete: this plan delivers the overview and the record view, not the matching, the notices, the pruning or the resolutions.
- The one visual item left to a human (UI consideration N7 overflow, backstop): long client names and shortened references at the narrow breakpoint.
- `InvoiceTable::dayText()` formats `PragueDay::start()` (a UTC instant) the same way as the chip bug fixed here; not checked or touched, worth a look in a later plan.

## Self-Check: PASSED

- Files: all 10 listed files exist.
- Commits: `deb3ea4`, `e3ccde8` and `8d179ad` are ancestors of HEAD.
