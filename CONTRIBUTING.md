# Contributing to Kokpit

Kokpit is a public AGPL-3.0 project (SPDX `AGPL-3.0-only`). This guide covers repository hygiene, the development setup and the conventions every change follows. Reporting a vulnerability is described in `SECURITY.md`; installing the application is described in `README.md`.

## Repository hygiene

The rule is: **Fictional data only.** Never put client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IP addresses, hostnames, tokens or personal absolute paths into code, tests, fixtures, docs, planning docs under `.planning/`, or commit messages.

Use `example.com` addresses, the placeholder company ID `12345678`, paths like `/Users/example/`, and test fakes assembled at runtime from fragments, so that no single line of a test file looks like a real value.

Review procedure before every commit:

1. `git status`
2. `git diff --staged`
3. `scripts/check-sensitive.sh`

Commit author name and e-mail are Git metadata and are not scanned. Each contributor decides what identity to commit with.

## Setup

Required tools: lefthook and gitleaks. Optional: shellcheck (lints the scripts).

Tested versions: lefthook 2.1.17 (minimum 2.1.0) and gitleaks 8.30.1.

Install with either of:

    brew install lefthook gitleaks
    mise use -g lefthook@2.1.17 gitleaks@8.30.1

Do not use the npm package for lefthook; use one of the commands above.

Run `scripts/install-hooks.sh` after every fresh clone. It checks both tools, refuses a `core.hooksPath` override, runs `lefthook install` and verifies the hook is in place. It is idempotent.

The pre-commit hook runs two jobs in order: `sensitive-content` (`scripts/check-sensitive.sh` over the staged changes), then `gitleaks` over the staged changes. If lefthook is not installed, the generated hook aborts the commit instead of skipping it.

Run the self-tests with `scripts/tests/run.sh` (`--quick` skips the tests that need lefthook or gitleaks). `scripts/tests/run-in-ubuntu.sh` repeats the quick suite in a container with the awk and grep flavours CI uses.

## Development

The application runs in a DDEV project (PHP 8.5, PostgreSQL 18, Redis, RustFS, Mailpit, a queue worker and a scheduler). `README.md` has the install sequence; day to day:

    ddev start
    ddev composer install
    ddev artisan migrate
    ddev exec vendor/bin/pest --filter=<name>

Run every PHP command through DDEV (`ddev composer ...`, `ddev artisan ...`, `ddev exec vendor/bin/pest ...`). The tests use the separate `kokpit_test` database that DDEV creates on start; `tests/TestCase.php` refuses any database whose name does not end in `_test`.

Composer scripts (the PHP gates; `composer ci` runs the first four in order):

| Script | What it runs |
|---|---|
| `composer test` | Pest: the Unit, Feature, Arch, Concurrency and Isolation suites |
| `composer lint` | Pint in check mode (`--test`); run `vendor/bin/pint` to fix |
| `composer stan` | Larastan level 8 |
| `composer check-licenses` | the AGPL-compatible licence allowlist over `composer.lock` |
| `composer ci` | all of the above, the same gates CI runs |

The bash self-tests of the hygiene tooling are a host command: `bash scripts/tests/run.sh`. All gates must be green before a pull request.

### Conventions

Each convention is enforced by a test, so a violation fails `composer test` and not only the review.

- **Keys and time.** Every table has a UUID v7 primary key with the `uuidv7()` column default; foreign keys, morph `*_id` columns and package tables are `uuid` too. Every timestamp is `timestamptz` (`timestampsTz()`, never `timestamps()`); storage is UTC. The schema tests (R1 to R9) read the PostgreSQL catalogue and name the offending column; an exception goes into `PgSchema::EXEMPT` with a reason.
- **Morph aliases.** Polymorphic relations store a short snake_case alias, never a class name. Aliases live only in `MorphMap`; the morph map is enforced, so an unmapped model fails loudly.
- **Money.** Money is the `Money` value object with a `<name>_minor` (bigint, minor units) and a `<name>_currency` (char(3)) column pair and `MoneyCast`. There is no float money. `Money::fromExactMinor` is the single rounding point (half up); `tests/Arch/MoneyBoundaryTest` guards the boundary.
- **Numbering.** Gap-free, duplicate-free numbers (tasks, invoices) come only from `SequenceAllocator`, called inside the caller's own transaction so a rolled-back caller gives the number back. The year is part of the scope key; never hand-roll `max(number) + 1`. Application code does not call the allocator directly: `DocumentNumbering` is the single entry point. `next()` allocates and writes the number of a document kind from the stored pattern, `preview()` shows the number the next allocation would give (a stored or a candidate pattern) and never takes, skips or reuses one, and `nextTaskNumber()` writes task numbers as `KEY-N` with a counter per project. A changed pattern applies only to documents issued afterwards. `tests/Feature/Operations/NumberingTest.php` enforces it.
- **Immutability.** Frozen records (issued invoices, billed time entries) are protected by database triggers built with `Immutability`; a forbidden change raises SQLSTATE `KP001`. Application code is not the only guard.
- **Localisation.** Every user-facing string lives in `lang/cs` (the interface is Czech, the fallback locale is English); no literal Czech or English text in classes or views. Enum labels are translated too. Times show as `j. n. Y H:i` in Europe/Prague, from the defaults set once in `LocalisationServiceProvider`.
- **Ordering.** A list that sorts by a timestamp breaks ties by `id`, so pagination is stable. Text that is sorted for people (names, titles) uses a Czech collation instead of the database default; no sorted text column exists yet, so the first one defines the mechanism and adds the test.
- **Isolation.** A Partner must never see another client's data, and the rule is enforced in the data layer, not by hiding UI. Every model implements `PartnerIsolated` (with `IsolatesPartners`, and a `KokpitPolicy` subclass that grants Partners explicitly; `DeniesPartners` for Admin-only data) or carries `NotPartnerScoped` with a reason. Every Filament resource, page, widget and relation manager carries an `AccessRule` attribute (`Audience::AdminOnly`, `Audience::PartnerAllowed` or `Audience::Guest`, each with a reason; `Guest` is always denied by the panel access checks and is allowed only on a `SimplePage`, see Guest pages below). Every new `PartnerIsolated` model gets one fixture line in `CanaryRegistry` (`tests/Support/CanaryRegistry.php`); the canary tests search every Partner-visible surface for another client's canary string. Known limit: the permission package registers a `Gate::before` callback that runs before `KokpitPolicy`, so a Partner who is granted a permission named like a policy ability would pass the gate before the policy is asked. No permissions exist yet; the first plan that introduces one must close this and extend the isolation tests.
- **Settings.** Every setting is a typed class extending `ValidatedSettings`, listed explicitly in `config/settings.php`; its `rules()` run on every `save()`, so a value written from a command, a job or a test is checked like one from the form. Create and change settings only through a migration under `database/settings` that extends `SettingsMigration` (its `up()` is final and runs `migrate()` in the system context, because the settings rows are Partner-scoped and fail closed). The package cache stays off, as a literal `false`: a cached read would bypass the Partner scope of `SettingsProperty`. A secret setting (a token, a key) goes into the package's `encrypted()` list of its class and never into a plain property. `SettingsProperty::PARTNER_VISIBLE_GROUPS` is the one widening point and is empty; a group added to it must be added on purpose together with the model's canary fixture. `tests/Feature/Operations/SettingsStorageTest.php` and the model declaration and canary tests (`tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`) enforce it.
- **Audit.** A model whose changes are audited uses `LogsAllowlistedActivity` and declares `#[LoggedAttributes([...])]` on the class itself, in the style of `#[NotPartnerScoped]`. Only the listed attributes are written, only when they change; there is no log-all option, and a missing or empty list throws on the first logged event. The allowlist covers model saves only: a bulk query update raises no model event and writes no row. Changes without a user are logged with a source label (`ActivitySourceLabel`: web, console, job, webhook) resolved from the execution context; a webhook handler wraps its work in `ActivitySource::as(ActivitySourceLabel::Webhook, ...)`. Activity rows are never pruned: the package's clean action is replaced by `RefusingCleanActivityLogAction`, which fails loudly, and nothing schedules it. A record shows its history through a concrete subclass of `ActivityHistoryRelationManager` that carries its own `#[AccessRule(Audience::AdminOnly, ...)]` (the attribute is not inherited, so a subclass without one is denied to everybody). `tests/Arch/ActivityAllowlistTest.php`, `tests/Feature/Operations/ActivityAllowlistColumnsTest.php`, `tests/Feature/Operations/NoPruningTest.php` and `tests/Feature/Operations/ActivityViewsTest.php` enforce it.
- **Jobs and alerts.** Every queued class extends `KokpitJob`, which gives 3 attempts, a backoff of 10 s, 60 s and 5 min, a 60 s timeout and the `RunsAsSystem` middleware (a worker has no user, and the Partner scopes are fail-closed). Add middleware through `jobMiddleware()`; the system context cannot be dropped. Every concrete job declares `#[Idempotent(how: '...')]`: a job can run twice, so it identifies its work by a natural key, checks before it acts, and lets a unique constraint be the last line of defence. The queue connection dispatches after commit, so a job dispatched inside a transaction is invisible to workers until the commit and never pushed after a rollback. A final failure is reported by the `ReportFailedJob` listener through `AdminAlerter`: a synchronous e-mail to the Admin plus a bell notification, neither going through the queue, throttled per job class (`config/kokpit.php`, `alerts`). Delayed jobs and jobs waiting in their backoff count as old in the "oldest pending job" indicator, because its age runs from the creation of the job record; a job with a delay or backoff beyond the warning threshold must account for this. `tests/Arch/JobContractTest.php`, `tests/Feature/Operations/QueueContractTest.php` and `tests/Feature/Operations/AdminAlertTest.php` enforce it.
- **Health indicators.** The System page renders `HealthIndicatorRegistry`, which holds one `HealthIndicator` per `HealthSlot`. Thresholds are fixed values in `config/kokpit.php` (`health`), not editable in the UI. A slot that is still a placeholder ("not available yet") is replaced by the phase that can measure it with `HealthIndicatorRegistry::replace($slot, $indicator)` in a service provider; the indicator must report that slot. An indicator that throws, and a slot without an indicator, show as Error. `tests/Feature/Operations/HealthRegistryTest.php` fails when one of the six slots has no indicator.
- **Storage.** Private files use the `s3` disk, which is configured with `'throw' => true`, so a failed write raises instead of returning false. `php artisan kokpit:storage:check` (`StorageCheck`) proves the disk from configuration alone: write, signed read, refused unsigned read, delete, gone. It never prints a signed URL, a signature or a credential, and neither may any code you add. In DDEV the temporary URLs are built from the container endpoint `http://rustfs:9000`, which resolves only inside DDEV, so a browser download in the Documents phase needs a reachable public endpoint or a custom temporary-URL builder. `tests/Feature/Operations/StorageCheckTest.php` enforces it and runs against RustFS in the `s3` Pest group.
- **Scheduled tasks.** Every event in `routes/console.php` is registered with `->onOneServer()` (closure and job events call `->name()` first, which Laravel requires), because the production crontab runs `schedule:run` on every container. The lock lives in the cache store, which must be the shared Redis in production (`CACHE_STORE` `redis`, refused otherwise by `ProductionConfigGuard`). Leave `SCHEDULE_CACHE_STORE` and `SCHEDULE_CACHE_DRIVER` unset so the scheduler uses that store. `tests/Feature/Operations/ScheduleOnOneServerTest.php` enforces it.

- **Partner-visible data.** A table that a Partner may read carries no Admin-only attribute. The `projects` table is Partner-readable, so its columns come from an allowlist and the rate, price, estimate, billing type and internal note live in `project_billing` (`ProjectBilling`: `DeniesPartners`, `AdminOnlyPolicy`, own uuid key, a unique foreign key to `projects`). `tests/Isolation/PartnerSafeColumnsTest.php` fails on any new column of `projects` that is not on the reviewed list and on a suspicious column name; extend the list only after checking that every Partner may read the new column. The Partner list and detail of a project are built only from the Partner-safe builders of `ProjectColumns` (`partnerColumns()`, `partnerEntries()`), shared with the Admin resource so the two cannot drift; `tests/Isolation/PartnerProjectVisibilityTest.php` pins the column and entry names and walks what a Partner can open. A model that a Partner may read has a real `constrainForPartner` (`Project` by its own client, `Tag` by the tags of visible own projects), a canary fixture in `CanaryRegistry` and a visibility test; `Client`, `Contact` and `ClientInvitation` stay closed to Partners. A project picker in any later phase uses `Project::selectable()` (not archived, client not archived), never its own repeat of that rule. Tasks follow the same rule: the `tasks` table and the `task_comments` table (`TaskComment`, whose Partner scope drops every internal comment) are Partner-readable with pinned column lists (`PartnerSafeColumnsTest`), while the checklist (`TaskChecklistItem`, table `task_checklist_items`) and the billing terms (`TaskBilling`, table `task_billing`) live in Admin-only tables (`DeniesPartners`, `AdminOnlyPolicy`). The Partner list and page of a task are built only from the Partner builders of `TaskColumns` (`partnerColumns()`, `partnerEntries()`, with the pinned name lists `PARTNER_COLUMN_NAMES` and `PARTNER_ENTRY_NAMES`), and `tests/Isolation/PartnerTaskVisibilityTest.php` walks what a Partner can open, canary strings included.
- **Tags.** Tags are typed (`TagType`), and every tag input, column, entry and query passes the type (`TagType::Project->value`, `TagType::Client->value`); an untyped read shows every type. Client tags never reach a Partner. Project tags do (they are shown in the Partner project list), so do not write internal wording into a project tag: this is the accepted risk D-07. `Client` and `Project` override `detachTags()` so that archiving (a soft delete, which also fires the package's `deleted` listener) keeps the tags and only a force delete detaches them; a new taggable model with soft deletes needs the same guard. `tests/Isolation/PartnerTagVisibilityTest.php` enforces the visibility.
- **Domain Actions.** A write to a client, contact, invitation or project goes only through its domain Action (`CreateClient`, `UpdateProject`, `InvitePartner` and so on): the Action validates, locks the parent row where a race matters and throws a `ValidationException` keyed by the bare data key or a `DomainException` for a refusal that belongs to no field. A Filament page or modal that hands its form to an Action uses `RethrowsDomainValidation` (`withFormErrors()`), which re-keys the errors to the form state path so each lands next to its field. `client_id` is not fillable on `Project`; creation code sets it through `$client->projects()`. The project key suggested while the name is typed comes from `ProjectKeySuggester` (a pure static helper with no model or database access); the unique key index in the database is the real guard, and the suggestion is only a convenience.
- **Guest pages.** A page that a visitor without an account opens (accepting an invitation, asking for a password reset link) carries `Audience::Guest`, is a `SimplePage`, and gets its access from its own signed route and throttle, not from the panel checks. The guest has no user, so every Partner scope is fail-closed: the page reads and writes through exactly one system-run path (`ClientInvitation::findAcceptable()` for the lookup, the `AcceptInvitation` Action for the write, both wrapping their work in `PartnerContext::runAsSystem`). Every failure on such a page looks the same to the visitor (one neutral message), and the route sends `Referrer-Policy: no-referrer`. `tests/Arch/PanelRegistryTest.php` fails when `Guest` sits on a class that is not a `SimplePage`, `tests/Feature/Clients/AcceptInvitationTest.php` covers the flow.
- **Escape hatches.** Removing the Partner scope (a `withoutGlobalScope` call naming `PartnerScope`), a bare `withoutGlobalScopes()` call or a raw `DB::table()` query is allowed in `app/` only for a file listed in `ESCAPE_HATCH_ALLOWLIST` with the reason it cannot reach a Partner. The list is empty on purpose; removing only `SoftDeletingScope` is fine and not reported. A lookup that must run without a user uses `PartnerContext::runAsSystem`, not a removed scope. `tests/Arch/QueryEscapeHatchTest.php` enforces it.
- **External lookups.** The ARES company lookup (`AresClient`) validates the company number (8 digits, checksum) before it builds a URL, retries only connection errors and 5xx responses, caches successes for an hour and is limited per user; a failure never blocks saving the form. The Pest base class calls `Http::preventStrayRequests()` (`tests/TestCase.php`), so a test that reaches the network fails; fake the response with `Http::fake`. Tests use a checksum-valid but fictional company number assembled at runtime by `FictionalCompanyId`, and the only literal allowed in a file is the placeholder `12345678`. `tests/Feature/Clients/AresClientTest.php` enforces it.
- **Passwords and accounts.** The minimum password length is 12 (`Password::defaults` in `AppServiceProvider`), for the installer, the invitation and the reset page alike. A deactivated Partner account cannot sign in and receives no reset link.
- **Tasks and numbering.** A task is created only through the `CreateTask` Action, and its `KEY-N` reference comes only from `DocumentNumbering::nextTaskNumber()`, which uses the `number_sequences` row `task:<project uuid>`; the `projects` table has no counter column. The project key is frozen from the first task on (archived tasks count): the `projects_key_frozen_guard` trigger raises SQLSTATE `KP002` for a changed key, and `UpdateProject` and the edit form refuse it earlier. A deleted number is never handed out again, and tasks are archived, never hard-deleted. `tests/Feature/Tasks/TaskActionsTest.php`, `tests/Feature/Tasks/TaskKeyTest.php`, `tests/Feature/Schema/TasksTableTest.php` and the parallel-process proof `tests/Concurrency/TaskNumberConcurrencyTest.php` (8 workers, with a mutation run that fails without the counter lock) enforce it.
- **Board.** Every write of a task's status or position goes through `TaskBoard` (`appendToColumn()` for a status change, `move()` for a drop) under the board advisory lock, and the order of locks inside one transaction is always the board lock, then the project row, then the counter row. `CreateTask`, `UpdateTask`, `ArchiveTask`, `RestoreTask` and `MoveTask` are the only callers; a new writer takes the same lock first. `MoveTask` checks the status whitelist, the id format, the Partner-scoped lookup and the policy, and re-reads the card under the lock. The `tasks` positions are guarded by a deferred exclusion constraint, so a missing lock fails loudly. `tests/Feature/Tasks/TaskBoardTest.php` and `tests/Concurrency/TaskBoardConcurrencyTest.php` (two processes moving cards in parallel, with a mutation run without the lock) enforce it.
- **Rich text.** User-written HTML (task descriptions, comments, escalation reasons) is stored only through `RichText::clean()` and rendered only through `RichText::render()`, which use their own strict sanitiser and not Filament's global configuration. Editors offer no file attachments; files arrive with the documents phase. The limit is `RichText::MAX_LENGTH` bytes and a longer text is a field error, never silently cut. `tests/Feature/Tasks/RichTextSanitiserTest.php` enforces it.
- **Partner writes on tasks.** A Partner writes to tasks only through `CreateTask` (content only), `AddTaskComment` (never internal), `EscalateTask`, `ClearEscalation` (the assignee only) and `UpdateTaskDescription`. The last one changes the description of an own client-visible task whose status is in `TaskPolicy::DESCRIPTION_EDITABLE_STATUSES`, that is Planned or To clarify (owner decision D-16). It is guarded by the ability `editDescription`, which is checked again on the row re-read under a row lock, so a status change while the editor is open refuses the save; a save based on an outdated description is refused as stale; and the change is logged as the history event `description_changed` without its text. `UpdateTask` refuses every Partner. A new Partner write path gets its own policy ability and its own Action, never a wider `update`. `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` enforces the description path.
- **Task billing.** A task's billing type, rate, fixed price and estimate are overrides in the Admin-only `task_billing` row, which exists only while something is overridden. The effective values are read only through `TaskBillingResolver` (task, parent task, project, client; the first level with a non-null value wins per field); a caller never repeats that order, and the resolver itself refuses anybody but the Admin or a system run. `tests/Feature/Tasks/TaskBillingResolverTest.php` enforces it.
- **Task notifications.** A task notification is sent only through `TaskNotifier`, which picks the recipients and builds the link of each audience (`/admin/tasks/KEY-N` for the Admin, `/admin/my-tasks/KEY-N` for a Partner). Notifications are queued after the commit and carry scalars only, so a worker reloads nothing and renders with no signed-in user. The preferences of a user (`NotificationPreferences`, edited on the profile page) can only narrow delivery. An internal comment never notifies a Partner, and that rule runs before any preference is read; it is also enforced in the notification constructor. Every value a task notification interpolates (title, display name, excerpt, change line) is escaped once, in `TaskNotification`, whose `toMail()` and `toDatabase()` are final: Markdown-escaped in the mail lines (the mail renderer already HTML-encodes each line, so no `e()` there), HTML-escaped with `e()` in the bell, and left as plain text in the subject. A subclass only names its text group and supplies raw values, and never calls a translation helper. `tests/Isolation/NotificationMarkupTest.php` enforces that for every subclass and audience. A Partner's edit of a task description tells the Admin and the assignee through `TaskChangedNotification`, with one change line that names the Partner and no description text. `tests/Feature/Tasks/TaskNotificationsTest.php`, `tests/Feature/Notifications/NotificationPreferencesTest.php` and the canary proof `tests/Isolation/NotificationLeakTest.php` enforce it.

### Hand-over notes for later phases

- **Phase 6 (time tracking).** A time entry of a task that `TaskBillingResolver` resolves as non-billable is created with billable set to false. The hourly rate and the estimate come from the resolver (`EffectiveBilling`), never from a copy of the project or client rate; the estimate inherits literally, so Phase 6 decides whether tracked time is compared with an inherited estimate. The timer starts from a task, and a project picker anywhere in the phase uses `Project::selectable()`. Tracked time, rates and prices stay invisible to a Partner: a new column on a Partner-readable table needs the pinned-list edit of `PartnerSafeColumnsTest`.
- **Phase 7 (calendar and reports).** A task is addressed by `tasks.reference` (`KEY-N`, unique, stable, also the route key of `/admin/tasks/KEY-N`), never by a title or a number alone. The board and the calendar read status and position only through `TaskBoard`.
- **Phase 9 (documents).** Task and comment attachments arrive with the documents module; the task page already reserves an "Attachments" section that says so and offers no upload control, and the rich-text editors have attachments switched off on purpose. An attachment of an internal comment must never reach a Partner (a Partner reads no internal comment, so the file follows the comment), and a Partner may see only the files of a visible task.
- **Phase 10 (invoicing).** The billing terms of a task are read through `TaskBillingResolver` only; `task_billing` is Admin-only and a row exists only for an override, so a missing row means "inherit". Invoice lines snapshot the effective values at issue time, and the resolver refuses a Partner on purpose.
- **Phase 8 (exports).** Text that came from ARES (names, addresses) is user-controlled content: an export to a spreadsheet must prefix cells that start with a formula character (`=`, `+`, `-`, `@`).
- **Phase 12 (API).** An API token of a deactivated user must be rejected; deactivating an account does not revoke its tokens by itself.
- **Search.** Search is a plain substring match; a search that ignores diacritics (the `unaccent` extension) is a known gap and not built yet.

The documentation test (`tests/Feature/Repo/RepositoryFilesTest.php`) fails when a class named in these bullets no longer exists.

### Add-a-model checklist

1. Extend `KokpitModel` (`HasUuids`); create the migration with a uuid primary key defaulting to `uuidv7()`, `timestampsTz()` and database constraints (foreign keys, unique and check constraints, partial indexes) rather than only validation.
2. Add one line to `MorphMap`.
3. Money columns as a `_minor` and `_currency` pair with `MoneyCast`; numbers through `SequenceAllocator`; frozen states through `Immutability`.
4. Declare isolation: implement `PartnerIsolated` and use `IsolatesPartners` (or `DeniesPartners`), or add `NotPartnerScoped` with a reason.
5. Write the policy as a `KokpitPolicy` subclass that grants Partners explicitly, or register `AdminOnlyPolicy`.
6. Put `AccessRule` on every Filament class you add, and add its labels and messages to `lang/cs`.
7. Add one line to `CanaryRegistry` for a `PartnerIsolated` model.
8. Audited models: use `LogsAllowlistedActivity`, declare `#[LoggedAttributes([...])]` on the class, add the model to the explicit list of logging models in `tests/Arch/ActivityAllowlistTest.php` (the test that compares it with the models found in `app/`), and attach a concrete `ActivityHistoryRelationManager` subclass to the model's resource.
9. A model that a Partner may read (`PartnerIsolated` with a real `constrainForPartner`): add the canary fixture and a visibility test, and for a table that a Partner reads pin its column list (see `PartnerSafeColumnsTest`, which covers `projects`, `tasks` and `task_comments`) and build the Partner screens only from pinned builders; money, rates, prices and internal notes go to an Admin-only side table, never onto the Partner-readable one. The canary harness proves a table only if a text column can carry each client's canary, so an Admin-only side table with no natural text column gets a nullable text column for it (`task_billing.internal_note`, `project_billing`'s internal note).
10. Run `ddev composer ci`.

## What the checker flags

`scripts/check-sensitive.sh` reports the following categories. The rule name is what appears in a finding.

| Rule | What it flags |
|---|---|
| `key-prefix` | Secret-key and token prefixes with a body: Stripe, GitHub and AWS style keys (the AKIA and ASIA prefixes) |
| `email` | E-mail addresses outside `example.com`, `example.org`, `example.net`, `.test`, `.invalid`, `.localhost` |
| `company-id` | 8-digit company-ID-like numbers (the placeholders `12345678` and `00000000` are allowed). The number is also found after `-`, `_` or `/`, so write dates with separators (for example 2026-10-07) and not as one run of digits |
| `iban` | IBANs with a valid country length and checksum, also in groups of four and also in lower case |
| `cz-account` | Czech account numbers, with or without prefix, also with one space on either side of the slash |
| `public-ip` | Public IPv4 addresses, also after `-` or `_` (private, loopback, link-local, documentation and similar ranges are allowed) |
| `hosting-host` | Zerops and S3-provider endpoint hostnames, in any letter case |
| `home-path` | Personal absolute home paths: `/Users/<name>` and `/home/<name>` with or without a trailing slash, also quoted or at the end of a line, and the Windows form `C:\Users\<name>` (any slash style and letter case). Placeholder names are allowed: `example`, `user`, `username`, `name` and `you`, for the Unix form also `runner`, `vagrant`, `ubuntu`, `www-data`, `ddev` and `Shared`, and for the Windows form `Public`, `Default`, `All` and `runneradmin`. A `home` or `Users` segment inside a URL or a relative path is not a home path |
| `git-attributes` | A `-diff`, `binary` or `filter=` attribute in any `.gitattributes`. Such an attribute hides content from gitleaks in the hook, or stores something other than the file in git. The rule is checked in staged, `--all` and explicit-file mode, never in `--history`, so a removed line cannot keep CI red. A deliberate use (for example Git LFS) needs a reviewed path-scoped allowlist entry for this rule |
| `denylist` | Terms from your local denylist, see below |

Modes:

- no argument (or `--staged`): the added lines of the staged diff. This is what the hook runs.
- `--all`: every tracked file in the index, read in full. CI runs it.
- `--history`: every added line in `git log --all`; findings name the introducing commit as `path:line@sha`. It covers every commit, including content introduced by a merge commit (diffed against its first parent, `--diff-merges=first-parent`) and renamed files (shown in full). CI runs it over the full history.
- `FILE...`: explicit files (use `--` before a name that starts with a dash). Every non-empty operand is scanned; there is no binary skip.

Every mode reads the full content of every file, including files git treats as binary (NUL bytes, a `-diff` or `binary` attribute) and UTF-16 text. Diffs are forced to text, textconv drivers are ignored, and NUL bytes are read twice, once as a space and once removed. Each finding is printed once.

Exit codes: `0` clean, `1` findings, `2` usage error, not a git repository, an unreadable or in-repository denylist, or a scanner error. Matched values are always masked: a finding prints the rule, the file and the line, plus the first characters and the length of the value, never the value. The same is true for the denylist stage: the term and the line text are never printed. A file path that itself contains a denylist term is printed as the finding path, because the path is the finding.

## Exemptions

Reviewed exemptions live in two files, both covered by code owners and reviewed in pull requests:

- `scripts/sensitive-allowlist.txt`: one entry per line, `rule ;; path-ERE ;; matched-value-ERE`. Make each entry as narrow as possible: one rule, the smallest path scope, an anchored value pattern. The value pattern is tested against the matched value, not the whole line.
- `.gitleaks.toml`: the allowlists of the gitleaks configuration.

Exactly five files are exempt from the generic rules, by exact path: `scripts/check-sensitive.sh`, `scripts/lib/scan.awk`, `scripts/lib/diff2tsv.awk`, `scripts/sensitive-allowlist.txt` and `.gitleaks.toml`. The denylist still scans them. Binary assets (images, fonts, PDFs) are scanned too. A false positive in one gets a narrow, path-scoped allowlist entry.

There are no inline ignore comments, no environment variable and no flag that disables or redirects a rule. gitleaks runs with `--ignore-gitleaks-allow`, so a `gitleaks:allow` comment in a file has no effect.

## Local denylist

Terms specific to your own instance (client names, internal project names, company IDs) go into a denylist that never enters the repository:

    export KOKPIT_DENYLIST="$HOME/.config/kokpit/denylist.txt"

The file lives in your home configuration directory. The script refuses a path inside the repository.

Format: one term per line. Blank lines and lines starting with `#` are ignored. Terms are fixed strings. Use terms of at least 3 characters: very short terms match too much.

Term and text are folded the same way: ASCII letters and the accented Latin letters from U+00C0 to U+017F (Czech, Slovak, German, Polish and more) become lower-case base letters, and combining marks are removed. A term written with diacritics therefore matches the same text without them, the reverse also holds, and composed and decomposed spellings match. Matching is byte-wise after folding and does not depend on the system locale. Letters of other scripts are compared exactly as written. Accented letters in UTF-16 or in legacy single-byte encodings such as Windows-1250 are not folded; only their ASCII letters match.

A fictional example file:

    # fictional example terms
    Acme Fictional Ltd
    Project Bluebird

Behaviour:

- `KOKPIT_DENYLIST` unset, or the file does not exist: one notice on stderr, then only the generic rules run. The exit code is unaffected. This is also how CI runs.
- the file exists but is not readable: exit `2`.
- the file is inside the repository: exit `2`, so a real list can never be committed through this tool.

## Never bypass

`git commit --no-verify` and `LEFTHOOK=0` skip the hook. Do not use either: CI rescans the whole tree and the whole history and fails the pull request, so a bypass only moves the finding to a later and more public place.

A path-limited commit (`git commit -- <path>`) scans only the committed paths, not the whole index.

## If something leaks

If something sensitive was committed or pushed:

1. Rotate or revoke it first. A secret that was ever pushed is compromised, whatever happens to the history afterwards.
2. Remove it from history. Before the first push, a local history rewrite is enough. After a push, contact the owner: the organisation ruleset blocks force-pushes to `main`, so the repair needs a decision.
3. Notify the affected parties.

The same runbook, together with how to report a vulnerability privately, is in `SECURITY.md`.

## CI

The workflow `Hygiene` runs on pushes to `main` and on pull requests, never on `pull_request_target`. Its jobs:

- `scan`: the hygiene self-tests, `--all`, `--history` and gitleaks.
- `workflow-lint`: actionlint and zizmor.
- `tests`: Pest on PostgreSQL 18 and Redis services, after booting the application from `.env.example` alone (copy, `key:generate`, `migrate`, `kokpit:install`).
- `static-analysis`: Pint and Larastan level 8.
- `dependencies`: `composer validate --strict`, `composer audit --locked` and the licence allowlist.
- `CI Passed`: the aggregator and the only status check the organisation ruleset requires. It waits for every other job, and `scripts/tests/test-workflow.sh` fails when a job is missing from its `needs` list.

Run the PHP gates locally inside DDEV with `ddev composer ci` (tests, formatting, static analysis and the licence check; `ddev composer check-licenses` runs the last one alone). The bash self-tests stay a host command: `bash scripts/tests/run.sh`.

The licence check passes a package when at least one of its declared licences is AGPL-3.0-compatible (a dual-licensed package lists its alternatives separately) and fails on `GPL-2.0-only`, on proprietary terms and on a package with no licence. A failure is a prompt to decide, not to extend the list in `scripts/check-licenses.php` silently; the change needs maintainer review.

The gitleaks step runs with `--log-opts="--all --diff-merges=first-parent --text --no-textconv"`, so it also reads merge commits and content git treats as binary. UTF-16 content stays invisible to gitleaks and is covered by the shell scan. In the pre-commit hook gitleaks cannot read binary-classified content; the `sensitive-content` job before it does.

Everything is pinned. Actions are pinned by full commit SHA and bumped by Dependabot. The tools gitleaks, actionlint and zizmor are downloaded as a release tarball by version and verified against a SHA-256 hard-coded in the workflow. Never trust a checksums file taken from the same release.

Manual bump procedure for a tool:

1. Read the new version and its asset digest: `gh api repos/<owner>/<repo>/releases/latest --jq '.assets[]|[.name,.digest]|@tsv'`.
2. Verify the digest against the upstream checksums.
3. Update the version and the digest in the workflow together, in one change, and update the tested versions in the Setup section.

## GitHub settings checklist (maintainer, manual)

These settings cannot be enforced from code or from CI (reading them needs an admin token), so the maintainer confirms them by hand and re-checks after GitHub UI changes:

- [x] secret scanning enabled
- [x] push protection enabled (Settings -> Advanced Security -> Secret Protection); review every push protection bypass alert
- [x] non-provider patterns and validity checks enabled
- [x] "Require actions to be pinned to a full-length commit SHA" enabled
- [x] "Allow GitHub Actions to create and approve pull requests" disabled
- [x] workflow approval required for outside contributors
- [x] Dependabot alerts and security updates enabled
- [x] two-factor authentication on the maintainer account
- [x] optional: e-mail privacy settings for commit author metadata ("Keep my email addresses private" and "Block command line pushes that expose my email")
- [x] the organisation ruleset on `main`: block deletion, block force push, require a pull request, required status check `CI Passed`. The first push of a new branch goes through a pull request.
- [x] decide whether the ruleset on `main` uses "Require review from Code Owners". Without it, `.github/CODEOWNERS` only requests a review. A solo maintainer cannot approve their own pull request, so turning it on blocks the maintainer's own pull requests unless the ruleset grants the maintainer a bypass. The decision is the owner's. CODEOWNERS covers `/.github/`, `/scripts/`, `/lefthook.yml`, `/.gitleaks.toml`, `/.gitleaksignore`, `/.gitattributes`, `/.gitignore`, `/CONTRIBUTING.md` and `/.claude/`.

A read-only audit of the repository-level values, run by the maintainer (never in CI):

    gh api repos/<owner>/<repo> --jq .security_and_analysis

## Deploy (maintainer, manual)

Kokpit deploys to Zerops from `.github/workflows/deploy.yml` and `zerops.yml`. `zerops.yml` has one setup, `backend`, pushed to one Zerops service that serves the panel (nginx and PHP-FPM), runs Horizon under supervisord and runs `schedule:run` from its crontab. The workflow starts only from a published release (not a prerelease, tag `v*`, commit on `main`) or a manual dispatch, and its `deploy` job runs in the protected `production` environment. Its secret-free `verify` job refuses any run whose ref is not exactly `refs/heads/main` or a `refs/tags/v*` tag (so a dispatch from a feature branch or a foreign tag stops there), any commit that is not an ancestor of `origin/main`, and any commit without a successful, completed `CI Passed` check run of the GitHub Actions app (the aggregator job of `hygiene.yml`, read with the job's own `GITHUB_TOKEN` and `checks: read`). A commit whose CI is still running, failed or was cancelled is therefore not deployed; wait for it or re-run the checks first. These checks live in the workflow file of the dispatched ref, so they are defence in depth, not a boundary against someone who can push a branch and edit the file there: that boundary is the environment's deployment branch and tag policy below. None of the settings below can be enforced from code, so the maintainer applies them by hand before the first deploy and re-checks them after UI changes. The static contract of the workflow is tested (`tests/Feature/Repo/DeployWorkflowTest.php`); the Zerops side is rehearsed once on a throwaway project (`.planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md`).

GitHub:

- [ ] environment `production` exists with a required reviewer (the maintainer). A solo maintainer leaves "prevent self-review" off and accepts the approval as a deliberate pause and audit trail. "Allow administrators to bypass" is off.
- [ ] environment deployment branches and tags: the tag pattern `v*` plus the default branch (for a manual dispatch). This is the control that holds even if a branch carries a modified `deploy.yml`; the `verify` job guard repeats it inside the workflow.
- [ ] a tag ruleset protects `v*`, so only the maintainer can create such a tag
- [ ] `ZEROPS_TOKEN` is stored as an environment secret of `production`, and the Zerops service id of the `backend` service as the environment variable `ZEROPS_SERVICE_ID` of `production`. Neither is ever a repository secret or a repository variable.
- [ ] no other workflow references the `production` environment, and "Require actions to be pinned to a full-length commit SHA" stays enabled

Zerops:

- [ ] the native Git integration (GitHub or GitLab) is disabled for the `backend` service (Pipelines and CI/CD settings, stop the automatic build trigger). It deploys on a push or a tag and would bypass the approval of the `production` environment.
- [ ] a dedicated access token with the narrowest scope that can push the `backend` service; rotate it after any suspected exposure and replace the environment secret
- [ ] every environment variable is set in the Zerops UI only (project or `backend` service level). `zerops.yml` holds no environment value (the build reads no environment), and the application refuses to boot in production on wrong values. The names, grouped, with the fixed value where the guard or the deployment demands one:
  - application: `APP_KEY` (secret), `APP_URL` (the public `https` URL of the instance; the guard refuses empty, plain `http` and localhost), `APP_ENV` `production`, `APP_DEBUG` `false`, `LOG_CHANNEL`
  - database: `DB_CONNECTION` `pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (references to the PostgreSQL service)
  - Redis: `REDIS_CLIENT` `phpredis`, `REDIS_HOST`, `REDIS_PORT`, `QUEUE_CONNECTION` `redis` (guard), `CACHE_STORE` `redis` (guard: the `onOneServer` locks, the heartbeats and the alert throttling live in the cache), `SESSION_DRIVER` `redis`
  - mail: `MAIL_MAILER` (a real transport, never `log` or `array`; guard), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
  - storage: `FILESYSTEM_DISK` `s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` `true` (references to the object storage service)
  - Kokpit: `KOKPIT_REQUIRE_ADMIN_2FA` `true` and `KOKPIT_CANARY_HARNESS` `false` (guard), `ACTIVITYLOG_ENABLED` unset or `true` (guard)

  The secrets (`APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, later the Stripe keys) are Zerops secrets.
- [ ] Valkey `maxmemory-policy` is `volatile-lru` or `noeviction`. Queue keys carry no TTL, so the default `allkeys-lru` could evict waiting jobs. The DDEV Redis keeps the default of its add-on because it is for development only.
- [ ] object storage policy is `private`, PostgreSQL backups are enabled, and the `backend` service may run more than one container: the crontab runs `schedule:run` on every container, every scheduled task uses `onOneServer` and the lock lives in the shared Redis cache
- [ ] the project services are named `db`, `redis` and `storage`, or the `${...}` references you set in the Zerops UI are adjusted to the chosen names

Rules:

- Each deploy pushes the `backend` setup once (the version name is the tag, or `main` plus the short sha for a dispatch from `main`). Every container of the new version runs the `initCommands` in order, and Zerops ends the deploy at the first failing one, before traffic is switched, so the previous version keeps serving. The migration runs once per deploy through `zsc execOnce` keyed on the app version id; a failure is reported as failed on every container (a Zerops property), which stops the deploy. Directly after it `php artisan kokpit:deploy:verify` runs on every container, without `execOnce`, and exits non-zero on an unreachable database, a pending migration (package and settings paths included) or a Redis connection (default, queue, cache, session) that fails or gives no valid answer, which stops the deploy too. There is no `readinessCheck`. Rehearsal checks 8 and 9 confirm the behaviour on a real project (`.planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md`).
- Horizon starts at container start: `sudo supervisorctl start horizon` is the last `initCommands` step, so it runs on every container only after the migration and the verify passed, and supervisord (`autostart` in `supervisor-horizon.ini`) starts it again after a restart. `ZeropsConfigTest` keeps the command last, after the migration and the verify, and outside `execOnce`.
- The build installs PHP dependencies only. The repository has no `package.json` or lockfile, so the manifest has no Node or pnpm step and no build environment; the maintainer adds a frontend toolchain (together with `ZeropsConfigTest`) when one exists.
- Migrations stay backward compatible with the previous release (expand, deploy, contract): the previous version keeps serving during a deploy and after a failed one, so a column is added first and dropped only one release later.
- rollback is activating the previous version of the service in Zerops. It does not undo migrations, which is why they must stay backward compatible.
- The zcli version and its SHA-256 are hard-coded in `deploy.yml` and bumped by hand with the tool bump procedure of the CI section (read the digest of the asset `zcli-linux-amd64`, update version and digest together).

## Per-phase .gitignore review

Review .gitignore at the start of every phase. For each new tool or framework output that the phase introduces (DDEV in Phase 2, Laravel storage, coverage reports, build output), add the ignore rule and a matching case to `scripts/tests/test-gitignore.sh` in the same change. The test asks `git check-ignore --no-index` about paths that do not exist on disk, so the verdicts hold for an empty checkout.

Only `.claude/CLAUDE.md` is versioned below `.claude/`. The directory itself must never be ignored (`.claude/`): git cannot re-include a file below an excluded parent. The rule is `.claude/*` followed by `!.claude/CLAUDE.md`, in that order, and the test fails if the order is reversed.
