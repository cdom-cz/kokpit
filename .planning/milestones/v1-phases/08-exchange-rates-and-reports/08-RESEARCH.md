# Phase 8: Exchange Rates and Reports - Research

**Researched:** 2026-10-14
**Domain:** Laravel 13 / Filament 5 / PostgreSQL 18: CNB exchange-rate ingestion, per-entry money reporting, spreadsheet export, Dompdf work report, dashboard widgets
**Confidence:** HIGH (CNB API and library behaviour probed live this session; in-repo contracts read from source). MEDIUM for UI composition details (no UI-SPEC exists yet).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): Dompdf 3.1 is the PDF engine for the work report and invoice PDF (Phase 3 spike, `03-SPIKE-PDF.md`); money is bigint minor units plus ISO 4217 currency; time is exact seconds, stored UTC and shown in `Europe/Prague`; rate resolution is task, project, client, global default and billing data lives in Admin-only `project_billing` / `task_billing` (Phases 4-6); task billing type values inherit / hourly / fixed / non-billable; Partner never sees time, rates, prices or finance (Phase 2 `#[AccessRule]` / `#[DeniesPartners]`); queued jobs extend `KokpitJob` and are idempotent, failed-job alert is a synchronous e-mail plus bell notification with throttling (Phase 3 D-10, D-11); the System page has a registered `last_rate_date` health slot to replace (Phase 3 D-13, `HealthSlot::LastRateDate`); the Signal planner (`SignalTask`, "Dnes a zítra" page) already exists.

**CNB rates: conversion, download, backfill (EX-01, EX-02, EX-03)**
- **D-01:** Unbilled time is converted to CZK in reports with the CNB rate of the **day of the time entry** (the previous valid rate when that day is a weekend or holiday). Reports for past periods therefore do not change from day to day. Document rates stay snapshots taken when the document is issued (EX-03, later phases).
- **D-02:** The whole daily CNB list is stored (all currencies, about 30), not only currencies used by clients, so adding a client in a new currency needs no historical catch-up. Per-unit amounts (for example 100 units) are stored with the unit amount and handled in the conversion helper.
- **D-03:** CNB publishes the rates once per working day after 14:30 Prague time, valid for that day and the following weekend or holiday (cnb.cz methodology sheet and FAQ). The scheduled download therefore runs after publication (for example 15:00) and, when it fails, repeats hourly until about 18:00. Exact schedule values live in `config/kokpit.php`.
- **D-04:** A final failure of the day triggers the Phase 3 alert (synchronous e-mail plus bell notification, throttled), independent of the queue. Until a new rate arrives the last known rate keeps being used. The System page `last_rate_date` slot becomes real (OK / Warning / Error thresholds are Claude's discretion, documented in `config/kokpit.php`).
- **D-05:** The rates overview is an Admin page with a manual "Download today's rates" action and a "Backfill history" action with a from-to date range. Backfill runs queued, is idempotent (a rerun creates no duplicates) and fills days with no CNB list (weekends, holidays) with the previous valid rate. A conversion helper returns the CZK amount for a currency, amount and date.

**Time report and effective rate (RE-01, RE-02, RE-05)**
- **D-06:** One Admin-only report page: filters client, project and period (presets this month, last month, custom), a table that expands client > project > task, a switch for breakdown by day and by user, and a summary at the top for billable vs non-billable and billed vs unbilled. Amounts are shown in client currency and CZK.
- **D-07:** For fixed-price projects the report shows hours, the agreed fixed price and the effective hourly rate; no per-entry amount is computed for them (hourly and fixed logic are not mixed). — **Reversibility:** costly — export columns, PDF and dashboard amounts depend on which entries carry an amount.
- **D-08:** Effective hourly rate = fixed price divided by all worked hours of the whole project (not only the selected period), shown in client currency and CZK, with both the price and the hours visible next to it.

**Exports and work report PDF (RE-03)**
- **D-09:** CSV and XLSX export respects the current report filters and contains one row per time entry (date, client, project, task, user, seconds and hours, billable, billed, amount in client currency and CZK) plus an XLSX summary sheet. It is built by a queued job; the finished file is kept on private storage for 7 days (value in `config/kokpit.php`), then deleted. The Admin gets a bell notification with a link; download goes through the app with a permission check (no public URL). Cells are spreadsheet-safe against formula injection.
- **D-10:** The PDF work report is generated with Dompdf in the client's language (`clients.invoice_language`, cs/en) and shows header (supplier, client, period), a table by day and task with hours, and a summary. It contains **no rates, prices or amounts** (it is an attachment; prices belong on the invoice). Multi-page, correct Czech diacritics, repeating header and page numbers as proven in the spike. Phase 8 delivers the generator as a service returning the PDF and a download action; storing it as an invoice attachment and e-mailing it is done by Phases 9/10. Raise the PHP memory limit for long reports as noted in the spike.

**Dashboard (RE-04)**
- **D-11:** The Admin dashboard (replacing the empty Phase 2 dashboard) shows: hours today and this week (Monday to Sunday, Europe/Prague, including a running timer) and worked time in the current month; unbilled billable time of the current month per client (hours and amount in client currency and CZK); the nearest deadlines of the first ten open tasks, overdue ones highlighted; a collapsible "plan for tomorrow" block read from the existing Signal planner (`SignalTask` for the next day), collapsed by default with the first three tasks visible and the rest expanded on click (read-only, no new planner functions); and a tile for unpaid invoices that appears when invoicing exists (Phase 10; the unresolved-payments tile follows in Phase 11).
- **D-12:** The Partner dashboard replaces the empty landing page: the Partner sees the list of their own visible projects and current open tasks, with tasks in status `in_review` ("ke kontrole") listed first and visually highlighted as a call to action. It shows no time, rates, prices or finance and uses the existing Partner scope from Phase 5. — **Reversibility:** reversible — it is a page and a query, no stored data.

### Claude's Discretion
Class and file names, table design for rates (one row per currency and date, whether weekend/holiday rows are stored or resolved by lookup), rounding of CZK amounts, behaviour of a conversion with no rate at all, XLSX library (AGPL-compatible), Filament widget structure, health slot thresholds, Czech wording, report query performance (indexes, aggregation in SQL), queue and retry values within D-03.

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope. (The Partner dashboard and the Signal plan block were accepted into this phase as small read-only views.)

Not in this phase (CONTEXT domain): storing the work report as an invoice attachment and mailing it (Phases 9/10), invoice and payment dashboard tiles (wired in Phases 10/11), any new planner functionality.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| EX-01 | Daily scheduled, idempotent download of CNB rates, manual run and history backfill; weekend/holiday uses previous rate; per-unit amounts handled | CNB API probes (endpoints, `validFor`, `amount` 1/100/1000), `exchange_rates` schema with unique `(currency, rate_date)` + `insertOrIgnore`, lookup-not-store for non-publication days, scheduled command plus `BackfillCnbRates` job, Czech holiday verification |
| EX-02 | Overview of rates and Admin alert when download fails; last known rate is used meanwhile | `ExchangeRateResource` overview, `AdminAlerter` + `OperationalAlert` called synchronously from the scheduled command on the final attempt, `LastRateDateIndicator` replacing the placeholder, gap self-heal |
| EX-03 | Conversion helper used for invoice and transaction CZK amounts; document rates are snapshots | `CnbConverter` / `RateBook` over `Money::convert(rate, 'CZK', unitAmount)`; proposed exact `Money::forDurationConverted`; typed `ExchangeRateMissing` |
| RE-01 | Time report per client and project for a period with breakdown by task, day and user | `TimeReportReader` (one SQL over `time_entries` with Prague-day predicate and effective billing joins), in-PHP aggregation tree, custom Blade tree table |
| RE-02 | Billable/non-billable and billed/unbilled split with amounts in client currency and CZK | Four-sum identity shared with `TimeTotals`, per-entry rounded amounts (`Money::forDuration`), per-currency subtotals, CZK at the entry's Prague day |
| RE-03 | CSV/XLSX export (queued, delivered by notification) and PDF work report attachable to an invoice | OpenSpout 4 (already installed), `ReportExport` row + `BuildReportExport` job + prune job, spreadsheet-safe cell writer (OpenSpout `Cell::fromValue` formula pitfall verified), Dompdf 3.1.6 `WorkReportPdf` service |
| RE-04 | Dashboard: today/this week hours, unbilled per client, overdue invoices, unresolved payments, upcoming task deadlines | Role-specific `Dashboard::getWidgets()`, `StatsOverviewWidget` / `TableWidget` / custom plan widget, Partner widgets on pinned Partner columns; invoice/payment tiles are Phase 10/11 |
| RE-05 | Effective hourly rate shown for fixed-price projects | `Money::hourlyRateFor(price, seconds)` single rounding point, whole-project hours from `TimeTotals::forProject()` |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` (read this session). Treated with the authority of locked decisions.

- Public AGPL-3.0 repository: **fictional data only**. No client names, prices, rates, real e-mail addresses, company IDs, bank accounts, IPs, hostnames, tokens or personal absolute paths in code, tests, fixtures, docs, `.planning/` or commit messages. Use `example.com`, company ID `12345678`, `/Users/example/`, and assemble test fakes at runtime from fragments. CNB API fixtures must use invented rates, not copied production lists.
- Review procedure before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook hook.
- All dependencies must be AGPL-compatible; no paid or closed packages (`composer check-licenses` is a CI gate).
- Tests run inside DDEV: `ddev exec vendor/bin/pest` (host has no database).
- Stack: PHP 8.5, Laravel 13, Filament 5 (SPA), PostgreSQL 18, S3-compatible private bucket, Redis queue.
- Partner must never see measured time, rates, prices or finance, nor another client's data: enforced by Policies and global scopes, not only UI hiding.
- Data integrity: UUID v7 keys, FK / unique / partial index / CHECK in the database. Immutable issued documents.
- Planning docs under `.planning/` are written in English; user-facing text is Czech via `lang/cs`.
- GSD workflow enforcement: file changes go through a GSD command.

## Summary

Phase 8 is mostly composition of contracts that Phases 2-7 already fixed, plus three genuinely new technical surfaces: the CNB feed, a spreadsheet writer, and a Dompdf report. The CNB side was probed live: `https://api.cnb.cz/cnbapi/exrates/daily?date=YYYY-MM-DD&lang=EN` returns the newest list with `validFor <= date` (a Saturday date returns Friday's list; a future date returns the latest list), and `daily-year?year=YYYY` returns every working-day list of a year in one 560 KB response. Units are 1, 100 or 1000 (`amount`). For 2023-2025 the published days equal exactly "weekday minus the 13 Czech public holidays", so a tiny own `CzechPublicHolidays` calculator is enough to know when a list is expected (needed for the final-attempt alert and the health slot). Weekend and holiday days must be **resolved by lookup** (`rate_date <= day`, newest), never stored as derived rows.

The money model is the delicate part. D-01 wants the CNB rate of each entry's Prague day, and the repo has one rounding point (`Money::fromExactMinor`, confined to `Money` by an arch test). Recommended: compute the amount **per time entry**, rounded once, from one shared reader that feeds the report, the export and the dashboard, so every total is additive and the export total equals the report total exactly. Two small methods must be added to `Money` (no Brick type may leave that class): an exact "duration at hourly rate converted at a CNB rate" and "effective hourly rate of a fixed price over N seconds". The effective billing type and rate per entry can be resolved set-based in one SQL (task, parent task, project, client chain) and must be proven equal to `TimeEntryRateResolver` by a parity test.

Three verified traps shape the implementation: (1) OpenSpout's `Cell::fromValue()` / `Row::fromValues()` turns any string starting with `=` into a real XLSX formula (verified by writing and unzipping a file), so text cells must be built as explicit `StringCell` and also prefixed; (2) CNB JSON rates arrive as JSON numbers, so they must be quoted before `json_decode` to stay decimal strings; (3) Filament's built-in Exporter is not usable here (bigint `exports.id`, batch table dropped, no `KokpitJob`), so the export is a custom `KokpitJob` on OpenSpout, which is already in `composer.lock` through `filament/actions`.

**Primary recommendation:** Add `exchange_rates` (unique `(currency, rate_date)`, `insertOrIgnore`), a synchronous scheduled `kokpit:rates:download` (15:00-17:00 plus a `--final` 18:00 run that alerts through `AdminAlerter`), one `TimeReportReader` that yields per-entry rows with effective rate and CNB-day conversion, custom OpenSpout export job + Dompdf `WorkReportPdf` service, and role-specific dashboard widgets that each carry `#[AccessRule]`; update the four existing tests that pin the old placeholder behaviour (listed in Wave 0).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| CNB list download, parse, validate | API / Backend (domain Action) | External CNB API | Outbound HTTP with timeouts, retry on 5xx only; no browser involvement |
| Daily schedule, hourly retry, final alert | API / Backend (scheduler command) | — | Must not depend on the queue (success criterion 2); runs in the scheduler process |
| Rate storage and idempotency | Database | — | Unique `(currency, rate_date)` is the last line of defence; `insertOrIgnore` |
| Rate lookup for a day (previous valid rate) | Database (indexed lookup) | API / Backend (`RateBook` preload) | Weekend/holiday = `rate_date <= day` newest; bulk preload avoids N queries |
| Effective billing type and rate per entry | Database (join chain in SQL) | API / Backend (parity with `TimeEntryRateResolver`) | Set-based; resolver is per-entry and loads relations (N+1) |
| Per-entry amounts, rounding, CZK conversion | API / Backend (`Money` + one reader) | — | Single rounding point lives in `Money`; no float anywhere |
| Report filters and tree interaction | Browser / Client (Alpine expand) | Frontend Server (Livewire page, URL state) | Filters re-validated server-side like `TimesheetPage` |
| Report page, widgets, rates overview | Frontend Server (Filament pages/resources/widgets) | — | `#[AccessRule]` on every class; Admin only except Partner widgets |
| Export build | API / Backend (queued `KokpitJob`) | Storage (private S3 disk) | Long-running, streaming OpenSpout writer; keeps 7 days |
| Export download | Frontend Server (Livewire action, permission checked) | Storage | No public URL; no new route (route-walk friendly) |
| Work report PDF | API / Backend (service, in request) | — | Dompdf in-process; memory limit raised for long reports |
| Tomorrow plan block | Frontend Server (reads `SignalDayReader`) | Database | Read-only; owner scope applies |
| Partner dashboard lists | Frontend Server (scoped Eloquent) | Database (`PartnerScope`) | Partner scope is fail-closed in the data layer |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| dompdf/dompdf | ^3.1 (resolves to v3.1.6, released 2026-07-20) | Work report PDF | Decided in Phase 3 spike; passed all criteria; PHP only [VERIFIED: `ddev composer require dompdf/dompdf:^3.1 --dry-run` locked v3.1.6; packagist] |
| openspout/openspout | ^4.32 (installed 4.32.0) | CSV and XLSX writer | Already in `composer.lock` via `filament/actions` (`"openspout/openspout": "^4.23"`, vendor/filament/actions/composer.json:18); MIT; streaming, low memory [VERIFIED: `composer why openspout/openspout`] |
| brick/money, brick/math (existing) | existing | Exact money arithmetic | Confined to `App\Domain\Shared\Money` by `tests/Arch/MoneyBoundaryTest.php` |
| Laravel HTTP client, scheduler, notifications (existing) | Laravel 13.17 | CNB fetch, schedule, bell notifications | Same pattern as `AresClient` |

Do not upgrade OpenSpout to 5.x (latest 5.12.2): `filament/actions` pins `^4.23`, so a major bump conflicts [VERIFIED: vendor/filament/actions/composer.json:18, packagist]. Add `"openspout/openspout": "^4.32"` to `composer.json` `require` explicitly because the application now uses it directly.

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Filament `StatsOverviewWidget`, `TableWidget` | 5.10.2 (existing) | Dashboard widgets | Hours stats, unbilled per client, deadlines |
| Alpine (ships with Filament) | existing | Collapsible tree rows and plan block | No Node build exists; do not add front-end tooling |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| OpenSpout | phpoffice/phpspreadsheet, maatwebsite/excel | Both MIT but heavy (whole workbook in memory) and a new dependency; OpenSpout is already installed |
| Filament `ExportAction`/`Exporter` | — | Rejected: `exports.id` is `$table->id()` (bigint) breaking schema rules R1/R2, uses job batches (`job_batches` table was dropped, decision A-OQ1), jobs do not extend `KokpitJob`, no 7-day retention, rows must be a model query [VERIFIED: vendor/filament/actions/database/migrations/create_exports_table.php] |
| Own `CzechPublicHolidays` | spatie/holidays 2.5.1 (MIT) | Requires `ext-calendar` (declared in its `require`) which Zerops may not ship; 13 fixed rules plus an Easter formula are ~30 lines and verified against 3 years of CNB data below |
| Dompdf directly | spatie/laravel-pdf DomPdf driver | Only direct Dompdf was measured in the spike; extra dependencies for no gain now |
| Chromium PDF | — | Rejected in Phase 3 (3x slower, 6x larger, Node/Chromium in container) |

**Installation:**
```bash
ddev composer require dompdf/dompdf:^3.1 openspout/openspout:^4.32
```
Also normalise `LGPL-2.1` to `LGPL-2.1-only` in `scripts/check-licenses.php` (see Pitfall 14; this is a maintainer decision recorded in 03-SPIKE-PDF.md, not a silent allowlist extension).

**Version verification:** `composer show -a dompdf/dompdf` lists v3.1.6 latest; packagist p2 metadata: `v3.1.6 2026-07-20`, requires `php ^7.1 || ^8.0`, `ext-dom`, `ext-mbstring`, `masterminds/html5 ^2.0`, `dompdf/php-font-lib ^1.0`, `dompdf/php-svg-lib ^1.0`. `composer require --dry-run` resolved five installs (dompdf v3.1.6, php-font-lib 1.0.2, php-svg-lib 1.0.2, masterminds/html5 2.11.0, sabberworm/php-css-parser v9.5.0) and `composer audit` after the dry run reported no advisories for them [VERIFIED: tool output this session]. `composer.json`/`composer.lock` were not modified by the dry run (git status clean).

## Package Legitimacy Audit

The `gsd_run query package-legitimacy check` seam supports only npm, pypi and crates (it printed `Usage: ... --ecosystem <npm|pypi|crates>` for packagist), so the checks below were done directly against the Packagist API and `composer`. Both direct packages are already authoritative in this repo: Dompdf by the Phase 3 decision (owner-confirmed gate in 03-SPIKE-PDF.md), OpenSpout by being locked as a dependency of `filament/actions`.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| dompdf/dompdf | Packagist | since 2012 | ~10M/month | github.com/dompdf/dompdf | OK (manual) | Approved; licence `LGPL-2.1` alias needs the checker normalisation |
| dompdf/php-font-lib | Packagist | package since 2024 (long-lived project) | ~9M/month | github.com/dompdf/php-font-lib | OK (manual) | Transitive of Dompdf |
| dompdf/php-svg-lib | Packagist | package since 2024 | ~9M/month | github.com/dompdf/php-svg-lib | OK (manual) | Transitive of Dompdf |
| masterminds/html5 | Packagist | since 2013 | ~14M/month | github.com/Masterminds/html5-php | OK (manual) | Transitive of Dompdf |
| sabberworm/php-css-parser | Packagist | since 2012 | ~11M/month | github.com/MyIntervals/PHP-CSS-Parser | OK (manual) | Transitive of Dompdf |
| openspout/openspout | Packagist | since 2022 | ~8M/month | github.com/openspout/openspout | OK (manual) | Already installed 4.32.0 (MIT) |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
                       +-------------------+
   scheduler (cron)    |  CNB api (public) |
   15:00 16:00 17:00   +---------+---------+
   18:00 --final  ---> kokpit:rates:download           (sync, no queue)
                       |  DownloadCnbRates Action
                       |   1. skip if not a Czech working day (scheduled run only)
                       |   2. skip if list for today already stored (no HTTP)
                       |   3. GET daily?date=today  (quote "rate" -> decimal string)
                       |   4. validate, insertOrIgnore(rows)  --> exchange_rates
                       |   5. gap check: last stored < previous working day
                       |        -> daily-year for affected year(s), insertOrIgnore
                       |   6. --final and still no list for today on a working day
                       |        or any failure -> AdminAlerter::alert(mail + bell, sync)
                       v
                  exchange_rates  <--- BackfillCnbRates (KokpitJob, queued, from-to)
                       |                  ^ Filament "Backfill" action (Admin)
                       |   RateBook::preload(currencies, from, to)
                       v
 time_entries --> TimeReportReader (one SQL, Prague-day range, joins:
 + task_billing      tasks, task_billing, parent task_billing, project_billing, clients)
 + project_billing   yields per-entry rows: seconds, flags, effective type, rate, work_date
 + clients                |
                          v
                   EntryAmounts (Money::forDuration, Money::forDurationConverted)
                          |
        +-----------------+------------------+-----------------------+
        v                 v                  v                       v
  TimeReportPage     BuildReportExport   WorkReportPdf         Dashboard widgets
  (tree, summary,    (KokpitJob)         (Dompdf, no money,    (hours, unbilled
  fixed-price rate)  OpenSpout CSV/XLSX  cs/en, page N / M)    per client, tasks,
        |                 |                  |                  tomorrow plan)
        |                 v                  v
        |          private disk + report_exports row   Filament download action
        |          (7 days, prune job)                 (Livewire, permission checked)
        v
  Admin browser  <--- bell notification (database channel) with link to exports list
```

### Recommended Project Structure
```
app/Domain/ExchangeRates/
├── Models/ExchangeRate.php          # DeniesPartners, rate decimal:10 cast
├── CnbClient.php                    # HTTP + parse + validate (like AresClient)
├── CnbList.php                      # value: rate_date + list of CnbRate rows
├── Actions/DownloadCnbRates.php     # idempotent; used by command and manual action
├── Jobs/BackfillCnbRates.php        # KokpitJob, #[Idempotent]
├── RateBook.php                     # preloaded lookup: previous valid rate for a day
├── CnbConverter.php                 # toCzk(Money, 'Y-m-d'): Money; throws ExchangeRateMissing
├── ExchangeRateMissing.php
└── CzechPublicHolidays.php          # pure, no ext-calendar
app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php
app/Console/Commands/DownloadCnbRatesCommand.php   # kokpit:rates:download {--final}
app/Domain/Reports/
├── ReportFilters.php                # validated: client?, project?, user?, from, to (Prague days)
├── Queries/TimeReportReader.php     # per-entry rows (lazy), Admin/system only
├── EntryAmounts.php                 # rounding + CNB conversion per entry
├── TimeReport.php                   # aggregate tree + summary + fixed-price block
├── Exports/{ReportExport.php,BuildReportExport.php,PruneExpiredReportExports.php,SpreadsheetCells.php,CsvWriter.php,XlsxWriter.php}
└── WorkReport/{WorkReportPdf.php,WorkReportSource.php}
app/Filament/Pages/TimeReportPage.php
app/Filament/Resources/{ExchangeRateResource.php,ReportExportResource.php}
app/Filament/Widgets/{HoursStats,UnbilledPerClient,UpcomingDeadlines,TomorrowPlan,PartnerProjects,PartnerTasks}.php
resources/views/filament/pages/time-report.blade.php, resources/views/pdf/work-report.blade.php
lang/{cs,en}/work_report.php          # en does not exist yet: PDF English strings live here
```

### Pattern 1: CNB fetch and exact parse
**What:** One client, two endpoints, rates kept as decimal strings.
**When to use:** Daily run, manual run, backfill, gap fill.
**Evidence:** `GET https://api.cnb.cz/cnbapi/exrates/daily?date=2026-10-14&lang=EN` returned HTTP 200, 30 rates, `validFor` `2026-10-14`, `order` 198; `date=2026-10-10` (Saturday) returned `validFor` `2026-10-09`; `date=2026-10-15` and `2026-12-31` (future) returned `validFor` `2026-10-14`; `date=abc` returned HTTP 400 with `errorCode` `VALIDATION_ERROR`; `daily-year?year=2025` returned 7781 rows over 251 distinct dates [VERIFIED: live probes 2026-10-14]. Units seen: `HUF` 100, `ISK` 100, `INR` 100, `JPY` 100, `KRW` 100, `PHP` 100, `THB` 100, `TRY` 100, `IDR` 1000, all others 1; `XDR` (SDR) is in the list [VERIFIED: live probe].
```php
// Source: live CNB response shape (see Evidence); pattern mirrors app/Domain/Clients/Ares/AresClient.php
$response = Http::acceptJson()->connectTimeout(3)->timeout(15)
    ->retry(3, 300, when: fn (Throwable $e) => $e instanceof ConnectionException
        || ($e instanceof RequestException && $e->response->serverError()), throw: false)
    ->get(rtrim((string) config('services.cnb.base_url'), '/').'/exrates/daily', ['date' => $day, 'lang' => 'EN']);

// JSON numbers would become floats: quote "rate" first so it stays a decimal string.
$body = preg_replace('/"rate"\s*:\s*(-?\d+(?:\.\d+)?)/', '"rate":"$1"', $response->body());
$data = json_decode((string) $body, false, 512, JSON_THROW_ON_ERROR);
// validate per row: currencyCode ^[A-Z]{3}$, amount int >= 1, rate ^\d+(\.\d{1,10})?$ and > 0, validFor Y-m-d,
// all validFor equal, validFor <= the requested day (never trust the requested date, trust validFor).
```
Use `config/services.php` `'cnb' => ['base_url' => env('CNB_API_BASE_URL', 'https://api.cnb.cz/cnbapi')]` and add the variable to `.env.example` (a test keeps `.env.example` in sync with config reads).

### Pattern 2: Idempotent store
**What:** Table `exchange_rates`: `id uuid default uuidv7()`, `rate_date date`, `currency char(3)`, `unit_amount integer`, `rate numeric(20,10)`, `fetched_at timestamptz(0)`, `timestampsTz`; `UNIQUE (currency, rate_date)` named `exchange_rates_currency_date_unique`; `CHECK (currency ~ '^[A-Z]{3}$')`, `CHECK (unit_amount >= 1)`, `CHECK (rate > 0)`; index on `rate_date` (for `MAX(rate_date)` and the overview). Money columns follow the repo convention `rate numeric(20,10)` with `decimal:10` cast [VERIFIED: STATE.md decision "Money column convention: ... rates numeric(20,10) with decimal:10 cast"].
```php
// Eloquent builder forwards insertOrIgnore to the query builder: ON CONFLICT DO NOTHING.
// The primary key comes from the database default uuidv7(); timestamps are explicit.
ExchangeRate::query()->insertOrIgnore($rows); // returns the number of inserted rows
```
CNB lists are immutable once published, so a rerun must not rewrite a stored rate (`DO NOTHING`, not `DO UPDATE`). `DB::table(` is banned in `app/` by `tests/Arch/QueryEscapeHatchTest.php`; the Eloquent form above is allowed.

### Pattern 3: Lookup, not stored fill (weekends and holidays)
**What:** A day's rate is the newest row with `rate_date <= day` per currency. Do not store derived rows for non-publication days: it duplicates data, makes `MAX(rate_date)` meaningless for health, and a later correction of a gap would have to chase copies. D-05's "fills days with no CNB list with the previous valid rate" is satisfied by the lookup.
```php
// Bulk: one query for the whole report range, then in-memory resolution per (currency, day).
$floor = ExchangeRate::query()->where('currency', $c)->where('rate_date', '<', $from)->max('rate_date');
$rows  = ExchangeRate::query()->whereIn('currency', $currencies)
    ->where('rate_date', '>=', $floor ?? $from)->where('rate_date', '<=', $to)
    ->orderBy('currency')->orderBy('rate_date')->get(['currency', 'rate_date', 'unit_amount', 'rate']);
```
(The per-currency floor must be the newest list before `from`, not "14 days earlier": an outage or a long holiday block can be longer.)

### Pattern 4: Scheduled download without the queue
**What:** `Schedule::command('kokpit:rates:download')` runs in the scheduler process, so the alert on failure cannot be lost to a dead worker (success criterion 2). Manual button also calls the same `DownloadCnbRates` Action synchronously (one 15 s request). `BackfillCnbRates` is the only queued piece (D-05).
```php
// routes/console.php (every event needs name() and onOneServer(): ScheduleOnOneServerTest)
Schedule::command('kokpit:rates:download')
    ->cron('0 15-17 * * 1-5')->timezone('Europe/Prague')   // hours from config('kokpit.rates.schedule')
    ->name('kokpit-cnb-rates')->onOneServer();
Schedule::command('kokpit:rates:download', ['--final'])
    ->cron('0 18 * * 1-5')->timezone('Europe/Prague')
    ->name('kokpit-cnb-rates-final')->onOneServer();
```
`config/app.php` timezone is `UTC` (line 70), so `->timezone('Europe/Prague')` is required or 15:00 would be 17:00 local in summer.
The command wraps the work in `PartnerContext::runAsSystem()` like `EndOverrunTimersCommand`. Outcomes: a stored list for today means all later attempts return immediately without HTTP (CNB asks not to poll without need [CITED: cnb.cz FAQ "Kurzy devizového trhu na www stránkách ČNB": excessive access "budou omezeny"]). A non-final failure logs and waits for the next hour; the `--final` run alerts: transport/parse/DB failure, or (on a Czech working day) still no list dated today. Alert through `AdminAlerter::alert('cnb-rates:'.$day, new OperationalAlert($title, $body, $overviewUrl))`, which sends `database` then `mail` with `Notification::sendNow` and never throws [VERIFIED: app/Domain/Operations/Alerts/AdminAlerter.php]. Messages must not carry raw exception text (use class-level reason and HTTP status only, like `HealthResult`).
Self-heal: when the newest stored `rate_date` is older than the previous Czech working day, fetch `daily-year` for the affected year(s) and `insertOrIgnore`. A silent gap is dangerous because a missing day looks exactly like a holiday and the lookup would use an older rate without any warning.

### Pattern 5: Per-entry amounts through one reader
**What:** One SQL over `time_entries` joins the effective billing chain; PHP rounds once per entry; everything (report, export, dashboard) sums those rounded entry amounts.
Verified column names: `time_entries.started_at`, `ended_at`, `duration_seconds`, `billable`, `billing_state`, `user_id`, `client_id`, `project_id`, `task_id`; `tasks.parent_id`; `task_billing(task_id, billing_type, hourly_rate_minor, hourly_rate_currency, fixed_price_minor ...)`; `project_billing(project_id, billing_type, hourly_rate_minor, ...)`; `clients(currency, hourly_rate_minor, hourly_rate_currency, invoice_language)` [VERIFIED: migrations read this session].
```sql
-- Prague day of the entry (D-01) and effective billing, same order as TaskBillingResolver / TimeEntryRateResolver
SELECT te.id,
       (te.started_at AT TIME ZONE 'Europe/Prague')::date AS work_date,
       COALESCE(te.duration_seconds, <TimeEntry::elapsedSecondsSql('te') with bound now>) AS seconds,
       COALESCE(NULLIF(tb.billing_type,'inherit'), NULLIF(ptb.billing_type,'inherit'), pb.billing_type, 'hourly') AS billing_type,
       COALESCE(tb.hourly_rate_minor, ptb.hourly_rate_minor, pb.hourly_rate_minor, c.hourly_rate_minor) AS rate_minor,
       c.currency
FROM time_entries te
JOIN clients c ON c.id = te.client_id
LEFT JOIN tasks t ON t.id = te.task_id
LEFT JOIN task_billing tb ON tb.task_id = t.id
LEFT JOIN task_billing ptb ON ptb.task_id = t.parent_id
LEFT JOIN project_billing pb ON pb.project_id = te.project_id
WHERE te.started_at >= :from_utc AND te.started_at < :to_utc   -- PragueDay::start(from) / endExclusive(to)
```
`COALESCE` keeps zero as a value (only null defers), matching the resolver rule "A rate of zero is a value" (app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php docblock). The global default rate never applies in SQL because `clients.hourly_rate_minor` is NOT NULL. Rules per entry: `billable = false` gives no amount; effective type `fixed_price` gives no amount and counts hours only (D-07); otherwise `Money::forDuration(rate, seconds)` in the client's currency, and CZK from the CNB rate of `work_date` (CZK clients: same amount, no lookup). Guard the SQL with `PartnerContext` (Admin or system only, like `TimeEntryRateResolver`) because the raw joins bypass the `PartnerScope` of the joined tables.
A **parity test** must run the SQL chain and `TimeEntryRateResolver::resolve()` over a matrix (task own rate, parent task rate, project rate, client rate, zero rate, fixed type at task / parent / project, non-billable task, project-only entry, client-only entry) and require identical rate and type. The resolver stays the specification; the SQL is an optimisation.

### Pattern 6: Spreadsheet-safe cells
```php
// Source: verified by writing an XLSX with OpenSpout 4.32 and unzipping it (see Pitfall 1)
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;

final class SpreadsheetCells
{
    /** Text from users or ARES: neutralise leading formula characters, and never let OpenSpout infer a formula. */
    public static function text(?string $value): StringCell
    {
        $value ??= '';
        if (preg_match('/^[\s]*[=+\-@]|^[\t\r\n]/u', $value) === 1) {
            $value = "'".$value;
        }
        return new StringCell($value, null);
    }
}
// numbers: Cell::fromValue(int|float) (NumericCell); never pass user strings to Row::fromValues().
```
Apply to every text cell (client, project, task, user, description) in CSV and in XLSX. Numeric cells (including negative numbers) are real numbers and stay safe.

### Pattern 7: Dompdf work report
Verified in a scratch run outside the repo (Dompdf 3.1.6 from the spike vendor directory): 120-row table, 3 pages, 168 ms, 34 MB peak; header repeated on every page via `position: fixed` block with negative top inside the `@page` margin; `thead { display: table-header-group }` repeated; `Strana {PAGE_NUM} / {PAGE_COUNT}` via `$canvas->page_text()` after `render()` printed `Strana 3 / 3` on the last page (this closes the spike's open item "total page count not measured"); Czech text extracted correctly; HTML in data (`<b>`) rendered literally when escaped; fonts embedded as subsets (`DejaVuSans`, `DejaVuSans-Bold`) [VERIFIED: pdftotext/pdffonts/pdfinfo on the generated PDF].
```php
// Source: scratch verification this session; options per Dompdf docs
$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');          // default Helvetica has no Czech glyphs
$options->set('chroot', storage_path('app/pdf-chroot')); // nothing readable from the filesystem
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');   // $html from Blade ({{ }} escaped), rendered inside App::withLocale($language)
$dompdf->setPaper('A4');
$dompdf->render();
$canvas = $dompdf->getCanvas();
$canvas->page_text(450, 810, __('work_report.page').' {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
$pdf = $dompdf->output();
```
Design for Phase 10 reuse now: `WorkReportPdf::render(WorkReportSource $source, string $language): string` where `WorkReportSource` is built either from filters (client, optional project, period, billable-only) or from an explicit list of entry IDs, so the invoice can attach exactly the billed entries later. Table rows are grouped by day and task (rows scale with days x tasks, not entries), which keeps memory far below the 124 MB measured at 500 raw rows [CITED: 03-SPIKE-PDF.md]. Before rendering, raise the limit only when it is lower and not unlimited: `ini_set('memory_limit', '256M')`.

### Pattern 8: Dashboard composition
`Dashboard::getWidgets()` returns the full role-specific list; `Dashboard::content()` (default) renders `getWidgetsSchemaComponents($this->getWidgets())`, which filters by each widget's `canView()` [VERIFIED: vendor/filament/filament/src/Pages/Dashboard.php `getWidgetsContentComponent`]. Every widget declares `#[AccessRule]` and uses `EnforcesWidgetAccessRule` (registry test fails otherwise). `Audience::PartnerAllowed` admits both roles, so a Partner-only widget must also override `canView()` to require `PartnerContext::partnerClientId() !== null` and not Admin, exactly as `PartnerProjectResource::canAccess()` does. The panel already calls `->discoverWidgets(in: app_path('Filament/Widgets'), ...)` and the directory does not exist yet.
- Hours: `TimesheetQuery::summary($admin, $from, $to, $now)` already counts a running entry at its elapsed time and gives `total`, `billable`, `non_billable` [VERIFIED: app/Domain/TimeTracking/Queries/TimesheetQuery.php docblock]; ranges from `dayRange()`, `weekRange()` (Monday to Sunday) and a new month range built with `PragueDay::start()/endExclusive()`.
- Unbilled per client (current month): same reader, filtered to `billable AND billing_state = 'unbilled'`, grouped by client; fixed-price entries add hours but no amount (state this in the column heading or a footnote).
- Deadlines: `Task` with `due_date IS NOT NULL`, `status <> 'done'`, ordered by `due_date`, limit 10; overdue = `due_date < today (Prague)`; archived tasks are excluded by the soft-delete scope. Values: tasks status CHECK `('planned', 'to_clarify', 'in_progress', 'in_review', 'ready_to_release', 'done')` (database/migrations/2026_10_10_000100_create_tasks_table.php:86), enum `ProjectStatus::InReview = 'in_review'` (app/Domain/Projects/Enums/ProjectStatus.php:21).
- Tomorrow plan: `SignalDayReader::tasks(SignalCalendar::tomorrow())` (owner-scoped to the signed-in user) plus `shadows()` for recurring templates not yet materialised, so the block matches the "Dnes a zítra" page; collapsed Alpine block, first 3 visible. No Signal action is called.
- Partner: scoped `Project::query()` and `Task::query()` through the pinned `ProjectColumns::partnerColumns()` / `TaskColumns::partnerColumns()` (`PARTNER_COLUMN_NAMES` are public constants compared by tests); order `CASE WHEN status = 'in_review' THEN 0 ELSE 1 END`, then the existing priority order `TaskColumns::orderByPriority`; highlight with a Filament badge/icon, not Tailwind utility classes (see Pitfall 11). Keep the existing empty-state text for a Partner with nothing to show.

### Anti-Patterns to Avoid
- **Storing derived weekend/holiday rate rows:** use lookup (Pattern 3).
- **`date(started_at)` on the UTC value for day bucketing:** use Prague range predicates and `AT TIME ZONE 'Europe/Prague'` for the label (CONTRIBUTING Phase 8 hand-over note).
- **Resolving rates with `TimeEntryRateResolver` per entry in a report:** it loads relations per call (N+1); use the SQL chain plus the parity test.
- **Float rates or amounts anywhere** (arch rule in `Money`); **Brick types outside `App\Domain\Shared\Money`** (arch test).
- **Alerting on every failed hourly attempt:** with `KokpitJob` retries plus `ReportFailedJob` that would send up to four alerts a day; the scheduled command alerts only on `--final`.
- **A public or signed URL for exports:** D-09 says download through the app.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| XLSX/CSV writing | Own ZIP/XML writer | OpenSpout 4 (installed) | Streaming, encoding, BOM, styles; but wrap text cells (Pitfall 1) |
| PDF layout | Own PDF writer | Dompdf 3.1.6 | Decided in Phase 3 |
| Money rounding and conversion | float math, `round()` | `Money` (+ the two new exact methods) | Single rounding point, enforced by arch tests |
| Admin alert delivery | Custom mail/bell code | `AdminAlerter` + `OperationalAlert` | Queue-independent, throttled, never throws |
| Job reliability | Raw `ShouldQueue` class | `KokpitJob` + `#[Idempotent]` | Retry/backoff/system context/failed-job alert; `JobContractTest` enforces it |
| Prague day boundaries | `Carbon::startOfDay` ad hoc | `PragueDay`, `TimesheetQuery` ranges | 23/25-hour DST days, storable-instant envelope |
| Duration display | own formatting | `DurationFormat::hoursMinutes()` | Truncates, never rounds up |
| Time sums | new SUM expressions | `TimeEntry::elapsedSecondsSql()` and the `TimeTotals` identity | Running entries bound from the PHP clock, clamped |
| Easter / holiday arithmetic | ad hoc date math in several places | one `CzechPublicHolidays` class | Needed by schedule gating, health slot and final alert; verified below |

**Key insight:** every hard number problem here (rounding, day boundaries, running entries, rate chain) already has a single owner in the repo; the phase succeeds by routing through those owners and adding only the two missing exact `Money` methods.

## Common Pitfalls

### Pitfall 1: OpenSpout turns `=...` strings into real formulas
**What goes wrong:** `Row::fromValues(['=1+1'])` writes `<f>1+1</f>` into the XLSX (a live formula); the CSV writer emits `=1+1` raw (Excel evaluates it on open).
**Why it happens:** `Cell::fromValue()` creates a `FormulaCell` when a string starts with `=` (vendor/openspout/openspout/src/Common/Entity/Cell.php:59-60: `if (isset($value[0]) && '=' === $value[0]) { return new FormulaCell($value, $style, null); }`).
**How to avoid:** build text cells as `new StringCell($text, null)` after prefixing `'` (Pattern 6); never pass user text to `Row::fromValues()`. A test must write a file with canary strings `=`, `+`, `-`, `@`, tab and read it back (OpenSpout reader) asserting inline strings and no formula.
**Warning signs:** an XLSX cell shows a computed value, or `<f>` appears in `xl/worksheets/sheet1.xml`.
Verified output this session: `Row::fromValues(['=1+1','+SUM(A1)','-5','@x'])` gave `A1 <f>1+1</f>` and the others `inlineStr`; an explicit `StringCell('=1+1')` gave `inlineStr`; `Cell::fromValue(-5)` gave a numeric `<v>-5</v>`.

### Pitfall 2: JSON floats for CNB rates
**What goes wrong:** `json_decode` yields floats; PHP prints very small floats as `1.0E-5`, which `Money::convert()` rejects (its regex requires digits and at most ten fraction digits).
**How to avoid:** quote `"rate"` values before decoding (Pattern 1) and validate with the same regex as `Money::convert`.

### Pitfall 3: Trusting the requested date
**What goes wrong:** the API answers any date with the newest list not after it (future dates return today's list). Storing rows under the requested date would fabricate rates.
**How to avoid:** store under `validFor` from the payload only; require all `validFor` equal in a `daily` response and `<= requested date`.

### Pitfall 4: A gap in stored lists looks like a holiday
**What goes wrong:** lookup silently uses an older rate; health would still be OK on later days.
**How to avoid:** gap self-heal in the scheduled run (Pattern 4), and a `LastRateDateIndicator` that compares the newest stored date with the expected date from `CzechPublicHolidays`, not with `now()` minus a fixed number of days.

### Pitfall 5: Per-unit amounts
**What goes wrong:** treating 100 HUF as 1 HUF multiplies a CZK amount by 100.
**How to avoid:** the rate means CZK per `unit_amount` units; always call `Money::convert($rate, 'CZK', $unitAmount)` (existing, supports `$unitAmount`) and test HUF (100), IDR (1000), JPY (no fraction digits target currency check) and EUR (1).

### Pitfall 6: Double rounding in CZK
**What goes wrong:** `forDuration()` (rounded to the client's minor unit) then `convert()` (rounded again) accumulates avoidable error (up to half a minor unit times the rate per entry).
**How to avoid:** add `Money::forDurationConverted(Money $hourlyRate, int $seconds, string $decimalRate, string $toCurrency, int $unitAmount = 1)` that builds one exact rational and calls `fromExactMinor` once. It must live in `Money` (arch tests confine Brick and `RoundingMode`).

### Pitfall 7: Report vs invoice amounts
**What goes wrong:** a time entry stores no money until Phase 10 snapshots it [CITED: app/Domain/TimeTracking/Billing/EntryRate.php docblock]. A report of billed entries computed from today's rates differs from the issued invoice after a rate change.
**How to avoid:** label report amounts "vypočteno z aktuální sazby" and add to the Phase 10 hand-over: switch billed entries to snapshot columns. Sums of rows may differ from an invoice line by rounding (per entry vs per line).

### Pitfall 8: Mixed currencies in one total
**What goes wrong:** summing client-currency amounts of clients with different currencies.
**How to avoid:** client-currency subtotals per client (and per currency in the summary); only the CZK column sums across clients. If a CZK rate is missing for some entries, show the total with a visible "chybí kurz" marker and exclude those entries from the CZK sum; never show zero.

### Pitfall 9: Filament built-in export trap
See "Alternatives Considered": do not use `ExportAction`. Its `exports.id` is a bigint (schema rule R1 fails) and the `job_batches` table was dropped in Phase 2.

### Pitfall 10: Livewire download holds the file in memory
**What goes wrong:** returning a `StreamedResponse` from an action makes Livewire capture the output and base64 it into the JSON response (vendor/livewire/livewire/src/Features/SupportFileDownloads/SupportFileDownloads.php `captureOutput` + `base64_encode`). Fine for a few MB, risky for huge exports.
**How to avoid:** accept it for the work report PDF and exports of a normal freelancer volume (and keep CSV/XLSX streaming in the job); record a note that a controller route is the fallback if exports ever exceed ~20 MB. This also keeps the route-walk test (`tests/Isolation/RouteWalkTest.php` walks every `filament.admin.*` GET route and fails on an unknown route parameter other than `{record}`) green.

### Pitfall 11: No Node build, so Tailwind utilities may be missing
**What goes wrong:** classes such as `break-all`, `select-all` were absent from the precompiled Filament CSS in Phase 7 [CITED: STATE.md 07-03]; row highlighting by a utility class would silently not render.
**How to avoid:** use Filament components (`x-filament::badge`, colours, icons) and inline `style=` attributes like `system-page.blade.php` does.

### Pitfall 12: Existing tests pin the old behaviour
`tests/Feature/Operations/HealthRegistryTest.php:116` expects `LastRateDate` to be `NotAvailable` through the app registry; `tests/Isolation/PanelAccessTest.php:52-61` expects the empty-dashboard heading and Admin/Partner descriptions; `tests/Arch/JobContractTest.php:28` pins `EXPECTED_APPLICATION_JOBS = [RecordWorkerHeartbeat::class, NotifyLongRunningTimers::class, PurgeExpiredIdempotencyKeys::class]`; `tests/Arch/ModelDeclarationTest.php` lists every model. All four must be edited deliberately (Wave 0).

### Pitfall 13: Scheduler time zone and DST
`config/app.php` timezone is `UTC`; without `->timezone('Europe/Prague')` the 15:00 slot drifts by an hour between summer and winter. Cron hour lists in the Prague zone are DST-safe.

### Pitfall 14: Licence gate
`composer licenses` reports Dompdf as `LGPL-2.1` (deprecated SPDX alias) and `scripts/check-licenses.php` allows `LGPL-2.1-only`, `LGPL-2.1-or-later`, `LGPL-3.0-only`, `LGPL-3.0-or-later` but not the alias [CITED: 03-SPIKE-PDF.md Licences]. Take the documented maintainer decision in this phase (one reviewed line normalising the alias), otherwise CI fails. Not legal advice.

### Pitfall 15: First deploy shows Error until the first download
The new slot treats "never downloaded" as Error (same philosophy as `SchedulerHeartbeatIndicator`). Add a README/CONTRIBUTING note: run the manual download (or `php artisan kokpit:rates:download`) after the first deploy.

### Pitfall 16: Job timeout chain
`KokpitJob` defaults to a 60 s timeout; the chain is asserted by `HorizonConfigTest` as job < supervisor 300 s < redis `retry_after` 330 s (config/horizon.php header comment). Backfill over many years and a large export need `#[Timeout(240)]`; keep every override below 300 and re-run `HorizonConfigTest`. Horizon workers also have `'memory' => 128` (MB): stream rows (`lazy()`/cursor) instead of collecting.

## Code Examples

### CNB list parse and expected-date logic
```php
// Source: probe results above; CzechPublicHolidays verified against CNB data (see Assumptions A5)
final class CzechPublicHolidays
{
    public static function isWorkingDay(CarbonImmutable $day): bool
    {
        return $day->isWeekday() && ! in_array($day->format('m-d'), self::fixed(), true) && ! self::isEasterRelated($day);
    }
    // fixed: 01-01, 05-01, 05-08, 07-05, 07-06, 09-28, 10-28, 11-17, 12-24, 12-25, 12-26
    // Good Friday (Easter-2d, since 2016) and Easter Monday (Easter+1d) from the Meeus/Jones/Butcher algorithm (no ext-calendar)
}
```

### Effective hourly rate and converted duration (new `Money` methods)
```php
// To add inside App\Domain\Shared\Money\Money (Brick stays confined; rounding only via fromExactMinor)
public static function hourlyRateFor(self $price, int $seconds): self   // price * 3600 / seconds, rounded once
{
    // throws InvalidArgumentException when $seconds < 1; callers show "-" for zero hours
    return self::fromExactMinor((string) BigRational::ofFraction(BigInteger::of($price->minor)->multipliedBy(3600), $seconds), $price->currency);
}

public static function forDurationConverted(self $hourlyRate, int $seconds, string $decimalRate, string $toCurrency, int $unitAmount = 1): self
{
    // exact: rateMinor/10^srcDigits * seconds/3600 * decimalRate/unitAmount * 10^targetDigits, then fromExactMinor once
}
```

### Last-rate-date health slot
```php
// Replace the placeholder: OperationsServiceProvider::$real gets new LastRateDateIndicator (missingSlots() then skips the placeholder)
$expected = ExpectedRateDate::forNow();            // latest Czech working day <= today; today only after 15:00 Prague
$last = ExchangeRate::query()->max('rate_date');   // null => Error "never downloaded"
$lag = CzechPublicHolidays::workingDaysBetween($last, $expected);
// 0 => Ok, >= config('kokpit.rates.health.warning_after_working_days') (1) => Warning, >= ...error_after... (2) => Error
// HealthResult value: last date; detail: Czech text only, never exception messages
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Parse `denni_kurz.txt` (pipe text, decimal comma, header `14.10.2026 #198`) | `api.cnb.cz/cnbapi/exrates/daily` and `daily-year` JSON with `validFor`, `amount` | Both live (probed 2026-10-14) | JSON is easier for history (`daily-year`); text format stays as a fallback [CITED: cnb.cz FAQ names both the TXT URL and the `/cnbapi/exrates` API] |
| One request per day for backfill | One request per year (`daily-year`, ~560 KB, ~250 dates) | — | Fewer requests; CNB warns against excessive access [CITED: cnb.cz FAQ] |
| Filament Exporter for exports | Custom `KokpitJob` + OpenSpout | Project decisions (UUID keys, no batches) | See Alternatives |

**Deprecated/outdated:** the older `cnb.cz/cs/financni_trhy/...` TXT path appears in legacy snippets; use the current documented URL or the API. SPDX alias `LGPL-2.1` is deprecated in SPDX (Pitfall 14).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Czech Excel opens a UTF-8 BOM, `;`-delimited CSV with decimal comma correctly (OpenSpout CSV `Options` default is `,` delimiter and BOM on) | Pattern 6 / Exports | CSV opens as one column; mitigated by configurable delimiter in `config/kokpit.php` |
| A2 | The Zerops `php-nginx@8.5` runtime has `ext-dom`, `ext-mbstring`, `ext-zip` and enough PHP memory for Dompdf/OpenSpout (DDEV has all, `memory_limit` 1024M web / -1 CLI) | Environment | Phase 3 deploy rehearsal item (`php -m`) should confirm before release |
| A3 | D-08 "all worked hours of the whole project" means every entry of the project, billable or not, finished or running (matches `TimeTotals::forProject()['worked']`) | Pattern 5 / RE-05 | Effective rate differs if the owner meant billable only |
| A4 | The CZK value of the fixed price in the effective-rate display uses the CNB rate of the last day of the selected period capped at today | RE-05 | Owner may prefer today's rate; D-08 does not state the date |
| A5 | CNB does not publish on any Czech public holiday in future years (verified for 2023-2025 only) | CzechPublicHolidays, health, final alert | A new holiday rule would cause one false alert or Warning; the list is a single class |
| A6 | An entry spanning midnight belongs wholly to the Prague day it started (CONTRIBUTING research A5, owner confirmation still pending) | Pattern 5 | Per-day rate and day buckets shift for a rare entry |
| A7 | Tasks whose effective type is `fixed_price` through a task or parent override inside an hourly project show hours only, no amount and no per-task effective rate | Pattern 5 | Owner may want a per-task effective rate for task-level fixed prices |
| A8 | Dashboard "unbilled per client" lists only clients with unbilled billable time in the current month, hours always, amount only for hourly-priced entries | Pattern 8 | Fixed-price clients show hours without money |
| A9 | One Admin exists; "hours today / this week" are the signed-in Admin's own entries (as `TimesheetQuery::summary` takes a `User`) | Pattern 8 | A second Admin would see own hours only |
| A10 | `poppler-utils` (`pdftotext`) may be absent on CI; PDF tests must not hard-depend on it | Validation | A test that needs it must skip when the binary is missing |

## Open Questions

1. **Conversion date of the fixed price in the effective hourly rate (D-08, A4)**
   - What we know: CZK needs a date; D-01 uses the entry day for time amounts; a fixed price is not an entry.
   - What's unclear: end of period, today, or the project's last entry day.
   - Recommendation: last day of the selected period capped at today (past reports stay stable); confirm in discuss/plan review.

2. **Command vs `KokpitJob` for the daily download**
   - What we know: CONTEXT lists `KokpitJob` as the base "for the rate download, backfill and export jobs"; success criterion 2 requires an alert that does not depend on the queue.
   - What's unclear: whether the owner expects a queued job.
   - Recommendation: synchronous scheduled command + shared Action for the daily/manual download (alert cannot be lost to a dead worker); `BackfillCnbRates`, `BuildReportExport`, `PruneExpiredReportExports` are `KokpitJob`s. Update `EXPECTED_APPLICATION_JOBS` accordingly.

3. **Task-level fixed price (A7)** and **billed-entry amounts before Phase 10 (Pitfall 7)** are scope-boundary calls for the planner; recommendations above.

4. **UI contract:** the phase has `UI hint: yes` and no UI-SPEC exists. Recommend `/gsd-ui-phase 8` before planning for the report tree table, the dashboard grid and the Partner dashboard.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | everything | ✓ | 8.5.11 (host and DDEV) | — |
| DDEV (web, db postgres:18, redis) | tests | ✓ | DDEV v1.25.4, all services OK | — |
| ext-gd (PNG), ext-dom, ext-mbstring, ext-zip, ext-xmlreader, ext-zlib, ext-intl | Dompdf, OpenSpout | ✓ in DDEV | `php -m` shows all; `gd_info()['PNG Support']` is `bool(true)` | Zerops runtime unverified (A2) |
| ext-calendar | only if spatie/holidays were used | ✓ in DDEV | — | Not needed: own `CzechPublicHolidays` |
| CNB API (`api.cnb.cz`) | EX-01 | ✓ | HTTP 200 from this machine | TXT endpoint on `www.cnb.cz` |
| pdftotext / pdfinfo / pdffonts | optional PDF verification | ✓ on dev host | poppler | Skip-if-missing guarded test (A10) |
| Composer packages dompdf, openspout | RE-03 | dompdf ✗ (not yet in `composer.json`), openspout ✓ | v3.1.6 resolvable; 4.32.0 installed | — |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** dompdf must be added (`composer require`); Zerops extension check is a deploy-rehearsal item.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5 on PHPUnit 13 (composer.json), suites Unit, Feature, Arch, Isolation, Concurrency |
| Config file | `phpunit.xml` (`APP_ENV=testing`, PostgreSQL `kokpit_test`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `FILESYSTEM_DISK=local`) |
| Quick run command | `ddev exec vendor/bin/pest --filter=<name>` |
| Full suite command | `ddev exec vendor/bin/pest` (also `ddev exec composer stan`, `lint`, `check-licenses`) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| EX-01 | Rerun creates no duplicates; weekend date returns Friday list stored once; 100/1000-unit rows stored with `unit_amount`; backfill from-to idempotent; gap self-heal | Feature (`Http::fake` with invented rates) | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/DownloadCnbRatesTest.php` | ❌ Wave 0 |
| EX-01 | Schedule entries named, `onOneServer`, Prague timezone, cron hours from config | Feature | `ddev exec vendor/bin/pest tests/Feature/Operations/ScheduleOnOneServerTest.php` | ✅ exists, extend |
| EX-01 | Schema: unique, CHECKs, UUID v7 | Feature | `ddev exec vendor/bin/pest tests/Feature/Schema/ExchangeRatesTableTest.php` | ❌ Wave 0 |
| EX-02 | Failure on non-final attempt: no alert; final attempt failure or missing list on a working day: mail and bell sent without queue; holiday: no alert | Feature | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/RateAlertTest.php` | ❌ Wave 0 |
| EX-02 | Health slot Ok/Warning/Error/never; System page shows last rate date | Feature | `ddev exec vendor/bin/pest tests/Feature/Operations/LastRateDateIndicatorTest.php` | ❌ Wave 0 |
| EX-02 | Overview resource Admin-only, Partner 403, manual action, backfill dispatch | Feature/Isolation | `ddev exec vendor/bin/pest tests/Feature/ExchangeRates/ExchangeRateResourceTest.php` | ❌ Wave 0 |
| EX-03 | Converter: previous valid rate, CZK passthrough, 100-unit currency, `ExchangeRateMissing`, HALF_UP once; new `Money` methods vs brick reference | Unit | `ddev exec vendor/bin/pest tests/Unit/Money tests/Unit/ExchangeRates` | ❌ Wave 0 |
| RE-01 | Tree totals by task/day/user; Prague-day boundaries incl. a 23-hour and a 25-hour day; running entry; constant query count | Feature | `ddev exec vendor/bin/pest tests/Feature/Reports/TimeReportTest.php` | ❌ Wave 0 |
| RE-02 | worked = billed + unbilled + non_billable equals `TimeTotals`; CZK uses the entry day's rate; missing rate flagged; mixed currencies | Feature | same file plus `tests/Feature/Reports/EffectiveBillingParityTest.php` | ❌ Wave 0 |
| RE-03 | Export job writes CSV and XLSX, summary sheet, formula canaries inert, notification, 7-day prune, download permission, no Partner access | Feature/Isolation | `ddev exec vendor/bin/pest tests/Feature/Reports/ReportExportTest.php` | ❌ Wave 0 |
| RE-03 | Work report: cs and en, no money strings, canvas page count > 1 for long input, `%PDF` header, embedded DejaVu font, optional `pdftotext` check skipped when missing | Feature | `ddev exec vendor/bin/pest tests/Feature/Reports/WorkReportPdfTest.php` | ❌ Wave 0 |
| RE-04 | Admin widgets render values; Partner dashboard shows own visible projects, `in_review` first, canary of other client absent, no money/time; tomorrow plan read-only | Feature/Isolation | `ddev exec vendor/bin/pest tests/Feature/Dashboard tests/Isolation/PanelAccessTest.php` | ❌ Wave 0 / ✅ extend |
| RE-05 | Effective rate = price*3600/all project seconds (not period only), CZK shown, zero hours shows dash | Unit/Feature | `ddev exec vendor/bin/pest tests/Feature/Reports/EffectiveRateTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `ddev exec vendor/bin/pest --filter=<new test class>` (seconds).
- **Per wave merge:** `ddev exec vendor/bin/pest` plus `composer stan` and `lint`.
- **Phase gate:** full suite, `check-licenses` and `scripts/check-sensitive.sh` green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] New test files listed above, with invented CNB fixtures (no real lists copied).
- [ ] Edit `tests/Feature/Operations/HealthRegistryTest.php:116` (LastRateDate no longer a placeholder; `UnprocessedWebhooks` and `UnsentInvoiceEmails` remain).
- [ ] Edit `tests/Isolation/PanelAccessTest.php` (lines 52-61: dashboard texts) and keep an empty-state text for a Partner with nothing to show.
- [ ] Edit `tests/Arch/JobContractTest.php:28` `EXPECTED_APPLICATION_JOBS`, `tests/Arch/ModelDeclarationTest.php` model list, `MorphMap::MAP` (`exchange_rate`, `report_export`), `AccessServiceProvider` policy registrations (`ExchangeRate`, `ReportExport` to `AdminOnlyPolicy`).
- [ ] `composer.json`: `dompdf/dompdf ^3.1`, `openspout/openspout ^4.32`; `scripts/check-licenses.php` alias normalisation; `.env.example` `CNB_API_BASE_URL`; CONTRIBUTING hand-over notes (documentation test `RepositoryFilesTest` fails if a named class is missing).
- [ ] Mutation-style self-checks in the project's style: a test that fails when `insertOrIgnore` is replaced by plain insert; a formula-canary test that fails when `Row::fromValues` is used.

## Security Domain

`security_enforcement` is enabled (absent = enabled), ASVS level 1, block on high.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (existing panel auth, Admin 2FA) | unchanged |
| V3 Session Management | no | unchanged |
| V4 Access Control | yes | `#[AccessRule(Audience::AdminOnly)]` on every new Page/Resource/Widget, `DeniesPartners` models + `AdminOnlyPolicy`, `OwnedByUser` on `ReportExport`; Partner widgets AND `partnerClientId() !== null`; Partner isolation canary tests; route-walk |
| V5 Input Validation | yes | `ReportFilters` validates ids (uuid), dates via `PragueDay::day()`, range order; backfill range capped (`from >= 2000-01-01`, `to <= today`, max span from config); CNB payload validated field by field |
| V6 Cryptography | no | none hand-rolled |
| V8 Data Protection | yes | exports on private disk, not public, deleted after 7 days; notification body has no client data beyond what the Admin owns |
| V12 Files and Resources | yes | download only through a permission-checked action; file name generated server side (uuid), user text never in a path; Dompdf `chroot` set, `isRemoteEnabled` and `isPhpEnabled` false |
| V13 API / outbound | yes | fixed base URL from config (no user-supplied host), TLS verification on, timeouts, retry only on connection error / 5xx |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| CSV/XLSX formula injection from descriptions, task titles, ARES names | Tampering | Prefix `'` and explicit `StringCell` (Pitfall 1); canary test |
| Partner reads rates, prices, time via dashboard, widgets or Livewire update | Information disclosure | Widget `canView` fail-closed + boot hook (`EnforcesWidgetAccessRule`), pinned Partner column builders, canary strings in tests |
| Stored XSS / HTML injection into PDF | Tampering | Blade `{{ }}` escaping, Dompdf remote and PHP disabled, no `{!! !!}` on user text |
| Path traversal or guessable export URL | Information disclosure | Server-generated uuid paths, `OwnedByUser` scope, permission check in the action, no public URL |
| Alert flood on repeated CNB failures | Denial of service (operator) | Alert only on final attempt; `AdminAlerter` throttle (900 s) |
| Poisoned rate (malformed, zero, huge) | Tampering | Row validation + DB CHECKs (`rate > 0`, `unit_amount >= 1`, currency regex) |
| Excessive polling of CNB leading to blocking | Denial of service | Skip HTTP when today's list is stored; one request per year for history |
| Error text leaks hosts/paths into alerts or health detail | Information disclosure | Class-level reasons only (existing `HealthResult` rule, `AlertMessageSanitiser`) |

## Sources

### Primary (HIGH confidence)
- Live probes of `https://api.cnb.cz/cnbapi/exrates/daily` and `daily-year` (2026-10-14): response shape, `validFor` semantics, units, error shape, 2023-2025 publication days vs Czech public holidays (script comparing weekdays minus 13 holidays: zero differences in all three years).
- Repository source read this session: `config/kokpit.php`, `routes/console.php`, `app/Domain/Operations/{Alerts,Health,Jobs}/*`, `app/Providers/OperationsServiceProvider.php`, `app/Domain/Shared/Money/Money.php`, `app/Domain/TimeTracking/{Queries,Billing,Models,Support}/*`, `app/Domain/Tasks/Billing/*`, `app/Domain/Signal/Queries/*`, `app/Filament/Pages/{Dashboard,TimesheetPage,SystemPage}.php`, `app/Filament/Partner/Resources/*`, migrations for clients, project_billing, task_billing, tasks, time_entries, signal tables, existing Arch/Isolation/Feature tests named above, `CONTRIBUTING.md` Phase 8 hand-over bullet, `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md`.
- vendor source: `openspout/openspout` `Cell::fromValue` (Cell.php:42-62), XLSX `WorksheetManager`, CSV `Options`; `filament/actions` export migration/models; `filament/filament` `Dashboard`; `livewire/livewire` `SupportFileDownloads`.
- Scratch experiments (outside the repo): OpenSpout XLSX/CSV written and unzipped; Dompdf 3.1.6 render with `page_text` checked with `pdfinfo`, `pdftotext`, `pdffonts`.
- Packagist API (dompdf, openspout, transitive packages) and `ddev composer require --dry-run`, `composer why`, `composer audit`.

### Secondary (MEDIUM confidence)
- https://www.cnb.cz/cs/casto-kladene-dotazy/Kurzy-devizoveho-trhu-na-www-strankach-CNB/ (fetched): publication about 14:30 on working days, TXT URL, API base `https://api.cnb.cz/` with `/cnbapi/exrates`, no numeric limit but excessive access may be restricted. The page does not document API parameters or weekend/holiday validity; those come from the live probes.

### Tertiary (LOW confidence)
- Training knowledge about Czech Excel list separators and CSV behaviour (A1), Zerops container extensions (A2).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH. Versions resolved with Composer this session; OpenSpout already locked; Dompdf proven by spike plus a fresh scratch run.
- Architecture: HIGH for CNB, rates, money and export (probed or read from source); MEDIUM for widget and report-page composition (no UI-SPEC yet).
- Pitfalls: HIGH for 1-6, 9-10, 12-14 (verified by experiment or source); MEDIUM for 7, 8, 11, 15, 16 (reasoned from repo contracts).

**Research date:** 2026-10-14
**Valid until:** 2026-11-13 (CNB API and Dompdf are stable; re-probe the CNB endpoints if planning slips past a month)
