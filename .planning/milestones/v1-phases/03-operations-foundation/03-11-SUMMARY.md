---
phase: 03-operations-foundation
plan: 11
subsystem: audit
tags: [activitylog, architecture-test, allowlist, retention, production-guard]

requires:
  - phase: 03-operations-foundation
    provides: "LoggedAttributes, LogsAllowlistedActivity, ActivityProbe and config/activitylog.php from plan 03-10"
  - phase: 02-platform-foundation
    provides: "ModelDeclaration scan style, ProductionConfigGuard"
provides:
  - "AuditDeclaration: detector for the allowlist rules (a) to (e) over every model that logs activity"
  - "Arch test (database-free) and feature test (real columns) with violating-fixture self-checks and a not-vacuous guard"
  - "RefusingCleanActivityLogAction: activitylog:clean fails loudly, activity records are kept indefinitely (D-09)"
  - "ProductionConfigGuard refuses to boot in production unless activitylog.enabled is exactly true"
affects: [03-12, 03-14, phase-04-tasks-projects, phase-06-time-entries, phase-10-invoices]

actuals:
  tokens: 6600
  tasks: 2
  commits: 3

plan_head_before: 8e6ee49e2c3680f7f51b61efe353f910bdb8ea03
plan_head_after: 0e266345fafdbb7ae72d2ed03d0ba8ff29aa6cd7

tech-stack:
  added: []
  patterns:
    - "Declaration detector in tests/Support with problems(), an explicit expected list, anonymous-class self-checks and a scan of the probe directory as the not-vacuous guard (same style as ModelDeclaration)"
    - "Package action replaced through config by a subclass that throws, so a destructive command cannot run"

key-files:
  created:
    - tests/Support/AuditDeclaration.php
    - tests/Arch/ActivityAllowlistTest.php
    - tests/Feature/Operations/ActivityAllowlistColumnsTest.php
    - app/Domain/Audit/RefusingCleanActivityLogAction.php
    - tests/Feature/Operations/NoPruningTest.php
  modified:
    - config/activitylog.php
    - app/Support/ProductionConfigGuard.php
    - tests/Unit/Support/ProductionConfigGuardTest.php

key-decisions:
  - "Sensitive-name rule is a slight superset of the plan list: password, secret, token, remember_token exactly, password_*, *_password, two_factor*, *_secret, *_token. Plural or embedded words such as tokens_per_hour stay allowed (tested)."
  - "Rule (b) is only evaluated when the wrapper trait is used; a model that skips the wrapper gets the single rule (a) message, so a violating model never produces two overlapping reports"
  - "activitylog.enabled must be exactly true in production (a missing value or a truthy string such as 'yes' is refused), matching the strict style of the canary harness rule"
  - "The queue.default production rule mentioned in research stays with plan 03-14, which declares it; it is not part of this plan"

patterns-established:
  - "Rule (d) takes the column list as an argument, so the Arch suite stays database-free and the Feature suite supplies Schema::getColumnListing"

requirements-completed: []

coverage:
  - id: D1
    description: "An architecture test fails when a model logs activity without the wrapper, overrides the options, has no or an empty allowlist, lists '*', a dotted or JSON path, a hidden attribute or a sensitive name; a feature test fails when an allowlisted attribute is not a real column; both have self-checks and a not-vacuous guard"
    requirement: FND-08
    verification:
      - kind: unit
        ref: "tests/Arch/ActivityAllowlistTest.php (26 tests, no database)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/ActivityAllowlistColumnsTest.php (probe, every app logging model, missing-column self-check)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Activity records are kept indefinitely: the clean command throws naming D-09 and deletes nothing, and the schedule contains no activity clean entry"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NoPruningTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "The application refuses to boot in production with the activity log off, missing or not strictly true"
    requirement: FND-08
    verification:
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php (5 new cases)"
        status: pass
    human_judgment: false

duration: 10min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 11: Allowlist Architecture Rules, Refused Pruning and Audit Production Guard Summary

**Architecture and real-column tests that fail any model logging activity without a reviewed, non-sensitive allowlist, a clean action that refuses to delete audit records, and a production guard against a disabled audit trail**

## Performance

- **Duration:** about 10 min
- **Completed:** 2026-10-08
- **Tasks:** 2
- **Files modified:** 8 (5 created, 3 modified)

## Accomplishments

- `AuditDeclaration::loggingModels()` finds application models using the package `LogsActivity` (via `class_uses_recursive`), and `problems($class, $columns)` reports rules (a) wrapper trait, (b) no `getActivitylogOptions()` override (method file compared with the wrapper trait file), (c) non-empty `#[LoggedAttributes]` on the class itself, (d) real columns (only when a column list is passed), (e) no `*`, dotted path, `->` JSON path, hidden attribute or sensitive name. Each message names the model and the attribute.
- `tests/Arch/ActivityAllowlistTest.php` (no database access): the explicit logging-model list is empty in this phase (tasks, projects, invoices and time entries add themselves), the probe directory scan must find `ActivityProbe` (not-vacuous guard), the probe is clean, and anonymous-class self-checks cover every violation, including an allowlist that exists only on a parent class.
- `tests/Feature/Operations/ActivityAllowlistColumnsTest.php`: rule (d) with `Schema::getColumnListing` for the provisioned probe table and every app logging model, plus a self-check with a missing column.
- `RefusingCleanActivityLogAction` is configured as `activitylog.actions.clean_log`; `activitylog:clean` throws a `RuntimeException` naming D-09 and every row survives (also with a log name and a 100-year retention). `NoPruningTest` asserts the schedule has no clean entry and proves the check with a scheduled fixture.
- `ProductionConfigGuard` refuses production unless `activitylog.enabled` is exactly `true`.

## Task Commits

1. **Task 1 (tracer): allowlist detector, arch test, column test** - `d4ea4c6` (test)
2. **Task 2 (tdd): refused pruning and production guard**
   - RED `9f9e2ae` (test) - 6 of the 13 new tests fail on their assertions (3 pruning, 3 guard)
   - GREEN `0e26634` (feat) - refusing action, config wiring, guard rule

**Plan metadata:** recorded in the docs commit that follows this summary.

## TDD Gate Compliance

Task 2 followed RED then GREEN with real commits (`test(03-11)` precedes `feat(03-11)`); no REFACTOR commit was needed. Task 1 is a tracer without `tdd="true"`: detector and tests were written together and committed once, as `test(03-11)` because every file is test code.

**RED evidence (semantic assessment), Task 2:** the clean command did not throw (`Exception [RuntimeException] not thrown`) and the rows were not protected, the config pointed at the package `CleanActivityLogAction`, and the guard did not throw for a disabled, missing or truthy-string `activitylog.enabled`. These are the planned assertions. Tests green at RED: the schedule test (nothing schedules the command, existing behaviour), its non-vacuous companion, and the guard's allow cases. The `gsd_run check tdd-red-evidence` classifier does not parse Pest output, so no `RED_EVIDENCE_OK` record exists; classification was by reading the Pest output (same as plan 03-10).

**Mutation checks proving the tests are not vacuous:**

- Task 1: forcing rule (b) to false, removing the `*` check, the hidden check and the `two_factor` prefix made 4 arch tests fail (overrides, wildcard, hidden, `two_factor_recovery_codes`); the file was restored byte-for-byte.
- Task 2: appending `Schedule::command('activitylog:clean')->daily()` to `routes/console.php` made `schedules no activity clean command` fail; `routes/console.php` was restored.

## Deviations from Plan

None - plan executed exactly as written. Two small choices inside the plan's latitude: `AuditDeclaration::attributeProblems()` is public so the sensitive-name cases can be data-driven (PHP attribute arguments must be constants, so a dataset cannot feed an anonymous class attribute), and the existing guard unit helper gained an `activityLog` argument defaulting to `true` so the older cases keep their meaning.

## Issues Encountered

None. `ddev composer ci` is green at the last code commit: Pest 735 passed (3111 assertions), Pint 205 files, Larastan no errors, licence check 201 packages.

## Known Stubs

None.

## Threat Flags

None. T-03-25, T-03-26 and T-03-27 are mitigated as planned; no new network, auth or file surface was added.

## User Setup Required

None - no external service configuration required. Operators must not set `ACTIVITYLOG_ENABLED=false` in production (the application now refuses to boot).

## Manual follow-up

None.

## Next Phase Readiness

- Plan 03-12 (also declaring FND-08) can rely on the allowlist, retention and production-guard tests; `requirements-completed` is left empty here because FND-08 closes with 03-12.
- Plan 03-14 adds the `queue.default` production rule to the same guard; the unit helper `guardConfig()` already accepts the activity log setting, so it only needs a further argument.
- The first model that logs activity must be added to the expected list in `tests/Arch/ActivityAllowlistTest.php` together with its `#[LoggedAttributes]`.

## Self-Check: PASSED

- Created files exist: `tests/Support/AuditDeclaration.php`, `tests/Arch/ActivityAllowlistTest.php`, `tests/Feature/Operations/ActivityAllowlistColumnsTest.php`, `app/Domain/Audit/RefusingCleanActivityLogAction.php`, `tests/Feature/Operations/NoPruningTest.php`.
- Commits `d4ea4c6`, `9f9e2ae`, `0e26634` are ancestors of HEAD.
- Acceptance criteria of both tasks re-run: all pass (`grep` checks, `DB::` count 0 in the arch test, both Pest files exit 0).

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
