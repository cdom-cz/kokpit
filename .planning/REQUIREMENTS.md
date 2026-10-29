# Requirements: Kokpit

**Defined:** 2026-10-06
**Core Value:** Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases. Original brief IDs are noted in brackets where they exist.

### Repository Hygiene

- [x] **HYG-01**: `.gitignore` excludes env files, local AI/IDE settings, storage, logs, dumps, exports and a local-data directory, and is reviewed every phase
- [x] **HYG-02**: `scripts/check-sensitive.sh` fails on non-example e-mails, 8-digit company-ID-like numbers, IBAN/account numbers, public IPs, hosting hostnames and key prefixes in staged files
- [x] **HYG-03**: The script additionally reads a local denylist from a path in `KOKPIT_DENYLIST` (never committed) and still runs with generic patterns only when it is absent (CI)
- [x] **HYG-04**: A pre-commit hook managed by lefthook (versioned `lefthook.yml`, installed by `scripts/install-hooks.sh`) runs the sensitive-content check and gitleaks on staged changes
- [x] **HYG-05**: `.gitleaks.toml` with generic extra rules, and the same checks as a CI step scanning full history
- [x] **HYG-06**: `CLAUDE.md` and CONTRIBUTING document the rule: no client names, prices, rates, production data or real data in planning docs, docs or commits; fictional data only
- [x] **HYG-07**: GitHub secret scanning with push protection and a review procedure (`git status`, `git diff --staged`, script run) are documented

### Foundation

- [x] **FND-01**: Application installs and runs from README and `.env.example` alone on Laravel (latest stable for PHP 8.5), PostgreSQL, one Filament SPA panel for both roles
- [x] **FND-20**: Local development environment is DDEV: a versioned `.ddev/config.yaml` (PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS as S3-compatible service, queue worker and scheduler as DDEV daemons/commands, no instance-specific values or secrets) so `ddev start` plus the README steps give a working app; tests and CI use the same PHP and PostgreSQL versions
- [x] **FND-02**: All primary keys, foreign keys and morph columns (own and package tables: media, tags, activity log, permission pivots, Sanctum tokens, webhook calls, sessions) are UUID v7 with schema tests that fail on non-UUID keys
- [x] **FND-03**: All timestamps are `timestamptz` stored in UTC and shown in `Europe/Prague`; morph map is enforced
- [x] **FND-04**: Money is stored as integer minor units plus ISO 4217 currency, through a single value object with one documented rounding point
- [x] **FND-05**: One sequence allocator provides gap-free, duplicate-free numbers inside the caller's transaction, proven under real parallel-process tests on PostgreSQL
- [x] **FND-06**: Roles Admin and Partner via permissions package; Policies and global query scopes enforce access (default-deny for Partner), not only UI hiding
- [x] **FND-07**: Typed settings hold company/supplier data, bank accounts per currency, VAT mode, default rate and currency, payment terms, numbering patterns, online-payment toggle
- [x] **FND-08**: Activity log with an explicit attribute allowlist; changes to tasks, projects, invoices and time entries are recorded
- [x] **FND-09**: Queue (Redis driver) worker and scheduler run; jobs share a base with retries, backoff, idempotence guidance and visible failure (failed jobs plus Admin alert not dependent on the queue)
- [x] **FND-10**: Admin health/System page shows failed jobs, oldest pending job, scheduler heartbeat, last rate date, unprocessed webhooks and unsent invoice e-mails
- [x] **FND-11**: All user-facing text is Czech via `lang/cs` (incl. Filament and validation translations, enum labels); default locale `cs`, Czech date/number/currency formats
- [x] **FND-12**: DB constraints and immutability triggers pattern (FK, unique/partial indexes, CHECK) with a raw-SQL test helper
- [x] **FND-13**: CI runs tests on PostgreSQL, static analysis, formatting, secret scan and a dependency licence allowlist (AGPL-compatible only)
- [x] **FND-14**: `LICENSE` AGPL-3.0 with matching `composer.json` license, README, SECURITY.md, CONTRIBUTING.md and `.env.example` kept in sync
- [x] **FND-15**: `zerops.yml` describes build, deploy, worker, scheduler and migrations without secrets; deploy workflow runs only on release publish or manual dispatch in a protected `production` environment with all hardening rules from the brief; manual GitHub settings checklist documented
- [x] **FND-16**: S3-compatible private storage (RustFS in development; any S3-compatible provider in production) configured by environment only, path-style capable, with a smoke test of upload and temporary URL
- [x] **FND-17**: Admin account is created by an install command (no default password); Admin two-factor authentication is available
- [x] **FND-18**: Partner isolation test harness (two fictional clients with canary strings) exists from Foundation and grows each phase; a registry test fails if any Resource, Page, Widget or relation manager lacks an explicit access rule
- [x] **FND-19**: Spikes resolve PDF engine (multi-page report with Czech diacritics and QR), kanban library vs custom board (queue driver Redis, PostgreSQL 18 and RustFS are already decided)

### Users

- [x] **US-01**: Roles Admin and Partner behave as specified in the permission matrix of the brief
- [x] **US-02**: Admin manages users and creates a client account (Partner) from the client detail with an e-mail invitation

### Clients

- [x] **CL-01**: Admin manages clients with billing data, stage, currency, rate, payment terms, invoice e-mail and language, online-payment flag
- [x] **CL-02**: Admin manages multiple contacts per client with primary and billing flags
- [x] **CL-03**: Client detail shows projects, unbilled time and amount, invoices, documents and client accounts
- [x] **CL-04**: Admin loads client data from ARES by company ID via a synchronous button in the form; errors show next to the field and leave the form unchanged
- [x] **CL-05**: Clients are archived (soft delete) rather than hard-deleted; tags are available on clients

### Projects

- [x] **PR-01**: Admin manages projects (name, status, description, dates, priority, files, tags)
- [x] **PR-02**: Each project has a unique 2-6 letter uppercase key suggested from the name; the key is frozen after the first task
- [x] **PR-03**: Projects have billing type, hourly rate, fixed price and time estimate; rates and prices live where Partner cannot read them
- [x] **PR-04**: Projects have a client-visibility flag controlling Partner access
- [x] **PR-05**: Project detail shows tasks, estimate vs actual, billed vs unbilled (Admin only)

### Tasks

- [x] **TA-01**: Tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags and files
- [x] **TA-02**: Task numbers `KEY-N` come from one per-project counter allocated in a locked transaction and never recycled; keys are searchable and usable in URL and API
- [x] **TA-03**: Todo checklist on tasks and subtasks
- [x] **TA-04**: Comments on tasks and subtasks, optionally internal; Partner comments are never internal and Partner never sees internal comments or their attachments
- [x] **TA-05**: List view with filters (client, project, status, priority, assignee, tag, due date)
- [x] **TA-06**: Fixed price, billing type, rate override and time estimate on task level
- [x] **TA-07**: Partner can create and comment on tasks in visible projects but cannot change status or priority; Admin is notified of Partner tasks and comments

### Kanban

- [x] **KB-01**: Kanban per project and global with columns by status and card order by position
- [x] **KB-02**: Drag and drop changes status and position synchronously; global view filters by client, assignee, tag, priority
- [x] **KB-03**: Partner has no board manipulation and sees a read-only task list

### Time Tracking

- [x] **TI-01**: A timer is visible throughout the app and can be started from a task in at most two clicks
- [x] **TI-02**: Manual creation and editing of entries (from, to, client, project, task, description)
- [x] **TI-03**: Entries can have only a client (no project or task); client is always required
- [x] **TI-04**: Billable flag defaults to true and is pre-set to false for non-billable projects/tasks; user can override
- [x] **TI-05**: Entries can be marked billed manually, in bulk, or automatically by invoicing; billed entries are locked until billing is cancelled
- [x] **TI-06**: Daily/weekly timesheet with totals
- [x] **TI-07**: Consistency rules enforced in the database: task/project/client agree, at most one running timer per user, end after start; starting a timer stops the running one
- [x] **TI-08**: Time is stored exactly in seconds without rounding; rate resolution is task, project, client, global default; snapshot of rate and amount on billing
- [x] **TI-09**: A forgotten long-running timer is flagged to the user

### API

- [x] **AP-01**: REST API `/api/v1` with Sanctum tokens carrying abilities (`time:read`, `time:write`); only Admin creates tokens
- [x] **AP-02**: Time entries list with filters, detail, create, update, delete; same domain actions and rules as the UI
- [x] **AP-04**: Read-only lookups of clients, projects and tasks (UUID, key, name); tasks addressable by key
- [x] **AP-05**: Token management UI, rate limiting, idempotency key support, OpenAPI documentation

### Exchange Rates

- [x] **EX-01**: Daily scheduled, idempotent download of CNB rates, manual run and history backfill; weekend/holiday uses previous rate; per-unit amounts handled
- [x] **EX-02**: Overview of rates and Admin alert when download fails; last known rate is used meanwhile
- [x] **EX-03**: Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots

### Reports

- [x] **RE-01**: Time report per client and project for a period with breakdown by task, day and user
- [x] **RE-02**: Billable/non-billable and billed/unbilled split with amounts in client currency and CZK
- [x] **RE-03**: CSV/XLSX export (queued, delivered by notification) and PDF work report attachable to an invoice
- [x] **RE-04**: Dashboard: today/this week hours, unbilled per client, overdue invoices, unresolved payments, upcoming task deadlines
- [x] **RE-05**: Effective hourly rate shown for fixed-price projects

### Documents

- [ ] **DO-01**: Files uploaded to clients, projects, tasks, comments, invoices, transactions or unattached on S3; Partner sees only files on accessible projects/tasks, excluding internal-comment attachments, and only those flagged visible
- [ ] **DO-02**: Admin central listing with filters, sorting, single and ZIP download (queued), bulk delete, storage totals overall and per client
- [ ] **DO-03**: Downloads always go through the app with permission check and short-lived signed URL; deleting a record removes the S3 object; issued invoice PDFs need explicit confirmation to delete

### Finance

- [x] **FI-01**: Income and expense transactions with hierarchical categories, currency, CZK amount, optional client/project/invoice, receipts as attachments
- [ ] **FI-02**: Overview per month/year, per category and per client in CZK
- [x] **FI-03**: Payment of an invoice creates an income transaction (automatically for Stripe; editable date and category for manual payment)

### Invoicing

- [x] **IN-01**: Invoices, proformas and credit notes with separate number series, number allocated only on issue
- [x] **IN-02**: Draft created from unbilled billable time (client, period, projects; grouping per project or task; fixed sums added) plus manual items; items editable; entries reserved so two drafts cannot bill the same time
- [x] **IN-03**: Issue freezes number, kind and the issue snapshots (supplier, customer, bank, rate, amounts); an issued invoice or proforma stays editable until a successful e-mail send locks it, a credit note is locked at issue, and number and kind never change, enforced in the database
- [x] **IN-04**: PDF generated at issue from snapshots only, stored in documents, with SPAYD QR for CZK bank transfer, in the client's invoice language; sent by queued e-mail with optional work report
- [x] **IN-05**: Manual payment (transfer, cash) recorded in a payments table; warning if already paid; overdue status derived; overdue overview
- [x] **IN-06**: Proforma converts to invoice with a link after payment
- [x] **IN-07**: Invoicing in client currency with CNB rate snapshot and CZK total
- [x] **IN-08**: Non-VAT-payer mode is the default and fully tested; data model prepared for VAT payer
- [x] **IN-09**: Partner sees own issued invoices and proformas (list, detail, PDF, pay button) read-only, never drafts, internal notes, linked time or rates
- [x] **IN-10**: Cancelling or deleting a draft, or storno, returns linked entries to unbilled
- [x] **IN-11**: An issued invoice or proforma is locked only by a successfully sent document e-mail; queued, pending or failed e-mails, payments, credit notes and storno never lock it
- [x] **IN-12**: Admin unlocks a locked invoice or proforma with a mandatory reason; the unlock is audited and the document stays editable until the next successful send locks it again
- [x] **IN-13**: While unlocked, Admin edits items, dates, customer and supplier snapshots, bank account, currency, language and linked time of an issued invoice or proforma; totals are recomputed with the single rounding rule, and totals below what was paid or credited warn instead of blocking
- [x] **IN-14**: Saving an edit regenerates and replaces the stored PDF with its QR code and refreshes the card payment link when amount or currency change; nothing is sent automatically
- [x] **IN-15**: Every change to an issued document after its first send, every lock and every unlock is audited with who, when, the values before and after and the unlock reason; a bank account change is audited by a non-reversible keyed fingerprint of the account (the fingerprints before and after, who and when) instead of the account values, so the account number never enters the history

### Payments (Stripe)

- [x] **PA-01**: Single-use Stripe Payment Link created by queued job at issue when online payment is enabled; failure never blocks issue and alerts Admin
- [x] **PA-02**: "Pay by card" button in e-mail, PDF and invoice detail (Admin and Partner); hidden when paid
- [x] **PA-03**: Online payment can be disabled per client and per document
- [x] **PA-04**: Link deactivated on payment by any route or on storno; link can be regenerated (old one deactivated first)
- [x] **PA-05**: Webhook endpoint verifies Stripe signature, stores event with unique event id and answers immediately; processing runs in a job
- [x] **PA-06**: Matching by invoice id (fallback link id) with amount and currency check creates payment, marks invoice paid, creates income, deactivates link, notifies Admin
- [x] **PA-07**: Second payment of the same invoice is stored as duplicate without income or invoice change and alerts Admin; repeated events are ignored; idempotence enforced by DB unique constraints
- [x] **PA-08**: Unmatched payments and refund events are recorded and alerted; Admin payments overview with duplicate/unmatched filters and manual resolution; old webhook events are pruned

### Payment Method and Currency

- [x] **P14-01**: A draft, invoice or proforma has an exclusive payment method `transfer` or `online`, default `transfer`; a credit note has none
- [x] **P14-02**: `transfer` prints the bank box, variable symbol and QR (CZK with a CZ IBAN only); `online` prints none of them, only the pay button or link and the amount and due facts
- [x] **P14-03**: Several bank accounts may share a currency; each account has a stable id; a document stores the chosen account separately from its currency
- [x] **P14-04**: Setting or changing the currency preselects the first account of that currency; any account may be chosen instead; with no match nothing is preselected and issuing a `transfer` document is blocked
- [x] **P14-05**: The currency choices are the CNB currencies plus account currencies and the client currency (plus the current and CZK), without duplicates
- [x] **P14-06**: Method, account and currency are editable on an open issued document and frozen once it is locked
- [x] **P14-07**: A method or currency change deactivates the live Stripe link and, when card payment is still offered, queues a new one in the document currency; the pay URL stays the same
- [x] **P14-08**: A currency change is refused once a payment is recorded; a method or account change is still allowed
- [x] **P14-09**: `online` requires card payment to be available (global setting on, Stripe configured, client flag on) and blocks issuing otherwise

### Cash Payments and Cash Receipts

- [x] **P15-01**: A draft, invoice or proforma may have the payment method `cash` as a third value next to `transfer` and `online`; a credit note still has none; the default of a new draft stays `transfer`
- [x] **P15-02**: A cash document prints no bank box, account, QR, variable symbol or pay button, and stores no bank snapshot (enforced by a database check)
- [x] **P15-03**: Cash excludes card payment: the card switch is off and locked, no Stripe link exists, and the pay URL shows the information page
- [x] **P15-04**: The method is chosen in the draft and changed while the issued document is open; a change to cash deactivates the live Stripe link
- [x] **P15-05**: Every recorded cash payment (any document method, invoice or proforma) issues one cash receipt document (PPD) in the payment transaction; carried copies of a proforma payment issue none
- [x] **P15-06**: A PPD has its own gap-free number series with a configurable pattern; it carries no variable symbol and no QR
- [x] **P15-07**: A PPD is editable until the successful confirmation send locks it; Admin unlocks it with a recorded reason; number, kind, amount and currency never change
- [x] **P15-08**: The PPD PDF carries the content the Accounting Act requires of an accounting document, is built from snapshots in the client's language, is stored, and is regenerated when the PPD is edited
- [x] **P15-09**: The PPD PDF is attached to the payment confirmation e-mail, and the successful send locks the PPD
- [x] **P15-10**: A payment with a PPD cannot be deleted before the PPD is cancelled with a reason; the PPD number is never reused
- [x] **P15-11**: Admin sees, downloads, edits and cancels PPDs in the invoice detail; Partner sees and downloads the PPDs of the own non-draft documents read-only, enforced by policy and scope
- [x] **P15-12**: A cancelled PPD keeps its number and PDF, is marked cancelled and is audited

### Per-Client E-mail Sending and Manual Lock

- [x] **P16-01**: Every existing and every new client has the switch "send documents by e-mail" on by default; the Admin edits it in the client form and sees it in the client detail with a badge when it is off; it is not a column of the client list
- [x] **P16-02**: A change of the switch is audited with who, when, the old and the new value
- [x] **P16-03**: A document e-mail (invoice, proforma, credit note) to a client with the switch off is refused by the domain, and no e-mail log row is written
- [x] **P16-04**: A payment of a client with the switch off gets its payment confirmation row as `skipped` and no send job is queued; the payment itself is never blocked
- [x] **P16-05**: A document e-mail or payment confirmation still queued when the switch goes off is not sent: the job re-checks after its claim, marks the row `skipped` and locks nothing; switching on again revives no row
- [x] **P16-06**: `skipped` rows count as neither failed nor unsent in the System page indicator, the failed-e-mail bell, the list rows and the detail, and the indicator ignores the documents of clients with the switch off
- [x] **P16-07**: For a client with the switch off, "Odeslat e-mailem" and "Odeslat znovu" are hidden in the detail header, the list row and the Odeslání row, the detail shows a gray note, and the send dialog does not open after an issue
- [x] **P16-08**: The Admin locks an issued, open invoice or proforma by hand for any client without a reason; the lock is refused for a draft, a credit note, a locked or cancelled document and a converted proforma; it is audited, number, kind and snapshots stay unchanged, and a later edit after an unlock is audited like one after a send
- [x] **P16-09**: The Admin locks an open, uncancelled cash receipt (PPD) by hand with the same rules
- [x] **P16-10**: Unlock stays Admin-only with a mandatory reason; the lock literals stay confined to named files (the architecture tests are extended on purpose); the database row guards are unchanged
- [x] **P16-11**: System e-mails to the Partner users of the client (invitation, password reset, task notifications) are unaffected by the switch

### Task, Project and Search UX Refinements

- [x] **P17-01**: The checklist card of the task form spans the full width of the form
- [x] **P17-02**: Rich-text content (task descriptions, comments, any rich-text field) is rendered with its formatting in read-only output on every surface of both panels, through the one sanitiser (sanitized, whitelisted HTML); e-mails and PDFs are unchanged
- [x] **P17-03**: The Admin creates a task from the project view with the project pre-filled and locked; the action is not offered for a Done or archived project
- [x] **P17-04**: In the global search, tasks and projects that are not Done come first inside their category; the category order and the display are unchanged
- [x] **P17-05**: The Admin adds a time entry from the task view with client, project and task pre-filled and locked, under the existing time-entry rules
- [x] **P17-06**: A task can be hidden from the kanban boards (shown by default); only the boards hide it and only the Admin changes it
- [x] **P17-07**: The Signal add field offers unfinished tasks by type-ahead and a pick stores the text `{PROJECT}: {task name} [{task code}]` linked to the task; a cross clears the pick, and a linked signal shows the shared timer control and an open-task icon
- [x] **P17-08**: Image files open in a single-image lightbox and non-image files in a new tab in every file list of both panels
- [x] **P17-09**: The project pickers of task creation (Admin and Partner) offer only projects that are not Done
- [x] **P17-10**: The project status can be changed from the project view as it can on the task view

### Partner Audit

- [x] **AUD-01**: Automated tests prove Partner never sees time, rates, prices, finance, internal comments or other clients' data in UI, search, selects, exports, notifications, activity log and API, for every entity
- [x] **AUD-02**: Route and screen audit confirms every non-public route is authenticated and every Resource, Page, Widget and API endpoint has an explicit role rule

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Billing Extensions

- **BIL-01**: Retainers and prepaid hour packages
- **BIL-02**: Recurring invoices
- **BIL-03**: Scheduled payment reminders
- **BIL-04**: Rate history with effective dates
- **BIL-05**: Time budget alerts at 80% and 100% of estimate

### Payments Extensions

- **PAY-01**: Stripe refunds and fee tracking in the app
- **PAY-02**: Bank statement matching

### Platform

- **PLT-01**: VAT-payer mode (calculations, PDF template, reverse charge)
- **PLT-02**: ISDOC export
- **PLT-03**: Project and task templates, recurring tasks
- **PLT-04**: Custom workflow statuses per project
- **PLT-05**: Outgoing webhooks and API for tasks
- **AP-03**: API timer: start, stop and current running (removed from Phase 7 on 2026-10-14; kept in ROADMAP.md Backlog as 999.1)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Mobile/desktop app | Web only, responsive |
| Separate client portal with own look | Partner uses the same Filament panel |
| Full accounting, VAT return, bank integration | Outside product focus |
| Stripe refunds, disputes, fees in app | Handled in the Stripe dashboard |
| Custom theme | Default Filament UI |
| Multi-tenancy | One instance per company |
| In-app backups | Handled by the database service |
| Import from previous tool | Done manually outside the app; must set the `number_sequences` rows (`task:<project uuid>` for the task counter of each project; there is no counter column on `projects`) |
| Time rounding | Exact time is a product decision |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| HYG-01 | Phase 1 | Complete |
| HYG-02 | Phase 1 | Complete |
| HYG-03 | Phase 1 | Complete |
| HYG-04 | Phase 1 | Complete |
| HYG-05 | Phase 1 | Complete |
| HYG-06 | Phase 1 | Complete |
| HYG-07 | Phase 1 | Complete |
| FND-01 | Phase 2 | Complete |
| FND-02 | Phase 2 | Complete |
| FND-03 | Phase 2 | Complete |
| FND-04 | Phase 2 | Complete |
| FND-05 | Phase 2 | Complete |
| FND-06 | Phase 2 | Complete |
| FND-07 | Phase 3 | Complete |
| FND-08 | Phase 3 | Complete |
| FND-09 | Phase 3 | Complete |
| FND-10 | Phase 3 | Complete |
| FND-11 | Phase 2 | Complete |
| FND-12 | Phase 2 | Complete |
| FND-13 | Phase 2 | Complete |
| FND-14 | Phase 2 | Complete |
| FND-15 | Phase 3 | Complete |
| FND-16 | Phase 3 | Complete |
| FND-17 | Phase 2 | Complete |
| FND-20 | Phase 2 | Complete |
| FND-18 | Phase 2 | Complete |
| FND-19 | Phase 3 | Complete |
| US-01 | Phase 12 | Complete |
| US-02 | Phase 4 | Complete |
| CL-01 | Phase 4 | Complete |
| CL-02 | Phase 4 | Complete |
| CL-03 | Phase 10 | Complete |
| CL-04 | Phase 4 | Complete |
| CL-05 | Phase 4 | Complete |
| PR-01 | Phase 4 | Complete |
| PR-02 | Phase 4 | Complete |
| PR-03 | Phase 4 | Complete |
| PR-04 | Phase 4 | Complete |
| PR-05 | Phase 6 | Complete |
| TA-01 | Phase 5 | Complete |
| TA-02 | Phase 5 | Complete |
| TA-03 | Phase 5 | Complete |
| TA-04 | Phase 5 | Complete |
| TA-05 | Phase 5 | Complete |
| TA-06 | Phase 5 | Complete |
| TA-07 | Phase 5 | Complete |
| KB-01 | Phase 5 | Complete |
| KB-02 | Phase 5 | Complete |
| KB-03 | Phase 5 | Complete |
| TI-01 | Phase 6 | Complete |
| TI-02 | Phase 6 | Complete |
| TI-03 | Phase 6 | Complete |
| TI-04 | Phase 6 | Complete |
| TI-05 | Phase 6 | Complete |
| TI-06 | Phase 6 | Complete |
| TI-07 | Phase 6 | Complete |
| TI-08 | Phase 6 | Complete |
| TI-09 | Phase 6 | Complete |
| AP-01 | Phase 7 | Complete |
| AP-02 | Phase 7 | Complete |
| AP-03 | Backlog (999.1) | Deferred |
| AP-04 | Phase 7 | Complete |
| AP-05 | Phase 7 | Complete |
| EX-01 | Phase 8 | Complete |
| EX-02 | Phase 8 | Complete |
| EX-03 | Phase 8 | Complete |
| RE-01 | Phase 8 | Complete |
| RE-02 | Phase 8 | Complete |
| RE-03 | Phase 8 | Complete |
| RE-04 | Phase 8 | Complete |
| RE-05 | Phase 8 | Complete |
| DO-01 | Phase 9 | Gaps Found |
| DO-02 | Phase 9 | Gaps Found |
| DO-03 | Phase 9 | Gaps Found |
| FI-01 | Phase 9 | Complete |
| FI-02 | Phase 9 | Gaps Found |
| FI-03 | Phase 10 | Complete |
| IN-01 | Phase 10 | Complete |
| IN-02 | Phase 10 | Complete |
| IN-03 | Phase 10.1 | Complete |
| IN-04 | Phase 10 | Complete |
| IN-05 | Phase 10 | Complete |
| IN-06 | Phase 10 | Complete |
| IN-07 | Phase 10 | Complete |
| IN-08 | Phase 10 | Complete |
| IN-09 | Phase 10 | Complete |
| IN-10 | Phase 10 | Complete |
| IN-11 | Phase 10.1 | Complete |
| IN-12 | Phase 10.1 | Complete |
| IN-13 | Phase 10.1 | Complete |
| IN-14 | Phase 10.1 | Complete |
| IN-15 | Phase 10.1 | Complete |
| PA-01 | Phase 11 | Complete |
| PA-02 | Phase 11 | Complete |
| PA-03 | Phase 11 | Complete |
| PA-04 | Phase 11 | Complete |
| PA-05 | Phase 11 | Complete |
| PA-06 | Phase 11 | Complete |
| PA-07 | Phase 11 | Complete |
| PA-08 | Phase 11 | Complete |
| P14-01 | Phase 14 | Complete |
| P14-02 | Phase 14 | Complete |
| P14-03 | Phase 14 | Complete |
| P14-04 | Phase 14 | Complete |
| P14-05 | Phase 14 | Complete |
| P14-06 | Phase 14 | Complete |
| P14-07 | Phase 14 | Complete |
| P14-08 | Phase 14 | Complete |
| P14-09 | Phase 14 | Complete |
| P15-01 | Phase 15 | Complete |
| P15-02 | Phase 15 | Complete |
| P15-03 | Phase 15 | Complete |
| P15-04 | Phase 15 | Complete |
| P15-05 | Phase 15 | Complete |
| P15-06 | Phase 15 | Complete |
| P15-07 | Phase 15 | Complete |
| P15-08 | Phase 15 | Complete |
| P15-09 | Phase 15 | Complete |
| P15-10 | Phase 15 | Complete |
| P15-11 | Phase 15 | Complete |
| P15-12 | Phase 15 | Complete |
| P16-01 | Phase 16 | Complete |
| P16-02 | Phase 16 | Complete |
| P16-03 | Phase 16 | Complete |
| P16-04 | Phase 16 | Complete |
| P16-05 | Phase 16 | Complete |
| P16-06 | Phase 16 | Complete |
| P16-07 | Phase 16 | Complete |
| P16-08 | Phase 16 | Complete |
| P16-09 | Phase 16 | Complete |
| P16-10 | Phase 16 | Complete |
| P16-11 | Phase 16 | Complete |
| P17-01 | Phase 17 | Complete |
| P17-02 | Phase 17 | Complete |
| P17-03 | Phase 17 | Complete |
| P17-04 | Phase 17 | Complete |
| P17-05 | Phase 17 | Complete |
| P17-06 | Phase 17 | Complete |
| P17-07 | Phase 17 | Complete |
| P17-08 | Phase 17 | Complete |
| P17-09 | Phase 17 | Complete |
| P17-10 | Phase 17 | Complete |
| AUD-01 | Phase 12 | Complete |
| AUD-02 | Phase 12 | Complete |

**Coverage:**
- v1 requirements: 144 total
- Mapped to phases: 143
- Deferred to backlog: 1 (AP-03)
- Unmapped: 0

**Mapping notes:** CL-03 and FI-03 (Phase 10), PR-05 (Phase 6) and US-01 (Phase 12) are traced to the phase where they first become fully observable. RE-04 tiles, the System page indicators (FND-10) and the file attachments of PR-01, TA-01 and TA-04 are wired by later phases. Details in ROADMAP.md (Overview, Mapping notes).

---
*Requirements defined: 2026-10-06*
*Last updated: 2026-10-29 for Phase 17 (task, project and search UX refinements: P17-01 to P17-10 added)*
