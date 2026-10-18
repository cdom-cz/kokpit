---
status: testing
phase: 11-stripe-payments
source: [11-VERIFICATION.md]
started: 2026-10-18T18:51:50Z
updated: 2026-10-18T18:51:50Z
---

## Current Test

number: 1
name: Pay page at 375 px (cs and en, light and dark)
expected: |
  The card is readable and centred, nothing overflows horizontally, the retry control on the "preparing" state is reachable.
awaiting: user response

## Tests

### 1. Pay page at 375 px (cs and en, light and dark)
expected: The card is readable and centred, nothing overflows horizontally, the retry control on the "preparing" state is reachable.
result: [pending]

### 2. Printed pay URL in a sample issued PDF (cs and en)
expected: The stable pay URL is on one line and the "Zaplatit kartou" / "Pay by card" button is visible next to it.
result: [pending]

### 3. Card payments overview at the narrow breakpoint
expected: Columns collapse to a usable layout; amount, type and status stay readable.
result: [pending]

### 4. Diacritics in Inter (UI) and Roboto (PDF)
expected: All Czech diacritics render for "Zaplatit kartou", "Odkaz není platný", "Nespárovaná"; no fallback glyphs.
result: [pending]

### 5. Contrast of "Zaplatit kartou" and "Zkusit znovu"
expected: Meets WCAG AA in light and dark mode.
result: [pending]

### 6. Manual Stripe test-mode round trip (test keys in local .env only)
expected: Payment Link created at Stripe, pay URL redirects to it, after payment the invoice is paid with its income and the bell shows the payment notice; a resent event creates no second payment.
result: [pending]

### 7. Owner confirms ruling R11-03-1 (assumption A8)
expected: An Admin override of the per-document card switch to ON for a client whose online-payment flag is OFF takes effect; otherwise the rule becomes a strict three-way AND.
result: [pending]

### 8. Owner confirms research assumption A7
expected: Payer personal data (customer_details, customer_email, shipping_details, shipping, collected_information) is dropped from the stored webhook payload and that is sufficient for tracing a payment.
result: [pending]

## Summary

total: 8
passed: 0
issues: 0
pending: 8
skipped: 0
blocked: 0

## Gaps
