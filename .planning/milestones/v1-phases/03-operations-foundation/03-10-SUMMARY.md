---
phase: 03-operations-foundation
plan: 10
subsystem: audit
tags: [activitylog, spatie, audit-trail, allowlist, queue-events, postgres-check]

requires:
  - phase: 02-platform-foundation
    provides: "Activity model (UUID, DeniesPartners), MorphMap, KokpitModel, PartnerContext, Canary and probe test conventions"
provides:
  - "#[LoggedAttributes] class attribute and LogsAllowlistedActivity wrapper trait: a model logs only the attributes it declares, no option can widen the list"
  - "ActivitySourceLabel enum, ActivitySource singleton and KokpitLogActivityAction: every activity row carries web, console, job or webhook"
  - "activity_log.source column (nullable string 16, CHECK, index)"
  - "OperationsServiceProvider (queue listeners, ActivitySource binding) for plans 03-13, 03-15 and 03-16 to extend"
  - "ActivityProbe and ActivityProbeJob test fixtures for the plan 03-11 architecture test"
affects: [03-11, 03-13, 03-15, 03-16, phase-04-tasks-projects, phase-06-time-entries, phase-10-invoices]

actuals:
  tokens: 7300
  tasks: 2
  commits: 4

plan_head_before: 424dbb6c4f4de07a47bde8def73519807ae38c8d
plan_head_after: 29e57367ee5adb3a6db0f1c85739a15f5f600db7

tech-stack:
  added: []
  patterns:
    - "Allowlist as a class attribute read by reflection (same style as #[NotPartnerScoped]); each concrete model declares its own"
    - "Wrapper trait fixes the package options so the safe behaviour is the only behaviour; missing configuration throws instead of logging everything"
    - "Execution-context label held by a container singleton with an injectable detector, entered and left by queue events"

key-files:
  created:
    - app/Domain/Audit/LoggedAttributes.php
    - app/Domain/Audit/LogsAllowlistedActivity.php
    - app/Domain/Audit/ActivitySourceLabel.php
    - app/Domain/Audit/ActivitySource.php
    - app/Domain/Audit/KokpitLogActivityAction.php
    - app/Providers/OperationsServiceProvider.php
    - database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php
    - tests/Support/Probes/ActivityProbe.php
    - tests/Support/Probes/ActivityProbeJob.php
    - tests/Feature/Operations/ActivityLogBehaviourTest.php
    - tests/Feature/Operations/ActivitySourceTest.php
  modified:
    - bootstrap/providers.php
    - config/activitylog.php
    - lang/cs/enums.php

key-decisions:
  - "LogsAllowlistedActivity carries a documented phpstan-ignore trait.unused, the Phase 2 convention for a trait whose first real user arrives in a later phase"
  - "The source column stays nullable (rows written outside the application, such as imports); the application always sets it and a test asserts no written row is null"
  - "A leaving queue event is JobProcessed or JobExceptionOccurred only; JobFailed is not listened to because it always follows JobExceptionOccurred and would double-leave"
  - "ActivityProbeJob is an extra test fixture (not in the plan file list): a sync-queue job cannot be an anonymous class because the payload is serialised"

patterns-established:
  - "Allowlist test pattern: runtime canary strings in non-allowlisted columns, then search the raw JSON of the whole activity_log table"
  - "Context simulation: app()->instance(ActivitySource::class, new ActivitySource(fn () => false)) turns a console test process into a web request"

requirements-completed: [FND-08]

coverage:
  - id: D1
    description: "A model logs only attributes declared with #[LoggedAttributes] through LogsAllowlistedActivity: create, update and delete write allowlisted attributes only, a non-allowlisted update writes no row, no package option can widen the list"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityLogBehaviourTest.php (create, non-allowlisted update, mixed update, delete, raw JSON canary search, bulk update)"
        status: pass
    human_judgment: false
  - id: D2
    description: "A model using the wrapper without the attribute, or with an empty list, throws LogicException on its first logged event"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityLogBehaviourTest.php#fails loudly on the first save (two tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every activity row carries a source web, console, job or webhook (CHECK constraint); work without a signed-in user is logged with a null causer"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivitySourceTest.php (web, console, job, job failure, webhook wrapper, manual call, CHECK 23514, every row non-null)"
        status: pass
    human_judgment: false

duration: 6min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 10: Allowlisted Activity Log and Source Label Summary

**Activity log that can only record attributes a model explicitly allows (`#[LoggedAttributes]` plus a fixed-options wrapper trait), with a server-side source label (web, console, job, webhook) and a null causer for work without a user, proven on a probe model and a probe queue job**

## Performance

- **Duration:** 6 min
- **Started:** 2026-10-08T03:33:09Z
- **Completed:** 2026-10-08T03:39:30Z
- **Tasks:** 2
- **Files modified:** 14 (11 created, 3 modified)

## Accomplishments

- `#[LoggedAttributes([...])]` on the model class plus the `LogsAllowlistedActivity` trait: fixed options `logOnly(list)`, `logOnlyDirty`, `dontLogEmptyChanges`, log name from the morph alias. A missing or empty list throws `LogicException` on the first logged event; the trait contains none of `logAll`, `logFillable`, `logUnguarded` and has no override hook for them.
- Proven on `ActivityProbe`: create logs title and status only; an update touching only `secret_note` or `api_token` writes no row; a mixed update logs only the allowlisted old and new value; delete logs old allowlisted values only; the raw JSON of every `activity_log` column never contains the runtime canary values. A bulk query update writes no row (documented limit: allowlisted attributes change through model saves only).
- Source label: `ActivitySource` (explicit `as()` wrapper, else inside a queue job, else console, else web), `KokpitLogActivityAction` sets it for model events and manual `activity()` calls, `activity_log.source` has a CHECK constraint (SQLSTATE 23514 on an unknown value) and an index. Console, job and webhook work is logged with causer null.
- `OperationsServiceProvider` registered; queue events enter and leave the job label, and a job that throws leaves the depth at zero (next write is console again).

## Task Commits

1. **Task 1 (tracer): allowlisted activity log on a probe**
   - RED `635cb48` (test) - 6 of 8 tests fail on assertions; trait was a placeholder over the package trait
   - GREEN `ed00faf` (feat) - `LogsAllowlistedActivity` with fixed options
2. **Task 2 (tdd): source label and null causer**
   - RED `a005678` (test) - 11 of 11 tests fail
   - GREEN `29e5736` (feat) - `ActivitySource`, `KokpitLogActivityAction`, provider, migration, config

**Plan metadata:** recorded in the docs commits that follow this summary.

## TDD Gate Compliance

Both tasks followed RED, GREEN with real commits (`test(03-10)` precedes `feat(03-10)` for each task). No REFACTOR commit was needed.

**RED evidence (semantic assessment):**

- Task 1, `ActivityLogBehaviourTest`: with the placeholder trait the package default options apply, so the target tests failed on the planned assertions: the create row carried no attribute changes (TypeError reading the missing `attributes` key), a non-allowlisted update wrote 2 rows instead of 0, the mixed update row had no `attributes`, the delete row had no `old`, and the two anonymous models raised `ClassMorphViolationException` instead of `LogicException`. Two tests were green at RED: the bulk-update test (documents existing package behaviour: no model event, no row) and the raw JSON canary search (vacuously true while nothing is logged). Both were proven by a mutation check at GREEN: replacing `logOnly(static::loggedAttributes())` with `logAll()` made 7 of 8 tests fail, including the canary search, so the canary test is not vacuous.
- Task 2, `ActivitySourceTest`: 11 of 11 failed. The enum and an inert `ActivitySource` placeholder (always web, wrappers call through) were in the RED commit so the files load. Failures were `BindingResolutionException` for the unbound `ActivitySource` (the provider binding was the missing behaviour), and `Undefined property source` for the missing column. Mutation checks at GREEN: dropping `JobExceptionOccurred` from the leave listener failed the "job throws" test; removing the `max(0, ...)` clamp failed the depth test.
- The RED run was classified by reading the Pest output; the `gsd_run check tdd-red-evidence` classifier does not parse Pest/PHPUnit output (no supported report format), so no `RED_EVIDENCE_OK` record exists.

## Files Created/Modified

- `app/Domain/Audit/LoggedAttributes.php` - class attribute carrying the allowlist
- `app/Domain/Audit/LogsAllowlistedActivity.php` - wrapper trait, `loggedAttributes()` reads the attribute of `static::class`
- `app/Domain/Audit/ActivitySourceLabel.php` - backed enum, `HasLabel`
- `app/Domain/Audit/ActivitySource.php` - label resolution, `as()`, `enterJob()`, `leaveJob()`
- `app/Domain/Audit/KokpitLogActivityAction.php` - sets `source` after calling the parent hook
- `app/Providers/OperationsServiceProvider.php` - `ActivitySource` singleton and queue listeners
- `database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php` - `source` column, CHECK, index
- `config/activitylog.php`, `bootstrap/providers.php`, `lang/cs/enums.php` - wiring and Czech labels
- `tests/Support/Probes/ActivityProbe.php`, `ActivityProbeJob.php`; `tests/Feature/Operations/ActivityLogBehaviourTest.php`, `ActivitySourceTest.php`

## Decisions Made

- The trait carries `@phpstan-ignore trait.unused`, matching the three Filament access traits of Phase 2: PHPStan analyses `app/` only and the first real user arrives with the first logged model. Plan 03-11's architecture test and later model plans make the ignore removable.
- `JobFailed` is not a leave trigger: the queue always raises `JobExceptionOccurred` first, so listening to it as well would leave twice. The depth clamp at zero is the second safety net.
- The `source` column stays nullable (plan contract); written rows are always non-null, asserted in tests.
- Probe table assertions: a model created inside the test transaction keeps its row when the `created` event throws (the event fires after the insert); the test therefore asserts the exception only. In production the surrounding transaction rolls back.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] phpstan trait.unused on the new wrapper trait**
- **Found during:** Task 1 (verification)
- **Issue:** Larastan level 8 reports `Trait ... is used zero times` because only a test probe uses it.
- **Fix:** Added the documented `@phpstan-ignore trait.unused (...)` docblock tag, the same convention as `app/Filament/Concerns/*`.
- **Files modified:** `app/Domain/Audit/LogsAllowlistedActivity.php`
- **Committed in:** `ed00faf`

**2. [Rule 1 - Bug] Wrong assertion in the missing-allowlist test**
- **Found during:** Task 1 (GREEN run)
- **Issue:** The test asserted that no probe row exists after the `LogicException`; the `created` event fires after the insert, so the row exists inside the test transaction.
- **Fix:** Removed the row-count assertion; the contract is the exception on first save.
- **Files modified:** `tests/Feature/Operations/ActivityLogBehaviourTest.php`
- **Committed in:** `ed00faf`

**3. [Rule 3 - Blocking] Extra test fixture `ActivityProbeJob`**
- **Found during:** Task 2 (RED authoring)
- **Issue:** The plan's job test needs a real queued job through the sync connection; a sync payload is serialised, so an anonymous class cannot be used. The plan's file list has no job fixture.
- **Fix:** Added `tests/Support/Probes/ActivityProbeJob.php` (plain, webhook-sequence and fail modes).
- **Committed in:** `a005678`

---

**Total deviations:** 3 auto-fixed (1 bug in a test assertion, 2 blocking).
**Impact on plan:** No change of scope or production behaviour; one test fixture file added.

## Issues Encountered

- A first mutation check (removing `JobExceptionOccurred` from the leave listener) seemed to survive; the same mutation re-run immediately before and after failed the target test as expected. The surviving run was a stale result right after a file edit through the DDEV mount; the test is verified to catch the mutation.

## Known Stubs

None.

## Threat Flags

None. The only new trust-boundary surface (queue event listeners) is covered by T-03-24 in the plan.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

None. The architecture test, the refused pruning of `activitylog:clean` and the production guard for `activitylog.enabled` and `queue.default` belong to plan 03-11.

## Next Phase Readiness

- Plan 03-11 can build the architecture test on `ActivityProbe` (happy path) and the reflection contract of `LogsAllowlistedActivity::loggedAttributes()`.
- `OperationsServiceProvider` is ready for the listeners and bindings of plans 03-13, 03-15 and 03-16.
- Open item for later model plans: set `#[LoggedAttributes]` on each logging model; kanban status moves and any allowlisted attribute must change through model saves, not bulk updates.

## Self-Check: PASSED

- Created files exist: all 11 files under key-files.created found on disk.
- Commits `635cb48`, `ed00faf`, `a005678`, `29e5736` are ancestors of HEAD.
- `ddev composer ci` green at the last code commit: Pest 695 passed (3036 assertions), Pint 200 files, Larastan no errors, licence check 201 packages.
- Acceptance criteria of both tasks re-run: all pass.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
