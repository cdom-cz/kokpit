# Phase 4: Clients and Projects - Research

**Researched:** 2026-10-08
**Domain:** Laravel 13 / Filament 5 (Livewire 4) CRM master data with fail-closed Partner isolation, a hashed-token invitation flow and a Czech registry (ARES) lookup
**Confidence:** HIGH for the isolation, schema and Filament findings (read from this repository's code and the installed vendor source); HIGH for the ARES response shape (called live today); MEDIUM for UX details and the few items marked `[ASSUMED]`

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): one Partner belongs to exactly one client via `users.client_id` (Phase 2 D-01); fail-closed default-deny with `#[AccessRule]`, `#[NotPartnerScoped]`, `#[DeniesPartners]` (Phase 2 D-02, D-03); `Money` value object with minor units; typed settings with defaults (Phase 3); activity log allowlist declared on the model (Phase 3 D-06); UUID v7, Czech UI, fictional data only.

**Partner invitation**
- **D-01:** An invitation is its own DB record (e-mail, client, token, expiry, state). The `users` row is created only when the invited person sets a password via the signed link. No half-created accounts; revoking is trivial.
- **D-02:** Invitations are valid for 7 days (value in `config/kokpit.php`). Admin sees the invitations in the client detail and can resend (the new token invalidates the old one) or revoke.
- **D-03:** Inviting an e-mail that already has a user account (Admin, another Partner, same client) is rejected with a validation error on the e-mail field. No re-linking of an existing Partner to another client.
- **D-04:** Admin can deactivate and reactivate a Partner account and send a password reset from the client detail. Deactivation blocks login and invalidates sessions and API tokens. Accounts are never hard-deleted. The account list in the client detail is part of this phase.

**Hiding prices from Partner**
- **D-05:** Hourly rate, fixed price and time estimate of a project live in a separate 1:1 table `project_billing`, Admin-only (`#[DeniesPartners]`). A Partner query on projects can never load these columns, including in selects, search, exports and error output. Reversibility: costly - every project form, list and later billing read goes through this relation.
- **D-06:** Partner has no access to clients and contacts at all (both models Admin-only). Client rate, currency, payment terms and billing data cannot leak. A Partner identifies the client only through the projects they see.
- **D-07:** A client-visible project shows a Partner read-only list and detail with name, key, status, description, dates, priority and tags. Never rates, prices, estimate, billing type or internal notes. The user explicitly chose that Partner **sees project tags** (accepted risk: Admin must not put internal wording into project tags; Partner tag display needs a canary test).

**Client form, ARES, archival**
- **D-08:** The ARES button overwrites only ARES-sourced fields (name, company ID, tax ID, registered address). It never touches currency, rate, payment terms, language, online-payment flag, contacts or tags. Changed fields are highlighted. Error or timeout shows a message next to the company ID field and leaves the form unchanged (CL-04).
- **D-09:** The client has foreign clients. Company ID and tax/VAT ID are optional free fields; the Czech mod-11 company ID check and the ARES button apply only when country is CZ. The company ID is unique within a country when present. Address is one structured billing address (street, city, postal code, ISO country); no separate postal address.
- **D-10:** `stage` is a fixed enum: lead, active, paused, ended (Czech labels in `lang/cs`). It is a label and filter only; it does not change permissions or billing.
- **D-11:** Archiving a client is a soft delete. Its projects stay (hidden from pickers), its Partner accounts keep existing but cannot log in; restoring the client restores access. Archived clients disappear from pickers and lists but can be restored (CL-05, success criterion 1).
- **D-12:** Exactly one primary contact per client (partial unique index in the DB); any number of billing contacts. `invoice_email` is a separate field on the client and does not depend on contact flags.
- **D-13:** Client currency, rate, payment terms and invoice language are pre-filled from the typed defaults at creation and stored on the client. Later changes to the defaults never change existing clients.

**Project key and fields**
- **D-14:** The key suggestion strips diacritics and takes initials of the words (one word: its first 3-4 letters), uppercase, 2-6 letters. On collision another variant is offered. The field stays editable; a duplicate is rejected with a field error and uniqueness is enforced by a DB unique index. Freezing after the first task is Phase 5.
- **D-15:** Billing type is an enum with exactly two values: hourly and fixed price (no non-billable type). The project currency is always the client's currency. A project rate is optional; empty means "use the client rate" (resolved at billing time in a later phase). Fixed price and time estimate are in `project_billing` (D-05).
- **D-16:** Project status enum: Planned, To clarify, In progress, In review, Ready to release, Done (Czech labels: Plánovaný, K upřesnění, V realizaci, Ke kontrole, K vypuštění, Dokončeno). Priority enum: low, normal, high, urgent. Status and priority can be switched freely between any values in any order: there is no transition workflow, no guard and no forced sequence (same for tasks later). Start and end dates are both optional. The same optional-dates rule applies to tasks later. Project archival is a soft delete separate from status.

### Claude's Discretion

Class and file names, invitation e-mail wording and mailable, exact ARES DTO fields and cache, tag input component (Filament Spatie tags plugin per STACK.md), Filament form layout, Czech label wording, how deactivation is stored (for example `users.deactivated_at`), activity-log allowlists for client, contact and project.

### Deferred Ideas (OUT OF SCOPE)

- **Client escalation raises priority by one step** (low to normal to high to urgent). Belongs to Phase 5, where a Partner creates tasks and comments but cannot change priority; open question for that phase's discussion: how a Partner escalates (button or flag on a task) and whether the one-step raise is automatic or an Admin-confirmed action. Phase 4 only guarantees the free-switch priority enum (D-16).

Not in this phase (CONTEXT domain boundary): tasks and the frozen-key rule (Phase 5, the key is only suggested and kept unique here), time totals, billed/unbilled and client detail panels for invoices and documents (CL-03, PR-05 land with their data in later phases), project files (Phase 9), invoicing.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| US-02 | Admin manages users and creates a client account (Partner) from the client detail with an e-mail invitation | Invitation table, signed accept page outside registration, `canAccessPanel` deactivation, account relation manager (sections "Invitation flow", "Account lifecycle") |
| CL-01 | Admin manages clients with billing data, stage, currency, rate, payment terms, invoice e-mail and language, online-payment flag | `clients` schema, defaults prefill table, Money columns, ClientResource design |
| CL-02 | Multiple contacts per client with primary and billing flags | `contacts` schema with partial unique index, contacts relation manager with a "make primary" action |
| CL-04 | ARES lookup by company ID via a synchronous button; errors next to the field, form unchanged | Live-verified ARES shape, `AresClient` + readonly DTO, mod-11 check, `suffixAction` pattern, failure-mode table |
| CL-05 | Clients archived (soft delete) not hard-deleted; tags available on clients | SoftDeletes + `TrashedFilter`, no force-delete, tags plugin, HasTags soft-delete trap |
| PR-01 | Admin manages projects (name, status, description, dates, priority, files, tags) - files are Phase 9 | `projects` schema, ProjectResource, tag type `project` |
| PR-02 | Unique 2-6 letter uppercase key suggested from the name (frozen after first task is Phase 5) | Key suggester algorithm, unique index covering archived rows, race-to-field-error handling |
| PR-03 | Billing type, hourly rate, fixed price, time estimate; rates and prices where Partner cannot read them | `project_billing` 1:1 Admin-only table, billing type placed there too, currency rule |
| PR-04 | Client-visibility flag controlling Partner access | `Project::constrainForPartner` (client + visible flag + not archived), separate Partner resource, canary and visibility tests |
</phase_requirements>

## Summary

Phase 4 introduces the first real tenant models, so almost all of the risk is concentrated in four places. (1) Partner leakage: the project model must be the only Partner-readable new model, its scope must fail closed on three conditions (own client, `client_visible = true`, client not archived), and everything Admin-only (clients, contacts, `project_billing`, invitations) must be `DeniesPartners`. Tags must be opened to the Partner with a real scope on the `Tag` model (type `project` and attached to a visible own project), not with a bypass. (2) The existing Phase 2/3 test and support code assumes fictional `client_id` values that point at no row. Adding the `users.client_id` foreign key and a client-active check in `canAccessPanel()` breaks about 15 test files unless `Canary::twoClients()` is changed to create real (fictional) client rows; that must be done first, in Wave 0. (3) The invitation accept page is a guest request, and every scope is fail-closed for guests, so the accept action is the one place that must run as an explicit system run. (4) `spatie/laravel-tags` registers a `deleted` listener that detaches all tags, and Eloquent fires `deleted` on a soft delete, so archiving a client or project would silently erase its tags unless the trait method is guarded.

Everything else follows established repository patterns: UUID v7 tables with raw-SQL CHECK and partial indexes, `Money` via `MoneyCast` column pairs, string-backed enums with `HasLabel` labels in `lang/cs/enums.php`, `#[LoggedAttributes]` allowlists, `#[AccessRule]` on every Filament class, Domain Actions for every state change, and canary fixtures per `PartnerIsolated` model. No new Composer package is mandatory. Two optional ones are verified installable: `filament/spatie-laravel-tags-plugin` v5.10.0 (recommended, official, locked by CONTEXT "tag input component") and `symfony/intl` v8.1.5 (country names; optional). `spatie/laravel-data` is not installed and is not justified for one ARES DTO; use a plain `final readonly` class.

ARES was called live (structure only recorded): the success payload has `ico`, `obchodniJmeno`, `sidlo{...}`, `dic` (optional), `pravniForma`; `psc` and the house numbers are JSON integers (leading zeros of a postal code are lost); unknown company returns 404 with `subKod VYSTUP_SUBJEKT_NENALEZEN`; a malformed id returns 400 `VSTUP_NEVALIDNI_FORMAT_ICO`; the API does not check the mod-11 checksum, so the local check saves calls. Laravel 13's `Http::retry()` without a `when` callback also retries 404/400, which must be avoided.

**Primary recommendation:** Build a thin end-to-end tracer first (tables, models with isolation declarations, `Canary::twoClients()` creating real clients, `Project` + `Tag` scopes, a read-only `PartnerProjectResource`, `canAccessPanel` client/deactivation checks, canary tests) before any Admin CRUD, then parallelise Admin resources, ARES, project billing and the invitation flow.

## Project Constraints (from CLAUDE.md)

Extracted from `.claude/CLAUDE.md` (treated as locked, same authority as CONTEXT decisions):

- Tech stack fixed: PHP 8.5, Laravel, Filament SPA mode, PostgreSQL, S3-compatible private bucket, Sanctum, queue.
- AGPL-3.0: every dependency must be AGPL-compatible; no paid or closed packages. `composer check-licenses` is part of `composer ci`.
- Partner must never see measured time, rates, prices or finance, nor another client's data - enforced by Policies and global query scopes, not only UI hiding.
- UUID v7 keys everywhere including package morph columns; FK, unique, partial indexes and CHECK constraints enforced in the database.
- Repository hygiene (public repo): fictional data only. Use `example.com` addresses, the placeholder company ID `12345678`, paths like `/Users/example/`, and test fakes assembled at runtime from fragments. Never put real client names, prices, company IDs, e-mails, hostnames, tokens or personal paths into code, tests, fixtures, docs or `.planning/`. Review before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook hook.
- Number sequences gap-free and duplicate-free (Phase 5 consumes `number_sequences` for task keys).
- Immutability of issued invoices and billed time (later phases).
- Work only through GSD commands; everything under `.planning/` is written in English, Czech only as UI strings in `lang/cs`.
- Project skills: none exist (`.claude/skills/`, `.agents/skills/` absent).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Partner row isolation (projects, tags, clients, contacts, billing, invitations) | Database / Storage (shape: Admin-only tables) | API / Backend (global scopes + policies) | Scope on the model is the only layer that covers relation selects, search, relation managers and Livewire re-queries; Filament resource scoping alone does not |
| Hiding rates, prices, estimate, billing type | Database / Storage (separate `project_billing` table) | API / Backend (`DeniesPartners`) | A Partner query on `projects` cannot select columns that are not in the table |
| Client / project / contact CRUD, archive, restore | API / Backend (Domain Actions in transactions) | Frontend Server (Filament resource adapters) | Invariants (one primary contact, currency rule, key uniqueness) belong in Actions + DB constraints, not in form callbacks |
| ARES lookup | API / Backend (`AresClient` HTTP adapter, sync) | Frontend Server (Filament `suffixAction`) | Network call, checksum validation and DTO mapping are domain logic; the button only calls it and calls `$set` |
| Invitation issue, resend, revoke, accept | API / Backend (Actions, hashed token, signed URL) | Frontend Server (accept SimplePage, admin actions) | Token handling, single use and the user creation are security logic; the page is an adapter |
| Invitation e-mail | API / Backend (queued notification, scalar payload) | -- | Runs in the queue worker without a user; must not carry models |
| Account deactivation and archived-client lockout | API / Backend (`User::canAccessPanel`) | Database (`users.deactivated_at`) | Filament checks `canAccessPanel` on every authenticated request and at login, so one method blocks sessions and new logins |
| Project key suggestion | Frontend Server (live form hook calls a pure service) | API / Backend (unique index decides) | Suggestion is convenience; correctness is the DB unique index and a field error on violation |
| Partner project list and detail | Frontend Server (separate read-only `PartnerProjectResource`) | API / Backend (scope + policy) | Separate class with an explicit column allowlist, per project architecture research |
| Localised labels | Frontend Server (`lang/cs`, enum `getLabel()`) | -- | Existing pattern; `EnumLabelsTest` scans every `HasLabel` enum |

## Standard Stack

### Core (already installed, versions read from `composer.lock`)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | v13.35.0 | Framework, `Http` client, rate limiter, signed URLs, notifications | Fixed by brief [VERIFIED: composer.lock] |
| filament/filament | v5.10.0 | Admin panel, SPA mode | Fixed by brief [VERIFIED: composer.lock] |
| livewire/livewire | v4.4.7 | Filament runtime | Required by Filament 5 [VERIFIED: composer.lock] |
| spatie/laravel-tags | 4.12.0 | Tags for clients and projects | Installed, UUID `Tag` subclass in `app/Domain/Shared/Models/Tag.php` [VERIFIED: composer.lock] |
| spatie/laravel-activitylog | 5.1.1 | Audit trail via `LogsAllowlistedActivity` | Installed [VERIFIED: composer.lock] |
| spatie/laravel-permission | 8.3.0 | Roles (admin, partner) | Installed [VERIFIED: composer.lock] |
| laravel/sanctum | 4.3.3 | API tokens (deleted on deactivation) | Installed [VERIFIED: composer.lock] |
| pestphp/pest | v5.3.0 | Tests | Installed [VERIFIED: composer.lock] |

### Supporting (new)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| filament/spatie-laravel-tags-plugin | ^5.10 (resolves to v5.10.0) | `SpatieTagsInput`, `SpatieTagsColumn` for the Filament form and table | Recommended: client and project tags. Not currently in `composer.json` and not in `vendor/`. A `composer require --dry-run` today resolved v5.10.0 with no conflicts [VERIFIED: ddev composer require --dry-run, 2026-10-08]; the Filament docs page lists 5.x support [CITED: filamentphp.com/plugins/filament-spatie-tags] |
| symfony/intl | ^8.1 (resolves to v8.1.5) | Czech country names for the country select (`Countries::getNames('cs')`) | Optional. `ext-intl` is present in DDEV but gives no clean country list; the dry-run resolved v8.1.5 [VERIFIED: ddev composer require --dry-run]. Alternative: a hard-coded short list of likely countries in a small enum [ASSUMED] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Plain `final readonly` ARES DTO | `spatie/laravel-data` ^4.23 | Installable (dry-run resolves 4.23.0 plus `spatie/php-structure-discoverer`), but two extra packages for one DTO; the REST-API phase can add it when response DTOs are needed. STACK.md suggested it as a candidate, this is Claude's discretion in CONTEXT |
| `SpatieTagsInput` | Filament `TagsInput` + own sync | Reimplements type-aware sync; the plugin already does `syncTagsWithType`; only the soft-delete trap (below) needs handling either way |
| Two resources (Admin + Partner) | One `ProjectResource` with `->visible()` toggles | Fewer files, but violates the architecture research ("never a shared Resource with visible() toggles for money") and relies on reviewers remembering every toggle |

**Installation:**
```bash
ddev composer require filament/spatie-laravel-tags-plugin:^5.10
# optional, for country names:
ddev composer require symfony/intl:^8.1
```

**Version verification:** versions above were resolved by Composer in this session (`ddev composer require --dry-run`, working tree left clean). Packagist metadata: the tags plugin has about 2.3M total installs, repository `filamentphp/spatie-laravel-tags-plugin`, first published 2021; `symfony/intl` about 231M installs, since 2013 [VERIFIED: packagist.org API, 2026-10-08].

## Package Legitimacy Audit

The GSD `package-legitimacy` seam supports only npm, pypi and crates, not Packagist (its usage line reads `--ecosystem <npm|pypi|crates>`), so the seam verdict is not available for these Composer packages. The audit below is manual.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| filament/spatie-laravel-tags-plugin | Packagist | ~5 yrs (2021) | ~2.3M total | github.com/filamentphp/spatie-laravel-tags-plugin (official Filament org, maintained by the Filament author) | OK (manual; seam unsupported) | Approved; official plugin per [CITED: filamentphp.com/plugins/filament-spatie-tags] |
| symfony/intl | Packagist | ~13 yrs | ~231M total | github.com/symfony/intl | OK (manual; seam unsupported); name not confirmed against the Symfony docs this session, so treat as `[ASSUMED]` until the planner confirms | Optional; planner adds a `checkpoint:human-verify` before install |
| spatie/laravel-data | Packagist | ~5 yrs | ~44M total | github.com/spatie/laravel-data | OK (manual) | NOT recommended for this phase (see Alternatives) |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none. Neither package has a postinstall step to check (Composer ecosystem; no npm scripts involved).

Both packages are MIT licensed [ASSUMED for symfony/intl; the plugin's `composer.json` states MIT, read from the downloaded source]. `composer check-licenses` must stay green; run it after install.

## Architecture Patterns

### System Architecture Diagram

```
                       ADMIN (2FA)                                      PARTNER (restricted)
                           |                                                   |
        +------------------+-------------------+                  +------------+-------------+
        |   Filament panel (SPA), /admin       |                  |  Filament panel          |
        |  ClientResource  ProjectResource     |                  |  PartnerProjectResource  |
        |  (AdminOnly)     (AdminOnly)         |                  |  (read-only list+view)   |
        +----+-------------+-------------+-----+                  +------------+-------------+
             |             |             |                                     |
   suffixAction ARES   form callbacks   header actions                          | Eloquent (global scopes ALWAYS on)
             |             |             |                                     v
             v             v             v                         +--------------------------+
   +------------+   +-------------------------------+              | Project (PartnerScope):  |
   | AresClient |   | Domain Actions (transactions) |              |  client_id = own         |
   | Http+cache |   | Create/UpdateClient           |              |  AND client_visible      |
   | -> AresCompany  | SetPrimaryContact             |              |  AND client not archived |
   +-----+------+   | Create/UpdateProject(+billing)|              | Tag (PartnerScope):      |
         |          | InvitePartner/Resend/Revoke   |              |  type=project AND attached|
         v          | Deactivate/Reactivate/Reset   |              |  to a visible own project|
   ares.gov.cz      +---------------+---------------+              | ProjectBilling, Client,  |
   (REST, JSON)                     |                              | Contact, Invitation:     |
                                    v                              |  DeniesPartners (1 = 0)  |
                 +------------------------------------+            +--------------------------+
                 | PostgreSQL 18: clients, contacts,  |
                 | projects, project_billing,         |<---- accept link (guest, signed URL)
                 | client_invitations, users(+FK,     |       |
                 | deactivated_at), tags, taggables   |       v
                 +------------------------------------+   AcceptInvitation SimplePage
                                                          -> AcceptInvitation Action
 Invitation mail: Action --(after commit)--> queued Notification (scalars only)   (runAsSystem, single use)
                  to an on-demand address ------------> Redis queue -> worker -> SMTP/Mailpit
 Login/every panel request: Filament Authenticate -> User::canAccessPanel()
   = role admin|partner AND not deactivated AND (admin OR client exists and is not archived)
```

### Recommended Project Structure

```
app/Domain/
├── Clients/
│   ├── Models/            Client, Contact, ClientInvitation
│   ├── Enums/             ClientStage, InvoiceLanguage, InvitationState (derived), TagType (shared, see below)
│   ├── Actions/           CreateClient, UpdateClient, ArchiveClient, RestoreClient, SetPrimaryContact,
│   │                      InvitePartner, ResendInvitation, RevokeInvitation, AcceptInvitation,
│   │                      DeactivatePartnerAccount, ReactivatePartnerAccount, SendPartnerPasswordReset
│   ├── Ares/              AresClient, AresCompany (DTO), AresFailure (enum), AresLookupFailed, CompanyId (mod-11)
│   └── Notifications/     PartnerInvitation (queued, scalar constructor)
├── Projects/
│   ├── Models/            Project, ProjectBilling
│   ├── Enums/             ProjectStatus, ProjectPriority, BillingType
│   ├── Actions/           CreateProject, UpdateProject (writes project + billing in one transaction)
│   └── ProjectKeySuggester.php
└── Shared/Models/Tag.php  (scope changed from DeniesPartners to a real constraint)
app/Filament/
├── Resources/             ClientResource (+Pages, +RelationManagers), ProjectResource (+Pages)   [AdminOnly]
│   └── ...                ClientHistoryRelationManager, ProjectHistoryRelationManager (extend ActivityHistoryRelationManager)
├── Partner/Resources/     PartnerProjectResource (+Pages: index, view)                             [PartnerAllowed, partner-only]
└── Pages/Auth/            AcceptInvitation (SimplePage, guest)
database/migrations/       one migration per table + one users alteration (FK, deactivated_at, lower(email) unique)
lang/cs/                   kokpit.php (new sections), enums.php (new enum label groups)
tests/                     Feature/Clients, Feature/Projects, Feature/Schema additions, Isolation additions, Unit/{Ares,Projects}
```

Register `app/Filament/Partner/Resources` in `AdminPanelProvider` with one more `discoverResources(in: app_path('Filament/Partner/Resources'), for: 'App\Filament\Partner\Resources')`; the existing `discoverResources` only covers `app/Filament/Resources` [VERIFIED: app/Providers/Filament/AdminPanelProvider.php:70 `->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')`]. The registry test scans all of `app/Filament`, so the new directory is covered without a change.

### Pattern 1: Partner isolation per new model (answers research question 1)

The primitives, read in full:

- `PartnerScope::apply()` lets an Admin or a system run through; a Partner with a client calls `$model->constrainForPartner($builder, $clientId)` only if the model implements `PartnerIsolated`; **every other state gets `whereRaw('1 = 0')`** [VERIFIED: app/Domain/Shared/Auth/PartnerScope.php:29-41]:
  ```php
  if ($context->isSystem() || $context->isAdmin()) { return; }
  ...
  if ($clientId !== null && $model instanceof PartnerIsolated) { $model->constrainForPartner($builder, $clientId); return; }
  $builder->whereRaw('1 = 0');
  ```
- `DeniesPartners` = `IsolatesPartners` + `constrainForPartner` that does `$query->whereRaw('1 = 0')` [VERIFIED: app/Domain/Shared/Auth/DeniesPartners.php:26-29].
- `KokpitPolicy::before()` returns `true` for the Admin, `null` for a Partner with a client (ability methods then decide, all default `false`) and `false` for everybody else [VERIFIED: app/Domain/Shared/Auth/KokpitPolicy.php:25-42]. Admin passes **every** ability including `forceDelete`; so "never hard-delete" must be enforced by not exposing force-delete actions and by `ON DELETE RESTRICT` foreign keys, not by the policy.
- `Audience` has exactly `case AdminOnly = 'admin_only';` and `case PartnerAllowed = 'partner_allowed';` [VERIFIED: app/Domain/Shared/Auth/Audience.php:13-14]. `AccessRules::allows()` returns false for any class without the attribute.
- `ModelDeclaration::appModels()` scans every concrete model under `app/Domain/*/Models/` and requires either `PartnerIsolated` or `#[NotPartnerScoped(reason)]`, never both [VERIFIED: tests/Support/ModelDeclaration.php:34, 72-78].

Decision per model:

| Model | Isolation | Policy | Notes |
|-------|-----------|--------|-------|
| `Client` | `implements PartnerIsolated` + `use DeniesPartners`, `SoftDeletes` | `#[UsePolicy(AdminOnlyPolicy::class)]` | D-06 |
| `Contact` | `DeniesPartners` | `AdminOnlyPolicy` | D-06 |
| `ProjectBilling` | `DeniesPartners` | `AdminOnlyPolicy` | D-05; `$project->billing` is `null` for a Partner, fail-closed |
| `ClientInvitation` | `DeniesPartners` | `AdminOnlyPolicy` | The guest accept flow reads it through an explicit `runAsSystem` (see Invitation flow) |
| `Project` | `implements PartnerIsolated` + `use IsolatesPartners` (real constraint), `SoftDeletes` | `ProjectPolicy extends KokpitPolicy` (explicit Partner grants) | the only new Partner-readable model |
| `Tag` (existing) | change from `DeniesPartners` to a real constraint (see Pattern 2) | stays `AdminOnlyPolicy` | Partner reads tags only through `$project->tags`, never through a tag resource |
| `User` | stays `#[NotPartnerScoped]` | new `UserPolicy` (or `AdminOnlyPolicy` registered for `User`) | needed because `strictAuthorization()` throws when a model used in a Filament table/action has no policy method [VERIFIED: vendor/filament/filament/src/helpers.php:43-55 "Strict authorization mode is enabled, but no ability ... was found for [...]"; panel sets it at AdminPanelProvider.php:58] |

`Project` scope (fail-closed, three conditions; all inside the single method the scope calls):

```php
// app/Domain/Projects/Models/Project.php
public function constrainForPartner(Builder $query, string $clientId): void
{
    $table = $this->getTable();

    $query
        ->where("{$table}.client_id", $clientId)
        ->where("{$table}.client_visible", true)               // NULL or false is excluded
        // An archived client hides its projects even if a stale session exists (D-11).
        ->whereExists(static fn ($sub) => $sub->selectRaw('1')->from('clients')
            ->whereColumn('clients.id', "{$table}.client_id")
            ->whereNull('clients.deleted_at'));
}
```

The scope fails closed because (a) `PartnerScope` itself denies on every state except a Partner with a client, (b) `client_visible` is `boolean NOT NULL DEFAULT false`, and (c) the comparison is `= true`. `SoftDeletingScope` hides archived projects for everybody by default; the Partner resource never removes it. `ProjectPolicy` re-checks single records (defence in depth): `viewAny` true, `view` true only when `$record instanceof Project && $record->client_id === $user->client_id && $record->client_visible`; `create`, `update`, `delete`, `restore`, `forceDelete`, `replicate`, `reorder` stay the base `false`.

How a Partner-visible `projects` model never loads billing data, by surface:

| Surface | Mechanism |
|---------|-----------|
| Table shape | `projects` has no money, estimate, billing type or internal-note column; a schema test (below) pins the exact column list so a later column cannot appear unreviewed |
| Eloquent relation `billing` | `ProjectBilling` is `DeniesPartners`, so `$project->billing` and `with('billing')` are `null` for a Partner |
| Filament global search | Panel keeps `->globalSearch(false)` [VERIFIED: app/Providers/Filament/AdminPanelProvider.php:61]; additionally set `protected static bool $isGloballySearchable = false;` on both project resources so a later panel-wide switch cannot expose them. Table search on the Partner list searches only `name` and `key` |
| Relation `Select` options | Project pickers use `Project::query()` which carries the scope; billing columns are not in the table anyway; Phase 5+ pickers must never use `DB::table` (existing `QueryEscapeHatchTest` fails the build) |
| Relation managers | The Partner resource registers **no** relation managers; Admin relation managers carry `#[AccessRule(AdminOnly)]` + `EnforcesRelationManagerAccessRule` |
| Export | No export action on either resource; Partner has no export at all |
| Validation messages | Partner cannot submit any project form (no create/edit pages, policy denies); the Admin `unique` rule for the key is Admin-only, so it cannot become an existence oracle for a Partner |
| Error pages | Partner queries never touch `project_billing`; a failing Partner query would show only `projects` columns |
| Activity log | `Activity` is Admin-only; project history relation managers are `AdminOnly` and attached only to the Admin `ProjectResource` |
| Notifications | None are sent to Partners in this phase; the only mail is the invitation, built from scalars |

Canary harness additions (all in `tests/Support/CanaryRegistry.php` and the tests that list models):

- Add one fixture line per new `PartnerIsolated` model: `Client`, `Contact`, `Project`, `ProjectBilling`, `ClientInvitation`. Each fixture stores the canary string in a text column (`clients.name`, `contacts.name`, `projects.name`, `project_billing.internal_note`, `client_invitations.name`) because `CanaryRegistryTest` asserts the Admin sees the canary in the serialised rows of every registered model [VERIFIED: tests/Isolation/CanaryRegistryTest.php:108-123]. This means `project_billing` needs a text column; use `internal_note` (D-07 itself names "internal notes" as hidden; see Open Question 3).
- The fixture for `Project` must create it with `client_visible = true` (the registry test requires a Partner to see "exactly the own client's row" of every non-`DeniesPartners` model [VERIFIED: tests/Isolation/CanaryRegistryTest.php:142-160]); the hidden / archived cases get their own dedicated tests.
- `Tag`'s fixture currently attaches the canary tag to the probe host (`self::host()->attachTag($canary)` [VERIFIED: tests/Support/CanaryRegistry.php:74-78]). After Pattern 2, `Tag` is no longer deny-all, so the registry test would demand one visible own row. Change the fixture to create a visible `Project` of that client and attach a `project`-type tag carrying the canary (fixture order: `Project` before `Tag`).
- `CanaryRegistryTest` "does not pass vacuously" hard-codes the six expected models [VERIFIED: tests/Isolation/CanaryRegistryTest.php:83-90]; extend the list. `ModelDeclarationTest` hard-codes nine app models [VERIFIED: tests/Arch/ModelDeclarationTest.php:31-41]; extend it.
- `tests/Arch/ActivityAllowlistTest.php` asserts `AuditDeclaration::loggingModels()` `toBe([])` [VERIFIED: tests/Arch/ActivityAllowlistTest.php:39-42]; replace with the explicit list (Client, Contact, Project, ProjectBilling, optionally ClientInvitation).
- Add a registry-style test that the `projects` column list equals an explicit Partner-safe allowlist (see Validation Architecture).
- Extend `EscapeHatchScanner` (optional hardening): also flag `withoutGlobalScope(PartnerScope::class)` outside an allowlist; the current scanner flags only the no-argument `withoutGlobalScopes()` and `DB::table(` [VERIFIED: tests/Support/EscapeHatchScanner.php:44-60].

### Pattern 2: Tags visible to the Partner, project tags only (answers question 2)

Facts: `spatie/laravel-tags` keeps all tags in one `tags` table (columns `name` json, `slug` json, `type` nullable string, `order_column`) and a `taggables` pivot with `uuidMorphs('taggable')` [VERIFIED: database/migrations/2026_10_07_152543_create_tag_tables.php]. Our `Tag` currently `use DeniesPartners` [VERIFIED: app/Domain/Shared/Models/Tag.php:23] and its docblock already anticipates opening it "with a client-bound constraint and explicit policy grants". `HasTags::tags()` is a `morphToMany` built from `config('tags.tag_model')` [VERIFIED: vendor/spatie/laravel-tags/src/HasTags.php `tags()`], so every `$project->tags` read passes through the `Tag` global scope.

Recommendation (Approach A, model-level scope, no bypass):

1. Separate by `type`: client tags are stored with `type = 'client'`, project tags with `type = 'project'`. Always call `SpatieTagsInput::make('tags')->type('client'|'project')` - without `->type()` the input uses `AllTagTypes`, suggests every tag of every type and syncs with an empty type [VERIFIED: vendor source of `SpatieTagsInput` v5.10.0, `getSuggestions()` and `syncTagsWithAnyType()`; docs advise always passing a type [CITED: filamentphp.com/plugins/filament-spatie-tags]].
2. Replace `DeniesPartners` in `Tag` by a real `constrainForPartner` that exposes only `project`-type tags attached to a project the Partner can see. The subquery reuses `Project`'s own scope, so the visible-flag, own-client and not-archived rules are defined in exactly one place:

```php
// app/Domain/Shared/Models/Tag.php  (replaces `use DeniesPartners`; keep `use IsolatesPartners, HasUuids`)
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query
        ->where($this->qualifyColumn('type'), TagType::Project->value)
        ->whereExists(static fn ($sub) => $sub->selectRaw('1')->from('taggables', 'partner_tg')
            ->whereColumn('partner_tg.tag_id', 'tags.id')                       // own alias: the relation query already joins taggables
            ->where('partner_tg.taggable_type', (new Project)->getMorphClass())  // alias 'project' from the morph map
            ->whereIn('partner_tg.taggable_id', Project::query()->select('projects.id')));  // Project's PartnerScope applies here
}
```

   A client-type tag never matches (`type`), a project tag attached only to a hidden or other-client project never matches (subquery), and a tag mistakenly attached to a client never matches (`taggable_type`). `Tag::all()`, a tag select and a stray `Tag::query()` all return only tags of visible own projects. The policy stays `AdminOnlyPolicy`, so the Partner has no tag screen; the tags appear only through `SpatieTagsColumn` / `TextEntry` on the Partner project resource (reads `$record->tags`).
3. Tests that must change with this: the `Tag` canary fixture (above); `DeniedModelsTest` keeps passing (its probe tag has no type and no project, so a Partner still sees 0 [VERIFIED: tests/Isolation/DeniedModelsTest.php:27-44, 68-73]) but its docblock and the "four package models" wording should be updated; add dedicated tests: Partner sees the visible project's tag, not a hidden project's tag, not another client's project tag, not a `client`-type tag, and `Tag::query()->count()` equals the number of distinct visible tags.

Fallback (Approach B, if the user prefers `Tag` to stay deny-all): a `Project::visibleTagNames()` method using `Tag::query()->withoutGlobalScope(PartnerScope::class)->withType('project')...` (allowed by the scanner because it has an argument). Rejected as the default: it re-introduces a hand-written bypass, breaks eager loading and `SpatieTagsColumn`, and moves the control from the data layer to a method that a future developer can call with the wrong model.

**Soft-delete trap (verified by reading both sources):** `HasTags::bootHasTags()` registers `static::deleted(...)` which loads `$deletedModel->tags()` and calls `detachTags($tags)` [VERIFIED: vendor/spatie/laravel-tags/src/HasTags.php `bootHasTags`]. `Model::delete()` fires `fireModelEvent('deleted', false)` after `performDeleteOnModel()` [VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:1807-1812], and `SoftDeletes::performDeleteOnModel()` runs `runSoftDelete()` when not force deleting [VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php:121-130]. So **archiving a client or project detaches all its tags, and restoring does not bring them back.** Guard it on `Client` and `Project`:

```php
use HasTags { detachTags as private detachTagsFromTrait; }

/** The package calls this from its `deleted` listener, which also fires for an archive (soft delete). */
public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
{
    if ($this->trashed() && ! $this->isForceDeleting()) {
        return $this;                       // archive keeps the tags; restore shows them again
    }

    return $this->detachTagsFromTrait($tags, $type);
}
```

Test it: archive a tagged client, assert `taggables` rows remain, restore, assert tags shown; force delete (only possible from code) still detaches.

### Pattern 3: Partner invitation flow (answers question 3)

**Tables / lifecycle.** `client_invitations` (Admin-only, `DeniesPartners`): `id`, `client_id` (FK restrict), `name`, `email` (stored lower-case, CHECK `email = lower(email)`), `token_hash` (char(64), unique), `expires_at`, `invited_by` (FK users), `last_sent_at`, `send_count`, `accepted_at`, `accepted_user_id` (FK users, restrict), `revoked_at`, timestamps. The state of CONTEXT D-01 is **derived**, not stored (`InvitationState`: pending, accepted, revoked, expired; computed from the three timestamps and `now()`), with `CHECK (NOT (accepted_at IS NOT NULL AND revoked_at IS NOT NULL))` and a partial unique index on `email` `WHERE accepted_at IS NULL AND revoked_at IS NULL`. Derived state cannot drift and `now()` cannot appear in an index predicate anyway. A pending invitation therefore occupies the e-mail until accepted, revoked or resent (resend rewrites the same row).

**Token handling.** `$plain = Str::random(64)` (or `bin2hex(random_bytes(32))`), store `hash('sha256', $plain)` only; the plain token appears only in the signed URL. SHA-256 is correct here because the token has 256 bits of entropy (a slow password hash adds nothing). Verification: look the row up by the id carried in the signed URL, then `hash_equals($row->token_hash, hash('sha256', $token))`. Resend generates a new token, overwrites `token_hash`, extends `expires_at`, bumps `send_count` and `last_sent_at`; the old URL then fails the hash check although its signature is still valid. Revoke sets `revoked_at`. Expiry: `config('kokpit.invitations.ttl_days')` = 7 added to `config/kokpit.php` as a literal, like the existing `alerts` block [VERIFIED: config/kokpit.php:50-53 `'alerts' => ['throttle_seconds' => 900, 'message_max_length' => 200],`].

**Signed link.** `URL::temporarySignedRoute('filament.admin.invitation.accept', $expiresAt, ['invitation' => $id, 'token' => $plain])` - extra parameters become the query string, so the route has **no path parameters** (important: `RouteWalkTest::walkedUrl()` throws on any route parameter other than `{record}` [VERIFIED: tests/Isolation/RouteWalkTest.php:75-88]). Register it inside the panel group with the panel `routes()` hook, which Filament's own password-reset route precedent uses with `->middleware(['signed'])` [VERIFIED: vendor/filament/filament/routes/web.php, reset route]:

```php
// AdminPanelProvider::configure()
->routes(fn () => Route::get('/invitation', AcceptInvitation::class)
    ->middleware(['signed', 'throttle:invitation'])
    ->name('invitation.accept'))      // resolves to filament.admin.invitation.accept
```

`RateLimiter::for('invitation', fn (Request $r) => Limit::perMinute(10)->by($r->ip()))` registered in a service provider. Add `Referrer-Policy: no-referrer` on the page (token is in the URL).

**The accept page.** A `Filament\Pages\SimplePage` subclass (same base as `Register`, which already has the name / password / confirmation components, `WithRateLimiting` and `CanUseDatabaseTransactions` [VERIFIED: vendor/filament/filament/src/Auth/Pages/Register.php]), holding `#[Locked]` `$invitationId` and `$token` (as Filament's reset page does for its token). It shows the invitee's e-mail read-only, a name field prefilled from the invitation, password and confirmation (`Password::min(12)` and the 72-byte cap, matching `InstallCommand` [VERIFIED: app/Console/Commands/InstallCommand.php:36 `private const int PASSWORD_MAX_BYTES = 72;` and :88 `Password::min(12),`]). It does **not** enable `->registration()`, so public self-registration stays impossible. After success it redirects to the login page with a success notification (consistent with Filament's reset flow, which does not auto-login).

Because `PanelRegistryTest` requires an `#[AccessRule]` on every concrete `Page` subclass under `app/Filament` [VERIFIED: tests/Arch/PanelRegistryTest.php:102-119, 167-171], and neither existing audience describes a guest page, add a third enum case `Audience::Guest` (documented: "reached only by its own signed route; never opens through the panel's access checks"), make `AccessRules::allows()` return `false` for it (fail-closed; the page uses no `Enforces*` trait), and add one arch assertion that `Guest` appears only on `SimplePage` subclasses that are not in `$panel->getPages()`. (Alternative: keep the class outside `app/Filament`; rejected because it hides a panel-served page from the registry test.)

**Accept action (the one sanctioned system run).** A guest has no user, so every scope is fail-closed and `ClientInvitation::query()` returns nothing. The `AcceptInvitation` Action runs inside `app(PartnerContext::class)->runAsSystem(...)` and inside a transaction, re-reads the row with `lockForUpdate()`, and re-checks "pending, not expired, hash matches". Anything wrong (unknown id, wrong token, expired, revoked, accepted) produces one identical neutral page and message, so the page is not an account or invitation oracle. Then:

```php
$user = User::query()->create(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
$user->forceFill(['client_id' => $invitation->client_id, 'email_verified_at' => now()])->save(); // client_id is not fillable on purpose
$user->assignRole(RoleName::Partner->value);
$invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
```

`User`'s fillable set is exactly `#[Fillable(['name', 'email', 'password'])]` and `client_id` is deliberately excluded [VERIFIED: app/Domain/Identity/Models/User.php:39]; `RoleName::Partner` has the value `'partner'` [VERIFIED: app/Domain/Identity/RoleName.php:17 `case Partner = 'partner';`]. A unique-violation on `users.email` (account created between invite and accept) is caught and answered with the same neutral message. The password is hashed by the model cast (`'password' => 'hashed'`).

**Invite form (D-03).** Admin header action "Pozvat partnera" on the client view page with fields name and email (email optionally chosen from the client's contacts). Validation on the e-mail field: `email`, lower-cased, and `Rule::unique('users', 'email')` plus a lower-case-insensitive check, with the Czech message; a second rule rejects an e-mail that already has a pending invitation ("use Resend"). Add a functional unique index `users_email_lower_unique ON users (lower(email))` so two accounts cannot differ only in case. The users table currently has only a plain `unique` on `email` [VERIFIED: database/migrations/0001_01_01_000000_create_users_table.php `$table->string('email')->unique();`].

**Mail.** A `PartnerInvitation` `Notification` that implements `ShouldQueue`, sent with `Notification::route('mail', $email)->notify(...)` (on-demand recipient: no user exists yet), built with `MailMessage` like the existing `OperationalAlert` [VERIFIED: app/Domain/Operations/Alerts/OperationalAlert.php `toMail`]. The constructor takes only scalars (name, client display name, URL string, expiry timestamp): a queued notification does not run through `RunsAsSystem`, and a serialised model would be re-fetched under a fail-closed scope. `config/queue.php` redis connection has `'after_commit' => true` [VERIFIED: config/queue.php:48], so a mail dispatched inside the Action's transaction is only queued after commit. It is not a `Dispatchable` class, so `JobContractTest` (every `Dispatchable` under `app/` extends `KokpitJob`) is unaffected [VERIFIED: tests/Arch/JobContractTest.php:62-72]. `ReportFailedJob` already raises an Admin alert for any failed queued job [VERIFIED: app/Domain/Operations/Alerts/ReportFailedJob.php]. Accepted risk: a failed mail job leaves the signed URL in `failed_jobs` until the invitation expires or is revoked; record it as accepted (single-use, 7 days).

### Pattern 4: Account lifecycle - deactivation, archive lockout, password reset (answers question 3, second half)

- Storage: `users.deactivated_at timestamptz NULL`, cast to datetime, set only through the Action (`forceFill`).
- Blocking: extend `User::canAccessPanel()` (currently `$this->hasAnyRole([RoleName::Admin->value, RoleName::Partner->value])` [VERIFIED: app/Domain/Identity/Models/User.php:64-67]) to also require `deactivated_at === null` and, for a Partner, an existing non-archived client. Filament calls it in the `Authenticate` middleware on **every** authenticated request and aborts 403 when false [VERIFIED: vendor/filament/filament/src/Http/Middleware/Authenticate.php `abort_if(... ! $user->canAccessPanel($panel) ..., 403)`], and the login page calls it inside a `Timebox` and returns the same generic credential error [VERIFIED: vendor/filament/filament/src/Auth/Pages/Login.php:103-108]. So one method gives: no new login, existing sessions die on their next request, remember-me cookies are useless, and no message distinguishes "deactivated" from "wrong password".
- The client lookup must be an explicit system run, because `Client` is `DeniesPartners` and a Partner would otherwise always "have no client": `app(PartnerContext::class)->runAsSystem(fn () => Client::query()->whereKey($this->client_id)->exists())` (the default `SoftDeletingScope` makes an archived client not found). Memoise per request on the user instance; do not cache across requests (a stale cache would delay an archive).
- Sessions: the session driver is Redis [VERIFIED: config/session.php:23 `'driver' => env('SESSION_DRIVER', 'redis'),`], so there is no `sessions.user_id` row to delete; `canAccessPanel` is the mechanism. A password reset additionally invalidates other sessions through the panel's `AuthenticateSession` middleware [VERIFIED: AdminPanelProvider.php:80].
- API tokens: the Action calls `$user->tokens()->delete()` (HasApiTokens; `PersonalAccessToken` is `NotPartnerScoped`). Phase 12 must also reject a deactivated user's token; note it there.
- Password reset by Admin (D-04): reuse the broker call Filament's own request page uses (`Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(...)`), including its guard that sends nothing when `canAccessPanel` is false [VERIFIED: vendor/filament/filament/src/Auth/Pages/PasswordReset/RequestPasswordReset.php:65-73]. This also means a deactivated or archived-client Partner can request a reset but silently gets no mail. Hide the action for a deactivated account.
- Archive and restore (D-11): nothing to store; `ArchiveClient` is just `$client->delete()`; the per-request client check makes the Partners' access follow the client row automatically, including restore.
- Accounts are never hard-deleted (D-04): the accounts relation manager exposes only deactivate, reactivate, send reset; no delete action; `client_invitations.accepted_user_id` and `users.client_id` foreign keys use `ON DELETE RESTRICT`. Test: the relation manager has no delete action.
- 2FA interaction: `EnsureAdminHasTwoFactor` returns `$next($request)` when the user is not an Admin or enforcement is off [VERIFIED: app/Http/Middleware/EnsureAdminHasTwoFactor.php:28-36], so a guest on the accept page and a Partner are never redirected to 2FA set-up. The invite and account actions are only reachable by an Admin who already passed 2FA. Nothing to change.

### Pattern 5: ARES lookup (answers question 4)

**Endpoint and response (live call today, structure only):** `GET https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`, no key, about 0.25-0.4 s observed. Success (HTTP 200) top-level keys and types: `ico` string; `obchodniJmeno` string; `sidlo` object; `pravniForma` string (3 digits); `pravniFormaRos` string; `financniUrad` string; `datumAktualizace` string (date `YYYY-MM-DD`); `dic` string, shape `CZ` + 8 digits, **optional**; `icoId` string; `adresaDorucovaci` object (`radekAdresy1..3` strings); `czNace2008` list; `czNace` list; `seznamRegistraci` object of `stavZdroje*` strings (VAT state in `stavZdrojeDph`, observed value `AKTIVNI`); `primarniZdroj` string; `dalsiUdaje` list. `sidlo` keys: `kodStatu` string (`CZ`), `nazevStatu`, `kodKraje` int, `nazevKraje`, `kodObce` int, `nazevObce` string, `kodUlice` int, `nazevUlice` string, `cisloDomovni` **int**, `cisloOrientacni` **int**, `nazevCastiObce` string, `psc` **int**, `textovaAdresa` string (comma-separated: street and number, municipality part, postal code and municipality), `standardizaceAdresy` bool, `typCisloDomovni` int, plus district fields. [VERIFIED: live ARES call 2026-10-08; values deliberately not recorded]

Error shapes [VERIFIED: live ARES calls 2026-10-08]:

| Case | HTTP | Body keys |
|------|------|-----------|
| Unknown company (well-formed 8 digits) | 404 | `kod` = `NENALEZENO`, `popis` (Czech text), `subKod` = `VYSTUP_SUBJEKT_NENALEZEN` |
| Malformed id (not 8 digits) | 400 | `kod` = `CHYBA_VSTUPU`, `popis`, `subKod` = `VSTUP_NEVALIDNI_FORMAT_ICO` |
| Checksum-invalid but 8 digits | 404 (not 400) | ARES does not check mod-11; validate locally |

Not observed (treat as `[ASSUMED]`, handled generically): 429 rate limit, 5xx, maintenance pages, a `cisloOrientacniPismeno` letter field, a missing `nazevUlice` for villages (fall back to `nazevCastiObce`, then `nazevObce`, then `textovaAdresa`). The published fair-use guidance (about 500 queries a minute, repeated identical queries) is MEDIUM confidence from STACK.md; a manual button is far below it.

**Design.**
- `CompanyId::isValid(string): bool` (pure, unit-tested): exactly 8 digits; sum = 8*d1 + 7*d2 + 6*d3 + 5*d4 + 4*d5 + 3*d6 + 2*d7; `r = sum % 11`; check digit = `1` if `r = 0`, `0` if `r = 1`, else `11 - r`; must equal `d8`. Verified by hand on one public body's id (check digit 7 reproduced) and on the placeholder: `12345678` has `sum = 112`, `r = 2`, expected check digit 9, so the placeholder is checksum-**invalid** (use it as the "fails locally, no HTTP call" fixture). Checksum-valid fictional ids for the success path must be generated at test time from fragments (compute the check digit of seven random digits), never written as a literal.
- Validation lives in a Laravel rule class (`CompanyIdRule`) used both by the form (only when country is `CZ`) and by `AresClient`; the client refuses before building the URL, so nothing unvalidated reaches the URL.
- `AresClient::lookup(string $ico): AresCompany`; throws `AresLookupFailed` carrying an `AresFailure` enum case (`InvalidId`, `NotFound`, `Unavailable`, `RateLimited`, `Malformed`). HTTP: `Http::acceptJson()->connectTimeout(3)->timeout(5)->retry(2, 200, when: fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)`. **The `when` callback is required**: without it Laravel 13 retries every failed response, including 404 and 400 [VERIFIED: vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1097 `$shouldRetry = $this->retryWhenCallback ? call_user_func(...) : true;`]. Base URL in `config('services.ares.base_url')` (a public government endpoint, not sensitive) so tests fake one host.
- Cache successful lookups 1 hour by id (`Cache::remember`, Redis store; stores the DTO as an array). Do not cache failures. Add a per-user `RateLimiter` (for example 10 a minute) to the button action; a limit hit is `RateLimited`.
- DTO: `final readonly class AresCompany` with `companyId`, `name`, `taxId` (nullable), `street`, `city`, `postalCode` (string, `str_pad((string) $psc, 5, '0', STR_PAD_LEFT)` because `psc` is an integer and loses leading zeros), `country` (`kodStatu`, default `CZ`), built by `AresCompany::fromResponse(array)`; a response missing `ico` or `obchodniJmeno` is `Malformed`. Street line = `nazevUlice` (or fallbacks above) + ' ' + `cisloDomovni` + ('/' + `cisloOrientacni` if present).
- Field mapping (only these form fields; D-08): `name` <- `obchodniJmeno`; `company_id` <- `ico`; `tax_id` <- `dic` (**only when ARES returns one**; an absent `dic` leaves the typed value alone, with a notice - see Assumption A5); `street`, `city`, `postal_code`, `country` <- `sidlo`. Currency, rate, payment terms, language, online flag, stage, contacts and tags are never touched.
- Foreign clients (D-09): the suffix action is `->visible(fn (Get $get): bool => $get('country') === 'CZ')`; the `country` select is `->live()`; the checksum rule is applied only for `CZ`; a non-CZ client's company id is a free string.

User-facing behaviour (all are a field error on `company_id`, form otherwise unchanged; saving is never blocked; Czech strings in `lang/cs/kokpit.php`):

| Failure | Cause | Message intent |
|---------|-------|----------------|
| `InvalidId` | not 8 digits or checksum fails (local, no HTTP) | "IČO nemá platný tvar nebo kontrolní číslici." |
| `NotFound` | 404 | "ARES tento subjekt nenašel." |
| `Unavailable` | timeout, connection error, 5xx after retry | "ARES teď neodpovídá. Zkuste to později nebo údaje vyplňte ručně." |
| `RateLimited` | 429 or local limiter | "Příliš mnoho dotazů do ARES, chvíli počkejte." |
| `Malformed` | invalid JSON or missing `ico` / `obchodniJmeno` | "ARES vrátil nečekanou odpověď. Údaje vyplňte ručně." |

**Filament 5 action wiring.** A `suffixAction` on the company-id `TextInput` (API exists: `HasAffixes::suffixAction(Action | Closure $action, bool | Closure $isInline = false)` [VERIFIED: vendor/filament/forms/src/Components/Concerns/HasAffixes.php:90]). The action has no modal, so a `ValidationException` thrown from it is re-thrown to Livewire and shown against the field; Filament rolls back and unmounts a modal-less action first [VERIFIED: vendor/filament/actions/src/Concerns/InteractsWithActions.php:382-393]. Throw it with the component's own state path so it lands next to the field in any form (`data.company_id` on a resource page):

```php
Action::make('ares')
    ->label(__('kokpit.ares.button'))
    ->icon(Heroicon::OutlinedMagnifyingGlass)
    ->visible(fn (Get $get): bool => $get('country') === 'CZ')
    ->action(function (Get $get, Set $set, TextInput $component, AresClient $ares): void {
        try {
            $company = $ares->lookup((string) $get('company_id'));
        } catch (AresLookupFailed $failure) {
            throw ValidationException::withMessages([$component->getStatePath() => $failure->userMessage()]);
        }

        // Only after success: nothing is written when the lookup failed.
        $changed = [];
        foreach ($company->formState($get('tax_id')) as $field => $value) {      // name, company_id, tax_id?, street, city, postal_code, country
            if ($get($field) !== $value) { $changed[] = $field; }
            $set($field, $value);
        }
        $set('ares_changed', $changed);                                           // hidden, ->dehydrated(false)
    })
```

Highlight changed fields without any CSS build (the repository no longer builds frontend assets): each ARES-sourced input uses `->hint(fn (Get $get): ?string => in_array('name', $get('ares_changed') ?? [], true) ? __('kokpit.ares.filled') : null)->hintColor('success')` (`hint()` and `hintColor()` exist [VERIFIED: vendor/filament/forms/src/Components/Concerns/HasHint.php:92, 102]); clear `ares_changed` when the form is saved. Test with `Livewire::test(CreateClient::class)->callFormComponentAction('company_id', 'ares')` or `callAction` per the Filament testing API (verify the exact helper name when writing the test).

### Pattern 6: Database design (answers question 5)

Conventions read from the repository: every key column is `uuid`, primary keys default to `uuidv7()`, timestamps are `timestampsTz()`, no auto-increment; the schema rules R1-R9 run over the whole catalogue and fail on any violation [VERIFIED: tests/Support/PgSchema.php rules R1-R6, tests/Feature/Schema/SchemaConventionsTest.php]. Existing precedent for raw-SQL constraints: `number_sequences` adds `CHECK` constraints through `DB::statement` [VERIFIED: database/migrations/2026_10_07_000100_create_number_sequences_table.php]. Money convention (STATE): `<name>_minor` bigint plus `<name>_currency` char(3) with `CHECK ~ '^[A-Z]{3}$'`, `MoneyCast` over the pair [VERIFIED: app/Domain/Shared/Money/MoneyCast.php docblock and `set()` writing `"{$key}_minor"` / `"{$key}_currency"`]. There is no enum column precedent yet: store enums as `string` + `CHECK (col IN (...))` (portable, alterable by a migration; a native PostgreSQL enum is hard to change) [ASSUMED - first enum columns in the project].

`clients` (soft deletes): `id`; `name` varchar(255) not null; `company_id` varchar(32) null; `tax_id` varchar(32) null; `country` char(2) not null default `'CZ'` CHECK `~ '^[A-Z]{2}$'`; `street`, `city` varchar(255) null; `postal_code` varchar(20) null; `stage` varchar(16) not null default `'active'` CHECK in (`lead`,`active`,`paused`,`ended`); `currency` char(3) not null CHECK `~ '^[A-Z]{3}$'`; `hourly_rate_minor` bigint not null CHECK `>= 0`; `hourly_rate_currency` char(3) not null with CHECK `hourly_rate_currency = currency` (one source of truth enforced by the database); `payment_terms_days` smallint not null CHECK between 0 and 365 (the same range as `InvoicingSettings`, `'payment_due_days' => ['required', 'integer', 'between:0,365']` [VERIFIED: app/Domain/Settings/Settings/InvoicingSettings.php:35]); `invoice_email` varchar(255) null; `invoice_language` varchar(5) not null CHECK in (`cs`,`en`) [ASSUMED language set]; `online_payment_enabled` boolean not null default false; `deleted_at` timestamptz null; timestamps. Indexes: unique `(country, company_id)` `WHERE company_id IS NOT NULL` - **without** a `deleted_at` predicate, so an archived client keeps reserving its id and a restore can never collide (the Admin form shows "client exists, archived - restore it"); index on `name`; `stage`.

`contacts`: `id`; `client_id` uuid FK `ON DELETE RESTRICT`; `name` varchar(255) not null; `email` varchar(255) null; `phone` varchar(50) null; `position` varchar(255) null; `is_primary` boolean not null default false; `is_billing` boolean not null default false; timestamps. **No soft delete** (a contact is plain data; later invoices snapshot the recipient; the activity log records the delete). `CREATE UNIQUE INDEX contacts_one_primary_per_client ON contacts (client_id) WHERE is_primary`. The index guarantees *at most* one primary; "exactly one" needs the Action layer (the first contact becomes primary; deleting the primary while others exist is refused; `SetPrimaryContact` demotes the old primary before promoting the new one inside a transaction because a partial unique index cannot be deferred). An optional `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` can enforce "at least one primary when any contact exists" [ASSUMED nice-to-have]; see Open Question 5.

`projects` (soft deletes): `id`; `client_id` uuid FK `RESTRICT` not null, indexed; `name` varchar(255) not null; `key` varchar(6) not null with `CHECK (key ~ '^[A-Z]{2,6}$')`; `description` text null; `status` varchar(24) not null default `'planned'` CHECK in (`planned`,`to_clarify`,`in_progress`,`in_review`,`ready_to_release`,`done`); `priority` varchar(8) not null default `'normal'` CHECK in (`low`,`normal`,`high`,`urgent`); `start_date` date null; `end_date` date null with `CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date)`; `client_visible` boolean not null default false; `deleted_at`; timestamps. `UNIQUE (key)` as a plain unique index **including archived rows**: Phase 5 task keys (`ABC-12`) stay valid references after a project is archived, so the key remains reserved. Index `(client_id, client_visible)` serves the Partner scope.

`project_billing` (1:1, Admin-only): `id`; `project_id` uuid not null `UNIQUE`, FK `ON DELETE RESTRICT` (an own `id` primary key, not `project_id` as primary key, because schema rule R6 demands a `uuidv7()` default on any single-column uuid primary key); `billing_type` varchar(16) not null CHECK in (`hourly`,`fixed_price`) (placed here because D-07 says the Partner must never see billing type, see Open Question 2); `hourly_rate_minor` bigint null + `hourly_rate_currency` char(3) null (both null or both set, `>= 0`); `fixed_price_minor` + `fixed_price_currency` likewise; `estimate_minutes` integer null CHECK `>= 0`; `internal_note` text null; optional `CHECK (billing_type <> 'fixed_price' OR fixed_price_minor IS NOT NULL)` [ASSUMED]; timestamps. The Action writes the project and its billing row in one transaction and always creates the row, so consumers can rely on `billing` existing.

Currency rule (flagged risk): D-15 says the project currency is always the client's currency, but the amounts are stored per row. Recommended rule: (1) the Action rejects project money whose currency differs from the client's; (2) the client's `currency` (and with it `hourly_rate_currency`) cannot be changed while any `project_billing` row of that client holds money - the form shows a field error naming the reason; (3) a project's client cannot be changed after creation (the field is read-only on edit), which also keeps the later task/time/invoice ownership chain simple [ASSUMED rule 3; see Open Question 4].

`client_invitations`: described in Pattern 3 (unique `token_hash`, partial unique pending e-mail, FKs `RESTRICT`).

`users` alteration (one migration): `deactivated_at timestamptz null`; `ALTER TABLE users ADD CONSTRAINT users_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT` (the column and its index already exist; the original migration deferred the FK to this phase [VERIFIED: 0001_01_01_000000_create_users_table.php comment "No foreign key until the clients table exists in Phase 4"]); `CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))`. The migration order must create `clients` first.

**Activity log allowlists** (declare on each concrete model with `use LogsAllowlistedActivity` and `#[LoggedAttributes([...])]`; attributes are not inherited; all names must be real columns and none sensitive [VERIFIED: tests/Support/AuditDeclaration.php rules a-e, sensitive pattern at line 30]):
- `Client`: `name`, `company_id`, `tax_id`, `country`, `street`, `city`, `postal_code`, `stage`, `currency`, `hourly_rate_minor`, `hourly_rate_currency`, `payment_terms_days`, `invoice_email`, `invoice_language`, `online_payment_enabled`.
- `Contact`: `client_id`, `name`, `email`, `phone`, `position`, `is_primary`, `is_billing`.
- `Project`: `client_id`, `name`, `key`, `status`, `priority`, `start_date`, `end_date`, `client_visible`.
- `ProjectBilling`: `project_id`, `billing_type`, `hourly_rate_minor`, `hourly_rate_currency`, `fixed_price_minor`, `fixed_price_currency`, `estimate_minutes` (rate changes are exactly what an audit trail is for; `Activity` is Admin-only).
- `ClientInvitation`: optional (`email`, `name`, `expires_at`, `accepted_at`, `revoked_at`); never `token_hash`.
The morph alias becomes the log name (`useLogName($this->getMorphClass())`), so the aliases below also need Czech subject labels under `kokpit.activity.subjects` (currently an empty array [VERIFIED: lang/cs/kokpit.php:203]).

**Morph map additions** (one line each; aliases are snake_case singular nouns; the map is enforced and R9 checks stored types [VERIFIED: app/Domain/Shared/Database/MorphMap.php:26-35]): `'client' => Client::class`, `'contact' => Contact::class`, `'project' => Project::class`, `'project_billing' => ProjectBilling::class`, `'client_invitation' => ClientInvitation::class`.

### Pattern 7: Filament 5 resources (answers question 6)

Filament 5 differs from 4 only by requiring Livewire 4 (the upgrade page lists PHP 8.2+, Laravel 11.28+, Livewire 4, Tailwind 4 and no resource/form API changes [CITED: filamentphp.com/docs/5.x/upgrade-guide]); the installed code uses the v4 `Schema`-based API (`Filament\Schemas\Schema`, `Filament\Actions\*`) and `Heroicon` icons [VERIFIED: app/Filament/Pages/SettingsPage.php imports]. Context7 was not available in this session; API names below were checked against the installed vendor source.

`ClientResource` (`#[AccessRule(Audience::AdminOnly, ...)]` + `use EnforcesResourceAccessRule`, `$recordTitleAttribute = 'name'`, `$isGloballySearchable = false`): pages `index`, `create`, `view`, `edit`. Form in sections: "Fakturační údaje" (country `Select` `->live()`, company id with the ARES `suffixAction`, name, tax id, street, city, postal code), "Obchodní podmínky" (stage, currency `Select` over `Money::isoCurrencyCodes()` as the settings page does, hourly rate text input parsed by `Money::fromMajor`, payment terms days, invoice language, invoice e-mail, online-payment toggle), "Štítky" (`SpatieTagsInput::make('tags')->type('client')`). Rate input follows the Settings page pattern: state is a string with a decimal comma, converted with `Money::fromMajor($state, $currency)` in the page's `mutateFormDataBeforeCreate/Save`, errors become field errors; keep the conversion in the Action. Defaults (D-13) via `->default(fn () => ...)` closures on create. Table: name, company id, stage badge, currency, tags (`SpatieTagsColumn::make('tags')->type('client')`), primary contact (selected with a subquery, not a per-row query); filters: stage `SelectFilter`, `TrashedFilter`. Archive/restore: `DeleteAction::make()->label(...)` worded "Archivovat" and `RestoreAction`, bulk equivalents, **no** `ForceDeleteAction`/bulk. Make archived records reachable by URL by overriding `getRecordRouteBindingEloquentQuery()` with `->withoutGlobalScopes([SoftDeletingScope::class])` (the argument form is allowed by `QueryEscapeHatchTest` [VERIFIED: tests/Arch/QueryEscapeHatchTest.php "does not report withoutGlobalScopes with an argument"]); Filament routes the record through `resolveRecordRouteBinding(... getRecordRouteBindingEloquentQuery())` [VERIFIED: vendor/filament/filament/src/Resources/Resource/Concerns/HasRoutes.php:41-55]. Archived clients vanish from every picker and list for free because the `SoftDeletingScope` is on the model; `Select::make('client_id')->relationship('client', 'name')` on the project form therefore offers active clients only.

Relation managers on the client (each with `#[AccessRule(Audience::AdminOnly, reason)]` and `EnforcesRelationManagerAccessRule`, registered in `getRelations()` so the registry test sees them): `ContactsRelationManager` (table, create/edit modal, row action "Nastavit jako primární" calling `SetPrimaryContact`, billing toggle; **not** a repeater: a repeater rewrites the whole collection and fights the partial unique index), `PartnerAccountsRelationManager` (relationship `users` on `Client` by `client_id`; actions deactivate, reactivate, send reset; no delete), `InvitationsRelationManager` (state badge from `InvitationState`, resend, revoke; invite is a header action on `ViewClient`), and `ClientHistoryRelationManager extends ActivityHistoryRelationManager` with its own `#[AccessRule]` [VERIFIED: app/Filament/RelationManagers/ActivityHistoryRelationManager.php docblock requires a concrete subclass with its own declaration]. Every model used by a relation manager needs a policy (strict authorization): `ContactPolicy`/`ClientInvitationPolicy`/`UserPolicy` can all be `AdminOnlyPolicy` via `#[UsePolicy]`.

`ProjectResource` (AdminOnly): form sections "Projekt" (client select, name, key, description, status, priority, dates, `client_visible` toggle with helper text, tags `->type('project')`) and "Fakturace" (billing type, hourly rate, fixed price, estimate, internal note) whose fields map to `project_billing` through the `CreateProject`/`UpdateProject` Actions (call them from `handleRecordCreation()` / `handleRecordUpdate()`; do not rely on Filament's `->relationship()` for `MoneyCast` pairs, because the virtual `hourly_rate` attribute is not in `attributesToArray()`). `mutateFormDataBeforeFill()` loads the billing row into the flat form state. Table: name, key, client, status badge, priority badge, visible icon, tags, plus `ProjectHistoryRelationManager`.

`PartnerProjectResource` (`#[AccessRule(Audience::PartnerAllowed, ...)]`, slug `my-projects` to avoid a route-name clash with the Admin `projects` slug; `canAccess()` additionally requires a Partner so the Admin does not get a duplicate navigation entry): pages `index` and `view` only; table columns name, key, status, priority, dates, tags (read-only); infolist with name, key, status, description, dates, priority, tags; no actions, no bulk actions, no relation managers, no client column. Share column / entry definitions between the two resources through one support class to prevent drift.

`globalSearch(false)` stays; "search" in success criterion 5 is satisfied by table search plus the global-search test below.

Filament pitfalls found: `PanelRegistryTest` and the access traits apply to every concrete `Resource`, `Page`, `Widget`, `RelationManager` and cluster (resource pages are exempt) [VERIFIED: tests/Arch/PanelRegistryTest.php:102-119]; a class method overrides a trait method, so a resource that overrides `canAccess()` must call the declaration too (the traits' boot hooks protect pages and relation managers against widening, the resource trait does not); `RelationManager` and widget traits additionally abort in `boot`.

### Pattern 8: Project key suggestion (answers question 7)

`ProjectKeySuggester::suggest(string $name, Closure $isTaken): string`, pure and unit-tested:
1. `Str::ascii($name)` (transliterates Czech diacritics), uppercase.
2. Split on anything that is not a letter; drop digits and empty tokens (`CHECK ^[A-Z]{2,6}$` allows no digits); drop single-letter words when at least two longer words remain (Czech prepositions such as "v", "s", "a", "k").
3. Base: more than one word -> initials of the words, at most 6; exactly one word -> its first 4 letters (3 if the word has only 3). If the base has fewer than 2 letters (name "!!!", "123", "X") -> `PRJ`.
4. Collision variants, deterministic order: for initials, extend with following letters of the first word, then of the second, up to 6 letters; then take the first 5 letters and append `A`..`Z`; the loop checks `$isTaken` including archived projects (`Project::withTrashed()` - no-argument `withoutGlobalScopes()` is not needed).
5. Return the first free candidate.

Run it from the Filament form: the name input is `->live(onBlur: true)` and its `afterStateUpdated` sets `key` **only on create and only while the user has not edited the key** (a hidden, non-dehydrated `key_touched` flag set by the key input's own `afterStateUpdated`; `$set()` calls do not fire the target's hooks by default). The key input uppercases on dehydrate, has `->maxLength(6)`, `->regex('/^[A-Z]{2,6}$/')` and `->unique(ignoreRecord: true)` (the form check; Filament builds `Rule::unique` and passes `ignoreRecord` [VERIFIED: vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:567-597]). The DB unique index is the authority: `CreateProject` / `UpdateProject` catch `Illuminate\Database\UniqueConstraintViolationException`, check that the message names the key index, and rethrow `ValidationException::withMessages(['data.key' => ...])`; the same pattern turns a company-id unique violation into a `data.company_id` error. Test it with two racing inserts simulated by inserting between validation and save.

Phase 5 readiness: **leave nothing in `projects`.** CONTEXT names a `projects.next_task_number` column, but the repository already built the counter as a generic `number_sequences` table with keys like `task:<project uuid>` [VERIFIED: database/migrations/2026_10_07_000100_create_number_sequences_table.php header comment "A key is "kind:qualifier", for example invoice:2026 or task:<project uuid>"]. A second counter column would duplicate it. The frozen-key rule in Phase 5 can reuse the same key column with an Action check or a trigger. Surfaced as Open Question 6.

### Pattern 9: Defaults prefill (answers question 8)

Read from the real classes; settings are read through the container (`app(DefaultsSettings::class)`), only in Admin / system context because `SettingsProperty` is `DeniesPartners` [VERIFIED: tests/Isolation/CanaryRegistryTest.php lists it as a model with a fixture; app/Domain/Shared/Models].

| Client field | Source | Notes |
|--------------|--------|-------|
| `currency` | `DefaultsSettings::$default_currency` (`public string $default_currency;`, line 22) | ISO 4217 code, initial `CZK` |
| `hourly_rate` | `DefaultsSettings::$default_hourly_rate` (`public Money $default_hourly_rate;`, line 24) | initial value is 0 CZK (settings migration `['minor' => 0, 'currency' => 'CZK']`), so a zero default rate is normal; form text via `str_replace('.', ',', $rate->toMajor())` as `toFormState()` does |
| `payment_terms_days` | `InvoicingSettings::$payment_due_days` (`public int $payment_due_days;`, line 21; rule `between:0,365`) | initial 14 |
| `online_payment_enabled` | `PaymentSettings::$online_payments_enabled` (`public bool $online_payments_enabled;`, line 15) | D-13 does not list it; recommended default is the global value |
| `invoice_language` | **no setting exists** | See Open Question 1: add `default_invoice_language` to `DefaultsSettings` (new file in `database/settings/`, tab field, `cs` default) or use a constant `cs` |
| `country` | `SupplierSettings::$country` (rule `'country' => ['required', 'regex:/^[A-Z]{2}$/']`, default `CZ`) | sensible default for a new client |
| `stage` | none | recommend default `active` [ASSUMED] |

The values are copied into the client row at creation and never re-read (D-13).

### Pattern 10: Czech localisation (answers question 9)

- Enum labels: each enum implements `Filament\Support\Contracts\HasLabel` and returns `__('enums.<snake_case_enum>.'.$this->value)`, exactly as `RoleName` and `VatMode` do; the translations live in `lang/cs/enums.php` [VERIFIED: lang/cs/enums.php groups `role_name`, `vat_mode`, ...; app/Domain/Settings/VatMode.php `getLabel()`]. `EnumLabelsTest` scans every `HasLabel` enum under `app/` and fails on a missing or key-looking label [VERIFIED: tests/Feature/Localisation/EnumLabelsTest.php]. New groups: `client_stage`, `invoice_language`, `invitation_state`, `project_status`, `project_priority`, `billing_type`.
- Page, resource, field and message strings: new sections in `lang/cs/kokpit.php` (`clients`, `contacts`, `projects`, `invitations`, `partner_accounts`, `ares`, `partner_projects`); navigation labels follow `kokpit.<area>.navigation_label/navigation_group` as `ActivityResource` does; validation attribute names and the `Rule` messages in the same file. Activity subject labels: `kokpit.activity.subjects.client|contact|project|project_billing`.
- Wording to confirm during planning (CONTEXT specifics): "Ready to release" = "K vypuštění". Other labels (client stage, priority, billing type, invitation states) are Claude's discretion; suggested: Zájemce / Aktivní / Pozastavený / Ukončený; Nízká / Normální / Vysoká / Naléhavá; Hodinová sazba / Pevná cena; Čeká / Přijata / Zrušena / Vypršela.
- Email wording lives in the notification via `__()`; the app locale is `cs` (`APP_LOCALE=cs` [VERIFIED: .env.example:11]).
- Country names: Czech names from `symfony/intl` `Countries::getNames('cs')` if installed.
- Search: Filament default `ILIKE` search is diacritics-sensitive ("novak" does not find "Novák"). The `unaccent` extension is available in the DDEV PostgreSQL 18 image (`pg_available_extensions` lists `unaccent` 1.1, `pg_trgm`, `citext`) [VERIFIED: ddev psql, 2026-10-08], but availability on the production host is not verified. Recommendation: keep default search in this phase and record the diacritics limitation as a known gap; decide the extension with the first deploy check [ASSUMED].

### Anti-Patterns to Avoid
- **One Resource with `->visible()` toggles for Admin and Partner:** forbidden for anything money-related by the architecture research; use the two resources.
- **`Tag` kept deny-all plus a bypass accessor:** Approach B above; only as an explicit fallback.
- **A repeater for contacts:** rewrites the collection and conflicts with the partial unique index.
- **Storing invitation state as a column:** drifts from the timestamps; derive it.
- **Reading `Client` in `canAccessPanel()` without `runAsSystem`:** every Partner would be locked out (Client is deny-all).
- **Serialising Eloquent models into the queued invitation notification:** the worker re-fetches them under a fail-closed scope.
- **Putting business rules in Filament callbacks or observers:** Actions + DB constraints (ARCHITECTURE.md Anti-Pattern 6).
- **`Http::retry(n, ms)` with no `when`:** retries 404 and 400.
- **Writing real or checksum-valid-looking company ids in tests:** assemble at runtime; use `Http::preventStrayRequests()`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Invitation signature and expiry | Own HMAC / expiry parsing | `URL::temporarySignedRoute` + the `signed` middleware | Constant-time verification, expiry in the signature; Filament's reset route uses the same |
| Token comparison / hashing | Custom comparison, bcrypt for a 256-bit token | `hash('sha256', ...)` + `hash_equals` | Fast and sufficient for high-entropy tokens |
| Password reset by Admin | Own reset tokens | `Password::broker(Filament::getAuthPasswordBroker())->sendResetLink` with Filament's notification | Existing throttling, token storage, and the `canAccessPanel` guard |
| Rate limiting | Counter in cache | `RateLimiter` / `throttle:` middleware / `WithRateLimiting` on the page | Already the pattern in Filament pages |
| Tag storage and sync | Own tag tables | `spatie/laravel-tags` + `SpatieTagsInput->type()` | Slugging, translation, morph pivot; only the soft-delete guard is ours |
| Money parsing / minor units | `floatval`, `round` | `Money::fromMajor` / `MoneyCast` | The single rounding / parsing point; floats are banned |
| ISO currency validation | A hard-coded list | `Money::isKnownCurrency` / `KnownCurrency` rule | Already exists |
| Retry and timeout of HTTP | Manual loops | `Http::connectTimeout()->timeout()->retry(..., when:)` | Built in; needs the `when` callback |
| UUID v7 keys | Custom generators | `KokpitModel` (`HasUuids`) and `uuidv7()` defaults | Schema rules enforce it |
| Archive / restore UI | Custom flags | `SoftDeletes` + `TrashedFilter` / `DeleteAction` / `RestoreAction` | Standard Filament behaviour |
| Company-id checksum | A package | A 15-line pure class + unit test | No dependency worth adding; algorithm below |
| Key suggestion | A slug library | `Str::ascii` + a small service | Needs custom rules (2-6 letters, no digits) |

**Key insight:** the dangerous parts of this phase (isolation, token handling, sessions, tags) all have a framework or repository mechanism already; the work is wiring them fail-closed and proving it with canary tests, not building new machinery.

## Common Pitfalls

### Pitfall 1: FK on `users.client_id` and the client-active check break the existing suite
**What goes wrong:** `Canary::partnerFor($clientId)` creates users with `client_id` set to a random UUID that has no `clients` row [VERIFIED: tests/Support/Canary.php:42-45 `twoClients()` returns two `Str::uuid7()` values]. After the FK migration the insert fails; after the `canAccessPanel` client check every such Partner is locked out. About 15 test files use these helpers (counted by grep: Isolation x6, Feature/Operations x5, Feature/Auth x2, Feature/Schema x2).
**How to avoid:** Wave 0 changes `Canary::twoClients()` to create two real fictional `Client` rows (system run) and return their ids; keep the signature so callers do not change. Where a test needs "a Partner whose client does not exist", build it explicitly.
**Warning signs:** `SQLSTATE[23503]` in Isolation tests; Partner tests suddenly 403.

### Pitfall 2: Archiving a client or project erases its tags
**What goes wrong:** see Pattern 2. **How to avoid:** the `detachTags` guard + test. **Warning signs:** tags gone after restore.

### Pitfall 3: The accept page is invisible to the scopes
**What goes wrong:** the guest request reads `ClientInvitation` through a fail-closed scope and finds nothing; the invitation flow "works in the admin test, fails for the invitee". **How to avoid:** the Action runs in `runAsSystem`; the Livewire page calls the Action for every read and write (each Livewire request has its own `PartnerContext`). Test the page as a guest with no `actingAs`.

### Pitfall 4: Account or invitation enumeration
**What goes wrong:** distinct messages for unknown / used / expired / revoked links, or for "an account exists" on the accept page. **How to avoid:** one neutral message; the existing-account error appears only in the Admin invite form (D-03), never to the invitee. Filament's login and reset already answer generically.

### Pitfall 5: `Http::retry` retries 404
See Pattern 5. A 404 would cost 200 ms and a duplicate request on every unknown id.

### Pitfall 6: ARES integers
`psc` is an int (leading zero lost), house numbers are ints, `dic` may be absent, the street may be absent. A mapper that casts blindly stores "1100" instead of "01100".

### Pitfall 7: Route walk and new routes
`RouteWalkTest::walkedUrl()` throws for any route parameter except `{record}`. Resource record routes are fine; the accept route has no path parameter by design. The walk also requests Admin resources as a Partner and expects 200/302/403/404 with no canary of client B: the new resources must be `AdminOnly` (403) or properly scoped.

### Pitfall 8: Partner-visible `projects` column creep
A later phase adds a column (an internal note, a cost) to `projects` because "it is just a project field". **How to avoid:** `projects` column allowlist test; Admin-only attributes go to `project_billing` or a new Admin-only table.

### Pitfall 9: Mass assignment of ownership columns
`Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction())` throws in dev and test but **silently discards in production** [VERIFIED: app/Providers/ModelConventionsServiceProvider.php:40]. Use `#[Fillable]` listing only user-editable columns; set `client_id` through the relation (`$client->contacts()->create(...)`), `forceFill` in an Action, or the explicit property; test it with an exception-throwing model in the test environment.

### Pitfall 10: Primary-contact index vs. update order
Promoting a new primary before demoting the old one violates the partial unique index. Demote first, in one transaction; the Filament toggle must call the Action, not bind `is_primary` directly.

### Pitfall 11: Company-id uniqueness and archived clients
If archived rows are excluded from the unique index, a restore can collide with a re-created client. The recommended index includes archived rows and the form explains it.

### Pitfall 12: Filament `unique` / `exists` rules as oracles
For the Admin only; a Partner never submits these forms. Do not add `unique` rules to any form a Partner can open (none in this phase).

### Pitfall 13: Real or realistic ARES data in the repository
The repository is public. Fixtures use invented payloads; ids assembled at runtime; `Http::preventStrayRequests()` is set in the test bootstrap so a missing fake fails instead of calling the live API. Do not paste a recorded real response into a test, fixture or note.

### Pitfall 14: Stale `Audience` assumption in the guest page
Adding a page under `app/Filament` without an `#[AccessRule]` fails `PanelRegistryTest`. Resolve with `Audience::Guest` (Pattern 3), not by annotating the page `PartnerAllowed`.

## Code Examples

### Mod-11 company id check
```php
// Source: standard Czech ICO checksum; verified by hand against one public record and the placeholder 12345678 (invalid)
final class CompanyId
{
    public static function isValid(string $id): bool
    {
        if (preg_match('/^\d{8}$/D', $id) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (range(0, 6) as $i) {
            $sum += (int) $id[$i] * (8 - $i);
        }

        $remainder = $sum % 11;
        $check = match ($remainder) { 0 => 1, 1 => 0, default => 11 - $remainder };

        return $check === (int) $id[7];
    }
}
```

### ARES HTTP call skeleton
```php
// Source: Laravel 13 Http client (retry semantics verified in vendor PendingRequest)
$response = Http::acceptJson()
    ->connectTimeout(3)->timeout(5)
    ->retry(2, 200, when: static fn (Throwable $e): bool => $e instanceof ConnectionException
        || ($e instanceof RequestException && $e->response->serverError()), throw: false)
    ->get(config('services.ares.base_url').'/'.$ico);

return match (true) {
    $response->successful() => AresCompany::fromResponse($response->json() ?? throw new AresLookupFailed(AresFailure::Malformed)),
    $response->status() === 404 => throw new AresLookupFailed(AresFailure::NotFound),
    $response->status() === 400 => throw new AresLookupFailed(AresFailure::InvalidId),
    $response->status() === 429 => throw new AresLookupFailed(AresFailure::RateLimited),
    default => throw new AresLookupFailed(AresFailure::Unavailable),
};   // a ConnectionException after the last retry must be caught and mapped to Unavailable
```

### `canAccessPanel` with deactivation and archived-client lockout
```php
public function canAccessPanel(Panel $panel): bool
{
    if (! $this->hasAnyRole([RoleName::Admin->value, RoleName::Partner->value])) {
        return false;
    }

    if ($this->deactivated_at !== null) {
        return false;
    }

    if ($this->hasRole(RoleName::Admin->value)) {
        return true;
    }

    // Partner: the client must exist and not be archived. Client is deny-all for a Partner, so this one
    // reviewed read is an explicit system run (D-11).
    return $this->clientIsActive ??= is_string($this->client_id) && app(PartnerContext::class)->runAsSystem(
        fn (): bool => Client::query()->whereKey($this->client_id)->exists(),
    );
}
```

### Canary helper change (Wave 0)
```php
// tests/Support/Canary.php: ids now point at real, fictional clients
public static function twoClients(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => [
        Client::factory()->create()->id,
        Client::factory()->create()->id,
    ]);
}
```

### Deactivation Action
```php
DB::transaction(function () use ($user): void {
    $user->forceFill(['deactivated_at' => now()])->save();   // not fillable, on purpose
    $user->tokens()->delete();                               // Sanctum tokens
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Legacy ARES XML interface | REST JSON `ekonomicke-subjekty-v-be` | superseded | Use only the REST endpoint (STACK.md) |
| `Http::retry()` retrying everything | `retry(..., when:)` callback | Laravel 8+ | Required to avoid retrying 404 |
| Filament 3/4 plugin ranges | Filament 5 plugins at `^5.x` | Filament 5 | Tags plugin `^5.10` |
| Unscoped Filament global search | Panel-level `globalSearch(false)` plus per-resource opt-in (`globalSearchResourceOptIn`, `$isGloballySearchable`) | Filament 5 | Keep disabled; `canGloballySearch()` also needs a record title attribute [VERIFIED: vendor/filament/filament/src/Resources/Resource/Concerns/HasGlobalSearch.php] |

**Deprecated/outdated:**
- Table-per-role user models and `statefulApi()` mixing: not applicable; single `users` table with roles.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Enums are stored as `string` + `CHECK` (no precedent in the repo yet) | Pattern 6 | Low; a later switch to native enums needs a migration |
| A2 | Invoice languages are exactly `cs` and `en` | Pattern 6 | Medium; foreign clients may need `de`, `sk`; CHECK needs a migration |
| A3 | A project's client is immutable after creation | Pattern 6 currency rule | Medium; user may want to move a project; would need a currency and ownership policy |
| A4 | `project_billing.billing_type` + `internal_note` live in `project_billing` | Pattern 6 | Medium; if the user wants `billing_type` on `projects`, D-07 (Partner never sees billing type) is violated |
| A5 | ARES-absent `dic` does not clear an existing tax id (overwrite only when ARES has one) | Pattern 5 | Low; D-08 literal reading might clear it |
| A6 | Client stage defaults to `active`; online-payment flag defaults to the global value; country defaults to the supplier country | Pattern 9 | Low |
| A7 | Optional DB trigger for "at least one primary contact" is a nice-to-have, Action layer is enough | Pattern 6 | Low; DB-enforced invariants is a project preference |
| A8 | `symfony/intl` is the right way to get Czech country names | Standard Stack | Low; alternatives exist; install gated by human verify |
| A9 | `unaccent` may not be allowed on the production database host | Pattern 10 | Low; only affects the optional search improvement |
| A10 | ARES 429/5xx/missing street / `cisloOrientacniPismeno` behaviours are not observed live | Pattern 5 | Low; handled generically and tested with fakes |
| A11 | A failed invitation mail leaving the signed URL in `failed_jobs` is an acceptable risk | Pattern 3 | Low; single-use, 7 days |
| A12 | Czech wording of enum labels other than the D-16 status labels | Pattern 10 | Low; trivial to edit |

## Open Questions

1. **Invoice language default (D-13 conflict).**
   - What we know: D-13 says the invoice language is pre-filled "from the typed defaults", but `DefaultsSettings` has only `default_currency` and `default_hourly_rate`; payment terms come from `InvoicingSettings`; no language setting exists anywhere.
   - What's unclear: whether to add a setting.
   - Recommendation: add `default_invoice_language` (`cs`) to `DefaultsSettings` with a new settings migration and a field in the existing Defaults tab (small, satisfies D-13 literally); update `SettingsGroupsTest` / `SettingsPageTest` expectations. Fallback: constant `cs`.

2. **Billing type and internal notes placement (D-05 vs D-07 vs D-15).**
   - What we know: D-05 lists rate, price and estimate for `project_billing`; D-07 says the Partner never sees billing type or internal notes; D-15 calls billing type a project enum.
   - What's unclear: nothing contradicts, but the placement is not stated.
   - Recommendation: put `billing_type` and a new `internal_note` into `project_billing` (otherwise a Partner query loads them). The canary harness also needs a text column there. Confirm with the user in plan review.

3. **Is there an internal note on projects at all?**
   - What we know: PR-01 has no notes field; D-07 mentions "internal notes".
   - Recommendation: include `project_billing.internal_note` (Admin-only text) unless the user says otherwise.

4. **Project client immutable after creation (A3) and client-currency lock.**
   - Recommendation: adopt both rules as stated in Pattern 6; confirm.

5. **"Exactly one primary contact" depth.**
   - What we know: the partial unique index gives at most one.
   - Recommendation: Action-layer rule plus tests; add a deferred constraint trigger only if the planner wants DB-enforced at-least-one.

6. **`projects.next_task_number` (CONTEXT code context) vs. `number_sequences`.**
   - What we know: the allocator and `task:<project uuid>` keys already exist.
   - Recommendation: add nothing to `projects` in Phase 4; update the Phase 5 note.

7. **Admin "manages users" (US-02).**
   - What we know: CONTEXT limits Phase 4 to the account list in the client detail (D-04); there is a single Admin.
   - Recommendation: no global `UserResource` in this phase.

8. **`Audience::Guest` versus keeping the accept page outside `app/Filament`.**
   - Recommendation: add `Guest` (Pattern 3); it needs a small shared-primitive change and one arch assertion, so the planner should treat it as its own task.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | all tests and commands | yes | v1.25.4, web PHP 8.5 | none needed |
| PostgreSQL | schema, constraints, tests | yes | 18.6 (`uuidv7()` built in) | none |
| `unaccent` / `pg_trgm` / `citext` extensions | optional accent-insensitive search | available in DDEV | unaccent 1.1 | skip; default `ILIKE` |
| ext-intl | money formatting, country names | yes (PHP, DDEV) | - | - |
| Redis | cache, queue, sessions | yes (DDEV) | - | array stores in tests |
| Mailpit | manual check of the invitation mail | yes (port 8025 in DDEV) | - | `MAIL_MAILER=array` in tests |
| ARES (ares.gov.cz) | manual end-to-end check only | reachable today | 200/404/400 observed | `Http::fake` in tests; never call it from tests |
| Composer packages (tags plugin, symfony/intl) | tags input, country names | resolvable | v5.10.0, v8.1.5 | n/a |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none. Baseline `ddev exec vendor/bin/pest tests/Arch tests/Isolation` passed today: 131 tests, 434 assertions, about 8 s.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.0 on PHPUnit 13, Livewire / Filament testing helpers |
| Config file | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `KOKPIT_CANARY_HARNESS=true`, `KOKPIT_REQUIRE_ADMIN_2FA=false`) [VERIFIED: phpunit.xml]; `tests/Pest.php` applies `RefreshDatabase` to Feature and Isolation |
| Quick run command | `ddev exec vendor/bin/pest tests/Arch tests/Isolation` (about 8 s today) and `ddev exec vendor/bin/pest --filter <Name>` |
| Full suite command | `ddev exec vendor/bin/pest` then `ddev composer ci` (test + pint + phpstan + licence check) |

No browser test tool is installed (no Dusk, no Pest browser plugin); everything below is HTTP, Livewire and database level. Manual UAT covers the visual ARES highlight and the rendered e-mail (Mailpit).

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CL-01 | Create / edit a client; defaults prefill (currency, rate, terms, language) copied once; Money pair stored; validation | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php` | no, Wave 0 |
| CL-01 | clients constraints: stage / currency CHECKs, rate currency = currency, unique `(country, company_id)` incl. archived | feature (schema, raw SQL) | `... tests/Feature/Schema/ClientTablesTest.php` | no, Wave 0 |
| CL-02 | Contacts: partial unique primary index rejects a second primary; `SetPrimaryContact` demotes then promotes; first contact becomes primary; primary cannot be deleted while others exist; billing flags | feature | `... tests/Feature/Clients/ContactsTest.php` | no, Wave 0 |
| CL-04 | `CompanyId` checksum (valid, invalid, placeholder, non-digits) | unit | `... tests/Unit/Ares/CompanyIdTest.php` | no, Wave 0 |
| CL-04 | `AresClient` with `Http::fake`: 200 mapping (int `psc` padded, missing `dic`, street fallbacks), 404, 400, 500, 429, timeout / `ConnectionException`, malformed JSON, 404 not retried | feature | `... tests/Feature/Clients/AresClientTest.php` | no, Wave 0 |
| CL-04 | Form action: fills only ARES fields, highlights changed ones, every failure sets an error on `company_id` and leaves other state unchanged; hidden for non-CZ | feature (Livewire) | `... tests/Feature/Clients/AresFormActionTest.php` | no, Wave 0 |
| CL-05 | Archive hides from lists and pickers; restore returns; no force-delete action; tags survive archive and restore (HasTags guard); tags input uses type `client` | feature | `... tests/Feature/Clients/ClientArchiveTest.php` | no, Wave 0 |
| PR-01 | Create / edit a project with tags (type `project`) | feature (Livewire) | `... tests/Feature/Projects/ProjectResourceTest.php` | no, Wave 0 |
| PR-02 | Key suggester (diacritics, one word, digits, punctuation, <2 letters, collisions incl. archived); duplicate key -> field error from form and from DB race; CHECK `^[A-Z]{2,6}$`; unique includes archived | unit + feature | `... tests/Unit/Projects/ProjectKeySuggesterTest.php`, `... tests/Feature/Projects/ProjectKeyTest.php` | no, Wave 0 |
| PR-03 | `project_billing` 1:1, money pairs, estimate, currency = client currency, client currency change refused while money exists | feature | `... tests/Feature/Projects/ProjectBillingTest.php` | no, Wave 0 |
| PR-04 | Partner sees only own, visible, non-archived projects of a non-archived client; hidden project, other client, archived project and archived client invisible; policy denies writes | isolation | `... tests/Isolation/PartnerProjectVisibilityTest.php` | no, Wave 0 |
| US-02 | Invite: creates invitation + queued notification to the address, existing user e-mail -> error on the field, pending duplicate -> error, resend invalidates the old link, revoke | feature | `... tests/Feature/Clients/PartnerInvitationTest.php` | no, Wave 0 |
| US-02 | Accept (guest): sets password, creates a Partner with `client_id` and verified e-mail, single use, expired / revoked / reused / tampered / wrong-token all give the same neutral page, unique-violation race, throttle; no self-registration route | feature | `... tests/Feature/Clients/AcceptInvitationTest.php` | no, Wave 0 |
| US-02 | Deactivate: login refused with the generic message, existing session gets 403, tokens deleted; reactivate restores; reset action sends mail only to active accounts; archived client blocks login and restore reopens; no delete action | feature | `... tests/Feature/Clients/PartnerAccountLifecycleTest.php` | no, Wave 0 |

### Success-criterion verification
| # | Criterion | Verified by |
|---|-----------|-------------|
| 1 | Client CRUD + archive / restore, picker and list exclusion | ClientResourceTest, ClientArchiveTest, ContactsTest |
| 2 | ARES fills the form; error beside the field, form unchanged | AresClientTest, AresFormActionTest (+ manual UAT with the real service for the visual highlight) |
| 3 | Project fields; key suggested; duplicate rejected | ProjectResourceTest, ProjectKeySuggesterTest, ProjectKeyTest |
| 4 | Invite -> set password -> log in | PartnerInvitationTest, AcceptInvitationTest, one end-to-end test that follows the mailed link as a guest and then logs in via the Filament login page |
| 5 | Partner sees only own visible projects, no prices / estimates in lists, details, selects, search; never another client's canary | PartnerProjectVisibilityTest, extended `RouteWalkTest` (Partner A requests the new routes), `CanaryRegistryTest` (new fixtures), Partner-safe-column test, global-search and table-search canary tests |

### Sampling Rate
- **Per task commit:** `ddev exec vendor/bin/pest tests/Arch tests/Isolation` plus the new test file(s) of the task.
- **Per wave merge:** `ddev exec vendor/bin/pest` (full suite).
- **Phase gate:** full suite green, `ddev composer ci` green, `scripts/check-sensitive.sh` clean, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Support/Canary.php`: `twoClients()` creates real clients; add `Canary::projectKey()`; `Client`/`Project` factories under `database/factories/`.
- [ ] `tests/Support/CanaryRegistry.php`: five new fixture lines; `Tag` fixture re-pointed at a visible `Project` (fixture order matters).
- [ ] Update hard-coded model lists: `tests/Arch/ModelDeclarationTest.php:31-41`, `tests/Isolation/CanaryRegistryTest.php:83-90`, `tests/Arch/ActivityAllowlistTest.php:39-42`; extend `RouteWalkTest` for the new resources; update the `DeniedModelsTest` wording.
- [ ] `Http::preventStrayRequests()` in a Feature `beforeEach` (Pest bootstrap) so no test can call ARES.
- [ ] New test files listed in the map above; a `tests/Isolation/PartnerSafeColumnsTest.php` that pins `Schema::getColumnListing('projects')` to an explicit allowlist.
- [ ] Optional: `EscapeHatchScanner` extension for `withoutGlobalScope(PartnerScope::class)`.
- [ ] Framework install: none (Pest present); `composer require` of the tags plugin (and optionally `symfony/intl`).

## Security Domain

`security_enforcement` is enabled in `.planning/config.json` (ASVS level 1, block on high).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Invitation sets the first password (min 12, 72-byte cap), generic login errors, Filament throttling, password reset through the broker |
| V3 Session Management | yes | `canAccessPanel` re-evaluated per request; `AuthenticateSession`; session regenerate on login (Filament) |
| V4 Access Control | yes (main risk) | `PartnerScope`, `DeniesPartners`, `ProjectPolicy`, `#[AccessRule]`, strict authorization, canary harness |
| V5 Input Validation | yes | Form rules, `CompanyId` rule, ARES id validated before the URL, `Money::fromMajor`, DB CHECK constraints |
| V6 Cryptography | yes | `random_bytes` token, `sha256` at rest, `hash_equals`, framework signed URLs (HMAC); never hand-roll |
| V7 Error handling / logging | yes | Neutral messages, `AlertMessageSanitiser` for failed-job alerts, no token in the activity log allowlist |
| V8 Data protection | yes | Fictional-data rule, no real ARES responses in the repo, Partner never loads rates, PII (contacts) is Admin-only |
| V13 / API | no | No API in this phase; Phase 12 must reject deactivated users' tokens |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Partner reads another client's project or tag via search, selects, relations or Livewire state | Information disclosure | Model scopes (not resource scopes), canary tests incl. raw HTML and Livewire snapshots, separate Partner resource |
| Partner infers hidden project or client data via tag names | Information disclosure | `Tag` scope limited to `project` type on visible own projects |
| Invitation link guessing / replay | Spoofing, Elevation | 256-bit token hashed at rest, signed expiring URL, single use under a row lock, resend rotates the token, throttling |
| Account or invitation enumeration | Information disclosure | One neutral accept-page message; generic login errors; existence error only in the Admin form |
| Mass assignment of `client_id` to take over another client | Elevation of privilege | `client_id` not fillable; set by Actions; Partner cannot submit write forms |
| Deactivated user keeps a session or API token | Elevation of privilege | `canAccessPanel` on every request, tokens deleted |
| ARES response injection (names with markup or CSV formulas) | Tampering | Store as text, Blade escapes output; Phase 7+ exports must prefix formula characters (note for exports) |
| SSRF via the company id | Tampering | Id validated as 8 digits before building the URL; fixed host from config |
| SQL injection | Tampering | Eloquent / bound parameters; the only raw SQL is constant migration DDL |
| Token or personal data in queue failure records | Information disclosure | Accepted risk A11; failed-job alerts carry no payload |

## Sources

### Primary (HIGH confidence)
- This repository, read in this session: `app/Domain/Shared/Auth/*`, `app/Domain/Shared/Models/{KokpitModel,Tag}.php`, `app/Domain/Shared/Database/MorphMap.php`, `app/Domain/Shared/Money/*`, `app/Domain/Identity/Models/User.php`, `app/Domain/Audit/*`, `app/Domain/Settings/Settings/*`, `app/Domain/Operations/{Alerts,Jobs}/*`, `app/Filament/**`, `app/Providers/**`, `app/Console/Commands/InstallCommand.php`, `config/{kokpit,queue,session,tags}.php`, `database/migrations/*`, `lang/cs/{enums,kokpit}.php`, `phpunit.xml`, `tests/{Arch,Isolation,Support,Feature/Schema}/**`, `.planning/research/{STACK,PITFALLS,ARCHITECTURE}.md`
- Installed vendor source: `vendor/filament/filament` (Authenticate middleware, Login, Register, RequestPasswordReset, routes, helpers, HasGlobalSearch, HasRoutes), `vendor/filament/{forms,actions}` (suffixAction, hint, unique, action validation handling), `vendor/laravel/framework` (Model::delete, SoftDeletes, PendingRequest retry), `vendor/spatie/laravel-tags` (HasTags, Tag)
- Live ARES REST calls on 2026-10-08 (200, 404, 400), structure only
- Downloaded `filament/spatie-laravel-tags-plugin` v5.10.0 source (`SpatieTagsInput`)
- `ddev composer require --dry-run` for the tags plugin, `spatie/laravel-data`, `symfony/intl`; Packagist API metadata
- Baseline test run: `ddev exec vendor/bin/pest tests/Arch tests/Isolation` (131 passed)

### Secondary (MEDIUM confidence)
- [CITED: filamentphp.com/plugins/filament-spatie-tags] official plugin page, `->type()` guidance, 5.x support
- [CITED: filamentphp.com/docs/5.x/upgrade-guide] Filament 5 requirements (Livewire 4)

### Tertiary (LOW confidence)
- ARES behaviours not observed live (429, 5xx, village addresses, orientation-number letter): `[ASSUMED]`
- `unaccent` availability on the production database host: `[ASSUMED]`

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - installed versions read from `composer.lock`; new packages resolved by Composer; seam does not cover Packagist, so legitimacy is manual
- Architecture (isolation, tags, schema): HIGH - derived from the repository's own primitives and tests, plus vendor source for the soft-delete trap and strict authorization
- Invitation / lifecycle: HIGH for the Filament mechanics (login, middleware, reset), MEDIUM for the guest-page audience extension (a design choice, flagged)
- ARES: HIGH for the live shape and error codes, MEDIUM for unobserved edge cases
- Pitfalls: HIGH for the test-suite breakage (counted from the code), MEDIUM for UX details

**Research date:** 2026-10-08
**Valid until:** 2026-11-07 (30 days; ARES shape and package versions are stable, re-check the Filament plugin version before install)
