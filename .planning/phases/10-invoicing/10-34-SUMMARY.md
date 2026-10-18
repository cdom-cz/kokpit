---
phase: 10-invoicing
plan: 34
subsystem: reports
tags: [reports, billing-snapshot, reserved-time, entry-amounts, time-report]

requires:
  - phase: 10-invoicing
    provides: "EntryBilling snapshot columns (billed_rate_minor, billed_rate_currency, billed_amount_minor) and the invoice_id reservation link (plans 10-06, 10-09)"
  - phase: 08-reports
    provides: "TimeReportReader, ReportEntry, EntryAmounts, TimeReport, the dashboard UnbilledPerClient widget"
provides:
  - "EntryAmounts prices a billed entry with its billing snapshot, converting the same seconds at the snapshot rate; unbilled and legacy billed entries keep the current effective rate"
  - "ReportEntry carries billedRate, billedAmount and a reserved flag (constructor arguments with defaults)"
  - "TimeReportReader selects the snapshot columns and the reserved flag, and rateCurrencies() includes snapshot currencies"
  - "Czech report note explaining the snapshot-first pricing rule"
affects: [10-13, 10-27, 10-40]

actuals:
  tokens: 14000
  tasks: 2
  commits: 2

plan_head_before: ee93ea80ad9ad592d246d0b03d589cc4b1f68b2b
plan_head_after: dddd7d3951e2b145f1c172d5a82662dca6117f53
commits: 2

coverage:
  - deliverable: "A billed entry keeps its billed amount in the time report summary after the client rate changes; the unbilled entry follows the new rate; the totals are their sum"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/BilledSnapshotAmountsTest.php#keeps the amount of an entry billed at 1 500,00 Kč after the rate rises to 2 000,00 Kč"
        status: pass
    human_judgment: false
  - deliverable: "A foreign-rate billed entry converts to CZK at the snapshot rate and the CNB list of its work day"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/BilledSnapshotAmountsTest.php#converts a foreign-rate billed entry at the snapshot rate and the CNB list of its work day"
        status: pass
    human_judgment: false
  - deliverable: "An entry billed before snapshots existed is priced at the current rate"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/BilledSnapshotAmountsTest.php#prices an entry billed before billing snapshots existed at the current rate"
        status: pass
    human_judgment: false
  - deliverable: "Reserved time is flagged and still counts as unbilled in every figure; billed and free entries are not flagged"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/BilledSnapshotAmountsTest.php#flags a reserved entry, counts it as unbilled and leaves a billed or free one unflagged"
        status: pass
    human_judgment: false
  - deliverable: "The report page shows the rewritten pricing note"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/BilledSnapshotAmountsTest.php#explains the pricing rule of billed entries in the report note"
        status: pass
    human_judgment: false
  - deliverable: "The effective-billing parity test and the Reports, Dashboard and TimeTracking suites stay green"
    verification:
      - kind: command
        ref: "ddev exec vendor/bin/pest tests/Feature/Reports tests/Feature/Dashboard tests/Feature/TimeTracking"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Snapshot-first pricing: one private converted() helper turns (rate, amount) into the amount and the CZK counterpart, shared by the snapshot branch and the current-rate branch"
    - "Rate currencies of a report are the union of effective-rate currencies and snapshot currencies, so a billed entry never lacks its CNB list"

key-files:
  created:
    - tests/Feature/Reports/BilledSnapshotAmountsTest.php
  modified:
    - app/Domain/Reports/Queries/TimeReportReader.php
    - app/Domain/Reports/ReportEntry.php
    - app/Domain/Reports/EntryAmounts.php
    - lang/cs/kokpit.php
    - tests/Feature/Reports/TimeReportTest.php

key-decisions:
  - "The snapshot branch applies only to an entry that is billed and holds both the snapshot rate and amount; the rest (unbilled, legacy billed, fixed-price) keeps the existing rule, so no second rate chain exists"
  - "rateCurrencies() is extended with the snapshot currencies (not in the plan text) because a billed entry converts at its snapshot currency, which can differ from today's effective rate currency"
  - "Reserved needs no counting change: a reserved entry has billing_state unbilled, so every unbilled figure already includes it; the flag only exposes it to plans 10-27 and 10-40"

requirements-completed: [IN-02]

duration: 25 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 34: Snapshot-first amounts in the reports Summary

**Reports, exports and the dashboard price billed time with the rate and amount snapshotted at billing, flag reserved time while it still counts as unbilled, and the report note states the rule.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 1 created, 5 modified

## Accomplishments

- `TimeReportReader` selects the three snapshot columns and `time_entries.invoice_id IS NOT NULL AND billing_state = 'unbilled' AS reserved`; the effective billing type and rate SQL is untouched, so the parity test stays green.
- `EntryAmounts::of()` uses the stored `billedAmount` of a billed entry with a snapshot and converts the same seconds at the snapshot rate with `forDurationConverted()` at the CNB list of the work day; a CZK snapshot is its own CZK amount. Without a snapshot the previous behaviour applies. Every consumer (time report, exports, dashboard widget) goes through `EntryAmounts`, so all of them follow.
- `ReportEntry` gains `billedRate`, `billedAmount` and `reserved` with defaults; existing callers are unaffected.
- `kokpit.report.notes.computed` rewritten to the plan's wording; the one existing assertion of the old text in `TimeReportTest` was updated.
- Tests: 1 500,00 Kč billed then the rate rises to 2 000,00 Kč (billed keeps 1 500,00, unbilled 2 000,00, totals 3 500,00); EUR snapshot converts to 2 500,00 Kč and not at the raised rate; legacy billed entry priced at the current rate; reserved flag with the reserved hour counted in the unbilled sum; the page note.

## Task Commits

1. **Task 1 (tracer): snapshot-first pricing in the time report** - `a08a3bf` (feat)
2. **Task 2: foreign-rate, legacy and reserved cases, rewritten note** - `dddd7d3` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Reports tests/Feature/Dashboard tests/Feature/TimeTracking`: 853 passed, 2 skipped (pre-existing skips).
- Pint clean, PHPStan clean, `scripts/check-sensitive.sh` clean on both commits; acceptance greps pass.
- Tracer re-verified end to end before expansion (the tracer test, the parity test and the report tests).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Rate currencies must include snapshot currencies**
- **Found during:** Task 1
- **Issue:** `rateCurrencies()` listed only the currencies of the effective rates, so a billed entry whose snapshot currency differs from today's effective rate currency would have been converted without a CNB list and shown as `czk_missing`.
- **Fix:** the method now merges the distinct `billed_rate_currency` values with the effective-rate currencies.
- **Files modified:** `app/Domain/Reports/Queries/TimeReportReader.php`
- **Commit:** `a08a3bf`

**2. [Rule 3 - Blocking] Existing note assertion**
- **Found during:** Task 2
- **Issue:** `TimeReportTest` asserted the old note text, which the plan replaces.
- **Fix:** assertion updated to the new text.
- **Files modified:** `tests/Feature/Reports/TimeReportTest.php`
- **Commit:** `dddd7d3`

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking). **Impact:** none on scope.

**Note on TDD ordering:** the foreign-rate, legacy and reserved tests of Task 2 passed on first run because the Task 1 implementation already covered them; only the note test was RED before the language change.

## Known Stubs

None.

## Threat Flags

None. The reader keeps its `authorise()` (Admin and system only); no new endpoint or schema.

## Next Phase Readiness

Ready for plan 10-13 (next change of `TimeReportReader`), plan 10-27 (client unbilled figure) and plan 10-40 (the "z toho v konceptech" line), which read `ReportEntry::$reserved`.

## Self-Check: PASSED

- FOUND: tests/Feature/Reports/BilledSnapshotAmountsTest.php
- FOUND commits a08a3bf and dddd7d3 (ancestors of HEAD)
