---
status: testing
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
source: [15-VERIFICATION.md]
started: 2026-10-27T21:21:48Z
updated: 2026-10-27T21:21:48Z
---

## Current Test

number: 1
name: Open one generated fictional receipt PDF (cs and en) from the invoice detail
expected: |
  A single-page cash receipt with the supplier signature image, the cancelled band after a cancel, and no payment
  instructions (no bank box, QR, variable symbol or pay button)
awaiting: user response

## Tests

### 1. Open one generated fictional receipt PDF (cs and en) from the invoice detail
expected: A single-page cash receipt with the supplier signature image, the cancelled band after a cancel, and no payment instructions (no bank box, QR, variable symbol or pay button)
result: [pending]

### 2. Decide WR-01: should an unlocked receipt be re-sendable / re-lockable?
expected: Either accept the current behaviour (an unlocked receipt stays open until a follow-up phase adds a resend or an explicit lock) or schedule a follow-up that adds a sanctioned resend of the payment confirmation
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
