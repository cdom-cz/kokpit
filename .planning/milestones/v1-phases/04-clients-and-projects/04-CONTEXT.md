# Phase 4: Clients and Projects - Context

**Gathered:** 2026-10-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin maintains clients, contacts and projects with their billing terms, and invites a client to a restricted Partner account that sees only projects flagged client-visible. Delivers US-02, CL-01, CL-02, CL-04, CL-05, PR-01, PR-02, PR-03, PR-04.

Not in this phase: tasks and the frozen-key rule (Phase 5, the key is only suggested and kept unique here), time totals, billed/unbilled and client detail panels for invoices and documents (CL-03, PR-05 land with their data in later phases), project files (Phase 9), invoicing.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): one Partner belongs to exactly one client via `users.client_id` (Phase 2 D-01); fail-closed default-deny with `#[AccessRule]`, `#[NotPartnerScoped]`, `#[DeniesPartners]` (Phase 2 D-02, D-03); `Money` value object with minor units; typed settings with defaults (Phase 3); activity log allowlist declared on the model (Phase 3 D-06); UUID v7, Czech UI, fictional data only.

### Partner invitation
- **D-01:** An invitation is its own DB record (e-mail, client, token, expiry, state). The `users` row is created only when the invited person sets a password via the signed link. No half-created accounts; revoking is trivial.
- **D-02:** Invitations are valid for 7 days (value in `config/kokpit.php`). Admin sees the invitations in the client detail and can resend (the new token invalidates the old one) or revoke.
- **D-03:** Inviting an e-mail that already has a user account (Admin, another Partner, same client) is rejected with a validation error on the e-mail field. No re-linking of an existing Partner to another client.
- **D-04:** Admin can deactivate and reactivate a Partner account and send a password reset from the client detail. Deactivation blocks login and invalidates sessions and API tokens. Accounts are never hard-deleted. The account list in the client detail is part of this phase.

### Hiding prices from Partner
- **D-05:** Hourly rate, fixed price and time estimate of a project live in a separate 1:1 table `project_billing`, Admin-only (`#[DeniesPartners]`). A Partner query on projects can never load these columns, including in selects, search, exports and error output. — **Reversibility:** costly — every project form, list and later billing read goes through this relation.
- **D-06:** Partner has no access to clients and contacts at all (both models Admin-only). Client rate, currency, payment terms and billing data cannot leak. A Partner identifies the client only through the projects they see.
- **D-07:** A client-visible project shows a Partner read-only list and detail with name, key, status, description, dates, priority and tags. Never rates, prices, estimate, billing type or internal notes. The user explicitly chose that Partner **sees project tags** (accepted risk: Admin must not put internal wording into project tags; Partner tag display needs a canary test).

### Client form, ARES, archival
- **D-08:** The ARES button overwrites only ARES-sourced fields (name, company ID, tax ID, registered address). It never touches currency, rate, payment terms, language, online-payment flag, contacts or tags. Changed fields are highlighted. Error or timeout shows a message next to the company ID field and leaves the form unchanged (CL-04).
- **D-09:** The client has foreign clients. Company ID and tax/VAT ID are optional free fields; the Czech mod-11 company ID check and the ARES button apply only when country is CZ. The company ID is unique within a country when present. Address is one structured billing address (street, city, postal code, ISO country); no separate postal address.
- **D-10:** `stage` is a fixed enum: lead, active, paused, ended (Czech labels in `lang/cs`). It is a label and filter only; it does not change permissions or billing.
- **D-11:** Archiving a client is a soft delete. Its projects stay (hidden from pickers), its Partner accounts keep existing but cannot log in; restoring the client restores access. Archived clients disappear from pickers and lists but can be restored (CL-05, success criterion 1).
- **D-12:** Exactly one primary contact per client (partial unique index in the DB); any number of billing contacts. `invoice_email` is a separate field on the client and does not depend on contact flags.
- **D-13:** Client currency, rate, payment terms and invoice language are pre-filled from the typed defaults at creation and stored on the client. Later changes to the defaults never change existing clients.

### Project key and fields
- **D-14:** The key suggestion strips diacritics and takes initials of the words (one word: its first 3-4 letters), uppercase, 2-6 letters. On collision another variant is offered. The field stays editable; a duplicate is rejected with a field error and uniqueness is enforced by a DB unique index. Freezing after the first task is Phase 5.
- **D-15:** Billing type is an enum with exactly two values: hourly and fixed price (no non-billable type). The project currency is always the client's currency. A project rate is optional; empty means "use the client rate" (resolved at billing time in a later phase). Fixed price and time estimate are in `project_billing` (D-05).
- **D-16:** Project status enum: Planned, To clarify, In progress, In review, Ready to release, Done (Czech labels: Plánovaný, K upřesnění, V realizaci, Ke kontrole, K vypuštění, Dokončeno). Priority enum: low, normal, high, urgent. Status and priority can be switched freely between any values in any order: there is no transition workflow, no guard and no forced sequence (same for tasks later). Start and end dates are both optional. The same optional-dates rule applies to tasks later. Project archival is a soft delete separate from status.

### Claude's Discretion
Class and file names, invitation e-mail wording and mailable, exact ARES DTO fields and cache, tag input component (Filament Spatie tags plugin per STACK.md), Filament form layout, Czech label wording, how deactivation is stored (for example `users.deactivated_at`), activity-log allowlists for client, contact and project.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 4 goal and five success criteria
- `.planning/REQUIREMENTS.md` — US-02, CL-01, CL-02, CL-04, CL-05, PR-01 to PR-04
- `.planning/PROJECT.md` — constraints (Partner isolation, UUID v7, DB-enforced integrity)
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — Partner scope, `users.client_id`, access rules, Money
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — typed settings and defaults, activity log allowlist, queued mail alerts

### Research
- `.planning/research/STACK.md` — ARES REST endpoint, timeout and retry guidance, `spatie/laravel-tags` UUID notes, `filament/spatie-laravel-tags-plugin`
- `.planning/research/FEATURES.md` — client defaults and Partner invite onboarding
- `.planning/research/PITFALLS.md` — Partner leakage surfaces (selects, search, exports)

### Repository rules
- `.claude/CLAUDE.md` — fictional data only (use company ID `12345678`, `example.com`)
- `scripts/check-sensitive.sh` — must pass on every commit

No external ADRs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Shared/Auth/` (`PartnerScope`, `IsolatesPartners`, `DeniesPartners`, `AccessRule`, `KokpitPolicy`): isolation primitives for the new models.
- `app/Domain/Shared/Models/Tag.php` and the tag tables: UUID-ready tags for clients and projects.
- `app/Domain/Settings/Settings/DefaultsSettings.php`, `PaymentSettings.php`: defaults for D-13.
- `app/Domain/Audit/LogsAllowlistedActivity.php`: activity log allowlist for new models.
- `app/Filament/Concerns/Enforces*AccessRule.php` and `ActivityHistoryRelationManager`: attach to new resources.
- `app/Domain/Operations/Alerts`, `Jobs/KokpitJob`: queued mail pattern for the invitation e-mail.
- `app/Console/Commands/InstallCommand.php`: Admin creation pattern, no default passwords.

### Established Patterns
- Every Filament class declares `#[AccessRule]`; every model declares Partner scope or `#[NotPartnerScoped]`; architecture tests enforce both.
- UUID v7 and `timestamptz` everywhere; schema tests fail otherwise; DB constraints (FK, unique, partial indexes, CHECK) for invariants.
- Canary harness (two fictional clients) gets one line per new Partner-visible model.
- Czech via `lang/cs`; code, tests and docs in English.

### Integration Points
- `users.client_id` already exists (nullable, indexed, no FK yet) — add the FK now.
- Client and project models join the Partner isolation harness and the morph map.
- Phase 5 attaches tasks and the key counter (`projects.next_task_number`) to projects.

</code_context>

<specifics>
## Specific Ideas

- The user has foreign clients, so nothing in the client model may assume a Czech company ID or Czech address.
- Status "Ready to release" is the English rendering of the user's "K vypuštění"; confirm the label wording during planning.

</specifics>

<deferred>
## Deferred Ideas

- **Client escalation raises priority by one step** (low to normal to high to urgent). Belongs to Phase 5, where a Partner creates tasks and comments but cannot change priority; open question for that phase's discussion: how a Partner escalates (button or flag on a task) and whether the one-step raise is automatic or an Admin-confirmed action. Phase 4 only guarantees the free-switch priority enum (D-16).

</deferred>

---

*Phase: 4-Clients and Projects*
*Context gathered: 2026-10-08*
