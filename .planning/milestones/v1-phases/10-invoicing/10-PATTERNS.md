# Phase 10: Invoicing - Pattern Map

**Mapped:** 2026-10-16
**Files analyzed:** 38 new or modified (grouped where one analog serves several files)
**Analogs found:** 34 / 38 (all analog paths verified git-tracked; none is a gitignored mirror)

Class and table names are working labels (CONTEXT "Claude's Discretion"). The analogs below are real, current code; RESEARCH.md patterns (Pattern 1 to 12) say what to build, this file says what to copy from.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_10_17_*_create_invoices_*` (invoices, invoice_items, invoice_admin, invoice_item_sources, invoice_payments, invoice_emails) | migration | CRUD + guard triggers | `database/migrations/2026_10_16_000400_create_transactions_table.php`, `database/migrations/2026_10_11_000100_create_time_entries_table.php` | exact |
| migration: `time_entries.invoice_id` + re-created guard + companion transition trigger | migration | event-driven (trigger) | `2026_10_11_000100_create_time_entries_table.php` lines 82-95 | exact |
| migration: `transactions.invoice_id`, `invoice_payment_id` | migration | CRUD | `2026_10_16_000400_create_transactions_table.php` (composite FK style) | exact |
| migration: child-of-frozen-parent guard function | migration | event-driven (trigger) | `app/Domain/Shared/Database/Immutability.php` + `database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php` | role-match |
| `app/Domain/Shared/Database/Immutability.php` (add child-guard builder) | utility | transform (SQL builder) | itself | exact |
| `app/Domain/Shared/Money/Money.php` (`times`, `negated`, sign/compare, cross-currency) | utility | transform | itself (`convert`, `fromExactMinor`) | exact |
| `app/Domain/Invoicing/Models/{Invoice,InvoiceItem,InvoiceAdmin,InvoiceItemSource,InvoicePayment,InvoiceEmail}.php` | model | CRUD | `app/Domain/Projects/Models/Project.php` (Partner-isolated), `app/Domain/Finance/Models/Transaction.php` (Admin-only) | exact |
| `app/Domain/Invoicing/Policies/InvoicePolicy.php` | policy | request-response | `app/Domain/Documents/Policies/DocumentPolicy.php` | role-match |
| `app/Domain/Invoicing/Actions/{CreateDraft,UpdateDraft,DeleteDraft,ReleaseEntries,AddEntriesToDraft,AddFixedAmount}.php` | service (Action) | CRUD | `app/Domain/TimeTracking/Actions/MarkEntriesBilled.php` | exact |
| `app/Domain/Invoicing/Actions/IssueDocument.php`, `IssueCreditNote.php`, `ConvertProforma.php` | service (Action) | request-response (one transaction) | `MarkEntriesBilled.php` + `DocumentNumbering::next()` + `AttachDocuments.php` | role-match |
| `app/Domain/Invoicing/Actions/RecordPayment.php`, `DeletePayment.php` | service (Action) | CRUD | `app/Domain/Finance/Actions/RecordTransaction.php` | exact |
| `app/Domain/Invoicing/Actions/QueueInvoiceEmail.php`, `RegeneratePdf.php`, `UpdateInternalNote.php` | service (Action) | event-driven / CRUD | `RecordTransaction.php` (Action shape), `BuildDocumentArchive` dispatch pattern | role-match |
| `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` | job | event-driven | `app/Domain/Documents/Jobs/BuildDocumentArchive.php` | exact |
| `app/Domain/Invoicing/Notifications/InvoiceEmailFailedNotification.php` | notification | event-driven | `app/Domain/Documents/Notifications/DocumentArchiveFailedNotification.php` | exact |
| `app/Domain/Invoicing/Pdf/{InvoicePdf,InvoiceFonts,PageDecoration}.php` | service | transform (HTML to PDF) | `app/Domain/Reports/WorkReport/WorkReportPdf.php`, `WorkReportPageDecoration.php` | exact |
| `app/Domain/Invoicing/Pdf/{DocumentPalette,Spayd,QrPng}.php`, `Support/{InvoiceQuantity,VariableSymbol,IssueChecks,DraftTotals}.php` | utility | transform | `app/Domain/Settings/Numbering/NumberPattern.php` (pure value logic) | partial |
| `resources/views/pdf/invoice.blade.php`, `lang/{cs,en}/invoice_pdf.php` | view | transform | `resources/views/pdf/work-report.blade.php` | exact |
| `app/Domain/Invoicing/Queries/{ReceivablesReader,InvoiceableEntries}.php` | service (reader) | batch / read | `app/Domain/Reports/Queries/TimeReportReader.php` | exact |
| `app/Domain/Documents/Support/DocumentOwnerResolver.php`, `Enums/DocumentOwnerType.php` (add `invoice`) | utility | CRUD | existing `Transaction` branch (lines 83-96) and enum `Transaction` case | exact |
| `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php` + `OperationsServiceProvider` edit | provider/indicator | read | `Health/Indicators/LastRateDateIndicator.php`, `OperationsServiceProvider.php:43-61` | exact |
| `app/Filament/Resources/InvoiceResource.php` + Pages (List, Edit, View) | resource | CRUD | `app/Filament/Resources/TransactionResource.php` + `TransactionResource/Pages/*` | exact |
| `InvoiceResource` relation managers (Payments, LinkedTime, Emails, DraftEntries, Documents) | component | CRUD | `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php`, `app/Filament/RelationManagers/TransactionDocumentsRelationManager.php` | exact |
| `app/Filament/Partner/Resources/PartnerInvoiceResource.php` + Pages | resource | request-response (read-only) | `app/Filament/Partner/Resources/PartnerProjectResource.php` | exact |
| `app/Filament/Support/InvoiceColumns.php` (`partnerColumns`, `partnerEntries`) | utility | transform | `app/Filament/Support/ProjectColumns.php` | exact |
| `app/Http/Controllers/InvoicePdfController.php` + signed route | controller | streaming / file-I/O | `app/Http/Controllers/DocumentFileController.php`, `app/Domain/Documents/Support/DocumentUrl.php` | role-match |
| `app/Filament/Widgets/UnpaidInvoices.php` + `Dashboard::getWidgets()` edit | widget | read | `app/Filament/Widgets/UnbilledPerClient.php` (stats variant: `HoursStats.php`), `app/Filament/Pages/Dashboard.php:42-55` | role-match |
| `app/Filament/Resources/ClientResource/{RelationManagers,Widgets}` (invoices, unbilled time, stats) + `ViewClient` | component | read | `ContactsRelationManager.php`, `ViewClient.php` | role-match |
| Settings additions (accent, logo, signature, footer) | config | CRUD | `app/Domain/Settings/Settings/NumberingSettings.php`, `BankAccountSettings.php` (spatie settings) | role-match |
| `app/Domain/Settings/Numbering/NumberPattern.php` (extend digits/10-char rule to proforma) | utility | transform | itself, lines 43, 135, 152 | exact |
| `UpdateTimeEntry`, `DeleteTimeEntry`, `MarkEntriesBilled`, `CancelEntriesBilling` (refuse reserved/invoice-linked rows) | service (Action) | CRUD | existing billed refusal at `UpdateTimeEntry.php:77`, `DeleteTimeEntry.php:40` | exact |
| `UpdateTransaction`, `DeleteTransaction` (refuse payment-derived rows) | service (Action) | CRUD | themselves | exact |
| Tests: schema, guard, concurrency, isolation, PDF, Partner | test | n/a | see "Test patterns" | exact |

## Pattern Assignments

### Migrations: invoices family (migration, CRUD + guard triggers)

**Analog:** `database/migrations/2026_10_16_000400_create_transactions_table.php` (whole file) and `2026_10_11_000100_create_time_entries_table.php`.

**Header docblock convention** (transactions lines 10-36): long comment that states what the DB guarantees and why each CHECK exists. Copy the style, one block per migration.

**Table skeleton** (transactions lines 41-70):
```php
Schema::create('transactions', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->bigInteger('amount_minor');
    $table->char('amount_currency', 3);
    $table->foreignUuid('client_id')->nullable()->constrained('clients');
    $table->uuid('project_id')->nullable();
    $table->timestampsTz();
    $table->index(['client_id', 'transaction_date'], 'transactions_client_id_transaction_date_index');
});
```

**CHECKs and composite FKs are raw `DB::statement` after the table** (lines 72-83):
```php
DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('income', 'expense'))");
DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_check CHECK (amount_currency ~ '^[A-Z]{3}\$')");
DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id)');
```
Apply to: `invoices` (`kind`/`state` CHECKs, `(state='draft') = (number IS NULL)`, `UNIQUE (kind, number) WHERE number IS NOT NULL` via `CREATE UNIQUE INDEX ... WHERE`, `UNIQUE (id, client_id)` as composite-FK target, sign check per kind), `invoice_items` (`CHECK (line_total_minor = round(quantity * unit_price_minor))`), `invoice_payments` (`amount_minor > 0`).

**Composite-unique target added in the same migration** (time_entries line 48): `ALTER TABLE projects ADD CONSTRAINT projects_id_client_unique UNIQUE (id, client_id)`; mirror for `invoices_id_client_unique`. Provide `down()` that drops it (time_entries lines 98-104).

**Partial index style** (time_entries lines 90-92): `CREATE UNIQUE INDEX ... WHERE ...` and `CREATE INDEX ... WHERE billing_state = 'unbilled' AND billable AND ended_at IS NOT NULL`.

**Guard trigger call, at the end of `up()`** (time_entries lines 94-95):
```php
DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds']));
DB::unprepared(Immutability::truncateGuardSql('time_entries'));
```
For `invoices` (revised by plan 10-04 R-04-6: an issued row accepts no write): `Immutability::guardTriggerSql('invoices', 'state', 'draft', [], withUpdatedAt: false)`; the storno marker is the credit note's `cancels_invoice` (R-04-5) and the PDF link is `invoice_admin.pdf_document_id` (plan 10-06 R-06-6). Keep table names at most 48 chars. No stored generated columns on guarded tables (time_entries docblock lines 35-42).

### Migration: re-created `time_entries` guard (migration, trigger)

**Analog:** same time_entries migration lines 94-95 plus `Immutability::dropGuardTriggerSql` (Immutability.php lines 65-70).

```php
DB::unprepared(Immutability::dropGuardTriggerSql('time_entries'));
DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled',
    ['billing_state', 'billed_at', 'duration_seconds', 'invoice_id']));
```
Then add `invoice_id` uuid column with composite FK `(invoice_id, client_id) REFERENCES invoices (id, client_id)` (same shape as `time_entries_project_client_fk`, line 82) and the companion transition trigger as a new function (research Pattern 2 and 7).

### `app/Domain/Shared/Database/Immutability.php` (add child-guard builders)

**Analog:** itself. New builder must follow the validation style.

**Builder pattern** (lines 40-81):
```php
public static function dropGuardTriggerSql(string $table): string
{
    self::assertTable($table);
    return sprintf('DROP TRIGGER IF EXISTS %s_frozen_guard ON %s', $table, $table);
}
```
Add e.g. `childGuardTriggerSql(string $table, string $fkColumn, string $parentTable, string $parentStateColumn, string $openState)` that calls `assertTable`/`assertIdentifier`, emits `... BEFORE INSERT OR UPDATE OR DELETE ... EXECUTE FUNCTION kokpit_guard_child_of_frozen_parent(...)`. Unit-test like `tests/Unit/Database/ImmutabilityTest.php`.

### `app/Domain/Shared/Money/Money.php` (extend)

**Analog:** itself. All rounding goes through `fromExactMinor` (single `HALF_UP` point, enforced by `tests/Arch/MoneyBoundaryTest.php`).

**Exact rational then one rounding** (lines 238-251, `convert`):
```php
public function convert(string $decimalRate, string $toCurrency, int $unitAmount = 1): self
{
    $target = self::validConversion($decimalRate, $toCurrency, $unitAmount);
    return self::fromExactMinor(
        self::convertedExact(BigRational::of($this->minor), $this->currency, $decimalRate, $target, $unitAmount)->toString(),
        $target,
    );
}
```
Copy this shape for `times(string $decimalQuantity)`, `negated()`, `isNegative()`, and a two-quote cross-currency method. Never use `round()`/floats; Brick stays inside this class. Add unit tests next to `tests/Feature/Money/`.

### `app/Domain/Invoicing/Models/*` (model, CRUD)

**Analog (Partner-readable, `Invoice`, `InvoiceItem`):** `app/Domain/Projects/Models/Project.php`.

**Class declaration, attributes and Partner scope** (lines 65-113):
```php
#[Fillable([...])]
#[LoggedAttributes(['client_id', 'name', ...])]   // allowlist; exclude free text / internal_note
final class Project extends KokpitModel implements PartnerIsolated
{
    use HasFactory, IsolatesPartners, LogsAllowlistedActivity, SoftDeletes;

    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $table = $this->getTable();
        $query->where("{$table}.client_id", $clientId)
            ->where("{$table}.client_visible", true)
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id")->whereNull('clients.deleted_at'));
    }
}
```
For `Invoice`: `constrainForPartner` = own client AND `state = 'issued'` AND kind in (invoice, proforma, credit_note); `client_id` not fillable (set by `forceFill`). `InvoiceItem` scope delegates to the scoped invoice query (as `Task` delegates to `Project`).

**Analog (Admin-only, `InvoiceAdmin`, `InvoiceItemSource`, `InvoicePayment`, `InvoiceEmail`):** `app/Domain/Finance/Models/Transaction.php` (uses `DeniesPartners`, money cast via `MoneyCast`). Register morph alias `invoice` in the morph map (`tests/Feature/Schema/MorphMapTest.php` pins it); `tests/Arch/ModelDeclarationTest.php` requires each model to declare its Partner stance.

### `app/Domain/Invoicing/Actions/*` draft and release (service, CRUD)

**Analog:** `app/Domain/TimeTracking/Actions/MarkEntriesBilled.php`.

**Authorize, sanitise ids, one transaction, lock in id order** (lines 52-66, 113-118):
```php
Gate::forUser($actor)->authorize('create', TimeEntry::class);
$ids = TimeEntryInput::uuids($ids);

return DB::transaction(function () use ($actor, $ids): array {
    $rows = $this->selection($ids, lock: true);
    foreach ($rows as $row) { Gate::forUser($actor)->authorize('update', $row); }
    ...
});

$query = TimeEntry::query()->whereKey($ids)->orderBy('id');
return ($lock ? $query->lockForUpdate() : $query)->get();
```
Differences to apply: `CreateDraft` claims entries with the compare-and-set `UPDATE ... WHERE invoice_id IS NULL AND billing_state='unbilled' ... RETURNING id` (RESEARCH Pattern 2) and builds items from the returned ids only. `IssueDocument` needs a strict variant of the eligibility rule (fails loudly instead of skipping, see `buckets()` lines 126-140). Save billed rows as models one by one so each writes an allowlisted activity row (docblock lines 29-37): `$row->forceFill(['billing_state' => BillingState::Billed, 'billed_at' => $now])->save();`. Domain errors use `DomainException(__('kokpit....'))` (line 68).

### `IssueDocument` / `IssueCreditNote` / `ConvertProforma` (service, one transaction)

**Analogs:** `MarkEntriesBilled` (lock/eligibility), `app/Domain/Settings/Numbering/DocumentNumbering.php` (number), `app/Domain/Documents/Actions/AttachDocuments.php` (PDF storage), `ExchangeRateConverter::rateFor()`.

**Number allocated inside the caller's transaction, late** (DocumentNumbering lines 33-41):
```php
public function next(DocumentKind $kind, DateTimeInterface $at): string
{
    $pattern = $this->settings->patternFor($kind);
    $number = $this->allocator->next($pattern->scopeKey($at));
    return $pattern->format($number, $at);
}
```
`preview()` (lines 50-58) gives the "expected number" for the confirm modal (`SequenceAllocator::peek`). Inject `DocumentNumbering` via constructor like `RecordTransaction` injects `TransactionCzk`.

**CNB snapshot** (ExchangeRateConverter lines 62-79): `rateFor(string $currency, string $day): array{rate, unit_amount, rate_date}`; throws `ExchangeRateMissing` (a pre-issue blocker, never zero). Call it once and store the three values; same snapshot trio as `transactions.exchange_rate / exchange_unit_amount / exchange_rate_date` (transactions migration lines 53-55, snapshot CHECK line 78).

**Generated PDF through the hardened upload path:** `AttachDocuments::handle(User $actor, ?Model $owner, array $files, ...)` accepts only `UploadedFile` (lines 74-78, 421-437). Wrap the Dompdf bytes: temp file then `new Illuminate\Http\UploadedFile($tmp, 'faktura-<number>.pdf', 'application/pdf', null, true)`, or add a small `StoreGeneratedDocument` wrapper. Register `DB::afterRollBack(...)` cleanup before the upload (AttachDocuments only cleans on its own failure, lines 109-113, 391-409).

**Order** is RESEARCH Pattern 3 (lock invoice, lock entries by id, blockers, CNB, number, snapshots, state=issued last for item writes, billed, PDF, afterRollBack).

### `RecordPayment` / `DeletePayment` (service, CRUD)

**Analog:** `app/Domain/Finance/Actions/RecordTransaction.php`.

**Action shape** (lines 27-62):
```php
final readonly class RecordTransaction
{
    public function __construct(private TransactionCzk $czk) {}

    public function handle(User $actor, array $data): Transaction
    {
        Gate::forUser($actor)->authorize('create', Transaction::class);

        return DB::transaction(function () use ($data): Transaction {
            $input = TransactionInput::fromArray($data);
            $czk = $this->czk->resolve($input->amount, $input->transactionDate, $input->manualCzk());
            $transaction = new Transaction([...]);
            $transaction->forceFill([...$czk]);
            $transaction->save();
            return $transaction->refresh();
        });
    }
}
```
`RecordPayment` inserts the payment, then (kind `invoice` only) calls `RecordTransaction->handle()` inside the same transaction with `TransactionCategory::invoicePayments()` and sets `invoice_id` / `invoice_payment_id` via `forceFill`. Keep `Gate` check first, validation errors as `ValidationException` with field keys.

`DeletePayment` (D-19 as amended 2026-10-17; plan 10-41) takes its deletion shape from `app/Domain/Finance/Actions/DeleteTransaction.php` (Gate first, one transaction, row re-read under `lockForUpdate`, each receipt through `DeleteDocument`, whose `DB::afterCommit()` object removal is dropped on rollback), but never calls `DeleteTransaction`, which refuses payment income: it locks the document, the payment and the income in that order, sets the transaction-local `kokpit.payment_deletion` that the guards of plan 10-06 read, deletes the income's receipts, the income and the payment through the models, and clears the flag.

### `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` (job, event-driven)

**Analog:** `app/Domain/Documents/Jobs/BuildDocumentArchive.php` (lines 1-75) and base `app/Domain/Operations/Jobs/KokpitJob.php`.

**Declaration** (BuildDocumentArchive lines 68-75; KokpitJob docblock lines 26-35):
```php
#[Idempotent(how: 'identified by the archive id; a record that is not pending is skipped ...')]
#[Timeout(240)]
final class BuildDocumentArchive extends KokpitJob
{
    public function __construct(public readonly string $archiveId) {}
    public function handle(): void { ... }
    public function failed(Throwable $e): void { ... }   // mark failed + bell
}
```
Identify by the `invoice_emails` row id; conditional `UPDATE ... queued -> sending`; unique constraint last line of defence. Imports: `App\Domain\Operations\Jobs\Idempotent`, `KokpitJob`, `PartnerContext`. `tests/Arch/JobContractTest.php` scans for `#[Idempotent]`.

### `InvoiceEmailFailedNotification` (notification)

**Analog:** `app/Domain/Documents/Notifications/DocumentArchiveFailedNotification.php` (whole file, 51 lines): `via() => ['database']`, `toDatabase()` builds `FilamentNotification::make()->title()->body()->danger()->icon(Heroicon::OutlinedExclamationCircle)->actions([Action::make('open')->url($this->url)])->getDatabaseMessage()`. Text fixed, number only, never the recipient address or exception text. Send with `notifyNow`.

### `app/Domain/Invoicing/Pdf/InvoicePdf.php` (service, transform)

**Analog:** `app/Domain/Reports/WorkReport/WorkReportPdf.php`.

**Render pipeline** (lines 41-59) and `html()` returning `view('pdf.work-report', [...])->render()` inside `withLocale($language->value, ...)` (lines 64-108); the `Localizable` trait sets the document language:
```php
$dompdf = new Dompdf($this->options());
$dompdf->setPaper('A4', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();
$dompdf->getCanvas()->page_script($decoration);
return (string) $dompdf->output();
```
**Options** (lines 172-186) — copy but ADD `fontDir` and widen `chroot` to both `resources/views/pdf` and `resources/fonts` (RESEARCH Pitfall 6):
```php
$fontCache = storage_path('framework/dompdf');
File::ensureDirectoryExists($fontCache);
return new Options([
    'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
    'defaultFont' => 'DejaVu Sans',
    'chroot' => resource_path('views/pdf'),
    'fontCache' => $fontCache, 'tempDir' => $fontCache,
]);
```
Also copy `raiseMemoryLimit()` (line 46, 198+), `date()` (Czech `j. n. Y`, English `Y-m-d`, lines 191-196), and the page-script decoration (`WorkReportPageDecoration`). Render from the frozen snapshot only; expose `html()` for the regeneration-equivalence test. `tests/Feature/Reports/WorkReportPdfTest.php` is the test model (markup canary renders as text).

### `resources/views/pdf/invoice.blade.php` (view)

**Analog:** `resources/views/pdf/work-report.blade.php`: tables/floats only (no flex/grid), every value via `{{ }}`, images as data URIs only, fonts through `@font-face` with `file://` TTF URLs.

### `app/Domain/Invoicing/Queries/ReceivablesReader.php` / `InvoiceableEntries.php` (reader)

**Analog:** `app/Domain/Reports/Queries/TimeReportReader.php` (hand-written SQL, `authorise()` refusing everybody but Admin/system; constants `BILLING_TYPE_SQL`, `RATE_MINOR_SQL` are private and must be extracted or extended, not re-implemented; parity test `tests/Feature/Reports/EffectiveBillingParityTest.php`). `tests/Arch/QueryEscapeHatchTest.php` forbids `DB::table` in `app/`; raw SQL only in these guarded readers. Bind "today" from PHP via `app/Domain/TimeTracking/Support/PragueDay.php` / `TimerClock::now()`, never SQL `now()`.

### `DocumentOwnerType` / `DocumentOwnerResolver` (add `invoice`)

**Analog:** the `Transaction` branch.
- Enum (`app/Domain/Documents/Enums/DocumentOwnerType.php` lines 17-34): add `case Invoice = 'invoice';` and add it to the non-shareable arm of `isShareable()`; add lang label `enums.document_owner_type.invoice`.
- Resolver (`DocumentOwnerResolver.php` lines 83-96): copy the `Transaction` branch with `sharedLock()->firstOrFail()` and return `owner_type`, `owner_id`, `client_id`, `shareable`. Without a branch the resolver throws `InvalidArgumentException` (line 98).
- A migration widening any `documents` owner-type CHECK (`documents_visible_owner_check` allows only project/task/task_comment for visible; `owner_type` list CHECK must gain `invoice`). Keep invoice out of `Document::ARCHIVABLE_OWNER_TYPES` (Document.php:71).

### Health slot: unsent invoice e-mails

**Analog:** `app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php` (lines 29-66): `final readonly class ... implements HealthIndicator`, `slot()` returns `HealthSlot::UnsentInvoiceEmails`, `check()` reads as system (`app(PartnerContext::class)->runAsSystem(static fn () => ...)`), returns `new HealthResult(HealthStatus::Ok|Warning|Error, $value, __('kokpit.system....'))`; thresholds from `config('kokpit.health...')`; detail text is Czech copy, never exception text.

**Registration** (`app/Providers/OperationsServiceProvider.php` lines 45-50): append `new UnsentInvoiceEmailsIndicator,` to `$real`; `missingSlots($real)` then drops the placeholder. Update `tests/Feature/Operations/HealthRegistryTest.php` (line ~53 lists the slot as placeholder).

### `app/Filament/Resources/InvoiceResource.php` (+ Pages) (resource, CRUD)

**Analog:** `app/Filament/Resources/TransactionResource.php`.

**Declaration and conventions** (lines 59-124):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class TransactionResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $model = Transaction::class;
    protected static ?string $slug = 'transactions';
    protected static ?int $navigationSort = 60;       // invoices: 50
    protected static bool $isGloballySearchable = false;
    public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->with([...]); }
    public static function getRelations(): array { return [TransactionDocumentsRelationManager::class, TransactionHistoryRelationManager::class]; }
    public static function getPages(): array { return ['index' => ListTransactions::route('/'), 'create' => ..., 'edit' => ...]; }
}
```
Docblock rule (lines 60-66): "Nothing here writes a row"; pages hand form state to Actions that re-validate. Use `Repeater::table()` over plain array state, never `Repeater::relationship()`. Icon via `Heroicon::OutlinedDocumentCurrencyDollar`. Money columns/CZK previews use the same rule objects the Action uses. Tabs with counts in `ListInvoices::getTabs()` from one aggregate query; omit the "Zálohy k vyfakturování" tab at zero. Copy: `lang/cs/finance.php` style for `lang/cs/invoices.php`.

### Relation managers (Payments, LinkedTime, Emails, DraftEntries, Documents)

**Analog:** `app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php` (lines 33-70) and `app/Filament/RelationManagers/TransactionDocumentsRelationManager.php` (whole file).

```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ContactsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    protected static string $relationship = 'contacts';
    public function isReadOnly(): bool { return false; }     // needed on view pages with actions
    public static function getTitle(Model $ownerRecord, string $pageClass): string { return __('kokpit.contacts.relation_title'); }
}
```
Documents tab: `use ManagesDocuments;` with `showsOwnerColumn(): bool { return false; }`. Payments tab: no edit row action; the one row action is "Smazat platbu" through `DeletePayment` (D-19 as amended 2026-10-17, plan 10-41). Writes only through domain Actions; ids from the browser re-read through scoped queries.

### `app/Filament/Partner/Resources/PartnerInvoiceResource.php` (resource, read-only)

**Analog:** `app/Filament/Partner/Resources/PartnerProjectResource.php`.

```php
#[AccessRule(Audience::PartnerAllowed, reason: '...')]
final class PartnerProjectResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $slug = 'my-projects';
    protected static bool $isGloballySearchable = false;
    protected static ?int $navigationSort = 10;            // invoices: 30

    public static function canAccess(): bool
    {
        return AccessRules::allows(self::class)
            && parent::canAccess()
            && app(PartnerContext::class)->partnerClientId() !== null;
    }
    public static function canCreate(): bool { return false; }
    public static function table(Table $table): Table { return $table->columns(ProjectColumns::partnerColumns())->defaultSort('name')->emptyStateHeading(...); }
    public static function infolist(Schema $schema): Schema { return $schema->components(ProjectColumns::partnerEntries()); }
    public static function getPages(): array { return ['index' => ..., 'view' => ...]; }
}
```
Use a different slug (`my-invoices`), only `partnerColumns()`/`partnerEntries()` builders, no actions/bulk actions/export/search, no relation managers (invoice files are not Partner-visible). A foreign or draft document is 404.

### `app/Filament/Support/InvoiceColumns.php`

**Analog:** `app/Filament/Support/ProjectColumns.php` (and `TaskColumns.php`): static `partnerColumns()` / `partnerEntries()` pinned builders that select only Partner-safe columns.

### `app/Http/Controllers/InvoicePdfController.php` (controller, streaming)

**Analogs:** `app/Http/Controllers/DocumentFileController.php` (find document, authorize, stream with `nosniff`, `private, no-store`) and `app/Domain/Documents/Support/DocumentUrl.php` lines 56-65:
```php
URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($ttl), [
    'document' => $document->getKey(), 'mode' => $mode, 'u' => $user->getKey(),
]);
```
Same TTL config key (`kokpit.documents.signed_url_ttl_seconds`), user-bound `u`. Authorise through the Partner-scoped `Invoice` query (single definition of "issued, own client"), then read the document and media as system. Do not widen `Document::constrainForPartner` (Document.php lines 80-96 pins project/task/comment only). Route walk test `tests/Isolation/RouteWalkTest.php` must include it.

### Dashboard widget `UnpaidInvoices` and `Dashboard` edit

**Analogs:** `app/Filament/Widgets/UnbilledPerClient.php` (lines 49-60, 182-273: `#[AccessRule(Audience::AdminOnly...)]`, `use EnforcesWidgetAccessRule;`, `protected static ?int $sort`, reads via reader, CZK sums per document, `Money` only) and `app/Filament/Pages/Dashboard.php` lines 42-48:
```php
if ($context->isAdmin()) {
    return [HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class];
}
```
Add the new widget with `$sort = 20`. A non-collapsible `StatsOverviewWidget` needs no `DashboardWidget` case; a collapsible one needs the enum case plus a migration widening `dashboard_widget_preferences_widget_key_check`. Update the Dashboard docblock that says the Phase 10/11 band is reserved.

### Client detail panels (CL-03)

**Analogs:** `app/Filament/Resources/ClientResource/Pages/ViewClient.php` (view page with header actions; the "Nová faktura" modal action mirrors `invitePartnerAction()` lines 61-114 including `abort_unless(ClientResource::canAccess(), 403)` inside the action closure and `underModal($e)` for `ValidationException`) and `ContactsRelationManager` for the new relation managers. Register in `ClientResource::getRelations()`. Existing Soubory/Účty tabs stay.

### Settings additions (accent, logo, signature, footer)

**Analog:** `app/Domain/Settings/Settings/NumberingSettings.php` / `BankAccountSettings.php` (spatie typed settings with `fieldRules`). Page: existing `SettingsPage`. Validate hex `^#[0-9a-f]{6}$` at the data layer; re-encode images before storage.

### Time-entry guards in Actions

**Analog:** the existing billed refusal in `UpdateTimeEntry` (line ~77) and `DeleteTimeEntry` (line ~40) under their row lock; add the reserved/invoice-linked refusal there with the same `entry_locked` 409 code (API docs text test must change). `CancelEntriesBilling` must refuse rows whose `invoice_id` points at an issued document.

## Test patterns

| New test | Copy from |
|----------|-----------|
| Guard triggers (invoices, items, payments, time_entries link) | `tests/Feature/Database/ImmutabilityPilotTest.php` lines 62-90: `RawSql::expectSqlState('KP001', fn () => DB::update(...))`, `RawSql::expectAllowed(...)`, plus a mutation self-check (drop trigger, assertion fails) |
| Schema/CHECK tests | `tests/Feature/Schema/TransactionTablesTest.php`, `TimeEntriesTableTest.php`, `SchemaConventionsTest.php`, `KeysAndTimestampsTest.php` |
| Gap-free issue under concurrency | `tests/Concurrency/SequenceAllocatorConcurrencyTest.php` + `worker.php`; `tests/Support/UnlockedSequenceAllocator.php` for the no-lock mutation |
| Draft claim race | `tests/Concurrency/TaskBoardConcurrencyTest.php`, `timer-worker.php` style |
| Partner column pin | `tests/Isolation/PartnerSafeColumnsTest.php` lines 13-41: pinned `Schema::getColumnListing` list plus the regex test `/rate|price|estimate|billing|note|cost|budget/i` (use `note|rate|cnb|czk|internal|payment`) |
| Partner leakage canaries | `tests/Isolation/CanaryRegistryTest.php`, `DocumentFinanceLeakTest.php`, `NotificationLeakTest.php`, `RouteWalkTest.php`, `tests/Support/Canary.php` |
| PDF | `tests/Feature/Reports/WorkReportPdfTest.php` (HTML equivalence, canary as text); QR decode via `chillerlan` reader; Ghostscript txtwrite for text |
| Money additions | `tests/Feature/Money/*`, `tests/Arch/MoneyBoundaryTest.php` |
| Job contract | `tests/Arch/JobContractTest.php`; `tests/Support/JobDeclaration.php` |
| Registry | `tests/Arch/PanelRegistryTest.php`, `LivewireComponentContractTest.php` (scalar-only public properties) |
| Finance action tests | `tests/Feature/Finance/TransactionCzkTest.php` (data helper functions, `Canary::canary(...)`); fictional IBAN/company id from `tests/Support/FictionalCompanyId.php` runtime generators, never literals |
| Health | `tests/Feature/Operations/HealthIndicatorsTest.php`, `LastRateDateIndicatorTest.php` |
| S3 cleanup after rollback | `tests/Support/S3TestDisk.php` |

## Shared Patterns

### Access declaration (fail-closed)
**Source:** `TransactionResource.php:67-70`, `PartnerProjectResource.php:32-35`, `UnbilledPerClient.php:49-53`, `ContactsRelationManager.php:33-36`
**Apply to:** every new Resource, Page, Widget, RelationManager. `#[AccessRule(Audience::AdminOnly|PartnerAllowed, reason: '...')]` plus `EnforcesResourceAccessRule` / `EnforcesWidgetAccessRule` / `EnforcesRelationManagerAccessRule` / `EnforcesPageAccessRule`. Models: `PartnerIsolated + IsolatesPartners` (Invoice, InvoiceItem) or `DeniesPartners` (admin tables).

### Action shape
**Source:** `RecordTransaction.php:27-62`, `MarkEntriesBilled.php:52-87`
**Apply to:** all Invoicing Actions. `Gate::forUser($actor)->authorize(...)` first, ids sanitised, `DB::transaction`, row locks `orderBy('id')->lockForUpdate()`, re-validation, `ValidationException` with field keys or `DomainException(__('...'))`. The Filament layer never writes rows.

### Money
**Source:** `Money.php:103` (`fromExactMinor`), `:238` (`convert`). Single rounding point; no floats, no `round()`; amounts shown with `Money::format('cs')`; different currencies never summed; CZK equivalent computed per document once and summed in CZK.

### Immutability
**Source:** `Immutability.php:40-81`, time_entries migration lines 94-95. State-column guard plus truncate guard for header tables; new child guard for items; error state `KP001`.

### Time and dates
**Source:** `TimerClock::now()`, `PragueDay.php`. `issue_date`, `due_date`, `paid_on` are `date` columns written as Prague calendar days; overdue "today" bound from PHP.

### Localisation
**Source:** `lang/cs/finance.php`, `lang/cs/documents.php`, `lang/cs/enums.php`. Czech text in `lang/cs`, English companion where the document language needs it (`invoice_pdf`, `invoice_mail`); enum labels via `__('enums.<enum>.<value>')`.

### Notifications and PHI-style hygiene
**Source:** `DocumentArchiveFailedNotification.php`. Database channel only, fixed text, number only, no recipient/amount/exception text.

### Repository hygiene for tests and fixtures
Fictional data only (`example.com`, company ID `12345678`, `/Users/example/`), test IBANs assembled at runtime from fragments (`tests/Support/FictionalCompanyId.php`). Run `scripts/check-sensitive.sh` before commit; binary fonts added to `resources/fonts/` need no `.gitattributes` row (RESEARCH Pitfall 14).

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `resources/fonts/Roboto-Regular.ttf`, `Roboto-Bold.ttf`, `OFL.txt` | asset | file-I/O | No bundled fonts exist; see RESEARCH "Supporting assets" (SHA-256 values listed there) |
| `kokpit_guard_child_of_frozen_parent` SQL function and the `time_entries` transition trigger | migration | event-driven (trigger) | Only the generic state-column guard exists (`2026_10_07_000200_create_kokpit_guard_frozen_row_function.php`); use it as the structural model and RESEARCH Pattern 4 and 7 for the logic |
| `DocumentPalette` (HSL/contrast), `Spayd`, `QrPng` | utility | transform | No colour or QR code exists yet; use RESEARCH Pattern 9 (palette rules, verified `chillerlan/php-qrcode` snippet with `Version::AUTO`) |
| Cross-currency `Money` method (two CNB quotes) | utility | transform | `convert()` handles only "target per unit of source" with at most ten fraction digits; design per RESEARCH Pattern 5 |

## Metadata

**Analog search scope:** `app/Domain/*`, `app/Filament/*`, `app/Http/Controllers`, `app/Providers`, `database/migrations`, `tests/*`
**Files read:** ~25 (Immutability, transactions and time_entries migrations, RecordTransaction, MarkEntriesBilled, DocumentNumbering, AttachDocuments, WorkReportPdf, KokpitJob, BuildDocumentArchive, DocumentArchiveFailedNotification, Project model, TransactionResource, PartnerProjectResource, ContactsRelationManager, TransactionDocumentsRelationManager, ViewClient, UnbilledPerClient, Dashboard, OperationsServiceProvider, LastRateDateIndicator, DocumentUrl, DocumentOwnerType, DocumentOwnerResolver, ExchangeRateConverter, Money, ImmutabilityPilotTest, PartnerSafeColumnsTest, RawSql)
**Pattern extraction date:** 2026-10-16
