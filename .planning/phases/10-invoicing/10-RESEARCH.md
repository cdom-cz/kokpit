# Phase 10: Invoicing - Research

**Researched:** 2026-10-16
**Domain:** Czech-market invoicing on Laravel 13 / Filament 5 / PostgreSQL 18: bill-from-time drafts, gap-free numbered immutable documents, Dompdf PDF with SPAYD QR, queued e-mail, manual payments with income transactions, derived overdue, Partner read-only view
**Confidence:** HIGH on the codebase seams and the PDF/QR/PostgreSQL mechanics (read or executed this session); MEDIUM on Czech legal field lists (secondary sources, see A1); several design choices are the planner's and are listed under Open Questions with a recommendation each.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): Dompdf 3.1 is the PDF engine (Phase 3 spike); money is bigint minor units plus ISO 4217, `HALF_UP` rounding in exactly one method, once per invoice line amount (Phase 2 D-09); gap-free numbering via `SequenceAllocator` with scope key such as `invoice:2026` (Phase 2 D-12) and editable number patterns per document kind (Phase 3 D-05); one bank account per currency (Phase 3 D-04); client currency, payment terms and invoice language are stored on the client (Phase 4 D-13); time billing state `billing_state` with a DB guard trigger (Phase 6); `ExchangeRateConverter` with CNB rates (Phase 8); work report PDF without prices (Phase 8 D-10); `documents` table, shared "Soubory" relation manager, confirmation for deleting issued invoice PDFs deferred to this phase, system income category (Phase 9 D-01, D-09, D-16); Partner never sees time, rates, prices-of-time or finance beyond own issued documents, enforced by Policies and scopes.

**Draft from time and items (IN-02, IN-10)**
- **D-01:** A draft belongs to exactly one client; the invoice currency defaults to the client's currency and Admin can override it in the draft. The bank account is picked by the invoice currency.
- **D-02:** When creating a draft from time, Admin chooses client, period, projects and the grouping, either per project or per task (chosen per draft). One time item = one group, hours from exact seconds.
- **D-03:** Time entries are linked directly to the invoice (draft), **not** to individual invoice items. Items are generated from the linked entries once and are then freely editable (quantity, unit, price, text, delete, add); editing or deleting an item never changes which entries are linked. — **Reversibility:** costly — the schema, the billing lock and the report "unbilled" queries all depend on the entry-to-invoice link instead of an entry-to-item link.
- **D-04:** Entries are reserved the moment the draft is created: the entry carries the draft's id (unique in the DB, so two drafts cannot hold the same entry), and the picker for other drafts excludes reserved entries. `billing_state = billed` and the existing billing lock apply at issue (storno and cancel release them). Exactly how reserved-but-unbilled entries are counted in the Phase 6/8 unbilled views is the planner's call but must be explicit and tested.
- **D-05:** Admin releases entries from a draft with an "unlink entries from invoice" action (select entries, they return to unbilled). Deleting or cancelling a whole draft releases all entries (IN-10).
- **D-06:** Entries added to a draft later (newly tracked time) are linked and generate new items only; items edited by hand are never overwritten.
- **D-07:** Fixed-price projects get no per-entry amount (Phase 8 D-07): Admin adds a "fixed amount" item manually (whole price or a part) while creating the draft; optionally marks the project's time as billed with it.
- **D-08:** Manual items are lines with description, quantity, unit and unit price. In non-VAT-payer mode (the default) no VAT is shown; the item has a VAT-rate field in the model that is unused in v1 (IN-08).
- **D-09:** When the entry's rate currency differs from the invoice currency, the unit price is converted with the CNB rate valid on the issue date, rounded once per line (`HALF_UP`), and the rate is stored in the snapshot. The draft shows a preview using the current rate; the final value is computed at issue. — **Reversibility:** one-way — issued amounts and the stored rate snapshot cannot be recomputed later.

**Issue, numbering and snapshots (IN-01, IN-03, IN-07, IN-08)**
- **D-10:** Invoice, proforma and credit note have separate series and the number is allocated only at issue, inside the same DB transaction as the snapshots and the billing lock; if anything fails the number is not consumed (series stays gap-free). PDF generation happens in the same flow (see D-24); e-mail goes to the queue after commit.
- **D-11:** Issue date defaults to today (Europe/Prague), taxable-supply date and due date are pre-filled (due = issue date plus the client's payment terms), editable in the draft, frozen at issue.
- **D-12:** The issue snapshot freezes supplier, customer and bank account, all items as text copies, rates, line and total amounts, invoice language, the CNB rate with date and the CZK total for client-currency invoices.
- **D-13:** Immutability after issue is enforced by DB guard triggers (the pattern of `Immutability::guardTriggerSql` used for `time_entries`) on invoices, items and snapshot data; only the payment state and the internal note stay writable, also against raw SQL UPDATE and DELETE. — **Reversibility:** one-way — relaxing a guard on issued financial data would need a migration and breaks the immutability promise.

**Proforma, credit note, cancellation (IN-06, IN-10)**
- **D-14:** An issued invoice is never edited or deleted. "Storno" issues a full credit note (own series) and marks the invoice cancelled; a partial credit note on chosen items is also allowed and keeps the time billed. Only a full storno returns the linked time to unbilled and unlocked.
- **D-15:** A proforma may be built from time and reserves it like a draft. When a paid proforma becomes an invoice ("Issue invoice" action on a paid proforma), the new invoice gets its own number from the invoice series, copies the items, keeps a link to the proforma, and the time entries move from the proforma to the invoice (stay billed, never released in between).
- **D-16:** Cancelling a paid invoice by credit note does not touch the existing payment or income; the system shows a warning and Admin returns the money and records the expense manually (no refund automation).

**Payments, overdue, income (IN-05, FI-03)**
- **D-17:** An invoice can have several (partial) manual payments (transfer or cash) in a `payments` table; the sum decides unpaid / partially paid / paid. Entering a payment on an already paid invoice, or above the remaining amount, shows a confirmation warning; Admin can confirm and save it anyway.
- **D-18:** Income is created from **invoices only**, never from a proforma. A payment recorded on a proforma is stored as a payment only; when the invoice is issued from that proforma the payment is carried to the invoice and the income transaction is created then, dated with the real payment date and in the system income category. A paid proforma with no invoice issued yet shows as an attention item (invoice list and dashboard) so money never sits outside the finance overview unnoticed. — **Reversibility:** costly — payment, proforma conversion and finance reports depend on the moment income is created.
- **D-19:** A manual invoice payment creates an income transaction linked to the payment and the invoice (the invoice reference on transactions, FI-01 deferred to this phase). The date defaults to the payment date and the category to the system income category, both editable when entering the payment; amount, currency and invoice link are locked afterwards (Phase 9 D-14).
- **D-20:** Overdue is derived, never stored: issued, not fully paid and due date earlier than today (Europe/Prague); partially paid invoices count by their remaining amount. There is an overdue overview and a dashboard tile (count and amount in CZK), wired into the Phase 8 dashboard.

**PDF, QR and e-mail (IN-04)**
- **D-21:** The PDF is generated from the snapshots only, in the client's invoice language (cs/en), with a SPAYD QR code for CZK bank transfer, and is stored in `documents` attached to the invoice. Deleting an issued invoice PDF needs an explicit confirmation (DO-03, proved in this phase).
- **D-22:** After issue a dialog offers the e-mail with the recipient pre-filled from the client's invoice e-mail, subject and text, and a checkbox to attach the work report (Phase 8 PDF). Sending runs in a queued idempotent job; failures appear in the "unsent invoice e-mails" indicator on the System page and can be re-sent.
- **D-23:** The PDF is generated synchronously at issue; if generation fails, the whole issue is rolled back (no number consumed). Regenerating the PDF later uses the same snapshots and must produce an equivalent document.
- **D-24:** Invoice design follows the cdom tools invoice PDF (`src/tools/invoice/invoice-pdf.tsx` in the public repository `cdom-cz/tools`), re-implemented in HTML/CSS for Dompdf with brand colour `#ff6f00`: a 7 pt accent stripe at the top and bottom, header with the supplier logo on the left and the document heading (small, letter-spaced, accent text colour) with a large bold number on the right over a dark bottom rule, two party blocks side by side (supplier with an accent left border, customer with a dark one), a three-cell date strip (issue, taxable supply when VAT payer, due), an items table with a dark header row, alternating row tint, index, description, quantity with unit, unit price and total, a right-aligned summary with a solid accent total bar ("to pay"), a payment box with a tinted background and accent top border containing the QR, bank details, variable symbol and due date, the supplier footer text, an optional signature image, "page/total" numbering and a Roboto font family (Czech diacritics). Colours are derived from the single accent: a darkened accent text colour when the contrast on white is below 3.5, white or near-black text on the accent chosen by contrast, a very light accent tint for the payment box and a neutral row tint. The accent colour is a typed setting (default `#ff6f00`), not hard-coded; the fictional-data rule applies to every demo value.

### Claude's Discretion

Class, file, table and index names; how the entry-to-invoice link and the "reserved" state are modelled (column, trigger, partial unique index); which exact statuses exist (draft, issued, partially paid, paid, cancelled) and where they are stored versus derived; VAT-ready column shapes; e-mail wording in cs/en; the invoice number pattern defaults; variable symbol derivation; whether PDF font files are bundled from the public repository's Roboto or from a package (AGPL-compatible licence check); the exact Partner view of issued documents (list, detail, PDF, read-only, no drafts, internal note, linked time or rates, as in IN-09 and roadmap criterion 5) and the client detail panels (CL-03: projects, unbilled time and amount, invoices, documents, client accounts), which were not discussed and follow the existing isolation rules; presentation of the overdue overview and the "paid proforma without invoice" attention item.

### Deferred Ideas (OUT OF SCOPE)

- Online payment (Stripe link, pay button behaviour, webhook matching) — Phase 11; this phase only leaves the fields and placement ready.
- VAT-payer calculations and PDF template — later, data model prepared.
- Automatic refund and payment reversal on storno — out of scope (handled manually).

Not in this phase (CONTEXT domain): Stripe Payment Links, webhooks and automatic matching, VAT-payer calculations and template (data model prepared, logic deferred), refunds and bank statement matching, the Partner-audit matrix (Phase 12). Reviewed todo not folded: "Redesign unreadable time report layout".
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| IN-01 | Invoices, proformas and credit notes with separate number series, number allocated only on issue | `DocumentNumbering::next()` over `SequenceAllocator` inside the issue transaction; `UNIQUE (kind, number)`; Pattern 3 and the issue concurrency test (Validation) |
| IN-02 | Draft from unbilled billable time (client, period, projects, grouping, fixed sums) plus manual items; entries reserved so two drafts cannot bill the same time | `time_entries.invoice_id` column + compare-and-set claim; composite FK to the client; grouping rules; Pattern 2, Pitfalls 1 and 5 |
| IN-03 | Issue freezes supplier, customer, bank snapshots, rate, amounts; immutable except payment state and internal note, in the database | Guard triggers (frozen invoice, child-of-frozen-parent, payments, `time_entries` re-created); Pattern 4, Pitfalls 2 and 3 |
| IN-04 | PDF at issue from snapshots only, stored in documents, SPAYD QR for CZK, client's language; queued e-mail with optional work report | Roboto TTF + Dompdf options (fontDir!), `chillerlan/php-qrcode` 5.0.5, `AttachDocuments` with a generated file, e-mail log table and idempotent job; Patterns 9 and 10 |
| IN-05 | Manual payment (transfer, cash) in a payments table; warning if already paid; overdue derived; overdue overview | `invoice_payments` immutable table, derived remaining/overdue reader bound to the Prague day, list tab + tile; Pattern 6 |
| IN-06 | Proforma converts to invoice with a link after payment | Copy-with-link payment carry-over, `proforma_id` unique per invoice, entries re-pointed while staying billed; Pattern 7 |
| IN-07 | Invoicing in client currency with CNB rate snapshot and CZK total | `ExchangeRateConverter::rateFor()` at the issue date, new exact cross-currency `Money` method, Admin-only snapshot table; Pattern 5 |
| IN-08 | Non-VAT-payer mode default and fully tested; data model prepared for VAT payer | `vat_mode` frozen on the document, nullable `vat_rate`, zero `vat_minor`, no VAT rendering path in v1; Pattern 1 |
| IN-09 | Partner sees own issued invoices and proformas (list, detail, PDF) read-only, never drafts, internal notes, linked time or rates | Partner-safe table split, pinned columns test, dedicated invoice PDF route; Pattern 11, Open Question 5 |
| IN-10 | Cancelling or deleting a draft, or storno, returns linked entries to unbilled | `ReleaseEntries` primitive shared by draft delete, unlink, cancel and full storno; Pattern 8 |
| CL-03 | Client detail shows projects, unbilled time and amount, invoices, documents, client accounts | Two new relation managers + one stats widget on `ViewClient`; existing Soubory/Účty tabs; Pattern 12 |
| FI-03 | Payment of an invoice creates an income transaction (editable date and category for manual payment) | `RecordTransaction` called from `RecordPayment` in one transaction, system income category, `transactions.invoice_id` + `invoice_payment_id`; Pattern 6, Pitfall 9 |
</phase_requirements>

## Summary

Every foundation the phase needs already exists and was read this session: the gap-free `SequenceAllocator` (refuses to run outside a transaction, locks the counter row), the generic `kokpit_guard_frozen_row()` guard (state-column based, SQLSTATE `KP001`), `Money` (single `HALF_UP` point, Brick confined to the class by an arch test), `ExchangeRateConverter::rateFor()`, `WorkReportSource::fromEntryIds()` + `WorkReportPdf`, `AttachDocuments`/`DeleteDocument` (D-23 after-commit object removal), `RecordTransaction`/`TransactionCzk`, `HealthSlot::UnsentInvoiceEmails` (still a placeholder), `KokpitJob`/`Idempotent`, and the `#[AccessRule]`/`IsolatesPartners`/`DeniesPartners` isolation machinery. No new Composer package is needed: the QR generator `chillerlan/php-qrcode` 5.0.5 is already locked as a hard dependency of `filament/filament`, and Roboto is a font file, not a package.

The hard parts are not in the libraries but in six places where the existing seams do not fit as-is and the planner must decide before writing plans: (1) the generic guard cannot guard child tables (no state column) and cannot protect a "transition-only" column such as `time_entries.invoice_id`, so two new trigger functions are needed; (2) `Money` has no `times(quantity)`, no negate/compare and no exact cross-currency conversion (CZK to EUR needs an inverted rate that does not fit `convert()`'s 10-decimal rate), so `Money` must grow three methods, all funnelled through `fromExactMinor`; (3) `AttachDocuments` only accepts `UploadedFile` instances, and the PDF object it writes to S3 is not rolled back with the surrounding issue transaction (needs `DB::afterRollBack`); (4) `WorkReportPdf` sets only `fontCache`, but a bundled `@font-face` font installs into `fontDir`, which defaults to `vendor/dompdf/dompdf/lib/fonts`; (5) the Partner must never read internal notes, payments or CNB data, so the Partner-readable tables must hold Partner-safe columns only (the project's pinned-column pattern), and the Partner PDF download needs its own authorisation path because the `Document` Partner scope returns only project/task/comment files; (6) the UI-SPEC and CONTEXT disagree in two places (fixed-price entries, reserved-entry editing) that need an explicit ruling.

**Primary recommendation:** Build in this order: `Money` additions and the pure helpers (quantity, palette, SPAYD) first; then one migration set (invoices, items, Admin-only side tables, payments, e-mail log, `time_entries.invoice_id`, `transactions.invoice_*`) with all guards and CHECKs and its schema tests; then the domain Actions (draft, issue, payment, convert, credit) each as one transaction with a concurrency or raw-SQL proof; only then the Filament surfaces, the PDF template and the Partner resource. Keep the Filament layer thin: every write goes through an Action that re-validates (the project's established pattern).

## Project Constraints (from CLAUDE.md)

Extracted from `.claude/CLAUDE.md` (treated with the authority of locked decisions):

- **Stack fixed:** PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL, S3-compatible private bucket, Sanctum, queue. No paid or closed packages; every dependency AGPL-3.0 compatible (`composer check-licenses` is a CI gate).
- **Security:** Partner must never see measured time, rates, prices or finance, nor another client's data: enforced by Policies and global query scopes, not only UI hiding.
- **Data integrity:** UUID v7 keys everywhere including morph columns; FK, unique, partial indexes and check constraints enforced in the DB.
- **Concurrency:** number sequences must be gap-free and duplicate-free under concurrent creation.
- **Immutability:** issued invoices and billed time entries are immutable; snapshots for supplier, customer, rates, exchange rates.
- **Repository hygiene (public AGPL repo):** fictional data only. Use `example.com` addresses, placeholder company ID `12345678`, paths like `/Users/example/`, test fakes assembled at runtime from fragments. No client names, prices, rates, real e-mails, bank accounts, IPs, hostnames, tokens or personal paths in code, tests, fixtures, docs, `.planning/` or commit messages. Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`; never bypass lefthook (`--no-verify`, `LEFTHOOK=0`). Test IBANs and company numbers in tests come from runtime generators (see `tests/Support/FictionalCompanyId.php`), never a literal.
- **Workflow:** file-changing work goes through a GSD command. Everything under `.planning/` is written in English (user preference). Tests and static analysis run through `ddev exec` (the host has no database) (user memory).
- **Config:** `workflow.nyquist_validation` is `true`; `security_enforcement` is `true`, ASVS level 1, block on `high`; `test_command` is `ddev exec vendor/bin/pest` [VERIFIED: .planning/config.json].

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Reservation of time entries, no double billing | Database / Storage | API / Backend | A single-valued FK column plus a compare-and-set `UPDATE` makes duplication impossible for any writer; the Action only orchestrates |
| Number allocation, gap-free | Database / Storage | API / Backend | `number_sequences` row lock inside the issue transaction; `UNIQUE (kind, number)` as the backstop |
| Immutability of issued documents | Database / Storage | — | Triggers (`KP001`) hold against raw SQL, packages and console |
| Money arithmetic and rounding | API / Backend (`Money`) | Database (CHECK `round(quantity * price)`) | One PHP rounding point; the DB CHECK pins the same rule for every writer |
| CNB conversion at issue | API / Backend | Database (frozen snapshot columns) | `ExchangeRateConverter::rateFor()` once, stored, never looked up again (EX-03) |
| PDF + QR generation | API / Backend (sync, in the issue request) | Database / Storage (private bucket) | D-23: rollback on failure, so it cannot be queued |
| E-mail sending | API / Backend (queue job) | Database (send log) | Idempotent `KokpitJob`, state in a log table, health slot reads the table |
| Payment state, overdue, receivables | API / Backend (reader class) | Database | Derived, never stored; bound to the Prague day from PHP, not SQL `now()` |
| Partner visibility of issued documents | API / Backend (model scope + policy) | Database (Partner-safe tables) | Scope selects issued, own-client rows; Admin-only data lives in other tables |
| Draft editor / lists / modals | Frontend Server (Filament + Livewire) | — | Thin layer calling Actions; items state is an array handed to one Action |
| Dashboard tile, client stats, System slot | Frontend Server | API / Backend (readers) | Widgets render reader output; readers are Admin/system-only |
| Fonts, accent, logo, signature | Database (typed settings) / private storage | API / Backend | Settings classes validate at the data layer; images re-encoded before storage |

## Standard Stack

### Core (all already installed; no `composer require` is needed except pinning the QR package explicitly)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | 13.36.0 | App, queue, mail, `DB::afterRollBack` | [VERIFIED: vendor/composer/installed.json] |
| filament/filament | 5.10.2 | Admin and Partner panels; `Repeater::table()`, tabs, stats widgets | [VERIFIED: installed.json]; `Repeater::table()` at `vendor/filament/forms/src/Components/Repeater.php:1351` |
| dompdf/dompdf | 3.1.6 (2026-07-20) | PDF engine, locked by Phase 3 spike | [VERIFIED: installed.json; packagist.org API]; licence screened by the owner ruling 2026-10-14 |
| chillerlan/php-qrcode | 5.0.5 (MIT or Apache-2.0) | SPAYD QR as PNG data URI, and QR decode in tests | [VERIFIED: composer.lock; required by filament/filament `^5.0`]; 6.0.1 exists but Filament pins `^5.0`, stay on 5.x |
| brick/money, brick/math | 0.15.2, 1.0.0 | Only inside `App\Domain\Shared\Money` | [VERIFIED: composer.lock; tests/Arch/MoneyBoundaryTest.php] |
| spatie/laravel-settings | ^3.9 | Typed settings for accent, footer, logo, signature | [VERIFIED: composer.json] |
| spatie/laravel-activitylog | ^5.1 | Allowlisted history of invoices and payments | [VERIFIED: composer.json] |
| Pest | ^5.3 on PHPUnit ^13.3 | Tests | [VERIFIED: composer.json] |

### Supporting assets

| Asset | Version | Purpose | When to Use |
|-------|---------|---------|-------------|
| Roboto Regular and Bold, static TTF | Roboto 3.011 release, OFL-1.1 | PDF body font with Czech diacritics | Bundle under `resources/fonts/` with `OFL.txt`; the cdom tools repo ships only WOFF, which Dompdf cannot be assumed to read |
| Ghostscript `txtwrite` (DDEV 10.05.1) | system | Extract PDF text in tests | Czech diacritics and content assertions; `pdftotext` is absent from DDEV |

SHA-256 of the files used in the spike (record these when bundling): `Roboto-Regular.ttf` `8cc1240ac3e832e267e24c1fc84743f3c029848c06096fe3f1d58732f3e3c424`, `Roboto-Bold.ttf` `27b68c55603c07b34903227d7621f46d5ce6f8e294ad95421319b49197697391` (from `android/static/` of `Roboto_v3.011.zip`, GitHub release of `googlefonts/roboto-3-classic`; the zip itself carries no licence text, take `OFL.txt` from the repository) [CITED: github.com/googlefonts/roboto-3-classic; VERIFIED: sha256 computed this session].

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Roboto from the owner's repo (WOFF) | Roboto static TTF | WOFF is not a documented Dompdf format; TTF was proven to render in the spike |
| `chillerlan/php-qrcode` | `endroid/qr-code`, `bacon/bacon-qr-code` | A second QR package for no gain; the installed one passed an encode-decode roundtrip |
| `smalot/pdfparser` (dev) for PDF text tests | Ghostscript `txtwrite` | Parser is a new dev dependency; gs is already in DDEV (skip the test when `gs` is missing, see Open Question 10) |
| SQL view for payment state | Query scopes / reader class | The project has no views yet; a reader is testable and consistent with `TimeReportReader` |

**Installation:**
```bash
# Pin what is already a transitive dependency (no new code enters the lock file):
ddev composer require chillerlan/php-qrcode:^5.0.5
ddev composer check-licenses
```

**Version verification:** `chillerlan/php-qrcode` locked at 5.0.5 (published 2025-11-23; 6.0.1 published 2026-03-18 is not usable while `filament/filament` requires `^5.0`); `dompdf/dompdf` v3.1.6 is the newest release (2026-07-20) [VERIFIED: repo.packagist.org queries this session].

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| chillerlan/php-qrcode | Packagist | years (5.0.5 on 2025-11-23) | not queried | github.com/chillerlan/php-qrcode | n/a (see note) | Approved: already locked, hard dependency of `filament/filament`, MIT or Apache-2.0 both on the allowlist |

**Note:** `gsd-tools package-legitimacy check` supports only `npm`, `pypi` and `crates` (it printed its usage line for `packagist`), so the seam could not run for Composer. No new package enters the project, so the gate has nothing to add; if the planner adds any package, treat it `[ASSUMED]` and gate the install behind `checkpoint:human-verify`.
**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
 Admin (Filament, SPA)                                   Partner (same panel, read-only)
   |                                                        |
   |  "Nová faktura" modal / draft editor / actions         |  list, detail, "Stáhnout PDF"
   v                                                        v
 Livewire page / modal  --form state (arrays, ids)-->  Partner-scoped Invoice query (issued, own client)
   |  (re-checks Admin on every update)                     |   pinned Partner-safe columns only
   v                                                        v
 DOMAIN ACTIONS (one DB transaction each, Admin-only)    InvoicePdfController (signed, user-bound)
   CreateDraft ──> claim entries: UPDATE time_entries SET invoice_id=… WHERE invoice_id IS NULL AND unbilled AND finished AND billable
   UpdateDraft ──> replace items (server recomputes line totals), never touches the entry link
   ReleaseEntries / DeleteDraft
   IssueDocument ─┬─ lock invoice row ──> lock entries (id order) ──> CNB rateFor(issue date) ──> unit prices + totals
                  ├─ DocumentNumbering::next(kind, issue date)   [locks number_sequences row until commit]
                  ├─ write snapshots, state='issued'  (guard now freezes the row)
                  ├─ entries: billing_state='billed' (invoice_id already set)
                  ├─ PDF: Blade snapshot ──> Dompdf (Roboto, SPAYD QR PNG) ──> AttachDocuments (owner=invoice) ──> private bucket
                  └─ DB::afterRollBack(delete stored object)  ──> COMMIT
   RecordPayment ──> invoice_payments row ──> RecordTransaction (invoice only, system income category)
   ConvertProforma ──> new invoice from proforma items; entries re-pointed; payments copied + income booked
   IssueCreditNote (partial | full storno) ──> negative items; full storno ReleaseEntries + invoice.cancelled_at
   QueueInvoiceEmail ──> invoice_emails row 'queued' ──> afterCommit dispatch
                                                            v
                         SendInvoiceEmail (KokpitJob, idempotent) ──> Mail (PDF + optional work report) ──> row 'sent' | 'failed' + bell
   |
   v
 PostgreSQL 18: invoices, invoice_items, invoice_admin, invoice_item_sources, invoice_payments, invoice_emails,
                time_entries.invoice_id, transactions.invoice_id / invoice_payment_id,
                triggers: invoices frozen | items child guard | payments immutable | time_entries link guard

 Readers (Admin/system only, Prague day bound from PHP):  ReceivablesReader ──> list tabs, overdue summarizer, dashboard tile, client stats
 Health: HealthSlot::UnsentInvoiceEmails ──> UnsentInvoiceEmailsIndicator (reads invoice_emails)
```

### Recommended Project Structure

```
app/Domain/Invoicing/
├── Actions/            # CreateDraft, UpdateDraft, DeleteDraft, ReleaseEntries, AddEntriesToDraft, AddFixedAmount,
│                       # IssueDocument, IssueCreditNote, RecordPayment, DeletePayment (see OQ 7), ConvertProforma,
│                       # QueueInvoiceEmail, RegeneratePdf, UpdateInternalNote
├── Enums/              # InvoiceKind (invoice|proforma|credit_note), InvoiceState (draft|issued), PaymentMethod, EmailState
├── Jobs/               # SendInvoiceEmail
├── Models/             # Invoice, InvoiceItem, InvoiceAdmin, InvoiceItemSource, InvoicePayment, InvoiceEmail
├── Pdf/                # InvoicePdf (render), DocumentPalette (hex/HSL/contrast), Spayd, QrPng, InvoiceFonts, PageDecoration
├── Policies/           # InvoicePolicy (Admin through before(); Partner view only issued own-client)
├── Queries/            # ReceivablesReader, InvoiceableEntries (picker + grouping source)
├── Support/            # InvoiceQuantity, VariableSymbol, IssueChecks (pre-issue blockers), DraftTotals
app/Filament/Resources/InvoiceResource (+ Pages, RelationManagers: Payments, LinkedTime, Emails, DraftEntries; reuse History)
app/Filament/Partner/Resources/PartnerInvoiceResource
app/Filament/Widgets/UnpaidInvoices, app/Filament/Resources/ClientResource/{RelationManagers,Widgets}
app/Http/Controllers/InvoicePdfController.php
resources/views/pdf/invoice.blade.php, resources/fonts/{Roboto-Regular.ttf,Roboto-Bold.ttf,OFL.txt}
lang/{cs,en}/invoice_pdf.php, lang/{cs,en}/invoice_mail.php, lang/cs/invoices.php
database/migrations/2026_10_17_*  (see Pattern 1)
```

### Pattern 1: Partner-safe table split (schema)

**What:** The Partner reads `invoices` and `invoice_items` for issued rows, so those two tables hold only what is printed on the document. Everything else goes to Admin-only tables with `DeniesPartners`: `invoice_admin` (internal note, CNB snapshot and CZK total), `invoice_item_sources` (from-time flag, source hourly rate, manual-price flag), `invoice_payments`, `invoice_emails`. This is the project's existing rule for `projects` vs `project_billing`, pinned by a column-list test [VERIFIED: tests/Isolation/PartnerSafeColumnsTest.php:1-30, "Put Admin-only attributes ... into project_billing or another Admin-only table"].

Suggested `invoices` columns (names are working labels): `id` uuidv7, `client_id` (FK), `kind`, `state` (`draft` or `issued`), `number` (null while draft), `variable_symbol`, `currency`, `language` (`cs`/`en`), `vat_mode` (frozen), `issue_date`, `taxable_supply_date` (nullable), `due_date`, `subtotal_minor`, `vat_minor` (0), `total_minor`, `note` (printed), `supplier_snapshot`, `customer_snapshot`, `bank_snapshot` (jsonb, written at issue), `proforma_id` (source proforma of an invoice), `credited_invoice_id` (credit note to invoice), `cancelled_at`, `pdf_document_id`, `issued_at`, timestamps.

Constraints to put in the database (all `[ASSUMED]` shapes, to be proven by schema tests): `kind` and `state` CHECKs; `(state = 'draft') = (number IS NULL)`; issued rows require number, issue date, due date, snapshots; `UNIQUE (kind, number) WHERE number IS NOT NULL` (closes the Phase 3 open item "unique constraint on the issued number string" [VERIFIED: .planning/STATE.md 03-08 entry]); `UNIQUE (id, client_id)` as target of composite FKs so a time entry, a source proforma and a credited invoice can only point at a document of the same client (same device as `projects_id_client_unique`); `UNIQUE (proforma_id) WHERE kind = 'invoice'` (one invoice per proforma); sign check: credit note total below zero, invoice and proforma above zero once issued; `vat_minor = 0` while `vat_mode = 'non_payer'`.

Items: `quantity numeric(14,4)`, `unit_price_minor bigint`, `line_total_minor bigint` with
`CHECK (line_total_minor = round(quantity * unit_price_minor))` [ASSUMED shape; the rounding semantics were verified in PostgreSQL 18.6: `round(2.5)` = 3, `round(-2.5)` = -3, `round(1.3333::numeric * 100000::bigint)` = 133330, i.e. half away from zero, identical to `Money::fromExactMinor` `HALF_UP`]. `vat_rate numeric(5,2)` null, `vat_minor bigint default 0`, `credited_item_id` (credit line to original line).

**Do not add stored generated columns to any guarded table.** PostgreSQL computes them after the BEFORE trigger, so `NEW` holds NULL while `OLD` holds the value and the generic guard refuses every update (the Phase 6 finding behind `duration_seconds` in the mutable list) [VERIFIED: database/migrations/2026_10_11_000100_create_time_entries_table.php header comment].

### Pattern 2: Entry link, reservation and the billing lock

**What:** One nullable column `time_entries.invoice_id` is the whole reservation. Uniqueness ("two drafts cannot hold the same entry") is by construction because the column is single-valued. State meaning: `invoice_id` set and `billing_state = 'unbilled'` is reserved by a draft; `invoice_id` set and `billed` is billed by an issued document; `invoice_id` null and `billed` is the manual billing of Phase 6 (TI-05 stays valid).

**Claim, not select-then-update** (two drafts can be created at the same moment):
```sql
-- [ASSUMED] shape; run in the CreateDraft transaction, ids ordered to keep lock order stable
UPDATE time_entries SET invoice_id = :draft
WHERE id IN (:candidates) AND invoice_id IS NULL AND billing_state = 'unbilled'
  AND billable AND ended_at IS NOT NULL AND client_id = :client
RETURNING id;
```
Build the items only from the returned ids. A second draft racing for the same entries gets fewer rows back and simply shows fewer entries, never a duplicate.

Rules that fall out of existing code:
- Reserved entries stay `unbilled`, so every existing unbilled query (`TimeTotals`, `TimeReportReader`, dashboard widget, project stats) counts them with no change [VERIFIED: app/Domain/TimeTracking/Queries/TimeTotals.php lines 119-145 filter on `billing_state = 'unbilled'`]. That answers D-04's "must be explicit": reserved time counts as unbilled everywhere, is excluded only from the picker, and the "z toho v konceptech" line comes from `WHERE invoice_id IS NOT NULL AND billing_state = 'unbilled'`.
- The picker must offer finished entries only. `TimeTotals` counts a running billable entry as unbilled by design; a running entry must not be reserved (`time_entries_billed_finished_check` would refuse billing it at issue anyway) [VERIFIED: create_time_entries migration constraint `time_entries_billed_finished_check`].
- `MarkEntriesBilled` and `CancelEntriesBilling` skip or refuse in ways that do not fit issue: `MarkEntriesBilled` silently skips running, non-billable and already billed rows, but issue must fail loudly if any linked entry is no longer eligible [VERIFIED: MarkEntriesBilled.php:52-80 and `buckets()`]. Add an internal strict variant (or a parameter) used by `IssueDocument`, and make the manual `markBilled` bulk action refuse reserved rows (F-13) and `CancelEntriesBilling` refuse rows whose `invoice_id` points at an issued document (also F-13).
- Composite FK `(invoice_id, client_id) REFERENCES invoices (id, client_id)` guarantees an entry can only be reserved by a document of its own client.

**Guard re-creation (hand-over from Phase 6):** the migration must drop and re-create `time_entries_frozen_guard` with `invoice_id` added to the mutable list, because a billed row has to be able to release (`billing_state`, `billed_at`, `invoice_id` change together on storno) [VERIFIED: CONTRIBUTING.md Phase 10 note; current call is `Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds'])` at create_time_entries migration line 94]. Listing a column as mutable lets raw SQL re-point a billed entry to another invoice, so add a companion trigger (new function) that refuses a change of `invoice_id` or any snapshot column on a row that is `billed` before and after the update (only the `billed -> unbilled` transition may clear them).

### Pattern 3: The issue transaction (order matters)

```
DB::transaction (all as Admin; any exception rolls back the number too):
 1  lock invoice row FOR UPDATE; assert state = draft, kind in (invoice, proforma)
 2  lock linked entries FOR UPDATE ORDER BY id; every one must still be finished, billable, unbilled, invoice_id = this
 3  re-run the pre-issue blockers server-side (items present, total > 0, supplier data, bank account for currency,
    CNB rate for currency on the issue date, client invoice address, issue date <= today Prague)
 4  CNB: ExchangeRateConverter::rateFor(currency, issue date) per needed pair; recompute unit price of every
    non-manual foreign line, line totals, subtotal/total; CZK total for non-CZK documents
 5  number = DocumentNumbering::next(kind, issue date)        <- locks number_sequences row until commit
 6  write snapshots + admin snapshot; state = 'issued', issued_at; variable symbol from the number's digits
 7  entries: billing_state = billed, billed_at (invoice_id was set at reservation)
 8  render PDF from the snapshot only -> AttachDocuments(owner=invoice) -> set pdf_document_id
 9  DB::afterRollBack(...) registered BEFORE step 8 uploads: delete the stored object if the outer transaction fails
COMMIT -> UI opens the e-mail dialog
```
Why this order: `SequenceAllocator::next()` throws outside a transaction and holds its row lock until commit [VERIFIED: app/Domain/Shared/Sequences/SequenceAllocator.php:43 `if (DB::transactionLevel() === 0) { throw new LogicException(...)`], so allocate late (after validation and conversion) to keep the lock short, but before the PDF because the number is printed on it. The invoice flips to `issued` last among the row writes that need the draft to be editable (items are rewritten for foreign-currency conversion while the parent is still `draft`; afterwards the child guard blocks any write).

The PDF object problem: `AttachDocuments` wraps its own `DB::transaction` (a savepoint inside the issue transaction) and deletes written objects only when its own call throws [VERIFIED: AttachDocuments.php, `catch (Throwable $e) { $this->deleteObjects($written); throw $e; }`]. If step 8 succeeds and the outer transaction then fails (commit error, a later step), the S3 object stays orphaned. Register `DB::afterRollBack` (exists in Laravel 13.36.0 [VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:371]) with the media object paths, mirroring what `DeleteDocument` does with `DB::afterCommit` for the opposite direction.

`AttachDocuments` accepts only `UploadedFile` instances and authorises `attach` [VERIFIED: AttachDocuments.php `uploadedFiles()` doc: "Only an uploaded file object reaches storage"]. For server-generated bytes: write to a temp file and wrap it as `new Illuminate\Http\UploadedFile($tmp, 'faktura-<number>.pdf', 'application/pdf', null, true)` (the last argument is Symfony's `$test` flag, the standard way to build one outside an HTTP request), or add a small `StoreGeneratedDocument` wrapper that does exactly that so the hardening (size, extension, finfo MIME, server-generated object name) is reused. Both need `DocumentOwnerType::Invoice` and a branch in `DocumentOwnerResolver` (an owner class without a branch throws `InvalidArgumentException`) [VERIFIED: DocumentOwnerResolver.php last line of `resolve()`].

### Pattern 4: Guard triggers (what the generic function cannot do)

The generic function freezes a row when its **own** state column differs from the open state, with a flat list of mutable columns [VERIFIED: database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php:39-46: `IF to_jsonb(OLD) ->> frozen_when IS DISTINCT FROM open_state THEN ... IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed)`]. It fires on UPDATE and DELETE only, and for `Immutability::guardTriggerSql(string $table, string $stateColumn, string $openState, array $mutableColumns)` [VERIFIED: Immutability.php:40].

| Table | Mechanism | Mutable after issue |
|-------|-----------|---------------------|
| `invoices` | generic: `guardTriggerSql('invoices', 'state', 'draft', ['cancelled_at', 'pdf_document_id'])` + `truncateGuardSql` | `cancelled_at`, `pdf_document_id` (regeneration, FK `SET NULL` on file delete), `updated_at` |
| `invoice_items` | **new** function `kokpit_guard_child_of_frozen_parent(parent_table, fk_column, parent_state_column, open_state)` on INSERT, UPDATE and DELETE (no state column on the item; INSERT must be blocked too) | none |
| `invoice_admin` | new variant: `internal_note` and `updated_at` always writable; CNB/CZK columns frozen once the parent is issued | `internal_note` |
| `invoice_item_sources` | child guard | none |
| `invoice_payments` | refuse UPDATE always (amount, currency, invoice link are locked, D-19); DELETE only through the sanctioned path (OQ 7) | none |
| `time_entries` | re-created generic guard with `invoice_id` listed + companion transition trigger (Pattern 2) | `billing_state`, `billed_at`, `invoice_id`, `duration_seconds` |

All trigger DDL goes through `Immutability` builders, which validate identifiers (table names are capped at 48 characters so `<table>_truncate_guard` fits the 63-byte limit) [VERIFIED: Immutability.php `TABLE_MAX_LENGTH = 48`]. Keep the new functions in one migration modelled on the existing one, and prove each with the raw-SQL helper `Tests\Support\RawSql::expectSqlState('KP001', fn)` including a mutation self-check (drop the trigger, show the same assertion fails) like the existing pilot tests.

Limit to state once: a table owner can disable triggers and a superuser session can set `session_replication_role = replica`; production must not run the app as a superuser [VERIFIED: Immutability.php class doc and Phase 3 assumption A4].

### Pattern 5: Money, quantity, rounding, cross-currency

**Verified gaps in `Money`:** public surface is `ofMinor, zero, plus, minus, equals, isZero, fromExactMinor, fromMajor, toMajor, isoCurrencyCodes, isKnownCurrency, forDuration, forDurations, convert, forDurationConverted, hourlyRateFor, format, jsonSerialize` [VERIFIED: app/Domain/Shared/Money/Money.php read in full this session, 381 lines]. Missing and needed: `times(string $decimalQuantity)` (unit price x quantity, via `fromExactMinor`), `negated()`, a sign/compare test (`isNegative()`, `isGreaterThan()`), and an exact **cross-currency conversion**. `convert()` expects a rate quoted as "target per unit of source" with at most ten fraction digits; CZK to EUR needs the inverse of the CNB quote, which is not exactly representable, and EUR to USD needs a ratio of two quotes. Add one method taking both CNB quotes (rate, unit amount for each side; CZK side is rate 1, unit 1) and computing one exact rational that goes through `fromExactMinor` (the arch test allows `RoundingMode` only inside `fromExactMinor` [VERIFIED: tests/Arch/MoneyBoundaryTest.php]). Unit-test it against `convert()` for the CZK-target case.

**Quantity and line math (recommendation for D-02/D-09):** generated quantity = `seconds / 3600` rounded `HALF_UP` to 4 decimals in one helper (`InvoiceQuantity::fromSeconds`), stored `numeric(14,4)`; line total = `round(quantity x unit_price)` and is the same number the editor shows and the CHECK enforces. `Money::forDurations()` (exact sum of rate x seconds rounded once) is then not used for invoice lines, because the customer must be able to multiply the printed quantity by the printed price and get the printed total, and the editor has to work that way anyway. Cost: up to 0.00005 h x rate (a few haléř at high rates) between an invoice line and an exact-seconds figure; Phase 8 report amounts of billed entries differ from invoice totals by that much and by the rate source (Open Question 9).

**Lines split by rate:** one line is one unit price. Group by (project or task) **and** effective rate (minor + currency); when task-level rates differ inside a project the project gets one line per rate. Source: `TimeReportReader` already resolves the effective rate and billing type set-based, but its filters take one client and one optional project and it returns every state; extend it (or extract its SQL fragments, whose constants are `private`) with "several projects, unbilled, unreserved, finished" rather than re-implementing the chain, and keep the parity test green [VERIFIED: TimeReportReader.php constants `BILLING_TYPE_SQL`, `RATE_MINOR_SQL`; tests/Feature/Reports/EffectiveBillingParityTest.php referenced in CONTRIBUTING].

**Credit notes store negative amounts:** negative `quantity` (positive original unit price), negative line and document totals; DB sign CHECK per kind; display negative (UI-SPEC U-14). This resolves the STATE blocker "sign convention".

**CNB timing:** the rate is `rateFor(currency, issueDate)`: "the newest stored list not after the day", so a weekend or an issue before the 14:30 publication uses the previous list; a missing list throws `ExchangeRateMissing` and is a pre-issue blocker, never a zero [VERIFIED: ExchangeRateConverter.php docblock and `rateFor()`].

### Pattern 6: Payments, derived state, overdue, income

- `invoice_payments`: `invoice_id`, `amount_minor` (> 0), `currency` (= document currency by CHECK via composite key or trigger), `paid_on` date, `method` (`transfer`, `cash`), `note`, `transaction_id` (FK, unique, null for proforma payments), `carried_from_payment_id` (unique, see Pattern 7).
- **Derived state, not stored:** `remaining = total - SUM(payments) - SUM(credit notes of this invoice)`, floored at zero for display. Credit notes reduce what is owed (economic reality of a partial credit note); a fully cancelled invoice has remaining zero by `cancelled_at`. Unpaid, partially paid and paid are three ranges of the same number. Provide model scopes plus one `ReceivablesReader` (Admin and system only, like `TimeReportReader`) used by the list tabs, the overdue summarizer, the dashboard tile and the client stats so they can never disagree.
- **Overdue** = issued, kind invoice or proforma, not cancelled, remaining > 0, `due_date < today(Prague)`. Bind today from PHP (`TimerClock::now()` converted to the Prague day), never SQL `now()`: PostgreSQL's `now()` ignores a frozen test clock [VERIFIED: pattern already documented in TimeEntry::elapsedSecondsSql docblock].
- CZK equivalent of overdue/unpaid totals: convert each document's **remaining** amount with that document's frozen issue-time snapshot via `Money::convert`, one rounding per document, sum in CZK (U-6). Do it in PHP over the filtered rows (few rows), not in SQL, so there is one rounding implementation.
- **Income (FI-03, D-18, D-19):** `RecordPayment` inserts the payment and, for kind `invoice` only, calls `RecordTransaction` in the same transaction with the system category `TransactionCategory::invoicePayments()` [VERIFIED: TransactionCategory.php `public static function invoicePayments(): self`], the payment date and the typed category override. `TransactionCzk` fixes the CZK figure from the **payment date's** CNB list (a different rate than the issue-date snapshot, by design). Add `transactions.invoice_id` (FK) and `transactions.invoice_payment_id` (unique FK) in a migration that keeps the composite keys and CHECKs [VERIFIED: transactions migration header "The link to an invoice is added in Phase 10"]; `UpdateTransaction` must refuse amount, currency and invoice changes on such a row (date and category stay editable), and `DeleteTransaction` must refuse deleting it (OQ 7).
- Warning flow (D-17): the modal computes "already paid / above remaining" live for the warning and label change; the Action itself never blocks, it only re-validates amount > 0, date not in the future, currency, kind (no payment on a credit note or a cancelled invoice).

### Pattern 7: Proforma conversion

Convert = one transaction: lock the proforma; require paid (sum of its payments >= total) and no existing invoice (`UNIQUE (proforma_id)` is the backstop); create the invoice draft-and-issue in one go with copied items, `proforma_id`, new number from the **invoice** series; `UPDATE time_entries SET invoice_id = :new WHERE invoice_id = :proforma` while they stay `billed` (never released in between, D-15), which needs the companion trigger to allow exactly this re-point when the update is part of the conversion (design it as an allowed transition: billed to billed with `invoice_id` moving from a proforma to its own converted invoice, checked by `EXISTS` on the `proforma_id` link). **Payments are copied, not moved:** payments are immutable, so the invoice gets new `invoice_payments` rows with `carried_from_payment_id` pointing at the proforma payment (unique), each with its real `paid_on`, and `RecordTransaction` books one income transaction per carried payment. The proforma keeps its payments (history), shows "Převedeno na fakturu" via the link, and the "paid proforma without invoice" attention rule is `paid AND NOT EXISTS (invoice with proforma_id = this)`.

### Pattern 8: Credit notes and storno

- Credit note = a `credit_note` document with `credited_invoice_id`, negative items each pointing at `credited_item_id`, allocated from the credit-note series at issue, same PDF pipeline minus the payment box.
- Creditable quantity per original line = original quantity minus the sum of earlier credits; enforced under a row lock on the original invoice (a sum across rows cannot be a CHECK). Over-credit is a field error (copy exists in the UI-SPEC).
- **Full storno** = credit note for all remaining creditable quantities (assumption A5), then in the same transaction `invoices.cancelled_at` on the original and `ReleaseEntries` (the single primitive also used by draft delete and the unlink action: `UPDATE time_entries SET invoice_id = NULL, billing_state = 'unbilled', billed_at = NULL`). A partial credit note leaves time billed. The existing payment and income stay untouched (D-16).
- Proforma storno is out (U-7).

### Pattern 9: PDF pipeline

Spike results, all executed this session on PHP 8.5.11 with `dompdf/dompdf` 3.1.6 and `chillerlan/php-qrcode` 5.0.5:
- Static Roboto TTF registered by `@font-face` with a `file://` URL renders Czech text and bold correctly (visual check of page 1: "Zálohová faktura – Částečně zaplaceno, Stornováno, Variabilní symbol, Příliš žluťoučký kůň úpěl ďábelské ódy", bold line with "12 000,00 Kč"); the PDF embeds `/Roboto-Regular` and `/Roboto-Bold` subsets; a `position: fixed` 8pt accent stripe at the top renders.
- Dompdf copies the TTF into **`fontDir`** and writes `installed-fonts.json` plus `.ufm` metrics on first use. `Options::getFontDir()` defaults to `vendor/dompdf/dompdf/lib/fonts` (ran `new Options()`), and `WorkReportPdf::options()` sets only `fontCache` and `tempDir` [VERIFIED: WorkReportPdf.php:182-184]. The invoice renderer must set `fontDir` to `storage/framework/dompdf` (writable, git-ignored) and `chroot` to **both** `resources/views/pdf` and `resources/fonts` (`Options::setChroot` accepts an array [VERIFIED: vendor/dompdf/dompdf/src/Options.php:499-507]).
- First-use font installation writes shared files; warm it deliberately (one throwaway render under a cache lock, or a step in `kokpit:deploy:verify`) so a queued job and a request cannot race on `installed-fonts.json` (risk magnitude `[ASSUMED]`).
- Keep `isRemoteEnabled`, `isPhpEnabled`, `isJavascriptEnabled` off; images are data URIs only; every value goes through Blade `{{ }}` (the work report already proves a markup canary renders as text).
- `Dompdf` has no flexbox or grid: every row is a table or float, as the UI-SPEC already requires.
- Page furniture ("Strana n / total", hairline) follows the existing `WorkReportPageDecoration` page-script pattern (a callable handed to `$dompdf->getCanvas()->page_script()`, so PHP stays off in HTML) [VERIFIED: WorkReportPageDecoration.php, WorkReportPdf.php:55-60].

**Regeneration equivalence (D-23) is testable on the HTML, not the PDF bytes:** render the same snapshot twice and assert identical HTML strings plus identical page count and extracted text; PDF bytes embed creation metadata. Therefore also freeze the render inputs that are not already snapshotted: accent hex and footer text belong in the issue snapshot (they are two short strings and the footer can carry legal registration text); logo and signature stay "current at render" (Open Question 4; the UI-SPEC assumption U-8 says the accent is current at regeneration).

**Palette:** reproduce the cdom tools derivation exactly [CITED: raw.githubusercontent.com/cdom-cz/tools/main/src/lib/invoice.ts]: normalise `^#[0-9a-f]{6}$` (else default); HSL from the accent; `ink` s=min(s,0.2) l=0.11; `muted` s=min(s,0.08) l=0.46; `hairline` s=min(s,0.12) l=0.89; `tint` s=min(s,0.7) l=0.95; `rowAlt` s=min(s,0.1) l=0.965; `accentText`: while contrast vs `#ffffff` < 3.5 and l > 0.05, l -= 0.02; `onAccent` = white if contrast(accent, white) >= contrast(accent, ink) else ink; WCAG luminance with threshold 0.03928 and weights 0.2126/0.7152/0.0722; contrast = (hi+0.05)/(lo+0.05). Pure PHP class, unit-tested with the default accent producing ink as `onAccent` (white on `#ff6f00` is about 2.8:1 per the UI-SPEC).

**SPAYD and QR** (spec [CITED: qr-platba.cz/pro-vyvojare/specifikace-formatu/]): header `SPD*1.0*`, `KEY:VALUE*` pairs, no `*` inside a value, `ACC` IBAN (max 46, optionally `+BIC`), `AM` max 10 chars dot decimal two places, `CC` exactly 3, `DT` `YYYYMMDD`, `MSG` max 60, `X-VS` numeric max 10, QR error correction level M, recommended character set digits/uppercase/space `$%*+-./:` (anything else switches the QR to binary mode and makes it denser). Recommendation: build the string in one pure `Spayd` class, strip diacritics with `iconv('UTF-8', 'ASCII//TRANSLIT')`, uppercase the message, replace `*`, cut to 60, require a CZ IBAN (all Europe 1 and Europe 2 accounts have one: `iban` is `required` whenever the format shows it [VERIFIED: BankAccountSettings.php `fieldRules` `'iban' => ['required', new IbanRule]`, BankAccountFormat::visibleFields]), CZK only, kinds invoice and proforma only. Executed this session and round-tripped (encode then decode with the library's own reader returned the identical string):

```php
// [VERIFIED: spike run this session; chillerlan/php-qrcode 5.0.5]
use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Common\{EccLevel, Version};
use chillerlan\QRCode\Output\QROutputInterface;

$options = new QROptions([
    'version' => Version::AUTO,                 // 0 means a fixed tiny version and overflows ("code length overflow")
    'eccLevel' => EccLevel::M,
    'outputType' => QROutputInterface::GDIMAGE_PNG,
    'outputBase64' => true,                     // returns "data:image/png;base64,..." for <img src>
    'scale' => 6,
    'quietzoneSize' => 4,
    'imageTransparent' => false,
]);
$dataUri = (new QRCode($options))->render($spayd);
// test side: (new QRCode(new QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($pngBytes) === $spayd
```

### Pattern 10: E-mail

- Table `invoice_emails` (Admin-only): `id`, `invoice_id`, `recipient`, `subject`, `body`, `attach_work_report`, `state` (`queued`, `sending`, `sent`, `failed`), `attempts`, `sent_at`, `failure_code` (a fixed class, never exception text), timestamps. One row per attempt group; "Odeslat znovu" creates a new row (OQ 11).
- `SendInvoiceEmail extends KokpitJob` with `#[Idempotent(how: ...)]` (the job contract test scans for it [VERIFIED: KokpitJob.php docblock and tests/Arch/JobContractTest.php]). Identify the work by the e-mail row id; claim `queued -> sending` with a conditional `UPDATE` (zero rows means another run owns it, return); send; mark `sent`. Defaults are 3 tries, backoff 10/60/300 s, 60 s timeout [VERIFIED: KokpitJob.php:49-51]. At-least-once delivery is the honest guarantee: a worker that dies between SMTP acceptance and the `sent` update can send twice on retry; a stale `sending` row older than a threshold is shown as failed so the Admin decides.
- Dispatch after commit (queue `after_commit` is on for redis [VERIFIED: config/queue.php `'after_commit' => true`]); a `failed()` hook marks the row `failed` and sends the one bell notice (title/body fixed copy, number only; pattern: `DocumentArchiveFailedNotification`, database channel only, `notifyNow`) [VERIFIED: DocumentArchiveFailedNotification.php].
- Attachments: the stored PDF bytes from the invoice's `pdf_document_id`, plus `WorkReportPdf::render(WorkReportSource::fromEntryIds($ids, $now), $language)` built inside the job from the entries linked at that moment (a cancelled invoice has none; show the checkbox only when entries are linked) [VERIFIED: WorkReportSource::fromEntryIds signature and WorkReportPdf::render]. The work report reads live supplier and client lines, not the snapshot: acceptable drift, say so in the code comment.
- Plain-text body only (U-11): no Markdown/HTML rendering of editable admin text; validate recipient with the e-mail rule, strip CR/LF from the subject, cap lengths, so there is no header injection.
- Health: add the new indicator to the `$real` list in `OperationsServiceProvider` (lines 44-49: `FailedJobsIndicator`, `OldestPendingJobIndicator`, `SchedulerHeartbeatIndicator`, `LastRateDateIndicator`); `HealthIndicatorRegistry::missingSlots($real)` then no longer registers the placeholder for `UnsentInvoiceEmails`, and `register()` would refuse a second indicator for the same slot [VERIFIED: OperationsServiceProvider.php:38-60; Phase 3 decisions 03-15 and 03-16]. Update `tests/Feature/Operations/HealthRegistryTest.php`, which still lists this slot as a placeholder (line 53). Value = invoices whose latest e-mail row is `queued` or `failed`; thresholds in `config/kokpit.php` `health` array next to the existing ones.

### Pattern 11: Partner access

- `Invoice` implements `PartnerIsolated` with `IsolatesPartners`; `constrainForPartner($query, $clientId)` = own client AND `state = 'issued'` AND kinds invoice, proforma, credit note (assumption U-2), mirroring `Document::constrainForPartner`. A draft is never a Partner row, so there is no count or hint.
- `InvoiceItem` Partner scope delegates to the scoped invoice query (as `Task` delegates to `Project`) [VERIFIED: STATE.md 05-01 entry]. Admin-only data sits in other tables (Pattern 1). Pin the `invoices` and `invoice_items` column lists in `PartnerSafeColumnsTest` and add the "no column looks like note/rate/cnb/czk/internal" regex test.
- Resource: `PartnerInvoiceResource` repeats the trait conditions in `canAccess()` plus a Partner-with-client check, like `PartnerProjectResource` [VERIFIED: PartnerProjectResource.php `canAccess()`]; read-only, no search; table and infolist from pinned Partner builders only.
- **PDF download for the Partner needs its own path.** `DocumentFileController` finds the file through the Partner-scoped `Document` query and then `Gate::authorize('view')`; `Document::constrainForPartner` returns only project, task and comment files, and `DocumentPolicy::view` refuses non-shareable owners [VERIFIED: DocumentFileController.php steps 3-4; Document.php `constrainForPartner`; DocumentPolicy::view]. Recommendation: do not widen the `Document` scope (its invisibility of invoice files is a Phase 9 isolation guarantee). Add `InvoicePdfController` + a signed, user-bound, short-lived route built like `DocumentUrl` (same TTL config, `u` bound to the user), which authorises through the **Partner-scoped Invoice query** (the single place that defines "issued, own client") and then reads the document and media as system and streams it with `nosniff`, `private, no-store`. The UI-SPEC says "no new route"; this is a deliberate, small deviation (OQ 5). The Admin can keep using the existing document route.
- Invoice-owned documents must stay hidden from the Partner `Soubory` tabs: the owner types there are project/task/comment only, the visible flag cannot be set for `invoice` because `documents_visible_owner_check` allows only project, task and task_comment [VERIFIED: create_documents migration]. Treat `invoice` like `transaction` for archived-owner rules (never archived: `Document::ARCHIVABLE_OWNER_TYPES = ['client','project','task','task_comment']` [VERIFIED: Document.php:71]) so invoice PDFs stay findable when a client is archived (Phase 9 hand-over item 1 asks to "decide and test").

### Pattern 12: Filament layer (thin)

- Writes go through Actions that re-validate (established rule; see `TransactionResource` docblock "Nothing here writes a row"). **Do not use `Repeater::relationship()` for items**: it persists from form state and bypasses the Action's validation, locking and server-side totals. Use `Repeater::table([...])` (verified present) over plain array state, send the whole array to `UpdateDraft`, which replaces items under the invoice row lock and recomputes every line total server-side (ignore any client-sent total).
- Livewire public properties are client-controlled: never put a client id, an invoice id of another client, a rate or a CNB rate in a public property that an Action trusts; re-read by id through the scoped query on every update (see `tests/Arch/LivewireComponentContractTest.php` for the existing rule on scalar-only properties).
- Every new Resource, Page, Widget and relation manager needs `#[AccessRule(...)]` plus the matching `Enforces*AccessRule` trait; a registry test fails on any missing declaration [VERIFIED: tests/Arch/PanelRegistryTest.php listed; Phase 2 decision 02-11]. Admin-only: all invoice resources and relation managers, the widget, client panels.
- Tabs with counts: build `getTabs()` after one aggregate count query and omit the "Zálohy k vyfakturování" tab at zero (UI-SPEC A).
- Dashboard: add the widget to the Admin list in `Dashboard::getWidgets()` (currently `[HoursStats::class, TodayPlan::class, UnbilledPerClient::class, UpcomingDeadlines::class]` [VERIFIED: Dashboard.php:47]) with `$sort = 20`; existing widget sorts are 30 (`UnbilledPerClient`). A `StatsOverviewWidget` that is **not** collapsible needs no new `DashboardWidget` case; if made collapsible, the enum case and a migration widening `dashboard_widget_preferences_widget_key_check` are both required [VERIFIED: DashboardWidget.php docblock].
- Navigation: "Faktury" Admin sort 50 is free (taken: 10, 20, 30, 31, 40, 41, 42, 43, 55, 56, 60, 61, 62, 80, 84, 90, 92, 95) and Partner sort 30 is free (taken: 10 and 20) [VERIFIED: grep of `$navigationSort` in app/Filament this session].
- The invoice PDF delete confirmation extends `DocumentActions` delete for `owner_type = 'invoice'` (explicit tick before the submit enables); after deletion the detail offers "Vytvořit PDF znovu" which calls `RegeneratePdf` (snapshots, new `Document`, new `pdf_document_id`).

### Anti-Patterns to Avoid

- **Storing a payment state column** next to the sum of payments: it drifts. Derive; keep only `cancelled_at` as a stored state.
- **Reading the settings, client or rates at PDF render time** for an issued document: only the snapshot may feed the template (accent and footer are snapshotted by recommendation).
- **A second rounding site:** any `round()`, `intdiv`-based or float math in PHP for money; everything through `Money`. Floats are banned by the Money boundary tests.
- **`DB::table()` or raw joins in Partner-reachable code:** the escape-hatch scanner forbids `DB::table` in `app/`; hand-written SQL readers must refuse everybody but Admin and system like `TimeReportReader` [VERIFIED: tests/Arch/QueryEscapeHatchTest.php listed; TimeReportReader::authorise()].
- **Allocating the number in the draft** or before validation: the series would gap on a failed issue (the transaction protects it only if allocation is inside the same transaction).
- **Moving a payment row between documents** (it is immutable); copy with a link.
- **Putting `internal_note`, CNB rate or source rate in `invoices` / `invoice_items`.**
- **Writing the billed columns of `time_entries` directly** from invoice code (CONTRIBUTING says use `MarkEntriesBilled`/`CancelEntriesBilling`; add the strict variant instead of bypassing).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Gap-free numbers | Max(number)+1, own counter | `DocumentNumbering::next()` / `SequenceAllocator` | Proven by 8x25 parallel processes and a no-lock mutation run (decision 02-06) |
| Immutability | PHP-only "readonly" checks | `Immutability::guardTriggerSql` + the new child guard | Must hold against raw SQL, packages, console |
| Money rounding/conversion | float math, `round()` | `Money` (extended, one `fromExactMinor` point) | Arch test forbids Brick and `RoundingMode` elsewhere |
| CNB lookup | own rate query | `ExchangeRateConverter::rateFor()` / `RateBook` | Handles weekends, holidays, outages; throws instead of zero |
| QR generation | own matrix encoder | `chillerlan/php-qrcode` 5.0.5 | Already installed; roundtrip proven |
| PDF engine | HTML-to-PDF via headless Chromium | Dompdf (spike result, Phase 3) | Chromium 3x slower, 6x larger, needs Node/Chromium in the container |
| Work report | invoice-specific report code | `WorkReportSource::fromEntryIds()` + `WorkReportPdf` | Carries no money by construction |
| File storage and deletion | direct `Storage::put` for the PDF | `AttachDocuments` / `DeleteDocument` | Hardened upload, server-generated names, D-23 removal |
| Income booking | direct `Transaction::create` | `RecordTransaction` / `TransactionCzk` | Category/currency/project checks, CZK snapshot |
| IBAN validation | new IBAN code | `App\Domain\Settings\Banking\Iban` | Already validates 32 countries with mod-97 |
| Queue job base | plain `ShouldQueue` | `KokpitJob` + `#[Idempotent]` | System context, retries, failed-job alert, arch test |
| Admin bell notices | custom channel | Filament database notification via `notifyNow` | Existing pattern, escaped once |
| Czech plural of "den" | manual if/else | `trans_choice` (the `cs` rule gives 1 / 2-4 / 5+) | [VERIFIED: vendor/laravel/framework/src/Illuminate/Translation/MessageSelector.php:359-363] |
| HSL/contrast palette | ad-hoc colours | One small `DocumentPalette` reproducing the cdom tools rules | Deterministic, unit-testable |

**Key insight:** this phase is mostly an integration of already-proven parts under a stricter integrity regime. Every custom solution invites a second rounding point, a second place that defines "issued, own client", or a write path that skips the guards.

## Common Pitfalls

### Pitfall 1: Two drafts race for the same entries
**What goes wrong:** select-then-update lets both read the entries as free.
**Why it happens:** a UI picker is not a lock.
**How to avoid:** the conditional `UPDATE ... WHERE invoice_id IS NULL ... RETURNING id`, items built from returned ids only, entries locked in id order elsewhere (as `MarkEntriesBilled` does).
**Warning signs:** a draft with fewer entries than the live count showed; a concurrency test with two workers creating drafts over the same set must end with every entry in exactly one draft.

### Pitfall 2: The generic guard blocks (or fails to block) the wrong thing
**What goes wrong:** items have no state column, so `guardTriggerSql('invoice_items', ...)` cannot freeze them; a billed time entry with `invoice_id` in the mutable list can be re-pointed by raw SQL; a stored generated column makes every update of a frozen row fail.
**How to avoid:** Pattern 4 (child guard on INSERT/UPDATE/DELETE, companion transition trigger, no generated columns), each with a `RawSql::expectSqlState('KP001')` test and a mutation check.

### Pitfall 3: The row is frozen before the issue is finished
**What goes wrong:** flipping `state` to `issued` early makes later writes in the same transaction (item recomputation, `pdf_document_id` is mutable but items are not) fail with `KP001`.
**How to avoid:** the order in Pattern 3; `pdf_document_id` and `cancelled_at` are the only post-issue invoice writes.

### Pitfall 4: Orphaned PDF object after a rolled-back issue
**What goes wrong:** the S3 object written by `AttachDocuments` survives the rollback.
**How to avoid:** `DB::afterRollBack` cleanup registered before the upload; test by forcing a failure after step 8 and asserting the bucket (the test suite has `Tests\Support\S3TestDisk`) holds no object and `number_sequences.next_value` is unchanged.

### Pitfall 5: "Unbilled" reserved entries edited or deleted
**What goes wrong:** a reserved entry can still be edited or deleted through the panel, the timesheet or the API, so the draft's hours and the work report diverge, or the entry vanishes from under a draft.
**How to avoid:** Open Question 1 (recommended: refuse changes to billing-relevant fields and deletion while reserved, same `entry_locked` 409 code for the API) in `UpdateTimeEntry` and `DeleteTimeEntry` under the row lock they already take; billed refusal sits at `UpdateTimeEntry.php:77` and `DeleteTimeEntry.php:40`. The API docs and their pinned text test then need the new sentence.

### Pitfall 6: Dompdf writes fonts into `vendor/`
**What goes wrong:** with only `fontCache` set, the first render tries to install Roboto under `vendor/dompdf/dompdf/lib/fonts` (read-only on deploy), or pollutes the working tree.
**How to avoid:** set `fontDir` explicitly and widen `chroot`; test that after a render no file under `vendor/dompdf` changed.

### Pitfall 7: `Version::AUTO` forgotten
**What goes wrong:** `QROptions(['version' => 0])` throws "code length overflow" for any realistic SPAYD (reproduced this session).
**How to avoid:** `Version::AUTO` as in the verified example, plus a unit test that builds a QR for the longest allowed SPAYD.

### Pitfall 8: Line total drift from quantity rounding
**What goes wrong:** the printed `quantity x unit price` does not equal the printed total.
**How to avoid:** total is `round(quantity x unit_price)` everywhere (Money method, editor, DB CHECK); the generator rounds the quantity once; test with awkward seconds (45305 s = 12.5847 h).

### Pitfall 9: A paid invoice with no income, or income that can be deleted away
**What goes wrong:** deleting or editing the payment's transaction on the Transactions screen leaves the invoice "paid" without income.
**How to avoid:** `DeleteTransaction`/`UpdateTransaction` refuse payment-derived rows (OQ 7); one sanctioned correction path that removes payment and transaction together.

### Pitfall 10: Overdue computed with SQL `now()`
**How to avoid:** bind the Prague day from PHP; the test clock does not move PostgreSQL (see Pattern 6).

### Pitfall 11: Partner leakage through "harmless" surfaces
**What goes wrong:** the invoice number or amount in a bell, an activity row, a Livewire snapshot, a global search hit, a 403 vs 404 difference, a tab count for drafts.
**How to avoid:** the UI-SPEC "Never on a Partner surface" list as a rendered-HTML test with canaries; activity allowlist excludes `internal_note`, free text and CNB data; invoice resources are not globally searchable; a foreign or draft document is 404.

### Pitfall 12: Number pattern limits only guard invoices
**What goes wrong:** the variable symbol is the digits of the number (at most 10), but `NumberPattern` enforces digits-only and the 10-character cap only for `DocumentKind::Invoice` [VERIFIED: NumberPattern.php:43 `INVOICE_MAX_LENGTH = 10`, lines 135 and 152 test `$kind === DocumentKind::Invoice`]. A proforma pattern with letters or a long counter yields a malformed or empty VS and an invalid SPAYD.
**How to avoid:** extend the checks to the proforma kind (it carries a VS and QR; credit notes carry neither), keep the Czech messages, add the UI-SPEC F-15 test.

### Pitfall 13: Same variable symbol on a proforma and an invoice
**What goes wrong:** all three kinds default to `{YYYY}{NNNN}` [VERIFIED: DocumentKind.php `defaultPattern()` returns `'{YYYY}{NNNN}'` for the non-task kinds], so a proforma and an invoice both get VS `20260001`. Harmless now; ambiguous for Phase 11 payment matching.
**How to avoid:** decide the proforma default or leave a note for Phase 11 (OQ 8); at minimum index `variable_symbol` non-uniquely.

### Pitfall 14: Binary fonts and the hygiene scanner
**What goes wrong:** two ~360 KB TTFs enter the repository; the scanner scans NUL content twice and the attribute guard rejects `-diff`/`binary` rows [VERIFIED: STATE.md 01-07 entries].
**How to avoid:** do not add `.gitattributes` rows for them; run `scripts/check-sensitive.sh` and the lefthook hook on the commit that adds them before assuming it passes.

### Pitfall 15: Time zone off-by-one on dates
**How to avoid:** `issue_date`, `due_date`, `paid_on` are `date` columns written as Prague calendar days; "today" is the Prague day (`PragueDay`); never derive them from a UTC instant formatted without conversion (the Phase 6 `DateTimePicker` note applies).

## Code Examples

### Existing guard call to copy and the one to re-create
```php
// Source: database/migrations/2026_10_11_000100_create_time_entries_table.php:94-95 [VERIFIED: read this session]
DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds']));
DB::unprepared(Immutability::truncateGuardSql('time_entries'));
// Phase 10 migration: Immutability::dropGuardTriggerSql('time_entries') first, then re-create with 'invoice_id' added.
```

### Raw-SQL immutability assertion (test)
```php
// Source: tests/Support/RawSql.php [VERIFIED]; each statement runs in a savepoint inside RefreshDatabase
RawSql::expectSqlState('KP001', fn () => DB::statement("UPDATE invoices SET total_minor = total_minor + 1 WHERE id = ?", [$issued->id]));
RawSql::expectAllowed(fn () => DB::statement("UPDATE invoice_admin SET internal_note = 'x' WHERE invoice_id = ?", [$issued->id]));
```

### Dompdf options for the invoice (same hardening as the work report, plus `fontDir` and a wider `chroot`)
```php
// Source: pattern of WorkReportPdf::options() [VERIFIED lines 182-184] + spike run this session
new Options([
    'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
    'defaultFont' => 'DejaVu Sans',                    // fallback only; body is Roboto via @font-face
    'chroot' => [resource_path('views/pdf'), resource_path('fonts')],
    'fontDir' => storage_path('framework/dompdf'),     // NOT the vendor default
    'fontCache' => storage_path('framework/dompdf'),
    'tempDir' => storage_path('framework/dompdf'),
]);
```
```html
<style>
@font-face { font-family: "Roboto"; font-weight: normal; font-style: normal;
  src: url("file://{{ $fontDir }}/Roboto-Regular.ttf") format("truetype"); }
@font-face { font-family: "Roboto"; font-weight: bold; font-style: normal;
  src: url("file://{{ $fontDir }}/Roboto-Bold.ttf") format("truetype"); }
</style>
```
(`$fontDir` is the absolute `resource_path('fonts')` passed from PHP, never user input.)

### PDF text extraction in a test (DDEV)
```bash
# [VERIFIED: ran in DDEV this session, gs 10.05.1; Czech diacritics came back intact]
gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- storage/framework/some-test.pdf
```

### Single-writer entry release (shared by delete draft, unlink, cancel, full storno)
```sql
-- [ASSUMED] shape
UPDATE time_entries SET invoice_id = NULL, billing_state = 'unbilled', billed_at = NULL
WHERE invoice_id = :invoice [AND id IN (:selected)];   -- ordered by id when locking first
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Chromium for HTML to PDF | Dompdf, tables only | Phase 3 spike | No Node/Chromium in the container; no flexbox |
| `chillerlan/php-qrcode` 4.x manual version | 5.x `Version::AUTO`, `EccLevel::M` | 5.0 | `version => 0` is not "auto" |
| Variable fonts for Roboto | static TTF 400/700 | n/a | Dompdf cannot use variable fonts |
| `DB::afterCommit` only | `DB::afterCommit` and `DB::afterRollBack` | present in 13.36.0 | Compensating cleanup for external side effects |

**Deprecated/outdated:** the cdom tools default accent `#f26b1d` (Kokpit's is `#ff6f00`); its WOFF fonts for Dompdf.

## Runtime State Inventory

Not applicable: Phase 10 is greenfield (no rename, refactor or migration of existing strings). The only migrations of existing structures are additive columns (`time_entries.invoice_id`, `transactions.invoice_id`, `transactions.invoice_payment_id`) and re-creation of one trigger. Existing rows: reserved/billed history does not exist for invoices; entries billed manually in Phase 6 keep `invoice_id = NULL` and stay valid.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The Czech legal field list for a non-VAT-payer invoice (name, address, company ID, registry note, number, issue and due date, amount, payment method, "not a VAT payer" note recommended; the heading "Faktura", not "daňový doklad"; a credit note must reference the original document) comes from secondary Czech business sources, not the statute text (§ 11 zákona o účetnictví, § 435 občanského zákoníku). It is a recommendation, not legal advice | Pattern 9, Summary | A missing mandatory field on a real invoice; owner should confirm with their accountant |
| A2 | Holding the `number_sequences` row lock during PDF render and upload (about a second) is acceptable for a single-admin instance | Pattern 3 | Issue serialisation under heavy parallel issue; mitigated by being the only writer in practice |
| A3 | First-use Dompdf font installation can race on `installed-fonts.json` between a request and a queue worker | Pattern 9 | Corrupt font index; mitigation is cheap (warm once), so applying it anyway is safe |
| A4 | PostgreSQL shapes suggested here (CHECKs, composite FKs, partial unique indexes, trigger functions) will be accepted by the schema convention tests R1-R9 without new exemptions | Pattern 1, 4 | Some `*_id` naming or type exemption needed in `PgSchema::EXEMPT` |
| A5 | A full storno credits the remaining creditable quantities (original minus earlier partial credits) rather than refusing when partial credits exist | Pattern 8 | Over- or under-credit versus the owner's accounting habit |
| A6 | Credit notes reduce the remaining amount owed on the original invoice | Pattern 6 | Overdue and unpaid figures differ from the owner's expectation for partially credited invoices |
| A7 | Overdue also applies to unpaid proformas (D-20 says "issued, not fully paid", it does not name the kind) | Pattern 6 | A proforma shows or hides in the overdue tab/tile against expectation |
| A8 | `ghostscript` is present on GitHub's `ubuntu-latest` runner image | Environment, Validation | CI PDF-text tests fail; make them skip without `gs` or install it in the workflow |
| A9 | Roboto 3.011 static TTF is the same design family as the owner's Roboto WOFF files; the owner's WOFF files carry no licence text in the public repository listing | Standard Stack | Cosmetic difference only |
| A10 | The sum of partial credit notes must never exceed the invoice (stated in the UI-SPEC copy) is enforced under the original invoice row lock, not by a CHECK | Pattern 8 | A race could over-credit if the lock is omitted |

## Open Questions (RESOLVED)

Every question below was ruled during planning; the ruling ids name the plan where each ruling is recorded. Where a ruling departs from the recommendation, the RESOLVED line says so and why (locked CONTEXT decisions win).

1. **May a reserved (draft-linked) time entry still be edited or deleted?** (UI-SPEC N15 "unresolved")
   - Known: reserved entries stay `unbilled`; UI-SPEC proposes a non-blocking callout; D-04 says nothing about edits; the API documents `entry_locked` 409 for billed entries only.
   - Unclear: the owner's workflow (does he fix a duration after drafting?).
   - Recommendation: lock `started_at`, `ended_at`, `billable`, client/project/task and deletion while reserved (description stays editable), refuse in the Actions under their row lock, map to the existing `entry_locked` 409 in the API and update the API text and its pinned test. This keeps invoice hours, work report and entries from drifting.
   - RESOLVED: R-10-1 in plan 10-10 (recommendation adopted: billing-relevant fields and deletion of a reserved entry are locked, description stays editable, API answers 409 `entry_locked`).

2. **Fixed-price entries: linked by default or only with the checkbox?**
   - Known: D-07 (locked) says the fixed amount is added manually "optionally marks the project's time as billed with it"; UI-SPEC Surface B says hours of fixed-price projects are added to items without an amount, and C3 says the checkbox links the entries.
   - Recommendation: CONTEXT wins. The time source excludes fixed-price entries unless the checkbox of C3 is ticked; the create-modal callout becomes informational ("hours stay unbilled until you add the fixed amount"). A zero-priced hours line contradicts "unit price zero only for a confirmed line".
   - RESOLVED: R-13-1 in plan 10-13 (recommendation adopted: D-07 wins; fixed-price entries are linked only with the fixed-amount checkbox, R-14-1 and R-14-3 in plan 10-14).

3. **Is the invoice date allowed to be in the past, and how does it interact with numbering and the CNB date?** (UI-SPEC unresolved)
   - Recommendation: allowed range is `[issue date of the latest issued document of the same kind and number scope, today]`, checked under the sequence lock so numbers stay chronological; the CNB rate is `rateFor(currency, issue date)`.
   - RESOLVED: R-12-1 in plan 10-12 (recommendation adopted: issue date between the latest issued document of the same kind and today, checked under the sequence lock).

4. **What is frozen for the PDF besides D-12?** Accent colour, footer text, logo, signature.
   - Recommendation: freeze accent and footer text (strings) in the issue snapshot; logo and signature stay current at render. Overrides UI-SPEC assumption U-8 for accent only; the owner can reverse it.
   - RESOLVED: R-03-1 in plan 10-03, with R-12-3 in plan 10-12 and R-15-4 in plan 10-15 (recommendation not adopted for logo and signature, to conform to D-21 and D-23: accent, footer, logo and signature are all frozen in the issue snapshot, the images as object path plus SHA-256 of retained, content-addressed objects; a missing or altered image fails the render instead of changing the regenerated PDF).

5. **Partner "never sees rates" versus unit prices on the document.** U-3 shows the frozen lines with unit prices to the Partner because they are printed on the PDF the client receives. For time lines the unit price is the hourly rate.
   - Recommendation: confirm with the owner that "rates" in IN-09 means rate configuration, CNB data and source-rate columns, not the printed unit price; and use a dedicated invoice PDF route for the Partner instead of widening the `Document` scope (deviation from "no new route").
   - RESOLVED: R-05-2 in plan 10-05 (printed unit prices are Partner-readable; "rates" means rate configuration, source rates and CNB data) and R-38-1 in plan 10-38 (dedicated signed, user-bound invoice PDF route; the `Document` scope is not widened).

6. **Cross-currency lines (rate currency differs from invoice currency):** CZK to foreign and foreign to foreign need the new exact `Money` conversion; confirm the recipient's expectation that unit price is converted and rounded to the minor unit, then the line total is `round(quantity x unit price)` (two roundings, one of them a representation of the converted rate).
   - RESOLVED: R-01-1 in plan 10-01 and R-17-1 in plan 10-17, with R-04-3 in plan 10-04, R-13-5 in plan 10-13 and R-23-4 in plan 10-23 (recommendation not adopted, to conform to D-09 and Phase 2 D-09: a converted line amount is one exact rational rounded HALF_UP exactly once by `Money::timesThroughCzk()`; the printed unit price is a display rounding that never feeds the amount, and `invoice_items.price_converted` lets the database bound the difference).

7. **Correcting a mistaken manual payment.** UI-SPEC makes payments immutable with no delete, "corrected on the transaction side"; but Phase 9 rules would let the Admin delete the income transaction and leave the payment orphaned.
   - Recommendation: add one sanctioned `DeletePayment` (confirmation, activity-logged, deletes payment and its income transaction in one transaction, allowed only on an issued, uncancelled invoice or proforma without a converted invoice) and make `DeleteTransaction`/`UpdateTransaction` refuse payment-derived rows otherwise.
   - RESOLVED (owner amendment of D-19, 2026-10-17; supersedes the earlier resolution that payments are permanent): recommendation adopted. Plan 10-06 R-06-1, R-06-5 and R-06-7: a payment is never updated; a payment and its income are deleted only together, under the transaction-local `kokpit.payment_deletion` flag that only `DeletePayment` sets, with a deferred pair check; payment income changes only in date and category for every writer. Plan 10-19 R-19-1: `UpdateTransaction`, `DeleteTransaction` and the Transactions API refuse payment income and point to deleting the payment in the invoice detail. Plan 10-41 R-41-1 to R-41-5: `DeletePayment` with the lock order document, payment, income; deviating from the recommendation, it is allowed on a cancelled invoice too, and on a converted proforma it deletes the invoice-side carried copy with its income without touching the proforma payment, while it refuses a proforma payment whose carried copy still exists. Plan 10-30: the "Smazat platbu" action with its confirmation.

8. **Proforma number pattern and variable symbol.** Same defaults produce equal VS for proforma and invoice. Recommendation: change the proforma default pattern to produce a distinct digit string and extend the digits-only/length checks to proforma (F-15); leave a Phase 11 note that matching by VS must scope by kind.
   - RESOLVED: R-07-1, R-07-3 and R-07-4 in plan 10-07 (recommendation adopted: digits-only, at most 10 characters for proforma patterns with the distinct default `{YYYY}9{NNNN}`; owner acknowledgement point for narrowing Phase 3 D-05 recorded in plan 10-07; Phase 11 matches the variable symbol per kind).

9. **Phase 8 report amounts of billed entries.** CONTRIBUTING says Phase 10 snapshots rate and amount and switches `EntryAmounts` to it. A per-entry amount cannot equal the invoice line (rounded per line).
   - Recommendation: snapshot only the hourly rate (minor, currency, source) per billed entry through the transition trigger, switch `EntryAmounts` to it for billed entries, and say on the report that billed amounts follow the billed rate. If the owner prefers a smaller phase, defer and keep the existing note `kokpit.report.notes.computed`.
   - RESOLVED: R-06-2 in plan 10-06, R-09-1 in plan 10-09 and plan 10-34 (recommendation adopted and widened: the rate and also the amount are snapshotted on every billing; the report prices billed entries with the snapshot and falls back to the current rate for entries billed before this phase).

10. **PDF text assertions in CI.** Primary: assert the Blade HTML and structural PDF facts (header, font embedded, page count) which need no external tool; secondary: `gs txtwrite` tests that skip when `gs` is absent.
   - RESOLVED: R-15-5 in plan 10-15, with R-18-2 in plan 10-18 (recommendation adopted: HTML and structural assertions first, Ghostscript text extraction skipped when `gs` is absent).

11. **Resend semantics (UI-SPEC unresolved).** Recommendation: each "send" is a new `invoice_emails` row (new attempt group), the job is idempotent per row, the log tab shows one row per group, and the System slot looks only at the latest row of each invoice.
   - RESOLVED: R-24-1 in plan 10-24, with R-37-1 in plan 10-37 for the stale threshold (recommendation adopted: one `invoice_emails` row per send attempt group; the latest row decides the document state).

12. **How is the internal note edited after issue?**
   - Known: it is one of the two writable fields (D-13) and sits in the Admin-only `invoice_admin` table (Pattern 1).
   - Recommendation: a single `UpdateInternalNote` Action (Admin only, row lock), activity log allowlist without the note text, because free text is never logged in this codebase (see `Document` and `Transaction` allowlists).
   - RESOLVED: R-11-5 in plan 10-11 (recommendation adopted: `UpdateInternalNote` only, row lock, the activity log records the marker and never the text).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV web (PHP) | all tests and tooling | yes | PHP 8.5.8, extensions `gd`, `imagick`, `intl`, `bcmath`, `mbstring`, `sodium` | none needed |
| PostgreSQL | triggers, CHECKs, concurrency tests | yes | 18.6 (`uuidv7()` available) | none |
| Redis / Horizon | queue for e-mail job | yes (DDEV service) | 7 | `QUEUE_CONNECTION=sync` in tests |
| Mailpit (SMTP 1025, UI 8025) | local e-mail check | yes (DDEV) | — | `Mail::fake()` in tests |
| Ghostscript | PDF text assertions | yes in DDEV | 10.05.1 | HTML-level assertions (OQ 10) |
| `pdftotext` | PDF text | no | — | `gs -sDEVICE=txtwrite` |
| `zbarimg` (QR scan) | QR decode | no | — | `chillerlan` reader (`readFromBlob`), round-trip proven |
| ImageMagick `convert` | optional PDF to PNG for the visual gate | yes in DDEV | — | `gs -sDEVICE=png16m` |
| S3-compatible bucket (RustFS) | PDF object storage | yes locally; tests use `MEDIA_DISK=local` plus `Tests\Support\S3TestDisk` | — | local disk |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** `pdftotext` (use `gs`), CI `gs` unverified (A8).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest ^5.3 on PHPUnit ^13.3 (suites Unit, Feature, Arch, Isolation, Concurrency) [VERIFIED: phpunit.xml] |
| Config file | `phpunit.xml`, `tests/Pest.php` (Feature and Isolation use `RefreshDatabase`; Concurrency has none and spawns worker processes) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Invoicing --stop-on-failure` |
| Full suite command | `ddev exec vendor/bin/pest` then `ddev exec vendor/bin/pint --test` and `ddev exec composer stan` and `ddev exec composer check-licenses` |

### Phase Requirements to Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| IN-01 | Separate series; number only at issue; gap-free under 8 parallel issuers incl. failing issues; `UNIQUE (kind, number)` | concurrency + schema | `ddev exec vendor/bin/pest tests/Concurrency/InvoiceIssueConcurrencyTest.php` | no (Wave 0) |
| IN-02 | Create from time (period, projects, grouping per project/task, fixed amount, manual items); two concurrent drafts never share an entry; picker excludes reserved | feature + concurrency | `... tests/Feature/Invoicing/CreateDraftTest.php`, `tests/Concurrency/ReservationConcurrencyTest.php` | no |
| IN-03 | Issue snapshots; raw SQL UPDATE/DELETE/TRUNCATE on issued invoice, items, side tables refused (`KP001`); only internal note, `cancelled_at`, `pdf_document_id` writable; mutation check | schema | `... tests/Feature/Schema/InvoiceTablesTest.php` | no |
| IN-03 | Atomic issue: forced failure after number allocation and after PDF upload leaves counter unchanged, no row issued, no S3 object | feature | `... tests/Feature/Invoicing/IssueRollbackTest.php` | no |
| IN-04 | PDF from snapshot only (change client/settings after issue, regenerate: identical HTML, page count, text); cs and en; Czech diacritics; SPAYD content, QR round-trip; CZK only; credit note without payment box; no vendor font writes | feature + unit | `... tests/Feature/Invoicing/InvoicePdfTest.php`, `tests/Unit/Invoicing/SpaydTest.php`, `DocumentPaletteTest.php` | no |
| IN-04 | E-mail: queued after commit, idempotent claim, failure marks row + one bell, resend creates new row, plain-text, header injection refused, work report attachment optional | feature | `... tests/Feature/Invoicing/SendInvoiceEmailTest.php` | no |
| IN-04 | System slot: counts queued/failed, thresholds, Czech detail, never exception text | feature | `... tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php` (extends `HealthRegistryTest`, which still lists the slot as placeholder) | partial |
| IN-05 | Several payments; warning states; overpayment allowed; sum decides state; overdue derived with bound Prague day; overview and tile equal; partial counts by remaining | feature | `... tests/Feature/Invoicing/PaymentsTest.php`, `ReceivablesReaderTest.php` | no |
| IN-06 | Paid proforma converts: own invoice number, link, entries re-pointed while staying billed, payments copied with `carried_from_payment_id`, income booked with real payment date; unpaid proforma refused; one invoice per proforma | feature | `... tests/Feature/Invoicing/ConvertProformaTest.php` | no |
| IN-07 | Foreign-currency issue stores rate, unit, date and CZK total; missing list blocks; cross-currency `Money` method equals `convert()` for the CZK target | feature + unit | `... tests/Feature/Invoicing/ForeignCurrencyIssueTest.php`, `tests/Unit/Money/MoneyCrossConversionTest.php` | no |
| IN-08 | Non-payer default: no VAT in snapshot/PDF/UI, `vat_minor` 0, taxable-supply date hidden; VAT columns present | feature | `... tests/Feature/Invoicing/NonVatPayerTest.php` | no |
| IN-09 | Partner: only issued own-client rows, no draft/internal note/payments/linked time/CNB/rates anywhere in rendered HTML, snapshots, bell, activity, search; foreign or draft id is 404; PDF route authorises via the scoped query; Admin gets 403 on the Partner resource | isolation | `... tests/Isolation/InvoiceLeakTest.php`, `PartnerSafeColumnsTest.php` additions, `CanaryRegistryTest.php` | partial |
| IN-10 | Delete draft, unlink, cancel, full storno return entries to unbilled and unlocked; partial credit keeps them billed; manual `markBilled` refuses reserved; unlock refuses issued-invoice entries | feature | `... tests/Feature/Invoicing/ReleaseEntriesTest.php`, `CreditNoteTest.php`, extend `tests/Feature/TimeTracking/BillingLockTest.php` | partial |
| CL-03 | Client page shows stats, Projekty, Faktury, Soubory, Účty tabs; Partner gets 403 | feature | `... tests/Feature/Clients/ClientInvoicePanelsTest.php` | no |
| FI-03 | Payment creates income via `RecordTransaction`, system category, editable date/category at entry, amount/currency/invoice locked afterwards; only invoices book income; delete/edit restrictions | feature | `... tests/Feature/Invoicing/PaymentIncomeTest.php`, extend `tests/Feature/Finance/TransactionResourceTest.php` | partial |
| DO-03 (delete PDF) | Explicit confirmation for an issued PDF, object removed after commit, regenerate action | feature | `... tests/Feature/Documents/InvoicePdfDeletionTest.php` | no |
| Arch | Models declare isolation; new jobs `#[Idempotent]`; panel classes `#[AccessRule]`; Livewire scalar props; Brick/rounding confinement | arch | `ddev exec vendor/bin/pest tests/Arch` | existing, lists updated per plan |

### Sampling Rate
- **Per task commit:** the narrow test file(s) of the task (`ddev exec vendor/bin/pest <file>`), plus `ddev exec vendor/bin/pest tests/Arch` when a model, job, resource or widget was added.
- **Per wave merge:** `ddev exec vendor/bin/pest --testsuite=Feature,Isolation,Arch` and `ddev exec composer stan`; Concurrency suite on waves that touch issuing or reservation.
- **Phase gate:** full `ddev exec vendor/bin/pest`, `pint --test`, `stan`, `check-licenses`, `scripts/check-sensitive.sh`, then `/gsd-verify-work`. Human gate items (UI-SPEC "backstop" rows: visual PDF at 3 sizes, 375 px draft table, contrast of accent buttons) go to end-of-phase human verification per `human_verify_mode`.

### Wave 0 Gaps
- [ ] `tests/Support/InvoiceFixtures.php` (builders for draft, issued, paid proforma, credit note, foreign-currency invoice; fictional data only, bank accounts from a runtime IBAN generator, company IDs from `FictionalCompanyId`)
- [ ] `tests/Concurrency/invoice-worker.php` + `InvoiceIssueConcurrencyTest.php` + a mutation twin (`UnlockedSequenceAllocator` already exists; reuse it to prove the harness can fail) + `ReservationConcurrencyTest.php` with a no-CAS mutation
- [ ] `tests/Support/PdfText.php` (runs `gs txtwrite`, skips when absent) and `QrDecode.php` (chillerlan reader)
- [ ] Canary registry lines for `Invoice` and `InvoiceItem` (PartnerIsolated) and the admin-only models; `PgSchema::EXEMPT` entries only if a column genuinely cannot satisfy R1/R2
- [ ] Update `tests/Arch/ModelDeclarationTest.php` model list, `tests/Arch/ActivityAllowlistTest.php` logging models list (its comment says invoices add themselves), `MorphMap::MAP` (`invoice`, `invoice_item`, `invoice_payment`, ...), `DocumentOwnerType::Invoice`
- [ ] Extend `tests/Feature/Repo/RepositoryFilesTest.php` and `CONTRIBUTING.md` hand-over (the repo test fails when a documented class does not exist); both already have uncommitted edits in the working tree, coordinate
- [ ] Framework install: none (Pest present)

## Security Domain

### Applicable ASVS Categories (level 1, `security_block_on: high`)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no change | Existing Admin 2FA and Partner login; the Partner PDF link is user-bound and signed |
| V3 Session Management | no change | Existing |
| V4 Access Control | yes | `#[AccessRule]` on every new class, `KokpitPolicy`, fail-closed `PartnerScope`, Partner-safe table split, 404 for foreign/draft ids, Admin re-check on every Livewire update |
| V5 Input Validation | yes | Actions re-validate everything (item text caps, quantity/price ranges, dates, currency, e-mail fields); server-side totals; Czech field errors keyed by bare data key |
| V6 Cryptography | no new use | Signed URLs use the framework (existing `DocumentUrl` pattern); no custom crypto |
| V8 Data Protection | yes | PDFs and logo on the private disk, `no-store`, nothing sensitive in notifications, activity log allowlist without free text |
| V12 Files and Resources | yes | Logo/signature: PNG/JPEG only, finfo MIME, `getimagesize`, size cap, re-encode through GD, server-generated object names, no SVG; generated PDF uses `AttachDocuments` hardening |
| V14 Configuration | yes | Dompdf closed (`isRemoteEnabled`, `isPhpEnabled`, `isJavascriptEnabled` off), chroot array, `fontDir` outside vendor |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Item text or party name injecting HTML/CSS into the PDF (file read, SSRF via `url()`/`<img>`) | Tampering, Information disclosure | Blade `{{ }}` only, remote and PHP off, chroot, data-URI images only, canary test |
| Client tampers with totals, prices or currency in a Livewire payload | Tampering | Server recomputes every line and total; DB CHECK on `round(quantity x unit_price)`; Action ignores client totals |
| IDOR on invoice id, PDF link or e-mail log | Information disclosure | Re-read through the scoped query; Partner PDF via scoped Invoice; signed route bound to user id; 404 not 403 |
| Double billing / double numbering by races | Tampering | CAS reservation, row locks, sequence lock, unique `(kind, number)`, concurrency tests with mutation twins |
| Raw SQL or package code editing issued data | Tampering | Guard triggers (`KP001`), no app role superuser, truncate guards |
| E-mail header/body injection through editable subject or recipient | Tampering | E-mail validation rule, CR/LF stripped, plain-text body, length caps |
| Partner learns of drafts, rates or finance from counts, bells, search, activity | Information disclosure | No draft rows or counts for a Partner; invoice resources not searchable; notifications carry the number only; allowlisted activity |
| Mass assignment of `state`, `number`, `total_minor`, `client_id` | Tampering | `forceFill` in Actions only; models carry a minimal `#[Fillable]` |
| Stored object left after rollback or delete | Repudiation / hygiene | `DB::afterRollBack` cleanup at issue; D-23 `DeleteDocument` for deletion |
| Logo upload (polyglot, SVG with script, decompression bomb) | Tampering | Raster only, dimension and size caps, GD re-encode, never served raw |

## Sources

### Primary (HIGH confidence)
- Codebase files read this session (paths cited inline): `app/Domain/Shared/Sequences/SequenceAllocator.php`, `app/Domain/Shared/Database/Immutability.php`, `database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php`, `database/migrations/2026_10_11_000100_create_time_entries_table.php`, `app/Domain/Shared/Money/Money.php`, `app/Domain/Settings/Numbering/*`, `app/Domain/Settings/Settings/*`, `app/Domain/Settings/Banking/*`, `app/Domain/TimeTracking/Actions/{MarkEntriesBilled,CancelEntriesBilling}.php`, `app/Domain/Reports/{EntryAmounts,Queries/TimeReportReader,WorkReport/*}.php`, `app/Domain/Documents/**`, `app/Domain/Finance/**`, `app/Domain/Operations/Jobs/KokpitJob.php`, `app/Filament/Pages/Dashboard.php`, `app/Http/Controllers/DocumentFileController.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Concurrency/*`, `.planning/phases/10-invoicing/10-CONTEXT.md`, `10-UI-SPEC.md`, `.planning/STATE.md`, `.planning/REQUIREMENTS.md`, `CONTRIBUTING.md` Phase 10 hand-over note.
- Executed this session: Dompdf 3.1.6 + Roboto TTF render with Czech text and QR (visual check); `Options::getFontDir()` default; `chillerlan/php-qrcode` encode/decode roundtrip and `Version::AUTO` requirement; PostgreSQL 18.6 `round()` semantics; Ghostscript `txtwrite` extraction in DDEV; Laravel `MessageSelector` Czech plural rule; `DB::afterRollBack` presence.
- `composer.lock` / `vendor/composer/installed.json` (versions and licences); Packagist API (latest versions).

### Secondary (MEDIUM confidence)
- [qr-platba.cz SPAYD specification](https://qr-platba.cz/pro-vyvojare/specifikace-formatu/) (fetched this session)
- [cdom-cz/tools `src/lib/invoice.ts`](https://raw.githubusercontent.com/cdom-cz/tools/main/src/lib/invoice.ts) (palette rules, fetched this session) and the `public/fonts` listing (WOFF only)
- [googlefonts/roboto-3-classic](https://github.com/googlefonts/roboto-3-classic) (OFL-1.1, `OFL.txt`); release `v3.011` asset used for the spike
- Czech invoicing practice (search results, not statute text): [fakturoid.cz náležitosti faktury](https://fakturoid.cz/almanach/zacatky-podnikani/nalezitosti-faktury), [pruvodcepodnikanim.cz](https://www.pruvodcepodnikanim.cz/clanek/jak-vystavit-fakturu/), [money.cz](https://money.cz/podnikani/by-mela-obsahovat-faktura-zalezi-tom-jestli-platce-dph), [pohoda.cz zálohová faktura](https://portal.pohoda.cz/dane-ucetnictvi-mzdy/ucetnictvi/co-byste-meli-vedet-o-zalohove-fakture/)

### Tertiary (LOW confidence)
- None used as a basis for a recommendation; the legal field list is flagged A1.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nothing new to install, everything version-checked in the lock file.
- Architecture: HIGH for seams that were read or executed; MEDIUM for the new schema and trigger shapes (A4), which are recommendations the planner finalises and the schema tests prove.
- Pitfalls: HIGH where reproduced (QR version, fontDir, afterRollBack, generated columns); MEDIUM where reasoned (font install race, CI `gs`).
- Czech legal content: MEDIUM-LOW (secondary sources), isolated in A1.

**Research date:** 2026-10-16
**Valid until:** 2026-11-15 (stable domain; re-check Filament and Dompdf minor versions before execution)
