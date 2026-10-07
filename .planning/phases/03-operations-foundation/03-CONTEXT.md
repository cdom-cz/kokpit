# Phase 3: Operations Foundation - Context

**Gathered:** 2026-10-08
**Status:** Ready for planning

<domain>
## Phase Boundary

The app becomes operable and deployable, and fails loudly instead of silently. Delivers FND-07 (typed settings), FND-08 (activity log with attribute allowlist), FND-09 (resilient queue jobs with visible failure), FND-10 (Admin System page), FND-15 (Zerops deploy and protected release workflow), FND-16 (private S3 storage smoke test) and FND-19 (PDF and kanban spikes).

No client, project, task, invoice or document features here. Later phases fill the System page slots (last rate date, unprocessed webhooks, unsent invoice e-mails) and attach the history tab to their own records.

</domain>

<decisions>
## Implementation Decisions

Already fixed before this discussion (not re-opened): Laravel 13, Filament 5 SPA, PHP 8.5, PostgreSQL 18, Redis for queue/cache/sessions, RustFS S3, DDEV, UUID v7, Czech UI, invoice number `{YYYY}{NNNN}`, per-class `#[AccessRule]` declarations, `#[NotPartnerScoped]`/PartnerScope data-layer default-deny (Phase 2).

### Typed settings
- **D-01:** Settings are stored with `spatie/laravel-settings` (typed settings classes in the database, cached). The researcher must verify PHP 8.5, Laravel 13 and UUID v7 compatibility (settings table key) before planning; if the package fails, escalate rather than silently switching.
- **D-02:** The Admin edits all settings on one Filament page with tabs (supplier, bank accounts, invoicing incl. VAT mode / payment terms / numbering, defaults, online payments). One form, one Save, one `#[AccessRule]` (Admin only). Everything is Czech via `lang/cs`.
- **D-03:** A bank account is a record with a **format** that decides which fields are shown, modelled on the Caflou form:
  - common: name (label), format, currency, BIC/SWIFT;
  - **Europe 1 (account number):** account number, bank code, bank name, IBAN (needed for QR payment codes);
  - **Europe 2 (IBAN only):** IBAN, BIC/SWIFT;
  - **World (universal):** account number, BIC/SWIFT, recipient name, bank name, bank address.
  Entered as a repeater. IBAN is validated.
- **D-04:** Currency is a field of each bank account and is **unique** across accounts (one account per currency, ISO 4217). Invoices later select the account by the client's currency.
- **D-05:** Numbering patterns are editable tokens (for example `{YYYY}{NNNN}`) for invoices, proformas, credit notes and tasks, with allowed-token validation and a live preview of the next number. A changed pattern applies only to newly issued documents; issued numbers never change. Pattern drives the scope key of the Phase 2 sequence allocator.

### Activity log
- **D-06:** The allowlist of logged attributes is declared on the model itself (attribute or method, in the style of `#[NotPartnerScoped]`), and an architecture test fails when a logged model has no allowlist or lists an attribute that is not a real column. Non-allowlisted attributes are never written.
- **D-07:** Admin sees history in two places: a reusable read-only history relation manager (attached to records in later phases) and an Admin-only global activity overview with filters. Phase 3 delivers the global overview and the reusable relation manager; Partner never sees any of it.
- **D-08:** Changes made without a logged-in user (console, jobs, scheduler, webhooks) are logged too, with `causer` null and a source label (console / job / webhook).
- **D-09:** Activity records are kept indefinitely; there is no pruning.

### Queue jobs, alerts and System page
- **D-10:** A shared base job class defaults to 3 attempts with backoff 10 s, 60 s, 5 min; a job can override both. Idempotence is part of the base class contract and documented.
- **D-11:** A failed job (final failure) triggers an alert that does not depend on the queue: a synchronous e-mail to the Admin plus a database notification shown in the Filament bell. Alerts are throttled so a single outage does not flood the inbox.
- **D-12:** The System page shows each indicator as OK / Warning / Error using fixed thresholds in `config/kokpit.php` (not editable in the UI): scheduler heartbeat older than 3 min = Error, oldest pending job older than 10 min = Warning and 30 min = Error, any failed job = Warning. Values are starting points; the planner may tune them and must document them.
- **D-13:** The page renders a registry of health indicators behind a common interface. Phase 3 registers failed jobs, oldest pending job and scheduler heartbeat for real, and registers last rate date, unprocessed webhooks and unsent invoice e-mails as placeholders ("not available yet"). A test fails if one of the six slots is not registered. Later phases replace one indicator each.

### Spikes, deploy and storage
- **D-14:** The PDF spike compares Dompdf and `spatie/laravel-pdf` (Browsershot) on Czech diacritics, a multi-page report, a QR code, Chromium requirements on Zerops, and licence compatibility.
- **D-15:** The kanban spike compares a custom Livewire + SortableJS board against a Filament-compatible package, on Filament 5 / Livewire 4 compatibility, persisted ordering (eloquent-sortable), Partner isolation and responsiveness; result is a build-or-buy decision for Phase 5.
- **D-16:** Spike code is throwaway and lives outside the application (separate branch or directory, never merged into `app/`). Only a decision record in English (criteria, measurements, outcome) goes into `.planning`. The chosen dependency is added to the app by the first phase that needs it.
- **D-17:** The S3 smoke test is an Artisan command (storage check: upload, fetch through a temporary URL, delete, print result) used on a server, plus tests that run against the RustFS service in CI. Configuration by environment variables only, path-style capable.
- **D-18:** On Zerops, migrations run once per deploy (not in worker or scheduler) before traffic is switched; a failing migration stops the deploy and the previous version keeps running. Migrations must therefore stay backward compatible with the previous release.

### Claude's Discretion
- Exact class and file names, settings group layout inside the tabs, alert throttling window, health indicator interface shape, scheduler heartbeat mechanism, deploy workflow job layout and hardening details (all hardening rules from the brief apply), `zerops.yml` structure, DDEV daemon details, Czech label wording, spike measurement method.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 3 goal and five success criteria
- `.planning/REQUIREMENTS.md` — FND-07, FND-08, FND-09, FND-10, FND-15, FND-16, FND-19
- `.planning/PROJECT.md` — constraints, Key Decisions, Zerops deployment context
- `.planning/STATE.md` — Phase 3 research flags (Zerops specifics, PDF and kanban spikes, PHP 8.5 package compatibility, Filament 5 behaviours)
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — data-layer conventions, `#[AccessRule]`, Partner default-deny, sequence allocator, Money
- `.planning/phases/01-repository-hygiene/01-CONTEXT.md` — hygiene rules for all new code, fixtures and docs

### Research
- `.planning/research/STACK.md` — authoritative package versions
- `.planning/research/PITFALLS.md` — leakage surfaces, queue and numbering pitfalls
- `.planning/research/ARCHITECTURE.md` — Domain Actions, DB-enforced invariants

### Repository rules
- `.claude/CLAUDE.md` — fictional-data-only rule, review procedure before every commit
- `CONTRIBUTING.md` — contribution procedure and the manual GitHub settings checklist to extend
- `scripts/check-sensitive.sh` — must pass on every commit

No external ADRs. The bank account form is modelled on the previous hosted tool's form; use fictional account numbers and IBANs only (never real values from screenshots).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `config/kokpit.php`: home for System page thresholds and other app config.
- `config/activitylog.php`, `config/media-library.php`, `config/webhook-client.php`, `config/queue.php`, `config/filesystems.php`: already present, UUID-adjusted in Phase 2.
- `app/Support/ProductionConfigGuard.php`: extend for new production-only guards if needed.
- `app/Domain/Shared`, `app/Domain/Identity`: location for domain code; sequence allocator from Phase 2.
- `app/Filament/Pages`, `app/Filament/Concerns`: pages and shared traits (access declaration).
- `.ddev/` (redis and RustFS compose files, daemons): worker, scheduler and S3 service for dev and CI.
- `.github/workflows/hygiene.yml` and the Phase 2 CI workflow with `ci-passed` job: deploy workflow must not weaken them.

### Established Patterns
- Every Filament class declares `#[AccessRule(Audience, reason)]`; a registry test fails otherwise.
- Every model declares Partner scope or `#[NotPartnerScoped(reason)]`; architecture tests enforce it.
- UUID v7 keys and `timestamptz` everywhere including package tables, enforced by schema tests.
- Console entry points run in the system context (`runAsSystem`).
- Czech via `lang/cs`; code, tests and docs in English.

### Integration Points
- Settings classes feed the later invoice, numbering and PDF phases.
- Activity log allowlist attribute is applied to task, project, invoice and time entry models when they are created.
- System page indicator registry is filled by Phases 8, 10 and 11.
- Spike outcomes feed Phase 5 (kanban) and Phases 8 and 10 (PDF).

</code_context>

<specifics>
## Specific Ideas

- Bank account form follows the previous tool's three formats (Europe 1, Europe 2, World) described in D-03.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 3-Operations Foundation*
*Context gathered: 2026-10-08*
