---
phase: 05-tasks-and-kanban
plan: 13
subsystem: tasks
tags: [filament, partner-isolation, escalation, comments, row-lock, rich-text, policy]

requires:
  - phase: 05-tasks-and-kanban
    provides: TaskComment model and policy, AddTaskComment with its escalation argument (05-09), PartnerTaskResource with the pinned escalated_at entry (05-12), TaskPolicy base and escalation columns (05-01)
provides:
  - PartnerTaskCommentsRelationManager, the Partner "Komentáře" tab on the own task page (create only, no internal switch)
  - EscalateTask Action (required comment, row lock, already-escalated refusal, priority untouched)
  - ClearEscalation Action (authorised on the row re-read under the lock, writes only the escalation pair)
  - TaskPolicy::clearEscalation granted to a Partner only on an own-client task they are assigned to
  - Escalate and clear actions on the Partner task page, escalation entry and clear action on the Admin task page
  - task_comments column pin in PartnerSafeColumnsTest
affects: [05-14 to 05-16 Partner and Admin notifications (escalation dispatch hooks into EscalateTask), 05-17 phase gate]

actuals:
  tokens: 13000
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "Authorise on the row re-read under lockForUpdate, not on the caller's instance, so a right that changed after the page was loaded (a reassignment) is honoured"
    - "A Partner relation manager that reads only the body from the form state and hands it to the domain Action; the flag it must not set is not in the form"
    - "Action modal errors keyed under mountedActions.0.data.<field> so the domain field error lands under the modal field"

key-files:
  created:
    - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php
    - app/Domain/Tasks/Actions/EscalateTask.php
    - app/Domain/Tasks/Actions/ClearEscalation.php
    - tests/Feature/Tasks/PartnerTaskCommentsTest.php
    - tests/Feature/Tasks/TaskEscalationTest.php
  modified:
    - app/Filament/Partner/Resources/PartnerTaskResource.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
    - app/Filament/Resources/TaskResource/Pages/ViewTask.php
    - app/Domain/Tasks/Policies/TaskPolicy.php
    - lang/cs/kokpit.php
    - tests/Isolation/PartnerSafeColumnsTest.php
    - tests/Isolation/PartnerTaskVisibilityTest.php

key-decisions:
  - "D-06 applied as written: the assignee resolves the flag. clearEscalation is true for a Partner only when the task belongs to the Partner's client and assignee_id is that Partner; the Admin always passes in before(). update, delete and restore stay denied, so a Partner assignee still cannot change priority or status"
  - "ClearEscalation authorises on the locked fresh row, so a Partner reassigned away after loading the page gets AuthorizationException"
  - "EscalateTask re-reads the task under lockForUpdate and checks escalated_at on that row, so a stale instance cannot create a second flag or comment"
  - "The Partner relation manager passes only the body to AddTaskComment; the server-side forcing from 05-09 remains the guarantee that a Partner comment is never internal"
  - "Czech copy follows UI-SPEC Surface H: action labels Eskalovat úkol and Zrušit eskalaci, field Důvod eskalace; the Admin entry is Eskalace with value Eskalováno (name, datetime) placed directly after Priorita (U-12)"

patterns-established:
  - "Mutation proof for authorisation and locking: each guarantee has a one-line mutation that makes a named test fail"
  - "Test fixtures that exercise a no-write guarantee give the task a non-default priority and status, so a write to either shows"

requirements-completed: [TA-07, TA-04]

coverage:
  - id: D1
    description: "A Partner reads the non-internal comments of an own task and adds a rich-text comment; the Admin sees it on the task page; the Partner form has no internal switch and a forged flag is stored as non-internal"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#lets a Partner add a rich-text comment that is stored for the Partner as a public comment"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#shows the Partner comment to the Admin in the comments tab of the task page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#stores a forged internal flag in the create data as not internal"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#offers a Partner no internal column, toggle, edit, delete or bulk action"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner escalates with a required comment: one visible escalation comment, who and when recorded, priority, status and assignee untouched; an empty reason is a field error on comment"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#lets a Partner escalate an own task with a comment and leaves priority and status alone"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#refuses an empty reason as the field error comment and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#shows the empty reason as an error under the field of the modal"
        status: pass
    human_judgment: false
  - id: D3
    description: "Idempotency and concurrency: a second escalation, also through a stale instance, is a field error on comment and creates no second comment (row lock)"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#refuses a second escalation as a field error on the comment and creates no second comment"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#creates no second comment when a modal opened before another session escalated is submitted"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#hides the escalate action on the Partner page while the task is escalated"
        status: pass
    human_judgment: false
  - id: D4
    description: "The Admin sees the escalation (who, when) on the task page and clears it; the Partner who is the assignee clears it on the own task page; clearing writes only the escalation pair; a Partner can escalate again"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#shows the Admin the marker with the Partner and the time, clears it, and lets the Partner escalate again"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#lets the Partner who is the assignee clear the flag from the own task page and nothing else changes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#shows the Admin no marker and no clear action while the task is not escalated"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#refuses to clear a task that is not escalated as a field error on task"
        status: pass
    human_judgment: false
  - id: D5
    description: "A Partner who is not the assignee, a Partner of another client, and a Partner reassigned away after loading the page cannot clear a flag; a Partner assignee still cannot change priority or status"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#does not let the escalating Partner clear the flag when the Partner is not the assignee"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#does not let a Partner of another client clear a flag"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#refuses a Partner who was reassigned away after loading the page, checking the right on the locked row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#keeps priority and status with the Admin even for a Partner who is the assignee"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskEscalationTest.php#keeps a Partner of another client out of the escalation"
        status: pass
    human_judgment: false
  - id: D6
    description: "Partner comments and the escalation reason pass the same sanitising as Admin comments and are stored and rendered clean on both sides (D-10 Partner canary)"
    requirement: TA-04
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#stores and renders a Partner comment without script, handler, script link or style, on both sides"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#cleans a Partner comment written around the Action again when it is rendered"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#sanitises the reason of an escalation like any other Partner comment"
        status: pass
    human_judgment: false
  - id: D7
    description: "The task_comments column list is pinned, and no internal comment, its text or its count reaches a Partner page, relation manager, query or count"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Isolation/PartnerSafeColumnsTest.php#pins the Partner-safe column list of the task_comments table"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskCommentsTest.php#reveals no internal comment of an own task on any Partner surface, in a query or in a count"
        status: pass
    human_judgment: false
  - id: D8
    description: "The look of the escalation modal, the danger badge and the gray clear action, and the Czech copy in light and dark mode in a real browser"
    requirement: TA-07
    verification: []
    human_judgment: true
    rationale: "Colour roles and modal copy cannot be asserted by the Pest/Livewire harness; the UI-SPEC visual check belongs to the phase gate"

duration: about 45min
completed: 2026-10-09
status: complete
commits: 4
plan_head_before: 56d98d4301599dea70b0618b3578631941c2fba5
plan_head_after: 8f0eb89d737f55e43269ac958d2c60f7a9d21306
---

# Phase 5 Plan 13: Partner Comments and Escalation Summary

**A Partner discusses and escalates an own task from its page (required reason, one visible escalation comment, who and when recorded, priority never touched), and the Admin or the Partner who is the task's assignee clears the flag, with the right checked on the row re-read under a lock.**

## Performance

- **Duration:** about 45 min of wall clock
- **Tasks:** 3 (tracer, TDD, TDD)
- **Files:** 12 (5 created, 7 modified)
- **Commits:** 4 task commits (2 feat, 2 test)

## Accomplishments

- The Partner task page has a "Komentáře" tab (`PartnerTaskCommentsRelationManager`): non-internal comments only (the Partner scope on `TaskComment` already drops internal rows), columns Autor, Napsáno, Komentář and the "Eskalace" badge, one create action with a rich editor, no internal toggle, no edit, delete or bulk action. The form hands only the body to `AddTaskComment`.
- `EscalateTask`: authorises `escalate`, re-reads the task under `lockForUpdate`, refuses an already escalated task as a field error on `comment`, stores the reason as one visible escalation comment (an empty reason is re-keyed from `body` to `comment`), then writes `escalated_at` and `escalated_by_id`. Priority, status and people are not touched.
- `ClearEscalation`: re-reads the task under `lockForUpdate` through the scoped query and authorises `clearEscalation` on that fresh row; a task that is not escalated is a field error on `task`; only the two escalation columns are set to null.
- `TaskPolicy::clearEscalation`: the Admin always (base `before()`), a Partner only on a task of the own client whose `assignee_id` is that Partner. `update`, `delete` and `restore` stay denied.
- Partner page header actions "Eskalovat úkol" (warning, modal with a required rich-text "Důvod eskalace", hidden while escalated) and "Zrušit eskalaci" (gray, confirmation, visible while escalated and only when the policy grants it). Admin task page: the "Eskalace" entry directly after Priorita (danger badge, "Eskalováno (name, datetime)") and the "Zrušit eskalaci" header action while escalated and not archived.
- `task_comments` column list pinned with a suspicious-name check; Partner comments and the escalation reason proven clean on both sides; an internal-comment canary proven absent from the Partner page, the relation manager, queries and counts.

## Task Commits

1. **Task 1 (tracer): Partner comments tab** - `384c409` (feat)
2. **Task 2 RED: failing escalation tests** - `d2f2308` (test)
3. **Task 2 GREEN: EscalateTask, ClearEscalation, policy, page actions** - `0f7bfb5` (feat)
4. **Task 3: sanitising, column pin, no internal comment on a Partner surface** - `8f0eb89` (test)

Tracer gate (auto mode): the tracer `<verify>` (Pest on the comments test and the panel registry arch test, Pint, PHPStan) ran green before the commit and the Pest part again after it, before expansion.

## TDD Gate Compliance

- **Task 2 RED:** `d2f2308`. All 17 cases failed. Each failed on the planned gap: the `escalate` and `clearEscalation` actions did not exist on the pages, and `EscalateTask` and `ClearEscalation` resolved to a `BindingResolutionException`. The fixture worked (the `beforeEach` and the `UpdateTask` refusal of a Partner already held). Semantic assessment: the failures are the missing feature, not setup, import or fixture faults. Pest has no report format supported by `gsd_run check tdd-red-evidence` (the plan is not `type: tdd`), so no machine record exists.
- **Task 2 GREEN:** `0f7bfb5`. 17 cases pass; one test (the modal after another session escalated) was rewritten during GREEN, see Deviations.
- **Task 3** is marked `tdd="true"` but has only a `test(05-13)` commit and no `feat(05-13)` after it, like plan 05-12: the behaviour (sanitising through `AddTaskComment`, the Partner scope, the relation manager) already existed from 05-09 and Task 1, so every new test passed on its first meaningful run (unexpected green, investigated: the tests are not vacuous, see the mutation table). No gap was found, so no GREEN code commit was needed.
- **REFACTOR:** none needed.

### Mutation runs (security-critical guarantees)

All mutations were re-edited back or reverted with `git checkout -- <file>` on committed, otherwise unchanged files.

| Mutation | Result |
|---|---|
| `clearEscalation` without the `assignee_id` condition | the non-assignee test and the reassigned-away test fail |
| `ClearEscalation` authorises on the caller's instance, not the locked row | the reassigned-away test fails |
| `EscalateTask` checks `escalated_at` on the caller's instance | the second-escalation (stale instance) test fails |
| `ClearEscalation` also resets priority | the Admin-clear and assignee-clear tests fail |
| `EscalateTask` also raises priority | the escalate test and the Admin-clear test fail |
| Partner `update` granted (to the assignee) | the "assignee cannot change priority or status" test fails |
| Partner relation manager renders the body without `RichText::render` | the render-time cleaning test fails |
| `AddTaskComment` does not clean on write | the stored-and-rendered sanitising test and the escalation sanitising test fail |
| `TaskComment` Partner scope without `is_internal = false` | the Partner-sees-only-visible test and the internal canary test fail |

The first run of the "clear also resets priority" mutation survived because the fixture task had the default priority; the fixture now sets priority `high` and status `in_progress` in `beforeEach`, after which the mutation is caught.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug, test side] Existing assertion that the Partner page has no header action**
- **Found during:** Task 2
- **Issue:** `tests/Isolation/PartnerTaskVisibilityTest.php` asserted `getCachedHeaderActions()` is `[]`, which is no longer true once the two escalation actions exist.
- **Fix:** the assertion now lists exactly `['escalate', 'clearEscalation']`; the no-`edit`, no-`delete` assertions stay.
- **Files modified:** `tests/Isolation/PartnerTaskVisibilityTest.php` (outside `files_modified`, one line)
- **Commit:** `0f7bfb5`

**2. [Plan nuance] Test for an escalation submitted from a stale modal**
- **Found during:** Task 2 GREEN
- **Issue:** the draft test expected a field error when the modal is submitted after another session escalated. In the page, Filament evaluates the action's visibility on the fresh record, so the hidden `escalate` action is a silent no-op, and the field error is reachable only through the Action itself.
- **Fix:** the Action-level stale-instance test keeps the field-error and row-lock proof; the modal test now proves that no second comment and no change of `escalated_by_id` result.
- **Commit:** `0f7bfb5`

### Other departures from the plan text (no behaviour change)

- **Copy keys:** the Admin side uses `kokpit.tasks.escalation.*` (label, value, reason, modal and toast copy); the Partner entry keeps its existing `kokpit.partner_tasks.escalation.*` keys from 05-12, so label and value text exist twice with identical wording. Action labels are `kokpit.tasks.actions.escalate` and `kokpit.tasks.actions.clear_escalation` as planned.
- **`PartnerTaskResource`** docblock updated: it now has one relation (the comments tab), registered in `getRelations()`.
- **A `pint` run on `lang/` by mistake** reformatted six untouched Laravel language files (`actions`, `auth`, `http-statuses`, `pagination`, `passwords`, `validation`); they were restored with `git checkout -- <file>` before any commit and are not part of any commit.
- **Admin clear action** is hidden on an archived task (an archived task is read-only until restored, like the Edit action).

### Left untouched on purpose (per the run instructions)

- UI-SPEC F-8 (`ProjectStatus::InReview` colour): not changed.
- UI-SPEC F-9 label changes on built Admin pages: not changed.
- UI-SPEC E-5 (refused-move toast on the boards): not part of this plan.
- No notification is sent for an escalation or a comment yet; that is plans 05-15 and 05-16.

**Total deviations:** 1 auto-fixed (test side), 1 plan nuance, plus the departures above. **Impact on plan:** none on scope.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-05-30 (priority or status change through escalation or clearing), T-05-42 (a non-assignee clears the flag), T-05-31 (stored XSS through Partner comments) and T-05-32 (racing escalations) are mitigated and each has a named test and a mutation run above. No package was added (T-05-SC).

## Issues Encountered

- A ddev Pest run right after a file write occasionally read a stale copy (bind-mount timing); re-running after a short wait gave the true result.
- Full suite at the last commit: 1912 passed (14037 assertions), Pint and PHPStan clean, `scripts/check-sensitive.sh` clean on every commit. No unrelated failures.

## Human Checks for the Phase Gate

1. As a Partner open an own task: check the "Komentáře" tab (add a comment with bold text), "Eskalovat úkol" (warning colour, modal copy, required "Důvod eskalace") and, after escalating, the danger "Eskalace" entry in light and dark mode at 375 px and desktop width.
2. As the Admin open the same task: check the "Eskalace" entry directly after Priorita, the comment row with the "Eskalace" badge and the gray "Zrušit eskalaci" action with its confirmation.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plans 05-14 to 05-16 can notify on a Partner comment and an escalation (to the assignee, falling back to the Admin) by hooking after the store in `AddTaskComment` and `EscalateTask`; internal comments must still never notify a Partner.
- Phase 5 plan 05-17 (phase gate) can run the visual checks above.

## Self-Check: PASSED

- All five created files exist on disk; commits `384c409`, `d2f2308`, `0f7bfb5`, `8f0eb89` are ancestors of HEAD.
- Acceptance greps of all three tasks pass (`Audience::PartnerAllowed`, `AddTaskComment`, `lockForUpdate` and `already_escalated` in `EscalateTask`, `escalation: true`, `clearEscalation` and `lockForUpdate` in `ClearEscalation`, `EscalateTask` and `ClearEscalation` on the pages, `assignee_id` in the policy, `'task_comments'` in the pin test).
- Plan verification: full Pest suite (1912 passed), Pint and PHPStan clean.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
