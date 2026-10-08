---
phase: quick-261008-bec
plan: 01
subsystem: operations
tags: [horizon, queue, redis, ddev, authorization]
requires:
  - phase 3 queue contract (KokpitJob, ReportFailedJob, OldestPendingJobIndicator)
provides:
  - laravel/horizon installed as the queue worker, Admin-only dashboard at /horizon
  - Horizon supervisor config agreeing with the owner's supervisor-horizon.ini
affects: [config/queue.php, routes/console.php, .ddev/config.yaml, README.md]
tech-stack:
  added: [laravel/horizon ^5.50, laravel/sentinel, symfony/polyfill-php83]
  patterns: [Gate-based dashboard access without environment shortcut, timeout chain asserted in a test]
key-files:
  created:
    - app/Providers/HorizonServiceProvider.php
    - config/horizon.php
    - tests/Feature/Operations/HorizonAccessTest.php
    - tests/Feature/Operations/HorizonConfigTest.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/providers.php
    - config/queue.php
    - routes/console.php
    - app/Domain/Operations/Jobs/KokpitJob.php
    - .env.example
    - .ddev/config.yaml
    - README.md
    - tests/Feature/Repo/DdevConfigTest.php
    - tests/Feature/Operations/AdminAlertTest.php
decisions:
  - DDEV queue-worker daemon runs php artisan horizon (was queue:listen)
  - Wildcard '*' Horizon environment inherits the defaults
  - Redis retry_after default raised 90 -> 330
status: complete
duration: about 35 minutes
completed: 2026-10-08
commits: 5
plan_head_before: 8c72141f6e06a0a725af1d770dd32590fd66663b
plan_head_after: 8f37d5c66552f4e49396cd179fc9445195354dab
actuals:
  tokens: 7100
  tasks: 3
  commits: 5
---

# Phase quick-261008-bec Plan 01: Install and configure Laravel Horizon Summary

Laravel Horizon replaces the plain queue worker: Admin-only dashboard at `/horizon` (403 for a Partner, a user without a role and a guest, in every environment including local), supervisor timeout 300 s inside a tested chain KokpitJob 60 s < supervisor 300 s < redis retry_after 330 s < stopwaitsecs 360 s, metrics snapshot scheduled, DDEV daemon on Horizon.

Note on `actuals.tokens`: chars/4 over the realized diff excluding composer.lock (28,391 chars); the lock file diff (217 lines) is machine generated and not counted.

## Tasks

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 (tracer) | Install, register, Admin-only gate, HTTP proof | 5f14027 |
| 2 | Supervisor config, retry_after, snapshot schedule, config test | 9365719, 28ddc68 (pint import order) |
| 3 | DDEV daemon, README, .env.example, DdevConfigTest | 8f37d5c |
| fix | AdminAlertTest mock gets getJobId (see deviations) | 72def1b |

The tracer was verified end-to-end before expanding: HorizonAccessTest and EnvExampleTest green, `composer check-licenses` passed.

## Baseline (Task 1, step 0)

Before any change, `tests/Feature/Repo` had 14 failures, all in `ZeropsConfigTest` (it still describes the old app/worker/scheduler zerops.yml; the owner's 8c72141 has one `backend` setup and no `envVariables`):

- it describes exactly the three setups app, worker and scheduler
- it builds every setup identically from composer install without development packages
- it runs every setup on php-nginx 8.5 and nothing is extended from another setup
- it migrates exactly once, in the app setup, through execOnce and before the caches
- it builds the framework caches at container start and never in the build
- it deploys every runtime directory and none of the development files
- it gates the app readiness on the deploy check and health-checks /up
- it serves the public directory from the app setup only
- it starts the queue worker and the scheduler in their own setups
- it gives every setup the same environment
- it sets the production switches the application guard demands
- it holds only references for every secret-like variable
- it contains no application key and no key-shaped value
- it documents every environment variable of the manifest in .env.example

DeployWorkflowTest passed in the baseline. The full suite at the end shows exactly these 14 failures and nothing else (971 passed).

## Packages added to composer.lock

- laravel/horizon (MIT, first-party)
- laravel/sentinel (MIT, first-party)
- symfony/polyfill-php83 (MIT)

`composer check-licenses`: 209 packages, all allowed. `composer audit`: no advisories.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] AdminAlertTest broke under Horizon's JobFailed listeners**
- **Found during:** Task 3 (full suite)
- **Issue:** 13 tests of `AdminAlertTest` failed. Horizon registers `ForgetJobTimer` on the Laravel `JobFailed` event, and it calls `$event->job->getJobId()`; the strict Mockery double of `Job` in `failedEvent()` had no such expectation.
- **Fix:** added `getJobId` to the mocked job in `failedEvent()` (test-only; production code unchanged). Horizon's other listener (`MarshalFailedEvent`) ignores non-Redis jobs.
- **Files modified:** tests/Feature/Operations/AdminAlertTest.php
- **Commit:** 72def1b

**2. [Rule 3 - Blocking] horizon:install normalised away file conventions**
- `horizon:install` rewrote `bootstrap/providers.php` without `declare(strict_types=1)` and with fully qualified names; restored the file's import style as the plan required.

**3. Process slip (no impact on content)**
- Task 2 was committed once with one pint issue (import order in HorizonConfigTest); fixed in the follow-up style commit 28ddc68. That is why there are 5 commits instead of 3.

**4. README placement**
- The plan said "after the queue worker bullet"; a paragraph there would split the two-item list, so the Horizon paragraph sits right after the "In DDEV both are daemons..." paragraph that closes the list.

No threat flags: the new HTTP surface (`/horizon` routes) is the one the threat model covers (T-261008-bec-01..03), each mitigated and tested.

## Known Stubs

None.

## For the owner

Not done by me, needs your action: run `ddev restart` (the DDEV config changed), then check `ddev exec supervisorctl status` (queue-worker RUNNING), `ddev artisan horizon:status`, open `https://kokpit.ddev.site/horizon` as the Admin (supervisor-1 working the default queue), and confirm a signed-in Partner gets 403 on that URL. Not run by me: the manual human check of Task 3.

### Three design choices to review

1. **DDEV daemon -> horizon.** The `queue-worker` daemon now execs `php artisan horizon` (was `queue:listen --tries=3 --sleep=1`), same supervisor config as production (the `local` block, 2 processes). Trade-off: no per-job code reload any more; after changing job code run `ddev artisan horizon:terminate` and DDEV supervisord restarts it. `horizon:listen` was not used (needs Node + chokidar).
2. **`*` wildcard environment.** `production`, `local` and `*` all exist; `production` and `*` inherit the defaults unchanged, so a mistyped APP_ENV still starts workers instead of zero.
3. **retry_after 90 -> 330.** `REDIS_QUEUE_RETRY_AFTER` default raised so it stays above the supervisor timeout 300 s (otherwise a long job is handed to a second worker). Chain, asserted by HorizonConfigTest: KokpitJob 60 < supervisor 300 < retry_after 330; supervisor 300 < stopwaitsecs 360 (read from your supervisor-horizon.ini). The variable is deliberately not documented in .env.example.

Also notable: `tries` is 1 at supervisor level (only jobs that declare their own policy, like KokpitJob with Tries(3), are retried), and the `HORIZON_MAX_PROCESSES` cap defaults to 3 per container.

### Deploy mismatches found, reported, not fixed

Your files `.deployignore`, `site.conf.tmpl`, `supervisor-horizon.ini` and `zerops.yml` are unchanged (`git diff 8c72141` on them is empty). These parts of the repo still describe the old layout:

- zerops.yml has one `backend` setup, but deploy.yml, `ZeropsConfigTest` (14 failing tests, see baseline), README (Deploy section: "three services app, worker, scheduler") and CONTRIBUTING still assume app/worker/scheduler pushes.
- The readiness check, the health check and the `envVariables` of the old zerops.yml are gone from the new one; the app guard (`ProductionConfigGuard`) and `.env.example` documentation tests relied on them.
- The build uses `--ignore-platform-reqs`, which would hide a missing `ext-pcntl` / `ext-posix` that Horizon needs at runtime. Both are loaded in DDEV; confirm them in the Zerops runtime.
- The zerops.yml crontab runs `schedule:run` on all containers, so any schedule without `onOneServer` runs once per container. The new `horizon:snapshot` uses `onOneServer`; the existing heartbeat entries (`kokpit-heartbeat`, `kokpit-worker-heartbeat`) do not, and `onOneServer` needs a shared cache store (Redis) to work.

## Self-Check: PASSED

- Created files exist: HorizonServiceProvider.php, config/horizon.php, HorizonAccessTest.php, HorizonConfigTest.php (FOUND)
- Commits 5f14027, 9365719, 28ddc68, 72def1b, 8f37d5c are ancestors of HEAD (FOUND); `git rev-list --count` from the ledger base = 5
- Pint and PHPStan clean, check-sensitive clean on every committed file, full suite = baseline failures only
