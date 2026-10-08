# Phase 3: Operations Foundation - Research

**Researched:** 2026-10-08
**Domain:** Laravel 13 / Filament 5 operations layer (typed settings, audit trail, queue failure handling, health page), S3 storage check, Zerops + GitHub Actions deploy, PDF and kanban spikes
**Confidence:** HIGH for package behaviour (installed and exercised in a throwaway copy of this repository on PHP 8.5); MEDIUM for Zerops runtime behaviour (read from official docs and recipe sources, not run on Zerops); LOW only where marked `[ASSUMED]`

<user_constraints>
## User Constraints (from CONTEXT.md)

Copied from `.planning/phases/03-operations-foundation/03-CONTEXT.md`. One substitution: D-03 names the previous hosted tool's form; that product name is replaced here by "the previous hosted tool" because this repository must not name it (the CONTEXT.md file itself still contains the name on D-03 and should be cleaned by its owner).

### Locked Decisions

Already fixed before this discussion (not re-opened): Laravel 13, Filament 5 SPA, PHP 8.5, PostgreSQL 18, Redis for queue/cache/sessions, RustFS S3, DDEV, UUID v7, Czech UI, invoice number `{YYYY}{NNNN}`, per-class `#[AccessRule]` declarations, `#[NotPartnerScoped]`/PartnerScope data-layer default-deny (Phase 2).

**Typed settings**
- **D-01:** Settings are stored with `spatie/laravel-settings` (typed settings classes in the database, cached). The researcher must verify PHP 8.5, Laravel 13 and UUID v7 compatibility (settings table key) before planning; if the package fails, escalate rather than silently switching.
- **D-02:** The Admin edits all settings on one Filament page with tabs (supplier, bank accounts, invoicing incl. VAT mode / payment terms / numbering, defaults, online payments). One form, one Save, one `#[AccessRule]` (Admin only). Everything is Czech via `lang/cs`.
- **D-03:** A bank account is a record with a **format** that decides which fields are shown, modelled on the previous hosted tool's form:
  - common: name (label), format, currency, BIC/SWIFT;
  - **Europe 1 (account number):** account number, bank code, bank name, IBAN (needed for QR payment codes);
  - **Europe 2 (IBAN only):** IBAN, BIC/SWIFT;
  - **World (universal):** account number, BIC/SWIFT, recipient name, bank name, bank address.
  Entered as a repeater. IBAN is validated.
- **D-04:** Currency is a field of each bank account and is **unique** across accounts (one account per currency, ISO 4217). Invoices later select the account by the client's currency.
- **D-05:** Numbering patterns are editable tokens (for example `{YYYY}{NNNN}`) for invoices, proformas, credit notes and tasks, with allowed-token validation and a live preview of the next number. A changed pattern applies only to newly issued documents; issued numbers never change. Pattern drives the scope key of the Phase 2 sequence allocator.

**Activity log**
- **D-06:** The allowlist of logged attributes is declared on the model itself (attribute or method, in the style of `#[NotPartnerScoped]`), and an architecture test fails when a logged model has no allowlist or lists an attribute that is not a real column. Non-allowlisted attributes are never written.
- **D-07:** Admin sees history in two places: a reusable read-only history relation manager (attached to records in later phases) and an Admin-only global activity overview with filters. Phase 3 delivers the global overview and the reusable relation manager; Partner never sees any of it.
- **D-08:** Changes made without a logged-in user (console, jobs, scheduler, webhooks) are logged too, with `causer` null and a source label (console / job / webhook).
- **D-09:** Activity records are kept indefinitely; there is no pruning.

**Queue jobs, alerts and System page**
- **D-10:** A shared base job class defaults to 3 attempts with backoff 10 s, 60 s, 5 min; a job can override both. Idempotence is part of the base class contract and documented.
- **D-11:** A failed job (final failure) triggers an alert that does not depend on the queue: a synchronous e-mail to the Admin plus a database notification shown in the Filament bell. Alerts are throttled so a single outage does not flood the inbox.
- **D-12:** The System page shows each indicator as OK / Warning / Error using fixed thresholds in `config/kokpit.php` (not editable in the UI): scheduler heartbeat older than 3 min = Error, oldest pending job older than 10 min = Warning and 30 min = Error, any failed job = Warning. Values are starting points; the planner may tune them and must document them.
- **D-13:** The page renders a registry of health indicators behind a common interface. Phase 3 registers failed jobs, oldest pending job and scheduler heartbeat for real, and registers last rate date, unprocessed webhooks and unsent invoice e-mails as placeholders ("not available yet"). A test fails if one of the six slots is not registered. Later phases replace one indicator each.

**Spikes, deploy and storage**
- **D-14:** The PDF spike compares Dompdf and `spatie/laravel-pdf` (Browsershot) on Czech diacritics, a multi-page report, a QR code, Chromium requirements on Zerops, and licence compatibility.
- **D-15:** The kanban spike compares a custom Livewire + SortableJS board against a Filament-compatible package, on Filament 5 / Livewire 4 compatibility, persisted ordering (eloquent-sortable), Partner isolation and responsiveness; result is a build-or-buy decision for Phase 5.
- **D-16:** Spike code is throwaway and lives outside the application (separate branch or directory, never merged into `app/`). Only a decision record in English (criteria, measurements, outcome) goes into `.planning`. The chosen dependency is added to the app by the first phase that needs it.
- **D-17:** The S3 smoke test is an Artisan command (storage check: upload, fetch through a temporary URL, delete, print result) used on a server, plus tests that run against the RustFS service in CI. Configuration by environment variables only, path-style capable.
- **D-18:** On Zerops, migrations run once per deploy (not in worker or scheduler) before traffic is switched; a failing migration stops the deploy and the previous version keeps running. Migrations must therefore stay backward compatible with the previous release.

### Claude's Discretion
- Exact class and file names, settings group layout inside the tabs, alert throttling window, health indicator interface shape, scheduler heartbeat mechanism, deploy workflow job layout and hardening details (all hardening rules from the brief apply), `zerops.yml` structure, DDEV daemon details, Czech label wording, spike measurement method.

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FND-07 | Typed settings: supplier data, bank accounts per currency, VAT mode, default rate and currency, payment terms, numbering patterns, online-payment toggle | `spatie/laravel-settings` 3.9.0 works on PHP 8.5 / Laravel 13 with a UUID v7 `settings` table via a custom model (experiment); Filament 5 tab page with repeater, `distinct()`, conditional fields and IBAN rule prototyped and tested; `NumberPattern` to allocator scope-key mapping; `Money` has no parse-from-major entry (gap) |
| FND-08 | Activity log with explicit attribute allowlist; tasks, projects, invoices, time entries | activitylog 5.1.1 installed; `logOnly` + `logOnlyDirty` + `dontLogEmptyChanges` proven to write allowlisted attributes only; raw trait without options writes empty rows (arch test needed); bulk `update()` bypasses logging; causer null + source label hook |
| FND-09 | Redis queue worker and scheduler; job base with retries, backoff, idempotence guidance, visible failure | Laravel 13 queue attributes (`#[Tries]`, `#[Backoff]`, `#[Timeout]`) inherit from an abstract base (experiment); `JobFailed` fires once on final failure; synchronous alert through `Notification::sendNow`; `after_commit` is `false` today (landmine) |
| FND-10 | Admin System page: failed jobs, oldest pending job, scheduler heartbeat, slots for rate date, webhooks, invoice e-mails | `RedisQueue::creationTimeOfOldestPendingJob()` and `CountableFailedJobProvider::count()` exist in Laravel 13.35; heartbeat via scheduled cache write; indicator registry shape and six-slot test |
| FND-15 | `zerops.yml` (build, deploy, worker, scheduler, migrations, no secrets); release/dispatch deploy workflow in protected `production` environment; manual settings checklist | Zerops pipeline, `zsc execOnce`, `extends`, readiness check semantics, `php-nginx@8.5` availability, zcli push semantics, recipe `zerops.yaml`; workflow skeleton and hardening rules; checklist items |
| FND-16 | Private S3 storage by environment only, path-style, smoke test of upload and temporary URL | `league/flysystem-aws-s3-v3` 3.35.3 exercised against RustFS 1.0.1 (put, temporary URL fetch, expiry, anonymous denial, delete); `throw => false` silently returns false on bad credentials (landmine) |
| FND-19 | Spikes resolve PDF engine and kanban library versus custom board | Dompdf and Browsershot both rendered a 3-page Czech report with QR; measurements and a repeatable method; Flowforge 4.1.4 requires a custom Filament theme; Livewire 4.4.7 ships `wire:sort` with groups; licence-gate finding for Dompdf |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Extracted from `.claude/CLAUDE.md` (treated as locked):

- Stack fixed: PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL, S3-compatible private bucket, Sanctum, Stripe Payment Links, queue (Redis decided).
- AGPL-3.0: every dependency AGPL-compatible, no paid or closed packages (the CI `composer check-licenses` gate enforces this).
- Partner must never see measured time, rates, prices, finance or another client's data, enforced by Policies and global query scopes, not only UI hiding.
- UUID v7 keys everywhere including package tables; FK, unique, partial indexes and check constraints in the database.
- Repository hygiene: **fictional data only** (no client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IPs, hostnames, tokens, personal absolute paths) in code, tests, fixtures, docs, `.planning/` and commit messages. Use `example.com`, company ID `12345678`, `/Users/example/` paths, and test fakes assembled at runtime from fragments (this is why no full IBAN appears in this document).
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook pre-commit hook (`--no-verify`, `LEFTHOOK=0`).
- Concurrency: number sequences gap-free and duplicate-free; immutability of issued records.
- All `.planning/` content in English.
- Work starts through a GSD command (this research is one).

## Summary

Every package the locked decisions need resolves and runs on the project's stack (PHP 8.5.11, Laravel 13.35.0, Filament 5.10.0, Livewire 4.4.7, PostgreSQL 18.6). I verified this by installing `spatie/laravel-settings`, `league/flysystem-aws-s3-v3`, `spatie/laravel-pdf` + Dompdf + Browsershot, and Flowforge into a throwaway copy of this repository outside the working tree and exercising each path with `error_reporting=-1`: no PHP 8.5 deprecation appeared in any exercised path. **`spatie/laravel-settings` passes D-01**: the `settings` table works with a UUID v7 primary key (`uuidv7()` default), `timestampsTz()` and a `jsonb` payload, provided a model subclass with `HasUuids` is registered in `config/settings.php`; the Phase 2 schema tests R1 to R9 stay green and the exempt map stays at four entries. No escalation is needed for D-01.

The decisions are feasible as written, but several behaviours will bite a planner who has not seen them: (1) Livewire runs a Page's own `mount()` **before** Filament's `mountCanAuthorizeAccess`, so the Phase 2 `EnforcesPageAccessRule` trait does not stop a `mount()` from running for a Partner; (2) the `settings` model is fail-closed under the Phase 2 `PartnerScope`, which interacts with D-01's "cached" (a cached read bypasses the scope), so the cache decision needs an explicit answer (Open Question 1); (3) the DDEV Redis config uses `allkeys-lfu` and the Zerops Valkey default is `allkeys-lru`, both of which can evict queue lists, silently losing jobs; (4) `config/queue.php` has `'after_commit' => false`; (5) the S3 disk has `'throw' => false`, so a wrong credential makes `put()` return `false` without an exception; (6) `dompdf/dompdf` reports its licence as `LGPL-2.1`, which is not on the `scripts/check-licenses.php` allowlist, so adding it fails the CI `dependencies` job until the allowlist is reviewed.

For the spikes, both PDF engines rendered a 3-page Czech report with a SPAYD-style QR; Dompdf was about 4 times faster on the host (about 0.2 s versus about 0.8 s warm, no browser process) and needs no binaries, while Browsershot gives native Chromium layout and page-number footers but needs Node and Chromium on Zerops. For the kanban spike, Livewire 4.4.7 already bundles SortableJS behind `wire:sort` with `wire:sort:group` / `wire:sort:group-id`, so the "custom board" option needs no extra JavaScript dependency, while Flowforge 4.1.4 requires a custom Filament theme (a Node build step). Both spikes have concrete falsification criteria below; the pre-spike lean is Dompdf and a custom Livewire board.

**Primary recommendation:** Build the phase as five vertical slices (settings, audit, jobs/alerts/health, storage + deploy, spikes), put all system-level behaviour behind small interfaces with tests that fail on a missing registration (six health slots, allowlist per logged model, `#[Idempotent]` per job), close the six landmines above inside the plans that touch them, and make every deployment-behaviour claim that could not be run here a `checkpoint:human-verify` rehearsal on a throwaway Zerops project.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Settings storage and typing | Database / Storage | API / Backend | `settings` table (UUID v7) read through typed `Settings` classes; the page never touches the table |
| Settings editing form, IBAN and pattern validation | Frontend Server (Filament/Livewire) | API / Backend | Livewire page; validation rules are plain PHP rules reused by the data layer (a request can bypass the form) |
| Numbering pattern to scope key, formatting | API / Backend | Database / Storage | Pure domain code; the counter row and lock stay in `SequenceAllocator` (Database) |
| Activity allowlist and logging | API / Backend (model layer) | Database / Storage | Model-level declaration read by a trait; rows in `activity_log` |
| History relation manager, global overview | Frontend Server (Filament) | API / Backend | Read-only tables over `Activity`; access by `#[AccessRule]` + `AdminOnlyPolicy` + `DeniesPartners` |
| Retry, backoff, failure alert | API / Backend (queue worker) | Database / Storage | Base job + global `JobFailed` listener; failed jobs in `failed_jobs`; alert is synchronous mail + `notifications` row |
| Health indicators and System page | API / Backend (indicator registry) | Frontend Server | Indicators read Redis queue state, `failed_jobs`, a cache heartbeat; page only renders results |
| Scheduler heartbeat | API / Backend (scheduler process) | Database / Storage (Redis cache) | A scheduled task writes a timestamp; Redis holds it |
| S3 storage check | API / Backend (Artisan) | CDN / Static (object store) | Command uses the Laravel `s3` disk; temporary URL is fetched over HTTP from the object store |
| Deploy pipeline | CDN / Static (GitHub Actions + Zerops platform) | API / Backend (`zerops.yml`, init commands) | Workflow gates and triggers; Zerops builds, runs migrations once, switches traffic |
| PDF and kanban spikes | Outside the app | Browser / Client (kanban drag) | Throwaway code; only the decision record enters `.planning/` |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `spatie/laravel-settings` | `^3.9` (3.9.0; MIT; PHP ^8.2; latest release 2026-06-26) | Typed settings classes persisted in the database | Locked by D-01. Installed and exercised on PHP 8.5 / Laravel 13.35 `[VERIFIED: throwaway-copy experiment; Packagist API]` |
| `league/flysystem-aws-s3-v3` | `^3.35` (3.35.3, pulls `aws/aws-sdk-php` 3.399.2; MIT) | S3 disk driver | Laravel 13 docs name it as the S3 requirement `[CITED: laravel.com/docs/13.x/filesystem]`. Exercised against RustFS 1.0.1 `[VERIFIED: experiment]` |
| `spatie/laravel-activitylog` | already `^5.1` (5.1.1 installed) | Audit trail | Phase 2 installed and UUID-adjusted it `[VERIFIED: composer.json, vendor]` |
| `laravel/framework` queue + notifications | 13.35.0 installed | Retry, backoff, `JobFailed`, `Notification::sendNow`, Redis queue metrics | No extra package needed `[VERIFIED: vendor source]` |
| Filament 5.10.0 / Livewire 4.4.7 | installed | Settings page, System page, activity resource, relation manager, DB notifications bell | Already in the panel `[VERIFIED: composer show]` |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `jschaedl/iban-validation` | `^2.7` (2.7.0, MIT, declares PHP `~8.5.0`; deps `ext-ctype`, `symfony/options-resolver`) | IBAN country, length, format and mod-97 checks behind an own `ValidationRule` | Recommended for D-03. `[ASSUMED]` package-name provenance (found by `composer search`); gate the install with `checkpoint:human-verify`. Fallback: own rule (mod-97 plus a country-length table) |
| `symfony/yaml` | already `^8.1` in `require-dev` | Parse `zerops.yml` and the deploy workflow in tests | Same pattern as `CiParityTest` |
| `chillerlan/php-qrcode` | already `5.0.5` (Filament dependency) | QR in the PDF spike | Not added to the app in Phase 3 |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Own page for all five settings groups | `filament/spatie-laravel-settings-plugin` 5.10 (`SettingsPage`) | The plugin page binds exactly one `Settings` class (`protected static string $settings`); D-02 needs one form over several groups and one Save. A custom `Page` using Filament's `CanUseDatabaseTransactions` and `HasUnsavedDataChangesAlert` traits is about 40 lines and avoids a dependency `[VERIFIED: vendor source read]` |
| Own health registry | `spatie/laravel-health` 1.40.2 (MIT, Laravel 13 compatible) | Brings its own results table (needs UUID edits) and a command-driven model; D-13 asks for six named slots behind an interface with a registration test. Not recommended |
| Dompdf | Browsershot via `spatie/laravel-pdf` | See spike; Browsershot needs Node + Chromium on Zerops |
| Custom `wire:sort` board | `relaticle/flowforge` 4.1.4 (MIT, PHP ^8.3, `ext-bcmath`, Filament ^5.0) | Flowforge docs: "Prerequisite: You need a custom Filament theme to include the FlowForge styles" `[CITED: relaticle.github.io/flowforge/getting-started/installation]` |

**Installation (Phase 3 application dependencies):**
```bash
ddev composer require spatie/laravel-settings:"^3.9" league/flysystem-aws-s3-v3:"^3.35"
ddev composer require jschaedl/iban-validation:"^2.7"   # only after the human-verify checkpoint
```
Spike packages (`spatie/laravel-pdf`, `dompdf/dompdf`, `spatie/browsershot`, `relaticle/flowforge`) go into the throwaway spike directory's own `composer.json`, never into the root `composer.json` (D-16).

**Version verification:** `composer show -a <pkg>` and the Packagist JSON API were run on 2026-10-08; a `composer require --dry-run` of settings, pdf, dompdf, flysystem-aws-s3-v3 and eloquent-sortable against the project's `composer.json` and `composer.lock` resolved cleanly `[VERIFIED: experiment]`. `spatie/eloquent-sortable` 5.0.1 is already installed transitively through `spatie/laravel-tags`; require it directly in Phase 5 when used.

## Package Legitimacy Audit

The GSD seam `package-legitimacy check` accepts only `npm|pypi|crates` (its usage text: `--ecosystem <npm|pypi|crates>`), so it cannot rate Composer packages `[VERIFIED: gsd-tools output]`. These rows come from the Packagist JSON API, `composer licenses`, and reading the installed source. No `postinstall` scripts exist in Composer; the only install-time scripts are the project's own `post-autoload-dump` hooks.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| `spatie/laravel-settings` | Packagist | since 2020-10 | 9.4M total, 797k/month | github.com/spatie/laravel-settings | seam n/a; Packagist-consistent; user-locked (D-01) | Approved |
| `league/flysystem-aws-s3-v3` | Packagist | since 2015-01 | 317M total, 11.7M/month | github.com/thephpleague/flysystem-aws-s3-v3 | seam n/a; named in Laravel docs | Approved |
| `aws/aws-sdk-php` (transitive) | Packagist | long-lived | very high | github.com/aws/aws-sdk-php | seam n/a; Apache-2.0 | Approved |
| `jschaedl/iban-validation` | Packagist | since 2018-08 | 3.6M total, 166k/month; last release 2025-12-02 | github.com/jschaedl/iban-validation | seam n/a; `[ASSUMED]` name provenance | **Flagged: planner adds `checkpoint:human-verify` before install** |
| `spatie/laravel-pdf` (spike only) | Packagist | since 2023-12 | 7.4M total, 1.07M/month | github.com/spatie/laravel-pdf | seam n/a | Spike only, not in root |
| `dompdf/dompdf` (spike only) | Packagist | since 2014-02 | 214M total, 9.7M/month | github.com/dompdf/dompdf | seam n/a; licence `LGPL-2.1` | Spike only (see licence landmine) |
| `spatie/browsershot` (spike only) | Packagist | since 2014-05 | 43.5M total | github.com/spatie/browsershot | seam n/a | Spike only |
| `relaticle/flowforge` (spike only) | Packagist | since 2025-03 | 255k total, 56k/month | github.com/Relaticle/flowforge | seam n/a; young package | Spike only |

**Packages removed due to [SLOP] verdict:** none (seam unavailable for Composer; none looked hallucinated: every name resolved with a source repository, history and download volume).
**Packages flagged as suspicious [SUS]:** none by signals; `jschaedl/iban-validation` is gated only because its name provenance is `[ASSUMED]`.

## Architecture Patterns

### System Architecture Diagram

```
                       +-----------------------------------------------------------+
 Admin browser  --->   | Filament SPA panel (one panel, two roles)                  |
 (Czech UI)            |  SettingsPage --5 tabs--> 5 typed Settings classes --+      |
                       |  SystemPage --reads--> HealthIndicatorRegistry       |      |
                       |  ActivityResource / ActivityHistoryRelationManager   |      |
                       |  DB notifications bell <-- notifications table       |      |
                       +----------------+------------------------------------+------+
                                        | every class: #[AccessRule(AdminOnly)] +
                                        | boot-hook 403 (before mount)          |
                                        v                                       v
  model save/delete --> LogsAllowlistedActivity --> activity_log          settings table
   (web | console | job)   (#[LoggedAttributes] allowlist,                (UUID v7, jsonb payload)
                            source label, causer null if no user)

  dispatch --> Redis queue ----> worker (queue:work) --- KokpitJob base (tries 3, backoff 10/60/300 s,
                  |                    |                  system context, #[Idempotent])
                  |                    +-- final failure --> failed_jobs row + JobFailed event
                  |                                              |
                  |                                   FailureAlerter (never throws, never queued)
                  |                                      +-- throttle (Cache::add window)
                  |                                      +-- Notification::sendNow: mail + database
                  +-- RedisQueue::creationTimeOfOldestPendingJob() --> OldestPendingJobIndicator
  scheduler (schedule:work) --every minute--> Cache heartbeat --> SchedulerHeartbeatIndicator
  failed_jobs count (queue.failer) --------------------------------> FailedJobsIndicator
  3 placeholder indicators (rate date, webhooks, invoice e-mails) -> "not available yet"

  GitHub release published / manual dispatch
        --> verify job (no secrets: tag commit is ancestor of main, not a prerelease)
        --> deploy job, environment: production (required reviewer, environment secret)
              zcli login + zcli service push: app, then worker, then scheduler (sequential, each waits)
                Zerops app container: initCommands = zsc execOnce <appVersionId> -- migrate
                  --> readiness check --> traffic switch (old version serves until then)
```

### Recommended Project Structure

```
app/
├── Domain/
│   ├── Settings/                 # Settings classes, casts, enums, numbering, rules
│   │   ├── Settings/             # SupplierSettings, BankAccountSettings, InvoicingSettings,
│   │   │                         #   DefaultsSettings, PaymentSettings (one group each)
│   │   ├── Casts/                # BankAccountListCast, MoneySettingsCast
│   │   ├── Numbering/            # NumberPattern, DocumentKind, DocumentNumbering
│   │   └── Rules/                # IbanRule, UniqueCurrencies, NumberPatternRule
│   ├── Audit/                    # LoggedAttributes (attribute), LogsAllowlistedActivity (trait),
│   │                             #   ActivitySource (scoped), KokpitLogActivityAction
│   ├── Operations/
│   │   ├── Jobs/KokpitJob.php    # abstract base, #[Tries] #[Backoff] #[Timeout]
│   │   ├── Alerts/               # FailureAlerter, OperationalAlert (Notification)
│   │   ├── Health/               # HealthIndicator, HealthStatus, HealthSlot, HealthResult,
│   │   │                         #   HealthIndicatorRegistry, indicators, PlaceholderIndicator
│   │   └── Storage/StorageCheck.php
│   └── Shared/Models/SettingsProperty.php   # UUID model for the settings table
├── Filament/
│   ├── Pages/{SettingsPage,SystemPage}.php
│   ├── Resources/ActivityResource.php (+ Pages/ListActivities.php)
│   └── RelationManagers/ActivityHistoryRelationManager.php   # abstract; concrete subclasses carry #[AccessRule]
├── Console/Commands/{StorageCheckCommand,DeployVerifyCommand}.php
database/{migrations,settings}/   # settings migration + SettingsMigration files (database/settings is auto-run by migrate)
zerops.yml                        # root, no secrets
.github/workflows/deploy.yml
lang/cs/kokpit.php                # new keys: settings.*, system.*, activity.*, alerts.*
tests/{Feature/Operations,Arch,...}
```

The Phase 2 convention of domain code under `app/Domain/<Context>` is kept. `config/settings.php` must list the settings path in `auto_discover_settings` (default is `app/Settings`) and set `repositories.database.model` to the UUID model `[VERIFIED: vendor/spatie/laravel-settings/config/settings.php]`.

### Pattern 1: Settings on a UUID v7 table (D-01)

**What:** Publish no vendor migration; write one that follows the Phase 2 pattern and point the package at a model subclass.

```php
// database/migrations/2026_10_08_000100_create_settings_table.php   [VERIFIED: ran, schema tests R1-R9 green]
Schema::create('settings', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->string('group');
    $table->string('name');
    $table->boolean('locked')->default(false);
    $table->jsonb('payload');
    $table->timestampsTz();
    $table->unique(['group', 'name']);
});

// config/settings.php  ->  'repositories' => ['database' => ['model' => SettingsProperty::class, ...]]
class SettingsProperty extends \Spatie\LaravelSettings\Models\SettingsProperty implements PartnerIsolated
{
    use DeniesPartners, HasUuids;   // same shape as App\Domain\Shared\Models\Activity
}
```

Observed: `createProperty` goes through Eloquent `create()` (HasUuids supplies the id); `save()` on a settings class writes through `upsert(..., ['group','name'], ['payload'])`, which bypasses model events, so the `uuidv7()` column default supplies new ids and `updated_at` is maintained `[VERIFIED: experiment; DatabaseSettingsRepository.php]`. Settings migrations live in `database/settings/` and run with the normal `php artisan migrate` `[VERIFIED: experiment]`.

Touch points in Phase 2 tests when the model is added (found by running the suite in the copy): `tests/Arch/ModelDeclarationTest.php` (model list and the policy test), `tests/Isolation/CanaryRegistryTest.php` plus one fixture line in `tests/Support/CanaryRegistry.php`, `AccessServiceProvider::boot()` (register `AdminOnlyPolicy` for the new model), `MorphMap` is **not** needed (no morph column). The R8 package registry in `SchemaConventionsTest.php` should list the new model.

### Pattern 2: Typed settings classes and casts

- A `list<array>` property must not carry `@var array<int, array<string, mixed>>`: the package parses `@var` and throws `CouldNotResolveDocblockType` (`mixed` inside a generic). Use `@phpstan-var list<array<string, mixed>>` or a cast `[VERIFIED: experiment]`.
- Prefer a `SettingsCast` that maps the payload to a `readonly BankAccount` value object list (the package supports `casts()` per property). Money settings (default rate) use `Money::jsonSerialize()` = `{minor, currency}` and `Money::ofMinor()` in a small `MoneySettingsCast`; the project's Money is minor units per hour (`Money::forDuration(self $hourlyRate, int $seconds)`, `app/Domain/Shared/Money/Money.php:117`).
- Gap: `Money` has no constructor from a user-typed major-unit string (only `ofMinor`, `zero`, `fromExactMinor(string $exactMinor, string $currency)`). The settings form needs `Money::fromMajor(string $decimal, string $currency)` that **rejects** excess decimals instead of rounding, added inside `Money` so Brick stays confined (the arch test `MoneyBoundaryTest` guards that). It also needs a public known-currency check for the currency `Select`.
- Hidden conditional repeater fields are **not dehydrated**: the stored bank-account payload differs by format (e.g. `world` has no `iban` key). The cast must tolerate missing keys.
- Tests use `GeneralSettings::fake([...])` (package API) for unit tests; DB-backed tests need the settings migration rows, which exist after `migrate:fresh`.

### Pattern 3: One Filament 5 page, five groups, one Save (D-02, D-03, D-04)

Prototyped and tested in the throwaway copy (Livewire test: Partner gets 403, duplicate currencies and a bad IBAN fail validation, a valid submit stores both accounts):

```php
#[AccessRule(Audience::AdminOnly, reason: 'Operator settings')]
class SettingsPage extends Page
{
    use EnforcesPageAccessRule, CanUseDatabaseTransactions, HasUnsavedDataChangesAlert;

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make()->tabs([
                Tab::make(__('kokpit.settings.tabs.bank'))->schema([
                    Repeater::make('accounts')->schema([
                        Select::make('format')->options(BankAccountFormat::class)->required()->live(),
                        Select::make('currency')->options(/* known ISO 4217 codes */)->required()->distinct(),
                        TextInput::make('iban')
                            ->visible(fn (Get $get): bool => in_array($get('format'), ['europe_1', 'europe_2'], true))
                            ->required(fn (Get $get): bool => /* same */ true)
                            ->rule(new IbanRule),
                        // account_number, bank_code, bank_name, bic, recipient_name, bank_address: visible per format
                    ]),
                ]),
            ]),
        ]);
    }
    // save(): beginDatabaseTransaction(); getState(); fill + save each Settings class; commit; notify
}
```

Imports (Filament 5): `Filament\Schemas\Schema`, `Filament\Schemas\Components\Tabs`, `Filament\Schemas\Components\Tabs\Tab`, `Filament\Schemas\Components\Utilities\Get`, `Filament\Forms\Components\{Repeater,Select,TextInput}`, `Filament\Pages\Concerns\{CanUseDatabaseTransactions,HasUnsavedDataChangesAlert}` `[VERIFIED: ran]`.

Findings that change the plan:
- `->distinct()` on a repeater child works for duplicate currencies **only when values are identical strings**: a free-text field with `czk` and `CZK` passes. Use a `Select` of canonical codes (so the value is always upper case) `[VERIFIED: experiment, failed first with text input]`. Also enforce uniqueness again at the data layer (a rule on the Settings class, used by `save()` and by anything that writes settings outside the form).
- `distinct()` is implemented in `vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:647` and compares sibling raw state; it ignores blank values.
- Show only `NonPayer` as selectable VAT mode (IN-08: non-VAT-payer default; payer is v2 PLT-01); keep the enum with both cases.
- Labels, enum labels and messages in `lang/cs/kokpit.php` and `lang/cs/enums.php`; enum labels are enforced by `tests/Feature/Localisation/EnumLabelsTest.php` for every `HasLabel` enum under `app/`.

### Pattern 4: Numbering pattern to allocator scope key (D-05)

Phase 2 contract (read this session): `SequenceAllocator::KEY_PATTERN = '/^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$/D'` (line 29), `scopeKeyForYear(string $kind, DateTimeInterface $at)` returns `"{kind}:{Y}"` with the year taken in Europe/Prague (lines 73-82), and `next(string $scopeKey)` must run inside the caller's transaction and starts a new key at 1.

Recommended `NumberPattern` value object (pure PHP, no framework):

| Token | Meaning | Affects scope key |
|-------|---------|-------------------|
| `{YYYY}` / `{YY}` | year (Europe/Prague) | yes, both give `kind:2026` so a switch between them keeps the counter |
| `{MM}` | month; allowed only together with a year token | yes: `kind:2026-05` |
| `{N}`, `{NN}`, `{NNN}`, `{NNNN}`... | counter, zero-padded to the token's length, never truncated | no |
| literal `A-Za-z0-9._/-` | fixed text | no |

Rules: exactly one counter token; no unknown token; length cap; reset granularity = finest date token present, none present gives `kind:all`. **Invariant to test:** the default invoice pattern `{YYYY}{NNNN}` must yield exactly `scopeKeyForYear('invoice', $at)` so importer-written `number_sequences` rows (Phase 2 contract: `next_value` is the NEXT number) remain valid. The preview reads the counter without locking (`SELECT next_value ... WHERE scope_key = ?`, absent row means 1) through a new read-only `SequenceAllocator::peek()`; it must not call `next()`.
- A pattern change never rewrites issued numbers (the number string is stored on the document at issue, Phase 10). Changing the **reset granularity** (year to month or to none) starts a new counter at 1; warn in the form and rely on the future unique index on the number for the collision case.
- The invoice number doubles as the SPAYD variable symbol (digits only, at most 10) per `.planning/research/STACK.md`; validate "digits only, at most 10 characters rendered" for the invoice kind (proforma and credit-note kinds: see Open Question 2).
- Tasks (TA-02): number is `KEY-N` from a per-project counter (`task:<project uuid>`), never recycled. Allow only `{KEY}` and `{N...}` tokens and a separator for the task kind, no date tokens, and scope the counter per project (not pattern-driven). See Open Question 2.
- Provide `DocumentNumbering::allocate(DocumentKind, DateTimeInterface): string` (calls the allocator inside the caller's transaction, then formats) so Phase 10 has one entry point.

### Pattern 5: Allowlisted activity log (D-06, D-08)

Behaviour of activitylog 5.1.1, all observed in an experiment on a probe table `[VERIFIED: experiment]`:

| Case | Result |
|------|--------|
| `logOnly(['title','status'])->logOnlyDirty()->dontLogEmptyChanges()` and a create | one row, `attribute_changes` has only `title` and `status` |
| update touching only a non-allowlisted column | **no row at all** |
| update touching an allowlisted and a non-allowlisted column | row with only the allowlisted column (old and new) |
| `Model::query()->where(...)->update([...])` (bulk) | **no row** (no model events); do not use for allowlisted attributes |
| delete | row with `old` values of allowlisted attributes |
| model uses `LogsActivity` and does not override `getActivitylogOptions()` | **an empty row per event** (`attribute_changes` `[]`), because `logEmptyChanges` defaults to true |

Design:
- Attribute `#[LoggedAttributes(['title', 'status', ...])]` on the class (reflection-readable without instantiating, same style as `#[NotPartnerScoped(reason)]`; attributes are not inherited, so each concrete model declares its own).
- Trait `LogsAllowlistedActivity` wraps spatie's `LogsActivity` and implements `getActivitylogOptions()` as `LogOptions::defaults()->logOnly($attrs)->logOnlyDirty()->dontLogEmptyChanges()->useLogName(<morph alias>)`.
- Architecture test (model scan like `ModelDeclaration::appModels()`): every class using `Spatie\Activitylog\Models\Concerns\LogsActivity` (via `class_uses_recursive`) must (a) use the wrapper trait, (b) not override `getActivitylogOptions()` (compare `ReflectionMethod::getFileName()` with the trait file), (c) carry a non-empty `#[LoggedAttributes]`, (d) list only real columns (`Schema::getColumnListing($model->getTable())`), (e) contain no `*`, no dotted relation path, no `->` JSON path, nothing in `$hidden`, and none of a denylist of sensitive names (`password`, `remember_token`, `two_factor_*`, `*_secret`). Include self-check cases (a violating anonymous class) as Phase 2 does.
- Source label (D-08): add a nullable `source` column to `activity_log` by a new migration (string, check constraint on `web|console|job|webhook`) so the overview filter is a plain indexed predicate; set it in a `KokpitLogActivityAction extends LogActivityAction` (config `activitylog.actions.log_activity` already exists; override the protected `beforeActivityLogged(Model $activity)` hook) `[VERIFIED: config/activitylog.php:71-74; LogActivityAction.php]`. A scoped `ActivitySource` service resolves: explicit `ActivitySource::as('webhook', fn)` wrapper, else inside a queue job (listen to `JobProcessing`/`JobProcessed`/`JobFailed`) `job`, else `runningInConsole()` `console`, else `web`. `causer` is already null without an authenticated user because `CauserResolver::getDefaultCauser()` reads the default guard `[VERIFIED: CauserResolver.php]`. Fallback if a column is unwanted: store the label in `properties`.
- D-09: `config/activitylog.php:20` has `'clean_after_days' => 365` and the package registers `activitylog:clean`. Never schedule it, add a test that asserts the schedule has no `activitylog:clean` entry, and consider setting `clean_after_days` to a value that cannot prune by accident.
- Production guard: extend `ProductionConfigGuard` so production refuses to boot with `activitylog.enabled` false and with `queue.default` equal to `sync` (a silent audit-trail switch and a silent no-worker mode are the two ways this phase's guarantees could be disabled).
- The overview and the relation manager query `Activity` (already `DeniesPartners` and `AdminOnlyPolicy`, `app/Domain/Shared/Models/Activity.php:23`). The model's own `fresh()` call during logging uses `newQueryWithoutScopes`, so the Partner scope does not interfere with writing.

### Pattern 6: Base job, global failure listener, queue-independent alert (D-10, D-11)

```php
#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]       // Illuminate\Queue\Attributes\*
abstract class KokpitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() { $this->afterCommit(); }
    public function middleware(): array { return [new RunsAsSystem]; }   // PartnerContext::runAsSystem around handle()
    public function failed(Throwable $e): void {}                         // optional per-job cleanup hook
}
```

- Laravel 13 reads these attributes from the class **and its parents**, and a child attribute overrides: a probe base with `#[Tries(3)] #[Backoff(10,60,300)] #[Timeout(60)]` gave `tries=3`, `backoff="10,60,300"` for a bare child and `tries=5`, `backoff="1,2"` for a child with its own attributes `[VERIFIED: experiment; Support/Traits/ReadsClassAttributes.php walks parents]`. A property or `backoff()` method on the job still wins over the attribute.
- Idempotence contract: an `#[Idempotent(how: '...')]` attribute (non-empty `how`) required on every concrete `KokpitJob` by an arch test, plus the docblock on the base class (natural key, "check then act", unique constraints). This mirrors `#[NotPartnerScoped(reason)]`.
- Jobs run in the system context: Phase 2's `PartnerContext::runAsSystem` docblock names jobs, and the fail-closed scopes would otherwise return nothing in a worker. A job middleware is the single place to do it.
- Global listener on `Illuminate\Queue\Events\JobFailed` rather than only `failed()`: `Job::fail()` deletes the job, calls the job's `failed()`, then dispatches `JobFailed` in a `finally`, once per final failure `[VERIFIED: Queue/Jobs/Job.php fail()]`. This also covers package jobs (webhook client, mail) that do not extend the base. The listener must catch everything: an exception there would stop later listeners (the `failed_jobs` insert).
- `FailureAlerter`: `Cache::add("kokpit:alert:{class}", ..., window)` per job class (first failure sends at once; later failures inside the window only increment a counter that the next alert reports); if the cache itself throws, send anyway (duplicates beat silence). Send with `Notification::sendNow($admins, new OperationalAlert(...))` where `via()` is `['mail','database']`, the class does **not** implement `ShouldQueue`, and `toDatabase()` returns a Filament-format payload (`Filament\Notifications\Notification::make()->title(..)->body(..)->danger()->getDatabaseMessage()`) so the bell renders it. `Filament\Notifications\Notification::sendToDatabase()` calls `$user->notify(...)`, which queues if the notification class is queueable, hence the explicit `sendNow` `[VERIFIED: vendor/filament/notifications/src/Notification.php:196-211]`. Wrap each channel in its own try/catch and fall back to `Log::critical` (syslog on Zerops).
- Alert content: job display name, queue, attempts, exception class, a truncated first line of the message (about 200 characters), the failed job uuid, and a link to the System page. Exception messages can carry data (SQL values, connection strings), so never put the payload or full trace in a mail.
- The panel does not enable the bell yet: `AdminPanelProvider` has no `databaseNotifications()` call (grep over `app/` returns nothing). Add `->databaseNotifications()` (optionally `->databaseNotificationsPolling('30s')`); the method exists at `vendor/filament/filament/src/Panel/Concerns/HasNotifications.php:25` `[VERIFIED]`.
- Set `'after_commit' => true` on the `redis` connection (`config/queue.php:44` is `'after_commit' => false`): a job dispatched inside a transaction otherwise runs before the row is visible (PITFALLS.md Pitfall 16). `retry_after` is 90 (`config/queue.php:42`), above the 60 s default timeout, as required.
- Worker command on Zerops: `php artisan queue:work --sleep=3 --max-time=3600` (no `--tries` flag needed; job attributes win).

### Pattern 7: Health indicators and the System page (D-12, D-13)

Shape (names are discretion):

```php
enum HealthStatus: string { case Ok='ok'; case Warning='warning'; case Error='error'; case NotAvailable='not_available'; }
enum HealthSlot: string { case FailedJobs; case OldestPendingJob; case SchedulerHeartbeat;
                          case LastRateDate; case UnprocessedWebhooks; case UnsentInvoiceEmails; }
interface HealthIndicator { public function slot(): HealthSlot; public function check(): HealthResult; }
final readonly class HealthResult { HealthStatus $status; ?string $value; ?string $detail; }
```

`HealthIndicatorRegistry` (singleton) maps every `HealthSlot` case to an indicator; the three later slots default to `PlaceholderIndicator` (status `NotAvailable`, text "not available yet"); a later phase calls `replace(HealthSlot::LastRateDate, new RateDateIndicator)`. **Test:** iterate `HealthSlot::cases()` and assert the registry resolves an indicator whose `slot()` equals the case; a self-check registers a registry with one slot missing and expects the failure.

Measurement on the Redis driver, all in Laravel 13.35 `[VERIFIED: vendor source]`:
- Oldest pending job: `Queue::connection('redis')` is a `RedisQueue` with `creationTimeOfOldestPendingJob($queue)` (`Queue/RedisQueue.php:329-340`: `lindex` 0 of the ready list, returns the payload's `createdAt`); `createdAt` is added to every payload at `Queue/Queue.php:194` as `'createdAt' => Carbon::now()->getTimestamp(),`. Age = now minus that, `null` means no pending job. Landmine: `createdAt` is the creation time, not the time the job became runnable, so a job created with `->delay(30 minutes)` shows an age of 30 minutes the moment it becomes ready, and a job released by backoff keeps its original age. Backoff here is at most 5 minutes (harmless against a 10-minute warning), but later phases that use `delay()` must not trip the indicator; document it and consider excluding delayed jobs by also reading `delayedSize()`.
- Dead worker with an empty queue is invisible to "oldest pending". Cheap mitigation (recommended): the scheduled heartbeat task also dispatches a trivial queued `RecordWorkerHeartbeat` job, and the Oldest-pending indicator shows Error when that second key is older than the Error threshold.
- Failed jobs: resolve `app('queue.failer')` (a `CountableFailedJobProvider` for the `database-uuids` driver, `DatabaseUuidFailedJobProvider::count()` at line 170) and call `count()`. Do not use `DB::table('failed_jobs')`: `tests/Arch/QueryEscapeHatchTest.php` fails on any `DB::table(` in `app/` outside an empty allowlist.
- Scheduler heartbeat: `Schedule::call(fn () => Cache::forever('kokpit:heartbeat:scheduler', now()->getTimestamp()))->everyMinute()->name('kokpit-heartbeat')` in `routes/console.php`, read by the indicator (older than 3 min = Error). `Cache::forever` keeps the key safe under a `volatile-lru` eviction policy (see Pitfall 3).
- Thresholds live in `config/kokpit.php` under one key (for example `health.thresholds`), env-free, with the starting values from D-12; document them in the file's docblock. Tests use `Carbon::setTestNow` and the array cache store.
- Non-Redis queue connection (tests use `sync`, `phpunit.xml`): the indicators return `NotAvailable` when the connection is not a `RedisQueue`; one integration test uses a real Redis queue with a unique queue name.
- Page: Admin only (`#[AccessRule(Audience::AdminOnly, ...)]`), `wire:poll` about every 30 s, optional navigation badge showing the worst status. Guard the data loading against the mount-order issue in Pitfall 1.

### Pattern 8: Reusable read-only history relation manager and overview (D-07)

- `abstract class ActivityHistoryRelationManager extends RelationManager` with `$relationship = 'activitiesAsSubject'` (the v5 relation on models using `LogsActivity`, v4 name `activities` is gone `[VERIFIED: UPGRADING.md; LogsActivity.php]`), read-only table (no create, edit or delete actions), default sort `created_at desc, id desc` (project convention: ties broken by id), columns: when, event, causer (or the source label for null), changed attributes with translated attribute names and old to new values.
- **Attribute rule:** `#[AccessRule]` is not inherited, and `PanelRegistryTest` only inspects concrete subclasses (`isAbstract()` classes are skipped). Each record's relation manager is therefore a 5-line concrete subclass carrying `#[AccessRule(Audience::AdminOnly, ...)]` and `use EnforcesRelationManagerAccessRule;` (the trait's own `@phpstan-ignore trait.unused` comment says it is waiting for the first real relation manager; remove it then).
- Prove it in Phase 3 against the canary resource fixture (as Phase 2 does for relation managers), since the real records arrive later.
- `ActivityResource`: list page only, Admin only, filters: event, source, subject type (from the `MorphMap` aliases of logging models), causer including "no user", date range. Show the subject type by alias through `lang/cs` without loading the subject (avoids N+1 and avoids resolving a deleted subject).

### Pattern 9: S3 storage check (D-17)

`php artisan kokpit:storage:check` builds the disk with `throw => true` (for example `Storage::build(array_merge(config('filesystems.disks.s3'), ['throw' => true]))`), then: put a small random object under a `healthcheck/` prefix, fetch it through `temporaryUrl()` (`Http::get`, assert 200 and equal body), optionally assert an unsigned URL is refused, delete, assert gone, print a one-line result per step and a non-zero exit code with the failing step on error. Never print the signed URL (it is a credential for its lifetime); print host and path only.

Observed against RustFS 1.0.1 with the repository's own env shape (`AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_ENDPOINT`, bucket from env): put returned `true` in about 110 ms, the temporary URL fetched the exact bytes (Czech text), an expired URL returned 403, an anonymous GET of the same path returned 403, delete worked `[VERIFIED: experiment]`. The AWS SDK exposes `request_checksum_calculation` and `response_checksum_validation` client options (`vendor/aws/aws-sdk-php/src/S3/S3Client.php:2403-2418`, values `when_supported` or `when_required`); keep them out of the config by default and add env-backed keys to the disk only if a provider rejects uploads. The default checksum behaviour worked with RustFS.

CI: add a `rustfs` service to the existing `tests` job in `.github/workflows/hygiene.yml` (same pinned tag as `.ddev/docker-compose.rustfs.yaml`, currently `rustfs/rustfs:1.0.1`, env `RUSTFS_ACCESS_KEY`, `RUSTFS_SECRET_KEY`, `RUSTFS_ADDRESS=:9000`, `RUSTFS_VOLUMES=/data`, health command `curl --fail http://localhost:9000/health`), job env `AWS_*` with `AWS_ENDPOINT=http://127.0.0.1:9000`, path style true; a PHP test creates the bucket through `getClient()->createBucket()` in a `beforeAll` (worked in the experiment, no extra tool needed). Do not touch `ci-passed.needs` (line 241: `needs: [scan, workflow-lint, tests, static-analysis, dependencies]`), the new service lives inside an existing job. Extend `CiParityTest` so the CI image tag equals the DDEV image tag. S3 tests carry a Pest group `s3` and fail (not skip) with a clear message when the endpoint is unreachable.

### Pattern 10: Zerops (D-18, FND-15)

Verified facts (Zerops docs repository and recipe, read this session):

| Topic | Fact | Source |
|-------|------|--------|
| PHP 8.5 | build base `php@8.5`, runtime `php-nginx@8.5`, import type `php-nginx@8.5+1.28`; PostgreSQL `postgresql:single@18` available | `[CITED: zeropsio/docs apps/docs/static/data.json]` |
| Setups and services | one `zerops.yaml` holds several `setup` entries; each service builds and deploys separately; `extends` copies another setup (maps merge, lists such as `initCommands` and `deployFiles` are replaced) | `[CITED: zerops-yaml/specification.mdx]` |
| zcli file name | zcli looks for `zerops.yaml` then `zerops.yml` | `[VERIFIED: zcli src/yamlReader/zeropsYaml.go]` |
| Migrations | `initCommands` run each time a new container starts; recipe uses `zsc execOnce ${appVersionId} --retryUntilSuccessful -- php artisan migrate --force` in the web setup only | `[CITED: references/zsc.mdx; zerops-recipe-apps/laravel-showcase-app zerops.yaml]` |
| `execOnce` semantics | "Execute a command exactly once across all containers in a service"; on failure "All containers report the command as failed"; with `--retryUntilSuccessful` retried on another container; docs example key `${ZEROPS_appVersionId}` | `[CITED: references/zsc.mdx]` |
| Traffic switch | new containers run init commands, start, pass the readiness check, then are activated; "Until it passes, traffic stays on the old container"; if it never passes "the deploy fails and the old version keeps serving"; `temporaryShutdown: false` is the default (old and new run side by side) | `[CITED: guides/readiness-health-checks.mdx; features/pipeline.mdx]` |
| Container build | no shared artifact between services: each `zcli service push --setup X` builds its own artifact from the same commit | `[CITED: features/pipeline.mdx]` |
| Scheduler | `run.crontab` supports `allContainers: false` (one container); the recipe has no scheduler | `[CITED: zerops-yaml/cron.mdx]` |
| Secrets | cross-service references such as `${db_password}` in `envVariables` are references, not values; real secrets are service or project secret variables set in the GUI or `envSecrets` in import YAML | `[CITED: features/env-variables.mdx; recipe]` |
| Valkey | AOF `everysec`, default `maxmemory-policy` `allkeys-lru`, configurable (profile override or `VALKEY_MAXMEMORY_POLICY`); docs: `noeviction` for job queues, `volatile-lru` for mixed workloads | `[CITED: valkey/overview.mdx lines 116-172]` |
| Proxy | the recipe sets `$middleware->trustProxies(at: '*')` | `[CITED: zerops-recipe-apps/laravel-showcase-app bootstrap/app.php]` |
| zcli push | `zcli service push` waits for the pipeline by default (`--no-wait` disables); flags `--service-id`, `--setup`, `--workspace-state clean`, `--zerops-yaml-path`; login `zcli login <token>` | `[VERIFIED: zcli src/cmd/servicePush.go; docs commands.mdx]` |

Recommended `zerops.yml` (structure only; hostnames in `${...}` are the service hostnames chosen in the Zerops project, generic names, no secrets):

```yaml
zerops:
  - setup: app
    build:
      base: php@8.5
      buildCommands:
        - composer install --no-dev --optimize-autoloader --no-interaction
      deployFiles: [app, bootstrap, config, database, lang, public, routes, storage, vendor, artisan, composer.json]
      cache: vendor
    deploy:
      readinessCheck:
        exec: { command: php artisan kokpit:deploy:verify }   # pending migrations, DB and Redis reachable
        failureTimeout: "120s"
        retryPeriod: "10s"
    run:
      base: php-nginx@8.5
      documentRoot: public
      initCommands:
        - zsc execOnce ${ZEROPS_appVersionId} -- php artisan migrate --force
        - php artisan config:cache
        - php artisan route:cache
        - php artisan view:cache
        - php artisan filament:optimize      # caches Filament components and Blade icons (command exists in 5.10.0)
        - php artisan settings:discover      # optional: caches auto-discovered settings classes
      healthCheck: { httpGet: { port: 80, path: /up } }
      envVariables: { APP_ENV: production, APP_DEBUG: "false", LOG_CHANNEL: syslog, QUEUE_CONNECTION: redis,
                      CACHE_STORE: redis, SESSION_DRIVER: redis, FILESYSTEM_DISK: s3,
                      DB_HOST: ${db_hostname}, DB_PASSWORD: ${db_password}, ... }   # references only
  - setup: worker
    extends: app
    deploy: { readinessCheck: null }       # confirm null removal semantics on first rehearsal
    run:
      start: php artisan queue:work --sleep=3 --max-time=3600
      initCommands: [php artisan config:cache]
      healthCheck: null
  - setup: scheduler
    extends: worker
    run:
      start: php artisan schedule:work
```

Notes for the planner:
- The build step runs `composer install`, whose `post-autoload-dump` scripts boot Laravel; it must not need a database (the CI job "Boot the application from .env.example alone" shows the app boots without one). `ProductionConfigGuard` runs when `APP_ENV` is production, and its defaults pass.
- `config:cache`, `route:cache`, `view:cache` must run at container start, not in the build, because build paths differ from runtime paths (the recipe says the same) `[CITED: recipe comments]`.
- Use `/up` (the framework health route configured at `bootstrap/app.php`: `health: '/up'`) for `healthCheck`. For the **readiness** check use a command that fails when migrations are pending, because the Zerops docs do not say what happens to the container when an `initCommand` fails (the pipeline page: "The page doesn't say"); this makes a failed migration unable to go live regardless. `kokpit:deploy:verify` (small Artisan command) checks pending migrations through the `Migrator`, database connectivity and Redis connectivity and exits non-zero otherwise.
- Do **not** add `--retryUntilSuccessful` to the migration unless the owner wants unbounded retries: without it a failure is reported and the deploy fails; with it, the same migration retries on another container.
- `deployFiles` lists directories explicitly (the repository has no `resources/` yet, but later phases add `resources/views`); add a test that parses `zerops.yml` and asserts the list contains every directory the app needs at runtime (`lang` is required for Czech).
- `trustProxies`: add `$middleware->trustProxies(at: '*')` to `bootstrap/app.php` (currently an empty `withMiddleware` closure) before the first deploy; without it Laravel generates `http://` URLs behind the TLS-terminating balancer (Pitfall 16 in `.planning/research/PITFALLS.md`).
- `REDIS_CLIENT`: the recipe uses `predis`, while Zerops' own Redis guide for `php-nginx` shows `REDIS_CLIENT: phpredis` `[CITED: docs Laravel redis.mdx]`; this project is on `phpredis` (`.env.example`: `REDIS_CLIENT=phpredis`, CI installs the extension). Verify `php -m | grep redis` on a `php-nginx@8.5` container during the rehearsal; fallback is `run.prepareCommands: sudo apk add --no-cache php85-pecl-redis` `[ASSUMED]` or adding `predis/predis`.
- Add `ext-*` platform requirements (`ext-intl`, `ext-redis`, `ext-pdo_pgsql`, `ext-bcmath`, `ext-gd`, `ext-zip`) to `composer.json` only after confirming which extensions the Zerops build image provides; Zerops docs say the build image is Alpine-minimal and missing extensions must be installed in `build.prepareCommands` (never `--ignore-platform-reqs`).
- Order of deployment: `app` first (runs migrations), then `worker`, then `scheduler`, one `zcli service push` per service, each waiting for its pipeline.

### Pattern 11: Deploy workflow (FND-15)

`.github/workflows/deploy.yml`, new file; the existing `hygiene.yml` and its `ci-passed` job are untouched. `actionlint` and `zizmor --offline .github/workflows` already lint every file in that directory (`hygiene.yml` job `workflow-lint`), so the new workflow is gated for free and must pass both.

Skeleton:

```yaml
name: Deploy
on:
  release:
    types: [published]
  workflow_dispatch:
permissions: {}
concurrency:
  group: deploy-production
  cancel-in-progress: false
jobs:
  verify:                       # no environment, no secrets
    runs-on: ubuntu-24.04
    permissions: { contents: read }
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1  # v7.0.1 (same pin as hygiene.yml)
        with: { fetch-depth: 0, persist-credentials: false }
      - name: Ref must be a commit on main and not a prerelease
        env: { EVENT: "${{ github.event_name }}", PRERELEASE: "${{ github.event.release.prerelease }}" }
        run: |
          set -euo pipefail
          [ "${EVENT}" != "release" ] || [ "${PRERELEASE}" = "false" ]
          git fetch --no-tags origin main
          git merge-base --is-ancestor "${GITHUB_SHA}" origin/main
  deploy:
    needs: verify
    runs-on: ubuntu-24.04
    environment: production       # required reviewer; ZEROPS_TOKEN and service ids are environment secrets/variables
    permissions: { contents: read }
    steps:
      - checkout (persist-credentials: false, fetch-depth: 1)
      - install zcli: download release asset by version, verify a SHA-256 hard-coded in the workflow (same procedure as gitleaks in hygiene.yml)
      - env: { ZEROPS_TOKEN: "${{ secrets.ZEROPS_TOKEN }}" }  run: zcli login "${ZEROPS_TOKEN}"
      - run: zcli service push --service-id "${APP_ID}" --setup app --workspace-state clean --zerops-yaml-path zerops.yml
      - same for worker, then scheduler
```

Hardening rules (from `.planning/research/PITFALLS.md` Pitfall 15, all applicable): top-level `permissions: {}` and per-job `contents: read`; every `uses:` pinned to a 40-character SHA with a version comment (Dependabot `github-actions` already bumps them); `persist-credentials: false`; no `pull_request_target`, `workflow_run` or `issue_comment` trigger; never interpolate `${{ }}` of event data inside `run:` (pass through `env:`); tools pinned by version plus hard-coded checksum; the Zerops token is an **environment** secret, not a repository secret; no caches or artifacts from PR builds. `release: published` also fires for prereleases `[CITED: docs.github.com events-that-trigger-workflows]`, hence the prerelease guard. The release event runs the workflow definition from the tag's commit (standard behaviour, the page does not state it `[ASSUMED]`), so an arbitrary tag could carry an altered workflow: the environment's "selected branches and tags" rule plus required reviewer stop it from reaching the secret, and the ancestor-of-`main` check stops an unreviewed commit from deploying.
zcli asset digests are exposed by the GitHub API (`gh api repos/zeropsio/zcli/releases/latest --jq '.assets[]|[.name,.digest]|@tsv'`), the same bump procedure as `CONTRIBUTING.md` documents for gitleaks. The Zerops-provided action (`zeropsio/actions`) was last updated 2025-05-16 and runs on `node20`; installing a pinned `zcli` is the better fit `[VERIFIED: action.yml, commits API]`.

Manual GitHub settings checklist to add to `CONTRIBUTING.md` (extends the existing "GitHub settings checklist (maintainer, manual)" at the same list style):
- Create environment `production`; add a required reviewer (the maintainer; a solo maintainer leaves "prevent self-review" off and accepts the approval as a deliberate pause and audit trail); do not allow administrators to bypass.
- Environment "Deployment branches and tags": selected tag pattern `v*` (plus the default branch for manual dispatch); protect `v*` tags with a ruleset so only the maintainer can create them.
- Store `ZEROPS_TOKEN` as an environment secret of `production`, and the three Zerops service ids as environment variables, never as repository secrets.
- Repository: confirm no other workflow references the `production` environment; keep "Require actions to be pinned to a full-length commit SHA" enabled (already ticked).
- **Disable the Zerops native GitHub or GitLab integration** for every service (Zerops: "Pipelines & CI/CD settings" -> stop automatic build trigger): it deploys on push or tag and bypasses the environment approval.
- Zerops: create a dedicated access token with the narrowest scope that can push these three services; rotate after any suspected exposure.
- Zerops project settings recorded in docs (not in `zerops.yml`): `APP_KEY` as a project secret, service secrets for mail and later Stripe, Valkey `maxmemory-policy` set to `volatile-lru` or `noeviction`, Object Storage policy `private`, PostgreSQL backups enabled.
- Document that rollback is activating the previous Zerops version, and that migrations must be backward compatible for one release (expand/contract), per D-18.

### Pattern 12: PDF spike (D-14, D-16)

Directory: outside the repository (for example `~/kokpit-spikes/pdf/`) with its own `composer.json`, or a never-pushed local branch. If an in-repo directory is chosen, it must be git-ignored with a matching case in `scripts/tests/test-gitignore.sh` (the `.gitignore` per-phase review rule in `CONTRIBUTING.md`). Deliverable: `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md` (English, fictional data).

Pre-spike evidence gathered here (host: macOS, PHP 8.5.11; same 90-row Czech table, 3 pages with Dompdf):

| Criterion | Dompdf 3.1.6 (via direct use, DejaVu Sans) | Browsershot 5.4.0 (host Google Chrome, Node 24) |
|-----------|---------------------------------------------|--------------------------------------------------|
| Czech diacritics | all of `ŘŠČŽÝÁÍÉŮÚĚŇŤĎ řščžýáíéůúěňťď` extracted correctly by `pdftotext`; fonts embedded as `DejaVuSans` subsets | correct; Helvetica fallback embedded |
| Multi-page report | 3 pages; repeated `thead` and a `position: fixed` header on every page worked; `counter(page)` page numbers worked | 2 pages; native footer `Strana n / m` worked |
| QR | SVG and PNG data URIs both rendered (visual check of page 1) | SVG rendered |
| Time (warm, after first run) | about 0.2 s (195 to 234 ms), peak 72.5 MB, 59 KB file | about 0.8 s (767 to 952 ms), first run 7.3 s, 144 KB file |
| Runtime needs | PHP only | Node, Puppeteer and Chromium; flags such as `--no-sandbox` in containers `[ASSUMED]` |
| Licence | LGPL-2.1 (plus `php-font-lib` LGPL-2.1-or-later, `php-svg-lib` LGPL-3.0-or-later); fonts DejaVu | MIT (Browsershot, laravel-pdf); Puppeteer Apache-2.0; Chromium BSD-style |

`[VERIFIED: experiment in throwaway copy]`. Host numbers are indicative only; the decision needs the same run inside a Zerops `php-nginx@8.5` container.

Repeatable method (script it once, run it on host and on Zerops): (1) fixed fictional HTML template and data (90-row table with the Czech pangram, repeating header, page counter, SPAYD QR built from placeholder values); (2) render N=10 times per engine, record wall time, `memory_get_peak_usage(true)`, file size; (3) `pdfinfo` page count equals expected; (4) `pdftotext` output contains the diacritics string on every page; `pdffonts` lists embedded fonts (no unembedded core font substituting Czech glyphs); (5) QR: rasterise with `pdftoppm -r 150`, decode (`zbarimg` if installed, otherwise a throwaway PHP decoder) and assert the payload equals the input string; (6) on Zerops measure install size and cold-start of Chromium (`apk add chromium nodejs npm` in `run.prepareCommands`, fonts such as `font-dejavu`) `[ASSUMED]` and container RAM during render; (7) record licence of every transitive package with `composer licenses` and run `scripts/check-licenses.php` against the spike lock.

Decision criteria to write down before measuring: Dompdf wins unless a required layout (repeating headers, page numbers, QR sharpness at the scan size) fails or text extraction shows missing glyphs; Browsershot wins only if Dompdf fails a criterion **and** Chromium runs within the Zerops container limits. Licence finding to record: `composer licenses` reports `dompdf/dompdf` as `LGPL-2.1`; `scripts/check-licenses.php` allows `LGPL-2.1-only` and `LGPL-2.1-or-later` (lines 36-37) but not `LGPL-2.1`, so `composer check-licenses` exits 1 with `dompdf/dompdf [LGPL-2.1]` (reproduced). Whoever adds Dompdf to the app (Phase 8 or 10) needs a reviewed one-line normalisation of that deprecated SPDX alias; this is a decision for the maintainer, not a silent allowlist edit (the script's own header says so).

### Pattern 13: Kanban spike (D-15, D-16)

Deliverable: `.planning/phases/03-operations-foundation/03-SPIKE-KANBAN.md`. Build and measure in a throwaway Filament 5 panel (copy of the app or a fresh skeleton) with a UUID v7 `tasks` table, a Partner policy and the `PartnerScope`.

Evidence so far:
- Livewire 4.4.7 (installed) ships `wire:sort`, `wire:sort:item`, `wire:sort:group`, `wire:sort:group-id`, `wire:sort:handle`, `wire:sort:ignore` and `wire:sort:config` in `vendor/livewire/livewire/dist/livewire.js` `[VERIFIED: grep]`. The handler receives item id and zero-based position, plus the destination group id as the third argument; "When an item moves to another group, only the destination group's handler fires"; Livewire does not persist order `[CITED: livewire.laravel.com/docs/4.x/wire-sort]`. A community thread reports cross-list behaviour not working as documented `[CITED: laracasts, web search result; unverified]`, so the spike must test it on 4.4.7. This means "custom Livewire + SortableJS" needs no separate SortableJS dependency.
- Flowforge 4.1.4 installs on this stack (requires `ext-bcmath`, Filament ^5.0, PHP ^8.3), but its documentation states a custom Filament theme with a Tailwind `@source` entry is a prerequisite (so a Node build in the pipeline). The docs do not address authorization or UUID keys `[CITED: relaticle.github.io/flowforge]`; its `flowforge:repair-positions` and decimal rank column are its own ordering scheme, separate from `spatie/eloquent-sortable`.
- `spatie/eloquent-sortable` 5.0.1 is key-type safe: `setNewOrder` uses `getQualifiedKeyName()` and `where($pk, $id)->update(...)` per id, no integer cast on keys `[VERIFIED: vendor SortableTrait.php]`. It removes only the soft-delete scope, so `PartnerScope` still applies. It updates through the query builder, so it fires no model events: a status change must go through `$task->update()` (to be logged), the position write may use `setNewOrder`.

Measurement method: for each of the two candidates implement the same board (4 status columns, 200 cards, filters by client and assignee) and record: (a) works with UUID v7 keys and a mid-column drop (persisted order survives reload); (b) status change goes through model events (an activity row is written for an allowlisted `status`); (c) Partner isolation: a Partner request to the board route returns 403/empty and a forged move call for another client's card changes nothing (cover with the Phase 2 canary style); (d) concurrency: two simultaneous moves into one column leave positions consistent (wrap in a transaction with row lock, test with the same parallel-process approach as `tests/Concurrency/`); (e) responsiveness: Playwright or a manual run at 375 px and 1280 px, drag with touch, 200 cards render time; (f) build cost: lines of code, extra assets, Node build step required or not; (g) upgrade risk: maintainer activity, issues about Livewire 4. Decision rule: custom board unless it fails (a) to (d) or cannot be made touch-friendly in about two days; buy (Flowforge) only if the theme build cost is accepted by the owner.

### Anti-Patterns to Avoid

- **Letting a Page's `mount()` do work before the access check** (Pitfall 1).
- **`LogsActivity` without the wrapper trait**, or `logAll()`, `logFillable()`, `logUnguarded()`: all produce rows with attributes nobody allowlisted.
- **Alerting through a queued notification or `Notification::send` of a `ShouldQueue` class**: the alert dies with the queue.
- **`DB::table('failed_jobs')` or `DB::table('settings')`** in `app/`: trips `QueryEscapeHatchTest` and bypasses scopes.
- **Free-text currency fields** where uniqueness matters.
- **Reading the Redis queue by hand (`lindex`)** instead of `RedisQueue` methods.
- **`config:cache` in the Zerops build**: bakes build paths and build-time env.
- **A repository-level `ZEROPS_TOKEN` secret** reachable by any workflow.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Typed, persisted settings | a key/value table plus casts | `spatie/laravel-settings` with the UUID model | Locked by D-01; handles casts, locking, migrations |
| IBAN validation | regex or partial mod-97 | `jschaedl/iban-validation` behind an own `ValidationRule` (or, if the dependency is rejected, a rule with the full ISO 13616 length table and mod-97) | Country lengths and BBAN formats change; checksum alone accepts wrong-length IBANs |
| Queue age and failure counts | raw Redis commands or SQL | `RedisQueue::creationTimeOfOldestPendingJob()`, `queue.failer->count()` | Already in Laravel 13.35; avoids `DB::table` escape-hatch findings |
| Retry and backoff | custom retry loops | `#[Tries]`, `#[Backoff]`, `#[Timeout]` on the base class | Verified inheritance and override |
| Final-failure hook | per-job try/catch | one `JobFailed` listener | Fires once on final failure for every job type |
| Signed S3 URLs | manual SigV4 | `Storage::disk('s3')->temporaryUrl()` | Verified working path-style against RustFS |
| Once-per-deploy migration | cache locks or `migrate --isolated` | `zsc execOnce ${ZEROPS_appVersionId}` | Platform primitive across containers; `--isolated` needs a shared cache lock |
| Page access guard | per-page `abort_unless` copies | extend `EnforcesPageAccessRule` with the boot hook | One place, covered by the registry test |
| Drag-and-drop JS | own pointer-event code | Livewire 4 `wire:sort` (SortableJS bundled) | Already shipped; touch support included |
| Order persistence | custom position math | `spatie/eloquent-sortable` for integer order; evaluate Flowforge's rank only in the spike | Key-type safe, tested |
| Deploy approvals | custom approval step | GitHub environment `production` with required reviewers | Native, auditable |
| PDF, QR in Phase 3 | any app code | spike directory only | D-16 |

**Key insight:** every item in this phase is a guarantee ("fails loudly", "never logs a non-allowlisted attribute", "no deploy without approval"), so each one needs a **test that fails when the guarantee is silently removed**: a missing health slot, a logged model without allowlist, a job without `#[Idempotent]`, a workflow with a new trigger, a `zerops.yml` with a literal secret. Custom code is acceptable only for those thin registries; everything underneath is a library or a platform feature.

## Runtime State Inventory

Not a rename or migration phase. Omitted.

## Common Pitfalls

### Pitfall 1: Livewire runs `mount()` before the access check (landmine for D-02, D-07, D-12)
**What goes wrong:** On a Filament Page that defines its own `mount()`, the component's `mount()` runs for a Partner before Filament's `mountCanAuthorizeAccess` aborts with 403. In the prototype a Partner request threw `MissingSettings` from `mount()` instead of returning 403, and any write or heavy read in `mount()` would execute for an unauthorized user.
**Why it happens:** `SupportLifecycleHooks::mount()` calls `callHook('mount')` and then `callTraitHook('mount')` (`vendor/livewire/livewire/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php:34-35`: `$this->callHook('mount', $params);` then `$this->callTraitHook('mount', $params);`), and Filament's check is the trait hook `mountCanAuthorizeAccess` (`vendor/filament/filament/src/Pages/Concerns/CanAuthorizeAccess.php:7-9`: `abort_unless(static::canAccess(), 403);`). Trait `boot` hooks run before `mount` (same Livewire file, lines 29-30: `callHook('boot')`, `callTraitHook('boot')`), which is why a `boot` hook in our trait is early enough.
**How to avoid:** add `public function bootEnforcesPageAccessRule(): void { abort_unless(static::canAccess(), 403); }` to the Phase 2 trait `app/Filament/Concerns/EnforcesPageAccessRule.php` (verified in the prototype: Partner then gets 403 before `mount()`), add the equivalent for relation managers and widgets, and add a registry test that calls each declared class's `boot` path as a Partner.
**Warning signs:** a Partner test that expects 403 but sees an exception or a database query inside a page's `mount()`.

### Pitfall 2: The settings model is fail-closed, and "cached" bypasses it
**What goes wrong:** With `SettingsProperty` under `PartnerScope`, any read outside Admin and system context sees no rows and throws `MissingSettings` (prototype). With the package cache on (`SETTINGS_CACHE_ENABLED`, default false), a read after an Admin warmed the cache returns values without touching the model, so the scope no longer decides.
**How to avoid:** see Open Question 1. Whatever is chosen: run jobs and console in the system context (base job middleware, existing `runAsSystem` in commands), and test one Partner-context read and one job-context read.

### Pitfall 3: Redis eviction can delete queued jobs
**What goes wrong:** `.ddev/redis/redis.conf` line 8 is `maxmemory-policy allkeys-lfu`; the Zerops Valkey default is `allkeys-lru` (`valkey/overview.mdx`). Under memory pressure the queue lists and sorted sets are evictable, so jobs vanish without a trace.
**How to avoid:** set `volatile-lru` (queue keys have no TTL; cache and session keys do) or `noeviction` on the Zerops Valkey service (a profile override, no restart) and document it; change the DDEV file only deliberately (the file carries the `ddev-generated` marker and `DdevConfigTest` checks the add-on files); keep the heartbeat key `Cache::forever`.

### Pitfall 4: Queue dispatch inside a transaction
**What goes wrong:** `config/queue.php:44` `'after_commit' => false` lets a worker pick up a job before the row it needs is committed (`ModelNotFoundException`).
**How to avoid:** set `'after_commit' => true` and call `afterCommit()` in the base job constructor; keep the default in tests with `RefreshDatabase`.

### Pitfall 5: `throw => false` hides storage failures
**What goes wrong:** On the repository's `s3` disk (`config/filesystems.php`: `'throw' => false`), `put()` with wrong credentials returned `false` and no exception `[VERIFIED: experiment]`; with `throw => true` it raised `League\Flysystem\UnableToWriteFile` with the SDK message.
**How to avoid:** the storage check (and later the Documents disk) builds the disk with `throw => true`; check return values anyway.

### Pitfall 6: Dompdf licence id fails the CI gate
See Pattern 12 (`composer check-licenses` exit 1, `dompdf/dompdf [LGPL-2.1]`). Plan the allowlist decision for the phase that adds Dompdf; record it in the spike result.

### Pitfall 7: Activity rows for non-allowlisted writes and bulk updates
See Pattern 5: bulk `update()` is silent; a model with the raw trait writes empty rows. **Avoid:** arch test plus a documented rule that allowlisted attributes change only through model saves (kanban status moves included).

### Pitfall 8: Settings class docblock types
`@var array<int, array<string, mixed>>` makes the package throw `CouldNotResolveDocblockType`. Use a cast or `@phpstan-var`.

### Pitfall 9: Hidden repeater fields change the stored shape
Fields hidden by `visible()` are not dehydrated. Make the cast and any reader tolerant of missing keys, and normalise on save (explicitly null the hidden fields) so stored shapes are uniform.

### Pitfall 10: Delayed and retried jobs inflate "oldest pending job"
See Pattern 7: `createdAt` is creation time. Document it on the indicator and in the base job docblock.

### Pitfall 11: Deployment gate bypass by the native Zerops Git integration
It deploys on push or tag and ignores GitHub environments. Disable it per service and list it in the checklist; add a docs test that the checklist mentions it.

### Pitfall 12: `release` workflow runs the tag's workflow file
Anyone with write access can publish a release from a branch with an altered workflow. Mitigate with the environment tag rule, required reviewer, the verify job (ancestor of `main`, not prerelease) and `v*` tag protection.

### Pitfall 13: Failed-job exception text in alerts
Messages can embed SQL values or connection details. Truncate and keep traces in `failed_jobs`/logs only.

### Pitfall 14: Alert listener that throws
An exception inside the `JobFailed` listener stops later listeners, including the one that writes `failed_jobs`. Catch everything, log, never rethrow.

### Pitfall 15: Preview consuming a number
A live preview that calls `SequenceAllocator::next()` burns gap-free numbers and needs a transaction. Add and use a read-only `peek()`.

### Pitfall 16: `distinct()` is case-sensitive
Covered in Pattern 3; use a Select.

### Pitfall 17: Spike code leaking into the repository
D-16 forbids it, and a public repo adds a hygiene risk (fixtures, real-looking IBANs in spike data). Keep spikes outside the tree, build the QR payload from placeholder fragments, never commit spike output PDFs.

### Pitfall 18: Heartbeat in a flushable cache
A `cache:clear` or Redis restart resets the heartbeat for up to one minute and shows Error. Accept (it self-heals) or store the heartbeat in the database; document the choice.

### Pitfall 19: Trusted proxies absent
Behind the Zerops balancer, `bootstrap/app.php` currently configures no proxy trust (`withMiddleware` is empty): URLs, signed URLs and secure cookies break. Add `trustProxies(at: '*')` (the recipe does) and test the generated scheme in the rehearsal.

### Pitfall 20: Temporary-URL host in DDEV
`AWS_ENDPOINT=http://rustfs:9000` is an internal Docker name; a browser cannot open a signed URL built from it. Irrelevant for the PHP-side smoke test, but Phase 9 download links need a reachable endpoint or `buildTemporaryUrlsUsing` (documented in the Laravel filesystem page).

## Code Examples

### Job probe contract (verified attribute inheritance)
```php
// Source: experiment in a throwaway copy, Laravel 13.35.0
#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]
abstract class BaseJob implements ShouldQueue { use Dispatchable, InteractsWithQueue, Queueable; /* ... */ }
class ChildDefault extends BaseJob {}                       // tries 3, backoff "10,60,300"
#[Tries(5)] #[Backoff(1, 2)] class ChildOverride extends BaseJob {}  // tries 5, backoff "1,2"
```

### Allowlisted logging options
```php
// Source: vendor/spatie/laravel-activitylog/src/Support/LogOptions.php (5.1.1); behaviour observed in an experiment
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly(self::loggedAttributes())    // read from #[LoggedAttributes] via reflection
        ->logOnlyDirty()
        ->dontLogEmptyChanges();
}
```

### Final-failure listener skeleton
```php
// Source: vendor/laravel/framework Queue/Jobs/Job.php fail(); Filament Notification::getDatabaseMessage()
Event::listen(JobFailed::class, function (JobFailed $event): void {
    try {
        app(FailureAlerter::class)->report($event->job->resolveName(), $event->job->getQueue(), $event->exception);
    } catch (Throwable $e) {
        Log::critical('Failure alert could not be sent', ['exception' => $e::class]);
    }
});
```

### Oldest pending job age
```php
// Source: vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:329-340
$queue = Queue::connection('redis');
$createdAt = $queue instanceof RedisQueue ? $queue->creationTimeOfOldestPendingJob() : null;
$ageSeconds = $createdAt === null ? null : now()->getTimestamp() - (int) $createdAt;
```

### Storage check core
```php
// Source: experiment against RustFS 1.0.1; Laravel 13 filesystem docs
$disk = Storage::build([...config('filesystems.disks.s3'), 'throw' => true]);
$disk->put($path, $body);
$url = $disk->temporaryUrl($path, now()->addMinutes(2));
$ok = Http::get($url)->body() === $body;           // never print $url
$disk->delete($path);
```

### Fictional IBAN in tests (hygiene)
Assemble at runtime from fragments, as `tests/Pest.php::exampleEmail()` does for addresses, so no single line holds a full IBAN: `implode('', [$country, $check, $bank, $branch, $account])`. The scratch experiment used a documentation-style example IBAN, which is not reproduced here.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Queue config as `$tries`/`backoff()` properties | PHP attributes `#[Tries]`, `#[Backoff]`, `#[Timeout]` read from the class and its parents | Laravel 13 | Base-class defaults with per-job override, same style as `#[AccessRule]` |
| activitylog v4 `properties.attributes/old`, `activities` relation, batches | v5 `attribute_changes` column, `activitiesAsSubject`, no batches | activitylog 5.0 | History UI reads `attribute_changes`; do not copy v4 tutorials |
| Filament 3 `Forms\Form` and `Pages\SettingsPage` single class | Filament 5 `Schema`, `Filament\Schemas\Components\*`, `Utilities\Get` | Filament 4/5 | Imports listed in Pattern 3 |
| Livewire 3 external sortable packages | Livewire 4 `wire:sort` | Livewire 4 | Custom kanban needs no extra JS dependency |
| Zerops GitHub Action `zeropsio/actions@main` | pinned `zcli` plus `zcli service push` | action untouched since 2025-05 | Pin and verify the binary |

**Deprecated/outdated:** `spatie/laravel-stripe-webhooks` conflicts with `stripe/stripe-php ^22` (not this phase). `filament/spatie-laravel-translatable-plugin` has no Filament 5 line (not used).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `jschaedl/iban-validation` is the right IBAN library (found via `composer search`, not an official source) | Standard Stack | Wrong dependency; fallback is an own rule |
| A2 | `apk add chromium nodejs npm` plus fonts works on the Zerops `php-nginx@8.5` runtime and fits its RAM | PDF spike | Browsershot may be infeasible on Zerops; Dompdf lean stands |
| A3 | `php-nginx@8.5` on Zerops provides the `redis` (phpredis) extension; fallback `php85-pecl-redis` via `apk` or `predis/predis` | Pattern 10 | Queue and cache fail at first deploy |
| A4 | A failing `initCommand` (or `execOnce` failure) prevents the new container from going live and the old version keeps serving | Pattern 10, D-18 | Failed migration could leave a half-migrated new version live; mitigated by the readiness command, still needs a rehearsal |
| A5 | The GitHub `release` event runs the workflow file as it exists at the tag | Pattern 11 | Weakens the reasoning for the tag rule; checklist already covers it |
| A6 | Zerops' L7 balancer sends `X-Forwarded-Proto` so `trustProxies(at: '*')` yields `https` URLs | Pitfall 19 | Mixed content; verify in rehearsal |
| A7 | `extends` with `deploy.readinessCheck: null` and `healthCheck: null` removes the inherited check (spec says `null` removes inherited env vars and whole maps) | Pattern 10 | Worker/scheduler would inherit the HTTP checks and never pass; rewrite them without `extends` for those blocks |
| A8 | `volatile-lru` is acceptable to the owner for the shared Redis (cache, sessions, queue) | Pitfall 3 | A full Redis rejects or evicts differently than expected |
| A9 | Dompdf's licence id `LGPL-2.1` may be treated as the same licence as `LGPL-2.1-only` for the allowlist | Pattern 12 | Maintainer may prefer excluding Dompdf |
| A10 | The settings page runs under the existing `strictAuthorization` panel config without a Resource, via `#[AccessRule]` only | Pattern 3 | Page not reachable; prototype in the copy was reachable for an Admin |

## Open Questions

1. **Settings model: fail-closed scope versus cache (D-01 "cached")**
   - What we know: `SettingsProperty` under `PartnerScope` returns nothing to non-Admin, non-system contexts; the package cache would let a cached read bypass the scope; later phases may legitimately read one non-sensitive setting (online-payment toggle) in a Partner request.
   - What's unclear: whether "cached" in D-01 is a requirement or a parenthetical feature note.
   - Recommendation: keep `PartnerIsolated` with `constrainForPartner` limited to an allowlist of Partner-visible groups (empty now, so behaviour equals `DeniesPartners`), run jobs and console in the system context, and leave `SETTINGS_CACHE_ENABLED=false` (about 20 rows, five groups). If the owner wants the cache, state that it accepts the scope bypass or splits Partner-visible values out of settings. Needs a confirmation.

2. **Numbering patterns for proformas, credit notes and tasks**
   - What we know: invoice number is the SPAYD variable symbol (digits, at most 10); tasks are `KEY-N` per project (TA-02); STATE.md lists "proforma series and variable symbol" as a Phase 10 decision.
   - What's unclear: whether the task pattern is really editable and what defaults proforma and credit-note use.
   - Recommendation: store four patterns, enforce digits-only and length 10 for the invoice kind, constrain the task kind to `{KEY}` plus separator plus `{N}` with per-project counters, default proforma and credit-note to `{YYYY}{NNNN}` with separate counters, and revisit digits-only for proformas in Phase 10.

3. **IBAN dependency or own rule**
   - Recommendation: take `jschaedl/iban-validation` after the human-verify gate; if declined, an own rule with the full length table is about 80 lines plus its table.

4. **Zerops runtime unknowns that need one rehearsal on a throwaway Zerops project** (A2, A3, A4, A6, A7, Valkey eviction setting)
   - Recommendation: one `checkpoint:human-verify` plan step at the end of the deploy slice: deploy with a deliberately failing migration (expect old version still serving), check `php -m`, the generated URL scheme, `volatile-lru`, and that disabling the native Git integration is done.

5. **Scheduler topology**
   - Recommendation: a separate `scheduler` setup running `schedule:work` (mirrors DDEV, one container, no cron syntax) with `minContainers`/`maxContainers` 1 set in import YAML; alternative is `run.crontab` with `allContainers: false` on the app service. The owner confirms the extra container cost.

6. **`Money::fromMajor` and a known-currency helper** must be added to `Money` (small, tested); confirm this belongs in Phase 3 rather than Phase 4.

7. **Bell for Partners:** enabling `databaseNotifications()` panel-wide also shows a bell to Partners (their own notifications only). Acceptable, or hide it for Partners with the panel's condition argument.

8. **Spike working location:** outside the repository (recommended) versus a git-ignored in-repo directory.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | everything | yes (host and DDEV) | 8.5.11 host, 8.5.8 DDEV | none needed |
| Composer | dependencies | yes | 2.10.3 | none |
| DDEV | dev and tests | yes, project `kokpit` running | 1.25.4 | none |
| PostgreSQL | tests, settings | yes (DDEV db) | 18.6 | none |
| Redis | queue, cache, tests | yes (DDEV service); CI service `redis:7` | 7 | none |
| RustFS | S3 smoke test | yes (DDEV `rustfs/rustfs:1.0.1`, tested with the same image) | 1.0.1 | none |
| PHP extensions in DDEV | redis, intl, bcmath, gd, pdo_pgsql, zip, sodium, exif | yes (listed by `php -m`) | - | none |
| Docker | RustFS experiments | yes | - | none |
| Node.js | Browsershot spike, Flowforge theme build (only if chosen) | yes | 24.21.0 / npm 11.19.0 | none |
| Google Chrome (host) | Browsershot spike on host | yes | m154 | Chromium via Puppeteer |
| `pdftotext`, `pdfinfo`, `pdffonts`, `pdftoppm`, `pdfimages` | PDF spike checks | yes (Homebrew poppler) | - | none |
| `zbarimg` | QR decode in the PDF spike | **no** | - | `brew install zbar`, or a throwaway PHP QR decoder |
| `zcli`, `gh`, `actionlint`, `zizmor`, `gitleaks`, `lefthook` | deploy workflow authoring and hygiene | yes | zcli release 1.1.2 available; `gh` 2.102.0 | none |
| Zerops account, project, token | rehearsal deploy | **no (human)** | - | `checkpoint:human-verify` |
| GitHub admin access for the `production` environment | manual settings | **no (human)** | - | maintainer checklist |

**Missing dependencies with no fallback:** a Zerops project and token and GitHub admin rights for the environment (human steps; the plans must gate them).
**Missing dependencies with fallback:** `zbarimg` (install or use a PHP decoder).

## Validation Architecture

Nyquist validation is enabled (`.planning/config.json`: `workflow.nyquist_validation` is `true`).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.0 on PHPUnit 13.3.6 (`composer.json` `require-dev`) |
| Config file | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array`, test DB must end in `_test`) |
| Quick run command | `ddev exec vendor/bin/pest --compact tests/Feature/Operations tests/Arch` (new files only: seconds) |
| Full suite command | `ddev composer ci` (Pest, Pint `--test`, Larastan level 8, licence check); Pest alone was 395 tests in about 33 s on the host against the DDEV database |
| Shell self-tests | `bash scripts/tests/run.sh` (host command; run when `.gitignore`, scripts or workflows change) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FND-07 / D-01 | settings table passes UUID v7 and timestamptz rules; upsert fills id from `uuidv7()` | feature | `pest tests/Feature/Operations/SettingsStorageTest.php` plus existing `tests/Feature/Schema` | no: Wave 0 |
| FND-07 / D-02 | Admin saves five groups in one transaction; Partner gets 403 **before** `mount()`; form is Czech | feature (Livewire) | `pest tests/Feature/Operations/SettingsPageTest.php` | no: Wave 0 |
| FND-07 / D-03, D-04 | per-format fields; IBAN valid and invalid; one account per currency in form and at data layer | feature + unit | `pest tests/Feature/Operations/BankAccountSettingsTest.php` | no: Wave 0 |
| FND-07 / D-05 | token validation; default invoice pattern equals `scopeKeyForYear`; preview does not consume a number; reset-granularity warning; changed pattern leaves issued numbers | unit + feature | `pest tests/Unit/Numbering` and `tests/Feature/Operations/NumberingTest.php` | no: Wave 0 |
| FND-08 / D-06 | every `LogsActivity` model has an allowlist of real columns, uses the wrapper, does not override options; self-checks | arch | `pest tests/Arch/ActivityAllowlistTest.php` | no: Wave 0 |
| FND-08 / D-06 | non-allowlisted attribute never written; non-allowlisted-only change writes nothing; create/update/delete | feature | `pest tests/Feature/Operations/ActivityLogBehaviourTest.php` (probe table) | no: Wave 0 |
| FND-08 / D-07 | overview and relation manager Admin only; Partner sees nothing (canary) | isolation | `pest tests/Isolation` (extended registry) and `tests/Feature/Operations/ActivityViewsTest.php` | partly: registry exists |
| FND-08 / D-08 | causer null and source label for console, job, webhook wrapper | feature | `pest tests/Feature/Operations/ActivitySourceTest.php` | no: Wave 0 |
| FND-08 / D-09 | no `activitylog:clean` in the schedule; production refuses `activitylog.enabled=false` | feature | `pest tests/Feature/Operations/NoPruningTest.php`; extend `tests/Unit/Support/ProductionConfigGuardTest.php` | extend |
| FND-09 / D-10 | base job defaults and override; every concrete job has `#[Idempotent]` | unit + arch | `pest tests/Arch/JobContractTest.php` | no: Wave 0 |
| FND-09 / D-10, D-11 | failing job retried, ends in `failed_jobs`, alert sent once with mail and database notification, queue-independent, throttled | feature (real Redis queue, unique queue name, test subclass with zero backoff) | `pest tests/Feature/Operations/FailingJobFlowTest.php` | no: Wave 0 |
| FND-10 / D-12 | thresholds per status with `Carbon::setTestNow`; non-Redis connection gives NotAvailable | unit | `pest tests/Unit/Health` | no: Wave 0 |
| FND-10 / D-13 | six slots registered, placeholder replaceable, missing slot fails | feature/arch | `pest tests/Feature/Operations/HealthRegistryTest.php` | no: Wave 0 |
| FND-10 | System page Admin only, shows statuses | feature (Livewire) | `pest tests/Feature/Operations/SystemPageTest.php` | no: Wave 0 |
| FND-15 / D-18 | `zerops.yml`: setups app/worker/scheduler, one `execOnce` migrate only in app, no literal secrets (values are `${...}` references), no `migrate` in worker or scheduler | feature (YAML parse) | `pest tests/Feature/Repo/ZeropsConfigTest.php` | no: Wave 0 |
| FND-15 | `deploy.yml`: triggers exactly `release: published` and `workflow_dispatch`; `environment: production` on the deploy job; `permissions: {}` top level; all `uses:` pinned to 40-hex SHAs; no `pull_request_target`; no `${{ github.event` in `run:` | feature (YAML parse) + CI lint | `pest tests/Feature/Repo/DeployWorkflowTest.php`; CI `workflow-lint` (actionlint, zizmor) | no: Wave 0 |
| FND-15 | `hygiene.yml` and `ci-passed.needs` unchanged in meaning | existing | `bash scripts/tests/test-workflow.sh` | yes |
| FND-15 | CONTRIBUTING checklist lists `production` environment and Git-integration disabling | feature (docs) | `pest tests/Feature/Repo/RepositoryFilesTest.php` extension | extend |
| FND-16 / D-17 | storage check passes against RustFS; fails with a clear step on wrong credentials and unreachable endpoint; never prints the signed URL | feature (group `s3`) | `pest --group=s3` | no: Wave 0 |
| FND-16 | CI RustFS image tag equals DDEV image tag; env keys documented | feature (YAML parse) | extend `tests/Feature/Repo/CiParityTest.php`, `EnvExampleTest.php` | extend |
| FND-19 | PDF and kanban decision records exist with the required sections and measurements | manual-only (spike outcome is a judgement); structure check optional | `checkpoint:human-verify` | n/a, justified: results are measured outside the app |
| Pitfall 1 | declared Filament classes deny a Partner before `mount()` | feature | extend `tests/Isolation/PanelAccessTest.php` | extend |

### Sampling Rate
- **Per task commit:** the quick command above for the files touched, plus `ddev exec vendor/bin/pint --test` on changed files.
- **Per wave merge:** `ddev composer ci`.
- **Phase gate:** `ddev composer ci` green, `bash scripts/tests/run.sh` green, `--group=s3` green against RustFS, spike records reviewed, Zerops rehearsal confirmed by the owner, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Operations/` (new directory under the existing `Feature` suite, which already extends `TestCase` with `RefreshDatabase` in `tests/Pest.php`)
- [ ] `tests/Support/Probes/ActivityProbe` (test-only table and model, created inside the test transaction like `Canary::createTable()`) and a registered probe morph alias that is restored afterwards (pattern of `PackageProbe`)
- [ ] a failing job fixture and a Redis queue test helper (unique queue name, flush after) for `FailingJobFlowTest`
- [ ] Pest group `s3` plus a helper that fails with a clear message when the endpoint is unreachable; CI job env and RustFS service
- [ ] updates to Phase 2 tests listed under Pattern 1 and a `CanaryRegistry` line for the settings model
- [ ] `zbarimg` or a decoder for the PDF spike (spike directory only)
- [ ] Framework install: none (Pest is installed)

## Security Domain

`security_enforcement` is not `false` in `.planning/config.json` (ASVS level 1, block on high), so this section is required.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no new flows | Phase 2 Admin 2FA stays; deploy token handling is V14/V6 below |
| V3 Session Management | no change | Redis sessions (Phase 2) |
| V4 Access Control | yes | `#[AccessRule(AdminOnly)]` on SettingsPage, SystemPage, ActivityResource, relation managers; boot-hook 403 before `mount()` (Pitfall 1); `AdminOnlyPolicy`; `DeniesPartners`/group allowlist scope; arch and registry tests |
| V5 Input Validation | yes | Filament form validation, `IbanRule`, `NumberPattern` parser (anchored, length-capped, whitelisted tokens), unique-currency rule enforced at form and data layer, ISO 4217 select |
| V6 Cryptography | yes (limited) | No custom crypto. Settings hold no secrets; any later secret setting uses the package's `#[ShouldBeEncrypted]`/`encrypted()` support rather than plain payload. Signed S3 URLs from the SDK |
| V7 Error Handling and Logging | yes | Global `JobFailed` listener that never throws; alerts omit payloads and full traces; activity log writes allowlisted attributes only (no password, token, 2FA columns); `Log::critical` fallback |
| V8 Data Protection | yes | Bank accounts and supplier data are Admin-only data (`DeniesPartners`); signed URLs never printed or logged; failed-job payloads stay in `failed_jobs` |
| V10 Malicious Code / supply chain | yes | Pinned action SHAs, pinned `zcli` with hard-coded checksum, `composer audit` and licence gate in CI, `jschaedl/iban-validation` human-verify gate |
| V14 Configuration | yes | `zerops.yml` with references only, `ProductionConfigGuard` extensions (no `sync` queue, activity log on), environment secrets, protected `production` environment, disabled Zerops Git integration |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Partner reaches an Admin page and its `mount()` runs | Elevation of privilege | boot-hook `abort_unless(canAccess())` plus registry test (Pitfall 1) |
| Sensitive attribute written to the activity log | Information disclosure | allowlist attribute, wrapper trait, arch test with sensitive-name denylist |
| Alert or log carries payload secrets | Information disclosure | truncated exception line, no payload in mail |
| Unreviewed or foreign commit deployed to production | Tampering / Elevation | `release`/`workflow_dispatch` only, ancestor-of-`main` check, protected environment with required reviewer, tag rule |
| Workflow script injection via event data | Tampering | event data only through `env:`, never inside `run:`; actionlint and zizmor in CI |
| Deploy token theft | Information disclosure | environment secret, `permissions: {}`, no repository-level secret, `persist-credentials: false` |
| Zerops Git auto-deploy bypasses approval | Elevation | disable native integration (checklist item plus docs test) |
| Queue job loss by Redis eviction | Denial of service / repudiation | `volatile-lru` or `noeviction`, documented and rehearsed |
| Public bucket or leaked signed URL | Information disclosure | private bucket policy, anonymous GET proven 403, URL never printed, short expiry |
| Silent audit or queue disablement in production | Repudiation | `ProductionConfigGuard` refuses `activitylog.enabled=false` and `queue.default=sync` |
| ReDoS or oversized numbering pattern | Denial of service | fixed token grammar parsed without nested quantifiers, maximum length |

## Suggested Plan Decomposition (input for the planner)

Dependency order, with parallelism noted. Every slice ends with its tests green and `ddev composer ci` clean.

1. **Settings storage and numbering** (D-01, D-05 core): migration, UUID model, `config/settings.php`, five Settings classes and casts, `Money::fromMajor`, `NumberPattern`, `peek()`, Phase 2 test touch points. No UI.
2. **Settings page** (D-02, D-03, D-04): needs 1 and the Pitfall 1 trait fix; IBAN checkpoint; Czech labels and enum labels.
3. **Audit** (D-06 to D-09): `LoggedAttributes`, wrapper trait, `ActivitySource`, `source` migration, arch test, probe-based behaviour tests, `ActivityResource`, abstract relation manager, schedule guard, `ProductionConfigGuard` extension. Parallel with 1.
4. **Jobs, alerts, health** (D-10 to D-13): base job, `#[Idempotent]`, `after_commit`, listener, alerter, bell, heartbeat, indicators, registry, System page. Parallel with 3 after the Pitfall 1 fix.
5. **Storage** (D-17, FND-16): `s3` disk hardening, `kokpit:storage:check`, RustFS service in CI, parity test, `.env.example` docs. Parallel with 3 and 4.
6. **Deploy** (D-18, FND-15): `trustProxies`, `kokpit:deploy:verify`, `zerops.yml`, `deploy.yml`, tests, CONTRIBUTING checklist, README deploy notes, Valkey policy documentation; ends with the Zerops rehearsal checkpoint.
7. **Spikes** (D-14 to D-16, FND-19): PDF and kanban, outside the repo; decision records in `.planning`; independent of everything else and can run in parallel with 1 to 5.

## Sources

### Primary (HIGH confidence)
- This repository at the Phase 2 state: `composer.json`, `config/*.php`, `app/Domain/**`, `app/Filament/**`, `tests/**`, `.github/workflows/hygiene.yml`, `.ddev/**`, `CONTRIBUTING.md`, `.planning/research/{STACK,PITFALLS,ARCHITECTURE}.md` (read this session)
- Installed package sources under `vendor/` (read this session): `spatie/laravel-activitylog` 5.1.1, `laravel/framework` 13.35.0 Queue and Notifications, `livewire/livewire` 4.4.7 `SupportLifecycleHooks` and `dist/livewire.js`, `filament/*` 5.10.0, `spatie/eloquent-sortable` 5.0.1
- Throwaway copy of the repository (outside the working tree, discarded) with `spatie/laravel-settings` 3.9.0, `league/flysystem-aws-s3-v3` 3.35.3, `jschaedl/iban-validation` 2.7.0, `spatie/laravel-pdf` 2.14.0, `dompdf/dompdf` 3.1.6, `spatie/browsershot` 5.4.0, `relaticle/flowforge` 4.1.4: settings UUID, Filament page prototype, activity log behaviour, queue attribute inheritance, S3 against RustFS 1.0.1, Dompdf and Browsershot renders, licence gate run, PHP 8.5 deprecation scan, full Pest run (395 passed, 16 failed all explained by the intentional additions and the missing `.ddev` directory in the copy)
- Zerops documentation sources (official `zeropsio/docs` repository, raw files): `features/pipeline.mdx`, `guides/readiness-health-checks.mdx`, `references/zsc.mdx`, `references/zcli/commands.mdx`, `zerops-yaml/{specification,cron}.mdx`, `valkey/overview.mdx`, `static/data.json`; recipe `zerops-recipe-apps/laravel-showcase-app` (`zerops.yaml`, `bootstrap/app.php`); `zeropsio/zcli` sources (`servicePush.go`, `yamlReader/zeropsYaml.go`); `zeropsio/actions` `action.yml`

### Secondary (MEDIUM confidence)
- https://laravel.com/docs/13.x/filesystem (S3 package requirement, temporary URLs)
- https://livewire.laravel.com/docs/4.x/wire-sort
- https://relaticle.github.io/flowforge/getting-started/installation
- https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments and https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows
- https://github.com/spatie/laravel-settings (README via search), https://spatie.be/docs/laravel-pdf/v2/installation-setup
- Packagist JSON API for the statistics in the audit table

### Tertiary (LOW confidence)
- Web-search summary of a Laracasts thread about `wire:sort` group behaviour (unverified; the spike decides)
- Chromium and Alpine package names for Zerops (`[ASSUMED]`)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, installed and exercised on the real stack (PHP 8.5.11, Laravel 13.35, Filament 5.10)
- Architecture: HIGH for settings, audit, jobs and health (behaviour observed); MEDIUM for deploy (documentation and recipe, not run on Zerops)
- Pitfalls: HIGH for the six code-level landmines (reproduced); MEDIUM for Zerops platform behaviour
- Spikes: MEDIUM, pre-spike measurements on one host only, decision pending the real runs

**Research date:** 2026-10-08
**Valid until:** 2026-11-07 for package versions (Filament, Livewire, Laravel and Spatie ship weekly); Zerops runtime facts 2026-11-07 or the first rehearsal, whichever comes first.
