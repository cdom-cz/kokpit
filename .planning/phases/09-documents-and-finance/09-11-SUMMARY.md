---
phase: 09-documents-and-finance
plan: 11
subsystem: finance
tags: [finance, transactions, filament, cnb-preview, activity-log, partner-isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: RecordTransaction, UpdateTransaction, DeleteTransaction, TransactionInput and TransactionCzk (09-22), category tree and selectable scope (09-04), Transaction model and canary fixture (09-09)
  - phase: 08-time-reports-and-exchange-rates
    provides: CNB rate rows and ExchangeRateConverter (EX-03), RateFormat
provides:
  - TransactionResource, the Admin-only "Transakce" screen (list, create, edit)
  - live CNB preview and manual CZK switch on the form
  - allowlisted activity on Transaction and TransactionHistoryRelationManager
  - route-walk entry for transactions
affects: [09-13 receipts and receipt-count column, 09-15 finance overview, 09-20 CONTRIBUTING hand-over note, Phase 10 invoice-payment transactions]

actuals:
  tokens: 16732
  tasks: 3
  commits: 3
plan_head_before: 0798a9e4d576aa2cd2515d2f7e5d26526eca2c2f
plan_head_after: e026d77421bdd153d6399a6516dd6905c79979d4
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Form preview runs the same TransactionCzk rule the Action applies, so the shown figure is the stored one"
    - "actionData() maps the form switch to the Action input: CZK drops the figure, manual on sends czk_manual true, a figure typed for a day without a list is manual, otherwise czk_manual false recomputes"
    - "Edit form is filled from an explicit formState() of form keys only, not from the model attributes"

key-files:
  created:
    - app/Filament/Resources/TransactionResource.php
    - app/Filament/Resources/TransactionResource/Pages/ListTransactions.php
    - app/Filament/Resources/TransactionResource/Pages/CreateTransaction.php
    - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
    - app/Filament/RelationManagers/TransactionHistoryRelationManager.php
    - tests/Feature/Finance/TransactionResourceTest.php
  modified:
    - app/Domain/Finance/Models/Transaction.php
    - lang/cs/finance.php
    - lang/cs/kokpit.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "The manual-kept hint shows whenever the czk_manual switch is on, not only on a stored manual transaction, because it describes what saving will do"
  - "The CZK field is shown and required when the switch is on or when the preview finds no CNB list; with the switch off a typed figure becomes manual and an empty field sends czk_manual false"
  - "The edit form fills czk_amount for a manual transaction only, so a computed one moved to a day without a list must ask for the figure again instead of silently freezing the old one"
  - "The category filter treats a parent as its children too; project select has no disabled state (a disabled field would not be dehydrated and the Action would keep the stored project)"
  - "Client and project option lists are built in the resource, not borrowed from the TimeTracking EntryContextOptions, to keep Finance free of a TimeTracking dependency"

patterns-established:
  - "Admin finance screens refuse a Partner with abort_unless(canAccess(), 403) in mount() before the record lookup"

requirements-completed: [FI-01]

coverage:
  - id: D1
    description: "The Admin records an income or expense on the Transakce screen in any CNB currency and sees the CNB preview; a CZK amount needs no rate"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#the tracer: record a foreign-currency expense and find it in the list"
        status: pass
    human_judgment: false
  - id: D2
    description: "A day without a CNB list asks for the CZK amount in Czech, refuses an empty value next to the field and saves a typed figure as manual"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#the edit page follows the recompute-versus-manual rule"
        status: pass
    human_judgment: false
  - id: D3
    description: "The edit page saves through UpdateTransaction: a computed figure follows a new date, a manual one is kept with the czk_manual_kept hint until the switch is turned off"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#the edit page follows the recompute-versus-manual rule"
        status: pass
    human_judgment: false
  - id: D4
    description: "Delete asks for a confirmation and removes the row through DeleteTransaction; creation, edit and deletion each write one activity row with the allowlist and never the description; the history tab lists them with Czech labels"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#deleting a transaction"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#the activity trail"
        status: pass
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php#lists the application models that log activity explicitly"
        status: pass
    human_judgment: false
  - id: D5
    description: "The list shows date, type, category with parent, counterparty, both amounts with the manual badge, client and project, newest first, with the filters; a Partner gets 403 on every transactions route and component"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionResourceTest.php#the tracer: record a foreign-currency expense and find it in the list"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: true
    rationale: "Filter behaviour and the visual layout of the list and form (badge, hint placement, preview colour) are not asserted by a test; only the Partner refusal and the list content are"

# Metrics
duration: 14min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 11: Transaction Screen Summary

**Admin-only "Transakce" screen over the 09-22 Actions: live CNB preview and manual CZK switch on the form, delete behind a confirmation, an allowlisted activity trail with a history tab, filters and a route-walk entry**

## Performance

- **Duration:** 14 min
- **Started:** 2026-10-16T14:07:40Z
- **Completed:** 2026-10-16T14:21:17Z (last code commit; SUMMARY follows)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 11 (6 created, 5 modified)

## Accomplishments

- The create page records an income or expense in CZK or any currency with a stored CNB row; the preview line shows the CZK figure, the rate, the unit and the list date of the chosen currency, amount and date, and for a day without a list it asks for the CZK amount and saving with it succeeds.
- The edit page saves through `UpdateTransaction`: a computed figure follows a new date, a manual one stays with the `czk_manual_kept` hint until the switch is turned off, a moved date without a list refuses an empty CZK field next to the field, and a category archived later still opens and saves.
- Delete runs `DeleteTransaction` behind a confirmation dialog and redirects to the list; `Transaction` logs twelve explicit attributes (never the description) and the edit page has a `TransactionHistoryRelationManager` tab with Czech attribute labels.
- The list shows date, type badge, "Parent › Child" category, counterparty, original and CZK amounts with a "ručně" badge, client and project, newest first, with filters for type, category (a parent includes its children), client, project, currency and an inclusive date range.
- A Partner gets 403 on the list, create, edit routes and the Livewire components; `RouteWalkTest` learned `transactions` with `partner => false`.

## Task Commits

1. **Task 1: Tracer, record a foreign-currency expense and find it in the list** - `7b978c6` (feat)
2. **Task 2: Edit page follows the recompute-versus-manual rule** - `86a03e0` (feat)
3. **Task 3: Delete with confirmation, activity trail, history tab** - `e026d77` (feat)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

Tracer verified end-to-end (tests, Pint, PHPStan) before expansion.

## Files Created/Modified

- `app/Filament/Resources/TransactionResource.php` - form, table, filters, the CNB preview, `actionData()` and `formState()` mapping
- `app/Filament/Resources/TransactionResource/Pages/ListTransactions.php`, `CreateTransaction.php`, `EditTransaction.php` - pages handing the form to the Actions through `withFormErrors()`; the edit page also holds the delete header action and the 403 guard
- `app/Filament/RelationManagers/TransactionHistoryRelationManager.php` - Admin-only history tab
- `app/Domain/Finance/Models/Transaction.php` - `LogsAllowlistedActivity` and `#[LoggedAttributes]`
- `lang/cs/finance.php`, `lang/cs/kokpit.php` - screen copy, activity subject and attribute labels
- `tests/Feature/Finance/TransactionResourceTest.php` - 29 tests for the whole plan
- `tests/Arch/ActivityAllowlistTest.php`, `tests/Isolation/RouteWalkTest.php` - Transaction as a logging model, `transactions` route-walk entry

## Decisions Made

See `key-decisions`. In short: the hint follows the switch, the CZK field follows "switch on or no list", a manual figure is prefilled for editing only when it is already manual, a parent category filters its children too, and Finance builds its own client and project options.

## Deviations from Plan

None - plan executed exactly as written.

Notes that are not deviations: Task 2 was written test-first, but only the hint test failed before the change, because the Task 1 form and the 09-22 Action already implemented the rest of the listed behaviour; the remaining new tests are regression cover. Pint, run over the `lang` directory, rewrote six unrelated Laravel stock files (`actions.php`, `auth.php`, `http-statuses.php`, `pagination.php`, `passwords.php`, `validation.php`); those edits were reverted with `git checkout` of those files and never staged.

## Issues Encountered

- A first test of the missing-rate path failed with "currency invalid" because no EUR row existed at all; the tests now store one list dated after the transaction date, which matches the real rule (a currency needs at least one stored CNB row).
- `assertFormSet` receives the `type` as the `TransactionType` enum, not the string; the test expects the enum.

## Known Stubs

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None. No new network endpoint, auth path or schema change; the screens sit behind `Audience::AdminOnly`, `AdminOnlyPolicy` and the route walk (T-09-38), the Actions re-validate every id (T-09-40) and the activity trail covers T-09-41. `composer.json` and `composer.lock` untouched (T-09-SC).

## Next Phase Readiness

- Plan 09-13 adds receipts to `DeleteTransaction` and the receipt-count column to the list; the edit page and the table are the places to extend.
- Plan 09-15 can read `Transaction::czk()` and `czk_source` directly.
- Plan 09-20 still has the CONTRIBUTING note that names `toCzk()`.

## Self-Check: PASSED

- Created files exist: `TransactionResource.php`, the three pages, `TransactionHistoryRelationManager.php`, `TransactionResourceTest.php` (all present).
- Commits `7b978c6`, `86a03e0`, `e026d77` are ancestors of HEAD; `git rev-list --count 0798a9e..HEAD` was 3 before this SUMMARY.
- Acceptance greps of all three tasks pass; plan verification: full `ddev exec vendor/bin/pest` 4286 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
