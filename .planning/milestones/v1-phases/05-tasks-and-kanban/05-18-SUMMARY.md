---
phase: 05-tasks-and-kanban
plan: 18
subsystem: tasks
tags: [filament, livewire, select-options, partner-isolation, tdd, gap-closure]

requires:
  - phase: 05-tasks-and-kanban
    provides: "UpdateTask unchanged-person rule, TaskPeople allowed set, CreateTask Partner drop list (plans 05-01 to 05-17)"
provides:
  - "Task edit form that keeps the stored assignee/requester selectable even when deactivated, offered only in its own field"
  - "CreateTask Partner drop list that also removes tags"
  - "Livewire and Action tests for both seams with recorded mutation runs"
affects: [05-19, phase-07-api]

actuals:
  tokens: 3900
  tasks: 3
  commits: 5
plan_head_before: 515fce1617e78e60c1cdffa5f629f4b384d65ea2
plan_head_after: 7676201f79c2255ad5dbcddc0324dfa4c4bdb36c

tech-stack:
  added: []
  patterns:
    - "Select options = domain allowed set plus the field's stored value; the domain Action stays the only judge of a changed value"
    - "Tests that read Partner-hidden rows (task tags) do so in a system run, with an Admin control case"

key-files:
  created: []
  modified:
    - app/Filament/Resources/TaskResource.php
    - app/Domain/Tasks/Actions/CreateTask.php
    - tests/Feature/Tasks/TaskUpdateTest.php
    - tests/Feature/Tasks/TaskActionsTest.php

key-decisions:
  - "peopleOptions adds only the record's current person of the same field; TaskPeople and UpdateTask are untouched, so every changed person is still re-checked under the row lock"
  - "tags join the Partner unset() in CreateTask, before any input is parsed, so a forged Partner tags value is no error and has no effect"

requirements-completed: [TA-01, TA-07]

coverage:
  - id: D1
    description: "The Admin saves any edit of a task whose assignee or requester was deactivated since, with no form error, and the person stays"
    requirement: TA-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#saves a rename of a task whose assignee was deactivated and keeps the assignee"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#keeps a deactivated requester when the Admin saves another change"
        status: pass
    human_judgment: false
  - id: D2
    description: "A deactivated current person is offered only in the field that holds it; no other deactivated or foreign account is offered"
    requirement: TA-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#offers a deactivated person only in the field that holds it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#offers exactly the active Admin and the active Partners of the project client as people"
        status: pass
    human_judgment: false
  - id: D3
    description: "A new pick of a deactivated or foreign account, a re-pick after switching away, and a person deactivated between page load and save are refused as a field error by the form and by UpdateTask"
    requirement: TA-01
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#refuses a new pick of a deactivated or foreign account"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#refuses picking a deactivated person again after the task moved away from them"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskUpdateTest.php#refuses a person deactivated between page load and save"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner-originated CreateTask payload with tags creates the task with no tag; the Admin stores the same tags; a refused Partner creation writes no task and no tag"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskActionsTest.php#drops the tags of a Partner payload and stores the same tags for the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskActionsTest.php#writes no tag when a Partner creation with tags is refused"
        status: pass
    human_judgment: false

duration: 12min
completed: 2026-10-09
status: complete
---

# Phase 5 Plan 18: People options keep the stored person, Partner tags dropped Summary

**Admin task edit form keeps a deactivated assignee/requester selectable in its own field only (CR-01), and CreateTask drops a Partner's tags before any parsing (G-1), both proven with mutation runs.**

## Performance

- **Duration:** 12 min
- **Started:** 2026-10-09T05:38:32Z
- **Completed:** 2026-10-09T05:50:00Z
- **Tasks:** 3
- **Files modified:** 4

## Accomplishments

- CR-01 closed: `TaskResource::peopleOptions($task, $field)` returns the `TaskPeople::options` set plus the record's stored person of that field when it is no longer active (name read through `User::query()->whereKey()->value('name')`). Renaming or re-prioritising a task whose Partner assignee or requester was offboarded now saves, and the person stays.
- The kept person is a convenience for the unchanged value only: a deactivated or foreign account can not be newly picked, whether by the form (Filament's option check gives a field error under the field), by a forged Livewire value, by re-picking a person the task moved away from, or by a person deactivated between page load and save. `UpdateTask` raises the same field error when the form is bypassed.
- G-1 closed: `$data['tags']` joins the Partner `unset(...)` in `CreateTask::handle`, ahead of `TaskInput::tags`; the docblock names tags among the ignored Partner inputs. A refused Partner creation writes no task and no tag (project check precedes any write), pinned by a test.

## RED evidence

| Case | Command | Result on the pre-fix code |
|---|---|---|
| Task 1: `saves a rename of a task whose assignee was deactivated and keeps the assignee` | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskUpdateTest.php --filter="saves a rename of a task whose assignee"` | exit 1; `Component has errors: "data.assignee_id" => ["Zvolená hodnota pro řešitel je neplatná."]` raised by `assertHasNoFormErrors()` after `call('save')` (the reviewer's probe result). Semantic assessment: the target test ran and failed on the planned assertion (no form error on a plain rename), not on setup. (A first draft that asserted the option list before saving failed one step earlier on the missing option; the assertions were reordered so the RED evidence is the save error.) |
| Task 3: `drops the tags of a Partner payload and stores the same tags for the Admin` | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskActionsTest.php --filter="tags"` | exit 1; `Failed asserting that two arrays are identical` at the Partner tag assertion: expected `[]`, actual `['CANARY_tag_...']`. Semantic assessment: the Partner's task carried the tag, which is exactly G-1; the second case (`writes no tag when a Partner creation with tags is refused`) passed on the current code, as the plan predicted, and stays as a pin of the project-check-before-write order. |

Task 2 tests were written after the Task 1 fix and passed on it as the plan expected (no `peopleOptions` change was needed); their guard is the mutation run below.

## Mutation runs

| Mutation | Result | Revert |
|---|---|---|
| MUT-P1 `peopleOptions` back to active-only (the current-person branch disabled with `if (false && ...)`) | 4 failed in `TaskUpdateTest`: `saves a rename of a task whose assignee was deactivated`, `keeps a deactivated requester`, `offers a deactivated person only in the field that holds it`, `refuses a person deactivated between page load and save` (the second half, the kept current person) | `git checkout -- app/Filament/Resources/TaskResource.php`, file green again (29 passed) |
| MUT-P2 `$data['tags']` taken out of the Partner `unset(...)` in `CreateTask` | 1 failed: `drops the tags of a Partner payload and stores the same tags for the Admin` (32 others passed) | `git checkout -- app/Domain/Tasks/Actions/CreateTask.php`, file green again |

The first attempt at MUT-P1 showed 29 passed because the DDEV container had not yet synced the edited file; after the sync (verified with `ddev exec grep`) the mutation was applied and the table above is the result. The same wait was used before every later run.

## Task Commits

1. **Task 1: Tracer, rename of a task with a deactivated assignee** - `d3bfec1` (test, RED), `cfae73c` (feat, GREEN)
2. **Task 2: kept person only in its own field, refused new picks** - `9d6c8a5` (test)
3. **Task 3: Partner tags dropped in CreateTask** - `1f6c75e` (test, RED), `7676201` (feat, GREEN)

**Plan metadata:** recorded in the docs commit that follows this file.

## TDD Gate Compliance

Task 1 and Task 3 each have a `test(05-18)` commit before the `feat(05-18)` commit. Task 2 is test-only by design (the Task 1 code already satisfies it); its guard is MUT-P1. No refactor commits were needed.

## Verification

- `ddev exec vendor/bin/pest`: 1990 passed (14807 assertions), including the unchanged people tests of 05-04 and the Partner tests of 05-01 and 05-12.
- `ddev exec vendor/bin/pint --test`: pass (455 files). `ddev exec vendor/bin/phpstan analyse`: no errors.
- `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) clean on every commit.
- Acceptance greps: `peopleOptions($record, 'assignee_id')` and `'requester_id'` in `TaskResource.php`; `unset(...'tags'...)` in `CreateTask.php`; test names `saves a rename of a task whose assignee was deactivated`, `only in the field that holds it`, `between page load and save`, `after the task moved away`, `drops the tags of a Partner payload`.

## Decisions Made

- Only the record's current person of the same field is added to the options; `TaskPeople` (allowed set of a write stays the active set) and `UpdateTask` were not touched, so the form can not widen what a write accepts (T-05-45).
- Partner tags are removed before parsing instead of rejected, matching how status, priority and people are already treated (D-04), so a forged value is no error and no oracle (T-05-46).

## Deviations from Plan

None - plan executed exactly as written. (Test assertion order in the Task 1 case was adjusted before its RED commit so that the recorded RED evidence is the save error named by the plan; this is not a code deviation.)

## Issues Encountered

- DDEV file-sync latency made the first mutation run read the unmutated file (29 passed). Re-run after a short wait and a container-side grep gave the real result. No effect on committed code.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema change; T-05-45 and T-05-46 are mitigated and tested.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Ready for 05-19. Out of scope items (WR-01, WR-03, WR-04, IN-01 to IN-07, UI-REVIEW flags, pending UAT) are untouched and stay open in 05-REVIEW-DISPOSITION.md, which later gates update.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*

## Self-Check: PASSED

- Modified files present: TaskResource.php, CreateTask.php, TaskUpdateTest.php, TaskActionsTest.php.
- Commits d3bfec1, cfae73c, 9d6c8a5, 1f6c75e, 7676201 are ancestors of HEAD.
