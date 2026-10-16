# Kokpit

Kokpit is an open-source web CRM/ERP for a freelancer or a small company. It follows the whole flow from client to project, task, tracked time, billing, invoice, payment and income, so that tracked time turns into an issued, payable invoice in one pass and no unbilled time or unpaid invoice slips through unnoticed.

One instance serves one company. The primary user is a single Admin; client accounts (role Partner) get a restricted view in the same panel and never see measured time, rates, prices, finance or another client's data.

The platform foundation is in place (database conventions, Czech localisation, sign-in with two-factor authentication, access isolation, CI). The business features arrive in later phases.

Stack: PHP 8.5, Laravel 13, Filament 5 (single panel in SPA mode), PostgreSQL 18, Redis and an S3-compatible private bucket (RustFS in development).

## Licence

Kokpit is released under the GNU Affero General Public License version 3 only, SPDX identifier `AGPL-3.0-only`. The full text is in [LICENSE](LICENSE). If you run a modified version for users over a network, section 13 of the licence asks you to offer them the source of your version.

## Requirements

- Docker and [DDEV](https://ddev.readthedocs.io/) 1.25 or newer. DDEV supplies PHP 8.5, PostgreSQL 18, Redis, RustFS, Mailpit, the queue worker and the scheduler, so nothing else has to be installed on your machine.
- PostgreSQL 18 with ICU support, because the application sorts Czech names with the ICU collation `cs-CZ-x-icu`. The official PostgreSQL images and the DDEV database have it; a server built without ICU does not, and `kokpit:deploy:verify` then fails the readiness gate on purpose, with no fallback to the default collation (which would sort Czech names wrongly). The remedy is an ICU-enabled PostgreSQL.
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
- Forgotten password: the sign-in page links to "forgot password" (`/admin/password-reset/request`), which works for the Admin as well as for a Partner. It always shows the same notice, whether or not the address has an account, and mails a link only to an account that can sign in. After the reset the Admin still has to pass two-factor authentication, so a lost device is handled with the command above. The new password has at least 12 characters.

## Deploy

Production runs on Zerops as one service, `kokpit`, built from the single setup in `zerops.yml`: nginx and PHP-FPM serve the panel, supervisord runs the Horizon queue worker (`supervisor-horizon.ini`) and a crontab runs the scheduler every minute, next to PostgreSQL, Valkey (Redis) and private object storage. Nothing in the repository holds a secret or an environment value: every environment variable is set in the Zerops UI, and the access token lives in the GitHub `production` environment.

A deploy starts only from a published release tagged `v*` (never a prerelease) or from a manual run of the `Deploy` workflow, and it waits for the approval of the `production` environment. The database is migrated once per deploy through `zsc execOnce`, then every container runs `php artisan kokpit:deploy:verify`, and a failed migration or a failed check ends the deploy before traffic switches, with the previous version still serving. Horizon is started by the last init command on every container start, only after both passed, and supervisord autostart brings it back after a restart. The build installs the PHP dependencies only: the build image carries Node.js 24, but there is no frontend build yet. Two commands check a running instance from its shell:

    php artisan kokpit:deploy:verify
    php artisan kokpit:storage:check

The first confirms that the database and Redis answer, that no migration is pending and that the database server provides the Czech ICU collation `cs-CZ-x-icu`; the second proves the private object storage (upload, signed read, refused unsigned read, delete). The manual GitHub and Zerops settings, rollback and the migration rules are in the "Deploy (maintainer, manual)" section of [CONTRIBUTING.md](CONTRIBUTING.md).

## Operations

Two background processes must run next to the web container, or the application degrades without a visible error:

- the queue worker runs every background job. Without it jobs wait in Redis and nothing is processed.
- the scheduler runs the periodic tasks, among them the heartbeats that the System page reads and, every five minutes, the forgotten-timer notice (a timer that has run longer than `time.long_running_hours` in `config/kokpit.php` (12 hours) puts one notice in the Admin's bell and keeps running), every hour, the overrun safety net (a running entry that has run longer than `time.max_running_hours` in `config/kokpit.php` (48 hours) is ended at its start plus 48 hours; `ddev artisan kokpit:time:end-overrun` runs it by hand) and, once a night, the activity-log prune (rows older than `clean_after_days` in `config/activitylog.php` (365 days) are deleted). Every scheduled task is registered with `->onOneServer()`, so the scheduler may run on several containers and each task still runs once. The lock lives in the cache, so production needs the shared Redis store (`CACHE_STORE=redis`, refused otherwise at boot).

In DDEV both are daemons that `ddev start` brings up (`ddev exec supervisorctl status` shows them); on Zerops supervisord runs Horizon and the crontab runs `schedule:run` every minute on every container of the `kokpit` service.

The queue worker is Laravel Horizon (`php artisan horizon`). Its dashboard is at `/horizon` (`https://kokpit.ddev.site/horizon` in DDEV). Only the Admin may open it, and while two-factor enforcement is on only after setting up 2FA; a Partner or a guest gets 403. In DDEV the `queue-worker` daemon runs Horizon; after changing job code, run `ddev artisan horizon:terminate` and the daemon starts it again on the new code. The scheduler takes a metrics snapshot every five minutes, and `HORIZON_MAX_PROCESSES` caps the worker processes per container (default 3, 2 in DDEV).

The Admin finds the state of both on the System page (menu "Systém", `/admin/system`, `https://kokpit.ddev.site/admin/system` in DDEV). It lists the failed jobs, the age of the oldest waiting job and the scheduler heartbeat as OK, Warning or Error, and refreshes itself every 30 seconds. A Partner cannot open it.

When a background job fails for good, the Admin gets an alert by e-mail and in the bell of the panel. The e-mail needs working mail settings (the `MAIL_*` values in `.env`; DDEV delivers to Mailpit, a production instance needs a real mail service). The bell works without mail, so with broken mail settings the failure is still visible in the panel, and the System page shows it too.

Invitations to a client (Partner) account and password reset links are e-mails sent through the queue, after the database transaction commits. They need working mail settings (the `MAIL_*` values in `.env`) and a running queue worker; in DDEV they arrive in Mailpit (`ddev launch -m`). If the mail settings are wrong or the worker is stopped, the invitation is created but the e-mail is not delivered, and the Admin can resend it from the client's "Pozvánky" tab.

Task notifications reach the Admin and the Partners by e-mail and in the bell: a new task or comment from a Partner, a Partner's edit of a task description (announced without its text), an escalation, a comment from the Admin and a change of status, priority or assignee. The e-mails go through the queue, after the database transaction commits, so they need working mail settings (the `MAIL_*` values in `.env`) and a running queue worker, as for invitations; in DDEV they arrive in Mailpit (`ddev launch -m`). The bell entries are written by the same queued job, so a stopped worker delays both. Every user switches each event and channel (e-mail, bell) on or off on the own profile page ("Upozornění"); a switch can only reduce what is sent. An internal comment is never announced to a Partner.

To prove that the private object storage works from the current configuration, run the storage check. It writes a throwaway object, reads it through a temporary URL, confirms that an unsigned read is refused, deletes the object and prints the result of each step:

    ddev artisan kokpit:storage:check

### Exchange rates and reports

Amounts in a foreign currency are converted to CZK with the daily rates of the Czech National Bank (CNB). A fresh instance holds no rate list, and the scheduler downloads only on Czech working days, so after the first deploy do the following once, in this order:

1. Download the first list: run `php artisan kokpit:rates:download` on the server (`ddev artisan kokpit:rates:download` in DDEV), or press "Stáhnout dnešní kurzy" on the "Kurzy ČNB" page of the panel. Until a list exists the "Datum posledního kurzu" row of the System page shows Error ("Kurzy ještě nebyly staženy"), which is expected on a new instance and not a fault.
2. Fill the history: open "Kurzy ČNB", press "Doplnit historii" and fill "Od" with the first day you have time entries for and "Do" with today or earlier. The lists are stored in the background (whole years at a time), so this needs the queue worker; one request covers at most 3660 days, and the earliest possible day is 1. 1. 2000.

After that the scheduler keeps the rates current without help. On every Czech working day (Monday to Friday, not a public holiday) it tries to download the day's list at 15:00, 16:00 and 17:00 Prague time, the CNB publishes it about 14:30, and makes a final attempt at 18:00. A failed attempt before the final one is silent. If the final attempt leaves the day without a list, every Admin gets an alert by e-mail and in the bell, and the "Datum posledního kurzu" row of the System page turns to Warning after one missing working day and to Error after two. The same run fills a missing working day of the last 31 days by itself. The rates need no API key, only the outgoing HTTPS access of the server to the CNB host named in `config/services.php`; the CNB host can be changed with `CNB_API_BASE_URL` in the environment of the instance.

The time report ("Report času") exports to CSV and XLSX in the background and keeps each file for 7 days, then deletes it together with its record every night. The work report ("Pracovní výkaz") is a PDF made on the spot. The PDF and the XLSX export need the PHP extensions `dom`, `mbstring`, `gd` and `zip`, and a rendering of a long report needs enough memory: the deploy rehearsal on the target server must confirm that `php -m` lists all four and read the effective `memory_limit` of the PHP-FPM and the queue worker (`php -i | grep memory_limit` shows the command line value, which may differ from the one of the web process). The PDF renderer raises a limit below 256M for its own run, so a lower value is not fatal, but a rehearsal on a long period should be done once.

### Documents and finance

Files (documents) and money records need three things from the operator.

**The private bucket takes the uploads straight from the browser.** The application runs on more than one web container, so a file chosen in a form is not sent to one container: the browser uploads it directly to the private bucket (a presigned `PUT`, valid five minutes, object under `livewire-tmp/`) and the submit, served by any container, reads it from there and stores it as a document. Two rules must exist on the bucket, and the deploy rehearsal checks both:

1. A CORS rule that allows the `PUT` method from the origin of the instance only (the URL the panel is served from, no wildcard, no other origin, no read methods). Without it the browser refuses the upload and the file field shows an error.
2. A one-day expiry rule on the prefix `livewire-tmp/`. Livewire does not clean temporary uploads on S3 by itself. Set the rule with `php artisan livewire:configure-s3-upload-cleanup` on the server, or in the storage console of the provider.

`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` stays unset in production: the application then uses the bucket, and it refuses to boot in production when the temporary disk is anything but a configured S3 disk. The one place that sets it is DDEV (`local`, see `.env.example`), because the DDEV object storage cannot be reached from a browser. Downloads are different: every download is streamed through the application behind a signed, short-lived link bound to the signed-in user, never a link to the bucket, so the bucket needs no read access from the browser and no CORS rule for reading.

**ZIP archives live 7 days.** "Stáhnout jako ZIP" in the file list builds one archive in the background (this needs the queue worker), tells the Admin in the bell when it is ready and keeps it for 7 days (`kokpit.documents.archive.ttl_days` in `config/kokpit.php`); the scheduler deletes the file and its record every night. One archive holds at most 200 files and 200 MB of source files. The PHP extension `zip` is required, as for the XLSX export.

**The system income category is seeded.** The migrations create a Czech starter set of finance categories and one frozen system income category ("Úhrady faktur"), which invoicing books payments into. It can be renamed or reordered in "Kategorie" but not archived, deleted or changed in type. Nothing needs to be created by hand; if the category is missing the instance was not migrated.

The finance overview ("Přehled financí") and the transaction list are Admin only and show CZK figures: a foreign amount is converted with the CNB list of the transaction date once, when it is saved, and the rate used is stored with it, so a later list never changes a recorded figure. A transaction in a foreign currency on a day without a list (a weekend) uses the newest earlier list; when the instance holds no list at all the form asks for the CZK figure instead of waiting, so download the first list as described above.

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
