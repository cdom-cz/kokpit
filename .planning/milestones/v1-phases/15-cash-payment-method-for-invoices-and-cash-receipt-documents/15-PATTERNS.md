# Phase 15: Cash payment method and cash receipt documents - Pattern Map

**Mapped:** 2026-10-27
**Files analyzed:** 27 (new and modified)
**Analogs found:** 26 / 27 (all analogs are git-tracked sources under `app/`, `database/`, `tests/`)

Line numbers refer to the working tree on 2026-10-27. Re-grep before editing, the repo moves (Phase 16 in parallel).

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Domain/Invoicing/Enums/DocumentPaymentMethod.php` (mod) | enum | transform | itself (+ predicates from RESEARCH Pattern 1) | exact |
| `database/migrations/..._add_cash_to_document_payment_method.php` (new) | migration | CRUD (CHECK rewrite) | `database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php` | exact |
| `database/migrations/..._create_cash_receipts_table.php` (new) | migration | CRUD + guard trigger | `2026_10_25_000100_create_payment_confirmations_table.php` (table shape) + `2026_10_22_000100_add_lock_state_to_invoices.php` (guard) | role-match |
| `database/settings/..._add_cash_receipt_pattern.php` (new) | settings migration | batch | `database/settings/2026_10_17_000200_distinct_proforma_number_pattern.php` | role-match |
| `app/Domain/Settings/Numbering/DocumentKind.php` (mod) | enum | transform | itself (add `CashReceipt`) | exact |
| `app/Domain/Settings/Settings/NumberingSettings.php` (mod) | config | transform | itself (add property, rule, match arm) | exact |
| `app/Domain/Invoicing/Models/CashReceipt.php` (new) | model | CRUD | `app/Domain/Invoicing/Models/InvoicePayment.php` | role-match |
| `app/Domain/Invoicing/Actions/IssueCashReceipt.php` (new) | action | CRUD, in-transaction | `Actions/RecordPayment.php` (`recordLocked`) + `Issuing/DocumentIssuer.php` | role-match |
| `app/Domain/Invoicing/Actions/UpdateCashReceipt.php` (new) | action | CRUD | `Actions/ReplaceIssuedPdf.php` + `Actions/UpdateIssuedDocument.php` | role-match |
| `app/Domain/Invoicing/Actions/UnlockCashReceipt.php` (new) | action | CRUD | `Actions/UnlockInvoice.php` | exact |
| `app/Domain/Invoicing/Actions/CancelCashReceipt.php` (new) | action | CRUD | `Actions/UnlockInvoice.php` (reason + audit) | role-match |
| `app/Domain/Invoicing/Actions/RecordPayment.php` (mod) | action | CRUD | itself, hook in `recordLocked` | exact |
| `app/Domain/Invoicing/Actions/DeletePayment.php` (mod) | action | CRUD | itself, guard after payment re-read | exact |
| `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php` (mod) | job | event-driven | `Jobs/SendInvoiceEmail.php` (lock on send) | exact |
| `app/Domain/Invoicing/Mail/PaymentConfirmationMail.php` (mod) | mail | request-response | itself, `attachments()` | exact |
| `app/Domain/Invoicing/Pdf/CashReceiptPdf.php`, `CashReceiptPdfData.php` (new) | service | transform | `Pdf/InvoicePdf.php`, `Pdf/InvoicePdfData.php` | exact |
| `resources/views/pdf/cash-receipt.blade.php` (new) | view | transform | `resources/views/pdf/invoice.blade.php` | role-match |
| `app/Http/Controllers/CashReceiptPdfController.php` (new) | controller | file-I/O streaming | `app/Http/Controllers/InvoicePdfController.php` | exact |
| `app/Domain/Invoicing/Policies/CashReceiptPolicy.php` (new) | policy | request-response | `Policies/InvoicePolicy.php` | exact |
| `app/Domain/Invoicing/Support/DocumentPayment.php` (mod) | utility | transform | itself (`forDraft`, `forIssued`, `issuedSwitch`) | exact |
| `app/Domain/Invoicing/Pdf/InvoicePdfData.php` (mod) | utility | transform | itself (`payment()` Online branch) | exact |
| `app/Domain/Invoicing/Actions/QueueInvoiceEmail.php` (mod) | action | transform | itself (`defaultBody` match) | exact |
| `app/Domain/Documents/Enums/DocumentOwnerType.php` + `DocumentOwnerResolver` + MorphMap (mod) | enum/config | transform | `Invoice` case in the same files | exact |
| `app/Domain/Invoicing/Support/InvoiceAudit.php` or new `CashReceiptAudit` (new/mod) | utility | event-driven | `Support/InvoiceAudit.php` | exact |
| Filament Admin invoice detail (payments tab) and Partner invoice view (mod) | component | request-response | `ViewInvoice.php` payment section (grep for the `Platby` tab and `InvoicePdfUrl`) | role-match |
| `lang/cs/*`, `lang/en/*` (invoice_pdf, invoice_mail, invoices, enums, kokpit) (mod) | config | n/a | existing `online_sentence`, `with_due_date_online` keys | exact |
| `tests/Arch/CashReceiptUnlockPathTest.php` (new) | test | n/a | `tests/Arch/InvoiceUnlockPathTest.php` | exact |
| Enumerating tests (`ModelDeclarationTest`, `ActivityAllowlistTest`, `MorphMapTest`, `PartnerSafeColumnsTest`, `RouteWalkTest`, `InvoiceEnumsTest`, `NumberingPageTest`) (mod) | test | n/a | the existing entries for `InvoicePayment` / `Invoice` | exact |
| Concurrency test `CashReceiptNumberingConcurrencyTest` (new) | test | n/a | `tests/Concurrency/InvoiceIssueConcurrencyTest.php` | exact |

## Pattern Assignments

### `DocumentPaymentMethod.php` (enum, modified)

**Current** (`app/Domain/Invoicing/Enums/DocumentPaymentMethod.php` 19-26):
```php
enum DocumentPaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Online = 'online';

    public function getLabel(): string
    {
        return __('enums.document_payment_method.'.$this->value);
    }
}
```
Add `case Cash = 'cash';`, two predicates (`printsBankDetails()`, `allowsCardChoice()` per RESEARCH Pattern 1), the label `enums.document_payment_method.cash` in `lang/cs/enums.php` and `lang/en/enums.php`, and update the docblock (it states "transfer or online").
Replace every `=== DocumentPaymentMethod::Online` with a predicate. Sites found: `IssueChecks.php:84,90`, `DocumentIssuer::bankSnapshot`/`writeAdminRow`, `ConvertProforma.php:82,131-152`, `InvoicePdfData.php:267,367`, `QueueInvoiceEmail.php:153`, `InvoiceColumns.php:159`, `ViewInvoice.php:968`, `EditInvoice.php` (`isOnline()`), `DocumentPayment.php:162,185,187,238,240,271,320,326`.

---

### `..._add_cash_to_document_payment_method.php` (migration)

**Analog:** `database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php`

**CHECK pair to drop and re-add** (lines 118, 122):
```php
DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('transfer', 'online'))");
DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_issued_fields_check CHECK (state = 'draft' OR (issue_date IS NOT NULL AND supplier_snapshot IS NOT NULL AND customer_snapshot IS NOT NULL AND appearance_snapshot IS NOT NULL AND (kind = 'credit_note' OR (due_date IS NOT NULL AND (payment_method = 'online') = (bank_snapshot IS NULL)))))");
```
New values: `IN ('transfer', 'online', 'cash')` and `(payment_method IN ('online', 'cash')) = (bank_snapshot IS NULL)`. Drop first with `ALTER TABLE invoices DROP CONSTRAINT <name>` (same style as lines 121 and 134).

**down() pattern** (lines 125-135): count rows of the new value, throw `RuntimeException` if any, then restore the previous checks verbatim. Copy that style (refuse while a `cash` document exists).

No guard function change: `payment_method` is already in `open_columns` of `kokpit_guard_invoice_row()` (same file, function body at line 53).

---

### `..._create_cash_receipts_table.php` (migration, new table plus guard trigger)

**Table shape analog:** `2026_10_25_000100_create_payment_confirmations_table.php` lines 33-60.
```php
Schema::create('payment_confirmations', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->uuid('invoice_payment_id');
    $table->foreignUuid('invoice_id')->constrained('invoices');
    ...
    $table->timestampsTz();
    $table->unique('invoice_payment_id', 'payment_confirmations_invoice_payment_unique');
});
DB::statement('ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_invoice_payment_fk FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments (id) ON DELETE CASCADE');
DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_state_check CHECK (state IN ('queued', 'sending', 'sent', 'failed', 'skipped'))");
```
Use the same named-constraint style. For the PPD the FK is NOT cascade: composite `(invoice_payment_id, invoice_id) -> invoice_payments (id, invoice_id) ON DELETE SET NULL (invoice_payment_id)` (target unique `invoice_payments_id_invoice_unique` exists) and `(invoice_id, client_id) -> invoices (id, client_id)` (target unique `invoices_id_client_unique` exists). See RESEARCH "Table sketch" and Pitfall 1.

**Guard trigger analog:** `kokpit_guard_invoice_row()` in `2026_10_24_000200_add_payment_method_to_invoices.php` (lines 53-110; verbatim older copy at 143-198). Skeleton to copy for `kokpit_guard_cash_receipt_row()`:
```sql
open_columns text[] := ARRAY[...];          -- received_on, purpose, 3 snapshots, pdf_document_id, lock_state, updated_at
unlock_columns text[] := ARRAY['lock_state', 'updated_at'];
IF TG_OP = 'DELETE' THEN RAISE EXCEPTION '...' USING ERRCODE = 'KP001'; END IF;
IF (to_jsonb(NEW) - open_columns) IS DISTINCT FROM (to_jsonb(OLD) - open_columns) THEN
  RAISE EXCEPTION 'the number, kind and identity of ... never change' USING ERRCODE = 'KP001';
END IF;
IF OLD.lock_state = 'locked' THEN
  IF NEW.lock_state = 'locked' THEN
    IF to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'a locked ... accepts no write' USING ERRCODE = 'KP001'; END IF;
  ELSE
    IF (to_jsonb(NEW) - unlock_columns) IS DISTINCT FROM (to_jsonb(OLD) - unlock_columns) THEN RAISE ...; END IF;
    IF current_setting('kokpit.cash_receipt_unlock', true) IS DISTINCT FROM OLD.id::text THEN RAISE ...; END IF;
  END IF;
END IF;
```
Deviations the PPD needs on top of the invoice guard (no analog, design from RESEARCH): `cancelled_at/by/reason` set once in either lock state; `invoice_payment_id -> NULL` allowed only on a cancelled row (FK-driven); TRUNCATE refused. Trigger creation line to mirror: `DB::unprepared('CREATE TRIGGER invoices_row_guard BEFORE INSERT OR UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION kokpit_guard_invoice_row()')` (`2026_10_22_000100_add_lock_state_to_invoices.php:145`).

---

### `database/settings/..._add_cash_receipt_pattern.php` (settings migration)

**Analog:** `database/settings/2026_10_17_000200_distinct_proforma_number_pattern.php`
```php
use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        if (! $this->migrator->exists('numbering.proforma_pattern')) { return; }
        ...
        $this->migrator->update('numbering.proforma_pattern', static fn (string $stored): string => ...);
    }
};
```
For an additive property use `$this->migrator->add('numbering.cash_receipt_pattern', 'PPD{YYYY}{NNNN}')` guarded by `! exists(...)`. Also see `2026_10_17_000100_add_document_appearance_settings.php` for the add form if needed.

---

### `DocumentKind.php` and `NumberingSettings.php` (modified)

`DocumentKind` (lines 17-60): add `case CashReceipt = 'cash_receipt';`. `defaultPattern()` has `default => '{YYYY}{NNNN}'` so add an explicit `self::CashReceipt => 'PPD{YYYY}{NNNN}'` arm. `carriesVariableSymbol()` stays untouched (Invoice or Proforma only), which is what allows letters. Label key `enums.document_kind.cash_receipt` (cs and en).

`NumberingSettings` (lines 21-62): add `public string $cash_receipt_pattern;`, a rules line, and a match arm:
```php
'credit_note_pattern' => ['bail', 'required', 'string', new NumberPatternRule(DocumentKind::CreditNote)],
...
DocumentKind::CreditNote => $this->credit_note_pattern,
```
`patternFor()` match is exhaustive (no default), so omitting the arm is a fatal error. Also touch `SettingsPage.php`, `NumberingPageTest`, `NumberingTest`.

Allocation call (analog `DocumentIssuer.php:121`): `$this->numbering->next(DocumentKind::CashReceipt, <today Prague noon>)`. `DocumentNumbering::next` requires a transaction and takes `FOR UPDATE` on the counter row. Use the issue day, not `paid_on` (RESEARCH Pitfall 7); reuse the `noonOf()` idea at `DocumentIssuer.php:318`.

---

### `CashReceipt.php` (model, CRUD)

**Analog:** `app/Domain/Invoicing/Models/InvoicePayment.php` (lines 1-80)
```php
#[Fillable(['note'])]
#[LoggedAttributes(['invoice_id', 'amount_minor', 'currency', 'paid_on', 'method', 'carried_from_payment_id'])]
final class InvoicePayment extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;
    protected $table = 'invoice_payments';
    public function amount(): Money { return Money::ofMinor($this->amount_minor, $this->currency); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
```
Differences for `CashReceipt`: the Partner may read it, so do NOT use `DeniesPartners`; take the scope/`PartnerIsolated` pattern from `Invoice.php` (own client, issued, client not archived; `client_id` column is on the row for that reason). Casts for the lock state (reuse `InvoiceLockState` or a twin enum) and the snapshots as in `Invoice`. `LoggedAttributes` allowlist: ids, number, dates, amount, currency, lock_state, cancelled_at only; never `purpose` or `cancel_reason` text (T-10-22 rule stated in the model docblock).

IMPORTANT constraint found: `tests/Arch/InvoiceUnlockPathTest.php` lines 56-62 pins the token `InvoiceLockState::Locked` to exactly three files (`DocumentIssuer`, `SendInvoiceEmail`, `Invoice`). A PPD that casts or writes `InvoiceLockState::Locked` will fail this test. Either use a separate `CashReceiptLockState` enum (RESEARCH lists it as an option, and it is the cleaner choice) or extend the allowlist with a reason.

---

### `IssueCashReceipt.php` (action, in-transaction create)

**Analogs:** `RecordPayment::recordLocked` (guard clauses and `forceFill` style) and `DocumentIssuer` (number + snapshots + PDF).

**Transaction guard to copy** (`RecordPayment.php:113-119`):
```php
if (DB::transactionLevel() === 0) {
    throw new LogicException('A payment is recorded inside the caller\'s transaction, with the document locked.');
}
```
**Row creation style** (lines 132-141): `new Model([...fillable]); $m->forceFill([...])->save();`.

**Hook point in `recordLocked`** (`RecordPayment.php:141-153`). Insert between the `reconcile` line and the confirmation, `carry()` untouched (D-07):
```php
$this->paymentLinks->reconcile($locked);
// NEW: if ($facts->method === PaymentMethod::Cash) { $this->cashReceipts->handle($locked, $payment, $actor); }
$this->confirmations->handle($locked, $payment);
```
Add `IssueCashReceipt` to the readonly constructor (lines 61-67). Order inside the new action: number, snapshots (supplier/customer/appearance from the locked invoice via `InvoiceSnapshot`), insert row, render PDF, store, link `pdf_document_id`.

**PDF store/link pattern** (`ReplaceIssuedPdf.php:61-100`): render first (a failing render changes nothing), `StoreGeneratedDocument::handle($actor, $owner, $bytes, $fileName)`, then `forceFill(['pdf_document_id' => $document->getKey()])->save()`. Wrap render failures like this:
```php
} catch (Throwable $e) {
    throw new RuntimeException(__('documents.actions.delete_issued_pdf.regenerate_failed'), previous: $e);
}
```
The PDF is owned by a document with `owner_type` of the new alias, so add `case CashReceipt = 'cash_receipt'` to `DocumentOwnerType` (and `isShareable()` false, in the match which has no default), `DocumentOwnerResolver`, `MorphMap::MAP`, label `enums.document_owner_type.cash_receipt`, and the `documents_visible_owner_check` stays unchanged only if the new type is non-shareable (verify in the documents migration).

---

### `UnlockCashReceipt.php` and `CancelCashReceipt.php` (actions)

**Analog:** `Actions/UnlockInvoice.php` (whole file, lines 40-98). Copy:
```php
public const int REASON_MIN = 10;
public const int REASON_MAX = 500;

Gate::forUser($actor)->authorize('update', $invoice);
$clean = trim($reason); $length = mb_strlen($clean);
if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
    throw ValidationException::withMessages(['reason' => __('invoices.errors.unlock_reason_length')]);
}
return DB::transaction(function () use (...) {
    $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    $this->refuse($locked);
    DB::select("select set_config('kokpit.invoice_unlock', ?, true)", [$locked->id]);
    $locked->forceFill(['lock_state' => InvoiceLockState::Open])->save();
    DB::select("select set_config('kokpit.invoice_unlock', '', true)");
    InvoiceAudit::unlocked($locked, $actor, $clean);
    return $locked->refresh();
});
```
Use a NEW literal `kokpit.cash_receipt_unlock` (only in `UnlockCashReceipt` and the migration that creates the guard). Cancel: same reason validation and transaction; lock the invoice row first (lock order invoice, payment, receipt), then the receipt row; set `cancelled_at/by/reason` through a model save (not bulk update, so the guard and audit see one row change); re-render the PDF with a STORNO band via `ReplaceIssuedPdf`-style replace but keep the stored object history rules (the cancelled PPD PDF is evidence, never deleted afterwards).

---

### `DeletePayment.php` (modified, guard)

**Insert after the payment is re-read under lock** (`DeletePayment.php:65-77`, next to the `payment_delete_carried` refusal):
```php
$copy = InvoicePayment::query()->where('carried_from_payment_id', $locked->id)->first();
if ($copy instanceof InvoicePayment) {
    throw ValidationException::withMessages([
        'payment' => __('invoices.errors.payment_delete_carried', ['number' => ...]),
    ]);
}
// NEW: active PPD (cancelled_at IS NULL) -> same ValidationException shape, key 'payment',
//      message 'invoices.errors.payment_delete_cash_receipt'
```
Do not delete the cancelled PPD or its PDF. Update the class docblock (steps 1-5, lock order "document, payment, income" gains "receipt"). `PaymentDeletionPathTest` pins `kokpit.payment_deletion`; the new code needs no such literal.

---

### `SendPaymentConfirmation.php` and `PaymentConfirmationMail.php` (modified)

**Send site** (`SendPaymentConfirmation.php:77-88`):
```php
$payment = InvoicePayment::query()->findOrFail($row->invoice_payment_id);
$invoice = Invoice::query()->findOrFail($row->invoice_id);
Mail::to($recipient)->send(PaymentConfirmationMail::forPayment(number: ..., amount: ..., paidOn: ..., remainingMinor: ..., language: ...));
$this->markSent();
```
Add: resolve the PPD of the payment at send time (not cancelled), pass its stored PDF into the mailable. **Attachment analog:** the document e-mail, `Mail/InvoiceMail` / `SendInvoiceEmail` (grep `attachments` there for how the stored PDF is attached from `Media`); `PaymentConfirmationMail::attachments()` currently returns `[]` (lines 70-78) with a docblock "No attachment (D-06)" that must be rewritten (also the class docblock line 17-18 "has no attachment").

**markSent** (lines 135-145) is a single bulk conditional update:
```php
PaymentConfirmation::query()->whereKey($this->confirmationId)
    ->where('state', PaymentConfirmationState::Sending->value)
    ->update(['state' => Sent, 'sent_at' => now(), 'updated_at' => now()]);
```
Change to a transaction that locks the PPD row, runs this conditional update, and only if it changed one row and the PPD is open locks the PPD through a model save plus an audit row, copying the lock-on-send implementation in `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` (its docblock lines 62-66 says a payment confirmation must never lock the invoice; update it to say the PPD is a different row). `InvoiceAudit::locked()` (`Support/InvoiceAudit.php` ~58-73) is the audit shape to twin. The `catch (Throwable $e)` requeue block (91-99) must stay untouched.

---

### `CashReceiptPdf.php`, `CashReceiptPdfData.php`, `cash-receipt.blade.php` (service/view)

**Analog:** `app/Domain/Invoicing/Pdf/InvoicePdf.php` (full file, 193 lines). Reuse, do not duplicate, the closed Dompdf options and font lock. Best approach: extract the `options()`, `fontsInstalled()`, `fontUrl()`, `raiseMemoryLimit()` and the lock wrapper into a small shared helper, or have `CashReceiptPdf` call a shared `DompdfRenderer`. Key excerpts to carry:
```php
return new Options([
    'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
    'defaultFont' => 'DejaVu Sans',
    'chroot' => [resource_path('views/pdf'), resource_path('fonts')],
    'fontDir' => $fontDir, 'fontCache' => $fontDir, 'tempDir' => $fontDir,
]);
...
return Cache::lock(self::FONT_LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn (): string => $this->pdf($html, $data));
```
`fileName()` pattern (lines 83-88): sanitise with `preg_replace('/[^A-Za-z0-9._-]+/', '-', ...)` then `trans('invoice_pdf.file_name.'.$kind, [...], $language)`; add a `cash_receipt` file-name key in cs and en. Put the view in `resources/views/pdf/` (the Dompdf chroot allows only that directory). Data object builds from snapshots only (appearance snapshot gives accent, footer, logo, signature); mirror the structure of `InvoicePdfData::fromInvoice`.

---

### `InvoicePdfData.php::payment()` (modified, cash branch)

**Existing Online branch** (lines 267-280), put the Cash branch directly after it and before `$bank = $issued->bank_snapshot; if ($bank === null) return null;`:
```php
if ($issued->payment_method === DocumentPaymentMethod::Online) {
    $amount = $issued->total()->format($issued->language->value);
    return [
        'qrDataUri' => null,
        'sentence' => self::sentence($t('online_sentence', ['amount' => "\u{1}".$amount."\u{1}", 'due' => "\u{1}".$date($issued->due_date)."\u{1}"])),
        'facts' => [
            ['key' => $t('payment_method'), 'value' => $t('online_payment')],
            ['key' => $t('amount_due'), 'value' => $amount],
        ],
    ];
}
```
Add `cash_sentence`, `cash_payment` keys to `lang/{cs,en}/invoice_pdf.php`. Also check the second reference at `InvoicePdfData.php:367` (grep `Online`) and the "pay by card" button logic.

**E-mail body** (`QueueInvoiceEmail.php:150-154`):
```php
$key = match (true) {
    $issued->kind === InvoiceKind::CreditNote || $issued->due_date === null => 'credit_note',
    $issued->payment_method === DocumentPaymentMethod::Online => 'with_due_date_online',
    default => 'with_due_date',
};
```
Add a `Cash` arm returning `with_due_date_cash`; add the key to `lang/{cs,en}/invoice_mail.php`.

---

### `DocumentPayment.php` (modified, card switch for Cash)

`issuedSwitch` (lines 306-345). Add before the `Online` branch (line 320):
```php
if ($method === DocumentPaymentMethod::Cash) {
    if ($explicit === true) { throw ValidationException::withMessages(['card_payment_enabled' => [__('invoices.errors.card_payment_unavailable')]]); }
    return false;
}
```
Mirror in `forDraft` (lines ~134-200: the `$method === Online => true` match at line 185) with `Cash => false`, and `forIssued` (line 238 `$online`, line 271 `$retake`): a Cash method sets `bank_snapshot` to null like Online (`issuedSnapshot()`), and `$retake` must also trigger when the stored method is Cash and the new one is Transfer. No bank account required (D-05). The link deactivation on `method_changed` already exists in `UpdateIssuedDocument.php:171,207-208`; no change expected (RESEARCH P15-04), only a test.

---

### `CashReceiptPdfController.php` (controller, streaming)

**Analog:** `app/Http/Controllers/InvoicePdfController.php` (lines 25-98). Keep the same 7-step check order and headers verbatim. Core:
```php
abort_unless($user !== null && $request->query('u') === $user->getKey(), 403);
abort_unless(Str::isUuid($invoice), 404);
$record = Invoice::query()->whereKey($invoice)->first();
abort_if($record === null || ! $record->isIssued(), 404);
Gate::authorize('view', $record);
$stored = $context->runAsSystem(static function () use ($record): ?array { ... Document::query()->whereKey($documentId)->where('owner_type', 'invoice')->where('owner_id', $record->getKey())->first(); ... });
...
return $disk->response($path, null, [
    'Content-Type' => ..., 'Content-Disposition' => DocumentDisposition::header('attachment', DocumentDisposition::downloadName($document)),
    'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
]);
```
Swap the subject to `CashReceipt` (scoped query, policy `view`) and `owner_type` to the new alias. Add a signed route named like `files.cash-receipt` and register it in `RouteWalkTest`/`CanaryRegistry`. URL helper analog: `Support/InvoicePdfUrl.php`.

### `CashReceiptPolicy.php`

**Analog:** `Policies/InvoicePolicy.php` (whole file, 16-34):
```php
final class CashReceiptPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Model $record): bool
    {
        return $record instanceof CashReceipt
            && $user->client_id !== null
            && $record->client_id === $user->client_id;   // plus: not a draft concept here, receipts exist only when issued
    }
}
```
Every write ability keeps the `KokpitPolicy` base denial, the Admin is admitted by `KokpitPolicy::before()`. Register the policy where `InvoicePolicy` is registered.

### Audit

**Analog:** `Support/InvoiceAudit.php`: static writers, each writes exactly one row with a fixed key allowlist:
```php
$logger = activity(self::LOG)->performedOn($invoice);
if ($causer instanceof User) { $logger->causedBy($causer); }
$logger->event('invoice_locked')->withChanges(['attributes' => ['lock_state' => 'locked'], 'old' => ['lock_state' => 'open']])->log('invoice_locked');
```
Create `CashReceiptAudit` with `issued`, `edited`, `locked`, `unlocked` (reason logged on purpose only if the owner accepts it, as D-13 did for invoices), `cancelled`. Keep the cancel reason text out of the log unless decided (RESEARCH security table).

## Shared Patterns

### Transaction and lock order
**Source:** `DeletePayment.php:62-79`, `UnlockInvoice.php:61-64`, `RecordPayment.php:81-82`
Always `DB::transaction`, lock the invoice row with `lockForUpdate()->firstOrFail()` first, then payment, then receipt, then income. Throw `LogicException` when a helper action is called with `DB::transactionLevel() === 0`.

### Authorisation
**Source:** `UnlockInvoice.php:52`, `DeletePayment.php:60`
`Gate::forUser($actor)->authorize('update', $invoice);` as the first line of every Admin action. Partner read-only goes through policy `view` plus the model global scope.

### Validation errors
**Source:** `RecordPayment.php:85`
`throw ValidationException::withMessages(['payment' => __('invoices.errors.<key>')]);` with the key in `lang/cs/invoices.php` and `lang/en/invoices.php`. New keys: `payment_delete_cash_receipt`, receipt cancel/edit refusals, reason length (reuse `unlock_reason_length`).

### Sanctioned-setting DB guard
**Source:** `UnlockInvoice.php:66-70` and the guard branch in migration `2026_10_24_000200...:100-106`. Set with `set_config(..., true)`, save through a model, clear before commit. Pin the new literal with an arch test copied from `tests/Arch/InvoiceUnlockPathTest.php` (helper `invoiceLockFilesNaming`, constants at lines 17-19, expectations at 51-62). Name the helper and constants differently (Pest loads all test files into one namespace, a duplicate function name is a fatal error).

### Money and snapshots
Money is `Money::ofMinor($minor, $currency)` and `->format($language)` (`InvoicePayment::amount()`, `InvoicePdfData::payment()`). The PPD copies amount, currency, dates and parties into its own row (D-11, no re-read of the payment for the PDF).

### Fictional data in tests
Per CLAUDE.md: `example.com` addresses, company ID `12345678`, fakes assembled at runtime. Tests run as `ddev exec vendor/bin/pest ...`. Fixture helper to extend: `tests/Support/InvoiceFixtures.php`.

## No Analog Found

| File / part | Role | Data Flow | Reason |
|-------------|------|-----------|--------|
| Guard trigger part for cancel-once columns and FK-driven `invoice_payment_id -> NULL` | migration/trigger | CRUD | The invoice guard has no cancel-once or nullable-link column; design from RESEARCH Pitfall 1 and the table sketch, use the invoice guard only as a skeleton |
| `ON DELETE SET NULL (col)` composite FK with a CHECK tied to `cancelled_at` | migration | CRUD | No existing FK uses the column-list form; verify PostgreSQL version in DDEV before relying on it (RESEARCH fallback: delete trigger on `invoice_payments`) |
| Filament Admin payment-row actions (cancel receipt with reason form, unlock, edit modal) | component | request-response | Reason forms exist for unlock; read the invoice-detail Livewire/Filament page (`ViewInvoice.php`, search for the unlock action) before planning; no receipt-specific analog |

## Metadata

**Analog search scope:** `app/Domain/Invoicing`, `app/Domain/Settings`, `app/Domain/Documents`, `app/Http/Controllers`, `database/migrations`, `database/settings`, `tests/Arch`
**Files read:** about 20 (RecordPayment, DeletePayment, UnlockInvoice, SendPaymentConfirmation, PaymentConfirmationMail, InvoicePdf, InvoicePdfController, InvoicePolicy, InvoicePayment, DocumentKind, NumberingSettings, DocumentNumbering, DocumentPaymentMethod, DocumentPayment (excerpts), InvoicePdfData (excerpt), QueueInvoiceEmail (excerpt), ReplaceIssuedPdf, QueuePaymentConfirmation, two migrations, settings migration, InvoiceUnlockPathTest, DocumentOwnerType)
**Not opened (planner to read before writing the plan):** `SendInvoiceEmail.php` (lock-on-send implementation), `ViewInvoice.php` / `EditInvoice.php` / `InvoiceColumns.php` (UI branches), `ConvertProforma.php`, `IssueChecks.php`, the Isolation and Schema enumerating tests, `StoreGeneratedDocument`, `resources/views/pdf/invoice.blade.php`
**Pattern extraction date:** 2026-10-27
