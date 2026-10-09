---
phase: 05-tasks-and-kanban
plan: 09
subsystem: tasks
tags: [laravel, postgres, filament, comments, partner-isolation, rich-text]

requires:
  - phase: 05-tasks-and-kanban
    provides: Task model and policy (comment ability), RichText sanitiser, admin-only relation manager pattern, canary fixtures for Task
provides:
  - task_comments table (body CHECK, internal/escalation exclusion CHECK, task and author RESTRICT FKs)
  - TaskComment model hiding internal rows from every Partner query
  - TaskCommentPolicy (Partner view of non-internal, create; update/delete denied)
  - AddTaskComment Action (RichText::clean, body field errors, Partner and escalation forcing)
  - Admin TaskCommentsRelationManager on task and subtask pages
affects: [05-13 Partner comment UI and escalation, 05-15 notifications, phase-06 time]

actuals:
  tokens: 9700
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "Partner scope that filters a flag column and delegates the parent rules to the scoped parent query (TaskComment over Task::query())"
    - "Server-side forcing of a privileged flag in the Action, pinned by a mutation run"
    - "Append-only relation manager: create header action only, no record or bulk actions"

key-files:
  created:
    - database/migrations/2026_10_10_000500_create_task_comments_table.php
    - app/Domain/Tasks/Models/TaskComment.php
    - app/Domain/Tasks/Policies/TaskCommentPolicy.php
    - app/Domain/Tasks/Actions/AddTaskComment.php
    - app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php
    - tests/Feature/Tasks/TaskCommentsTest.php
  modified:
    - app/Domain/Tasks/Models/Task.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - app/Filament/Resources/TaskResource.php
    - lang/cs/kokpit.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Arch/ModelDeclarationTest.php

key-decisions:
  - "The internal flag is honoured only for an Admin actor (fail-closed), not merely ignored for a Partner: any other actor, and any escalation comment, is stored as not internal"
  - "Comments are append-only and not activity-logged: no soft delete, no edit or delete action, no LogsAllowlistedActivity on TaskComment"
  - "Migration body CHECK trims space, tab and line breaks (same expression as the checklist text check) so a whitespace-only body is refused by the database too"
  - "The Admin tab shows a separate 'escalation' badge column next to the internal badge, ready for plan 05-13"

patterns-established:
  - "Canary fixture with an internal twin: two comments carrying the canary, the exact-one-row registry rule proves the internal one is hidden"
  - "Test helpers in Feature/Tasks use a feature prefix (comment...) because Pest helper functions are global"

requirements-completed: [TA-04]

coverage:
  - id: D1
    description: "The Admin adds rich-text comments to a task and a subtask, optionally internal, listed oldest first with author, time and an internal badge"
    requirement: TA-04
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#adds a comment from the task page, once visible and once internal, with author, flags and a clean body"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#comments on a subtask the same way"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#lists the comments newest last"
        status: pass
    human_judgment: false
  - id: D2
    description: "Bodies are cleaned on write and on render; an empty or script-only body and an over-long body are field errors on body"
    requirement: TA-04
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#cleans the body before it is stored and renders it without markup that could run"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#refuses a body with no text left as a field error on body and stores nothing"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Partner never reads an internal comment: the canary registry shows Partner A exactly the non-internal canary comment"
    requirement: TA-04
    verification:
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#shows a Partner of the own client only the non-internal comment, in the relation and in the count"
        status: pass
    human_judgment: false
  - id: D4
    description: "Partner comments and escalation comments are never internal (server-side forcing, mutation-checked); comments are append-only and not activity-logged"
    requirement: TA-04
    verification:
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#stores a Partner comment as not internal whatever the payload says"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#writes no activity row for a comment, so an internal text never reaches a history"
        status: pass
      - kind: unit
        ref: "tests/Feature/Tasks/TaskCommentsTest.php#offers no edit, delete or bulk action on the comments tab"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-10-09
status: complete
commits: 3
plan_head_before: d886bcde1f9f77a8345b78c2db6ace3f38954466
plan_head_after: 0c148f1a63d722053608d7a41812e722b46dccd6
---

# Phase 5 Plan 09: Task Comments Summary

**Append-only task and subtask comments with an internal flag: a Partner-scoped TaskComment model that filters internal rows at the data layer, an AddTaskComment Action that cleans the body with RichText and forces the flag off for every non-Admin actor, and an Admin comments tab.**

## Performance

- **Duration:** about 25 min
- **Completed:** 2026-10-09
- **Tasks:** 2 (tracer, TDD)
- **Files:** 14 in the code commits (6 created, 8 modified)

## Accomplishments

- `task_comments` table: UUID v7 key, RESTRICT foreign keys to `tasks` and `users`, `body` text with a non-blank CHECK, `is_internal` and `is_escalation` flags with the CHECK `task_comments_internal_escalation_check` (an escalation is never internal), index on (`task_id`, `created_at`), no soft delete.
- `TaskComment`: `PartnerIsolated`; `constrainForPartner` keeps `is_internal = false` rows whose task is in the Partner-scoped `Task::query()`. Only `body` is fillable; task, author and flags go through `forceFill`. `Task::comments()` is ordered by `created_at`, then `id`.
- `TaskCommentPolicy`: Partner `viewAny`, `view` (non-internal comment of a viewable task), `create`; `update` and `delete` keep the base denial.
- `AddTaskComment`: authorises `comment` on the task, cleans the body with `RichText::clean` (empty becomes the `body_empty` field error, over-length becomes `body_too_long`), stores task, author and flags with `forceFill`.
- `TaskCommentsRelationManager`: Admin-only tab on task and subtask pages; columns author, time, body through `RichText::render`, internal and escalation badges; create action with a `RichEditor` (no attachments) and an internal toggle, delegating to the Action; no edit, delete or bulk action.
- Canary fixture creates a visible comment and an internal twin that both carry the canary; the existing exact-one-row rule therefore fails if an internal comment is ever returned to Partner A.

## TDD Gate Compliance

- RED: `b6b2b2a` test(05-09). 2 of the 17 cases failed, each on the planned assertion: a Partner comment with `internal: true` was stored internal (`Failed asserting that true is false`), and an escalation comment asked to be internal hit the database CHECK (`task_comments_internal_escalation_check`) instead of being stored visible. The other behaviour cases (cross-client refusal, empty body, edit/delete denial, no activity row, no row actions) already held after the tracer, because the tracer implemented the authorisation, sanitising and policy parts of the contract; they are kept as regression pins. Semantic assessment: both failures are the intended behaviour gap (missing forcing), not setup, import or fixture faults. One more failure in the first RED run was a defect of my own test (header action keys are numeric) and was fixed before the RED commit. Pest does not emit a report format that `gsd_run check tdd-red-evidence` supports (plan is not `type: tdd`), so no machine record exists.
- GREEN: `0c148f1` feat(05-09). All 17 cases pass; full suite 1818 passed.
- REFACTOR: none needed.

Mutation run (T-05-20): with the forcing line in `AddTaskComment` commented out, `stores a Partner comment as not internal whatever the payload says` and `stores an escalation comment as visible even when it is asked to be internal` both fail (2 failed, 15 passed). The line was re-edited back and the 17 cases pass again.

## Task Commits

1. **Task 1 (tracer): table, model, policy, Action, Admin tab, canary and registries** - `1111553`
2. **Task 2 RED: failing tests for Partner forcing and append-only comments** - `b6b2b2a`
3. **Task 2 GREEN: force Partner and escalation comments to be visible** - `0c148f1`

Tracer gate (auto mode): the tracer `<verify>` (targeted Pest files, Pint, PHPStan) was run before the commit and the Pest part re-run after it, all green, before expanding.

## Deviations from Plan

### Auto-fixed Issues

**1. [Plan nuance] Forcing deferred from the tracer to Task 2**
- **Found during:** Task 1
- **Issue:** The artifact table describes the final `AddTaskComment` including the Partner forcing, but Task 2 is the TDD task for exactly that behaviour. Implementing it in Task 1 would have left no real RED.
- **Fix:** The tracer stored the flags as given (only the Admin UI called it); Task 2 added the forcing. Nothing could call the Action with a Partner between the two commits (the Partner UI is plan 05-13).
- **Commit:** `1111553`, `0c148f1`

**2. [Rule 2 - Missing critical] Fail-closed internal flag**
- **Found during:** Task 2
- **Issue:** Forcing only for `Partner` would let any other actor that passes the policy store an internal comment.
- **Fix:** The flag is honoured only when the actor has the Admin role and the comment is not an escalation.
- **Files modified:** `app/Domain/Tasks/Actions/AddTaskComment.php`
- **Commit:** `0c148f1`

**3. [Plan nuance] Extra Czech strings and a test double-check**
- `body_empty` is used as planned; `body_too_long` was added next to it for the length refusal, plus the `tasks.comments` block of UI labels.

**Total deviations:** 3 (1 Rule 2, 2 plan nuances). **Impact:** no scope change, no file outside `files_modified` touched.

## Known Stubs

None.

## Threat Flags

None. The new surface (comments table, Partner read of non-internal comments) is covered by T-05-19 to T-05-21; no package was added (T-05-SC).

## Issues Encountered

None blocking. One ddev Pest run right after a file write read a stale copy (bind-mount timing) and was re-run after a short wait. Full-suite run: 1818 passed.

## Next Phase Readiness

Ready for 05-10. Plan 05-13 can reuse `TaskComment`, `TaskCommentPolicy` and `AddTaskComment` (its `escalation` argument) for the Partner comment UI and `EscalateTask`; plan 05-15 adds the notification dispatch after the store. The `task_comments` column pin joins `PartnerSafeColumnsTest` in plan 05-13 as planned.

## Self-Check: PASSED

- All created files exist on disk; commits `1111553`, `b6b2b2a`, `0c148f1` are ancestors of HEAD.
- Acceptance criteria re-run: migration check name, `is_internal` and `Task::query()` in the model, `RichText::clean` in the Action, canary fixture and morph alias, `authorize('comment'` in the Action, policy `view` and `create` on `KokpitPolicy`: all present. Targeted Pest files, full Pest suite (1818 passed), Pint and PHPStan: green.
