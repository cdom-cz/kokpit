# Phase 15: Cash payment method for invoices and cash receipt documents - Research

**Researched:** 2026-10-27
**Domain:** Czech invoicing / accounting documents (cash receipt, PPD) on Laravel 13 + Filament 5 + PostgreSQL (existing Kokpit invoicing domain)
**Confidence:** MEDIUM (codebase findings HIGH; legal findings MEDIUM: one primary statute source, the rest secondary; EET 2.0 status LOW-MEDIUM, a bill at the time of writing)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): money is bigint minor units plus ISO 4217 (Phase 2 D-09); a document stays editable until a successful e-mail send locks it, the Admin may unlock with a recorded reason (Phase 10.1); payment method on a document is an exclusive column with open-column rules, a card switch and a Stripe link lifecycle (Phase 14 D-01..D-11, OA-1..OA-7); recorded payments (`invoice_payments`, `RecordPayment`, `DeletePayment`) already carry `PaymentMethod::Cash` and book the income in one transaction (Phase 10 D-17..D-19); number sequences go through `SequenceAllocator` and are gap-free (Phase 3).

**Cash as a payment method**
- **D-01:** `DocumentPaymentMethod` gets a third case `Cash` next to `Transfer` and `Online`; the `invoices.payment_method` column and its CHECK constraint are extended, not a new column. A credit note still carries no method. Reversibility: costly - the column, the CHECK, the PDF, e-mail and detail templates and the Stripe link logic all branch on it.
- **D-02:** A document with method `Cash` hides all payment details: no bank box, no bank account, no QR, no variable symbol, no "Pay by card" button, no bank snapshot (`bank_snapshot` NULL, like `online` under Phase 14 OA-7). It prints only the amount, the due date and what is needed to identify the document. (Owner, explicit: "hide the payment details on a cash invoice".)
- **D-03:** `Cash` excludes card payment: the card switch is off and locked, no Stripe link is created, and the public pay URL shows the existing information page. This differs from `Transfer`, which can still be paid by card.
- **D-04:** `Cash` is chosen and changed like the other methods (Phase 14 D-09): in the draft and, while the document is open, on an issued invoice or proforma until a successful e-mail send locks it; afterwards only after an Admin unlock. Changing to `Cash` deactivates any active Stripe link (Phase 14 D-10). The default of a new draft stays `Transfer`.
- **D-05:** Currency stays independent of the method, so a cash document may have any currency. No bank account is chosen or required for `Cash`.

**When a cash receipt document (PPD) is issued**
- **D-06:** A PPD is issued automatically, in the same transaction as the payment and the income, for every recorded payment whose payment method is cash (`PaymentMethod::Cash`). It follows the method of the payment, not the method of the document, so a transfer invoice paid in cash also gets a PPD, and so does a proforma. No cash payment is left without a PPD.
- **D-07:** One PPD per payment (partial payments each get their own, for the amount of that payment). When a proforma is converted to an invoice, its payments and PPDs are carried by reference and no new PPD is issued (the existing `RecordPayment::carry()` rule: no second confirmation, no second document).
- **D-08:** A payment that has a PPD cannot be deleted through `DeletePayment` directly. The Admin first cancels the PPD (storno) with a recorded reason; the number is never reused and never returned. Only then can the payment be deleted.

**Numbering and immutability**
- **D-09:** The PPD has its own number series and pattern: a new `DocumentKind::CashReceipt` with a pattern setting in `NumberingSettings` (default pattern chosen by the planner, e.g. `PPD{YYYY}{NNNN}`), allocated by `SequenceAllocator` so it is gap-free and duplicate-free under concurrent creation. It carries no variable symbol and no QR, so `carriesVariableSymbol()` is false for it. Reversibility: one-way - issued numbers and the settings migration cannot be undone.
- **D-10:** A PPD follows the invoice rules of Phase 10.1: editable until a successful e-mail send locks it, unlockable by the Admin with a recorded reason, with number and kind frozen. Editable fields are the receipt date, the purpose text and the supplier and customer snapshots. The amount and currency are tied to the payment and change only through the payment.

**PDF, e-mail and display**
- **D-11:** The PPD PDF is generated at issue from snapshots only (supplier, customer, amount, currency, payment date, purpose), in the client's invoice language, stored in documents like invoice PDFs, and regenerated on an edit. The legally required content comes from research.
- **D-12:** The PPD PDF is attached to the payment confirmation e-mail of Phase 13 (`QueuePaymentConfirmation`); no separate e-mail and no separate send state. The confirmation e-mail is the delivery, and a successful send of it counts as the send that locks the PPD (D-10).
- **D-13:** The Admin sees the PPD in the invoice detail next to the payment (PDF download, cancel action, edit while open). The Partner sees and downloads the PPDs of their own invoices only, read-only, never drafts or internal notes, and never time rates, enforced by Policy and scope, not only UI hiding.

### Claude's Discretion
Enum case and column names, the table shape of the PPD (own table or reuse of the invoice table with a new kind; the planner decides after research), the default numbering pattern, the migration of the CHECK constraint, the exact wording (cs/en), the layout of the PPD PDF, the form for the cancel reason, and how the cancelled state is shown.

### Deferred Ideas (OUT OF SCOPE)
None - discussion stayed within phase scope. Not in this phase: new payment providers, a cash register / cash book module, reports on cash, refunds, VAT calculations beyond what the research shows the PPD must carry. (Reviewed todo "Redesign unreadable time report layout" was not folded: unrelated.)
</user_constraints>

<phase_requirements>
## Phase Requirements

ROADMAP lists Requirements as TBD for this phase. The planner should register the IDs below in REQUIREMENTS.md (P15-xx, same style as P14-xx) so plans and verification can reference them. They are derived from the goal and D-01..D-13.

| ID | Description | Research Support |
|----|-------------|------------------|
| P15-01 | A draft, invoice or proforma may have payment method `cash` (third value); credit note still none | Touchpoint table (enum, CHECK, `DocumentPayment`) |
| P15-02 | A cash document prints no bank box, account, QR, variable symbol or pay button, and stores no bank snapshot | PDF `payment()` branch, CHECK rewrite, `ConvertProforma`, `IssueChecks` |
| P15-03 | Cash excludes card payment: switch off and locked, no Stripe link, pay URL shows the information page | `DocumentPayment::issuedSwitch/forDraft`, `PaymentLinks::deactivateLive` |
| P15-04 | Method is chosen/changed in the draft and while the issued document is open; change to cash deactivates the live link | `UpdateIssuedDocument` `method_changed` path (already generic) |
| P15-05 | Every recorded cash payment (any document method, invoice or proforma) issues one PPD in the payment transaction; carried copies issue none | `RecordPayment::recordLocked` hook, `carry()` untouched |
| P15-06 | PPD has its own gap-free series `DocumentKind::CashReceipt` with a pattern setting; no VS/QR | `DocumentNumbering`, `NumberingSettings`, `NumberPattern` |
| P15-07 | PPD is editable until locked by the successful confirmation send; unlock with reason; number/kind/amount/currency frozen | New `cash_receipts` guard trigger modelled on `kokpit_guard_invoice_row` |
| P15-08 | PPD PDF carries the content required by the Accounting Act Sec. 11, built from snapshots, in the client's language, stored and regenerated on edit | Legal findings, `CashReceiptPdf` modelled on `InvoicePdf` |
| P15-09 | PPD PDF is attached to the payment confirmation e-mail; the send locks the PPD | `PaymentConfirmationMail::attachments()`, `SendPaymentConfirmation` |
| P15-10 | A payment with a PPD cannot be deleted before the PPD is cancelled with a reason; the number is never reused | `DeletePayment` guard + DB FK design |
| P15-11 | Admin sees/downloads/edits/cancels PPDs in the invoice detail; Partner sees and downloads own non-draft PPDs read-only, enforced by policy and scope | Partner isolation section |
| P15-12 | Cancelled PPD keeps number and PDF, is marked cancelled, is audited | Audit allowlist, storno pattern |
</phase_requirements>

## Summary

A cash receipt (pokladni prijmovy doklad, PPD) is the accounting document by which the receiver of cash records that money entered the cash desk; the payer normally gets a copy or a tear-off receipt (stvrzenka) as proof of payment. It is an internal accounting document (ucetni doklad) of the receiving entity, not a tax document. No statute prescribes a form or a number format for it. What the law prescribes is the general content of any accounting document in Sec. 11 of Act 563/1991 Coll. (designation of the document, content of the case and the parties, amount, date of creation, date of the case if different, and signature records of the responsible persons), the right to keep records in technical (electronic) form with equal effect (Sec. 33), and that a signature record may be a "similar record in technical form" that proves origin (Sec. 33a(4)). Gap-free, uninterrupted numbering is a practice recommendation to prove completeness, not a statutory wording. [CITED: zakonyprolidi.cz/cs/1991-563] [CITED: skrblik.cz secondary]

Kokpit supports only a non-VAT-payer supplier in this milestone (`VatMode` doc: "Only NonPayer is supported in this milestone"), so the PPD needs no VAT lines. A tax document for a received advance payment (Sec. 28 VAT Act, within 15 days) matters only for VAT payers and is a recorded future concern, not a Phase 15 deliverable. The 270,000 CZK same-day cash payment limit (Act 254/2004) and the planned "EET 2.0" sales recording from 1 Jan 2027 (passed by the Chamber on 9 Sept 2026 over a Senate veto, awaiting the President as of the last source found) are compliance facts the owner should know; neither changes what Kokpit must generate, and both are out of scope (no cash book, no registration).

Technically the phase is a wide but shallow change on a mature codebase: (1) extend `DocumentPaymentMethod` with `Cash` and replace the scattered `=== Online` branches with two predicates (prints bank details / allows card), (2) extend two DB CHECKs, (3) add a new `cash_receipts` table with its own guard trigger, number series, PDF, storage owner type, Admin and Partner surfaces, (4) hook PPD creation into `RecordPayment::recordLocked`, (5) attach the PDF in the confirmation job and lock the PPD on a successful send, (6) guard `DeletePayment`. Recommendation on the open design choice: use a **separate `cash_receipts` table**, not a new kind in `invoices`; the invoices table is welded to invoice-only checks (due date, variable symbol, total sign, composite document links).

**Primary recommendation:** Add `DocumentPaymentMethod::Cash` with `printsBankDetails()` / `allowsCardPayment()` helpers, create a dedicated `cash_receipts` table (one row per cash payment, composite FKs to the payment and invoice, own guard trigger, `ON DELETE SET NULL (invoice_payment_id)` so a cancelled PPD survives deletion of its payment), allocate the number in `RecordPayment::recordLocked` via `DocumentNumbering` with the new `DocumentKind::CashReceipt`, render the PDF with a Dompdf view modelled on `InvoicePdf`, and attach it in `SendPaymentConfirmation`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Cash as document method (validation, switch, snapshot rules) | API / Backend (Actions + `DocumentPayment`) | Database (CHECK constraints) | Rules live in Actions; DB CHECK is the last line |
| PPD issue (number, snapshot, PDF) | API / Backend (`RecordPayment` transaction) | Database (sequence row lock, unique number, guard trigger) | Must be atomic with payment + income; gap-free via DB row lock |
| PPD immutability / lock / unlock | Database (guard trigger) | API / Backend (Actions) | Project rule: immutability enforced in DB, as for invoices |
| PPD PDF generation | API / Backend (Dompdf, queue not needed) | Storage (private bucket via `Document`) | Same as `InvoicePdf` |
| Delivery (attachment + lock on send) | API / Backend (queue job `SendPaymentConfirmation`) | Mail transport | Job already owns the confirmation lifecycle |
| Admin UI (detail, cancel, edit, unlock) | Frontend Server (Filament SSR/Livewire) | API / Backend | Filament actions call domain Actions |
| Partner read-only access | API / Backend (global scope + Policy + signed route) | Frontend Server | Security rule: not UI hiding only |
| Hide payment details on a cash invoice | API / Backend (PDF data builder, e-mail text) | Frontend Server (detail page boxes) | Snapshot-only rendering |

## Standard Stack

### Core (all already installed; no new packages)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 | Actions, queue, mail | Project stack [VERIFIED: composer.json] |
| filament/filament | ^5.0 | Admin and Partner panels | Project stack [VERIFIED: composer.json] |
| dompdf/dompdf | ^3.1 | PPD PDF, same renderer as invoices | `InvoicePdf` already runs it closed (remote/PHP/JS off) [VERIFIED: composer.json, app/Domain/Invoicing/Pdf/InvoicePdf.php] |
| spatie/laravel-settings | ^3.9 | `NumberingSettings` new `cash_receipt_pattern` property | Existing settings group `numbering` [VERIFIED: composer.json] |
| spatie/laravel-activitylog | ^5.1 | Audit of PPD issue/edit/cancel/lock | Existing `LogsAllowlistedActivity` wrapper [VERIFIED: composer.json] |
| pestphp/pest | ^5.3 | Tests | Project test framework [VERIFIED: composer.json] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Separate `cash_receipts` table | New kind `cash_receipt` in `invoices` | Rejected: `invoices` CHECKs require `due_date`, `variable_symbol` shape, positive total, `bank_snapshot` rules, `invoice_items`, composite links; every one would need a `kind` exception. A separate table keeps the invoice guard function and Partner column list untouched. |
| Own table | Store PPD data as columns on `invoice_payments` | Rejected: payments are immutable (UPDATE refused) while the PPD is editable until locked and cancellable. |

**Installation:** none. **Package Legitimacy Audit:** no external package is installed or added by this phase, so the gate has nothing to check.

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| (none added) | - | - | - | - | - | n/a |

**Packages removed due to [SLOP] verdict:** none. **Packages flagged [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
Admin: "Record payment" (method = cash)             Admin: edit document (method -> Cash)
        |                                                   |
        v                                                   v
RecordPayment::handle -> lock invoice row            UpdateIssuedDocument (open doc only)
        |                                              DocumentPayment::forIssued
        v                                              - bank_snapshot -> NULL, card switch -> false
RecordPayment::recordLocked (one DB transaction)       - PaymentLinks::deactivateLive(Replaced)
  1. insert invoice_payments row                      IssuedEditFinisher -> new PDF (no bank box)
  2. invoice only: bookIncome (finance write path)
  3. PaymentLinks::reconcile
  4. [NEW] if payment.method == Cash:
        IssueCashReceipt
          a. DocumentNumbering::next(CashReceipt)  <- number_sequences row lock (gap-free)
          b. snapshots: supplier, customer, appearance, language
          c. insert cash_receipts row (open)
          d. CashReceiptPdf::render -> StoreGeneratedDocument -> pdf_document_id
  5. QueuePaymentConfirmation (row + delayed job, 300 s grace)
        |
        v  (after commit, delayed)
SendPaymentConfirmation job
  - claim confirmation row (queued -> sending)
  - [NEW] load PPD of the payment (not cancelled) -> attach stored PDF
  - Mail::send(PaymentConfirmationMail ... attachments)
  - markSent  --> [NEW] same transaction: lock the PPD (cash_receipts.lock_state = locked) + audit

Admin invoice detail "Platby" tab: PPD number, PDF, edit (open), unlock (locked), cancel (reason)
Cancel PPD (reason) -> cancelled_at/by/reason set, PDF re-rendered with STORNO mark
   -> only now DeletePayment is allowed (PPD row survives, invoice_payment_id set NULL by FK)
Partner panel: invoice detail -> list of own PPDs (number, date, amount) + signed PDF route
```

### Recommended Project Structure
```
app/Domain/Invoicing/
├── Actions/IssueCashReceipt.php          # called by RecordPayment; number + row + PDF
├── Actions/UpdateCashReceipt.php         # edit while open (receipt date, purpose, snapshots) + PDF replace
├── Actions/UnlockCashReceipt.php         # reason 10..500, sanctioned setting (own literal)
├── Actions/CancelCashReceipt.php         # storno with reason, re-render PDF
├── Enums/CashReceiptLockState.php?       # or reuse InvoiceLockState (same open/locked values)
├── Models/CashReceipt.php                # PartnerIsolated + LogsAllowlistedActivity
├── Pdf/CashReceiptPdf.php, CashReceiptPdfData.php
├── Policies/CashReceiptPolicy.php
resources/views/pdf/cash-receipt.blade.php
app/Http/Controllers/CashReceiptPdfController.php   # route files.cash-receipt, same 7-step order as InvoicePdfController
database/migrations/..._add_cash_to_document_payment_method.php
database/migrations/..._create_cash_receipts_table.php
database/settings/..._add_cash_receipt_pattern.php
```
(Directory placement inside `Invoicing` mirrors where `InvoicePayment` and `PaymentConfirmation` live; a new `CashReceipts` domain folder is equally defensible, planner decides.)

### Pattern 1: Replace scattered `=== Online` with method predicates
**What:** Today the code compares `payment_method === DocumentPaymentMethod::Online` in at least: `IssueChecks.php:84,90`, `DocumentIssuer::bankSnapshot`, `writeAdminRow`, `ConvertProforma.php:82,131-152`, `InvoicePdfData.php:267,367`, `QueueInvoiceEmail.php:153`, `InvoiceColumns.php:159` (`$notOnline`), `ViewInvoice.php:968` (`=== Transfer`), `EditInvoice.php` (about twenty `isOnline()` uses), `DocumentPayment` (`forDraft`, `forIssued`, `issuedSwitch`, retake rule `$storedMethod === Online`). [VERIFIED: grep this session]
**When to use:** Add to the enum, then replace every site:
```php
// Source: design for this phase, based on app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
enum DocumentPaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Online = 'online';
    case Cash = 'cash';

    /** Transfer is the only method that prints bank details, a variable symbol and a QR. */
    public function printsBankDetails(): bool { return $this === self::Transfer; }

    /** Online forces the card switch on, Cash forces it off, Transfer leaves it to the Admin. */
    public function allowsCardChoice(): bool { return $this === self::Transfer; }
}
```
Existing verbatim values to preserve: `case Transfer = 'transfer';` `case Online = 'online';` [VERIFIED: app/Domain/Invoicing/Enums/DocumentPaymentMethod.php:19-20].

### Pattern 2: DB-enforced method/snapshot tie (extends Phase 14 OA-7)
Current checks, verbatim: `CHECK (payment_method IS NULL OR payment_method IN ('transfer', 'online'))` [VERIFIED: database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php:118] and `... (kind = 'credit_note' OR (due_date IS NOT NULL AND (payment_method = 'online') = (bank_snapshot IS NULL)))` [VERIFIED: same file:122]. New migration: drop and re-add both: `IN ('transfer', 'online', 'cash')` and `(payment_method IN ('online', 'cash')) = (bank_snapshot IS NULL)`. No change to `kokpit_guard_invoice_row()`: `payment_method` is already in its `open_columns` list [VERIFIED: same file, function body]. `down()` must refuse while a cash document exists and restore both checks verbatim (copy the Phase 14 `down()` style).

### Pattern 3: PPD creation inside the payment transaction
`RecordPayment::recordLocked()` already guards `DB::transactionLevel() === 0`, knows `$facts->method` and `$actor`, and `$actor` is non-null for any non-card payment (`LogicException` otherwise) [VERIFIED: RecordPayment.php recordLocked]. Insert the call after the income booking and `reconcile`, **before** `QueuePaymentConfirmation::handle` (the confirmation row is created in the same transaction and the delayed job reads the PPD later). Do not touch `carry()` (D-07).

### Pattern 4: Lock on confirmation send (D-12) without breaking the invoice-lock rule
`SendInvoiceEmail` documents "the only lock of the system" for the *invoice* and says a payment confirmation must never lock it [VERIFIED: SendInvoiceEmail.php docblock lines 62-66]. D-12 locks the *PPD*, a different row in a different table, so the invoice rule stays true. Update both docblocks to say so, and implement the lock in the same conditional-update pattern: `markSent()` becomes a transaction that (1) lock the PPD row, (2) conditional update `sending -> sent`, (3) only when that update changed the row and the PPD is open, set `lock_state = locked`, (4) audit. A resend of a locked PPD writes nothing.

### Pattern 5: Unlock/cancel sanctioned-setting pattern
Copy the `kokpit.invoice_unlock` mechanism (transaction-local `set_config`, cleared before commit, guard accepts only `lock_state`+`updated_at` change). Use a **new literal** (e.g. `kokpit.cash_receipt_unlock`) and add an architecture test like `tests/Arch/InvoiceUnlockPathTest.php` that keeps the literal out of every other file. [VERIFIED: tests/Arch/InvoiceUnlockPathTest.php exists; UnlockInvoice.php]

### Anti-Patterns to Avoid
- **Deriving "has PPD" from the document method.** D-06: it follows the payment method. A transfer invoice paid in cash gets a PPD; a cash invoice paid by transfer does not.
- **Storing the PPD amount only by reference and re-reading the payment for the PDF.** D-11 says snapshots only; copy amount/currency/dates/parties into the PPD row.
- **Using `ON DELETE CASCADE` from `cash_receipts` to `invoice_payments`.** It would delete a numbered document; numbers must never vanish (D-08).
- **Writing the lock from `SendPaymentConfirmation` with a bulk query update through Eloquent models that log.** Follow `SendInvoiceEmail`: model save inside the transaction so the audit row is written.
- **Adding a `{YYYY}`-less or month-reset default pattern.** Keep the yearly pattern; a month token creates monthly scope keys (`NumberPattern::scopeKey`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Gap-free numbers | A MAX(number)+1 query | `DocumentNumbering::next(DocumentKind::CashReceipt, $at)` -> `SequenceAllocator` (row lock `FOR UPDATE`, rolled back with the caller) | Concurrency constraint of the project |
| Number pattern validation | Own regex | `NumberPattern::parse` via `NumberPatternRule(DocumentKind::CashReceipt)` | Grammar, length, one counter token, year scope are already enforced |
| PDF rendering and storage | New Dompdf setup, own S3 code | `InvoicePdf` conventions (closed Dompdf options, font lock) and `StoreGeneratedDocument` + `ReplaceIssuedPdf` pattern | Hardened upload path, rollback cleanup of objects |
| Immutability | Application-only checks | A guard trigger modelled on `kokpit_guard_invoice_row` plus the sanctioned-setting unlock | Project requirement: DB-enforced |
| Signed PDF download | A public or presigned URL | Signed route + controller with the fixed check order of `InvoicePdfController` | Partner isolation, no storage path leaks |
| Audit | Custom log table | `LogsAllowlistedActivity` + `#[LoggedAttributes]` allowlist and `InvoiceAudit`-style helper | `ActivityAllowlistTest` enforces the list |
| Cash-on-document logic spread over templates | `@if method == 'cash'` in views | The two enum predicates and one `payment()` branch in `InvoicePdfData` | Phase 14 left ~30 comparisons; do not add a third copy |

**Key insight:** every hard part (numbering, immutability, PDF storage, Partner scoping, lock-by-send, audit) already has a tested mechanism in Phase 10/10.1/13/14. The risk in this phase is not inventing, it is missing one of the ~15 touchpoints and the arch/isolation tests that enumerate models, routes and logged models.

## Runtime State Inventory

Not a rename/refactor/migration phase in the rename sense, but it adds a value to a stored column and a settings property, so the existing-data angle is stated explicitly:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `invoices.payment_method` holds `transfer`/`online`/NULL only; existing cash payments (`invoice_payments.method = 'cash'`) recorded before this phase have **no PPD** | Decide in planning: no backfill (recommended: PPDs only for payments recorded from now on; historic cash payments are out of the "automatic" rule). Record this as an explicit decision, see Open Question 2. |
| Live service config | None - verified: Stripe links are per document and are deactivated by existing `PaymentLinks`; no external system stores the method | None |
| OS-registered state | None - verified: no scheduler/cron entry names methods or number series | None |
| Secrets/env vars | None - no new secret; `cash_receipt_pattern` is a settings property, not env | None |
| Build artifacts | `settings` table row group `numbering` lacks the new property until the settings migration runs; tests/seeders that build `NumberingSettings` need the property | Add a settings migration in `database/settings/` (pattern of `2026_10_17_000200_distinct_proforma_number_pattern.php`) and update `SettingsPage.php`, `tests/Feature/Operations/NumberingPageTest.php`, `NumberingTest.php` |

## Common Pitfalls

### Pitfall 1: The deletion FK blocks the very flow D-08 requires
**What goes wrong:** A plain `FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments` (NO ACTION) refuses `DeletePayment` forever, even after the PPD is cancelled, because the cancelled PPD row still exists.
**Why:** D-08 keeps the cancelled PPD (number never reused) yet allows deleting its payment.
**How to avoid:** Make the PPD's link nullable only for cancelled rows: composite FK `(invoice_payment_id, invoice_id) -> invoice_payments (id, invoice_id)` with `ON DELETE SET NULL (invoice_payment_id)` (column-list form; PostgreSQL 15+, the project already needs 18 for `uuidv7()`), plus `CHECK (invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL)`. Deleting a payment of an active PPD then fails on the CHECK (database-level D-08 even for raw SQL), deleting the payment of a cancelled PPD succeeds. The guard trigger on `cash_receipts` must allow exactly that FK-driven `invoice_payment_id -> NULL` on a cancelled row. Existing unique targets: `invoice_payments_id_invoice_unique UNIQUE (id, invoice_id)` [VERIFIED: database/migrations/2026_10_17_000300_create_invoice_admin_tables.php:180] and `invoices_id_client_unique UNIQUE (id, client_id)` [VERIFIED: database/migrations/2026_10_17_000200_create_invoices_tables.php:99].
**Warning signs:** `DeletePaymentTest` for a cash payment hits SQLSTATE 23503 or 23514 after cancel.

### Pitfall 2: `DeletePayment` order and the sanctioned flag
**What goes wrong:** The guard added to `DeletePayment` runs before the lock order is respected, or it deletes the PPD document files like the income receipts.
**How to avoid:** Keep lock order invoice -> payment -> (receipt) -> income. Add the refusal right after the payment is re-read under its lock: if a PPD with `cancelled_at IS NULL` exists, throw `ValidationException` key `payment` with a new `invoices.errors.payment_delete_cash_receipt`. Never delete the cancelled PPD or its stored PDF (it is the evidence). `PaymentDeletionPathTest` pins where `kokpit.payment_deletion` may appear; the new code does not need that literal.

### Pitfall 3: The 5-minute grace window versus D-08
**What goes wrong:** The documented correction flow of Phase 13 is "delete the mistaken payment within `GRACE_SECONDS = 300`, record the right one; only the corrected payment is mailed" [VERIFIED: QueuePaymentConfirmation.php:44 and class docblock]. With D-08 a cash payment cannot be deleted without first cancelling the PPD, so a mistaken cash payment now needs: cancel PPD (reason) -> delete payment -> re-record -> **new PPD number**. The first PPD number is burnt as "cancelled" and, if the confirmation was not yet sent, the cascade removes the confirmation row on payment deletion so no e-mail leaves for the cancelled one.
**How to avoid:** Accept and document it (it is the intent of D-08), make the UI say it ("Cancel the receipt first"), and test the sequence. Do not add a shortcut that deletes a PPD inside the grace window (violates "number never returned").

### Pitfall 4: Confirmation job and a cancelled/edited PPD
**What goes wrong:** The job runs 5 minutes later; the PPD may have been edited (PDF replaced), cancelled, or the confirmation recipient may be missing.
**How to avoid:** In the job, resolve the PPD at send time and under a row lock: attach the **stored current** PDF if the PPD exists and is not cancelled; if cancelled, send the confirmation without attachment (the payment still happened) or skip - planner decides, recommended: send without attachment so the client still learns of the payment. A `skipped` confirmation (client has no usable invoice e-mail) never sends, so that PPD stays open forever; that is acceptable and must be shown ("not sent yet") rather than hidden. Edit of a PPD while its confirmation is `sending` should be refused (mirror `IssuedDocumentRules` `email_in_flight`). `payment_confirmations_recipient_check` forces `recipient IS NULL` exactly when `state = 'skipped'`, so a cancel cannot mark an existing row `skipped`. [VERIFIED: 2026_10_25_000100_create_payment_confirmations_table.php]

### Pitfall 5: Cash document leaves behind a Stripe link or token behaviour
**What goes wrong:** Changing a document to `Cash` keeps `card_payment_enabled = true` or a live link.
**How to avoid:** In `DocumentPayment::issuedSwitch` and `forDraft` a Cash method returns `false` and an explicit `card_payment_enabled = true` with cash is a validation error (like the unavailable-card error); `UpdateIssuedDocument` already deactivates the live link on `method_changed` via `PaymentLinks::deactivateLive(..., PaymentLinkDeactivation::Replaced)` [VERIFIED: UpdateIssuedDocument.php:171,207-208] and `IssuedEditFinisher::paymentLink` opens a new link only when `card_payment_enabled` [VERIFIED: IssuedEditFinisher.php]. The pay token outlives a switch-off by design ("a printed pay URL keeps answering"), and `PayPageState` returns the *Preparing* outcome for a token whose link is not active: for a document that is now cash the existing "preparing" wording is slightly misleading; consider a dedicated outcome only if cheap (CONTEXT says "shows the existing information page", so no change is required).

### Pitfall 6: Currency and amount tie
**What goes wrong:** Admin edits the PPD amount/currency.
**How to avoid:** Not editable (D-10). The payment currency is already pinned to its document by `invoice_payments_invoice_currency_fk` and `IssuedDocumentRules::currencyBlocker` refuses a currency change once a payment exists [VERIFIED: IssuedDocumentRules.php currencyBlocker], so a PPD can never disagree with its invoice currency. To make the amount tie database-enforced, add `UNIQUE (id, currency, amount_minor)` on `invoice_payments` and use a composite FK from the PPD copy (optional hardening; otherwise a guard that freezes both columns after insert).

### Pitfall 7: Number series interplay and year choice
**What goes wrong:** The year token uses the payment date, so a back-dated payment (allowed, `paid_on` may be any past day) takes a number from an old year's counter, producing out-of-order numbers (`PPD20250031` issued after `PPD20260007`).
**How to avoid:** Allocate the number with the **issue day (today, Prague)**, not `paid_on`. Keep `received_on` (= `paid_on`) as a separate printed date. This matches the Act's two dates (creation, and date of the case if different). `DocumentIssuer` allocates at `noonOf($issueDate)`; use the same helper idea for "today".

### Pitfall 8: Counter lock held across the PDF render
`DocumentIssuer` allocates the number late and renders the PDF afterwards, holding the counter row lock until commit. The PPD must print its number, so render after allocation; a cash payment holds the `cash_receipt:<year>` counter during one Dompdf render. Acceptable for a single-admin tool; keep the order (payment, income, reconcile, number, row, PDF, confirmation) and never call Stripe or the network inside.

### Pitfall 9: Test/enumeration arch tests will fail until updated
New model `CashReceipt` must be added to `ModelDeclarationTest` list, `ActivityAllowlistTest` (`loggingModels()` expected list), `MorphMap::MAP` alias, `DocumentOwnerType` (+ `DocumentOwnerResolver` branch + `isShareable()` match + `lang/cs/enums.php` label), `PartnerSafeColumnsTest`, `CanaryRegistry`/`RouteWalkTest` for the new route, `KeysAndTimestampsTest`/`SchemaConventionsTest` (uuidv7 default). [VERIFIED: files exist under tests/Arch, tests/Isolation, tests/Feature/Schema; contents of the Isolation/Schema ones not read in full, so exact edits are for the planner to open]

## Code Examples

### Hook in `RecordPayment::recordLocked` (shape)
```php
// Source: design based on app/Domain/Invoicing/Actions/RecordPayment.php (recordLocked)
$payment->save();

if ($books) {
    $this->bookIncome($locked, $payment, $facts->amount, $facts->incomeDate, $facts->categoryId, $dateKey);
}

$this->paymentLinks->reconcile($locked);

// Phase 15, D-06: a cash payment, whatever the method of the document, issues its receipt in this transaction.
if ($facts->method === PaymentMethod::Cash) {
    $this->cashReceipts->handle($locked, $payment, $actor); // $actor is non-null here
}

$this->confirmations->handle($locked, $payment);
```
`$actor` non-null for cash is guaranteed by the existing `LogicException` branch (`$actor === null && $facts->method !== PaymentMethod::Card`). [VERIFIED: RecordPayment.php]

### Numbering setting and kind
```php
// Source: design based on app/Domain/Settings/Numbering/DocumentKind.php and NumberingSettings.php
case CashReceipt = 'cash_receipt';           // sequenceKind() === 'cash_receipt' fits SequenceAllocator::KEY_PATTERN
// defaultPattern(): self::CashReceipt => 'PPD{YYYY}{NNNN}',
// carriesVariableSymbol(): unchanged (Invoice || Proforma only) -> false, so letters are allowed in the pattern
```
Literal characters accepted by the grammar: `private const string LITERAL_PATTERN = '/^[A-Za-z0-9._\/-]$/D';` [VERIFIED: NumberPattern.php:49]; letters are refused only when `$kind->carriesVariableSymbol()` [VERIFIED: NumberPattern.php parse loop]. Scope key = `cash_receipt:YYYY` for a yearly pattern [VERIFIED: NumberPattern::scopeKey]. Add `cash_receipt_pattern` to `NumberingSettings` (`rules()`, `patternFor()` match arm), the settings migration, `SettingsPage.php`, and `lang/cs/kokpit.php` labels. `DocumentKind` has no `match` default for the label, so add `enums.document_kind.cash_receipt`.

### Table sketch (recommended shape)
```sql
-- Source: design; patterns from 2026_10_17_000200 / 2026_10_25_000100 migrations
CREATE TABLE cash_receipts (
  id uuid PRIMARY KEY DEFAULT uuidv7(),
  invoice_id uuid NOT NULL,
  client_id uuid NOT NULL,                       -- Partner scope, composite FK to invoices(id, client_id)
  invoice_payment_id uuid NULL,                  -- NULL only after cancel + payment deletion
  number varchar(32) NOT NULL,                   -- UNIQUE
  issued_on date NOT NULL,                       -- date of creation (Sec. 11(1)(d))
  received_on date NOT NULL,                     -- date of the case (Sec. 11(1)(e)); editable while open
  amount_minor bigint NOT NULL CHECK (amount_minor > 0),
  currency char(3) NOT NULL,
  purpose varchar(500) NOT NULL,                 -- e.g. "Payment of invoice <number>"
  language char(2) NOT NULL CHECK (language IN ('cs','en')),
  supplier_snapshot jsonb NOT NULL, customer_snapshot jsonb NOT NULL, appearance_snapshot jsonb NOT NULL,
  lock_state varchar(8) NOT NULL DEFAULT 'open' CHECK (lock_state IN ('open','locked')),
  cancelled_at timestamptz NULL, cancelled_by uuid NULL, cancel_reason varchar(500) NULL,
  pdf_document_id uuid NULL,                     -- FK documents ON DELETE SET NULL, like invoice_admin
  created_by uuid NOT NULL, created_at timestamptz, updated_at timestamptz,
  UNIQUE (invoice_payment_id),                   -- one PPD per payment (D-07)
  CHECK ((cancelled_at IS NULL) = (cancel_reason IS NULL)),
  CHECK (invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL)
);
-- FKs: (invoice_id, client_id) -> invoices(id, client_id); (invoice_payment_id, invoice_id) -> invoice_payments(id, invoice_id) ON DELETE SET NULL (invoice_payment_id)
-- Guard trigger: number, invoice_id, client_id, amount_minor, currency, issued_on, created_* always frozen;
-- open columns: received_on, purpose, language?, 3 snapshots, lock_state (sanctioned), pdf_document_id, updated_at;
-- cancelled_* settable once NULL -> value in either lock state, never reverted; row never deleted; TRUNCATE refused.
```
Whether `language` is editable: D-10 lists only date, purpose and the two snapshots; keep `language` frozen (it is the client's invoice language at issue).

### PDF payment box for a cash document
```php
// Source: design extending app/Domain/Invoicing/Pdf/InvoicePdfData.php::payment()
if ($issued->payment_method === DocumentPaymentMethod::Cash) {
    $amount = $issued->total()->format($issued->language->value);
    return [
        'qrDataUri' => null,
        'sentence' => self::sentence($t('cash_sentence', ['amount' => "\u{1}".$amount."\u{1}", 'due' => "\u{1}".$date($issued->due_date)."\u{1}"])),
        'facts' => [['key' => $t('payment_method'), 'value' => $t('cash_payment')], ['key' => $t('amount_due'), 'value' => $amount]],
    ];
}
```
Put it **before** the `$bank = $issued->bank_snapshot; if ($bank === null) return null;` fallthrough [VERIFIED: InvoicePdfData.php:267-300]. Add `cash_sentence`, `cash_payment` to `lang/cs/invoice_pdf.php` and `lang/en/invoice_pdf.php`, and `with_due_date_cash` to `invoice_mail` (cs and en); `QueueInvoiceEmail::defaultBody` match gets a `Cash` arm [VERIFIED: QueueInvoiceEmail.php:150-154]. Also the variable symbol is still stored on the invoice row (the CHECK requires it for issued invoice/proforma) but is not printed.

## Legal Findings: what a cash receipt document (PPD) is

| Question | Finding | Source / confidence |
|----------|---------|---------------------|
| What is it? | Accounting document of the **receiving** entity recording cash taken into the cash desk. The payer gets a copy/tear-off (stvrzenka) as proof of payment. | Secondary practice sources [CITED: skrblik.cz/vzory-zdarma/prijmovy-pokladni-doklad-a-stvrzenka] MEDIUM |
| Is a form prescribed? | No form or number format is prescribed for the PPD itself; it is an "ucetni doklad" and Sec. 11 sets the general content. | [CITED: zakonyprolidi.cz/cs/1991-563 Sec. 11] MEDIUM (statute read via summary of the page, not quoted) |
| Required content (Sec. 11(1)) | (a) designation of the document, (b) content of the accounting case and its participants, (c) money amount (or unit price and quantity), (d) time the document was created, (e) time the case occurred if different, (f) signature records of the person responsible for the case and the person responsible for posting it. Elements may be spread over several records, one signature may cover several documents (with a linking identifier). Documents are to be made without undue delay. | [CITED: zakonyprolidi.cz/cs/1991-563 Sec. 11] MEDIUM |
| Electronic form allowed? | Yes: records may be paper, technical (electronic) or mixed with the same effect (Sec. 33(2),(6)). A "signature record" is a handwritten signature, a recognised electronic signature **or a similar technical record that guarantees provable and unambiguous origin** (Sec. 33a(4)). | [CITED: zakonyprolidi.cz/cs/1991-563 Sec. 33, 33a] MEDIUM |
| Numbering | Practice: a continuous, uninterrupted series proves completeness of the cash records; no statutory pattern. Cancelled forms stay in the series. | [CITED: skrblik.cz, secondary]; the "cancelled stays" detail is [ASSUMED] (see A4) |
| Who must issue one? | The Act applies to accounting entities. A sole trader in tax records (danova evidence) is not bound by Act 563 as such, but a receipt is the normal proof of a cash income and the iDoklad guidance states the PPD is the "document of receipt" for cash in tax records. Kokpit serves both, so issue always (D-06). | [CITED: idoklad.cz secondary] LOW-MEDIUM; see A1 |
| VAT relevance | The PPD is **not** a tax document. For a VAT payer, an advance payment received before the supply needs a tax document within 15 days (Sec. 28 VAT Act); a simplified tax document is allowed up to 10,000 CZK. Kokpit is non-payer only in this milestone, so no VAT fields are needed on the PPD. If payer mode arrives, an advance cash payment on a proforma needs a tax document for a received payment, separate from the PPD. | [CITED: portal.pohoda.cz/.../ucetni-a-danove-doklady] MEDIUM; Kokpit non-payer limit [VERIFIED: app/Domain/Settings/VatMode.php docblock] |
| Cash limit | Act 254/2004: payments above 270,000 CZK between the same payer and payee on one calendar day must be non-cash; the recipient may not accept more; fines to 500,000 / 5,000,000 CZK. Last amendment found: 183/2017. | [CITED: money.cz, muj-pravnik.cz secondary] MEDIUM |
| EET 2.0 | "Evidence of sales" bill passed the Chamber 15 Jul 2026, Senate amendments overridden 9 Sep 2026, awaiting the President; planned start 1 Jan 2027. Covers payments made in personal contact (cash, card, QR), says bank transfers and invoice payments are not recorded. Whether a cash payment *of an invoice* is in scope was **not settled** by the sources. Small flat-tax traders may get an exemption regime. | [CITED: dauc.cz/aktuality/2093, businessinfo.cz via search] LOW-MEDIUM |

### Recommended PPD content (derived from Sec. 11; every item from snapshots)
1. Title "Pokladni prijmovy doklad" / "Cash receipt" and the **number** (designation).
2. **Issue date** (`issued_on`) and **date of receipt** (`received_on`), both printed.
3. **Received from** (customer snapshot: name, address, company ID, VAT ID if any) and **received by** (supplier snapshot incl. company ID).
4. **Amount** in figures and currency (format via `Money::format`), optionally amount in words (practice; LOW priority, skip unless owner asks: Czech number-to-words for all currencies is hand-rolled work).
5. **Purpose** (free text, default "Payment of invoice <number>" / "Payment of proforma invoice <number>", localized) and the referenced document number.
6. **Issuer line**: "Issued electronically by <supplier name>"; a signature box image from the existing appearance snapshot `signature` (already used by invoices) as the signature record; optionally a "Received by" line for the payer. See A3.
7. Cancelled PPD: a clear "STORNO / Cancelled <date>" band; number and content retained.
8. No VAT breakdown, no variable symbol, no QR, no bank data, no pay button.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Paper PPD in numbered pad, two signatures | Electronic accounting record, "similar technical record" as signature record | Act 563/1991 Sec. 33, 33a (long-standing, current text read 2026) | An e-mailed PDF generated by the system is acceptable; owner confirms (A3) |
| EET (2016-2023, abolished) | EET 2.0 planned from 1 Jan 2027 | Chamber override 9 Sep 2026 | Possible later phase for cash received in person; not this phase |
| Payment method only transfer/online (Phase 14) | + cash | This phase | Branches on the method must be generalised |

**Deprecated/outdated:** nothing in the stack. The abolished first EET must not be used as a model.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | A freelancer in tax records is not legally forced to issue a PPD for cash income, but issuing one is the normal proof; Kokpit therefore issues one for every cash payment | Legal Findings | Low: issuing extra is harmless; if issuing were forbidden or required to differ, the PDF content changes |
| A2 | Use two dates: `issued_on` (today, Prague) drives the number year; `received_on` (= payment date, editable while open) is the date of the case. D-10's single "receipt date" is read as `received_on` | Pitfall 7, table | If owner wants the year from the payment date, numbers can go out of chronological order |
| A3 | A system-generated PDF with the issuer name, the supplier's signature image and the "issued electronically" line is an adequate signature record ("similar technical record", Sec. 33a(4)); no handwritten signature of the payer is needed | PPD content | If the owner/accountant wants a payer signature line, add an empty "Received by / date" box (cheap) |
| A4 | A cancelled PPD is kept with its number and marked "cancelled"; it is not deleted | PPD cancel | Matches D-08 as written; only the Czech accounting-practice wording is assumed |
| A5 | Default pattern `PPD{YYYY}{NNNN}` yearly reset, no letters problem because `carriesVariableSymbol()` is false | Numbering | Owner may prefer another pattern; changeable in settings |
| A6 | No backfill: cash payments recorded before this phase get no PPD | Runtime State Inventory | If the owner wants PPDs for history, a one-off command is needed (not planned) |
| A7 | The Partner may also see a **cancelled** PPD of their own invoice, marked cancelled, without the cancel reason | Partner section | If hidden, a Partner who already received the e-mail cannot find the document |
| A8 | Amount in words is not required | PPD content | Czech practice often includes it; adding it is extra scope |
| A9 | EET 2.0 will become law on the stated schedule and does not change PPD content | Legal Findings | Out of scope here; revisit if the President vetoes or scope text differs |
| A10 | A confirmation whose client has no usable invoice e-mail (`skipped`) leaves the PPD open (never locked) | Pitfall 4 | Owner may expect a manual "mark as handed over" lock; not in D-12 |

## Open Questions

1. **Cancelled PPD with a still-recorded payment.**
   - Known: D-08 lets the Admin cancel a PPD first and delete the payment second. Between the two steps a cash payment exists without a valid PPD, contradicting D-06's invariant.
   - Unclear: should the cancel action offer "cancel receipt and delete payment" as one step, or leave the intermediate state visible?
   - Recommendation: keep two steps as decided, show a warning badge "payment without a valid receipt" on the payment row, and add the combined action only if the owner asks.
2. **Historic cash payments.** Recommendation: no backfill (A6). Confirm.
3. **Confirmation e-mail for a proforma payment.** `QueuePaymentConfirmation` already queues one for proforma payments (the docblock notes A-13-1 as the switch). A cash proforma payment therefore mails its PPD too. Confirm this is wanted (it follows from D-06 and D-12).
4. **Cancelled PPD and the confirmation not yet sent** (grace window): recommended behaviour is to send the confirmation without attachment (Pitfall 4). Confirm.
5. **Admin "regenerate PDF" when the stored PPD PDF was deleted.** Mirror `RegeneratePdf` for PPDs or rely on edit-save. Recommendation: small `RegenerateCashReceiptPdf` reusing `CashReceiptPdf`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | All tests/migrations (host has no DB) | yes | v1.25.5 [VERIFIED: `ddev describe`] | none needed |
| PHP / Composer deps | Everything | inside DDEV | PHP ^8.5, Laravel ^13.17 per composer.json | - |
| PostgreSQL | Migrations, `ON DELETE SET NULL (col)` column-list form | inside DDEV | project needs `uuidv7()` (PG 18) so the column-list FK syntax is available [ASSUMED: server version not queried this session] | If the syntax is unavailable, use a trigger on `invoice_payments` delete instead |
| S3-compatible bucket | PDF storage | fake disk in tests | - | existing test setup |

**Missing dependencies with no fallback:** none found. Run everything with `ddev exec` (user memory: host has no DB).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest ^5.3 [VERIFIED: composer.json] |
| Config file | `phpunit.xml`/`tests/Pest.php` (existing); CI shards use `tests/.pest/shards.json` (timings file, new test classes may need to be picked up by the shard script) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptTest.php` |
| Full suite command | `ddev exec composer test` (= `vendor/bin/pest --exclude-testsuite=Audit`), plus `composer partner-audit`, `composer lint`, `composer stan` [VERIFIED: composer.json scripts] |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| P15-01 | Cash accepted in draft/issued edit; credit note refuses a method; CHECK rejects other values | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/DocumentPaymentTest.php` (extend) | extend existing |
| P15-02 | Cash PDF/e-mail/detail print no bank data; DB CHECK `(payment_method IN ('online','cash')) = (bank_snapshot IS NULL)` | feature + schema | `... InvoicePdfVariantsTest.php`, `InvoiceLockGuardTest.php` (extend) | extend existing |
| P15-03/04 | Switch off+locked, link deactivated on change to cash, no link at issue | feature | `... tests/Feature/Payments/LinkLifecycleTest.php` (extend) | extend existing |
| P15-05 | Cash payment on invoice and on proforma issues one PPD in the same transaction; transfer payment on cash doc issues none; carry issues none; rollback frees the number | feature + concurrency | `... tests/Feature/Invoicing/CashReceiptIssueTest.php`, `tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` | no, Wave 0 |
| P15-06 | Pattern validation, preview, gap-free under parallel creation | unit + concurrency | `... tests/Unit/Numbering/NumberPatternTest.php` (extend), `tests/Feature/Operations/NumberingTest.php` | extend existing |
| P15-07 | Edit while open; locked after confirmation send; unlock with reason; frozen columns refused with KP001 | schema + feature | `... tests/Feature/Schema/CashReceiptsTableTest.php`, `CashReceiptLockTest.php` | no, Wave 0 |
| P15-08/09 | PDF content from snapshots; attached to the confirmation mail; skipped client sends nothing | feature | `... PaymentConfirmationMailTest.php` (extend), `CashReceiptPdfTest.php` | partly |
| P15-10 | DeletePayment refuses until PPD cancelled; then deletes; cancelled row survives; raw SQL delete of active PPD payment fails | feature + schema | `... DeletePaymentActionTest.php` (extend) | extend existing |
| P15-11 | Partner sees only own PPDs, no foreign/other-client id (404), no payment/rate leakage | isolation | `ddev exec vendor/bin/pest tests/Isolation` + audit suite | extend |
| P15-12 | Cancel with reason, STORNO PDF, audit rows, no activity-log leaks of reason text | feature + arch | `... ActivityAllowlistTest.php` | extend |

### Sampling Rate
- **Per task commit:** the single new/extended test file for the task via `ddev exec`.
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Schema tests/Arch tests/Isolation`.
- **Phase gate:** full `composer ci` green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Schema/CashReceiptsTableTest.php` - constraints, guard, FK behaviours (P15-05..10)
- [ ] `tests/Feature/Invoicing/CashReceiptIssueTest.php`, `CashReceiptLockTest.php`, `CashReceiptPdfTest.php`, `CashReceiptCancelTest.php`
- [ ] `tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` (pattern of `InvoiceIssueConcurrencyTest`)
- [ ] `tests/Arch/CashReceiptUnlockPathTest.php` (literal kept out of other files)
- [ ] Fixtures in `tests/Support/InvoiceFixtures.php` for a cash payment (fictional data only, `example.com` addresses, company ID `12345678`)
- [ ] Update enumerating tests: `ModelDeclarationTest`, `ActivityAllowlistTest`, `MorphMapTest`, `PartnerSafeColumnsTest`, `RouteWalkTest`, `InvoiceEnumsTest`, `NumberingPageTest`

## Security Domain

### Applicable ASVS Categories (level 1, `security_enforcement` enabled)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no change | existing panel auth + 2FA middleware on routes |
| V3 Session Management | no change | existing |
| V4 Access Control | **yes** | `CashReceiptPolicy` on `KokpitPolicy`, `PartnerIsolated` global scope (own client, issued, client not archived), signed route bound to the requesting user id, Admin-only edit/cancel/unlock (`Gate::authorize('update', ...)`) |
| V5 Input Validation | yes | Validate purpose (<=500), cancel/unlock reason (10..500 like `UnlockInvoice::REASON_MIN/MAX`), dates as Prague days; re-validate server-side in Actions; Blade `{{ }}` escaping only in the PDF view |
| V6 Cryptography | no | signed URLs via Laravel `URL::temporarySignedRoute` (existing); no new crypto |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Partner reads another client's PPD / guesses ids | Information disclosure | Scoped query -> 404, policy `view`, ids under signature; same fixed check order as `InvoicePdfController` |
| Partner learns payment, rate or finance data through the PPD route/model | Information disclosure | Partner-readable table holds only what the PPD prints; no cancel reason, no created_by, no internal notes; list columns pinned in `PartnerSafeColumnsTest`; do not expose `invoice_payments` |
| Storage path or bucket URL leaks | Information disclosure | Stream through the controller, `nosniff`, `private, no-store` (copy headers from `InvoicePdfController`) |
| Edit/unlock/cancel of PPD by raw SQL or bypassing the Action | Tampering | DB guard trigger with sanctioned transaction-local setting; arch test pins the literal |
| Number reuse / gap under concurrency | Tampering / Repudiation | `SequenceAllocator` row lock in the payment transaction; unique index on `number`; no delete, no truncate |
| Template/markup injection through purpose or snapshot text | Tampering | Blade escaping, Dompdf closed (remote/PHP/JS off); mail attachment is the stored PDF, text in mail escaped with `MailText::escapeMarkdown` |
| Cancel reason or purpose text leaking into the activity log | Information disclosure | Allowlist only ids, number, dates, amount, currency, state, lock_state; never `purpose` or `cancel_reason` text (same rule as the payment note, T-10-22) |
| Sensitive data in repo (fixtures, docs) | Information disclosure | Fictional data only; run `scripts/check-sensitive.sh` before commit (project rule) |

## Sources

### Primary (HIGH/MEDIUM confidence)
- Codebase files read this session: `app/Domain/Invoicing/Actions/{RecordPayment,DeletePayment,QueuePaymentConfirmation,UnlockInvoice,UpdateIssuedDocument(grep),ConvertProforma(grep),RegeneratePdf,ReplaceIssuedPdf,QueueInvoiceEmail}.php`, `Jobs/SendPaymentConfirmation.php`, `Mail/PaymentConfirmationMail.php`, `Models/{Invoice,InvoicePayment,PaymentConfirmation}.php`, `Support/{DocumentPayment,IssuedEditFinisher,IssuedDocumentRules,InvoiceSnapshot,InvoicePdfUrl,PaymentInput}.php`, `Pdf/{InvoicePdf,InvoicePdfData}.php`, `Issuing/DocumentIssuer.php`, `app/Domain/Settings/Numbering/*`, `Settings/NumberingSettings.php`, `Shared/Sequences/SequenceAllocator.php`, `Payments/Support/{CardPaymentSwitch,PaymentLinks,PayUrl}.php`, `Payments/Queries/PayPageState.php`, `Documents/*`, `Http/Controllers/InvoicePdfController.php`, migrations `2026_10_17_000200`, `000300`, `2026_10_22_000100`, `2026_10_24_000200`, `000300`, `2026_10_25_000100`
- [Act 563/1991 Coll. on accounting, Sec. 11, 33, 33a](https://www.zakonyprolidi.cz/cs/1991-563) - content of an accounting document, forms of records, signature record (summarised from the page)

### Secondary (MEDIUM confidence)
- [Skrblik: prijmovy pokladni doklad a stvrzenka](https://www.skrblik.cz/vzory-zdarma/prijmovy-pokladni-doklad-a-stvrzenka) - content, numbering practice, PPD vs stvrzenka
- [iDoklad guidance](https://www.idoklad.cz/?p=6699) and [iDoklad](https://www.idoklad.cz/?p=4440) - cash receipt in tax records, VAT payer 10,000 CZK note
- [Pohoda portal: ucetni a danove doklady](https://portal.pohoda.cz/dane-ucetnictvi-mzdy/dph/ucetni-a-danove-doklady/) - tax document for received payment, 15 days, simplified document limit
- [money.cz: omezeni plateb v hotovosti a navrh EET od roku 2027](https://money.cz/novinky-a-tipy/dane/omezeni-plateb-v-hotovosti-a-navrh-eet-od-roku-2027/) - 270,000 CZK limit, EET bill
- [dauc.cz: Snemovna prehlasovala Senat, EET 2.0](https://www.dauc.cz/aktuality/2093/snemovna-prehlasovala-senat-a-stvrdila-zavedeni-eet-2-0-jeji-fungovani-ministerstvo-vyrazi-vysvetlovat-podnikatelum-na-roadshow-po-cesku) - EET 2.0 scope and status

### Tertiary (LOW confidence)
- Austrian advisory leaflet found in search (LENA) on receipt content - Austrian practice, not used for Czech requirements.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - no new packages; everything exists and was read.
- Architecture: HIGH for codebase mechanics (read this session); MEDIUM for the exact guard-trigger/FK design (new, untested here).
- Legal content of the PPD: MEDIUM - Sec. 11/33/33a read from the statute page; everything about practice, numbering and obligations is from secondary sources.
- EET 2.0: LOW-MEDIUM - bill status changes quickly.
- Pitfalls: HIGH for those derived from code; MEDIUM for the test-enumeration list (the Isolation/Schema test files were not opened in full).

**Research date:** 2026-10-27
**Valid until:** 2026-11-26 for codebase facts (the repo moves with Phase 16 in parallel); legal/EET status 2 weeks - recheck whether the President signed EET 2.0 before planning any follow-up.

## Project Constraints (from CLAUDE.md)

- Public AGPL repository: **fictional data only** - no client names, prices, rates, real e-mails, company IDs, bank accounts, hostnames, tokens or personal paths in code, tests, fixtures, docs, `.planning/`, commit messages. Use `example.com` addresses, company ID `12345678`, paths like `/Users/example/`; assemble test fakes from fragments at runtime.
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`; lefthook pre-commit runs the checker and gitleaks; never `--no-verify` or `LEFTHOOK=0`.
- Stack: PHP 8.5, Laravel, Filament (SPA mode), PostgreSQL, S3-compatible private bucket, Sanctum; no paid/closed packages; AGPL-compatible dependencies only (`composer check-licenses`).
- Security: a Partner must never see measured time, rates, prices or finance, nor another client's data - enforced by Policies and global query scopes, not UI hiding.
- Data integrity: UUID v7 keys everywhere; FK, unique, partial indexes and CHECK constraints enforced in the DB.
- Concurrency: number sequences must be gap-free and duplicate-free under concurrent creation.
- Immutability: issued documents editable until a successful e-mail send locks them; Admin may unlock with a recorded reason; number and kind never change; snapshots for supplier, customer, rates.
- Work only through a GSD workflow before editing files; tests run through `ddev exec` (host has no DB); `.planning/` content in English; Czech UI text in `lang/cs`.
