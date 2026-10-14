---
status: complete
phase: 07-rest-api
source: [07-VERIFICATION.md]
started: 2026-10-14T13:50:04Z
updated: 2026-10-14T18:33:54.292Z
---

## Current Test

[testing complete]

## Tests

### 1. Residual window of the running-entry span guard
expected: Fix the cast or accept the risk with an override.
result: issue
reported: "Reproduced by the verifier and left open: PATCH a running entry to a start 2147483645 s old returns 200; seconds later EXTRACT(EPOCH ...)::int in TimeEntry::scopeWithElapsedSeconds overflows (SQLSTATE 22003) and the Admin entry list fails. Plan 07-13 truth 3 says the list can never be poisoned."
severity: major

### 2. Public /docs page as a guest
expected: Readable page listing every endpoint with the confirmed time entry contract and example payloads, no Try It Out, noindex.
result: pass
source: automated (curl against the local DDEV site, no browser)
evidence: GET /docs answers 200 to a guest with X-Robots-Tag noindex, nofollow; the page has no Try It Out; all 12 endpoints are listed (clients, projects, tasks lookups; time entries list, show, create, batch, update, delete); /docs.openapi answers 200. Wording and layout were not judged visually. The spec server URL is the example.com placeholder because the docs tests regenerate the local output (review IN-04).

### 3. Token management screen as Admin and as Partner
expected: Admin creates a token with both abilities (password + TOTP), copies it once from the modal, closes it, revokes it; status badges and Czech labels readable. A Partner does not see the screen.
result: issue
reported: "Clicking the 'Nový API token' button in the empty state of the token table does nothing. The modal does not open."
severity: major
prior_skip_reason: Behaviour verified by ApiTokenScreenTest, IssueApiTokenTest and ApiAccessTest (56 passed). Visual judgement of the modal and badges needs a logged-in browser session as Admin with TOTP; no credentials were used, so presentation was not checked.

## Summary

total: 3
passed: 1
issues: 2
pending: 0
skipped: 0
blocked: 0

## Gaps

- gap_id: G-07-1
  truth: "The running-entry span guard keeps the Admin entry list readable for any accepted start"
  status: resolved
  resolved_by: 07-19-PLAN.md
  resolved_at: 2026-10-14
  reason: "Guard checks the start against now only at edit time; the elapsed-seconds ::int cast overflows later."
  severity: major
  test: 1
  artifacts:
    - path: app/Domain/TimeTracking/Models/TimeEntry.php
      issue: "scopeWithElapsedSeconds casts EXTRACT(EPOCH ...) to int"
  missing:
    - "Overflow-proof elapsed seconds (bigint with LEAST/GREATEST clamp to 2147483647)"

- gap_id: G-07-3
  truth: "Admin creates a token with both abilities from the token screen; the create modal opens from the table empty state"
  status: resolved
  resolved_by: 07-20-PLAN.md
  resolved_at: 2026-10-14
  reason: "User reported: clicking the 'Nový API token' button in the empty state of the token table does nothing, the modal does not open."
  severity: major
  test: 3
  root_cause: "The empty-state action 'newToken' is a table action whose handler calls mountAction('createToken'); Filament unmounts the finished newToken action afterwards, so the create modal never stays open. No test covers the empty-state button."
  artifacts:
    - path: app/Filament/Resources/ApiTokenResource.php
      issue: "emptyStateActions 'newToken' nests mountAction inside an action handler"
  missing:
    - "Open createToken from the empty state without a nested action mount (replaceMountedAction or a wire click on mountAction)"
    - "Livewire test that clicks the empty-state button and asserts the createToken modal is mounted"
  debug_session: ""
