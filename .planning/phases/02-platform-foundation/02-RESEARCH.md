# Phase 2: Platform Foundation - Research

**Researched:** 2026-10-07
**Domain:** Greenfield Laravel 13 + Filament 5 (SPA) on PostgreSQL 18 with DB-enforced conventions, fail-closed Partner isolation, DDEV and CI
**Confidence:** HIGH for everything tagged `[VERIFIED: lab ...]` (executed this session in a throwaway Laravel 13.35.0 project on PHP 8.5.11 against PostgreSQL 18.6 and a real DDEV 1.25.4 project, both since deleted); MEDIUM for items tagged `[CITED]`; LOW for `[ASSUMED]` (see Assumptions Log).

All names, IDs and addresses in this document are fictional (`example.com`, company ID `12345678`).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed before this discussion (not re-opened): Laravel 13, Filament 5 (SPA, Livewire 4), PHP 8.5, PostgreSQL 18, Redis for queue/cache/sessions, RustFS S3, DDEV, UUID v7, Czech default locale, Partner as client account in the same panel, invoice number `{YYYY}{NNNN}`.

#### Partner isolation
- **D-01:** One Partner account belongs to exactly one client: nullable `users.client_id` (null for Admin). A second contact of the same client gets a second account. — **Reversibility:** costly — moving to many-to-many later changes every scope, policy and canary test.
- **D-02:** Default-deny is enforced in the data layer by a shared trait/interface on tenant-scoped models plus a policy base class, both fail-closed: no authenticated user, a Partner without `client_id`, or an unknown role yields an empty result set and denied abilities. Admin is allowed through one explicit rule; every Partner permission is an explicit grant.
- **D-03:** The registry test uses a mandatory interface or attribute. Every Resource, Page, Widget and relation manager registered in the panel must declare its access rule (for example Admin-only or Partner-allowed); the test reflects over all registered classes and fails on any class without a declaration. No hand-maintained list.
- **D-04:** The canary harness starts in this phase with a small test-only tenant model that uses the isolation trait, plus two fictional clients with canary strings. Real models join the harness with one line each in later phases. No `clients` table is created in Phase 2 (that is Phase 4).

#### Admin install and two-factor authentication
- **D-05:** The install command is interactive by default (e-mail, name, hidden password prompt) and accepts flags for automation. The password is never an argument; non-interactive use reads it from an environment variable. There is no default password and the command refuses to create a second Admin.
- **D-06:** Admin 2FA is mandatory from the first login: after signing in, the Admin is redirected to a 2FA setup screen and cannot reach the panel until it is enabled. Enforcement can be switched off by configuration for local development and tests only. Partner 2FA is not required.
- **D-07:** Use Filament's built-in TOTP multi-factor authentication with one-time recovery codes shown at setup. A lost device is recovered with a recovery code or with a CLI command that resets 2FA on the server. No third-party 2FA package.

#### Money
- **D-08:** `Money` is a thin own value object (integer minor units plus ISO 4217 currency) with an Eloquent cast, delegating arithmetic and rounding to `brick/money` (MIT). The domain never uses the library API directly. — **Reversibility:** costly — every persisted amount and every call site uses the value object.
- **D-09:** Rounding mode is `HALF_UP`, applied in exactly one documented method, once per invoice line amount. Durations stay in exact minutes, hours times rate is computed in exact arithmetic, and no later step (sums, conversion to CZK) adds another rounding unless it produces a new document amount.
- **D-10:** Hourly rates are `Money` (minor units per hour). Exchange rates and any per-minute rate are stored as `NUMERIC(20,10)` and handled as decimals outside `Money`; only the rounded result becomes `Money`.

#### Sequence allocator
- **D-11:** A counters table with `SELECT ... FOR UPDATE` inside the caller's transaction. A rollback rolls the counter back, so numbers are gap-free; PostgreSQL sequences and `MAX()+1` are rejected. Proven by real parallel-process tests on PostgreSQL. — **Reversibility:** one-way — issued numbers cannot be changed afterwards.
- **D-12:** The counter is identified by a generic scope key string, for example `invoice:2026` or `task:<project-id>`. The allocator does no resets itself: the year is part of the key and a new year is a new row starting at 1. One API serves tasks and invoices.

### Claude's Discretion

Package migration strategy for UUID conversion (edit published migrations versus custom model subclasses), morph map key names, schema-test mechanics, CI job layout, PHPStan level, formatter and licence-allowlist tooling, DDEV daemon details and exact class/file names. Follow `.planning/research/STACK.md` and `.planning/research/PITFALLS.md`.

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope. (Phase boundary from CONTEXT.md: typed settings, activity log, queue/health page, Zerops deploy, S3 smoke test and spikes belong to Phase 3. No client, project, task or invoice features here.)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FND-01 | Installs and runs from README and `.env.example` alone on Laravel, PostgreSQL, one Filament SPA panel | Skeleton merge recipe, `.env.example` keys, DDEV config, CI "boot from .env.example" job, README sequence (sections Architecture, Code Examples, Pitfalls 9-13) |
| FND-20 | DDEV: PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS, queue worker and scheduler daemons | Lab-verified `.ddev` config, RustFS compose, daemon wrappers, test DB hook (DDEV section) |
| FND-02 | All keys, FKs, morph columns UUID v7 incl. package tables, schema tests | Edited-migration + `HasUuids` subclass recipe, catalogue SQL, morph map, exempt list (UUID section) |
| FND-03 | `timestamptz` in UTC, shown in `Europe/Prague`, morph map enforced | `timestampsTz()` edits, PG session timezone, `FilamentTimezone`, schema rule R5 |
| FND-04 | Money as integer minor units + ISO 4217, one value object, one rounding point | `Money` + `MoneyCast` over `brick/money` 0.15.2 (lab-verified), token-scan test for the rounding point |
| FND-05 | One sequence allocator, gap-free, real parallel-process tests | Allocator + multi-process harness (lab: 200/200 gap-free, mutation caught) |
| FND-06 | Admin/Partner roles via permission package; policies + global scopes, default-deny | `PartnerContext`, `PartnerScope`, `KokpitPolicy`, strict authorization, model declaration test |
| FND-11 | Czech UI text, `lang/cs`, Czech date/number/currency formats | `laravel-lang/common`, Filament `cs` packs, global format defaults, enum label test |
| FND-12 | DB constraints and immutability trigger pattern with raw-SQL test helper | Generic trigger function + CHECK + partial index pilot, `RawSql` helper (lab-verified) |
| FND-13 | CI on PostgreSQL: tests, static analysis, formatting, secret scan, licence allowlist | Jobs merged into `hygiene.yml` behind the single `CI Passed` check; actionlint + zizmor clean |
| FND-14 | LICENSE AGPL-3.0 matching composer.json, README, SECURITY.md, CONTRIBUTING, `.env.example` in sync | SPDX validation, repo-file test, env-sync test design |
| FND-17 | Admin created by install command (no default password); Admin 2FA available | `kokpit:install` (lab: 4 tests), Filament TOTP wiring and enforcement middleware (lab: 5 tests) |
| FND-18 | Partner isolation harness with canary strings; registry test for Resource/Page/Widget/relation manager | `#[AccessRule]` attribute, registry walk (lab-proven to catch defaults), canary harness design |
</phase_requirements>

## Summary

Phase 2 turns an empty repository (hygiene tooling only) into an installable Laravel 13.35 + Filament 5.10 application whose rules are enforced by tests. The whole recipe was executed in a throwaway project this session, so almost every recommendation below carries a lab result rather than a documentation claim. The five hardest findings, in order of how likely they are to derail the plan: (1) the Laravel 13 skeleton collides with the Phase 1 hygiene tooling in six concrete ways (ignored storage placeholders, generated lock-file e-mails, a welcome view and a queue config that trip the scanner, AI guideline files, Filament published assets) and the first skeleton commit will fail the pre-commit hook unless those are cleaned first; (2) package models used without a `HasUuids` subclass silently corrupt keys (the id becomes the integer `1`) even when PostgreSQL has a `uuidv7()` default, so subclass-and-register is mandatory and the DB default is only a safety net for raw SQL; (3) Filament evaluates `isRequired` for multi-factor authentication once at route-registration time, so "Admin only, switchable by config" needs a custom middleware, not a closure; (4) the request-scoped `PartnerContext` must be a `scoped` container binding or the explicit "run as system" escape is silently lost and fail-closed scopes return nothing to jobs and seeders; (5) the locked decisions D-08/D-09 differ from earlier research (`ARCHITECTURE.md` proposed an own VO with two rounding places and a database queue), so this document follows CONTEXT.md.

Stack is fixed by the owner; versions were resolved by Composer today: `laravel/framework` 13.35.0, `filament/filament` 5.10.0 (Livewire 4.4.7), `laravel/sanctum` 4.3.3, `spatie/laravel-permission` 8.3.0, `spatie/laravel-medialibrary` 11.23.9, `spatie/laravel-tags` 4.12.0, `spatie/laravel-activitylog` 5.1.1, `spatie/laravel-webhook-client` 3.7.0, `brick/money` 0.15.2 with `brick/math` 1.0.0, Pest 5.3.0 (needs PHPUnit 13.3, the skeleton pins 12.5), Larastan 3.12.3 with PHPStan 2.3.0, Pint 1.32.1, `laravel-lang/common` 6.8.0. All pass the licence allowlist (202 packages including dev, 130 without dev) and `composer audit`.

**Primary recommendation:** Build in this order: skeleton merge with hygiene cleanup, then DDEV and PostgreSQL test harness, then UUID/timestamp conventions with schema tests, then Money, allocator and immutability pilot, then roles/install/2FA/localisation, then Partner isolation and registry, then CI and repository files. Use the exact recipes below; treat every `[ASSUMED]` item as needing a quick confirmation checkpoint.

## Research Answers Index (orchestrator questions)

| # | Question | Answer lives in |
|---|----------|-----------------|
| 1 | UUID v7 for package tables, migrations vs subclasses, native default vs `HasUuids`, morph map | "UUID v7 and morph conventions" |
| 2 | Schema-test mechanics | "Schema tests" |
| 3 | Money / brick integration | "Money" |
| 4 | Sequence allocator and parallel tests | "Sequence allocator" |
| 5 | Partner default-deny, registry test, canary harness | "Partner isolation" |
| 6 | Filament TOTP, Admin-only enforcement, CLI reset, install command | "Admin install and 2FA" |
| 7 | Czech localisation | "Localisation" |
| 8 | DDEV and `.env.example` | "DDEV" |
| 9 | CI, PHPStan, formatter, licence allowlist | "CI" |
| 10 | Immutability pilot | "Immutability pilot" |
| 11 | Repository files and hygiene collisions | "Repository files and hygiene collisions" |

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` (project instructions, read this session).

- Stack fixed by the brief: PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL, S3-compatible private bucket, Sanctum, Stripe Payment Links, queue (Redis, decided). AGPL-3.0; all dependencies must be AGPL-compatible; no paid or closed packages.
- Security: Partner must never see measured time, rates, prices or finance, nor another client's data; enforced by Policies and global query scopes, not UI hiding.
- Data integrity: UUID v7 keys everywhere including package morph columns; FK, unique, partial indexes and CHECK constraints in the database.
- Concurrency: number sequences gap-free and duplicate-free under concurrent creation.
- Immutability: issued invoices and billed time entries immutable; snapshots for supplier, customer, rates, exchange rates.
- Repository hygiene: fictional data only (`example.com` addresses, company ID `12345678`, paths like `/Users/example/`, test fakes assembled at runtime from fragments). Review procedure before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. The lefthook pre-commit hook must never be bypassed. After regenerating GSD blocks in `.claude/CLAUDE.md`, rerun `scripts/check-sensitive.sh .claude/CLAUDE.md`.
- Workflow: file changes go through a GSD command; this research writes only under `.planning/`.
- `commit_docs` is false for this project: research and planning documents are not committed by the agents.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Key/morph/timestamp conventions | Database / Storage | API / Backend (models, migrations) | PostgreSQL catalogue is the source of truth; schema tests introspect it, not PHP |
| Immutability, CHECK, partial unique indexes | Database / Storage | API / Backend (friendly pre-check) | Triggers fire for every writer including raw SQL and package code |
| Gap-free number allocation | Database / Storage (row lock) | API / Backend (allocator service) | Lock lives in PostgreSQL; the service only must run inside the caller's transaction |
| Money representation and rounding | API / Backend (value object) | Database (bigint + `char(3)` CHECK) | One PHP rounding point; DB stores integers only |
| Partner default-deny | API / Backend (global scope + policies) | Database (denormalised `client_id` later) | Filament, API and jobs all go through Eloquent; UI hiding is not security |
| Filament access declarations | Frontend Server (Filament classes) | API / Backend (policies) | Pages and Widgets default to visible, so each class declares and enforces its rule |
| 2FA enforcement for Admin | Frontend Server (route middleware) | API / Backend (user model columns) | Redirect to set-up happens in panel middleware; secrets live encrypted on the user row |
| Localisation, number/date formats | Frontend Server (Filament defaults, `lang/cs`) | Browser / Client (Intl via Filament JS) | Stored in UTC, formatted in `Europe/Prague` at display time |
| Dev environment and daemons | Infrastructure (DDEV) | — | Versioned `.ddev/` is the contract for FND-20 |
| CI gates | Infrastructure (GitHub Actions) | — | Same PHP/PostgreSQL versions as DDEV |

## Standard Stack

### Core
| Library | Version (resolved 2026-10-07) | Purpose | Why Standard |
|---------|-------------------------------|---------|--------------|
| PHP | 8.5 (local CLI 8.5.11, DDEV image 8.5.8) | Runtime | Fixed by brief; DDEV supports 8.5 `[VERIFIED: lab ddev php -v]` |
| `laravel/framework` | 13.35.0 | Framework | Fixed; `HasUuids` emits UUIDv7 via `Str::uuid7()` `[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUuids.php:18 "return (string) Str::uuid7();"]` |
| `filament/filament` | 5.10.0 (Livewire 4.4.7) | Single SPA panel, TOTP MFA, Czech packs | Fixed; ships `cs` language files for actions, forms, tables, panels, schemas, notifications, widgets `[VERIFIED: lab ls vendor/filament/*/resources/lang/cs]` |
| `laravel/sanctum` | 4.3.3 | Token table now, API in Phase 7 | Its migration is one of the package tables the schema test must cover |
| `spatie/laravel-permission` | 8.3.0 | Roles Admin/Partner | Accepts backed enums in `assignRole`/`hasRole` `[VERIFIED: lab, RoleName enum]` |
| `spatie/laravel-medialibrary` | 11.23.9 | `media` table (used from Phase 9) | Install now so its table is under the schema test |
| `spatie/laravel-tags` | 4.12.0 | `tags`/`taggables` (used from Phase 4) | Same reason |
| `spatie/laravel-activitylog` | 5.1.1 | `activity_log` (used from Phase 3) | Same reason; v5 API differs from v4 tutorials |
| `spatie/laravel-webhook-client` | 3.7.0 | `webhook_calls` (used from Phase 11) | Same reason |
| `brick/money` | `~0.15.2` (0.15.2, pre-1.0) | Arithmetic and rounding behind the own VO | D-08; pre-1.0, so pin the minor `[VERIFIED: lab composer show]` |
| `brick/math` | `^1.0` (1.0.0) | Exact rationals, `RoundingMode::HalfUp` | Laravel already requires it; case name is `HalfUp` in 1.0 `[VERIFIED: vendor/brick/math/src/RoundingMode.php "case HalfUp;"]` |
| `laravel/prompts` | 0.3.25 (framework dep) | Install command prompts | `password()` is hidden input |

### Supporting (dev)
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `pestphp/pest` | 5.3.0 | Test runner | Requires `phpunit/phpunit ^13.3` and PHP ^8.4; the skeleton pins PHPUnit 12.5, so install with `-W` `[VERIFIED: lab composer upgraded phpunit 12.5.33 to 13.3.6]` |
| `pestphp/pest-plugin-laravel` | 5.0.1 | Laravel helpers for Pest | Always |
| `larastan/larastan` | 3.12.3 (PHPStan 2.3.0) | Static analysis | Level 8 on `app`, `routes`, `database/factories`, `database/seeders` |
| `laravel/pint` | 1.32.1 | Formatter | `pint --test` in CI |
| `laravel-lang/common` | 6.8.0 (`laravel-lang/lang` 15.37.3) | Czech Laravel/validation strings | `--dev`; commit the generated `lang/cs` files `[VERIFIED: lab php artisan lang:add cs]` |
| `brianium/paratest` | 7.25.0 | Parallel Pest runs | Optional; the concurrency tests must not run inside a parallel DB-per-worker setup unless they read the DB name at runtime |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Edited published migrations + subclasses | Only `Schema::morphUsingUuids()` | The switch fixes only `morphs()` columns, not `id()`, `foreignId()` or the permission pivots; keep it as a safety net, not the strategy |
| `Redis` DDEV image `redis:7` (add-on default) | `redis:8` or Valkey | The `redis:7` tag resolves to a release line with a non-OSI licence; acceptable for local dev only; pin deliberately `[ASSUMED]` |
| Third-party DDEV add-ons for RustFS and queue | Own compose file and `web_extra_daemons` | Add-ons from individual authors are supply-chain surface; the own files are 40 lines and lab-verified |
| Reusable workflow for app CI | Jobs inside `hygiene.yml` | `actionlint` 1.7.12 rejects the `$/` self-repository syntax that `zizmor` 1.30.1 asks for, and zizmor flags `./`; inline jobs satisfy both `[VERIFIED: lab, both linters run]` |

**Installation (in the merged skeleton):**
```bash
composer require filament/filament:^5.0 laravel/sanctum:^4.3 \
  spatie/laravel-permission:^8.3 spatie/laravel-medialibrary:^11.23 spatie/laravel-tags:^4.12 \
  spatie/laravel-activitylog:^5.1 spatie/laravel-webhook-client:^3.7 \
  brick/money:~0.15.2 brick/math:^1.0
composer require --dev pestphp/pest:^5.3 phpunit/phpunit:^13.3 pestphp/pest-plugin-laravel \
  larastan/larastan:^3.12 laravel-lang/common:^6.8 -W
php artisan filament:install --panels --no-interaction
```
`league/flysystem-aws-s3-v3` (S3 disk) and `spatie/laravel-settings` belong to Phase 3, not here.

**Version verification:** `composer show` output of the lab (above) and Packagist metadata fetched 2026-10-07. The GSD legitimacy seam supports only npm/pypi/crates, so the audit below was done against the Packagist API.

## Package Legitimacy Audit

Composer ecosystem is not supported by `gsd-tools package-legitimacy check` (usage: `--ecosystem <npm|pypi|crates>`), so each package was checked against the Packagist API (first release, monthly downloads, source repository, abandoned flag, licence). Names come from the existing `STACK.md` research and from the official Laravel/Filament documentation, and every package was installed from Packagist in the lab without conflict.

| Package | Registry | First release | Downloads (approx.) | Source Repo | Verdict | Disposition |
|---------|----------|---------------|---------------------|-------------|---------|-------------|
| laravel/framework | Packagist | 2013 | ~16M/month | github.com/laravel/framework | OK | Approved |
| filament/filament | Packagist | 2020 | ~4M/month | github.com/filamentphp/panels | OK | Approved |
| livewire/livewire | Packagist | 2019 | ~8M/month | github.com/livewire/livewire | OK | Approved (transitive) |
| laravel/sanctum | Packagist | 2020 | ~10M/month | github.com/laravel/sanctum | OK | Approved |
| spatie/laravel-permission | Packagist | 2015 | ~7M/month | github.com/spatie/laravel-permission | OK | Approved |
| spatie/laravel-medialibrary | Packagist | 2015 | ~3M/month | github.com/spatie/laravel-medialibrary | OK | Approved |
| spatie/laravel-tags | Packagist | 2016 | ~0.7M/month | github.com/spatie/laravel-tags | OK | Approved |
| spatie/laravel-activitylog | Packagist | 2016 | ~4M/month | github.com/spatie/laravel-activitylog | OK | Approved |
| spatie/laravel-webhook-client | Packagist | 2019 | ~0.7M/month | github.com/spatie/laravel-webhook-client | OK | Approved |
| brick/money | Packagist | 2017 | ~1.9M/month | github.com/brick/money | OK (pre-1.0, pin minor) | Approved |
| brick/math | Packagist | 2014 | ~21M/month | github.com/brick/math | OK | Approved |
| pestphp/pest, pestphp/pest-plugin-laravel | Packagist | 2020 | ~7M/month | github.com/pestphp/* | OK | Approved |
| larastan/larastan | Packagist | 2018 | ~8M/month | github.com/larastan/larastan | OK | Approved |
| laravel/pint | Packagist | 2022 | ~11M/month | github.com/laravel/pint | OK | Approved |
| laravel-lang/common | Packagist | 2023 | ~0.5M/month | github.com/Laravel-Lang/common | OK | Approved (dev only) |
| laravel/pao | Packagist | 2026-04 | ~6M/month | github.com/laravel/pao | OK (ships in the official skeleton) | Keep or drop; it only reformats CLI output of Pest/Pint/PHPStan as JSON for agents |

**Packages removed due to SLOP verdict:** none.
**Packages flagged as suspicious (SUS):** none. No postinstall-style Composer scripts were added by these packages beyond Filament's `filament:upgrade` in `post-autoload-dump` (publishes assets into `public/`).

## Architecture Patterns

### System Architecture Diagram

```
 browser ──HTTPS──> DDEV router ──> nginx/php-fpm (web container)
                                        │
                  ┌─────────────────────┼───────────────────────────────┐
                  │ Filament panel (SPA, one panel, Czech)               │
                  │  login ─> [rate limit 5] ─> password ─> TOTP        │
                  │       ─> EnsureAdminHasTwoFactor (Admin only)       │
                  │       ─> Page/Resource/Widget class                 │
                  │            └─ #[AccessRule] + canAccess/canView     │
                  └─────────────┬────────────────────────────────────────┘
                                │ Eloquent only (never DB::table on tenant data)
        ┌───────────────────────┼─────────────────────────────┐
        │ PartnerScope (global scope on PartnerIsolated models)│
        │   Admin | runAsSystem  ─> pass through               │
        │   Partner + client_id  ─> constrainForPartner()      │
        │   anything else        ─> WHERE 1 = 0 (fail closed)  │
        │ KokpitPolicy::before: Admin true | no Partner false  │
        └───────────────────────┬─────────────────────────────┘
                                │
   PostgreSQL 18 ◄──────────────┘   triggers · CHECK · partial unique idx · uuidv7() defaults
     number_sequences (row lock, caller's transaction)   timestamptz everywhere
   Redis (cache, sessions, queue) ◄── queue:listen + schedule:work daemons (DDEV supervisor)
   Mailpit (SMTP 1025)    RustFS (S3 :9000, bucket created by init container)

 CI (GitHub Actions, hygiene.yml): scan · workflow-lint · tests (pg18 service) · static-analysis · dependencies
                                   └──────────── all in needs of "CI Passed" ─────────────┘
```

### Recommended Project Structure

Follows `.planning/research/ARCHITECTURE.md` ("Recommended Project Structure"); only the parts Phase 2 creates:

```
app/
├── Console/Commands/            # InstallCommand (kokpit:install), ResetAdminTwoFactorCommand
├── Domain/
│   ├── Identity/                # User, Role, Permission (UUID subclasses), RoleName enum
│   └── Shared/
│       ├── Auth/                # PartnerContext, PartnerScope, PartnerIsolated, IsolatesPartners,
│       │                        # DeniesPartners, NotPartnerScoped, KokpitPolicy, AccessRule, Audience
│       ├── Models/              # KokpitModel (HasUuids, strictness), package-model subclasses
│       ├── Money/               # Money, MoneyCast
│       ├── Sequences/           # SequenceAllocator
│       └── Database/            # Immutability (migration helper), UuidMorphs notes
├── Filament/{Admin,Partner}/    # Dashboard page etc.; every class carries #[AccessRule]
├── Http/Middleware/             # EnsureAdminHasTwoFactor
└── Providers/                   # AppServiceProvider, MorphMapServiceProvider, Filament/AdminPanelProvider
.ddev/                           # config.yaml, docker-compose.rustfs.yaml, redis add-on files, commands
config/kokpit.php                # require_admin_two_factor, canary_harness
database/migrations/             # edited published migrations + number_sequences + guard function
lang/cs/                         # laravel-lang output + enums.php + app strings
scripts/check-licenses.php       # licence allowlist (OR semantics)
tests/{Unit,Feature,Concurrency,Arch,Isolation,Support}/
```

### Pattern 1: Edited published migrations plus HasUuids subclasses (answers Q1)
**What:** Publish each package migration, edit it before the first `migrate`, subclass each package model with `HasUuids`, register the subclass in the package config, call `Relation::enforceMorphMap`.
**Why not only subclass or only the DB default (lab evidence):**
- Package `Role` used directly against a uuid table: `null value in column "id" of relation "roles" violates not-null constraint` `[VERIFIED: lab]`.
- With `ALTER COLUMN id SET DEFAULT uuidv7()` the insert succeeds but the incrementing base model casts the returned key to int: `$role->id` becomes `int(1)` and `Role::find($role->id)` throws `invalid input syntax for type uuid: "1"` `[VERIFIED: lab, getCasts()['id'] is 'int']`. Silent key corruption, so subclass-and-register is mandatory and the DB default is only a safety net for raw SQL.
- With subclasses and edited migrations the full smoke ran: user, role assignment, Sanctum token (token string `uuid|secret`, `findToken` resolves the owner), tag attach, activity log (`subject_type` stored as `user`), media (`model_type` `user`), webhook call `[VERIFIED: lab smoke script]`.

**Edits (all lab-applied, migrations ran on PG 18 with zero schema-rule violations):**

| Table(s) | Edit |
|----------|------|
| `users` | `uuid('id')->primary()`, `timestampTz`/`timestampsTz`, add `uuid('client_id')->nullable()->index()` (no FK until Phase 4), add `text('app_authentication_secret')->nullable()`, `text('app_authentication_recovery_codes')->nullable()` |
| `sessions` | `foreignUuid('user_id')->nullable()->index()` |
| `password_reset_tokens`, `failed_jobs` | `timestampTz` |
| `permissions`, `roles` | `uuid('id')->primary()`, `timestampsTz()`; leave `teams` off (`permission.teams` is false by default) |
| `model_has_permissions`, `model_has_roles`, `role_has_permissions` | every `unsignedBigInteger($pivot…)` and `$columnNames['model_morph_key']` becomes `uuid(...)` |
| `activity_log` | `uuid('id')->primary()`, `nullableUuidMorphs('subject','subject')`, `nullableUuidMorphs('causer','causer')`, `timestampsTz()` |
| `media` | `uuid('id')->primary()`, `uuidMorphs('model')`, `timestampsTz()` (its own `uuid` column stays) |
| `tags`, `taggables` | `uuid('id')->primary()`, `foreignUuid('tag_id')`, `uuidMorphs('taggable')`, `timestampsTz()` |
| `personal_access_tokens` | `uuid('id')->primary()`, `uuidMorphs('tokenable')`, `timestampTz` for `last_used_at`/`expires_at`, `timestampsTz()` |
| `webhook_calls` | `uuid('id')->primary()` (replaces `bigIncrements`), `timestampsTz()` |
| `notifications` (`php artisan notifications:table`) | `uuidMorphs('notifiable')`, `timestampTz('read_at')`, `timestampsTz()` |

Own tables and every edited primary key also get the PostgreSQL safety net: `->default(DB::raw('uuidv7()'))` on the column definition `[VERIFIED: lab, raw insert produced a version-7 id, pg_get_expr returns "uuidv7()"]`.

**Subclass recipe (seven classes, identical shape):**
```php
// Source: lab-verified 2026-10-07
namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    use HasUuids;   // sets $incrementing=false, string key, UUIDv7 in Laravel 13
}
```
Register: `config/permission.php` `models.role|permission`, `config/media-library.php` `media_model`, `config/tags.php` `tag_model`, `config/activitylog.php` `activity_model`, `config/webhook-client.php` `configs.*.webhook_model`, and `Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class)` in a provider `[VERIFIED: lab, all registered and used]`. Subclass bases: `Spatie\Permission\Models\Role|Permission`, `Spatie\MediaLibrary\MediaCollections\Models\Media`, `Spatie\Tags\Tag`, `Spatie\Activitylog\Models\Activity`, `Spatie\WebhookClient\Models\WebhookCall`, `Laravel\Sanctum\PersonalAccessToken`.

**Safety net for later packages:** `Schema::morphUsingUuids()` in `AppServiceProvider::boot()` makes every future `morphs()` / `nullableMorphs()` call in a freshly published migration create uuid columns `[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1590-1599 "if (Builder::$defaultMorphKeyType === 'uuid') { $this->uuidMorphs(...)"]`. It does not fix `id()` or `foreignId()`, so the schema test remains the real guard.

**Morph map (answers the key-name question):** short singular snake_case aliases equal to the model's table noun. Phase 2 aliases: `user`, `role`, `permission`, `media`, `tag`, `activity`, `webhook_call`, `personal_access_token`. Later phases add `client`, `project`, `task`, `comment`, `time_entry`, `invoice`, `payment`, `transaction`. Register with `Relation::enforceMorphMap([...])` in `MorphMapServiceProvider`; it calls `requireMorphMap()` internally so an unmapped class throws `ClassMorphViolationException` `[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/Relation.php:542-547 "public static function enforceMorphMap(array $map, $merge = true) { static::requireMorphMap(); ..."]`.

### Pattern 2: Catalogue-driven schema tests (answers Q2)
**What:** One Pest file queries `pg_catalog` once and applies rules to every table in the current schema, so a package table added later is checked without anyone listing it.
**Rules (all implemented and passing on the edited schema; the same SQL returned 79 violations on the stock package migrations `[VERIFIED: lab]`):**

| Rule | Check |
|------|-------|
| R1 | Every column named `id` or `*_id`, every foreign-key column and every single-column primary key has type `uuid`, except entries in an explicit exempt map `table.column => reason` |
| R2 | No identity or `nextval(...)` default outside the exempt map |
| R3 | For every `<x>_type` column with a sibling `<x>_id`, the sibling is `uuid` (covers all eight package morph pairs found: `activity_log` x2, `media`, `model_has_permissions`, `model_has_roles`, `notifications`, `personal_access_tokens`, `taggables`) |
| R4 | No column of type `timestamp without time zone` anywhere (own and package) |
| R5 | Exempt map has no stale entries (each still exists), so the list cannot rot |
| R6 | Every single-column uuid primary key has default `uuidv7()` (belt and braces for raw inserts) |
| R7 | Morph map is non-empty, `Relation::requiresMorphMap()` is true, aliases contain no backslash, each mapped class uses `HasUuids` |
| R8 | Config-registered package model classes (list above) use `HasUuids` and are the subclasses |
| R9 | After one exercising seed (assign a role, create a token, attach a tag, add media with a fake disk, write an activity row, send a database notification) every distinct value in every morph `_type` column is a key of `Relation::morphMap()` |

Exempt map for Phase 2 (each with a reason string): `migrations.id`, `jobs.id`, `failed_jobs.id`, `job_batches.id`, `cache.key`, `cache_locks.key`, `sessions.id` (opaque session string; `sessions.user_id` is still checked), `password_reset_tokens.email`. Phase 3 adds `settings.id` when `spatie/laravel-settings` arrives. See Open Question 1 about dropping `jobs`, `job_batches`, `cache`, `cache_locks` entirely.

Core of the helper (lab version, 8 tests green in 0.6 s):
```php
// Source: lab-verified 2026-10-07
DB::select(<<<'SQL'
    SELECT c.oid::int AS reloid, c.relname AS tbl, a.attnum, a.attname AS col, a.atttypid::regtype::text AS udt,
           format_type(a.atttypid, a.atttypmod) AS typ,
           (a.attidentity <> '' OR coalesce(pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval(%', false)) AS auto_inc,
           EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = c.oid AND k.contype = 'f' AND a.attnum = ANY (k.conkey)) AS is_fk,
           EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = c.oid AND k.contype = 'p'
                   AND array_length(k.conkey, 1) = 1 AND a.attnum = ANY (k.conkey)) AS is_single_pk
    FROM pg_attribute a
    JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
    JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
    LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    WHERE a.attnum > 0 AND NOT a.attisdropped
    ORDER BY c.relname, a.attnum
SQL);
```
Then `filter(fn ($c) => $c->col === 'id' || str_ends_with($c->col, '_id') || $c->is_fk || $c->is_single_pk)->reject(exempt)->filter(fn ($c) => $c->udt !== 'uuid')` must be empty.

**Timestamps:** `Blueprint::timestamps()` on PostgreSQL emits `timestamp(0) without time zone` `[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:1074 "return 'timestamp'.(is_null($column->precision) ? '' : \"($column->precision)\").' without time zone';"]`, so every `timestamps()`, `nullableTimestamps()`, `timestamp()` becomes the `Tz` variant. Set the PostgreSQL session zone explicitly (`'timezone' => 'UTC'` in the `pgsql` connection array; `PostgresConnector::configureTimezone` runs `set time zone` when the key is present `[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Connectors/PostgresConnector.php:180-185]`); the lab server happened to report `Etc/UTC` but that is server configuration, not a guarantee.

### Pattern 3: Sequence allocator with real parallel-process tests (answers Q4)
**Table:** `number_sequences(id uuid pk default uuidv7(), scope_key varchar unique, next_value bigint default 1, timestamps tz)` plus `CHECK (next_value >= 1)` and a format CHECK on `scope_key` (`^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$`) `[VERIFIED: lab migration]`. Semantics: `next_value` is "the next number to hand out", so an importer can set it directly.

**Allocator (D-11 literal: row lock inside the caller's transaction):**
```php
// Source: lab-verified 2026-10-07
public function next(string $scopeKey): int
{
    if (DB::transactionLevel() === 0) {
        throw new LogicException('SequenceAllocator::next() must run inside the caller\'s transaction.');
    }
    DB::insert('INSERT INTO number_sequences (scope_key, next_value, created_at, updated_at)
                VALUES (?, 1, now(), now()) ON CONFLICT (scope_key) DO NOTHING', [$scopeKey]);
    $row = DB::selectOne('SELECT next_value FROM number_sequences WHERE scope_key = ? FOR UPDATE', [$scopeKey]);
    DB::update('UPDATE number_sequences SET next_value = next_value + 1, updated_at = now() WHERE scope_key = ?', [$scopeKey]);
    return (int) $row->next_value;
}
```
`ON CONFLICT DO NOTHING` closes the first-use race; the year lives in the key (`invoice:2026`), computed by the caller in `Europe/Prague`.

**Harness (lab: 8 workers x 25 allocations):**
- Parent (Pest test, no `RefreshDatabase` wrapper, own scratch table) spawns N `php tests/Concurrency/worker.php <startAt> <count> <scopeKey> <failEvery>` with `Symfony\Component\Process\Process` (symfony/process 8.1.7 is already installed), passes the DB env through, and waits for all.
- Start barrier: every worker busy-waits until a shared `microtime(true)` timestamp one second ahead, so the connections truly overlap. Inside the transaction each worker calls `pg_sleep(0.002)` to widen the race window and inserts `(n, pid)` into a probe table with a primary key on `n`.
- Assertions: row count equals the exact expected count, numbers are exactly `1..count`, `next_value` equals count+1, at least two distinct PIDs, zero worker failures. With `failEvery = 4` (every fourth transaction throws after allocating) the counter rolled back: 152 rows, `next_value` 153, still gap-free `[VERIFIED: lab]`.
- Results: 8x25 in 3.4 s, 20x10 in 3.6 s (about 1 s of that is the barrier); 200 of 200 gap-free `[VERIFIED: lab]`.
- **Mutation proof:** deleting only ` FOR UPDATE` from the SELECT left 55 of 200 rows (duplicates rejected by the probe primary key), so the count assertion is what detects a missing lock `[VERIFIED: lab]`. Keep a variant of the test that asserts the harness fails against a deliberately broken allocator stub, or at minimum assert the exact row count.
- The parent must not hold an open transaction around the children (committed rows are the point), clean its scratch tables in `afterEach`, and run in the same PostgreSQL database the test process uses (read `config('database.connections.pgsql.database')` at runtime).

### Pattern 4: Money over brick/money (answers Q3)
**Shape (lab-verified, 5 tests):** `final readonly class Money { public int $minor; public string $currency; }`, constructed only through `Money::ofMinor(int, string)` which validates the code via `Brick\Money\Currency::of()` (unknown or malformed codes throw `UnknownCurrencyException`). Methods: `zero`, `plus`, `minus` (same currency or `InvalidArgumentException`; overflow throws because `BigDecimal::toInt()` throws instead of wrapping), `equals`, `format($locale)`, `jsonSerialize`.

**The single rounding point (D-09):** exactly one method, `Money::fromExactMinor(BigNumber|string $exactMinor, string $currency)`, contains `RoundingMode::HalfUp`. Everything else is exact:
- `exactForDuration(Money $hourlyRate, int $seconds): BigRational` is `rate_minor * seconds / 3600`, never rounded.
- `forDuration(...)` = `fromExactMinor(exactForDuration(...))`, called once per invoice line on the line's total duration.
- `convert(string $decimalRate, string $toCurrency, int $unitAmount = 1)` multiplies minor units by the stored `NUMERIC(20,10)` rate (as `BigDecimal` from a string, never float), divides by the quoted unit amount (CNB quotes 1, 100 or 1000), then calls `fromExactMinor`.
- A test scans `app/` and fails if `RoundingMode::` appears in any file other than `Money.php`, and that the `brick/` namespace is imported only inside `Domain/Shared/Money`.

**Lab results:** 1234.56 CZK/hour for 67 minutes (4020 s) gives 137859 minor units (exact 137859.2); 3 minor units for half an hour gives 2 (1.5 rounds up); the negative case gives -2 (HALF_UP is away from zero, consistent with PostgreSQL `round()` and PHP `round()`); three entries of 29 minutes at 1.00/hour rounded per entry sum to 144 while the one line of 87 minutes gives 145, which is why rounding is once per line; 100.00 EUR at rate 24.4050000000 gives 244050 minor CZK; JPY with unit amount 100 converts correctly; `RoundingMode::Unnecessary` (the library default) throws `RoundingNecessaryException`, so an accidental implicit rounding fails loudly `[VERIFIED: lab]`.

**Persistence:** two columns per amount, `<name>_minor bigint` and `<name>_currency char(3)` with `CHECK (<name>_currency ~ '^[A-Z]{3}$')`; `MoneyCast` implements `CastsAttributes`, reads both attributes in `get()` and returns `["{$key}_minor" => ..., "{$key}_currency" => ...]` from `set()` `[VERIFIED: lab round trip on PostgreSQL, including `decimal:10` cast returning the string '0.0166666667']`. Rates and exchange rates use `decimal(20,10)` with the `decimal:10` cast so PHP never sees a float.

**Unit conflict to resolve (Open Question 2):** D-09 says "exact minutes", TI-08 and PITFALLS say time is stored exactly in seconds. The method above takes seconds, which equals minutes/60 for whole minutes and removes the conflict.

### Pattern 5: Fail-closed Partner isolation (answers Q5)
**Components (names follow ARCHITECTURE.md where it names them):**
- `RoleName` enum (`Admin = 'admin'`, `Partner = 'partner'`); roles created by the install command and by a seeder using `Role::findOrCreate`.
- `PartnerContext` (lab prototype called `AccessContext`): `isAdmin()`, `partnerClientId()` (non-null only when the user has role Partner **and** a `client_id`), `runAsSystem(callable)`. **Register it with `$this->app->scoped(PartnerContext::class)`.** With a plain `app(...)` resolution the system flag set in one call site was invisible to the scope (count 0 instead of 2) `[VERIFIED: lab, test failed then passed after `scoped()`]`; `scoped` also resets between queue jobs.
- `PartnerIsolated` interface: `constrainForPartner(Builder $query, string $clientId): void`; there is no default implementation, so each tenant model must decide.
- `PartnerScope` (global scope): Admin or system run passes through; a Partner with a client calls `constrainForPartner`; every other state (guest, Partner without `client_id`, user with no role, unknown role, model that does not implement the interface) adds `whereRaw('1 = 0')` `[VERIFIED: lab, 6 tests: guest 0, Partner without client 0, role-less user with a client id 0, Partner sees only own client rows, Admin sees 2, system sees 2]`.
- `IsolatesPartners` trait registers the scope in `bootIsolatesPartners()`.
- `DeniesPartners` trait (implements the interface with `whereRaw('1 = 0')`) for Admin-only package subclasses: `Media`, `Tag`, `Activity`, `WebhookCall` in Phase 2 (later phases relax `Media` and `Tag` deliberately).
- `#[NotPartnerScoped(reason: '...')]` attribute for models that must stay unscoped because authentication needs them: `User`, `Role`, `Permission`, `PersonalAccessToken`. A scope on these would recurse (the scope itself calls `hasRole`) or lock everyone out; the arch test below forces the decision to be written down. `[ASSUMED]` for the exact failure mode of `PersonalAccessToken`, reasoned from `findToken()` running before any user exists.
- `KokpitPolicy` base class: `before(?User $user, string $ability): ?bool` returns `false` for a guest, `true` for Admin (the single explicit Admin rule), `false` for anyone who is not a Partner with a client (unknown role), `null` for a valid Partner so the explicit per-ability methods decide; every standard ability method (`viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`, `reorder`, `replicate`) returns `false` by default. Do not use a global `Gate::before` (PITFALLS Pitfall 3).
- Panel `->strictAuthorization()`: a Resource whose model has no policy, or a policy missing the requested method, throws `LogicException` instead of allowing `[VERIFIED: vendor/filament/filament/src/helpers.php:66-83 "if (Filament::isAuthorizationStrict()) { ... throw new LogicException(blank($policyClass) ? \"Strict authorization mode is enabled, but no policy was found for [{$modelName}].\" ..." and Panel/Concerns/HasAuth.php:691 "public function strictAuthorization(bool | Closure $condition = true): static"]`. It covers Resources only; custom Pages and Widgets default to allowed `[VERIFIED: vendor/filament/filament/src/Pages/Concerns/CanAuthorizeAccess.php:17-23 "// Security: Custom pages default to allowing access for all authenticated panel users." / "return true;" and vendor/filament/widgets/src/Widget.php:34-37 "public static function canView(): bool { return true; }"]`.

**Declaration attribute and registry test (D-03):**
```php
#[Attribute(Attribute::TARGET_CLASS)]
final class AccessRule { public function __construct(public readonly Audience $audience, public readonly string $reason = '') {} }
enum Audience: string { case AdminOnly = 'admin_only'; case PartnerAllowed = 'partner_allowed'; }
```
- The attribute must sit on the concrete class itself (PHP does not inherit attributes), which is the "explicit per class" requirement.
- A trait `EnforcesAccessRule` implements `canAccess()` (Pages, Resources), `canView()` (Widgets) and `canViewForRecord()` (relation managers) from the attribute: `AdminOnly` returns `isAdmin()`; `PartnerAllowed` returns `isAdmin() || partnerClientId() !== null`. The declaration therefore drives behaviour and cannot lie.
- Registry test: collect `$panel->getResources()`, `getPages()`, `getWidgets()`, `getClusters()` plus every resource's `getRelations()` (flatten `RelationGroup::getManagers()`), and assert each class has the attribute `[VERIFIED: lab, API calls work; the test fails today on Filament\Pages\Dashboard, AccountWidget and FilamentInfoWidget]`. Add a second walk over `app/Filament/**` classes so classes used only through `getHeaderWidgets()` are caught too. Replace the stock Dashboard with an own `App\Filament\Pages\Dashboard` (declared `PartnerAllowed`, no widgets), and remove `AccountWidget` and `FilamentInfoWidget` from the panel.
- Behaviour test: for each declared class, as a Partner, `canAccess()`/`canView()` equals `audience === PartnerAllowed`.
- Model declaration test: every concrete class in `app/Domain/**/Models` (and package subclasses) either implements `PartnerIsolated` or carries `#[NotPartnerScoped]`.
- Policy test: every model implementing `PartnerIsolated` has a policy that extends `KokpitPolicy`.
- Static test: `withoutGlobalScopes(` (bare form) and `DB::table(` on tenant tables appear only in an explicit allowlist of files.

**Canary harness (D-04):** `tests/Support/CanaryRecord` (test-only model with `client_id`, `secret`, implements `PartnerIsolated`), table created inside each test (PostgreSQL DDL is transactional, so `RefreshDatabase` rolls it back `[VERIFIED: lab]`), two fictional client UUIDs, canary strings assembled at runtime (`'CANARY_'.'B_'.bin2hex(random_bytes(4))`), users for every state in the fail-closed matrix. The harness exposes a registry (`CanaryRegistry::register(Model::class, fixtureFactory)`) and a test that fails if a `PartnerIsolated` model lacks a registered fixture, so "one line per real model later" is enforced. A route walk requests every GET route of the panel as Partner A and asserts that no response body (and no Livewire snapshot JSON) contains a client-B canary. Optional but recommended: a test-only Filament Resource for `CanaryRecord` registered only when `config('kokpit.canary_harness')` is true (set by `phpunit.xml`, boot guard throws if true in production) so the Filament query path (`getEloquentQuery`, global search, select options) is exercised from day one.

### Pattern 6: Immutability pilot (answers Q10)
Generic trigger function installed by a migration, parameterised per table by `TG_ARGV`, lab-verified on PostgreSQL 18.6:
```sql
CREATE FUNCTION kokpit_guard_frozen_row() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  frozen_when text := TG_ARGV[0];                                  -- lifecycle column
  open_state  text := TG_ARGV[1];                                  -- the only editable state
  allowed     text[] := string_to_array(coalesce(TG_ARGV[2], ''), ',');  -- operational columns
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF to_jsonb(OLD) ->> frozen_when IS DISTINCT FROM open_state THEN
      RAISE EXCEPTION 'row in %.% is immutable (state %): delete refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, to_jsonb(OLD) ->> frozen_when USING ERRCODE = 'KP001';
    END IF;
    RETURN OLD;
  END IF;
  IF to_jsonb(OLD) ->> frozen_when IS DISTINCT FROM open_state THEN
    IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
      RAISE EXCEPTION 'row in %.% is immutable (state %): update refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, to_jsonb(OLD) ->> frozen_when USING ERRCODE = 'KP001';
    END IF;
  END IF;
  RETURN NEW;
END $$;
-- per table:
CREATE TRIGGER <t>_guard BEFORE UPDATE OR DELETE ON <t> FOR EACH ROW
  EXECUTE FUNCTION kokpit_guard_frozen_row('status', 'draft', 'note,updated_at');
```
Pilot table (created inside the test, not shipped): `status` CHECK in (`draft`,`issued`), `CHECK (status = 'draft' OR (number IS NOT NULL AND issued_at IS NOT NULL))`, `CHECK (amount_minor >= 0)`, `CHECK (currency ~ '^[A-Z]{3}$')`, `CREATE UNIQUE INDEX ... (number) WHERE number IS NOT NULL`.

**Lab verdicts:** insert draft OK; issued without number rejected `23514`; negative amount rejected `23514`; draft to issued update allowed; duplicate number rejected `23505`; two drafts with NULL numbers allowed (partial index); changing `amount_minor` of an issued row rejected with custom SQLSTATE `KP001`; changing `note` of an issued row allowed; deleting an issued row rejected `KP001`; deleting a draft allowed `[VERIFIED: lab]`. `KP001` is a legal user-defined SQLSTATE class (first letters I-Z).

**Raw-SQL test helper:** `RawSql::expectSqlState(string $state, Closure $statement)` runs the statement inside `DB::transaction()` (nested, so Laravel issues a SAVEPOINT; without it a failed statement aborts the surrounding `RefreshDatabase` transaction and every later statement errors), catches `QueryException`, and compares `$e->getPrevious()->errorInfo[0]` `[VERIFIED: lab]`. A second helper `RawSql::expectAllowed(Closure)`. Both live in `tests/Support`.

**Limits to document:** row triggers do not fire for `TRUNCATE` (add a `BEFORE TRUNCATE` statement trigger where it matters, but test cleanup that truncates would conflict), the table owner can `ALTER TABLE ... DISABLE TRIGGER`, and `session_replication_role = replica` skips triggers; the application database role should not be a superuser in production `[ASSUMED]` (DDEV's `db` role is a superuser `[VERIFIED: lab pg_roles]`).

### Pattern 7: Admin install, TOTP and enforcement (answers Q6)
**User model:** implements `Filament\Models\Contracts\FilamentUser` (`canAccessPanel`: any of the two roles), `HasAppAuthentication` and `HasAppAuthenticationRecovery` with the two traits `InteractsWithAppAuthentication` and `InteractsWithAppAuthenticationRecovery`; the traits cast the secret `encrypted` and recovery codes `encrypted:array`, and hide both `[VERIFIED: vendor/filament/filament/src/Auth/MultiFactor/App/Concerns/*.php]`. Columns: `app_authentication_secret` and `app_authentication_recovery_codes`, both `text` nullable `[CITED: filamentphp.com/docs/5.x/users/multi-factor-authentication]`. Recovery codes are stored as bcrypt hashes and consumed under a cache lock plus row lock; TOTP codes are replay-protected at login (`shouldPreventCodeReuse: true`); login is rate limited to 5 attempts and the MFA challenge is rate limited too `[VERIFIED: AppAuthentication.php:397, Login.php:70,123]`. Rate limiting and the replay cache need a shared cache store (Redis in DDEV).

**Panel wiring (lab-verified):**
```php
$panel->login()->profile()->spa()->strictAuthorization()
    ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
    ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminHasTwoFactor::class);
```
**Why a custom middleware:** `isRequired` accepts a closure but it is evaluated while routes are built (`Pages/Concerns/HasRoutes.php:92 "...(static::isMultiFactorAuthenticationRequired($panel) ? [static::getMultiFactorAuthenticationRequiredMiddleware($panel)] : [])..."`), so it cannot see the signed-in user. `EnsureAdminHasTwoFactor extends Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled`, returns `$next($request)` unless `config('kokpit.require_admin_two_factor')` is true and the user is Admin, otherwise defers to the parent, which redirects to `Filament::getSetUpRequiredMultiFactorAuthenticationUrl()` when no provider is enabled.

**Lab tests (5 green):** Admin without 2FA and enforcement on is redirected to a URL containing `multi-factor-authentication/set-up`; enforcement off lets the Admin in; a Partner is never forced; an Admin with a secret passes; following the redirect renders the set-up page (no loop, body contains "Authenticator").

**Switching enforcement off (D-06):** `config/kokpit.php` `'require_admin_two_factor' => (bool) env('KOKPIT_REQUIRE_ADMIN_2FA', true)`; `phpunit.xml` sets it `false`, one dedicated test class flips it on; `.env.example` ships `true`. Add a boot guard in a provider: if `app()->isProduction()` and the flag is false, throw `RuntimeException` so "local development and tests only" is enforced rather than documented.

**CLI reset:** `kokpit:admin:reset-2fa {email}` with a `confirm()` prompt sets both columns to null (`forceFill`), writes a log line, and tells the operator that the Admin will be sent to set-up at the next login. Losing or rotating `APP_KEY` makes the encrypted secret undecryptable, which is exactly the lockout this command exists for; document `APP_PREVIOUS_KEYS` (read by `config/app.php`) in the README.

**Install command (lab-verified, 4 tests green):** `kokpit:install {--name=} {--email=}` using `Laravel\Prompts\text()` and `password()`; non-interactive mode (`--no-interaction`) reads `KOKPIT_ADMIN_PASSWORD` from the environment and fails if it is empty; the password is validated with `Password::min(12)` (do not use `->uncompromised()` in a CLI that may run offline); roles are `findOrCreate`d **before** `User::role(RoleName::Admin)->exists()`, because that scope throws `RoleDoesNotExist` on a fresh database `[VERIFIED: lab]`; a second run fails with a message and creates nothing; the body runs inside `PartnerContext::runAsSystem()`. Wrap creation in `DB::transaction()`; an advisory lock is optional for the double-start race.

### Pattern 8: Localisation (answers Q7)
- `APP_LOCALE=cs`, `APP_FALLBACK_LOCALE=en`, `APP_FAKER_LOCALE=cs_CZ`; app timezone stays `UTC` (hard-coded in `config/app.php`, no env) `[VERIFIED: lab config/app.php lines 68, 81-85]`.
- Filament ships `cs` for actions, filament, forms, infolists, notifications, query-builder, schemas, support, tables and widgets; login title renders "Přihlášení", delete label "Smazat" `[VERIFIED: lab]`.
- Laravel and validation strings: `composer require --dev laravel-lang/common` then `php artisan lang:add cs`, commit `lang/cs.json` and `lang/cs/{actions,auth,http-statuses,pagination,passwords,validation}.php`; `:attribute` renders as e.g. "E-mail musí být vyplněno." `[VERIFIED: lab]`. The pack still contains a few English leftovers (for example `array_keys`, `base64`), so a test must not assert "zero English strings"; assert the keys the app actually uses.
- Enum labels: enums implement `Filament\Support\Contracts\HasLabel` (`getLabel(): string|Htmlable|null`) returning `__('enums.<enum>.<case>')` from `lang/cs/enums.php`; a test reflects over all enums in `app/` that implement `HasLabel` and asserts each case's label differs from its translation key.
- Formats, set once in `AppServiceProvider::boot()`: `FilamentTimezone::set('Europe/Prague')` (`Filament\Support\Facades\FilamentTimezone`, falls back to `config('app.timezone')`); `Table::configureUsing(...)` and `Schema::configureUsing(...)` with `defaultDateDisplayFormat('j. n. Y')`, `defaultDateTimeDisplayFormat('j. n. Y H:i')`, `defaultTimeDisplayFormat('H:i')`, `defaultNumberLocale('cs')`, `defaultCurrency('CZK')`; `DateTimePicker::configureUsing(fn ($c) => $c->displayFormat('j. n. Y H:i'))`; `Number::useLocale('cs')` and `Number::useCurrency('CZK')` `[VERIFIED: lab method_exists true for Table, Schema, DateTimePicker configureUsing; Number::useLocale/useCurrency present at Support/Number.php:433,444]`.
- Czech output facts (ICU 78.3 on PHP 8.5.11): currency `1 234,50 Kč`, decimal `1 234,5`, grouping separator is a no-break space (U+00A0), date `6. ledna 2026`, an instant stored as 2026-01-05 23:30 UTC displays as `6. 1. 2026 00:30` in `Europe/Prague` `[VERIFIED: lab]`. Tests must normalise U+00A0 before comparing.
- Round-trip test: write an instant, read it back identical, assert its Prague rendering (summer time +2, winter +1), assert the PostgreSQL session reports UTC.

### Pattern 9: DDEV (answers Q8 and FND-20)
Lab-verified with DDEV 1.25.4 (Docker via OrbStack): `ddev start` boots web (nginx-fpm, PHP 8.5.8, extensions `intl bcmath pdo_pgsql redis gd exif zip imagick sodium` present), PostgreSQL 18.6, Redis (`PONG` from phpredis), RustFS (health 200, bucket created), Mailpit (sendmail path `/usr/local/bin/mailpit sendmail -t --smtp-addr 127.0.0.1:1025`), two supervised daemons `[VERIFIED: lab ddev describe, supervisorctl status]`.

`.ddev/config.yaml` (essential keys): `name: kokpit` (neutral, no instance values), `type: laravel`, `docroot: public`, `php_version: "8.5"`, `webserver_type: nginx-fpm`, `database: {type: postgres, version: "18"}`, `composer_version: "2"`, `nodejs_version: "24"` only if Phase 3 needs a theme build (omit otherwise), plus:
```yaml
web_extra_daemons:
  - name: queue-worker
    command: "until [ -f vendor/autoload.php ] && php artisan about --only=environment >/dev/null 2>&1; do sleep 5; done; exec php artisan queue:listen --tries=3 --sleep=1"
    directory: /var/www/html
  - name: scheduler
    command: "until [ -f vendor/autoload.php ] && php artisan about --only=environment >/dev/null 2>&1; do sleep 5; done; exec php artisan schedule:work"
    directory: /var/www/html
hooks:
  post-start:
    - exec: "PGPASSWORD=db psql -h db -U db -d postgres -tAc \"SELECT 1 FROM pg_database WHERE datname='kokpit_test'\" | grep -q 1 || PGPASSWORD=db createdb -h db -U db kokpit_test"
```
The wrapper waits for `vendor/` and a bootable app, so a fresh clone (no `vendor/`, no migrated DB) does not put the daemons into a restart loop `[VERIFIED: lab, both daemons RUNNING and spawning queue:work --once under queue:listen]`. The lab used the nested form `bash -c 'until ...; done; exec php artisan ...'` (DDEV already wraps the string in `bash -c`); the un-nested form shown here is expected to be equivalent `[ASSUMED]`, and the wait-loop behaviour on a truly empty checkout was not exercised `[ASSUMED]`, so the plan needs the clean-clone `ddev start` checkpoint. `queue:listen` reloads code per job, which suits development; production uses `queue:work` (Phase 3) `[ASSUMED: Laravel queue docs, not fetched]`. The `post-start` hook created `kokpit_test` idempotently `[VERIFIED: lab]`; the DDEV `db` role is a superuser with CREATEDB.

Redis: `ddev add-on get ddev/ddev-redis` installed v2.2.0 (official add-on; image from `${REDIS_DOCKER_IMAGE:-redis:7}`); commit the generated files (`docker-compose.redis.yaml`, `redis/`, `commands/`, `addon-metadata/redis/manifest.yaml`) `[VERIFIED: lab]`.

RustFS: own `.ddev/docker-compose.rustfs.yaml`, image pinned `rustfs/rustfs:1.0.1` (published 2026-10-03; `latest` also exists, do not use it) with env `RUSTFS_ACCESS_KEY`, `RUSTFS_SECRET_KEY`, `RUSTFS_ADDRESS=:9000`, `RUSTFS_CONSOLE_ENABLE=true`, `RUSTFS_CONSOLE_ADDRESS=:9001`, `RUSTFS_VOLUMES=/data`, a named volume, and healthcheck `curl --fail http://localhost:9000/health` `[CITED: docs.rustfs.com/installation/docker]`. A second service `rustfs-init` (image `rustfs/rc:v0.1.36`, `entrypoint: ["/bin/sh","-c"]`, command `rc alias set local http://rustfs:9000 <key> <secret> && (rc bucket create local/kokpit-dev || true) && exec sleep infinity`) creates the bucket. **Pitfall:** without the trailing `exec sleep infinity` the init container exits successfully and `ddev start` fails with "container exited" `[VERIFIED: lab, failed then passed]`. Dev credentials are well-known public defaults (`.env.example` carries them); both Phase 1 scanners accept them `[VERIFIED: lab, check-sensitive.sh and gitleaks run over a draft .env.example]`. The compose file is a lab composition, not a third-party add-on; other DDEV projects on the owner's machine use a similar pattern and were not read.

**Commit set:** `.ddev/config.yaml`, `.ddev/docker-compose.rustfs.yaml`, the Redis add-on files, optional `.ddev/commands/*`. `.ddev/.gitignore` is generated by DDEV, lists itself and every generated template (providers, apache, nginx, postgres conf, `*.example`, README files, traefik certs) and must not be committed; with it present the `.ddev` templates no longer trip the sensitive-content scanner `[VERIFIED: lab, index rebuilt]`.

**DDEV rewrites the developer's `.env` DB settings at start** (the lab app reported `pgsql`/host `db` although `.env.example` said sqlite) `[VERIFIED: lab config:show]`; the project guard hook blocks tools from reading `.env`, so use `php artisan config:show` or read `.env.example` only. Write `.env.example` DDEV-ready (host `db`, `redis`, mail `127.0.0.1:1025`, S3 `http://rustfs:9000`) so `cp .env.example .env` is the only copy step.

`.env.example` keys for Phase 2: `APP_*` (locale `cs`, fallback `en`, faker `cs_CZ`), `DB_CONNECTION=pgsql` with DDEV host/port/db/user, `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`/host/port, `MAIL_MAILER=smtp` 127.0.0.1:1025 and `MAIL_FROM_ADDRESS="kokpit@example.com"`, `FILESYSTEM_DISK`, `AWS_*` for RustFS with `AWS_USE_PATH_STYLE_ENDPOINT=true` and `AWS_ENDPOINT=http://rustfs:9000`, `KOKPIT_REQUIRE_ADMIN_2FA=true`, `KOKPIT_ADMIN_PASSWORD=` (commented, install only), `KOKPIT_CANARY_HARNESS=false`. The S3 browser-reachable URL question (temporary URLs signed for `rustfs:9000` are not reachable from the host browser, and Laravel's `temporary_url` only rewrites the host after signing `[VERIFIED: vendor/laravel/framework/src/Illuminate/Filesystem/AwsS3V3Adapter.php:102-103]`) is a Phase 3 item (FND-16).

**Env sync (FND-14):** a literal "every config `env()` key is in `.env.example`" test is too noisy because framework configs read dozens of optional keys with defaults (lab: forward direction failed with 40+ keys). Use: (a) reverse direction strict, every documented key is read by some config file or sits in a small allowlist; (b) forward direction only for keys read without a default (`env('KEY')` with no second argument) plus a curated required list; (c) the CI job that boots from `cp .env.example .env` and runs `key:generate`, `migrate`, `kokpit:install` (this is the real proof of FND-01).

### Pattern 10: CI (answers Q9)
Add the application jobs to the existing `.github/workflows/hygiene.yml` and add them to the `needs` list of `ci-passed`. The file currently has jobs `scan`, `workflow-lint` and `ci-passed` with `needs: [scan, workflow-lint]` and the comment "the organisation ruleset requires a status check named exactly \"CI Passed\"" `[VERIFIED: .github/workflows/hygiene.yml:104-109]`. Two checks with the same name in two workflows would be ambiguous, and a reusable workflow is rejected by one of the two pinned linters (see Alternatives). Lab result: merged file passes `actionlint` 1.7.12 and `zizmor` 1.30.1 with no findings `[VERIFIED: lab]`.

New jobs (all `runs-on: ubuntu-24.04`, `permissions: contents: read`, `persist-credentials: false`, pinned actions with a version comment as in the existing file):
- `tests`: service `postgres:18` with `pg_isready` health check, job `env` `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` (real environment wins over `phpunit.xml` `<env>`), `shivammathur/setup-php` with `php-version: "8.5"` and extensions `intl, bcmath, pdo_pgsql, gd, exif, zip, sodium, mbstring, redis`, `composer install --prefer-dist`, then the "boot from `.env.example` alone" step (`cp .env.example .env`, `key:generate`, `migrate --force`, `KOKPIT_ADMIN_PASSWORD=$(openssl rand -base64 24) php artisan kokpit:install --no-interaction --name=... --email=ci@example.com`), then `vendor/bin/pest`. Do not pass `--ci` (it is not in Pest 5.3's option list).
- `static-analysis`: `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- `dependencies`: `composer validate --strict`, `composer audit --locked`, `composer licenses --locked --format=json | php scripts/check-licenses.php`.
- Action pins resolved today via `gh api`: `actions/checkout` v7.0.1 (already pinned in the file), `shivammathur/setup-php` 2.37.2 (commit starts `f3e473d`), `actions/cache` v6.1.0 (commit starts `55cc834`) if Composer caching is added. Resolve full SHAs again with `gh api repos/<owner>/<repo>/git/ref/tags/<tag>` when writing the file. setup-php lists PHP 8.5 on the Ubuntu 26.04 runner and supports `php-version` 8.5 generally `[CITED: github.com/shivammathur/setup-php README]`; confirm it installs on `ubuntu-24.04` on the first run.
- Add `package-ecosystem: composer` to `.github/dependabot.yml` (currently only `github-actions`) `[VERIFIED: .github/dependabot.yml]`.
- Add a bash test `scripts/tests/test-workflow.sh` asserting that `ci-passed.needs` contains every job id of the workflow, so a job can never be dropped from the gate silently.
- CONTRIBUTING's `CI` section and `scripts/tests/test-docs.sh` (required phrases) must keep passing; update the section text to list the new jobs.

**PHPStan:** `larastan/larastan` 3.12.3, `phpstan.neon` with `includes: vendor/larastan/larastan/extension.neon`, `level: 8`, `paths: app, routes, database/factories, database/seeders`, `tmpDir: storage/framework/phpstan` (add it to `.gitignore`). The lab run at level 8 over a mixed tree reported only generics hints on `Scope`/`Builder` (fixable with `@implements Scope<Model>` and `Builder<Model>` docblocks), vendor-config noise (published `config/*.php`, so exclude `config/`), anonymous-migration return types (exclude `database/migrations`) and Pest `$this` property noise (exclude `tests/`) `[VERIFIED: lab]`. Do not add a baseline file in this phase.
**Pint:** default Laravel preset plus `declare_strict_types` for `app/Domain`; the formatter already runs in lab (`pint --test` reported fixers `fully_qualified_strict_types`, `ordered_imports` on freshly generated code, so run `pint` once before the first commit).

**Licence allowlist (FND-13):** `composer licenses --locked --format=json | php scripts/check-licenses.php`. Rule: a package passes if at least one of its declared licences is on the allowlist, because Composer lists alternatives of a dual-licensed package separately (`nette/utils`, `nette/schema`, `nette/php-generator` are BSD-3-Clause **or** GPL-2.0-only **or** GPL-3.0-only; a per-licence check would wrongly reject them) `[VERIFIED: lab composer licenses]`. Allowlist: MIT, MIT-0, BSD-2-Clause, BSD-3-Clause, 0BSD, ISC, Apache-2.0, Unlicense, CC0-1.0, MPL-2.0, LGPL-2.1-only/-or-later, LGPL-3.0-only/-or-later, GPL-3.0-only/-or-later, GPL-2.0-or-later, AGPL-3.0-only/-or-later; deliberately absent: GPL-2.0-only, anything proprietary, packages with no licence. Lab result: 202 packages (130 without dev) all pass; a synthetic GPL-2.0-only package fails with exit 1 `[VERIFIED: lab]`. Apache-2.0 is compatible with GPLv3/AGPLv3 but not GPLv2-only, and LGPL is compatible as an unmodified library, per the FSF compatibility notes already used in STACK.md `[CITED: .planning/research/STACK.md "Licence Audit"]` (not legal advice). Run it over dev dependencies too (cheap, catches a bad dev tool) and treat a failure as a prompt to decide, not to extend the list blindly.

### Pattern 11: Repository files and hygiene collisions (answers Q11)
Findings from copying the lab skeleton into a sandbox repository that carries the real Phase 1 tooling (index rebuilt, scanner and gitleaks run) `[VERIFIED: lab]`:

| Collision | Cause | Fix |
|-----------|-------|-----|
| `composer.lock`: about 208 `email` findings and one `public-ip` | Packagist author metadata (public maintainer e-mails) and one four-part dev-dependency version string that looks like an IPv4 address | Two path-scoped lines in `scripts/sensitive-allowlist.txt`: `email ;; ^composer\.lock$ ;; ^[^@ ]+@[^@ ]+$` and `public-ip ;; ^composer\.lock$ ;; *` (verified that `... ;; *` clears them). The file's own rule asks for anchored value patterns and CODEOWNERS covers it, so this needs the maintainer's explicit approval (Open Question 7) |
| `resources/views/welcome.blade.php`: 5 `company-id` findings | SVG path digits read as 8-digit IDs | Delete the view and point `/` at the panel (`redirect('/admin')` or panel path `''`) |
| `config/queue.php`: `hosting-host` finding in the scanner and `kokpit-hosting-endpoint` in gitleaks | Default SQS URL prefix | Remove the unused `sqs`, `beanstalkd`, `database` connections or blank the URL default |
| `README.md` e-mail finding | Laravel skeleton README | Replace with Kokpit README (no maintainer e-mails; use "Report a vulnerability" in SECURITY.md instead of an address) |
| `public/js/filament/**`, `public/css/filament/**`: many findings | Filament publishes compiled assets in `post-autoload-dump` (`filament:upgrade`) | Add `/public/js/filament/`, `/public/css/filament/`, `/public/fonts/filament/` to `.gitignore` (the skeleton's own `.gitignore` does this, but the skeleton `.gitignore` must not replace the Phase 1 one) |
| Fresh clone lacks `storage/framework/views` | Phase 1 `.gitignore` lines `/storage/framework/cache/`, `/storage/framework/sessions/`, `/storage/framework/views/`, `/storage/app/` ignore the skeleton's tracked `.gitignore` placeholder files (`git ls-files storage` was empty) `[VERIFIED: .gitignore:33-40 read + lab]`. `composer install` then fails when `filament:upgrade` clears the view cache | Change those lines to content-ignore form: `/storage/framework/{cache,sessions,views,testing}/*` each followed by `!<same dir>/.gitignore`, and `/bootstrap/cache/*` + `!/bootstrap/cache/.gitignore`; verdicts checked with `git check-ignore --no-index` (placeholders trackable, `storage/logs/laravel.log`, `storage/app/private/x.pdf`, `public/js/filament/forms/forms.js`, `bootstrap/cache/packages.php` ignored). Update `scripts/tests/test-gitignore.sh` in the same change, as CONTRIBUTING's "Per-phase .gitignore review" requires |
| `CLAUDE.md` and `AGENTS.md` in the skeleton root (`<laravel-boost-guidelines>` setup instructions, including curl-to-bash PHP installers) | New in the Laravel 13 skeleton | Do not import them. The repository's agent instructions live in `.claude/CLAUDE.md` only |
| `.npmrc` (`ignore-scripts=true`, `audit=true`) is ignored by Phase 1 `.gitignore` and asserted ignored by `test-gitignore.sh` | `.npmrc` may hold tokens | Keep it ignored; Phase 2 needs no Node build (Filament ships precompiled assets), so remove `package.json`, `vite.config.js`, `resources/js`, `resources/css` from the merge and reintroduce them in the phase that needs a theme, together with `.npmrc.example` (already trackable) and an npm licence step |
| Skeleton `.gitignore`, `.gitattributes`, `.editorconfig` | Overlap with Phase 1 files | Merge, never replace. The skeleton `.gitattributes` (`* text=auto eol=lf`, `diff=html/css/php/markdown`, `export-ignore`) is accepted by the Phase 1 attribute guard (no finding) and may be added; CODEOWNERS covers `/.gitattributes` |
| `.ddev/` generated files | See DDEV section | Commit only the non-generated set |

Merge recipe for the skeleton (Wave 0): `composer create-project laravel/laravel` into a temporary directory outside the repository, copy everything except `.gitignore`, `CLAUDE.md`, `AGENTS.md`, `README.md`, `vendor/`, `package.json`, `vite.config.js`, `resources/js`, `resources/css`, `resources/views/welcome.blade.php`; set `composer.json` `"license": "AGPL-3.0-only"` (see Open Question 3; `AGPL-3.0` alone is a deprecated SPDX id and `composer validate` warns `[VERIFIED: lab]`), name `kokpit/kokpit`; merge `.gitignore` lines; run `scripts/check-sensitive.sh --all` and `gitleaks` over the staged tree before the first commit; run `bash scripts/tests/run.sh` (baseline: 9 test files pass, about 40 s `[VERIFIED: lab]`).

**New repository files:**
- `SECURITY.md`: supported versions (latest release only), report through GitHub private vulnerability reporting ("Report a vulnerability" under the Security tab), no e-mail address in the file (the scanner flags any non-example address), expected response times, scope (the application, not the hosting), safe-harbour sentence, and a reminder that real data must never be attached. CONTRIBUTING already requires the phrase `SECURITY.md` (`test-docs.sh`).
- `README.md`: purpose, AGPL badge text, requirements (Docker, DDEV 1.25+, `gitleaks`, `lefthook`), the install sequence below, Czech UI note, link to CONTRIBUTING and SECURITY. Install sequence: `git clone`, `ddev start`, `cp .env.example .env`, `ddev composer install`, `ddev artisan key:generate`, `ddev artisan migrate`, `ddev artisan kokpit:install`, open the printed URL, enable 2FA. State the fictional-data rule.
- `CONTRIBUTING.md`: extend (never replace) with a Development section (DDEV commands, `composer test|lint|stan|licenses|ci` scripts, schema/morph rules, how to add a model: UUID, morph alias, `PartnerIsolated` or `NotPartnerScoped`, policy, `AccessRule`, canary registration) and update the `CI` section. All phrases in `scripts/tests/test-docs.sh` must remain.
- `LICENSE` exists (GNU Affero General Public License, Version 3, 19 November 2007) `[VERIFIED: LICENSE:1-2]`; add a test that `composer.json` license SPDX matches it and that README names the licence. Note for the maintainer: AGPL section 13 asks that users interacting over a network can obtain the source of a modified version; a footer link to the repository (panel render hook, URL from config) is a cheap way to honour it (not in scope unless the owner wants it).
- Add composer scripts: `test`, `lint` (`pint --test`), `stan`, `licenses`, `ci`.

### Anti-Patterns to Avoid
- **Using a package model class directly** (`Spatie\...\Role`) instead of the registered subclass: null ids, or integer-cast ids with a DB default (lab).
- **Registering `PartnerContext` as a plain resolution**: the system flag is lost; use `scoped`.
- **Relying on `isRequired: fn () => ...` for per-role 2FA**: evaluated at route build.
- **`RefreshDatabase` around the concurrency test** or running the children against a different database than the parent.
- **Per-licence allowlist checks** that reject dual-licensed packages.
- **Fail-closed scope on authentication models** (`User`, `Role`, `Permission`, `PersonalAccessToken`).
- **`DB::table()`/bare `withoutGlobalScopes()` on tenant data** outside an allowlist (PITFALLS Pitfall 5).
- **Docblock-only or UI-only (`->visible()`) access control**: the class attribute drives enforcement.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| TOTP, recovery codes, QR, replay and rate limiting | Own 2FA | Filament `AppAuthentication` + `pragmarx/google2fa` (already a dependency) | Locking, hashing, replay cache and rate limits are done and tested upstream (source read) |
| Exact decimal arithmetic and rounding | Float or `bcmath` wrappers | `brick/math` `BigRational`/`BigDecimal` via `brick/money` behind the VO | Overflow throws, rounding mode explicit; Laravel already requires `brick/math` |
| Hidden password prompt and validation | `stty`/`readline` | `Laravel\Prompts\password()` | Cross-platform, testable with `expectsQuestion` |
| Czech Laravel/validation strings | Hand-translated files | `laravel-lang/common` generated files | Maintained, covers framework updates |
| Czech date/number/currency formats | `str_replace` formatting | Filament `configureUsing` defaults + `Number::useLocale` (ICU) | Correct grouping and no-break spaces |
| Process spawning for parallel tests | `exec` + temp files | `Symfony\Component\Process\Process` (already installed) | Environment passing, exit codes, output capture |
| Licence parsing | Regex over `composer.lock` | `composer licenses --format=json` | Handles dual licences and normalisation |
| Gap-free counters | `MAX()+1`, sequences, `lockForUpdate()` outside a transaction | The allocator (row lock in the caller's transaction) | Races, gaps on rollback, no-op locks |
| DB immutability | Eloquent `updating` guards only | The generic trigger function plus CHECK and partial indexes | Package code, raw SQL and tinker bypass observers |

**Key insight:** every convention in this phase fails silently at runtime if it is only a convention (a bigint morph column, a float rounding, an unscoped query, an unprotected Page). The phase's value is the failing test next to each one.

## Runtime State Inventory

Not applicable: Phase 2 is greenfield (no rename, refactor or migration of existing runtime state). Verified: the repository contains no application code, no database, no OS-registered tasks; the only pre-existing assets are the Phase 1 hygiene tooling, which this phase extends.

## Common Pitfalls

### Pitfall 1: Skeleton commit trips the Phase 1 hook
**What goes wrong:** the first commit of the skeleton fails `check-sensitive.sh` (lock-file e-mails, welcome view, SQS URL, README) and gitleaks (`kokpit-hosting-endpoint`).
**How to avoid:** apply the collision table before `git add`; allowlist lines need the maintainer's approval; never `--no-verify`.
**Warning signs:** hundreds of `composer.lock:... email` lines.

### Pitfall 2: Fresh clone cannot run Composer
**What goes wrong:** `storage/framework/views` does not exist because the Phase 1 `.gitignore` ignores the placeholder files.
**How to avoid:** content-ignore patterns with `!.gitignore` negations plus a `test-gitignore.sh` case for each placeholder. Re-clone into a temp directory in CI-like conditions to prove it.

### Pitfall 3: Subclass registered nowhere
**What goes wrong:** the package falls back to its base model: null id (lab) or int-cast uuid (lab).
**How to avoid:** schema rule R8 plus a test that creates one row through each package's public API.

### Pitfall 4: PHPUnit 12 vs Pest 5
**What goes wrong:** `composer require pestphp/pest:^5.3` fails against the skeleton's `phpunit/phpunit ^12.5`; Composer then picks Pest 4.7.8 silently if you omit the constraint `[VERIFIED: lab, first install resolved pest 4.7.8]`.
**How to avoid:** `-W` with explicit `pestphp/pest:^5.3 phpunit/phpunit:^13.3`.

### Pitfall 5: Tests against the dev database
**What goes wrong:** `RefreshDatabase` runs `migrate:fresh` on whatever `DB_DATABASE` resolves to.
**How to avoid:** `phpunit.xml` pins `DB_DATABASE=kokpit_test`; `TestCase::setUp` aborts unless the database name ends in `_test`; the DDEV hook creates it; CI uses the same name. `phpunit.xml` `<env>` does not override real environment variables, so CI sets `DB_*` explicitly.

### Pitfall 6: Failed statement poisons the test transaction
**What goes wrong:** inside `RefreshDatabase`, one rejected raw statement aborts the transaction ("current transaction is aborted") and every later assertion fails.
**How to avoid:** wrap each expected failure in a nested `DB::transaction()` (savepoint) inside the `RawSql` helper (lab).

### Pitfall 7: Concurrency test can pass vacuously
**What goes wrong:** workers that fail are swallowed by a broad `catch`, or the expected count is not exact.
**How to avoid:** assert exact row count, consecutive numbers, `next_value`, distinct PIDs and zero failed workers; keep the removed-lock mutation in mind (55 of 200).

### Pitfall 8: Zero-or-everything on the system escape
**What goes wrong:** fail-closed scopes also silence the install command, seeders, queue jobs and webhook processing. `User::role()` also throws on a database without roles.
**How to avoid:** every console/job entry point wraps in `runAsSystem`; jobs still receive explicit ids (PITFALLS Pitfall 5); roles are created first.

### Pitfall 9: Per-licence allowlist
**What goes wrong:** `nette/*` (dual BSD/GPL) are rejected, tempting someone to add GPL-2.0-only to the list.
**How to avoid:** OR semantics in `check-licenses.php`; a negative test with a synthetic GPL-only package.

### Pitfall 10: DDEV init container exits
**What goes wrong:** `ddev start` fails because the one-shot bucket init container exits.
**How to avoid:** end the command with `exec sleep infinity` (lab). Daemons need a readiness wait (see DDEV) or they die before Composer ran.

### Pitfall 11: Two linters, one reusable-workflow syntax
**What goes wrong:** `uses: ./.github/workflows/x.yml` fails zizmor (self-repository audit), `uses: $/.github/workflows/x.yml` fails actionlint 1.7.12.
**How to avoid:** inline jobs in `hygiene.yml`.

### Pitfall 12: Timestamps without time zone
**What goes wrong:** `timestamps()` creates `timestamp(0) without time zone` on PostgreSQL, including in every package migration; the Prague display then shifts.
**How to avoid:** `timestampsTz()` everywhere and rule R4.

### Pitfall 13: Locked decisions vs earlier research
**What goes wrong:** the plan copies `ARCHITECTURE.md` Pattern 4 (own VO, two rounding places, "rounding exactly two places") or `STACK.md` "use the database queue".
**How to avoid:** CONTEXT.md wins: Redis for queue/cache/sessions, `brick/money` behind the VO, one rounding method. The skeleton's `jobs`, `job_batches`, `cache`, `cache_locks` tables are not needed (Open Question 1).

### Pitfall 14: Hidden English or missing Czech
**What goes wrong:** the `cs` pack has English leftovers, and Livewire/Filament strings come from several packages.
**How to avoid:** fallback locale `en`, tests assert the strings the app uses and enum labels, a manual Czech walk-through of login, 2FA set-up and profile pages at the phase gate.

### Pitfall 15: Recovery after APP_KEY change
**What goes wrong:** rotating `APP_KEY` makes the encrypted TOTP secret undecryptable and locks the Admin out.
**How to avoid:** `kokpit:admin:reset-2fa`, README note, `APP_PREVIOUS_KEYS`.

## Code Examples

Verified patterns are inline above (migration edits, schema SQL, allocator, harness, trigger, Money, scope, middleware, install command, DDEV YAML, CI jobs, licence script). Two more that the plan should copy verbatim.

### Licence allowlist script (`scripts/check-licenses.php`)
```php
// Source: lab-verified 2026-10-07 (202 packages pass; synthetic GPL-2.0-only package fails with exit 1)
$allow = ['MIT','MIT-0','BSD-2-Clause','BSD-3-Clause','0BSD','ISC','Apache-2.0','Unlicense','CC0-1.0','MPL-2.0',
    'LGPL-2.1-only','LGPL-2.1-or-later','LGPL-3.0-only','LGPL-3.0-or-later',
    'GPL-3.0-only','GPL-3.0-or-later','GPL-2.0-or-later','AGPL-3.0-only','AGPL-3.0-or-later'];
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($data['dependencies'] as $name => $dep) {
    $licenses = $dep['license'] ?? [];
    if ($licenses === [] || array_intersect($licenses, $allow) === []) { $bad[] = sprintf('%s [%s]', $name, implode(', ', $licenses) ?: 'no licence declared'); }
}
// print $bad to STDERR and exit(1) when non-empty
```

### Pest wiring and DB guard
```php
// tests/Pest.php
pest()->extend(Tests\TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature', 'Arch', 'Isolation');
pest()->extend(Tests\TestCase::class)->in('Concurrency');   // commits for real, cleans its own tables
// tests/TestCase.php::setUp(): abort unless str_ends_with(config('database.connections.pgsql.database'), '_test')
```
`phpunit.xml` env for the suite: `DB_CONNECTION=pgsql`, `DB_DATABASE=kokpit_test`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `KOKPIT_REQUIRE_ADMIN_2FA=false`, `KOKPIT_CANARY_HARNESS=true`. Pest's `toThrow(Throwable::class)` treats an interface name as a message string, use `Exception::class` or a concrete class `[VERIFIED: lab]`.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `VerifyCsrfToken` middleware | `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` in the Filament panel stack | Laravel 13 skeleton | Webhook routes (Phase 11) must exempt the new class `[VERIFIED: lab AdminPanelProvider]` |
| Filament 3/4 `Form`/`Table` signatures | Schema-based API, `Table::configureUsing`, `strictAuthorization()` | Filament 4/5 | Old tutorials do not compile |
| Pest 4 on PHPUnit 12 | Pest 5.3 on PHPUnit 13.3 (PHP ^8.4) | 2026 | Install with `-W` |
| `brick/math` `RoundingMode::HALF_UP` constants | Enum case `RoundingMode::HalfUp` | brick/math 1.0 | Confine to one file |
| Skeleton without agent files | Skeleton ships `CLAUDE.md`/`AGENTS.md` and `laravel/pao` | Laravel 13 | Do not import the files; `pao` only changes tool output |
| GitHub `uses: ./path` | `uses: $/path` self-repository syntax | July 2026 (per zizmor docs) | Tool support is uneven, so avoid local reusable workflows for now `[CITED: docs.zizmor.sh/audits]` |

**Deprecated/outdated:** SPDX id `AGPL-3.0` (use `-only` or `-or-later`); third-party DDEV add-ons for RustFS/queue (own files used instead).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `queue:listen` reloads code per job (good for dev) and `queue:work` is right for production | DDEV | Low: dev inconvenience only |
| A2 | The daemon readiness wait (`artisan about --only=environment`) behaves as intended on a truly empty clone (no `vendor/`, no `.env`) | DDEV | Medium: daemons may restart-loop on first start; a manual `ddev start` on a clean clone is a human-verify checkpoint |
| A3 | `PersonalAccessToken` must be `NotPartnerScoped` because token lookup happens before any user exists | Partner isolation | Medium: if scoped, API auth (Phase 7) breaks; an arch/behaviour test exercises it |
| A4 | The application's production database role will not be a superuser, so triggers cannot be bypassed via `session_replication_role` | Immutability | Medium: affects Zerops setup in Phase 3 |
| A5 | `redis:7` tag licence concerns are acceptable for local-only use; choose `redis:8` or Valkey deliberately | DDEV | Low |
| A6 | setup-php 2.37.2 installs PHP 8.5 on `ubuntu-24.04` | CI | Low: first CI run shows it |
| A7 | Moving the app CI jobs into `hygiene.yml` needs no change to the organisation ruleset because it requires only the check named `CI Passed` (CONTRIBUTING checklist) | CI | Low |
| A8 | `Media`, `Tag`, `Activity`, `WebhookCall` are deny-all for Partners in Phase 2 and are relaxed deliberately in Phases 4/9 | Partner isolation | Low; confirm with owner |
| A9 | `AGPL-3.0-only` (not `-or-later`) is the intended SPDX id | Repository files | Medium: owner decision, see Open Question 3 |
| A10 | Filament's `cs` translation is complete for every key the Phase 2 screens render | Localisation | Low; manual Czech walk-through |

## Open Questions

1. **Drop unused skeleton tables?** With Redis fixed for queue, cache and sessions, `jobs`, `job_batches`, `cache`, `cache_locks` are dead; `failed_jobs` (queue.failed `database-uuids`), `sessions` (named in success criterion 3) and `password_reset_tokens` stay.
   - What we know: CONTEXT fixes Redis; the success criterion lists `sessions`.
   - What's unclear: whether the owner wants a database-driver fallback for self-hosters.
   - Recommendation: drop the four dead tables (fewer exemptions), keep `sessions` with `foreignUuid('user_id')` and default `SESSION_DRIVER=redis`.
2. **Duration unit in the rounding method.** D-09 says exact minutes, TI-08 says exact seconds.
   - Recommendation: the method takes whole seconds (`seconds/3600`); minutes are a special case. Confirm at plan review.
3. **`AGPL-3.0-only` or `AGPL-3.0-or-later`?** LICENSE is the plain AGPL v3 text with no "or later" grant statement.
   - Recommendation: `AGPL-3.0-only` unless the owner states otherwise; the test only requires match with README text.
4. **Allowlist lines for `composer.lock`.** They widen a Phase 1 control; CODEOWNERS covers the file.
   - Recommendation: maintainer approves the two path-scoped entries in the same PR as the first lock file.
5. **Panel path.** Default `/admin` versus panel at the root. Not research-blocking; Phase 7 `/api/v1` and Phase 11 webhook routes must not collide with either.
6. **Phase 2 installs medialibrary, tags, activitylog, webhook-client and Sanctum now?** Recommended yes (criterion 3 names their tables), with models and configs ready and no feature code.
7. **`composer.lock` tooling note:** if Phase 3 introduces npm, an npm licence step and `package-lock.json` handling by gitleaks need a repeat of the collision check `[ASSUMED]`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | local scripts, Composer | yes | 8.5.11 (DDEV 8.5.8) | DDEV web container |
| Composer | install | yes | 2.10.3 | — |
| Docker | DDEV, PostgreSQL for tests | yes | 29.4.0 | — |
| DDEV | FND-20 | yes | v1.25.4 (supports PHP 8.5, Postgres 18) | — |
| PostgreSQL client | scripts | yes | psql 18.6 | `ddev psql` |
| PostgreSQL server (host) | — | no | — | DDEV `db` container, CI service container (a throwaway `postgres:18` container was used in research and removed) |
| Node / npm | not needed in Phase 2 | yes | 24.21.0 / 11.19.0 | omit Node build |
| gh CLI | action SHA lookup | yes | 2.102.0 | — |
| gitleaks / lefthook | hygiene hook | yes | 8.30.1 / 2.1.17 | — |
| actionlint / zizmor / shellcheck | workflow lint | yes | 1.7.12 / 1.30.1 / present | — |
| Redis server (host) | — | no | — | DDEV `redis` service |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** host PostgreSQL and Redis (provided by DDEV and CI services).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.0 on PHPUnit 13.3.6 with `pestphp/pest-plugin-laravel` 5.0.1; hygiene harness in bash (`scripts/tests/run.sh`, 9 files, passes today) |
| Config file | `phpunit.xml` (PostgreSQL `kokpit_test`), `tests/Pest.php`, `phpstan.neon`, `pint.json` (new, Wave 0) |
| Quick run command | `vendor/bin/pest tests/Unit tests/Feature/Schema tests/Arch` (under 30 s) |
| Full suite command | `composer ci` = `vendor/bin/pest && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && composer licenses --locked --format=json \| php scripts/check-licenses.php && bash scripts/tests/run.sh` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FND-01 | App boots from `.env.example` alone; panel login renders | CI job step + feature | `vendor/bin/pest tests/Feature/Boot` (plus the CI "boot from .env.example" step) | Wave 0 |
| FND-01 | `.env.example` has no dead keys, required keys present | unit | `vendor/bin/pest tests/Feature/Repo/EnvExampleTest.php` | Wave 0 |
| FND-20 | `.ddev/config.yaml` has PHP 8.5, postgres 18, daemons; compose has redis and rustfs | static (symfony/yaml) | `vendor/bin/pest tests/Feature/Repo/DdevConfigTest.php` | Wave 0 |
| FND-20 | `ddev start` on a clean clone is healthy, daemons RUNNING, bucket exists | manual-only | human-verify checkpoint at phase gate (needs Docker/DDEV) | — |
| FND-02 | Key/FK/morph columns uuid, no auto-increment, uuidv7 defaults | schema | `vendor/bin/pest tests/Feature/Schema/SchemaConventionsTest.php` | Wave 0 |
| FND-02 | Package models are the registered `HasUuids` subclasses; one row per package API | feature | `vendor/bin/pest tests/Feature/Schema/PackageModelsTest.php` | Wave 0 |
| FND-03 | No `timestamp without time zone`; session UTC; Prague display; morph map enforced; morph values in map | schema/feature | `vendor/bin/pest tests/Feature/Schema/TimeAndMorphTest.php` | Wave 0 |
| FND-04 | Rounding half-up once per line, conversion, cast round trip, single rounding file | unit + feature | `vendor/bin/pest tests/Unit/Money tests/Feature/Money` | Wave 0 |
| FND-05 | Gap-free, duplicate-free under real processes; rollback gap-free; outside-transaction refused | concurrency | `vendor/bin/pest tests/Concurrency` | Wave 0 |
| FND-06 | Fail-closed matrix (guest, no client, no role, Partner, Admin, system); policy base denies | feature | `vendor/bin/pest tests/Isolation` | Wave 0 |
| FND-11 | Locale `cs`, Czech Filament/validation text, formats, enum labels | feature | `vendor/bin/pest tests/Feature/Localisation` | Wave 0 |
| FND-12 | CHECK, partial unique index, trigger pilot with `RawSql` helper | database | `vendor/bin/pest tests/Feature/Database/ImmutabilityPilotTest.php` | Wave 0 |
| FND-13 | Workflow jobs gated by `CI Passed`; actionlint/zizmor clean; licence script fails on GPL-only | bash + unit | `bash scripts/tests/run.sh` (new `test-workflow.sh`) and `vendor/bin/pest tests/Unit/LicenceCheckTest.php` | Wave 0 |
| FND-14 | LICENSE/composer SPDX match; README, SECURITY.md, CONTRIBUTING required phrases | feature | `vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` and `bash scripts/tests/test-docs.sh` | Wave 0 |
| FND-17 | Install command (prompt, env password, second Admin refused); 2FA enforcement; CLI reset | feature | `vendor/bin/pest tests/Feature/Auth` | Wave 0 |
| FND-18 | Registry test, model declaration test, canary harness incl. route walk | arch + isolation | `vendor/bin/pest tests/Arch tests/Isolation` | Wave 0 |

### Sampling Rate
- **Per task commit:** the quick run command plus `scripts/check-sensitive.sh` (hook does the latter).
- **Per wave merge:** full suite command.
- **Phase gate:** full suite green, CI green, manual `ddev start` on a clean clone, and a Czech walk-through of login, 2FA set-up and profile before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] Skeleton merge with hygiene cleanup; `composer.json` licence and scripts; `phpunit.xml` PostgreSQL; `tests/Pest.php`, `tests/TestCase.php` with the `_test` database guard
- [ ] `tests/Support/{PgSchema,RawSql,CanaryRecord,CanaryRegistry}.php`
- [ ] All test files in the map above (none exist; repository has no application code)
- [ ] `scripts/check-licenses.php`, `scripts/tests/test-workflow.sh`, updated `scripts/tests/test-gitignore.sh`
- [ ] `phpstan.neon`, `pint.json`
- [ ] Framework install: composer commands above (Pest needs `-W`)

## Security Domain

`security_enforcement` is enabled in `.planning/config.json` (ASVS level 1, block on high), so this section applies.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Install command with hidden prompt, `Password::min(12)`, Filament login with built-in rate limiting (5 attempts), TOTP with replay protection and hashed recovery codes, no default password |
| V3 Session Management | yes | Redis sessions, Filament `AuthenticateSession` middleware (in the panel stack), session regenerated by the login pages; set `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE` in production (Phase 3) |
| V4 Access Control | yes | `PartnerScope` + `KokpitPolicy` + strict authorization + `#[AccessRule]` registry; default-deny |
| V5 Input Validation | yes | Laravel validation (Czech messages), DB CHECK constraints, typed `Money`; parameter binding only (migrations use `DB::unprepared` for static DDL only) |
| V6 Cryptography | yes | `encrypted` casts for TOTP secret/recovery codes (APP_KEY), bcrypt hashes; never hand-rolled |
| V7/V8 Errors, data protection | yes | `APP_DEBUG=false` outside local (QueryException messages in dev output contain bound values, as seen in lab), no secrets in repo, `.env.example` placeholders only |
| V14 Configuration | yes | Pinned CI actions, licence/audit gates, hygiene scanners |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-client read (IDOR, search, selects, relation managers) | Information disclosure | Model-level global scope (fail-closed), policies, canary harness, registry test |
| Permissive Filament defaults (Pages and Widgets visible) | Elevation of privilege | `#[AccessRule]` on every class, enforcing trait, registry test, strict authorization for Resources |
| Admin 2FA bypass by config in production | Elevation of privilege | Boot guard throws when enforcement is off in production |
| TOTP code replay, recovery-code reuse | Spoofing | Filament replay cache and locked, hashed recovery codes (needs shared cache) |
| Second Admin via race or re-run | Elevation of privilege | Command refuses; transaction (optional advisory lock) |
| Installer password exposure (process list, shell history) | Information disclosure | Hidden prompt; env var for automation only; never an argument |
| Mass assignment on `User` | Tampering | Explicit `$fillable` (the lab used `$guarded = []` for brevity, do not copy it) |
| Mutating issued records through raw SQL or tinker | Tampering | Triggers + CHECK (Phase 2 pilot), owner/superuser caveat documented |
| Duplicate or gapped numbers | Tampering/Repudiation | Row-locked allocator, real parallel test |
| Supply chain (Composer, Actions, DDEV add-ons) | Tampering | Licence/audit job, SHA-pinned actions, Dependabot for Composer, own DDEV files instead of third-party add-ons |
| Sensitive data in repository | Information disclosure | Phase 1 scanners; fictional data; runtime-assembled canaries |

## Sources

### Primary (HIGH confidence, executed or read this session)
- Throwaway lab project (Laravel 13.35.0, Filament 5.10.0, PHP 8.5.11, PostgreSQL 18.6, DDEV 1.25.4): schema catalogue audit, UUID/morph smoke, allocator and parallel harness with mutation, immutability pilot, Money, partner scope, MFA middleware, install command, registry test, licence script, Pest/PHPStan/Pint runs, DDEV start with Redis/RustFS/daemons, actionlint and zizmor runs (all deleted afterwards)
- Installed package sources under `vendor/` (paths and lines quoted inline): Laravel `Blueprint.php`, `Builder.php`, `PostgresGrammar.php`, `PostgresConnector.php`, `Relation.php`, `HasUuids.php`, `Number.php`, `AwsS3V3Adapter.php`; Filament `helpers.php`, `Panel/Concerns/HasAuth.php`, `Pages/Concerns/HasRoutes.php`, `CanAuthorizeAccess.php`, `Auth/MultiFactor/**`, `Auth/Pages/Login.php`, `support/src/TimezoneManager.php`, `HasDefaultDataFormattingSettings.php`; brick/math `RoundingMode.php`, brick/money `Money.php`
- Repository files read: `.planning/phases/02-platform-foundation/02-CONTEXT.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `.planning/research/{STACK,ARCHITECTURE,PITFALLS}.md`, `.claude/CLAUDE.md`, `.github/workflows/hygiene.yml`, `.github/dependabot.yml`, `.github/CODEOWNERS`, `.gitignore`, `scripts/sensitive-allowlist.txt`, `scripts/tests/test-docs.sh`, `scripts/tests/test-gitignore.sh`, `CONTRIBUTING.md`, `LICENSE`
- Packagist API (versions, first release, downloads, licence, abandoned flag), `composer licenses`, `composer audit`

### Secondary (MEDIUM confidence)
- https://filamentphp.com/docs/5.x/users/multi-factor-authentication (TOTP columns, `recoverable()`, `isRequired`)
- https://docs.ddev.com/en/stable/users/configuration/config/ (PHP 8.5, Postgres 9-18, laravel type) and https://docs.ddev.com/en/stable/users/extend/customization-extendibility/ (`web_extra_daemons`)
- https://docs.rustfs.com/installation/docker/ (image, ports, env, health check, uid 10001)
- https://docs.zizmor.sh/audits/ (self-repository audit)
- https://github.com/shivammathur/setup-php README (PHP 8.5 runner matrix), `gh api` tag lookups for action versions

### Tertiary (LOW confidence)
- Community DDEV add-on listings for RustFS and Laravel queue (searched, not used; shown only to justify own files)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions resolved by Composer today and exercised in the lab
- Architecture: HIGH for DB, money, allocator, 2FA, registry (executed); MEDIUM for canary route walk and test-only Resource (designed, not run)
- Pitfalls: HIGH — most were reproduced

**Research date:** 2026-10-07
**Valid until:** 2026-10-21 for package versions and action pins (fast-moving: Filament, Pest, RustFS, setup-php); conventions and SQL patterns remain valid until the next major of Laravel or Filament
