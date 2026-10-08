---
phase: 03-operations-foundation
reviewed: 2026-10-08T00:00:00Z
depth: standard
files_reviewed: 88
files_reviewed_list:
  - .github/workflows/deploy.yml
  - .github/workflows/hygiene.yml
  - app/Console/Commands/DeployVerifyCommand.php
  - app/Console/Commands/StorageCheckCommand.php
  - app/Domain/Audit/ActivitySource.php
  - app/Domain/Audit/ActivitySourceLabel.php
  - app/Domain/Audit/KokpitLogActivityAction.php
  - app/Domain/Audit/LoggedAttributes.php
  - app/Domain/Audit/LogsAllowlistedActivity.php
  - app/Domain/Audit/RefusingCleanActivityLogAction.php
  - app/Domain/Operations/Alerts/AdminAlerter.php
  - app/Domain/Operations/Alerts/OperationalAlert.php
  - app/Domain/Operations/Alerts/ReportFailedJob.php
  - app/Domain/Operations/Health/HealthIndicator.php
  - app/Domain/Operations/Health/HealthIndicatorRegistry.php
  - app/Domain/Operations/Health/HealthResult.php
  - app/Domain/Operations/Health/HealthSlot.php
  - app/Domain/Operations/Health/HealthStatus.php
  - app/Domain/Operations/Health/Heartbeats.php
  - app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php
  - app/Domain/Operations/Health/Indicators/OldestPendingJobIndicator.php
  - app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php
  - app/Domain/Operations/Health/Indicators/SchedulerHeartbeatIndicator.php
  - app/Domain/Operations/Jobs/Idempotent.php
  - app/Domain/Operations/Jobs/KokpitJob.php
  - app/Domain/Operations/Jobs/Middleware/RunsAsSystem.php
  - app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php
  - app/Domain/Operations/Storage/StorageCheck.php
  - app/Domain/Operations/Storage/StorageCheckStep.php
  - app/Domain/Settings/Banking/BankAccount.php
  - app/Domain/Settings/Banking/BankAccountFormat.php
  - app/Domain/Settings/Banking/Iban.php
  - app/Domain/Settings/Casts/BankAccountListCast.php
  - app/Domain/Settings/Casts/MoneySettingsCast.php
  - app/Domain/Settings/Numbering/DocumentKind.php
  - app/Domain/Settings/Numbering/DocumentNumbering.php
  - app/Domain/Settings/Numbering/InvalidNumberPattern.php
  - app/Domain/Settings/Numbering/NumberPattern.php
  - app/Domain/Settings/Numbering/ResetPeriod.php
  - app/Domain/Settings/Rules/IbanRule.php
  - app/Domain/Settings/Rules/KnownCurrency.php
  - app/Domain/Settings/Rules/NumberPatternRule.php
  - app/Domain/Settings/Rules/UniqueCurrencies.php
  - app/Domain/Settings/Settings/BankAccountSettings.php
  - app/Domain/Settings/Settings/DefaultsSettings.php
  - app/Domain/Settings/Settings/InvoicingSettings.php
  - app/Domain/Settings/Settings/NumberingSettings.php
  - app/Domain/Settings/Settings/PaymentSettings.php
  - app/Domain/Settings/Settings/SupplierSettings.php
  - app/Domain/Settings/Settings/ValidatedSettings.php
  - app/Domain/Settings/SettingsMigration.php
  - app/Domain/Settings/VatMode.php
  - app/Domain/Shared/Models/SettingsProperty.php
  - app/Domain/Shared/Money/Money.php
  - app/Domain/Shared/Sequences/SequenceAllocator.php
  - app/Filament/Concerns/EnforcesPageAccessRule.php
  - app/Filament/Concerns/EnforcesRelationManagerAccessRule.php
  - app/Filament/Concerns/EnforcesResourceAccessRule.php
  - app/Filament/Concerns/EnforcesWidgetAccessRule.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Pages/SystemPage.php
  - app/Filament/RelationManagers/ActivityHistoryRelationManager.php
  - app/Filament/Resources/ActivityResource.php
  - app/Filament/Resources/ActivityResource/Pages/ListActivities.php
  - app/Filament/Support/ActivityPresenter.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/OperationsServiceProvider.php
  - app/Support/ProductionConfigGuard.php
  - bootstrap/app.php
  - bootstrap/providers.php
  - config/activitylog.php
  - config/filesystems.php
  - config/kokpit.php
  - config/queue.php
  - config/settings.php
  - database/migrations/2026_10_08_000100_create_settings_table.php
  - database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php
  - database/migrations/2026_10_08_000300_make_notifications_data_jsonb.php
  - database/settings/2026_10_08_000110_create_supplier_settings.php
  - database/settings/2026_10_08_000120_create_defaults_settings.php
  - database/settings/2026_10_08_000130_create_invoicing_settings.php
  - database/settings/2026_10_08_000140_create_payment_settings.php
  - database/settings/2026_10_08_000150_create_bank_account_settings.php
  - database/settings/2026_10_08_000160_create_numbering_settings.php
  - resources/views/filament/pages/system-page.blade.php
  - routes/console.php
  - zerops.yml
findings:
  critical: 1
  warning: 8
  info: 4
  total: 13
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-10-08
**Depth:** standard
**Files Reviewed:** 88
**Status:** issues_found

## Summary

Phase 3 (operations foundation) was reviewed at standard depth: the deploy workflow and manifest, the readiness and storage commands, the activity-log allowlist, the failed-job alerting, the health indicators and System page, the settings classes (supplier, bank, numbering, defaults), IBAN, Money and the sequence allocator.

Overall the design is careful. Partner isolation is enforced on the data layer (fail-closed `PartnerScope`, `SettingsProperty` denies Partners, `AdminOnlyPolicy` on the activity log) and on the UI layer (`#[AccessRule]` traits with boot hooks). The deploy workflow takes event data through `env` only, pins actions and tools by SHA or checksum, and splits the unprivileged `verify` job from the `production` environment job. The storage check never prints the signed URL, and the activity presenter renders only the allowlisted `attribute_changes`. I traced the following and found no defect: the `ActivitySource` job-depth bookkeeping against the Laravel worker event flow, SequenceAllocator locking, mod-97 IBAN arithmetic and the IBAN length table, `Money::fromMajor`/`fromExactMinor` rounding, `SerializesModels` restoration (it runs without global scopes, so the fail-closed scope does not break model-bearing jobs), and the access trait boot hooks.

One blocker was found. The failed-job alert runs synchronously, ahead of the framework's `failed_jobs` write, so a slow mail transport can cost the failure record itself. The warnings concern an access-rule bypass path in one trait, unsanitised exception text in alerts, production config guards that miss mail and URL settings, an unbounded heartbeat backlog, deploy trigger scope, cross-pattern number collisions and proxy trust.

## Critical Issues

### CR-01: Failed-job alert is delivered synchronously before the `failed_jobs` row is written, so a slow mail transport can lose the failure record

**File:** `app/Domain/Operations/Alerts/ReportFailedJob.php:27-43`, `app/Providers/OperationsServiceProvider.php:74`, `app/Domain/Operations/Alerts/AdminAlerter.php:98-106`
**Issue:** `Job::fail()` deletes the job from Redis and then dispatches `JobFailed`. The row in `failed_jobs` is written by a listener that `queue:work` (`WorkCommand::listenForEvents`) registers when the command starts, which is after `OperationsServiceProvider::boot()` registered `ReportFailedJob`. Listeners run in registration order, so the alert runs first. It sends the database row and then the mail synchronously (`Notification::sendNow`) for every Admin. The worker's SIGALRM timeout handler (job timeout 60 s plus sleep 3 s, counted from the start of the job) kills the process when it fires. If the SMTP transport hangs (the Symfony default socket timeout is 60 s, and the loop runs once per Admin), the process is killed inside the listener. The job is already deleted from the queue and `failed_jobs` was never written, so the job and its failure record are both lost, and no alert is raised. A mail outage is exactly when jobs that call external services fail in bursts. `AdminAlerter::alert()` catches exceptions, but it cannot catch a hang or a kill. The docblock promise "must still reach failed_jobs" does not hold in this scenario.
**Fix:** Do not do slow I/O before the framework logged the failure. Options, best first:
```php
// 1. Defer the delivery until after the current unit of work.
public function handle(JobFailed $event): void
{
    $alert = $this->buildAlert($event);   // pure, no I/O

    \Illuminate\Support\defer(static fn () => app(AdminAlerter::class)->alert('failed-job:'.$name, $alert));
}
```
If `defer()` does not run inside the worker loop for your Laravel version, write the database bell row inline (a fast single insert, already first in `CHANNELS`) and deliver the mail from a short, bounded step, for example by setting a low `timeout` on the mailer transport used for alerts (`config('mail.mailers.smtp.timeout')` of 5 to 10 s). Add a test that fakes a transport that sleeps beyond the job timeout and asserts that the `failed_jobs` row still exists.

## Warnings

### WR-01: `EnforcesPageAccessRule` boot hook calls the overridable `canAccess()`, so a page can widen its own `#[AccessRule]`

**File:** `app/Filament/Concerns/EnforcesPageAccessRule.php:22-30`
**Issue:** The trait defines `canAccess()` and the boot hook calls `static::canAccess()`. A concrete page that overrides `canAccess()` (a very common Filament pattern) replaces the trait method (class methods win over trait methods), and the boot hook then calls the override. A page declared `Audience::AdminOnly` that returns `true` from its own `canAccess()` is reachable by a Partner, and the "cannot be widened" guarantee that `EnforcesRelationManagerAccessRule` and `EnforcesWidgetAccessRule` give is missing here. These two traits ask `AccessRules::allows(static::class)` directly in the boot hook. `SettingsPage` and `SystemPage` do not override it today, so nothing is exploitable yet, but the next page author gets a silent bypass of the rule that protects settings, bank accounts and the System page.
**Fix:**
```php
public function bootEnforcesPageAccessRule(): void
{
    abort_unless(AccessRules::allows(static::class) && static::canAccess(), 403);
}
```
Add an architecture test that fails when a class using the trait declares its own `canAccess` without the declaration check.

### WR-02: Alert text is truncated, not sanitised; exception messages can still carry infrastructure details and data values into mail and the bell

**File:** `app/Domain/Operations/Alerts/ReportFailedJob.php:59-65`, `config/kokpit.php:43-45`
**Issue:** The docblock and the config comment state that messages "can carry SQL values or connection details, so the alert never holds more". The code only keeps the first line, cut to 200 characters. A connection failure message starts with the database host and port (`SQLSTATE[08006] ... connection to server at "<host>" (<ip>), port <port> failed ...`), a unique violation names the constraint and, in the DETAIL line or in the same line for some drivers, the key value, and a `QueryException` message embeds the SQL and bindings. All of this lands in plain text in an e-mail (leaves the infrastructure) and in the `notifications` table. The comment overstates the protection.
**Fix:** Do not forward the message for exceptions that are known to carry infrastructure text. Send the class name and the failed job id only, and leave the message in `failed_jobs`:
```php
__('kokpit.alerts.failed_job.error', ['class' => $event->exception::class, 'message' => '']),
```
or strip anything that looks like a host, IP, `SQL:` tail or `Key (...)` before sending, and correct the comment to describe what is really done.

### WR-03: Production guard does not catch a mailer of `log` or a missing `APP_URL`, so alert mails can be silently "sent" to the log and alert links can point at localhost

**File:** `app/Support/ProductionConfigGuard.php:16-52`, `config/mail.php:19`, `zerops.yml:65-90`, `app/Domain/Operations/Alerts/ReportFailedJob.php:73-75`
**Issue:** `config/mail.php` defaults `MAIL_MAILER` to `log`, and no setup in `zerops.yml` sets `MAIL_*` or `APP_URL` (they are meant to come from project secrets). If the operator forgets the mailer, every Admin alert mail is accepted by the `log` transport and written to syslog: no exception, no warning, the alert channel the phase exists for is dead and nobody knows. If `APP_URL` is missing, the `route('filament.admin.pages.system')` link built in the worker (console context, no request) points at `http://localhost`. The guard already refuses other production-unsafe values (2FA off, canary on, audit off, sync queue), so these two belong there.
**Fix:**
```php
if (in_array($config->get('mail.default'), ['log', 'array', null], true)) {
    throw new RuntimeException('Refusing to boot in production: MAIL_MAILER must be a real transport; the Admin alert mail would only be logged.');
}

$url = (string) $config->get('app.url');
if ($url === '' || str_contains($url, 'localhost') || ! str_starts_with($url, 'https://')) {
    throw new RuntimeException('Refusing to boot in production: APP_URL must be the public https URL.');
}
```
Also state in `zerops.yml` that `APP_KEY`, `APP_URL` and the `MAIL_*` values must be project-level (not per-service) variables, because the worker and the scheduler need them too.

### WR-04: The scheduled worker heartbeat job piles up without bound while the worker is down

**File:** `routes/console.php:22-24`, `app/Domain/Operations/Jobs/RecordWorkerHeartbeat.php:15-21`
**Issue:** `Schedule::job(new RecordWorkerHeartbeat)->everyMinute()` queues a new job every minute with no uniqueness and no expiry. During a worker outage of one day the queue grows by 1440 identical jobs, and on recovery the worker runs all of them (each writes "now", so the heartbeat is correct but the backlog delays real jobs). The outage is the one situation the indicator is built for. A multi-hour outage also inflates the Redis list and the `Heartbeats` "oldest pending" reading for no benefit.
**Fix:** Make the job unique and short-lived:
```php
final class RecordWorkerHeartbeat extends KokpitJob implements ShouldBeUnique
{
    public int $uniqueFor = 120;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(2);   // a stale heartbeat is worthless
    }
}
```
A unique lock needs the cache store to be shared by scheduler and worker, which it is (Redis).

### WR-05: Deploy can be started from any ref and does not require a green CI run for the commit

**File:** `.github/workflows/deploy.yml:14-17`, `.github/workflows/deploy.yml:66-73`
**Issue:** `workflow_dispatch` can be started from any branch or tag by anybody with write access. The `verify` job only requires `GITHUB_SHA` to be an ancestor of `origin/main`. Dispatching from an old, already merged branch therefore deploys an arbitrary older commit of `main`: older code against a database that migrations only move forward, and possibly an older, weaker `deploy.yml` (the workflow file that runs is the one of the dispatched ref). Neither path checks that the `CI Passed` status exists and is green for the commit, so a release published on a `main` commit whose Hygiene run failed or never ran is deployed, with the human reviewer of the `production` environment as the only gate.
**Fix:** Pin the dispatch to the default branch or a release tag, and require the CI result:
```yaml
      - name: Refuse dispatch from other refs
        if: ${{ github.event_name == 'workflow_dispatch' }}
        env:
          REF: ${{ github.ref }}
        run: |
          case "${REF}" in
            refs/heads/main|refs/tags/v*) ;;
            *) echo "::error::Dispatch only from main or a v* tag."; exit 1 ;;
          esac
```
and, with `checks: read` on the `verify` job, query the check runs of `GITHUB_SHA` via `gh api` and fail unless `CI Passed` concluded `success`.

### WR-06: Changing the number pattern can produce a number that an earlier pattern already issued

**File:** `app/Domain/Settings/Numbering/NumberPattern.php:155-159` and `:186-190`, `app/Filament/Pages/SettingsPage.php:334-336`
**Issue:** The counter series is chosen by the reset period only (`invoice:2026`, `invoice:2026-10`, `invoice:all`), not by the pattern text. Two different patterns with different widths can therefore write the same string from different counters. Concrete case: yearly `{YY}{NNNN}` with the 2026 counter at 1010 writes `261010`; after the Admin switches to monthly `{YY}{MM}{NN}`, October 2026 counter 10 also writes `261010`. The editor warns only about a reset-period change, never about a collision. The allocator stays gap-free, but when a unique constraint on the invoice number arrives, issuing fails mid-flow and cannot succeed until the pattern is changed back. The same holds for proformas and credit notes.
**Fix:** Include a stable identity of the number shape in the scope key (for instance a short hash of the normalised token layout, so `{YYYY}` and `{YY}` can still be declared equivalent on purpose), or refuse a pattern change once numbers of that kind exist unless the new pattern's output space is provably disjoint, or at least check on issue that the formatted number does not exist yet and raise a clear error before the allocator increments.

### WR-07: `trustProxies(at: '*')` trusts every hop for all forwarded headers, including `X-Forwarded-Host`

**File:** `bootstrap/app.php:19`
**Issue:** The comment assumes the containers are reachable only through the balancer. Any other path to the container (another service in the Zerops project, a misrouted internal port, a future change) lets a client spoof `X-Forwarded-For` (which defeats throttling and IP-based audit data), `X-Forwarded-Proto` and `X-Forwarded-Host`. The last one changes every URL Laravel generates from the request, such as links in mails and Filament redirects. Trusting the host header is not needed for the stated reason (making generated URLs `https`).
**Fix:** Restrict the headers, and the proxies if Zerops publishes a range:
```php
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
);
```
and set `URL::forceRootUrl(config('app.url'))` in production so the host is never taken from the request.

### WR-08: `Money::convert` accepts negative and zero exchange rates

**File:** `app/Domain/Shared/Money/Money.php:239`
**Issue:** The rate pattern `/^-?\d+(\.\d{1,10})?$/D` allows `-1.5` and `0`. A zero rate silently converts every amount to zero, a negative rate flips the sign of a billed amount. Rates come from an external source (central bank import, Phase 8) and are persisted as invoice snapshots, so a bad row would be turned into a wrong, immutable invoice total without any error. The method's own comment says nothing about negative rates being valid.
**Fix:**
```php
if (preg_match('/^\d+(\.\d{1,10})?$/D', $decimalRate) !== 1 || BigDecimal::of($decimalRate)->isZero()) {
    throw new InvalidArgumentException('The exchange rate must be a positive decimal string with at most ten fraction digits.');
}
```

## Info

### IN-01: Supplier rules are weaker at the data layer than the other settings rules

**File:** `app/Domain/Settings/Settings/SupplierSettings.php:53-58`
**Issue:** `country`, `company_id` and `vat_id` use regular expressions without the `D` modifier (every other pattern in the phase uses it), so `12345678\n` and `CZ\n` pass the data-layer check and would reach printed invoices. `website` uses the plain `url` rule, which accepts non-web schemes; the value is printed as a link on documents. Text fields are not trimmed in `fillFromFormState`, so a stray space in `company_name` is stored and snapshotted. `website`, `email` and `company_name` have no sensible bounds for a snapshotted value (`email` and `website` have no `max`).
**Fix:** Use `/^\d{8}$/D`, `/^[A-Z]{2}$/D`, `/^[A-Z]{2}[0-9A-Z]{2,13}$/D`, `'url:http,https'`, add `max:255` to `email` and `website`, and trim string values in `ValidatedSettings::fillFromFormState`.

### IN-02: The alert recipient lookup needs the database, so a database outage sends no mail

**File:** `app/Domain/Operations/Alerts/AdminAlerter.php:88-96`
**Issue:** The channels are isolated from each other, but the Admin list is read with `User::query()->role(...)`. When the database is the failing component, `deliver()` throws before any channel runs, and the only trace is a `Log::critical` line in syslog. This is the incident class where the Admin most needs the mail. A related effect: the throttle window is claimed before delivery, so a failed delivery also suppresses the next 15 minutes of alerts for that job class.
**Fix:** Offer a fallback recipient from configuration (`kokpit.alerts.fallback_mail`, set from an environment variable) used when the lookup throws, and release the throttle key when no channel succeeded.

### IN-03: Time zone `Europe/Prague` and one provider constant are hard-coded in several domain classes

**File:** `app/Domain/Settings/Numbering/NumberPattern.php:50`, `app/Domain/Shared/Sequences/SequenceAllocator.php:96`, `app/Filament/Pages/SystemPage.php:74`, `app/Domain/Operations/Health/Indicators/OldestPendingJobIndicator.php:12` and `:87`
**Issue:** The business time zone appears as a string literal in four places (the invoice year boundary is legal logic and should have one definition). `OldestPendingJobIndicator` in the domain layer imports `App\Providers\LocalisationServiceProvider` only for a format constant, which points the dependency from the domain at a provider.
**Fix:** One `config('app.business_timezone')` (or a constant on a small value class) read by all of them, and the display format constant moved to a support class next to the domain.

### IN-04: `kokpit:deploy:verify` does not assert the Redis ping result and does not cover the session connection

**File:** `app/Console/Commands/DeployVerifyCommand.php:122-133`
**Issue:** `Redis::connection($connection)->ping()` is called for its side effect. A client that answers a failure with a falsy value instead of an exception passes the gate. The session driver is `redis` in `zerops.yml`, but `session.connection` is not part of the connection list, so a session-only misconfiguration passes the readiness check and every login fails after the switch.
**Fix:** Add `config('session.connection')` to the list and treat a falsy ping result as a failure:
```php
if (! Redis::connection($connection)->ping()) {
    throw new RuntimeException('Redis ping failed');
}
```

---

_Reviewed: 2026-10-08_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
