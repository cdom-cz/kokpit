# Kokpit

## What This Is

Kokpit is an open-source (AGPL-3.0) web CRM/ERP for a freelancer or small company, replacing a hosted tool such as Caflou. It covers the whole flow: client -> project -> task -> tracked time -> billing -> invoice -> payment -> income. One instance serves one company; the primary user is a single admin, secondary users are client accounts (role Partner) with a restricted view in the same panel.

## Core Value

Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.

## Requirements

### Validated

- ✓ Repository hygiene tooling (scanner, pre-commit hook, gitleaks, CI scan, contribution rules) — v1
- ✓ Foundation: Laravel + Filament SPA panel, UUID v7 keys, roles Admin/Partner, settings, activity log, queue, scheduler, CI, Zerops deploy — v1
- ✓ Clients and contacts (tags, client accounts, ARES lookup) — v1
- ✓ Projects (keys, client visibility, billing type, rates, fixed price, estimate) — v1
- ✓ Tasks, subtasks, comments, list filters and kanban (per project and global) — v1
- ✓ Time tracking (always-visible timer, manual entries, billed locking, timesheet) — v1
- ✓ REST API `/api/v1` for time entries, lookups and transactions (Sanctum abilities, OpenAPI docs, rate limiting) — v1
- ✓ CNB exchange rates, reports, dashboard, CSV/XLSX export, work report PDF — v1
- ✓ Documents on S3-compatible storage, central listing, ZIP download, bulk delete — v1
- ✓ Finance: income/expense transactions, categories, overview in CZK — v1
- ✓ Invoicing: number sequences, invoices, proformas, credit notes, PDF with QR, e-mail, billing from time, manual and cash payment with receipts, switchable payment method and currency, send-driven lock with Admin unlock — v1
- ✓ Stripe: payment links, signed idempotent webhooks, automatic matching, duplicate and unmatched payment handling — v1
- ✓ Unified HTML e-mail layout and payment confirmations — v1
- ✓ Partner-view audit: automated leak and client-isolation tests across all screens and API — v1

### Active

Next milestone is not defined yet; run `/gsd-new-milestone`. Candidates carried over from the v1 audit (`milestones/v1-MILESTONE-AUDIT.md`):

- [ ] API timer endpoints (AP-03, backlog 999.1)
- [ ] G1b: release time held by an unpaid issued proforma
- [ ] G3: dashboard alert for unbilled time older than the current month
- [ ] G4: reaper, System indicator and resend path for a stuck `payment_confirmations` row
- [ ] Human UAT and verification of phases 10.1, 11, 12, 13 and 14
- [ ] Fix the PHPStan error in `ResetAdminTwoFactorCommand.php:39`
- [ ] Redesign the unreadable time report layout (todo 2026-10-14)

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

- Shipped v1 on 2026-10-30: 20 phases, 325 plans, about 84k lines of PHP in `app/` and about 142k in `tests/`; archives in `.planning/milestones/`.
- Repository is public from the first commit; anything committed is treated as published. All seeds, factories, tests, docs and planning files use fictional data only (`example.com`, non-existent company IDs).
- Language: code, DB, enums, routes, tests, comments, commit messages in English; all user-facing text in Czech via `lang/cs` (default locale `cs`); invoice and invoice email follow `clients.invoice_language` (`cs`/`en`).
- Money: bigint minor units plus ISO 4217 currency; time stored UTC (`timestamptz`), shown in `Europe/Prague`; durations in integer seconds, never rounded.
- Spatie packages preferred: permission, medialibrary, tags, activitylog, settings, query-builder, data, eloquent-sortable, pdf, webhook-client.
- Deployment target: Zerops; deploy only from published release or manual dispatch, behind a GitHub `production` environment with manual approval.
- Slow or external work runs in queued, idempotent jobs; synchronous only where the user waits (timer, kanban move, invoice number allocation, ARES lookup).
- The `.planning/codebase/` map predates the application code and is ignored by git; treat it as unreliable and re-map before relying on it.

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
| The Partner permission matrix (screens, API endpoints, Livewire components, Partner actions per record state, Partner fields, entity by channel leaks) is a literal declaration in `tests/Support`, never derived from production code, and the owner approved it as implemented on 2026-10-24 with no amendment; DO-01 stays with phase 9 (Phase 12, D-02, 12-MATRIX-REVIEW.md) | The audit is only an independent check when the expectation is written by hand and reviewed by the owner | Approved 2026-10-24 |
| A route in the public allowlist and in the closed list is keyed by its route name, else by `METHOD /uri` with the Livewire prefix written `livewire-{hash}`; adding a route means editing `RouteRules` in the same pull request (Phase 12, D-04, D-06, R-12-01-1) | Unnamed vendor routes (the Livewire assets) still need an explicit, reviewable key, and the declaration shows in the diff | Approved 2026-10-24 |
| The Partner audit is an `Audit` Pest suite excluded from `composer test` and the main CI Pest step, run by `composer partner-audit`, by `composer ci` and by its own CI step `Partner audit` (Phase 12, D-10, R-12-01-4) | A failing audit names its own step in the pull request, and `composer ci` stays the one local gate | — Pending |
| The unused Filament export and import download routes stay registered and are closed with 404 by explicit route binders, declared as closed routes, instead of creating their tables (Phase 12, R-12-02-3) | The application uses no export or import action, so the routes must answer the same to everyone and never 500 | Approved 2026-10-24 |
| A guest without an `Accept` header is redirected to the panel login route, so an unauthenticated `/api/v1` request answers a JSON 401 instead of a 500 (Phase 12, R-12-02-2) | The missing `login` route made the guest redirect throw; the audit walk of every route exposed it | Approved 2026-10-24 |

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
*Last updated: 2026-10-30 after v1 milestone*
