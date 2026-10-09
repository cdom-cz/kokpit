---
phase: 05-tasks-and-kanban
plan: 16
subsystem: notifications
status: complete
tags: [notifications, mail, bell, escalation, partner-isolation, canary, leak-proof, tdd]

requires:
  - phase: 05-tasks-and-kanban
    provides: TaskNotifier, TaskNotification base and the internal-comment lock (05-15), NotificationPreferences (05-14), EscalateTask and ClearEscalation (05-13), UpdateTask and MoveTask (05-04, 05-07, 05-10)
provides:
  - TaskEscalatedNotification and TaskNotifier::escalated(), exactly one recipient per escalation (the assignee when it can receive, else the Admin)
  - TaskChangedNotification and TaskNotifier::changed(), one notification per save to the Partner requester and assignee, one line per changed status, priority or assignee
  - dispatch from EscalateTask, UpdateTask and MoveTask
  - tests/Isolation/NotificationLeakTest.php, the canary proof over every Partner notification surface
affects: [05-17 phase gate (owner confirmation of A5 and A13, Mailpit human check)]

actuals:
  tokens: 13100
  tasks: 3
  commits: 4
plan_head_before: 946f78c213eb202f277cde2fb5a0a807bcaad62a
plan_head_after: d96d010fb7537a2dfd057ae9f2efb3e9f03be8e2
commits: 4

tech-stack:
  added: []
  patterns:
    - "One recipient per escalation: the assignee if it passes the same eligibility check as comment recipients and is not the actor, otherwise the Admin; the fallback never looks at preferences"
    - "A change notification is assembled from enum labels and two user names only, so no other task data can reach the message"
    - "A Partner leak proof renders every notification sent on the fake after auth()->logout() (a worker context) and also searches stored rows, decoded mail parts and the real Filament bell"

key-files:
  created:
    - app/Domain/Tasks/Notifications/TaskEscalatedNotification.php
    - app/Domain/Tasks/Notifications/TaskChangedNotification.php
    - tests/Isolation/NotificationLeakTest.php
  modified:
    - app/Domain/Tasks/Notifications/TaskNotifier.php
    - app/Domain/Tasks/Notifications/TaskNotification.php
    - app/Domain/Tasks/Actions/EscalateTask.php
    - app/Domain/Tasks/Actions/UpdateTask.php
    - app/Domain/Tasks/Actions/MoveTask.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/TaskNotificationsTest.php

key-decisions:
  - "A13 applied as written: the escalation fallback to the Admin depends only on whether the assignee can receive, never on preferences; an assignee with escalation off on both channels gets nothing and the Admin is not told in that place (test named after A13)"
  - "The fallback Admin is the first active Admin by creation time (the same account TaskPeople::admin() returns) taken from the notifier's own admin list, so an escalation never throws when no Admin exists"
  - "An actor is never notified of the own action: an Admin who escalates a task assigned to the Admin tells nobody"
  - "Change notifications are sent only for an Admin actor; the old assignee of a reassignment is not told, only the current Partner requester and assignee"
  - "The bell body of a change joins the escaped change lines with <br>, because Filament renders the body as sanitised HTML"

patterns-established:
  - "Mutation proof of a leak test: the guards were switched off and links and body content were corrupted, and the test failed each time (table below)"

requirements-completed: [TA-07]

coverage:
  - id: D1
    description: "A Partner escalation notifies the assignee (Admin or active Partner of the client) on mail and bell; the Admin only when the assignee cannot receive (deactivated, another client, the escalating Partner); exactly one recipient; the Partner link opens /admin/my-tasks/KEY-N"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#escalation notifications (Admin assignee, Partner assignee, deactivated, another client, escalating Partner is the assignee, exactly one recipient)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Preferences narrow escalation delivery (mail off gives the bell only) and never move the notice to the Admin (A13)"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#delivers only the bell to an Admin who switched the e-mail of escalations off"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#A13: tells nobody when the assignee switched escalations off on both channels, the Admin is not a preference fallback"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Admin's status, priority or assignee change tells the Partner requester and assignee once per save, listing every change; a newly assigned Partner is told; a reorder, dates, title, description and an unchanged value tell nobody; preferences narrow delivery"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#change notifications (12 tests: save, assignment, requester and assignee in one person, no-change saves, board move, reorder, done column, both channels off, mail off, deactivated, bell and mail render)"
        status: pass
    human_judgment: false
  - id: D4
    description: "No Partner notification body, mail, bell row, link or Filament bell contains an internal comment, checklist item, tag, billing note, rate, price, estimate or another client's task data, and the bell holds only own rows opening /admin/my-tasks"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationLeakTest.php (5 tests, 391 assertions) with the mutation runs below"
        status: pass
    human_judgment: false
  - id: D5
    description: "How the four mails look in a real mail client (Mailpit) and that a queued worker renders them"
    requirement: TA-07
    verification: []
    human_judgment: true
    rationale: "The plan's human-check needs a running DDEV worker and Mailpit; rendering is asserted as text with no signed-in user, the visual check is recorded as a backstop for the phase gate (05-17)"
---

# Phase 5 Plan 16: Escalation and change notifications Summary

**Escalations go to the task's assignee with the Admin only as fallback, the Admin's status, priority and assignee changes reach the Partner side once per save, and a canary leak test with mutation runs proves nothing internal, financial or foreign reaches a Partner.**

## Performance

- **Duration:** about 20 min (the start time was not captured at the beginning of the run, so this is an estimate)
- **Completed:** 2026-10-09T00:44Z
- **Tasks:** 3 (a tracer and two TDD tasks)
- **Files:** 10 (3 created, 7 modified)

## Accomplishments

- `TaskNotifier::escalated` picks exactly one recipient: the assignee when it passes the shared eligibility check (active, Admin or Partner of the project's client who can still read the task) and is not the actor, otherwise the Admin (unless the Admin is the actor). A Partner assignee gets the `/admin/my-tasks/KEY-N` link, the Admin `/admin/tasks/KEY-N`. `EscalateTask` calls it inside its transaction after the flag is set, which closes the gap that 05-15 left on purpose (`AddTaskComment` stays silent for an escalation comment).
- `TaskNotifier::changed` tells the active Partner requester and assignee once when status, priority or assignee differ after an Admin save (`UpdateTask`) or a board move that changes the column (`MoveTask`). One line per change ("Stav: old to new", "Priorita: ...", "Řešitel: ...") built only from enum labels and the two user names. A reorder, dates, title, description, tags, billing and a save naming the stored values again send nothing.
- `NotificationLeakTest` (5 tests, 391 assertions) seeds canaries (internal comment, checklist item, tag, billing note, hourly rate, fixed price, estimate, and the title, number and project key of a client B task), triggers assignment, visible and internal Admin comments, a status and priority change, a board move, a colleague's comment and escalation, a Partner A escalation to the Admin and client B events, then searches the mails rendered after `auth()->logout()`, the stored rows of both client A Partners, the decoded parts of the sent mails, the Admin's links and the real Filament bell component.
- Full suite 1978 passed (14660 assertions), Pint and PHPStan (level 8) clean, `scripts/check-sensitive.sh` and gitleaks clean on every commit.

## Task Commits

1. **Task 1 (tracer): escalation to the assignee, the Admin as fallback** - `0bfc722` (feat)
2. **Task 2 RED: failing tests for the Admin's change notifications** - `d0f853e` (test)
3. **Task 2 GREEN: change notifications from the form and the board** - `effbf44` (feat)
4. **Task 3: canary leak proof** - `d96d010` (test)

**Tracer gate:** the `<verify>` of Task 1 (targeted tests, Pint, PHPStan) and then `tests/Feature/Tasks`, `tests/Feature/Notifications`, `tests/Arch` and `tests/Isolation` (540 passed) were run before expansion; logged "Tracer verified end-to-end - expanding".

## TDD Gate Compliance

RED `d0f853e` precedes GREEN `effbf44`; no REFACTOR commit was needed. RED evidence (Pest, target file `tests/Feature/Tasks/TaskNotificationsTest.php`, filter `change notifications`, 12 tests, 7 failed, 5 passed, exit 2). Semantic assessment: every one of the seven failures is on the planned assertion for the intended reason (nothing is sent yet): six "actual size 0 matches expected size 1" and one "The expected [TaskChangedNotification] notification was not sent"; none is a load or fixture fault. Five tests passed in RED because they are negative pins that hold trivially before anything is sent (dates, title and description; the stored values named again; a task without a Partner; both channels off; a deactivated requester). They were validated against the GREEN code by mutation runs MUT19 and MUT20 below. No `gsd_run check tdd-red-evidence` record was produced (the classifier does not read Pest console output).

## Mutation runs (security-critical, GREEN code)

| Mutation | Result |
|---|---|
| MUT16 escalation always goes to the Admin (assignee ignored) | 3 failed in `escalation notifications` |
| MUT17 escalation does not exclude the actor | 1 failed |
| MUT19 `MoveTask` notifies on every move, reorder included | failed (reorder test) |
| MUT20 `UpdateTask` notifies on every save, changed or not | 5 failed with MUT19 in the same run (no-change, dates and save tests among them) |
| MUT-A all three internal-comment locks of `TaskNotifier`/`TaskNotification` off (selection, per-recipient check, constructor) | `NotificationLeakTest`: first run 1 failed (count of Partner A comment notifications 3 instead of 2), after adding the per-row count assertion 2 failed |
| MUT-A2 MUT-A plus the internal excerpt kept in the notification | 3 failed (text canary found in the mail, the bell row and the rendered payload) |
| MUT-B change notification links a Partner to the Admin route | 2 failed |
| MUT-C the internal billing note appended to the change lines | 3 failed |
| MUT-F escalation link of a Partner points to the Admin route | 2 failed |

The first MUT-A run showed that with only the locks off the leak test caught the existence of the notification (a count), not a text, because the notification classes also drop the excerpt of an internal comment. The per-row counts and the title assertions were added to `NotificationLeakTest` for that reason. Every mutation was reverted by editing the file back; `grep MUT app tests/Feature tests/Isolation` is empty, `git status` is clean for `app`, and the full suite was run afterwards.

## Files Created/Modified

- `app/Domain/Tasks/Notifications/TaskEscalatedNotification.php`, `TaskChangedNotification.php` - the two events, Czech copy from the UI-SPEC rows
- `app/Domain/Tasks/Notifications/TaskNotifier.php` - `escalated()`, `changed()`, change line builder
- `app/Domain/Tasks/Notifications/TaskNotification.php` - `mailLines()` hook and `escapeMarkdown()` made protected (see deviations)
- `app/Domain/Tasks/Actions/EscalateTask.php`, `UpdateTask.php`, `MoveTask.php` - dispatch
- `lang/cs/kokpit.php` - `tasks.notifications.escalated` and `.changed`
- `tests/Feature/Tasks/TaskNotificationsTest.php` - 10 escalation tests and 12 change tests added (42 in the file)
- `tests/Isolation/NotificationLeakTest.php` - 5 tests

## Decisions Made

See `key-decisions` above.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] A mail body with one line per change needed a hook in the base class**
- **Found during:** Task 2 GREEN
- **Issue:** The UI-SPEC mail for a change is the event line followed by one line per change, but `TaskNotification::toMail` could only add the single `mailLine()`. `TaskNotification.php` is not in `files_modified`.
- **Fix:** Minimal change to the base: a `mailLines()` hook that defaults to `[mailLine()]`, and `escapeMarkdown()` from `private` to `protected` so the change lines are escaped like the quote block. Behaviour of the existing notifications is unchanged (the 05-15 tests pass).
- **Files modified:** `app/Domain/Tasks/Notifications/TaskNotification.php`
- **Verification:** `TaskNotificationsTest` all green, mutation MUT-C
- **Committed in:** `effbf44`

**2. [Rule 2 - Missing critical] An escalation never fails when no Admin exists, and an Admin actor is not told of the own action**
- **Found during:** Task 1
- **Issue:** The plan names `TaskPeople::admin()` for the fallback, which throws when no active Admin exists; an escalation by the only Admin of a task assigned to the Admin would also have told the actor.
- **Fix:** The fallback is the first entry of the notifier's own ordered Admin list (the same account), a missing Admin or an Admin actor means no recipient; no exception reaches the action.
- **Files modified:** `app/Domain/Tasks/Notifications/TaskNotifier.php`
- **Committed in:** `0bfc722`

**3. [Rule 1 - Bug avoided] The leak test did not catch the loss of an internal-comment lock**
- **Found during:** Task 3 mutation run MUT-A
- **Issue:** With all three locks off, only a count assertion failed; a row for the internal comment carries no text because the notification drops the excerpt, so the canary search alone passed.
- **Fix:** Per-row counts per event kind (6 rows: 2 comments, 1 escalation, 3 changes) in the stored-rows test.
- **Files modified:** `tests/Isolation/NotificationLeakTest.php`
- **Committed in:** `d96d010`

### Plan wording

- Test `A13: tells nobody ...` is named after A13 as the plan requires; the case "assignee is a Partner of an archived client" is covered by the shared `addCandidate` check and its 05-15 tests, not repeated here, because the scoped query hides a task of an archived client from `EscalateTask` before a notification could be sent.
- The helper `taskNotifMailCount` in `TaskNotificationsTest.php` got an optional subject filter (existing calls are unchanged).

**Total deviations:** 3 auto-fixed (1 blocking, 1 missing critical, 1 bug avoided) plus 2 wording notes. **Impact:** one extra file touched minimally; all changes strengthen the leak guarantees.

## Assumptions flagged for owner confirmation (05-17 SUMMARY)

- **A5:** "relevant changes" are status, priority and assignee; dates, title and description do not notify; one notification per save lists every change. Implemented as planned.
- **A13:** an assignee who can receive the escalation but switched it off on both channels gets nothing and the Admin is not told in that place. Implemented as planned. If the owner wants the Admin told, it is one branch in `TaskNotifier::escalated` (read `NotificationPreferences::for($assignee)` for both channels and choose the Admin when both are off) and the A13 test case flips.
- Not an assumption but worth an owner note: the old assignee of a reassignment (a Partner replaced by the Admin or by a colleague) is not told; only the current Partner requester and assignee are.

## UI-SPEC flags left untouched

F-8 (Phase 4 `InReview` enum colour) and F-9 (label changes on the built Admin task pages) are pending OWNER decisions and were not touched. All other flags are unchanged.

## Issues Encountered

- The full `ddev exec vendor/bin/pest` run exceeded the 120 s foreground limit and was moved to the background; it finished with 1978 passed, so no re-run was needed. No unrelated failures occurred in any suite run.
- A `ddev exec` Pint run right after a file write could read a stale copy (bind-mount timing, noted in earlier plans); a short wait before Pint or Pest was used throughout and the test counts were checked.
- The Mailpit human check of the plan (four mails through a running worker) was not performed: it needs the DDEV worker and a mail UI; it is recorded as the backstop `D5` for the end-of-phase verification.
- The unrelated uncommitted files `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` were not touched or staged.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-05-39 (billing, checklist, tags or internal text in a body) is mitigated by allowlisted scalars and proved by `NotificationLeakTest` with mutations MUT-A, MUT-A2 and MUT-C; T-05-40 (Admin link or foreign bell rows) by the per-audience URL and the bell test, mutations MUT-B and MUT-F; T-05-43 (escalation to a Partner who is not an available assignee) by the eligibility check, the four fallback tests and mutation MUT16.

## Next Phase Readiness

Ready for 05-17 (phase gate). The notification matrix of D-07 and D-15 is complete: task created, comment, escalation and change reach the right side, narrowed by preferences. Open for the gate: the Mailpit check (D5) and owner confirmation of A5 and A13.

## Self-Check: PASSED

Created files exist on disk (`TaskEscalatedNotification.php`, `TaskChangedNotification.php`, `NotificationLeakTest.php`). Commits `0bfc722`, `d0f853e`, `effbf44`, `d96d010` are ancestors of HEAD and `git rev-list --count` from the recorded base gives 4. Acceptance greps of the three tasks pass; `TaskNotificationsTest` 42 passed; `NotificationLeakTest` 5 passed; full suite 1978 passed; Pint and PHPStan clean.
