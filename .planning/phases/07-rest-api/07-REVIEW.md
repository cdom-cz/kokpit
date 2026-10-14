---
phase: 07-rest-api
reviewed: 2026-10-14T00:00:00Z
depth: standard
files_reviewed: 68
files_reviewed_list:
  - CONTRIBUTING.md
  - app/Domain/Api/Idempotency/IdempotencyStore.php
  - app/Domain/Api/ReferenceResolver.php
  - app/Domain/Identity/Actions/IssueApiToken.php
  - app/Domain/Identity/Actions/RevokeApiToken.php
  - app/Domain/Identity/ApiAbility.php
  - app/Domain/Identity/ApiTokenStatus.php
  - app/Domain/Identity/Models/PersonalAccessToken.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Support/PragueDay.php
  - app/Filament/Resources/ApiTokenResource.php
  - app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php
  - app/Filament/Resources/TimeEntryResource.php
  - app/Filament/Resources/TimeEntryResource/Pages/EditTimeEntry.php
  - app/Http/Controllers/Api/V1/ApiExamples.php
  - app/Http/Controllers/Api/V1/ClientController.php
  - app/Http/Controllers/Api/V1/LookupExamples.php
  - app/Http/Controllers/Api/V1/ProjectController.php
  - app/Http/Controllers/Api/V1/TaskController.php
  - app/Http/Controllers/Api/V1/TimeEntryBatchController.php
  - app/Http/Controllers/Api/V1/TimeEntryController.php
  - app/Http/Middleware/EnsureApiDocsGenerated.php
  - app/Http/Requests/Api/V1/BatchStoreTimeEntryRequest.php
  - app/Http/Requests/Api/V1/ListLookupsRequest.php
  - app/Http/Requests/Api/V1/ListTimeEntriesRequest.php
  - app/Http/Requests/Api/V1/UpdateTimeEntryRequest.php
  - app/Http/Resources/Api/V1/ClientResource.php
  - app/Http/Resources/Api/V1/ProjectResource.php
  - app/Http/Resources/Api/V1/TimeEntryResource.php
  - app/Http/Responses/ApiError.php
  - app/Providers/ApiServiceProvider.php
  - bootstrap/app.php
  - bootstrap/providers.php
  - config/kokpit.php
  - config/sanctum.php
  - config/scribe.php
  - lang/cs/api.php
  - lang/cs/kokpit.php
  - routes/api.php
  - tests/Concurrency/IdempotencyConcurrencyTest.php
  - tests/Concurrency/idempotency-worker.php
  - tests/Feature/Api/ApiActivitySourceTest.php
  - tests/Feature/Api/ApiDocumentationAccessTest.php
  - tests/Feature/Api/ApiDocumentationHygieneTest.php
  - tests/Feature/Api/ApiDocumentationTest.php
  - tests/Feature/Api/ApiTokenScreenTest.php
  - tests/Feature/Api/AuthenticationTest.php
  - tests/Feature/Api/IdempotencyTest.php
  - tests/Feature/Api/IssueApiTokenTest.php
  - tests/Feature/Api/LookupTest.php
  - tests/Feature/Api/RateLimitTest.php
  - tests/Feature/Api/TimeEntryBatchTest.php
  - tests/Feature/Api/TimeEntryContractTest.php
  - tests/Feature/Api/TimeEntryExternalTest.php
  - tests/Feature/Api/TimeEntryListTest.php
  - tests/Feature/Api/TimeEntryWriteTest.php
  - tests/Feature/Operations/ActivitySourceTest.php
  - tests/Feature/Operations/ActivityViewsTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/TimeEntriesTableTest.php
  - tests/Feature/TimeTracking/PragueDayTest.php
  - tests/Isolation/ApiAccessTest.php
  - tests/Support/ApiDocs.php
  - tests/Support/ApiFixtures.php
  - tests/Support/Probes/BrokenDocsProbeController.php
  - tests/Support/TimeEntryContract.php
  - tests/Support/UnclaimedIdempotencyStore.php
  - tests/Support/scribe-broken-route.php
findings:
  critical: 0
  warning: 3
  info: 4
  total: 7
status: issues_found
---

# Phase 7: Code Review Report

**Reviewed:** 2026-10-14T00:00:00Z
**Depth:** standard
**Files Reviewed:** 68
**Status:** issues_found

## Summary

The REST API layer is, on the whole, carefully built. Authentication is a single choke point (`auth:sanctum` with the active-Admin callback, empty Sanctum `guard`, so a panel session never authenticates). Every route sits in exactly one of two groups that carry an ability and a rate limit, and a route-walk test enforces it. Resources use explicit allow-lists. The idempotency claim is one atomic `INSERT ... ON CONFLICT DO NOTHING`, with ownership checked on `complete` and `release`. The token screen throttles the identity confirmation per user, fails closed without an authenticator secret, and prevents TOTP reuse. No hardcoded secrets or real values were found, and `scripts/check-sensitive.sh` is clean over the whole file list.

The defects found are all of one kind: input that the API accepts as well-formed but that PostgreSQL or the Eloquent layer later refuses, so an authenticated token holder gets an unhandled 500 instead of a 422. They were checked against the live DDEV database and HTTP kernel inside rolled-back transactions. There are no BLOCKERs: none of them leaks data or crosses a trust boundary. Two of them (WR-01 running-entry variant, WR-02 batch amplification) can leave the Admin's own data in a state that is painful to recover from, so they should not be left for long.

Verified and rejected during the review (no finding): the `{id}` URL parameter name in the Scribe attributes (Scribe normalises `{client}`/`{project}`/`{task}` to `{id}` and a test pins it), comma/percent handling in `filter[name]` (covered by `LookupTest`), the trusted-proxy fallback of the docs limiter (Laravel's `'*'` trusts only the calling proxy, so the forwarded address is not client-spoofable), and the `catch (Throwable)` in `EnsureIdempotency` (Laravel's routing pipeline converts exceptions to responses, so the non-2xx `release` branch is the one that actually runs; both paths release).

## Warnings

### WR-01: A time entry's interval is not bounded on update; a far-past start gives a 500 (T-07-26) and poisons a running entry

**File:** `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php:159-182`, `app/Http/Requests/Api/V1/TimeEntryPayload.php:174-183`
**Issue:** The `MAX_DURATION_SECONDS` guard exists only in `TimeEntryPayload::end()`, and `forUpdate()` calls `end()` only when `ended_at` or `duration_seconds` is sent. This leaves two holes.

1. (Known, T-07-26.) `PATCH {"started_at": "<more than 68 years ago>"}` on a finished entry passes `assertEndAfterStart` (the start is earlier, so the check passes). The save then overflows the generated `duration_seconds integer` column (`22003 integer out of range`), which is not KP001 and is rethrown, so the client gets a 500. The panel can reach the same state, because `applyTimes` has no bound either: the picker sends both instants and nothing checks the span.
2. (Not in the audit, found during this review.) A running entry may have its start changed (documented, and `lets a running entry change ... start` tests it), and the `! $locked->isRunning()` condition at line 177 skips every check for it. A start more than 2^31 seconds in the past is stored. I confirmed on the dev database that `EXTRACT(EPOCH FROM (now() - '1900-01-01'::timestamptz))::int` raises `integer out of range`. That expression is `TimeEntry::scopeWithElapsedSeconds`, which `TimeEntryResource::getEloquentQuery()` applies to the panel entry list (and, through the timer, to the pill and side panel). So one PATCH can make the Admin's time entry list fail, and `StopTimer` fails too because the stored `duration_seconds` overflows. Recovery needs another PATCH or SQL.

**Fix:** Enforce the span in the Action, which both the panel and the API share, and move the constant next to the input rules.

```php
// TimeEntryInput
public const int MAX_DURATION_SECONDS = 2147483647;

public static function assertDurationFits(CarbonImmutable $start, ?CarbonImmutable $end, ?CarbonImmutable $now = null): void
{
    $to = $end ?? $now;  // a running entry is measured against the clock

    if ($to !== null && $to->getTimestamp() - $start->getTimestamp() > self::MAX_DURATION_SECONDS) {
        throw self::error($end === null ? 'started_at' : 'ended_at', 'duration_too_long');
    }
}

// UpdateTimeEntry::applyTimes, before forceFill()
TimeEntryInput::assertDurationFits($start, $locked->isRunning() ? null : $end, TimerClock::now());
```

Keep the existing `TimeEntryPayload::end()` check, or delegate it to the same method. Add tests for `PATCH {started_at: "1900-01-01T00:00:00Z"}` on a finished entry and on a running entry (expect 422 under `started_at`).

### WR-02: Instants whose UTC value falls outside years 1 to 9999 give a 500 on writes and list filters; in a batch this also releases the Idempotency-Key after earlier items were saved

**File:** `app/Http/Requests/Api/V1/TimeEntryPayload.php:41`, `app/Http/Requests/Api/V1/ListTimeEntriesRequest.php:115-123`, `app/Domain/TimeTracking/Support/PragueDay.php:42-71`, `app/Http/Controllers/Api/V1/TimeEntryBatchController.php:75-84`
**Issue:** `INSTANT` rejects the literal year `0000` (`(?!0000)`) but accepts any other four-digit year with any offset, and the conversion to UTC is never range-checked. I reproduced these through the real HTTP kernel (rolled-back transaction, dev database):

- `POST /time-entries` with `started_at: 0001-01-01T00:00:00+02:00`, `duration_seconds: 1` gives 500, `SQLSTATE[22008] date/time field value out of range: "0000-12-31 22:00:00"`. The same happens for `9999-12-31T23:59:59-12:00` (UTC year 10000), which gives 500 `DateMalformedStringException` from Carbon, which cannot parse a five-digit year.
- `GET /time-entries?filter[from]=0001-01-01` gives 500: `PragueDay::start()` returns the Prague midnight (`0000-12-31 23:02:16+00:00` under the historic offset), which PostgreSQL rejects. `filter[from]=0000-01-01T00:00:00Z` and instants near the upper bound fail the same way, because `isInstant()` has no `(?!0000)` guard at all. The panel's custom period filter shares `PragueDay` and has the same hole.
- Batch: `TimeEntryBatchController` only catches `ValidationException` per item and runs each item in its own transaction. One item of this kind yields a 500 after the items before it are committed, and `EnsureIdempotency` releases the key on any non-2xx. A client that retries with the same key (the behaviour the endpoint advertises) therefore saves the earlier items a second time. The docs warn about "the server fails in the middle of a batch", but here a client-supplied value triggers it, so a single bad item defeats the idempotency guarantee.

`TimeEntryWriteTest` line 406 covers only the literal `0000` case, so none of this is tested.

**Fix:** Range-check the normalised UTC instant in one place and let it raise the usual `invalid_time` 422.

```php
// TimeEntryInput::normalised(CarbonInterface $value, string $field)
$utc = TimerClock::truncate($value)->utc();

if ($utc->year < 1 || $utc->year > 9999) {
    throw self::error($field, 'invalid_time');
}
```

In `PragueDay::midnight()` and `PragueDay::start()`/`endExclusive()`, return null or throw when the UTC result is outside the range (and have `day()` or `dayOrInstant()` reject such days), so a lookup filter answers 422. In the batch controller, consider also mapping a failed item that is not a `ValidationException` to an `errors` entry instead of aborting the request, so a late fault cannot release the key after partial commits. Add a regression test for the year-1-with-positive-offset and year-9999-with-negative-offset cases on create, update and both filters.

### WR-03: A well-formed but foreign or hand-edited `cursor` gives a 500

**File:** `app/Http/Requests/Api/V1/ListTimeEntriesRequest.php:60`, `app/Http/Requests/Api/V1/ListLookupsRequest.php:45` (rule `cursor` is only `string|max:1024`), consumed at `TimeEntryController.php:112`, `ClientController.php:65`, `ProjectController.php:70`, `TaskController.php:77`
**Issue:** Laravel's `Cursor::fromEncoded()` accepts any base64url JSON object that contains `_pointsToNextItems`; it does not check the parameter names or values. I sent `cursor=` the encoding of `{"_pointsToNextItems":true}` to `GET /time-entries` (rolled-back transaction) and got 500, `UnexpectedValueException: Unable to find parameter [started_at] in pagination item`. A cursor with the right keys but a wrong value (`"time_entries.id":"x"`, `"started_at":"x"`) reaches the query as a bound parameter against a `uuid` or `timestamptz` column and gives `22P02`/`22007`, also a 500. The same happens when the cursor of one endpoint is replayed on another (a time entry cursor on `/clients`), or on the same endpoint with a different `sort`. The example cursor published in `ApiExamples::TIME_ENTRY_LIST` decodes to `{"time_entries.id":"0199e3a1-6c8d","_pointsToNextItems":true}`, which is exactly such a cursor, so an integrator who pastes it gets a 500. Every other malformed input of the list endpoints is a clean 422 by design; the cursor is the one gap.
**Fix:** Validate the decoded cursor against the expected columns, in the form request.

```php
'cursor' => ['sometimes', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
    $cursor = is_string($value) ? \Illuminate\Pagination\Cursor::fromEncoded($value) : null;
    $expected = $this->cursorColumns();             // e.g. ['started_at', 'time_entries.id'] for the effective sort
    $parameters = $cursor?->toArray() ?? [];

    foreach ($expected as $column) {
        if (! array_key_exists($column, $parameters) || ! is_string($parameters[$column]) || ! $this->cursorValueFits($column, $parameters[$column])) {
            $fail(__('validation.regex', ['attribute' => $attribute]));
            return;
        }
    }
}],
```

where `cursorValueFits` checks a uuid for the id column and a parseable instant for timestamp columns, and a string for name or title. Replace the example cursor in `ApiExamples` with one that includes every key of the real cursor, so the published sample is usable. Add a test per list endpoint for an empty-parameter cursor and a cursor from another endpoint.

## Info

### IN-01: CONTRIBUTING.md still says "never a bare `orderBy('name')`", while the API sorts by the plain column

**File:** `CONTRIBUTING.md:73`, `app/Http/Controllers/Api/V1/ClientController.php:42`, `ProjectController.php:43`, `TaskController.php:44`
**Issue:** The Ordering convention requires `CzechCollation::orderBy()` for any text sorted for people. The three lookup lists allow `sort=name` and `sort=title` on the database default collation, which is `en_US.utf8` on the dev database. Verified there: `Čapek` sorts before `Cyril` (Czech order puts it after). The phase summaries record this as a deliberate choice ("stable machine order with an id tiebreaker"), and a collated cursor comparison would need the same `COLLATE` in the keyset predicate, so it is not a defect to fix in code. But the convention text was updated for the API in this same diff (Audit, REST API sections) without an exception here, so the rule as written is now violated by shipped code.
**Fix:** Add one sentence to the Ordering bullet: the API sorts by the plain column on purpose, and a cursor cannot use `CzechCollation` without collating the keyset predicate. State the same in the `sort` parameter description of the three lookup lists so integrators do not expect Czech order.

### IN-02: Hard-coded numbers in the Scribe intro duplicate `config/kokpit.php`

**File:** `config/scribe.php` (`intro_text`, "Rate limits" and "Lists and pagination"), `config/kokpit.php:140-156`
**Issue:** The intro states 120 read and 60 write requests a minute, a page size of 50, a maximum of 200, 100 batch items and a 24-hour key lifetime as literals. `config/kokpit.php` is where these live ("read when the request arrives, so a changed config value applies at once"), and several `#[QueryParam]`/`#[BodyParam]` descriptions repeat them. A change to the config would leave the public documentation wrong until someone edits the strings; the hygiene and contract tests do not compare them.
**Fix:** Build the intro with `config('kokpit.api...')` (a `sprintf` or a heredoc with interpolation), or add a test that asserts the documented limits equal the configured ones.

### IN-03: `PROJECT_KEY` and `TASK_REFERENCE` end with `$`, which also matches before a trailing newline

**File:** `app/Domain/Api/ReferenceResolver.php:23,25`
**Issue:** `preg_match('/^[A-Z]{2,6}$/', "DEMO\n")` returns 1. A key with a trailing line feed passes the validation closures in `ListTimeEntriesRequest`, `ListLookupsRequest` and `TimeEntryPayload`, then resolves to nothing (empty list or a 404, or the Action's own field error on a write). No wrong data results, but the pattern accepts a string that is not a key, and the two request classes document "a well formed key never reaches a query".
**Fix:** Use `\z` or the `D` modifier: `'/^[A-Z]{2,6}$/D'` and `'/^[A-Z]{2,6}-[1-9][0-9]*$/D'`.

### IN-04: The documentation tests regenerate the real docs output with a placeholder host

**File:** `tests/Support/ApiDocs.php:50-65,156-171`
**Issue:** `ensureGenerated()` runs `scribe:generate` once per PHP process with `APP_URL` set to the placeholder host and the output going to the real gitignored paths (`storage/app/private/scribe`, `resources/views/scribe`, `public/vendor/scribe`, `.scribe`). A developer who runs the suite and then opens `/docs` locally sees the placeholder base URL, not the DDEV one, until the generation is run again. A parallel Pest run would also have several processes writing the same files. Neither is a correctness bug, and the suite is not parallel today.
**Fix:** Generate into a scratch directory for the tests (Scribe's `static.output_path` and a test-time `laravel` view path are overridable), or document in the helper that the suite overwrites the local docs and must not be run with `--parallel`.

---

_Reviewed: 2026-10-14T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
