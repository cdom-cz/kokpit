---
phase: 07-rest-api
verified: 2026-10-14T20:00:00Z
status: human_needed
score: 7/7 must-haves verified
covered_files:
  - .planning/phases/07-rest-api/07-01-PLAN.md
  - .planning/phases/07-rest-api/07-01-SUMMARY.md
  - .planning/phases/07-rest-api/07-02-PLAN.md
  - .planning/phases/07-rest-api/07-02-SUMMARY.md
  - .planning/phases/07-rest-api/07-03-PLAN.md
  - .planning/phases/07-rest-api/07-03-SUMMARY.md
  - .planning/phases/07-rest-api/07-04-PLAN.md
  - .planning/phases/07-rest-api/07-04-SUMMARY.md
  - .planning/phases/07-rest-api/07-05-PLAN.md
  - .planning/phases/07-rest-api/07-05-SUMMARY.md
  - .planning/phases/07-rest-api/07-06-PLAN.md
  - .planning/phases/07-rest-api/07-06-SUMMARY.md
  - .planning/phases/07-rest-api/07-07-PLAN.md
  - .planning/phases/07-rest-api/07-07-SUMMARY.md
  - .planning/phases/07-rest-api/07-08-PLAN.md
  - .planning/phases/07-rest-api/07-08-SUMMARY.md
  - .planning/phases/07-rest-api/07-09-PLAN.md
  - .planning/phases/07-rest-api/07-09-SUMMARY.md
  - .planning/phases/07-rest-api/07-10-PLAN.md
  - .planning/phases/07-rest-api/07-10-SUMMARY.md
  - .planning/phases/07-rest-api/07-11-PLAN.md
  - .planning/phases/07-rest-api/07-11-SUMMARY.md
  - .planning/phases/07-rest-api/07-12-PLAN.md
  - .planning/phases/07-rest-api/07-12-SUMMARY.md
  - .planning/phases/07-rest-api/07-13-PLAN.md
  - .planning/phases/07-rest-api/07-13-SUMMARY.md
  - .planning/phases/07-rest-api/07-14-PLAN.md
  - .planning/phases/07-rest-api/07-14-SUMMARY.md
  - .planning/phases/07-rest-api/07-15-PLAN.md
  - .planning/phases/07-rest-api/07-15-SUMMARY.md
  - .planning/phases/07-rest-api/07-16-PLAN.md
  - .planning/phases/07-rest-api/07-16-SUMMARY.md
  - .planning/phases/07-rest-api/07-17-PLAN.md
  - .planning/phases/07-rest-api/07-17-SUMMARY.md
  - .planning/phases/07-rest-api/07-18-PLAN.md
  - .planning/phases/07-rest-api/07-18-SUMMARY.md
  - .planning/phases/07-rest-api/07-19-PLAN.md
  - .planning/phases/07-rest-api/07-19-SUMMARY.md
  - CONTRIBUTING.md
  - app/Domain/Api/ReferenceResolver.php
  - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Models/TimeEntry.php
  - app/Domain/TimeTracking/Queries/TimeTotals.php
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
  - routes/api.php
  - tests/Feature/Api/TimeEntryBatchTest.php
  - tests/Feature/Api/TimeEntryListTest.php
  - tests/Feature/Api/TimeEntryWriteTest.php
  - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
  - tests/Feature/TimeTracking/TimeEntryActionsTest.php
  - tests/Feature/TimeTracking/TimeEntryListTest.php
  - tests/Isolation/ApiAccessTest.php
covered_digest: "v3:sha256:d616e93abe918558e93048ff7a3c302c69a8456337d963a7f590e80061501fb8"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 6/7
  gaps_closed:
    - "G-07-1 / truth 7 / WR-02 of the second review: a running start accepted at the edge of the limit no longer breaks the Admin list, view, edit page, totals or task tab once the clock passes 2147483647 s (plan 07-19)"
  gaps_remaining: []
  regressions: []
behavior_unverified_items: []
human_verification:
  - test: "Public /docs page as a guest."
    expected: "Readable page listing every endpoint with the confirmed time entry contract and example payloads, no Try It Out, noindex."
    why_human: "Visual and wording quality of the generated documentation. 07-UAT.md test 2 was checked by curl only (200, noindex, 12 endpoints, no Try It Out); layout and wording were not judged by eye."
  - test: "Token management screen as Admin (and as Partner)."
    expected: "Create a token with both abilities (password + TOTP), copy it once from the modal, close the modal, revoke it; status badges and Czech labels readable. A Partner does not see the screen."
    why_human: "Visual and UX check; 07-UAT.md test 3 is skipped. The isolation and behavior tests are green but cannot judge presentation."
---

# Phase 7: REST API Verification Report

**Phase Goal:** External tools can read and write time entries (single and batch) and look up clients, projects and tasks through a versioned, token-secured API that applies the same rules as the UI
**Verified:** 2026-10-14T20:00:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap closure plan 07-19 (G-07-1, truth 7)

## Goal Achievement

The phase goal is achieved and no must-have is open. The one item that kept the previous report short of 7/7 (truth 7, the running-entry span guard and the elapsed-seconds integer cast, 07-UAT.md G-07-1, 07-REVIEW.md WR-02) is closed in the code, and this verification reproduced the exact UAT sequence with its own temporary probe (written fresh, not copied from plan 07-19's tests, and deleted afterwards). The status stays `human_needed` only because the two visual checks (the public /docs page and the token management screen) can never be settled by grep or a test run and are still pending in 07-UAT.md.

### Observable Truths

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | SC1: Admin creates and revokes API tokens with `time:read` / `time:write`, a Partner cannot create tokens, a token lacking the ability is refused | VERIFIED | Regression check: `routes/api.php` unchanged since the previous report; `ApiTokenScreenTest`, `IssueApiTokenTest`, `AuthenticationTest`, `tests/Isolation/ApiAccessTest` inside the green directory run below. |
| 2 | SC2: list with filters, create, read, update, delete; the API refuses what the UI refuses | VERIFIED | Regression check: `TimeEntryWriteTest`, `TimeEntryListTest` green. The 07-13 write bound is untouched by 07-19 (`git diff 3f7ed3c..HEAD` over `TimeEntryInput.php` and `UpdateTimeEntry.php` shows docblock and comment changes only; `MAX_DURATION_SECONDS = 2147483647` and `assertDurationFits` code unchanged). |
| 3 | SC3: same Idempotency-Key does not duplicate; a batch saves every valid item and reports only the failed ones | VERIFIED | Regression check: `IdempotencyTest`, `TimeEntryBatchTest`, `IdempotencyConcurrencyTest` green; batch controller not modified by 07-19. |
| 4 | SC4: clients, projects and tasks by UUID, key and name through read-only lookups; task by `KEY-N` | VERIFIED | Regression check: `LookupTest` green; lookup code not modified by 07-19. |
| 5 | SC5: rate-limit response for excess requests; published OpenAPI documentation describes every endpoint | VERIFIED | Regression check: `RateLimitTest`, `ApiDocumentationTest`, `ApiDocumentationAccessTest`, `ApiDocumentationHygieneTest` green. |
| 6 | Plan must-have (07-04, 07-05, T-07-26): every refusal path is a clean 4xx, never a 500; malformed or out-of-range input is a 422 | VERIFIED | Regression check: the guard code (`TimeEntryInput::normalised`, `assertDurationFits`, `PragueDay`, `ApiCursor`) is unchanged in behavior; the 07-13 to 07-18 tests for it are green in the 948-test run. |
| 7 | Plan 07-13 truth 3: a running entry's start is bounded "so the Admin's entry list can never be poisoned" (G-07-1) | VERIFIED | The failure mode is gone. `TimeEntry::elapsedSecondsSql()` (`app/Domain/TimeTracking/Models/TimeEntry.php` lines 111-115) is `COALESCE(<alias>.duration_seconds, LEAST(2147483647, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - <alias>.started_at))::bigint))::int)`: the difference is computed as bigint, floored at 0, capped at `TimeEntryInput::MAX_DURATION_SECONDS` (same constant as the write bound), and only the capped value is cast to int. `scopeWithElapsedSeconds` (line 129) and both `TimeTotals` readers (`aggregate()` line 139 for `time_entries`, `taskSum()` line 168 for `entry`) go through it; `git grep "EXTRACT(EPOCH" -- app` lists only `TimeEntry.php:114`, `git grep "started_at))::int" -- app` and `git grep elapsed_seconds -- app/Http` are empty (no raw int cast left, no wire change). Reproduced independently, see Behavioral Spot-Checks. |

**Score:** 7/7 truths verified (0 present, behavior-unverified; 0 coincidental-reliance)

Truth 7 is behavior-dependent (a time-dependent invariant), so presence was not accepted as proof: the invariant is exercised by a passing behavioral test (plan 07-19's `keeps the entry list readable after a running start accepted at the edge of the limit runs past it (G-07-1)` plus the panel, Action and totals tests listed below) and by this verification's own probe.

### Deferred Items

None. AP-03 (API timer) was removed from this phase on 2026-10-14 and is tracked as ROADMAP backlog item 999.1; it is not a gap of this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Domain/TimeTracking/Models/TimeEntry.php` | `elapsedSecondsSql(alias)`, the one overflow-proof expression; scope uses it | VERIFIED | exists, substantive (bigint, `GREATEST`, `LEAST`, constant from `TimeEntryInput`), wired into the scope and `TimeTotals` |
| `app/Domain/TimeTracking/Queries/TimeTotals.php` | Task and project sums on `TimeEntry::elapsedSecondsSql` for both aliases; private copies gone | VERIFIED | `ELAPSED` and `ELAPSED_OF_ENTRY` constants removed (diff); four bindings for four uses in `aggregate()` unchanged |
| `app/Domain/TimeTracking/TimeEntryInput.php`, `Actions/UpdateTimeEntry.php` | Docblocks no longer claim the write guard keeps the list readable; code unchanged (D-09) | VERIFIED | diff shows docblock/comment lines only |
| `tests/Feature/Api/TimeEntryWriteTest.php` | UAT reproduction through the API | VERIFIED | contains the 2147483645 s PATCH, 4 s advance, clamp assertion, GET 200 |
| `tests/Feature/TimeTracking/TimeEntryListTest.php` | List, view, edit render `596523:14` / `596523:14:07`, exact total beyond the integer range | VERIFIED | green |
| `tests/Feature/TimeTracking/TimeEntryActionsTest.php` | Boundary after one more second, year 1 / year 9999 / 30 minutes dataset, correct-then-stop recovery | VERIFIED | green |
| `tests/Feature/TimeTracking/ProjectTimeOverviewTest.php` | `TimeTotals`, stats row and task tab with a 1900 and a 9999 start | VERIFIED | green |
| Earlier phase artifacts (`routes/api.php`, controllers, `ApiCursor`, `PragueDay`, token screen, idempotency, Scribe docs) | See previous report | VERIFIED | unchanged by 07-19, tests green |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `TimeEntry::elapsedSecondsSql` | `TimeEntryInput::MAX_DURATION_SECONDS` | concatenated constant in the `LEAST` | WIRED | one constant for write bound and read clamp |
| `TimeEntryResource::getEloquentQuery()` (list, view, edit, project entries tab, timesheet) | `TimeEntry::scopeWithElapsedSeconds` | `withElapsedSeconds` scope | WIRED | scope selects `elapsedSecondsSql('time_entries')` |
| `TimeTotals::aggregate()` and `taskSum()` | `TimeEntry::elapsedSecondsSql` | static call, aliases `time_entries` and `entry` | WIRED | `forTask`, `forProject`, `withoutTask`, `taskColumns` read through it |
| Controllers | Domain Actions | `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry` | WIRED | unchanged |
| Route groups | Sanctum, abilities, throttle | middleware arrays | WIRED | unchanged |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Admin entry list, view page (`elapsed_seconds`) | `elapsed_seconds` | `time_entries` row through the clamped SQL expression | Yes (exact below the ceiling, clamped above) | FLOWING |
| Task page, project stats row, task tab (`worked`, `billed`, `unbilled`, `non_billable`) | `TimeTotals` sums | `SUM` of the clamped per-row expression (bigint) | Yes | FLOWING |
| API `TimeEntryResource` | entry fields | Eloquent through Actions; no elapsed field (D-09 one-way contract) | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Control: the OLD cast overflows for the UAT row | temporary probe test via `ddev exec php -d memory_limit=1G vendor/bin/pest`, raw `extract(epoch ...)::int` inside a savepoint after the 4 s advance | SQLSTATE 22003 raised, so the probe truly reproduces G-07-1 | PASS (control) |
| PATCH a running entry to a start 2147483645 s old (API, token with `time:write`), clock +10 s, `withElapsedSeconds()` | same probe | PATCH 200, `elapsed_seconds` is exactly 2147483647, no `QueryException` | PASS |
| `TimeTotals::forTask` and `forProject` on the overrun entry | same probe | `worked` is 2147483647 each | PASS |
| Start 2147483648 s old (one over the ceiling) and a start 1800 s old | same probe | 2147483647 and 1800 | PASS |
| `started_at = '-infinity'` written with raw SQL | same probe | `SQLSTATE 0A000 cannot convert infinity to bigint` (review WR-02 of plan 07-19 confirmed; see non-blocking notes) | NOTE |
| Phase directories | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Api tests/Isolation tests/Concurrency tests/Feature/TimeTracking` | 948 passed (6585 assertions), 0 failed (the earlier combined-run `TimerBarTest` case passed too) | PASS |
| Style and static analysis | `ddev exec vendor/bin/pint --test`; `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | Pint PASS (657 files); PHPStan `Found 2 errors`, only the two known pre-existing `cast.string` errors in `ResetAdminTwoFactorCommand.php:38` and `StorageCheckCommand.php:33` | PASS |

The Livewire page rendering (list `596523:14`, footer total `2147483647 + 3600`, view `596523:14:07`, edit page) was checked through plan 07-19's own tests, which are inside the 948 that passed; my own Livewire probe was dropped because the second sign-in setup of the ad-hoc file returned 403 and added no evidence beyond those assertions. The temporary probe file was removed; `git status` shows only the pre-existing `.planning` changes (plus an untracked `.planning/quick/261014-o13-...` directory that is not part of this phase).

### Probe Execution

Step 7c: SKIPPED (the phase declares no `probe-*.sh` scripts).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ---------- | ----------- | ------ | -------- |
| AP-01 | 07-01, 07-03, 07-12, 07-18 | `/api/v1` with Sanctum tokens carrying abilities; only Admin creates tokens | SATISFIED | Truth 1 |
| AP-02 | 07-01, 07-04 to 07-07, 07-11 to 07-16, 07-19 | Time entries list, detail, create, update, delete; same domain Actions and rules as the UI | SATISFIED | Truths 2, 3, 6, 7 |
| AP-04 | 07-08, 07-12, 07-16, 07-17 | Read-only lookups of clients, projects, tasks; tasks addressable by key | SATISFIED | Truth 4 |
| AP-05 | 07-01 to 07-03, 07-09 to 07-12, 07-15 to 07-18 | Token management UI, rate limiting, idempotency, OpenAPI documentation | SATISFIED | Truths 1, 3, 5 |

All four IDs in the PLAN frontmatter (AP-01, AP-02, AP-04, AP-05) appear in REQUIREMENTS.md; no orphaned requirement (AP-03 is deferred to backlog 999.1, listed in REQUIREMENTS.md). Bookkeeping for the orchestrator: REQUIREMENTS.md still shows `- [ ] **AP-04**` (line 96) and the traceability row `AP-04 | Phase 7 | Gaps Found` (line 261), left over from the first verification. The gap behind it was closed in plans 07-08 to 07-17, so set both to complete.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| phase files | - | TBD/FIXME/XXX scan over the 07-19 files and `app/Http`, `app/Domain/Api`, `app/Domain/TimeTracking`, `routes/api.php` | none | no markers |
| `app/Domain/TimeTracking/Actions/StopTimer.php` | 61 | stopping a running entry that has run past 2147483647 s overflows the generated `duration_seconds` column (SQLSTATE 22003); also blocks `StartTimer` for that user | Warning (accepted T-07-77) | non-blocking, see notes |
| `app/Domain/TimeTracking/Models/TimeEntry.php` | 114 | clamp does not cover an infinite `started_at` (review WR-02 of 07-19) | Warning | non-blocking, see notes |
| `app/Domain/TimeTracking/TimeEntryInput.php` | 82, 91 | strict string id comparison, uppercase UUID refused as `inconsistent_context` (07-REVIEW WR-01) | Warning (advisory) | not a listed must-have |
| `app/Http/Controllers/Api/V1/TimeEntryBatchController.php` | 101-105 | all-items server fault answers 422 `batch_none_saved` (07-REVIEW WR-03) | Warning (advisory) | by design of plan 07-15 |
| `TimeEntry::elapsedSecondsSql`, `ProjectTimeOverviewTest`, `TimeEntryWriteTest` | - | no runtime alias check; some assertions weaker than they could be (07-19 review IN-01 to IN-03) | Info | no behavior impact |

### Non-blocking notes on the 07-19 advisory review

The review had 0 critical findings and 2 warnings. Neither breaks a must-have.

1. **StopTimer overflow past 2147483647 s (review WR-01, plan threat T-07-77, accepted).** Truth 7 and the plan's must-haves concern the entry list, view and edit pages, timesheet and totals staying readable ("the list can never be poisoned"). That holds. Stopping an already-overrun entry is a different, write-side operation: the plan names it in its scope note and threat register, the recovery path (correct the start through `UpdateTimeEntry`, then stop) is tested (`lets the Admin correct a running entry that has run past the limit and then stop it`), and the Admin can reach the edit page. It needs a start set within seconds of the 68-year bound and does not involve the REST API timer (AP-03 is out of this phase). One consequence goes beyond the plan's wording: the one-running-per-user rule makes `StartTimer` stop the running entry first, so that user cannot start a new timer until the start is corrected. Recommended small follow-up for the owner (outside this phase): reuse `TimeEntryInput::assertDurationFits` in `StopTimer`, or clamp `ended_at`, so the failure is a message instead of an unhandled 500. Note the untracked quick task `.planning/quick/261014-o13-hourly-safety-net-command-that-ends-runn/` appears to concern ending running timers and may be the place to address it.
2. **Infinite `started_at` (review WR-02).** Reproduced here: a raw-SQL `-infinity` start makes the cast fail with SQLSTATE 0A000. The application layer cannot store such a value (the API and panel accept only instants within UTC years 1 to 9999, truth 6), so only direct SQL, an import or a data fix can create it. The 07-13 must-have is about the span guard on accepted starts, and the reachable range is covered. The docblock sentence "no storable start can overflow the cast" is stronger than the truth for the database column itself. Recommended follow-up: a CHECK constraint that rejects infinity, or a one-word docblock correction to "no start the application can store".

### Human Verification Required

1. **Public /docs page.** Test: open `/docs` as a guest. Expected: readable page listing every endpoint with example payloads, no Try It Out, noindex. Why human: visual and wording quality (07-UAT.md test 2 only checked status, header and endpoint list by curl).
2. **Token management screen.** Test: as Admin create a token (password + TOTP), copy it once, close the modal, revoke it; as Partner confirm the screen is absent. Expected: plain token only in the one-time modal, readable badges and Czech labels. Why human: visual and UX check (07-UAT.md test 3, skipped).

The former item "decide on the residual window of the running-entry span guard" is resolved by plan 07-19 and removed.

### Gaps Summary

No gap remains. G-07-1 is closed in the code and reproduced as closed by an independent probe, the one-expression rule holds across the scope and both `TimeTotals` readers, the write bound (D-09) is unchanged, the wire contract is unchanged, and the whole phase directory run is green (948 passed). What is left is two visual checks for a human, and two optional follow-ups for the owner (StopTimer on an overrun entry, infinite timestamps in raw SQL) that no must-have depends on.

---

_Verified: 2026-10-14T20:00:00Z_
_Verifier: Claude (gsd-verifier)_
