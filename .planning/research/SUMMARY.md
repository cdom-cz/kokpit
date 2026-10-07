# Project Research Summary

**Project:** Kokpit (single-tenant, self-hosted, open-source CRM/ERP for a freelancer or small company)
**Domain:** Client -> project -> task -> time -> invoice -> payment, with Czech invoicing conventions (non-VAT-payer supplier) and a restricted client role (Partner) in the same Filament panel
**Researched:** 2026-10-06
**Confidence:** MEDIUM-HIGH

## Executive Summary

Kokpit is a Jira-lite plus time-tracker plus Czech invoicing tool. Its reason to exist is one pass: tracked time becomes an issued, payable invoice, and nothing unbilled or unpaid slips through unnoticed. Experts build this kind of product as a Laravel monolith. Filament, the REST API and queue jobs are thin adapters over transactional Domain Actions. Every invariant (one running timer, gap-free numbers, immutable issued invoices, idempotent payments, Partner isolation) is enforced by PostgreSQL itself (partial unique indexes, CHECK, triggers, row locks), not by UI or observers. The stack is fully resolved: Laravel 13, Filament 5 (Livewire 4), PHP 8.5, PostgreSQL 18, UUID v7 keys, the Spatie package family, Sanctum, Stripe Payment Links, and the database queue.

The recommended approach is to front-load cross-cutting infrastructure in Foundation, because retrofitting it is the expensive part:
- UUID and timestamptz schema conventions, with edited package migrations;
- a single `Money` value object;
- one `SequenceAllocator`;
- a DB immutability trigger pattern;
- default-deny Partner isolation (model scope, policy, separate Partner allowlist screens);
- failure visibility;
- a canary-based isolation test harness that grows with every phase.

Feature phases then follow the dependency chain: Clients, Projects, Tasks, Kanban, Time, API, Rates, Reports, Documents, Finance, Invoicing, Stripe. The last phase is an audit that only verifies, because the leak tests started in the first phase.

Key risks:
- Partner data leaking through secondary Filament surfaces (search, selects, relation managers, widgets, exports, notifications, Livewire snapshots).
- Package migrations left with bigint morph columns.
- Numbering that is not gap-free or is race-prone.
- Mutable issued invoices.
- The Stripe webhook: the default Spatie validator does not understand Stripe signatures, and Payment Links are reusable.
- Secrets or real data leaking into a public repo; Zerops Git auto-deploy would bypass the GitHub approval gate.

The research also found things the brief omits that hurt v1 if skipped: a payments table, overdue and unbilled alerts, supplier profile and bank accounts, notifications, API lookup endpoints, admin 2FA, Partner document visibility. These are folded into the phases.

## Key Findings

### Recommended Stack
Start on the newest majors. Everything is MIT except DOMPDF (LGPL, compatible as an unmodified Composer library). Details in STACK.md.

**Core technologies:**
- PHP 8.5 + Laravel `^13.0`: `HasUuids` already generates UUID v7.
- Filament `^5.0` (Livewire `^4.4`): one SPA panel (`->spa()`) with two roles, plus plugins for medialibrary, settings and tags.
- PostgreSQL 18: native `uuidv7()`, partial unique indexes, row locks; the `database` queue uses `SKIP LOCKED`.
- Spatie: permission `^8.3`, medialibrary `^11.23`, tags `^4.12`, activitylog `^5.1` (PHP 8.4+, breaking vs v4), settings, query-builder, data, eloquent-sortable, pdf, webhook-client. Every table-owning package needs its migration edited to uuid before the first migrate, plus a model subclass registered in config.
- Laravel Sanctum `^4.3`: token-only `/api/v1` with abilities; Scramble (free core) for OpenAPI.
- `stripe/stripe-php ^22` used directly with a custom signature validator on webhook-client. Do NOT use `spatie/laravel-stripe-webhooks` (caps stripe-php at ^21).
- DOMPDF behind spatie/laravel-pdf's driver switch (no binaries on Zerops), with a Foundation spike on the multi-page work report. Gotenberg is the per-document escape hatch.
- `spatie/simple-excel` for streamed CSV/XLSX. Own small `Spayd` value object plus `chillerlan/php-qrcode ^5`. Never `openspout ^5` or `php-qrcode ^6` (they conflict with Filament).
- Queue, cache and sessions on the PostgreSQL database. Zerops worker service plus crontab for `schedule:run`. S3 via env only (Zerops MinIO needs path-style).
- Pest, Pint, Larastan, gitleaks, and a `composer licenses` allowlist in CI.

### Expected Features
Details in FEATURES.md.

**Must have (table stakes):**
- Clients and contacts with ARES lookup (graceful fallback), invoice defaults, archive instead of delete.
- Projects with billing types, rate precedence with snapshots, `client_visible` flag.
- Tasks with gap-free `KEY-N`, subtasks, checklist, comments with internal flag, list view with filters, kanban (Admin-only drag, Partner read-only list).
- Timer plus manual entries, DB-enforced single running timer, consistency rules, timesheet, billed lock, long-timer warning.
- Time API with project/task lookup endpoints and `Idempotency-Key`.
- CNB rates: weekend/holiday handling, per-unit `amount`, snapshot on documents.
- Documents on private S3 with an explicit Partner-visible flag, central listing, ZIP job.
- Invoicing: draft vs issued split, numbers allocated at issue; bill-from-time wizard with entry reservation; PDF cs/en with SPAYD QR and stored issued PDF; e-mail with work report; `payments` table (1:N); proforma -> invoice; credit notes; derived overdue.
- Stripe: single-use Payment Links, signed idempotent webhooks, duplicate/unmatched queue, link deactivation on any settlement.
- Finance: income auto-created from payments (cash basis), expenses with receipts, CZK overview.
- Dashboard and reports with unbilled-time and overdue alerts, work report PDF, CSV/XLSX.
- Partner view plus an automated isolation suite.
- Gaps to make explicit: supplier profile with per-currency bank accounts, notifications, Admin 2FA, first-run setup and fictional demo data.

**Should have (differentiators):**
- One-pass bill-from-time wizard (the Core Value path).
- "Slip-through" alerts: unbilled-time age, overdue invoices, unmatched Stripe payments, stale CNB rates, daily admin digest.
- Stripe auto-matching with DB-enforced idempotence; Partner "pay now".
- Time API as an integration surface.

**Defer (v1.x / v2+):**
- v1.x: scheduled reminders, recurring invoices, editable templates, accountant export pack, forgotten-timer e-mail, expense re-billing, profitability widgets, VAT-threshold monitor.
- v2+: real VAT-payer mode (keep nullable columns and snapshots), bank import and matching, team features, Peppol, public invoice link.
- Anti-features to hold firm on: time rounding, configurable kanban workflows, editing issued invoices, Partner seeing time or rates, full accounting.

### Architecture Approach
Filament, the REST API and jobs are adapters. Every state change is one Action class in `app/Domain/*` (no Filament imports, arch-tested), run in a short DB transaction with I/O after commit.

Partner isolation is default-deny in three layers: (1) sensitive data lives in Admin-only tables; (2) a `KokpitModel` base with abstract `constrainForPartner()` forces a decision per model; (3) `BasePolicy` enforces ownership. Partner screens are a separate allowlist (`app/Filament/Partner/*`), Partner has no API tokens, jobs never use ambient auth.

**Major components:**
1. Shared kernel: `Money`/`MoneyMath` (only rounding points), `SequenceAllocator`, `PartnerContext`/`PartnerScope`, `KokpitModel`, `KokpitJob` base with failure reporter.
2. Identity, Clients, Projects (billing terms Admin-only), Tasks.
3. TimeTracking: partial unique index for one running timer, generated duration, `invoice_item_id` as billed lock enforced by trigger.
4. Rates, Reports, Documents, Finance.
5. Invoicing and Payments: `IssueInvoice` state machine (lock, allocate number, snapshots, after-commit PDF/mail/Stripe link); `ApplyPayment` as the single entry point for manual and Stripe payments; Stripe pipeline with three idempotence layers (unique event id, job claim, unique payment object).

### Critical Pitfalls
Top items from PITFALLS.md:
1. **Partner leaks through secondary surfaces** (global search, selects, relation managers, widgets, exports, notifications, activity log, Livewire snapshots, counts). Prevention: three layers plus a canary harness started in Foundation and extended every phase; registry test that every Resource/Page/Widget/RelationManager has an approved base.
2. **UUID and timestamp conventions broken by package migrations.** Edit all published migrations in one Foundation commit; `Relation::enforceMorphMap`; catalog architecture test.
3. **Gap-free numbers and timer races.** `SequenceAllocator` in the numbering transaction, allocate at issue only, year from `Europe/Prague`; partial unique index for the timer; real parallel-process tests on PostgreSQL.
4. **Immutability only in app code.** PostgreSQL triggers on issued invoices, lines, billed entries; `ON DELETE RESTRICT`; PDF rendered from snapshots and stored with sha256.
5. **Stripe correctness.** Custom `Stripe-Signature` validator on the raw body; unique `evt_` id; only `payment_status = paid` settles; `completed_sessions.limit = 1`; deactivate link on every settlement path; match by metadata, not amount.
6. **Operational traps.** Health widget, non-queued failure alert, scheduler heartbeat, stale-CNB check; Zerops `zsc execOnce` and single-container scheduler; deploy only via GitHub Actions; gitleaks on full history in CI; SHA-pinned actions, no `pull_request_target`.
7. **Money, CNB and time details.** Integer minor units, one rounding per invoice line; CNB `amount` of 1/100/1000 and lookups keyed by `validFor`; local-day bucketing via `AT TIME ZONE 'Europe/Prague'`; DST fixtures.

## Implications for Roadmap

Ordering follows the architecture build order, which matches the brief. Deliberate adjustments: isolation harness starts in Foundation; payments table and notification channel introduced early; Stripe last because it only extends `ApplyPayment`. Step 0 (hygiene) stays first.

### Phase 0: Repo Hygiene
**Rationale:** The repo is public from the first commit; leaks cannot be undone. `.planning/codebase/` is already committed.
**Delivers:** `.gitignore`, sensitive-content script and hook; gitleaks on full history in CI with custom rules; local denylist outside the repo; secret scanning and push protection; actionlint/zizmor, SHA-pinned actions, CODEOWNERS on workflows; fictional-data rule in CLAUDE.md and CONTRIBUTING; licence allowlist CI step.

### Phase 1: Foundation
**Rationale:** Everything rests on these conventions; retrofitting is the most expensive item.
**Delivers:** Laravel 13 + Filament 5 SPA panel; CI with PostgreSQL; edited package migrations and UUID/timestamptz/morph-map catalog tests; `KokpitModel`/`PartnerScope`/`BasePolicy`/approved Filament bases with registry and arch tests; roles; `Money`/`MoneyMath`; `SequenceAllocator` plus parallel-process test harness; immutability trigger pattern; `KokpitJob` base, failure reporter, System/health page with scheduler heartbeat; typed settings (supplier profile, bank accounts, invoicing defaults); activity-log allowlist; notification channel; Admin 2FA; first-run install command; S3 disk smoke test; Actions-only Zerops deploy; spikes (DOMPDF 3-page report with diacritics and QR; Flowforge on UUID table with Partner policy); Zerops audits (PHP extensions, PG collation/extensions); `lang/cs`; isolation canary harness skeleton.

### Phase 2: Clients
Clients, contacts, tags, archive-not-delete, per-client defaults, ARES lookup (validation, timeout, fallback), Partner account invite linked via `users.client_id`, first isolation registry entries. Decide one Partner per client vs many-to-many.

### Phase 3: Projects
Projects with frozen key, billing type, rate precedence, `client_visible`, estimate, archive; billing terms in an Admin-only table; project keys never reused.

### Phase 4: Tasks
Per-project counter proving the allocator, subtasks, checklist, comments with internal flag forced in the Action, attachments, list view with filters, Partner task creation, notification e-mails using Partner-safe DTOs.

### Phase 5: Kanban
Admin-only board (project and global), `MoveTask` reusing `ChangeTaskStatus`, read-only Partner list. Flowforge if the Foundation spike passes (needs a custom theme/Node build); otherwise a custom Livewire page with Alpine `x-sort`.

### Phase 6: Time Tracking
`time_entries` schema (partial unique index, generated duration, composite task/project FK), Start/Stop/Manual Actions, topbar timer widget (server timestamps, Alpine tick), timesheet, overlap policy decision, billed-lock trigger, long-timer banner.

### Phase 7: REST API
Token-only `/api/v1`, abilities plus policies, token expiry/prune, rate limit by token, `Idempotency-Key`, read-only projects/tasks lookup, `updated_since`, Scramble docs (gated), Admin-only token UI. Calls the same Actions as the UI.

### Phase 8: Exchange Rates (CNB)
JSON feed with TXT fallback, stored `amount`, `valid_for`-keyed idempotent upsert, backfill command, converter (latest on or before date, staleness cap), 14:45 Prague schedule with retries, stale-rate health signal.

### Phase 9: Reports and Dashboard
Query objects; time per client/project/period; billable vs billed vs unbilled; streamed CSV/XLSX with formula-injection sanitising; work-report PDF; dashboard; daily admin digest.

### Phase 10: Documents
medialibrary (UUID) on private S3, app download route with short-lived URLs, per-document `visible_to_client` (default hidden), MIME/size allowlist, streamed ZIP job, bulk delete with orphan reconciliation.

### Phase 11: Finance
Transactions and categories, expenses with receipts, CZK snapshot, monthly/yearly overview, cash-basis recognition, `PaymentApplied` listener.

### Phase 12: Invoicing
Largest and riskiest; consider splitting 12a (draft, issue, numbering, snapshots, PDF, e-mail, manual payment) and 12b (bill-from-time wizard, proforma, credit notes, overdue and reminder). Includes `payments` table and `ApplyPayment`, `IssueInvoice` state machine with triggers, series per document type, VS column, SPAYD QR, PDF from snapshots, work-report e-mail, entry reservation, derived overdue, Partner invoice view (issued only).

### Phase 13: Stripe
Payment Link job (limit 1, idempotency key, metadata, deactivation on every settlement path), custom signature validator, three idempotence layers, matching, duplicate/unmatched/needs_review queue, payments overview with retry, payload retention, livemode check, fixture suite.

### Phase 14: Partner Audit
Close registry gaps, route audit, Resources/Pages/Widgets/API x roles enumeration, canary pass over HTML, Livewire JSON, API, downloads, notifications; 2-container deploy rehearsal.

### Phase Ordering Rationale
- Money and scopes precede Projects (rates); allocator proven on tasks before invoices; Rates precede Reports, Finance and Invoicing; Documents and Finance precede Invoicing; `payments` precedes Stripe.
- Each phase adds one Domain module, its migrations, and its rows in the Partner isolation registry.
- All schema conventions and the harness are Foundation work with failing CI tests; Stripe and Invoicing get fixture suites; the deploy path is fixed once in Foundation.
- v1.x items (reminders, recurring invoices, accountant export) stay outside this roadmap; keep invoice creation callable from a job.

### Research Flags
Deeper research (`/gsd-plan-phase --research-phase <N>`): Phase 1 (Zerops specifics, PDF and Flowforge spikes, PHP 8.5 package compatibility, Filament v5 [verify] items), Phase 5 (only if Flowforge spike fails), Phase 6 (overlap policy, SPA timer), Phase 7 (Scramble coverage), Phase 12 (Czech invoicing details), Phase 13 (Payment Link API shape, event matrix, API version pinning).
Standard patterns: 0, 2, 3, 4, 8, 9, 10, 11, 14.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Versions read from Packagist/package sources on 2026-10-06. MEDIUM for Zerops specifics, UUID edits, Scramble/Stripe details |
| Features | MEDIUM | Czech invoicing rules MEDIUM-HIGH; competitor analysis from product knowledge; VAT threshold and Stripe edge cases need re-verification |
| Architecture | MEDIUM-HIGH | Patterns are established practice; package-specific and Filament v5 details partly unverified |
| Pitfalls | MEDIUM-HIGH | Stripe, CNB, Sanctum, Spatie UUID, Zerops `zsc` verified against docs; Filament internals tagged [verify] |

**Overall confidence:** MEDIUM-HIGH

### Gaps to Address
- **Researcher drift:** ARCHITECTURE cites spatie/laravel-permission v7 and an in-house kanban; STACK verified `^8.3` and recommends Flowforge. Treat STACK as authoritative on versions; resolve kanban via the Foundation spike (fallback: custom board).
- **Zerops runtime facts** (extensions, worker model, PG extensions/collation, S3 checksums): verify in Foundation.
- **DOMPDF multi-page work report:** spike; switch that document to Gotenberg if it fails.
- **Filament v5 behaviours** (default authorization without policy, global search/relation-manager scoping, Livewire snapshot contents, export auth context): encode as tests in the isolation harness.
- **Product decisions before the owning phase:** Partner per client cardinality (P2); overlapping entries (P6); credit-note effect on billed entries and sign convention (P12); which date selects the CNB rate; proforma series and VS; whether Partner sees documents (default hidden with share flag).
- **Czech legal text:** not legal advice; document as limitation and re-verify at implementation.
- **Activity log UI:** `rmsramos/activitylog` vs a simple relation manager under activitylog v5.

## Sources

### Primary (HIGH confidence)
- Packagist metadata and package sources (Laravel 13, Filament 5, Spatie packages, Sanctum, stripe-php, Flowforge, openspout, QR libraries), 2026-10-06.
- Laravel 12/13 docs, Stripe docs, CNB feeds, ARES REST API (live), Spatie docs, PostgreSQL 18 docs, SPAYD specification.

### Secondary (MEDIUM confidence)
- Zerops docs, Filament docs, Czech invoicing tool guides, GitHub Actions hardening guidance, competitor product knowledge.

### Tertiary (LOW confidence, needs validation)
- Items tagged [verify] in PITFALLS.md; Czech statute references; Scramble UI licence.

---
*Research completed: 2026-10-06*
*Ready for roadmap: yes*
