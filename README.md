# Kokpit

Kokpit is an open-source web CRM/ERP for a freelancer or a small company. It follows the whole flow from client to project, task, tracked time, billing, invoice, payment and income, so that tracked time turns into an issued, payable invoice in one pass and no unbilled time or unpaid invoice slips through unnoticed.

One instance serves one company. The primary user is a single Admin; client accounts (role Partner) get a restricted view in the same panel and never see measured time, rates, prices, finance or another client's data.

The platform foundation is in place (database conventions, Czech localisation, sign-in with two-factor authentication, access isolation, CI). The business features arrive in later phases.

Stack: PHP 8.5, Laravel 13, Filament 5 (single panel in SPA mode), PostgreSQL 18, Redis and an S3-compatible private bucket (RustFS in development).

## Licence

Kokpit is released under the GNU Affero General Public License version 3 only, SPDX identifier `AGPL-3.0-only`. The full text is in [LICENSE](LICENSE). If you run a modified version for users over a network, section 13 of the licence asks you to offer them the source of your version.

## Requirements

- Docker and [DDEV](https://ddev.readthedocs.io/) 1.25 or newer. DDEV supplies PHP 8.5, PostgreSQL 18, Redis, RustFS, Mailpit, the queue worker and the scheduler, so nothing else has to be installed on your machine.
- Contributors also need `lefthook` and `gitleaks` (the pre-commit hook); see [CONTRIBUTING.md](CONTRIBUTING.md).

## Install

Everything below uses only this README and `.env.example`.

    git clone <repository URL>
    cd kokpit
    ddev start
    cp .env.example .env
    ddev composer install
    ddev artisan key:generate
    ddev artisan migrate
    ddev artisan kokpit:install

`ddev start` brings up the web container, PostgreSQL, Redis, RustFS (bucket `kokpit-dev`), the queue worker and the scheduler. The two daemons wait until `vendor/` exists and the application boots, so they do not restart-loop before `ddev composer install` has run. Check them with `ddev describe` and `ddev exec supervisorctl status`.

`.env.example` is a DDEV-ready template: copying it is the only configuration step. It already sets `APP_LOCALE=cs`, so the panel is Czech out of the box. If you keep an older `.env`, make sure it carries `APP_LOCALE=cs` as well.

`ddev artisan kokpit:install` asks for the name, e-mail address and password of the single Admin (at least 12 characters) and prints the panel URL, `https://kokpit.ddev.site/admin` in DDEV. It refuses to run a second time once an Admin exists. For automation only, run it with `--no-interaction --name=... --email=...` and pass the password in the `KOKPIT_ADMIN_PASSWORD` environment variable of that one command; never keep that variable in `.env`.

## First sign-in and two-factor authentication

Open the panel URL, sign in as the Admin and set up an authenticator app (TOTP) on the page you are sent to. The Admin cannot reach the dashboard before two-factor authentication is set up. Save the recovery codes: they are shown once, and each works only once.

`KOKPIT_REQUIRE_ADMIN_2FA` in `.env` switches the Admin requirement off for local development and tests only. A production instance refuses to boot unless it is `true`.

### Recovery

- Lost device or lost recovery codes: run `ddev artisan kokpit:admin:reset-2fa <e-mail>` (without DDEV: `php artisan kokpit:admin:reset-2fa <e-mail>`). It clears the stored TOTP secret and recovery codes of that user, after a confirmation (`--force` skips it), and the user sets two-factor authentication up again at the next sign-in. It needs shell access to the server; there is deliberately no web equivalent.
- After an `APP_KEY` rotation the stored TOTP secrets can no longer be decrypted. Keep the previous key in `APP_PREVIOUS_KEYS` (a comma-separated list in `.env`) so they stay readable, or reset the two-factor authentication with the command above.
- There is no password recovery path for the Admin yet. Keep access to the server shell.

## Deploy

Production runs on Zerops as one service, `backend`, built from the single setup in `zerops.yml`: nginx and PHP-FPM serve the panel, supervisord runs the Horizon queue worker (`supervisor-horizon.ini`) and a crontab runs the scheduler every minute, next to PostgreSQL, Valkey (Redis) and private object storage. Nothing in the repository holds a secret or an environment value: every environment variable is set in the Zerops UI, and the access token lives in the GitHub `production` environment.

A deploy starts only from a published release tagged `v*` (never a prerelease) or from a manual run of the `Deploy` workflow, and it waits for the approval of the `production` environment. The database is migrated once per deploy through `zsc execOnce`, then every container runs `php artisan kokpit:deploy:verify`, and a failed migration or a failed check ends the deploy before traffic switches, with the previous version still serving. Horizon is started by the last init command on every container start, only after both passed, and supervisord autostart brings it back after a restart. The build installs the PHP dependencies only (there is no frontend build yet). Two commands check a running instance from its shell:

    php artisan kokpit:deploy:verify
    php artisan kokpit:storage:check

The first confirms that the database and Redis answer and that no migration is pending; the second proves the private object storage (upload, signed read, refused unsigned read, delete). The manual GitHub and Zerops settings, rollback and the migration rules are in the "Deploy (maintainer, manual)" section of [CONTRIBUTING.md](CONTRIBUTING.md).

## Operations

Two background processes must run next to the web container, or the application degrades without a visible error:

- the queue worker runs every background job. Without it jobs wait in Redis and nothing is processed.
- the scheduler runs the periodic tasks, among them the heartbeats that the System page reads. Every scheduled task is registered with `->onOneServer()`, so the scheduler may run on several containers and each task still runs once. The lock lives in the cache, so production needs the shared Redis store (`CACHE_STORE=redis`, refused otherwise at boot).

In DDEV both are daemons that `ddev start` brings up (`ddev exec supervisorctl status` shows them); on Zerops supervisord runs Horizon and the crontab runs `schedule:run` every minute on every container of the `backend` service.

The queue worker is Laravel Horizon (`php artisan horizon`). Its dashboard is at `/horizon` (`https://kokpit.ddev.site/horizon` in DDEV). Only the Admin may open it, and while two-factor enforcement is on only after setting up 2FA; a Partner or a guest gets 403. In DDEV the `queue-worker` daemon runs Horizon; after changing job code, run `ddev artisan horizon:terminate` and the daemon starts it again on the new code. The scheduler takes a metrics snapshot every five minutes, and `HORIZON_MAX_PROCESSES` caps the worker processes per container (default 3, 2 in DDEV).

The Admin finds the state of both on the System page (menu "Systém", `/admin/system`, `https://kokpit.ddev.site/admin/system` in DDEV). It lists the failed jobs, the age of the oldest waiting job and the scheduler heartbeat as OK, Warning or Error, and refreshes itself every 30 seconds. A Partner cannot open it.

When a background job fails for good, the Admin gets an alert by e-mail and in the bell of the panel. The e-mail needs working mail settings (the `MAIL_*` values in `.env`; DDEV delivers to Mailpit, a production instance needs a real mail service). The bell works without mail, so with broken mail settings the failure is still visible in the panel, and the System page shows it too.

To prove that the private object storage works from the current configuration, run the storage check. It writes a throwaway object, reads it through a temporary URL, confirms that an unsigned read is refused, deletes the object and prints the result of each step:

    ddev artisan kokpit:storage:check

## Language and time

The whole interface is Czech (`lang/cs`), the fallback locale is English. Times are stored in UTC and shown in Europe/Prague as `j. n. Y H:i`.

## Quality gates

Run the PHP gates inside DDEV:

    ddev composer ci

It runs the Pest suite (`composer test`), Pint (`composer lint`), Larastan level 8 (`composer stan`) and the licence allowlist (`composer check-licenses`). The shell self-tests of the repository hygiene tooling run on the host:

    bash scripts/tests/run.sh

CI (workflow `Hygiene`) runs the same gates on every pull request; the single required check is `CI Passed`.

## Fictional data only

This is a public repository. Never put client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IP addresses, hostnames, tokens or personal absolute paths into code, tests, fixtures, docs or commit messages. Use `example.com` addresses, the company ID `12345678` and paths like `/Users/example/`. A pre-commit hook and CI enforce this; see [CONTRIBUTING.md](CONTRIBUTING.md).

## Contributing and security

- [CONTRIBUTING.md](CONTRIBUTING.md): setup, conventions, CI and the hygiene rules.
- [SECURITY.md](SECURITY.md): how to report a vulnerability, and what to do if something sensitive leaks.
