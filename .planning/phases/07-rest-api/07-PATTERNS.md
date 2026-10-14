# Phase 7: REST API - Pattern Map

**Mapped:** 2026-10-14
**Files analyzed:** 36 new/modified
**Analogs found:** 30 / 36 (6 have no in-repo analog: HTTP layer and Scramble are greenfield, planner uses 07-RESEARCH.md patterns 1-3, 5, 7, 9)

All analog paths below are git-tracked (verified with `git ls-files` for the key ones; none live under a gitignored mirror).

## Owner decisions applied (locked, from the orchestrator)

- `duration_seconds` is accepted on writes as an alternative to `ended_at` (exactly one); mapped to `ended_at = started_at + duration` in the request layer, the Actions stay unchanged.
- Running UI-timer entries are refused like in the UI: PATCH cannot set `ended_at` -> 422; DELETE of a running entry -> 409. Note the Actions do NOT enforce this today (see "Gaps" below).
- Idempotency stores and replays only 2xx; any non-2xx or exception releases (deletes) the claim.
- New `api` activity-source label: migration + enum case + Czech lang string. Docs UI stays on the CDN (no vendoring).
- Date-only `from`/`to` = Europe/Prague calendar days, inclusive; a full ISO 8601 instant with offset is used exactly.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `bootstrap/app.php` (modify: `api:` routing, `apiPrefix: 'api/v1'`, middleware aliases) | config | request-response | itself (`bootstrap/app.php` 12-16, 38-46) | exact |
| `routes/api.php` | route | request-response | `routes/web.php` / `routes/console.php` (structure only) | partial |
| `config/sanctum.php` (publish, `guard => []`) | config | n/a | `config/permission.php` (published vendor config) | role-match |
| `config/scramble.php` (publish) | config | n/a | `config/activitylog.php` | role-match |
| `config/kokpit.php` (add `api` block) | config | n/a | `config/kokpit.php` `alerts` block (lines 41-59) | exact |
| `database/migrations/..._add_external_to_time_entries_table.php` | migration | CRUD | `database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php` (additive ALTER + CHECK) | role-match |
| `database/migrations/..._create_api_idempotency_keys_table.php` | migration | CRUD | `database/migrations/2026_10_11_000100_create_time_entries_table.php` + `..._151910_create_personal_access_tokens_table.php` | exact |
| `database/migrations/..._add_api_to_activity_log_source_check.php` | migration | CRUD | `2026_10_08_000200_add_source_to_activity_log_table.php` | exact |
| `app/Domain/Audit/ActivitySourceLabel.php` (add `case Api = 'api'`) | enum | transform | itself | exact |
| `lang/cs/enums.php` (`activity_source_label.api`) | config | n/a | `lang/cs/enums.php` lines 91-96 | exact |
| `app/Domain/Audit/ActivitySource.php` (explicit `as(Api, ...)` use in API pipeline) | service | request-response | itself (`as()` lines 46-60) | exact |
| `app/Domain/TimeTracking/Models/TimeEntry.php` (add `external` cast/docblock) | model | CRUD | itself | exact |
| `app/Domain/TimeTracking/TimeEntryInput.php` (add `external()` validator) | utility | transform | `TimeEntryInput::description()` (same class) | exact |
| `app/Domain/TimeTracking/Actions/CreateTimeEntry.php` / `UpdateTimeEntry.php` (additive `external` key) | service | CRUD | themselves | exact |
| `app/Domain/Identity/Models/PersonalAccessToken.php` (add `findToken()` UUID guard) | model | request-response | itself | exact |
| `app/Domain/Identity/ApiAbility.php` (enum `time:read`, `time:write`, label) | enum | transform | `app/Domain/Audit/ActivitySourceLabel.php` | exact |
| `app/Domain/Identity/Actions/IssueApiToken.php` | service | CRUD | `app/Domain/TimeTracking/Actions/CreateTimeEntry.php` (Gate + transaction pattern) | role-match |
| `app/Domain/TimeTracking/Api/EntryContextResolver.php` | service | transform | `TimeEntryInput::context()` + `EntryContextOptions` | role-match |
| `app/Domain/TimeTracking/Api/IdempotencyKey.php` (model) | model | CRUD | `app/Domain/Shared/Models/KokpitModel.php` subclass `PersonalAccessToken` (`#[NotPartnerScoped]`) | role-match |
| `app/Domain/Shared/Database/MorphMap.php` (add alias) | config | n/a | itself | exact |
| `app/Http/Middleware/EnsureIdempotency.php` | middleware | request-response | no in-repo analog (`app/Http/Middleware/SetNoReferrerPolicy.php` for registration only) | none |
| `app/Http/Controllers/Api/V1/*` | controller | request-response | no in-repo analog (thin shell over Actions) | none |
| `app/Http/Requests/Api/V1/*` | request | request-response | `TimeEntryInput` rules + `AcceptInvitation` page validation | none |
| `app/Http/Resources/Api/V1/*` | resource | transform | no in-repo analog | none |
| `app/Providers/ApiServiceProvider.php` (limiters, Sanctum callback, gate, Scramble) | provider | request-response | `app/Providers/AppServiceProvider.php` (line 39 `RateLimiter::for`) + `AccessServiceProvider` | role-match |
| `bootstrap/providers.php` (register provider) | config | n/a | itself | exact |
| `app/Providers/AccessServiceProvider.php` (add `Gate::policy(PersonalAccessToken::class, AdminOnlyPolicy::class)`) | provider | n/a | itself | exact |
| `app/Filament/Resources/ApiTokenResource.php` | component | CRUD | `app/Filament/Resources/ActivityResource.php` (list-only, Admin-only) | exact |
| `app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php` | component | CRUD | `app/Filament/Resources/ActivityResource/Pages/ListActivities.php` | exact |
| `app/Domain/TimeTracking/Jobs/PurgeExpiredIdempotencyKeys.php` (or `app/Domain/Operations/Jobs/`) | job | batch | `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php` | exact |
| `routes/console.php` (schedule the purge) | config | batch | itself (`kokpit-activity-log-prune`, lines 41-45) | exact |
| `lang/cs/kokpit.php` (`api_tokens.*`) | config | n/a | `lang/cs/kokpit.php` `activity.*` | exact |
| `docs/api/openapi.json` (committed spec) | config | n/a | none | none |
| `tests/Feature/Api/*`, `tests/Isolation/ApiAccessTest.php`, `tests/Concurrency/IdempotencyConcurrencyTest.php` | test | request-response | `tests/Feature/TimeTracking/TimeEntryActionsTest.php`, `tests/Isolation/RouteWalkTest.php`, `tests/Feature/Operations/ActivitySourceTest.php` | role-match |
| `tests/Arch/JobContractTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Feature/Operations/ScheduleOnOneServerTest.php` (modify lists) | test | n/a | themselves | exact |

## Pattern Assignments

### `app/Domain/Audit/ActivitySourceLabel.php` + migration + `lang/cs/enums.php` (enum, transform)

**Analog:** `app/Domain/Audit/ActivitySourceLabel.php` lines 14-27; `database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php`; `lang/cs/enums.php` lines 91-96.

Enum (add one case):
```php
enum ActivitySourceLabel: string implements HasLabel
{
    case Web = 'web';
    case Console = 'console';
    case Job = 'job';
    case Webhook = 'webhook';
    // add: case Api = 'api';
    public function getLabel(): string { return __('enums.activity_source_label.'.$this->value); }
}
```
Lang (add `'api' => 'API'` to `activity_source_label`):
```php
'activity_source_label' => ['web' => 'Web', 'console' => 'Konzole', 'job' => 'Úloha na pozadí', 'webhook' => 'Webhook'],
```
Migration: the existing CHECK is `activity_log_source_check CHECK (source IN ('web','console','job','webhook'))` and was created with a raw `DB::statement`. The new migration must DROP and re-ADD the constraint (the original migration has no `down()`; give the new one a `down()` or follow the same up-only style):
```php
DB::statement('ALTER TABLE activity_log DROP CONSTRAINT activity_log_source_check');
DB::statement("ALTER TABLE activity_log ADD CONSTRAINT activity_log_source_check CHECK (source IN ('web', 'console', 'job', 'webhook', 'api'))");
```
Wiring: `ActivitySource::as(ActivitySourceLabel::Api, fn () => ...)` (ActivitySource.php lines 46-60, innermost wins, restored in `finally`) is the mechanism; wrap it in an API middleware or the controller so the label comes from the execution context, never from input. Note `ActivitySource::current()` falls back to Console in the test runner, so API tests must assert the explicit `api` wrapper (see `tests/Feature/Operations/ActivitySourceTest.php` lines 1-40 for `simulateWebRequest()` and `createdRowOfProbe()` helpers). Also add an `api` filter case in `tests/Feature/Operations/ActivityViewsTest.php` style (line 178-186) if the overview filter enumerates labels.

---

### `database/migrations/..._add_external_to_time_entries_table.php` (migration, CRUD)

**Analog:** `2026_10_08_000200_add_source_to_activity_log_table.php` (header comment style, `Schema::table` + raw CHECK) and `2026_10_11_000100_create_time_entries_table.php` (header explains DB-enforced invariants).

Copy: file header comment explaining the column; `declare(strict_types=1)`; anonymous `new class extends Migration`; raw `DB::statement` for jsonb CHECK (07-RESEARCH Pattern 4):
```php
DB::statement('ALTER TABLE time_entries ADD COLUMN external jsonb');
DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_external_object_check CHECK (external IS NULL OR jsonb_typeof(external) = 'object')");
```
Frozen-row trigger: the Phase 6 migration header states the guard compares `to_jsonb(NEW) - allowed` to OLD, so `external` is frozen on billed rows automatically; do NOT add it to the mutable list. Model: keep out of `#[Fillable]` (TimeEntry.php line ~76 `#[Fillable(['description','billable'])]`) and out of `#[LoggedAttributes]` (line ~77), use `forceFill` in the Actions, cast as `object`-safe (not `array`).

---

### `database/migrations/..._create_api_idempotency_keys_table.php` (migration, CRUD)

**Analog:** `2026_10_07_151910_create_personal_access_tokens_table.php` (whole file, 12-27) and `2026_10_11_000100_create_time_entries_table.php` lines 47-70.

```php
Schema::create('personal_access_tokens', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->uuidMorphs('tokenable');
    ...
    $table->timestampTz('expires_at')->nullable()->index();
    $table->timestampsTz();
});
```
Apply: `uuid('id')->primary()->default(DB::raw('uuidv7()'))`; `foreignUuid('token_id')->constrained('personal_access_tokens')->cascadeOnDelete()`; `string('key', 255)`; `char('request_hash', 64)`; `timestampTz('locked_at')`, `smallInteger('response_status')->nullable()`, `jsonb` body (raw `DB::statement` or `$table->jsonb`), `timestampTz('completed_at')->nullable()`, `timestampTz('expires_at')->index()`, `timestampsTz()`; `unique(['token_id','key'])`. Always `timestampTz`, never `timestamps()`.

---

### `app/Domain/TimeTracking/Api/IdempotencyKey.php` (model, CRUD)

**Analog:** `app/Domain/Identity/Models/PersonalAccessToken.php` lines 1-28 (class-level `#[NotPartnerScoped(reason: ...)]`) on top of `app/Domain/Shared/Models/KokpitModel.php` (HasUuids base, lines 1-27 docblock lists the conventions).
```php
#[NotPartnerScoped(reason: 'token lookup runs before any user is authenticated')]
class PersonalAccessToken extends BasePersonalAccessToken { use HasUuids; }
```
Apply: `final class IdempotencyKey extends KokpitModel` with `#[NotPartnerScoped(reason: 'API infrastructure keyed by token, never a Partner surface')]`. Required follow-ups (arch tests are exact lists): add alias in `app/Domain/Shared/Database/MorphMap.php::MAP` (existing entry style: `'personal_access_token' => PersonalAccessToken::class`) and add the class to the `ModelDeclaration::appModels()` expected list in `tests/Arch/ModelDeclarationTest.php` (lines 46-). Use the Eloquent builder (`IdempotencyKey::query()->insertOrIgnore(...)`) because `DB::table(` and bare `withoutGlobalScopes()` are forbidden by `tests/Arch/QueryEscapeHatchTest.php`.

---

### `app/Domain/Identity/Models/PersonalAccessToken.php` (modify: `findToken()` guard)

**Analog:** itself (lines 19-28). Add, per Pitfall 2:
```php
public static function findToken($token)
{
    if (is_string($token) && str_contains($token, '|')) {
        [$id] = explode('|', $token, 2);
        if (! Str::isUuid($id)) { return null; }
    }
    return parent::findToken($token);
}
```
Test: `Bearer abc|def`, `Bearer |x`, empty bearer, valid-uuid-wrong-secret all 401 (not 500).

---

### `app/Domain/Identity/Actions/IssueApiToken.php` (service, CRUD)

**Analog:** `app/Domain/TimeTracking/Actions/CreateTimeEntry.php` (final class, `Gate::forUser($actor)->authorize(...)` first line of `handle`, `@throws` docblock, strictly typed). Imports/convention excerpt (lines 5-14, 47-58):
```php
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
final class CreateTimeEntry {
    public function handle(User $actor, array $data): TimeEntry {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);
```
Apply: `handle(User $actor, string $name, list<ApiAbility> $abilities, ?CarbonImmutable $expiresAt): NewAccessToken`; refuse non-Admin (`$actor->hasRole(RoleName::Admin->value)`, `RoleName` at `app/Domain/Identity/RoleName.php`), validate abilities only from `ApiAbility` enum values, call `$actor->createToken($name, $values, $expiresAt)`. Activity row via manual `activity()->log()` with name and abilities only (never the token), wrapped as source Api or Web as appropriate.

---

### `app/Domain/TimeTracking/TimeEntryInput.php` (add `external()`)

**Analog:** `TimeEntryInput::description()` in the same class (constant `DESCRIPTION_MAX_LENGTH = 1000` at line 21; throws `ValidationException::withMessages(['description' => __('kokpit.time.errors...')])`). Add `private const` for depth cap and a `public static function external(mixed $value): ?object` reading size from `config('kokpit.api.external_max_bytes')`, error key `external`, lang strings under `lang/cs/kokpit.php` `time.errors`. Object-ness must be decided from the raw JSON body (`{}` vs `[]`).

---

### `app/Domain/TimeTracking/Actions/CreateTimeEntry.php` / `UpdateTimeEntry.php` (modify)

**Analog:** themselves. Additive only: extend `@phpstan-type EntryData` (CreateTimeEntry line 31) and `EntryChanges` (UpdateTimeEntry line 38) with `external?: mixed`, then in `CreateTimeEntry::handle` add `'external' => ...` to the `forceFill` array (lines 76-82) and in `UpdateTimeEntry::handle` an `array_key_exists('external', $data)` branch next to the description branch (lines 66-68; absent keeps, explicit null clears).

Gaps to close (owner decision "running UI-timer entries refused"):
- `UpdateTimeEntry::applyTimes()` lines 156-160 silently IGNORES a named `ended_at` on a running entry ("a named end is ignored"). The API must answer 422, so the check belongs in the API request layer (or a small additive Action guard) rather than changing the UI behaviour; planner must decide and state it. Do not make the Action throw if the UI relies on silent ignore (verify with `tests/Feature/TimeTracking/TimeEntryActionsTest.php`).
- `DeleteTimeEntry` (lines 28-47) explicitly ALLOWS deleting a running entry ("A running entry can be deleted; the list does not offer it"). The API must return 409 for a running entry: add the guard in the controller via `$entry->isRunning()` (`TimeEntry::isRunning()` exists, line ~84) before calling the Action. This is API-side, no change to the Action.

Exception mapping (07-RESEARCH Pitfall 5): `DeleteTimeEntry`/`UpdateTimeEntry` throw `DomainException` (via `TimeEntryInput::locked()`) for a billed entry; Laravel renders it as 500, so the controller catches it and answers 409. `ValidationException` renders the stock 422 `{message, errors}` with the Action's own field keys (`client_id`, `project_id`, `task_id`, `started_at`, `ended_at`, `description`).

---

### `app/Domain/TimeTracking/Api/EntryContextResolver.php` (service, transform)

**Analog:** `TimeEntryInput::context()` (`app/Domain/TimeTracking/TimeEntryInput.php` lines 33-65) for the id handling and field-error keys; `app/Domain/TimeTracking/Queries/EntryContextOptions.php` for the "selectable" sets (read it for `Project::selectable()` usage when writing lookups). Resolve project key (`^[A-Z]{2,6}$`) and task reference (`^[A-Z]{2,6}-[0-9]+$`, column `tasks.reference`, project `projects.key`) to UUIDs before calling the Action; `Str::isUuid()` first, never pass an unvalidated string to a uuid column. Unknown value -> `ValidationException::withMessages(['task_id' => ...])` using the same lang keys as the Action (`task_unavailable`, `project_unavailable`, `client_required`).

---

### `app/Filament/Resources/ApiTokenResource.php` + `Pages/ListApiTokens.php` (component, CRUD)

**Analog:** `app/Filament/Resources/ActivityResource.php` (list-only, Admin-only, no create) and `app/Filament/Resources/ActivityResource/Pages/ListActivities.php`.

Resource skeleton (ActivityResource lines 34-44, 193-198):
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ActivityResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $model = Activity::class;
    protected static ?string $slug = 'activity';
    protected static ?int $navigationSort = 80;
    public static function getNavigationLabel(): string { return __('kokpit.activity.navigation_label'); }
    public static function getNavigationGroup(): string { return __('kokpit.activity.navigation_group'); }
    public static function getNavigationIcon(): Heroicon { return Heroicon::OutlinedClock; }
    public static function canCreate(): bool { return false; }
    public static function getPages(): array { return ['index' => ListActivities::route('/')]; }
}
```
Apply per UI-SPEC: slug `api-tokens`, `$navigationSort = 92`, icon `Heroicon::OutlinedKey`, group "Správa" (reuse the existing group string used by the settings/system pages; grep `navigation_group` in `lang/cs/kokpit.php`), model `PersonalAccessToken`, `getEloquentQuery()` restricted to `tokenable_type = 'user'` and the signed-in user id. Imports to copy: `App\Domain\Shared\Auth\AccessRule`, `App\Domain\Shared\Auth\Audience`, `App\Filament\Concerns\EnforcesResourceAccessRule`, `Filament\Support\Icons\Heroicon`.

List page (ListActivities lines 9-33): `final class ListApiTokens extends ListRecords { protected static string $resource = ...; getTitle() via __(); getHeaderActions() }` but here `getHeaderActions()` returns the `createToken` `Action` (custom form + handler, not `CreateAction`) and the docs link. Identity confirmation: `Hash::check` + `AppAuthentication::make()->verifyCode($code, null, shouldPreventCodeReuse: true)`; throttle with `danharrin/livewire-rate-limiting` `WithRateLimiting` (precedent `app/Filament/Pages/Auth/AcceptInvitation.php`). Token plain text only in a `#[Locked]` Livewire property. Add `Gate::policy(PersonalAccessToken::class, AdminOnlyPolicy::class)` in `app/Providers/AccessServiceProvider.php` (imports already include `AdminOnlyPolicy` and `Gate`; model import `App\Domain\Identity\Models\PersonalAccessToken` is not there yet). Czech labels under `lang/cs/kokpit.php` `api_tokens.*` (UI-SPEC Copywriting Contract is the exact string list).

---

### `app/Domain/TimeTracking/Jobs/PurgeExpiredIdempotencyKeys.php` (job, batch)

**Analog:** `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php` (and `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` for a TimeTracking-located job).
```php
#[Idempotent(how: 'overwrites one cache timestamp, so a second run changes nothing')]
final class RecordWorkerHeartbeat extends KokpitJob implements ShouldBeUnique
{
    public int $uniqueFor = 900;
    public function handle(): void { app(Heartbeats::class)->recordWorker(); }
}
```
Apply: `#[Idempotent(how: 'deletes rows past expires_at; a second run finds nothing to delete')]`, `final class ... extends KokpitJob`, `handle()` deletes with `IdempotencyKey::query()->where('expires_at', '<', now())->delete()`. Schedule in `routes/console.php` following lines 41-45:
```php
Schedule::job(new NotifyLongRunningTimers)->everyFiveMinutes()->name('kokpit-long-running-timers')->onOneServer();
```
Use `->daily()->name('kokpit-api-idempotency-prune')->onOneServer()`. Update the exact lists: `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php` (line ~21) and the known-events `toContain(...)` in `tests/Feature/Operations/ScheduleOnOneServerTest.php` (line 39).

---

### `app/Providers/ApiServiceProvider.php` (provider, request-response)

**Analog:** `app/Providers/AppServiceProvider.php` line 39 and `app/Providers/AccessServiceProvider.php` (Gate/Policy wiring). Pattern:
```php
RateLimiter::for('invitation', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
```
Apply (07-RESEARCH Patterns 1, 8, 9): `RateLimiter::for('api-read'|'api-write', ...)` keyed by `$r->user()?->currentAccessToken()?->getKey() ?? $r->ip()` with limits from `config('kokpit.api.rate_limit.read|write')`; `Sanctum::authenticateAccessTokensUsing(...)` (Admin role AND not deactivated AND valid); `Gate::define('viewApiDocs', ...)`; Scramble `configure()` transformers. Register in `bootstrap/providers.php`.

---

### `bootstrap/app.php` (modify)

**Analog:** itself. Existing `withRouting(web:, commands:, health:)` lines 12-16 gets `api: __DIR__.'/../routes/api.php', apiPrefix: 'api/v1'`; `withMiddleware` lines 17-31 gets `$middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class, ...])`; existing priority-list prepend precedent (lines 28-30):
```php
$middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: SetNoReferrerPolicy::class);
```
`shouldRenderJsonWhen` already renders `api/*` as JSON (lines 33-35); but the prefix means routes live under `api/v1/*`, which `$request->is('api/*')` still matches. Make the idempotency middleware run after `auth:sanctum` and throttle (add to priority list if ordering is needed).

---

### `config/kokpit.php` (add `api` block)

**Analog:** `alerts` block (lines 41-59 of the same file): a docblock header comment banner explaining each key, then a literal array. Add:
```php
'api' => [
    'rate_limit' => ['read' => 120, 'write' => 60],
    'idempotency_ttl_hours' => 24,
    'idempotency_stale_lock_seconds' => 120,
    'batch_max_items' => 100,
    'external_max_bytes' => 4096,
    'page_size' => ['default' => 50, 'max' => 200],
],
```

---

### Controllers, FormRequests, Resources, EnsureIdempotency (greenfield HTTP layer)

No in-repo analog (the repo has no `app/Http/Controllers`, `Requests` or `Resources`; only `app/Http/Middleware/SetNoReferrerPolicy.php`). Planner uses 07-RESEARCH.md Patterns 2 (thin controllers), 3 (query-builder list, lookups), 5 (batch), 7 (idempotency algorithm). Repo-wide conventions that still apply:
- `declare(strict_types=1);`, `final class`, docblocks explaining the rule (see any Action).
- Controllers pass `$request->user()` as the `User $actor` to the existing Actions (`handle(User $actor, array $data)` signature).
- Date range: mirror `TimeEntryResource::periodBounds()` / `pragueMidnight()` (`app/Filament/Resources/TimeEntryResource.php` lines 538-580). Key excerpt, which defines the inclusive-day semantics the owner locked:
```php
// Both days are inclusive: the end is the midnight after the last day.
'custom' => [
    ($day = self::day($data['from'] ?? null)) === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC'),
    ($day = self::day($data['until'] ?? null)) === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->addDay(),
],
...
return CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), FilamentTimezone::get())?->utc();
```
These are `private static` in a Filament resource, so extract to a small shared helper (for example `app/Domain/TimeTracking/Support/` next to `TimerClock`) and have both the resource and the API filter call it, rather than copying. A full ISO 8601 instant with offset is used verbatim (`TimeEntryInput::instant()` parses it).
- Instants: writes go through `TimeEntryInput::instant()` (app timezone UTC or ISO 8601 with offset, truncated to whole seconds). `duration_seconds` -> `ended_at` mapping must truncate identically; bound the interval to avoid PG integer overflow (Pitfall 7).
- Read paths: `TimeEntry` is `DeniesPartners`; reads by the Admin token user work through the normal model. Lookups must return explicit field allow-lists (no `hourly_rate*`, billing tables).
- Idempotency middleware must not hold the claim inside the request transaction and must delete the claim on non-2xx/exception (owner decision).

---

### Tests

**Analogs:**
- CRUD/behaviour tests: `tests/Feature/TimeTracking/TimeEntryActionsTest.php` (header imports lines 1-27, `entrySystem()` helper runs code through `PartnerContext::runAsSystem`, fictional data only). Use `$admin->createToken(...)->plainTextToken` + `withToken()` for security tests; `Sanctum::actingAs()` only for plain CRUD.
- Isolation: `tests/Isolation/RouteWalkTest.php` (walks `Route::getRoutes()` for `filament.admin.` GET routes) is the model for an `ApiAccessTest` that walks every `api/v1` route and asserts 401 without a token, 401 for a Partner token, 403 for a missing ability. Extend the canary harness (`tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`) with one line for API access, and add the UI-SPEC "Never on a Partner surface" strings to the rendered-HTML check.
- Activity source: `tests/Feature/Operations/ActivitySourceTest.php` for the new `api` label.
- Concurrency: files in `tests/Concurrency/` are the analog for the idempotency 409 race test.
- Arch lists to update: `tests/Arch/JobContractTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Feature/Operations/ScheduleOnOneServerTest.php`, plus `tests/Arch/ActivityAllowlistTest.php` stays untouched (`external` is not logged).
- Test env: `phpunit.xml` runs with `KOKPIT_REQUIRE_ADMIN_2FA=false` and `CACHE_STORE=array`; run via `ddev exec vendor/bin/pest`. Fake values must be assembled from fragments (see `exampleEmail()` in `tests/Pest.php` line 27).

## Shared Patterns

### Admin-only access declaration
**Source:** `app/Filament/Resources/ActivityResource.php` lines 34-37 (`#[AccessRule(Audience::AdminOnly, reason: ...)]` + `use EnforcesResourceAccessRule`), `app/Domain/Shared/Policies/AdminOnlyPolicy.php` registered in `AccessServiceProvider`.
**Apply to:** `ApiTokenResource`, its page, any new Livewire component, the `PersonalAccessToken` policy registration.

### Model isolation declaration
**Source:** `app/Domain/Identity/Models/PersonalAccessToken.php` line 19 (`#[NotPartnerScoped(reason: '...')]`), `TimeEntry` uses `DeniesPartners` + `PartnerIsolated`.
**Apply to:** `IdempotencyKey` model (NotPartnerScoped); `TimeEntry` keeps its Partner denial untouched.

### Domain Action as the single rule owner
**Source:** `app/Domain/TimeTracking/Actions/CreateTimeEntry.php` lines 47-90 (`Gate::forUser($actor)->authorize` then validation then `DB::transaction` + `forceFill`), `DeleteTimeEntry.php` lines 28-47 (lock, `TimeEntryInput::locked()`, KP001 translation).
**Apply to:** every write endpoint, batch items (each item calls `CreateTimeEntry::handle` in its own transaction), `IssueApiToken`.

### Migrations
**Source:** `2026_10_07_151910_create_personal_access_tokens_table.php`: `uuid('id')->primary()->default(DB::raw('uuidv7()'))`, `timestampTz`, `timestampsTz()`, raw `DB::statement` for CHECKs; header block comment states the invariants (`2026_10_11_000100_create_time_entries_table.php` 1-40).
**Apply to:** the three new migrations.

### Scheduled / queued job contract
**Source:** `RecordWorkerHeartbeat.php` + `routes/console.php` (every event `->name(...)->onOneServer()`).
**Apply to:** the idempotency purge job.

### Config values and Czech strings
**Source:** `config/kokpit.php` literal arrays with banner comments; `lang/cs/kokpit.php`, `lang/cs/enums.php` for user-facing Czech; code, tests and docs in English.
**Apply to:** every limit/TTL/size value and every panel string.

### Fictional data
**Source:** `.claude/CLAUDE.md` Repository Hygiene. Applies to the committed spec (`servers` URL literal `https://kokpit.example.com/api/v1`), fixtures and examples. Run `scripts/check-sensitive.sh` before commit.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `app/Http/Controllers/Api/V1/*` | controller | request-response | The repo has no HTTP controllers (Filament/Livewire only); use 07-RESEARCH Patterns 2, 3, 5 |
| `app/Http/Requests/Api/V1/*` | request | request-response | No FormRequests exist; validate shape only, rules stay in the Actions |
| `app/Http/Resources/Api/V1/*` | resource | transform | No API Resources exist; explicit allow-lists, no rates/amounts (D-10) |
| `app/Http/Middleware/EnsureIdempotency.php` | middleware | request-response | Only `SetNoReferrerPolicy` exists (trivial header middleware); algorithm is 07-RESEARCH Pattern 7 plus owner decisions above |
| `routes/api.php` | route | request-response | No API routes yet; Pattern 1 route groups with `auth:sanctum`, `abilities:time:read|time:write`, `throttle:api-read|api-write` |
| Scramble extensions, `config/scramble.php`, `docs/api/openapi.json`, drift test | config/test | transform | Greenfield; 07-RESEARCH Pattern 9 (docs UI stays on the CDN, `RestrictedDocsAccess` skips the gate in `APP_ENV=local`, test the gate in `testing`) |

## Metadata

**Analog search scope:** `app/Domain/{TimeTracking,Identity,Audit,Operations,Shared}`, `app/Filament/Resources`, `app/Providers`, `bootstrap`, `routes`, `config`, `lang/cs`, `database/migrations`, `tests/{Arch,Feature,Isolation,Support}`
**Files scanned:** about 45 (read in full or in targeted ranges)
**Pattern extraction date:** 2026-10-14
