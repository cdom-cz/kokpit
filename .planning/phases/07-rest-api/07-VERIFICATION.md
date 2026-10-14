---
phase: 07-rest-api
verified: 2026-10-14T21:00:00Z
status: passed
score: 8/8 must-haves verified
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
  - .planning/phases/07-rest-api/07-20-PLAN.md
  - .planning/phases/07-rest-api/07-20-SUMMARY.md
  - CONTRIBUTING.md
  - app/Domain/Api/ReferenceResolver.php
  - app/Domain/TimeTracking/Actions/CreateTimeEntry.php
  - app/Domain/TimeTracking/Actions/UpdateTimeEntry.php
  - app/Domain/TimeTracking/Models/TimeEntry.php
  - app/Domain/TimeTracking/Queries/TimeTotals.php
  - app/Domain/TimeTracking/Support/PragueDay.php
  - app/Domain/TimeTracking/Support/TimerClock.php
  - app/Domain/TimeTracking/TimeEntryInput.php
  - app/Filament/Resources/ApiTokenResource.php
  - app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php
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
  - tests/Feature/Api/ApiTokenScreenTest.php
  - tests/Feature/Api/TimeEntryBatchTest.php
  - tests/Feature/Api/TimeEntryListTest.php
  - tests/Feature/Api/TimeEntryWriteTest.php
  - tests/Feature/TimeTracking/ProjectTimeOverviewTest.php
  - tests/Feature/TimeTracking/TimeEntryActionsTest.php
  - tests/Feature/TimeTracking/TimeEntryListTest.php
  - tests/Isolation/ApiAccessTest.php
covered_digest: "v3:sha256:45e6db0b6a185c832d1ea9e5cc05090614fedb8d7fd5f6f4461fced124902895"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 7/7
  gaps_closed:
    - "G-07-3 / UAT test 3: the empty-state button 'Novy API token' of the token table did nothing; it now opens the create modal (plan 07-20)"
  gaps_remaining: []
  regressions: []
behavior_unverified_items: []
human_verification:
  - test: "Token management screen in a real browser as Admin (and as Partner), including the empty-state button."
    expected: "With no tokens, click the empty-state button 'Novy API token': the create modal opens. Create a token with both abilities (password + TOTP), copy it once from the one-time modal, close it, then revoke it from the list. Status badges and Czech labels are readable. A Partner does not see the screen."
    why_human: "Visual and UX judgement plus the real Alpine/Livewire click path. The Livewire tests drive the server side of the click (mountTableAction) and fail on the old code, but they cannot prove the browser renders the modal or judge presentation. 07-UAT.md test 3 is still unconfirmed in a browser since the fix."
  - test: "Public /docs page as a guest."
    expected: "Readable page listing every endpoint with the confirmed time entry contract and example payloads, no Try It Out, noindex."
    why_human: "Visual and wording quality of the generated documentation. 07-UAT.md test 2 was checked by curl only (200, noindex, 12 endpoints, no Try It Out); layout and wording were not judged by eye."
---

# Phase 7: REST API Verification Report

**Phase Goal:** External tools can read and write time entries (single and batch) and look up clients, projects and tasks through a versioned, token-secured API that applies the same rules as the UI
**Verified:** 2026-10-14T21:00:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap closure plan 07-20 (G-07-3, UAT test 3). G-07-1 (plan 07-19) was closed and verified in the previous report; it is re-checked by regression only.

## Goal Achievement

The phase goal is achieved and no must-have is open. The one new item since the previous report, G-07-3 (the empty-state token button that opened nothing), is fixed in the code and proven by tests that fail on the old code (mutation check below). The status stays `human_needed` only because two browser-only checks cannot be settled by grep or a test run: the token screen (now including the empty-state click path) and the public /docs page.

### Observable Truths

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | SC1: Admin creates and revokes API tokens with `time:read` / `time:write`, a Partner cannot create tokens, a token lacking the ability is refused | VERIFIED | Regression: `ApiTokenScreenTest`, `IssueApiTokenTest`, `AuthenticationTest`, `tests/Isolation/ApiAccessTest` are inside the green 961-test run below. `ListApiTokens.php` has no diff since the previous report; `ApiTokenResource` changed only in the `newToken` handler and docblock. |
| 2 | SC2: list with filters, create, read, update, delete; the API refuses what the UI refuses | VERIFIED | Regression: `TimeEntryWriteTest`, `TimeEntryListTest` green; write bound (`TimeEntryInput::MAX_DURATION_SECONDS`, `assertDurationFits`) untouched by 07-20. |
| 3 | SC3: same Idempotency-Key does not duplicate; a batch saves every valid item and reports only the failed ones | VERIFIED | Regression: `IdempotencyTest`, `TimeEntryBatchTest`, `IdempotencyConcurrencyTest` green. |
| 4 | SC4: clients, projects and tasks by UUID, key and name through read-only lookups; task by `KEY-N` | VERIFIED | Regression: `LookupTest` green; lookup code untouched. |
| 5 | SC5: rate-limit response for excess requests; published OpenAPI documentation describes every endpoint | VERIFIED | Regression: `RateLimitTest`, `ApiDocumentationTest`, `ApiDocumentationAccessTest`, `ApiDocumentationHygieneTest` green. |
| 6 | Plan must-have (07-04, 07-05, T-07-26): every refusal path is a clean 4xx, never a 500; malformed or out-of-range input is a 422 | VERIFIED | Regression: guard code unchanged, tests green. |
| 7 | Plan 07-13 truth 3 (G-07-1): a running entry's start is bounded so the Admin's entry list can never be poisoned | VERIFIED | Regression: `TimeEntry::elapsedSecondsSql()` still clamps in bigint (`LEAST(2147483647, GREATEST(0, ...))::int`), used by `scopeWithElapsedSeconds` and `TimeTotals`; 07-19 tests (`TimeEntryWriteTest`, `TimeEntryListTest`, `TimeEntryActionsTest`, `ProjectTimeOverviewTest`) green. Verified in depth with an independent probe in the previous report; no source change since. |
| 8 | Plan 07-20 truth (G-07-3, UAT test 3): the empty-state button of the token table opens the create modal, and the Admin creates a token with both abilities from it; a Partner cannot drive it | VERIFIED | `ApiTokenResource::table()` `emptyStateActions` now holds `Action::make('newToken')` whose handler runs `abort_unless(self::canAccess(), 403)` then `$livewire->replaceMountedAction('createToken')`. Filament's `callMountedAction` explicitly skips the final `unmountAction()` when the mounted stack was replaced during the call (`vendor/filament/actions/src/Concerns/InteractsWithActions.php` lines 505-511), which is the exact failure the old nested `mountAction` hit. Three behavioral tests (open and create with both abilities and read the API with the plain token; wrong password stays under the modal; cancel leaves nothing mounted) plus the forged-Partner 403 test and the pinned empty-state action test are green. Mutation check below. |

**Score:** 8/8 truths verified (0 present, behavior-unverified; 0 coincidental-reliance)

Truths 7 and 8 are behavior-dependent (state transitions of a clamped value and of the mounted-action stack). Neither was accepted on presence: each has passing behavioral tests, and for truth 8 the tests were shown to fail on the pre-fix code.

### Deferred Items

None. AP-03 (API timer) was removed from this phase on 2026-10-14 and is tracked as ROADMAP backlog item 999.1; it is not a gap of this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Filament/Resources/ApiTokenResource.php` | `newToken` empty-state action replaces the mounted stack with `createToken`, with a `canAccess()` guard | VERIFIED | exists, substantive (handler is a real closure, not a stub), wired: `createToken` is defined on `ListApiTokens::createTokenAction()` and the page type-hint (`ListApiTokens $livewire`) resolves to the running page |
| `app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php` | Unchanged: one create path, identity confirmation (password, TOTP, throttle) reused | VERIFIED | no diff since the previous report; `issueToken` still ends with `replaceMountedAction('showToken')` |
| `tests/Feature/Api/ApiTokenScreenTest.php` | G-07-3 regression tests, forged-Partner `mountTableAction('newToken')` | VERIFIED | contains the 3 G-07-3 tests, the strengthened empty-state test and the forged-call entry; green |
| `app/Domain/TimeTracking/Models/TimeEntry.php`, `Queries/TimeTotals.php` | Overflow-proof elapsed seconds (07-19) | VERIFIED | regression only |
| Earlier phase artifacts (`routes/api.php`, controllers, `ApiCursor`, `PragueDay`, idempotency, Scribe docs) | See earlier reports | VERIFIED | unchanged by 07-20, tests green |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| Empty-state `newToken` handler | `ListApiTokens::createTokenAction()` (`createToken`) | `replaceMountedAction('createToken')` | WIRED | `git grep -e '->mountAction(' -- app` is empty; no nested mount remains in `app/` |
| `createToken` form action | `IssueApiToken` via `ListApiTokens::issueToken` | same code path as the header button | WIRED | one create path; identity confirmation, throttle and field errors shared (wrong-password test shows `mountedActions.0.data.password` error) |
| `newToken` handler | `ApiTokenResource::canAccess()` | `abort_unless(..., 403)` | WIRED | forged Partner call answers 403 and writes no token (test) |
| `TimeEntry::elapsedSecondsSql` | `TimeEntryInput::MAX_DURATION_SECONDS` | constant in the `LEAST` | WIRED | regression only |
| Controllers | Domain Actions | `CreateTimeEntry` / `UpdateTimeEntry` / `DeleteTimeEntry` | WIRED | unchanged |
| Route groups | Sanctum, abilities, throttle | middleware arrays | WIRED | unchanged |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Token create modal opened from the empty state | form data to `IssueApiToken` | the same `createToken` schema and `issueToken` as the header button | Yes: the test reads back the stored row (`['time:read', 'time:write']`, `expires_at` null) and calls `GET /api/v1/time-entries` with the issued plain token (200) | FLOWING |
| Admin entry list, totals | `elapsed_seconds`, `TimeTotals` sums | clamped SQL expression | Yes | FLOWING |
| API `TimeEntryResource` | entry fields | Eloquent through Actions | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Whole phase directories | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Api tests/Isolation tests/Concurrency tests/Feature/TimeTracking` | 961 passed (6673 assertions), 0 failed | PASS |
| Mutation: the G-07-3 tests detect the old bug | temporarily replaced `replaceMountedAction('createToken')` with `mountAction('createToken')` in `ApiTokenResource.php`, ran `ApiTokenScreenTest.php --filter "G-07-3"`, then restored the file with `git checkout` | all 3 G-07-3 tests failed with `Failed asserting that null matches expected 'createToken'` (the mounted stack is empty, the exact UAT symptom); `git status` shows no change under `app/` afterwards | PASS (control) |
| No nested mount left in app code | `git grep -n -e '->mountAction(' -- app` | empty | PASS |
| Style | `ddev exec vendor/bin/pint --test` | PASS (660 files) | PASS |
| Static analysis | `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `Found 2 errors`, only the two known pre-existing `cast.string` errors in `ResetAdminTwoFactorCommand.php:38` and `StorageCheckCommand.php:33` | PASS |
| Repository hygiene | `scripts/check-sensitive.sh` on the 07-20 summary, `ApiTokenResource.php`, `ApiTokenScreenTest.php` | clean | PASS |

The real browser click (Alpine `mountAction` call on the empty-state button, modal rendering) was not exercised: no browser session or Admin TOTP credentials were used. It is carried as a human verification item.

### Probe Execution

Step 7c: SKIPPED (the phase declares no `probe-*.sh` scripts).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ---------- | ----------- | ------ | -------- |
| AP-01 | 07-01, 07-03, 07-12, 07-18, 07-20 | `/api/v1` with Sanctum tokens carrying abilities; only Admin creates tokens | SATISFIED | Truths 1 and 8 |
| AP-02 | 07-01, 07-04 to 07-07, 07-11 to 07-16, 07-19 | Time entries list, detail, create, update, delete; same domain Actions and rules as the UI | SATISFIED | Truths 2, 3, 6, 7 |
| AP-04 | 07-08, 07-12, 07-16, 07-17 | Read-only lookups of clients, projects, tasks; tasks addressable by key | SATISFIED | Truth 4 |
| AP-05 | 07-01 to 07-03, 07-09 to 07-12, 07-15 to 07-18, 07-20 | Token management UI, rate limiting, idempotency, OpenAPI documentation | SATISFIED | Truths 1, 3, 5, 8 |

All four IDs in the PLAN frontmatter (AP-01, AP-02, AP-04, AP-05) appear in REQUIREMENTS.md; no orphaned requirement (AP-03 is deferred to backlog 999.1). Bookkeeping for the orchestrator (outside this report): `.planning/REQUIREMENTS.md` still shows `- [ ] **AP-04**` (line 96) and the traceability row `AP-04 | Phase 7 | Gaps Found` (line 261), left over from the first verification. The gap behind it was closed in plans 07-08 to 07-17; set both to complete.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| 07-20 files (`ApiTokenResource.php`, `ApiTokenScreenTest.php`) | - | TBD/FIXME/XXX and stub scan | none | no markers, no stubs |
| `app/Domain/TimeTracking/Actions/StopTimer.php` | 61 | stopping an entry that ran past 2147483647 s overflows `duration_seconds` (accepted T-07-77, from the previous report) | Warning, non-blocking | not a must-have; an hourly safety-net command (`EndOverrunTimers`, quick task 261014-o13, commit 54f56d7) now ends runaway timers after 48 hours, which narrows the exposure further |
| `app/Domain/TimeTracking/Models/TimeEntry.php` | 114 | clamp does not cover an infinite `started_at` stored by raw SQL (07-19 review WR-02) | Warning, non-blocking | the application cannot store such a value |
| `app/Domain/TimeTracking/TimeEntryInput.php`, `TimeEntryBatchController.php` | 82/91, 101-105 | 07-REVIEW WR-01 (uppercase UUID) and WR-03 (all-items fault answers 422) | Warning (advisory) | not listed must-haves |

### Human Verification Required

1. **Token management screen in a browser (UAT test 3).** Test: as Admin with no tokens, click the empty-state button "Novy API token"; the create modal must open. Create a token with both abilities (password + TOTP), copy it once from the one-time modal, close it, revoke it. As Partner confirm the screen is absent. Expected: modal opens, plain token shown only once, badges and Czech labels readable. Why human: visual and UX check plus the real browser click path; the server side is proven by tests.
2. **Public /docs page (UAT test 2, visual part).** Test: open `/docs` as a guest. Expected: readable page listing every endpoint with example payloads, no Try It Out, noindex. Why human: wording and layout quality; only status, header and endpoint list were checked by curl.

### Gaps Summary

No gap remains. G-07-1 stays closed (regression green) and G-07-3 is closed: the handler swaps the mounted stack instead of nesting a mount, Filament's own call path keeps a replaced stack mounted, the regression tests fail on the old code and pass on the new, the Partner is refused with 403, and the whole phase directory run is green (961 passed). What is left is two browser-only visual checks for a human, and optional non-blocking follow-ups noted above.

Uncommitted files that were already in the working tree before this verification (`.planning/config.json`, `.planning/state.json`, `.planning/milestone.lock`, `.planning/phases/08-*` plans, `07-19-PLAN.md`, `07-UAT.md`, `composer.lock`) were not touched or staged by this verification.

---

_Verified: 2026-10-14T21:00:00Z_
_Verifier: Claude (gsd-verifier)_
