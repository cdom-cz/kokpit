---
phase: 02-platform-foundation
reviewed: 2026-10-07T00:00:00Z
depth: standard
files_reviewed: 188
files_reviewed_list:
  - .ddev/addon-metadata/redis/manifest.yaml
  - .ddev/config.yaml
  - .ddev/docker-compose.redis.yaml
  - .ddev/docker-compose.rustfs.yaml
  - .ddev/redis/redis.conf
  - .editorconfig
  - .env.example
  - .gitattributes
  - .github/dependabot.yml
  - .github/workflows/hygiene.yml
  - .gitignore
  - CONTRIBUTING.md
  - README.md
  - SECURITY.md
  - app/Console/Commands/InstallCommand.php
  - app/Console/Commands/ResetAdminTwoFactorCommand.php
  - app/Domain/Identity/Models/Permission.php
  - app/Domain/Identity/Models/PersonalAccessToken.php
  - app/Domain/Identity/Models/Role.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Identity/RoleName.php
  - app/Domain/Shared/Auth/AccessRule.php
  - app/Domain/Shared/Auth/AccessRules.php
  - app/Domain/Shared/Auth/Audience.php
  - app/Domain/Shared/Auth/DeniesPartners.php
  - app/Domain/Shared/Auth/IsolatesPartners.php
  - app/Domain/Shared/Auth/KokpitPolicy.php
  - app/Domain/Shared/Auth/NotPartnerScoped.php
  - app/Domain/Shared/Auth/PartnerContext.php
  - app/Domain/Shared/Auth/PartnerIsolated.php
  - app/Domain/Shared/Auth/PartnerScope.php
  - app/Domain/Shared/Database/Immutability.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Models/Activity.php
  - app/Domain/Shared/Models/KokpitModel.php
  - app/Domain/Shared/Models/Media.php
  - app/Domain/Shared/Models/Tag.php
  - app/Domain/Shared/Models/WebhookCall.php
  - app/Domain/Shared/Money/Money.php
  - app/Domain/Shared/Money/MoneyCast.php
  - app/Domain/Shared/Policies/AdminOnlyPolicy.php
  - app/Domain/Shared/Sequences/SequenceAllocator.php
  - app/Filament/Concerns/EnforcesPageAccessRule.php
  - app/Filament/Concerns/EnforcesRelationManagerAccessRule.php
  - app/Filament/Concerns/EnforcesResourceAccessRule.php
  - app/Filament/Concerns/EnforcesWidgetAccessRule.php
  - app/Filament/Pages/Dashboard.php
  - app/Http/Controllers/Controller.php
  - app/Http/Middleware/EnsureAdminHasTwoFactor.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/LocalisationServiceProvider.php
  - app/Providers/ModelConventionsServiceProvider.php
  - app/Support/InitialsAvatarProvider.php
  - app/Support/ProductionConfigGuard.php
  - artisan
  - bootstrap/app.php
  - bootstrap/cache/.gitignore
  - bootstrap/providers.php
  - composer.json
  - config/activitylog.php
  - config/app.php
  - config/auth.php
  - config/cache.php
  - config/database.php
  - config/filesystems.php
  - config/kokpit.php
  - config/logging.php
  - config/mail.php
  - config/media-library.php
  - config/permission.php
  - config/queue.php
  - config/services.php
  - config/session.php
  - config/tags.php
  - config/webhook-client.php
  - database/.gitignore
  - database/factories/UserFactory.php
  - database/migrations/0001_01_01_000000_create_users_table.php
  - database/migrations/0001_01_01_000002_create_jobs_table.php
  - database/migrations/2026_10_07_000100_create_number_sequences_table.php
  - database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php
  - database/migrations/2026_10_07_151401_create_permission_tables.php
  - database/migrations/2026_10_07_151910_create_personal_access_tokens_table.php
  - database/migrations/2026_10_07_151911_create_notifications_table.php
  - database/migrations/2026_10_07_152542_create_media_table.php
  - database/migrations/2026_10_07_152543_create_tag_tables.php
  - database/migrations/2026_10_07_152629_create_activity_log_table.php
  - database/migrations/2026_10_07_152631_create_webhook_calls_table.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/RoleSeeder.php
  - lang/cs.json
  - lang/cs/actions.php
  - lang/cs/auth.php
  - lang/cs/enums.php
  - lang/cs/http-statuses.php
  - lang/cs/kokpit.php
  - lang/cs/pagination.php
  - lang/cs/passwords.php
  - lang/cs/validation.php
  - phpstan.neon
  - phpunit.xml
  - pint.json
  - public/.htaccess
  - public/favicon.ico
  - public/index.php
  - public/robots.txt
  - routes/console.php
  - routes/web.php
  - scripts/boot-from-env-example.sh
  - scripts/check-licenses.php
  - scripts/sensitive-allowlist.txt
  - scripts/tests/test-docs.sh
  - scripts/tests/test-gitignore.sh
  - scripts/tests/test-workflow.sh
  - storage/app/.gitignore
  - storage/app/private/.gitignore
  - storage/app/public/.gitignore
  - storage/framework/.gitignore
  - storage/framework/cache/.gitignore
  - storage/framework/cache/data/.gitignore
  - storage/framework/cache/locks/.gitignore
  - storage/framework/sessions/.gitignore
  - storage/framework/testing/.gitignore
  - storage/framework/views/.gitignore
  - storage/logs/.gitignore
  - tests/Arch/ModelDeclarationTest.php
  - tests/Arch/MoneyBoundaryTest.php
  - tests/Arch/PanelRegistryTest.php
  - tests/Arch/QueryEscapeHatchTest.php
  - tests/Concurrency/SequenceAllocatorConcurrencyTest.php
  - tests/Concurrency/worker.php
  - tests/Feature/Auth/InstallCommandTest.php
  - tests/Feature/Auth/ResetTwoFactorCommandTest.php
  - tests/Feature/Auth/TwoFactorEnforcementTest.php
  - tests/Feature/Boot/PanelBootTest.php
  - tests/Feature/Database/ImmutabilityPilotTest.php
  - tests/Feature/Localisation/EnumLabelsTest.php
  - tests/Feature/Localisation/FormatsTest.php
  - tests/Feature/Localisation/LangEncodingTest.php
  - tests/Feature/Localisation/LocalisationTest.php
  - tests/Feature/Money/MoneyCastTest.php
  - tests/Feature/Repo/CiParityTest.php
  - tests/Feature/Repo/DdevConfigTest.php
  - tests/Feature/Repo/EnvExampleTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/KeysAndTimestampsTest.php
  - tests/Feature/Schema/MorphMapTest.php
  - tests/Feature/Schema/PackageModelsTest.php
  - tests/Feature/Schema/SchemaConventionsTest.php
  - tests/Feature/Sequences/SequenceAllocatorTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/DeniedModelsTest.php
  - tests/Isolation/FailClosedScopeTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PolicyBaseTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Isolation/SystemRunCommandsTest.php
  - tests/Pest.php
  - tests/Support/Canary.php
  - tests/Support/CanaryRecord.php
  - tests/Support/CanaryRecordPolicy.php
  - tests/Support/CanaryRegistry.php
  - tests/Support/EscapeHatchScanner.php
  - tests/Support/Filament/CanaryRecordResource.php
  - tests/Support/Filament/CanaryRecordResource/Pages/ListCanaryRecords.php
  - tests/Support/Filament/CanaryRecordResource/Pages/ViewCanaryRecord.php
  - tests/Support/Filament/Fixtures/UndeclaredPage.php
  - tests/Support/Fixtures/TranslatedFixtureEnum.php
  - tests/Support/Fixtures/UntranslatedFixtureEnum.php
  - tests/Support/Localisation/EnumLabelChecker.php
  - tests/Support/ModelDeclaration.php
  - tests/Support/ModelRules.php
  - tests/Support/PgSchema.php
  - tests/Support/Probes/MoneyProbe.php
  - tests/Support/Probes/PackageProbe.php
  - tests/Support/Probes/ProbeNotification.php
  - tests/Support/Probes/UnmappedProbe.php
  - tests/Support/RawSql.php
  - tests/Support/UnlockedSequenceAllocator.php
  - tests/Support/Uuids.php
  - tests/TestCase.php
  - tests/Unit/Database/ImmutabilityTest.php
  - tests/Unit/LicenceCheckTest.php
  - tests/Unit/Money/MoneyTest.php
  - tests/Unit/Support/InitialsAvatarProviderTest.php
  - tests/Unit/Support/ProductionConfigGuardTest.php
findings:
  critical: 0
  warning: 10
  info: 8
  total: 18
status: issues_found
---

# Phase 02: Code Review Report

**Reviewed:** 2026-10-07
**Depth:** standard
**Files Reviewed:** 188
**Status:** issues_found

## Summary

The core isolation design holds up under adversarial reading. The access rules fail closed and the Admin rule lives in `KokpitPolicy::before()` only. `PartnerScope` falls back to `1 = 0` for every state that is not clearly Admin, system or a Partner with a client. The sequence allocator is correct under READ COMMITTED. The immutability trigger and the identifier validation are sound. The mandatory Admin TOTP is enforced per request, and the 2FA reset command writes no PII to its log. I found no data-leak or authentication-bypass path in the data layer or the panel as built today. That is why there are no Critical findings.

The weaknesses sit in three places:

1. **Guard rails are narrower than they claim.** The escape-hatch scanner and the model-declaration scan promise "no model without an isolation decision" and "no read around the scope", but each covers only part of the surface.
2. **Production defaults do not match the stated constraints.** Examples are the media library disk, the session cookie `Secure` flag and the production-only config guard.
3. **The fail-closed scope has silent failure modes** for package maintenance commands that run without a user.

The orchestrator already recorded these items, so they are not re-reported: the spatie `Gate::before`, `Color::Amber`, no Admin password recovery, and the `Tests` class named in `AdminPanelProvider`.

## Warnings

### WR-01: Escape-hatch scanner misses most ways around PartnerScope, yet the test claims to prove there are none

**File:** `tests/Support/EscapeHatchScanner.php:40-60` and `tests/Arch/QueryEscapeHatchTest.php:60-70`
**Issue:** The scanner flags exactly two token shapes: `DB::table(` and `withoutGlobalScopes()` with an empty argument list. The test docblock and the allowlist comment present this as the gate against reading around the fail-closed scopes.

These all pass the scan unnoticed:
- `->withoutGlobalScope(PartnerScope::class)` (singular).
- `->withoutGlobalScopes([PartnerScope::class])`. The test "does not report withoutGlobalScopes with an argument" explicitly blesses any argument, although the `PartnerScope::class` argument is the one that matters.
- `Model::newQueryWithoutScopes()` and `newQueryWithoutScope(...)`.
- `DB::select/selectOne/statement/unprepared(...)` with raw SQL on tenant tables.
- `DB::connection()->table(...)` and an aliased import (`use ...\DB as Database`).

A later phase can leak another client's rows with a one-line change that the architecture test keeps green.

**Fix:** Extend the scanner to flag the following:
- `withoutGlobalScope`, `newQueryWithoutScope(s)` and `DB::select|selectOne|statement|unprepared|insert|update|delete`.
- `->table(` on a `DB::connection(...)` chain.
- Any `withoutGlobalScopes` call whose argument mentions `PartnerScope`.

Move the "argument is fine" case to "argument not naming PartnerScope is fine", and add one scanner test per new pattern. Until then, soften the docblock so it does not claim completeness.

### WR-02: Model-declaration scan only sees `app/Domain/**/Models/`, so a model elsewhere escapes the "no model without an isolation decision" rule

**File:** `tests/Support/ModelDeclaration.php:21-50` (the `/Models/` path filter at lines 31-35), consumed by `tests/Arch/ModelDeclarationTest.php:38-60` and `tests/Isolation/CanaryRegistryTest.php:36-45`
**Issue:** `appModels()` iterates `app/Domain` and keeps only paths containing `/Models/`. A model in Laravel's default `app/Models/`, or in `app/Domain/Billing/Invoice.php` (no `Models` directory), is invisible. It needs neither `PartnerIsolated` nor `#[NotPartnerScoped]` and needs no canary fixture. `KokpitModel`'s docblock says the arch test enforces the declaration for every model. The test "finds every model of the application" pins an exact list, but only of what the scan can see.

**Fix:** Scan all of `app/` for concrete `Model` subclasses, or get the list from `get_declared_classes()` after autoloading the PSR-4 map with `is_subclass_of(Model::class)`. Add a second assertion that fails when a `Model` subclass file exists under `app/` outside the scanned roots.

### WR-03: Media library defaults to the public disk, contradicting the private-bucket constraint

**File:** `config/media-library.php:38` (with `config/filesystems.php:43-50`)
**Issue:** `'disk_name' => env('MEDIA_DISK', 'public')` and `.env.example` does not set `MEDIA_DISK`. Every file added through the media library (invoice PDFs and attachments later) lands on the `public` disk, which is served from `/storage` after `storage:link` and is world-readable by URL. CLAUDE.md requires an S3-compatible private bucket. The Admin-only policy and scope protect the database row, not the file URL. A new deployment that forgets the variable silently publishes client documents.

**Fix:** Default to a private disk and document the variable.
```php
'disk_name' => env('MEDIA_DISK', 's3'),            // or 'local' (storage/app/private) in development
```
Add `MEDIA_DISK=s3` to `.env.example` (RustFS is already configured there). Add a test that `config('media-library.disk_name')` is not a disk with `visibility => public`.

### WR-04: Fail-closed scope silently turns package maintenance commands into no-ops

**File:** `app/Domain/Shared/Auth/PartnerScope.php:29-41`, with `config/activitylog.php:20` and `config/webhook-client.php:84`
**Issue:** `PartnerScope` returns `WHERE 1 = 0` for any caller that is not an Admin, not a Partner and not inside `runAsSystem()`. Only the two Kokpit commands wrap themselves in `runAsSystem()`. The packages' own maintenance paths do not:
- `activitylog:clean` runs `Activity::query()->where('created_at', '<', ...)->delete()` (verified in `CleanActivityLogAction`). The scope makes that `... AND 1 = 0`, so it deletes nothing and reports success.
- `model:prune` for `WebhookCall` (30-day retention, which may hold personal data in payloads) is the same.

The configured retention (`clean_after_days = 365`, `delete_after_days = 30`) is therefore never applied, and nothing signals it. Models restored by `SerializesModels` are not affected (`newQueryForRestoration` bypasses scopes), but any job or command that queries by itself is.

**Fix:** Run console and queue work as system by default, not per command. For example, in `AccessServiceProvider::boot()`, listen to `CommandStarting` and `JobProcessing` and enter system mode (reset on `CommandFinished` and `JobProcessed`/`JobFailed`). Alternatively, schedule the maintenance commands through a wrapper command that uses `runAsSystem()`. Add a test that `activitylog:clean` removes an old row while a Partner is not signed in.

### WR-05: Session cookie has no `Secure` flag unless an env variable is set, and nothing enforces it in production

**File:** `config/session.php:174` (with `.env.example:82`, `app/Support/ProductionConfigGuard.php`)
**Issue:** `'secure' => env('SESSION_SECURE_COOKIE')` resolves to null. Laravel then emits the session cookie without the `Secure` attribute, and `SESSION_SECURE_COOKIE` appears only as a commented-out line in `.env.example`. `ProductionConfigGuard` checks 2FA and the canary switch but not this. The panel carries a CRM admin session (and a Partner's). A production deployment that forgets the variable sends the session cookie over any plain-HTTP request. No `trustProxies` is configured in `bootstrap/app.php`, so behind a TLS-terminating proxy Laravel also treats requests as insecure (mixed-content asset URLs, wrong `https` detection).

**Fix:**
```php
'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production'),
```
Add `->trustProxies(at: env('TRUSTED_PROXIES'))` to the middleware config and document it, or add `session.secure === true` to `ProductionConfigGuard`.

### WR-06: `ProductionConfigGuard` only runs when `APP_ENV` is exactly `production`

**File:** `app/Support/ProductionConfigGuard.php:18`, `app/Providers/AppServiceProvider.php:26`
**Issue:** The guard returns early unless `isProduction()` is true. A public instance run with `APP_ENV=staging`, `prod`, `live` or any custom name skips the guard completely, so `KOKPIT_REQUIRE_ADMIN_2FA=false` or `KOKPIT_CANARY_HARNESS=true` boots on an internet-facing server. `Model::preventSilentlyDiscardingAttributes` has the same inverted shape: it is off for everything but `production`. The safe direction is to allow the dev-only switches in `local` and `testing` only.

**Fix:**
```php
$devOnly = $app->environment(['local', 'testing']);
ProductionConfigGuard::check(! $devOnly, config());
```
Keep the existing unit tests (the first argument stays a boolean "strict mode") and add one for `staging`.

### WR-07: E-mail identity is case-sensitive in the database, while the 2FA reset command matches case-insensitively and takes the first hit

**File:** `database/migrations/0001_01_01_000000_create_users_table.php:20`, `app/Console/Commands/ResetAdminTwoFactorCommand.php:40`, `app/Console/Commands/InstallCommand.php:78-84`
**Issue:** `users.email` has a plain `unique()` index, which is case-sensitive in PostgreSQL. `InstallCommand` lowercases, but later Partner accounts created through the panel or seeders are not guaranteed to be. Two rows `Jane@example.com` and `jane@example.com` can then coexist. `kokpit:admin:reset-2fa` does `whereRaw('lower(email) = ?')->first()` with no ordering, so with such a pair it clears the TOTP secret and recovery codes of whichever row the planner returns first. That is a security-relevant action on a nondeterministic target. Filament login compares the typed address exactly, so a user who types a different case gets a confusing "wrong credentials".

**Fix:** Enforce one identity per address in the database and look up the same way.
```php
DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
```
Normalise `email` to lower case in a `User` mutator. Make the reset command fail when `lower(email)` matches more than one row (`->limit(2)->get()` and check the count).

### WR-08: 2FA reset leaves existing sessions and remember tokens valid

**File:** `app/Console/Commands/ResetAdminTwoFactorCommand.php:63-68`
**Issue:** The command is the documented break-glass path for a lost device. It clears the secret and the recovery codes but does not rotate `remember_token`, and it cannot touch sessions. Sessions are stored in Redis and `AuthenticateSession` only invalidates when the password hash changes. If the reason for the reset is a stolen or lost device that was signed in, that session survives the reset (the middleware then merely sends it to the set-up page, where the holder can enrol their own authenticator). The README presents the command as the recovery from a lost device without mentioning this.

**Fix:** In the command, set a new `remember_token` (`Str::random(60)`). Either move sessions to a store that can be enumerated by user, or add a per-user session-version attribute checked in a custom middleware. At minimum, state in the README and the command output that existing sessions stay valid and that a password change invalidates them.

### WR-09: Redis queue connection dispatches inside open transactions

**File:** `config/queue.php:44`
**Issue:** `'after_commit' => false` on the redis connection. The architecture is explicitly transactional (number allocation inside the caller's transaction, immutability on issue). A job dispatched inside such a transaction (for example "send invoice mail" after issuing) can start before the commit and not find the row. After a rollback it will still run, against a number or state that was given back. The same applies to `media-library.queue_conversions_after_database_commit`, which is already true, so the two settings are inconsistent.

**Fix:** Set `'after_commit' => true` for the redis connection and keep the media setting.

### WR-10: `Money::convert` accepts a zero or negative exchange rate

**File:** `app/Domain/Shared/Money/Money.php:166`
**Issue:** The pattern `^-?\d+(\.\d{1,10})?$` allows a sign, and `0.0000000000` passes. The docblock describes the rate as "digits with an optional point and at most ten fraction digits", with no sign, and an exchange rate is strictly positive. A rate of zero silently converts any amount to 0 minor units of the target currency (and a negative rate yields a negative amount). For invoice snapshots this produces a wrong, plausible-looking number instead of an error. The tests cover malformed strings, but not zero or negative.

**Fix:**
```php
if (preg_match('/^\d+(\.\d{1,10})?$/D', $decimalRate) !== 1 || BigDecimal::of($decimalRate)->isZero()) {
    throw new InvalidArgumentException('The exchange rate must be a positive decimal with at most ten fraction digits.');
}
```
Add unit cases for `'0'`, `'0.0000000000'` and `'-1'`.

## Info

### IN-01: Migrations without `down()`

**File:** `database/migrations/2026_10_07_000100_create_number_sequences_table.php:19-35`, `2026_10_07_152542_create_media_table.php:10-36`, `2026_10_07_152629_create_activity_log_table.php:10-26`, `2026_10_07_152631_create_webhook_calls_table.php:10-29`
**Issue:** Four migrations define only `up()`. `migrate:rollback` / `migrate:fresh --step` then marks them rolled back without dropping the tables, and a re-run fails with "relation already exists". All the other migrations define `down()`.
**Fix:** Add `Schema::dropIfExists(...)` (for `number_sequences`, `media`, `activity_log`, `webhook_calls`).

### IN-02: Redis eviction policy can silently drop queue jobs and sessions

**File:** `.ddev/redis/redis.conf:7-8`
**Issue:** `maxmemory-policy allkeys-lfu` is the DDEV add-on default, but `.env.example` and the README make Redis carry the queue, the sessions and the cache. Under memory pressure Redis may evict queued jobs (data loss) and sessions. It is harmless at development scale, but it is the example that production setups copy.
**Fix:** Document in CONTRIBUTING (or in `config/queue.php`) that production Redis for the queue and sessions needs `maxmemory-policy noeviction`, or use a separate instance for the cache.

### IN-03: Dead and dangling configuration left over from the skeleton

**File:** `config/cache.php:95-99`, `config/queue.php:59-62`, `database/migrations/0001_01_01_000000_create_users_table.php:39-46`, `composer.json` (`post-create-project-cmd`)
**Issue:**
- The `failover` cache store lists a `database` store that no longer exists.
- `queue.batching` points at a `job_batches` table that is deliberately not created, so `Bus::batch()` would fail at runtime.
- The `sessions` table is created although sessions live in Redis.
- `post-create-project-cmd` touches `database/database.sqlite` and runs `migrate --graceful` for a PostgreSQL-only project.

**Fix:** Remove the failover store and the sqlite step, and either drop the batching config or create the table. Keep or remove `sessions` deliberately.

### IN-04: `Immutability` helper can only freeze a table by its own state column

**File:** `app/Domain/Shared/Database/Immutability.php:17-26`
**Issue:** `guardTriggerSql` decides "frozen" from a column of the same table. Child rows that must become immutable with their parent (invoice lines, billed time entries referencing an invoice) have no such column. The limits list in the docblock does not mention this, and the first real use is in Phase 3.
**Fix:** Note the limit now. Plan a variant that looks the state up in the parent through a foreign key, or require a denormalised state column on child tables.

### IN-05: Partner-visible route walk covers GET routes only

**File:** `tests/Isolation/RouteWalkTest.php:48-70`
**Issue:** `walkedRoutes()` keeps `GET` routes of `filament.admin.*`. Livewire `POST /livewire/update` and `livewire/upload-file` are not requested as Partner A with a tampered payload. Only `Livewire::test()` on the list component exercises Livewire. This is acceptable today (the snapshot checksum protects ids), but the docblock says "every GET route" while the stated goal is "nothing of client B in any response".
**Fix:** Add one real `POST /livewire/update` case when the first real resource arrives, or state the GET-only scope in the test name.

### IN-06: Hard-coded database name in a test that the harness allows to differ

**File:** `tests/Feature/Boot/PanelBootTest.php:23` vs `tests/TestCase.php:27`
**Issue:** `TestCase` accepts any database whose name ends in `_test`, but this test requires exactly `kokpit_test`. A contributor with `kokpit_dev_test` gets a red test that has nothing to do with correctness.
**Fix:** Assert `str_ends_with($database, '_test')` or compare with `config('database.connections.pgsql.database')`.

### IN-07: Broken or machine-translated Czech strings in the published action labels

**File:** `lang/cs/actions.php:17,53,54,63,78`
**Issue:** `'cancel' => 'zrušení'` (noun, not an action), `'open' => 'OTEVŘENO'` (status, upper case), `'like' => 'Jako'`, `'load' => 'Zatížení'` (weight, not "load"), and `named.duplicate => 'Duplikát: jméno'`, which has a literal word instead of the `:name` placeholder. The encoding test only checks UTF-8 and NFC, so none of this is caught. Several keys are unused today, but they surface the moment a later phase uses `__('actions.…')`.
**Fix:** Correct these entries (`'Zrušit'`, `'Otevřít'`, `'Duplikát :name'`, …) and add a test that every `:placeholder` of an English source key is present in the Czech value.

### IN-08: Smaller test-robustness and tooling notes

**File:** `tests/Concurrency/SequenceAllocatorConcurrencyTest.php:25`, `config/media-library.php:7`, `.github/workflows/hygiene.yml:14-16`
**Issue:**
- The 3-second start barrier is a fixed delay. On a slow runner the eight PHP workers may boot after it, overlap less, and the mutation test ("detects the defect when the allocator has no row lock") could pass the real allocator trivially or miss the defect. It is flaky by construction, not wrong.
- `config/media-library.php` imports `Spatie\MediaLibraryPro\Models\TemporaryUpload`, a class of a paid, closed package that the project forbids. It is never autoloaded, but it should be removed or commented to avoid a licence-policy smell.
- `cancel-in-progress: true` in the workflow's concurrency group also cancels a still-running `main` push run, which can leave the previous commit without a finished required check.

**Fix:**
- Make the barrier adaptive (workers signal readiness through a counter table).
- Drop the Pro import.
- Use `cancel-in-progress: ${{ github.event_name == 'pull_request' }}`.

---

_Reviewed: 2026-10-07_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
