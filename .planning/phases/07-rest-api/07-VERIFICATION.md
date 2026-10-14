---
phase: 07-rest-api
verified: 2026-10-14T12:00:00Z
status: gaps_found
score: 5/6 must-haves verified
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
covered_digest: "v3:sha256:ab9d0ff2f73c39b8db2de221a810c05e69b4a5ff1b9a58eeefb6fcc0aa3a1c9a"
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "Every refusal path of the API answers a clean 4xx, never a 500: malformed or out-of-range input is refused with a 422 (plan 07-05 must-have: an interval longer than 2147483647 seconds answers 422, never the PostgreSQL integer overflow 500; plan 07-04 must-have: every malformed filter answers 422, never 500; D-09 'the API refuses what the UI refuses')"
    status: failed
    reason: "Three families of well-formed but unsupported input still give an unhandled HTTP 500 (reproduced on the DDEV database by this verification, each case in its own transaction): (1) PATCH started_at far in the past (T-07-26, open in 07-SECURITY.md), (2) instants whose UTC value is outside years 1 to 9999 on create, update and filter[from]/filter[to], and (3) a well-formed but foreign or hand-edited cursor on every list endpoint. Family (2) in a batch aborts the request after earlier items were committed and releases the Idempotency-Key, so a retry with the same key saves those items twice, which defeats the success-criterion 3 guarantee for that path."
    artifacts:
      - path: "app/Domain/TimeTracking/Actions/UpdateTimeEntry.php"
        issue: "No MAX_DURATION_SECONDS bound in applyTimes(); PATCH {started_at: '1900-01-01T00:00:00Z'} gives 500 (integer out of range) on a finished entry; on a running entry the start is stored and the Admin's entry list (scopeWithElapsedSeconds) can then fail (WR-01)"
      - path: "app/Http/Requests/Api/V1/TimeEntryPayload.php"
        issue: "INSTANT accepts any four-digit year except 0000 with any offset and never range-checks the UTC result; POST started_at 0001-01-01T00:00:00+02:00 gives 500 (WR-02)"
      - path: "app/Domain/TimeTracking/Support/PragueDay.php"
        issue: "start()/endExclusive()/midnight() can return a value outside the PostgreSQL timestamptz range; GET /time-entries?filter[from]=0001-01-01 gives 500 (WR-02); the panel custom period filter shares the hole"
      - path: "app/Http/Controllers/Api/V1/TimeEntryBatchController.php"
        issue: "Only ValidationException is caught per item; any other exception aborts after earlier items are committed and EnsureIdempotency releases the key (WR-02)"
      - path: "app/Http/Requests/Api/V1/ListTimeEntriesRequest.php"
        issue: "cursor rule is only string|max:1024; Cursor::fromEncoded accepts any JSON object with _pointsToNextItems, so a cursor with missing or wrong-typed keys gives 500 on /time-entries (WR-03); same in ListLookupsRequest.php for /clients, /projects, /tasks"
      - path: "app/Http/Controllers/Api/V1/ApiExamples.php"
        issue: "The published example cursor lacks the keys of a real cursor, so an integrator pasting it gets a 500 (WR-03)"
    missing:
      - "Enforce the interval span in the shared Action (UpdateTimeEntry::applyTimes, before forceFill) via one assertDurationFits() helper next to the input rules, measuring a running entry against TimerClock::now(); keep or delegate the existing TimeEntryPayload::end() check. Tests: PATCH started_at 1900-01-01T00:00:00Z on a finished and on a running entry expect 422 under started_at"
      - "Range-check the normalised UTC instant (years 1 to 9999) in one place (TimeEntryInput::normalised) and raise the existing invalid_time 422; make PragueDay start()/endExclusive()/midnight()/day() reject out-of-range days so filter[from]/filter[to] answer 422. Regression tests: year 1 with +02:00 and year 9999 with -12:00 on create, update and both filters"
      - "In TimeEntryBatchController map a failed item that is not a ValidationException to an errors[] entry (or otherwise guarantee the key is not released after partial commits) so a late fault cannot cause duplicates on retry; add a batch test with a bad-range item after valid items and a retry with the same key"
      - "Validate the decoded cursor in ListTimeEntriesRequest and ListLookupsRequest against the columns expected for the effective sort (all keys present, uuid for id columns, parseable instant for timestamps); answer 422. Replace the example cursor in ApiExamples with a complete one. Tests: empty-parameter cursor and a cursor from another endpoint on every list endpoint"
deferred: []
---

# Phase 7: REST API Verification Report

**Phase Goal:** External tools can read and write time entries (single and batch) and look up clients, projects and tasks through a versioned, token-secured API that applies the same rules as the UI
**Verified:** 2026-10-14T12:00:00Z
**Status:** gaps_found
**Re-verification:** No, initial verification

## Goal Achievement

The feature surface of the phase exists, is wired and tested: token-secured `/api/v1` routes, the admin token screen, time entry list and CRUD over the shared domain Actions, idempotent single and batch create, read-only lookups, per-token rate limits and generated OpenAPI documentation. `ddev exec vendor/bin/pest tests/Feature/Api tests/Concurrency` was re-run by this verification: 256 passed (2403 assertions). The goal is not fully achieved against the plans' own must-haves because several accepted-but-unsupported inputs end in an unhandled 500 instead of a 422, one of which (PATCH `started_at`) is a documented, still-open mitigation (T-07-26) and one of which breaks the idempotent-retry guarantee of a batch.

### Observable Truths

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | SC1: Admin creates and revokes API tokens with `time:read` / `time:write` in a token screen, a Partner cannot create tokens, a token lacking the ability is refused | VERIFIED | `ApiTokenResource`, `IssueApiToken` (password + TOTP re-confirmation), `RevokeApiToken` exist and are wired; `routes/api.php` puts every route in a group with `auth:sanctum`, `abilities:<ability>` and a throttle; `ApiTokenScreenTest`, `IssueApiTokenTest`, `AuthenticationTest`, `tests/Isolation/ApiAccessTest` green in the 256-test run (403 on missing ability, 401 for Partner/session-only tokens). Visual quality of the screen is a human item. |
| 2 | SC2: Client lists time entries with filters, creates, reads, updates, deletes; API refuses what the UI refuses (locked billed entries, inconsistent client/project/task, end before start) | VERIFIED (named refusals) | `TimeEntryController` delegates to `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry`; `TimeEntryWriteTest` (UI refusal matrix: 409 `entry_locked`, field errors for inconsistent context, end before start), `TimeEntryListTest` green. The out-of-range-input class is tracked under truth 6. |
| 3 | SC3: Same idempotency key on single or batch create does not duplicate; a batch saves every valid item and reports only the failed ones | VERIFIED (specified behaviour) | `EnsureIdempotency` + `IdempotencyStore` (atomic `INSERT ... ON CONFLICT DO NOTHING`), `TimeEntryBatchController` returns 201/207/422 with `errors[]` of failed items only; `IdempotencyTest`, `TimeEntryBatchTest`, `IdempotencyConcurrencyTest` (8 real processes) green. Caveat: a client-supplied out-of-range instant in a batch item breaks replay safety, see truth 6. |
| 4 | SC4: Clients, projects and tasks resolved by UUID, key and name through read-only lookups; task addressed by `KEY-N` | VERIFIED | `ClientController`, `ProjectController`, `TaskController`, `ReferenceResolver`; only GET routes for them in `routes/api.php`; `LookupTest` green. |
| 5 | SC5: Excess requests get a rate-limit response; published OpenAPI documentation describes every endpoint | VERIFIED | Per-token `throttle:api-read` / `throttle:api-write` on both route groups, `RateLimitTest` (429 + Retry-After) green; Scribe docs, `/docs` and `/docs.openapi` public, generated spec exists at `storage/app/private/scribe/openapi.yaml`; `ApiDocumentationTest` (every route documented), `ApiDocumentationAccessTest`, `ApiDocumentationHygieneTest` green. |
| 6 | Plan must-have: every refusal path is a clean 4xx, never a 500; malformed or out-of-range input is a 422 (07-04 and 07-05 must-haves, T-07-26) | FAILED | Reproduced by this verification with a temporary Pest probe (deleted afterwards), each case in its own transaction: `PATCH {started_at: 1900-01-01T00:00:00Z}` -> 500; `POST` with `started_at 0001-01-01T00:00:00+02:00` -> 500; `GET /time-entries?filter[from]=0001-01-01` -> 500; `GET /time-entries?cursor=<{"_pointsToNextItems":true}>` -> 500; `GET /clients?cursor=<same>` -> 500. Matches 07-REVIEW.md WR-01, WR-02, WR-03. Batch controller code confirms the partial-commit plus key-release path (only `ValidationException` is caught). |

**Score:** 5/6 truths verified (0 present, behavior-unverified)

### Deferred Items

None. AP-03 (API timer) was removed from this phase on 2026-10-14 and is tracked as ROADMAP backlog item 999.1; it is not a gap of this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `routes/api.php` | Versioned routes in two guarded groups | VERIFIED | auth, ability and throttle on every route; batch declared before PATCH/DELETE |
| `app/Http/Controllers/Api/V1/*Controller.php` (time entry, batch, client, project, task) | Thin controllers over Actions | VERIFIED | Wired from routes; call domain Actions / resolver |
| `app/Filament/Resources/ApiTokenResource.php` + `IssueApiToken` / `RevokeApiToken` | Admin token screen | VERIFIED | Registered in panel; Partner isolation tests green |
| `app/Domain/Api/Idempotency/IdempotencyStore.php`, `EnsureIdempotency` | Idempotent create | VERIFIED | Wired as route middleware on both create routes |
| `config/scribe.php`, docs routes, `storage/app/private/scribe/openapi.yaml` | Published OpenAPI | VERIFIED | Generated output present and gitignored |
| `app/Domain/TimeTracking/Actions/UpdateTimeEntry.php` | Shared rules incl. interval bound | PARTIAL | No span bound on started_at-only edits (gap) |
| `app/Http/Requests/Api/V1/ListTimeEntriesRequest.php`, `ListLookupsRequest.php` | Total input validation | PARTIAL | Cursor not validated (gap) |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| Controllers | Domain Actions | `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry` | WIRED | Same Actions as the panel |
| Route groups | Sanctum + abilities + throttle | middleware arrays in `routes/api.php` | WIRED | Route-walk test enforces it |
| Create routes | Idempotency | `EnsureIdempotency::class` middleware | WIRED | POST single and batch only |
| Token screen | `IssueApiToken` / `RevokeApiToken` | Filament actions | WIRED | |
| Time entry list filters | `PragueDay` | shared with panel | WIRED | out-of-range days not handled (gap) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `TimeEntryResource` (API) | time entry fields | Eloquent `time_entries` queries via Actions/controllers | Yes | FLOWING |
| Lookup resources | client/project/task fields | Eloquent queries through selectable sets | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| API feature and concurrency suites | `ddev exec vendor/bin/pest tests/Feature/Api tests/Concurrency` | 256 passed, 2403 assertions | PASS |
| PATCH far-past started_at is refused with 422 | temporary probe test | HTTP 500 | FAIL |
| Year-1 instant on create refused with 422 | temporary probe test | HTTP 500 | FAIL |
| Year-1 `filter[from]` refused with 422 | temporary probe test | HTTP 500 | FAIL |
| Malformed cursor refused with 422 (`/time-entries`, `/clients`) | temporary probe test | HTTP 500 both | FAIL |

The orchestrator's full suite run (2907 passed) was not repeated; the existing suite is green because none of the failing inputs is covered by a test (`TimeEntryWriteTest` covers only the literal year `0000`).

### Probe Execution

Step 7c: SKIPPED (the phase declares no `probe-*.sh` scripts).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| AP-01 | 07-01, 07-03 | `/api/v1` with Sanctum tokens carrying abilities; only Admin creates tokens | SATISFIED | Truth 1; `ApiServiceProvider` accepts only active-Admin tokens, `AdminOnly` token screen |
| AP-02 | 07-01, 07-04, 07-05, 07-06, 07-07 | Time entries list, detail, create, update, delete; same domain actions and rules as the UI | SATISFIED with gap | Truths 2 and 6: actions shared, but out-of-range input gives 500 (gaps) |
| AP-04 | 07-08 | Read-only lookups of clients, projects, tasks; tasks addressable by key | SATISFIED | Truth 4; cursor 500 on the lookup lists is part of the gap |
| AP-05 | 07-01, 07-02, 07-03, 07-09 (and 07-10 to 07-12 by phase) | Token management UI, rate limiting, idempotency, OpenAPI docs | SATISFIED | Truths 1, 3, 5 |

All four phase requirement IDs (AP-01, AP-02, AP-04, AP-05) appear in PLAN frontmatter and in REQUIREMENTS.md (marked complete, traceability table maps them to Phase 7). No orphaned requirement: AP-03 is deferred to backlog 999.1 and is not mapped to Phase 7.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| (phase files) | - | TBD/FIXME/XXX/TODO/HACK scan over `app/Http`, `app/Domain/Api`, token actions, token resource, `routes/api.php`, `config/scribe.php` | none | no markers found |
| `UpdateTimeEntry.php` | ~159-182 | Guard exists only on create/ended_at path | BLOCKER (gap above) | 500 on PATCH started_at; can poison a running entry |
| `TimeEntryBatchController.php` | 75-84 | Catches only ValidationException | BLOCKER (gap above) | partial commit plus released key on server fault |
| `ListTimeEntriesRequest.php` / `ListLookupsRequest.php` | 60 / 45 | Weak `cursor` rule | Warning (gap above) | 500 on foreign cursor; sample cursor in docs triggers it |
| `ReferenceResolver.php` | 23, 25 | `$` instead of `\z` in key regexes (IN-03) | Info | accepts a key with trailing newline; no wrong data |
| `CONTRIBUTING.md`, `config/scribe.php`, `tests/Support/ApiDocs.php` | - | IN-01, IN-02, IN-04 | Info | documentation or test hygiene; not goal-blocking |

### Human Verification Required

These are for UAT and do not change the status (gaps_found takes precedence):

1. **Public /docs page.** Test: open `/docs` as a guest. Expected: readable page listing every endpoint, with the confirmed time entry contract and example payloads, no Try It Out, noindex. Why human: visual and wording quality.
2. **Token management screen.** Test: as Admin, create a token with both abilities (password + TOTP), copy it once, close the modal, revoke it. Expected: plain token only in the one-time modal, status badges and Czech labels readable. Why human: visual and UX check; as a Partner, confirm the screen is absent.

### Gaps Summary

One root cause group: input validation of the API is not total. The plans promised that every refusal is a clean 4xx and that overflow is a 422; the code and a live reproduction show three still-open families of 500s (far-past `started_at` on PATCH, UTC-out-of-range instants on create/update/filter, forged cursors). The first is the already recorded open threat T-07-26 (medium, below the blocking severity threshold, so security passed), but it contradicts the 07-05 must-have text, and the second also undermines success criterion 3 (replay-safe batch retries) when a client-supplied value triggers it. Fixes are concrete and small (see `missing` in the frontmatter) and are best closed in one gap-closure plan: a shared span/range guard in the Actions and `TimeEntryInput`, a range-aware `PragueDay`, a non-`ValidationException` item mapping in the batch controller, and cursor validation in the two list request classes, each with the regression tests named above. Info items IN-01 to IN-04 may be folded into the same plan. Alternatively, the owner may accept the residual risk with an `overrides:` entry for truth 6, which this verification does not recommend because WR-01 can leave a running entry in a state that breaks the Admin's own list.

---

_Verified: 2026-10-14T12:00:00Z_
_Verifier: Claude (gsd-verifier)_
