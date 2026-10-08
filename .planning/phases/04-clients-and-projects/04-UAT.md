---
status: testing
phase: 04-clients-and-projects
source: [04-VERIFICATION.md]
started: 2026-10-08T16:39:36Z
updated: 2026-10-08T16:39:36Z
---

## Current Test

number: 1
name: ARES button on a Czech client form (browser)
expected: |
  With a fictional valid company ID the ARES-sourced fields (name, company ID, tax ID, address) fill and show the "filled" highlight; with the network blocked or an unknown ID the error appears next to the company ID field and every field stays unchanged
awaiting: user response

## Tests

### 1. ARES button on a Czech client form (browser)
expected: With a fictional valid company ID the ARES-sourced fields (name, company ID, tax ID, address) fill and show the "filled" highlight; with the network blocked or an unknown ID the error appears next to the company ID field and every field stays unchanged
result: [pending]

### 2. Partner invitation end to end through Mailpit
expected: Admin presses "Pozvat partnera" in the client detail; the mail arrives in Mailpit; the link opens the accept page showing the invitee e-mail; setting a password (12+ chars) signs the Partner in and shows only the "Moje projekty" list with visible projects of that client
result: [pending]

### 3. Password reset for Admin and Partner through Mailpit
expected: Reset mail arrives for both; after resetting, the Partner lands in the panel and the Admin still gets the 2FA challenge
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
