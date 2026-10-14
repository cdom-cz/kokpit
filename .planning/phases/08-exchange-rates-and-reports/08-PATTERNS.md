# Phase 8: Exchange Rates and Reports - Pattern Map

**Mapped:** 2026-10-14
**Files analyzed:** 38 new/modified (grouped where the pattern is identical)
**Analogs found:** 36 / 38 (all analog paths verified as git-tracked under `app/`, `routes/`, `database/`)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `database/migrations/2026_10_15_*_create_exchange_rates_table.php` | migration | CRUD | `database/migrations/2026_10_14_000300_create_api_idempotency_keys_table.php` | exact |
| `database/migrations/*_create_report_exports_table.php` | migration | CRUD | same | exact |
| `app/Domain/ExchangeRates/Models/ExchangeRate.php` | model | CRUD | `app/Domain/Projects/Models/ProjectBilling.php` (DeniesPartners) + `app/Domain/Api/Models/IdempotencyKey.php` (casts) | role-match |
| `app/Domain/Reports/Exports/ReportExport.php` | model | CRUD | `app/Domain/Api/Models/IdempotencyKey.php` + `ProjectBilling` | role-match |
| `app/Domain/ExchangeRates/CnbClient.php` | service | request-response | `app/Domain/Clients/Ares/AresClient.php` | exact |
| `app/Domain/ExchangeRates/Actions/DownloadCnbRates.php` | action | batch | `app/Domain/TimeTracking/Actions/EndOverrunTimers.php` | role-match |
| `app/Console/Commands/DownloadCnbRatesCommand.php` | command | batch | `app/Console/Commands/EndOverrunTimersCommand.php` | exact |
| `routes/console.php` (modify) | config | event-driven | itself, `kokpit-end-overrun-timers` entry | exact |
| `app/Domain/ExchangeRates/Jobs/BackfillCnbRates.php` | job | batch | `app/Domain/Api/Jobs/PurgeExpiredIdempotencyKeys.php` + `NotifyLongRunningTimers` | exact |
| `app/Domain/Reports/Exports/BuildReportExport.php` | job | file-I/O | `NotifyLongRunningTimers` (notify in transaction) | role-match |
| `app/Domain/Reports/Exports/PruneExpiredReportExports.php` | job | batch | `PurgeExpiredIdempotencyKeys` | exact |
| `app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php` | health indicator | request-response | `app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php` | exact |
| `app/Providers/OperationsServiceProvider.php` (modify `$real`) | provider | config | itself lines 43-47 | exact |
| `app/Providers/AccessServiceProvider.php` (modify) | provider | config | itself, `Gate::policy(...AdminOnlyPolicy)` | exact |
| `app/Domain/Shared/Money/Money.php` (add `hourlyRateFor`, `forDurationConverted`) | utility | transform | `Money::convert` and `Money::forDurations` | exact |
| `app/Domain/ExchangeRates/{RateBook,CnbConverter,ExchangeRateMissing,CzechPublicHolidays}.php` | service/utility | transform | `Money::convert` (call target); `AresFailure`/`AresLookupFailed` for typed exception | partial |
| `app/Domain/Reports/Queries/TimeReportReader.php` | query | CRUD (read) | `app/Domain/TimeTracking/Queries/TimeTotals.php` + `TimesheetQuery.php` | role-match |
| `app/Domain/Reports/{ReportFilters,EntryAmounts,TimeReport}.php` | utility | transform | `TimesheetPage` URL validation + `PragueDay` | partial |
| `app/Domain/Reports/Exports/{SpreadsheetCells,CsvWriter,XlsxWriter}.php` | utility | file-I/O | none in repo (RESEARCH Pattern 6) | none |
| `app/Domain/Reports/WorkReport/{WorkReportPdf,WorkReportSource}.php` | service | transform | none in repo (RESEARCH Pattern 7, 03-SPIKE-PDF.md) | none |
| `app/Filament/Pages/TimeReportPage.php` | page | request-response | `app/Filament/Pages/TimesheetPage.php` | exact |
| `resources/views/filament/pages/time-report.blade.php` | view | request-response | `resources/views/filament/pages/timesheet.blade.php`, `system-page.blade.php` | role-match |
| `app/Filament/Resources/ExchangeRateResource.php` (+Pages) | resource | CRUD (list + actions) | `app/Filament/Resources/ApiTokenResource.php` | exact |
| `app/Filament/Resources/ReportExportResource.php` (+Pages) | resource | CRUD (list + download) | `ApiTokenResource.php` | exact |
| `app/Filament/Pages/Dashboard.php` (modify) | page | request-response | itself | exact |
| `app/Filament/Widgets/{HoursStats,UnbilledPerClient,UpcomingDeadlines,TomorrowPlan}.php` | widget | request-response | `app/Filament/Concerns/EnforcesWidgetAccessRule.php` (trait) + `TimesheetQuery::summary` | partial (no widget exists) |
| `app/Filament/Widgets/{PartnerProjects,PartnerTasks}.php` | widget | request-response | `app/Filament/Partner/Resources/PartnerTaskResource.php` | role-match |
| `lang/cs/kokpit.php`, `lang/{cs,en}/work_report.php` (modify/new) | config | n/a | existing `kokpit.php` keys (`kokpit.dashboard.*`, `kokpit.time.*`) | exact |
| `config/kokpit.php`, `config/services.php`, `.env.example`, `composer.json`, `scripts/check-licenses.php` (modify) | config | n/a | existing blocks | exact |
| Tests (Wave 0) | test | n/a | `tests/Feature/Operations/{ScheduleOnOneServerTest,HealthIndicatorsTest,AdminAlertTest,HealthRegistryTest}.php`, `tests/Arch/JobContractTest.php`, `tests/Isolation/PanelAccessTest.php` | role-match |

## Pattern Assignments

### Migrations: `exchange_rates`, `report_exports` (migration, CRUD)

**Analog:** `database/migrations/2026_10_14_000300_create_api_idempotency_keys_table.php`

UUID v7 default, `timestampTz(.., 0)`, named unique, CHECKs by `DB::statement` after `Schema::create`, `down()` drops table, header docblock explaining invariants:
```php
Schema::create('api_idempotency_keys', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
    $table->timestampTz('expires_at', 0)->index();
    $table->timestampsTz();
    $table->unique(['token_id', 'key'], 'api_idempotency_keys_token_key_unique');
});
DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_response_check CHECK (...)');
```
Apply: `exchange_rates` unique `(currency, rate_date)` named `exchange_rates_currency_date_unique`, `rate numeric(20,10)`, CHECKs `rate > 0`, `unit_amount >= 1`, `currency ~ '^[A-Z]{3}$'`, index on `rate_date`. `report_exports`: `foreignUuid('user_id')`, `expires_at` indexed (prune), generated uuid path, no user text in path. Migration filename timestamps follow `2026_10_NN_NNNNNN_` (latest is `2026_10_14_000300`).

### `ExchangeRate` and `ReportExport` models (model, CRUD)

**Analogs:** `app/Domain/Projects/Models/ProjectBilling.php` (lines 7-14 imports, `DeniesPartners` + `PartnerIsolated`, `@property` docblock), `app/Domain/Api/Models/IdempotencyKey.php` (lines 8-45).

```php
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
// ExchangeRate: final class ... extends KokpitModel implements PartnerIsolated { use DeniesPartners; }
protected function casts(): array
{
    return ['rate_date' => 'immutable_date', 'fetched_at' => 'immutable_datetime', 'rate' => 'decimal:10', 'unit_amount' => 'integer'];
}
```
`ReportExport` is user-owned (`OwnedByUser` per RESEARCH Security table; locate the trait with `grep -rn OwnedByUser app/Domain/Shared`). Alternative for infrastructure-only models: `#[NotPartnerScoped(reason: '...')]` as on `IdempotencyKey`. Register: `Gate::policy(ExchangeRate::class, AdminOnlyPolicy::class)` in `AccessServiceProvider` (lines 59-97 pattern), `MorphMap::MAP` entries, `tests/Arch/ModelDeclarationTest.php` list.

### `CnbClient` (service, request-response)

**Analog:** `app/Domain/Clients/Ares/AresClient.php` (lines 53-95)

Typed failure enum + exception (`AresFailure`, `AresLookupFailed`) so callers never see raw exception text. Copy HTTP block:
```php
$response = Http::acceptJson()
    ->connectTimeout(3)->timeout(5)
    ->retry(3, 200,
        when: static fn (Throwable $e): bool => $e instanceof ConnectionException
            || ($e instanceof RequestException && $e->response->serverError()),
        throw: false)
    ->get(rtrim((string) config('services.ares.base_url'), '/').'/'.$companyNumber);
// catch (ConnectionException) { throw new AresLookupFailed(AresFailure::Unavailable); }
// if (! $response->successful()) -> failure enum; non-array json -> Malformed
```
Change: `config('services.cnb.base_url')`, timeout 15, quote `"rate"` before `json_decode` (RESEARCH Pattern 1), store under `validFor`. Skip the per-user RateLimiter/Cache (not applicable). Add `services.cnb` block next to `services.ares` in `config/services.php`.

### `DownloadCnbRatesCommand` (command, batch)

**Analog:** `app/Console/Commands/EndOverrunTimersCommand.php` (full file)

```php
class EndOverrunTimersCommand extends Command
{
    protected $signature = 'kokpit:time:end-overrun';
    public function handle(EndOverrunTimers $action): int
    {
        $count = app(PartnerContext::class)->runAsSystem(fn (): int => $action->handle());
        $this->components->info(__('kokpit.overrun_timers.done', [...]));
        return self::SUCCESS;
    }
}
```
Apply: signature `kokpit:rates:download {--final}`, wrap in `runAsSystem`, output counts/dates only. On `--final` failure call `AdminAlerter::alert('cnb-rates:'.$day, new OperationalAlert($title, $body, $overviewUrl))` (ctor: `title`, `body`, `?actionUrl`; `AdminAlerter::alert` never throws, sends database then mail via `sendNow`, throttled). No raw exception text in body (see `AlertMessageSanitiser`).

### `routes/console.php` schedule entries (config, event-driven)

**Analog:** same file, lines for `kokpit-end-overrun-timers`:
```php
Schedule::command('kokpit:time:end-overrun')
    ->hourly()
    ->name('kokpit-end-overrun-timers')
    ->onOneServer();
```
Add `kokpit-cnb-rates` (`->cron(...)->timezone('Europe/Prague')`, hours from `config('kokpit.rates.schedule')`), `kokpit-cnb-rates-final`, `kokpit-report-export-prune` (`->daily()`, `Schedule::job(new PruneExpiredReportExports)`). Every event needs `name()` and `onOneServer()` (`ScheduleOnOneServerTest`).

### `BackfillCnbRates`, `PruneExpiredReportExports`, `BuildReportExport` (jobs)

**Analogs:** `app/Domain/Api/Jobs/PurgeExpiredIdempotencyKeys.php` (whole file, ideal for prune) and `app/Domain/TimeTracking/Jobs/NotifyLongRunningTimers.php` (notify inside `DB::transaction`).

```php
#[Idempotent(how: 'deletes rows past expires_at; a second run finds nothing to delete')]
final class PurgeExpiredIdempotencyKeys extends KokpitJob
{
    public function handle(): void
    {
        IdempotencyKey::query()->where('expires_at', '<', now())->delete();
    }
}
```
Rules from `KokpitJob` docblock: every concrete job has `#[Idempotent(how: ...)]`, identifies work by natural key (range, export id), check-then-act, unique constraint as last defence; handle() already runs as system via `RunsAsSystem`. Backfill/export need `#[Timeout(240)]` (attribute from `Illuminate\Queue\Attributes\Timeout`, below supervisor 300; `HorizonConfigTest`), stream with `lazy()`. Notify the Admin by `$owner->notifyNow(new XxxNotification(..., url: ...))` as in `NotifyLongRunningTimers` lines 66-80 (notification class modelled on `app/Domain/TimeTracking/Notifications/LongRunningTimerNotification.php`). Update `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php:28`. The daily download is a command, not a job (RESEARCH open question 2).

### `LastRateDateIndicator` (health indicator, request-response)

**Analog:** `app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php`

```php
final readonly class FailedJobsIndicator implements HealthIndicator
{
    public function slot(): HealthSlot { return HealthSlot::FailedJobs; }
    public function check(): HealthResult
    {
        ...
        return new HealthResult(HealthStatus::Warning, (string) $count, __('kokpit.system.failed_jobs.some'));
    }
}
```
Apply: `slot()` returns `HealthSlot::LastRateDate`; thresholds from `config('kokpit.health...')` read with `max(1, (int) config(...))`; Czech detail via `__('kokpit.system.last_rate_date.*')`, never exception text. Register in `OperationsServiceProvider` `$real` array (lines 43-47; `missingSlots()` then skips the placeholder). Edit `tests/Feature/Operations/HealthRegistryTest.php:116`.

### `Money` additions (utility, transform)

**Analog:** `app/Domain/Shared/Money/Money.php` `convert()` (lines ~237-262) and `forDurations()` (lines ~203-221)

Single rounding point: build an exact `BigRational`, call `self::fromExactMinor($exact->toString(), $currency)` once. Rate validation to reuse verbatim:
```php
if (preg_match('/^\d+(\.\d{1,10})?$/D', $decimalRate) !== 1 || BigDecimal::of($decimalRate)->isZero()) { throw new InvalidArgumentException(...); }
$sourceDigits = Currency::of($this->currency)->getDefaultFractionDigits();
$exact = BigRational::of($this->minor)->dividedBy(BigInteger::ten()->power($sourceDigits))
    ->multipliedBy(BigDecimal::of($decimalRate))->dividedBy($unitAmount)
    ->multipliedBy(BigInteger::ten()->power($targetDigits));
```
Brick must stay inside this class (`tests/Arch/MoneyBoundaryTest.php`). `forDurationConverted` = `forDurations`' `rate*seconds/3600` fraction times the `convert` factors, rounded once. `hourlyRateFor` = `BigRational::ofFraction(minor*3600, seconds)`.

### `TimeReportReader` (query, read)

**Analogs:** `app/Domain/TimeTracking/Queries/TimeTotals.php`, `TimesheetQuery.php`; helpers `TimeEntry::elapsedSecondsSql('te')` (needs bound `now`, format `$now->utc()->format('Y-m-d H:i:sP')`), `PragueDay::start()/endExclusive()/day()`. Rate-chain specification: `app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php` (parity test). Guard with `PartnerContext` admin-or-system as the resolver does. Eloquent/query builder only: `DB::table(` is banned in `app/` (`QueryEscapeHatchTest`), so build the joins with `TimeEntry::query()->join(...)->selectRaw(...)`. Read the exact join/COALESCE SQL from RESEARCH Pattern 5.

### `TimeReportPage` (page, request-response)

**Analog:** `app/Filament/Pages/TimesheetPage.php` (lines 1-80)

```php
#[AccessRule(Audience::AdminOnly, reason: '...closed to Partners.')]
class TimeReportPage extends Page implements HasTable   // table optional; custom Blade tree per RESEARCH
{
    use EnforcesPageAccessRule, InteractsWithTable;
    protected static ?string $slug = 'time-report';
    protected static ?int $navigationSort = 42;
    protected string $view = 'filament.pages.time-report';
    #[Url(as: 'view')] public string $mode = 'day';
    #[Url] public ?string $date = null;
    public static function getNavigationLabel(): string { return __('kokpit.time.timesheet.navigation'); }
    public static function getNavigationIcon(): Heroicon { return Heroicon::OutlinedCalendarDays; }
    public function getTitle(): string { ... }
}
```
Filters live in the URL (`#[Url]`) and are re-validated server-side every request (docblock lines 33-39); PHP property is client-controlled. Use inline `style=` and Filament components, not Tailwind utilities (no Node build; see `system-page.blade.php`). Choose a `$navigationSort` that does not collide (`grep -rn navigationSort app/Filament`).

### `ExchangeRateResource`, `ReportExportResource` (resource, list-only)

**Analog:** `app/Filament/Resources/ApiTokenResource.php` (lines 1-70)

```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ApiTokenResource extends Resource
{
    use EnforcesResourceAccessRule;
    protected static ?string $model = PersonalAccessToken::class;
    protected static ?string $slug = 'api-tokens';
    protected static ?int $navigationSort = 92;
    public static function getNavigationLabel(): string { return __('kokpit.api_tokens.navigation_label'); }
    public static function getNavigationGroup(): string { ... }
}
```
List page only, header actions on the List page class (`Filament\Actions\Action`, `Filament\Notifications\Notification`) for "Download today's rates" (sync, same `DownloadCnbRates` action) and "Backfill history" (form with from/to, dispatches `BackfillCnbRates`; validate range: `from >= 2000-01-01`, `to <= today`, span from config). Export download is a row `Action` returning a file response after re-checking ownership/permission; no new route (RouteWalkTest).

### Dashboard and widgets

**Analogs:** `app/Filament/Pages/Dashboard.php` (modify), `app/Filament/Concerns/EnforcesWidgetAccessRule.php` (trait, no widget exists yet; `app/Filament/Widgets/` must be created), `app/Filament/Partner/Resources/PartnerTaskResource.php` (Partner-only gating).

```php
// Dashboard.php: replace getWidgets(): array<never> with a role-specific list; delete the EmptyState content() or keep it only as the Partner empty state.
#[AccessRule(Audience::PartnerAllowed, reason: '...')]
class Dashboard extends BaseDashboard { use EnforcesPageAccessRule; }
```
Each widget: `#[AccessRule(Audience::AdminOnly, reason: '...')]` + `use EnforcesWidgetAccessRule;` (trait provides `canView()` and a boot `abort_unless(... , 403)`). Partner widgets declare `Audience::PartnerAllowed` and override `canView()` to be stricter, copying `PartnerTaskResource::canAccess()`:
```php
return AccessRules::allows(self::class) && parent::canAccess() && app(PartnerContext::class)->partnerClientId() !== null;
```
For widgets use `static::canView()` form: `AccessRules::allows(static::class) && partnerClientId() !== null`. Partner queries use scoped `Project::query()` / `Task::query()` through `ProjectColumns::partnerColumns()` / `TaskColumns::partnerColumns()` / `TaskColumns::orderByPriority`. Hours: `TimesheetQuery::summary`. Tomorrow plan: `SignalDayReader::tasks(SignalCalendar::tomorrow())` and `shadows()` (read-only). Edit `tests/Isolation/PanelAccessTest.php:52-61`.

### Shared: DeniesPartners / AdminOnlyPolicy registration
Every new model: `DeniesPartners` + `PartnerIsolated` (or `#[NotPartnerScoped(reason)]`), `Gate::policy(Model::class, AdminOnlyPolicy::class)` in `AccessServiceProvider`, entry in `ModelDeclarationTest`, `MorphMap::MAP`.

## Shared Patterns

### Access declaration (fail-closed)
**Source:** `TimesheetPage`, `ApiTokenResource`, `EnforcesWidgetAccessRule`
**Apply to:** every new Page, Resource, Widget (`#[AccessRule(Audience::AdminOnly, reason: ...)]` plus the matching `Enforces*AccessRule` trait). The registry test fails when missing.

### System context for console and jobs
**Source:** `EndOverrunTimersCommand` (`app(PartnerContext::class)->runAsSystem(...)`); jobs get it from `KokpitJob` `RunsAsSystem`.
**Apply to:** `DownloadCnbRatesCommand`, all jobs.

### Admin alert, queue-independent
**Source:** `app/Domain/Operations/Alerts/AdminAlerter.php` (`alert(string $throttleKey, OperationalAlert $alert)`, never throws), `OperationalAlert(title, body, ?actionUrl)`.
**Apply to:** final failure of CNB download only (not each hourly attempt).

### Idempotent writes
**Source:** `NotifyLongRunningTimers` (atomic claim) and `KokpitJob` docblock contract.
**Apply to:** `insertOrIgnore` on `exchange_rates` (`ExchangeRate::query()->insertOrIgnore($rows)`; never `DB::table`), backfill, export build.

### Day boundaries and durations
**Source:** `PragueDay::start/endExclusive/day`, `TimeEntry::elapsedSecondsSql`, `DurationFormat::hoursMinutes()`.
**Apply to:** reader, dashboard, PDF, exports. Never `date(started_at)` on UTC.

### Money
**Source:** `Money::forDuration`, `convert`, new methods. No float, no Brick outside `App\Domain\Shared\Money`.

### Config and Czech text
**Source:** `config/kokpit.php` (thresholds, documented inline), `lang/cs/kokpit.php`. Keep `.env.example` in sync with every new `env()` read (test enforces).

### Pre-commit
Fictional data only; CNB test fixtures use invented rates. Run `scripts/check-sensitive.sh`.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `app/Domain/Reports/Exports/{SpreadsheetCells,CsvWriter,XlsxWriter}.php` | utility | file-I/O | No spreadsheet writer in repo; use RESEARCH Pattern 6 (explicit `StringCell`, `'` prefix; never `Row::fromValues` with user text) |
| `app/Domain/Reports/WorkReport/*` and `resources/views/pdf/work-report.blade.php` | service/view | transform | Dompdf not installed; follow RESEARCH Pattern 7 and `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md` |
| `app/Filament/Widgets/*` | widget | request-response | Directory does not exist; only the access trait exists; Filament `StatsOverviewWidget`/`TableWidget` per RESEARCH Pattern 8 |
| `CzechPublicHolidays`, `RateBook` | utility | transform | New pure logic; spec in RESEARCH Code Examples and Pattern 3 |
| `lang/en/work_report.php` | config | n/a | `lang/en` does not exist yet |

## Metadata

**Analog search scope:** `app/Domain/{Operations,Clients,TimeTracking,Api,Projects,Shared,Signal}`, `app/Filament/{Pages,Resources,Partner,Concerns}`, `app/Console`, `app/Providers`, `routes`, `database/migrations`
**Files scanned:** about 30 read or grepped
**Pattern extraction date:** 2026-10-14
