---
phase: 04-clients-and-projects
plan: 15
subsystem: integration
tags: [ares, http-client, filament, rate-limiter, cache, company-number]

requires:
  - phase: 04-clients-and-projects
    provides: CompanyId checksum, CompanyIdRule, FictionalCompanyId and the ClientResource form with the live country input (04-14, 04-09 to 04-11)
provides:
  - AresClient, the ARES REST adapter with validated id, bounded retry, one-hour cache and a per-user limiter
  - AresCompany readonly DTO, AresFailure enum and AresLookupFailed exception
  - ARES button on the client company number (Czech clients only) that fills the registry fields and marks what changed
  - Http::preventStrayRequests() in the test base class
affects: [04-21 phase verification, Phase 8 exports (formula characters in stored ARES text)]

actuals:
  tokens: 9000
  tasks: 2
  commits: 2
plan_head_before: f371f944940a884809198ca3b8f2a2a450e2cb6c
plan_head_after: 64afb5e3b8a834a9f720f1d840e2022bbf69a5f5

tech-stack:
  added: []
  patterns:
    - "An external lookup is a service that throws one exception carrying an enum of failure reasons with a Czech message; the form turns it into a field error on the state path before writing anything"
    - "Test base class calls Http::preventStrayRequests(); a test that needs a real local service allows exactly that host (S3TestDisk)"

key-files:
  created:
    - app/Domain/Clients/Ares/AresClient.php
    - app/Domain/Clients/Ares/AresCompany.php
    - app/Domain/Clients/Ares/AresFailure.php
    - app/Domain/Clients/Ares/AresLookupFailed.php
    - tests/Feature/Clients/AresClientTest.php
    - tests/Feature/Clients/AresFormActionTest.php
  modified:
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/Pages/EditClient.php
    - config/services.php
    - lang/cs/kokpit.php
    - tests/TestCase.php
    - tests/Support/S3TestDisk.php

key-decisions:
  - "The per-user limiter is hit on every valid lookup, before the cache, so a repeated button press counts as well as a cache miss; an invalid id never counts"
  - "A missing tax number from ARES keeps the typed value and shows a warning notification (A5)"
  - "The ares_changed hint list is cleared on save of the edit page (afterSave); the create page redirects"

patterns-established:
  - "Highlight of externally filled inputs without CSS: a success hint driven by a hidden, non-dehydrated state list"

requirements-completed: [CL-04]

coverage:
  - id: D1
    description: "On a Czech client form the ARES button fills name, company number, tax number (only when returned), street, city, postal code and country, lists the changed fields in ares_changed, and leaves currency, rate, terms, language, e-mail, online payment and stage untouched"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AresFormActionTest.php#it fills only the registry fields, marks what changed and leaves the terms alone"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AresFormActionTest.php#it keeps a typed tax number when ARES returns none"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every lookup failure (checksum, not found, invalid id, server error, rate limit, malformed, unreachable) shows a Czech message on the company number and leaves every form field unchanged"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AresFormActionTest.php#it shows the Czech message on the company number and changes nothing for every lookup failure"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/AresFormActionTest.php#it shows a Czech field error and changes nothing when the number fails the checksum"
        status: pass
    human_judgment: false
  - id: D3
    description: "The ARES button is shown only while the country is CZ"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AresFormActionTest.php#it hides the ARES action while the country is not CZ"
        status: pass
    human_judgment: false
  - id: D4
    description: "AresClient validates the id before any request, retries only connection errors and 5xx (404 and 400 once), caches successes for an hour and never failures, and limits a user to ten lookups a minute; postal codes are padded and street fallbacks apply"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/AresClientTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "No test can reach the live ARES service and no recorded response is committed"
    verification:
      - kind: other
        ref: "ddev exec vendor/bin/pest (full suite, 1459 passed with Http::preventStrayRequests() in TestCase); scripts/check-sensitive.sh on the ARES test files"
        status: pass
    human_judgment: false
  - id: D6
    description: "The green Načteno z ARES hint is visible on the changed fields in the browser and the error message sits next to the company number with the network blocked"
    requirement: CL-04
    verification: []
    human_judgment: true
    rationale: "Visual highlight and blocked-network behaviour need a browser; deferred to the end-of-phase manual check (04-VALIDATION.md manual item 1)"

duration: 18min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 15: ARES lookup Summary

**Synchronous ARES registry lookup behind a button on the Czech client form: validated id, retries only for connection errors and 5xx, one-hour cache, per-user limiter, field-error-only failures and a stray-request guard for every test**

## Performance

- **Duration:** 18 min
- **Started:** 2026-10-08T14:47:00Z
- **Completed:** 2026-10-08T15:05:00Z
- **Tasks:** 2
- **Files modified:** 12

## Accomplishments
- `AresClient::lookup()` checks the id with `CompanyId::isValid()` before any URL is built, requests the configured base URL with a 3 s connect and 5 s total timeout, retries only connection errors and 5xx (a 404 or 400 is asked once), and maps 200, 404, 400, 429, other and malformed bodies to `AresFailure`. Successes are cached for an hour by company number, failures never, and a user is limited to ten lookups a minute.
- `AresCompany` is a readonly DTO: integer postal codes are padded to five digits, the street falls back to the municipality part, the municipality and the text address, and house numbers render as `domovni/orientacni`. `formState()` omits the tax number when ARES returns none.
- The `ares` suffix action on `company_number` (Czech clients only) fills only the ARES fields, records the changed ones in a hidden `ares_changed` state and shows a success hint `Načteno z ARES` on them; any failure becomes a Czech `ValidationException` on the field before anything is written.
- `Http::preventStrayRequests()` is now in `tests/TestCase.php`; the full suite (1459 tests), Pint and PHPStan are green.

## Task Commits

1. **Task 1: Tracer, ARES button fills the registry fields** - `ea6c032` (feat)
2. **Task 2: Failure modes, retry, cache and limiter tests** - `64afb5e` (test)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

_Note: Task 2 is a TDD task, but the full adapter was already required by Task 1's artifact table, so its tests passed on first run; there was no separate failing-test commit._

## Files Created/Modified
- `app/Domain/Clients/Ares/AresClient.php` - HTTP adapter with validation, retry, cache and limiter
- `app/Domain/Clients/Ares/AresCompany.php` - DTO and response mapping
- `app/Domain/Clients/Ares/AresFailure.php` - failure enum with Czech messages
- `app/Domain/Clients/Ares/AresLookupFailed.php` - exception carrying the failure
- `app/Filament/Resources/ClientResource.php` - ARES action, hints and hidden `ares_changed` state
- `app/Filament/Resources/ClientResource/Pages/EditClient.php` - clears the marks after save
- `config/services.php` - `services.ares.base_url`
- `lang/cs/kokpit.php` - button, hint, notice and error messages
- `tests/TestCase.php` - stray-request guard
- `tests/Support/S3TestDisk.php` - allows the S3 test endpoint over the Http client
- `tests/Feature/Clients/AresClientTest.php`, `AresFormActionTest.php` - adapter and form tests with invented payloads

## Decisions Made
- The limiter counts every valid lookup before the cache is consulted, so repeated presses on one number also count; an invalid id costs nothing.
- When ARES publishes no tax number the typed value stays and a warning notification says so (assumption A5).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Retry count**
- **Found during:** Task 1 (AresClient)
- **Issue:** The artifact table says `retry(2, 200, ...)`, but Laravel's first argument is the total number of tries, and the Task 2 behavior requires three requests (500, 500, 200 succeeds; three 500s fail).
- **Fix:** `retry(3, 200, when: ..., throw: false)`: up to two retries.
- **Files modified:** app/Domain/Clients/Ares/AresClient.php
- **Verification:** `AresClientTest` retry cases assert exactly three requests
- **Committed in:** ea6c032

**2. [Rule 3 - Blocking] Stray-request guard broke the storage check tests**
- **Found during:** Task 1 (full suite after adding the guard)
- **Issue:** `kokpit:storage:check` reads an object without a signature through the Http client against the local S3 test endpoint; `preventStrayRequests()` blocked it and two `StorageCheckTest` cases failed.
- **Fix:** `S3TestDisk::use()` calls `Http::allowStrayRequests()` for the configured S3 test endpoint only; every other host stays blocked.
- **Files modified:** tests/Support/S3TestDisk.php
- **Verification:** full suite green
- **Committed in:** ea6c032

**3. [Rule 2 - Missing critical] Marks cleared after save on the edit page**
- **Found during:** Task 1
- **Issue:** The plan says saving clears the list, but the edit page stays open after save and would keep the green hint.
- **Fix:** `EditClient::afterSave()` resets `ares_changed`.
- **Files modified:** app/Filament/Resources/ClientResource/Pages/EditClient.php
- **Committed in:** ea6c032

---

**Total deviations:** 3 auto-fixed (1 bug, 1 blocking, 1 missing critical)
**Impact on plan:** No scope creep; all needed for correctness of the stated behavior.

## Issues Encountered
None beyond the deviations above. The tracer's `<human-check>` (green hint in a browser, blocked-network error) was not run in this unattended session; the plan's verification section schedules it for the end of the phase and the automated tracer verify was re-run green before the second task.

## User Setup Required
None - no external service configuration required. `ARES_BASE_URL` is optional and defaults to the public ARES endpoint.

## Known Stubs
None.

## Threat Flags
None - the outbound request is the plan's T-04-28 surface (id validated before the URL is built, fixed base URL from config), covered by tests.

## Next Phase Readiness
- CL-04 is complete; only the manual visual check in 04-VALIDATION.md remains for phase verification.
- Phase 8 exports must prefix spreadsheet formula characters in stored client text (T-04-29).

## Self-Check: PASSED

All created files exist; commits `ea6c032` and `64afb5e` are in the history.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
