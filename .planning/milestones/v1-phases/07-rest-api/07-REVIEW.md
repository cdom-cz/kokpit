---
phase: 07-rest-api
reviewed: 2026-10-14T00:00:00Z
depth: standard
files_reviewed: 31
files_reviewed_list:
  - CONTRIBUTING.md
  - app/Domain/Api/ReferenceResolver.php
  - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Support/PragueDay.php
  - app/Domain/TimeTracking/Support/TimerClock.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Http/Controllers/Api/V1/ApiExamples.php
  - app/Http/Controllers/Api/V1/ClientController.php
  - app/Http/Controllers/Api/V1/ProjectController.php
  - app/Http/Controllers/Api/V1/TaskController.php
  - app/Http/Controllers/Api/V1/TimeEntryBatchController.php
  - app/Http/Controllers/Api/V1/TimeEntryController.php
  - app/Http/Requests/Api/V1/ApiCursor.php
  - app/Http/Requests/Api/V1/ListLookupsRequest.php
  - app/Http/Requests/Api/V1/ListTimeEntriesRequest.php
  - app/Http/Requests/Api/V1/TimeEntryPayload.php
  - config/scribe.php
  - lang/cs/api.php
  - lang/cs/kokpit.php
  - tests/Feature/Api/ApiDocumentationTest.php
  - tests/Feature/Api/LookupTest.php
  - tests/Feature/Api/TimeEntryBatchTest.php
  - tests/Feature/Api/TimeEntryContractTest.php
  - tests/Feature/Api/TimeEntryListTest.php
  - tests/Feature/Api/TimeEntryWriteTest.php
  - tests/Feature/TimeTracking/PragueDayTest.php
  - tests/Feature/TimeTracking/TimeEntryActionsTest.php
  - tests/Feature/TimeTracking/TimeEntryListTest.php
  - tests/Isolation/ApiAccessTest.php
  - tests/Support/ApiDocs.php
findings:
  critical: 0
  warning: 3
  info: 4
  total: 7
status: issues_found
---

# Phase 7: Code Review Report

**Reviewed:** 2026-10-14
**Depth:** standard
**Files Reviewed:** 31
**Status:** issues_found

## Summary

Scope: the gap-closure changes of plans 07-13 to 07-18 since commit 9f345e4 (cursor validation, storable-year and
span guards, batch fault mapping, trailing-line-feed key patterns, documentation limits). I read every listed source
file in full and the diffs of the tests, and checked the cursor key shape against the vendored Laravel paginator.
`scripts/check-sensitive.sh` is clean for the reviewed files. No tests were run (the API documentation tests rewrite
the generated docs output).

The new work is largely sound. `ApiCursor` mirrors `Cursor::fromEncoded()` decoding and the cursor keys
(`started_at`, `time_entries.id`, ...) match what the paginator writes. The tables use `timestampTz` with the default
precision of 0 (`Schema\Builder::$defaultTimePrecision = 0`), so a whole-second cursor value cannot skip rows. The
storable-year check and `PragueDay` bounds are consistent with each other. No security issue was found (the cursor
only ever reaches the database as a bound parameter, and only after it has passed the token and ability middleware).

Three real defects remain, none of them a security problem: an uppercase UUID is refused as an inconsistent context,
the running-entry span guard is only a snapshot, and a batch where every item hits a server fault answers with a 422.

## Warnings

### WR-01: An uppercase UUID is accepted by validation but refused as `inconsistent_context`

**File:** `app/Domain/TimeTracking/TimeEntryInput.php:82` and `:91` (also `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php:126`)
**Issue:** `TimeEntryInput::id()` and the API validation (`Str::isUuid`, which accepts `A-F`) let an uppercase UUID
through unchanged. `context()` then compares ids with strict string inequality against the lowercase keys PostgreSQL
returns: `$projectId !== $project->getKey()` (line 82) and `$clientId !== $project->client_id` (line 91). An API client
that sends `{"task_id": "<task uuid>", "project_id": "<PROJECT UUID IN UPPERCASE>"}`, or an uppercase `client_id` together
with a project or task, gets a spurious 422 `inconsistent_context` for a perfectly consistent request. Every query by
`whereKey()` on the same value works, so the same id is both valid and inconsistent depending on the code path.
`UpdateTimeEntry::contextChanged()` has the same flaw: an uppercase echo of the stored id counts as a change and then
fails the comparison in `context()`. UUID text is case-insensitive per RFC 4122; `TimeEntryInput::uuids()` already
lowercases for exactly this reason, but `id()` does not.
**Fix:** Normalise in `id()` so every comparison sees one form.
```php
return strtolower($value);
```
and in `UpdateTimeEntry::id()` compare `is_string($value) ? strtolower($value) : $value`. Add one API test with an
uppercase `project_id` next to a `task_id`.

### WR-02: The running-entry span guard is a snapshot, so an accepted start can break the entry list later

**File:** `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php:191-195` (consumer: `app/Domain/TimeTracking/Models/TimeEntry.php:111`)
**Issue:** The new guard refuses a changed start of a running entry only when `abs(now - start) > 2147483647` at the
moment of the edit. The consumer it protects, `EXTRACT(EPOCH FROM (now - started_at))::int`, is evaluated on every
list read for as long as the entry keeps running. A start that is accepted with a few seconds of margin (the test
`bounds the start of a running entry ... exactly at the column limit` pins an exactly-at-the-limit start) pushes
the elapsed seconds over the int range one second later, and from then on every `withElapsedSeconds()` query fails with
SQLSTATE 22003: the panel time-entry list is dead until someone edits the row in the database. The docblock claims the
list stays readable; it only does so at the instant of the check. The window is narrow (a typo year about 68 years ago),
but the failure mode is a hard outage of a core screen, and an Admin typo such as `1958` for `2026` is plausible.
**Fix:** Remove the dependency on the margin rather than shrinking it: make the SQL cast safe, so no stored start can
overflow.
```php
'COALESCE(time_entries.duration_seconds, LEAST(2147483647, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - time_entries.started_at))::bigint)))::int AS elapsed_seconds'
```
(the `::bigint` cast cannot overflow for years 1 to 9999). The `assertDurationFits` guard on running entries can then
stay as a sanity bound, and the test should freeze time and advance it by one second after saving the limit start.

### WR-03: A batch in which every item meets a server fault answers 422 instead of a server error

**File:** `app/Http/Controllers/Api/V1/TimeEntryBatchController.php:101-105`
**Issue:** The new `catch (Throwable)` correctly turns a late fault into a failed item so that a 207 protects the
Idempotency-Key once some items were committed. But when `$saved === 0` the response is the 422 `batch_none_saved`
for every mix of failures, including a batch where all items failed because of the server (database unreachable,
a deadlock storm, a failing observer). The client then sees a validation-style 4xx with the message "no item could be
saved" and a per-item text blaming `entry`; HTTP clients and retry middleware treat 4xx as a permanent client error and
do not retry, and monitoring that watches 5xx never sees the outage (only the `report()` log line does). Nothing was
saved in this case, so a 500/503 is safe: the key is released exactly as the 422 does today, and the retry is correct.
**Fix:** Track faults separately and answer 500 (or 503) when nothing was saved and at least one item faulted.
```php
$faulted = false; // set to true in the Throwable catch
...
if ($saved === 0 && $faulted) {
    return new JsonResponse(['message' => __('api.errors.batch_item_failed'), ...$counts], 500);
}
```
Update the `422` documented in the endpoint and the test `answers 422 and releases the key when every item meets a
fault of the server` accordingly.

## Info

### IN-01: The trailing-line-feed `D` modifier is applied to the key patterns only

**File:** `app/Http/Requests/Api/V1/TimeEntryPayload.php:41`, `app/Http/Requests/Api/V1/ListTimeEntriesRequest.php:130`, `app/Domain/TimeTracking/TimeEntryInput.php:226,228`
**Issue:** `ReferenceResolver::PROJECT_KEY` and `TASK_REFERENCE` now end with `$/D` so `"DEMO\n"` is refused. The instant
patterns (`TimeEntryPayload::INSTANT`, `ListTimeEntriesRequest::isInstant()`, both regexes in `TimeEntryInput::instant()`)
still use a bare `$`, so `"2026-10-12T10:00:00Z\n"` passes the rule. It is harmless today because `instant()` runs
`trim()` and `TrimStrings` strips the value first, but the defence in depth is applied inconsistently and a reader cannot
tell which pattern is meant to be strict.
**Fix:** Add `D` to the four instant patterns, or state in `ReferenceResolver` why only the keys need it.

### IN-02: Sort, filter and id-column tables are duplicated in the request classes

**File:** `app/Http/Requests/Api/V1/ListTimeEntriesRequest.php:31` and `app/Http/Requests/Api/V1/ListLookupsRequest.php:90,104,118`
**Issue:** `ListTimeEntriesRequest::SORT_VALUES` repeats `TimeEntryController::SORTS` (the controller constant is the
declared single source for the allow-list, the docs and the rules), so the two can drift. `ListLookupsRequest` resolves
the same `match ($this->route()?->getControllerClass())` three times (filters, sorts, id column).
**Fix:** Derive `SORT_VALUES` from `TimeEntryController::SORTS` as `ListLookupsRequest::sortValues()` does, and resolve
the controller class once into a small descriptor (filters, sorts, id column).

### IN-03: Prose placed between `@return` and `@throws` in the `TimeEntryInput::instant()` docblock

**File:** `app/Domain/TimeTracking/TimeEntryInput.php:200-206`
**Issue:** The new paragraph about unstorable years sits after the `@param` / `@return` tags, so PHPDoc and IDEs attach
it to `@return`, and it is not shown in the summary. The same information is already in the `@throws` line.
**Fix:** Move the paragraph above the tags (into the description block) and keep the single `@throws` line.

### IN-04: The documented write endpoints do not mention the span bound

**File:** `app/Http/Controllers/Api/V1/TimeEntryController.php:154,190` and `app/Http/Controllers/Api/V1/TimeEntryBatchController.php:51`
**Issue:** Create, update and batch can now answer 422 `duration_too_long` under `ended_at` (or `started_at` on update)
for an interval above 2147483647 seconds (about 68 years), and `invalid_time` for an instant outside UTC years 1 to 9999.
The endpoint descriptions only state the `duration_seconds` bound; an integrator learns the interval limit from the error.
Also `tests/Feature/Api/TimeEntryWriteTest.php:408-409` asserts the same message twice, once through `__()` and once as a
hard-coded Czech literal, which breaks on any wording change for no extra coverage.
**Fix:** Add one sentence to the three descriptions ("An entry spans at most 2147483647 seconds, and instants lie in the
years 1 to 9999; otherwise 422") and drop the duplicated literal assertion.

---

_Reviewed: 2026-10-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
