---
phase: 05-tasks-and-kanban
plan: 21
subsystem: tasks
tags: [notifications, partner-isolation, filament, profile-preferences, documentation, laravel]
gap_closure: true
gap_ids: [G-05-5]

requires:
  - phase: 05-tasks-and-kanban
    provides: "05-20 UpdateTaskDescription and TaskPolicy::editDescription (D-16), 05-15/05-16 TaskNotifier and TaskNotification base class, 05-14 notification preferences, 05-19 hardened notification markup"
provides:
  - "TaskNotifier::descriptionChanged: a Partner's description edit tells every active Admin and the eligible assignee, with one change line and no description text"
  - "TaskChangedNotification serves both audiences (recipientIsPartner), with the Admin link /admin/tasks/KEY-N or the Partner link /admin/my-tasks/KEY-N"
  - "Admin profile row 'Změna úkolu' (all on by default) with its own helper key assignment_change_admin; Partner rows and copy unchanged"
  - "NotificationMarkupTest matrix covers TaskChangedNotification to the admin"
  - "CONTRIBUTING lists every Partner write path of a task; RepositoryFilesTest guards the description path; README and 05-UI-SPEC.md describe the action and the notice"
affects: [phase 05 verification (UAT test 5 extension), phase 07 API in front of the same Actions]

estimate:
  tokens: 76000
  raw_tokens: 76000
  tasks: 3
  confidence: low
actuals:
  tokens: 13000
  tasks: 3
  commits: 3
plan_head_before: 9eff81cf708398481b45970fbca31d0acb2845dc
plan_head_after: 21aa2148508d77d969fcbb781d9861c7bc63a589

tech-stack:
  added: []
  patterns:
    - "One notification class for both audiences: the notifier passes the audience flag, the base class escapes once at render time"
    - "A role-specific enum helper only where the copy differs (Admin task change row), every other helper shared"
    - "Notification built from the actor's name only; no excerpt, so no user-written text can travel"

key-files:
  created: []
  modified:
    - app/Domain/Tasks/Notifications/TaskNotifier.php
    - app/Domain/Tasks/Notifications/TaskChangedNotification.php
    - app/Domain/Tasks/Actions/UpdateTaskDescription.php
    - app/Domain/Notifications/NotificationEvent.php
    - app/Filament/Auth/EditProfile.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/PartnerTaskDescriptionTest.php
    - tests/Feature/Notifications/NotificationPreferencesTest.php
    - tests/Isolation/NotificationMarkupTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - README.md
    - .planning/phases/05-tasks-and-kanban/05-UI-SPEC.md

key-decisions:
  - "Reuse the existing event AssignmentChange ('Změna úkolu') for the Admin instead of a new enum case or notification class; the Admin gets a profile row because a switch exists exactly for a delivery the system makes (D-15)"
  - "NotificationEvent::helper takes the role; only the Admin's AssignmentChange row has its own key (assignment_change_admin), the Partner key and text stay byte-identical"
  - "Recipients follow the Partner comment rule: active Admins plus the assignee when eligible, author removed, requester not told; an Admin's edit through the Action or EditTask/UpdateTask notifies nobody"
  - "The change line carries the Partner's name only; no excerpt of the description is ever built (D-07, Surface J content limits)"

patterns-established:
  - "Notification::fake() is re-armed before each attempt of a multi-case test, after any arrangement that would notify for real"

requirements-completed: [TA-07]

coverage:
  - id: D1
    description: "A Partner's description edit reaches the Admin and the eligible assignee by mail and in the bell, with the audience's link and no description text"
    requirement: "TA-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#tells the Admin of a Partner description edit by mail and in the bell with the admin link"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#puts no text of the description into the notification of the edit"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#tells the assignee of the same client and never the author or a Partner of another client"
        status: pass
    human_judgment: false
  - id: D2
    description: "Refused, stale and unchanged saves and every Admin edit path send nothing"
    requirement: "TA-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#sends nothing for a refused, stale or unchanged save or an Admin edit through the Action"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#sends no notification when the Admin changes the description on the edit page"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#sends no notification when the Admin saves only the description through UpdateTask"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Admin switches the task change delivery per channel on the profile page under an Admin-specific helper; Partner rows unchanged"
    requirement: "TA-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#shows the Admin the task created, comment, escalation and task change rows with the Admin helper"
        status: pass
      - kind: integration
        ref: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php#lets the Admin narrow the description edit notice per channel"
        status: pass
    human_judgment: false
  - id: D4
    description: "Markup in a Partner-controlled title or name is inert in the new Admin audience"
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php (TaskChangedNotification to the admin pairs)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Browser walk of the whole description edit and its notice (Partner edit, unchanged save, status refusal, Admin history, bell, Mailpit, profile)"
    verification: []
    human_judgment: true
    rationale: "Needs a real browser and a restarted DDEV worker; the rich-text editor's re-serialisation of an unchanged text cannot be asserted in Pest. Pending human verification."

duration: 9min
completed: 2026-10-09
status: complete
---

# Phase 5 Plan 21: Admin notification of a Partner description edit (G-05-5) Summary

**A Partner's edit of a task description now tells every active Admin and the eligible assignee by queued mail and bell through the existing TaskChangedNotification (one line naming the Partner, never a word of the text), the Admin gets the matching profile switch with its own helper, and the Partner write paths are documented and test-guarded.**

## Performance

- **Duration:** about 9 min of executor time (plus a 3 min full-suite run)
- **Started:** 2026-10-09T09:15:54Z
- **Completed:** 2026-10-09T09:24Z
- **Tasks:** 3
- **Files modified:** 13 (0 created, 13 modified)

## Accomplishments

- `TaskNotifier::descriptionChanged` follows the Partner comment rule: active Admins plus the assignee when that account may receive it (active Admin, or an active Partner of the project's client), the author removed, the requester never told. Only a Partner actor notifies. Each recipient gets the link of their audience.
- `UpdateTaskDescription` receives `TaskNotifier` and calls it inside the transaction only after a real write (after the history row); delivery waits for the commit through the notification's `afterCommit()`. Refused, stale and unchanged saves never reach the call.
- `TaskChangedNotification` gains a last parameter `bool $recipientIsPartner = true`; existing callers stay valid.
- `NotificationEvent::forRole(Admin)` now includes `AssignmentChange`; `helper(RoleName)` gives the Admin row `assignment_change_admin` ("Klient upravil popis úkolu.") and leaves the Partner helper text exactly as it was.
- NotificationMarkupTest runs the Admin audience of `TaskChangedNotification` through inert-DOM, plain-subject and identical-second-render cases on the unchanged base class.
- CONTRIBUTING gains the "Partner writes on tasks" convention and a notification sentence; README names the new event; 05-UI-SPEC.md Surfaces G and I and both copy tables describe the action, its status limit and the notice; RepositoryFilesTest fails if the description path or its enforcing test disappears.
- Closing gate green: full Pest suite 2047 passed (15327 assertions), Pint clean, PHPStan no errors, `composer check-licenses` (210 packages), `.gitignore` self-test (112 assertions), `scripts/check-sensitive.sh --all` clean.

## Task Commits

1. **Task 1 (tracer): Partner edit reaches the Admin and eligible assignee** - `0891104` (feat)
2. **Task 2: Admin 'Změna úkolu' switch with its own helper** - `fdfd8d7` (feat)
3. **Task 3: markup proof, Partner write path documentation, closing gate** - `21aa214` (docs)

**Plan metadata:** recorded in the docs commit that follows this file.

## RED evidence

Both RED runs were made before any production change of the task, and the plan prescribes one commit per task, so the RED state is recorded here rather than committed.

- **Task 1 tracer:** `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskDescriptionTest.php` after adding the six cases: 3 failed, 24 passed. Failures: `tells the Admin of a Partner description edit ...` ("Expected 1 notifications to be sent, but 0 were sent"), `puts no text of the description into the notification ...` (the bell count stayed at the previous value: expected N+1, got N) and `tells the assignee of the same client ...` ("Expected 2 notifications to be sent, but 0 were sent"). The two Admin-path cases and the refusal case pass before and after by design (they pin that those paths stay silent). Semantic assessment: the targeted tests executed their set-up (Partner-created task, assignee changes) and failed on the planned assertion because nothing is sent yet; no syntax, fixture or load fault.
- **Task 2:** `pest tests/Feature/Notifications/NotificationPreferencesTest.php tests/Feature/Tasks/PartnerTaskDescriptionTest.php` after the test edits: 2 failed, 46 passed. Failed: `shows the Admin the task created, comment, escalation and task change rows with the Admin helper` (the Admin profile had no 'Změna úkolu' row) and `keeps the Partner task change helper and gives only the Admin row its own helper` (`helper(RoleName::Admin)` still returned the Partner text). The Partner profile case and the per-channel narrowing case pass before the change because preferences already narrow every event generically; the narrowing case pins that behaviour for the Admin row.

## Mutation run

| Mutation | Failed cases | Revert |
|---|---|---|
| Drop the assignee from the candidates of `TaskNotifier::descriptionChanged` (the `addCandidate(... $facts['assignee_id'] ...)` line removed) | 1 failed: `tells the assignee of the same client and never the author or a Partner of another client` (26 others passed) | `git checkout -- app/Domain/Tasks/Notifications/TaskNotifier.php`; file green again (27 passed) |

## Files Created/Modified

- `app/Domain/Tasks/Notifications/TaskNotifier.php` - `descriptionChanged()` and the extended recipient matrix in the class docblock.
- `app/Domain/Tasks/Notifications/TaskChangedNotification.php` - `$recipientIsPartner` parameter and docblock.
- `app/Domain/Tasks/Actions/UpdateTaskDescription.php` - constructor dependency, the call after the history row, notification paragraph.
- `app/Domain/Notifications/NotificationEvent.php` - Admin list, `helper(RoleName)`.
- `app/Filament/Auth/EditProfile.php` - `$event->helper($this->role())`.
- `lang/cs/kokpit.php` - `tasks.notifications.changed.description`, `notifications.profile.helpers.assignment_change_admin`.
- `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` - 7 new cases.
- `tests/Feature/Notifications/NotificationPreferencesTest.php` - Admin row case renamed and extended, helper pin, Partner case extended.
- `tests/Isolation/NotificationMarkupTest.php` - admin audience for `TaskChangedNotification`.
- `tests/Feature/Repo/RepositoryFilesTest.php` - description path guard.
- `CONTRIBUTING.md`, `README.md`, `.planning/phases/05-tasks-and-kanban/05-UI-SPEC.md` - documentation.

## Decisions Made

See `key-decisions` above. In short: reuse `AssignmentChange`, role-aware helper only for the Admin row, comment-rule recipients, name-only change line.

## Deviations from Plan

None - plan executed exactly as written. Notes that are not deviations:

- Both-channels-off for the Admin: `NotificationFake` records nothing when a notification resolves to no channel, so that case asserts `Notification::assertNothingSent()` (and thereby that no other recipient is chosen) instead of an empty-channel send.
- The Task 1 "no description text" case does its real-delivery half (stored bell row) before `Notification::fake()` and its rendered-mail half under the fake, because the fake cannot be undone inside a test.
- Mail text was checked through the rendered HTML, the subject and the intro/outro lines (this Laravel version has no plain-text render of a `MailMessage`).

## TDD Gate Compliance

`workflow.tdd_mode` is off. The plan prescribes one commit per task, so each task's RED run is recorded above rather than committed separately. Task 3 is test and documentation only: the markup pairs for the new audience passed on the 05-19 base class with no change to it (as the plan expected), and the documentation guard was proven by the full suite.

## Issues Encountered

None.

## Known Stubs

None.

## Threat Flags

None - the surface added is the one modelled as T-05-53 (recipients only through `addCandidate` plus Admins, name-only line, canary search and assignee mutation run), T-05-54 (admin audience in the markup matrix) and T-05-55 (accepted; only a real change notifies, per-channel switch on the profile).

## Pending human verification

The browser walk of Task 3 (`<human-check>`) was not performed by the executor and is **not** claimed as passed. It is left for the phase verifier to harvest into UAT (extends UAT test 5 of 05-UAT.md):

1. Restart the DDEV worker first (`ddev artisan horizon:terminate`).
2. As a fictional Partner open an own task in "Plánovaný" at `/admin/my-tasks/KEY-N`, click "Upravit popis", change the text and save; then open the modal and save without changing anything; then open an own task in "V realizaci".
3. Expected: the page shows the new text; the task in "V realizaci" offers no "Upravit popis"; the second save changes nothing and sends nothing (note whether the browser editor re-serialises an unchanged text so that it counts as a change).
4. As the Admin: the task history shows "Popis upraven" with the Partner's name; the bell and Mailpit each hold one "Změna úkolu KEY-N" with the line "Popis upraven uživatelem ..." and no description text; the profile page shows the "Změna úkolu" switches.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- G-05-5 is closed in code, tests and documents; the gap closure's closing gate is green. Phase 5 is ready for the end-of-phase verification, which should include the pending browser walk above.
- 05-VALIDATION.md, 05-SECURITY.md, 05-VERIFICATION.md and 05-REVIEW-DISPOSITION.md were deliberately not touched (later gates update them). UAT test 7 items (CR-01, T-05-44, WR-02, G-1) are untouched, as scoped.

## Self-Check: PASSED

- Modified files exist; commits `0891104`, `fdfd8d7`, `21aa214` are ancestors of HEAD; `git rev-list --count 9eff81c..HEAD` = 3 before this file.
- All acceptance criteria of the three tasks re-run: PASS. Plan verification: full Pest suite 2047 passed, `pint --test` clean, PHPStan no errors, licence check, `.gitignore` self-test and `scripts/check-sensitive.sh --all` clean, one mutation run recorded and reverted.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
