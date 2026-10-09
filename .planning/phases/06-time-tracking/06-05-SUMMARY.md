---
phase: 06-time-tracking
plan: 05
subsystem: time-tracking
tags: [laravel, postgres, domain-actions, row-lock, activity-log, guard-trigger, rate-resolution, pest]

requires:
  - phase: 06-time-tracking
    provides: "time_entries table with the frozen-row guard trigger, UpdateTimeEntry and DeleteTimeEntry with the billed lock, TimerClock, TimeEntryInput (plans 06-01 to 06-04)"
  - phase: 05-tasks
    provides: "TaskBillingResolver and BillingSource (task, parent task, project, client rate order)"
provides:
  - "MarkEntriesBilled: bulk billing of the eligible entries of a selection under row locks, with counts by skip reason and a read-only preview()"
  - "CancelEntriesBilling: the only way back from billed to unbilled, with a read-only preview()"
  - "TimeEntry activity allowlist (eight attributes, never the description) and Czech history labels"
  - "TimeEntryRateResolver, EntryRate, RateSource: the effective hourly rate of any entry with its source, Admin and system only"
  - "TimeEntryInput::uuids: the shared cleaning of an untrusted id selection"
affects: [06-06, 06-07, 06-08, 06-09, phase-07-api, phase-10-invoicing]

tech-stack:
  added: []
  patterns:
    - "Bulk state changes save one model at a time inside one transaction (rows FOR UPDATE in id order), so each row writes an activity row; never a bulk query update"
    - "Eligibility is decided once in a private bucketing method shared by handle() and preview(), so the confirmation modal and the write cannot disagree"
    - "A rate-bearing resolver refuses callers that are neither the Admin nor a system run, in the resolver itself"

key-files:
  created:
    - app/Domain/TimeTracking/Actions/MarkEntriesBilled.php
    - app/Domain/TimeTracking/Actions/CancelEntriesBilling.php
    - app/Domain/TimeTracking/Billing/TimeEntryRateResolver.php
    - app/Domain/TimeTracking/Billing/EntryRate.php
    - app/Domain/TimeTracking/Billing/RateSource.php
    - tests/Feature/TimeTracking/BillingLockTest.php
    - tests/Feature/TimeTracking/EntryRateResolverTest.php
  modified:
    - app/Domain/TimeTracking/Models/TimeEntry.php
    - app/Domain/TimeTracking/TimeEntryInput.php
    - tests/Arch/ActivityAllowlistTest.php
    - lang/cs/kokpit.php

key-decisions:
  - "A skipped entry is counted once under the first applicable reason: already billed, then running, then non-billable (a running non-billable entry counts as running)."
  - "Zero-length finished billable entries are eligible for billing."
  - "Both Actions authorize create on the model class first (only the Admin passes the admin-only policy) and then update on each locked row; a Partner is refused before anything is read or written."
  - "The rate sources have their own labels under kokpit.time.rate_source.* (UI-SPEC wording); the task page keeps its enums.billing_source.* labels unchanged."
  - "The global default rate applies only below every other level and only when its currency equals the client's currency; otherwise the result is an empty EntryRate (null rate, null source)."
  - "A rate of zero is a value and stops the search at its level."
  - "No money is stored on a time entry; the rate and amount snapshot at billing is Phase 10."

patterns-established:
  - "Activity assertions in tests read activity_log rows with event 'updated' for the subject ids, because the factory create also writes a 'created' row once the model logs"
  - "A raw-SQL refusal in a test runs inside DB::transaction (a savepoint) so the RefreshDatabase transaction survives the aborted statement"

requirements-completed: [TI-05, TI-08]

coverage:
  - id: D1
    description: "MarkEntriesBilled bills every eligible entry (finished, billable, unbilled) of a selection in one transaction, sets billed_at to the frozen instant, and reports the billed count, their exact seconds and the skipped counts by reason"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#bills a selection, locks it against edit, delete and raw SQL, and unlocks it only by cancelling"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#bills only the eligible entry of a mixed selection and counts each skipped reason"
        status: pass
    human_judgment: false
  - id: D2
    description: "A billed entry cannot be edited or deleted by UpdateTimeEntry, DeleteTimeEntry or raw SQL (KP001) until billing is cancelled; CancelEntriesBilling is the only way back and an edit then succeeds"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#bills a selection, locks it against edit, delete and raw SQL, and unlocks it only by cancelling"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#unlocks only the billed entries of a mixed selection"
        status: pass
    human_judgment: false
  - id: D3
    description: "Each billed or unbilled entry writes one activity row through a model save with only allowlisted attributes; the description is never logged"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#writes one allowlisted history row per entry and per action, never the description"
        status: pass
      - kind: arch
        ref: "tests/Arch/ActivityAllowlistTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "Stale and forged selections change nothing they should not: nothing eligible raises the Czech DomainException with no change and no history row, malformed and unknown ids are ignored, a duplicate counts once, an entry billed meanwhile counts as already billed, a Partner is refused, and the billed_finished CHECK refuses a non-billable or running entry for any writer"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#refuses a selection with nothing to bill and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#counts an entry billed by another request after the page loaded as already billed"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#refuses a Partner on both Actions and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#keeps the billed_finished check as the backstop for every writer"
        status: pass
    human_judgment: false
  - id: D5
    description: "preview() on both Actions reports the same eligibility and exact seconds as the write, read-only, so the confirmation modal (plan 06-07) cannot disagree with the action"
    requirement: "TI-05"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#previews the same selection with its exact seconds and writes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/TimeTracking/BillingLockTest.php#previews a cancel with the billed entries and their seconds and writes nothing"
        status: pass
    human_judgment: false
  - id: D6
    description: "TimeEntryRateResolver resolves the effective hourly rate of a task, project-only and client-only entry with its source (task, parent task, project, client), applies the global default only below all levels and in the client's currency, and still resolves archived tasks, projects and clients"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/EntryRateResolverTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "The rate resolver refuses a Partner, a guest and a user without a role with AuthorizationException and allows the Admin and a system run, so no rate reaches a Partner"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/TimeTracking/EntryRateResolverTest.php#who may ask"
        status: pass
    human_judgment: false
  - id: D8
    description: "Every RateSource case has a Czech label (the app-wide enum label scan passes)"
    requirement: "TI-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Localisation/EnumLabelsTest.php"
        status: pass
    human_judgment: false

actuals:
  tokens: 11700
  tasks: 3
  commits: 3
plan_head_before: 93d409a63e9e2bb31f4de524c28621af0b87e1a4
plan_head_after: 9781c53515115dfdd02ffffb7ed2de96b8b75b10
commits: 3

duration: 10min
completed: 2026-10-09
status: complete
---

# Phase 6 Plan 05: Billing state and rate resolution Summary

**Bulk "mark as billed" and "cancel billing" as domain Actions that lock rows in id order and write one allowlisted history row per entry, plus a rate resolver that gives the effective hourly rate of any entry with its source (task, parent task, project, client, default), Admin and system only.**

## Performance

- **Duration:** about 10 min
- **Completed:** 2026-10-09
- **Tasks:** 3 (1 tracer, 2 tdd-flagged auto)
- **Files:** 11 (7 created, 4 modified)

## Accomplishments

- `MarkEntriesBilled::handle()` authorizes `create` on the model class (only the Admin passes), then in one transaction reads the selection `FOR UPDATE` ordered by id, authorizes `update` on each row, buckets the rows once (eligible, billed, running, non-billable) and saves each eligible row as a model with `billing_state` billed and `billed_at` at the frozen second. It returns `billed`, `billed_seconds` and the three skip counts. A selection with nothing eligible raises the Czech `nothing_to_bill` DomainException and changes nothing.
- `CancelEntriesBilling::handle()` is the exact inverse and the only way back; the existing guard trigger allows exactly the flip of the two billing columns. Nothing billed raises `nothing_to_unbill`.
- Both Actions have a read-only `preview()` over the same private bucketing, for the confirmation modal of plan 06-07.
- `TimeEntry` now logs eight allowlisted attributes through `LogsAllowlistedActivity`; the description and `long_running_notified_at` are never logged. Czech subject and attribute labels were added, and `ActivityAllowlistTest` expects `TimeEntry::class`.
- `TimeEntryInput::uuids()` drops non-uuid values, lowercases and de-duplicates an untrusted selection.
- `TimeEntryRateResolver` resolves the three entry shapes (task through `TaskBillingResolver`, project-only from the project billing row then the client, client-only from the client). The global default counts only below all levels and only in the client's currency. A zero rate stops the search. Archived task, project and client still resolve. Partner, guest and role-less users are refused with `AuthorizationException`.
- Tests: 15 in `BillingLockTest` (2 tracer, 13 eligibility), 17 in `EntryRateResolverTest`. Full suite 2219 passed (16021 assertions), including `TimerConcurrencyTest` (its cleanup already removed `time_entry` activity rows). Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): billing Actions, activity allowlist, tracer lock test** - `a8728ff` (feat)
2. **Task 2: eligibility, skipped counts and stale selections** - `b39283b` (test)
3. **Task 3: effective hourly rate with its source** - `9781c53` (feat)

Tracer feedback gate: the tracer `<verify>` is automated-only and the human-verify mode is end-of-phase, so the verify (four Pest files, Pint, PHPStan) was run end to end and passed before expansion.

## TDD note

The Task 2 behaviours were already satisfied by the Task 1 implementation, which was written with the bucketing and preview in place from the start, so the Task 2 tests passed on first run and the commit contains tests only. To prove the tests can fail, one mutation was run: removing the `create` authorization from `CancelEntriesBilling` made the Partner test fail, and the file was restored. Task 3 tests were written before the resolver files existed, and one test bug (a scoped-instance reset dropping the system flag) was fixed in the test before commit.

## Decisions Made

See `key-decisions`. The `billed_at` instant is `TimerClock::now()` taken once per run, so every entry of a selection carries the same second.

## Deviations from Plan

### Auto-fixed Issues

None for the production code.

### Plan additions (within scope)

- `TimeEntryInput::uuids()` was added (the plan did not name `TimeEntryInput` in `files_modified`) so both Actions and later callers share one cleaning of an untrusted selection instead of duplicating it.
- The `QueryException` translation that Update and Delete carry was not added to `MarkEntriesBilled`: it can only bill rows that are unbilled under the lock, so the frozen-row trigger cannot fire.
- Task 2 is a tests-only commit (see the TDD note).

**Total deviations:** 0 auto-fixed, 2 small in-scope additions.
**Impact:** none on scope.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None. The register is covered: T-06-12 (rows re-read under `lockForUpdate()` in id order, eligibility rechecked on the locked rows, `time_entries_billed_finished_check` tested by raw SQL for a running and a non-billable entry), T-06-13 (resolver refuses Partner, guest and role-less user; tested), T-06-14 (one allowlisted activity row per entry and per action, description absent, tested), T-06-SC (no package added).

## Requirements

`requirements-completed` copies the plan frontmatter. TI-05 was already ticked in REQUIREMENTS.md and is now fully delivered at the domain level (the bulk action buttons are plans 06-06 and 06-07). TI-08 stays open: the rate resolution is delivered here, but its text also names the rate and amount snapshot on billing, which ROADMAP assigns to Phase 10, and the entry screens that show the rate are later plans.

## Next Phase Readiness

Ready for 06-06. The entry resource and bulk actions (06-06, 06-07) call `MarkEntriesBilled::handle()` and `CancelEntriesBilling::handle()` and the two `preview()` methods for their modals, catch the `DomainException` of the nothing-to-do case and show its message as a danger toast, and use `TimeEntryRateResolver::resolve()` on the view page with `EntryRate::$source->getLabel()` (a null rate shows a dash). Phase 10 adds the snapshot columns and must re-create the guard trigger with the extended mutable column list.

## Self-Check: PASSED

All seven created files exist, commits `a8728ff`, `b39283b` and `9781c53` are ancestors of HEAD, all task acceptance criteria re-run green, and the plan-level verification (full `vendor/bin/pest` 2219 passed, Pint, PHPStan, `scripts/check-sensitive.sh`) passes.
