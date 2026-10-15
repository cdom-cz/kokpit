---
phase: 08-exchange-rates-and-reports
reviewed: 2026-10-15T12:00:00Z
depth: standard
files_reviewed: 69
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Console/Commands/DownloadCnbRatesCommand.php
  - app/Domain/ExchangeRates/Actions/DownloadCnbRates.php
  - app/Domain/ExchangeRates/Actions/HealCnbRateGaps.php
  - app/Domain/ExchangeRates/Cnb/CnbClient.php
  - app/Domain/ExchangeRates/Cnb/CnbDownloadFailed.php
  - app/Domain/ExchangeRates/Cnb/CnbFailure.php
  - app/Domain/ExchangeRates/Cnb/CnbList.php
  - app/Domain/ExchangeRates/CzechPublicHolidays.php
  - app/Domain/ExchangeRates/ExchangeRateConverter.php
  - app/Domain/ExchangeRates/ExchangeRateMissing.php
  - app/Domain/ExchangeRates/Jobs/BackfillCnbRates.php
  - app/Domain/ExchangeRates/Models/ExchangeRate.php
  - app/Domain/ExchangeRates/RateBook.php
  - app/Domain/ExchangeRates/RateFormat.php
  - app/Domain/Operations/Alerts/OperationalAlert.php
  - app/Domain/Operations/Health/Indicators/LastRateDateIndicator.php
  - app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php
  - app/Domain/Reports/EntryAmounts.php
  - app/Domain/Reports/Enums/ReportExportFormat.php
  - app/Domain/Reports/Enums/ReportExportStatus.php
  - app/Domain/Reports/Exports/SpreadsheetCells.php
  - app/Domain/Reports/FixedPriceSummary.php
  - app/Domain/Reports/Jobs/BuildReportExport.php
  - app/Domain/Reports/Jobs/PruneExpiredReportExports.php
  - app/Domain/Reports/Models/ReportExport.php
  - app/Domain/Reports/Notifications/ReportExportFailedNotification.php
  - app/Domain/Reports/Notifications/ReportExportReadyNotification.php
  - app/Domain/Reports/Queries/TimeReportReader.php
  - app/Domain/Reports/ReportBucket.php
  - app/Domain/Reports/ReportEntry.php
  - app/Domain/Reports/ReportFilters.php
  - app/Domain/Reports/TimeReport.php
  - app/Domain/Reports/WorkReport/WorkReportPdf.php
  - app/Domain/Reports/WorkReport/WorkReportSource.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Money/Money.php
  - app/Filament/Pages/Dashboard.php
  - app/Filament/Pages/TimeReportPage.php
  - app/Filament/Resources/ExchangeRateResource.php
  - app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php
  - app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php
  - app/Filament/Resources/ProjectResource/Widgets/ProjectTimeStats.php
  - app/Filament/Resources/ReportExportResource.php
  - app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php
  - app/Filament/Widgets/HoursStats.php
  - app/Filament/Widgets/PartnerProjects.php
  - app/Filament/Widgets/PartnerTasks.php
  - app/Filament/Widgets/UnbilledPerClient.php
  - app/Filament/Widgets/UpcomingDeadlines.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/OperationsServiceProvider.php
  - composer.json
  - config/kokpit.php
  - config/services.php
  - database/migrations/2026_10_15_000100_create_exchange_rates_table.php
  - database/migrations/2026_10_15_000200_create_report_exports_table.php
  - lang/cs/enums.php
  - lang/cs/kokpit.php
  - resources/views/filament/pages/system-page.blade.php
  - resources/views/filament/pages/time-report.blade.php
  - resources/views/filament/widgets/partner-tasks.blade.php
  - resources/views/filament/widgets/unbilled-per-client-cell.blade.php
  - resources/views/filament/widgets/unbilled-per-client-footer.blade.php
  - resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
  - resources/views/pdf/work-report.blade.php
  - routes/console.php
  - scripts/tests/test-gitignore.sh
findings:
  critical: 1
  warning: 3
  info: 9
  total: 13
status: issues_found
---

# Phase 08: Code Review Report

**Reviewed:** 2026-10-15T12:00:00Z
**Depth:** standard
**Files Reviewed:** 69 source files (the 38 test files were used as evidence only)
**Status:** issues_found

## Summary

Phase 8 is carefully built. The areas listed as focus points held up under adversarial reading:

- **Partner isolation:** every Admin widget, page and resource carries `EnforcesWidgetAccessRule` or its page and resource equivalent. The Partner widgets read only through the scoped `Task` and `Project` models with pinned field lists. `TimeReportReader`, `FixedPriceSummary` and `WorkReportSource` refuse everyone but the Admin and the system context. `ReportExport` is owner-scoped, and both the download and the job re-check the record. No leak path was found.
- **Money:** a single `fromExactMinor` rounding point, per-unit CNB rates divided by the unit amount, no float anywhere in a rate path, and Prague-day boundaries built from half-open UTC instants. I re-derived the 100 EUR / 61 s / 25.125 worked example (42.57 CZK) by hand and it matches. The SQL billing chain matches `TaskBillingResolver` and `TimeEntryRateResolver`.
- **Export safety:** all text cells are explicit string cells. The storage path is server-generated and pinned by a CHECK constraint. The download name is built from validated days only.
- **Queries:** the report reader and `RateBook` use a constant number of queries. I found no N+1.

One provable defect remains where the report code depends on an invariant that nothing in the application guarantees. It can take down the Admin dashboard. There are also three robustness defects in the export retention, the rates alert and the export record. The Info items are small.

`<structural_findings>` and `<external_reviewer_evidence>` were not supplied for this run.

## Critical Issues

### CR-01: A client with money in two currencies crashes the report, the dashboard widget and the exports

**File:** `app/Domain/Reports/ReportBucket.php:83-85`, `app/Domain/Reports/TimeReport.php:110-126`, `app/Filament/Widgets/UnbilledPerClient.php:200-202`. The root cause is in `app/Domain/Clients/Actions/UpdateClient.php:43` and `app/Domain/Projects/Models/ProjectBilling.php:78-89`, both outside this phase's file list.

**Issue:** The report code assumes "a client has one currency". `ReportBucket::add()` and `UnbilledPerClient::compute()` add every entry amount of a client with `Money::plus()`. `plus()` throws `InvalidArgumentException('Money in different currencies cannot be combined.')` when the currencies differ.

The invariant is only partly enforced. `ProjectBilling::clientHoldsMoney()` locks a client's currency while some project billing row holds an hourly rate or a fixed price. It does not look at `task_billing`, whose `hourly_rate_minor` and `hourly_rate_currency` also hold money.

The failing sequence is reachable from the UI:

1. The client has currency EUR and a client rate. No project holds money. A task gets its own hourly rate in EUR.
2. The Admin edits the client to CZK with a new client rate. `UpdateClient` allows it because `clientHoldsMoney()` is false.
3. The task keeps its EUR rate. The SQL in `TimeReportReader` (`RATE_CURRENCY_SQL`) resolves the currency from the same level as the rate, so entries on that task are priced in EUR and entries on other tasks in CZK. Both belong to the same client bucket.
4. `ReportBucket::add()` throws on the first mixed pair. Three places fail:
   - The time report page returns a 500.
   - The `UnbilledPerClient` widget throws while the Admin dashboard renders (`$isLazy = false`), so the Admin's landing page returns a 500.
   - `BuildReportExport` fails on every attempt. The CSV path does not sum, but the XLSX summary and `TimeReport` do.

The data does not heal by itself. The Admin has to guess that a task rate must be edited.

**Fix:** Close the root cause and make the consumers defensive.

1. Extend the currency lock to task money:
```php
// ProjectBilling::clientHoldsMoney() (or a new TaskBilling::clientHoldsMoney() called from UpdateClient)
return DB::table('task_billing')
    ->join('tasks', 'tasks.id', '=', 'task_billing.task_id')
    ->join('projects', 'projects.id', '=', 'tasks.project_id')
    ->where('projects.client_id', $clientId)
    ->where(fn ($q) => $q->whereNotNull('hourly_rate_minor')->orWhereNotNull('fixed_price_minor'))
    ->exists();
```
   Include archived tasks, as the project lock does.

2. Make the buckets currency-safe. Either key `ReportBucket::$amount` by currency (`array<string, Money>`) and show one line per currency, or skip summing when the currency differs and show the "more currencies" marker. At the very least, catch the mismatch and render it as a visible marker rather than letting it propagate.

3. Add a test: a client with a CZK client rate and one task with an EUR task rate must render the report and the dashboard widget.

## Warnings

### WR-01: A report file whose record ends `failed` or `pending` is never deleted from the private disk

**File:** `app/Domain/Reports/Jobs/PruneExpiredReportExports.php:35-37`, `app/Domain/Reports/Jobs/BuildReportExport.php:134-156`

**Issue:** `BuildReportExport` stores the file at `report-exports/<id>.<ext>` (line 134) and only afterwards marks the record ready in a transaction that also sends the notification (lines 141-156). If that transaction fails on every attempt, for example because the notification insert throws, `failed()` marks the record `failed` and `path` stays NULL.

`PruneExpiredReportExports` deletes only `$export->path`, which is NULL for that record. When the record expires it is deleted and the file stays in the bucket permanently. It holds measured time and money data, and nothing references it any more.

The same happens if the transaction finds the record no longer pending (line 144) after the file was written. The test at `ReportExportPruneTest.php:80` covers a failed record without a file, not a failed record with an orphan file.

**Fix:** The storage path is derived only from the id and format, so the prune can always delete it safely:
```php
foreach ($exports as $export) {
    $disk->delete($export->storagePath());   // idempotent; a missing file is not an error
    $export->delete();
}
```
Optionally also delete the file in `BuildReportExport::failed()`.

### WR-02: A failed gap heal after a successful daily download raises a false "today's rates could not be downloaded" alert

**File:** `app/Console/Commands/DownloadCnbRatesCommand.php:56-76`

**Issue:** The daily step and the heal share one `try` block. When the daily list is stored (line 59) and `HealCnbRateGaps` then fails on the year endpoint (line 61), the `catch (CnbDownloadFailed)` branch runs. On the `--final` run it calls `alertAdmin($today)` and returns FAILURE.

The alert body reads "Today's CNB rates (:date) could not be downloaded even after repeated attempts. The last known rates from :last are used." Here `:last` is today, because today's list is already stored. The Admin is told that rates are missing when they are not, and the real problem (a gap that could not be healed) is not named.

The heal claim `kokpit:cnb-gap-heal:<day>` is already consumed, so the failure is not retried that day. Once consumed, nothing flags the failed heal again.

**Fix:** Run the heal in its own `try`. A heal failure should warn, set a failing exit code, and use its own alert copy (for example "a gap in the rate history could not be filled"). Alert about today's list only when the daily step itself failed:
```php
$result = ...->handle($today);              // failure here alerts as today
try {
    $healed = ...->handle($today);
} catch (CnbDownloadFailed $failure) {
    $this->components->warn(__('kokpit.rates.console.heal_failed', [...]));
    $healFailed = true;                     // no "today missing" alert
}
```

### WR-03: A legal client and project name can make the export request fail with a database error

**File:** `app/Filament/Pages/TimeReportPage.php:140-144` and `215-226`, `database/migrations/2026_10_15_000200_create_report_exports_table.php` (`summary` is `string(255)`)

**Issue:** `exportSummary()` joins the client name, the project name and the period into one string stored in `report_exports.summary`, a `varchar(255)`. Client and project names are each allowed up to 255 characters (`clients.name` and `projects.name` are `string(255)`), so schema-valid names can overflow. The `create()` call then throws a `QueryException` (value too long) in a Livewire action. The Admin gets a generic error and no export is queued.

Names from ARES are often long, so this is plausible with a long legal name plus a descriptive project name.

**Fix:** Truncate before storing, with the same ellipsis rule the page uses elsewhere, or widen the column:
```php
$summary = Str::limit(implode(', ', [...]), 255, '…');
```
Alternatively change `summary` to `text` in the migration. The migration is new in this phase, so no data migration is needed.

## Info

### IN-01: The leading-apostrophe neutralisation also alters legitimate text in the XLSX

**File:** `app/Domain/Reports/Exports/SpreadsheetCells.php:37-47`, `app/Domain/Reports/Jobs/BuildReportExport.php:417-420`

`text()` prefixes `'` for `= + - @ TAB CR`. That is the right defence for CSV. An OpenSpout XLSX `StringCell` is written as a string type and is never evaluated, so a name such as `-Acme` shows as `'-Acme` in Excel. The same applies to a client or project name that starts with `-` or `+`.

**Fix:** Keep the prefix for the CSV writer only, and give the XLSX path a variant that returns a plain `StringCell`. The formula-free XLSX test still holds.

### IN-02: An upper-case UUID in the URL filters correctly but the select shows "all clients"

**File:** `app/Domain/Reports/ReportFilters.php:210-229`, `resources/views/filament/pages/time-report.blade.php:94` and `109`

`Str::isUuid()` accepts upper case. PostgreSQL compares `uuid` values case-insensitively, so the filter works. `ReportFilters` keeps the id as typed, though, and the view compares with `===` against the lower-case id, so no option is selected.

**Fix:** Return `strtolower($id)` from `existingClient()` and `projectOf()`.

### IN-03: `TimeReportPage::filters()` revalidates against the database on every call

**File:** `app/Filament/Pages/TimeReportPage.php:115`, `138`, `165`, `252-262`, `277`, `269`

Each call runs `existingClient` and `projectOf` (two `exists` queries when a client is chosen). One render calls it several times: the header action `visible` closure, `getViewData` and `report()`. The cost is constant and small, but it is avoidable.

**Fix:** Memoise per request in a private property, keyed on the input array. Reset it in `mount()`.

### IN-04: Work report rows can disagree with their day subtotal

**File:** `app/Domain/Reports/WorkReport/WorkReportPdf.php:119-156`, `app/Domain/TimeTracking/Support/DurationFormat.php`

`DurationFormat::hoursMinutes()` truncates to whole minutes. The task rows are formatted one by one while the day subtotal is formed from seconds, so the printed rows can sum to less than the printed subtotal (three rows of 0:59 against a 2:57 total). This is documented as the app-wide rule for the Admin screens. On a document handed to a client it invites questions.

**Fix:** Either accept it and note it in the PDF footer, or print `H:MM` rounded to the nearest minute at row level, with the rounding remainder carried to the subtotal.

### IN-05: The backfill job restarts from the first year on every attempt and has no progress memory

**File:** `app/Domain/ExchangeRates/Jobs/BackfillCnbRates.php:113-123`

With the 3660-day span limit, a backfill can request about 11 years. One year costs up to 3 attempts of 15 s plus backoff, about 45 s in a degraded-CNB worst case, which is over the 240 s timeout. A retry downloads every year again even though earlier years are already stored. The job stays idempotent, but a slow CNB can exhaust the three tries without finishing.

**Fix:** Skip a year when the year endpoint's days are already all stored, or re-dispatch one job per year. This is not urgent under normal CNB response times.

### IN-06: The holiday calendar applies Dec 24 for years before 2016

**File:** `app/Domain/ExchangeRates/CzechPublicHolidays.php:29-31`

`12-24` is in `FIXED_DAYS` for every year, although it became a public holiday in 2016 (Good Friday is gated by `GOOD_FRIDAY_FROM_YEAR`, Christmas Eve is not). The recent window, the health slot and the console command are unaffected. A backfilled history before 2016 that is later healed or evaluated for gaps would treat Dec 24 as a non-publication day.

**Fix:** Gate `12-24` by year the same way as Good Friday.

### IN-07: `ExchangeRateConverter` has no production caller and its arithmetic is duplicated

**File:** `app/Domain/ExchangeRates/ExchangeRateConverter.php:33-82`, `app/Domain/Reports/FixedPriceSummary.php:101-110`

The only references in `app/` are a docblock link in `FixedPriceSummary`, which re-implements the same lookup and conversion through `RateBook` and `Money::convert`. The class is a service kept for Phase 10 (EX-03). Each `rateFor()` call runs two queries, so a later caller that loops per line would produce an N+1 pattern. The docblock does not warn about this.

**Fix:** Mention in the class docblock that per-line callers must use `RateBook`. Optionally let `FixedPriceSummary` and the converter share one conversion helper.

### IN-08: The prune ignores the result of a failed delete on a disk that does not throw

**File:** `app/Domain/Reports/Jobs/PruneExpiredReportExports.php:34-39`, `config/filesystems.php:39,48`

The comment says a record whose file could not be deleted stays and is retried. The code deletes the record unconditionally. The `s3` disk is configured with `throw => true`, so production behaves as the comment says. The local and public disks have `throw => false`, so there a failed delete silently drops the record.

**Fix:** Check the return value of `$disk->delete()`, or fold into WR-01's fix with `if ($disk->delete(...) === false) { continue; }`.

### IN-09: A non-retryable validation failure in the export job is retried three times

**File:** `app/Domain/Reports/Jobs/BuildReportExport.php:115`

`ReportFilters::fromStored()` throws `InvalidArgumentException` when a stored client or project no longer exists. That error cannot succeed on retry, but the job sleeps through the backoffs of 10 s, 60 s and 300 s before `failed()` runs.

**Fix:** Catch it in `handle()` and call `$this->fail($e)`, which goes straight to `failed()`.

---

_Reviewed: 2026-10-15T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
