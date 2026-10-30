---
status: testing
phase: 12-partner-audit
source: [12-VERIFICATION.md]
started: 2026-10-24T06:10:57Z
updated: 2026-10-24T06:10:57Z
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  gap_snapshot: "testing::scenarios=2"
---

## Current Test

number: 1
name: Hosted CI run of the Partner audit step
expected: |
  After a pull request is opened (or a push to main), the hosted Hygiene run shows the "Pest" step (running with --exclude-testsuite=Audit) and the "Partner audit" step (running --testsuite=Audit, listing the four Audit test files) both green, and "CI Passed" green. Note the duration.
awaiting: user response

## Tests

### 1. Hosted CI run of the Partner audit step
expected: In the job "Tests (PostgreSQL 18)" the steps "Pest" and "Partner audit" are green, the four Audit files are listed, "CI Passed" is green; duration noted.
result: [pending]

### 2. Owner decision on review warnings WR-01, WR-02 and IN-05
expected: The owner decides to fix WR-01 (policy completeness against Gate::policies()) and WR-02 (unprobed abilities) in a follow-up plan, or to accept them with a written reason and soften the README/CONTRIBUTING wording ("proves a Partner reaches nothing without a declared rule").
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
