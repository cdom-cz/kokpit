# Feature Research

**Domain:** Self-hosted freelancer / small-company CRM + ERP (clients -> projects -> tasks -> time -> invoice -> payment), Czech invoicing conventions, non-VAT-payer supplier, client-account (Partner) restricted view
**Researched:** 2026-10-06
**Confidence:** MEDIUM overall. Czech invoicing rules: MEDIUM-HIGH (law 563/1991 Sb., civil code s.435, corroborated by Fakturoid, iDoklad, Pohoda, Money.cz guides). SPAYD: HIGH for field set (Wikipedia + Czech Banking Association format, multiple libraries), MEDIUM for edge rules. Competitor behaviour (hosted CRM/ERP tools, Harvest, Toggl, Clockify, Jira): MEDIUM, from product knowledge plus general search; competitor pages were not exhaustively scraped. Stripe Payment Link edge cases and VAT-threshold figures: MEDIUM, verify in the phase that implements them.

Scope note: the functional scope is fixed by the brief (PROJECT.md Active). This file does not re-litigate it. It (a) classifies each area into table stakes / differentiators / anti-features, (b) pins down Czech invoicing details, (c) lists what the brief omits that will hurt v1, and (d) gives dependencies and complexity for roadmap ordering.

---

## Feature Landscape

### Table Stakes (Users Expect These)

Grouped by feature area. "Brief" = already in PROJECT.md Active; "GAP" = not (or only implicitly) in the brief and should be made explicit.

#### Clients and contacts

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Client record: name, company ID (IČO), tax ID (DIČ, optional), billing address, country | Every Czech invoice tool; needed as customer snapshot on invoices | LOW | Brief. Keep billing address separate from "contact" address only if cheap; one structured address is enough for v1 |
| ARES lookup by IČO (name, address, DIČ, legal form) | Fakturoid/iDoklad and hosted CRM/ERP tools all do it; saves typing and typos | MEDIUM | Brief. Synchronous call with short timeout, graceful failure to manual entry. Only on create/refresh, never on invoice render |
| Per-client invoice defaults: currency, invoice language (cs/en), payment term days, default hourly rate | Tool must not make you retype these per invoice | LOW | Brief (currency, language). GAP: payment term days and default rate belong here too |
| Contacts per client with e-mail/phone/role; flag "invoice recipient" | Invoice e-mail needs recipient(s) and CC | LOW | GAP (recipient flag + multiple recipients). Without it invoice e-mail has no defined target |
| Tags, search, archive (not delete) | Basic CRM hygiene; clients with invoices must never be hard-deleted (accounting docs kept 5 yrs, tax docs 10 yrs) | LOW | Brief (tags). GAP: archive instead of delete, restrict delete when invoices/time exist |
| Client account (Partner user) invite + password reset | Portal is useless without a working onboarding path | MEDIUM | Brief ("client accounts"). Needs invite e-mail, set-password flow, link contact -> user, multiple Partner users per client |

#### Projects

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Project with client, key (Jira-style), status (active/on hold/archived), dates | Harvest/Jira baseline | LOW | Brief. Key frozen after first task |
| Billing type: hourly / fixed price / non-billable (+ optional retainer later) | Decides how invoice is produced from the project | MEDIUM | Brief (billing type, rates, fixed price, estimate). Fixed price still needs an invoicing path that does NOT consume time entries (see Invoicing) |
| Rate resolution order (task/entry override -> project -> client -> user/default) with snapshot at billing | Disputes over rates are the #1 time-billing support issue | MEDIUM | Brief ("snapshots for rates"). Define the precedence once, test it |
| Client visibility flag per project | Drives Partner view | LOW | Brief |
| Estimate (hours or amount) vs actual | Harvest "budget" is table stakes in time tools | LOW | Brief (estimate); the comparison widget is a differentiator below |

#### Tasks and kanban

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Task with key `KEY-N`, title, description (rich text/markdown), status, priority, due date, assignee | Jira-lite baseline | MEDIUM | Brief. Per-project counter must be gap-free under concurrency (same mechanism as invoice numbers) |
| Subtasks, to-do checklist, comments with internal flag | Brief; internal-flag is the key Partner-safety primitive | MEDIUM | Brief. Internal flag must be filtered in every channel: UI, API, notification e-mails, activity log |
| List view with filters (project, client, status, priority, assignee, due, tag) and sorting | Where users actually live; kanban alone is not enough | MEDIUM | Brief. spatie/query-builder fits |
| Kanban per project and global, drag-and-drop persists status and order | Jira/Trello expectation | MEDIUM-HIGH | Brief. Needs stable ordering (eloquent-sortable or fractional index), optimistic UI, concurrency-safe move, Partner cannot move cards |
| File attachments on tasks | Jira and hosted project-management tools baseline; people paste screenshots into tasks | LOW-MEDIUM | GAP (brief has documents module; make explicit that tasks attach via medialibrary and reuse the central listing) |
| Notifications: new task/comment from Partner -> admin; non-internal admin reply -> Partner (e-mail, queued; in-app bell for admin) | A client-facing request channel that does not notify anyone is a silent failure | MEDIUM | GAP, important. Partner "creates/comments" per brief assumption but nothing alerts the admin. Minimal version: e-mail on Partner-created task or comment; e-mail to Partner on non-internal admin comment |
| Task status fixed set (e.g. Backlog / To do / In progress / Review / Done / Cancelled) | Brief assumption 3 | LOW | Brief. Keep enum, not configurable columns |

#### Time tracking

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Always-visible timer (start/stop), attach to project (task optional, description) | Toggl/Harvest/Clockify core | MEDIUM | Brief. One running timer per user enforced in DB (partial unique index), synchronous |
| Start timer directly from a task card / task page | The Jira-lite value proposition: time lives where the work is | LOW | Implied; make explicit |
| Manual entries (date, start/end or duration), edit, delete | Everyone forgets the timer | LOW | Brief |
| Consistency rules: end after start, no future-dated end, no overlapping entries per user (or explicit allow), max duration sanity | Harvest/Clockify have overlap handling; billing needs trustworthy data | MEDIUM | Brief ("consistency rules"). Decide overlap policy once (recommend: reject overlap, allow override flag off by default) |
| Billable flag per entry (defaults from project) | Required for "billable vs billed" | LOW | Implied by brief report; make explicit |
| Timesheet view (week grid or day list with totals) | Harvest/Clockify baseline; the place to review before invoicing | MEDIUM | Brief ("timesheet") |
| Billed entries locked (edit/delete blocked, enforced in DB/policy) | Invoice immutability | MEDIUM | Brief. Needs defined unlock path when a draft invoice is deleted or a credit note cancels it |
| Forgotten/long-running timer protection (banner after N hours; hard cap warning) | Real-world data hygiene; Toggl has idle detection | LOW-MEDIUM | GAP, small. Minimum: highlight in the always-visible bar when running > N hours; scheduler e-mail is a differentiator |

#### Time API

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Sanctum tokens with abilities, `/api/v1`, OpenAPI docs, rate limiting | Brief | MEDIUM | Brief. Token management UI for admin (create/revoke, show once) |
| Endpoints: timer start/stop/current; time entries list/create/update/delete | Brief | MEDIUM | Brief |
| Read-only `projects` and `tasks` lookup endpoints (id, key, name, client name, status; archived filter) | A time client (CLI, shortcut, git hook, menu bar app) cannot pick a project without them | LOW | GAP, high value. Without lookup the API is unusable for its stated purpose |
| `Idempotency-Key` header on create/start/stop | Flaky mobile/CLI clients retry; duplicate entries or double stop are the common bug | MEDIUM | GAP. Cheap if designed in from the start |
| Same consistency rules and billed-lock as UI, consistent error format, pagination, `updated_since` filter | Parity avoids two code paths with different rules | LOW-MEDIUM | Share the domain action classes between Filament and API |

#### Exchange rates (CNB)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Daily scheduled download of CNB rates, idempotent per date+currency, manual backfill, failure alert | Brief | LOW-MEDIUM | Brief |
| Handle non-publishing days (weekends, Czech holidays): use latest rate on or before the date; store rate date alongside | CNB publishes on working days only; invoices are issued on weekends | LOW | Pitfall-adjacent; define lookup function once |
| Per-unit normalisation (some currencies are quoted per 100 units, e.g. HUF, JPY) | Wrong by 100x otherwise | LOW | Store `amount` column from the CNB file and divide |
| Rate snapshot on invoice (rate, rate date, source) | Brief ("snapshots for exchange rates") | LOW | Czech accounting requires CZK conversion at the rate valid on the date of the accounting event (CNB daily rate is the common choice) |

#### Reports and dashboard

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Time per client / project / period (and per user for future-proofing), billable vs billed vs unbilled | Brief | MEDIUM | Brief |
| CSV/XLSX export of time and report tables | Brief | LOW-MEDIUM | Brief. Stream; do not build in request for large ranges |
| Work report PDF (výkaz práce) per client/project/period | Czech clients routinely expect an hours statement attached to the invoice | MEDIUM | Brief. Must be attachable to the invoice e-mail and tied to the same entries as the invoice (snapshot) |
| Dashboard: running timer, hours today/week/month, unbilled amount, unpaid and overdue invoices, income this month/year, tasks due | A "cockpit" is the product name; also carries the Core Value alerts | MEDIUM | Brief (dashboard). GAP: explicitly include unbilled amount and overdue invoices; they are the Core Value |
| Receivables list with aging (not due / 1-30 / 31-60 / 60+) | Every invoicing tool has an "unpaid" view | LOW | GAP (small): the overdue filter on invoice list plus one widget |

#### Documents

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Upload to private S3-compatible storage, link to client/project/task/expense/invoice | Brief (medialibrary) | MEDIUM | Brief. Authorised download via short-lived signed URLs only |
| Central listing with search/filter, ZIP download, bulk delete | Brief | MEDIUM | Brief. Build ZIP in a queued job, notify when ready (memory/timeouts) |
| Per-document "visible to client" flag | Partner view of documents must be opt-in | LOW | GAP: the brief does not say whether Partner sees documents at all. Recommend: default hidden, explicit share flag, Partner download-only |
| Upload limits and MIME allowlist | Baseline security on a public product | LOW | GAP |

#### Finance

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Income and expense transactions with categories, date, amount, currency, CZK equivalent | Brief | MEDIUM | Brief |
| Income auto-created from a paid invoice / matched payment (no manual double entry), and linked back | A paid invoice that does not show as income breaks the flow promise | MEDIUM | Brief ("payment -> income"), but make the linkage rule explicit |
| Monthly/yearly overview in CZK (income, expenses, net) | Brief | LOW-MEDIUM | Brief |
| Attach receipt/document to expense | Czech freelancers hand receipts to an accountant | LOW | Falls out of documents module |
| Cash-basis recognition date for income (payment date), not invoice date | Czech sole traders on tax records (daňová evidence) are cash-basis | LOW | Decision to document; matters for yearly overview |

#### Invoicing (detail in the dedicated section below)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Invoice with line items (description, quantity, unit, unit price, total), due date, currency, notes | Obvious | MEDIUM | Brief |
| Gap-free number sequences allocated at issue time, per document type | Czech accounting expects an unbroken numbered series | MEDIUM-HIGH | Brief. See pitfall: allocate on issue, not on draft creation |
| PDF generation (cs/en) with Czech diacritics, supplier snapshot, SPAYD QR | Brief | MEDIUM-HIGH | Brief |
| E-mail invoice with PDF attached (and work report optional), queued, sent log | Brief | MEDIUM | Brief |
| Proforma (zálohová faktura), credit note (opravný doklad / dobropis), conversion proforma -> invoice | Brief | MEDIUM-HIGH | Brief |
| Manual payment recording incl. partial payment, payment date, method | Brief ("manual payment") | MEDIUM | Brief. Recommend a `payments` table (1:N to invoice) from day one, needed anyway for Stripe duplicates/unmatched |
| Invoice statuses: draft, issued/sent, partially paid, paid, overdue (derived), cancelled (via credit note) | Brief implies | LOW-MEDIUM | Overdue should be derived from due date + balance, not a stored state |

#### Stripe

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Payment Link generated per invoice/proforma for the exact amount and currency, shown on PDF/e-mail/Partner view | Brief | MEDIUM | Brief |
| Signature-verified webhooks, idempotent event handling, automatic matching to invoice | Brief | MEDIUM-HIGH | Brief (spatie/webhook-client) |
| Duplicate-payment and unmatched-payment handling with a visible "needs attention" list | Brief | MEDIUM | Brief |
| Payments overview | Brief | LOW-MEDIUM | Brief |
| Deactivate link when invoice is paid manually, cancelled, or credited | Stale links = double payment | LOW-MEDIUM | GAP, small but important |

#### Partner (client account) view

See dedicated section "Client-portal permission expectations" below.

#### Platform / foundation

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Admin 2FA (TOTP) | The instance holds financial data and is internet-facing | LOW | GAP: Filament ships MFA support in current versions; enable it |
| Supplier profile in settings: legal name, address, company ID, registry statement, bank accounts per currency (IBAN, BIC), logo, e-mail/phone, default invoice footer, default payment term | Source of the supplier snapshot on every invoice | MEDIUM | GAP (brief says "settings" generically). Phase must define this before invoicing |
| Editable invoice e-mail templates (cs/en) | Users always want to change the wording | LOW-MEDIUM | GAP; start with fixed good defaults + a few placeholders, editing can be v1.x |
| Activity log, queue, scheduler, failure alerts | Brief | MEDIUM | Brief |
| Demo/seed data (fictional) and first-run setup wizard (supplier profile, first number sequence) | Self-hosters need a path from empty install to first invoice | LOW-MEDIUM | GAP; fits the open-source goal |

---

### Czech invoicing requirements (non-VAT-payer supplier)

Confidence: MEDIUM-HIGH. Sources: law 563/1991 Sb. (accounting), civil code s.435 (business document identification), Fakturoid/iDoklad/Pohoda/Money.cz guides. Not legal advice; the project should state in docs that the maintainer is not an accountant.

#### What a non-VAT-payer invoice is, legally

- A non-VAT-payer invoice is an **accounting document**, not a "tax document" (daňový doklad). No DUZP, no VAT breakdown, no "daňový doklad" label. Retention: accounting documents 5 years, tax documents 10 years; keep issued PDFs for at least 5 years and never regenerate old ones from changed templates (store the PDF generated at issue time).
- Practical consequence for the brief's "VAT-payer mode data model prepared": keep nullable columns for DIČ, tax rate per line, taxable-supply date, but render nothing VAT-related in v1.

#### Mandatory / expected fields

| Field | Status | Notes |
|-------|--------|-------|
| Document designation ("Faktura") | Required | Label differs per type: Faktura / Zálohová faktura (proforma) / Opravný doklad (credit note) |
| Document number (unique, sequential) | Required in practice; strongly expected | Brief: `{YYYY}{NNNN}` e.g. `20260001`. Separate series for proforma and credit notes with a distinguishing prefix |
| Supplier: name (or trade name), registered address / place of business, company ID (IČO) | Required (s.435 civil code, accounting law) | Snapshot at issue |
| Supplier registry statement: natural person "registered in the trade register" / company "registered in the Commercial Register kept by [court], section X, file Y" | Required for businesses by s.435 civil code | Store as free-text field in supplier profile; pre-fill sensible default text |
| Statement "not a VAT payer" ("Neplátce DPH") | Voluntary but expected by clients | Print by default, toggle in settings; fixes confusion with clients' accountants |
| Customer: name, address, IČO (if any), DIČ (if any) | Required (parties to the transaction) | Snapshot at issue |
| Issue date | Required | |
| Date of supply (datum uskutečnění plnění) | Required by accounting law when it differs from issue date; harmless to always print | Default = issue date; editable on draft |
| Due date | Not mandatory by law (default 30 days under civil code) but universally expected | Default from client/payment term |
| Description of the performance + quantity + unit + unit price + line total | Required (content of the accounting case) | For time-based lines: description + hours + hourly rate; hours shown as decimal and/or h:mm |
| Total amount, currency | Required | Integer minor units + ISO 4217 |
| Payment method and bank account (CZ account number + bank code, and IBAN/BIC) | Expected (not formally mandatory) | Per-currency supplier accounts |
| Variable symbol (VS) | Expected by Czech bank payment flow; in practice mandatory for matching | Default = digits of invoice number; max 10 digits, numeric only; `20260001` (8 digits) fits |
| Signature or stamp | Not required for electronically issued invoices | Do not build; optional signature image is a nice-to-have |
| Issuer name ("Vystavil") and contact | Customary | Optional |
| CZK equivalent and CNB rate when invoice is in foreign currency | Required for the books (CZK conversion); customary on the invoice | Print "1 EUR = x.xx CZK (CNB, date)" and CZK total as a note; the snapshot is stored |
| Constant symbol (KS), specific symbol (SS) | Legacy/optional | Optional fields; do not default |

Rounding: totals in Czech invoices to bank are normally exact to haléř (0.01). Rounding to whole CZK is an optional "haléřové vyrovnání" line; recommend off by default and only as an explicit per-invoice rounding line, never silently.

#### Variable symbol and number format

- VS must be numeric and at most 10 digits. `{YYYY}{NNNN}` yields 8 digits and works as VS directly. If the format is ever changed (prefixes with letters), VS must be derived separately, so store VS as its own column from the start.
- Proforma and invoice must have different VS, otherwise payment matching (Stripe metadata, bank transfers, future bank import) is ambiguous. Recommend distinct number series (e.g. proforma `{YYYY}9{NNN}` or a letter-prefixed number with a numeric VS series).
- Stripe matching should use metadata/`client_reference_id` (invoice UUID), not VS parsing, but print the VS for bank-transfer payers.

#### QR payment (SPAYD / "QR platba")

Format: `SPD*1.0*KEY:VALUE*KEY:VALUE...`, `*` is the delimiter, values percent-encoded for non-ASCII and for `*`.

| Key | Meaning | Notes |
|-----|---------|-------|
| `ACC` | Recipient account as IBAN (optionally `IBAN+BIC`) | Required; max 46 chars. Compute IBAN from prefix-number/bank code or store IBAN directly and validate mod-97 |
| `AM` | Amount | Dot as decimal separator, 2 decimals, max 10 chars |
| `CC` | Currency, ISO 4217 | |
| `X-VS` | Variable symbol | Numeric, max 10 digits |
| `X-KS` / `X-SS` | Constant / specific symbol | Optional |
| `DT` | Due date `YYYYMMDD` | Optional |
| `MSG` | Message | Max 60 chars; keep ASCII (strip Czech diacritics) to avoid scanner issues |
| `RN` | Recipient name | Max 35 chars |
| `CRC32` | Checksum | Optional |

Illustrative payload (placeholder IBAN, fictional): `SPD*1.0*ACC:CZ0000000000000000000000*AM:12000.00*CC:CZK*X-VS:20260001*DT:20260131*MSG:INVOICE 20260001`

Rules and judgement calls:
- Print the QR on every invoice/proforma that has a payable balance in a currency for which the supplier has a matching account. For CZK invoices to a Czech account it is universally supported. For EUR invoices only print the QR if the supplier configured a EUR-capable IBAN; always print IBAN + BIC as text for foreign payers (the SPAYD QR is essentially Czech/Slovak-only).
- Do not print QR on credit notes (nothing to pay) or on fully paid invoices.
- Generate the QR as SVG/PNG embedded in the PDF; test with real banking apps (manual QA step), because encoding bugs only show up in the scanner.
- The Stripe payment link/QR is separate: "Pay by card" link next to "Pay by bank transfer (QR)".

#### Proforma -> invoice (non-VAT-payer simplification)

- A proforma (zálohová faktura) is **not an accounting or tax document** for a non-VAT-payer; it is a payment request with no legal required format. It creates no income by itself.
- Expected flow: issue proforma (own number, header "Zálohová faktura / Proforma", note "This is not a tax document"), client pays it (bank or Stripe), supplier issues the final invoice with same items, marked as paid by advance (date = payment date), linked to the proforma. Income is recognised when the payment arrives; make sure the finance module does not count both proforma payment and final invoice.
- For a non-VAT-payer there is no advance-settlement VAT mechanics (that complexity belongs to VAT-payer mode, deferred). The final invoice shows "Paid by proforma YYYY... : X, to pay: 0".
- "Convert to invoice" must copy lines, link both ways, allocate the invoice number at conversion, and carry the payment over (no second payment entry). Converting an unpaid proforma is allowed (client-requested invoice instead).
- Proforma can be cancelled (status) without a credit note because it was never an accounting document. Keep it numbered; never delete.

#### Credit notes (opravný doklad / dobropis)

- For a non-VAT-payer the legal framework is an **opravný účetní doklad** (corrective accounting document); many tools still call it "dobropis". Czech practice for non-payers also allows a "storno faktura" (negative copy). The brief chooses credit notes: keep that, with clear naming in Czech UI ("Dobropis / opravný doklad") and the original invoice number referenced.
- Requirements: own number series, references original invoice(s), negative or positive-with-credit semantic (pick one and keep it consistent in DB, PDF and reports; recommend stored positive amounts with `type = credit_note` and signed effect computed in reporting), reason text, full or partial credit, immutable once issued, never delete or edit the original.
- Effects to define: reduces receivable of the original invoice; if the original was paid, the credit creates a refundable balance (refund itself is manual and out of scope: Stripe refunds are in the Stripe dashboard; record a manual outgoing payment/expense); deactivates any unused Stripe link; shows in finance as negative income in the period of issue.
- Unlock policy for billed time entries after a full credit note: decide explicitly. Recommendation: entries stay locked as billed (history), and the user re-bills by adding manual lines; or provide an explicit "release entries" action when crediting in full. Do not silently unlock.

#### E-invoicing / formats (low priority)

- ISDOC (Czech XML invoice format) is what Czech accounting software (Pohoda, Money, iDoklad) imports; useful to hand an accountant. LOW confidence on any legal e-invoicing mandate for B2B small suppliers in 2026; not required for v1. Treat ISDOC export as a P2 differentiator.
- Czech VAT registration threshold (turnover 2,000,000 CZK over 12 months from 2025, MEDIUM confidence, verify): a "turnover vs threshold" indicator is a cheap, high-value differentiator for a non-VAT-payer-first tool (see Differentiators).

---

### Time-to-invoice billing flow (Core Value path)

What users expect, derived from Harvest, hosted CRM/ERP tools, Fakturoid + Toggl-to-invoice practice:

1. **Entry point:** "Bill unbilled time" from client or project page and from the dashboard widget "Unbilled: 42 h / 63,000 CZK (Client X)".
2. **Selection:** list of unbilled, billable, completed (not running) entries for the client, filter by project and date range, preselected all, individually deselectable. Show totals before committing.
3. **Grouping into lines:** per project (default), per task, per day, or one line per entry; free-text description template. Quantity = exact duration; amount computed from exact seconds x rate, rounded **once per line** to minor units (not per entry, not from rounded hours), to avoid pennies mismatch. Show hours as `h:mm` and decimal.
4. **Rate and currency:** rate resolved per precedence and snapshotted; if project currency differs from client invoice currency, block or convert explicitly (do not guess); fixed-price projects do not consume entries (invoice is manual lines/percentage of fixed price, optionally via proforma).
5. **Draft invoice:** editable lines (brief: "invoice item editable manually"), but each line retains link to source entries. Entries are **reserved** by the draft (cannot be put on a second draft); deleting the draft releases them.
6. **Issue:** allocate number (gap-free, in a transaction), freeze snapshots (supplier, customer, rates, CNB rate), render and store PDF, lock entries as billed, generate Stripe payment link, optionally generate work-report PDF.
7. **Send:** e-mail with invoice PDF (+ optional work report), copy to self; status = sent; Partner (if account exists) sees it in the portal.
8. **Follow-up:** unpaid -> overdue badge on due date, dashboard counter, reminder (manual button v1, scheduled reminder differentiator); payment (Stripe webhook or manual) -> paid, income auto-created, entries/projects show "billed and paid".
9. **Safety nets (Core Value: "nothing slips through"):** dashboard and report of unbilled time older than N days; per-client unbilled total; warning when issuing an invoice that leaves billable entries of the same period unbilled; overdue invoices count in the top bar.

Expected variants (v1 should handle the first three):
- Hourly time-based invoice (default)
- Fixed-price invoice or milestone/percentage via proforma
- Manual free-form invoice (no time)
- Expense re-billing (pass-through costs) — v1.x; the model should not forbid an invoice line referencing an expense
- Retainer / recurring monthly invoice — v1.x (see gaps)

---

### Client-portal permission expectations (Partner)

Same Filament panel, enforced by Policies and global scopes (brief). Expected capabilities and hard walls:

| Area | Partner CAN | Partner CANNOT |
|------|-------------|----------------|
| Clients | See own client record basics (company name, own contacts) | Any other client; tags/internal notes; client rates/currency settings; ARES internals |
| Projects | List and open projects with `visible_to_client = true` for own client, see name, key, status, description | Rates, billing type, fixed price, estimate/budget, hours, internal projects, other clients' projects |
| Tasks | See tasks of visible projects; create tasks; comment; see non-internal comments; attach files | Change status/priority/assignee (brief assumption 4); see internal comments; see time spent; delete; reassign |
| Kanban | View (read-only) board of visible projects, own client only | Drag cards; global board across clients |
| Time | Nothing | Timer, entries, timesheet, reports, work-report generation, API tokens |
| Documents | Download documents explicitly flagged "visible to client" and attached to own client/projects/invoices | Central document listing of everything, bulk delete, other clients' documents, internal receipts |
| Invoices | See **issued** invoices and proformas of own client, download PDF, see status (unpaid/overdue/paid), "Pay by card" Stripe link, QR/bank details | Drafts, credit notes of other clients, finance transactions, internal notes, payments overview, Stripe dashboard data |
| Finance | Nothing | Income/expense, overview, categories |
| Settings | Own profile and password, language, notification preferences | Any admin setting, user list, roles, activity log |

Cross-cutting leak vectors the audit phase must cover (these are the usual real-world portal leaks, not UI-hiding issues):
- Global search results and Filament relation managers returning hidden records or counts.
- Livewire/Filament hydrated payloads exposing hidden model attributes (rates, internal flags) even when not rendered.
- E-mail notification bodies (internal comment text, rates), activity-log entries, widgets/dashboard stats, exports.
- Direct URL/UUID guessing (UUID does not protect; only Policies do), API endpoints, signed document URLs shared across clients, medialibrary conversions and temporary URLs.
- Partner-created task defaults (must not set priority/assignee/internal flag or be able to submit them via crafted requests: mass assignment).
- Invoice PDF for Partner must be the stored issued PDF, not re-rendered with different data.

Expectation level: Harvest and hosted CRM/ERP portal-style (clients see their invoices, tasks and shared files, can pay). Not expected: client-side time approval, budgets, client-editable tasks beyond comments/creation, multiple permission tiers within Partner.

---

### Differentiators (Competitive Advantage)

Aligned with Core Value: "tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed."

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| One-pass bill-from-time wizard with reservation, rate snapshots and automatic Stripe link + work report | Collapses the multi-step flow of Harvest and hosted CRM/ERP tools into one screen; the product's reason to exist | MEDIUM-HIGH | Core Value; P1 |
| "Slip-through" alerts: unbilled time older than N days, overdue invoices, unmatched/duplicate Stripe payments, failed CNB download | Directly implements the Core Value; most competitors only list, they do not nag | MEDIUM | P1 for dashboard widgets and a daily digest e-mail to admin; P2 for configurable thresholds |
| Stripe Payment Link per invoice with DB-enforced idempotent matching and auto-paid status | Czech tools mostly rely on bank-statement matching; card payment closes the loop instantly | MEDIUM-HIGH | Brief |
| Time API (token abilities, OpenAPI, idempotency) so any tool can track into Kokpit | Open source + self-hosted users script everything | MEDIUM | Brief |
| Self-hosted, AGPL, no per-seat pricing, one-company-per-instance | Positioning vs hosted CRM/ERP and Fakturoid SaaS | n/a | Positioning, not a feature |
| Project profitability: fixed-price effective hourly rate, estimate vs actual burn | Freelancers on fixed price rarely know real rate; data already exists | LOW-MEDIUM | P2 |
| VAT-threshold monitor (rolling 12-month turnover vs limit) for non-VAT-payers | Missing the threshold is a real legal risk for the target user | LOW-MEDIUM | P2; income data already in finance; verify current threshold figure at implementation |
| Scheduled overdue reminders (templated, cs/en, one or two escalation steps) | Fakturoid/iDoklad have it; automation beats manual chasing | MEDIUM | P2 (v1 offers a manual "send reminder" button) |
| Recurring invoices / retainers | Fakturoid and hosted CRM/ERP parity; common for hosting/maintenance work | MEDIUM-HIGH | P2; see gaps |
| Accountant export: month/year ZIP of issued invoice PDFs + CSV of invoices and payments (+ optional ISDOC) | Czech freelancers send a monthly pack to their accountant | MEDIUM | P2; documents ZIP infra and finance data exist |
| Forgotten-timer notifications (e-mail/push after N hours) | Fewer garbage entries | LOW | P2 |
| Multi-currency awareness end to end (client currency, CNB snapshot, CZK finance) | Czech tools often bolt this on; here it is designed in | MEDIUM | Brief |
| Partner "pay now" in portal with live status | Reduces friction for the client; Harvest-like | LOW-MEDIUM | Falls out of Stripe + Partner view |

---

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Rounding time entries up to 15/30 min | Harvest/Toggl have it | Contradicts brief ("exact, no rounding"); creates disputes and breaks seconds-integrity | Per-line amount computed from exact seconds, once rounded to minor unit; invoice line editable manually |
| Bank-statement import / Fio-style auto-matching in v1 | Czech tools do it, VS exists | Explicitly out of scope (bank integration); large surface (formats, partial payments) | Manual payment + Stripe webhook; keep VS a first-class column so v2 matching is easy |
| Real VAT-payer mode in v1 (DPH breakdown, DUZP, advance settlement, control statement, tax returns) | "What if I register for VAT" | Out of scope; doubles invoicing complexity and legal risk | Keep nullable columns/snapshots; separate milestone |
| Full accounting (ledger, chart of accounts, tax returns) | "ERP" in the name | Out of scope; legal liability | Finance overview + accountant export |
| Configurable kanban columns/statuses and custom workflows | Jira habit | Brief assumes fixed statuses; per-project workflows multiply Partner permission rules | Fixed enum; revisit only on demand |
| Separate branded client portal / white-label | "Looks professional" | Out of scope; Partner uses the same panel | Same panel with restricted resources, company logo on invoices/e-mails |
| Client time approval, client-visible hours and rates in portal | Harvest-style transparency | Violates hard rule (Partner never sees time/rates/prices) | Admin controls what the client sees via the attached work-report PDF |
| Stripe refunds/disputes/fees inside app | "One place for money" | Out of scope; webhook states explode | Stripe dashboard; record manual refund transaction |
| Team features: multiple staff users, per-user rates, approval workflows, capacity planning | Toggl/Harvest are team-oriented | One admin is the primary user; permission matrix explodes | Keep `user_id` on entries (future-proof) but no team UI |
| Invoice editing after issue / delete of issued invoices | "I made a typo" | Breaks immutability and number series (and Czech accounting) | Credit note + new invoice; drafts are freely editable |
| Rich in-app invoice template designer | Branding wishes | Large UX/engineering cost | One good Blade/PDF template, logo, footer text, accent colour setting |
| Live chat / client messaging beyond task comments | Portal temptation | Duplicates comments, adds moderation | Task comments + e-mail notifications |
| In-app backup, GDPR tooling suite | "Complete product" | Out of scope (DB provider backups) | README guidance |
| Gantt, dependencies, resource planning | Hosted project-management tools offer Gantt | Jira-lite should stay lite | List + kanban; revisit with demand |

---

## Feature Dependencies

```
Foundation (auth, roles, settings, UUIDv7, queue, scheduler, S3)
    ├──requires──> Supplier profile + bank accounts (settings)
    │                  └──required by──> Invoice snapshot + PDF + SPAYD QR
    ├──requires──> Clients + Contacts
    │                  ├──requires──> ARES lookup (enhances)
    │                  └──requires──> Client accounts (Partner users)
    │                                     └──requires──> Partner policies + global scopes (audit last)
    └──requires──> Projects
                       └──requires──> Tasks (per-project counter)
                                          ├──enhances──> Kanban
                                          └──enhances──> Time entries (task link, timer from task)

Time entries
    ├──requires──> Timer (one running per user, DB-enforced)
    ├──requires──> Rate resolution (task/project/client/default)
    ├──enables──> Time API (same domain actions)
    ├──enables──> Reports/dashboard (billable vs billed)
    └──enables──> Bill-from-time wizard

CNB rates ──requires──> scheduler + idempotent job
    └──required by──> Foreign-currency invoices (CZK snapshot), finance overview in CZK

Number sequences (gap-free, concurrency-safe)
    ├──required by──> Task numbers KEY-N
    └──required by──> Invoice / proforma / credit note numbers

Invoicing
    ├──requires──> Clients, Supplier profile, Number sequences, CNB (if foreign currency)
    ├──requires──> Immutability rules (issue = freeze snapshots, store PDF, lock time)
    ├──requires──> Payments table (1:N) ──required by──> manual payment, Stripe, partial/duplicate
    ├──proforma ──converts-to──> invoice (links payment, no double income)
    ├──credit note ──references──> invoice; ──deactivates──> Stripe link
    └──enhances──> Work report PDF (attachment), Documents (stored issued PDF)

Stripe
    ├──requires──> Issued invoice (immutable amount/currency) + payments table
    └──requires──> Webhook client with signature + dedupe ──produces──> payment ──creates──> income (Finance)

Finance (transactions/categories)
    └──consumes──> payments (income), expenses + receipts (documents)

Partner view
    ├──requires──> Clients/Projects visibility flag, Tasks internal-comment flag
    ├──requires──> Invoices (issued only), Documents "client visible" flag
    └──audit──requires──> every other module finished (leak tests across all screens and API)

Notifications (GAP) ──enhances──> Tasks/comments (Partner requests), Invoices (send, reminders)
Recurring invoices (GAP) ──requires──> Invoicing + scheduler (defer to v1.x)
Accountant export (GAP) ──requires──> Invoicing + Documents ZIP + Finance (defer)
```

### Dependency Notes

- **Invoicing requires payments table before Stripe:** the brief asks for duplicate and unmatched payment handling; those states cannot be modelled on a single `paid_at` column. Build `payments` (invoice_id nullable for unmatched, source, external id unique, amount, currency, status) with manual recording first, add Stripe second.
- **Number sequences are shared infrastructure:** implement one locking mechanism (row lock on a sequence row or `SELECT ... FOR UPDATE`, in the same transaction as the insert) and use it for `KEY-N` and invoice numbers; test with concurrent workers. Allocate invoice numbers on **issue**, not on draft creation, otherwise deleted drafts create gaps.
- **Rate resolution requires time entries and projects first:** the billing wizard depends on a stable precedence and on snapshotting at issue.
- **Bill-from-time requires issue-time locking:** time-entry immutability (billed flag, FK to invoice line, unique so an entry can be billed once) must exist before the wizard.
- **Credit note requires Stripe link deactivation** and decisions on time-entry unlock and refund recording.
- **Partner audit depends on everything:** schedule it last, but write Policies and global scopes **with each module**, not at the end; the audit then only verifies.
- **CNB requires nothing but must precede foreign-currency invoices:** otherwise issue is blocked for non-CZK clients. Can ship before invoicing.
- **Documents module enhances invoicing:** stored issued PDFs and work reports live on S3 via medialibrary; build documents before invoice PDF storage.
- **Notifications conflict with nothing but touch all modules:** introduce the notification channel early (foundation) so Partner task/comment events can plug in.
- **Time API conflicts with duplicating logic:** it must call the same actions as the Filament timer, otherwise rules diverge.

---

## What the brief likely misses (hurts v1 if skipped)

Ranked by damage. "Cheap" means <= 1-2 days of work when designed in at the right phase.

| # | Gap | Why it hurts | Cost to fix later | Recommendation |
|---|-----|--------------|-------------------|----------------|
| 1 | Overdue invoice detection, dashboard counter, manual "send reminder" | Core Value says no unpaid invoice slips through; the brief lists no overdue handling | Low | Add to Invoicing phase (derived overdue, filter, widget, reminder e-mail button) |
| 2 | Unbilled-time alert/widget (value per client, age) | Core Value; brief only has a "billable vs billed" report | Low | Dashboard widget + daily admin digest |
| 3 | Payments table (1:N), partial payments | Single `paid` flag cannot model duplicates, partials, unmatched Stripe payments | HIGH (schema migration later on immutable data) | Design in at the Invoicing phase |
| 4 | Supplier profile and per-currency bank accounts with IBAN validation | Snapshot, PDF, SPAYD all depend on it; brief only says "settings" | Medium | Specify as an explicit Foundation/Invoicing prerequisite, include first-run wizard |
| 5 | Numbers allocated at issue; draft vs issued separation; reservation of time entries by drafts | Without it, gaps appear and two drafts bill the same time | HIGH | Decide before invoice schema is written |
| 6 | Read-only projects/tasks endpoints in the API (+ idempotency keys) | The time API is unusable by clients and unreliable when retried | Low if early | Add to API phase |
| 7 | Notifications (admin on Partner activity; Partner on non-internal replies; daily digest) | Portal requests would be missed silently; part of the Core Value spirit | Medium | Introduce notification channel in Foundation, wire per module |
| 8 | Stored immutable issued PDF + stored work report | Regenerating from templates changes historical documents; 5-year retention | Medium | Store at issue in documents (S3) |
| 9 | Stripe link hygiene: single-use/limited completion, deactivate on paid/cancel/credit, test vs live mode keys | Reusable links lead to duplicate payments | Low | Include in Stripe phase acceptance criteria |
| 10 | Admin 2FA | Financial data on public internet | Low | Enable Filament MFA in Foundation |
| 11 | Document visibility to Partner (explicit flag) | Brief silent; default must be hidden | Low | Add column + policy in Documents phase |
| 12 | Archive instead of delete (clients, projects) with FK restriction | Deleting a client with invoices breaks history and retention duties | Low | Soft-delete/archived status; DB RESTRICT |
| 13 | Recurring invoices (retainers, hosting) | Fakturoid and hosted CRM/ERP parity; many freelancers have monthly recurring items | Medium | Defer to v1.x but keep invoice-create action callable from a job |
| 14 | Expense re-billing to client | Common for freelancers (domains, licences) | Medium | Defer; model allows an invoice line referencing an expense |
| 15 | Invoice e-mail templates editable; reminder templates | Users always tweak wording | Low | Defaults in v1, editor v1.x |
| 16 | Public/secured invoice link for clients without a Partner account | Many clients will never log in | Medium | Not needed if PDF is attached and Stripe link works; skip in v1 |
| 17 | Idle timer notification / max-duration warning | Data hygiene | Low | Banner in v1, e-mail v1.x |
| 18 | Global search (clients, projects, tasks, invoices) with Partner-safe scoping | Daily-use basics; but also a leak vector | Low-Medium | Use Filament global search, test in Partner audit |
| 19 | Demo data and first-run setup flow | Open-source adoption | Low | Fictional seeders |
| 20 | CZK equivalent on foreign-currency invoices and per-unit CNB handling | Czech accounting rule; easy to get wrong | Low | Part of CNB + Invoicing phases |
| 21 | Foreign client invoicing note: EU B2B service invoices by a non-VAT-payer may require "identified person" status; text on invoice varies | Legal edge, not an app feature | n/a | Document as a known limitation in README; no code |

---

## MVP Definition

### Launch With (v1)

Everything in the brief's Active list qualifies as the product (the brief is already the MVP by the owner's intent). Within it, the following are the **non-negotiable cores** and the additions needed to make the brief coherent:

- [ ] Foundation: auth, Admin/Partner roles, settings with supplier profile and bank accounts, MFA, notification channel, activity log, queue/scheduler, number-sequence service — everything else rests on these
- [ ] Clients/contacts with ARES, archive-not-delete, invoice defaults — invoice snapshot source
- [ ] Projects with billing types, rates, visibility flag — drives billing and Partner view
- [ ] Tasks with KEY-N counter, comments (internal flag), list view, Partner notification e-mails
- [ ] Kanban per project/global — Jira-lite promise
- [ ] Time tracking: timer, manual entries, consistency rules, timesheet, billed lock, long-timer warning
- [ ] Time API incl. projects/tasks lookup and idempotency keys
- [ ] CNB rates with fallback to last published and per-unit handling
- [ ] Documents with Partner-visible flag, central listing, ZIP job
- [ ] Invoicing: draft/issued split, numbers on issue, bill-from-time wizard, PDF cs/en with SPAYD QR, e-mail with PDF + work report, manual and partial payments, proforma -> invoice, credit notes, overdue derivation, stored issued PDFs
- [ ] Stripe: payment links (single-use), signed idempotent webhooks, matching, duplicate/unmatched queue, link deactivation, payments overview
- [ ] Finance: income (auto from payments), expenses with receipts, monthly/yearly CZK overview
- [ ] Dashboard + reports with unbilled/overdue alerts, CSV/XLSX, work report PDF
- [ ] Partner view + automated leak/isolation test suite across screens and API

### Add After Validation (v1.x)

- [ ] Scheduled overdue reminders (templated, escalation) — once manual reminder usage shows demand
- [ ] Recurring invoices / retainers — first retainer client needs it
- [ ] Editable e-mail/PDF text templates — after first real invoices reveal wording changes
- [ ] Accountant export pack (ZIP of PDFs + CSV, ISDOC optional) — first accounting period close
- [ ] Forgotten-timer e-mail notifications
- [ ] Expense re-billing line type
- [ ] Project profitability widgets (fixed-price effective rate, estimate burn)
- [ ] VAT-threshold monitor

### Future Consideration (v2+)

- [ ] VAT-payer mode (calculations, DUZP, PDF template, advance settlement) — brief defers; data model ready
- [ ] Bank statement import / matching by VS — bank integration outside focus now
- [ ] Multi-user team features (per-user rates, approvals) — product is single-admin by design
- [ ] ISDOC/Peppol e-invoice delivery — only if legal need or accountant demand emerges
- [ ] Public invoice link/portal for non-account clients

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Foundation + number sequences + supplier profile | HIGH | MEDIUM | P1 |
| Clients/contacts + ARES | HIGH | LOW-MEDIUM | P1 |
| Projects + rate resolution | HIGH | MEDIUM | P1 |
| Tasks (list, comments, internal flag) | HIGH | MEDIUM | P1 |
| Kanban | MEDIUM-HIGH | MEDIUM-HIGH | P1 (brief) |
| Timer + manual entries + timesheet | HIGH | MEDIUM | P1 |
| Billed lock + reservation | HIGH | MEDIUM | P1 |
| Time API (+ lookup, idempotency) | MEDIUM-HIGH | MEDIUM | P1 |
| CNB rates | MEDIUM-HIGH | LOW-MEDIUM | P1 |
| Documents on S3 | MEDIUM | MEDIUM | P1 |
| Bill-from-time wizard | HIGH | MEDIUM-HIGH | P1 |
| Invoice PDF + SPAYD + e-mail | HIGH | MEDIUM-HIGH | P1 |
| Payments table + manual/partial payment | HIGH | MEDIUM | P1 |
| Proforma -> invoice, credit note | MEDIUM-HIGH | MEDIUM-HIGH | P1 (brief) |
| Overdue + unbilled alerts, manual reminder | HIGH | LOW-MEDIUM | P1 (gap) |
| Stripe links + webhook + matching | HIGH | MEDIUM-HIGH | P1 (brief) |
| Finance transactions + CZK overview | MEDIUM | MEDIUM | P1 (brief) |
| Reports/dashboard/exports/work report PDF | HIGH | MEDIUM | P1 |
| Notifications (Partner/admin) | MEDIUM-HIGH | MEDIUM | P1 (gap) |
| Admin MFA | MEDIUM | LOW | P1 (gap) |
| Partner view + isolation audit | HIGH | MEDIUM-HIGH | P1 |
| Scheduled reminders | MEDIUM | MEDIUM | P2 |
| Recurring invoices | MEDIUM | MEDIUM-HIGH | P2 |
| Accountant export | MEDIUM | MEDIUM | P2 |
| Profitability widgets | MEDIUM | LOW-MEDIUM | P2 |
| VAT-threshold monitor | MEDIUM | LOW-MEDIUM | P2 |
| Editable templates | LOW-MEDIUM | LOW-MEDIUM | P2 |
| ISDOC export | LOW-MEDIUM | MEDIUM | P3 |
| Bank import | MEDIUM | HIGH | P3 |
| VAT-payer mode | MEDIUM | HIGH | P3 |

**Priority key:**
- P1: Must have for launch
- P2: Should have, add when possible
- P3: Nice to have, future consideration

---

## Competitor Feature Analysis

Confidence MEDIUM (product knowledge + general search; not a full feature audit).

| Feature | Harvest | Toggl / Clockify | Fakturoid / iDoklad (Czech invoicing) | Our Approach |
|---------|---------|------------------|----------------------------------------|--------------|
| Projects/tasks | Projects with tasks as billing categories only | Projects and tasks (light) | None | Jira-lite: KEY-N, kanban, subtasks, comments, no Gantt |
| Time tracking | Best-in-class timer, timesheet, reminders | Best-in-class timer, idle detection, integrations | Fakturoid has simple time-to-invoice import in some plans | Timer + timesheet + API; exact seconds, no rounding |
| Time -> invoice | One-click invoice from tracked time, grouping options | Invoicing in paid tiers (Clockify) / none (Toggl) | Import of hours as items (limited) | One-pass wizard with reservation, rate snapshots, work report |
| Czech invoice conventions | No (US-centric) | No | Yes: VS, QR, ARES, proforma, credit note, ISDOC, bank matching | Yes: SPAYD QR, VS, proforma/credit note, ARES, non-VAT-payer first |
| Payments | Stripe/PayPal | none | Bank pairing, card gateway (Fakturoid), reminders | Stripe Payment Links + webhook; no bank pairing v1 |
| Reminders | Auto reminders | n/a | Automatic dunning | Manual in v1, scheduled in v1.x |
| Client portal | Invoice viewing/paying link | Reports share links | Public invoice link | Partner accounts in same panel; invoices, tasks, shared files |
| Finance overview | Expenses | none | Expenses, tax views for OSVČ | Income/expense overview in CZK, no tax calculations |
| API | Public REST API | Public API | Public API (Fakturoid) | Time-only API, token abilities |
| Pricing | SaaS per seat | Freemium | SaaS | Self-hosted AGPL |

Competitor observations that shape scope:
- Harvest's strongest idea is **tracked time -> invoice in a few clicks**; this is exactly the Core Value, so polish there beats breadth elsewhere.
- Czech invoicing tools win on **QR, VS, ARES, dunning, accountant export**; the first three are in the brief, the last two are the P2 list.
- Toggl/Clockify win on timer ergonomics and integrations; the always-visible timer and the API are the answer, with idempotency to match their robustness.
- The weak spot of hosted CRM/ERP tools (and the reason this exists) is likely cost/hosting/control; the self-hosted angle is positioning, not features.

---

## Sources

- [Fakturoid: invoice requirements (náležitosti faktury)](https://fakturoid.cz/almanach/zacatky-podnikani/nalezitosti-faktury) — non-VAT-payer mandatory fields, accounting vs tax document, 5 vs 10 years retention (MEDIUM-HIGH)
- [Money.cz: invoice requirements for VAT payers and non-payers](https://money.cz/podnikani/by-mela-obsahovat-faktura-zalezi-tom-jestli-platce-dph) (MEDIUM)
- [iDoklad: what must not be missing on invoices](https://www.idoklad.cz/blog/co-nesmi-chybet-na-vasich-fakturach-zalezi-na-tom-jestli-jste-platci-dph) (MEDIUM)
- [Pruvodce podnikanim: how to issue an invoice, plus proforma and storno guides](https://www.pruvodcepodnikanim.cz/clanek/jak-vystavit-fakturu/) (MEDIUM)
- [Pruvodce podnikanim: storno of invoice and corrective tax document](https://www.pruvodcepodnikanim.cz/clanek/storno-faktury-a-opravny-danovy-doklad/) — non-payers use storno/corrective accounting document (MEDIUM)
- [Fakturoid support: proforma invoice](https://www.fakturoid.cz/podpora/faktury/zalohova-faktura) and [iDoklad: proforma invoices](https://www.idoklad.cz/podpora/zalohove-faktury) — proforma is not an accounting/tax document for non-payers (MEDIUM-HIGH)
- [Wikipedia: Short Payment Descriptor](https://en.wikipedia.org/wiki/Short_Payment_Descriptor) — SPAYD fields and limits (HIGH for field set)
- [Czech Banking Association QR format as published by KB](https://www.kb.cz/getmedia/35265715-fe8e-4df9-9212-beaf625ba417/Klientsky-format-pro-QR-platbu-v-KB-pdf.pdf) (HIGH, not re-read in full)
- Law references (not fetched, standard knowledge, verify when implementing): act no. 563/1991 Sb. on accounting, civil code s.435 on business-document identification, VAT-registration threshold of 2,000,000 CZK (from 2025; MEDIUM)
- Project context: `.planning/PROJECT.md`

---
*Feature research for: self-hosted freelancer CRM/ERP with Czech invoicing (Kokpit)*
*Researched: 2026-10-06*
