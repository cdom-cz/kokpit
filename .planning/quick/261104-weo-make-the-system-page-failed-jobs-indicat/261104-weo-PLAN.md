---
phase: 261104-weo-make-the-system-page-failed-jobs-indicat
plan: 261104-weo
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php
  - tests/Feature/Operations/HealthIndicatorsTest.php
  - config/horizon.php
autonomous: true
requirements: [FND-09]

estimate:
  tokens: 40000
  raw_tokens: 40000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "When the default queue connection uses the redis driver and Horizon's JobRepository is bound, the System page Failed jobs slot shows Horizon's failed-job count (JobRepository::countFailed, counted within horizon.trim.failed minutes)"
    - "In that Redis + Horizon mode, rows in the failed_jobs table do not change the count; Horizon's store is the only source"
    - "When the default queue connection is not redis (sync in tests, database elsewhere), the count comes from the queue failer (CountableFailedJobProvider) exactly as before and Horizon is never asked"
    - "On a redis queue without a bound Horizon JobRepository, the indicator falls back to the queue failer"
    - "Warning still starts at kokpit.health.failed_jobs_warning_at (minimum 1) for both sources; a failer that cannot count is still NotAvailable, never Ok"
    - "The detail texts kokpit.system.failed_jobs.none / some / not_countable are unchanged and no file under lang/ changes"
  artifacts:
    - path: "app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php"
      provides: "Failed-jobs count from Horizon's Redis failed set on a Horizon Redis queue, from the queue failer otherwise"
      contains: "countFailed()"
    - path: "tests/Feature/Operations/HealthIndicatorsTest.php"
      provides: "Pest coverage of both sources, both fallbacks, the threshold on Horizon and the System page rendering"
      contains: "Laravel\\Horizon\\Contracts\\JobRepository"
    - path: "config/horizon.php"
      provides: "Comment above trim that names trim.failed as the window of the System page count on Redis"
      contains: "FailedJobsIndicator"
  key_links:
    - from: "app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php"
      to: "Laravel\\Horizon\\Contracts\\JobRepository"
      via: "app(JobRepository::class)->countFailed() when bound and queue.default uses the redis driver"
      pattern: "countFailed\\(\\)"
    - from: "app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php"
      to: "config/queue.php connections"
      via: "driver of the connection named by queue.default"
      pattern: "queue\\.connections\\."
    - from: "app/Providers/OperationsServiceProvider.php"
      to: "app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php"
      via: "registry registers new FailedJobsIndicator (no-arg constructor stays)"
      pattern: "new FailedJobsIndicator"
---

<objective>
Make the System page "Failed jobs" indicator count failures from the queue store actually in use (D-12 System page health indicators, FND-09 visible failure).

Today `FailedJobsIndicator::check()` always counts through `app('queue.failer')`, i.e. the `failed_jobs` table. With `QUEUE_CONNECTION=redis` and Horizon running, the Admin sees `0` / Ok while Horizon lists failed jobs. After this plan: on a redis-driver default connection with Horizon bound, the count comes from Horizon's Redis failed-job repository (`Laravel\Horizon\Contracts\JobRepository::countFailed()`, which counts only entries inside the `horizon.trim.failed` retention window); otherwise the queue failer path stays exactly as it is.

Purpose: the Admin must never see "Ok" while failed jobs wait for review.
Output: updated indicator, Pest tests for both sources and both fallbacks, corrected comment in `config/horizon.php`.

Out of scope (do not touch): the lang strings (already source-neutral), the registry wiring in `app/Providers/OperationsServiceProvider.php`, the queue failer configuration in `config/queue.php`, any migration, any new package. Do not try to diagnose why `failed_jobs` stays empty in production while Horizon records failures; that is reported back to the owner separately.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php
@app/Domain/Operations/Health/Indicators/OldestPendingJobIndicator.php
@tests/Feature/Operations/HealthIndicatorsTest.php
@config/horizon.php

<interfaces>
Facts the executor needs, extracted from the codebase and vendor (laravel/horizon v5.50.1, laravel/framework ^13):

- `Laravel\Horizon\Contracts\JobRepository` is bound by Horizon's service provider (vendor/laravel/horizon/src/ServiceBindings.php) to `Laravel\Horizon\Repositories\RedisJobRepository`. Horizon is a production dependency (composer.json `require`), auto-discovered, so in tests `app()->bound(JobRepository::class)` is true.
- `JobRepository::countFailed()` (docblock `@return int`): `zcount` on Horizon's `failed_jobs` sorted set, limited to entries newer than `horizon.trim.failed` minutes (10080 in config/horizon.php). This is the set behind Horizon's Failed Jobs list.
- `JobRepository::countRecentlyFailed()`: same over `recent_failed_jobs` within `horizon.trim.recent_failed` (also 10080). `deleteFailed($id)`, which `horizon:forget {id}` and `horizon:forget --all` call (the latter also calls `queue.failer->forget`), removes an entry only from the `failed_jobs` set, never from the recent set.
- `config/queue.php`: `default` = env QUEUE_CONNECTION (default `redis`); connections `sync` and `redis` (driver `redis`); `failed.driver` = `database-uuids`, table `failed_jobs`.
- `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so every existing failed-jobs test runs the failer path and keeps passing unchanged.
- `HealthIndicatorRegistry::results()` catches any Throwable from `check()` and shows `HealthStatus::Error` with only the exception class as detail. A Redis outage on the Horizon branch therefore surfaces as Error, never Ok, never a message.
- `HealthResult(HealthStatus $status, ?string $value = null, ?string $detail = null, ?string $url = null, ?string $urlLabel = null)`.
- The registry builds the indicator with `new FailedJobsIndicator` (no constructor arguments). Keep it that way.
- Test helpers already in tests/Feature/Operations/HealthIndicatorsTest.php: `logFailedJob()` writes one failed_jobs row through the failer; `useRedisQueueAt(object $test)` sets `queue.default` to `redis` on a test-unique queue name and fixes the clock, and the file's `afterEach` flushes that queue via `Tests\Support\RedisTestQueue`. Both are file-level functions, callable from any test in the file.
- Feature tests run inside RefreshDatabase (tests/Pest.php). Laravel's `$this->mock(Abstract::class, fn (MockInterface $mock) => ...)` binds a strict Mockery mock as the container instance; a call to any method that was not expected throws, which also proves the indicator never calls `countRecentlyFailed()`.
</interfaces>
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Count Horizon's failed set on a Horizon Redis queue, end to end through the System page</name>
  <files>app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php, tests/Feature/Operations/HealthIndicatorsTest.php</files>
  <action>
Indicator (app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php), per D-12 and FND-09:

1. Keep the class `final readonly`, keep `slot()` unchanged and keep it constructor-less, because app/Providers/OperationsServiceProvider.php registers it with `new FailedJobsIndicator` and must not change.
2. Import `Laravel\Horizon\Contracts\JobRepository`. Add a private method `horizonRepository(): ?JobRepository` that returns `app(JobRepository::class)` only when both hold, checked in this order: (a) `app()->bound(JobRepository::class)` is true, meaning Horizon is installed and its provider registered its bindings; (b) the default queue connection uses the redis driver: read `config('queue.default')`, return null unless it is a non-empty string, then compare `config('queue.connections.'.$name.'.driver')` strictly with `'redis'`. Otherwise return null. Claude's discretion, record it in the SUMMARY: decide on the configured driver and do not resolve `Queue::connection()` as OldestPendingJobIndicator does, so deciding the source never builds a queue connection and a misnamed connection never throws; on the failer path the indicator behaves exactly as before.
3. In `check()`: when `horizonRepository()` returns a repository, set the count to its `countFailed()` result. Do not use `countRecentlyFailed()`. Otherwise run the existing failer path unchanged: the loosely typed `app('queue.failer')`, NotAvailable with `kokpit.system.failed_jobs.not_countable` when it is not a `CountableFailedJobProvider`, else `$failer->count()`. Then apply the existing threshold logic once, for both sources: `max(1, (int) config('kokpit.health.failed_jobs_warning_at'))`, Warning with `kokpit.system.failed_jobs.some` at or above it, Ok with `kokpit.system.failed_jobs.none` below it, and the value is the count as a string. The contract documents `int`, so add no cast unless PHPStan reports the value as mixed; in that case cast it with `(int)`.
4. Rewrite the class docblock in the existing style (short English sentences, D-12 reference). It must say:
   - Which source is read when, with the `bound()` + redis-driver rule.
   - On Horizon the count is `countFailed()`, the set behind Horizon's Failed Jobs list, counted only within `horizon.trim.failed` minutes.
   - Why not `countRecentlyFailed()`: `horizon:forget` clears an entry from the failed set (and `--all` also forgets the failer row) but never from the recent set, so a reviewed failure could not be acknowledged and would keep the Warning for up to a week.
   - A job retried from Horizon stays in the failed set until it is forgotten or trimmed.
   - On every other connection the queue failer counts, and a failer that cannot count is NotAvailable, never Ok.
   - A Redis error on the Horizon branch reaches the registry, which shows Error with the exception class only.

Tracer test (tests/Feature/Operations/HealthIndicatorsTest.php): write it first, directly after the existing test 'shows the Admin the failed-jobs slot as Warning with the count after a job failed'.
- Add the imports `Laravel\Horizon\Contracts\JobRepository` and `Mockery\MockInterface` to the import block in alphabetical order. Pint's ordered imports must stay clean.
- Name the test 'shows the Admin the failed jobs Horizon holds when the queue runs on Redis'.
- In the test: call `useRedisQueueAt($this)`. Bind a mock with `$this->mock(JobRepository::class, ...)` whose `countFailed` returns 2. Do not log any failed_jobs row, so the failer alone would report none. GET `/admin/system` as `Canary::admin()` with `assertOk()`. Expect the HTML to contain `__('kokpit.system.failed_jobs.some')` and not to contain `__('kokpit.system.failed_jobs.none')`. This proves the page reads Horizon's store.
- Why a mock and not real Redis: DDEV's Redis is shared and a local Horizon may hold real failed jobs under the Horizon prefix, so a real count is not deterministic. Tests never flush shared Redis (see the RedisTestQueue docblock).
- Use only fictional values, per .claude/CLAUDE.md.

Run no migrations and no Pest inside a worktree. Tests run only after the merge, in DDEV (see verification).
  </action>
  <verify>
    <automated>php -l app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php && php -l tests/Feature/Operations/HealthIndicatorsTest.php && test "$(grep -cF 'use Laravel\Horizon\Contracts\JobRepository;' app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php)" -ge 1 && test "$(grep -v '^[[:space:]]*\*' app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php | grep -cF 'countFailed()')" -ge 1 && test "$(grep -cF 'queue.connections.' app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php)" -ge 1 && test "$(grep -cF 'use Laravel\Horizon\Contracts\JobRepository;' tests/Feature/Operations/HealthIndicatorsTest.php)" -ge 1 && test "$(grep -cF "the failed jobs Horizon holds when the queue runs on Redis" tests/Feature/Operations/HealthIndicatorsTest.php)" -eq 1</automated>
    <automated>Post-merge, on the HEAD branch, end-to-end proof: ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Operations/HealthIndicatorsTest.php</automated>
    <human-check>After deploy, the System page Failed jobs count matches the number of jobs on Horizon's Failed Jobs page that failed within the last 7 days, and it shows Warning while that number is at least kokpit.health.failed_jobs_warning_at.</human-check>
  </verify>
  <done>Gate baselines at HEAD are all 0 (JobRepository import in the indicator, non-docblock countFailed() call, queue.connections. lookup, JobRepository import in the test file, tracer test name), and each is now at least 1, with the tracer test name exactly 1. Both files lint clean with php -l. After the merge, the tracer test passes in DDEV: the System page shows the failed-jobs "some" detail from a mocked Horizon count of 2 while failed_jobs is empty. All previously existing tests in HealthIndicatorsTest.php still pass unchanged.</done>
</task>

<task type="auto">
  <name>Task 2: Pin both sources and both fallbacks in Pest, and correct the Horizon trim comment</name>
  <files>tests/Feature/Operations/HealthIndicatorsTest.php, config/horizon.php</files>
  <behavior>
    - Redis + Horizon: mocked countFailed returns 3, so the indicator returns Warning with value '3' and detail kokpit.system.failed_jobs.some
    - Redis + Horizon ignores the table: one failed_jobs row via logFailedJob() and mocked countFailed returns 0, so the result is Ok, '0', kokpit.system.failed_jobs.none
    - Threshold on Horizon: kokpit.health.failed_jobs_warning_at = 2, mocked countFailed returns 1 then 2, so the first check is Ok '1' and the second is Warning '2'
    - Non-redis with Horizon installed: queue.default = sync, the mock expects countFailed never, one logFailedJob(), so the result is Warning '1' (failer path)
    - Redis without Horizon: queue.default = redis, the JobRepository binding is removed from the container, one logFailedJob(), so the result is Warning '1' (failer fallback)
  </behavior>
  <action>
Tests (tests/Feature/Operations/HealthIndicatorsTest.php):
- Add five Pest tests that implement the behavior block above, per D-12 and FND-09. Place them right after the Task 1 tracer test, so all failed-jobs tests stay together before the scheduler helpers.
- Suggested names:
  - 'counts the failed jobs Horizon holds when the queue runs on Redis'
  - 'ignores the failed_jobs table when Horizon is the failure store'
  - 'takes the warning threshold from the configuration on Horizon too'
  - 'keeps counting through the queue failer when the queue is not Redis, even with Horizon installed'
  - 'falls back to the queue failer on Redis when Horizon is not installed'
- Call `(new FailedJobsIndicator)->check()` directly. These five tests need no live Redis, because the indicator only reads config and the mocked repository. So set `config(['queue.default' => 'redis'])` directly instead of calling `useRedisQueueAt`, and only for the Redis cases.
- Set `config(['queue.default' => 'sync'])` explicitly in the non-redis case, so the test does not depend on phpunit.xml.
- Expectations on the mock:
  - Bind it with `$this->mock(JobRepository::class, fn (MockInterface $mock) => ...)`.
  - Use `shouldReceive('countFailed')->once()->andReturn(...)` in the single-check cases, and `->twice()->andReturn(1, 2)` in the threshold case.
  - Use `shouldNotReceive('countFailed')` in the non-redis case.
  - Assert status, value and, where the behavior names it, detail. Compare the detail with `__('kokpit.system.failed_jobs.some')` or `__('kokpit.system.failed_jobs.none')`, never with a hard-coded Czech string.
- In the Redis-without-Horizon case, remove the binding with `unset($this->app[JobRepository::class])`. The container's offsetUnset drops the binding and the resolved instance, so `bound()` turns false. The app is rebuilt for the next test.
- Do not touch the existing tests or helpers, and do not add tests for other slots.

Config comment (config/horizon.php):
- Replace only the single `//` comment line directly above `'trim' => [`. It currently claims the failed_jobs table is the authoritative record and calls Horizon's store a mere display. Replace it with at most two `//` lines that say: on a Redis queue, the System page failed-jobs slot (FailedJobsIndicator) counts Horizon's failed set, so `trim.failed` is also the window of that count; the failed_jobs table, written by the queue failer, is the count source when the queue is not Redis (FND-09).
- Leave every trim value and the rest of the file unchanged.
- Do not edit anything under lang/. The existing texts already fit both sources.
  </action>
  <verify>
    <automated>php -l tests/Feature/Operations/HealthIndicatorsTest.php && php -l config/horizon.php && test "$(grep -cF "shouldReceive('countFailed')" tests/Feature/Operations/HealthIndicatorsTest.php)" -ge 4 && test "$(grep -cF "shouldNotReceive('countFailed')" tests/Feature/Operations/HealthIndicatorsTest.php)" -ge 1 && test "$(grep -cF 'unset($this->app[JobRepository::class])' tests/Feature/Operations/HealthIndicatorsTest.php)" -eq 1 && test "$(grep -cF 'FailedJobsIndicator' config/horizon.php)" -ge 1 && test "$(grep -cF "'failed' => 10080," config/horizon.php)" -eq 1 && test "$(grep -cF 'authoritative failure record' config/horizon.php)" -eq 0</automated>
    <automated>Post-merge, on the HEAD branch: ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Operations/HealthIndicatorsTest.php</automated>
  </verify>
  <done>Baselines at HEAD: shouldReceive('countFailed') is 0 (Task 1 adds 1, so it is at least 4 after Task 2), shouldNotReceive('countFailed') is 0, the unset binding line is 0, FailedJobsIndicator in config/horizon.php is 0, the old comment phrase 'authoritative failure record' in config/horizon.php is 1. After this task: at least 4, at least 1, exactly 1, at least 1 and 0, with the trim values unchanged. After the merge, all five new tests and every existing test in the file pass in DDEV. No file under lang/ appears in this plan's diff.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Admin browser to System page | Admin-only Filament page, already behind the existing panel policy; this plan does not change access |
| App to Redis (Horizon prefix) | The indicator now reads a count from Horizon's Redis store on every page load and 30 s poll |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261104-weo-01 | Information disclosure | FailedJobsIndicator Horizon branch | medium | mitigate | Only `countFailed()` is called; no job payload, name or exception text is read. Detail texts stay the fixed lang keys. A Redis exception reaches HealthIndicatorRegistry, which shows only the exception class (HealthResult rule: no raw infrastructure text) |
| T-261104-weo-02 | Repudiation / integrity of the signal | Source selection | medium | mitigate | The source is decided by `bound(JobRepository)` plus the redis driver of `queue.default`. Pest pins all four combinations (Horizon count, table ignored, non-redis failer, redis-without-Horizon failer), so the slot cannot silently fall back to a store that reads 0 |
| T-261104-weo-03 | Denial of service | Redis count per poll | low | accept | `zcount` on one sorted set is O(log N) and runs once per check, the same order as the existing OldestPendingJobIndicator Redis read |
| T-261104-weo-04 | Elevation of privilege | System page access | low | accept | Unchanged: the page and registry stay Admin-only; no new route, action or Partner-visible surface |
| T-261104-weo-SC | Tampering | npm/pip/composer installs | low | accept | No package is installed or upgraded; laravel/horizon is already a locked production dependency |
</threat_model>

<verification>
In the worktree, run only the cheap checks from each task's first automated verify (php -l and grep gates). Run no migrations; this plan adds none.

After the merge into the HEAD branch, in DDEV:
1. `ddev exec vendor/bin/pint --test app/Domain/Operations/Health/Indicators/FailedJobsIndicator.php tests/Feature/Operations/HealthIndicatorsTest.php config/horizon.php`
2. `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (CI form)
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Operations/HealthIndicatorsTest.php`
4. The full suite as CI runs it: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency`. Never use `--parallel`.

Before each commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook hook.
</verification>

<success_criteria>
- On a redis-driver default connection with Horizon bound, the Failed jobs slot reports Horizon's `countFailed()` and turns Warning at the configured threshold. A failed_jobs row does not affect it.
- On any other connection, and on redis without Horizon, the slot counts through the queue failer exactly as before, including NotAvailable for a non-countable failer.
- The six new Pest tests (one tracer, five branch tests) and all existing tests in tests/Feature/Operations/HealthIndicatorsTest.php pass in DDEV after the merge. Pint and PHPStan are clean.
- The config/horizon.php trim comment no longer contradicts the indicator. lang/ and app/Providers/OperationsServiceProvider.php are unchanged.
</success_criteria>

<output>
Create `.planning/quick/261104-weo-make-the-system-page-failed-jobs-indicat/261104-weo-SUMMARY.md` when done
</output>
