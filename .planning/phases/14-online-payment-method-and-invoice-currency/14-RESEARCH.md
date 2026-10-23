# Phase 14: Switchable payment method and independent invoice currency - Research

**Researched:** 2026-10-23
**Domain:** Laravel 13 / Filament 5 / PostgreSQL invoicing: a per-document payment method, a bank account chosen independently of the currency, a wider currency list, Stripe link lifecycle on change
**Confidence:** HIGH for what exists in the code and what must change; MEDIUM for the three open design questions (flagged below)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217 (Phase 2 D-09); the bank account is snapshotted into `invoices.bank_snapshot` at issue and a document that carries a payment must have it (Phase 10 D-12, `invoices_issued_fields_check`); a document stays editable until a successful e-mail send locks it, the Admin may unlock with a recorded reason, and currency and `bank_snapshot` are already open columns (Phase 10.1); the Stripe link uses a stable public pay URL, is created for the remaining amount in a queued idempotent job, and a link with a wrong amount never stays active (Phase 11 D-01..D-09); conversion to another currency uses the CNB rate valid on the issue date and freezes it in the snapshot (Phase 10 D-09); `invoice_payments` has a composite FK `(invoice_id, currency)`.

### Payment method
- **D-01:** The payment method is an exclusive per-document choice `transfer` | `online`, stored as a new column (replacing the implicit "card switch only" model for what the document prints). `transfer` prints the bank box, variable symbol and (where supported) the QR; `online` prints no bank details, no QR and no variable-symbol/bank rows, only the pay button/link and the facts needed to pay via Stripe. — **Reversibility:** costly — the column and the PDF/e-mail/detail templates all depend on it.
- **D-02:** The default of a new draft is always `transfer`, whatever the client flag or the global setting says. `online` is chosen by hand in the draft. A document of kind credit note carries no payment method.
- **D-03:** A `transfer` document may also be paid by card: it keeps the existing card-button behaviour driven by the Phase 11 card switch (global `online_payments_enabled` AND Stripe configured AND the per-document card switch, whose default still follows the client's `online_payment_enabled` flag). So a transfer invoice can show the bank details and the "Pay by card" button together, as today. Choosing `online` forces the card switch on and requires the card payment to be available (global setting on, Stripe configured, client flag on). The client flag therefore stays as the client-level permission/default for the card switch; it no longer sets the payment method. — *Assumption derived from the owner's two clarifications ("default is transfer, online can be chosen" and "a transfer payment can also be paid online"); confirm in planning if the card switch for `transfer` should instead be dropped.*

### Bank accounts
- **D-04:** More than one bank account per currency is allowed: the `UniqueCurrencies` rule on `BankAccountSettings` is dropped (this overrides Phase 3 D-04 "one account per currency"). Each account needs a stable identifier (not its list position) so a draft can reference it; the planner chooses the identifier and the settings migration for existing data.
- **D-05:** The draft stores the chosen account separately from the currency. When the currency is set or changed, the first configured account of that currency is preselected (order of the settings list); the Admin may pick any other account at any time, including one in a different currency. If no account matches the currency, no account is preselected and a `transfer` document cannot be issued until one is chosen; an `online` document needs no account.
- **D-06:** An account whose currency differs from the document's currency is allowed with no warning. The QR is printed only when `Spayd::supports(currency, iban)` holds (today CZK with a matching IBAN); otherwise the document prints the bank details without a QR. The bank snapshot is taken from the chosen account at issue.

### Invoice currency
- **D-07:** The currency selectable in a draft is the union of: currencies for which CNB rates are available, the currencies of all configured bank accounts, and the client's currency. It no longer depends on the account or the payment method. Conversion of rates in another currency follows Phase 10 D-09.
- **D-08:** The currency of a document is carried unchanged to the PDF (amounts, QR/SPAYD decision) and to the Stripe link. Stripe-unsupported currencies are not pre-checked: the link job fails through the existing Phase 11 D-09 path (Admin alerted, issuing never blocked).

### Changes after issue
- **D-09:** Payment method, bank account and currency are open columns of an issued document, like currency and `bank_snapshot` since Phase 10.1: changeable until a successful e-mail send locks the document; afterwards only after an Admin unlock with a recorded reason. Number, kind and variable symbol stay frozen.
- **D-10:** Changing the payment method or the currency of an open issued document deactivates any active Stripe link first. If the resulting state offers card payment (method `online`, or `transfer` with the card switch on), a new link is created for the remaining amount in the new currency by the queued job. The public pay URL stays the same; when no card payment is offered the existing information page is shown.
- **D-11:** The currency cannot be changed while the document has a recorded payment (composite FK on `invoice_payments`, Phase 10 D-17..D-19); the Admin deletes the payment through `DeletePayment` first. Payment method and account may still change with a payment recorded.

### Claude's Discretion
Column and enum names, the account identifier format and the settings migration, the exact draft/edit form layout and wording (cs/en), validation message text, the order of the currency list, how the CNB currency list is obtained for the dropdown, and how `online` is shown on the Partner invoice detail.

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope.

Reviewed todos (not folded): "Redesign unreadable time report layout" — matched on keywords only; unrelated to payments.

Not in this phase (CONTEXT domain): new payment providers, refunds, bank statement matching, VAT calculations, new e-mail layouts (Phase 13).
</user_constraints>

<phase_requirements>
## Phase Requirements

No requirement IDs are mapped to this phase (`TBD` in ROADMAP.md and REQUIREMENTS.md). The planner (or orchestrator) should register IDs before planning closes. The behaviours below are the testable units this research derives from D-01..D-11; they are **proposed** IDs, not existing ones.

| Proposed ID | Behaviour | Research support |
|-------------|-----------|------------------|
| P14-01 | A draft, invoice or proforma has an exclusive payment method `transfer`/`online`; default `transfer`; a credit note has none | Schema section, Pattern 1 |
| P14-02 | `transfer` prints bank box + variable symbol + QR (CZK/CZ IBAN only); `online` prints none of them, only the pay button/link and the amount/due facts | Pattern 4 (PDF) |
| P14-03 | Several accounts per currency; accounts have a stable id; a draft stores the chosen account separately from currency | Pattern 2 |
| P14-04 | Setting/changing currency preselects the first account of that currency; any account may be chosen; no match means no preselection and a blocker for `transfer` | Pattern 2, Pitfall 5 |
| P14-05 | Currency list = CNB currencies + account currencies + client currency | Pattern 3 |
| P14-06 | Method, account, currency are open columns of an open issued document and frozen when locked | Schema section, Pitfall 1 |
| P14-07 | A method or currency change deactivates the live Stripe link and, if card is still offered, queues a new one in the new currency; pay URL unchanged | Pattern 5 |
| P14-08 | Currency change refused with a recorded payment; method/account change allowed | existing `currencyBlocker` |
| P14-09 | `online` requires card payment available (global on, Stripe configured, client flag on) and blocks issue otherwise | Pitfall 4 |
</phase_requirements>

## Summary

The phase is mostly a data-model and wiring change on top of a mature invoicing/payments codebase. Three facts drive the plan. First, **today the document has no concept of payment method**: `InvoicePdfData::payment()` returns `null` when `bank_snapshot` is null and builds the whole box from the bank snapshot, and the card button is a separate block keyed on the existence of `invoice_admin.pay_token`. Second, **four database rules block the feature as specified** and must be amended in one migration: `invoices_issued_fields_check` demands a bank snapshot on every issued invoice/proforma (an `online` document has none); `kokpit_guard_invoice_row()` lists the open columns exactly (and a test pins that list); `kokpit_guard_invoice_admin_issued()` freezes `card_payment_enabled` and `pay_token` for every issued document, but "online forces the card switch on" and "the pay URL stays the same" both require changing them on an open issued document; and `invoice_admin_pay_token_check` forbids a token on a document whose switch is off. Third, **the bank account is resolved by currency in five places** (`InvoiceSnapshot::bank`, `IssueChecks` blocker, `EditInvoice::bankAccountFor`, `UpdateIssuedDocument::currencyChange`/`reload_bank`, `IssuedDocumentRules::currencyChoices`) and the settings list has no identifier, so all five move to "account by id".

The Stripe side is already largely done: `PaymentLink` rows carry the document currency at insert (guard-enforced), `PaymentLinks::reconcile()` already deactivates a live link whose currency differs from the document, and `IssuedEditFinisher` already opens a new pending link after an edit. What is new is deactivating the link on a method change when amount and currency are unchanged, and making "card offered" a function of the method.

**Primary recommendation:** Store `payment_method` on the Partner-readable `invoices` row (nullable, NULL only for credit notes, DB-checked against `bank_snapshot`), store the chosen account id on the Admin-only `invoice_admin` row, keep `invoice_admin.card_payment_enabled` as the single "card is offered" predicate (the Action forces it on for `online`), and open `card_payment_enabled` and a once-only `pay_token` on open issued documents. Give every `BankAccount` a UUID v7 `id` written by a settings migration and by `BankAccountSettings::save()`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Payment method storage and invariants | Database | API / Backend (Action validation) | CHECKs tie method to `bank_snapshot`; project rule is "enforced in DB" |
| Method / account / currency change rules | API / Backend (Actions) | Database guard (open-column list) | Actions run under the invoice row lock; trigger is defence in depth |
| Bank account identity and preselection | API / Backend (settings domain) | Browser (live Select refill) | Settings are server state; Filament form only mirrors the rule |
| Currency list (CNB union) | API / Backend (query/service) | Browser (Select options) | `exchange_rates` is Admin-only data, read server-side |
| PDF payment box variants | API / Backend (`InvoicePdfData`) | — | PDF is rendered server-side from the row and snapshots |
| Stripe link lifecycle on change | API / Backend (queued job + `PaymentLinks`) | Database (`kokpit_guard_payment_link`) | Stripe is never called inside a transaction (Phase 11) |
| Partner view of the method | Frontend Server (Filament Partner panel) | Database (Partner-safe column pin) | Partner reads `invoices` only; `payment_method` is safe, bank account id is not on that table |
| Online availability gate | API / Backend (`CardPaymentSwitch`) | Browser (disabled option + helper) | Same predicate must run at draft save, issued edit, and issue |

## Standard Stack

No new packages. Everything below is already installed and locked.

### Core (existing, versions read from `composer.lock` / `composer.json`)
| Library | Version | Purpose | Why standard here |
|---------|---------|---------|-------------------|
| laravel/framework | v13.38.0 | Application, validation, queue | [VERIFIED: composer.lock `"name": "laravel/framework"`, `"version": "v13.38.0"`] |
| filament/filament | v5.10.3 | Admin and Partner panels, forms | [VERIFIED: composer.lock `"name": "filament/filament"`, `"version": "v5.10.3"`] |
| spatie/laravel-settings | 3.10.0 | Bank account list in group `bank` | [VERIFIED: composer.lock `"name": "spatie/laravel-settings"`, `"version": "3.10.0"`] |
| stripe/stripe-php | v22.0.0 | Payment Links (already wrapped by `StripeGateway`) | [VERIFIED: composer.lock `"name": "stripe/stripe-php"`, `"version": "v22.0.0"`] |
| dompdf/dompdf | ^3.1 | PDF render | [VERIFIED: composer.json require] |
| pestphp/pest | ^5.3 | Tests (Feature suites use `RefreshDatabase` on pgsql `kokpit_test`) | [VERIFIED: composer.json require-dev, tests/Pest.php] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `payment_method` on `invoices` | on `invoice_admin` | Admin-only table cannot be CHECKed against `bank_snapshot` and the Partner detail would need a system-context read. Rejected. |
| Account id on `invoices` | on `invoice_admin` (recommended) | `invoices` is Partner-readable and its column list is pinned by `PartnerSafeColumnsTest`; an id of a settings entry has no Partner use. Admin-only wins, and `invoice_admin` already follows the lock without a trigger change. |
| `card_payment_enabled` stays frozen, "offered" = `method = online OR switch` | open the switch on open issued documents (recommended) | The first leaves `payment_links` insert guard, `PaymentLinkStatus`, `RegeneratePaymentLink`, `IssuedEditFinisher`, `DocumentIssuer` each needing a new predicate; the second keeps all of them untouched. |
| New deactivation reason `method_changed` | reuse `Replaced` | A new reason needs the `payment_links_deactivation_check` constraint, the enum, a label and UI copy; `Replaced` already exists and means "a new link replaced it" |

**Installation:** none.

## Package Legitimacy Audit

No external packages are installed or upgraded by this phase. `gsd_run query package-legitimacy check` was not run because there is nothing to check. **Packages removed due to [SLOP] verdict:** none. **Packages flagged as suspicious [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
Admin (Filament)                     Domain Actions (one transaction, invoice row locked)            Database (guards)
-----------------                    ----------------------------------------------------            -----------------
Settings > Bank tab ---------------> BankAccountSettings::save()
  accounts[] (+hidden id)              assigns missing UUID v7 ids, no UniqueCurrencies
                                                                                                      
Create-draft modal ---currency-----> CreateDraft
                                       method = transfer, account = first of currency (or null)
                                       admin.card_payment_enabled = client flag (as today)
                                                                                                      
Draft editor ----currency------------> UpdateDraft  <-- method, bank_account_id, card switch
  Select currency (union list)           currency change => preselect first account of new currency
  Select method (transfer|online)        online => switch forced on, needs CardPaymentSwitch::available()
  Select account (all accounts)          draft keeps bank_snapshot NULL
  Toggle card (transfer only)                                                                         
                |                                                                                     
Issue ---------------------------------> IssueChecks::blockers  (bank_missing = no usable account,
                                           online_unavailable) -> DocumentIssuer
                                          snapshot = transfer ? account(id) : NULL                    invoices_issued_fields_check
                                          token + switch via CardPaymentSwitch                        (online) = (bank_snapshot IS NULL)
                                          PaymentLink::open + CreatePaymentLink after commit          kokpit_guard_payment_link
                                                                                                      
Issued edit (open doc) -----------------> UpdateIssuedDocument  <-- currency, payment_method,
                                          bank_account_id, card switch, reload_bank                   kokpit_guard_invoice_row (open_columns
                                            1 header/currency/method/account on invoices               + 'payment_method')
                                            2 admin row: account id, switch, token NULL->value          kokpit_guard_invoice_admin_issued
                                            3 PaymentLinks: deactivate live link when method/currency  (token set-once, switch free while open)
                                              changed                                                  child guard: locked => frozen
                                            4 IssuedEditFinisher: czk total, new pending link if
                                              card offered, ReplaceIssuedPdf, audit row
                                                                  |                                   
                                                                  v                                   
                       InvoicePdfData  (method transfer: bank box, VS, QR; online: pay box only)       
                       PayLinkBlock / PartnerPayButton / PaymentLinkStatus (switch AND token)         
                       Partner detail (method; bank rows only for transfer)                           
Queue: CreatePaymentLink -> Stripe (currency = document currency, amount = remaining)
```

### Recommended Project Structure
```
app/Domain/Invoicing/Enums/DocumentPaymentMethod.php   # new: Transfer | Online (PaymentMethod.php is the recorded-payment enum)
app/Domain/Invoicing/Support/PaymentMethodChange.php   # new: validate + apply method/account/switch/token for draft and issued
app/Domain/Invoicing/Support/InvoiceCurrencies.php     # new: union list (CNB + accounts + client + current + CZK)
app/Domain/Settings/Banking/BankAccount.php            # + ?string $id (trailing, default null)
app/Domain/Settings/Settings/BankAccountSettings.php   # - UniqueCurrencies; + find(id), firstForCurrency(); save() assigns ids
app/Domain/Settings/Rules/UniqueCurrencies.php         # delete (and its lang key)
database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php
database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php
database/migrations/2026_10_24_000300_add_bank_account_id_and_open_card_switch_to_invoice_admin.php
```

### Pattern 1: Payment method column, DB-checked (D-01, D-02, D-09)

**What:** `invoices.payment_method varchar(16) NULL`. Values `transfer`, `online`. NULL exactly for a credit note.

**Schema decisions, each backed by code read this session:**

1. The open column list of the row guard is exact and currently reads (verbatim): `'currency', 'language', 'issue_date', 'taxable_supply_date', 'due_date', 'subtotal_minor', 'vat_minor', 'total_minor', 'note', 'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'lock_state', 'updated_at'` [VERIFIED: database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php:90-94]. A new column on `invoices` is frozen until listed (same file, docblock lines 19-21). `payment_method` must be added to `open_columns` by `CREATE OR REPLACE FUNCTION kokpit_guard_invoice_row()`. The test `pins the open column list inside the invoice row guard exactly` [VERIFIED: tests/Feature/Schema/InvoiceLockGuardTest.php:444-460] must be updated in the same plan, deliberately.
2. The issued-fields check currently reads (verbatim): `... AND (kind = 'credit_note' OR (due_date IS NOT NULL AND bank_snapshot IS NOT NULL))))` [VERIFIED: database/migrations/2026_10_17_000200_create_invoices_tables.php:111]. Replace the constraint so an issued non-credit-note requires `(payment_method = 'online') = (bank_snapshot IS NULL)` instead of `bank_snapshot IS NOT NULL`. This amends Phase 10 D-12 (bank snapshot required) to "required for `transfer`".
3. Add `invoices_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('transfer','online'))` and `invoices_payment_method_kind_check CHECK ((kind = 'credit_note') = (payment_method IS NULL))`.
4. **Insert default through the guard trigger, not a column default.** 55 test statements insert into `invoices` by raw SQL or `DB::table` [VERIFIED: grep count this session], and credit notes must stay NULL. The existing guard already sets `lock_state := 'locked'` for an inserted credit note because "a BEFORE trigger runs ahead of the checks" [VERIFIED: 2026_10_22_000100 docblock lines 54-56 and function lines 97-102]. Do the same: in the `INSERT` branch, `IF NEW.kind <> 'credit_note' AND NEW.payment_method IS NULL THEN NEW.payment_method := 'transfer'`. Application code still sets it explicitly; the default is a safety net that keeps the raw-SQL fixtures valid.
5. **Backfill:** `UPDATE invoices SET payment_method = 'transfer' WHERE kind <> 'credit_note'` is refused by the row guard for every issued row ("the old guard refuses the backfill itself", same docblock line 62). Follow the Phase 10.1 migration: replace the function, `ALTER TABLE invoices DISABLE TRIGGER invoices_row_guard`, run the backfill, `ENABLE TRIGGER`, then add the constraints. Do not touch `updated_at`.
6. `LoggedAttributes` on `Invoice` gains `payment_method`; the real-column rule of `ActivityAllowlistColumnsTest` is satisfied by the new column. `PartnerSafeColumnsTest` pins the exact `invoices` column list [VERIFIED: tests/Isolation/PartnerSafeColumnsTest.php:160-188]; add `payment_method` there after the review sentence ("every Partner may read the method; it carries no price, rate or account").
7. Optional defence in depth (cross-table, planner decides): `kokpit_guard_invoice_admin_issued()` refuses `card_payment_enabled = false` while the parent row has `payment_method = 'online'`, and `kokpit_guard_invoice_row()` refuses a move to `online` while the Admin-only row's switch is false. Both read one row without taking a new lock order (the admin guard already does `SELECT state FROM invoices WHERE id = OLD.invoice_id FOR SHARE`, [VERIFIED: 2026_10_22_000100:152]). If added, the Action order is fixed: switch on first then method online; method transfer first then switch off.

**Enum:** `App\Domain\Invoicing\Enums\DocumentPaymentMethod` with `Transfer = 'transfer'`, `Online = 'online'`, `HasLabel`, label keys under `enums.document_payment_method.*` (cs). `PaymentMethod` (`Transfer`, `Cash`, `Card`) is the recorded-payment enum and must not be reused [VERIFIED: app/Domain/Invoicing/Enums/PaymentMethod.php:15-18].

### Pattern 2: Bank account identity and the chosen account (D-04, D-05, D-06)

- `BankAccount` is a `final readonly` value object whose constructor is called positionally in 11 places [VERIFIED: grep this session]. Add `public ?string $id = null` as the **last** parameter so none of them change. `fromArray`/`toArray` read and write `id`.
- `BankAccountSettings::save()` (already overridden to validate per account, [VERIFIED: app/Domain/Settings/Settings/BankAccountSettings.php:159-183]) assigns `Str::uuid7()` to any account without an id before `parent::save()`. The repo already makes every generated UUID version 7 [VERIFIED: app/Providers/ModelConventionsServiceProvider.php:36 `Str::createUuidsUsing(static fn () => Uuid::uuid7());`], so `Str::uuid()` and `Str::uuid7()` both satisfy "UUID v7 everywhere".
- The settings form needs a `Hidden::make('id')` in the Repeater so the id round-trips; `fillFromFormState` also assigns a missing id (new repeater item) so the domain, not the form, is the source of truth. Add an `ids unique and well-formed` rule in `rules()` replacing `UniqueCurrencies`.
- **Settings migration** (`database/settings/`, base class `App\Domain\Settings\SettingsMigration` with `protected function migrate()`, [VERIFIED: app/Domain/Settings/SettingsMigration.php:20-27]): `$this->migrator->update('bank.accounts', fn (array $accounts) => array_map(fn ($a) => ($a['id'] ?? null) ? $a : [...$a, 'id' => (string) Str::uuid7()], $accounts))`. `SettingsMigrator::update(string $property, Closure $closure, bool $encrypted = false)` receives the decoded payload [VERIFIED: vendor/spatie/laravel-settings/src/Migrations/SettingsMigrator.php:81-96]. Existing precedent: database/settings/2026_10_17_000200_distinct_proforma_number_pattern.php. **Never generate ids lazily on read** (e.g. in `BankAccountListCast::get`): the id would change on every request.
- New settings API: `find(string $id): ?BankAccount`, `firstForCurrency(string $currency): ?BankAccount` (the old `forCurrency`, same order-of-list semantics as D-05), `accountsForCurrency`. Keep `forCurrency` as an alias only if needed by tests; its meaning ("the account of a currency") is no longer true.
- **Chosen account storage:** `invoice_admin.bank_account_id uuid NULL`, no foreign key (the target is a JSON entry in `settings`). It is Admin-only, follows the lock through the existing child guard (a locked parent allows only `internal_note`, `internal_note_updated_at`, `pdf_document_id`, `updated_at`, [VERIFIED: 2026_10_22_000100:176-181]) and needs no change to the pinned Partner-safe lists.
- **Resolution rules (one helper, used by form, `IssueChecks`, `DocumentIssuer`, `UpdateIssuedDocument`):**
  - draft, `bank_account_id` set and found in settings: that account;
  - draft, `bank_account_id` NULL (legacy draft or no match at creation): first account of the document currency (D-05 preselection), persisted on the next save;
  - draft, `bank_account_id` set but no longer in settings: **not chosen** (blocker), never silently re-picked;
  - issued: the stored `bank_snapshot` is authoritative; `bank_account_id` only preselects the edit form. NULL on an issued document (legacy) means "keep the snapshot unless the Admin picks an account".
- **Account vs currency rule on change** (Action level, so the API of the Action matches the form): when `currency` changes and the data carries no `bank_account_id`, the Action applies the preselection (first of new currency, else NULL). When the data carries `bank_account_id`, it wins. An issued `transfer` document that would end with no account is refused as `bank_account` (replaces today's `bank_missing` under `currency` in `UpdateIssuedDocument::currencyChange`).
- D-06 consequence to keep: an EUR account on a CZK document, or a CZ IBAN account on a EUR document, is accepted silently. `Spayd::supports($currency, $iban)` requires `$currency === 'CZK'` and a valid CZ IBAN [VERIFIED: app/Domain/Invoicing/Pdf/Spayd.php:34-43], so the QR rule needs no change. Note the QR can still be printed for a CZK document paid to a CZ IBAN that is the supplier's EUR account; that is the owner's explicit decision (D-06), do not add a warning.

### Pattern 3: Currency list (D-07, Claude's discretion)

Current sources: `CreateDraftAction::currencyOptions()` = account currencies + client currency [VERIFIED: app/Filament/Support/CreateDraftAction.php:438-455]; `IssuedDocumentRules::currencyChoices()` = account currencies + document currency + client currency [VERIFIED: app/Domain/Invoicing/Support/IssuedDocumentRules.php:94-107]; `EditInvoice::currencyOptions()` delegates to the latter. Replace all three with one `InvoiceCurrencies::choices(?Client $client, ?string $current): list<string>` = distinct, sorted union of `ExchangeRate::query()->distinct()->pluck('currency')`, all account currencies, the client currency, the document's current currency, and `CZK` (CNB publishes no CZK row; CZK needs no rate).

- `exchange_rates` has one row per currency and day with a `currency` CHECK `^[A-Z]{3}$` [VERIFIED: database/migrations/2026_10_15_000100_create_exchange_rates_table.php:46]; the model is closed to Partners, which is fine because the list is built only in Admin pages.
- A currency that is in the list but has no CNB list on the issue date is already handled by the `cnb_missing` blocker in `IssueChecks` [VERIFIED: app/Domain/Invoicing/Support/IssueChecks.php:80-82]; no new check.
- `UpdateDraft::currency()` and `CreateDraft::currency()` accept any ISO code known to `Money::isKnownCurrency`, only the issued path enforces the list (`UpdateIssuedDocument::currencyChange` uses `currencyChoices`). Keep that asymmetry; point the issued check at the new service.

### Pattern 4: PDF variants (D-01, D-06, D-08)

`InvoicePdfData::fromInvoice` computes `$payment = $kind->carriesPayment() ? self::payment(...) : null` and `payment()` returns `null` when `bank_snapshot` is null [VERIFIED: app/Domain/Invoicing/Pdf/InvoicePdfData.php:105 and 258-262]. The template prints the box when `$data->payment !== null`, with an optional QR cell, a `sentence`, `facts`, and the card button only inside that box [VERIFIED: resources/views/pdf/invoice.blade.php:195-230]. Plan:

- `payment()` branches on `$issued->payment_method`:
  - `Transfer`: unchanged (bank facts, variable symbol, QR only if `Spayd::supports`), currency now independent of the account.
  - `Online`: `qrDataUri = null`; `sentence` = new key such as `invoice_pdf.online_sentence` ("Please pay :amount online by card by :due."); `facts` = payment method ("Online payment") + amount due, no `account`, `iban`, `bic`, `variable_symbol` rows. The template needs no structural change (the box already renders without a QR and with a card button).
- The card button condition changes from "a pay token exists" to "card is offered and a token exists": `cardPayment()` currently returns a URL whenever `PayUrl::forInvoice()` finds a token [VERIFIED: InvoicePdfData.php:331-340]. Once a token can outlive the switch (see Pitfall 2) it must also read `invoice_admin.card_payment_enabled`. The class docblock says it reads only the token from the Admin-only row; update that sentence.
- English and Czech keys live in `lang/cs/invoice_pdf.php` and `lang/en/invoice_pdf.php`; the existing keys include `payment_method`, `bank_transfer`, `card_lead`, `card_button` [VERIFIED: lang/cs/invoice_pdf.php:35-39, lang/en/invoice_pdf.php:34-38]. A test compares cs/en key sets for `invoice_mail` (docblock of lang/cs/invoice_mail.php); keep the pair in step.
- Amounts, QR and the Stripe link all derive from `$issued->currency`; no further work for "currency carries through to the PDF".

### Pattern 5: Stripe link on change (D-08, D-10)

What already works and must only be tested, not rebuilt:
- `PaymentLink::open()` writes the locked invoice's current `currency` into the link row and the guard refuses a link in another currency than the document [VERIFIED: app/Domain/Payments/Models/PaymentLink.php:62-80; database/migrations/2026_10_22_000300_relax_payment_link_currency_key.php:36-37 `a payment link is created in the currency of its document`].
- `PaymentLinks::reconcile()` deactivates a live link whose `currency` differs from the document with `AmountChanged` [VERIFIED: app/Domain/Payments/Support/PaymentLinks.php:78-82].
- `IssuedEditFinisher::paymentLink()` runs `reconcile`, then opens a new pending link for the remaining amount when the frozen switch is on and no live link remains, and dispatches `CreatePaymentLink` after commit [VERIFIED: app/Domain/Invoicing/Support/IssuedEditFinisher.php:100-127].
- Stripe request building uses the link row's currency and refuses ISK/UGX before any call: `UNSUPPORTED_CURRENCIES = ['ISK', 'UGX']` [VERIFIED: app/Domain/Payments/Stripe/PaymentLinkRequest.php:22]; a failure becomes a `failed` row plus an Admin alert (D-08 as locked).

What is new:
1. **Method-change deactivation.** `reconcile` does nothing when amount and currency are unchanged, so a transfer-to-online switch would leave the old link alone, contradicting D-10. Add `PaymentLinks::deactivateLive(Invoice $locked, PaymentLinkDeactivation $reason)` (lock the live row, call existing `deactivate()`), and call it from `UpdateIssuedDocument` when `payment_method` or `currency` changed, before `IssuedEditFinisher::finish()`. Use `Replaced` for a method change (no new constraint needed) and keep `AmountChanged` for a currency change. Do **not** use `Manual`: `IssuedEditFinisher` suppresses the new link when the latest link was deactivated `manual` [VERIFIED: IssuedEditFinisher.php:120], which would leave an `online` document with no link.
2. **Explicit change overrides an earlier manual switch-off.** If the newest link was manually deactivated and the Admin now switches to `online`, the explicit choice should open a link. Give `IssuedEditFinisher::finish()` a `forceNewLink` flag (default false) used only for method/currency changes. Flag to the owner if disagreement (Open Question 3).
3. **The deactivation reason list is a DB constraint**: `deactivation_reason IN ('paid', 'cancelled', 'amount_changed', 'manual', 'replaced')` [VERIFIED: database/migrations/2026_10_18_000400_create_payment_links_table.php:68]. Adding a case to the PHP enum without altering this constraint fails at the first write.
4. `CreatePaymentLink::staleReason()` checks cancelled/paid/amount but not currency or "card still offered" [VERIFIED: app/Domain/Payments/Jobs/CreatePaymentLink.php:296-309]. It is safe today because the edit transaction deactivates the pending row (it is "live") and the job then returns at `state !== Pending` (line 140) or, if Stripe was already called, takes the `settle()` stale path. Add tests for exactly this race (method/currency change while the job is between phase A and C) rather than changing the job.

### Pattern 6: Card switch semantics (D-03) — the part that needs the DB change

`invoice_admin.card_payment_enabled` is frozen at issue: `kokpit_guard_invoice_admin_issued()` raises when `NEW.pay_token IS DISTINCT FROM OLD.pay_token OR NEW.card_payment_enabled IS DISTINCT FROM OLD.card_payment_enabled` on an issued document [VERIFIED: database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php:158-162], and `invoice_admin_pay_token_check` reads `pay_token IS NULL OR (card_payment_enabled AND pay_token ~ '^[A-Za-z0-9]{32}$')` [VERIFIED: database/migrations/2026_10_18_000200_add_card_payment_to_invoice_admin.php:38]. D-09/D-10 require, on an open issued document: transfer(card off) -> online must set the switch on and create a token; online -> transfer must be able to turn the switch off while the printed pay URL keeps working.

**Recommendation (Open Question 1 in the log):**
- Keep `card_payment_enabled` as the single "card is offered" predicate. Invariant enforced by the Action: `payment_method = online` implies `card_payment_enabled = true`. Every existing consumer keeps working unchanged: `PaymentLinkStatus` (`$switch`), `RegeneratePaymentLink`, `IssuedEditFinisher`, `DocumentIssuer::openPaymentLink`, and the `payment_links` insert guard (`card_enabled IS DISTINCT FROM true` raises, [VERIFIED: 2026_10_22_000300:58-59]).
- Migration: replace `kokpit_guard_invoice_admin_issued()` so that on an issued document it refuses a change of `invoice_id`, refuses any change of an existing `pay_token` (set-once: NULL -> value is allowed, value -> other/NULL is not), and no longer refuses a change of `card_payment_enabled`. A locked document is still frozen because the separate child guard `invoice_admin_parent_guard` refuses everything except four columns under a locked parent [VERIFIED: 2026_10_22_000100:176-181]. The row is still never deleted.
- Migration: replace `invoice_admin_pay_token_check` with the format-only form `pay_token IS NULL OR pay_token ~ '^[A-Za-z0-9]{32}$'` so the token survives a switch-off. `down()` must refuse to run when any row has a token with the switch off (same style as the existing `down()` that refuses when a token exists, [VERIFIED: 2026_10_18_000200 lines 44-52]).
- The Action generates the token with the existing `PayToken::generate()` the first time a document with the switch on has none.
- The switch toggle must be editable in both forms for `transfer` documents and shown as on and disabled for `online`. Today it is `visible(! issuedMode() && kind->carriesPayment())` and `disabled(! available())` [VERIFIED: app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php:961-965], and `issuedValues()` never reads it ("the card switch ... not read from the form at all (T-10.1-24)", docblock lines 683-686). Opening it on issued documents is a deliberate reversal of that decision; record it.

### Anti-Patterns to Avoid
- **Deriving the account from the currency anywhere.** Every call site of `forCurrency($currency)` / `snapshots->bank($currency)` must go (list in Pitfall 6). A leftover one silently re-couples currency and account.
- **Generating account ids on read** (changes per request, see Pattern 2).
- **Putting `bank_account_id` on `invoices`.** It forces a Partner-safe pin update and a guard change for no Partner benefit.
- **Using `PaymentLinkDeactivation::Manual` for automatic deactivations** (suppresses regeneration).
- **Testing the pay URL only through the token.** After this phase "the button is printed" means switch AND token, in the PDF, the e-mail pay block, the Admin action and the Partner button.
- **Relying on the Filament Repeater default to create ids.** Assign in the domain; verify the form round-trip with a Livewire test.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Account id generation | custom id scheme or list-position keys | `Str::uuid7()` (global v7 generator already installed) | Positions shift when the Admin reorders or deletes accounts (D-04) |
| Settings payload evolution | raw SQL on `settings` | `SettingsMigration` + `$this->migrator->update()` | Handles the system context and JSON payload shape |
| Pay token | a second token column or scheme | `PayToken::generate()` / existing `pay_token` | Printed URLs must not change (Phase 11 D-01) |
| Stripe link teardown | direct Stripe calls in the edit transaction | `PaymentLinks::deactivate()` + `DeactivatePaymentLinks` job after commit | Phase 11 rule: never call Stripe inside a transaction |
| Link creation after edit | new job | `PaymentLink::open()` + `CreatePaymentLink::dispatch()->afterCommit()` as in `IssuedEditFinisher` | Idempotent by row id, already handles failure and alerting |
| Currency decimal handling | own rounding | `Money`, `TimeLineConversion::freezeAt()` | Existing single rounding rule; the currency-change path already exists |
| QR eligibility | new rule | `Spayd::supports()` | Already currency- and IBAN-aware |
| Cross-field integrity | PHP-only checks | CHECK + trigger in one migration, following 10.1 | Project rule: integrity enforced in the DB |

**Key insight:** this phase adds almost no new mechanism. The risk is in the places where "currency decides the account" and "a token means a card button" are implicit; finding and decoupling them all is the work.

## Runtime State Inventory

This phase changes the stored shape of two existing things (the settings list of accounts and the `invoices` rows), so the inventory applies.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `settings` row group `bank`, name `accounts`: accounts have no id. `invoices`: every non-credit-note row needs `payment_method = 'transfer'`; issued rows are guard-frozen. `invoice_admin`: new `bank_account_id` (NULL for all existing rows). Open drafts reference no account. | Settings migration (add ids, data migration). Schema migration with guard disabled for backfill (data migration). `bank_account_id` NULL is valid for legacy rows (code edit: resolution rules in Pattern 2). Optional best-effort backfill of `bank_account_id` by matching `bank_snapshot` to an account, not required. |
| Live service config | Stripe Payment Links already created for existing documents stay valid; no Stripe-side config embeds the method. | None. |
| OS-registered state | None — verified: no scheduler/cron entry embeds an account or method (the only recurring jobs are `PruneProcessedWebhookCalls` and the link sweep, which read `payment_links`). | None. |
| Secrets / env vars | `STRIPE_*` keys and `online_payments_enabled` unchanged; `CardPaymentSwitch::available()` still reads them. | None. |
| Build artifacts | None; no package rename. Filament/Blade caches rebuild with `optimize:clear` as usual. | None. |

## Common Pitfalls

### Pitfall 1: The invoice row guard silently freezes the new column
**What goes wrong:** `payment_method` added without touching `open_columns` makes every edit of an open issued document fail with KP001 ("the number, kind and identity of an issued document never change").
**Why it happens:** `(to_jsonb(NEW) - open_columns) IS DISTINCT FROM (to_jsonb(OLD) - open_columns)` treats any unlisted column as frozen [VERIFIED: 2026_10_22_000100:122].
**How to avoid:** include the column in the same `CREATE OR REPLACE FUNCTION`; update the pinned-list test on purpose.
**Warning signs:** green draft tests, red issued-edit tests.

### Pitfall 2: A token that outlives the switch re-enables a dead button
**What goes wrong:** after online -> transfer (card off) the PDF and e-mail still print the pay URL, because they test only for a token (`InvoicePdfData::cardPayment`, `PayLinkBlock::url`, `PayUrl::forInvoice`).
**Why it happens:** today a token exists exactly when the switch was on at issue.
**How to avoid:** every printer/emitter checks `card_payment_enabled AND token`. `PaymentLinkStatus::for` already returns `'url' => $switch ? PayUrl::forInvoice($invoice) : null` [VERIFIED: PaymentLinkStatus.php:88]; make `PayLinkBlock::url`, `InvoicePdfData::cardPayment` and `PayByCardAction` use the same gate. Consider one `PayUrl::offeredFor(Invoice)` that returns the URL only when the switch is on.
**Warning signs:** a PDF of a `transfer` document with the card off shows "Zaplatit kartou".

### Pitfall 3: Issuing an `online` draft when card payment became unavailable
**What goes wrong:** the draft was saved `online` with the switch on; later the global setting is turned off or Stripe keys removed; `DocumentIssuer::writeAdminRow` computes `CardPaymentSwitch::effective()` = false and freezes switch off, token null, so the document is `online` with no way to pay.
**Why it happens:** `effective(kind, draftSwitch)` ANDs the installation state [VERIFIED: app/Domain/Payments/Support/CardPaymentSwitch.php:32-35].
**How to avoid:** new `IssueChecks` blocker `online_unavailable` (same code path for the UI list and `assertIssuable`) when `payment_method = online` and `! CardPaymentSwitch::available()`. Same refusal in `UpdateDraft` and `UpdateIssuedDocument` when choosing `online`. Also require the client flag (D-03): `$client->online_payment_enabled`.
**Warning signs:** an issued `online` PDF with no button.

### Pitfall 4: An online document fails the old `bank_snapshot IS NOT NULL` check
**What goes wrong:** `DocumentIssuer` writes `bank_snapshot = null` for `online`; `invoices_issued_fields_check` rejects it (23514), rolling back the issue.
**How to avoid:** the constraint change in Pattern 1 and a schema test that an issued `online` row without snapshot is accepted and a `transfer` row without one is refused.

### Pitfall 5: The currency change path still refuses without an account
**What goes wrong:** `UpdateIssuedDocument::currencyChange` throws `bank_missing` under `currency` when the settings hold no account for the new currency [VERIFIED: UpdateIssuedDocument.php:366-367]; with the account now independent this blocks a legal edit (new currency, existing account).
**How to avoid:** remove that check from `currencyChange`; the account requirement moves to the end of the Action (`transfer` without a resolvable account -> `bank_account`). `IssuedCurrencyChangeTest`, `IssuedHeaderEditTest` and `IssueDocumentTest` assert the old behaviour [VERIFIED: grep for `bank_missing`/`reload_bank` this session] and must be rewritten, not patched.

### Pitfall 6: Hidden currency-to-account couplings
Sites to change (all read this session): `InvoiceSnapshot::bank(string $currency)` (app/Domain/Invoicing/Support/InvoiceSnapshot.php:77-80); `IssueChecks` blocker (`forCurrency($draft->currency) === null`, IssueChecks.php:76); `DocumentIssuer` (`snapshot->bank($lockedDraft->currency)`, DocumentIssuer.php:116); `UpdateIssuedDocument::header()` `reload_bank` and `currencyChange` (lines 265-275, 366); `EditInvoice::bankAccountFor/bankAccountState/issuedBankState/refillBankAction` (lines 1700-1720, 1094-1106, 1057-1076); `IssuedDocumentRules::currencyChoices` (lines 94-107); `CreateDraftAction::currencyOptions` (lines 438-455); `SettingsPage::bankTab` `->distinct()` on currency (SettingsPage.php:366-372). Add a grep-based Arch test that no application file calls `forCurrency(` after the change.

### Pitfall 7: Converting a proforma paid online
**What goes wrong:** `ConvertProforma::createDraft` creates a plain `InvoiceAdmin` and a draft with the default method; the invoice then needs an account that may not exist for an `online` proforma, so `IssueChecks` blocks the conversion of a document that is already paid.
**How to avoid:** decide the inheritance rule (Open Question 2). Recommended: the invoice copies `payment_method` and `bank_account_id` from the proforma. For `online` the copied switch is on, so `DocumentIssuer::openPaymentLink` would open a link for the full total; `RecordPayment::carry` does not call `PaymentLinks::reconcile` (only `RecordPayment::handle` does, [VERIFIED: RecordPayment.php:142 vs the `carry` method at 165-195]), so call `reconcile` after the carries in `ConvertProforma`.

### Pitfall 8: Stripe minimums and in-flight payments after a currency change
**What goes wrong:** (a) switching a small amount to another currency can fall below Stripe's minimum (the docs list e.g. 15.00 CZK, 0.50 EUR) and the link comes back `invalid_request`; (b) a client who opened the old link before the change may pay it after the edit.
**Why it happens:** Stripe enforces per-currency minimums [CITED: docs.stripe.com/currencies "Minimum charge amount by currency"]; the remote deactivation runs after commit.
**How to avoid:** (a) nothing to pre-check (D-08); make sure the failed-link state shows on the document and the Admin alert text is unchanged. (b) an old-currency payment is handled by the existing amount+currency match (PA-06/08: unmatched or unresolved payment alert); add one test documenting it.

### Pitfall 9: The public information page says "being prepared"
After online -> transfer (card off) with the old URL already sent, `PayPageState` returns `Preparing` ("Odkaz na platbu se připravuje") because no active link exists [VERIFIED: app/Domain/Payments/Queries/PayPageState.php:82-86 and lang/cs/pay_page.php `preparing`]. D-10 explicitly keeps the existing page, so do not add an outcome unless the owner asks; mention it in the plan's UAT notes.

### Pitfall 10: Filament live-field ordering on currency change
`EditInvoice::currencyChosen()` may abort the change and open a confirmation action when typed prices exist, resetting the field to the old value [VERIFIED: EditInvoice.php:1439-1458]. The account preselection must run after the confirmed change as well (in the `changeCurrency` action), not only in the Select's `afterStateUpdated`, or the account stays on the old currency's first account.

## Code Examples

### Account id assignment in the settings domain (new)
```php
// BankAccountSettings::save() — before parent::save(); ids are generated once and then stored.
$this->accounts = array_map(
    static fn (BankAccount $account): BankAccount => $account->id !== null ? $account : $account->withId((string) Str::uuid7()),
    $this->accounts,
);
```
Source: pattern derived from the existing `save()` structure [VERIFIED: BankAccountSettings.php:159-183]; `withId()` is a new method on the readonly value object.

### Insert-time default in the guard (new, mirrors the existing lock_state line)
```sql
-- inside kokpit_guard_invoice_row(), INSERT branch
IF NEW.kind <> 'credit_note' AND NEW.payment_method IS NULL THEN
  NEW.payment_method := 'transfer';
END IF;
```
Mirrors `IF NEW.state = 'issued' AND NEW.kind = 'credit_note' THEN NEW.lock_state := 'locked'; END IF;` [VERIFIED: 2026_10_22_000100:97-101].

### Set-once token, free switch (new guard body fragment)
```sql
IF document_state = 'issued' THEN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION '...never deleted' USING ERRCODE = 'KP001'; END IF;
  IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
     OR (OLD.pay_token IS NOT NULL AND NEW.pay_token IS DISTINCT FROM OLD.pay_token) THEN
    RAISE EXCEPTION 'the document and an issued pay token never change' USING ERRCODE = 'KP001';
  END IF;
END IF;
```
Adapted from the current body [VERIFIED: 2026_10_22_000100:154-163].

### Deactivate the live link on a method or currency change (new)
```php
// PaymentLinks
public function deactivateLive(Invoice $locked, PaymentLinkDeactivation $reason): void
{
    $link = PaymentLink::query()->live()->where('invoice_id', $locked->id)->lockForUpdate()->first();

    if ($link instanceof PaymentLink) {
        $this->deactivate($link, $reason); // queues DeactivatePaymentLinks after commit when a Stripe id exists
    }
}
```
Built on `PaymentLinks::deactivate()` [VERIFIED: PaymentLinks.php:94-109].

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| One account per currency, resolved by currency | Accounts by stable id, currency independent | This phase (overrides Phase 3 D-04) | Five code paths plus the settings form |
| Card button iff pay token exists | Card button iff method/switch offers it | This phase | PDF, e-mail block, Partner button, Admin action |
| Switch and token frozen at issue | Switch free and token set-once while open | This phase (amends Phase 11 D-06 "frozen at issue") | One trigger and one CHECK replaced |
| Bank snapshot required on every issued invoice/proforma | Required for `transfer`, forbidden for `online` | This phase (amends Phase 10 D-12) | Constraint, issuer, PDF |

**Deprecated/outdated:** `UniqueCurrencies` rule and its lang key; `BankAccountSettings::forCurrency` as "the" account of a currency; the `bank_missing` message text ("Pro měnu :currency není nastaven bankovní účet") which now means "no account chosen".

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The card switch toggle should be shown and editable for `transfer` documents in both the draft and the open issued editor, and disabled-on for `online`; going online -> transfer leaves the Admin to decide the switch. D-03 itself flags this as an assumption to confirm. | Pattern 6 | If the owner wants `transfer` to mean "no card", the toggle disappears and the guard change shrinks to token set-once. |
| A2 | Opening `card_payment_enabled` and set-once `pay_token` on open issued documents is acceptable (reverses T-10.1-24 / Phase 11 D-06 "frozen at issue"). | Pattern 6, Pitfall 2 | Alternative design (frozen switch, offered = method OR switch) touches many more files. |
| A3 | A converted invoice inherits the proforma's payment method and account, with `reconcile` after the carries. | Pitfall 7 | If the owner prefers always `transfer`, an online-paid proforma in a currency without an account cannot be converted without choosing one. |
| A4 | A method or currency change always opens a new link when card is offered, even when the previous link was deactivated manually. | Pattern 5 | Admin who switched a link off by hand gets a link again after an unrelated method change. |
| A5 | Reusing `PaymentLinkDeactivation::Replaced` for a method change is acceptable copy ("Nahrazen novým odkazem") even when no new link follows. | Pattern 5 | Misleading label in the link section; fix is a constraint + enum case + label. |
| A6 | Legacy rows with `bank_account_id` NULL need no backfill (drafts preselect at read, issued documents keep their snapshot). | Pattern 2, Runtime State Inventory | A legacy draft shows a different preselected account than the Admin remembers; low impact. |
| A7 | `CZK` is always offered in the currency list although CNB has no CZK row. | Pattern 3 | CZK missing from the dropdown for a client in another currency. |
| A8 | The Filament 5 Repeater round-trips a `Hidden('id')` child field of an existing item. Not verified in vendor this session; the domain assigns missing ids so the risk is limited to losing an id on save. | Pattern 2 | A lost id changes the account id of an existing account, breaking drafts that referenced it. A Livewire round-trip test closes this. |
| A9 | The proposed requirement IDs P14-01..09 do not collide with the ID scheme the owner wants. | Phase Requirements | Rename before planning. |
| A10 | Stripe presentment-currency availability depends on the Stripe account country; the docs page rendered without its currency table in this session, so no claim is made about which CNB currencies Stripe accepts beyond ISK/UGX refused by `PaymentLinkRequest`. | Pitfall 8 | A CNB currency Stripe does not take fails at link creation (handled by D-08/D-09). |

## Open Questions

1. **Card switch for `transfer` documents (D-03 assumption).**
   - What we know: D-03 keeps the switch for `transfer`; CONTEXT asks to confirm in planning. The switch is currently frozen at issue and hidden on the issued editor.
   - What's unclear: whether the Admin can turn the switch on/off on an open issued transfer document, and what the switch becomes after online -> transfer.
   - Recommendation: show the toggle in both editors for `transfer`; on online -> transfer in the form, reset the toggle to the client's flag (as a new draft would). Ask the owner once before planning.
2. **Proforma conversion.** What method and account does the invoice made from a paid proforma get? Recommendation: inherit both; call `PaymentLinks::reconcile` after the carries (Pitfall 7).
3. **Manual switch-off versus an explicit method change.** Recommendation: the explicit change wins (A4).
4. **Partner wording for `online`.** Discretion item. Recommendation: payment section shows "Platba online (kartou)" and the pay button; bank rows and variable symbol hidden. The bank rows read `bank_snapshot`, which is NULL for `online`, so only the label and the VS row need a condition.
5. **E-mail body for `online`.** The Czech body says "Platební údaje najdete v přiloženém dokumentu." [VERIFIED: lang/cs/invoice_mail.php body `with_due_date`] and the pay block lead says "Doklad můžete zaplatit online kartou:" [VERIFIED: same file `pay_block_lead`]. Both are acceptable for `online`. Phase 13 rebuilds the invoice e-mail around `PayLinkBlock`; keep `PayLinkBlock::applies/forMail` as the one predicate so Phase 13's template inherits the new gate. Phase 13 has only a CONTEXT so far (no plans); if it is planned first, tell its planner that the pay-block gate changes here.
6. **Requirement IDs.** None mapped; register before planning (A9).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV (web + postgres + redis) | all tests (host has no DB) | yes | v1.25.4; db `postgres:18`; web PHP 8.5 | — |
| PHP on host | static tools only | yes | 8.5.12 | run inside DDEV |
| PostgreSQL 18 (in DDEV) | triggers, CHECKs, `uuidv7()` | yes | 18 | — |
| Redis (in DDEV) | queue | yes | — | DB queue |
| Stripe credentials | live link creation | not needed | tests use fakes (`tests/Support/CardPaymentSetup.php`) | — |

**Missing dependencies with no fallback:** none. **Missing with fallback:** none.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13.3 [VERIFIED: composer.json] |
| Config file | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; `DB_CONNECTION=pgsql`, `DB_DATABASE=kokpit_test`) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Feature/Operations/BankAccountSettingsTest.php` |
| Full suite command | `ddev exec vendor/bin/pest` (also `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `ddev exec vendor/bin/pint --test`, the `composer ci` parts) |

Project memory: tests and static analysis run through DDEV, never on the host (the host has no DB).

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| P14-01 | `payment_method` NULL only for credit notes; invoice/proforma default `transfer` also for raw inserts; CHECK rejects other values | schema | `pest tests/Feature/Schema/InvoiceTablesTest.php` | extend (file exists) |
| P14-01 | New draft is `transfer` regardless of client flag and global setting | feature | `pest tests/Feature/Invoicing/DraftLifecycleTest.php` | extend |
| P14-02 | Transfer PDF has bank box, VS, QR only for CZK + CZ IBAN; online PDF has none of them but has button + amount | feature | `pest tests/Feature/Invoicing/InvoicePdfVariantsTest.php` | extend |
| P14-02 | CZK doc with EUR-currency account prints bank rows, no warning; EUR doc with CZ IBAN prints no QR | feature | same file | extend |
| P14-03 | Two accounts with the same currency save; each keeps a stable id across reorder and re-save; settings migration assigns ids to legacy payload | feature | `pest tests/Feature/Operations/BankAccountSettingsTest.php` | replace the two "same currency" tests, extend |
| P14-04 | Create draft / change currency preselects first account of that currency; no match leaves NULL and `IssueChecks` blocks `transfer`, not `online` | feature | `pest tests/Feature/Invoicing/DraftLifecycleTest.php tests/Feature/Invoicing/IssueDocumentTest.php` | extend |
| P14-05 | Currency list = CNB + account + client + current + CZK, sorted, no duplicates | unit/feature | `pest tests/Feature/Invoicing/CreateDraftModalTest.php` (+ new `InvoiceCurrenciesTest`) | extend / ❌ Wave 0 |
| P14-06 | Open-column list pinned with `payment_method`; locked document refuses method, account and currency writes; open accepts them | schema | `pest tests/Feature/Schema/InvoiceLockGuardTest.php` | extend (update the pinned list on purpose) |
| P14-06 | Token set-once NULL -> value on open issued doc; value -> other/NULL refused open and locked; switch free when open, frozen when locked | schema | `pest tests/Feature/Schema/InvoiceAdminTablesTest.php` | rewrite lines 225-250 |
| P14-06 | Issued `online` row without snapshot accepted; issued `transfer` row without snapshot refused | schema | `pest tests/Feature/Schema/InvoiceTablesTest.php` | extend |
| P14-07 | transfer -> online on open issued doc: old link deactivated (`Replaced`), pending link in document currency opened and job dispatched after commit; pay URL unchanged | feature | `pest tests/Feature/Payments/LinkLifecycleTest.php` | extend |
| P14-07 | currency change: link deactivated `AmountChanged`, new link in new currency; online -> transfer with card off: link deactivated, no new link, pay page `Preparing` | feature | `pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Invoicing/IssuedCurrencyChangeTest.php` | extend |
| P14-07 | Race: method change while the link job is between Stripe call and settle ends with no active link | feature | `pest tests/Feature/Payments/CreatePaymentLinkTest.php` | extend |
| P14-08 | Currency change refused with a payment (`currency_has_payments`), method and account change allowed with a payment | feature | `pest tests/Feature/Invoicing/IssuedHeaderEditTest.php` | extend |
| P14-09 | `online` refused / blocks issue when global off, Stripe missing or client flag off | feature | `pest tests/Feature/Payments/CardSwitchTest.php` | extend |
| all | Partner sees method, never an account id or bank row for `online`; Partner-safe pin includes `payment_method` | isolation | `pest tests/Isolation` | extend `PartnerSafeColumnsTest`, `InvoiceLeakTest` |
| all | No application file calls `forCurrency(` or `snapshots->bank(`/`bank($currency)` | arch | `pest tests/Arch` | ❌ Wave 0 (new arch test) |
| all | Audit snapshot has `payment_method`, never the account id or number; `invoice_edited` row records a method change | feature | `pest tests/Feature/Invoicing/InvoiceEditAuditTest.php` | extend |
| P14-04 | Settings bank tab: Hidden id round-trips through a Livewire save of an existing item | feature | `pest tests/Feature/Operations/BankAccountSettingsTest.php` | extend |

### Sampling Rate
- **Per task commit:** the narrowest file from the table above through DDEV.
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Isolation tests/Arch`.
- **Phase gate:** full `ddev exec vendor/bin/pest`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `ddev exec vendor/bin/pint --test` green before `/gsd-verify-work`; plus `scripts/check-sensitive.sh` before commits (project rule).

### Wave 0 Gaps
- [ ] `tests/Feature/Invoicing/InvoiceCurrenciesTest.php` — P14-05
- [ ] `tests/Arch/BankAccountByIdTest.php` — no remaining `forCurrency(` / currency-based `bank(` callers (P14 all)
- [ ] `tests/Support/InvoiceFixtures.php` helper to build a document with a chosen payment method and account, and two accounts in one currency (existing helper at lines 117-121 builds one CZK and one EUR account)
- [ ] Update `tests/Feature/Schema/InvoiceLockGuardTest.php` pinned list, `tests/Isolation/PartnerSafeColumnsTest.php` invoices list, `tests/Feature/Schema/InvoiceAdminTablesTest.php` token tests — these fail by design until the migrations land
- Framework install: none (Pest present)

## Security Domain

`security_enforcement` is enabled (absent = enabled; `security_asvs_level` 1, `security_block_on` high in `.planning/config.json`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no auth change) | — |
| V3 Session Management | no | — |
| V4 Access Control | yes | `InvoicePolicy`/Gate `update` on every Action, Partner global scope; `payment_method` on the Partner-readable table is deliberate and pinned, account id and bank snapshot stay Admin-side |
| V5 Input Validation | yes | Server-side enum parse of `payment_method` (`tryFrom`, reject unknown), account id must be a UUID found in `BankAccountSettings`, currency must be in `InvoiceCurrencies::choices` for issued edits; never take an account or token from request data (existing T-10.1-28 rule: "The account itself never travels" extended to "only an id of a configured account travels") |
| V6 Cryptography | yes | `PayToken::generate()` (existing CSPRNG-based 32-char token); never hand-roll, never log it |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Forged `bank_account_id` pointing at a non-configured or foreign value | Tampering | Resolve through `BankAccountSettings::find()`; reject unknown ids with a field error; snapshot copied from settings, never from the request |
| Switching method on a locked document through a stale Livewire page | Tampering | Row lock plus `IssuedDocumentRules::assertEditable()` on the locked row (existing) and the DB guard refusing the write |
| Pay token leaking through the audit log or Partner views | Information disclosure | Token stays off `LoggedAttributes` (existing comment: bearer value), `payment_method` is logged, account id and token are not; audit uses `bank_fingerprint` only |
| Token reuse after switch-off giving a dead but valid URL | Spoofing / Info | Token is set-once and only resolves to the public info page; `PayPageState` redirects only on an active link of the exact remaining amount |
| Partner sees bank details of an `online` document | Information disclosure | `online` documents have `bank_snapshot` NULL by CHECK; the Partner detail reads the snapshot only for `transfer` |
| Race between a payment and a currency change | Tampering / Repudiation | Composite FK `invoice_payments (invoice_id, currency)` plus `currencyBlocker` under the invoice row lock (existing, D-11) |
| Account details written into git (fictional-data rule) | Information disclosure | Test accounts built at runtime via `FictionalIban::czech()` fragments as in existing fixtures; run `scripts/check-sensitive.sh` |

## Sources

### Primary (HIGH confidence) — files read in this session
- `.planning/phases/14-online-payment-method-and-invoice-currency/14-CONTEXT.md`, `.planning/phases/13-.../13-CONTEXT.md`, `.planning/phases/11-stripe-payments/11-CONTEXT.md` (D-01..D-09 lines), `.planning/ROADMAP.md` Phase 13/14, `.planning/REQUIREMENTS.md` IN-03..IN-15 and PA-01..PA-08, `.planning/config.json`
- `database/migrations/2026_10_17_000200_create_invoices_tables.php`, `2026_10_18_000200_add_card_payment_to_invoice_admin.php`, `2026_10_18_000400_create_payment_links_table.php`, `2026_10_22_000100_add_lock_state_to_invoices.php`, `2026_10_22_000300_relax_payment_link_currency_key.php`, `2026_10_17_000100_create_kokpit_child_guard_functions.php`, `2026_10_15_000100_create_exchange_rates_table.php`
- `app/Domain/Settings/**` (BankAccount, BankAccountSettings, BankAccountListCast, UniqueCurrencies, SettingsMigration), `app/Domain/Invoicing/**` (Invoice, InvoiceAdmin, CreateDraft, UpdateDraft, UpdateIssuedDocument, ConvertProforma, DocumentIssuer, IssueChecks, IssuedDocumentRules, IssuedEditFinisher, InvoiceSnapshot, InvoiceAudit, InvoicePdfData, Spayd, PaymentMethod enum), `app/Domain/Payments/**` (CardPaymentSwitch, PaymentLinks, PaymentLink, CreatePaymentLink, RegeneratePaymentLink, PayUrl, PayLinkBlock, PayPageState, PartnerPayButton, PaymentLinkStatus, PaymentLinkRequest, PaymentLinkDeactivation), `app/Domain/ExchangeRates/**`, `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php`, `app/Filament/Support/CreateDraftAction.php`, `app/Filament/Support/InvoiceColumns.php`, `app/Filament/Pages/SettingsPage.php` (bank tab)
- `resources/views/pdf/invoice.blade.php`, `lang/cs|en/invoice_pdf.php`, `lang/cs/invoice_mail.php`, `lang/cs/pay_page.php`, `lang/cs/enums.php`
- `tests/Pest.php`, `phpunit.xml`, `tests/Support/InvoiceFixtures.php`, `tests/Feature/Schema/InvoiceLockGuardTest.php`, `tests/Feature/Schema/InvoiceAdminTablesTest.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Feature/Operations/BankAccountSettingsTest.php`, `tests/Arch/ActivityAllowlistTest.php`
- `vendor/spatie/laravel-settings/src/Migrations/SettingsMigrator.php` (`update`), `composer.json`, `composer.lock`

### Secondary (MEDIUM confidence)
- [CITED: https://docs.stripe.com/currencies] — zero-decimal currencies, ISK/UGX two-decimal special case, per-currency minimum charge amounts (15.00 CZK, 0.50 EUR, 0.50 USD), "over 135 currencies"; the supported-presentment table did not render in the fetch.

### Tertiary (LOW confidence)
- None used for claims; training-only statements are tagged `[ASSUMED]` in the Assumptions Log (A8, A10).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no change, versions read from the lockfile.
- Architecture: HIGH for the schema/guard/Action changes (every guard and check was read); MEDIUM for the card-switch design because it reverses an earlier "frozen at issue" rule (A1, A2).
- Pitfalls: HIGH — each is tied to a read line; Stripe minimums are documentation-level.

**Research date:** 2026-10-23
**Valid until:** 2026-11-22 (stable internal code; re-check if Phase 13 lands first and changes `PayLinkBlock`/`InvoiceMail`)

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` in the repository root (project instructions).
- **Fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages: no real client names, prices, rates, invoice data, e-mails, company IDs, bank accounts, IPs, hostnames, tokens or personal paths. Use `example.com`, company ID `12345678`, `/Users/example/`, and test fakes assembled at runtime from fragments (this phase's IBANs: `FictionalIban::czech()` as in existing fixtures).
- Review before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`; the lefthook hook runs it plus gitleaks; never bypass (`--no-verify`, `LEFTHOOK=0`).
- Stack fixed: PHP 8.5, Laravel, Filament SPA, PostgreSQL, S3-compatible bucket, Sanctum, Stripe Payment Links, queue; AGPL-compatible dependencies only (this phase adds none).
- Partner must never see measured time, rates, prices or finance, nor another client's data — enforced by Policies and global query scopes, not only UI hiding.
- Data integrity: UUID v7 keys everywhere incl. package morph columns; FK, unique, partial indexes and check constraints enforced in the DB (applies to the account id and the new CHECKs).
- Immutability: an issued document stays editable until a successful e-mail send locks it; Admin may unlock with a recorded reason; number and kind never change; snapshots for supplier, customer, rates, exchange rates.
- Concurrency: number sequences gap-free under concurrent creation (not touched; edits keep the invoice row lock).
- GSD workflow: file changes only through a GSD command; everything under `.planning/` is written in English; tests and static analysis run in DDEV (`ddev exec vendor/bin/pest`, phpstan), per user memory.
- No project skills found in `.claude/skills/` or `.agents/skills/`.
