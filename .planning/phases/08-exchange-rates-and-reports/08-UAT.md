---
status: testing
phase: 08-exchange-rates-and-reports
source: [08-VERIFICATION.md]
started: 2026-10-15T06:25:24Z
updated: 2026-10-15T06:25:24Z
---

## Current Test

number: 1
name: Owner visual pass of /admin/time-report (ledger 6)
expected: |
  With a real month of entries, at desktop width and at 375px: task titles no longer wrap one word per line, the client/project/task/day-or-user levels are told apart, the tree opens only as deep as the filters ask, the Name column stays in view while scrolling sideways, toggles work with Tab/Enter/Space with a visible focus ring. The owner confirms the page is usable.
awaiting: user response

## Tests

### 1. Owner visual pass of /admin/time-report (ledger 6; todo 2026-10-14-redesign-unreadable-time-report-layout)
expected: Readable layout at desktop width and 375px, as described in Current Test.
result: [pending]

### 2. Ledger 2: /admin/time-report with a year of entries in DDEV
expected: The SPA progress bar shows while it loads and the filter strip stays usable.
result: [pending]

### 3. Ledger 3: rates overview "download today's rates" with a slowed CNB answer, light and dark mode
expected: In-flight state of the button is visible; white-on-primary contrast acceptable (UI-SPEC F-1, about 3.2:1 on Amber, below AA 4.5:1). Needs an owner decision: switch primary to Indigo or amend the spec.
result: [pending]

### 4. Ledger 4: Admin dashboard in a browser, including a client with unbilled time in two currencies
expected: One column below lg, two from lg, widgets side by side; "Fixed price" and "Missing rate" badges; one amount line per currency; running-timer text contrast acceptable.
result: [pending]

### 5. Ledger 5: request a CSV export, open the notification bell, open the CSV in a Czech spreadsheet
expected: Bell styling copes with long period text; columns split at the semicolon; decimal commas display correctly.
result: [pending]

### 6. Ledger 7: open an XLSX export in a spreadsheet application
expected: Frozen header pane, auto filter, CZK-missing comments and number formats as designed; per-currency amounts in the Summary sheet.
result: [pending]

### 7. PDF look sign-off (plan 08-06): work report of about three pages for a Czech and an English client
expected: Czech characters on every page, running header from page 2, footer "Page N / M" with generation date, billable section before non-billable.
result: [pending]

## Summary

total: 7
passed: 0
issues: 0
pending: 7
skipped: 0
blocked: 0

## Gaps
