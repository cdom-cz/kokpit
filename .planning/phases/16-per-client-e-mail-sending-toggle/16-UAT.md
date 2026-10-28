---
status: complete
phase: 16-per-client-e-mail-sending-toggle
source: 16-01-SUMMARY.md, 16-02-SUMMARY.md, 16-03-SUMMARY.md, 16-04-SUMMARY.md, 16-05-SUMMARY.md, 16-06-SUMMARY.md
started: 2026-10-28T09:58:44Z
updated: 2026-10-28T11:14:28.044Z
---

## Current Test

[testing complete]

## Tests

### 1. Cold Start Smoke Test
expected: Kill any running server/service, clear ephemeral state, start the application from scratch. The three phase 16 migrations complete, the app boots without errors and the panel dashboard loads with live data.
result: pass

### 2. Client form and detail badge
expected: Create a client, then edit one and switch "Odesílat doklady e-mailem" off. The toggle sits next to the invoice e-mail and is on by default. The warning badge "E-maily s doklady vypnuté" appears in the detail heading only for the switched-off client. The client list has no column for it.
result: pass

### 3. Hand-served document journey in the browser
expected: For a switched-off client, issue an invoice and open its detail, the Odeslání tab and the invoice list. No send or resend action anywhere, a gray note in the detail, no send dialog after the issue. Clicking "Uzamknout" on the open callout works through its confirmation modal and the locked state shows afterwards. Record a cash payment and lock its receipt from the Platby tab the same way.
result: pass

### 4. Real worker end to end
expected: With a queue worker and Mailpit, queue a document e-mail or payment confirmation, switch the client off inside the grace window, let the worker run, then switch it on again. Nothing is delivered, the e-mail row ends "skipped", the document is not locked, there is no failure bell and nothing is revived.
result: pass

## Summary

total: 4
passed: 4
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

[none yet]
