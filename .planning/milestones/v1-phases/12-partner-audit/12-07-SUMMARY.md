---
phase: 12-partner-audit
plan: 07
subsystem: testing
tags: [pest, audit, canary, channel-matrix, notifications, mail, bell]

requires:
  - phase: 12-partner-audit
    provides: ChannelMatrix, ChannelProbe (seven drivers, compose, memo) and ChannelMatrixTest from plans 12-05 and 12-06
provides:
  - ChannelProbe notifications driver (event script on the canary data, Partner A's bell rows read through the bell component, every mail sent to Partner A decoded); DRIVEN now equals all eight channels
  - ChannelMatrix::NOTIFICATION_AUDIENCES and ChannelMatrix::notificationClasses() for the 17 concrete Notification and Mailable classes under app/, with notificationProblemsIn() wired into problems()
  - ChannelMatrixTest notification completeness (both ways), admin-class check, every-channel-driven check and the notifications control
affects: [12-08, 12-09]

actuals:
  tokens: 7430
  tasks: 2
  commits: 2

plan_head_before: a96ced173087188b357f030b51d74ea783c9d7c4
plan_head_after: 5518270fa9441dc99624679c6e5b1536354e0ebb
commits: 2

tech-stack:
  added: []
  patterns:
    - "The notifications blob is the same for every entity: the type and data of every bell row of Partner A plus the decoded subject, text and HTML of every mail to Partner A; no attribution layer is needed because the event script retitles the canary task, so the client A canary text can only come from a row that has no business in a task notification"
    - "The internal Admin comment of the event script carries the client A canary as its body, so an internal remark reaching Partner A fails every none cell"
    - "Notification and Mailable classes are discovered by scanning app/ (file to class, concrete subclasses of Illuminate\\Notifications\\Notification and Illuminate\\Mail\\Mailable) and compared with ChannelMatrix::notificationClasses() in both directions"

key-files:
  created: []
  modified:
    - tests/Support/ChannelProbe.php
    - tests/Support/ChannelMatrix.php
    - tests/Audit/ChannelMatrixTest.php

key-decisions:
  - "Retitle the client A canary task in the event script (as system, before the inbox is emptied) so the canary text is no longer the task title; then the 38 notification cells share one unattributed blob and a client A canary in a notification is a leak for every none cell, which closes the shared-text blind spot of plan 12-06 for this channel"
  - "Add a colleague Partner of client A to the script: only a colleague escalating the task assigned to Partner A makes an escalation reach Partner A, and the plan lists escalation among the events that can reach a Partner"
  - "notifyBellRows() reads the real bell component as Partner A and asserts that it shows only Partner A's rows and every stored row of Partner A; the bell is the channel, not a table query"
  - "The admin-class test also requires every type Partner A holds to be a class declared partner or any-user, which a client-mail or admin class in the bell would break"

patterns-established:
  - "A new Notification or Mailable class fails the Audit suite until ChannelMatrix::notificationClasses() declares its audience and a reason"
  - "A channel is added to ChannelMatrix::CHANNELS and ChannelProbe::DRIVEN in the same pull request or the test 'drives every channel of the matrix' fails"

requirements-completed: [AUD-01]

coverage:
  - id: D1
    description: "Every entity is walked through Partner A's bell and mails after every event that can reach a Partner (Admin assign, visible and internal comment, status, priority, board move; colleague comment and escalation; Partner comment, escalation, upload; Admin on a client B task): no client B needle or id, and for a none cell no client A needle, appears (38 notification cells)"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#keeps the canaries of the cell out of Partner A's channel (x notifications, 38 cells)"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#reaches Partner A with the own task notifications, so the notification rows are not vacuous"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every concrete Notification and Mailable class under app/ has a declared audience and reason, a declaration without a class fails, and Partner A never holds a notification of an admin class"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#declares every notification and mail class and only existing ones"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#reports an undeclared notification class"
        status: pass
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#never sends Partner A a notification of an admin class"
        status: pass
    human_judgment: false
  - id: D3
    description: "Every channel of ChannelMatrix::CHANNELS has a driver in ChannelProbe::DRIVEN"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/ChannelMatrixTest.php#drives every channel of the matrix"
        status: pass
    human_judgment: false
  - id: D4
    description: "Whether the audience labels of the 17 classes (in particular the client-mail and any-user ones, which are not reachable through the Partner bell script) are the right judgment is a call for the owner"
    requirement: AUD-01
    verification: []
    human_judgment: true
    rationale: "ResetPasswordNotification, PartnerInvitation, InvoiceMail and PaymentConfirmationMail are declared from reading their code and the plan table; the event script does not trigger them, so only the declaration, not a run, backs their label"

duration: 11 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 07: Notifications Driver and Notification Class Audiences Summary

**Partner A's bell and mailbox are now walked for all 38 entities after every Partner-reachable task event (38 notification cells, all eight channels driven), and each of the 17 Notification and Mailable classes under app/ has a declared audience that a new class cannot skip.**

## Performance

- **Duration:** about 11 min (from the plan 12-06 close-out commit at 00:30Z to this summary at 00:41Z)
- **Tasks:** 2 (1 tracer, 1 auto)
- **Files modified:** 3 (0 created, 3 modified)
- **Audit suite:** 592 tests, 1249 assertions, 152 s (was 549 in plan 12-06: 38 notification cells, 1 control, 4 completeness tests)

## Accomplishments

- Task 1 (tracer): the notifications driver. Arrangement, as system: the client A canary task gets a new title, Partner A creates a second client A task (so is its requester), a colleague Partner of client A is created; the notifications table and the array mail transport are emptied. Events (copied from `tests/Isolation/NotificationLeakTest.php`, which stays byte-identical to `58c5e93`): the Admin assigns the canary task to Partner A, answers visibly, writes an internal remark whose body is the client A canary, changes status and priority and moves the task on the board; the colleague answers and escalates the task assigned to Partner A; Partner A comments on, escalates and uploads a file named after the client A canary to the own task; the Admin answers on and changes the client B canary task. The blob is the type and data of every bell row of Partner A plus the decoded subject, text and HTML of every mail to Partner A. 38 cells pass, with no leak found. The control proves Partner A holds a bell row of each of the three task events that can reach a Partner (changed, commented, escalated), at least one mail, the canary task reference and the new title, and neither canary. `⚡ Tracer verified end-to-end — expanding`.
- Task 2: `ChannelMatrix::notificationClasses()` declares the 17 classes (4 `partner`, 1 `any-user`, 3 `client-mail`, 9 `admin`), `NOTIFICATION_AUDIENCES` lists the four audiences, and `problems()` now also reports an unknown audience or an empty reason. The discovery scan of app/ matched the plan's draft table on the first run. New tests: completeness both ways, "reports an undeclared notification class" (a planted anonymous Notification subclass, a planted declaration without a class, an unknown audience and an empty reason), "never sends Partner A a notification of an admin class" (also requires every type Partner A holds to be a partner or any-user class) and "drives every channel of the matrix" (`DRIVEN` equals `CHANNELS` as sets, eight channels).
- Mutation checks by hand (each restored afterwards): making the visible Admin comment carry the client A canary fails `Client x notifications` with the client A needle named, which proves a leaked body would be caught; deleting the `OperationalAlert` declaration fails the completeness test naming the class.
- `tests/Isolation` 356 tests green and byte-identical to `58c5e93` (D-08); Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, the notifications driver and its control** - `bf4fb8e` (test)
2. **Task 2: notification class audiences, admin-class check and every-channel check** - `5518270` (test)

**Plan metadata:** committed separately as docs(12-07).

## Files Created/Modified

- `tests/Support/ChannelProbe.php` - `DRIVEN` lists all eight channels; `notifyBlob()`, public `notifyEvents()`, `notifyBellRows()`, `notifyMailTexts()` and `notifyFacts()`, private `notifySeen()`
- `tests/Support/ChannelMatrix.php` - `NOTIFICATION_AUDIENCES`, `notificationClasses()`, `notificationProblemsIn()`, `problems()` extended
- `tests/Audit/ChannelMatrixTest.php` - the notifications control, `auditChannelNotificationClasses()` and `auditChannelNotificationDifferences()` helpers, and the four completeness tests

## Final audience table

| Class | Audience | Reason |
|---|---|---|
| `TaskCreatedNotification`, `TaskCommentedNotification`, `TaskChangedNotification`, `TaskEscalatedNotification` | partner | sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner |
| `ResetPasswordNotification` | any-user | password reset of any user, a Partner included; carries only the reset link |
| `PartnerInvitation` | client-mail | sent to the invited address before an account exists; carries only the signed invitation link |
| `InvoiceMail`, `PaymentConfirmationMail` | client-mail | sent to the client's invoice e-mail address; carry the client's own issued document or payment confirmation |
| `PartnerUploadedFilesNotification`, `DocumentArchiveReadyNotification`, `DocumentArchiveFailedNotification`, `ReportExportReadyNotification`, `ReportExportFailedNotification`, `InvoiceEmailFailedNotification`, `CardPaymentMatchedNotification`, `LongRunningTimerNotification`, `OperationalAlert` | admin | operational and finance messages for the Admin only |

## Production files touched by a D-09 fix

None. No leak was found in the notifications channel; no production file was edited.

## Cell corrections (finding table, D-02 / D-07)

None. All 38 notification cells held as declared by plan 12-05.

| Entity x channel | Declared | Observed | Change |
|---|---|---|---|
| (none) | - | - | - |

## Findings for the owner (no change made)

1. **The retitled canary task makes the none cells sharp, at the price of one rule.** The shared canary text (plan 12-06 finding D4) would make every none cell fail on the legitimate task title in a mail, so this driver gives the canary task a new title before the script runs. Result: the client A canary in a notification can only come from a project name, client name, comment, checklist item, billing note or another row that has no business in a task notification, and it fails every none cell. The `foreign` cells (Project, Task, TaskComment) are allowed to show it. The residual gap is the same as everywhere: a leak of a value that is only checked by the `foreign` cells of Project, Task and TaskComment cannot be told apart from a legitimate appearance by this channel alone; `tests/Isolation/NotificationLeakTest.php` (distinct canaries per field) covers that and is unchanged.
2. **Four declared classes are not run by the script.** `ResetPasswordNotification`, `PartnerInvitation`, `InvoiceMail` and `PaymentConfirmationMail` are labelled from reading their code and the plan table. A password reset to a Partner is by design (any-user), and the two mails and the invitation go to e-mail addresses, not to a bell. The audit proves the labels are present and that no `admin` class reaches Partner A; it does not prove the content of these four.
3. **A Partner escalation reaches a Partner only through a colleague.** Partner A escalating the own task tells the Admin; Partner A is told of an escalation only when a colleague Partner (or the Admin, when allowed) escalates a task assigned to Partner A. The script includes the colleague for that reason.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

None - plan executed exactly as written. Inside the plan's latitude: the script adds a colleague Partner of client A and retitles the canary task (both arrangements, as system, before the inbox is emptied); the bell is read through the bell component instead of a plain table query, with the stored rows as the cross-check; the draft audience table matched the scan, so no class was added or dropped.

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

None. The scan discovered 17 classes, equal to the draft table, on the first run (ResetPasswordNotification, which the plan did not name, is in the table).

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code only; no network endpoint, auth path, file access or schema was added.

## Next Phase Readiness

- Plan 12-08 (owner sign-off) can present the declared cells, the not-applicable reasons, the shared-canary finding of plan 12-06 and the findings above together; all eight channels are now driven and no leak or cell correction was found in this plan.
- Adding a Notification or Mailable class, an entity or a channel now needs a diff in `ChannelMatrix.php` in the same pull request.

## Self-Check: PASSED

- Modified files exist: tests/Support/ChannelProbe.php, tests/Support/ChannelMatrix.php, tests/Audit/ChannelMatrixTest.php
- Commits `bf4fb8e` and `5518270` are ancestors of HEAD; `git rev-list --count a96ced1..HEAD` printed 2 before this SUMMARY commit
- Acceptance criteria re-run and passing: `'notifications'` present 3 times in ChannelProbe; 39 tests green for `--filter="x notifications|notification rows"` (38 cells plus the control); `grep -c "function notificationClasses"` prints 1, `OperationalAlert::class` and `PaymentConfirmationMail::class` print 1 each; `--filter="every channel"` and `--filter="undeclared notification"` exit 0; `tests/Isolation` unchanged against `58c5e93` and `NotificationLeakTest`, `NotificationMarkupTest` green
- Plan-level verification re-run: Audit suite 592 passed, `tests/Isolation` 356 passed, `vendor/bin/pint --test` clean, PHPStan no errors

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
