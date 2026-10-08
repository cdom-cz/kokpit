# Phase 3: Zerops rehearsal checklist

Status: **pending, non-blocking manual verification** (resolved open question 4 of the Phase 3 research). Nothing in Phase 3 waits for it. Revised for the single-setup `backend` manifest (owner commit 8c72141) by quick task 261008-bp0; the earlier assumption of separate worker and scheduler services is obsolete. Plan 03-18 delivered `kokpit:deploy:verify`, `.github/workflows/deploy.yml` and the manual-settings checklist; this document lists what only a real Zerops project can prove.

Requirement: FND-15. Decision: D-18 (once-per-deploy migrations, previous version keeps serving).

Where results go: tick the box and write the observed result under the item (one line, no hostnames, no ids, no tokens). A failed item becomes a follow-up task or a quick fix; if it changes `zerops.yml` (owned by the maintainer), `tests/Feature/Repo/ZeropsConfigTest.php` is updated in the same change.

All names below are generic. Never copy a real hostname, service id, project id or token into this file or into the repository.

## Prerequisites

- [ ] A throwaway Zerops project (delete it afterwards) with PostgreSQL 18 named `db`, Valkey named `redis`, private object storage named `storage`, and one Alpine `php-nginx@8.5` service for the `backend` setup. The service names matter: the `${db_*}`, `${redis_*}` and `${storage_*}` references assume them.
- [ ] Every environment variable from the CONTRIBUTING section "Deploy (maintainer, manual)" is set in the Zerops UI (never in `zerops.yml`, which only references `${RUNTIME_VITE_APP_NAME}` for the build).
- [ ] The native Zerops Git integration (GitHub or GitLab) is switched off for the `backend` service.
- [ ] The GitHub side is set up as in the CONTRIBUTING section "Deploy (maintainer, manual)": `production` environment with a required reviewer, environment secret `ZEROPS_TOKEN` and the environment variable `ZEROPS_SERVICE_ID`. For a rehearsal a fork or a scratch repository with the same workflow is acceptable.

## Checks

- [ ] **1. One push after approval.** Start the `Deploy` workflow by manual dispatch on `main`. The `verify` job passes, the `deploy` job waits for the approval of the `production` environment, and after the approval pushes the `backend` setup once.
  Expected: one successful pipeline; the version name in Zerops is `main-` plus the first 12 characters of the commit (for a release: the `v*` tag); no other trigger started a deploy.

- [ ] **2. PHP extensions.** In a shell of the backend container run `php -m`.
  Expected: the list contains `redis`, `pdo_pgsql`, `intl`, `bcmath`, `gd`, `zip`, `dom`, `mbstring` and `zlib` (the last four are what Dompdf needs, see `03-SPIKE-PDF.md`) and, for Horizon, `pcntl` and `posix`. Also `php -r 'var_dump(gd_info()["PNG Support"]);'` prints `bool(true)`, and the effective `memory_limit` is noted for the Phase 8 report rendering.
  The build runs `composer install` with `--ignore-platform-reqs`, which hides a missing extension until runtime, so this check is the only place it shows. A fix (an Alpine package in `run.prepareCommands`) goes to the maintainer, who owns `zerops.yml`.

- [ ] **3. Environment variable references.** In the backend container run `php artisan about --only=environment,drivers` and `php artisan kokpit:deploy:verify`.
  Expected: the database, Redis and storage values resolve; `kokpit:deploy:verify` prints three "v pořádku" lines and exits 0. A wrong variable name is corrected in the Zerops UI.

- [ ] **4. HTTPS URLs behind the balancer.** Open the sign-in page of the panel over HTTPS and inspect the page source.
  Expected: the form action and every generated link and asset URL start with `https://`, the session cookie is sent, and a request forwarded as HTTP is not served as a mixed-content page. This confirms `trustProxies(at: '*')`.

- [ ] **5. Valkey eviction policy.** In the Valkey service run `CONFIG GET maxmemory-policy`.
  Expected: `volatile-lru` or `noeviction`, as set in the Zerops service settings (the default `allkeys-lru` may evict queue keys that have no TTL).

- [ ] **6. Horizon and the scheduler cron.** In the backend container run `sudo supervisorctl status horizon` and `php artisan horizon:status`, then open the System page of the panel as the Admin.
  Expected: `horizon` is RUNNING under supervisord, `horizon:status` reports running, no migration command ran outside the `execOnce` line, and the scheduler heartbeat on the System page shows OK within a few minutes (the crontab runs `schedule:run` every minute). With two containers, note whether each scheduled task runs once or once per container.

- [ ] **7. Storage check on Zerops object storage.** In the backend container run `php artisan kokpit:storage:check`.
  Expected: all five steps "v pořádku", exit 0, no test object left in the bucket. This also confirms `AWS_USE_PATH_STYLE_ENDPOINT=true` and the endpoint reference against the Zerops storage.

- [ ] **8. A failing migration (D-18).** On a throwaway branch add a migration that throws, merge it to the rehearsal `main`, publish a release and approve the deploy.
  Expected: the pipeline fails (the migration error is in the log) and the panel keeps serving the previous version. `zerops.yml` has no readiness gate any more, so record explicitly whether a failing `initCommands` entry fails the deploy and the previous version keeps serving, and whether a pending migration with `zsc execOnce` skipped (a second container of the same version) goes live; in that case run `php artisan kokpit:deploy:verify` by hand and note its result. Then remove the migration and confirm the next deploy goes through.

- [ ] **9. Chromium (only if the PDF decision changes).** Not needed while Dompdf stays the PDF engine (`03-SPIKE-PDF.md`). If a later phase switches to a Chromium engine, record here whether it runs within the container limits of the `php-nginx@8.5` service.

## Extra observations to record

- [ ] Which `execOnce` variable Zerops expands in `initCommands`, `${appVersionId}` or `${ZEROPS_appVersionId}`, and that a second container of the same version skips the migration. `ZeropsConfigTest` accepts both spellings until this is settled; then it is narrowed to the one that works.
- [ ] Whether `zcli service push --workspace-state clean` with `deployFiles: ./` and `.deployignore` uploads exactly the expected files (`tests/` and `scripts/` are not excluded).
- [ ] Whether an `initCommands` failure fails the deploy by itself (research assumption A4).

## Known follow-ups (as of quick task 261008-bp0)

- `laravel/horizon` and `config/horizon.php` were not installed when the manifest was committed. The parallel quick task 261008-bec installs them; `php artisan horizon:terminate` and `supervisorctl start horizon` in `initCommands` need it.
- The repository has no `package.json` and no `pnpm-lock.yaml`, so `pnpm install` and `pnpm run build` in `buildCommands` fail until a frontend toolchain exists. The maintainer decides whether the commands stay or wait.
- `schedule:run` with `allContainers: true` runs on every container, so each scheduled task needs `->onOneServer()` with a shared cache lock, or the service stays at one container. The maintainer decides.
- The readiness gate `kokpit:deploy:verify` (`app/Console/Commands/DeployVerifyCommand.php`, kept in the code) is no longer wired into the manifest, so a half-applied migration is not stopped by a readiness check. The maintainer decides whether to wire it back.
- Confirm `${appVersionId}` versus `${ZEROPS_appVersionId}` (see the extra observations).
- The build's `--ignore-platform-reqs` hides missing PHP extensions (`pcntl`, `posix`, `redis`) until runtime. The maintainer decides whether the build should check them.
