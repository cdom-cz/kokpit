---
phase: quick-261008-bec
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - composer.lock
  - bootstrap/providers.php
  - app/Providers/HorizonServiceProvider.php
  - config/horizon.php
  - config/queue.php
  - app/Domain/Operations/Jobs/KokpitJob.php
  - routes/console.php
  - .env.example
  - .ddev/config.yaml
  - README.md
  - tests/Feature/Operations/HorizonAccessTest.php
  - tests/Feature/Operations/HorizonConfigTest.php
  - tests/Feature/Repo/DdevConfigTest.php
autonomous: true
requirements: [FND-09, FND-06, FND-20, FND-14]

estimate:
  tokens: 70000
  raw_tokens: 70000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "The Admin opens /horizon and gets the Horizon dashboard (HTTP 200). While KOKPIT_REQUIRE_ADMIN_2FA is true, this needs a stored TOTP secret; an Admin without one gets 403"
    - "A Partner, a user without a role and a guest get HTTP 403 on every route named horizon.*, also when APP_ENV is local (the package's local-environment bypass is removed)"
    - "Every Horizon supervisor in every configured environment (production, local, *) works the redis connection's queue with timeout 300 s. That timeout is above the KokpitJob timeout (60 s) and below the redis retry_after (330 s) and the stopwaitsecs (360 s) of supervisor-horizon.ini"
    - "routes/console.php schedules horizon:snapshot every five minutes on one server"
    - "The DDEV queue-worker daemon waits for vendor/autoload.php and then execs php artisan horizon. The README Operations section documents the dashboard URL, who may open it and ddev artisan horizon:terminate"
    - "laravel/horizon is in composer.json require, composer check-licenses passes, and .env.example documents every HORIZON_* key the config reads (EnvExampleTest passes)"
    - "The owner's deploy files (.deployignore, site.conf.tmpl, supervisor-horizon.ini, zerops.yml, committed in 8c72141) are unchanged: git diff 8c72141 on those four paths is empty after execution"
  artifacts:
    - path: "app/Providers/HorizonServiceProvider.php"
      provides: "viewHorizon gate (Admin role plus 2FA when enforced) and a Horizon::auth callback with no environment shortcut"
      contains: "viewHorizon"
    - path: "config/horizon.php"
      provides: "Supervisor, trim, silenced and metrics settings; env-driven with safe defaults"
      contains: "'timeout' => 300"
    - path: "config/queue.php"
      provides: "redis retry_after raised above the Horizon timeout"
      contains: "REDIS_QUEUE_RETRY_AFTER', 330"
    - path: "routes/console.php"
      provides: "Scheduled Horizon metrics snapshot"
      contains: "horizon:snapshot"
    - path: ".ddev/config.yaml"
      provides: "DDEV queue-worker daemon running Horizon"
      contains: "exec php artisan horizon"
    - path: "tests/Feature/Operations/HorizonAccessTest.php"
      provides: "Dashboard access contract (Admin allowed; Partner, no-role user and guest refused on every horizon.* route)"
    - path: "tests/Feature/Operations/HorizonConfigTest.php"
      provides: "Config sanity: timeout chain, environments, silenced heartbeat, snapshot schedule, redis queue compatibility"
  key_links:
    - from: "bootstrap/providers.php"
      to: "app/Providers/HorizonServiceProvider.php"
      via: "provider registration"
      pattern: "HorizonServiceProvider::class"
    - from: "app/Providers/HorizonServiceProvider.php"
      to: "Laravel\\Horizon\\Horizon::auth"
      via: "authorization() override delegating to the viewHorizon gate"
      pattern: "Horizon::auth"
    - from: "config/horizon.php"
      to: "config/queue.php and supervisor-horizon.ini"
      via: "timeout 300 < retry_after 330 < stopwaitsecs 360 (asserted in HorizonConfigTest)"
      pattern: "retry_after"
---

<objective>
Install laravel/horizon and configure it so that it replaces the plain queue worker without breaking the
Phase 3 queue contract. Only the Admin may open the dashboard (FND-06): a Partner, a user without a role
or a guest never can.

Purpose: The owner is moving production to a single Zerops `backend` service that runs `php artisan horizon`
under supervisord (their `supervisor-horizon.ini` and `zerops.yml`, committed in 8c72141). The application
side has to exist and agree with those files: supervisor timeout 300 s, stopwaitsecs 360 s, a redis queue
connection, after-commit dispatch, the failed-job alert and the oldest-pending-job indicator.

Output: Horizon installed and registered, an Admin-only gate, tuned config/horizon.php, retry_after raised,
the snapshot scheduled, the DDEV worker daemon on Horizon, README and .env.example updated, and two new focused
Pest test files.
</objective>

<execution_context>
@.claude/gsd-core/workflows/execute-plan.md
@.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@config/queue.php
@app/Providers/OperationsServiceProvider.php
@app/Http/Middleware/EnsureAdminHasTwoFactor.php
@app/Domain/Shared/Auth/PartnerContext.php
@app/Domain/Operations/Jobs/KokpitJob.php
@app/Domain/Operations/Health/Indicators/OldestPendingJobIndicator.php
@app/Support/ProductionConfigGuard.php
@routes/console.php
@tests/Feature/Repo/EnvExampleTest.php
@tests/Feature/Repo/DdevConfigTest.php
@tests/Feature/Auth/TwoFactorEnforcementTest.php
@tests/Support/Canary.php
@supervisor-horizon.ini

Facts the planner verified (do not re-research):
- laravel/horizon 5.x is MIT, first-party Laravel, and supports illuminate ^13. It pulls in laravel/sentinel (MIT,
  first-party). It requires ext-pcntl and ext-posix; both are loaded in the DDEV web container.
- `horizon:install` publishes config/horizon.php and app/Providers/HorizonServiceProvider.php, and appends the
  provider to bootstrap/providers.php. Horizon 5 renders its assets inline from the package, so nothing should
  land in public/.
- The package's default authorization lets anyone in when APP_ENV is local. The stub's gate is meant only for
  non-local environments.
- Horizon docs: the supervisor `timeout` must be a few seconds shorter than the connection's `retry_after`, or jobs
  run twice. `stopwaitsecs` must exceed the longest job. `horizon:snapshot` belongs in the schedule every five
  minutes. A `'*'` wildcard environment applies when no other environment matches. `horizon:listen` needs Node
  plus chokidar, so it is NOT used here.
- Laravel\Horizon\RedisQueue extends Illuminate\Queue\RedisQueue. That keeps OldestPendingJobIndicator (its
  instanceof check and creationTimeOfOldestPendingJob) and ProductionConfigGuard (strict `redis` connection name)
  working.
- Pest loads every test file into one process. Global helper functions in a test file must have unique names, so
  prefix them with `horizon` (NoPruningTest already defines scheduleRuns and HealthIndicatorsTest defines
  scheduleEventNamed).
- phpunit.xml runs with APP_ENV=testing, KOKPIT_REQUIRE_ADMIN_2FA=false, QUEUE_CONNECTION=sync, CACHE_STORE=array.
  Tests run inside DDEV.

OWNER'S DEPLOY FILES (hard rule for every task): the owner committed `.deployignore`, `site.conf.tmpl`,
`supervisor-horizon.ini` and the rewritten `zerops.yml` in 8c72141 ("feat(deployment): add initial configuration for
app deployment"). They belong to the owner, and this plan never edits them. It only reads supervisor-horizon.ini.
Never run `git add -A`, `git add .`, `git add -u`, `git stash`, `git checkout --` or `git restore`. If the owner has
new uncommitted work in the tree when you start, leave it alone. Stage only this plan's files by explicit path.
Commit with an explicit pathspec, either `gsd_run query commit "<msg>" --files <paths>` (which scopes the commit with
`--`) or `git commit -m "<msg>" -- <paths>`. After every commit, `git diff HEAD~1 --stat` must list only this plan's
files, and `git diff 8c72141 -- .deployignore site.conf.tmpl supervisor-horizon.ini zerops.yml` must be empty.
Run `scripts/check-sensitive.sh` with explicit file operands (this plan's files only). Never bypass hooks
(`--no-verify`, `LEFTHOOK=0`).
</context>

<tasks>

<task type="tracer">
  <name>Task 1: End-to-end "Admin opens /horizon, everyone else gets 403": install, register, gate, prove over HTTP</name>
  <files>composer.json, composer.lock, bootstrap/providers.php, app/Providers/HorizonServiceProvider.php, config/horizon.php, .env.example, tests/Feature/Operations/HorizonAccessTest.php</files>
  <action>
Step 0 (baseline, before any change): run `git status --short` and write down any entries that are not this plan's,
then leave them alone. Run `ddev exec vendor/bin/pest tests/Feature/Repo` and write the names of the tests that already
fail into the SUMMARY. ZeropsConfigTest and probably DeployWorkflowTest are expected to fail, because they still
describe the old three-service zerops.yml (app, worker, scheduler) while the owner's committed zerops.yml (8c72141) has
a single `backend` setup. Those failures predate this plan and are not its to fix. Do not touch zerops.yml,
.github/workflows/deploy.yml, ZeropsConfigTest or DeployWorkflowTest.

Step 1, install: run `ddev composer require laravel/horizon` and keep the caret constraint Composer writes. Inspect
the composer.lock diff. The expected new packages are laravel/horizon and laravel/sentinel (plus only already-present
or symfony/ramsey packages). List every newly added package in the SUMMARY. Run `ddev composer check-licenses` (must
pass). Also run `ddev composer audit` for information: report any advisory, and block only if it names horizon or
sentinel.

Step 2, publish: run `ddev artisan horizon:install`. Then `git status --short` must show only config/horizon.php,
app/Providers/HorizonServiceProvider.php and bootstrap/providers.php as new or changed, besides composer files and the
entries noted in Step 0. If anything else appears (for example under public/), report it and do not commit it. Normalise
bootstrap/providers.php to the file's import style: add a `use App\Providers\HorizonServiceProvider;` line in
alphabetical order and list `HorizonServiceProvider::class` after `OperationsServiceProvider::class`.

Step 3, provider (FND-06, D-06 parity): rewrite app/Providers/HorizonServiceProvider.php as a `final class` with
`declare(strict_types=1);` that extends Laravel\Horizon\HorizonApplicationServiceProvider, with a docblock saying why
it exists.
- boot(): call parent::boot() only. Route no Horizon notifications. Failed jobs already alert the Admin through
  ReportFailedJob, and slow queues show on the System page. A notification route would add a second alert channel and
  an address in code, which repository hygiene forbids. Remove the stub's commented notification lines.
- gate(): Gate::define('viewHorizon', ...) with a static closure whose first parameter is typed non-nullable
  App\Domain\Identity\Models\User, so a guest is denied without the closure running. The closure returns true only
  when the user has RoleName::Admin and, while `config('kokpit.require_admin_two_factor') === true`, also has a
  filled getAppAuthenticationSecret(). This mirrors EnsureAdminHasTwoFactor: an Admin who has not enrolled 2FA must
  not reach the dashboard through a route that sits outside the Filament panel middleware.
- authorization(): override the parent's protected method. Call $this->gate(), then register Horizon::auth with a
  static callback that takes the Illuminate\Http\Request and returns
  Gate::forUser($request->user())->allows('viewHorizon'). Add no APP_ENV-based shortcut of any kind. The package
  default lets everyone in on local, and Partner accounts must never reach the dashboard in any environment.

Step 4, config file: give config/horizon.php `declare(strict_types=1);` and a short header comment in the style of
config/queue.php. Leave the tuning to Task 2.

Step 5, .env.example: add a commented block titled Horizon, placed after the Redis block, with one explanatory line
(the dashboard lives at /horizon for the Admin only, and the defaults fit DDEV and production). Then add the commented
keys `# HORIZON_NAME=`, `# HORIZON_DOMAIN=`, `# HORIZON_PATH=horizon` and `# HORIZON_PREFIX=`. EnvExampleTest's forward
check requires HORIZON_NAME and HORIZON_DOMAIN because the published config reads them without a default. The reverse
check requires every documented key to be read by config/horizon.php, so do not document a key that config does not read.

Step 6, test: write tests/Feature/Operations/HorizonAccessTest.php (Pest, declare strict types, fictional data only).
Build users with Tests\Support\Canary (admin(), partnerFor(), userWithoutRole(), twoClients()), and seed a TOTP secret
the way TwoFactorEnforcementTest does (Google2FA generateSecretKey at runtime). Give every global helper function a
`horizon` prefix. Cases:
(a) The Admin GETs /horizon and gets 200.
(b) Walk Route::getRoutes() for every route whose name starts with `horizon.`. Build each URI by replacing every
`{param}` and `{param?}` placeholder with a fictional token, and call it with its first non-HEAD method as a Partner,
as a user without a role and as a guest. Every response is exactly 403. Assert that at least 10 routes were walked
and that horizon.index is among them, so the walk cannot pass by walking nothing.
(c) Switch the application environment to local (app()->detectEnvironment returning local). A Partner and a guest
still get 403 on /horizon.
(d) With config kokpit.require_admin_two_factor set to true, an Admin without a secret gets 403 on /horizon, and an
Admin with a secret gets 200.
(e) Gate::forUser(admin) allows viewHorizon, and Gate::forUser(partner) denies it.
  </action>
  <verify>
    <automated>ddev exec vendor/bin/pest tests/Feature/Operations/HorizonAccessTest.php tests/Feature/Repo/EnvExampleTest.php && ddev composer check-licenses && grep -q 'laravel/horizon' composer.json && grep -q 'HorizonServiceProvider::class' bootstrap/providers.php</automated>
  </verify>
  <done>
laravel/horizon is required and passes the licence allowlist. HorizonServiceProvider is registered and defines
viewHorizon as Admin plus 2FA-when-enforced, with no environment shortcut. The Admin gets 200 on /horizon. A
Partner, a no-role user and a guest get 403 on every horizon.* route, including under APP_ENV=local. EnvExampleTest
passes. The baseline failing Repo tests are recorded in the SUMMARY. Commit by explicit pathspec; the owner's deploy
files are unchanged.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Horizon works the queue with the agreed timeouts and feeds its metrics</name>
  <files>config/horizon.php, config/queue.php, app/Domain/Operations/Jobs/KokpitJob.php, routes/console.php, tests/Feature/Operations/HorizonConfigTest.php</files>
  <behavior>
    - Every supervisor in each of the environments production, local and * (merged with defaults) has connection redis, a queue list containing config('queue.connections.redis.queue'), timeout 300 and tries 1
    - The longest supervisor timeout is below config('queue.connections.redis.retry_after'), which is 330 by default
    - The longest supervisor timeout is above the Timeout attribute of KokpitJob (60), read by reflection
    - The stopwaitsecs of [program:horizon] in supervisor-horizon.ini (committed by the owner, so the test requires the file and never skips) is above the longest supervisor timeout
    - horizon.silenced contains RecordWorkerHeartbeat::class
    - The schedule holds a horizon:snapshot event with expression */5 * * * * and onOneServer true
    - Queue::connection('redis') is an Illuminate\Queue\RedisQueue (Horizon's subclass), so OldestPendingJobIndicator keeps measuring
    - horizon.path is horizon, horizon.use is default, horizon.middleware is ['web']
  </behavior>
  <action>
Write tests/Feature/Operations/HorizonConfigTest.php first with the behavior cases above (RED against the published
defaults). Then change the config until it is GREEN. Prefix every global helper with `horizon`. To merge defaults
into each environment, prefer Horizon's own Laravel\Horizon\ProvisioningPlan if it exposes the merged plan; otherwise
apply array_replace_recursive of the default supervisor onto each environment's supervisor. Parse
supervisor-horizon.ini with parse_ini_file using sections and INI_SCANNER_RAW. Only read it; never edit it.

config/horizon.php (keep the published key set and env() calls; every value comes from env with a safe default or
is a literal; no hostnames, addresses or instance names):
- defaults.supervisor-1:
  - connection `redis`, and queue `[env('REDIS_QUEUE', 'default')]` so it always follows config/queue.php.
  - balance `auto`, autoScalingStrategy `time`, minProcesses 1, maxProcesses `(int) env('HORIZON_MAX_PROCESSES', 3)`,
    balanceMaxShift 1, balanceCooldown 3.
  - maxTime 3600 (the old `queue:work --max-time=3600`), maxJobs 1000, memory 128, sleep 3, nice 0.
  - tries 1. Only jobs that declare their own policy are retried; KokpitJob declares Tries(3) and is idempotent by
    contract. Package jobs make no idempotence promise.
  - timeout 300. This value is fixed by the owner's supervisor-horizon.ini, whose stopwaitsecs of 360 must exceed it.
    Keep it a literal, not an env value.
- environments: `production` and `*` inherit everything from defaults (each an empty supervisor-1 block with a
  comment). The wildcard keeps a mistyped APP_ENV from starting zero workers. `local` sets supervisor-1 maxProcesses 2.
- waits: one entry keyed `'redis:'.env('REDIS_QUEUE', 'default')` at 60. Add a comment saying it only colours the
  dashboard, because Kokpit's alerting is the System page indicator plus ReportFailedJob.
- trim: recent 60, pending 60, completed 60, recent_failed 10080, failed 10080, monitored 10080. Add a comment saying
  the failed_jobs table stays the authoritative failure record (FND-09).
- silenced: [App\Domain\Operations\Jobs\RecordWorkerHeartbeat::class]. It runs every minute and would flood the
  completed list.
- metrics.trim_snapshots: job 288 and queue 288 (24 hours at the five-minute snapshot cadence).
- fast_termination false and memory_limit 64. Keep path, domain, name, prefix, use and middleware as published.
- Add a header comment with the timeout chain: KokpitJob 60 s < supervisor timeout 300 s < redis retry_after 330 s,
  and supervisor timeout 300 s < stopwaitsecs 360 s in supervisor-horizon.ini.

config/queue.php: raise the redis `retry_after` default from 90 to 330 (the env key REDIS_QUEUE_RETRY_AFTER stays the
same). Add a comment saying it must stay a few seconds above the Horizon supervisor timeout (300 s, config/horizon.php),
otherwise a long job is handed to a second worker while it still runs. Do not document REDIS_QUEUE_RETRY_AFTER in
.env.example, because lowering it is a foot-gun.

app/Domain/Operations/Jobs/KokpitJob.php: docblock only. Replace the sentence about retry_after (90 s) with one
stating that the redis retry_after (330 s) stays above the Horizon supervisor timeout (300 s), which stays above this
60 s timeout. Change no code or attribute.

routes/console.php: add Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->name('kokpit-horizon-snapshot'),
with a comment saying it feeds the Horizon metrics graphs. onOneServer is used because the owner's new deploy runs
schedule:run on every container.

Then run the existing queue and health suites listed in verify. They prove that Horizon's queue connector keeps the
Phase 3 contract: payload retry policy, after-commit visibility, failed-job alert, oldest-pending indicator, and the
no-pruning schedule check.
  </action>
  <verify>
    <automated>ddev exec vendor/bin/pest tests/Feature/Operations/HorizonConfigTest.php tests/Feature/Operations/HorizonAccessTest.php tests/Feature/Operations/QueueContractTest.php tests/Feature/Operations/FailingJobFlowTest.php tests/Feature/Operations/HealthIndicatorsTest.php tests/Feature/Operations/NoPruningTest.php tests/Unit/Support/ProductionConfigGuardTest.php</automated>
  </verify>
  <done>
HorizonConfigTest passes with no skipped case, including the supervisor-horizon.ini stopwaitsecs check. The supervisor
timeout is 300 in every environment, retry_after defaults to 330, the heartbeat
job is silenced, and horizon:snapshot runs every five minutes on one server. The Phase 3 queue and health suites stay
green. Commit by explicit pathspec; the owner's deploy files are unchanged.
  </done>
</task>

<task type="auto">
  <name>Task 3: Developer runs Horizon in DDEV and finds it documented</name>
  <files>.ddev/config.yaml, tests/Feature/Repo/DdevConfigTest.php, README.md, .env.example</files>
  <action>
.ddev/config.yaml (FND-20): keep the daemon name `queue-worker` and the existing wait loop (vendor/autoload.php plus
`php artisan about`). Replace only the exec target `php artisan queue:listen --tries=3 --sleep=1` with
`php artisan horizon`, so DDEV uses the same supervisor config as production (the `local` environment block). Do not
use horizon:listen, which needs Node plus chokidar, and nothing more may be installed. DDEV runs its extra daemons
under supervisord with autorestart, so `ddev artisan horizon:terminate` restarts Horizon on new code. Do NOT run
`ddev restart` yourself; the owner does that (see the human check).

tests/Feature/Repo/DdevConfigTest.php: add one case asserting that the queue-worker daemon's command contains
`exec php artisan horizon`. Keep the existing cases unchanged; the daemon names stay queue-worker and scheduler.

README.md, Operations section: after the queue worker bullet, add a short paragraph. The queue worker is Laravel
Horizon (`php artisan horizon`). Its dashboard is at `/horizon` (`https://kokpit.ddev.site/horizon` in DDEV). Only the
Admin may open it, and while two-factor enforcement is on only after setting up 2FA. A Partner or a guest gets 403.
In DDEV the queue-worker daemon runs Horizon; after changing job code, run `ddev artisan horizon:terminate` and the
daemon starts it again on the new code. The scheduler takes a metrics snapshot every five minutes, and
HORIZON_MAX_PROCESSES caps the worker processes per container (default 3, 2 in DDEV). Do NOT rewrite the Deploy
section or the sentence naming the Zerops `worker` and `scheduler` services. Both still describe the old three-service
layout. The owner's zerops.yml (8c72141) replaced it with one `backend` service, and deploy.yml, CONTRIBUTING and
the Repo tests have not caught up yet. That is the owner's deploy migration: report it in the SUMMARY and do not fix
it here. RepositoryFilesTest requires every `ddev artisan <cmd>` named in the README to be a
registered command; horizon:terminate is registered by Horizon.

.env.example: in the Horizon block from Task 1, add `# HORIZON_MAX_PROCESSES=3` with a comment saying it caps the
worker processes per container and that DDEV uses 2.

Final gates, in order: the verify command below, then the full suite `ddev exec vendor/bin/pest`. Compare the full
suite with the Task 1 baseline. Every failure outside the recorded baseline (the zerops.yml-driven Repo tests) is this
plan's to fix. Then run `scripts/check-sensitive.sh` with operands for every file this plan created or changed (the
files_modified list minus composer.lock). Commit by explicit pathspec. Then confirm that
`git diff 8c72141 -- .deployignore site.conf.tmpl supervisor-horizon.ini zerops.yml` is empty.
  </action>
  <verify>
    <automated>ddev exec vendor/bin/pest tests/Feature/Repo/DdevConfigTest.php tests/Feature/Repo/EnvExampleTest.php tests/Feature/Repo/RepositoryFilesTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G && scripts/check-sensitive.sh README.md .env.example .ddev/config.yaml config/horizon.php config/queue.php app/Providers/HorizonServiceProvider.php routes/console.php bootstrap/providers.php app/Domain/Operations/Jobs/KokpitJob.php tests/Feature/Operations/HorizonAccessTest.php tests/Feature/Operations/HorizonConfigTest.php tests/Feature/Repo/DdevConfigTest.php</automated>
    <human-check>After `ddev restart`: `ddev exec supervisorctl status` shows webextradaemons:queue-worker RUNNING; `ddev artisan horizon:status` reports Horizon running; the Admin opens https://kokpit.ddev.site/horizon and sees supervisor-1 working the default queue; a signed-in Partner gets 403 on the same URL.</human-check>
  </verify>
  <done>
The DDEV queue-worker daemon execs php artisan horizon after the vendor wait. DdevConfigTest, EnvExampleTest and
RepositoryFilesTest pass. The README documents the dashboard, its access rule and horizon:terminate. Pint, PHPStan
and check-sensitive are clean. The full suite has no failures beyond the recorded zerops.yml baseline. The owner's
deploy files are unchanged since 8c72141.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser -> /horizon routes | Any signed-in user (Admin or Partner) or guest can request the dashboard and its JSON API, including the POST actions that retry jobs |
| Filament panel middleware -> plain web routes | The Horizon routes sit outside the panel middleware, so the panel's per-request Admin 2FA enforcement does not cover them |
| repository -> public | The config, docs and tests are public (AGPL), so no instance value may be committed |
| Packagist -> vendor | New third-party code enters through composer require |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261008-bec-01 | Elevation of Privilege / Information Disclosure | HorizonServiceProvider authorization() and the viewHorizon gate | high | mitigate | The gate admits only RoleName::Admin. The authorization() override removes the package's APP_ENV=local bypass. HorizonAccessTest walks every horizon.* route as Partner, no-role user and guest (403), including under APP_ENV=local |
| T-261008-bec-02 | Spoofing | Admin session without 2FA enrolment reaching /horizon outside the panel middleware | medium | mitigate | While kokpit.require_admin_two_factor is true, the gate also requires a filled app authentication secret (parity with EnsureAdminHasTwoFactor, D-06). Test case (d) proves it |
| T-261008-bec-03 | Tampering | Horizon POST actions (retry or forget jobs) | low | mitigate | horizon.middleware keeps the `web` group, which carries CSRF protection. The gate applies to every action route (walked in test b) |
| T-261008-bec-04 | Tampering / Denial of Service | Duplicate job execution when the supervisor timeout reaches retry_after | medium | mitigate | retry_after default is raised to 330 against timeout 300. HorizonConfigTest asserts timeout < retry_after and KokpitJob timeout < supervisor timeout |
| T-261008-bec-05 | Information Disclosure | config/horizon.php, .env.example, README in a public repo | medium | mitigate | Every instance value comes from env with neutral defaults. No notification routes or addresses. scripts/check-sensitive.sh runs on every changed file before commit |
| T-261008-bec-06 | Information Disclosure | Stale session on /horizon after a password change (no AuthenticateSession on Horizon routes) | low | accept | Single-Admin instance. Sessions live in Redis and the panel invalidates them on the next panel request. Adding AuthenticateSession outside a panel context would redirect to a missing login route |
| T-261008-bec-SC | Tampering | composer require laravel/horizon | high | mitigate | First-party Laravel packages only (laravel/horizon and laravel/sentinel, both MIT, verified on Packagist and the official docs). The composer.lock diff is inspected and every new package listed. composer check-licenses must pass and composer audit is reviewed. No unverified package is installed |
</threat_model>

<verification>
- Task 1 baseline recorded: the SUMMARY names the Repo tests that already failed against the owner's committed zerops.yml (8c72141).
- `ddev exec vendor/bin/pest` (full suite) shows no failure outside that baseline.
- `ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` is clean.
- `ddev composer check-licenses` passes.
- `git diff 8c72141 -- .deployignore site.conf.tmpl supervisor-horizon.ini zerops.yml` is empty.
- `git log -3 --stat` shows only this plan's files in the plan's commits.
</verification>

<success_criteria>
- Only the Admin (with 2FA when enforced) can open the Horizon dashboard; a Partner, a no-role user and a guest get 403 everywhere, in every environment.
- Horizon's supervisor config agrees with the owner's supervisor-horizon.ini (timeout 300 < stopwaitsecs 360) and with the queue (timeout 300 < retry_after 330), and the Phase 3 queue contract tests stay green.
- The metrics snapshot is scheduled, DDEV runs Horizon, and the README plus .env.example document it, with no instance-specific values.
- The owner's deploy files are untouched.
</success_criteria>

<output>
Create `.planning/quick/261008-bec-install-and-configure-laravel-horizon/261008-bec-SUMMARY.md` when done. Include:
- the Task 1 baseline of failing Repo tests;
- the list of packages added to composer.lock;
- the deploy mismatches that were reported but not fixed: the single `backend` setup in zerops.yml versus the
  app/worker/scheduler pushes in deploy.yml, ZeropsConfigTest, README and CONTRIBUTING; the readiness check, health
  check and env variables dropped from zerops.yml; `--ignore-platform-reqs` in the build, which would hide a missing
  ext-pcntl/ext-posix that Horizon needs at runtime; and the zerops.yml crontab running schedule:run on all
  containers, so schedules without onOneServer run once per container.
</output>
