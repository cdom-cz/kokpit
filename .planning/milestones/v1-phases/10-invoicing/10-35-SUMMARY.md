---
phase: 10-invoicing
plan: 35
subsystem: invoicing
tags: [concurrency, gap-free-numbering, time-reservation, mutation-twin, pest]

requires:
  - phase: 10-invoicing
    provides: "IssueDocument and the issue pipeline (plan 10-12); CreateDraft and EntryReservation (plan 10-13); InvoiceFixtures (plans 10-11, 10-12)"
  - phase: 02-foundation
    provides: "SequenceAllocator with the row lock, the concurrency harness (barrier, worker processes, mutation run)"
provides:
  - "Proof that the invoice series stays contiguous, gap-free and duplicate-free when 8 processes issue at once and every third issue fails after its number was allocated"
  - "Proof that 4 processes creating drafts from the same 24 time entries at once leave every entry in exactly one draft with line seconds equal to the held seconds"
  - "Two mutation twins (allocator without the row lock, claim without the lock) that make their own harness fail"
  - "FailingAfterAllocationSequenceAllocator failure seam"
affects: [10-14, 10-15, 10-16, 10-40]

actuals:
  tokens: 11300
  tasks: 2
  commits: 2

plan_head_before: 11c73f523628a89f0ca824dbb7df94c3a5772d4b
plan_head_after: 8c52c8d8fcea8847287a0facba488f0c7e1bb51c
commits: 2

coverage:
  - deliverable: "Under 8 parallel issuing processes with failing issues mixed in, the issued numbers are contiguous from the start of the series, no number is duplicated, every failed draft is a draft without a number and the counter continues at the next number"
    verification:
      - kind: test
        ref: "tests/Concurrency/InvoiceIssueConcurrencyTest.php#keeps the issued numbers contiguous when 8 processes issue at once and every third issue fails after its number was allocated"
        status: pass
    human_judgment: false
  - deliverable: "The same issue harness run with the allocator without the row lock reports a defect (unique violations on the number)"
    verification:
      - kind: test
        ref: "tests/Concurrency/InvoiceIssueConcurrencyTest.php#detects the defect when the allocator has no row lock (mutation run with UnlockedSequenceAllocator)"
        status: pass
    human_judgment: false
  - deliverable: "Four processes creating drafts from the same 24 committed entries end with every entry in exactly one draft and the seconds on the lines of each draft equal to the seconds of the entries it holds"
    verification:
      - kind: test
        ref: "tests/Concurrency/ReservationConcurrencyTest.php#puts every entry in exactly one draft when 4 processes create drafts from the same 24 entries at once"
        status: pass
    human_judgment: false
  - deliverable: "The same reservation harness run with the claim that reads without a lock reports a defect (link-guard refusal in the losing workers)"
    verification:
      - kind: test
        ref: "tests/Concurrency/ReservationConcurrencyTest.php#detects the defect when the claim reads without a lock (mutation run with UnlockedEntryReservation)"
        status: pass
    human_judgment: false
  - deliverable: "Both tests clean up only their own fictional rows and leave every guard trigger enabled"
    verification:
      - kind: test
        ref: "afterEach cleanup assertions in both concurrency test files (rows left plus triggers not enabled must be 0); each file run twice in a row"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "Concurrency proof = real worker processes behind a barrier + a mutation twin that must make the same harness report a defect, in rounds until it does"
    - "Cleanup of guarded invoice tables: ALTER TABLE ... DISABLE TRIGGER USER, delete the own client's rows, ENABLE TRIGGER USER inside one transaction, then assert pg_trigger shows every non-internal trigger enabled"
    - "Settings snapshot and restore for tests that must commit supplier and bank settings the workers can read"

key-files:
  created:
    - tests/Support/FailingAfterAllocationSequenceAllocator.php
    - tests/Concurrency/invoice-worker.php
    - tests/Concurrency/InvoiceIssueConcurrencyTest.php
    - tests/Support/UnlockedEntryReservation.php
    - tests/Concurrency/reservation-worker.php
    - tests/Concurrency/ReservationConcurrencyTest.php
  modified:
    - app/Domain/Invoicing/Support/EntryReservation.php

key-decisions:
  - "EntryReservation is no longer final (still readonly), like SequenceAllocator, so the worker can bind the unlocked twin; CreateDraft type-hints the class, so the twin has to be a subclass"
  - "The invoice counter key is global (invoice:<year>), not per test, so the test reads the counter before, expects numbers from that start, and puts the counter row back afterwards"
  - "The mutation runs retry up to 3 fresh rounds like the board harness; both detected in the first round in every observed run"
  - "UnlockedEntryReservation sleeps 50 ms between its unlocked read and the reservation to widen the race window; the production class gets no hook"

requirements-completed: [IN-01, IN-02]

duration: 40 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 35: Concurrency proofs of the invoicing core Summary

**Gap-free numbering at issue (8 real processes, failures after allocation mixed in) and race-free reservation of time (4 processes, same 24 entries) are proven against PostgreSQL, each with a mutation twin that makes its own harness fail.**

## Performance

- **Duration:** about 40 min
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 6 created, 1 modified

## Accomplishments

- `FailingAfterAllocationSequenceAllocator` extends the real allocator, so the row lock stays; it throws after the number was taken and the counter advanced for the configured call numbers. The issue worker uses call numbers 3 and 6 of its 6 drafts.
- `invoice-worker.php` binds the allocator variant (`locked`, `failing`, `unlocked`), signs in the Admin, waits for the barrier and prints one JSON line per draft. The test issues 8 x 6 committed drafts of one fictional client: 32 are issued, 16 fail after allocation and remain drafts without a number; the issued numbers are exactly the contiguous run from the start of the series (composed with the stored pattern), none is duplicated and the counter continues right after the last number.
- Mutation run with `UnlockedSequenceAllocator`: every worker accounts for all its drafts and the harness reports unique violations on `invoices_kind_number_unique` (observed in every run).
- `reservation-worker.php` binds the reservation variant and creates one draft from the unbilled time without a project. Four workers over the same 24 entries: all entries end in drafts, the seconds on the lines of each draft equal the `duration_seconds` of the entries it holds and the total equals the 24 entries.
- Mutation run with `UnlockedEntryReservation`: the losing workers are refused by the time-entry link guard (KP001), reported as crashed workers (observed in every run).
- Cleanup: the issue test opens the guarded invoice tables with `DISABLE TRIGGER USER` inside one transaction, deletes its own client's rows, re-enables, and `afterEach` asserts that no non-internal trigger of the invoice tables or `time_entries` is disabled; the supplier and bank settings and the counter row are restored. The reservation test releases the entries, deletes drafts and entries. Both files were run twice in a row; the test database holds no invoice, user, client, time entry, counter or role row afterwards.

## Task Commits

1. **Task 1 (tracer): gap-free issue under 8 processes with failures, plus the no-lock mutation** - `d53a395` (test)
2. **Task 2: race-free reservation under 4 processes, plus the unlocked-claim mutation** - `8c52c8d` (test)

## Verification

- `ddev exec vendor/bin/pest tests/Concurrency/InvoiceIssueConcurrencyTest.php` and `.../ReservationConcurrencyTest.php`: green, twice in a row each.
- `ddev exec vendor/bin/pest tests/Concurrency`: 22 passed.
- Full suite `ddev exec vendor/bin/pest --compact`: 5408 passed, 3 skipped (pre-existing skips), 0 failed.
- Pint clean, PHPStan clean (978 files), `scripts/check-sensitive.sh` and the lefthook hooks clean on both commits; acceptance greps pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] EntryReservation was `final readonly`, so no twin could be bound**
- **Found during:** Task 2
- **Issue:** `CreateDraft` type-hints `EntryReservation`; a class that is not a subclass cannot be injected, and a final class cannot be extended. The plan asks for a container binding of the unlocked twin in the worker.
- **Fix:** removed `final` (kept `readonly`) and documented why in the class docblock, the same approach the plan names for `SequenceAllocator`. No application code extends it.
- **Files modified:** `app/Domain/Invoicing/Support/EntryReservation.php`
- **Commit:** `8c52c8d`

**2. [Rule 1 - Bug in own test] Settings rows are invisible to the parent process**
- **Found during:** Task 1 first run
- **Issue:** reading the numbering pattern in the test process threw `MissingSettings`, because settings rows are Admin and system only and the parent has no signed-in user.
- **Fix:** the test reads the pattern inside `PartnerContext::runAsSystem()`.
- **Commit:** `d53a395`

**Total deviations:** 2 auto-fixed (1 blocking, 1 own bug). **Impact:** one production class lost `final`; nothing else outside the test tree changed.

## Known Stubs

None.

## Threat Flags

None. The tests add no endpoint, auth path or schema; they disable triggers only inside the test cleanup transaction on the dedicated `_test` database (the workers refuse any other database).

## Self-Check: PASSED

- Created files exist: all six test files and the modified `EntryReservation.php` verified on disk.
- Commits `d53a395` and `8c52c8d` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run and passing.
