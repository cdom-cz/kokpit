---
phase: 05-tasks-and-kanban
plan: 20
subsystem: tasks
tags: [filament, policy, partner-isolation, rich-text, activity-log, laravel]
gap_closure: true
gap_ids: [G-05-5]

requires:
  - phase: 05-tasks-and-kanban
    provides: "05-13 escalation actions and Partner task page, 05-19 hardened task write paths, RichText sanitiser (D-10), TaskInput"
provides:
  - "TaskPolicy::editDescription with TaskPolicy::DESCRIPTION_EDITABLE_STATUSES (D-16: Planned or To clarify)"
  - "UpdateTaskDescription domain Action: description only, ability re-checked on the locked row, stale and unchanged saves, history row without text"
  - "Header action 'Upravit popis' on the Partner task page and its Czech copy"
  - "Activity event description_changed ('Popis upraven') in the task history and the overview filter"
affects: [05-21 Admin notification of a Partner description edit (injects TaskNotifier into UpdateTaskDescription), phase 05 verification and security gates]

estimate:
  tokens: 56000
  raw_tokens: 56000
  tasks: 3
  confidence: low
actuals:
  tokens: 11000
  tasks: 3
  commits: 3
plan_head_before: 4becb0f7acfcc675d3c4596ef046d3945c199111
plan_head_after: 6fab92e156d8544e8f2e5f557b4d57247656dd2c

tech-stack:
  added: []
  patterns:
    - "One policy ability decides visibility, pre-check and the re-check on the locked row (no second copy of the status rule)"
    - "Action takes no data array: only ?string description and the fingerprint of the description the editor was opened with"
    - "Manual activity row through the package logger with an event and no properties, so no text can reach the log"

key-files:
  created:
    - app/Domain/Tasks/Actions/UpdateTaskDescription.php
    - tests/Feature/Tasks/PartnerTaskDescriptionTest.php
  modified:
    - app/Domain/Tasks/Policies/TaskPolicy.php
    - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
    - app/Filament/Resources/ActivityResource.php
    - lang/cs/kokpit.php
    - tests/Isolation/PartnerTaskVisibilityTest.php
    - tests/Feature/Tasks/TaskEscalationTest.php

key-decisions:
  - "The D-16 status rule lives only in TaskPolicy::editDescription; the Action evaluates the same ability on the locked row (denies('editDescription', $locked)) instead of copying the status list"
  - "A status change out of the two editable statuses while the editor is open: page level the hidden action is not run (Filament disabled check), Action level a field error under description"
  - "The overview filter case asserts the filter offers the option (labelled), because filterTable accepts any value and could not fail otherwise"

requirements-completed: [TA-07, TA-01, KB-03]

duration: 10min
completed: 2026-10-09
status: complete
---

# Phase 5 Plan 20: Partner description edit (G-05-5) Summary

**A Partner edits only the description of an own client-visible task in the status Planned or To clarify, through a policy ability and a domain Action that re-checks the status on the locked row, sanitises the text, refuses stale saves, skips unchanged ones and logs the change without its text.**

## Performance

- **Duration:** about 10 min of executor time (plus a 3 min full-suite run)
- **Started:** 2026-10-09T09:04:49Z
- **Completed:** 2026-10-09T09:14Z
- **Tasks:** 3
- **Files modified:** 8 (2 created, 6 modified)

## Accomplishments

- Gap G-05-5 closed for the Partner side: header action "Upravit popis" on the own task page, modal with the description editor only (no file attachments, toolbar of the Partner create form), toast "Popis byl uložen".
- `TaskPolicy::editDescription` (own client-visible project, task not archived, status in `DESCRIPTION_EDITABLE_STATUSES`); `update` stays denied to every Partner, the Partner still has no edit page, edit route or list row action.
- `UpdateTaskDescription` writes the description and nothing else: authorise, clean (`TaskInput::description`), row lock through the Partner-scoped query, ability re-check on the locked row, fingerprint comparison, unchanged shortcut, one `description_changed` activity row without properties.
- Admin sees "Popis upraven" in the task history and can filter the activity overview by it.
- 21 Pest cases in `PartnerTaskDescriptionTest.php` plus the two updated header pins; full suite 2036 passed, Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, Partner edits the description, history row for the Admin** - `efdf585` (feat; RED run recorded below, the plan prescribes one commit per task)
2. **Task 2: only the description, only in D-16 statuses, always cleaned, never logged** - `72d9b7a` (test)
3. **Task 3: stale, status flip, unchanged, overview filter, mutations, plan gate** - `6fab92e` (feat: the filter option; tests)

**Plan metadata:** recorded in the docs commit that follows this file.

## RED evidence (Task 1 tracer)

Command: `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskDescriptionTest.php` before any production change. Result: 2 failed, 0 passed assertions beyond the set-up.

- `it lets a Partner edit the description of an own task from the task page and logs it in the task history` failed on `assertActionVisible('editDescription')`: "Failed asserting that an action with name [editDescription] is visible ... null is an instance of class Filament\Actions\Action".
- `it keeps the header actions of the Partner task page ...` failed on the header list: expected `['editDescription', 'escalate', 'clearEscalation']`, actual `['escalate', 'clearEscalation']`.

Semantic assessment: the target tests executed (the `beforeEach` set-up, including the Partner-created task, ran), and failed on the planned assertion because the action does not exist yet, not on a syntax, fixture or load fault. The Overview case of Task 3 was RED the same way: the first version passed because `filterTable()` accepts any value, so it was strengthened to assert that the filter offers the labelled option, failed with "an array has the key [description_changed]", then went GREEN with the `ActivityResource` change.

## Mutation runs

Each mutation applied to a committed file, the file run, then reverted with `git checkout -- <file>`; the file was green again (21 passed) after the last revert. The container sync was verified with `ddev exec grep` before each run.

| Mutation | Failed cases | Revert |
|---|---|---|
| (a) `TaskPolicy::editDescription` returns `$user->client_id !== null` (any Partner with a client) | 7 failed: `keeps a Partner of another client, a hidden project and an archived task out of the description edit`, 4x `refuses the description edit to a Partner in every other status`, 2x `refuses the save when the status left the editable statuses while the editor was open` | `git checkout -- app/Domain/Tasks/Policies/TaskPolicy.php` |
| (b) `$clean = $description;` instead of `TaskInput::description($description)` | 3 failed: `stores and shows an edited Partner description without any hostile part (D-10)`, `refuses an over-long description as a field error and writes nothing`, `clears the description when the editor is emptied` | `git checkout -- app/Domain/Tasks/Actions/UpdateTaskDescription.php` |
| (c) fingerprint comparison disabled (`if (false && ! hash_equals(...))`) | 1 failed: `refuses a save based on a description that changed since the modal opened` | same file |
| (d) unchanged-value return disabled (`if (false && $clean === $locked->description)`) | 1 failed: `writes nothing and logs nothing when the description is saved unchanged` | same file |
| (e) status condition dropped from `TaskPolicy::editDescription` | 6 failed: 4x `refuses the description edit to a Partner in every other status`, 2x `refuses the save when the status left the editable statuses while the editor was open` | policy file |
| (f) re-check 3b disabled (`if (false && Gate::forUser($actor)->denies('editDescription', $locked))`) | 2 failed: `refuses the save when the status left the editable statuses while the editor was open` (Action-level half, both datasets) | Action file |

## Files Created/Modified

- `app/Domain/Tasks/Actions/UpdateTaskDescription.php` - the only Partner write path of a description (`fingerprint()`, `handle()`).
- `app/Domain/Tasks/Policies/TaskPolicy.php` - `DESCRIPTION_EDITABLE_STATUSES`, `editDescription`, docblock with the D-16 rule.
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php` - `editDescription` header action first, then `escalate`, `clearEscalation`.
- `app/Filament/Resources/ActivityResource.php` - event filter option `description_changed`.
- `lang/cs/kokpit.php` - seven Czech strings (action, heading, submit, toast, stale and not-editable errors, event label).
- `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` - 21 cases (new).
- `tests/Isolation/PartnerTaskVisibilityTest.php`, `tests/Feature/Tasks/TaskEscalationTest.php` - header pins updated to the three actions.

## Decisions Made

- The status rule is evaluated by the policy on both the passed instance and the locked row, so the list of editable statuses exists once.
- `UpdateTaskDescription::handle` returns the caller's refreshed `$task`, matching `EscalateTask`; its public signature is final for plan 05-21, which adds a `TaskNotifier` constructor dependency only.
- Archived tasks are not editable even for the Admin through this Action (the soft-delete scope of the locked re-read gives a not-found error), consistent with the archive decision; the Admin edits descriptions through the existing edit page.

## Deviations from Plan

None - plan executed exactly as written. Notes that are not deviations:

- The Task 2 cases passed on the Task 1 code at the first run (as the plan expected), so no production file changed in Task 2.
- Task 3: the first run of the status-flip case failed because my test counted history rows before the Admin's own status change (which writes an `updated` row); the test now takes the baseline after the Admin's change. Production code was not involved.
- The overview-filter case was strengthened (option assertion) so that it can fail before the `ActivityResource` change, as the plan's RED step requires; see the RED evidence section.

## TDD Gate Compliance

`workflow.tdd_mode` is off. The plan prescribes one commit per task, so Task 1's RED run is recorded above rather than committed separately. Task 2 is test-only by design (guards proven by the mutation table); Task 3 has its RED (overview option) and GREEN (`ActivityResource`) in one commit. No refactor commits were needed.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None - the new surface (the `editDescription` action and Action) is the one modelled as T-05-47 to T-05-51 and T-05-56 in the plan's threat model; each is covered by a named test and, for the guards, by a mutation above.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 05-21 can inject `TaskNotifier` into `UpdateTaskDescription` and call it after the history row (step 7) without changing the public signature.
- `ActivityResource` already lists the `description_changed` event; 05-VALIDATION.md, 05-SECURITY.md and 05-VERIFICATION.md were deliberately not touched (later gates update them).

## Self-Check: PASSED

- Created files exist: `app/Domain/Tasks/Actions/UpdateTaskDescription.php`, `tests/Feature/Tasks/PartnerTaskDescriptionTest.php`.
- Commits `efdf585`, `72d9b7a`, `6fab92e` are ancestors of HEAD; `git rev-list --count 4becb0f..HEAD` = 3 before this file.
- All acceptance criteria of the three tasks re-run: PASS. Plan verification: full Pest suite 2036 passed, `pint --test` clean, PHPStan no errors, `scripts/check-sensitive.sh` clean on every commit, six mutations recorded and reverted.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
