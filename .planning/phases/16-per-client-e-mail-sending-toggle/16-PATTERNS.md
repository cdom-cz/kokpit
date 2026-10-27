# Phase 16: Per-client e-mail sending toggle - Pattern Map

**Mapped:** 2026-10-27
**Files analyzed:** 27 (6 new, 21 modified)
**Analogs found:** 25 / 27 (all analog paths verified git-tracked under `app/`, `database/`, `tests/`, `lang/`)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_10_28_000100_add_document_emails_enabled_to_clients_table.php` (new) | migration | CRUD | `2026_10_09_000100_create_clients_table.php` (line 47) | role-match |
| `database/migrations/2026_10_28_000200_add_skipped_state_to_invoice_emails.php` (new) | migration | CRUD | `2026_10_17_000300_create_invoice_admin_tables.php` (line 236 CHECK) | role-match |
| `database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php` (new) | migration | CRUD | `2026_10_25_000100_create_payment_confirmations_table.php` (lines 56, 62) | role-match |
| `app/Domain/Clients/Models/Client.php` | model | CRUD | itself, entry `online_payment_enabled` (lines 63, 82, 99, 119) | exact |
| `app/Domain/Clients/Actions/ClientInput.php`, `CreateClient.php` | service/action | CRUD | itself, `online_payment_enabled` (ClientInput:151, CreateClient:58,115) | exact (but do NOT copy `?? false`) |
| `database/factories/ClientFactory.php` | factory | CRUD | itself (`'online_payment_enabled' => false`) | exact |
| `app/Domain/Invoicing/Support/ClientDocumentEmails.php` (new) | utility | request-response | `QueuePaymentConfirmation::clientOf()` | partial |
| `app/Domain/Invoicing/Actions/LockInvoice.php` (new) | action | CRUD | `Actions/UnlockInvoice.php` | exact (twin) |
| `app/Domain/Invoicing/Actions/LockCashReceipt.php` (new) | action | CRUD | `Actions/UnlockCashReceipt.php` | exact (twin) |
| `app/Domain/Invoicing/Support/InvoiceAudit.php`, `CashReceiptAudit.php` | utility (audit) | event-driven | `locked()` / `unlocked()` in the same files | exact |
| `app/Domain/Invoicing/Actions/QueueInvoiceEmail.php` | action | request-response | itself (ValidationException at `isIssued()` check) | exact |
| `app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php` | action | event-driven | itself (`state` ternary, dispatch guard) | exact |
| `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php`, `SendPaymentConfirmation.php` | job | event-driven | themselves (claim, then `try`, conditional updates) | exact |
| `app/Domain/Invoicing/Enums/InvoiceEmailState.php` | enum | - | `PaymentConfirmationState` (has `Skipped`) | exact |
| `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php` | service | batch | itself | exact (optional client-off join, A3) |
| `app/Filament/Resources/ClientResource.php` | component | CRUD | itself, `online_payment_enabled` Toggle/IconEntry (lines 312-316, 361-363, 560) | exact |
| `app/Filament/Support/SendInvoiceEmailAction.php` | component | request-response | itself (`make()->visible(...)`) | exact |
| `app/Filament/Support/InvoiceTable.php` | component | request-response | itself (`emailKey()` line 423, resend action, `email` filter) | exact |
| `app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php` | component | request-response | itself (`banners()` line 731, `unlockAction()` line 812, `openSendDialog()`) | exact |
| `.../RelationManagers/InvoiceEmailsRelationManager.php` | component | request-response | itself (state color `match`) | exact |
| `.../RelationManagers/InvoicePaymentsRelationManager.php` | component | request-response | itself (`unlockReceiptAction()` line 467, `runReceiptAction()` line 544) | exact |
| `RecordPaymentAction` (`confirmationHint()`) | component | request-response | itself | exact |
| `lang/cs/{kokpit,invoices,cash_receipts,enums}.php` | config | - | existing keys next to `online_payment_enabled`, `invoice_locked` | exact |
| `tests/Arch/InvoiceUnlockPathTest.php`, `CashReceiptUnlockPathTest.php` | test | - | themselves (allowlist arrays) | exact |
| `tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php` (189-197), `InvoiceEditAuditTest.php` (303, 522), `tests/Unit/Invoicing/InvoiceEnumsTest.php` (124-128) | test | - | themselves | exact |
| New feature tests (toggle, enforcement, lock, journey) | test | - | sibling tests in `tests/Feature/Invoicing/` | role-match |

## Pattern Assignments

### `LockInvoice.php` (action, CRUD) - analog `app/Domain/Invoicing/Actions/UnlockInvoice.php`

**Imports** (lines 5-15): `User`, `InvoiceKind`, `InvoiceLockState`, `Invoice`, `InvoiceAudit`, `DB`, `Gate`, `ValidationException`. Class is `final readonly`, `declare(strict_types=1)`.

**Core pattern** (lines 49-73), minus the reason and the `set_config` calls (the DB guard allows open to locked, so no setting is needed or allowed):
```php
Gate::forUser($actor)->authorize('update', $invoice);

return DB::transaction(function () use ($actor, $invoice): Invoice {
    $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

    $this->refuse($locked);

    $locked->forceFill(['lock_state' => InvoiceLockState::Locked])->save();

    InvoiceAudit::lockedManually($locked, $actor);

    return $locked->refresh();
});
```

**Refusal pattern** (lines 80-97): `match (true)` returning an `invoices.errors.*` key, thrown as `ValidationException::withMessages(['lock' => __('invoices.errors.'.$message)])`. Arms: `isDraft()` => `not_issued`; `kind === CreditNote` => `credit_note_locked`; `isLocked()` => `document_locked`; `isCancelled()` => `cancelled_not_editable`; proforma with `invoiceFromProforma()->exists()` => `converted_not_editable`. Verify the keys exist in `lang/cs/invoices.php` (`IssuedDocumentRules::editBlocker()` uses them).

**Constraint:** this file is the only new place that names `InvoiceLockState::Locked`. Add it to the arch allowlist. The Filament page must call the Action, never name the case.

---

### `LockCashReceipt.php` (action, CRUD) - analog `Actions/UnlockCashReceipt.php`

Same shape (lines 51-75 and 82-93). Differences:
- Lock order: Invoice row first, then the receipt row (per `UpdateCashReceipt`). `UnlockCashReceipt` locks only the receipt row, so look at `UpdateCashReceipt.php` for the invoice-first sequence.
- `CashReceipt::query()->whereKey(...)->lockForUpdate()->firstOrFail()`, `forceFill(['lock_state' => CashReceiptLockState::Locked])->save()`, `CashReceiptAudit::lockedManually($locked, $actor)`.
- Refusals under key `receipt`: `isCancelled()` => `cash_receipts.errors.cancelled`; `isLocked()` => a new `cash_receipts.errors.already_locked` key.

---

### `InvoiceAudit::lockedManually()` / `CashReceiptAudit::lockedManually()` (audit, event-driven)

**Analog:** `InvoiceAudit::unlocked()` lines 74-85 and `CashReceiptAudit::locked()` lines 124-139. Copy the `unlocked()` shape (actor is required, always a `User`), with the `locked()` payload:
```php
activity(self::LOG)
    ->performedOn($invoice)
    ->causedBy($actor)
    ->event('invoice_locked_manually')
    ->withChanges(['attributes' => ['lock_state' => 'locked'], 'old' => ['lock_state' => 'open']])
    ->log('invoice_locked_manually');
```
Twin event `cash_receipt_locked_manually` in `CashReceiptAudit`. Extend `InvoiceAudit::everSent()` (Pitfall 4, assumption A1) with an `invoice_locked_manually` EXISTS check. Add labels to `lang/cs/kokpit.php` `activity.events` beside `invoice_locked` / `cash_receipt_locked` (around lines 419-425).

---

### `QueueInvoiceEmail.php` (action, request-response) - self

**Enforcement point** (after `isIssued()` check, lines 89-91; before the row build at line 97). Reuse the same throw style:
```php
if (! $document->isIssued()) {
    throw ValidationException::withMessages(['invoice' => __('invoices.errors.email_not_issued')]);
}
if (! ClientDocumentEmails::enabledFor($document->client_id)) {
    throw ValidationException::withMessages(['invoice' => __('invoices.errors.email_client_disabled')]);
}
```
No `invoice_emails` row is written, because the throw precedes `forceFill()->save()`. `defaults()` (lines 60-77) needs no change.

---

### `QueuePaymentConfirmation.php` (action, event-driven) - self

**Row creation and dispatch** (lines 67-85). Change only the state and the dispatch guard:
```php
$enabled = ClientDocumentEmails::enabledFor((string) $locked->client_id);
'state' => ($recipient === null || ! $enabled) ? PaymentConfirmationState::Skipped : PaymentConfirmationState::Queued,
...
if ($recipient !== null && $enabled) {
    SendPaymentConfirmation::dispatch($row->id)->afterCommit()->delay(now()->addSeconds(self::GRACE_SECONDS));
}
```
Keep `recipient` stored on a switch-skipped row (needs migration 3). `clientOf()` (lines 110-113, `Client::query()->withTrashed()->whereKey(...)->first()`) is the model for `ClientDocumentEmails`. Update the class docblock (lines 14-36) to mention the switch.

---

### `ClientDocumentEmails.php` (utility) - analog `QueuePaymentConfirmation::clientOf()`

```php
public static function enabledFor(string $clientId): bool
{
    $value = Client::query()->withTrashed()->whereKey($clientId)->value('document_emails_enabled');

    return $value === null ? true : (bool) $value; // fresh read, never a cached relation (grace window)
}
```
It lives in `Domain/Invoicing/Support/`. Job context is safe because `KokpitJob` enters the system context.

---

### `SendInvoiceEmail.php` / `SendPaymentConfirmation.php` (job, event-driven) - self

**Insertion point:** immediately after `claim()` and the row load, as the first statement inside `try` (SendInvoiceEmail lines 98-103; SendPaymentConfirmation lines 78-85, before `cleanAddress`). Use the conditional-update idiom already used in the catch blocks (SendInvoiceEmail lines 121-125):
```php
$clientId = Invoice::query()->whereKey($row->invoice_id)->value('client_id');

if (! is_string($clientId) || ! ClientDocumentEmails::enabledFor($clientId)) {
    InvoiceEmail::query()->whereKey($row->id)
        ->where('state', InvoiceEmailState::Sending->value)
        ->update(['state' => InvoiceEmailState::Skipped->value, 'updated_at' => now()]);

    return; // no throw (a throw re-queues), no tellAdmins, no lock (IN-11)
}
```
SendPaymentConfirmation uses `PaymentConfirmation` / `PaymentConfirmationState::Skipped` and `invoice_id` directly from `$row`. Update both `#[Idempotent(how: ...)]` strings and docblocks.

---

### Enum and table seams - `InvoiceEmailState` (analog `PaymentConfirmationState`)

Add `case Skipped = 'skipped'` and its label. Fix the exhaustive matches: `InvoiceTable::emailKey()` (line 423) maps `Skipped` to `'none'`; the `email` filter `'none'` branch becomes `IS NULL OR state = 'skipped'`; the color `match` in `InvoiceEmailsRelationManager::table()` gets `Skipped => 'gray'`. The Platby tab already uses `default => 'gray'`. Add `'skipped'` to `lang/cs/enums.php` `invoice_email_state` and update `InvoiceEnumsTest.php:124-128`.

---

### `ClientResource.php` / `Client` / `ClientInput` / `CreateClient` (toggle)

**Model** (Client.php): add `@property bool $document_emails_enabled`, an entry in `#[Fillable]` (after line 82), in `#[LoggedAttributes]` (after line 99) and cast `'document_emails_enabled' => 'boolean'` (after line 119). That one allowlist entry is the whole audit (P16-02). Add the label under `kokpit.activity.attributes.client` next to `'online_payment_enabled'`.

**Form** (ClientResource.php 312-316): copy the Toggle after `invoice_email`:
```php
Toggle::make('document_emails_enabled')
    ->label(__('kokpit.clients.fields.document_emails_enabled'))
    ->helperText(__('kokpit.clients.hints.document_emails_enabled'))
    ->default(true)
    ->columnSpanFull(),
```

**Detail** (361-363): `IconEntry::make('document_emails_enabled')->label(...)->boolean()` plus a `TextEntry` `->badge()->color('warning')->visible(fn (Client $record) => ! $record->document_emails_enabled)`.

**Mapping pitfall:** `actionData()` (line 560) and `ClientInput::attributes()` (line 151) use `(bool) (... ?? false)`. For the new key use `?? true` in `actionData()`. In `ClientInput` add the attribute only when `array_key_exists('document_emails_enabled', $data)`. In `CreateClient::withDefaults()` (lines 115-117 idiom) set it to `true` when absent. Add `document_emails_enabled?: bool|null` to the phpstan type (line 58).

---

### `SendInvoiceEmailAction.php` / `InvoiceTable` / `InvoiceEmailsRelationManager` (visibility)

**Detail header** (SendInvoiceEmailAction.php line 59):
`->visible(static fn (): bool => self::fresh($invoice())->isIssued())` becomes `... && ClientDocumentEmails::enabledFor(self::fresh($invoice())->client_id)`. `forListRow()` and `forLogRow()` have no `visible`. Their call sites (`InvoiceTable::resendEmailAction()` and the `InvoiceEmailsRelationManager` row action, `->visible(... Failed)`) get the extra clause. In the list, use the eager-loaded `$record->client?->document_emails_enabled` to avoid N+1.

---

### `ViewInvoice.php` (component) - self

**Gray note** (`banners()` line 731; copy shape of lines 740-748):
```php
Callout::make(__('invoices.detail.banners.email_disabled'))
    ->key('email_disabled')
    ->color('gray')
    ->visible(fn (): bool => $this->invoice()->isIssued() && ! ClientDocumentEmails::enabledFor($this->invoice()->client_id)),
```

**Lock button:** add `->actions([$this->lockAction()])` to the `open_for_edits` callout (lines 740-743), mirroring `->actions([$this->unlockAction()])` at line 748. `lockAction()` copies `unlockAction()` (lines 812-870) with these changes:
- `->requiresConfirmation()` instead of the `schema([Textarea reason])` and rules.
- `->visible(fn () => $this->isEditableDocument() && ! $this->invoice()->isLocked())`.
- The action body calls `app(LockInvoice::class)->handle($actor, $this->invoice())`.
- Keep the same `abort_unless(InvoiceResource::canAccess(), 403)`, rethrow `AuthorizationException`, the `ValidationException` catch that refreshes and shows a danger Notification, and a success Notification.

Guard `openSendDialog()` and the `sendDialogPending` assignment (about line 153) for a disabled client (P16-07).

---

### `InvoicePaymentsRelationManager.php` (PPD lock) - self

Add `lockReceiptAction()` into the `ActionGroup` (lines 198-200) next to `unlockReceiptAction()`. Copy that method (lines 467-487), with `Heroicon::OutlinedLockClosed`, `requiresConfirmation()`, no schema, and visible when `$record->cashReceipt !== null && ! isCancelled() && ! isLocked()`. Run it through the existing frame:
```php
->action(function (InvoicePayment $record): void {
    $this->runReceiptAction($record, static fn (User $actor, CashReceipt $receipt): mixed => app(LockCashReceipt::class)->handle($actor, $receipt), 'cash_receipts.admin.lock.done');
})
```

---

### `UnsentInvoiceEmailsIndicator.php` - self

No change needed for D-08: `skipped` matches neither the `failed` nor the `waiting` FILTER. Add a pinning test. Optional (A3): join `invoices`/`clients` and ignore documents of clients with the switch off.

---

### Migrations (3)

- **1 (clients):** copy the Blueprint idiom from create_clients line 47, `$table->boolean('document_emails_enabled')->default(true)` in `Schema::table`. The default covers existing rows.
- **2 (invoice_emails):** `DB::statement` drop and re-add `invoice_emails_state_check` with `'skipped'` added (the original is at `2026_10_17_000300...:236`). `down()` must delete or convert skipped rows first.
- **3 (payment_confirmations):** replace `payment_confirmations_recipient_check` (original at `2026_10_25_000100...:62`) with `CHECK ((recipient IS NULL AND state = 'skipped') OR (recipient IS NOT NULL AND btrim(recipient) <> ''))`.

Before writing migrations 2 and 3, read the original drop/add CHECK style in the named files. Migrations are written in the worktree but run only after the merge into the HEAD branch.

---

### Arch tests - `tests/Arch/InvoiceUnlockPathTest.php`

Lines 5-15 hold the header comment ("There is no manual 'mark as sent' anywhere (D-02)"). Rewrite it, since a manual lock is now the owner-approved second writer (Phase 16 D-02). Add `app/Domain/Invoicing/Actions/LockInvoice.php` to the expected list of the `InvoiceLockState::Locked` test. Do the same in `CashReceiptUnlockPathTest.php` with `LockCashReceipt.php`. The unlock-flag test (`kokpit.invoice_unlock` only in `UnlockInvoice`) is unchanged: the lock Actions must not name it.

## Shared Patterns

### Domain gate before UI
**Source:** the new `ClientDocumentEmails::enabledFor()` (fresh DB read, `withTrashed`).
**Apply to:** `QueueInvoiceEmail`, `QueuePaymentConfirmation`, both jobs, the three send actions, the post-issue dialog, the gray note and `RecordPaymentAction::confirmationHint()`. One implementation, no cached relations in jobs.

### Action frame (authorize, transaction, row lock first, model save, explicit audit)
**Source:** `UnlockInvoice.php` lines 49-73 and `UnlockCashReceipt.php` lines 51-75.
**Apply to:** `LockInvoice`, `LockCashReceipt`. Filament only maps errors (the pattern of `ViewInvoice::unlockAction()` lines 835-868, `runReceiptAction()` lines 544-574).

### Conditional update to a terminal state, never throw
**Source:** catch blocks in `SendInvoiceEmail` (121-125) and `SendPaymentConfirmation` (`->whereKey()->where('state', Sending)->update([...])`).
**Apply to:** both skip paths.

### Audit
**Source:** allowlisted model log (`#[LoggedAttributes]` on `Client`) for the toggle; explicit `activity(self::LOG)->performedOn()->causedBy()->event()->withChanges()->log()` rows for document locks.

### Fictional data only
Test fixtures and lang strings use `example.com` addresses and company ID `12345678`. Run `scripts/check-sensitive.sh` before committing.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| Lock (non-send) of a document without a reason | action | CRUD | Only the unlock exists; `LockInvoice` / `LockCashReceipt` are adapted twins (no reason, no `set_config`) |
| Gray `Callout` with `->color('gray')` | component | - | No existing gray callout; `Callout::color()` exists in vendor (`vendor/filament/schemas/src/Components/Callout.php:118`), existing callouts use `->info()` / `->warning()` / `->danger()` |

## Metadata

**Analog search scope:** `app/Domain/Invoicing`, `app/Domain/Clients`, `app/Filament`, `tests/Arch`, `database/migrations`
**Files read this session:** CONTEXT.md, RESEARCH.md (lines 1-402; the rest is validation architecture), UnlockInvoice, UnlockCashReceipt, QueueInvoiceEmail (60-140), QueuePaymentConfirmation, SendPaymentConfirmation (1-200), SendInvoiceEmail (75-150), SendInvoiceEmailAction (1-120), Client / ClientInput / CreateClient / ClientResource excerpts, ViewInvoice and InvoicePaymentsRelationManager excerpts, InvoiceUnlockPathTest header. The line numbers in the migration and test-file entries come from RESEARCH.md and were not re-read.
**Pattern extraction date:** 2026-10-27
