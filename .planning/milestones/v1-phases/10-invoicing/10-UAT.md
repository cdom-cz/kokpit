---
status: complete
phase: 10-invoicing
source: [10-VERIFICATION.md]
started: 2026-10-17T21:56:10Z
updated: 2026-10-29T15:51:40.864Z
---

## Current Test

[testing complete]

## Tests

### 1. PDF print layout on 1, 15 and 60 line documents (plan 10-15 D6, VALIDATION N10)
expected: Stripes, rows never split across pages, total bar and payment box kept with content, page numbers and header row correct on a second page; QR placement and Czech diacritics ('Zalohova faktura', 'Castecne', 'Stornovano' with their real accents) render correctly
result: pass

### 2. Draft editor and items table at 375 px width (plans 10-25 D6, VALIDATION N3)
expected: The items table scrolls sideways inside its wrapper, the page never scrolls sideways, delete buttons keep 24 x 24 px, the summary stays readable
result: pass

### 3. Accent colour contrast on buttons and on the PDF (plan 10-03, VALIDATION)
expected: Button text keeps readable contrast with the configured accent colour; the Settings sample shows the measured contrast
result: pass

### 4. Nova faktura modal spinner and long client names (plan 10-21 D7, VALIDATION N2, N1, N16)
expected: Stock loading spinner shows while the count recomputes on a period change; an 80-character client name wraps or truncates with stock behaviour in the list and the Partner list at the narrow breakpoint
result: pass

### 5. Client detail stats row, dashboard card Nezaplacene faktury and narrow-screen footers (plans 10-27 D6, 10-28 D5)
expected: Multi-line descriptions with the report link, danger colour of the overdue stat, three stats side by side, warning icon and collapsed header on narrow screens read well in light and dark mode
result: pass

### 6. Issued detail banners, sections and tabs, and synchronous PDF render of a 60-line invoice (plan 10-29 D8, VALIDATION N10)
expected: Order follows the UI-SPEC in light and dark mode; the render finishes within the request without a visible stall
result: pass

### 7. Payment modal and credit dialog layout (plans 10-30 D7, 10-31 D6)
expected: Warning callout, the 'cannot be edited, only deleted' note, and the credit table repeater (checkbox, quantity and amount columns, live total) read well in light and dark mode
result: pass

### 8. E-mail dialog with a long edited text (plan 10-31 D5, VALIDATION N9)
expected: A body of many lines (up to 4 000 characters) keeps the dialog scrollable and the submit button reachable
result: pass

### 9. Amended UI-SPEC and complete Czech screen copy (plan 10-21 D6)
expected: The amended 10-UI-SPEC.md says what the phase actually built and the Czech copy in lang/cs/invoices.php reads naturally
result: pass

### 10. Owner confirmation R-07-1: proforma numbers must be digits-only
expected: The owner accepts that the proforma number pattern is narrowed to digits-only (so the variable symbol can differ from the invoice series), which narrows Phase 3 decision D-05
result: pass

### 11. Owner confirmation R-17-1 / R-01-1: once-rounded converted line amount
expected: The owner accepts that, on a foreign-currency line converted at issue, the line amount is rounded once from the exact conversion and may differ by one minor unit from quantity x printed unit price (the database CHECK allows a bound of abs(quantity)/2 + 0.5 for converted lines)
result: pass

### 12. Owner confirmation: reworded issue-confirmation callout (plan 10-21)
expected: The owner accepts the reworded confirmation callout shown before Vystavit
result: pass

### 13. Owner decision on the code review warnings WR-01, WR-02, WR-03 and WR-04 (re-confirmed by this verification, see Anti-Patterns and Gaps Summary)
expected: Decide to close them in a gap-closure plan before Phase 11 or to accept and record them: WR-01 payment recordable on an already converted proforma (no income booked); WR-02 storno dialog swallows validation errors; WR-03 time billed by an invoice fully credited through partial credit notes (and by an unpaid issued proforma) can never be released; WR-04 a CZK document of 10 000 000,00 or more cannot be issued
result: pass

## Summary

total: 13
passed: 13
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
