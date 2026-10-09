# Stack Research

**Domain:** Single-tenant open-source CRM/ERP (client -> project -> task -> time -> invoice -> payment) on Laravel + Filament, AGPL-3.0
**Researched:** 2026-10-06
**Confidence:** HIGH for versions and compatibility (read directly from Packagist metadata and package sources today); MEDIUM for Zerops specifics, UUID edits in third-party packages (read from source, not run), and Scramble/Stripe details.

> Note on the brief: PROJECT.md says "current stable Laravel and Filament". As of today that is **Laravel 13** and **Filament 5** (Livewire 4), not Laravel 12 / Filament 4. Everything below targets that pair. All Spatie packages in the brief have releases that support it.

## Recommended Stack

### Core Technologies

| Technology | Version (verified 2026-10-06) | Purpose | Why Recommended |
|------------|-------------------------------|---------|-----------------|
| PHP | 8.5 (8.5.11 current) | Runtime | Brief-mandated. Laravel 13 supports 8.3-8.5. Zerops offers `php@8.5` on php-nginx and php-apache. |
| Laravel | `^13.0` (13.35.0; requires PHP ^8.3) | Framework | Latest major (released 2026-03-17), bug fixes until Q3 2027, security until 2028-03-17. Laravel 12 is in security-only mode (bug fixes ended 2026-08-13), so start on 13. |
| Filament (panel) | `^5.0` (5.10.0) | Admin/Partner UI | Latest major. Requires Livewire `^4.4.7`, Laravel `^11.28\|12\|13`, PHP `^8.2`, and `ext-intl`. v4 (4.15.0) is the previous major; do not start a new project on it. |
| Livewire | `^4.4` (4.4.7, pulled by Filament) | Reactive components | Hard dependency of Filament 5. Do not pin separately. |
| PostgreSQL | 18 (18.6 current minor; EOL 2030-11-14) | Database | PG 18 ships native `uuidv7()` (verified in the PG 18 docs), which gives a DB-side default for UUID v7 keys. 19 is still beta. Zerops offers `postgresql@18`. Laravel 13 supports PG 13+, so 17 is an acceptable fallback but loses `uuidv7()`. |
| Laravel Sanctum | `^4.3` (4.3.3) | API tokens with abilities | Supports Laravel 11-13, PHP ^8.2. Token abilities map directly to the `/api/v1` time/timer scope. |
| Node.js (build only) | 24 LTS | Vite/Tailwind build | Only needed to compile the (minimal) Filament theme, see the kanban note below. Not a runtime dependency. |

### Spatie Packages (all verified Laravel 13 compatible)

| Package | Version | PHP req. | Purpose | UUID status |
|---------|---------|----------|---------|-------------|
| `spatie/laravel-permission` | `^8.3` (8.3.0) | ^8.3 | Roles Admin/Partner + permissions | Needs migration edit + custom Role/Permission models (see UUID matrix). Uses `getKeyName()` throughout, so column can stay named `id`. |
| `spatie/laravel-medialibrary` | `^11.23` (11.23.9) | ^8.2 | Documents on S3, conversions, ZIP via `MediaStream` | Needs migration edit (`uuidMorphs('model')`, uuid PK) + custom `Media` model with `HasUuids`. |
| `spatie/laravel-tags` | `^4.12` (4.12.0) | ^8.1 | Client tags | Needs migration edit (`foreignUuid`, `uuidMorphs('taggable')`) + custom `Tag` model (`tag_model` config). Depends on `spatie/laravel-translatable ^6` (MIT) and `eloquent-sortable`. |
| `spatie/laravel-activitylog` | `^5.1` (5.1.1) | **^8.4** | Audit trail | Needs migration edit (`nullableUuidMorphs` x2, uuid PK) + custom `Activity` model (`activity_model` config). v5 is a breaking rewrite vs v4: see compatibility notes. |
| `spatie/laravel-settings` | `^3.9` (3.9.0) | ^8.2 | Supplier data, numbering defaults, feature toggles | No morph columns. Table has `id()` bigint. Exempt it from the UUID rule (singleton key/value store, `group+name` unique). |
| `spatie/laravel-query-builder` | `^7.3` (7.3.5) | ^8.3 | `/api/v1` filtering/sorting/includes | No tables. Works with UUID keys. |
| `spatie/laravel-data` | `^4.23` (4.23.0) | ^8.1 | DTOs for API responses, ARES/CNB payloads, Stripe payload mapping | No tables. |
| `spatie/eloquent-sortable` | `^5.0` (5.0.1) | ^8.2 | Todo/checklist ordering, manual list order | No tables; orders by an int `order_column`. Works with UUID PKs. |
| `spatie/laravel-pdf` | `^2.14` (2.14.0) | ^8.2 | Invoice and work report PDF | Pluggable drivers (Browsershot default, DOMPDF, Gotenberg, WeasyPrint, Cloudflare, Chrome PHP). See the PDF recommendation below. |
| `spatie/laravel-webhook-client` | `^3.7` (3.7.0) | ^8.1\|^8.2 | Stripe webhook intake: store, verify, queue | Needs migration edit (`webhook_calls.id` -> uuid) + custom model (`webhook_model` config). |

### Payments, API docs, exports, QR

| Library | Version | Purpose | Recommendation |
|---------|---------|---------|----------------|
| `stripe/stripe-php` | `^22.0` (22.0.0, 2026-09-30; MIT; pins API `2026-09-30.endive`) | Payment Links + webhook signature verification | Use directly. Fallback `^21.3` only if a wrapper package forces it (see "What NOT to Use"). |
| `dedoc/scramble` | `^0.13` (0.13.47; MIT) | OpenAPI 3.1 generation from routes, FormRequests, Resources | Use free core only. Routes `/docs/api` and `/docs/api.json`; UI is gated to the `local` env unless you define a `viewApiDocs` gate. Pre-1.0: pin with `~0.13.47` and commit the exported spec in CI to catch drift. |
| `spatie/simple-excel` | `^3.10` (3.10.0; MIT) | Streaming CSV/XLSX export for reports | Recommended. Built on OpenSpout `^4.30`, which is compatible with Filament's own `openspout/openspout ^4.23` constraint and supports PHP 8.3-8.5 (4.32). |
| `chillerlan/php-qrcode` | `^5.0` (explicit require) | QR rendering for SPAYD | Already installed transitively by Filament (`^5.0`) for MFA; declare it explicitly. **Do not** require `^6` (6.0.1 exists but Filament pins `^5`). |
| `league/flysystem-aws-s3-v3` | `^3.35` (3.35.3; MIT) | S3-compatible disk driver | Required for the S3 disk. |
| `dompdf/dompdf` | `^3.1` (3.1.6; LGPL-2.1) | PDF driver (recommended default) | LGPL is compatible with AGPL-3.0 use as a library (see licence audit). |

### Filament plugin packages (all have 5.x releases today)

| Package | Version | Purpose |
|---------|---------|---------|
| `filament/spatie-laravel-media-library-plugin` | `^5.10` | Upload/gallery fields backed by medialibrary |
| `filament/spatie-laravel-settings-plugin` | `^5.10` | Settings pages backed by spatie/laravel-settings |
| `filament/spatie-laravel-tags-plugin` | `^5.10` | Tag input/column |
| `relaticle/flowforge` | `^4.1` (4.1.4; MIT) | Kanban board page (see kanban section) |
| `rmsramos/activitylog` | `^4.1` (4.1.0; supports activitylog `^4.8 \|\| ^5.0`, Filament `^5.0`) | OPTIONAL read-only activity log resource. Skip in v1 if a simple custom relation manager is enough. |

Do **not** add `filament/spatie-laravel-translatable-plugin` (latest is 3.3.x, no 5.x line; the app is single-language `cs` with `lang/cs` files).

### Development Tools

| Tool | Version | Purpose | Notes |
|------|---------|---------|-------|
| Pest | `^5.3` (requires PHP ^8.4) | Tests | Partner-isolation audit tests live here. |
| Laravel Pint | `^1.32` | Code style | PHP ^8.3. |
| Larastan | `^3.12` | Static analysis | Supports Laravel 13. |
| Laravel Pail | `^1.2` | Log tailing in dev | Optional. |
| gitleaks | latest (MIT, not a Composer dependency) | Secret scan in pre-commit and CI | Matches the step-0 hygiene requirement. |
| `composer licenses` / `composer audit` | built in | CI licence and advisory gate | Fail CI on any licence outside an allowlist (MIT, BSD, Apache-2.0, LGPL, ISC, GPL-compatible). |

## Installation

```bash
# Core + Spatie + integrations
composer require laravel/framework:^13.0 filament/filament:^5.0 laravel/sanctum:^4.3 \
  spatie/laravel-permission:^8.3 spatie/laravel-medialibrary:^11.23 spatie/laravel-tags:^4.12 \
  spatie/laravel-activitylog:^5.1 spatie/laravel-settings:^3.9 spatie/laravel-query-builder:^7.3 \
  spatie/laravel-data:^4.23 spatie/eloquent-sortable:^5.0 spatie/laravel-pdf:^2.14 \
  spatie/laravel-webhook-client:^3.7 stripe/stripe-php:^22.0 \
  league/flysystem-aws-s3-v3:^3.35 dompdf/dompdf:^3.1 spatie/simple-excel:^3.10 \
  chillerlan/php-qrcode:^5.0 relaticle/flowforge:^4.1 \
  filament/spatie-laravel-media-library-plugin:^5.10 \
  filament/spatie-laravel-settings-plugin:^5.10 \
  filament/spatie-laravel-tags-plugin:^5.10

# API docs (kept in require, not require-dev, if /docs/api is served in production)
composer require dedoc/scramble:~0.13.47

# Dev
composer require --dev pestphp/pest:^5.3 laravel/pint:^1.32 larastan/larastan:^3.12 laravel/pail:^1.2

# Filament panel scaffold (SPA mode is one call in the PanelProvider: ->spa(hasPrefetching: true))
php artisan filament:install --panels

# Minimal custom theme (required by Flowforge, see kanban section); needs Node 24 LTS
npm install && npm run build
```

Required PHP extensions (verify on the Zerops base image before phase 1 ends): `intl` (Filament), `bcmath` (Flowforge), `pdo_pgsql`, `mbstring`, `curl` (Stripe), `exif` + `fileinfo` (medialibrary), `gd` or `imagick` (image conversions, QR PNG), `opcache`.

## Decisions Requested by the Brief

### Queue driver: use the PostgreSQL `database` driver in v1

- Laravel 13's default `QUEUE_CONNECTION` is already `database`, and the driver pops jobs with `FOR UPDATE SKIP LOCKED` on PostgreSQL >= 9.5 (verified in `DatabaseQueue.php`), so multiple workers are safe.
- One instance, one company, low volume (invoice PDFs, emails, Stripe webhooks, daily CNB fetch). A Redis/Valkey service adds a moving part for no measurable gain.
- Jobs can be dispatched inside the same DB transaction as the business write. Set `'after_commit' => true` on the connection so a rolled-back invoice never enqueues an email.
- Use `cache` and `session` on `database` as well (shared across the web and worker containers; Zerops containers have an ephemeral filesystem). Rate limiting works on the database cache store.
- Run `php artisan queue:work --tries=3 --max-time=3600` as a separate Zerops worker service/process, and `schedule:run` via the Zerops `run.crontab` (`* * * * *`). Make jobs idempotent anyway (the brief already requires it).
- **Revisit trigger:** sustained > ~50 jobs/s, need for Horizon dashboards, or a measurable DB-load problem. Switching is an env change plus `QUEUE_CONNECTION=redis`; keep job classes driver-agnostic. Note that the Zerops Laravel recipe uses Valkey with `REDIS_CLIENT=predis`, so if Redis is adopted later, expect Predis rather than phpredis.
- Exempt `jobs`, `failed_jobs` (already has its own `uuid` column), `job_batches`, `cache`, `cache_locks`, `sessions`, `migrations` from "UUID v7 everywhere" except `sessions.user_id` (must become `foreignUuid`). They are framework-internal, not domain or morph tables.

### PDF driver: DOMPDF by default, behind spatie/laravel-pdf's driver switch

- Zero binaries: works on any Zerops PHP runtime without installing Node and Chromium (the Browsershot default needs both). That removes the largest deploy-risk item.
- Invoices and proformas are table-based, mostly single-page documents; DOMPDF's CSS 2.1 + tables is sufficient. Embed a DejaVu Sans (or similar) font via `font_dir` pointed at `storage_path('fonts')` to guarantee Czech diacritics.
- Known limits (from the package docs): no flexbox/grid, headers and footers are not repeated per page, no JS. The multi-page **work report** PDF is the only deliverable at risk (repeating header, page numbers).
- Mitigation: build both templates with tables from day one, and run a **phase-1 spike** rendering a 3-page report with diacritics and a QR. If it fails, switch only that document with `->driver('gotenberg')` (Gotenberg 8: open-source Docker image, MIT; needs a container service) or Browsershot. Because the driver is selectable per PDF, this is a config change, not a rewrite.
- Always generate via `saveQueued()` to the S3 disk, then attach the stored file to the email (snapshot semantics for issued invoices: generate once at issue time, store, never regenerate from mutable data).

### S3-compatible approach

- One private bucket, driven only by env: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` (use `us-east-1` when the provider ignores it), `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`. No provider-specific code, so self-hosters can use any S3 API (MinIO, R2, Backblaze B2, Hetzner, AWS).
- **Zerops:** its managed Object Storage is MinIO and **requires path-style URLs** (`AWS_USE_PATH_STYLE_ENDPOINT=true`). Bucket policy `private` is available. Container filesystems are replaced on every deploy, so nothing may rely on local disk.
- medialibrary config: `disk_name` = the S3 disk, `visibility` private, serve files only through `$media->getTemporaryUrl()` or an authorized streaming route that checks the Policy first (Partner must never receive a guessable URL). Use `MediaStream` (ZipStream, included in medialibrary) for the central "ZIP download".
- Early smoke test (phase 1): upload, temporary URL, delete, and a conversion against a real bucket. Newer AWS SDK versions send default CRC checksums that some S3-compatible stores reject; if uploads fail, set `request_checksum_calculation => 'when_required'` and `response_checksum_validation => 'when_required'` on the disk config (MEDIUM confidence, provider-dependent).

## UUID v7 Matrix (what works out of the box vs needs edits)

Laravel 13's `HasUuids` already generates **UUID v7** (`Str::uuid7()`), and `Blueprint` has `uuid()`, `foreignUuid()`, `uuidMorphs()`, `nullableUuidMorphs()` (verified in the 13.x sources). PostgreSQL 18 adds `uuidv7()` for DB-side defaults (use as `->default(DB::raw('uuidv7()'))` safety net for raw inserts and the manual import, while Eloquent supplies the value normally).

None of the packages publish UUID-ready migrations. **Every Spatie package that owns a table needs its published migration edited and a model subclass.** Do the edits before the first `migrate` (greenfield: no data migration pain).

| Package / table | Default stub | Required edit | Model/config change | Confidence |
|-----------------|--------------|---------------|---------------------|------------|
| Laravel `users`, domain tables | `id()` | `uuid('id')->primary()` | `HasUuids` on a shared base model/trait | HIGH |
| Laravel `sessions` | `foreignId('user_id')` | `foreignUuid('user_id')->nullable()->index()` | none | HIGH |
| Laravel/Filament `notifications` | `uuid('id')` (already) + `morphs('notifiable')` | `uuidMorphs('notifiable')` | none | HIGH |
| Sanctum `personal_access_tokens` | `id()` + `morphs('tokenable')` | `uuidMorphs('tokenable')`; id may stay bigint or become uuid | Optional custom token model with `HasUuids` via `Sanctum::usePersonalAccessTokenModel()`. Token strings are `id\|secret` and `findToken()` does `find($id)`, so string ids work. | MEDIUM |
| permission: `permissions`, `roles` | `id()` | `uuid('id')->primary()` | Custom `Role`/`Permission` extending the package models + `HasUuids`; register in `config/permission.php` `models`. The package resolves keys via `getKeyName()`, so the column may stay `id` (the official doc example renames it to `uuid` only for clarity). | MEDIUM |
| permission: `model_has_roles`, `model_has_permissions` | `unsignedBigInteger(model_morph_key)`, pivot ids `unsignedBigInteger` | `uuid('model_id')`, `uuid('role_id')`, `uuid('permission_id')`, plus `role_has_permissions` pivot ids as uuid; update the `foreign()->references('id')` accordingly | `column_names.model_morph_key` stays `model_id` (or `model_uuid`) | HIGH (documented in package `advanced-usage/uuid.md`) |
| medialibrary `media` | `id()` + `morphs('model')` + own `uuid()->nullable()->unique()` | `uuid('id')->primary()`, `uuidMorphs('model')` (the extra `uuid` column is separate and can stay) | Custom `Media extends BaseMedia` + `HasUuids`; `media-library.media_model`. Minor: `media:regenerate --starting-from-id` casts to `int`; use `--ids` or filter by model instead. | MEDIUM |
| tags `tags`, `taggables` | `id()`, `foreignId('tag_id')`, `morphs('taggable')` | `uuid('id')->primary()`, `foreignUuid('tag_id')`, `uuidMorphs('taggable')` (keep the unique index) | Custom `Tag extends Spatie\Tags\Tag` + `HasUuids`; `tags.tag_model` | MEDIUM |
| activitylog `activity_log` | `id()`, `nullableMorphs('subject')`, `nullableMorphs('causer')` | `uuid('id')->primary()`, `nullableUuidMorphs('subject','subject')`, `nullableUuidMorphs('causer','causer')`; keep `attribute_changes` and `properties` json | Custom `Activity` model + `HasUuids`; `activitylog.activity_model`. The package's own docs say to adjust `subject_id`/`causer_id` for UUIDs. | MEDIUM |
| webhook-client `webhook_calls` | `bigIncrements('id')` | `uuid('id')->primary()`; add a nullable unique `external_id` (the Stripe `evt_...`) for DB-enforced idempotence | Custom `WebhookCall` model (`HasUuids`, extra column set in `storeWebhook`); `webhook-client.configs.*.webhook_model` | MEDIUM |
| settings `settings` | `id()` | none (exempt) | none | HIGH |
| query-builder, data, eloquent-sortable, pdf | no tables | none | none | HIGH |
| Filament Import/Export actions (if used) | `foreignId('user_id')` | `foreignUuid('user_id')` | none | MEDIUM (only if the built-in actions are adopted; recommendation below avoids them) |

Cross-cutting rules:
- Call `Relation::enforceMorphMap([...])` with short aliases (`client`, `project`, `task`, `invoice`, ...) so `subject_type`/`model_type`/`taggable_type` never store class names. This also makes the Partner-isolation tests and later class renames safe.
- Package migrations that use `foreignId(...)->constrained()` will fail against uuid keys with a type mismatch error: that is the first symptom of a missed edit.
- Keep PostgreSQL `uuid` as the native column type everywhere (never `char(36)`), and index morph pairs `(model_type, model_id)` as the stubs already do.
- Cast route keys: Filament resources and Policies work with string keys out of the box; avoid any `(int)` casts or `->whereKey((int) ...)` in own code.

## Kanban drag-and-drop in Filament

**Recommendation: `relaticle/flowforge ^4.1`, with an early spike, fallback to a custom Livewire board.**

Why Flowforge: it is the only maintained kanban package with a Filament **5** release (4.1.4, last push 2026-10-06, MIT, PHP ^8.3, Livewire 4). The older `mokhosh/filament-kanban` (2.11.0) and `invaders-xx/filament-kanban-board` only support Filament 3. Source review shows it is key-agnostic: card ids travel as strings end to end, it uses `getKeyName()`/`whereKey()`, and the JS contains no `parseInt`. Moves run in a DB transaction with `lockForUpdate()` and then `$card->update()`, so model events (activity log, status-change rules) still fire. Positions are `DECIMAL(20,10)` (`flowforgePositionColumn()` macro) with automatic rebalancing when gaps shrink below 0.0001; it ships a `flowforge:repair-positions` command.

Caveats the roadmap must absorb:
1. **It requires a custom Filament theme** (Tailwind 4 `@source` entry for its views). The brief says "Custom theme: out of scope". Interpret that as "no visual customization": generate the stock theme (`make:filament-theme`), add the one `@source` line, and run `vite build` in CI/deploy. This introduces a Node build step in the pipeline, which is the real cost.
2. The position column is its own decimal rank, separate from `spatie/eloquent-sortable`. Use eloquent-sortable for todos/checklists and Flowforge ranks for kanban cards; do not try to unify them.
3. **Authorization is not built in.** Moves go through the board's query, so global scopes apply, but you must add your own check that only Admin can change status/position (Partner cannot change status per the brief). Simplest: register the board pages for Admin only and expose Partner a read-only list view.
4. Dragging writes `status`; if "done" requires side effects (stop timer, set `completed_at`), put them in a model observer/action called by an overridden move hook, not in the UI layer.

Fallback (if the spike shows UUID, Policy or theme problems): a custom Livewire page with Alpine's bundled `x-sort` plugin (SortableJS-style, shipped with Livewire's Alpine) calling `$wire.moveCard($id, $status, $afterId)` and a small plain-CSS asset registered via `FilamentAsset`. About 1-2 days of work; avoids the theme build.

## Filament 5 panel notes (SPA, one panel, two roles)

- Enable with `->spa(hasPrefetching: true)` in the `PanelProvider`; exclude non-SPA URLs (file downloads, PDF streams, the Stripe return URL) with `->spaUrlExceptions(fn (): array => [...])`. SPA mode uses Livewire `wire:navigate`.
- **Always-visible timer:** inject a Livewire component via a panel render hook (topbar). Treat the server as the source of truth (`started_at` in the DB, one running timer per user enforced by a partial unique index) and tick on the client with Alpine from that timestamp; with `wire:navigate` the topbar can be re-rendered, so re-hydrate from the DB, or wrap in `@persist`.
- One panel, two roles: gate panel access with `FilamentUser::canAccessPanel()`, and hide resources/pages via `canViewAny()`/Policies, but **enforce isolation in Eloquent global scopes and Policies** (as the brief says). Filament paths that bypass `Resource::getEloquentQuery()` include relationship selects, global search, widgets/charts and custom pages, so a scope on the model is the only reliable layer.
- Filament charts/widgets (Chart.js) cover the dashboard; no chart package needed.
- Keep Filament's built-in 2FA/MFA as an option for the Admin (it brings `pragmarx/google2fa` and `chillerlan/php-qrcode`, both MIT-compatible).

## Integration Notes

### ARES (Czech company registry) - verified live today

- Endpoint: `GET https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}` (REST, JSON, no API key, free). `{ico}` is exactly 8 digits; validate length and the mod-11 checksum locally before calling.
- Success (200) keys observed: `ico`, `icoId`, `obchodniJmeno` (name), `sidlo` (object: `nazevUlice`, `cisloDomovni`, `cisloOrientacni`, `nazevObce`, `psc`, `kodStatu`, `nazevStatu`, `textovaAdresa`, ...), `dic` (VAT ID, may be absent), `pravniForma` (code), `czNace`, `datumAktualizace`, `seznamRegistraci` (source states, e.g. `stavZdrojeDph`).
- Not found: HTTP **404** with JSON `{"kod":"NENALEZENO","popis":"...","subKod":"VYSTUP_SUBJEKT_NENALEZEN"}`. Map 404 to a form validation message, not an exception.
- Published fair-use guidance (MEDIUM): the Ministry may block clients above ~500 queries/min or repeating identical queries. A manual, user-triggered lookup is far below that.
- Implementation: a `Http::timeout(5)->retry(1, 200)` call wrapped in a small service returning a `spatie/laravel-data` DTO; short cache by ICO (e.g. 1 h) to absorb double clicks; on timeout or 5xx show "ARES unavailable, fill in manually" and never block saving. Do not use the legacy XML ARES interface.
- Use only fictional IDs in tests/fixtures; fake HTTP with `Http::fake()`; never commit recorded real responses.

### CNB daily exchange rates - verified live today

- Two official feeds, same data:
  - Text (legacy, stable): `https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=DD.MM.YYYY`. Line 1: `DD.MM.YYYY #<order>`; line 2 header `zeme|mena|mnozstvi|kod|kurz` (UTF-8 with Czech diacritics); then `Country|currency|amount|CODE|rate` with a **decimal comma** (e.g. `1|EUR|24,405` shape).
  - JSON (new REST): `https://api.cnb.cz/cnbapi/exrates/daily?date=YYYY-MM-DD&lang=EN` returns `{"rates":[{"validFor":"YYYY-MM-DD","order":N,"country":"...","currency":"...","amount":1,"currencyCode":"EUR","rate":24.405}]}`.
- **Recommendation:** primary = JSON feed (`lang=EN`), keyed by `currencyCode`; keep the TXT parser as a fallback. Parse `rate` as a string/decimal (CNB publishes 3 decimals; convert with `number_format($rate, 3, '.', '')` or `brick/math`, never float arithmetic) and store `numeric(18,6)` plus `amount`.
- **`amount` is not always 1** (observed 100 for some currencies, 1000 for others). Store `amount` and compute the per-unit rate as `rate / amount`.
- **Requesting a weekend or holiday date returns the last published day** (observed: a Sunday request returned `validFor` of the preceding Friday with its `order`). Always store by the response's `validFor` and dedupe on `(valid_for, currency_code)`, not on the requested date. This makes the daily job and backfill naturally idempotent.
- Rates are published on banking days in the afternoon Prague time (about 14:30); schedule the job at ~14:45 `Europe/Prague` on weekdays with retries (e.g. hourly to 17:00), and alert via the failure notification if no row exists for the latest banking day (MEDIUM: timing from CNB's published practice, not re-measured).
- CZK itself is not in the feed; treat it as rate 1.

### SPAYD QR payment

- Format (from the official qr-platba.cz spec): `SPD*1.0*ACC:<IBAN>[+<BIC>]*AM:<amount>*CC:<ISO4217>*X-VS:<digits>*MSG:<text>*...`. `ACC` is mandatory (IBAN, up to 46 chars, no spaces). `AM`: dot decimal, max 2 decimals, max 10 chars. `X-VS`/`X-KS`/`X-SS`: digits, max 10. `MSG`: max 60 chars, recommended uppercase ASCII subset (digits, A-Z, space, `$%*+-./:`); a literal `*` inside a value must be encoded `%2A`. `DT` = `YYYYMMDD`. Recommended QR error correction: **M**.
- The planned invoice number `{YYYY}{NNNN}` is 8 digits, so it fits `X-VS` exactly; use it as the variable symbol and as the payment-matching key for manual payments.
- **Recommendation: write a ~40-line `Spayd` value object** (own code, easy to unit-test against the spec) and render with `chillerlan/php-qrcode ^5` (SVG or PNG data URI embedded in the PDF). `rikudou/czqrpayment` (5.3.1, MIT) works but its last release is Dec 2024 and it targets PHP ^7.3|^8.0; it adds three transitive packages for a trivial string format. `endroid/qr-code 6.x` requires PHP ^8.4 and is a second QR stack next to the one Filament already installs.
- Show the QR only when the supplier has an IBAN and the invoice currency is one the account can receive (CZK, EUR via IBAN); strip diacritics from `MSG`.

### Stripe Payment Links + webhook (MEDIUM; details belong to the Stripe phase)

- Create links with `$stripe->paymentLinks->create([...])`. Metadata set on the Payment Link is **copied to the Checkout Sessions it spawns** (verified in Stripe docs), so put the invoice UUID in link `metadata` and read it from `checkout.session.completed`. Use `payment_intent_data[metadata]` as well if the PaymentIntent must carry it.
- Payment Links are reusable: set `restrictions.completed_sessions.limit = 1` for single-invoice links, deactivate (`active=false`) on payment, void or credit note, and still handle duplicates/unmatched payments defensively (the brief already requires it).
- Webhook: verify with `\Stripe\Webhook::constructEvent($rawBody, $sigHeader, $secret)` (default 5-minute tolerance, raw body required, CSRF exempt). Retries last up to 3 days; events may arrive duplicated and out of order. Dedupe on the Stripe event id with a **DB unique constraint** (the `external_id` column proposed above), return 2xx fast, and process in a queued job.
- Implement a custom `SignatureValidator` for `spatie/laravel-webhook-client` that calls `constructEvent`; its default validator is a generic HMAC and does not match Stripe's `t=...,v1=...` scheme.
- Pin the webhook endpoint's **API version** explicitly in the Stripe dashboard to the one the installed library expects (v22 pins `2026-09-30.endive`), so payload shapes match the SDK.
- Set `STRIPE_SUPPRESS_NOTICES=true` in CI/test environments (v22 can print notices in sandbox/test mode).

### XLSX export

- **Use `spatie/simple-excel ^3.10`** (OpenSpout 4.32 underneath): row-by-row streaming, constant memory, CSV and XLSX from the same code, ~5-line API. Fits the report exports (time per client/project, billable vs billed). Run exports above a row threshold in a queued job that writes to the S3 disk and notifies the user; small ones can stream directly.
- Avoid `maatwebsite/excel 4.0.3` (PhpSpreadsheet): heavy memory, features the app does not need.
- Filament's own `ExportAction` also uses OpenSpout 4 and works, but requires extra tables (`exports`, `export_failed_rows`) whose `user_id` columns need uuid edits and ties exports to Filament tables; skip it in v1 unless the Admin wants one-click table exports.
- **Never pin `openspout/openspout ^5`**: v5.x requires PHP ~8.4-8.6 and conflicts with Filament's `^4.23` constraint.
- Sanitize cell values that start with `=`, `+`, `-`, `@` (spreadsheet formula injection) when exporting client-supplied text.

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Laravel 13 + Filament 5 | Laravel 12 + Filament 4 | Only if a required plugin lacks a v5 release. None of the brief's packages do. |
| `database` queue | Redis/Valkey + Horizon | Sustained high job volume, need for Horizon metrics, or latency under ~1 s for emails. Zerops offers Valkey 7.2 (Predis client required). |
| DOMPDF driver | Gotenberg 8 (separate container) | Multi-page reports needing repeating headers/footers or modern CSS, when the host can run a Docker service. Per-document switch. |
| DOMPDF driver | Browsershot (Node + Chromium) | Pixel-perfect modern CSS; accept the heavy runtime install and slower cold starts. |
| DOMPDF driver | WeasyPrint (Python binary) | Best CSS Paged Media output (page counters, running headers) if a Python binary is acceptable. |
| Scramble | `darkaonline/l5-swagger` 11.1 (swagger-php attributes) or `knuckleswtf/scribe` 5.11 | If you want hand-authored, annotation-first specs or Postman collections. Scramble infers from FormRequests/Resources with no annotations, which suits a small `/api/v1` surface. |
| `spatie/simple-excel` | Filament `ExportAction` | One-click export of Filament tables with column picker. |
| Flowforge | Custom Livewire + Alpine `x-sort` board | If the theme build or Policy integration is unacceptable (about 1-2 days). |
| Own SPAYD builder | `rikudou/czqrpayment` | If you prefer a pre-built library and accept an unmaintained-looking dependency chain. |
| JSON CNB feed | TXT feed | Fallback parser; both carry identical data. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `spatie/laravel-stripe-webhooks` 3.11.1 | Allows `stripe/stripe-php` only up to `^21`, so it conflicts with `^22` today | `spatie/laravel-webhook-client` + custom validator (as in the brief), or pin stripe-php `^21.3` |
| `openspout/openspout ^5` | Conflicts with Filament's `^4.23` | Let Filament and simple-excel pull `^4.x` |
| `chillerlan/php-qrcode ^6` | Filament 5 requires `^5.0` | `^5.0` |
| `filament/spatie-laravel-translatable-plugin` | No Filament 5 line (3.3.x); app is single-locale | `lang/cs` files; `spatie/laravel-translatable` is still pulled by laravel-tags (fine) |
| Spatie `laravel-medialibrary-pro`, Scramble Pro, any paid Filament plugin | Paid/closed; violates "no paid or closed packages" | Free core packages listed above |
| Legacy ARES XML interface, `wwwinfo.mfcr.cz` | Superseded by the REST API | The `ekonomicke-subjekty-v-be` REST endpoint |
| Float arithmetic for CNB rates and money | Rounding drift | bigint minor units; `brick/math` (already a Laravel dependency) for rate conversion |
| Using `morphs()`/`foreignId()` from package stubs unmodified | Type mismatch against uuid keys | See the UUID matrix |
| `filament-shield` (bezhansalleh) | Overkill for two fixed roles | Plain Policies + spatie roles seeded from an enum |
| MinIO as a Composer/dev dependency choice | MinIO server itself is AGPL-3.0 (compatible, but it is infrastructure, not a library) | Use it only as the S3 backend via the standard SDK |

## Stack Patterns by Variant

**If the deploy target is Zerops (planned):**
- `php-nginx@8.5` for web, a second PHP runtime (same repo, `setup: worker`) running `php artisan queue:work`, `postgresql@18`, managed Object Storage (MinIO, path-style, private policy), crontab for `schedule:run`.
- Because the filesystem is replaced on deploy: all uploads and generated PDFs go to S3, `storage/` is ephemeral, sessions/cache in the DB.

**If self-hosters have no S3:**
- Keep the same `s3` disk contract and document MinIO via Docker Compose; do not add a `local` fallback for documents (private-URL logic differs).

**If PDF layout needs exceed DOMPDF:**
- Add a Gotenberg service and set `LARAVEL_PDF_DRIVER=gotenberg` + `GOTENBERG_URL`; nothing else changes.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| Laravel 13.35 | PHP 8.3-8.5 | Official support matrix. Uses `symfony/*` 7.4/8.x. |
| Filament 5.10 | Livewire `^4.4.7`, Laravel `^11.28\|12\|13`, PHP `^8.2`, `ext-intl` | Tailwind v4.1+ only matters for custom themes. Upgrade script `filament-v5` exists but is irrelevant for greenfield. |
| spatie/laravel-activitylog 5.1 | PHP **^8.4**, Laravel 12-13 | Breaking vs v4: batches removed; `attribute_changes` column added; `activities` relation renamed `activitiesAsSubject`/`activitiesAsCauser`; `tapActivity` -> `beforeActivityLogged`; `withoutLogs` -> `withoutLogging`; `CauserResolver` -> `Activity::defaultCauser()`. Do not copy v4 tutorials. |
| spatie/laravel-permission 8.3 | PHP ^8.3, Laravel 12-13 | v8 changed `Role`/`Permission` contract signatures (`findByName`/`findOrCreate` accept `BackedEnum\|string`): only matters for custom subclasses. Backed-enum role/permission names are supported, handy for an Admin/Partner enum. |
| spatie/laravel-query-builder 7.3 | PHP ^8.3, Laravel 12-13 | |
| spatie/laravel-medialibrary 11.23 | Laravel 10-13, `spatie/image ^3`, `maennchen/zipstream-php ^3.1` | Needs `exif`, `fileinfo`; GD or Imagick for conversions. |
| spatie/laravel-tags 4.12 | Laravel 10-13, `spatie/eloquent-sortable ^4\|^5`, `spatie/laravel-translatable ^6` | Tag names/slugs are JSON (translatable) columns; fine for `cs` only. |
| spatie/laravel-pdf 2.14 | Laravel 11-13 | DOMPDF driver needs `dompdf/dompdf ^3`. |
| spatie/laravel-webhook-client 3.7 | Laravel 9-13 | |
| laravel/sanctum 4.3 | Laravel 11-13 | |
| stripe/stripe-php 22.0 | PHP >= 7.4 (fine on 8.5), `ext-curl` | `spatie/laravel-stripe-webhooks` not yet compatible. Dropped PHP 7.2/7.3 and changed a few request-param shapes only. |
| relaticle/flowforge 4.1 | Filament `^5.0`, PHP ^8.3, `ext-bcmath`, Laravel 12+ | Needs a custom Filament theme to compile its views. |
| openspout 4.32 | PHP 8.3-8.5 | Pulled by Filament actions (`^4.23`) and simple-excel (`^4.30`). |
| Pest 5.3 | PHP ^8.4 | Fine; the project is on 8.5. |
| PostgreSQL 18 | Laravel 13 | `uuidv7()` available server-side; the database queue uses `SKIP LOCKED`. |

## Licence Audit (AGPL-3.0 compatibility)

No AGPL-incompatible licence found among the recommended packages. Read from Packagist metadata and `composer.json` files on 2026-10-06.

| Component | Licence | Verdict |
|-----------|---------|---------|
| Laravel, Sanctum, Livewire, Filament (+ its plugins), all `spatie/*` packages above, `stripe/stripe-php`, `dedoc/scramble`, `relaticle/flowforge`, `openspout`, `league/flysystem*`, `pragmarx/google2fa`, Pest, Pint, Larastan | MIT | Compatible |
| `chillerlan/php-qrcode` | MIT (v5) / MIT or Apache-2.0 (v6) | Compatible (Apache-2.0 is compatible with GPLv3/AGPLv3) |
| `bacon/bacon-qr-code` (if `endroid/qr-code` were used) | BSD-2-Clause | Compatible |
| `dompdf/dompdf` 3.1.6 | LGPL-2.1 (its `php-svg-lib` is LGPL-3.0, `php-font-lib` LGPL-2.1+) | Compatible when used as an unmodified library via Composer; add a line to the README/NOTICE listing it. LGPL-2.1 section 3 allows relicensing a copy under GPL, and GPLv3/AGPLv3 section 13 covers the combination. |
| Scramble UI (Stoplight Elements) | Apache-2.0 (MEDIUM: not re-verified in the package bundle) | Compatible with AGPL-3.0 (not with GPL-2.0-only) |
| Browsershot / Puppeteer / Chromium, Gotenberg, WeasyPrint | MIT / Apache-2.0 / BSD / MIT / BSD-3 | Compatible (only relevant if the driver is switched) |
| MinIO (Zerops S3 backend) | AGPL-3.0 | Not a dependency of the codebase; infrastructure only. |

**Flag (paid/closed, must not be added):** Spatie Medialibrary Pro, Scramble Pro, Filament paid/marketplace plugins, Laravel Nova, Spark, Livewire Flux Pro. Add a CI step running `composer licenses --format=json` with an allowlist so a transitive licence change fails the build.

## Open Gaps (carry into phase research)

- **Zerops PHP image contents:** php@8.5 and postgresql@18 are confirmed; the preinstalled extension list (`intl`, `bcmath`, `gd`/`imagick`, `exif`, `pdo_pgsql`) and how to add extensions were not found in the public docs. Verify in phase 1; this decides DOMPDF fonts/QR PNG vs SVG and Flowforge's `bcmath` requirement.
- **Zerops crontab/worker details:** syntax confirmed (`run.crontab`), but overlap/timeouts for `schedule:run` and the worker restart behaviour on deploy are untested.
- **DOMPDF multi-page work report:** needs a phase-1 spike (see PDF section).
- **Flowforge + UUID + Policy:** source review says it should work with string ids; no UUID test in its docs. Spike with a UUID v7 `tasks` table, a Partner Policy and a global scope before committing.
- **S3 checksum behaviour against the chosen provider:** smoke test early.
- **Scramble coverage of spatie/laravel-query-builder parameters and Sanctum bearer scheme:** confirm the generated spec documents filters, abilities and 429 rate-limit responses; otherwise add a small custom extension/annotations. Also define the `viewApiDocs` gate so the docs are Admin-only (or intentionally public) in production.
- **spatie/laravel-activitylog v5 + Filament:** `rmsramos/activitylog` 4.1 declares support, but UI/relation naming changed in v5; decide between that plugin and a simple relation manager.

## Sources

- Packagist p2 metadata (versions, PHP/Laravel/Filament constraints, licences, release dates), fetched 2026-10-06 for: laravel/framework, filament/*, laravel/sanctum, every spatie package in scope, stripe/stripe-php, dedoc/scramble, spatie/simple-excel, openspout/openspout, maatwebsite/excel, rikudou/czqrpayment, chillerlan/php-qrcode, endroid/qr-code, relaticle/flowforge, rmsramos/activitylog, pest/pint/larastan - HIGH
- Spatie package sources cloned from GitHub (migrations stubs, config, docs, `UPGRADING.md`): laravel-permission (`docs/advanced-usage/uuid.md`, `docs/upgrading.md`), laravel-activitylog (`UPGRADING.md`), laravel-medialibrary, laravel-tags, laravel-webhook-client, laravel-settings, laravel-pdf (`docs/requirements.md`, `docs/drivers/*`) - HIGH
- Laravel 13 release notes and support policy: https://laravel.com/docs/13.x/releases - HIGH
- Laravel 13.x framework sources (`HasUuids` uses `Str::uuid7()`, `Blueprint::uuidMorphs`, `DatabaseQueue::getLockForPopping`, default `QUEUE_CONNECTION=database`): https://github.com/laravel/framework/tree/13.x - HIGH
- Laravel Sanctum `PersonalAccessToken::findToken` and migration stub: https://github.com/laravel/sanctum/tree/4.x - HIGH
- Filament 5 installation/upgrade docs: https://filamentphp.com/docs/5.x/introduction/installation, https://filamentphp.com/docs/5.x/upgrade-guide - HIGH. SPA mode (`->spa()`, `->spaUrlExceptions()`): Filament panel configuration docs via web search - MEDIUM
- Flowforge repository (README, docs, source, CHANGELOG, `composer.json`): https://github.com/Relaticle/flowforge - HIGH for facts read from source
- PostgreSQL versioning and `uuidv7()`: https://www.postgresql.org/support/versioning/, https://www.postgresql.org/docs/18/functions-uuid.html - HIGH
- Stripe docs: https://docs.stripe.com/api/payment-link/create, https://docs.stripe.com/webhooks; stripe-php 22.0.0 changelog https://github.com/stripe/stripe-php/blob/master/CHANGELOG.md - HIGH
- Scramble docs (routes, `viewApiDocs` gate): https://scramble.dedoc.co/usage/getting-started - MEDIUM
- ARES REST API: live calls to `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}` (200 and 404 shapes) - HIGH; rate-limit text from Ministry of Finance ARES pages (https://mf.gov.cz/cs/ministerstvo/informacni-systemy/ares) - MEDIUM
- CNB feeds: live calls to the `denni_kurz.txt` and `https://api.cnb.cz/cnbapi/exrates/daily` endpoints, including a weekend date - HIGH; publication-time guidance - MEDIUM
- SPAYD specification: https://qr-platba.cz/pro-vyvojare/specifikace-formatu/ - HIGH
- Zerops: https://docs.zerops.io/zerops-yaml/base-list (php@8.5), https://docs.zerops.io/guides/object-storage-integration (MinIO, path-style, private policy), https://app.zerops.io/recipes/laravel-showcase.md (postgresql@18, Valkey, Predis, worker command), https://docs.zerops.io/zerops-yaml/specification (crontab) - MEDIUM
- Licence notes for LGPL/Apache combinations are general knowledge of FSF compatibility guidance, not legal advice - MEDIUM

---
*Stack research for: single-tenant CRM/ERP (Laravel 13, Filament 5, PostgreSQL 18, UUID v7)*
*Researched: 2026-10-06*
