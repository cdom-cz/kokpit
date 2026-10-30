---
phase: 03-operations-foundation
plan: 19
subsystem: docs
tags: [contributing, readme, documentation-test, gitignore-review, phase-gate, operations]

requires:
  - phase: 03-operations-foundation
    provides: "03-03 to 03-18: ValidatedSettings and SettingsMigration, DocumentNumbering, LoggedAttributes and LogsAllowlistedActivity, ActivityHistoryRelationManager, KokpitJob and Idempotent, HealthIndicatorRegistry, StorageCheck and kokpit:storage:check, the deploy path"
provides:
  - "CONTRIBUTING Conventions bullets for Settings, Audit, Jobs and alerts, Health indicators and Storage, and DocumentNumbering in the Numbering bullet, each naming the test that enforces it"
  - "Add-a-model checklist step 8 for audited models (LoggedAttributes, the explicit logging-model list of the allowlist test, a concrete history relation manager)"
  - "README Operations section: worker and scheduler must run, the System page, alert mail requirement, kokpit:storage:check"
  - "RepositoryFilesTest: documented Phase 3 classes must exist (class, trait, interface or enum)"
  - "Per-phase .gitignore review result (no change) and a green phase gate"
affects: [phase-4 onward (every phase extends these mechanisms), phase-5-kanban, phase-8-pdf-report, phase-10-invoicing, release-process]

actuals:
  tokens: 4000
  tasks: 2
  commits: 1

plan_head_before: 61cbbeaf21cf8514a2bb38be1da0941cd5c919f6
plan_head_after: f70cbd1c7f825486cf05e7ed5b1cbcff4089cd25

tech-stack:
  added: []
  patterns:
    - "Documentation drift guard: every class named in CONTRIBUTING must exist, checked by class_exists, trait_exists, interface_exists and enum_exists in RepositoryFilesTest (same style as the documented-commands tests)"
    - "A convention bullet names its mechanism, its rule and the test file that enforces it"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php

key-decisions:
  - "The .gitignore needed no change in Phase 3: no new tool output sits in the tree, so no rule and no test-gitignore.sh case was added"
  - "The README Operations section sits before 'Language and time' and keeps the storage check command as the only new indented code block, so the existing documented-command tests cover it"
  - "The documentation test has a second, wider test over secondary class names (ActivitySourceLabel, RunsAsSystem, ReportFailedJob and others) so a rename of any class the bullets rely on fails the suite, not only the ten classes the plan lists"
  - "Step 8 of the add-a-model checklist points at the explicit list in tests/Arch/ActivityAllowlistTest.php (the test that asserts the found logging models), because AuditDeclaration::loggingModels() scans app/ and the explicit list is the test expectation"

patterns-established:
  - "Phase close-out: docs plus a documentation test plus the full gate (composer ci, s3 group, shell self-tests, sensitive scan of the whole tree, composer validate, fresh-clone install)"

requirements-completed: [FND-07, FND-08, FND-09, FND-10, FND-16]

coverage:
  - id: D1
    description: "CONTRIBUTING Conventions name every Phase 3 mechanism (settings, numbering, audit, jobs and alerts, health, storage) and the add-a-model checklist has the audit step"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it names every Phase 3 operations mechanism in CONTRIBUTING.md and the named class exists"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it documents the audit step in the add-a-model checklist and the operations section of the README"
        status: pass
    human_judgment: false
  - id: D2
    description: "A documented class that is renamed or removed fails the suite (mutation: a missing class name turns the test red, restored afterwards)"
    requirement: FND-08
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it only names Phase 3 classes in CONTRIBUTING.md that exist in app/"
        status: pass
    human_judgment: false
  - id: D3
    description: "README Operations section: worker, scheduler, System page, alert mail, storage check in an indented code block that the artisan-command test validates"
    requirement: FND-10
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it only documents artisan commands that exist"
        status: pass
    human_judgment: false
  - id: D4
    description: "Per-phase .gitignore review: nothing new to ignore, the System page view is tracked and not ignored"
    requirement: FND-16
    verification:
      - kind: other
        ref: "git check-ignore -q resources/views/filament/pages/system-page.blade.php (rc 1) and git ls-files prints the path"
        status: pass
    human_judgment: true
    rationale: "Whether a path is tool output that deserves a rule is a review judgment; the inspected list is recorded below"
  - id: D5
    description: "The phase gate is green: composer ci, the s3 group, the shell self-tests, check-sensitive --all, composer validate --strict and a fresh-clone install"
    requirement: FND-09
    verification:
      - kind: integration
        ref: "ddev composer ci (893 passed, Pint clean, PHPStan no errors, 206 packages licence-allowed)"
        status: pass
      - kind: integration
        ref: "ddev exec vendor/bin/pest --group=s3 (9 passed against RustFS)"
        status: pass
      - kind: integration
        ref: "bash scripts/tests/run.sh (PASS 10 FAIL 0 SKIP 0)"
        status: pass
    human_judgment: false

duration: 5min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 19: Close the phase - conventions, operations README, gitignore review and gate Summary

**CONTRIBUTING now names every Phase 3 mechanism a later phase must use (ValidatedSettings, DocumentNumbering, LoggedAttributes, KokpitJob with Idempotent, HealthIndicatorRegistry::replace(), the throwing s3 disk with kokpit:storage:check), a test fails when a named class disappears, the README tells operators what must run, and the full phase gate is green.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-10-08T05:12:30Z
- **Completed:** 2026-10-08T05:17:00Z
- **Tasks:** 2 (Task 1 tracer committed; Task 2 review and gate, no file change)
- **Files modified:** 3

## Accomplishments

- CONTRIBUTING Conventions: new bullets Settings, Audit, Jobs and alerts, Health indicators and Storage, and the Numbering bullet extended with `DocumentNumbering` (`next()`, `preview()`, `nextTaskNumber()`). Each bullet names the rule, the exception paths and the test files that enforce it. The Settings bullet carries cache off and why, the package `encrypted()` list for any future secret setting, and `SettingsProperty::PARTNER_VISIBLE_GROUPS` with its canary guard. The Audit bullet carries model saves only (no query updates), no pruning (`RefusingCleanActivityLogAction`), source labels with `ActivitySource::as()` and the concrete `ActivityHistoryRelationManager` subclass with its own Admin-only rule. The Jobs bullet carries system context by middleware, after-commit dispatch, the alert path (mail plus bell, not through the queue, throttled) and the delayed-job caveat. The Storage bullet carries the DDEV temporary-URL caveat (`http://rustfs:9000` resolves only inside DDEV; research Pitfall 20).
- Add-a-model checklist gained step 8 for audited models; the closing `ddev composer ci` step is now 9.
- README Operations section: queue worker and scheduler must run (DDEV daemons, Zerops services), the System page (`/admin/system`), alerts need working mail settings while the bell works without mail, and `ddev artisan kokpit:storage:check` in an indented code block.
- `RepositoryFilesTest` gained three tests: the ten classes of the plan are named in CONTRIBUTING and exist; ten secondary names the bullets rely on exist; the checklist step and the README section are present. A mutation (a non-existent class name in the list) turned the first test red and was restored.
- Per-phase `.gitignore` review recorded below (no change).
- Phase gate green (details below).

## Task Commits

1. **Task 1: Tracer - conventions, README operations section, class-existence test** - `f70cbd1` (docs)
2. **Task 2: Per-phase .gitignore review and the full phase gate** - no commit: the review found nothing to ignore, and the gate needed no fix.

**Plan metadata:** the `docs(03-19)` commit that carries this summary, STATE.md, ROADMAP.md and REQUIREMENTS.md.

## Files Created/Modified

- `CONTRIBUTING.md` - Phase 3 convention bullets and the audit step of the add-a-model checklist
- `README.md` - Operations section (worker, scheduler, System page, alert mail, storage check)
- `tests/Feature/Repo/RepositoryFilesTest.php` - class-existence tests for the documented mechanisms

## .gitignore review result

No change. Inspected with `git status --ignored --porcelain` and `git ls-files --others --exclude-standard`:

- Ignored and already covered: `.env`, `vendor/`, `storage/logs/laravel.log`, compiled views under `storage/framework/views/`, `storage/framework/phpstan/`, `storage/framework/testing/disks/` (its own placeholder `.gitignore`), `bootstrap/cache/packages.php` and `services.php`, the Filament published assets below `public/css/`, `public/js/` and `public/fonts/` (rules `/public/css/filament/` style entries), `.claude/*` tooling, `.idea/`, `.planning/codebase/`, and the DDEV generated files (covered by DDEV's own `.ddev/.gitignore`).
- `.gsd/` is the GSD tool's own state directory and ignores itself through its `.gitignore`; it is not application tool output and needs no rule in the repository file.
- The only untracked path is `.planning/milestone.lock` (GSD orchestrator state, owned by the repository owner's tooling; left untouched).
- Spike work lives outside the repository (`~/kokpit-spikes`, directories `kanban` and `pdf`); inside the tree only the two decision records `03-SPIKE-KANBAN.md` and `03-SPIKE-PDF.md` are tracked.
- `resources/views/filament/pages/system-page.blade.php` is tracked (`git ls-files` prints it) and not ignored (`git check-ignore` exits 1).

## Gate results

| Gate | Result |
|---|---|
| `ddev composer ci` | exit 0: Pest 893 passed (4050 assertions), Pint clean, PHPStan level 8 "No errors", licence check "206 packages, every one has an allowed licence" |
| `ddev exec vendor/bin/pest --group=s3` | 9 passed (81 assertions) against RustFS |
| `bash scripts/tests/run.sh` | PASS 10 FAIL 0 SKIP 0 |
| `scripts/check-sensitive.sh --all` | clean (denylist not set, generic patterns only) |
| `composer validate --strict` | `./composer.json is valid` |
| Fresh clone (`git clone` into a temporary directory, `composer install`) | installed; `storage/framework/views/.gitignore` and `bootstrap/cache/.gitignore` exist; directory removed |
| Pre-commit hook on Task 1 | sensitive-content and gitleaks passed |

## Decisions Made

See `key-decisions` above. No decision changes a locked D-01 to D-18 decision.

## Deviations from Plan

None - plan executed exactly as written.

The plan's step to add a `.gitignore` rule applies only to real new tool output; the review found none, so Task 2 produced no commit (the plan allows this: "if nothing changed, record the review result in the summary").

**Total deviations:** 0.
**Impact on plan:** none.

## Issues Encountered

- A first mutation run of the documentation test passed although the class name had been changed: the DDEV container still saw the old file (mutagen sync lag). Re-running after the sync (verified with `ddev exec grep`) failed as expected; the test file was then restored from a backup copy outside the repository and re-run green. No test weakness; the lesson was already recorded in 03-18 (wait for the sync before trusting a mutation run).

## User Setup Required

None - no external service configuration required.

## Known Stubs

None. The three slots that still show "not available yet" on the System page (last rate date, unprocessed webhooks, unsent invoice e-mails) are the intended D-13 placeholders and are documented in CONTRIBUTING as replaced through `HealthIndicatorRegistry::replace()` by Phases 8, 10 and 11.

## Threat Flags

None. The plan adds documentation and a test only; no new endpoint, auth path, file access or schema surface.

## Remaining non-blocking manual verifications

Unchanged from `03-VALIDATION.md`: the Zerops rehearsal on a throwaway project (`03-ZEROPS-REHEARSAL.md`), the manual GitHub and Zerops settings checklist in CONTRIBUTING "Deploy (maintainer, manual)", and the owner's review of the two spike decision records. The roadmap success criteria 1, 2 and 4 are backed by green tests, criterion 3 by the deploy contract tests plus the documented manual checklist, criterion 5 by the two spike decision records (FND-19 is not declared by this plan and is not marked here).

## Next Phase Readiness

- Phase 3 plans are all executed (19 of 19). The orchestrator verifies and closes the phase.
- Phases 4 to 11 find each operations mechanism documented next to its enforcing test; a renamed or removed class fails `RepositoryFilesTest`.
- Carried-over limits, all documented: the Gate::before limit for the first permission (Phase 2), temporary URLs from the DDEV endpoint do not resolve in a browser (Documents phase), delayed jobs age in the oldest-pending indicator.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*

## Self-Check: PASSED

- FOUND: CONTRIBUTING.md, README.md, tests/Feature/Repo/RepositoryFilesTest.php (modified, committed in f70cbd1)
- FOUND: commit f70cbd1 is an ancestor of HEAD
- FOUND: RepositoryFilesTest 18 passed; gate commands green as listed
