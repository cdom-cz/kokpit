---
phase: quick-261018-mic
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_10_18_000900_add_external_to_transactions_table.php
  - app/Domain/Api/ExternalObject.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Domain/Finance/Models/Transaction.php
  - app/Domain/Finance/Actions/RecordTransaction.php
  - app/Domain/Finance/Actions/UpdateTransaction.php
  - app/Http/Requests/Api/V1/RawJsonBody.php
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
  - tests/Feature/Api/TransactionExternalTest.php
  - tests/Feature/Api/TransactionPaymentIncomeTest.php
  - tests/Feature/Schema/TransactionTablesTest.php
autonomous: true
requirements: [FI-01, AP-05]

estimate:
  tokens: 80000
  raw_tokens: 80000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "POST /api/v1/transactions accepts an optional `external` JSON object (for example {\"source\": \"demo-bank\", \"id\": \"TX-1001\"}). The create answer, GET /api/v1/transactions/{id} and GET /api/v1/transactions all return the same content under `data.external`. A transaction created without it returns `external: null`. `{}` is stored as a jsonb object and returned as `{}`, never as `[]`"
    - "`external` sits in the transaction response directly after `project_id` and before `created_at`, in the resource, in TransactionExamples and in the generated spec, where it is marked nullable. TransactionContract::KEYS pins it as `?array`. Every key that existed before keeps its name, order and type"
    - "A value of `external` that is not a JSON object (an empty list, a list, a string, a number, a bool) answers 422 under `external` with __('kokpit.time.errors.external_not_object') and saves nothing. More than 4096 bytes of compact JSON answers `external_too_large`. More than 8 levels of nesting answers `external_too_deep`. Transactions and time entries share one rule (App\\Domain\\Api\\ExternalObject), so the limits and messages are the same, and TimeEntryExternalTest passes unchanged"
    - "PATCH /api/v1/transactions/{id}: an absent `external` keeps the stored object, `null` clears it, and an object replaces it whole (nothing is merged). Changing only `external` leaves the amount, the CZK figure, its source and the rate snapshot exactly as stored"
    - "POST /api/v1/transactions/batch: every item may carry its own `external`. A saved item stores its object. An item whose `external` is not an object fails alone, under `external` with its index, and the other items are still saved (207)"
    - "The income of an invoice payment keeps `external` null. A PATCH that sends an object there answers 422 `payment_transaction_locked` under `external` and changes no column. A raw SQL update of `external` on such a row is refused by kokpit_guard_payment_income() with KP001, and that guard function is not changed"
    - "The database refuses a non-object `external` from any writer through the CHECK `transactions_external_object_check` (23514). There is no index, no unique constraint and no list filter on `external`. The API never looks a transaction up by it and never skips a duplicate because of it"
    - "`external` is not in the activity log, is not rendered on /admin/transactions or /admin/transactions/{id}/edit (Livewire snapshot included), and saving the edit form in the panel keeps the stored object. A Partner still reaches no transaction, because DeniesPartners and the finance:* abilities are unchanged"
    - "The generated API docs describe `external` as an optional object on create, update and every batch item. The batch endpoint and the intro of config/scribe.php tell the client to store its own id in `external` to deduplicate"
    - "`ddev composer ci` is green and `scripts/check-sensitive.sh` passes over every touched file"
  artifacts:
    - path: "database/migrations/2026_10_18_000900_add_external_to_transactions_table.php"
      provides: "Nullable jsonb column transactions.external with the object-only CHECK, no index"
      contains: "transactions_external_object_check"
    - path: "app/Domain/Api/ExternalObject.php"
      provides: "The one object/size/depth rule of every free-form external object (time entries and transactions)"
      contains: "final class ExternalObject"
    - path: "app/Http/Requests/Api/V1/RawJsonBody.php"
      provides: "The request body decoded as objects once per request, so {} stays an object"
      contains: "final class RawJsonBody"
    - path: "app/Http/Resources/Api/V1/TransactionResource.php"
      provides: "The `external` key of the transaction wire contract, after project_id"
      contains: "'external' =>"
    - path: "tests/Feature/Api/TransactionExternalTest.php"
      provides: "End-to-end proofs of create, read, list, limits, PATCH, batch, the non-leak checks and the docs"
  key_links:
    - from: "app/Http/Controllers/Api/V1/TransactionController.php"
      to: "app/Http/Requests/Api/V1/TransactionPayload.php"
      via: "store and update hand the raw body decoded as objects to the payload mapping"
      pattern: "RawJsonBody::of\\(\\$request\\)"
    - from: "app/Domain/Finance/Actions/RecordTransaction.php"
      to: "app/Domain/Api/ExternalObject.php"
      via: "the create Action checks `external` with the shared rule before it writes"
      pattern: "ExternalObject::parse"
    - from: "app/Domain/Finance/Actions/UpdateTransaction.php"
      to: "app/Domain/Api/ExternalObject.php"
      via: "the update Action checks a named `external`, compares it on a payment income, and writes it"
      pattern: "ExternalObject::parse"
    - from: "app/Http/Controllers/Api/V1/TransactionBatchController.php"
      to: "app/Http/Requests/Api/V1/TransactionPayload.php"
      via: "each batch item is mapped with its raw counterpart from the body"
      pattern: "forBatchItem\\(\\$item, \\$raw"
---

<objective>
Give finance transactions their own free-form `external` JSON object. It works exactly like `time_entries.external` (Phase 7, D-15, plan 07-06), and the Transactions API (Phase 09.1) reads and writes it. The owner's n8n automations can then keep the transaction's id in their own system (a bank line id, a source name, any structured reference) next to the Kokpit row. They read it back on every list and detail call and use it to deduplicate imports.

Purpose: today the batch docs say a transaction has nowhere to keep an importer's own id. That leaves only document_number, the date and the counterparty to deduplicate on. With `external`, the importer stores its own id and finds it again.
Output: one migration, one shared domain rule (ExternalObject), one shared HTTP helper (RawJsonBody), the column in the create, update and batch paths and in the response, contract and examples, Scribe docs, and tests (new TransactionExternalTest, plus extra cases in TransactionTablesTest and TransactionPaymentIncomeTest).

Assumptions and scope decisions (no CONTEXT.md exists for this quick task; Claude's discretion, verified against the code while planning):
- "Transactions" means the `transactions` table (App\Domain\Finance\Models\Transaction) behind /api/v1/transactions, the only transaction table the 09.1 API writes. `transaction_categories` is read-only over the API and is not touched.
- Same column name, type and semantics as on time entries: `external`, a nullable jsonb that is only ever a JSON object. It is a free-form object (not a plain string), at most 4096 bytes as compact JSON (`kokpit.api.external_max_bytes`, reused, not duplicated) and at most 8 levels deep. Like the time entry column it has no index, no unique constraint, no list filter and no dedup on the server.
- One rule for both tables. The body of TimeEntryInput::external() moves to the new App\Domain\Api\ExternalObject::parse(), and TimeEntryInput::external() delegates to it. The same goes for the raw-body decoder: TimeEntryPayload::rawBody() moves to App\Http\Requests\Api\V1\RawJsonBody::of(), and TimeEntryPayload::rawBody() delegates to it. Every time entry caller stays untouched. The three Czech messages stay where they are (`kokpit.time.errors.external_*`; their wording is generic: "Pole external ..."), so no lang file or time entry test changes.
- The response contract of D-07 (09.1) gains one key, `external`, after `project_id`. The change is additive: no existing key changes name, order or type. The owner asked for it in this task.
- The income of an invoice payment keeps `external` null. kokpit_guard_payment_income() (migration 2026_10_17_000400, ruling R-06-5) freezes every column of such a row except the date, the category and the CZK figure. A new column is frozen there automatically, and the guard function is deliberately not changed. UpdateTransaction refuses an object there with the existing `payment_transaction_locked` message under `external`, so the API answers 422 instead of a 500 from KP001. Letting an importer tag a payment income would be a separate owner decision (it widens a writer-independent guard).
- The panel neither shows nor sends `external`. TransactionResource::formState()/actionData() carry only form keys, so saving the edit form keeps the stored object. The column is not added to #[LoggedAttributes] or to #[Fillable] (it is written only with forceFill by the Actions).
- The working tree holds unrelated uncommitted files: .planning/config.json, .planning/state.json, .planning/milestone.lock and .planning/phases/09.1-transactions-api/.gitkeep. Never stage them. Stage only the paths listed in each task's `<files>`, by name.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@.planning/phases/09.1-transactions-api/09.1-CONTEXT.md
@.planning/phases/07-rest-api/07-06-SUMMARY.md
@database/migrations/2026_10_14_000100_add_external_to_time_entries_table.php
@database/migrations/2026_10_17_000400_link_transactions_to_invoices.php
@app/Domain/TimeTracking/TimeEntryInput.php
@app/Http/Requests/Api/V1/TimeEntryPayload.php
@app/Http/Requests/Api/V1/TransactionPayload.php
@app/Domain/Finance/Actions/RecordTransaction.php
@app/Domain/Finance/Actions/UpdateTransaction.php
@app/Domain/Finance/Models/Transaction.php
@app/Http/Controllers/Api/V1/TransactionController.php
@app/Http/Controllers/Api/V1/TransactionBatchController.php
@app/Http/Resources/Api/V1/TransactionResource.php
@tests/Feature/Api/TimeEntryExternalTest.php
@tests/Support/TransactionContract.php

Facts the executor can rely on (verified while planning):
- Tests, Pint and PHPStan run only inside DDEV (`ddev exec ...`), because the host has no database. The docs tests (ApiDocs::operation, ApiDocumentationTest, TransactionContractTest) regenerate Scribe in a subprocess, so never run two ddev test commands in parallel. Pest uses RefreshDatabase for tests/Feature, so a new migration is applied to the test database automatically. To update the local dev database, run `ddev exec php artisan migrate` once.
- The time entry pattern that is mirrored here: the migration `2026_10_14_000100_add_external_to_time_entries_table.php` adds a jsonb column and the CHECK `external IS NULL OR jsonb_typeof(external) = 'object'`, with no down(). TimeEntry casts `'external' => 'object'`, so it reads back as stdClass or null. TimeEntryInput::external() (lines 150-188) refuses non-objects, measures the compact JSON in bytes against `config('kokpit.api.external_max_bytes')` (4096), and uses a json_encode depth of EXTERNAL_MAX_DEPTH = 8. It throws ValidationException under `external` with `kokpit.time.errors.external_not_object|external_too_large|external_too_deep`. TimeEntryPayload::rawBody() (lines 43-60) decodes the body once per request as objects, cached under the request attribute 'kokpit.api.raw_body'. TimeEntryPayload::external($validated, $raw) (lines 181-196) puts the key into the Action data exactly when the body names it: from $raw when there is a JSON object body, otherwise from $validated. TimeEntryBatchController lines 72-80 show the batch wiring with `$raw[$index] ?? null`.
- Transaction writes: TransactionPayload::forCreate(array $validated, ReferenceResolver $refs), forUpdate(array $validated, Transaction $stored, ReferenceResolver $refs) and forBatchItem(mixed $item, ReferenceResolver $refs) build the allow-listed Action data. TransactionController::store (line 203) and update (line 245) call them, and TransactionBatchController line 87 calls forBatchItem. RecordTransaction::write() is the one insert, shared by handle() and writePaymentIncome(). Phase 11 plans call RecordTransaction::handle() with data keys only, so an optional `external` key is backward-compatible. UpdateTransaction::handle() locks the row and, for a payment income, calls assertOnlyDateAndCategoryChange($locked, $data). That method collects changed keys and throws `invoices.errors.payment_transaction_locked` (with the invoice number) under every changed key.
- The transaction wire contract is pinned in four places that must change together: TransactionResource::toArray(), tests/Support/TransactionContract::KEYS (order and JSON type, with a `?` prefix for nullable), TransactionExamples::TRANSACTION and both items of TRANSACTION_LIST, and the ResponseField attributes of show. The test 'marks exactly the optional keys of the response as nullable in the generated spec' compares the spec property order and the nullable flags with KEYS. The time entry precedent: TimeEntryContract pins `'external' => '?array'` (a decoded JSON object is a PHP array), and TimeEntryController declares `#[ResponseField('data.external', 'object', '...', nullable: true)]`.
- ApiDocumentationTest (IN-02) matches the sentences 'records up to 500 transactions' and 'more than 500 transactions, answers `422`' in config('scribe.intro_text'). Keep both sentences word for word when editing the transaction batch bullets of the intro.
- Test helpers: ApiFixtures::admin(), ApiFixtures::token($user, ['finance:read'|'finance:write']), ApiFixtures::client(), ApiFixtures::project($client, 'DEMO'); FinanceApiFixtures::softwareCategory(), FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125'), FinanceApiFixtures::transaction($admin, $actionDataOverrides) (records through RecordTransaction with major-unit 'amount', for example '100,00'). In TransactionWriteTest, txWriteBody() shows the minimal create body (type, transaction_date, category_id, amount_minor, currency). Call Auth::forgetGuards() between requests with different tokens, as TimeEntryExternalTest does. Pest helper functions are global: prefix every new helper with `txExternal`, because `externalBody`, `externalNested`, `externalOfBytes` and `externalEntry` already exist in TimeEntryExternalTest.
- Schema helpers in TransactionTablesTest: transactionTablesInsert($overrides) (raw SQL insert, string values bind to jsonb), transactionTablesBooked() (an income that books a payment), RawSql::expectSqlState('23514'|'KP001', fn), RawSql::expectAllowed(fn). In TransactionPaymentIncomeTest, apiPaymentIncome() and the `$this->patch` closure of the describe 'changing the income of a payment over the API' (lines 57-95) are the fixtures for a PATCH on a payment income.
- Panel test pattern (tests/Feature/Finance/TransactionResourceTest.php): `Filament::setCurrentPanel(Filament::getPanel('admin'))`, actingAs an Admin, `Livewire::test(EditTransaction::class, ['record' => $id])->fillForm([...])->call('save')->assertHasNoFormErrors()`. The morph alias of Transaction in activity_log.subject_type is 'transaction'.
- PHPStan level 8 with Larastan, no strict-rules: a loose `!=` between stdClass values is allowed, and it compares properties regardless of key order. Pint enforces declare(strict_types=1) and removes unused imports. After the move, the JsonException and stdClass imports of TimeEntryInput, and the stdClass import of TimeEntryPayload, are still needed only if still referenced.
- Phase 11 plans will create migrations 2026_10_18_000500 and 000600, so this plan uses 2026_10_18_000900.
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: End-to-end "an importer creates a transaction with its own external object and reads it back"</name>
  <files>database/migrations/2026_10_18_000900_add_external_to_transactions_table.php, app/Domain/Api/ExternalObject.php, app/Domain/TimeTracking/TimeEntryInput.php, app/Domain/Finance/Models/Transaction.php, app/Domain/Finance/Actions/RecordTransaction.php, app/Http/Requests/Api/V1/RawJsonBody.php, app/Http/Requests/Api/V1/TimeEntryPayload.php, app/Http/Requests/Api/V1/TransactionPayload.php, app/Http/Controllers/Api/V1/TransactionController.php, app/Http/Resources/Api/V1/TransactionResource.php, app/Http/Controllers/Api/V1/TransactionExamples.php, tests/Support/TransactionContract.php, tests/Feature/Api/TransactionExternalTest.php</files>
  <precondition>DDEV runs for this project: `ddev exec php -v` exits 0</precondition>
  <behavior>
    - POST /api/v1/transactions with txExternalBody(['external' => ['source' => 'demo-bank', 'id' => 'TX-1001', 'tags' => ['x']]]) and a finance:write token answers 201. `data.external` equals the sent object, compared with toEqual because jsonb does not keep key order. GET /api/v1/transactions/{id} and GET /api/v1/transactions (finance:read) return the same object under `data.external` and `data.0.external`
    - A create without `external` answers 201 with the key `data.external` present and null
    - A create with `new stdClass` returns raw content containing '"external":{}' and not '"external":[]'. `select jsonb_typeof(external)` for the row is 'object', and GET returns '"external":{}'
    - A create whose `external` is [], [1], 'text', 5 or true answers 422 with errors.external.0 === __('kokpit.time.errors.external_not_object'), and the transactions table stays empty
    - An object of exactly 4096 compact bytes is accepted. 4097 bytes answers errors.external.0 === __('kokpit.time.errors.external_too_large', ['max' => 4096]). 8 nesting levels are accepted, and 9 answers __('kokpit.time.errors.external_too_deep')
    - TransactionContract::assertShape passes on the detail response (the `external` key after `project_id`), and TransactionContractTest passes, its spec-nullable test included
  </behavior>
  <action>
RED first. Create tests/Feature/Api/TransactionExternalTest.php in the style of tests/Feature/Api/TimeEntryExternalTest.php. Use a file comment that names this quick task and says every value is fictional. Add the helpers txExternalBody(array $extra = []) (the txWriteBody shape: a CZK expense in FinanceApiFixtures::softwareCategory(), amount_minor 123450, plus $extra), txExternalNested(int $levels) and txExternalOfBytes(int $bytes, string $filler = 'a'), copied in behaviour from the time entry helpers. Add the behavior cases above, each as its own it(), with real Sanctum tokens from ApiFixtures. Run the file and see it fail.

GREEN, in this order:
(a) Create database/migrations/2026_10_18_000900_add_external_to_transactions_table.php. Mirror the time entry migration. Its file comment says three things. `external` is the free-form object an importer keeps next to the row, nullable, object-only. It has no index and no unique constraint, because nothing looks a transaction up by it. kokpit_guard_payment_income() compares `to_jsonb(NEW) - allowed` with `to_jsonb(OLD) - allowed`, so the new column is frozen on the income of an invoice payment without any change to the guard, deliberately. up() runs two DB::statement calls: ALTER TABLE transactions ADD COLUMN external jsonb, and ADD CONSTRAINT transactions_external_object_check CHECK (external IS NULL OR jsonb_typeof(external) = 'object'). Add a down() that drops the constraint and then the column, both with IF EXISTS.
(b) Create app/Domain/Api/ExternalObject.php as `final class ExternalObject` with `public const int MAX_DEPTH = 8;` and `public static function parse(mixed $value): ?stdClass`. Move the body and the docblock of TimeEntryInput::external() into it unchanged: null stays null, only a stdClass passes, json_encode with JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR and depth MAX_DEPTH, a JSON_ERROR_DEPTH answer is external_too_deep and any other JsonException is external_not_object, and the byte cap comes from config('kokpit.api.external_max_bytes'). Errors are ValidationException::withMessages(['external' => __('kokpit.time.errors.'.$message, $replace)]). The class docblock says this is the one rule for every free-form `external` object (time entries D-15, transactions), and that the messages keep their D-15 keys under kokpit.time.errors because their wording names only the field. In TimeEntryInput, set `public const int EXTERNAL_MAX_DEPTH = ExternalObject::MAX_DEPTH;` and make external() a one-line delegate to ExternalObject::parse($value), keeping its signature and a short docblock. Remove imports that are no longer used.
(c) Create app/Http/Requests/Api/V1/RawJsonBody.php as `final class RawJsonBody` with `public static function of(Request $request): ?stdClass`. Move the body, the cache constant ('kokpit.api.raw_body') and the docblock of TimeEntryPayload::rawBody() into it. Make TimeEntryPayload::rawBody() a one-line delegate to RawJsonBody::of($request), and update the paragraph of the TimeEntryPayload class docblock that names rawBody().
(d) In app/Domain/Finance/Models/Transaction.php, add `'external' => 'object'` to casts() and `@property \stdClass|null $external` after invoice_payment_id. Add one class-docblock sentence: `external` is the importer's free-form object, written only by RecordTransaction and UpdateTransaction with forceFill, never logged and never shown in the panel. Do not add it to #[Fillable] or #[LoggedAttributes].
(e) In RecordTransaction::write(), before DB::transaction, set $external from ExternalObject::parse($data['external'] ?? null), and add `'external' => $external` to the forceFill of the new row. Add `external` (an optional JSON object of an importer) to the Data keys paragraph of the class docblock. RecordPayment never passes it, so a payment income is inserted with null.
(f) In TransactionPayload: add `'external' => ['nullable']` to rules(), with a comment that the shape and size are decided by ExternalObject::parse() on the raw body. Give forCreate a third parameter `?stdClass $raw = null` and append the external part to its returned array. Add `private static function external(array $validated, ?stdClass $raw): array`, a copy of TimeEntryPayload::external(): the key is present exactly when the body names it, read from $raw when there is one, otherwise from $validated. Add an `external` paragraph to the class docblock.
(g) In TransactionController::store, pass RawJsonBody::of($request) as the third argument of TransactionPayload::forCreate. On show, store and update, insert `#[ResponseField('data.external', 'object', 'The free-form JSON object your import tool stored with the transaction, or null.', nullable: true)]` between the data.project_id and data.created_at fields.
(h) In TransactionResource::toArray(), add `'external' => $transaction->external` between project_id and created_at, and add a docblock sentence that `external` is the importer's object, or null.
(i) In TransactionExamples, add `'external' => ['source' => 'demo-bank', 'id' => 'TX-1001']` to TRANSACTION['data'] and `'external' => null` to the second TRANSACTION_LIST item, each after project_id.
(j) In tests/Support/TransactionContract.php, add `'external' => '?array'` after project_id in KEYS. Add a docblock sentence that a decoded JSON object is a PHP array.
Run the verify command and make it pass. TimeEntryExternalTest, TimeEntryWriteTest and TimeEntryBatchTest must stay green unchanged, because they prove the extraction changed nothing for time entries. If a pinned time entry test fails, fix the extraction, not the test.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Api/TransactionExternalTest.php tests/Feature/Api/TransactionContractTest.php tests/Feature/Api/TransactionWriteTest.php tests/Feature/Api/TransactionListTest.php tests/Feature/Api/TimeEntryExternalTest.php tests/Feature/Api/TimeEntryWriteTest.php tests/Feature/Api/TimeEntryBatchTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G && grep -q 'transactions_external_object_check' database/migrations/2026_10_18_000900_add_external_to_transactions_table.php && grep -q 'ExternalObject::parse' app/Domain/Finance/Actions/RecordTransaction.php && grep -q 'ExternalObject::parse' app/Domain/TimeTracking/TimeEntryInput.php && grep -q 'RawJsonBody::of' app/Http/Controllers/Api/V1/TransactionController.php && grep -q "'external' => '?array'" tests/Support/TransactionContract.php</automated>
  </verify>
  <done>An importer can POST a transaction with its own `external` object and get the same object back from create, detail and list. A non-object, oversized or too-deep value is refused under `external` with the time entry messages. The transaction contract, its examples and the spec carry `external` after `project_id`. Time entries behave exactly as before. Pint and PHPStan are green.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Change and clear `external` with PATCH, send it per batch item, and keep it locked on a payment income</name>
  <files>app/Domain/Finance/Actions/UpdateTransaction.php, app/Http/Requests/Api/V1/TransactionPayload.php, app/Http/Controllers/Api/V1/TransactionController.php, app/Http/Controllers/Api/V1/TransactionBatchController.php, tests/Feature/Api/TransactionExternalTest.php, tests/Feature/Api/TransactionPaymentIncomeTest.php</files>
  <behavior>
    - A transaction recorded with FinanceApiFixtures::transaction($admin, ['external' => (object) ['source' => 'demo-bank']]) keeps that object after a PATCH that names only `description`
    - PATCH {'external': null} answers 200 with `data.external` null, and the column is SQL NULL
    - PATCH with (object) ['id' => 'TX-2002'] on a row holding ['source' => 'demo-bank', 'old' => 'gone'] returns exactly ['id' => 'TX-2002'] (no merge)
    - PATCH {'external': []} answers 422 under `external`, and a following PATCH with an object answers 200 and stores it
    - On an own-rate EUR transaction (exchange_rate '25.125', amount '100,00'), a PATCH of only `external` leaves czk_minor, czk_source 'custom', exchange_rate and exchange_rate_date exactly as before
    - POST /api/v1/transactions/batch with items A (external {'id': 'TX-3001'}), B (external [1]) and C (external {}) answers 207 with saved 2 and failed 1. The error of index 1 has errors.external. Row A stores {'id': 'TX-3001'}, row C has jsonb_typeof 'object'
    - In TransactionPaymentIncomeTest, a PATCH of the payment income with {'external': {'id': 'TX-4001'}} answers 422 with errors.external === [payment_transaction_locked message], and the row is unchanged (whole-row compare as in the existing case). A PATCH with {'transaction_date': '2026-10-12', 'external': null} answers 200
  </behavior>
  <action>
RED first. Add the PATCH and batch cases to tests/Feature/Api/TransactionExternalTest.php, with a helper txExternalBatchItem(array $overrides = []) if needed. Add the payment-income case as a new it() inside the describe 'changing the income of a payment over the API' of tests/Feature/Api/TransactionPaymentIncomeTest.php, using its `$this->patch` closure and its `$message` construction. Run both files and see the new cases fail.

GREEN:
(a) UpdateTransaction::handle(): before DB::transaction, set $external to ExternalObject::parse($data['external']) when the data names `external`, otherwise to null. This mirrors UpdateTimeEntry line 75: a bad value fails before any row is locked. Pass $external into assertOnlyDateAndCategoryChange as a new third parameter `?stdClass $external`. There, when the data names `external` and `$external != $stored->external` (a loose stdClass comparison, so key order does not matter), add 'external' to $changed. On a payment income the stored value is always null, so any object is a change and a sent null is not. After the existing forceFill on the locked row, and only when the data names `external`, forceFill `['external' => $external]`. Extend the class docblock: `external` follows absent keeps, null clears, an object replaces the stored one whole, nothing is merged. It does not touch the CZK figure, and it is part of what a payment income refuses.
(b) TransactionPayload::forUpdate(): add a fourth parameter `?stdClass $raw = null` and append the external part, built by the private external() helper from Task 1, to the returned array. TransactionPayload::forBatchItem(): change the signature to `forBatchItem(mixed $item, mixed $rawItem, ReferenceResolver $refs)`. Keep the existing not-an-object check on $item unchanged (it decides the `transaction` error). Then call forCreate($validated, $refs, $rawItem instanceof stdClass ? $rawItem : null). Update both docblocks.
(c) TransactionController::update: pass RawJsonBody::of($request) as the fourth argument of TransactionPayload::forUpdate.
(d) TransactionBatchController::__invoke: read the raw items as `RawJsonBody::of($request)->transactions ?? []` and keep them only when they are an array, the same as TimeEntryBatchController lines 74-75. Pass `$raw[$index] ?? null` to forBatchItem.
Run the verify command. The existing PaymentTransactionLockTest and TransactionResourceTest cases must pass unchanged. They prove that the panel and the lock behave as before.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Api/TransactionExternalTest.php tests/Feature/Api/TransactionPaymentIncomeTest.php tests/Feature/Api/TransactionBatchTest.php tests/Feature/Api/TransactionWriteTest.php tests/Feature/Api/TransactionIdempotencyTest.php tests/Feature/Finance/PaymentTransactionLockTest.php tests/Feature/Finance/TransactionResourceTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G && grep -q 'ExternalObject::parse' app/Domain/Finance/Actions/UpdateTransaction.php && grep -q 'RawJsonBody::of' app/Http/Controllers/Api/V1/TransactionBatchController.php && grep -q 'forBatchItem(mixed $item, mixed $rawItem' app/Http/Requests/Api/V1/TransactionPayload.php</automated>
  </verify>
  <done>PATCH keeps, clears or replaces `external` without touching the CZK figure. Every batch item carries its own object, and a bad one fails only that item. The income of an invoice payment refuses an object under `external` with the existing Czech lock message and stays unchanged. The panel and payment-lock suites are green.</done>
</task>

<task type="auto">
  <name>Task 3: Database and non-leak proofs, API documentation, and the full gate</name>
  <files>tests/Feature/Schema/TransactionTablesTest.php, tests/Feature/Api/TransactionExternalTest.php, app/Http/Requests/Api/V1/StoreTransactionRequest.php, app/Http/Requests/Api/V1/UpdateTransactionRequest.php, app/Http/Controllers/Api/V1/TransactionBatchController.php, app/Http/Controllers/Api/V1/TransactionController.php, config/scribe.php, config/kokpit.php</files>
  <action>
(a) Proofs. In tests/Feature/Schema/TransactionTablesTest.php:
- Add a describe 'the external object'. It mirrors TimeEntriesTableTest lines 132-152 with transactionTablesInsert(). A non-object value ('[]', '[1]', '"x"', '5', 'true', and a JSON 'null') makes the insert fail with a message containing transactions_external_object_check. On an existing row, an `update transactions set external = ?::jsonb` answers 23514. '{}', '{"source": "demo-bank"}' and an absent value are allowed.
- In the describe 'the income of a payment (ruling R-06-5)', add an it() saying that the payment income keeps its external object frozen. Using transactionTablesBooked(), an update that sets external to '{"id": "TX-5001"}'::jsonb on the income answers KP001.
In tests/Feature/Api/TransactionExternalTest.php, add:
- no activity row change and no 'external' or canary text in the activity_log rows of subject_type 'transaction' when a PATCH changes only `external`, and none for a create that carries it (mirror the time entry case);
- a panel case on the admin panel as the Admin: with a canary 'canary-'.bin2hex(random_bytes(6)) stored in `external`, GET /admin/transactions and /admin/transactions/{id}/edit both answer 200, assertDontSee the canary and assertDontSee '&quot;external&quot;', with the raw flag false;
- a panel save case: Livewire::test(EditTransaction::class) with fillForm of a new description and call('save') has no form errors and leaves the stored `external` object unchanged;
- a docs case: through ApiDocs::operation for the routes api.v1.transactions.store and api.v1.transactions.update, the request body schema properties contain `external`, and the batch operation's `transactions` items schema contains `external`. Read the property path from the generated spec. If the batch nesting differs, assert the documented key the way TransactionBatchTest reads its documented body.
(b) Documentation.
- StoreTransactionRequest::bodyParameters() and UpdateTransactionRequest::bodyParameters(): add an `external` entry after project_id with `'type' => 'object'` and the example ['source' => 'demo-bank', 'id' => 'TX-1001']. Adapt the text of StoreTimeEntryRequest's external description to a transaction: your own free-form JSON object, for example the id of the transaction in the bank or tool it was imported from; optional; must be an object; at most 4096 bytes as compact JSON and 8 levels deep; returned with the transaction (key order not kept); never shown in the application and never used to look transactions up or skip duplicates. The update text adds: an absent key keeps it, null clears it, an object replaces it whole; the income of an invoice payment takes none.
- TransactionBatchController: add `#[BodyParam('transactions[].external', 'object', ..., required: false, example: ['source' => 'demo-bank', 'id' => 'TX-1001'])]` after the project_id BodyParam, with the time entry batch wording adapted. Add the same `external` example to EXAMPLE_TRANSACTION. In the Endpoint description, replace the sentence that tells the client a transaction has nowhere to keep its own id (the one listing `document_number`, the date and the counterparty) with: deduplicate on your side, store your own id in `external` (it comes back with every transaction; the API never skips a duplicate because of it), and list the period before you send a batch again under a new key.
- TransactionController Endpoint descriptions: show adds that `external` is the free-form JSON object your import tool stored with the transaction, or `null`. Create adds that an optional `external` object (at most 4096 bytes, 8 levels) is stored as sent. Update adds the keep/clear/replace rule for `external`.
- config/scribe.php intro, in the transaction batch bullets: replace the bullet about the missing own-id field (the one listing document_number, the date and the counterparty) with a bullet saying that duplicates are avoided the same way as for entries: store your own id in `external` (it comes back with every transaction), and list the transactions of the period before you resend under a new key. Keep the sentences 'records up to 500 transactions' and 'more than 500 transactions, answers `422`' word for word (ApiDocumentationTest IN-02).
- config/kokpit.php: change the comment line of external_max_bytes to say the object is stored with an entry or a transaction. The value stays 4096.
(c) Full gate. Run `ddev composer ci` on its own (pest, pint, phpstan, licenses), not in parallel with another ddev command. Run scripts/check-sensitive.sh over every file this plan touched. Only fictional values ('demo-bank', 'TX-…', example.com) may appear. Commit each task with the house style (`feat(quick-261018-mic): ...`, docs with `docs(quick-261018-mic): ...`). Stage explicit paths only, never .planning/config.json, .planning/state.json, .planning/milestone.lock or .planning/phases/09.1-transactions-api/.gitkeep. Never bypass the lefthook hook.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Schema/TransactionTablesTest.php tests/Feature/Api/TransactionExternalTest.php tests/Feature/Api/ApiDocumentationTest.php tests/Feature/Api/TransactionBatchTest.php && ! grep -q 'natural keys' config/scribe.php app/Http/Controllers/Api/V1/TransactionBatchController.php && grep -qF "transactions[].external" app/Http/Controllers/Api/V1/TransactionBatchController.php && grep -q "'external' =>" app/Http/Requests/Api/V1/StoreTransactionRequest.php && grep -q "'external' =>" app/Http/Requests/Api/V1/UpdateTransactionRequest.php && ddev composer ci && scripts/check-sensitive.sh database/migrations/2026_10_18_000900_add_external_to_transactions_table.php app/Domain/Api/ExternalObject.php app/Domain/TimeTracking/TimeEntryInput.php app/Domain/Finance/Models/Transaction.php app/Domain/Finance/Actions/RecordTransaction.php app/Domain/Finance/Actions/UpdateTransaction.php app/Http/Requests/Api/V1/RawJsonBody.php app/Http/Requests/Api/V1/TimeEntryPayload.php app/Http/Requests/Api/V1/TransactionPayload.php app/Http/Requests/Api/V1/StoreTransactionRequest.php app/Http/Requests/Api/V1/UpdateTransactionRequest.php app/Http/Controllers/Api/V1/TransactionController.php app/Http/Controllers/Api/V1/TransactionBatchController.php app/Http/Controllers/Api/V1/TransactionExamples.php app/Http/Resources/Api/V1/TransactionResource.php config/scribe.php config/kokpit.php tests/Support/TransactionContract.php tests/Feature/Api/TransactionExternalTest.php tests/Feature/Api/TransactionPaymentIncomeTest.php tests/Feature/Schema/TransactionTablesTest.php</automated>
  </verify>
  <done>The database refuses a non-object `external` from any writer and keeps it frozen on a payment income. `external` never reaches the activity log or the panel, and a panel save keeps it. The API docs describe it on create, update and batch items and tell importers to deduplicate with it. `ddev composer ci` and the sensitive-data check are green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| API client (n8n token) → /api/v1/transactions, /batch | `external` is arbitrary client-supplied JSON, stored and echoed back |
| Action layer → PostgreSQL | Writers other than the API (raw SQL, future code) can write the column |
| Finance data → Partner session / panel | Transactions are Admin-only. The new column must not open a read path |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261018-mic-01 | Tampering | transactions.external (any writer) | medium | mitigate | CHECK transactions_external_object_check refuses every non-object JSON value in the database. ExternalObject::parse() refuses non-objects in the Actions. Proven by the TransactionTablesTest 23514 cases and the API 422 cases |
| T-261018-mic-02 | Denial of Service | ExternalObject::parse on create, update, batch | medium | mitigate | 4096-byte cap (kokpit.api.external_max_bytes), measured on the compact JSON in bytes, and a depth cap of 8 in json_encode. A batch stays capped at 500 items and counts as one write against the rate limit. Proven by the limit tests at the exact boundaries |
| T-261018-mic-03 | Tampering | UpdateTransaction on a payment income | high | mitigate | assertOnlyDateAndCategoryChange refuses a changed `external` with payment_transaction_locked before any write. kokpit_guard_payment_income() refuses it with KP001 for every other writer, and the guard is left unchanged. Proven by the TransactionPaymentIncomeTest 422 case and the TransactionTablesTest KP001 case |
| T-261018-mic-04 | Elevation of Privilege | Mass assignment of `external` | medium | mitigate | The column is not in #[Fillable]. Only RecordTransaction and UpdateTransaction write it with forceFill. TransactionPayload adds the key only when the body names it, and the rest of the allow-list is unchanged |
| T-261018-mic-05 | Information Disclosure | Activity log, panel pages, Partner | medium | mitigate | Not in #[LoggedAttributes]. The panel fills only form keys (formState/actionData). DeniesPartners and the finance:* abilities are unchanged. Proven by the activity-log, panel canary and panel save cases |
| T-261018-mic-06 | Tampering (injection/XSS) | Storage and rendering of client JSON | low | mitigate | Stored through bound parameters and the `object` cast. Returned only as JSON by the API resource and never rendered in Blade |
| T-261018-mic-07 | Information Disclosure | Test fixtures, examples, docs, planning files | low | mitigate | Only fictional values ('demo-bank', 'TX-…', example.com). scripts/check-sensitive.sh over every touched file, and the lefthook gitleaks hook |
| T-261018-mic-SC | Tampering | composer/npm installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Api/TransactionExternalTest.php tests/Feature/Api/TimeEntryExternalTest.php tests/Feature/Schema/TransactionTablesTest.php tests/Feature/Api/TransactionPaymentIncomeTest.php` is green
- `ddev composer ci` is green (pest, pint, phpstan, licenses)
- `scripts/check-sensitive.sh` passes over every touched file
- Human check (not blocking): after `ddev exec php artisan migrate` and `ddev exec php artisan scribe:generate`, open the local /docs. "Create a transaction" lists `external` as an optional object, and the batch section tells you to deduplicate with `external`
</verification>

<success_criteria>
- A transaction carries an optional `external` JSON object, written through create, PATCH and batch and returned on detail, list and every write answer
- Validation, limits and messages are identical to time entries, because both use one shared rule. Time entry behavior is unchanged
- The payment income stays locked, the database enforces the object-only shape, and nothing leaks into the log or the panel
- The docs tell importers to deduplicate with `external`. The full CI gate and the sensitive-data check are green
</success_criteria>

<output>
Create `.planning/quick/261018-mic-add-an-external-identifier-column-to-tra/261018-mic-SUMMARY.md` when done
</output>
