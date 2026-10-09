---
phase: 05-tasks-and-kanban
verified: 2026-10-09T10:50:00Z
status: passed
score: 5/5 roadmap success criteria verified; all plan-level truths of 05-20, 05-21 and 05-22 verified
covered_files: [".planning/phases/05-tasks-and-kanban/05-01-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-01-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-02-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-02-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-03-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-03-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-04-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-04-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-05-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-05-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-06-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-06-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-07-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-07-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-08-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-08-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-09-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-09-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-10-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-10-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-11-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-11-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-12-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-12-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-13-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-13-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-14-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-14-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-15-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-15-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-16-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-16-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-17-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-17-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-18-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-18-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-19-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-19-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-20-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-20-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-21-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-21-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-22-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-22-SUMMARY.md", "CONTRIBUTING.md", "README.md", "app/Domain/Notifications/NotificationEvent.php", "app/Domain/Tasks/Actions/UpdateTaskDescription.php", "app/Domain/Tasks/Notifications/TaskChangedNotification.php", "app/Domain/Tasks/Notifications/TaskNotification.php", "app/Domain/Tasks/Notifications/TaskNotifier.php", "app/Domain/Tasks/Policies/TaskPolicy.php", "app/Filament/Auth/EditProfile.php", "app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php", "app/Filament/Resources/ActivityResource.php", "lang/cs/kokpit.php", "tests/Feature/Notifications/NotificationPreferencesTest.php", "tests/Feature/Repo/RepositoryFilesTest.php", "tests/Feature/Tasks/PartnerTaskDescriptionTest.php", "tests/Feature/Tasks/TaskEscalationTest.php", "tests/Isolation/NotificationMarkupTest.php", "tests/Isolation/PartnerTaskVisibilityTest.php"]
covered_digest: "v3:sha256:4e764ca5e169315ae92646582636e2e246190e0664625f7d17056a433914f677"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: "5/5 roadmap success criteria; 1 plan-level gate truth failed (test-only)"
  gaps_closed:
    - "Closing-gate truth of plan 05-21 (green full Pest suite) was falsified by a flaky case in PartnerTaskDescriptionTest (any-Partner description edit, ordering by tied created_at); closed by plan 05-22 (commits acee05f, 66b42e0)"
  gaps_remaining: []
  regressions: []
gaps: []
deferred: []
advisory: []
human_verification:
  - test: "Browser walk of the description edit (plan 05-21 Task 3 human-check, extends UAT test 5). Restart the worker first (`ddev artisan horizon:terminate`). As a fictional Partner open an own task in the status Plánovaný at /admin/my-tasks/KEY-N, click 'Upravit popis', change the text and save. Then open the modal again and save without changing anything. Then open an own task in the status V realizaci. Then, as the Admin, open the task history, the bell, Mailpit and the profile page."
    expected: "The page shows the new text. The task in V realizaci offers no 'Upravit popis'. The unchanged second save changes nothing and sends nothing (record in the SUMMARY or UAT whether the browser rich-text editor re-serialises an unchanged text so that it counts as a change, which would make the second save write a history row and send a notice). Admin: the task history shows 'Popis upraven' with the Partner's name; the bell and Mailpit hold one 'Změna úkolu KEY-N' with the line 'Popis upraven uživatelem ...' and no description text, with a working button to /admin/tasks/KEY-N; the profile page shows the 'Změna úkolu' switches with the helper 'Klient upravil popis úkolu.'."
    why_human: "Needs a real browser, a restarted queue worker, Mailpit and the TipTap editor. Pest drives the Livewire action directly, so the editor's serialisation of an unchanged text, the modal rendering and real mail delivery are not exercised. The executor of 05-21 did not perform this walk and did not claim it passed."
  - test: "Re-run UAT test 5, the end-to-end Admin and Partner walk that raised G-05-5 (05-17-PLAN human-check): Partner creates a task and comments, Admin receives bell and mail, Admin answers with an internal and a public comment, Partner sees only the public one, Partner escalates, Admin clears the flag and moves the card, a second fictional Partner escalates a task assigned to the first Partner, who clears the flag from the own task page with no priority or status control. Add: the Partner edits the description where the status allows it."
    expected: "Every step matches ROADMAP success criteria 1 to 5, and the reporter of G-05-5 confirms the Partner can now edit the description where applicable. 05-UAT.md test 5 can then move from 'issue' to 'pass' and G-05-5 can be closed."
    why_human: "Whole-flow judgement across two panels, queue worker, mail and bell; UAT test 5 is still recorded as 'issue' and only the owner can close it."
  - test: "Mailpit and bell rendering of a markup title (05-19-PLAN human-check): create a task whose title holds a Markdown link and an HTML anchor to an example.com address, comment on it as a Partner, and also edit its description; open the Admin's mails and bell."
    expected: "Title and display name read exactly as typed as plain text: no clickable link, image or styling, no visible backslash or entity in the HTML view and the bell (the text view may show backslashes, accepted in 05-19). Includes the new description-change mail for the Admin audience."
    why_human: "Visual rendering in a real mail client and the bell. 05-UAT.md test 3 lists only the 05-16 steps, so it is not evident that the 05-19 markup extension was walked; the new Admin audience of TaskChangedNotification was added after that test."
  - test: "Czech copy review of the strings added by plans 05-20 and 05-21 in lang/cs/kokpit.php: partner_tasks.actions.edit_description, edit_description_heading, edit_description_submit, partner_tasks.notifications.description_saved, tasks.errors.description_stale, description_not_editable, tasks.notifications.changed.description, notifications.profile.helpers.assignment_change_admin, activity.events.description_changed"
    expected: "Natural Czech, terms consistent with úkol, popis, klient, no English left. UAT test 4 passed before these nine strings existed."
    why_human: "Wording judgement."
  - test: "Light and dark mode look of the new 'Upravit popis' modal and button on the Partner task page, and of the Admin's new 'Změna úkolu' row on the profile page"
    expected: "Gray action button readable in both modes, editor toolbar usable, helper text legible. UAT test 6 passed before these elements existed."
    why_human: "Visual check, no browser audit available."
---

# Phase 5: Tasks and Kanban Verification Report

**Phase Goal:** Admin organises work as tasks and subtasks with per-project keys and a drag-and-drop board, and a Partner can raise and discuss tasks in visible projects without seeing anything internal
**Verified:** 2026-10-09T10:50:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap-closure plan 05-22 (the flaky closing-gate test reported by the previous report). This report replaces the one written after 05-20 and 05-21.

Method: goal-backward against the five ROADMAP success criteria and the plan-level truths of 05-20, 05-21 and 05-22. For the previous gap I read the changed test code and ran the tests myself rather than relying on 05-22-SUMMARY.md.

**Headline.** The phase goal is achieved in the code and the one gap from the previous report is closed. The ten requirements are satisfied, all automated gates are green, and the closing-gate truth of 05-21 ("green full suite") is now reliably true. The status is `human_needed` only because five browser, mail and wording checks cannot be done by a program; none is marked passed.

## Evidence base (what I ran at HEAD `5056a8e`)

Working tree: only `.planning/config.json`, `.planning/state.json` and the untracked `.planning/milestone.lock` differ from HEAD. Since the previous verified head `8b6db83`, the only non-`.planning` files changed are the two test files below (`git diff --stat 8b6db83 HEAD -- . ':!.planning'`: 14 and 4 line changes). No production file changed.

| Run (all strictly sequential, never in parallel) | Result |
|-----|--------|
| `ddev exec vendor/bin/pest tests/Feature/Tasks --compact`, run 1 | 365 passed (1942 assertions), 32.3 s |
| same, run 2 | 365 passed, 37.1 s |
| same, run 3 | 365 passed, 33.2 s |
| same, run 4 | 365 passed, 34.4 s |
| same, run 5 | 365 passed, 31.4 s |
| `ddev exec vendor/bin/pest --compact` (full suite) | 2047 passed (15333 assertions), 174 s, no failure |
| `ddev exec vendor/bin/pint --test` | PASS, 458 files |
| `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | [OK] No errors |
| `scripts/check-sensitive.sh --all` | clean (generic patterns only; `KOKPIT_DENYLIST` not set in this shell) |

Context for the five directory runs: the previous report observed the failure in 4 of 7 runs of this same directory, about 55 percent per run. Five consecutive green runs would happen by chance with a probability of roughly 2 percent, so together with the code reading below this is real evidence of the fix, not luck. I did not rerun the mutation proof (descending id) that 05-22-SUMMARY records; I read the code that makes it work (below).

## Gap Closure (re-verification focus): plan 05-22

Previous gap: the closing-gate truth of plan 05-21 (green full suite) failed because `partnerDescHistory()` ordered by `created_at` only; `activity_log` timestamps have whole-second precision, two edits by two Partners in one second tied, and `array_last()` read an arbitrary row.

| Must-have (plan 05-22) | Status | Evidence in code |
|---|---|---|
| `partnerDescHistory()` orders by `created_at` and then by `id`, so tied rows come back in write order | VERIFIED | `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` lines 73-79: `->orderBy('created_at')->orderBy('id')`. Docblock states the whole-second resolution and the UUID v7 tiebreaker. Ids are built by the single test process (`HasUuids` with the UUID v7 factory), strictly increasing within one process per 05-22 key link; the test process writes both Partner edits, so write order equals id order. |
| The any-Partner dataset case proves each save independently of row order: exactly one new `description_changed` row (id difference against the rows before the save) whose causer is the saving Partner; the last row also names that Partner | VERIFIED | Lines 246-265: `$editIdsBefore` collected before `callAction`, `$newEdits` computed by id difference, expectations `->toHaveCount(1)`, `$newEdits[0]->causer_id === $partner->id`, plus the original `array_last(partnerDescEdits($task))->causer_id` expectation retained. The `$newEdits` proof does not depend on ordering at all; the `array_last` expectation now depends only on the deterministic id tiebreaker. |
| `escalationComments()` in `TaskEscalationTest` orders by `created_at` then `id`; every `created_at` ordering in `tests/Feature/Tasks` carries the tiebreaker | VERIFIED | `tests/Feature/Tasks/TaskEscalationTest.php` line 67 has `->orderBy('created_at')->orderBy('id')`. `grep orderBy('created_at') tests/Feature/Tasks` finds exactly one other occurrence (PartnerTaskDescriptionTest line 75) and it is followed by `->orderBy('id')` on the next line. |
| Recorded assumption check that the id tiebreaker carries the order among tied timestamps (frozen clock; ascending passes 3x, descending fails; mutation reverted) | VERIFIED (claim, not re-run) | 05-22-SUMMARY "Assumption check" table has the five rows with run results and the revert; `git status` shows the test files unmodified, so no leftover mutation. The code path is consistent with the claim. I did not repeat the mutation. |
| Five consecutive green `tests/Feature/Tasks` runs and one green full run | VERIFIED | Reproduced independently: 5 of 5 green (365 passed each) and a full run of 2047 passed, same counts as the SUMMARY (the SUMMARY records 15333 assertions for the full run, which I also saw). |
| No production file changes; Pint, PHPStan and the sensitive scan clean | VERIFIED | `git diff --stat 8b6db83 HEAD -- . ':!.planning'` lists only the two test files; Pint, PHPStan and `check-sensitive.sh --all` green as above. |

Resulting status of the previous gap: the plan 05-21 truth "closing gate green: full Pest, Pint, Larastan, licence check, gitignore self-test, sensitive scan" is now true for full Pest, Pint, PHPStan and the sensitive scan. I did not re-run `check-licenses` or `scripts/test-gitignore.sh` (unchanged by this plan, which touched tests only; `RepositoryFilesTest` is part of the green full suite).

## Carried verification of plans 05-20 and 05-21 (G-05-5, unchanged since the previous report)

No production file has changed since the previous verification, whose findings I re-checked by the full green suite (which includes `PartnerTaskDescriptionTest`, `NotificationPreferencesTest`, `NotificationMarkupTest`, `PartnerTaskVisibilityTest`, `RepositoryFilesTest`). Summary of the verified must-haves:

| Must-have | Status | Evidence |
|---|---|---|
| Partner 'Upravit popis' header action, description-only modal, no attachments, sanitised result stored | VERIFIED | `ViewPartnerTask::editDescriptionAction()` calls only `UpdateTaskDescription::handle(actor, task, description, basedOn)`; description cleaned by `TaskInput::description` |
| Nothing but the description changes; no edit page or route; `UpdateTask` still refuses Partners | VERIFIED | Action writes `forceFill(['description' => $clean])` only; `PartnerTaskResource::getPages()` still index, create, view; no Partner `update` ability in `TaskPolicy` |
| D-16 scope: any Partner of the client, visible non-archived project, status Planned or To clarify only | VERIFIED | `TaskPolicy::DESCRIPTION_EDITABLE_STATUSES`; `editDescription` ability; dataset tests for the other statuses and isolation cases green |
| Status re-checked on the locked row; stale and unchanged saves handled | VERIFIED | `UpdateTaskDescription::handle`: `lockForUpdate`, `denies('editDescription', $locked)`, fingerprint compare, unchanged shortcut |
| One 'Popis upraven' history row, no text; Admin reads it, Partner reads no activity | VERIFIED | `event('description_changed')` with no properties; filter in `ActivityResource` |
| Admin and eligible assignee notified (queued mail plus bell), author never, no description text in the notice | VERIFIED | `TaskNotifier::descriptionChanged`, `TaskChangedNotification` with `recipientIsPartner`, `afterCommit`; tests green |
| Admin profile switch 'Změna úkolu' with its own helper; Partner rows unchanged | VERIFIED | `NotificationEvent::forRole(Admin)`, `helper(RoleName)`, `EditProfile` |
| Markup in title or actor name inert in the new Admin audience | VERIFIED | `NotificationMarkupTest` matrix green |
| CONTRIBUTING lists all Partner write paths and a documentation test pins the symbols | VERIFIED | `RepositoryFilesTest` green |
| Prohibitions (3, `verification: test`): no other attribute; no write outside D-16 statuses; no description text in logs | VERIFIED | Each has wired enforcement (forged payload test, status datasets, history-row test) |

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin creates tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags, checklist, plus billing type, fixed price, rate override, estimate | VERIFIED | `CreateTask`, `UpdateTask`, `TaskBillingResolver`, `task_billing` unchanged; `tests/Feature/Tasks` (365 tests, 5 runs) and the full suite green. Files on tasks are Phase 9 per the ROADMAP mapping note. |
| 2 | KEY-N from the project counter, no duplicate or gap under parallel creation, never reused, found by key in search and URL, project key frozen after the first task | VERIFIED | `TaskKeyTest`, `TasksTableTest`, `tests/Concurrency` (part of the green full suite). API use of the key is Phase 7. |
| 3 | List filters by client, project, status, priority, assignee, tag, due date; comments on tasks and subtasks, optionally internal | VERIFIED | `TaskListFiltersTest`, `TaskCommentsTest` green |
| 4 | Drag cards on per-project and global board; status and position persist; global filters | VERIFIED (server side); gesture confirmed by the owner in UAT tests 1 and 2 (pass) | `TaskBoardTest`, `TaskBoardConcurrencyTest` green |
| 5 | Partner creates tasks and comments in visible projects, cannot change status, priority or move cards, sees a read-only list, never sees internal comments or another client's tasks; Admin notified | VERIFIED | `PartnerTaskVisibilityTest`, `PartnerSafeColumnsTest`, `NotificationLeakTest`, `PartnerTaskCommentsTest`, canary registry green; the description edit changes neither status, priority nor position and adds no edit page |

**Score:** 5/5 roadmap success criteria verified, 0 present but behavior-unverified. No plan-level truth failed.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Tasks/Actions/UpdateTaskDescription.php` | Only Partner write path of the description | VERIFIED | Substantive, wired from `ViewPartnerTask` |
| `app/Domain/Tasks/Policies/TaskPolicy.php` | `editDescription`, `DESCRIPTION_EDITABLE_STATUSES` | VERIFIED | Used by the Action (twice), page visibility, docs test |
| `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php` | Header action | VERIFIED | Pinned by tests |
| `TaskNotifier`, `TaskChangedNotification`, `NotificationEvent`, `EditProfile`, lang | Admin and assignee notice, profile switch | VERIFIED | Called after a real write; helper wired |
| `CONTRIBUTING.md`, `README.md`, `RepositoryFilesTest` | Documentation of Partner write paths | VERIFIED | Documentation test green |
| `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` | Deterministic end-to-end proofs | VERIFIED | Now orders by `created_at, id`; new-row proof independent of ordering; 5 of 5 runs green |
| `tests/Feature/Tasks/TaskEscalationTest.php` | Deterministic comment helper | VERIFIED | `created_at, id` ordering |

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| `ViewPartnerTask` action | `UpdateTaskDescription` | `handle(actor, task, description, based_on)` | WIRED |
| `UpdateTaskDescription` | `TaskPolicy::editDescription` | `authorize` before the transaction, `denies` on the locked row | WIRED |
| `UpdateTaskDescription` | `TaskInput::description` | sanitiser and length limit | WIRED |
| `UpdateTaskDescription` | `TaskNotifier::descriptionChanged` | after the history row, notification `afterCommit` | WIRED |
| `EditProfile` | `NotificationEvent::helper($role)` | row helper | WIRED |
| `partnerDescEdits()` | `partnerDescHistory()` | edit filter keeps the helper's `created_at, id` order | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data variable | Source | Real data | Status |
|----------|---------------|--------|-----------|--------|
| Partner modal editor | `description`, `based_on` | task record, fingerprint of stored description | Yes | FLOWING |
| Partner task page after save | task description | `$task->refresh()` then re-render | Yes | FLOWING |
| Admin history | `description_changed` rows | `activity_log` row written by the Action | Yes | FLOWING |
| Admin bell and mail | change line | `kokpit.tasks.notifications.changed.description` with the actor name | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Tasks suite is deterministic | `ddev exec vendor/bin/pest tests/Feature/Tasks --compact` x5 | 365 passed each | PASS |
| Full suite | `ddev exec vendor/bin/pest --compact` | 2047 passed | PASS |
| Style, static analysis, sensitive scan | Pint, PHPStan, `check-sensitive.sh --all` | clean | PASS |

### Probe Execution

Step 7c: SKIPPED. No PLAN or SUMMARY declares a `probe-*.sh`.

### Requirements Coverage

All ten IDs are declared in PLAN frontmatter and are the only IDs REQUIREMENTS.md maps to Phase 5 (lines 239-248: all `Complete`; checkboxes at lines 67-78 ticked); no orphan.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| TA-01 | 05-01..05, 17, 18, 20, 22 | Tasks, one-level subtasks, fields | SATISFIED | SC1; Partner description edit within D-16. The "files" part is Phase 9 by mapping note |
| TA-02 | 05-01..03, 17 | KEY-N counter, locked, never recycled, searchable | SATISFIED | SC2; API use is Phase 7 |
| TA-03 | 05-06, 17 | Todo checklist | SATISFIED | `TaskChecklistTest` |
| TA-04 | 05-09, 13, 17, 19 | Comments, internal flag, Partner never internal | SATISFIED | SC3, SC5; attachments part is Phase 9 |
| TA-05 | 05-03, 17 | List with filters | SATISFIED | SC3 |
| TA-06 | 05-07, 08, 17 | Fixed price, billing type, rate override, estimate | SATISFIED | resolver and table tests |
| TA-07 | 05-12..16, 17, 18, 19, 20, 21, 22 | Partner create and comment, no status or priority change, Admin notified | SATISFIED | SC5 and the G-05-5 additions; the closing gate is reliably green |
| KB-01 | 05-10, 11, 17 | Kanban per project and global | SATISFIED | SC4 |
| KB-02 | 05-10, 11, 17 | Drag and drop synchronous; global filters | SATISFIED | SC4; gesture confirmed by the owner in UAT |
| KB-03 | 05-10, 12, 17, 20 | Partner has no board manipulation, read-only list | SATISFIED | No new Partner page or list action |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (the two test files of 05-22) | - | Previous non-deterministic `orderBy('created_at')` | resolved | Tiebreaker added; 5 of 5 directory runs green |
| (05-20, 05-21, 05-22 files) | - | TBD/FIXME/XXX/TODO/HACK | none found in the previous round; 05-22 added no new such markers (diff is 18 lines of ordering and assertions) | - |

Carried, non-blocking (unchanged): review items WR-01, WR-03, WR-04 and IN-01..IN-07 in `05-REVIEW-DISPOSITION.md`; UI review flags F-1, F-2, F-5, F-8, F-9. A code review report for plan 05-22 exists (`5056a8e`); I did not re-read it.

Bookkeeping note (not a gap): the ROADMAP Phase 5 header line still says "05-22 planned" and the phase checkbox is unchecked; update when the phase closes.

### Human Verification Required

Five items, listed in the frontmatter `human_verification`, all still open and none marked passed:

1. Browser walk of the description edit (05-21 Task 3), including whether the TipTap editor re-serialises an unchanged text as a change.
2. Re-walk of UAT test 5 (G-05-5) by the owner; it is still recorded as `issue` in `05-UAT.md`.
3. Mailpit and bell rendering of markup titles, now including the new Admin description-change mail.
4. Czech copy review of nine strings added by 05-20 and 05-21.
5. Light and dark mode look of the new modal, button and profile row.

Already confirmed by the owner in `05-UAT.md` and not repeated: touch drag (test 1), SPA navigation (test 2), Mailpit of the 05-16 mails (test 3), Czech copy as of then (test 4), visual check as of then (test 6) and the owner decision (test 7).

### Gaps Summary

No gaps remain. The previous gap was in a test, not in the product: an order-dependent assertion over whole-second timestamps. Plan 05-22 fixed it correctly (deterministic `created_at, id` ordering in both affected helpers, plus an order-independent new-row proof), changed no production code, and I reproduced the closing gate independently: five green runs of `tests/Feature/Tasks` (365 tests), a green full suite (2047 tests), Pint, PHPStan and the sensitive scan clean. The phase goal and all ten requirements are met in the code; only the five human checks above stand between this phase and closure of G-05-5.

---

_Verified: 2026-10-09T10:50:00Z_
_Verifier: Claude (gsd-verifier)_
