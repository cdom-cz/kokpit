---
status: testing
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
source: [13-VERIFICATION.md]
started: 2026-10-23T22:05:30Z
updated: 2026-10-23T22:05:30Z
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  gap_snapshot: "testing::scenarios=1"
---

## Current Test

number: 1
name: Review every phase e-mail in Mailpit and real mail clients
expected: |
  Same header on all e-mails (logo at most 180 px wide and sharp, or the supplier name as text) and the same footer. The logo is not listed as an attachment and no image is broken. The amber button is readable and works. The invoice body keeps its line breaks and typed symbols. The payment confirmation shows number, amount, date and the remaining amount or the paid-in-full sentence. No Czech label appears in English e-mails. Plain-text views read cleanly with no stray backslashes. The operational alert matches the client e-mails apart from its red button.
awaiting: user response

## Tests

### 1. Review every phase e-mail in Mailpit and real mail clients
expected: See "Current Test". Trigger a card-enabled and a transfer invoice (one Czech and one English client), a partial and then the final payment (wait out the 5-minute grace window with the queue worker running), a partner invitation, a password reset, a Partner comment on a task, and an operational alert through AdminAlerter from tinker. Open them in Mailpit (ddev launch -m) and, where a test mailbox exists, in Gmail, Outlook, Apple Mail and one mobile client.
result: [pending]

## Summary

total: 1
passed: 0
issues: 0
pending: 1
skipped: 0
blocked: 0

## Gaps
