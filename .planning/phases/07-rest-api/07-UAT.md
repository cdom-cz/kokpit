---
status: testing
phase: 07-rest-api
source: [07-VERIFICATION.md]
started: 2026-10-14T13:50:04Z
updated: 2026-10-14T13:50:04Z
---

## Current Test

number: 1
name: Decide on the residual window of the running-entry span guard (review WR-02, plan 07-13 truth 3)
expected: |
  Either make the elapsed-seconds SQL cast in TimeEntry::scopeWithElapsedSeconds overflow-proof
  (LEAST/GREATEST clamp on a bigint), or accept the residual risk with an overrides: entry.
  Reproduced: PATCH a running entry to a start 2147483645 seconds old returns 200; a few seconds later
  the entry list query fails with SQLSTATE 22003 (integer out of range).
awaiting: user response

## Tests

### 1. Residual window of the running-entry span guard
expected: Fix the cast or accept the risk with an override (see Current Test).
result: [pending]

### 2. Public /docs page as a guest
expected: Readable page listing every endpoint with the confirmed time entry contract and example payloads, no Try It Out, noindex.
result: [pending]

### 3. Token management screen as Admin and as Partner
expected: Admin creates a token with both abilities (password + TOTP), copies it once from the modal, closes it, revokes it; status badges and Czech labels readable. A Partner does not see the screen.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
