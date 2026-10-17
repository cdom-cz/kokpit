---
phase: 10-invoicing
plan: 09
subsystem: time-tracking-billing
tags: [time-entries, billing-snapshot, reservation, storno, single-writer, activity-log]

requires:
  - phase: 10-invoicing
    provides: "time_entries.invoice_id, billed rate/amount snapshot columns and the link guard (plan 10-06)"
  - phase: 06-time-tracking
    provides: "MarkEntriesBilled, CancelEntriesBilling, TimeEntryRateResolver"
provides:
  - "EntryBilling: the single writer of billing_state, billed_at, invoice_id and the billed rate/amount snapshot of time entries (bill, unbill, reserve, unreserve, release, repoint, snapshot)"
  - "TimeEntry::invoice(), isReserved(), billedRate(), billedAmount(); invoice_id in the activity allowlist"
  - "MarkEntriesBilled skips reserved entries (skipped_reserved) and refuses a reserved-only selection"
  - "CancelEntriesBilling refuses a selection holding an invoice-billed entry, naming the invoice"
affects: [10-12, 10-13, 10-22, 10-32, 10-34]

actuals:
  tokens: 36000
  tasks: 2
  commits: 2
plan_head_before: 5cfe114a9597a8bbffd8b91c16e434bba62e3035
plan_head_after: 80c99dc1eff791b50fffeedb6063dfa45624ada7
commits: 2

coverage:
  - deliverable: "Billing snapshots the effective hourly rate and the exact-seconds amount (rounded once); a fixed-price or rate-less entry snapshots nothing; cancelling clears the snapshot; the client rate changing later does not touch it"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/BilledSnapshotTest.php"
        status: pass
    human_judgment: false
  - deliverable: "Manual bulk billing skips an entry reserved by a draft and refuses a reserved-only selection with the Czech message"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#bills the free entry of a selection and skips the one reserved by a draft"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#refuses a selection of only reserved entries and changes nothing"
        status: pass
    human_judgment: false
  - deliverable: "Cancelling billing refuses (all or nothing) a selection containing an entry billed by an issued invoice, naming the invoice; a full storno releases the entry, a partial credit note is refused by the database"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#refuses to unlock an entry billed by an issued invoice"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#releases the time of an invoice only after an issued storno credit note"
        status: pass
    human_judgment: false
  - deliverable: "reserve/unreserve round-trip, repoint keeps billed_at and the snapshot, and every writer method refuses a Partner"
    verification:
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#reserves and unreserves an entry, and repoints the billed entries of a proforma to its invoice"
        status: pass
      - kind: test
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#refuses every writer method outside the Admin and system contexts"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Single writer service taking rows the caller already locked FOR UPDATE in id order, one model save per row so each change writes one allowlisted activity row"
    - "Wrong-state rows raise LogicException in the writer; the database link guard stays the backstop for every other writer"

key-files:
  created:
    - app/Domain/TimeTracking/Billing/EntryBilling.php
    - tests/Feature/TimeTracking/BilledSnapshotTest.php
  modified:
    - app/Domain/TimeTracking/Actions/MarkEntriesBilled.php
    - app/Domain/TimeTracking/Actions/CancelEntriesBilling.php
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - lang/cs/kokpit.php
    - tests/Feature/TimeTracking/BillingLockTest.php

key-decisions:
  - "R-09-1 applied: every billing, manual or by invoice, takes the snapshot through EntryBilling::snapshot(); the billing type comes from TaskBillingResolver for a task entry, else the project billing row, else hourly, and only 'hourly' snapshots"
  - "R-09-3 applied: bulk unlock is all or nothing when an invoice-billed entry is selected; the message names the invoice with the lowest id; CancelEntriesBilling::preview() then reports 0 eligible"
  - "The reserved bucket is the last skip reason (billed, running, non-billable, reserved), so the existing reason order is unchanged"
  - "The snapshot columns are not in the activity allowlist; only invoice_id was added to it"

patterns-established:
  - "Test documents are prepared as system with raw SQL (draft insert, issue update) until the issuing Actions arrive in plan 10-12"

requirements-completed: [IN-02, IN-10]

duration: 55 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 09: One writer for the billing columns of time entries Summary

**EntryBilling is the only writer of billing state, link and the TI-08 rate/amount snapshot of time entries, and the Phase 6 billing actions now leave reserved entries to their draft and invoice-billed entries to the credit note.**

## Performance

- **Duration:** about 55 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 2 created, 5 modified

## Accomplishments

- `EntryBilling` with `bill`, `unbill`, `reserve`, `unreserve`, `release`, `repoint` and `snapshot`. Admin or system context only (AuthorizationException otherwise), rows pre-locked by the caller, one model save per row so each change writes one allowlisted history row, wrong-state rows raise a LogicException.
- Snapshot: a 45305 s entry at a client rate of 1 500,00 CZK stores rate 150000 and amount 1887708 (exact seconds, rounded once). A fixed-price project entry stores nothing. The snapshot survives a later client rate change and is cleared by unbilling and release.
- `MarkEntriesBilled` and `CancelEntriesBilling` call `EntryBilling` (constructor-injected); `TimeEntry` gains `invoice()`, `isReserved()`, `billedRate()`, `billedAmount()` and logs `invoice_id`.
- Reservation awareness: reserved entries are skipped and counted as `skipped_reserved` (also in `preview()`'s `skipped`); a reserved-only selection raises "Záznam je připojen ke konceptu faktury. Odpojte ho v konceptu." and changes nothing.
- Invoice awareness: a selection holding an entry billed by an issued invoice is refused whole with "Záznam patří k vystavené faktuře :number a nelze ho odemknout. Vystavte dobropis."; nothing changes. A full storno credit note releases the entry through `release()`; a partial credit note and no credit note are refused by the database (KP001).
- Mutation self-check: removing the invoice refusal call from `CancelEntriesBilling` makes `BillingLockTest` fail.

## Task Commits

1. **Task 1 (tracer): EntryBilling, snapshot, TimeEntry additions** - `84349a8` (feat)
2. **Task 2: reservation-aware and invoice-aware billing actions, Czech messages, tests** - `80c99dc` (feat)

## Verification

- `ddev exec vendor/bin/pest` (whole suite): 5238 passed, 3 skipped, 0 failed.
- Plan verification set (TimeTracking, Reports, Dashboard, Api): 1356 passed, 2 skipped.
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both staged sets; the tracer was re-verified end to end before Task 2 (Pest, Pint, PHPStan).

## Deviations from Plan

None - plan executed exactly as written. The result array of `MarkEntriesBilled::handle()` gained `skipped_reserved` as the plan's artifact table specifies, so two existing exact-shape assertions in `BillingLockTest` were updated to include `skipped_reserved => 0`.

## Known Stubs

None.

## Threat Flags

None. T-10-23 (manual billing of a reserved entry) and T-10-24 (unlocking an invoice-billed entry) are mitigated by the `reserved` bucket and the all-or-nothing refusal, with the database link guard as backstop; no new endpoint, auth path or file access.

## Notes for later plans

- `CancelEntriesBilling::preview()` reports 0 eligible for a selection holding an invoice-billed entry (a run would unlock nothing), so the existing bulk-action modal shows "nothing to unlock" there; the specific refusal message appears when the action is submitted. Plan 10-34 / Surface M can show the invoice message in the modal if wanted.
- Plan 10-22 can call `EntryBilling::repoint()` directly; plan 10-32 rewrites the CONTRIBUTING paragraph (R-09-2: invoicing bills through `EntryBilling`, not through `MarkEntriesBilled`).
- Plan 10-34 switches the report to the snapshot (`billedRate()` / `billedAmount()` exist for it).

## Self-Check: PASSED

Created files exist (`EntryBilling.php`, `BilledSnapshotTest.php`); commits `84349a8` and `80c99dc` are ancestors of HEAD; the commit count measured from the plan ledger is 2.
