# Phase 3: Zerops rehearsal checklist

Status: **pending, non-blocking manual verification** (resolved open question 4 of the Phase 3 research). Nothing in Phase 3 waits for it. Plan 03-18 delivered `zerops.yml`, `kokpit:deploy:verify`, `.github/workflows/deploy.yml` and the manual-settings checklist; this document lists what only a real Zerops project can prove.

Requirement: FND-15. Decision: D-18 (once-per-deploy migrations, previous version keeps serving).

Where results go: tick the box and write the observed result under the item (one line, no hostnames, no ids, no tokens). A failed item becomes a follow-up task or a quick fix; if it changes `zerops.yml`, `tests/Feature/Repo/ZeropsConfigTest.php` is updated in the same change.

All names below are generic. Never copy a real hostname, service id, project id or token into this file or into the repository.

## Prerequisites

- [ ] A throwaway Zerops project (delete it afterwards) with PostgreSQL 18 named `db`, Valkey named `redis`, private object storage named `storage`, and three `php-nginx@8.5` services named `app`, `worker` and `scheduler` (the scheduler with exactly one container). The service names matter: the `${db_*}`, `${redis_*}` and `${storage_*}` references in `zerops.yml` assume them.
- [ ] `APP_KEY`, `APP_URL` and the mail credentials are set as project or service secrets in the Zerops GUI (never in `zerops.yml`).
- [ ] The native Zerops Git integration (GitHub or GitLab) is switched off for all three services.
- [ ] The GitHub side is set up as in the CONTRIBUTING section "Deploy (maintainer, manual)": `production` environment with a required reviewer, environment secret `ZEROPS_TOKEN` and the three service id variables. For a rehearsal a fork or a scratch repository with the same workflow is acceptable.

## Checks

- [ ] **1. Ordered deploy after approval.** Start the `Deploy` workflow by manual dispatch on `main`. The `verify` job passes, the `deploy` job waits for the approval of the `production` environment, and after the approval pushes the app, then the worker, then the scheduler.
  Expected: three successful pipelines in that order; no other trigger started a deploy.

- [ ] **2. PHP extensions.** In a shell of each of the three containers run `php -m`.
  Expected: the list contains `redis`, `pdo_pgsql`, `intl`, `bcmath`, `gd`, `zip`, `dom`, `mbstring` and `zlib` (the last four are what Dompdf needs, see `03-SPIKE-PDF.md`). Also `php -r 'var_dump(gd_info()["PNG Support"]);'` prints `bool(true)`, and the effective `memory_limit` is noted for the Phase 8 report rendering.
  If `redis` is missing: add `run.prepareCommands` with the Alpine package (`sudo apk add --no-cache php85-pecl-redis`, assumed) or set `REDIS_CLIENT=predis` and add the package. If another extension is missing, install it in `build.prepareCommands` and `run.prepareCommands`; never use `--ignore-platform-reqs`.

- [ ] **3. Environment variable references.** In the app container run `php artisan about --only=environment,drivers` and `php artisan kokpit:deploy:verify`.
  Expected: the database, Redis and storage values resolve (the references `db_hostname`, `db_port`, `db_dbName`, `db_user`, `db_password`, `redis_hostname`, `redis_port`, `storage_accessKeyId`, `storage_secretAccessKey`, `storage_bucketName`, `storage_apiUrl` are assumed names); `kokpit:deploy:verify` prints three "v pořádku" lines and exits 0. If a name differs, correct `zerops.yml` and `ZeropsConfigTest`.

- [ ] **4. HTTPS URLs behind the balancer.** Open the sign-in page of the panel over HTTPS and inspect the page source.
  Expected: the form action and every generated link and asset URL start with `https://`, the session cookie is sent, and a request forwarded as HTTP is not served as a mixed-content page. This confirms `trustProxies(at: '*')`.

- [ ] **5. Valkey eviction policy.** In the Valkey service run `CONFIG GET maxmemory-policy`.
  Expected: `volatile-lru` or `noeviction`, as set in the Zerops service settings (the default `allkeys-lru` may evict queue keys that have no TTL).

- [ ] **6. Worker and scheduler start.** Check the logs of the worker and the scheduler services, then open the System page of the panel as the Admin.
  Expected: the worker runs `queue:work`, the scheduler runs `schedule:work`, no migration command ran in either, and both heartbeats on the System page show OK within a few minutes.

- [ ] **7. Storage check on Zerops object storage.** In the app container run `php artisan kokpit:storage:check`.
  Expected: all five steps "v pořádku", exit 0, no test object left in the bucket. This also confirms `AWS_USE_PATH_STYLE_ENDPOINT=true` and the endpoint reference against the Zerops storage.

- [ ] **8. A failing migration keeps the previous version serving (D-18).** On a throwaway branch add a migration that throws, merge it to the rehearsal `main`, publish a release and approve the deploy.
  Expected: the app pipeline fails (the migration error is in the log), the readiness check never passes, the new version never takes traffic, and the panel keeps serving the previous version without interruption. Then remove the migration and confirm the next deploy goes through. Also note whether a pending migration (migration file present, `zsc execOnce` skipped) is caught by `kokpit:deploy:verify` alone.

- [ ] **9. Chromium (only if the PDF decision changes).** Not needed while Dompdf stays the PDF engine (`03-SPIKE-PDF.md`). If a later phase switches to a Chromium engine, record here whether it runs within the container limits of the `php-nginx@8.5` service.

## Extra observations to record

- [ ] Whether `extends` with a `null` override works (research assumption A7; `zerops.yml` does not rely on it, the three setups are written out in full).
- [ ] Whether an `initCommands` failure in the app setup fails the deploy by itself (research assumption A4); the readiness check is the guarantee either way.
- [ ] Whether `zcli service push --workspace-state clean` uploads exactly the files listed in `deployFiles` and nothing else.
