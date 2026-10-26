---
phase: 12-partner-audit
plan: 09
subsystem: testing
tags: [contributing, documentation, repository-test, composer, partner-audit]

requires:
  - phase: 12-partner-audit
    provides: the Audit suite, RouteRules, PartnerMatrix and ChannelMatrix (plans 12-01 to 12-07) and the owner's approval of the matrix (12-08)
provides:
  - the rewritten CONTRIBUTING Phase 12 bullet that tells later phases which declaration to edit in the same pull request
  - the composer table row for composer partner-audit and the corrected Livewire update endpoint livewire-{hash}/update
  - RepositoryFilesTest guard that the Phase 12 bullet names only classes, test files and composer scripts that exist
  - README gate paragraph and PROJECT.md Key Decisions rows for Phase 12
affects: [every later phase that adds a route, screen, endpoint, Livewire component, isolated entity, notification or mail class]

actuals:
  tokens: 2100
  tasks: 2
  commits: 2

plan_head_before: 231baf745e384b709382a9b950bae0885406527f
plan_head_after: e2c1ce2ee643a63f036c2d241c75847360d836a9
commits: 2

tech-stack:
  added: []
  patterns:
    - "Documentation that names code is guarded by RepositoryFilesTest: every backticked CamelCase class, every tests/...php path and every composer script the Phase 12 bullet names must exist"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - README.md
    - .planning/PROJECT.md

key-decisions:
  - "The Phase 12 bullet keeps the label **Phase 12.** and avoids a backticked Audit (the Card payments guard resolves every backticked CamelCase name of the bullet as a class), so the suite is written as the Audit suite and by its directory tests/Audit"
  - "The second wrong mention of /livewire/update (the Task checklist bullet) is corrected to livewire-{hash}/update together with the Livewire components bullet, so no CONTRIBUTING text names the endpoint that does not exist"
  - "PROJECT.md outcomes: the matrix, the route key rule, the closed Filament action routes and the guest redirect fix read Approved 2026-10-24 (the owner approved the findings and open questions 1 to 6 as implemented); the CI shape stays Pending until the hosted run is observed"

patterns-established:
  - "A later phase finds in CONTRIBUTING which declaration to edit (RouteRules, PartnerMatrix, ChannelMatrix) and the repository test fails the day the text names something that no longer exists"

requirements-completed: [US-01, AUD-01, AUD-02]

coverage:
  - id: D1
    description: "CONTRIBUTING hands the Partner audit over to later phases: the Audit suite and composer partner-audit, the RouteRules, PartnerMatrix and ChannelMatrix declarations, the same-pull-request rules, and the real Livewire update endpoint"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands over the Partner audit: the Phase 12 bullet names only what exists"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only documents composer scripts that exist"
        status: pass
    human_judgment: false
  - id: D2
    description: "README and the composer table describe the new gate; PROJECT.md records the Phase 12 decisions"
    requirement: AUD-02
    verification:
      - kind: other
        ref: "grep -c 'composer partner-audit' README.md prints 1; grep -c 'Phase 12' .planning/PROJECT.md prints 6"
        status: pass
    human_judgment: true
    rationale: "Whether the prose reads clearly to a contributor and states the owner's decisions correctly is a reading judgement; no test asserts it"
  - id: D3
    description: "The full gate composer ci is green (Pest without Audit, the Audit suite, Pint, PHPStan, licences), scripts/check-sensitive.sh --all is clean and routes/public.php and tests/Isolation are unchanged since 58c5e93"
    requirement: US-01
    verification:
      - kind: integration
        ref: "ddev composer ci (exit 0: 6878 passed, 3 skipped; Audit 592 passed; Pint, PHPStan and licences clean)"
        status: pass
      - kind: other
        ref: "scripts/check-sensitive.sh --all clean; git diff --quiet 58c5e93 -- routes/public.php tests/Isolation succeeds"
        status: pass
    human_judgment: false
  - id: D4
    description: "The hosted CI run on GitHub runs the Pest and Partner audit steps green"
    verification: []
    human_judgment: true
    rationale: "Needs a push and a look at the hosted workflow; the local composer ci and CiParityTest prove the configuration, not the hosted run"

duration: 25 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 09: Partner Audit Hand-over Summary

**CONTRIBUTING, README and PROJECT.md now tell later phases which declaration to edit (RouteRules, PartnerMatrix, ChannelMatrix) in the same pull request, RepositoryFilesTest fails when that text names a class, test or composer script that no longer exists, and the full `composer ci` gate including the 592-test Audit suite is green.**

## Performance

- **Duration:** 25 min of editing plus a 19 min local `composer ci` run
- **Started:** 2026-10-24T05:08:00Z
- **Completed:** 2026-10-24T05:56:00Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- The CONTRIBUTING `**Phase 12.**` bullet is rewritten: the Audit suite (`tests/Audit`, its four test files, `composer partner-audit`, the CI step "Partner audit", excluded from `composer test`), `RouteRules` (keys, means, closed routes; `pay.show` and `webhooks.stripe` stay its two Phase 11 entries and `CardPaymentLeakTest` still fails on a third route), `PartnerMatrix`, `ChannelMatrix`, the helpers `RouteInventory`, `PanelInventory`, `ChannelProbe`, and the rules (declare in the same pull request, never derive the expectation from production code, never allowlist a route or mark a channel not applicable to make the audit pass, fix a leak in the phase that finds it).
- The composer table gains `composer partner-audit`, `composer test` reads "the Audit suite runs separately", the sentence above it says `composer ci` runs the first five, and the endpoint is `livewire-{hash}/update` (derived from `APP_KEY`) in the Livewire components bullet and the Task checklist bullet.
- RepositoryFilesTest: "hands over the Partner audit: the Phase 12 bullet names only what exists" (required phrases, every backticked CamelCase name resolves, every named `tests/...php` exists, the script exists, the endpoint is named as routed) and the composer pattern of "only documents composer scripts that exist" gains `partner-audit`.
- README gate paragraph names `composer partner-audit` and what it proves; PROJECT.md gets five Phase 12 Key Decisions rows (owner-approved matrix, route key rule, CI shape, closed Filament action routes, guest redirect fix).

## Task Commits

1. **Task 1: Tracer - CONTRIBUTING hands the Partner audit over, guarded by RepositoryFilesTest** - `89b050f` (docs; RED seen first: the new test failed on the old bullet, then 46 repository tests passed)
2. **Task 2: README and PROJECT.md record the gate and the decisions, full gate green** - `e2c1ce2` (docs)

**Plan metadata:** committed separately as docs(12-09) with this SUMMARY.

## Files Created/Modified

- `CONTRIBUTING.md` - the Phase 12 bullet, the composer table, the corrected Livewire endpoint in two bullets
- `tests/Feature/Repo/RepositoryFilesTest.php` - the Partner audit hand-over guard and the extended composer pattern
- `README.md` - the gate paragraph
- `.planning/PROJECT.md` - five Key Decisions rows and the last-updated line

## Decisions Made

- The bullet avoids a backticked `Audit` because the Card payments guard resolves every backticked CamelCase name of the Phase 12 bullet as a class; the suite is named "the Audit suite" and by `tests/Audit`.
- The Task checklist bullet also named `/livewire/update`; it is corrected with the Livewire components bullet so no text names the nonexistent endpoint, and the new guard forbids the old backticked form.
- PROJECT.md outcomes follow 12-MATRIX-REVIEW.md: Approved 2026-10-24 for the matrix, route key rule, closed Filament routes and guest redirect fix; the CI shape stays "Pending" because the hosted run has not been observed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A second documentation mention of the nonexistent `/livewire/update` endpoint**
- **Found during:** Task 1 (the new guard failed after the Livewire components bullet was fixed)
- **Issue:** The Task checklist bullet also said `/livewire/update` is public; the endpoint is `livewire-{hash}/update`.
- **Fix:** Replaced the wording in that bullet too.
- **Files modified:** CONTRIBUTING.md
- **Verification:** `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` (46 passed)
- **Committed in:** `89b050f` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Documentation accuracy only; no scope creep.

## Issues Encountered

None. The 3 skipped tests in the main Pest run are existing skips in other suites and were not touched by this plan.

## Human review

Open, not performed or approved by the executor; the verifier collects it into the phase UAT (`workflow.human_verify_mode` is `end-of-phase`):

- **Test:** Push the branch and open the latest run of the "Hygiene" workflow on GitHub. In the job "Tests (PostgreSQL 18)" open the steps "Pest" and "Partner audit".
- **Expected:** "Pest" runs `vendor/bin/pest --exclude-testsuite=Audit` and "Partner audit" runs `vendor/bin/pest --testsuite=Audit`; both are green, the Audit step lists RouteAuditTest, AccessMatrixTest, PartnerActionsTest and ChannelMatrixTest, and "CI Passed" is green. Note its duration in the UAT.
- **Why human:** The hosted run needs a push and a look at the workflow; the local `composer ci` and CiParityTest prove the configuration, not the hosted run.

## Full gate result

- `ddev composer ci`: exit 0. Pest without Audit: 6878 passed, 3 skipped (92452 assertions, 982 s). Audit suite: 592 passed (1249 assertions, 151 s). Pint clean (1236 files), PHPStan "No errors", licences: 227 packages, all allowed.
- `scripts/check-sensitive.sh --all`: clean.
- `git diff --quiet 58c5e93 -- routes/public.php tests/Isolation`: succeeds (D-08). `composer.lock` untouched by this plan.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Phase 12 has no further plans. Ready for the phase verifier and the end-of-phase UAT, which holds the hosted CI check above.

## Self-Check: PASSED

Verified: `CONTRIBUTING.md`, `README.md`, `.planning/PROJECT.md` and `tests/Feature/Repo/RepositoryFilesTest.php` exist and carry the changes; commits `89b050f` and `e2c1ce2` are ancestors of HEAD; all acceptance criteria of both tasks pass.

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
