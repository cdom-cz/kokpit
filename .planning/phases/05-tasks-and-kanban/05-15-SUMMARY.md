---
phase: 05-tasks-and-kanban
plan: 15
subsystem: notifications
status: complete
tags: [notifications, mail, bell, partner-isolation, internal-comments, preferences, queued, tdd]

requires:
  - phase: 05-tasks-and-kanban
    provides: NotificationPreferences, NotificationEvent and NotificationChannel (05-14), CreateTask and AddTaskComment (05-02, 05-09), Partner task resource and comments (05-12, 05-13)
provides:
  - App\Domain\Tasks\Notifications\TaskNotifier with taskCreated() and commented(), the single place that picks recipients and builds URLs per audience
  - TaskNotification, the abstract queued base (ShouldQueue, afterCommit, scalars only, preference-narrowed via(), internal-comment LogicException in the constructor)
  - TaskCreatedNotification and TaskCommentedNotification with Czech mail and bell copy under kokpit.tasks.notifications
  - dispatch from CreateTask (Partner actor) and AddTaskComment (inside its transaction)
affects: [05-16 escalation and change notifications (extend TaskNotifier and TaskNotification, announce the escalation themselves), 05-17 phase gate]

actuals:
  tokens: 23600
  tasks: 2
  commits: 3
plan_head_before: 706ace1dc67cf5030fc949dfe89283c49410ff2d
plan_head_after: c910d409737a359250de3190a0ab2128e0905825
commits: 3

tech-stack:
  added: []
  patterns:
    - "A queued notification built from scalars at dispatch time: recipients, URL of the recipient's audience and the excerpt are computed by the notifier, the worker reloads nothing"
    - "An internal comment is blocked three times: the candidate list, the per-recipient check and the notification constructor"
    - "Recipient eligibility is one rule: active account, and for a Partner the project's client, a client-visible and non-archived project and a non-archived client"
    - "The excerpt is plain text from the sanitised body (block ends become spaces, tags and entities resolved, tags stripped again) and the Markdown of the mail quote block is escaped"

key-files:
  created:
    - app/Domain/Tasks/Notifications/TaskNotifier.php
    - app/Domain/Tasks/Notifications/TaskNotification.php
    - app/Domain/Tasks/Notifications/TaskCreatedNotification.php
    - app/Domain/Tasks/Notifications/TaskCommentedNotification.php
    - tests/Feature/Tasks/TaskNotificationsTest.php
  modified:
    - app/Domain/Tasks/Actions/CreateTask.php
    - app/Domain/Tasks/Actions/AddTaskComment.php
    - lang/cs/kokpit.php

key-decisions:
  - "An escalation comment is not announced by AddTaskComment; the escalation notification of plan 05-16 covers it, so the Admin is not told twice"
  - "The Admin recipients of a Partner event are all active accounts with the Admin role (one in practice), not only the oldest one, so the matrix does not depend on TaskPeople::admin()"
  - "A Partner recipient must belong to the project's client and the project must be client-visible and not archived, and the client not archived; this extends 'deactivated or archived client receive nothing' to the cases where the Partner could not open the task anyway"
  - "The mail body keeps the UI-SPEC line verbatim, so the task title is carried by the subject, the bell body and the comment line, and the task-created mail body names the project key and the author"
  - "The bell body shows the excerpt cut to 120 characters (UI-SPEC U-10); the mail quote carries the 300-character excerpt"
  - "The project is named by its key in the notifications (the constructor contract has projectKey only)"

patterns-established:
  - "RED commit may contain tests that pass vacuously (nothing is sent yet): the internal-comment, deactivated/archived and escalation pins were validated against the GREEN code by mutation runs"

requirements-completed: [TA-07]

coverage:
  - id: D1
    description: "When a Partner creates a task, the Admin receives one queued e-mail and one bell entry with the reference, title and project key and a link to /admin/tasks/KEY-N; the Admin's own task creation notifies nobody"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#sends the Admin one queued notification on mail and in the bell when a Partner creates a task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#sends nothing when the Admin creates a task"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#stores one Filament-format bell entry for the Admin that opens the admin task page"
        status: pass
    human_judgment: false
  - id: D2
    description: "A Partner comment tells the Admin and an eligible assignee; a non-internal Admin comment tells the Partner requester and assignee; the author is never told; each recipient gets the URL of its audience"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#comment notifications (tells the Admin of a Partner comment ..., also tells a Partner assignee ..., tells the Partner requester ..., tells both ..., never tells a Partner assignee of the own comment)"
        status: pass
    human_judgment: false
  - id: D3
    description: "An internal comment notifies nobody on the Partner side and leaves no text in any Partner inbox; the notification refuses to be built for a Partner"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#notifies nobody of an internal Admin comment, and leaves no canary in any Partner inbox"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#refuses to build a comment notification of an internal comment for a Partner"
        status: pass
    human_judgment: false
  - id: D4
    description: "Delivery follows the recipient's preferences (mail off gives the bell only, both off gives nothing, empty column gives both); deactivated accounts, Partners of other clients and of archived clients receive nothing"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#delivers only the bell when the recipient switched the e-mail of comments off"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#delivers nothing to a recipient who switched both channels off, and still tells the others"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#does not tell a deactivated Partner requester or a Partner of an archived client"
        status: pass
    human_judgment: false
  - id: D5
    description: "Bodies render complete with no signed-in user; the excerpt is plain text of at most 300 characters; the Markdown of the mail quote is inert"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#renders the mail and the bell complete with no signed-in user and no tag in the excerpt"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#cuts the excerpt to plain text of at most 300 characters from the cleaned body"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/TaskNotificationsTest.php#keeps markup of a comment out of the quote block of the mail"
        status: pass
    human_judgment: false
  - id: D6
    description: "How the e-mail and the bell entries look in a real client and in the Filament bell (copy, quote block, button) for both roles"
    requirement: TA-07
    verification: []
    human_judgment: true
    rationale: "Copy follows the UI-SPEC rows verbatim and the rendering is asserted as text; the visual result in a mail client and in the bell at 375px is a recorded backstop check at the phase gate"

duration: about 25 min
completed: 2026-10-09
---

# Phase 5 Plan 15: Task and comment notifications Summary

**Queued e-mail and bell notifications of Partner tasks and comments to the Admin, and of non-internal Admin comments to the Partner requester and assignee, narrowed by each recipient's preferences, with an internal comment blocked from every Partner in three places.**

## Performance

- **Duration:** about 25 min (the start time was not captured at the beginning of the run, so this is an estimate)
- **Completed:** 2026-10-09T00:27Z
- **Tasks:** 2 (a tracer and a TDD task)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `TaskNotifier` is the one place that picks recipients and builds the URL of each audience (`/admin/tasks/KEY-N` for the Admin, `/admin/my-tasks/KEY-N` for a Partner); the notifications are built from scalars, queued after commit, and a worker reloads nothing.
- Partner creates a task: every active Admin gets `TaskCreatedNotification` on mail and bell. The Admin's own creation notifies nobody (D-07 does not require a notice to a Partner).
- Partner comments: the Admin plus the assignee when that is an eligible account other than the author. The Admin comments non-internally: the Partner requester and assignee. The author is never told.
- Internal comment: no Partner recipient, ever. The flag is read before the candidates are chosen, again before each notification is built, and `TaskNotification::__construct` throws `LogicException` for `internal && recipientIsPartner`, before any preference is read. An internal notification carries no excerpt.
- Preferences narrow `via()`: mail off gives only the bell, both off gives nothing, an empty column gives both. Deactivated accounts, Partners of another client, of a hidden or archived project and of an archived client receive nothing.
- 20 tests in `TaskNotificationsTest` (82 assertions). Full suite 1951 passed. Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): a Partner creates a task and the Admin is told** - `b1d5ea5` (feat)
2. **Task 2 RED: failing tests for comment notifications** - `8759e14` (test)
3. **Task 2 GREEN: comment notifications and the internal guard** - `c910d40` (feat)

**Tracer gate:** the `<verify>` of Task 1 (the targeted test files, Pint, PHPStan) and then `tests/Feature/Tasks`, `tests/Feature/Notifications` and `tests/Arch` (370 passed) were run before expansion; logged "Tracer verified end-to-end - expanding".

## TDD Gate Compliance

RED `8759e14` precedes GREEN `c910d40`; no REFACTOR commit was needed. RED evidence (Pest, target file `tests/Feature/Tasks/TaskNotificationsTest.php`, 19 tests, 12 failed, 7 passed, exit 1). Semantic assessment: every failure is on the planned assertion for the intended reason, none is a load or fixture fault.

- Eleven tests failed with "The expected [TaskCommentedNotification] notification was not sent", "actual size 0 matches expected size 1" or "0 is identical to 1" (nothing is sent or stored yet).
- `refuses to build a comment notification of an internal comment for a Partner` failed on its first assertion, `class_exists(TaskCommentedNotification::class)` being true, so the missing class is an assertion failure, not a fatal error.
- The excerpt and render tests failed with `ItemNotFoundException` on `taskNotifSent(...)->firstOrFail()`, i.e. no notification was sent; they stop on the planned precondition before reaching the excerpt assertions.
- Seven tests passed in RED on purpose or by necessity: the four tracer tests from Task 1, and three negative pins (internal comment leaves no canary, deactivated/archived recipients receive nothing, an escalation comment is not announced) that hold trivially before anything is sent. These three were therefore validated against the GREEN code by mutation runs (below). No `gsd_run check tdd-red-evidence` record was produced (the classifier does not read Pest console output).

### Mutation runs (security-critical, GREEN code, `TaskNotificationsTest` only)

| Mutation | Result |
|---|---|
| M1 selection no longer excludes internal comments (second lock stays) | survives by design: the per-recipient check and the constructor still block |
| M2 selection and per-recipient check both removed | 1 failed (the constructor throws `LogicException`, canary test) |
| M3 M2 plus the constructor guard removed (full leak) | 2 failed: canary inbox test and constructor test |
| M4 constructor guard removed only | 1 failed |
| M5 internal notification keeps its excerpt | 1 failed |
| M6 deactivated accounts not filtered | 2 failed |
| M7 hidden project or archived client not checked | 1 failed |
| M8 Partner of another client allowed | 1 failed |
| M9 preferences ignored in `via()` | 2 failed |
| M10 author not excluded | survived at first; added `never tells a Partner assignee of the own comment`, then 1 failed |
| M11 escalation comment announced | 1 failed |
| M12 excerpt keeps tags | 3 failed |
| M13 excerpt not cut | 1 failed |
| M14 Partner URL points to the Admin route | 2 failed |
| M15 Markdown of the quote not escaped | 1 failed |

Every mutation was reverted by editing the file back; `grep` for the mutation marker over `app` and `tests/Feature` is empty and the full suite was run afterwards.

## Files Created/Modified

- `app/Domain/Tasks/Notifications/TaskNotifier.php` - recipients, eligibility, excerpt, URLs, dispatch
- `app/Domain/Tasks/Notifications/TaskNotification.php` - abstract queued base, preference-narrowed channels, mail and Filament bell payload, internal guard
- `app/Domain/Tasks/Notifications/TaskCreatedNotification.php`, `TaskCommentedNotification.php` - the two events and their Czech strings
- `app/Domain/Tasks/Actions/CreateTask.php` - calls `taskCreated()` after the insert, inside the transaction
- `app/Domain/Tasks/Actions/AddTaskComment.php` - wraps the insert in a transaction and calls `commented()`, except for an escalation comment
- `lang/cs/kokpit.php` - `tasks.notifications.shared`, `.task_created`, `.comment`
- `tests/Feature/Tasks/TaskNotificationsTest.php` - 20 tests

## Decisions Made

See `key-decisions` above. The important one for 05-16: `AddTaskComment` skips the notification for `escalation: true`, so 05-16 must announce the escalation itself (to the assignee, with the Admin as fallback, per D-07).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] A Partner recipient is checked against the task's project, not only against the account**
- **Found during:** Task 2
- **Issue:** The plan lists "deactivated accounts and Partners of archived clients". A Partner of another client (a forged assignee id), or a Partner of a project that is no longer client-visible or is archived, could not open the task, yet would have received its title and excerpt by mail.
- **Fix:** `TaskNotifier::addCandidate` requires the Partner's `client_id` to equal the project's client and the project to be client-visible, not archived and with a non-archived client.
- **Files modified:** `app/Domain/Tasks/Notifications/TaskNotifier.php`
- **Verification:** `does not tell a Partner assignee of another client, a deactivated assignee or the author`, mutations M7 and M8
- **Committed in:** `c910d40`

**2. [Rule 2 - Missing critical] The Markdown of the mail quote is escaped**
- **Found during:** Task 1 (design) and Task 2 (tested)
- **Issue:** The excerpt is placed in a Markdown quote line of the mail; unescaped, a comment could build a link or emphasis in an e-mail from Kokpit.
- **Fix:** `TaskNotification::escapeMarkdown` backslash-escapes the Markdown punctuation before the line is built.
- **Files modified:** `app/Domain/Tasks/Notifications/TaskNotification.php`
- **Verification:** `keeps markup of a comment out of the quote block of the mail`, mutation M15
- **Committed in:** `b1d5ea5`

**3. [Rule 1 - Bug avoided] An escalation comment would have produced a second notification**
- **Found during:** Task 2 (design)
- **Issue:** `EscalateTask` stores the reason through `AddTaskComment`; announcing every comment would tell the Admin of an escalation as a plain comment and again, in plan 05-16, as an escalation.
- **Fix:** `AddTaskComment` does not call the notifier for `escalation: true`; pinned by a test and mutation M11.
- **Files modified:** `app/Domain/Tasks/Actions/AddTaskComment.php`
- **Committed in:** `c910d40`

**4. [Rule 3 - Blocking, test-only] The no-user render test compared a JSON-escaped name**
- **Found during:** Task 2 GREEN
- **Issue:** `json_encode` escaped the Czech characters of the author name, so the `toContain` check failed on the payload. A test fault, not a product fault.
- **Fix:** `JSON_UNESCAPED_UNICODE` in the test.
- **Committed in:** `c910d40`

### Plan wording

- The tracer test list says the mail "renders with the reference, title and project key". The UI-SPEC mail line for a created task ("V projektu :project přibyl nový úkol od uživatele :actor.") is an exact copy contract and carries no title, so the test asserts the title in the subject and the bell payload, and the project key and author in the body. No product behaviour differs from the UI-SPEC.
- The comment excerpt is `Str::limit(trim(strip_tags(...)))` in the plan; the implementation also turns block ends into spaces, decodes entities and strips again, so paragraphs do not run together and an entity cannot re-form a tag. It stays within 300 characters.

**Total deviations:** 3 auto-fixed (2 missing critical, 1 bug avoided) plus 1 test-only fix and 2 wording clarifications. **Impact:** all strengthen the internal-comment and Partner-isolation guarantees; no file outside `files_modified` was touched.

## UI-SPEC flags left untouched

F-8 (Phase 4 `InReview` enum colour) and F-9 (label changes on the built Admin task pages) are pending OWNER decisions and were not touched. F-1 and the other flags are unchanged.

## Issues Encountered

- A `ddev exec` Pint run right after a file write read a stale copy of the test file and wrote it back, silently dropping one just-added test; it was noticed because the test count did not rise, and the test was re-added after a short wait. Same bind-mount timing as noted in earlier plans: wait a few seconds after a write before running Pint or Pest.
- `ddev artisan horizon:terminate` reported "No processes to terminate" (no worker running), so nothing needed restarting.
- The unrelated uncommitted files `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` were not touched or staged.

## Known Stubs

None. The `AssignmentChange` and `Escalation` events have profile switches (plan 05-14) but no sender yet by design; both belong to plan 05-16.

## Threat Flags

None beyond the plan's threat model. T-05-36 (internal comment to a Partner) is mitigated three times and proved by tests and mutations M2 to M5; T-05-37 (worker reloading models) by scalars-only constructors, URLs computed at dispatch and the no-user render tests; T-05-38 (flooding) is accepted as planned: the per-user switches are the control, and a rate limit is recorded as an owner note (research Pitfall 9).

## Next Phase Readiness

Ready for 05-16. It can extend `TaskNotifier` with `escalated()` and `changed()` and reuse `addCandidate`, `facts` and `url`; the escalation comment already stays silent here. Owner notes: the project is named by its key in notifications (the full name is not in the constructor contract), and a notification to every active Admin account is sent when more than one exists.

## Self-Check: PASSED

Created files exist on disk (`TaskNotifier`, `TaskNotification`, `TaskCreatedNotification`, `TaskCommentedNotification`, `TaskNotificationsTest`). Commits `b1d5ea5`, `8759e14`, `c910d40` are ancestors of HEAD and `git rev-list --count` from the recorded base gives 3. Task acceptance greps pass; `TaskNotificationsTest` 20 passed; the full suite 1951 passed; Pint and PHPStan clean.
