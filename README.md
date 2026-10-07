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
