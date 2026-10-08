# Phase 3: Operations Foundation - Pattern Map

**Mapped:** 2026-10-08
**Files analyzed:** 46 new/modified files (grouped by the 7 slices of "Suggested Plan Decomposition")
**Analogs found:** 38 / 46 (the rest are covered by RESEARCH.md patterns)

All analog paths below are git-tracked (checked with `git ls-files`). Paths are repo-relative. Line numbers refer to the state at the start of Phase 3.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_10_08_*_create_settings_table.php` | migration | CRUD | `database/migrations/2026_10_07_000100_create_number_sequences_table.php` | exact |
| `database/migrations/2026_10_08_*_add_source_to_activity_log.php` | migration | CRUD | same + `2026_10_07_152629_create_activity_log_table.php` | role-match |
| `app/Domain/Shared/Models/SettingsProperty.php` | model | CRUD | `app/Domain/Shared/Models/Activity.php` | exact |
| `config/settings.php` | config | n/a | `config/activitylog.php` (Phase 2 UUID model swap) | role-match |
| `app/Domain/Settings/Settings/*Settings.php` (5 classes) | model (typed settings) | CRUD | none in repo; `app/Domain/Shared/Money/MoneyCast.php` for casts | partial |
| `app/Domain/Settings/Casts/*Cast.php` | utility | transform | `app/Domain/Shared/Money/MoneyCast.php` | role-match |
| `app/Domain/Settings/Numbering/{NumberPattern,DocumentKind,DocumentNumbering}.php` | service / value object | transform | `app/Domain/Shared/Sequences/SequenceAllocator.php` | role-match |
| `app/Domain/Shared/Sequences/SequenceAllocator.php` (add `peek()`) | service (modified) | CRUD | itself | exact |
| `app/Domain/Shared/Money/Money.php` (add `fromMajor`) | utility (modified) | transform | itself | exact |
| `app/Domain/Settings/Rules/*Rule.php` | utility (validation) | transform | none; use RESEARCH Pattern 3 | no analog |
| `app/Filament/Pages/SettingsPage.php` | page | request-response | `app/Filament/Pages/Dashboard.php` | role-match |
| `app/Filament/Pages/SystemPage.php` | page | request-response | `app/Filament/Pages/Dashboard.php` | role-match |
| `app/Filament/Concerns/EnforcesPageAccessRule.php` (add boot hook) | trait (modified) | request-response | itself | exact |
| `app/Filament/Concerns/EnforcesRelationManagerAccessRule.php`, `EnforcesWidgetAccessRule.php` (boot hook) | trait (modified) | request-response | `EnforcesPageAccessRule.php` | exact |
| `app/Domain/Audit/LoggedAttributes.php` | attribute | n/a | `app/Domain/Shared/Auth/NotPartnerScoped.php` | exact |
| `app/Domain/Audit/LogsAllowlistedActivity.php` | trait | event-driven | `app/Domain/Shared/Auth/DeniesPartners.php` (trait on models) | role-match |
| `app/Domain/Audit/{ActivitySource,KokpitLogActivityAction}.php` | service | event-driven | none; RESEARCH Pattern 5 | no analog |
| `app/Filament/Resources/ActivityResource.php` (+ `Pages/ListActivities.php`) | resource | CRUD (read-only) | `tests/Support/Filament/CanaryRecordResource.php` | role-match |
| `app/Filament/RelationManagers/ActivityHistoryRelationManager.php` + concrete subclasses | component | CRUD (read-only) | `tests/Support/Filament/Fixtures/AdminOnlyRelationManager.php` | role-match |
| `app/Domain/Operations/Jobs/KokpitJob.php` + `RunsAsSystem` middleware | service (queue base) | event-driven | `app/Domain/Shared/Auth/PartnerContext.php::runAsSystem` | partial |
| `app/Domain/Operations/Alerts/{FailureAlerter,OperationalAlert}.php` + `JobFailed` listener | service / notification | event-driven | `tests/Support/Probes/ProbeNotification.php` | partial |
| `app/Domain/Operations/Health/*` (interface, enums, registry, 3 real + 3 placeholder indicators) | service | request-response | none; RESEARCH Pattern 7 | no analog |
| `routes/console.php` (heartbeat schedule) | config | event-driven | itself (currently only `inspire`) | exact |
| `config/kokpit.php` (health thresholds, alert window) | config | n/a | itself | exact |
| `config/queue.php` (`after_commit` true), `config/filesystems.php` (s3 disk) | config (modified) | n/a | themselves | exact |
| `app/Providers/Filament/AdminPanelProvider.php` (`databaseNotifications()`) | provider (modified) | request-response | itself | exact |
| `app/Providers/AccessServiceProvider.php` (policy for settings model) | provider (modified) | n/a | itself | exact |
| `app/Support/ProductionConfigGuard.php` (activitylog on, queue not sync) | utility (modified) | request-response | itself | exact |
| `bootstrap/app.php` (`trustProxies(at: '*')`) | config (modified) | request-response | itself | exact |
| `app/Console/Commands/StorageCheckCommand.php` | command | file-I/O | `app/Console/Commands/ResetAdminTwoFactorCommand.php` | role-match |
| `app/Console/Commands/DeployVerifyCommand.php` | command | request-response | `app/Console/Commands/ResetAdminTwoFactorCommand.php` | role-match |
| `app/Domain/Operations/Storage/StorageCheck.php` | service | file-I/O | none | no analog |
| `zerops.yml` | config | batch | none (yaml tested like `.ddev/config.yaml`) | no analog |
| `.github/workflows/deploy.yml` | config (CI) | event-driven | `.github/workflows/hygiene.yml` | role-match |
| `.github/workflows/hygiene.yml` (rustfs service in `tests` job) | config (modified) | event-driven | itself, `redis` service lines 129-137 | exact |
| `lang/cs/kokpit.php`, `lang/cs/enums.php` (new keys) | config | n/a | themselves | exact |
| `CONTRIBUTING.md` (deploy checklist) | docs | n/a | its existing GitHub settings checklist | exact |
| `tests/Arch/AuditAllowlistTest.php` | test (arch) | transform | `tests/Arch/ModelDeclarationTest.php` + `tests/Support/ModelDeclaration.php` | exact |
| `tests/Arch/JobIdempotenceTest.php` | test (arch) | transform | `tests/Arch/ModelDeclarationTest.php` | role-match |
| `tests/Feature/Operations/HealthRegistryTest.php` | test | request-response | `tests/Arch/PanelRegistryTest.php` | role-match |
| `tests/Feature/Repo/{Zerops,DeployWorkflow}Test.php`, `CiParityTest.php` (extend) | test | transform | `tests/Feature/Repo/CiParityTest.php` | exact |
| `tests/Feature/Operations/StorageCheckTest.php` (group `s3`) | test | file-I/O | `tests/Isolation/SystemRunCommandsTest.php` (artisan command test) | role-match |
| Phase 2 touch points: `ModelDeclarationTest`, `CanaryRegistryTest`, `CanaryRegistry`, `SchemaConventionsTest` | test (modified) | n/a | themselves | exact |
| `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md`, `03-SPIKE-KANBAN.md` | docs | n/a | none (spike code lives outside the repo, D-16) | no analog |

## Pattern Assignments

### `database/migrations/*_create_settings_table.php` (migration, CRUD)

**Analog:** `database/migrations/2026_10_07_000100_create_number_sequences_table.php`

**Core pattern** (lines 19-34): UUID v7 default key, `timestampsTz()`, unique index, raw-SQL check constraints; header comment states the contract.
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('scope_key', 191)->unique();
            $table->bigInteger('next_value')->default(1);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_next_value_check CHECK (next_value >= 1)');
    }
};
```
Apply: `settings` uses `jsonb('payload')`, `unique(['group','name'])`, `boolean('locked')` (RESEARCH Pattern 1 gives the exact body). The `source` migration on `activity_log` follows the `ALTER TABLE ... ADD CONSTRAINT ... CHECK (source IN ('web','console','job','webhook'))` style above. No `down()` (the repo has none).

---

### `app/Domain/Shared/Models/SettingsProperty.php` (model, CRUD)

**Analog:** `app/Domain/Shared/Models/Activity.php` (lines 5-24)
```php
namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity implements PartnerIsolated
{
    use DeniesPartners, HasUuids;
}
```
Apply: `class SettingsProperty extends \Spatie\LaravelSettings\Models\SettingsProperty implements PartnerIsolated { use DeniesPartners, HasUuids; }`, registered in `config/settings.php` `repositories.database.model`. Touch points (RESEARCH Pattern 1): `tests/Arch/ModelDeclarationTest.php` lines 27-38 (the explicit `toEqualCanonicalizing` model list must include the new class), `tests/Isolation/CanaryRegistryTest.php` + `tests/Support/CanaryRegistry.php`, `AccessServiceProvider::boot()` (register `AdminOnlyPolicy`), R8 package registry in `tests/Feature/Schema/SchemaConventionsTest.php`.

---

### `app/Domain/Audit/LoggedAttributes.php` (attribute)

**Analog:** `app/Domain/Shared/Auth/NotPartnerScoped.php` (lines 17-21)
```php
#[Attribute(Attribute::TARGET_CLASS)]
final class NotPartnerScoped
{
    public function __construct(public readonly string $reason) {}
}
```
Apply: `#[Attribute(Attribute::TARGET_CLASS)] final class LoggedAttributes { /** @param list<string> $attributes */ public function __construct(public readonly array $attributes) {} }`. Attributes are not inherited, so each concrete model declares its own.

---

### `tests/Arch/AuditAllowlistTest.php` (arch test, transform)

**Analog:** `tests/Support/ModelDeclaration.php` + `tests/Arch/ModelDeclarationTest.php`

**Scan and problem-list pattern** (ModelDeclaration lines 24-55, 63-88): `appModels()` walks `app/Domain/**/Models`; `problems(string $class): list<string>` reads `ReflectionClass::getAttributes()` on the class itself and returns human-readable problems. Self-check cases use anonymous classes (ModelDeclarationTest lines 52-60: `new #[NotPartnerScoped(reason: '  ')] class extends Model {}`).

Apply: new support class (e.g. `tests/Support/AuditDeclaration.php`) with `problems()` covering RESEARCH Pattern 5 rules (a) to (e); reuse `ModelDeclaration::appModels()` filtered by `class_uses_recursive(LogsActivity::class)`. Include a "scan is not vacuous" test and violating anonymous-class self-checks, exactly as `ModelDeclarationTest` does. Because no model logs yet, create a test-only probe model (like `tests/Support/CanaryRecord.php`) to exercise the happy path.

---

### `app/Filament/Pages/SettingsPage.php` and `SystemPage.php` (page, request-response)

**Analog:** `app/Filament/Pages/Dashboard.php`

**Imports + declaration pattern** (lines 7-24):
```php
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

#[AccessRule(Audience::PartnerAllowed, reason: '...')]
class Dashboard extends BaseDashboard
{
    use EnforcesPageAccessRule;
```
Apply: use `Audience::AdminOnly` with a reason, `use EnforcesPageAccessRule;`, Czech text via `__('kokpit....')` (Dashboard line 37-41). Form body from RESEARCH Pattern 3 (Tabs, Repeater, `->distinct()` on a Select, `CanUseDatabaseTransactions`, `HasUnsavedDataChangesAlert`). `SystemPage` reads the registry in a method that is not `mount()` (Pitfall 1) and uses `wire:poll`.

**Required trait fix** (`app/Filament/Concerns/EnforcesPageAccessRule.php` lines 16-22 currently only define `canAccess()`): add
```php
public function bootEnforcesPageAccessRule(): void
{
    abort_unless(static::canAccess(), 403);
}
```
Do the same in `EnforcesRelationManagerAccessRule` and `EnforcesWidgetAccessRule`; extend `tests/Arch/PanelRegistryTest.php` with a Partner boot-path check.

---

### `app/Filament/RelationManagers/ActivityHistoryRelationManager.php` and concrete subclasses

**Analog:** `tests/Support/Filament/Fixtures/AdminOnlyRelationManager.php` (lines 17-25)
```php
#[AccessRule(Audience::AdminOnly, reason: 'test fixture')]
final class AdminOnlyRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'siblings';
}
```
Apply: the abstract class is skipped by `PanelRegistryTest` (`isAbstract()`), so each record's concrete subclass (5 lines) carries `#[AccessRule(Audience::AdminOnly, ...)]` and the trait. Prove it against the canary resource fixture. Remove the `@phpstan-ignore trait.unused` comment in the trait when this lands.

---

### `app/Filament/Resources/ActivityResource.php` (resource, read-only)

**Analog:** `tests/Support/Filament/CanaryRecordResource.php` with `Pages/ListCanaryRecords.php` (read these before writing; only the pattern of a declared `#[AccessRule]` + `EnforcesResourceAccessRule` + policy-backed resource is relevant). Policy: `app/Domain/Shared/Policies/AdminOnlyPolicy.php`. Panel has `strictAuthorization()` (`AdminPanelProvider` line 53), so the policy must define every method used. The directory is auto-discovered (`AdminPanelProvider` line 65).

---

### `app/Domain/Operations/Jobs/KokpitJob.php` (queue base, event-driven)

**Analog (system context):** `app/Console/Commands/ResetAdminTwoFactorCommand.php` lines 31-33
```php
// Console work has no signed-in user, so the fail-closed scopes would
// silence it (Pitfall 8): the whole run is an explicit system run.
return app(PartnerContext::class)->runAsSystem(fn (): int => $this->reset());
```
Apply: `RunsAsSystem` job middleware wraps `$next($job)` in `app(PartnerContext::class)->runAsSystem(...)`. Attribute-based base class per RESEARCH Pattern 6 (`#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]`, `afterCommit()` in the constructor). Attribute `#[Idempotent(how: '...')]` mirrors `NotPartnerScoped` (attribute above) and is enforced by an arch test cloned from `ModelDeclarationTest`.

---

### `app/Domain/Operations/Alerts/*` (notification, event-driven)

**Analog:** `tests/Support/Probes/ProbeNotification.php` (test notification shape only; read before writing). Core design is RESEARCH Pattern 6: listener on `Illuminate\Queue\Events\JobFailed`, catch everything, `Notification::sendNow`, class not `ShouldQueue`, `toDatabase()` in Filament format, `Cache::add` throttle window read from `config/kokpit.php`. Add `->databaseNotifications()` to `AdminPanelProvider::configure()` (chain after `->spa()`, line 50).

---

### `app/Console/Commands/StorageCheckCommand.php`, `DeployVerifyCommand.php` (command)

**Analog:** `app/Console/Commands/ResetAdminTwoFactorCommand.php`

**Signature, system run, exit codes, Czech messages** (lines 25-45, 70-72):
```php
protected $signature = 'kokpit:admin:reset-2fa {email : ...} {--force : ...}';
protected $description = '...';

public function handle(): int
{
    return app(PartnerContext::class)->runAsSystem(fn (): int => $this->reset());
}
...
$this->components->error(__('kokpit.reset_2fa.not_found'));
return self::FAILURE;
...
$this->components->info(__('kokpit.reset_2fa.done'));
return self::SUCCESS;
```
Apply: `kokpit:storage:check` and `kokpit:deploy:verify` use the `kokpit:` prefix, return `self::SUCCESS`/`self::FAILURE`, print through `$this->components->*`, put messages in `lang/cs/kokpit.php`. Never print signed URLs or secrets (see the audit line comment at line 21). Test pattern: `tests/Isolation/SystemRunCommandsTest.php` (the `recordSystemFlagAt` helper, lines 18-34, proves the command runs in the system context; add each new command to that test).

---

### `app/Domain/Settings/Numbering/*` and `SequenceAllocator::peek()`

**Analog:** `app/Domain/Shared/Sequences/SequenceAllocator.php`

**Key contract** (lines 29, 73-82): `KEY_PATTERN = '/^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$/D'`, `scopeKeyForYear($kind, $at)` yields `kind:YYYY` in Europe/Prague. Invariant to test: default `{YYYY}{NNNN}` maps to exactly `scopeKeyForYear('invoice', $at)`.

**Read-only peek** (model on lines 56 and 84-89; no lock, no insert, no transaction requirement):
```php
$row = DB::selectOne('SELECT next_value FROM number_sequences WHERE scope_key = ?', [$scopeKey]);
return $row === null ? 1 : (int) $row->next_value;
```
Call `self::assertValidKey($scopeKey)` first. Do not call `next()` from the preview. Tests: clone the style of `tests/Feature/Sequences/SequenceAllocatorTest.php`.

---

### `.github/workflows/deploy.yml` and `hygiene.yml` rustfs service

**Analog:** `.github/workflows/hygiene.yml`

**Job conventions** (lines 109-114, 148-150): `runs-on: ubuntu-24.04`, `permissions: contents: read`, checkout pinned to a 40-char SHA with version comment, `persist-credentials: false`:
```yaml
uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
with:
  persist-credentials: false
```
**Service pattern** (lines 129-137, `redis`): add `rustfs` next to it with the same `options: --health-cmd ... --health-interval 5s --health-timeout 5s --health-retries 12`, image tag equal to `.ddev/docker-compose.rustfs.yaml`. Do not touch `ci-passed.needs`.

`deploy.yml` is a separate file; `CiParityTest::ciJobs()` hard-codes `hygiene.yml`, so write a second test file (e.g. `DeployWorkflowTest.php`) reusing the helper approach, asserting: top-level `permissions: {}`, no `pull_request_target`/`workflow_run`/`issue_comment` triggers, `environment: production` on the deploy job, SHA-pinned `uses`, `persist-credentials: false`, no `${{ }}` of event data inside `run:`. Note `CiParityTest` line 116 requires `runs-on: ubuntu-24.04` and `contents: read` per job in hygiene only; keep the same values in deploy.yml.

---

### `zerops.yml` and its test

**Analog (test only):** `tests/Feature/Repo/CiParityTest.php` lines 12-20: `Yaml::parseFile(base_path($relativePath))` with `symfony/yaml` (already in `require-dev`), static assertions, no Docker or network. Assert: no literal secrets (only `${...}` references), `deployFiles` contains `lang`, `app`, `config`, `database`, `public`, `routes`, `vendor`; `execOnce` migration only in the `app` setup. YAML body from RESEARCH Pattern 10.

---

### Config files (modified)

- `config/kokpit.php` (lines 5-31): flat array with a boxed docblock comment above each key and `(bool) env('KOKPIT_...', default)`. Add a `health` key with thresholds in plain literals (env-free per D-12) and a documented alert throttle window.
- `app/Support/ProductionConfigGuard.php` (lines 16-36): add two more `if (...) throw new RuntimeException('Refusing to boot in production: ...')` blocks (activitylog.enabled must be true; queue.default must not be `sync`), with matching cases in `tests/Unit/Support/ProductionConfigGuardTest.php`.
- `routes/console.php`: add `Schedule::call(...)->everyMinute()->name('kokpit-heartbeat')` (use `Cache::forever`); add a test asserting no `activitylog:clean` is scheduled (D-09).
- `config/queue.php:44` `'after_commit' => true`; `config/filesystems.php` s3 disk stays env-only (the check builds it with `throw => true`).

## Shared Patterns

### Access declaration (all new Filament classes)
**Source:** `app/Filament/Pages/Dashboard.php` lines 21-24 and `app/Filament/Concerns/EnforcesPageAccessRule.php`
**Apply to:** SettingsPage, SystemPage, ActivityResource, every concrete relation manager. Use `Audience::AdminOnly` with a non-empty `reason`. `tests/Arch/PanelRegistryTest.php` fails otherwise.

### System context
**Source:** `app/Console/Commands/ResetAdminTwoFactorCommand.php` lines 31-33
**Apply to:** new Artisan commands, `KokpitJob` middleware, scheduled closures that touch scoped models. Register commands in `tests/Isolation/SystemRunCommandsTest.php`.

### Model declaration
**Source:** `app/Domain/Shared/Models/Activity.php`, `tests/Support/ModelDeclaration.php`
**Apply to:** every new model (settings model): `implements PartnerIsolated` + `DeniesPartners` + `HasUuids`, and add it to the explicit model list in `ModelDeclarationTest`.

### Czech strings and enum labels
**Source:** `lang/cs/kokpit.php`, `lang/cs/enums.php`, `tests/Feature/Localisation/EnumLabelsTest.php`
**Apply to:** all UI text, every `HasLabel` enum (`BankAccountFormat`, `HealthStatus`, `HealthSlot`, `DocumentKind`, `VatMode`). Code and docs stay English.

### Query escape hatch
**Source:** `tests/Arch/QueryEscapeHatchTest.php`
**Apply to:** all new `app/` code. No `DB::table(` (use `app('queue.failer')->count()`, Eloquent models). `SequenceAllocator` uses `DB::insert/select/update` with raw SQL, which is the allowed precedent for counters.

### Repository hygiene in fixtures
**Source:** `.claude/CLAUDE.md`, `scripts/check-sensitive.sh`
**Apply to:** bank account, IBAN and supplier fixtures. Assemble fake IBANs at runtime from fragments; `example.com` e-mails (tests use an `exampleEmail()` helper, see `SystemRunCommandsTest.php` line 40); company ID `12345678`.

### Test conventions
**Source:** `tests/Arch/ModelDeclarationTest.php`, `tests/Feature/Repo/CiParityTest.php`
Pest `it('...', function (): void {...})`, `declare(strict_types=1)`, scan tests have a "not vacuous" guard plus anonymous-class self-checks, YAML contract tests parse and never run.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Settings/Settings/*Settings.php` | typed settings | CRUD | No `spatie/laravel-settings` usage exists; follow RESEARCH Pattern 2 (use casts, avoid `@var array<int, array<string, mixed>>`) |
| `app/Domain/Settings/Rules/*` (IBAN, currencies, pattern) | validation rule | transform | No custom `ValidationRule` yet; follow RESEARCH Patterns 3 and 4 |
| `app/Domain/Audit/{ActivitySource,KokpitLogActivityAction}.php` | service | event-driven | No activity logging yet; RESEARCH Pattern 5 (override `beforeActivityLogged`, config key `activitylog.actions.log_activity`) |
| `app/Domain/Operations/Health/*` | registry/interface | request-response | New concept; RESEARCH Pattern 7, plus a registry test cloned in spirit from `PanelRegistryTest` |
| `app/Domain/Operations/Storage/StorageCheck.php` | service | file-I/O | No S3 usage yet; RESEARCH Pattern 9 |
| `zerops.yml` | config | batch | First deploy manifest; RESEARCH Pattern 10 |
| Spike records (`03-SPIKE-PDF.md`, `03-SPIKE-KANBAN.md`) | docs | n/a | Spike code is throwaway and outside the repo (D-16) |

## Metadata

**Analog search scope:** `app/`, `tests/`, `config/`, `routes/`, `database/migrations/`, `.github/workflows/`, `.ddev/`, `lang/cs/`, `scripts/`
**Files scanned:** about 150 tracked files listed, 16 read in full or in part
**Pattern extraction date:** 2026-10-08
