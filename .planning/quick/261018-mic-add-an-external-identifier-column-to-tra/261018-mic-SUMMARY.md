---
phase: quick-261018-mic
plan: 01
subsystem: finance-api
tags: [transactions, api, external, jsonb, import, scribe]
requires:
  - phase: 09.1-transactions-api
    provides: Transactions REST API (create, update, batch, wire contract D-07)
  - phase: 07-rest-api
    provides: time_entries.external (D-15, plan 07-06), the pattern mirrored here
provides:
  - transactions.external, a nullable jsonb column that is only ever a JSON object
  - App\Domain\Api\ExternalObject, the one object/size/depth rule shared by time entries and transactions
  - App\Http\Requests\Api\V1\RawJsonBody, the request body decoded as objects once per request
  - `external` in the transaction response, create, PATCH and every batch item
affects: [transactions-api, time-entries-api, api-docs, payments]
tech-stack:
  added: []
  patterns:
    - "One shared domain rule for a free-form object column (ExternalObject::parse) with thin delegates on the old call sites"
    - "A new column is frozen on a payment income by the existing kokpit_guard_payment_income() without changing it"
key-files:
  created:
    - database/migrations/2026_10_18_000900_add_external_to_transactions_table.php
    - app/Domain/Api/ExternalObject.php
    - app/Http/Requests/Api/V1/RawJsonBody.php
    - tests/Feature/Api/TransactionExternalTest.php
  modified:
    - app/Domain/TimeTracking/TimeEntryInput.php
    - app/Domain/Finance/Models/Transaction.php
    - app/Domain/Finance/Actions/RecordTransaction.php
    - app/Domain/Finance/Actions/UpdateTransaction.php
    - app/Http/Requests/Api/V1/TimeEntryPayload.php
    - app/Http/Requests/Api/V1/TransactionPayload.php
    - app/Http/Requests/Api/V1/StoreTransactionRequest.php
    - app/Http/Requests/Api/V1/UpdateTransactionRequest.php
    - app/Http/Controllers/Api/V1/TransactionController.php
    - app/Http/Controllers/Api/V1/TransactionBatchController.php
    - app/Http/Controllers/Api/V1/TransactionExamples.php
    - app/Http/Resources/Api/V1/TransactionResource.php
    - config/scribe.php
    - config/kokpit.php
    - tests/Support/TransactionContract.php
    - tests/Feature/Api/TransactionPaymentIncomeTest.php
    - tests/Feature/Schema/TransactionTablesTest.php
    - tests/Isolation/ApiAccessTest.php
key-decisions:
  - "Same column name, type and semantics as time_entries.external; no index, no unique constraint, no list filter, no server-side dedup"
  - "The three D-15 messages stay under kokpit.time.errors (their wording names only the field), so no lang file or time entry test changed"
  - "The payment income keeps external null and the guard function is untouched; UpdateTransaction answers 422 payment_transaction_locked under `external` instead of a KP001 500"
  - "The panel neither shows nor sends external; the column is not in #[Fillable] or #[LoggedAttributes]"
requirements-completed: [FI-01, AP-05]
duration: 1h 50m
completed: 2026-10-18
status: complete
commits: 3
plan_head_before: f8e3d7e1d996cfd1154fe7e90d0ddfdafe06dfcf
plan_head_after: 8dc3ebb181c35ebd8b66c862a6a4199b494429fe
actuals:
  tokens: 11970
  tasks: 3
  commits: 3
---

# Phase quick-261018-mic Plan 01: External identifier on transactions Summary

**A transaction now carries the same free-form `external` JSON object as a time entry, so n8n imports can store their own id next to the row and read it back from every create, detail, list and batch call.**

## Performance

- **Duration:** about 1h 50m (including waiting for two full-suite runs of about 11 minutes each)
- **Tasks:** 3 of 3 (task 1 is the tracer slice, tasks 2 and 3 are auto)
- **Files:** 4 created, 18 modified

## Accomplishments

- Migration `2026_10_18_000900` adds `transactions.external` (jsonb) with the CHECK `transactions_external_object_check`; no index.
- `ExternalObject::parse()` is the one object/size/depth rule (4096 bytes compact JSON from `kokpit.api.external_max_bytes`, 8 levels). `TimeEntryInput::external()` and `TimeEntryPayload::rawBody()` are now one-line delegates, so time entries behave exactly as before (TimeEntryExternalTest, TimeEntryWriteTest and TimeEntryBatchTest pass unchanged).
- `external` sits after `project_id` in the resource, `TransactionExamples`, `TransactionContract::KEYS` (`?array`) and the generated spec, marked nullable.
- Create stores the object as sent; `{}` stays `{}`. PATCH keeps (absent), clears (`null`) or replaces whole (object) and never touches the CZK figure or its snapshot. Each batch item carries its own object and a bad one fails alone (207).
- A payment income keeps `external` null: a PATCH with an object answers 422 `payment_transaction_locked` under `external`, a raw SQL update is refused with KP001 by the unchanged guard.
- Not in the activity log, not rendered on `/admin/transactions` or the edit page (Livewire snapshot included), and a panel save keeps the stored object.
- Scribe docs (store, update, batch items, batch description) and the intro tell importers to deduplicate with `external`; the "natural keys" wording is gone.

## Task Commits

1. **Task 1: tracer, create and read with its own external object** - `f51fd24` (feat)
2. **Task 2: PATCH, batch items and the locked payment income** - `d58074a` (feat)
3. **Task 3: database and non-leak proofs, docs, full gate** - `8dc3ebb` (feat)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Pinned wire-contract key list in the isolation suite**
- **Found during:** Task 3 full-suite gate
- **Issue:** `tests/Isolation/ApiAccessTest.php` hard-coded the old key list of the transaction response, so the additive `external` key failed it.
- **Fix:** Added `'external'` after `'project_id'` in that list.
- **Files modified:** tests/Isolation/ApiAccessTest.php (not listed in the plan's files_modified)
- **Commit:** `8dc3ebb`

**2. [Rule 1 - Test assumption] Request-body `external` renders as `string|null` in the spec**
- **Found during:** Task 3 docs test
- **Issue:** The plan's docs case implied `type: object` for the request property. Scribe renders a `nullable` rule as `[string, null]` for request bodies, exactly as it already does for time entries; only the description and the batch `type: object` carry the shape.
- **Fix:** The docs test asserts that the `external` property exists on store and update and that its description names the free-form JSON object, plus that batch items list it. No product change.
- **Commit:** `8dc3ebb`

### Process notes (not product deviations)

- Another agent (plan 11-08, public pay page) was working in the same tree and running tests against the same `kokpit_test` database. Concurrent `migrate:fresh` runs made the suite fail intermittently with "column external does not exist" and filesystem races. I ran all verification against a private scratch database (`DB_DATABASE=<name>_test` override, dropped afterwards). Their staged files briefly ended up in my second commit; I soft-reset that commit and recommitted with explicit paths, so the commit contains only this task's files and their index state was kept.
- `ddev composer ci` was run with the database override. Result on the full suite: 6093 passed, 3 skipped, 1 failed. The one failure is `PanelBootTest > it runs on the dedicated test database`, which asserts the database is literally named `kokpit_test` and therefore fails only because of the override. Pint (1106 files), PHPStan level 8 and the licence check pass. `scripts/check-sensitive.sh` is clean over every touched file.
- The local dev database was not migrated (`ddev exec php artisan migrate` would also apply the pending Phase 11 migrations of the other plan). Run it once when ready.

## Known Stubs

None.

## Threat Flags

None. The new surface (a client-supplied JSON object on the existing transaction endpoints) is covered by the plan's threat model T-261018-mic-01 to 07: object-only CHECK, 4096 byte and depth caps, payment-income lock, no mass assignment, no log or panel exposure, fictional fixtures only.

## Self-Check: PASSED

- Files: the four created files exist (migration, ExternalObject, RawJsonBody, TransactionExternalTest).
- Commits `f51fd24`, `d58074a`, `8dc3ebb` are ancestors of HEAD.
- No `.planning/config.json`, `.planning/state.json`, `.planning/milestone.lock` or `.planning/phases/09.1-transactions-api/.gitkeep` was staged or committed.
