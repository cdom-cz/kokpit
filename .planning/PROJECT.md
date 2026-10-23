# Kokpit

## What This Is

Kokpit is an open-source (AGPL-3.0) web CRM/ERP for a freelancer or small company, replacing a hosted tool such as Caflou. It covers the whole flow: client -> project -> task -> tracked time -> billing -> invoice -> payment -> income. One instance serves one company; the primary user is a single admin, secondary users are client accounts (role Partner) with a restricted view in the same panel.

## Core Value

Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Repository hygiene tooling (step 0): .gitignore, sensitive-content check script, pre-commit hook, gitleaks, CI scan, CLAUDE.md rule, CONTRIBUTING section
- [ ] Foundation: Laravel + Filament SPA panel, UUID (v7) keys across own and package tables, auth, roles (Admin/Partner), settings, activity log, queue worker, scheduler, CI, Zerops deploy, .env.example, README/SECURITY/CONTRIBUTING
- [ ] Clients and contacts (incl. tags, client accounts, synchronous ARES lookup by company ID)
- [ ] Projects (Jira-style keys, client visibility flag, billing type, rates, fixed price, estimate)
- [ ] Tasks and subtasks (shared per-project counter `KEY-N`, todos, comments with internal flag, list view with filters)
- [ ] Kanban per project and global, drag and drop
- [ ] Time tracking (always-visible timer, manual entries, one running timer per user, consistency rules, billed locking, timesheet)
- [ ] REST API `/api/v1` for time entries and lookups; timer endpoints are in the backlog (Sanctum tokens with abilities, OpenAPI docs, rate limiting)
- [ ] CNB exchange rates (daily scheduled download, idempotent, manual backfill, failure alert)
- [ ] Reports and dashboard (time per client/project, billable vs billed, CSV/XLSX export, work report PDF)
- [ ] Documents on S3-compatible storage via medialibrary, central listing, ZIP download, bulk delete
- [ ] Finance: income/expense transactions and categories, monthly/yearly overview in CZK
- [ ] Invoicing: number sequences, invoices, proformas, credit notes, PDF, email, billing from time entries, manual payment, client view of issued invoices; non-VAT-payer mode
- [ ] Stripe: payment links, signed webhooks, automatic matching, duplicate and unmatched payment handling, payments overview
- [ ] Partner-view audit: automated leak and client-isolation tests across all screens and API

### Out of Scope

- Mobile/desktop app — web only, responsive
- Separate client portal with own look — Partner uses the same Filament panel
- Full accounting, VAT returns, bank integration — outside product focus
- Stripe refunds, disputes, fee tracking inside the app — handled in the Stripe dashboard
- Custom theme — default Filament UI
- Multi-tenancy — one instance per company
- In-app backups — handled by the database service
- Import from the previous tool — done manually outside the app; the import must set the counters, which are `number_sequences` rows (`task:<project uuid>` for task numbers)
- VAT-payer mode calculations and PDF template — data model prepared, implemented later

## Context

- Repository is public from the first commit; anything committed is treated as published. All seeds, factories, tests, docs and planning files use fictional data only (`example.com`, non-existent company IDs).
- Language: code, DB, enums, routes, tests, comments, commit messages in English; all user-facing text in Czech via `lang/cs` (default locale `cs`); invoice and invoice email follow `clients.invoice_language` (`cs`/`en`).
- Money: bigint minor units plus ISO 4217 currency; time stored UTC (`timestamptz`), shown in `Europe/Prague`; durations in integer seconds, never rounded.
- Spatie packages preferred: permission, medialibrary, tags, activitylog, settings, query-builder, data, eloquent-sortable, pdf, webhook-client.
- Deployment target: Zerops; deploy only from published release or manual dispatch, behind a GitHub `production` environment with manual approval.
- Slow or external work runs in queued, idempotent jobs; synchronous only where the user waits (timer, kanban move, invoice number allocation, ARES lookup).
- Existing `.planning/codebase/` map was committed before step 0 although the repository contains no application code; treat it as unreliable.

## Constraints

- **Tech stack**: PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL 18, Redis (queue, cache, sessions), S3-compatible private bucket (RustFS), Sanctum, Stripe Payment Links — fixed by the brief
- **Dev environment**: DDEV (versioned `.ddev/`, no secrets or instance values) — local development, tests and CI share PHP and PostgreSQL versions
- **License**: AGPL-3.0; all dependencies must be AGPL-compatible, no paid or closed packages
- **Security**: Partner must never see measured time, rates, prices or finance, nor another client's data — enforced by Policies and global query scopes, not only UI hiding
- **Data integrity**: UUID v7 keys everywhere incl. package morph columns; FK, unique, partial indexes and check constraints enforced in DB
- **Repository hygiene**: no secrets, real data or instance-specific values in git; enforced by tooling from step 0 (local denylist outside repo, pre-commit hook, gitleaks, CI)
- **Concurrency**: number sequences (tasks, invoices) must be gap-free and duplicate-free under concurrent creation
- **Immutability**: an issued invoice or proforma stays editable until a successful e-mail send locks it; Admin may unlock a sent document with a recorded reason; number and kind never change; credit notes and billed time entries are immutable; snapshots for supplier, customer, rates, exchange rates

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Name Kokpit, AGPL-3.0, public repo | Open source; others deploy their own instance | — Pending |
| UUID v7 primary keys | Time-ordered, safe in URLs/API; costly to change later | — Pending |
| Partner = client account in same Filament panel | Avoids building a separate portal | — Pending |
| Exact time, no rounding; invoice item editable manually | Accuracy plus flexibility | — Pending |
| Currency per client, CNB daily rate via scheduler | Czech invoicing needs CZK conversion | — Pending |
| Stripe Payment Links + webhook as source of truth | Automatic payment matching, DB-enforced idempotence | — Pending |
| Supplier is a non-VAT-payer in v1 | Data model ready for VAT payer, logic deferred | — Pending |
| Planning docs committed only after hygiene check passes | Public repo; `commit_docs` off until step 0 is done | — Pending |
| Local development on DDEV | Reproducible environment for contributors of a public repo | — Pending |
| PostgreSQL 18, Redis for queue/cache/sessions, RustFS as S3-compatible storage | Stack chosen by owner; replaces the brief's open choice (DB vs Redis queue, S3 provider) | — Pending |
| The send-driven lock replaces status-driven immutability: an issued invoice or proforma is locked only by a successful e-mail send and Admin can unlock it with a reason (Phase 10.1, D-01, D-12) | An issued document may need correction before the customer has it; the database still enforces the lock once sent | — Pending |
| Payment method per document (transfer or online) independent of currency and bank account; bank accounts have stable ids and several may share a currency (Phase 14, D-01, D-04; overrides Phase 3 D-04) | Online invoices must not print bank details; the account is a choice, the currency only preselects it | — Pending |
| Card switch editable on an open issued transfer document, forced on for online, frozen by the lock; pay token set once (Phase 14, OA-1; amends Phase 11 D-06) | The method can change until the document is sent; the printed pay URL must keep working | — Pending |
| Every e-mail renders through one published `kokpit` mail theme; the supplier branding is read only through `MailBrand` (system context, field whitelist, fallback to the application name) and the logo is an inline cid part, never a route (Phase 13, D-02, D-03, D-04) | One look for every e-mail without a new public route or a Partner-scope leak | — Pending |
| The document e-mail keeps its plain-text body, escaped into HTML by `PlainTextHtml`, and gains a card button under `PayLinkBlock::applies()`; every typed value in a Markdown line is escaped with `MailText` (Phase 13, D-01) | An Admin-typed body must never inject markup, and the button and the text block must never disagree | — Pending |
| Payment confirmation: after every recorded payment the client gets an e-mail, queued from `RecordPayment::recordLocked()`, one `payment_confirmations` row per payment, sent after a five-minute grace window, never locks the invoice; proforma payments are confirmed, carried copies are not (Phase 13, D-05, D-06, A-13-1, A-13-6) | Clients learn that a payment arrived; a payment taken back within the window sends nothing | — Pending |
| The invitation, password reset, task and alert e-mails are reviewed in Czech only; English is required only for the new payment texts, the invoice button and the layout strings; no recipient-language mechanism is added (Phase 13, D-07 clarification, owner-approved 2026-10-23, so the owner's decision and not an assumption) | The single admin and Czech clients; a language mechanism for other recipients is out of scope | — Pending |
| Phase 13 planner assumptions for the owner to confirm or overrule: A-13-3 (a back-filled payment is confirmed too, and the record dialog says so beforehand), A-13-4 (an online-method document gets its own pre-filled body), A-13-5 (no pay-the-rest button in a partial confirmation), A-13-7 (the confirmation state shows in the Platby tab); A-13-1 and A-13-6 are in the payment confirmation row | Recorded so each can be overruled later without searching the plans | — Pending |
| Assumptions kept: Partner sees visible-project tasks, creates/comments but cannot change status/priority; fixed task statuses; project key frozen after first task; invoice number `{YYYY}{NNNN}` | Brief's open questions 1-5 | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-10-06 after initialization*
