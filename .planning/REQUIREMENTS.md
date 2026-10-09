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

- [ ] **US-01**: Roles Admin and Partner behave as specified in the permission matrix of the brief
- [x] **US-02**: Admin manages users and creates a client account (Partner) from the client detail with an e-mail invitation

### Clients

- [x] **CL-01**: Admin manages clients with billing data, stage, currency, rate, payment terms, invoice e-mail and language, online-payment flag
- [x] **CL-02**: Admin manages multiple contacts per client with primary and billing flags
- [ ] **CL-03**: Client detail shows projects, unbilled time and amount, invoices, documents and client accounts
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
- [ ] **TI-08**: Time is stored exactly in seconds without rounding; rate resolution is task, project, client, global default; snapshot of rate and amount on billing
- [x] **TI-09**: A forgotten long-running timer is flagged to the user

### API

- [ ] **AP-01**: REST API `/api/v1` with Sanctum tokens carrying abilities (`time:read`, `time:write`); only Admin creates tokens
- [ ] **AP-02**: Time entries list with filters, detail, create, update, delete; same domain actions and rules as the UI
- [ ] **AP-03**: Timer start, stop and current running
- [ ] **AP-04**: Read-only lookups of clients, projects and tasks (UUID, key, name); tasks addressable by key
- [ ] **AP-05**: Token management UI, rate limiting, idempotency key support, OpenAPI documentation

### Exchange Rates

- [ ] **EX-01**: Daily scheduled, idempotent download of CNB rates, manual run and history backfill; weekend/holiday uses previous rate; per-unit amounts handled
- [ ] **EX-02**: Overview of rates and Admin alert when download fails; last known rate is used meanwhile
- [ ] **EX-03**: Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots

### Reports

- [ ] **RE-01**: Time report per client and project for a period with breakdown by task, day and user
- [ ] **RE-02**: Billable/non-billable and billed/unbilled split with amounts in client currency and CZK
- [ ] **RE-03**: CSV/XLSX export (queued, delivered by notification) and PDF work report attachable to an invoice
- [ ] **RE-04**: Dashboard: today/this week hours, unbilled per client, overdue invoices, unresolved payments, upcoming task deadlines
- [ ] **RE-05**: Effective hourly rate shown for fixed-price projects

### Documents

- [ ] **DO-01**: Files uploaded to clients, projects, tasks, comments, invoices, transactions or unattached on S3; Partner sees only files on accessible projects/tasks, excluding internal-comment attachments, and only those flagged visible
- [ ] **DO-02**: Admin central listing with filters, sorting, single and ZIP download (queued), bulk delete, storage totals overall and per client
- [ ] **DO-03**: Downloads always go through the app with permission check and short-lived signed URL; deleting a record removes the S3 object; issued invoice PDFs need explicit confirmation to delete

### Finance

- [ ] **FI-01**: Income and expense transactions with hierarchical categories, currency, CZK amount, optional client/project/invoice, receipts as attachments
- [ ] **FI-02**: Overview per month/year, per category and per client in CZK
- [ ] **FI-03**: Payment of an invoice creates an income transaction (automatically for Stripe; editable date and category for manual payment)

### Invoicing

- [ ] **IN-01**: Invoices, proformas and credit notes with separate number series, number allocated only on issue
- [ ] **IN-02**: Draft created from unbilled billable time (client, period, projects; grouping per project or task; fixed sums added) plus manual items; items editable; entries reserved so two drafts cannot bill the same time
- [ ] **IN-03**: Issue freezes supplier, customer and bank snapshots, rate and amounts; issued invoices are immutable except payment state and internal note, enforced in the database
- [ ] **IN-04**: PDF generated at issue from snapshots only, stored in documents, with SPAYD QR for CZK bank transfer, in the client's invoice language; sent by queued e-mail with optional work report
- [ ] **IN-05**: Manual payment (transfer, cash) recorded in a payments table; warning if already paid; overdue status derived; overdue overview
- [ ] **IN-06**: Proforma converts to invoice with a link after payment
- [ ] **IN-07**: Invoicing in client currency with CNB rate snapshot and CZK total
- [ ] **IN-08**: Non-VAT-payer mode is the default and fully tested; data model prepared for VAT payer
- [ ] **IN-09**: Partner sees own issued invoices and proformas (list, detail, PDF, pay button) read-only, never drafts, internal notes, linked time or rates
- [ ] **IN-10**: Cancelling or deleting a draft, or storno, returns linked entries to unbilled

### Payments (Stripe)

- [ ] **PA-01**: Single-use Stripe Payment Link created by queued job at issue when online payment is enabled; failure never blocks issue and alerts Admin
- [ ] **PA-02**: "Pay by card" button in e-mail, PDF and invoice detail (Admin and Partner); hidden when paid
- [ ] **PA-03**: Online payment can be disabled per client and per document
- [ ] **PA-04**: Link deactivated on payment by any route or on storno; link can be regenerated (old one deactivated first)
- [ ] **PA-05**: Webhook endpoint verifies Stripe signature, stores event with unique event id and answers immediately; processing runs in a job
- [ ] **PA-06**: Matching by invoice id (fallback link id) with amount and currency check creates payment, marks invoice paid, creates income, deactivates link, notifies Admin
- [ ] **PA-07**: Second payment of the same invoice is stored as duplicate without income or invoice change and alerts Admin; repeated events are ignored; idempotence enforced by DB unique constraints
- [ ] **PA-08**: Unmatched payments and refund events are recorded and alerted; Admin payments overview with duplicate/unmatched filters and manual resolution; old webhook events are pruned

### Partner Audit

- [ ] **AUD-01**: Automated tests prove Partner never sees time, rates, prices, finance, internal comments or other clients' data in UI, search, selects, exports, notifications, activity log and API, for every entity
- [ ] **AUD-02**: Route and screen audit confirms every non-public route is authenticated and every Resource, Page, Widget and API endpoint has an explicit role rule

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
| US-01 | Phase 12 | Pending |
| US-02 | Phase 4 | Complete |
| CL-01 | Phase 4 | Complete |
| CL-02 | Phase 4 | Complete |
| CL-03 | Phase 10 | Pending |
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
| TI-08 | Phase 6 | Pending |
| TI-09 | Phase 6 | Complete |
| AP-01 | Phase 7 | Pending |
| AP-02 | Phase 7 | Pending |
| AP-03 | Phase 7 | Pending |
| AP-04 | Phase 7 | Pending |
| AP-05 | Phase 7 | Pending |
| EX-01 | Phase 8 | Pending |
| EX-02 | Phase 8 | Pending |
| EX-03 | Phase 8 | Pending |
| RE-01 | Phase 8 | Pending |
| RE-02 | Phase 8 | Pending |
| RE-03 | Phase 8 | Pending |
| RE-04 | Phase 8 | Pending |
| RE-05 | Phase 8 | Pending |
| DO-01 | Phase 9 | Pending |
| DO-02 | Phase 9 | Pending |
| DO-03 | Phase 9 | Pending |
| FI-01 | Phase 9 | Pending |
| FI-02 | Phase 9 | Pending |
| FI-03 | Phase 10 | Pending |
| IN-01 | Phase 10 | Pending |
| IN-02 | Phase 10 | Pending |
| IN-03 | Phase 10 | Pending |
| IN-04 | Phase 10 | Pending |
| IN-05 | Phase 10 | Pending |
| IN-06 | Phase 10 | Pending |
| IN-07 | Phase 10 | Pending |
| IN-08 | Phase 10 | Pending |
| IN-09 | Phase 10 | Pending |
| IN-10 | Phase 10 | Pending |
| PA-01 | Phase 11 | Pending |
| PA-02 | Phase 11 | Pending |
| PA-03 | Phase 11 | Pending |
| PA-04 | Phase 11 | Pending |
| PA-05 | Phase 11 | Pending |
| PA-06 | Phase 11 | Pending |
| PA-07 | Phase 11 | Pending |
| PA-08 | Phase 11 | Pending |
| AUD-01 | Phase 12 | Pending |
| AUD-02 | Phase 12 | Pending |

**Coverage:**
- v1 requirements: 97 total
- Mapped to phases: 97
- Unmapped: 0

**Mapping notes:** CL-03 and FI-03 (Phase 10), PR-05 (Phase 6) and US-01 (Phase 12) are traced to the phase where they first become fully observable. RE-04 tiles, the System page indicators (FND-10) and the file attachments of PR-01, TA-01 and TA-04 are wired by later phases. Details in ROADMAP.md (Overview, Mapping notes).

---
*Requirements defined: 2026-10-06*
*Last updated: 2026-10-06 after roadmap creation (traceability filled)*
