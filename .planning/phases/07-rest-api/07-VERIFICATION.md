---
phase: 07-rest-api
verified: 2026-10-14T18:00:00Z
status: human_needed
score: 6/7 must-haves verified
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
  - routes/api.php
  - tests/Feature/Api/TimeEntryBatchTest.php
  - tests/Feature/Api/TimeEntryListTest.php
  - tests/Feature/Api/TimeEntryWriteTest.php
  - tests/Isolation/ApiAccessTest.php
covered_digest: "v3:sha256:e10defaad9ac16bbfe8ebc1d4f6b846b87bb2c7a46c1e21cc0d80c4b26bda211"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 5/6
  gaps_closed:
    - "Far-past started_at on PATCH of a finished or running entry no longer gives a 500 (T-07-26, plan 07-13)"
    - "Instants outside UTC years 1 to 9999 on create, update and filter[from]/filter[to] answer 422 (plan 07-14)"
    - "A batch item that meets a non-validation fault is reported as a failed item, so the Idempotency-Key keeps protecting the retry (plan 07-15)"
    - "A forged, foreign or incomplete cursor answers 422 on /time-entries, /clients, /projects and /tasks, and the published example cursor is complete and accepted (plans 07-16, 07-18)"
    - "IN-01 to IN-04 of the first review (whole-string key patterns, documented sort order, documented limits equal config, docs helper side effects) (plan 07-17)"
  gaps_remaining: []
  regressions: []
behavior_unverified_items: []
human_verification:
  - test: "Decide on the residual window of the running-entry span guard (07-REVIEW.md WR-02, plan 07-13 truth 3)."
    expected: "Either fix it (make the elapsed-seconds SQL cast overflow-proof in TimeEntry::scopeWithElapsedSeconds, for example a LEAST/GREATEST clamp on a bigint) or accept the residual risk with an overrides: entry. Reproduced by this verification: PATCH a running entry to a start exactly 2147483645 seconds before now (HTTP 200, accepted by the guard), wait a few seconds, and TimeEntry::query()->withElapsedSeconds(now)->get() fails with SQLSTATE 22003 integer out of range. The window is a start within the elapsed run time of the column limit (about 68.05 years back), so it needs a deliberately or accidentally boundary-exact start, but the effect is a dead Admin entry list until the row is edited in the database."
    why_human: "Product and risk decision: the guard is exactly what the original gap prescribed and every named case of the gap is closed, but the plan wording says the list can 'never' be poisoned and a time-dependent edge remains. The review rates it a warning, not a blocker."
  - test: "Public /docs page as a guest."
    expected: "Readable page listing every endpoint with the confirmed time entry contract and example payloads, no Try It Out, noindex."
    why_human: "Visual and wording quality of the generated documentation."
  - test: "Token management screen as Admin (and as Partner)."
    expected: "Create a token with both abilities (password + TOTP), copy it once from the modal, close the modal, revoke it; status badges and Czech labels readable. A Partner does not see the screen."
    why_human: "Visual and UX check; the isolation and behavior tests are green but cannot judge presentation."
---

# Phase 7: REST API Verification Report

**Phase Goal:** External tools can read and write time entries (single and batch) and look up clients, projects and tasks through a versioned, token-secured API that applies the same rules as the UI
**Verified:** 2026-10-14T18:00:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap closure plans 07-13 to 07-18

## Goal Achievement

The phase goal is achieved. The one gap of the first verification (truth 6: unhandled 500 instead of 422 for well-formed but unsupported input) is closed in all three families. This verification reproduced each original failing request with its own temporary Pest probe (written fresh, not copied from the plans' tests, and deleted afterwards) and every one now answers a clean 422. The earlier passing truths (SC1 to SC5) still hold: the Api, Isolation, Concurrency and TimeTracking test directories were re-run (937 passed in the combined run; the single failure, `TimerBarTest` "opens the start form...", asserts the `x-on:click="toggle()"` markup from the unrelated commit 8a72b07, passes in isolation and in the TimeTracking directory run (394 passed), and is not part of this phase). The orchestrator's full suite (3006 passed at the end of plan 07-15) was not repeated.

One residual edge is left open for a human decision (truth 7): the running-entry span guard is a snapshot, so a start accepted at the very edge of the limit can break the Admin's entry list a few seconds later. It is the open WR-02 of the second review, reproduced here, and it is the reason the status is `human_needed` rather than `passed`; the two visual UAT items from the first report also remain.

### Observable Truths

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | SC1: Admin creates and revokes API tokens with `time:read` / `time:write`, a Partner cannot create tokens, a token lacking the ability is refused | VERIFIED | Unchanged since the first verification; `routes/api.php` guards every route with `auth:sanctum`, `abilities:` and a throttle; `ApiTokenScreenTest`, `IssueApiTokenTest`, `AuthenticationTest`, `tests/Isolation/ApiAccessTest` green in this run. This run's probe also showed a forged cursor gives 401 for a guest and 403 for a token without the ability, never 422 (validation runs after the guards, plan 07-18). |
| 2 | SC2: list with filters, create, read, update, delete; the API refuses what the UI refuses | VERIFIED | `TimeEntryController` over the shared `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry`; `TimeEntryWriteTest`, `TimeEntryListTest` green. The span bound and the storable-year check now live in `TimeEntryInput` (`assertDurationFits`, `normalised`) and are shared by the panel and the API. |
| 3 | SC3: same Idempotency-Key does not duplicate; a batch saves every valid item and reports only the failed ones | VERIFIED | `TimeEntryBatchController` now catches `ValidationException` and, separately, `Throwable` per item (reported to the log, listed under `entry` with `api.errors.batch_item_failed`), so a late fault no longer aborts the request after partial commits. Probe: a batch of valid, year-1 (+02:00), far-past start with an end, valid answers 207, `failed` is 2; a retry with the same key answers 207 with `Idempotent-Replayed: true` and the table holds exactly the 2 valid entries. `IdempotencyTest`, `TimeEntryBatchTest`, `IdempotencyConcurrencyTest` green. |
| 4 | SC4: clients, projects and tasks by UUID, key and name through read-only lookups; task by `KEY-N` | VERIFIED | Unchanged; `LookupTest` green. `ReferenceResolver::PROJECT_KEY` and `TASK_REFERENCE` now end in `$/D`, so a key with a trailing line feed is refused (plan 07-17). |
| 5 | SC5: rate-limit response for excess requests; published OpenAPI documentation describes every endpoint | VERIFIED | `RateLimitTest`, `ApiDocumentationTest` (every route documented, documented limits equal `config/kokpit.php`), `ApiDocumentationAccessTest`, `ApiDocumentationHygieneTest` green. The example cursor in the docs is a complete cursor (`ApiExamples.php` lines 108 and 113 decode to `started_at`, `time_entries.id`, `_pointsToNextItems`). |
| 6 | Plan must-have (07-04, 07-05, T-07-26): every refusal path is a clean 4xx, never a 500; malformed or out-of-range input is a 422 | VERIFIED | Re-probed live (see Behavioral Spot-Checks): PATCH `started_at` 1900-01-01 on a finished and a running entry, PATCH `0001-01-01T00:00:00+02:00` on a running entry, POST with year 1 +02:00, POST with `ended_at` 9999-12-31T23:59:59-12:00, `filter[from]=0001-01-01`, `filter[to]=0001-01-01`, `filter[from]=9999-12-31T23:59:59-12:00`, and a cursor of no parameters, of another endpoint or not a cursor on `/time-entries`, `/clients`, `/projects`, `/tasks`: all answer 422; none a 500. Code: `UpdateTimeEntry::applyTimes()` calls `assertDurationFits` on a finished entry and against `TimerClock::now()` on a running one; `TimeEntryInput::normalised()` refuses an unstorable instant with `invalid_time`; `PragueDay::midnight()` returns null for an unstorable midnight and `day()` rejects the day; `ApiCursor::rule()` is wired in `ListTimeEntriesRequest` and `ListLookupsRequest` with exact key set and typed values. |
| 7 | Plan 07-13 truth 3: a running entry's start is bounded "so the Admin's entry list can never be poisoned" | UNCERTAIN (WARNING, human decision) | The named cases are closed (1900 and 2200 starts refused, row unchanged, list readable). Residual, reproduced: a start accepted with a margin of two seconds from the limit (HTTP 200) makes `withElapsedSeconds()` fail with SQLSTATE 22003 once the entry has run a few seconds longer, because `EXTRACT(EPOCH ...)::int` in `TimeEntry::scopeWithElapsedSeconds` is not overflow-proof (07-REVIEW.md WR-02, disposition `open`). See Human Verification item 1. |

**Score:** 6/7 truths verified (0 present, behavior-unverified; 1 left for a human decision)

### Deferred Items

None. AP-03 (API timer) was removed from this phase on 2026-10-14 and is tracked as ROADMAP backlog item 999.1; it is not a gap of this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `routes/api.php` | Versioned routes in two guarded groups | VERIFIED | unchanged, route-walk test enforces auth, ability and throttle |
| `app/Http/Controllers/Api/V1/*Controller.php` | Thin controllers over Actions | VERIFIED | batch controller now maps every item failure |
| `app/Domain/TimeTracking/TimeEntryInput.php` | `MAX_DURATION_SECONDS`, `assertDurationFits()`, storable-instant check | VERIFIED | present, substantive, called from `UpdateTimeEntry`, `CreateTimeEntry` and `TimeEntryPayload` |
| `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php` | Shared rules incl. interval bound on started_at-only edits | VERIFIED (WR-02 margin noted) | bound on finished and running entries |
| `app/Domain/TimeTracking/Support/PragueDay.php`, `TimerClock::isStorable()` | Range-aware day boundaries | VERIFIED | `start()`/`endExclusive()` throw, `day()` returns null, filters answer 422 |
| `app/Http/Requests/Api/V1/ApiCursor.php`, `ListTimeEntriesRequest.php`, `ListLookupsRequest.php` | Total cursor validation | VERIFIED | exact key set, typed values, decoded like `Cursor::fromEncoded` |
| `app/Http/Controllers/Api/V1/ApiExamples.php` | Complete example cursor | VERIFIED | accepted by the API (probe), fictional values only |
| `app/Filament/Resources/ApiTokenResource.php`, `IssueApiToken`, `RevokeApiToken`, `IdempotencyStore`, `EnsureIdempotency`, Scribe docs output | Token screen, idempotency, docs | VERIFIED | unchanged and green |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `UpdateTimeEntry::applyTimes` | `TimeEntryInput::assertDurationFits` | direct call before `forceFill` | WIRED | finished: against end; running: against `TimerClock::now()` |
| `TimeEntryInput::normalised` | `TimerClock::isStorable` | direct call | WIRED | `invalid_time` under the caller's field |
| `ListTimeEntriesRequest::dayOrInstant` | `PragueDay::day` | filter parsing | WIRED | out-of-range day gives 422 |
| `ListTimeEntriesRequest`, `ListLookupsRequest` | `ApiCursor::rule` | cursor rules | WIRED | per sort and per controller |
| `TimeEntryBatchController` | `EnsureIdempotency` | 207 is a stored 2xx | WIRED | probe: retry replays the 207 |
| Controllers | Domain Actions | `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry` | WIRED | same Actions as the panel |
| Route groups | Sanctum, abilities, throttle | middleware arrays | WIRED | forged cursor never passes guards |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `TimeEntryResource` (API) | entry fields | Eloquent `time_entries` through Actions and controllers | Yes | FLOWING |
| Lookup resources | client, project, task fields | Eloquent queries through selectable sets | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| PATCH far-past start on finished and running entries, year 1 with +02:00 | temporary probe test via `ddev exec vendor/bin/pest` | 422 each, list query still readable | PASS |
| POST year 1 start (+02:00) and year 10000 UTC end | same | 422 each | PASS |
| `filter[from]=0001-01-01`, `filter[to]=0001-01-01`, `filter[from]=9999-12-31T23:59:59-12:00` | same | 422 each | PASS |
| Cursor of no parameters, foreign cursor, garbage cursor on 4 list endpoints | same | 422 on all 4 endpoints for each | PASS |
| Example cursor on `/time-entries` | same | 200 | PASS |
| Forged cursor: guest and token without ability | same | 401 and 403 | PASS |
| Batch with bad-range items between valid ones, retried with the same key | same | 207, replay header, 2 rows stored | PASS |
| Running entry PATCHed to a start 2147483645 s old, then 4 s pass | same | PATCH 200, then list query fails with SQLSTATE 22003 | FAIL (residual, truth 7, WR-02) |
| Uppercase `project_id` next to a consistent `task_id` | same | 422 `inconsistent_context` (WR-01 of the second review, advisory) | noted |
| Api, Isolation, Concurrency, TimeTracking suites | `ddev exec vendor/bin/pest tests/Feature/Api tests/Isolation tests/Concurrency tests/Feature/TimeTracking` | 937 passed, 1 failed (`TimerBarTest`, unrelated, passes alone and in the TimeTracking directory) | PASS for this phase |

The temporary probe file was removed; `git status` shows only the pre-existing `.planning` changes.

### Probe Execution

Step 7c: SKIPPED (the phase declares no `probe-*.sh` scripts).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ---------- | ----------- | ------ | -------- |
| AP-01 | 07-01, 07-03, 07-12, 07-18 | `/api/v1` with Sanctum tokens carrying abilities; only Admin creates tokens | SATISFIED | Truth 1; forged-cursor guard order verified |
| AP-02 | 07-01, 07-04 to 07-07, 07-11 to 07-16 | Time entries list, detail, create, update, delete; same domain Actions and rules as the UI | SATISFIED | Truths 2, 3, 6; WR-02 residual is truth 7 |
| AP-04 | 07-08, 07-12, 07-16, 07-17 | Read-only lookups of clients, projects, tasks; tasks addressable by key | SATISFIED | Truth 4; lookup cursors validated |
| AP-05 | 07-01 to 07-03, 07-09 to 07-12, 07-15 to 07-18 | Token management UI, rate limiting, idempotency, OpenAPI documentation | SATISFIED | Truths 1, 3, 5 |

All four IDs appear in PLAN frontmatter and in REQUIREMENTS.md; no orphaned requirement (AP-03 is deferred to backlog 999.1). Bookkeeping note for the orchestrator: REQUIREMENTS.md still shows `- [ ] **AP-04**` and a traceability row `AP-04 | Phase 7 | Gaps Found`, left over from the first verification. The gap behind it is closed, so these should be set to complete once the human items are resolved.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| phase files | - | TBD/FIXME/XXX scan over `app/Http`, `app/Domain/Api`, `app/Domain/TimeTracking`, `routes/api.php` | none | no markers |
| `app/Domain/TimeTracking/Models/TimeEntry.php` | 111 | `EXTRACT(EPOCH ...)::int` not overflow-proof (WR-02) | Warning | Admin list can fail for an edge-of-limit running start |
| `app/Domain/TimeTracking/TimeEntryInput.php` | 82, 91 | strict string id comparison, uppercase UUID refused as `inconsistent_context` (WR-01) | Warning (advisory) | spurious 422 for a case-variant UUID next to a task or project; not a listed must-have |
| `app/Http/Controllers/Api/V1/TimeEntryBatchController.php` | 101-105 | all-items server fault answers 422 `batch_none_saved` (WR-03) | Warning (advisory) | by design of plan 07-15 truth 4; a 5xx would be more honest to retry middleware |
| instant patterns, duplicated sort tables, docblock order, endpoint descriptions | - | IN-01 to IN-04 of the second review | Info | no behavior impact |

### Human Verification Required

1. **Running-entry span guard residual (decision).** Test: review 07-REVIEW.md WR-02 and the reproduction above. Expected: either a fix (overflow-proof elapsed-seconds cast) in a small follow-up, or an `overrides:` entry accepting the risk. Why human: risk acceptance; the guard implements the originally prescribed fix.
2. **Public /docs page.** Test: open `/docs` as a guest. Expected: readable page listing every endpoint with example payloads, no Try It Out, noindex. Why human: visual and wording quality.
3. **Token management screen.** Test: as Admin create a token (password + TOTP), copy it once, close the modal, revoke it; as Partner confirm the screen is absent. Expected: plain token only in the one-time modal, readable badges and Czech labels. Why human: visual and UX check.

### Gaps Summary

No blocking gap remains. All three families of 500s from the first verification (far-past PATCH start, unstorable instants on create, update and filters, forged cursors) now answer 422, the batch retry guarantee holds with a bad-range item, and the earlier passing truths are intact. What is left is one narrow, reproduced edge of the running-entry guard (WR-02) for the owner to fix or accept, and two visual checks. The other open review warnings (WR-01 uppercase UUID, WR-03 all-fault batch status) and the info items are advisory and break no must-have.

---

_Verified: 2026-10-14T18:00:00Z_
_Verifier: Claude (gsd-verifier)_
