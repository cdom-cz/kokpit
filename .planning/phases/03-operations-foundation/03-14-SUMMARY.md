---
phase: 03-operations-foundation
plan: 14
subsystem: infra
tags: [queue, redis, after-commit, architecture-test, production-guard, jobs]

requires:
  - phase: 03-operations-foundation
    provides: "KokpitJob base, #[Idempotent], RunsAsSystem, RedisTestQueue, FailingProbeJob (03-13); SupplierSettings fail-closed read (03-03, 03-04)"
  - phase: 02-platform-foundation
    provides: "ProductionConfigGuard, ModelDeclaration scan style, Canary helpers"
provides:
  - "Redis queue connection dispatches after commit: a job dispatched in a transaction is invisible to workers until the commit and never pushed on rollback"
  - "QueueContractTest: base defaults (3 tries, backoff 10,60,300, timeout 60) and attribute overrides read from the real Redis payload; system-context settings read in a job"
  - "JobContractTest architecture test and JobDeclaration scanner: explicit application job list, every Dispatchable class under app/ extends KokpitJob, every concrete KokpitJob carries a non-empty #[Idempotent]"
  - "ProductionConfigGuard rule: production refuses to boot unless queue.default is exactly redis"
affects: [03-15, 03-16, phase-08-cnb-rates-pdf, phase-10-invoices, phase-11-stripe]

actuals:
  tokens: 11300
  tasks: 2
  commits: 3

plan_head_before: dfe97e60b19c25bbaaa16541449639da763231c1
plan_head_after: d2509c405d0fb76a083b40445d21b0650f770d64

tech-stack:
  added: []
  patterns:
    - "Job contract enforced by a class-file scan (JobDeclaration) with an explicit expected list, self-checks on anonymous classes and a not-vacuous guard, same shape as ModelDeclaration"
    - "Production guard checks use strict comparison: anything but the exact expected value (missing, 'Redis') counts as unsafe"

key-files:
  created:
    - tests/Feature/Operations/QueueContractTest.php
    - tests/Support/Probes/SettingsReadingProbeJob.php
    - tests/Support/JobDeclaration.php
    - tests/Arch/JobContractTest.php
  modified:
    - config/queue.php
    - app/Support/ProductionConfigGuard.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "after_commit is set on the redis connection in config (not per job in the KokpitJob constructor): one switch covers every job and the test reads the config value plus the observable behaviour"
  - "The production queue check compares strictly with 'redis'; a missing or differently spelled value is refused, like the other guard rules"
  - "Application jobs are the concrete KokpitJob subclasses found by scanning app/ (PSR-4 path to class name); the expected list is a constant in the test, empty until plan 03-16"
  - "Test-only probe jobs are exempt from the architecture rule (the scan covers app/ only); ActivityProbeJob, a Dispatchable that does not extend KokpitJob, is used as a live positive for the scanner instead"

patterns-established:
  - "Contract test pairs: a payload/behaviour test on the real backend plus an architecture scan that makes the contract unavoidable for new classes"

requirements-completed: [FND-09]

coverage:
  - id: D1
    description: "Base retry policy (maxTries 3, backoff 10,60,300, timeout 60) in the Redis payload of a KokpitJob child, and a job's own #[Tries]/#[Backoff] override it"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/QueueContractTest.php#puts the base retry policy in the payload of a pushed job; #lets a job that declares its own tries and backoff override the base defaults"
        status: pass
    human_judgment: false
  - id: D2
    description: "Redis connection dispatches after commit: invisible inside the transaction, visible after the commit, never pushed after a rollback"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/QueueContractTest.php#keeps a job dispatched inside a transaction invisible to workers until the commit; #never pushes a job whose transaction rolled back"
        status: pass
    human_judgment: false
  - id: D3
    description: "A KokpitJob without a signed-in user reads the stored supplier settings through the system context; the same read outside fails closed"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/QueueContractTest.php#reads fail-closed settings in a job without a signed-in user, while the same read outside fails closed"
        status: pass
    human_judgment: false
  - id: D4
    description: "Architecture test: expected application job list, every Dispatchable class extends KokpitJob, every concrete job declares a non-empty #[Idempotent], with self-checks, not-vacuous guards and no database access"
    requirement: FND-09
    verification:
      - kind: unit
        ref: "tests/Arch/JobContractTest.php (7 tests)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Production refuses to boot with a queue connection other than redis"
    requirement: FND-09
    verification:
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php (sync, missing, 'Redis', redis, outside production); tests/Feature/Auth/TwoFactorEnforcementTest.php#refuses to boot the application provider in production with the sync queue"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 14: Job Contract Enforcement Summary

**Redis jobs dispatch after commit, the base retry policy and the system-context read are proven on a real Redis payload, an architecture test makes the KokpitJob base and the #[Idempotent] declaration unavoidable, and production refuses to boot on any queue but redis**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-08T04:12:30Z (approximate; the plan's start was not recorded to the second)
- **Completed:** 2026-10-08T04:20:00Z
- **Tasks:** 2
- **Files modified:** 9 (4 created, 5 modified)

## Accomplishments

- `config/queue.php`: `'after_commit' => true` on the redis connection. A job dispatched inside `DB::transaction` shows queue size 0 until the commit and 1 after it; after a rollback it is never pushed.
- `QueueContractTest` reads the decoded payload from the Redis list of a unique queue: a bare `KokpitJob` child carries `maxTries 3`, `backoff "10,60,300"`, `timeout 60`; a child with `#[Tries(5)] #[Backoff(1, 2)]` carries 5 and `"1,2"` with the inherited timeout 60.
- `SettingsReadingProbeJob` dispatched on the sync connection without a user reads the stored supplier company name; the same read outside the job throws `MissingSettings`.
- `JobContractTest` plus `Tests\Support\JobDeclaration`: the concrete `KokpitJob` subclasses under `app/` must equal the explicit list (empty), every `Dispatchable` class under `app/` extends `KokpitJob`, every concrete job has a non-blank `#[Idempotent(how)]`. Self-checks cover a job without the attribute, with a blank `how`, a dispatchable outside the base and a subclass that inherits nothing. Not-vacuous guards: the `app/` scan finds `KokpitJob` and more than 20 classes, the `tests/Support/Probes` scan finds `FailingProbeJob`, `SettingsReadingProbeJob` and the non-conforming `ActivityProbeJob`. The file contains no database access.
- `ProductionConfigGuard`: production with `queue.default !== 'redis'` (sync, missing, `Redis`) throws and names `QUEUE_CONNECTION`.

## Task Commits

1. **Task 1 (tracer): after-commit redis dispatch, payload contract and system-context read** - `882223e` (feat)
2. **Task 2 (tdd): job contract architecture test and production queue guard**
   - RED `265c34c` (test) - 3 new guard cases fail on the intended assertion
   - GREEN `d2509c4` (feat) - guard rule, plus the three production boot tests that the rule affected

**Plan metadata:** recorded in the docs commit that follows this summary.

Tracer gate: auto-chain was active, so the tracer's `<verify>` was re-run end to end (`QueueContractTest`, `FailingJobFlowTest`, Pint, Larastan: 7 tests passed, clean) before expansion; it passed ("Tracer verified end-to-end - expanding").

## TDD Gate Compliance

`test(03-14)` (`265c34c`) precedes `feat(03-14)` (`d2509c4`) for Task 2. No REFACTOR commit was needed. Task 1 is a tracer (not `tdd="true"`); its tests were written together with the config change.

**RED evidence (semantic assessment), `ProductionConfigGuardTest`:** 3 of 20 tests failed, each on the planned assertion: "throws in production when the queue connection is sync", "treats a missing queue setting as not redis in production" and "refuses a queue connection that is only similar to redis" all reported `Failed asserting that exception of type "RuntimeException" is thrown.` The 17 other tests (including the new "allows redis", "allows sync outside production" and the unchanged rules) passed; no setup, import or syntax fault. The `gsd_run check tdd-red-evidence` classifier does not parse Pest output, so no `RED_EVIDENCE_OK` record exists (same as plans 03-10 and 03-13).

**Green on first run (architecture test, `QueueContractTest`):** `JobContractTest` documents behaviour that 03-13 already delivered (the base class, the attribute, the empty app job list), so its 7 tests were green when first run, and so were the 5 `QueueContractTest` tests apart from the after-commit ones (which depend on the config change made in the same tracer commit). Each is proven non-vacuous by a mutation check, every mutation failing exactly the matching tests:

| Mutation | Failing tests |
|---|---|
| `after_commit` set to false | transaction-invisibility and rollback tests |
| `RunsAsSystem` removed from `KokpitJob::middleware()` | system-context settings read test |
| `#[Tries(3)]` changed to 4 | payload defaults test |
| `#[Backoff(10, 60, 300)]` shortened | payload defaults test |
| `#[Timeout(60)]` changed to 61 | payload defaults and override tests |
| temporary `app/` job without `#[Idempotent]` | expected job list and idempotent-declaration tests |
| temporary `app/` job with blank `how` | expected job list and idempotent-declaration tests |
| temporary `app/` Dispatchable class outside the base | dispatchables-extend-base test |
| `trim()` removed from the blank check in `JobDeclaration` | self-check test |

All mutations were reverted; the temporary `app/` files were deleted.

## Files Created/Modified

- `config/queue.php` - redis connection `'after_commit' => true`
- `tests/Feature/Operations/QueueContractTest.php` - payload defaults/overrides, after-commit visibility and rollback, job-context settings read
- `tests/Support/Probes/SettingsReadingProbeJob.php` - test job recording `SupplierSettings::company_name`
- `tests/Support/JobDeclaration.php` - class-file scanner and problem reporter for the job contract
- `tests/Arch/JobContractTest.php` - the architecture rules above
- `app/Support/ProductionConfigGuard.php` - queue rule
- `tests/Unit/Support/ProductionConfigGuardTest.php` - queue cases; the shared config helper now sets `queue.default`
- `tests/Feature/Auth/TwoFactorEnforcementTest.php`, `tests/Isolation/PanelAccessTest.php` - production boot tests set the redis queue; one provider-level case for the sync queue

## Decisions Made

- **After-commit in config, not per job.** One switch on the connection covers every job, including jobs of later phases; the plan placed it in `config/queue.php` and 03-13 left the constructor untouched on purpose.
- **Strict comparison for the production queue rule.** `Redis`, a missing value or `sync` all refuse to boot, consistent with the other guard rules.
- **Scanner as a support class.** `JobDeclaration` mirrors `ModelDeclaration` (reusable by 03-16 and later job plans) instead of inlining functions in the test file.
- **Probes are out of scope of the rule** (the scan covers `app/`); `ActivityProbeJob` serves as a live example of a flagged class.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Production boot tests broke when the guard learned about the queue**
- **Found during:** Task 2 (full Pest run after the guard change)
- **Issue:** Three existing tests that boot the application in production (`TwoFactorEnforcementTest` provider test and real-process test, `PanelAccessTest` real-process test) ran with the suite's `QUEUE_CONNECTION=sync` and now correctly refused to boot.
- **Fix:** Those tests set `queue.default` to `redis` (config) or pass `QUEUE_CONNECTION=redis` to the spawned process. Added a provider-level test that production with the sync queue throws `QUEUE_CONNECTION`.
- **Files modified:** `tests/Feature/Auth/TwoFactorEnforcementTest.php`, `tests/Isolation/PanelAccessTest.php` (not in the plan's file list; the plan names Phase 2 tests the change may break)
- **Verification:** `ddev composer ci` green, 791 tests.
- **Committed in:** `d2509c4`

**2. [Rule 3 - Blocking] Scanner extracted into a support class**
- **Found during:** Task 2
- **Issue:** The plan lists only `tests/Arch/JobContractTest.php`, but the scan, the problem reporting and the probes scan need a reusable, self-checkable unit like `ModelDeclaration`.
- **Fix:** `tests/Support/JobDeclaration.php` (test support only, no production code).
- **Committed in:** `265c34c`

---

**Total deviations:** 2 auto-fixed (both blocking/structural, test code only)
**Impact on plan:** None on behaviour; no Rule 4 trigger.

## Issues Encountered

- My first mutation helper reverted the uncommitted `config/queue.php` edit through `git checkout -- <file>` after the first mutation, which confounded the next runs (the after-commit tests failed in every later mutation). Noticed from the unexpected failures, the edit was re-applied, and the mutations were re-run cleanly (results in the table above). Later mutations restored files that are committed.
- `class JobContractParentJob` and the two jobs of `QueueContractTest` are declared at file level in test files; they are not under `app/` and so outside the scan.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-03-35 (job outside system context), T-03-36 (sync queue in production) and T-03-37 (job before commit) are mitigated and covered by the tests above; T-03-SC accepted, no package added.

## User Setup Required

None - no external service configuration required. Production deployments must set `QUEUE_CONNECTION=redis` (already the `.env.example` value and the config default); the application now refuses to boot otherwise.

## Manual follow-up

- None required. When plan 03-16 adds its heartbeat job, add it to `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php`.

## Next Phase Readiness

- Plan 03-16 can add its heartbeat job as a `KokpitJob` with `#[Idempotent]`; the architecture test will list it as the first expected application job.
- Plan 03-15 can reuse the contract unchanged; the System page's queue indicators are unaffected by after-commit dispatch.

## Self-Check: PASSED

- Created files exist: `tests/Feature/Operations/QueueContractTest.php`, `tests/Support/Probes/SettingsReadingProbeJob.php`, `tests/Support/JobDeclaration.php`, `tests/Arch/JobContractTest.php` found on disk.
- Commits `882223e`, `265c34c`, `d2509c4` are ancestors of HEAD; `git rev-list --count dfe97e6..HEAD` = 3.
- `ddev composer ci` green at the last code commit: Pest 791 passed (3347 assertions), Pint 229 files, Larastan no errors, licence check 201 packages.
- Acceptance criteria of both tasks re-run: all pass (`'after_commit' => true`, `SettingsReadingProbeJob` and `10,60,300` in the queue test, `queue.default` in the guard, `Idempotent` and `FailingProbeJob` in the arch test, 0 `DB::` occurrences in the arch test, both new test files exit 0).

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
