---
status: complete
---

# Quick Task 261011-cqm: Code review of the Signal merge and test coverage check

Review-only task, no source changes. Merge commit `0f9f45a`.

## Code review

Full report: `261011-cqm-REVIEW.md` (15 findings: 2 critical, 8 warnings, 5 info). No cross-user isolation
breach found. The reviewer did not execute code, so the findings are static analysis and still need
reproduction before fixing.

- CR-01: a materialized recurring task of today cannot be deleted (re-created on next render).
- CR-02: materialized recurring tasks count twice against the daily limit, wrongly refusing category moves.
- WR-01..08: unvalidated public Livewire properties (500 on tampered dates/ids), missing advisory lock in
  settings save, template/lock race in materialization, 30-day streak cap, template vs existing-day limits.

## Test run (inside DDEV; the host cannot reach the `db` host)

Command: Signal unit/feature/schema tests plus `tests/Isolation` and `tests/Arch`: 331 passed, 2 failed.

1. `SignalTablesTest` "does not let a user with planner rows be deleted" expects SQLSTATE 23503, but the
   migration uses `restrictOnDelete()`, which Postgres reports as 23001 (see `ProjectBillingTableTest`).
   Test expectation is wrong.
2. `CanaryRegistryTest` "Admin reads the canary of both clients": `SignalTask` fixture rows are owned by a
   fresh `Canary::admin()` user per call, and `OwnerScope` limits even the Admin to own rows, so the
   acting Admin sees neither canary. Test/fixture design mismatch (reproducible, not flaky).

## Coverage

No coverage driver (Xdebug/PCOV) is installed, so coverage was assessed by mapping. Every class under
`app/Domain/Signal`, `app/Filament/Pages/Signal` and `app/Livewire/Signal` is referenced by at least one
test (Actions, Rules, Calendar, Stats, WeekdaysCast, screens, isolation, schema). Gaps, derived from the review:

- No test for deleting a materialized recurring instance (CR-01) or for the double count (CR-02).
- No tests for tampered Livewire properties (invalid dates, non-UUID ids, `0000-01-01`).
- No concurrency tests for `SaveSignalSettings` and materialization vs template deletion.
- No test for streaks longer than 30 days or for recap text length.

## Follow-ups

Fix the two failing tests, then fix CR-01/CR-02 test-first. Not done here (review-only scope).
