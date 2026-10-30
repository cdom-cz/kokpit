---
phase: 12-partner-audit
plan: 08
subsystem: testing
tags: [pest, audit, owner-sign-off, permission-matrix, partner]

requires:
  - phase: 12-partner-audit
    provides: PartnerMatrix, RouteRules and ChannelMatrix declarations and the Audit suite from plans 12-02 to 12-07
provides:
  - 12-MATRIX-REVIEW.md, the single owner review sheet of the Partner permission matrix, with the owner decision recorded (approved, 2026-10-24)
  - the owner's confirmation line in the docblocks of PartnerMatrix, RouteRules and ChannelMatrix
affects: [12-09]

actuals:
  tokens: 4200
  tasks: 3
  commits: 2

plan_head_before: 1ffc6a5315c0bb9ed77215b73889d09556ef23d4
plan_head_after: bbeed9f9374e9d89b037eb4cfd458665ba4c98b4
commits: 2

tech-stack:
  added: []
  patterns:
    - "The owner confirmation is recorded twice: in the review sheet (decision section and per-finding marks) and in the docblock of each declaration file, so the sign-off shows in the diff and in the code"

key-files:
  created:
    - .planning/phases/12-partner-audit/12-MATRIX-REVIEW.md
  modified:
    - tests/Support/PartnerMatrix.php
    - tests/Support/RouteRules.php
    - tests/Support/ChannelMatrix.php

key-decisions:
  - "The owner approved the Partner permission matrix as implemented (Status: approved, 2026-10-24): F-6, F-11, F-12, F-14, F-20 and F-23 confirmed, open questions 1 to 6 keep their implemented defaults"
  - "DO-01 (open question 6) stays out of scope and with phase 9"

patterns-established:
  - "A behaviour-neutral sign-off changes only documentation and docblocks; the declaration rows and production code stay as the audit already enforces them"

requirements-completed: [US-01]

coverage:
  - id: D1
    description: "The owner reviewed one sheet that shows the whole matrix, the routes, the channel cells, the findings F-1 to F-24, the fixes and the six open questions, and decided on it (approved, 2026-10-24)"
    requirement: US-01
    verification:
      - kind: unit
        ref: "grep -A3 '^## Owner decision' 12-MATRIX-REVIEW.md | grep -cE '^Status: (approved|amended)$' prints 1"
        status: pass
    human_judgment: true
    rationale: "Whether the permission matrix is what Kokpit must enforce is the owner's call; it was answered by the owner at the Task 2 checkpoint"
  - id: D2
    description: "The declarations carry the owner's confirmation with its date, and the Audit suite is green with the confirmed matrix"
    requirement: US-01
    verification:
      - kind: unit
        ref: "ddev exec vendor/bin/pest --testsuite=Audit (592 passed, 1249 assertions)"
        status: pass
      - kind: unit
        ref: "grep -c 'Confirmed by the owner' tests/Support/PartnerMatrix.php tests/Support/RouteRules.php tests/Support/ChannelMatrix.php prints 1 per file"
        status: pass
    human_judgment: false

duration: 14 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 08: Owner Sign-off of the Partner Permission Matrix Summary

**The owner approved the Partner permission matrix, the findings and the six implemented defaults as they are (2026-10-24); the decision is recorded in 12-MATRIX-REVIEW.md and in the three declaration files, and the Audit suite still passes with 592 tests.**

## Performance

- **Duration:** about 14 min of executor time across the continuation (the checkpoint wait for the owner is not counted)
- **Tasks:** 3 (1 auto sheet, 1 decision checkpoint, 1 auto recording)
- **Files modified:** 4 (1 created, 3 modified besides the sheet)
- **Audit suite:** 592 passed, 1249 assertions (unchanged: no behaviour changed)

## Accomplishments

- Task 1: the review sheet `12-MATRIX-REVIEW.md` was built from the declarations (commit `d7bb734`): the Partner matrix tables, routes, channel cells, findings F-1 to F-24, fixes made and the six open questions with their implemented defaults.
- Task 2 (checkpoint:decision, blocking, no auto-select): the owner first typed "amend", asked for a list of the items that need a decision and then answered item by item: F-6, F-11, F-12, F-14 and F-23 confirmed as implemented, F-20 kept as implemented (no distinct canary text per entity), open questions 1 to 6 stand as implemented.
- Task 3: because no item changes behaviour, the decision is recorded as `Status: approved` (not amended) with the date and the paraphrased answer; the findings F-6, F-11, F-12, F-14, F-20 and F-23 carry a "Confirmed by the owner on 2026-10-24" mark in the sheet; the line "Confirmed by the owner on 2026-10-24 in plan 12-08 (D-02); see .planning/phases/12-partner-audit/12-MATRIX-REVIEW.md." was added to the docblock of PartnerMatrix, RouteRules and ChannelMatrix (commit `bbeed9f`).

## Task Commits

1. **Task 1: the owner review sheet** - `d7bb734` (docs)
2. **Task 2: owner decision checkpoint** - no commit (answered by the owner)
3. **Task 3: record the decision in the sheet and the declarations** - `bbeed9f` (docs)

**Plan metadata:** committed separately as docs(12-08).

## Owner decision

Status: approved, 2026-10-24.

| Item | Owner answer |
|---|---|
| F-6 `UnresolvedPayments` visible while an unresolved card payment exists | confirmed as implemented |
| F-11 paid amount in the Partner invoice detail | confirmed as implemented |
| F-12 item unit price in the Partner invoice detail | confirmed as implemented |
| F-14 record keys in the `PartnerTasks` widget snapshot | confirmed as implemented |
| F-20 shared canary text limits what a `none` ui cell proves | keep as implemented |
| F-23 labels of the four notification and mail classes not run by the script | confirmed as implemented |
| Open questions 1 to 6 | implemented defaults stand; DO-01 (question 6) stays out of scope |

## Applied amendments

None. No item changed behaviour, so no declaration row and no production code were edited.

## Deferred by the owner

Nothing. DO-01 stays with phase 9 as the sheet already stated.

## Files Created/Modified

- `.planning/phases/12-partner-audit/12-MATRIX-REVIEW.md` - created in Task 1; Task 3 recorded the decision and marked six findings as confirmed
- `tests/Support/PartnerMatrix.php` - docblock line with the owner confirmation (docblock only)
- `tests/Support/RouteRules.php` - docblock line with the owner confirmation (docblock only)
- `tests/Support/ChannelMatrix.php` - docblock line with the owner confirmation (docblock only)

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

None - plan executed exactly as written. The owner's route through the checkpoint (first "amend", then an item-by-item list that turned out to confirm every item) ended as an approval; the plan's "approved" branch of Task 3 applies.

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. Only documentation and docblocks changed; no network endpoint, auth path, file access or schema was added. Threat T-12-28 (matrix treated as confirmed without the owner) is mitigated: the decision came from the owner at a blocking checkpoint and is dated in the sheet and in the declarations. `scripts/check-sensitive.sh` was clean on the sheet and on the staged commit.

## Next Phase Readiness

- The permission matrix is now the owner's confirmed specification and the Audit suite enforces exactly it. Plan 12-09 can proceed.

## Self-Check: PASSED

- Files exist: 12-MATRIX-REVIEW.md, PartnerMatrix.php, RouteRules.php, ChannelMatrix.php
- Commits `d7bb734` and `bbeed9f` are ancestors of HEAD
- Acceptance criteria: `grep -c "Confirmed by the owner"` prints 1 for each of the three declaration files; the `## Owner decision` status line reads `Status: approved`; `ddev exec vendor/bin/pest --testsuite=Audit` 592 passed; `ddev exec vendor/bin/pint --test` clean; `ddev exec vendor/bin/phpstan analyse` no errors; `scripts/check-sensitive.sh` clean

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
